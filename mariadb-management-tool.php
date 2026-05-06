<?php
session_start();
if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) { header('Location: index.php'); exit; }
$rootFolder = $_SESSION['rootFolder'];
$username   = $_SESSION['username'];
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>File Manager</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
/* ========================================
   RESET & BASE
   ======================================== */
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
:root{
  --bg:#0b0d13;--panel:#11141e;--surface:#171b28;--hover:#1e2336;--active:#252a40;
  --text:#e4e7f0;--dim:#8890a8;--muted:#555b73;
  --accent:#5e9eff;--accent-h:#4b8cf0;--accent-bg:rgba(94,158,255,.12);
  --danger:#ff5c6c;--danger-h:#e84d5c;--danger-bg:rgba(255,92,108,.12);
  --success:#4ade80;--success-bg:rgba(74,222,128,.12);
  --warning:#fbbf24;--warning-bg:rgba(251,191,36,.12);
  --border:#1f2437;--border-l:#2a3048;
  --r:6px;--r-lg:10px;
  --sidebar-w:280px;--header-h:52px;--toolbar-h:46px;--status-h:30px;
}
html,body{height:100%;overflow:hidden}
body{font-family:'Outfit',sans-serif;background:var(--bg);color:var(--text);font-size:14px}

/* Scrollbar */
::-webkit-scrollbar{width:7px;height:7px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:var(--border-l);border-radius:4px}
::-webkit-scrollbar-thumb:hover{background:var(--muted)}

/* ========================================
   LAYOUT
   ======================================== */
#app{display:flex;flex-direction:column;height:100vh}
#header{height:var(--header-h);background:var(--panel);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 16px;gap:16px;flex-shrink:0;z-index:50}
.header-left{display:flex;align-items:center;gap:12px;flex-shrink:0}
.header-left h1{font-size:16px;font-weight:700;white-space:nowrap;letter-spacing:-.3px}
#sidebar-toggle{background:none;border:none;color:var(--dim);cursor:pointer;padding:4px;border-radius:var(--r);display:flex;align-items:center;justify-content:center;transition:color .2s,background .2s}
#sidebar-toggle:hover{color:var(--text);background:var(--hover)}
#breadcrumb{flex:1;display:flex;align-items:center;gap:2px;overflow-x:auto;white-space:nowrap;font-size:13px;min-width:0}
.crumb{color:var(--dim);cursor:pointer;padding:3px 6px;border-radius:4px;transition:color .15s,background .15s;flex-shrink:0}
.crumb:hover{color:var(--text);background:var(--hover)}
.crumb.active{color:var(--text);font-weight:600}
.crumb-sep{color:var(--muted);font-size:11px;flex-shrink:0}
.header-right{display:flex;align-items:center;gap:12px;flex-shrink:0}
.user-badge{font-size:12px;color:var(--dim);background:var(--surface);padding:4px 10px;border-radius:20px;border:1px solid var(--border)}
.btn-logout{font-size:12px;color:var(--dim);text-decoration:none;padding:5px 12px;border-radius:var(--r);border:1px solid var(--border);transition:all .2s}
.btn-logout:hover{color:var(--danger);border-color:var(--danger);background:var(--danger-bg)}

#main{display:flex;flex:1;overflow:hidden}

/* Sidebar */
#sidebar{width:var(--sidebar-w);min-width:200px;max-width:500px;background:var(--panel);border-right:1px solid var(--border);display:flex;flex-direction:column;overflow:hidden;transition:margin-left .25s ease}
#sidebar.collapsed{margin-left:calc(var(--sidebar-w) * -1)}
.sidebar-head{padding:10px 14px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);border-bottom:1px solid var(--border)}
#tree-wrap{flex:1;overflow-y:auto;overflow-x:hidden;padding:6px 0}

/* Resize Handle */
#resize-handle{width:4px;cursor:col-resize;background:transparent;transition:background .2s;flex-shrink:0;position:relative;z-index:10}
#resize-handle:hover,#resize-handle.active{background:var(--accent)}

/* Content */
#content{flex:1;display:flex;flex-direction:column;overflow:hidden;position:relative;min-width:0}

/* Toolbar */
#toolbar{height:var(--toolbar-h);background:var(--panel);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 12px;gap:4px;flex-shrink:0}
.tb-group{display:flex;align-items:center;gap:2px}
.tb-sep{width:1px;height:22px;background:var(--border);margin:0 6px}
.tb-spacer{flex:1}
.tb{background:none;border:1px solid transparent;color:var(--dim);cursor:pointer;padding:5px 10px;border-radius:var(--r);font-family:'Outfit',sans-serif;font-size:12px;font-weight:500;display:flex;align-items:center;gap:5px;transition:all .15s;white-space:nowrap}
.tb:hover:not(:disabled){color:var(--text);background:var(--hover);border-color:var(--border)}
.tb:disabled{opacity:.35;cursor:default}
.tb.danger:hover:not(:disabled){color:var(--danger);background:var(--danger-bg);border-color:rgba(255,92,108,.25)}
.tb svg{width:14px;height:14px;flex-shrink:0}

/* Upload Progress */
#upload-bar{display:none;padding:0;height:3px;background:var(--surface);flex-shrink:0}
#upload-bar.show{display:block}
#upload-fill{height:100%;background:linear-gradient(90deg,var(--accent),#8b5cf6);width:0%;transition:width .3s;border-radius:0 2px 2px 0}

