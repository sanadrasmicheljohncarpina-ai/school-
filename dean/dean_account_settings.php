<?php
// dean_account_settings.php
// Dean self-service account settings: profile details, profile photo,
// and password. Uses the same authentication/session path as the Dean portal.

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => false,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
require_once 'db.php';

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'dean') {
    header('Location: dean_login.php');
    exit;
}

if (empty($_SESSION['dean_settings_csrf'])) {
    $_SESSION['dean_settings_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['dean_settings_csrf'];

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

// Pull the current Dean profile before processing a request so all forms
// and the sidebar always reflect the latest saved data.
$stmt = $mysqli->prepare(
    "SELECT full_name, username, email, designation, photo
     FROM users WHERE id = ? AND role = 'dean' LIMIT 1"
);
$stmt->bind_param('i', $userId);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$me) {
    session_unset();
    session_destroy();
    header('Location: dean_login.php');
    exit;
}

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
                        $del->bind_param('i', $userId); $del->execute(); $del->close();
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

$validateCsrf = function () use ($csrfToken): bool {
    return isset($_POST['csrf_token']) && hash_equals($csrfToken, (string)$_POST['csrf_token']);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$validateCsrf()) {
        $errors[] = 'Your session expired. Please refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        // ── PROFILE DETAILS ───────────────────────────────────────────
        if ($action === 'update_profile') {
            $newName       = trim((string)($_POST['full_name'] ?? ''));
            $newUsername   = trim((string)($_POST['username'] ?? ''));
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
                    // Keep the username unique while excluding this Dean.
                    $check = $mysqli->prepare('SELECT id, username FROM users WHERE username = ? AND id <> ? LIMIT 1');
                    $check->bind_param('si', $newUsername, $userId);
                    $check->execute();
                    $duplicate = $check->get_result()->fetch_assoc();
                    $check->close();

                    if ($duplicate) {
                        $errors[] = 'That username is already in use.';
                    } else {
                        $stmt = $mysqli->prepare("UPDATE users
                            SET full_name = ?, username = ?, designation = ?
                            WHERE id = ? AND role = 'dean'");
                        $stmt->bind_param('sssi', $newName, $newUsername, $newDesignation, $userId);
                        $stmt->execute();
                        $stmt->close();

                        $me['full_name'] = $newName;
                        $me['username'] = $newUsername;
                        $me['designation'] = $newDesignation;
                        $_SESSION['full_name'] = $newName;
                        $_SESSION['username'] = $newUsername;
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
                    $destDir = defined('UPLOAD_DIR') ? UPLOAD_DIR : dirname(__DIR__) . '/image/';
                    if (!is_dir($destDir) && !@mkdir($destDir, 0755, true)) {
                        $errors[] = 'The profile photo folder could not be created.';
                    } elseif (!is_writable($destDir)) {
                        $errors[] = 'The profile photo folder is not writable.';
                    } else {
                        $filename = 'dean_' . $userId . '_' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
                        $destination = rtrim($destDir, '/\\') . DIRECTORY_SEPARATOR . $filename;

                        if (move_uploaded_file($file['tmp_name'], $destination)) {
                            $stmt = $mysqli->prepare("UPDATE users SET photo = ? WHERE id = ? AND role = 'dean'");
                            $stmt->bind_param('si', $filename, $userId);
                            $stmt->execute();
                            $stmt->close();

                            // Remove the previous Dean photo only after the new
                            // database value has been saved.
                            $oldPhoto = $me['photo'] ?? '';
                            if ($oldPhoto && basename($oldPhoto) === $oldPhoto) {
                                $oldPath = rtrim($destDir, '/\\') . DIRECTORY_SEPARATOR . $oldPhoto;
                                if (is_file($oldPath) && $oldPhoto !== $filename) {
                                    @unlink($oldPath);
                                }
                            }

                            $me['photo'] = $filename;
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

            $stmt = $mysqli->prepare("SELECT password_hash FROM users WHERE id = ? AND role = 'dean' LIMIT 1");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row || empty($row['password_hash']) || $row['password_hash'] === 'NOT NULL') {
                $errors[] = 'Could not verify your current password. Please contact the administrator.';
            } elseif (!password_verify($current, $row['password_hash'])) {
                $errors[] = 'Your current password is incorrect.';
            } elseif (strlen($new) < 8) {
                $errors[] = 'New password must be at least 8 characters.';
            } elseif ($new !== $confirm) {
                $errors[] = 'New password and confirmation do not match.';
            } elseif ($current === $new) {
                $errors[] = 'Your new password must be different from your current password.';
            } else {
                $hashed = password_hash($new, PASSWORD_DEFAULT);
                $stmt = $mysqli->prepare("UPDATE users SET password_hash = ? WHERE id = ? AND role = 'dean'");
                $stmt->bind_param('si', $hashed, $userId);
                $stmt->execute();
                $stmt->close();
                $success = 'Password changed successfully.';
            }
        }
    }
}

$photoUrl = !empty($me['photo']) ? UPLOAD_URL . $me['photo'] : UPLOAD_URL . 'pbi_logo';
$displayDesignation = trim((string)($me['designation'] ?? '')) !== '' ? $me['designation'] : 'Dean';

// Which tab to show: after saving anything on the Profile tab, stay on it.
$activeTab = ($_SERVER['REQUEST_METHOD'] !== 'POST' && ($_GET['tab'] ?? '') === 'appearance')
           ? 'appearance' : 'profile';

$active = 'settings';
$sidebarScope = 'Higher Education Division';
?>
<!DOCTYPE html>
<html lang="en" class="dean-internal-scroll-page">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PBI — Settings</title>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{--dark:#0A192F;--mid:#172A45;--inner:#0F1F3D;--violet:#7C5FD9;--violet-h:#9C85F0;--light:#E0E6F0;--muted:#A0B3C6;--danger:#f05454;--good:#10B981;--shadow:0 8px 32px rgba(0,0,0,.45);--radius:12px}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{min-height:100vh;background:linear-gradient(rgba(5,18,36,.72),rgba(5,18,36,.82)),url('../background.png') center center/cover no-repeat fixed;background-color:var(--dark);font-family:'DM Sans',sans-serif;color:var(--light);display:flex}
.sidebar{width:250px;flex-shrink:0;background:#0F1F33;border-right:1px solid #060E18;min-height:100vh;padding:28px 20px;display:flex;flex-direction:column}
.sb-profile{text-align:center;margin-bottom:26px}.sb-photo{width:72px;height:72px;border-radius:50%;object-fit:cover;border:2.5px solid var(--violet);box-shadow:0 0 18px rgba(124,95,217,.4);margin:0 auto 10px;display:block}.sb-name{font-weight:700;font-size:15px;color:#fff}.sb-role{font-size:11px;color:var(--violet-h);text-transform:uppercase;letter-spacing:.6px;margin-top:2px}.sb-scope{font-size:10px;color:var(--muted);margin-top:4px}.sb-nav{display:flex;flex-direction:column;gap:4px;margin-top:10px}.sb-nav a{display:flex;align-items:center;gap:10px;padding:11px 14px;border-radius:8px;color:var(--muted);text-decoration:none;font-size:14px;font-weight:500;transition:.2s}.sb-nav a:hover,.sb-nav a.active{background:rgba(124,95,217,.15);color:#fff}.sb-nav a i{width:18px;text-align:center;color:var(--violet-h)}.sb-logout{margin-top:auto}.sb-logout a{display:flex;align-items:center;gap:10px;padding:11px 14px;border-radius:8px;color:#fca5a5;text-decoration:none;font-size:14px;font-weight:500}.sb-logout a:hover{background:rgba(240,84,84,.12)}
.main{flex:1;padding:36px 44px;min-width:0}.page-header{margin-bottom:26px}.page-title{font-family:'Rajdhani',sans-serif;font-size:30px;font-weight:700;color:#fff;letter-spacing:1px}.page-sub{font-size:13px;color:var(--muted);margin-top:4px}
.alert{border-radius:10px;padding:12px 15px;font-size:13px;margin-bottom:18px;display:flex;align-items:center;gap:9px}.alert.success{background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.3);color:#86efac}.alert.error{background:rgba(240,84,84,.12);border:1px solid rgba(240,84,84,.3);color:#ff9a9a}
.section{background:rgba(23,42,69,.86);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:24px;box-shadow:var(--shadow);margin-bottom:22px}.section h2{font-family:'Rajdhani',sans-serif;font-size:20px;color:#fff;margin-bottom:18px;display:flex;align-items:center;gap:9px}.section h2 i{color:var(--violet-h);font-size:17px}
.profile-card{display:flex;align-items:center;gap:24px}.profile-photo-lg{width:96px;height:96px;border-radius:50%;object-fit:cover;border:3px solid var(--violet);box-shadow:0 0 22px rgba(124,95,217,.3);background:var(--inner)}.photo-help{font-size:12px;color:var(--muted);margin-top:8px;line-height:1.5}
.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:17px 20px;margin-bottom:20px}.form-group{display:flex;flex-direction:column;gap:7px}.form-group label{font-size:11px;font-weight:600;letter-spacing:.8px;text-transform:uppercase;color:var(--muted)}.form-group input{width:100%;padding:12px 13px;background:rgba(10,25,47,.72);border:1px solid rgba(255,255,255,.1);border-radius:9px;color:var(--light);font:14px 'DM Sans',sans-serif;outline:none;transition:.2s}.form-group input:focus{border-color:var(--violet);box-shadow:0 0 0 3px rgba(124,95,217,.16)}.form-group input:disabled{opacity:.65;cursor:not-allowed;background:rgba(10,25,47,.5)}
.btn-primary{padding:11px 17px;border:0;border-radius:9px;background:var(--violet);color:#fff;font:600 14px 'DM Sans',sans-serif;cursor:pointer;box-shadow:0 4px 14px rgba(124,95,217,.3);transition:.2s}.btn-primary:hover{background:var(--violet-h);transform:translateY(-1px)}.btn-secondary{padding:11px 17px;border:1px solid rgba(255,255,255,.12);border-radius:9px;background:rgba(10,25,47,.5);color:var(--light);font:600 14px 'DM Sans',sans-serif;cursor:pointer}
.setting-row{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;flex-wrap:wrap}.setting-row strong{display:block;font-size:14px;color:var(--text-l,#fff);margin-bottom:2px}.setting-row .appearance-hint{font-size:12px;color:var(--muted-l,var(--muted))}.appearance-choice-row{display:flex;gap:10px;flex-wrap:wrap}.appearance-choice{display:flex;align-items:center;gap:8px;padding:10px 16px;border-radius:9px;border:1px solid var(--line-strong-l,rgba(255,255,255,.12));background:var(--card-l,rgba(10,25,47,.5));color:var(--muted-l,var(--muted));font:600 13px 'DM Sans',sans-serif;cursor:pointer;transition:.2s}.appearance-choice:hover{border-color:#7C5FD9;color:#7C5FD9}.appearance-choice.active{background:rgba(124,95,217,.14);border-color:#7C5FD9;color:#7C5FD9}
.password-note{font-size:12px;color:var(--muted);margin:-5px 0 18px}.info-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:20px}.info-item{padding:14px;border-radius:10px;background:rgba(10,25,47,.42);border:1px solid rgba(255,255,255,.06)}.info-item span{display:block;font-size:10px;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);margin-bottom:5px}.info-item strong{font-size:14px;color:#fff;font-weight:600;word-break:break-word}
@media(max-width:900px){.sidebar{width:220px}.main{padding:28px 24px}.form-grid,.info-grid{grid-template-columns:1fr}}@media(max-width:650px){body{display:block}.sidebar{width:100%;min-height:auto;padding:18px}.sb-nav{display:grid;grid-template-columns:repeat(2,1fr)}.sb-logout{margin-top:12px}.main{padding:24px 16px}.profile-card{align-items:flex-start;flex-direction:column}.form-grid,.info-grid{grid-template-columns:1fr}}
</style>
<link rel="stylesheet" href="includes/dean_light_theme.css?v=dashboard-ui-20261009"/>

<style>
/* Keep the Dean sidebar fixed and scroll this feature workspace internally. */
html.dean-internal-scroll-page,
html.dean-internal-scroll-page body {
  overflow: hidden !important;
  height: 100% !important;
}

main.main.dean-internal-scroll {
  height: calc(100vh - 20px) !important;
  max-height: calc(100vh - 20px) !important;
  min-height: 0 !important;
  overflow-y: scroll !important;
  overflow-x: hidden !important;
  scrollbar-gutter: stable;
  overscroll-behavior: contain;
  scrollbar-width: thin;
  scrollbar-color: #AEBAC8 #EEF2F6;
}

main.main.dean-internal-scroll::-webkit-scrollbar {
  width: 10px;
}

main.main.dean-internal-scroll::-webkit-scrollbar-track {
  background: #EEF2F6;
  border-radius: 10px;
}

main.main.dean-internal-scroll::-webkit-scrollbar-thumb {
  background: #AEBAC8;
  border: 2px solid #EEF2F6;
  border-radius: 10px;
}

main.main.dean-internal-scroll::-webkit-scrollbar-thumb:hover {
  background: #8F9CAB;
}

html.dark-theme main.main.dean-internal-scroll {
  scrollbar-color: #2A4468 #0F1F3D;
}

html.dark-theme main.main.dean-internal-scroll::-webkit-scrollbar-track {
  background: #0F1F3D;
}

html.dark-theme main.main.dean-internal-scroll::-webkit-scrollbar-thumb {
  background: #2A4468;
  border-color: #0F1F3D;
}

html.dark-theme main.main.dean-internal-scroll::-webkit-scrollbar-thumb:hover {
  background: #385A86;
}

@media (max-width: 768px) {
  html.dean-internal-scroll-page,
  html.dean-internal-scroll-page body {
    overflow: auto !important;
    height: auto !important;
  }

  main.main.dean-internal-scroll {
    height: auto !important;
    max-height: none !important;
    min-height: calc(100vh - 12px) !important;
    overflow: visible !important;
    scrollbar-gutter: auto;
  }
}
</style>
<script>
(function(){try{var d=document.documentElement;
if(localStorage.getItem('dean_density')==='compact')d.classList.add('dean-density-compact');
if(localStorage.getItem('dean_reduce_motion')==='1')d.classList.add('dean-reduce-motion');}catch(e){}})();
</script>
</head>
<body>
<?php $photo_src = $photoUrl; include __DIR__ . '/includes/dean_sidebar.php'; if (is_file(__DIR__ . '/dean_header_dark.php')) include __DIR__ . '/dean_header_dark.php'; ?>
<main class="main dean-internal-scroll">
    <div class="page-header">
        <div class="page-title"></div>
    </div>

    <?php if ($success): ?><div class="alert success"><i class="fa-solid fa-circle-check"></i><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php foreach ($errors as $err): ?><div class="alert error"><i class="fa-solid fa-circle-exclamation"></i><?= htmlspecialchars($err) ?></div><?php endforeach; ?>

    <div class="ac-tabs" role="tablist" aria-label="Settings sections">
        <button type="button" class="ac-tab<?= $activeTab === 'profile' ? ' active' : '' ?>" role="tab" id="tabBtnProfile" data-tab="profile" aria-controls="tab-profile" aria-selected="<?= $activeTab === 'profile' ? 'true' : 'false' ?>">
            <i class="fa-solid fa-user-gear"></i> Profile &amp; Security
        </button>
        <button type="button" class="ac-tab<?= $activeTab === 'appearance' ? ' active' : '' ?>" role="tab" id="tabBtnAppearance" data-tab="appearance" aria-controls="tab-appearance" aria-selected="<?= $activeTab === 'appearance' ? 'true' : 'false' ?>">
            <i class="fa-solid fa-palette"></i> Appearance
        </button>
    </div>

    <!-- TAB 1: APPEARANCE -->
    <div class="ac-tabpanel<?= $activeTab === 'appearance' ? ' active' : '' ?>" id="tab-appearance" role="tabpanel" aria-labelledby="tabBtnAppearance"<?= $activeTab === 'appearance' ? '' : ' hidden' ?>>
    <div class="section appearance-settings-section ac-card ac-c1" id="dean-appearance-settings">
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
                    <button type="button" class="appearance-choice" id="appearanceLightBtn" data-theme-choice="light" aria-pressed="false" onclick="setDeanAppearance('light')">
                        <span class="appearance-choice-icon"><i class="fa-solid fa-sun"></i></span>
                        <span class="appearance-choice-copy"><strong>Light</strong><small>Bright and clean</small></span>
                        <span class="appearance-choice-check"><i class="fa-solid fa-check"></i></span>
                    </button>
                    <button type="button" class="appearance-choice" id="appearanceDarkBtn" data-theme-choice="dark" aria-pressed="false" onclick="setDeanAppearance('dark')">
                        <span class="appearance-choice-icon"><i class="fa-solid fa-moon"></i></span>
                        <span class="appearance-choice-copy"><strong>Dark</strong><small>Low-light friendly</small></span>
                        <span class="appearance-choice-check"><i class="fa-solid fa-check"></i></span>
                    </button>
                    <button type="button" class="appearance-choice" id="appearanceSystemBtn" data-theme-choice="system" aria-pressed="false" onclick="setDeanAppearance('system')">
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
                    <button type="button" class="appearance-density-choice" data-density-choice="comfortable" aria-pressed="false" onclick="setDeanDensity('comfortable')"><i class="fa-solid fa-expand"></i><span><strong>Comfortable</strong><small>More breathing room</small></span></button>
                    <button type="button" class="appearance-density-choice" data-density-choice="compact" aria-pressed="false" onclick="setDeanDensity('compact')"><i class="fa-solid fa-compress"></i><span><strong>Compact</strong><small>More content on screen</small></span></button>
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
                <button type="button" class="appearance-motion-toggle" id="appearanceMotionBtn" aria-pressed="false" onclick="toggleDeanReducedMotion()">
                    <span class="appearance-motion-copy"><strong>Reduce animations</strong><small>Minimize transitions and motion effects</small></span>
                    <span class="appearance-switch-track" aria-hidden="true"><span class="appearance-switch-thumb"></span></span>
                </button>
                <div class="appearance-accessibility-note"><i class="fa-solid fa-shield-heart"></i> Your settings stay in this browser and do not change other users' views.</div>
            </section>
        </div>
    </div>

    </div><!-- /#tab-appearance -->

    <!-- TAB 2: PROFILE PHOTO, PROFILE DETAILS, CHANGE PASSWORD -->
    <div class="ac-tabpanel<?= $activeTab === 'profile' ? ' active' : '' ?>" id="tab-profile" role="tabpanel" aria-labelledby="tabBtnProfile"<?= $activeTab === 'profile' ? '' : ' hidden' ?>>

    <div class="section ac-card ac-c6 sq-top-card <?= $sq_status === 'ok' ? 'is-set' : 'is-unset' ?>" id="security-questions-settings">
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
            <img class="profile-photo-lg" src="<?= htmlspecialchars($photoUrl) ?>" alt="Dean profile photo" onerror="this.src='<?= htmlspecialchars(UPLOAD_URL . 'pbi_logo') ?>'">
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
                <div class="form-group"><label>Designation</label><input type="text" name="designation" maxlength="100" value="<?= htmlspecialchars(($me['designation'] ?? '') !== '' ? $me['designation'] : 'Dean') ?>" placeholder="e.g. Dean" required></div>
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
                <div class="form-group"><label>Current Password</label><div class="pw-wrap"><input type="password" name="current_password" minlength="1" required autocomplete="current-password"><button type="button" class="pw-toggle" aria-label="Show password" aria-pressed="false"><svg class="pw-eye" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg><svg class="pw-eye-off" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 19C5 19 1 12 1 12a18.5 18.5 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.1 9.1 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12A3 3 0 1 1 9.88 9.88"/><line x1="1" y1="1" x2="23" y2="23"/></svg></button></div></div>
                <div class="form-group"></div>
                <div class="form-group"><label>New Password</label><div class="pw-wrap"><input type="password" name="new_password" minlength="8" required autocomplete="new-password"><button type="button" class="pw-toggle" aria-label="Show password" aria-pressed="false"><svg class="pw-eye" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg><svg class="pw-eye-off" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 19C5 19 1 12 1 12a18.5 18.5 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.1 9.1 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12A3 3 0 1 1 9.88 9.88"/><line x1="1" y1="1" x2="23" y2="23"/></svg></button></div></div>
                <div class="form-group"><label>Confirm New Password</label><div class="pw-wrap"><input type="password" name="confirm_password" minlength="8" required autocomplete="new-password"><button type="button" class="pw-toggle" aria-label="Show password" aria-pressed="false"><svg class="pw-eye" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg><svg class="pw-eye-off" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 19C5 19 1 12 1 12a18.5 18.5 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.1 9.1 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12A3 3 0 1 1 9.88 9.88"/><line x1="1" y1="1" x2="23" y2="23"/></svg></button></div></div>
            </div>
            <button class="btn-primary" type="submit"><i class="fa-solid fa-key"></i> Update Password</button>
        </form>
    </div>
    </div><!-- /#tab-profile -->
</main>

<?php
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
main.main .sq-top-card.is-unset{border:2px solid #E2A33B !important;box-shadow:0 0 0 4px rgba(226,163,59,.16) !important}
main.main .sq-top-card.is-unset::before{background:#E2A33B !important}
main.main .sq-top-card.is-unset .ac-icon{background:rgba(226,163,59,.18) !important;color:#D97706 !important}
main.main .sq-top-card.is-unset .ac-icon i{color:#D97706 !important}

.sqm-overlay{position:fixed;inset:0;z-index:2147483000;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(11,31,58,.55);overscroll-behavior:contain}
.sqm-overlay[hidden]{display:none}
.sqm-modal{--sq-acc:#7C5FD9;--sq-acc-h:#9C85F0;--sq-line:rgba(128,140,160,.30);width:min(620px,100%);max-height:calc(100vh - 40px);overflow:auto;background:var(--mid,#fff);border:1px solid var(--sq-line);border-radius:16px;padding:20px 22px;box-shadow:0 24px 60px rgba(11,31,58,.35);color:var(--light,#0B1F3A)}
.sqm-modal,.sqm-modal *{box-sizing:border-box;font-family:'DM Sans',system-ui,-apple-system,"Segoe UI",sans-serif}
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
    fetch('dean_account_settings.php',{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}})
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
    fetch('dean_account_settings.php',{method:'POST',body:new FormData(form),credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(r){ return r.json(); })
      .then(function(d){
        if(d&&d.ok){ location.href='dean_account_settings.php?tab=profile'; return; }
        showErr((d&&d.message)||'The security questions could not be saved. Please try again.'); md.scrollTop=0;
      })
      .catch(function(){ showErr('Could not reach the server. Please try again.'); })
      .then(function(){ busy=false; save.textContent='Save questions'; sync(); });
  });
})();
</script>
<script>
/* Dean appearance controls — same options as the Principal settings page. */
(function(){
  var KEY_THEME='dean_theme_pref', KEY_DENSITY='dean_density', KEY_MOTION='dean_reduce_motion';
  var root=document.documentElement, mq=window.matchMedia?window.matchMedia('(prefers-color-scheme: dark)'):null;
  function get(k,d){try{var v=localStorage.getItem(k);return v===null?d:v;}catch(e){return d;}}
  function set(k,v){try{localStorage.setItem(k,v);}catch(e){}}

  function currentPref(){
    var p=get(KEY_THEME,null);
    if(p==='light'||p==='dark'||p==='system')return p;
    return root.classList.contains('dark-theme')?'dark':'light';
  }
  function applyResolved(mode){
    // Prefer the portal's own switcher (defined in the shared sidebar/header) when present.
    if(typeof window.setAppearance==='function'){ try{ window.setAppearance(mode); }catch(e){} }
    root.classList.toggle('dark-theme',mode==='dark');
  }
  function paintTheme(pref){
    document.querySelectorAll('[data-theme-choice]').forEach(function(b){
      var on=b.getAttribute('data-theme-choice')===pref;
      b.setAttribute('aria-pressed',on?'true':'false'); b.classList.toggle('active',on);
    });
    var out=document.getElementById('appearanceCurrentMode');
    if(out){ var eff=root.classList.contains('dark-theme')?'Dark':'Light';
      out.textContent=pref==='system'?'System ('+eff+')':eff; }
  }
  window.setDeanAppearance=function(pref){
    set(KEY_THEME,pref);
    applyResolved(pref==='system'?(mq&&mq.matches?'dark':'light'):pref);
    paintTheme(pref);
  };
  if(mq){ var onChange=function(){ if(currentPref()==='system'){ applyResolved(mq.matches?'dark':'light'); paintTheme('system'); } };
    if(mq.addEventListener)mq.addEventListener('change',onChange); else if(mq.addListener)mq.addListener(onChange); }

  function paintDensity(d){
    document.querySelectorAll('[data-density-choice]').forEach(function(b){
      b.setAttribute('aria-pressed',b.getAttribute('data-density-choice')===d?'true':'false');
    });
  }
  window.setDeanDensity=function(d){
    root.classList.toggle('dean-density-compact',d==='compact');
    set(KEY_DENSITY,d); paintDensity(d);
  };

  function paintMotion(on){
    var b=document.getElementById('appearanceMotionBtn'); if(b)b.setAttribute('aria-pressed',on?'true':'false');
  }
  window.toggleDeanReducedMotion=function(){
    var on=!root.classList.contains('dean-reduce-motion');
    root.classList.toggle('dean-reduce-motion',on);
    set(KEY_MOTION,on?'1':'0'); paintMotion(on);
  };

  document.addEventListener('DOMContentLoaded',function(){
    var pref=currentPref();
    if(pref==='system')applyResolved(mq&&mq.matches?'dark':'light');
    paintTheme(pref);
    paintDensity(get(KEY_DENSITY,'comfortable')==='compact'?'compact':'comfortable');
    paintMotion(get(KEY_MOTION,'0')==='1');
  });
})();
</script>
<style id="dean-password-toggle">
main.main .pw-wrap{position:relative;display:block;}
main.main .pw-wrap input{width:100%;padding-right:46px !important;}
main.main .pw-toggle{position:absolute;top:50%;right:8px;transform:translateY(-50%);width:34px;height:34px;display:flex;align-items:center;justify-content:center;border:0;border-radius:8px;background:transparent;color:#64748B;cursor:pointer;padding:0;}
main.main .pw-toggle .pw-eye{display:none;}
main.main .pw-toggle .pw-eye-off{display:block;}
main.main .pw-toggle[aria-pressed="true"] .pw-eye{display:block;}
main.main .pw-toggle[aria-pressed="true"] .pw-eye-off{display:none;}
main.main .pw-toggle:hover{color:#5F45B8;background:rgba(124,95,217,.12);}
main.main .pw-toggle:focus-visible{outline:2px solid #7C5FD9;outline-offset:1px;}
</style>
<script>
/* Settings tabs: Profile & Security | Appearance */
(function(){
    var tabs = Array.prototype.slice.call(document.querySelectorAll('.ac-tab'));
    var panels = Array.prototype.slice.call(document.querySelectorAll('.ac-tabpanel'));
    function show(name, focus){
        tabs.forEach(function(t){
            var on = t.getAttribute('data-tab') === name;
            t.classList.toggle('active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
            t.tabIndex = on ? 0 : -1;
            if (on && focus) t.focus();
        });
        panels.forEach(function(p){
            var on = p.id === 'tab-' + name;
            p.classList.toggle('active', on);
            p.hidden = !on;
        });
        try { history.replaceState(null, '', '?tab=' + name); } catch(e){}
    }
    tabs.forEach(function(t, i){
        t.addEventListener('click', function(){ show(t.getAttribute('data-tab')); });
        t.addEventListener('keydown', function(e){
            if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
            var n = (i + (e.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
            show(tabs[n].getAttribute('data-tab'), true);
        });
    });
    tabs.forEach(function(t){ if (!t.classList.contains('active')) t.tabIndex = -1; });
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
<script id="dean-rename-settings-label">
/* Sidebar/header label: "Account Settings" -> "Settings" (stopgap until includes/dean_sidebar.php is edited). */
(function(){
    function rename(){
        var w=document.createTreeWalker(document.body,NodeFilter.SHOW_TEXT,{acceptNode:function(n){
            var p=n.parentNode&&n.parentNode.nodeName;
            return (p==='SCRIPT'||p==='STYLE'||p==='TEXTAREA')?NodeFilter.FILTER_REJECT:NodeFilter.FILTER_ACCEPT;
        }});
        var n,list=[];
        while((n=w.nextNode())){ if(n.nodeValue.trim()==='Account Settings') list.push(n); }
        list.forEach(function(t){ t.nodeValue=t.nodeValue.replace('Account Settings','Settings'); });
    }
    if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',rename); else rename();
})();
</script>
</body>
<link rel="stylesheet" href="includes/dean_light_theme.css?v=dashboard-ui-20261009" id="dean-light-theme-final"/>

<style id="ac-faculty-style">
/* ── Account Settings — same card language as the Faculty portal ──
   Coloured top bar, icon tile, title + description. Surface/text colours are left to the
   existing theme so light and dark mode keep working; only structure + accents change. */
main.main{--ac:#7C5FD9;}
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
<style id="dean-principal-ui-match">
/* ================================================================
   Dean settings — matched to the Principal settings interface:
   white viewport + inset #F8FAFC workspace, Dean sidebar and violet
   accents, white cards with a coloured top bar, and the three-card
   Appearance panel (Theme / Spacing / Accessibility).
   ================================================================ */
html:not(.dark-theme){background:#FFFFFF !important;color-scheme:light !important;}
html:not(.dark-theme) body{background:#FFFFFF !important;background-image:none !important;color:#172033 !important;}

/* Inset workspace canvas */
html:not(.dark-theme) main.main{
  position:relative !important;flex:1 1 auto !important;width:auto !important;max-width:none !important;
  margin:20px 20px 0 20px !important;padding:24px 34px 34px !important;
  background:#F8FAFC !important;color:#172033 !important;
  border-radius:16px 16px 0 0 !important;box-shadow:none !important;
}
html:not(.dark-theme) main.main::before{display:none !important;content:none !important;}
html:not(.dark-theme) main.main > .page-header{background:transparent !important;border:0 !important;box-shadow:none !important;padding:0 !important;margin:0 0 22px !important;}
html:not(.dark-theme) .page-title{color:#0F172A !important;}

/* White cards */
html:not(.dark-theme) main.main .section{
  background:#FFFFFF !important;color:#172033 !important;border-color:#D9E4EF !important;
  box-shadow:0 1px 5px rgba(15,23,42,.035) !important;
}
html:not(.dark-theme) main.main .ac-head h2{color:#0F172A !important;}
html:not(.dark-theme) main.main .ac-head .ac-sub,
html:not(.dark-theme) .photo-help,
html:not(.dark-theme) .form-group label{color:#64748B !important;}

/* Inputs + buttons */
html:not(.dark-theme) .form-group input{background:#FFFFFF !important;color:#172033 !important;border-color:#CBD5E1 !important;}
html:not(.dark-theme) .form-group input:disabled{background:#F1F5F9 !important;}
.form-group input:focus{border-color:#7C5FD9 !important;box-shadow:0 0 0 3px rgba(124,95,217,.12) !important;}
.btn-primary{background:#7C5FD9 !important;color:#fff !important;box-shadow:0 4px 14px rgba(124,95,217,.25) !important;}
.btn-primary:hover{background:#9C85F0 !important;color:#fff !important;}
.profile-photo-lg{border-color:#7C5FD9 !important;box-shadow:0 0 22px rgba(124,95,217,.22) !important;}
html:not(.dark-theme) .info-item{background:#F8FAFC !important;border-color:#E2E8F0 !important;}
html:not(.dark-theme) .info-item span{color:#64748B !important;}
html:not(.dark-theme) .info-item strong{color:#0F172A !important;}
html:not(.dark-theme) .alert.success{background:rgba(16,185,129,.10);color:#047857;border-color:rgba(16,185,129,.25);}
html:not(.dark-theme) .alert.error{background:rgba(240,84,84,.10);color:#b91c1c;border-color:rgba(240,84,84,.25);}

/* ── Appearance panel ─────────────────────────────────────────── */
main.main{--ap-card:#FFFFFF;--ap-soft:#F8FAFC;--ap-line:#E2E8F0;--ap-text:#0F172A;--ap-muted:#64748B;--ap-accent:#7C5FD9;--ap-accent-bg:rgba(124,95,217,.10);}
html.dark-theme main.main{--ap-card:rgba(10,25,47,.55);--ap-soft:rgba(10,25,47,.45);--ap-line:rgba(255,255,255,.10);--ap-text:#E0E6F0;--ap-muted:#A0B3C6;--ap-accent-bg:rgba(124,95,217,.16);}

.appearance-device-badge{margin-left:auto;display:inline-flex;align-items:center;gap:7px;align-self:flex-start;padding:6px 12px;border-radius:999px;font-size:11.5px;font-weight:600;white-space:nowrap;background:var(--ap-accent-bg);color:#6B4FCB;border:1px solid rgba(124,95,217,.28);}
html.dark-theme .appearance-device-badge{color:#9C85F0;}
.appearance-feature-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;}
.appearance-theme-card{grid-column:1 / -1;}
.appearance-control-card{background:var(--ap-soft);border:1px solid var(--ap-line);border-radius:14px;padding:18px;}
.appearance-control-head{display:flex;align-items:flex-start;gap:12px;margin-bottom:14px;}
.appearance-control-icon{width:34px;height:34px;flex:0 0 34px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;background:var(--ap-accent-bg);color:var(--ap-accent);font-size:14px;}
.appearance-control-head h3{font-size:15px;font-weight:700;color:var(--ap-text);margin:0 0 2px;}
.appearance-control-head p{font-size:12.5px;color:var(--ap-muted);margin:0;line-height:1.45;}

.appearance-theme-options{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;}
.appearance-choice{position:relative;display:flex;align-items:center;gap:12px;padding:14px;text-align:left;border-radius:12px;border:1.5px solid var(--ap-line);background:var(--ap-card);color:var(--ap-text);cursor:pointer;font-family:inherit;transition:border-color .2s,background .2s,box-shadow .2s;}
.appearance-choice:hover{border-color:var(--ap-accent);}
.appearance-choice-icon{width:36px;height:36px;flex:0 0 36px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;background:var(--ap-soft);color:var(--ap-muted);font-size:15px;}
.appearance-choice-copy strong,.appearance-density-choice strong,.appearance-motion-copy strong{display:block;font-size:13.5px;font-weight:700;color:var(--ap-text);}
.appearance-choice-copy small,.appearance-density-choice small,.appearance-motion-copy small{display:block;font-size:11.5px;color:var(--ap-muted);margin-top:1px;}
.appearance-choice-check{margin-left:auto;width:20px;height:20px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:10px;background:var(--ap-accent);color:#fff;opacity:0;transform:scale(.6);transition:.2s;}
.appearance-choice[aria-pressed="true"]{border-color:var(--ap-accent);background:var(--ap-accent-bg);box-shadow:0 0 0 3px rgba(124,95,217,.12);}
.appearance-choice[aria-pressed="true"] .appearance-choice-icon{background:var(--ap-accent);color:#fff;}
.appearance-choice[aria-pressed="true"] .appearance-choice-check{opacity:1;transform:scale(1);}
.appearance-inline-status{margin-top:14px;font-size:12.5px;color:var(--ap-muted);display:flex;align-items:center;gap:7px;}
.appearance-inline-status i{color:var(--ap-accent);}
.appearance-inline-status strong{color:var(--ap-text);}

.appearance-density-options{display:grid;gap:10px;}
.appearance-density-choice{display:flex;align-items:center;gap:12px;padding:12px 14px;text-align:left;border-radius:12px;border:1.5px solid var(--ap-line);background:var(--ap-card);color:var(--ap-muted);cursor:pointer;font-family:inherit;transition:.2s;}
.appearance-density-choice:hover{border-color:var(--ap-accent);}
.appearance-density-choice > i{font-size:15px;width:20px;text-align:center;}
.appearance-density-choice[aria-pressed="true"]{border-color:var(--ap-accent);background:var(--ap-accent-bg);}
.appearance-density-choice[aria-pressed="true"] > i{color:var(--ap-accent);}

.appearance-motion-toggle{width:100%;display:flex;align-items:center;justify-content:space-between;gap:14px;padding:12px 14px;text-align:left;border-radius:12px;border:1.5px solid var(--ap-line);background:var(--ap-card);cursor:pointer;font-family:inherit;transition:.2s;}
.appearance-motion-toggle:hover{border-color:var(--ap-accent);}
.appearance-switch-track{width:44px;height:24px;flex:0 0 44px;border-radius:999px;background:#CBD5E1;position:relative;transition:background .2s;}
.appearance-switch-thumb{position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:transform .2s;}
.appearance-motion-toggle[aria-pressed="true"]{border-color:var(--ap-accent);background:var(--ap-accent-bg);}
.appearance-motion-toggle[aria-pressed="true"] .appearance-switch-track{background:var(--ap-accent);}
.appearance-motion-toggle[aria-pressed="true"] .appearance-switch-thumb{transform:translateX(20px);}
.appearance-accessibility-note{margin-top:12px;font-size:11.5px;color:var(--ap-muted);display:flex;gap:7px;align-items:flex-start;line-height:1.5;}
.appearance-accessibility-note i{color:var(--ap-accent);margin-top:2px;}

/* Density + reduced motion */
html.dean-density-compact main.main{padding-top:18px !important;padding-bottom:24px !important;}
html.dean-density-compact main.main .ac-card{padding:16px 18px 18px !important;margin-bottom:14px !important;}
html.dean-density-compact main.main .ac-head{margin-bottom:12px;}
html.dean-density-compact .form-grid{gap:12px 16px;margin-bottom:14px;}
html.dean-reduce-motion *,html.dean-reduce-motion *::before,html.dean-reduce-motion *::after{transition:none !important;animation:none !important;scroll-behavior:auto !important;}

@media (max-width:900px){.appearance-theme-options{grid-template-columns:1fr;}}
@media (max-width:768px){
  html:not(.dark-theme) main.main{margin:12px 12px 0 12px !important;padding:20px 18px 28px !important;border-radius:12px 12px 0 0 !important;}
  .appearance-feature-grid{grid-template-columns:1fr;}
  .appearance-device-badge{display:none;}
}
@media print{html:not(.dark-theme) main.main{margin:0 !important;background:#fff !important;}}

/* ── Settings tabs ───────────────────────────────────────────── */
/* Separate pill buttons (same style as Account Management), in the Dean violet */
.ac-tabs{display:flex;gap:10px;margin:0 0 16px;padding:2px;width:fit-content;max-width:100%;overflow-x:auto;background:transparent;border:0;border-radius:0;}
.ac-tab{display:inline-flex;align-items:center;gap:8px;padding:10px 18px;border:1px solid var(--ap-line,#E2E8F0);border-radius:10px;background:var(--ap-card,#fff);color:var(--ap-muted,#64748B);font:700 13.5px 'DM Sans',sans-serif;cursor:pointer;white-space:nowrap;transition:background .2s,color .2s,border-color .2s;}
.ac-tab i{font-size:14px;}
.ac-tab:hover{color:var(--ap-text,#0F172A);border-color:#B9A8EE;background:var(--ap-card,#fff);}
.ac-tab.active{background:#7C5FD9;border-color:#5F45B8;color:#fff;box-shadow:0 4px 14px rgba(124,95,217,.25);}
.ac-tab.active:hover{color:#fff;background:#7C5FD9;}
/* Pull the whole workspace up: the empty page header only added dead space above the tabs */
main.main > .page-header{display:none !important;}
html:not(.dark-theme) main.main.dean-internal-scroll{margin-top:8px !important;padding-top:6px !important;height:calc(100vh - 8px) !important;max-height:calc(100vh - 8px) !important;}
html.dark-theme main.main.dean-internal-scroll{padding-top:8px !important;}
/* Tighten the gap above the tabs. Doubled selectors so shared theme files cannot out-rank this. */
html body main.main.main.dean-internal-scroll{margin-top:0 !important;padding-top:8px !important;}
html body main.main.main.dean-internal-scroll::before,
html body main.main.main.dean-internal-scroll::after{display:none !important;content:none !important;}
html body main.main.main.dean-internal-scroll > .page-header{display:none !important;margin:0 !important;padding:0 !important;height:0 !important;}
html body main.main.main.dean-internal-scroll > .ac-tabs{margin-top:0 !important;margin-bottom:14px !important;padding-top:0 !important;}
html body main.main.main.dean-internal-scroll > .alert{margin-top:0 !important;}
.ac-tab:focus-visible{outline:2px solid #7C5FD9;outline-offset:2px;}
.ac-tabpanel[hidden]{display:none !important;}
@media(max-width:600px){.ac-tabs{width:100%;}.ac-tab{flex:1;justify-content:center;padding:10px 12px;}}
</style>
</html>
<?php $mysqli->close(); ?>