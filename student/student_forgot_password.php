<?php
// student/forgot_password.php
// Password recovery with security questions (no email involved).
//   Step 1: student enters their username (must be a real account that has all 3 questions saved).
//   Step 2: student answers their 3 security questions.
//   If all 3 are right, a short-lived reset permission is stored in the SESSION
//   (never in a URL or on screen) and the student is sent to reset_password.php.
require_once 'security.php';
secure_session_start();
require_once 'db.php';
security_ensure_tables($mysqli);

$error = '';

if (($_GET['restart'] ?? '') === '1') {
    unset($_SESSION['fp']);
    header("Location: forgot_password.php");
    exit;
}

// Recovery attempt state expires after 15 minutes
$fp = $_SESSION['fp'] ?? null;
if ($fp && (time() - (int)($fp['t'] ?? 0)) > 900) { unset($_SESSION['fp']); $fp = null; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $step = $_POST['step'] ?? '';

    if (!csrf_valid($_POST['csrf_token'] ?? '')) {
        $error = "Your session expired. Please try again.";

    } elseif ($step === 'username') {
        $u     = trim($_POST['username'] ?? '');
        $ident = mb_strtolower(mb_substr($u, 0, 100));
        if ($u === '' || mb_strlen($u) > 100) {
            $error = "Please enter your username.";
        } elseif (auth_is_locked($mysqli, 'reset', $ident)) {
            $error = AUTH_LOCK_MESSAGE;
        } else {
            $stmt = $mysqli->prepare(
                "SELECT id FROM users
                 WHERE username = ? AND role = 'student' AND is_active = 1 AND account_status = 'approved' LIMIT 1"
            );
            $stmt->bind_param("s", $u);
            $stmt->execute();
            $acct = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            // Only a real, active, approved account with all 3 saved questions may continue.
            $uid = 0; $keys = [];
            if ($acct) {
                $qrows = sq_load($mysqli, (int)$acct['id']);
                if (count($qrows) === 3 && isset($qrows[1], $qrows[2], $qrows[3])) {
                    $uid = (int)$acct['id'];
                    for ($i = 1; $i <= 3; $i++) { $keys[] = $qrows[$i]['question_key']; }   // slot order, as chosen at registration
                }
            }

            if (!$uid) {
                auth_record($mysqli, 'reset', $ident, false);
                $error = "We couldn't start recovery for that username. Check it and try again, or contact your System Administrator.";
            } else {
                $_SESSION['fp'] = ['u' => $u, 't' => time(), 'uid' => $uid, 'keys' => $keys];
                $fp = $_SESSION['fp'];
            }
        }

    } elseif ($step === 'answers' && $fp && !empty($fp['uid'])) {
        $u     = $fp['u'];
        $ident = mb_strtolower(mb_substr($u, 0, 100));

        if (auth_is_locked($mysqli, 'reset', $ident)) {
            $error = AUTH_LOCK_MESSAGE;
        } else {
            $rows  = sq_load($mysqli, (int)$fp['uid']);
            $valid = count($rows) === 3;
            $given = (array)($_POST['answer'] ?? []);

            // Check all three every time (no early exit). The questions must still be the ones shown.
            $allOk = true;
            for ($slot = 1; $slot <= 3; $slot++) {
                $hash  = $rows[$slot]['answer_hash'] ?? DUMMY_HASH;
                $ok    = password_verify(sq_normalize(mb_substr((string)($given[$slot] ?? ''), 0, 100)), $hash);
                $same  = isset($rows[$slot]) && $rows[$slot]['question_key'] === ($fp['keys'][$slot - 1] ?? null);
                $allOk = $allOk && $ok && $same;
            }

            if ($valid && $allOk) {
                auth_clear($mysqli, 'reset', $ident);
                auth_record($mysqli, 'reset', $ident, true);
                session_regenerate_id(true);
                unset($_SESSION['fp']);
                $_SESSION['pw_reset'] = ['uid' => (int)$fp['uid'], 'exp' => time() + 600];   // 10 minutes
                header("Location: reset_password.php");
                exit;
            }

            auth_record($mysqli, 'reset', $ident, false);
            $error = "Those answers do not match our records.";
        }
    }
}

