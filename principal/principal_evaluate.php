<?php
// principal/principal_evaluate.php
// Legacy URL kept so old links/bookmarks keep working. The evaluation form now
// lives in the rating modal on principal_evaluations.php (including the
// "Leave without submitting?" guard). All eligibility, period and CSRF checks
// are enforced there, so nothing is duplicated here.
$tid = (int)($_GET['tid'] ?? 0);
$qs  = $tid > 0 ? '?open=' . $tid . (isset($_GET['view']) ? '&view=1' : '') : '';
header('Location: principal_evaluations.php' . $qs);
exit;
}

function principal_user_levels(mysqli $mysqli, int $userId): array {
    // Level VALUES come from user_year_levels ONLY — see the matching
    // note on principal_roster_levels() in principal_evaluations.php.
    // teaching_assignments accumulates a new row per reassignment without
    // clearing the old one, so it is not safe to read year_level from it;
    // it's still fine for the coarse "has some teaching assignment"
    // existence check (principal_has_teaching_assignment()) below.
    if (!function_exists('safe_rows')) return [];
    return array_values(array_map(fn($r) => trim((string)$r['year_level']),
        safe_rows($mysqli, "SELECT year_level FROM user_year_levels WHERE user_id=? ORDER BY year_level", "i", [$userId])
    ));
}
function principal_has_teaching_assignment(mysqli $mysqli, int $userId): bool {
    $rows = safe_rows($mysqli, "
        SELECT 1 FROM teaching_assignments WHERE user_id=?
        UNION ALL
        SELECT 1 FROM user_year_levels WHERE user_id=?
        LIMIT 1
    ", "ii", [$userId, $userId]);
    return !empty($rows);
}
function principal_is_high_level(string $level): bool {
    return (bool)preg_match('/^Grade\s*(7|8|9|10|11|12)\b/i', trim($level));
}
function principal_target_bucket(mysqli $mysqli, int $targetId): ?string {
    $rows = safe_rows($mysqli, "
        SELECT id, role, secondary_role
        FROM users
        WHERE id=? AND is_active=1 AND account_status='approved'
        LIMIT 1
    ", "i", [$targetId]);
    $u = $rows[0] ?? null;
    if (!$u) return null;

    $role = strtolower((string)$u['role']);
    if ($role === 'superadmin') return 'EA';

    $levels = principal_user_levels($mysqli, $targetId);
    $hasTeaching = $role === 'teacher'
        || (($u['secondary_role'] ?? '') === 'teacher')
        || principal_has_teaching_assignment($mysqli, $targetId);

    if ($role === 'staff' && !$hasTeaching) return 'Staff';

    $insideScope = false;
    foreach ($levels as $level) {
        if (principal_is_high_level($level)) { $insideScope = true; break; }
    }
    return $hasTeaching && $insideScope ? 'Faculty' : null;
}
function principal_load_questions(mysqli $mysqli, int $targetId, string $bucket): array {
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
function principal_existing_answers(mysqli $mysqli, int $trackerId): array {
    return safe_rows($mysqli, "
        SELECT qa.question_source, qa.question_id, qa.user_question_id, qa.answer_score,
               COALESCE(uq.question_text, eq.question_text) AS question_text,
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

$validBuckets = ['Faculty', 'Staff', 'Executive Assistant'];
if ($requestedBucket !== null && !in_array($requestedBucket, $validBuckets, true)) $requestedBucket = null;

$bucket = principal_target_bucket($mysqli, $tid);
if ($bucket === null) {
    http_response_code(403);
    exit('This person is not eligible for Principal evaluation. Principal Faculty evaluations are limited to High School / SHS-assigned teaching personnel; non-teaching Staff and the EA remain eligible.');
}

$targetRows = safe_rows($mysqli, "
    SELECT id, full_name, designation, photo, department, role, secondary_role
    FROM users
    WHERE id=? AND is_active=1 AND account_status='approved'
    LIMIT 1
", "i", [$tid]);
$target = $targetRows[0] ?? null;
if (!$target) {
    http_response_code(404);
    exit('Evaluation target not found.');
}

$targetRoleLabel = $bucket === 'Faculty'
    ? (($target['role'] === 'staff') ? 'Teaching Staff' : 'Faculty')
    : ($bucket === 'Staff' ? 'Staff' : 'Executive Assistant');

$questions = principal_load_questions($mysqli, $tid, $bucket);
$noQuestionsConfigured = empty($questions);
$questionnaireTitle = $bucket === 'Faculty'
    ? 'Principal Evaluation — Faculty'
    : ($bucket === 'Staff' ? 'Principal Evaluation — Non-Teaching Staff' : 'Principal Evaluation — Executive Assistant');
$formType = 'school_head_principal_' . strtolower($bucket);

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
    ", "iii", [$evaluator_id, $tid, $period_id_int]);
    $existing = $existingRows[0] ?? null;
}
$existingAnswers = $existing ? principal_existing_answers($mysqli, (int)$existing['id']) : [];
$readOnly = $viewOnly || $existing !== null || !$evalOpen || !$hasPeriod || $noQuestionsConfigured;

// Fresh server-side boundary check: a form left open in a browser must not
// become submittable before the configured Manila opening instant.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !principal_schedule_is_open($settings)) {
    $readOnly = true;
    $evalOpen = false;
}

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$readOnly) {
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Session expired. Please refresh and try again.';
    } else {
        $questions = principal_load_questions($mysqli, $tid, $bucket);
        if (empty($questions)) {
            $errors[] = 'No questions are currently configured for this evaluation target.';
        } else {
            $answers = [];
            $scoreSum = 0.0;
            $scoreCount = 0;

            foreach ($questions as $q) {
                $field = 'q_' . (int)$q['id'];
                $val = trim((string)($_POST[$field] ?? ''));
                if ($val === '') {
                    $errors[] = 'Please rate every question from 1 to 5 before submitting.';
                    break;
                }
                $score = (float)$val;
                if ($score < 1 || $score > 5) {
                    $errors[] = 'Every rating must be between 1 and 5.';
                    break;
                }
                $answers[] = ['id' => (int)$q['id'], 'source' => $q['source'], 'score' => $score];
                $scoreSum += $score;
                $scoreCount++;
            }

            $overallRemarks = trim((string)($_POST['overall_remarks'] ?? ''));
            if (empty($errors) && $scoreCount === 0) $errors[] = 'There are no answerable questions in this questionnaire.';
            if (empty($errors) && $overallRemarks === '') $errors[] = 'Please write a comment before submitting. Comments / suggestions are required.';

            if (empty($errors)) {
                $overallScore = round($scoreSum / $scoreCount, 2);
                $level = 'basic_education';

                $mysqli->begin_transaction();
                try {
                    $evalType = 'school_head';
                    $ins = $mysqli->prepare("
                        INSERT INTO evaluation_tracker
                            (evaluator_id, target_user_id, eval_bucket, level, form_type,
                             form_id, period_id, score, remarks, eval_type, status, submitted_at)
                        VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, 'submitted', NOW())
                    ");
                    $ins->bind_param(
                        'iisssidss',
                        $evaluator_id, $tid, $bucket, $level, $questionnaireTitle,
                        $period_id_int, $overallScore, $overallRemarks, $evalType
                    );
                    $ins->execute();
                    $tracker_id = $mysqli->insert_id;
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
                            $tracker_id, $questionId, $userQuestionId, $source, $answerText, $score
                        );
                        $ains->execute();
                    }
                    $ains->close();

                    $mysqli->commit();
                    $_SESSION['toast'] = "Evaluation submitted for " . $target['full_name'] . ".";
                    header("Location: principal_evaluations.php");
                    exit;
                } catch (mysqli_sql_exception $e) {
                    $mysqli->rollback();
                    $errors[] = 'Could not save this evaluation. Nothing was recorded — please try again.';
                }
            }
        }
    }
}

