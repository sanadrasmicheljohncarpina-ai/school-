<?php
// admin/admin_login.php
session_start();
require_once 'db.php';

// Already logged in → go straight to dashboard
if (!empty($_SESSION['user_id']) && in_array($_SESSION['user_role'] ?? '', ['superadmin'])) {
    header("Location: admin_dashboard.php");
    exit;
}

$error   = '';
$success = $_SESSION['reg_success'] ?? '';
unset($_SESSION['reg_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password']     ?? '';

    if ($username === '' || $password === '') {
        $error = "Please enter your username and password.";
    } else {
        // BINARY makes the username match case-sensitive: "Admin" will not log in as "admin".
        // (Passwords are already case-sensitive because they are checked with password_verify().)
        $stmt = $mysqli->prepare(
            "SELECT id, full_name, email, password_hash, role, is_logged_in
             FROM users WHERE BINARY username = ? AND role IN ('superadmin') AND is_active = 1 LIMIT 1"
        );
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role']      = $user['role'];

            $upd = $mysqli->prepare("UPDATE users SET is_logged_in = 1 WHERE id = ?");
            $upd->bind_param("i", $user['id']);
            $upd->execute();
            $upd->close();

            $mysqli->close();
            header("Location: admin_dashboard.php");
            exit;
        } else {
            $error = "Incorrect username or password. Please try again.";
        }
    }
    if ($mysqli->ping()) $mysqli->close();
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>PBI Admin — Secure Access</title>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<style>
:root{
    --dark-blue:#14100A;
    --blue-mid:#EFE6D0;
    --blue-inner:#E9DFC6;
    --blue-accent:#C9A227;
    --blue-hover:#9C7A12;
    --light:#2B2416;
    --muted:#7A6F58;
    --radius:10px;
    --shadow:0 8px 32px rgba(120,100,60,.18);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{min-height:100%;background:var(--dark-blue)}
body{
    min-height:100vh;
    background:
        radial-gradient(circle at 10% 10%,rgba(201,162,39,.16),transparent 32%),
        radial-gradient(circle at 90% 90%,rgba(240,202,90,.11),transparent 30%),
        var(--dark-blue);
    background-attachment:fixed;
    font-family:'DM Sans',sans-serif;color:var(--light);
    display:flex;align-items:center;justify-content:center;
    overflow:hidden;position:relative;padding:24px;
}

/* Decorative background + ambient lighting — same composition as the Student and
   Dean portals, kept in the Admin blue identity. */
body::before{
    content:"";position:fixed;inset:0;z-index:0;pointer-events:none;
    background:linear-gradient(180deg,rgba(20,15,8,.05),transparent 26%,rgba(120,100,60,.06));
}
.bg-grid{
    position:fixed;inset:0;z-index:0;pointer-events:none;
    background-image:
        repeating-linear-gradient(45deg,rgba(201,162,39,.075) 0,rgba(201,162,39,.075) 1px,transparent 1px,transparent 26px),
        repeating-linear-gradient(-45deg,rgba(201,162,39,.055) 0,rgba(201,162,39,.055) 1px,transparent 1px,transparent 26px);
}
.hex-deco{position:fixed;z-index:0;pointer-events:none;opacity:.5}
.hex-1{top:-60px;left:-60px}
.hex-2{bottom:-70px;right:-70px}

.login-card{
    position:relative;z-index:10;width:100%;max-width:420px;
    padding:48px 44px 40px;
    background:rgba(255,252,244,.92);
    backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px);
    border:1px solid rgba(20,15,8,.14);border-radius:18px;
    box-shadow:0 24px 70px rgba(120,100,60,.14),0 0 0 1px rgba(20,15,8,.04),0 0 90px rgba(201,162,39,.10);
    transition:border-color .3s ease,box-shadow .3s ease;
    animation:cardIn .7s cubic-bezier(.22,1,.36,1) both;
}
.login-card:hover{border-color:rgba(240,202,90,.30);box-shadow:0 24px 70px rgba(120,100,60,.14),0 0 0 1px rgba(20,15,8,.04),0 0 100px rgba(201,162,39,.17)}
@keyframes cardIn{from{opacity:0;transform:translateY(32px) scale(.97)}to{opacity:1;transform:none}}
.card-header{text-align:center;margin-bottom:28px}
.logo-img{
    width:72px;height:72px;border-radius:50%;object-fit:cover;display:block;
    border:2.5px solid var(--blue-accent);box-shadow:0 0 22px rgba(201,162,39,.42);
    margin:0 auto 16px;
}
.card-title{font-family:'Rajdhani',sans-serif;font-size:26px;font-weight:700;letter-spacing:2px;color:#1F1B12;text-transform:uppercase}
.card-subtitle{font-size:12px;color:var(--muted);letter-spacing:1.2px;text-transform:uppercase;margin-top:4px}
.divider{height:1px;background:linear-gradient(90deg,transparent,rgba(201,162,39,.40),transparent);margin-bottom:24px}
.form-group{margin-bottom:18px}
.form-label{display:block;font-size:11px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:var(--muted);margin-bottom:7px}
.input-wrap{position:relative}
.f-icon{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:14px;pointer-events:none;transition:color .2s}
.form-input{
    width:100%;padding:12px 42px 12px 40px;min-height:50px;
    background:rgba(0,0,0,.045);border:1px solid rgba(20,15,8,.12);border-radius:var(--radius);
    color:var(--light);font:500 14px 'DM Sans',sans-serif;outline:none;
    transition:border-color .25s,box-shadow .25s,background .25s;
}
.form-input::placeholder{color:rgba(122,111,88,.75)}
.form-input:hover:not(:focus){border-color:rgba(240,202,90,.42)}
.form-input:focus{border-color:var(--blue-accent);background:rgba(0,0,0,.06);box-shadow:0 0 0 3px rgba(201,162,39,.20)}
.input-wrap:focus-within .f-icon{color:var(--blue-hover)}
.toggle-pw{position:absolute;right:13px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--muted);cursor:pointer;font-size:14px;padding:5px;line-height:1}
.toggle-pw:hover{color:var(--blue-hover)}
.alert{display:flex;align-items:flex-start;gap:8px;border-radius:8px;padding:11px 14px;font-size:13px;line-height:1.45;margin-bottom:18px}
.alert-error{background:rgba(240,84,84,.12);border:1px solid rgba(240,84,84,.35);color:#C0392B}
.alert-success{background:rgba(34,197,94,.10);border:1px solid rgba(34,197,94,.28);color:#15803D}
.forgot-row{text-align:right;margin-top:-10px;margin-bottom:14px}
.forgot-row a{font-size:12px;color:var(--blue-hover);text-decoration:none;font-weight:600}
.forgot-row a:hover{text-decoration:underline}
.btn-main{
    width:100%;min-height:52px;padding:13px;background:var(--blue-accent);border:none;border-radius:var(--radius);
    color:#fff;font:600 15px 'DM Sans',sans-serif;cursor:pointer;
    display:flex;align-items:center;justify-content:center;gap:8px;
    box-shadow:0 4px 16px rgba(201,162,39,.40);
    transition:background .2s,transform .15s,box-shadow .2s;
}
.btn-main:hover{background:var(--blue-hover);transform:translateY(-1px)}
.btn-main:active{transform:translateY(1px)}
.register-row{text-align:center;margin-top:16px;font-size:13px;color:var(--muted)}
.register-row a{color:var(--blue-hover);font-weight:600;text-decoration:none;text-underline-offset:3px}
.register-row a:hover{text-decoration:underline}
.card-footer{text-align:center;margin-top:18px;padding-top:14px;border-top:1px solid rgba(20,15,8,.08);font-size:12px;color:var(--muted)}
.secure-badge{display:inline-flex;align-items:center;gap:5px;font-size:11px;color:var(--muted)}
.secure-badge i{color:#16A34A;font-size:10px}

button:focus-visible,a:focus-visible,.photo-preview:focus-visible{outline:3px solid rgba(240,202,90,.38);outline-offset:2px}
@media(prefers-reduced-motion:reduce){.login-card,.reg-card{animation:none;transition:none}.btn-main,.photo-preview{transition:none}}
@media(max-width:480px){body{padding:16px}.login-card{padding:36px 22px 30px}.card-title{font-size:24px}}
@media(max-height:700px){body{overflow:auto}}

/* Final input icon alignment fix: keep icons inside their own visual space. */
.input-wrap .f-icon{
    left:14px!important;
    z-index:2!important;
    width:18px!important;
    text-align:center!important;
}
.input-wrap .form-input{
    padding-left:46px!important;
    padding-right:46px!important;
}
.input-wrap .toggle-pw{
    right:12px!important;
    z-index:3!important;
    width:26px!important;
    height:30px!important;
    display:flex!important;
    align-items:center!important;
    justify-content:center!important;
}
.input-wrap .toggle-pw i{display:block!important;}
</style>

<link rel="stylesheet" href="admin_appearance.css">
<script src="admin_appearance.js"></script>

<style id="pbi-auth-navy-theme">
/* PBI Admin authentication theme — matched to the Executive Assistant
   navigation/sidebar navy palette shown in the current UI reference. */
:root{
    --auth-navy:#0F1E33;
    --auth-navy-deep:#091727;
    --auth-navy-panel:#12263E;
    --auth-navy-input:#0B1B2E;
    --auth-border:#29405A;
    --auth-border-soft:rgba(152,178,207,.18);
    --auth-text:#E7EEF7;
    --auth-muted:#9AAFC5;
    --auth-gold:#F2C94C;
    --auth-gold-hover:#FFD866;
    --auth-blue:#2F6EE2;
    --auth-blue-hover:#3D7BF0;
}

html,body{background:var(--auth-navy-deep)!important;color:var(--auth-text)!important;}
html{color-scheme:dark!important;}
body{
    background:
        radial-gradient(circle at 10% 8%,rgba(47,110,226,.13),transparent 30%),
        radial-gradient(circle at 90% 90%,rgba(242,201,76,.08),transparent 28%),
        repeating-linear-gradient(45deg,rgba(79,129,184,.045) 0,rgba(79,129,184,.045) 1px,transparent 1px,transparent 26px),
        repeating-linear-gradient(-45deg,rgba(79,129,184,.035) 0,rgba(79,129,184,.035) 1px,transparent 1px,transparent 26px),
        var(--auth-navy-deep)!important;
}
body::before{background:linear-gradient(180deg,rgba(15,30,51,.12),transparent 30%,rgba(47,110,226,.035))!important;}
.bg-grid{
    background-image:
        repeating-linear-gradient(45deg,rgba(79,129,184,.055) 0,rgba(79,129,184,.055) 1px,transparent 1px,transparent 26px),
        repeating-linear-gradient(-45deg,rgba(79,129,184,.04) 0,rgba(79,129,184,.04) 1px,transparent 1px,transparent 26px)!important;
}

.login-card,.reg-card{
    background:rgba(15,30,51,.96)!important;
    border:1px solid rgba(152,178,207,.18)!important;
    box-shadow:0 24px 70px rgba(0,0,0,.45),0 0 0 1px rgba(255,255,255,.025),0 0 80px rgba(47,110,226,.07)!important;
    color:var(--auth-text)!important;
}
.login-card:hover,.reg-card:hover{
    border-color:rgba(98,144,201,.36)!important;
    box-shadow:0 24px 70px rgba(0,0,0,.48),0 0 0 1px rgba(255,255,255,.025),0 0 90px rgba(47,110,226,.11)!important;
}

.card-title{color:#F3F7FB!important;}
.card-subtitle{color:#92A7BF!important;text-wrap:balance;}
.card-header .card-subtitle{margin-top:0;}
.role-pill{
    display:inline-flex;align-items:center;justify-content:center;gap:6px;
    margin-top:10px;padding:4px 14px;border-radius:20px;
    background:rgba(47,110,226,.14);border:1px solid rgba(98,144,230,.42);
    color:#8DB4FF;font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;
}
.divider{background:linear-gradient(90deg,transparent,rgba(93,145,204,.36),transparent)!important;}
.form-label{color:#98ADC3!important;}
.required,.req{color:#F87171!important;}

.form-input{
    background:var(--auth-navy-input)!important;
    border-color:var(--auth-border)!important;
    color:var(--auth-text)!important;
}
.form-input::placeholder{color:rgba(154,175,197,.50)!important;}
.form-input:hover:not(:focus){
    background:#0D2035!important;
    border-color:#345473!important;
}
.form-input:focus{
    background:#0D2035!important;
    border-color:var(--auth-blue)!important;
    box-shadow:0 0 0 3px rgba(47,110,226,.20)!important;
}
.f-icon,.toggle-pw{color:#92A7BF!important;}
.input-wrap:focus-within .f-icon,.toggle-pw:hover{color:#B7C9DB!important;}

.forgot-row a,.register-row a,.card-footer a{color:var(--auth-gold)!important;}
.forgot-row a:hover,.register-row a:hover,.card-footer a:hover{color:var(--auth-gold-hover)!important;}

.btn-main{
    background:var(--auth-blue)!important;
    box-shadow:0 8px 22px rgba(47,110,226,.30)!important;
}
.btn-main:hover{
    background:var(--auth-blue-hover)!important;
    box-shadow:0 10px 28px rgba(47,110,226,.38)!important;
}

.alert-error{
    background:rgba(248,113,113,.10)!important;
    border-color:rgba(248,113,113,.28)!important;
    color:#FCA5A5!important;
}
.alert-success{
    background:rgba(34,197,94,.09)!important;
    border-color:rgba(34,197,94,.24)!important;
    color:#86EFAC!important;
}

.card-footer{border-top-color:rgba(152,178,207,.12)!important;color:var(--auth-muted)!important;}
.secure-badge{color:#8FA6BE!important;}
.secure-badge i{color:#4ADE80!important;}

.photo-upload-area{
    background:rgba(10,27,46,.78)!important;
    border-color:rgba(152,178,207,.17)!important;
}
.photo-preview{
    background:#0A1B2E!important;
    border-color:rgba(79,129,184,.55)!important;
}
.photo-preview:hover{border-color:var(--auth-blue)!important;box-shadow:0 0 0 4px rgba(47,110,226,.12)!important;}
.photo-preview .ph-icon{color:#91A8BF!important;}
.photo-preview:hover .ph-icon{color:#BDD0E4!important;}
.photo-info p{color:#E6EDF6!important;}
.photo-info span{color:#8FA6BE!important;}
.btn-photo{
    background:rgba(242,201,76,.08)!important;
    border-color:rgba(242,201,76,.30)!important;
    color:var(--auth-gold)!important;
}
.btn-photo:hover{
    background:rgba(242,201,76,.14)!important;
    border-color:rgba(242,201,76,.50)!important;
}

.pw-strength{background:rgba(152,178,207,.12)!important;}
.pw-hint{color:#8FA6BE!important;}

button:focus-visible,a:focus-visible,.photo-preview:focus-visible{
    outline:3px solid rgba(79,144,231,.35)!important;
    outline-offset:2px;
}
</style>

</head>
<body>
<div class="bg-grid" aria-hidden="true"></div>

<svg class="hex-deco hex-1" width="260" height="260" viewBox="0 0 260 260" aria-hidden="true"><polygon points="130,10 240,70 240,190 130,250 20,190 20,70" fill="none" stroke="#C9A227" stroke-width="1"/><polygon points="130,50 200,90 200,170 130,210 60,170 60,90" fill="none" stroke="#C9A227" stroke-width="1"/></svg>
<svg class="hex-deco hex-2" width="300" height="300" viewBox="0 0 300 300" aria-hidden="true"><polygon points="150,10 280,80 280,220 150,290 20,220 20,80" fill="none" stroke="#9C7A12" stroke-width="1"/><polygon points="150,60 220,100 220,200 150,240 80,200 80,100" fill="none" stroke="#9C7A12" stroke-width="1"/></svg>

<div class="login-card">
    <div class="card-header">
        <img class="logo-img" src="../image/pbi_logo" alt="PBI Logo"
             onerror="this.style.display='none'"/>
        <div class="card-subtitle">Employee Performance Evaluation &amp; Management System</div>
        <div class="role-pill"><i class="fa-solid fa-user-shield"></i> Admin Access</div>
    </div>
    <div class="divider"></div>

    <?php if ($success): ?>
    <div class="alert alert-success">
        <i class="fa-solid fa-circle-check"></i>
        <?= htmlspecialchars($success) ?>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-error">
        <i class="fa-solid fa-circle-exclamation"></i>
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="admin_login.php" autocomplete="off">
        <div class="form-group">
            <label class="form-label">Username</label>
            <div class="input-wrap">
                <i class="fa-solid fa-user f-icon"></i>
                <input class="form-input" type="text" name="username"
                       placeholder="Enter admin username" required autocomplete="off"/>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Password</label>
            <div class="input-wrap">
                <i class="fa-solid fa-lock f-icon"></i>
                <input class="form-input" type="password" id="lp" name="password"
                       placeholder="Enter your password" required autocomplete="new-password"/>
                <button type="button" class="toggle-pw" id="togglePassword" onclick="togglePw()" aria-label="Show password" title="Show password">
                    <i class="fa-solid fa-eye-slash" id="eyeIcon"></i>
                </button>
            </div>
        </div>
        
<div class="forgot-row">
    <a href="forgot_password.php" style="font-size:12px; color:var(--blue-hover); text-decoration:none; font-weight:600;">
        <i class="fa-solid fa-key"></i> Forgot Password?
    </a>
</div>
        <button type="submit" class="btn-main">
            <i class="fa-solid fa-shield-halved"></i> Sign In
        </button>
    </form>

    <div class="register-row">
        Don't have an account? <a href="admin_register.php">Register here</a>
    </div>
</div>

<script>
function togglePw() {
    const el = document.getElementById('lp'), ic = document.getElementById('eyeIcon');
    const btn = document.getElementById('togglePassword');
    const showing = el.type === 'password';
    el.type = showing ? 'text' : 'password';
    ic.className = showing ? 'fa-solid fa-eye' : 'fa-solid fa-eye-slash';
    if (btn) {
        btn.setAttribute('aria-label', showing ? 'Hide password' : 'Show password');
        btn.setAttribute('title', showing ? 'Hide password' : 'Show password');
    }
}
</script>
</body>
</html>