/* File List Header */
#list-head{display:grid;grid-template-columns:32px 28px 1fr 100px 150px 70px;align-items:center;height:32px;padding:0 12px 0 12px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);border-bottom:1px solid var(--border);background:var(--panel);flex-shrink:0;user-select:none}
.lh-sort{cursor:pointer;transition:color .15s}
.lh-sort:hover{color:var(--text)}
#list-head input[type=checkbox]{accent-color:var(--accent);cursor:pointer}

/* File List */
#file-list{flex:1;overflow-y:auto;padding:4px 0}
.fr{display:grid;grid-template-columns:32px 28px 1fr 100px 150px 70px;align-items:center;padding:0 12px;height:34px;cursor:pointer;transition:background .1s;animation:fadeRow .2s ease forwards;opacity:0}
.fr:nth-child(1){animation-delay:.02s}
.fr:nth-child(2){animation-delay:.04s}
.fr:nth-child(3){animation-delay:.06s}
.fr:nth-child(4){animation-delay:.08s}
.fr:nth-child(5){animation-delay:.1s}
.fr:nth-child(6){animation-delay:.12s}
.fr:nth-child(7){animation-delay:.14s}
.fr:nth-child(8){animation-delay:.16s}
.fr:nth-child(9){animation-delay:.18s}
.fr:nth-child(10){animation-delay:.2s}
@keyframes fadeRow{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:translateY(0)}}
.fr:hover{background:var(--hover)}
.fr.selected{background:var(--accent-bg)}
.fr input[type=checkbox]{accent-color:var(--accent);cursor:pointer}
.fr-icon{display:flex;align-items:center;justify-content:center}
.fr-name{font-size:13px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-family:'JetBrains Mono',monospace;font-size:12px}
.fr-name.is-dir{color:var(--warning)}
.fr-name.is-text{color:var(--accent)}
.fr-size,.fr-date,.fr-perm{font-size:12px;color:var(--dim);font-family:'JetBrains Mono',monospace}

/* Empty State */
.empty-state{display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;color:var(--muted);gap:8px;padding:40px}
.empty-state svg{opacity:.3}
.empty-state p{font-size:13px}

/* Loading */
.loading{display:flex;align-items:center;justify-content:center;height:100%;gap:10px;color:var(--dim)}
.spinner{width:20px;height:20px;border:2.5px solid var(--border);border-top-color:var(--accent);border-radius:50%;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}

/* Status Bar */
#status-bar{height:var(--status-h);background:var(--panel);border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 14px;font-size:11px;color:var(--muted);flex-shrink:0}

/* Drop Overlay */
#drop-overlay{position:absolute;inset:0;background:rgba(11,13,19,.88);z-index:80;display:flex;align-items:center;justify-content:center;border:3px dashed var(--accent);border-radius:var(--r-lg);margin:8px;opacity:0;pointer-events:none;transition:opacity .2s}
#drop-overlay.show{opacity:1;pointer-events:auto}
.drop-inner{text-align:center;color:var(--accent)}
.drop-inner svg{margin-bottom:12px;opacity:.7}
.drop-inner p{font-size:15px;font-weight:600}
.drop-inner span{font-size:12px;color:var(--dim)}

/* ========================================
   TREE
   ======================================== */
.tree-node{}
.tree-row{display:flex;align-items:center;gap:4px;padding:3px 12px;cursor:pointer;border-radius:0;transition:background .1s;user-select:none}
.tree-row:hover{background:var(--hover)}
.tree-row.active{background:var(--accent-bg)}
.tree-row.active .tree-name{color:var(--accent);font-weight:600}
.tree-toggle{width:18px;height:18px;display:flex;align-items:center;justify-content:center;flex-shrink:0;color:var(--muted);transition:transform .15s}
.tree-toggle svg{width:9px;height:9px}
.tree-node.expanded>.tree-row .tree-toggle{transform:rotate(90deg)}
.tree-toggle.no-children{visibility:hidden}
.tree-icon{width:16px;height:16px;flex-shrink:0;display:flex;align-items:center}
.tree-name{font-size:12.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--text)}
.tree-children{display:none;padding-left:0}
.tree-node.expanded>.tree-children{display:block}

/* ========================================
   MODALS
   ======================================== */
.modal{position:fixed;inset:0;z-index:200;display:flex;align-items:center;justify-content:center}
.modal.hidden{display:none}
.modal-bg{position:absolute;inset:0;background:rgba(0,0,0,.55);backdrop-filter:blur(3px)}
.modal-box{position:relative;background:var(--panel);border:1px solid var(--border-l);border-radius:var(--r-lg);box-shadow:0 24px 64px rgba(0,0,0,.5);display:flex;flex-direction:column;animation:mIn .2s ease}
@keyframes mIn{from{opacity:0;transform:translateY(16px) scale(.97)}to{opacity:1;transform:translateY(0) scale(1)}}
.modal-lg{width:92vw;height:88vh;max-width:1500px}
.modal-sm{width:420px;max-width:92vw}
.modal-hd{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid var(--border);flex-shrink:0}
.modal-hd h3{font-size:14px;font-weight:600}
.modal-close{background:none;border:none;color:var(--dim);font-size:20px;cursor:pointer;padding:2px 6px;border-radius:var(--r);transition:all .15s;line-height:1}
.modal-close:hover{color:var(--text);background:var(--hover)}
.modal-ft{display:flex;justify-content:flex-end;gap:8px;padding:12px 20px;border-top:1px solid var(--border);flex-shrink:0}
.modal-bd{flex:1;overflow:hidden;display:flex;flex-direction:column}
.modal-bd p{font-size:13px;color:var(--dim);line-height:1.6}
.modal-bd input[type=text]{width:100%;padding:10px 12px;background:var(--surface);border:1px solid var(--border);border-radius:var(--r);color:var(--text);font-family:'JetBrains Mono',monospace;font-size:13px;outline:0;margin-top:12px;transition:border .2s}
.modal-bd input[type=text]:focus{border-color:var(--accent)}
#editor-wrap{flex:1;position:relative}
#ace-editor{position:absolute;inset:0}

