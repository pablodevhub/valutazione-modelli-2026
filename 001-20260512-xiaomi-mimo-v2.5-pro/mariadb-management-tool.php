<?php
/**
 * MariaDB Manager — Single-file database management tool
 */

// ════════════════════════════════════════════════════════════
//  CONFIGURAZIONE
// ════════════════════════════════════════════════════════════
$API_BACKUPS = [];
$API_ALLOWED_IPS = [];
$APP_NAME = 'MariaDB Manager';
$MAX_ROWS = 200;
$MAX_EXECUTION = 300;

// ════════════════════════════════════════════════════════════
//  BOOTSTRAP
// ════════════════════════════════════════════════════════════
session_start();
set_time_limit($MAX_EXECUTION);
ini_set('memory_limit', '512M');
error_reporting(E_ERROR | E_PARSE);

// ════════════════════════════════════════════════════════════
//  UTILITY
// ════════════════════════════════════════════════════════════
function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function ei($name) {
    return '`' . str_replace('`', '``', $name) . '`';
}

function ess($str) {
    return str_replace(
        ["\\", "\0", "\n", "\r", "\t", "\b", "\x1a", "'"],
        ["\\\\", "\\0", "\\n", "\\r", "\\t", "\\b", "\\Z", "\\'"],
        $str
    );
}

function fb($bytes) {
    $u = array('B','KB','MB','GB','TB');
    $i = 0;
    while ($bytes >= 1024 && $i < 4) { $bytes /= 1024; $i++; }
    return round($bytes, 2) . ' ' . $u[$i];
}

function msg($text, $type = 'info') {
    $_SESSION['flash'][] = array('text' => $text, 'type' => $type);
}

function getFlash() {
    $f = isset($_SESSION['flash']) ? $_SESSION['flash'] : array();
    unset($_SESSION['flash']);
    return $f;
}

// ════════════════════════════════════════════════════════════
//  BACKUP ENGINE
// ════════════════════════════════════════════════════════════
function generateBackup($pdo, $dbName, $options = array()) {
    $structureOnly = isset($options['structure_only']) ? $options['structure_only'] : false;
    $includeRoutines = isset($options['include_routines']) ? $options['include_routines'] : false;
    $out = "-- ==============================================\n";
    $out .= "-- MariaDB Manager Backup\n";
    $out .= "-- Database: `$dbName`\n";
    $out .= "-- Date: " . date('Y-m-d H:i:s') . "\n";
    $out .= "-- ==============================================\n\n";
    $out .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n";

    $tables = $pdo->query("SHOW FULL TABLES FROM " . ei($dbName) . " WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
    foreach ($tables as $tblRow) {
        $tName = $tblRow[0];
        $out .= "DROP TABLE IF EXISTS " . ei($tName) . ";\n";
        $cr = $pdo->query("SHOW CREATE TABLE " . ei($tName))->fetch(PDO::FETCH_NUM);
        $out .= $cr[1] . ";\n\n";
        if ($structureOnly) continue;

        $cols = $pdo->query("SHOW COLUMNS FROM " . ei($tName))->fetchAll(PDO::FETCH_ASSOC);
        $blobIdx = array();
        $selectParts = array();
        foreach ($cols as $ci => $col) {
            if (preg_match('/(blob|binary|varbinary)/i', $col['Type'])) {
                $blobIdx[$ci] = true;
                $selectParts[] = "HEX(" . ei($col['Field']) . ") AS " . ei($col['Field']);
            } else {
                $selectParts[] = ei($col['Field']);
            }
        }
        $stmt = $pdo->query("SELECT " . implode(', ', $selectParts) . " FROM " . ei($tName));
        $rowCount = 0;
        $insertBuf = '';
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $vals = array();
            foreach ($row as $ri => $v) {
                if ($v === null) {
                    $vals[] = 'NULL';
                } elseif (isset($blobIdx[$ri])) {
                    $vals[] = "UNHEX('" . $v . "')";
                } else {
                    $vals[] = "'" . ess($v) . "'";
                }
            }
            $insertBuf .= ($rowCount === 0 ? "INSERT INTO " . ei($tName) . " VALUES\n" : ",\n");
            $insertBuf .= "(" . implode(', ', $vals) . ")";
            $rowCount++;
            if ($rowCount % 500 === 0) {
                $insertBuf .= ";\n";
                $out .= $insertBuf;
                $insertBuf = '';
                $rowCount = 0;
            }
        }
        if ($rowCount > 0) $insertBuf .= ";\n";
        if ($insertBuf !== '') {
            $out .= "LOCK TABLES " . ei($tName) . " WRITE;\n" . $insertBuf . "UNLOCK TABLES;\n";
        }
        $out .= "\n";
    }

    $views = $pdo->query("SHOW FULL TABLES FROM " . ei($dbName) . " WHERE Table_type='VIEW'")->fetchAll(PDO::FETCH_NUM);
    foreach ($views as $vr) {
        $out .= "DROP VIEW IF EXISTS " . ei($vr[0]) . ";\n";
        $cv = $pdo->query("SHOW CREATE VIEW " . ei($vr[0]))->fetch(PDO::FETCH_NUM);
        $out .= $cv[1] . ";\n\n";
    }

    if ($includeRoutines) {
        try {
            $routines = $pdo->query("SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = " . $pdo->quote($dbName))->fetchAll(PDO::FETCH_ASSOC);
            foreach ($routines as $r) {
                $rn = $r['ROUTINE_NAME'];
                $rt = $r['ROUTINE_TYPE'];
                $out .= "DROP $rt IF EXISTS " . ei($rn) . ";\nDELIMITER ;;\n";
                $cr = $pdo->query("SHOW CREATE $rt " . ei($rn))->fetch(PDO::FETCH_NUM);
                $out .= $cr[2] . ";;\nDELIMITER ;\n\n";
            }
        } catch (Exception $e) { /* skip */ }
        try {
            $triggers = $pdo->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = " . $pdo->quote($dbName))->fetchAll(PDO::FETCH_NUM);
            foreach ($triggers as $tr) {
                $out .= "DROP TRIGGER IF EXISTS " . ei($tr[0]) . ";\nDELIMITER ;;\n";
                $ct = $pdo->query("SHOW CREATE TRIGGER " . ei($tr[0]))->fetch(PDO::FETCH_NUM);
                $out .= $ct[2] . ";;\nDELIMITER ;\n\n";
            }
        } catch (Exception $e) { /* skip */ }
    }
    $out .= "SET FOREIGN_KEY_CHECKS = 1;\n";
    return $out;
}

// ════════════════════════════════════════════════════════════
//  RESTORE ENGINE
// ════════════════════════════════════════════════════════════
function parseSQLStatements($sql) {
    $statements = array();
    $current = '';
    $delimiter = ';';
    $i = 0;
    $len = strlen($sql);
    while ($i < $len) {
        if (preg_match('/\GDELIMITER[ \t]+(\S+)/s', $sql, $m, $i)) {
            $stmt = trim($current);
            if ($stmt !== '') $statements[] = $stmt;
            $current = '';
            $delimiter = $m[1];
            $i += strlen($m[0]);
            while ($i < $len && ($sql[$i] === "\n" || $sql[$i] === "\r")) $i++;
            continue;
        }
        $dLen = strlen($delimiter);
        if (substr($sql, $i, $dLen) === $delimiter) {
            $stmt = trim($current);
            if ($stmt !== '') $statements[] = $stmt;
            $current = '';
            $i += $dLen;
            continue;
        }
        if ($sql[$i] === '-' && $i + 1 < $len && $sql[$i + 1] === '-') {
            while ($i < $len && $sql[$i] !== "\n") { $current .= $sql[$i]; $i++; }
            continue;
        }
        if ($sql[$i] === '#') {
            while ($i < $len && $sql[$i] !== "\n") { $current .= $sql[$i]; $i++; }
            continue;
        }
        if ($sql[$i] === '/' && $i + 1 < $len && $sql[$i + 1] === '*') {
            $current .= '/*';
            $i += 2;
            while ($i < $len) {
                if ($sql[$i] === '*' && $i + 1 < $len && $sql[$i + 1] === '/') {
                    $current .= '*/'; $i += 2; break;
                }
                $current .= $sql[$i]; $i++;
            }
            continue;
        }
        if ($sql[$i] === "'" || $sql[$i] === '"') {
            $q = $sql[$i]; $current .= $q; $i++;
            while ($i < $len) {
                if ($sql[$i] === '\\') {
                    $current .= $sql[$i]; $i++;
                    if ($i < $len) { $current .= $sql[$i]; $i++; }
                    continue;
                }
                if ($sql[$i] === $q) {
                    if ($i + 1 < $len && $sql[$i + 1] === $q) {
                        $current .= $q . $q; $i += 2; continue;
                    }
                    $current .= $q; $i++; break;
                }
                $current .= $sql[$i]; $i++;
            }
            continue;
        }
        if ($sql[$i] === '`') {
            $current .= '`'; $i++;
            while ($i < $len) {
                if ($sql[$i] === '`') {
                    if ($i + 1 < $len && $sql[$i + 1] === '`') {
                        $current .= '``'; $i += 2; continue;
                    }
                    $current .= '`'; $i++; break;
                }
                $current .= $sql[$i]; $i++;
            }
            continue;
        }
        $current .= $sql[$i]; $i++;
    }
    $stmt = trim($current);
    if ($stmt !== '') $statements[] = $stmt;
    return $statements;
}

function executeRestore($pdo, $sql) {
    $statements = parseSQLStatements($sql);
    $ok = 0; $err = 0; $errors = array();
    foreach ($statements as $idx => $stmt) {
        $s = trim($stmt, " \t\n\r\0\x0B");
        if ($s === '' || preg_match('/^--/s', $s) || preg_match('/^\/\*.*\*\/$/s', $s)) continue;
        try { $pdo->exec($s); $ok++; }
        catch (PDOException $e) {
            $err++;
            $errors[] = "#" . ($idx + 1) . ": " . $e->getMessage();
            if (count($errors) > 50) { $errors[] = '...'; break; }
        }
    }
    return array('ok' => $ok, 'errors' => $err, 'details' => $errors);
}

// ════════════════════════════════════════════════════════════
//  API HANDLER
// ════════════════════════════════════════════════════════════
if (isset($_GET['api_backup'])) {
    header('Content-Type: text/plain; charset=utf-8');
    $key = $_GET['api_backup'];
    if (!isset($API_BACKUPS[$key])) { http_response_code(403); die("Invalid API key."); }
    if (!empty($API_ALLOWED_IPS) && !in_array($_SERVER['REMOTE_ADDR'], $API_ALLOWED_IPS)) {
        http_response_code(403); die("IP not allowed.");
    }
    $cfg = $API_BACKUPS[$key];
    try {
        $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['db']};charset=utf8mb4";
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        $opts = array(
            'structure_only' => isset($_GET['structure_only']) ? true : false,
            'include_routines' => isset($_GET['routines']) ? true : false
        );
        $backup = generateBackup($pdo, $cfg['db'], $opts);
        $filename = $cfg['db'] . '_' . date('Ymd_His') . '.sql';
        if (isset($_GET['compress'])) {
            $backup = gzencode($backup, 9);
            $filename .= '.gz';
            header('Content-Type: application/gzip');
        } else {
            header('Content-Type: application/sql');
        }
        header("Content-Disposition: attachment; filename=\"$filename\"");
        header('Content-Length: ' . strlen($backup));
        echo $backup;
    } catch (PDOException $e) {
        http_response_code(500);
        die("Backup failed: " . $e->getMessage());
    }
    exit;
}

// ════════════════════════════════════════════════════════════
//  CONNECTION
// ════════════════════════════════════════════════════════════
function getPdo() {
    if (!isset($_SESSION['db_conn'])) return null;
    $c = $_SESSION['db_conn'];
    try {
        $dsn = "mysql:host={$c['host']};port={$c['port']};charset=utf8mb4";
        return new PDO($dsn, $c['user'], $c['pass'], array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ));
    } catch (PDOException $e) {
        unset($_SESSION['db_conn']);
        return null;
    }
}

// ════════════════════════════════════════════════════════════
//  ROUTING
// ════════════════════════════════════════════════════════════
$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : 'home');
$pdo = getPdo();

