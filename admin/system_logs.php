<?php
// admin/system_logs.php
// Standalone "System Logs" feature. Previously this table lived inline on
// the dashboard (admin_dashboard.php) as the "System Logs" box; it has been
// moved out into its own nav item/page so the dashboard stays focused on
// stats, and logs get a dedicated, full-height view. Data source is
// unchanged — it still reads the same `feed_full` array from
// dashboard_counts.php, polled every 10 seconds, so behavior/functionality
// is identical to before, just relocated.
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/', 'domain' => '',
    'secure' => false, 'httponly' => true, 'samesite' => 'Lax',
]);
session_start();
require_once 'db.php';

// Guard — same roles allowed to see the dashboard (and therefore the old
// inline System Logs box) can see this page.
if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin','superadmin','registrar'])) {
    header('Location: admin_login.php'); exit;
}
if ($mysqli->ping()) $mysqli->close();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>System Logs — PBI</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{
  --page-bg:#F8FAFC;--card-bg:#FFFFFF;--inner:#F4F8FF;--card-border:#B9CDE5;
  --text-dark:#0B1F3A;--text-dim:#67819E;
  --radius:10px;--card-shadow:0 1px 2px rgba(30,82,144,.05),0 4px 12px rgba(30,82,144,.06);
  --accent:#2563EB;--accent-bg:rgba(37,99,235,.08);--accent-border:rgba(37,99,235,.16);--hover:#2563EB;
}
*{box-sizing:border-box} body{margin:0;background:var(--page-bg);color:var(--text-dark);font-family:'Inter',Segoe UI,Arial,sans-serif}
.wrap{max-width:1320px;margin:auto;padding:34px}
.back-link{display:inline-flex;align-items:center;gap:8px;color:var(--text-dim);text-decoration:none;font-size:13px;margin-bottom:20px}
.back-link:hover{color:var(--text-dark)}
.page-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:22px;flex-wrap:wrap;gap:14px;background:var(--card-bg);border:1px solid var(--card-border);border-radius:14px;padding:22px 26px;box-shadow:var(--card-shadow)}
.page-header h1{margin:0;font-family:'Rajdhani',sans-serif;font-size:28px;font-weight:700;color:var(--text-dark)}
.page-header p{color:var(--text-dim);margin:6px 0 0;font-size:13px}
.refresh-badge{background:var(--accent-bg);border:1px solid var(--accent-border);color:var(--accent);padding:8px 16px;border-radius:20px;font-size:12.5px;font-weight:700;display:flex;align-items:center;gap:8px;white-space:nowrap}

