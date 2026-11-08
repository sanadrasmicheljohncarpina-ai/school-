<?php
// student/reset_password.php
// Reached only after forgot_password.php verified all three security answers.
// The permission lives in the server-side session (10 minutes) - there is no token in the URL.
require_once 'security.php';
secure_session_start();
require_once 'db.php';

$pr    = $_SESSION['pw_reset'] ?? null;
$valid = is_array($pr) && isset($pr['uid'], $pr['exp']) && time() <= (int)$pr['exp'];
$error = '';
$fatal = '';

if (!$valid) {
    unset($_SESSION['pw_reset']);
    $fatal = "This password-reset session is invalid or has expired. Please start again.";
}

if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password']         ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    $stmt = $mysqli->prepare("SELECT username FROM users WHERE id = ? AND role = 'student' AND is_active = 1 LIMIT 1");
    $uid  = (int)$pr['uid'];
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!csrf_valid($_POST['csrf_token'] ?? '')) {
        $error = "Your session expired. Please try again.";
    } elseif (!$u) {
        unset($_SESSION['pw_reset']);
        $valid = false;
        $fatal = "This account can no longer be reset. Please contact your administrator.";
    } elseif (strlen($password) < 8) {
        $error = "Password must be at least 8 characters.";
    } elseif (strlen($password) > 72) {
        $error = "Password must be 72 characters or fewer.";
    } elseif (mb_strtolower($password) === mb_strtolower($u['username'])) {
        $error = "Your password cannot be the same as your username.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $upd  = $mysqli->prepare("UPDATE users SET password_hash = ? WHERE id = ? AND role = 'student' LIMIT 1");
        $upd->bind_param("si", $hash, $uid);
        $upd->execute();
        $upd->close();

        unset($_SESSION['pw_reset']);
        session_regenerate_id(true);
        $_SESSION['reg_success'] = "Password reset successfully. Please sign in.";
        header("Location: student_login.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>PBI — Reset Password</title>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<link rel="stylesheet" href="student_ui.css"/>
<link rel="stylesheet" href="student_recovery.css"/>
</head>
<body>
<div class="bg-grid" aria-hidden="true"></div>
<svg class="hex-deco hex-1" width="260" height="260" viewBox="0 0 260 260" aria-hidden="true"><polygon points="130,10 240,70 240,190 130,250 20,190 20,70" fill="none" stroke="#D97706" stroke-width="1"/><polygon points="130,50 200,90 200,170 130,210 60,170 60,90" fill="none" stroke="#D97706" stroke-width="1"/></svg>
<svg class="hex-deco hex-2" width="300" height="300" viewBox="0 0 300 300" aria-hidden="true"><polygon points="150,10 280,80 280,220 150,290 20,220 20,80" fill="none" stroke="#2B6CB0" stroke-width="1"/><polygon points="150,60 220,100 220,200 150,240 80,200 80,100" fill="none" stroke="#2B6CB0" stroke-width="1"/></svg>

<div class="card">
    <div class="card-header">
        <div class="icon-wrap"><i class="fa-solid fa-lock"></i></div>
        <div class="card-title">Reset Password</div>
        <div class="card-subtitle"><?= $valid ? 'Answers verified. Choose a new password for your account.' : 'Password recovery' ?></div>
    </div>
    <div class="divider"></div>

    <?php if ($fatal): ?>
    <div class="alert alert-error" role="alert"><i class="fa-solid fa-circle-exclamation"></i><span><?= htmlspecialchars($fatal) ?></span></div>
    <div class="back-row"><a href="forgot_password.php"><i class="fa-solid fa-rotate-left"></i> Start again</a></div>
    <?php else: ?>
        <?php if ($error): ?>
        <div class="alert alert-error" role="alert"><i class="fa-solid fa-circle-exclamation"></i><span><?= htmlspecialchars($error) ?></span></div>
        <?php endif; ?>
        <form method="POST" action="reset_password.php" autocomplete="off" id="resetForm">
            <?= csrf_field() ?>
            <div class="form-group">
                <label class="form-label" for="pw1">New Password</label>
                <div class="input-wrap">
                    <i class="fa-solid fa-lock f-icon"></i>
                    <input class="form-input" type="password" id="pw1" name="password" placeholder="Min. 8 characters"
                           required minlength="8" maxlength="72" autocomplete="new-password" autofocus/>
                    <button type="button" class="toggle-pw" onclick="togglePw('pw1','e1')" aria-label="Show password"><i class="fa-solid fa-eye-slash" id="e1"></i></button>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label" for="pw2">Confirm New Password</label>
                <div class="input-wrap">
                    <i class="fa-solid fa-lock f-icon"></i>
                    <input class="form-input" type="password" id="pw2" name="confirm_password" placeholder="Re-enter password"
                           required minlength="8" maxlength="72" autocomplete="new-password"/>
                    <button type="button" class="toggle-pw" onclick="togglePw('pw2','e2')" aria-label="Show password"><i class="fa-solid fa-eye-slash" id="e2"></i></button>
                </div>
            </div>
            <div class="alert alert-error" id="mismatch" style="display:none"><i class="fa-solid fa-circle-exclamation"></i><span>Passwords do not match.</span></div>
            <button type="submit" class="btn-main" id="resetBtn"><i class="fa-solid fa-check"></i> Reset Password</button>
        </form>
        <div class="back-row"><a href="student_login.php"><i class="fa-solid fa-arrow-left"></i> Back to Login</a></div>
    <?php endif; ?>
</div>
<script>
function togglePw(id, ic){
    const e = document.getElementById(id), i = document.getElementById(ic);
    e.type = e.type === 'password' ? 'text' : 'password';
    i.className = e.type === 'password' ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
}
const rf = document.getElementById('resetForm');
if (rf) {
    rf.addEventListener('submit', (ev) => {
        if (document.getElementById('pw1').value !== document.getElementById('pw2').value) {
            ev.preventDefault();
            document.getElementById('mismatch').style.display = 'flex';
            return;                                   // button stays usable
        }
        document.getElementById('resetBtn').disabled = true;
    });
    window.addEventListener('pageshow', (e) => { if (e.persisted) document.getElementById('resetBtn').disabled = false; });
}
</script>
</body>
</html>