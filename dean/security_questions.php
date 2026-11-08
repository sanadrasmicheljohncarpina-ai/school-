<?php
// security_questions.php  (copy this SAME file into both the dean/ and principal/ folders)
// Lets a signed-in dean or principal set or change their security questions.
// Uses the shared security.php in the same folder. Self-contained styling (no student CSS needed).
require_once 'security.php';
secure_session_start();
require_once 'db.php';
security_ensure_tables($mysqli);

// Per-role settings: where to go if signed out, where "back" goes, and the accent colour.
$sqRoutes = [
    'dean'      => ['login' => 'dean_login.php',      'back' => 'dean_account_settings.php',      'accent' => '#7C5FD9', 'accentH' => '#9C85F0'],
    'principal' => ['login' => 'principal_login.php', 'back' => 'principal_account_settings.php', 'accent' => '#D99A2B', 'accentH' => '#F0B84D'],
];
$role = (string)($_SESSION['role'] ?? '');
if (empty($_SESSION['user_id']) || !isset($sqRoutes[$role])) {
    header("Location: dean_login.php");
    exit;
}
$route = $sqRoutes[$role];
$uid   = (int)$_SESSION['user_id'];
$setup = ($_GET['setup'] ?? '') === '1';

$error = $success = '';
$sq_all = sq_questions();
$existing = sq_load($mysqli, $uid);
$hasQuestions = count($existing) === 3;
$hasRetired   = $hasQuestions && sq_needs_update($existing);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ident = 'uid:' . $uid;

    $stmt = $mysqli->prepare("SELECT username, password_hash FROM users WHERE id = ? AND role = ? LIMIT 1");
    $stmt->bind_param("is", $uid, $role);
    $stmt->execute();
    $me = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!csrf_valid($_POST['csrf_token'] ?? '')) {
        $error = "Your session expired. Please try again.";
    } elseif (!$me) {
        header("Location: " . $route['login']);
        exit;
    } elseif (auth_is_locked($mysqli, 'sqchange', $ident)) {
        $error = AUTH_LOCK_MESSAGE;
    } elseif (!password_verify((string)($_POST['current_password'] ?? ''), $me['password_hash'])) {
        auth_record($mysqli, 'sqchange', $ident, false);
        $error = "Your current password is incorrect.";
    } else {
        [$sqErr, $rows] = sq_validate($_POST['sq_question'] ?? [], $_POST['sq_answer'] ?? [], $me['username']);
        if ($sqErr) {
            $error = $sqErr;
        } else {
            try {
                $mysqli->begin_transaction();
                sq_save($mysqli, $uid, $rows);
                $mysqli->commit();
                auth_clear($mysqli, 'sqchange', $ident);
                if ($setup) { header("Location: " . $route['back']); exit; }
                $success = "Your security questions have been saved.";
                $hasQuestions = true;
            } catch (Throwable $e) {
                try { $mysqli->rollback(); } catch (Throwable $ignored) {}
                error_log('security_questions save failed: ' . $e->getMessage());
                $error = "Could not save your security questions. Please try again.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>PBI — Security Questions</title>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<style>
:root{--accent:<?= $route['accent'] ?>;--accent-h:<?= $route['accentH'] ?>;--dark:#0A192F;--mid:#172A45;--inner:#0F1F3D;--light:#E0E6F0;--muted:#A0B3C6;--danger:#f05454;--good:#10B981;}
*{box-sizing:border-box;margin:0;padding:0}
body{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;background:var(--dark);color:var(--light);font-family:'DM Sans',sans-serif;font-size:14px}
.card{width:100%;max-width:560px;background:rgba(23,42,69,.92);border:1px solid rgba(255,255,255,.08);border-radius:16px;padding:32px;box-shadow:0 8px 32px rgba(0,0,0,.45);position:relative}
.card-header{text-align:center}
.icon-wrap{width:56px;height:56px;margin:0 auto 12px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:22px;color:var(--accent);background:color-mix(in srgb,var(--accent) 16%,transparent)}
.card-title{font-family:'Rajdhani',sans-serif;font-size:28px;font-weight:700;color:#fff}
.card-subtitle{color:var(--muted);margin-top:4px;line-height:1.5}
.divider{height:1px;background:rgba(255,255,255,.08);margin:20px 0}
.alert{display:flex;gap:10px;align-items:flex-start;padding:12px 14px;border-radius:10px;margin-bottom:16px;font-size:13px;line-height:1.45}
.alert i{margin-top:2px}
.alert-error{background:rgba(240,84,84,.12);border:1px solid rgba(240,84,84,.35);color:#fca5a5}
.alert-success{background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.35);color:#6ee7b7}
.alert-info{background:rgba(59,130,246,.12);border:1px solid rgba(59,130,246,.35);color:#93c5fd}
.sq-block{background:var(--inner);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:16px;margin-bottom:16px}
.q-num{display:block;font-size:11px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:var(--accent-h);margin-bottom:8px}
.form-group{margin-bottom:14px}.sq-block .form-group:last-child{margin-bottom:0}
.form-label{display:block;font-size:11px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--muted);margin-bottom:6px}
.input-wrap{position:relative}
.f-icon{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:13px}
.form-input{width:100%;padding:11px 14px;background:var(--dark);border:1px solid rgba(255,255,255,.14);border-radius:9px;color:#fff;font:500 14px 'DM Sans',sans-serif}
.input-wrap .form-input{padding-left:38px}
.form-input.plain{padding-left:14px}
.form-input:focus{outline:none;border-color:var(--accent)}
.form-input option{background:var(--mid);color:#fff}
.form-input option:disabled{color:#64748b}
.select-arr::after{content:'\f078';font-family:'Font Awesome 6 Free';font-weight:900;position:absolute;right:14px;top:50%;transform:translateY(-50%);font-size:11px;color:var(--muted);pointer-events:none}
.select-arr select{appearance:none;-webkit-appearance:none;padding-right:36px;padding-left:14px}
.sq-chosen{font-size:12px;color:var(--muted);margin:-2px 0 10px;line-height:1.4}.sq-chosen:empty{display:none}
.btn-main{width:100%;padding:12px 18px;border:0;border-radius:10px;background:var(--accent);color:#fff;font:600 14.5px 'DM Sans',sans-serif;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:8px;transition:.2s}
.btn-main:hover{background:var(--accent-h);transform:translateY(-1px)}
.btn-main:disabled{opacity:.6;cursor:not-allowed;transform:none}
.help-note{font-size:12px;color:var(--muted);line-height:1.55;margin-top:16px}
.back-row{text-align:center;margin-top:18px}
.back-row a{color:var(--accent-h);text-decoration:none;font-weight:600;font-size:13.5px}
.back-row a:hover{text-decoration:underline}
</style>
</head>
<body>
<div class="card">
    <div class="card-header">
        <div class="icon-wrap"><i class="fa-solid fa-shield-halved"></i></div>
        <div class="card-title">Security Questions</div>
        <div class="card-subtitle">
            <?= $setup
                ? 'Set up your security questions. You will need them if you ever forget your password.'
                : ($hasQuestions ? 'Change the questions used to recover your password.' : 'Set the questions used to recover your password.') ?>
        </div>
    </div>
    <div class="divider"></div>

    <?php if ($hasRetired && !$success): ?>
    <div class="alert alert-info"><i class="fa-solid fa-circle-info"></i><span>One of your current questions is no longer offered because it was too easy to guess. Please choose new questions below.</span></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-error" role="alert"><i class="fa-solid fa-circle-exclamation"></i><span><?= htmlspecialchars($error) ?></span></div>
    <?php endif; ?>
    <?php if ($success): ?>
    <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i><span><?= htmlspecialchars($success) ?></span></div>
    <?php endif; ?>

    <form method="POST" action="security_questions.php<?= $setup ? '?setup=1' : '' ?>" autocomplete="off" id="sqForm">
        <?= csrf_field() ?>
        <?php for ($n = 1; $n <= 3; $n++): ?>
        <div class="sq-block">
            <span class="q-num">Question <?= $n ?></span>
            <div class="form-group" style="margin-bottom:10px">
                <div class="input-wrap select-arr">
                    <select class="form-input sq-select" name="sq_question[<?= $n ?>]" id="sq_q<?= $n ?>" required>
                        <option value="" disabled <?= empty($_POST['sq_question'][$n]) ? 'selected' : '' ?>>Choose a question</option>
                        <?php foreach ($sq_all as $k => $q): ?>
                        <option value="<?= htmlspecialchars($k) ?>" <?= (($_POST['sq_question'][$n] ?? '') === $k) ? 'selected' : '' ?>><?= htmlspecialchars($q) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="sq-chosen" id="sq_chosen<?= $n ?>" aria-live="polite"></div>
            <div class="form-group">
                <input class="form-input plain" type="text" name="sq_answer[<?= $n ?>]" id="sq_a<?= $n ?>" maxlength="100"
                       value="<?= $error ? htmlspecialchars(is_string($_POST['sq_answer'][$n] ?? null) ? $_POST['sq_answer'][$n] : '') : '' ?>" placeholder="Your answer" required autocomplete="off"/>
            </div>
        </div>
        <?php endfor; ?>

        <div class="form-group">
            <label class="form-label" for="cp">Confirm with your current password</label>
            <div class="input-wrap">
                <i class="fa-solid fa-lock f-icon"></i>
                <input class="form-input" type="password" id="cp" name="current_password" placeholder="Current password"
                       required autocomplete="current-password"/>
            </div>
        </div>
        <div class="alert alert-error" id="clientError" style="display:none"><i class="fa-solid fa-circle-exclamation"></i><span id="clientErrorText"></span></div>
        <button type="submit" class="btn-main" id="sqBtn"><i class="fa-solid fa-floppy-disk"></i> Save Security Questions</button>
    </form>
    <p class="help-note">Choose 3 different questions only you can answer, and avoid answers someone could guess. Use short answers you will type the same way every time (for example, just a name). Answers are stored encrypted and are not case-sensitive.</p>

    <div class="back-row">
        <a href="<?= htmlspecialchars($route['back']) ?>"><i class="fa-solid fa-arrow-left"></i> <?= $setup ? 'Skip for now' : 'Back to Settings' ?></a>
    </div>
</div>
<script>
function syncChoices() {
    const sel = [1,2,3].map(n => document.getElementById('sq_q' + n));
    const chosen = sel.map(s => s.value).filter(Boolean);
    sel.forEach((s, i) => {
        for (const o of s.options) o.disabled = (o.value !== '' && chosen.includes(o.value) && o.value !== s.value) || (o.value === '' );
        document.getElementById('sq_chosen' + (i + 1)).textContent = s.value ? s.options[s.selectedIndex].text : '';
    });
}
document.querySelectorAll('.sq-select').forEach(s => s.addEventListener('change', syncChoices));
syncChoices();

const form = document.getElementById('sqForm');
form.addEventListener('submit', (e) => {
    const norm = v => v.trim().toLowerCase().replace(/[^\p{L}\p{N}]+/gu, '');
    const ans = [1,2,3].map(n => norm(document.getElementById('sq_a' + n).value));
    let msg = '';
    if (ans.some(a => a.length < 3)) msg = 'Each answer needs at least 3 letters or numbers.';
    else if (new Set(ans).size !== 3) msg = 'Each answer must be different.';
    if (msg) {
        e.preventDefault();
        document.getElementById('clientErrorText').textContent = msg;
        document.getElementById('clientError').style.display = 'flex';
        return;
    }
    document.getElementById('sqBtn').disabled = true;
});
window.addEventListener('pageshow', (e) => { if (e.persisted) document.getElementById('sqBtn').disabled = false; });
</script>
</body>
</html>