// Questions shown at step 2 are the account's own, saved at registration (looked up in step 1)
$fp = $_SESSION['fp'] ?? null;
$showQuestions = [];
if ($fp && !empty($fp['keys'])) {
    $all = sq_all_questions();
    foreach ($fp['keys'] as $k) $showQuestions[] = $all[$k] ?? 'Answer your security question.';
} else {
    $fp = null;
}
$stage = $fp ? 2 : 1;
$subtitles = [
    1 => 'Enter your username to begin recovering your password.',
    2 => 'Answer the three security questions you chose when you registered.',
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
:root{--accent:#D97706;--accent-h:#F59E0B;--btn-text:#ffffff;--dark:#0A192F;--mid:#172A45;--inner:#0F1F3D;--light:#E0E6F0;--muted:#A0B3C6;}
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
.back-row a:hover{text-decoration:underline}
.back-row .sep{color:var(--muted);margin:0 8px}

/* Match Admin (EA) forgot-password card size + page background */
:root{--auth-navy-deep:#091727;}
html,body{background:var(--auth-navy-deep)!important;}
body{
  background:
    radial-gradient(circle at 10% 8%,rgba(47,110,226,.13),transparent 30%),
    radial-gradient(circle at 90% 90%,rgba(242,201,76,.08),transparent 28%),
    repeating-linear-gradient(45deg,rgba(79,129,184,.045) 0,rgba(79,129,184,.045) 1px,transparent 1px,transparent 26px),
    repeating-linear-gradient(-45deg,rgba(79,129,184,.035) 0,rgba(79,129,184,.035) 1px,transparent 1px,transparent 26px),
    var(--auth-navy-deep)!important;
  background-attachment:fixed!important;
}
.card{max-width:420px!important;padding:40px 40px 32px!important;}
.logo-img{margin-bottom:18px!important;}
.card-title{letter-spacing:2px!important;}
.role-pill{display:none!important;}
.card-subtitle{font-size:13px!important;margin-top:8px!important;color:#7F93AB!important;}
.divider{margin:24px 0 0!important;}
.steps{margin:20px 0 18px!important;}
.form-input{min-height:0!important;height:36px!important;padding-top:0!important;padding-bottom:0!important;}
.btn-main{min-height:0!important;height:38px!important;padding:0 16px!important;}
.help-note.hide-note{display:none!important;}
.back-row{border-top:0!important;padding-top:0!important;margin-top:18px!important;}
@media(max-width:480px){.card{padding:32px 22px 28px!important;}}
</style>
</head>
<body>
<div class="card">
    <div class="card-header">
        <img class="logo-img" src="../image/pbi_logo" alt="PBI Logo"
             onerror="this.style.display='none';document.getElementById('fallbackIcon').style.display='flex'"/>
        <div class="icon-wrap" id="fallbackIcon" style="display:none"><i class="fa-solid <?= $stage === 2 ? 'fa-shield-halved' : 'fa-key' ?>"></i></div>
        <div class="card-title">Forgot Password</div>
        <div class="role-pill">Student Account</div>
        <div class="card-subtitle"><?= htmlspecialchars($subtitles[$stage]) ?></div>
    </div>
    <div class="divider"></div>
    <div class="steps" aria-hidden="true"><span class="on"></span><span class="<?= $stage >= 2 ? 'on' : '' ?>"></span><span></span></div>

    <?php if ($error): ?>
    <div class="alert alert-error" role="alert"><i class="fa-solid fa-circle-exclamation"></i><span><?= htmlspecialchars($error) ?></span></div>
    <?php endif; ?>

    <?php if ($stage === 1): ?>
    <form method="POST" action="forgot_password.php" autocomplete="off" id="fpForm">
        <?= csrf_field() ?>
        <input type="hidden" name="step" value="username">
        <div class="form-group">
            <label class="form-label" for="username">Username</label>
            <div class="input-wrap">
                <input class="form-input" type="text" id="username" name="username" maxlength="100" placeholder="Enter your username" required autofocus autocomplete="username"/>
                <i class="fa-solid fa-user f-icon"></i>
            </div>
        </div>
        <button type="submit" class="btn-main" id="fpBtn"><i class="fa-solid fa-arrow-right"></i> Continue</button>
    </form>
    <p class="help-note hide-note">You will be asked the three security questions you chose when you registered (or last changed in Settings). If you can't answer them, please contact your administrator.</p>

    <?php else: ?>
    <form method="POST" action="forgot_password.php" autocomplete="off" id="fpForm">
        <?= csrf_field() ?>
        <input type="hidden" name="step" value="answers">
        <?php foreach ($showQuestions as $i => $qText): $n = $i + 1; ?>
        <div class="form-group">
            <label class="form-label" for="ans<?= $n ?>"><span class="qn"><?= $n ?>.</span> <?= htmlspecialchars($qText) ?></label>
            <input class="form-input plain" type="text" id="ans<?= $n ?>" name="answer[<?= $n ?>]" maxlength="100" placeholder="Your answer" required autocomplete="off" <?= $n === 1 ? 'autofocus' : '' ?>/>
        </div>
        <?php endforeach; ?>
        <button type="submit" class="btn-main" id="fpBtn"><i class="fa-solid fa-circle-check"></i> Verify Answers</button>
    </form>
    <p class="help-note">Answers are not case-sensitive. Too many wrong attempts will lock recovery for 15 minutes.</p>
    <?php endif; ?>

    <div class="back-row">
        <a href="student_login.php"><i class="fa-solid fa-arrow-left"></i> Back to Login</a>
        <?php if ($stage > 1): ?><span class="sep">·</span><a href="forgot_password.php?restart=1">Start over</a><?php endif; ?>
    </div>
</div>
<script>
const fpForm = document.getElementById('fpForm');
const fpBtn  = document.getElementById('fpBtn');
fpForm.addEventListener('submit', () => { fpBtn.disabled = true; });
window.addEventListener('pageshow', (e) => { if (e.persisted) fpBtn.disabled = false; });
</script>
</body>
</html>
