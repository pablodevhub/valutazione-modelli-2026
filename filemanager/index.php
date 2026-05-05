<?php
session_start();
if (isset($_SESSION['authenticated']) && $_SESSION['authenticated']) {
    header('Location: app.php');
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

$config  = loadEnv(__DIR__ . '/.env');
$folders = array_filter(array_map('trim', explode(',', $config['ALLOWED_FOLDERS'] ?? '')));
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user   = $_POST['username'] ?? '';
    $pass   = $_POST['password'] ?? '';
    $folder = $_POST['folder']   ?? '';
    if ($user === ($config['APP_USER'] ?? '') && $pass === ($config['APP_PASSWORD'] ?? '')) {
        if (in_array($folder, $folders) && is_dir($folder)) {
            $_SESSION['authenticated'] = true;
            $_SESSION['username']      = $user;
            $_SESSION['rootFolder']    = $folder;
            header('Location: app.php');
            exit;
        }
        $error = 'La cartella selezionata non \u00e8 valida o non esiste.';
    } else {
        $error = 'Credenziali non valide.';
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>File Manager &mdash; Login</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#0b0d13;--surface:#11141e;--surface2:#171b28;--text:#e4e7f0;--dim:#8890a8;--accent:#5e9eff;--accent-h:#4b8cf0;--danger:#ff5c6c;--border:#1f2437;--r:8px}
body{font-family:'Outfit',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center}
.login-box{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:44px 40px;width:100%;max-width:420px;box-shadow:0 24px 64px rgba(0,0,0,.45)}
.login-box h1{text-align:center;font-size:26px;font-weight:700;margin-bottom:6px}
.login-box .sub{text-align:center;color:var(--dim);font-size:14px;margin-bottom:32px}
.fg{margin-bottom:20px}
.fg label{display:block;font-size:12px;font-weight:600;color:var(--dim);margin-bottom:6px;text-transform:uppercase;letter-spacing:.6px}
.fg input,.fg select{width:100%;padding:11px 14px;background:var(--surface2);border:1px solid var(--border);border-radius:var(--r);color:var(--text);font-family:'JetBrains Mono',monospace;font-size:14px;outline:0;transition:border .2s}
.fg input:focus,.fg select:focus{border-color:var(--accent)}
.fg select{cursor:pointer;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%238890a4' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center;padding-right:36px}
.fg select option{background:var(--surface);color:var(--text)}
.btn-login{width:100%;padding:12px;background:var(--accent);color:#fff;border:none;border-radius:var(--r);font-family:'Outfit',sans-serif;font-size:15px;font-weight:600;cursor:pointer;transition:background .2s,transform .1s;margin-top:8px}
.btn-login:hover{background:var(--accent-h)}
.btn-login:active{transform:scale(.98)}
.err{background:rgba(255,92,108,.1);border:1px solid rgba(255,92,108,.3);color:var(--danger);padding:10px 14px;border-radius:var(--r);font-size:13px;margin-bottom:20px}
</style>
</head>
<body>
<div class="login-box">
    <h1>File Manager</h1>
    <p class="sub">Accedi per gestire i file sul server</p>
    <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="POST">
        <div class="fg">
            <label for="u">Username</label>
            <input type="text" id="u" name="username" required autocomplete="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
        </div>
        <div class="fg">
            <label for="p">Password</label>
            <input type="password" id="p" name="password" required autocomplete="current-password">
        </div>
        <div class="fg">
            <label for="f">Cartella da gestire</label>
            <select id="f" name="folder" required>
                <option value="">&mdash; Seleziona una cartella &mdash;</option>
                <?php foreach ($folders as $fo): ?>
                <option value="<?= htmlspecialchars($fo) ?>" <?= (($_POST['folder'] ?? '') === $fo) ? 'selected' : '' ?>><?= htmlspecialchars($fo) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn-login">Accedi</button>
    </form>
</div>
</body>
</html>