.logs-table-wrap{background:var(--card-bg);border:1px solid var(--card-border);border-radius:14px;overflow-x:auto;box-shadow:var(--card-shadow)}
.logs-table{width:100%;min-width:760px;border-collapse:collapse;font-size:13px}
.logs-table thead th{padding:13px 18px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text-dim);text-align:left;white-space:nowrap;border-bottom:1px solid var(--card-border);background:var(--inner)}
.logs-table tbody td{padding:14px 18px;vertical-align:top;border-bottom:1px solid var(--card-border);color:var(--text-dark)}
.logs-table tbody tr:last-child td{border-bottom:none}
.logs-table tbody tr:hover td{background:var(--page-bg)}
.log-datetime{color:var(--text-dim);white-space:nowrap}
.log-action{display:inline-flex;align-items:flex-start;gap:6px;font-weight:600;white-space:normal;word-break:break-word}
.log-action-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0;margin-top:6px}
.log-user{font-weight:600;white-space:nowrap}
.log-details{color:var(--text-dim);word-break:break-word}
.logs-more{display:block;margin:16px auto;padding:9px 20px;border:1px solid var(--accent-border);border-radius:20px;background:var(--accent-bg);color:var(--accent);font-weight:700;font-size:13px;cursor:pointer}
.logs-more:disabled{opacity:.6;cursor:default}
.logs-empty{padding:40px 20px;text-align:center;color:var(--text-dim);font-size:13.5px}
.logs-error{color:#b91c1c}
.logs-retry{margin-left:8px;padding:5px 10px;border:1px solid currentColor;border-radius:6px;background:transparent;color:inherit;font-weight:600;cursor:pointer}
@media(max-width:900px){.wrap{padding:20px}.logs-table{font-size:11.5px}}
@media(max-width:600px){.logs-table thead th:nth-child(4),.logs-table tbody td:nth-child(4){display:none}}
</style>
<link rel="stylesheet" href="admin_ui_theme.css">
    <link rel="stylesheet" href="admin_compact_ui.css">
<style id="pbi-feature-scrollbar">

/* PBI FEATURE SCROLLBAR — consistent with the compact page scrollbar */
html, body {
  scrollbar-width: thin !important;
  scrollbar-color: #888 transparent !important;
}
html::-webkit-scrollbar, body::-webkit-scrollbar,
.feature-compact ::-webkit-scrollbar { width: 10px !important; height: 10px !important; }
html::-webkit-scrollbar-track, body::-webkit-scrollbar-track,
.feature-compact ::-webkit-scrollbar-track { background: transparent !important; }
html::-webkit-scrollbar-thumb, body::-webkit-scrollbar-thumb,
.feature-compact ::-webkit-scrollbar-thumb {
  background: #888 !important; border-radius: 999px !important;
  border: 2px solid transparent !important; background-clip: padding-box !important;
}
html::-webkit-scrollbar-thumb:hover, body::-webkit-scrollbar-thumb:hover,
.feature-compact ::-webkit-scrollbar-thumb:hover { background: #777 !important; background-clip: padding-box !important; }
html::-webkit-scrollbar-button, body::-webkit-scrollbar-button,
.feature-compact ::-webkit-scrollbar-button { display: block !important; width: 10px !important; height: 10px !important; background-color: transparent !important; }

</style>

<link rel="stylesheet" href="admin_appearance.css">
<script src="admin_appearance.js"></script>
<link rel="stylesheet" href="admin_unified_ui.css?v=<?= (int)@filemtime(__DIR__ . '/admin_unified_ui.css') ?>">
</head>
<body class="feature-compact">
<main class="wrap">

<a class="back-link" href="admin_dashboard.php" onclick="if(window.parent&&window.parent!==window&&window.parent.showPage){window.parent.showPage('dashboard',window.parent.document.getElementById('link-dashboard'));return false;}"><i class="fa-solid fa-arrow-left"></i> Back to Dashboard</a>

<div class="page-header">
  <div>
    <h1>System Logs</h1>
    <p>A live audit trail of role/designation changes and evaluation submissions across the system.</p>
  </div>
  <span class="refresh-badge"><i class="fa-solid fa-arrows-rotate"></i> Auto-refreshes every 10s</span>
</div>

<div class="logs-table-wrap">
  <table class="logs-table">
    <thead>
      <tr>
        <th>Date &amp; Time</th>
        <th>Action</th>
        <th>Performed By</th>
        <th>Source</th>
      </tr>
    </thead>
    <tbody id="logsTableBody">
      <tr><td colspan="4" class="logs-empty">Loading recent activity…</td></tr>
    </tbody>
  </table>
</div>

<button type="button" class="logs-more" id="logsMoreBtn" style="display:none" onclick="loadMoreLogs()">Load more</button>

</main>

<script>
/* Same feed_full source and rendering logic as the dashboard used to run
   inline — only relocated to its own page. */
function escH(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
const SEVERITY_COLORS = { green:'#0F9F6E', yellow:'#C77A08', red:'#D6455D', blue:'#2563EB', info:'#2563EB' };
function activityColor(a){
    if(a.color) return a.color;
    if(a.severity && SEVERITY_COLORS[a.severity]) return SEVERITY_COLORS[a.severity];
    return a.type==='role_change' ? '#4968C8' : '#2563EB';
}
// The signed-in user's own actions are not listed (same rule as the
// dashboard bell). Matched by actor id when supplied, else actor name, else
// the "<Me> evaluated ..." text.
const ME_ID = <?= json_encode((string)($_SESSION['user_id'] ?? '')) ?>;
const ME_NAME = <?= json_encode(trim((string)($_SESSION['full_name'] ?? ''))) ?>.toLowerCase();
function isOwnAction(e){
    if(!e) return false;
    const aid = e.actor_user_id ?? e.actor_id ?? e.evaluator_id ?? null;
    if(aid!==null && aid!=='' && ME_ID!=='' && String(aid)===ME_ID) return true;
    if(ME_NAME==='') return false;
    const actor = String(e.actor||'').trim().toLowerCase();
    if(actor===ME_NAME) return true;
    return String(e.text||'').trim().toLowerCase().startsWith(ME_NAME+' evaluated ');
}
function renderLogsTable(feedFull){
    const body=document.getElementById('logsTableBody');if(!body)return;
    feedFull=(feedFull||[]).filter(a=>!isOwnAction(a));
    if(feedFull.length===0){
        body.innerHTML='<tr><td colspan="4" class="logs-empty">No recent activity yet.</td></tr>';
        return;
    }
    body.innerHTML=feedFull.map(a=>{
        const color=activityColor(a);
        const user=a.actor ? escH(a.actor) : '—';
        return `
        <tr>
            <td class="log-datetime">${escH(a.date||a.time||'—')}</td>
            <td><span class="log-action"><span class="log-action-dot" style="background:${color};"></span>${escH(a.text)}</span></td>
            <td class="log-user">${user}</td>
            <td class="log-details">${escH(a.meta||'')}</td>
        </tr>`;
    }).join('');
}
function renderLoadError(message){
    const body=document.getElementById('logsTableBody');
    if(body){
        body.innerHTML='<tr><td colspan="4" class="logs-empty logs-error">'
            +escH(message||'Unable to load recent activity.')
            +' <button type="button" class="logs-retry" onclick="refreshLogs()">Retry</button></td></tr>';
    }
}
const PAGE_SIZE=50;
let logsLimit=PAGE_SIZE;
function loadMoreLogs(){
    logsLimit+=PAGE_SIZE;
    const more=document.getElementById('logsMoreBtn');
    if(more) more.disabled=true;
    refreshLogs();
}
let _logsRequest=null;
function refreshLogs(){
    if(_logsRequest) _logsRequest.abort();
    const controller=new AbortController();
    _logsRequest=controller;
    const timeout=setTimeout(()=>controller.abort(),8000);

    fetch('dashboard_counts.php?feed_limit='+logsLimit,{
        credentials:'same-origin',
        cache:'no-store',
        signal:controller.signal
    })
    .then(async r=>{
        const raw=await r.text();
        let d=null;
        try{ d=raw ? JSON.parse(raw) : null; }catch(e){
            throw new Error('The activity service returned an invalid response.');
        }
        if(!r.ok){
            throw new Error(d?.error || 'The activity service could not be reached.');
        }
        return d;
    })
    .then(d=>{
        if(!d) return;
        renderLogsTable(d.feed_full);
        const more=document.getElementById('logsMoreBtn');
        if(more){
            more.disabled=false;
            // a full page back means there may be older entries
            more.style.display=(d.feed_full||[]).length>=logsLimit ? 'block' : 'none';
        }
    })
    .catch(err=>{
        if(err?.name==='AbortError'){
            renderLoadError('Recent activity is taking too long to load.');
        }else{
            renderLoadError(err?.message || 'Unable to load recent activity.');
        }
    })
    .finally(()=>{
        clearTimeout(timeout);
        if(_logsRequest===controller) _logsRequest=null;
    });
}
setInterval(refreshLogs,10000);
refreshLogs();
</script>
</body>
</html>