// Login
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = isset($_POST['host']) ? $_POST['host'] : 'localhost';
    $port = intval(isset($_POST['port']) ? $_POST['port'] : 3306);
    $user = isset($_POST['user']) ? $_POST['user'] : '';
    $pass = isset($_POST['pass']) ? $_POST['pass'] : '';
    try {
        $dsn = "mysql:host=$host;port=$port;charset=utf8mb4";
        $testPdo = new PDO($dsn, $user, $pass, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ));
        $_SESSION['db_conn'] = compact('host', 'port', 'user', 'pass');
        $_SESSION['server_version'] = $testPdo->query("SELECT VERSION()")->fetchColumn();
        msg('Connessione riuscita!', 'success');
        header('Location: ?action=databases');
        exit;
    } catch (PDOException $e) {
        msg('Autenticazione fallita: ' . $e->getMessage(), 'danger');
        header('Location: ?');
        exit;
    }
}

if ($action === 'logout') {
    session_destroy();
    header('Location: ?');
    exit;
}

if (!$pdo && $action !== 'home') $action = 'home';

// Use DB
if ($action === 'use_db' && isset($_GET['db'])) {
    $db = $_GET['db'];
    try { $pdo->exec("USE " . ei($db)); $_SESSION['current_db'] = $db; }
    catch (PDOException $e) { msg("Errore: " . $e->getMessage(), 'danger'); }
    header("Location: ?action=browse&db=" . urlencode($db));
    exit;
}

// Create DB
if ($action === 'create_db' && $_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    $name = trim(isset($_POST['db_name']) ? $_POST['db_name'] : '');
    $collation = isset($_POST['collation']) ? $_POST['collation'] : 'utf8mb4_general_ci';
    if ($name !== '') {
        try {
            $pdo->exec("CREATE DATABASE " . ei($name) . " COLLATE " . ei($collation));
            msg("Database `$name` creato.", 'success');
        } catch (PDOException $e) { msg($e->getMessage(), 'danger'); }
    }
    header('Location: ?action=databases');
    exit;
}

// Drop DB
if ($action === 'drop_db' && $_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    $name = isset($_POST['db_name']) ? $_POST['db_name'] : '';
    if ($name !== '' && isset($_POST['confirm'])) {
        try {
            $pdo->exec("DROP DATABASE " . ei($name));
            msg("Database `$name` eliminato.", 'success');
            if (isset($_SESSION['current_db']) && $_SESSION['current_db'] === $name) unset($_SESSION['current_db']);
        } catch (PDOException $e) { msg($e->getMessage(), 'danger'); }
    }
    header('Location: ?action=databases');
    exit;
}

// Create Table
if ($action === 'create_table' && $_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    $db = isset($_SESSION['current_db']) ? $_SESSION['current_db'] : '';
    $tName = trim(isset($_POST['table_name']) ? $_POST['table_name'] : '');
    if ($db !== '' && $tName !== '') {
        try { $pdo->exec("USE " . ei($db)); } catch (Exception $e) { /* skip */ }
        $colDefs = array();
        $names = isset($_POST['col_name']) ? $_POST['col_name'] : array();
        $types = isset($_POST['col_type']) ? $_POST['col_type'] : array();
        $lengths = isset($_POST['col_length']) ? $_POST['col_length'] : array();
        $nullables = isset($_POST['col_nullable']) ? $_POST['col_nullable'] : array();
        $defaults = isset($_POST['col_default']) ? $_POST['col_default'] : array();
        $autos = isset($_POST['col_auto']) ? $_POST['col_auto'] : array();
        $pks = isset($_POST['col_pk']) ? $_POST['col_pk'] : array();
        $unsigneds = isset($_POST['col_unsigned']) ? $_POST['col_unsigned'] : array();
        $engine = isset($_POST['engine']) ? $_POST['engine'] : 'InnoDB';
        $charset = isset($_POST['table_charset']) ? $_POST['table_charset'] : 'utf8mb4';
        foreach ($names as $i => $cn) {
            $cn = trim($cn);
            if ($cn === '') continue;
            $def = ei($cn) . ' ' . strtoupper($types[$i]);
            if ($lengths[$i] !== '') $def .= '(' . intval($lengths[$i]) . ')';
            if (!empty($unsigneds[$i])) $def .= ' UNSIGNED';
            if (!empty($nullables[$i])) $def .= ' NOT NULL';
            if ($defaults[$i] !== '') {
                $dv = trim($defaults[$i]);
                $upper = strtoupper($dv);
                $noQuote = array('NULL','CURRENT_TIMESTAMP','CURRENT_DATE','CURRENT_TIME','NOW()','CURDATE()','CURTIME()','UUID()');
                if (in_array($upper, $noQuote) || (strpos($dv, '(') !== false && strpos($dv, ')') !== false)) {
                    $def .= ' DEFAULT ' . $dv;
                } else {
                    $def .= ' DEFAULT ' . $pdo->quote($dv);
                }
            }
            if (!empty($autos[$i])) $def .= ' AUTO_INCREMENT';
            $colDefs[] = $def;
        }
        $pkCols = array();
        foreach ($pks as $pi => $pv) {
            if (!empty($pv) && isset($names[$pi]) && trim($names[$pi]) !== '') {
                $pkCols[] = ei(trim($names[$pi]));
            }
        }
        if (!empty($pkCols)) $colDefs[] = 'PRIMARY KEY (' . implode(',', $pkCols) . ')';
        $sql = "CREATE TABLE " . ei($tName) . " (\n  " . implode(",\n  ", $colDefs) . "\n) ENGINE=$engine DEFAULT CHARSET=$charset";
        try {
            $pdo->exec($sql);
            msg("Tabella `$tName` creata.", 'success');
            header("Location: ?action=structure&db=" . urlencode($db) . "&table=" . urlencode($tName));
            exit;
        } catch (PDOException $e) { msg($e->getMessage(), 'danger'); }
    }
    header("Location: ?action=browse&db=" . urlencode($db));
    exit;
}

// Drop Table
if ($action === 'drop_table' && $_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    $db = isset($_SESSION['current_db']) ? $_SESSION['current_db'] : '';
    $tName = isset($_POST['table_name']) ? $_POST['table_name'] : '';
    if ($db !== '' && $tName !== '' && isset($_POST['confirm'])) {
        try { $pdo->exec("USE " . ei($db)); $pdo->exec("DROP TABLE " . ei($tName)); msg("Tabella eliminata.", 'success'); }
        catch (PDOException $e) { msg($e->getMessage(), 'danger'); }
    }
    header("Location: ?action=browse&db=" . urlencode($db));
    exit;
}

// Truncate Table
if ($action === 'truncate_table' && $_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    $db = isset($_SESSION['current_db']) ? $_SESSION['current_db'] : '';
    $tName = isset($_POST['table_name']) ? $_POST['table_name'] : '';
    if ($db !== '' && $tName !== '' && isset($_POST['confirm'])) {
        try { $pdo->exec("USE " . ei($db)); $pdo->exec("TRUNCATE TABLE " . ei($tName)); msg("Tabella svuotata.", 'success'); }
        catch (PDOException $e) { msg($e->getMessage(), 'danger'); }
    }
    header("Location: ?action=structure&db=" . urlencode($db) . "&table=" . urlencode($tName));
    exit;
}

// Drop Column
if ($action === 'drop_column' && $_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    $db = isset($_SESSION['current_db']) ? $_SESSION['current_db'] : '';
    $tName = isset($_POST['table_name']) ? $_POST['table_name'] : '';
    $colName = isset($_POST['col_name']) ? $_POST['col_name'] : '';
    if ($db !== '' && $tName !== '' && $colName !== '' && isset($_POST['confirm'])) {
        try {
            $pdo->exec("USE " . ei($db));
            $pdo->exec("ALTER TABLE " . ei($tName) . " DROP COLUMN " . ei($colName));
            msg("Colonna `$colName` eliminata.", 'success');
        } catch (PDOException $e) { msg($e->getMessage(), 'danger'); }
    }
    header("Location: ?action=structure&db=" . urlencode($db) . "&table=" . urlencode($tName));
    exit;
}

// Modify Column
if ($action === 'modify_column' && $_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    $db = isset($_SESSION['current_db']) ? $_SESSION['current_db'] : '';
    $tName = isset($_POST['table_name']) ? $_POST['table_name'] : '';
    $colName = isset($_POST['col_name']) ? $_POST['col_name'] : '';
    $newName = trim(isset($_POST['new_name']) ? $_POST['new_name'] : $colName);
    $colType = strtoupper(trim(isset($_POST['col_type']) ? $_POST['col_type'] : 'VARCHAR'));
    $colLength = trim(isset($_POST['col_length']) ? $_POST['col_length'] : '');
    $colNullable = isset($_POST['col_nullable']);
    $colDefault = trim(isset($_POST['col_default']) ? $_POST['col_default'] : '');
    $colExtra = trim(isset($_POST['col_extra']) ? $_POST['col_extra'] : '');
    $colUnsigned = isset($_POST['col_unsigned']);
    $colPosition = isset($_POST['col_position']) ? $_POST['col_position'] : '';
    if ($db !== '' && $tName !== '' && $colName !== '') {
        try { $pdo->exec("USE " . ei($db)); } catch (Exception $e) { /* skip */ }
        $typeSql = $colType;
        if ($colLength !== '') $typeSql .= "(" . $colLength . ")";
        if ($colUnsigned) $typeSql .= " UNSIGNED";
        $sql = "ALTER TABLE " . ei($tName) . " CHANGE COLUMN " . ei($colName) . " " . ei($newName) . " $typeSql";
        $sql .= $colNullable ? " NOT NULL" : " NULL";
        if ($colDefault !== '') {
            $upper = strtoupper($colDefault);
            $noQ = array('NULL','CURRENT_TIMESTAMP','CURRENT_DATE','CURRENT_TIME','NOW()','CURDATE()','CURTIME()','UUID()');
            if (in_array($upper, $noQ) || (strpos($colDefault, '(') !== false && strpos($colDefault, ')') !== false)) {
                $sql .= " DEFAULT $colDefault";
            } else {
                $sql .= " DEFAULT " . $pdo->quote($colDefault);
            }
        }
        if ($colExtra !== '') $sql .= " $colExtra";
        if ($colPosition === 'FIRST') $sql .= " FIRST";
        elseif ($colPosition !== '' && $colPosition !== '__KEEP__') $sql .= " AFTER " . ei($colPosition);
        try { $pdo->exec($sql); msg("Colonna modificata.", 'success'); }
        catch (PDOException $e) { msg($e->getMessage(), 'danger'); }
    }
    header("Location: ?action=structure&db=" . urlencode($db) . "&table=" . urlencode($tName));
    exit;
}

// Execute SQL
$sqlResult = null;
if ($action === 'exec_sql' && $_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    $rawSQL = isset($_POST['sql_text']) ? $_POST['sql_text'] : '';
    $db = isset($_POST['sql_db']) ? $_POST['sql_db'] : (isset($_SESSION['current_db']) ? $_SESSION['current_db'] : '');
    if ($db !== '') { try { $pdo->exec("USE " . ei($db)); } catch (Exception $e) { /* skip */ } }
    $statements = parseSQLStatements($rawSQL);
    $results = array();
    foreach ($statements as $idx => $stmt) {
        $s = trim($stmt);
        if ($s === '') continue;
        try {
            $st = $pdo->query($s);
            if ($st && $st->columnCount() > 0) {
                $rows = $st->fetchAll(PDO::FETCH_NUM);
                $cols = array();
                for ($c = 0; $c < $st->columnCount(); $c++) {
                    $meta = $st->getColumnMeta($c);
                    $cols[] = $meta['name'];
                }
                $results[] = array('type' => 'select', 'columns' => $cols, 'rows' => $rows, 'count' => count($rows));
            } else {
                $results[] = array('type' => 'affected', 'count' => $pdo->rowCount());
            }
        } catch (PDOException $e) {
            $results[] = array('type' => 'error', 'msg' => $e->getMessage());
        }
    }
    $sqlResult = $results;
    $action = 'query';
}

