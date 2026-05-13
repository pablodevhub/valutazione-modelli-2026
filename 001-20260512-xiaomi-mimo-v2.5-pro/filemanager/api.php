<?php
session_start();
if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Non autenticato']);
    exit;
}

function loadEnv($path) {
    $env = [];
    if (!file_exists($path)) return $env;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $p = explode('=', $line, 2);
        if (count($p) === 2) $env[trim($p[0])] = trim($p[1]);
    }
    return $env;
}

$config          = loadEnv(__DIR__ . '/.env');
$allowedFolders  = array_filter(array_map('trim', explode(',', $config['ALLOWED_FOLDERS'] ?? '')));
$textExtensions  = array_filter(array_map('trim', explode(',', $config['TEXT_EXTENSIONS'] ?? '')));

header('Content-Type: application/json');

function validatePath($path, $allowed) {
    $rp = realpath($path);
    if ($rp === false) return false;
    foreach ($allowed as $f) {
        $rf = realpath($f);
        if ($rf !== false && strpos($rp, $rf) === 0) return $rp;
    }
    return false;
}

function delRecursive($p) {
    if (!file_exists($p)) return false;
    if (is_dir($p)) {
        foreach (array_diff(scandir($p), ['.','..']) as $e) delRecursive($p.'/'.$e);
        return rmdir($p);
    }
    return unlink($p);
}

$action = $_REQUEST['action'] ?? '';