$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$tPhoto = !empty($target['photo']) ? '../image/' . $target['photo'] : '../image/pbi_logo';
$mysqli->close();

html_head_open('PBI — Evaluate ' . ($target['full_name'] ?? ''));
?>
<style>
/* Card-based question layout, matching the reference mockup's structure
   (boxed card per question, pill-style rating buttons) but kept in the
   Principal portal's amber accent instead of a literal violet, to stay
   consistent with the rest of this portal (sidebar, buttons, badges).
   Say the word if you actually want the violet swapped in instead. */
.eval-q-card{background:rgba(23,42,69,.85);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:22px 24px;box-shadow:var(--shadow);margin-bottom:16px;}
.eval-q-category{font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--amber-h);margin-bottom:6px;}
.eval-q-text{font-size:15px;color:var(--light);font-weight:600;margin-bottom:16px;line-height:1.5;}
.eval-q-text .req-star{color:var(--danger);}
.eval-rating-row{display:flex;gap:12px;flex-wrap:wrap;}
.eval-rating-opt{flex:1;min-width:70px;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.12);border-radius:10px;padding:14px 10px;cursor:pointer;font-size:14px;font-weight:600;color:var(--light);transition:border-color .15s,background .15s;}
.eval-rating-opt input{position:absolute;opacity:0;pointer-events:none;}
.eval-rating-opt:hover{border-color:rgba(217,154,43,.4);}
.eval-rating-opt:has(input:checked){border-color:var(--amber);border-width:2px;background:rgba(217,154,43,.1);color:#fff;}
.eval-rating-scale-note{font-size:11px;color:var(--muted);margin-top:10px;}
.eval-comment-box{width:100%;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.12);border-radius:10px;padding:14px 16px;color:var(--light);font-size:14px;font-family:'DM Sans',sans-serif;resize:vertical;}
.eval-comment-box:focus{outline:none;border-color:var(--amber);}
.eval-submit-btn{margin-top:6px;}

