<?php
// index/maintenance.php
// ONE shared maintenance page for every role that gets locked out
// (Student, Teacher, Dean, Staff, Principal). The Executive Assistant is NOT
// sent here: that exemption is decided by the guard that performs the
// redirect, not by this page.
//
// Standalone on purpose: no session guard, no db.php, no redirects, so it can
// never loop back on itself while the rest of the system is locked.

// If your logo file lives somewhere else, change this path (relative to this file).
$logoSrc = 'logo.png';

// Where to send people once maintenance ends. The guard that redirects here
// should append ?return=<the page they were on>. Only local paths are accepted
// (no external URLs, no protocol-relative //, no loops back to this page).
$return = (string)($_GET['return'] ?? '');
if ($return === ''
    || $return[0] !== '/'
    || str_starts_with($return, '//')
    || str_contains($return, '\\')
    || stripos($return, 'maintenance.php') !== false) {
    $return = '';
}

http_response_code(503);
header('Retry-After: 1800');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>System Maintenance — Pandan Bay Institute Inc.</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#0A1730;--card:#152543;--card-border:#223559;
  --text:#EAF0FA;--dim:#8FA3C4;
  --amber:#E58A12;--amber-soft:rgba(229,138,18,.14);
  --blue:#2F6EE2;
}
*{box-sizing:border-box}
html,body{height:100%}
body{
  margin:0;display:flex;align-items:center;justify-content:center;padding:24px;overflow:hidden;
  background:var(--bg);color:var(--text);font-family:'DM Sans','Segoe UI',Arial,sans-serif;
  background-image:
    linear-gradient(rgba(143,163,196,.05) 1px,transparent 1px),
    linear-gradient(90deg,rgba(143,163,196,.05) 1px,transparent 1px);
  background-size:40px 40px;
}
/* decorative hexagons, same motif as the portal landing page */
.hex{position:fixed;pointer-events:none;opacity:.9}
.hex.tl{top:34px;left:50px;width:240px;color:var(--amber)}
.hex.br{bottom:-30px;right:50px;width:240px;color:var(--blue)}
@media(max-width:900px){.hex{display:none}}

.wrap{position:relative;z-index:1;width:100%;max-width:560px;text-align:center}
.logo{
  width:98px;height:98px;margin:0 auto 18px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;overflow:hidden;
  background:var(--card);border:2px solid var(--amber);
  box-shadow:0 0 0 4px rgba(229,138,18,.18),0 0 32px rgba(229,138,18,.35);
  color:var(--amber);font-size:38px;
}
.logo img{width:100%;height:100%;object-fit:cover;display:block}
h1.school{margin:0;font-family:'Rajdhani',sans-serif;font-size:34px;font-weight:700;letter-spacing:.5px}
.system{margin:8px 0 28px;font-size:12.5px;letter-spacing:1.6px;text-transform:uppercase;color:var(--dim)}

.card{
  background:var(--card);border:1px solid var(--card-border);border-radius:16px;
  padding:34px 30px 28px;box-shadow:0 18px 44px rgba(0,0,0,.35);
}
.icon{
  position:relative;width:72px;height:72px;margin:0 auto 18px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  border:2px solid var(--amber);background:var(--amber-soft);color:var(--amber);font-size:28px;
}
.icon::after{
  content:'';position:absolute;inset:-9px;border-radius:50%;
  border:1px dashed var(--amber);opacity:.4;animation:spin 16s linear infinite;
}
@keyframes spin{to{transform:rotate(360deg)}}
h2{margin:0 0 10px;font-family:'Rajdhani',sans-serif;font-size:28px;font-weight:700}
.msg{margin:0 auto;max-width:410px;color:var(--dim);font-size:14.5px;line-height:1.65}
.status{
  display:inline-flex;align-items:center;gap:8px;margin:22px 0 4px;padding:8px 16px;border-radius:999px;
  border:1px solid rgba(229,138,18,.35);background:var(--amber-soft);color:var(--amber);font-size:12.5px;font-weight:700;
}
.dot{width:8px;height:8px;border-radius:50%;background:var(--amber);animation:pulse 1.6s ease-in-out infinite}
@keyframes pulse{50%{opacity:.3}}
button{
  margin-top:20px;display:inline-flex;align-items:center;gap:8px;cursor:pointer;
  border:1px solid var(--amber);background:var(--amber);color:#1b1203;
  padding:11px 22px;border-radius:10px;font:700 13.5px 'DM Sans',sans-serif;transition:filter .15s;
}
button:hover{filter:brightness(1.1)}
.foot{margin-top:22px;font-size:12px;color:var(--dim)}
@media (prefers-reduced-motion: reduce){.icon::after,.dot{animation:none}}
</style>
</head>
<body>