/* Buttons */
.btn{padding:7px 18px;border-radius:var(--r);font-family:'Outfit',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;transition:all .15s}
.btn-primary{background:var(--accent);color:#fff;border-color:var(--accent)}
.btn-primary:hover{background:var(--accent-h)}
.btn-ghost{background:transparent;color:var(--dim);border-color:var(--border)}
.btn-ghost:hover{color:var(--text);background:var(--hover)}

/* ========================================
   TOAST
   ======================================== */
#toasts{position:fixed;bottom:16px;right:16px;z-index:300;display:flex;flex-direction:column-reverse;gap:8px;pointer-events:none}
.toast{pointer-events:auto;padding:11px 16px;border-radius:var(--r);font-size:13px;font-weight:500;animation:tIn .3s ease;display:flex;align-items:center;gap:8px;min-width:260px;max-width:420px;box-shadow:0 8px 28px rgba(0,0,0,.35)}
@keyframes tIn{from{opacity:0;transform:translateX(32px)}to{opacity:1;transform:translateX(0)}}
.toast.t-ok{background:var(--success-bg);border:1px solid rgba(74,222,128,.3);color:var(--success)}
.toast.t-err{background:var(--danger-bg);border:1px solid rgba(255,92,108,.3);color:var(--danger)}
.toast.t-info{background:var(--accent-bg);border:1px solid rgba(94,158,255,.3);color:var(--accent)}

/* Responsive */
@media(max-width:800px){
  #sidebar{position:fixed;left:0;top:var(--header-h);bottom:0;z-index:60}
  #sidebar.collapsed{margin-left:calc(var(--sidebar-w) * -1)}
  .fr-date,.fr-perm,#list-head .fr-date,#list-head .fr-perm{display:none}
  #list-head,.fr{grid-template-columns:32px 28px 1fr 90px}
}
</style>
</head>
<body>
<div id="app">

  <!-- HEADER -->
  <header id="header">
    <div class="header-left">
      <button id="sidebar-toggle" onclick="App.toggleSidebar()" title="Toggle sidebar">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 12h18M3 6h18M3 18h18"/></svg>
      </button>
      <h1>File Manager</h1>
    </div>
    <nav id="breadcrumb"></nav>
    <div class="header-right">
      <span class="user-badge"><?= htmlspecialchars($username) ?></span>
      <a href="logout.php" class="btn-logout">Logout</a>
    </div>
  </header>

  <!-- MAIN -->
  <div id="main">
    <!-- SIDEBAR -->
    <aside id="sidebar">
      <div class="sidebar-head">Esplora</div>
      <div id="tree-wrap"></div>
    </aside>

    <div id="resize-handle"></div>

    <!-- CONTENT -->
    <section id="content">
      <!-- Toolbar -->
      <div id="toolbar">
        <div class="tb-group">
          <button class="tb" onclick="App.triggerUpload()" title="Carica file">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            Carica
          </button>
          <button class="tb" onclick="App.newFolder()" title="Nuova cartella">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
            Cartella
          </button>
          <button class="tb" onclick="App.newFile()" title="Nuovo file">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
            File
          </button>
        </div>
        <div class="tb-sep"></div>
        <div class="tb-group">
          <button class="tb" id="btn-dl" disabled onclick="App.downloadSelected()" title="Download">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Download
          </button>
          <button class="tb" id="btn-rn" disabled onclick="App.renameSelected()" title="Rinomina">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M17 3a2.83 2.83 0 114 4L7.5 20.5 2 22l1.5-5.5z"/></svg>
            Rinomina
          </button>
          <button class="tb danger" id="btn-del" disabled onclick="App.deleteSelected()" title="Elimina">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg>
            Elimina
          </button>
        </div>
        <div class="tb-sep"></div>
        <div class="tb-group">
          <button class="tb" id="btn-zip" disabled onclick="App.createZip()" title="Crea ZIP">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 8v13H3V3h12"/><path d="M15 3v6h6"/><rect x="9" y="13" width="6" height="5" rx="1"/></svg>
            Crea ZIP
          </button>
        </div>
        <div class="tb-spacer"></div>
        <div class="tb-group">
          <button class="tb" onclick="App.refresh()" title="Aggiorna">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/></svg>
          </button>
        </div>
      </div>

      <!-- Upload progress -->
      <div id="upload-bar"><div id="upload-fill"></div></div>

      <!-- List Header -->
      <div id="list-head">
        <div><input type="checkbox" id="sel-all" onchange="App.toggleAll()"></div>
        <div></div>
        <div class="lh-sort" onclick="App.sort('name')">Nome</div>
        <div class="lh-sort" onclick="App.sort('size')">Dim.</div>
        <div class="lh-sort" onclick="App.sort('modified')">Data</div>
        <div>Perms</div>
      </div>

      <!-- File List -->
      <div id="file-list">
        <div class="loading"><div class="spinner"></div>Caricamento...</div>
      </div>

      <!-- Drop Overlay -->
      <div id="drop-overlay">
        <div class="drop-inner">
          <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
          <p>Rilascia i file qui</p>
          <span>Verranno caricati nella cartella corrente</span>
        </div>
      </div>

      <!-- Status Bar -->
      <div id="status-bar">
        <span id="st-count">0 elementi</span>
        <span id="st-sel"></span>
      </div>
    </section>
  </div>
</div>

<!-- Hidden file input -->
<input type="file" id="file-input" multiple style="display:none">

<!-- EDITOR MODAL -->
<div id="editor-modal" class="modal hidden">
  <div class="modal-bg" onclick="App.closeEditor()"></div>
  <div class="modal-box modal-lg">
    <div class="modal-hd">
      <h3 id="ed-title">Editor</h3>
      <button class="modal-close" onclick="App.closeEditor()">&times;</button>
    </div>
    <div class="modal-bd">
      <div id="editor-wrap"><div id="ace-editor"></div></div>
    </div>
    <div class="modal-ft">
      <button class="btn btn-ghost" onclick="App.closeEditor()">Chiudi</button>
      <button class="btn btn-primary" onclick="App.saveEditor()">Salva (Ctrl+S)</button>
    </div>
  </div>
</div>

<!-- DIALOG MODAL -->
<div id="dlg-modal" class="modal hidden">
  <div class="modal-bg" onclick="App.dlgCancel()"></div>
  <div class="modal-box modal-sm">
    <div class="modal-hd">
      <h3 id="dlg-title">Conferma</h3>
      <button class="modal-close" onclick="App.dlgCancel()">&times;</button>
    </div>
    <div class="modal-bd" style="padding:20px">
      <p id="dlg-msg"></p>
      <input type="text" id="dlg-input" style="display:none" autocomplete="off">
    </div>
    <div class="modal-ft">
      <button class="btn btn-ghost" onclick="App.dlgCancel()">Annulla</button>
      <button class="btn btn-primary" id="dlg-ok" onclick="App.dlgConfirm()">OK</button>
    </div>
  </div>
</div>

<!-- TOASTS -->
<div id="toasts"></div>

<!-- ACE EDITOR CDN -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.32.6/ace.js"></script>

<script>
/* ==========================================================
   APPLICATION
   ========================================================== */
const App = {
    rootFolder: '<?= addslashes($rootFolder) ?>',
    currentPath: '',
    files: [],
    selected: new Set(),
    sortField: 'name',
    sortAsc: true,
    textExts: [],
    aceEditor: null,
    acePath: null,
    dlgCb: null,
    dlgInput: false,
    sidebarOpen: true,

    /* --------------------------------------------------
       ICONS (inline SVG)
       -------------------------------------------------- */
    I: {
        folder: '<svg width="16" height="16" viewBox="0 0 24 24"><path d="M2 6c0-1.1.9-2 2-2h5l2 2h9a2 2 0 012 2v10a2 2 0 01-2 2H4a2 2 0 01-2-2V6z" fill="#fbbf24"/></svg>',
        fileT: '<svg width="16" height="16" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6z" fill="#5e9eff"/><path d="M14 2v6h6" fill="#4183d4"/><path d="M8 13h8M8 17h5" stroke="#fff" stroke-width="1.5" stroke-linecap="round"/></svg>',
        file:  '<svg width="16" height="16" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6z" fill="#3a4058"/><path d="M14 2v6h6" fill="#2d3348"/></svg>',
        zip:   '<svg width="16" height="16" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6z" fill="#f97316"/><path d="M14 2v6h6" fill="#d95f0c"/></svg>',
        img:   '<svg width="16" height="16" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6z" fill="#a855f7"/><path d="M14 2v6h6" fill="#8b3de8"/><circle cx="10" cy="14" r="2" fill="#fff" opacity=".7"/><path d="M6 20l4-5 3 3 3-4 4 6H6z" fill="#fff" opacity=".5"/></svg>'
    },

    /* --------------------------------------------------
       INIT
       -------------------------------------------------- */
    async init() {
        const cfg = await App.api({action:'getConfig'});
        if (cfg && cfg.textExtensions) App.textExts = cfg.textExtensions;
        App.currentPath = App.rootFolder;
        App.initTree();
        App.initDragDrop();
        App.initResize();
        App.initKeys();
        App.loadDir(App.currentPath);
        document.getElementById('file-input').addEventListener('change', function(){
            if (this.files.length) App.upload(this.files);
            this.value = '';
        });
    },

    /* --------------------------------------------------
       API HELPERS
       -------------------------------------------------- */
    async api(params) {
        try {
            const qs = new URLSearchParams(params).toString();
            const r = await fetch('api.php?' + qs);
            if (r.status === 401) { window.location='index.php'; return null; }
            const d = await r.json();
            if (d.error) { App.toast(d.error,'err'); return null; }
            return d;
        } catch(e) { App.toast('Errore di rete','err'); return null; }
    },

    async apiPost(params) {
        try {
            const fd = new FormData();
            for (const k in params) fd.append(k, params[k]);
            const r = await fetch('api.php', {method:'POST',body:fd});
            if (r.status === 401) { window.location='index.php'; return null; }
            const d = await r.json();
            if (d.error) { App.toast(d.error,'err'); return null; }
            return d;
        } catch(e) { App.toast('Errore di rete','err'); return null; }
    },

    /* --------------------------------------------------
       TREE
       -------------------------------------------------- */
    async initTree() {
        const wrap = document.getElementById('tree-wrap');
        const root = App.mkNode({name: App.base(App.rootFolder), path: App.rootFolder, hasChildren: true}, 0);
        root.classList.add('expanded');
        wrap.appendChild(root);
        await App.expandNode(root);
        App.highlightTree(App.rootFolder);
    },

    mkNode(item, level) {
        const node = document.createElement('div');
        node.className = 'tree-node';
        node.dataset.path = item.path;

        const row = document.createElement('div');
        row.className = 'tree-row';
        row.style.paddingLeft = (8 + level * 16) + 'px';

        const tog = document.createElement('span');
        tog.className = 'tree-toggle' + (item.hasChildren ? '' : ' no-children');
        tog.innerHTML = '<svg viewBox="0 0 10 10"><path d="M3 1l4 4-4 4" fill="currentColor"/></svg>';
        tog.addEventListener('click', e => { e.stopPropagation(); App.toggleNode(node); });

        const ico = document.createElement('span');
        ico.className = 'tree-icon';
        ico.innerHTML = App.I.folder;

        const nm = document.createElement('span');
        nm.className = 'tree-name';
        nm.textContent = item.name;

        row.appendChild(tog);
        row.appendChild(ico);
        row.appendChild(nm);
        row.addEventListener('click', () => App.selectTree(node));
        node.appendChild(row);

        const ch = document.createElement('div');
        ch.className = 'tree-children';
        node.appendChild(ch);
        return node;
    },

    async expandNode(node) {
        const ch = node.querySelector(':scope > .tree-children');
        if (ch.dataset.loaded === '1') { node.classList.add('expanded'); return; }
        const d = await App.api({action:'tree', path:node.dataset.path});
        if (!d) return;
        const level = App.treeLevel(node) + 1;
        d.items.forEach(it => ch.appendChild(App.mkNode(it, level)));
        ch.dataset.loaded = '1';
        node.classList.add('expanded');
    },

    async toggleNode(node) {
        if (node.classList.contains('expanded')) {
            node.classList.remove('expanded');
        } else {
            await App.expandNode(node);
        }
    },

    treeLevel(node) {
        let n = 0, el = node;
        while (el.parentElement && el.parentElement.id !== 'tree-wrap') {
            if (el.parentElement.classList.contains('tree-children')) n++;
            el = el.parentElement;
        }
        return n;
    },

    selectTree(node) {
        App.highlightTree(node.dataset.path);
        App.currentPath = node.dataset.path;
        App.loadDir(node.dataset.path);
    },

    highlightTree(path) {
        document.querySelectorAll('.tree-row.active').forEach(r => r.classList.remove('active'));
        const n = document.querySelector(`.tree-node[data-path="${CSS.escape(path)}"] > .tree-row`);
        if (n) n.classList.add('active');
    },

    async syncTree(path) {
        const segs = path.replace(App.rootFolder,'').split('/').filter(Boolean);
        let cur = App.rootFolder;
        let node = document.querySelector(`.tree-node[data-path="${CSS.escape(cur)}"]`);
        for (const s of segs) {
            cur += '/' + s;
            if (node && !node.classList.contains('expanded')) await App.expandNode(node);
            const ch = node ? node.querySelector(':scope > .tree-children') : null;
            node = ch ? ch.querySelector(`.tree-node[data-path="${CSS.escape(cur)}"]`) : null;
        }
        if (node) App.highlightTree(path);
    },

    /* --------------------------------------------------
       FILE BROWSER
       -------------------------------------------------- */
    async loadDir(path) {
        App.currentPath = path;
        App.selected.clear();
        App.updateToolbar();
        document.getElementById('sel-all').checked = false;
        document.getElementById('file-list').innerHTML = '<div class="loading"><div class="spinner"></div>Caricamento...</div>';
        App.updateBreadcrumb();
        App.syncTree(path);

        const d = await App.api({action:'list', path});
        if (!d) { document.getElementById('file-list').innerHTML = ''; return; }
        App.files = d.items;
        App.renderList();
    },

    renderList() {
        const list = document.getElementById('file-list');
        let items = [...App.files];

        // Sort
        items.sort((a,b) => {
            if (a.isDir !== b.isDir) return a.isDir ? -1 : 1;
            let c;
            switch(App.sortField) {
                case 'size':     c = ((a.size||0)-(b.size||0)); break;
                case 'modified': c = ((a.modified||0)-(b.modified||0)); break;
                default:         c = a.name.localeCompare(b.name);
            }
            return App.sortAsc ? c : -c;
        });

        if (!items.length) {
            list.innerHTML = '<div class="empty-state"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg><p>Cartella vuota</p></div>';
            App.updateStatus();
            return;
        }

        let html = '';
        items.forEach((f,i) => {
            const sel = App.selected.has(f.path) ? ' selected' : '';
            const icon = f.isDir ? App.I.folder : (f.isText ? App.I.fileT : (['zip','rar','7z','tar','gz','bz2'].includes(f.extension) ? App.I.zip : (['jpg','jpeg','png','gif','bmp','svg','webp','ico','avif'].includes(f.extension) ? App.I.img : App.I.file)));
            const nmClass = f.isDir ? 'is-dir' : (f.isText ? 'is-text' : '');
            html += `<div class="fr${sel}" data-idx="${i}" data-path="${App.esc(f.path)}" onclick="App.toggleSel(event,'${App.esc(f.path)}')" ondblclick="App.dblClick(${i})">
                <div><input type="checkbox" ${App.selected.has(f.path)?'checked':''} onclick="event.stopPropagation();App.toggleSel(event,'${App.esc(f.path)}')"></div>
                <div class="fr-icon">${icon}</div>
                <div class="fr-name ${nmClass}" title="${App.esc(f.name)}">${App.esc(f.name)}</div>
                <div class="fr-size">${f.isDir ? '&mdash;' : App.fmtSize(f.size)}</div>
                <div class="fr-date">${f.modified ? App.fmtDate(f.modified) : ''}</div>
                <div class="fr-perm">${f.permissions||''}</div>
            </div>`;
        });
        list.innerHTML = html;
        App.updateStatus();
    },

    dblClick(idx) {
        const f = App.files[idx];
        if (!f) return;
        if (f.isDir) {
            App.loadDir(f.path);
        } else if (f.isText) {
            App.openEditor(f.path);
        } else {
            App.dlFile(f.path);
        }
    },

    toggleSel(e, path) {
        if (App.selected.has(path)) App.selected.delete(path);
        else App.selected.add(path);
        const row = document.querySelector(`.fr[data-path="${CSS.escape(path)}"]`);
        if (row) {
            row.classList.toggle('selected', App.selected.has(path));
            const cb = row.querySelector('input[type=checkbox]');
            if (cb) cb.checked = App.selected.has(path);
        }
        document.getElementById('sel-all').checked = App.selected.size === App.files.length && App.files.length > 0;
        App.updateToolbar();
        App.updateStatus();
    },

    toggleAll() {
        const all = document.getElementById('sel-all').checked;
        App.selected.clear();
        if (all) App.files.forEach(f => App.selected.add(f.path));
        document.querySelectorAll('.fr').forEach(r => {
            const p = r.dataset.path;
            r.classList.toggle('selected', App.selected.has(p));
            const cb = r.querySelector('input[type=checkbox]');
            if (cb) cb.checked = App.selected.has(p);
        });
        App.updateToolbar();
        App.updateStatus();
    },

    updateToolbar() {
        const n = App.selected.size;
        document.getElementById('btn-dl').disabled = n === 0;
        document.getElementById('btn-rn').disabled = n !== 1;
        document.getElementById('btn-del').disabled = n === 0;
        document.getElementById('btn-zip').disabled = n < 1;
    },

    updateStatus() {
        document.getElementById('st-count').textContent = App.files.length + ' elementi';
        const n = App.selected.size;
        document.getElementById('st-sel').textContent = n ? n + ' selezionati' : '';
    },

    updateBreadcrumb() {
        const bc = document.getElementById('breadcrumb');
        const rel = App.currentPath.replace(App.rootFolder, '');
        const parts = rel.split('/').filter(Boolean);
        let html = `<span class="crumb${parts.length?'':' active'}" onclick="App.loadDir('${App.esc(App.rootFolder)}')">${App.esc(App.base(App.rootFolder))}</span>`;
        let bp = App.rootFolder;
        parts.forEach((p,i) => {
            bp += '/' + p;
            const isLast = i === parts.length - 1;
            html += `<span class="crumb-sep">/</span><span class="crumb${isLast?' active'}" onclick="App.loadDir('${App.esc(bp)}')">${App.esc(p)}</span>`;
        });
        bc.innerHTML = html;
    },

    sort(field) {
        if (App.sortField === field) App.sortAsc = !App.sortAsc;
        else { App.sortField = field; App.sortAsc = true; }
        App.renderList();
    },

    refresh() { App.loadDir(App.currentPath); },

    /* --------------------------------------------------
       UPLOAD & DRAG/DROP
       -------------------------------------------------- */
    initDragDrop() {
        const ct = document.getElementById('content');
        const ov = document.getElementById('drop-overlay');
        let cnt = 0;
        ct.addEventListener('dragenter', e => { e.preventDefault(); cnt++; ov.classList.add('show'); });
        ct.addEventListener('dragleave', e => { e.preventDefault(); cnt--; if(cnt<=0){cnt=0;ov.classList.remove('show');} });
        ct.addEventListener('dragover', e => e.preventDefault());
        ct.addEventListener('drop', e => {
            e.preventDefault(); cnt=0; ov.classList.remove('show');
            if (e.dataTransfer.files.length) App.upload(e.dataTransfer.files);
        });
    },

    triggerUpload() { document.getElementById('file-input').click(); },

    async upload(fileList) {
        const bar = document.getElementById('upload-bar');
        const fill = document.getElementById('upload-fill');
        bar.classList.add('show');
        fill.style.width = '0%';

        const fd = new FormData();
        fd.append('action', 'upload');
        fd.append('path', App.currentPath);
        for (let i=0; i<fileList.length; i++) fd.append('files[]', fileList[i]);

        const xhr = new XMLHttpRequest();
        xhr.upload.addEventListener('progress', e => {
            if (e.lengthComputable) fill.style.width = Math.round(e.loaded/e.total*100)+'%';
        });
        xhr.addEventListener('load', () => {
            bar.classList.remove('show');
            if (xhr.status === 200) {
                try {
                    const d = JSON.parse(xhr.responseText);
                    if (d.error) App.toast(d.error,'err');
                    else {
                        const ok = d.results.filter(r=>r.status==='ok').length;
                        App.toast(ok + ' file caricati','ok');
                        App.refresh();
                    }
                } catch(e) { App.toast('Errore upload','err'); }
            } else App.toast('Errore upload ('+xhr.status+')','err');
        });
        xhr.addEventListener('error', () => { bar.classList.remove('show'); App.toast('Errore di rete','err'); });
        xhr.open('POST','api.php');
        xhr.send(fd);
    },

    /* --------------------------------------------------
       FILE OPERATIONS
       -------------------------------------------------- */
    dlFile(path) {
        const a = document.createElement('a');
        a.href = 'api.php?action=download&path='+encodeURIComponent(path);
        a.download = '';
        document.body.appendChild(a);
        a.click();
        a.remove();
    },

    async downloadSelected() {
        if (App.selected.size === 0) return;
        if (App.selected.size === 1) {
            App.dlFile([...App.selected][0]);
        } else {
            // Create zip then download
            App.toast('Creazione ZIP in corso...','info');
            const d = await App.apiPost({action:'zip', items:JSON.stringify([...App.selected]), basePath:App.currentPath});
            if (d && d.status==='ok') {
                const a = document.createElement('a');
                a.href = 'api.php?action=downloadZip&path='+encodeURIComponent(d.zipPath);
                a.download = d.zipName;
                document.body.appendChild(a);
                a.click();
                a.remove();
                App.toast('ZIP scaricato','ok');
                setTimeout(() => App.refresh(), 500);
            }
        }
    },

    async deleteSelected() {
        if (!App.selected.size) return;
        const names = [...App.selected].map(p => App.base(p)).join(', ');
        App.confirm(`Eliminare ${App.selected.size} elemento/i?<br><br><strong>${App.esc(names)}</strong>`, async () => {
            let ok = 0;
            for (const p of App.selected) {
                const d = await App.apiPost({action:'delete', path:p});
                if (d && d.status==='ok') ok++;
            }
            App.toast(ok + ' eliminati','ok');
            App.refresh();
            // Refresh tree
            document.getElementById('tree-wrap').innerHTML = '';
            App.initTree();
        });
    },

    newFolder() {
        App.input('Nome nuova cartella:', '', async (name) => {
            if (!name) return;
            const d = await App.apiPost({action:'mkdir', path:App.currentPath, name});
            if (d && d.status==='ok') { App.toast('Cartella creata','ok'); App.refresh(); }
        });
    },

    newFile() {
        App.input('Nome nuovo file:', '', async (name) => {
            if (!name) return;
            const d = await App.apiPost({action:'createFile', path:App.currentPath, name});
            if (d && d.status==='ok') { App.toast('File creato','ok'); App.refresh(); }
        });
    },

    renameSelected() {
        if (App.selected.size !== 1) return;
        const old = [...App.selected][0];
        App.input('Nuovo nome:', App.base(old), async (name) => {
            if (!name || name === App.base(old)) return;
            const d = await App.apiPost({action:'rename', oldPath:old, newName:name});
            if (d && d.status==='ok') { App.toast('Rinominato','ok'); App.refresh(); }
        });
    },

    async createZip() {
        if (!App.selected.size) return;
        App.toast('Creazione ZIP...','info');
        const d = await App.apiPost({action:'zip', items:JSON.stringify([...App.selected]), basePath:App.currentPath});
        if (d && d.status==='ok') { App.toast('ZIP creato: '+d.zipName,'ok'); App.refresh(); }
    },

    /* --------------------------------------------------
       EDITOR (ACE)
       -------------------------------------------------- */
    async openEditor(path) {
        const d = await App.api({action:'read', path});
        if (!d) return;
        document.getElementById('editor-modal').classList.remove('hidden');
        document.getElementById('ed-title').textContent = d.name;
        App.acePath = path;

        if (!App.aceEditor) {
            App.aceEditor = ace.edit('ace-editor');
            App.aceEditor.setTheme('ace/theme/monokai');
            App.aceEditor.setOptions({
                fontSize: '14px',
                showPrintMargin: false,
                tabSize: 4,
                useSoftTabs: true,
                wrap: false,
                enableBasicAutocompletion: false
            });
            App.aceEditor.commands.addCommand({
                name: 'save',
                bindKey: {win:'Ctrl-S', mac:'Command-S'},
                exec: () => App.saveEditor()
            });
        }
        const mode = App.aceMode(d.extension);
        App.aceEditor.session.setMode('ace/mode/' + mode);
        App.aceEditor.setValue(d.content, -1);
        setTimeout(() => App.aceEditor.resize(), 50);
        App.aceEditor.focus();
    },

    async saveEditor() {
        if (!App.acePath) return;
        const content = App.aceEditor.getValue();
        const d = await App.apiPost({action:'save', path:App.acePath, content});
        if (d && d.status==='ok') App.toast('File salvato','ok');
    },

    closeEditor() {
        document.getElementById('editor-modal').classList.add('hidden');
        App.acePath = null;
    },

    aceMode(ext) {
        const m = {
            js:'javascript',ts:'typescript',jsx:'jsx',tsx:'tsx',php:'php',
            py:'python',rb:'ruby',java:'java',c:'c_cpp',cpp:'c_cpp',h:'c_cpp',hpp:'c_cpp',
            css:'css',scss:'scss',less:'less',sass:'scss',
            html:'html',htm:'html',xml:'xml',svg:'xml',
            json:'json',md:'markdown',yml:'yaml',yaml:'yaml',
            sql:'sql',sh:'sh',bash:'sh',zsh:'sh',fish:'sh',
            ini:'ini',cfg:'ini',conf:'ini',toml:'toml',
            env:'sh',htaccess:'apache_conf',gitignore:'text',
            csv:'text',log:'text',txt:'text',lock:'text',
            go:'golang',rs:'rust',swift:'swift',kt:'kotlin',scala:'scala',
            vue:'html',svelte:'html',makefile:'makefile',cmake:'text',
            r:'r',m:'objectivec',mm:'objectivec',pl:'perl',lua:'lua',
            ex:'elixir',exs:'elixir',erl:'erlang',hs:'haskell',clj:'clojure',cljs:'clojure'
        };
        return m[ext] || 'text';
    },

    /* --------------------------------------------------
       DIALOG (confirm / input)
       -------------------------------------------------- */
    confirm(msg, cb) {
        document.getElementById('dlg-title').textContent = 'Conferma';
        document.getElementById('dlg-msg').innerHTML = msg;
        document.getElementById('dlg-input').style.display = 'none';
        document.getElementById('dlg-modal').classList.remove('hidden');
        App.dlgCb = cb;
        App.dlgInput = false;
    },

    input(label, def, cb) {
        document.getElementById('dlg-title').textContent = label;
        document.getElementById('dlg-msg').textContent = '';
        const inp = document.getElementById('dlg-input');
        inp.style.display = 'block';
        inp.value = def || '';
        document.getElementById('dlg-modal').classList.remove('hidden');
        setTimeout(() => inp.focus(), 50);
        App.dlgCb = cb;
        App.dlgInput = true;
    },

    dlgConfirm() {
        const val = App.dlgInput ? document.getElementById('dlg-input').value : true;
        document.getElementById('dlg-modal').classList.add('hidden');
        if (App.dlgCb) App.dlgCb(val);
        App.dlgCb = null;
    },

    dlgCancel() {
        document.getElementById('dlg-modal').classList.add('hidden');
        App.dlgCb = null;
    },

    /* --------------------------------------------------
       TOAST
       -------------------------------------------------- */
    toast(msg, type='info') {
        const c = document.getElementById('toasts');
        const t = document.createElement('div');
        t.className = 'toast t-' + type;
        t.textContent = msg;
        c.appendChild(t);
        setTimeout(() => { t.style.opacity='0'; t.style.transition='opacity .3s'; setTimeout(()=>t.remove(),300); }, 3500);
    },

    /* --------------------------------------------------
       RESIZE HANDLE
       -------------------------------------------------- */
    initResize() {
        const h = document.getElementById('resize-handle');
        const sb = document.getElementById('sidebar');
        let sx, sw;
        h.addEventListener('mousedown', e => {
            e.preventDefault();
            sx = e.clientX; sw = sb.offsetWidth;
            h.classList.add('active');
            document.body.style.cursor = 'col-resize';
            document.body.style.userSelect = 'none';
            const onM = ev => { sb.style.width = Math.max(200, Math.min(500, sw + ev.clientX - sx)) + 'px'; };
            const onU = () => {
                document.removeEventListener('mousemove', onM);
                document.removeEventListener('mouseup', onU);
                h.classList.remove('active');
                document.body.style.cursor = '';
                document.body.style.userSelect = '';
            };
            document.addEventListener('mousemove', onM);
            document.addEventListener('mouseup', onU);
        });
    },

    /* --------------------------------------------------
       KEYBOARD
       -------------------------------------------------- */
    initKeys() {
        document.addEventListener('keydown', e => {
            // Close modals on Escape
            if (e.key === 'Escape') {
                if (!document.getElementById('editor-modal').classList.contains('hidden')) App.closeEditor();
                else if (!document.getElementById('dlg-modal').classList.contains('hidden')) App.dlgCancel();
            }
            // Delete selected
            if (e.key === 'Delete' && App.selected.size > 0 && document.getElementById('editor-modal').classList.contains('hidden') && document.getElementById('dlg-modal').classList.contains('hidden')) {
                App.deleteSelected();
            }
            // F2 rename
            if (e.key === 'F2' && App.selected.size === 1) {
                e.preventDefault();
                App.renameSelected();
            }
        });
    },

    /* --------------------------------------------------
       SIDEBAR TOGGLE
       -------------------------------------------------- */
    toggleSidebar() {
        const sb = document.getElementById('sidebar');
        App.sidebarOpen = !App.sidebarOpen;
        sb.classList.toggle('collapsed', !App.sidebarOpen);
    },

    /* --------------------------------------------------
       UTILITIES
       -------------------------------------------------- */
    esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); },
    base(p) { return p.split('/').filter(Boolean).pop() || p; },
    fmtSize(b) {
        if (b == null || b === 0) return '0 B';
        const u = ['B','KB','MB','GB','TB'];
        const i = Math.floor(Math.log(b)/Math.log(1024));
        return (b/Math.pow(1024,i)).toFixed(i?1:0) + ' ' + u[i];
    },
    fmtDate(ts) {
        const d = new Date(ts * 1000);
        const pad = n => String(n).padStart(2,'0');
        return `${pad(d.getDate())}/${pad(d.getMonth()+1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
    }
};

/* ==========================================================
   BOOTSTRAP
   ========================================================== */
document.addEventListener('DOMContentLoaded', () => App.init());
</script>
</body>
</html>

