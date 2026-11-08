<?php
// faculty/staff_login.php
session_start();
require_once 'db.php';

$error   = '';
$success = $_SESSION['reg_success'] ?? '';
unset($_SESSION['reg_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Please enter your username and password.';
    } else {
        // Accept only the username registered in the users table.
        // The login field is NOT matched against full name or email.
        $stmt = $mysqli->prepare(
            "SELECT id, full_name, password_hash, role, designation, account_status, is_active
             FROM users
             WHERE username = ? AND role = 'staff'
             LIMIT 1"
        );

        if (!$stmt) {
            $error = 'Unable to process login right now. Please try again.';
        } else {
            $stmt->bind_param('s', $username);
            error_log("LOGIN DEBUG: about to query username=[$username]");
            if (!$stmt->execute()) {
                $error = 'Unable to process login right now. Please try again.';
                $stmt->close();
            } else {
                $user = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$user) {
                    error_log("LOGIN DEBUG: no row found for username=[$username] role=staff");
                    $error = 'Incorrect username or password. Please try again.';
                } elseif ($user['account_status'] === 'pending') {
                    $error = 'Your account is awaiting admin approval. You will be able to log in once it is approved.';
                } elseif (!password_verify($password, $user['password_hash'])) {
                    error_log("LOGIN DEBUG: row found (id={$user['id']}) but password_verify failed for username=[$username]");
                    $error = 'Incorrect username or password. Please try again.';
                } elseif ($user['account_status'] !== 'approved' || (int)$user['is_active'] !== 1) {
                    // Correct credentials, but the account is currently blocked by the Executive Assistant.
                    $error = 'This account has been blocked by the Executive Assistant. Please contact the administrator.';
                } else {

                    // Keep compatibility with installations that do/don't have
                    // the optional must_change_password column.
                    $mustChangePassword = false;
                    $columnCheck = $mysqli->query("SHOW COLUMNS FROM users LIKE 'must_change_password'");
                    if ($columnCheck) {
                        $mustChangePassword = $columnCheck->num_rows > 0;
                        $columnCheck->free();
                    }

                    if ($mustChangePassword) {
                        $mcStmt = $mysqli->prepare("SELECT must_change_password FROM users WHERE id = ? LIMIT 1");
                        if ($mcStmt) {
                            $mcStmt->bind_param('i', $user['id']);
                            if ($mcStmt->execute()) {
                                $mcRow = $mcStmt->get_result()->fetch_assoc();
                                $mustChangePassword = !empty($mcRow['must_change_password']);
                            }
                            $mcStmt->close();
                        }
                    } else {
                        $mustChangePassword = false;
                    }

                    session_regenerate_id(true);
                    $_SESSION['user_id']              = (int)$user['id'];
                    $_SESSION['full_name']            = $user['full_name'];
                    $_SESSION['role']                 = $user['role'];
                    $_SESSION['designation']          = $user['designation'];
                    $_SESSION['must_change_password'] = $mustChangePassword;

                    $mysqli->close();

                    if ($mustChangePassword) {
                        header('Location: ../admin/force_password_change.php');
                    } else {
                        header('Location: staff_dashboard.php');
                    }
                    exit;
                }
            }
        }
    }

    if ($mysqli->ping()) {
        $mysqli->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>PBI — Teacher & Staff Login</title>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<style>
:root{--dark-blue:#0A192F;--blue-mid:#172A45;--teal:#16A34A;--teal-hover:#22C55E;--light:#E0E6F0;--muted:#A0B3C6;--radius:10px;--shadow:0 8px 32px rgba(0,0,0,0.45);}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{min-height:100vh;background:radial-gradient(circle at 50% 42%,rgba(255,255,255,.028),transparent 34%),radial-gradient(circle at 88% 82%,rgba(43,108,176,.11),transparent 30%),radial-gradient(circle at 8% 12%,rgba(22,163,74,.08),transparent 26%),#08182d;font-family:'DM Sans',sans-serif;color:var(--light);display:flex;align-items:center;justify-content:center;overflow:hidden;position:relative;}
.bg-grid{display:block;position:fixed;inset:0;z-index:0;background-image:radial-gradient(circle at 50% 45%,rgba(22,163,74,.045),transparent 38%),repeating-linear-gradient(45deg,rgba(22,163,74,.055) 0px,rgba(22,163,74,.055) 1px,transparent 1px,transparent 26px),repeating-linear-gradient(-45deg,rgba(22,163,74,.035) 0px,rgba(22,163,74,.035) 1px,transparent 1px,transparent 26px);}
.hex-deco{position:fixed;z-index:0;pointer-events:none;opacity:.46;filter:drop-shadow(0 0 10px rgba(22,163,74,.05));}
.hex-1{top:-60px;left:-60px;}
.hex-2{bottom:-70px;right:-70px;}
.login-card{position:relative;z-index:10;background:rgba(23,42,69,.82);backdrop-filter:blur(18px);border:1px solid rgba(255,255,255,.09);border-radius:18px;padding:48px 44px 40px;width:100%;max-width:440px;box-shadow:var(--shadow),0 0 0 1px rgba(22,163,74,.10),0 0 34px rgba(22,163,74,.055);animation:cardIn .7s cubic-bezier(.22,1,.36,1) both;}
@keyframes cardIn{from{opacity:0;transform:translateY(32px) scale(.97)}to{opacity:1;transform:none}}
.card-header{text-align:center;margin-bottom:28px;}
.logo-ring{width:72px;height:72px;border-radius:50%;display:block;object-fit:cover;border:2.5px solid var(--teal);box-shadow:0 0 14px rgba(245,158,11,.16),0 0 26px rgba(22,163,74,.34),0 0 42px rgba(22,163,74,.10);margin:0 auto 16px;}
.card-title{font-family:'Rajdhani',sans-serif;font-size:26px;font-weight:700;letter-spacing:2px;color:#fff;text-transform:uppercase;}
.card-subtitle{font-size:12px;color:var(--muted);letter-spacing:1.2px;text-transform:uppercase;line-height:1.55;text-wrap:balance;}
.role-pill{display:inline-flex;align-items:center;justify-content:center;gap:6px;margin-top:10px;padding:4px 14px;border-radius:20px;background:rgba(22,163,74,.14);border:1px solid rgba(74,222,128,.38);color:#4ADE80;font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;}
.divider{height:1px;background:linear-gradient(90deg,transparent,rgba(22,163,74,.45),transparent);margin-bottom:24px;}
.form-group{margin-bottom:18px;}
.form-label{display:block;font-size:11px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:var(--muted);margin-bottom:7px;}
.input-wrap{position:relative;}
.f-icon{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:14px;pointer-events:none;}
.form-input{width:100%;padding:12px 14px 12px 40px;background:rgba(10,25,47,.7);border:1px solid rgba(255,255,255,.1);border-radius:var(--radius);color:var(--light);font-size:14px;font-family:'DM Sans',sans-serif;outline:none;transition:border-color .25s,box-shadow .25s;}
.form-input::placeholder{color:rgba(160,179,198,.45);}
.form-input:focus{border-color:var(--teal);box-shadow:0 0 0 3px rgba(22,163,74,.18),0 0 16px rgba(22,163,74,.11);}
.toggle-pw{position:absolute;right:13px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--muted);cursor:pointer;font-size:14px;padding:0;}
.alert{border-radius:8px;padding:11px 14px;font-size:13px;margin-bottom:18px;display:flex;align-items:center;gap:8px;}
.alert-error{background:rgba(240,84,84,.12);border:1px solid rgba(240,84,84,.35);color:#ff8a8a;}
.alert-success{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.28);color:#86efac;}
.btn-login{width:100%;padding:13px;background:var(--teal);border:none;border-radius:var(--radius);color:#fff;font-size:15px;font-weight:600;font-family:'DM Sans',sans-serif;cursor:pointer;transition:background .2s,transform .15s;box-shadow:0 6px 18px rgba(22,163,74,.34),0 0 22px rgba(22,163,74,.18);display:flex;align-items:center;justify-content:center;gap:8px;}
.btn-login:hover{background:var(--teal-hover);transform:translateY(-1px);box-shadow:0 9px 24px rgba(22,163,74,.42),0 0 30px rgba(22,163,74,.22);}
.card-footer{text-align:center;margin-top:22px;font-size:12px;color:var(--muted);border-top:1px solid rgba(255,255,255,.06);padding-top:18px;}
.card-footer a{color:var(--teal-hover);text-decoration:none;font-weight:600;}
.card-footer a:hover{text-decoration:underline;}
</style>
<style id="login-match-ea">
/* Same card proportions as the Admin (EA) login: width, field height, button height,
   icon positions and spacing. */
.login-card{max-width:420px}
body{padding:24px}
.card-subtitle{margin-top:4px;line-height:normal}
.input-wrap .form-input{height:37px;min-height:37px;padding:0 46px}
.input-wrap .f-icon{left:14px;width:18px;text-align:center;z-index:2}
.input-wrap .toggle-pw{right:12px;z-index:3;width:26px;height:30px;padding:0;display:flex;align-items:center;justify-content:center}
.input-wrap .toggle-pw i{display:block}
.btn-login{height:38px;min-height:38px;padding:0 16px}
.card-footer{margin-top:16px;padding-top:0;border-top:0;font-size:13px}
@media(max-width:480px){body{padding:16px}.login-card{padding:36px 22px 30px}}
@media(max-height:700px){body{overflow:auto}}
</style>

</head>
<body>
<div class="bg-grid"></div>
<svg class="hex-deco hex-1" width="260" height="260" viewBox="0 0 260 260"><polygon points="130,10 240,70 240,190 130,250 20,190 20,70" fill="none" stroke="#16A34A" stroke-width="1"/><polygon points="130,50 200,90 200,170 130,210 60,170 60,90" fill="none" stroke="#16A34A" stroke-width="1"/></svg>
<svg class="hex-deco hex-2" width="300" height="300" viewBox="0 0 300 300"><polygon points="150,10 280,80 280,220 150,290 20,220 20,80" fill="none" stroke="#2B6CB0" stroke-width="1"/><polygon points="150,60 220,100 220,200 150,240 80,200 80,100" fill="none" stroke="#2B6CB0" stroke-width="1"/></svg>

<div class="login-card">
    <div class="card-header">
        <img class="logo-ring" src="../image/pbi_logo" alt="PBI Logo"/>
        <div class="card-subtitle">Employee Performance Evaluation &amp; Management System</div>
        <div class="role-pill"><i class="fa-solid fa-id-badge"></i> Staff Access</div>
    </div>
    <div class="divider"></div>

    <?php if ($success): ?>
    <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="staff_login.php" autocomplete="off">
        <div class="form-group">
            <label class="form-label">Username</label>
            <div class="input-wrap">
                <input class="form-input" type="text" name="username"
                       placeholder="Enter your username" required autocomplete="off"/>
                <i class="fa-solid fa-user f-icon"></i>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Password</label>
            <div class="input-wrap">
                <input class="form-input" type="password" id="password" name="password"
                       placeholder="Enter your password" required autocomplete="new-password"/>
                <i class="fa-solid fa-lock f-icon"></i>
                <button type="button" class="toggle-pw" onclick="togglePw()" aria-label="Show password">
                    <i class="fa-solid fa-eye-slash" id="eyeIcon"></i>
                </button>
            </div>
        </div>
        <div style="text-align:right; margin-top:-10px; margin-bottom:14px;">
            <a href="staff_forgot_password.php" style="font-size:12px; color:var(--teal-hover); text-decoration:none; font-weight:600;">
                <i class="fa-solid fa-key"></i> Forgot Password?
            </a>
        </div>
        <button type="submit" class="btn-login">
            <i class="fa-solid fa-right-to-bracket"></i> Sign In
        </button>
    </form>

    <div class="card-footer">
        New to the system? <a href="staff_register.php">Register here</a>
    </div>
</div>

<script>
function togglePw() {
    const pw = document.getElementById('password'), ic = document.getElementById('eyeIcon');
    pw.type = pw.type === 'password' ? 'text' : 'password';
    ic.className = pw.type === 'password' ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
}
</script>
</body>
</html>