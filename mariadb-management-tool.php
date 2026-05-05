<?php
/**
 * MiniDBA v1.0 — Complete MariaDB Management Tool
 * Single-file PHP application for MariaDB administration.
 *
 * Features: DB/Table/View/Procedure/Function CRUD, Backup/Restore
 * with BLOB & date support, automated backup via secure GET API.
 */

// ═══════════════════════════════════════════════════════════════════════════════
// CONFIGURATION
// ═══════════════════════════════════════════════════════════════════════════════
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('max_execution_time', 600);
ini_set('memory_limit', '512M');
session_start();

define('ROWS_PER_PAGE', 50);
define('INSERT_BATCH', 100);

// ── Automated Backup API ─────────────────────────────────────────────────────
// Set BACKUP_API_KEY to a strong random string to enable GET-based backups.
// Usage examples:
//   ?auto_backup=1&key=YOUR_KEY&db=mydb
//   ?auto_backup=1&key=YOUR_KEY&db=mydb&compress=1
//   ?auto_backup=1&key=YOUR_KEY&db=mydb&save=1
//   ?auto_backup=1&key=YOUR_KEY&db=mydb&save=1&compress=1
define('BACKUP_API_KEY',      '');        // leave empty to disable
define('BACKUP_DB_HOST',      '127.0.0.1');
define('BACKUP_DB_PORT',      3306);
define('BACKUP_DB_USER',      '');        // MariaDB user for automated backups
define('BACKUP_DB_PASS',      '');        // password
define('BACKUP_ALLOWED_IPS',  []);        // empty = all; e.g. ['10.0.0.5']
define('BACKUP_SAVE_DIR',     '');        // server dir; empty = force download

