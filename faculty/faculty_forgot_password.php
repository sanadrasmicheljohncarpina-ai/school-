<?php
// faculty/faculty_forgot_password.php
// Password recovery for Faculty accounts ONLY (users.role = 'teacher').
// Flow: username -> answer the user's own 3 security questions -> choose a new password.
// Unknown / ineligible usernames are stopped at step 1 with a generic message.
require_once 'security.php';
secure_session_start();
require_once 'db.php';
security_ensure_tables($mysqli);

const REC_ROLE       = 'teacher';          // only accounts with this role can recover here
const REC_KIND       = 'faculty_recover';          // throttle bucket, separate from the other portals
const REC_LOGIN      = 'faculty_login.php';
const REC_SKEY       = 'rec_faculty';          // session key holding the in-progress recovery
const REC_TTL        = 900;                 // recovery expires after 15 minutes
const REC_VERIFY_TTL = 600;                 // time allowed to set the new password after answering (10 minutes)

if (($_GET['restart'] ?? '') === '1') {
    unset($_SESSION[REC_SKEY]);
    header('Location: faculty_forgot_password.php');
    exit;
}

$error = '';
$state = $_SESSION[REC_SKEY] ?? null;
if ($state && (time() - ($state['ts'] ?? 0)) > REC_TTL) { unset($_SESSION[REC_SKEY]); $state = null; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!csrf_valid($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please try again.';

    // ── Step 1: username ────────────────────────────────────────────────
    } elseif ($action === 'lookup') {
        $username = trim((string)($_POST['username'] ?? ''));
        if ($username === '') {
            $error = 'Please enter your username.';
        } elseif (mb_strlen($username) > 100) {
            $error = 'That username is too long.';
        } elseif (auth_is_locked($mysqli, REC_KIND, $username)) {
            $error = AUTH_LOCK_MESSAGE;
        } else {
            $st = $mysqli->prepare("SELECT id FROM users WHERE username = ? AND role = ? AND is_active = 1 AND account_status = 'approved' LIMIT 1");
            $role = REC_ROLE;
            $st->bind_param('ss', $username, $role);
            $st->execute();
            $u = $st->get_result()->fetch_assoc();
            $st->close();

            // Only a real, active, approved account with all 3 saved questions may continue.
            $uid = 0; $keys = [];
            if ($u) {
                $rows = sq_load($mysqli, (int)$u['id']);
                if (count($rows) === 3 && isset($rows[1], $rows[2], $rows[3])) {
                    $uid = (int)$u['id'];
                    for ($i = 1; $i <= 3; $i++) { $keys[$i] = $rows[$i]['question_key']; }   // slot order, as chosen at registration
                }
            }

            if ($uid === 0) {
                auth_record($mysqli, REC_KIND, $username, false);
                $error = "We couldn't start recovery for that username. Check it and try again, or contact your System Administrator.";
            } else {
                session_regenerate_id(true);
                $_SESSION[REC_SKEY] = ['step' => 2, 'uid' => $uid, 'username' => $username, 'keys' => $keys, 'ts' => time()];
                $state = $_SESSION[REC_SKEY];
            }
        }

    // ── Step 2: security answers ────────────────────────────────────────
    } elseif ($action === 'verify' && $state && ($state['step'] ?? 0) === 2) {
        $username = $state['username'];
        $answers  = is_array($_POST['sq_answer'] ?? null) ? $_POST['sq_answer'] : [];

        if (auth_is_locked($mysqli, REC_KIND, $username)) {
            $error = AUTH_LOCK_MESSAGE;
        } else {
            $rows = sq_load($mysqli, (int)$state['uid']);
            $ok   = count($rows) === 3;
            for ($slot = 1; $slot <= 3; $slot++) {
                $given = sq_normalize(mb_substr((string)($answers[$slot] ?? ''), 0, 100));
                $hash  = $rows[$slot]['answer_hash'] ?? DUMMY_HASH;
                $match = password_verify($given, $hash);               // always run every check, for equal timing
                $same  = isset($rows[$slot]) && $rows[$slot]['question_key'] === ($state['keys'][$slot] ?? null);
                if ($given === '' || !$same || !$match) $ok = false;
            }
            if ($ok) {
                auth_clear($mysqli, REC_KIND, $username);
                session_regenerate_id(true);
                $state['step'] = 3;
                $state['verified_at'] = time();
                $_SESSION[REC_SKEY] = $state;
            } else {
                auth_record($mysqli, REC_KIND, $username, false);
                $error = auth_is_locked($mysqli, REC_KIND, $username)
                    ? AUTH_LOCK_MESSAGE
                    : 'One or more answers are incorrect. Please check them and try again.';
            }
        }

    // ── Step 3: new password ────────────────────────────────────────────
    } elseif ($action === 'reset' && $state && ($state['step'] ?? 0) === 3) {
        if (empty($state['uid']) || time() - (int)($state['verified_at'] ?? 0) > REC_VERIFY_TTL) {
            unset($_SESSION[REC_SKEY]); $state = null;
            $error = 'Your recovery session timed out. Please start again.';
        } else {
            $pw  = (string)($_POST['password'] ?? '');
            $pw2 = (string)($_POST['confirm_password'] ?? '');

            // The account must still be a valid, active, approved Faculty account.
            $st = $mysqli->prepare("SELECT id FROM users WHERE id = ? AND role = ? AND is_active = 1 AND account_status = 'approved' LIMIT 1");
            $role = REC_ROLE;
            $st->bind_param('is', $state['uid'], $role);
            $st->execute();
            $acct = $st->get_result()->fetch_assoc();
            $st->close();

            if (!$acct) {
                $error = 'This account can no longer be recovered here. Please contact your administrator.';
            } elseif (strlen($pw) < 8) {
                $error = 'Password must be at least 8 characters.';
            } elseif (strlen($pw) > 72) {
                $error = 'Password must be 72 characters or fewer.';
            } elseif ($pw !== $pw2) {
                $error = 'Passwords do not match.';
            } else {
                $newHash = password_hash($pw, PASSWORD_DEFAULT);
                $up = $mysqli->prepare('UPDATE users SET password_hash = ? WHERE id = ? AND role = ?');
                $role = REC_ROLE;
                $up->bind_param('sis', $newHash, $state['uid'], $role);
                $up->execute();
                $up->close();
                auth_record($mysqli, REC_KIND, $state['username'], true);
                auth_clear($mysqli, REC_KIND, $state['username']);
                unset($_SESSION[REC_SKEY]);
                session_regenerate_id(true);
                $_SESSION['reg_success'] = 'Password reset successfully. Please sign in.';
                header('Location: ' . REC_LOGIN);
                exit;
            }
        }
    }
}

