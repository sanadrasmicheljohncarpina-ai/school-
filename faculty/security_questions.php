<?php
// faculty/security_questions.php
// Lets a logged-in Faculty (role 'teacher') or Staff (role 'staff') member set up or change the
// 3 security questions used for password recovery. Accounts created before this feature have
// none, so they set them here. Saving requires the current password.
require_once 'security.php';
secure_session_start();
require_once 'db.php';

$role = (string)($_SESSION['role'] ?? '');
if (empty($_SESSION['user_id']) || !in_array($role, ['teacher', 'staff'], true)) {
    header('Location: faculty_login.php');
    exit;
}
$user_id = (int)$_SESSION['user_id'];

// Each portal keeps its own look, login page and dashboard.
$PORTALS = [
    'teacher' => ['label' => 'Faculty', 'login' => 'faculty_login.php', 'dash' => 'faculty_dashboard.php',
                  'accent' => '#2563EB', 'hover' => '#3B82F6', 'light' => '#60A5FA', 'rgb' => '37,99,235', 'top' => '#3B82F6', 'bot' => '#1D4ED8'],
    'staff'   => ['label' => 'Staff',   'login' => 'staff_login.php',   'dash' => 'staff_dashboard.php',
                  'accent' => '#16A34A', 'hover' => '#22C55E', 'light' => '#4ADE80', 'rgb' => '22,163,74', 'top' => '#22C55E', 'bot' => '#15803D'],
];
$P    = $PORTALS[$role];
$back = $P['dash'] . '?page=settings';

security_ensure_tables($mysqli);

// This account, in this portal's role only.
$st = $mysqli->prepare("SELECT username, password_hash FROM users WHERE id = ? AND role = ? AND is_active = 1 LIMIT 1");
$st->bind_param('is', $user_id, $role);
$st->execute();
$me = $st->get_result()->fetch_assoc();
$st->close();
if (!$me) { header('Location: ' . $P['login']); exit; }

$kind  = 'sqchange_' . $role;
$error = $success = '';

// Step 1 = confirm the current password (unlocks this page for a few minutes).
// Step 2 = the questions themselves; saving needs the unlock, not the password again.
$UNLOCK_KEY = 'sq_unlock_' . $role;
$UNLOCK_TTL = 600;
if ($_SERVER['REQUEST_METHOD'] === 'GET') { unset($_SESSION[$UNLOCK_KEY]); }   // each visit starts at the password step
$unlocked = !empty($_SESSION[$UNLOCK_KEY]) && (time() - (int)$_SESSION[$UNLOCK_KEY]) <= $UNLOCK_TTL;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if (!csrf_valid($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please try again.';
    } elseif ($action === 'verify') {
        $current = (string)($_POST['current_password'] ?? '');
        if (auth_is_locked($mysqli, $kind, (string)$user_id)) {
            $error = AUTH_LOCK_MESSAGE;
        } elseif (!password_verify($current, $me['password_hash'])) {
            auth_record($mysqli, $kind, (string)$user_id, false);
            $error = 'Current password is incorrect.';
        } else {
            auth_clear($mysqli, $kind, (string)$user_id);
            $_SESSION[$UNLOCK_KEY] = time();
            $unlocked = true;
            $_POST = [];
        }
    } elseif ($action === 'save') {
        if (!$unlocked) {
            $error = 'Please confirm your current password first.';
        } else {
            $existingRows = sq_load($mysqli, $user_id);
            [$sqErr, $sqRows] = function_exists('sq_validate_update')
                ? sq_validate_update($_POST['sq_question'] ?? [], $_POST['sq_answer'] ?? [], (string)$me['username'], $existingRows)
                : sq_validate($_POST['sq_question'] ?? [], $_POST['sq_answer'] ?? [], (string)$me['username']);
            if ($sqErr !== null) {
                $error = $sqErr;
            } else {
                try {
                    $mysqli->begin_transaction();
                    sq_save($mysqli, $user_id, $sqRows);
                    $mysqli->commit();
                    unset($_SESSION[$UNLOCK_KEY]);
                    $unlocked = false;
                    $success = 'Your security questions were saved. You can now use them to recover your password.';
                    $_POST = [];
                } catch (Throwable $e) {
                    try { $mysqli->rollback(); } catch (Throwable $ignored) {}
                    error_log('security_questions save failed: ' . $e->getMessage());
                    $error = 'Could not save your security questions. Please try again in a moment.';
                }
            }
        }
    }
}

