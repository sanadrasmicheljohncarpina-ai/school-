<?php
// admin/system_archive.php
// Secure evaluation-year archive. Super Admin has full access; Admin requires
// the system_archive edit permission. Questions, users, assignments and
// system settings are intentionally preserved. Evaluation records are snapshotted
// into system_archives and removed from the live evaluation tables so the next
// evaluation cycle starts clean.
session_start();
require_once 'db.php';
require_once 'permissions.php';

if (empty($_SESSION['user_id'])) {
    header('Location: admin_login.php');
    exit;
}

mysqli_report(MYSQLI_REPORT_OFF);

// Embedded mode: this page is shown inside System & Period > Schedule history.
// Only the archived schedules are shown; the archive picker appears only when the
// "Create new evaluation schedule" step sends the admin here to archive the finished round.
$embed = isset($_GET['embed']);
$embedView = (($_GET['embed'] ?? '') === 'previous') ? 'previous' : 'history';   // previous = not yet archived, history = archived
$showPicker  = !$embed || $embedView === 'previous';
$showHistory = !$embed || $embedView === 'history';

// ── CSRF protection ────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') . '">';
}
function csrf_valid(): bool {
    $sent = $_POST['csrf_token'] ?? '';
    return is_string($sent) && $sent !== '' && hash_equals($_SESSION['csrf_token'], $sent);
}

// Run a statement and throw if it fails, so a transaction can never commit half-done.
function exec_or_throw(mysqli $db, string $sql): void {
    if ($db->query($sql) === false) {
        throw new Exception('Database error: ' . $db->error);
    }
}

// Days a deleted archive stays in "Recently Deleted" before it is permanently erased.
const ARCHIVE_TRASH_DAYS = 30;