// ═══════════════════════════════════════════════════════════════════════════════
// AUTO-BACKUP API HANDLER  (runs before any HTML)
// ═══════════════════════════════════════════════════════════════════════════════
if (isset($_GET['auto_backup']) && $_GET['auto_backup'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    if (BACKUP_API_KEY === '' || BACKUP_DB_USER === '') {
        http_response_code(403);
        echo json_encode(['error' => 'Automated backup not configured']); exit;
    }
    $key = $_GET['key'] ?? '';
    if (!hash_equals(BACKUP_API_KEY, $key)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid API key']); exit;
    }
    if (!empty(BACKUP_ALLOWED_IPS)) {
        $ip = $_SERVER['REMOTE_ADDR'];
        $ok = false;
        foreach (BACKUP_ALLOWED_IPS as $a) {
            if (strpos($a, '/') !== false) {
                list($sub,$mask) = explode('/', $a);
                if ((ip2long($ip) & ~((1<<(32-$mask))-1)) === ip2long($sub)) { $ok = true; break; }
            } elseif ($ip === $a) { $ok = true; break; }
        }
        if (!$ok) { http_response_code(403); echo json_encode(['error'=>'IP not allowed']); exit; }
    }
    $dbName = $_GET['db'] ?? '';
    if (!$dbName) { http_response_code(400); echo json_encode(['error'=>'Missing db param']); exit; }
    try {
        $p = dbConnect(BACKUP_DB_HOST, BACKUP_DB_PORT, BACKUP_DB_USER, BACKUP_DB_PASS, $dbName);
        $sql = generateBackup($p, $dbName);
        $fn  = preg_replace('/[^a-zA-Z0-9_]/','_', $dbName) . '_' . date('Y-m_d_His') . '.sql';
        if (!empty($_GET['compress'])) { $sql = gzencode($sql, 6); $fn .= '.gz'; }
        if (!empty($_GET['save']) && BACKUP_SAVE_DIR !== '') {
            $path = rtrim(BACKUP_SAVE_DIR, '/') . '/' . $fn;
            file_put_contents($path, $sql);
            echo json_encode(['ok'=>true,'file'=>$path,'size'=>strlen($sql)]);
        } else {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="'.$fn.'"');
            header('Content-Length: '.strlen($sql));
            echo $sql;
        }
    } catch (Exception $ex) {
        http_response_code(500); echo json_encode(['error'=>$ex->getMessage()]);
    }
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// HELPER FUNCTIONS
// ═══════════════════════════════════════════════════════════════════════════════
function dbConnect($h,$p,$u,$pw,$db=null){
    $dsn="mysql:host=$h;port=$p;charset=utf8mb4".($db?";dbname=$db":"");
    return new PDO($dsn,$u,$pw,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
}
function qi($i){ return '`'.str_replace('`','``',$i).'`'; }           // quote identifier
function eh($s){ return htmlspecialchars($s??'',ENT_QUOTES,'UTF-8'); } // escape html
function csrf(){ if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function chk(){ return hash_equals($_SESSION['csrf']??'', $_POST['_csrf']??''); }
function fmtBytes($b){$u=['B','KB','MB','GB','TB'];$i=0;while($b>=1024&&$i<4){$b/=1024;$i++;}return round($b,2).' '.$u[$i];}
function redir($u){header("Location:$u");exit;}
function escSQL($v){$s=["\\","\0","\n","\r","'",'"',"\x1a"];$r=["\\\\","\\0","\\n","\\r","\\'",'\\"',"\\Z"];return str_replace($s,$r,$v);}

function colCategory($t){
    $t=strtolower($t);
    if(preg_match('/(blob|binary|varbinary)/',$t))return'bin';
    if(preg_match('/^bit/',$t))return'bit';
    if($t==='date')return'date';
    if(preg_match('/^(datetime|timestamp)$/',$t))return'dt';
    if($t==='time')return'time';
    if($t==='year')return'year';
    if(preg_match('/^(int|integer|smallint|mediumint|bigint|tinyint|float|double|decimal|numeric|real)/',$t))return'num';
    return'str';
}

function sqlVal($v,$type){
    if($v===null)return'NULL';
    $c=colCategory($type);
    switch($c){
        case'bin':  return'0x'.bin2hex($v);
        case'bit':  return"b'".decbin(ord($v))."'";
        case'date': return"'".date('Y-m-d',strtotime($v))."'";
        case'dt':   return"'".date('Y-m-d H:i:s',strtotime($v))."'";
        case'time': return"'".$v."'";
        case'year': return intval($v);
        case'num':  return is_numeric($v)?$v:"'".escSQL($v)."'";
        default:    return"'".escSQL($v)."'";
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// BACKUP ENGINE
// ═══════════════════════════════════════════════════════════════════════════════
function generateBackup($pdo, $dbName, $opts=[]){
    $incData  = $opts['data']  ?? true;
    $incViews = $opts['views'] ?? true;
    $incProcs = $opts['procs'] ?? true;
    $incFuncs = $opts['funcs'] ?? true;
    $selTbl   = $opts['tables']?? null;
    $o=[];
    $o[]="-- MiniDBA Backup";
    $o[]="-- Database: $dbName";
    $o[]="-- Date: ".date('Y-m-d H:i:s');
    $o[]="";
    $o[]="SET NAMES utf8mb4;";
    $o[]="SET CHARACTER SET utf8mb4;";
    $o[]="SET FOREIGN_KEY_CHECKS=0;";
    $o[]="SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';";
    $o[]="SET AUTOCOMMIT=0;";
    $o[]="START TRANSACTION;";
    $o[]="";
    // tables
    $allT=$pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
    $allV=$pdo->query("SHOW FULL TABLES WHERE Table_type='VIEW'")->fetchAll(PDO::FETCH_COLUMN);
    $tables=$selTbl?array_intersect($allT,$selTbl):$allT;
    foreach($tables as $t){
        $cr=$pdo->query("SHOW CREATE TABLE ".qi($t))->fetch(PDO::FETCH_NUM);
        $o[]="-- Table: $t";
        $o[]="DROP TABLE IF EXISTS ".qi($t).";";
        $o[]=$cr[1].";";
        $o[]="";
        if($incData){
            $cols=$pdo->query("SHOW COLUMNS FROM ".qi($t))->fetchAll();
            $cNames=array_map(function($c){return qi($c['Field']);},$cols);
            $cTypes=[];foreach($cols as $c)$cTypes[$c['Field']]=$c['Type'];
            $cnt=$pdo->query("SELECT COUNT(*) FROM ".qi($t))->fetchColumn();
            if($cnt>0){
                $cl=implode(', ',$cNames);
                $batch=[];$tr=0;
                $stmt=$pdo->query("SELECT * FROM ".qi($t));
                while($row=$stmt->fetch(PDO::FETCH_NUM)){
                    $vals=[];foreach($row as $i=>$v)$vals[]=sqlVal($v,$cTypes[$cols[$i]['Field']]);
                    $batch[]='('.implode(', ',$vals).')'; $tr++;
                    if(count($batch)>=INSERT_BATCH){ $o[]="INSERT INTO ".qi($t)." ($cl) VALUES"; $o[]=implode(",\n",$batch).";"; $o[]=""; $batch=[]; }
                }
                if($batch){ $o[]="INSERT INTO ".qi($t)." ($cl) VALUES"; $o[]=implode(",\n",$batch).";"; }
                $o[]="-- Rows: $tr"; $o[]="";
            }
        }
    }
    // views
    if($incViews && $allV){
        $o[]="-- ═══ VIEWS ═══"; $o[]="";
        foreach($allV as $v){
            $cr=$pdo->query("SHOW CREATE VIEW ".qi($v))->fetch(PDO::FETCH_NUM);
            $o[]="DROP VIEW IF EXISTS ".qi($v).";"; $o[]=$cr[1].";"; $o[]="";
        }
    }
    // procedures
    if($incProcs){
        $rs=$pdo->query("SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=".$pdo->quote($dbName)." AND ROUTINE_TYPE='PROCEDURE'")->fetchAll(PDO::FETCH_COLUMN);
        if($rs){ $o[]="-- ═══ PROCEDURES ═══"; $o[]="";
            foreach($rs as $r){ $cr=$pdo->query("SHOW CREATE PROCEDURE ".qi($r))->fetch(PDO::FETCH_NUM);
                $o[]="DROP PROCEDURE IF EXISTS ".qi($r).";"; $o[]="DELIMITER //"; $o[]=$cr[2]." //"; $o[]="DELIMITER ;"; $o[]=""; }
        }
    }
    // functions
    if($incFuncs){
        $rs=$pdo->query("SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=".$pdo->quote($dbName)." AND ROUTINE_TYPE='FUNCTION'")->fetchAll(PDO::FETCH_COLUMN);
        if($rs){ $o[]="-- ═══ FUNCTIONS ═══"; $o[]="";
            foreach($rs as $r){ $cr=$pdo->query("SHOW CREATE FUNCTION ".qi($r))->fetch(PDO::FETCH_NUM);
                $o[]="DROP FUNCTION IF EXISTS ".qi($r).";"; $o[]="DELIMITER //"; $o[]=$cr[2]." //"; $o[]="DELIMITER ;"; $o[]=""; }
        }
    }
    $o[]="COMMIT;";
    $o[]="SET FOREIGN_KEY_CHECKS=1;";
    $o[]="SET AUTOCOMMIT=1;";
    $o[]="-- Backup complete";
    return implode("\n",$o);
}

// ═══════════════════════════════════════════════════════════════════════════════
// RESTORE ENGINE  (SQL splitter handles DELIMITER, strings, comments)
// ═══════════════════════════════════════════════════════════════════════════════
function splitSQL($sql){
    $stmts=[];$cur='';$delim=';';$inStr=false;$sChar='';$esc=false;$inMC=false;
    $len=strlen($sql);$i=0;
    while($i<$len){
        $ch=$sql[$i];$nx=$i+1<$len?$sql[$i+1]:'';
        if(!$inStr&&!$inMC){
            if(stripos(ltrim(substr($sql,$i)),'DELIMITER ')===0){
                $end=strpos($sql,"\n",$i); if($end===false)$end=$len;
                $nd=trim(substr($sql,$i+10,$end-$i-10)); if($nd!=='')$delim=$nd;
                $i=$end+1; continue;
            }
        }
        if(!$inStr){
            if(!$inMC&&$ch==='-'&&$nx==='-'){$end=strpos($sql,"\n",$i);if($end===false)$end=$len;$cur.=substr($sql,$i,$end-$i);$i=$end;continue;}
            if(!$inMC&&$ch==='/'&&$nx==='*'){$inMC=true;$cur.='/*';$i+=2;continue;}
            if($inMC){$cur.=$ch;if($ch==='*'&&$nx==='/'){$cur.='/';$inMC=false;$i+=2;continue;}$i++;continue;}
        }
        if(!$inMC){
            if($esc){$cur.=$ch;$esc=false;$i++;continue;}
            if($ch==='\\'){$cur.=$ch;$esc=true;$i++;continue;}
            if($inStr){$cur.=$ch;if($ch===$sChar)$inStr=false;$i++;continue;}
            if($ch==="'"||$ch==='"'){$inStr=true;$sChar=$ch;$cur.=$ch;$i++;continue;}
        }
        if(!$inStr&&!$inMC){ $dl=strlen($delim); if(substr($sql,$i,$dl)===$delim){ $cur=trim($cur); if($cur!=='')$stmts[]=$cur; $cur=''; $i+=$dl; continue; } }
        $cur.=$ch;$i++;
    }
    $cur=trim($cur); if($cur!=='')$stmts[]=$cur;
    return $stmts;
}
function restoreSQL($pdo,$sql){
    $stmts=splitSQL($sql);$ok=0;$errs=[];
    foreach($stmts as $i=>$s){ $s=trim($s); if($s==='')continue;
        try{$pdo->exec($s);$ok++;}catch(PDOException $e){$errs[]="#".($i+1).": ".$e->getMessage();}
    }
    return['executed'=>$ok,'errors'=>$errs];
}

// ═══════════════════════════════════════════════════════════════════════════════
// AUTHENTICATION
// ═══════════════════════════════════════════════════════════════════════════════
$me=$_SESSION['db_user']??null; $mh=$_SESSION['db_host']??null;
$mp=$_SESSION['db_port']??null; $mw=$_SESSION['db_pass']??null;
$curDb=$_GET['db']??$_SESSION['db_name']??null; $_SESSION['db_name']=$curDb;
$pdo=null; $loginErr='';

if(isset($_POST['act'])&&$_POST['act']==='login'){
    $h=$_POST['host']??'127.0.0.1'; $p=intval($_POST['port']??3306);
    $u=$_POST['user']??''; $pw=$_POST['pass']??'';
    try{$pdo=dbConnect($h,$p,$u,$pw);
        $_SESSION['db_host']=$h;$_SESSION['db_port']=$p;$_SESSION['db_user']=$u;$_SESSION['db_pass']=$pw;$_SESSION['db_login']=time();
        $me=$u;$mh=$h;$mp=$p;$mw=$pw;
    }catch(PDOException $e){$loginErr='Login failed: '.$e->getMessage();}
}
if(isset($_GET['act'])&&$_GET['act']==='logout'){session_destroy();redir('?');}

if(!$me){showLogin($loginErr);exit;}

if(!$pdo){try{$pdo=dbConnect($mh,$mp,$me,$mw,$curDb);}catch(PDOException $e){session_destroy();showLogin('Session expired.');exit;}}

// ═══════════════════════════════════════════════════════════════════════════════
// ACTION PROCESSING (POST)
// ═══════════════════════════════════════════════════════════════════════════════
$msg=''; $err=''; $act=$_GET['act']??$_POST['act']??'databases'; $tbl=$_GET['table']??null;

if($_SERVER['REQUEST_METHOD']==='POST'&&chk()){
    switch($_POST['act']){
        case'create_db':
            $n=trim($_POST['name']??''); $ch=$_POST['charset']??'utf8mb4'; $co=$_POST['collation']??'';
            if($n){try{$pdo->exec("CREATE DATABASE ".qi($n)." CHARACTER SET ".qi($ch).($co?" COLLATE ".qi($co):""));
                $msg="Database '$n' created.";}catch(PDOException $e){$err=$e->getMessage();}}
            break;
        case'drop_db':
            $n=$_POST['name']??''; if($n){try{$pdo->exec("DROP DATABASE ".qi($n));$msg="Database '$n' dropped.";
                if($curDb===$n){$curDb=null;$_SESSION['db_name']=null;}}catch(PDOException $e){$err=$e->getMessage();}}
            break;
        case'create_table':
            $tn=trim($_POST['tname']??''); $eng=$_POST['engine']??'InnoDB'; $ch=$_POST['charset']??'utf8mb4';
            $cols=$_POST['cols']??[];
            if($tn&&$cols){
                $defs=[];$pks=[];
                foreach($cols as $c){
                    $f=trim($c['field']??''); if(!$f)continue;
                    $def=qi($f).' '.($c['type']??'VARCHAR(255)');
                    if(!empty($c['nn']))$def.=' NOT NULL';
                    if($c['default']!==''&&$c['default']!==null)$def.=' DEFAULT '.($c['default']==='NULL'?'NULL':"'".escSQL($c['default'])."'");
                    if(!empty($c['ai']))$def.=' AUTO_INCREMENT';
                    if(!empty($c['comment']))$def.=" COMMENT '".escSQL($c['comment'])."'";
                    $defs[]=$def;
                    if(!empty($c['pk']))$pks[]=qi($f);
                }
                if($pks)$defs[]='PRIMARY KEY ('.implode(',',$pks).')';
                $sql="CREATE TABLE ".qi($tn)." (\n  ".implode(",\n  ",$defs)."\n) ENGINE=$eng DEFAULT CHARSET=$ch";
                try{$pdo->exec($sql);$msg="Table '$tn' created.";$act='structure';$tbl=$tn;}catch(PDOException $e){$err=$e->getMessage();}
            }
            break;
        case'drop_table':
            $tn=$_POST['table']??''; if($tn){try{$pdo->exec("DROP TABLE ".qi($tn));$msg="Table '$tn' dropped.";$tbl=null;$act='tables';
            }catch(PDOException $e){$err=$e->getMessage();}} break;
        case'truncate':
            $tn=$_POST['table']??''; if($tn){try{$pdo->exec("TRUNCATE TABLE ".qi($tn));$msg="Table '$tn' truncated.";
            }catch(PDOException $e){$err=$e->getMessage();}} break;
        case'insert_row':
            $tn=$_POST['table']??''; $vals=$_POST['vals']??[];
            if($tn&&$vals){
                $fs=[];$vs=[];$ps=[];
                foreach($vals as $col=>$info){
                    $fs[]=qi($col);
                    if(($info['null']??false)&&($info['val']??'')===''){$vs[]='NULL';}
                    else{$vs[]='?';$ps[]=$info['val'];}
                }
                $sql="INSERT INTO ".qi($tn)." (".implode(',',$fs).") VALUES (".implode(',',$vs).")";
                try{$st=$pdo->prepare($sql);$st->execute($ps);$msg="Row inserted (ID: ".$pdo->lastInsertId().").";
                }catch(PDOException $e){$err=$e->getMessage();}
            }
            break;
        case'update_row':
            $tn=$_POST['table']??''; $vals=$_POST['vals']??[]; $where=$_POST['where']??'';
            if($tn&&$where){
                $sets=[];$ps=[];
                foreach($vals as $col=>$info){
                    if(($info['null']??false)&&($info['val']??'')===''){$sets[]=qi($col)."=NULL";}
                    else{$sets[]=qi($col)."=?";$ps[]=$info['val'];}
                }
                $sql="UPDATE ".qi($tn)." SET ".implode(',',$sets)." WHERE $where LIMIT 1";
                try{$st=$pdo->prepare($sql);$st->execute($ps);$msg="Row updated.";}catch(PDOException $e){$err=$e->getMessage();}
            }
            break;
        case'delete_row':
            $tn=$_POST['table']??''; $where=$_POST['where']??'';
            if($tn&&$where){try{$pdo->exec("DELETE FROM ".qi($tn)." WHERE $where LIMIT 1");$msg="Row deleted.";
            }catch(PDOException $e){$err=$e->getMessage();}} break;
        case'add_column':
            $tn=$_POST['table']??''; $f=trim($_POST['field']??''); $tp=$_POST['type']??'VARCHAR(255)';
            $nn=!empty($_POST['nn']); $df=$_POST['default']??''; $ai=!empty($_POST['ai']);
            if($tn&&$f){
                $sql="ALTER TABLE ".qi($tn)." ADD COLUMN ".qi($f)." $tp".($nn?" NOT NULL":"").($df!==''?" DEFAULT ".($df==='NULL'?"NULL":"'".escSQL($df)."'"):"").($ai?" AUTO_INCREMENT":"");
                try{$pdo->exec($sql);$msg="Column '$f' added.";}catch(PDOException $e){$err=$e->getMessage();}
            }
            break;
        case'drop_column':
            $tn=$_POST['table']??''; $col=$_POST['column']??'';
            if($tn&&$col){try{$pdo->exec("ALTER TABLE ".qi($tn)." DROP COLUMN ".qi($col));$msg="Column '$col' dropped.";
            }catch(PDOException $e){$err=$e->getMessage();}} break;
        case'add_index':
            $tn=$_POST['table']??''; $iname=trim($_POST['iname']??''); $cols=$_POST['icols']??[]; $unique=!empty($_POST['unique']);
            if($tn&&$iname&&$cols){
                try{$pdo->exec("ALTER TABLE ".qi($tn)." ADD ".($unique?"UNIQUE ":"")."INDEX ".qi($iname)." (".implode(',',array_map('qi',$cols)).")");
                $msg="Index '$iname' added.";}catch(PDOException $e){$err=$e->getMessage();}
            }
            break;
        case'drop_index':
            $tn=$_POST['table']??''; $iname=$_POST['index']??'';
            if($tn&&$iname){try{$pdo->exec("ALTER TABLE ".qi($tn)." DROP INDEX ".qi($iname));$msg="Index '$iname' dropped.";
            }catch(PDOException $e){$err=$e->getMessage();}} break;
        case'create_view':
            $vn=trim($_POST['name']??''); $def=trim($_POST['definition']??'');
            if($vn&&$def){try{$pdo->exec("CREATE OR REPLACE VIEW ".qi($vn)." AS $def");$msg="View '$vn' created.";
            }catch(PDOException $e){$err=$e->getMessage();}} break;
        case'drop_view':
            $vn=$_POST['name']??''; if($vn){try{$pdo->exec("DROP VIEW ".qi($vn));$msg="View '$vn' dropped.";
            }catch(PDOException $e){$err=$e->getMessage();}} break;
        case'create_proc':
            $pn=trim($_POST['name']??''); $body=trim($_POST['body']??'');
            if($pn&&$body){try{$pdo->exec("DROP PROCEDURE IF EXISTS ".qi($pn));$pdo->exec($body);$msg="Procedure '$pn' created.";
            }catch(PDOException $e){$err=$e->getMessage();}} break;
        case'drop_proc':
            $pn=$_POST['name']??''; if($pn){try{$pdo->exec("DROP PROCEDURE IF EXISTS ".qi($pn));$msg="Procedure '$pn' dropped.";
            }catch(PDOException $e){$err=$e->getMessage();}} break;
        case'create_func':
            $fn=trim($_POST['name']??''); $body=trim($_POST['body']??'');
            if($fn&&$body){try{$pdo->exec("DROP FUNCTION IF EXISTS ".qi($fn));$pdo->exec($body);$msg="Function '$fn' created.";
            }catch(PDOException $e){$err=$e->getMessage();}} break;
        case'drop_func':
            $fn=$_POST['name']??''; if($fn){try{$pdo->exec("DROP FUNCTION IF EXISTS ".qi($fn));$msg="Function '$fn' dropped.";
            }catch(PDOException $e){$err=$e->getMessage();}} break;
        case'run_sql':
            $sql=trim($_POST['sqltext']??'');
            if($sql){
                $t0=microtime(true);
                try{
                    if(preg_match('/^\s*(select|show|describe|explain|desc)\s/i',$sql)){
                        $st=$pdo->query($sql); $sqlResult=$st->fetchAll(); $sqlTime=round((microtime(true)-$t0)*1000,2);
                        $sqlCols=$st->columnCount()?array_map(function($i)use($st){return $st->getColumnMeta($i)['name'];},range(0,$st->columnCount()-1)):[];$sqlRows=count($sqlResult);$sqlMsg="$sqlRows row(s) in {$sqlTime}ms";
                    }else{$affected=$pdo->exec($sql);$sqlTime=round((microtime(true)-$t0)*1000,2);$sqlResult=null;$sqlMsg="$affected row(s) affected in {$sqlTime}ms";}
                }catch(PDOException $e){$sqlErr=$e->getMessage();}
            }
            break;
        case'restore':
            $sql='';
            if(!empty($_FILES['file']['tmp_name'])){$sql=file_get_contents($_FILES['file']['tmp_name']);}
            elseif(!empty($_POST['sqltext'])){$sql=$_POST['sqltext'];}
            if($sql){
                $compressed=false;
                if(substr($sql,0,2)==="\x1f\x8b"){$sql=gzdecode($sql);$compressed=true;}
                $res=restoreSQL($pdo,$sql);
                $msg="Restore: {$res['executed']} statement(s) executed".($res['errors']?"; ".count($res['errors'])." error(s)":"").($compressed?" (decompressed)":"");
                if($res['errors'])$err=implode('<br>',array_slice($res['errors'],0,10));
            }
            break;
        case'do_backup':
            $dbName=$_POST['db']??$curDb;
            if($dbName){
                $bOpts=['data'=>$_POST['inc_data']??1,'views'=>$_POST['inc_views']??1,'procs'=>$_POST['inc_procs']??1,'funcs'=>$_POST['inc_funcs']??1];
                $sql=generateBackup($pdo,$dbName,$bOpts);
                if(!empty($_POST['compress'])){$sql=gzencode($sql,6);$ext='.sql.gz';}else{$ext='.sql';}
                $fn=preg_replace('/[^a-zA-Z0-9_]/','_',$dbName).'_'.date('Y-m-d_His').$ext;
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="'.$fn.'"');
                header('Content-Length: '.strlen($sql)); echo $sql; exit;
            }
            break;
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// DATA QUERIES FOR VIEWS
// ═══════════════════════════════════════════════════════════════════════════════
$dbList=[]; $tblList=[]; $viewList=[]; $procList=[]; $funcList=[];
try{$dbList=$pdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);}catch(Exception $e){}

if($curDb){
    try{$pdo->exec("USE ".qi($curDb));}catch(Exception $e){}
    try{$tblList=$pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);}catch(Exception $e){}
    try{$viewList=$pdo->query("SHOW FULL TABLES WHERE Table_type='VIEW'")->fetchAll(PDO::FETCH_COLUMN);}catch(Exception $e){}
    try{$procList=$pdo->query("SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=".$pdo->quote($curDb)." AND ROUTINE_TYPE='PROCEDURE'")->fetchAll(PDO::FETCH_COLUMN);}catch(Exception $e){}
    try{$funcList=$pdo->query("SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=".$pdo->quote($curDb)." AND ROUTINE_TYPE='FUNCTION'")->fetchAll(PDO::FETCH_COLUMN);}catch(Exception $e){}
}

// browse helpers
$browseData=[];$browseCols=[];$browseTotal=0;$browsePages=0;
$page=max(1,intval($_GET['p']??1)); $orderBy=$_GET['order']??''; $where=$_GET['where']??'';
if($act==='browse'&&$tbl&&$curDb){
    try{
        $wClause=$where?" WHERE $where":'';
        $cnt=$pdo->query("SELECT COUNT(*) FROM ".qi($tbl).$wClause)->fetchColumn();
        $browseTotal=$cnt; $browsePages=ceil($cnt/ROWS_PER_PAGE);
        $oClause=$orderBy?" ORDER BY $orderBy":'';
        $off=($page-1)*ROWS_PER_PAGE;
        $st=$pdoCols=[];
if($act==='insert'&&$tbl&&$curDb){try{$insertCols=$pdo->query("SHOW COLUMNS FROM ".qi($tbl))->fetchAll();}catch(Exception $e){}}

// view/procedure/function definition
$viewDef='';$procDef='';$funcDef='';
if($act==='edit_view'&&($_GET['name']??'')){try{$cr=$pdo->query("SHOW CREATE VIEW ".qi($_GET['name']))->fetch(PDO::FETCH_NUM);$viewDef=$cr[1];}catch(Exception $e){}}
if($act==='edit_proc'&&($_GET['name']??'')){try{$cr=$pdo->query("SHOW CREATE PROCEDURE ".qi($_GET['name']))->fetch(PDO::FETCH_NUM);$procDef=$cr[2];}catch(Exception $e){}}
if($act==='edit_func'&&($_GET['name']??'')){try{$cr=$pdo->query("SHOW CREATE FUNCTION ".qi($_GET['name']))->fetch(PDO::FETCH_NUM);$funcDef=$cr[2];}catch(Exception $e){}}

// table info for browse header
$tblInfo=null;
if($tbl&&$curDb){try{$tblInfo=$pdo->query("SELECT TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=".$pdo->quote($curDb)." AND TABLE_NAME=".$pdo->quote($tbl))->fetch();}catch(Exception $e){}}

// available charsets
$charsets=[];
try{$charsets=$pdo->query("SHOW CHARACTER SET")->fetchAll();}catch(Exception $e){}

// ═══════════════════════════════════════════════════════════════════════════════
// RENDER HTML
// ═══════════════════════════════════════════════════════════════════════════════
function showLogin($err=''){?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>MiniDB insert form columns
$insert->query("SELECT * FROM ".qi($tbl).$wClause.$oClause." LIMIT ".ROWS_PER_PAGE." OFFSET $off");
        $browseCols=array_map(function($i)use($st){return $st->getColumnMeta($i)['name'];},range(0,$st->columnCount()-1));
        $browseData=$st->fetchAll(PDO::FETCH_NUM);
    }catch(PDOException $e){$err=$e->getMessage();}
}

// structure helpers
$structCols=[];$structIdx=[];$structCreate='';
if(in_array($act,['structure','add_column'])&&$tbl&&$curDb){
    try{$structCols=$pdo->query("SHOW FULL COLUMNS FROM ".qi($tbl))->fetchAll();}catch(Exception $e){}
    try{$structIdx=$pdo->query("SHOW INDEX FROM ".qi($tbl))->fetchAll();}catch(Exception $e){}
    try{$cr=$pdo->query("SHOW CREATE TABLE ".qi($tbl))->fetch(PDO::FETCH_NUM);$structCreate=$cr[1];}catch(Exception $e){}
}

// edit row data
$editRow=[];$editCols=[];
if($act==='edit'&&$tbl&&$curDb){
    try{
        $editCols=$pdo->query("SHOW COLUMNS FROM ".qi($tbl))->fetchAll();
        $pkCols=[];foreach($editCols as $c){if($c['Key']==='PRI')$pkCols[]=$c['Field'];}
        if(!$pkCols)$pkCols=array_column($editCols,'Field');
        $wh=[];$ps=[];
        foreach($pkCols as $pk){$v=$_GET['pk_'.urlencode($pk)]??'';$wh[]=qi($pk)."=?";$ps[]=$v;}
        $st=$pdo->prepare("SELECT * FROM ".qi($tbl)." WHERE ".implode(' AND ',$wh)." LIMIT 1");
        $st->execute($ps);$editRow=$st->fetch();
    }catch(PDOException $e){$err=$e->getMessage();}
}

//A — Login</:var(--mu);margin-bottom:4px;text-transform:uppercase;letter-spacing:.5px}
input[type=text],input[type=password],input[type=number]{width:100%;padding:10px 12px;background:var(--s2);border:1px solid var(--bd);border-radius:6px;color:var(--tx);font-family:var(--fm);font-size:13px;margin-bottom:16px;outline:none;transition:border .2s}
input:focus{border-color:var(--ac)}
.row{display:flex;gap:12px}.row>div{flex:1}
button{width:100%;padding:11px;background:var(--ac);color:#fff;border:none;border-radius:6px;font-family:var(--fn);font-size:14px;font-weight:600;cursor:pointer;transition:background .2s}
button:hover{background:var(--ac2)}
.er{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:var(--er);padding:10px 12px;border-radius:6px;font-size:13px;margin-bottom:16px}
</style></head><body>
<div class="login-box">
<h1>MiniDBA</h1><p class="sub">MariaDB Management Tool</p>
<?php if($err):?><div class="er"><?=eh($err)?></div><?php endif;?>
<form method="POST"><input type="hidden" name="act" value="login">
<div class="row"><div><label>Host</label><input type="text" name="host" value="127.0.0.1"></div>
<div><label>Port</label><input type="number" name="port" value="3306"></div></div>
<label>Username</label><input type="text" name="user" required autofocus>
<label>Password</label><input type="password" name="pass">
<button type="submit">Connect</button></form></div></body></html>
<?php }

// ── Main Interface ────────────────────────────────────────────────────────────
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=eh($curDb?"$curDb — ":"")?>MiniDBA</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#0c0e14;--s:#151822;--s2:#1c2030;--s3:#252a3a;--ac:#10b981;--ac2:#059669;--acbg:rgba(16,185,129,.1);--er:#ef4444;--erbg:rgba(239,68,68,.08);--warn:#f59e0b;--info:#3b82f6;--tx:#e2e8f0;--mu:#64748b;--bd:#1e2233;--fn:'DM Sans',system-ui,sans-serif;--fm:'DM Mono','Consolas',monospace}
html{font-size:14px}body{background:var(--bg);color:var(--tx);font-family:var(--fn);min-height:100vh}
a{color:var(--ac);text-decoration:none}a:hover{text-decoration:underline}
.app{display:flex;flex-direction:column;height:100vh}
header{background:var(--s);border-bottom:1px solid var(--bd);padding:0 20px;height:48px;display:flex;align-items:center;gap:16px;flex-shrink:0}
header .logo{font-weight:700;font-size:16px;color:var(--ac);letter-spacing:-.5px}
header .logo span{color:var(--mu);font-weight:400;font-size:12px;margin-left:6px}
header .sep{width:1px;height:24px;background:var(--bd)}
header select{background:var(--s2);border:1px solid var(--bd);color:var(--tx);padding:5px 8px;border-radius:4px;font-family:var(--fm);font-size:12px;cursor:pointer}
header .spacer{flex:1}
header .user{font-size:12px;color:var(--mu);font-family:var(--fm)}
header a.btn-sm{font-size:12px;padding:5px 12px;background:var(--s2);border:1px solid var(--bd);border-radius:4px;color:var(--tx);transition:all .2s}
header a.btn-sm:hover{background:var(--s3);text-decoration:none}
.layout{display:flex;flex:1;overflow:hidden}
aside{width:240px;background:var(--s);border-right:1px solid var(--bd);overflow-y:auto;flex-shrink:0;padding:12px 0}
aside .sec{padding:6px 16px;font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--mu);font-weight:600;margin-top:8px}
aside a{display:block;padding:5px 16px;font-size:13px;color:var(--tx);transition:background .15s}
aside a:hover{background:var(--s2);text-decoration:none}
aside a.active{background:var(--acbg);color:var(--ac);border-right:2px solid var(--ac)}
aside a .cnt{float:right;color:var(--mu);font-size:11px;font-family:var(--fm)}
main{flex:1;overflow:auto;padding:20px 24px}
.tabs{display:flex;gap:0;border-bottom:1px solid var(--bd);margin-bottom:20px}
.tabs a{padding:8px 16px;font-size:13px;color:var(--mu);border-bottom:2px solid transparent;transition:all .2s}
.tabs a:hover{color:var(--tx);text-decoration:none}
.tabs a.active{color:var(--ac);border-bottom-color:var(--ac)}
h2{font-size:18px;font-weight:700;margin-bottom:16px}
h3{font-size:15px;font-weight:600;margin:16px 0 8px}
.alert{padding:10px 14px;border-radius:6px;font-size:13px;margin-bottom:16px}
.alert-error{background:var(--erbg);border:1px solid rgba(239,68,68,.2);color:var(--er)}
.alert-success{background:var(--acbg);border:1px solid rgba(16,185,129,.2);color:var(--ac)}
table.dt{width:100%;border-collapse:collapse;font-size:13px}
table.dt th{background:var(--s2);text-align:left;padding:8px 10px;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--mu);border-bottom:1px solid var(--bd);position:sticky;top:0;z-index:1}
table.dt td{padding:7px 10px;border-bottom:1px solid var(--bd);max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
table.dt tr:hover td{background:var(--s2)}
table.dt td.nw{white-space:nowrap;font-family:var(--fm);font-size:12px}
table.dt td.null{color:var(--mu);font-style:italic}
table.dt td.blob{color:var(--warn);font-family:var(--fm);font-size:11px}
table.dt td a{color:var(--ac)}
.pagination{display:flex;align-items:center;gap:8px;margin-top:12px;font-size:12px;color:var(--mu)}
.pagination a,.pagination span{padding:4px 10px;border:1px solid var(--bd);border-radius:4px;font-family:var(--fm)}
.pagination a:hover{background:var(--s2);text-decoration:none}
.pagination .cur{background:var(--ac);color:#fff;border-color:var(--ac)}
form.inline{display:inline}
input[type=text],input[type=number],input[type=password],textarea,select{
    background:var(--s2);border:1px solid var(--bd);color:var(--tx);padding:7px 10px;border-radius:4px;font-family:var(--fm);font-size:13px;outline:none;transition:border .2s}
input:focus,textarea:focus,select:focus{border-color:var(--ac)}
textarea{resize:vertical;min-height:80px;font-family:var(--fm)}
label{display:block;font-size:12px;font-weight:500;color:var(--mu);margin-bottom:4px;text-transform:uppercase;letter-spacing:.4px}
.btn{display:inline-block;padding:7px 16px;border:none;border-radius:4px;font-family:var(--fn);font-size:13px;font-weight:600;cursor:pointer;transition:all .2s}
.btn-ac{background:var(--ac);color:#fff}.btn-ac:hover{background:var(--ac2)}
.btn-er{background:var(--er);color:#fff}.btn-er:hover{background:#dc2626}
.btn-ghost{background:transparent;border:1px solid var(--bd);color:var(--tx)}.btn-ghost:hover{background:var(--s2)}
.btn-warn{background:var(--warn);color:#000}
.form-row{margin-bottom:14px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.col-editor{border:1px solid var(--bd);border-radius:6px;overflow:hidden;margin-bottom:12px}
.col-editor .col-row{display:grid;grid-template-columns:1.5fr 1.2fr auto auto auto auto auto;gap:6px;padding:6px 10px;border-bottom:1px solid var(--bd);align-items:center;font-size:12px}
.col-editor .col-row:last-child{border-bottom:none}
.col-editor .hdr{background:var(--s2);font-weight:600;color:var(--mu);font-size:10px;text-transform:uppercase;letter-spacing:.5px}
.col-editor input,.col-editor select{padding:5px 6px;font-size:12px}
.col-editor input[type=checkbox]{width:14px;height:14px}
.filter-bar{display:flex;gap:8px;margin-bottom:12px;align-items:center}
.filter-bar input{flex:1}
.info-bar{font-size:12px;color:var(--mu);margin-bottom:12px;font-family:var(--fm)}
.code-block{background:var(--s2);border:1px solid var(--bd);border-radius:6px;padding:14px;font-family:var(--fm);font-size:12px;white-space:pre-wrap;word-break:break-all;max-height:400px;overflow:auto;margin:8px 0}
.actions-cell{display:flex;gap:4px}
.actions-cell .btn{padding:3px 8px;font-size:11px}
@media(max-width:900px){.layout{flex-direction:column}aside{width:100%;max-height:200px;border-right:none;border-bottom:1px solid var(--bd)}}
</style></head><body>
<div class="app">
<!-- HEADER -->
<header>
    <div class="logo">MiniDBA<span>v1.0</span></div>
    <div class="sep"></div>
    <form method="GET" style="display:flex;align-items:center;gap:8px">
        <select name="db" onchange="this.form.submit()" style="min-width:160px">
            <option value="">— Select Database —</option>
            <?php foreach($dbList as $d):?><option value="<?=eh($d)?>"<?=($curDb===$d?' selected':'')?>><?=eh($d)?></option><?php endforeach;?>
        </select>
        <?php if($tbl):?><input type="hidden" name="table" value="<?=eh($tbl)?>"><?php endif;?>
        <input type="hidden" name="act" value="<?=eh($act)?>">
    </form>
    <div class="spacer"></div>
    <span class="user"><?=eh($me)?>@<?=eh($mh)?>:<?=eh($mp)?></span>
    <a href="?act=logout" class="btn-sm">Logout</a>
</header>
<div class="layout">
<!-- SIDEBAR -->
<aside>
    <div class="sec">Navigation</div>
    <a href="?act=databases" class="<?=($act==='databases'?'active':'')?>">Databases</a>
    <?php if($curDb):?>
    <a href="?act=tables&db=<?=eh($curDb)?>" class="<?=($act==='tables'?'active':'')?>">Tables <span class="cnt"><?=count($tblList)?></span></a>
    <a href="?act=views&db=<?=eh($curDb)?>" class="<?=($act==='views'||$act==='edit_view'?'active':'')?>">Views <span class="cnt"><?=count($viewList)?></span></a>
    <a href="?act=procedures&db=<?=eh($curDb)?>" class="<?=($act==='procedures'||$act==='edit_proc'?'active':'')?>">Procedures <span class="cnt"><?=count($procList)?></span></a>
    <a href="?act=functions&db=<?=eh($curDb)?>" class="<?=($act==='functions'||$act==='edit_func'?'active':'')?>">Functions <span class="cnt"><?=count($funcList)?></span></a>
    <div class="sec">Tools</div>
    <a href="?act=sql&db=<?=eh($curDb)?>" class="<?=($act==='sql'?'active':'')?>">SQL Query</a>
    <a href="?act=backup&db=<?=eh($curDb)?>" class="<?=($act==='backup'?'active':'')?>">Backup</a>
    <a href="?act=restore&db=<?=eh($curDb)?>" class="<?=($act==='restore'?'active':'')?>">Restore</a>
    <?php endif;?>
    <?php if($tbl&&$curDb):?>
    <div class="sec">Table: <?=eh($tbl)?></div>
    <a href="?act=browse&db=<?=eh($curDb)?>&table=<?=eh($tbl)?>" class="<?=($act==='browse'?'active':'')?>">Browse</a>
    <a href="?act=structure&db=<?=eh($curDb)?>&table=<?=eh($tbl)?>" class="<?=($act==='structure'||$act==='add_column'?'active':'')?>">Structure</a>
    <a href="?act=insert&db=<?=eh($curDb)?>&table=<?=eh($tbl)?>" class="<?=($act==='insert'?'active':'')?>">Insert</a>
    <?php endif;?>
</aside>
<!-- MAIN -->
<main>
<?php if($msg):?><div class="alert alert-success"><?=eh($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-error"><?=$err?></div><?php endif;?>

<?php
// ═══════════════════════════════════════════════════════════════════════════
// VIEW: DATABASES
// ═══════════════════════════════════════════════════════════════════════════
if($act==='databases'):
    $dbSizes=[];
    try{$dbSizes=$pdo->query("SELECT TABLE_SCHEMA,SUM(DATA_LENGTH+INDEX_LENGTH) sz FROM information_schema.TABLES GROUP BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_KEY_PAIR);}catch(Exception $e){}
?>
<h2>Databases</h2>
<table class="dt"><thead><tr><th>Database</th><th>Size</th><th>Actions</th></tr></thead><tbody>
<?php foreach($dbList as $d):?>
<tr>
    <td><a href="?db=<?=eh($d)?>&act=tables"><?=eh($d)?></a></td>
    <td class="nw"><?=isset($dbSizes[$d])?fmtBytes($dbSizes[$d]):'—'?></td>
    <td><form method="POST" class="inline" onsubmit="return confirm('Drop database <?=eh($d)?>?')"><input type="hidden" name="act" value="drop_db"><input type="hidden" name="name" value="<?=eh($d)?>"><input type="hidden" name="_csrf" value="<?=csrf()?>"><button class="btn btn-er" style="padding:3px 8px;font-size:11px">Drop</button></form></td>
</tr>
<?php endforeach;?>
</tbody></table>
<h3>Create Database</h3>
<form method="POST" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="act" value="create_db"><input type="hidden" name="_csrf" value="<?=csrf()?>">
    <div><label>Name</label><input type="text" name="name" required style="min-width:200px"></div>
    <div><label>Charset</label><select name="charset"><?php foreach($charsets as $cs):?><option value="<?=eh($cs['Charset'])?>"><?=eh($cs['Charset'])?></option><?php endforeach;?></select></div>
    <button class="btn btn-ac">Create</button>
</form>

<?php
// ═══════════════════════════════════════════════════════════════════════════
// VIEW: TABLES
// ═══════════════════════════════════════════════════════════════════════════
elseif($act==='tables'&&$curDb):
    $tblSizes=[];
    try{$tblSizes=$pdo->query("SELECT TABLE_NAME,TABLE_ROWS,DATA_LENGTH+INDEX_LENGTH sz,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=".$pdo->quote($curDb))->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){}
    $sizeMap=[];foreach($tblSizes as $t)$sizeMap[$t['TABLE_NAME']]=$t;
?>
<h2>Tables — <?=eh($curDb)?></h2>
<table class="dt"><thead><tr><th>Table</th><th>Engine</th><th>Rows</th><th>Size</th><th>Actions</th></tr></thead><tbody>
<?php foreach($tblList as $t): $inf=$sizeMap[$t]??null;?>
<tr>
    <td><a href="?act=browse&db=<?=eh($curDb)?>&table=<?=eh($t)?>"><?=eh($t)?></a></td>
    <td class="nw"><?=eh($inf['ENGINE']??'')?></td>
    <td class="nw"><?=number_format($inf['TABLE_ROWS']??0)?></td>
    <td class="nw"><?=isset($inf['sz'])?fmtBytes($inf['sz']):'—'?></td>
    <td class="actions-cell">
        <a href="?act=structure&db=<?=eh($curDb)?>&table=<?=eh($t)?>" class="btn btn-ghost">Struct</a>
        <form method="POST" class="inline" onsubmit="return confirm('Truncate <?=eh($t)?>?')"><input type="hidden" name="act" value="truncate"><input type="hidden" name="table" value="<?=eh($t)?>"><input type="hidden" name="_csrf" value="<?=csrf()?>"><button class="btn btn-warn" style="padding:3px 8px;font-size:11px">Trunc</button></form>
        <form method="POST" class="inline" onsubmit="return confirm('DROP table <?=eh($t)?>?')"><input type="hidden" name="act" value="drop_table"><input type="hidden" name="table" value="<?=eh($t)?>"><input type="hidden" name="_csrf" value="<?=csrf()?>"><button class="btn btn-er" style="padding:3px 8px;font-size:11px">Drop</button></form>
    </td>
</tr>
<?php endforeach;?>
</tbody></table>

<h3>Create Table</h3>
<form method="POST" id="ctForm">
    <input type="hidden" name="act" value="create_table"><input type="hidden" name="_csrf" value="<?=csrf()?>">
    <div style="display:flex;gap:12px;margin-bottom:12px;align-items:flex-end;flex-wrap:wrap">
        <div><label>Table Name</label><input type="text" name="tname" required style="min-width:200px"></div>
        <div><label>Engine</label><select name="engine"><option>InnoDB</option><option>MyISAM</option><option>Aria</option><option>MEMORY</option></select></div>
        <div><label>Charset</label><select name="charset"><?php foreach($charsets as $cs):?><option value="<?=eh($cs['Charset'])?>"<?=($cs['Charset']==='utf8mb4'?' selected':'')?>><?=eh($cs['Charset'])?></option><?php endforeach;?></select></div>
    </div>
    <div class="col-editor" id="colEditor">
        <div class="col-row hdr"><span>Name</span><span>Type</span><span>NN</span><span>PK</span><span>AI</span><span>Default</span><span></span></div>
        <div class="col-row" data-idx="0"><input type="text" name="cols[0][field]" placeholder="id" required><select name="cols[0][type]"><option>INT</option><option>BIGINT</option><option>VARCHAR(255)</option><option>TEXT</option><option>DATETIME</option><option>DATE</option><option>DECIMAL(10,2)</option><option>BLOB</option><option>TINYINT</option><option>BOOLEAN</option><option>JSON</option></select><input type="checkbox" name="cols[0][nn]" value="1"><input type="checkbox" name="cols[0][pk]" value="1" checked><input type="checkbox" name="cols[0][ai]" value="1" checked><input type="text" name="cols[0][default]" placeholder="NULL" style="width:80px"><button type="button" class="btn btn-er" style="padding:3px 6px;font-size:11px" onclick="this.closest('.col-row').remove()">✕</button></div>
    </div>
    <button type="button" class="btn btn-ghost" onclick="addCol()" style="margin-bottom:12px">+ Add Column</button>
    <div><button class="btn btn-ac" type="submit">Create Table</button></div>
</form>
<script>
let colIdx=1;
function addCol(){
    let d=document.createElement('div');d.className='col-row';d.dataset.idx=colIdx;
    d.innerHTML='<input type="text" name="cols['+colIdx+'][field]" placeholder="column_name" required><select name="cols['+colIdx+'][type]"><option>VARCHAR(255)</option><option>INT</option><option>BIGINT</option><option>TEXT</option><option>DATETIME</option><option>DATE</option><option>DECIMAL(10,2)</option><option>BLOB</option><option>TINYINT</option><option>BOOLEAN</option><option>JSON</option></select><input type="checkbox" name="cols['+colIdx+'][nn]" value="1"><input type="checkbox" name="cols['+colIdx+'][pk]" value="1"><input type="checkbox" name="cols['+colIdx+'][ai]" value="1"><input type="text" name="cols['+colIdx+'][default]" placeholder="" style="width:80px"><button type="button" class="btn btn-er" style="padding:3px 6px;font-size:11px" onclick="this.closest(\'.col-row\').remove()">✕</button>';
    document.getElementById('colEditor').appendChild(d);colIdx++;
}
</script>

<?php
// ═══════════════════════════════════════════════════════════════════════════
// VIEW: BROWSE TABLE DATA
// ═══════════════════════════════════════════════════════════════════════════
elseif($act==='browse'&&$tbl&&$curDb):
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
    <h2 style="margin:0"><?=eh($tbl)?> — Browse</h2>
    <div style="display:flex;gap:8px">
        <?php if($tblInfo):?><span class="info-bar"><?=number_format($browseTotal)?> rows · <?=eh($tblInfo['ENGINE']??'')?> · <?=fmtBytes(($tblInfo['DATA_LENGTH']??0)+($tblInfo['INDEX_LENGTH']??0))?></span><?php endif;?>
        <a href="?act=insert&db=<?=eh($curDb)?>&table=<?=eh($tbl)?>" class="btn btn-ac">+ Insert</a>
    </div>
</div>
<form class="filter-bar" method="GET">
    <input type="hidden" name="act" value="browse"><input type="hidden" name="db" value="<?=eh($curDb)?>"><input type="hidden" name="table" value="<?=eh($tbl)?>">
    <input type="text" name="where" placeholder="WHERE clause (e.g. id > 10)" value="<?=eh($where)?>" style="flex:1">
    <button class="btn btn-ghost">Filter</button>
    <?php if($where):?><a href="?act=browse&db=<?=eh($curDb)?>&table=<?=eh($tbl)?>" class="btn btn-ghost">Clear</a><?php endif;?>
</form>
<div style="overflow-x:auto">
<table class="dt"><thead><tr>
<?php foreach($browseCols as $c): $od=($orderBy===qi($c))?(qi($c)." DESC"):qi($c);?>
<th><a href="?act=browse&db=<?=eh($curDb)?>&table=<?=eh($tbl)?>&where=<?=urlencode($where)?>&order=<?=urlencode($od)?>"><?=eh($c)?></a></th>
<?php endforeach;?>
<th>Actions</th>
</tr></thead><tbody>
<?php if(!$browseData):?><tr><td colspan="<?=count($browseCols)+1?>" style="text-align:center;color:var(--mu);padding:30px">No data</td></tr><?php endif;?>
<?php foreach($browseData as $ri=>$row):?>
<tr>
<?php foreach($row as $ci=>$v):
    $colName=$browseCols[$ci]; $isBlob=false;
    try{$colMeta=$structCols?:[]; foreach($colMeta as $cm){if($cm['Field']===$colName&&preg_match('/(blob|binary)/i',$cm['Type'])){$isBlob=true;break;}}}catch(Exception $ex){}
?>
<td class="<?=($v===null?'null':($isBlob?'blob':''))?>" title="<?=eh($v===null?'NULL':($isBlob?'[BLOB '.strlen($v).' bytes]':$v))?>"><?=($v===null?'NULL':($isBlob?'[BLOB '.strlen($v).'B]':eh($v)))?></td>
<?php endforeach;?>
<td class="actions-cell">
<?php
// build PK link
$pkLink='act=edit&db='.urlencode($curDb).'&table='.urlencode($tbl);
foreach($browseCols as $ci=>$cn){$pkLink.='&pk_'.urlencode($cn).'='.urlencode($row[$ci]??'');}
?>
<a href="?<?=$pkLink?>" class="btn btn-ghost">Edit</a>
<form method="POST" class="inline" onsubmit="return confirm('Delete this row?')"><input type="hidden" name="act" value="delete_row"><input type="hidden" name="table" value="<?=eh($tbl)?>"><input type="hidden" name="_csrf" value="<?=csrf()?>"><input type="hidden" name="where" value="<?php
$wh=[];foreach($browseCols as $ci=>$cn){$v=$row[$ci];$wh[]=qi($cn).($v===null?' IS NULL':"='".escSQL($v)."'");}
echo eh(implode(' AND ',$wh));
?>"><button class="btn btn-er" style="padding:3px 8px;font-size:11px">Del</button></form>
</td>
</tr>
<?php endforeach;?>
</tbody></table>
</div>
<?php if($browsePages>1):?>
<div class="pagination">
<?php if($page>1):?><a href="?act=browse&db=<?=eh($curDb)?>&table=<?=eh($tbl)?>&p=<?=$page-1?>&where=<?=urlencode($where)?>&order=<?=urlencode($orderBy)?>">&laquo; Prev</a><?php endif;?>
<?php for($i=max(1,$page-3);$i<=min($browsePages,$page+3);$i++):?>
<?php if($i===$page):?><span class="cur"><?=$i?></span><?php else:?><a href="?act=browse&db=<?=eh($curDb)?>&table=<?=eh($tbl)?>&p=<?=$i?>&where=<?=urlencode($where)?>&order=<?=urlencode($orderBy)?>"><?=$i?></a><?php endif;?>
<?php endfor;?>
<?php if($page<$browsePages):?><a href="?act=browse&db=<?=eh($curDb)?>&table=<?=eh($tbl)?>&p=<?=$page+1?>&where=<?=urlencode($where)?>&order=<?=urlencode($orderBy)?>">Next &raquo;</a><?php endif;?>
<span style="margin-left:8px"><?=number_format($browseTotal)?> rows total</span>
</div>
<?php endif;?>

<?php
// ═══════════════════════════════════════════════════════════════════════════
// VIEW: TABLE STRUCTURE
// ═══════════════════════════════════════════════════════════════════════════
elseif(($act==='structure'||$act==='add_column')&&$tbl&&$curDb):
?>
<h2><?=eh($tbl)?> — Structure</h2>
<div class="tabs">
    <a href="?act=structure&db=<?=eh($curDb)?>&table=<?=eh($tbl)?>" class="active">Columns</a>
    <a href="#" onclick="document.getElementById('idxSection').scrollIntoView();return false">Indexes</a>
    <a href="#" onclick="document.getElementById('createSection').scrollIntoView();return false">CREATE SQL</a>
</div>
<table class="dt"><thead><tr><th>#</th><th>Column</th><th>Type</th><th>Nullable</th><th>Default</th><th>Key</th><th>Extra</th><th>Actions</th></tr></thead><tbody>
<?php foreach($structCols as $i=>$c):?>
<tr>
    <td class="nw"><?=$i+1?></td>
    <td><strong><?=eh($c['Field'])?></strong></td>
    <td class="nw"><?=eh($c['Type'])?></td>
    <td class="nw"><?=eh($c['Null'])?></td>
    <td class="nw"><?=eh($c['Default']??'NULL')?></td>
    <td class="nw"><?=eh($c['Key'])?></td>
    <td class="nw"><?=eh($c['Extra'])?></td>
    <td><form method="POST" class="inline" onsubmit="return confirm('Drop column <?=eh($c['Field'])?>?')"><input type="hidden" name="act" value="drop_column"><input type="hidden" name="table" value="<?=eh($tbl)?>"><input type="hidden" name="column" value="<?=eh($c['Field'])?>"><input type="hidden" name="_csrf" value="<?=csrf()?>"><button class="btn btn-er" style="padding:3px 8px;font-size:11px">Drop</button></form></td>
</tr>
<?php endforeach;?>
</tbody></table>

<h3>Add Column</h3>
<form method="POST" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="act" value="add_column"><input type="hidden" name="table" value="<?=eh($tbl)?>"><input type="hidden" name="_csrf" value="<?=csrf()?>">
    <div><label>Name</label><input type="text" name="field" required></div>
    <div><label>Type</label><input type="text" name="type" value="VARCHAR(255)" style="width:150px"></div>
    <div><label>Not Null</label><input type="checkbox" name="nn" value="1" style="margin-top:4px"></div>
    <div><label>Auto Incr</label><input type="checkbox" name="ai" value="1" style="margin-top:4px"></div>
    <div><label>Default</label><input type="text" name="default" placeholder="NULL" style="width:100px"></div>
    <button class="btn btn-ac">Add</button>
</form>

<h3 id="idxSection">Indexes</h3>
<?php
$idxGrouped=[];
foreach($structIdx as $ix){$idxGrouped[$ix['Key_name']][]=$ix;}
?>
<table class="dt"><thead><tr><th>Index</th><th>Unique</th><th>Columns</th><th>Actions</th></tr></thead><tbody>
<?php foreach($idxGrouped as $iname=>$cols):?>
<tr>
    <td><?=eh($iname)?></td>
    <td><?=($cols[0]['Non_unique']==0?'Yes':'No')?></td>
    <td><?=eh(implode(', ',array_column($cols,'Column_name')))?></td>
    <td><?php if($iname!=='PRIMARY'):?><form method="POST" class="inline" onsubmit="return confirm('Drop index?')"><input type="hidden" name="act" value="drop_index"><input type="hidden" name="table" value="<?=eh($tbl)?>"><input type="hidden" name="index" value="<?=eh($iname)?>"><input type="hidden" name="_csrf" value="<?=csrf()?>"><button class="btn btn-er" style="padding:3px 8px;font-size:11px">Drop</button></form><?php else:?>—<?php endif;?></td>
</tr>
<?php endforeach;?>
</tbody></table>

<h3>Add Index</h3>
<form method="POST" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="act" value="add_index"><input type="hidden" name="table" value="<?=eh($tbl)?>"><input type="hidden" name="_csrf" value="<?=csrf()?>">
    <div><label>Name</label><input type="text" name="iname" required></div>
    <div><label>Columns</label><select name="icols[]" multiple size="1" style="min-width:150px;height:32px"><?php foreach($structCols as $c):?><option value="<?=eh($c['Field'])?>"><?=eh($c['Field'])?></option><?php endforeach;?></select></div>
    <div><label>Unique</label><input type="checkbox" name="unique" value="1" style="margin-top:4px"></div>
    <button class="btn btn-ac">Add</button>
</form>

<h3 id="createSection">CREATE Statement</h3>
<div class="code-block"><?=eh($structCreate)?></div>

<?php
// ═══════════════════════════════════════════════════════════════════════════
// VIEW: INSERT ROW
// ═══════════════════════════════════════════════════════════════════════════
elseif($act==='insert'&&$tbl&&$curDb):
?>
<h2>Insert into <?=eh($tbl)?></h2>
<form method="POST">
    <input type="hidden" name="act" value="insert_row"><input type="hidden" name="table" value="<?=eh($tbl)?>"><input type="hidden" name="_csrf" value="<?=csrf()?>">
    <?php foreach($insertCols as $c):?>
    <div class="form-row">
        <label><?=eh($c['Field'])?> 
        <span style="color:var(--mu)">
        (
        <?=eh($c['Type'])?>
        <?=($c['Null']==='YES'?' · NULL':'')?><?=($c['Extra']?' · '.$c['Extra']:'')?>
        )
        </span>
        </label>
        <?php if(preg_match('/(blob|binary)/i',$c['Type'])):?>
            <input type="file" name="file_<?=eh($c['Field'])?>" style="color:var(--tx)"><input type="hidden" name="vals[<?=eh($c['Field'])?>][val]" value="">
        <?php elseif(preg_match('/(text|json)/i',$c['Type'])):?>
            <textarea name="vals[<?=eh($c['Field'])?>][val]" rows="3" style="width:100%"></textarea>
        <?php else:?>
            <input type="text" name="vals[<?=eh($c['Field'])?>][val]" style="width:100%" placeholder="<?=($c['Default']!==null?'Default: '.$c['Default']:'')?>">
        <?php endif;?>
        <?php if($c['Null']==='YES'):?><label style="font-weight:normal;text-transform:none"><input type="checkbox" name="vals[<?=eh($c['Field'])?>][null]" value="1"> Set NULL</label><?php endif;?>
    </div>
    <?php endforeach;?>
    <button class="btn btn-ac">Insert Row</button>
</form>

<?php
// ═══════════════════════════════════════════════════════════════════════════
// VIEW: EDIT ROW
// ═══════════════════════════════════════════════════════════════════════════
elseif($act==='edit'&&$tbl&&$curDb&&$editRow):
?>
<h2>Edit Row — <?=eh($tbl)?></h2>
<form method="POST">
    <input type="hidden" name="act" value="update_row"><input type="hidden" name="table" value="<?=eh($tbl)?>"><input type="hidden" name="_csrf" value="<?=csrf()?>">
    <?php
    // rebuild where clause from PK values
    $whParts=[];
    foreach($editCols as $c){if($c['Key']==='PRI'){$whParts[]=qi($c['Field'])."='".escSQL($editRow[$c['Field']]??'')."'";}
    }
    if(!$whParts)foreach($editCols as $c){$whParts[]=qi($c['Field'])."='".escSQL($editRow[$c['Field']]??'')."'";}
    ?>
    <input type="hidden" name="where" value="<?=eh(implode(' AND ',$whParts))?>">
    <?php foreach($editCols as $c): $v=$editRow[$c['Field']]??'';?>
    <div class="form-row">
        <label><?=eh($c['Field'])?> <span style="color:var(--mu)">(<?=eh($c['Type'])?>)</span></label>
        <?php if(preg_match('/(text|json)/i',$c['Type'])):?>
            <textarea name="vals[<?=eh($c['Field'])?>][val]" rows="3" style="width:100%"><?=eh($v)?></textarea>
        <?php elseif(preg_match('/(blob|binary)/i',$c['Type'])):?>
            <input type="text" name="vals[<?=eh($c['Field'])?>][val]" value="[BLOB - not editable inline]" disabled style="width:100%">
        <?php else:?>
            <input type="text" name="vals[<?=eh($c['Field'])?>][val]" value="<?=eh($v)?>" style="width:100%">
        <?php endif;?>
        <?php if($c['Null']==='YES'):?><label style="font-weight:normal;text-transform:none"><input type="checkbox" name="vals[<?=eh($c['Field'])?>][null]" value="1"<?=($v===null?' checked':'')?>> NULL</label><?php endif;?>
    </div>
    <?php endforeach;?>
    <button class="btn btn-ac">Save Changes</button>
    <a href="?act=browse&db=<?=eh($curDb)?>&table=<?=eh($tbl)?>" class="btn btn-ghost" style="margin-left:8px">Cancel</a>
</form>

<?php
// ═══════════════════════════════════════════════════════════════════════════
// VIEW: VIEWS
// ═══════════════════════════════════════════════════════════════════════════
elseif($act==='views'&&$curDb):
?>
<h2>Views — <?=eh($curDb)?></h2>
<table class="dt"><thead><tr><th>View</th><th>Actions</th></tr></thead><tbody>
<?php foreach($viewList as $v):?>
<tr>
    <td><?=eh($v)?></td>
    <td class="actions-cell">
        <a href="?act=edit_view&db=<?=eh($curDb)?>&name=<?=eh($v)?>" class="btn btn-ghost">Edit</a>
        <form method="POST" class="inline" onsubmit="return confirm('Drop view?')"><input type="hidden" name="act" value="drop_view"><input type="hidden" name="name" value="<?=eh($v)?>"><input type="hidden" name="_csrf" value="<?=csrf()?>"><button class="btn btn-er" style="padding:3px 8px;font-size:11px">Drop</button></form>
    </td>
</tr>
<?php endforeach;?>
<?php if(!$viewList):?><tr><td colspan="2" style="text-align:center;color:var(--mu);padding:20px">No views</td></tr><?php endif;?>
</tbody></table>

<h3>Create / Edit View</h3>
<form method="POST">
    <input type="hidden" name="act" value="create_view"><input type="hidden" name="_csrf" value="<?=csrf()?>">
    <div class="form-row"><label>Name</label><input type="text" name="name" value="<?=eh($_GET['name']??'')?>" required style="width:300px"></div>
    <div class="form-row"><label>Definition (AS SELECT ...)</label><textarea name="definition" rows="6" style="width:100%" placeholder="SELECT id, name FROM users WHERE active = 1"><?=eh($viewDef)?></textarea></div>
    <button class="btn btn-ac">Save View</button>
</form>

<?php
// ═══════════════════════════════════════════════════════════════════════════
// VIEW: PROCEDURES
// ═══════════════════════════════════════════════════════════════════════════
elseif($act==='procedures'&&$curDb):
?>
<h2>Stored Procedures — <?=eh($curDb)?></h2>
<table class="dt"><thead><tr><th>Procedure</th><th>Actions</th></tr></thead><tbody>
<?php foreach($procList as $p):?>
<tr>
    <td><?=eh($p)?></td>
    <td class="actions-cell">
        <a href="?act=edit_proc&db=<?=eh($curDb)?>&name=<?=eh($p)?>" class="btn btn-ghost">Edit</a>
        <form method="POST" class="inline" onsubmit="return confirm('Drop procedure?')"><input type="hidden" name="act" value="drop_proc"><input type="hidden" name="name" value="<?=eh($p)?>"><input type="hidden" name="_csrf" value="<?=csrf()?>"><button class="btn btn-er" style="padding:3px 8px;font-size:11px">Drop</button></form>
    </td>
</tr>
<?php endforeach;?>
<?php if(!$procList):?><tr><td colspan="2" style="text-align:center;color:var(--mu);padding:20px">No stored procedures</td></tr><?php endif;?>
</tbody></table>

<h3>Create / Edit Procedure</h3>
<form method="POST">
    <input type="hidden" name="act" value="create_proc"><input type="hidden" name="_csrf" value="<?=csrf()?>">
    <div class="form-row"><label>Name</label><input type="text" name="name" value="<?=eh($_GET['name']??'')?>" required style="width:300px"></div>
    <div class="form-row"><label>CREATE PROCEDURE Statement</label><textarea name="body" rows="10" style="width:100%"><?=eh($procDef?:'CREATE PROCEDURE `name`(IN param INT)\nBEGIN\n  -- body\nEND') ?></textarea></div>
    <button class="btn btn-ac">Save Procedure</button>
</form>

<?php
// ═══════════════════════════════════════════════════════════════════════════
// VIEW: FUNCTIONS
// ═══════════════════════════════════════════════════════════════════════════
elseif($act==='functions'&&$curDb):
?>
<h2>Functions — <?=eh($curDb)?></h2>
<table class="dt"><thead><tr><th>Function</th><th>Actions</th></tr></thead><tbody>
<?php foreach($funcList as $f):?>
<tr>
    <td><?=eh($f)?></td>
    <td class="actions-cell">
        <a href="?act=edit_func&db=<?=eh($curDb)?>&name=<?=eh($f)?>" class="btn btn-ghost">Edit</a>
        <form method="POST" class="inline" onsubmit="return confirm('Drop function?')"><input type="hidden" name="act" value="drop_func"><input type="hidden" name="name" value="<?=eh($f)?>"><input type="hidden" name="_csrf" value="<?=csrf()?>"><button class="btn btn-er" style="padding:3px 8px;font-size:11px">Drop</button></form>
    </td>
</tr>
<?php endforeach;?>
<?php if(!$funcList):?><tr><td colspan="2" style="text-align:center;color:var(--mu);padding:20px">No functions</td></tr><?php endif;?>
</tbody></table>

<h3>Create / Edit Function</h3>
<form method="POST">
    <input type="hidden" name="act" value="create_func"><input type="hidden" name="_csrf" value="<?=csrf()?>">
    <div class="form-row"><label>Name</label><input type="text" name="name" value="<?=eh($_GET['name']??'')?>" required style="width:300px"></div>
    <div class="form-row"><label>CREATE FUNCTION Statement</label><textarea name="body" rows="10" style="width:100%"><?=eh($funcDef?:'CREATE FUNCTION `name`(param INT)\nRETURNS INT DETERMINISTIC\nBEGIN\n  -- body\n  RETURN 0;\nEND')?></textarea></div>
    <button class="btn btn-ac">Save Function</button>
</form>

<?php
// ═══════════════════════════════════════════════════════════════════════════
// VIEW: SQL QUERY
// ═══════════════════════════════════════════════════════════════════════════
elseif($act==='sql'&&$curDb):
?>
<h2>SQL Query — <?=eh($curDb)?></h2>
<form method="POST">
    <input type="hidden" name="act" value="run_sql"><input type="hidden" name="_csrf" value="<?=csrf()?>">
    <div class="form-row"><textarea name="sqltext" rows="8" style="width:100%" placeholder="SELECT * FROM table_name WHERE ..." autofocus><?=eh($_POST['sqltext']??'')?></textarea></div>
    <button class="btn btn-ac">Execute</button>
    <span style="margin-left:12px;color:var(--mu);font-size:12px">Database: <?=eh($curDb)?></span>
</form>
<?php if(isset($sqlErr)):?>
<div class="alert alert-error"><?=eh($sqlErr)?></div>
<?php elseif(isset($sqlMsg)):?>
<div class="alert alert-success"><?=eh($sqlMsg)?></div>
<?php if($sqlResult!==null&&$sqlCols):?>
<div style="overflow-x:auto;margin-top:12px">
<table class="dt"><thead><tr><?php foreach($sqlCols as $c):?><th><?=eh($c)?></th><?php endforeach;?></tr></thead><tbody>
<?php foreach($sqlResult as $row):?>
<tr><?php foreach($row as $v):?><td<?=($v===null?' class="null"':'')?>><?=($v===null?'NULL':eh($v))?></td><?php endforeach;?></tr>
<?php endforeach;?>
<?php if(!$sqlResult):?><tr><td colspan="<?=count($sqlCols)?>" style="text-align:center;color:var(--mu);padding:16px">Empty result set</td></tr><?php endif;?>
</tbody></table>
</div>
<?php endif; endif;?>

<?php
// ═══════════════════════════════════════════════════════════════════════════
// VIEW: BACKUP
// ═══════════════════════════════════════════════════════════════════════════
elseif($act==='backup'&&$curDb):
?>
<h2>Backup — <?=eh($curDb)?></h2>
<form method="POST">
    <input type="hidden" name="act" value="do_backup"><input type="hidden" name="db" value="<?=eh($curDb)?>"><input type="hidden" name="_csrf" value="<?=csrf()?>">
    <div class="form-grid">
        <div><label><input type="checkbox" name="inc_data" value="1" checked> Include Data</label></div>
        <div><label><input type="checkbox" name="inc_views" value="1" checked> Include Views</label></div>
        <div><label><input type="checkbox" name="inc_procs" value="1" checked> Include Procedures</label></div>
        <div><label><input type="checkbox" name="inc_funcs" value="1" checked> Include Functions</label></div>
        <div><label><input type="checkbox" name="compress" value="1"> Compress (gzip)</label></div>
    </div>
    <div style="margin-top:16px"><button class="btn btn-ac">Download Backup</button></div>
</form>

<h3 style="margin-top:28px">Automated Backup via GET</h3>
<p style="color:var(--mu);font-size:13px;margin-bottom:8px">To enable automated backups, set <code style="background:var(--s2);padding:2px 6px;border-radius:3px;font-family:var(--fm)">BACKUP_API_KEY</code> at the top of the PHP file, then use:</p>
<div class="code-block" style="font-size:12px">
# Full backup (download)
curl -o backup.sql "<?=eh((isset($_SERVER['HTTPS'])?'https':'http').'://'.$_SERVER['HTTP_HOST'].$_SERVER['SCRIPT_NAME'])?>?auto_backup=1&key=YOUR_KEY&db=<?=eh($curDb)?>&user=<?=eh($me)?>"

# Compressed backup
curl -o backup.sql.gz "...?auto_backup=1&key=YOUR_KEY&db=<?=eh($curDb)?>&compress=1"

# Save to server directory (requires BACKUP_SAVE_DIR)
curl "...?auto_backup=1&key=YOUR_KEY&db=<?=eh($curDb)?>&save=1"
</div>
<p style="color:var(--mu);font-size:12px;margin-top:8px">Note: For automated backups, set BACKUP_DB_USER and BACKUP_DB_PASS in the script configuration. The <code style="background:var(--s2);padding:2px 6px;border-radius:3px;font-family:var(--fm)">user</code> and <code style="background:var(--s2);padding:2px 6px;border-radius:3px;font-family:var(--fm)">pass</code> GET parameters are also accepted but less secure (visible in server logs).</p>

<?php
// ═══════════════════════════════════════════════════════════════════════════
// VIEW: RESTORE
// ═══════════════════════════════════════════════════════════════════════════
elseif($act==='restore'&&$curDb):
?>
<h2>Restore — <?=eh($curDb)?></h2>
<form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="act" value="restore"><input type="hidden" name="_csrf" value="<?=csrf()?>">
    <div class="form-row"><label>Upload .sql or .sql.gz file</label><input type="file" name="file" accept=".sql,.gz,.sql.gz" style="color:var(--tx)"></div>
    <div style="text-align:center;color:var(--mu);margin:12px 0;font-size:12px">— OR paste SQL below —</div>
    <div class="form-row"><textarea name="sqltext" rows="12" style="width:100%" placeholder="Paste SQL statements here..."></textarea></div>
    <button class="btn btn-ac">Restore</button>
</form>

<?php
// ═══════════════════════════════════════════════════════════════════════════
// DEFAULT: redirect to databases
// ═══════════════════════════════════════════════════════════════════════════
else:
    redir('?act=databases');
endif;
?>
</main>
</div>
</div>
</body></html>