/* Compact questionnaire table layout */
.eval-table-wrap{background:rgba(23,42,69,.85);border:1px solid rgba(255,255,255,.08);border-radius:14px;overflow:hidden;box-shadow:var(--shadow);margin-bottom:18px;}
.eval-table{width:100%;border-collapse:collapse;table-layout:fixed;}
.eval-table th{background:rgba(255,255,255,.045);color:var(--muted);font-size:11px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;text-align:center;padding:12px 8px;border-bottom:1px solid rgba(255,255,255,.08);}
.eval-table th:first-child{text-align:left;width:auto;padding-left:16px;}
.eval-table th:not(:first-child){width:58px;}
.eval-table td{padding:12px 8px;border-bottom:1px solid rgba(255,255,255,.07);vertical-align:middle;text-align:center;}
.eval-table tr:last-child td{border-bottom:none;}
.eval-table td:first-child{text-align:left;padding-left:16px;padding-right:14px;}
.eval-qno{display:inline-block;color:var(--amber);font-weight:800;margin-right:7px;}
.eval-qtext{font-size:14px;color:var(--light);line-height:1.45;}
.eval-rating-cell{display:flex;justify-content:center;align-items:center;}
.eval-rating-cell input{position:absolute;opacity:0;pointer-events:none;}
.eval-rating-cell label{width:38px;height:34px;display:flex;align-items:center;justify-content:center;border-radius:7px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.025);color:var(--muted);font-size:13px;font-weight:800;cursor:pointer;transition:.15s ease;}
.eval-rating-cell label:hover{border-color:rgba(217,154,43,.45);background:rgba(217,154,43,.08);}
.eval-rating-cell input:checked + label{background:var(--amber);border-color:var(--amber);color:#fff;}
.eval-category-heading{font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--amber-h);margin:24px 0 9px;padding-left:2px;}
.eval-category-heading:first-child{margin-top:0;}
.eval-read-score{font-size:12px;font-weight:800;color:var(--muted);}
@media(max-width:700px){.eval-table th:not(:first-child){width:48px}.eval-rating-cell label{width:32px;height:30px}.eval-table td:first-child{padding-left:12px}.eval-qtext{font-size:13px}}

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

<?php render_principal_sidebar('evaluations', $me, $scopeLabel, $photo_src); ?>

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

