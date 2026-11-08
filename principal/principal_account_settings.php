<?php
require_once 'principal_common.php';

// principal_account_settings.php
// Principal self-service account settings: profile details, profile photo,
// and password. Structured the same way as dean_account_settings.php.

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['principal_settings_csrf'])) {
    $_SESSION['principal_settings_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['principal_settings_csrf'];

$errors = [];
$success = null;
if (!empty($_SESSION['sq_flash'])) { $success = (string)$_SESSION['sq_flash']; unset($_SESSION['sq_flash']); }
$userId = (int)$_SESSION['user_id'];

// ── SECURITY QUESTIONS STATUS (for the Settings card) ────────
$sq_status = 'none'; // none | ok | retired
try {
    if (!function_exists('sq_load')) { require_once 'security.php'; }
    security_ensure_tables($mysqli);
    $sq_existing = sq_load($mysqli, $userId);
    if (count($sq_existing) === 3) { $sq_status = sq_needs_update($sq_existing) ? 'retired' : 'ok'; }
} catch (Throwable $e) { error_log('settings sq_load failed: ' . $e->getMessage()); }

$validateCsrf = function () use ($csrfToken): bool {
    return isset($_POST['csrf_token']) && hash_equals($csrfToken, (string)$_POST['csrf_token']);
};

// ── SECURITY QUESTIONS POPUP: save (AJAX, returns JSON) ───────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['sq_ajax'] ?? '') === '1') {
    $out = ['ok' => false, 'message' => 'Something went wrong. Please try again.'];
    try {
        if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
            $out['message'] = 'Your session expired. Please reload the page and try again.';
        } else {
            if (!function_exists('sq_validate')) { require_once 'security.php'; }
            security_ensure_tables($mysqli);

            // Find the stored password (supports the legacy plain-text column too).
            $storedPw = null;
            foreach (['password_hash', 'password'] as $col) {
                try {
                    $st = $mysqli->prepare("SELECT `$col` AS pw FROM users WHERE id = ? LIMIT 1");
                    if (!$st) continue;
                    $st->bind_param('i', $userId); $st->execute();
                    $r = $st->get_result()->fetch_assoc(); $st->close();
                    if ($r && isset($r['pw']) && (string)$r['pw'] !== '') { $storedPw = (string)$r['pw']; break; }
                } catch (Throwable $e) { /* column missing: try the next one */ }
            }
            $given = (string)($_POST['current_password'] ?? '');
            $pwOk = false;
            if ($storedPw !== null && $given !== '') {
                $pwOk = !empty(password_get_info($storedPw)['algo'])
                    ? password_verify($given, $storedPw)
                    : hash_equals($storedPw, $given);
            }
            if ((int)($_SESSION['sq_lock_until'] ?? 0) > time()) {
                $out['message'] = 'Too many incorrect attempts. Please wait a minute and try again.';
            } elseif (!$pwOk) {
                $_SESSION['sq_fail'] = (int)($_SESSION['sq_fail'] ?? 0) + 1;
                if ($_SESSION['sq_fail'] >= 5) { $_SESSION['sq_lock_until'] = time() + 60; $_SESSION['sq_fail'] = 0; }
                $out['message'] = 'Your current password is not correct.';
            } elseif (($_POST['sq_verify'] ?? '') === '1') {
                // Step 1: the password is confirmed; the questions can now be shown.
                unset($_SESSION['sq_fail']);
                $out = ['ok' => true, 'message' => 'Password confirmed.'];
            } else {
                unset($_SESSION['sq_fail']);
                [$sqErr, $sqRows] = function_exists('sq_validate_update')
                    ? sq_validate_update($_POST['sq_question'] ?? [], $_POST['sq_answer'] ?? [], (string)($me['username'] ?? ''), sq_load($mysqli, $userId))
                    : sq_validate($_POST['sq_question'] ?? [], $_POST['sq_answer'] ?? [], (string)($me['username'] ?? ''));
                if ($sqErr !== null) {
                    $out['message'] = $sqErr;
                } else {
                    $mysqli->begin_transaction();
                    try {
                        $del = $mysqli->prepare('DELETE FROM student_security_answers WHERE user_id = ?');
                        $del->bind_param('i', $userId);
                        if (!$del->execute()) { throw new RuntimeException('delete failed'); }
                        $del->close();
                        sq_save($mysqli, $userId, $sqRows);
                        $mysqli->commit();
                        $_SESSION['sq_flash'] = 'Security questions updated successfully.';
                        $out = ['ok' => true, 'message' => 'Security questions updated successfully.'];
                    } catch (Throwable $e) {
                        try { $mysqli->rollback(); } catch (Throwable $ignored) {}
                        error_log('dean security questions save failed: ' . $e->getMessage());
                        $out['message'] = 'The security questions could not be saved. Please try again.';
                    }
                }
            }
        }
    } catch (Throwable $e) {
        error_log('dean security questions failed: ' . $e->getMessage());
    }
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($out);
    exit;
}

// ═══════════════════════════════════════════════════════════
// HANDLE FORM SUBMISSIONS
// ═══════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$validateCsrf()) {
        $errors[] = 'Your session expired. Please refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        // ── PROFILE DETAILS ───────────────────────────────────────────
        if ($action === 'update_profile') {
            $newName        = trim((string)($_POST['full_name'] ?? ''));
            $newUsername    = trim((string)($_POST['username'] ?? ''));
            $newDesignation = trim((string)($_POST['designation'] ?? ''));

            if ($newName === '') {
                $errors[] = "Full name can't be empty.";
            } elseif (mb_strlen($newName) > 150) {
                $errors[] = 'Full name must be 150 characters or fewer.';
            }

            if ($newDesignation === '') {
                $errors[] = "Designation can't be empty.";
            } elseif (mb_strlen($newDesignation) > 100) {
                $errors[] = 'Designation must be 100 characters or fewer.';
            }

            if ($newUsername === '') {
                $errors[] = "Username can't be empty.";
            } elseif (mb_strlen($newUsername) > 50) {
                $errors[] = 'Username must be 50 characters or fewer.';
            } elseif (preg_match('/\s/', $newUsername)) {
                $errors[] = 'Username cannot contain spaces.';
            }

            if (!$errors) {
                try {
                    // Keep the username unique while excluding this Principal.
                    $check = $mysqli->prepare('SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1');
                    $check->bind_param('si', $newUsername, $userId);
                    $check->execute();
                    $duplicate = $check->get_result()->fetch_assoc();
                    $check->close();

                    if ($duplicate) {
                        $errors[] = 'That username is already in use.';
                    } else {
                        $stmt = $mysqli->prepare('UPDATE users SET full_name = ?, username = ?, designation = ? WHERE id = ?');
                        $stmt->bind_param('sssi', $newName, $newUsername, $newDesignation, $userId);
                        $stmt->execute();
                        $stmt->close();

                        $me['full_name']   = $newName;
                        $me['username']    = $newUsername;
                        $me['designation'] = $newDesignation;
                        $_SESSION['full_name'] = $newName;
                        $_SESSION['username']  = $newUsername;
                        $success = 'Profile details updated successfully.';
                    }
                } catch (mysqli_sql_exception $e) {
                    $errors[] = 'Unable to update your profile right now. Please try again.';
                }
            }
        }

        // ── PROFILE PHOTO ─────────────────────────────────────────────
        if ($action === 'change_photo') {
            if (empty($_FILES['photo']['name'])) {
                $errors[] = 'Please choose a photo to upload.';
            } else {
                $file = $_FILES['photo'];
                $allowed = [
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/webp' => 'webp',
                ];

                $mime = '';
                if ($file['error'] === UPLOAD_ERR_OK && is_uploaded_file($file['tmp_name'])) {
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime = $finfo->file($file['tmp_name']) ?: '';
                }

                if ($file['error'] !== UPLOAD_ERR_OK) {
                    $errors[] = 'Photo upload failed. Please try again.';
                } elseif (!isset($allowed[$mime])) {
                    $errors[] = 'Please upload a JPG, PNG, or WEBP image.';
                } elseif ($file['size'] > 5 * 1024 * 1024) {
                    $errors[] = 'Photo must be smaller than 5MB.';
                } else {
                    $destDir = defined('UPLOAD_DIR') ? UPLOAD_DIR : __DIR__ . '/../image/';
                    if (!is_dir($destDir) && !@mkdir($destDir, 0755, true)) {
                        $errors[] = 'The profile photo folder could not be created.';
                    } elseif (!is_writable($destDir)) {
                        $errors[] = 'The profile photo folder is not writable.';
                    } else {
                        $filename = 'principal_' . $userId . '_' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
                        $destination = rtrim($destDir, '/\\') . DIRECTORY_SEPARATOR . $filename;

                        if (move_uploaded_file($file['tmp_name'], $destination)) {
                            $stmt = $mysqli->prepare('UPDATE users SET photo = ? WHERE id = ?');
                            $stmt->bind_param('si', $filename, $userId);
                            $stmt->execute();
                            $stmt->close();

                            // Remove the previous Principal photo only after the new
                            // database value has been saved. Only files this page created
                            // (principal_*) are deleted, never a shared/default image.
                            $oldPhoto = $me['photo'] ?? '';
                            if ($oldPhoto && basename($oldPhoto) === $oldPhoto
                                && strpos($oldPhoto, 'principal_') === 0 && $oldPhoto !== $filename) {
                                $oldPath = rtrim($destDir, '/\\') . DIRECTORY_SEPARATOR . $oldPhoto;
                                if (is_file($oldPath)) {
                                    @unlink($oldPath);
                                }
                            }

                            $me['photo'] = $filename;
                            $photo_src = '../image/' . $filename;
                            $success = 'Profile photo updated successfully.';
                        } else {
                            $errors[] = 'Could not save the uploaded photo. Please try again.';
                        }
                    }
                }
            }
        }

        // ── PASSWORD ──────────────────────────────────────────────────
        if ($action === 'change_password') {
            $current = (string)($_POST['current_password'] ?? '');
            $new     = (string)($_POST['new_password'] ?? '');
            $confirm = (string)($_POST['confirm_password'] ?? '');

            // Read the stored password directly (no helper) and tolerate the
            // common column names, so a lookup problem can't masquerade as a
            // "could not verify" error.
            $pwCol = null;
            $row   = null;
            foreach (['password', 'password_hash'] as $col) {
                try {
                    $stmt = $mysqli->prepare("SELECT `$col` AS pw FROM users WHERE id = ? LIMIT 1");
                    $stmt->bind_param('i', $userId);
                    $stmt->execute();
                    $res = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ($res && isset($res['pw']) && (string)$res['pw'] !== '') {
                        $pwCol = $col;
                        $row   = (string)$res['pw'];
                        break;
                    }
                } catch (Throwable $e) {
                    // Column doesn't exist; try the next candidate.
                }
            }

            // A stored value that isn't a password hash is a legacy plain-text
            // password: compare it directly (it is re-saved as a hash below).
            $currentOk = false;
            if ($row !== null) {
                $currentOk = !empty(password_get_info($row)['algo'])
                    ? password_verify($current, $row)
                    : hash_equals($row, $current);
            }

            if ($row === null) {
                $errors[] = 'Could not verify your current password. Please contact the administrator.';
            } elseif (!$currentOk) {
                $errors[] = 'Your current password is incorrect.';
            } elseif (strlen($new) < 8) {
                $errors[] = 'New password must be at least 8 characters.';
            } elseif ($new !== $confirm) {
                $errors[] = 'New password and confirmation do not match.';
            } elseif ($current === $new) {
                $errors[] = 'Your new password must be different from your current password.';
            } else {
                $hashed = password_hash($new, PASSWORD_DEFAULT);
                $stmt = $mysqli->prepare("UPDATE users SET `$pwCol` = ? WHERE id = ?");
                $stmt->bind_param('si', $hashed, $userId);
                $stmt->execute();
                $stmt->close();
                $success = 'Password changed successfully.';
            }
        }
    }
}

html_head_open('PBI — Account Settings');
render_principal_sidebar('settings', $me, $scopeLabel, $photo_src);
?>

<style id="principal-ea-logs-canvas-final">
/* Light-mode only: scoped so dark mode keeps its own surfaces. */
/* EA System Logs-like visual treatment:
   white viewport + a subtle #F8FAFC feature canvas inset inside it. */
html:where(:not(.dark-theme)),
:where(html:not(.dark-theme)) body{
  background: #FFFFFF !important;
  background-image: none !important;
  color: #172033 !important;
}

:where(html:not(.dark-theme)) .main,
:where(html:not(.dark-theme)) main.main,
:where(html:not(.dark-theme)) .main-content,
:where(html:not(.dark-theme)) .content,
:where(html:not(.dark-theme)) .page-content{
  position: relative !important;
  isolation: isolate !important;
  background: #FFFFFF !important;
  color: #172033 !important;
  min-height: 100vh;
}

/* The feature canvas does NOT cover the whole white page. */
:where(html:not(.dark-theme)) .main::before,
:where(html:not(.dark-theme)) main.main::before{
  content: "";
  position: absolute;
  left: 16px;
  right: 0;
  top: 56px;
  bottom: 0;
  background: #F8FAFC !important;
  border-radius: 16px 0 0 0;
  pointer-events: none;
  z-index: -1;
}

/* Preserve the existing navy Principal sidebar. */
:where(html:not(.dark-theme)) .sidebar{
  background: #0A192F !important;
}

