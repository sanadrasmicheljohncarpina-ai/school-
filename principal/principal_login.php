<?php
// principal/principal_login.php
session_start();
require_once 'db.php';

$error = '';
$success = $_SESSION['reset_success'] ?? '';
unset($_SESSION['reset_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        $error = 'Session expired. Please try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $error = 'Please enter both username and password.';
        } else {
            // Principal authentication is handled locally here so this login
            // page does not depend on a missing shared ems_* helper.
            $stmt = $mysqli->prepare(
                "SELECT id, full_name, email, username, password_hash, role, is_active, account_status
                 FROM users
                 WHERE BINARY username = BINARY ?
                   AND role = 'principal'
                 LIMIT 1"
            );

            if (!$stmt) {
                $error = 'Unable to process the login right now. Please try again.';
            } else {
                $stmt->bind_param('s', $username);
                $stmt->execute();
                $user = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                // A blocked account is only reported after the correct password is given,
                // so usernames cannot be probed by anyone who does not know the password.
                $passwordOk = $user && password_verify($password, $user['password_hash']);
                $isBlocked  = $passwordOk && (
                    $user['account_status'] === 'blocked'
                    || ((int)$user['is_active'] === 0 && $user['account_status'] === 'approved')
                );

                if ($isBlocked) {
                    $error = 'This account has been blocked by the Executive Assistant. Please contact the administrator.';
                } elseif ($passwordOk && (int)$user['is_active'] === 1 && $user['account_status'] === 'approved') {
                    session_regenerate_id(true);

                    $_SESSION['user_id']   = (int)$user['id'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['email']     = $user['email'] ?? '';
                    $_SESSION['username']  = $user['username'];
                    $_SESSION['role']      = 'principal';

                    $upd = $mysqli->prepare("UPDATE users SET is_logged_in = 1 WHERE id = ?");
                    if ($upd) {
                        $upd->bind_param('i', $user['id']);
                        $upd->execute();
                        $upd->close();
                    }

                    header('Location: principal_dashboard.php');
                    exit;
                } else {
                    $error = 'Incorrect registered username or password, or your principal account is not yet approved.';
                }
            }
        }
    }
}

