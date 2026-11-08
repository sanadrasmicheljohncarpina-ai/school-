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
require_once 'principal_notifications_feed.php';   // safe_scalar(), esc_list(), principal_build_notifications()
require_once 'school_head_structure_gate.php';
require_once __DIR__ . '/principal_sidebar.php';

if (!function_exists('principal_theme_assets')) {
    function principal_theme_assets(): void {
        static $done = false;
        if ($done) return;
        $done = true;
        ?>
<script>(function(){try{if(localStorage.getItem('pbi_theme') === 'dark'){document.documentElement.classList.add('principal-theme-dark-pending');}}catch(e){}})();</script>
<link rel="stylesheet" href="includes/principal_theme.css?v=20261011.4"/>
<script defer src="includes/principal_theme.js?v=20261011.4"></script>
        <?php
    }
}
   // the one shared Principal sidebar

// ── AUTH GUARD ────────────────────────────────────────────
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'principal') {
    header("Location: principal_login.php");
    exit;
}

/* =========================================================================
   OVERVIEW-ONLY DASHBOARD
   -------------------------------------------------------------------------
   This page is deliberately a landing/summary page. Detail lives on the
   dedicated pages and is NOT duplicated here:

     Grade-level analytics ............ principal_reports.php
     Teacher lists / rankings ......... principal_teachers.php, principal_reports.php
     Staff breakdown .................. principal_staff.php
     Live monitoring / countdown ...... principal_evaluation_tracker.php
     Report generation ................ principal_reports.php
     My own results ................... principal_results.php

   Anything added here should answer "what's the state of my division right
   now?" in one glance. If it needs a table, it belongs on another page.
   ========================================================================= */

// ── SELF-HEALING SCHEMA (matches your ALTER-TABLE-on-load pattern) ─────
@$mysqli->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS academic_level VARCHAR(20) NULL");
@$mysqli->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS grade_level VARCHAR(10) NULL");