<svg class="hex tl" viewBox="0 0 240 260" fill="none" stroke="currentColor" stroke-width="1.2" aria-hidden="true">
  <polygon points="120,6 232,68 232,192 120,254 8,192 8,68"/>
  <polygon points="120,48 196,90 196,170 120,212 44,170 44,90"/>
</svg>
<svg class="hex br" viewBox="0 0 240 260" fill="none" stroke="currentColor" stroke-width="1.2" aria-hidden="true">
  <polygon points="120,6 232,68 232,192 120,254 8,192 8,68"/>
  <polygon points="120,48 196,90 196,170 120,212 44,170 44,90"/>
</svg>

<main class="wrap" role="main">
  <div class="logo">
    <img src="<?= htmlspecialchars($logoSrc, ENT_QUOTES) ?>" alt="Pandan Bay Institute Inc. logo"
         onerror="this.remove();document.querySelector('.logo').innerHTML='<i class=&quot;fa-solid fa-school&quot;></i>';">
  </div>
  <h1 class="school">Pandan Bay Institute Inc.</h1>
  <div class="system">Employee Performance Evaluation and Management System</div>

  <section class="card">
    <div class="icon"><i class="fa-solid fa-screwdriver-wrench"></i></div>
    <h2>System Under Maintenance</h2>
    <p class="msg">We're making some improvements to keep the system running smoothly.
       Please check back in a little while. Your data is safe and nothing has been lost.</p>
    <div class="status"><span class="dot"></span> Maintenance in progress</div><br>
    <button type="button" id="checkBtn" onclick="checkNow(true)"><i class="fa-solid fa-arrows-rotate"></i> Check again</button>
    <div class="foot">Thank you for your patience.</div>
  </section>
</main>

<script>
// Where to go back to: the page they were on (?return=), else the previous
// same-site page, else the portal home (this file lives in /index/).
var RETURN_TO = <?= json_encode($return) ?>;
if (!RETURN_TO && document.referrer) {
  try {
    var r = new URL(document.referrer);
    if (r.origin === location.origin && r.pathname.indexOf('maintenance.php') === -1) {
      RETURN_TO = r.pathname + r.search;
    }
  } catch (e) {}
}
if (!RETURN_TO) RETURN_TO = './';

var checking = false;
// Ask the server for the page they were on. While maintenance is ON it bounces
// back to maintenance.php (or answers 503); once it's OFF the real page loads,
// and we send them straight there.
function checkNow(manual){
  if (checking) return;
  checking = true;
  var btn = document.getElementById('checkBtn');
  if (manual && btn) btn.disabled = true;
  fetch(RETURN_TO, {credentials:'same-origin', cache:'no-store'})
    .then(function(res){
      var stillDown = res.status === 503 || res.url.indexOf('maintenance.php') !== -1;
      if (res.ok && !stillDown) { location.replace(RETURN_TO); return; }
      if (manual && btn) btn.innerHTML = '<i class="fa-solid fa-hourglass-half"></i> Still under maintenance';
    })
    .catch(function(){})
    .finally(function(){
      checking = false;
      if (btn) setTimeout(function(){
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-arrows-rotate"></i> Check again';
      }, manual ? 2500 : 0);
    });
}
setInterval(function(){ checkNow(false); }, 15000);
</script>
</body>
</html>