<main class="main">
    <a class="back-link" href="principal_evaluations.php"><i class="fa-solid fa-arrow-left"></i> Back to Evaluations</a>

    <div class="section">
        <div class="profile-card">
            <img class="profile-photo-lg" src="<?= htmlspecialchars($tPhoto) ?>" alt="">
            <div>
                <div class="page-title" style="font-size:22px;"><?= htmlspecialchars($target['full_name']) ?></div>
                <div class="page-sub"><?= htmlspecialchars($target['designation'] ?: $bucket) ?></div>
            </div>
            <span class="pill <?= $bucket === 'Faculty' || $bucket === 'EA' ? 'good' : 'warn' ?>" style="margin-left:auto;"><?= htmlspecialchars($bucket) ?></span>
        </div>
    </div>

    <?php if ($existing): ?>
    <div class="section">
        <div class="alert success" style="margin-bottom:0;">
            <i class="fa-solid fa-circle-check"></i> You've already submitted an evaluation for <?= htmlspecialchars($target['full_name']) ?> this period.
        </div>
    </div>
    <?php else: ?>

    <?php foreach ($errors as $err): ?>
    <div class="alert error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($err) ?></div>
    <?php endforeach; ?>

    <?php if (!$period_id_int): ?>
    <div class="alert error"><i class="fa-solid fa-clock"></i> No active evaluation period. You can review the form below, but submission is disabled until an admin opens a period.</div>
    <?php endif; ?>

    <div class="section" style="background:transparent;border:none;box-shadow:none;padding:0 0 8px;">
        <div class="eval-heading-row">
            <h2 style="margin-bottom:18px;"><i class="fa-solid fa-clipboard-list"></i> <?= htmlspecialchars($questionnaireTitle) ?></h2>
            <div class="eval-progress" id="evalProgress" aria-live="polite">0 / 0 questions answered</div>
        </div>
        <div class="eval-missing-alert" id="evalMissingAlert" role="alert" aria-live="assertive">
            <i class="fa-solid fa-circle-exclamation"></i>
            <div><strong>Please complete all required questions.</strong><span id="evalMissingText"></span></div>
        </div>

        <?php if (empty($questions)): ?>
        <p class="empty-note">No questions have been configured for this evaluation target yet.</p>
        <?php else: ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <?php $groupedQuestions=[]; foreach($questions as $q){ $groupedQuestions[$q['category'] ?: 'General'][]=$q; } $qNo=1; ?>
            <?php foreach ($groupedQuestions as $cat => $catQuestions): ?>
            <div class="eval-category-heading"><?= htmlspecialchars($cat) ?></div>
            <div class="eval-table-wrap">
                <table class="eval-table">
                    <thead><tr><th>Question</th><th>5</th><th>4</th><th>3</th><th>2</th><th>1</th></tr></thead>
                    <tbody>
                    <?php foreach($catQuestions as $q): $field='q_'.$q['id']; $posted=$_POST[$field]??''; ?>
                    <?php $currentQNo = $qNo++; ?>
                    <tr class="eval-question-row" data-question-number="<?= $currentQNo ?>" data-required="1">
                        <td><div class="eval-qtext"><span class="eval-qno"><?= $currentQNo ?>.</span><?= htmlspecialchars($q['question']) ?><?= $q['is_required'] ? ' <span class="req-star">*</span>' : '' ?></div></td>
                        <?php for($n=5;$n>=1;$n--): ?>
                        <td><div class="eval-rating-cell"><input type="radio" name="<?= $field ?>" id="<?= $field ?>_<?= $n ?>" value="<?= $n ?>" <?= (string)$posted===(string)$n?'checked':'' ?>><label for="<?= $field ?>_<?= $n ?>"><?= $n ?></label></div></td>
                        <?php endfor; ?>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endforeach; ?>

            <div class="eval-q-card">
                <div class="eval-q-category">Comments / Suggestions <span style="color:#ef4444">*</span></div>
                <textarea name="overall_remarks" rows="3" class="eval-comment-box" required aria-required="true" placeholder="Share your feedback (required)..."><?= htmlspecialchars($_POST['overall_remarks'] ?? '') ?></textarea>
            </div>

            <button class="btn-primary eval-submit-btn" id="principalEvalSubmit" type="submit" <?= !$period_id_int ? 'disabled style="opacity:.5;cursor:not-allowed;"' : '' ?>><i class="fa-solid fa-paper-plane"></i> Submit Evaluation</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>
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
  body{background:#F8FAFC!important;}
}
</style>
<script src="../admin/eval_status_poll.js" defer></script>\n<script id="principal-required-question-feedback-js">\n(function(){\n    const form = document.querySelector('main.main form[method="post"]');\n    if (!form) return;\n\n    const rows = Array.from(form.querySelectorAll('.eval-question-row'));\n    const progress = document.getElementById('evalProgress');\n    const missingAlert = document.getElementById('evalMissingAlert');\n    const missingText = document.getElementById('evalMissingText');\n    const submitBtn = document.getElementById('principalEvalSubmit');\n\n    function unansweredRows(){\n        return rows.filter(row => {\n            const radios = row.querySelectorAll('input[type="radio"]');\n            return !Array.from(radios).some(r => r.checked);\n        });\n    }\n\n    function updateProgress(){\n        const missing = unansweredRows();\n        const answered = rows.length - missing.length;\n        if (progress) {\n            progress.textContent = answered + ' / ' + rows.length + ' questions answered';\n            progress.classList.toggle('complete', rows.length > 0 && missing.length === 0);\n        }\n        rows.forEach(row => row.classList.toggle('unanswered', missing.includes(row)));\n        if (missingAlert && missing.length === 0) missingAlert.classList.remove('show');\n        return missing;\n    }\n\n    rows.forEach(row => {\n        row.querySelectorAll('input[type="radio"]').forEach(input => {\n            input.addEventListener('change', updateProgress);\n        });\n    });\n\n    form.addEventListener('submit', function(e){\n        const missing = updateProgress();\n        if (!missing.length) return;\n\n        e.preventDefault();\n        const nums = missing.map(row => row.getAttribute('data-question-number'));\n        const preview = nums.length <= 6 ? nums.join(', ') : nums.slice(0,6).join(', ') + ' …';\n        if (missingText) {\n            missingText.textContent = ' ' + missing.length + ' question' + (missing.length === 1 ? ' is' : 's are') + ' unanswered. Missing question' + (missing.length === 1 ? '' : 's') + ': ' + preview + '.';\n        }\n        if (missingAlert) missingAlert.classList.add('show');\n        missing[0].scrollIntoView({behavior:'smooth', block:'center'});\n\n        const firstInput = missing[0].querySelector('input[type="radio"]');\n        if (firstInput) firstInput.focus({preventScroll:true});\n    });\n\n    updateProgress();\n})();\n</script>\n
</body>
</html>
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
</style>