$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>PBI — Principal Workspace</title>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<style>
:root{--dark:#0A192F;--mid:#172A45;--amber:#D99A2B;--amber-h:#F0B84D;--light:#E0E6F0;--muted:#A0B3C6;--radius:10px;--shadow:0 8px 32px rgba(0,0,0,0.45);}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{min-height:100vh;background:#0A192F url('../bacjground.png') center center / cover no-repeat fixed;font-family:'DM Sans',sans-serif;color:var(--light);display:flex;align-items:center;justify-content:center;overflow:hidden;position:relative;}
.bg-image-overlay{position:fixed;inset:0;z-index:0;background:rgba(10,25,47,.42);pointer-events:none;}
.bg-grid{display:none;position:fixed;inset:0;z-index:0;background-image:linear-gradient(rgba(217,154,43,.06) 1px,transparent 1px),linear-gradient(90deg,rgba(217,154,43,.06) 1px,transparent 1px);background-size:48px 48px;animation:g 22s linear infinite;}
@keyframes g{0%{background-position:0 0}100%{background-position:48px 48px}}
.orb{display:none;position:fixed;border-radius:50%;filter:blur(90px);z-index:0;pointer-events:none;}
.orb-1{width:380px;height:380px;background:radial-gradient(circle,rgba(217,154,43,.2) 0%,transparent 70%);top:-80px;right:-80px;animation:o1 14s ease-in-out infinite;}
.orb-2{width:300px;height:300px;background:radial-gradient(circle,rgba(43,108,176,.15) 0%,transparent 70%);bottom:-60px;left:-60px;animation:o2 18s ease-in-out infinite;}
@keyframes o1{0%,100%{transform:translate(0,0)}50%{transform:translate(-30px,25px)}}
@keyframes o2{0%,100%{transform:translate(0,0)}50%{transform:translate(25px,-20px)}}
.login-card{position:relative;z-index:10;background:rgba(23,42,69,.85);backdrop-filter:blur(18px);border:1px solid rgba(255,255,255,.09);border-radius:18px;padding:48px 44px 40px;width:100%;max-width:430px;box-shadow:var(--shadow),0 0 0 1px rgba(217,154,43,.15);animation:cardIn .7s cubic-bezier(.22,1,.36,1) both;}
@keyframes cardIn{from{opacity:0;transform:translateY(32px) scale(.97)}to{opacity:1;transform:none}}
.card-header{text-align:center;margin-bottom:28px;}
.logo-ring{width:76px;height:76px;border-radius:50%;display:block;object-fit:cover;border:2.5px solid var(--amber);box-shadow:0 0 22px rgba(217,154,43,.45);margin:0 auto 16px;}
.card-title{font-family:'Rajdhani',sans-serif;font-size:26px;font-weight:700;letter-spacing:2px;color:#fff;text-transform:uppercase;}
.card-subtitle{font-size:12px;color:var(--muted);letter-spacing:1.2px;text-transform:uppercase;margin-top:4px;}
.role-pill{display:inline-flex;align-items:center;gap:6px;background:rgba(217,154,43,.15);border:1px solid rgba(217,154,43,.35);color:var(--amber-h);font-size:11px;font-weight:700;padding:4px 14px;border-radius:20px;text-transform:uppercase;letter-spacing:.8px;margin-top:10px;}
.divider{height:1px;background:linear-gradient(90deg,transparent,rgba(217,154,43,.45),transparent);margin-bottom:24px;}
.form-group{margin-bottom:18px;}
.form-label{display:block;font-size:11px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:var(--muted);margin-bottom:7px;}
.input-wrap{position:relative;}
.f-icon{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:14px;pointer-events:none;}
.form-input{width:100%;padding:12px 14px 12px 40px;background:rgba(10,25,47,.7);border:1px solid rgba(255,255,255,.1);border-radius:var(--radius);color:var(--light);font-size:14px;font-family:'DM Sans',sans-serif;outline:none;transition:border-color .25s,box-shadow .25s;}
.form-input::placeholder{color:rgba(160,179,198,.45);}
.form-input:focus{border-color:var(--amber);box-shadow:0 0 0 3px rgba(217,154,43,.2);}
.toggle-pw{position:absolute;right:13px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--muted);cursor:pointer;font-size:14px;padding:0;}
.alert{border-radius:8px;padding:11px 14px;font-size:13px;margin-bottom:18px;display:flex;align-items:center;gap:8px;}
.alert-error{background:rgba(240,84,84,.12);border:1px solid rgba(240,84,84,.35);color:#ff8a8a;}
.btn-login{width:100%;padding:13px;background:var(--amber);border:none;border-radius:var(--radius);color:#1a1204;font-size:15px;font-weight:600;font-family:'DM Sans',sans-serif;cursor:pointer;transition:background .2s,transform .15s;box-shadow:0 4px 16px rgba(217,154,43,.4);display:flex;align-items:center;justify-content:center;gap:8px;}
.btn-login:hover{background:var(--amber-h);transform:translateY(-1px);}
.register-row{text-align:center;margin-top:14px;font-size:13px;color:var(--muted);}
.register-row a{color:var(--amber-h);font-weight:600;text-decoration:none;}
.register-row a:hover{text-decoration:underline;}
.card-footer{text-align:center;margin-top:14px;font-size:12px;color:var(--muted);border-top:1px solid rgba(255,255,255,.06);padding-top:14px;}
.secure-badge{display:inline-flex;align-items:center;gap:5px;font-size:11px;color:var(--muted);}
.secure-badge i{color:#4ade80;font-size:10px;}
@media(max-width:480px){.login-card{padding:36px 20px 32px;margin:16px;}}
</style>

<style id="white-theme-override">
:root{--dark:#ffffff;--mid:#ffffff;--inner:#f5f7fb;--light:#172033;--muted:#64748b;--shadow:0 4px 18px rgba(15,23,42,.08);}
html,body{background:#fff!important;color:#172033!important;}
body{background-image:none!important;}
main,.main-content,.content,.page-content{background:#fff!important;color:#172033!important;}
.sidebar{background:#0A192F!important;color:#E0E6F0!important;border-right:1px solid #172A45!important;}
.sidebar *,.sidebar a{color:inherit;}
.stat-card,.section,.period-strip,.filter-bar,.card,.panel,.table-wrap,.modal-content{background:#fff!important;color:#172033!important;border-color:#e2e8f0!important;box-shadow:var(--shadow)!important;}
h1,h2,h3,h4,h5,h6,.section-title,.page-title{color:#0f172a!important;}
p,span,label,td,th,small{color:inherit;}
table{color:#172033!important;}
table thead th{background:#f8fafc!important;color:#334155!important;border-color:#e2e8f0!important;}
table tbody td{border-color:#e2e8f0!important;}
input,select,textarea{background:#fff!important;color:#172033!important;border-color:#cbd5e1!important;}
.search-box input[type=text],.search-box select,.form-group input,.filter-field select,.filter-field input[type=text]{background:#fff!important;color:#172033!important;}
.btn-reset{color:#475569!important;border-color:#cbd5e1!important;}
.notif-list li{background:#f8fafc!important;}
</style>

<style id="principal-subtle-feature-background">
/* Subtle light backdrop for every Principal feature area. Feature cards remain white; sidebar stays navy. */
html{background:#F8FAFC !important;}
body{background:#F8FAFC !important;}
.main, main.main, .main-content, .content, .page-content{background:#F8FAFC !important;}
.page-header,.eval-switcher,.eval-banner,.group-tab-wrap,.group-tabs,.desig-subtabs,.faculty-subtabs,
.card,.panel,.section,.table-card,.content-card,.stat-card,.info-banner,.gl-card,.amber-card,
.green-card,.red-card,.ra-table-card,.table-wrap,.table-card-wrap,.content-panel,.history-card,
target-card,.eval-card,.comment-section,.avg-summary,.standing-panel,.person-row,.no-evaluated,
.no-archived,.evaluator-grid,.people-list,.cat-section,.period-strip,.filter-bar{background:#FFFFFF !important;}
@media print{html,body,.main,main.main,.main-content,.content,.page-content{background:#fff !important;}}
</style>

<style id="principal-workspace-sharper-final">
/* Final Principal workspace composition: white outer page + sharper light workspace. */
html{background:#FFFFFF!important;color-scheme:light!important;}
body{background:#FFFFFF!important;background-image:none!important;color:#172033!important;}
.sidebar{background:#0A192F!important;}
.main,main.main{
  position:relative!important;
  background:#F3F6FA!important;
  color:#172033!important;
  border:1px solid #E5EAF0!important;
  border-bottom:0!important;
  border-radius:16px 16px 0 0!important;
  box-shadow:none!important;
}
.main > .page-header,main.main > .page-header{
  background:transparent!important;
  border:0!important;
  box-shadow:none!important;
}
.main .page-header,.main .section,.main .card,.main .panel,.main .table-card,.main .content-card,.main .stat-card,
.main .period-strip,.main .filter-bar,.main .table-wrap,.main .table-card-wrap,.main .content-panel,
.main .eval-banner,.main .info-banner,.main .history-card,.main .gl-card,.main .amber-card,.main .green-card,
.main .red-card,.main .ra-table-card,.main .eval-card,.main .comment-section,.main .avg-summary,.main .standing-panel,
.main .person-row,.main .target-card,.main .no-eval,.main .no-data,.main .no-evaluated,.main .no-archived,
.main .evaluator-grid,.main .people-list,.main .cat-section,.main .results-card,.main .result-card,.main .feature-card{
  background:#FFFFFF!important;
  color:#172033!important;
  border-color:#D9E4EF!important;
  box-shadow:0 1px 5px rgba(15,23,42,.035)!important;
}
.main table thead th,.main .table-head,.main .table-header,.main .thead,.main .subtle-head{
  background:#F4F8FF!important;color:#4B6580!important;border-color:#D9E4EF!important;
}
@media(max-width:768px){
  .main,main.main{margin:12px 12px 0!important;padding:20px 18px 28px!important;border-radius:12px 12px 0 0!important;}
}
@media print{
  html,body,.main,main.main{background:#FFFFFF!important;border-color:transparent!important;}
}
</style>
</head>
<body>
<div class="bg-grid"></div>
<div class="portal-hex portal-hex-gold" aria-hidden="true">
    <svg viewBox="0 0 300 300" role="presentation"><polygon points="150,18 269,86 269,214 150,282 31,214 31,86"/></svg>
</div>
<div class="portal-hex portal-hex-blue" aria-hidden="true">
    <svg viewBox="0 0 300 300" role="presentation"><polygon points="150,18 269,86 269,214 150,282 31,214 31,86"/></svg>
</div>

<div class="login-card">
    <div class="card-header">
        <img class="logo-ring" src="../image/pbi_logo" alt="PBI Logo"/>
        <div class="card-subtitle">Employee Performance Evaluation & Management System</div>
        <div class="role-pill"><i class="fa-solid fa-user-tie"></i> Principal Access</div>
    </div>
    <div class="divider"></div>

    <?php if ($success): ?>
    <div class="alert alert-success" role="status"><i class="fa-solid fa-circle-check"></i><span><?= htmlspecialchars($success) ?></span></div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-error">
        <i class="fa-solid fa-circle-exclamation"></i>
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="principal_login.php" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

        <div class="form-group">
            <label class="form-label">Username</label>
            <div class="input-wrap">
                <input class="form-input" type="text" name="username"
                       placeholder="Enter your username" required autocomplete="off"
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"/>
                <i class="fa-solid fa-user f-icon"></i>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Password</label>
            <div class="input-wrap">
                <input class="form-input" type="password" id="password" name="password"
                       placeholder="Enter your password" required autocomplete="new-password"/>
                <i class="fa-solid fa-lock f-icon"></i>
                <button type="button" class="toggle-pw" onclick="togglePw()" aria-label="Show password" title="Show password">
                    <i class="fa-solid fa-eye-slash" id="eyeIcon"></i>
                </button>
            </div>
        </div>
        <div style="text-align:right; margin-top:-8px; margin-bottom:16px;">
            <a href="forgot_password.php?restart=1" style="font-size:12px; color:var(--amber-h); text-decoration:none; font-weight:600;">
                <i class="fa-solid fa-key"></i> Forgot Password?
            </a>
        </div>

        <button type="submit" class="btn-login">
            <i class="fa-solid fa-right-to-bracket"></i> Sign In as Principal
        </button>
    </form>

    <div class="register-row">
        New to the system? <a href="principal_register.php">Register here</a>
    </div>

    <div class="card-footer">
        <span class="secure-badge">
            <i class="fa-solid fa-circle-check"></i> Secured &amp; Encrypted Connection
        </span>
    </div>
</div>

<script>
function togglePw() {
    const pw = document.getElementById('password'), ic = document.getElementById('eyeIcon');
    const showing = pw.type === 'password';
    pw.type = showing ? 'text' : 'password';
    ic.className = showing ? 'fa-solid fa-eye' : 'fa-solid fa-eye-slash';
    const label = showing ? 'Hide password' : 'Show password';
    const btn = ic.closest('.toggle-pw');
    if (btn) { btn.setAttribute('aria-label', label); btn.setAttribute('title', label); }
}
</script>
<style id="principal-white-theme-final">
:root{
  --dark:#ffffff!important;
  --mid:#ffffff!important;
  --inner:#f5f7fb!important;
  --light:#172033!important;
  --muted:#64748b!important;
  --border:#e2e8f0!important;
  --shadow:0 4px 18px rgba(15,23,42,.08)!important;
  --page-bg:#ffffff!important;
  --card-bg:#ffffff!important;
  --card-border:#e2e8f0!important;
}
html{background:#fff!important;color-scheme:light!important;}
body{background:#fff!important;background-image:none!important;color:#172033!important;}

/* Keep the existing navy sidebar; the content area is the white-theme area. */
.sidebar{background:#0A192F!important;color:#E0E6F0!important;border-right:1px solid #172A45!important;}
.sidebar *{color:inherit;}
.sidebar .sb-name{color:#fff!important;}
.sidebar .sb-role,.sidebar .sb-nav a i{color:#f0b84d!important;}
.sidebar .sb-scope,.sidebar .sb-nav a{color:#A0B3C6!important;}
.sidebar .sb-nav a:hover,.sidebar .sb-nav a.active{background:rgba(217,154,43,.15)!important;color:#fff!important;}
.sidebar .sb-logout a{color:#fca5a5!important;}

/* Main content surfaces */
main,.main,.main-content,.content,.page-content{background:#fff!important;color:#172033!important;}
.stat-card,.section,.period-strip,.filter-bar,.card,.panel,.table-wrap,
.content-card,.table-card,.summary-card,.sum-card,.standing-panel,.eval-card,
.eval-banner,.info-banner,.history-card,.gl-card,.amber-card,.green-card,.red-card,
.person-row,.target-card,.no-eval,.no-data,.no-evaluated,.comment-section,
.avg-summary,.cat-section,.people-list,.evaluator-grid,.eval-q-card,.eval-table-wrap,
.received-item,.q-result,.info-grid>div,.comment-modal,.ra-table-card,
.eval-switcher,.tabs,.level-tabs,.status-tabs,.faculty-subtabs{
  background:#fff!important;
  color:#172033!important;
  border-color:#e2e8f0!important;
  box-shadow:var(--shadow)!important;
}

/* Headings and readable data text */
.page-title,.page-header h1,.section-title,.sheet-name,.target-name,
h1,h2,h3,h4,h5,h6,.section h2,.eval-modal-title,.modal-section-title,
.stat-card .num,.tracker-item .big,.eval-q-text,.eval-qtext,.q-text,.info-value,
.received-anon,.cat-name-modal,.cat-score-modal{color:#0f172a!important;}
.page-sub,.sheet-desig,.target-desig,.muted,.hint,.helper,.filter-hint,.empty-note,
.main p,.main label,.main td,.main li,.main small,.q-score,.q-no,.received-meta,
.info-label,.loading-eval,.eval-rating-scale-note,.eval-read-score{color:#64748b!important;}
table{color:#172033!important;}
table thead th,table.data th,.q-table th{background:#f8fafc!important;color:#334155!important;border-color:#e2e8f0!important;}
table tbody td,table.data td,.q-table td{color:#334155!important;border-color:#e2e8f0!important;}
table tbody tr:hover,.person-row:hover,.standing-item:hover{background:#f8fafc!important;}

/* Accent elements stay amber/semantic rather than reverting to dark-mode text. */
.stat-card i,.section h2 i,.eval-q-category,.eval-category-heading,
.eval-modal-title i,.period-item .v,.period-badge,.back-link,
.stat-card .label,.period-item .k,.received-score,.score-big,.cat-score-modal,
.bell-btn,.bell-head button,.bell-list li i,.eval-banner-title,.eval-banner-icon,
.cat-title,.comment-title,.search-box button,.section h2 i{color:#d99a2b!important;}
.period-badge{background:rgba(217,154,43,.12)!important;border-color:rgba(217,154,43,.28)!important;}
.period-badge.closed{background:rgba(240,84,84,.10)!important;border-color:rgba(240,84,84,.28)!important;color:#dc2626!important;}
.period-badge.gray{background:#f1f5f9!important;border-color:#cbd5e1!important;color:#64748b!important;}

/* Forms */
input,select,textarea,
.search-box input[type=text],.search-box select,.form-group input,
.filter-field select,.filter-field input[type=text],.ra-filter-row select,.ra-search,
.eval-comment-box{
  background:#fff!important;color:#172033!important;border-color:#cbd5e1!important;
}
input::placeholder,textarea::placeholder{color:#94a3b8!important;}
input:focus,select:focus,textarea:focus{border-color:#d99a2b!important;box-shadow:0 0 0 3px rgba(217,154,43,.10)!important;outline:none!important;}

/* Buttons / tabs */
.report-btns button,.qa-btns a,.filter-btns a,a.btn,
.btn,.action-btn,.back-btn,.btn-print,.btn-archive,.btn-restore,.btn-solid,
.btn-archived-link,.ra-tool-btn,.page-btn{
  background:rgba(217,154,43,.10)!important;
  border-color:rgba(217,154,43,.30)!important;
  color:#a16207!important;
}
.report-btns button:hover,.qa-btns a:hover,.filter-btns a:hover,a.btn:hover,
.btn:hover,.action-btn:hover,.back-btn:hover,.btn-print:hover,.btn-archive:hover,
.btn-restore:hover,.btn-archived-link:hover,.ra-tool-btn:hover,.page-btn:hover{
  background:rgba(217,154,43,.16)!important;color:#92400e!important;
}
.btn-primary,.btn-solid{background:#d99a2b!important;color:#0A192F!important;border-color:#d99a2b!important;}
.btn-primary:hover,.btn-solid:hover{background:#f0b84d!important;color:#0A192F!important;}
.report-btns a.active,.filter-btns a.active,.group-tab.active,
.eval-tab.student.active,.eval-tab.peer.active,.eval-tab.multi-role.active,
.tab.active,.level-tab.active,.status-tab.active,.desig-subtab.active,
.page-btn.active{background:rgba(217,154,43,.14)!important;color:#a16207!important;border-color:rgba(217,154,43,.35)!important;}
.eval-tab,.tab,.level-tab,.status-tab,.group-tab,.desig-subtab{color:#64748b!important;background:transparent!important;}
.eval-tab:hover,.tab:hover,.level-tab:hover,.status-tab:hover,.group-tab:hover,.desig-subtab:hover{color:#334155!important;background:#f8fafc!important;}

/* Evaluation questionnaire */
.eval-q-card,.eval-table-wrap{background:#fff!important;}
.eval-rating-opt{background:#f8fafc!important;color:#334155!important;border-color:#cbd5e1!important;}
.eval-rating-opt:has(input:checked){background:rgba(217,154,43,.10)!important;color:#92400e!important;border-color:#d99a2b!important;}
.eval-table th{background:#f8fafc!important;color:#64748b!important;border-color:#e2e8f0!important;}
.eval-table td{border-color:#e2e8f0!important;color:#334155!important;}
.eval-rating-cell label{background:#fff!important;color:#64748b!important;border-color:#cbd5e1!important;}
.eval-rating-cell label:hover{background:rgba(217,154,43,.08)!important;border-color:#d99a2b!important;}
.eval-rating-cell input:checked + label{background:#d99a2b!important;color:#fff!important;border-color:#d99a2b!important;}
.eval-qno{color:#b8801f!important;}
.eval-comment-box{color:#334155!important;}

/* Status pills */
.pill.good{background:rgba(16,185,129,.12)!important;color:#047857!important;}
.pill.warn{background:rgba(217,154,43,.12)!important;color:#a16207!important;}
.pill.bad{background:rgba(240,84,84,.10)!important;color:#b91c1c!important;}
.alert.success{background:rgba(16,185,129,.10)!important;color:#047857!important;border-color:rgba(16,185,129,.25)!important;}
.alert.error{background:rgba(240,84,84,.10)!important;color:#b91c1c!important;border-color:rgba(240,84,84,.25)!important;}

/* Progress bars */
.bar-wrap,.score-bar-bg,.eval-bar-bg,.avg-bar-bg,.cat-bar{background:#e2e8f0!important;}
.bar-fill,.avg-bar-fill,.cat-bar>div{background:linear-gradient(90deg,#b8801f,#f0b84d)!important;}

/* Notifications */
.notif-list li,.bell-list li{background:#f8fafc!important;color:#334155!important;border-color:#e2e8f0!important;}
.notif-list li.unseen,.bell-list li.unseen{background:rgba(217,154,43,.08)!important;}
.bell-btn{background:#fff!important;border-color:#cbd5e1!important;color:#d99a2b!important;}
.bell-btn:hover,.bell-btn[aria-expanded="true"]{background:rgba(217,154,43,.10)!important;border-color:rgba(217,154,43,.35)!important;}
.bell-panel{background:#fff!important;border-color:#e2e8f0!important;box-shadow:0 18px 44px rgba(15,23,42,.16)!important;color:#172033!important;}
.bell-head{border-color:#e2e8f0!important;}
.bell-head h3{color:#0f172a!important;}
.bell-list li{color:#334155!important;}
.bell-count{border-color:#fff!important;}

/* Reports / analytics data surfaces and modal */
.standing-score,.pstat-val,.avg-score-big,.avg-score-label{color:#047857!important;}
.standing-title.top{color:#047857!important;}
.standing-title.low{color:#dc2626!important;}
.comment-text{background:#f8fafc!important;color:#475569!important;border-color:#e2e8f0!important;}
.info-grid>div,.q-result,.received-item{border-color:#e2e8f0!important;}
.eval-modal-overlay{background:rgba(15,23,42,.55)!important;}
.eval-modal{background:#fff!important;color:#172033!important;border-color:#e2e8f0!important;}
.eval-modal-header{border-color:#e2e8f0!important;}
.eval-modal-close{color:#64748b!important;}
.star{color:#cbd5e1!important;}
.star.filled{color:#facc15!important;}

/* Account / settings / roster utilities */
.profile-photo-lg{border-color:#d99a2b!important;}
.avatar-sm{border-color:rgba(217,154,43,.45)!important;}
.btn-reset{color:#475569!important;border-color:#cbd5e1!important;background:#fff!important;}
.structure-note{background:rgba(217,154,43,.06)!important;border-color:rgba(217,154,43,.24)!important;}
.structure-note p{color:#475569!important;}
.structure-note p b{color:#0f172a!important;}

@media(max-width:768px){
  body{background:#fff!important;}
}
</style>

<style id="principal-auth-portal-theme">
/* Principal authentication pages: flat navy foundation, diagonal gold hatch, and geometric hex accents. */
:root{
  --dark:#0A192F!important;
  --mid:#172A45!important;
  --inner:#0F1F3D!important;
  --light:#E0E6F0!important;
  --muted:#A0B3C6!important;
  --amber:#D99A2B!important;
  --amber-h:#F0B84D!important;
  --amber-hover:#F0B84D!important;
  --blue-accent:#2B6CB0!important;
  --shadow:0 14px 40px rgba(0,0,0,.42)!important;
}
html{background:#0A192F!important;color-scheme:dark!important;}
body{background:#0A192F!important;background-image:none!important;color:#E0E6F0!important;}
.bg-image-overlay{display:none!important;}
.bg-grid{
  display:block!important;
  position:fixed!important;inset:0!important;z-index:0!important;
  background-color:#0A192F!important;
  background-image:
    repeating-linear-gradient(135deg,transparent 0,transparent 15px,rgba(217,154,43,.055) 15px,rgba(217,154,43,.055) 16px),
    repeating-linear-gradient(45deg,transparent 0,transparent 15px,rgba(217,154,43,.04) 15px,rgba(217,154,43,.04) 16px)!important;
  background-size:32px 32px!important;
  animation:none!important;
}
.orb{display:none!important;}
.portal-hex{
  position:fixed!important;width:320px!important;height:320px!important;
  z-index:1!important;pointer-events:none!important;
  opacity:.34!important;
}
.portal-hex svg{width:100%!important;height:100%!important;display:block!important;overflow:visible!important;}
.portal-hex polygon{fill:none!important;stroke-width:2!important;vector-effect:non-scaling-stroke!important;}
.portal-hex-gold{top:-95px!important;left:-95px!important;transform:rotate(10deg)!important;}
.portal-hex-gold polygon{stroke:#D99A2B!important;}
.portal-hex-blue{right:-100px!important;bottom:-100px!important;transform:rotate(-10deg)!important;opacity:.26!important;}
.portal-hex-blue polygon{stroke:#2B6CB0!important;}
.login-card,.reg-card{
  position:relative!important;z-index:10!important;
  background:rgba(23,42,69,.93)!important;
  backdrop-filter:blur(16px)!important;
  -webkit-backdrop-filter:blur(16px)!important;
  border:1px solid rgba(217,154,43,.22)!important;
  box-shadow:var(--shadow),0 0 0 1px rgba(217,154,43,.08)!important;
}
.card-title{color:#fff!important;}
.card-subtitle,.form-label,.register-row,.card-footer,.secure-badge{color:#A0B3C6!important;}
.divider{background:linear-gradient(90deg,transparent,rgba(217,154,43,.55),transparent)!important;}
.role-pill{background:rgba(217,154,43,.12)!important;border-color:rgba(217,154,43,.34)!important;color:#F0B84D!important;}
.logo-ring{border-color:#D99A2B!important;box-shadow:0 0 20px rgba(217,154,43,.30)!important;}
.login-card .form-input,.reg-card .form-input,.form-input{background:rgba(10,25,47,.78)!important;color:#E0E6F0!important;border-color:rgba(255,255,255,.12)!important;}
.form-input::placeholder{color:rgba(160,179,198,.48)!important;}
.form-input:focus{border-color:#D99A2B!important;box-shadow:0 0 0 3px rgba(217,154,43,.18)!important;}
.f-icon,.toggle-pw{color:#A0B3C6!important;}
.btn-login,.btn-register{background:#D99A2B!important;color:#1A1204!important;box-shadow:0 5px 18px rgba(217,154,43,.30)!important;}
.btn-login:hover,.btn-register:hover{background:#F0B84D!important;color:#1A1204!important;}
.register-row a,.card-footer a{color:#F0B84D!important;}
.alert-error{background:rgba(240,84,84,.12)!important;border-color:rgba(240,84,84,.35)!important;color:#ff9b9b!important;}
.alert-success{background:rgba(34,197,94,.10)!important;border-color:rgba(34,197,94,.30)!important;color:#86efac!important;}
.strength-bar{background:rgba(255,255,255,.08)!important;}

@media(max-width:600px){
  .portal-hex{width:240px!important;height:240px!important;}
  .portal-hex-gold{top:-80px!important;left:-80px!important;}
  .portal-hex-blue{right:-80px!important;bottom:-80px!important;}
}
.alert-success{display:flex;align-items:flex-start;gap:8px;border-radius:8px;padding:11px 14px;margin-bottom:18px;font-size:13px;line-height:1.45;background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.35);color:#6ee7b7;}

/* Match Admin (EA) login card size */
.login-card{max-width:420px!important;padding:48px 44px 40px!important;}
.logo-ring{width:72px!important;height:72px!important;}
.card-subtitle{text-wrap:balance;}
.login-card .form-input{height:38px!important;min-height:0!important;padding-top:0!important;padding-bottom:0!important;padding-left:46px!important;padding-right:46px!important;}
.input-wrap .f-icon{left:14px!important;width:18px!important;text-align:center!important;}
.btn-login{height:38px!important;min-height:0!important;padding:0 13px!important;}
.login-card .card-footer{display:none!important;}
@media(max-width:480px){.login-card{padding:36px 22px 30px!important;margin:16px;}}
</style>
</body>
</html>