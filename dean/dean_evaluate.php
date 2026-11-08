<?php
// dean/dean_evaluate.php
// Evaluates Faculty, non-teaching Staff, and the Executive Assistant.
// QUESTION SOURCE OF TRUTH:
//   Faculty -> centralized Questionnaire > Faculty bank
//   Staff   -> centralized Questionnaire > Staff target set
//   EA      -> centralized Questionnaire > Executive Assistant (EA) target set
// The target eligibility rules remain Dean-specific; only the question source
// has been generalized.

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
require_once dirname(__DIR__) . '/shared/system_settings_service.php';
require_once dirname(__DIR__) . '/shared/QuestionnaireService.php';
require_once 'school_head_structure_gate.php';
qn_migrate_legacy_once($mysqli);

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'dean') {
    header("Location: dean_login.php");
    exit;
}
$deanId = (int)$_SESSION['user_id'];

function safe_rows(mysqli $mysqli, string $sql, string $types = '', array $params = []): array {
    try {
        $stmt = @$mysqli->prepare($sql);
        if (!$stmt) return [];
        if ($types !== '') $stmt->bind_param($types, ...$params);
        if (!@$stmt->execute()) { $stmt->close(); return []; }
        $res = $stmt->get_result();
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $rows;
    } catch (mysqli_sql_exception $e) {
        return [];
    }
}

function sh_user_levels(mysqli $mysqli, int $userId): array {
    // Level VALUES come from user_year_levels ONLY — see the matching
    // note on dean_roster_levels() in dean_evaluation.php.
    // teaching_assignments accumulates a new row per reassignment without
    // clearing the old one, so it is not safe to read year_level from it;
    // it's still fine for the coarse "has some teaching assignment"
    // existence check (sh_has_teaching_assignment()) below.
    $rows = safe_rows($mysqli, "SELECT year_level FROM user_year_levels WHERE user_id=? ORDER BY year_level", "i", [$userId]);
    return array_values(array_map(fn($r) => trim((string)$r['year_level']), $rows));
}

function sh_is_college_level(string $level): bool {
    return stripos($level, 'college') !== false
        || preg_match('/^(1st|2nd|3rd|4th)\s*Year\b/i', trim($level));
}

function sh_is_high_school_level(string $level): bool {
    return (bool)preg_match('/^Grade\s*(7|8|9|10|11|12)\b/i', trim($level));
}