<style id="principal-required-question-feedback">
.eval-heading-row{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;}
.eval-heading-row h2{margin-bottom:0!important;}
.eval-progress{font-size:12px;font-weight:800;color:#64748B;background:#F1F5F9;border:1px solid #D9E4EF;border-radius:999px;padding:7px 11px;white-space:nowrap;}
.eval-progress.complete{color:#15803D;background:#ECFDF5;border-color:#BBF7D0;}
.eval-missing-alert{display:none;align-items:flex-start;gap:10px;margin:0 0 16px;padding:12px 14px;border:1px solid #FCA5A5;border-radius:10px;background:#FEF2F2;color:#B91C1C;font-size:13px;line-height:1.45;}
.eval-missing-alert.show{display:flex;}
.eval-missing-alert>i{margin-top:2px;font-size:14px;flex:0 0 auto;}
.eval-missing-alert strong{display:block;color:#991B1B;margin-bottom:2px;}
.eval-missing-alert span{display:block;color:#B91C1C;}
.eval-question-row.unanswered td{background:#FFF7F7!important;}
.eval-question-row.unanswered td:first-child{box-shadow:inset 4px 0 0 #EF4444;}
.eval-question-row.unanswered .eval-qno{color:#DC2626!important;}
.eval-question-row.unanswered .eval-qtext{color:#991B1B!important;}
.eval-question-row.unanswered .eval-rating-cell label{border-color:rgba(239,68,68,.35)!important;}
@media(max-width:700px){.eval-heading-row{align-items:flex-start;}.eval-progress{font-size:11px;}.eval-missing-alert{font-size:12px;}}
</style>

<link rel="stylesheet" href="includes/principal_dark_repairs.css?v=20261011.4" id="principal-dark-repairs"/>