$questions = sq_all_questions();
$stage     = $state ? (int)($state['step'] ?? 1) : 1;
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
<title>PBI — Faculty Forgot Password</title>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<style>
:root{--accent:#2563EB;--accent-h:#60A5FA;--accent-hover:#3B82F6;--btn-text:#ffffff;--dark:#0A192F;--mid:#172A45;--inner:#0F1F3D;--light:#E0E6F0;--muted:#A0B3C6;}
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
.btn-main:hover{background:var(--accent-hover);transform:translateY(-1px)}
.btn-main:disabled{opacity:.65;cursor:not-allowed;transform:none}
.help-note{font-size:12px;color:var(--muted);line-height:1.55;margin-top:16px;text-align:center}
.back-row{text-align:center;margin-top:18px;padding-top:14px;border-top:1px solid rgba(255,255,255,.06);font-size:13px}
.back-row a{color:var(--accent-h);text-decoration:none;font-weight:600}
.back-row a:hover{text-decoration:underline}
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
        <div class="icon-wrap" id="fallbackIcon" style="display:none"><i class="fa-solid <?= $stage === 3 ? 'fa-lock-open' : ($stage === 2 ? 'fa-shield-halved' : 'fa-key') ?>"></i></div>
        <div class="card-title">Forgot Password</div>
        <div class="role-pill">Faculty Account</div>
        <div class="card-subtitle"><?= htmlspecialchars($subtitles[$stage] ?? $subtitles[1]) ?></div>
    </div>
    <div class="divider"></div>
        <div class="steps" aria-hidden="true"><span class="on"></span><span class="<?= $stage >= 2 ? 'on' : '' ?>"></span><span class="<?= $stage >= 3 ? 'on' : '' ?>"></span></div>

    <?php if ($error): ?>
    <div class="alert alert-error" role="alert"><i class="fa-solid fa-circle-exclamation"></i><span><?= htmlspecialchars($error) ?></span></div>
    <?php endif; ?>

    <?php if ($stage === 1): ?>
    <form method="POST" action="faculty_forgot_password.php" autocomplete="off" id="fpForm">
        <?= csrf_field() ?>
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

    <?php elseif ($stage === 2): ?>
    <form method="POST" action="faculty_forgot_password.php" autocomplete="off" id="fpForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="verify">
        <?php foreach ($state['keys'] as $slot => $key): ?>
        <div class="form-group">
            <label class="form-label" for="ans<?= (int)$slot ?>"><span class="qn"><?= (int)$slot ?>.</span> <?= htmlspecialchars($questions[$key] ?? 'Security question') ?></label>
            <input class="form-input plain" type="text" id="ans<?= (int)$slot ?>" name="sq_answer[<?= (int)$slot ?>]" maxlength="100" placeholder="Your answer" required autocomplete="off" <?= (int)$slot === 1 ? 'autofocus' : '' ?>/>
        </div>
        <?php endforeach; ?>
        <button type="submit" class="btn-main" id="fpBtn"><i class="fa-solid fa-circle-check"></i> Verify Answers</button>
    </form>

    <?php else: ?>
    <form method="POST" action="faculty_forgot_password.php" autocomplete="off" id="fpForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reset">
        <div class="form-group">
            <label class="form-label" for="password">New Password</label>
            <div class="input-wrap">
                <input class="form-input" type="password" id="password" name="password" minlength="8" maxlength="72" placeholder="At least 8 characters" required autocomplete="new-password"/>
                <i class="fa-solid fa-lock f-icon"></i>
                <button type="button" class="toggle-pw" data-target="password" aria-label="Show password"><i class="fa-solid fa-eye-slash"></i></button>
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
        <a href="<?= htmlspecialchars(REC_LOGIN) ?>"><i class="fa-solid fa-arrow-left"></i> Back to Login</a>
        <?php if ($stage > 1): ?><span class="sep">·</span><a href="faculty_forgot_password.php?restart=1">Start over</a><?php endif; ?>
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
    const np = document.getElementById('password'), cp = document.getElementById('confirm_password');
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