try {
    switch ($action) {

        /* ---------- TREE ---------- */
        case 'tree': {
            $rp = validatePath($_GET['path'] ?? '', $allowedFolders);
            if (!$rp) throw new Exception('Percorso non valido');
            $items = [];
            foreach (array_diff(@scandir($rp) ?: [], ['.','..']) as $e) {
                $fp = $rp.'/'.$e;
                if (!is_dir($fp)) continue;
                $has = false;
                foreach (array_diff(@scandir($fp) ?: [], ['.','..']) as $s) {
                    if (is_dir($fp.'/'.$s)) { $has = true; break; }
                }
                $items[] = ['name'=>$e,'path'=>$fp,'hasChildren'=>$has];
            }
            usort($items, fn($a,$b) => strcasecmp($a['name'],$b['name']));
            echo json_encode(['items'=>$items]);
            break;
        }

        /* ---------- LIST ---------- */
        case 'list': {
            $rp = validatePath($_GET['path'] ?? '', $allowedFolders);
            if (!$rp) throw new Exception('Percorso non valido');
            $items = [];
            foreach (array_diff(@scandir($rp) ?: [], ['.','..']) as $e) {
                $fp = $rp.'/'.$e;
                $isD = is_dir($fp);
                $ext = $isD ? '' : strtolower(pathinfo($e, PATHINFO_EXTENSION));
                $items[] = [
                    'name'       => $e,
                    'path'       => $fp,
                    'isDir'      => $isD,
                    'size'       => $isD ? null : @filesize($fp),
                    'modified'   => @filemtime($fp),
                    'extension'  => $ext,
                    'isText'     => !$isD && in_array($ext, $textExtensions),
                    'permissions'=> substr(sprintf('%o', @fileperms($fp)), -4)
                ];
            }
            usort($items, fn($a,$b) => ($b['isDir']<=>$a['isDir']) ?: strcasecmp($a['name'],$b['name']));
            echo json_encode(['items'=>$items,'path'=>$rp]);
            break;
        }

        /* ---------- UPLOAD ---------- */
        case 'upload': {
            $rp = validatePath($_POST['path'] ?? '', $allowedFolders);
            if (!$rp) throw new Exception('Percorso non valido');
            $results = [];
            if (!empty($_FILES['files'])) {
                $f = $_FILES['files'];
                $n = is_array($f['name']) ? count($f['name']) : 1;
                for ($i=0;$i<$n;$i++) {
                    $nm = is_array($f['name'])    ? $f['name'][$i]    : $f['name'];
                    $tp = is_array($f['tmp_name']) ? $f['tmp_name'][$i] : $f['tmp_name'];
                    $er = is_array($f['error'])    ? $f['error'][$i]    : $f['error'];
                    if ($er === UPLOAD_ERR_OK && move_uploaded_file($tp, $rp.'/'.basename($nm))) {
                        $results[] = ['name'=>$nm,'status'=>'ok'];
                    } else {
                        $results[] = ['name'=>$nm,'status'=>'error','message'=>"Errore $er"];
                    }
                }
            }
            echo json_encode(['results'=>$results]);
            break;
        }

        /* ---------- DOWNLOAD ---------- */
        case 'download': {
            $rp = validatePath($_GET['path'] ?? '', $allowedFolders);
            if (!$rp || is_dir($rp)) throw new Exception('File non valido');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="'.basename($rp).'"');
            header('Content-Length: '.filesize($rp));
            readfile($rp);
            exit;
        }

        /* ---------- READ (text) ---------- */
        case 'read': {
            $rp = validatePath($_GET['path'] ?? '', $allowedFolders);
            if (!$rp || is_dir($rp)) throw new Exception('File non valido');
            $ext = strtolower(pathinfo($rp, PATHINFO_EXTENSION));
            if (!in_array($ext, $textExtensions)) throw new Exception('Non \u00e8 un file di testo');
            $c = file_get_contents($rp);
            if ($c === false) throw new Exception('Lettura fallita');
            echo json_encode(['content'=>$c,'name'=>basename($rp),'extension'=>$ext]);
            break;
        }

        /* ---------- SAVE (text) ---------- */
        case 'save': {
            $rp = validatePath($_POST['path'] ?? '', $allowedFolders);
            if (!$rp || is_dir($rp)) throw new Exception('File non valido');
            $ext = strtolower(pathinfo($rp, PATHINFO_EXTENSION));
            if (!in_array($ext, $textExtensions)) throw new Exception('Non \u00e8 un file di testo');
            if (file_put_contents($rp, $_POST['content'] ?? '') === false) throw new Exception('Salvataggio fallito');
            echo json_encode(['status'=>'ok']);
            break;
        }

        /* ---------- DELETE ---------- */
        case 'delete': {
            $rp = validatePath($_POST['path'] ?? '', $allowedFolders);
            if (!$rp) throw new Exception('Percorso non valido');
            foreach ($allowedFolders as $af) { if (realpath($af)===$rp) throw new Exception('Non puoi eliminare la cartella root'); }
            if (!delRecursive($rp)) throw new Exception('Eliminazione fallita');
            echo json_encode(['status'=>'ok']);
            break;
        }

        /* ---------- MKDIR ---------- */
        case 'mkdir': {
            $name = trim($_POST['name'] ?? '');
            if ($name==='') throw new Exception('Nome richiesto');
            $rp = validatePath($_POST['path'] ?? '', $allowedFolders);
            if (!$rp) throw new Exception('Percorso non valido');
            $nd = $rp.'/'.basename($name);
            if (file_exists($nd)) throw new Exception('Gi\u00e0 esistente');
            if (!mkdir($nd)) throw new Exception('Creazione fallita');
            echo json_encode(['status'=>'ok']);
            break;
        }

        /* ---------- CREATE FILE ---------- */
        case 'createFile': {
            $name = trim($_POST['name'] ?? '');
            if ($name==='') throw new Exception('Nome richiesto');
            $rp = validatePath($_POST['path'] ?? '', $allowedFolders);
            if (!$rp) throw new Exception('Percorso non valido');
            $nf = $rp.'/'.basename($name);
            if (file_exists($nf)) throw new Exception('Gi\u00e0 esistente');
            if (file_put_contents($nf, '')===false) throw new Exception('Creazione fallita');
            echo json_encode(['status'=>'ok']);
            break;
        }

        /* ---------- RENAME ---------- */
        case 'rename': {
            $nn = trim($_POST['newName'] ?? '');
            if ($nn==='') throw new Exception('Nome richiesto');
            $rp = validatePath($_POST['oldPath'] ?? '', $allowedFolders);
            if (!$rp) throw new Exception('Percorso non valido');
            $np = dirname($rp).'/'.basename($nn);
            if (file_exists($np)) throw new Exception('Gi\u00e0 esistente');
            if (!rename($rp,$np)) throw new Exception('Rinomina fallita');
            echo json_encode(['status'=>'ok','newPath'=>$np]);
            break;
        }

        /* ---------- ZIP ---------- */
        case 'zip': {
            $items = json_decode($_POST['items'] ?? '[]', true);
            if (empty($items)) throw new Exception('Nessun elemento selezionato');
            $bp = validatePath($_POST['basePath'] ?? '', $allowedFolders);
            if (!$bp) throw new Exception('Percorso non valido');
            if (!class_exists('ZipArchive')) throw new Exception('ZipArchive non disponibile');
            $zn = 'archive_'.date('Y-m-d_His').'.zip';
            $zp = $bp.'/'.$zn;
            $zip = new ZipArchive();
            if ($zip->open($zp, ZipArchive::CREATE)!==true) throw new Exception('Creazione ZIP fallita');
            $add = function($z,$p,$base) use (&$add) {
                $rel = substr($p, strlen($base)+1);
                if (is_dir($p)) {
                    $z->addEmptyDir($rel);
                    foreach (array_diff(scandir($p),['.','..']) as $e) $add($z,$p.'/'.$e,$base);
                } else { $z->addFile($p,$rel); }
            };
            foreach ($items as $it) {
                $ri = validatePath($it, $allowedFolders);
                if ($ri) $add($zip, $ri, dirname($ri));
            }
            $zip->close();
            echo json_encode(['status'=>'ok','zipName'=>$zn,'zipPath'=>$zp]);
            break;
        }

        /* ---------- DOWNLOAD ZIP ---------- */
        case 'downloadZip': {
            $rp = validatePath($_GET['path'] ?? '', $allowedFolders);
            if (!$rp || !file_exists($rp)) throw new Exception('File non trovato');
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="'.basename($rp).'"');
            header('Content-Length: '.filesize($rp));
            readfile($rp);
            @unlink($rp);
            exit;
        }

        /* ---------- CONFIG ---------- */
        case 'getConfig': {
            echo json_encode(['textExtensions'=>$textExtensions,'rootFolder'=>$_SESSION['rootFolder']]);
            break;
        }

        default:
            throw new Exception('Azione sconosciuta');
    }
} catch (Exception $e) {
    echo json_encode(['error'=>$e->getMessage()]);
}
