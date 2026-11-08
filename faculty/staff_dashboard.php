<?php
/* Evaluate Others: per-group progress bar + call to action for the picker cards. */
if (!function_exists('fx_group_progress')) {
    function fx_group_progress(int $n, int $done): string {
        $out = '';
        if ($n > 0) {
            $pct = (int) round($done / $n * 100);
            $out .= '<div class="eval-group-progress' . ($pct === 100 ? ' complete' : '') . '" role="img" aria-label="' . $done . ' of ' . $n . ' evaluated">'
                  . '<span class="eval-group-bar"><i style="width:' . $pct . '%"></i></span>'
                  . '<span class="eval-group-progress-text"><strong>' . $done . '</strong> of ' . $n . ' evaluated</span></div>';
        } else {
            $out .= '<div class="eval-group-progress-text empty">No eligible members</div>';
        }
        return $out . '<span class="eval-group-cta"><span class="cta-select">Select group <i class="fa-solid fa-arrow-right"></i></span><span class="cta-selected"><i class="fa-solid fa-check"></i> Selected</span></span>';
    }
}

        // session_bootstrap.php — include this BEFORE session_start() everywhere
        session_set_cookie_params([
            'lifetime' => 0,        // session cookie, dies when browser closes
            'path'     => '/',      // available across the whole site, not just /admin/
            'domain'   => '',       // let the browser infer it — avoids localhost vs IP mismatches
            'secure'   => false,    // set true only if you're on https
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        require_once 'db.php';
        require_once '../shared/eligibility.php';
        require_once '../shared/ea_personnel_service.php';
require_once '../shared/system_settings_service.php';
require_once '../shared/QuestionnaireService.php';
require_once __DIR__ . '/kept_feedback.php';
require_once __DIR__ . '/received_results.php';

// ── Current-period helpers (same rule the Dean dashboard uses) ───────────────
// A period that System Archive has archived is never "current", even if its is_active flag is
// on, and when several periods are active the one System Settings points to wins. The archive
// table is created lazily by System Archive, so every check is skipped when it does not exist.
if (!function_exists('pbi_not_archived_sql')) {
    function pbi_not_archived_sql(mysqli $db, string $alias = 'evaluation_periods'): string {
        static $hasArchive = null;
        if ($hasArchive === null) {
            try { $r = $db->query("SHOW TABLES LIKE 'system_archives'"); $hasArchive = (bool)($r && $r->num_rows > 0); }
            catch (Throwable $e) { $hasArchive = false; }
        }
        return $hasArchive
            ? " AND NOT EXISTS (SELECT 1 FROM system_archives sa WHERE sa.period_id={$alias}.id AND sa.status='archived')"
            : '';
    }
}
if (!function_exists('pbi_current_period_tail')) {
    function pbi_current_period_tail(mysqli $db): string {
        $pid = 0;
        try {
            if (function_exists('get_system_settings')) { $st = get_system_settings($db); $pid = (int)($st['period_id'] ?? 0); }
        } catch (Throwable $e) { $pid = 0; }
        return pbi_not_archived_sql($db) . ($pid > 0 ? " ORDER BY (id=" . $pid . ") DESC, id DESC" : " ORDER BY id DESC");
    }
}
        // ── AUTH GUARD ────────────────────────────────────────────────
        if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
            header("Location: staff_login.php"); exit;
        }

        $user_id     = $_SESSION['user_id'];
        require_once '../shared/notification_message.php';
        require_once __DIR__ . '/notification_links.php';
        $full_name   = $_SESSION['full_name']   ?? 'Staff';
        $designation = $_SESSION['designation'] ?? 'Staff';

qn_migrate_legacy_once($mysqli);
        $page        = $_GET['page'] ?? 'dashboard';
        // NOTE: 'peer' / 'peer_eval' are live pages in their own right (the
        // Peer Evaluation feature below) and must NOT be aliased away — only
        // the old EA-only URLs collapse into the unified Staff Evaluation page.
        if ($page === 'ea_eval') $page = 'staff_eval';
        if ($page === 'ea_eval_form') $page = 'staff_eval_form';

// ── SECURITY QUESTIONS STATUS (for the Settings card) ────────
// none = not set up, ok = 3 current questions saved, retired = one of them is no longer offered.
$sq_status = 'none';
$sq_existing = [];
if ($page === 'settings') {
    try {
        require_once __DIR__ . '/security.php';
        security_ensure_tables($mysqli);
        $sq_existing = sq_load($mysqli, (int)$user_id);
        if (count($sq_existing) === 3) { $sq_status = sq_needs_update($sq_existing) ? 'retired' : 'ok'; }
    } catch (Throwable $e) { error_log('settings security-questions status failed: ' . $e->getMessage()); }
}

if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        $csrf_token = $_SESSION['csrf_token'];

// ── SECURITY QUESTIONS POPUP (AJAX, returns JSON) ─────────────
// Step 1 (sq_verify=1) only checks the current password; step 2 checks it again and saves.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['sq_ajax'] ?? '') === '1') {
    $sqOut = ['ok' => false, 'message' => 'Something went wrong. Please try again.'];
    try {
        if (!hash_equals($csrf_token, (string)($_POST['csrf_token'] ?? ''))) {
            $sqOut['message'] = 'Your session expired. Please reload the page and try again.';
        } else {
            require_once __DIR__ . '/security.php';
            security_ensure_tables($mysqli);
            $sqUid  = (int)$user_id;
            $sqRole = 'staff';
            $sqKind = 'sqchange_' . $sqRole;
            $sqSt = $mysqli->prepare("SELECT username, password_hash FROM users WHERE id = ? AND role = ? AND is_active = 1 LIMIT 1");
            $sqSt->bind_param('is', $sqUid, $sqRole);
            $sqSt->execute();
            $sqMe = $sqSt->get_result()->fetch_assoc();
            $sqSt->close();
            $sqGiven = (string)($_POST['current_password'] ?? '');
            if (!$sqMe) {
                $sqOut['message'] = 'Your account could not be found. Please log in again.';
            } elseif (auth_is_locked($mysqli, $sqKind, (string)$sqUid)) {
                $sqOut['message'] = AUTH_LOCK_MESSAGE;
            } elseif ($sqGiven === '' || !password_verify($sqGiven, (string)$sqMe['password_hash'])) {
                auth_record($mysqli, $sqKind, (string)$sqUid, false);
                $sqOut['message'] = 'Your current password is not correct.';
            } elseif (($_POST['sq_verify'] ?? '') === '1') {
                auth_clear($mysqli, $sqKind, (string)$sqUid);
                $sqOut = ['ok' => true, 'message' => 'Password confirmed.'];
            } else {
                auth_clear($mysqli, $sqKind, (string)$sqUid);
                [$sqErr, $sqRows] = function_exists('sq_validate_update')
                    ? sq_validate_update($_POST['sq_question'] ?? [], $_POST['sq_answer'] ?? [], (string)$sqMe['username'], sq_load($mysqli, $sqUid))
                    : sq_validate($_POST['sq_question'] ?? [], $_POST['sq_answer'] ?? [], (string)$sqMe['username']);
                if ($sqErr !== null) {
                    $sqOut['message'] = $sqErr;
                } else {
                    $mysqli->begin_transaction();
                    try {
                        sq_save($mysqli, $sqUid, $sqRows);
                        $mysqli->commit();
                        $_SESSION['toast'] = 'Security questions updated successfully.';
                        $sqOut = ['ok' => true, 'message' => 'Security questions updated successfully.'];
                    } catch (Throwable $e) {
                        try { $mysqli->rollback(); } catch (Throwable $ignored) {}
                        error_log('security questions save failed: ' . $e->getMessage());
                        $sqOut['message'] = 'The security questions could not be saved. Please try again.';
                    }
                }
            }
        }
    } catch (Throwable $e) {
        error_log('security questions popup failed: ' . $e->getMessage());
    }
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($sqOut);
    exit;
}

// ── PROFILE PHOTO UPLOAD (handled inside the authenticated portal) ──
// Posting to this dashboard keeps the user's existing portal session and
// returns them to the correct role/page after the photo is saved.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['photo'])) {
    $returnPage = (string)($_POST['return_page'] ?? 'profile');
    if (!in_array($returnPage, ['settings', 'profile'], true)) $returnPage = 'profile';
    $returnUrl = 'staff_dashboard.php?page=' . rawurlencode($returnPage);

    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
        $_SESSION['toast_error'] = 'Your session expired. Please refresh the page and try again.';
        header('Location: ' . $returnUrl);
        exit;
    }

    $upload = $_FILES['photo'];
    $uploadError = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) {
        $_SESSION['toast_error'] = in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'Photo must be 10MB or smaller.'
            : ($uploadError === UPLOAD_ERR_NO_FILE ? 'Please choose a photo to upload.' : 'Photo upload failed. Please try again.');
        header('Location: ' . $returnUrl);
        exit;
    }

    if (!is_uploaded_file((string)($upload['tmp_name'] ?? ''))) {
        $_SESSION['toast_error'] = 'The uploaded photo could not be verified. Please try again.';
        header('Location: ' . $returnUrl);
        exit;
    }
    if ((int)($upload['size'] ?? 0) > 10 * 1024 * 1024) {
        $_SESSION['toast_error'] = 'Photo must be 10MB or smaller.';
        header('Location: ' . $returnUrl);
        exit;
    }

    $allowedPhotoTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $photoMime = '';
    try {
        $photoInfo = new finfo(FILEINFO_MIME_TYPE);
        $photoMime = $photoInfo->file((string)$upload['tmp_name']) ?: '';
    } catch (Throwable $photoCheckError) {
        $photoMime = '';
    }
    if (!isset($allowedPhotoTypes[$photoMime])) {
        $_SESSION['toast_error'] = 'Please upload a JPG, PNG, WEBP, or GIF image.';
        header('Location: ' . $returnUrl);
        exit;
    }

    $photoDir = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR;
    if (!is_dir($photoDir) && !@mkdir($photoDir, 0755, true)) {
        $_SESSION['toast_error'] = 'The profile photo folder is not available.';
        header('Location: ' . $returnUrl);
        exit;
    }
    if (!is_writable($photoDir)) {
        $_SESSION['toast_error'] = 'The profile photo folder is not writable.';
        header('Location: ' . $returnUrl);
        exit;
    }

    $newPhoto = 'staff_' . bin2hex(random_bytes(12)) . '.' . $allowedPhotoTypes[$photoMime];
    $newPhotoPath = $photoDir . $newPhoto;
    $photoSavedToDisk = false;
    try {
        $oldPhotoStmt = $mysqli->prepare('SELECT photo FROM users WHERE id = ? LIMIT 1');
        $oldPhotoStmt->bind_param('i', $user_id);
        $oldPhotoStmt->execute();
        $oldPhoto = (string)($oldPhotoStmt->get_result()->fetch_assoc()['photo'] ?? '');
        $oldPhotoStmt->close();

        if (!move_uploaded_file((string)$upload['tmp_name'], $newPhotoPath)) {
            throw new RuntimeException('move_uploaded_file failed');
        }
        $photoSavedToDisk = true;

        $savePhotoStmt = $mysqli->prepare('UPDATE users SET photo = ? WHERE id = ?');
        $savePhotoStmt->bind_param('si', $newPhoto, $user_id);
        if (!$savePhotoStmt->execute()) {
            $savePhotoStmt->close();
            throw new RuntimeException('Photo database update failed');
        }
        $savePhotoStmt->close();

        // Delete only files created by this upload flow, and only after the DB update succeeds.
        $managedOldPhoto = str_starts_with($oldPhoto, 'fac_') || str_starts_with($oldPhoto, 'staff_');
        if ($oldPhoto !== '' && basename($oldPhoto) === $oldPhoto && $managedOldPhoto && is_file($photoDir . $oldPhoto)) {
            @unlink($photoDir . $oldPhoto);
        }
        $_SESSION['toast'] = 'Profile photo updated successfully.';
    } catch (Throwable $photoSaveError) {
        if ($photoSavedToDisk && is_file($newPhotoPath)) @unlink($newPhotoPath);
        error_log('[staff_dashboard.php] Profile photo update failed for user ' . (int)$user_id . ': ' . $photoSaveError->getMessage());
        $_SESSION['toast_error'] = 'Unable to update your profile photo right now. Please try again.';
    }

    header('Location: ' . $returnUrl);
    exit;
}


        function csrf_check(): bool {
            return isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token']);
        }

// Centralized live schedule gate for Faculty/Staff evaluation access.
// Follow Schedule uses Asia/Manila and the configured [start, end) window.
// Force Open / Force Closed remain explicit overrides.
function staff_schedule_settings(mysqli $mysqli): array {
    $out = [];
    $rs = $mysqli->query("SELECT setting_key, setting_value FROM system_settings");
    if ($rs) {
        while ($row = $rs->fetch_assoc()) {
            $out[(string)$row['setting_key']] = (string)$row['setting_value'];
        }
        $rs->free();
    }
    return $out;
}

function staff_parse_manila_datetime(?string $raw): ?DateTimeImmutable {
    $raw = trim((string)$raw);
    if ($raw === '') return null;
    $tz = new DateTimeZone('Asia/Manila');
    $formats = ['Y-m-d\\TH:i:s', 'Y-m-d\\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i'];
    foreach ($formats as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $raw, $tz);
        if ($dt !== false) {
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors === false || ($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0) {
                return $dt;
            }
        }
    }
    try {
        return new DateTimeImmutable($raw, $tz);
    } catch (Throwable $e) {
        return null;
    }
}

function staff_schedule_is_open(mysqli $mysqli): bool {
    $s = staff_schedule_settings($mysqli);
    $mode = strtolower(trim((string)($s['control_mode'] ?? 'schedule')));

    if ($mode === 'open') return true;
    if ($mode === 'closed') return false;

    $start = staff_parse_manila_datetime($s['eval_start'] ?? '');
    $end   = staff_parse_manila_datetime($s['eval_end'] ?? '');
    if (!$start || !$end || $end <= $start) return false;

    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    return $now >= $start && $now < $end;
}


        // ── FETCH MY PHOTO ────────────────────────────────────────────
        $photo_url   = null;
        $staff_photo = '';
        $pq = $mysqli->prepare("SELECT photo FROM users WHERE id=? LIMIT 1");
        $pq->bind_param("i", $user_id); $pq->execute(); $pq->bind_result($photo_db); $pq->fetch(); $pq->close();
        $account_username = '';
        $userNameStmt = $mysqli->prepare("SELECT username FROM users WHERE id=? LIMIT 1");
        $userNameStmt->bind_param("i", $user_id);
        $userNameStmt->execute();
        $account_username = (string)($userNameStmt->get_result()->fetch_assoc()['username'] ?? '');
        $userNameStmt->close();
        if (!empty($photo_db)) { $photo_url = '../image/' . $photo_db; $staff_photo = $photo_db; }

        // ── VALID SPECIFIC YEAR LEVELS ───────────────────────────────────
        // Same exact list & string values as admin/manage_privileged_accounts.php
        // uses for students and for faculty/staff assignment — keeping these
        // identical is what lets a staff member's picks match real students.
        $year_levels = [
            'Grade 7','Grade 8','Grade 9','Grade 10',
            'Grade 11','Grade 12',
            '1st Year College','2nd Year College','3rd Year College','4th Year College',
        ];

        // ── FETCH MY ASSIGNED TEACHING LEVEL(S) ─────────────────────────
        // A staff member can be assigned to more than one SPECIFIC year
        // level at once (e.g. Grade 8 AND Grade 10), so this is a list, not
        // a single value.
        $my_levels = [];
        $lvlQ = $mysqli->prepare("SELECT year_level FROM user_year_levels WHERE user_id=?");
        $lvlQ->bind_param("i", $user_id);
        $lvlQ->execute();
        $lvlRes = $lvlQ->get_result();
        while ($lr = $lvlRes->fetch_assoc()) $my_levels[] = $lr['year_level'];
        $lvlQ->close();

        // ── DERIVE TEACHING SCOPE (College vs JHS/SHS) ──────────────────
        // A Staff account that has been assigned to teach one or more year
        // levels (user_year_levels — set via admin/manage_privileged_accounts.php's
        // "Year Level(s) Responsible For / Teaching") is Teaching Staff. Which
        // supervisor(s) that Staff account evaluates depends on WHICH levels:
        // any College level ('1st Year College'..'4th Year College') means
        // they evaluate the Dean; any Grade level ('Grade 7'..'Grade 12', i.e.
        // JHS/SHS) means they evaluate the Principal; teaching both scopes at
        // once means they evaluate both the Dean and the Principal. Staff with
        // zero teaching assignments are non-teaching Staff and instead
        // evaluate the Executive Assistant. Teaching Staff (of any scope)
        // additionally get Peer Evaluation (evaluating fellow Teacher/Staff),
        // which non-teaching Staff do not. Enforced both in the render
        // (below) and again in the submit handlers, since those must never
        // trust page state alone.
        $staff_has_teaching_assignment = !empty($my_levels);
        $staff_teaches_college  = false;
        $staff_teaches_basic_ed = false; // JHS/SHS (Grade 7–12)
        foreach ($my_levels as $lvl) {
            if (stripos($lvl, 'College') !== false) $staff_teaches_college  = true;
            if (stripos($lvl, 'Grade') !== false)   $staff_teaches_basic_ed = true;
        }
        // Peer-to-Peer with the Faculty group is available to any Staff account
        // the EA has assigned at least one year level to (Teaching Staff).
        $staff_can_evaluate_faculty_peer = $staff_has_teaching_assignment;

        // Human-readable summary of who this Staff account can currently
        // evaluate, used on the Role & Designation page.
        $staff_can_evaluate_parts = [];
        if ($staff_teaches_college)  $staff_can_evaluate_parts[] = 'Dean';
        if ($staff_teaches_basic_ed) $staff_can_evaluate_parts[] = 'Principal';
        if ($staff_has_teaching_assignment) $staff_can_evaluate_parts[] = 'Fellow Teacher/Staff (Peer-to-Peer Evaluation)';
        if (empty($staff_can_evaluate_parts)) $staff_can_evaluate_parts[] = 'Executive Assistant';
        $staff_can_evaluate_label = implode(', ', $staff_can_evaluate_parts);

        // ── STAFF EVALUATION "HOME" PAGE ─────────────────────────────
        // Non-teaching Staff evaluate the EA under the dedicated Staff
        // Evaluation page/nav item. Teaching Staff no longer get a separate
        // Staff Evaluation nav item — their Dean/Principal evaluation is
        // folded into the Peer Evaluation page instead (rendered above the
        // Teacher/Staff designation picker), so every link/redirect that
        // used to point at 'staff_eval' for them now points at 'peer'.
        $staff_eval_home_page = $staff_has_teaching_assignment ? 'peer' : 'staff_eval';
        $staff_eval_feature_label = $staff_has_teaching_assignment ? 'Evaluation' : 'Staff Evaluation';

        // Teaching Staff no longer have a standalone Staff Evaluation landing
        // page — Dean/Principal evaluation now lives inside Peer Evaluation's
        // Step 1 designation picker. Send old bookmarks/links straight there
        // instead of showing an interstitial "this moved" screen.
        if ($staff_has_teaching_assignment && $page === 'staff_eval') {
            header('Location: staff_dashboard.php?page=peer'); exit;
        }

        // ── AUTO-CREATE notifications table ──────────────────────────
        $mysqli->query("CREATE TABLE IF NOT EXISTS notifications (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            type VARCHAR(50) NOT NULL DEFAULT 'designation_update',
            user_id INT UNSIGNED NOT NULL,
            message TEXT NOT NULL,
            extra_data TEXT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_unread (is_read, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // ── AUTO-CREATE role_change_log table ────────────────────────
        // This is what powers the admin dashboard's "System Audits" box and bell.
        $mysqli->query("CREATE TABLE IF NOT EXISTS role_change_log (
            id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id          INT NOT NULL,
            old_role         VARCHAR(60)  NOT NULL DEFAULT '',
            new_role         VARCHAR(60)  NOT NULL DEFAULT '',
            old_designation  VARCHAR(120) NULL,
            new_designation  VARCHAR(120) NULL,
            changed_at       DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_changed (changed_at),
            INDEX idx_user    (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // ── AUTO-CREATE user_preferences table ───────────────────────
        $mysqli->query("CREATE TABLE IF NOT EXISTS user_preferences (
            user_id INT UNSIGNED PRIMARY KEY,
            email_on_designation_update TINYINT(1) NOT NULL DEFAULT 1,
            email_on_new_evaluation     TINYINT(1) NOT NULL DEFAULT 1,
            show_result_details         TINYINT(1) NOT NULL DEFAULT 1,
            compact_dashboard           TINYINT(1) NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Keep preferences compatible with older installations.
        $mysqli->query("ALTER TABLE user_preferences ADD COLUMN IF NOT EXISTS show_result_details TINYINT(1) NOT NULL DEFAULT 1");
        $mysqli->query("ALTER TABLE user_preferences ADD COLUMN IF NOT EXISTS compact_dashboard TINYINT(1) NOT NULL DEFAULT 0");
$mysqli->query("ALTER TABLE user_preferences ADD COLUMN IF NOT EXISTS notify_evaluation_schedule TINYINT(1) NOT NULL DEFAULT 1");
$mysqli->query("ALTER TABLE user_preferences ADD COLUMN IF NOT EXISTS notify_teaching_assignment TINYINT(1) NOT NULL DEFAULT 1");
$mysqli->query("ALTER TABLE user_preferences ADD COLUMN IF NOT EXISTS notify_academic_period TINYINT(1) NOT NULL DEFAULT 1");
        $prefStmt = $mysqli->prepare("SELECT * FROM user_preferences WHERE user_id=? LIMIT 1");
        $prefStmt->bind_param('i',$user_id); $prefStmt->execute();
        $user_prefs = array_merge(['show_result_details'=>1,'compact_dashboard'=>0,'notify_evaluation_schedule'=>1,'notify_teaching_assignment'=>1,'notify_academic_period'=>1], $prefStmt->get_result()->fetch_assoc() ?: []);
function notif_muted_types_sql(array $prefs): string {
    $map = ['notify_evaluation_schedule'=>'evaluation_schedule','notify_teaching_assignment'=>'teaching_assignment','notify_academic_period'=>'academic_period'];
    $m = [];
    foreach ($map as $key => $type) { if (isset($prefs[$key]) && (int)$prefs[$key] === 0) $m[] = "'" . $type . "'"; }
    return $m ? ' AND type NOT IN (' . implode(',', $m) . ')' : '';
}

        $prefStmt->close();

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_preferences'])) {
            if (!csrf_check()) { $_SESSION['toast_error']='Your session expired. Please try again.'; header('Location: staff_dashboard.php?page=settings'); exit; }
            $n1 = isset($_POST['notify_evaluation_schedule']) ? 1 : 0; $n2 = isset($_POST['notify_teaching_assignment']) ? 1 : 0; $n3 = isset($_POST['notify_academic_period']) ? 1 : 0;
$up = $mysqli->prepare("INSERT INTO user_preferences (user_id,notify_evaluation_schedule,notify_teaching_assignment,notify_academic_period) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE notify_evaluation_schedule=VALUES(notify_evaluation_schedule),notify_teaching_assignment=VALUES(notify_teaching_assignment),notify_academic_period=VALUES(notify_academic_period)");
$up->bind_param('iiii', $user_id, $n1, $n2, $n3); $up->execute(); $up->close();
            $_SESSION['toast']='Settings saved successfully.'; header('Location: staff_dashboard.php?page=settings'); exit;
        }

        // ── UPDATE ACCOUNT DETAILS FROM SETTINGS ─────────────────────
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_account_name'])) {
            if (!csrf_check()) {
                $_SESSION['toast_error'] = 'Your session expired. Please try again.';
                header('Location: staff_dashboard.php?page=settings'); exit;
            }
            $new_name = trim(preg_replace('/\s+/u', ' ', (string)($_POST['full_name'] ?? '')));
            $new_username = trim((string)($_POST['username'] ?? $account_username));
            $new_desig = trim((string)($_POST['designation'] ?? $designation));
            $profile_error = '';
            if ($new_name === '') $profile_error = 'Full name cannot be empty.';
            elseif (mb_strlen($new_name) > 150) $profile_error = 'Full name must be 150 characters or fewer.';
            elseif (preg_match('/[\x00-\x1F\x7F<>]/u', $new_name)) $profile_error = 'Full name contains invalid characters.';
            elseif ($new_username === '') $profile_error = 'Username cannot be empty.';
            elseif (mb_strlen($new_username) > 50) $profile_error = 'Username must be 50 characters or fewer.';
            elseif (preg_match('/\s/', $new_username)) $profile_error = 'Username cannot contain spaces.';
            elseif ($new_desig === '') $profile_error = 'Designation cannot be empty.';
            elseif (mb_strlen($new_desig) > 100) $profile_error = 'Designation must be 100 characters or fewer.';

            if ($profile_error !== '') {
                $_SESSION['toast_error'] = $profile_error;
            } else {
                $checkName = $mysqli->prepare('SELECT id FROM users WHERE username=? AND id<>? LIMIT 1');
                $checkName->bind_param('si', $new_username, $user_id);
                $checkName->execute();
                $duplicateUsername = (bool)$checkName->get_result()->fetch_assoc();
                $checkName->close();
                if ($duplicateUsername) {
                    $_SESSION['toast_error'] = 'That username is already in use.';
                } else {
                    $oldDesignation = (string)$designation;
                    try {
                        $updateProfile = $mysqli->prepare('UPDATE users SET full_name=?, username=?, designation=? WHERE id=?');
                        $updateProfile->bind_param('sssi', $new_name, $new_username, $new_desig, $user_id);
                        $ok = $updateProfile->execute();
                        $updateProfile->close();
                        if (!$ok) {
                            $_SESSION['toast_error'] = 'Could not update your profile. Please try again.';
                        } else {
                            $_SESSION['full_name'] = $new_name;
                            $_SESSION['username'] = $new_username;
                            $_SESSION['designation'] = $new_desig;
                            $full_name = $new_name; $account_username = $new_username; $designation = $new_desig;
                            if ($new_desig !== $oldDesignation) {
                                try {
                                    $audit = $mysqli->prepare("INSERT INTO role_change_log (user_id, old_role, new_role, old_designation, new_designation) VALUES (?, 'staff', 'staff', ?, ?)");
                                    $audit->bind_param('iss', $user_id, $oldDesignation, $new_desig);
                                    $audit->execute(); $audit->close();
                                } catch (Throwable $auditError) { error_log('[staff settings] Could not record designation update: ' . $auditError->getMessage()); }
                            }
                            $_SESSION['toast'] = 'Profile details updated successfully.';
                        }
                    } catch (mysqli_sql_exception $e) {
                        error_log('[staff settings] Profile update failed for user ' . $user_id . ': ' . $e->getMessage());
                        $_SESSION['toast_error'] = 'Unable to update your profile right now. Please try again.';
                    }
                }
            }
            header('Location: staff_dashboard.php?page=settings'); exit;
        }

// ── UPDATE PASSWORD FROM ACCOUNT SETTINGS ─────────────────────
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_password'])) {
            if (!csrf_check()) {
                $_SESSION['toast_error'] = 'Your session expired. Please try again.';
            } else {
                $currentPassword = (string)($_POST['current_password'] ?? '');
                $newPassword = (string)($_POST['new_password'] ?? '');
                $confirmPassword = (string)($_POST['confirm_password'] ?? '');
                $pw = $mysqli->prepare("SELECT password_hash FROM users WHERE id=? LIMIT 1");
                $pw->bind_param('i', $user_id);
                $pw->execute();
                $pwRow = $pw->get_result()->fetch_assoc();
                $pw->close();
                if (!$pwRow || !password_verify($currentPassword, (string)$pwRow['password_hash'])) {
                    $_SESSION['toast_error'] = 'Your current password is incorrect.';
                } elseif (strlen($newPassword) < 8) {
                    $_SESSION['toast_error'] = 'New password must be at least 8 characters.';
                } elseif ($newPassword !== $confirmPassword) {
                    $_SESSION['toast_error'] = 'New password and confirmation do not match.';
                } elseif (password_verify($newPassword, (string)$pwRow['password_hash'])) {
                    $_SESSION['toast_error'] = 'Your new password must be different from your current password.';
                } else {
                    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $pwUpdate = $mysqli->prepare("UPDATE users SET password_hash=? WHERE id=?");
                    $pwUpdate->bind_param('si', $newHash, $user_id);
                    if ($pwUpdate->execute()) $_SESSION['toast'] = 'Password changed successfully.';
                    else $_SESSION['toast_error'] = 'Unable to update your password right now. Please try again.';
                    $pwUpdate->close();
                }
            }
            header('Location: staff_dashboard.php?page=settings'); exit;
        }

// ── UPDATE MY DESIGNATION ─────────────────────────────────────
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_designation'])) {
            if (!csrf_check()) {
                $_SESSION['toast_error'] = "Your session expired or the request could not be verified. Please try again.";
                header("Location: staff_dashboard.php?page=profile"); exit;
            }
            $new_desig = trim($_POST['new_designation'] ?? '');
            $old_desig = $designation;

            if (empty($new_desig)) {
                $_SESSION['toast_error'] = "Designation cannot be empty.";
            } elseif (preg_replace('/\s+/', ' ', mb_strtolower($new_desig)) === preg_replace('/\s+/', ' ', mb_strtolower(trim((string)$old_desig)))) {
                $_SESSION['toast_error'] = "That is already your current designation.";
            } else {
                $stmt = $mysqli->prepare("UPDATE users SET designation=? WHERE id=?");
                $stmt->bind_param("si", $new_desig, $user_id);
                $stmt->execute(); $stmt->close();
                $designation = $new_desig;
                $_SESSION['designation'] = $new_desig;

                // No self-notification: the person just made this change themselves.
                // The admin is still informed through role_change_log below.

                // Also log to role_change_log so it shows up in the admin dashboard's
                // System Audits box and notification bell.
                $rstmt = $mysqli->prepare("INSERT INTO role_change_log (user_id, old_role, new_role, old_designation, new_designation) VALUES (?, 'staff', 'staff', ?, ?)");
                $rstmt->bind_param("iss", $user_id, $old_desig, $new_desig);
                $rstmt->execute(); $rstmt->close();

                $_SESSION['toast'] = "Your designation has been updated to \"$new_desig\" and the admin has been notified.";
            }
            header("Location: staff_dashboard.php?page=profile"); exit;
        }

        // ── UPDATE TEACHING LEVEL(S) ──────────────────────────────────
        // Multi-select — a staff member can be assigned to more than one
        // specific year level at once (e.g. teaches both Grade 8 and Grade 10).
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_levels'])) {
            if (!csrf_check()) {
                $_SESSION['toast_error'] = "Your session expired or the request could not be verified. Please try again.";
                header("Location: staff_dashboard.php?page=profile"); exit;
            }
            $selected = array_values(array_intersect($_POST['levels'] ?? [], $year_levels));

            $mysqli->begin_transaction();
            try {
                $del = $mysqli->prepare("DELETE FROM user_year_levels WHERE user_id=?");
                $del->bind_param("i", $user_id);
                $del->execute();
                $del->close();

                if (!empty($selected)) {
                    $ins = $mysqli->prepare("INSERT INTO user_year_levels (user_id, year_level) VALUES (?, ?)");
                    foreach ($selected as $lvl) {
                        $ins->bind_param("is", $user_id, $lvl);
                        $ins->execute();
                    }
                    $ins->close();
                }
                $mysqli->commit();

                $_SESSION['toast'] = !empty($selected)
                    ? "Your teaching level(s) updated to: " . implode(', ', $selected) . "."
                    : "Your teaching level assignment has been cleared. Students won't be able to evaluate you until a level is set.";
            } catch (Exception $e) {
                $mysqli->rollback();
                error_log('[staff_dashboard] update_levels failed for user_id=' . $user_id . ': ' . $e->getMessage());
                $_SESSION['toast_error'] = "Failed to update teaching levels. Please try again.";
            }
            header("Location: staff_dashboard.php?page=profile"); exit;
        }

        // ── CHANGE PASSWORD ─────────────────────────────────────────────
        // ── MARK NOTIFICATIONS READ ──────────────────────────────────
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_notifications_read'])) {
            if (!csrf_check()) {
                header("Location: staff_dashboard.php?page=" . urlencode($page)); exit;
            }
            $mr = $mysqli->prepare("UPDATE notifications SET is_read=1 WHERE user_id=? AND is_read=0");
            $mr->bind_param("i", $user_id);
            $mr->execute(); $mr->close();
            header("Location: staff_dashboard.php?page=" . urlencode($page)); exit;
        }

        // ── ACTIVE PERIOD ─────────────────────────────────────────────
        $period = null;
        $pr = $mysqli->query("SELECT * FROM evaluation_periods WHERE is_active=1" . pbi_current_period_tail($mysqli) . " LIMIT 1");
        if ($pr) $period = $pr->fetch_assoc();

        // Faculty peer submissions must use a Higher Education term, since the
        // Dean report excludes School Year periods. Prefer an active HE term,
        // otherwise use the newest semester/summer row, without changing the
        // active period used for the rest of the Staff dashboard.
        $college_peer_period = null;
        $cpp = $mysqli->query("SELECT * FROM evaluation_periods
            WHERE semester IN ('1st Semester','2nd Semester','Summer')" . pbi_not_archived_sql($mysqli) . "
            ORDER BY
                (CONVERT(TRIM(school_year) USING utf8mb4) COLLATE utf8mb4_unicode_ci =
                    CONVERT(TRIM((SELECT setting_value FROM system_settings WHERE setting_key='acad_year' LIMIT 1)) USING utf8mb4) COLLATE utf8mb4_unicode_ci) DESC,
                (CONVERT(TRIM(semester) USING utf8mb4) COLLATE utf8mb4_unicode_ci =
                    CONVERT(TRIM((SELECT setting_value FROM system_settings WHERE setting_key='acad_term' LIMIT 1)) USING utf8mb4) COLLATE utf8mb4_unicode_ci) DESC,
                is_active DESC,
                id DESC LIMIT 1");
        if ($cpp) $college_peer_period = $cpp->fetch_assoc();

        // Do not use evaluation_periods.is_active as the schedule clock.
        // Follow Schedule must remain closed until the exact Asia/Manila
        // opening instant, and must close immediately at the exact end.
        $evaluation_open = staff_schedule_is_open($mysqli);

        // ── DASHBOARD SCHEDULE SUMMARY ─────────────────────────────────
        // Use the same database-backed settings as the admin Settings page.
        $dashboard_sys = ss_raw($mysqli);
        $dashboard_structure_labels = ss_structure_labels();
        $dashboard_structure = $dashboard_structure_labels[$dashboard_sys['acad_structure'] ?? ''] ?? ucfirst((string)($dashboard_sys['acad_structure'] ?? ''));
        $dashboard_acad_year = trim((string)($dashboard_sys['acad_year'] ?? ($period['school_year'] ?? '')));
        $dashboard_term = trim((string)($dashboard_sys['acad_term'] ?? ($period['semester'] ?? '')));
        $dashboard_start_raw = trim((string)($dashboard_sys['eval_start'] ?? ''));
        $dashboard_end_raw = trim((string)($dashboard_sys['eval_end'] ?? ''));
        $dashboard_start = $dashboard_start_raw !== '' ? ss_parse_datetime($dashboard_start_raw) : null;
        $dashboard_end = $dashboard_end_raw !== '' ? ss_parse_datetime($dashboard_end_raw) : null;
        $dashboard_tz = new DateTimeZone((string)($dashboard_sys['schedule_timezone'] ?? 'Asia/Manila'));
        $dashboard_status = 'Scheduled';
        if (($dashboard_sys['control_mode'] ?? 'schedule') === 'open') $dashboard_status = 'Open';
        elseif (($dashboard_sys['control_mode'] ?? 'schedule') === 'closed') $dashboard_status = 'Closed';
        elseif ($dashboard_start && $dashboard_end) {
            $dashboard_now = new DateTimeImmutable('now', $dashboard_tz);
            if ($dashboard_now >= $dashboard_end) $dashboard_status = 'Closed';
            elseif ($dashboard_now >= $dashboard_start) $dashboard_status = 'Open';
        }
        $dashboard_window = ($dashboard_start && $dashboard_end) ? $dashboard_start->format('M j, Y') . ' – ' . $dashboard_end->format('M j, Y') : 'Not configured';
        $dashboard_open_display = $dashboard_start ? $dashboard_start->format('M j, Y g:i A') : 'Not configured';
        $dashboard_close_display = $dashboard_end ? $dashboard_end->format('M j, Y g:i A') : 'Not configured';
        $dashboard_status_class = strtolower($dashboard_status);

        // ── STAFF EVALUATION QUESTIONNAIRE ─────────────────────────────
        // Non-teaching Staff evaluate the Executive Assistant. Teaching Staff
        // instead evaluate the Dean and/or Principal, chosen by which
        // level(s) they teach (see $staff_teaches_college / $staff_teaches_basic_ed
        // above) — never the EA. The Questionnaire feature is the single
        // source of truth: questions are read directly from evaluation_questions
        // using eval_type='staff' and the selected target_type (Dean / Principal / EA).
        $staff_eval_targets = [];

        if ($staff_has_teaching_assignment) {
            $wanted_roles = [];
            if ($staff_teaches_college)  $wanted_roles[] = 'dean';
            if ($staff_teaches_basic_ed) $wanted_roles[] = 'principal';

            if (!empty($wanted_roles)) {
                $rolesInClause = "'" . implode("','", array_map(fn($r) => $mysqli->real_escape_string($r), $wanted_roles)) . "'";
                $dnprStmt = $mysqli->query("
                    SELECT id, full_name, designation, photo, role
                    FROM users
                    WHERE role IN ($rolesInClause)
                      AND is_active=1
                      AND account_status='approved'
                    ORDER BY FIELD(role,'dean','principal'), full_name ASC
                ");
                if ($dnprStmt) {
                    while ($row = $dnprStmt->fetch_assoc()) {
                        $staff_eval_targets[] = [
                            'id' => (int)$row['id'],
                            'full_name' => $row['full_name'],
                            'designation' => $row['designation'] ?? '',
                            'photo' => $row['photo'] ?? '',
                            'target_type' => strtolower($row['role']) === 'dean' ? 'Dean' : 'Principal',
                            'target_label' => ucfirst(strtolower($row['role']))
                        ];
                    }
                    $dnprStmt->free();
                }
            }
        } else {
            $ea_candidates = ea_get_executive_assistants($mysqli, (int)($period['id'] ?? 0));
            foreach ($ea_candidates as $ea) {
                $staff_eval_targets[] = [
                    'id' => (int)$ea['id'],
                    'full_name' => $ea['full_name'] ?? 'Executive Assistant',
                    'designation' => $ea['position'] ?? ($ea['designation'] ?? ''),
                    'photo' => $ea['photo'] ?? '',
                    'target_type' => 'EA',
                    'target_label' => 'Executive Assistant'
                ];
            }
        }

        $staff_eval_target = null;
        if (isset($_GET['tid'])) {
            $requestedStaffTargetId = (int)$_GET['tid'];
            foreach ($staff_eval_targets as $candidate) {
                if ((int)$candidate['id'] === $requestedStaffTargetId) {
                    $staff_eval_target = $candidate;
                    break;
                }
            }
        }
        if (!$staff_eval_target && !empty($staff_eval_targets)) {
            $staff_eval_target = $staff_eval_targets[0];
        }

        $staff_eval_questions = [];
        $staff_eval_categories = [];
        $staff_eval_already_done = false;

        if (in_array($page, ['staff_eval','staff_eval_form'], true) && $staff_eval_target) {
            $q = $mysqli->prepare("
                SELECT id, category, question_text
                FROM user_questions
                WHERE user_id=?
                  AND target_type=?
                  AND eval_type='general'
                ORDER BY category ASC, id ASC
            ");
            $q->bind_param('is', $staff_eval_target['id'], $staff_eval_target['target_type']);
            $q->execute();
            $staff_eval_questions = $q->get_result()->fetch_all(MYSQLI_ASSOC);
            $q->close();

            foreach ($staff_eval_questions as $qrow) {
                $cat = trim($qrow['category'] ?? '') ?: 'General';
                $staff_eval_categories[$cat][] = $qrow;
            }

            if ($period) {
                $d = $mysqli->prepare("
                    SELECT id
                    FROM evaluation_tracker
                    WHERE evaluator_id=?
                      AND target_user_id=?
                      AND period_id=?
                      AND eval_type='staff'
                      AND status='submitted'
                    LIMIT 1
                ");
                $d->bind_param('iii', $user_id, $staff_eval_target['id'], $period['id']);
                $d->execute();
                $staff_eval_already_done = (bool)$d->get_result()->fetch_assoc();
                $d->close();
            }
        }

        // ── MY EVALUATION RESULTS ─────────────────────────────────────
        // Reads from evaluation_tracker + questionnaire_answers (student evaluations of this staff)
        $my_avg    = null;
        $my_total  = 0;
        $my_scores = [];

        $live_sum = 0.0; $live_cnt = 0; $live_total = 0;
        $res_stmt = $mysqli->prepare("
            SELECT AVG(qa.answer_score) as avg_score, COUNT(DISTINCT et.id) as total,
                   SUM(qa.answer_score) AS sum_score, COUNT(qa.answer_score) AS cnt_score
            FROM evaluation_tracker et
            JOIN questionnaire_answers qa ON qa.tracker_id = et.id
            WHERE et.target_user_id = ?
        ");
        $res_stmt->bind_param("i", $user_id);
        $res_stmt->execute();
        $res = $res_stmt->get_result();
        if ($res) {
            $row      = $res->fetch_assoc();
            $my_avg   = $row['avg_score'] !== null ? round($row['avg_score'], 2) : null;
            $my_total = $row['total'] ?? 0;
            $live_sum   = (float)($row['sum_score'] ?? 0);
            $live_cnt   = (int)($row['cnt_score'] ?? 0);
            $live_total = (int)($row['total'] ?? 0);
        }
        $res_stmt->close();

        $cat_stmt = $mysqli->prepare("
            SELECT COALESCE(uq.category, eq.category, 'General') AS category,
                   AVG(qa.answer_score) AS avg_cat,
                   SUM(qa.answer_score) AS sum_cat, COUNT(qa.answer_score) AS cnt_cat
            FROM questionnaire_answers qa
            JOIN evaluation_tracker et ON et.id = qa.tracker_id
            LEFT JOIN user_questions uq
              ON qa.question_source='user'
             AND uq.id = COALESCE(qa.user_question_id, qa.question_id)
            LEFT JOIN evaluation_questions eq
              ON qa.question_source='evaluation'
             AND eq.id = qa.question_id
            WHERE et.target_user_id = ?
            GROUP BY COALESCE(uq.category, eq.category, 'General')
        ");
        $cat_stmt->bind_param("i", $user_id);
        $cat_stmt->execute();
        $cat_res = $cat_stmt->get_result();
        if ($cat_res) $my_scores = $cat_res->fetch_all(MYSQLI_ASSOC);
        $cat_stmt->close();

        // Add feedback that was archived by the EA, so received evaluations never disappear.
        $kept_combined = kept_feedback_combine($live_sum, $live_cnt, $live_total, $my_scores, kept_feedback_aggregate($mysqli, (int)$user_id));
        $my_avg    = $kept_combined['avg'];
        $my_total  = $kept_combined['total'];
        $my_scores = $kept_combined['scores'];

        // Performance Level uses the same bands and wording as the Rating Legend on the reports
        // (same as the Dean dashboard). No ratings yet -> "No Ratings Yet".
        if ($my_avg === null)       { $perf_label = 'No Ratings Yet';                     $perf_color = '#6b7280'; }
        elseif ($my_avg >= 4.5)     { $perf_label = 'Very Satisfied';                     $perf_color = '#32B98A'; }
        elseif ($my_avg >= 3.5)     { $perf_label = 'Satisfied';                          $perf_color = '#6BCFA9'; }
        elseif ($my_avg >= 2.5)     { $perf_label = 'Neither Satisfied nor Dissatisfied'; $perf_color = '#facc15'; }
        elseif ($my_avg >= 1.5)     { $perf_label = 'Dissatisfied';                       $perf_color = '#fb923c'; }
        else                        { $perf_label = 'Very Dissatisfied';                  $perf_color = '#E6788A'; }
        $perf_long = strlen($perf_label) > 20;   // the long wording needs a smaller font to fit the card

        // ── PEER EVALUATION GROUPING (same rules as Faculty dashboard) ───
        // Grouping follows the account role plus the EA's year-level assignment
        // (user_year_levels, set in Account Management → Assign Year Levels).
        // It is NOT derived from designation, sector, secondary role, or
        // teaching_assignments:
        //   • role teacher/faculty                   -> Faculty group
        //   • role staff WITH  >= 1 year-level row   -> Faculty group (Teaching Staff)
        //   • role staff WITHOUT any year-level row  -> Staff group
        // Checked live, so a Staff account switches groups (and which
        // questionnaire evaluates them) as soon as the EA assigns or clears
        // a year level.
        function peer_has_teaching_assignment(mysqli $mysqli, array $u): bool {
            $uid = (int)($u['id'] ?? 0);
            if ($uid <= 0) return false;
            $stmt = $mysqli->prepare('SELECT 1 FROM user_year_levels WHERE user_id=? LIMIT 1');
            if (!$stmt) return false;
            $stmt->bind_param('i', $uid);
            $stmt->execute();
            $found = (bool)$stmt->get_result()->fetch_assoc();
            $stmt->close();
            return $found;
        }
        function resolve_peer_group(mysqli $mysqli, array $u): ?string {
            $role = strtolower(trim((string)($u['role'] ?? '')));
            if (in_array($role, ['teacher','faculty'], true)) return 'teacher';
            if ($role === 'staff') return peer_has_teaching_assignment($mysqli, $u) ? 'teacher' : 'staff';
            return null;
        }

        // Show Teaching Staff clearly inside the Faculty bucket while leaving
        // their stored role/designation untouched.
        function peer_display_label(mysqli $mysqli, array $p, string $peer_group, array $peer_group_labels): string {
            $role = strtolower(trim($p['role'] ?? ''));
            if ($peer_group === 'teacher' && $role === 'staff') {
                $lvlQ = $mysqli->prepare("SELECT year_level FROM user_year_levels WHERE user_id=? ORDER BY year_level ASC");
                $pid = (int)$p['id'];
                $lvlQ->bind_param("i", $pid);
                $lvlQ->execute();
                $lvlRes = $lvlQ->get_result();
                $levels = [];
                while ($lr = $lvlRes->fetch_assoc()) $levels[] = $lr['year_level'];
                $lvlQ->close();
                return $levels ? 'Teaching Staff · ' . implode(', ', $levels) : 'Teaching Staff';
            }
            return $p['designation'] ?: ($peer_group_labels[$peer_group] ?? '');
        }
        $peer_group_labels = ['teacher' => 'Faculty', 'staff' => 'Staff', 'dean' => 'Dean', 'principal' => 'Principal'];

        // ── ADD peer_group COLUMN TO evaluation_tracker (idempotent) ──
        // Stores which of the two designation groups (Teacher/Staff) the
        // evaluator picked in Step 1, alongside the existing tracker row.
        $colChk = $mysqli->query("SHOW COLUMNS FROM evaluation_tracker LIKE 'peer_group'");
        if ($colChk && $colChk->num_rows === 0) {
            $mysqli->query("ALTER TABLE evaluation_tracker ADD COLUMN peer_group VARCHAR(20) NULL AFTER eval_type");
        }

        // ── ENSURE A UNIQUE CONSTRAINT BACKS THE DUPLICATE-EVAL CHECK ──
        // The application-level duplicate check below (SELECT ... then INSERT)
        // has a race window between two near-simultaneous submissions. This
        // unique index makes the DB itself the source of truth: a second insert
        // for the same (evaluator, target, eval_type, period) will fail with a
        // duplicate-key error instead of silently creating a second row.
        $idxChk = $mysqli->query("SHOW INDEX FROM evaluation_tracker WHERE Key_name = 'uniq_eval_submission'");
        if ($idxChk && $idxChk->num_rows === 0) {
            $mysqli->query("ALTER TABLE evaluation_tracker ADD UNIQUE INDEX uniq_eval_submission (evaluator_id, target_user_id, eval_type, period_id)");
        }

        // ── REPAIR LEGACY STAFF-EVALUATION TRACKER ROWS ───────────────
        // Older Staff Evaluation submissions were written with the target
        // type (Dean/Principal/EA) in evaluation_tracker.eval_type instead
        // of the dedicated 'staff' evaluation type. Normalize those rows so
        // the Staff Evaluation report and completion checks can see them.
        // Do not touch a row if a correct 'staff' row already exists for the
        // same evaluator/target/period, avoiding a unique-key collision.
        $mysqli->query("
            UPDATE evaluation_tracker bad
            LEFT JOIN evaluation_tracker good
              ON good.evaluator_id = bad.evaluator_id
             AND good.target_user_id = bad.target_user_id
             AND good.period_id = bad.period_id
             AND good.eval_type = 'staff'
            SET bad.eval_type = 'staff'
            WHERE bad.peer_group = 'Staff Evaluation'
              AND bad.eval_type IN ('Dean','Principal','EA')
              AND good.id IS NULL
        ");

        // ── SUBMIT STAFF EVALUATION ────────────────────────────────────
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_staff_evaluation'])) {
            if (!csrf_check()) {
                $_SESSION['toast_error'] = "Your session expired or the request could not be verified. Please try again.";
                header("Location: staff_dashboard.php?page=$staff_eval_home_page"); exit;
            }

            // No teaching-assignment gate here: $staff_eval_targets was just
            // freshly re-derived above from $my_levels/$staff_teaches_college/
            // $staff_teaches_basic_ed on THIS request, so the foreach lookup
            // below already rejects any target_id that isn't currently valid
            // for this Staff account (EA for non-teaching, Dean/Principal
            // matching their current teaching scope) — no separate re-check
            // is needed.

            $tid = (int)($_POST['target_id'] ?? 0);
            $target = null;
            foreach ($staff_eval_targets as $candidate) {
                if ((int)$candidate['id'] === $tid) {
                    $target = $candidate;
                    break;
                }
            }

            $ratings = $_POST['ratings'] ?? [];
            $comments = trim($_POST['comments'] ?? '');
            $errors = [];

            if (!staff_schedule_is_open($mysqli)) {
                $errors[] = "Evaluation is currently closed. Please return at the scheduled opening time.";
            } elseif (!$target) {
                $errors[] = "The {$staff_eval_feature_label} target is not currently available.";
            } else {
                // Use the live active period instead of trusting a stale page.
                $currentPeriodResult = $mysqli->query("SELECT * FROM evaluation_periods WHERE is_active=1" . pbi_current_period_tail($mysqli) . " LIMIT 1");
                $currentPeriod = $currentPeriodResult ? $currentPeriodResult->fetch_assoc() : null;
                if (!$currentPeriod) {
                    $errors[] = "No evaluation period is currently open.";
                } else {
                    $period = $currentPeriod;
                $validStmt = $mysqli->prepare("
                    SELECT id
                    FROM user_questions
                    WHERE user_id=?
                      AND eval_type='general'
                      AND target_type=?
                ");
                $validStmt->bind_param('is', $target['id'], $target['target_type']);
                $validStmt->execute();
                $validRes = $validStmt->get_result();
                $valid_question_ids = [];
                while ($vq = $validRes->fetch_assoc()) $valid_question_ids[] = (int)$vq['id'];
                $validStmt->close();

                $ratings = array_filter($ratings, function($val, $qid) use ($valid_question_ids) {
                    return in_array((int)$qid, $valid_question_ids, true);
                }, ARRAY_FILTER_USE_BOTH);

                if (empty($valid_question_ids) || count($ratings) !== count($valid_question_ids)) {
                    $errors[] = 'Please rate all questions before submitting.';
                } else {
                    foreach ($ratings as $qid => $score) {
                        $score = (int)$score;
                        if ($score < 1 || $score > 5) {
                            $errors[] = 'Please use a rating from 1 to 5 for every question.';
                            break;
                        }
                    }
                }

                // A written comment is mandatory (whitespace-only doesn't count).
                if (!$errors && $comments === '') {
                    $errors[] = 'Please write a comment before submitting. Comments and suggestions are required.';
                }

                if (!$errors && in_array($target['target_type'], ['Dean','Principal','EA'], true)) {
                    $dup = $mysqli->prepare("
                        SELECT id
                        FROM evaluation_tracker
                        WHERE evaluator_id=?
                          AND target_user_id=?
                          AND period_id=?
                          AND eval_type='staff'
                        LIMIT 1
                    ");
                    $dup->bind_param('iii', $user_id, $tid, $period['id']);
                    $dup->execute();
                    $already = (bool)$dup->get_result()->fetch_assoc();
                    $dup->close();
                    if ($already) $errors[] = 'You have already evaluated this person for this period.';
                }
                }
            }

            if (!$errors) {
                $overallScore = round(array_sum($ratings) / count($ratings), 2);
                $periodId = (int)$period['id'];

                // Use a real active form id when available for tracker compatibility.
                $formId = 0;
                $formLookup = $mysqli->query("SELECT id FROM questionnaire_forms WHERE is_active=1 ORDER BY id DESC LIMIT 1");
                if ($formLookup && ($fr = $formLookup->fetch_assoc())) $formId = (int)$fr['id'];
                if ($formLookup) $formLookup->free();

                $mysqli->begin_transaction();
                try {
                    $trk = $mysqli->prepare("
                        INSERT INTO evaluation_tracker
                        (evaluator_id,target_user_id,form_id,period_id,eval_type,peer_group,score,remarks,status,submitted_at)
                        VALUES (?,?,?,?,?,?,?,?,'submitted',NOW())
                    ");
                    $peerGroupLabel = 'Staff Evaluation';
                    $evalType = 'staff';
                    $trk->bind_param('iiiissds', $user_id, $tid, $formId, $periodId, $evalType, $peerGroupLabel, $overallScore, $comments);
                    $trk->execute();
                    $trackerId = $mysqli->insert_id;
                    $trk->close();

                    $ans = $mysqli->prepare("
                        INSERT INTO questionnaire_answers
                        (tracker_id,question_id,question_source,user_question_id,answer_score,submitted_at)
                        VALUES (?,NULL,'user',?,?,NOW())
                    ");
                    foreach ($ratings as $qid => $rating) {
                        $qid = (int)$qid;
                        $score = min(5, max(1, (int)$rating));
                        $ans->bind_param('iii', $trackerId, $qid, $score);
                        $ans->execute();
                    }
                    $ans->close();

                    $mysqli->commit();

                    $targetLabel = $target['target_label'];
                    $notifMsg = NOTIF_EVAL_RECEIVED_MSG; // anonymous
                    $nins = $mysqli->prepare("INSERT INTO notifications (type, user_id, message, extra_data) VALUES ('evaluation_received', ?, ?, ?)");
                    $extra = json_encode([
                        'evaluation_type' => 'staff',
                        'target_type' => $target['target_type'],
                        'target_label' => $targetLabel
                    ]);
                    $nins->bind_param('iss', $tid, $notifMsg, $extra);
                    $nins->execute();
                    $nins->close();

                    $_SESSION['toast'] = "{$staff_eval_feature_label} for {$target['target_label']} submitted successfully.";
                    header("Location: staff_dashboard.php?page=$staff_eval_home_page"); exit;
                } catch (Throwable $e) {
                    $mysqli->rollback();
                    error_log('[staff_dashboard] submit staff evaluation failed for evaluator=' . $user_id . ' target=' . $tid . ': ' . $e->getMessage());
                    $_SESSION['toast_error'] = "Unable to submit the {$staff_eval_feature_label}. Please try again.";
                    header('Location: staff_dashboard.php?page=staff_eval_form&tid=' . $tid); exit;
                }
            }

            $_SESSION['toast_error'] = implode(' ', $errors);
            header('Location: staff_dashboard.php?page=staff_eval_form&tid=' . $tid); exit;
        }

        // ── PEER EVALUATION ───────────────────────────────────────────
        // 'dean' and 'principal' are Peer Evaluation designations too now
        // (folded in from the old Staff Evaluation page) — they use
        // $staff_eval_targets (already scoped to this Staff account's
        // teaching level) instead of the teacher/staff peers query, and
        // their "done" state comes from eval_type='staff' (the existing
        // Dean/Principal tracker rows), not 'staff_peer'.
        $peers_all  = [];   // all eligible Teacher/Staff peers, unfiltered — used for counts
        $peers      = [];   // peers/targets filtered down to the selected group
        $done_peers = [];
        $done_supervisors = []; // Dean/Principal target_user_ids already evaluated this period
        $peer_group = null; // 'teacher' | 'staff' | 'dean' | 'principal' | null (Step 1 not yet completed)

        if ($page === 'peer' && $staff_has_teaching_assignment) {
            $pr2 = $mysqli->prepare("SELECT u.id, u.full_name, u.designation, u.photo, u.role, u.sector, u.secondary_role
                    FROM users u WHERE u.role IN ('teacher','staff','faculty')
                      AND u.is_active=1 AND u.account_status='approved' AND u.id != ? ORDER BY u.full_name ASC");
            $pr2->bind_param("i", $user_id);
            $pr2->execute();
            $pr2res = $pr2->get_result();
            if ($pr2res) $peers_all = $pr2res->fetch_all(MYSQLI_ASSOC);
            $pr2->close();

            // Done if already has a Peer Evaluation tracker entry for this person in the current period
            $requestedPeerGroup = $_GET['group'] ?? '';
            $peerDonePeriodId = ($requestedPeerGroup === 'teacher' && $college_peer_period)
                ? (int)$college_peer_period['id'] : (int)($period['id'] ?? 0);
            if ($period || ($requestedPeerGroup === 'teacher' && $college_peer_period)) {
                $dpStmt = $mysqli->prepare("SELECT target_user_id FROM evaluation_tracker WHERE evaluator_id=? AND period_id=? AND eval_type IN ('faculty_peer','peer','staff_peer')");
                $dpStmt->bind_param("ii", $user_id, $peerDonePeriodId);
            } else {
                $dpStmt = $mysqli->prepare("SELECT target_user_id FROM evaluation_tracker WHERE evaluator_id=? AND eval_type IN ('faculty_peer','peer','staff_peer')");
                $dpStmt->bind_param("i", $user_id);
            }
            $dpStmt->execute();
            $dp = $dpStmt->get_result();
            if ($dp) while ($r = $dp->fetch_assoc()) $done_peers[] = $r['target_user_id'];
            $dpStmt->close();

            // Done if already has an eval_type='staff' tracker entry (Dean/Principal)
            $dsStmt = $mysqli->prepare("SELECT target_user_id FROM evaluation_tracker WHERE evaluator_id=? AND eval_type='staff'");
            $dsStmt->bind_param("i", $user_id);
            $dsStmt->execute();
            $ds = $dsStmt->get_result();
            if ($ds) while ($r = $ds->fetch_assoc()) $done_supervisors[] = $r['target_user_id'];
            $dsStmt->close();

            if (isset($_GET['group']) && in_array($_GET['group'], ['teacher', 'staff', 'dean', 'principal'], true)) {
                $peer_group = $_GET['group'];
                if (in_array($peer_group, ['dean', 'principal'], true)) {
                    $wantedTargetType = ucfirst($peer_group);
                    $peers = array_values(array_filter($staff_eval_targets, fn($t) => $t['target_type'] === $wantedTargetType));
                } else {
                    // Faculty category includes all eligible Faculty/Teaching Staff,
                    // regardless of their assigned year level.
                    $peers = array_values(array_filter($peers_all, function ($p) use ($mysqli, $peer_group, $staff_can_evaluate_faculty_peer) {
                        if ($peer_group === 'teacher') {
                            return $staff_can_evaluate_faculty_peer && resolve_peer_group($mysqli, $p) === 'teacher';
                        }
                        return resolve_peer_group($mysqli, $p) === $peer_group;
                    }));
                }
            }
        }

        // ── PEER EVAL FORM ────────────────────────────────────────────
        $peer_target       = null;
        $peer_questions    = [];
        $peer_categories   = [];
        $peer_eval_group   = null;  // group carried over from Step 1, validated below
        $peer_group_error  = '';    // set when tid/group don't match, shown in the invalid-target view

        if ($page === 'peer_eval' && $staff_has_teaching_assignment && isset($_GET['tid'])) {
            $tid = intval($_GET['tid']);
            $req_group = $_GET['group'] ?? null;

            $tu  = $mysqli->prepare("SELECT * FROM users WHERE id=? AND role IN ('teacher','staff','faculty') AND is_active=1 AND account_status='approved' LIMIT 1");
            $tu->bind_param("i", $tid); $tu->execute();
            $peer_target = $tu->get_result()->fetch_assoc(); $tu->close();

            if ($peer_target && !in_array($req_group, ['teacher', 'staff'], true)) {
                $peer_group_error = "Please select a designation.";
                $peer_target = null;
            } elseif ($peer_target) {
                $actual_group = resolve_peer_group($mysqli, $peer_target);
                if ($req_group === 'teacher' && !$staff_can_evaluate_faculty_peer) {
                    $peer_group_error = "Faculty Peer-to-Peer Evaluation is only available to Teaching Staff (Staff with an assigned year level).";
                    $peer_target = null;
                } elseif ($actual_group !== $req_group) {
                    $peer_group_error = "The selected user does not belong to the selected designation.";
                    $peer_target = null;
                } else {
                    $peer_eval_group = $req_group;
                }
            }

            if ($peer_target) {
                // Questionnaire Management assigns questions differently per bucket:
                // Non-Teaching Staff targets use their per-user Staff questionnaire, while
                // Faculty and Teaching Staff targets use the shared Faculty questionnaire.
                // A Teaching Staff account is therefore evaluated exactly like a Faculty
                // target once an EA assigns teaching responsibility.
                if ($peer_eval_group === 'staff') {
                    $qs = $mysqli->prepare("SELECT * FROM user_questions WHERE user_id=? AND target_type='Staff' AND eval_type='general' ORDER BY category ASC, id ASC");
                    $qs->bind_param("i", $tid); $qs->execute();
                } else {
                    $qs = $mysqli->prepare("SELECT * FROM evaluation_questions WHERE target_type='Faculty' AND eval_type='general' AND evaluator_role='shared' AND is_active=1 ORDER BY category ASC, id ASC");
                    $qs->execute();
                }
                $peer_questions = $qs->get_result()->fetch_all(MYSQLI_ASSOC); $qs->close();
                foreach ($peer_questions as $q) {
                    $cat = $q['category'] ?: 'General';
                    $peer_categories[$cat][] = $q;
                }
            }
        }

        // ── SUBMIT PEER EVAL ──────────────────────────────────────────
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_peer'])) {
            if (!csrf_check()) {
                $_SESSION['toast_error'] = "Your session expired or the request could not be verified. Please try again.";
                header("Location: staff_dashboard.php?page=peer"); exit;
            }

            // Strict re-check, mirroring the Staff Evaluation submit handler:
            // eligibility can change between page load and submit, so this is
            // re-derived from $my_levels, never trusted from the rendered form.
            if (!$staff_has_teaching_assignment) {
                $_SESSION['toast_error'] = "Peer-to-Peer Evaluation is only available to Staff with a teaching assignment.";
                header("Location: staff_dashboard.php?page=dashboard"); exit;
            }

            $submitted_group = $_POST['group'] ?? '';
            $tid_raw         = $_POST['target_id'] ?? '';
            $ratings         = $_POST['ratings'] ?? [];
            $comments        = trim($_POST['comments'] ?? '');

            // Re-check the live schedule on every POST. The browser may have
            // loaded the form before the schedule changed or may be stale.
            if (!staff_schedule_is_open($mysqli)) {
                $_SESSION['toast_error'] = "Evaluation is currently closed. Please return at the scheduled opening time.";
                header("Location: staff_dashboard.php?page=peer"); exit;
            }

            // ── Validation (per the Peer Evaluation designation-selection update) ──
            if (empty($submitted_group) || !in_array($submitted_group, ['teacher', 'staff'], true)) {
                $_SESSION['toast_error'] = "Please select a designation.";
                header("Location: staff_dashboard.php?page=peer"); exit;
            }
            if (empty($tid_raw)) {
                $_SESSION['toast_error'] = "Please select a user to evaluate.";
                header("Location: staff_dashboard.php?page=peer&group=" . urlencode($submitted_group)); exit;
            }
            $tid = intval($tid_raw);

            $tchk = $mysqli->prepare("SELECT id, designation, role, sector, secondary_role FROM users WHERE id=? AND role IN ('teacher','staff','faculty') AND is_active=1 AND account_status='approved' LIMIT 1");
            $tchk->bind_param("i", $tid); $tchk->execute();
            $tchkRow = $tchk->get_result()->fetch_assoc(); $tchk->close();

            if (!$tchkRow) {
                $_SESSION['toast_error'] = "The selected user does not exist.";
                header("Location: staff_dashboard.php?page=peer&group=" . urlencode($submitted_group)); exit;
            }
            if (resolve_peer_group($mysqli, $tchkRow) !== $submitted_group) {
                $_SESSION['toast_error'] = "The selected user does not belong to the selected designation.";
                header("Location: staff_dashboard.php?page=peer&group=" . urlencode($submitted_group)); exit;
            }
            if ($submitted_group === 'teacher' && !$staff_can_evaluate_faculty_peer) {
                $_SESSION['toast_error'] = "Faculty Peer-to-Peer Evaluation is only available to Teaching Staff (Staff with an assigned year level).";
                header("Location: staff_dashboard.php?page=peer&group=teacher"); exit;
            }

            // Eligibility check — peer evaluations have no year-level restriction, but this
            // still blocks self-evaluation and invalid targets before anything gets inserted.
            [$eligible, $eligMsg] = canPeerEvaluate($mysqli, $user_id, $tid);

            if (!$eligible) {
                $_SESSION['toast_error'] = $eligMsg;
                header("Location: staff_dashboard.php?page=peer&group=" . urlencode($submitted_group)); exit;
            }

            // Gate on an active evaluation period -- no period open, no submissions.
            if ($submitted_group === 'teacher') {
                $periodStmt = $mysqli->query("SELECT id FROM evaluation_periods
                    WHERE semester IN ('1st Semester','2nd Semester','Summer')" . pbi_not_archived_sql($mysqli) . "
                    ORDER BY
                (CONVERT(TRIM(school_year) USING utf8mb4) COLLATE utf8mb4_unicode_ci =
                    CONVERT(TRIM((SELECT setting_value FROM system_settings WHERE setting_key='acad_year' LIMIT 1)) USING utf8mb4) COLLATE utf8mb4_unicode_ci) DESC,
                (CONVERT(TRIM(semester) USING utf8mb4) COLLATE utf8mb4_unicode_ci =
                    CONVERT(TRIM((SELECT setting_value FROM system_settings WHERE setting_key='acad_term' LIMIT 1)) USING utf8mb4) COLLATE utf8mb4_unicode_ci) DESC,
                is_active DESC,
                id DESC LIMIT 1");
                $periodError = "No semester period is configured for Faculty Peer-to-Peer Evaluation.";
            } else {
                $periodStmt = $mysqli->query("SELECT id FROM evaluation_periods WHERE is_active=1" . pbi_current_period_tail($mysqli) . " LIMIT 1");
                $periodError = "No evaluation period is currently open.";
            }
            $activePeriod = $periodStmt ? $periodStmt->fetch_assoc() : null;
            if (!$activePeriod) {
                $_SESSION['toast_error'] = $periodError;
                header("Location: staff_dashboard.php?page=peer&group=" . urlencode($submitted_group)); exit;
            }
            $period_id = (int)$activePeriod['id'];

            // Only accept ratings for question IDs that actually belong to this
            // target's EA-assigned peer question set — anything else in the POST
            // is ignored rather than trusted, so a tampered request can't insert
            // stray rows. Mirrors the display-side fetch: per-user user_questions
            // for Non-Teaching Staff targets, shared Faculty-bucket questions otherwise.
            if ($submitted_group === 'staff') {
                $validQStmt = $mysqli->prepare("SELECT id FROM user_questions WHERE user_id=? AND target_type='Staff' AND eval_type='general'");
                $validQStmt->bind_param("i", $tid);
            } else {
                $validQStmt = $mysqli->prepare("SELECT id FROM evaluation_questions WHERE target_type='Faculty' AND eval_type='general' AND evaluator_role='shared' AND is_active=1");
            }
            $validQStmt->execute();
            $validQRes = $validQStmt->get_result();
            $valid_question_ids = [];
            if ($validQRes) while ($vq = $validQRes->fetch_assoc()) $valid_question_ids[] = (int)$vq['id'];
            $validQStmt->close();

            $ratings = array_filter($ratings, function ($val, $qid) use ($valid_question_ids) {
                return in_array((int)$qid, $valid_question_ids, true);
            }, ARRAY_FILTER_USE_BOTH);

            if (empty($ratings) || count($ratings) !== count($valid_question_ids)) {
                $_SESSION['toast_error'] = "Please rate all questions before submitting.";
                header("Location: staff_dashboard.php?page=peer_eval&tid=" . $tid . "&group=" . urlencode($submitted_group)); exit;
            }

            // A written comment is mandatory (whitespace-only doesn't count).
            if ($comments === '') {
                $_SESSION['toast_error'] = "Please write a comment before submitting. Comments and suggestions are required.";
                header("Location: staff_dashboard.php?page=peer_eval&tid=" . $tid . "&group=" . urlencode($submitted_group)); exit;
            }

            // Check duplicate for this period -- evaluator_id is this staff's user_id
            $dup = $mysqli->prepare("SELECT id FROM evaluation_tracker WHERE evaluator_id=? AND target_user_id=? AND period_id=? AND eval_type IN ('faculty_peer','peer','staff_peer') LIMIT 1");
            $dup->bind_param("iii", $user_id, $tid, $period_id); $dup->execute(); $dup->store_result();

            if ($dup->num_rows === 0) {
                $dup->close();
                try {
                    $mysqli->begin_transaction();
$eval_type         = 'faculty_peer';
$overall           = count($ratings) ? round(array_sum($ratings)/count($ratings), 2) : 0;
$peer_group_label  = $peer_group_labels[$submitted_group] ?? ucfirst($submitted_group);

$trk = $mysqli->prepare("INSERT INTO evaluation_tracker (evaluator_id, target_user_id, form_id, period_id, eval_type, peer_group, score, remarks, status, submitted_at) VALUES (?,?,?,?,?,?,?,?,'submitted',NOW())");
$trk->bind_param("iiiissds", $user_id, $tid, $peer_form_id, $period_id, $eval_type, $peer_group_label, $overall, $comments);
$trk->execute();
$tracker_id = $mysqli->insert_id; $trk->close();

                    $questionSource = $submitted_group === 'teacher' ? 'evaluation' : 'user';
                    $ins = $mysqli->prepare("INSERT INTO questionnaire_answers (tracker_id, question_id, question_source, user_question_id, answer_score, submitted_at) VALUES (?, ?, ?, ?, ?, NOW())");
                    foreach ($ratings as $qid => $rating) {
                        $qid   = intval($qid);
                        $score = min(5, max(1, intval($rating)));
                        $questionId = $questionSource === 'evaluation' ? $qid : null;
                        $userQuestionId = $questionSource === 'user' ? $qid : null;
                        $ins->bind_param("iisii", $tracker_id, $questionId, $questionSource, $userQuestionId, $score);
                        $ins->execute();
                    }
                    $ins->close();
                    $mysqli->commit();

                    // Notify the evaluated staff member — anonymously, no evaluator identity attached.
                    $notif_msg = NOTIF_EVAL_RECEIVED_MSG;
                    $nins = $mysqli->prepare("INSERT INTO notifications (type, user_id, message) VALUES ('evaluation_received', ?, ?)");
                    $nins->bind_param("is", $tid, $notif_msg);
                    $nins->execute(); $nins->close();

                    $_SESSION['toast'] = "Peer-to-Peer evaluation submitted!";
                } catch (Exception $e) {
                    $mysqli->rollback();
                    error_log('[staff_dashboard] submit_peer failed for evaluator=' . $user_id . ' target=' . $tid . ': ' . $e->getMessage());
                    // A duplicate-key error from the new unique index lands here too
                    // (race between two near-simultaneous submissions) — same
                    // user-facing message either way, no internal detail leaked.
                    $_SESSION['toast_error'] = (($mysqli->errno ?? 0) === 1062)
                        ? "You already evaluated this staff member."
                        : "Submission failed. Please try again.";
                }
            } else {
                $dup->close();
                $_SESSION['toast_error'] = "You already evaluated this staff member.";
            }
            header("Location: staff_dashboard.php?page=peer&group=" . urlencode($submitted_group)); exit;
        }


        // ── RECENT SUBMISSIONS ────────────────────────────────────────
        $recent_subs = [];
        $rsStmt = $mysqli->prepare("
            SELECT et.id as tracker_id, et.submitted_at,
                   (SELECT AVG(qa.answer_score) FROM questionnaire_answers qa WHERE qa.tracker_id = et.id) as overall_score
            FROM evaluation_tracker et
            WHERE et.target_user_id = ?
            ORDER BY et.submitted_at DESC LIMIT 5
        ");
        $rsStmt->bind_param("i", $user_id);
        $rsStmt->execute();
        $rs = $rsStmt->get_result();
        if ($rs) $recent_subs = $rs->fetch_all(MYSQLI_ASSOC);
        $rsStmt->close();
        $recent_subs = kept_feedback_merge_lists($recent_subs, kept_feedback_rows($mysqli, (int)$user_id), 5);

        // ── MY NOTIFICATIONS (bell) ──────────────────────────────────
        $my_notifications = [];
        $unread_count     = 0;
        $nq = $mysqli->prepare("SELECT id, type, message, extra_data, is_read, created_at FROM notifications WHERE user_id=?" . notif_muted_types_sql($user_prefs) . " ORDER BY created_at DESC, id DESC");
        $nq->bind_param("i", $user_id);
        $nq->execute();
        $nres = $nq->get_result();
        if ($nres) $my_notifications = $nres->fetch_all(MYSQLI_ASSOC);
        $nq->close();
        foreach ($my_notifications as &$notification) {
            $notification['message'] = notif_apply_eval_wording($notification, notification_message_for_view($notification, (int)$user_id));
        }
        unset($notification);
        foreach ($my_notifications as $n) if (empty($n['is_read'])) $unread_count++;

        // ── DESIGNATION SUGGESTIONS ───────────────────────────────────
        $desig_suggestions = ['Personnel','Registrar','Cashier','Bookkeeper','Librarian','Guidance','Nurse','Coordinator','Teacher','Adviser','Department Head'];

        $toast       = $_SESSION['toast']       ?? ''; unset($_SESSION['toast']);
        $toast_error = $_SESSION['toast_error'] ?? ''; unset($_SESSION['toast_error']);

        // Helpers
        $first_name = explode(',', $full_name)[0] ?? $full_name;
        $parts      = explode(' ', trim($full_name));
        $initials   = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
        <meta charset="UTF-8"/>
        <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
        <title>Staff Dashboard — PBI</title>
        <script>
        // Prevent a flash of the wrong theme: if the saved preference is "dark" but the
        // page markup below defaults to class="light-theme", hide the page until the
        // real theme JS (further down) applies the correct class and reveals it.
        (function(){
    try{
        var savedTheme = localStorage.getItem('pbi_theme');
        var systemDark = savedTheme === 'system' && window.matchMedia && !window.matchMedia('(prefers-color-scheme: light)').matches;
        if (savedTheme === 'dark' || systemDark) {
            document.write('<style id="theme-fouc-guard">body{visibility:hidden}</style>');
        }
    }catch(e){}
})();
        </script>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
        <link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
        <style>
        :root{
            --dark:#0A192F;--mid:#172A45;--inner:#0F1F3D;
            --accent:#16A34A;--hover:#15803D;
            --teal:#16A34A;--teal-hover:#15803D;
            --light:#E0E6F0;--muted:#A0B3C6;
            --danger:#F05454;--success:#22C55E;
            --border:rgba(255,255,255,0.08);--radius:10px;--shadow:0 4px 20px rgba(0,0,0,0.35);
            --sidebar-w:240px;
        }
        body.light-theme{
            --dark:#F3F6FB;--mid:#FFFFFF;--inner:#EEF2F8;
            --accent:#16A34A;--hover:#15803D;
            --teal:#16A34A;--teal-hover:#15803D;
            --light:#16263B;--muted:#5B7186;
            --danger:#DC2626;--success:#16A34A;
            --border:rgba(15,31,61,0.10);--shadow:0 4px 20px rgba(15,31,61,.08);
        }
        body.light-theme .sidebar-brand:hover{background:rgba(15,31,61,.04);}
        body.light-theme .profile-dd-btn:hover{background:rgba(15,31,61,.06);}
        body.light-theme .dd-appearance-val{background:rgba(15,31,61,.06);}
        body.light-theme .nav-link:hover{background:rgba(15,31,61,.05);}
        body.light-theme .notif-btn{background:rgba(15,31,61,.06);}
        body.light-theme .notif-item{border-bottom:1px solid rgba(15,31,61,.07);}
        body.light-theme .cat-bar-bg{background:rgba(15,31,61,.08);}
        body.light-theme .peer-card-btn.done{background:rgba(15,31,61,.06);}
        body.light-theme .sidebar-title,
        body.light-theme .nav-page-title,
        body.light-theme .notif-header-title,
        body.light-theme .photo-modal-title,
        body.light-theme .dashboard-intro .status-pill.closed{background:#FFF1F1;border-color:#F3B0B0;color:#B42318;}
body.light-theme .welcome-bar h2,
        body.light-theme .stat-box-val,
        body.light-theme .section-card-title,
        body.light-theme .eval-modal-title,
        body.light-theme .eval-info-value,
        body.light-theme .eval-q-text,
        body.light-theme .profile-hero-name,
        body.light-theme .role-card-title,
        body.light-theme .student-mini-name,
        body.light-theme .peer-card-name,
        body.light-theme .eval-name,
        body.light-theme .q-text{color:var(--light);}
        body.light-theme .ea-tab{background:rgba(124,58,237,.10);color:#4c1d95;border-color:rgba(124,58,237,.24);}
        body.light-theme .ea-tab .count{background:rgba(124,58,237,.14);color:#4c1d95;}
        body.light-theme .ea-toolbar{background:#FFFFFF;}
        body.light-theme .ea-search-input{background:#F3F6FB;border-color:rgba(15,31,61,.12);color:#16263B;}
        body.light-theme .ea-search-icon{color:#8892a6;}
        body.light-theme .ea-export-btn{background:rgba(124,58,237,.08);color:#5b21b6;border-color:rgba(124,58,237,.28);}
        body.light-theme .ea-table-card{background:#FFFFFF;}
        body.light-theme .ea-table th{background:#EEF2F8;color:#5B7186;}
        body.light-theme .ea-table td{color:#16263B;border-top-color:rgba(15,31,61,.08);}
        body.light-theme .ea-table tr:hover{background:rgba(124,58,237,.04);}
        body.light-theme .ea-avatar{border-color:rgba(15,31,61,.12);background:#EEF2F8;color:#5b21b6;}
        body.light-theme .ea-name{color:#16263B;}
        body.light-theme .ea-role-pill{background:rgba(124,58,237,.10);color:#5b21b6;border-color:rgba(124,58,237,.20);}
        body.light-theme .ea-status{background:rgba(100,116,139,.12);color:#475569;}
        body.light-theme .ea-status.done{background:rgba(34,197,94,.12);color:#15803d;}
        body.light-theme .ea-table-footer{color:#5B7186;border-top-color:rgba(15,31,61,.08);}
        body.light-theme .level-view-empty,
        body.light-theme .peer-select-hint.warn{color:#B45309;}
        body.light-theme .notif-btn:hover,
        body.light-theme .notif-btn.has-unread{color:#B45309;}
        body.light-theme .info-note i{color:#2563EB;}
        body.light-theme .toast-success{color:#15803D;}
        body.light-theme .toast-error{color:#B91C1C;}
        body.light-theme .level-view-pill{color:#0F766E;}
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
        body{font-family:'DM Sans',sans-serif;background:var(--dark);color:var(--light);min-height:100vh;display:flex;}

        /* SIDEBAR */
        .sidebar{width:var(--sidebar-w);flex-shrink:0;background:var(--mid);border-right:1px solid var(--border);display:flex;flex-direction:column;position:fixed;top:0;left:0;height:100vh;z-index:40;transition:transform .3s;}
        .sidebar-brand{padding:16px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;cursor:pointer;transition:background .2s;position:relative;}
        .sidebar-brand:hover{background:rgba(255,255,255,.04);}
        .brand-avatar{width:42px;height:42px;border-radius:50%;flex-shrink:0;border:2px solid var(--teal);box-shadow:0 0 10px rgba(99,102,241,.3);overflow:hidden;background:var(--inner);display:flex;align-items:center;justify-content:center;}
        .brand-avatar img{width:100%;height:100%;object-fit:cover;display:block;}
        .brand-avatar .brand-initials{font-family:'Rajdhani',sans-serif;font-size:15px;font-weight:700;color:var(--teal-hover);line-height:1;}
        .sidebar-title{font-family:'Rajdhani',sans-serif;font-size:14px;font-weight:700;letter-spacing:.4px;color:#fff;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:118px;}
        .sidebar-sub{font-size:11px;color:var(--teal-hover);letter-spacing:.2px;margin-top:2px;font-weight:600;}
        .sidebar-caret{font-size:11px;color:var(--muted);flex-shrink:0;transition:transform .2s;}
        .sidebar-profile-dropdown{margin:0 12px;max-height:0;opacity:0;overflow:hidden;background:var(--inner);border-radius:10px;transition:max-height .2s ease,opacity .2s ease,margin .2s ease;}
        .sidebar-profile-dropdown.open{max-height:260px;opacity:1;margin:8px 12px 10px;border:1px solid var(--border);}
        .profile-dd-btn{width:100%;padding:10px 12px;border-radius:8px;border:none;background:none;color:var(--light);font-size:13px;font-family:'DM Sans',sans-serif;cursor:pointer;display:flex;align-items:center;gap:10px;transition:background .18s;text-align:left;text-decoration:none;}
        .profile-dd-btn:hover{background:rgba(255,255,255,.06);}
        .profile-dd-btn i{width:16px;text-align:center;color:var(--muted);}
        .profile-dd-btn i.dd-icon-blue{color:#3B82F6;}
        .profile-dd-btn i.dd-icon-purple{color:#8B5CF6;}
        .nav-icon-blue{color:#3B82F6;}
        .nav-icon-purple{color:#8B5CF6;}
        .nav-icon-green{color:#22C55E;}
        .nav-icon-orange{color:#F97316;}
        .profile-dd-divider{height:1px;background:var(--border);margin:6px 4px;}
        .profile-dd-btn.logout{color:#f87171;}
        .profile-dd-btn.logout i{color:#f87171;}
        .dd-appearance-val{margin-left:auto;font-size:11px;color:var(--muted);font-weight:700;background:rgba(255,255,255,.06);padding:3px 9px;border-radius:20px;flex-shrink:0;}
        .profile-dd-btn:hover .dd-appearance-val{color:var(--teal-hover);}
        .sidebar-nav{flex:1;padding:16px 10px;overflow-y:auto;}
        .nav-section-label{font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:1.5px;color:#99F6E4;padding:0 8px;margin-bottom:7px;margin-top:16px;text-align:center;text-shadow:0 1px 8px rgba(45,212,191,.14);}
        .nav-section-label:first-child{margin-top:0;}
        .nav-link{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:8px;color:var(--muted);text-decoration:none;font-size:14px;font-weight:600;transition:all .2s;margin-bottom:2px;}
        .nav-link:hover{background:rgba(255,255,255,.05);color:var(--light);}
        .nav-link.active{background:rgba(99,102,241,.18);color:var(--teal-hover);}
        .nav-link.active i{color:var(--teal);}
        .nav-link i{font-size:15px;width:18px;text-align:center;}
        .nav-link .badge{margin-left:auto;background:var(--teal);color:#fff;font-size:10px;font-weight:700;padding:1px 7px;border-radius:20px;}
        .sidebar-footer{padding:14px 16px;border-top:1px solid var(--border);}
        .btn-logout-side{display:flex;align-items:center;gap:7px;width:100%;padding:9px 12px;border:1px solid rgba(240,84,84,.3);background:rgba(240,84,84,.08);border-radius:8px;color:#f87171;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;transition:all .2s;font-family:'DM Sans',sans-serif;}
        .btn-logout-side:hover{background:rgba(240,84,84,.18);}
        .btn-view-all-evals{
        width:100%;
        display:flex;
        align-items:center;
        gap:10px;
        background:rgba(99,102,241,.12);
        border:1px solid rgba(99,102,241,.3);
        color:var(--teal-hover);
        font-size:13.5px;
        font-weight:700;
        padding:12px 16px;
        border-radius:10px;
        cursor:pointer;
        font-family:'DM Sans',sans-serif;
        transition:background .2s;
    }
    .btn-view-all-evals:hover{background:rgba(99,102,241,.2);}

        /* TOP NAV */
        .top-nav{position:fixed;top:0;left:var(--sidebar-w);right:0;height:60px;z-index:30;background:var(--mid);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 28px;box-shadow:var(--shadow);}
        .nav-page-title{font-family:'Rajdhani',sans-serif;font-size:20px;font-weight:700;letter-spacing:.5px;color:#fff;}
        .nav-right{display:flex;align-items:center;gap:14px;}
        .period-badge{background:rgba(99,102,241,.15);border:1px solid rgba(99,102,241,.3);color:var(--teal-hover);padding:5px 12px;border-radius:20px;font-size:12px;font-weight:600;display:flex;align-items:center;gap:6px;}
        .hamburger{display:none;background:none;border:none;color:var(--light);font-size:20px;cursor:pointer;}

        /* NOTIFICATION BELL */
        .notif-wrap{position:relative;display:flex;align-items:center;}
        .notif-btn{width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.06);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:15px;cursor:pointer;transition:all .2s;position:relative;}
        .notif-btn:hover,.notif-btn.has-unread{color:#facc15;border-color:rgba(250,204,21,.4);background:rgba(250,204,21,.08);}
        .notif-badge{position:absolute;top:-4px;right:-4px;min-width:18px;height:18px;border-radius:9px;background:#ef4444;color:#fff;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;padding:0 4px;border:2px solid var(--mid);}
        .notif-dropdown{position:absolute;top:calc(100% + 10px);right:0;width:min(320px,calc(100vw - 24px));max-height:min(70vh,560px);display:flex;flex-direction:column;background:var(--mid);border:1px solid var(--border);border-radius:14px;box-shadow:0 16px 48px rgba(0,0,0,.55);opacity:0;visibility:hidden;transform:translateY(-8px);transition:all .2s ease;z-index:120;overflow:hidden;}
        .notif-dropdown.show{opacity:1;visibility:visible;transform:translateY(0);}
        .notif-header{flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid var(--border);}
        .notif-header-title{font-size:13px;font-weight:700;color:#fff;display:flex;align-items:center;gap:7px;}
        .notif-mark-read{font-size:11px;color:var(--teal-hover);cursor:pointer;font-weight:600;background:none;border:none;font-family:'DM Sans',sans-serif;padding:0;}
        .notif-list{flex:1 1 auto;min-height:0;max-height:100%;overflow-y:auto;overflow-x:hidden;overscroll-behavior:contain;-webkit-overflow-scrolling:touch;scrollbar-width:thin;scrollbar-color:rgba(148,163,184,.55) transparent;}
        .notif-list::-webkit-scrollbar{width:7px;}
        .notif-list::-webkit-scrollbar-track{background:transparent;}
        .notif-list::-webkit-scrollbar-thumb{background:rgba(148,163,184,.55);border-radius:8px;}
        .notif-item{display:flex;align-items:flex-start;gap:10px;padding:11px 14px;border-bottom:1px solid rgba(255,255,255,.05);position:relative;}
        .notif-item:last-child{border-bottom:none;}
        a.notif-item{text-decoration:none;color:inherit;cursor:pointer;transition:background .15s ease;}
        a.notif-item:hover,a.notif-item:focus-visible{background:rgba(255,255,255,.07);outline:none;}
        body.light-theme a.notif-item:hover,body.light-theme a.notif-item:focus-visible{background:rgba(15,31,61,.06);}
        a.notif-item::after{content:'\f054';font-family:'Font Awesome 6 Free';font-weight:900;font-size:10px;color:var(--muted);align-self:center;margin-left:auto;padding-left:6px;opacity:0;transform:translateX(-3px);transition:opacity .15s ease,transform .15s ease;}
        a.notif-item:hover::after,a.notif-item:focus-visible::after{opacity:1;transform:translateX(0);color:var(--teal-hover);}
        a.notif-item:hover,body.light-theme a.notif-item:hover,a.notif-item:focus-visible,body.light-theme a.notif-item:focus-visible{background:rgba(128,128,128,.10);background:color-mix(in srgb,var(--teal) 12%,transparent);}
        a.notif-item:focus-visible{outline:2px solid var(--teal);outline-offset:-2px;}
        a.notif-item:active{background:color-mix(in srgb,var(--teal) 20%,transparent);}
        .notif-item.unread{background:rgba(99,102,241,.07);}
        .notif-item.unread::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--teal);border-radius:0 2px 2px 0;}
        .notif-icon{width:30px;height:30px;border-radius:8px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:12px;margin-top:1px;}
        .notif-text{font-size:12px;color:var(--light);line-height:1.45;margin-bottom:3px;word-break:break-word;}
        .notif-meta{font-size:11px;color:var(--muted);}
        .notif-empty{text-align:center;padding:32px 16px;color:var(--muted);font-size:13px;}
        .notif-empty i{font-size:28px;display:block;margin-bottom:8px;opacity:.2;}
        .live-notif-toast{position:fixed;top:74px;right:28px;z-index:250;max-width:380px;display:flex;align-items:flex-start;gap:10px;padding:12px 14px;border:1px solid rgba(16,185,129,.35);border-radius:12px;background:var(--mid);box-shadow:0 16px 40px rgba(0,0,0,.25);color:var(--light);opacity:0;transform:translateY(-10px);pointer-events:none;transition:opacity .22s ease,transform .22s ease;}
        .live-notif-toast.show{opacity:1;transform:translateY(0);pointer-events:auto;cursor:pointer;}
        .live-notif-toast-icon{width:30px;height:30px;flex:0 0 30px;border-radius:8px;display:flex;align-items:center;justify-content:center;background:rgba(16,185,129,.12);color:#10b981;}
        .live-notif-toast strong{display:block;font-size:12px;color:#fff;margin-bottom:2px;}
        .live-notif-toast span{display:block;font-size:12px;line-height:1.4;color:var(--light);}
        @media (max-width:700px){.live-notif-toast{left:16px;right:16px;top:70px;max-width:none;}}


        /* PROFILE PHOTO MODAL */
        .photo-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:300;display:none;align-items:center;justify-content:center;padding:20px;}
        .photo-modal-overlay.open{display:flex;}
        .photo-modal{background:var(--mid);border:1px solid var(--border);border-radius:18px;width:100%;max-width:400px;box-shadow:0 20px 60px rgba(0,0,0,.6);overflow:hidden;}
        .photo-modal-header{padding:20px 24px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;}
        .photo-modal-title{font-family:'Rajdhani',sans-serif;font-size:20px;font-weight:700;color:#fff;}
        .photo-modal-body{padding:24px;}
        .photo-upload-circle{width:110px;height:110px;border-radius:50%;border:3px dashed rgba(99,102,241,.5);display:flex;align-items:center;justify-content:center;margin:0 auto 20px;cursor:pointer;transition:border-color .2s;overflow:hidden;position:relative;background:var(--inner);}
        .photo-upload-circle:hover{border-color:var(--teal);}
        .photo-upload-circle img{width:100%;height:100%;object-fit:cover;display:none;border-radius:50%;}
        .photo-upload-circle .upload-icon-modal{color:var(--muted);font-size:32px;transition:color .2s;}
        .photo-upload-circle:hover .upload-icon-modal{color:var(--teal);}
        .photo-upload-hint-modal{text-align:center;font-size:12px;color:var(--muted);margin-bottom:20px;}
        .photo-upload-hint-modal span{color:var(--teal-hover);font-weight:600;cursor:pointer;}
        .photo-modal-footer{padding:0 24px 24px;display:flex;gap:10px;}
        .btn-save-photo{flex:1;padding:11px;background:var(--teal);border:none;border-radius:var(--radius);color:#fff;font-size:14px;font-weight:700;cursor:pointer;font-family:'DM Sans',sans-serif;}
        .btn-save-photo:hover{background:var(--teal-hover);}
        .btn-skip-photo{flex:1;padding:11px;background:var(--inner);border:1px solid var(--border);border-radius:var(--radius);color:var(--muted);font-size:14px;font-weight:600;cursor:pointer;font-family:'DM Sans',sans-serif;}

        /* MAIN */
        .main{margin-left:var(--sidebar-w);margin-top:60px;padding:28px;flex:1;min-height:calc(100vh - 60px);}

        /* TOAST */
        .toast{border-radius:8px;padding:12px 18px;font-size:13px;margin-bottom:22px;display:flex;align-items:center;gap:8px;animation:fadeIn .3s ease;}
        .toast-success{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.3);color:#86efac;}
        .toast-error{background:rgba(240,84,84,.12);border:1px solid rgba(240,84,84,.3);color:#fca5a5;}
        @keyframes fadeIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}

        /* DASHBOARD */
        .evaluation-schedule-card{background:var(--mid);border:1px solid var(--border);border-radius:16px;padding:22px 26px;margin:0 0 22px;box-shadow:0 5px 18px rgba(15,35,60,.035);}
        .schedule-summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:20px;align-items:start;}
        .schedule-summary-item{min-width:0;}
        .schedule-label{display:block;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#E19A1D;margin-bottom:7px;}
        .schedule-summary-item strong{display:block;font-size:15px;line-height:1.25;color:#fff;font-weight:700;white-space:normal;}
        .schedule-status{display:inline-flex;align-items:center;padding:6px 14px;border-radius:999px;font-size:12px;font-weight:700;border:1px solid rgba(239,68,68,.35);background:rgba(239,68,68,.10);color:#DC2626;}
        .schedule-status.open{border-color:rgba(34,197,94,.35);background:rgba(34,197,94,.12);color:#22C55E;}
        .schedule-status.closed{border-color:rgba(239,68,68,.35);background:rgba(239,68,68,.10);color:#F87171;}
        .schedule-times{margin-top:19px;padding-top:16px;border-top:1px solid var(--border);}
        .schedule-message{display:flex;align-items:center;gap:7px;flex-wrap:wrap;font-size:13px;color:var(--muted);}
        .schedule-message i{color:#E19A1D;font-size:15px;margin-right:2px;}
        .schedule-message strong{color:#fff;}
        @media(max-width:900px){.schedule-summary-grid{grid-template-columns:repeat(2,minmax(0,1fr));}}
        @media(max-width:600px){.evaluation-schedule-card{padding:18px 20px;}.schedule-summary-grid{grid-template-columns:1fr 1fr;gap:16px;}.schedule-message{display:block;line-height:1.7;}.schedule-message i{margin-right:5px;}}
        .welcome-bar{background:linear-gradient(135deg,var(--mid) 0%,rgba(99,102,241,.15) 100%);border:1px solid rgba(99,102,241,.2);border-radius:14px;padding:24px 28px;margin-bottom:24px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px;}
        .welcome-bar h2{font-family:'Rajdhani',sans-serif;font-size:24px;font-weight:700;color:#fff;margin-bottom:4px;}
        .welcome-bar p{font-size:13px;color:var(--muted);}
        .score-chip{background:rgba(99,102,241,.2);border:1px solid rgba(99,102,241,.35);border-radius:12px;padding:14px 22px;text-align:center;}
        .score-chip .big{font-size:32px;font-weight:700;color:var(--teal-hover);}
        .score-chip .lbl{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;}
        .stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px;}
        .stat-box{background:var(--mid);border:1px solid var(--border);border-radius:12px;padding:18px 20px;}
        .stat-box-link{display:block;color:inherit;text-decoration:none;cursor:pointer;transition:transform .18s ease,border-color .18s ease,box-shadow .18s ease;}
        .stat-box-link:hover{transform:translateY(-2px);border-color:var(--teal-hover);box-shadow:0 8px 18px rgba(20,42,67,.10);}
        .stat-box-link:focus-visible{outline:3px solid var(--teal-hover);outline-offset:3px;}
        body.light-theme .stat-box-link:hover{border-color:#16A34A !important;box-shadow:0 8px 18px rgba(20,42,67,.10) !important;}
        .stat-box-lbl{font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);margin-bottom:8px;}
        .stat-box-val{font-size:26px;font-weight:700;color:#fff;}
        .stat-box-val.teal{color:var(--teal-hover);}
        .stat-box-val.gold{color:#F59E0B;}
        .section-card{background:var(--mid);border:1px solid var(--border);border-radius:14px;padding:22px 24px;margin-bottom:22px;}
        .section-card-title{font-family:'Rajdhani',sans-serif;font-size:18px;font-weight:700;color:#fff;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
        .cat-row{display:flex;align-items:center;gap:12px;margin-bottom:10px;}
        .cat-name{font-size:13px;color:var(--light);width:200px;flex-shrink:0;}
        .cat-bar-bg{flex:1;height:7px;background:rgba(255,255,255,.07);border-radius:4px;overflow:hidden;}
        .cat-bar-fill{height:100%;border-radius:4px;transition:width .5s ease;}
        .cat-score{font-size:13px;font-weight:700;width:36px;text-align:right;}
        .sub-item{background:var(--inner);border:1px solid var(--border);border-radius:8px;padding:12px 16px;margin-bottom:8px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;}
        .sub-meta{font-size:12px;color:var(--muted);}
        .sub-score-badge{padding:4px 12px;border-radius:20px;font-size:13px;font-weight:700;}
        .sub-item-clickable{cursor:pointer;transition:background .15s,border-color .15s;}
        .sub-item-clickable:hover{background:rgba(99,102,241,.08);border-color:rgba(99,102,241,.35);}
        .btn-view-details{background:rgba(99,102,241,.14);border:1px solid rgba(99,102,241,.3);color:var(--teal-hover);font-size:11px;font-weight:700;padding:5px 12px;border-radius:20px;cursor:pointer;white-space:nowrap;font-family:'DM Sans',sans-serif;}
        .btn-view-details:hover{background:var(--teal);color:#fff;}
        .empty-state{text-align:center;padding:48px 20px;color:var(--muted);}
        .empty-state i{font-size:38px;opacity:.3;display:block;margin-bottom:14px;}
        .back-link{display:inline-flex;align-items:center;gap:7px;background:var(--inner);border:1px solid var(--border);color:var(--light);padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;margin-bottom:18px;transition:background .2s;}
        .back-link:hover{background:var(--accent);}

        /* ══ EVALUATION DETAILS MODAL ══ */
        .eval-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:300;display:none;align-items:center;justify-content:center;padding:20px;}
        .eval-modal-overlay.open{display:flex;}
        .eval-modal{background:var(--mid);border:1px solid var(--border);border-radius:18px;width:100%;max-width:640px;max-height:88vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.6);}
        .eval-modal-header{padding:20px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
        .eval-modal-title{font-family:'Rajdhani',sans-serif;font-size:19px;font-weight:700;color:#fff;}
        .eval-modal-close{background:none;border:none;color:var(--muted);font-size:18px;cursor:pointer;}
        .eval-modal-body{padding:22px 24px;overflow-y:auto;}
        .eval-info-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:20px;}
        .eval-info-item{background:var(--inner);border:1px solid var(--border);border-radius:10px;padding:12px 14px;}
        .eval-info-label{font-size:10px;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);font-weight:700;margin-bottom:5px;}
        .eval-info-value{font-size:13.5px;color:#fff;font-weight:600;}
        .eval-modal-loading{text-align:center;padding:50px 20px;color:var(--muted);}
        .eval-modal-loading i{font-size:26px;margin-bottom:10px;display:block;}
        .eval-q-star{color:#374151;font-size:14px;}
        .eval-q-star.filled{color:#facc15;}
        .eval-comment-box{background:var(--inner);border:1px solid var(--border);border-radius:10px;padding:15px 17px;font-size:13.5px;color:var(--light);line-height:1.6;font-style:italic;}
        .eval-comment-box.empty{color:var(--muted);font-style:normal;}
        .eval-q-card{background:var(--mid);border:1px solid var(--border);border-radius:12px;padding:14px 16px;margin-bottom:10px;}
        .eval-q-no{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:5px;font-weight:700;}
        .eval-q-text{font-size:14px;font-weight:600;color:#fff;margin-bottom:8px;line-height:1.5;}
        .eval-cat-header{display:flex;align-items:center;gap:8px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:1.5px;color:var(--teal-hover);margin:18px 0 10px;padding-bottom:8px;border-bottom:1px solid rgba(99,102,241,.2);}
        .eval-cat-header:first-child{margin-top:0;}

        /* ══ MY PROFILE / ROLE ASSIGNMENT ══ */
        .profile-hero{background:linear-gradient(135deg,var(--mid) 0%,rgba(99,102,241,.18) 100%);border:1px solid rgba(99,102,241,.25);border-radius:16px;padding:28px;margin-bottom:22px;display:flex;align-items:center;justify-content:space-between;gap:22px;flex-wrap:wrap;}
        .profile-hero-left{display:flex;align-items:center;gap:22px;flex-wrap:wrap;}
        .profile-hero-avatar{width:80px;height:80px;border-radius:50%;object-fit:cover;border:3px solid var(--teal);box-shadow:0 0 18px rgba(99,102,241,.4);flex-shrink:0;display:flex;align-items:center;justify-content:center;color:var(--teal-hover);font-size:30px;background:var(--inner);}
        .profile-hero-name{font-family:'Rajdhani',sans-serif;font-size:26px;font-weight:700;color:#fff;margin-bottom:6px;}
        .profile-hero-desig{display:inline-flex;align-items:center;gap:7px;background:rgba(99,102,241,.2);border:1px solid rgba(99,102,241,.35);color:var(--teal-hover);font-size:13px;font-weight:700;padding:5px 14px;border-radius:20px;}

        /* ROLE ASSIGNMENT CARD */
        .role-card{background:var(--mid);border:2px solid rgba(99,102,241,.3);border-radius:16px;padding:26px;margin-bottom:22px;position:relative;overflow:hidden;}
        .role-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--teal),var(--teal-hover));}
        .role-card-title{font-family:'Rajdhani',sans-serif;font-size:20px;font-weight:700;color:#fff;margin-bottom:4px;display:flex;align-items:center;gap:9px;}
        .role-card-sub{font-size:13px;color:var(--muted);margin-bottom:22px;}

        /* Quick-pick chips */
        .role-chips-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--muted);margin-bottom:10px;display:flex;align-items:center;gap:6px;}
        .role-chips{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:20px;}
        .role-chip{padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;border:2px solid var(--border);background:var(--inner);color:var(--muted);transition:all .22s;display:flex;align-items:center;gap:6px;}
        .role-chip:hover{border-color:var(--teal);color:var(--teal-hover);background:rgba(99,102,241,.1);}
        .role-chip.is-current{border-color:var(--teal);background:rgba(99,102,241,.18);color:var(--teal-hover);pointer-events:none;}
        .role-chip.is-current::after{content:'✓';margin-left:2px;font-size:12px;}
        .eval-group-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:18px;margin-bottom:4px;}
        .eval-group-card{background:var(--inner);border:1px solid var(--border);border-radius:14px;padding:30px 20px;display:flex;flex-direction:column;align-items:center;text-align:center;text-decoration:none;transition:all .2s;}
        .eval-group-card:hover{border-color:var(--teal);transform:translateY(-3px);box-shadow:var(--shadow);}
        .eval-group-icon{width:58px;height:58px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:22px;margin-bottom:16px;}
        .eval-group-icon.blue{background:rgba(59,130,246,.14);color:#3B82F6;}
        .eval-group-icon.green{background:rgba(34,197,94,.14);color:#22C55E;}
        .eval-group-icon.purple{background:rgba(139,92,246,.14);color:#8B5CF6;}
        .eval-group-icon.gold{background:rgba(245,158,11,.14);color:#F59E0B;}
        .eval-group-title{font-family:'Rajdhani',sans-serif;font-size:17px;font-weight:700;color:#fff;margin-bottom:4px;}
        .eval-group-count{font-size:12.5px;color:var(--muted);}
        body.light-theme .eval-group-title{color:var(--light);}
        body.light-theme .eval-group-card:hover{border-color:#15803D;box-shadow:0 8px 24px rgba(21,128,61,.08);}
        .eval-group-card.is-selected{border-color:var(--teal);background:rgba(99,102,241,.10);}
        body.light-theme .eval-group-card.is-selected{border-color:#15803D;background:rgba(21,128,61,.06);}
        .peer-inline-categories{margin-bottom:22px;}

        /* Text input row */
        .custom-role-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--muted);margin-bottom:8px;display:flex;align-items:center;gap:6px;}
        .custom-role-row{display:flex;gap:10px;flex-wrap:wrap;}
        .custom-role-input{flex:1;min-width:220px;background:var(--inner);border:2px solid var(--border);color:var(--light);padding:13px 16px;border-radius:10px;font-size:14px;font-family:'DM Sans',sans-serif;outline:none;transition:border-color .2s,box-shadow .2s;}
        .custom-role-input:focus{border-color:var(--teal);box-shadow:0 0 0 3px rgba(99,102,241,.2);}
        .custom-role-input::placeholder{color:var(--muted);}
        .btn-save-role{background:var(--teal);color:#fff;border:none;padding:13px 28px;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:8px;transition:all .2s;font-family:'DM Sans',sans-serif;white-space:nowrap;}
        .btn-save-role:hover{background:var(--teal-hover);transform:translateY(-1px);}

        .info-note{background:rgba(43,108,176,.08);border:1px solid rgba(43,108,176,.2);border-radius:10px;padding:14px 18px;font-size:13px;color:var(--muted);display:flex;gap:10px;align-items:flex-start;margin-top:20px;}
        .info-note i{color:#60a5fa;flex-shrink:0;margin-top:1px;}

        /* TEACHING LEVEL DROPDOWN (header quick-access, mirrors faculty dashboard UX) */
        .level-view-wrap{flex-shrink:0;background:var(--inner);border:1px solid var(--border);border-radius:12px;padding:14px 18px;min-width:220px;max-width:320px;transition:box-shadow .3s ease,border-color .3s ease;}
        .level-view-wrap.flash{border-color:var(--teal);box-shadow:0 0 0 3px rgba(13,148,136,.25);}
        .level-view-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);display:flex;align-items:center;gap:7px;margin-bottom:9px;}
        .level-view-pills{display:flex;flex-wrap:wrap;gap:6px;}
        .level-view-pill{background:rgba(13,148,136,.15);color:#5eead4;border:1px solid rgba(13,148,136,.35);font-size:12px;font-weight:700;padding:4px 12px;border-radius:20px;}
        .level-view-empty{font-size:11.5px;color:#fcd34d;display:flex;align-items:center;gap:6px;line-height:1.5;}
        .level-view-hint{font-size:11px;color:var(--muted);margin-top:9px;}

        /* Toggle switch for notification preferences */
        .toggle-switch{position:relative;display:inline-block;width:42px;height:24px;flex-shrink:0;cursor:pointer;}
        .toggle-switch input{display:none;}
        .toggle-slider{position:absolute;inset:0;background:var(--border);border-radius:20px;transition:background .2s;}
        .toggle-slider::before{content:"";position:absolute;width:18px;height:18px;left:3px;top:3px;background:#fff;border-radius:50%;transition:transform .2s;}
        .toggle-switch input:checked + .toggle-slider{background:var(--teal);}
        .toggle-switch input:checked + .toggle-slider::before{transform:translateX(18px);}

        /* STUDENT MINI CARDS */
        .students-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:16px;}
        .student-mini-card{background:var(--mid);border:2px solid var(--border);border-radius:12px;padding:16px 12px;display:flex;flex-direction:column;align-items:center;gap:6px;text-align:center;}
        .student-mini-photo{width:60px;height:60px;border-radius:50%;object-fit:cover;border:2px solid var(--border);}
        .student-mini-photo-ph{width:60px;height:60px;border-radius:50%;background:var(--inner);border:2px solid var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:20px;}
        .student-mini-name{font-size:13px;font-weight:600;color:#fff;line-height:1.3;}
        .student-year-badge{font-size:10px;font-weight:700;color:#a5b4fc;background:rgba(99,102,241,.15);border:1px solid rgba(99,102,241,.3);border-radius:20px;padding:2px 10px;margin-top:2px;}

        /* ══ PEER EVALUATION — PHOTO CARD GRID ══ */
        .peer-card-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:16px;}
        .peer-card{background:var(--inner);border:1px solid var(--border);border-radius:12px;padding:20px 16px;display:flex;flex-direction:column;align-items:center;text-align:center;gap:4px;transition:border-color .2s,transform .2s;}
        .peer-card:hover{border-color:rgba(99,102,241,.4);transform:translateY(-2px);}
        .peer-card.is-done{opacity:.65;}
        .peer-card-photo{width:64px;height:64px;border-radius:50%;object-fit:cover;border:2px solid var(--border);margin-bottom:8px;}
        .peer-card-photo-ph{display:flex;align-items:center;justify-content:center;background:var(--mid);color:var(--muted);font-size:22px;}
        .peer-card-name{font-size:14px;font-weight:700;color:#fff;line-height:1.3;}
        .peer-card-desig{font-size:12px;color:var(--teal-hover);margin-bottom:12px;}
        .peer-card-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;width:100%;padding:9px 0;border-radius:8px;border:none;background:var(--teal);color:#fff;font-size:13px;font-weight:700;cursor:pointer;text-decoration:none;transition:background .2s;font-family:'DM Sans',sans-serif;}
        .peer-card-btn:hover{background:var(--teal-hover);}
        .peer-card-btn.done{background:rgba(255,255,255,.06);color:var(--muted);cursor:not-allowed;}

        /* ══ PEER EVALUATION — DROPDOWN SELECTOR ══ */
        .peer-select-card{display:flex;flex-direction:column;gap:14px;}
        .peer-select-row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start;}
        .peer-select-input{flex:1;min-width:240px;background:var(--inner);border:2px solid var(--border);color:var(--light);padding:13px 16px;border-radius:10px;font-size:14px;font-family:'DM Sans',sans-serif;outline:none;transition:border-color .2s,box-shadow .2s;appearance:none;-webkit-appearance:none;background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%23A0B3C6' stroke-width='2.5'><polyline points='6 9 12 15 18 9'/></svg>");background-repeat:no-repeat;background-position:right 14px center;padding-right:38px;}
        .peer-select-input:focus{border-color:var(--teal);box-shadow:0 0 0 3px rgba(99,102,241,.2);}
        .peer-select-input option:disabled{color:#5a6b80;}
        .peer-select-hint{font-size:12px;color:var(--muted);display:flex;align-items:center;gap:6px;}
        .peer-select-hint.warn{color:#fcd34d;}
        .btn-proceed-peer{background:var(--teal);color:#fff;border:none;padding:13px 26px;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:8px;transition:all .2s;font-family:'DM Sans',sans-serif;white-space:nowrap;}
        .btn-proceed-peer:hover:not(:disabled){background:var(--teal-hover);transform:translateY(-1px);}
        .btn-proceed-peer:disabled{opacity:.45;cursor:not-allowed;}

        /* PEER EVAL FORM */
        .eval-header-bar{background:var(--mid);border:1px solid var(--border);border-radius:14px;padding:20px 24px;margin-bottom:20px;display:flex;align-items:center;gap:16px;}
        .eval-photo-lg{width:60px;height:60px;border-radius:50%;object-fit:cover;border:2px solid var(--teal);}
        .eval-photo-ph{width:60px;height:60px;border-radius:50%;background:var(--inner);border:2px solid var(--teal);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:22px;}
        .eval-name{font-family:'Rajdhani',sans-serif;font-size:18px;font-weight:700;color:#fff;}
        .eval-desig{font-size:12px;color:var(--muted);}
        .scale-legend{position:sticky;top:60px;z-index:20;background:var(--mid);border:1px solid var(--border);border-radius:12px;padding:10px 14px;margin-bottom:20px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;box-shadow:var(--shadow);}
        .scale-legend-item{display:flex;align-items:center;gap:7px;background:var(--inner);border:1px solid var(--border);border-radius:8px;padding:5px 10px;}
        .scale-legend-num{width:20px;height:20px;border-radius:5px;background:var(--teal);color:#fff;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
        .scale-legend-lbl{font-size:12px;font-weight:600;color:var(--light);}
        .cat-group{margin-bottom:26px;}
        .cat-group-title{display:flex;align-items:center;gap:9px;font-family:'Rajdhani',sans-serif;font-size:15px;font-weight:700;color:var(--teal-hover);text-transform:uppercase;letter-spacing:1px;padding-bottom:8px;margin-bottom:14px;border-bottom:1px solid var(--border);}
        .q-card{background:var(--mid);border:1px solid var(--border);border-radius:12px;padding:18px 20px;margin-bottom:14px;}
        .q-no{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:5px;}
        .q-text{font-size:14px;font-weight:600;color:#fff;margin-bottom:14px;line-height:1.5;}
        .star-group{display:flex;gap:8px;flex-wrap:wrap;flex:1;}
        .star-btn{flex:1;min-width:78px;display:flex;flex-direction:column;align-items:center;gap:3px;padding:9px 6px;border-radius:8px;border:2px solid var(--border);background:var(--inner);color:var(--muted);cursor:pointer;transition:all .18s;font-family:'DM Sans',sans-serif;}
        .star-btn .sb-num{font-size:14px;font-weight:700;}
        .star-btn .sb-txt{font-size:10px;font-weight:600;letter-spacing:.3px;text-transform:uppercase;}
        .star-btn:hover,.star-btn.sel,.star-btn.selected{background:var(--teal);border-color:var(--teal);color:#fff;transform:translateY(-1px);}
        .comments-card{background:var(--mid);border:1px solid var(--border);border-radius:12px;padding:18px 20px;margin-bottom:18px;}
        .comments-box{width:100%;min-height:90px;resize:vertical;background:var(--inner);border:1px solid var(--border);border-radius:8px;color:var(--light);font-family:'DM Sans',sans-serif;font-size:13px;padding:12px 14px;outline:none;transition:border-color .2s;}
        .comments-box:focus{border-color:var(--teal);}
        .comments-box::placeholder{color:var(--muted);}
        /* required comment: red outlined box + inline message */
        .comment-req{color:#ef4444;font-weight:800;}
        .comments-card.invalid,.staff-eval-comments-card.invalid{border:1px solid #f87171!important;box-shadow:0 0 0 3px rgba(239,68,68,.12);}
        .comment-error{margin-top:10px;font-size:13px;font-weight:700;color:#f87171;line-height:1.4;}
        .comment-error[hidden]{display:none;}
        body.light-theme .comment-error,.staff-eval-modal .comment-error{color:#b91c1c;}
        .submit-row{background:var(--mid);border:1px solid var(--border);border-radius:12px;padding:18px 22px;display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;}
        .btn-submit{background:var(--teal);color:#fff;border:none;padding:12px 30px;border-radius:var(--radius);font-size:14px;font-weight:700;cursor:pointer;transition:all .2s;display:flex;align-items:center;gap:8px;font-family:'DM Sans',sans-serif;}
        .btn-submit:hover{background:var(--teal-hover);transform:translateY(-1px);}

        /* ══ STAFF EVALUATION ══ */
        .ea-eval-wrap{width:100%;max-width:1180px;}
        .ea-tab-row{display:flex;align-items:stretch;gap:10px;margin-bottom:18px;flex-wrap:wrap;}
        .ea-tab{display:flex;align-items:center;gap:9px;min-width:190px;padding:12px 16px;border:1px solid rgba(99,102,241,.25);border-radius:12px;background:rgba(99,102,241,.08);color:var(--muted);text-decoration:none;font-size:13px;font-weight:700;transition:all .2s ease;}
        .ea-tab i{font-size:15px;color:var(--teal-hover);}
        .ea-tab:hover{background:rgba(99,102,241,.14);border-color:rgba(129,140,248,.45);color:var(--light);transform:translateY(-1px);}
        .ea-tab.active{background:linear-gradient(135deg,rgba(99,102,241,.24),rgba(99,102,241,.12));border-color:var(--teal);box-shadow:0 8px 24px rgba(0,0,0,.18);color:#fff;}
        .ea-tab.active i{color:#fff;}
        .ea-tab .count{margin-left:auto;min-width:24px;height:24px;padding:0 7px;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;background:rgba(255,255,255,.08);color:var(--light);font-size:11px;font-weight:800;}
        .ea-tab.active .count{background:rgba(255,255,255,.16);color:#fff;}
        .ea-toolbar{display:flex;align-items:center;justify-content:space-between;gap:18px;background:var(--mid);border:1px solid var(--border);border-radius:14px;padding:18px 20px;margin-bottom:18px;box-shadow:var(--shadow);}
        .ea-toolbar > div:first-child{min-width:0;}
        .ea-status{display:inline-flex;align-items:center;gap:7px;white-space:nowrap;padding:8px 12px;border-radius:999px;background:rgba(250,204,21,.10);border:1px solid rgba(250,204,21,.18);color:#facc15;font-size:12px;font-weight:700;}
        .ea-status.done{background:rgba(34,197,94,.10);border-color:rgba(34,197,94,.2);color:#86efac;}
        .ea-table-card{background:var(--mid);border:1px solid var(--border);border-radius:14px;overflow:hidden;box-shadow:var(--shadow);}
        .ea-table{width:100%;border-collapse:collapse;}
        .ea-table th{padding:13px 16px;background:var(--inner);color:var(--muted);text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.9px;font-weight:800;}
        .ea-table td{padding:16px;border-top:1px solid var(--border);color:var(--light);font-size:13px;vertical-align:middle;}
        .ea-table tbody tr{transition:background .18s ease;}
        .ea-table tbody tr:hover{background:rgba(99,102,241,.05);}
        .ea-profile{display:flex;align-items:center;gap:12px;min-width:220px;}
        .ea-avatar{width:42px;height:42px;flex:0 0 42px;border-radius:50%;display:flex;align-items:center;justify-content:center;overflow:hidden;background:var(--inner);border:1px solid rgba(129,140,248,.26);color:var(--teal-hover);}
        img.ea-avatar{object-fit:cover;display:block;}
        .ea-name{font-size:14px;font-weight:700;color:#fff;line-height:1.25;}
        .ea-sub{font-size:11px;color:var(--muted);margin-top:3px;}
        .ea-role-pill{display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;background:rgba(124,58,237,.12);border:1px solid rgba(124,58,237,.22);color:#c4b5fd;font-size:11px;font-weight:800;}
        .ea-evaluate-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:8px 13px;border-radius:8px;background:var(--teal);border:1px solid var(--teal);color:#fff;text-decoration:none;font-size:12px;font-weight:700;white-space:nowrap;transition:all .2s;}
        .ea-evaluate-btn:hover{background:var(--teal-hover);border-color:var(--teal-hover);transform:translateY(-1px);}
        .ea-evaluate-btn.done{background:rgba(34,197,94,.10);border-color:rgba(34,197,94,.2);color:#86efac;cursor:default;}
        .ea-evaluate-btn.done:hover{transform:none;}
        body.light-theme .ea-tab{background:rgba(124,58,237,.06);color:#5b21b6;border-color:rgba(124,58,237,.20);}
        body.light-theme .ea-tab.active{background:rgba(124,58,237,.12);border-color:#7c3aed;color:#4c1d95;}
        body.light-theme .ea-tab.active i{color:#5b21b6;}
        @media(max-width:760px){
            .ea-toolbar{align-items:flex-start;flex-direction:column;}
            .ea-tab{flex:1 1 220px;min-width:0;}
            .ea-table-card{overflow-x:auto;}
            .ea-table{min-width:760px;}
        }


        /* RESPONSIVE */
        @media(max-width:900px){.sidebar{transform:translateX(-100%);}.sidebar.open{transform:translateX(0);}.top-nav{left:0;}.main{margin-left:0;}.hamburger{display:block;}}
        @media(max-width:600px){.main{padding:16px;}.stats-grid{grid-template-columns:1fr 1fr;}.students-grid{grid-template-columns:repeat(auto-fill,minmax(130px,1fr));}.scale-legend{top:0;}.star-btn{min-width:60px;}.custom-role-row{flex-direction:column;}.role-chips{gap:6px;}.eval-group-grid{grid-template-columns:1fr;}.peer-select-row{flex-direction:column;}.btn-proceed-peer{width:100%;justify-content:center;}}
        @media(max-width:1024px){.stats-grid{grid-template-columns:repeat(2,1fr);}}
        
.settings-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-top:18px;}
.settings-card{background:var(--inner);border:1px solid var(--border);border-radius:14px;padding:18px;}
.settings-card.full{grid-column:1/-1;}
.settings-card-head{display:flex;align-items:center;gap:11px;margin-bottom:14px;}
.settings-card-head .sicon{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:rgba(13,148,136,.12);color:var(--teal-hover);}
.settings-card-head h3{margin:0;color:var(--light);font-size:15px;}
.settings-card-head p{margin:3px 0 0;color:var(--muted);font-size:11.5px;}
.setting-row{display:flex;align-items:center;justify-content:space-between;gap:18px;padding:12px 0;border-top:1px solid var(--border);}
.setting-row:first-of-type{border-top:0;}
.setting-row strong{display:block;color:var(--light);font-size:13px;}
.setting-row span{display:block;color:var(--muted);font-size:11px;margin-top:3px;line-height:1.45;}
.setting-toggle{position:relative;width:44px;height:24px;flex:0 0 44px;}
.setting-toggle input{display:none;}
.setting-toggle .slider{position:absolute;inset:0;border-radius:20px;background:var(--border);cursor:pointer;transition:.2s;}
.setting-toggle .slider:before{content:'';position:absolute;width:18px;height:18px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.2s;}
.setting-toggle input:checked+.slider{background:var(--teal);}
.setting-toggle input:checked+.slider:before{transform:translateX(20px);}
.settings-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:16px;}
.settings-save{border:0;border-radius:9px;background:var(--teal);color:#fff;font-weight:700;padding:10px 16px;cursor:pointer;}
.settings-save:hover{background:var(--teal-hover);}
.account-facts{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;}
.account-fact{background:var(--mid);border:1px solid var(--border);border-radius:10px;padding:12px;}
.account-fact label{display:block;font-size:10px;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);margin-bottom:4px;}
.account-fact b{color:var(--light);font-size:12.5px;}
.account-name-view{display:flex;align-items:center;justify-content:space-between;gap:8px;}
.account-name-edit{border:0;background:transparent;color:var(--teal-hover);cursor:pointer;padding:4px 6px;border-radius:6px;font-size:12px;}
.account-name-edit:hover{background:rgba(13,148,136,.14);}
.account-name-form input[type=text]{width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid var(--border);background:var(--inner);color:var(--light);border-radius:8px;font-size:12.5px;outline:none;}
.account-name-form input[type=text]:focus{border-color:var(--teal);}
.account-name-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:8px;}
.account-name-save,.account-name-cancel{border-radius:8px;font-size:11.5px;font-weight:700;padding:6px 12px;cursor:pointer;}
.account-name-save{border:0;background:var(--teal);color:#fff;}
.account-name-save:hover{background:var(--teal-hover);}
.account-name-cancel{background:transparent;color:var(--muted);border:1px solid var(--border);}
@media(max-width:760px){.settings-grid{grid-template-columns:1fr}.settings-card.full{grid-column:auto}.account-facts{grid-template-columns:1fr}}

        .settings-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-top:18px;}
        .settings-card{background:var(--inner);border:1px solid var(--border);border-radius:14px;padding:18px;}
        .settings-card.full{grid-column:1/-1;}
        .settings-card-head{display:flex;align-items:center;gap:11px;margin-bottom:14px;}
        .settings-card-head .sicon{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:rgba(99,102,241,.12);color:var(--teal-hover);}
        .settings-card-head h3{margin:0;color:var(--light);font-size:15px;}
        .settings-card-head p{margin:3px 0 0;color:var(--muted);font-size:11.5px;}
        .setting-row{display:flex;align-items:center;justify-content:space-between;gap:18px;padding:12px 0;border-top:1px solid var(--border);}
        .setting-row:first-of-type{border-top:0;} .setting-row strong{display:block;color:var(--light);font-size:13px;}
        .setting-row span{display:block;color:var(--muted);font-size:11px;margin-top:3px;line-height:1.45;}
        .setting-toggle{position:relative;width:44px;height:24px;flex:0 0 44px;} .setting-toggle input{display:none;}
        .setting-toggle .slider{position:absolute;inset:0;border-radius:20px;background:var(--border);cursor:pointer;transition:.2s;}
        .setting-toggle .slider:before{content:'';position:absolute;width:18px;height:18px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.2s;}
        .setting-toggle input:checked+.slider{background:var(--teal);} .setting-toggle input:checked+.slider:before{transform:translateX(20px);}
        .settings-actions{display:flex;justify-content:flex-end;margin-top:16px;} .settings-save{border:0;border-radius:9px;background:var(--teal);color:#fff;font-weight:700;padding:10px 16px;cursor:pointer;text-decoration:none;}
        .settings-save:hover{background:var(--teal-hover);} .account-facts{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;}
        .account-fact{background:var(--mid);border:1px solid var(--border);border-radius:10px;padding:12px;}
        .account-fact label{display:block;font-size:10px;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);margin-bottom:4px;} .account-fact b{color:var(--light);font-size:12.5px;}
        @media(max-width:760px){.settings-grid{grid-template-columns:1fr}.settings-card.full{grid-column:auto}.account-facts{grid-template-columns:1fr}}

/* Shared compact questionnaire tables (Staff + Peer evaluations) */
.compact-eval-table-wrap{background:var(--mid);border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:16px;}
.compact-eval-table{width:100%;border-collapse:collapse;table-layout:fixed;}
.compact-eval-table th{background:rgba(255,255,255,.035);color:var(--muted);font-size:10px;font-weight:800;letter-spacing:.7px;text-transform:uppercase;text-align:center;padding:11px 7px;border-bottom:1px solid var(--border)}
.compact-eval-table th:first-child{text-align:left;width:auto;padding-left:15px}.compact-eval-table th:not(:first-child){width:60px}
.compact-eval-table td{padding:10px 7px;border-bottom:1px solid rgba(255,255,255,.06);vertical-align:middle;text-align:center}.compact-eval-table tr:last-child td{border-bottom:none}.compact-eval-table td:first-child{text-align:left;padding-left:15px;padding-right:12px}
.compact-eval-qtext{font-size:13px;color:var(--light);line-height:1.45}.compact-eval-qno{color:var(--teal-hover);font-weight:800;margin-right:7px}.compact-eval-rating{position:relative;display:flex;align-items:center;justify-content:center}.compact-eval-rating input{position:absolute;left:50%;top:50%;width:1px;height:1px;margin:0;padding:0;transform:translate(-50%,-50%);opacity:0;pointer-events:none}.compact-eval-rating input:focus-visible+label{outline:2px solid var(--teal-hover);outline-offset:2px}.compact-eval-rating label{width:38px;height:32px;display:flex;align-items:center;justify-content:center;padding:0;border-radius:7px;border:1px solid var(--border);background:var(--inner);color:var(--muted);font-size:13px;font-weight:800;cursor:pointer;transition:.15s ease;user-select:none}.compact-eval-rating label:hover{border-color:var(--teal);background:rgba(20,184,166,.08);color:var(--light)}.compact-eval-rating input:checked+label{background:var(--teal);border-color:var(--teal);color:#fff;box-shadow:0 0 0 2px rgba(20,184,166,.12)}
@media(max-width:760px){.compact-eval-table th:not(:first-child){width:48px}.compact-eval-rating label{width:32px;height:30px}.compact-eval-qtext{font-size:12px}}



/* ── Light theme refinement: white surface + coordinated text/tab/icon states ── */
body.light-theme .nav-section-label{
    color:#4F46E5;
    text-shadow:none;
}
body.light-theme .nav-link{
    color:#52677A;
}
body.light-theme .nav-link:hover{
    background:rgba(15,31,61,.055);
    color:#16263B;
}
body.light-theme .nav-link.active{
    background:rgba(99,102,241,.11);
    color:#4F46E5;
    font-weight:700;
}
body.light-theme .nav-link.active i{
    color:#4F46E5;
}
body.light-theme .nav-link .badge{
    background:#6366F1;
    color:#FFFFFF;
}
body.light-theme .period-badge{
    background:rgba(99,102,241,.09);
    border-color:rgba(99,102,241,.22);
    color:#4F46E5;
}
body.light-theme .sidebar-sub{
    color:#4F46E5;
}
body.light-theme .brand-avatar{
    border-color:#6366F1;
    box-shadow:0 0 10px rgba(99,102,241,.16);
    background:#EEF2F8;
}
body.light-theme .brand-avatar .brand-initials{
    color:#4F46E5;
}
body.light-theme .profile-dd-btn{
    color:#16263B;
}
body.light-theme .profile-dd-btn:hover{
    background:rgba(15,31,61,.055);
}
body.light-theme .dd-appearance-val{
    color:#52677A;
    background:#EEF2F8;
}
body.light-theme .ea-tab{
    background:rgba(99,102,241,.06);
    color:#4338CA;
    border-color:rgba(99,102,241,.20);
}
body.light-theme .ea-tab i{
    color:#4F46E5;
}
body.light-theme .ea-tab:hover{
    background:rgba(99,102,241,.10);
    border-color:rgba(99,102,241,.30);
    color:#3730A3;
}
body.light-theme .ea-tab.active{
    background:rgba(99,102,241,.12);
    border-color:#6366F1;
    color:#3730A3;
    box-shadow:0 6px 20px rgba(15,31,61,.08);
}
body.light-theme .ea-tab.active i{
    color:#4F46E5;
}
body.light-theme .ea-tab .count{
    background:rgba(99,102,241,.10);
    color:#4338CA;
}
body.light-theme .ea-tab.active .count{
    background:rgba(99,102,241,.16);
    color:#3730A3;
}
/* Required-rating feedback */
.eval-validation-alert{display:none;margin:0 0 12px;padding:11px 14px;border-radius:9px;border:1px solid rgba(240,84,84,.34);background:rgba(240,84,84,.10);color:#fca5a5;font-size:13px;line-height:1.5;}
.eval-validation-alert.show{display:block;}
.eval-validation-alert strong{color:#fff;}
.eval-question-row.eval-unanswered{background:rgba(240,84,84,.06);}
.eval-question-row.eval-unanswered td:first-child{border-left:3px solid #f87171;}
.eval-progress{display:inline-flex;align-items:center;padding:6px 10px;margin-right:auto;border-radius:7px;background:rgba(255,255,255,.05);color:var(--muted);font-size:12px;font-weight:600;}
.eval-progress.is-complete{color:#86efac;background:rgba(34,197,94,.10);}
body.light-theme .eval-modal,
body.light-theme .eval-item,
body.light-theme .ea-table-card,
body.light-theme .compact-eval-table-wrap{
    box-shadow:0 6px 22px rgba(15,31,61,.055);
}
body.light-theme .peer-card:hover{
    border-color:rgba(99,102,241,.24);
}
body.light-theme .peer-card.is-done{
    background:#F8FAFD;
}
body.light-theme .peer-select-input{
    background-color:#FFFFFF;
    color:#16263B;
}
body.light-theme .peer-select-input::placeholder{
    color:#7A8B9B;
}
body.light-theme .compact-eval-table th{
    background:#EEF2F8;
    color:#5B7186;
}
body.light-theme .compact-eval-table td{
    border-bottom-color:rgba(15,31,61,.07);
}
body.light-theme .btn-save-photo,
body.light-theme .btn-proceed-peer{
    color:#FFFFFF;
}

    
/* Core light surfaces — Staff */
body.light-theme,
body.light-theme .main{background:#F3F6FB;color:#16263B;}
body.light-theme .sidebar,
body.light-theme .top-nav{background:#FFFFFF;}
body.light-theme .nav-page-title,
body.light-theme .sidebar-title,
body.light-theme .welcome-bar h2,
body.light-theme .section-title,
body.light-theme .stat-box .stat-value,
body.light-theme .peer-name,
body.light-theme .eval-name{color:#16263B;}

/* ── Final Staff white + green role theme ────────────────────────────── */
body.light-theme{
    --accent:#16A34A;
    --hover:#15803D;
    --teal:#16A34A;
    --teal-hover:#15803D;
    --dark:#F3F6FB;
    --mid:#FFFFFF;
    --inner:#F8FAFD;
    --light:#16263B;
    --muted:#52677A;
    --border:rgba(15,31,61,.10);
    --shadow:0 6px 22px rgba(15,31,61,.055);
}
body.light-theme .nav-section-label{color:#15803D;text-shadow:none;}
body.light-theme .nav-link{color:#52677A;}
body.light-theme .nav-link:hover{background:rgba(22,163,74,.055);color:#16263B;}
body.light-theme .nav-link.active{background:rgba(22,163,74,.10);color:#15803D;border-left:3px solid #16A34A;padding-left:9px;}
body.light-theme .nav-link.active i{color:#16A34A;}
body.light-theme .nav-link .badge{background:#16A34A;color:#fff;}
body.light-theme .period-badge{background:rgba(22,163,74,.08);border-color:rgba(22,163,74,.20);color:#15803D;}
body.light-theme .sidebar-sub{color:#15803D;}
body.light-theme .brand-avatar{border-color:#16A34A;box-shadow:0 0 10px rgba(22,163,74,.16);background:#EEF2F8;}
body.light-theme .brand-avatar .brand-initials{color:#15803D;}
body.light-theme .welcome-bar{background:linear-gradient(135deg,#FFFFFF 0%,rgba(22,163,74,.055) 100%);border-color:rgba(22,163,74,.16);}
body.light-theme .welcome-bar h2{color:#16263B;}
body.light-theme .welcome-bar strong{color:#16A34A;}
body.light-theme .score-chip{background:rgba(22,163,74,.07);border-color:rgba(22,163,74,.18);}
body.light-theme .score-chip .sc-val{color:#15803D;}
body.light-theme .section-card-title i,
body.light-theme .section-title i{color:#16A34A !important;}
body.light-theme .ea-tab{background:rgba(22,163,74,.06);color:#166534;border-color:rgba(22,163,74,.18);}
body.light-theme .ea-tab i{color:#15803D;}
body.light-theme .ea-tab:hover{background:rgba(22,163,74,.10);border-color:rgba(22,163,74,.28);color:#166534;}
body.light-theme .ea-tab.active{background:rgba(22,163,74,.12);border-color:#16A34A;color:#166534;box-shadow:0 6px 20px rgba(15,31,61,.08);}
body.light-theme .ea-tab.active i{color:#15803D;}
body.light-theme .ea-tab .count{background:rgba(22,163,74,.10);color:#166534;}
body.light-theme .ea-tab.active .count{background:rgba(22,163,74,.16);color:#166534;}
body.light-theme .ea-export-btn{background:rgba(22,163,74,.07);color:#166534;border-color:rgba(22,163,74,.22);}
body.light-theme .ea-table tr:hover{background:rgba(22,163,74,.04);}
body.light-theme .ea-avatar{color:#166534;}
body.light-theme .ea-role-pill{background:rgba(22,163,74,.08);color:#166534;border-color:rgba(22,163,74,.18);}
body.light-theme .peer-card:hover{border-color:rgba(22,163,74,.24);box-shadow:0 8px 24px rgba(22,163,74,.08);}
body.light-theme .peer-card-btn,
body.light-theme .btn-save-photo,
body.light-theme .btn-proceed-peer,
body.light-theme .btn-submit{background:#16A34A;}
body.light-theme .compact-eval-qno{color:#15803D;}
body.light-theme .compact-eval-rating label:hover{border-color:#16A34A;background:rgba(22,163,74,.07);color:#16263B;}
body.light-theme .compact-eval-rating input:checked+label{background:#16A34A;border-color:#16A34A;box-shadow:0 0 0 2px rgba(22,163,74,.12);}
body.light-theme .profile-hero{background:linear-gradient(135deg,#FFFFFF 0%,rgba(22,163,74,.06) 100%);border-color:rgba(22,163,74,.18);}
body.light-theme .profile-hero-avatar{border-color:#16A34A;box-shadow:0 0 18px rgba(22,163,74,.20);color:#15803D;}
body.light-theme .profile-desig-badge{background:rgba(22,163,74,.08);border-color:rgba(22,163,74,.20);color:#166534;}
body.light-theme .suggestion-chip:hover{background:rgba(22,163,74,.08);border-color:rgba(22,163,74,.24);color:#166534;}
body.light-theme .suggestion-chip.is-current{background:rgba(22,163,74,.11);border-color:#16A34A;color:#166534;}
body.light-theme .profile-dd-btn:hover .profile-dd-icon{background:rgba(22,163,74,.12);color:#15803D;}
body.light-theme .notif-item.unread{background:rgba(22,163,74,.05);}
body.light-theme .settings-card-head .sicon{background:rgba(22,163,74,.09);color:#15803D;}

        
/* LIGHT CONTENT + DARK ROLE SIDEBAR */
body.light-theme .sidebar{
    background:#172A45 !important;
    border-right-color:rgba(255,255,255,.08) !important;
}
body.light-theme .sidebar-brand{
    background:#172A45 !important;
    border-bottom-color:rgba(255,255,255,.08) !important;
}
body.light-theme .sidebar-brand:hover{
    background:#1B3455 !important;
}
body.light-theme .sidebar-title{
    color:#FFFFFF !important;
}
body.light-theme .sidebar-sub{
    color:#4ADE80 !important;
}
body.light-theme .sidebar-caret{
    color:#A0B3C6 !important;
}
body.light-theme .brand-avatar{
    border-color:#16A34A !important;
    background:#0F1F3D !important;
    box-shadow:0 0 12px rgba(22,163,74,.28) !important;
}
body.light-theme .brand-avatar .brand-initials{
    color:#4ADE80 !important;
}
body.light-theme .sidebar-profile-dropdown{
    background:#0F1F3D !important;
    border-color:rgba(255,255,255,.08) !important;
}
body.light-theme .profile-dd-btn{
    color:#E0E6F0 !important;
}
body.light-theme .profile-dd-btn:hover{
    background:rgba(255,255,255,.06) !important;
}
body.light-theme .profile-dd-btn i{
    color:#A0B3C6 !important;
}
body.light-theme .dd-appearance-val{
    background:rgba(255,255,255,.08) !important;
    color:#A0B3C6 !important;
}
body.light-theme .nav-section-label{
    color:#86EFAC !important;
    text-shadow:0 1px 8px rgba(22,163,74,.12) !important;
}
body.light-theme .nav-link{
    color:#A0B3C6 !important;
}
body.light-theme .nav-link:hover{
    background:rgba(255,255,255,.06) !important;
    color:#FFFFFF !important;
}
body.light-theme .nav-link.active{
    background:rgba(22,163,74,.18) !important;
    color:#FFFFFF !important;
    border-left:3px solid #16A34A !important;
}
body.light-theme .nav-link.active i{
    color:#22C55E !important;
}
body.light-theme .nav-link .badge{
    background:#16A34A !important;
    color:#FFFFFF !important;
}
body.light-theme .nav-icon-blue{color:#3B82F6 !important;}
body.light-theme .nav-icon-purple{color:#8B5CF6 !important;}
body.light-theme .nav-icon-green{color:#22C55E !important;}
body.light-theme .nav-icon-orange{color:#F97316 !important;}
body.light-theme .profile-dd-icon.dd-icon-blue{color:#60A5FA !important;}
body.light-theme .profile-dd-icon.dd-icon-amber{color:#FBBF24 !important;}
body.light-theme .profile-dd-icon.dd-icon-purple{color:#A78BFA !important;}
body.light-theme .sidebar-footer{
    border-top-color:rgba(255,255,255,.08) !important;
    background:#172A45 !important;
}
body.light-theme .btn-logout-side{
    background:rgba(240,84,84,.08) !important;
    border-color:rgba(240,84,84,.30) !important;
    color:#FCA5A5 !important;
}
body.light-theme .btn-logout-side:hover{
    background:rgba(240,84,84,.16) !important;
}

/* ================================================================
   REFERENCE-STYLE LIGHT DASHBOARD — STAFF
   Dark role sidebar + airy white workspace + green staff accent.
   ================================================================ */
body.light-theme{
    --light:#13263F;
    --muted:#536A82;
    --dark:#F4F7FB;
    --mid:#FFFFFF;
    --inner:#F7F9FC;
    --border:#DFE6EE;
    --accent:#16A34A;
    --hover:#15803D;
    --teal:#16A34A;
    --teal-hover:#15803D;
    --teal-light:rgba(22,163,74,.10);
    --shadow:0 8px 26px rgba(18,43,73,.06);
}
body.light-theme .sidebar{
    background:#081B2E !important;border-right:1px solid rgba(255,255,255,.08) !important;
}
body.light-theme .sidebar-brand,
body.light-theme .sidebar-footer{background:#081B2E !important;}
body.light-theme .sidebar-brand{border-bottom-color:rgba(255,255,255,.08) !important;}
body.light-theme .sidebar-title{color:#fff !important;}
body.light-theme .sidebar-sub{color:#4ADE80 !important;}
body.light-theme .nav-section-label{color:#86EFAC !important;}
body.light-theme .nav-link{color:#B6C5D6 !important;}
body.light-theme .nav-link:hover{background:rgba(255,255,255,.06) !important;color:#fff !important;}
body.light-theme .nav-link.active{background:rgba(22,163,74,.16) !important;color:#fff !important;border-left:3px solid #16A34A !important;}
body.light-theme .nav-link.active i{color:#4ADE80 !important;}
body.light-theme .sidebar-caret{color:#AFC0D2 !important;}
body.light-theme .brand-avatar{border-color:#16A34A !important;box-shadow:0 0 14px rgba(22,163,74,.25) !important;}
body.light-theme .sidebar-profile-dropdown{background:#0E2944 !important;border-color:rgba(255,255,255,.08) !important;}
body.light-theme .profile-dd-btn{color:#E6EDF5 !important;}
body.light-theme .profile-dd-btn:hover{background:rgba(255,255,255,.06) !important;}
body.light-theme .dd-appearance-val{background:rgba(255,255,255,.08) !important;color:#B6C5D6 !important;}
body.light-theme .sidebar-footer{border-top-color:rgba(255,255,255,.08) !important;}
body.light-theme .btn-logout-side{background:rgba(248,113,113,.08) !important;color:#FCA5A5 !important;border-color:rgba(248,113,113,.28) !important;}
body.light-theme .btn-logout-side:hover{background:rgba(248,113,113,.15) !important;}
body.light-theme .top-nav{background:#FFFFFF !important;border-bottom-color:#DEE6EF !important;box-shadow:0 2px 14px rgba(15,34,55,.04) !important;}
body.light-theme .nav-page-title{color:#13263F !important;}
body.light-theme .period-badge{background:rgba(22,163,74,.07) !important;border-color:rgba(22,163,74,.20) !important;color:#15803D !important;}
body.light-theme .main{background:#F4F7FB !important;color:#13263F !important;padding:24px 28px 42px !important;}

body.light-theme .dashboard-shell{
    background:linear-gradient(180deg,#FFFFFF 0%,#F8FAFD 100%);
    border:1px solid #E1E8F0;border-radius:22px;padding:22px 28px 180px;
    box-shadow:0 10px 28px rgba(19,38,63,.045);min-height:calc(100vh - 114px);
}
body.light-theme .evaluation-schedule-card{background:#FFFFFF;border-color:rgba(30,82,144,.13);box-shadow:0 5px 18px rgba(30,82,144,.04);}
body.light-theme .schedule-summary-item strong{color:#10243E;}
body.light-theme .schedule-message strong{color:#10243E;}
body.light-theme .dashboard-intro{margin:2px 2px 18px;}
body.light-theme .dashboard-kicker{color:#D97706;font-size:11px;font-weight:800;letter-spacing:1.2px;text-transform:uppercase;display:flex;align-items:center;gap:7px;margin-bottom:5px;}
body.light-theme .dashboard-intro h1{margin:0;font-family:'Rajdhani',sans-serif;font-size:28px;line-height:1.05;font-weight:800;letter-spacing:.2px;color:#10243E;}
body.light-theme .dashboard-intro p{margin-top:7px;font-size:13px;color:#5C7187;}
body.light-theme .dashboard-intro .status-pill{display:inline-flex;align-items:center;gap:7px;margin-top:14px;padding:6px 12px;border-radius:20px;background:#E8FFF1;border:1px solid #91E7B2;color:#168447;font-size:11.5px;font-weight:700;}
body.light-theme .welcome-bar{background:linear-gradient(135deg,#FFFFFF 0%,#F4FBF6 100%) !important;border:1px solid rgba(22,163,74,.20) !important;border-radius:17px !important;padding:24px 28px !important;margin-bottom:20px !important;box-shadow:0 5px 18px rgba(22,163,74,.05);}
body.light-theme .welcome-bar h2{color:#11263F !important;font-size:23px !important;}
body.light-theme .welcome-bar p{color:#5E7388 !important;}
body.light-theme .welcome-bar p strong{color:#15803D !important;}
body.light-theme .score-chip{background:#EDF9F1 !important;border:1px solid #BFE6CA !important;border-radius:15px !important;max-width:340px;min-width:230px;padding:16px 26px !important;}
body.light-theme .score-chip .big{color:#15803D !important;font-size:39px !important;}
body.light-theme .score-chip .lbl{color:#66798D !important;}
body.light-theme .stats-grid{gap:14px !important;margin-bottom:20px !important;}
body.light-theme .stat-box{position:relative;background:#FFFFFF !important;border:1px solid #E0E7EF !important;border-radius:15px !important;padding:18px 20px 20px !important;box-shadow:0 6px 18px rgba(20,42,67,.045) !important;overflow:hidden;}
body.light-theme .stat-box::before{content:'';position:absolute;left:0;right:0;top:0;height:3px;background:#16A34A;}
body.light-theme .stat-box:nth-child(2)::before{background:#10B981;}
body.light-theme .stat-box:nth-child(3)::before{background:#F59E0B;}
body.light-theme .stat-box:nth-child(4)::before{background:#3B82F6;}
body.light-theme .stat-box-icon{width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;margin-bottom:12px;font-size:15px;}
body.light-theme .stat-box-icon.green{background:#E5F9ED;color:#16A34A;}
body.light-theme .stat-box-icon.mint{background:#E7FAF0;color:#10B981;}
body.light-theme .stat-box-icon.gold{background:#FFF4D8;color:#D88900;}
body.light-theme .stat-box-icon.sky{background:#E5F0FF;color:#3478E5;}
body.light-theme .stat-box-lbl{color:#5E7388 !important;font-size:10.5px !important;letter-spacing:.9px !important;}
body.light-theme .stat-box-val{color:#12263F !important;font-size:28px !important;}
body.light-theme .stat-box-val.teal{color:#15803D !important;}
body.light-theme .stat-box-val.gold{color:#D88900 !important;}
body.light-theme .section-card{background:#FFFFFF !important;border:1px solid #E1E8F0 !important;border-radius:16px !important;padding:20px 22px !important;margin-bottom:18px !important;box-shadow:0 5px 18px rgba(20,42,67,.04) !important;}
body.light-theme .section-card-title{color:#13263F !important;font-size:17px !important;}
body.light-theme .section-card-title i{color:#16A34A !important;}
body.light-theme .btn-view-all-evals{background:#EDF9F1 !important;border-color:#C6E8D0 !important;color:#15803D !important;border-radius:10px !important;padding:12px 15px !important;}
body.light-theme .btn-view-all-evals:hover{background:#E5F6EA !important;}
body.light-theme .sub-item{background:#F8FAFD !important;border:1px solid #E1E8F0 !important;}
body.light-theme .sub-meta{color:#6E8093 !important;}
body.light-theme .btn-view-details{background:#EDF9F1 !important;border-color:#C6E8D0 !important;color:#15803D !important;}
body.light-theme .btn-view-details:hover{background:#E2F5E8 !important;}
body.light-theme .profile-hero{background:linear-gradient(135deg,#FFFFFF 0%,#F1FBF4 100%) !important;border-color:#C8E8D0 !important;}
body.light-theme .profile-hero-name{color:#11263F !important;}
body.light-theme .profile-hero-desig{background:#EDFAF1 !important;border-color:#C8E8D0 !important;color:#15803D !important;}
body.light-theme .role-card{background:#FFFFFF !important;border-color:#CDE6D3 !important;}
body.light-theme .role-card-title{color:#13263F !important;}
body.light-theme .role-card::before{background:linear-gradient(90deg,#16A34A,#4ADE80) !important;}
body.light-theme .role-chip{background:#F7F9FC !important;border-color:#DDE5EE !important;color:#51677F !important;}
body.light-theme .role-chip:hover,.role-chip.is-current{background:#EDFAF1 !important;border-color:#AFE0BC !important;color:#15803D !important;}
body.light-theme .level-view-wrap{background:#F8FAFD !important;border-color:#DDE6EF !important;}
body.light-theme .level-view-pill{background:#E9F8EE !important;color:#15803D !important;border-color:#C5E7CE !important;}
@media(max-width:900px){body.light-theme .dashboard-shell{padding:18px 16px 130px;border-radius:16px;}body.light-theme .main{padding:18px 14px 28px !important;}}

/* Settings is now a first-class sidebar destination. */
.settings-page-head{
    display:flex;align-items:flex-start;justify-content:space-between;gap:20px;
    margin:0 0 18px;padding:0 2px;
}
.settings-page-kicker{
    display:flex;align-items:center;gap:7px;color:var(--teal);
    font-size:11px;font-weight:800;letter-spacing:1px;text-transform:uppercase;
    margin-bottom:5px;
}
.settings-page-title{
    margin:0;color:var(--light);font-family:'Rajdhani',sans-serif;
    font-size:28px;font-weight:700;
}
.settings-page-subtitle{margin:3px 0 0;color:var(--muted);font-size:12.5px;line-height:1.5;}
.settings-appearance-current{
    display:inline-flex;align-items:center;gap:7px;padding:8px 11px;border-radius:999px;
    background:rgba(37,99,235,.08);border:1px solid rgba(37,99,235,.18);
    color:#1D4ED8;font-size:11px;font-weight:700;white-space:nowrap;
}
.appearance-choice-row{display:flex;gap:10px;flex-wrap:wrap;margin-top:2px;}
.appearance-choice{
    border:1px solid var(--border);background:var(--mid);color:var(--light);
    border-radius:10px;padding:10px 14px;font:700 12px 'DM Sans',sans-serif;cursor:pointer;
    display:inline-flex;align-items:center;gap:8px;transition:.18s ease;
}
.appearance-choice:hover{border-color:var(--teal);color:var(--teal-hover);}
.appearance-choice.active{background:rgba(37,99,235,.10);border-color:rgba(37,99,235,.28);color:#1D4ED8;}
body.light-theme .settings-card{background:#FFFFFF;border-color:rgba(15,31,61,.10);box-shadow:0 5px 18px rgba(30,82,144,.06);}
body.light-theme .settings-card-head h3{color:#16263B;}
body.light-theme .settings-card-head p{color:#5B7186;}
body.light-theme .setting-row{border-top-color:rgba(15,31,61,.08);}
body.light-theme .setting-row strong{color:#16263B;}
body.light-theme .setting-row span{color:#5B7186;}
body.light-theme .account-fact{background:#F8FAFC;border-color:rgba(15,31,61,.08);}
body.light-theme .account-fact label{color:#5B7186;}
body.light-theme .account-fact b{color:#16263B;}
body.light-theme .account-name-form input[type=text]{background:#fff;color:#16263B;border-color:rgba(15,31,61,.18);}
body.light-theme .account-name-cancel{color:#5B7186;border-color:rgba(15,31,61,.18);}
body.light-theme .settings-page-title{color:#16263B;}
body.light-theme .settings-page-subtitle{color:#5B7186;}
body.light-theme .appearance-choice{background:#FFFFFF;color:#294765;border-color:#D8E3EF;}

/* Unified Student-style sidebar structure for Staff; existing role accent retained. */
:root { --sidebar-w:248px; --role-accent:#16A34A; --role-accent-light:#4ADE80; }
.sidebar { width:var(--sidebar-w) !important; background:#0A192F !important; border-right:1px solid #172A45 !important; box-shadow:6px 0 20px rgba(0,0,0,.16); }
.sidebar-brand.portal-brand { min-height:68px; padding:14px 17px !important; border-bottom:1px solid rgba(255,255,255,.08) !important; display:flex; align-items:center; gap:11px; cursor:default; }
.portal-brand-logo { width:38px; height:38px; flex:0 0 38px; border-radius:11px; display:flex; align-items:center; justify-content:center; overflow:hidden; background:#0F1F3D; border:1px solid #16A34A; box-shadow:0 0 14px rgba(22,163,74,.18); }
.portal-brand-logo img { width:100%; height:100%; object-fit:cover; display:block; }
.portal-brand-copy { min-width:0; display:flex; flex-direction:column; line-height:1.2; }
.portal-brand-copy strong { font-family:'Rajdhani',sans-serif; font-size:15px; font-weight:700; color:#F8FAFC; letter-spacing:.25px; }
.portal-brand-copy span { margin-top:3px; font-size:10px; color:#8FA6BF; font-weight:600; letter-spacing:.15px; white-space:nowrap; }
.portal-sidebar-profile { padding:18px 16px 17px; text-align:center; border-bottom:1px solid rgba(255,255,255,.08); }
.portal-profile-avatar-wrap { margin:0 auto 11px; display:flex; justify-content:center; }
.portal-profile-avatar { width:64px; height:64px; border-radius:50%; object-fit:cover; border:2px solid #16A34A; background:#0F1F3D; box-shadow:0 0 15px rgba(22,163,74,.18); display:flex; align-items:center; justify-content:center; }
.portal-profile-fallback { color:#4ADE80; font-family:'Rajdhani',sans-serif; font-size:17px; font-weight:700; }
.portal-profile-name { color:#F8FAFC; font-size:13px; line-height:1.35; font-weight:700; word-break:break-word; }
.portal-profile-role { margin-top:3px; color:#8FA6BF; font-size:10px; text-transform:uppercase; letter-spacing:.7px; font-weight:700; line-height:1.35; }
.portal-sidebar-nav { flex:1; padding:16px 10px !important; overflow-y:auto; }
.portal-sidebar-nav .nav-section-label { padding:0 8px !important; margin:0 0 7px !important; font-size:9.5px !important; font-weight:800 !important; letter-spacing:1.25px !important; text-align:left !important; color:#8FA6BF !important; text-shadow:none !important; }
.portal-sidebar-nav .nav-section-label.sidebar-section-secondary { margin-top:17px !important; }
.portal-sidebar-nav .nav-link { min-height:40px; margin:2px 2px !important; padding:9px 11px !important; border-left:0 !important; border-radius:8px !important; gap:10px; color:#CBD8E8 !important; font-size:13px !important; font-weight:500 !important; line-height:1.2; }
.portal-sidebar-nav .nav-link i { width:18px; font-size:14px; color:#8FA6BF !important; text-align:center; flex:0 0 18px; }
.portal-sidebar-nav .nav-link:hover { background:rgba(255,255,255,.07) !important; color:#FFFFFF !important; }
.portal-sidebar-nav .nav-link:hover i { color:#4ADE80 !important; }
.portal-sidebar-nav .nav-link.active { background:linear-gradient(90deg,rgba(22,163,74,.18),rgba(255,255,255,.025)) !important; color:#FFFFFF !important; font-weight:700 !important; box-shadow:inset 3px 0 0 #4ADE80; }
.portal-sidebar-nav .nav-link.active i { color:#4ADE80 !important; }
.portal-sidebar-nav .side-nav-badge, .portal-sidebar-nav .nav-badge { margin-left:auto; background:rgba(22,163,74,.22) !important; color:#BBF7D0 !important; border-radius:20px; padding:2px 7px; font-size:9px; font-weight:800; }
.sidebar-footer { padding:13px 14px 15px !important; border-top:1px solid rgba(255,255,255,.08) !important; }
.btn-logout-side { padding:9px 11px !important; border-radius:8px !important; }
@media(max-width:900px) { .sidebar { width:248px !important; transform:translateX(-100%); transition:transform .22s ease; } .sidebar.open { transform:translateX(0); box-shadow:12px 0 34px rgba(0,0,0,.45); } .top-nav { left:0 !important; } .main { margin-left:0 !important; } }
@media(max-width:520px) { .portal-sidebar-nav { padding-left:9px !important; padding-right:9px !important; } }


/* Compact dashboard layout: keep summary information visible without forced blank space. */
body.light-theme .main {
    padding: 14px 24px 16px !important;
}
body.light-theme .dashboard-shell {
    padding: 16px 24px 18px !important;
    min-height: 0 !important;
}
body.light-theme .dashboard-intro {
    margin: 0 2px 12px !important;
}
body.light-theme .dashboard-intro h1 {
    font-size: 26px !important;
}
body.light-theme .dashboard-intro p {
    margin-top: 5px !important;
    line-height: 1.35;
}
body.light-theme .dashboard-intro .status-pill {
    margin-top: 9px !important;
    padding: 5px 10px !important;
}
body.light-theme .evaluation-schedule-card {
    padding: 14px 22px !important;
    margin-bottom: 14px !important;
}
body.light-theme .schedule-summary-grid {
    gap: 16px;
}
body.light-theme .schedule-label {
    margin-bottom: 5px;
}
body.light-theme .welcome-bar {
    padding: 15px 22px !important;
    margin-bottom: 14px !important;
    gap: 12px;
}
body.light-theme .score-chip {
    padding: 10px 20px !important;
}
body.light-theme .score-chip .sc-val,
body.light-theme .score-chip .big {
    font-size: 34px !important;
}
body.light-theme .score-chip .sc-lbl,
body.light-theme .score-chip .lbl {
    margin-top: 5px !important;
}
body.light-theme .stats-grid {
    gap: 12px !important;
    margin-bottom: 0 !important;
}
body.light-theme .stat-card,
body.light-theme .stat-box {
    padding: 13px 16px 15px !important;
}
body.light-theme .stat-card-icon,
body.light-theme .stat-box-icon {
    width: 30px !important;
    height: 30px !important;
    margin-bottom: 8px !important;
}
body.light-theme .stat-card-lbl,
body.light-theme .stat-box-lbl {
    margin-bottom: 8px !important;
}
body.light-theme .stat-card-val,
body.light-theme .stat-box-val {
    font-size: 26px !important;
}

@media (max-width: 900px) {
    body.light-theme .main {
        padding: 14px 14px 18px !important;
    }
    body.light-theme .dashboard-shell {
        padding: 16px 14px 18px !important;
        border-radius: 16px;
    }
    body.light-theme .evaluation-schedule-card {
        padding: 13px 16px !important;
    }
    body.light-theme .welcome-bar {
        padding: 14px 16px !important;
    }
}

/* Expanded dashboard overview: staff workspace mirrors the faculty overview layout. */
body.light-theme .dashboard-shell {
    min-height: calc(100vh - 110px) !important;
    display: flex;
    flex-direction: column;
}
body.light-theme .dashboard-quick-access {
    margin-top: auto;
    padding-top: 22px;
}
body.light-theme .quick-access-heading {
    display: flex;
    align-items: end;
    justify-content: space-between;
    gap: 16px;
    margin: 0 2px 12px;
}
body.light-theme .quick-access-kicker {
    display: block;
    margin-bottom: 3px;
    color: #15803D;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 1.15px;
}
body.light-theme .quick-access-heading h2 {
    color: #10243E;
    font-family: 'Rajdhani', sans-serif;
    font-size: 19px;
    line-height: 1.15;
    font-weight: 800;
}
body.light-theme .quick-access-caption {
    color: #6B7D91;
    font-size: 11px;
    padding-bottom: 2px;
}
body.light-theme .quick-access-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
}
body.light-theme .quick-access-card {
    min-width: 0;
    min-height: 92px;
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 15px 16px;
    text-decoration: none;
    border: 1px solid #DEE7F2;
    border-radius: 14px;
    background: #FFFFFF;
    box-shadow: 0 4px 14px rgba(19, 38, 63, .035);
    transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
}
body.light-theme .quick-access-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(19, 38, 63, .08);
    border-color: #BFD2F8;
}
body.light-theme .quick-access-icon {
    width: 42px;
    height: 42px;
    flex: 0 0 42px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 12px;
    font-size: 17px;
}
body.light-theme .quick-blue .quick-access-icon { background: #EAF1FF; color: #2563EB; }
body.light-theme .quick-green .quick-access-icon { background: #E7F9EF; color: #0E9F6E; }
body.light-theme .quick-amber .quick-access-icon { background: #FFF4DB; color: #D97706; }
body.light-theme .quick-access-copy { min-width: 0; flex: 1; display: flex; flex-direction: column; gap: 4px; }
body.light-theme .quick-access-copy strong { color: #10243E; font-size: 12.5px; line-height: 1.25; font-weight: 800; }
body.light-theme .quick-access-copy small { color: #64788E; font-size: 11px; line-height: 1.4; }
body.light-theme .quick-access-arrow { color: #8AA0B8; font-size: 12px; flex: 0 0 auto; transition: transform .18s ease, color .18s ease; }
body.light-theme .quick-access-card:hover .quick-access-arrow { color: #15803D; transform: translateX(3px); }
@media (max-width: 1100px) {
    body.light-theme .dashboard-shell { min-height: calc(100vh - 100px) !important; }
    body.light-theme .quick-access-grid { grid-template-columns: 1fr; }
    body.light-theme .quick-access-card { min-height: 76px; }
}
@media (max-width: 900px) {
    body.light-theme .dashboard-shell { min-height: 0 !important; }
    body.light-theme .dashboard-quick-access { margin-top: 22px; padding-top: 0; }
    body.light-theme .quick-access-heading { align-items: flex-start; flex-direction: column; gap: 4px; }
}




/* Staff Evaluation form — principal-modal proportions with the Staff green theme. */
.staff-eval-modal-overlay{
    position:fixed;inset:0;z-index:2500;display:flex;align-items:center;justify-content:center;
    padding:28px 16px;overflow:auto;background:rgba(8,27,46,.62);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);
}
.staff-eval-modal{
    --staff-eval-accent:#16A34A;--staff-eval-accent-dark:#15803D;--staff-eval-border:#D9E4EF;
    display:flex;flex-direction:column;flex:0 1 960px;width:100%;max-width:960px;
    height:calc(100vh - 56px);max-height:calc(100vh - 56px);min-height:420px;min-width:0;
    overflow:hidden;background:#fff;color:#172033;border:1px solid #E2E8F0;border-radius:18px;
    box-shadow:0 24px 70px rgba(0,0,0,.34);font-family:'DM Sans',sans-serif;
}
.staff-eval-modal-head{display:flex;align-items:center;gap:14px;padding:18px 24px;border-bottom:1px solid #D9E4EF;flex:0 0 auto;min-width:0;background:#fff;}
.staff-eval-modal-avatar,.staff-eval-modal-avatar-ph{width:56px;height:56px;flex:0 0 56px;box-sizing:border-box;border:2px solid #16A34A;border-radius:50%;object-fit:cover;background:#F0FDF4;color:#15803D;}
.staff-eval-modal-avatar-ph{display:flex;align-items:center;justify-content:center;font-size:21px;}
.staff-eval-modal-identity{min-width:0;flex:1;}
.staff-eval-modal-identity h2{margin:0;color:#13263F;font-family:'Rajdhani',sans-serif;font-size:22px;line-height:1.15;font-weight:700;overflow-wrap:anywhere;}
.staff-eval-modal-identity p{margin:3px 0 0;color:#647B91;font-size:13px;line-height:1.4;overflow-wrap:anywhere;}
.staff-eval-modal-close{display:flex;align-items:center;justify-content:center;flex:0 0 40px;width:40px;height:40px;border:0;border-radius:8px;color:#647B91;text-decoration:none;font-size:20px;}
.staff-eval-modal-close:hover{background:#F0FDF4;color:#15803D;}
.staff-eval-modal-form{display:flex;flex:1 1 auto;flex-direction:column;min-height:0;overflow:hidden;}
.staff-eval-modal-body{padding:20px 24px;overflow-y:auto;overscroll-behavior:contain;flex:1 1 auto;min-height:0;background:#fff;scrollbar-color:#A7B6C7 #F8FAFC;}
.staff-eval-progress-banner{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:11px 16px;margin:0 0 14px;border-radius:10px;background:#F0FDF4;border:1px solid #BBF7D0;color:#41586E;font-size:13px;font-weight:600;}
.staff-eval-progress-banner strong{color:#15803D;text-align:right;white-space:nowrap;font-weight:800;}
.staff-eval-rating-guide{display:flex;align-items:center;gap:14px;flex-wrap:wrap;padding:10px 14px;margin-bottom:16px;border-radius:10px;background:#F8FAFC;border:1px solid #D9E4EF;}
.staff-eval-rating-guide-item{display:flex;align-items:center;gap:7px;color:#52677D;font-size:12.5px;}
.staff-eval-rating-guide-item span{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:6px;background:#16A34A;color:#fff;font-size:12px;font-weight:800;flex:0 0 22px;}
.staff-eval-category-title{display:flex;align-items:center;gap:8px;margin:18px 0 8px;color:#15803D;font-size:12px;font-weight:800;letter-spacing:.8px;text-transform:uppercase;line-height:1.4;}
.staff-eval-category-title:first-of-type{margin-top:0;}
.staff-eval-table-wrap{margin:0 0 18px!important;background:#fff!important;border:1px solid #D9E4EF!important;border-radius:12px!important;overflow:hidden!important;box-shadow:none!important;}
.staff-eval-table{background:#fff!important;table-layout:fixed;width:100%;border-collapse:collapse;}
.staff-eval-table th{background:#F4F8FF!important;color:#5A7189!important;font-size:11px!important;letter-spacing:.7px!important;text-transform:uppercase;padding:11px 8px!important;border-bottom:1px solid #D9E4EF!important;text-align:center!important;}
.staff-eval-table th:first-child{width:auto!important;text-align:left!important;padding-left:14px!important;}
.staff-eval-table th:not(:first-child){width:58px!important;}
.staff-eval-table td{padding:11px 7px!important;border-bottom:1px solid #E8EEF5!important;text-align:center!important;vertical-align:middle!important;background:#fff!important;}
.staff-eval-table td:first-child{text-align:left!important;padding-left:14px!important;padding-right:12px!important;}
.staff-eval-table tr:last-child td{border-bottom:none!important;}
.staff-eval-table .compact-eval-qtext{color:#263D54!important;font-size:14px!important;font-weight:500!important;line-height:1.45!important;}
.staff-eval-table .compact-eval-qno{color:#15803D!important;margin-right:7px;}
.staff-eval-table .compact-eval-rating label{width:36px;height:32px;border:1px solid #D5E0EC!important;border-radius:8px!important;background:#F4F8FC!important;color:#4D6A85!important;font-size:13px!important;font-weight:600!important;box-shadow:none!important;}
.staff-eval-table .compact-eval-rating label:hover{border-color:#16A34A!important;background:#F0FDF4!important;color:#15803D!important;}
.staff-eval-table .compact-eval-rating input:checked+label{background:#16A34A!important;border-color:#16A34A!important;color:#fff!important;box-shadow:0 0 0 2px rgba(22,163,74,.12)!important;}
.staff-eval-table tr.eval-unanswered td{background:#FEF2F2!important;}
.staff-eval-comments-card{margin:8px 0 2px;padding:16px;border:1px solid #D9E4EF;border-radius:12px;background:#fff;}
.staff-eval-comments-label{display:flex;align-items:center;gap:8px;margin-bottom:10px;color:#15803D;font-size:13px;font-weight:800;}
.staff-eval-comments-label span{color:#647B91;font-weight:500;font-size:12px;}
.staff-eval-modal .comments-box{min-height:90px;resize:vertical;width:100%;box-sizing:border-box;background:#F8FAFC!important;color:#172033!important;border:1px solid #CBD5E1!important;border-radius:9px!important;padding:12px 14px;font-size:13px;}
.staff-eval-modal .comments-box:focus{outline:none;border-color:#16A34A!important;box-shadow:0 0 0 3px rgba(22,163,74,.10);}
.staff-eval-modal-foot{display:flex;align-items:center;gap:12px;padding:16px 24px;border-top:1px solid #D9E4EF;background:#fff;flex:0 0 auto;}
.staff-eval-cancel,.staff-eval-submit{min-height:52px;display:inline-flex;align-items:center;justify-content:center;gap:8px;border-radius:10px;font-family:'DM Sans',sans-serif;font-size:14px;font-weight:700;text-decoration:none;cursor:pointer;}
.staff-eval-cancel{flex:0 0 auto;padding:0 22px;background:#F8FAFC;color:#172033;border:1px solid #D5E0EC;}
.staff-eval-cancel:hover{background:#F0FDF4;border-color:#86D6A2;color:#166534;}
.staff-eval-submit{flex:1;min-width:0;padding:0 24px;background:#16A34A;color:#fff;border:1px solid #16A34A;box-shadow:0 5px 14px rgba(22,163,74,.18);}
.staff-eval-submit:hover{background:#15803D;border-color:#15803D;}
.staff-eval-submit:disabled{opacity:.65;cursor:not-allowed;}
.staff-eval-state-body{display:flex;align-items:center;justify-content:center;}
.staff-eval-state-body .empty-state{max-width:520px;}
.staff-eval-modal .eval-validation-alert{flex:0 0 auto;}
@media(max-width:700px){
 .staff-eval-modal-overlay{align-items:stretch;padding:8px;}
 .staff-eval-modal{width:100%;max-width:none;height:calc(100vh - 16px);max-height:calc(100vh - 16px);min-height:0;border-radius:13px;}
 .staff-eval-modal-head{padding:14px 16px;gap:10px;}
 .staff-eval-modal-avatar,.staff-eval-modal-avatar-ph{width:46px;height:46px;flex-basis:46px;}
 .staff-eval-modal-identity h2{font-size:19px;}
 .staff-eval-modal-identity p{font-size:11.5px;}
 .staff-eval-modal-body{padding:14px 14px;}
 .staff-eval-progress-banner{align-items:flex-start;flex-direction:column;gap:5px;padding:10px 12px;font-size:12px;}
 .staff-eval-progress-banner strong{text-align:left;white-space:normal;}
 .staff-eval-rating-guide{gap:8px;padding:9px 10px;}
 .staff-eval-rating-guide-item{font-size:11px;gap:5px;}
 .staff-eval-table-wrap{overflow-x:auto!important;}
 .staff-eval-table{min-width:560px;}
 .staff-eval-modal-foot{padding:12px 14px;gap:8px;}
 .staff-eval-cancel,.staff-eval-submit{min-height:46px;padding:0 13px;font-size:12.5px;}
}
@media(max-height:620px) and (min-width:701px){
 .staff-eval-modal-overlay{align-items:flex-start;padding:12px;}
 .staff-eval-modal{height:calc(100vh - 24px);max-height:calc(100vh - 24px);}
}

/* Top-bar page title — dark title, icon in the portal accent colour */
.top-nav .nav-page-title,body.light-theme .top-nav .nav-page-title{display:flex;align-items:center;gap:9px;font-family:'Rajdhani',sans-serif !important;font-size:20px !important;font-weight:700 !important;letter-spacing:.4px !important;text-transform:none !important;background:transparent !important;text-shadow:none !important;-webkit-font-smoothing:antialiased;}
.top-nav .nav-page-title i{font-size:16px;color:var(--teal);}
.top-nav .nav-page-title span{background:transparent !important;}
@media(max-width:600px){.top-nav .nav-page-title{font-size:17px !important;}}
/* Pages without an in-page title block: keep the status pill tight to the top */
.dashboard-intro .status-pill{margin-top:0 !important;}
.settings-page-head{justify-content:flex-end;}
/* ===== Settings — same visual language as the Dashboard (mirrors the Faculty portal) ===== */
.settings-intro{display:flex;align-items:flex-start;justify-content:flex-end;gap:20px;flex-wrap:wrap}
.settings-shell .settings-grid{margin-top:0;gap:14px;}
.settings-shell .settings-card{--sc-bar:var(--teal);--sc-ico:var(--teal);--sc-ico-bg:color-mix(in srgb,var(--teal) 14%,transparent);position:relative;overflow:hidden;background:var(--mid);border:1px solid var(--border);border-radius:var(--radius);padding:22px 24px 24px;}
.settings-shell .settings-card::before{content:'';position:absolute;left:0;right:0;top:0;height:3px;background:var(--sc-bar);}
.settings-shell .settings-card:nth-child(2),.settings-shell .settings-card:nth-child(5){--sc-bar:#10B981;--sc-ico:#10B981;--sc-ico-bg:#E5F9ED;}
.settings-shell .settings-card:nth-child(3){--sc-bar:#F59E0B;--sc-ico:#D88900;--sc-ico-bg:#FFF4D8;}
.settings-shell .settings-card:nth-child(4){--sc-bar:#3B82F6;--sc-ico:#3478E5;--sc-ico-bg:#E5F0FF;}
.settings-shell .settings-card-head{align-items:flex-start;gap:14px;margin-bottom:18px;}
.settings-shell .settings-card-head .sicon{width:42px;height:42px;flex:0 0 42px;border-radius:12px;font-size:17px;background:var(--sc-ico-bg);color:var(--sc-ico);}
.settings-shell .settings-card-head h3{margin:0 0 4px;font-family:'Rajdhani',sans-serif;font-size:21px;font-weight:800;line-height:1.15;}
.settings-shell .settings-card-head p{margin:0;font-size:13px;line-height:1.5;}
.settings-shell .setting-row strong{font-size:13.5px;}
.settings-shell .setting-row span{font-size:12px;}
body.light-theme .settings-shell{min-height:0 !important;display:block !important;}
body.light-theme .settings-shell .settings-card{background:#FFFFFF;border:1px solid #E0E7EF;border-radius:15px;box-shadow:0 6px 18px rgba(20,42,67,.045);}
body.light-theme .settings-shell .settings-card-head h3{color:#10243E;}
body.light-theme .settings-shell .settings-card-head p{color:#5E7388;}
body.light-theme .settings-shell .setting-row strong{color:#10243E;}
body.light-theme .settings-shell .setting-row span{color:#5E7388;}
@media(max-width:600px){.settings-shell .settings-card{padding:18px 18px 20px;}}

/* ================================================================
   DARK MODE — STAFF DASHBOARD
   Mirrors the light-theme dashboard (shell card, status pill, compact
   schedule / welcome / stat cards, quick-access shortcuts) using
   dark-surface colours. Scoped to body:not(.light-theme) so light mode
   is untouched.
   ================================================================ */
body:not(.light-theme) .main{padding:14px 24px 16px !important;}
body:not(.light-theme) .dashboard-shell{
    background:linear-gradient(180deg,#112240 0%,#0D1D36 100%);
    border:1px solid rgba(255,255,255,.08);border-radius:22px;
    padding:16px 24px 18px !important;
    box-shadow:0 10px 28px rgba(0,0,0,.30);
    min-height:calc(100vh - 110px) !important;
    display:flex;flex-direction:column;
}
body:not(.light-theme) .settings-shell{min-height:0 !important;display:block !important;}

/* Intro + status pill */
body:not(.light-theme) .dashboard-intro{margin:0 2px 12px !important;}
body:not(.light-theme) .dashboard-intro .status-pill{
    display:inline-flex;align-items:center;gap:7px;padding:5px 10px !important;border-radius:20px;
    background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.35);color:#4ADE80;
    font-size:11.5px;font-weight:700;
}
body:not(.light-theme) .dashboard-intro .status-pill.closed{
    background:rgba(239,68,68,.12);border-color:rgba(239,68,68,.35);color:#F87171;
}

/* Schedule card */
body:not(.light-theme) .evaluation-schedule-card{
    background:var(--mid);border:1px solid rgba(255,255,255,.08);
    padding:14px 22px !important;margin-bottom:14px !important;box-shadow:0 5px 18px rgba(0,0,0,.20);
}
body:not(.light-theme) .schedule-summary-grid{gap:16px;}
body:not(.light-theme) .schedule-label{margin-bottom:5px;}
body:not(.light-theme) .schedule-summary-item strong{color:#F1F5F9;}

/* Welcome bar + score chip */
body:not(.light-theme) .welcome-bar{
    background:linear-gradient(135deg,#172A45 0%,rgba(22,163,74,.12) 100%) !important;
    border:1px solid rgba(34,197,94,.25) !important;border-radius:17px !important;
    padding:15px 22px !important;margin-bottom:14px !important;gap:12px;
    box-shadow:0 5px 18px rgba(0,0,0,.18);
}
body:not(.light-theme) .welcome-bar h2{color:#F8FAFC !important;font-size:23px !important;}
body:not(.light-theme) .welcome-bar p{color:#A0B3C6 !important;}
body:not(.light-theme) .welcome-bar p strong{color:#4ADE80 !important;}
body:not(.light-theme) .score-chip{
    background:rgba(22,163,74,.12) !important;border:1px solid rgba(34,197,94,.30) !important;
    border-radius:15px !important;max-width:340px;min-width:230px;padding:10px 20px !important;
}
body:not(.light-theme) .score-chip .big{color:#4ADE80 !important;font-size:34px !important;}
body:not(.light-theme) .score-chip .lbl{color:#A0B3C6 !important;margin-top:5px !important;}

/* Stat cards */
body:not(.light-theme) .stats-grid{gap:12px !important;margin-bottom:0 !important;}
body:not(.light-theme) .stat-box{
    position:relative;background:var(--mid) !important;border:1px solid rgba(255,255,255,.08) !important;
    border-radius:15px !important;padding:13px 16px 15px !important;overflow:hidden;
    box-shadow:0 6px 18px rgba(0,0,0,.20) !important;
}
body:not(.light-theme) .stat-box::before{content:'';position:absolute;left:0;right:0;top:0;height:3px;background:#16A34A;}
body:not(.light-theme) .stat-box:nth-child(2)::before{background:#10B981;}
body:not(.light-theme) .stat-box:nth-child(3)::before{background:#F59E0B;}
body:not(.light-theme) .stat-box:nth-child(4)::before{background:#3B82F6;}
body:not(.light-theme) .stat-box-icon{width:30px;height:30px;border-radius:10px;display:flex;align-items:center;justify-content:center;margin-bottom:8px;font-size:15px;}
body:not(.light-theme) .stat-box-icon.green{background:rgba(34,197,94,.16);color:#4ADE80;}
body:not(.light-theme) .stat-box-icon.mint{background:rgba(16,185,129,.16);color:#34D399;}
body:not(.light-theme) .stat-box-icon.gold{background:rgba(245,158,11,.16);color:#FBBF24;}
body:not(.light-theme) .stat-box-icon.sky{background:rgba(59,130,246,.16);color:#60A5FA;}
body:not(.light-theme) .stat-box-lbl{color:#A0B3C6 !important;font-size:10.5px !important;letter-spacing:.9px !important;margin-bottom:8px !important;}
body:not(.light-theme) .stat-box-val{color:#F1F5F9 !important;font-size:26px !important;}
body:not(.light-theme) .stat-box-val.teal{color:#4ADE80 !important;}
body:not(.light-theme) .stat-box-val.gold{color:#FBBF24 !important;}
body:not(.light-theme) .stat-box-link:hover{border-color:rgba(74,222,128,.45) !important;box-shadow:0 8px 18px rgba(0,0,0,.30) !important;}

/* Quick access shortcuts */
body:not(.light-theme) .dashboard-quick-access{margin-top:auto;padding-top:22px;}
body:not(.light-theme) .quick-access-heading{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin:0 2px 12px;}
body:not(.light-theme) .quick-access-kicker{display:block;margin-bottom:3px;color:#4ADE80;font-size:10px;font-weight:800;letter-spacing:1.15px;}
body:not(.light-theme) .quick-access-heading h2{color:#F8FAFC;font-family:'Rajdhani',sans-serif;font-size:19px;line-height:1.15;font-weight:800;}
body:not(.light-theme) .quick-access-caption{color:#8FA6BF;font-size:11px;padding-bottom:2px;}
body:not(.light-theme) .quick-access-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;}
body:not(.light-theme) .quick-access-card{
    min-width:0;min-height:92px;display:flex;align-items:center;gap:12px;padding:15px 16px;
    text-decoration:none;background:var(--mid);border:1px solid rgba(255,255,255,.08);border-radius:14px;
    box-shadow:0 4px 14px rgba(0,0,0,.20);
    transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease;
}
body:not(.light-theme) .quick-access-card:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(0,0,0,.32);border-color:rgba(74,222,128,.40);}
body:not(.light-theme) .quick-access-icon{width:42px;height:42px;flex:0 0 42px;display:inline-flex;align-items:center;justify-content:center;border-radius:12px;font-size:17px;}
body:not(.light-theme) .quick-blue .quick-access-icon{background:rgba(59,130,246,.16);color:#60A5FA;}
body:not(.light-theme) .quick-green .quick-access-icon{background:rgba(16,185,129,.16);color:#34D399;}
body:not(.light-theme) .quick-amber .quick-access-icon{background:rgba(245,158,11,.16);color:#FBBF24;}
body:not(.light-theme) .quick-access-copy{min-width:0;flex:1;display:flex;flex-direction:column;gap:4px;}
body:not(.light-theme) .quick-access-copy strong{color:#F1F5F9;font-size:12.5px;line-height:1.25;font-weight:800;}
body:not(.light-theme) .quick-access-copy small{color:#A0B3C6;font-size:11px;line-height:1.4;}
body:not(.light-theme) .quick-access-arrow{color:#8FA6BF;font-size:12px;flex:0 0 auto;transition:transform .18s ease,color .18s ease;}
body:not(.light-theme) .quick-access-card:hover .quick-access-arrow{color:#4ADE80;transform:translateX(3px);}

@media(max-width:1100px){
    body:not(.light-theme) .dashboard-shell{min-height:calc(100vh - 100px) !important;}
    body:not(.light-theme) .settings-shell{min-height:0 !important;}
    body:not(.light-theme) .quick-access-grid{grid-template-columns:1fr;}
    body:not(.light-theme) .quick-access-card{min-height:76px;}
}
@media(max-width:900px){
    body:not(.light-theme) .main{padding:14px 14px 18px !important;}
    body:not(.light-theme) .dashboard-shell{padding:16px 14px 18px !important;border-radius:16px;min-height:0 !important;}
    body:not(.light-theme) .evaluation-schedule-card{padding:13px 16px !important;}
    body:not(.light-theme) .welcome-bar{padding:14px 16px !important;}
    body:not(.light-theme) .dashboard-quick-access{margin-top:22px;padding-top:0;}
    body:not(.light-theme) .quick-access-heading{align-items:flex-start;flex-direction:column;gap:4px;}
}

/* Settings page shares the dashboard shell — keep its accents readable on dark */
body:not(.light-theme) .settings-shell .settings-card{--sc-ico:#4ADE80;--sc-ico-bg:rgba(34,197,94,.16);}
body:not(.light-theme) .settings-shell .settings-card:nth-child(2),
body:not(.light-theme) .settings-shell .settings-card:nth-child(5){--sc-ico:#34D399;--sc-ico-bg:rgba(16,185,129,.16);}
body:not(.light-theme) .settings-shell .settings-card:nth-child(3){--sc-ico:#FBBF24;--sc-ico-bg:rgba(245,158,11,.16);}
body:not(.light-theme) .settings-shell .settings-card:nth-child(4){--sc-ico:#60A5FA;--sc-ico-bg:rgba(59,130,246,.16);}
body:not(.light-theme) .settings-appearance-current{background:rgba(59,130,246,.14);border-color:rgba(96,165,250,.30);color:#93C5FD;}
body:not(.light-theme) .appearance-choice.active{background:rgba(59,130,246,.16);border-color:rgba(96,165,250,.35);color:#93C5FD;}

/* ================================================================
   STAFF EVALUATION — same look as the Dashboard (shell, cards, accents)
   ================================================================ */
.dashboard-shell.ea-eval-wrap{max-width:none;}
.ea-eval-wrap .ea-tab-row{margin-bottom:14px;gap:12px;}
.ea-eval-wrap .ea-toolbar,.ea-eval-wrap .ea-table-card{position:relative;overflow:hidden;border-radius:15px;box-shadow:0 6px 18px rgba(20,42,67,.045);}
.ea-eval-wrap .ea-toolbar{padding:16px 20px;margin-bottom:14px;}
.ea-eval-wrap .ea-toolbar::before,.ea-eval-wrap .ea-table-card::before{content:'';position:absolute;left:0;right:0;top:0;height:3px;background:#16A34A;z-index:1;}
.ea-eval-wrap .ea-table-card::before{background:#3B82F6;}
.ea-kicker{display:block;margin-bottom:3px;color:#15803D;font-size:10px;font-weight:800;letter-spacing:1.15px;text-transform:uppercase;}
.ea-toolbar-title{margin:0;color:var(--light);font-family:'Rajdhani',sans-serif;font-size:19px;line-height:1.15;font-weight:800;}
.ea-toolbar-sub{margin:4px 0 0;color:var(--muted);font-size:12.5px;}

body:not(.light-theme) .ea-kicker{color:#4ADE80;}
body:not(.light-theme) .ea-toolbar-title{color:#F8FAFC;}
body:not(.light-theme) .ea-eval-wrap .ea-toolbar,
body:not(.light-theme) .ea-eval-wrap .ea-table-card{border-color:rgba(255,255,255,.08);box-shadow:0 6px 18px rgba(0,0,0,.20);}
body:not(.light-theme) .ea-tab{background:rgba(22,163,74,.10);border-color:rgba(34,197,94,.25);color:#CBD8E8;}
body:not(.light-theme) .ea-tab i{color:#4ADE80;}
body:not(.light-theme) .ea-tab:hover{background:rgba(22,163,74,.16);border-color:rgba(74,222,128,.45);color:#fff;}
body:not(.light-theme) .ea-tab.active{background:linear-gradient(135deg,rgba(22,163,74,.24),rgba(22,163,74,.10));border-color:#16A34A;color:#fff;box-shadow:none;}
body:not(.light-theme) .ea-tab.active i{color:#4ADE80;}
body:not(.light-theme) .ea-role-pill{background:rgba(34,197,94,.12);border-color:rgba(34,197,94,.25);color:#86EFAC;}
body:not(.light-theme) .ea-avatar{border-color:rgba(34,197,94,.35);color:#4ADE80;}
body:not(.light-theme) .ea-table tbody tr:hover{background:rgba(34,197,94,.05);}
body:not(.light-theme) .period-badge{background:rgba(34,197,94,.12);border-color:rgba(34,197,94,.30);color:#4ADE80;}

/* ================================================================
   CATEGORY PICKER — same look as the Executive Assistant's
   "Evaluate Others → Choose category" (large icon cards, no progress
   pills, no accent bottom border). Used by Faculty + Teaching Staff
   Peer-to-Peer Evaluation. Works in light and dark mode.
   ================================================================ */
.cat-heading{display:flex;align-items:center;gap:12px;margin:0 0 22px;font-family:'Rajdhani',sans-serif;font-size:26px;line-height:1.1;font-weight:800;color:var(--light);}
.cat-heading i{font-size:20px;color:var(--teal);}
body .section-card:has(.cat-select){padding:28px 34px 32px !important;}
.eval-group-grid.cat-select{grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:24px;margin-bottom:0;}
body .eval-group-grid.cat-select .eval-group-card.eval-group-card{
    min-height:230px;padding:38px 20px !important;justify-content:center;
    border:1px solid #E2E9F2 !important;border-bottom:1px solid #E2E9F2 !important;border-radius:16px;
    background:#FFFFFF !important;box-shadow:none;
    transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease;
}
body .eval-group-grid.cat-select .eval-group-card.eval-group-card:hover{transform:translateY(-2px);border-color:#C9D6E6 !important;box-shadow:0 8px 20px rgba(20,42,67,.07);}
body .eval-group-grid.cat-select .eval-group-card.eval-group-card.is-selected{border-color:var(--teal) !important;border-bottom-color:var(--teal) !important;box-shadow:0 0 0 1px var(--teal);}
.eval-group-grid.cat-select .eval-group-icon{width:80px;height:80px;font-size:28px;margin-bottom:22px;}
body .eval-group-grid.cat-select .eval-group-title.eval-group-title{font-family:'DM Sans',sans-serif;font-size:24px;font-weight:700;color:#10243E !important;margin-bottom:6px;}
body .eval-group-grid.cat-select .eval-group-count.eval-group-count{font-size:15px;color:#64788E !important;}

/* dark mode */
body:not(.light-theme) .eval-group-grid.cat-select .eval-group-card.eval-group-card{background:var(--inner) !important;border-color:rgba(255,255,255,.10) !important;border-bottom-color:rgba(255,255,255,.10) !important;}
body:not(.light-theme) .eval-group-grid.cat-select .eval-group-card.eval-group-card:hover{border-color:rgba(255,255,255,.22) !important;box-shadow:0 8px 20px rgba(0,0,0,.30);}
body:not(.light-theme) .eval-group-grid.cat-select .eval-group-card.eval-group-card.is-selected{border-color:var(--teal) !important;}
body:not(.light-theme) .eval-group-grid.cat-select .eval-group-title.eval-group-title{color:#F1F5F9 !important;}
body:not(.light-theme) .eval-group-grid.cat-select .eval-group-count.eval-group-count{color:#A0B3C6 !important;}

/* once a category is chosen the picker stays as a compact switcher above the list */
@media(max-width:600px){.cat-heading{font-size:22px;}body .section-card:has(.cat-select){padding:20px 18px 24px !important;}}

/* Peer-to-Peer Evaluation → same page frame as the Admin "Evaluate Others" screen */
.cat-heading{font-size:30px;gap:13px;}
.cat-heading i{font-size:22px;color:#2563EB;}
body .section-card:has(.cat-select){min-height:calc(100vh - 130px);padding:34px 36px 40px !important;border-radius:20px;}
body .eval-group-grid.cat-select .eval-group-title.eval-group-title{font-size:25px;}
body .eval-group-grid.cat-select .eval-group-count.eval-group-count{font-size:16px;}
body .eval-group-grid.cat-select .eval-group-card.eval-group-card{min-height:235px;border-radius:18px;}
@media(max-width:900px){body .section-card:has(.cat-select){min-height:0;padding:22px 18px 26px !important;}}

/* Category picker sizing — exact metrics from the admin "Evaluate Others" screen */
body .section-card:has(.cat-select){padding:24px 28px 22px !important;border-radius:14px;}
.cat-heading{font-size:26px;gap:11px;margin:0 0 14px;font-weight:700;letter-spacing:.1px;}
.cat-heading i{font-size:22px;}
.eval-group-grid.cat-select{gap:18px;}
body .eval-group-grid.cat-select .eval-group-card.eval-group-card{min-height:188px;padding:22px 18px !important;border-radius:14px;}
.eval-group-grid.cat-select .eval-group-icon{width:64px;height:64px;font-size:25px;margin-bottom:15px;}
body .eval-group-grid.cat-select .eval-group-title.eval-group-title{font-size:20px;margin-bottom:3px;}
body .eval-group-grid.cat-select .eval-group-count.eval-group-count{font-size:13.5px;}
/* compact switcher shown once a category is chosen */
@media(max-width:900px){body .section-card:has(.cat-select){padding:20px !important;}}
@media(max-width:560px){.cat-heading{font-size:23px;}body .section-card:has(.cat-select){padding:16px !important;}body .eval-group-grid.cat-select .eval-group-card.eval-group-card{min-height:150px;}}
.cat-heading{font-size:21px;gap:9px;margin:0 0 14px;}
.cat-heading i{font-size:16px;color:#16A34A !important;}
@media(max-width:560px){.cat-heading{font-size:19px;}.cat-heading i{font-size:15px;}}

/* ================================================================
   PEER-TO-PEER EVALUATION — roster + popup, same structure as the
   Admin "Evaluate Others" screen. Colours follow the Staff green theme.
   ================================================================ */
.peer-inline-categories{background:var(--mid);border:1px solid var(--border);border-radius:14px;padding:24px 28px 22px;margin-bottom:20px;box-shadow:0 6px 18px rgba(20,42,67,.045);}
body:not(.light-theme) .peer-inline-categories{box-shadow:0 6px 18px rgba(0,0,0,.20);}
@media(max-width:900px){.peer-inline-categories{padding:20px;}}
@media(max-width:560px){.peer-inline-categories{padding:16px;}}
.cat-heading i{color:var(--teal) !important;}
.roster-panel{
    --rp-panel:#FFFFFF;--rp-line:#DCE8F5;--rp-soft:#F8FBFF;--rp-card:#FFFFFF;--rp-card-border:#D7E3EF;
    --rp-text:#10243F;--rp-muted:#587089;--rp-muted2:#7E93AB;--rp-icon:#DCFCE7;--rp-icon-fg:#16A34A;
    --rp-photo:#F2F6FB;--rp-photo-border:#C9D9EA;--rp-view-border:#9FB4CA;
    margin-top:0;scroll-margin-top:12px;background:var(--rp-panel);border:1px solid var(--rp-line);border-radius:14px;overflow:hidden;
    box-shadow:0 6px 18px rgba(20,42,67,.045);
}
body:not(.light-theme) .roster-panel{
    --rp-panel:var(--mid);--rp-line:rgba(255,255,255,.09);--rp-soft:var(--inner);--rp-card:var(--inner);--rp-card-border:rgba(255,255,255,.10);
    --rp-text:#F5F7FB;--rp-muted:#9CB0CA;--rp-muted2:#8298B4;--rp-icon:rgba(34,197,94,.16);--rp-icon-fg:#4ADE80;
    --rp-photo:#18304E;--rp-photo-border:#2B476A;--rp-view-border:#34506F;
    box-shadow:0 6px 18px rgba(0,0,0,.20);
}
.roster-head{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px 16px;padding:18px 22px;border-bottom:1px solid var(--rp-line);background:var(--rp-soft);}
.roster-heading{display:flex;align-items:center;gap:11px;}
.roster-heading .roster-icon{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:var(--rp-icon);color:var(--rp-icon-fg);font-size:15px;}
.roster-heading h2{margin:0;font-family:'DM Sans',sans-serif;font-size:20px;font-weight:700;color:var(--rp-text);}
.roster-stats{display:flex;align-items:center;flex-wrap:wrap;gap:8px 16px;color:var(--rp-muted);font-size:12.5px;}
.roster-stats strong{color:var(--rp-text);}
.roster-stats .period{display:inline-flex;align-items:center;gap:6px;}
.person-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;padding:18px;background:var(--rp-panel);}
.person-card{display:flex;align-items:center;gap:13px;background:var(--rp-card);border:1px solid var(--rp-card-border);border-radius:11px;padding:13px 14px;min-width:0;}
.person-photo{width:44px;height:44px;border-radius:50%;background:var(--rp-photo);border:1px solid var(--rp-photo-border);display:flex;align-items:center;justify-content:center;color:var(--rp-muted);flex-shrink:0;overflow:hidden;}
.person-photo img{width:100%;height:100%;object-fit:cover;display:block;}
.person-info{min-width:0;flex:1;}
.person-name{font-size:14.5px;font-weight:800;color:var(--rp-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.person-meta{margin-top:3px;color:var(--rp-muted);font-size:12.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.person-card .status-pill{display:inline-flex;align-items:center;gap:5px;margin-top:7px;padding:4px 10px;border-radius:999px;font-size:11.5px;font-weight:800;}
.person-card .status-pill.done{background:rgba(52,211,153,.12);color:#11805F;border:1px solid rgba(52,211,153,.18);}
.person-card .status-pill.pending{background:rgba(245,158,11,.12);color:#A66000;border:1px solid rgba(245,158,11,.18);}
body:not(.light-theme) .person-card .status-pill.done{color:#6EE7B7;}
body:not(.light-theme) .person-card .status-pill.pending{color:#F8C465;}
.person-action{flex-shrink:0;}
.btn-action{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:10px 14px;border-radius:8px;text-decoration:none;font-size:12.5px;font-weight:800;border:1px solid transparent;font-family:'DM Sans',sans-serif;}
.btn-action.evaluate{background:var(--teal);color:#fff;border-color:var(--teal);}
.btn-action.evaluate:hover{background:var(--teal-hover);border-color:var(--teal-hover);}
.btn-action.view{background:transparent;color:var(--rp-muted);border-color:var(--rp-view-border);}
.btn-action.is-disabled{cursor:not-allowed;opacity:.6;}
.btn-action.view.is-disabled{cursor:default;opacity:1;}
.roster-empty{text-align:center;padding:44px 20px;color:var(--rp-muted2);font-size:12.5px;}
.roster-empty i{display:block;margin-bottom:10px;font-size:26px;opacity:.45;}
.roster-panel a:focus-visible,.eval-group-card:focus-visible{outline:2px solid var(--teal);outline-offset:2px;}
@media(max-width:900px){.person-grid{grid-template-columns:1fr;}}
@media(max-width:560px){.person-card{align-items:flex-start;flex-wrap:wrap;}.person-action{width:100%;}.person-action .btn-action{width:100%;}}


/* ---- match the Admin "Evaluate Others" screen exactly ---- */
body .section-card.peer-shell{min-height:0 !important;}
.peer-shell .roster-panel{margin-top:22px;box-shadow:none;}
body .eval-group-grid.cat-select .eval-group-card.eval-group-card:hover{background:#F2FBF6 !important;border-color:rgba(22,163,74,.45) !important;box-shadow:0 10px 24px rgba(16,24,40,.08) !important;transform:translateY(-2px);}
body .eval-group-grid.cat-select .eval-group-card.eval-group-card.is-selected{background:#F2FBF6 !important;border-color:var(--teal) !important;border-bottom-color:var(--teal) !important;box-shadow:0 0 0 3px rgba(22,163,74,.14) !important;}
body:not(.light-theme) .eval-group-grid.cat-select .eval-group-card.eval-group-card:hover,
body:not(.light-theme) .eval-group-grid.cat-select .eval-group-card.eval-group-card.is-selected{background:#14301F !important;}
.person-card{border-radius:12px;box-shadow:0 1px 2px rgba(16,24,40,.04);}
.btn-action{border-radius:10px;padding:10px 16px;font-weight:700;}
.roster-heading h2{font-size:22px;letter-spacing:-.005em;}

/* fixed-height white box: whitespace stays put and the scrollbar lives INSIDE the box (same as the EA screen) */
body .section-card.peer-box{height:calc(100vh - 116px);min-height:0 !important;margin-bottom:0;overflow-y:auto;overscroll-behavior:contain;scrollbar-width:thin;scrollbar-color:#B7C6D8 transparent;}
body .section-card.peer-box::-webkit-scrollbar{width:8px;}
body .section-card.peer-box::-webkit-scrollbar-thumb{background:#B7C6D8;border-radius:8px;}
body .section-card.peer-box::-webkit-scrollbar-track{background:transparent;}
body:not(.light-theme) .section-card.peer-box{scrollbar-color:#3A5578 transparent;}
body:not(.light-theme) .section-card.peer-box::-webkit-scrollbar-thumb{background:#3A5578;}
@media(max-width:900px){body .section-card.peer-box{height:auto;overflow:visible;}}

/* Role & Designation page */
/* ===== All pages: the white shell has a fixed height and scrolls INSIDE itself (page never scrolls) ===== */
html body .dashboard-shell{height:calc(100vh - 116px);min-height:0 !important;overflow-y:auto;overflow-x:hidden;overscroll-behavior:contain;scrollbar-width:thin;scrollbar-color:#B7C6D8 transparent;}
html body .dashboard-shell > *{flex-shrink:0;}
html body.light-theme .dashboard-shell{padding-bottom:28px !important;}
html body .dashboard-shell::-webkit-scrollbar{width:8px;}
html body .dashboard-shell::-webkit-scrollbar-thumb{background:#B7C6D8;border-radius:8px;}
html body .dashboard-shell::-webkit-scrollbar-track{background:transparent;}
html body:not(.light-theme) .dashboard-shell{scrollbar-color:#3A5578 transparent;}
html body:not(.light-theme) .dashboard-shell::-webkit-scrollbar-thumb{background:#3A5578;}
.main > .toast{position:fixed;top:72px;right:28px;z-index:300;margin:0;max-width:420px;box-shadow:0 10px 28px rgba(0,0,0,.18);}
@media(max-width:900px){html body .dashboard-shell{height:auto;overflow:visible;}.main > .toast{left:14px;right:14px;max-width:none;}}
body .dashboard-shell.role-shell{display:block !important;padding:24px 28px !important;}
.role-shell .profile-hero{margin-bottom:20px;}
.role-shell .role-card{margin-bottom:0;}
@media(max-width:900px){body .dashboard-shell.role-shell{padding:18px 16px !important;}}

/* evaluation popup */
.peer-eval-overlay{position:fixed;inset:0;z-index:280;display:flex;align-items:flex-start;justify-content:center;padding:28px 16px;overflow-y:auto;background:rgba(8,20,39,.58);backdrop-filter:blur(7px);-webkit-backdrop-filter:blur(7px);}
.peer-eval-dialog{width:100%;max-width:960px;max-height:calc(100vh - 56px);min-height:0;display:flex;flex-direction:column;overflow:hidden;background:var(--mid);border:1px solid var(--border);border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.35);}
.peer-eval-dialog-header{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:18px 24px;border-bottom:1px solid var(--border);background:var(--mid);flex:0 0 auto;}
.peer-eval-target{display:flex;align-items:center;gap:14px;min-width:0;}
.peer-eval-avatar{width:56px;height:56px;border-radius:50%;object-fit:cover;border:2px solid var(--teal);flex-shrink:0;}
.peer-eval-avatar-placeholder{display:flex;align-items:center;justify-content:center;background:var(--inner);color:var(--muted);font-size:20px;}
.peer-eval-target-copy{min-width:0;}
.peer-eval-target-copy h2{margin:0;color:var(--light);font-family:'Rajdhani',sans-serif;font-size:22px;font-weight:700;line-height:1.15;overflow-wrap:anywhere;}
.peer-eval-target-copy p{margin:2px 0 0;color:var(--muted);font-size:13px;}
.peer-eval-close{width:38px;height:38px;display:flex;align-items:center;justify-content:center;flex-shrink:0;border-radius:9px;color:var(--muted);font-size:22px;text-decoration:none;}
.peer-eval-close:hover{background:var(--inner);color:var(--light);}
.peer-eval-form{display:flex;flex:1 1 auto;min-height:0;flex-direction:column;}
.peer-eval-scroll{padding:20px 24px;overflow-y:auto;overscroll-behavior:contain;min-height:0;flex:1 1 auto;}
.peer-eval-footer{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 24px;border-top:1px solid var(--border);background:var(--mid);flex:0 0 auto;}
.peer-eval-footer .btn-cancel-peer{display:inline-flex;align-items:center;justify-content:center;padding:12px 20px;border-radius:10px;border:1px solid var(--border);background:var(--inner);color:var(--light);font-size:14px;font-weight:600;text-decoration:none;white-space:nowrap;}
.peer-eval-footer .btn-submit{flex:1;justify-content:center;min-height:48px;}
.peer-eval-empty{padding:28px;color:var(--muted);display:flex;gap:10px;align-items:center;}
.peer-eval-empty i{color:#d97706;}
@media(max-width:700px){
 .peer-eval-overlay{padding:8px;align-items:center;}
 .peer-eval-dialog{height:calc(100vh - 16px);max-height:calc(100vh - 16px);}
 .peer-eval-dialog-header{padding:16px;}
 .peer-eval-scroll{padding:16px 12px 20px;}
 .peer-eval-footer{padding:12px;}
}
</style>
        <style id="fx-polish">
/* ------------------------------------------------------------------
   Evaluate Others polish (same treatment as the admin EA page):
   group cards with progress, clearer roster, stronger contrast.
   Light/dark follow the page's own body.light-theme switch.
   Presentation only: links, filters, forms and evaluation popups are untouched.
   ------------------------------------------------------------------ */
html body:not(#fxp) .cat-heading{margin-bottom:6px!important;}
html body:not(#fxp) .cat-lead{margin:0 0 22px;font-size:14.5px;line-height:1.5;}
html body.light-theme:not(#fxp) .cat-lead{color:#475569;}
html body:not(.light-theme):not(#fxp) .cat-lead{color:#A0B3C6;}

/* group cards */
html body:not(#fxp) .eval-group-grid.cat-select{gap:22px!important;}
html body:not(#fxp) .eval-group-grid.cat-select .eval-group-card.eval-group-card{--g:#2563EB;--g-ink:#1D4ED8;--g-bg:#E1EBFF;--g-ring:rgba(37,99,235,.26);
    position:relative;justify-content:flex-start!important;gap:0;min-height:320px!important;padding:32px 22px 24px!important;border-radius:18px!important;
    border-width:1px!important;border-style:solid!important;border-top:5px solid var(--g)!important;}
html body:not(#fxp) .eval-group-grid.cat-select .eval-group-card.eval-group-card:has(.eval-group-icon.green){--g:#16A34A;--g-ink:#15803D;--g-bg:#DDF5E6;--g-ring:rgba(22,163,74,.26);}
html body:not(#fxp) .eval-group-grid.cat-select .eval-group-card.eval-group-card:has(.eval-group-icon.purple){--g:#7C5FD9;--g-ink:#5F45B8;--g-bg:#ECE3FF;--g-ring:rgba(124,95,217,.26);}
html body:not(#fxp) .eval-group-grid.cat-select .eval-group-card.eval-group-card:has(.eval-group-icon.gold){--g:#D97706;--g-ink:#B45309;--g-bg:#FFEFCF;--g-ring:rgba(217,119,6,.26);}
html body:not(#fxp) .eval-group-grid.cat-select .eval-group-icon{width:88px!important;height:88px!important;font-size:34px!important;margin-bottom:16px!important;}
html body:not(#fxp) .eval-group-grid.cat-select .eval-group-title.eval-group-title{font-size:24px!important;margin-bottom:2px!important;}
html body:not(#fxp) .eval-group-grid.cat-select .eval-group-count.eval-group-count{display:inline-flex;margin-top:10px;padding:6px 16px;border-radius:999px;font-size:13.5px!important;font-weight:700;}
html body:not(#fxp) .eval-group-progress{display:flex;flex-direction:column;align-items:center;gap:7px;width:100%;max-width:230px;margin-top:20px;}
html body:not(#fxp) .eval-group-bar{display:block;width:100%;height:8px;border-radius:999px;overflow:hidden;background:rgba(100,116,139,.22);}
html body:not(#fxp) .eval-group-bar i{display:block;height:100%;border-radius:999px;background:var(--g);transition:width .3s ease;}
html body:not(#fxp) .eval-group-progress.complete .eval-group-bar i{background:#16A34A;}
html body:not(#fxp) .eval-group-progress-text{font-size:13px;font-weight:500;}
html body:not(#fxp) .eval-group-progress-text strong{font-weight:800;}
html body:not(#fxp) .eval-group-progress-text.empty{margin-top:20px;}
html body:not(#fxp) .eval-group-cta{display:inline-flex;margin-top:auto;padding-top:20px;font-size:13px;font-weight:800;color:var(--g-ink);}
html body:not(#fxp) .eval-group-cta .cta-select,html body:not(#fxp) .eval-group-cta .cta-selected{display:inline-flex;align-items:center;gap:8px;}
html body:not(#fxp) .eval-group-cta .cta-selected{display:none;}
html body:not(#fxp) .eval-group-card.is-selected .eval-group-cta .cta-select{display:none;}
html body:not(#fxp) .eval-group-card.is-selected .eval-group-cta .cta-selected{display:inline-flex;}
html body:not(#fxp) .eval-group-cta i{font-size:12px;transition:transform .15s ease;}
html body:not(#fxp) .eval-group-card:hover .eval-group-cta .cta-select i{transform:translateX(4px);}
html body:not(#fxp) .eval-group-card:focus-visible{outline:3px solid var(--g);outline-offset:3px;}

html body.light-theme:not(#fxp) .eval-group-grid.cat-select .eval-group-card.eval-group-card{background:#fff!important;border-color:#CDD8E6!important;border-top-color:var(--g)!important;box-shadow:0 1px 2px rgba(11,31,58,.06),0 8px 22px rgba(11,31,58,.06)!important;}
html body.light-theme:not(#fxp) .eval-group-grid.cat-select .eval-group-card.eval-group-card:hover{background:#fbfdff!important;border-color:var(--g)!important;box-shadow:0 14px 32px rgba(11,31,58,.13)!important;transform:translateY(-3px)!important;}
html body.light-theme:not(#fxp) .eval-group-grid.cat-select .eval-group-card.eval-group-card.is-selected{background:#fff!important;border-color:var(--g)!important;box-shadow:0 0 0 3px var(--g-ring),0 12px 28px rgba(11,31,58,.12)!important;}
html body.light-theme:not(#fxp) .eval-group-grid.cat-select .eval-group-title.eval-group-title{color:#0b1f3a!important;}
html body.light-theme:not(#fxp) .eval-group-grid.cat-select .eval-group-count.eval-group-count{background:var(--g-bg);color:var(--g-ink)!important;}
html body.light-theme:not(#fxp) .eval-group-progress-text{color:#475569;}
html body.light-theme:not(#fxp) .eval-group-progress-text strong{color:#0b1f3a;}
html body:not(.light-theme):not(#fxp) .eval-group-grid.cat-select .eval-group-card.eval-group-card{border-top-color:var(--g)!important;}
html body:not(.light-theme):not(#fxp) .eval-group-grid.cat-select .eval-group-card.eval-group-card:hover,html body:not(.light-theme):not(#fxp) .eval-group-grid.cat-select .eval-group-card.eval-group-card.is-selected{border-color:var(--g)!important;}
html body:not(.light-theme):not(#fxp) .eval-group-grid.cat-select .eval-group-count.eval-group-count{background:rgba(255,255,255,.08);color:#E5ECF5!important;}
html body:not(.light-theme):not(#fxp) .eval-group-progress-text{color:#A0B3C6;}
html body:not(.light-theme):not(#fxp) .eval-group-progress-text strong{color:#F1F5F9;}
html body:not(.light-theme):not(#fxp) .eval-group-cta{color:var(--g);}

/* roster */
html body:not(#fxp) .roster-panel{border-radius:18px!important;}
html body:not(#fxp) .roster-head{padding:20px 26px!important;}
html body:not(#fxp) .roster-heading .roster-icon{width:42px!important;height:42px!important;font-size:17px!important;}
html body:not(#fxp) .roster-heading h2{font-size:24px!important;}
html body:not(#fxp) .roster-stats{gap:8px 10px!important;}
html body:not(#fxp) .roster-stats span{padding:6px 14px;border-radius:999px;font-size:13px;font-weight:600;border:1px solid transparent;}
html body:not(#fxp) .roster-stats strong{font-size:15px;font-weight:800;color:inherit;}
html body.light-theme:not(#fxp) .roster-stats span{background:#EEF2F7;color:#334155;border-color:#D3DCE8;}
html body.light-theme:not(#fxp) .roster-stats span:nth-child(2){background:#DDF4E6;color:#0A6A3B;border-color:#9ED8B7;}
html body.light-theme:not(#fxp) .roster-stats span:nth-child(3){background:#FFF1D4;color:#8A5300;border-color:#EBCB88;}
html body.light-theme:not(#fxp) .roster-stats span.period{background:#EEF2FF;color:#3730A3;border-color:#C7D2FE;}
html body:not(.light-theme):not(#fxp) .roster-stats span{background:rgba(255,255,255,.06);color:#C9D6E8;border-color:rgba(255,255,255,.10);}
html body:not(.light-theme):not(#fxp) .roster-stats span:nth-child(2){background:rgba(34,197,94,.14);color:#86EFAC;border-color:rgba(34,197,94,.3);}
html body:not(.light-theme):not(#fxp) .roster-stats span:nth-child(3){background:rgba(245,158,11,.14);color:#F8C465;border-color:rgba(245,158,11,.3);}
html body:not(#fxp) .roster-panel .person-grid{gap:14px!important;padding:22px!important;}
html body:not(#fxp) .roster-panel .person-card{gap:16px!important;padding:16px 18px!important;border-radius:14px!important;border-left-width:4px!important;transition:box-shadow .15s ease;}
html body:not(#fxp) .roster-panel .person-card.is-done{border-left-color:#16A34A!important;}
html body:not(#fxp) .roster-panel .person-card.is-pending{border-left-color:#F59E0B!important;}
html body:not(#fxp) .roster-panel .person-card:hover{box-shadow:0 6px 18px rgba(11,31,58,.10);}
html body:not(#fxp) .roster-panel .person-photo{width:56px!important;height:56px!important;}
html body:not(#fxp) .roster-panel .person-name{font-size:15.5px!important;}
html body:not(#fxp) .roster-panel .person-meta{font-size:13px!important;margin-top:2px!important;}
html body:not(#fxp) .roster-panel .person-card .status-pill{font-size:12px!important;padding:4px 11px!important;margin-top:8px!important;}
html body:not(#fxp) .roster-panel .btn-action{padding:11px 18px!important;border-radius:10px!important;font-size:13px!important;min-width:112px;}
html body.light-theme:not(#fxp) .roster-panel .person-card{background:#fff!important;border-color:#CDD8E6!important;}
html body.light-theme:not(#fxp) .roster-panel .person-name{color:#0b1f3a!important;}
html body.light-theme:not(#fxp) .roster-panel .person-meta{color:#475569!important;}
html body.light-theme:not(#fxp) .roster-panel .person-card .status-pill.done{background:#D6F2E4!important;color:#0A6A3B!important;border-color:#8ED0AD!important;}
html body.light-theme:not(#fxp) .roster-panel .person-card .status-pill.pending{background:#FFF1D4!important;color:#8A5300!important;border-color:#EBCB88!important;}
html body.light-theme:not(#fxp) .roster-panel .btn-action.view{background:#fff!important;color:#1e293b!important;border-color:#b6c3d6!important;}
html body:not(#fxp) .roster-empty{padding:56px 20px!important;font-size:14px!important;}

@media(max-width:900px){html body:not(#fxp) .eval-group-grid.cat-select .eval-group-card.eval-group-card{min-height:250px!important;}}
@media(max-width:560px){html body:not(#fxp) .eval-group-grid.cat-select .eval-group-card.eval-group-card{min-height:0!important;}html body:not(#fxp) .roster-panel .person-card{flex-wrap:wrap;}}

/* ── Staff Evaluation: one target, so show a focused card instead of a picker + table ── */
html body:not(#fxp) .ea-single .ea-toolbar > .ea-status{display:none!important;}
html body:not(#fxp) .ea-single .ea-toolbar{margin-bottom:18px!important;}
html body:not(#fxp) .ea-hero{position:relative;border-radius:18px;border:1px solid var(--border);border-top:5px solid #16A34A;padding:28px 32px 26px;box-shadow:0 1px 2px rgba(11,31,58,.06),0 8px 22px rgba(11,31,58,.06);}
html body:not(#fxp) .ea-hero-main{display:flex;align-items:center;gap:24px;flex-wrap:wrap;}
html body:not(#fxp) .ea-hero-avatar{width:92px;height:92px;flex:0 0 92px;border-radius:50%;object-fit:cover;border:3px solid rgba(22,163,74,.35);}
html body:not(#fxp) .ea-hero-avatar.ph{display:flex;align-items:center;justify-content:center;font-size:34px;}
html body:not(#fxp) .ea-hero-id{flex:1;min-width:220px;}
html body:not(#fxp) .ea-hero-name{margin:8px 0 2px;font-family:'Rajdhani',sans-serif;font-size:30px;font-weight:800;line-height:1.15;}
html body:not(#fxp) .ea-hero-desig{margin:0;font-size:13.5px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;}
html body:not(#fxp) .ea-hero-btn{display:inline-flex;align-items:center;justify-content:center;gap:10px;padding:15px 30px;border-radius:12px;font-size:15px;font-weight:800;text-decoration:none;background:var(--teal);color:#fff;border:1px solid var(--teal);box-shadow:0 6px 16px rgba(22,163,74,.28);transition:transform .15s ease,background .15s ease;}
html body:not(#fxp) a.ea-hero-btn:hover{background:var(--teal-hover);border-color:var(--teal-hover);transform:translateY(-2px);}
html body:not(#fxp) .ea-hero-btn.off{opacity:.6;cursor:not-allowed;box-shadow:none;}
html body:not(#fxp) .ea-hero-btn.done{box-shadow:none;cursor:default;}
html body:not(#fxp) .ea-hero-facts{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px;margin-top:24px;}
html body:not(#fxp) .ea-fact{display:flex;align-items:center;gap:14px;padding:16px 18px;border-radius:14px;border:1px solid transparent;}
html body:not(#fxp) .ea-fact > i{width:42px;height:42px;flex:0 0 42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:17px;}
html body:not(#fxp) .ea-fact span{display:block;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;}
html body:not(#fxp) .ea-fact strong{display:block;margin-top:2px;font-size:15.5px;font-weight:800;line-height:1.3;}
html body.light-theme:not(#fxp) .ea-hero{background:#fff;border-color:#CDD8E6;border-top-color:#16A34A;}
html body.light-theme:not(#fxp) .ea-hero-name{color:#0b1f3a;}
html body.light-theme:not(#fxp) .ea-hero-desig{color:#475569;}
html body.light-theme:not(#fxp) .ea-hero-avatar.ph{background:#DDF5E6;color:#15803D;}
html body.light-theme:not(#fxp) .ea-hero-btn.done{background:#D6F2E4;border-color:#8ED0AD;color:#0A6A3B;}
html body.light-theme:not(#fxp) .ea-fact{background:#F7F9FC;border-color:#D3DCE8;}
html body.light-theme:not(#fxp) .ea-fact > i{background:#DDF5E6;color:#15803D;}
html body.light-theme:not(#fxp) .ea-fact span{color:#475569;}
html body.light-theme:not(#fxp) .ea-fact strong{color:#0b1f3a;}
html body.light-theme:not(#fxp) .ea-fact.is-pending > i{background:#FFF1D4;color:#8A5300;}
html body:not(.light-theme):not(#fxp) .ea-hero{background:var(--mid);border-color:rgba(255,255,255,.10);border-top-color:#16A34A;box-shadow:0 6px 18px rgba(0,0,0,.20);}
html body:not(.light-theme):not(#fxp) .ea-hero-name{color:#F8FAFC;}
html body:not(.light-theme):not(#fxp) .ea-hero-desig{color:#A0B3C6;}
html body:not(.light-theme):not(#fxp) .ea-hero-avatar.ph{background:var(--inner);color:#4ADE80;}
html body:not(.light-theme):not(#fxp) .ea-hero-btn.done{background:rgba(34,197,94,.12);border-color:rgba(34,197,94,.3);color:#86EFAC;}
html body:not(.light-theme):not(#fxp) .ea-fact{background:var(--inner);border-color:rgba(255,255,255,.08);}
html body:not(.light-theme):not(#fxp) .ea-fact > i{background:rgba(34,197,94,.16);color:#4ADE80;}
html body:not(.light-theme):not(#fxp) .ea-fact span{color:#A0B3C6;}
html body:not(.light-theme):not(#fxp) .ea-fact strong{color:#F1F5F9;}
html body:not(.light-theme):not(#fxp) .ea-fact.is-pending > i{background:rgba(245,158,11,.16);color:#F8C465;}
@media(max-width:700px){html body:not(#fxp) .ea-hero{padding:22px 18px;}html body:not(#fxp) .ea-hero-btn{width:100%;}html body:not(#fxp) .ea-hero-name{font-size:25px;}}
</style>
</head>
        <body class="light-theme">

        <!-- SIDEBAR -->
                <aside class="sidebar" id="sidebar">
            <div class="sidebar-brand portal-brand">
                <div class="portal-brand-logo"><img src="../image/pbi_logo" alt="PBI" onerror="this.style.display='none'"/></div>
                <div class="portal-brand-copy">
                    <strong>Staff Workspace</strong>
                </div>
            </div>

            <div class="portal-sidebar-profile">
                <div class="portal-profile-avatar-wrap">
                    <?php if ($staff_photo): ?>
                    <img class="portal-profile-avatar" src="<?= htmlspecialchars($photo_url) ?>" alt="<?= htmlspecialchars($full_name) ?>"/>
                    <?php else: ?>
                    <span class="portal-profile-avatar portal-profile-fallback"><?= htmlspecialchars($initials) ?></span>
                    <?php endif; ?>
                </div>
                <div class="portal-profile-name"><?= htmlspecialchars($full_name) ?></div>
                <div class="portal-profile-role"><?= htmlspecialchars(trim((string)$designation) !== '' ? $designation : 'Staff') ?><?= !empty($my_levels) ? ' · ' . htmlspecialchars(implode(', ', $my_levels)) : '' ?></div>
            </div>

            <nav class="sidebar-nav portal-sidebar-nav">
                <div class="nav-section-label">Main</div>
                <a href="staff_dashboard.php?page=dashboard" class="nav-link <?= $page==='dashboard'?'active':'' ?>">
                    <i class="fa-solid fa-house"></i><span>Dashboard</span>
                </a>
                <a href="staff_dashboard.php?page=profile" class="nav-link <?= $page==='profile'?'active':'' ?>">
                    <i class="fa-solid fa-id-badge"></i><span>Role &amp; Designation</span>
                </a>

                <div class="nav-section-label sidebar-section-secondary">Evaluation</div>
                <a href="staff_dashboard.php?page=my_results" class="nav-link <?= $page==='my_results'?'active':'' ?>">
                    <i class="fa-solid fa-chart-bar"></i><span>Evaluations Received</span>
                </a>
                <?php if (!$staff_has_teaching_assignment): ?>
                <a href="staff_dashboard.php?page=staff_eval" class="nav-link <?= in_array($page,['staff_eval','staff_eval_form'])?'active':'' ?>">
                    <i class="fa-solid fa-users"></i><span>Staff Evaluation</span>
                    <?php if (!empty($staff_eval_targets) && $page==='staff_eval'): ?>
                    <span class="side-nav-badge"><?= count($staff_eval_targets) ?></span>
                    <?php endif; ?>
                </a>
                <?php else: ?>
                <a href="staff_dashboard.php?page=peer" class="nav-link <?= in_array($page,['peer','peer_eval','staff_eval','staff_eval_form'])?'active':'' ?>">
                    <i class="fa-solid fa-people-arrows"></i><span>Peer-to-Peer Evaluation</span>
                </a>
                <?php endif; ?>

                <div class="nav-section-label sidebar-section-secondary">Support</div>
                <a href="staff_dashboard.php?page=settings" class="nav-link <?= $page==='settings'?'active':'' ?>">
                    <i class="fa-solid fa-gear"></i><span>Settings</span>
                </a>
            </nav>

            <div class="sidebar-footer">
                <a href="staff_logout.php" class="btn-logout-side" onclick="return openLogoutModal(this)">
                    <i class="fa-solid fa-power-off"></i><span>Log Out</span>
                </a>
            </div>
        </aside>

        <!-- PROFILE PHOTO MODAL -->
        <div class="photo-modal-overlay" id="photoModal">
            <div class="photo-modal">
                <div class="photo-modal-header">
                    <div class="photo-modal-title">Profile Photo</div>
                    <button style="background:none;border:none;color:var(--muted);font-size:18px;cursor:pointer;" onclick="closePhotoModal()">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
                <div class="photo-modal-body">
                    <form method="POST" action="staff_dashboard.php" enctype="multipart/form-data" id="photoForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>"/>
                        <input type="hidden" name="return_page" value="<?= in_array($page, ['settings', 'profile'], true) ? htmlspecialchars($page) : 'profile' ?>"/>
                        <input type="hidden" name="portal_role" value="staff"/>
                        <div class="photo-upload-circle" id="photoCircle" onclick="document.getElementById('photoFileInput').click()">
                            <img id="photoPreviewImg" src="<?= $staff_photo ? htmlspecialchars($photo_url) : '' ?>"
                                 style="<?= $staff_photo ? 'display:block' : '' ?>"/>
                            <i class="fa-solid fa-camera upload-icon-modal" id="uploadIconEl" style="<?= $staff_photo ? 'display:none' : '' ?>"></i>
                        </div>
                        <div class="photo-upload-hint-modal">
                            Click to choose a photo<br>
                            <span onclick="document.getElementById('photoFileInput').click()">Browse files</span>
                            &nbsp;·&nbsp; JPG, PNG, WebP · Max 10MB
                        </div>
                        <input type="file" id="photoFileInput" name="photo" accept="image/jpeg,image/png,image/webp,image/gif"
                               onchange="previewPhoto(this)" style="display:none"/>
                    </form>
                </div>
                <div class="photo-modal-footer">
                    <button class="btn-skip-photo" onclick="closePhotoModal()">Skip / Cancel</button>
                    <button class="btn-save-photo" onclick="submitPhoto()"><i class="fa-solid fa-check"></i> Save Photo</button>
                </div>
            </div>
        </div>

        <!-- EVALUATION DETAILS MODAL -->
        <div class="eval-modal-overlay" id="evalDetailsModal">
            <div class="eval-modal">
                <div class="eval-modal-header">
                    <div class="eval-modal-title"><i class="fa-solid fa-star" style="color:var(--teal-hover);margin-right:8px;"></i>Evaluation Details</div>
                    <button class="eval-modal-close" onclick="closeEvalDetails()"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <div class="eval-modal-body" id="evalDetailsBody">
                    <div class="eval-modal-loading"><i class="fa-solid fa-spinner fa-spin"></i>Loading evaluation…</div>
                </div>
            </div>
        </div>

        <!-- TOP NAV -->
        <nav class="top-nav">
            <div style="display:flex;align-items:center;gap:14px;">
                <button class="hamburger" onclick="document.getElementById('sidebar').classList.toggle('open')">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="nav-page-title">
                    <?php
                    $titles = ['dashboard'=>'Dashboard','profile'=>'Role & Designation','my_results'=>"Evaluations Received",
                        'staff_eval'=> $staff_has_teaching_assignment ? 'Evaluate Others' : 'Staff Evaluation',
                        'staff_eval_form'=> $staff_has_teaching_assignment ? 'Evaluate Others' : 'Staff Evaluation',
                        'peer'=>'Evaluate Others','peer_eval'=>'Evaluate Others','settings'=>'Settings'];
                    $p2p_icon = 'fa-clipboard-check';
                    $title_icons = ['dashboard'=>'fa-house','profile'=>'fa-id-badge','my_results'=>'fa-chart-bar',
                        'staff_eval'=> $staff_has_teaching_assignment ? $p2p_icon : 'fa-users',
                        'staff_eval_form'=> $staff_has_teaching_assignment ? $p2p_icon : 'fa-users',
                        'peer'=>$p2p_icon,'peer_eval'=>$p2p_icon,'settings'=>'fa-gear'];
                    $title_key = isset($titles[$page]) ? $page : 'dashboard';
                    ?>
                    <i class="fa-solid <?= $title_icons[$title_key] ?>"<?= $title_icons[$title_key] === 'fa-clipboard-check' ? ' style="color:#16A34A"' : '' ?> aria-hidden="true"></i>
                    <span><?= htmlspecialchars($titles[$title_key]) ?></span>
                </div>
            </div>
            <div class="nav-right">
                <?php if ($period): ?>
                <div class="period-badge">
                    <i class="fa-solid fa-calendar-check"></i>
                    <?= htmlspecialchars($period['period_label']) ?>
                </div>
                <?php endif; ?>

                <div class="notif-wrap" id="notifWrap">
                    <button class="notif-btn <?= $unread_count>0?'has-unread':'' ?>" id="notifBtn" onclick="toggleNotifDropdown(event)" title="Notifications">
                        <i class="fa-regular fa-bell"></i>
                        <?php if ($unread_count > 0): ?>
                        <span class="notif-badge" id="notifBadge"><?= $unread_count > 99 ? '99+' : $unread_count ?></span>
                        <?php endif; ?>
                    </button>
                    <div class="notif-dropdown" id="notifDropdown">
                        <div class="notif-header">
                            <span class="notif-header-title"><i class="fa-solid fa-bell" style="color:var(--teal-hover);"></i> Notifications</span>
                            <form method="POST" style="margin:0;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>"/>
                                <input type="hidden" name="mark_notifications_read" value="1"/>
                                <button type="submit" class="notif-mark-read">Mark all read</button>
                            </form>
                        </div>
                        <div class="notif-list">
                            <?php if (empty($my_notifications)): ?>
                            <div class="notif-empty"><i class="fa-regular fa-bell-slash"></i>No notifications yet.</div>
                            <?php else: foreach ($my_notifications as $n):
                                $is_eval = $n['type'] === 'evaluation_received';
                                $n_icon  = $is_eval ? 'fa-star' : 'fa-id-badge';
                                $n_color = $is_eval ? '#facc15' : '#2B6CB0';
                            ?>
                            <a class="notif-item <?= empty($n['is_read'])?'unread':'' ?>" data-notification-id="<?= (int)$n['id'] ?>" href="<?= htmlspecialchars(notif_link_for((string)$n['type'], 'staff')) ?>">
                                <div class="notif-icon" style="color:<?= $n_color ?>;background:<?= $n_color ?>22;"><i class="fa-solid <?= $n_icon ?>"></i></div>
                                <div style="flex:1;min-width:0;">
                                    <div class="notif-text"><?= htmlspecialchars($n['message']) ?></div>
                                    <div class="notif-meta"><?= date('M d, Y g:i A', strtotime($n['created_at'])) ?></div>
                                </div>
                            </a>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </nav>

        <!-- MAIN -->
        <main class="main">

        <?php if ($toast): ?>
        <div class="toast toast-success"><i class="fa-solid fa-circle-check"></i><?= htmlspecialchars($toast) ?></div>
        <?php endif; ?>
        <?php if ($toast_error): ?>
        <div class="toast toast-error"><i class="fa-solid fa-circle-exclamation"></i><?= htmlspecialchars($toast_error) ?></div>
        <?php endif; ?>

        <!-- ══════════ DASHBOARD ══════════ -->
        <?php if ($page === 'dashboard'): ?>

        <div class="dashboard-shell">
            <div class="dashboard-intro">
        <?php
        // Countdown to opening: only while closed, on a schedule, and the start is still ahead.
        $fsOpensAtIso = '';
        if (!$evaluation_open && $dashboard_start && ($dashboard_sys['control_mode'] ?? 'schedule') === 'schedule') {
            try {
                $fsNowDt = new DateTimeImmutable('now', $dashboard_start->getTimezone());
                if ($dashboard_start > $fsNowDt) $fsOpensAtIso = $dashboard_start->format('c');
            } catch (Throwable $e) { $fsOpensAtIso = ''; }
        }
        ?>
        <style>
        .dashboard-intro .status-row{display:flex;align-items:center;flex-wrap:wrap;gap:10px;}
        .fs-open-cd{display:inline-flex;align-items:baseline;gap:5px;font-size:11.5px;font-weight:700;color:#B42318;}
        .fs-open-cd-sep{color:#F3B0B0;margin-right:3px;font-weight:400;}
        .fs-open-cd .fs-cd-unit[hidden]{display:none;}
        .fs-open-cd .fs-cd-unit b{font-weight:800;font-variant-numeric:tabular-nums;color:inherit;}
        body:not(.light-theme) .fs-open-cd,body:not(.light-theme) .fs-open-cd .fs-cd-unit b{color:#F87171;}
        body:not(.light-theme) .fs-open-cd-sep{color:#7F3A3A;}
        </style>
        <?php if ($period): ?>
        <div class="status-row">
            <span class="status-pill <?= $evaluation_open ? '' : 'closed' ?>">
                <i class="fa-solid <?= $evaluation_open ? 'fa-lock-open' : 'fa-lock' ?>"></i>
                <?= $evaluation_open ? 'Evaluation period is currently open' : ($fsOpensAtIso !== '' ? 'Evaluation is currently closed' : 'Evaluation is currently closed — waiting for the scheduled opening') ?>
                <?php if ($fsOpensAtIso !== ''): ?>
                <span class="fs-open-cd" id="fsOpenCountdown" data-open="<?= htmlspecialchars($fsOpensAtIso) ?>" aria-live="off">
                    <span class="fs-open-cd-sep" aria-hidden="true">|</span>
                    <span class="fs-open-cd-label">Opens in</span>
                    <span class="fs-cd-unit" id="fs-oc-days-wrap" hidden><b id="fs-oc-days">0</b> D</span>
                    <span class="fs-cd-unit" id="fs-oc-hours-wrap" hidden><b id="fs-oc-hours">0</b> H</span>
                    <span class="fs-cd-unit"><b id="fs-oc-mins">0</b> M</span>
                    <span class="fs-cd-unit"><b id="fs-oc-secs">0</b> s</span>
                </span>
                <?php endif; ?>
            </span>
            <?php if ($fsOpensAtIso !== ''): ?>
            <script>
            (function(){
                var row = document.getElementById('fsOpenCountdown');
                if (!row) return;
                var openAt = new Date(row.dataset.open).getTime();
                var el = function(id){ return document.getElementById(id); };
                var timer = null;
                function tick(){
                    var diff = Math.max(0, openAt - Date.now());
                    var days = Math.floor(diff / 86400000); diff -= days * 86400000;
                    var hours = Math.floor(diff / 3600000); diff -= hours * 3600000;
                    var mins = Math.floor(diff / 60000); diff -= mins * 60000;
                    var secs = Math.floor(diff / 1000);
                    el('fs-oc-days').textContent = days;
                    el('fs-oc-hours').textContent = hours;
                    el('fs-oc-mins').textContent = mins;
                    el('fs-oc-secs').textContent = secs;
                    el('fs-oc-days-wrap').hidden = days === 0;
                    el('fs-oc-hours-wrap').hidden = days === 0 && hours === 0;
                    if (openAt - Date.now() <= 0) {
                        clearInterval(timer);
                        // Reload once so the page switches to "open" (guard prevents a reload loop).
                        var key = 'fsOpenReload:' + row.dataset.open;
                        try { if (sessionStorage.getItem(key)) return; sessionStorage.setItem(key, '1'); } catch (e) {}
                        setTimeout(function(){ location.reload(); }, 1500);
                    }
                }
                tick();
                timer = setInterval(tick, 1000);
            })();
            </script>
            <?php endif; ?>
        </div>
        <?php endif; ?>
            </div>

        <div class="evaluation-schedule-card">
            <div class="schedule-summary-grid">
                <div class="schedule-summary-item"><span class="schedule-label">Academic Year</span><strong><?= htmlspecialchars($dashboard_acad_year ?: '—') ?></strong></div>
                <div class="schedule-summary-item"><span class="schedule-label">Evaluation Opens</span><strong><?= htmlspecialchars($dashboard_open_display) ?></strong></div>
                <div class="schedule-summary-item"><span class="schedule-label">Evaluation Closes</span><strong><?= htmlspecialchars($dashboard_close_display) ?></strong></div>
                <div class="schedule-summary-item schedule-status-item"><span class="schedule-label">Status</span><span class="schedule-status <?= htmlspecialchars($dashboard_status_class) ?>"><?= htmlspecialchars($dashboard_status) ?></span></div>
            </div>
        </div>

        <div class="welcome-bar">
            <div>
                <h2>Welcome, <?= htmlspecialchars(explode(',', $full_name)[0] ?? $full_name) ?>!</h2>
                <p><?= htmlspecialchars($designation) ?> &nbsp;·&nbsp;
                <?= $period ? htmlspecialchars($period['period_label']) : 'No active evaluation period' ?></p>
            </div>
        </div>

        <div class="stats-grid">
            <a class="stat-box stat-box-link" href="staff_dashboard.php?page=my_results" aria-label="View Evaluations Received" title="View Evaluations Received"><div class="stat-box-icon green"><i class="fa-solid fa-users" aria-hidden="true"></i></div><div class="stat-box-lbl">Evaluations Received</div><div class="stat-box-val teal"><?= $my_total ?></div></a>
            <div class="stat-box"><div class="stat-box-icon mint"><i class="fa-solid fa-circle-check"></i></div><div class="stat-box-lbl">Average Rating</div><div class="stat-box-val gold"><?= $my_avg !== null ? number_format($my_avg,2).' / 5' : '—' ?></div></div>
            <div class="stat-box"><div class="stat-box-icon gold"><i class="fa-solid fa-award"></i></div><div class="stat-box-lbl">Performance Level</div>
                <div class="stat-box-val" style="font-size:<?= $perf_long ? '15px;line-height:1.25' : '18px' ?>;color:<?= $perf_color ?>">
                    <?= htmlspecialchars($perf_label) ?>
                </div></div>
            <div class="stat-box"><div class="stat-box-icon sky"><i class="fa-solid fa-calendar"></i></div><div class="stat-box-lbl">Current Period</div>
                <div class="stat-box-val" style="font-size:16px;color:var(--teal-hover)"><?= $period?htmlspecialchars($period['semester']):'None' ?></div></div>
        </div>

        <section class="dashboard-quick-access" aria-label="Quick access">
            <div class="quick-access-heading">
                <div>
                    <span class="quick-access-kicker">WORKSPACE SHORTCUTS</span>
                    <h2>Continue where you need to be</h2>
                </div>
                <span class="quick-access-caption"></span>
            </div>
            <div class="quick-access-grid">
                <a class="quick-access-card quick-green" href="staff_dashboard.php?page=my_results">
                    <span class="quick-access-icon"><i class="fa-solid fa-chart-column"></i></span>
                    <span class="quick-access-copy">
                        <strong>Evaluations Received</strong>
                        <small>Review your feedback and evaluation results.</small>
                    </span>
                    <i class="fa-solid fa-arrow-right quick-access-arrow" aria-hidden="true"></i>
                </a>
                <?php if ($staff_has_teaching_assignment): ?>
                <a class="quick-access-card quick-blue" href="staff_dashboard.php?page=peer">
                    <span class="quick-access-icon"><i class="fa-solid fa-users"></i></span>
                    <span class="quick-access-copy">
                        <strong>Your Evaluation</strong>
                        <small>Open the evaluation tasks assigned to you.</small>
                    </span>
                    <i class="fa-solid fa-arrow-right quick-access-arrow" aria-hidden="true"></i>
                </a>
                <?php else: ?>
                <a class="quick-access-card quick-blue" href="staff_dashboard.php?page=staff_eval">
                    <span class="quick-access-icon"><i class="fa-solid fa-clipboard-check"></i></span>
                    <span class="quick-access-copy">
                        <strong>Your Evaluation</strong>
                        <small>Open the evaluation tasks assigned to you.</small>
                    </span>
                    <i class="fa-solid fa-arrow-right quick-access-arrow" aria-hidden="true"></i>
                </a>
                <?php endif; ?>
                <a class="quick-access-card quick-amber" href="staff_dashboard.php?page=profile">
                    <span class="quick-access-icon"><i class="fa-solid fa-id-card"></i></span>
                    <span class="quick-access-copy">
                        <strong>Role &amp; Designation</strong>
                        <small>View your role, designation, and assigned levels.</small>
                    </span>
                    <i class="fa-solid fa-arrow-right quick-access-arrow" aria-hidden="true"></i>
                </a>
            </div>
        </section>

        </div>
        <!-- ══════════ MY PROFILE & ROLE ══════════ -->
        <?php elseif ($page === 'profile'): ?>

        <div class="dashboard-shell settings-shell role-shell">

        <!-- Hero card with photo, name, and current role. -->
        <div class="profile-hero">
            <div class="profile-hero-left">
                <div style="position:relative;">
                    <?php if ($photo_url): ?>
                    <img class="profile-hero-avatar" src="<?= htmlspecialchars($photo_url) ?>" alt="<?= htmlspecialchars($full_name) ?>" style="display:block;"/>
                    <?php else: ?>
                    <div class="profile-hero-avatar"><i class="fa-solid fa-briefcase"></i></div>
                    <?php endif; ?>
                    <button type="button" onclick="openPhotoModal()" title="Update Profile Photo"
                            style="position:absolute;bottom:-2px;right:-2px;width:30px;height:30px;border-radius:50%;background:var(--teal);border:2px solid var(--mid);color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:13px;">
                        <i class="fa-solid fa-camera"></i>
                    </button>
                </div>
                <div>
                    <div class="profile-hero-name"><?= htmlspecialchars($full_name) ?></div>
                    <div class="profile-hero-desig">
                        <i class="fa-solid fa-id-badge"></i> <?= htmlspecialchars($designation) ?>
                    </div>
                    <button type="button" onclick="openPhotoModal()" style="margin-top:6px;background:none;border:none;color:var(--teal);font-size:12px;font-weight:600;cursor:pointer;padding:0;">
                        <i class="fa-solid fa-camera"></i> Update Profile Photo
                    </button>
                </div>
            </div>

            <div class="level-view-wrap" id="levelDdWrap">
                <div class="level-view-label"><i class="fa-solid fa-layer-group"></i> My Teaching Level(s)</div>
                <div class="level-view-pills">
                    <?php if (empty($my_levels)): ?>
                    <span class="level-view-empty"><i class="fa-solid fa-triangle-exclamation"></i> No level assigned yet — contact the admin.</span>
                    <?php else: foreach ($my_levels as $lvl): ?>
                    <span class="level-view-pill"><?= htmlspecialchars($lvl) ?></span>
                    <?php endforeach; endif; ?>
                </div>
                <div class="level-view-hint">Set by the admin — reach out to them to change this.</div>
            </div>
        </div>

        <!-- ROLE ASSIGNMENT CARD -->
        <div class="role-card">
            <div class="role-card-title">
                <i class="fa-solid fa-tags" style="color:var(--teal)"></i>
                Assign / Update My Role
            </div>
            <div class="role-card-sub">
                Your role determines which evaluation questions apply to you and how you appear in the questionnaire.
                Changes take effect immediately and the admin is notified.
            </div>

            <!-- Quick pick chips -->
            <div class="role-chips-label">
                <i class="fa-solid fa-bolt" style="color:var(--teal)"></i> Quick Pick
            </div>
            <div class="role-chips">
                <?php foreach ($desig_suggestions as $d): ?>
                <div class="role-chip <?= ($d === $designation) ? 'is-current' : '' ?>"
                     onclick="pickRole('<?= htmlspecialchars(addslashes($d)) ?>')">
                    <?= htmlspecialchars($d) ?>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Custom text input -->
            <div class="custom-role-label">
                <i class="fa-solid fa-pen-to-square" style="color:var(--teal)"></i> Or type a custom role
            </div>
            <form method="POST" id="roleForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>"/>
                <input type="hidden" name="update_designation" value="1"/>
                <div class="custom-role-row">
                    <input type="text" name="new_designation" id="roleInput" class="custom-role-input"
                           placeholder="e.g. Department Head, Bookkeeper…"
                           value="<?= htmlspecialchars($designation) ?>" required/>
                    <button type="submit" class="btn-save-role">
                        <i class="fa-solid fa-floppy-disk"></i> Save &amp; Notify Admin
                    </button>
                </div>
            </form>

            <div class="info-note">
                <i class="fa-solid fa-circle-info"></i>
                <span>Your role change is logged and sent to the admin automatically so they can confirm or reassign you if needed.</span>
            </div>
        </div>

        </div><!-- /role-shell -->

        <?php elseif ($page === 'settings'): ?>

<div class="dashboard-shell settings-shell principal-settings-layout" style="--ap-accent:#16A34A;--ap-accent-strong:#15803D;--ap-accent-dark:#4ADE80;--ap-accent-rgb:22,163,74;">
    <div class="settings-tabs" role="tablist" aria-label="Settings sections">
        <button type="button" class="settings-tab active" id="settingsTabProfile" role="tab" aria-selected="true" aria-controls="settingsPanelProfile" data-settings-tab="profile"><i class="fa-solid fa-user-gear"></i> Profile &amp; Security</button>
        <button type="button" class="settings-tab" id="settingsTabAppearance" role="tab" aria-selected="false" aria-controls="settingsPanelAppearance" data-settings-tab="appearance"><i class="fa-solid fa-palette"></i> Appearance</button>
    </div>

    <div class="settings-layout-grid settings-tab-panel" id="settingsPanelProfile" role="tabpanel" aria-labelledby="settingsTabProfile">
        <div class="settings-card full ac-card ac-c5 sq-top-card <?= $sq_status === 'ok' ? 'is-set' : 'is-unset' ?>" id="security-questions-settings">
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

        <section class="settings-card full ac-card ac-c2"><div class="ac-head"><span class="ac-icon"><i class="fa-solid fa-id-badge"></i></span><div><h2>Profile Photo</h2><p class="ac-sub">Update the photo shown in your sidebar and profile.</p></div></div><div class="profile-card"><?php if ($staff_photo && $photo_url): ?><img class="profile-photo-lg" src="<?= htmlspecialchars($photo_url) ?>" alt="Staff profile photo"/><?php else: ?><div class="profile-photo-lg profile-photo-fallback"><i class="fa-solid fa-user"></i></div><?php endif; ?><div class="photo-form-wrap"><button class="btn-primary" type="button" onclick="openPhotoModal()"><i class="fa-solid fa-upload"></i> Upload New Photo</button><div class="photo-help">JPG, PNG, WEBP, or GIF only. Maximum file size: 10MB.</div></div></div></section>

        <section class="settings-card full ac-card ac-c3"><div class="ac-head"><span class="ac-icon"><i class="fa-solid fa-user-pen"></i></span><div><h2>Profile Details</h2><p class="ac-sub">Update your name, username, and designation.</p></div></div><form method="post" action="staff_dashboard.php?page=settings" class="profile-details-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>"><input type="hidden" name="update_account_name" value="1"><div class="form-grid"><div class="form-group"><label for="staffSettingsName">Full Name</label><input id="staffSettingsName" type="text" name="full_name" maxlength="150" required value="<?= htmlspecialchars($full_name) ?>" autocomplete="name"></div><div class="form-group"><label for="staffSettingsUsername">Username</label><input id="staffSettingsUsername" type="text" name="username" maxlength="50" pattern="\S+" title="Username cannot contain spaces" required value="<?= htmlspecialchars($account_username) ?>" autocomplete="username"></div><div class="form-group"><label for="staffSettingsDesignation">Designation</label><input id="staffSettingsDesignation" type="text" name="designation" maxlength="100" required value="<?= htmlspecialchars($designation) ?>"></div></div><button class="btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button></form></section>

        <section class="settings-card full ac-card ac-c4"><div class="ac-head"><span class="ac-icon"><i class="fa-solid fa-lock"></i></span><div><h2>Change Password</h2><p class="ac-sub">Your new password must be at least 8 characters and different from your current password.</p></div></div><form method="post" action="staff_dashboard.php?page=settings" class="profile-details-form" id="staffSettingsPasswordForm" autocomplete="off"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>"><input type="hidden" name="update_password" value="1"><div class="form-grid"><div class="form-group"><label for="staffCurrentPassword">Current Password</label><input id="staffCurrentPassword" type="password" name="current_password" required autocomplete="current-password"></div><div class="form-group"></div><div class="form-group"><label for="staffNewPassword">New Password</label><input id="staffNewPassword" type="password" name="new_password" minlength="8" required autocomplete="new-password"></div><div class="form-group"><label for="staffConfirmPassword">Confirm New Password</label><input id="staffConfirmPassword" type="password" name="confirm_password" minlength="8" required autocomplete="new-password"></div></div><button class="btn-primary" type="submit"><i class="fa-solid fa-key"></i> Update Password</button></form></section>

        
    </div>

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
.settings-shell .sq-top-card.is-unset{border:2px solid #E2A33B !important;box-shadow:0 0 0 4px rgba(226,163,59,.16) !important}
.settings-shell .sq-top-card.is-unset::before{background:#E2A33B !important}
.settings-shell .sq-top-card.is-unset .ac-icon{background:rgba(226,163,59,.18) !important;color:#D97706 !important}
.settings-shell .sq-top-card.is-unset .ac-icon i{color:#D97706 !important}

.sqm-overlay{position:fixed;inset:0;z-index:2147483000;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(11,31,58,.55);overscroll-behavior:contain}
.sqm-overlay[hidden]{display:none}
.sqm-modal{--sq-acc:#16A34A;--sq-acc-h:#22C55E;--sq-line:rgba(128,140,160,.30);width:min(620px,100%);max-height:calc(100vh - 40px);overflow:auto;background:var(--mid,#fff);border:1px solid var(--sq-line);border-radius:16px;padding:20px 22px;box-shadow:0 24px 60px rgba(11,31,58,.35);color:var(--light,#0B1F3A)}
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
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
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
    fetch('staff_dashboard.php',{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}})
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
    fetch('staff_dashboard.php',{method:'POST',body:new FormData(form),credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(r){ return r.json(); })
      .then(function(d){
        if(d&&d.ok){ location.href='staff_dashboard.php?page=settings'; return; }
        showErr((d&&d.message)||'The security questions could not be saved. Please try again.'); md.scrollTop=0;
      })
      .catch(function(){ showErr('Could not reach the server. Please try again.'); })
      .then(function(){ busy=false; save.textContent='Save questions'; sync(); });
  });
})();
</script>

    <div class="settings-layout-grid settings-tab-panel" id="settingsPanelAppearance" role="tabpanel" aria-labelledby="settingsTabAppearance" hidden>
        <section class="settings-card full ac-card ac-c1" id="principal-style-appearance">
            <div class="ac-head appearance-heading-row"><span class="ac-icon"><i class="fa-solid fa-palette"></i></span><div><h2>Appearance</h2><p class="ac-sub">Choose your theme, content spacing, and motion preferences.</p></div><span class="appearance-device-badge"><i class="fa-solid fa-laptop"></i> Saved on this device</span></div>
            <div class="appearance-feature-grid">
                <section class="appearance-control-card appearance-theme-card" aria-labelledby="appearanceThemeHeading">
                    <div class="appearance-control-head"><span class="appearance-control-icon"><i class="fa-solid fa-circle-half-stroke"></i></span><div><h3 id="appearanceThemeHeading">Theme mode</h3><p>Choose a look that suits your environment.</p></div></div>
                    <div class="appearance-theme-options" role="group" aria-label="Theme mode">
                        <button type="button" class="appearance-choice" id="appearanceLightBtn" data-theme-choice="light" aria-pressed="false" onclick="setAppearance('light')"><span class="appearance-choice-icon"><i class="fa-solid fa-sun"></i></span><span class="appearance-choice-copy"><strong>Light</strong><small>Bright and clean</small></span><span class="appearance-choice-check"><i class="fa-solid fa-check"></i></span></button>
                        <button type="button" class="appearance-choice" id="appearanceDarkBtn" data-theme-choice="dark" aria-pressed="false" onclick="setAppearance('dark')"><span class="appearance-choice-icon"><i class="fa-solid fa-moon"></i></span><span class="appearance-choice-copy"><strong>Dark</strong><small>Low-light friendly</small></span><span class="appearance-choice-check"><i class="fa-solid fa-check"></i></span></button>
                        <button type="button" class="appearance-choice" id="appearanceSystemBtn" data-theme-choice="system" aria-pressed="false" onclick="setAppearance('system')"><span class="appearance-choice-icon"><i class="fa-solid fa-desktop"></i></span><span class="appearance-choice-copy"><strong>System</strong><small>Follow device setting</small></span><span class="appearance-choice-check"><i class="fa-solid fa-check"></i></span></button>
                    </div>
                    <div class="appearance-inline-status"><i class="fa-solid fa-circle-info"></i> Current mode: <strong id="appearanceCurrentMode">Light</strong></div>
                </section>
                <section class="appearance-control-card" aria-labelledby="appearanceLayoutHeading"><div class="appearance-control-head"><span class="appearance-control-icon"><i class="fa-solid fa-sliders"></i></span><div><h3 id="appearanceLayoutHeading">Interface spacing</h3><p>Choose how much space appears around tables and content.</p></div></div><div class="appearance-density-options" role="group" aria-label="Interface spacing"><button type="button" class="appearance-density-choice" data-density-choice="comfortable" aria-pressed="false" onclick="setPrincipalDensity('comfortable')"><i class="fa-solid fa-expand"></i><span><strong>Comfortable</strong><small>More breathing room</small></span></button><button type="button" class="appearance-density-choice" data-density-choice="compact" aria-pressed="false" onclick="setPrincipalDensity('compact')"><i class="fa-solid fa-compress"></i><span><strong>Compact</strong><small>More content on screen</small></span></button></div></section>
                <section class="appearance-control-card appearance-accessibility-card" aria-labelledby="appearanceAccessibilityHeading"><div class="appearance-control-head"><span class="appearance-control-icon"><i class="fa-solid fa-universal-access"></i></span><div><h3 id="appearanceAccessibilityHeading">Accessibility</h3><p>Reduce interface movement for a calmer experience.</p></div></div><button type="button" class="appearance-motion-toggle" id="appearanceMotionBtn" aria-pressed="false" onclick="togglePrincipalReducedMotion()"><span class="appearance-motion-copy"><strong>Reduce animations</strong><small>Minimize transitions and motion effects</small></span><span class="appearance-switch-track" aria-hidden="true"><span class="appearance-switch-thumb"></span></span></button><div class="appearance-accessibility-note"><i class="fa-solid fa-shield-heart"></i> Your settings stay in this browser and do not change other users' views.</div></section>
            </div>
        </section>
    </div>

    <script>
    (function(){
        var tabs = document.querySelectorAll('[data-settings-tab]');
        var panels = {profile: document.getElementById('settingsPanelProfile'), appearance: document.getElementById('settingsPanelAppearance')};
        if (!tabs.length || !panels.profile || !panels.appearance) return;
        function show(name, updateHash) {
            if (!panels[name]) name = 'profile';
            Object.keys(panels).forEach(function(k){ panels[k].hidden = (k !== name); });
            tabs.forEach(function(t){
                var on = t.getAttribute('data-settings-tab') === name;
                t.classList.toggle('active', on);
                t.setAttribute('aria-selected', on ? 'true' : 'false');
                t.tabIndex = on ? 0 : -1;
            });
            if (updateHash && window.history && history.replaceState) history.replaceState(null, '', name === 'profile' ? location.pathname + location.search : '#appearance');
        }
        tabs.forEach(function(t, i){
            t.addEventListener('click', function(){ show(t.getAttribute('data-settings-tab'), true); });
            t.addEventListener('keydown', function(e){
                if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
                var n = tabs[(i + (e.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
                n.focus(); show(n.getAttribute('data-settings-tab'), true);
            });
        });
        show(location.hash === '#appearance' ? 'appearance' : 'profile', false);
    })();
    </script>
</div>

<?php elseif ($page === 'my_results'): ?>

<?php render_received_results($mysqli, (int)$user_id); ?>

<!-- ══════════ STAFF EVALUATION (EA / Dean / Principal, per role) ══════════ -->
        <?php elseif ($page === 'staff_eval' && !$staff_has_teaching_assignment): ?>

        <!-- ══════════ STAFF EVALUATION (Executive Assistant, non-teaching Staff only) ══════════ -->
        <?php $eaSingle = count($staff_eval_targets) === 1; ?>
        <div class="ea-eval-wrap dashboard-shell<?= $eaSingle ? ' ea-single' : '' ?>">
            <?php if ($period): ?>
            <div class="dashboard-intro">
                <span class="status-pill <?= $evaluation_open ? '' : 'closed' ?>">
                    <i class="fa-solid <?= $evaluation_open ? 'fa-lock-open' : 'fa-lock' ?>"></i>
                    <?= $evaluation_open ? 'Evaluation period is currently open' : 'Evaluation is currently closed — waiting for the scheduled opening' ?>
                </span>
            </div>
            <?php endif; ?>
            <?php if (!$eaSingle): ?>
            <div class="ea-tab-row">
                <?php foreach ($staff_eval_targets as $target): ?>
                    <?php $isDoneTarget = false;
                    if ($period) {
                        $chk = $mysqli->prepare("SELECT id FROM evaluation_tracker WHERE evaluator_id=? AND target_user_id=? AND period_id=? AND eval_type='staff' AND status='submitted' LIMIT 1");
                        $chk->bind_param('iii', $user_id, $target['id'], $period['id']);
                        $chk->execute();
                        $isDoneTarget = (bool)$chk->get_result()->fetch_assoc();
                        $chk->close();
                    }
                    ?>
                    <a class="ea-tab <?= $staff_eval_target && $staff_eval_target['target_type'] === $target['target_type'] ? 'active' : '' ?>"
                       href="staff_dashboard.php?page=<?= $staff_eval_home_page ?>&tid=<?= (int)$target['id'] ?>">
                        <i class="fa-solid <?= $target['target_type']==='Dean' ? 'fa-graduation-cap' : ($target['target_type']==='Principal' ? 'fa-user-tie' : 'fa-user-shield') ?>"></i>
                        <?= htmlspecialchars($target['target_label']) ?>
                        <span class="count"><?= $isDoneTarget ? '✓' : '1' ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="ea-toolbar">
                <div>
                    <span class="ea-kicker">Staff Evaluation</span>
                    <h2 class="ea-toolbar-title">Evaluate the Executive Assistant</h2>
                    <p class="ea-toolbar-sub">Uses the questionnaire configured by the admin.</p>
                </div>
                <span class="ea-status <?= $staff_eval_already_done ? 'done' : '' ?>">
                    <i class="fa-solid <?= $staff_eval_already_done ? 'fa-circle-check' : 'fa-clipboard-list' ?>"></i>
                    <?= $staff_eval_already_done ? 'Completed' : 'Ready' ?>
                </span>
            </div>

            <?php if ($eaSingle && $staff_eval_target):
                $heroT = $staff_eval_target;
                $heroDone = false; $heroLast = null; $heroQ = 0;
                if ($period) {
                    $hchk = $mysqli->prepare("SELECT submitted_at FROM evaluation_tracker WHERE evaluator_id=? AND target_user_id=? AND period_id=? AND eval_type='staff' AND status='submitted' ORDER BY submitted_at DESC LIMIT 1");
                    $hchk->bind_param('iii', $user_id, $heroT['id'], $period['id']);
                    $hchk->execute();
                    $hRow = $hchk->get_result()->fetch_assoc();
                    if ($hRow) { $heroDone = true; $heroLast = $hRow['submitted_at']; }
                    $hchk->close();
                }
                $hq = $mysqli->prepare("SELECT COUNT(*) AS c FROM user_questions WHERE user_id=? AND target_type=? AND eval_type='general'");
                $hq->bind_param('is', $heroT['id'], $heroT['target_type']);
                $hq->execute();
                $heroQ = (int)($hq->get_result()->fetch_assoc()['c'] ?? 0);
                $hq->close();
                $heroPeriod = trim((string)($period['period_label'] ?? ($period['semester'] ?? '')));
            ?>
            <section class="ea-hero<?= $heroDone ? ' is-done' : '' ?>">
                <div class="ea-hero-main">
                    <?php if (!empty($heroT['photo'])): ?>
                        <img class="ea-hero-avatar" src="../image/<?= htmlspecialchars($heroT['photo']) ?>" alt="">
                    <?php else: ?>
                        <div class="ea-hero-avatar ph"><i class="fa-solid fa-user-shield"></i></div>
                    <?php endif; ?>
                    <div class="ea-hero-id">
                        <span class="ea-role-pill"><?= htmlspecialchars($heroT['target_label']) ?></span>
                        <h3 class="ea-hero-name"><?= htmlspecialchars($heroT['full_name']) ?></h3>
                        <p class="ea-hero-desig"><?= htmlspecialchars($heroT['designation'] ?: $heroT['target_label']) ?></p>
                    </div>
                    <div class="ea-hero-action">
                        <?php if ($heroDone): ?>
                            <span class="ea-hero-btn done"><i class="fa-solid fa-circle-check"></i> Evaluated</span>
                        <?php elseif (!$evaluation_open): ?>
                            <span class="ea-hero-btn off"><i class="fa-solid fa-clock"></i> Scheduled</span>
                        <?php else: ?>
                            <a class="ea-hero-btn" href="staff_dashboard.php?page=staff_eval_form&tid=<?= (int)$heroT['id'] ?>"><i class="fa-solid fa-pen"></i> Start evaluation</a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="ea-hero-facts">
                    <div class="ea-fact"><i class="fa-solid fa-list-check"></i><div><span>Questionnaire</span><strong><?= $heroQ ?> question<?= $heroQ === 1 ? '' : 's' ?></strong></div></div>
                    <div class="ea-fact <?= $heroDone ? 'is-done' : 'is-pending' ?>"><i class="fa-solid <?= $heroDone ? 'fa-circle-check' : 'fa-hourglass-half' ?>"></i><div><span>Status</span><strong><?= $heroDone ? 'Evaluated' . ($heroLast ? ' · ' . htmlspecialchars(date('M j, Y', strtotime($heroLast))) : '') : 'Not started' ?></strong></div></div>
                    <div class="ea-fact"><i class="fa-solid fa-lock"></i><div><span>Privacy</span><strong>Responses are confidential</strong></div></div>
                    <?php if ($heroPeriod !== ''): ?><div class="ea-fact"><i class="fa-regular fa-calendar"></i><div><span>Period</span><strong><?= htmlspecialchars($heroPeriod) ?></strong></div></div><?php endif; ?>
                </div>
            </section>
            <?php endif; ?>

            <?php if (!$eaSingle): ?>
            <div class="ea-table-card">
                <?php if (empty($staff_eval_targets)): ?>
                    <div class="empty-state">
                        <i class="fa-solid fa-users"></i>
                        <p>No Executive Assistant is currently available for Staff Evaluation.</p>
                    </div>
                <?php else: ?>
                    <table class="ea-table">
                        <thead>
                            <tr>
                                <th style="width:31%">Target</th>
                                <th style="width:18%">Role</th>
                                <th style="width:18%">Questionnaire</th>
                                <th style="width:18%">Evaluation Status</th>
                                <th style="width:15%">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($staff_eval_targets as $target): ?>
                            <?php if (!$staff_eval_target || (int)$target['id'] !== (int)$staff_eval_target['id']) continue; ?>
                            <?php
                            $done = false; $last_eval = null; $qCount = 0;
                            if ($period) {
                                $chk = $mysqli->prepare("SELECT submitted_at FROM evaluation_tracker WHERE evaluator_id=? AND target_user_id=? AND period_id=? AND eval_type='staff' AND status='submitted' ORDER BY submitted_at DESC LIMIT 1");
                                $chk->bind_param('iii', $user_id, $target['id'], $period['id']);
                                $chk->execute();
                                $doneRow = $chk->get_result()->fetch_assoc();
                                if ($doneRow) { $done = true; $last_eval = $doneRow['submitted_at']; }
                                $chk->close();
                            }
                            $qc = $mysqli->prepare("SELECT COUNT(*) AS c FROM user_questions WHERE user_id=? AND target_type=? AND eval_type='general'");
                            $qc->bind_param('is', $target['id'], $target['target_type']);
                            $qc->execute();
                            $qCount = (int)($qc->get_result()->fetch_assoc()['c'] ?? 0);
                            $qc->close();
                            ?>
                            <tr>
                                <td>
                                    <div class="ea-profile">
                                        <?php if (!empty($target['photo'])): ?>
                                            <img class="ea-avatar" src="../image/<?= htmlspecialchars($target['photo']) ?>" alt="">
                                        <?php else: ?>
                                            <div class="ea-avatar"><i class="fa-solid <?= $target['target_type']==='Dean' ? 'fa-graduation-cap' : ($target['target_type']==='Principal' ? 'fa-user-tie' : 'fa-user-shield') ?>"></i></div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="ea-name"><?= htmlspecialchars($target['full_name']) ?></div>
                                            <div class="ea-sub"><?= htmlspecialchars($target['designation'] ?: $target['target_label']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="ea-role-pill"><?= htmlspecialchars($target['target_label']) ?></span></td>
                                <td><?= $qCount ?> question<?= $qCount === 1 ? '' : 's' ?></td>
                                <td><span class="ea-status <?= $done ? 'done' : '' ?>"><i class="fa-solid <?= $done ? 'fa-circle-check' : 'fa-hourglass-half' ?>"></i><?= $done ? 'Evaluated' : 'Not Started' ?></span></td>
                                <td>
                                    <?php if ($done): ?>
                                        <span class="ea-evaluate-btn done"><i class="fa-solid fa-check"></i> Evaluated</span>
                                    <?php elseif (!$evaluation_open): ?>
                                        <span class="ea-evaluate-btn" style="opacity:.55;cursor:not-allowed;"><i class="fa-solid fa-clock"></i> Scheduled</span>
                                    <?php else: ?>
                                        <a class="ea-evaluate-btn" href="staff_dashboard.php?page=staff_eval_form&tid=<?= (int)$target['id'] ?>"><i class="fa-solid fa-pen"></i> Evaluate</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <?php elseif ($page === 'staff_eval_form'): ?>

        <?php $staff_eval_back_url = 'staff_dashboard.php?page=' . rawurlencode($staff_eval_home_page) . ($staff_has_teaching_assignment ? '' : '&tid=' . (int)($staff_eval_target['id'] ?? 0)); ?>
        <div class="staff-eval-modal-overlay" id="staffEvalModalOverlay">
            <section class="staff-eval-modal" role="dialog" aria-modal="true" aria-labelledby="staffEvalModalTitle">
                <header class="staff-eval-modal-head">
                    <?php if (!empty($staff_eval_target['photo'])): ?>
                        <img class="staff-eval-modal-avatar" src="<?= UPLOAD_URL . htmlspecialchars($staff_eval_target['photo']) ?>" alt="" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                        <div class="staff-eval-modal-avatar-ph" style="display:none;"><i class="fa-solid fa-user"></i></div>
                    <?php else: ?>
                        <div class="staff-eval-modal-avatar-ph"><i class="fa-solid fa-user"></i></div>
                    <?php endif; ?>
                    <div class="staff-eval-modal-identity">
                        <h2 id="staffEvalModalTitle"><?= $staff_eval_target ? 'Evaluate ' . htmlspecialchars($staff_eval_target['full_name']) : 'Staff Evaluation' ?></h2>
                        <p><?= $staff_eval_target ? htmlspecialchars(trim((string)($staff_eval_target['designation'] ?? '')) ?: $staff_eval_target['target_label']) . ' · ' . htmlspecialchars($staff_eval_target['target_label']) . ' · responses are confidential.' : htmlspecialchars($staff_eval_feature_label) ?></p>
                    </div>
                    <a class="staff-eval-modal-close" href="<?= htmlspecialchars($staff_eval_back_url) ?>" aria-label="Close evaluation form" title="Close"><i class="fa-solid fa-xmark"></i></a>
                </header>

                <?php if (!$evaluation_open): ?>
                    <div class="staff-eval-modal-body staff-eval-state-body"><div class="empty-state"><i class="fa-solid fa-clock"></i><p>Evaluation is currently closed. It will open at the scheduled time set by the administrator.</p></div></div>
                    <footer class="staff-eval-modal-foot"><a href="<?= htmlspecialchars($staff_eval_back_url) ?>" class="staff-eval-cancel"><i class="fa-solid fa-arrow-left"></i> Back</a></footer>
                <?php elseif (!$staff_eval_target): ?>
                    <div class="staff-eval-modal-body staff-eval-state-body"><div class="empty-state"><i class="fa-solid fa-users"></i><p>No <?= htmlspecialchars($staff_eval_feature_label) ?> target is available.</p></div></div>
                    <footer class="staff-eval-modal-foot"><a href="<?= htmlspecialchars($staff_eval_back_url) ?>" class="staff-eval-cancel"><i class="fa-solid fa-arrow-left"></i> Back</a></footer>
                <?php elseif (empty($staff_eval_questions)): ?>
                    <div class="staff-eval-modal-body staff-eval-state-body"><div class="empty-state"><i class="fa-solid fa-clipboard-question"></i><p>The <?= htmlspecialchars($staff_eval_target['target_label']) ?> questionnaire has not been configured yet.</p></div></div>
                    <footer class="staff-eval-modal-foot"><a href="<?= htmlspecialchars($staff_eval_back_url) ?>" class="staff-eval-cancel"><i class="fa-solid fa-arrow-left"></i> Back</a></footer>
                <?php elseif ($staff_eval_already_done): ?>
                    <div class="staff-eval-modal-body staff-eval-state-body"><div class="empty-state"><i class="fa-solid fa-circle-check"></i><p>You have already evaluated <?= htmlspecialchars($staff_eval_target['full_name']) ?> for this period.</p></div></div>
                    <footer class="staff-eval-modal-foot"><a href="<?= htmlspecialchars($staff_eval_back_url) ?>" class="staff-eval-cancel"><i class="fa-solid fa-arrow-left"></i> Back</a></footer>
                <?php else: ?>
                    <form method="POST" action="staff_dashboard.php?page=staff_eval_form&amp;tid=<?= (int)$staff_eval_target['id'] ?>" id="staffEvalForm" class="staff-eval-modal-form" novalidate>
                        <div class="staff-eval-modal-body" id="staffEvalModalBody">
                            <div class="eval-validation-alert" id="staffEvalValidation" role="alert" aria-live="assertive"></div>
                            <div class="staff-eval-rating-guide" aria-label="Rating guide">
                                <?php foreach ([5=>'Always',4=>'Often',3=>'Sometimes',2=>'Rarely',1=>'Never'] as $score => $label): ?>
                                    <div class="staff-eval-rating-guide-item"><span><?= $score ?></span><label><?= $label ?></label></div>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                            <input type="hidden" name="target_id" value="<?= (int)$staff_eval_target['id'] ?>">
                            <input type="hidden" name="submit_staff_evaluation" value="1">

                            <?php $staffQNo=1; foreach ($staff_eval_categories as $category => $questions): ?>
                                <div class="staff-eval-category-title"><i class="fa-solid fa-layer-group"></i> <?= htmlspecialchars($category) ?></div>
                                <div class="compact-eval-table-wrap staff-eval-table-wrap">
                                    <table class="compact-eval-table staff-eval-table">
                                        <colgroup><col><col class="c-rate"><col class="c-rate"><col class="c-rate"><col class="c-rate"><col class="c-rate"></colgroup>
                                        <tbody>
                                        <?php foreach ($questions as $q): ?>
                                        <tr class="eval-question-row staff-eval-question-row">
                                            <td><div class="compact-eval-qtext"><span class="compact-eval-qno"><?= $staffQNo++ ?>.</span><?= htmlspecialchars($q['question_text']) ?></div></td>
                                            <?php for($r=5;$r>=1;$r--): $optId='staff_r_' . (int)$q['id'] . '_' . $r; ?><td><div class="compact-eval-rating"><input type="radio" name="ratings[<?= (int)$q['id'] ?>]" id="<?= $optId ?>" value="<?= $r ?>"><label for="<?= $optId ?>"><?= $r ?></label></div></td><?php endfor; ?>
                                        </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endforeach; ?>

                            <div class="staff-eval-comments-card">
                                <label class="staff-eval-comments-label" for="staffEvalComments"><i class="fa-solid fa-comment-dots"></i> Comments &amp; Suggestions <span class="comment-req" title="Required">*</span></label>
                                <textarea class="comments-box" id="staffEvalComments" name="comments" required aria-required="true" placeholder="Share your thoughts, suggestions, or concerns about this person's performance."></textarea>
                                <div class="comment-error" role="alert" hidden>Required \u2014 you can't submit this evaluation without a comment.</div>
                            </div>
                        </div>
                        <footer class="staff-eval-modal-foot">
                            <a href="<?= htmlspecialchars($staff_eval_back_url) ?>" class="staff-eval-cancel">Cancel</a>
                            <button type="submit" class="staff-eval-submit"><i class="fa-solid fa-paper-plane"></i> Submit Evaluation</button>
                        </footer>
                    </form>
                <?php endif; ?>
            </section>
        </div>

        <script>
        function staffRate(id,val){
            const radio=document.querySelector('input[name="ratings['+id+']"][value="'+val+'"]');
            if (radio) radio.checked=true;
        }
        function validateRatingForm(form, alertBoxId) {
    if (!form) return false;
    const radios = [...form.querySelectorAll('input[type="radio"][name^="ratings["]')];
    const groups = [...new Set(radios.map(r => r.name))];
    const missing = groups.filter(name => !form.querySelector('input[name="' + CSS.escape(name) + '"]:checked'));
    const alertBox = document.getElementById(alertBoxId);
    form.querySelectorAll('.eval-question-row.eval-unanswered').forEach(row => row.classList.remove('eval-unanswered'));

    if (!groups.length) {
        if (alertBox) { alertBox.innerHTML = '<strong>No questions found.</strong> Please close and try again.'; alertBox.classList.add('show'); }
        return false;
    }
    if (missing.length) {
        const labels=[];
        missing.forEach(name => {
            const input=form.querySelector('input[name="'+CSS.escape(name)+'"]');
            const row=input ? input.closest('tr') : null;
            if(row){
                row.classList.add('eval-unanswered');
                const q=row.querySelector('.compact-eval-qno');
                labels.push(q ? q.textContent.replace(/[^0-9]/g,'') : '?');
            }
        });
        const questionWord=missing.length===1?'question is':'questions are';
        if(alertBox){
            alertBox.innerHTML='<strong>Please complete all required questions.</strong> '+missing.length+' '+questionWord+' unanswered.'+(labels.length?' Missing: <strong>Q'+labels.join(', Q')+'.</strong>':'');
            alertBox.classList.add('show');
            alertBox.scrollIntoView({behavior:'smooth',block:'center'});
        }
        const firstRow=form.querySelector('.eval-question-row.eval-unanswered');
        if(firstRow) setTimeout(()=>firstRow.scrollIntoView({behavior:'smooth',block:'center'}),80);
        return false;
    }
    if(alertBox) alertBox.classList.remove('show');
    return true;
}
function updateRatingProgress(form, counterId, alertId) {
    const counter=document.getElementById(counterId);
    if(!form) return;
    const groups=[...new Set([...form.querySelectorAll('input[type="radio"][name^="ratings["]')].map(r=>r.name))];
    const answered=groups.filter(name=>form.querySelector('input[name="'+CSS.escape(name)+'"]:checked')).length;
    if(counter){
        counter.textContent=answered+' of '+groups.length+' questions answered';
        counter.classList.toggle('is-complete',groups.length>0 && answered===groups.length);
    }
    if(groups.length && answered===groups.length) document.getElementById(alertId)?.classList.remove('show');
}
function checkStaffAll(){ return validateRatingForm(document.getElementById('staffEvalForm'),'staffEvalValidation'); }
function checkAll(){ return validateRatingForm(document.getElementById('evalForm'),'peerEvalValidation'); }

// Clicking a rating label normally transfers focus to its hidden radio input.
// Keep that focus inside the rating cell and suppress browser auto-scrolling,
// which otherwise can jump the Staff Evaluation view back toward the top.
function keepRatingClickInPlace(form){
    if(!form) return;
    form.addEventListener('click',function(e){
        const label=e.target.closest('label[for]');
        if(!label || !form.contains(label) || !label.closest('.compact-eval-rating')) return;
        const radio=document.getElementById(label.htmlFor);
        if(!radio || radio.type!=='radio' || !form.contains(radio)) return;
        e.preventDefault();
        try { radio.focus({preventScroll:true}); } catch (_) { radio.focus(); }
        if(!radio.checked){
            radio.checked=true;
            radio.dispatchEvent(new Event('change',{bubbles:true}));
        }
    },true);
}

const staffEvalForm=document.getElementById('staffEvalForm');
const peerEvalForm=document.getElementById('evalForm');
keepRatingClickInPlace(staffEvalForm);
keepRatingClickInPlace(peerEvalForm);

// The evaluation is a fixed modal. Lock the page behind it so focus or
// wheel gestures cannot scroll the dashboard behind the form.
if(document.getElementById('staffEvalModalOverlay')){
    document.documentElement.style.overflow='hidden';
    document.body.style.overflow='hidden';
}

if(staffEvalForm){
    staffEvalForm.addEventListener('change',function(e){
        if(!e.target.matches('input[type="radio"][name^="ratings["]')) return;
        const row=e.target.closest('.eval-question-row'); if(row) row.classList.remove('eval-unanswered');
        updateRatingProgress(staffEvalForm,'staffEvalProgress','staffEvalValidation');
    });
    staffEvalForm.addEventListener('submit',function(e){ if(!checkStaffAll()) e.preventDefault(); });
    updateRatingProgress(staffEvalForm,'staffEvalProgress','staffEvalValidation');
}
if(peerEvalForm){
    peerEvalForm.addEventListener('change',function(e){
        if(!e.target.matches('input[type="radio"][name^="ratings["]')) return;
        const row=e.target.closest('.eval-question-row'); if(row) row.classList.remove('eval-unanswered');
        updateRatingProgress(peerEvalForm,'peerEvalProgress','peerEvalValidation');
    });
    peerEvalForm.addEventListener('submit',function(e){ if(!checkAll()) e.preventDefault(); });
    updateRatingProgress(peerEvalForm,'peerEvalProgress','peerEvalValidation');
}
        </script>

        <!-- ══════════ PEER EVALUATION — LOCKED FOR NON-TEACHING STAFF ══════════ -->
        <?php elseif (in_array($page, ['peer','peer_eval'], true) && !$staff_has_teaching_assignment): ?>

        <div class="section-card peer-box">
            <div class="empty-state"><i class="fa-solid fa-lock"></i><p>Peer-to-Peer Evaluation is only available to Staff with a teaching assignment. Use Staff Evaluation instead.</p></div>
        </div>

        <!-- ══════════ PEER EVALUATION — STEP 1: CHOOSE DESIGNATION ══════════ -->
        <?php elseif ($page === 'peer' && $peer_group === null): ?>

        <div class="section-card peer-box">
            <?php if (!$evaluation_open): ?>
            <div class="no-period-warn"><i class="fa-solid fa-clock"></i> Evaluation is currently closed until the scheduled opening time.</div>
            <?php endif; ?>

            <?php
                $faculty_peer_targets = array_values(array_filter($peers_all, fn($p) => resolve_peer_group($mysqli, $p) === 'teacher'));
                $teacher_count   = $staff_can_evaluate_faculty_peer ? count($faculty_peer_targets) : 0;
                $staff_count     = count(array_filter($peers_all, fn($p) => resolve_peer_group($mysqli, $p) === 'staff'));
                $dean_count      = count(array_filter($staff_eval_targets, fn($t) => $t['target_type'] === 'Dean'));
                $principal_count = count(array_filter($staff_eval_targets, fn($t) => $t['target_type'] === 'Principal'));
                $anything_to_evaluate = !empty($peers_all) || $staff_teaches_college || $staff_teaches_basic_ed;
                $px_done_peers = array_map('intval', $done_peers);
                $px_done_sup   = array_map('intval', $done_supervisors);
                $teacher_done   = $staff_can_evaluate_faculty_peer ? count(array_intersect(array_map('intval', array_column($faculty_peer_targets, 'id')), $px_done_peers)) : 0;
                $staff_done     = count(array_intersect(array_map('intval', array_column(array_filter($peers_all, fn($p) => resolve_peer_group($mysqli, $p) === 'staff'), 'id')), $px_done_peers));
                $dean_done      = count(array_intersect(array_map('intval', array_column(array_filter($staff_eval_targets, fn($t) => $t['target_type'] === 'Dean'), 'id')), $px_done_sup));
                $principal_done = count(array_intersect(array_map('intval', array_column(array_filter($staff_eval_targets, fn($t) => $t['target_type'] === 'Principal'), 'id')), $px_done_sup));
            ?>
            <?php if (!$anything_to_evaluate): ?>
            <div class="empty-state"><i class="fa-solid fa-users"></i><p>No eligible Faculty/Staff peers are currently available.</p></div>
            <?php else: ?>
            <h2 class="cat-heading"><i class="fa-solid fa-clipboard-check" aria-hidden="true"></i> Choose category</h2>
            <p class="cat-lead">Choose a group, then evaluate each member. Your progress for the active period is shown on every card.</p>
            <div class="eval-group-grid cat-select">
                <?php if ($staff_can_evaluate_faculty_peer): ?>
                <a href="staff_dashboard.php?page=peer&group=teacher#roster" class="eval-group-card">
                    <div class="eval-group-icon blue"><i class="fa-solid fa-chalkboard-user"></i></div>
                    <div class="eval-group-title">Faculty</div>
                    <div class="eval-group-count"><?= $teacher_count ?> member<?= $teacher_count == 1 ? '' : 's' ?></div>
                    <?= fx_group_progress((int)$teacher_count, (int)$teacher_done) ?>
                </a>
                <?php endif; ?>
                <a href="staff_dashboard.php?page=peer&group=staff#roster" class="eval-group-card">
                    <div class="eval-group-icon green"><i class="fa-solid fa-briefcase"></i></div>
                    <div class="eval-group-title">Staff</div>
                    <div class="eval-group-count"><?= $staff_count ?> member<?= $staff_count == 1 ? '' : 's' ?></div>
                    <?= fx_group_progress((int)$staff_count, (int)$staff_done) ?>
                </a>
                <?php if ($staff_teaches_college): ?>
                <a href="staff_dashboard.php?page=peer&group=dean#roster" class="eval-group-card">
                    <div class="eval-group-icon gold"><i class="fa-solid fa-graduation-cap"></i></div>
                    <div class="eval-group-title">Dean</div>
                    <div class="eval-group-count"><?= $dean_count ?> member<?= $dean_count == 1 ? '' : 's' ?></div>
                    <?= fx_group_progress((int)$dean_count, (int)$dean_done) ?>
                </a>
                <?php endif; ?>
                <?php if ($staff_teaches_basic_ed): ?>
                <a href="staff_dashboard.php?page=peer&group=principal#roster" class="eval-group-card">
                    <div class="eval-group-icon purple"><i class="fa-solid fa-user-tie"></i></div>
                    <div class="eval-group-title">Principal</div>
                    <div class="eval-group-count"><?= $principal_count ?> member<?= $principal_count == 1 ? '' : 's' ?></div>
                    <?= fx_group_progress((int)$principal_count, (int)$principal_done) ?>
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- ══════════ PEER EVALUATION — STEP 2: CATEGORY SWITCHER + ROSTER (same layout as Admin "Evaluate Others") ══════════ -->
        <?php elseif ($page === 'peer' && $peer_group !== null): ?>

        <?php
            $is_supervisor_group = in_array($peer_group, ['dean', 'principal'], true);
            $sel_faculty_targets = array_values(array_filter($peers_all, fn($p) => resolve_peer_group($mysqli, $p) === 'teacher'));
            $sel_counts = [
                'teacher'   => $staff_can_evaluate_faculty_peer ? count($sel_faculty_targets) : 0,
                'staff'     => count(array_filter($peers_all, fn($p) => resolve_peer_group($mysqli, $p) === 'staff')),
                'dean'      => count(array_filter($staff_eval_targets, fn($t) => $t['target_type'] === 'Dean')),
                'principal' => count(array_filter($staff_eval_targets, fn($t) => $t['target_type'] === 'Principal')),
            ];
            $sel_done = [
                'teacher'   => $staff_can_evaluate_faculty_peer ? count(array_intersect(array_map('intval', array_column($sel_faculty_targets, 'id')), array_map('intval', $done_peers))) : 0,
                'staff'     => count(array_intersect(array_map('intval', array_column(array_filter($peers_all, fn($p) => resolve_peer_group($mysqli, $p) === 'staff'), 'id')), array_map('intval', $done_peers))),
                'dean'      => count(array_intersect(array_map('intval', array_column(array_filter($staff_eval_targets, fn($t) => $t['target_type'] === 'Dean'), 'id')), array_map('intval', $done_supervisors))),
                'principal' => count(array_intersect(array_map('intval', array_column(array_filter($staff_eval_targets, fn($t) => $t['target_type'] === 'Principal'), 'id')), array_map('intval', $done_supervisors))),
            ];
            $sel_cards = [
                ['teacher',   'Faculty',   'blue',   'fa-chalkboard-user', $staff_can_evaluate_faculty_peer],
                ['staff',     'Staff',     'green',  'fa-briefcase',       true],
                ['dean',      'Dean',      'gold',   'fa-graduation-cap',  $staff_teaches_college],
                ['principal', 'Principal', 'purple', 'fa-user-tie',        $staff_teaches_basic_ed],
            ];
            $done_ids = $is_supervisor_group ? $done_supervisors : $done_peers;
            $done_in_group = count(array_intersect(array_map('intval', array_column($peers, 'id')), array_map('intval', $done_ids)));
            $displayPeerPeriod = ($peer_group === 'teacher' && !empty($college_peer_period)) ? $college_peer_period : $period;
            $roster_icons = ['teacher'=>'fa-chalkboard-user','staff'=>'fa-briefcase','principal'=>'fa-user-tie','dean'=>'fa-graduation-cap'];
            $roster_icon  = $roster_icons[$peer_group] ?? 'fa-users';
        ?>
        <div class="section-card peer-shell peer-box">
            <h2 class="cat-heading"><i class="fa-solid fa-clipboard-check" aria-hidden="true"></i> Choose category</h2>
            <p class="cat-lead">Choose a group, then evaluate each member. Your progress for the active period is shown on every card.</p>
            <div class="eval-group-grid cat-select">
                <?php foreach ($sel_cards as [$g, $label, $color, $icon, $show]): if (!$show) continue; $is_sel = ($peer_group === $g); ?>
                <a href="staff_dashboard.php?page=peer<?= $is_sel ? '' : '&group=' . $g . '#roster' ?>" class="eval-group-card <?= $is_sel ? 'is-selected' : '' ?>" <?= $is_sel ? 'aria-current="true"' : '' ?>>
                    <div class="eval-group-icon <?= $color ?>"><i class="fa-solid <?= $icon ?>"></i></div>
                    <div class="eval-group-title"><?= $label ?></div>
                    <div class="eval-group-count"><?= $sel_counts[$g] ?> member<?= $sel_counts[$g] == 1 ? '' : 's' ?></div>
                    <?= fx_group_progress((int)$sel_counts[$g], (int)$sel_done[$g]) ?>
                </a>
                <?php endforeach; ?>
            </div>

        <?php if (!$evaluation_open): ?>
        <div class="no-period-warn"><i class="fa-solid fa-clock"></i> Evaluation is currently closed until the scheduled opening time.</div>
        <?php endif; ?>

        <section class="roster-panel" id="roster">
            <div class="roster-head">
                <div class="roster-heading">
                    <div class="roster-icon"><i class="fa-solid <?= $roster_icon ?>"></i></div>
                    <h2><?= htmlspecialchars($peer_group_labels[$peer_group] ?? ucfirst($peer_group)) ?></h2>
                </div>
                <div class="roster-stats">
                    <span><strong><?= count($peers) ?></strong> <?= count($peers) === 1 ? 'member' : 'members' ?></span>
                    <span><strong><?= $done_in_group ?></strong> completed</span>
                    <span><strong><?= max(0, count($peers) - $done_in_group) ?></strong> pending</span>
                    <span class="period"><i class="fa-regular fa-calendar"></i> <?= htmlspecialchars($displayPeerPeriod['period_label'] ?? $displayPeerPeriod['semester'] ?? 'Current Period') ?></span>
                </div>
            </div>

            <?php if (empty($peers)): ?>
            <div class="roster-empty"><i class="fa-solid fa-user-slash"></i>
                <?= $is_supervisor_group ? 'No ' . htmlspecialchars(strtolower($peer_group_labels[$peer_group])) . ' is currently available for your teaching level(s).' : 'No registered ' . htmlspecialchars(strtolower($peer_group_labels[$peer_group])) . ' members found.' ?>
            </div>
            <?php else: ?>
            <div class="person-grid">
                <?php foreach ($peers as $p):
                    $done = in_array((int)$p['id'], array_map('intval', $done_ids), true);
                    $peer_label = peer_display_label($mysqli, $p, $peer_group, $peer_group_labels);
                    $evalHref = $is_supervisor_group
                        ? 'staff_dashboard.php?page=staff_eval_form&tid=' . (int)$p['id']
                        : 'staff_dashboard.php?page=peer_eval&tid=' . (int)$p['id'] . '&group=' . urlencode($peer_group);
                ?>
                <article class="person-card <?= $done ? 'is-done' : 'is-pending' ?>">
                    <div class="person-photo">
                        <?php if (!empty($p['photo'])): ?><img src="../image/<?= htmlspecialchars($p['photo']) ?>" alt="" loading="lazy" onerror="this.parentNode.innerHTML='<i class=&quot;fa-solid fa-user&quot;></i>'"/><?php else: ?><i class="fa-solid fa-user"></i><?php endif; ?>
                    </div>
                    <div class="person-info">
                        <div class="person-name" title="<?= htmlspecialchars($p['full_name']) ?>"><?= htmlspecialchars($p['full_name']) ?></div>
                        <div class="person-meta" title="<?= htmlspecialchars($peer_label) ?>"><?= htmlspecialchars($peer_label) ?></div>
                        <span class="status-pill <?= $done ? 'done' : 'pending' ?>"><i class="fa-solid <?= $done ? 'fa-check' : 'fa-hourglass-half' ?>"></i> <?= $done ? 'Completed' : 'Pending' ?></span>
                    </div>
                    <div class="person-action">
                        <?php if ($done): ?>
                        <span class="btn-action view is-disabled"><i class="fa-solid fa-check"></i> Evaluated</span>
                        <?php elseif (!$evaluation_open): ?>
                        <span class="btn-action evaluate is-disabled" aria-disabled="true"><i class="fa-solid fa-clock"></i> Scheduled</span>
                        <?php else: ?>
                        <a class="btn-action evaluate" href="<?= $evalHref ?>"><i class="fa-solid fa-pen"></i> Evaluate</a>
                        <?php endif; ?>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>
        </div>

        <!-- ══════════ PEER EVAL FORM (popup, same as Admin / Faculty) ══════════ -->
        <?php elseif ($page === 'peer_eval' && $peer_target): ?>
        <?php $peerBackUrl = 'staff_dashboard.php?page=peer&group=' . urlencode($peer_eval_group) . '#roster'; ?>

        <div class="peer-eval-overlay">
          <section class="peer-eval-dialog" role="dialog" aria-modal="true" aria-labelledby="peerEvalTitle">
            <header class="peer-eval-dialog-header">
              <div class="peer-eval-target">
                <?php if (!empty($peer_target['photo'])): ?>
                  <img class="peer-eval-avatar" src="<?= UPLOAD_URL . htmlspecialchars($peer_target['photo']) ?>" alt=""/>
                <?php else: ?>
                  <div class="peer-eval-avatar peer-eval-avatar-placeholder"><i class="fa-solid fa-user"></i></div>
                <?php endif; ?>
                <div class="peer-eval-target-copy">
                  <h2 id="peerEvalTitle"><?= htmlspecialchars($peer_target['full_name']) ?></h2>
                  <p><?= htmlspecialchars(peer_display_label($mysqli, $peer_target, $peer_eval_group ?? 'staff', $peer_group_labels)) ?></p>
                </div>
              </div>
              <a class="peer-eval-close" href="<?= htmlspecialchars($peerBackUrl) ?>" aria-label="Close evaluation"><i class="fa-solid fa-xmark"></i></a>
            </header>

            <?php if (!$evaluation_open): ?>
              <div class="peer-eval-empty"><i class="fa-solid fa-clock"></i> Evaluation is currently closed. It will open at the scheduled time set by the administrator.</div>
              <footer class="peer-eval-footer"><a class="btn-cancel-peer" href="<?= htmlspecialchars($peerBackUrl) ?>">Back to Peer-to-Peer Evaluation</a></footer>
            <?php elseif (empty($peer_questions)): ?>
              <div class="peer-eval-empty"><i class="fa-solid fa-triangle-exclamation"></i> No peer-to-peer evaluation questions set up yet. Please contact the admin.</div>
              <footer class="peer-eval-footer"><a class="btn-cancel-peer" href="<?= htmlspecialchars($peerBackUrl) ?>">Back to Peer-to-Peer Evaluation</a></footer>
            <?php else: ?>
            <form class="peer-eval-form" method="POST" action="staff_dashboard.php?page=peer_eval&tid=<?= $peer_target['id'] ?>&group=<?= urlencode($peer_eval_group) ?>" id="evalForm" novalidate>
              <div class="peer-eval-scroll">
                <div class="eval-validation-alert" id="peerEvalValidation" role="alert" aria-live="assertive"></div>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>"/>
                <input type="hidden" name="submit_peer" value="1"/>
                <input type="hidden" name="target_id"   value="<?= $peer_target['id'] ?>"/>
                <input type="hidden" name="group"       value="<?= htmlspecialchars($peer_eval_group) ?>"/>

                <div class="scale-legend">
                    <?php foreach ([5=>'Always',4=>'Often',3=>'Sometimes',2=>'Rarely',1=>'Never'] as $n=>$lbl): ?>
                    <div class="scale-legend-item"><div class="scale-legend-num"><?= $n ?></div><div class="scale-legend-lbl"><?= $lbl ?></div></div>
                    <?php endforeach; ?>
                </div>

                <?php $qno=1; foreach ($peer_categories as $cat_name => $cat_qs): ?>
                <div class="cat-group-title" style="margin-bottom:9px;"><i class="fa-solid fa-layer-group"></i> <?= htmlspecialchars($cat_name) ?></div>
                <div class="compact-eval-table-wrap">
                    <table class="compact-eval-table">
                        <colgroup><col><col class="c-rate"><col class="c-rate"><col class="c-rate"><col class="c-rate"><col class="c-rate"></colgroup>
                        <tbody>
                        <?php foreach($cat_qs as $q): ?>
                        <tr class="eval-question-row peer-eval-question-row">
                            <td><div class="compact-eval-qtext"><span class="compact-eval-qno"><?= $qno++ ?>.</span><?= htmlspecialchars($q['question_text']) ?></div></td>
                            <?php for($r=5;$r>=1;$r--): $optId='peer_r_' . (int)$q['id'] . '_' . $r; ?><td><div class="compact-eval-rating"><input type="radio" name="ratings[<?= (int)$q['id'] ?>]" id="<?= $optId ?>" value="<?= $r ?>"><label for="<?= $optId ?>"><?= $r ?></label></div></td><?php endfor; ?>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endforeach; ?>

                <div class="comments-card">
                    <div style="font-size:13px;font-weight:700;color:var(--teal-hover);margin-bottom:10px;"><i class="fa-solid fa-comment-dots"></i> Comments &amp; Suggestions <span class="comment-req" title="Required">*</span></div>
                    <textarea class="comments-box" name="comments" required aria-required="true" placeholder="Share your thoughts, suggestions, or concerns about this person's performance."></textarea>
                    <div class="comment-error" role="alert" hidden>Required \u2014 you can't submit this evaluation without a comment.</div>
                </div>
              </div>
              <footer class="peer-eval-footer">
                <a class="btn-cancel-peer" href="<?= htmlspecialchars($peerBackUrl) ?>">Cancel</a>
                <button type="submit" class="btn-submit"><i class="fa-solid fa-paper-plane"></i> Submit Evaluation</button>
              </footer>
            </form>
            <?php endif; ?>
          </section>
        </div>

        <!-- ══════════ PEER EVAL — INVALID / MISSING TARGET ══════════ -->
        <?php elseif ($page === 'peer_eval' && !$peer_target): ?>

        <div class="section-card peer-box">
        <a href="staff_dashboard.php?page=peer" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to Peer Selection</a>

        <div style="background:rgba(240,84,84,.08);border:1px solid rgba(240,84,84,.25);border-radius:10px;padding:18px;color:#fca5a5;font-size:13px;display:flex;gap:10px;">
            <i class="fa-solid fa-circle-exclamation"></i>
            <?= htmlspecialchars($peer_group_error ?: "Selected user does not exist, is inactive, or is not a valid Staff account. Please choose someone from the list.") ?>
        </div>
        </div>

        <?php endif; ?>

        </main>

        <script>
        // Required comment for the Staff Evaluation (#staffEvalForm) and Peer-to-Peer (#evalForm) forms.
        // Runs first (capture). If ratings are incomplete it steps aside so the existing
        // rating validation reports those first; otherwise it blocks an empty comment.
        (function(){
            function card(ta){ return ta.closest('.staff-eval-comments-card, .comments-card'); }
            document.addEventListener('submit', function(e){
                var form = e.target;
                if (!form || (form.id !== 'staffEvalForm' && form.id !== 'evalForm')) return;
                var ta = form.querySelector('textarea[name="comments"]');
                if (!ta) return;
                var names = [], radios = form.querySelectorAll('input[type="radio"][name^="ratings["]');
                radios.forEach(function(r){ if (names.indexOf(r.name) === -1) names.push(r.name); });
                var unanswered = names.some(function(n){ return !form.querySelector('input[name="' + CSS.escape(n) + '"]:checked'); });
                if (unanswered) return;
                if (ta.value.trim() !== '') return;
                e.preventDefault(); e.stopImmediatePropagation();
                var c = card(ta), err = c && c.querySelector('.comment-error');
                var top = form.querySelector('.eval-validation-alert'); if (top) top.classList.remove('show');
                if (c) { c.classList.add('invalid'); c.scrollIntoView({behavior:'smooth', block:'center'}); }
                if (err) err.hidden = false;
                setTimeout(function(){ try { ta.focus({preventScroll:true}); } catch (_) { ta.focus(); } }, 120);
            }, true);
            document.addEventListener('input', function(e){
                var ta = e.target;
                if (!ta.matches || !ta.matches('textarea[name="comments"]') || ta.value.trim() === '') return;
                var c = card(ta); if (!c) return;
                c.classList.remove('invalid');
                var err = c.querySelector('.comment-error'); if (err) err.hidden = true;
            });
        })();
        </script>

        <script>
        // Quick-pick role chip → fill input
        function pickRole(name) {
            document.getElementById('roleInput').value = name;
            document.querySelectorAll('.role-chip').forEach(c => c.classList.remove('is-current'));
            event.target.classList.add('is-current');
        }

        function eaRate(qid, val) {
            document.querySelectorAll(`#ea_grp_${qid} .star-btn`).forEach(b => b.classList.toggle('sel', parseInt(b.dataset.val) === val));
            const input=document.getElementById(`ea_r_${qid}`);
            if (input) input.value=val;
        }
        function checkEaAll() {
            const missing=[...document.querySelectorAll('input[id^=ea_r_][required]')].some(i => !i.value);
            if (missing) { alert('Please rate every question before submitting.'); return false; }
            return true;
        }

        // Peer evaluation rating controls use real radio buttons so the
        // 5-4-3-2-1 choices remain directly clickable even when JavaScript
        // is delayed or unavailable.
        function rate(qid, val) {
            const radio=document.querySelector('input[name="ratings['+qid+']"][value="'+val+'"]');
            if (radio) radio.checked=true;
        }
        function checkAll() {
            const radios=document.querySelectorAll('#evalForm input[type="radio"][name^="ratings["]');
            if(!radios.length){ alert('No questions found. Please close and try again.'); return false; }
            const names=[...new Set([...radios].map(r=>r.name))];
            for(const n of names){
                if(!document.querySelector('#evalForm input[name="'+CSS.escape(n)+'"]:checked')){
                    alert('Please rate all questions.');
                    return false;
                }
            }
            return true;
        }

        // ── Evaluation details modal ──
        function openEvalDetails(trackerId) {
            const modal = document.getElementById('evalDetailsModal');
            const body  = document.getElementById('evalDetailsBody');
            body.innerHTML = '<div class="eval-modal-loading"><i class="fa-solid fa-spinner fa-spin"></i>Loading evaluation…</div>';
            modal.classList.add('open');
            document.body.style.overflow = 'hidden';

            fetch('get_evaluation_details.php?tracker_id=' + encodeURIComponent(trackerId))
                .then(r => r.json())
                .then(data => {
                    body.innerHTML = data.ok
                        ? renderEvalDetails(data)
                        : '<div class="eval-modal-loading"><i class="fa-solid fa-triangle-exclamation"></i>' + (data.error || 'Unable to load this evaluation.') + '</div>';
                })
                .catch(() => {
                    body.innerHTML = '<div class="eval-modal-loading"><i class="fa-solid fa-triangle-exclamation"></i>Something went wrong loading this evaluation.</div>';
                });
        }
        function closeEvalDetails() {
            document.getElementById('evalDetailsModal').classList.remove('open');
            document.body.style.overflow = '';
        }
        document.getElementById('evalDetailsModal').addEventListener('click', function(e) {
            if (e.target === this) closeEvalDetails();
        });

        function starsHtml(score) {
            let h = '';
            for (let i = 1; i <= 5; i++) h += `<i class="fa-solid fa-star eval-q-star ${i <= score ? 'filled' : ''}"></i>`;
            return h;
        }

function renderEvalDetails(data) {
    let html = `<div class="eval-info-grid">
        <div class="eval-info-item"><div class="eval-info-label">Evaluator</div><div class="eval-info-value"><i class="fa-solid fa-eye-slash" style="color:var(--muted);margin-right:5px"></i>Anonymous Evaluator</div></div>
        <div class="eval-info-item"><div class="eval-info-label">Period</div><div class="eval-info-value">${escapeHtml(data.period_label || '—')}</div></div>
        <div class="eval-info-item"><div class="eval-info-label">Submitted</div><div class="eval-info-value">${escapeHtml(data.submitted_at)}</div></div>
        <div class="eval-info-item"><div class="eval-info-label">Overall Score</div><div class="eval-info-value" style="color:var(--teal-hover)">${parseFloat(Number(data.overall_score).toFixed(2))} / 5</div></div>
    </div>`;

            if (data.categories && data.categories.length) {
                html += `<div class="section-card-title" style="font-size:14px;margin-bottom:12px;"><i class="fa-solid fa-layer-group" style="color:var(--teal)"></i> Performance by Category</div>`;
                data.categories.forEach(c => {
                    const pct = Math.round((c.avg / 5) * 100);
                    const col = c.avg >= 4 ? '#4ade80' : (c.avg >= 3 ? '#facc15' : '#f87171');
                    html += `<div class="cat-row">
                        <div class="cat-name">${escapeHtml(c.category)}</div>
                        <div class="cat-bar-bg"><div class="cat-bar-fill" style="width:${pct}%;background:${col}"></div></div>
                        <div class="cat-score" style="color:${col}">${c.avg.toFixed(2)}</div>
                    </div>`;
                });
            }

            if (data.questions && data.questions.length) {
                html += `<div class="section-card-title" style="font-size:14px;margin:20px 0 12px;"><i class="fa-solid fa-list-check" style="color:var(--accent)"></i> Question-by-Question Results</div>`;
                let lastCat = null;
                data.questions.forEach((q, idx) => {
                    if (q.category !== lastCat) {
                        html += `<div class="eval-cat-header">${escapeHtml(q.category)}</div>`;
                        lastCat = q.category;
                    }
                    html += `<div class="eval-q-card">
                        <div class="eval-q-no">Question ${idx + 1}</div>
                        <div class="eval-q-text">${escapeHtml(q.question_text)}</div>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <div>${starsHtml(q.score)}</div>
                            <div style="font-size:12px;color:var(--muted);font-weight:700;">Score: ${q.score} / 5</div>
                        </div>
                    </div>`;
                });
            }

            html += `<div class="section-card-title" style="font-size:14px;margin:20px 0 10px;"><i class="fa-solid fa-comment-dots" style="color:var(--teal-hover)"></i> Comments / Feedback</div>`;
            html += data.comment
                ? `<div class="eval-comment-box">"${escapeHtml(data.comment)}"</div>`
                : `<div class="eval-comment-box empty">No written feedback was provided.</div>`;

            return html;
        }
        function toggleRecentEvals() {
    const list  = document.getElementById('recentEvalsList');
    const caret = document.getElementById('recentEvalsCaret');
    const isOpen = list.style.display !== 'none';
    list.style.display = isOpen ? 'none' : 'block';
    caret.style.transform = isOpen ? '' : 'rotate(180deg)';
}

function toggleAllEvals() {
    const list  = document.getElementById('allEvalsList');
    const caret = document.getElementById('allEvalsCaret');
    const isOpen = list.style.display !== 'none';
    list.style.display = isOpen ? 'none' : 'block';
    caret.style.transform = isOpen ? '' : 'rotate(180deg)';
}

        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            const d = document.createElement('div');
            d.textContent = str;
            return d.innerHTML;
        }

        // Change-password confirm check
        const pwForm = document.getElementById('pwForm');
        if (pwForm) {
            pwForm.addEventListener('submit', function(e) {
                const newPw  = pwForm.querySelector('[name="new_password"]').value;
                const confPw = pwForm.querySelector('[name="confirm_password"]').value;
                if (newPw !== confPw) {
                    e.preventDefault();
                    alert('New password and confirmation do not match.');
                }
            });
        }

        // Mobile sidebar
        document.addEventListener('click', function(e) {
            const sb = document.getElementById('sidebar');
            if (sb.classList.contains('open') && !sb.contains(e.target) && !e.target.closest('.hamburger')) {
                sb.classList.remove('open');
            }
        });
        // ── Appearance and accessibility preferences ──
function applyAppearance(mode) {
    const normalized = ['light', 'dark', 'system'].includes(mode) ? mode : 'light';
    const systemLight = !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches);
    const effectiveLight = normalized === 'light' || (normalized === 'system' && systemLight);
    document.body.classList.toggle('light-theme', effectiveLight);
    document.body.dataset.appearance = normalized;
    const value = document.getElementById('appearanceVal');
    if (value) value.textContent = normalized.charAt(0).toUpperCase() + normalized.slice(1);
    const current = document.getElementById('appearanceCurrentMode');
    if (current) current.textContent = normalized.charAt(0).toUpperCase() + normalized.slice(1);
    document.querySelectorAll('[data-theme-choice]').forEach(function(btn) {
        const selected = btn.getAttribute('data-theme-choice') === normalized;
        btn.classList.toggle('active', selected);
        btn.setAttribute('aria-pressed', selected ? 'true' : 'false');
    });
}
function setAppearance(mode) {
    const normalized = ['light', 'dark', 'system'].includes(mode) ? mode : 'light';
    try { localStorage.setItem('pbi_theme', normalized); } catch (e) {}
    applyAppearance(normalized);
}
function applyPrincipalDensity(mode) {
    const density = mode === 'compact' ? 'compact' : 'comfortable';
    document.body.classList.toggle('compact-density', density === 'compact');
    document.querySelectorAll('[data-density-choice]').forEach(function(btn) {
        const selected = btn.getAttribute('data-density-choice') === density;
        btn.classList.toggle('active', selected);
        btn.setAttribute('aria-pressed', selected ? 'true' : 'false');
    });
}
function setPrincipalDensity(mode) {
    const density = mode === 'compact' ? 'compact' : 'comfortable';
    try { localStorage.setItem('pbi_density', density); } catch (e) {}
    applyPrincipalDensity(density);
}
function applyPrincipalReducedMotion(enabled) {
    const on = !!enabled;
    document.body.classList.toggle('reduce-motion', on);
    const btn = document.getElementById('appearanceMotionBtn');
    if (btn) btn.setAttribute('aria-pressed', on ? 'true' : 'false');
}
function togglePrincipalReducedMotion() {
    const next = !document.body.classList.contains('reduce-motion');
    try { localStorage.setItem('pbi_reduced_motion', next ? '1' : '0'); } catch (e) {}
    applyPrincipalReducedMotion(next);
}
document.addEventListener('DOMContentLoaded', function() {
    let theme = 'light', density = 'comfortable', reduceMotion = false;
    try {
        const savedTheme = localStorage.getItem('pbi_theme');
        if (['light', 'dark', 'system'].includes(savedTheme)) theme = savedTheme;
        density = localStorage.getItem('pbi_density') === 'compact' ? 'compact' : 'comfortable';
        reduceMotion = localStorage.getItem('pbi_reduced_motion') === '1';
        if (!localStorage.getItem('pbi_theme')) localStorage.setItem('pbi_theme', theme);
    } catch (e) {}
    applyAppearance(theme);
    applyPrincipalDensity(density);
    applyPrincipalReducedMotion(reduceMotion);
    const passwordForm = document.getElementById('facultySettingsPasswordForm') || document.getElementById('staffSettingsPasswordForm');
    if (passwordForm) passwordForm.addEventListener('submit', function(e) {
        const newPassword = passwordForm.querySelector('[name="new_password"]').value;
        const confirmPassword = passwordForm.querySelector('[name="confirm_password"]').value;
        if (newPassword !== confirmPassword) {
            e.preventDefault();
            alert('New password and confirmation do not match.');
        }
    });
    document.body.style.visibility = 'visible';
});
if (window.matchMedia) {
    const systemAppearance = window.matchMedia('(prefers-color-scheme: light)');
    const handleSystemAppearance = function() {
        try { if (localStorage.getItem('pbi_theme') === 'system') applyAppearance('system'); } catch (e) {}
    };
    if (systemAppearance.addEventListener) systemAppearance.addEventListener('change', handleSystemAppearance);
    else if (systemAppearance.addListener) systemAppearance.addListener(handleSystemAppearance);
}

        // ── Real-time notification bell ──
        let notificationKnownIds = new Set();
        let notificationFirstRefresh = true;
        let notificationRefreshBusy = false;
        const NOTIF_CSRF = <?= json_encode($csrf_token) ?>;

        // Mark one notification read, then open the page it points to.
        function openNotification(id, href) {
            let done = false;
            const go = () => { if (done) return; done = true; if (href) window.location.href = href; };
            if (!id) { go(); return; }
            const timer = setTimeout(go, 1200); // never let a slow request block navigation
            fetch('notifications_api.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ mark_notification_id: String(id), csrf_token: NOTIF_CSRF })
            }).catch(() => {}).finally(() => { clearTimeout(timer); go(); });
        }

        // Clicking an unread notification marks it read first; read ones just follow the link.
        document.addEventListener('click', function(e) {
            // Every notification row is a link: close the bell, mark it read if needed, then open its page.
            const item = e.target.closest('a.notif-item');
            if (!item) return;
            if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return; // let new-tab clicks behave normally
            const href = item.getAttribute('href');
            if (!href) return;
            e.preventDefault();
            document.getElementById('notifDropdown')?.classList.remove('show');
            if (item.classList.contains('unread')) {
                openNotification(Number(item.dataset.notificationId) || 0, href);
            } else {
                window.location.href = href;
            }
        });

        function toggleNotifDropdown(e) {
            e.stopPropagation();
            document.getElementById('notifDropdown')?.classList.toggle('show');
            refreshNotifications();
        }

        document.addEventListener('click', function(e) {
            const wrap = document.getElementById('notifWrap');
            if (wrap && !wrap.contains(e.target)) {
                document.getElementById('notifDropdown')?.classList.remove('show');
            }
        });

        function escapeNotificationHtml(value) {
            return String(value ?? '').replace(/[&<>\"']/g, ch => ({
                '&':'&amp;', '<':'&lt;', '>':'&gt;', '\"':'&quot;', "'":'&#039;'
            }[ch] || ch));
        }

        function notificationVisual(type) {
            if (type === 'evaluation_received') {
                return { icon: 'fa-star', color: '#facc15' };
            }
            if (type === 'designation_update') {
                return { icon: 'fa-id-badge', color: '#2B6CB0' };
            }
            if (type === 'teaching_assignment') {
                return { icon: 'fa-chalkboard-user', color: '#2B6CB0' };
            }
            if (type === 'academic_period') {
                return { icon: 'fa-calendar-days', color: '#2B6CB0' };
            }
            if (type === 'evaluation_schedule') {
                return { icon: 'fa-clock', color: '#2B6CB0' };
            }
            return { icon: 'fa-bell', color: 'var(--teal-hover)' };
        }

        function renderLiveNotifications(items) {
            const list = document.querySelector('#notifDropdown .notif-list');
            if (!list) return;

            if (!items.length) {
                list.dataset.sig = '';
                list.innerHTML = '<div class="notif-empty"><i class="fa-regular fa-bell-slash"></i>No notifications yet.</div>';
                return;
            }

            const html = items.map(n => {
                const v = notificationVisual(n.type);
                const unread = Number(n.is_read) === 0;
                return `<a class="notif-item ${unread ? 'unread' : ''}" data-notification-id="${Number(n.id) || 0}" href="${escapeNotificationHtml(n.link || 'staff_dashboard.php?page=dashboard')}">\n` +
                    `  <div class="notif-icon" style="color:${v.color};background:${v.color}22;"><i class="fa-solid ${v.icon}"></i></div>\n` +
                    `  <div style="flex:1;min-width:0;">\n` +
                    `    <div class="notif-text">${escapeNotificationHtml(n.message)}</div>\n` +
                    `    <div class="notif-meta">${escapeNotificationHtml(n.created_at_label)}</div>\n` +
                    `  </div>\n` +
                    `</a>`;
            }).join('');

            // Re-render only when something changed, and keep the user's scroll position
            if (list.dataset.sig === html) return;
            const prevTop = list.scrollTop;
            list.dataset.sig = html;
            list.innerHTML = html;
            list.scrollTop = prevTop;
        }

        function updateNotificationBadge(count) {
            const button = document.getElementById('notifBtn');
            const existing = document.getElementById('notifBadge');
            const safeCount = Math.max(0, Number(count) || 0);

            button?.classList.toggle('has-unread', safeCount > 0);

            if (safeCount > 0) {
                const label = safeCount > 99 ? '99+' : String(safeCount);
                if (existing) {
                    existing.textContent = label;
                } else if (button) {
                    const badge = document.createElement('span');
                    badge.className = 'notif-badge show';
                    badge.id = 'notifBadge';
                    badge.textContent = label;
                    button.appendChild(badge);
                }
            } else if (existing) {
                existing.remove();
            }
        }

        function showLiveNotificationToast(notification) {
            const existing = document.getElementById('liveNotifToast');
            if (existing) existing.remove();

            const toast = document.createElement('div');
            toast.id = 'liveNotifToast';
            toast.className = 'live-notif-toast';
            toast.innerHTML = `<div class="live-notif-toast-icon"><i class="fa-solid fa-bell"></i></div>` +
                `<div><strong>New notification</strong><span>${escapeNotificationHtml(notification.message)}</span></div>`;
            toast.addEventListener('click', () => openNotification(Number(notification.id) || 0, notification.link || 'staff_dashboard.php?page=dashboard'));
            document.body.appendChild(toast);
            requestAnimationFrame(() => toast.classList.add('show'));
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 250);
            }, 5000);
        }

        async function refreshNotifications(showNewToast = true) {
            if (notificationRefreshBusy || document.hidden) return;
            notificationRefreshBusy = true;
            try {
                const response = await fetch('notifications_api.php?_=' + Date.now(), {
                    method: 'GET',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: { 'Accept': 'application/json' }
                });
                if (!response.ok) throw new Error('Notification request failed');
                const data = await response.json();
                if (!data.success) throw new Error(data.message || 'Notification request failed');

                const incoming = Array.isArray(data.notifications) ? data.notifications : [];
                if (!notificationFirstRefresh && showNewToast) {
                    const newUnread = incoming.filter(n => Number(n.is_read) === 0 && !notificationKnownIds.has(Number(n.id)));
                    // Auto-refresh read-only pages when something relevant changes (never on forms, never every 2s blindly).
                    const pageName = new URLSearchParams(window.location.search).get('page') || 'dashboard';
                    const refreshTypes = pageName === 'my_results' ? ['evaluation_received']
                                       : pageName === 'dashboard'  ? ['evaluation_received', 'evaluation_schedule', 'academic_period']
                                       : [];
                    const needsRefresh = newUnread.some(n => refreshTypes.includes(n.type));
                    const ae = document.activeElement;
                    const busyTyping = ae && /^(INPUT|TEXTAREA|SELECT)$/.test(ae.tagName);
                    const modalOpen = document.querySelector('[class*="modal"].open, [class*="overlay"].open, [class*="modal"].show');
                    if (needsRefresh && !busyTyping && !modalOpen) { window.location.reload(); return; }
                    if (newUnread.length) showLiveNotificationToast(newUnread[0]);
                }

                notificationKnownIds = new Set(incoming.map(n => Number(n.id)));
                notificationFirstRefresh = false;
                renderLiveNotifications(incoming);
                updateNotificationBadge(data.unread_count);
            } catch (err) {
                console.debug('Notification polling:', err.message);
            } finally {
                notificationRefreshBusy = false;
            }
        }

        function startNotificationPolling() {
            refreshNotifications(false);
            setInterval(() => refreshNotifications(true), 2000);
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) refreshNotifications(false);
            });

            const markReadForm = document.querySelector('#notifDropdown form');
            if (markReadForm) {
                markReadForm.addEventListener('submit', async function(e) {
                    e.preventDefault();
                    try {
                        const response = await fetch('notifications_api.php', {
                            method: 'POST',
                            credentials: 'same-origin',
                            cache: 'no-store',
                            headers: { 'Accept': 'application/json' },
                            body: new FormData(markReadForm)
                        });
                        if (!response.ok) throw new Error('Mark-read request failed');
                        const data = await response.json();
                        if (!data.success) throw new Error(data.message || 'Mark-read request failed');
                        renderLiveNotifications(Array.isArray(data.notifications) ? data.notifications : []);
                        updateNotificationBadge(data.unread_count);
                    } catch (err) {
                        console.debug('Mark notifications read:', err.message);
                    }
                });
            }
        }

        document.addEventListener('DOMContentLoaded', startNotificationPolling);

        // ── Profile photo modal ──
        function openPhotoModal() {
            document.getElementById('photoModal').classList.add('open');
            document.body.style.overflow = 'hidden';
        }
        function closePhotoModal() {
            document.getElementById('photoModal').classList.remove('open');
            document.body.style.overflow = '';
        }
        function previewPhoto(input) {
            if (input.files && input.files[0]) {
                const r = new FileReader();
                r.onload = e => {
                    const img = document.getElementById('photoPreviewImg');
                    const ic  = document.getElementById('uploadIconEl');
                    img.src = e.target.result;
                    img.style.display = 'block';
                    ic.style.display  = 'none';
                };
                r.readAsDataURL(input.files[0]);
            }
        }
        function submitPhoto() {
            const fileInput = document.getElementById('photoFileInput');
            if (!fileInput.files || !fileInput.files[0]) { closePhotoModal(); return; }
            document.getElementById('photoForm').submit();
        }
        document.getElementById('photoModal').addEventListener('click', function(e) {
            if (e.target === this) closePhotoModal();
        });
        </script>

        <?php $mysqli->close(); ?>
        <script src="../admin/eval_status_poll.js" defer></script>
        
<!-- Logout confirmation (replaces the browser's native confirm popup) -->
<style>
.lgo-overlay{position:fixed;inset:0;z-index:99999;display:none;align-items:center;justify-content:center;padding:16px;background:rgba(2,8,23,.55);backdrop-filter:blur(3px)}
.lgo-overlay.open{display:flex}
.lgo-box{width:100%;max-width:360px;background:var(--mid);color:var(--light);border:1px solid var(--border);border-top:3px solid var(--teal);border-radius:16px;padding:24px 22px 20px;box-shadow:0 24px 70px rgba(0,0,0,.35);text-align:center;font-family:inherit}
.lgo-icon{width:46px;height:46px;margin:0 auto 12px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:rgba(22,163,74,.14);color:var(--teal);font-size:18px}
.lgo-title{font-size:17px;font-weight:700;margin:0 0 6px;color:var(--light)}
.lgo-text{font-size:13.5px;line-height:1.5;color:var(--muted);margin:0 0 18px}
.lgo-actions{display:flex;gap:10px}
.lgo-actions a,.lgo-actions button{flex:1;padding:10px 12px;border-radius:10px;font:inherit;font-size:13.5px;font-weight:600;cursor:pointer;text-decoration:none;text-align:center;border:1px solid transparent;transition:background .15s,border-color .15s}
.lgo-cancel{background:transparent;color:var(--light);border-color:var(--border)}
.lgo-cancel:hover{background:var(--inner)}
.lgo-ok{background:var(--teal);color:#fff}
.lgo-ok:hover{background:var(--teal-hover)}
.lgo-actions a:focus-visible,.lgo-actions button:focus-visible{outline:3px solid rgba(22,163,74,.45);outline-offset:2px}
</style>
<div class="lgo-overlay" id="lgoOverlay" role="dialog" aria-modal="true" aria-labelledby="lgoTitle">
    <div class="lgo-box">
        <div class="lgo-icon"><i class="fa-solid fa-power-off"></i></div>
        <h3 class="lgo-title" id="lgoTitle">Log Out</h3>
        <p class="lgo-text">Are you sure you want to log out?</p>
        <div class="lgo-actions">
            <button type="button" class="lgo-cancel" id="lgoCancel">Cancel</button>
            <a href="#" class="lgo-ok" id="lgoOk">Log Out</a>
        </div>
    </div>
</div>
<script>
(function(){
    var ov=document.getElementById('lgoOverlay'),ok=document.getElementById('lgoOk'),cancel=document.getElementById('lgoCancel');
    if(!ov)return;
    window.openLogoutModal=function(link){
        ok.href=link.getAttribute('href');
        ov.classList.add('open');
        cancel.focus();
        return false;
    };
    function close(){ov.classList.remove('open');}
    cancel.addEventListener('click',close);
    ov.addEventListener('click',function(e){if(e.target===ov)close();});
    document.addEventListener('keydown',function(e){if(e.key==='Escape')close();});
})();
</script>
<!-- ══════════ LEAVE-WITHOUT-SUBMITTING GUARD (evaluation forms) ══════════ -->
<style>
.leave-guard-overlay{position:fixed;inset:0;z-index:100000;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(15,23,42,.42);-webkit-backdrop-filter:blur(6px);backdrop-filter:blur(6px);}
.leave-guard-overlay.open{display:flex;}
.leave-guard-box{width:100%;max-width:525px;background:#fff;border-radius:22px;padding:32px 35px 30px;box-shadow:0 30px 80px rgba(15,23,42,.28);font-family:inherit;animation:leaveGuardIn .18s ease-out;}
@keyframes leaveGuardIn{from{opacity:0;transform:translateY(8px) scale(.98);}to{opacity:1;transform:none;}}
.leave-guard-title{margin:0 0 10px;font-size:19px;line-height:24px;font-weight:700;color:#0f172a;}
.leave-guard-text{margin:0 0 26px;font-size:15px;line-height:26px;color:#64748b;}
.leave-guard-actions{display:flex;gap:15px;}
.leave-guard-actions button{flex:1;height:46px;border-radius:12px;font:inherit;font-size:15.5px;font-weight:700;letter-spacing:.2px;cursor:pointer;transition:background .15s,box-shadow .15s,transform .1s;}
.leave-guard-actions button:active{transform:translateY(1px);}
.leave-guard-stay{background:#fff;color:#0f172a;border:1px solid #e2e8f0;}
.leave-guard-stay:hover{background:#f8fafc;}
.leave-guard-leave{background:var(--teal,#2563EB);color:#fff;border:0;box-shadow:0 10px 24px rgba(0,0,0,.18);box-shadow:0 10px 24px color-mix(in srgb,var(--teal,#2563EB) 38%,transparent);}
.leave-guard-leave:hover{background:var(--teal-hover,#1D4ED8);}
.leave-guard-actions button:focus-visible{outline:3px solid color-mix(in srgb,var(--teal,#2563EB) 45%,transparent);outline-offset:2px;}
@media(max-width:560px){.leave-guard-box{padding:26px 22px 24px;border-radius:18px;}.leave-guard-actions{gap:12px;}}
</style>
<div class="leave-guard-overlay" id="leaveGuardOverlay" aria-hidden="true">
    <div class="leave-guard-box" role="alertdialog" aria-modal="true" aria-labelledby="leaveGuardTitle" aria-describedby="leaveGuardText">
        <h3 class="leave-guard-title" id="leaveGuardTitle">Leave without submitting?</h3>
        <p class="leave-guard-text" id="leaveGuardText">You have an unsubmitted evaluation. If you leave now, your answers will be lost.</p>
        <div class="leave-guard-actions">
            <button type="button" class="leave-guard-stay" id="leaveGuardStay">Stay</button>
            <button type="button" class="leave-guard-leave" id="leaveGuardLeave">Leave</button>
        </div>
    </div>
</div>
<script>
(function(){
    // Works for the faculty/staff peer evaluation (#evalForm) and the staff evaluation (#staffEvalForm).
    var form = document.getElementById('evalForm') || document.getElementById('staffEvalForm');
    var ov = document.getElementById('leaveGuardOverlay');
    if (!form || !ov) return;
    var stay = document.getElementById('leaveGuardStay'),
        leave = document.getElementById('leaveGuardLeave'),
        pending = null, allow = false, lastFocus = null;

    function dirty() {
        if (form.querySelector('input[type="radio"]:checked')) return true;
        var tas = form.querySelectorAll('textarea');
        for (var i = 0; i < tas.length; i++) if (tas[i].value.trim() !== '') return true;
        return false;
    }
    function openGuard(url) {
        pending = url; lastFocus = document.activeElement;
        ov.classList.add('open'); ov.setAttribute('aria-hidden', 'false');
        stay.focus();
    }
    function closeGuard() {
        ov.classList.remove('open'); ov.setAttribute('aria-hidden', 'true'); pending = null;
        if (lastFocus && lastFocus.focus) lastFocus.focus();
    }

    // Intercept every link that would take the user away from the form (X, Cancel, sidebar, logout…).
    document.addEventListener('click', function(e) {
        if (allow || ov.contains(e.target)) return;
        var a = e.target.closest && e.target.closest('a[href]');
        if (!a || !dirty()) return;
        var href = a.getAttribute('href');
        if (!href || href.charAt(0) === '#' || /^javascript:/i.test(href) || a.target === '_blank') return;
        e.preventDefault(); e.stopPropagation();
        openGuard(a.href);
    }, true);

    stay.addEventListener('click', closeGuard);
    leave.addEventListener('click', function() {
        allow = true;
        var url = pending; ov.classList.remove('open');
        if (url) window.location.href = url;
    });
    ov.addEventListener('click', function(e) { if (e.target === ov) closeGuard(); });
    // Accidental click on the dimmed area outside the evaluation card -> ask first.
    var evalOverlay = document.querySelector('.peer-eval-overlay, .staff-eval-modal-overlay');
    var closeLink = document.querySelector('.peer-eval-close, .staff-eval-modal-close');
    var downOnBackdrop = false;
    if (evalOverlay) {
        evalOverlay.addEventListener('mousedown', function(e) { downOnBackdrop = (e.target === evalOverlay); });
        evalOverlay.addEventListener('touchstart', function(e) { downOnBackdrop = (e.target === evalOverlay); }, {passive: true});
        evalOverlay.addEventListener('click', function(e) {
            // Only a press AND release on the backdrop counts (not a text-selection drag that ends outside the card).
            if (e.target !== evalOverlay || !downOnBackdrop) return;
            downOnBackdrop = false;
            if (allow || !dirty() || !closeLink) return;
            openGuard(closeLink.href);
        });
    }

    document.addEventListener('keydown', function(e) {
        if (e.key !== 'Escape') return;
        if (ov.classList.contains('open')) { closeGuard(); return; }
        if (!allow && dirty() && closeLink) openGuard(closeLink.href);
    });

    // A successful submit must never trigger the guard.
    document.addEventListener('submit', function(e) { if (!e.defaultPrevented) allow = true; });

    // Fallback for browser back / refresh / closing the tab.
    window.addEventListener('beforeunload', function(e) {
        if (allow || !dirty()) return;
        e.preventDefault(); e.returnValue = '';
    });
})();
</script>


<style id="principal-matched-account-settings">
/* Principal Account Settings visual language, recoloured for this role. */
.settings-shell.principal-settings-layout {
  --ap-card:var(--mid);--ap-soft:var(--inner);--ap-line:var(--border);
  --ap-text:var(--light);--ap-muted:var(--muted);
  --ap-accent-bg:rgba(var(--ap-accent-rgb),.10);--ap-ring:rgba(var(--ap-accent-rgb),.14);
  max-width:1480px;padding-top:24px !important;padding-bottom:30px !important;
}
.settings-shell.principal-settings-layout .settings-page-head{display:flex;align-items:center;justify-content:space-between;gap:20px;margin:0 0 22px;padding:0 2px;}
.settings-shell.principal-settings-layout .settings-page-kicker{display:flex;align-items:center;gap:7px;color:var(--ap-accent-dark);font-size:10.5px;font-weight:800;letter-spacing:1.05px;text-transform:uppercase;margin-bottom:5px;}
.settings-shell.principal-settings-layout .settings-page-title{margin:0;color:var(--ap-text);font-family:'Rajdhani',sans-serif;font-size:29px;font-weight:800;line-height:1.15;}
.settings-shell.principal-settings-layout .settings-page-subtitle{margin:5px 0 0;color:var(--ap-muted);font-size:13px;line-height:1.5;}
.settings-shell.principal-settings-layout .settings-appearance-current{display:inline-flex;align-items:center;gap:7px;padding:9px 12px;border-radius:999px;background:var(--ap-accent-bg);border:1px solid rgba(var(--ap-accent-rgb),.22);color:var(--ap-accent-dark);font-size:11.5px;font-weight:700;white-space:nowrap;}
.settings-shell.principal-settings-layout .settings-appearance-current strong{color:var(--ap-text);}
.settings-shell.principal-settings-layout .settings-layout-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;align-items:start;margin-top:0;}
.settings-shell.principal-settings-layout .settings-card.ac-card{position:relative !important;overflow:hidden !important;grid-column:auto;border-radius:15px !important;padding:22px 24px 24px !important;margin:0 !important;background:var(--ap-card) !important;color:var(--ap-text) !important;border:1px solid var(--ap-line) !important;box-shadow:0 2px 10px rgba(15,23,42,.055) !important;}
.settings-shell.principal-settings-layout .settings-card.ac-card.full{grid-column:1/-1;}
.settings-shell.principal-settings-layout .settings-card.ac-card::before{content:'';position:absolute;left:0;right:0;top:0;height:3px;background:var(--ap-card-accent,var(--ap-accent));}
.settings-shell.principal-settings-layout .ac-c1{--ap-card-accent:var(--ap-accent);}
.settings-shell.principal-settings-layout .ac-c2{--ap-card-accent:var(--ap-accent);}
.settings-shell.principal-settings-layout .ac-c3{--ap-card-accent:var(--ap-accent);}
.settings-shell.principal-settings-layout .ac-c4{--ap-card-accent:var(--ap-accent);}
.settings-shell.principal-settings-layout .ac-extra{--ap-card-accent:var(--ap-accent);}
.settings-shell.principal-settings-layout .ac-head{display:flex;align-items:flex-start;gap:14px;margin:0 0 18px;}
.settings-shell.principal-settings-layout .ac-icon{width:42px;height:42px;flex:0 0 42px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;font-size:17px;background:var(--ap-accent-bg) !important;color:var(--ap-accent-dark) !important;}
.settings-shell.principal-settings-layout .ac-icon i{font-size:17px;color:var(--ap-accent-dark) !important;}
.settings-shell.principal-settings-layout .ac-head h2{display:block;margin:0 0 4px;font-family:'Rajdhani',sans-serif;font-size:21px;font-weight:800;line-height:1.15;letter-spacing:0;color:var(--ap-text) !important;}
.settings-shell.principal-settings-layout .ac-head .ac-sub{margin:0;font-size:13px;line-height:1.5;color:var(--ap-muted) !important;opacity:1;}
.settings-shell.principal-settings-layout .ac-head.appearance-heading-row{justify-content:flex-start;}
.settings-shell.principal-settings-layout .appearance-device-badge{margin-left:auto;display:inline-flex;align-items:center;gap:6px;padding:7px 10px;border-radius:999px;background:var(--ap-soft);border:1px solid var(--ap-line);color:var(--ap-muted);font-size:10.5px;font-weight:700;white-space:nowrap;}
.settings-shell.principal-settings-layout .appearance-feature-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;}
.settings-shell.principal-settings-layout .appearance-theme-card{grid-column:1/-1;}
.settings-shell.principal-settings-layout .appearance-control-card{display:block;background:var(--ap-soft);border:1px solid var(--ap-line);border-radius:14px;padding:18px;box-shadow:none;min-width:0;}
.settings-shell.principal-settings-layout .appearance-control-head{display:flex;align-items:flex-start;gap:12px;margin-bottom:14px;}
.settings-shell.principal-settings-layout .appearance-control-icon{width:34px;height:34px;flex:0 0 34px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;background:var(--ap-accent-bg);color:var(--ap-accent-dark);font-size:14px;}
.settings-shell.principal-settings-layout .appearance-control-head h3{font-size:15px;font-weight:700;color:var(--ap-text);margin:0 0 2px;}
.settings-shell.principal-settings-layout .appearance-control-head p{font-size:12.5px;color:var(--ap-muted);margin:0;line-height:1.45;}
.settings-shell.principal-settings-layout .appearance-theme-options{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;}
.settings-shell.principal-settings-layout .appearance-choice{position:relative;display:flex;flex-direction:row;align-items:center;justify-content:flex-start;gap:12px;width:auto;height:auto;min-height:0;padding:14px;text-align:left;border-radius:12px;border:1.5px solid var(--ap-line);background:var(--ap-card);color:var(--ap-text);cursor:pointer;box-shadow:none;transition:border-color .18s,background .18s;}
.settings-shell.principal-settings-layout .appearance-choice::before,.settings-shell.principal-settings-layout .appearance-choice::after{display:none;content:none;}
.settings-shell.principal-settings-layout .appearance-choice:hover{border-color:var(--ap-accent);color:var(--ap-text);}
.settings-shell.principal-settings-layout .appearance-choice-icon{width:36px;height:36px;flex:0 0 36px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;background:var(--ap-soft);color:var(--ap-muted);font-size:15px;}
.settings-shell.principal-settings-layout .appearance-choice-copy{display:block;min-width:0;}
.settings-shell.principal-settings-layout .appearance-choice-copy strong,.settings-shell.principal-settings-layout .appearance-density-choice strong,.settings-shell.principal-settings-layout .appearance-motion-copy strong{display:block;font-size:13.5px;font-weight:700;color:var(--ap-text);}
.settings-shell.principal-settings-layout .appearance-choice-copy small,.settings-shell.principal-settings-layout .appearance-density-choice small,.settings-shell.principal-settings-layout .appearance-motion-copy small{display:block;font-size:11.5px;color:var(--ap-muted);margin-top:1px;}
.settings-shell.principal-settings-layout .appearance-choice-check{position:static;margin-left:auto;width:20px;height:20px;flex:0 0 20px;border:0;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:10px;background:var(--ap-accent);color:#fff;opacity:0;transform:scale(.6);transition:opacity .2s,transform .2s;}
.settings-shell.principal-settings-layout .appearance-choice[aria-pressed="true"],.settings-shell.principal-settings-layout .appearance-choice.active{border-color:var(--ap-accent);background:var(--ap-accent-bg);color:var(--ap-text);box-shadow:0 0 0 3px var(--ap-ring);}
.settings-shell.principal-settings-layout .appearance-choice[aria-pressed="true"] .appearance-choice-icon{background:var(--ap-accent);color:#fff;}
.settings-shell.principal-settings-layout .appearance-choice[aria-pressed="true"] .appearance-choice-check{opacity:1;transform:scale(1);}
.settings-shell.principal-settings-layout .appearance-inline-status{margin-top:14px;font-size:12.5px;color:var(--ap-muted);display:flex;align-items:center;gap:7px;}
.settings-shell.principal-settings-layout .appearance-inline-status i{color:var(--ap-accent-dark);}
.settings-shell.principal-settings-layout .appearance-inline-status strong{color:var(--ap-text);}
.settings-shell.principal-settings-layout .appearance-density-options{display:grid;grid-template-columns:1fr;gap:10px;}
.settings-shell.principal-settings-layout .appearance-density-choice{display:flex;align-items:center;gap:12px;width:100%;padding:12px 14px;text-align:left;border-radius:12px;border:1.5px solid var(--ap-line);background:var(--ap-card);color:var(--ap-muted);cursor:pointer;box-shadow:none;}
.settings-shell.principal-settings-layout .appearance-density-choice:hover{border-color:var(--ap-accent);}
.settings-shell.principal-settings-layout .appearance-density-choice>i{width:20px;flex:0 0 20px;background:transparent;border-radius:0;font-size:15px;text-align:center;color:var(--ap-muted);}
.settings-shell.principal-settings-layout .appearance-density-choice[aria-pressed="true"],.settings-shell.principal-settings-layout .appearance-density-choice.active{border-color:var(--ap-accent);background:var(--ap-accent-bg);}
.settings-shell.principal-settings-layout .appearance-density-choice[aria-pressed="true"]>i{color:var(--ap-accent-dark);}
.settings-shell.principal-settings-layout .appearance-motion-toggle{width:100%;display:flex;align-items:center;justify-content:space-between;gap:14px;padding:12px 14px;text-align:left;border-radius:12px;border:1.5px solid var(--ap-line);background:var(--ap-card);cursor:pointer;box-shadow:none;color:var(--ap-text);}
.settings-shell.principal-settings-layout .appearance-motion-toggle:hover{border-color:var(--ap-accent);}
.settings-shell.principal-settings-layout .appearance-motion-toggle[aria-pressed="true"]{border-color:var(--ap-accent);background:var(--ap-accent-bg);}
.settings-shell.principal-settings-layout .appearance-switch-track{width:44px;height:24px;flex:0 0 44px;border-radius:999px;background:#CBD5E1;position:relative;transition:background .2s;}
.settings-shell.principal-settings-layout .appearance-switch-thumb{position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:transform .2s;}
.settings-shell.principal-settings-layout .appearance-motion-toggle[aria-pressed="true"] .appearance-switch-track{background:var(--ap-accent);}
.settings-shell.principal-settings-layout .appearance-motion-toggle[aria-pressed="true"] .appearance-switch-thumb{transform:translateX(20px);}
.settings-shell.principal-settings-layout .appearance-accessibility-note{margin-top:12px;font-size:11.5px;color:var(--ap-muted);display:flex;gap:7px;align-items:flex-start;line-height:1.5;}
.settings-shell.principal-settings-layout .appearance-accessibility-note i{color:var(--ap-accent-dark);margin-top:2px;}
.settings-shell.principal-settings-layout .profile-card{display:flex;align-items:center;gap:24px;}
.settings-shell.principal-settings-layout .profile-photo-lg{width:96px;height:96px;flex:0 0 96px;border-radius:50%;object-fit:cover;border:3px solid var(--ap-accent);background:var(--ap-soft);display:flex;align-items:center;justify-content:center;color:var(--ap-accent-dark);font-size:28px;}
.settings-shell.principal-settings-layout .profile-photo-fallback{display:flex;}
.settings-shell.principal-settings-layout .photo-form-wrap{display:flex;flex-direction:column;align-items:flex-start;gap:3px;min-width:0;}
.settings-shell.principal-settings-layout .photo-help{font-size:12px;color:var(--ap-muted);margin-top:8px;line-height:1.5;}
.settings-shell.principal-settings-layout .form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:17px 20px;margin-bottom:20px;}
.settings-shell.principal-settings-layout .form-group{display:flex;flex-direction:column;gap:7px;min-width:0;}
.settings-shell.principal-settings-layout .form-group label{font-size:11px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;color:var(--ap-muted);}
.settings-shell.principal-settings-layout .form-group input{width:100%;box-sizing:border-box;padding:12px 13px;font:14px 'DM Sans',sans-serif;border-radius:9px;border:1px solid var(--ap-line);background:var(--ap-card);color:var(--ap-text);outline:none;}
.settings-shell.principal-settings-layout .form-group input:focus{border-color:var(--ap-accent);box-shadow:0 0 0 3px var(--ap-ring);}
.settings-shell.principal-settings-layout .form-group input[readonly]{background:var(--ap-soft);color:var(--ap-muted);cursor:default;}
.settings-shell.principal-settings-layout .field-note{font-size:11px;line-height:1.4;color:var(--ap-muted);}
.settings-shell.principal-settings-layout .btn-primary{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 17px;border:0;border-radius:9px;font:700 13.5px 'DM Sans',sans-serif;color:#fff;background:var(--ap-accent);box-shadow:0 4px 14px rgba(var(--ap-accent-rgb),.20);cursor:pointer;text-decoration:none;transition:transform .18s,background .18s;}
.settings-shell.principal-settings-layout .btn-primary:hover{background:var(--ap-accent-strong);color:#fff;transform:translateY(-1px);}
.settings-shell.principal-settings-layout .ac-c5{--ap-card-accent:var(--ap-accent);}
.settings-shell.principal-settings-layout a.btn-primary{text-decoration:none;}
.settings-shell.principal-settings-layout .sq-status{display:inline-flex;align-items:center;gap:7px;padding:5px 11px;margin-bottom:16px;border-radius:999px;font-size:12px;font-weight:600;background:var(--ap-accent-bg);border:1px solid var(--ap-line);color:var(--ap-muted);}
.settings-shell.principal-settings-layout .sq-status.ok{color:#10B981;border-color:rgba(16,185,129,.4);}
.settings-shell.principal-settings-layout .sq-status.warn{color:#D97706;border-color:rgba(217,119,6,.45);}
.settings-shell.principal-settings-layout .btn-primary:focus-visible,.settings-shell.principal-settings-layout button:focus-visible{outline:3px solid rgba(var(--ap-accent-rgb),.30);outline-offset:2px;}
.settings-shell.principal-settings-layout .settings-action-link{font-size:12px;padding:9px 12px;white-space:nowrap;}
.settings-shell.principal-settings-layout .settings-protected-icon{color:var(--ap-accent-dark);font-size:17px;flex:0 0 auto;}
.settings-shell.principal-settings-layout .setting-row{display:flex;align-items:center;justify-content:space-between;gap:18px;padding:13px 0;border-top:1px solid var(--ap-line);}
.settings-shell.principal-settings-layout .setting-row:first-of-type{border-top:0;}
.settings-shell.principal-settings-layout .setting-row strong{display:block;color:var(--ap-text);font-size:13px;}
.settings-shell.principal-settings-layout .setting-row span{display:block;color:var(--ap-muted);font-size:11.5px;margin-top:3px;line-height:1.45;}
.settings-shell.principal-settings-layout .setting-toggle{position:relative;width:44px;height:24px;flex:0 0 44px;}
.settings-shell.principal-settings-layout .setting-toggle input{display:none;}
.settings-shell.principal-settings-layout .setting-toggle .slider{position:absolute;inset:0;border-radius:20px;background:var(--ap-line);cursor:pointer;transition:background .2s;}
.settings-shell.principal-settings-layout .setting-toggle .slider:before{content:'';position:absolute;width:18px;height:18px;left:3px;top:3px;background:#fff;border-radius:50%;transition:transform .2s;}
.settings-shell.principal-settings-layout .setting-toggle input:checked+.slider{background:var(--ap-accent);}
.settings-shell.principal-settings-layout .setting-toggle input:checked+.slider:before{transform:translateX(20px);}
.settings-shell.principal-settings-layout .settings-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:16px;}
.settings-shell.principal-settings-layout .account-facts{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;}
.settings-shell.principal-settings-layout .account-fact{background:var(--ap-soft);border:1px solid var(--ap-line);border-radius:10px;padding:12px;min-width:0;}
.settings-shell.principal-settings-layout .account-fact label{display:block;font-size:10px;text-transform:uppercase;letter-spacing:.7px;color:var(--ap-muted);margin-bottom:4px;}
.settings-shell.principal-settings-layout .account-fact b{color:var(--ap-text);font-size:12.5px;overflow-wrap:anywhere;}
body:not(.light-theme) .settings-shell.principal-settings-layout{--ap-text:#E0E6F0;--ap-muted:#A0B3C6;--ap-line:rgba(255,255,255,.10);--ap-accent-bg:rgba(var(--ap-accent-rgb),.16);--ap-ring:rgba(var(--ap-accent-rgb),.16);}
body:not(.light-theme) .settings-shell.principal-settings-layout .settings-card.ac-card{box-shadow:0 8px 24px rgba(0,0,0,.18) !important;}
body:not(.light-theme) .settings-shell.principal-settings-layout .appearance-choice-check{color:#fff;}
body.compact-density .settings-shell.principal-settings-layout .settings-card.ac-card{padding:17px 19px 19px !important;}
body.compact-density .settings-shell.principal-settings-layout .appearance-control-card{padding:14px;}
body.compact-density .settings-shell.principal-settings-layout .setting-row{padding:9px 0;}

/* ── Settings tabs (Profile & Security / Appearance) — uses each portal's own accent ── */
.settings-shell.principal-settings-layout .settings-tabs{display:inline-flex;gap:6px;padding:6px;margin:0 0 20px;background:var(--ap-card);border:1px solid var(--ap-line);border-radius:16px;box-shadow:0 2px 10px rgba(15,23,42,.045);max-width:100%;}
.settings-shell.principal-settings-layout .settings-tab{display:inline-flex;align-items:center;justify-content:center;gap:9px;padding:11px 22px;border:0;border-radius:11px;background:transparent;color:var(--ap-muted);font:700 14px 'DM Sans',sans-serif;cursor:pointer;white-space:nowrap;transition:background .18s,color .18s,box-shadow .18s;}
.settings-shell.principal-settings-layout .settings-tab i{font-size:15px;}
.settings-shell.principal-settings-layout .settings-tab:hover{color:var(--ap-text);background:var(--ap-accent-bg);}
.settings-shell.principal-settings-layout .settings-tab.active{background:var(--ap-accent);color:#fff;box-shadow:0 6px 16px rgba(var(--ap-accent-rgb),.28);}
.settings-shell.principal-settings-layout .settings-tab:focus-visible{outline:3px solid rgba(var(--ap-accent-rgb),.30);outline-offset:2px;}
.settings-shell.principal-settings-layout .settings-tab-panel[hidden]{display:none !important;}
@media(max-width:560px){.settings-shell.principal-settings-layout .settings-tabs{display:flex;width:100%;box-sizing:border-box;}.settings-shell.principal-settings-layout .settings-tab{flex:1;padding:11px 10px;font-size:13px;}}
body.reduce-motion *,body.reduce-motion *::before,body.reduce-motion *::after{animation-duration:.01ms !important;animation-iteration-count:1 !important;scroll-behavior:auto !important;transition-duration:.01ms !important;}
@media(max-width:900px){.settings-shell.principal-settings-layout .appearance-theme-options{grid-template-columns:1fr;}.settings-shell.principal-settings-layout .settings-layout-grid{grid-template-columns:1fr;}.settings-shell.principal-settings-layout .settings-card.ac-card.full{grid-column:auto;}.settings-shell.principal-settings-layout .appearance-feature-grid{grid-template-columns:1fr;}.settings-shell.principal-settings-layout .appearance-theme-card{grid-column:auto;}}
@media(max-width:760px){.settings-shell.principal-settings-layout .settings-page-head{align-items:flex-start;flex-direction:column;}.settings-shell.principal-settings-layout .appearance-device-badge{display:none;}.settings-shell.principal-settings-layout .account-facts{grid-template-columns:1fr;}.settings-shell.principal-settings-layout .form-grid{grid-template-columns:1fr;}}
@media(max-width:560px){.settings-shell.principal-settings-layout .settings-card.ac-card{padding:18px 17px 20px !important;}.settings-shell.principal-settings-layout .profile-card{align-items:flex-start;flex-direction:column;}.settings-shell.principal-settings-layout .ac-head{gap:11px;}.settings-shell.principal-settings-layout .ac-icon{width:38px;height:38px;flex-basis:38px;}.settings-shell.principal-settings-layout .setting-row{align-items:flex-start;}}
</style>

<style id="staff-form-ea-sizing">
/* Staff evaluation popups sized like the admin EA evaluation form. */
html body .staff-eval-modal{max-width:780px;flex-basis:780px;}
html body .staff-eval-modal-head{padding:24px 28px 16px;}
html body .staff-eval-modal-avatar,html body .staff-eval-modal-avatar-ph{width:52px;height:52px;flex:0 0 52px;}
html body .staff-eval-modal-identity h2{font-size:20px;}
html body .staff-eval-modal-identity p{font-size:12px;}
html body .staff-eval-modal-body{padding:24px 28px;}
html body .staff-eval-rating-guide{gap:16px;margin-bottom:18px;padding:10px 14px;border-radius:8px;}
html body .staff-eval-rating-guide-item{gap:5px;font-size:11px;}
html body .staff-eval-rating-guide-item span{width:18px;height:18px;flex:0 0 18px;border-radius:4px;font-size:10px;}
html body .staff-eval-category-title{margin:18px 0 8px;font-size:10px;letter-spacing:.9px;}
html body .staff-eval-table-wrap{margin:0 0 16px!important;}
html body .staff-eval-table td{padding:10px 6px!important;}
html body .staff-eval-table td:first-child{padding-left:14px!important;padding-right:10px!important;}
html body .staff-eval-table col.c-rate{width:52px;}
html body .staff-eval-table .compact-eval-qtext{font-size:12.5px!important;font-weight:400!important;}
html body .staff-eval-table .compact-eval-rating label{width:34px!important;height:30px!important;border-radius:7px!important;font-size:12px!important;font-weight:800!important;}
html body .staff-eval-comments-card{margin-top:24px;padding:16px;border-radius:10px;}
html body .staff-eval-comments-label{font-size:12px;}
html body .staff-eval-modal-foot{padding:16px 28px 24px;}
html body .staff-eval-cancel,html body .staff-eval-submit{min-height:0;padding-top:13px;padding-bottom:13px;}
html body .staff-eval-submit{box-shadow:none;font-size:15px;}

/* Staff peer-to-peer popup */
html body .peer-eval-dialog{max-width:780px;border-radius:18px;}
html body .peer-eval-dialog .peer-eval-dialog-header{padding:24px 28px 16px;}
html body .peer-eval-dialog .peer-eval-avatar{width:52px;height:52px;}
html body .peer-eval-dialog .peer-eval-target-copy h2{font-size:20px;}
html body .peer-eval-dialog .peer-eval-target-copy p{font-size:12px;}
html body .peer-eval-dialog .peer-eval-scroll{padding:24px 28px;}
html body .peer-eval-dialog .scale-legend{gap:16px;margin-bottom:18px;padding:10px 14px;border-radius:8px;}
html body .peer-eval-dialog .compact-eval-table-wrap{margin:0 0 16px;}
html body .peer-eval-dialog .compact-eval-table td{padding:10px 6px;}
html body .peer-eval-dialog .compact-eval-table col.c-rate{width:52px;}
html body .peer-eval-dialog .compact-eval-qtext{font-size:12.5px;}
html body .peer-eval-dialog .compact-eval-rating label{width:34px;height:30px;border-radius:7px;font-size:12px;font-weight:800;}
html body .peer-eval-dialog .peer-eval-footer{padding:16px 28px 24px;}
@media(max-width:700px){
 html body .staff-eval-modal-head,html body .staff-eval-modal-foot,html body .peer-eval-dialog .peer-eval-dialog-header,html body .peer-eval-dialog .peer-eval-footer{padding-left:18px;padding-right:18px;}
 html body .staff-eval-modal-body,html body .peer-eval-dialog .peer-eval-scroll{padding:18px;}
 html body .staff-eval-table col.c-rate,html body .peer-eval-dialog .compact-eval-table col.c-rate{width:40px;}
}
</style>
</body>
        </html>