/* Keep feature surfaces white, with only a very light edge/shadow. */
:where(html:not(.dark-theme)) .main .page-header,
:where(html:not(.dark-theme)) .main .section,
:where(html:not(.dark-theme)) .main .card,
:where(html:not(.dark-theme)) .main .panel,
:where(html:not(.dark-theme)) .main .table-card,
:where(html:not(.dark-theme)) .main .content-card,
:where(html:not(.dark-theme)) .main .stat-card,
:where(html:not(.dark-theme)) .main .period-strip,
:where(html:not(.dark-theme)) .main .filter-bar,
:where(html:not(.dark-theme)) .main .table-wrap,
:where(html:not(.dark-theme)) .main .table-card-wrap,
:where(html:not(.dark-theme)) .main .content-panel,
:where(html:not(.dark-theme)) .main .eval-banner,
:where(html:not(.dark-theme)) .main .info-banner,
:where(html:not(.dark-theme)) .main .history-card,
:where(html:not(.dark-theme)) .main .gl-card,
:where(html:not(.dark-theme)) .main .amber-card,
:where(html:not(.dark-theme)) .main .green-card,
:where(html:not(.dark-theme)) .main .red-card,
:where(html:not(.dark-theme)) .main .ra-table-card,
:where(html:not(.dark-theme)) .main .eval-card,
:where(html:not(.dark-theme)) .main .comment-section,
:where(html:not(.dark-theme)) .main .avg-summary,
:where(html:not(.dark-theme)) .main .standing-panel,
:where(html:not(.dark-theme)) .main .person-row,
:where(html:not(.dark-theme)) .main .target-card,
:where(html:not(.dark-theme)) .main .no-eval,
:where(html:not(.dark-theme)) .main .no-data,
:where(html:not(.dark-theme)) .main .no-evaluated,
:where(html:not(.dark-theme)) .main .no-archived,
:where(html:not(.dark-theme)) .main .evaluator-grid,
:where(html:not(.dark-theme)) .main .people-list,
:where(html:not(.dark-theme)) .main .cat-section,
:where(html:not(.dark-theme)) .main .results-card,
:where(html:not(.dark-theme)) .main .result-card,
:where(html:not(.dark-theme)) .main .feature-card{
  background: #FFFFFF !important;
  color: #172033 !important;
  border-color: #D9E4EF !important;
  box-shadow: 0 1px 5px rgba(15,23,42,.035) !important;
}

:where(html:not(.dark-theme)) .main table thead th,
:where(html:not(.dark-theme)) .main .table-head,
:where(html:not(.dark-theme)) .main .table-header,
:where(html:not(.dark-theme)) .main .thead,
:where(html:not(.dark-theme)) .main .subtle-head{
  background: #F4F8FF !important;
  color: #4B6580 !important;
  border-color: #D9E4EF !important;
}

:where(html:not(.dark-theme)) .main table tbody td{
  background: #FFFFFF !important;
  color: #172033 !important;
  border-color: #D9E4EF !important;
}

:where(html:not(.dark-theme)) .main table tbody tr:hover td,
:where(html:not(.dark-theme)) .main .person-row:hover,
:where(html:not(.dark-theme)) .main .standing-item:hover{
  background: #F8FAFC !important;
}

:where(html:not(.dark-theme)) .main input,
:where(html:not(.dark-theme)) .main select,
:where(html:not(.dark-theme)) .main textarea,
:where(html:not(.dark-theme)) .main .search-box input[type=text],
:where(html:not(.dark-theme)) .main .search-box select,
:where(html:not(.dark-theme)) .main .form-group input,
:where(html:not(.dark-theme)) .main .filter-field select,
:where(html:not(.dark-theme)) .main .filter-field input[type=text],
:where(html:not(.dark-theme)) .main .ra-filter-row select,
:where(html:not(.dark-theme)) .main .ra-search,
:where(html:not(.dark-theme)) .main .eval-comment-box{
  background: #FFFFFF !important;
  color: #172033 !important;
  border-color: #CBD5E1 !important;
}

:where(html:not(.dark-theme)) .main .modal,
:where(html:not(.dark-theme)) .main .modal-content,
:where(html:not(.dark-theme)) .main .dropdown,
:where(html:not(.dark-theme)) .main .menu,
:where(html:not(.dark-theme)) .main .popover,
:where(html:not(.dark-theme)) .main .panel{
  background: #FFFFFF !important;
  color: #172033 !important;
  border-color: #D9E4EF !important;
}

@media (max-width: 768px) {
  :where(html:not(.dark-theme)) .main::before,
:where(html:not(.dark-theme)) main.main::before{
    left: 0;
    top: 52px;
    border-radius: 12px 0 0 0;
  }
}

@media print {
  html:where(:not(.dark-theme)),
:where(html:not(.dark-theme)) body,
:where(html:not(.dark-theme)) .main,
:where(html:not(.dark-theme)) main.main,
:where(html:not(.dark-theme)) .main-content,
:where(html:not(.dark-theme)) .content,
:where(html:not(.dark-theme)) .page-content{
    background: #FFFFFF !important;
  }
  :where(html:not(.dark-theme)) .main::before,
:where(html:not(.dark-theme)) main.main::before{
    display: none !important;
  }
}
</style>

<main class="main principal-internal-scroll">
    <div class="page-header">
        <div class="page-title"></div>
    </div>

    <?php if ($success): ?><div class="alert success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php foreach ($errors as $err): ?><div class="alert error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($err) ?></div><?php endforeach; ?>

    <div class="settings-tabs" role="tablist" aria-label="Account settings sections">
        <button type="button" class="settings-tab" role="tab" id="tabBtnProfile" data-tab="profile" aria-controls="tabPanelProfile" aria-selected="true"><i class="fa-solid fa-user"></i> Profile &amp; Security</button>
        <button type="button" class="settings-tab" role="tab" id="tabBtnAppearance" data-tab="appearance" aria-controls="tabPanelAppearance" aria-selected="false" tabindex="-1"><i class="fa-solid fa-palette"></i> Appearance</button>
    </div>

    <div class="settings-tabpanel" role="tabpanel" id="tabPanelProfile" aria-labelledby="tabBtnProfile" data-panel="profile">
    <div class="section ac-card sq-top-card <?= $sq_status === 'ok' ? 'is-set' : 'is-unset' ?>" id="security-questions-settings">
        <div class="sq-top-row">
            <div class="ac-head">
                <span class="ac-icon"><i class="fa-solid <?= $sq_status === 'ok' ? 'fa-shield-halved' : 'fa-triangle-exclamation' ?>"></i></span>
                <div>
                    <h2>Security Questions</h2>
                    <p class="ac-sub">These questions are used to recover your account if you forget your password.</p>
                    <?php if ($sq_status === 'ok'): ?>
                    <div class="sq-status ok"><i class="fa-solid fa-circle-check"></i> Security questions are set</div>
                    <?php elseif ($sq_status === 'retired'): ?>
                    <div class="sq-status warn"><i class="fa-solid fa-triangle-exclamation"></i> One of your questions is no longer offered &mdash; please update them</div>
                    <?php else: ?>
                    <div class="sq-status warn"><i class="fa-solid fa-triangle-exclamation"></i> Not set up yet &mdash; you won&rsquo;t be able to recover your account without them</div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="sq-top-action">
                <button type="button" class="btn-primary" id="sqOpen"><i class="fa-solid fa-pen-to-square"></i> <?= $sq_status === 'none' ? 'Set Security Questions' : 'Update Security Questions' ?></button>
            </div>
        </div>
    </div>

    <div class="section ac-card ac-c2">
        <div class="ac-head">
            <span class="ac-icon"><i class="fa-solid fa-id-badge"></i></span>
            <div>
                <h2>Profile Photo</h2>
                <p class="ac-sub">Update the photo shown in your sidebar and profile.</p>
            </div>
        </div>
        <div class="profile-card">
            <img class="profile-photo-lg" src="<?= htmlspecialchars($photo_src) ?>" alt="Principal profile photo">
            <div class="photo-form-wrap">
                <form method="post" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:10px;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="change_photo">
                    <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required>
                    <button class="btn-primary" type="submit" style="width:fit-content;"><i class="fa-solid fa-upload"></i> Upload New Photo</button>
                </form>
                <div class="photo-help">JPG, PNG, or WEBP only. Maximum file size: 5MB.</div>
            </div>
        </div>
    </div>

    <div class="section ac-card ac-c3">
        <div class="ac-head">
            <span class="ac-icon"><i class="fa-solid fa-user-pen"></i></span>
            <div>
                <h2>Profile Details</h2>
                <p class="ac-sub">Update your name, username, and designation.</p>
            </div>
        </div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="update_profile">
            <div class="form-grid">
                <div class="form-group"><label>Full Name</label><input type="text" name="full_name" maxlength="150" value="<?= htmlspecialchars($me['full_name'] ?? '') ?>" required></div>
                <div class="form-group"><label>Username</label><input type="text" name="username" maxlength="50" value="<?= htmlspecialchars($me['username'] ?? '') ?>" required autocomplete="username"></div>
                <div class="form-group"><label>Designation</label><input type="text" name="designation" maxlength="100" value="<?= htmlspecialchars(($me['designation'] ?? '') !== '' ? $me['designation'] : 'Principal') ?>" placeholder="e.g. Principal" required></div>
            </div>
            <button class="btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button>
        </form>
    </div>

    <div class="section ac-card ac-c4">
        <div class="ac-head">
            <span class="ac-icon"><i class="fa-solid fa-lock"></i></span>
            <div>
                <h2>Change Password</h2>
                <p class="ac-sub">Your new password must be at least 8 characters and different from your current password.</p>
            </div>
        </div>
        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="change_password">
            <div class="form-grid">
                <div class="form-group"><label>Current Password</label><div class="pw-wrap"><input type="password" name="current_password" minlength="1" required autocomplete="current-password"><button type="button" class="pw-toggle" aria-label="Show password" aria-pressed="false" tabindex="0"><svg class="pw-eye" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg><svg class="pw-eye-off" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 19C5 19 1 12 1 12a18.5 18.5 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.1 9.1 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12A3 3 0 1 1 9.88 9.88"/><line x1="1" y1="1" x2="23" y2="23"/></svg></button></div></div>
                <div class="form-group"></div>
                <div class="form-group"><label>New Password</label><div class="pw-wrap"><input type="password" name="new_password" minlength="8" required autocomplete="new-password"><button type="button" class="pw-toggle" aria-label="Show password" aria-pressed="false" tabindex="0"><svg class="pw-eye" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg><svg class="pw-eye-off" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 19C5 19 1 12 1 12a18.5 18.5 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.1 9.1 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12A3 3 0 1 1 9.88 9.88"/><line x1="1" y1="1" x2="23" y2="23"/></svg></button></div></div>
                <div class="form-group"><label>Confirm New Password</label><div class="pw-wrap"><input type="password" name="confirm_password" minlength="8" required autocomplete="new-password"><button type="button" class="pw-toggle" aria-label="Show password" aria-pressed="false" tabindex="0"><svg class="pw-eye" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg><svg class="pw-eye-off" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 19C5 19 1 12 1 12a18.5 18.5 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.1 9.1 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12A3 3 0 1 1 9.88 9.88"/><line x1="1" y1="1" x2="23" y2="23"/></svg></button></div></div>
            </div>
            <button class="btn-primary" type="submit"><i class="fa-solid fa-key"></i> Update Password</button>
        </form>
    </div>
    </div>

    <div class="settings-tabpanel" role="tabpanel" id="tabPanelAppearance" aria-labelledby="tabBtnAppearance" data-panel="appearance" hidden>
    <div class="section appearance-settings-section ac-card ac-c1" id="principal-appearance-settings">
        <div class="appearance-heading-row ac-head">
            <span class="ac-icon"><i class="fa-solid fa-palette"></i></span>
            <div>
                <h2>Appearance</h2>
                <p class="appearance-subtitle ac-sub">Choose your theme, content spacing, and motion preferences.</p>
            </div>
            <span class="appearance-device-badge"><i class="fa-solid fa-laptop"></i> Saved on this device</span>
        </div>

        <div class="appearance-feature-grid">
            <section class="appearance-control-card appearance-theme-card" aria-labelledby="appearanceThemeHeading">
                <div class="appearance-control-head">
                    <span class="appearance-control-icon"><i class="fa-solid fa-circle-half-stroke"></i></span>
                    <div>
                        <h3 id="appearanceThemeHeading">Theme mode</h3>
                        <p>Choose a look that suits your environment.</p>
                    </div>
                </div>
                <div class="appearance-theme-options" role="group" aria-label="Theme mode">
                    <button type="button" class="appearance-choice" id="appearanceLightBtn" data-theme-choice="light" aria-pressed="false" onclick="setPrincipalAppearance('light')">
                        <span class="appearance-choice-icon"><i class="fa-solid fa-sun"></i></span>
                        <span class="appearance-choice-copy"><strong>Light</strong><small>Bright and clean</small></span>
                        <span class="appearance-choice-check"><i class="fa-solid fa-check"></i></span>
                    </button>
                    <button type="button" class="appearance-choice" id="appearanceDarkBtn" data-theme-choice="dark" aria-pressed="false" onclick="setPrincipalAppearance('dark')">
                        <span class="appearance-choice-icon"><i class="fa-solid fa-moon"></i></span>
                        <span class="appearance-choice-copy"><strong>Dark</strong><small>Low-light friendly</small></span>
                        <span class="appearance-choice-check"><i class="fa-solid fa-check"></i></span>
                    </button>
                    <button type="button" class="appearance-choice" id="appearanceSystemBtn" data-theme-choice="system" aria-pressed="false" onclick="setPrincipalAppearance('system')">
                        <span class="appearance-choice-icon"><i class="fa-solid fa-desktop"></i></span>
                        <span class="appearance-choice-copy"><strong>System</strong><small>Follow device setting</small></span>
                        <span class="appearance-choice-check"><i class="fa-solid fa-check"></i></span>
                    </button>
                </div>
                <div class="appearance-inline-status"><i class="fa-solid fa-circle-info"></i> Current mode: <strong id="appearanceCurrentMode">Light</strong></div>
            </section>



            <section class="appearance-control-card" aria-labelledby="appearanceLayoutHeading">
                <div class="appearance-control-head">
                    <span class="appearance-control-icon"><i class="fa-solid fa-sliders"></i></span>
                    <div>
                        <h3 id="appearanceLayoutHeading">Interface spacing</h3>
                        <p>Choose how much space appears around tables and content.</p>
                    </div>
                </div>
                <div class="appearance-density-options" role="group" aria-label="Interface spacing">
                    <button type="button" class="appearance-density-choice" data-density-choice="comfortable" aria-pressed="false" onclick="setPrincipalDensity('comfortable')"><i class="fa-solid fa-expand"></i><span><strong>Comfortable</strong><small>More breathing room</small></span></button>
                    <button type="button" class="appearance-density-choice" data-density-choice="compact" aria-pressed="false" onclick="setPrincipalDensity('compact')"><i class="fa-solid fa-compress"></i><span><strong>Compact</strong><small>More content on screen</small></span></button>
                </div>
            </section>

            <section class="appearance-control-card appearance-accessibility-card" aria-labelledby="appearanceAccessibilityHeading">
                <div class="appearance-control-head">
                    <span class="appearance-control-icon"><i class="fa-solid fa-universal-access"></i></span>
                    <div>
                        <h3 id="appearanceAccessibilityHeading">Accessibility</h3>
                        <p>Reduce interface movement for a calmer experience.</p>
                    </div>
                </div>
                <button type="button" class="appearance-motion-toggle" id="appearanceMotionBtn" aria-pressed="false" onclick="togglePrincipalReducedMotion()">
                    <span class="appearance-motion-copy"><strong>Reduce animations</strong><small>Minimize transitions and motion effects</small></span>
                    <span class="appearance-switch-track" aria-hidden="true"><span class="appearance-switch-thumb"></span></span>
                </button>
                <div class="appearance-accessibility-note"><i class="fa-solid fa-shield-heart"></i> Your settings stay in this browser and do not change other users' views.</div>
            </section>
        </div>

    </div>
    </div>
