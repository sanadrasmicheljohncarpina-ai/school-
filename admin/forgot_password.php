<?php
// admin/forgot_password.php
// Recovery flow:  1) username  ->  2) the user's OWN security questions  ->  3) set new password
session_start();
require_once 'db.php';
require_once 'security.php';          // same helpers used by admin_register.php
security_ensure_tables($mysqli);
$sq_all = sq_questions();             // key => question text (same list shown at registration)

/* ───────────────────────── ADAPTER (edit if your security.php differs) ─────────────────────────
   Must return the rows saved by sq_save() at registration: [['question_key'=>..., 'answer_hash'=>...], ...] */
const FP_ANSWERS_TABLE = 'student_security_answers';

function fp_get_user_answers(mysqli $db, int $uid): array {
    $st = $db->prepare("SELECT question_key, answer_hash FROM " . FP_ANSWERS_TABLE . " WHERE user_id = ? ORDER BY slot");
    $st->bind_param("i", $uid);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}
// Answers are compared case-insensitively and ignoring extra spaces.
// If sq_save() normalises differently, change this to match.
function fp_norm(string $s): string {
    return preg_replace('/\s+/u', ' ', trim(mb_strtolower($s, 'UTF-8')));
}
// Tries the normalised form first, then plain-trimmed and raw, in case sq_save() normalises differently.
function fp_answer_matches(string $given, string $hash): bool {
    foreach ([fp_norm($given), trim($given), $given] as $cand) {
        if ($cand !== '' && password_verify($cand, $hash)) return true;
    }
    return false;
}
/* ──────────────────────────────────────────────────────────────────────────────────────────── */

const FP_MAX_ATTEMPTS = 5;      // wrong tries before lockout
const FP_LOCK_SECONDS = 900;    // 15 minutes
const FP_RESET_WINDOW = 600;    // 10 minutes to set the new password after answering correctly

function fp_reset_state(): void { unset($_SESSION['fp']); }
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

if (isset($_GET['restart'])) { fp_reset_state(); header("Location: forgot_password.php"); exit; }

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));

$fp = $_SESSION['fp'] ?? ['step' => 1, 'uid' => 0, 'fails' => 0, 'locked_until' => 0, 'verified_at' => 0];
$error = '';
$questions = [];   // [question_key => question text] for step 2

// Lockout check
$locked = $fp['locked_until'] > time();
if ($locked) {
    $mins = (int)ceil(($fp['locked_until'] - time()) / 60);
    $error = "Too many failed attempts. Please try again in about {$mins} minute(s).";
    $fp['step'] = 1; $fp['uid'] = 0;
} elseif ($fp['locked_until'] && $fp['locked_until'] <= time()) {
    $fp['fails'] = 0; $fp['locked_until'] = 0;
}

