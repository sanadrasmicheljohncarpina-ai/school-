<?php
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
require_once dirname(__DIR__) . '/shared/system_settings_service.php';
require_once __DIR__ . '/dean_ea_notices.php';
require_once dirname(__DIR__) . '/shared/ea_personnel_service.php';
require_once 'school_head_structure_gate.php';

// Display-only: drop a trailing timezone label such as "(Asia/Manila)" from
// schedule strings coming from the shared settings service.
if (!function_exists('dean_strip_tz_label')) {
    function dean_strip_tz_label($v) {
        return trim((string)preg_replace('/\s*\(\s*[A-Za-z_]+(?:\/[A-Za-z_\-]+)+\s*\)/', '', (string)$v));
    }
}

// ── AUTH GUARD ────────────────────────────────────────────
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'dean') {
    header("Location: dean_login.php");
    exit;
}

// Faculty/Staff/EA/Student roster reads (ea_get_faculty(), ea_get_staff(),
// ea_get_executive_assistants(), ea_get_students()) live in
// shared/ea_personnel_service.php — see that file's header comment for
// the confirmed schema decisions and still-open TODOs (evaluation_status
// tracking, Faculty "program" field, questionnaire routing).

// ── PULL DEAN PROFILE ─────────────────────────────────────
$stmt = $mysqli->prepare("SELECT full_name, username, email, designation, photo, department FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ── GLOBAL SYSTEM SETTINGS (single source of truth) ─────────────────────
// Everything about "what period is it, what's the status, what's the
// schedule" comes from here. The Dean dashboard never derives or stores
// any of this itself, and never hardcodes an academic year/term.
// (Step 14: Synchronization with System Settings)
$settings = get_school_head_settings($mysqli, 'dean');

// Academic Structure / Academic Term decide which school head's evaluation
// is applicable. Dean owns College; Principal owns School Year. This only
// ever narrows — schedule / Force Open / Force Closed are untouched when
// College is the active structure. See school_head_structure_gate.php.
$settings = sh_gate_apply($settings, 'dean');

// A Dean oversees the Higher Education (internally "college") division.
// If the Executive Assistant has the active Academic Structure set to
// something else, the Dean must not show Higher Ed analytics as current.
$structureActive  = !empty($settings['school_head_applicable']);
$period_id_int    = (int)($settings['period_id'] ?? 0);
$evalOpen         = !empty($settings['school_head_is_open']);

// Treat a period as current only while its evaluation_periods row is active.
// System Archive marks an archived period inactive and clears its live rows;
// this extra check prevents stale system settings from resurrecting its scores
// on the Dean dashboard after the archive completes.
$dashboardCurrentPeriodId = 0;
if ($structureActive && $period_id_int > 0) {
    try {
        // A period that System Archive has archived (and not restored) is never current,
        // even if something flips its is_active flag back on. If the archive table does
        // not exist yet (fresh install), fall back to the plain active-period check.
        $periodCheck = false;
        try {
            $periodCheck = $mysqli->prepare("SELECT ep.id FROM evaluation_periods ep
                WHERE ep.id=? AND ep.is_active=1
                  AND NOT EXISTS (SELECT 1 FROM system_archives sa WHERE sa.period_id=ep.id AND sa.status='archived')
                LIMIT 1");
        } catch (Throwable $archiveCheckError) {
            $periodCheck = false;
        }
        if (!$periodCheck) {
            $periodCheck = $mysqli->prepare("SELECT id FROM evaluation_periods WHERE id=? AND is_active=1 LIMIT 1");
        }
        if ($periodCheck) {
            $periodCheck->bind_param('i', $period_id_int);
            if ($periodCheck->execute()) {
                $currentPeriodRow = $periodCheck->get_result()->fetch_assoc();
                if ($currentPeriodRow) $dashboardCurrentPeriodId = (int)$currentPeriodRow['id'];
            }
            $periodCheck->close();
        }
    } catch (Throwable $periodCheckError) {
        $dashboardCurrentPeriodId = 0;
    }
}
$hasCurrentEvaluationPeriod = $dashboardCurrentPeriodId > 0;

// Step 8: internal value stays "college" everywhere; this is the only
// display label used in markup below.
const HIGHER_ED_LABEL = 'Higher Education';

// ── PERSONNEL ROSTERS (Step 9 / Step 11 — Dashboard cards) ─────────────
// Rosters reflect the Dean's Higher Education headcount and are fetched
// unconditionally — they're informational even when the college period
// isn't the currently active academic structure, so the dashboard never
// has to sit empty (see the personnel-overview branch below). Only the
// evaluation-status math (completed/pending, ratings) depends on the
// active period actually being college and is gated behind $structureActive.
$facultyList = ea_get_faculty($mysqli, $dashboardCurrentPeriodId);
$staffList   = ea_get_staff($mysqli, $dashboardCurrentPeriodId);
$eaList      = ea_get_executive_assistants($mysqli, $dashboardCurrentPeriodId);
$studentList = ea_get_students($mysqli, $dashboardCurrentPeriodId);

$countByStatus = function (array $rows, string $status): int {
    return count(array_filter($rows, fn($r) => ($r['evaluation_status'] ?? '') === $status));
};

$facultyPending = $staffPending = $eaPending = 0;
$facultyCompleted = $staffCompleted = $eaCompleted = 0;
$overallCompletionPct = 0;
$studentParticipationPct = 0;
$totalAssigned = 0;

if ($hasCurrentEvaluationPeriod) {
    $facultyCompleted = $countByStatus($facultyList, 'completed');
    $staffCompleted   = $countByStatus($staffList, 'completed');
    $eaCompleted      = $countByStatus($eaList, 'completed');

    $facultyPending = max(0, count($facultyList) - $facultyCompleted);
    $staffPending   = max(0, count($staffList) - $staffCompleted);
    $eaPending      = max(0, count($eaList) - $eaCompleted);

    $totalAssigned  = count($facultyList) + count($staffList) + count($eaList);
    $totalCompleted = $facultyCompleted + $staffCompleted + $eaCompleted;
    $overallCompletionPct = $totalAssigned > 0 ? (int) round($totalCompleted / $totalAssigned * 100) : 0;

    // Student participation stays informational only — Dean never manages
    // student accounts (Step 7), this is read-only context for the card.
    $studentSubmitted = $countByStatus($studentList, 'submitted');
    $studentParticipationPct = count($studentList) > 0
        ? (int) round($studentSubmitted / count($studentList) * 100)
        : 0;
}

// ── NOTIFICATIONS ─────────────────────────────────────────────────────
// Generic schedule-driven notices come straight from the shared service.
$notifications = $settings['notifications'];

// Executive Assistant notices: academic period and evaluation schedule.
foreach (dean_ea_notices($settings) as $eaNotice) {
    $notifications[] = $eaNotice;
}

// Seed the bell with the latest submitted evaluation events as well as the
// current status notices. The browser poll keeps these events visible.
if ($hasCurrentEvaluationPeriod) {
    $currentPeriodId = $dashboardCurrentPeriodId;
    $collegeLevels = "'1st Year College','2nd Year College','3rd Year College','4th Year College'";
    $recentEvalQ = $mysqli->query("SELECT et.id, et.eval_type, et.submitted_at, target.full_name AS target_name
        FROM evaluation_tracker et
        JOIN users target ON target.id=et.target_user_id
        WHERE et.period_id=$currentPeriodId
          AND et.submitted_at IS NOT NULL
          AND target.is_active=1
          AND target.account_status='approved'
          AND et.target_user_id <> " . (int)($_SESSION['user_id'] ?? 0) . "
          AND (target.role IN ('principal','staff') OR (target.role IN ('teacher','faculty') AND EXISTS (
              SELECT 1 FROM user_year_levels uyl
              WHERE uyl.user_id=target.id AND uyl.year_level IN ($collegeLevels)
          )))
          AND et.eval_type IN ('student','peer','faculty_peer','staff_peer')
        ORDER BY et.submitted_at DESC, et.id DESC
        LIMIT 500");
    if ($recentEvalQ) {
        while ($ev = $recentEvalQ->fetch_assoc()) {
            $kind = in_array($ev['eval_type'] ?? '', ['peer','faculty_peer','staff_peer'], true) ? 'Peer-to-Peer' : 'Student';
            $notifications[] = "New {$kind} evaluation received for " . ($ev['target_name'] ?? 'personnel') . ".";
        }
        $recentEvalQ->free();
    }
}

if ($hasCurrentEvaluationPeriod) {
    if ($evalOpen && ($facultyPending + $staffPending + $eaPending) > 0) {
        $remaining = $facultyPending + $staffPending + $eaPending;
        $notifications[] = "{$remaining} evaluation" . ($remaining === 1 ? '' : 's') . " still pending.";
    }
    if ($totalAssigned > 0 && $overallCompletionPct === 100) {
        $notifications[] = "All Higher Education evaluations are complete.";
    }
}
// (No "structure not active" notice added here — the Personnel Overview
// cards above already stand in for the old empty-state messaging, so this
// doesn't need to also eat a slot in the notification bell.)
if (empty($notifications)) {
    $notifications[] = "No urgent items right now.";
}

// ── YOUR EVALUATION RATING (Phase 2, §7) ────────────────────────────
// The Dean's own average rating this period, from Teacher-submitted
// results. Same eval_type/eval_bucket placeholder convention used in
// dean_results.php — confirm the actual values your evaluation_tracker
// uses for Teacher → Dean submissions and adjust both files together.
const DEAN_RESULT_EVAL_TYPE   = 'teacher';
const DEAN_RESULT_EVAL_BUCKET = 'Dean';
$myRating = null;
if ($hasCurrentEvaluationPeriod) {
    try {
        // bind_param() requires actual variables passed by reference — a
        // class constant or an array element (like $_SESSION['user_id'])
        // can't be passed directly, so copy them into plain local
        // variables first.
        $evalType   = DEAN_RESULT_EVAL_TYPE;
        $evalBucket = DEAN_RESULT_EVAL_BUCKET;
        $deanIdForRating = (int)$_SESSION['user_id'];

        $stmt = $mysqli->prepare("
            SELECT AVG(score) v FROM evaluation_tracker
            WHERE eval_type=? AND eval_bucket=? AND status IN ('submitted','approved')
              AND target_user_id=? AND period_id=?
        ");
        $stmt->bind_param("ssii", $evalType, $evalBucket, $deanIdForRating, $dashboardCurrentPeriodId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $myRating = $row && $row['v'] !== null ? round((float)$row['v'], 2) : null;
    } catch (mysqli_sql_exception $e) {
        $myRating = null;
    }
}
// ── CURRENT-PERIOD RECEIVED-EVALUATION SUMMARY ────────────────────────
// Dashboard headline figures are cycle-specific. Once System Archive marks a
// period inactive, its retained feedback remains available in the results/archive
// workflow but must not count toward the fresh dashboard cycle.
// Count only submitted/approved student school-head reviews and EA reviews for
// the active period, without querying or exposing evaluator identity.
$deanOverallAverage = null;
$deanOverallResponses = 0;
$deanOverallScoreSum = 0.0;
$deanOverallAnswerCount = 0;
$deanSummaryTargetId = (int)($_SESSION['user_id'] ?? 0);

if ($hasCurrentEvaluationPeriod) {
    try {
        $summaryStmt = $mysqli->prepare("
            SELECT COUNT(*) AS response_count,
                   COALESCE(SUM(answer_totals.score_sum), 0) AS score_sum,
                   COALESCE(SUM(answer_totals.score_count), 0) AS score_count
            FROM evaluation_tracker et
            LEFT JOIN (
                SELECT tracker_id, SUM(answer_score) AS score_sum, COUNT(answer_score) AS score_count
                FROM questionnaire_answers
                GROUP BY tracker_id
            ) answer_totals ON answer_totals.tracker_id = et.id
            WHERE ((et.eval_type='student' AND et.evaluation_context='school_head') OR et.eval_type='ea')
              AND et.status IN ('submitted','approved')
              AND et.target_user_id=?
              AND et.period_id=?
        ");
        if ($summaryStmt) {
            $summaryStmt->bind_param('ii', $deanSummaryTargetId, $dashboardCurrentPeriodId);
            $summaryStmt->execute();
            $summaryRow = $summaryStmt->get_result()->fetch_assoc() ?: [];
            $summaryStmt->close();

            $deanOverallResponses += (int)($summaryRow['response_count'] ?? 0);
            $deanOverallScoreSum += (float)($summaryRow['score_sum'] ?? 0);
            $deanOverallAnswerCount += (int)($summaryRow['score_count'] ?? 0);
        }
    } catch (Throwable $summaryError) {
        // Keep the dashboard usable on older installations with optional schema differences.
    }

    // Retained (archived) feedback is deliberately NOT added back here. It stays
    // readable on the Evaluation Received page, but the dashboard figures only
    // reflect the live, active evaluation cycle so they reset after an archive.
}

$deanOverallAverage = $deanOverallAnswerCount > 0
    ? round($deanOverallScoreSum / $deanOverallAnswerCount, 2)
    : null;

// Performance Level uses the same bands and wording as the Rating Legend on the reports.
// A fresh/empty cycle has no performance rating yet.
if ($deanOverallAverage === null) {
    $deanPerformanceLabel = 'No Ratings Yet';
    $deanPerformanceClass = 'neutral';
} elseif ($deanOverallAverage >= 4.5) {
    $deanPerformanceLabel = 'Very Satisfied';
    $deanPerformanceClass = 'very-satisfied';
} elseif ($deanOverallAverage >= 3.5) {
    $deanPerformanceLabel = 'Satisfied';
    $deanPerformanceClass = 'satisfied';
} elseif ($deanOverallAverage >= 2.5) {
    $deanPerformanceLabel = 'Neither Satisfied nor Dissatisfied';
    $deanPerformanceClass = 'neither';
} elseif ($deanOverallAverage >= 1.5) {
    $deanPerformanceLabel = 'Dissatisfied';
    $deanPerformanceClass = 'dissatisfied';
} else {
    $deanPerformanceLabel = 'Very Dissatisfied';
    $deanPerformanceClass = 'very-dissatisfied';
}

$pendingEvaluationsTotal = $facultyPending + $staffPending + $eaPending;

$mysqli->close();
$photo_src = !empty($me['photo']) ? '../image/' . $me['photo'] : '../image/pbi_logo';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>PBI — Dean Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<style>
:root{--page-l:#F5F8FC;--card-l:#FFFFFF;--line-l:#DCE7F1;--line-strong-l:#9FB2C3;--text-l:#12263A;--muted-l:#6D8194;--shadow-l:0 4px 16px rgba(28,64,92,.09);--input-l:#F7FAFC;--dark:#0A192F;--mid:#172A45;--inner:#0F1F3D;--violet:#7C5FD9;--violet-h:#9C85F0;--violet-dark:#5F45B8;--light:#E0E6F0;--muted:#A0B3C6;--radius:10px;--shadow:0 8px 32px rgba(0,0,0,0.45);--danger:#f05454;--good:#10B981;}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{min-height:100vh;background:var(--page-l);font-family:'DM Sans',sans-serif;color:var(--text-l);display:flex;}

.sidebar{width:250px;flex-shrink:0;background:#0F1F33;border-right:1px solid #060E18;min-height:100vh;padding:28px 20px;display:flex;flex-direction:column;}
.sb-profile{text-align:center;margin-bottom:26px;}
.sb-photo{width:72px;height:72px;border-radius:50%;object-fit:cover;border:2.5px solid var(--violet);box-shadow:0 0 18px rgba(124,95,217,.4);margin:0 auto 10px;display:block;}
.sb-name{font-weight:700;font-size:15px;color:#fff;}
.sb-role{font-size:11px;color:var(--violet-h);text-transform:uppercase;letter-spacing:.6px;margin-top:2px;}
.sb-scope{font-size:10px;color:var(--muted);margin-top:4px;}
.sb-nav{display:flex;flex-direction:column;gap:4px;margin-top:10px;}
.sb-nav a{display:flex;align-items:center;gap:10px;padding:11px 14px;border-radius:8px;color:var(--muted);text-decoration:none;font-size:14px;font-weight:500;transition:background .2s,color .2s;}
.sb-nav a:hover,.sb-nav a.active{background:rgba(124,95,217,.15);color:#fff;}
.sb-nav a i{width:18px;text-align:center;color:var(--violet-h);}
.sb-logout{margin-top:auto;}
.sb-logout a{display:flex;align-items:center;gap:10px;padding:11px 14px;border-radius:8px;color:#fca5a5;text-decoration:none;font-size:14px;font-weight:500;transition:background .2s;}
.sb-logout a:hover{background:rgba(240,84,84,.12);}

.main{flex:1;padding:36px 44px;}
.page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:28px;flex-wrap:wrap;gap:14px;}
.page-title{font-family:'Rajdhani',sans-serif;font-size:28px;font-weight:700;color:var(--text-l);letter-spacing:1px;}
.page-sub{font-size:13px;color:var(--muted-l);margin-top:4px;}
.card-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:30px;}
.stat-card-link{display:block;text-decoration:none;color:inherit;min-width:0;}
.stat-card{background:var(--card-l);border:1px solid var(--line-l);border-radius:14px;padding:20px;box-shadow:var(--shadow-l);}
.stat-card i{color:var(--violet-dark);font-size:20px;margin-bottom:10px;}
.stat-card .num{font-size:26px;font-weight:700;color:var(--text-l);}
.stat-card .label{font-size:12px;color:var(--muted-l);margin-top:4px;}

.section{background:var(--card-l);border:1px solid var(--line-l);border-radius:14px;padding:24px;box-shadow:var(--shadow-l);margin-bottom:26px;}
.section h2{font-family:'Rajdhani',sans-serif;font-size:19px;color:var(--text-l);margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.section h2 i{color:var(--violet-dark);font-size:16px;}

.bar-wrap{background:var(--input-l);border-radius:6px;height:8px;width:100%;overflow:hidden;}
.bar-fill{height:100%;background:linear-gradient(90deg,var(--violet-dark),var(--violet-h));border-radius:6px;}
.pill{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;}
.pill.good{background:rgba(16,185,129,.14);color:var(--good);}
.pill.warn{background:rgba(124,95,217,.14);color:var(--violet-dark);}
.pill.bad{background:rgba(240,84,84,.12);color:#fca5a5;}

.two-col{display:grid;grid-template-columns:1fr 1fr;gap:20px;}


.notif-list{list-style:none;font-size:13px;}
.notif-list li{padding:10px 12px;border-radius:8px;background:var(--input-l);margin-bottom:8px;display:flex;align-items:center;gap:10px;}
.notif-list li i{color:var(--violet-dark);}
.notif-list li.notif-evaluation i{color:var(--good);}
.notif-list li:last-child{margin-bottom:0;}

.qa-btns{display:flex;flex-wrap:wrap;gap:10px;}

.period-badge{background:rgba(124,95,217,.14);border:1px solid rgba(124,95,217,.3);color:var(--violet-dark);padding:6px 14px;border-radius:20px;font-size:12px;font-weight:600;display:flex;align-items:center;gap:7px;}
.period-badge.closed{background:rgba(240,84,84,.1);border-color:rgba(240,84,84,.3);color:#fca5a5;}
.period-badge.scheduled{background:rgba(217,154,43,.12);border-color:rgba(217,154,43,.28);color:#d49a2a;}
.period-badge.amber{background:rgba(217,119,6,.14);border-color:rgba(217,119,6,.3);color:#fbbf24;}
.period-badge.gray{background:var(--input-l);border-color:var(--line-l);color:var(--muted-l);}
.empty-note{color:var(--muted-l);font-size:13px;font-style:italic;}

.period-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.15fr) minmax(0,1fr) minmax(0,1fr) minmax(0,1.6fr) minmax(0,1.6fr);gap:16px 24px;align-items:start;margin-bottom:16px;}
.period-field{min-width:0;}
.period-field .period-field-label{white-space:nowrap;}
.period-field .period-badge{display:inline-flex;width:fit-content;padding:6px 18px;}
.period-field .period-field-value.period-date{white-space:nowrap;}
@media(max-width:1280px){.period-grid{grid-template-columns:repeat(3,minmax(0,1fr));}.period-field .period-field-value.period-date{white-space:normal;}}
@media(max-width:600px){.period-grid{grid-template-columns:repeat(2,minmax(0,1fr));}}
.period-field .period-field-label{font-size:11px;font-weight:700;color:var(--muted-l);text-transform:uppercase;letter-spacing:.6px;margin-bottom:5px;}
.period-field .period-field-value{font-size:16px;font-weight:700;color:var(--text-l);}
.period-message{font-size:13px;color:var(--muted-l);padding-top:14px;border-top:1px solid var(--line-l);}
.period-message strong{color:var(--text-l);}

.section-header-row{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:16px;}
.section-header-row h2{margin-bottom:0;}

.notif-bell-wrap{position:relative;}
.notif-bell{background:rgba(124,95,217,.12);border:1px solid rgba(124,95,217,.35);color:var(--violet-dark);width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;cursor:pointer;position:relative;transition:background .2s;}
.notif-bell:hover{background:rgba(124,95,217,.22);}
.notif-bell i{font-size:15px;}
.notif-badge{position:absolute;top:-6px;right:-6px;background:var(--danger);color:#fff;font-size:10px;font-weight:700;min-width:18px;height:18px;border-radius:9px;display:none;align-items:center;justify-content:center;padding:0 4px;border:2px solid var(--card-l);}

.notif-dropdown{position:absolute;top:calc(100% + 10px);right:0;width:320px;max-height:360px;overflow-y:auto;background:var(--input-l);border:1px solid var(--line-l);border-radius:12px;box-shadow:var(--shadow-l);padding:14px;z-index:50;opacity:0;visibility:hidden;transform:translateY(-6px);transition:opacity .15s,transform .15s,visibility .15s;}
.notif-dropdown.open{opacity:1;visibility:visible;transform:translateY(0);}
.notif-dropdown-header{display:flex;align-items:center;justify-content:space-between;font-size:11px;font-weight:700;color:var(--muted-l);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;padding-bottom:10px;border-bottom:1px solid var(--line-l);}
.notif-live-dot{width:7px;height:7px;border-radius:50%;background:var(--good);box-shadow:0 0 0 0 rgba(16,185,129,.6);animation:notifPulse 2s infinite;}
.notif-live-dot.stale{background:var(--muted-l);animation:none;box-shadow:none;}
@keyframes notifPulse{0%{box-shadow:0 0 0 0 rgba(16,185,129,.55);}70%{box-shadow:0 0 0 7px rgba(16,185,129,0);}100%{box-shadow:0 0 0 0 rgba(16,185,129,0);}}

.countdown-row{display:flex;gap:14px;margin-top:14px;}
.countdown-box{flex:1;background:var(--input-l);border:1px solid var(--line-l);border-radius:10px;padding:12px;text-align:center;}
.countdown-box .num{font-size:22px;font-weight:700;color:var(--text-l);}
.countdown-box .lbl{font-size:10px;color:var(--muted-l);text-transform:uppercase;letter-spacing:.5px;margin-top:2px;}

.stub-note{font-size:11px;color:var(--violet-dark);background:rgba(124,95,217,.08);border:1px dashed rgba(124,95,217,.35);border-radius:8px;padding:8px 12px;margin-top:10px;}

@media(max-width:1200px){.card-grid{grid-template-columns:repeat(3,minmax(0,1fr));}}
@media(max-width:900px){.two-col{grid-template-columns:1fr;}.card-grid{grid-template-columns:repeat(2,minmax(0,1fr));}}
@media(max-width:560px){.card-grid{grid-template-columns:1fr;}}
@media(max-width:768px){body{flex-direction:column;}.sidebar{width:100%;min-height:auto;}}

/* ================================================================
   DEAN SIDEBAR — FINAL FACULTY-MATCHED SHELL
   Kept inline on the dashboard so the shared sidebar cannot be
   overridden by the dashboard's legacy .sidebar rules.
   ================================================================ */
:root { --sidebar-w:248px; --role-accent:#2563EB; --role-accent-light:#60A5FA; }
.sidebar {
  width:var(--sidebar-w) !important;
  flex:0 0 var(--sidebar-w) !important;
  min-height:100vh !important;
  padding:0 !important;
  background:#0A192F !important;
  background-image:none !important;
  border-right:1px solid #172A45 !important;
  box-shadow:6px 0 20px rgba(0,0,0,.16) !important;
  overflow:hidden !important;
  display:flex !important;
  flex-direction:column !important;
}
.sidebar-brand.portal-brand {
  min-height:68px !important;
  padding:14px 17px !important;
  margin:0 !important;
  border-bottom:1px solid rgba(255,255,255,.08) !important;
  display:flex !important;
  align-items:center !important;
  gap:11px !important;
  background:transparent !important;
}
.portal-brand-logo {
  width:38px !important; height:38px !important; flex:0 0 38px !important;
  border-radius:11px !important; display:flex !important; align-items:center !important;
  justify-content:center !important; overflow:hidden !important;
  background:#0F1F3D !important; border:1px solid #2563EB !important;
  box-shadow:0 0 14px rgba(37,99,235,.18) !important;
}
.portal-brand-logo img { width:100% !important; height:100% !important; object-fit:cover !important; display:block !important; }
.portal-brand-copy { min-width:0 !important; display:flex !important; flex-direction:column !important; line-height:1.2 !important; }
.portal-brand-copy strong { font-family:'Rajdhani','DM Sans',sans-serif !important; font-size:15px !important; font-weight:700 !important; color:#F8FAFC !important; letter-spacing:.25px !important; white-space:nowrap !important; }
.portal-brand-copy span { margin-top:3px !important; font-size:10px !important; color:#8FA6BF !important; font-weight:600 !important; letter-spacing:.15px !important; white-space:nowrap !important; }
.portal-sidebar-profile {
  padding:18px 16px 17px !important; margin:0 !important; text-align:center !important;
  border-bottom:1px solid rgba(255,255,255,.08) !important; background:transparent !important;
}
.portal-profile-avatar-wrap { margin:0 auto 11px !important; display:flex !important; justify-content:center !important; }
.portal-profile-avatar {
  width:64px !important; height:64px !important; max-width:64px !important; max-height:64px !important;
  border-radius:50% !important; object-fit:cover !important; border:2px solid #2563EB !important;
  background:#0F1F3D !important; box-shadow:0 0 15px rgba(37,99,235,.18) !important; display:block !important; flex:none !important;
}
.portal-profile-fallback {
  color:#60A5FA !important; font-family:'Rajdhani','DM Sans',sans-serif !important;
  font-size:17px !important; font-weight:700 !important; align-items:center !important; justify-content:center !important;
}
.portal-profile-name { color:#F8FAFC !important; font-size:13px !important; line-height:1.35 !important; font-weight:700 !important; word-break:break-word !important; }
.portal-profile-role { margin-top:3px !important; color:#8FA6BF !important; font-size:10px !important; text-transform:uppercase !important; letter-spacing:.7px !important; font-weight:700 !important; line-height:1.35 !important; }
.portal-profile-scope { margin-top:4px !important; color:#8FA6BF !important; font-size:10px !important; line-height:1.35 !important; font-weight:600 !important; }
.portal-sidebar-nav { flex:1 1 auto !important; min-height:0 !important; padding:16px 10px !important; margin:0 !important; overflow-y:auto !important; display:flex !important; flex-direction:column !important; gap:0 !important; }
.portal-sidebar-nav .nav-section-label { padding:0 8px !important; margin:0 0 7px !important; font-size:9.5px !important; font-weight:800 !important; letter-spacing:1.25px !important; text-align:left !important; color:#8FA6BF !important; line-height:1.3 !important; }
.portal-sidebar-nav .nav-section-label.sidebar-section-secondary { margin-top:17px !important; }
.portal-sidebar-nav .nav-link {
  min-height:40px !important; width:auto !important; margin:2px 2px !important; padding:9px 11px !important;
  border:0 !important; border-left:0 !important; border-radius:8px !important; gap:10px !important;
  color:#CBD8E8 !important; background:transparent !important; font-size:13px !important; font-weight:500 !important;
  line-height:1.2 !important; text-decoration:none !important; display:flex !important; align-items:center !important; box-shadow:none !important; transform:none !important;
}
.portal-sidebar-nav .nav-link i { width:18px !important; min-width:18px !important; font-size:14px !important; color:#8FA6BF !important; text-align:center !important; flex:0 0 18px !important; }
.portal-sidebar-nav .nav-link:hover { background:rgba(255,255,255,.07) !important; color:#FFFFFF !important; }
.portal-sidebar-nav .nav-link:hover i { color:#60A5FA !important; }
.portal-sidebar-nav .nav-link.active {
  background:linear-gradient(90deg,rgba(37,99,235,.18),rgba(255,255,255,.025)) !important;
  color:#FFFFFF !important; font-weight:700 !important; border:0 !important;
  box-shadow:inset 3px 0 0 #60A5FA !important;
}
.portal-sidebar-nav .nav-link.active i { color:#60A5FA !important; }
.sidebar-footer { flex:0 0 auto !important; padding:13px 14px 15px !important; margin:0 !important; border-top:1px solid rgba(255,255,255,.08) !important; background:transparent !important; }
.btn-logout-side {
  width:100% !important; min-height:40px !important; display:flex !important; align-items:center !important; gap:10px !important;
  padding:9px 11px !important; border-radius:8px !important; color:#FCA5A5 !important; text-decoration:none !important;
  font-size:13px !important; font-weight:600 !important; border:1px solid rgba(248,113,113,.28) !important; background:rgba(248,113,113,.08) !important; box-sizing:border-box !important;
}
.btn-logout-side i { width:18px !important; min-width:18px !important; text-align:center !important; flex:0 0 18px !important; color:#FF8E8E !important; }
@media(max-width:768px){ .sidebar{width:248px !important; flex:0 0 248px !important;} }

/* ================================================================
   DEAN DASHBOARD — DARK MODE CONTENT
   Uses the same localStorage preference as the Dean settings page.
   The shared sidebar toggles html.dark-theme; these rules extend that
   theme across the dashboard workspace itself.
   ================================================================ */
html.dark-theme {
  --page-l:#0A192F !important;
  --card-l:#172A45 !important;
  --line-l:rgba(255,255,255,.08) !important;
  --line-strong-l:rgba(255,255,255,.14) !important;
  --text-l:#E0E6F0 !important;
  --muted-l:#A0B3C6 !important;
  --shadow-l:0 8px 26px rgba(0,0,0,.28) !important;
  --input-l:#0F1F3D !important;
  --violet-dark:#9C85F0 !important;
}
html.dark-theme body {
  background:#0A192F !important;
  color:#E0E6F0 !important;
  color-scheme:dark !important;
}
html.dark-theme .main {
  background:#0A192F !important;
  color:#E0E6F0 !important;
}
html.dark-theme .page-title { color:#F8FAFC !important; }
html.dark-theme .page-sub { color:#A0B3C6 !important; }
html.dark-theme .section,
html.dark-theme .stat-card {
  background:#172A45 !important;
  color:#E0E6F0 !important;
  border-color:rgba(255,255,255,.08) !important;
  box-shadow:0 8px 26px rgba(0,0,0,.28) !important;
}
html.dark-theme .section h2 { color:#F8FAFC !important; }
html.dark-theme .section h2 i,
html.dark-theme .stat-card i { color:#9C85F0 !important; }
html.dark-theme .period-field .period-field-label,
html.dark-theme .stat-card .label,
html.dark-theme .empty-note,
html.dark-theme .period-message,
html.dark-theme .countdown-box .lbl {
  color:#A0B3C6 !important;
}
html.dark-theme .period-field .period-field-value,
html.dark-theme .stat-card .num,
html.dark-theme .period-message strong {
  color:#F8FAFC !important;
}
html.dark-theme .period-message { border-top-color:rgba(255,255,255,.08) !important; }
html.dark-theme .bar-wrap,
html.dark-theme .countdown-box,
html.dark-theme .notif-list li,
html.dark-theme .notif-dropdown {
  background:#0F1F3D !important;
  border-color:rgba(255,255,255,.08) !important;
  color:#E0E6F0 !important;
}
html.dark-theme .countdown-box .num { color:#F8FAFC !important; }
html.dark-theme .period-badge {
  background:rgba(156,133,240,.14) !important;
  border-color:rgba(156,133,240,.30) !important;
  color:#C4B5FD !important;
}
html.dark-theme .period-badge.closed {
  background:rgba(240,84,84,.10) !important;
  border-color:rgba(240,84,84,.30) !important;
  color:#FCA5A5 !important;
}
html.dark-theme .period-badge.scheduled,
html.dark-theme .period-badge.amber { color:#FBBF24 !important; }
html.dark-theme .period-badge.gray {
  background:#0F1F3D !important;
  border-color:rgba(255,255,255,.08) !important;
  color:#A0B3C6 !important;
}
html.dark-theme .notif-bell {
  background:rgba(156,133,240,.14) !important;
  border-color:rgba(156,133,240,.34) !important;
  color:#C4B5FD !important;
}
html.dark-theme .notif-bell:hover { background:rgba(156,133,240,.22) !important; }
html.dark-theme .notif-dropdown-header {
  color:#A0B3C6 !important;
  border-bottom-color:rgba(255,255,255,.08) !important;
}
html.dark-theme .notif-list li i { color:#C4B5FD !important; }
html.dark-theme .notif-badge { border-color:#172A45 !important; }
html.dark-theme .stub-note {
  background:rgba(156,133,240,.10) !important;
  border-color:rgba(156,133,240,.30) !important;
  color:#C4B5FD !important;
}
html.dark-theme .stub-note code { color:#E0E6F0 !important; }
html.dark-theme a.stat-card-link:hover .stat-card {
  border-color:rgba(156,133,240,.32) !important;
  transform:translateY(-1px);
}


/* Faculty-inspired Dean dashboard overview and four headline cards. */
.dean-dashboard-shell{
    width:100%;min-width:0;min-height:calc(100vh - 108px);padding:22px 28px 30px;
    display:flex;flex-direction:column;
    background:linear-gradient(180deg,#FFFFFF 0%,#F8FAFD 100%);
    border:1px solid #E1E8F0;border-radius:22px;
    box-shadow:0 10px 28px rgba(19,38,63,.045);
}
.dean-dashboard-intro{margin:2px 2px 18px;}
.dean-dashboard-kicker{display:flex;align-items:center;gap:7px;margin-bottom:5px;color:#D97706;font-size:11px;font-weight:800;letter-spacing:1.2px;text-transform:uppercase;}
.dean-dashboard-intro h1{margin:0;font-family:'Rajdhani','DM Sans',sans-serif;font-size:28px;line-height:1.05;font-weight:800;letter-spacing:.2px;color:#10243E;}
.dean-dashboard-intro p{margin-top:7px;font-size:13px;line-height:1.5;color:#5C7187;}
.dean-dashboard-status{display:inline-flex;align-items:center;gap:7px;margin-top:14px;padding:6px 12px;border-radius:20px;background:#E8FFF1;border:1px solid #91E7B2;color:#168447;font-size:11.5px;font-weight:700;}
.dean-dashboard-status.closed{background:#FFF1F1;border-color:#F3B0B0;color:#B42318;}
.dean-status-row{display:flex;align-items:center;flex-wrap:wrap;gap:10px;margin-top:14px;}
.dean-status-row .dean-dashboard-status{margin-top:0;}
.dean-open-cd{display:inline-flex;align-items:baseline;gap:5px;font-size:11.5px;font-weight:700;color:#B42318;}
.dean-open-cd-sep{color:#F3B0B0;margin-right:3px;font-weight:400;}
.dean-open-cd .dean-cd-unit[hidden]{display:none;}
.dean-open-cd .dean-cd-unit b{font-weight:800;font-variant-numeric:tabular-nums;color:inherit;}
html.dark-theme .dean-open-cd,html.dark-theme .dean-open-cd .dean-cd-unit b{color:#FCA5A5;}
html.dark-theme .dean-open-cd-sep{color:#7F3A3A;}
html.dark-theme html.dark-theme html.dark-theme html.dark-theme html.dark-theme .dean-schedule-card{background:#FFFFFF;border:1px solid rgba(30,82,144,.13);border-radius:16px;padding:22px 26px;margin:0 0 22px;box-shadow:0 5px 18px rgba(30,82,144,.04);}
.dean-schedule-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:20px;align-items:start;}
.dean-schedule-item{min-width:0;}
.dean-schedule-label{display:block;margin-bottom:7px;color:#D88900;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;}
.dean-schedule-value{display:block;color:#10243E;font-size:15px;font-weight:700;line-height:1.35;overflow-wrap:anywhere;}
.dean-schedule-status{display:inline-flex;align-items:center;padding:6px 14px;border-radius:999px;border:1px solid #91E7B2;background:#E8FFF1;color:#168447;font-size:12px;font-weight:700;}
.dean-schedule-status.closed{border-color:#F3B0B0;background:#FFF1F1;color:#B42318;}
.dean-schedule-extras{margin-top:17px;padding-top:14px;border-top:1px solid rgba(30,82,144,.10);}
.dean-schedule-extras .period-message{padding-top:0;border-top:0;}
.dean-welcome-bar{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;padding:24px 28px;margin:0 0 20px;border:1px solid rgba(37,99,235,.20);border-radius:17px;background:linear-gradient(135deg,#FFFFFF 0%,#F5F9FF 100%);box-shadow:0 5px 18px rgba(37,99,235,.05);}
.dean-welcome-text{min-width:0;}
.dean-welcome-text h2{margin:0 0 5px;color:#11263F;font-family:'Rajdhani','DM Sans',sans-serif;font-size:23px;font-weight:800;letter-spacing:.2px;}
.dean-welcome-text p{color:#5E7388;font-size:13px;line-height:1.5;}
.dean-welcome-text p strong{color:#2563EB;font-weight:700;}
.dean-score-chip{display:flex;flex:1 1 230px;align-self:stretch;flex-direction:column;align-items:center;justify-content:center;max-width:340px;min-width:230px;padding:16px 26px;border:1px solid #CADBFF;border-radius:15px;background:#F0F5FF;text-align:center;}
.dean-score-value{color:#2563EB;font-family:'Rajdhani','DM Sans',sans-serif;font-size:39px;font-weight:800;line-height:1;}
.dean-score-label{margin-top:8px;color:#66798D;font-size:11px;letter-spacing:1px;text-transform:uppercase;}
.dean-faculty-stats-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin:0 0 26px;}
.dean-faculty-stat{position:relative;min-width:0;overflow:hidden;padding:18px 20px 20px;background:#FFFFFF;border:1px solid #E0E7EF;border-radius:15px;box-shadow:0 6px 18px rgba(20,42,67,.045);}
.dean-faculty-stat::before{position:absolute;top:0;left:0;right:0;height:3px;background:#2563EB;content:'';}
.dean-faculty-stat:nth-child(2)::before{background:#10B981;}
.dean-faculty-stat:nth-child(3)::before{background:#F59E0B;}
.dean-faculty-stat:nth-child(4)::before{background:#3B82F6;}
.dean-faculty-stat-icon{display:flex;align-items:center;justify-content:center;width:36px;height:36px;margin-bottom:12px;border-radius:10px;font-size:15px;}
.dean-faculty-stat:nth-child(1) .dean-faculty-stat-icon{background:#E6EEFF;color:#2563EB;}
.dean-faculty-stat:nth-child(2) .dean-faculty-stat-icon{background:#E5F9ED;color:#10B981;}
.dean-faculty-stat:nth-child(3) .dean-faculty-stat-icon{background:#FFF4D8;color:#D88900;}
.dean-faculty-stat:nth-child(4) .dean-faculty-stat-icon{background:#E5F0FF;color:#3478E5;}
.dean-faculty-stat-label{margin-bottom:12px;color:#5E7388;font-size:10.5px;font-weight:700;letter-spacing:.9px;text-transform:uppercase;}
.dean-faculty-stat-value{color:#12263F;font-size:28px;font-weight:800;line-height:1.1;overflow-wrap:anywhere;}
.dean-faculty-stat:nth-child(1) .dean-faculty-stat-value{color:#2563EB;}
.dean-faculty-stat:nth-child(2) .dean-faculty-stat-value{color:#D88900;}
.dean-faculty-stat-value.compact{font-size:21px;}
.dean-operational-heading{margin:2px 2px 14px;}
.dean-operational-heading h2{margin:0 0 4px;color:#13263F;font-family:'Rajdhani','DM Sans',sans-serif;font-size:20px;font-weight:800;}
.dean-operational-heading p{color:#6D8194;font-size:12.5px;line-height:1.5;}

/* Expanded overview shortcuts, matching Faculty/Staff while retaining the Dean violet accent. */
.dean-dashboard-quick-access{margin-top:auto;padding-top:22px;}
.dean-quick-access-heading{display:flex;align-items:end;justify-content:space-between;gap:16px;margin:0 2px 12px;}
.dean-quick-access-kicker{display:block;margin-bottom:3px;color:#7C5FD9;font-size:10px;font-weight:800;letter-spacing:1.15px;}
.dean-quick-access-heading h2{margin:0;color:#13263F;font-family:'Rajdhani','DM Sans',sans-serif;font-size:20px;line-height:1.15;font-weight:800;}
.dean-quick-access-caption{color:#6D8194;font-size:11px;padding-bottom:2px;}
.dean-quick-access-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;}
.dean-quick-access-card{min-width:0;min-height:92px;display:flex;align-items:center;gap:12px;padding:15px 16px;text-decoration:none;color:inherit;border:1px solid #DEE7F2;border-radius:14px;background:#FFFFFF;box-shadow:0 4px 14px rgba(19,38,63,.035);transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease;}
.dean-quick-access-card:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(19,38,63,.08);border-color:#C9BDF5;}
.dean-quick-access-icon{width:42px;height:42px;flex:0 0 42px;display:inline-flex;align-items:center;justify-content:center;border-radius:12px;font-size:17px;}
.dean-quick-violet .dean-quick-access-icon{background:#F0EBFF;color:#6D4CC3;}
.dean-quick-blue .dean-quick-access-icon{background:#EAF1FF;color:#2563EB;}
.dean-quick-amber .dean-quick-access-icon{background:#FFF4DB;color:#D97706;}
.dean-quick-access-copy{min-width:0;flex:1;display:flex;flex-direction:column;gap:4px;}
.dean-quick-access-copy strong{color:#12263F;font-size:13px;font-weight:700;line-height:1.3;}
.dean-quick-access-copy small{color:#6D8194;font-size:11.5px;line-height:1.45;}
.dean-quick-access-arrow{flex:0 0 auto;color:#9C85F0;font-size:12px;transition:transform .18s ease;}
.dean-quick-access-card:hover .dean-quick-access-arrow{transform:translateX(3px);}
html.dark-theme .dean-quick-access-heading h2{color:#F8FAFC;}
html.dark-theme .dean-quick-access-caption{color:#A0B3C6;}
html.dark-theme .dean-quick-access-card{background:#172A45;border-color:rgba(255,255,255,.08);box-shadow:0 8px 26px rgba(0,0,0,.22);}
html.dark-theme .dean-quick-access-card:hover{border-color:rgba(156,133,240,.45);}
html.dark-theme .dean-quick-access-copy strong{color:#F8FAFC;}
html.dark-theme .dean-quick-access-copy small{color:#A0B3C6;}
html.dark-theme .dean-quick-violet .dean-quick-access-icon{background:rgba(156,133,240,.15);color:#C4B5FD;}
html.dark-theme .dean-quick-blue .dean-quick-access-icon{background:rgba(37,99,235,.16);color:#93C5FD;}
html.dark-theme .dean-quick-amber .dean-quick-access-icon{background:rgba(245,158,11,.15);color:#FCD34D;}
@media(max-width:900px){.dean-quick-access-grid{grid-template-columns:1fr 1fr;}}
@media(max-width:600px){.dean-quick-access-grid{grid-template-columns:1fr;}.dean-quick-access-heading{align-items:flex-start;flex-direction:column;gap:4px;}}

html.dark-theme .dean-dashboard-shell{background:linear-gradient(180deg,#0F1F3D 0%,#0A192F 100%);border-color:rgba(255,255,255,.08);box-shadow:0 10px 28px rgba(0,0,0,.2);}
html.dark-theme .dean-dashboard-intro h1,html.dark-theme .dean-operational-heading h2{color:#F8FAFC;}
html.dark-theme .dean-dashboard-intro p,html.dark-theme .dean-operational-heading p{color:#A0B3C6;}
html.dark-theme .dean-schedule-card,html.dark-theme .dean-faculty-stat{background:#172A45;border-color:rgba(255,255,255,.08);box-shadow:0 8px 26px rgba(0,0,0,.22);}
html.dark-theme .dean-schedule-value,html.dark-theme .dean-welcome-text h2,html.dark-theme .dean-faculty-stat-value{color:#F8FAFC;}
html.dark-theme .dean-schedule-extras{border-top-color:rgba(255,255,255,.08);}
html.dark-theme .dean-welcome-bar{background:linear-gradient(135deg,#172A45 0%,#0F1F3D 100%);border-color:rgba(96,165,250,.25);}
html.dark-theme .dean-welcome-text p,html.dark-theme .dean-faculty-stat-label{color:#A0B3C6;}
html.dark-theme .dean-score-chip{background:#0F1F3D;border-color:rgba(96,165,250,.25);}
html.dark-theme .dean-score-label{color:#A0B3C6;}
html.dark-theme .dean-faculty-stat:nth-child(1) .dean-faculty-stat-icon{background:rgba(37,99,235,.16);color:#93C5FD;}
html.dark-theme .dean-faculty-stat:nth-child(2) .dean-faculty-stat-icon{background:rgba(16,185,129,.15);color:#6EE7B7;}
html.dark-theme .dean-faculty-stat:nth-child(3) .dean-faculty-stat-icon{background:rgba(245,158,11,.15);color:#FCD34D;}
html.dark-theme .dean-faculty-stat:nth-child(4) .dean-faculty-stat-icon{background:rgba(59,130,246,.15);color:#93C5FD;}
html.dark-theme .dean-schedule-status{background:rgba(16,185,129,.14);border-color:rgba(16,185,129,.30);color:#6EE7B7;}
html.dark-theme .dean-schedule-status.closed{background:rgba(240,84,84,.12);border-color:rgba(240,84,84,.30);color:#FCA5A5;}
@media(max-width:1200px){.dean-faculty-stats-grid{grid-template-columns:repeat(2,minmax(0,1fr));}.dean-schedule-grid{grid-template-columns:repeat(2,minmax(0,1fr));}}
@media(max-width:900px){.dean-dashboard-shell{padding:18px 16px 34px;border-radius:16px;}.dean-schedule-card{padding:18px 20px;}}
@media(max-width:600px){.dean-faculty-stats-grid{grid-template-columns:1fr 1fr;}.dean-schedule-grid{grid-template-columns:1fr 1fr;gap:16px;}.dean-welcome-bar{padding:18px 20px;}.dean-welcome-text h2{font-size:20px;}.dean-score-chip{min-width:0;max-width:none;flex-basis:100%;}.dean-faculty-stat{padding:16px 14px;}.dean-faculty-stat-value{font-size:23px;}.dean-faculty-stat-value.compact{font-size:18px;}}
@media(max-width:420px){.dean-faculty-stats-grid{grid-template-columns:1fr;}.dean-schedule-grid{grid-template-columns:1fr 1fr;}.dean-dashboard-intro h1{font-size:25px;}}

/* Faculty-style all-time evaluation summary, using the Dean's violet theme. */
.overall-summary-heading{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;flex-wrap:wrap;margin-bottom:16px;}
.overall-summary-heading h2{margin-bottom:6px;}
.overall-summary-sub{font-size:12.5px;line-height:1.55;color:var(--muted-l);}
.overall-summary-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;}
.overall-summary-tile{display:flex;align-items:center;gap:14px;min-width:0;padding:18px;background:var(--input-l);border:1px solid var(--line-l);border-radius:12px;}
.overall-summary-icon{display:flex;align-items:center;justify-content:center;flex:0 0 46px;width:46px;height:46px;border-radius:12px;background:rgba(124,95,217,.12);color:var(--violet-dark);font-size:19px;}
.overall-summary-label{font-size:11px;color:var(--muted-l);text-transform:uppercase;letter-spacing:.07em;font-weight:700;margin-bottom:5px;}
.overall-summary-value{font-family:'Rajdhani',sans-serif;font-size:30px;font-weight:700;color:var(--text-l);line-height:1.1;}
.overall-summary-value small{font-family:'DM Sans',sans-serif;font-size:13px;color:var(--muted-l);font-weight:600;margin-left:4px;}
.overall-summary-action{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:10px 14px;border-radius:9px;border:1px solid rgba(124,95,217,.32);background:rgba(124,95,217,.10);color:var(--violet-dark);font-size:12px;font-weight:700;text-decoration:none;white-space:nowrap;}
.overall-summary-action:hover{background:rgba(124,95,217,.16);border-color:rgba(124,95,217,.5);}
html.dark-theme .overall-summary-sub{color:#A0B3C6 !important;}
html.dark-theme .overall-summary-tile{background:#0F1F3D !important;border-color:rgba(255,255,255,.08) !important;}
html.dark-theme .overall-summary-icon{background:rgba(156,133,240,.15) !important;color:#C4B5FD !important;}
html.dark-theme .overall-summary-label{color:#A0B3C6 !important;}
html.dark-theme .overall-summary-value{color:#F8FAFC !important;}
html.dark-theme .overall-summary-value small{color:#A0B3C6 !important;}
html.dark-theme .overall-summary-action{background:rgba(156,133,240,.14) !important;border-color:rgba(156,133,240,.30) !important;color:#C4B5FD !important;}
@media(max-width:700px){.overall-summary-grid{grid-template-columns:1fr;}.overall-summary-action{width:100%;}}



/* Desktop overview sizing aligned with the Faculty and Staff dashboards.
   The Dean top bar is fixed, so reserve its height before the dashboard shell. */
.main.dean-dashboard-main{padding:84px 28px 12px !important;min-width:0;}
.dean-dashboard-shell{
  min-height:calc(100vh - 108px);padding:18px 28px 16px;border-radius:22px;
  display:flex;flex-direction:column;
}
.dean-dashboard-intro{margin:0 2px 14px;}
.dean-dashboard-kicker{margin-bottom:4px;font-size:11px;letter-spacing:1.1px;}
.dean-dashboard-intro h1{font-size:27px;line-height:1.05;}
.dean-dashboard-intro p{margin-top:6px;font-size:13px;line-height:1.4;}
.dean-dashboard-status{margin-top:10px;padding:6px 12px;font-size:11.5px;}
.dean-schedule-card{padding:14px 20px;margin-bottom:16px;border-radius:15px;}
.dean-schedule-grid{gap:16px;}
.dean-schedule-label{margin-bottom:5px;font-size:10.5px;}
.dean-schedule-value{font-size:14.5px;line-height:1.3;}
.dean-schedule-status{padding:5px 13px;font-size:11.5px;}
.dean-schedule-extras{margin-top:8px;padding-top:8px;}
.dean-schedule-extras .period-message{font-size:12px;line-height:1.35;}
.countdown-row{gap:10px;margin-top:10px;}
.countdown-box{padding:8px;}
.countdown-box .num{font-size:18px;}
.countdown-box .lbl{font-size:9px;}
.dean-welcome-bar{gap:12px;padding:18px 24px;margin-bottom:18px;border-radius:16px;}
.dean-welcome-text h2{font-size:22px;}
.dean-welcome-text p{font-size:12.5px;line-height:1.4;}
.dean-score-chip{flex-basis:220px;max-width:300px;min-width:200px;padding:14px 22px;border-radius:14px;}
.dean-score-value{font-size:36px;}
.dean-score-label{margin-top:6px;font-size:10.5px;}
.dean-faculty-stats-grid{gap:12px;margin-bottom:16px;}
.dean-faculty-stat{padding:14px 17px 16px;border-radius:14px;}
.dean-faculty-stat-icon{width:32px;height:32px;margin-bottom:9px;border-radius:9px;font-size:14px;}
.dean-faculty-stat-label{margin-bottom:9px;font-size:10px;letter-spacing:.7px;}
.dean-faculty-stat-value{font-size:25px;}
.dean-faculty-stat-value.compact{font-size:19px;}
.dean-dashboard-quick-access{margin-top:auto;padding-top:10px;}
.dean-quick-access-heading{margin-bottom:10px;}
.dean-quick-access-kicker{font-size:10px;margin-bottom:3px;}
.dean-quick-access-heading h2{font-size:19px;}
.dean-quick-access-caption{font-size:11px;}
.dean-quick-access-grid{gap:12px;}
.dean-quick-access-card{min-height:86px;padding:12px 14px;gap:11px;border-radius:13px;}
.dean-quick-access-icon{width:38px;height:38px;flex-basis:38px;border-radius:11px;font-size:16px;}
.dean-quick-access-copy{gap:4px;}
.dean-quick-access-copy strong{font-size:12.5px;}
.dean-quick-access-copy small{font-size:10.8px;line-height:1.4;}

/* Short desktop viewports: keep the same hierarchy, trimming only excess padding. */
@media(max-height:790px) and (min-width:901px){
  .main.dean-dashboard-main{padding:84px 28px 8px !important;}
  .dean-dashboard-shell{min-height:calc(100vh - 100px);padding:14px 26px 12px;border-radius:18px;}
  .dean-dashboard-intro{margin-bottom:11px;}
  .dean-dashboard-intro h1{font-size:26px;}
  .dean-dashboard-status{margin-top:8px;padding:5px 11px;}
  .dean-schedule-card{padding:12px 18px;margin-bottom:13px;}
  .dean-schedule-extras{margin-top:7px;padding-top:7px;}
  .dean-welcome-bar{padding:18px 24px;margin-bottom:16px;}
  .dean-welcome-text h2{font-size:21px;}
  .dean-score-chip{padding:14px 22px;}
  .dean-score-value{font-size:34px;}
  .dean-faculty-stats-grid{gap:10px;margin-bottom:13px;}
  .dean-faculty-stat{padding:15px 16px 18px;}
  .dean-faculty-stat-icon{width:32px;height:32px;margin-bottom:9px;}
  .dean-faculty-stat-label{margin-bottom:9px;}
  .dean-faculty-stat-value{font-size:23px;}
  .dean-dashboard-quick-access{padding-top:8px;}
  .dean-quick-access-heading{margin-bottom:8px;}
  .dean-quick-access-card{min-height:92px;padding:12px 14px;}
  .dean-quick-access-icon{width:38px;height:38px;flex-basis:38px;font-size:16px;}
}
@media(max-width:900px){
  .main.dean-dashboard-main{padding:18px 16px 24px !important;}
  .dean-dashboard-shell{min-height:0;padding:18px 18px 20px;border-radius:16px;}
  .dean-dashboard-quick-access{margin-top:18px;padding-top:0;}
}
@media(max-width:600px){
  .dean-dashboard-shell{min-height:0;padding:16px 13px 18px;}
  .dean-score-chip{flex-basis:100%;max-width:none;}
  .dean-quick-access-heading{align-items:flex-start;flex-direction:column;gap:4px;}
}

</style>
<style id="dean-principal-layout">
/* Same layout and styling as the Principal dashboard, using the Dean's violet accent. */
.dean-dashboard-main .dean-welcome-bar{border:1px solid #DDD0FA!important;background:linear-gradient(110deg,#FFFFFF 0%,#F8F5FF 100%)!important;box-shadow:0 4px 15px rgba(124,58,237,.055)!important;}
.dean-dashboard-main .dean-welcome-text p strong{color:#6D28D9!important;}
.dean-dashboard-main .dean-score-chip{background:#F5F0FF!important;border:1px solid #DDD0FA!important;}
.dean-dashboard-main .dean-score-value{color:#6D28D9!important;font-family:'DM Sans',sans-serif!important;font-weight:800!important;letter-spacing:-1.2px!important;}
.dean-dashboard-main .dean-score-label{color:#64748B!important;font-weight:700!important;letter-spacing:.8px!important;text-transform:uppercase!important;font-size:10px!important;}
.dean-dashboard-main a.dean-faculty-stat{display:block;color:inherit;text-decoration:none;transition:transform .16s ease,box-shadow .16s ease;}
.dean-dashboard-main a.dean-faculty-stat:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(19,38,63,.08);}
.dean-dashboard-main a.dean-faculty-stat:focus-visible{outline:2px solid #7C3AED;outline-offset:2px;}
.dean-dashboard-main .dean-faculty-stats-grid .dean-faculty-stat::before{background:#7C3AED!important;}
.dean-dashboard-main .dean-faculty-stats-grid .dean-faculty-stat .dean-faculty-stat-icon{background:#EDE9FE!important;color:#6D28D9!important;}
.dean-dashboard-main .dean-faculty-stats-grid .dean-faculty-stat .dean-faculty-stat-value{color:#6D28D9!important;}
.dean-dashboard-main .dean-faculty-stat-value.compact.neither{font-size:15px!important;line-height:1.25!important;}
.dean-dashboard-main .dean-quick-access-kicker{color:#6D28D9!important;}
.dean-dashboard-main .dean-quick-access-card .dean-quick-access-icon{background:#EDE9FE!important;color:#6D28D9!important;}
.dean-dashboard-main .dean-quick-access-card .dean-quick-access-arrow{color:#6D28D9!important;}
.dean-dashboard-main .dean-quick-access-card:hover{border-color:#C9BDF5!important;}
html.dark-theme .dean-dashboard-main .dean-welcome-bar{background:linear-gradient(110deg,#0F1F3D,#13294A)!important;border-color:rgba(167,139,250,.30)!important;}
html.dark-theme .dean-dashboard-main .dean-welcome-text p strong,html.dark-theme .dean-dashboard-main .dean-score-value{color:#C4B5FD!important;}
html.dark-theme .dean-dashboard-main .dean-score-chip{background:#172A45!important;border-color:rgba(167,139,250,.30)!important;}
html.dark-theme .dean-dashboard-main .dean-faculty-stats-grid .dean-faculty-stat .dean-faculty-stat-icon,
html.dark-theme .dean-dashboard-main .dean-quick-access-card .dean-quick-access-icon{background:rgba(167,139,250,.16)!important;color:#C4B5FD!important;}
html.dark-theme .dean-dashboard-main .dean-faculty-stats-grid .dean-faculty-stat .dean-faculty-stat-value,
html.dark-theme .dean-dashboard-main .dean-quick-access-card .dean-quick-access-arrow,
html.dark-theme .dean-dashboard-main .dean-quick-access-kicker{color:#C4B5FD!important;}
</style>
</head>
<body>

<?php
$active = 'dashboard';
$sidebarScope = HIGHER_ED_LABEL . ' Division';
include __DIR__ . '/includes/dean_sidebar.php'; if (is_file(__DIR__ . '/dean_header_dark.php')) include __DIR__ . '/dean_header_dark.php';
?>

<?php
// Schedule dates use the same short format as the Principal dashboard (e.g. "Nov 4, 2026 4:39 PM").
$deanFmtSched = static function ($value): string {
    if (empty($value)) return '—';
    try {
        $dt = ss_parse_datetime((string)$value);
        return $dt ? $dt->format('M j, Y g:i A') : '—';
    } catch (Throwable $e) { return '—'; }
};
?>
<main class="main dean-dashboard-main">
<div class="dean-dashboard-shell">
    <section class="dean-dashboard-intro">
        <div class="dean-dashboard-kicker"><i class="fa-solid fa-chart-line" aria-hidden="true"></i> Dashboard Overview</div>
        
        <?php
        // Countdown to opening: only while closed and the scheduled start is still ahead.
        $opensAtIso = '';
        if ($structureActive && $hasCurrentEvaluationPeriod && !$evalOpen && !empty($settings['eval_start'])) {
            try {
                $opensDt = ss_parse_datetime($settings['eval_start']);
                $nowDt   = new DateTimeImmutable('now', $opensDt->getTimezone());
                if ($opensDt > $nowDt) $opensAtIso = $opensDt->format('c');
            } catch (Throwable $e) { $opensAtIso = ''; }
        }
        ?>
        <div class="dean-status-row">
        <span class="dean-dashboard-status <?= ($hasCurrentEvaluationPeriod && $evalOpen) ? '' : 'closed' ?>">
            <i class="fa-solid <?= ($hasCurrentEvaluationPeriod && $evalOpen) ? 'fa-lock-open' : 'fa-lock' ?>" aria-hidden="true"></i>
            <?php if (!$structureActive): ?>
                Higher Education is not the active evaluation structure
            <?php elseif (!$hasCurrentEvaluationPeriod): ?>
                No active evaluation period
            <?php elseif ($evalOpen): ?>
                Evaluation period is currently open
            <?php else: ?>
                Evaluation period is currently closed
            <?php endif; ?>
            <?php if ($opensAtIso !== ''): ?>
            <span class="dean-open-cd" id="openCountdownRow" data-open="<?= htmlspecialchars($opensAtIso) ?>" aria-live="off">
                <span class="dean-open-cd-sep" aria-hidden="true">|</span>
                <span class="dean-open-cd-label">Opens in</span>
                <span class="dean-cd-unit" id="oc-days-wrap" hidden><b id="oc-days">0</b> D</span>
                <span class="dean-cd-unit" id="oc-hours-wrap" hidden><b id="oc-hours">0</b> H</span>
                <span class="dean-cd-unit"><b id="oc-mins">0</b> M</span>
                <span class="dean-cd-unit"><b id="oc-secs">0</b> s</span>
            </span>
            <?php endif; ?>
        </span>
        </div>
    </section>

    <!-- Schedule summary mirrors the four-column Faculty dashboard panel. -->
    <section class="dean-schedule-card" id="dean-evaluation-schedule" aria-label="Current evaluation schedule">
        <div class="dean-schedule-grid">
            <div class="dean-schedule-item">
                <span class="dean-schedule-label">Academic Year</span>
                <strong class="dean-schedule-value"><?= $hasCurrentEvaluationPeriod ? htmlspecialchars($settings['academic_year'] ?? '—') : '—' ?></strong>
            </div>
            <div class="dean-schedule-item">
                <span class="dean-schedule-label">Evaluation Opens</span>
                <strong class="dean-schedule-value"><?= $hasCurrentEvaluationPeriod ? htmlspecialchars($deanFmtSched($settings['eval_start'] ?? null)) : '—' ?></strong>
            </div>
            <div class="dean-schedule-item">
                <span class="dean-schedule-label">Evaluation Closes</span>
                <strong class="dean-schedule-value"><?= $hasCurrentEvaluationPeriod ? htmlspecialchars($deanFmtSched($settings['eval_end'] ?? null)) : '—' ?></strong>
            </div>
            <div class="dean-schedule-item">
                <span class="dean-schedule-label">Status</span>
                <span class="dean-schedule-status <?= ($hasCurrentEvaluationPeriod && $evalOpen) ? '' : 'closed' ?>">
                    <?= !$structureActive ? 'Inactive Structure' : (!$hasCurrentEvaluationPeriod ? 'No Active Period' : htmlspecialchars($settings['status']['label'] ?? ($evalOpen ? 'Open' : 'Closed'))) ?>
                </span>
            </div>
        </div>
        <?php
        // Schedule message values (the countdown row was removed from this dashboard).
        $periodMessage = $settings['message'] ?? '';
        $periodHeadline = is_array($periodMessage) ? ($periodMessage['headline'] ?? '') : (string)$periodMessage;
        $periodSub = is_array($periodMessage) ? ($periodMessage['sub'] ?? '') : '';
        ?>
    </section>

    <section class="dean-welcome-bar" id="dean-overall-summary" aria-label="Dean evaluation summary">
        <div class="dean-welcome-text">
            <h2>Welcome, <?= htmlspecialchars($me['full_name'] ?? 'Dean') ?>!</h2>
            <p><strong>Dean</strong> &nbsp;·&nbsp; <?= $hasCurrentEvaluationPeriod ? htmlspecialchars($settings['academic_year'] ?? '—') . ' — ' . htmlspecialchars($settings['academic_term'] ?? 'Current Term') : 'No Active Period' ?> · <?= HIGHER_ED_LABEL ?></p>
        </div>
    </section>

    <section class="dean-faculty-stats-grid" id="dean-performance-summary" aria-label="Evaluation summary metrics">
        <a class="dean-faculty-stat" href="dean_results.php" aria-label="View Evaluation Received page" title="View Evaluation Received">
            <div class="dean-faculty-stat-icon"><i class="fa-solid fa-users" aria-hidden="true"></i></div>
            <div class="dean-faculty-stat-label">Evaluations Received</div>
            <div class="dean-faculty-stat-value"><?= number_format($deanOverallResponses) ?></div>
        </a>
        <a class="dean-faculty-stat" href="dean_results.php">
            <div class="dean-faculty-stat-icon"><i class="fa-solid fa-circle-check" aria-hidden="true"></i></div>
            <div class="dean-faculty-stat-label">Rating Average</div>
            <div class="dean-faculty-stat-value"><?= number_format($deanOverallAverage ?? 0, 2) ?> / 5</div>
        </a>
        <a class="dean-faculty-stat" href="dean_results.php">
            <div class="dean-faculty-stat-icon"><i class="fa-solid fa-award" aria-hidden="true"></i></div>
            <div class="dean-faculty-stat-label">Performance Level</div>
            <div class="dean-faculty-stat-value compact <?= htmlspecialchars($deanPerformanceClass) ?>"><?= htmlspecialchars($deanPerformanceLabel) ?></div>
        </a>
        <a class="dean-faculty-stat" href="#dean-evaluation-schedule">
            <div class="dean-faculty-stat-icon"><i class="fa-solid fa-calendar" aria-hidden="true"></i></div>
            <div class="dean-faculty-stat-label">Current Period</div>
            <div class="dean-faculty-stat-value compact"><?= $hasCurrentEvaluationPeriod ? htmlspecialchars($settings['academic_term'] ?? '—') : 'No Active Period' ?></div>
        </a>
    </section>

    <section class="dean-dashboard-quick-access" aria-label="Workspace shortcuts">
        <div class="dean-quick-access-heading">
            <div>
                <span class="dean-quick-access-kicker">WORKSPACE SHORTCUTS</span>
                <h2>Continue where you need to be</h2>
            </div>
            <span class="dean-quick-access-caption"></span>
        </div>
        <div class="dean-quick-access-grid">
            <a class="dean-quick-access-card" href="dean_results.php">
                <span class="dean-quick-access-icon"><i class="fa-solid fa-chart-column" aria-hidden="true"></i></span>
                <span class="dean-quick-access-copy">
                    <strong>Your Evaluation</strong>
                    <small>Review your evaluation feedback and results.</small>
                </span>
                <i class="fa-solid fa-arrow-right dean-quick-access-arrow" aria-hidden="true"></i>
            </a>
            <a class="dean-quick-access-card" href="dean_evaluation_tracker.php">
                <span class="dean-quick-access-icon"><i class="fa-solid fa-calendar-days" aria-hidden="true"></i></span>
                <span class="dean-quick-access-copy">
                    <strong>Evaluation Tracker</strong>
                    <small>Monitor the live evaluation period and progress.</small>
                </span>
                <i class="fa-solid fa-arrow-right dean-quick-access-arrow" aria-hidden="true"></i>
            </a>
            <a class="dean-quick-access-card" href="dean_reports.php">
                <span class="dean-quick-access-icon"><i class="fa-solid fa-award" aria-hidden="true"></i></span>
                <span class="dean-quick-access-copy">
                    <strong>Reports</strong>
                    <small>Generate reports and view college-level analytics.</small>
                </span>
                <i class="fa-solid fa-arrow-right dean-quick-access-arrow" aria-hidden="true"></i>
            </a>
        </div>
    </section>

</div><!-- /.dean-dashboard-shell -->
</main>

<script>
(function(){
    const row = document.getElementById('openCountdownRow');
    if (!row) return;
    const openAt = new Date(row.dataset.open).getTime();
    const el = function(id){ return document.getElementById(id); };
    const pad = function(n){ return n < 10 ? '0' + n : '' + n; };
    let timer = null;
    function tick(){
        let diff = Math.max(0, openAt - Date.now());
        const days = Math.floor(diff / 86400000); diff -= days * 86400000;
        const hours = Math.floor(diff / 3600000); diff -= hours * 3600000;
        const mins = Math.floor(diff / 60000); diff -= mins * 60000;
        const secs = Math.floor(diff / 1000);
        el('oc-days').textContent = days;
        el('oc-hours').textContent = hours;
        el('oc-mins').textContent = mins;
        el('oc-secs').textContent = secs;
        el('oc-days-wrap').hidden = days === 0;
        el('oc-hours-wrap').hidden = days === 0 && hours === 0;
        if (openAt - Date.now() <= 0) {
            clearInterval(timer);
            // Reload once so the page switches to "open" (guard prevents a reload loop).
            var key = 'deanOpenReload:' + row.dataset.open;
            try { if (sessionStorage.getItem(key)) return; sessionStorage.setItem(key, '1'); } catch (e) {}
            setTimeout(function(){ location.reload(); }, 1500);
        }
    }
    tick();
    timer = setInterval(tick, 1000);
})();
</script>

<script src="../admin/eval_status_poll.js" defer></script>
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
</html>