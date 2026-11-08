<?php
// dean/forgot_password.php
// Password recovery for the Dean account only (separate from the other portals):
//   Step 1  enter username
//   Step 2  answer the 3 security questions
//   Step 3  choose a new password
// Open it from dean_login.php as forgot_password.php?restart=1
// Unknown / ineligible usernames are stopped at step 1 with a generic message.
require_once 'security.php';
secure_session_start();
require_once 'db.php';
security_ensure_tables($mysqli);

const FP_VERIFY_TTL = 600; // seconds the "answers verified" state stays valid (10 minutes)

$role = 'dean';
$cfg  = ['label' => 'Dean', 'login' => 'dean_login.php', 'accent' => '#7C5FD9', 'accentH' => '#9C85F0', 'btnText' => '#ffffff'];

if (empty($_SESSION['fp_csrf'])) { $_SESSION['fp_csrf'] = bin2hex(random_bytes(32)); }
function fp_csrf_ok($t): bool { return is_string($t) && hash_equals($_SESSION['fp_csrf'], $t); }

// A fresh visit (?restart=1) or no saved state always starts at step 1.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (isset($_GET['restart']) || !is_array($_SESSION['fp'] ?? null) || ($_SESSION['fp']['role'] ?? '') !== $role)) {
    $_SESSION['fp'] = ['role' => $role, 'step' => 1];
}
$fp = $_SESSION['fp'] ?? null;
if (!is_array($fp) || ($fp['role'] ?? '') !== $role) {
    $fp = $_SESSION['fp'] = ['role' => $role, 'step' => 1];
}
$step  = (int)($fp['step'] ?? 1);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if (!fp_csrf_ok($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please try again.';

    // ── Step 1: username ────────────────────────────────────────────────
    } elseif ($action === 'lookup' && $step === 1) {
        $username = trim((string)($_POST['username'] ?? ''));
        $ident    = $role . ':' . mb_strtolower(mb_substr($username, 0, 80));
        if ($username === '' || mb_strlen($username) > 100) {
            $error = 'Please enter your username.';
        } elseif (auth_is_locked($mysqli, 'recover', $ident)) {
            $error = AUTH_LOCK_MESSAGE;
        } else {
            $stmt = $mysqli->prepare("SELECT id, is_active, account_status FROM users WHERE BINARY username = BINARY ? AND role = ? LIMIT 1");
            $stmt->bind_param('ss', $username, $role);
            $stmt->execute();
            $u = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            // Only a real, active, approved account that has all 3 saved security questions may continue.
            $uid = 0; $keys = [];
            if ($u && (int)$u['is_active'] === 1 && $u['account_status'] === 'approved') {
                $rows = sq_load($mysqli, (int)$u['id']);
                if (count($rows) === 3 && isset($rows[1], $rows[2], $rows[3])) {
                    $uid = (int)$u['id'];
                    for ($i = 1; $i <= 3; $i++) { $keys[] = $rows[$i]['question_key']; }   // slot order, as chosen at registration
                }
            }

            if (!$uid) {
                auth_record($mysqli, 'recover', $ident, false);
                $error = "We couldn't start recovery for that username. Check it and try again, or contact your System Administrator.";
            } else {
                $fp = ['role' => $role, 'step' => 2, 'uid' => $uid, 'username' => $username, 'keys' => $keys, 'ident' => $ident];
                $_SESSION['fp'] = $fp;
                $step = 2;
            }
        }

    // ── Step 2: security answers ────────────────────────────────────────
    } elseif ($action === 'verify' && $step === 2) {
        if (auth_is_locked($mysqli, 'recover', $fp['ident'])) {
            $error = AUTH_LOCK_MESSAGE;
        } else {
            $ans  = is_array($_POST['answer'] ?? null) ? $_POST['answer'] : [];
            $rows = $fp['uid'] ? sq_load($mysqli, (int)$fp['uid']) : [];
            $ok   = (bool)$fp['uid'];
            for ($i = 1; $i <= 3; $i++) {
                $norm  = sq_normalize(mb_substr((string)($ans[$i] ?? ''), 0, 100));
                $hash  = $rows[$i]['answer_hash'] ?? DUMMY_HASH;
                $match = password_verify($norm, $hash);                 // always run, for equal timing
                $same  = isset($rows[$i]) && $rows[$i]['question_key'] === ($fp['keys'][$i - 1] ?? null);
                if ($norm === '' || !$same) { $match = false; }
                $ok = $ok && $match;
            }
            if ($ok) {
                auth_clear($mysqli, 'recover', $fp['ident']);
                session_regenerate_id(true);
                $fp['step'] = 3;
                $fp['verified_at'] = time();
                $_SESSION['fp'] = $fp;
                $step = 3;
            } else {
                auth_record($mysqli, 'recover', $fp['ident'], false);
                $error = 'One or more answers are incorrect. Please check them and try again.';
            }
        }

    // ── Step 3: new password ────────────────────────────────────────────
    } elseif ($action === 'reset' && $step === 3) {
        if (empty($fp['uid']) || time() - (int)($fp['verified_at'] ?? 0) > FP_VERIFY_TTL) {
            $_SESSION['fp'] = ['role' => $role, 'step' => 1];
            $fp = $_SESSION['fp']; $step = 1;
            $error = 'Your recovery session timed out. Please start again.';
        } else {
            $new  = (string)($_POST['new_password'] ?? '');
            $conf = (string)($_POST['confirm_password'] ?? '');
            $stmt = $mysqli->prepare("SELECT username, password_hash FROM users WHERE id = ? AND role = ? AND is_active = 1 AND account_status = 'approved' LIMIT 1");
            $uidInt = (int)$fp['uid'];
            $stmt->bind_param('is', $uidInt, $role);
            $stmt->execute();
            $me = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$me) {
                $error = 'This account can no longer be recovered here. Please contact your administrator.';
            } elseif (strlen($new) < 8) {
                $error = 'New password must be at least 8 characters.';
            } elseif (strlen($new) > 72) {
                $error = 'New password must be 72 characters or fewer.';
            } elseif (mb_strtolower($new) === mb_strtolower($me['username'])) {
                $error = 'Your password cannot be the same as your username.';
            } elseif ($new !== $conf) {
                $error = 'New passwords do not match.';
            } elseif (password_verify($new, $me['password_hash'])) {
                $error = 'New password must be different from your current password.';
            } else {
                try {
                    $hash = password_hash($new, PASSWORD_DEFAULT);
                    $upd = $mysqli->prepare("UPDATE users SET password_hash = ? WHERE id = ? AND role = ? LIMIT 1");
                    $upd->bind_param('sis', $hash, $uidInt, $role);
                    $upd->execute();
                    $upd->close();
                    unset($_SESSION['fp']);
                    session_regenerate_id(true);
                    $_SESSION['reset_success'] = 'Your password has been updated. You can now sign in.';
                    header('Location: ' . $cfg['login']);
                    exit;
                } catch (Throwable $e) {
                    error_log('forgot_password reset failed: ' . $e->getMessage());
                    $error = 'Could not update your password. Please try again.';
                }
            }
        }
    } else {
        $_SESSION['fp'] = ['role' => $role, 'step' => 1];
        $fp = $_SESSION['fp']; $step = 1;
        $error = 'Something went wrong. Please start again.';
    }
}