function sh_has_teaching_assignment(mysqli $mysqli, int $userId): bool {
    $rows = safe_rows($mysqli, "
        SELECT 1 FROM teaching_assignments WHERE user_id=?
        UNION ALL
        SELECT 1 FROM user_year_levels WHERE user_id=?
        LIMIT 1
    ", "ii", [$userId, $userId]);
    return !empty($rows);
}

/**
 * Resolve the Questionnaire > Dean / Principal Evaluation target bucket.
 * The same person cannot be Staff and Faculty on this page:
 *   - Teacher / Teaching Staff -> Faculty, but only when inside the Dean's College scope.
 *   - Non-teaching Staff -> Staff, always eligible.
 *   - Superadmin/EA -> EA, always eligible.
 */
function dean_target_bucket(mysqli $mysqli, int $targetId): ?string {
    $rows = safe_rows($mysqli, "
        SELECT id, role, secondary_role, is_active, account_status
        FROM users
        WHERE id=? AND is_active=1 AND account_status='approved'
        LIMIT 1
    ", "i", [$targetId]);
    $u = $rows[0] ?? null;
    if (!$u) return null;

    $role = strtolower((string)$u['role']);
    if ($role === 'superadmin') return 'EA';

    $levels = sh_user_levels($mysqli, $targetId);
    $hasTeaching = $role === 'teacher'
        || (($u['secondary_role'] ?? '') === 'teacher')
        || sh_has_teaching_assignment($mysqli, $targetId);

    if ($role === 'staff' && !$hasTeaching) return 'Staff';
    if ($hasTeaching && count(array_filter($levels, 'sh_is_college_level')) > 0) return 'Faculty';

    return null;
}

function load_dean_questions(mysqli $mysqli, int $targetId, string $bucket): array {
    if ($bucket === 'Faculty') {
        $rows = qn_get_faculty_questions($mysqli);
        $source = 'evaluation';
    } elseif ($bucket === 'Staff') {
        $rows = qn_get_person_questions($mysqli, $targetId, 'Staff');
        $source = 'user';
    } else {
        $rows = qn_get_person_questions($mysqli, $targetId, 'EA');
        $source = 'user';
    }

    return array_map(fn($q) => [
        'id' => (int)$q['id'],
        'source' => $source,
        'category' => $q['category'] ?: 'General',
        'question' => $q['question_text'],
        'type' => 'rating',
        'max_score' => 5,
        'is_required' => 1,
    ], $rows);
}

function load_existing_answers(mysqli $mysqli, int $trackerId): array {
    return safe_rows($mysqli, "
        SELECT qa.question_source, qa.question_id, qa.user_question_id,
               qa.answer_score AS score,
               COALESCE(uq.question_text, eq.question_text) AS question,
               COALESCE(uq.category, eq.category, 'General') AS category,
               COALESCE(eq.id, 0) AS sort_key
        FROM questionnaire_answers qa
        LEFT JOIN user_questions uq
          ON qa.question_source='user' AND uq.id=qa.user_question_id
        LEFT JOIN evaluation_questions eq
          ON qa.question_source='evaluation' AND eq.id=qa.question_id
        WHERE qa.tracker_id=?
        ORDER BY category, sort_key, qa.id
    ", "i", [$trackerId]);
}

$validTabs = ['faculty', 'executive_assistant'];
$tab = $_GET['tab'] ?? 'faculty';
if (!in_array($tab, $validTabs, true)) $tab = 'faculty';
$targetId = (int)($_GET['user_id'] ?? 0);
$viewOnly = isset($_GET['view']);

if ($targetId <= 0) {
    header("Location: dean_evaluation.php");
    exit;
}

$settings = get_school_head_settings($mysqli, 'dean');
// Academic Structure / Academic Term gate: Dean owns College, Principal
// owns School Year. Narrow-only — existing scheduling, Force Open and
// Force Closed still decide open/closed while College is active.
$settings = sh_gate_apply($settings, 'dean');
$structureActive = !empty($settings['school_head_applicable']);
$period_id_int = (int)($settings['period_id'] ?? 0);
$hasPeriod = $period_id_int > 0;
$evalOpen = !empty($settings['school_head_is_open']);
const HIGHER_ED_LABEL = 'Higher Education';

if (!$structureActive) {
    http_response_code(403);
    exit('Higher Education is not the active academic structure right now.');
}

$bucket = dean_target_bucket($mysqli, $targetId);
if ($bucket === null) {
    http_response_code(403);
    exit('This person is not eligible for Dean evaluation. Dean Faculty evaluations are limited to College-assigned teaching personnel; non-teaching Staff and the EA remain eligible.');
}

$targetRows = safe_rows($mysqli, "
    SELECT id, full_name, designation, photo, department, role, secondary_role
    FROM users
    WHERE id=? AND is_active=1 AND account_status='approved'
    LIMIT 1
", "i", [$targetId]);
$target = $targetRows[0] ?? null;
if (!$target) {
    http_response_code(404);
    exit('Evaluation target not found.');
}

$targetRoleLabel = $bucket === 'Faculty'
    ? (($target['role'] === 'staff') ? 'Teaching Staff' : 'Faculty')
    : ($bucket === 'Staff' ? 'Staff' : 'Executive Assistant');

$questions = load_dean_questions($mysqli, $targetId, $bucket);
$noQuestionsConfigured = empty($questions);
$questionnaireTitle = $bucket === 'Faculty'
    ? 'Dean Evaluation — Faculty'
    : ($bucket === 'Staff' ? 'Dean Evaluation — Non-Teaching Staff' : 'Dean Evaluation — Executive Assistant');
$formType = 'school_head_dean_' . strtolower($bucket);

$existing = null;
if ($hasPeriod) {
    $existingRows = safe_rows($mysqli, "
        SELECT id, score, remarks AS comment, submitted_at
        FROM evaluation_tracker
        WHERE eval_type='school_head'
          AND evaluator_id=? AND target_user_id=? AND period_id=?
          AND status IN ('submitted','approved')
        ORDER BY submitted_at DESC, id DESC
        LIMIT 1
    ", "iii", [$deanId, $targetId, $period_id_int]);
    $existing = $existingRows[0] ?? null;
}

$existingAnswers = $existing ? load_existing_answers($mysqli, (int)$existing['id']) : [];
$readOnly = $viewOnly || $existing !== null || !$evalOpen || !$hasPeriod || $noQuestionsConfigured;

$error = '';
$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$readOnly) {
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Session expired. Please refresh and try again.';
    } else {
        $questions = load_dean_questions($mysqli, $targetId, $bucket);
        if (empty($questions)) {
            $error = 'No questions are currently configured for this evaluation target.';
        } else {
            $answers = [];
            $scoreSum = 0.0;
            $scoreCount = 0;

            foreach ($questions as $q) {
                $field = 'q_' . (int)$q['id'];
                $val = trim((string)($_POST[$field] ?? ''));
                if ($val === '') {
                    $error = 'Please rate every question from 1 to 5 before submitting.';
                    break;
                }
                $score = (float)$val;
                if ($score < 1 || $score > 5) {
                    $error = 'Every rating must be between 1 and 5.';
                    break;
                }

                $answers[] = [
                    'id' => (int)$q['id'],
                    'source' => $q['source'],
                    'score' => $score,
                ];
                $scoreSum += $score;
                $scoreCount++;
            }

            $comment = trim((string)($_POST['comment'] ?? ''));
            if ($error === '' && $comment === '') {
                $error = 'Please write a comment before submitting. Comments are required.';
            } elseif ($error === '' && $scoreCount === 0) {
                $error = 'There are no answerable questions in this questionnaire.';
            } elseif ($error === '' && $scoreCount !== count($questions)) {
                $missingCount = count($questions) - $scoreCount;
                $error = 'Please answer all required questions before submitting. ' .
                    $missingCount . ' question' . ($missingCount === 1 ? '' : 's') . ' remain' . ($missingCount === 1 ? 's' : '') . '.';
            }

            if ($error === '') {
                $avgScore = round($scoreSum / $scoreCount, 2);
                $level = 'college';

                $mysqli->begin_transaction();
                try {
                    $ins = $mysqli->prepare("
                        INSERT INTO evaluation_tracker
                            (eval_type, eval_bucket, level, status, score, remarks,
                             evaluator_id, target_user_id, period_id, form_type, submitted_at)
                        VALUES ('school_head', ?, ?, 'submitted', ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $ins->bind_param(
                        'ssdsiiis',
                        $bucket, $level, $avgScore, $comment,
                        $deanId, $targetId, $period_id_int, $formType
                    );
                    $ins->execute();
                    $trackerId = $mysqli->insert_id;
                    $ins->close();

                    $ains = $mysqli->prepare("
                        INSERT INTO questionnaire_answers
                            (tracker_id, question_id, user_question_id, question_source,
                             answer_text, answer_score, submitted_at)
                        VALUES (?, ?, ?, ?, ?, ?, NOW())
                    ");
                    foreach ($answers as $a) {
                        $questionId = $a['source'] === 'evaluation' ? $a['id'] : null;
                        $userQuestionId = $a['source'] === 'user' ? $a['id'] : null;
                        $source = $a['source'];
                        $answerText = null;
                        $score = $a['score'];
                        $ains->bind_param(
                            'iiissd',
                            $trackerId, $questionId, $userQuestionId, $source, $answerText, $score
                        );
                        $ains->execute();
                    }
                    $ains->close();

                    $mysqli->commit();
                    $saved = true;
                    $existing = [
                        'id' => $trackerId,
                        'score' => $avgScore,
                        'comment' => $comment,
                        'submitted_at' => date('Y-m-d H:i:s'),
                    ];
                    $existingAnswers = load_existing_answers($mysqli, $trackerId);
                    $readOnly = true;
                } catch (mysqli_sql_exception $e) {
                    $mysqli->rollback();
                    $error = 'Could not save this evaluation. Nothing was recorded — please try again.';
                }
            }
        }
    }
}

$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$tPhoto = !empty($target['photo']) ? '../image/' . $target['photo'] : '../image/pbi_logo';
$mysqli->close();
?>
<!DOCTYPE html>
<html lang="en" class="eval-scroll-lock">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>PBI — <?= htmlspecialchars($questionnaireTitle) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<style>
:root{--dark:#0A192F;--mid:#172A45;--inner:#0F1F3D;--violet:#7C5FD9;--violet-h:#9C85F0;--violet-dark:#5F45B8;--light:#E0E6F0;--muted:#A0B3C6;--radius:10px;--shadow:0 8px 32px rgba(0,0,0,0.45);--danger:#f05454;--good:#10B981;}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{min-height:100vh;background:linear-gradient(rgba(5,18,36,.72),rgba(5,18,36,.82)),url('../background.png') center center / cover no-repeat fixed;background-color:var(--dark);font-family:'DM Sans',sans-serif;color:var(--light);display:flex;}

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

.main{flex:1;padding:36px 44px;max-width:760px;}
.back-link{color:var(--violet-h);font-size:13px;text-decoration:none;display:inline-flex;align-items:center;gap:6px;margin-bottom:18px;}
.back-link:hover{text-decoration:underline;}

.person-card{display:flex;align-items:center;gap:16px;background:rgba(23,42,69,.85);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:20px 22px;box-shadow:var(--shadow);margin-bottom:24px;}
.person-photo{width:60px;height:60px;border-radius:50%;object-fit:cover;border:2px solid var(--violet);}
.person-name{font-family:'Rajdhani',sans-serif;font-size:20px;font-weight:700;color:#fff;}
.person-meta{font-size:12.5px;color:var(--muted);margin-top:2px;}

.alert{border-radius:8px;padding:11px 14px;font-size:13px;margin-bottom:18px;display:flex;align-items:center;gap:8px;}
.alert-error{background:rgba(240,84,84,.12);border:1px solid rgba(240,84,84,.35);color:#ff8a8a;}
.alert-success{background:rgba(74,222,128,.12);border:1px solid rgba(74,222,128,.35);color:#a9f0c4;}
.alert-info{background:rgba(124,95,217,.1);border:1px solid rgba(124,95,217,.3);color:var(--violet-h);}

.q-block{background:rgba(23,42,69,.85);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:20px 22px;box-shadow:var(--shadow);margin-bottom:16px;}
.q-cat{font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--violet-h);margin-bottom:6px;}
.q-text{font-size:14.5px;color:var(--light);margin-bottom:14px;}
.rating-row{display:flex;gap:10px;}
.rating-opt{flex:1;text-align:center;}
.rating-opt input{display:none;}
.rating-opt label{display:block;padding:10px 0;border-radius:8px;border:1px solid rgba(255,255,255,.12);background:rgba(10,25,47,.5);color:var(--muted);font-size:13px;font-weight:600;cursor:pointer;transition:all .15s;}
.rating-opt input:checked + label{background:var(--violet);border-color:var(--violet);color:#fff;}
.rating-opt label:hover{border-color:var(--violet-h);}
.rating-readonly{display:flex;align-items:center;gap:6px;}
.rating-readonly .stars{color:var(--violet-h);}

.comment-block{background:rgba(23,42,69,.85);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:20px 22px;box-shadow:var(--shadow);margin-bottom:22px;}
.comment-block label{display:block;font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--muted);margin-bottom:10px;}
.comment-block textarea{width:100%;min-height:100px;background:rgba(10,25,47,.7);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:var(--light);font-size:13.5px;font-family:'DM Sans',sans-serif;padding:12px;outline:none;resize:vertical;}
.comment-block textarea:focus{border-color:var(--violet);}
.comment-readonly{font-size:13.5px;color:var(--light);font-style:italic;line-height:1.6;}

.btn-submit{padding:13px 26px;background:var(--violet);border:none;border-radius:var(--radius);color:#fff;font-size:14.5px;font-weight:600;font-family:'DM Sans',sans-serif;cursor:pointer;box-shadow:0 4px 16px rgba(124,95,217,.4);display:inline-flex;align-items:center;gap:8px;}
.btn-submit:hover{background:var(--violet-h);}

.summary-score{display:flex;align-items:baseline;gap:8px;margin-bottom:4px;}
.summary-score .num{font-family:'Rajdhani',sans-serif;font-size:34px;font-weight:700;color:#fff;}
.summary-score .of{font-size:13px;color:var(--muted);}

@media(max-width:768px){body{flex-direction:column;}.sidebar{width:100%;min-height:auto;}.rating-row{flex-wrap:wrap;}.rating-opt{min-width:50px;}}

/* Compact questionnaire table layout */
.eval-table{width:100%;border-collapse:collapse;table-layout:fixed;}
.eval-table-wrap{background:rgba(23,42,69,.85);border:1px solid rgba(255,255,255,.08);border-radius:14px;overflow:hidden;box-shadow:var(--shadow);margin-bottom:18px;}
.eval-table th{background:rgba(255,255,255,.045);color:var(--muted);font-size:11px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;text-align:center;padding:11px 8px;border-bottom:1px solid rgba(255,255,255,.08)}
.eval-table th:first-child{text-align:left;width:auto;padding-left:16px}.eval-table th:not(:first-child){width:58px}
.eval-table td{padding:12px 8px;border-bottom:1px solid rgba(255,255,255,.07);vertical-align:middle;text-align:center}.eval-table tr:last-child td{border-bottom:none}.eval-table td:first-child{text-align:left;padding-left:16px;padding-right:14px}
.eval-qno{color:var(--violet-h);font-weight:800;margin-right:7px}.eval-qtext{font-size:14px;color:var(--light);line-height:1.45}.eval-rating-cell{display:flex;justify-content:center;align-items:center}.eval-rating-cell input{position:absolute;opacity:0;pointer-events:none}.eval-rating-cell label{width:38px;height:34px;display:flex;align-items:center;justify-content:center;border-radius:7px;border:1px solid rgba(255,255,255,.12);background:rgba(10,25,47,.5);color:var(--muted);font-size:13px;font-weight:800;cursor:pointer;transition:.15s ease}.eval-rating-cell label:hover{border-color:var(--violet-h)}.eval-rating-cell input:checked + label{background:var(--violet);border-color:var(--violet);color:#fff}.eval-category-heading{font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--violet-h);margin:24px 0 9px}.eval-category-heading:first-child{margin-top:0}
.eval-question-row.is-unanswered td{background:rgba(240,84,84,.055);border-bottom-color:rgba(240,84,84,.22)}
.eval-question-row.is-unanswered td:first-child{box-shadow:inset 4px 0 0 #f05454}
.eval-question-row.is-unanswered .eval-qno{color:#ff8a8a}
.eval-completion-status{display:flex;align-items:center;gap:9px;margin:8px 0 12px;padding:10px 13px;border:1px solid rgba(16,185,129,.25);border-radius:9px;background:rgba(16,185,129,.08);color:#a9f0c4;font-size:13px;font-weight:600}
.eval-completion-status i{font-size:13px}
.eval-completion-status.is-incomplete{border-color:rgba(240,84,84,.35);background:rgba(240,84,84,.10);color:#ffb0b0}
.eval-validation-summary{margin:0 0 14px;padding:12px 14px;border-radius:9px;border:1px solid rgba(240,84,84,.4);background:rgba(240,84,84,.12);color:#ffd0d0;font-size:13px;line-height:1.55}
.eval-validation-summary strong{color:#fff}
.eval-validation-summary[hidden]{display:none}
@media(max-width:768px){.eval-table th:not(:first-child){width:48px}.eval-rating-cell label{width:32px;height:30px}.eval-qtext{font-size:13px}}

</style>
<link rel="stylesheet" href="includes/dean_light_theme.css?v=dashboard-ui-20261009"/>
<style>
/* Evaluation page: keep the sidebar fixed and scroll the feature workspace internally. */
html.eval-scroll-lock,
html.eval-scroll-lock body {
  overflow: hidden !important;
}

main.main.evaluation-workspace {
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

main.main.evaluation-workspace::-webkit-scrollbar {
  width: 10px;
}

main.main.evaluation-workspace::-webkit-scrollbar-track {
  background: #EEF2F6;
  border-radius: 10px;
}

main.main.evaluation-workspace::-webkit-scrollbar-thumb {
  background: #AEBAC8;
  border: 2px solid #EEF2F6;
  border-radius: 10px;
}

main.main.evaluation-workspace::-webkit-scrollbar-thumb:hover {
  background: #8F9CAB;
}

@media (max-width: 768px) {
  html.eval-scroll-lock,
  html.eval-scroll-lock body {
    overflow: auto !important;
  }

  main.main.evaluation-workspace {
    height: auto !important;
    max-height: none !important;
    min-height: calc(100vh - 12px) !important;
    overflow: visible !important;
    scrollbar-gutter: auto;
  }
}
</style>
</head>
<body>

<?php
$active = 'evaluation';
$sidebarScope = HIGHER_ED_LABEL . ' Division';
include __DIR__ . '/includes/dean_sidebar.php'; if (is_file(__DIR__ . '/dean_header_dark.php')) include __DIR__ . '/dean_header_dark.php';
?>

<main class="main evaluation-workspace">
    <a href="dean_evaluation.php?tab=<?= urlencode($tab) ?>" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to Evaluation</a>

    <div class="schedule-strip" style="display:grid;grid-template-columns:1fr 1fr 140px;gap:12px;margin:14px 0 18px;padding:14px 16px;border:1px solid rgba(255,255,255,.08);border-radius:12px;background:rgba(255,255,255,.035);">
        <div><div style="font-size:10px;text-transform:uppercase;letter-spacing:.08em;opacity:.65;font-weight:700;">Evaluation Opens</div><div style="margin-top:4px;font-size:15px;font-weight:700;"><?= $settings['eval_start_display'] !== '' ? htmlspecialchars($settings['eval_start_display']) : '—' ?></div></div>
        <div><div style="font-size:10px;text-transform:uppercase;letter-spacing:.08em;opacity:.65;font-weight:700;">Evaluation Closes</div><div style="margin-top:4px;font-size:15px;font-weight:700;"><?= $settings['eval_end_display'] !== '' ? htmlspecialchars($settings['eval_end_display']) : '—' ?></div></div>
        <div><div style="font-size:10px;text-transform:uppercase;letter-spacing:.08em;opacity:.65;font-weight:700;">Current State</div><div style="margin-top:4px;font-size:15px;font-weight:800;"><?= htmlspecialchars($settings['status']['label']) ?></div></div>
    </div>

    <div class="person-card">
        <img class="person-photo" src="<?= !empty($target['photo']) ? htmlspecialchars(UPLOAD_URL . $target['photo']) : htmlspecialchars(UPLOAD_URL . 'pbi_logo') ?>" alt=""/>
        <div>
            <div class="person-name"><?= htmlspecialchars($target['full_name']) ?></div>
            <div class="person-meta">
                <?= htmlspecialchars($targetRoleLabel) ?><?php if (!empty($target['department'])): ?> · <?= htmlspecialchars($target['department']) ?><?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (!$hasPeriod): ?>
        <div class="alert alert-info"><i class="fa-solid fa-circle-info"></i> No active evaluation period right now.</div>
    <?php elseif ($noQuestionsConfigured && !$existing): ?>
        <div class="alert alert-info"><i class="fa-solid fa-circle-info"></i> No questions have been configured for this evaluation target yet. Contact your administrator.</div>
    <?php elseif (!$evalOpen && !$existing): ?>
        <div class="alert alert-info"><i class="fa-solid fa-lock"></i> Evaluation is currently closed for this period.</div>
    <?php else: ?>

        <?php if ($error): ?>
        <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($saved): ?>
        <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> Evaluation submitted. Thank you.</div>
        <?php elseif ($readOnly && $existing): ?>
        <div class="alert alert-info"><i class="fa-solid fa-eye"></i> You already evaluated <?= htmlspecialchars($target['full_name']) ?> this period — showing what was submitted.</div>
        <?php endif; ?>

        <?php if ($readOnly && $existing): ?>
            <div class="q-block">
                <div class="summary-score">
                    <span class="num"><?= htmlspecialchars((string)$existing['score']) ?></span>
                    <span class="of">/ 5.00 overall</span>
                </div>
                <div class="person-meta">Submitted <?= htmlspecialchars(date('M j, Y g:i A', strtotime($existing['submitted_at']))) ?></div>
            </div>
            <?php foreach ($existingAnswers as $a): ?>
            <div class="q-block">
                <div class="q-cat"><?= htmlspecialchars($a['category']) ?></div>
                <div class="q-text"><?= htmlspecialchars($a['question']) ?></div>
                <div class="rating-readonly"><span class="stars"><?= str_repeat('★', (int)$a['score']) . str_repeat('☆', 5 - (int)$a['score']) ?></span> <?= (int)$a['score'] ?>/5</div>
            </div>
            <?php endforeach; ?>
            <div class="comment-block">
                <label>Comment</label>
                <p class="comment-readonly"><?= $existing['comment'] !== '' ? htmlspecialchars($existing['comment']) : 'No written comment.' ?></p>
            </div>
        <?php else: ?>
            <form method="POST" action="dean_evaluate.php?tab=<?= urlencode($tab) ?>&user_id=<?= (int)$target['id'] ?>" id="deanEvalForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <?php $groupedQuestions=[]; foreach($questions as $q){ $groupedQuestions[$q['category'] ?: 'General'][]=$q; } $qNo=1; ?>
                <?php foreach($groupedQuestions as $cat=>$catQuestions): ?>
                <div class="eval-category-heading"><?= htmlspecialchars($cat) ?></div>
                <div class="eval-table-wrap">
                    <table class="eval-table">
                        <thead><tr><th>Question</th><th>5</th><th>4</th><th>3</th><th>2</th><th>1</th></tr></thead>
                        <tbody>
                        <?php foreach($catQuestions as $q): ?>
                        <?php $currentQNo = $qNo++; ?>
                        <tr class="eval-question-row" data-question-number="<?= $currentQNo ?>">
                            <td><div class="eval-qtext"><span class="eval-qno"><?= $currentQNo ?>.</span><?= htmlspecialchars($q['question']) ?></div></td>
                            <?php for($i=5;$i>=1;$i--): ?>
                            <td><div class="eval-rating-cell"><input type="radio" name="q_<?= (int)$q['id'] ?>" id="q_<?= (int)$q['id'] ?>_<?= $i ?>" value="<?= $i ?>" required><label for="q_<?= (int)$q['id'] ?>_<?= $i ?>"><?= $i ?></label></div></td>
                            <?php endfor; ?>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endforeach; ?>

                <div class="eval-completion-status" id="evalCompletionStatus" role="status" aria-live="polite">
                    <i class="fa-solid fa-circle-check"></i>
                    <span id="evalCompletionText">0 of <?= count($questions) ?> questions answered</span>
                </div>
                <div class="eval-validation-summary" id="evalValidationSummary" role="alert" aria-live="assertive" hidden></div>
                <div class="comment-block"><label for="comment">Comment <span style="color:#ef4444">*</span></label><textarea id="comment" name="comment" required aria-required="true" placeholder="Share your feedback (required)..."></textarea></div>
                <button type="submit" class="btn-submit" <?= (!$hasPeriod || !$evalOpen) ? 'disabled style="opacity:.5;cursor:not-allowed;"' : '' ?>><i class="fa-solid fa-paper-plane"></i> Submit Evaluation</button>
            </form>
        <?php endif; ?>

    <?php endif; ?>
</main>
<script>
(function(){
    const form = document.getElementById('deanEvalForm');
    const status = document.getElementById('evalCompletionStatus');
    const statusText = document.getElementById('evalCompletionText');
    const summary = document.getElementById('evalValidationSummary');
    if (!form || !status || !statusText) return;

    const rows = Array.from(form.querySelectorAll('.eval-question-row'));
    const total = rows.length;

    function rowAnswered(row){
        return !!row.querySelector('input[type="radio"]:checked');
    }

    function updateProgress(){
        let answered = 0;
        rows.forEach(row => {
            const done = rowAnswered(row);
            row.classList.toggle('is-unanswered', false);
            if (done) answered++;
        });
        statusText.textContent = answered + ' of ' + total + ' questions answered';
        status.classList.toggle('is-incomplete', answered < total);
        const icon = status.querySelector('i');
        if (icon) icon.className = answered === total ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-exclamation';
        return answered;
    }

    function showMissing(){
        const missing = rows.filter(row => !rowAnswered(row));
        rows.forEach(row => row.classList.toggle('is-unanswered', !rowAnswered(row)));
        const nums = missing.map(row => row.dataset.questionNumber);
        const count = missing.length;
        summary.hidden = false;
        summary.innerHTML = '<strong>Please complete all required questions before submitting.</strong> ' +
            'You have ' + count + ' unanswered question' + (count === 1 ? '' : 's') +
            '. Missing question' + (count === 1 ? '' : 's') + ': ' + nums.join(', ') + '.';
        missing[0]?.scrollIntoView({behavior:'smooth', block:'center'});
        const first = missing[0]?.querySelector('input[type="radio"]');
        if (first) first.focus({preventScroll:true});
    }

    form.addEventListener('change', function(e){
        if (!e.target.matches('input[type="radio"]')) return;
        const row = e.target.closest('.eval-question-row');
        if (row) row.classList.remove('is-unanswered');
        updateProgress();
        const remaining = rows.filter(r => !rowAnswered(r));
        if (remaining.length === 0) {
            summary.hidden = true;
            summary.innerHTML = '';
        }
    });

    form.addEventListener('submit', function(e){
        const answered = updateProgress();
        if (answered !== total) {
            e.preventDefault();
            showMissing();
            return;
        }
        summary.hidden = true;
        summary.innerHTML = '';
    });

    updateProgress();
})();
</script>
<script src="../admin/eval_status_poll.js" defer></script>
</body>
<link rel="stylesheet" href="includes/dean_light_theme.css?v=dashboard-ui-20261009" id="dean-light-theme-final"/>
</html>