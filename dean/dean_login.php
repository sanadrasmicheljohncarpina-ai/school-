<?php
// dean/dean_login.php
session_start();
require_once 'db.php';

$error = '';
$success = $_SESSION['reset_success'] ?? '';
unset($_SESSION['reset_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
        $error = 'Session expired. Please try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $error = 'Please enter both username and password.';
        } else {
            // Case-sensitive username: MySQL's default collation treats "Dean" and
            // "dean" as equal, so require an exact (binary) match with the username
            // that was typed during registration before running the normal login.
            $exactMatch = false;
            $exactStmt = $mysqli->prepare("SELECT 1 FROM users WHERE BINARY username = ? AND role = 'dean' LIMIT 1");
            if ($exactStmt) {
                $exactStmt->bind_param('s', $username);
                $exactStmt->execute();
                $exactStmt->store_result();
                $exactMatch = $exactStmt->num_rows > 0;
                $exactStmt->close();
            }

            if (!$exactMatch) {
                $result = ['ok' => false, 'error' => 'Invalid username or password.'];
            } else {
                $result = ems_verify_login($mysqli, $username, $password, 'dean');
            }
            if ($result['ok']) {
                session_regenerate_id(true);
                ems_start_authenticated_session($result['user']);
                header('Location: dean_dashboard.php');
                exit;
            } else {
                $error = $result['error'];
            }
        }
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>PBI — Dean Login</title>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<style>
:root{
    --dark-blue:#0A192F;
    --blue-mid:#172A45;
    --violet:#7C5FD9;
    --violet-hover:#9C85F0;
    --light:#E0E6F0;
    --muted:#A0B3C6;
    --radius:10px;
    --shadow:0 8px 32px rgba(0,0,0,.45);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
html{min-height:100%;background:var(--dark-blue);}
body{
    min-height:100vh;
    background:var(--dark-blue);
    font-family:'DM Sans',sans-serif;
    color:var(--light);
    display:flex;
    align-items:center;
    justify-content:center;
    overflow:hidden;
    position:relative;
    padding:24px;
}

/* Decorative background — intentionally matches the Student Portal composition,
   while the Dean Portal keeps its violet identity. */
.bg-grid{
    position:fixed;
    inset:0;
    z-index:0;
    pointer-events:none;
    background-image:
        repeating-linear-gradient(45deg,rgba(124,95,217,.075) 0,rgba(124,95,217,.075) 1px,transparent 1px,transparent 26px),
        repeating-linear-gradient(-45deg,rgba(124,95,217,.055) 0,rgba(124,95,217,.055) 1px,transparent 1px,transparent 26px);
}
.bg-glow{
    position:fixed;
    width:480px;
    height:480px;
    border-radius:50%;
    z-index:0;
    pointer-events:none;
    filter:blur(50px);
    opacity:.28;
}
.bg-glow-1{top:-260px;right:-120px;background:radial-gradient(circle,rgba(124,95,217,.65),transparent 68%);}
.bg-glow-2{bottom:-300px;left:-170px;background:radial-gradient(circle,rgba(43,108,176,.45),transparent 68%);}
.hex-deco{position:fixed;z-index:0;pointer-events:none;opacity:.42;}
.hex-1{top:-60px;left:-60px;}
.hex-2{bottom:-70px;right:-70px;}

.login-card{
    position:relative;
    z-index:10;
    width:100%;
    max-width:420px;
    padding:48px 44px 40px;
    background:rgba(23,42,69,.84);
    backdrop-filter:blur(18px);
    -webkit-backdrop-filter:blur(18px);
    border:1px solid rgba(255,255,255,.09);
    border-radius:18px;
    box-shadow:var(--shadow),0 0 0 1px rgba(124,95,217,.10),0 18px 60px rgba(0,0,0,.20);
    animation:cardIn .7s cubic-bezier(.22,1,.36,1) both;
}
@keyframes cardIn{from{opacity:0;transform:translateY(32px) scale(.97)}to{opacity:1;transform:none}}

.card-header{text-align:center;margin-bottom:28px;}
.logo-ring{
    width:72px;
    height:72px;
    border-radius:50%;
    display:block;
    object-fit:cover;
    border:2.5px solid var(--violet);
    box-shadow:0 0 22px rgba(124,95,217,.42);
    margin:0 auto 16px;
}
.card-title{
    font-family:'Rajdhani',sans-serif;
    font-size:26px;
    font-weight:700;
    letter-spacing:2px;
    color:#fff;
    text-transform:uppercase;
}
.card-subtitle{
    font-size:12px;
    color:var(--muted);
    letter-spacing:1.2px;
    text-transform:uppercase;
    margin-top:4px;
}
.role-pill{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    margin-top:10px;
    padding:4px 14px;
    border-radius:20px;
    background:rgba(124,95,217,.14);
    border:1px solid rgba(124,95,217,.38);
    color:var(--violet-hover);
    font-size:11px;
    font-weight:700;
    letter-spacing:.8px;
    text-transform:uppercase;
}
.divider{
    height:1px;
    margin-bottom:24px;
    background:linear-gradient(90deg,transparent,rgba(124,95,217,.40),transparent);
}

.alert{
    display:flex;
    align-items:flex-start;
    gap:8px;
    border-radius:8px;
    padding:11px 14px;
    margin-bottom:18px;
    font-size:13px;
    line-height:1.45;
}
.alert-error{
    background:rgba(240,84,84,.12);
    border:1px solid rgba(240,84,84,.35);
    color:#ff8a8a;
}

.form-group{margin-bottom:18px;}
.form-label{
    display:block;
    margin-bottom:7px;
    color:var(--muted);
    font-size:11px;
    font-weight:600;
    letter-spacing:1.2px;
    text-transform:uppercase;
}
.input-wrap{position:relative;}
.f-icon{
    position:absolute;
    left:14px;
    top:50%;
    transform:translateY(-50%);
    color:var(--muted);
    font-size:14px;
    pointer-events:none;
    transition:color .2s ease;
}
.form-input{
    width:100%;
    padding:12px 42px 12px 40px;
    min-height:50px;
    background:rgba(10,25,47,.70);
    border:1px solid rgba(255,255,255,.10);
    border-radius:var(--radius);
    color:var(--light);
    font:500 14px 'DM Sans',sans-serif;
    outline:none;
    transition:border-color .25s,box-shadow .25s,background .25s;
}
.form-input::placeholder{color:rgba(160,179,198,.45);}
.form-input:hover{background:rgba(10,25,47,.78);}
.form-input:focus{
    border-color:var(--violet);
    background:rgba(10,25,47,.84);
    box-shadow:0 0 0 3px rgba(124,95,217,.20);
}
.form-input:focus + .f-icon,
.input-wrap:focus-within .f-icon{color:var(--violet-hover);}
.toggle-pw{
    position:absolute;
    right:13px;
    top:50%;
    transform:translateY(-50%);
    border:0;
    background:none;
    padding:5px;
    color:var(--muted);
    cursor:pointer;
    font-size:14px;
    line-height:1;
}
.toggle-pw:hover{color:#fff;}
.toggle-pw:focus-visible{outline:2px solid var(--violet-hover);outline-offset:2px;border-radius:5px;}

.btn-login{
    width:100%;
    min-height:52px;
    padding:13px 16px;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    border:0;
    border-radius:var(--radius);
    background:var(--violet);
    color:#fff;
    font:600 15px 'DM Sans',sans-serif;
    cursor:pointer;
    box-shadow:0 4px 16px rgba(124,95,217,.40);
    transition:background .2s,transform .15s,box-shadow .2s,opacity .2s;
}
.btn-login:hover{background:var(--violet-hover);transform:translateY(-1px);box-shadow:0 7px 20px rgba(124,95,217,.48);}
.btn-login:active{transform:translateY(0);}
.btn-login:disabled{cursor:not-allowed;opacity:.78;transform:none;box-shadow:0 4px 12px rgba(124,95,217,.22);}

.register-row{
    margin-top:16px;
    text-align:center;
    color:var(--muted);
    font-size:13px;
}
.register-row a{
    color:var(--violet-hover);
    font-weight:600;
    text-decoration:none;
}
.register-row a:hover{text-decoration:underline;}

.card-footer{
    margin-top:18px;
    padding-top:14px;
    border-top:1px solid rgba(255,255,255,.06);
    text-align:center;
    color:var(--muted);
    font-size:12px;
}
.secure-badge{
    display:inline-flex;
    align-items:center;
    gap:5px;
    font-size:11px;
}
.secure-badge i{color:#4ade80;font-size:10px;}

@media(max-width:480px){
    body{padding:16px;}
    .login-card{padding:36px 20px 32px;margin:0;}
    .hex-deco{opacity:.28;transform:scale(.78);}
    .card-title{font-size:24px;}
}
@media(max-height:700px){
    body{overflow:auto;}
    .login-card{margin:18px 0;}
}
@media(prefers-reduced-motion:reduce){
    .login-card{animation:none;}
}

/* =====================================================================
   Ambient lighting — same treatment as the Student Portal auth pages
   (student_ui.css), recoloured from amber to the Dean violet.
   ===================================================================== */
html{background:var(--dark-blue);}
body{
    background:
        radial-gradient(circle at 10% 10%, rgba(124,95,217,.16), transparent 32%),
        radial-gradient(circle at 90% 90%, rgba(43,108,176,.12), transparent 30%),
        var(--dark-blue);
    background-attachment:fixed;
}
body::before{
    content:"";
    position:fixed;
    inset:0;
    z-index:0;
    pointer-events:none;
    background:linear-gradient(180deg,rgba(255,255,255,.025),transparent 26%,rgba(0,0,0,.12));
}
.bg-glow{display:none;}

.login-card,.reg-card{
    border-color:rgba(255,255,255,.12);
    box-shadow:0 24px 70px rgba(0,0,0,.36),0 0 0 1px rgba(255,255,255,.02),0 0 90px rgba(124,95,217,.09);
    transition:border-color .3s ease,box-shadow .3s ease;
}
.login-card:hover,.reg-card:hover{
    border-color:rgba(156,133,240,.30);
    box-shadow:0 24px 70px rgba(0,0,0,.36),0 0 0 1px rgba(255,255,255,.02),0 0 100px rgba(124,95,217,.15);
}
.form-input:hover:not(:focus){border-color:rgba(156,133,240,.42);}

button:focus-visible,a:focus-visible,.photo-preview:focus-visible{
    outline:3px solid rgba(156,133,240,.38);
    outline-offset:2px;
}
.btn-login:active,.btn-register:active{transform:translateY(1px);}

@media(prefers-reduced-motion:reduce){
    .login-card,.reg-card{transition:none;}
}
.alert-success{display:flex;align-items:flex-start;gap:8px;border-radius:8px;padding:11px 14px;margin-bottom:18px;font-size:13px;line-height:1.45;background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.35);color:#6ee7b7;}
</style>
<style id="dean-login-match-ea">
/* Same card proportions as the Admin (EA) login: identical width, field height, button height,
   icon positions and spacing. The "Secured & Encrypted" note sits just under the card so it no
   longer makes the card taller than the EA one. */
.input-wrap .form-input{height:37px;min-height:37px;padding:0 46px}
.input-wrap .f-icon{left:14px;width:18px;text-align:center;z-index:2}
.input-wrap .toggle-pw{right:12px;z-index:3;width:26px;height:30px;padding:0;display:flex;align-items:center;justify-content:center}
.input-wrap .toggle-pw i{display:block}
.forgot-row{text-align:right;margin-top:-10px;margin-bottom:14px}
.forgot-row a{font-size:12px;color:var(--violet-hover);text-decoration:none;font-weight:600}
.forgot-row a:hover{text-decoration:underline}
.btn-login{height:38px;min-height:38px;padding:0 16px}
.login-card .card-footer{position:absolute;left:0;right:0;top:100%;margin:14px 0 0;padding:0;border-top:0}
@media(max-width:480px){.login-card{padding:36px 22px 30px}}
</style>

</head>
<body>
<div class="bg-grid" aria-hidden="true"></div>
<div class="bg-glow bg-glow-1" aria-hidden="true"></div>
<div class="bg-glow bg-glow-2" aria-hidden="true"></div>

<svg class="hex-deco hex-1" width="260" height="260" viewBox="0 0 260 260" aria-hidden="true">
    <polygon points="130,10 240,70 240,190 130,250 20,190 20,70" fill="none" stroke="#7C5FD9" stroke-width="1"/>
    <polygon points="130,50 200,90 200,170 130,210 60,170 60,90" fill="none" stroke="#7C5FD9" stroke-width="1"/>
</svg>
<svg class="hex-deco hex-2" width="300" height="300" viewBox="0 0 300 300" aria-hidden="true">
    <polygon points="150,10 280,80 280,220 150,290 20,220 20,80" fill="none" stroke="#2B6CB0" stroke-width="1"/>
    <polygon points="150,60 220,100 220,200 150,240 80,200 80,100" fill="none" stroke="#2B6CB0" stroke-width="1"/>
</svg>

<div class="login-card">
    <div class="card-header">
        <img class="logo-ring" src="../image/pbi_logo" alt="PBI Logo"/>
        <div class="card-subtitle">Employee Performance Evaluation & Management System</div>
        <div class="role-pill"><i class="fa-solid fa-user-tie"></i> Dean Access</div>
    </div>
    <div class="divider"></div>

    <?php if ($success): ?>
    <div class="alert alert-success" role="status"><i class="fa-solid fa-circle-check"></i><span><?= htmlspecialchars($success) ?></span></div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-error" role="alert">
        <i class="fa-solid fa-circle-exclamation"></i>
        <span><?= htmlspecialchars($error) ?></span>
    </div>
    <?php endif; ?>

    <form method="POST" action="dean_login.php" autocomplete="on" id="loginForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

        <div class="form-group">
            <label class="form-label" for="username">Username</label>
            <div class="input-wrap">
                <input class="form-input" type="text" id="username" name="username"
                       placeholder="Enter your username" required autocomplete="username"
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"/>
                <i class="fa-solid fa-user f-icon"></i>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label" for="password">Password</label>
            <div class="input-wrap">
                <input class="form-input" type="password" id="password" name="password"
                       placeholder="Enter your password" required autocomplete="current-password"/>
                <i class="fa-solid fa-lock f-icon"></i>
                <button type="button" class="toggle-pw" onclick="togglePw()" aria-label="Show password">
                    <i class="fa-solid fa-eye-slash" id="eyeIcon"></i>
                </button>
            </div>
        </div>

        <div class="forgot-row">
            <a href="forgot_password.php?restart=1" style="font-size:12px; color:var(--violet-hover); text-decoration:none; font-weight:600;">
                <i class="fa-solid fa-key"></i> Forgot Password?
            </a>
        </div>

        <button type="submit" class="btn-login" id="loginBtn">
            <i class="fa-solid fa-right-to-bracket"></i>
            <span>Sign In as Dean</span>
        </button>
    </form>

    <div class="register-row">
        New to the system? <a href="dean_register.php">Register here</a>
    </div>

    <div class="card-footer">
        <span class="secure-badge">
            <i class="fa-solid fa-circle-check"></i> Secured &amp; Encrypted Connection
        </span>
    </div>
</div>

<script>
function togglePw() {
    const pw = document.getElementById('password');
    const ic = document.getElementById('eyeIcon');
    const toggle = document.querySelector('.toggle-pw');
    const visible = pw.type === 'password';

    pw.type = visible ? 'text' : 'password';
    ic.className = visible ? 'fa-solid fa-eye' : 'fa-solid fa-eye-slash';
    if (toggle) toggle.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
}

const loginForm = document.getElementById('loginForm');
const loginBtn = document.getElementById('loginBtn');
if (loginForm && loginBtn) {
    loginForm.addEventListener('submit', () => {
        if (!loginForm.checkValidity()) return;
        loginBtn.disabled = true;
        loginBtn.querySelector('i').className = 'fa-solid fa-spinner fa-spin';
        loginBtn.querySelector('span').textContent = 'Signing In…';
    });
    window.addEventListener('pageshow', (e) => {
        if (!e.persisted) return;
        loginBtn.disabled = false;
        loginBtn.querySelector('i').className = 'fa-solid fa-right-to-bracket';
        loginBtn.querySelector('span').textContent = 'Sign In as Dean';
    });
}
</script>
</body>
</html>