if (!$locked && $_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $error = "Your session expired. Please try again.";
        $fp = ['step' => 1, 'uid' => 0, 'fails' => 0, 'locked_until' => 0, 'verified_at' => 0];
    } else {
        $action = $_POST['action'] ?? '';

        /* ── STEP 1: username ─────────────────────────────── */
        if ($action === 'username') {
            $username = trim($_POST['username'] ?? '');
            if ($username === '') {
                $error = "Please enter your username.";
            } else {
                $stmt = $mysqli->prepare(
                    "SELECT id FROM users
                     WHERE BINARY username = ? AND role IN ('admin','superadmin','registrar') AND is_active = 1 LIMIT 1"
                );
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $user = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                $rows = $user ? fp_get_user_answers($mysqli, (int)$user['id']) : [];
                if ($user && count($rows) > 0) {
                    $fp['uid'] = (int)$user['id'];
                    $fp['step'] = 2;
                    $fp['verified_at'] = 0;
                } else {
                    // Same message whether the account is missing or has no questions saved
                    $fp['fails']++;
                    $error = "We couldn't start recovery for that username. Check it and try again, or contact your System Administrator.";
                }
            }
        }

        /* ── STEP 2: answer the user's own security questions ─ */
        elseif ($action === 'answers' && $fp['step'] === 2 && $fp['uid']) {
            $rows  = fp_get_user_answers($mysqli, $fp['uid']);
            $given = $_POST['answer'] ?? [];
            $ok = count($rows) > 0;
            foreach ($rows as $r) {
                $a = is_array($given) ? (string)($given[$r['question_key']] ?? '') : '';
                if ($a === '' || !fp_answer_matches($a, $r['answer_hash'])) $ok = false;
            }
            if ($ok) {
                $fp['step'] = 3;
                $fp['verified_at'] = time();
                $fp['fails'] = 0;
                session_regenerate_id(true);
            } else {
                $fp['fails']++;
                $error = "One or more answers are incorrect.";
            }
        }

        /* ── STEP 3: new password ─────────────────────────── */
        elseif ($action === 'reset' && $fp['step'] === 3 && $fp['uid']) {
            if (time() - $fp['verified_at'] > FP_RESET_WINDOW) {
                $error = "Verification expired. Please start again.";
                $fp = ['step' => 1, 'uid' => 0, 'fails' => 0, 'locked_until' => 0, 'verified_at' => 0];
            } else {
                $pw  = $_POST['password'] ?? '';
                $pw2 = $_POST['confirm_password'] ?? '';
                if (strlen($pw) < 8)       $error = "Password must be at least 8 characters.";
                elseif ($pw !== $pw2)      $error = "Passwords do not match.";
                else {
                    $hash = password_hash($pw, PASSWORD_DEFAULT);
                    $upd = $mysqli->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                    $upd->bind_param("si", $hash, $fp['uid']);
                    $upd->execute();
                    $upd->close();
                    fp_reset_state();
                    $_SESSION['reg_success'] = "Password updated! You can now sign in.";
                    header("Location: admin_login.php");
                    exit;
                }
            }
        }
    }

    if ($fp['fails'] >= FP_MAX_ATTEMPTS) {
        $fp['locked_until'] = time() + FP_LOCK_SECONDS;
        $fp['step'] = 1; $fp['uid'] = 0;
        $error = "Too many failed attempts. Please try again in 15 minutes.";
    }
}

$_SESSION['fp'] = $fp;