// ── PULL PRINCIPAL PROFILE ────────────────────────────────
$stmt = $mysqli->prepare("SELECT full_name, username, email, designation, photo, education_level FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ── SCOPE (Principal must never see College) ────────────────
$myLevel = $me['education_level'] ?? 'both';
if ($myLevel === 'junior_high') {
    $scopeAcademicLevels = ['junior_high'];
    $scopeGrades = ['7', '8', '9', '10'];
} elseif ($myLevel === 'senior_high') {
    $scopeAcademicLevels = ['senior_high'];
    $scopeGrades = ['11', '12'];
} else {
    $scopeAcademicLevels = ['junior_high', 'senior_high'];
    $scopeGrades = ['7', '8', '9', '10', '11', '12'];
}
$scopeAcademicIn = esc_list($mysqli, $scopeAcademicLevels);
$scopeGradesIn   = esc_list($mysqli, $scopeGrades);

// ── GLOBAL SYSTEM SETTINGS (single source of truth) ─────────────────────
// Everything about "what period is it, what's the status, what's the
// schedule" comes from here. The Principal dashboard never derives or
// stores any of this itself, and never hardcodes an academic year/term —
// same convention as dean_dashboard.php (Step 14: Synchronization with
// System Settings).
$settings = get_school_head_settings($mysqli, 'principal');

// Academic Structure / Academic Term gate: Principal owns School Year,
// Dean owns College. Narrow-only — existing scheduling, Force Open and
// Force Closed still decide open/closed while Basic Education is active.
$settings = sh_gate_apply($settings, 'principal');

// A Principal oversees the Basic Education (Junior High / Senior High)
// division. If the Executive Assistant/Admin has the active Academic
// Structure set to College, the Principal must not show Basic Ed
// analytics as current.
$structureActive = !empty($settings['school_head_applicable']);
$period_id_int   = $settings['period_id'] ?? 0;
$evalOpen        = !empty($settings['school_head_is_open']);
$hasPeriod       = $period_id_int > 0;

// Treat the period as current only while it is active and has not been archived (same rule the
// Dean dashboard uses). System Archive deactivates a period and clears its live rows, so a stale
// setting can't keep an archived period "current"; restoring the archive makes it current again.
$period_id_int = (int)$period_id_int;
if ($hasPeriod) {
    $periodIsCurrent = false;
    $pcStmt = false;
    try {
        $pcStmt = $mysqli->prepare("SELECT ep.id FROM evaluation_periods ep
            WHERE ep.id=? AND ep.is_active=1
              AND NOT EXISTS (SELECT 1 FROM system_archives sa WHERE sa.period_id=ep.id AND sa.status='archived')
            LIMIT 1");
    } catch (Throwable $e) {
        $pcStmt = false; // system_archives not created yet: fall back to the plain active-period check
    }
    if (!$pcStmt) {
        try { $pcStmt = $mysqli->prepare("SELECT id FROM evaluation_periods WHERE id=? AND is_active=1 LIMIT 1"); }
        catch (Throwable $e) { $pcStmt = false; }
    }
    if ($pcStmt) {
        try {
            $pcStmt->bind_param('i', $period_id_int);
            if ($pcStmt->execute()) $periodIsCurrent = (bool)$pcStmt->get_result()->fetch_assoc();
        } catch (Throwable $e) { $periodIsCurrent = false; }
        $pcStmt->close();
        if (!$periodIsCurrent) { $period_id_int = 0; $hasPeriod = false; }
    }
}

// Step 8 (mirrored from Dean): internal value stays whatever admin uses;
// this is the only display label used in markup below.
$daysRemaining = null;
if ($settings['eval_end']) {
    $diff = (strtotime($settings['eval_end']) - strtotime(date('Y-m-d')));
    $daysRemaining = (int)ceil($diff / 86400);
}

// Dashboard schedule display uses the same Asia/Manila timezone as the
// centralized evaluation scheduler.
$displayTimezone = new DateTimeZone('Asia/Manila');
$formatScheduleDateTime = static function ($value) use ($displayTimezone): string {
    if (empty($value)) return '—';
    try {
        return (new DateTime((string)$value, $displayTimezone))->setTimezone($displayTimezone)->format('M j, Y g:i A');
    } catch (Throwable $e) {
        return '—';
    }
};
$evalOpenDisplay = $structureActive && $evalOpen;
$dashboardStatusLabel = $evalOpenDisplay ? 'Open' : ($hasPeriod ? 'Scheduled' : 'Closed');
$dashboardStatusClass = $evalOpenDisplay ? 'open' : 'closed';
$dashboardStatusHeadline = !$structureActive
    ? 'Basic Education is not the active evaluation structure.'
    : ($evalOpen
        ? 'Evaluation is currently open.'
        : 'Evaluation is currently closed — waiting for the scheduled opening.');

// ── HEADLINE STATS ONLY ────────────────────────────────────
// Six numbers, nothing per-person and nothing per-grade. The old page ran an
// N+1 query per teacher and per grade here; all of that moved to the pages
// that actually display it.
$teacherCount = $staffCount = $studentCount = 0;
$studentsSubmitted = $targetsEvaluated = 0;
$totalTargets = $evaluationCompletion = $pendingEvaluations = 0;
$studentParticipation = 0;

if ($structureActive) {
    $teacherCount = (int)(safe_scalar($mysqli, "
        SELECT COUNT(*) c FROM users
        WHERE role='teacher' AND is_active=1 AND account_status='approved'
          AND academic_level IN ($scopeAcademicIn)
    ") ?? 0);

    $staffCount = (int)(safe_scalar($mysqli, "
        SELECT COUNT(*) c FROM users
        WHERE role='staff' AND is_active=1 AND account_status='approved'
          AND academic_level IN ($scopeAcademicIn)
    ") ?? 0);

    $studentCount = (int)(safe_scalar($mysqli, "
        SELECT COUNT(*) c FROM users
        WHERE role='student' AND is_active=1 AND account_status='approved'
          AND grade_level IN ($scopeGradesIn)
    ") ?? 0);

    if ($hasPeriod) {
        $studentsSubmitted = (int)(safe_scalar($mysqli, "
            SELECT COUNT(DISTINCT et.evaluator_id) c
            FROM evaluation_tracker et
            INNER JOIN users u ON u.id = et.evaluator_id
            WHERE et.eval_type='student' AND et.period_id=?
              AND u.role='student' AND u.grade_level IN ($scopeGradesIn)
        ", "i", [$period_id_int]) ?? 0);

        $targetsEvaluated = (int)(safe_scalar($mysqli, "
            SELECT COUNT(DISTINCT et.target_user_id) c
            FROM evaluation_tracker et
            INNER JOIN users u ON u.id = et.target_user_id
            WHERE et.eval_type='student' AND et.period_id=?
              AND u.role IN ('teacher','staff') AND u.academic_level IN ($scopeAcademicIn)
        ", "i", [$period_id_int]) ?? 0);
    }

    $totalTargets          = $teacherCount + $staffCount;
    $evaluationCompletion  = $totalTargets > 0 ? round($targetsEvaluated / $totalTargets * 100) : 0;
    $pendingEvaluations    = max(0, $totalTargets - $targetsEvaluated);
    $studentParticipation  = $studentCount > 0 ? round($studentsSubmitted / $studentCount * 100) : 0;
}

// ── PRINCIPAL'S OWN EVALUATION SUMMARY (live + archived anonymous feedback) ──
// Mirrors the Principal Results page: no evaluator identity is selected or exposed.
$principalEvaluationResponses = 0;
$principalEvaluationScoreSum = 0.0;
$principalEvaluationScoreCount = 0;
$principalSummaryTargetId = (int)($_SESSION['user_id'] ?? 0);

try {
    $principalLiveSummaryStmt = $mysqli->prepare("
        SELECT COUNT(*) AS response_count,
               COALESCE(SUM(answer_totals.score_sum), 0) AS score_sum,
               COALESCE(SUM(answer_totals.score_count), 0) AS score_count
        FROM evaluation_tracker et
        LEFT JOIN (
            SELECT tracker_id, SUM(answer_score) AS score_sum, COUNT(answer_score) AS score_count
            FROM questionnaire_answers
            GROUP BY tracker_id
        ) answer_totals ON answer_totals.tracker_id = et.id
        WHERE et.target_user_id = ?
          AND et.status IN ('submitted', 'approved')
          AND (
              et.eval_type = 'faculty_peer'
              OR (et.eval_type = 'student' AND et.evaluation_context = 'school_head')
              OR et.eval_type = 'school_head'
          )
    ");
    $principalLiveSummaryStmt->bind_param('i', $principalSummaryTargetId);
    $principalLiveSummaryStmt->execute();
    $principalLiveSummaryRow = $principalLiveSummaryStmt->get_result()->fetch_assoc() ?: [];
    $principalLiveSummaryStmt->close();

    $principalEvaluationResponses += (int)($principalLiveSummaryRow['response_count'] ?? 0);
    $principalEvaluationScoreSum += (float)($principalLiveSummaryRow['score_sum'] ?? 0);
    $principalEvaluationScoreCount += (int)($principalLiveSummaryRow['score_count'] ?? 0);
} catch (Throwable $principalLiveSummaryError) {
    // Keep the dashboard available on installations whose evaluation schema is older.
}

try {
    $principalArchiveSummaryStmt = $mysqli->prepare("
        SELECT COUNT(*) AS response_count,
               COALESCE(SUM(score_sum), 0) AS score_sum,
               COALESCE(SUM(score_count), 0) AS score_count
        FROM feedback_received_keep
        WHERE target_user_id = ?
    ");
    $principalArchiveSummaryStmt->bind_param('i', $principalSummaryTargetId);
    $principalArchiveSummaryStmt->execute();
    $principalArchiveSummaryRow = $principalArchiveSummaryStmt->get_result()->fetch_assoc() ?: [];
    $principalArchiveSummaryStmt->close();

    $principalEvaluationResponses += (int)($principalArchiveSummaryRow['response_count'] ?? 0);
    $principalEvaluationScoreSum += (float)($principalArchiveSummaryRow['score_sum'] ?? 0);
    $principalEvaluationScoreCount += (int)($principalArchiveSummaryRow['score_count'] ?? 0);
} catch (Throwable $principalArchiveSummaryError) {
    // The archive table is optional on a fresh deployment.
}

$principalOverallAverage = $principalEvaluationScoreCount > 0
    ? round($principalEvaluationScoreSum / $principalEvaluationScoreCount, 2)
    : null;
// Performance Level uses the same bands and wording as the Rating Legend on the reports.
if ($principalOverallAverage === null)      { $principalPerformanceLabel = '—'; }
elseif ($principalOverallAverage >= 4.5)    { $principalPerformanceLabel = 'Very Satisfied'; }
elseif ($principalOverallAverage >= 3.5)    { $principalPerformanceLabel = 'Satisfied'; }
elseif ($principalOverallAverage >= 2.5)    { $principalPerformanceLabel = 'Neither Satisfied nor Dissatisfied'; }
elseif ($principalOverallAverage >= 1.5)    { $principalPerformanceLabel = 'Dissatisfied'; }
else                                        { $principalPerformanceLabel = 'Very Dissatisfied'; }

$photo_src = !empty($me['photo']) ? '../image/' . $me['photo'] : '../image/pbi_logo';

// ── NOTIFICATIONS ─────────────────────────────────────────────────────
// Built by the shared feed so the initial render and the polled JSON can
// never disagree. Must run BEFORE $mysqli->close().
$notifications = principal_build_notifications($mysqli, (int)$_SESSION['user_id'], $settings);

$mysqli->close();

$scopeLabel = $myLevel === 'both' ? 'Junior High & Senior High' : ($myLevel === 'junior_high' ? 'Junior High School' : 'Senior High School');

// Use a natural display order for names stored as "Last Name, First Name M.I.".
$dashboardDisplayName = trim((string)($me['full_name'] ?? 'Principal'));
if (strpos($dashboardDisplayName, ',') !== false) {
    [$dashboardLastName, $dashboardGivenName] = array_map('trim', explode(',', $dashboardDisplayName, 2));
    $dashboardDisplayName = trim($dashboardGivenName . ' ' . $dashboardLastName);
}

$dashboardPeriodParts = [];
foreach ([
    $settings['academic_year'] ?? '',
    $settings['academic_term'] ?? $settings['semester'] ?? '',
] as $dashboardPeriodPart) {
    $dashboardPeriodPart = trim((string)$dashboardPeriodPart);
    if ($dashboardPeriodPart !== '') $dashboardPeriodParts[] = $dashboardPeriodPart;
}
$dashboardCurrentPeriod = implode(' — ', $dashboardPeriodParts);

$principalCurrentPeriodLabel = trim((string)($settings['academic_term'] ?? ''));
if ($principalCurrentPeriodLabel === '') $principalCurrentPeriodLabel = trim((string)($settings['semester'] ?? ''));
if ($principalCurrentPeriodLabel === '') $principalCurrentPeriodLabel = trim((string)($settings['period_label'] ?? ''));
if ($principalCurrentPeriodLabel === '') $principalCurrentPeriodLabel = '—';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>PBI — Principal Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<?php principal_theme_assets(); ?>
<style>
:root{--dark:#0A192F;--mid:#172A45;--inner:#0F1F3D;--amber:#d99a2b;--amber-h:#f0b84d;--amber-dark:#b8801f;--light:#E0E6F0;--muted:#A0B3C6;--radius:10px;--shadow:0 8px 32px rgba(0,0,0,0.45);--danger:#f05454;--good:#10B981;}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{min-height:100vh;background:linear-gradient(rgba(5,18,36,.72),rgba(5,18,36,.82)),url('../background.png') center center / cover no-repeat fixed;background-color:var(--dark);font-family:'DM Sans',sans-serif;color:var(--light);display:flex;}

/* SIDEBAR */
.sidebar{width:250px;flex-shrink:0;background:rgba(23,42,69,.9);border-right:1px solid rgba(255,255,255,.08);min-height:100vh;padding:28px 20px;display:flex;flex-direction:column;}
.sb-profile{text-align:center;margin-bottom:26px;}
.sb-photo{width:72px;height:72px;border-radius:50%;object-fit:cover;border:2.5px solid var(--amber);box-shadow:0 0 18px rgba(217,154,43,.4);margin:0 auto 10px;display:block;}
.sb-name{font-weight:700;font-size:15px;color:#fff;}
.sb-role{font-size:11px;color:var(--amber-h);text-transform:uppercase;letter-spacing:.6px;margin-top:2px;}
.sb-scope{font-size:10px;color:var(--muted);margin-top:4px;}
.sb-nav{display:flex;flex-direction:column;gap:5px;margin-top:10px;width:100%;}
.sb-nav-section-label{width:auto;margin:5px 12px 1px;padding:0 2px;color:var(--muted);font-size:11px;font-weight:900;letter-spacing:1.6px;line-height:1.25;text-transform:uppercase;text-align:center;color:#C7D2FE;text-shadow:0 1px 8px rgba(129,140,248,.16);}
.sb-nav-section-label:first-child{margin-top:0;}
.sb-nav a{box-sizing:border-box;width:100%;min-height:42px;margin:0;padding:5px 14px;display:flex;align-items:center;gap:10px;border-radius:8px;color:var(--muted);text-decoration:none;font-size:14px;font-weight:500;transition:background .2s,color .2s;}
.sb-nav a:hover,.sb-nav a.active{background:rgba(217,154,43,.15);color:#fff;}
.sb-nav a i{width:18px;flex:0 0 18px;text-align:center;color:var(--amber-h);}
.sb-nav .sb-logout-link{color:#fca5a5;}
.sb-nav .sb-logout-link:hover{background:rgba(240,84,84,.12);color:#fecaca;}
.sb-logout{margin-top:6px;}
.sb-logout a{display:flex;align-items:center;gap:10px;padding:11px 14px;border-radius:8px;color:#fca5a5;text-decoration:none;font-size:14px;font-weight:500;transition:background .2s;}
.sb-logout a:hover{background:rgba(240,84,84,.12);}

/* MAIN */
.main{flex:1;padding:36px 44px;max-width:1280px;}
.page-header{position:relative;}
.page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:26px;flex-wrap:wrap;gap:14px;}
.page-title{font-family:'Rajdhani',sans-serif;font-size:28px;font-weight:700;color:#fff;letter-spacing:1px;}
.page-sub{font-size:13px;color:var(--muted);margin-top:4px;}

/* PERIOD STRIP — one compact line instead of a full panel */
.period-strip{display:flex;flex-wrap:wrap;align-items:center;gap:26px;background:rgba(23,42,69,.85);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:16px 22px;box-shadow:var(--shadow);margin-bottom:22px;}
.period-item{display:flex;flex-direction:column;gap:3px;}
.period-item .k{font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;}
.period-item .v{font-size:14px;font-weight:700;color:#fff;}
.period-note{flex:1 1 100%;font-size:12px;color:var(--muted);}
.period-note strong{color:var(--light);}

.card-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:16px;margin-bottom:24px;}
.stat-card{background:rgba(23,42,69,.85);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:20px;box-shadow:var(--shadow);height:100%;transition:border-color .2s,transform .2s;}
a.stat-link{text-decoration:none;color:inherit;display:block;}
a.stat-link:hover .stat-card{border-color:rgba(217,154,43,.45);transform:translateY(-2px);}
.stat-card i{color:var(--amber-h);font-size:20px;margin-bottom:10px;}
.stat-card .num{font-size:26px;font-weight:700;color:#fff;}
.stat-card .label{font-size:12px;color:var(--muted);margin-top:4px;}

.section{background:rgba(23,42,69,.85);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:24px;box-shadow:var(--shadow);margin-bottom:22px;}
.section h2{font-family:'Rajdhani',sans-serif;font-size:19px;color:#fff;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.section h2 i{color:var(--amber-h);font-size:16px;}

/* NOTIFICATION BELL — header-right, flush to the page edge */
.header-right{display:flex;align-items:center;gap:12px;margin-left:auto;}
.bell{position:relative;}
.bell-btn{position:relative;width:42px;height:42px;border-radius:12px;background:rgba(23,42,69,.9);border:1px solid rgba(255,255,255,.1);color:var(--amber-h);font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .2s,border-color .2s;}
.bell-btn:hover,.bell-btn[aria-expanded="true"]{background:rgba(217,154,43,.18);border-color:rgba(217,154,43,.45);}
.bell-count{position:absolute;top:-6px;right:-6px;min-width:19px;height:19px;padding:0 5px;border-radius:10px;background:var(--danger);color:#fff;font-size:10px;font-weight:700;display:none;align-items:center;justify-content:center;border:2px solid var(--dark);}
.bell-count.show{display:flex;}
.bell-btn.pulse{animation:bellPulse 1.1s ease-in-out 2;}
@keyframes bellPulse{0%,100%{transform:scale(1);}35%{transform:scale(1.14);}}

.bell-panel{position:absolute;top:calc(100% + 10px);right:0;width:340px;max-height:min(70vh,560px);overflow:hidden;background:rgba(15,31,61,.98);border:1px solid rgba(255,255,255,.12);border-radius:14px;box-shadow:0 18px 44px rgba(0,0,0,.55);padding:8px;z-index:60;display:none;flex-direction:column;}
.bell-panel.open{display:flex;}
.bell-head{display:flex;align-items:center;justify-content:space-between;padding:8px 10px 10px;border-bottom:1px solid rgba(255,255,255,.08);margin-bottom:6px;flex:0 0 auto;}
.bell-head h3{font-family:'Rajdhani',sans-serif;font-size:16px;color:#fff;font-weight:700;}
.bell-head button{background:none;border:none;color:var(--amber-h);font-size:11px;font-weight:600;cursor:pointer;text-transform:uppercase;letter-spacing:.4px;}
.bell-head button:hover{text-decoration:underline;}
.bell-list{list-style:none;font-size:13px;flex:1 1 auto;min-height:0;max-height:100%;overflow-y:auto;overflow-x:hidden;overscroll-behavior:contain;padding:0 3px 2px 0;scrollbar-width:thin;scrollbar-color:rgba(148,163,184,.55) transparent;}
.bell-list::-webkit-scrollbar{width:7px;}
.bell-list::-webkit-scrollbar-track{background:transparent;}
.bell-list::-webkit-scrollbar-thumb{background:rgba(148,163,184,.55);border-radius:8px;}
.bell-list::-webkit-scrollbar-thumb:hover{background:rgba(148,163,184,.7);}
.bell-list li{padding:10px 11px;border-radius:9px;display:flex;align-items:flex-start;gap:10px;line-height:1.45;color:var(--light);}
.bell-list li+li{margin-top:4px;}
.bell-list li i{font-size:12px;margin-top:3px;color:var(--amber-h);flex-shrink:0;}
.bell-list li.unseen{background:rgba(217,154,43,.1);}
.bell-list li.lv-warn i{color:#fca5a5;}
.bell-list li.lv-good i{color:var(--good);}
.bell-foot{padding:8px 11px 4px;font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;}

.period-badge{background:rgba(217,154,43,.14);border:1px solid rgba(217,154,43,.3);color:var(--amber-h);padding:6px 14px;border-radius:20px;font-size:12px;font-weight:600;display:inline-flex;align-items:center;gap:7px;}
.period-badge.closed{background:rgba(240,84,84,.1);border-color:rgba(240,84,84,.3);color:#fca5a5;}
.period-badge.amber{background:rgba(217,154,43,.14);border-color:rgba(217,154,43,.3);color:var(--amber-h);}
.period-badge.gray{background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.12);color:var(--muted);}
.empty-note{color:var(--muted);font-size:13px;font-style:italic;}

.structure-note{display:flex;align-items:flex-start;gap:14px;padding:18px 20px;background:rgba(217,154,43,.08);border:1px solid rgba(217,154,43,.25);border-radius:12px;margin-bottom:22px;}
.structure-note i{color:var(--amber-h);font-size:20px;margin-top:2px;}
.structure-note p{font-size:13px;color:var(--light);line-height:1.6;}
.structure-note p b{color:#fff;}

.stub-note{font-size:11px;color:var(--amber-h);background:rgba(217,154,43,.08);border:1px dashed rgba(217,154,43,.35);border-radius:8px;padding:8px 12px;margin-bottom:22px;}

@media(max-width:768px){body{flex-direction:column;}.sidebar{width:100%;min-height:auto;}.main{padding:24px 18px;}}
</style>

<style id="white-theme-override">
:root{--dark:#ffffff;--mid:#ffffff;--inner:#f5f7fb;--light:#172033;--muted:#64748b;--shadow:0 4px 18px rgba(15,23,42,.08);}
html,body{background:#F8FAFC!important;color:#172033!important;}
body{background-image:none!important;}
main,.main-content,.content,.page-content{background:#F8FAFC!important;color:#172033!important;}
.sidebar{background:#0A192F!important;color:#E0E6F0!important;border-right:1px solid #172A45!important;}
.sidebar *,.sidebar a{color:inherit;}
.stat-card,.section,.period-strip,.filter-bar,.card,.panel,.table-wrap,.modal-content{background:#fff!important;color:#172033!important;border-color:#e2e8f0!important;box-shadow:var(--shadow)!important;}
h1,h2,h3,h4,h5,h6,.section-title,.page-title{color:#0f172a!important;}
p,span,label,td,th,small{color:inherit;}
table{color:#172033!important;}
table thead th{background:#f8fafc!important;color:#334155!important;border-color:#e2e8f0!important;}
table tbody td{border-color:#e2e8f0!important;}
input,select,textarea{background:#fff!important;color:#172033!important;border-color:#cbd5e1!important;}
.search-box input[type=text],.search-box select,.form-group input,.filter-field select,.filter-field input[type=text]{background:#fff!important;color:#172033!important;}
.btn-reset{color:#475569!important;border-color:#cbd5e1!important;}
.notif-list li{background:#f8fafc!important;}
</style>

<style id="principal-subtle-feature-background">
/* Subtle light backdrop for every Principal feature area. Feature cards remain white; sidebar stays navy. */
html{background:#F8FAFC !important;}
body{background:#F8FAFC !important;}
.main, main.main, .main-content, .content, .page-content{background:#F8FAFC !important;}
.page-header,.eval-switcher,.eval-banner,.group-tab-wrap,.group-tabs,.desig-subtabs,.faculty-subtabs,
.card,.panel,.section,.table-card,.content-card,.stat-card,.info-banner,.gl-card,.amber-card,
.green-card,.red-card,.ra-table-card,.table-wrap,.table-card-wrap,.content-panel,.history-card,
target-card,.eval-card,.comment-section,.avg-summary,.standing-panel,.person-row,.no-evaluated,
.no-archived,.evaluator-grid,.people-list,.cat-section,.period-strip,.filter-bar{background:#FFFFFF !important;}
@media print{html,body,.main,main.main,.main-content,.content,.page-content{background:#fff !important;}}
</style>

<style id="principal-ea-style-feature-canvas-standalone">
/* Match the EA System Logs feature: subtle light canvas with white content surfaces. */
html{background:#F8FAFC !important;}
body{background:#F8FAFC !important;background-image:none !important;}
.main, main.main, .main-content, .content, .page-content{background:#F8FAFC !important;}
/* Preserve clean white feature cards/panels. */
.page-header,.card,.panel,.section,.table-card,.content-card,.stat-card,
.period-strip,.filter-bar,.table-wrap,.table-card-wrap,.content-panel,
.eval-banner,.info-banner,.history-card,.gl-card,.amber-card,.green-card,.red-card,
.ra-table-card,.eval-card,.comment-section,.avg-summary,.standing-panel,
.person-row,.target-card,.no-eval,.no-data,.no-evaluated,.no-archived,
.evaluator-grid,.people-list,.cat-section,.eval-switcher,.tabs,.level-tabs,
.status-tabs,.faculty-subtabs,.group-tabs,.group-tab-wrap,
.desig-subtabs,.desig-subtab-wrap,.results-card,.result-card,.feature-card{
    background:#FFFFFF !important;
}
/* Light inner surfaces, equivalent to the EA log table header treatment. */
.main table thead th,.main .table-head,.main .table-header,.main .thead,
.main .subtle-head{background:#F4F8FF !important;}
@media print{
  html,body,.main,main.main,.main-content,.content,.page-content{background:#fff !important;}
}
</style>


<style id="principal-integrated-light-view">
/* ================================================================
   PRINCIPAL INTEGRATED LIGHT VIEW
   Visual direction: same overall canvas treatment as the EA dashboard.
   The content area is one continuous light surface; feature sections
   remain white, but are flatter and more naturally integrated instead
   of looking like isolated floating cards.
   ================================================================ */
html, body {
  background: #F8FAFC !important;
  color: #172033 !important;
}
body {
  background-image: none !important;
}

/* Continuous page canvas */
.main,
main.main,
.main-content,
.content,
.page-content {
  background: #F8FAFC !important;
  color: #172033 !important;
  min-height: 100vh;
}

/* Keep the existing navy Principal sidebar exactly as-is */
.sidebar {
  background: #0A192F !important;
}

/* Page heading sits directly on the canvas — not in a floating card. */
.main > .page-header,
main.main > .page-header {
  background: transparent !important;
  border: 0 !important;
  box-shadow: none !important;
  border-radius: 0 !important;
  padding: 0 !important;
  margin: 0 0 22px !important;
}

.page-title,
.page-header h1 {
  color: #0F172A !important;
}
.page-sub,
.page-header p {
  color: #64748B !important;
}

/* Major feature sections: white, but visually grounded on the light canvas. */
.period-strip,
.structure-note,
.stub-note,
.section,
.card-grid,
.eval-switcher,
.eval-banner,
.group-tab-wrap,
.group-tabs,
.desig-subtabs,
.faculty-subtabs,
.filter-bar,
.ra-table-card,
.ra-filters-panel,
.table-wrap,
.table-card,
.content-card,
.summary-card,
.standing-panel,
.history-card,
.people-list,
.evaluator-grid,
.no-archived,
.info-grid,
.sheet-header,
.scale-bar,
.q-table,
.comment-section,
.avg-summary {
  border-color: #E2E8F0 !important;
}

.period-strip,
.structure-note,
.section,
.sheet-header,
.scale-bar,
.comment-section,
.avg-summary,
.ra-table-card,
.ra-filters-panel,
.table-wrap,
.table-card,
.content-card,
.summary-card,
.standing-panel,
.history-card,
.people-list,
.evaluator-grid,
.no-archived,
.info-grid {
  background: #FFFFFF !important;
  color: #172033 !important;
  box-shadow: 0 2px 10px rgba(15,23,42,.055) !important;
}

/* Dashboard KPI/detail cards stay visible, but lose the heavy "floating" effect. */
.card-grid {
  background: transparent !important;
  box-shadow: none !important;
  border: 0 !important;
  padding: 0 !important;
}
.stat-card,
.stat-link .stat-card {
  background: #FFFFFF !important;
  border-color: #E2E8F0 !important;
  box-shadow: 0 3px 12px rgba(15,23,42,.055) !important;
}
.stat-card:hover,
.stat-link:hover .stat-card {
  box-shadow: 0 5px 14px rgba(15,23,42,.075) !important;
}

/* Common tables/inner surfaces stay clean and readable. */
table,
.eval-table-wrap,
.eval-q-card,
.q-result,
.received-item {
  background: #FFFFFF !important;
  color: #172033 !important;
  border-color: #E2E8F0 !important;
}

table thead th,
.eval-table th,
.q-table thead tr,
table.data th,
.q-table th {
  background: #F8FAFC !important;
  color: #475569 !important;
  border-color: #E2E8F0 !important;
}

table tbody td,
.eval-table td,
.q-table td,
table.data td {
  background: #FFFFFF !important;
  color: #334155 !important;
  border-color: #E2E8F0 !important;
}

table tbody tr:hover td,
.eval-table tr:hover td,
.q-table tr:hover td {
  background: #F8FAFC !important;
}

/* Report feature area follows the same integrated page treatment. */
.eval-tab,
.tab,
.level-tab,
.status-tab,
.group-tab,
.desig-subtab,
.faculty-subtab {
  background: transparent !important;
}

/* Inner highlighted controls can still use soft tint, never dark navy. */
.main .subtle-head,
.main .table-head,
.main .table-header,
.main .thead {
  background: #F4F8FF !important;
}

input,
select,
textarea,
.search-box input[type=text],
.search-box select,
.form-group input,
.filter-field select,
.filter-field input[type=text],
.ra-filter-row select,
.ra-search,
.eval-comment-box {
  background: #FFFFFF !important;
  color: #172033 !important;
  border-color: #CBD5E1 !important;
}

/* Avoid accidental dark-mode remnants in common feature containers. */
.main .modal,
.main .modal-content,
.main .dropdown,
.main .menu,
.main .popover,
.main .panel {
  background: #FFFFFF !important;
  color: #172033 !important;
  border-color: #E2E8F0 !important;
}

@media (max-width: 768px) {
  .main,
  main.main,
  .main-content,
  .content,
  .page-content {
    padding: 24px 18px !important;
  }
}

@media print {
  html, body, .main, main.main, .main-content, .content, .page-content {
    background: #FFFFFF !important;
  }
  .main > .page-header,
  main.main > .page-header {
    box-shadow: none !important;
  }
}
</style>



<style id="principal-ea-logs-canvas-final">
/* EA System Logs-like visual treatment:
   white viewport + a subtle #F8FAFC feature canvas inset inside it. */
html, body {
  background: #FFFFFF !important;
  background-image: none !important;
  color: #172033 !important;
}

.main,
main.main,
.main-content,
.content,
.page-content {
  position: relative !important;
  isolation: isolate !important;
  background: #FFFFFF !important;
  color: #172033 !important;
  min-height: 100vh;
}

/* The feature canvas does NOT cover the whole white page. */
.main::before,
main.main::before {
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
.sidebar {
  background: #0A192F !important;
}

/* Keep feature surfaces white, with only a very light edge/shadow. */
.main .page-header,
.main .section,
.main .card,
.main .panel,
.main .table-card,
.main .content-card,
.main .stat-card,
.main .period-strip,
.main .filter-bar,
.main .table-wrap,
.main .table-card-wrap,
.main .content-panel,
.main .eval-banner,
.main .info-banner,
.main .history-card,
.main .gl-card,
.main .amber-card,
.main .green-card,
.main .red-card,
.main .ra-table-card,
.main .eval-card,
.main .comment-section,
.main .avg-summary,
.main .standing-panel,
.main .person-row,
.main .target-card,
.main .no-eval,
.main .no-data,
.main .no-evaluated,
.main .no-archived,
.main .evaluator-grid,
.main .people-list,
.main .cat-section,
.main .results-card,
.main .result-card,
.main .feature-card {
  background: #FFFFFF !important;
  color: #172033 !important;
  border-color: #D9E4EF !important;
  box-shadow: 0 1px 5px rgba(15,23,42,.035) !important;
}

.main table thead th,
.main .table-head,
.main .table-header,
.main .thead,
.main .subtle-head {
  background: #F4F8FF !important;
  color: #4B6580 !important;
  border-color: #D9E4EF !important;
}

.main table tbody td {
  background: #FFFFFF !important;
  color: #172033 !important;
  border-color: #D9E4EF !important;
}

.main table tbody tr:hover td,
.main .person-row:hover,
.main .standing-item:hover {
  background: #F8FAFC !important;
}

.main input,
.main select,
.main textarea,
.main .search-box input[type=text],
.main .search-box select,
.main .form-group input,
.main .filter-field select,
.main .filter-field input[type=text],
.main .ra-filter-row select,
.main .ra-search,
.main .eval-comment-box {
  background: #FFFFFF !important;
  color: #172033 !important;
  border-color: #CBD5E1 !important;
}

.main .modal,
.main .modal-content,
.main .dropdown,
.main .menu,
.main .popover,
.main .panel {
  background: #FFFFFF !important;
  color: #172033 !important;
  border-color: #D9E4EF !important;
}

@media (max-width: 768px) {
  .main::before,
  main.main::before {
    left: 0;
    top: 52px;
    border-radius: 12px 0 0 0;
  }
}

@media print {
  html, body, .main, main.main, .main-content, .content, .page-content {
    background: #FFFFFF !important;
  }
  .main::before,
  main.main::before {
    display: none !important;
  }
}
</style>


<style id="principal-workspace-sharper-final">
/* Final Principal workspace composition: white outer page + sharper light workspace. */
html{background:#FFFFFF!important;color-scheme:light!important;}
body{background:#FFFFFF!important;background-image:none!important;color:#172033!important;}
.sidebar{background:#0A192F!important;}
.main,main.main{
  position:relative!important;
  background:#F3F6FA!important;
  color:#172033!important;
  border:1px solid #E5EAF0!important;
  border-bottom:0!important;
  border-radius:16px 16px 0 0!important;
  box-shadow:none!important;
}
.main > .page-header,main.main > .page-header{
  background:transparent!important;
  border:0!important;
  box-shadow:none!important;
}
.main .page-header,.main .section,.main .card,.main .panel,.main .table-card,.main .content-card,.main .stat-card,
.main .period-strip,.main .filter-bar,.main .table-wrap,.main .table-card-wrap,.main .content-panel,
.main .eval-banner,.main .info-banner,.main .history-card,.main .gl-card,.main .amber-card,.main .green-card,
.main .red-card,.main .ra-table-card,.main .eval-card,.main .comment-section,.main .avg-summary,.main .standing-panel,
.main .person-row,.main .target-card,.main .no-eval,.main .no-data,.main .no-evaluated,.main .no-archived,
.main .evaluator-grid,.main .people-list,.main .cat-section,.main .results-card,.main .result-card,.main .feature-card{
  background:#FFFFFF!important;
  color:#172033!important;
  border-color:#D9E4EF!important;
  box-shadow:0 1px 5px rgba(15,23,42,.035)!important;
}
.main table thead th,.main .table-head,.main .table-header,.main .thead,.main .subtle-head{
  background:#F4F8FF!important;color:#4B6580!important;border-color:#D9E4EF!important;
}
@media(max-width:768px){
  .main,main.main{margin:12px 12px 0!important;padding:20px 18px 28px!important;border-radius:12px 12px 0 0!important;}
}
@media print{
  html,body,.main,main.main{background:#FFFFFF!important;border-color:transparent!important;}
}
</style>
</head>
<body>

<?php render_principal_sidebar('dashboard', $me, $scopeLabel, $photo_src); ?>

<main class="main principal-dashboard" id="principalDashboard">
    <div class="principal-dashboard-shell">
        <section class="principal-dashboard-heading" aria-labelledby="principal-dashboard-title">
            <div class="principal-dashboard-eyebrow"><i class="fa-solid fa-chart-line"></i> Dashboard Overview</div>
            <h1 id="principal-dashboard-title"></h1>
        </section>

        <div class="schedule-status <?= htmlspecialchars($dashboardStatusClass, ENT_QUOTES, 'UTF-8') ?>">
            <i class="fa-solid <?= !$structureActive ? 'fa-circle-info' : ($evalOpen ? 'fa-lock-open' : 'fa-lock') ?>"></i>
            <strong><?= htmlspecialchars($dashboardStatusHeadline, ENT_QUOTES, 'UTF-8') ?></strong>
        </div>

        <section class="period-strip evaluation-schedule-strip" id="principalSchedule" aria-label="Evaluation schedule">
            <div class="period-item">
                <div class="k">Academic Year</div>
                <div class="v"><?= htmlspecialchars((string)($settings['academic_year'] ?? '—')) ?></div>
            </div>
            <div class="period-item">
                <div class="k">Evaluation Opens</div>
                <div class="v"><?= htmlspecialchars($formatScheduleDateTime($settings['eval_start'] ?? null)) ?></div>
            </div>
            <div class="period-item">
                <div class="k">Evaluation Closes</div>
                <div class="v"><?= htmlspecialchars($formatScheduleDateTime($settings['eval_end'] ?? null)) ?></div>
            </div>
            <div class="period-item">
                <div class="k">Status</div>
                <div class="v"><span class="period-badge <?= htmlspecialchars($dashboardStatusClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($dashboardStatusLabel, ENT_QUOTES, 'UTF-8') ?></span></div>
            </div>
        </section>

        <section class="principal-welcome-card" id="principalPerformanceSummary" aria-label="Principal overview">
            <div class="principal-welcome-copy">
                <h2>Welcome, <?= htmlspecialchars($dashboardDisplayName, ENT_QUOTES, 'UTF-8') ?>!</h2>
                <div class="principal-welcome-meta">
                    <strong><?= htmlspecialchars($scopeLabel, ENT_QUOTES, 'UTF-8') ?></strong>
                    <span class="principal-meta-dot">·</span>
                    <span><?= htmlspecialchars($dashboardCurrentPeriod !== '' ? $dashboardCurrentPeriod : 'No active evaluation period', ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </div>
        </section>

        <section class="principal-summary-grid" aria-label="Principal evaluation summary metrics">
            <a class="principal-summary-card summary-blue" href="principal_results.php" aria-label="View Evaluation Received page" title="View Evaluation Received">
                <span class="principal-summary-icon"><i class="fa-solid fa-comments" aria-hidden="true"></i></span>
                <span class="principal-summary-label">Evaluations Received</span>
                <strong class="principal-summary-value"><?= number_format($principalEvaluationResponses) ?></strong>
            </a>
            <a class="principal-summary-card summary-green" href="principal_results.php">
                <span class="principal-summary-icon"><i class="fa-solid fa-circle-check" aria-hidden="true"></i></span>
                <span class="principal-summary-label">Average Rating</span>
                <strong class="principal-summary-value"><?= $principalOverallAverage !== null ? number_format($principalOverallAverage, 2) . ' / 5' : '—' ?></strong>
            </a>
            <a class="principal-summary-card summary-amber" href="principal_results.php">
                <span class="principal-summary-icon"><i class="fa-solid fa-award" aria-hidden="true"></i></span>
                <span class="principal-summary-label">Performance Level</span>
                <strong class="principal-summary-value principal-performance-label<?= strlen($principalPerformanceLabel) > 20 ? ' is-long' : '' ?>"><?= htmlspecialchars($principalPerformanceLabel, ENT_QUOTES, 'UTF-8') ?></strong>
            </a>
            <a class="principal-summary-card summary-sky" href="#principalSchedule">
                <span class="principal-summary-icon"><i class="fa-solid fa-calendar" aria-hidden="true"></i></span>
                <span class="principal-summary-label">Current Period</span>
                <strong class="principal-summary-value principal-period-label"><?= htmlspecialchars($principalCurrentPeriodLabel, ENT_QUOTES, 'UTF-8') ?></strong>
            </a>
        </section>

        <?php /* No-active-period reminders are surfaced through the notification bell. */ ?>
        <?php if ($structureActive && !$hasPeriod): ?>
        <div class="stub-note">
            <i class="fa-solid fa-clock-rotate-left"></i>
            Rosters are live, but there is no active evaluation period in System &amp; Period settings yet. Completion and participation figures remain at 0% until a period opens.
        </div>
        <?php endif; ?>

        <section class="principal-workspace-shortcuts" aria-labelledby="principal-shortcuts-title">
            <div class="principal-shortcuts-heading">
                <div>
                    <div class="principal-shortcuts-eyebrow">Workspace Shortcuts</div>
                    <h2 id="principal-shortcuts-title">Continue where you need to be</h2>
                </div>
                <p></p>
            </div>
            <div class="principal-shortcuts-grid">
                <a class="principal-shortcut" href="principal_results.php">
                    <span class="principal-shortcut-icon"><i class="fa-solid fa-chart-column" aria-hidden="true"></i></span>
                    <span class="principal-shortcut-copy">
                        <strong>Your Evaluation</strong>
                        <small>Review your evaluation feedback and results.</small>
                    </span>
                    <i class="fa-solid fa-arrow-right principal-shortcut-arrow" aria-hidden="true"></i>
                </a>
                <a class="principal-shortcut" href="principal_evaluation_tracker.php">
                    <span class="principal-shortcut-icon"><i class="fa-solid fa-calendar-days" aria-hidden="true"></i></span>
                    <span class="principal-shortcut-copy">
                        <strong>Evaluation Tracker</strong>
                        <small>Monitor the live evaluation period and progress.</small>
                    </span>
                    <i class="fa-solid fa-arrow-right principal-shortcut-arrow" aria-hidden="true"></i>
                </a>
                <a class="principal-shortcut" href="principal_reports.php">
                    <span class="principal-shortcut-icon"><i class="fa-solid fa-award" aria-hidden="true"></i></span>
                    <span class="principal-shortcut-copy">
                        <strong>Reports</strong>
                        <small>Generate reports and view grade-level analytics.</small>
                    </span>
                    <i class="fa-solid fa-arrow-right principal-shortcut-arrow" aria-hidden="true"></i>
                </a>
            </div>
        </section>
    </div>
</main>

<style id="principal-white-theme-final">
:root{
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
html{background:#F8FAFC!important;color-scheme:light!important;}
body{background:#F8FAFC!important;background-image:none!important;color:#172033!important;}

/* Keep the existing navy sidebar; the content area is the white-theme area. */
.sidebar{background:#0A192F!important;color:#E0E6F0!important;border-right:1px solid #172A45!important;}
.sidebar *{color:inherit;}
.sidebar .sb-name{color:#fff!important;}
.sidebar .sb-role,.sidebar .sb-nav a i{color:#f0b84d!important;}
.sidebar .sb-scope,.sidebar .sb-nav a{color:#A0B3C6!important;}
.sidebar .sb-nav a:hover,.sidebar .sb-nav a.active{background:rgba(217,154,43,.15)!important;color:#fff!important;}
.sidebar .sb-logout a{color:#fca5a5!important;}

/* Main content surfaces */
main,.main,.main-content,.content,.page-content{background:#F8FAFC!important;color:#172033!important;}
.stat-card,.section,.period-strip,.filter-bar,.card,.panel,.table-wrap,
.content-card,.table-card,.summary-card,.sum-card,.standing-panel,.eval-card,
.eval-banner,.info-banner,.history-card,.gl-card,.amber-card,.green-card,.red-card,
.person-row,.target-card,.no-eval,.no-data,.no-evaluated,.comment-section,
.avg-summary,.cat-section,.people-list,.evaluator-grid,.eval-q-card,.eval-table-wrap,
.received-item,.q-result,.info-grid>div,.comment-modal,.ra-table-card,
.eval-switcher,.tabs,.level-tabs,.status-tabs,.faculty-subtabs{
  background:#fff!important;
  color:#172033!important;
  border-color:#e2e8f0!important;
  box-shadow:var(--shadow)!important;
}

/* Headings and readable data text */
.page-title,.page-header h1,.section-title,.sheet-name,.target-name,
h1,h2,h3,h4,h5,h6,.section h2,.eval-modal-title,.modal-section-title,
.stat-card .num,.tracker-item .big,.eval-q-text,.eval-qtext,.q-text,.info-value,
.received-anon,.cat-name-modal,.cat-score-modal{color:#0f172a!important;}
.page-sub,.sheet-desig,.target-desig,.muted,.hint,.helper,.filter-hint,.empty-note,
.main p,.main label,.main td,.main li,.main small,.q-score,.q-no,.received-meta,
.info-label,.loading-eval,.eval-rating-scale-note,.eval-read-score{color:#64748b!important;}
table{color:#172033!important;}
table thead th,table.data th,.q-table th{background:#f8fafc!important;color:#334155!important;border-color:#e2e8f0!important;}
table tbody td,table.data td,.q-table td{color:#334155!important;border-color:#e2e8f0!important;}
table tbody tr:hover,.person-row:hover,.standing-item:hover{background:#f8fafc!important;}

/* Accent elements stay amber/semantic rather than reverting to dark-mode text. */
.stat-card i,.section h2 i,.eval-q-category,.eval-category-heading,
.eval-modal-title i,.period-item .v,.period-badge,.back-link,
.stat-card .label,.period-item .k,.received-score,.score-big,.cat-score-modal,
.bell-btn,.bell-head button,.bell-list li i,.eval-banner-title,.eval-banner-icon,
.cat-title,.comment-title,.search-box button,.section h2 i{color:#d99a2b!important;}
.period-badge{background:rgba(217,154,43,.12)!important;border-color:rgba(217,154,43,.28)!important;}
.period-badge.closed{background:rgba(240,84,84,.10)!important;border-color:rgba(240,84,84,.28)!important;color:#dc2626!important;}
.period-badge.gray{background:#f1f5f9!important;border-color:#cbd5e1!important;color:#64748b!important;}

/* Dashboard evaluation schedule: closed/scheduled is red; open is green. */
.schedule-status{display:flex;align-items:center;gap:8px;width:max-content;max-width:100%;margin:0 0 18px;padding:9px 14px;border-radius:999px;font-size:13px;border:1px solid;}
.schedule-status i{font-size:13px;}
.schedule-status.closed{color:#b91c1c!important;background:rgba(240,84,84,.10)!important;border-color:rgba(240,84,84,.38)!important;}
.schedule-status.open{color:#047857!important;background:rgba(16,185,129,.10)!important;border-color:rgba(16,185,129,.35)!important;}
.period-badge.closed{background:rgba(240,84,84,.10)!important;border-color:rgba(240,84,84,.38)!important;color:#b91c1c!important;}
.period-badge.open{background:rgba(16,185,129,.10)!important;border-color:rgba(16,185,129,.35)!important;color:#047857!important;}
.evaluation-schedule-strip{display:grid!important;grid-template-columns:repeat(4,minmax(0,1fr));gap:26px;margin-bottom:22px;}
@media(max-width:900px){.evaluation-schedule-strip{grid-template-columns:repeat(2,minmax(0,1fr));}}
@media(max-width:560px){.evaluation-schedule-strip{grid-template-columns:1fr;}.schedule-status{width:100%;}}


/* Forms */
input,select,textarea,
.search-box input[type=text],.search-box select,.form-group input,
.filter-field select,.filter-field input[type=text],.ra-filter-row select,.ra-search,
.eval-comment-box{
  background:#fff!important;color:#172033!important;border-color:#cbd5e1!important;
}
input::placeholder,textarea::placeholder{color:#94a3b8!important;}
input:focus,select:focus,textarea:focus{border-color:#d99a2b!important;box-shadow:0 0 0 3px rgba(217,154,43,.10)!important;outline:none!important;}

/* Buttons / tabs */
.report-btns button,.qa-btns a,.filter-btns a,a.btn,
.btn,.action-btn,.back-btn,.btn-print,.btn-archive,.btn-restore,.btn-solid,
.btn-archived-link,.ra-tool-btn,.page-btn{
  background:rgba(217,154,43,.10)!important;
  border-color:rgba(217,154,43,.30)!important;
  color:#a16207!important;
}
.report-btns button:hover,.qa-btns a:hover,.filter-btns a:hover,a.btn:hover,
.btn:hover,.action-btn:hover,.back-btn:hover,.btn-print:hover,.btn-archive:hover,
.btn-restore:hover,.btn-archived-link:hover,.ra-tool-btn:hover,.page-btn:hover{
  background:rgba(217,154,43,.16)!important;color:#92400e!important;
}
.btn-primary,.btn-solid{background:#d99a2b!important;color:#0A192F!important;border-color:#d99a2b!important;}
.btn-primary:hover,.btn-solid:hover{background:#f0b84d!important;color:#0A192F!important;}
.report-btns a.active,.filter-btns a.active,.group-tab.active,
.eval-tab.student.active,.eval-tab.peer.active,.eval-tab.multi-role.active,
.tab.active,.level-tab.active,.status-tab.active,.desig-subtab.active,
.page-btn.active{background:rgba(217,154,43,.14)!important;color:#a16207!important;border-color:rgba(217,154,43,.35)!important;}
.eval-tab,.tab,.level-tab,.status-tab,.group-tab,.desig-subtab{color:#64748b!important;background:transparent!important;}
.eval-tab:hover,.tab:hover,.level-tab:hover,.status-tab:hover,.group-tab:hover,.desig-subtab:hover{color:#334155!important;background:#f8fafc!important;}

/* Evaluation questionnaire */
.eval-q-card,.eval-table-wrap{background:#fff!important;}
.eval-rating-opt{background:#f8fafc!important;color:#334155!important;border-color:#cbd5e1!important;}
.eval-rating-opt:has(input:checked){background:rgba(217,154,43,.10)!important;color:#92400e!important;border-color:#d99a2b!important;}
.eval-table th{background:#f8fafc!important;color:#64748b!important;border-color:#e2e8f0!important;}
.eval-table td{border-color:#e2e8f0!important;color:#334155!important;}
.eval-rating-cell label{background:#fff!important;color:#64748b!important;border-color:#cbd5e1!important;}
.eval-rating-cell label:hover{background:rgba(217,154,43,.08)!important;border-color:#d99a2b!important;}
.eval-rating-cell input:checked + label{background:#d99a2b!important;color:#fff!important;border-color:#d99a2b!important;}
.eval-qno{color:#b8801f!important;}
.eval-comment-box{color:#334155!important;}

/* Status pills */
.pill.good{background:rgba(16,185,129,.12)!important;color:#047857!important;}
.pill.warn{background:rgba(217,154,43,.12)!important;color:#a16207!important;}
.pill.bad{background:rgba(240,84,84,.10)!important;color:#b91c1c!important;}
.alert.success{background:rgba(16,185,129,.10)!important;color:#047857!important;border-color:rgba(16,185,129,.25)!important;}
.alert.error{background:rgba(240,84,84,.10)!important;color:#b91c1c!important;border-color:rgba(240,84,84,.25)!important;}

/* Progress bars */
.bar-wrap,.score-bar-bg,.eval-bar-bg,.avg-bar-bg,.cat-bar{background:#e2e8f0!important;}
.bar-fill,.avg-bar-fill,.cat-bar>div{background:linear-gradient(90deg,#b8801f,#f0b84d)!important;}

/* Notifications */
.notif-list li,.bell-list li{background:#f8fafc!important;color:#334155!important;border-color:#e2e8f0!important;}
.notif-list li.unseen,.bell-list li.unseen{background:rgba(217,154,43,.08)!important;}
.bell-btn{background:#fff!important;border-color:#cbd5e1!important;color:#d99a2b!important;}
.bell-btn:hover,.bell-btn[aria-expanded="true"]{background:rgba(217,154,43,.10)!important;border-color:rgba(217,154,43,.35)!important;}
.bell-panel{background:#fff!important;border-color:#e2e8f0!important;box-shadow:0 18px 44px rgba(15,23,42,.16)!important;color:#172033!important;}
.bell-head{border-color:#e2e8f0!important;}
.bell-head h3{color:#0f172a!important;}
.bell-list li{color:#334155!important;}
.bell-count{border-color:#fff!important;}

/* Reports / analytics data surfaces and modal */
.standing-score,.pstat-val,.avg-score-big,.avg-score-label{color:#047857!important;}
.standing-title.top{color:#047857!important;}
.standing-title.low{color:#dc2626!important;}
.comment-text{background:#f8fafc!important;color:#475569!important;border-color:#e2e8f0!important;}
.info-grid>div,.q-result,.received-item{border-color:#e2e8f0!important;}
.eval-modal-overlay{background:rgba(15,23,42,.55)!important;}
.eval-modal{background:#fff!important;color:#172033!important;border-color:#e2e8f0!important;}
.eval-modal-header{border-color:#e2e8f0!important;}
.eval-modal-close{color:#64748b!important;}
.star{color:#cbd5e1!important;}
.star.filled{color:#facc15!important;}

/* Account / settings / roster utilities */
.profile-photo-lg{border-color:#d99a2b!important;}
.avatar-sm{border-color:rgba(217,154,43,.45)!important;}
.btn-reset{color:#475569!important;border-color:#cbd5e1!important;background:#fff!important;}
.structure-note{background:rgba(217,154,43,.06)!important;border-color:rgba(217,154,43,.24)!important;}
.structure-note p{color:#475569!important;}
.structure-note p b{color:#0f172a!important;}

@media(max-width:768px){
  body{background:#fff!important;}
}
</style>

<style id="principal-f8fafc-workspace-final">
/* Principal workspace canvas: integrated light background matching the EA view. */
html, body {
  background: #F8FAFC !important;
  background-image: none !important;
  color: #172033 !important;
}
main, main.main, .main, .main-content, .content, .page-content {
  background: #F8FAFC !important;
  color: #172033 !important;
}
.sidebar { background: #0A192F !important; }
@media print {
  html, body, main, main.main, .main, .main-content, .content, .page-content {
    background: #fff !important;
  }
}
</style>
<script src="../admin/eval_status_poll.js" defer></script>
</body>
</html>

<style id="principal-ea-exact-workspace-canvas">
/*
  Principal workspace shell — matches the EA feature/iframe composition:
  white outer viewport + an inset #F8FAFC workspace canvas.
  The canvas is the feature surface; white cards remain white.
*/
html{
  background:#FFFFFF !important;
  color-scheme:light !important;
}
body{
  background:#FFFFFF !important;
  background-image:none !important;
  color:#172033 !important;
}

/* Leave the navy Principal sidebar unchanged. */
.sidebar{
  background:#0A192F !important;
  color:#E0E6F0 !important;
}

/* The feature workspace is inset, like the EA iframe inside its white page. */
.main,
main.main{
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
.main::before,
main.main::before{
  display:none !important;
  content:none !important;
}

/* Page headings live on the same light workspace surface, as on the EA
   feature pages rendered inside their iframe. */
.main > .page-header,
main.main > .page-header{
  background:transparent !important;
  border:0 !important;
  box-shadow:none !important;
}

/* White feature surfaces remain clearly distinct from the #F8FAFC canvas. */
.main .page-header:has(.page-title),
.main .section,
.main .card,
.main .panel,
.main .table-card,
.main .content-card,
.main .stat-card,
.main .period-strip,
.main .filter-bar,
.main .table-wrap,
.main .table-card-wrap,
.main .content-panel,
.main .eval-banner,
.main .info-banner,
.main .history-card,
.main .gl-card,
.main .amber-card,
.main .green-card,
.main .red-card,
.main .ra-table-card,
.main .eval-card,
.main .comment-section,
.main .avg-summary,
.main .standing-panel,
.main .person-row,
.main .target-card,
.main .no-eval,
.main .no-data,
.main .no-evaluated,
.main .no-archived,
.main .evaluator-grid,
.main .people-list,
.main .cat-section,
.main .results-card,
.main .result-card,
.main .feature-card{
  background:#FFFFFF !important;
  color:#172033 !important;
  border-color:#D9E4EF !important;
  box-shadow:0 1px 5px rgba(15,23,42,.035) !important;
}

/* Soft inner tint, matching the EA logs table header / page surfaces. */
.main table thead th,
.main .table-head,
.main .table-header,
.main .thead,
.main .subtle-head{
  background:#F4F8FF !important;
  color:#4B6580 !important;
  border-color:#D9E4EF !important;
}

/* Responsive: retain the inset composition without squeezing narrow screens. */
@media (max-width:768px){
  .main,
  main.main{
    margin:12px 12px 0 12px !important;
    padding:20px 18px 28px !important;
    min-height:calc(100vh - 12px) !important;
    border-radius:12px 12px 0 0 !important;
  }
}

@media print{
  html,body{
    background:#FFFFFF !important;
  }
  .main,
  main.main{
    margin:0 !important;
    padding:20px !important;
    min-height:0 !important;
    background:#FFFFFF !important;
    border-radius:0 !important;
  }
}

/* Faculty-inspired Principal dashboard overview and four summary cards. */
#principalDashboard.main {
  box-sizing: border-box !important;
  flex: 1 1 auto !important;
  width: auto !important;
  max-width: none !important;
  min-width: 0 !important;
  min-height: calc(100vh - 58px) !important;
  margin: 58px 0 0 248px !important;
  padding: 28px 28px 36px !important;
  border-radius: 0 !important;
  background: #F3F6FB !important;
  color: #172033 !important;
  box-shadow: none !important;
  isolation: auto !important;
  overflow: visible !important;
}
#principalDashboard .principal-dashboard-shell {
  width: 100% !important;
  max-width: 1680px !important;
  min-height: calc(100vh - 86px) !important;
  margin: 0 auto !important;
  padding: 27px 32px 34px !important;
  background: #FFFFFF !important;
  color: #172033 !important;
  border: 1px solid #DFE7F0 !important;
  border-radius: 23px !important;
  box-shadow: 0 2px 10px rgba(15,23,42,.025) !important;
}
#principalDashboard .principal-dashboard-heading {
  margin: 0 0 17px !important;
  padding: 0 !important;
  background: transparent !important;
  border: 0 !important;
  box-shadow: none !important;
}
#principalDashboard .principal-dashboard-eyebrow {
  display: flex !important;
  align-items: center !important;
  gap: 9px !important;
  margin-bottom: 4px !important;
  color: #B8801F !important;
  font-family: 'DM Sans', sans-serif !important;
  font-size: 12px !important;
  font-weight: 800 !important;
  letter-spacing: 1.25px !important;
  line-height: 1.4 !important;
  text-transform: uppercase !important;
}
#principalDashboard .principal-dashboard-eyebrow i { color: #B8801F !important; font-size: 13px !important; }
#principalDashboard #principal-dashboard-title {
  margin: 0 !important;
  color: #10223B !important;
  font-family: 'Rajdhani', sans-serif !important;
  font-size: clamp(26px, 2vw, 34px) !important;
  font-weight: 700 !important;
  letter-spacing: .25px !important;
  line-height: 1.18 !important;
}
#principalDashboard .principal-dashboard-heading p {
  margin: 6px 0 0 !important;
  color: #64748B !important;
  font-size: 14px !important;
  line-height: 1.55 !important;
}
#principalDashboard .schedule-status {
  display: inline-flex !important;
  align-items: center !important;
  gap: 8px !important;
  width: fit-content !important;
  max-width: 100% !important;
  margin: 0 0 18px !important;
  padding: 8px 14px !important;
  border: 1px solid #86EFAC !important;
  border-radius: 999px !important;
  background: #F0FDF4 !important;
  color: #047857 !important;
  font-family: 'DM Sans', sans-serif !important;
  font-size: 12px !important;
  font-weight: 700 !important;
  line-height: 1.35 !important;
}
#principalDashboard .schedule-status i { color: inherit !important; font-size: 12px !important; }
#principalDashboard .schedule-status.closed { color: #B91C1C !important; background: #FEF2F2 !important; border-color: #FECACA !important; }
#principalDashboard .evaluation-schedule-strip {
  display: grid !important;
  grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
  align-items: stretch !important;
  gap: 22px !important;
  margin: 0 0 24px !important;
  padding: 24px 29px !important;
  background: #FFFFFF !important;
  border: 1px solid #DFE7F0 !important;
  border-radius: 18px !important;
  box-shadow: 0 4px 14px rgba(15,23,42,.035) !important;
}
#principalDashboard .evaluation-schedule-strip .period-item { min-width: 0 !important; gap: 5px !important; }
#principalDashboard .evaluation-schedule-strip .period-item .k {
  color: #B8801F !important;
  font-size: 11px !important;
  font-weight: 800 !important;
  letter-spacing: .75px !important;
  line-height: 1.35 !important;
}
#principalDashboard .evaluation-schedule-strip .period-item .v {
  color: #13263F !important;
  font-size: 15px !important;
  font-weight: 700 !important;
  line-height: 1.45 !important;
  overflow-wrap: anywhere !important;
}
#principalDashboard .evaluation-schedule-strip .period-badge {
  display: inline-flex !important;
  align-items: center !important;
  padding: 6px 14px !important;
  border-radius: 999px !important;
  font-size: 11px !important;
  font-weight: 700 !important;
  background: #F0FDF4 !important;
  color: #047857 !important;
  border: 1px solid #86EFAC !important;
}
#principalDashboard .evaluation-schedule-strip .period-badge.closed { background: #FEF2F2 !important; color: #B91C1C !important; border-color: #FECACA !important; }
#principalDashboard .principal-welcome-card {
  display: flex !important;
  align-items: center !important;
  justify-content: space-between !important;
  gap: 24px !important;
  margin: 0 0 24px !important;
  padding: 27px 32px !important;
  border: 1px solid #E7D2A6 !important;
  border-radius: 20px !important;
  background: linear-gradient(110deg, #FFFFFF 0%, #FFFBF2 100%) !important;
  box-shadow: 0 4px 15px rgba(217,154,43,.055) !important;
}
#principalDashboard .principal-welcome-copy { min-width: 0 !important; }
#principalDashboard .principal-welcome-card h2 {
  margin: 0 !important;
  color: #10223B !important;
  font-family: 'Rajdhani', sans-serif !important;
  font-size: clamp(22px, 1.8vw, 27px) !important;
  font-weight: 700 !important;
  line-height: 1.25 !important;
}
#principalDashboard .principal-welcome-meta {
  display: flex !important;
  align-items: center !important;
  flex-wrap: wrap !important;
  gap: 8px !important;
  margin-top: 8px !important;
  color: #64748B !important;
  font-size: 13px !important;
  line-height: 1.45 !important;
}
#principalDashboard .principal-welcome-meta strong { color: #B8801F !important; font-weight: 700 !important; }
#principalDashboard .principal-meta-dot { color: #94A3B8 !important; }
#principalDashboard .principal-completion-panel {
  display: flex !important;
  flex: 0 0 31% !important;
  min-width: 205px !important;
  min-height: 104px !important;
  flex-direction: column !important;
  align-items: center !important;
  justify-content: center !important;
  padding: 12px 18px !important;
  border: 1px solid #EBD8AB !important;
  border-radius: 18px !important;
  background: #FFFAEB !important;
}
#principalDashboard .principal-completion-value {
  color: #B8801F !important;
  font-family: 'DM Sans', sans-serif !important;
  font-size: clamp(30px, 3vw, 42px) !important;
  font-weight: 800 !important;
  letter-spacing: -1.4px !important;
  line-height: 1.1 !important;
}
#principalDashboard .principal-completion-label {
  margin-top: 9px !important;
  color: #64748B !important;
  font-size: 11px !important;
  font-weight: 700 !important;
  letter-spacing: .8px !important;
  line-height: 1.35 !important;
  text-align: center !important;
  text-transform: uppercase !important;
}
#principalDashboard .principal-summary-grid {
  display: grid !important;
  grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
  gap: 16px !important;
  margin: 0 0 24px !important;
  padding: 0 !important;
  border: 0 !important;
  background: transparent !important;
  box-shadow: none !important;
}
#principalDashboard a.principal-summary-card {
  position: relative !important;
  display: flex !important;
  min-width: 0 !important;
  min-height: 158px !important;
  flex-direction: column !important;
  align-items: flex-start !important;
  padding: 20px 23px 21px !important;
  overflow: hidden !important;
  text-decoration: none !important;
  background: #FFFFFF !important;
  color: #13263F !important;
  border: 1px solid #DFE7F0 !important;
  border-radius: 17px !important;
  box-shadow: 0 3px 12px rgba(15,23,42,.045) !important;
  transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease !important;
}
#principalDashboard a.principal-summary-card::before {
  content: "" !important;
  position: absolute !important;
  top: 0 !important;
  left: 0 !important;
  right: 0 !important;
  height: 4px !important;
  background: #D99A2B !important;
}
#principalDashboard a.principal-summary-card:hover {
  transform: translateY(-2px) !important;
  border-color: #E7D1A5 !important;
  box-shadow: 0 8px 18px rgba(15,23,42,.075) !important;
}
#principalDashboard a.principal-summary-card:focus-visible {
  outline: 3px solid #D99A2B !important;
  outline-offset: 3px !important;
}
#principalDashboard .principal-summary-icon {
  display: flex !important;
  width: 40px !important;
  height: 40px !important;
  align-items: center !important;
  justify-content: center !important;
  margin: 0 0 14px !important;
  border-radius: 12px !important;
  background: #FFF1CF !important;
  color: #B8801F !important;
  font-size: 17px !important;
}
#principalDashboard .principal-summary-label {
  display: block !important;
  margin: 0 0 7px !important;
  color: #64748B !important;
  font-size: 11px !important;
  font-weight: 700 !important;
  letter-spacing: .75px !important;
  line-height: 1.35 !important;
  text-transform: uppercase !important;
}
#principalDashboard .principal-summary-value {
  display: block !important;
  color: #B8801F !important;
  font-family: 'DM Sans', sans-serif !important;
  font-size: clamp(25px, 2.1vw, 31px) !important;
  font-weight: 800 !important;
  letter-spacing: -.6px !important;
  line-height: 1.18 !important;
}
#principalDashboard .summary-green::before { background: #10B981 !important; }
#principalDashboard .summary-green .principal-summary-icon { background: #E3F9EE !important; color: #10B981 !important; }
#principalDashboard .summary-green .principal-summary-value { color: #059669 !important; }
#principalDashboard .summary-amber::before { background: #F59E0B !important; }
#principalDashboard .summary-amber .principal-summary-icon { background: #FFF4D8 !important; color: #B8801F !important; }
#principalDashboard .summary-amber .principal-summary-value { color: #B8801F !important; }
#principalDashboard .summary-sky::before { background: #B8801F !important; }
#principalDashboard .summary-sky .principal-summary-icon { background: #FFF1CF !important; color: #B8801F !important; }
#principalDashboard .summary-sky .principal-summary-value { color: #9A6700 !important; }

/* Preserve the existing dark-theme option while using the faculty-style light view by default. */
html.principal-theme-dark #principalDashboard.main { background: #0B1528 !important; color: #E0E6F0 !important; }
html.principal-theme-dark #principalDashboard .principal-dashboard-shell { background: #0F1F3D !important; border-color: rgba(255,255,255,.12) !important; color: #E0E6F0 !important; }
html.principal-theme-dark #principalDashboard #principal-dashboard-title,
html.principal-theme-dark #principalDashboard .principal-welcome-card h2 { color: #F8FAFC !important; }
html.principal-theme-dark #principalDashboard .principal-dashboard-heading p,
html.principal-theme-dark #principalDashboard .principal-welcome-meta,
html.principal-theme-dark #principalDashboard .principal-summary-label { color: #A0B3C6 !important; }
html.principal-theme-dark #principalDashboard .evaluation-schedule-strip,
html.principal-theme-dark #principalDashboard a.principal-summary-card { background: #172A45 !important; border-color: rgba(255,255,255,.10) !important; color: #E0E6F0 !important; }
html.principal-theme-dark #principalDashboard .evaluation-schedule-strip .period-item .v,
html.principal-theme-dark #principalDashboard .principal-summary-value { color: #F8FAFC !important; }
html.principal-theme-dark #principalDashboard .principal-welcome-card { background: linear-gradient(110deg,#0F1F3D,#13294A) !important; border-color: rgba(240,184,77,.30) !important; }
html.principal-theme-dark #principalDashboard .principal-completion-panel { background: #172A45 !important; border-color: rgba(240,184,77,.30) !important; }
html.principal-theme-dark #principalDashboard .principal-completion-value { color: #F0B84D !important; }
html.principal-theme-dark #principalDashboard .principal-summary-icon { background: rgba(217,154,43,.16) !important; }

@media (max-width: 1100px) {
  #principalDashboard .evaluation-schedule-strip { gap: 16px !important; padding: 20px !important; }
  #principalDashboard .principal-summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }
}
@media (max-width: 900px) {
  #principalDashboard.main { margin-left: 0 !important; }
  #principalDashboard .principal-dashboard-shell { max-width: none !important; }
}
@media (max-width: 768px) {
  #principalDashboard.main { margin: 0 !important; padding: 18px 12px 26px !important; min-height: calc(100vh - 58px) !important; }
  #principalDashboard .principal-dashboard-shell { min-height: 0 !important; padding: 22px 18px 25px !important; border-radius: 16px !important; }
  #principalDashboard .evaluation-schedule-strip { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; gap: 18px !important; }
  #principalDashboard .principal-welcome-card { align-items: stretch !important; flex-direction: column !important; padding: 22px !important; }
  #principalDashboard .principal-completion-panel { flex: 1 1 auto !important; width: 100% !important; min-width: 0 !important; min-height: 88px !important; }
}
@media (max-width: 520px) {
  #principalDashboard .evaluation-schedule-strip { grid-template-columns: 1fr !important; }
  #principalDashboard .principal-summary-grid { grid-template-columns: 1fr !important; }
  #principalDashboard .principal-summary-card { min-height: 145px !important; }
  #principalDashboard .principal-dashboard-shell { padding: 19px 14px 22px !important; }
}

</style>

<link rel="stylesheet" href="includes/principal_dark_repairs.css?v=20261011.4" id="principal-dark-repairs"/>

<style id="principal-one-view-dashboard-update">
/* Compact single-view workspace, aligned with the Faculty and Staff dashboards.
   The Principal's existing amber/gold accent is intentionally preserved. */
#principalDashboard.main {
  box-sizing:border-box !important;
  min-height:calc(100vh - 58px) !important;
  margin:58px 0 0 248px !important;
  padding:14px 28px 16px !important;
  overflow:visible !important;
}
#principalDashboard .principal-dashboard-shell {
  box-sizing:border-box !important;
  width:100% !important;
  max-width:1680px !important;
  min-height:calc(100vh - 88px) !important;
  margin:0 auto !important;
  padding:18px 26px 20px !important;
  border-radius:20px !important;
}
#principalDashboard .principal-dashboard-heading { margin:0 0 11px !important; }
#principalDashboard .principal-dashboard-eyebrow { margin-bottom:2px !important; font-size:10.5px !important; }
#principalDashboard #principal-dashboard-title { font-size:clamp(25px,1.9vw,31px) !important; line-height:1.12 !important; }
#principalDashboard .principal-dashboard-heading p { margin-top:4px !important; font-size:13px !important; line-height:1.4 !important; }
#principalDashboard .structure-note { gap:10px !important; margin:0 0 10px !important; padding:10px 14px !important; }
#principalDashboard .structure-note i { font-size:16px !important; }
#principalDashboard .structure-note p { font-size:11.5px !important; line-height:1.4 !important; }
#principalDashboard .schedule-status { margin:0 0 10px !important; padding:6px 11px !important; font-size:11px !important; gap:7px !important; }
#principalDashboard .evaluation-schedule-strip {
  gap:14px !important;
  padding:14px 22px !important;
  margin:0 0 14px !important;
  border-radius:15px !important;
}
#principalDashboard .evaluation-schedule-strip .period-item { gap:4px !important; }
#principalDashboard .evaluation-schedule-strip .period-item .k { font-size:10px !important; }
#principalDashboard .evaluation-schedule-strip .period-item .v { font-size:14px !important; line-height:1.3 !important; }
#principalDashboard .evaluation-schedule-strip .period-badge { padding:5px 12px !important; font-size:10.5px !important; }
#principalDashboard .principal-welcome-card {
  gap:18px !important;
  margin:0 0 14px !important;
  padding:16px 22px !important;
  border-radius:16px !important;
}
#principalDashboard .principal-welcome-card h2 { font-size:clamp(20px,1.6vw,24px) !important; }
#principalDashboard .principal-welcome-meta { margin-top:5px !important; font-size:12px !important; gap:6px !important; }
#principalDashboard .principal-completion-panel {
  flex-basis:25% !important;
  min-width:185px !important;
  min-height:76px !important;
  padding:9px 14px !important;
  border-radius:14px !important;
}
#principalDashboard .principal-completion-value { font-size:clamp(28px,2.5vw,36px) !important; }
#principalDashboard .principal-completion-label { margin-top:5px !important; font-size:10px !important; }
#principalDashboard .principal-summary-grid {
  gap:12px !important;
  margin:0 0 16px !important;
}
#principalDashboard a.principal-summary-card {
  min-height:112px !important;
  padding:13px 17px 15px !important;
  border-radius:14px !important;
}
#principalDashboard .principal-summary-icon {
  width:33px !important;
  height:33px !important;
  margin-bottom:8px !important;
  border-radius:10px !important;
  font-size:14px !important;
}
#principalDashboard .principal-summary-label { margin-bottom:4px !important; font-size:10px !important; }
#principalDashboard .principal-summary-value { font-size:clamp(23px,1.9vw,28px) !important; }
/* Keep all four KPI cards in the Principal's amber/gold theme. */
#principalDashboard .principal-summary-grid a.principal-summary-card::before { background:#D99A2B !important; }
#principalDashboard .principal-summary-grid .principal-summary-icon { background:#FFF1CF !important; color:#B8801F !important; }
#principalDashboard .principal-summary-grid .principal-summary-value { color:#B8801F !important; }
#principalDashboard .principal-summary-value.principal-performance-label { font-size:clamp(17px,1.45vw,23px) !important; letter-spacing:-.25px !important; overflow-wrap:anywhere !important; }
#principalDashboard .principal-summary-value.principal-performance-label.is-long { font-size:clamp(14px,1.1vw,17px) !important; line-height:1.25 !important; }
#principalDashboard .principal-summary-value.principal-period-label { font-size:clamp(19px,1.55vw,25px) !important; letter-spacing:-.3px !important; overflow-wrap:anywhere !important; }
html.principal-theme-dark #principalDashboard .principal-summary-grid .principal-summary-icon { background:rgba(217,154,43,.16) !important; color:#F0B84D !important; }
html.principal-theme-dark #principalDashboard .principal-summary-grid .principal-summary-value { color:#F0B84D !important; }
#principalDashboard .stub-note { margin:0 0 12px !important; padding:7px 10px !important; line-height:1.4 !important; }
#principalDashboard .principal-workspace-shortcuts { margin:0 !important; }
#principalDashboard .principal-shortcuts-heading {
  display:flex !important;
  align-items:flex-end !important;
  justify-content:space-between !important;
  gap:12px !important;
  margin:0 2px 8px !important;
}
#principalDashboard .principal-shortcuts-eyebrow {
  margin-bottom:2px !important;
  color:#B8801F !important;
  font-size:10px !important;
  font-weight:800 !important;
  letter-spacing:1.05px !important;
  line-height:1.3 !important;
  text-transform:uppercase !important;
}
#principalDashboard .principal-shortcuts-heading h2 {
  margin:0 !important;
  color:#13263F !important;
  font-family:'Rajdhani','DM Sans',sans-serif !important;
  font-size:19px !important;
  font-weight:800 !important;
  line-height:1.15 !important;
}
#principalDashboard .principal-shortcuts-heading p { margin:0 !important; color:#6D8194 !important; font-size:11px !important; white-space:nowrap !important; }
#principalDashboard .principal-shortcuts-grid { display:grid !important; grid-template-columns:repeat(3,minmax(0,1fr)) !important; gap:12px !important; }
#principalDashboard .principal-shortcut {
  display:flex !important;
  align-items:center !important;
  gap:12px !important;
  min-width:0 !important;
  min-height:78px !important;
  padding:12px 14px !important;
  color:#13263F !important;
  text-decoration:none !important;
  background:#FFFFFF !important;
  border:1px solid #DFE7F0 !important;
  border-radius:14px !important;
  box-shadow:0 3px 12px rgba(15,23,42,.035) !important;
  transition:transform .16s ease,border-color .16s ease,box-shadow .16s ease !important;
  scroll-margin-top:72px !important;
}
#principalDashboard .principal-shortcut:hover { transform:translateY(-1px) !important; border-color:#E7D1A5 !important; box-shadow:0 7px 16px rgba(15,23,42,.07) !important; }
#principalDashboard .principal-shortcut-icon {
  display:flex !important; align-items:center !important; justify-content:center !important;
  flex:0 0 36px !important; width:36px !important; height:36px !important;
  border-radius:11px !important; background:#FFF1CF !important; color:#B8801F !important; font-size:15px !important;
}
#principalDashboard .principal-shortcut-copy { display:flex !important; flex-direction:column !important; gap:3px !important; min-width:0 !important; }
#principalDashboard .principal-shortcut-copy strong { color:#13263F !important; font-size:12.5px !important; font-weight:800 !important; line-height:1.25 !important; }
#principalDashboard .principal-shortcut-copy small { color:#64748B !important; font-size:10.5px !important; line-height:1.35 !important; }
#principalDashboard .principal-shortcut-arrow { flex:0 0 auto !important; margin-left:auto !important; color:#B8801F !important; font-size:12px !important; }
html.principal-theme-dark #principalDashboard .principal-shortcuts-heading h2,
html.principal-theme-dark #principalDashboard .principal-shortcut-copy strong { color:#F8FAFC !important; }
html.principal-theme-dark #principalDashboard .principal-shortcuts-heading p,
html.principal-theme-dark #principalDashboard .principal-shortcut-copy small { color:#A0B3C6 !important; }
html.principal-theme-dark #principalDashboard .principal-shortcut { background:#172A45 !important; border-color:rgba(255,255,255,.10) !important; color:#E0E6F0 !important; }
html.principal-theme-dark #principalDashboard .principal-shortcut-icon { background:rgba(217,154,43,.16) !important; color:#F0B84D !important; }
html.principal-theme-dark #principalDashboard .principal-shortcut-arrow { color:#F0B84D !important; }
@media(max-width:1200px) {
  #principalDashboard .principal-dashboard-shell { padding:17px 20px 19px !important; }
  #principalDashboard .principal-summary-grid { gap:10px !important; }
  #principalDashboard a.principal-summary-card { padding:12px 14px !important; }
}
@media(max-width:900px) {
  #principalDashboard.main { margin-left:0 !important; }
  #principalDashboard .principal-dashboard-shell { max-width:none !important; min-height:0 !important; }
  #principalDashboard .principal-shortcuts-grid { grid-template-columns:1fr !important; }
}
@media(max-width:768px) {
  #principalDashboard.main { min-height:calc(100vh - 58px) !important; margin:0 !important; padding:18px 12px 24px !important; }
  #principalDashboard .principal-dashboard-shell { min-height:0 !important; padding:18px 16px 20px !important; }
  #principalDashboard .evaluation-schedule-strip { grid-template-columns:repeat(2,minmax(0,1fr)) !important; gap:14px !important; }
  #principalDashboard .principal-welcome-card { align-items:stretch !important; flex-direction:column !important; padding:16px !important; }
  #principalDashboard .principal-completion-panel { flex:1 1 auto !important; width:100% !important; min-width:0 !important; min-height:72px !important; }
  #principalDashboard .principal-shortcuts-heading { align-items:flex-start !important; flex-direction:column !important; }
  #principalDashboard .principal-shortcuts-heading p { white-space:normal !important; }
}
@media(max-width:520px) {
  #principalDashboard .evaluation-schedule-strip { grid-template-columns:1fr !important; }
  #principalDashboard .principal-summary-grid { grid-template-columns:1fr !important; }
  #principalDashboard .principal-shortcuts-grid { grid-template-columns:1fr !important; }
}
</style>