<?php
if (!function_exists('sq_questions')) { try { require_once 'security.php'; } catch (Throwable $e) {} }
$sqIsNew = ($sq_status === 'none');
$sq_existing = (isset($sq_existing) && is_array($sq_existing)) ? $sq_existing : [];
$sqQuestionList = [];
try { $sqQuestionList = sq_questions(); } catch (Throwable $e) { error_log('sq_questions failed: ' . $e->getMessage()); }
?>
<style id="sqm-styles">
.sq-top-row{display:flex;align-items:center;gap:16px;flex-wrap:wrap}
.sq-top-row .ac-head{flex:1;min-width:260px;margin-bottom:0 !important}
.sq-top-row .sq-top-action{flex:0 0 auto}
.sq-top-row .sq-status{margin:10px 0 0}
.sq-top-card.is-unset{border:2px solid #E2A33B !important;box-shadow:0 0 0 4px rgba(226,163,59,.16) !important}
.sq-top-card.is-unset::before{background:#E2A33B !important}
.sq-top-card.is-set{--ac-c:#D97706}
.sq-top-card.is-unset{--ac-c:#E2A33B}
.sq-status{display:inline-flex;align-items:center;gap:7px;padding:5px 11px;border-radius:999px;font-size:12px;font-weight:600;background:rgba(148,163,184,.12);border:1px solid rgba(148,163,184,.3)}
.sq-status.ok{color:#10B981;border-color:rgba(16,185,129,.4)}
.sq-status.warn{color:#D97706;border-color:rgba(217,119,6,.45)}
.sq-top-card.is-unset .ac-icon{background:rgba(226,163,59,.18) !important;color:#D97706 !important}
.sq-top-card.is-unset .ac-icon i{color:#D97706 !important}

.sqm-overlay{position:fixed;inset:0;z-index:2147483000;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(11,31,58,.55);overscroll-behavior:contain}
.sqm-overlay[hidden]{display:none}
.sqm-modal{--sq-acc:#D97706;--sq-acc-h:#B45309;--sq-line:rgba(128,140,160,.30);width:min(620px,100%);max-height:calc(100vh - 40px);overflow:auto;background:var(--mid,#fff);border:1px solid var(--sq-line);border-radius:16px;padding:20px 22px;box-shadow:0 24px 60px rgba(11,31,58,.35);color:var(--light,#0B1F3A)}
.sqm-modal,.sqm-modal *{box-sizing:border-box}
.sqm-modal,.sqm-modal *:not([class*="fa-"]){font-family:'DM Sans',system-ui,-apple-system,"Segoe UI",sans-serif}
.sqm-modal:focus{outline:none}
.sqm-modal [hidden]{display:none !important}
.sqm-head{position:sticky;top:-20px;z-index:2;margin:-20px -22px 0;padding:20px 22px 6px;background:var(--mid,#fff);border-radius:16px 16px 0 0}
.sqm-head-row{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap}
.sqm-head-text{flex:1;min-width:220px}
.sqm-modal h3{font-size:18px;font-weight:700;line-height:1.25;color:var(--light,#0B1F3A);margin:0 0 4px}
.sqm-text{font-size:13.5px;line-height:1.5;color:var(--muted,#405A76);margin:0}
.sqm-head-actions{display:flex;gap:10px;flex:0 0 auto}
.sqm-btn{height:40px;padding:0 16px;border:1px solid var(--sq-line);background:transparent;color:var(--light,#0B1F3A);border-radius:10px;font-weight:700;font-size:13px;cursor:pointer}
.sqm-btn:hover{border-color:var(--sq-acc)}
.sqm-btn.primary{background:var(--sq-acc);border-color:var(--sq-acc);color:#fff}
.sqm-btn.primary:hover:not(:disabled){background:var(--sq-acc-h);border-color:var(--sq-acc-h)}
.sqm-btn.danger{background:#D6455D;border-color:#D6455D;color:#fff}
.sqm-btn:disabled{opacity:.55;cursor:not-allowed}
.sqm-error{margin-top:12px;padding:10px 12px;border-radius:10px;background:rgba(240,84,84,.12);border:1px solid rgba(240,84,84,.35);color:#E05252;font-size:12.5px;line-height:1.45;font-weight:600}
.sqm-discard{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:12px;padding:10px 12px;border:1px solid #F5D48A;background:rgba(245,212,138,.16);border-radius:10px;color:#B07400;font-size:13px}
.sqm-discard span{flex:1;min-width:180px}
.sqm-sec{margin:12px 0 0}
.sqm-sec-title{font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--muted,#67819E);margin:0 0 6px}
.sqm-confirm{display:flex;gap:12px;align-items:center;padding:12px 14px;border:2px solid var(--sq-acc);border-radius:12px;background:color-mix(in srgb,var(--sq-acc) 10%,transparent);box-shadow:0 0 0 4px color-mix(in srgb,var(--sq-acc) 12%,transparent)}
.sqm-lock{flex:0 0 38px;height:38px;border-radius:50%;display:grid;place-items:center;background:var(--sq-acc);color:#fff;font-size:15px}
.sqm-confirm-body{flex:1;min-width:0}
.sqm-confirm-title{font-size:14px;font-weight:800;color:var(--light,#0B1F3A)}
.sqm-confirm-sub{font-size:12px;color:var(--muted,#5C6B8A);margin:1px 0 6px}
.sqm-modal label{display:flex;flex-direction:column;gap:5px;font-size:12px;font-weight:700;color:var(--muted,#405A76)}
.sqm-modal input[type=text],.sqm-modal input[type=password],.sqm-modal select{width:100%;height:38px;padding:0 12px;border:1px solid var(--sq-line);border-radius:10px;font:500 14px 'DM Sans',system-ui,sans-serif;color:var(--light,#0B1F3A);background:var(--inner,#fff)}
.sqm-modal select{appearance:none;-webkit-appearance:none;cursor:pointer;padding-right:34px;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' fill='none' stroke='%2367819E' stroke-width='2' stroke-linecap='round'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center}
.sqm-modal input:focus,.sqm-modal select:focus{outline:2px solid color-mix(in srgb,var(--sq-acc) 35%,transparent);border-color:var(--sq-acc)}
.sqm-modal select.is-empty{color:var(--sq-acc);font-weight:700}
.sqm-modal select option{background:var(--mid,#fff);color:var(--light,#0B1F3A);font-weight:500}
.sqm-modal select option[value=""]{color:var(--sq-acc);font-weight:700}
.sqm-modal select option:disabled{color:#8A97A8}
.sqm-pw{position:relative}
.sqm-pw input{padding-right:44px !important}
.sqm-eye{position:absolute;right:6px;top:50%;transform:translateY(-50%);border:0;background:none;color:var(--muted,#67819E);cursor:pointer;padding:8px;line-height:1;display:flex;align-items:center;justify-content:center}
.sqm-eye:hover{color:var(--sq-acc)}
.sqm-q{display:flex;gap:12px;align-items:flex-start;margin:0 0 8px}
.sqm-num{flex:0 0 auto;width:28px;height:28px;margin-top:5px;border-radius:50%;display:grid;place-items:center;background:color-mix(in srgb,var(--sq-acc) 14%,transparent);color:var(--sq-acc);font-weight:800;font-size:12.5px}
.sqm-q-fields{flex:1;min-width:0;display:grid;gap:6px}
.sqm-chosen{font-size:12.5px;color:var(--muted,#405A76);line-height:1.4;padding:0 2px}
.sqm-chosen,.sqm-chosen:empty{display:none!important}
.sqm-hint{margin-top:4px;padding:9px 13px;border-radius:10px;font-size:12.5px;line-height:1.45;border:1px solid var(--sq-line);background:var(--inner,#F7FAFF);color:var(--muted,#405A76)}
.sqm-hint b{display:block;font-size:13.5px;color:var(--light,#0B1F3A)}
#sqmGate[hidden],#sqmQsec[hidden]{display:none!important}
.sqm-verify-row{display:flex;align-items:center;gap:12px;margin-top:10px;flex-wrap:wrap}
.sqm-verified{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;font-weight:700;color:#10B981}
.sqm-shake{animation:sqmShake .35s ease}
@keyframes sqmShake{0%,100%{transform:translateX(0)}25%{transform:translateX(-6px)}75%{transform:translateX(6px)}}
@media (max-height:720px){.sqm-modal{padding:16px 20px}.sqm-head{top:-16px;margin:-16px -20px 0;padding:16px 20px 4px}.sqm-sec{margin-top:8px}.sqm-modal input[type=text],.sqm-modal input[type=password],.sqm-modal select{height:34px}.sqm-q{margin-bottom:6px}.sqm-q-fields{gap:4px}.sqm-num{margin-top:3px}.sqm-confirm{padding:8px 12px}.sqm-hint{padding:6px 12px}}
</style>
<div class="sqm-overlay" id="sqmOverlay" hidden>
  <div class="sqm-modal" id="sqmModal" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="sqmTitle">
    <form id="sqmForm" autocomplete="off" novalidate>
      <input type="hidden" name="sq_ajax" value="1">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
      <div class="sqm-head">
        <div class="sqm-head-row">
          <div class="sqm-head-text">
            <h3 id="sqmTitle"><?= $sqIsNew ? 'Set security questions' : 'Update security questions' ?></h3>
            <p class="sqm-text">Used to recover your account if you forget your password. Choose 3 different questions.</p>
          </div>
          <div class="sqm-head-actions">
            <button type="button" class="sqm-btn" id="sqmCancel">Cancel</button>
            <button type="submit" class="sqm-btn primary" id="sqmSave" disabled>Save questions</button>
          </div>
        </div>
        <div class="sqm-discard" id="sqmDiscard" hidden>
          <span>You have unsaved changes. Discard them?</span>
          <button type="button" class="sqm-btn" id="sqmKeep">Keep editing</button>
          <button type="button" class="sqm-btn danger" id="sqmDiscardGo">Discard</button>
        </div>
        <div class="sqm-error" id="sqmError" role="alert" hidden></div>
      </div>

      <div class="sqm-sec" id="sqmGate">
        <div class="sqm-sec-title">Confirm it&rsquo;s you</div>
        <div class="sqm-confirm">
          <span class="sqm-lock"><i class="fa-solid fa-lock"></i></span>
          <div class="sqm-confirm-body">
            <div class="sqm-confirm-title">Enter your current password first</div>
            <div class="sqm-confirm-sub">Confirm your password to unlock your questions.</div>
            <label>Current password
              <span class="sqm-pw">
                <input type="password" id="sqmPw" name="current_password" autocomplete="current-password" placeholder="Enter your current password">
                <button type="button" class="sqm-eye" id="sqmEye" aria-label="Show password" title="Show password"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.9 10.9 0 0 1 12 19C5.5 19 2 12 2 12a18.6 18.6 0 0 1 5.06-5.94"/><path d="M9.9 4.24A10.9 10.9 0 0 1 12 4c6.5 0 10 8 10 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12A3 3 0 1 1 9.88 9.88"/><path d="M1 1l22 22"/></svg></button>
              </span>
            </label>
            <div class="sqm-verify-row">
              <button type="button" class="sqm-btn primary" id="sqmVerify" disabled>Continue</button>
              <span class="sqm-verified" id="sqmVerified" hidden>&#10003; Password confirmed</span>
            </div>
          </div>
        </div>
      </div>

      <div class="sqm-sec" id="sqmQsec" hidden>
        <div class="sqm-sec-title">Your questions</div>
        <?php for ($n = 1; $n <= 3; $n++): ?>
        <div class="sqm-q">
          <span class="sqm-num"><?= $n ?></span>
          <div class="sqm-q-fields">
            <?php $sqCur = (string)($sq_existing[$n]['question_key'] ?? ''); if (!isset($sqQuestionList[$sqCur])) $sqCur = ''; ?>
            <select name="sq_question[<?= $n ?>]" id="sqmQ<?= $n ?>" class="<?= $sqCur === '' ? 'is-empty' : '' ?>" data-orig="<?= htmlspecialchars($sqCur) ?>" aria-label="Question <?= $n ?>">
              <option value="">Choose a question</option>
              <?php foreach ($sqQuestionList as $k => $q): ?><option value="<?= htmlspecialchars((string)$k) ?>"<?= ((string)$k === $sqCur) ? ' selected' : '' ?>><?= htmlspecialchars($q) ?></option><?php endforeach; ?>
            </select>
            <div class="sqm-chosen" id="sqmChosen<?= $n ?>" aria-live="polite"></div>
            <input type="text" name="sq_answer[<?= $n ?>]" id="sqmA<?= $n ?>" maxlength="100" placeholder="Your answer" autocomplete="off" aria-label="Answer <?= $n ?>">
          </div>
        </div>
        <?php endfor; ?>
        <div class="sqm-hint"><b><?= $sqIsNew ? 'Pick a question and give an answer only you would know.' : 'Your current questions are shown. Change just one if you like, and leave the other answers as they are to keep them.' ?></b>For your security, existing answers are never shown.</div>
      </div>
    </form>
  </div>
</div>
<script>
(function(){
  var ov=document.getElementById('sqmOverlay'), md=document.getElementById('sqmModal'), form=document.getElementById('sqmForm');
  var openBtn=document.getElementById('sqOpen'); if(!ov||!form||!openBtn) return;
  document.body.appendChild(ov); /* lift above sidebar / header stacking contexts */
  var save=document.getElementById('sqmSave'), err=document.getElementById('sqmError'), disc=document.getElementById('sqmDiscard');
  var pw=document.getElementById('sqmPw'), eye=document.getElementById('sqmEye');
  var qs=[1,2,3].map(function(n){return document.getElementById('sqmQ'+n);}), as=[1,2,3].map(function(n){return document.getElementById('sqmA'+n);});
  var busy=false, verified=false;
  function resetGate(){
    verified=false; pw.readOnly=false;
    document.getElementById('sqmGate').hidden=false;
    document.getElementById('sqmQsec').hidden=true;
    document.getElementById('sqmVerified').hidden=true;
    document.getElementById('sqmVerify').hidden=false;
  }
  var SQM_EYE='<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>';
  var SQM_SLASH='<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.9 10.9 0 0 1 12 19C5.5 19 2 12 2 12a18.6 18.6 0 0 1 5.06-5.94"/><path d="M9.9 4.24A10.9 10.9 0 0 1 12 4c6.5 0 10 8 10 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12A3 3 0 1 1 9.88 9.88"/><path d="M1 1l22 22"/></svg>';
  var orig=qs.map(function(s){return s.getAttribute('data-orig')||'';});
  function changedAt(i){ return qs[i].value!==orig[i] || as[i].value.trim()!==''; }
  function dirty(){ return (!verified && !!pw.value) || [0,1,2].some(changedAt); }
  function ready(){
    if(!verified || pw.value==='') return false;
    if(!qs.every(function(s){return s.value;})) return false;
    if(![0,1,2].some(changedAt)) return false;
    /* an answer is only optional for a question that is unchanged (its saved answer is kept) */
    return [0,1,2].every(function(i){ return as[i].value.trim()!=='' || (orig[i]!=='' && qs[i].value===orig[i]); });
  }
  function sync(){
    var chosen=qs.map(function(s){return s.value;}).filter(Boolean);
    qs.forEach(function(s,i){
      Array.prototype.forEach.call(s.options,function(o){ o.disabled=o.value!==''&&chosen.indexOf(o.value)!==-1&&o.value!==s.value; });
      s.classList.toggle('is-empty',!s.value);
      document.getElementById('sqmChosen'+(i+1)).textContent=s.value?s.options[s.selectedIndex].text:'';
    });
    as.forEach(function(a,i){ a.placeholder=(orig[i]!==''&&qs[i].value===orig[i])?'\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022':'Your answer'; });
    save.disabled=busy||!ready();
    document.getElementById('sqmVerify').disabled=busy||verified||pw.value==='';
  }
  function showErr(m){ err.textContent=m; err.hidden=!m; }
  function open(){
    form.reset(); resetGate(); showErr(''); disc.hidden=true; pw.type='password';
    eye.innerHTML=SQM_SLASH; eye.setAttribute('aria-label','Show password');
    sync(); ov.hidden=false; md.scrollTop=0;
    setTimeout(function(){ pw.focus({preventScroll:true}); },30);
  }
  function close(){ ov.hidden=true; openBtn.focus(); }
  function requestClose(){
    if(busy) return;
    if(dirty()){ disc.hidden=false; md.scrollTop=0; md.classList.remove('sqm-shake'); void md.offsetWidth; md.classList.add('sqm-shake'); }
    else close();
  }
  openBtn.addEventListener('click',open);
  document.getElementById('sqmCancel').addEventListener('click',requestClose);
  document.getElementById('sqmKeep').addEventListener('click',function(){ disc.hidden=true; });
  document.getElementById('sqmDiscardGo').addEventListener('click',close);
  ov.addEventListener('mousedown',function(e){ if(e.target===ov) requestClose(); });
  document.addEventListener('keydown',function(e){ if(!ov.hidden&&e.key==='Escape'){ e.preventDefault(); requestClose(); } });
  form.addEventListener('input',sync); form.addEventListener('change',sync);
  eye.addEventListener('click',function(){
    var show=pw.type==='password'; pw.type=show?'text':'password';
    eye.innerHTML=show?SQM_EYE:SQM_SLASH;
    eye.setAttribute('aria-label',show?'Hide password':'Show password');
  });
  /* Step 1: the password must be confirmed on the server before the questions appear. */
  function verifyPw(){
    var vb=document.getElementById('sqmVerify');
    if(busy||verified||pw.value==='') return;
    busy=true; vb.disabled=true; vb.textContent='Checking\u2026'; showErr('');
    var fd=new FormData(form); fd.append('sq_verify','1');
    fetch('principal_account_settings.php',{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(r){ return r.json(); })
      .then(function(d){
        if(d&&d.ok){
          verified=true; pw.readOnly=true;
          document.getElementById('sqmGate').hidden=true;
          document.getElementById('sqmQsec').hidden=false;
          document.getElementById('sqmVerified').hidden=false;
          vb.hidden=true;
          setTimeout(function(){ qs[0].focus({preventScroll:true}); },30);
        } else { showErr((d&&d.message)||'Your current password is not correct.'); pw.select(); }
      })
      .catch(function(){ showErr('Could not reach the server. Please try again.'); })
      .then(function(){ busy=false; vb.textContent='Continue'; sync(); });
  }
  document.getElementById('sqmVerify').addEventListener('click',verifyPw);
  pw.addEventListener('keydown',function(e){ if(e.key==='Enter'){ e.preventDefault(); verifyPw(); } });
  form.addEventListener('submit',function(e){
    e.preventDefault(); if(busy||!ready()) return;
    busy=true; save.disabled=true; save.textContent='Saving\u2026'; showErr('');
    fetch('principal_account_settings.php',{method:'POST',body:new FormData(form),credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(r){ return r.json(); })
      .then(function(d){
        if(d&&d.ok){ location.href='principal_account_settings.php'; return; }
        showErr((d&&d.message)||'The security questions could not be saved. Please try again.'); md.scrollTop=0;
      })
      .catch(function(){ showErr('Could not reach the server. Please try again.'); })
      .then(function(){ busy=false; save.textContent='Save questions'; sync(); });
  });
})();
</script>
</main>
<style id="principal-white-theme-final">
/* Light-mode only: scoped so dark mode keeps its own surfaces. */
:root:where(:not(.dark-theme)){
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
html:where(:not(.dark-theme)){background:#F8FAFC!important;color-scheme:light!important;}
:where(html:not(.dark-theme)) body{background:#F8FAFC!important;background-image:none!important;color:#172033!important;}

/* Keep the existing navy sidebar; the content area is the white-theme area. */
:where(html:not(.dark-theme)) .sidebar{background:#0A192F!important;color:#E0E6F0!important;border-right:1px solid #172A45!important;}
:where(html:not(.dark-theme)) .sidebar *{color:inherit;}
:where(html:not(.dark-theme)) .sidebar .sb-name{color:#fff!important;}
:where(html:not(.dark-theme)) .sidebar .sb-role,
:where(html:not(.dark-theme)) .sidebar .sb-nav a i{color:#f0b84d!important;}
:where(html:not(.dark-theme)) .sidebar .sb-scope,
:where(html:not(.dark-theme)) .sidebar .sb-nav a{color:#A0B3C6!important;}
:where(html:not(.dark-theme)) .sidebar .sb-nav a:hover,
:where(html:not(.dark-theme)) .sidebar .sb-nav a.active{background:rgba(217,154,43,.15)!important;color:#fff!important;}
:where(html:not(.dark-theme)) .sidebar .sb-logout a{color:#fca5a5!important;}

/* Main content surfaces */
:where(html:not(.dark-theme)) main,
:where(html:not(.dark-theme)) .main,
:where(html:not(.dark-theme)) .main-content,
:where(html:not(.dark-theme)) .content,
:where(html:not(.dark-theme)) .page-content{background:#F8FAFC!important;color:#172033!important;}
:where(html:not(.dark-theme)) .stat-card,
:where(html:not(.dark-theme)) .section,
:where(html:not(.dark-theme)) .period-strip,
:where(html:not(.dark-theme)) .filter-bar,
:where(html:not(.dark-theme)) .card,
:where(html:not(.dark-theme)) .panel,
:where(html:not(.dark-theme)) .table-wrap,
:where(html:not(.dark-theme)) .content-card,
:where(html:not(.dark-theme)) .table-card,
:where(html:not(.dark-theme)) .summary-card,
:where(html:not(.dark-theme)) .sum-card,
:where(html:not(.dark-theme)) .standing-panel,
:where(html:not(.dark-theme)) .eval-card,
:where(html:not(.dark-theme)) .eval-banner,
:where(html:not(.dark-theme)) .info-banner,
:where(html:not(.dark-theme)) .history-card,
:where(html:not(.dark-theme)) .gl-card,
:where(html:not(.dark-theme)) .amber-card,
:where(html:not(.dark-theme)) .green-card,
:where(html:not(.dark-theme)) .red-card,
:where(html:not(.dark-theme)) .person-row,
:where(html:not(.dark-theme)) .target-card,
:where(html:not(.dark-theme)) .no-eval,
:where(html:not(.dark-theme)) .no-data,
:where(html:not(.dark-theme)) .no-evaluated,
:where(html:not(.dark-theme)) .comment-section,
:where(html:not(.dark-theme)) .avg-summary,
:where(html:not(.dark-theme)) .cat-section,
:where(html:not(.dark-theme)) .people-list,
:where(html:not(.dark-theme)) .evaluator-grid,
:where(html:not(.dark-theme)) .eval-q-card,
:where(html:not(.dark-theme)) .eval-table-wrap,
:where(html:not(.dark-theme)) .received-item,
:where(html:not(.dark-theme)) .q-result,
:where(html:not(.dark-theme)) .info-grid>div,
:where(html:not(.dark-theme)) .comment-modal,
:where(html:not(.dark-theme)) .ra-table-card,
:where(html:not(.dark-theme)) .eval-switcher,
:where(html:not(.dark-theme)) .tabs,
:where(html:not(.dark-theme)) .level-tabs,
:where(html:not(.dark-theme)) .status-tabs,
:where(html:not(.dark-theme)) .faculty-subtabs{
  background:#fff!important;
  color:#172033!important;
  border-color:#e2e8f0!important;
  box-shadow:var(--shadow)!important;
}

/* Headings and readable data text */
:where(html:not(.dark-theme)) .page-title,
:where(html:not(.dark-theme)) .page-header h1,
:where(html:not(.dark-theme)) .section-title,
:where(html:not(.dark-theme)) .sheet-name,
:where(html:not(.dark-theme)) .target-name,
:where(html:not(.dark-theme)) h1,
:where(html:not(.dark-theme)) h2,
:where(html:not(.dark-theme)) h3,
:where(html:not(.dark-theme)) h4,
:where(html:not(.dark-theme)) h5,
:where(html:not(.dark-theme)) h6,
:where(html:not(.dark-theme)) .section h2,
:where(html:not(.dark-theme)) .eval-modal-title,
:where(html:not(.dark-theme)) .modal-section-title,
:where(html:not(.dark-theme)) .stat-card .num,
:where(html:not(.dark-theme)) .tracker-item .big,
:where(html:not(.dark-theme)) .eval-q-text,
:where(html:not(.dark-theme)) .eval-qtext,
:where(html:not(.dark-theme)) .q-text,
:where(html:not(.dark-theme)) .info-value,
:where(html:not(.dark-theme)) .received-anon,
:where(html:not(.dark-theme)) .cat-name-modal,
:where(html:not(.dark-theme)) .cat-score-modal{color:#0f172a!important;}
:where(html:not(.dark-theme)) .page-sub,
:where(html:not(.dark-theme)) .sheet-desig,
:where(html:not(.dark-theme)) .target-desig,
:where(html:not(.dark-theme)) .muted,
:where(html:not(.dark-theme)) .hint,
:where(html:not(.dark-theme)) .helper,
:where(html:not(.dark-theme)) .filter-hint,
:where(html:not(.dark-theme)) .empty-note,
:where(html:not(.dark-theme)) .main p,
:where(html:not(.dark-theme)) .main label,
:where(html:not(.dark-theme)) .main td,
:where(html:not(.dark-theme)) .main li,
:where(html:not(.dark-theme)) .main small,
:where(html:not(.dark-theme)) .q-score,
:where(html:not(.dark-theme)) .q-no,
:where(html:not(.dark-theme)) .received-meta,
:where(html:not(.dark-theme)) .info-label,
:where(html:not(.dark-theme)) .loading-eval,
:where(html:not(.dark-theme)) .eval-rating-scale-note,
:where(html:not(.dark-theme)) .eval-read-score{color:#64748b!important;}
:where(html:not(.dark-theme)) table{color:#172033!important;}
:where(html:not(.dark-theme)) table thead th,
:where(html:not(.dark-theme)) table.data th,
:where(html:not(.dark-theme)) .q-table th{background:#f8fafc!important;color:#334155!important;border-color:#e2e8f0!important;}
:where(html:not(.dark-theme)) table tbody td,
:where(html:not(.dark-theme)) table.data td,
:where(html:not(.dark-theme)) .q-table td{color:#334155!important;border-color:#e2e8f0!important;}
:where(html:not(.dark-theme)) table tbody tr:hover,
:where(html:not(.dark-theme)) .person-row:hover,
:where(html:not(.dark-theme)) .standing-item:hover{background:#f8fafc!important;}

/* Accent elements stay amber/semantic rather than reverting to dark-mode text. */
:where(html:not(.dark-theme)) .stat-card i,
:where(html:not(.dark-theme)) .section h2 i,
:where(html:not(.dark-theme)) .eval-q-category,
:where(html:not(.dark-theme)) .eval-category-heading,
:where(html:not(.dark-theme)) .eval-modal-title i,
:where(html:not(.dark-theme)) .period-item .v,
:where(html:not(.dark-theme)) .period-badge,
:where(html:not(.dark-theme)) .back-link,
:where(html:not(.dark-theme)) .stat-card .label,
:where(html:not(.dark-theme)) .period-item .k,
:where(html:not(.dark-theme)) .received-score,
:where(html:not(.dark-theme)) .score-big,
:where(html:not(.dark-theme)) .cat-score-modal,
:where(html:not(.dark-theme)) .bell-btn,
:where(html:not(.dark-theme)) .bell-head button,
:where(html:not(.dark-theme)) .bell-list li i,
:where(html:not(.dark-theme)) .eval-banner-title,
:where(html:not(.dark-theme)) .eval-banner-icon,
:where(html:not(.dark-theme)) .cat-title,
:where(html:not(.dark-theme)) .comment-title,
:where(html:not(.dark-theme)) .search-box button,
:where(html:not(.dark-theme)) .section h2 i{color:#d99a2b!important;}
:where(html:not(.dark-theme)) .period-badge{background:rgba(217,154,43,.12)!important;border-color:rgba(217,154,43,.28)!important;}
:where(html:not(.dark-theme)) .period-badge.closed{background:rgba(240,84,84,.10)!important;border-color:rgba(240,84,84,.28)!important;color:#dc2626!important;}
:where(html:not(.dark-theme)) .period-badge.gray{background:#f1f5f9!important;border-color:#cbd5e1!important;color:#64748b!important;}

/* Forms */
:where(html:not(.dark-theme)) input,
:where(html:not(.dark-theme)) select,
:where(html:not(.dark-theme)) textarea,
:where(html:not(.dark-theme)) .search-box input[type=text],
:where(html:not(.dark-theme)) .search-box select,
:where(html:not(.dark-theme)) .form-group input,
:where(html:not(.dark-theme)) .filter-field select,
:where(html:not(.dark-theme)) .filter-field input[type=text],
:where(html:not(.dark-theme)) .ra-filter-row select,
:where(html:not(.dark-theme)) .ra-search,
:where(html:not(.dark-theme)) .eval-comment-box{
  background:#fff!important;color:#172033!important;border-color:#cbd5e1!important;
}
:where(html:not(.dark-theme)) input::placeholder,
:where(html:not(.dark-theme)) textarea::placeholder{color:#94a3b8!important;}
:where(html:not(.dark-theme)) input:focus,
:where(html:not(.dark-theme)) select:focus,
:where(html:not(.dark-theme)) textarea:focus{border-color:#d99a2b!important;box-shadow:0 0 0 3px rgba(217,154,43,.10)!important;outline:none!important;}

/* Buttons / tabs */
:where(html:not(.dark-theme)) .report-btns button,
:where(html:not(.dark-theme)) .qa-btns a,
:where(html:not(.dark-theme)) .filter-btns a,
:where(html:not(.dark-theme)) a.btn,
:where(html:not(.dark-theme)) .btn,
:where(html:not(.dark-theme)) .action-btn,
:where(html:not(.dark-theme)) .back-btn,
:where(html:not(.dark-theme)) .btn-print,
:where(html:not(.dark-theme)) .btn-archive,
:where(html:not(.dark-theme)) .btn-restore,
:where(html:not(.dark-theme)) .btn-solid,
:where(html:not(.dark-theme)) .btn-archived-link,
:where(html:not(.dark-theme)) .ra-tool-btn,
:where(html:not(.dark-theme)) .page-btn{
  background:rgba(217,154,43,.10)!important;
  border-color:rgba(217,154,43,.30)!important;
  color:#a16207!important;
}
:where(html:not(.dark-theme)) .report-btns button:hover,
:where(html:not(.dark-theme)) .qa-btns a:hover,
:where(html:not(.dark-theme)) .filter-btns a:hover,
:where(html:not(.dark-theme)) a.btn:hover,
:where(html:not(.dark-theme)) .btn:hover,
:where(html:not(.dark-theme)) .action-btn:hover,
:where(html:not(.dark-theme)) .back-btn:hover,
:where(html:not(.dark-theme)) .btn-print:hover,
:where(html:not(.dark-theme)) .btn-archive:hover,
:where(html:not(.dark-theme)) .btn-restore:hover,
:where(html:not(.dark-theme)) .btn-archived-link:hover,
:where(html:not(.dark-theme)) .ra-tool-btn:hover,
:where(html:not(.dark-theme)) .page-btn:hover{
  background:rgba(217,154,43,.16)!important;color:#92400e!important;
}
:where(html:not(.dark-theme)) .btn-primary,
:where(html:not(.dark-theme)) .btn-solid{background:#d99a2b!important;color:#0A192F!important;border-color:#d99a2b!important;}
:where(html:not(.dark-theme)) .btn-primary:hover,
:where(html:not(.dark-theme)) .btn-solid:hover{background:#f0b84d!important;color:#0A192F!important;}
:where(html:not(.dark-theme)) .report-btns a.active,
:where(html:not(.dark-theme)) .filter-btns a.active,
:where(html:not(.dark-theme)) .group-tab.active,
:where(html:not(.dark-theme)) .eval-tab.student.active,
:where(html:not(.dark-theme)) .eval-tab.peer.active,
:where(html:not(.dark-theme)) .eval-tab.multi-role.active,
:where(html:not(.dark-theme)) .tab.active,
:where(html:not(.dark-theme)) .level-tab.active,
:where(html:not(.dark-theme)) .status-tab.active,
:where(html:not(.dark-theme)) .desig-subtab.active,
:where(html:not(.dark-theme)) .page-btn.active{background:rgba(217,154,43,.14)!important;color:#a16207!important;border-color:rgba(217,154,43,.35)!important;}
:where(html:not(.dark-theme)) .eval-tab,
:where(html:not(.dark-theme)) .tab,
:where(html:not(.dark-theme)) .level-tab,
:where(html:not(.dark-theme)) .status-tab,
:where(html:not(.dark-theme)) .group-tab,
:where(html:not(.dark-theme)) .desig-subtab{color:#64748b!important;background:transparent!important;}
:where(html:not(.dark-theme)) .eval-tab:hover,
:where(html:not(.dark-theme)) .tab:hover,
:where(html:not(.dark-theme)) .level-tab:hover,
:where(html:not(.dark-theme)) .status-tab:hover,
:where(html:not(.dark-theme)) .group-tab:hover,
:where(html:not(.dark-theme)) .desig-subtab:hover{color:#334155!important;background:#f8fafc!important;}

/* Evaluation questionnaire */
:where(html:not(.dark-theme)) .eval-q-card,
:where(html:not(.dark-theme)) .eval-table-wrap{background:#fff!important;}
:where(html:not(.dark-theme)) .eval-rating-opt{background:#f8fafc!important;color:#334155!important;border-color:#cbd5e1!important;}
:where(html:not(.dark-theme)) .eval-rating-opt:has(input:checked){background:rgba(217,154,43,.10)!important;color:#92400e!important;border-color:#d99a2b!important;}
:where(html:not(.dark-theme)) .eval-table th{background:#f8fafc!important;color:#64748b!important;border-color:#e2e8f0!important;}
:where(html:not(.dark-theme)) .eval-table td{border-color:#e2e8f0!important;color:#334155!important;}
:where(html:not(.dark-theme)) .eval-rating-cell label{background:#fff!important;color:#64748b!important;border-color:#cbd5e1!important;}
:where(html:not(.dark-theme)) .eval-rating-cell label:hover{background:rgba(217,154,43,.08)!important;border-color:#d99a2b!important;}
:where(html:not(.dark-theme)) .eval-rating-cell input:checked + label{background:#d99a2b!important;color:#fff!important;border-color:#d99a2b!important;}
:where(html:not(.dark-theme)) .eval-qno{color:#b8801f!important;}
:where(html:not(.dark-theme)) .eval-comment-box{color:#334155!important;}

/* Status pills */
:where(html:not(.dark-theme)) .pill.good{background:rgba(16,185,129,.12)!important;color:#047857!important;}
:where(html:not(.dark-theme)) .pill.warn{background:rgba(217,154,43,.12)!important;color:#a16207!important;}
:where(html:not(.dark-theme)) .pill.bad{background:rgba(240,84,84,.10)!important;color:#b91c1c!important;}
:where(html:not(.dark-theme)) .alert.success{background:rgba(16,185,129,.10)!important;color:#047857!important;border-color:rgba(16,185,129,.25)!important;}
:where(html:not(.dark-theme)) .alert.error{background:rgba(240,84,84,.10)!important;color:#b91c1c!important;border-color:rgba(240,84,84,.25)!important;}

/* Progress bars */
:where(html:not(.dark-theme)) .bar-wrap,
:where(html:not(.dark-theme)) .score-bar-bg,
:where(html:not(.dark-theme)) .eval-bar-bg,
:where(html:not(.dark-theme)) .avg-bar-bg,
:where(html:not(.dark-theme)) .cat-bar{background:#e2e8f0!important;}
:where(html:not(.dark-theme)) .bar-fill,
:where(html:not(.dark-theme)) .avg-bar-fill,
:where(html:not(.dark-theme)) .cat-bar>div{background:linear-gradient(90deg,#b8801f,#f0b84d)!important;}

/* Notifications */
:where(html:not(.dark-theme)) .notif-list li,
:where(html:not(.dark-theme)) .bell-list li{background:#f8fafc!important;color:#334155!important;border-color:#e2e8f0!important;}
:where(html:not(.dark-theme)) .notif-list li.unseen,
:where(html:not(.dark-theme)) .bell-list li.unseen{background:rgba(217,154,43,.08)!important;}
:where(html:not(.dark-theme)) .bell-btn{background:#fff!important;border-color:#cbd5e1!important;color:#d99a2b!important;}
:where(html:not(.dark-theme)) .bell-btn:hover,
:where(html:not(.dark-theme)) .bell-btn[aria-expanded="true"]{background:rgba(217,154,43,.10)!important;border-color:rgba(217,154,43,.35)!important;}
:where(html:not(.dark-theme)) .bell-panel{background:#fff!important;border-color:#e2e8f0!important;box-shadow:0 18px 44px rgba(15,23,42,.16)!important;color:#172033!important;}
:where(html:not(.dark-theme)) .bell-head{border-color:#e2e8f0!important;}
:where(html:not(.dark-theme)) .bell-head h3{color:#0f172a!important;}
:where(html:not(.dark-theme)) .bell-list li{color:#334155!important;}
:where(html:not(.dark-theme)) .bell-count{border-color:#fff!important;}

/* Reports / analytics data surfaces and modal */
:where(html:not(.dark-theme)) .standing-score,
:where(html:not(.dark-theme)) .pstat-val,
:where(html:not(.dark-theme)) .avg-score-big,
:where(html:not(.dark-theme)) .avg-score-label{color:#047857!important;}
:where(html:not(.dark-theme)) .standing-title.top{color:#047857!important;}
:where(html:not(.dark-theme)) .standing-title.low{color:#dc2626!important;}
:where(html:not(.dark-theme)) .comment-text{background:#f8fafc!important;color:#475569!important;border-color:#e2e8f0!important;}
:where(html:not(.dark-theme)) .info-grid>div,
:where(html:not(.dark-theme)) .q-result,
:where(html:not(.dark-theme)) .received-item{border-color:#e2e8f0!important;}
:where(html:not(.dark-theme)) .eval-modal-overlay{background:rgba(15,23,42,.55)!important;}
:where(html:not(.dark-theme)) .eval-modal{background:#fff!important;color:#172033!important;border-color:#e2e8f0!important;}
:where(html:not(.dark-theme)) .eval-modal-header{border-color:#e2e8f0!important;}
:where(html:not(.dark-theme)) .eval-modal-close{color:#64748b!important;}
:where(html:not(.dark-theme)) .star{color:#cbd5e1!important;}
:where(html:not(.dark-theme)) .star.filled{color:#facc15!important;}

/* Account / settings / roster utilities */
:where(html:not(.dark-theme)) .profile-photo-lg{border-color:#d99a2b!important;}
:where(html:not(.dark-theme)) .avatar-sm{border-color:rgba(217,154,43,.45)!important;}
:where(html:not(.dark-theme)) .btn-reset{color:#475569!important;border-color:#cbd5e1!important;background:#fff!important;}
:where(html:not(.dark-theme)) .structure-note{background:rgba(217,154,43,.06)!important;border-color:rgba(217,154,43,.24)!important;}
:where(html:not(.dark-theme)) .structure-note p{color:#475569!important;}
:where(html:not(.dark-theme)) .structure-note p b{color:#0f172a!important;}

@media(max-width:768px){
  :where(html:not(.dark-theme)) body{background:#F8FAFC!important;}
}
</style>

<style id="principal-integrated-light-view">
/* Light-mode only: scoped so dark mode keeps its own surfaces. */
/* ================================================================
   PRINCIPAL INTEGRATED LIGHT VIEW
   Visual direction: same overall canvas treatment as the EA dashboard.
   The content area is one continuous light surface; feature sections
   remain white, but are flatter and more naturally integrated instead
   of looking like isolated floating cards.
   ================================================================ */
html:where(:not(.dark-theme)),
:where(html:not(.dark-theme)) body{
  background: #F8FAFC !important;
  color: #172033 !important;
}
:where(html:not(.dark-theme)) body{
  background-image: none !important;
}

/* Continuous page canvas */
:where(html:not(.dark-theme)) .main,
:where(html:not(.dark-theme)) main.main,
:where(html:not(.dark-theme)) .main-content,
:where(html:not(.dark-theme)) .content,
:where(html:not(.dark-theme)) .page-content{
  background: #F8FAFC !important;
  color: #172033 !important;
  min-height: 100vh;
}

/* Keep the existing navy Principal sidebar exactly as-is */
:where(html:not(.dark-theme)) .sidebar{
  background: #0A192F !important;
}

/* Page heading sits directly on the canvas — not in a floating card. */
:where(html:not(.dark-theme)) .main > .page-header,
:where(html:not(.dark-theme)) main.main > .page-header{
  background: transparent !important;
  border: 0 !important;
  box-shadow: none !important;
  border-radius: 0 !important;
  padding: 0 !important;
  margin: 0 0 22px !important;
}

:where(html:not(.dark-theme)) .page-title,
:where(html:not(.dark-theme)) .page-header h1{
  color: #0F172A !important;
}
:where(html:not(.dark-theme)) .page-sub,
:where(html:not(.dark-theme)) .page-header p{
  color: #64748B !important;
}

/* Major feature sections: white, but visually grounded on the light canvas. */
:where(html:not(.dark-theme)) .period-strip,
:where(html:not(.dark-theme)) .structure-note,
:where(html:not(.dark-theme)) .stub-note,
:where(html:not(.dark-theme)) .section,
:where(html:not(.dark-theme)) .card-grid,
:where(html:not(.dark-theme)) .eval-switcher,
:where(html:not(.dark-theme)) .eval-banner,
:where(html:not(.dark-theme)) .group-tab-wrap,
:where(html:not(.dark-theme)) .group-tabs,
:where(html:not(.dark-theme)) .desig-subtabs,
:where(html:not(.dark-theme)) .faculty-subtabs,
:where(html:not(.dark-theme)) .filter-bar,
:where(html:not(.dark-theme)) .ra-table-card,
:where(html:not(.dark-theme)) .ra-filters-panel,
:where(html:not(.dark-theme)) .table-wrap,
:where(html:not(.dark-theme)) .table-card,
:where(html:not(.dark-theme)) .content-card,
:where(html:not(.dark-theme)) .summary-card,
:where(html:not(.dark-theme)) .standing-panel,
:where(html:not(.dark-theme)) .history-card,
:where(html:not(.dark-theme)) .people-list,
:where(html:not(.dark-theme)) .evaluator-grid,
:where(html:not(.dark-theme)) .no-archived,
:where(html:not(.dark-theme)) .info-grid,
:where(html:not(.dark-theme)) .sheet-header,
:where(html:not(.dark-theme)) .scale-bar,
:where(html:not(.dark-theme)) .q-table,
:where(html:not(.dark-theme)) .comment-section,
:where(html:not(.dark-theme)) .avg-summary{
  border-color: #E2E8F0 !important;
}

:where(html:not(.dark-theme)) .period-strip,
:where(html:not(.dark-theme)) .structure-note,
:where(html:not(.dark-theme)) .section,
:where(html:not(.dark-theme)) .sheet-header,
:where(html:not(.dark-theme)) .scale-bar,
:where(html:not(.dark-theme)) .comment-section,
:where(html:not(.dark-theme)) .avg-summary,
:where(html:not(.dark-theme)) .ra-table-card,
:where(html:not(.dark-theme)) .ra-filters-panel,
:where(html:not(.dark-theme)) .table-wrap,
:where(html:not(.dark-theme)) .table-card,
:where(html:not(.dark-theme)) .content-card,
:where(html:not(.dark-theme)) .summary-card,
:where(html:not(.dark-theme)) .standing-panel,
:where(html:not(.dark-theme)) .history-card,
:where(html:not(.dark-theme)) .people-list,
:where(html:not(.dark-theme)) .evaluator-grid,
:where(html:not(.dark-theme)) .no-archived,
:where(html:not(.dark-theme)) .info-grid{
  background: #FFFFFF !important;
  color: #172033 !important;
  box-shadow: 0 2px 10px rgba(15,23,42,.055) !important;
}

/* Dashboard KPI/detail cards stay visible, but lose the heavy "floating" effect. */
:where(html:not(.dark-theme)) .card-grid{
  background: transparent !important;
  box-shadow: none !important;
  border: 0 !important;
  padding: 0 !important;
}
:where(html:not(.dark-theme)) .stat-card,
:where(html:not(.dark-theme)) .stat-link .stat-card{
  background: #FFFFFF !important;
  border-color: #E2E8F0 !important;
  box-shadow: 0 3px 12px rgba(15,23,42,.055) !important;
}
:where(html:not(.dark-theme)) .stat-card:hover,
:where(html:not(.dark-theme)) .stat-link:hover .stat-card{
  box-shadow: 0 5px 14px rgba(15,23,42,.075) !important;
}

/* Common tables/inner surfaces stay clean and readable. */
:where(html:not(.dark-theme)) table,
:where(html:not(.dark-theme)) .eval-table-wrap,
:where(html:not(.dark-theme)) .eval-q-card,
:where(html:not(.dark-theme)) .q-result,
:where(html:not(.dark-theme)) .received-item{
  background: #FFFFFF !important;
  color: #172033 !important;
  border-color: #E2E8F0 !important;
}

:where(html:not(.dark-theme)) table thead th,
:where(html:not(.dark-theme)) .eval-table th,
:where(html:not(.dark-theme)) .q-table thead tr,
:where(html:not(.dark-theme)) table.data th,
:where(html:not(.dark-theme)) .q-table th{
  background: #F8FAFC !important;
  color: #475569 !important;
  border-color: #E2E8F0 !important;
}

:where(html:not(.dark-theme)) table tbody td,
:where(html:not(.dark-theme)) .eval-table td,
:where(html:not(.dark-theme)) .q-table td,
:where(html:not(.dark-theme)) table.data td{
  background: #FFFFFF !important;
  color: #334155 !important;
  border-color: #E2E8F0 !important;
}

:where(html:not(.dark-theme)) table tbody tr:hover td,
:where(html:not(.dark-theme)) .eval-table tr:hover td,
:where(html:not(.dark-theme)) .q-table tr:hover td{
  background: #F8FAFC !important;
}

/* Report feature area follows the same integrated page treatment. */
:where(html:not(.dark-theme)) .eval-tab,
:where(html:not(.dark-theme)) .tab,
:where(html:not(.dark-theme)) .level-tab,
:where(html:not(.dark-theme)) .status-tab,
:where(html:not(.dark-theme)) .group-tab,
:where(html:not(.dark-theme)) .desig-subtab,
:where(html:not(.dark-theme)) .faculty-subtab{
  background: transparent !important;
}

/* Inner highlighted controls can still use soft tint, never dark navy. */
:where(html:not(.dark-theme)) .main .subtle-head,
:where(html:not(.dark-theme)) .main .table-head,
:where(html:not(.dark-theme)) .main .table-header,
:where(html:not(.dark-theme)) .main .thead{
  background: #F4F8FF !important;
}

:where(html:not(.dark-theme)) input,
:where(html:not(.dark-theme)) select,
:where(html:not(.dark-theme)) textarea,
:where(html:not(.dark-theme)) .search-box input[type=text],
:where(html:not(.dark-theme)) .search-box select,
:where(html:not(.dark-theme)) .form-group input,
:where(html:not(.dark-theme)) .filter-field select,
:where(html:not(.dark-theme)) .filter-field input[type=text],
:where(html:not(.dark-theme)) .ra-filter-row select,
:where(html:not(.dark-theme)) .ra-search,
:where(html:not(.dark-theme)) .eval-comment-box{
  background: #FFFFFF !important;
  color: #172033 !important;
  border-color: #CBD5E1 !important;
}

/* Avoid accidental dark-mode remnants in common feature containers. */
:where(html:not(.dark-theme)) .main .modal,
:where(html:not(.dark-theme)) .main .modal-content,
:where(html:not(.dark-theme)) .main .dropdown,
:where(html:not(.dark-theme)) .main .menu,
:where(html:not(.dark-theme)) .main .popover,
:where(html:not(.dark-theme)) .main .panel{
  background: #FFFFFF !important;
  color: #172033 !important;
  border-color: #E2E8F0 !important;
}

@media (max-width: 768px) {
  :where(html:not(.dark-theme)) .main,
:where(html:not(.dark-theme)) main.main,
:where(html:not(.dark-theme)) .main-content,
:where(html:not(.dark-theme)) .content,
:where(html:not(.dark-theme)) .page-content{
    padding: 24px 18px !important;
  }
}

@media print {
  html:where(:not(.dark-theme)),
:where(html:not(.dark-theme)) body,
:where(html:not(.dark-theme)) .main,
:where(html:not(.dark-theme)) main.main,
:where(html:not(.dark-theme)) .main-content,
:where(html:not(.dark-theme)) .content,
:where(html:not(.dark-theme)) .page-content{
    background: #FFFFFF !important;
  }
  :where(html:not(.dark-theme)) .main > .page-header,
:where(html:not(.dark-theme)) main.main > .page-header{
    box-shadow: none !important;
  }
}
</style>

</body></html>
<?php $mysqli->close(); ?>
<style id="white-theme-override">
/* Light-mode only: scoped so dark mode keeps its own surfaces. */
:root:where(:not(.dark-theme)){--dark:#ffffff;--mid:#ffffff;--inner:#f5f7fb;--light:#172033;--muted:#64748b;--shadow:0 4px 18px rgba(15,23,42,.08);}
html:where(:not(.dark-theme)),
:where(html:not(.dark-theme)) body{background:#F8FAFC!important;color:#172033!important;}
:where(html:not(.dark-theme)) body{background-image:none!important;}
:where(html:not(.dark-theme)) main,
:where(html:not(.dark-theme)) .main-content,
:where(html:not(.dark-theme)) .content,
:where(html:not(.dark-theme)) .page-content{background:#F8FAFC!important;color:#172033!important;}
:where(html:not(.dark-theme)) .sidebar{background:#0A192F!important;color:#E0E6F0!important;border-right:1px solid #172A45!important;}
:where(html:not(.dark-theme)) .sidebar *,
:where(html:not(.dark-theme)) .sidebar a{color:inherit;}
:where(html:not(.dark-theme)) .stat-card,
:where(html:not(.dark-theme)) .section,
:where(html:not(.dark-theme)) .period-strip,
:where(html:not(.dark-theme)) .filter-bar,
:where(html:not(.dark-theme)) .card,
:where(html:not(.dark-theme)) .panel,
:where(html:not(.dark-theme)) .table-wrap,
:where(html:not(.dark-theme)) .modal-content{background:#fff!important;color:#172033!important;border-color:#e2e8f0!important;box-shadow:var(--shadow)!important;}
:where(html:not(.dark-theme)) h1,
:where(html:not(.dark-theme)) h2,
:where(html:not(.dark-theme)) h3,
:where(html:not(.dark-theme)) h4,
:where(html:not(.dark-theme)) h5,
:where(html:not(.dark-theme)) h6,
:where(html:not(.dark-theme)) .section-title,
:where(html:not(.dark-theme)) .page-title{color:#0f172a!important;}
:where(html:not(.dark-theme)) p,
:where(html:not(.dark-theme)) span,
:where(html:not(.dark-theme)) label,
:where(html:not(.dark-theme)) td,
:where(html:not(.dark-theme)) th,
:where(html:not(.dark-theme)) small{color:inherit;}
:where(html:not(.dark-theme)) table{color:#172033!important;}
:where(html:not(.dark-theme)) table thead th{background:#f8fafc!important;color:#334155!important;border-color:#e2e8f0!important;}
:where(html:not(.dark-theme)) table tbody td{border-color:#e2e8f0!important;}
:where(html:not(.dark-theme)) input,
:where(html:not(.dark-theme)) select,
:where(html:not(.dark-theme)) textarea{background:#fff!important;color:#172033!important;border-color:#cbd5e1!important;}
:where(html:not(.dark-theme)) .search-box input[type=text],
:where(html:not(.dark-theme)) .search-box select,
:where(html:not(.dark-theme)) .form-group input,
:where(html:not(.dark-theme)) .filter-field select,
:where(html:not(.dark-theme)) .filter-field input[type=text]{background:#fff!important;color:#172033!important;}
:where(html:not(.dark-theme)) .btn-reset{color:#475569!important;border-color:#cbd5e1!important;}
:where(html:not(.dark-theme)) .notif-list li{background:#f8fafc!important;}
</style>

<style id="principal-ea-exact-workspace-canvas">
/* Light-mode only: scoped so dark mode keeps its own surfaces. */
/*
  Principal workspace shell — matches the EA feature/iframe composition:
  white outer viewport + an inset #F8FAFC workspace canvas.
  The canvas is the feature surface; white cards remain white.
*/
html:where(:not(.dark-theme)){
  background:#FFFFFF !important;
  color-scheme:light !important;
}
:where(html:not(.dark-theme)) body{
  background:#FFFFFF !important;
  background-image:none !important;
  color:#172033 !important;
}

/* Leave the navy Principal sidebar unchanged. */
:where(html:not(.dark-theme)) .sidebar{
  background:#0A192F !important;
  color:#E0E6F0 !important;
}

/* The feature workspace is inset, like the EA iframe inside its white page. */
:where(html:not(.dark-theme)) .main,
:where(html:not(.dark-theme)) main.main{
  position:relative !important;
  flex:1 1 auto !important;
  width:auto !important;
  max-width:none !important;
  min-height:calc(100vh - 20px) !important;
  margin:20px 20px 0 20px !important;
  padding:24px 34px 34px !important;
  background:#F8FAFC !important;
  color:#172033 !important;
  border-radius:16px 16px 0 0 !important;
  box-shadow:none !important;
  isolation:auto !important;
  overflow:visible !important;
}

/* Disable the earlier pseudo-canvas implementation; the main itself is now
   the correctly inset workspace surface. */
:where(html:not(.dark-theme)) .main::before,
:where(html:not(.dark-theme)) main.main::before{
  display:none !important;
  content:none !important;
}

/* Page headings live on the same light workspace surface, as on the EA
   feature pages rendered inside their iframe. */
:where(html:not(.dark-theme)) .main > .page-header,
:where(html:not(.dark-theme)) main.main > .page-header{
  background:transparent !important;
  border:0 !important;
  box-shadow:none !important;
}

/* White feature surfaces remain clearly distinct from the #F8FAFC canvas. */
:where(html:not(.dark-theme)) .main .page-header:has(.page-title),
:where(html:not(.dark-theme)) .main .section,
:where(html:not(.dark-theme)) .main .card,
:where(html:not(.dark-theme)) .main .panel,
:where(html:not(.dark-theme)) .main .table-card,
:where(html:not(.dark-theme)) .main .content-card,
:where(html:not(.dark-theme)) .main .stat-card,
:where(html:not(.dark-theme)) .main .period-strip,
:where(html:not(.dark-theme)) .main .filter-bar,
:where(html:not(.dark-theme)) .main .table-wrap,
:where(html:not(.dark-theme)) .main .table-card-wrap,
:where(html:not(.dark-theme)) .main .content-panel,
:where(html:not(.dark-theme)) .main .eval-banner,
:where(html:not(.dark-theme)) .main .info-banner,
:where(html:not(.dark-theme)) .main .history-card,
:where(html:not(.dark-theme)) .main .gl-card,
:where(html:not(.dark-theme)) .main .amber-card,
:where(html:not(.dark-theme)) .main .green-card,
:where(html:not(.dark-theme)) .main .red-card,
:where(html:not(.dark-theme)) .main .ra-table-card,
:where(html:not(.dark-theme)) .main .eval-card,
:where(html:not(.dark-theme)) .main .comment-section,
:where(html:not(.dark-theme)) .main .avg-summary,
:where(html:not(.dark-theme)) .main .standing-panel,
:where(html:not(.dark-theme)) .main .person-row,
:where(html:not(.dark-theme)) .main .target-card,
:where(html:not(.dark-theme)) .main .no-eval,
:where(html:not(.dark-theme)) .main .no-data,
:where(html:not(.dark-theme)) .main .no-evaluated,
:where(html:not(.dark-theme)) .main .no-archived,
:where(html:not(.dark-theme)) .main .evaluator-grid,
:where(html:not(.dark-theme)) .main .people-list,
:where(html:not(.dark-theme)) .main .cat-section,
:where(html:not(.dark-theme)) .main .results-card,
:where(html:not(.dark-theme)) .main .result-card,
:where(html:not(.dark-theme)) .main .feature-card{
  background:#FFFFFF !important;
  color:#172033 !important;
  border-color:#D9E4EF !important;
  box-shadow:0 1px 5px rgba(15,23,42,.035) !important;
}

/* Soft inner tint, matching the EA logs table header / page surfaces. */
:where(html:not(.dark-theme)) .main table thead th,
:where(html:not(.dark-theme)) .main .table-head,
:where(html:not(.dark-theme)) .main .table-header,
:where(html:not(.dark-theme)) .main .thead,
:where(html:not(.dark-theme)) .main .subtle-head{
  background:#F4F8FF !important;
  color:#4B6580 !important;
  border-color:#D9E4EF !important;
}

/* Responsive: retain the inset composition without squeezing narrow screens. */
@media (max-width:768px){
  :where(html:not(.dark-theme)) .main,
:where(html:not(.dark-theme)) main.main{
    margin:12px 12px 0 12px !important;
    padding:20px 18px 28px !important;
    min-height:calc(100vh - 12px) !important;
    border-radius:12px 12px 0 0 !important;
  }
}

@media print{
  html:where(:not(.dark-theme)),
:where(html:not(.dark-theme)) body{
    background:#FFFFFF !important;
  }
  :where(html:not(.dark-theme)) .main,
:where(html:not(.dark-theme)) main.main{
    margin:0 !important;
    padding:20px !important;
    min-height:0 !important;
    background:#FFFFFF !important;
    border-radius:0 !important;
  }
}
</style>

<link rel="stylesheet" href="includes/principal_dark_repairs.css?v=20261011.4" id="principal-dark-repairs"/>
<style id="ac-faculty-style">
/* ── Account Settings — same card language as the Faculty portal ──
   Coloured top bar, icon tile, title + description. Surface/text colours are left to the
   existing theme so light and dark mode keep working; only structure + accents change. */
main.main{--ac:#D97706;}
main.main .ac-c1{--ac-c:var(--ac);}
main.main .ac-c2,main.main .ac-c5{--ac-c:#10B981;}
main.main .ac-c3{--ac-c:#D88900;}
main.main .ac-c4{--ac-c:#3B82F6;}
main.main .ac-c6{--ac-c:#8B5CF6;}
main.main a.btn-primary{display:inline-flex;align-items:center;gap:8px;text-decoration:none;}
.sq-status{display:inline-flex;align-items:center;gap:7px;padding:5px 11px;margin-bottom:16px;border-radius:999px;font-size:12px;font-weight:600;background:rgba(148,163,184,.12);border:1px solid rgba(148,163,184,.3);}
.sq-status.ok{color:#10B981;border-color:rgba(16,185,129,.4);}
.sq-status.warn{color:#D97706;border-color:rgba(217,119,6,.45);}
main.main .ac-card{position:relative !important;overflow:hidden !important;border-radius:15px !important;padding:22px 24px 24px !important;margin-bottom:20px !important;}
main.main .ac-card::before{content:'';position:absolute;left:0;right:0;top:0;height:3px;background:var(--ac-c);}
main.main .ac-head{display:flex;align-items:flex-start;gap:14px;margin-bottom:18px;}
main.main .ac-icon{width:42px;height:42px;flex:0 0 42px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;font-size:17px;background:color-mix(in srgb,var(--ac-c) 14%,transparent) !important;color:var(--ac-c) !important;}
main.main .ac-icon i{font-size:17px;color:var(--ac-c) !important;}
main.main .ac-head h2{display:block !important;margin:0 0 4px !important;font-family:'Rajdhani',sans-serif !important;font-size:21px !important;font-weight:800 !important;line-height:1.15 !important;letter-spacing:0 !important;}
main.main .ac-head .ac-sub{margin:0 !important;font-size:13px !important;line-height:1.5 !important;color:inherit !important;opacity:.72;}
main.main .ac-head.appearance-heading-row{justify-content:flex-start !important;}
main.main .ac-head.appearance-heading-row .appearance-device-badge{margin-left:auto;}
@media(max-width:600px){main.main .ac-card{padding:18px 18px 20px !important;}}
</style>
<style id="principal-settings-structure">
/* Same structure as the Dean settings page: photo help text + a fixed sidebar
   with the settings workspace scrolling internally. */
.photo-help{font-size:12px;color:#64748B;margin-top:8px;line-height:1.5;}
.profile-card{display:flex;align-items:center;gap:24px;}
.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:17px 20px;margin-bottom:20px;}
.form-group{display:flex;flex-direction:column;gap:7px;}
.form-group label{font-size:11px;font-weight:600;letter-spacing:.8px;text-transform:uppercase;color:#64748B !important;}
.alert{border-radius:10px;padding:12px 15px;font-size:13px;margin-bottom:18px;display:flex;align-items:center;gap:9px;}

html:has(main.main.principal-internal-scroll),
html:has(main.main.principal-internal-scroll) body{overflow:hidden !important;height:100% !important;}
main.main.principal-internal-scroll{
  height:calc(100vh - 20px) !important;max-height:calc(100vh - 20px) !important;min-height:0 !important;
  overflow-y:scroll !important;overflow-x:hidden !important;scrollbar-gutter:stable;
  overscroll-behavior:contain;scrollbar-width:thin;scrollbar-color:#AEBAC8 #EEF2F6;
}
main.main.principal-internal-scroll::-webkit-scrollbar{width:10px;}
main.main.principal-internal-scroll::-webkit-scrollbar-track{background:#EEF2F6;border-radius:10px;}
main.main.principal-internal-scroll::-webkit-scrollbar-thumb{background:#AEBAC8;border:2px solid #EEF2F6;border-radius:10px;}
main.main.principal-internal-scroll::-webkit-scrollbar-thumb:hover{background:#8F9CAB;}
html.dark-theme main.main.principal-internal-scroll{scrollbar-color:#2A4468 #0F1F3D;}
html.dark-theme main.main.principal-internal-scroll::-webkit-scrollbar-track{background:#0F1F3D;}
html.dark-theme main.main.principal-internal-scroll::-webkit-scrollbar-thumb{background:#2A4468;border-color:#0F1F3D;}

@media(max-width:900px){.form-grid{grid-template-columns:1fr;}}
@media(max-width:768px){
  html:has(main.main.principal-internal-scroll),
  html:has(main.main.principal-internal-scroll) body{overflow:auto !important;height:auto !important;}
  main.main.principal-internal-scroll{height:auto !important;max-height:none !important;min-height:calc(100vh - 12px) !important;overflow:visible !important;scrollbar-gutter:auto;}
}
@media(max-width:650px){.profile-card{align-items:flex-start;flex-direction:column;}}
</style>

<style id="principal-dean-ui-match">
/* ================================================================
   Principal settings — interface matched to the Dean settings page:
   full-width Theme card (3 horizontal options), Interface spacing and
   Accessibility side by side, dark headings, check badge at the right,
   same photo/button sizing. Principal keeps its amber accent.
   ================================================================ */
main.main{--ap-card:#FFFFFF;--ap-soft:#F8FAFC;--ap-line:#E2E8F0;--ap-text:#0F172A;--ap-muted:#64748B;--ap-accent:#D97706;--ap-accent-bg:rgba(217,119,6,.10);--ap-ring:rgba(217,119,6,.14);}
html.dark-theme main.main{--ap-card:rgba(10,25,47,.55);--ap-soft:rgba(10,25,47,.45);--ap-line:rgba(255,255,255,.10);--ap-text:#E0E6F0;--ap-muted:#A0B3C6;--ap-accent-bg:rgba(217,119,6,.16);}

/* Headings: dark, not accent-coloured */
html:not(.dark-theme) main.main .ac-head h2{color:#0F172A !important;}
html:not(.dark-theme) main.main .ac-head .ac-sub{color:#64748B !important;opacity:1;}

/* Appearance grid: Theme spans the full row, the other two share the next row */
main.main .appearance-feature-grid{display:grid !important;grid-template-columns:repeat(2,minmax(0,1fr)) !important;gap:16px !important;}
main.main .appearance-theme-card{grid-column:1 / -1 !important;}
main.main .appearance-control-card{display:block !important;background:var(--ap-soft) !important;border:1px solid var(--ap-line) !important;border-radius:14px !important;padding:18px !important;box-shadow:none !important;}
main.main .appearance-control-head{display:flex !important;align-items:flex-start !important;gap:12px !important;margin-bottom:14px !important;}
main.main .appearance-control-icon{width:34px !important;height:34px !important;flex:0 0 34px !important;border-radius:10px !important;display:inline-flex !important;align-items:center !important;justify-content:center !important;background:var(--ap-accent-bg) !important;color:var(--ap-accent) !important;font-size:14px !important;}
main.main .appearance-control-head h3{font-size:15px !important;font-weight:700 !important;color:var(--ap-text) !important;margin:0 0 2px !important;}
main.main .appearance-control-head p{font-size:12.5px !important;color:var(--ap-muted) !important;margin:0 !important;line-height:1.45 !important;}

/* Theme options: three wide horizontal cards */
main.main .appearance-theme-options{display:grid !important;grid-template-columns:repeat(3,minmax(0,1fr)) !important;gap:12px !important;}
main.main .appearance-choice{position:relative !important;display:flex !important;flex-direction:row !important;align-items:center !important;justify-content:flex-start !important;gap:12px !important;width:auto !important;height:auto !important;min-height:0 !important;padding:14px !important;text-align:left !important;border-radius:12px !important;border:1.5px solid var(--ap-line) !important;background:var(--ap-card) !important;color:var(--ap-text) !important;cursor:pointer;box-shadow:none !important;}
main.main .appearance-choice::before,main.main .appearance-choice::after{display:none !important;content:none !important;}
main.main .appearance-choice:hover{border-color:var(--ap-accent) !important;}
main.main .appearance-choice-icon{width:36px !important;height:36px !important;flex:0 0 36px !important;border-radius:10px !important;display:inline-flex !important;align-items:center !important;justify-content:center !important;background:var(--ap-soft) !important;color:var(--ap-muted) !important;font-size:15px !important;}
main.main .appearance-choice-copy{display:block !important;min-width:0;}
main.main .appearance-choice-copy strong,main.main .appearance-density-choice strong,main.main .appearance-motion-copy strong{display:block !important;font-size:13.5px !important;font-weight:700 !important;color:var(--ap-text) !important;}
main.main .appearance-choice-copy small,main.main .appearance-density-choice small,main.main .appearance-motion-copy small{display:block !important;font-size:11.5px !important;color:var(--ap-muted) !important;margin-top:1px !important;}
main.main .appearance-choice-check{position:static !important;margin-left:auto !important;width:20px !important;height:20px !important;flex:0 0 20px !important;border:0 !important;border-radius:50% !important;display:inline-flex !important;align-items:center !important;justify-content:center !important;font-size:10px !important;background:var(--ap-accent) !important;color:#fff !important;opacity:0 !important;transform:scale(.6) !important;transition:.2s;}
main.main .appearance-choice[aria-pressed="true"]{border-color:var(--ap-accent) !important;background:var(--ap-accent-bg) !important;box-shadow:0 0 0 3px var(--ap-ring) !important;}
main.main .appearance-choice[aria-pressed="true"] .appearance-choice-icon{background:var(--ap-accent) !important;color:#fff !important;}
main.main .appearance-choice[aria-pressed="true"] .appearance-choice-check{opacity:1 !important;transform:scale(1) !important;}
main.main .appearance-inline-status{margin-top:14px !important;font-size:12.5px !important;color:var(--ap-muted) !important;display:flex !important;align-items:center !important;gap:7px !important;}
main.main .appearance-inline-status i{color:var(--ap-accent) !important;}
main.main .appearance-inline-status strong{color:var(--ap-text) !important;}

/* Interface spacing: stacked rows, plain icon (no filled tile) */
main.main .appearance-density-options{display:grid !important;grid-template-columns:1fr !important;gap:10px !important;}
main.main .appearance-density-choice{display:flex !important;align-items:center !important;gap:12px !important;width:auto !important;padding:12px 14px !important;text-align:left !important;border-radius:12px !important;border:1.5px solid var(--ap-line) !important;background:var(--ap-card) !important;color:var(--ap-muted) !important;cursor:pointer;box-shadow:none !important;}
main.main .appearance-density-choice:hover{border-color:var(--ap-accent) !important;}
main.main .appearance-density-choice > i{width:20px !important;height:auto !important;flex:0 0 20px !important;background:transparent !important;border-radius:0 !important;font-size:15px !important;text-align:center !important;color:var(--ap-muted) !important;}
main.main .appearance-density-choice[aria-pressed="true"]{border-color:var(--ap-accent) !important;background:var(--ap-accent-bg) !important;box-shadow:none !important;}
main.main .appearance-density-choice[aria-pressed="true"] > i{color:var(--ap-accent) !important;background:transparent !important;}

/* Accessibility toggle */
main.main .appearance-motion-toggle{width:100% !important;display:flex !important;align-items:center !important;justify-content:space-between !important;gap:14px !important;padding:12px 14px !important;text-align:left !important;border-radius:12px !important;border:1.5px solid var(--ap-line) !important;background:var(--ap-card) !important;cursor:pointer;box-shadow:none !important;}
main.main .appearance-motion-toggle:hover{border-color:var(--ap-accent) !important;}
main.main .appearance-motion-toggle[aria-pressed="true"]{border-color:var(--ap-accent) !important;background:var(--ap-accent-bg) !important;}
main.main .appearance-switch-track{width:44px !important;height:24px !important;flex:0 0 44px !important;border-radius:999px !important;background:#CBD5E1 !important;position:relative !important;transition:background .2s;}
main.main .appearance-switch-thumb{position:absolute !important;top:3px !important;left:3px !important;width:18px !important;height:18px !important;border-radius:50% !important;background:#fff !important;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:transform .2s;}
main.main .appearance-motion-toggle[aria-pressed="true"] .appearance-switch-track{background:var(--ap-accent) !important;}
main.main .appearance-motion-toggle[aria-pressed="true"] .appearance-switch-thumb{transform:translateX(20px) !important;}
main.main .appearance-accessibility-note{margin-top:12px !important;font-size:11.5px !important;color:var(--ap-muted) !important;display:flex !important;gap:7px;align-items:flex-start;line-height:1.5;}
main.main .appearance-accessibility-note i{color:var(--ap-accent) !important;margin-top:2px;}

/* Profile photo row + buttons, same sizing as Dean */
main.main .profile-photo-lg{width:96px !important;height:96px !important;border-radius:50% !important;object-fit:cover;border:3px solid var(--ap-accent) !important;}
main.main .photo-form-wrap input[type="file"]{font:13px 'DM Sans',sans-serif !important;padding:2px 4px !important;width:auto !important;max-width:100%;border:1px solid #CBD5E1 !important;border-radius:6px !important;background:#fff !important;}
main.main .btn-primary{padding:11px 17px !important;border:0 !important;border-radius:9px !important;font:600 14px 'DM Sans',sans-serif !important;color:#fff !important;background:var(--ap-accent) !important;box-shadow:0 4px 14px rgba(217,119,6,.25) !important;cursor:pointer;}
main.main .btn-primary:hover{background:#F59E0B !important;color:#fff !important;transform:translateY(-1px);}
main.main .form-group input{padding:12px 13px !important;font:14px 'DM Sans',sans-serif !important;border-radius:9px !important;}

@media (max-width:900px){main.main .appearance-theme-options{grid-template-columns:1fr !important;}}
@media (max-width:768px){main.main .appearance-feature-grid{grid-template-columns:1fr !important;}main.main .appearance-device-badge{display:none !important;}}
</style>

<style id="principal-password-toggle">
main.main .pw-wrap{position:relative;display:block;}
main.main .pw-wrap input{width:100%;padding-right:46px !important;}
main.main .pw-toggle{position:absolute;top:50%;right:8px;transform:translateY(-50%);width:34px;height:34px;display:flex;align-items:center;justify-content:center;border:0;border-radius:8px;background:transparent;color:#64748B;font-size:15px;cursor:pointer;padding:0;}
main.main .pw-toggle .pw-eye{display:none;}
main.main .pw-toggle .pw-eye-off{display:block;}
main.main .pw-toggle[aria-pressed="true"] .pw-eye{display:block;}
main.main .pw-toggle[aria-pressed="true"] .pw-eye-off{display:none;}
main.main .pw-toggle:hover{color:#B45309;background:rgba(217,154,43,.12);}
main.main .pw-toggle:focus-visible{outline:2px solid #D99A2B;outline-offset:1px;}
</style>
<style id="principal-settings-tabs">
.settings-tabs{display:flex;gap:6px;margin:0 0 18px;padding:5px;border:1px solid #D9E4EF;border-radius:12px;background:#F4F8FF;width:fit-content;max-width:100%;overflow-x:auto;}
.settings-tab{appearance:none;border:0;background:transparent;color:#64748B;font:inherit;font-size:13.5px;font-weight:600;padding:9px 18px;border-radius:9px;cursor:pointer;display:inline-flex;align-items:center;gap:8px;white-space:nowrap;transition:background .2s,color .2s,box-shadow .2s;}
.settings-tab i{color:#D99A2B;}
.settings-tab:hover{color:#172033;background:rgba(217,154,43,.12);}
.settings-tab[aria-selected="true"]{background:#FFFFFF;color:#172033;box-shadow:0 1px 5px rgba(15,23,42,.10);}
.settings-tab:focus-visible{outline:2px solid #D99A2B;outline-offset:2px;}
.settings-tabpanel[hidden]{display:none !important;}
html.dark-theme .settings-tabs{background:rgba(10,25,47,.55);border-color:rgba(255,255,255,.10);}
html.dark-theme .settings-tab{color:#A0B3C6;}
html.dark-theme .settings-tab:hover{color:#fff;background:rgba(217,154,43,.15);}
html.dark-theme .settings-tab[aria-selected="true"]{background:rgba(217,154,43,.18);color:#fff;box-shadow:none;}
/* Pull the settings up: the page heading is empty here, so drop its reserved space and trim the top padding. */
main.main > .page-header,
.main > .page-header{display:none !important;}
main.main,
.main{padding-top:14px !important;}
@media (max-width:768px){main.main,.main{padding-top:12px !important;}}
</style>

<script>
(function(){
    var tabs=[].slice.call(document.querySelectorAll('.settings-tab'));
    var panels=[].slice.call(document.querySelectorAll('.settings-tabpanel'));
    if(!tabs.length)return;
    function show(name,focus){
        if(!panels.some(function(p){return p.dataset.panel===name;}))name='profile';
        tabs.forEach(function(t){
            var on=t.dataset.tab===name;
            t.setAttribute('aria-selected',on?'true':'false');
            t.tabIndex=on?0:-1;
            if(on&&focus)t.focus();
        });
        panels.forEach(function(p){p.hidden=p.dataset.panel!==name;});
        try{history.replaceState(null,'','#'+name);}catch(e){}
    }
    tabs.forEach(function(t,i){
        t.addEventListener('click',function(){show(t.dataset.tab);});
        t.addEventListener('keydown',function(e){
            var n=null;
            if(e.key==='ArrowRight')n=tabs[(i+1)%tabs.length];
            if(e.key==='ArrowLeft')n=tabs[(i-1+tabs.length)%tabs.length];
            if(n){e.preventDefault();show(n.dataset.tab,true);}
        });
    });
    show((location.hash||'').replace('#','')||'profile');
})();
</script>

<script>
document.querySelectorAll('.pw-toggle').forEach(function(btn){
    btn.addEventListener('click', function(){
        var input = btn.parentElement.querySelector('input');
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.setAttribute('aria-pressed', show ? 'true' : 'false');
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
});
</script>
<script>(function(){try{var t=localStorage.getItem("pbi_theme");var d=t==="dark"||(t==="system"&&window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches);if(d)document.documentElement.classList.add("dark-theme");}catch(e){}})();</script>
<style id="settings-dark-mode">
/* Settings: opaque dark surfaces (the translucent dark tokens were sitting on white parents). */
html.dark-theme body:not(#as1):not(#as2) .settings-tabs{background:#172A45!important;border-color:rgba(255,255,255,.12)!important;}
html.dark-theme body:not(#as1):not(#as2) .settings-tab{color:#A0B3C6!important;background:transparent!important;}
html.dark-theme body:not(#as1):not(#as2) .settings-tab:hover{color:#fff!important;background:rgba(217,154,43,.15)!important;}
html.dark-theme body:not(#as1):not(#as2) .settings-tab[aria-selected="true"]{background:rgba(217,154,43,.20)!important;color:#fff!important;box-shadow:none!important;}
html.dark-theme body:not(#as1):not(#as2) .appearance-control-card{background:#172A45!important;border:1px solid rgba(255,255,255,.12)!important;box-shadow:none!important;}
html.dark-theme body:not(#as1):not(#as2) .appearance-control-head h3{color:#fff!important;}
html.dark-theme body:not(#as1):not(#as2) .appearance-control-head p{color:#A0B3C6!important;}
html.dark-theme body:not(#as1):not(#as2) .appearance-control-icon{background:rgba(217,119,6,.20)!important;color:#F0B84D!important;}
html.dark-theme body:not(#as1):not(#as2) .appearance-choice,html.dark-theme body:not(#as1):not(#as2) .appearance-density-choice,html.dark-theme body:not(#as1):not(#as2) .appearance-motion-toggle{background:#0F1F3D!important;border:1.5px solid rgba(255,255,255,.14)!important;color:#E0E6F0!important;}
html.dark-theme body:not(#as1):not(#as2) .appearance-choice[aria-pressed="true"],html.dark-theme body:not(#as1):not(#as2) .appearance-density-choice[aria-pressed="true"],html.dark-theme body:not(#as1):not(#as2) .appearance-motion-toggle[aria-pressed="true"]{background:#33270F!important;border-color:#D97706!important;box-shadow:0 0 0 3px rgba(217,119,6,.18)!important;}
html.dark-theme body:not(#as1):not(#as2) .appearance-choice strong,html.dark-theme body:not(#as1):not(#as2) .appearance-density-choice strong,html.dark-theme body:not(#as1):not(#as2) .appearance-motion-copy strong{color:#fff!important;}
html.dark-theme body:not(#as1):not(#as2) .appearance-choice small,html.dark-theme body:not(#as1):not(#as2) .appearance-density-choice small,html.dark-theme body:not(#as1):not(#as2) .appearance-motion-copy small{color:#A0B3C6!important;}
html.dark-theme body:not(#as1):not(#as2) .appearance-choice-icon{background:#172A45!important;color:#A0B3C6!important;}
html.dark-theme body:not(#as1):not(#as2) .appearance-choice[aria-pressed="true"] .appearance-choice-icon{background:#D97706!important;color:#fff!important;}
html.dark-theme body:not(#as1):not(#as2) .appearance-density-choice > i{color:#A0B3C6!important;}
html.dark-theme body:not(#as1):not(#as2) .appearance-density-choice[aria-pressed="true"] > i{color:#F0B84D!important;}
html.dark-theme body:not(#as1):not(#as2) .appearance-inline-status,html.dark-theme body:not(#as1):not(#as2) .appearance-accessibility-note{color:#A0B3C6!important;}
html.dark-theme body:not(#as1):not(#as2) .appearance-inline-status strong{color:#fff!important;}
html.dark-theme body:not(#as1):not(#as2) .appearance-switch-track{background:#3B4B66!important;}
html.dark-theme body:not(#as1):not(#as2) .appearance-motion-toggle[aria-pressed="true"] .appearance-switch-track{background:#D97706!important;}
/* Security-questions popup */
html.dark-theme body:not(#as1):not(#as2) .sqm-modal{background:#172A45!important;color:#E0E6F0!important;border-color:rgba(255,255,255,.14)!important;box-shadow:0 24px 60px rgba(0,0,0,.55)!important;}
html.dark-theme body:not(#as1):not(#as2) .sqm-head{background:#172A45!important;}
html.dark-theme body:not(#as1):not(#as2) .sqm-modal h3,html.dark-theme body:not(#as1):not(#as2) .sqm-confirm-title,html.dark-theme body:not(#as1):not(#as2) .sqm-hint b{color:#fff!important;}
html.dark-theme body:not(#as1):not(#as2) .sqm-text,html.dark-theme body:not(#as1):not(#as2) .sqm-sec-title,html.dark-theme body:not(#as1):not(#as2) .sqm-confirm-sub,html.dark-theme body:not(#as1):not(#as2) .sqm-modal label,html.dark-theme body:not(#as1):not(#as2) .sqm-chosen,html.dark-theme body:not(#as1):not(#as2) .sqm-hint{color:#A0B3C6!important;}
html.dark-theme body:not(#as1):not(#as2) .sqm-btn:not(.primary):not(.danger){background:transparent!important;color:#E0E6F0!important;border-color:rgba(255,255,255,.25)!important;}
html.dark-theme body:not(#as1):not(#as2) .sqm-confirm{background:rgba(217,119,6,.12)!important;}
html.dark-theme body:not(#as1):not(#as2) .sqm-modal input[type=text],html.dark-theme body:not(#as1):not(#as2) .sqm-modal input[type=password],html.dark-theme body:not(#as1):not(#as2) .sqm-modal select{background:#0F1F3D!important;color:#fff!important;border-color:rgba(255,255,255,.22)!important;}
html.dark-theme body:not(#as1):not(#as2) .sqm-modal select option{background:#0F1F3D!important;color:#E0E6F0!important;}
html.dark-theme body:not(#as1):not(#as2) .sqm-hint{background:#0F1F3D!important;border-color:rgba(255,255,255,.14)!important;}
</style>