$allQ = sq_all_questions();
$subtitles = [
    1 => 'Enter your username to begin recovering your password.',
    2 => 'Answer the three security questions you chose when you registered.',
    3 => 'Choose a new password for your account.',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>PBI — Forgot Password</title>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<style>
:root{--accent:<?= $cfg['accent'] ?>;--accent-h:<?= $cfg['accentH'] ?>;--btn-text:<?= $cfg['btnText'] ?>;--dark:#0A192F;--mid:#172A45;--inner:#0F1F3D;--light:#E0E6F0;--muted:#A0B3C6;}
*{box-sizing:border-box;margin:0;padding:0}
body{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;font-family:'DM Sans',sans-serif;font-size:14px;color:var(--light);
 background:radial-gradient(circle at 10% 10%,color-mix(in srgb,var(--accent) 16%,transparent),transparent 32%),radial-gradient(circle at 90% 90%,rgba(43,108,176,.12),transparent 30%),var(--dark);background-attachment:fixed}
.card{width:100%;max-width:440px;background:rgba(23,42,69,.88);backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px);border:1px solid rgba(255,255,255,.12);border-radius:18px;padding:40px 36px 32px;box-shadow:0 24px 70px rgba(0,0,0,.36)}
.card-header{text-align:center}
.icon-wrap{width:58px;height:58px;margin:0 auto 14px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:22px;color:var(--accent);background:color-mix(in srgb,var(--accent) 16%,transparent)}
.logo-img{width:72px;height:72px;border-radius:50%;object-fit:cover;display:block;border:2.5px solid var(--accent);box-shadow:0 0 22px color-mix(in srgb,var(--accent) 40%,transparent);margin:0 auto 14px}
.card-title{font-family:'Rajdhani',sans-serif;font-size:26px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#fff}
.role-pill{display:inline-block;margin-top:8px;padding:3px 13px;border-radius:20px;font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--accent-h);background:color-mix(in srgb,var(--accent) 14%,transparent);border:1px solid color-mix(in srgb,var(--accent) 38%,transparent)}
.card-subtitle{color:var(--muted);margin-top:12px;line-height:1.5}
.steps{display:flex;justify-content:center;gap:8px;margin:18px 0 4px}
.steps span{width:28px;height:4px;border-radius:4px;background:rgba(255,255,255,.12)}
.steps span.on{background:var(--accent)}
.divider{height:1px;background:rgba(255,255,255,.08);margin:18px 0 22px}
.alert{display:flex;gap:10px;align-items:flex-start;padding:12px 14px;border-radius:10px;margin-bottom:18px;font-size:13px;line-height:1.45}
.alert i{margin-top:2px}
.alert-error{background:rgba(240,84,84,.12);border:1px solid rgba(240,84,84,.35);color:#ff9a9a}
.form-group{margin-bottom:16px}
.form-label{display:block;font-size:11px;font-weight:600;letter-spacing:1.1px;text-transform:uppercase;color:var(--muted);margin-bottom:7px;line-height:1.5}
.form-label .qn{color:var(--accent-h);margin-right:4px}
.input-wrap{position:relative}
.f-icon{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:14px;pointer-events:none}
.form-input{width:100%;min-height:48px;padding:12px 42px 12px 40px;background:rgba(10,25,47,.7);border:1px solid rgba(255,255,255,.12);border-radius:10px;color:var(--light);font:500 14px 'DM Sans',sans-serif;outline:none;transition:border-color .2s,box-shadow .2s}
.form-input.plain{padding-left:14px}
.form-input::placeholder{color:rgba(160,179,198,.45)}
.form-input:focus{border-color:var(--accent);box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 22%,transparent)}
.toggle-pw{position:absolute;right:12px;top:50%;transform:translateY(-50%);border:0;background:none;padding:5px;color:var(--muted);cursor:pointer;font-size:14px}
.toggle-pw:hover{color:#fff}
.btn-main{width:100%;min-height:50px;padding:12px 16px;border:0;border-radius:10px;background:var(--accent);color:var(--btn-text);font:600 15px 'DM Sans',sans-serif;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:.2s;margin-top:4px}
.btn-main:hover{background:var(--accent-h);transform:translateY(-1px)}
.btn-main:disabled{opacity:.65;cursor:not-allowed;transform:none}
.help-note{font-size:12px;color:var(--muted);line-height:1.55;margin-top:16px;text-align:center}
.back-row{text-align:center;margin-top:18px;padding-top:14px;border-top:1px solid rgba(255,255,255,.06);font-size:13px}
.back-row a{color:var(--accent-h);text-decoration:none;font-weight:600}
.back-row a:hover{color:#fff;text-decoration:underline}
.back-row .sep{color:var(--muted);margin:0 8px}
</style>
<style id="forgot-match-ea">
/* Matches the Admin (EA) forgot-password page: same card size, field and button height, spacing
   and page background (diagonal grid + soft glow), tinted with each role's own colour. */
body{background:#0A192F;overflow-x:hidden;position:relative}
.bg-grid{position:fixed;inset:0;z-index:0;pointer-events:none;background-image:repeating-linear-gradient(45deg,color-mix(in srgb,var(--accent) 8%,transparent) 0,color-mix(in srgb,var(--accent) 8%,transparent) 1px,transparent 1px,transparent 26px),repeating-linear-gradient(-45deg,color-mix(in srgb,var(--accent) 5%,transparent) 0,color-mix(in srgb,var(--accent) 5%,transparent) 1px,transparent 1px,transparent 26px)}
.orb{position:fixed;border-radius:50%;filter:blur(85px);z-index:0;pointer-events:none}
.orb-1{width:420px;height:420px;top:-100px;left:-100px;background:radial-gradient(circle,color-mix(in srgb,var(--accent) 16%,transparent) 0%,transparent 70%)}
.orb-2{width:320px;height:320px;bottom:-80px;right:-80px;background:radial-gradient(circle,color-mix(in srgb,var(--accent) 13%,transparent) 0%,transparent 70%)}
.card{position:relative;z-index:10;max-width:420px;padding:40px 40px 32px;background:rgba(23,42,69,.92);border:1px solid rgba(255,255,255,.09);box-shadow:0 8px 32px rgba(0,0,0,.45);animation:cardIn .7s cubic-bezier(.22,1,.36,1) both}
@keyframes cardIn{from{opacity:0;transform:translateY(32px) scale(.97)}to{opacity:1;transform:none}}
@media (prefers-reduced-motion:reduce){.card{animation:none}}
.card-header{margin-bottom:24px}
.card-title{font-size:24px;letter-spacing:2px}
.card-subtitle{font-size:12px;margin-top:6px;line-height:1.6}
/* The account label stays, as a small badge in the corner, so it no longer adds height. */
.role-pill{position:absolute;top:16px;right:16px;margin:0;padding:3px 10px;font-size:10px}
.divider{margin:0 0 22px;background:linear-gradient(90deg,transparent,color-mix(in srgb,var(--accent) 38%,transparent),transparent)}
.steps{margin:0 0 18px}
.form-label{margin-bottom:6px}
.form-input{height:37px;min-height:37px;padding:0 46px 0 42px}
.f-icon{left:13px;font-size:13px;z-index:2}
.toggle-pw{right:12px;width:26px;height:30px;padding:0;display:flex;align-items:center;justify-content:center;z-index:3}
.btn-main{height:38px;min-height:38px;padding:0 16px;margin-top:0}
/* The explanation note sits just under the card instead of inside it. */
.help-note{position:absolute;left:0;right:0;top:100%;margin:14px 0 0;padding:0 8px}
.back-row{margin-top:18px;padding-top:0;border-top:0}
@media(max-width:480px){.card{padding:32px 22px 28px}}
</style>

</head>
<body>
<div class="bg-grid"></div>
<div class="orb orb-1"></div>
<div class="orb orb-2"></div>
<div class="card">
    <div class="card-header">
        <img class="logo-img" src="../image/pbi_logo" alt="PBI Logo"
             onerror="this.style.display='none';document.getElementById('fallbackIcon').style.display='flex'"/>
        <div class="icon-wrap" id="fallbackIcon" style="display:none"><i class="fa-solid <?= $step === 3 ? 'fa-lock-open' : ($step === 2 ? 'fa-shield-halved' : 'fa-key') ?>"></i></div>
        <div class="card-title">Forgot Password</div>
        <div class="role-pill"><?= htmlspecialchars($cfg['label']) ?> Account</div>
        <div class="card-subtitle"><?= htmlspecialchars($subtitles[$step] ?? $subtitles[1]) ?></div>
    </div>
    <div class="divider"></div>
        <div class="steps" aria-hidden="true"><span class="<?= $step >= 1 ? 'on' : '' ?>"></span><span class="<?= $step >= 2 ? 'on' : '' ?>"></span><span class="<?= $step >= 3 ? 'on' : '' ?>"></span></div>

    <?php if ($error): ?>
    <div class="alert alert-error" role="alert"><i class="fa-solid fa-circle-exclamation"></i><span><?= htmlspecialchars($error) ?></span></div>
    <?php endif; ?>

    <?php if ($step === 1): ?>
    <form method="POST" action="forgot_password.php" autocomplete="off" id="fpForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['fp_csrf']) ?>">
        <input type="hidden" name="action" value="lookup">
        <div class="form-group">
            <label class="form-label" for="username">Username</label>
            <div class="input-wrap">
                <input class="form-input" type="text" id="username" name="username" maxlength="100" placeholder="Enter your username" required autofocus autocomplete="username"/>
                <i class="fa-solid fa-user f-icon"></i>
            </div>
        </div>
        <button type="submit" class="btn-main" id="fpBtn"><i class="fa-solid fa-arrow-right"></i> Continue</button>
    </form>


    <?php elseif ($step === 2): ?>
    <form method="POST" action="forgot_password.php" autocomplete="off" id="fpForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['fp_csrf']) ?>">
        <input type="hidden" name="action" value="verify">
        <?php foreach (array_values($fp['keys']) as $i => $key): ?>
        <div class="form-group">
            <label class="form-label" for="ans<?= $i + 1 ?>"><span class="qn"><?= $i + 1 ?>.</span> <?= htmlspecialchars($allQ[$key] ?? 'Security question') ?></label>
            <input class="form-input plain" type="text" id="ans<?= $i + 1 ?>" name="answer[<?= $i + 1 ?>]" maxlength="100" placeholder="Your answer" required autocomplete="off"/>
        </div>
        <?php endforeach; ?>
        <button type="submit" class="btn-main" id="fpBtn"><i class="fa-solid fa-circle-check"></i> Verify Answers</button>
    </form>

    <?php else: ?>
    <form method="POST" action="forgot_password.php" autocomplete="off" id="fpForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['fp_csrf']) ?>">
        <input type="hidden" name="action" value="reset">
        <div class="form-group">
            <label class="form-label" for="new_password">New Password</label>
            <div class="input-wrap">
                <input class="form-input" type="password" id="new_password" name="new_password" minlength="8" maxlength="72" placeholder="At least 8 characters" required autocomplete="new-password"/>
                <i class="fa-solid fa-lock f-icon"></i>
                <button type="button" class="toggle-pw" data-target="new_password" aria-label="Show password"><i class="fa-solid fa-eye-slash"></i></button>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label" for="confirm_password">Confirm New Password</label>
            <div class="input-wrap">
                <input class="form-input" type="password" id="confirm_password" name="confirm_password" minlength="8" maxlength="72" placeholder="Repeat the new password" required autocomplete="new-password"/>
                <i class="fa-solid fa-lock f-icon"></i>
                <button type="button" class="toggle-pw" data-target="confirm_password" aria-label="Show password"><i class="fa-solid fa-eye-slash"></i></button>
            </div>
        </div>
        <div class="alert alert-error" id="clientError" style="display:none"><i class="fa-solid fa-circle-exclamation"></i><span>New passwords do not match.</span></div>
        <button type="submit" class="btn-main" id="fpBtn"><i class="fa-solid fa-key"></i> Update Password</button>
    </form>
    <?php endif; ?>

    <div class="back-row">
        <a href="<?= htmlspecialchars($cfg['login']) ?>"><i class="fa-solid fa-arrow-left"></i> Back to Login</a>
        <?php if ($step > 1): ?><span class="sep">·</span><a href="forgot_password.php?restart=1">Start over</a><?php endif; ?>
    </div>
</div>
<script>
document.querySelectorAll('.toggle-pw').forEach(btn => btn.addEventListener('click', () => {
    const input = document.getElementById(btn.dataset.target);
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.querySelector('i').className = show ? 'fa-solid fa-eye' : 'fa-solid fa-eye-slash';
    btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
}));
const fpForm = document.getElementById('fpForm');
const fpBtn  = document.getElementById('fpBtn');
fpForm.addEventListener('submit', (e) => {
    const np = document.getElementById('new_password'), cp = document.getElementById('confirm_password');
    if (np && cp && np.value !== cp.value) {
        e.preventDefault();
        document.getElementById('clientError').style.display = 'flex';
        return;
    }
    fpBtn.disabled = true;
});
window.addEventListener('pageshow', (e) => { if (e.persisted) fpBtn.disabled = false; });
</script>
</body>
</html>