// Create the archive store automatically for existing installations (once per session).
if (empty($_SESSION['archive_schema_v1'])) {
$mysqli->query("CREATE TABLE IF NOT EXISTS system_archives (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    period_id INT UNSIGNED NOT NULL,
    period_label VARCHAR(150) NOT NULL,
    school_year VARCHAR(30) NOT NULL,
    archived_by INT UNSIGNED DEFAULT NULL,
    archived_by_name VARCHAR(150) DEFAULT NULL,
    archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    restored_at DATETIME DEFAULT NULL,
    restored_by INT UNSIGNED DEFAULT NULL,
    status ENUM('archived','restored') NOT NULL DEFAULT 'archived',
    record_count INT UNSIGNED NOT NULL DEFAULT 0,
    summary_json LONGTEXT DEFAULT NULL,
    payload_json LONGTEXT NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_system_archive_period (period_id),
    KEY idx_system_archive_year (school_year),
    KEY idx_system_archive_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Permanent record of deleted archives (metadata only; the archived payload itself is not kept).
$mysqli->query("CREATE TABLE IF NOT EXISTS system_archive_deletions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    archive_id INT UNSIGNED NOT NULL,
    period_id INT UNSIGNED DEFAULT NULL,
    period_label VARCHAR(150) NOT NULL,
    school_year VARCHAR(30) NOT NULL,
    status_at_delete VARCHAR(20) NOT NULL,
    record_count INT UNSIGNED NOT NULL DEFAULT 0,
    archived_at DATETIME DEFAULT NULL,
    archived_by_name VARCHAR(150) DEFAULT NULL,
    deleted_by INT UNSIGNED DEFAULT NULL,
    deleted_by_name VARCHAR(150) DEFAULT NULL,
    deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    archived_by INT UNSIGNED DEFAULT NULL,
    restored_at DATETIME DEFAULT NULL,
    restored_by INT UNSIGNED DEFAULT NULL,
    summary_json LONGTEXT DEFAULT NULL,
    payload_json LONGTEXT DEFAULT NULL,
    recovered_at DATETIME DEFAULT NULL,
    recovered_by_name VARCHAR(150) DEFAULT NULL,
    purged_at DATETIME DEFAULT NULL,
    purge_reason VARCHAR(20) DEFAULT NULL,
    purged_by_name VARCHAR(150) DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
// Upgrade tables created by the earlier (metadata-only) version.
$delCols = [];
if ($rc = $mysqli->query("SHOW COLUMNS FROM system_archive_deletions")) { while ($c = $rc->fetch_assoc()) $delCols[$c['Field']] = true; }
foreach ([
    'archived_by' => 'INT UNSIGNED DEFAULT NULL',
    'restored_at' => 'DATETIME DEFAULT NULL',
    'restored_by' => 'INT UNSIGNED DEFAULT NULL',
    'summary_json' => 'LONGTEXT DEFAULT NULL',
    'payload_json' => 'LONGTEXT DEFAULT NULL',
    'recovered_at' => 'DATETIME DEFAULT NULL',
    'recovered_by_name' => 'VARCHAR(150) DEFAULT NULL',
    'purged_at' => 'DATETIME DEFAULT NULL',
    'purge_reason' => 'VARCHAR(20) DEFAULT NULL',
    'purged_by_name' => 'VARCHAR(150) DEFAULT NULL',
] as $colName => $colDef) {
    if (!isset($delCols[$colName])) $mysqli->query("ALTER TABLE system_archive_deletions ADD COLUMN `$colName` $colDef");
}
$_SESSION['archive_schema_v1'] = true;
} // end one-time schema setup

// Dean + Principal "Feedback's Received" keep-copy.
// The archive clears live evaluation rows, but the Dean and the Principal must still be able to
// read the anonymous feedback they received. At archive time we store an identity-free copy here
// (no evaluator id/name/photo/department — same confidentiality rule as dean_results.php and
// principal_results.php).
// Rows are tied to the archive: they are removed only when that archive is restored back to
// the live tables (the live rows then serve the Dean/Principal pages again). Deleting or purging an
// archive does NOT remove them.
// Uses its own session flag so sessions that already ran the v1 setup still create it.
if (empty($_SESSION['archive_schema_keep_v1'])) {
    $keepOk = $mysqli->query("CREATE TABLE IF NOT EXISTS feedback_received_keep (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        archive_id INT UNSIGNED NOT NULL,
        tracker_id INT UNSIGNED NOT NULL,
        target_user_id INT UNSIGNED NOT NULL,
        period_id INT UNSIGNED NOT NULL,
        school_year VARCHAR(30) DEFAULT NULL,
        semester VARCHAR(50) DEFAULT NULL,
        period_label VARCHAR(150) DEFAULT NULL,
        remarks TEXT DEFAULT NULL,
        submitted_at DATETIME DEFAULT NULL,
        score_sum DOUBLE NOT NULL DEFAULT 0,
        score_count INT UNSIGNED NOT NULL DEFAULT 0,
        answers_json LONGTEXT DEFAULT NULL,
        kept_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_keep_archive_tracker (archive_id, tracker_id),
        KEY idx_keep_target_period (target_user_id, period_id),
        KEY idx_keep_archive (archive_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if ($keepOk) $_SESSION['archive_schema_keep_v1'] = true;
}

/**
 * Copy the Dean's AND the Principal's received feedback for one period into
 * feedback_received_keep. Must run BEFORE the live rows are deleted. Throws on failure so
 * the surrounding transaction rolls back and nothing is lost.
 *
 * Which trackers count as "received feedback" mirrors what each page shows live:
 *   Dean      -> student/school_head OR ea
 *   Principal -> faculty_peer (Faculty/Staff -> Principal) OR student/school_head OR school_head
 * The rows are identity-free: no evaluator id/name/photo/department is ever copied.
 */
function keep_received_feedback(mysqli $db, int $archiveId, array $period): int {
    $periodId = (int)$period['id'];
    // One delete for the whole archive (Dean + Principal rows), then re-insert both.
    exec_or_throw($db, "DELETE FROM feedback_received_keep WHERE archive_id=" . $archiveId);

    $hasSecondary = false;
    if ($rc = $db->query("SHOW COLUMNS FROM users LIKE 'secondary_role'")) { $hasSecondary = $rc->num_rows > 0; }
    $deanCond      = $hasSecondary ? "(u.role='dean' OR u.secondary_role='dean')"           : "u.role='dean'";
    $principalCond = $hasSecondary ? "(u.role='principal' OR u.secondary_role='principal')" : "u.role='principal'";

    $res = $db->query("SELECT et.id, et.target_user_id, et.remarks, et.submitted_at
        FROM evaluation_tracker et
        INNER JOIN users u ON u.id = et.target_user_id
        WHERE et.period_id=$periodId
          AND et.status IN ('submitted','approved')
          AND (
                ($deanCond AND ((et.eval_type='student' AND et.evaluation_context='school_head') OR et.eval_type='ea'))
             OR ($principalCond AND (et.eval_type='faculty_peer'
                                     OR (et.eval_type='student' AND et.evaluation_context='school_head')
                                     OR et.eval_type='school_head'))
          )");
    if ($res === false) throw new Exception('Could not read received feedback to keep: ' . $db->error);
    $trackers = [];
    while ($r = $res->fetch_assoc()) $trackers[(int)$r['id']] = $r;
    if (!$trackers) return 0;

    // Same joins get_my_evaluation_details.php uses, so the details popup renders identically.
    $in = implode(',', array_keys($trackers));
    $ares = $db->query("SELECT qa.tracker_id, qa.answer_score,
            COALESCE(uq.question_text, eq.question_text) AS question_text,
            COALESCE(uq.category, eq.category, 'General') AS category
        FROM questionnaire_answers qa
        LEFT JOIN user_questions uq ON qa.question_source='user' AND uq.id=qa.user_question_id
        LEFT JOIN evaluation_questions eq ON qa.question_source='evaluation' AND eq.id=qa.question_id
        WHERE qa.tracker_id IN ($in)
        ORDER BY category ASC, COALESCE(eq.id, 0) ASC, qa.id ASC");
    if ($ares === false) throw new Exception('Could not read answers to keep: ' . $db->error);
    $answers = [];
    while ($a = $ares->fetch_assoc()) {
        $answers[(int)$a['tracker_id']][] = [
            'category'      => $a['category'] ?: 'General',
            'question_text' => $a['question_text'] ?: '(This question is no longer available)',
            'score'         => $a['answer_score'] !== null ? (float)$a['answer_score'] : 0.0,
        ];
    }

    $ins = $db->prepare("INSERT INTO feedback_received_keep
        (archive_id, tracker_id, target_user_id, period_id, school_year, semester, period_label, remarks, submitted_at, score_sum, score_count, answers_json)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    if (!$ins) throw new Exception('Could not prepare feedback keep: ' . $db->error);

    $kept = 0;
    foreach ($trackers as $tid => $t) {
        $list  = $answers[$tid] ?? [];
        $sum   = 0.0;
        foreach ($list as $a) $sum += $a['score'];
        $count = count($list);
        $json  = json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $uid   = (int)$t['target_user_id'];
        $sy    = $period['school_year'] ?? null;
        $sem   = $period['semester'] ?? null;
        $pl    = $period['period_label'] ?? null;
        $rem   = $t['remarks'];
        $sub   = $t['submitted_at'];
        $ins->bind_param('iiiisssssdis', $archiveId, $tid, $uid, $periodId, $sy, $sem, $pl, $rem, $sub, $sum, $count, $json);
        if (!$ins->execute()) { $err = $ins->error; $ins->close(); throw new Exception('Could not keep received feedback: ' . $err); }
        $kept++;
    }
    $ins->close();
    return $kept;
}
// Backwards-compatible name for any older caller.
function keep_dean_feedback(mysqli $db, int $archiveId, array $period): int {
    return keep_received_feedback($db, $archiveId, $period);
}

function archive_allowed(mysqli $db): bool {
    $role = $_SESSION['role'] ?? '';
    if ($role === 'superadmin') return true;
    return $role === 'admin' && admin_can_edit($db, 'system_archive');
}

$allowed = archive_allowed($mysqli);
if (!$allowed) {
    http_response_code(403);
}

// Permanently erase deleted archives that have sat in Recently Deleted past the retention window.
// The history row stays as a small audit record; only the archived data is removed.
if ($allowed) {
    $mysqli->query("UPDATE system_archive_deletions SET payload_json=NULL, summary_json=NULL, purged_at=NOW(), purge_reason='expired'
        WHERE recovered_at IS NULL AND purged_at IS NULL AND payload_json IS NOT NULL
        AND deleted_at < (NOW() - INTERVAL " . (int)ARCHIVE_TRASH_DAYS . " DAY)");
    $autoPurged = $mysqli->affected_rows;
    if ($autoPurged > 0) {
        $txt = $mysqli->real_escape_string("Automatically erased $autoPurged deleted archive" . ($autoPurged === 1 ? '' : 's') . " from Recently Deleted (older than " . (int)ARCHIVE_TRASH_DAYS . " days)");
        $mysqli->query("INSERT INTO activity_log (actor_name, actor_type, action_text, icon, color) VALUES ('System','admin','$txt','fa-trash-can','#D6455D')");
    }
}

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
// "Aug 12 – Dec 18, 2026" (or with both years when the range crosses a year). '' when no dates are set.
function eval_date_range($start, $end): string {
    $ts = $start ? strtotime((string)$start) : false;
    $te = $end ? strtotime((string)$end) : false;
    if (!$ts && !$te) return '';
    if ($ts && !$te) return date('M j, Y', $ts);
    if (!$ts && $te) return date('M j, Y', $te);
    if (date('Y-m-d', $ts) === date('Y-m-d', $te)) return date('M j, Y', $ts);
    return date('Y', $ts) === date('Y', $te)
        ? date('M j', $ts) . ' – ' . date('M j, Y', $te)
        : date('M j, Y', $ts) . ' – ' . date('M j, Y', $te);
}
function json_rows(mysqli_result|false $res): array {
    $rows = [];
    if ($res) while ($row = $res->fetch_assoc()) $rows[] = $row;
    return $rows;
}
function rows_by_ids(mysqli $db, string $table, string $key, array $ids): array {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) return [];
    $in = implode(',', $ids);
    return json_rows($db->query("SELECT * FROM `$table` WHERE `$key` IN ($in)"));
}
function tracker_ids(array $rows): array {
    return array_values(array_unique(array_map(fn($r) => (int)$r['id'], $rows)));
}
function count_rows(array $data): int { return count($data); }

$flash = null;
$flashType = 'ok';
$viewArchive = null;

// Message left by the previous action (see Post/Redirect/Get below).
if (!empty($_SESSION['sa_flash']) && is_array($_SESSION['sa_flash'])) {
    $flash = (string)($_SESSION['sa_flash']['msg'] ?? '');
    $flashType = (($_SESSION['sa_flash']['type'] ?? 'ok') === 'err') ? 'err' : 'ok';
    if ($flash === '') $flash = null;
}
unset($_SESSION['sa_flash']);

// PHP keeps the session file LOCKED until the request ends. Archiving/deleting can run for a
// while, and every other page of the dashboard (status poll, notifications, other tabs) shares
// this session, so they all froze until it finished. Everything we still need from $_SESSION
// below is only READ, which keeps working after the lock is released.
session_write_close();

// ── POST: ARCHIVE / RESTORE ────────────────────────────────────────────────
$isSuperAdmin = (($_SESSION['role'] ?? '') === 'superadmin');
if ($allowed && $_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_valid()) {
    http_response_code(403);
    $flash = 'Your session security token was missing or expired. Please reload the page and try again.';
    $flashType = 'err';
    $_POST = [];
}
if ($allowed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    // Large archives can take a while; do not let PHP cut the request off half way.
    @set_time_limit(300);
    @ignore_user_abort(true);

    // Destructive actions must come through the confirmation dialog (it adds confirmed=1).
    // This blocks any form that was submitted without the warning being shown and accepted.
    if (in_array($action, ['archive', 'delete', 'purge', 'empty_trash'], true) && ($_POST['confirmed'] ?? '') !== '1') {
        $flash = 'The action was not confirmed, so nothing was changed. Please try again and confirm the warning.';
        $flashType = 'err';
        $action = '';
    }

    if ($action === 'archive') {
        $periodIds = [];
        if (isset($_POST['period_ids']) && is_array($_POST['period_ids'])) {
            foreach ($_POST['period_ids'] as $v) { $v = (int)$v; if ($v > 0) $periodIds[$v] = $v; }
        } elseif (!empty($_POST['period_id'])) {
            $periodIds[(int)$_POST['period_id']] = (int)$_POST['period_id'];
        }
        $periodIds = array_values($periodIds);
        $archivedOk = [];
        $archiveErrs = [];

        foreach ($periodIds as $periodId) {
        $stmt = $mysqli->prepare("SELECT * FROM evaluation_periods WHERE id=? LIMIT 1");
        $stmt->bind_param('i', $periodId);
        $stmt->execute();
        $period = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$period) {
            $archiveErrs[] = "Period #$periodId could not be found.";
        } else {
            $periodName = $period['school_year'] . ' — ' . $period['period_label'];
            $stmt = $mysqli->prepare("SELECT id, status FROM system_archives WHERE period_id=? LIMIT 1");
            $stmt->bind_param('i', $periodId);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($existing && $existing['status'] === 'archived') {
                $archiveErrs[] = "$periodName already has an archive record.";
            } else {
                // The modern evaluation_tracker table is the source of truth.
                $trackers = json_rows($mysqli->query("SELECT * FROM evaluation_tracker WHERE period_id=" . $periodId));
                $trackerIds = tracker_ids($trackers);

                $trackerIn = $trackerIds ? implode(',', $trackerIds) : '0';
                $submissions = json_rows($mysqli->query("SELECT * FROM evaluation_submissions WHERE period_id=" . $periodId));
                $results = json_rows($mysqli->query("SELECT * FROM evaluation_results WHERE period_id=" . $periodId . ($trackerIds ? " OR tracker_id IN ($trackerIn)" : '')));
                $peerSubmissions = json_rows($mysqli->query("SELECT * FROM peer_evaluation_submissions WHERE period_id=" . $periodId));
                $peerIds = array_map(fn($r)=>(int)$r['id'], $peerSubmissions);
                $peerIn = $peerIds ? implode(',', $peerIds) : '0';
                $peerResults = $peerIds ? json_rows($mysqli->query("SELECT * FROM peer_evaluation_results WHERE submission_id IN ($peerIn) OR period_id=" . $periodId)) : json_rows($mysqli->query("SELECT * FROM peer_evaluation_results WHERE period_id=" . $periodId));
                $questionnaireAnswers = $trackerIds ? rows_by_ids($mysqli, 'questionnaire_answers', 'tracker_id', $trackerIds) : [];
                $evaluationAnswers = $trackerIds ? rows_by_ids($mysqli, 'evaluation_answers', 'tracker_id', $trackerIds) : [];
                $reminders = json_rows($mysqli->query("SELECT * FROM evaluation_reminders WHERE period_id=" . $periodId));
                $reports = json_rows($mysqli->query("SELECT * FROM analytics_reports WHERE period=" . "'" . $mysqli->real_escape_string($period['school_year']) . "'"));

                // Notifications created for this evaluation period are archived and cleared.
                // Exact period_id match only (so period 5 never matches 50/51, and unrelated
                // notifications that merely mention the school year are left alone).
                $notifWhere = "extra_data REGEXP '\"period_id\"[[:space:]]*:[[:space:]]*\"?" . (int)$periodId . "\"?[[:space:]]*[,}]'";
                $notifRes = $mysqli->query("SELECT * FROM notifications WHERE $notifWhere");
                $snapshotReadError = ($notifRes === false) ? $mysqli->error : null;
                $notifications = json_rows($notifRes);

                // Activity logs stay live for auditability; we snapshot only the evaluation-window logs.
                $activity = [];
                if (!empty($period['date_start']) && !empty($period['date_end'])) {
                    $start = $mysqli->real_escape_string($period['date_start'] . ' 00:00:00');
                    $end = $mysqli->real_escape_string($period['date_end'] . ' 23:59:59');
                    $activity = json_rows($mysqli->query("SELECT * FROM activity_log WHERE created_at BETWEEN '$start' AND '$end' AND (action_text LIKE '%evaluat%' OR action_text LIKE '%submission%' OR action_text LIKE '%questionnaire%') ORDER BY created_at"));
                }

                $snapshot = [
                    'evaluation_tracker' => $trackers,
                    'questionnaire_answers' => $questionnaireAnswers,
                    'evaluation_answers' => $evaluationAnswers,
                    'evaluation_submissions' => $submissions,
                    'evaluation_results' => $results,
                    'peer_evaluation_submissions' => $peerSubmissions,
                    'peer_evaluation_results' => $peerResults,
                    'evaluation_reminders' => $reminders,
                    'analytics_reports' => $reports,
                    'notifications' => $notifications,
                    'activity_log_snapshot' => $activity,
                ];
                $counts = [];
                $total = 0;
                foreach ($snapshot as $table => $rows) {
                    $counts[$table] = count_rows($rows);
                    if ($table !== 'activity_log_snapshot') $total += count_rows($rows);
                }

                // Remember the period flags so a restore can put tracking back as it was.
                $snapshot['period_state'] = [
                    'is_active' => (int)($period['is_active'] ?? 0),
                    'tracking_enabled' => (int)($period['tracking_enabled'] ?? 0),
                ];

                // Encode defensively: invalid UTF-8 is substituted and any failure aborts BEFORE
                // anything is deleted (a false payload used to be stored as '' and the data wiped).
                $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;
                try {
                    $payload = json_encode($snapshot, $jsonFlags);
                    $summary = json_encode($counts, $jsonFlags);
                } catch (Throwable $jex) {
                    $archiveErrs[] = "$periodName was NOT archived (nothing was changed): could not encode the snapshot (" . $jex->getMessage() . ").";
                    continue;
                }
                if (!is_string($payload) || $payload === '' || $payload === '[]' || !is_string($summary)) {
                    $archiveErrs[] = "$periodName was NOT archived (nothing was changed): the snapshot was empty.";
                    continue;
                }
                if ($snapshotReadError !== null) {
                    $archiveErrs[] = "$periodName was NOT archived (nothing was changed): could not read notifications ($snapshotReadError).";
                    continue;
                }
                $payloadLen = function_exists('mb_strlen') ? mb_strlen($payload, 'UTF-8') : null;
                $byId = (int)$_SESSION['user_id'];
                $byName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Administrator';

                try {
                    $mysqli->begin_transaction();

                    // Lock the period row so two admins cannot archive the same period at once,
                    // then re-check state and that the live data still matches the snapshot.
                    $lock = $mysqli->prepare("SELECT id FROM evaluation_periods WHERE id=? FOR UPDATE");
                    if (!$lock) throw new Exception('Could not lock the period: ' . $mysqli->error);
                    $lock->bind_param('i', $periodId);
                    if (!$lock->execute()) throw new Exception('Could not lock the period: ' . $lock->error);
                    $lock->close();

                    $recheck = $mysqli->query("SELECT id, status FROM system_archives WHERE period_id=" . (int)$periodId . " LIMIT 1 FOR UPDATE");
                    if ($recheck === false) throw new Exception('Could not re-check archive state: ' . $mysqli->error);
                    $nowExisting = $recheck->fetch_assoc();
                    if ($nowExisting && $nowExisting['status'] === 'archived') throw new Exception('this period was just archived by someone else.');
                    if ($nowExisting && !$existing) throw new Exception('an archive record for this period appeared while archiving; please retry.');

                    $liveNow = $mysqli->query("SELECT COUNT(*) c FROM evaluation_tracker WHERE period_id=" . (int)$periodId);
                    if ($liveNow === false || (int)$liveNow->fetch_assoc()['c'] !== count($trackers)) {
                        throw new Exception('evaluation data changed while the snapshot was being taken; please retry.');
                    }

                    if ($existing) {
                        // Previously restored: refresh the existing record instead of inserting a
                        // new one, since period_id is UNIQUE in system_archives.
                        $upd = $mysqli->prepare("UPDATE system_archives SET period_label=?, school_year=?, archived_by=?, archived_by_name=?, archived_at=NOW(), restored_at=NULL, restored_by=NULL, status='archived', record_count=?, summary_json=?, payload_json=? WHERE id=?");
                        $upd->bind_param('ssisissi', $period['period_label'], $period['school_year'], $byId, $byName, $total, $summary, $payload, $existing['id']);
                        if (!$upd->execute()) throw new Exception('Could not update archive record: ' . $upd->error);
                        $archiveId = (int)$existing['id'];
                        $upd->close();
                    } else {
                        $ins = $mysqli->prepare("INSERT INTO system_archives (period_id, period_label, school_year, archived_by, archived_by_name, record_count, summary_json, payload_json) VALUES (?,?,?,?,?,?,?,?)");
                        $ins->bind_param('issisiss', $periodId, $period['period_label'], $period['school_year'], $byId, $byName, $total, $summary, $payload);
                        if (!$ins->execute()) throw new Exception('Could not create archive record: ' . $ins->error);
                        $archiveId = $ins->insert_id;
                        $ins->close();
                    }

                    // Read the archive back and confirm it was stored intact BEFORE deleting anything.
                    $chk = $mysqli->prepare("SELECT CHAR_LENGTH(payload_json) AS len, record_count FROM system_archives WHERE id=?");
                    if (!$chk) throw new Exception('Could not verify the archive: ' . $mysqli->error);
                    $chk->bind_param('i', $archiveId);
                    if (!$chk->execute()) throw new Exception('Could not verify the archive: ' . $chk->error);
                    $stored = $chk->get_result()->fetch_assoc();
                    $chk->close();
                    if (!$stored || (int)$stored['len'] < 2 || ($payloadLen !== null && (int)$stored['len'] !== $payloadLen) || (int)$stored['record_count'] !== (int)$total) {
                        throw new Exception('the stored archive did not match the snapshot, so nothing was deleted.');
                    }

                    // Keep the Dean's and Principal's anonymous received feedback readable after the live rows are cleared.
                    keep_received_feedback($mysqli, (int)$archiveId, $period);

                    // Delete dependent rows first. Questions, users, assignments, system settings
                    // and system logs remain intact. Every statement must succeed or we roll back.
                    $schoolYearSql = $mysqli->real_escape_string((string)$period['school_year']);
                    exec_or_throw($mysqli, "DELETE FROM questionnaire_answers WHERE tracker_id IN ($trackerIn)");
                    exec_or_throw($mysqli, "DELETE FROM evaluation_answers WHERE tracker_id IN ($trackerIn)");
                    exec_or_throw($mysqli, "DELETE FROM evaluation_results WHERE period_id=$periodId" . ($trackerIds ? " OR tracker_id IN ($trackerIn)" : ''));
                    exec_or_throw($mysqli, "DELETE FROM evaluation_tracker WHERE period_id=$periodId");
                    exec_or_throw($mysqli, "DELETE FROM evaluation_submissions WHERE period_id=$periodId");
                    exec_or_throw($mysqli, "DELETE FROM peer_evaluation_results WHERE period_id=$periodId" . ($peerIds ? " OR submission_id IN ($peerIn)" : ''));
                    exec_or_throw($mysqli, "DELETE FROM peer_evaluation_submissions WHERE period_id=$periodId");
                    exec_or_throw($mysqli, "DELETE FROM evaluation_reminders WHERE period_id=$periodId");
                    exec_or_throw($mysqli, "DELETE FROM analytics_reports WHERE period='$schoolYearSql'");
                    if ($notifications) exec_or_throw($mysqli, "DELETE FROM notifications WHERE $notifWhere");
                    exec_or_throw($mysqli, "UPDATE evaluation_periods SET is_active=0, tracking_enabled=0 WHERE id=$periodId");

                    $actor = $mysqli->real_escape_string((string)$byName);
                    $actionText = $mysqli->real_escape_string("Archived evaluation data for {$period['school_year']} (Archive #$archiveId)");
                    exec_or_throw($mysqli, "INSERT INTO activity_log (actor_name, actor_type, action_text, icon, color) VALUES ('$actor','admin','$actionText','fa-box-archive','#2563EB')");

                    $mysqli->commit();

                    $archivedOk[] = $periodName;
                } catch (Throwable $ex) {
                    $mysqli->rollback();
                    $archiveErrs[] = "$periodName was NOT archived and live data was left untouched: " . $ex->getMessage();
                }
            }
        }
        } // end foreach $periodIds

        $okCount = count($archivedOk);
        if (!$periodIds) {
            $flash = 'No evaluation periods were selected.';
            $flashType = 'err';
        } else {
            $flash = '';
            if ($okCount === 1) {
                $flash = "Archive successful. {$archivedOk[0]} is now a fresh evaluation cycle with live submissions cleared.";
            } elseif ($okCount > 1) {
                $flash = "Archive successful. $okCount periods archived (" . implode('; ', $archivedOk) . ") with live submissions cleared.";
            }
            if ($archiveErrs) $flash = trim($flash . ' Not archived: ' . implode(' ', $archiveErrs));
            $flashType = $okCount > 0 ? 'ok' : 'err';
        }
    }

    if ($action === 'restore' || $action === 'restore_selected') {
        $requestedIds = [];

        if ($action === 'restore') {
            $oneId = (int)($_POST['archive_id'] ?? 0);
            if ($oneId > 0) $requestedIds[] = $oneId;
        } else {
            $requestedIds = array_values(array_unique(array_filter(
                array_map('intval', (array)($_POST['archive_ids'] ?? [])),
                fn($id) => $id > 0
            )));
        }

        if (!$requestedIds) {
            $flash = 'No archived records were selected for restore.';
            $flashType = 'err';
        } else {
            $restoreErrors = [];
            $restored = 0;

            try {
                $mysqli->begin_transaction();

                foreach ($requestedIds as $archiveId) {
                    $stmt = $mysqli->prepare("SELECT * FROM system_archives WHERE id=? AND status='archived' LIMIT 1");
                    if (!$stmt) {
                        throw new Exception('Could not prepare archive lookup: ' . $mysqli->error);
                    }
                    $stmt->bind_param('i', $archiveId);
                    $stmt->execute();
                    $archive = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if (!$archive) {
                        $restoreErrors[] = "Archive #$archiveId could not be found or has already been restored.";
                        continue;
                    }

                    $payload = json_decode($archive['payload_json'], true);
                    if (!is_array($payload) || !array_key_exists('evaluation_tracker', $payload)) {
                        $restoreErrors[] = "Archive #$archiveId has an invalid archive payload.";
                        continue;
                    }

                    $live = $mysqli->query(
                        "SELECT COUNT(*) c FROM evaluation_tracker WHERE period_id=" . (int)$archive['period_id']
                    );
                    $liveCount = $live ? (int)$live->fetch_assoc()['c'] : 0;

                    if ($liveCount > 0) {
                        $restoreErrors[] = "Archive #$archiveId was skipped because {$archive['school_year']} already contains live evaluation records.";
                        continue;
                    }

                    $order = [
                        'evaluation_tracker',
                        'questionnaire_answers',
                        'evaluation_answers',
                        'evaluation_submissions',
                        'evaluation_results',
                        'peer_evaluation_submissions',
                        'peer_evaluation_results',
                        'evaluation_reminders',
                        'analytics_reports',
                        'notifications',
                    ];

                    foreach ($order as $table) {
                        $rows = $payload[$table] ?? [];
                        foreach ($rows as $row) {
                            if (!$row) continue;

                            $columns = array_keys($row);
                            $quotedCols = '`' . implode('`,`', array_map(
                                fn($c) => str_replace('`', '``', $c),
                                $columns
                            )) . '`';
                            $placeholders = implode(',', array_fill(0, count($columns), '?'));
                            $sql = "INSERT INTO `$table` ($quotedCols) VALUES ($placeholders)";

                            $stmt = $mysqli->prepare($sql);
                            if (!$stmt) {
                                throw new Exception("Could not prepare restore for table $table: " . $mysqli->error);
                            }

                            $types = '';
                            $values = [];
                            foreach ($columns as $c) {
                                $v = $row[$c];
                                $types .= is_int($v) ? 'i' : (is_float($v) ? 'd' : 's');
                                $values[] = $v;
                            }

                            $bind = [$types];
                            foreach ($values as $k => $v) $bind[] = &$values[$k];
                            call_user_func_array([$stmt, 'bind_param'], $bind);

                            if (!$stmt->execute()) {
                                $err = $stmt->error;
                                $stmt->close();
                                throw new Exception("Restore failed for archive #$archiveId in $table: $err");
                            }
                            if ($stmt->affected_rows !== 1) {
                                $stmt->close();
                                throw new Exception("Restore failed for archive #$archiveId in $table: a row was not inserted.");
                            }
                            $stmt->close();
                        }
                    }

                    // Make the period visible only after this archive has been restored.
                    $ps = is_array($payload['period_state'] ?? null) ? $payload['period_state'] : null;
                    $trk = $ps !== null ? (int)($ps['tracking_enabled'] ?? 0) : null;
                    $pid = (int)$archive['period_id'];
                    if ($trk === null) {
                        $stmt = $mysqli->prepare("UPDATE evaluation_periods SET is_active=1 WHERE id=?");
                        if (!$stmt) throw new Exception('Could not prepare period update: ' . $mysqli->error);
                        $stmt->bind_param('i', $pid);
                    } else {
                        $stmt = $mysqli->prepare("UPDATE evaluation_periods SET is_active=1, tracking_enabled=? WHERE id=?");
                        if (!$stmt) throw new Exception('Could not prepare period update: ' . $mysqli->error);
                        $stmt->bind_param('ii', $trk, $pid);
                    }
                    if (!$stmt->execute()) {
                        $err = $stmt->error;
                        $stmt->close();
                        throw new Exception("Could not reactivate evaluation period for archive #$archiveId: $err");
                    }
                    $stmt->close();

                    $byId = (int)$_SESSION['user_id'];
                    $stmt = $mysqli->prepare(
                        "UPDATE system_archives SET status='restored', restored_at=NOW(), restored_by=? WHERE id=?"
                    );
                    $stmt->bind_param('ii', $byId, $archiveId);
                    if (!$stmt->execute()) {
                        $err = $stmt->error;
                        $stmt->close();
                        throw new Exception("Could not mark archive #$archiveId as restored: $err");
                    }
                    $stmt->close();

                    // Live rows are back, so the Dean and Principal pages read them directly again.
                    exec_or_throw($mysqli, "DELETE FROM feedback_received_keep WHERE archive_id=" . (int)$archiveId);

                    $actor = $mysqli->real_escape_string(
                        $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Administrator'
                    );
                    $actionText = $mysqli->real_escape_string(
                        "Restored evaluation archive #$archiveId for {$archive['school_year']}"
                    );
                    if (!$mysqli->query(
                        "INSERT INTO activity_log (actor_name, actor_type, action_text, icon, color)
                         VALUES ('$actor','admin','$actionText','fa-box-open','#0F9F6E')"
                    )) {
                        throw new Exception('Could not write the restore audit log: ' . $mysqli->error);
                    }

                    $restored++;
                }

                // A skipped archive is not a database failure. Commit successful restores
                // and report any items that were not eligible.
                $mysqli->commit();

                if ($action === 'restore') {
                    if ($restored === 1 && !$restoreErrors) {
                        $flash = 'The selected archive was restored successfully.';
                        $flashType = 'ok';
                    } elseif ($restored === 0) {
                        $flash = $restoreErrors[0] ?? 'The selected archive could not be restored.';
                        $flashType = 'err';
                    } else {
                        $flash = 'The archive was restored with warnings: ' . implode(' ', $restoreErrors);
                        $flashType = 'ok';
                    }
                } else {
                    $summaryParts = [];
                    if ($restored > 0) {
                        $summaryParts[] = $restored . ' archive' . ($restored === 1 ? '' : 's') . ' restored successfully.';
                    }
                    if ($restoreErrors) {
                        $summaryParts[] = count($restoreErrors) . ' selection' . (count($restoreErrors) === 1 ? '' : 's') . ' skipped.';
                    }
                    $flash = $summaryParts
                        ? implode(' ', $summaryParts) . ($restoreErrors ? ' ' . implode(' ', $restoreErrors) : '')
                        : 'No selected archives were restored.';
                    $flashType = $restored > 0 ? 'ok' : 'err';
                }
            } catch (Throwable $ex) {
                $mysqli->rollback();
                $flash = 'Restore failed: ' . $ex->getMessage();
                $flashType = 'err';
            }
        }
    }

    if ($action === 'recover') {
        $histId = (int)($_POST['history_id'] ?? 0);
        $stmt = $mysqli->prepare("SELECT * FROM system_archive_deletions WHERE id=? LIMIT 1");
        $stmt->bind_param('i', $histId);
        $stmt->execute();
        $h = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$h) {
            $flash = 'That delete-history entry could not be found.';
            $flashType = 'err';
        } elseif (!empty($h['recovered_at'])) {
            $flash = 'This archive has already been restored from Recently Deleted.';
            $flashType = 'err';
        } elseif (!empty($h['purged_at'])) {
            $flash = 'This archive was permanently erased and can no longer be restored.';
            $flashType = 'err';
        } elseif ($h['payload_json'] === null || $h['payload_json'] === '') {
            $flash = 'This archive cannot be restored because its data was not kept when it was deleted.';
            $flashType = 'err';
        } else {
            $hPid = (int)$h['period_id'];
            $stmt = $mysqli->prepare("SELECT id FROM system_archives WHERE period_id=? LIMIT 1");
            $stmt->bind_param('i', $hPid);
            $stmt->execute();
            $conflict = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($conflict) {
                $flash = "{$h['school_year']} ({$h['period_label']}) already has a newer archive record, so the deleted one cannot be restored.";
                $flashType = 'err';
            } else {
                try {
                    $mysqli->begin_transaction();

                    // Keep the original archive number when it is still free.
                    $origId = (int)$h['archive_id'];
                    $stmt = $mysqli->prepare("SELECT 1 FROM system_archives WHERE id=? LIMIT 1");
                    $stmt->bind_param('i', $origId);
                    $stmt->execute();
                    $idTaken = (bool)$stmt->get_result()->fetch_row();
                    $stmt->close();
                    $newId = $idTaken ? null : $origId;

                    $ins = $mysqli->prepare("INSERT INTO system_archives (id, period_id, period_label, school_year, archived_by, archived_by_name, archived_at, restored_at, restored_by, status, record_count, summary_json, payload_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    if (!$ins) throw new Exception($mysqli->error);
                    $hStatus = ($h['status_at_delete'] === 'restored') ? 'restored' : 'archived';
                    $hCnt = (int)$h['record_count'];
                    $ins->bind_param('iississsisiss', $newId, $hPid, $h['period_label'], $h['school_year'], $h['archived_by'], $h['archived_by_name'], $h['archived_at'], $h['restored_at'], $h['restored_by'], $hStatus, $hCnt, $h['summary_json'], $h['payload_json']);
                    if (!$ins->execute()) throw new Exception('Could not restore the archive record: ' . $ins->error);
                    $newArchiveId = $ins->insert_id ?: $origId;
                    $ins->close();

                    $byName = (string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Administrator');
                    $mark = $mysqli->prepare("UPDATE system_archive_deletions SET recovered_at=NOW(), recovered_by_name=? WHERE id=?");
                    $mark->bind_param('si', $byName, $histId);
                    if (!$mark->execute()) throw new Exception('Could not update delete history: ' . $mark->error);
                    $mark->close();

                    $actor = $mysqli->real_escape_string($byName);
                    $actionText = $mysqli->real_escape_string("Restored deleted evaluation archive for {$h['school_year']} ({$h['period_label']}) as Archive #$newArchiveId");
                    $mysqli->query("INSERT INTO activity_log (actor_name, actor_type, action_text, icon, color) VALUES ('$actor','admin','$actionText','fa-rotate-left','#2563EB')");

                    $mysqli->commit();
                    $flash = "{$h['school_year']} ({$h['period_label']}) was restored to Archived Records as Archive #$newArchiveId.";
                    $flashType = 'ok';
                } catch (Throwable $ex) {
                    $mysqli->rollback();
                    $flash = 'Restore failed: ' . $ex->getMessage();
                    $flashType = 'err';
                }
            }
        }
    }

    if (($action === 'purge' || $action === 'empty_trash') && !$isSuperAdmin) {
        $flash = 'Only the Super Admin can permanently erase archives.';
        $flashType = 'err';
    } elseif ($action === 'purge' || $action === 'empty_trash') {
        $byName = (string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Administrator');
        $actor = $mysqli->real_escape_string($byName);
        if ($action === 'purge') {
            $histId = (int)($_POST['history_id'] ?? 0);
            $stmt = $mysqli->prepare("UPDATE system_archive_deletions SET payload_json=NULL, summary_json=NULL, purged_at=NOW(), purge_reason='manual', purged_by_name=? WHERE id=? AND recovered_at IS NULL AND purged_at IS NULL AND payload_json IS NOT NULL");
            $stmt->bind_param('si', $byName, $histId);
            $stmt->execute();
            $n = $stmt->affected_rows;
            $stmt->close();
            if ($n > 0) {
                $mysqli->query("INSERT INTO activity_log (actor_name, actor_type, action_text, icon, color) VALUES ('$actor','admin','" . $mysqli->real_escape_string("Permanently erased a deleted evaluation archive (Recently Deleted #$histId)") . "','fa-trash-can','#D6455D')");
                $flash = 'The archive was permanently deleted.';
                $flashType = 'ok';
            } else {
                $flash = 'That item is no longer in Recently Deleted.';
                $flashType = 'err';
            }
        } else {
            $stmt = $mysqli->prepare("UPDATE system_archive_deletions SET payload_json=NULL, summary_json=NULL, purged_at=NOW(), purge_reason='manual', purged_by_name=? WHERE recovered_at IS NULL AND purged_at IS NULL AND payload_json IS NOT NULL");
            $stmt->bind_param('s', $byName);
            $stmt->execute();
            $n = $stmt->affected_rows;
            $stmt->close();
            if ($n > 0) {
                $mysqli->query("INSERT INTO activity_log (actor_name, actor_type, action_text, icon, color) VALUES ('$actor','admin','" . $mysqli->real_escape_string("Emptied Recently Deleted: permanently erased $n archive" . ($n === 1 ? '' : 's')) . "','fa-trash-can','#D6455D')");
                $flash = "Recently Deleted was emptied ($n archive" . ($n === 1 ? '' : 's') . ' permanently deleted).';
                $flashType = 'ok';
            } else {
                $flash = 'Recently Deleted is already empty.';
                $flashType = 'err';
            }
        }
    }

    if ($action === 'delete') {
        $archiveId = (int)($_POST['archive_id'] ?? 0);
        $stmt = $mysqli->prepare("SELECT * FROM system_archives WHERE id=? LIMIT 1");
        $stmt->bind_param('i', $archiveId);
        $stmt->execute();
        $archive = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$archive) {
            $flash = 'The archive could not be found.';
            $flashType = 'err';
        } else {
            // Save the history copy FIRST. If it cannot be saved, the archive is NOT deleted,
            // so a deletion can never silently lose the archived data.
            $delById = (int)$_SESSION['user_id'];
            $delByName = (string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Administrator');
            try {
                $mysqli->begin_transaction();

                $hist = $mysqli->prepare("INSERT INTO system_archive_deletions (archive_id, period_id, period_label, school_year, status_at_delete, record_count, archived_at, archived_by, archived_by_name, restored_at, restored_by, summary_json, payload_json, deleted_by, deleted_by_name) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                if (!$hist) throw new Exception($mysqli->error);
                $hPid = (int)$archive['period_id']; $hCnt = (int)$archive['record_count'];
                $hist->bind_param('iisssisississis', $archiveId, $hPid, $archive['period_label'], $archive['school_year'], $archive['status'], $hCnt, $archive['archived_at'], $archive['archived_by'], $archive['archived_by_name'], $archive['restored_at'], $archive['restored_by'], $archive['summary_json'], $archive['payload_json'], $delById, $delByName);
                if (!$hist->execute()) throw new Exception($hist->error ?: $mysqli->error);
                $hist->close();

                $del = $mysqli->prepare("DELETE FROM system_archives WHERE id=?");
                $del->bind_param('i', $archiveId);
                if (!$del->execute()) throw new Exception($del->error);
                $del->close();

                $mysqli->commit();

                $actor = $mysqli->real_escape_string($delByName);
                $actionText = $mysqli->real_escape_string("Deleted evaluation archive #$archiveId for {$archive['school_year']} ({$archive['period_label']})");
                $mysqli->query("INSERT INTO activity_log (actor_name, actor_type, action_text, icon, color) VALUES ('$actor','admin','$actionText','fa-trash','#D6455D')");
                $flash = "Archive #$archiveId for {$archive['school_year']} was moved to Recently Deleted. It can be restored for " . (int)ARCHIVE_TRASH_DAYS . " days.";
                $flashType = 'ok';
            } catch (Throwable $ex) {
                $mysqli->rollback();
                $flash = 'Delete cancelled, the archive was kept: ' . $ex->getMessage() . ' (If this mentions max_allowed_packet, raise it in XAMPP\'s my.ini, e.g. max_allowed_packet=64M, then restart MySQL.)';
                $flashType = 'err';
            }
        }
    }
}

// Post/Redirect/Get: after any action, reload the page with a plain GET so a refresh can never
// re-submit it and the iframe never stays on a half-finished POST response.
if ($allowed && $_SERVER['REQUEST_METHOD'] === 'POST' && !headers_sent()) {
    @session_start();                       // re-open only long enough to store the message
    $_SESSION['sa_flash'] = ['msg' => (string)$flash, 'type' => (string)$flashType];
    session_write_close();
    header('Location: system_archive.php' . ($embed ? '?embed=' . $embedView : ''), true, 303);
    exit;
}

// ── View one archive ───────────────────────────────────────────────────────
if ($allowed && isset($_GET['view'])) {
    $id = (int)$_GET['view'];
    $stmt = $mysqli->prepare("SELECT * FROM system_archives WHERE id=? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $viewArchive = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($viewArchive) {
        $viewArchive['summary'] = json_decode($viewArchive['summary_json'] ?? '{}', true) ?: [];
    }
}

$periods = [];
$archives = [];
$trashItems = [];
$deletionLog = [];
$activePeriod = null;
if ($allowed) {
// List EVERY evaluation period that is not already archived (even ones with 0 live
// evaluations so far), so any specific period can be picked and archived.
$res = $mysqli->query("
    SELECT ep.id, ep.period_label, ep.semester, ep.school_year, ep.is_active,
           ep.date_start, ep.date_end, COUNT(et.id) AS live_count
    FROM evaluation_periods ep
    LEFT JOIN evaluation_tracker et ON et.period_id = ep.id
    LEFT JOIN system_archives sa ON sa.period_id = ep.id AND sa.status = 'archived'
    WHERE sa.id IS NULL
    GROUP BY ep.id, ep.period_label, ep.semester, ep.school_year, ep.is_active, ep.date_start, ep.date_end
    ORDER BY ep.school_year DESC, FIELD(ep.semester,'1st Semester','2nd Semester','Summer','School Year'), ep.id DESC
");
if ($res) while ($r = $res->fetch_assoc()) $periods[] = $r;
$archives = [];
// Only archives that are currently archived are listed. A restored archive drops off this list
    // and reappears only when its period is archived again (that flips the same row back to 'archived').
$res = $mysqli->query("SELECT sa.*, ep.date_start AS ep_start, ep.date_end AS ep_end
    FROM system_archives sa
    LEFT JOIN evaluation_periods ep ON ep.id = sa.period_id
    WHERE sa.status='archived' ORDER BY sa.archived_at DESC, sa.id DESC");
if ($res) while ($r = $res->fetch_assoc()) {
    $r['summary'] = json_decode($r['summary_json'] ?? '{}', true) ?: [];
    $archives[] = $r;
}
$trashItems = [];
$deletionLog = [];
$res = $mysqli->query("SELECT id, archive_id, period_id, period_label, school_year, status_at_delete, record_count, archived_at, archived_by_name, deleted_by_name, deleted_at, recovered_at, recovered_by_name, purged_at, purge_reason, purged_by_name,
    (payload_json IS NOT NULL AND payload_json <> '') AS has_payload,
    GREATEST(0, CEIL(TIMESTAMPDIFF(SECOND, NOW(), deleted_at + INTERVAL " . (int)ARCHIVE_TRASH_DAYS . " DAY) / 86400)) AS days_left
    FROM system_archive_deletions ORDER BY deleted_at DESC, id DESC LIMIT 300");
if ($res) while ($r = $res->fetch_assoc()) {
    if ((int)$r['has_payload'] === 1 && empty($r['recovered_at']) && empty($r['purged_at'])) $trashItems[] = $r;
    else $deletionLog[] = $r;
}
$ap = $mysqli->query("SELECT school_year, semester FROM evaluation_periods WHERE is_active=1 ORDER BY id DESC LIMIT 1");
if ($ap) $activePeriod = $ap->fetch_assoc();
} // end if ($allowed)
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>System Archive — Evaluation System</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="admin_ui_theme.css">
<link rel="stylesheet" href="admin_compact_ui.css">
<style>
:root{--bg:#F5F8FC;--card:#fff;--line:#D8E5F4;--text:#102746;--muted:#67819E;--blue:#2563EB;--blue-soft:#EEF4FF;--green:#0F9F6E;--green-soft:#EAF9F2;--amber:#C77A08;--amber-soft:#FFF7E6;--red:#D6455D;--red-soft:#FFF0F2;--shadow:0 3px 14px rgba(30,82,144,.08)}
*{box-sizing:border-box} body{margin:0;background:var(--bg);color:var(--text);font:13px/1.5 Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;-webkit-font-smoothing:antialiased}.wrap{max-width:1240px;margin:0 auto;padding:24px 28px 44px}.back{display:inline-flex;gap:8px;align-items:center;color:var(--muted);text-decoration:none;font-size:12px;font-weight:600;margin-bottom:15px}.hero{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;margin-bottom:16px}.hero h1{font-size:25px;margin:0 0 3px;letter-spacing:-.025em}.hero p{margin:0;color:var(--muted);font-size:13px}.badge{display:inline-flex;align-items:center;gap:7px;padding:7px 10px;border-radius:999px;border:1px solid #C9D9EF;background:#fff;color:var(--blue);font-weight:700;font-size:11px}.grid{display:grid;grid-template-columns:1.25fr .75fr;gap:14px}.card{background:var(--card);border:1px solid var(--line);border-radius:12px;box-shadow:var(--shadow);padding:17px}.card h2{font-size:14px;margin:0 0 4px}.sub{color:var(--muted);font-size:11.5px}.label{display:block;font-size:11px;font-weight:700;color:#405A76;margin:14px 0 6px;text-transform:uppercase;letter-spacing:.04em}.select{width:100%;padding:9px 11px;border:1px solid #B9CDE5;border-radius:8px;background:#fff;color:var(--text);font:600 12px Inter}.actions{display:flex;gap:8px;align-items:center;margin-top:13px;flex-wrap:wrap}.btn{border:1px solid #B9CDE5;background:#fff;border-radius:8px;padding:9px 12px;font:700 12px Inter;color:var(--text);cursor:pointer}.btn.primary{border-color:var(--blue);background:var(--blue);color:#fff}.btn.danger{border-color:#F1B7C0;background:#FFF6F7;color:var(--red)}.btn:disabled{opacity:.45;cursor:not-allowed}.warn{display:flex;gap:8px;align-items:flex-start;margin-top:12px;padding:9px 10px;background:var(--amber-soft);border:1px solid #F5D48A;border-radius:8px;color:#8D5D00;font-size:11.5px}.success{display:flex;gap:8px;align-items:flex-start;padding:9px 10px;background:var(--green-soft);border:1px solid #BDE9D4;border-radius:8px;color:#08734F;font-size:11.5px}.archive-table{margin-top:14px;border:1px solid var(--line);border-radius:10px;overflow:auto;background:#fff}.archive-table table{width:100%;border-collapse:collapse;min-width:760px}.archive-table th,.archive-table td{padding:10px 11px;border-bottom:1px solid #EAF0F7;text-align:left;font-size:11.5px;white-space:nowrap}.archive-table th.select-col,.archive-table td.select-col{width:38px;text-align:center;padding-left:10px;padding-right:4px}.archive-table input.archive-check,.archive-table input#selectAllArchives{width:15px;height:15px;accent-color:var(--blue);cursor:pointer}.bulk-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.selection-count{font-size:11px;color:var(--muted);font-weight:600}.archive-table th{background:#F8FAFC;color:#49647F;font-size:10.5px;text-transform:uppercase;letter-spacing:.05em}.archive-table tr:last-child td{border-bottom:0}.status{display:inline-flex;padding:4px 8px;border-radius:999px;font-size:10px;font-weight:800}.status.archived{background:var(--green-soft);color:var(--green)}.status.left-warn{background:#FFF6E0;color:#8D5D00}.status.del-unrestored{background:#FFF0F2;color:var(--red)}.status.restored{background:var(--blue-soft);color:var(--blue)}.empty{padding:25px;text-align:center;color:var(--muted)}.detail{margin-top:14px}.detail-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}.mini{padding:11px;border:1px solid var(--line);background:#FBFDFF;border-radius:8px}.mini b{display:block;font-size:16px}.mini span{color:var(--muted);font-size:10.5px}.notice{margin-bottom:14px;padding:10px 12px;border-radius:9px;border:1px solid #C9D9EF;background:var(--blue-soft);color:#2855A4;font-size:11.5px;display:flex;gap:8px}.restricted{max-width:620px;margin:60px auto;text-align:center}.restricted .icon{font-size:30px;color:var(--red);margin-bottom:8px}.multi-wrap{position:relative}.multi-trigger{display:flex;align-items:center;justify-content:space-between;gap:10px;text-align:left;cursor:pointer}.multi-trigger i{font-size:10px;color:#49647F;transition:transform .15s}.multi-trigger[aria-expanded=true] i{transform:rotate(180deg)}.multi-box[hidden]{display:none}.multi-box{position:static;margin-top:6px;border:1px solid #B9CDE5;border-radius:8px;background:#fff;max-height:190px;overflow:auto}.multi-row{display:flex;align-items:center;gap:9px;margin:0;padding:8px 11px;min-height:0;height:auto;line-height:1.3;border-bottom:1px solid #EAF0F7;font-size:12px;font-weight:600;cursor:pointer}.multi-row:last-child{border-bottom:0}.multi-row:hover{background:#F5F9FF}.multi-row input{margin:0;padding:0;flex:0 0 15px;width:15px;height:15px;accent-color:var(--blue);cursor:pointer}.multi-row em{font-style:normal;color:var(--muted);font-weight:500}.multi-all{background:#F8FAFC;position:sticky;top:0;color:#405A76}@media(max-width:900px){.grid{grid-template-columns:1fr}.detail-grid{grid-template-columns:repeat(2,1fr)}}

.archive-filter{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:14px 0 4px}
.archive-filter .af-field{display:flex;align-items:center;gap:8px}
.archive-filter label{font-size:11px;font-weight:700;color:#405A76;text-transform:uppercase;letter-spacing:.04em;display:flex;align-items:center;gap:6px}
.archive-filter select{appearance:none;-webkit-appearance:none;min-width:170px;padding:8px 34px 8px 12px;border:1px solid #B9CDE5;border-radius:8px;color:var(--text);font:600 12px Inter,system-ui,sans-serif;cursor:pointer;background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' fill='none' stroke='%2367819E' stroke-width='2' stroke-linecap='round'/%3E%3C/svg%3E") no-repeat right 12px center}
.archive-filter select:hover{border-color:var(--blue)}
.archive-filter select:focus{outline:3px solid rgba(37,99,235,.25);outline-offset:1px;border-color:var(--blue)}
.archive-filter .af-clear{border:0;background:none;color:var(--blue);font:700 12px Inter,system-ui,sans-serif;cursor:pointer;padding:6px 4px;display:none}
.archive-filter .af-clear:hover{text-decoration:underline}
</style>
<style id="sa-friendly">
.pick-toolbar{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 2px 6px}
.pick-all{display:flex;align-items:center;gap:8px;font-size:12px;font-weight:700;color:#405A76;cursor:pointer;margin:0}
.pick-all input{width:15px;height:15px;accent-color:var(--blue)}
.pick-hint{font-size:11px;color:var(--muted);font-weight:600}
.pick-list{border:1px solid var(--line);border-radius:10px;background:#fff;max-height:340px;overflow:auto;padding:4px 10px 10px}
.pick-year{display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:1;background:#fff;padding:10px 2px 6px;font-size:11px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:#49647F}
.pick-year i{margin-right:5px;color:var(--blue)}
.pick-year-btn{border:0;background:none;color:var(--blue);font:700 11.5px Inter,system-ui,sans-serif;cursor:pointer;padding:3px 6px;border-radius:6px;text-transform:none;letter-spacing:0}
.pick-year-btn:hover{background:var(--blue-soft)}
.pick-card{display:flex;align-items:center;gap:12px;margin:0 0 8px;padding:11px 13px;border:1.5px solid var(--line);border-radius:10px;background:#fff;cursor:pointer;text-transform:none;letter-spacing:0;transition:border-color .12s,background .12s,box-shadow .12s}
.pick-card:hover{border-color:#9DBBE6;background:#F8FBFF}
.pick-card.on{border-color:var(--blue);background:var(--blue-soft);box-shadow:0 0 0 3px rgba(37,99,235,.12)}
.pick-card input{width:17px;height:17px;flex:0 0 17px;margin:0;accent-color:var(--blue);cursor:pointer}
.pick-main{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px}
.pick-title{display:flex;align-items:center;gap:7px;flex-wrap:wrap;font-size:13px;color:var(--text)}
.pick-main small{font-size:11px;color:var(--muted);font-weight:500}
.pick-main small i{margin-right:3px}
.pick-count{text-align:right;line-height:1.15}
.pick-count b{display:block;font-size:17px;color:var(--text)}
.pick-count small{font-size:10.5px;color:var(--muted)}
.pick-card.is-empty .pick-count b{color:#9AA9BB}
.chip{display:inline-flex;align-items:center;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:800;letter-spacing:.02em}
.chip-active{background:var(--green-soft);color:var(--green)}
.chip-empty{background:#EEF2F7;color:#6D7F95}
.pick-summary{display:flex;align-items:center;gap:8px;margin-top:12px;padding:9px 12px;border-radius:8px;background:#F4F7FB;color:var(--muted);font-size:12px;font-weight:600}
.pick-summary.ready{background:var(--blue-soft);color:#1E4FBF}
.pick-summary b{color:inherit}
.pick-warn{margin-top:8px;padding:9px 12px;border-radius:8px;background:var(--amber-soft);border:1px solid #F5D48A;color:#8D5D00;font-size:11.5px;line-height:1.5}
.pick-warn[hidden]{display:none}
.pick-empty{display:flex;gap:12px;align-items:center;margin-top:10px;padding:18px;border:1.5px dashed #B9CDE5;border-radius:10px;color:var(--muted);font-size:12px}
.pick-empty i{font-size:24px;color:#9DBBE6}
.actions .btn.primary{padding:10px 18px;font-size:12.5px}
.detail-grid .mini span+b+span{display:block;margin-top:2px}
html[data-theme="dark"] .pick-list,html[data-theme="dark"] .pick-year,html[data-theme="dark"] .pick-card{background:#172A45}
html[data-theme="dark"] .pick-card.on{background:#1B3A6B}
html[data-theme="dark"] .pick-title,html[data-theme="dark"] .pick-count b{color:#E7ECF3}
html[data-theme="dark"] .pick-summary{background:#0F1F3D}
</style>
<style id="pbi-feature-scrollbar">

/* PBI FEATURE SCROLLBAR — consistent with the compact page scrollbar */
html, body {
  scrollbar-width: thin !important;
  scrollbar-color: #888 transparent !important;
}
html::-webkit-scrollbar, body::-webkit-scrollbar,
.feature-compact ::-webkit-scrollbar { width: 10px !important; height: 10px !important; }
html::-webkit-scrollbar-track, body::-webkit-scrollbar-track,
.feature-compact ::-webkit-scrollbar-track { background: transparent !important; }
html::-webkit-scrollbar-thumb, body::-webkit-scrollbar-thumb,
.feature-compact ::-webkit-scrollbar-thumb {
  background: #888 !important; border-radius: 999px !important;
  border: 2px solid transparent !important; background-clip: padding-box !important;
}
html::-webkit-scrollbar-thumb:hover, body::-webkit-scrollbar-thumb:hover,
.feature-compact ::-webkit-scrollbar-thumb:hover { background: #777 !important; background-clip: padding-box !important; }
html::-webkit-scrollbar-button, body::-webkit-scrollbar-button,
.feature-compact ::-webkit-scrollbar-button { display: block !important; width: 10px !important; height: 10px !important; background-color: transparent !important; }

</style>

<link rel="stylesheet" href="admin_appearance.css">
<script src="admin_appearance.js"></script>
<link rel="stylesheet" href="admin_unified_ui.css?v=<?= (int)@filemtime(__DIR__ . '/admin_unified_ui.css') ?>">
<style id="sa-v2">
.sa-hero{display:flex;flex-direction:column;gap:16px;margin:0 0 14px;padding:20px 22px;background:#fff;border:1px solid #D8E5F4;border-left:5px solid #2563EB;border-radius:16px;box-shadow:0 2px 4px rgba(30,82,144,.05),0 8px 20px rgba(30,82,144,.06)}
.sa-hero.attn{border-left-color:#E2A33B}
.sa-hero.clear{border-left-color:#0F9F6E}
.sa-hero-main{display:flex;gap:14px;align-items:center}
.sa-hero-icon{flex:0 0 46px;height:46px;border-radius:12px;display:grid;place-items:center;font-size:20px;background:#FFF7E6;color:#C77A08}
.sa-hero.clear .sa-hero-icon{background:#E8F8F1;color:#0F9F6E}
.sa-hero-title{font-size:19px;font-weight:800;color:#0B1F3A;letter-spacing:-.01em;line-height:1.3}
.sa-hero-sub{font-size:13px;color:#5C6B8A;margin-top:2px}
.sa-hero-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
@media(max-width:900px){.sa-hero-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
.sa-stat{padding:12px 14px;border:1px solid #E3ECF7;border-radius:12px;background:#F8FBFF}
.sa-stat span{display:block;font-size:11.5px;font-weight:600;color:#67819E}
.sa-stat b{display:block;margin-top:2px;font-size:22px;color:#0B1F3A}
.sa-stat small{font-size:11.5px;color:#7A8CA5}
.sa-flow{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:14px 0 20px}
@media(max-width:760px){.sa-flow{grid-template-columns:1fr}}
.sa-flow>div{display:flex;flex-direction:column;gap:2px;padding:12px 14px;border:1px solid #E3ECF7;border-radius:12px;background:#F8FBFF}
.sa-flow i{color:#2563EB;font-size:16px;margin-bottom:4px}
.sa-flow b{font-size:13px;color:#0B1F3A}
.sa-flow span{font-size:12px;line-height:1.45;color:#5C6B8A}
.sa-step-head{display:flex;gap:12px;align-items:flex-start;margin:0 0 12px}
.sa-step-gap{margin-top:22px}
.sa-num{flex:0 0 auto;width:26px;height:26px;border-radius:50%;background:#EEF4FF;color:#2563EB;font-weight:800;font-size:12.5px;display:flex;align-items:center;justify-content:center}
.sa-step-head h3{margin:0;font-size:15px;font-weight:700;color:#0B1F3A}
.sa-step-head p{margin:2px 0 0;font-size:12.5px;color:#5C6B8A}
.sa-archive .warn{margin-top:14px}
</style>
<style id="sa-v3">
/* ---- "Choose what to archive" picker, redesigned (conservative pass) ---- */
.sa-archive{padding:22px 24px 20px}
.sa-pick-head{display:flex;gap:13px;align-items:flex-start;margin:0 0 15px}
.sa-pick-icon{flex:0 0 38px;height:38px;border-radius:10px;background:#EEF4FF;color:#2563EB;display:flex;align-items:center;justify-content:center;font-size:15px}
.sa-pick-head-text{min-width:0}
.sa-pick-eyebrow{display:block;font-size:10.5px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#6D86A3;margin:0 0 3px}
.sa-pick-head h3{margin:0;font-size:16px;font-weight:800;color:#0B1F3A;letter-spacing:-.01em}
.sa-pick-head p{margin:3px 0 0;font-size:12.5px;line-height:1.5;color:#5C6B8A;max-width:540px}

.sa-pick-notice{display:flex;gap:11px;align-items:flex-start;margin:0 0 14px;padding:11px 13px;border:1px solid #CFE0F7;border-radius:11px;background:#F4F8FF}
.sa-pick-notice i{flex:0 0 auto;margin-top:2px;color:#2563EB;font-size:13px}
.sa-pick-notice div{font-size:12.5px;line-height:1.55;color:#2B446B}
.sa-pick-notice strong{color:#0B1F3A}

.pick-toolbar{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:0 2px 10px}
.pick-all{display:flex;align-items:center;gap:8px;font-size:12px;font-weight:700;color:#405A76;cursor:pointer;margin:0}
.pick-all input{width:15px;height:15px;accent-color:#2563EB;cursor:pointer}
.pick-hint{font-size:11px;color:#7A8CA5;font-weight:600}

.pick-list{border:1px solid #E3ECF7;border-radius:11px;background:#FBFDFF;max-height:360px;overflow:auto;padding:4px 10px 10px}
.pick-year{display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:1;background:#FBFDFF;padding:10px 2px 6px;font-size:11px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:#49647F;border-bottom:1px solid #EAF1FA}
.pick-year i{margin-right:5px;color:#2563EB}
.pick-year-count{display:inline-block;margin-left:7px;font-weight:600;letter-spacing:0;text-transform:none;color:#8DA0BB;font-size:10.5px}
.pick-year-btn{border:0;background:none;color:#2563EB;font:700 11.5px Inter,sans-serif;cursor:pointer;padding:3px 6px;border-radius:6px;text-transform:none;letter-spacing:0}
.pick-year-btn:hover{background:#EEF4FF}

.pick-card{position:relative;display:flex;align-items:center;gap:12px;margin:8px 0 0;padding:11px 13px 11px 15px;border:1.5px solid #E3ECF7;border-radius:10px;background:#fff;cursor:pointer;transition:border-color .12s,background .12s,box-shadow .12s}
.pick-card::before{content:'';position:absolute;left:0;top:8px;bottom:8px;width:3px;border-radius:3px;background:transparent}
.pick-card:hover{border-color:#B7CEEC;background:#F8FBFF}
.pick-card.on{border-color:#2563EB;background:#F0F6FF;box-shadow:0 1px 3px rgba(37,99,235,.1)}
.pick-card.on::before{background:#2563EB}
.pick-card input{width:17px;height:17px;flex:0 0 17px;margin:0;accent-color:#2563EB;cursor:pointer}
.pick-main{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px}
.pick-title{display:flex;align-items:center;gap:7px;flex-wrap:wrap;font-size:13px;color:#0B1F3A}
.pick-main small{font-size:11px;color:#7A8CA5;font-weight:500}
.pick-main small i{margin-right:3px}
.pick-count{text-align:right;line-height:1.15;padding-left:10px;border-left:1px solid #EEF2F8}
.pick-count b{display:block;font-size:17px;font-weight:800;color:#0B1F3A}
.pick-count small{font-size:10.5px;color:#8DA0BB}
.pick-card.is-empty .pick-count b{color:#AEB9C8}

.chip-active{background:#E8F8F1;color:#0A7A55;gap:5px}
.chip-active::before{content:'';width:5px;height:5px;border-radius:50%;background:#0F9F6E}

.pick-summary{display:flex;align-items:center;gap:8px;margin-top:14px;padding:10px 13px;border-radius:10px;background:#F4F7FB;color:#5C6B8A;font-size:12px;font-weight:600}
.pick-summary.ready{background:#EEF4FF;color:#1E4FBF}
.pick-summary b{color:inherit}
.pick-warn{margin-top:8px;padding:9px 12px;border-radius:9px;background:#FFF7E6;border:1px solid #F5D48A;color:#8D5D00;font-size:11.5px;line-height:1.5}
.sa-archive .actions{margin-top:13px}
.sa-archive .actions .btn.primary{padding:12px 20px;font-size:13.5px;font-weight:800;border-radius:11px;box-shadow:0 3px 10px rgba(37,99,235,.22)}
.sa-archive .actions .btn.primary:disabled{box-shadow:none}

.pick-empty{display:flex;gap:13px;align-items:center;padding:24px 18px;border:1.5px dashed #C9D9EF;border-radius:12px;color:#67819E;font-size:12.5px;background:#FBFDFF}
.pick-empty i{font-size:25px;color:#9DBBE6}

html[data-theme="dark"] .sa-pick-head h3,html[data-theme="dark"] .pick-title{color:#E7ECF3}
html[data-theme="dark"] .pick-list,html[data-theme="dark"] .pick-year,html[data-theme="dark"] .pick-card,html[data-theme="dark"] .sa-pick-notice{background:#172A45}
html[data-theme="dark"] .pick-card.on{background:#1B3A6B}
html[data-theme="dark"] .pick-count b{color:#E7ECF3}
html[data-theme="dark"] .pick-summary{background:#0F1F3D}
</style>
<?php if ($embed): ?><style>body{background:transparent}.wrap{max-width:none;padding:4px 2px 24px}.back{display:none}</style><?php endif; ?>
</head>
<body class="feature-compact ux-nested">
<div class="wrap">
<?php if (!$allowed): ?>
<div class="card restricted"><div class="icon"><i class="fa-solid fa-lock"></i></div><h1>System Archive is restricted</h1><p class="sub">Only the Super Admin, or an Admin explicitly granted the <strong>System Archive</strong> edit permission, can archive or restore evaluation data.</p></div>
<?php else: ?>
<?php if ($flash): ?><div class="notice" style="border-color:<?= $flashType==='ok'?'#BDE9D4':'#F1B7C0' ?>;background:<?= $flashType==='ok'?'#EAF9F2':'#FFF0F2' ?>;color:<?= $flashType==='ok'?'#08734F':'#A62A42' ?>;"><i class="fa-solid <?= $flashType==='ok'?'fa-circle-check':'fa-circle-exclamation' ?>"></i><span><?= e($flash) ?></span></div><?php endif; ?>
<?php $sa_live_total = array_sum(array_map(fn($p) => (int)$p['live_count'], $periods));
$sa_live_periods = count(array_filter($periods, fn($p) => (int)$p['live_count'] > 0)); ?>
<section class="sa-hero <?= $sa_live_total > 0 ? 'attn' : 'clear' ?>"<?= $embed ? ' hidden style="display:none"' : '' ?>>
  <div class="sa-hero-main">
    <span class="sa-hero-icon"><i class="fa-solid <?= $sa_live_total > 0 ? 'fa-box-archive' : 'fa-circle-check' ?>"></i></span>
    <div>
      <div class="sa-hero-title"><?php if ($sa_live_total > 0): ?><?= number_format($sa_live_total) ?> live evaluation<?= $sa_live_total === 1 ? '' : 's' ?> in <?= $sa_live_periods ?> period<?= $sa_live_periods === 1 ? '' : 's' ?> can be archived<?php else: ?>Nothing is waiting to be archived<?php endif; ?></div>
      <div class="sa-hero-sub"><?php if ($activePeriod): ?>Active period: <?= e($activePeriod['school_year']) ?> &middot; <?= e($activePeriod['semester']) ?><?php else: ?>There is no active evaluation period right now<?php endif; ?></div>
    </div>
  </div>
  <div class="sa-hero-stats">
    <div class="sa-stat"><span>Live evaluations</span><b><?= number_format($sa_live_total) ?></b><small>across all open periods</small></div>
    <div class="sa-stat"><span>Periods ready to archive</span><b><?= count($periods) ?></b><small>not yet archived</small></div>
    <div class="sa-stat"><span>Archived so far</span><b><?= count($archives) ?></b><small>in Archived Records</small></div>
    <div class="sa-stat"><span>Active period</span><b><?= $activePeriod ? e($activePeriod['school_year']) : 'None' ?></b><small><?= $activePeriod ? e($activePeriod['semester']) : 'no active period' ?></small></div>
  </div>
</section>

<div class="card sa-archive"<?= $showPicker ? '' : ' hidden style="display:none"' ?>>

<div class="sa-pick-head">
  <span class="sa-pick-icon"><i class="fa-solid fa-box-archive"></i></span>
  <div class="sa-pick-head-text">
    <span class="sa-pick-eyebrow">Step 1 of 2 &middot; Select periods</span>
    <h3>Choose what to archive</h3>
    <p>Tick each finished period below, or use <strong>Select year</strong> to grab a whole school year at once.</p>
  </div>
</div>
<?php $byYear = []; foreach ($periods as $p) $byYear[$p['school_year']][] = $p;
$preselectIds = array_values(array_filter(array_map('intval', explode(',', (string)($_GET['preselect'] ?? ''))))); ?>
<?php if ($preselectIds): ?><div class="sa-pick-notice"><i class="fa-solid fa-circle-info"></i><div><strong>Opened from Create new evaluation schedule.</strong> The current evaluation is already selected below &mdash; archive it, then switch back to <strong>Current schedule</strong> to create the new one.</div></div><?php endif; ?>
<?php if (!$periods): ?>
<div class="pick-empty"><i class="fa-regular fa-folder-open"></i><div><strong>No evaluation periods to archive</strong><br>Periods you create in System &amp; Period will appear here. Already-archived periods are listed under Archived Records.</div></div>
<?php else: ?>
<div class="pick-toolbar"><label class="pick-all"><input type="checkbox" id="periodAll"> <span>Select all periods</span></label><span class="pick-hint"><?= count($periods) ?> period<?= count($periods)===1?'':'s' ?> available</span></div>
<div class="pick-list" id="periodList">
<?php foreach ($byYear as $yr => $list):
  $yrLive = array_sum(array_map(fn($p) => (int)$p['live_count'], $list)); ?>
<div class="pick-year"><span><i class="fa-regular fa-calendar-days"></i> Academic Year <?= e($yr) ?><span class="pick-year-count"><?= count($list) ?> period<?= count($list)===1?'':'s' ?> &middot; <?= number_format($yrLive) ?> evaluation<?= $yrLive===1?'':'s' ?></span></span><button type="button" class="pick-year-btn" data-year="<?= e($yr) ?>">Select year</button></div>
<?php foreach ($list as $p): $live = (int)$p['live_count']; $rng = eval_date_range($p['date_start'] ?? null, $p['date_end'] ?? null); $isAct = (int)$p['is_active'] === 1; ?>
<label class="pick-card<?= $live === 0 ? ' is-empty' : '' ?>">
<input type="checkbox" class="period-check" value="<?= (int)$p['id'] ?>" data-label="<?= e($p['school_year'] . ' — ' . $p['semester']) ?>" data-live="<?= $live ?>" data-active="<?= $isAct ? 1 : 0 ?>" data-year="<?= e($p['school_year']) ?>" <?= ((!empty($viewArchive) && (int)$viewArchive['period_id']===(int)$p['id']) || in_array((int)$p['id'], $preselectIds, true))?'checked':'' ?>>
<span class="pick-main"><span class="pick-title"><strong><?= e($p['semester']) ?></strong><?php if ($isAct): ?><span class="chip chip-active">Active</span><?php endif; ?><?php if ($live === 0): ?><span class="chip chip-empty">No evaluations yet</span><?php endif; ?></span>
<small><?php if ($rng !== ''): ?><i class="fa-regular fa-calendar"></i> <?= e($rng) ?><?php else: ?>No dates set<?php endif; ?><?php if ($p['period_label'] !== $p['semester']): ?> · <?= e($p['period_label']) ?><?php endif; ?></small></span>
<span class="pick-count"><b><?= number_format($live) ?></b><small>evaluation<?= $live === 1 ? '' : 's' ?></small></span>
</label>
<?php endforeach; endforeach; ?>
</div>
<div class="pick-summary" id="pickSummary"></div>
<div class="pick-warn" id="activeWarn" hidden></div>
<?php endif; ?>
<div class="actions"<?= $periods ? '' : ' style="display:none"' ?>><form method="POST" id="archiveForm" onsubmit="return confirmArchive(this)"><?= csrf_field() ?><input type="hidden" name="action" value="archive"><div id="archivePeriodInputs"></div><button class="btn primary" type="submit" id="archiveBtn" disabled><i class="fa-solid fa-box-archive"></i> <span id="archiveBtnText">Archive selected</span></button></form></div>
</div>

<div<?= $showHistory ? '' : ' hidden style="display:none"' ?>>
<?php if ($viewArchive): ?>
<div class="card detail"><h2>Archived Evaluation Data — <?= e($viewArchive['school_year']) ?></h2><div class="sub">Archive #<?= (int)$viewArchive['id'] ?> · Archived <?= e($viewArchive['archived_at']) ?> by <?= e($viewArchive['archived_by_name']) ?> · Status: <span class="status <?= e($viewArchive['status']) ?>"><?= ucfirst(e($viewArchive['status'])) ?></span></div>
<div class="detail-grid" style="margin-top:12px">
<?php foreach (($viewArchive['summary']??[]) as $k=>$v): ?><div class="mini"><span><?= e(ucwords(str_replace('_',' ',$k))) ?></span><b><?= number_format((int)$v) ?></b></div><?php endforeach; ?>
</div>
<div class="actions"><a class="btn" href="system_archive.php<?= $embed ? '?embed=' . $embedView : '' ?>"><i class="fa-solid fa-arrow-left"></i> Back to Archive</a><?php if ($viewArchive['status']==='archived'): ?><form method="POST" onsubmit="return confirmRestore(this)"><?= csrf_field() ?><input type="hidden" name="action" value="restore"><input type="hidden" name="archive_id" value="<?= (int)$viewArchive['id'] ?>"><button class="btn danger" type="submit"><i class="fa-solid fa-box-open"></i> Restore This Archive</button></form><?php endif; ?><form method="POST" onsubmit="return confirmDelete(this, <?= e(json_encode($viewArchive['school_year'] . ' — ' . $viewArchive['period_label'])) ?>, <?= e(json_encode($viewArchive['status'])) ?>)"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="archive_id" value="<?= (int)$viewArchive['id'] ?>"><button class="btn danger" type="submit"><i class="fa-solid fa-trash"></i> Delete This Archive</button></form></div>
</div>
<?php endif; ?>

<div class="card" style="margin-top:14px"><div style="display:flex;justify-content:space-between;align-items:end;gap:12px"><div><h2>Archived Records</h2><div class="sub">Finished evaluations are saved here. Tick one or more to restore them to the live system, or open one to review it.</div></div><div class="bulk-actions"><span class="selection-count" id="selectionCount">0 selected</span><form method="POST" id="bulkRestoreForm" onsubmit="return confirmBulkRestore(this)"><?= csrf_field() ?><input type="hidden" name="action" value="restore_selected"><div id="bulkRestoreInputs"></div><button class="btn primary" type="submit" id="bulkRestoreBtn" disabled><i class="fa-solid fa-box-open"></i> Restore Selected</button></form><div class="sub" id="archiveCountLabel" data-total="<?= count($archives) ?>"><?= count($archives) ?> archive<?= count($archives)===1?'':'s' ?></div></div></div>
<?php if ($archives):
    $archTerms = array_values(array_unique(array_map(fn($a) => (string)$a['period_label'], $archives)));
    $archYears = array_values(array_unique(array_map(fn($a) => (string)$a['school_year'], $archives)));
    sort($archTerms, SORT_NATURAL | SORT_FLAG_CASE);
    rsort($archYears, SORT_NATURAL);
?>
<div class="archive-filter">
    <div class="af-field"><label for="archiveTermFilter"><i class="fa-solid fa-filter"></i> Term</label>
        <select id="archiveTermFilter"><option value="">All terms</option><?php foreach ($archTerms as $t):
            $ranges = [];
            foreach ($archives as $a) { if ((string)$a['period_label'] === $t) { $r = eval_date_range($a['ep_start'] ?? null, $a['ep_end'] ?? null); if ($r !== '') $ranges[$r] = true; } }
            $optText = count($ranges) === 1 ? $t . ' (' . array_key_first($ranges) . ')' : $t;
        ?><option value="<?= e($t) ?>"><?= e($optText) ?></option><?php endforeach; ?></select></div>
    <?php if (count($archYears) > 1): ?>
    <div class="af-field"><label for="archiveYearFilter"><i class="fa-solid fa-calendar-days"></i> Academic Year</label>
        <select id="archiveYearFilter"><option value="">All years</option><?php foreach ($archYears as $y): ?><option value="<?= e($y) ?>"><?= e($y) ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <button type="button" class="af-clear" id="archiveFilterClear"><i class="fa-solid fa-xmark"></i> Clear filter</button>
</div>
<?php endif; ?>
<div class="archive-table">
<table><thead><tr><th class="select-col"><input type="checkbox" id="selectAllArchives" title="Select all archived records"></th><th>Academic Year</th><th>Archived On</th><th>Archived By</th><th>Records</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php if (!$archives): ?><tr><td colspan="7" class="empty">No archived evaluation years yet.</td></tr>
<?php else: foreach ($archives as $a): ?><tr class="archive-row" data-term="<?= e($a['period_label']) ?>" data-year="<?= e($a['school_year']) ?>"><td class="select-col"><?php if ($a['status']==='archived'): ?><input type="checkbox" class="archive-check" value="<?= (int)$a['id'] ?>" data-label="<?= e($a['school_year'] . ' — ' . $a['period_label']) ?>"><?php else: ?><span style="color:#B7C5D5">—</span><?php endif; ?></td><td><strong><?= e($a['school_year']) ?></strong><br><span style="color:#91A6BE;font-size:10.5px"><?= e($a['period_label']) ?></span><?php $rng = eval_date_range($a['ep_start'] ?? null, $a['ep_end'] ?? null); if ($rng): ?><br><span style="color:#6D86A3;font-size:10.5px;font-weight:600"><i class="fa-regular fa-calendar" style="margin-right:4px"></i><?= e($rng) ?></span><?php endif; ?></td><td><?= e(date('M j, Y · g:i A', strtotime((string)$a['archived_at']))) ?></td><td><?= e($a['archived_by_name']) ?></td><td><?= number_format((int)$a['record_count']) ?></td><td><span class="status <?= e($a['status']) ?>"><?= ucfirst(e($a['status'])) ?></span></td><td style="display:flex;gap:6px;align-items:center"><?php if ($a['status']==='archived'): ?><form method="POST" onsubmit="return confirmRestore(this)"><?= csrf_field() ?><input type="hidden" name="action" value="restore"><input type="hidden" name="archive_id" value="<?= (int)$a['id'] ?>"><button class="btn primary" type="submit"><i class="fa-solid fa-box-open"></i> Restore</button></form><?php else: ?><span style="font-size:11px;color:#7A90AA;font-weight:600"><i class="fa-solid fa-circle-check"></i> Restored</span><?php endif; ?><form method="POST" onsubmit="return confirmDelete(this, <?= e(json_encode($a['school_year'] . ' — ' . $a['period_label'])) ?>, <?= e(json_encode($a['status'])) ?>)"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="archive_id" value="<?= (int)$a['id'] ?>"><button class="btn danger" type="submit"><i class="fa-solid fa-trash"></i> Delete</button></form></td></tr><?php endforeach; endif; ?>
<tr id="archiveNoMatch" style="display:none"><td colspan="7" class="empty">No archived records match this filter.</td></tr>
</tbody></table></div></div>

<div class="card" style="margin-top:14px"><div style="display:flex;justify-content:space-between;align-items:end;gap:12px;flex-wrap:wrap"><div><h2><i class="fa-solid fa-trash-can-arrow-up" style="color:var(--red)"></i> Recently Deleted</h2><div class="sub">Deleted archives stay here for <?= (int)ARCHIVE_TRASH_DAYS ?> days and can be restored. After that they are permanently erased.</div></div><div class="bulk-actions"><span class="selection-count"><?= count($trashItems) ?> item<?= count($trashItems)===1?'':'s' ?></span><?php if ($trashItems): ?><form method="POST" onsubmit="return confirmEmptyTrash(this, <?= count($trashItems) ?>)"><?= csrf_field() ?><input type="hidden" name="action" value="empty_trash"><button class="btn danger" type="submit"><i class="fa-solid fa-trash-can"></i> Empty Recently Deleted</button></form><?php endif; ?></div></div>
<div class="archive-table">
<table><thead><tr><th>Academic Year</th><th>Records</th><th>Deleted By</th><th>Deleted On</th><th>Time Left</th><th>Actions</th></tr></thead><tbody>
<?php if (!$trashItems): ?><tr><td colspan="6" class="empty">Nothing here. Deleted archives will appear for <?= (int)ARCHIVE_TRASH_DAYS ?> days.</td></tr>
<?php else: foreach ($trashItems as $d): $left = (int)$d['days_left']; $leftClass = $left <= 3 ? 'del-unrestored' : ($left <= 7 ? 'left-warn' : 'restored'); $leftText = $left <= 0 ? 'Erased soon' : ($left === 1 ? '1 day left' : $left . ' days left'); $lbl = $d['school_year'] . ' — ' . $d['period_label']; ?><tr><td><strong><?= e($d['school_year']) ?></strong><br><span style="color:#91A6BE;font-size:10.5px"><?= e($d['period_label']) ?> · Archive #<?= (int)$d['archive_id'] ?></span></td><td><?= number_format((int)$d['record_count']) ?></td><td><?= e($d['deleted_by_name'] ?: '—') ?></td><td><?= e($d['deleted_at']) ?></td><td><span class="status <?= $leftClass ?>"><?= e($leftText) ?></span></td><td style="display:flex;gap:6px;align-items:center"><form method="POST" onsubmit="return confirmRecover(this, <?= e(json_encode($lbl)) ?>)"><?= csrf_field() ?><input type="hidden" name="action" value="recover"><input type="hidden" name="history_id" value="<?= (int)$d['id'] ?>"><button class="btn primary" type="submit"><i class="fa-solid fa-rotate-left"></i> Restore</button></form><form method="POST" onsubmit="return confirmPurge(this, <?= e(json_encode($lbl)) ?>)"><?= csrf_field() ?><input type="hidden" name="action" value="purge"><input type="hidden" name="history_id" value="<?= (int)$d['id'] ?>"><button class="btn danger" type="submit"><i class="fa-solid fa-trash"></i> Delete Now</button></form></td></tr><?php endforeach; endif; ?>
</tbody></table></div>
<?php if ($deletionLog): ?>
<details style="margin-top:12px"><summary style="cursor:pointer;font-size:11.5px;font-weight:700;color:#49647F">Deletion log (<?= count($deletionLog) ?>)</summary>
<div class="archive-table">
<table><thead><tr><th>Academic Year</th><th>Deleted By</th><th>Deleted On</th><th>Outcome</th></tr></thead><tbody>
<?php foreach ($deletionLog as $d): ?><tr><td><strong><?= e($d['school_year']) ?></strong><br><span style="color:#91A6BE;font-size:10.5px"><?= e($d['period_label']) ?> · Archive #<?= (int)$d['archive_id'] ?></span></td><td><?= e($d['deleted_by_name'] ?: '—') ?></td><td><?= e($d['deleted_at']) ?></td><td><?php if (!empty($d['recovered_at'])): ?><span class="status restored">Restored <?= e($d['recovered_at']) ?></span><?php elseif (!empty($d['purged_at'])): ?><span class="status del-unrestored"><?= $d['purge_reason']==='expired' ? 'Expired — erased ' : 'Erased by ' . e($d['purged_by_name'] ?: 'admin') . ' ' ?><?= e($d['purged_at']) ?></span><?php else: ?><span style="font-size:11px;color:#9AA9BB">Not restorable (deleted before data was kept)</span><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div></details>
<?php endif; ?>
</div>

</div><!-- /history wrap -->
<?php endif; ?>
</div>
<script>
const periodChecks=[...document.querySelectorAll('.period-check')];
const periodAll=document.getElementById('periodAll');
const btn=document.getElementById('archiveBtn');
const periodInputs=document.getElementById('archivePeriodInputs');
const periodTrigger=document.getElementById('periodTrigger');
const periodText=document.getElementById('periodText');
const periodBox=document.getElementById('periodMulti');
const periodWrap=document.getElementById('periodWrap');
const archiveBtnText=document.getElementById('archiveBtnText');
function updatePickSummary(chosen){
    periodChecks.forEach(c=>{const card=c.closest('.pick-card');if(card)card.classList.toggle('on',c.checked);});
    const sum=document.getElementById('pickSummary'), warn=document.getElementById('activeWarn');
    if(sum){
        if(!chosen.length){sum.innerHTML='<i class="fa-regular fa-hand-pointer"></i> Select at least one period to enable archiving.';}
        else{const total=chosen.reduce((t,c)=>t+(parseInt(c.dataset.live,10)||0),0);sum.innerHTML=`<i class="fa-solid fa-circle-check"></i> <b>${chosen.length}</b> period${chosen.length===1?'':'s'} selected · <b>${total.toLocaleString()}</b> evaluation${total===1?'':'s'} will be archived`;}
        sum.classList.toggle('ready',chosen.length>0);
    }
    if(warn){
        const act=chosen.filter(c=>c.dataset.active==='1'), empty=chosen.filter(c=>(parseInt(c.dataset.live,10)||0)===0);
        const msgs=[];
        if(act.length) msgs.push(`<i class="fa-solid fa-triangle-exclamation"></i> <b>${act.map(c=>c.dataset.label).join(', ')}</b> ${act.length===1?'is the':'are'} currently active. Archiving will set ${act.length===1?'it':'them'} to inactive and turn evaluation tracking off.`);
        if(empty.length) msgs.push(`<i class="fa-solid fa-circle-info"></i> ${empty.length===1?'One selected period has':empty.length+' selected periods have'} no evaluations yet, so there is nothing to save for ${empty.length===1?'it':'them'}.`);
        warn.innerHTML=msgs.join('<br>');
        warn.hidden=msgs.length===0;
    }
    document.querySelectorAll('.pick-year-btn').forEach(b=>{const boxes=periodChecks.filter(c=>c.dataset.year===b.dataset.year);b.textContent=boxes.length&&boxes.every(c=>c.checked)?'Clear year':'Select year';});
}
document.querySelectorAll('.pick-year-btn').forEach(b=>b.addEventListener('click',()=>{const boxes=periodChecks.filter(c=>c.dataset.year===b.dataset.year);const all=boxes.every(c=>c.checked);boxes.forEach(c=>c.checked=!all);sync();}));
function sync(){
    if(!btn) return;
    const chosen=periodChecks.filter(c=>c.checked);
    btn.disabled=chosen.length===0;
    if(periodText) periodText.textContent=chosen.length===0?'Select an evaluation period…':(chosen.length===1?chosen[0].dataset.label:`${chosen.length} evaluation periods selected`);
    if(archiveBtnText) archiveBtnText.textContent=chosen.length===0?'Archive selected':(chosen.length===1?'Archive this period':`Archive ${chosen.length} periods`);
    if(periodInputs) periodInputs.innerHTML=chosen.map(c=>`<input type="hidden" name="period_ids[]" value="${c.value}">`).join('');
    updatePickSummary(chosen);
    if(periodAll){
        periodAll.checked=periodChecks.length>0 && chosen.length===periodChecks.length;
        periodAll.indeterminate=chosen.length>0 && chosen.length<periodChecks.length;
    }
}
function setPeriodOpen(open){if(!periodBox)return;periodBox.hidden=!open;periodTrigger.setAttribute('aria-expanded',open?'true':'false');}
if(periodTrigger){
    periodTrigger.addEventListener('click',()=>setPeriodOpen(periodBox.hidden));
    document.addEventListener('click',e=>{if(periodWrap&&!periodWrap.contains(e.target))setPeriodOpen(false);});
    document.addEventListener('keydown',e=>{if(e.key==='Escape')setPeriodOpen(false);});
}
periodChecks.forEach(c=>c.addEventListener('change',sync));
if(periodAll) periodAll.addEventListener('change',()=>{periodChecks.forEach(c=>c.checked=periodAll.checked);sync();});
sync();
// ---------------------------------------------------------------------------
// Custom confirm dialog (replaces the browser's native "school-evaluation.com says" box).
// Order of preference:
//   1) the dashboard's PBI.confirm (this page runs inside an iframe, so PBI usually lives in the parent)
//   2) the built-in modal below, which needs no other file
// The native window.confirm() is never used.
// ---------------------------------------------------------------------------
(function(){
    // The confirm overlay is mounted on the TOP-LEVEL page (the dashboard), not inside this iframe,
    // so the dark backdrop covers the whole screen - sidebar and header included - exactly like the
    // evaluation form dialog. If the parent page is not reachable (cross-origin / opened standalone)
    // it falls back to this document.
    function hostDoc(){
        try { if (window.top && window.top.document && window.top.document.body) return window.top.document; } catch (e) {}
        try { if (window.parent && window.parent.document && window.parent.document.body) return window.parent.document; } catch (e) {}
        return document;
    }
    const CSS = `
    .sa-modal-backdrop{position:fixed;top:0;left:0;right:0;bottom:0;width:100vw;height:100vh;z-index:2147483000;display:flex;align-items:center;justify-content:center;padding:24px;background:rgba(10,24,48,.62);-webkit-backdrop-filter:blur(3px);backdrop-filter:blur(3px);opacity:0;transition:opacity .15s ease;font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif}
    .sa-modal-backdrop.show{opacity:1}
    .sa-modal-backdrop *{box-sizing:border-box}
    .sa-modal{width:100%;max-width:520px;max-height:calc(100vh - 48px);display:flex;flex-direction:column;background:#fff;border-radius:18px;box-shadow:0 28px 70px rgba(10,30,60,.40);transform:translateY(10px) scale(.98);transition:transform .15s ease;overflow:hidden}
    .sa-modal-backdrop.show .sa-modal{transform:none}
    .sa-modal-head{display:flex;gap:14px;align-items:center;padding:24px 26px 8px}
    .sa-modal-icon{flex:0 0 44px;height:44px;border-radius:50%;display:grid;place-items:center;background:#FFF0F2;color:#D6455D}
    .sa-modal-icon svg{width:20px;height:20px;fill:currentColor}
    .sa-modal.safe .sa-modal-icon{background:#EEF4FF;color:#2563EB}
    .sa-modal-title{margin:0;font-size:18px;font-weight:800;color:#102746;letter-spacing:-.01em;line-height:1.35}
    .sa-modal-body{padding:8px 26px 22px 84px;overflow:auto;white-space:pre-line;font-size:14px;line-height:1.6;color:#405A76}
    .sa-modal-foot{display:flex;justify-content:flex-end;gap:10px;padding:16px 26px 22px;border-top:1px solid #D8E5F4;background:#F9FBFE}
    .sa-btn{border:1px solid #B9CDE5;background:#fff;color:#102746;border-radius:10px;padding:11px 22px;font:700 14px Inter,system-ui,sans-serif;cursor:pointer}
    .sa-btn:hover{background:#F1F6FC}
    .sa-btn.ok{border-color:transparent;color:#fff;background:#D6455D}
    .sa-btn.ok:hover{filter:brightness(.95)}
    .sa-modal.safe .sa-btn.ok{background:#0F9F6E}
    .sa-btn:focus-visible{outline:3px solid rgba(37,99,235,.35);outline-offset:2px}
    @media(max-width:560px){.sa-modal-body{padding-left:26px}}
    html[data-theme="dark"] .sa-modal{background:#172A45;border:1px solid rgba(255,255,255,.16)}
    html[data-theme="dark"] .sa-modal-title{color:#E7ECF3}
    html[data-theme="dark"] .sa-modal-body{color:#B9C6DA}
    html[data-theme="dark"] .sa-modal-foot{background:#0F1F3D;border-top-color:rgba(255,255,255,.12)}
    html[data-theme="dark"] .sa-btn{background:#0F1F3D;color:#E7ECF3;border-color:rgba(255,255,255,.2)}
    html[data-theme="dark"] .sa-btn:hover{background:#1B3253}`;
    const ICON_DANGER = '<svg viewBox="0 0 512 512" aria-hidden="true"><path d="M256 32c14.2 0 27.3 7.5 34.5 19.8l216 368c7.3 12.4 7.3 27.7.2 40.1S486.3 480 472 480H40c-14.3 0-27.6-7.7-34.7-20.1s-7-27.8.2-40.1l216-368C228.7 39.5 241.8 32 256 32zm0 128c-13.3 0-24 10.7-24 24v112c0 13.3 10.7 24 24 24s24-10.7 24-24V184c0-13.3-10.7-24-24-24zm32 224a32 32 0 1 0-64 0 32 32 0 1 0 64 0z"/></svg>';
    const ICON_SAFE = '<svg viewBox="0 0 512 512" aria-hidden="true"><path d="M256 512A256 256 0 1 0 256 0a256 256 0 1 0 0 512zm0-336c-17.7 0-32 14.3-32 32 0 13.3-10.7 24-24 24s-24-10.7-24-24c0-44.2 35.8-80 80-80s80 35.8 80 80c0 47.2-36 67.2-56 74.5V280c0 13.3-10.7 24-24 24s-24-10.7-24-24v-8.1c0-20.5 14.8-35.2 30.1-40.2 6.4-2.1 13.2-5.5 18.2-10.3 4.3-4.2 7.7-10 7.7-19.4 0-17.7-14.3-32-32-32zm-32 192a32 32 0 1 1 64 0 32 32 0 1 1-64 0z"/></svg>';

    // Safety net: if this page unloads while a dialog is mounted on the top page, remove it.
    const tracked = new Set();
    window.addEventListener('pagehide', () => tracked.forEach(n => { try { n.remove(); } catch (e) {} }));

    window.saConfirm = function(message, opts){
        opts = opts || {};
        const danger = opts.danger !== false;
        const lines = String(message).split('\n');
        const title = opts.title || lines[0].replace(/\?$/, '?');
        const body = (opts.title ? lines : lines.slice(1)).join('\n').trim();
        const doc = hostDoc();
        if (!doc.getElementById('sa-modal-style')) {
            const st = doc.createElement('style');
            st.id = 'sa-modal-style';
            st.textContent = CSS;
            doc.head.appendChild(st);
        }
        return new Promise(resolve => {
            const back = doc.createElement('div');
            back.className = 'sa-modal-backdrop';
            back.innerHTML = `
              <div class="sa-modal ${danger ? '' : 'safe'}" role="alertdialog" aria-modal="true" aria-labelledby="saTitle">
                <div class="sa-modal-head">
                  <div class="sa-modal-icon">${danger ? ICON_DANGER : ICON_SAFE}</div>
                  <h3 class="sa-modal-title" id="saTitle"></h3>
                </div>
                <div class="sa-modal-body"></div>
                <div class="sa-modal-foot">
                  <button type="button" class="sa-btn cancel"></button>
                  <button type="button" class="sa-btn ok"></button>
                </div>
              </div>`;
            back.querySelector('.sa-modal-title').textContent = title;
            const bodyEl = back.querySelector('.sa-modal-body');
            if (body) bodyEl.textContent = body; else bodyEl.remove();
            const okBtn = back.querySelector('.ok'), noBtn = back.querySelector('.cancel');
            okBtn.textContent = opts.okLabel || 'OK';
            noBtn.textContent = opts.cancelLabel || 'Cancel';
            const prevFocus = document.activeElement;
            const docs = doc === document ? [document] : [doc, document];
            tracked.add(back);
            function close(val){
                docs.forEach(d => d.removeEventListener('keydown', onKey, true));
                tracked.delete(back);
                if (val) {
                    // Accepted: remove it at once. The form is about to navigate, and a delayed
                    // removal would never run, leaving a dark backdrop over the whole dashboard.
                    back.remove();
                } else {
                    back.classList.remove('show');
                    setTimeout(() => { back.remove(); }, 150);
                }
                try { if (prevFocus && prevFocus.focus) prevFocus.focus(); } catch (e) {}
                resolve(val);
            }
            function onKey(e){
                if (e.key === 'Escape'){ e.preventDefault(); close(false); }
                else if (e.key === 'Tab'){
                    e.preventDefault();
                    (doc.activeElement === okBtn ? noBtn : okBtn).focus();
                }
            }
            okBtn.addEventListener('click', () => close(true));
            noBtn.addEventListener('click', () => close(false));
            back.addEventListener('mousedown', e => { if (e.target === back) close(false); });
            docs.forEach(d => d.addEventListener('keydown', onKey, true));
            doc.body.appendChild(back);
            requestAnimationFrame(() => { back.classList.add('show'); noBtn.focus(); });
        });
    };
})();

function findPBI(){
    for (const w of [window, window.parent, window.top]) {
        try { if (w && w.PBI && typeof w.PBI.confirm === 'function') return w.PBI; } catch (e) { /* cross-origin */ }
    }
    return null;
}

// Submits a form only after the custom confirm modal is accepted.
// Always returns false synchronously so the browser's own submit is blocked;
// HTMLFormElement.prototype.submit bypasses onsubmit so there's no re-trigger loop.
// Full-page "working" indicator so a long archive never looks like a frozen page.
function showBusy(form){
    try {
        const act = ((form.querySelector('input[name="action"]') || {}).value) || '';
        const text = {archive:'Archiving\u2026', restore:'Restoring\u2026', restore_selected:'Restoring\u2026', recover:'Recovering\u2026',
                      delete:'Moving to Recently Deleted\u2026', purge:'Deleting permanently\u2026', empty_trash:'Emptying Recently Deleted\u2026'}[act] || 'Working\u2026';
        let o = document.getElementById('saBusy');
        if (!o) {
            if (!document.getElementById('saBusyStyle')) {
                const st = document.createElement('style'); st.id = 'saBusyStyle';
                st.textContent = '#saBusy{position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,.82);backdrop-filter:blur(2px)}'
                  + '#saBusy .sa-busy-box{display:flex;flex-direction:column;align-items:center;gap:8px;padding:26px 34px;border-radius:16px;background:#fff;border:1px solid #D8E5F4;box-shadow:0 18px 50px rgba(10,30,60,.18);text-align:center}'
                  + '#saBusy b{font-size:16px;color:#102746}#saBusy span{font-size:12.5px;color:#67819E}'
                  + '#saBusy .sa-spin{width:34px;height:34px;border-radius:50%;border:4px solid #D8E5F4;border-top-color:#2563EB;animation:saSpin .8s linear infinite}'
                  + '@keyframes saSpin{to{transform:rotate(360deg)}}';
                document.head.appendChild(st);
            }
            o = document.createElement('div'); o.id = 'saBusy'; o.setAttribute('role', 'status'); o.setAttribute('aria-live', 'polite');
            o.innerHTML = '<div class="sa-busy-box"><div class="sa-spin"></div><b></b><span>This can take a minute. Please keep this page open.</span></div>';
            document.body.appendChild(o);
        }
        o.querySelector('b').textContent = text;
    } catch (e) {}
}
// Coming back with the Back button must never show a stale "working" screen.
window.addEventListener('pageshow', () => { window.__saSubmitting = false; const o = document.getElementById('saBusy'); if (o) o.remove(); });

function confirmSubmit(form, message, opts){
    (async () => {
        let ok;
        try {
            const pbi = findPBI();
            ok = pbi ? await pbi.confirm(message, opts) : await window.saConfirm(message, opts);
        } catch (err) {
            console.error('Dashboard confirm failed, using built-in modal', err);
            ok = await window.saConfirm(message, opts);
        }
        if (ok) {
            if (!form.querySelector('input[name="confirmed"]')) {
                const c = document.createElement('input');
                c.type = 'hidden'; c.name = 'confirmed'; c.value = '1';
                form.appendChild(c);
            }
            if (window.__saSubmitting) return;       // ignore double clicks while one is running
            window.__saSubmitting = true;
            showBusy(form);
            HTMLFormElement.prototype.submit.call(form);
        }
    })();
    return false;
}
function confirmArchive(form){const chosen=periodChecks.filter(c=>c.checked);if(!chosen.length)return false;const n=chosen.length;const list=chosen.map(c=>`• ${c.dataset.label} (${c.dataset.live} evaluation${c.dataset.live==='1'?'':'s'})`).join('\n');return confirmSubmit(form, `Archive ${n===1?'this period':n+' periods'}?\n\n${list}\n\nTheir live evaluation records move to Archived Records and are cleared from the dashboards. Archived periods are set to inactive. You can restore them later from Archived Records.`, {okLabel:'Archive'});}
function confirmRestore(form){return confirmSubmit(form, 'Restore this archive back into the live evaluation tables?\n\nThis is allowed only when the selected period has no live evaluation records.', {okLabel:'Restore', danger:false});}
const archiveChecks=[...document.querySelectorAll('.archive-check')];
const selectAllArchives=document.getElementById('selectAllArchives');
const bulkRestoreBtn=document.getElementById('bulkRestoreBtn');
const bulkRestoreForm=document.getElementById('bulkRestoreForm');
const bulkRestoreInputs=document.getElementById('bulkRestoreInputs');
const selectionCount=document.getElementById('selectionCount');

function archiveRowShown(c){const tr=c.closest('tr');return !tr || tr.style.display!=='none';}

// ── Term / year filter for Archived Records ──
const archiveTermFilter=document.getElementById('archiveTermFilter');
const archiveYearFilter=document.getElementById('archiveYearFilter');
const archiveFilterClear=document.getElementById('archiveFilterClear');
const archiveCountLabel=document.getElementById('archiveCountLabel');
const archiveNoMatch=document.getElementById('archiveNoMatch');
function applyArchiveFilter(){
    const term=archiveTermFilter?archiveTermFilter.value:'';
    const year=archiveYearFilter?archiveYearFilter.value:'';
    const rows=[...document.querySelectorAll('tr.archive-row')];
    let shown=0;
    rows.forEach(tr=>{
        const ok=(!term||tr.dataset.term===term)&&(!year||tr.dataset.year===year);
        tr.style.display=ok?'':'none';
        if(ok) shown++;
        else { const cb=tr.querySelector('.archive-check'); if(cb) cb.checked=false; } // never restore a hidden row by accident
    });
    const total=rows.length, filtered=!!(term||year);
    if(archiveNoMatch) archiveNoMatch.style.display=(filtered&&shown===0)?'':'none';
    if(archiveFilterClear) archiveFilterClear.style.display=filtered?'inline-block':'none';
    if(archiveCountLabel) archiveCountLabel.textContent=filtered?`${shown} of ${total} archive${total===1?'':'s'}`:`${total} archive${total===1?'':'s'}`;
    syncArchiveSelection();
}
if(archiveTermFilter) archiveTermFilter.addEventListener('change',applyArchiveFilter);
if(archiveYearFilter) archiveYearFilter.addEventListener('change',applyArchiveFilter);
if(archiveFilterClear) archiveFilterClear.addEventListener('click',()=>{if(archiveTermFilter)archiveTermFilter.value='';if(archiveYearFilter)archiveYearFilter.value='';applyArchiveFilter();});

function syncArchiveSelection(){
    if(!bulkRestoreBtn || !bulkRestoreForm) return;
    const selected=archiveChecks.filter(c=>c.checked);
    const visible=archiveChecks.filter(archiveRowShown);
    const visibleSelected=visible.filter(c=>c.checked);
    if(selectionCount) selectionCount.textContent=`${selected.length} selected`;
    bulkRestoreBtn.disabled=selected.length===0;
    if(selectAllArchives){
        selectAllArchives.checked=visible.length>0 && visibleSelected.length===visible.length;
        selectAllArchives.indeterminate=visibleSelected.length>0 && visibleSelected.length<visible.length;
    }
    if(bulkRestoreInputs){
        bulkRestoreInputs.innerHTML=selected.map(c=>`<input type="hidden" name="archive_ids[]" value="${c.value}">`).join('');
    }
}
archiveChecks.forEach(c=>c.addEventListener('change',syncArchiveSelection));
if(selectAllArchives){
    selectAllArchives.addEventListener('change',()=>{
        // "Select all" only touches the rows currently shown by the filter.
        archiveChecks.filter(archiveRowShown).forEach(c=>c.checked=selectAllArchives.checked);
        syncArchiveSelection();
    });
}
function confirmBulkRestore(form){
    const selected=archiveChecks.filter(c=>c.checked);
    if(!selected.length) return false;
    return confirmSubmit(form, `Restore ${selected.length} selected archive${selected.length===1?'':'s'}?\n\nEach selected period must have no live evaluation records. Continue?`, {okLabel:'Restore', danger:false});
}
syncArchiveSelection();
function confirmDelete(form, label, status){const d=<?= (int)ARCHIVE_TRASH_DAYS ?>;const risk=status==='archived'?`⚠ This archive has NOT been restored, so it holds the only copy of this evaluation data.\n\n`:`This archive was already restored to the live tables.\n\n`;return confirmSubmit(form, `Are you sure you want to delete the archive for ${label}?\n\n${risk}It will move to Recently Deleted, where you can restore it for ${d} days. After ${d} days it is permanently erased and cannot be recovered.`, {okLabel:'Delete'});}
function confirmRecover(form, label){return confirmSubmit(form, `Restore the deleted archive for ${label}?\n\nIt will return to Archived Records with its original data.`, {okLabel:'Restore', danger:false});}
function confirmPurge(form, label){return confirmSubmit(form, `Permanently delete the archive for ${label}?\n\nThis erases its data now and cannot be undone.`, {okLabel:'Delete Now'});}
function confirmEmptyTrash(form, n){return confirmSubmit(form, `Empty Recently Deleted?\n\n${n} archive${n===1?'':'s'} will be permanently erased. This cannot be undone.`, {okLabel:'Empty'});}
</script>
<script src="admin_ajax.php"></script>
</body>
</html>