// Build the user's own questions for display (step 2)
if ($fp['step'] === 2 && $fp['uid']) {
    foreach (fp_get_user_answers($mysqli, $fp['uid']) as $r) {
        $questions[$r['question_key']] = $sq_all[$r['question_key']] ?? $r['question_key'];
    }
}
$step = $fp['step'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>PBI Admin — Forgot Password</title>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<style>
:root{--dark-blue:#0A192F;--blue-mid:#172A45;--blue-inner:#0F1F3D;--blue-accent:#2F6FE4;--blue-hover:#4B82F1;--light:#E0E6F0;--muted:#A0B3C6;--radius:10px;--shadow:0 8px 32px rgba(0,0,0,.45);}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{min-height:100vh;background:#0A192F;font-family:'DM Sans',sans-serif;color:var(--light);display:flex;align-items:center;justify-content:center;padding:24px;overflow-x:hidden;position:relative;}
.bg-grid{position:fixed;inset:0;z-index:0;background-image:repeating-linear-gradient(45deg,rgba(74,130,241,.08) 0px,rgba(74,130,241,.08) 1px,transparent 1px,transparent 26px),repeating-linear-gradient(-45deg,rgba(74,130,241,.05) 0px,rgba(74,130,241,.05) 1px,transparent 1px,transparent 26px);}
.orb{position:fixed;border-radius:50%;filter:blur(85px);z-index:0;pointer-events:none;}
.orb-1{width:420px;height:420px;background:radial-gradient(circle,rgba(47,111,228,.16) 0%,transparent 70%);top:-100px;left:-100px;}
.orb-2{width:320px;height:320px;background:radial-gradient(circle,rgba(75,130,241,.13) 0%,transparent 70%);bottom:-80px;right:-80px;}
.card{position:relative;z-index:10;background:rgba(23,42,69,.92);backdrop-filter:blur(18px);border:1px solid rgba(255,255,255,.09);border-radius:18px;padding:40px 40px 32px;width:100%;max-width:420px;box-shadow:var(--shadow);animation:cardIn .7s cubic-bezier(.22,1,.36,1) both;}
@keyframes cardIn{from{opacity:0;transform:translateY(32px) scale(.97)}to{opacity:1;transform:none}}
.card-header{text-align:center;margin-bottom:24px;}
.icon-wrap{width:64px;height:64px;border-radius:50%;background:rgba(47,111,228,.12);border:2px solid var(--blue-accent);display:flex;align-items:center;justify-content:center;margin:0 auto 14px;font-size:26px;color:#9CC0FF;box-shadow:0 0 20px rgba(47,111,228,.20);}
.logo-img{width:72px;height:72px;border-radius:50%;object-fit:cover;display:block;border:2.5px solid var(--blue-accent);box-shadow:0 0 22px rgba(47,111,228,.35);margin:0 auto 14px;}
.toggle-pw{position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--muted);cursor:pointer;font-size:14px;width:26px;height:30px;display:flex;align-items:center;justify-content:center;z-index:3;line-height:1;}
.toggle-pw:hover{color:var(--blue-hover);}
.toggle-pw i{display:block!important;}
.input-wrap .form-input.has-toggle{padding-right:46px!important;}
.card-title{font-family:'Rajdhani',sans-serif;font-size:24px;font-weight:700;letter-spacing:2px;color:#fff;text-transform:uppercase;}
.card-subtitle{font-size:12px;color:var(--muted);margin-top:6px;line-height:1.6;}
.divider{height:1px;background:linear-gradient(90deg,transparent,rgba(47,111,228,.35),transparent);margin-bottom:22px;}
.steps{display:flex;justify-content:center;gap:8px;margin-bottom:18px;}
.steps span{width:28px;height:4px;border-radius:4px;background:rgba(255,255,255,.12);}
.steps span.on{background:var(--blue-accent);}
.form-group{margin-bottom:16px;}
.form-label{display:block;font-size:11px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:var(--muted);margin-bottom:6px;}
.q-label{text-transform:none;letter-spacing:.2px;font-size:13px;color:var(--light);line-height:1.4;}
.input-wrap{position:relative;}
.f-icon{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:13px;pointer-events:none;z-index:2;}
.form-input{width:100%;padding:12px 13px 12px 38px;background:rgba(10,25,47,.70);border:1px solid rgba(255,255,255,.10);border-radius:var(--radius);color:var(--light);font-size:14px;font-family:'DM Sans',sans-serif;outline:none;transition:border-color .25s,box-shadow .25s;}
.input-wrap .form-input{padding-left:42px!important;} /* keeps text clear of the icon */
.form-input::placeholder{color:rgba(160,179,198,.45);}
.form-input:focus{border-color:var(--blue-accent);box-shadow:0 0 0 3px rgba(47,111,228,.18);}
.alert{border-radius:8px;padding:10px 13px;font-size:13px;margin-bottom:16px;display:flex;align-items:flex-start;gap:8px;line-height:1.45;}
.alert-error{background:rgba(240,84,84,.12);border:1px solid rgba(240,84,84,.35);color:#fca5a5;}
.alert-success{background:rgba(74,222,128,.10);border:1px solid rgba(74,222,128,.30);color:#86efac;}
.btn-main{width:100%;padding:13px;background:#2F6FE4;border:none;border-radius:var(--radius);color:#fff;font-size:15px;font-weight:600;font-family:'DM Sans',sans-serif;cursor:pointer;transition:background .2s,transform .15s;box-shadow:0 4px 16px rgba(47,111,228,.32);display:flex;align-items:center;justify-content:center;gap:8px;}
.btn-main:hover{background:#4B82F1;transform:translateY(-1px);}
.back-row{text-align:center;margin-top:18px;font-size:13px;color:var(--muted);}
.back-row a{color:#F2C94C!important;font-weight:600;text-decoration:none;}
.back-row a:hover{color:#FFD866!important;text-decoration:underline;}
</style>

<link rel="stylesheet" href="admin_appearance.css">
<script src="admin_appearance.js"></script>
</head>
<body>
<div class="bg-grid"></div>
<div class="orb orb-1"></div>
<div class="orb orb-2"></div>

<div class="card">
    <div class="card-header">
        <img class="logo-img" src="../image/pbi_logo" alt="PBI Logo"
             onerror="this.style.display='none';document.getElementById('fallbackIcon').style.display='flex'"/>
        <div class="icon-wrap" id="fallbackIcon" style="display:none">
            <i class="fa-solid <?= $step === 1 ? 'fa-key' : ($step === 2 ? 'fa-shield-halved' : 'fa-lock') ?>"></i>
        </div>
        <div class="card-title">
            <?= $step === 1 ? 'Forgot Password' : ($step === 2 ? 'Security Questions' : 'New Password') ?>
        </div>
        <div class="card-subtitle">
            <?php if ($step === 1): ?>Enter your username to begin account recovery.
            <?php elseif ($step === 2): ?>Answer the security questions you set when you registered.
            <?php else: ?>Identity verified. Choose a new password for your account.<?php endif; ?>
        </div>
    </div>
    <div class="divider"></div>

    <div class="steps"><span class="on"></span><span class="<?= $step >= 2 ? 'on' : '' ?>"></span><span class="<?= $step >= 3 ? 'on' : '' ?>"></span></div>

    <?php if ($error): ?>
    <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><span><?= h($error) ?></span></div>
    <?php endif; ?>

    <?php if ($step === 1): ?>
    <form method="POST" action="forgot_password.php" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"/>
        <input type="hidden" name="action" value="username"/>
        <div class="form-group">
            <label class="form-label" for="username">Username</label>
            <div class="input-wrap">
                <i class="fa-solid fa-user f-icon"></i>
                <input class="form-input" id="username" type="text" name="username" placeholder="Enter your username" required autofocus <?= $locked ? 'disabled' : '' ?>/>
            </div>
        </div>
        <button type="submit" class="btn-main" <?= $locked ? 'disabled' : '' ?>>
            <i class="fa-solid fa-arrow-right"></i> Continue
        </button>
    </form>

    <?php elseif ($step === 2): ?>
    <form method="POST" action="forgot_password.php" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"/>
        <input type="hidden" name="action" value="answers"/>
        <?php $n = 1; foreach ($questions as $key => $text): ?>
        <div class="form-group">
            <label class="form-label q-label" for="a<?= $n ?>"><?= $n ?>. <?= h($text) ?></label>
            <div class="input-wrap">
                <i class="fa-solid fa-comment-dots f-icon"></i>
                <input class="form-input" id="a<?= $n ?>" type="text" name="answer[<?= h($key) ?>]" maxlength="100" placeholder="Your answer" required <?= $n === 1 ? 'autofocus' : '' ?>/>
            </div>
        </div>
        <?php $n++; endforeach; ?>
        <button type="submit" class="btn-main">
            <i class="fa-solid fa-shield-check"></i> Verify Answers
        </button>
    </form>

    <?php else: ?>
    <form method="POST" action="forgot_password.php" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"/>
        <input type="hidden" name="action" value="reset"/>
        <div class="form-group">
            <label class="form-label" for="password">New Password</label>
            <div class="input-wrap">
                <i class="fa-solid fa-lock f-icon"></i>
                <input class="form-input has-toggle" id="password" type="password" name="password" placeholder="At least 8 characters" minlength="8" required autofocus/>
                <button type="button" class="toggle-pw" data-target="password" aria-label="Show password" title="Show password"><i class="fa-solid fa-eye-slash"></i></button>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label" for="confirm_password">Confirm Password</label>
            <div class="input-wrap">
                <i class="fa-solid fa-lock f-icon"></i>
                <input class="form-input has-toggle" id="confirm_password" type="password" name="confirm_password" placeholder="Re-enter new password" minlength="8" required/>
                <button type="button" class="toggle-pw" data-target="confirm_password" aria-label="Show password" title="Show password"><i class="fa-solid fa-eye-slash"></i></button>
            </div>
        </div>
        <button type="submit" class="btn-main">
            <i class="fa-solid fa-floppy-disk"></i> Update Password
        </button>
    </form>
    <?php endif; ?>

    <div class="back-row">
        <?php if ($step > 1): ?>
        <a href="forgot_password.php?restart=1"><i class="fa-solid fa-rotate-left"></i> Start over</a> &nbsp;·&nbsp;
        <?php endif; ?>
        <a href="admin_login.php"><i class="fa-solid fa-arrow-left"></i> Back to Login</a>
    </div>
</div>
<script>
document.querySelectorAll('.toggle-pw').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var el = document.getElementById(btn.dataset.target), ic = btn.querySelector('i');
        var showing = el.type === 'password';
        el.type = showing ? 'text' : 'password';
        ic.className = showing ? 'fa-solid fa-eye' : 'fa-solid fa-eye-slash';
        btn.setAttribute('aria-label', showing ? 'Hide password' : 'Show password');
        btn.setAttribute('title', showing ? 'Hide password' : 'Show password');
    });
});
</script>
</body>
</html>
