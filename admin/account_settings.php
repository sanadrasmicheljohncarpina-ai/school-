<?php
// admin/account_settings.php
// "My Account" tab of Settings (loaded inside an iframe by settings.php).
// Lets the signed-in admin update their name, change username, change
// password and manage their profile photo. All writes go through this file as
// JSON/AJAX posts, each guarded by a CSRF token. Username and password changes
// additionally require the CURRENT password.
require_once 'session_bootstrap.php';   // starts session, connects DB, enforces admin/superadmin/registrar
require_once 'db.php';

mysqli_report(MYSQLI_REPORT_OFF);

$uid = (int)$_SESSION['user_id'];

// ── CSRF ───────────────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

function acc_json(bool $ok, string $msg, array $extra = []): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok' => $ok, 'message' => $msg], $extra));
    exit;
}

function acc_photo_url(?string $photo): ?string {
    $photo = trim((string)$photo);
    if ($photo === '') return null;
    if (preg_match('#^(?:https?:)?//#i', $photo) || str_starts_with($photo, 'data:image/')) return $photo;
    $photo = ltrim(str_replace('\\', '/', $photo), '/');
    $photo = preg_replace('#^(?:\./|\.\./)+#', '', $photo);
    if (str_starts_with($photo, 'image/')) return '../' . $photo;
    return UPLOAD_URL . $photo;
}