// Insert Row
if ($action === 'insert_row' && $_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    $db = isset($_SESSION['current_db']) ? $_SESSION['current_db'] : '';
    $tName = isset($_POST['table_name']) ? $_POST['table_name'] : '';
    if ($db !== '' && $tName !== '') {
        try { $pdo->exec("USE " . ei($db)); } catch (Exception $e) { /* skip */ }
        $fields = isset($_POST['field']) ? $_POST['field'] : array();
        $vals = isset($_POST['value']) ? $_POST['value'] : array();
        $nulls = isset($_POST['is_null']) ? $_POST['is_null'] : array();
        $cols = array(); $values = array();
        foreach ($fields as $i => $f) {
            if ($f === '') continue;
            $cols[] = ei($f);
            $values[] = in_array($f, $nulls) ? 'NULL' : $pdo->quote(isset($vals[$i]) ? $vals[$i] : '');
        }
        if (!empty($cols)) {
            try {
                $pdo->exec("INSERT INTO " . ei($tName) . " (" . implode(',', $cols) . ") VALUES (" . implode(',', $values) . ")");
                msg("Riga inserita (ID: " . $pdo->lastInsertId() . ").", 'success');
            } catch (PDOException $e) { msg($e->getMessage(), 'danger'); }
        }
    }
    header("Location: ?action=data&db=" . urlencode($db) . "&table=" . urlencode($tName));
    exit;
}

// Update Row
if ($action === 'update_row' && $_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    $db = isset($_SESSION['current_db']) ? $_SESSION['current_db'] : '';
    $tName = isset($_POST['table_name']) ? $_POST['table_name'] : '';
    if ($db !== '' && $tName !== '') {
        try { $pdo->exec("USE " . ei($db)); } catch (Exception $e) { /* skip */ }
        $fields = isset($_POST['field']) ? $_POST['field'] : array();
        $vals = isset($_POST['value']) ? $_POST['value'] : array();
        $nulls = isset($_POST['is_null']) ? $_POST['is_null'] : array();
        $whereFields = isset($_POST['where_field']) ? $_POST['where_field'] : array();
        $whereVals = isset($_POST['where_value']) ? $_POST['where_value'] : array();
        $sets = array();
        foreach ($fields as $i => $f) {
            if ($f === '') continue;
            if (in_array($f, $nulls)) $sets[] = ei($f) . " = NULL";
            else $sets[] = ei($f) . " = " . $pdo->quote(isset($vals[$i]) ? $vals[$i] : '');
        }
        $wheres = array();
        foreach ($whereFields as $i => $wf) {
            if ($wf === '') continue;
            $wheres[] = ei($wf) . " = " . $pdo->quote(isset($whereVals[$i]) ? $whereVals[$i] : '');
        }
        if (!empty($sets) && !empty($wheres)) {
            try {
                $pdo->exec("UPDATE " . ei($tName) . " SET " . implode(', ', $sets) . " WHERE " . implode(' AND ', $wheres) . " LIMIT 1");
                msg("Riga aggiornata.", 'success');
            } catch (PDOException $e) { msg($e->getMessage(), 'danger'); }
        }
    }
    header("Location: ?action=data&db=" . urlencode($db) . "&table=" . urlencode($tName));
    exit;
}

// Delete Row
if ($action === 'delete_row' && $_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    $db = isset($_SESSION['current_db']) ? $_SESSION['current_db'] : '';
    $tName = isset($_POST['table_name']) ? $_POST['table_name'] : '';
    if ($db !== '' && $tName !== '' && isset($_POST['confirm'])) {
        try { $pdo->exec("USE " . ei($db)); } catch (Exception $e) { /* skip */ }
        $whereFields = isset($_POST['where_field']) ? $_POST['where_field'] : array();
        $whereVals = isset($_POST['where_value']) ? $_POST['where_value'] : array();
        $wheres = array();
        foreach ($whereFields as $i => $wf) {
            if ($wf === '') continue;
            $wheres[] = ei($wf) . " = " . $pdo->quote(isset($whereVals[$i]) ? $whereVals[$i] : '');
        }
        if (!empty($wheres)) {
            try {
                $pdo->exec("DELETE FROM " . ei($tName) . " WHERE " . implode(' AND ', $wheres) . " LIMIT 1");
                msg("Riga eliminata.", 'success');
            } catch (PDOException $e) { msg($e->getMessage(), 'danger'); }
        }
    }
    header("Location: ?action=data&db=" . urlencode($db) . "&table=" . urlencode($tName));
    exit;
}

// Drop Routine
if ($action === 'drop_routine' && $_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    $db = isset($_SESSION['current_db']) ? $_SESSION['current_db'] : '';
    $rName = isset($_POST['routine_name']) ? $_POST['routine_name'] : '';
    $rType = isset($_POST['routine_type']) ? $_POST['routine_type'] : 'PROCEDURE';
    if ($db !== '' && $rName !== '' && isset($_POST['confirm'])) {
        try { $pdo->exec("USE " . ei($db)); $pdo->exec("DROP $rType " . ei($rName)); msg("$rType eliminato.", 'success'); }
        catch (PDOException $e) { msg($e->getMessage(), 'danger'); }
    }
    header("Location: ?action=browse&db=" . urlencode($db));
    exit;
}

// Drop View
if ($action === 'drop_view' && $_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    $db = isset($_SESSION['current_db']) ? $_SESSION['current_db'] : '';
    $vName = isset($_POST['view_name']) ? $_POST['view_name'] : '';
    if ($db !== '' && $vName !== '' && isset($_POST['confirm'])) {
        try { $pdo->exec("USE " . ei($db)); $pdo->exec("DROP VIEW " . ei($vName)); msg("Vista eliminata.", 'success'); }
        catch (PDOException $e) { msg($e->getMessage(), 'danger'); }
    }
    header("Location: ?action=browse&db=" . urlencode($db));
    exit;
}

// Backup
if ($action === 'do_backup' && $_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    $db = isset($_POST['backup_db']) ? $_POST['backup_db'] : (isset($_SESSION['current_db']) ? $_SESSION['current_db'] : '');
    if ($db !== '') {
        try { $pdo->exec("USE " . ei($db)); } catch (Exception $e) { /* skip */ }
        $opts = array(
            'structure_only' => isset($_POST['structure_only']),
            'include_routines' => isset($_POST['include_routines'])
        );
        $backup = generateBackup($pdo, $db, $opts);
        header('Content-Type: application/sql; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"" . $db . '_' . date('Ymd_His') . ".sql\"");
        header('Content-Length: ' . strlen($backup));
        echo $backup;
        exit;
    }
}

// Restore
$restoreResult = null;
if ($action === 'do_restore' && $_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    $db = isset($_POST['restore_db']) ? $_POST['restore_db'] : (isset($_SESSION['current_db']) ? $_SESSION['current_db'] : '');
    if ($db !== '') {
        try { $pdo->exec("USE " . ei($db)); } catch (Exception $e) { /* skip */ }
        $sql = '';
        if (isset($_FILES['sql_file']) && $_FILES['sql_file']['error'] === UPLOAD_ERR_OK) {
            $f = fopen($_FILES['sql_file']['tmp_name'], 'r');
            while (!feof($f)) $sql .= fread($f, 8192);
            fclose($f);
            if (substr($_FILES['sql_file']['name'], -3) === '.gz') $sql = gzdecode($sql);
        } elseif (isset($_POST['sql_text']) && $_POST['sql_text'] !== '') {
            $sql = $_POST['sql_text'];
        }
        if ($sql !== '') {
            $restoreResult = executeRestore($pdo, $sql);
            $type = $restoreResult['errors'] > 0 ? 'warning' : 'success';
            msg("Restore: {$restoreResult['ok']} OK, {$restoreResult['errors']} errori.", $type);
        }
    }
    $action = 'restore';
}

// ════════════════════════════════════════════════════════════
//  DATA GATHERING
// ════════════════════════════════════════════════════════════
$databases = array();
$serverInfo = array();
$currentDb = isset($_SESSION['current_db']) ? $_SESSION['current_db'] : '';
$tables = array();
$views = array();
$procedures = array();
$functions = array();
$tableStructure = array();
$tableIndexes = array();
$createView = '';
$createProc = '';
$createFunc = '';
$databasesMeta = array();
$collations = array();
$sidebarObjects = array();
$SYSTEM_DBS = array('information_schema', 'mysql', 'performance_schema', 'sys');

if ($pdo) {
    try { $serverInfo['version'] = $pdo->query("SELECT VERSION()")->fetchColumn(); } catch (Exception $e) { /* skip */ }
    try { $serverInfo['user'] = $pdo->query("SELECT CURRENT_USER()")->fetchColumn(); } catch (Exception $e) { /* skip */ }
    try { $databases = $pdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN); } catch (PDOException $e) { /* skip */ }

    // Sidebar objects
    try {
        $stmt = $pdo->query("SELECT TABLE_SCHEMA, TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES ORDER BY TABLE_SCHEMA, TABLE_TYPE, TABLE_NAME");
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $schema = $r['TABLE_SCHEMA'];
            if (!isset($sidebarObjects[$schema])) $sidebarObjects[$schema] = array('tables' => array(), 'views' => array(), 'procedures' => array(), 'functions' => array());
            if ($r['TABLE_TYPE'] === 'BASE TABLE') $sidebarObjects[$schema]['tables'][] = $r['TABLE_NAME'];
            else $sidebarObjects[$schema]['views'][] = $r['TABLE_NAME'];
        }
    } catch (Exception $e) { /* skip */ }
    try {
        $stmt = $pdo->query("SELECT ROUTINE_SCHEMA, ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES ORDER BY ROUTINE_SCHEMA, ROUTINE_TYPE, ROUTINE_NAME");
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $schema = $r['ROUTINE_SCHEMA'];
            if (!isset($sidebarObjects[$schema])) $sidebarObjects[$schema] = array('tables' => array(), 'views' => array(), 'procedures' => array(), 'functions' => array());
            if ($r['ROUTINE_TYPE'] === 'PROCEDURE') $sidebarObjects[$schema]['procedures'][] = $r['ROUTINE_NAME'];
            else $sidebarObjects[$schema]['functions'][] = $r['ROUTINE_NAME'];
        }
    } catch (Exception $e) { /* skip */ }

    // DB metadata
    try {
        $schemas = $pdo->query("SELECT SCHEMA_NAME, DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA ORDER BY SCHEMA_NAME")->fetchAll(PDO::FETCH_ASSOC);
        $sizeMap = array();
        try {
            $sStmt = $pdo->query("SELECT TABLE_SCHEMA, COUNT(*) as cnt, COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) as total_size FROM information_schema.TABLES GROUP BY TABLE_SCHEMA");
            while ($sr = $sStmt->fetch(PDO::FETCH_ASSOC)) $sizeMap[$sr['TABLE_SCHEMA']] = $sr;
        } catch (Exception $e) { /* skip */ }
        foreach ($schemas as $s) {
            $sn = $s['SCHEMA_NAME'];
            $cnt = isset($sizeMap[$sn]) ? $sizeMap[$sn]['cnt'] : 0;
            $sz = isset($sizeMap[$sn]) ? $sizeMap[$sn]['total_size'] : 0;
            $databasesMeta[] = array('name' => $sn, 'charset' => $s['DEFAULT_CHARACTER_SET_NAME'], 'collation' => $s['DEFAULT_COLLATION_NAME'], 'tables' => $cnt, 'size' => $sz);
        }
    } catch (Exception $e) { /* skip */ }

    // Collations
    try {
        $colls = $pdo->query("SHOW COLLATION")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($colls as $c) $collations[$c['Charset']][] = $c['Collation'];
    } catch (Exception $e) { /* skip */ }

    // Current DB objects
    if ($currentDb !== '') {
        try { $pdo->exec("USE " . ei($currentDb)); } catch (Exception $e) { /* skip */ }
        try {
            $rows = $pdo->query("SHOW FULL TABLES FROM " . ei($currentDb) . " WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
            foreach ($rows as $r) {
                $info = array('name' => $r[0], 'rows' => null, 'engine' => null, 'size' => null);
                try {
                    $ti = $pdo->query("SELECT TABLE_ROWS, ENGINE, DATA_LENGTH + INDEX_LENGTH AS total_size FROM information_schema.TABLES WHERE TABLE_SCHEMA=" . $pdo->quote($currentDb) . " AND TABLE_NAME=" . $pdo->quote($r[0]))->fetch(PDO::FETCH_ASSOC);
                    if ($ti) { $info['rows'] = $ti['TABLE_ROWS']; $info['engine'] = $ti['ENGINE']; $info['size'] = $ti['total_size']; }
                } catch (Exception $e) { /* skip */ }
                $tables[] = $info;
            }
        } catch (Exception $e) { /* skip */ }
        try { $views = $pdo->query("SHOW FULL TABLES FROM " . ei($currentDb) . " WHERE Table_type='VIEW'")->fetchAll(PDO::FETCH_COLUMN, 0); } catch (Exception $e) { /* skip */ }
        try {
            $routines = $pdo->query("SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=" . $pdo->quote($currentDb))->fetchAll(PDO::FETCH_ASSOC);
            foreach ($routines as $r) {
                if ($r['ROUTINE_TYPE'] === 'PROCEDURE') $procedures[] = $r['ROUTINE_NAME'];
                else $functions[] = $r['ROUTINE_NAME'];
            }
        } catch (Exception $e) { /* skip */ }
    }
}