$saved = sq_load($mysqli, $user_id);                 // questions only, never answers
$isSet = count($saved) === 3;
$all   = sq_all_questions();
$active = sq_questions();
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$orig = []; $cur = [];
for ($n = 1; $n <= 3; $n++) {
    $o = (string)($saved[$n]['question_key'] ?? '');
    $orig[$n] = isset($active[$o]) ? $o : '';          // retired questions are not pre-selected
    $pq = (string)($_POST['sq_question'][$n] ?? '');
    $cur[$n] = ($pq !== '' && isset($active[$pq])) ? $pq : $orig[$n];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>PBI — Security Questions</title>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<style>
:root{--dark-blue:#0A192F;--accent:<?= $P['accent'] ?>;--accent-hover:<?= $P['hover'] ?>;--accent-light:<?= $P['light'] ?>;--rgb:<?= $P['rgb'] ?>;--light:#E0E6F0;--muted:#A0B3C6;}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{min-height:100vh;background:var(--dark-blue);font-family:'DM Sans',sans-serif;color:var(--light);display:flex;align-items:center;justify-content:center;padding:24px;position:relative;}
.bg-grid{position:fixed;inset:0;z-index:0;background-image:linear-gradient(rgba(var(--rgb),.06) 1px,transparent 1px),linear-gradient(90deg,rgba(var(--rgb),.06) 1px,transparent 1px);background-size:48px 48px;}
.card{position:relative;z-index:10;width:100%;max-width:540px;background:linear-gradient(180deg,#1D3555 0%,#172A45 38%,#122238 100%);border:1px solid rgba(var(--rgb),.30);border-radius:18px;padding:32px 32px 26px;box-shadow:0 30px 80px rgba(0,0,0,.5),inset 0 1px 0 rgba(255,255,255,.07);}
.card-header{text-align:center;margin-bottom:18px;}
.icon-wrap{width:60px;height:60px;border-radius:50%;background:rgba(var(--rgb),.15);border:2px solid var(--accent);display:flex;align-items:center;justify-content:center;margin:0 auto 12px;font-size:24px;color:var(--accent-light);box-shadow:0 0 24px rgba(var(--rgb),.35);}
.card-title{font-family:'Rajdhani',sans-serif;font-size:23px;font-weight:700;letter-spacing:2px;color:#fff;text-transform:uppercase;}
.role-pill{display:inline-flex;align-items:center;gap:6px;margin-top:8px;padding:4px 14px;border-radius:20px;background:rgba(var(--rgb),.14);border:1px solid rgba(var(--rgb),.4);color:var(--accent-light);font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;}
.card-subtitle{font-size:12.5px;color:var(--muted);margin-top:10px;line-height:1.6;}
.divider{height:1px;background:linear-gradient(90deg,transparent,rgba(var(--rgb),.55),transparent);margin-bottom:18px;}
.form-group{margin-bottom:14px;}
.form-label{display:block;font-size:10.5px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--muted);margin-bottom:6px;}
.required{color:#f87171;margin-left:3px;}
.input-wrap{position:relative;}
.f-icon{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:13px;pointer-events:none;}
.form-input{width:100%;height:44px;padding:0 13px 0 38px;background:#0B1A32;border:1px solid rgba(255,255,255,.12);border-radius:10px;color:var(--light);font:500 14px 'DM Sans',sans-serif;outline:none;box-shadow:inset 0 2px 6px rgba(0,0,0,.30);transition:border-color .2s,box-shadow .2s;}
.form-input.plain{padding-left:13px;}
.form-input:focus{border-color:var(--accent);box-shadow:inset 0 2px 6px rgba(0,0,0,.22),0 0 0 3px rgba(var(--rgb),.22);}
.form-input.field-invalid{border-color:#f05454;}
select.form-input{appearance:none;-webkit-appearance:none;cursor:pointer;padding-right:34px;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23A0B3C6' stroke-width='3'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 13px center;}
select.form-input option{background:#0B1A32;color:#E0E6F0;}
select.form-input option:disabled{color:#7b8ea3;}
.sq-item{display:flex;flex-direction:column;gap:6px;margin-bottom:14px;}
.toggle-pw{position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--muted);cursor:pointer;font-size:13px;padding:4px;}
.toggle-pw:hover{color:#fff;}
.alert{border-radius:8px;padding:10px 13px;font-size:13px;margin-bottom:14px;display:flex;align-items:flex-start;gap:8px;line-height:1.45;}
.alert i{margin-top:2px;}
.alert-error{background:rgba(240,84,84,.12);border:1px solid rgba(240,84,84,.35);color:#ff8a8a;}
.alert-success{background:rgba(34,197,94,.10);border:1px solid rgba(34,197,94,.30);color:#86efac;}
.alert-info{background:rgba(var(--rgb),.10);border:1px solid rgba(var(--rgb),.35);color:var(--accent-light);}
.saved-list{list-style:none;margin:0 0 14px;padding:10px 12px;border-radius:10px;background:rgba(10,25,47,.55);border:1px solid rgba(255,255,255,.08);font-size:12px;line-height:1.5;}
.saved-list li{padding:2px 0;}
.saved-list li::before{content:'\f058';font-family:'Font Awesome 6 Free';font-weight:900;color:var(--accent-light);margin-right:8px;}
.btn-main{width:100%;height:46px;margin-top:4px;display:flex;align-items:center;justify-content:center;gap:9px;background:linear-gradient(180deg,<?= $P['top'] ?> 0%,var(--accent) 55%,<?= $P['bot'] ?> 100%);border:none;border-radius:10px;color:#fff;font:700 14px 'DM Sans',sans-serif;cursor:pointer;box-shadow:inset 0 1px 0 rgba(255,255,255,.24),0 10px 26px rgba(var(--rgb),.36);transition:transform .15s,filter .2s;}
.btn-main:hover{filter:brightness(1.08);transform:translateY(-1px);}
.btn-ghost{display:flex;align-items:center;justify-content:center;gap:8px;margin-top:12px;font-size:13px;font-weight:600;color:var(--accent-light);text-decoration:none;}
.btn-ghost:hover{text-decoration:underline;}
@media(max-width:480px){.card{padding:26px 18px 20px;}}
</style>
</head>
<body>
<div class="bg-grid" aria-hidden="true"></div>
<div class="card">
    <div class="card-header">
        <div class="icon-wrap"><i class="fa-solid fa-shield-halved"></i></div>
        <div class="card-title">Security Questions</div>
        <div class="role-pill"><i class="fa-solid fa-id-badge"></i> <?= $h($P['label']) ?> Access</div>
        <div class="card-subtitle">Choose 3 different questions. If you forget your password, you will answer all three to reset it. Use short, simple answers you will type the same way every time. Answers are stored encrypted and are not case-sensitive.</div>
    </div>
    <div class="divider"></div>

    <?php if ($error): ?>
    <div class="alert alert-error" role="alert"><i class="fa-solid fa-circle-exclamation"></i><span><?= $h($error) ?></span></div>
    <?php endif; ?>
    <?php if ($success): ?>
    <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i><span><?= $h($success) ?></span></div>
    <?php endif; ?>

    <?php if ($success): ?>
    <a class="btn-ghost" href="security_questions.php"><i class="fa-solid fa-pen-to-square"></i> Update again</a>
    <?php elseif (!$unlocked): ?>
    <div class="alert alert-info"><i class="fa-solid fa-lock"></i><span><?= $isSet ? 'Enter your current password first to see and change your security questions.' : 'Enter your current password first, then you can set up your security questions.' ?></span></div>
    <form method="POST" autocomplete="off" id="gateForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="verify">
        <div class="form-group">
            <label class="form-label" for="current_password">Current Password<span class="required">*</span></label>
            <div class="input-wrap">
                <i class="fa-solid fa-lock f-icon"></i>
                <input class="form-input" type="password" id="current_password" name="current_password" placeholder="Confirm it is you" autocomplete="current-password" required/>
                <button type="button" class="toggle-pw" onclick="togglePw()" aria-label="Show password"><i class="fa-solid fa-eye-slash" id="eye1"></i></button>
            </div>
        </div>
        <button type="submit" class="btn-main"><i class="fa-solid fa-unlock-keyhole"></i> Continue</button>
    </form>
    <?php else: ?>
    <?php if ($isSet): ?>
    <div class="alert alert-info"><i class="fa-solid fa-circle-info"></i><span>Your current questions are shown. Change just one if you like, and leave the other answers as they are to keep them.</span></div>
    <?php else: ?>
    <div class="alert alert-info"><i class="fa-solid fa-triangle-exclamation"></i><span>You have not set up your security questions yet. Without them you cannot recover a forgotten password yourself.</span></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off" id="sqForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <?php for ($n = 1; $n <= 3; $n++): ?>
        <div class="sq-item">
            <label class="form-label" for="sq_q<?= $n ?>">Question <?= $n ?><span class="required">*</span></label>
            <select class="form-input sq-select" name="sq_question[<?= $n ?>]" id="sq_q<?= $n ?>" data-orig="<?= $h($orig[$n]) ?>" required>
                <option value="" disabled <?= $cur[$n] === '' ? 'selected' : '' ?>>Choose a question</option>
                <?php foreach ($active as $k => $q): ?>
                <option value="<?= $h($k) ?>" <?= ($cur[$n] === $k) ? 'selected' : '' ?>><?= $h($q) ?></option>
                <?php endforeach; ?>
            </select>
            <input class="form-input plain" type="text" name="sq_answer[<?= $n ?>]" id="sq_a<?= $n ?>" maxlength="100" placeholder="<?= ($orig[$n] !== '' && $cur[$n] === $orig[$n]) ? '&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;' : 'Your answer' ?>" autocomplete="off"/>
        </div>
        <?php endfor; ?>

        <div class="alert alert-error" id="clientError" role="alert" style="display:none"><i class="fa-solid fa-circle-exclamation"></i><span id="clientErrorText"></span></div>
        <button type="submit" class="btn-main"><i class="fa-solid fa-shield-halved"></i> Save Security Questions</button>
    </form>
    <?php endif; ?>
    <a class="btn-ghost" href="<?= $h($back) ?>"><i class="fa-solid fa-arrow-left"></i> Back to Settings</a>
</div>

<script>
function togglePw(){
    const e = document.getElementById('current_password'), i = document.getElementById('eye1');
    if (!e || !i) return;
    const showing = e.type === 'password';
    e.type = showing ? 'text' : 'password';
    i.className = showing ? 'fa-solid fa-eye' : 'fa-solid fa-eye-slash';
}
(function(){
    const form = document.getElementById('sqForm');
    if (!form) return;
    const selects = [1,2,3].map(n => document.getElementById('sq_q' + n));
    const answers = [1,2,3].map(n => document.getElementById('sq_a' + n));
    const orig = selects.map(s => s.getAttribute('data-orig') || '');
    const kept = i => orig[i] !== '' && selects[i].value === orig[i];

    // Keep the three dropdowns from repeating each other, and show dots on answers that are kept.
    function sync(){
        selects.forEach(function(s){
            Array.from(s.options).forEach(function(o){
                if (!o.value) return;
                o.disabled = selects.some(function(t){ return t !== s && t.value === o.value; });
            });
        });
        answers.forEach(function(a, i){ a.placeholder = kept(i) ? '\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022' : 'Your answer'; });
    }
    selects.forEach(s => s.addEventListener('change', sync));
    sync();

    form.addEventListener('submit', function(e){
        const box = document.getElementById('clientError'), txt = document.getElementById('clientErrorText');
        let msg = '';
        const qs  = selects.map(s => s.value);
        const ans = answers.map(a => a.value.trim().toLowerCase().replace(/[^\p{L}\p{N}]+/gu, ''));
        if (qs.some(v => !v)) msg = 'Please choose all three questions.';
        else if (new Set(qs).size !== 3) msg = 'Please choose three different questions.';
        else if (![0,1,2].some(i => !kept(i) || ans[i] !== '')) msg = 'Change at least one question or answer before saving.';
        else if ([0,1,2].some(i => !(kept(i) && ans[i] === '') && ans[i].length < 3)) msg = 'Each new answer needs at least 3 letters or numbers.';
        if (msg) { e.preventDefault(); txt.textContent = msg; box.style.display = 'flex'; }
        else box.style.display = 'none';
    });
})();
</script>
</body>
</html>