function acc_load(mysqli $db, int $uid): ?array {
    $st = $db->prepare("SELECT id, username, full_name, email, photo, password_hash FROM users WHERE id = ? LIMIT 1");
    $st->bind_param('i', $uid);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

// ── AJAX actions ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || !hash_equals($csrf, $sent)) {
        acc_json(false, 'Your session expired. Please reload the page and try again.');
    }
    $me = acc_load($mysqli, $uid);
    if (!$me) acc_json(false, 'Account not found.');
    $action = $_POST['action'] ?? '';

    // ---- Personal information ----
    if ($action === 'update_profile') {
        $full = trim(preg_replace('/\s+/', ' ', (string)($_POST['full_name'] ?? '')));
        if ($full === '' || mb_strlen($full) > 150) acc_json(false, 'Enter your full name (up to 150 characters).');
        $st = $mysqli->prepare("UPDATE users SET full_name = ? WHERE id = ?");
        $st->bind_param('si', $full, $uid);
        if (!$st->execute()) { $st->close(); acc_json(false, 'Could not save your profile. Please try again.'); }
        $st->close();
        $_SESSION['full_name'] = $full;
        acc_json(true, 'Personal information updated.', ['full_name' => $full]);
    }

    // ---- Username (requires current password) ----
    if ($action === 'update_username') {
        $new = trim((string)($_POST['username'] ?? ''));
        $cur = (string)($_POST['current_password'] ?? '');
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $new)) {
            acc_json(false, 'Username must be 3–50 characters: letters, numbers, dot, dash or underscore (no spaces).');
        }
        if (!password_verify($cur, (string)$me['password_hash'])) {
            acc_json(false, 'Your current password is incorrect.');
        }
        if ($new === $me['username']) acc_json(false, 'That is already your username.');
        // Case-insensitive uniqueness so "Admin" and "admin" can't coexist.
        $st = $mysqli->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(?) AND id <> ? LIMIT 1");
        $st->bind_param('si', $new, $uid);
        $st->execute();
        $taken = $st->get_result()->num_rows > 0;
        $st->close();
        if ($taken) acc_json(false, 'That username is already taken. Please choose another.');

        $st = $mysqli->prepare("UPDATE users SET username = ? WHERE id = ?");
        $st->bind_param('si', $new, $uid);
        if (!$st->execute()) { $st->close(); acc_json(false, 'Could not change the username. Please try again.'); }
        $st->close();
        acc_json(true, 'Username changed. Use it the next time you sign in.', ['username' => $new]);
    }

    // ---- Password (requires current password) ----
    if ($action === 'update_password') {
        $cur = (string)($_POST['current_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $con = (string)($_POST['confirm_password'] ?? '');
        if (!password_verify($cur, (string)$me['password_hash'])) acc_json(false, 'Your current password is incorrect.');
        if (strlen($new) < 8) acc_json(false, 'New password must be at least 8 characters.');
        if (strlen($new) > 72) acc_json(false, 'New password must be 72 characters or fewer.');
        if ($new !== $con) acc_json(false, 'New passwords do not match.');
        if (hash_equals($cur, $new)) acc_json(false, 'Choose a password different from your current one.');

        $hash = password_hash($new, PASSWORD_DEFAULT);
        $st = $mysqli->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $st->bind_param('si', $hash, $uid);
        if (!$st->execute()) { $st->close(); acc_json(false, 'Could not change the password. Please try again.'); }
        $st->close();
        // Clear the forced-change flag if this install has that column.
        $col = $mysqli->query("SHOW COLUMNS FROM users LIKE 'must_change_password'");
        if ($col && $col->num_rows > 0) {
            $st = $mysqli->prepare("UPDATE users SET must_change_password = 0 WHERE id = ?");
            $st->bind_param('i', $uid);
            $st->execute();
            $st->close();
            $_SESSION['must_change_password'] = 0;
        }
        session_regenerate_id(true);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        acc_json(true, 'Password changed successfully.', ['csrf_token' => $_SESSION['csrf_token']]);
    }

    // ---- Photo upload / removal ----
    if ($action === 'update_photo') {
        $f = $_FILES['photo'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) acc_json(false, 'Choose an image to upload.');
        if ($f['size'] > 5 * 1024 * 1024) acc_json(false, 'Image must be 5 MB or smaller.');
        $mime = mime_content_type($f['tmp_name']);
        $exts = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        if (!isset($exts[$mime]) || @getimagesize($f['tmp_name']) === false) {
            acc_json(false, 'Invalid file. Use a JPG, PNG, WebP or GIF image.');
        }
        if (!is_dir(UPLOAD_DIR) && !@mkdir(UPLOAD_DIR, 0755, true)) acc_json(false, 'The image folder is not writable.');
        $name = 'adm_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $exts[$mime];
        if (!move_uploaded_file($f['tmp_name'], UPLOAD_DIR . $name)) acc_json(false, 'Could not save the uploaded image.');

        $old = (string)$me['photo'];
        $st = $mysqli->prepare("UPDATE users SET photo = ? WHERE id = ?");
        $st->bind_param('si', $name, $uid);
        if (!$st->execute()) { $st->close(); @unlink(UPLOAD_DIR . $name); acc_json(false, 'Could not update your photo.'); }
        $st->close();
        if ($old !== '' && basename($old) === $old && is_file(UPLOAD_DIR . $old)) @unlink(UPLOAD_DIR . $old);
        acc_json(true, 'Profile photo updated.', ['photo_url' => acc_photo_url($name)]);
    }

    if ($action === 'remove_photo') {
        $old = (string)$me['photo'];
        $st = $mysqli->prepare("UPDATE users SET photo = NULL WHERE id = ?");
        $st->bind_param('i', $uid);
        $st->execute();
        $st->close();
        if ($old !== '' && basename($old) === $old && is_file(UPLOAD_DIR . $old)) @unlink(UPLOAD_DIR . $old);
        acc_json(true, 'Profile photo removed.', ['photo_url' => null]);
    }

    acc_json(false, 'Unknown action.');
}

// ── Page render ────────────────────────────────────────────────────────────
$me = acc_load($mysqli, $uid);
if (!$me) { header('Location: admin_login.php'); exit; }
$photoUrl = acc_photo_url($me['photo']);
$parts = preg_split('/\s+/', trim((string)$me['full_name']));
$initials = strtoupper(mb_substr($parts[0] ?? '', 0, 1) . (isset($parts[1]) ? mb_substr($parts[1], 0, 1) : ''));
$roleMap = ['superadmin' => 'Executive Assistant', 'admin' => 'Admin', 'registrar' => 'Registrar'];
$roleLabel = $roleMap[$_SESSION['role'] ?? ''] ?? ucfirst((string)($_SESSION['role'] ?? ''));
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<script>
(function(){
  try{
    var t=localStorage.getItem('pbiTheme')||'light', a=(localStorage.getItem('pbiAccent')||'#0F9F6E').replace(/^#1f9d63$/i,'#0F9F6E');
    var r=t==='system'?(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'):t;
    document.documentElement.setAttribute('data-theme',r);
    document.documentElement.style.setProperty('--accent',a);
    document.documentElement.classList.toggle('density-comfortable',(localStorage.getItem('pbiDensity')||'compact')==='comfortable');
  }catch(e){}
})();
</script>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>My Account — PBI Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{--accent:#0F9F6E;--accent-soft:color-mix(in srgb,var(--accent) 12%,#fff);--bg:#f4f8ff;--text:#10243f;--muted:#66809c;--panel-bg:#fff;--panel-border:#dce8f5;--input-bg:#f9fbff;--input-border:#d4e2f2;--ok:#0F9F6E;--err:#D6455D}
html[data-theme="dark"]{--bg:#0b1626;--text:#e7eef8;--muted:#8ba0bd;--panel-bg:#121f33;--panel-border:#22344d;--input-bg:#16243a;--input-border:#25374f;--accent-soft:color-mix(in srgb,var(--accent) 22%,#121f33)}
*{box-sizing:border-box}
html,body{margin:0;font-family:Inter,system-ui,sans-serif;background:transparent;color:var(--text)}
body{padding:18px}
.acc-grid{display:grid;grid-template-columns:300px minmax(0,1fr);gap:16px;align-items:start;max-width:1100px}
.card{background:var(--panel-bg);border:1px solid var(--panel-border);border-radius:13px;padding:18px}
.card + .card{margin-top:16px}
.card h3{margin:0 0 3px;font-size:14px;font-weight:800}
.card .sub{margin:0 0 14px;color:var(--muted);font-size:11.5px;line-height:1.45}
.me{text-align:center}
.avatar{width:104px;height:104px;border-radius:50%;margin:2px auto 12px;background:var(--accent-soft);color:var(--accent);display:flex;align-items:center;justify-content:center;font-size:34px;font-weight:800;overflow:hidden;border:3px solid var(--panel-border)}
.avatar img{width:100%;height:100%;object-fit:cover;display:block}
.me-name{font-size:16px;font-weight:800;margin:0}
.me-user{font-size:12px;color:var(--muted);margin:2px 0 8px}
.role-pill{display:inline-block;font-size:10.5px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--accent);background:var(--accent-soft);padding:4px 10px;border-radius:99px}
.photo-actions{display:flex;gap:8px;justify-content:center;margin-top:14px;flex-wrap:wrap}
.field{margin-bottom:13px}
.field label{display:block;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px}
.field input{width:100%;border:1px solid var(--input-border);background:var(--input-bg);color:var(--text);border-radius:9px;padding:10px 12px;font:500 13px Inter,sans-serif;outline:none;transition:border-color .15s,box-shadow .15s}
.field input:focus{border-color:var(--accent);box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 20%,transparent)}
.field .hint{margin-top:5px;font-size:11px;color:var(--muted)}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.pw-wrap{position:relative}.pw-wrap input{padding-right:40px}
.pw-eye{position:absolute;right:4px;top:50%;transform:translateY(-50%);border:0;background:none;color:var(--muted);width:34px;height:34px;cursor:pointer;border-radius:8px}
.pw-eye:hover{color:var(--accent)}
.meter{height:5px;border-radius:99px;background:var(--input-border);overflow:hidden;margin-top:7px}
.meter i{display:block;height:100%;width:0;background:var(--err);transition:width .2s,background .2s}
.btn{display:inline-flex;align-items:center;gap:8px;border:1px solid var(--accent);background:var(--accent);color:#fff;border-radius:9px;padding:9px 16px;font:700 12.5px Inter,sans-serif;cursor:pointer;transition:opacity .15s}
.btn:hover:not(:disabled){opacity:.9}.btn:disabled{opacity:.55;cursor:not-allowed}
.btn.ghost{background:transparent;color:var(--text);border-color:var(--input-border)}
.btn.ghost:hover:not(:disabled){border-color:var(--accent);color:var(--accent);opacity:1}
.btn.danger{background:transparent;color:var(--err);border-color:color-mix(in srgb,var(--err) 45%,transparent)}
.btn.sm{padding:7px 12px;font-size:11.5px}
.form-foot{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:4px}
.msg{font-size:12px;font-weight:600;display:none}
.msg.ok{display:inline;color:var(--ok)}.msg.err{display:inline;color:var(--err)}
@media(max-width:860px){.acc-grid{grid-template-columns:1fr}.row2{grid-template-columns:1fr}}
</style>
<script src="admin_ajax.php"></script>
</head>
<body>
<div class="acc-grid">

  <!-- Left: identity summary + photo -->
  <aside class="card me">
    <div class="avatar" id="avatar">
      <?php if ($photoUrl): ?><img id="avatarImg" src="<?= $e($photoUrl) ?>" alt="" onerror="this.remove();document.getElementById('avatar').textContent=document.getElementById('avatar').dataset.initials"><?php else: ?><?= $e($initials) ?><?php endif; ?>
    </div>
    <script>document.getElementById('avatar').dataset.initials=<?= json_encode($initials) ?>;</script>
    <p class="me-name" id="meName"><?= $e($me['full_name']) ?></p>
    <p class="me-user">@<span id="meUser"><?= $e($me['username']) ?></span></p>
    <span class="role-pill"><?= $e($roleLabel) ?></span>
    <div class="photo-actions">
      <input type="file" id="photoInput" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
      <button class="btn sm" type="button" id="photoBtn"><i class="fa-solid fa-camera"></i> Change photo</button>
      <button class="btn sm danger" type="button" id="photoRemove" <?= $photoUrl ? '' : 'hidden' ?>><i class="fa-regular fa-trash-can"></i> Remove</button>
    </div>
    <p class="sub" style="margin:12px 0 0">JPG, PNG, WebP or GIF · up to 5 MB</p>
    <div class="msg" id="msg-photo" style="margin-top:6px"></div>
  </aside>

  <!-- Right: forms -->
  <div>
    <section class="card">
      <h3>Personal information</h3>
      <p class="sub">Your name as shown across the admin portal.</p>
      <form id="formProfile" autocomplete="off">
        <div class="field" style="max-width:calc(50% - 6px)"><label for="full_name">Full name</label><input id="full_name" name="full_name" type="text" maxlength="150" required value="<?= $e($me['full_name']) ?>"></div>
        <div class="form-foot"><button class="btn" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save changes</button><span class="msg" id="msg-profile"></span></div>
      </form>
    </section>

    <section class="card">
      <h3>Username</h3>
      <p class="sub">This is what you type to sign in. Usernames are case-sensitive at login. Confirm with your current password.</p>
      <form id="formUsername" autocomplete="off">
        <div class="row2">
          <div class="field"><label for="username">New username</label><input id="username" name="username" type="text" minlength="3" maxlength="50" required value="<?= $e($me['username']) ?>" autocapitalize="off" spellcheck="false">
            <div class="hint">3–50 characters: letters, numbers, . _ -</div></div>
          <div class="field"><label for="u_cur">Current password</label>
            <div class="pw-wrap"><input id="u_cur" name="current_password" type="password" required autocomplete="current-password"><button class="pw-eye" type="button" tabindex="-1" aria-label="Show password"><i class="fa-regular fa-eye"></i></button></div></div>
        </div>
        <div class="form-foot"><button class="btn" type="submit"><i class="fa-solid fa-user-pen"></i> Change username</button><span class="msg" id="msg-username"></span></div>
      </form>
    </section>

    <section class="card">
      <h3>Password</h3>
      <p class="sub">Use at least 8 characters. A longer phrase is stronger than a short complex one.</p>
      <form id="formPassword" autocomplete="off">
        <div class="field" style="max-width:calc(50% - 6px)"><label for="p_cur">Current password</label>
          <div class="pw-wrap"><input id="p_cur" name="current_password" type="password" required autocomplete="current-password"><button class="pw-eye" type="button" tabindex="-1" aria-label="Show password"><i class="fa-regular fa-eye"></i></button></div></div>
        <div class="row2">
          <div class="field"><label for="p_new">New password</label>
            <div class="pw-wrap"><input id="p_new" name="new_password" type="password" minlength="8" maxlength="72" required autocomplete="new-password"><button class="pw-eye" type="button" tabindex="-1" aria-label="Show password"><i class="fa-regular fa-eye"></i></button></div>
            <div class="meter"><i id="meterBar"></i></div></div>
          <div class="field"><label for="p_con">Confirm new password</label>
            <div class="pw-wrap"><input id="p_con" name="confirm_password" type="password" minlength="8" maxlength="72" required autocomplete="new-password"><button class="pw-eye" type="button" tabindex="-1" aria-label="Show password"><i class="fa-regular fa-eye"></i></button></div></div>
        </div>
        <div class="form-foot"><button class="btn" type="submit"><i class="fa-solid fa-key"></i> Change password</button><span class="msg" id="msg-password"></span></div>
      </form>
    </section>
  </div>
</div>

<script>
let CSRF = <?= json_encode($csrf) ?>;

function setMsg(id, ok, text){
  const el = document.getElementById('msg-'+id);
  el.className = 'msg ' + (ok ? 'ok' : 'err');
  el.textContent = text;
}
async function send(action, fd){
  fd.append('action', action);
  fd.append('csrf_token', CSRF);
  try{
    const r = await fetch('account_settings.php', {method:'POST', body:fd, credentials:'same-origin'});
    return await r.json();
  }catch(e){
    return {ok:false, message:'Network error. Please try again.'};
  }
}
// Tell the dashboard shell (parent of the Settings iframe) to refresh its sidebar profile block.
function syncShell(data){
  try{
    let w = window.parent;
    for (let i=0;i<3 && w;i++){
      if (typeof w.pbiUpdateProfile === 'function'){ w.pbiUpdateProfile(data); break; }
      if (w === w.parent) break; w = w.parent;
    }
  }catch(e){}
}
function wire(formId, key, action, onOk){
  const form = document.getElementById(formId);
  form.addEventListener('submit', async ev=>{
    ev.preventDefault();
    const btn = form.querySelector('button[type=submit]');
    btn.disabled = true; setMsg(key, true, 'Saving…');
    const res = await send(action, new FormData(form));
    btn.disabled = false;
    setMsg(key, res.ok, res.message);
    if (res.ok) onOk && onOk(res);
    if (res.ok && window.PBI && PBI.toast) PBI.toast(res.message);
  });
}
const initials = n => { const p=n.trim().split(/\s+/); return ((p[0]||'')[0]||'').toUpperCase() + ((p[1]||'')[0]||'').toUpperCase(); };

wire('formProfile','profile','update_profile', res=>{
  document.getElementById('meName').textContent = res.full_name;
  document.getElementById('avatar').dataset.initials = initials(res.full_name);
  if (!document.getElementById('avatarImg')) document.getElementById('avatar').textContent = initials(res.full_name);
  syncShell({full_name: res.full_name});
});
wire('formUsername','username','update_username', res=>{
  document.getElementById('meUser').textContent = res.username;
  document.getElementById('u_cur').value = '';
});
wire('formPassword','password','update_password', res=>{
  if (res.csrf_token) CSRF = res.csrf_token;
  document.getElementById('formPassword').reset();
  document.getElementById('meterBar').style.width = '0';
});

// Photo
const photoInput = document.getElementById('photoInput');
document.getElementById('photoBtn').onclick = ()=>photoInput.click();
function showPhoto(url){
  const av = document.getElementById('avatar');
  av.innerHTML = '';
  if (url){ const i=document.createElement('img'); i.id='avatarImg'; i.src=url+'?t='+Date.now(); av.appendChild(i); }
  else av.textContent = av.dataset.initials || '';
  document.getElementById('photoRemove').hidden = !url;
}
photoInput.addEventListener('change', async ()=>{
  const f = photoInput.files[0]; if (!f) return;
  const fd = new FormData(); fd.append('photo', f);
  setMsg('photo', true, 'Uploading…');
  const res = await send('update_photo', fd);
  photoInput.value = '';
  setMsg('photo', res.ok, res.message);
  if (res.ok){ showPhoto(res.photo_url); syncShell({photo_url: res.photo_url}); }
});
document.getElementById('photoRemove').onclick = async ()=>{
  if (window.PBI && PBI.confirm && !(await PBI.confirm('Remove your profile photo?', {okLabel:'Remove'}))) return;
  const res = await send('remove_photo', new FormData());
  setMsg('photo', res.ok, res.message);
  if (res.ok){ showPhoto(null); syncShell({photo_url: null}); }
};

// Show/hide password
document.querySelectorAll('.pw-eye').forEach(b=>b.addEventListener('click',()=>{
  const inp = b.parentElement.querySelector('input'), show = inp.type==='password';
  inp.type = show ? 'text' : 'password';
  b.firstElementChild.className = show ? 'fa-regular fa-eye-slash' : 'fa-regular fa-eye';
}));

// Strength meter
document.getElementById('p_new').addEventListener('input', e=>{
  const v = e.target.value; let s = 0;
  if (v.length >= 8) s++; if (v.length >= 12) s++;
  if (/[a-z]/.test(v) && /[A-Z]/.test(v)) s++;
  if (/\d/.test(v)) s++; if (/[^A-Za-z0-9]/.test(v)) s++;
  const bar = document.getElementById('meterBar');
  bar.style.width = (v ? Math.max(12, s*20) : 0) + '%';
  bar.style.background = s<=1 ? 'var(--err)' : s<=3 ? '#F0B429' : 'var(--ok)';
});
</script>
</body>
</html>