$tableName = isset($_GET['table']) ? $_GET['table'] : (isset($_POST['table_name']) ? $_POST['table_name'] : '');
if ($pdo && $currentDb !== '' && $tableName !== '' && in_array($action, array('structure', 'data'))) {
    try { $pdo->exec("USE " . ei($currentDb)); } catch (Exception $e) { /* skip */ }
    try { $tableStructure = $pdo->query("SHOW COLUMNS FROM " . ei($tableName))->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) { /* skip */ }
    try { $tableIndexes = $pdo->query("SHOW INDEX FROM " . ei($tableName))->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) { /* skip */ }
}

$dataRows = array();
$dataTotal = 0;
$dataPage = max(1, intval(isset($_GET['p']) ? $_GET['p'] : 1));
$sortCol = isset($_GET['sort']) ? $_GET['sort'] : '';
$sortDir = isset($_GET['dir']) ? $_GET['dir'] : 'ASC';
$filter = isset($_GET['filter']) ? $_GET['filter'] : '';

if ($pdo && $currentDb !== '' && $tableName !== '' && $action === 'data') {
    try { $pdo->exec("USE " . ei($currentDb)); } catch (Exception $e) { /* skip */ }
    try {
        $cnt = $pdo->query("SELECT COUNT(*) FROM " . ei($tableName) . ($filter !== '' ? " WHERE $filter" : ''))->fetchColumn();
        $dataTotal = intval($cnt);
        $offset = ($dataPage - 1) * $MAX_ROWS;
        $orderBy = '';
        if ($sortCol !== '') $orderBy = " ORDER BY " . ei($sortCol) . ($sortDir === 'DESC' ? ' DESC' : ' ASC');
        $whereSql = $filter !== '' ? " WHERE $filter" : '';
        $dataRows = $pdo->query("SELECT * FROM " . ei($tableName) . $whereSql . $orderBy . " LIMIT $MAX_ROWS OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { msg("Errore: " . $e->getMessage(), 'danger'); }
}

$viewName = isset($_GET['view']) ? $_GET['view'] : '';
if ($pdo && $currentDb !== '' && $viewName !== '' && $action === 'view_def') {
    try { $pdo->exec("USE " . ei($currentDb)); $cv = $pdo->query("SHOW CREATE VIEW " . ei($viewName))->fetch(PDO::FETCH_NUM); $createView = $cv[1]; } catch (Exception $e) { msg($e->getMessage(), 'danger'); }
}

$routineName = isset($_GET['routine']) ? $_GET['routine'] : '';
$routineType = isset($_GET['rtype']) ? $_GET['rtype'] : 'PROCEDURE';
if ($pdo && $currentDb !== '' && $routineName !== '' && $action === 'routine_def') {
    try {
        $pdo->exec("USE " . ei($currentDb));
        $cr = $pdo->query("SHOW CREATE $routineType " . ei($routineName))->fetch(PDO::FETCH_NUM);
        if ($routineType === 'PROCEDURE') $createProc = $cr[2];
        else $createFunc = $cr[2];
    } catch (Exception $e) { msg($e->getMessage(), 'danger'); }
}

$charsets = array();
if ($pdo) { try { $charsets = $pdo->query("SHOW CHARACTER SET")->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) { /* skip */ } }

// ════════════════════════════════════════════════════════════
//  VIEW NORMALIZATION
// ════════════════════════════════════════════════════════════
$flash = getFlash();
$view = $action;
if (!$pdo) $view = 'login';
elseif ($action === 'home' || $action === 'login') $view = 'databases';
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h($APP_NAME); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#080c12;--bg-s:#0d1219;--bg-p:#131a26;--bg-h:#1a2435;--bg-card:#101822;--border:#1e2a3a;--accent:#e8a830;--accent-h:#f0bc50;--text:#d4dae4;--text-b:#f4f5f7;--text-m:#8a96ab;--green:#34d058;--red:#f85149;--blue:#58a6ff;--yellow:#f0b040;--radius:6px}
html,body{height:100%;background:var(--bg);color:var(--text);font-family:'DM Sans',sans-serif;font-size:14px;line-height:1.5}
a{color:var(--accent);text-decoration:none}a:hover{color:var(--accent-h);text-decoration:underline}
.app{display:grid;grid-template-rows:48px 1fr auto;grid-template-columns:260px 1fr;height:100vh;overflow:hidden}
.topbar{grid-column:1/-1;background:var(--bg-s);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 16px;gap:16px;z-index:10}
.topbar .logo{font-weight:700;font-size:15px;color:var(--accent);white-space:nowrap;text-decoration:none}.topbar .logo:hover{color:var(--accent-h);text-decoration:none}
.topbar .server-info{font-size:12px;color:var(--text-m);font-family:'JetBrains Mono',monospace}
.topbar .spacer{flex:1}
.topbar nav{display:flex;gap:4px}
.topbar nav a{padding:6px 12px;border-radius:var(--radius);font-size:12px;font-weight:500;color:var(--text-m);transition:all .15s}
.topbar nav a:hover,.topbar nav a.active{background:var(--bg-h);color:var(--text-b);text-decoration:none}
.sidebar{background:var(--bg-s);border-right:1px solid var(--border);overflow-y:auto;padding:8px 0}
.sidebar::-webkit-scrollbar{width:6px}.sidebar::-webkit-scrollbar-thumb{background:var(--border);border-radius:3px}
.sidebar .section-title{padding:8px 16px 6px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:1px;color:var(--text-m)}
.db-row{display:flex;align-items:center}
.db-arrow{display:inline-flex;align-items:center;justify-content:center;width:22px;height:28px;cursor:pointer;font-size:11px;color:var(--text-m);transition:transform .15s;flex-shrink:0;user-select:none}
.db-arrow.open{transform:rotate(90deg)}
.db-label{flex:1;padding:5px 12px 5px 2px;font-size:13px;color:var(--text);text-decoration:none;cursor:pointer;border-left:3px solid transparent;transition:all .1s}
.db-label:hover{background:var(--bg-h);text-decoration:none}.db-label.active{border-left-color:var(--accent);color:var(--accent);background:var(--bg-h)}
.obj-item{display:block;padding:4px 12px 4px 34px;font-size:12px;color:var(--text-m);font-family:'JetBrains Mono',monospace;transition:all .1s;text-decoration:none}
.obj-item:hover{background:var(--bg-h);color:var(--text-b);text-decoration:none}
.obj-item.tbl::before{content:'⊞ ';color:var(--blue)}.obj-item.vw::before{content:'◉ ';color:var(--green)}.obj-item.sp::before{content:'ƒ ';color:var(--yellow)}.obj-item.fn::before{content:'ƒ ';color:#c084fc}
.main{overflow-y:auto;padding:20px 24px;background:var(--bg)}
.statusbar{grid-column:1/-1;background:var(--bg-s);border-top:1px solid var(--border);padding:4px 16px;font-size:11px;color:var(--text-m);display:flex;gap:16px;font-family:'JetBrains Mono',monospace}
.panel{background:var(--bg-p);border:1px solid var(--border);border-radius:var(--radius);margin-bottom:16px}
.panel-header{padding:12px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:12px}
.panel-header h2{font-size:14px;font-weight:600;color:var(--text-b)}.panel-body{padding:16px}
table.data{width:100%;border-collapse:collapse;font-size:13px}
table.data th{background:var(--bg-s);padding:8px 12px;text-align:left;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-m);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:2;white-space:nowrap}
table.data td{padding:7px 12px;border-bottom:1px solid var(--border);font-family:'JetBrains Mono',monospace;font-size:12px;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--text)}
table.data tr:hover td{background:var(--bg-h)}table.data td.null-val{color:var(--text-m);font-style:italic}table.data td.num{text-align:right}
button,.btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:var(--radius);font-size:12px;font-weight:500;font-family:'DM Sans',sans-serif;cursor:pointer;border:1px solid var(--border);background:var(--bg-p);color:var(--text);transition:all .15s}
button:hover,.btn:hover{background:var(--bg-h);color:var(--text-b);text-decoration:none}
.btn-accent{background:var(--accent);color:#000;border-color:var(--accent);font-weight:600}.btn-accent:hover{background:var(--accent-h);color:#000}
.btn-danger{color:var(--red);border-color:rgba(248,81,73,.3)}.btn-danger:hover{background:rgba(248,81,73,.15);border-color:var(--red)}
.btn-sm{padding:4px 10px;font-size:11px}.btn-group{display:flex;gap:6px;flex-wrap:wrap}
input[type="text"],input[type="number"],input[type="password"],input[type="date"],textarea,select{background:var(--bg);border:1px solid var(--border);color:var(--text-b);padding:7px 10px;border-radius:var(--radius);font-family:'JetBrains Mono',monospace;font-size:12px;transition:border-color .15s;width:100%}
input:focus,textarea:focus,select:focus{outline:none;border-color:var(--accent)}
textarea{resize:vertical;min-height:80px}
label{display:block;font-size:12px;font-weight:500;color:var(--text-m);margin-bottom:4px}
.form-row{margin-bottom:12px}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.form-grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}
.flash{padding:10px 14px;border-radius:var(--radius);margin-bottom:12px;font-size:13px;border:1px solid}
.flash.success{background:rgba(52,208,88,.1);border-color:rgba(52,208,88,.3);color:var(--green)}
.flash.danger{background:rgba(248,81,73,.1);border-color:rgba(248,81,73,.3);color:var(--red)}
.flash.warning{background:rgba(240,176,64,.1);border-color:rgba(240,176,64,.3);color:var(--yellow)}
.flash.info{background:rgba(88,166,255,.1);border-color:rgba(88,166,255,.3);color:var(--blue)}
code{font-family:'JetBrains Mono',monospace;font-size:12px;background:var(--bg);padding:2px 6px;border-radius:3px;color:var(--accent)}
pre.sql-block{background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);padding:16px;overflow-x:auto;font-family:'JetBrains Mono',monospace;font-size:12px;line-height:1.6;color:var(--green);white-space:pre-wrap;word-break:break-all;max-height:500px;overflow-y:auto}
.pagination{display:flex;gap:4px;align-items:center;padding-top:12px}
.pagination a,.pagination span{padding:5px 10px;border-radius:var(--radius);font-size:12px;border:1px solid var(--border)}
.pagination span.current{background:var(--accent);color:#000;border-color:var(--accent);font-weight:600}
.tabs{display:flex;gap:0;border-bottom:1px solid var(--border);margin-bottom:16px}
.tabs a{padding:10px 18px;font-size:13px;font-weight:500;color:var(--text-m);border-bottom:2px solid transparent;transition:all .15s}
.tabs a:hover{color:var(--text-b);text-decoration:none}.tabs a.active{color:var(--accent);border-bottom-color:var(--accent)}
.tag{display:inline-block;padding:2px 8px;border-radius:3px;font-size:10px;font-weight:600;text-transform:uppercase}
.tag-engine{background:rgba(88,166,255,.15);color:var(--blue)}.tag-system{background:rgba(138,150,171,.15);color:var(--text-m)}.tag-charset{background:rgba(52,208,88,.12);color:var(--green)}
.login-wrap{display:flex;align-items:center;justify-content:center;height:100vh;background:var(--bg)}
.login-box{background:var(--bg-p);border:1px solid var(--border);border-radius:10px;padding:40px;width:380px}
.login-box h1{font-size:22px;font-weight:700;color:var(--accent);text-align:center;margin-bottom:4px}
.login-box p{text-align:center;color:var(--text-m);font-size:12px;margin-bottom:24px}
.empty-state{text-align:center;padding:40px 20px;color:var(--text-m)}
.confirm-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:100;align-items:center;justify-content:center}
.confirm-overlay.show{display:flex}
.confirm-box{background:var(--bg-p);border:1px solid var(--border);border-radius:10px;padding:24px;max-width:400px;width:90%}
.confirm-box h3{margin-bottom:12px;color:var(--text-b)}
.db-summary{display:flex;gap:24px;padding:12px 16px;border-bottom:1px solid var(--border);font-size:12px;color:var(--text-m)}
.db-summary strong{color:var(--text-b);font-size:16px}
.col-card{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);margin-bottom:12px;overflow:hidden}
.col-card-header{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:var(--bg-s);border-bottom:1px solid var(--border)}
.col-card-header .col-num{font-size:12px;font-weight:600;color:var(--text-m)}
.col-card-body{padding:14px}
.col-options{display:flex;flex-wrap:wrap;gap:16px;margin-top:10px;padding-top:10px;border-top:1px solid var(--border)}
.col-opt{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text);cursor:pointer}
.col-opt input[type="checkbox"]{width:auto;margin:0;accent-color:var(--accent)}
.default-presets{display:flex;gap:4px;margin-top:6px;flex-wrap:wrap;align-items:center}
.default-presets .preset-btn{padding:3px 8px;font-size:10px;border-radius:3px;background:var(--bg-h);border:1px solid var(--border);color:var(--text-m);cursor:pointer;transition:all .12s}
.default-presets .preset-btn:hover{border-color:var(--accent);color:var(--accent)}
@media(max-width:900px){.app{grid-template-columns:1fr}.sidebar{display:none}}
@keyframes fadeIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}.main>*{animation:fadeIn .25s ease forwards}
::-webkit-scrollbar{width:8px;height:8px}::-webkit-scrollbar-track{background:transparent}::-webkit-scrollbar-thumb{background:var(--border);border-radius:4px}
</style>
</head>
<body>
<?php if ($view === 'login'): ?>
<div class="login-wrap">
<div class="login-box">
    <h1><?php echo h($APP_NAME); ?></h1>
    <p>Connettiti al tuo server MariaDB</p>
    <?php foreach ($flash as $f): ?>
        <div class="flash <?php echo h($f['type']); ?>"><?php echo h($f['text']); ?></div>
    <?php endforeach; ?>
    <form method="post" action="?action=login">
        <input type="hidden" name="action" value="login">
        <div class="form-grid">
            <div class="form-row"><label>Host</label><input type="text" name="host" value="localhost" required></div>
            <div class="form-row"><label>Porta</label><input type="number" name="port" value="3306" required></div>
        </div>
        <div class="form-row"><label>Utente</label><input type="text" name="user" value="root" required autofocus></div>
        <div class="form-row"><label>Password</label><input type="password" name="pass" value=""></div>
        <button type="submit" class="btn-accent" style="width:100%;justify-content:center;padding:10px;margin-top:8px">Connetti</button>
    </form>
