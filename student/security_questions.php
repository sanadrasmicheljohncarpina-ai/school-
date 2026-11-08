<?php
// student/security_questions.php
// Lets a signed-in student set or change their security questions.
// Existing students are sent here right after login until they have set them up (?setup=1).
require_once 'security.php';
secure_session_start();
require_once 'db.php';
security_ensure_tables($mysqli);

// Students, deans and principals can all manage their security questions.
$sqRoutes = [
    'student'   => ['login' => 'student_login.php',   'back' => 'student_dashboard.php',         'backLabel' => 'Dashboard'],
    'dean'      => ['login' => 'dean_login.php',      'back' => 'dean_account_settings.php',     'backLabel' => 'Settings'],
    'principal' => ['login' => 'principal_login.php', 'back' => 'principal_account_settings.php', 'backLabel' => 'Settings'],
];
$role = (string)($_SESSION['role'] ?? '');
if (empty($_SESSION['user_id']) || !isset($sqRoutes[$role])) {
    header("Location: student_login.php");
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
<link rel="stylesheet" href="student_ui.css"/>
<link rel="stylesheet" href="student_recovery.css"/>
</head>
<body>
<div class="bg-grid" aria-hidden="true"></div>
<svg class="hex-deco hex-1" width="260" height="260" viewBox="0 0 260 260" aria-hidden="true"><polygon points="130,10 240,70 240,190 130,250 20,190 20,70" fill="none" stroke="#D97706" stroke-width="1"/><polygon points="130,50 200,90 200,170 130,210 60,170 60,90" fill="none" stroke="#D97706" stroke-width="1"/></svg>
<svg class="hex-deco hex-2" width="300" height="300" viewBox="0 0 300 300" aria-hidden="true"><polygon points="150,10 280,80 280,220 150,290 20,220 20,80" fill="none" stroke="#2B6CB0" stroke-width="1"/><polygon points="150,60 220,100 220,200 150,240 80,200 80,100" fill="none" stroke="#2B6CB0" stroke-width="1"/></svg>

<div class="card wide">
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
    <p class="help-note">Choose 3 different questions only you can answer, and avoid answers a classmate could guess. Use short answers you will type the same way every time (for example, just a name). Answers are stored encrypted and are not case-sensitive.</p>

    <div class="back-row">
        <a href="<?= htmlspecialchars($route['back']) ?>"><i class="fa-solid fa-arrow-left"></i> <?= $setup ? 'Skip for now' : 'Back to ' . htmlspecialchars($route['backLabel']) ?></a>
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