</div>
</div>
<?php else: ?>
<div class="app">
<div class="topbar">
    <a href="?action=databases" class="logo"><?php echo h($APP_NAME); ?></a>
    <span class="server-info"><?php echo h(isset($serverInfo['version']) ? $serverInfo['version'] : ''); ?> &middot; <?php echo h(isset($serverInfo['user']) ? $serverInfo['user'] : ''); ?></span>
    <div class="spacer"></div>
    <nav>
        <a href="?action=databases" class="<?php echo $view === 'databases' ? 'active' : ''; ?>">Database</a>
        <a href="?action=backup" class="<?php echo $view === 'backup' ? 'active' : ''; ?>">Backup</a>
        <a href="?action=restore" class="<?php echo $view === 'restore' ? 'active' : ''; ?>">Restore</a>
        <a href="?action=query&db=<?php echo urlencode($currentDb); ?>" class="<?php echo $view === 'query' ? 'active' : ''; ?>">SQL</a>
        <a href="?action=logout" style="color:var(--red)">Esci</a>
    </nav>
</div>
<!-- SIDEBAR -->
<div class="sidebar">
    <div class="section-title">Database</div>
    <?php foreach ($databases as $db): ?>
    <?php $isCurrent = ($db === $currentDb); ?>
    <?php $objs = isset($sidebarObjects[$db]) ? $sidebarObjects[$db] : array('tables' => array(), 'views' => array(), 'procedures' => array(), 'functions' => array()); ?>
    <div class="db-node">
        <div class="db-row">
            <span class="db-arrow <?php echo $isCurrent ? 'open' : ''; ?>" onclick="toggleDbTree(this)">&#9656;</span>
            <a href="?action=use_db&db=<?php echo urlencode($db); ?>" class="db-label <?php echo $isCurrent ? 'active' : ''; ?>"><?php echo h($db); ?></a>
        </div>
        <div class="db-children" <?php echo $isCurrent ? '' : 'style="display:none"'; ?>>
            <?php foreach ($objs['tables'] as $tbl): ?>
            <?php $tblActive = ($db === $currentDb && $tbl === $tableName) ? ' active' : ''; ?>
            <a class="obj-item tbl<?php echo $tblActive; ?>" href="?action=structure&db=<?php echo urlencode($db); ?>&table=<?php echo urlencode($tbl); ?>"><?php echo h($tbl); ?></a>
            <?php endforeach; ?>
            <?php foreach ($objs['views'] as $vw): ?>
            <a class="obj-item vw" href="?action=view_def&db=<?php echo urlencode($db); ?>&view=<?php echo urlencode($vw); ?>"><?php echo h($vw); ?></a>
            <?php endforeach; ?>
            <?php foreach ($objs['procedures'] as $sp): ?>
            <a class="obj-item sp" href="?action=routine_def&db=<?php echo urlencode($db); ?>&routine=<?php echo urlencode($sp); ?>&rtype=PROCEDURE"><?php echo h($sp); ?></a>
            <?php endforeach; ?>
            <?php foreach ($objs['functions'] as $fn): ?>
            <a class="obj-item fn" href="?action=routine_def&db=<?php echo urlencode($db); ?>&routine=<?php echo urlencode($fn); ?>&rtype=FUNCTION"><?php echo h($fn); ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<!-- MAIN -->
<div class="main">
<?php foreach ($flash as $f): ?>
    <div class="flash <?php echo h($f['type']); ?>"><?php echo h($f['text']); ?></div>
<?php endforeach; ?>

<?php if ($view === 'databases'): ?>
<h2 style="font-size:18px;margin-bottom:16px">Tutti i Database</h2>
<div class="panel">
    <div class="panel-header"><h2>Database (<?php echo count($databasesMeta); ?>)</h2></div>
    <?php $totalTables = 0; $totalSize = 0; foreach ($databasesMeta as $dm) { $totalTables += $dm['tables']; $totalSize += $dm['size']; } ?>
    <div class="db-summary">
        <div>Totale: <strong><?php echo count($databasesMeta); ?></strong> database</div>
        <div>Tabelle: <strong><?php echo number_format($totalTables); ?></strong></div>
        <div>Dimensione: <strong><?php echo fb($totalSize); ?></strong></div>
    </div>
    <div class="panel-body" style="padding:0">
        <table class="data">
            <thead><tr><th>Database</th><th>Charset</th><th>Collation</th><th style="text-align:right">Tabelle</th><th style="text-align:right">Dimensione</th><th>Azioni</th></tr></thead>
            <tbody>
            <?php foreach ($databasesMeta as $dm): ?>
            <?php $isSys = in_array($dm['name'], $SYSTEM_DBS); ?>
            <tr>
                <td><a href="?action=use_db&db=<?php echo urlencode($dm['name']); ?>" style="font-weight:600"><?php echo h($dm['name']); ?></a> <?php if ($isSys): ?><span class="tag tag-system">sistema</span><?php endif; ?></td>
                <td><span class="tag tag-charset"><?php echo h($dm['charset']); ?></span></td>
                <td><?php echo h($dm['collation']); ?></td>
                <td class="num"><?php echo number_format($dm['tables']); ?></td>
                <td class="num"><?php echo fb($dm['size']); ?></td>
                <td><div class="btn-group">
                    <a href="?action=use_db&db=<?php echo urlencode($dm['name']); ?>" class="btn btn-sm">Sfoglia</a>
                    <?php if (!$isSys): ?>
                    <form method="post" action="?action=drop_db" style="display:inline" onsubmit="return confirm('Eliminare database?')"><input type="hidden" name="db_name" value="<?php echo h($dm['name']); ?>"><input type="hidden" name="confirm" value="1"><button class="btn btn-sm btn-danger">Elimina</button></form>
                    <?php endif; ?>
                </div></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<div class="panel">
    <div class="panel-header"><h2>Crea Nuovo Database</h2></div>
    <div class="panel-body">
        <form method="post" action="?action=create_db" style="display:flex;gap:16px;align-items:flex-end;flex-wrap:wrap">
            <div style="flex:1;min-width:200px"><label>Nome Database</label><input type="text" name="db_name" placeholder="mio_database" required></div>
            <div style="flex:1;min-width:250px"><label>Collazione</label>
                <select name="collation">
                    <?php foreach ($collations as $charset => $colls): ?>
                    <optgroup label="<?php echo h($charset); ?>">
                        <?php foreach ($colls as $coll): ?>
                        <option value="<?php echo h($coll); ?>" <?php echo $coll === 'utf8mb4_general_ci' ? 'selected' : ''; ?>><?php echo h($coll); ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn-accent">Crea Database</button>
        </form>
    </div>
</div>

<?php elseif ($view === 'browse'): ?>
<h2 style="font-size:18px;margin-bottom:16px">Database: <span style="color:var(--accent)"><?php echo h($currentDb); ?></span></h2>
<div class="panel">
    <div class="panel-header"><h2>Tabelle (<?php echo count($tables); ?>)</h2><a href="?action=create_table_form&db=<?php echo urlencode($currentDb); ?>" class="btn btn-accent btn-sm">+ Nuova Tabella</a></div>
    <div class="panel-body" style="padding:0">
        <?php if (empty($tables)): ?><div class="empty-state"><p>Nessuna tabella.</p></div>
        <?php else: ?>
        <table class="data"><thead><tr><th>Nome</th><th>Righe</th><th>Engine</th><th>Dimensione</th><th>Azioni</th></tr></thead><tbody>
        <?php foreach ($tables as $t): ?>
        <tr>
            <td><a href="?action=structure&db=<?php echo urlencode($currentDb); ?>&table=<?php echo urlencode($t['name']); ?>" style="font-weight:500"><?php echo h($t['name']); ?></a></td>
            <td class="num"><?php echo $t['rows'] !== null ? number_format($t['rows']) : '—'; ?></td>
            <td><span class="tag tag-engine"><?php echo h($t['engine']); ?></span></td>
            <td class="num"><?php echo $t['size'] !== null ? fb($t['size']) : '—'; ?></td>
            <td><div class="btn-group">
                <a href="?action=data&db=<?php echo urlencode($currentDb); ?>&table=<?php echo urlencode($t['name']); ?>" class="btn btn-sm">Dati</a>
                <a href="?action=structure&db=<?php echo urlencode($currentDb); ?>&table=<?php echo urlencode($t['name']); ?>" class="btn btn-sm">Struttura</a>
                <form method="post" action="?action=truncate_table" style="display:inline" onsubmit="return confirm('Svuotare?')"><input type="hidden" name="table_name" value="<?php echo h($t['name']); ?>"><input type="hidden" name="confirm" value="1"><button class="btn btn-sm btn-danger">Svuota</button></form>
                <form method="post" action="?action=drop_table" style="display:inline" onsubmit="return confirm('Eliminare?')"><input type="hidden" name="table_name" value="<?php echo h($t['name']); ?>"><input type="hidden" name="confirm" value="1"><button class="btn btn-sm btn-danger">Elimina</button></form>
            </div></td>
        </tr>
        <?php endforeach; ?></tbody></table>
        <?php endif; ?>
    </div>
</div>
<?php if (!empty($views)): ?>
<div class="panel"><div class="panel-header"><h2>Viste (<?php echo count($views); ?>)</h2></div><div class="panel-body" style="padding:0"><table class="data"><thead><tr><th>Nome</th><th>Azioni</th></tr></thead><tbody>
<?php foreach ($views as $v): ?><tr><td><a href="?action=view_def&db=<?php echo urlencode($currentDb); ?>&view=<?php echo urlencode($v); ?>"><?php echo h($v); ?></a></td><td><form method="post" action="?action=drop_view" style="display:inline" onsubmit="return confirm('Eliminare?')"><input type="hidden" name="view_name" value="<?php echo h($v); ?>"><input type="hidden" name="confirm" value="1"><button class="btn btn-sm btn-danger">Elimina</button></form></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php endif; ?>
<?php if (!empty($procedures) || !empty($functions)): ?>
<div class="panel"><div class="panel-header"><h2>Routine</h2></div><div class="panel-body" style="padding:0"><table class="data"><thead><tr><th>Nome</th><th>Tipo</th><th>Azioni</th></tr></thead><tbody>
<?php foreach ($procedures as $p): ?><tr><td><a href="?action=routine_def&db=<?php echo urlencode($currentDb); ?>&routine=<?php echo urlencode($p); ?>&rtype=PROCEDURE"><?php echo h($p); ?></a></td><td><span class="tag" style="background:rgba(240,176,64,.15);color:var(--yellow)">PROCEDURE</span></td><td><form method="post" action="?action=drop_routine" style="display:inline" onsubmit="return confirm('Eliminare?')"><input type="hidden" name="routine_name" value="<?php echo h($p); ?>"><input type="hidden" name="routine_type" value="PROCEDURE"><input type="hidden" name="confirm" value="1"><button class="btn btn-sm btn-danger">Elimina</button></form></td></tr><?php endforeach; ?>
<?php foreach ($functions as $fn): ?><tr><td><a href="?action=routine_def&db=<?php echo urlencode($currentDb); ?>&routine=<?php echo urlencode($fn); ?>&rtype=FUNCTION"><?php echo h($fn); ?></a></td><td><span class="tag" style="background:rgba(192,132,252,.15);color:#c084fc">FUNCTION</span></td><td><form method="post" action="?action=drop_routine" style="display:inline" onsubmit="return confirm('Eliminare?')"><input type="hidden" name="routine_name" value="<?php echo h($fn); ?>"><input type="hidden" name="routine_type" value="FUNCTION"><input type="hidden" name="confirm" value="1"><button class="btn btn-sm btn-danger">Elimina</button></form></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php endif; ?>

<?php elseif ($view === 'structure'): ?>
<h2 style="font-size:18px;margin-bottom:4px"><a href="?action=browse&db=<?php echo urlencode($currentDb); ?>" style="color:var(--text-m)"><?php echo h($currentDb); ?></a> <span style="color:var(--text-m)">/</span> <span style="color:var(--accent)"><?php echo h($tableName); ?></span></h2>
<div class="tabs">
    <a href="?action=structure&db=<?php echo urlencode($currentDb); ?>&table=<?php echo urlencode($tableName); ?>" class="active">Struttura</a>
    <a href="?action=data&db=<?php echo urlencode($currentDb); ?>&table=<?php echo urlencode($tableName); ?>">Dati</a>
    <a href="?action=query&db=<?php echo urlencode($currentDb); ?>&table=<?php echo urlencode($tableName); ?>">SQL</a>
</div>
<div class="panel">
    <div class="panel-header"><h2>Colonne (<?php echo count($tableStructure); ?>)</h2></div>
    <div class="panel-body" style="padding:0;overflow-x:auto">
        <table class="data">
            <thead><tr><th>Nome</th><th>Tipo</th><th>Null</th><th>Default</th><th>Extra</th><th>Chiave</th><th>Azioni</th></tr></thead>
            <tbody>
            <?php foreach ($tableStructure as $col): ?>
            <?php
                $colField = $col['Field'];
                $colType = $col['Type'];
                $colNull = $col['Null'];
                $colDefault = $col['Default'];
                $colExtra = $col['Extra'];
                $colKey = $col['Key'];
                $colDefVal = isset($colDefault) ? $colDefault : '';
            ?>
            <tr>
                <td style="color:var(--text-b);font-weight:500"><?php echo h($colField); ?></td>
                <td><?php echo h($colType); ?></td>
                <td><?php echo $colNull === 'YES' ? 'SI' : '<span style="color:var(--red)">NO</span>'; ?></td>
                <td><?php echo isset($colDefault) ? h($colDefault) : '<span class="null-val">NULL</span>'; ?></td>
                <td><?php echo h($colExtra); ?></td>
                <td><?php if ($colKey === 'PRI') echo '<span style="color:var(--accent)">PK</span>'; elseif ($colKey === 'UNI') echo '<span style="color:var(--blue)">UNI</span>'; elseif ($colKey === 'MUL') echo '<span style="color:var(--text-m)">MUL</span>'; ?></td>
                <td><div class="btn-group">
                    <button class="btn btn-sm" onclick="modifyColumn('<?php echo h(addslashes($colField)); ?>','<?php echo h(addslashes($colType)); ?>','<?php echo h($colNull); ?>','<?php echo h(addslashes($colDefVal)); ?>','<?php echo h(addslashes($colExtra)); ?>')">Modifica</button>
                    <form method="post" action="?action=drop_column" style="display:inline" onsubmit="return confirm('Eliminare colonna?')"><input type="hidden" name="table_name" value="<?php echo h($tableName); ?>"><input type="hidden" name="col_name" value="<?php echo h($colField); ?>"><input type="hidden" name="confirm" value="1"><button class="btn btn-sm btn-danger">Elimina</button></form>
                </div></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php if (!empty($tableIndexes)): ?>
<div class="panel"><div class="panel-header"><h2>Indici</h2></div><div class="panel-body" style="padding:0"><table class="data"><thead><tr><th>Nome</th><th>Colonna</th><th>Unico</th><th>Tipo</th></tr></thead><tbody>
<?php $seenIdx = array(); foreach ($tableIndexes as $idx): ?>
<?php if (isset($seenIdx[$idx['Key_name']])): ?>
<tr><td></td><td><?php echo h($idx['Column_name']); ?></td><td></td><td></td></tr>
<?php else: $seenIdx[$idx['Key_name']] = true; ?>
<tr><td style="font-weight:500"><?php echo h($idx['Key_name']); ?></td><td><?php echo h($idx['Column_name']); ?></td><td><?php echo $idx['Non_unique'] == 0 ? '<span style="color:var(--green)">SI</span>' : 'NO'; ?></td><td><?php echo h($idx['Index_type']); ?></td></tr>
<?php endif; endforeach; ?>
</tbody></table></div></div>
<?php endif; ?>
<!-- Add Column -->
<div class="panel"><div class="panel-header"><h2>Aggiungi Colonna</h2></div><div class="panel-body">
    <form method="post" action="?action=exec_sql"><input type="hidden" name="sql_db" value="<?php echo h($currentDb); ?>"><input type="hidden" name="sql_text" id="addColSQL">
        <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
            <div><label>Nome</label><input type="text" id="ac_name" style="width:150px" required></div>
            <div><label>Tipo</label><select id="ac_type" style="width:130px"><option>INT</option><option>VARCHAR</option><option>TEXT</option><option>BIGINT</option><option>DECIMAL</option><option>FLOAT</option><option>DOUBLE</option><option>DATE</option><option>DATETIME</option><option>TIMESTAMP</option><option>BOOLEAN</option><option>BLOB</option><option>ENUM</option><option>JSON</option></select></div>
            <div><label>Lunghezza</label><input type="number" id="ac_len" style="width:80px" placeholder="255"></div>
            <div><label>&nbsp;</label><label style="display:flex;align-items:center;gap:4px"><input type="checkbox" id="ac_nn" style="width:auto"> NOT NULL</label></div>
            <div><label>Default</label><input type="text" id="ac_def" style="width:120px" placeholder="NULL"></div>
            <button type="submit" class="btn-accent btn-sm" onclick="buildAddColSQL()">Aggiungi</button>
        </div>
    </form>
</div></div>
<script>
function buildAddColSQL(){
    var n=document.getElementById('ac_name').value;
    var t=document.getElementById('ac_type').value;
    var l=document.getElementById('ac_len').value;
    var nn=document.getElementById('ac_nn').checked;
    var d=document.getElementById('ac_def').value;
    var s='ALTER TABLE `<?php echo h($tableName); ?>` ADD COLUMN `'+n+'` '+t;
    if(l)s+='('+l+')';
    if(nn)s+=' NOT NULL';
    if(d)s+=" DEFAULT '"+d.replace(/'/g,"\\'")+"'";
    document.getElementById('addColSQL').value=s;
}
</script>
<!-- Modify Column Modal -->
<div id="modify-col-modal" class="confirm-overlay">
<div class="confirm-box" style="max-width:520px;max-height:85vh;overflow-y:auto">
    <h3>Modifica Colonna</h3>
    <form method="post" action="?action=modify_column">
        <input type="hidden" name="table_name" value="<?php echo h($tableName); ?>">
        <input type="hidden" name="col_name" id="mc_name">
        <div class="form-row"><label>Nome Colonna</label><input type="text" name="new_name" id="mc_new_name" required></div>
        <div class="form-grid">
            <div class="form-row"><label>Tipo</label><select name="col_type" id="mc_type">
                <?php foreach (array('INT','TINYINT','SMALLINT','MEDIUMINT','BIGINT','DECIMAL','FLOAT','DOUBLE','CHAR','VARCHAR','TINYTEXT','TEXT','MEDIUMTEXT','LONGTEXT','DATE','DATETIME','TIMESTAMP','TIME','YEAR','BINARY','VARBINARY','TINYBLOB','BLOB','MEDIUMBLOB','LONGBLOB','BOOLEAN','ENUM','SET','JSON','BIT') as $t): ?>
                <option value="<?php echo $t; ?>"><?php echo $t; ?></option>
                <?php endforeach; ?>
            </select></div>
            <div class="form-row"><label>Lunghezza / Valori</label><input type="text" name="col_length" id="mc_length" placeholder="255 oppure 'si','no'"></div>
        </div>
        <div class="form-grid">
            <div class="form-row"><label style="display:flex;align-items:center;gap:8px;cursor:pointer"><input type="checkbox" name="col_nullable" id="mc_nullable" value="1" style="width:auto"> NOT NULL</label></div>
            <div class="form-row"><label style="display:flex;align-items:center;gap:8px;cursor:pointer"><input type="checkbox" name="col_unsigned" id="mc_unsigned" value="1" style="width:auto"> UNSIGNED</label></div>
        </div>
        <div class="form-grid">
            <div class="form-row"><label>Default</label><input type="text" name="col_default" id="mc_default" placeholder="NULL, CURRENT_TIMESTAMP..."></div>
            <div class="form-row"><label>Extra</label><input type="text" name="col_extra" id="mc_extra" placeholder="AUTO_INCREMENT..."></div>
        </div>
        <div class="form-row"><label>Posizione</label><select name="col_position" id="mc_position"><option value="__KEEP__">Invariata</option><option value="FIRST">Prima colonna</option><?php foreach ($tableStructure as $col): ?><option value="<?php echo h($col['Field']); ?>">Dopo "<?php echo h($col['Field']); ?>"</option><?php endforeach; ?></select></div>
        <div style="display:flex;gap:8px;margin-top:16px"><button type="submit" class="btn-accent">Salva Modifiche</button><button type="button" class="btn" onclick="document.getElementById('modify-col-modal').classList.remove('show')">Annulla</button></div>
    </form>
</div>
</div>
<script>
function parseColumnType(s){s=s.trim().toLowerCase();var u=s.indexOf('unsigned')!==-1;var c=s.replace(/\s*(unsigned|zerofill)\s*/g,'').trim();var m=c.match(/^([a-z_]+)(?:$$(.+)$$)?$/);return{type:m?m[1].toUpperCase():c.toUpperCase(),length:m&&m[2]?m[2].trim():'',unsigned:u}}
function modifyColumn(name,type,nullable,defVal,extra){
    document.getElementById('mc_name').value=name;document.getElementById('mc_new_name').value=name;
    var p=parseColumnType(type);var sel=document.getElementById('mc_type');var found=false;
    for(var i=0;i<sel.options.length;i++){if(sel.options[i].value===p.type){sel.selectedIndex=i;found=true;break;}}
    if(!found){var o=document.createElement('option');o.value=p.type;o.textContent=p.type;o.selected=true;sel.insertBefore(o,sel.firstChild);}
    document.getElementById('mc_length').value=p.length;document.getElementById('mc_unsigned').checked=p.unsigned;
    document.getElementById('mc_nullable').checked=(nullable!=='YES');
    document.getElementById('mc_default').value=defVal||'';document.getElementById('mc_extra').value=extra||'';
    document.getElementById('mc_position').selectedIndex=0;
    document.getElementById('modify-col-modal').classList.add('show');
}
</script>

<?php elseif ($view === 'data'): ?>
<h2 style="font-size:18px;margin-bottom:4px"><a href="?action=browse&db=<?php echo urlencode($currentDb); ?>" style="color:var(--text-m)"><?php echo h($currentDb); ?></a> <span style="color:var(--text-m)">/</span> <a href="?action=structure&db=<?php echo urlencode($currentDb); ?>&table=<?php echo urlencode($tableName); ?>" style="color:var(--accent)"><?php echo h($tableName); ?></a></h2>
<div class="tabs">
    <a href="?action=structure&db=<?php echo urlencode($currentDb); ?>&table=<?php echo urlencode($tableName); ?>">Struttura</a>
    <a href="?action=data&db=<?php echo urlencode($currentDb); ?>&table=<?php echo urlencode($tableName); ?>" class="active">Dati</a>
    <a href="?action=query&db=<?php echo urlencode($currentDb); ?>&table=<?php echo urlencode($tableName); ?>">SQL</a>
</div>
<div class="panel" style="margin-bottom:12px"><div class="panel-body" style="padding:10px 16px">
    <form method="get" style="display:flex;gap:12px;align-items:flex-end"><input type="hidden" name="action" value="data"><input type="hidden" name="db" value="<?php echo h($currentDb); ?>"><input type="hidden" name="table" value="<?php echo h($tableName); ?>">
        <div style="flex:1"><label>Filtro WHERE</label><input type="text" name="filter" value="<?php echo h($filter); ?>" placeholder="es: id > 10"></div>
        <button type="submit" class="btn-accent btn-sm">Filtra</button>
        <a href="?action=data&db=<?php echo urlencode($currentDb); ?>&table=<?php echo urlencode($tableName); ?>" class="btn btn-sm">Reset</a>
    </form>
</div></div>
<div class="panel">
    <div class="panel-header"><h2>Dati — <?php echo number_format($dataTotal); ?> righe</h2><a href="#" class="btn btn-accent btn-sm" onclick="document.getElementById('insert-form').style.display='block';return false">+ Inserisci Riga</a></div>
    <div class="panel-body" style="padding:0;overflow-x:auto">
    <?php if (empty($dataRows)): ?><div class="empty-state"><p>Nessun dato.</p></div>
    <?php else: ?>
    <table class="data"><thead><tr><th>#</th>
    <?php $colNames = array_keys($dataRows[0]); foreach ($colNames as $c): ?>
    <?php $newDir = ($sortCol === $c && $sortDir === 'ASC') ? 'DESC' : 'ASC'; ?>
    <th><a href="?action=data&db=<?php echo urlencode($currentDb); ?>&table=<?php echo urlencode($tableName); ?>&sort=<?php echo urlencode($c); ?>&dir=<?php echo $newDir; ?>&filter=<?php echo urlencode($filter); ?>" style="color:var(--text-m)"><?php echo h($c); ?> <?php echo $sortCol === $c ? ($sortDir === 'ASC' ? '&#8593;' : '&#8595;') : ''; ?></a></th>
    <?php endforeach; ?><th>Azioni</th></tr></thead><tbody>
    <?php $rowNum = ($dataPage - 1) * $MAX_ROWS; foreach ($dataRows as $row): $rowNum++; ?>
    <tr><td class="num" style="color:var(--text-m)"><?php echo $rowNum; ?></td>
    <?php foreach ($row as $col => $val): ?>
    <td class="<?php echo $val === null ? 'null-val' : ''; ?>"><?php echo $val === null ? 'NULL' : h(mb_strimwidth((string)$val, 0, 80, '...')); ?></td>
    <?php endforeach; ?>
    <td><div class="btn-group">
        <button class="btn btn-sm" onclick="editRow(this)" data-row='<?php echo htmlspecialchars(json_encode($row), ENT_QUOTES); ?>'>Modifica</button>
        <form method="post" action="?action=delete_row" style="display:inline" onsubmit="return confirm('Eliminare?')"><input type="hidden" name="table_name" value="<?php echo h($tableName); ?>"><input type="hidden" name="confirm" value="1"><?php foreach ($row as $c => $v): ?><input type="hidden" name="where_field[]" value="<?php echo h($c); ?>"><input type="hidden" name="where_value[]" value="<?php echo h((string)$v); ?>"><?php endforeach; ?><button class="btn btn-sm btn-danger">Elimina</button></form>
    </div></td></tr>
    <?php endforeach; ?></tbody></table>
    <?php endif; ?>
    </div>
</div>
<?php $totalPages = max(1, ceil($dataTotal / $MAX_ROWS)); if ($totalPages > 1): ?>
<div class="pagination">
    <?php if ($dataPage > 1): ?><a href="?action=data&db=<?php echo urlencode($currentDb); ?>&table=<?php echo urlencode($tableName); ?>&p=<?php echo $dataPage - 1; ?>&sort=<?php echo urlencode($sortCol); ?>&dir=<?php echo urlencode($sortDir); ?>&filter=<?php echo urlencode($filter); ?>">&larr; Prec</a><?php endif; ?>
    <?php for ($pg = max(1, $dataPage - 3); $pg <= min($totalPages, $dataPage + 3); $pg++): ?>
    <?php if ($pg === $dataPage): ?><span class="current"><?php echo $pg; ?></span>
    <?php else: ?><a href="?action=data&db=<?php echo urlencode($currentDb); ?>&table=<?php echo urlencode($tableName); ?>&p=<?php echo $pg; ?>&sort=<?php echo urlencode($sortCol); ?>&dir=<?php echo urlencode($sortDir); ?>&filter=<?php echo urlencode($filter); ?>"><?php echo $pg; ?></a><?php endif; ?>
    <?php endfor; ?>
    <?php if ($dataPage < $totalPages): ?><a href="?action=data&db=<?php echo urlencode($currentDb); ?>&table=<?php echo urlencode($tableName); ?>&p=<?php echo $dataPage + 1; ?>&sort=<?php echo urlencode($sortCol); ?>&dir=<?php echo urlencode($sortDir); ?>&filter=<?php echo urlencode($filter); ?>">Succ &rarr;</a><?php endif; ?>
</div>
<?php endif; ?>
<!-- Insert Form -->
<div id="insert-form" class="panel" style="display:none;margin-top:16px"><div class="panel-header"><h2>Inserisci Nuova Riga</h2></div><div class="panel-body">
    <form method="post" action="?action=insert_row"><input type="hidden" name="table_name" value="<?php echo h($tableName); ?>">
    <?php foreach ($tableStructure as $col): ?>
    <div class="form-row" style="display:flex;gap:12px;align-items:flex-end">
        <div style="flex:0 0 200px">
            <label><?php echo h($col['Field']); ?> <span style="color:var(--text-m);font-size:10px"><?php echo h($col['Type']); ?></span></label>
            <input type="hidden" name="field[]" value="<?php echo h($col['Field']); ?>">
            <?php $ct = strtolower($col['Type']); ?>
            <?php if (strpos($ct, 'text') !== false || strpos($ct, 'blob') !== false): ?>
            <textarea name="value[]" rows="2" style="width:100%"></textarea>
            <?php elseif (strpos($ct, 'enum') !== false && preg_match('/enum$$(.+)$$/i', $col['Type'], $em)): ?>
            <select name="value[]" style="width:100%"><option value="">-- select --</option>
            <?php $vals = str_getcsv(str_replace(array("'", '"'), '', $em[1])); foreach ($vals as $ev): ?>
            <option><?php echo h(trim($ev)); ?></option>
            <?php endforeach; ?></select>
            <?php else: ?>
            <input type="<?php echo strpos($ct, 'date') !== false ? 'date' : 'text'; ?>" name="value[]" style="width:100%" placeholder="<?php echo isset($col['Default']) ? h($col['Default']) : ''; ?>">
            <?php endif; ?>
        </div>
        <div style="flex:0 0 80px"><label><input type="checkbox" name="is_null[]" value="<?php echo h($col['Field']); ?>"> NULL</label></div>
    </div>
    <?php endforeach; ?>
    <button type="submit" class="btn-accent" style="margin-top:8px">Inserisci Riga</button>
    <button type="button" class="btn" onclick="document.getElementById('insert-form').style.display='none'">Annulla</button>
    </form>
</div></div>
<!-- Edit Modal -->
<div id="edit-modal" class="confirm-overlay"><div class="confirm-box" style="max-width:600px;max-height:80vh;overflow-y:auto"><h3>Modifica Riga</h3>
    <form method="post" action="?action=update_row"><input type="hidden" name="table_name" value="<?php echo h($tableName); ?>">
    <div id="edit-fields"></div><div id="edit-where" style="display:none"></div>
    <div style="display:flex;gap:8px;margin-top:16px"><button type="submit" class="btn-accent">Salva</button><button type="button" class="btn" onclick="document.getElementById('edit-modal').classList.remove('show')">Annulla</button></div>
    </form>
</div></div>
<script>
function editRow(btn){var row=JSON.parse(btn.getAttribute('data-row'));var f=document.getElementById('edit-fields'),w=document.getElementById('edit-where');f.innerHTML='';w.innerHTML='';for(var c in row){var v=row[c],n=v===null,d=document.createElement('div');d.className='form-row';d.innerHTML='<label>'+c+'</label><input type="hidden" name="field[]" value="'+c+'"><div style="display:flex;gap:8px;align-items:center"><input type="text" name="value[]" value="'+(n?'':String(v).replace(/"/g,'&quot;'))+'" style="flex:1"><label style="white-space:nowrap"><input type="checkbox" name="is_null[]" value="'+c+'"'+(n?' checked':'')+'> NULL</label></div>';f.appendChild(d);w.innerHTML+='<input type="hidden" name="where_field[]" value="'+c+'"><input type="hidden" name="where_value[]" value="'+(n?'':String(v).replace(/"/g,'&quot;'))+'">';}document.getElementById('edit-modal').classList.add('show');}
</script>

<?php elseif ($view === 'query'): ?>
<h2 style="font-size:18px;margin-bottom:16px">Esegui SQL <?php echo $currentDb !== '' ? '&mdash; <span style="color:var(--accent)">' . h($currentDb) . '</span>' : ''; ?></h2>
<div class="panel"><div class="panel-body">
    <form method="post" action="?action=exec_sql" id="sqlForm"><input type="hidden" name="sql_db" value="<?php echo h($currentDb); ?>">
        <div class="form-row"><textarea name="sql_text" id="sqlEditor" rows="8" style="font-family:'JetBrains Mono',monospace;font-size:13px;line-height:1.6" placeholder="Scrivi SQL qui..."><?php echo h(isset($_POST['sql_text']) ? $_POST['sql_text'] : ''); ?></textarea></div>
        <div style="display:flex;gap:12px;align-items:center"><button type="submit" class="btn-accent">Esegui (Ctrl+Invio)</button><span style="font-size:11px;color:var(--text-m)">Pi&ugrave; statement separati da ;</span></div>
    </form>
</div></div>
<script>document.getElementById('sqlEditor').addEventListener('keydown',function(e){if(e.ctrlKey&&e.key==='Enter'){e.preventDefault();document.getElementById('sqlForm').submit();}});</script>
<?php if ($sqlResult !== null): ?>
<div class="panel"><div class="panel-header"><h2>Risultati</h2></div><div class="panel-body" style="padding:0;overflow-x:auto">
<?php foreach ($sqlResult as $ri => $res): ?>
<?php if ($res['type'] === 'select'): ?>
<div style="padding:12px 16px;border-bottom:1px solid var(--border)"><span style="font-size:12px;color:var(--text-m)">Query #<?php echo $ri + 1; ?> — <?php echo $res['count']; ?> righe</span></div>
<table class="data"><thead><tr><?php foreach ($res['columns'] as $c): ?><th><?php echo h($c); ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($res['rows'] as $row): ?><tr><?php foreach ($row as $v): ?><td class="<?php echo $v === null ? 'null-val' : ''; ?>"><?php echo $v === null ? 'NULL' : h(mb_strimwidth((string)$v, 0, 120, '...')); ?></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody></table>
<?php elseif ($res['type'] === 'affected'): ?>
<div style="padding:12px 16px;border-bottom:1px solid var(--border);color:var(--green)">Query #<?php echo $ri + 1; ?> — <?php echo $res['count']; ?> righe modificate</div>
<?php else: ?>
<div style="padding:12px 16px;border-bottom:1px solid var(--border);color:var(--red)">Query #<?php echo $ri + 1; ?> — Errore: <?php echo h($res['msg']); ?></div>
<?php endif; endforeach; ?>
</div></div>
<?php endif; ?>

<?php elseif ($view === 'view_def'): ?>
<h2 style="font-size:18px;margin-bottom:16px">Vista: <span style="color:var(--accent)"><?php echo h($viewName); ?></span></h2>
<div class="panel"><div class="panel-header"><h2>Definizione</h2><form method="post" action="?action=drop_view" onsubmit="return confirm('Eliminare?')"><input type="hidden" name="view_name" value="<?php echo h($viewName); ?>"><input type="hidden" name="confirm" value="1"><button class="btn btn-danger btn-sm">Elimina</button></form></div>
<div class="panel-body"><pre class="sql-block"><?php echo h($createView); ?></pre></div></div>

<?php elseif ($view === 'routine_def'): ?>
<h2 style="font-size:18px;margin-bottom:16px"><?php echo h($routineType); ?>: <span style="color:var(--accent)"><?php echo h($routineName); ?></span></h2>
<div class="panel"><div class="panel-header"><h2>Definizione</h2><form method="post" action="?action=drop_routine" onsubmit="return confirm('Eliminare?')"><input type="hidden" name="routine_name" value="<?php echo h($routineName); ?>"><input type="hidden" name="routine_type" value="<?php echo h($routineType); ?>"><input type="hidden" name="confirm" value="1"><button class="btn btn-danger btn-sm">Elimina</button></form></div>
<div class="panel-body"><pre class="sql-block"><?php echo h($routineType === 'PROCEDURE' ? $createProc : $createFunc); ?></pre></div></div>

<?php elseif ($view === 'create_table_form'): ?>
<h2 style="font-size:18px;margin-bottom:16px">Crea Nuova Tabella in <span style="color:var(--accent)"><?php echo h($currentDb); ?></span></h2>
<div class="panel"><div class="panel-body">
    <form method="post" action="?action=create_table">
        <div class="form-grid" style="margin-bottom:16px">
            <div class="form-row"><label>Nome Tabella</label><input type="text" name="table_name" required placeholder="nome_tabella"></div>
            <div class="form-grid">
                <div><label>Engine</label><select name="engine"><option>InnoDB</option><option>MyISAM</option><option>MEMORY</option><option>ARCHIVE</option></select></div>
                <div><label>Charset</label><select name="table_charset"><?php foreach ($charsets as $cs): ?><option value="<?php echo h($cs['Charset']); ?>" <?php echo $cs['Charset'] === 'utf8mb4' ? 'selected' : ''; ?>><?php echo h($cs['Charset']); ?></option><?php endforeach; ?></select></div>
            </div>
        </div>
        <h3 style="font-size:14px;margin-bottom:12px;color:var(--text-b)">Definizione Colonne</h3>
        <div id="ct-columns">
            <div class="col-card">
                <div class="col-card-header"><span class="col-num">Colonna #1</span><button type="button" class="btn btn-sm btn-danger" onclick="removeCol(this)" style="display:none">Rimuovi</button></div>
                <div class="col-card-body">
                    <div class="form-grid-3">
                        <div class="form-row"><label>Nome colonna</label><input type="text" name="col_name[]" required placeholder="es: id"></div>
                        <div class="form-row"><label>Tipo di dato</label><select name="col_type[]"><option value="INT">INT - Intero</option><option value="BIGINT">BIGINT - Intero grande</option><option value="SMALLINT">SMALLINT</option><option value="TINYINT">TINYINT</option><option value="VARCHAR">VARCHAR - Testo variabile</option><option value="CHAR">CHAR - Testo fisso</option><option value="TEXT">TEXT - Testo lungo</option><option value="LONGTEXT">LONGTEXT</option><option value="DECIMAL">DECIMAL - Decimale</option><option value="FLOAT">FLOAT</option><option value="DOUBLE">DOUBLE</option><option value="DATE">DATE - Data</option><option value="DATETIME">DATETIME</option><option value="TIMESTAMP">TIMESTAMP</option><option value="TIME">TIME</option><option value="BOOLEAN">BOOLEAN</option><option value="BLOB">BLOB</option><option value="LONGBLOB">LONGBLOB</option><option value="ENUM">ENUM</option><option value="JSON">JSON</option></select></div>
                        <div class="form-row"><label>Lunghezza / Valori</label><input type="text" name="col_length[]" placeholder="es: 255"></div>
                    </div>
                    <div class="form-row">
                        <label>Valore di default</label>
                        <input type="text" name="col_default[]" placeholder="(nessuno — lascia vuoto)">
                        <div class="default-presets">
                            <span style="font-size:10px;color:var(--text-m);margin-right:4px">Rapido:</span>
                            <button type="button" class="preset-btn" onclick="setDef(this,'NULL')">NULL</button>
                            <button type="button" class="preset-btn" onclick="setDef(this,'CURRENT_TIMESTAMP')">CURRENT_TIMESTAMP</button>
                            <button type="button" class="preset-btn" onclick="setDef(this,'UUID()')">UUID()</button>
                            <button type="button" class="preset-btn" onclick="setDef(this,'0')">0</button>
                            <button type="button" class="preset-btn" onclick="setDef(this,'')">vuoto</button>
                        </div>
                    </div>
                    <div class="col-options">
                        <label class="col-opt"><input type="checkbox" name="col_nullable[]" value="1"> <strong>NOT NULL</strong> <span style="color:var(--text-m);font-size:10px">&mdash; Non permette valori vuoti</span></label>
                        <label class="col-opt"><input type="checkbox" name="col_auto[]" value="1"> <strong>AUTO_INCREMENT</strong> <span style="color:var(--text-m);font-size:10px">&mdash; Valore automatico crescente</span></label>
                        <label class="col-opt"><input type="checkbox" name="col_pk[]" value="1"> <strong>PRIMARY KEY</strong> <span style="color:var(--text-m);font-size:10px">&mdash; Chiave primaria</span></label>
                        <label class="col-opt"><input type="checkbox" name="col_unsigned[]" value="1"> <strong>UNSIGNED</strong> <span style="color:var(--text-m);font-size:10px">&mdash; Solo valori positivi</span></label>
                    </div>
                </div>
            </div>
        </div>
        <button type="button" class="btn btn-sm" onclick="addCol()" style="margin:12px 0">+ Aggiungi Colonna</button>
        <div><button type="submit" class="btn-accent">Crea Tabella</button></div>
    </form>
</div></div>
<script>
var colCount=1;
function setDef(btn,val){btn.closest('.col-card').querySelector('input[name="col_default[]"]').value=val;}
function addCol(){
    colCount++;
    var tpl=document.querySelector('.col-card').cloneNode(true);
    tpl.querySelector('.col-num').textContent='Colonna #'+colCount;
    tpl.querySelectorAll('input').forEach(function(el){if(el.type==='checkbox')el.checked=false;else el.value='';});
    tpl.querySelectorAll('select').forEach(function(el){el.selectedIndex=0;});
    var rb=tpl.querySelector('.btn-danger');if(rb)rb.style.display='';
    document.getElementById('ct-columns').appendChild(tpl);
}
function removeCol(btn){
    var cards=document.querySelectorAll('.col-card');
    if(cards.length>1){btn.closest('.col-card').remove();
    document.querySelectorAll('.col-card').forEach(function(c,i){c.querySelector('.col-num').textContent='Colonna #'+(i+1);});
    colCount=document.querySelectorAll('.col-card').length;}
}
</script>

<?php elseif ($view === 'backup'): ?>
<h2 style="font-size:18px;margin-bottom:16px">Backup Database</h2>
<div class="panel"><div class="panel-body">
    <form method="post" action="?action=do_backup">
        <div class="form-row"><label>Database</label><select name="backup_db" required><?php foreach ($databases as $db): ?><option value="<?php echo h($db); ?>" <?php echo $db === $currentDb ? 'selected' : ''; ?>><?php echo h($db); ?></option><?php endforeach; ?></select></div>
        <div class="form-row"><label style="display:flex;align-items:center;gap:8px;cursor:pointer"><input type="checkbox" name="include_routines" value="1" checked style="width:auto"> Includi Routine (Procedure, Funzioni, Trigger)</label></div>
        <div class="form-row"><label style="display:flex;align-items:center;gap:8px;cursor:pointer"><input type="checkbox" name="structure_only" value="1" style="width:auto"> Solo struttura (senza dati)</label></div>
        <button type="submit" class="btn-accent">Genera &amp; Scarica Backup (.sql)</button>
    </form>
</div></div>
<div class="panel"><div class="panel-header"><h2>Backup Automatico via GET</h2></div><div class="panel-body">
    <p style="font-size:13px;color:var(--text-m);margin-bottom:12px">Configura <code>$API_BACKUPS</code> nel file PHP per backup automatici via HTTP GET.</p>
    <pre class="sql-block" style="font-size:11px"># Configurazione:
$API_BACKUPS = array('mio_backup' =&gt; array('host'=&gt;'localhost','port'=&gt;3306,'user'=&gt;'backup_user','pass'=&gt;'password','db'=&gt;'nome_db'));

# Esecuzione:
curl "URL_SCRIPT?api_backup=mio_backup" -o backup.sql
curl "...?api_backup=mio_backup&amp;routines=1&amp;compress=1" -o backup.sql.gz

# Cron ogni notte:
# 0 3 * * * curl -s "...?api_backup=mio_backup&amp;compress=1" -o /backups/db_$(date +\%Y\%m\%d).sql.gz</pre>
</div></div>

<?php elseif ($view === 'restore'): ?>
<h2 style="font-size:18px;margin-bottom:16px">Restore Database</h2>
<div class="panel"><div class="panel-body">
    <form method="post" action="?action=do_restore" enctype="multipart/form-data">
        <div class="form-row"><label>Database destinazione</label><select name="restore_db" required><?php foreach ($databases as $db): ?><option value="<?php echo h($db); ?>" <?php echo $db === $currentDb ? 'selected' : ''; ?>><?php echo h($db); ?></option><?php endforeach; ?></select></div>
        <div class="form-grid">
            <div class="form-row"><label>Carica file .sql o .sql.gz</label><input type="file" name="sql_file" accept=".sql,.sql.gz,.gz"></div>
            <div class="form-row"><label>Oppure incolla SQL</label><textarea name="sql_text" rows="6" placeholder="INSERT INTO ..."></textarea></div>
        </div>
        <button type="submit" class="btn-accent">Esegui Restore</button>
    </form>
</div></div>
<?php if ($restoreResult !== null): ?>
<div class="panel"><div class="panel-header"><h2>Risultato Restore</h2></div><div class="panel-body">
    <p style="color:var(--green)">Successi: <?php echo $restoreResult['ok']; ?></p>
    <p style="color:<?php echo $restoreResult['errors'] > 0 ? 'var(--red)' : 'var(--text-m)'; ?>">Errori: <?php echo $restoreResult['errors']; ?></p>
    <?php if (!empty($restoreResult['details'])): ?>
    <div style="margin-top:12px"><?php foreach ($restoreResult['details'] as $ed): ?><div style="font-size:12px;color:var(--red);margin-bottom:4px;font-family:'JetBrains Mono',monospace"><?php echo h($ed); ?></div><?php endforeach; ?></div>
    <?php endif; ?>
</div></div>
<?php endif; ?>

<?php endif; ?>
</div><!-- /main -->
<div class="statusbar">
    <?php if ($currentDb !== ''): ?><span>DB: <?php echo h($currentDb); ?></span><?php endif; ?>
    <?php if ($tableName !== ''): ?><span>Tabella: <?php echo h($tableName); ?></span><?php endif; ?>
    <span style="flex:1"></span><span><?php echo h(isset($serverInfo['version']) ? $serverInfo['version'] : ''); ?></span>
</div>
</div><!-- /app -->
<script>
function toggleDbTree(arrow){
    arrow.classList.toggle('open');
    var children=arrow.closest('.db-node').querySelector('.db-children');
    children.style.display=arrow.classList.contains('open')?'block':'none';
}
</script>
<?php endif; ?>
</body>
</html>
