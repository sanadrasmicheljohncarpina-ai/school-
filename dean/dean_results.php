<?php
// session_bootstrap.php — include this BEFORE session_start() everywhere
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
require_once 'db.php';
require_once dirname(__DIR__) . '/shared/system_settings_service.php';

// ── AUTH GUARD ────────────────────────────────────────────
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'dean') {
    header("Location: dean_login.php");
    exit;
}

// ── dean_results.php (Phase 2, new page) ────────────────────────────
// The Dean's OWN evaluation results — feedback received from the
// evaluator groups currently authorized to evaluate the Dean (including
// the Executive Assistant's EA evaluation flow). This is a read-only
// "how am I doing" view, not an evaluation tool.
//
// ── CONFIDENTIALITY — NON-NEGOTIABLE ────────────────────────────────
// The Dean must NEVER be able to identify which evaluator (student or EA) submitted which
// response. Dates are shown WITHOUT time of day, and per-response detail is hidden
// for any term with fewer than MIN_RESPONSES_FOR_DETAIL responses. Every query below intentionally does NOT join to the
// evaluator's identity (name/id/photo/department/account), and nothing
// derived from evaluator identity (timestamp-vs-other-students ordering,
// IP, etc.) is exposed. Responses are labeled only "Evaluation #1",
// "Evaluation #2", ... in submission order, or "Anonymous Response".

function safe_rows(mysqli $mysqli, string $sql, string $types = '', array $params = []): array {
    try {
        $stmt = @$mysqli->prepare($sql);
        if (!$stmt) return [];
        if ($types !== '') { $stmt->bind_param($types, ...$params); }
        if (!@$stmt->execute()) { $stmt->close(); return []; }
        $res = $stmt->get_result();
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $rows;
    } catch (mysqli_sql_exception $e) {
        return [];
    }
}

$deanId = (int)$_SESSION['user_id'];

// ── DEAN PROFILE (for sidebar) ─────────────────────────────
$stmt = $mysqli->prepare("SELECT full_name, designation, photo FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $deanId);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc();
$stmt->close();
$photo_src = !empty($me['photo']) ? UPLOAD_URL . $me['photo'] : UPLOAD_URL . 'pbi_logo';

// ── GLOBAL SYSTEM SETTINGS ──────────────────────────────────────────
$settings = get_system_settings($mysqli);
$period_id_int = $settings['period_id'] ?? 0;
$hasPeriod     = $period_id_int > 0;

const HIGHER_ED_LABEL = 'Higher Education';

// NOTE ON eval_type/evaluation_context (confirmed against student_dashboard.php
// and admin/questionnaire.php, not assumed):
// Students evaluate the active Dean via the normal Student Evaluation flow —
// eval_type='student', evaluation_context='school_head'. The Executive
// Assistant evaluation flow writes eval_type='ea'. Both tracker shapes use
// target_user_id to point at the Dean's own account, so this page accepts
// either authorized source without exposing evaluator identity. The question set is
// per-person (Principal/Dean are "per_user_targets" in questionnaire.php), so
// answers join through user_questions via questionnaire_answers.user_question_id,
// with question_source='user' — the same source admin_analytics.php's sheet
// view already reads for Staff/Principal/Dean questions.
const SCHOOL_HEAD_EVAL_TYPE = 'student';
const SCHOOL_HEAD_CONTEXT   = 'school_head';

$overallAvg = null;
$responseCount = 0;
$history = [];

// ── EVALUATION TERMS (dropdown) ─────────────────────────────────────
// Feedback is separated per evaluation period/term. A term is listed when it is the active
// period, still has live feedback for this Dean, or has feedback kept by System Archive
// (feedback_received_keep — an identity-free copy saved when the EA archives a period).
const EA_EVAL_TYPE_ELIGIBLE = "((et.eval_type=? AND et.evaluation_context=?) OR et.eval_type='ea') AND et.status IN ('submitted','approved') AND et.target_user_id=?";

// Anonymity threshold: with very few responses in a term (e.g. only the EA's),
// individual comments / scores / details could point at one evaluator, so they stay hidden.
const MIN_RESPONSES_FOR_DETAIL = 3;
function dean_term_response_count(mysqli $mysqli, int $deanId, int $periodId): int {
    $live = safe_rows($mysqli, "SELECT COUNT(*) AS c FROM evaluation_tracker et WHERE " . EA_EVAL_TYPE_ELIGIBLE . " AND et.period_id=?",
        'ssii', [SCHOOL_HEAD_EVAL_TYPE, SCHOOL_HEAD_CONTEXT, $deanId, $periodId]);
    $kept = safe_rows($mysqli, "SELECT COUNT(*) AS c FROM feedback_received_keep WHERE target_user_id=? AND period_id=?", 'ii', [$deanId, $periodId]);
    return (int)($live[0]['c'] ?? 0) + (int)($kept[0]['c'] ?? 0);
}

// ── DETAILS ENDPOINT (used by the "View Details" modal) ─────────────
// Served by this page itself so it always uses the same eligibility rules
// as the list above. Returns NO evaluator identity.
//   ?details=<id>             -> live evaluation_tracker row
//   ?details=<id>&src=kept    -> identity-free copy kept by System Archive
if (isset($_GET['details'])) {
    header('Content-Type: application/json; charset=utf-8');
    $detailId = (int)$_GET['details'];
    $isKept   = (($_GET['src'] ?? '') === 'kept');
    $notFound = static function () {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'This evaluation could not be found.']);
        exit;
    };

    $answers = []; $comment = ''; $submittedAt = null; $periodLabel = ''; $gatePeriod = 0;

    if ($isKept) {
        $rows = safe_rows($mysqli, "SELECT period_id, remarks, submitted_at, school_year, semester, period_label, answers_json
            FROM feedback_received_keep WHERE id=? AND target_user_id=? LIMIT 1", 'ii', [$detailId, $deanId]);
        if (!$rows) $notFound();
        $kept = $rows[0];
        $gatePeriod = (int)($kept['period_id'] ?? 0);
        foreach ((json_decode((string)$kept['answers_json'], true) ?: []) as $a) {
            $answers[] = [
                'category'      => !empty($a['category']) ? (string)$a['category'] : 'General',
                'question_text' => (string)($a['question_text'] ?? 'Question'),
                'score'         => isset($a['score']) ? (float)$a['score'] : null,
            ];
        }
        $comment     = (string)($kept['remarks'] ?? '');
        $submittedAt = $kept['submitted_at'];
        $periodLabel = (!empty($kept['school_year']) && !empty($kept['semester']))
            ? $kept['school_year'] . ' · ' . $kept['semester']
            : (string)($kept['period_label'] ?? '');
    } else {
        $rows = safe_rows($mysqli, "
            SELECT et.id, et.period_id, et.remarks AS comment, et.submitted_at, ep.period_label, ep.semester, ep.school_year
            FROM evaluation_tracker et
            LEFT JOIN evaluation_periods ep ON ep.id = et.period_id
            WHERE " . EA_EVAL_TYPE_ELIGIBLE . " AND et.id=?
            LIMIT 1", 'ssii', [SCHOOL_HEAD_EVAL_TYPE, SCHOOL_HEAD_CONTEXT, $deanId, $detailId]);
        if (!$rows) $notFound();
        $t = $rows[0];
        $gatePeriod = (int)($t['period_id'] ?? 0);
        foreach (safe_rows($mysqli, "
            SELECT qa.answer_score,
                   COALESCE(uq.category, eq.category, 'General') AS category,
                   COALESCE(uq.question_text, eq.question_text,
                            CONCAT('Question #', COALESCE(qa.user_question_id, qa.question_id, qa.id))) AS question_text
            FROM questionnaire_answers qa
            LEFT JOIN user_questions uq ON qa.question_source='user' AND uq.id=COALESCE(qa.user_question_id, qa.question_id)
            LEFT JOIN evaluation_questions eq ON qa.question_source='evaluation' AND eq.id=qa.question_id
            WHERE qa.tracker_id=?
            ORDER BY category, qa.id", 'i', [$detailId]) as $a) {
            $answers[] = [
                'category'      => trim((string)$a['category']) !== '' ? trim((string)$a['category']) : 'General',
                'question_text' => (string)$a['question_text'],
                'score'         => $a['answer_score'] !== null ? (float)$a['answer_score'] : null,
            ];
        }
        $comment     = (string)($t['comment'] ?? '');
        $submittedAt = $t['submitted_at'];
        $periodLabel = (!empty($t['school_year']) && !empty($t['semester']))
            ? $t['school_year'] . ' · ' . $t['semester']
            : ((string)($t['period_label'] ?? '') ?: (string)($t['semester'] ?? ''));
    }

    if (dean_term_response_count($mysqli, $deanId, $gatePeriod) < MIN_RESPONSES_FOR_DETAIL) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Individual responses stay hidden until this term has at least ' . MIN_RESPONSES_FOR_DETAIL . ' responses, to protect evaluator anonymity.']);
        exit;
    }

    $cats = []; $sumAll = 0.0; $cntAll = 0; $questions = [];
    foreach ($answers as $a) {
        if ($a['score'] === null) continue;            // only scored answers are shown
        $c = $a['category'];
        if (!isset($cats[$c])) $cats[$c] = [0.0, 0];
        $cats[$c][0] += $a['score']; $cats[$c][1]++;
        $sumAll += $a['score']; $cntAll++;
        $questions[] = ['question_text' => $a['question_text'], 'score' => $a['score']];
    }
    $catRows = [];
    foreach ($cats as $name => $v) {
        $catRows[] = ['category' => (string)$name, 'avg' => round($v[0] / $v[1], 2)];
    }

    $comment = trim($comment);
    if ($comment !== '' && preg_match('/^(n\/?a|none|nil|no comments?|no feedback|not applicable|[\s.\-–—]+)$/iu', $comment)) {
        $comment = '';
    }

    echo json_encode([
        'ok'            => true,
        'period_label'  => $periodLabel,
        'submitted_at'  => $submittedAt ? date('F j, Y', strtotime($submittedAt)) : '—',
        'overall_score' => $cntAll ? round($sumAll / $cntAll, 2) : null,
        'categories'    => $catRows,
        'questions'     => $questions,
        'comment'       => $comment,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$termIds = [];
if ($hasPeriod) { $termIds[(int)$period_id_int] = true; }

$liveTermRows = safe_rows($mysqli, "SELECT DISTINCT et.period_id AS pid FROM evaluation_tracker et WHERE " . EA_EVAL_TYPE_ELIGIBLE,
    "ssi", [SCHOOL_HEAD_EVAL_TYPE, SCHOOL_HEAD_CONTEXT, $deanId]);
foreach ($liveTermRows as $r) { if ((int)$r['pid'] > 0) $termIds[(int)$r['pid']] = true; }

$keptTermRows = safe_rows($mysqli, "SELECT period_id AS pid, MAX(school_year) AS school_year, MAX(semester) AS semester, MAX(period_label) AS period_label
    FROM feedback_received_keep WHERE target_user_id=? GROUP BY period_id", "i", [$deanId]);
$keptTerms = [];
foreach ($keptTermRows as $r) {
    $keptTerms[(int)$r['pid']] = $r;
    if ((int)$r['pid'] > 0) $termIds[(int)$r['pid']] = true;
}

$terms = [];
if ($termIds) {
    $in = implode(',', array_map('intval', array_keys($termIds)));
    foreach (safe_rows($mysqli, "SELECT * FROM evaluation_periods WHERE id IN ($in)") as $ep) {
        $terms[(int)$ep['id']] = [
            'id'          => (int)$ep['id'],
            'school_year' => $ep['school_year'] ?? '',
            'period_label'=> $ep['period_label'] ?? ($ep['semester'] ?? ''),
            'date_start'  => $ep['date_start'] ?? '',
        ];
    }
    // A period row that no longer exists can still have kept feedback — fall back to its snapshot labels.
    foreach (array_keys($termIds) as $pid) {
        if (!isset($terms[$pid])) {
            $k = $keptTerms[$pid] ?? [];
            $terms[$pid] = ['id' => $pid, 'school_year' => $k['school_year'] ?? '', 'period_label' => $k['period_label'] ?? ($k['semester'] ?? ''), 'date_start' => ''];
        }
    }
    foreach ($terms as $pid => &$t) {
        $t['current']  = ($hasPeriod && $pid === (int)$period_id_int);
        $t['archived'] = isset($keptTerms[$pid]);
        // period_label may already contain the school year — don't prepend it twice.
        $t['label']    = ($t['school_year'] !== '' && $t['period_label'] !== '')
            ? (stripos($t['period_label'], $t['school_year']) !== false ? $t['period_label'] : $t['school_year'] . ' — ' . $t['period_label'])
            : ($t['period_label'] !== '' ? $t['period_label'] : ($t['school_year'] !== '' ? $t['school_year'] : 'Period #' . $pid));
    }
    unset($t);
    // Current term first, then newest to oldest.
    uasort($terms, function ($a, $b) {
        if ($a['current'] !== $b['current']) return $a['current'] ? -1 : 1;
        $c = strcmp((string)$b['date_start'], (string)$a['date_start']);
        return $c ?: ($b['id'] <=> $a['id']);
    });
}

// Same behaviour as the EA's Evaluation Received page: open on "All Evaluation Terms"
// unless a specific term is requested; an unknown term falls back to all terms.
$requestedPeriod    = $_GET['period'] ?? 'all';
$allPeriodsSelected = !is_string($requestedPeriod) || strtolower(trim($requestedPeriod)) === 'all' || $requestedPeriod === '';
$selPeriod          = $allPeriodsSelected ? 0 : (int)$requestedPeriod;
if (!$allPeriodsSelected && !isset($terms[$selPeriod])) { $allPeriodsSelected = true; $selPeriod = 0; }
$showData = true;

$items = [];

if ($showData) {
    // ── LIVE RESPONSES (selected term or all terms) ─────────────────
    // Deliberately selects ONLY tracker id (for per-tracker avg + ordering),
    // comment, score totals, period labels, and submission order. No evaluator_id,
    // name, or any evaluator-identifying column is selected — do not add one.
    $livePeriodFilter = $allPeriodsSelected ? '' : ' AND et.period_id=?';
    $liveTypes = $allPeriodsSelected ? 'ssi' : 'ssii';
    $liveParams = $allPeriodsSelected
        ? [SCHOOL_HEAD_EVAL_TYPE, SCHOOL_HEAD_CONTEXT, $deanId]
        : [SCHOOL_HEAD_EVAL_TYPE, SCHOOL_HEAD_CONTEXT, $deanId, $selPeriod];
    $rawHistory = safe_rows($mysqli, "
        SELECT et.id, et.period_id, et.remarks AS comment, et.submitted_at, ep.period_label, ep.semester, ep.school_year,
               (SELECT SUM(qa2.answer_score)   FROM questionnaire_answers qa2 WHERE qa2.tracker_id = et.id) AS score_sum,
               (SELECT COUNT(qa2.answer_score) FROM questionnaire_answers qa2 WHERE qa2.tracker_id = et.id) AS score_count
        FROM evaluation_tracker et
        LEFT JOIN evaluation_periods ep ON ep.id = et.period_id
        WHERE " . EA_EVAL_TYPE_ELIGIBLE . $livePeriodFilter . "
    ", $liveTypes, $liveParams);

    foreach ($rawHistory as $r) {
        $items[] = [
            'source'       => 'live',
            'period_id'    => (int)($r['period_id'] ?? 0),
            'key'          => (int)$r['id'],
            'comment'      => $r['comment'] ?: '',
            'submitted_at' => $r['submitted_at'],
            'score_sum'    => (float)($r['score_sum'] ?? 0),
            'score_count'  => (int)($r['score_count'] ?? 0),
            'school_year'  => $r['school_year'] ?? '',
            'semester'     => $r['semester'] ?? '',
            'period_label' => $r['period_label'] ?? '',
        ];
    }

    // ── KEPT BY SYSTEM ARCHIVE (selected term or all terms) ─────────
    $keptPeriodFilter = $allPeriodsSelected ? '' : ' AND period_id=?';
    $keptTypes = $allPeriodsSelected ? 'i' : 'ii';
    $keptParams = $allPeriodsSelected ? [$deanId] : [$deanId, $selPeriod];
    $keptRows = safe_rows($mysqli, "
        SELECT id, period_id, remarks, submitted_at, school_year, semester, period_label, score_sum, score_count
        FROM feedback_received_keep
        WHERE target_user_id=?" . $keptPeriodFilter . "
    ", $keptTypes, $keptParams);
    foreach ($keptRows as $r) {
        $items[] = [
            'source'       => 'kept',
            'period_id'    => (int)($r['period_id'] ?? 0),
            'key'          => (int)$r['id'],          // feedback_received_keep.id (not the old tracker id)
            'comment'      => $r['remarks'] ?: '',
            'submitted_at' => $r['submitted_at'],
            'score_sum'    => (float)$r['score_sum'],
            'score_count'  => (int)$r['score_count'],
            'school_year'  => $r['school_year'] ?? '',
            'semester'     => $r['semester'] ?? '',
            'period_label' => $r['period_label'] ?? '',
        ];
    }
}

// Submission order only — never evaluator identity.
usort($items, function ($a, $b) {
    $ta = $a['submitted_at'] ? strtotime($a['submitted_at']) : 0;
    $tb = $b['submitted_at'] ? strtotime($b['submitted_at']) : 0;
    return $ta <=> $tb ?: ($a['key'] <=> $b['key']);
});

$termCounts = [];
foreach ($items as $it) { $p = (int)($it['period_id'] ?? 0); $termCounts[$p] = ($termCounts[$p] ?? 0) + 1; }

$totalSum = 0.0; $totalCount = 0; $n = 1;
foreach ($items as $it) {
    $totalSum   += $it['score_sum'];
    $totalCount += $it['score_count'];
    $cleanComment = trim((string)$it['comment']);
    // Treat placeholders such as "N/A", "none", "-" as no written comment.
    $commentOk = $cleanComment !== '' && !preg_match('/^(n\/?a|none|nil|no comments?|no feedback|not applicable|[\s.\-–—]+)$/iu', $cleanComment);
    $hidden = ($termCounts[(int)($it['period_id'] ?? 0)] ?? 0) < MIN_RESPONSES_FOR_DETAIL;
    $history[] = [
        '_tracker_id'        => $it['key'],
        'ts'                 => $it['submitted_at'] ? (int)strtotime(date('Y-m-d', strtotime($it['submitted_at']))) : 0,   // day only, never time of day
        'idx'                => $n,
        'hidden'             => $hidden,
        'comment_ok'         => ($commentOk && !$hidden),
        '_source'            => $it['source'],
        'label'              => 'Evaluation #' . $n,
        'score'              => $it['score_count'] > 0 ? round($it['score_sum'] / $it['score_count'], 2) : null,
        'comment'            => ($commentOk && !$hidden) ? $cleanComment : '',
        'submitted_at_label' => $it['submitted_at'] ? date('M d, Y', strtotime($it['submitted_at'])) : 'Unknown date',
        'period_label'       => (!empty($it['school_year']) && !empty($it['semester'])) ? ($it['school_year'].' · '.$it['semester']) : ($it['period_label'] ?: $it['semester']),
    ];
    $n++;
}
$responseCount = count($history);
$overallAvg    = $totalCount > 0 ? round($totalSum / $totalCount, 2) : null;

// ── SUMMARY STATS FOR THE OVERVIEW CARDS ────────────────────────────
// All aggregates only — nothing here touches evaluator identity.
$scoredCount = 0; $commentCount = 0;
foreach ($history as $h) {
    if ($h['score'] !== null) $scoredCount++;
    if ($h['comment_ok']) $commentCount++;
}

// Category averages across every response shown.
$catAgg = []; $liveIds = []; $keptIds = [];
foreach ($history as $h) {
    if ($h['_source'] === 'kept') { $keptIds[] = (int)$h['_tracker_id']; } else { $liveIds[] = (int)$h['_tracker_id']; }
}
if ($liveIds) {
    $inLive = implode(',', array_map('intval', $liveIds));
    $catRows = safe_rows($mysqli, "
        SELECT COALESCE(uq.category, eq.category, 'General') AS category, SUM(qa.answer_score) AS s, COUNT(qa.answer_score) AS c
        FROM questionnaire_answers qa
        LEFT JOIN user_questions uq ON qa.question_source='user' AND uq.id=qa.user_question_id
        LEFT JOIN evaluation_questions eq ON qa.question_source='evaluation' AND eq.id=qa.question_id
        WHERE qa.tracker_id IN ($inLive)
        GROUP BY COALESCE(uq.category, eq.category, 'General')
    ");
    foreach ($catRows as $r) {
        $cat = trim((string)$r['category']) !== '' ? trim((string)$r['category']) : 'General';
        if (!isset($catAgg[$cat])) $catAgg[$cat] = [0.0, 0];
        $catAgg[$cat][0] += (float)$r['s'];
        $catAgg[$cat][1] += (int)$r['c'];
    }
}
if ($keptIds) {
    $inKept = implode(',', array_map('intval', $keptIds));
    $keptAns = safe_rows($mysqli, "SELECT answers_json FROM feedback_received_keep WHERE target_user_id=? AND id IN ($inKept)", 'i', [$deanId]);
    foreach ($keptAns as $r) {
        $list = json_decode((string)$r['answers_json'], true);
        if (!is_array($list)) continue;
        foreach ($list as $row) {
            if (!isset($row['score'])) continue;
            $cat = !empty($row['category']) ? (string)$row['category'] : 'General';
            if (!isset($catAgg[$cat])) $catAgg[$cat] = [0.0, 0];
            $catAgg[$cat][0] += (float)$row['score'];
            $catAgg[$cat][1] += 1;
        }
    }
}
$catStats = [];
foreach ($catAgg as $name => $v) {
    if ($v[1] > 0) $catStats[] = ['name' => (string)$name, 'avg' => round($v[0] / $v[1], 2)];
}
usort($catStats, function ($a, $b) { return $b['avg'] <=> $a['avg']; });
$topCat = $catStats[0] ?? null;
$lowCat = count($catStats) > 1 ? $catStats[count($catStats) - 1] : null;

// Change versus the previous term (only when a single term is selected).
$trendText = ''; $trendClass = ''; $prevTermLabel = '';
if (!$allPeriodsSelected && $selPeriod > 0 && $overallAvg !== null) {
    $termKeys = array_keys($terms);
    $pos = array_search($selPeriod, $termKeys, true);
    if ($pos !== false && isset($termKeys[$pos + 1])) {
        $prevId = (int)$termKeys[$pos + 1];
        $pSum = 0.0; $pCnt = 0;
        $pl = safe_rows($mysqli, "SELECT SUM(qa.answer_score) AS s, COUNT(qa.answer_score) AS c
            FROM questionnaire_answers qa INNER JOIN evaluation_tracker et ON et.id = qa.tracker_id
            WHERE " . EA_EVAL_TYPE_ELIGIBLE . " AND et.period_id=?", 'ssii', [SCHOOL_HEAD_EVAL_TYPE, SCHOOL_HEAD_CONTEXT, $deanId, $prevId]);
        if ($pl) { $pSum += (float)$pl[0]['s']; $pCnt += (int)$pl[0]['c']; }
        $pk = safe_rows($mysqli, "SELECT SUM(score_sum) AS s, SUM(score_count) AS c FROM feedback_received_keep WHERE target_user_id=? AND period_id=?", 'ii', [$deanId, $prevId]);
        if ($pk) { $pSum += (float)$pk[0]['s']; $pCnt += (int)$pk[0]['c']; }
        if ($pCnt > 0) {
            $d = round($overallAvg - ($pSum / $pCnt), 2);
            $prevTermLabel = $terms[$prevId]['label'];
            if ($d > 0)      { $trendClass = 'up';   $trendText = '▲ +' . number_format($d, 2); }
            elseif ($d < 0)  { $trendClass = 'down'; $trendText = '▼ -' . number_format(abs($d), 2); }
            else             { $trendClass = 'flat'; $trendText = 'No change'; }
        }
    }
}

function er_score_class($score) {
    if ($score === null) return 'none';
    if ($score >= 4) return 'high';
    if ($score >= 3) return 'mid';
    return 'low';
}
$displayList = array_reverse($history);   // newest first by default
$starsOn = $overallAvg !== null ? (int)round($overallAvg) : 0;

$reportMode = false;
// ── GENERATE REPORT ─────────────────────────────────────────────────
// Same cumulative, fully anonymous report as the Executive Assistant's Evaluation
// Received page. This only prepares the data; the report itself is drawn inside the
// normal Dean layout (sidebar + header) in place of the list, further down.
if (($_GET['report'] ?? '') === '1') {
    $reportMode = true;
    $escape = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $repLive = []; $repKept = []; $repDates = []; $repComments = []; $repEvals = []; $repExcluded = 0;
    foreach ($history as $h) {
        if ($h['score'] === null) continue;
        if (!empty($h['hidden'])) { $repExcluded++; continue; }   // too few responses in that term
        $repEvals[] = ['ts' => (int)$h['ts'], 'score' => (float)$h['score']];
        if ($h['_source'] === 'kept') { $repKept[] = (int)$h['_tracker_id']; } else { $repLive[] = (int)$h['_tracker_id']; }
        if ($h['ts']) $repDates[] = (int)$h['ts'];
        if ($h['comment_ok']) $repComments[] = $h['comment'];
    }
    $repCount = count($repLive) + count($repKept);

    // Per-question aggregation, merging identical wording (old + new question ids).
    $qAgg = []; $qOrd = 0;
    $qAdd = function ($cat, $text, $sum, $cnt) use (&$qAgg, &$qOrd) {
        $cat  = trim((string)$cat) !== '' ? trim((string)$cat) : 'General';
        $text = trim((string)$text) !== '' ? trim((string)$text) : 'Question';
        $key  = mb_strtolower($text);
        if (!isset($qAgg[$key])) { $qAgg[$key] = ['cat' => $cat, 'text' => $text, 'sum' => 0.0, 'cnt' => 0, 'ord' => $qOrd++]; }
        $qAgg[$key]['sum'] += (float)$sum;
        $qAgg[$key]['cnt'] += (int)$cnt;
    };
    if ($repLive) {
        $inRep = implode(',', array_map('intval', $repLive));
        foreach (safe_rows($mysqli, "
            SELECT COALESCE(eq.category, uq.category, 'General') AS category,
                   COALESCE(eq.question_text, uq.question_text,
                            CONCAT('Question #', COALESCE(qa.question_id, qa.user_question_id, qa.id))) AS question_text,
                   MIN(COALESCE(qa.question_id, qa.user_question_id, qa.id)) AS q_id,
                   SUM(qa.answer_score) AS s, COUNT(qa.answer_score) AS c
            FROM questionnaire_answers qa
            LEFT JOIN evaluation_questions eq ON qa.question_source='evaluation' AND eq.id=qa.question_id
            LEFT JOIN user_questions uq ON qa.question_source='user' AND uq.id=COALESCE(qa.user_question_id, qa.question_id)
            WHERE qa.tracker_id IN ($inRep) AND qa.answer_score IS NOT NULL
            GROUP BY 1, 2
            ORDER BY 1, q_id") as $r) {
            $qAdd($r['category'], $r['question_text'], $r['s'], $r['c']);
        }
    }
    if ($repKept) {
        $inRepK = implode(',', array_map('intval', $repKept));
        foreach (safe_rows($mysqli, "SELECT answers_json FROM feedback_received_keep WHERE target_user_id=? AND id IN ($inRepK)", 'i', [$deanId]) as $r) {
            $list = json_decode((string)$r['answers_json'], true);
            if (!is_array($list)) continue;
            foreach ($list as $a) {
                if (!isset($a['score'])) continue;
                $qAdd($a['category'] ?? 'General', $a['question_text'] ?? 'Question', (float)$a['score'], 1);
            }
        }
    }

    // Group by category (first-seen order), questions in their original order.
    $repByCat = []; $repSum = 0.0; $repN = 0;
    uasort($qAgg, fn($a, $b) => $a['ord'] <=> $b['ord']);
    foreach ($qAgg as $q) {
        if ($q['cnt'] < 1) continue;
        $repByCat[$q['cat']][] = ['text' => $q['text'], 'avg' => round($q['sum'] / $q['cnt'], 2), 'n' => $q['cnt']];
        $repSum += $q['sum']; $repN += $q['cnt'];
    }
    // Overall = flat average of every individual answer (same method as the page's Overall Average).
    $repOverall = $repN > 0 ? round($repSum / $repN, 2) : null;

    $repTermLabel = (!$allPeriodsSelected && isset($terms[$selPeriod])) ? $terms[$selPeriod]['label'] : 'All Evaluation Terms';
    if ($repDates) {
        $dFrom = min($repDates); $dTo = max($repDates);
        $repDateText = date('Y-m-d', $dFrom) === date('Y-m-d', $dTo) ? date('F d, Y', $dTo) : date('M d, Y', $dFrom) . ' – ' . date('M d, Y', $dTo);
    } else { $repDateText = '—'; }

    // Date Evaluated: the evaluation's open and close times from System Settings
    // (same source as the Evaluation Reports page), one per line.
    $repSched = ['eval_start' => '', 'eval_end' => ''];
    if ($repSchedRes = $mysqli->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('eval_start','eval_end')")) {
        while ($repSchedRow = $repSchedRes->fetch_assoc()) $repSched[$repSchedRow['setting_key']] = trim((string)$repSchedRow['setting_value']);
    }
    $repFmtSched = static function (string $v): string {
        if ($v === '') return '';
        try { return (new DateTime($v, new DateTimeZone('Asia/Manila')))->format('M j, Y, g:i A'); }
        catch (Exception $e) { return ''; }
    };
    $repDateLabel = 'Date Evaluated';
    $repOpen  = $repFmtSched($repSched['eval_start']);
    $repClose = $repFmtSched($repSched['eval_end']);
    // The System Settings schedule belongs to the ACTIVE term only (with times). Other terms use the
    // dates saved on their evaluation_periods row; if none, the actual submission times.
    $repIsCurrentTerm = !$allPeriodsSelected && !empty($terms[$selPeriod]['current']);
    if ($repIsCurrentTerm && $repOpen !== '' && $repClose !== '') {
        $repDateText = 'Open: ' . $repOpen . "\n" . 'Close: ' . $repClose;
    } elseif (!$allPeriodsSelected && ($repPeriodRow = safe_rows($mysqli, "SELECT * FROM evaluation_periods WHERE id=? LIMIT 1", 'i', [$selPeriod])[0] ?? null)
              && ($repPStart = trim((string)($repPeriodRow['eval_start_at'] ?? $repPeriodRow['date_start'] ?? $repPeriodRow['start_date'] ?? ''))) !== ''
              && ($repPEnd   = trim((string)($repPeriodRow['eval_end_at']   ?? $repPeriodRow['date_end']   ?? $repPeriodRow['end_date']   ?? ''))) !== '') {
        // Past term: its own saved open/close. eval_start_at/eval_end_at are DATETIMEs (shown with time);
        // older rows that only have dates are shown as dates.
        $repFmtP = static function (string $v): string {
            try {
                $hasTime = (bool)preg_match('/\d{1,2}:\d{2}/', $v);
                return (new DateTime($v, new DateTimeZone('Asia/Manila')))->format($hasTime ? 'M j, Y, g:i A' : 'M j, Y');
            } catch (Exception $e) { return ''; }
        };
        $repDateText = 'Open: ' . $repFmtP($repPStart) . "\n" . 'Close: ' . $repFmtP($repPEnd);
    } elseif ($repDates) {
        $repFmtTs = static fn(int $ts): string => (new DateTime('@' . $ts))->setTimezone(new DateTimeZone('Asia/Manila'))->format('M j, Y');
        $repDateText = (min($repDates) === max($repDates))
            ? $repFmtTs(min($repDates))
            : 'Earliest: ' . $repFmtTs(min($repDates)) . "\n" . 'Latest: ' . $repFmtTs(max($repDates));
    }

    $scoreColor = static function ($s) {
        if ($s === null) return '#6b7280';
        if ($s >= 4.5) return '#32B98A';
        if ($s >= 3.5) return '#6BCFA9';
        if ($s >= 2.5) return '#facc15';
        if ($s >= 1.5) return '#fb923c';
        return '#E6788A';
    };
    $satLabel = static function ($s) {
        if ($s === null) return '—';
        if ($s >= 4.5) return 'Very Satisfied';
        if ($s >= 3.5) return 'Satisfied';
        if ($s >= 2.5) return 'Neither Satisfied nor Dissatisfied';
        if ($s >= 1.5) return 'Dissatisfied';
        return 'Very Dissatisfied';
    };
    $backHref = 'dean_results.php' . ($allPeriodsSelected ? '' : '?period=' . (int)$selPeriod);
}

?>
<!DOCTYPE html>
<html lang="en" class="dean-internal-scroll-page">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>PBI — Evaluation Received</title>
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

.main{flex:1;padding:36px 44px;}
.page-header{margin-bottom:26px;}
.page-title{font-family:'Rajdhani',sans-serif;font-size:30px;font-weight:700;color:#fff;letter-spacing:1px;}
.page-sub{font-size:13px;color:var(--muted);margin-top:4px;}
.page-header{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap;}
.term-picker{display:flex;flex-direction:column;gap:5px;min-width:260px;}
.term-picker label{font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);display:flex;align-items:center;gap:6px;}
.term-picker select{appearance:none;-webkit-appearance:none;width:100%;padding:10px 38px 10px 14px;border-radius:10px;border:1px solid #B9CDE5;background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' fill='none' stroke='%2367819E' stroke-width='2' stroke-linecap='round'/%3E%3C/svg%3E") no-repeat right 14px center;color:#102746;font:600 13px 'DM Sans',sans-serif;cursor:pointer;}
.term-picker select:hover{border-color:var(--violet);}
.term-picker select:focus{outline:3px solid rgba(124,95,217,.28);outline-offset:1px;border-color:var(--violet);}

.confidentiality-note{display:flex;align-items:flex-start;gap:14px;padding:16px 20px;background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.3);border-radius:12px;margin-bottom:26px;}
.confidentiality-note i{color:var(--good);font-size:18px;margin-top:2px;}
.confidentiality-note p{font-size:12.5px;color:var(--light);line-height:1.6;}

.card-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:26px;}
.stat-card{background:rgba(23,42,69,.85);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:20px;box-shadow:var(--shadow);}
.stat-card i{color:var(--violet-h);font-size:20px;margin-bottom:10px;}
.stat-card .num{font-size:28px;font-weight:700;color:#fff;}
.stat-card .label{font-size:12px;color:var(--muted);margin-top:4px;}

.section{background:rgba(23,42,69,.85);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:24px;box-shadow:var(--shadow);margin-bottom:26px;}
.section h2{font-family:'Rajdhani',sans-serif;font-size:19px;color:#fff;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.section h2 i{color:var(--violet-h);font-size:16px;}

.cat-row{display:flex;align-items:center;gap:14px;padding:10px 0;border-bottom:1px solid rgba(255,255,255,.05);}
.cat-row:last-child{border-bottom:none;}
.cat-name{width:180px;font-size:13px;color:var(--light);flex-shrink:0;}
.bar-wrap{flex:1;background:rgba(255,255,255,.08);border-radius:6px;height:8px;overflow:hidden;}
.bar-fill{height:100%;background:linear-gradient(90deg,var(--violet-dark),var(--violet-h));border-radius:6px;}
.cat-score{width:48px;text-align:right;font-size:13px;font-weight:700;color:#fff;flex-shrink:0;}

.trend-row{display:flex;justify-content:space-between;padding:9px 0;border-bottom:1px solid rgba(255,255,255,.05);font-size:13px;}
.trend-row:last-child{border-bottom:none;}
.trend-row .val{color:var(--violet-h);font-weight:700;}


.empty-note{color:var(--muted);font-size:13px;font-style:italic;}


.details-btn{background:rgba(13,148,136,.12);border:1px solid rgba(13,148,136,.28);color:#5eead4;font-size:11px;font-weight:700;padding:6px 12px;border-radius:18px;cursor:pointer}.eval-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.78);z-index:500;display:none;align-items:center;justify-content:center;padding:20px}.eval-modal-overlay.open{display:flex}.eval-modal{background:var(--mid);border:1px solid rgba(255,255,255,.08);border-radius:16px;width:100%;max-width:720px;max-height:88vh;display:flex;flex-direction:column;box-shadow:0 24px 80px rgba(0,0,0,.6)}.eval-modal-header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid rgba(255,255,255,.08)}.eval-modal-title{font-family:'Rajdhani',sans-serif;font-size:21px;font-weight:700;color:#fff}.eval-modal-title i{color:#9C85F0;margin-right:8px}.eval-modal-close{background:none;border:none;color:var(--muted);font-size:19px;cursor:pointer}.eval-modal-body{padding:22px;overflow:auto}.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:20px}.info-grid>div{background:var(--inner);border:1px solid rgba(255,255,255,.05);border-radius:10px;padding:12px 14px}.info-label{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.7px;margin-bottom:5px}.info-value{font-size:13px;color:#fff;font-weight:600}.info-value i{color:var(--muted);margin-right:4px}.score-big{color:#5eead4}.modal-section-title{font-size:14px;color:#fff;margin:18px 0 10px}.cat-row-modal{display:flex;align-items:center;gap:10px;margin:9px 0}.cat-name-modal{width:170px;font-size:12px;color:var(--light);flex-shrink:0}.cat-bar{flex:1;height:7px;background:rgba(255,255,255,.08);border-radius:6px;overflow:hidden}.cat-bar>div{height:100%;background:linear-gradient(90deg,#5f45b8,#9c85f0);border-radius:6px}.cat-score-modal{width:42px;text-align:right;font-size:12px;font-weight:700;color:#fff}.q-result{background:var(--inner);border:1px solid rgba(255,255,255,.06);border-radius:10px;padding:13px 15px;margin-bottom:8px}.q-no{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.7px;margin-bottom:4px}.q-text{font-size:13px;color:#fff;font-weight:600;line-height:1.5}.q-score{margin-top:7px;font-size:12px;color:var(--muted)}.q-score span{margin-left:7px;font-weight:700}.dean-star{color:rgba(255,255,255,.16);margin-right:2px}.dean-star.filled{color:#facc15}.comment-modal{background:var(--inner);border:1px solid rgba(255,255,255,.06);border-radius:10px;padding:14px;color:var(--light);font-size:13px;line-height:1.6;font-style:italic}.comment-modal.empty{color:var(--muted);font-style:normal}.loading-eval{padding:50px 10px;text-align:center;color:var(--muted);font-size:13px}.loading-eval i{margin-right:8px}
@media(max-width:768px){body{flex-direction:column;}.sidebar{width:100%;min-height:auto;}.cat-name{width:120px;}}

.er-head-right{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.er-report-btn,.er-report-btn:link,.er-report-btn:visited{display:inline-flex;align-items:center;gap:8px;padding:10px 18px;border-radius:10px;background:var(--violet);color:#fff !important;font:700 13px 'DM Sans',Inter,Segoe UI,Arial,sans-serif;text-decoration:none;border:1px solid var(--violet);min-height:40px;line-height:1.2;cursor:pointer}
.er-report-btn:hover{opacity:.88}
.er-report-btn i{color:#fff !important}
@media(max-width:640px){.er-head-right{flex-direction:column;align-items:stretch;width:100%}}
/* ── Generate Report (inside the Dean layout) ── */
.rep-wrap{--panel:#fff;--border:#D7E3EF;--text:#0B1F3A;--muted:#647B96;--inner:#F4F8FF;--accent:#2563EB;color:var(--text);font-family:Inter,'DM Sans',Segoe UI,Arial,sans-serif;max-width:1500px}
html.dark-theme .rep-wrap{--panel:#172A45;--border:#24395A;--text:#E7ECF3;--muted:#93A5BE;--inner:#1E314F}
.rep-wrap *{box-sizing:border-box}
.rep-wrap .top-actions{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:16px}
.rep-wrap .back-btn{display:inline-flex;align-items:center;gap:8px;padding:9px 18px;border-radius:10px;font:700 13px Inter,sans-serif;text-decoration:none;cursor:pointer}
.rep-wrap .back-btn{background:var(--panel);border:1px solid var(--border);color:var(--text)}
.rep-wrap .cum-topbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;font-size:12px;margin-bottom:10px;padding:8px 14px;border:1px solid var(--border);background:var(--inner)}
.rep-wrap .cum-type{font-weight:700;text-transform:uppercase;letter-spacing:.8px}
.rep-wrap .sheet-header{background:var(--panel);border:1px solid var(--border);padding:14px 18px;margin-bottom:12px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}
.rep-wrap .sheet-avatar{width:56px;height:56px;border-radius:50%;object-fit:cover;border:2px solid var(--border)}
.rep-wrap .sheet-avatar-ph{width:56px;height:56px;border-radius:50%;background:var(--inner);border:2px solid var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:22px}
.rep-wrap .sheet-name{font-family:Rajdhani,sans-serif;font-size:22px;font-weight:700}
.rep-wrap .sheet-desig{font-size:12.5px;color:var(--muted);margin-top:2px}
.rep-wrap .cum-date{margin-left:auto;text-align:right}
.rep-wrap .cum-date-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);margin-bottom:2px}
.rep-wrap .cum-date-val{font-size:14px;font-weight:700}
.rep-wrap .cat-section{margin-bottom:16px}
.rep-wrap .cat-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1.2px;margin-bottom:8px;padding-bottom:5px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:7px}
.rep-wrap table.q-table{width:100%;border-collapse:collapse;background:var(--panel);border:1px solid var(--border)}
.rep-wrap .q-table thead tr{background:var(--inner)}
.rep-wrap .q-table th{padding:9px 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);text-align:left}
.rep-wrap .q-table th.rating-col{text-align:center;width:150px}
.rep-wrap .q-table td{padding:10px 14px;border-top:1px solid var(--border);font-size:13px;line-height:1.45;vertical-align:middle}
.rep-wrap .q-table td.q-num{width:44px;font-weight:700;color:var(--muted)}
.rep-wrap .q-table td.rating-cell{text-align:center}
.rep-wrap .rating-badge{display:inline-flex;align-items:center;justify-content:center;width:110px;height:36px;border-radius:8px;background:var(--inner)}
.rep-wrap .rating-num{font-size:15px;font-weight:700}
.rep-wrap .avg-summary{background:var(--panel);border:1px solid var(--border);padding:12px 14px;display:flex;flex-direction:column;justify-content:flex-start;align-items:flex-start;text-align:left;gap:8px;margin-bottom:12px}
.rep-wrap .cum-total-label{font-size:14px;font-weight:700;text-transform:uppercase;letter-spacing:1px}
.rep-wrap .cum-total-result{text-align:left;margin:0}
.rep-wrap .cum-legend-rows{display:flex;flex-direction:column;gap:5px;min-width:340px}
.rep-wrap .cum-legend-row{display:flex;align-items:center;gap:10px;font-size:13px}
.rep-wrap .cum-legend-dot{width:12px;height:12px;border-radius:3px;flex-shrink:0;background-color:var(--c);-webkit-print-color-adjust:exact;print-color-adjust:exact}
.rep-wrap .cum-legend-range{font-weight:700;width:92px;flex-shrink:0}
.rep-wrap .cum-legend-text{flex:1;text-align:left}
.rep-wrap .avg-score-big{font-family:Rajdhani,sans-serif;font-size:46px;font-weight:700;line-height:1}
.rep-wrap .avg-score-label{font-size:13px;font-weight:700;margin-top:4px}
.rep-wrap .avg-out-of{font-size:12px;color:var(--muted);margin-top:4px}
.rep-wrap .comment-section{background:var(--panel);border:1px solid var(--border);padding:16px 20px;margin-bottom:20px}
.rep-wrap .comment-title{font-size:13px;font-weight:700;margin-bottom:10px;display:flex;align-items:center;gap:8px}
.rep-wrap .cum-remark{font-size:13.5px;line-height:1.55;font-style:italic;margin-bottom:8px;white-space:pre-wrap}
.rep-wrap .no-comment{font-size:13px;color:var(--muted);font-style:italic}
.rep-wrap .anon-note{font-size:11px;color:var(--muted);font-style:italic;margin-top:6px}
.rep-wrap .empty-rep{text-align:center;padding:30px;border:1px solid var(--border);background:var(--panel);font-style:italic}
.rep-wrap .back-btn,.rep-wrap .back-btn:link,.rep-wrap .back-btn:visited{color:var(--text)}
@media print{
  html.dean-internal-scroll-page,html.dean-internal-scroll-page body{height:auto!important;overflow:visible!important;display:block!important;background:#fff!important}
  body>*:not(main.main){display:none!important}
  main.main.dean-internal-scroll{height:auto!important;max-height:none!important;overflow:visible!important;padding:6mm 10mm!important;background:#fff!important}
  .rep-wrap,.rep-wrap *{color:#000!important;background-color:transparent!important;border-color:#ddd!important}
  .rep-wrap .no-print{display:none!important}
  .rep-wrap .cum-topbar{background:#f5f5f5!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .rep-wrap .cat-section{break-inside:auto}.rep-wrap .cat-title{break-after:avoid}
  .rep-wrap .q-table thead{display:table-header-group}.rep-wrap .q-table tr{break-inside:avoid}
  .rep-wrap .q-table td{padding:5px 12px!important}
  .rep-wrap .rating-badge{height:26px!important;width:70px!important;border:1px solid #ccc}
  .rep-wrap .cum-legend-rows{gap:1px!important;min-width:300px!important}
  .rep-wrap .cum-legend-row{font-size:11px!important;gap:6px!important}
  .rep-wrap .cum-legend-dot{width:9px!important;height:9px!important;background-color:var(--c)!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .rep-wrap .cum-legend-range{width:74px!important}
  .rep-wrap .cum-comments-page{break-before:page;padding-top:14mm}
}
</style>

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
<link rel="stylesheet" href="includes/dean_light_theme.css?v=dashboard-ui-20261009" id="dean-light-theme-final"/>
<style id="dean-results-violet">
/* Dean theme (violet) for this page's content. The shared light theme forces
   blue (#2563EB) on icons and stat-card top borders; override it here. */
html:not(.dark-theme) main.main .section h2 i,
html:not(.dark-theme) main.main .section-title i,
html:not(.dark-theme) main.main .page-header i,
html:not(.dark-theme) main.main .stat-card i { color:#7C5FD9 !important; }
html:not(.dark-theme) main.main .stat-card,
html:not(.dark-theme) main.main .card-grid > .stat-card:nth-child(n),
html:not(.dark-theme) main.main .card-grid > a:nth-child(n) .stat-card {
  border-top:3px solid #7C5FD9 !important;
  border-top-color:#7C5FD9 !important;
}
html:not(.dark-theme) main.main .term-picker select { border-color:#D9CFF7 !important; }
html:not(.dark-theme) main.main .term-picker select:hover,
html:not(.dark-theme) main.main .term-picker select:focus { border-color:#7C5FD9 !important; }
</style>

<style id="er-view">
/* ── Evaluation Received — refreshed layout ─────────────────────── */
main.main .er-stats{grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px;margin-bottom:20px;}
main.main .er-stats .stat-card{display:flex;align-items:flex-start;gap:14px;padding:16px 18px;}
.er-stat-icon{flex:0 0 40px;width:40px;height:40px;border-radius:12px;background:rgba(124,95,217,.12);display:flex;align-items:center;justify-content:center;}
main.main .er-stats .stat-card .er-stat-icon i{margin:0;font-size:17px;}
.er-stat-body{min-width:0;}
.er-stats .stat-card .num{font-size:28px;line-height:1.1;}
.er-num-unit{font-size:14px;font-weight:600;color:var(--muted);margin-left:4px;}
.er-stat-sub{font-size:12px;color:var(--muted);margin-top:6px;line-height:1.5;overflow-wrap:anywhere;}
.er-stars{letter-spacing:2px;font-size:14px;margin-right:6px;}
.er-stars span{color:#D5DDE6;}
.er-stars span.on{color:#F5B301;}
.er-trend{font-weight:700;}
.er-trend.up{color:#059669;}
.er-trend.down{color:#DC2626;}
.er-trend.flat{color:var(--muted);}

.er-head{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:14px;}
.er-head h2{margin-bottom:0 !important;}
.er-count{font:700 12px 'DM Sans',sans-serif;background:rgba(124,95,217,.12);color:#5F45B8;padding:2px 10px;border-radius:12px;margin-left:4px;}
.er-head .term-picker{flex-direction:row;align-items:center;gap:10px;min-width:0;}
.er-head .term-picker select{width:auto;min-width:270px;}

.er-toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding:12px 0 14px;border-top:1px solid var(--border);}
.er-controls{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.er-select{padding:7px 12px;border-radius:9px;border:1px solid #D9CFF7;background:var(--inner);color:var(--light);font:500 13px 'DM Sans',sans-serif;cursor:pointer;}
.er-select:focus{outline:3px solid rgba(124,95,217,.28);outline-offset:1px;border-color:#7C5FD9;}
.er-chip{display:inline-flex;align-items:center;gap:7px;padding:7px 12px;border-radius:9px;border:1px solid #D9CFF7;font:500 13px 'DM Sans',sans-serif;color:var(--light);cursor:pointer;user-select:none;}
.er-chip input{accent-color:#7C5FD9;}
.er-shown{font-size:12px;color:var(--muted);}

.er-item{display:flex;justify-content:space-between;align-items:center;gap:18px;background:var(--inner);border:1px solid var(--border);border-radius:12px;padding:14px 18px;margin-bottom:10px;cursor:pointer;transition:border-color .15s,box-shadow .15s;}
.er-item:last-child{margin-bottom:0;}
.er-item:hover,.er-item:focus-visible{border-color:#7C5FD9;box-shadow:0 2px 12px rgba(124,95,217,.16);outline:none;}
.er-main{min-width:0;flex:1;}
.er-eyebrow{font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);display:flex;align-items:center;gap:6px;}
.er-eyebrow i{color:#7C5FD9;}
.er-meta{font-size:12px;color:var(--muted);margin-top:3px;}
.er-comment{margin-top:8px;font-size:13.5px;line-height:1.55;font-style:italic;color:var(--light);display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
.er-comment.none{font-style:normal;color:var(--muted);}
.er-side{display:flex;flex-direction:column;align-items:flex-end;gap:8px;flex-shrink:0;}
.er-badge{font-size:13px;font-weight:800;padding:4px 12px;border-radius:18px;border:1px solid transparent;white-space:nowrap;}
.er-badge small{font-size:11px;font-weight:600;opacity:.75;}
.er-badge.high{background:rgba(16,185,129,.12);color:#047857;border-color:rgba(16,185,129,.3);}
.er-badge.mid{background:rgba(245,158,11,.14);color:#B45309;border-color:rgba(245,158,11,.35);}
.er-badge.low{background:rgba(239,68,68,.12);color:#B91C1C;border-color:rgba(239,68,68,.32);}
.er-badge.none{background:rgba(109,129,148,.12);color:var(--muted);border-color:rgba(109,129,148,.25);font-weight:700;font-size:12px;}
.details-btn:hover{filter:brightness(.95);}

@media(max-width:640px){
  .er-item{flex-direction:column;align-items:flex-start;gap:12px;}
  .er-side{flex-direction:row;align-items:center;justify-content:space-between;width:100%;}
  .er-head .term-picker{flex-direction:column;align-items:stretch;width:100%;}
  .er-head .term-picker select{width:100%;min-width:0;}
}

/* ── Evaluation Details popup — cover the whole page (sidebar + header) ── */
html .eval-modal-overlay{z-index:100000 !important;background:rgba(3,10,22,.55) !important;-webkit-backdrop-filter:blur(4px);backdrop-filter:blur(4px);}
html.dark-theme .eval-modal-overlay{background:rgba(0,0,0,.65) !important;}
</style>
<style id="er-fixes">
/* Button fixes: consistent heights, disabled state, touch targets, light-theme contrast */
.er-head-right{align-items:stretch}
.er-head .term-picker select{min-height:40px}
.er-report-btn.is-disabled{opacity:.45;cursor:not-allowed;pointer-events:none}
.details-btn{min-height:32px;padding:7px 14px;font-family:'DM Sans',sans-serif}
html:not(.dark-theme) .details-btn{color:#0F766E;background:rgba(13,148,136,.10);border-color:rgba(13,148,136,.35)}
.eval-modal-close{padding:8px 10px;border-radius:8px;line-height:1}
.eval-modal-close:hover{background:rgba(124,95,217,.14);color:#fff}
.eval-modal-close:focus-visible,.details-btn:focus-visible,.er-report-btn:focus-visible,.rep-wrap .back-btn:focus-visible{outline:3px solid rgba(124,95,217,.45);outline-offset:2px}
@media(max-width:640px){.details-btn{min-height:44px;padding:10px 18px}.er-report-btn{justify-content:center;min-height:44px}.rep-wrap .top-actions{flex-direction:column;align-items:stretch}.rep-wrap .back-btn{justify-content:center}}
</style>
</head>
<body>

<?php
$active = 'results';
$sidebarScope = HIGHER_ED_LABEL . ' Division';
include __DIR__ . '/includes/dean_sidebar.php'; if (is_file(__DIR__ . '/dean_header_dark.php')) include __DIR__ . '/dean_header_dark.php';
?>

<main class="main dean-internal-scroll">
    <?php if ($reportMode): ?>
<div class="rep-wrap">
<div class="top-actions no-print">
    <a href="<?= $escape($backHref) ?>" class="back-btn"><i class="fa-solid fa-arrow-left"></i> Back to Evaluation Received</a>
</div>

<div class="cum-topbar">
    <span class="cum-type">Dean Evaluation</span>
    <span>Evaluated by: <strong><?= (int)$repCount ?> Evaluator<?= $repCount === 1 ? '' : 's' ?></strong> · <?= $escape($repTermLabel) ?></span>
</div>

<div class="sheet-header">
    <?php if (!empty($photo_src)): ?><img class="sheet-avatar" src="<?= $escape($photo_src) ?>" alt=""/>
    <?php else: ?><div class="sheet-avatar-ph"><i class="fa-solid fa-user"></i></div><?php endif; ?>
    <div>
        <div class="sheet-name"><?= $escape(($me['full_name'] ?? '')) ?></div>
        <div class="sheet-desig"><?= $escape(trim((string)($me['designation'] ?? '')) !== '' ? $me['designation'] : 'Dean') ?></div>
    </div>
    <div class="cum-date">
        <div class="cum-date-label"><?= $escape($repDateLabel) ?></div>
        <div class="cum-date-val"><?= nl2br($escape($repDateText)) ?></div>
    </div>
</div>

<?php $repNum = 1; foreach ($repByCat as $cat => $rows): ?>
<div class="cat-section">
    <div class="cat-title"><i class="fa-solid fa-layer-group" style="font-size:10px"></i> <?= $escape($cat) ?></div>
    <table class="q-table">
        <tbody>
        <?php foreach ($rows as $row): $c = $scoreColor($row['avg']); ?>
        <tr>
            <td class="q-num"><?= $repNum++ ?></td>
            <td><?= $escape($row['text']) ?></td>
            <td class="rating-cell"><div class="rating-badge" style="border:1px solid <?= $c ?>33"><span class="rating-num" style="color:<?= $c ?>"><?= number_format($row['avg'], 2) ?></span></div></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endforeach; ?>



<?php if ($repOverall !== null): ?>
<!-- RATING LEGEND: same card style as Total Score, placed right before it (satisfaction wording) -->
<div class="avg-summary cum-legend">
    <div class="cum-total-label">Rating Legend</div>
    <div class="cum-legend-rows">
        <?php foreach ([
            ['4.50 &ndash; 5.00', 'Very Satisfied',                     '#32B98A'],
            ['3.50 &ndash; 4.49', 'Satisfied',                          '#6BCFA9'],
            ['2.50 &ndash; 3.49', 'Neither Satisfied nor Dissatisfied', '#facc15'],
            ['1.50 &ndash; 2.49', 'Dissatisfied',                       '#fb923c'],
            ['1.00 &ndash; 1.49', 'Very Dissatisfied',                  '#E6788A'],
        ] as $lg): ?>
        <div class="cum-legend-row">
            <span class="cum-legend-dot" style="--c:<?= $lg[2] ?>"></span>
            <span class="cum-legend-range"><?= $lg[0] ?></span>
            <span class="cum-legend-text"><?= $lg[1] ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="avg-summary">
    <div class="cum-total-label">Total Score</div>
    <div class="cum-total-result">
        <div class="avg-score-big" style="color:<?= $scoreColor($repOverall) ?>"><?= number_format($repOverall, 2) ?></div>
        <div class="avg-score-label" style="color:<?= $scoreColor($repOverall) ?>"><?= $escape($satLabel($repOverall)) ?></div>
        <div class="avg-out-of">Overall cumulative rating · out of 5.00</div>
    </div>
</div>
<?php endif; ?>

<div class="cum-comments-page">
    <div class="comment-section">
        <div class="comment-title"><i class="fa-solid fa-comment-dots"></i> Comments, Suggestions &amp; Areas for Improvement</div>
        <?php if ($repComments): foreach ($repComments as $rc): ?>
        <div class="cum-remark">&ldquo;<?= $escape($rc) ?>&rdquo;</div>
        <?php endforeach; else: ?>
        <div class="no-comment">No comments or suggestions were provided.</div>
        <?php endif; ?>
        <div class="anon-note"></div>
    </div>
</div>
</div>
    <?php elseif (!$showData): ?>
        <p class="empty-note">No evaluation term is available yet.</p>
    <?php else: ?>

    <!-- OVERVIEW -->
    <div class="card-grid er-stats">
        <div class="stat-card">
            <div class="er-stat-icon"><i class="fa-solid fa-star"></i></div>
            <div class="er-stat-body">
                <div class="num"><?= $overallAvg !== null ? $overallAvg : '—' ?><?php if ($overallAvg !== null): ?><span class="er-num-unit">/ 5</span><?php endif; ?></div>
                <div class="label">Average Rating</div>
                <?php if ($overallAvg !== null): ?>
                <div class="er-stat-sub">
                    <span class="er-stars" aria-hidden="true"><?php for ($i = 1; $i <= 5; $i++): ?><span class="<?= $i <= $starsOn ? 'on' : '' ?>">★</span><?php endfor; ?></span>
                    <?php if ($trendText !== ''): ?>
                        <span class="er-trend <?= $trendClass ?>"><?= htmlspecialchars($trendText) ?></span> vs <?= htmlspecialchars($prevTermLabel) ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="stat-card">
            <div class="er-stat-icon"><i class="fa-solid fa-comments"></i></div>
            <div class="er-stat-body">
                <div class="num"><?= (int)$responseCount ?></div>
                <div class="label">Responses Received</div>
                <?php if ($responseCount > 0): ?><div class="er-stat-sub"><?= (int)$scoredCount ?> scored · <?= (int)$commentCount ?> with comments</div><?php endif; ?>
            </div>
        </div>
        <?php if ($topCat): ?>
        <div class="stat-card">
            <div class="er-stat-icon"><i class="fa-solid fa-arrow-trend-up"></i></div>
            <div class="er-stat-body">
                <div class="num"><?= htmlspecialchars((string)$topCat['avg']) ?><span class="er-num-unit">/ 5</span></div>
                <div class="label">Highest-Rated Area</div>
                <div class="er-stat-sub"><?= htmlspecialchars($topCat['name']) ?></div>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($lowCat): ?>
        <div class="stat-card">
            <div class="er-stat-icon"><i class="fa-solid fa-arrow-trend-down"></i></div>
            <div class="er-stat-body">
                <div class="num"><?= htmlspecialchars((string)$lowCat['avg']) ?><span class="er-num-unit">/ 5</span></div>
                <div class="label">Lowest-Rated Area</div>
                <div class="er-stat-sub"><?= htmlspecialchars($lowCat['name']) ?></div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- EVALUATIONS RECEIVED (anonymous) -->
    <div class="section">
        <div class="er-head">
            <h2><i class="fa-solid fa-clock-rotate-left"></i> Evaluations Received <span class="er-count"><?= (int)$responseCount ?></span></h2>
            <div class="er-head-right">
            <?php if ($history): ?>
            <a class="er-report-btn" href="?report=1&amp;period=<?= $allPeriodsSelected ? 'all' : (int)$selPeriod ?>"><i class="fa-solid fa-file-lines"></i> Generate Report</a>
            <?php else: ?>
            <span class="er-report-btn is-disabled" aria-disabled="true" title="No evaluations to report for this selection"><i class="fa-solid fa-file-lines"></i> Generate Report</span>
            <?php endif; ?>
            <?php if ($terms): ?>
            <form method="get" class="term-picker">
                <label for="termSelect"><i class="fa-solid fa-calendar-days"></i> Evaluation Term</label>
                <select id="termSelect" name="period" onchange="this.form.submit()">
                    <option value="all"<?= $allPeriodsSelected ? ' selected' : '' ?>>All Evaluation Terms — Past &amp; Current</option>
                    <?php foreach ($terms as $t): ?>
                    <option value="<?= (int)$t['id'] ?>"<?= !$allPeriodsSelected && (int)$t['id'] === $selPeriod ? ' selected' : '' ?>><?= htmlspecialchars($t['label']) ?><?= $t['current'] ? ' (Current)' : ($t['archived'] ? ' (Archived)' : '') ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php endif; ?>
            </div>
        </div>

        <?php if (empty($history)): ?>
            <p class="empty-note"><?= $allPeriodsSelected ? 'No responses were submitted across any evaluation term.' : 'No responses were submitted for this term.' ?></p>
        <?php else: ?>
            <div class="er-toolbar">
                <div class="er-controls">
                    <select id="erSort" class="er-select" onchange="erApply()" aria-label="Sort evaluations">
                        <option value="newest">Newest first</option>
                        <option value="oldest">Oldest first</option>
                        <option value="high">Highest score</option>
                        <option value="low">Lowest score</option>
                    </select>
                    <label class="er-chip"><input type="checkbox" id="erOnlyComments" onchange="erApply()"> With comments only</label>
                </div>
                <span class="er-shown" id="erShown">Showing <?= (int)$responseCount ?> of <?= (int)$responseCount ?></span>
            </div>

            <div id="erList">
            <?php foreach ($displayList as $h):
                $src = $h['_source'] === 'kept' ? 'kept' : 'live';
                $cls = er_score_class($h['score']);
                $hid  = !empty($h['hidden']);
                $open = 'openDeanEvalDetails(' . (int)$h['_tracker_id'] . ",'" . $src . "')";
            ?>
                <div class="er-item"
                     data-ts="<?= (int)$h['ts'] ?>" data-idx="<?= (int)$h['idx'] ?>"
                     data-score="<?= (!$hid && $h['score'] !== null) ? htmlspecialchars((string)$h['score']) : '-1' ?>"
                     data-comment="<?= $h['comment_ok'] ? '1' : '0' ?>"
                     <?= $hid ? '' : 'onclick="' . $open . '"' ?>>
                    <div class="er-main">
                        <div class="er-eyebrow"><i class="fa-solid fa-user-secret"></i> Anonymous — <?= htmlspecialchars($h['label']) ?></div>
                        <div class="er-meta"><?= htmlspecialchars($h['submitted_at_label']) ?><?= !empty($h['period_label']) ? ' · ' . htmlspecialchars($h['period_label']) : '' ?></div>
                        <?php if ($hid): ?>
                            <p class="er-comment none">Details hidden until this term has at least <?= (int)MIN_RESPONSES_FOR_DETAIL ?> responses (protects evaluator anonymity).</p>
                        <?php elseif ($h['comment_ok']): ?>
                            <p class="er-comment">&ldquo;<?= htmlspecialchars($h['comment']) ?>&rdquo;</p>
                        <?php else: ?>
                            <p class="er-comment none">No written comment</p>
                        <?php endif; ?>
                    </div>
                    <div class="er-side">
                        <?php if ($hid): ?>
                            <span class="er-badge none">Hidden</span>
                        <?php elseif ($h['score'] !== null): ?>
                            <span class="er-badge <?= $cls ?>"><?= htmlspecialchars((string)$h['score']) ?><small> / 5</small></span>
                        <?php else: ?>
                            <span class="er-badge none">No score</span>
                        <?php endif; ?>
                        <?php if (!$hid): ?><button type="button" class="details-btn" aria-label="View evaluation details" onclick="event.stopPropagation();<?= $open ?>">View Details <i class="fa-solid fa-chevron-right"></i></button><?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
            <p class="empty-note" id="erNoMatch" style="display:none;">No evaluations match this filter.</p>
        <?php endif; ?>
    </div>

    <?php endif; ?>
</main>

<div class="eval-modal-overlay" id="deanEvalModal">
  <div class="eval-modal" role="dialog" aria-modal="true" aria-labelledby="deanEvalTitle">
    <div class="eval-modal-header"><div class="eval-modal-title" id="deanEvalTitle"><i class="fa-solid fa-star"></i> Evaluation Details</div><button type="button" class="eval-modal-close" aria-label="Close evaluation details" onclick="closeDeanEvalDetails()"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="eval-modal-body" id="deanEvalBody"><div class="empty-note">Loading evaluation…</div></div>
  </div>
</div>

<script>
function erApply(){const list=document.getElementById('erList');if(!list)return;const items=[].slice.call(list.querySelectorAll('.er-item'));const mode=document.getElementById('erSort').value;const only=document.getElementById('erOnlyComments').checked;const n=(el,k)=>parseFloat(el.dataset[k])||0;const lowKey=el=>{const v=parseFloat(el.dataset.score);return v<0?Infinity:v;};
items.sort((a,b)=>{if(mode==='oldest')return n(a,'ts')-n(b,'ts')||n(a,'idx')-n(b,'idx');if(mode==='high')return parseFloat(b.dataset.score)-parseFloat(a.dataset.score)||n(b,'ts')-n(a,'ts');if(mode==='low'){const x=lowKey(a),y=lowKey(b);return x===y?n(b,'ts')-n(a,'ts'):(x<y?-1:1);}return n(b,'ts')-n(a,'ts')||n(b,'idx')-n(a,'idx');});
let shown=0;items.forEach(el=>{const ok=!only||el.dataset.comment==='1';el.style.display=ok?'':'none';if(ok)shown++;list.appendChild(el);});
document.getElementById('erShown').textContent='Showing '+shown+' of '+items.length;document.getElementById('erNoMatch').style.display=shown?'none':'block';}
function escDean(v){if(v===null||v===undefined)return '';const d=document.createElement('div');d.textContent=v;return d.innerHTML;}
function starsDean(score){let h='';for(let i=1;i<=5;i++)h+=`<i class="fa-solid fa-star dean-star ${i<=score?'filled':''}"></i>`;return h;}
function openDeanEvalDetails(id,src){const m=document.getElementById('deanEvalModal'),b=document.getElementById('deanEvalBody');b.innerHTML='<div class="loading-eval"><i class="fa-solid fa-spinner fa-spin"></i> Loading evaluation…</div>';window._deanLastFocus=document.activeElement;m.classList.add('open');document.body.style.overflow='hidden';var cb=m.querySelector('.eval-modal-close');if(cb)cb.focus();fetch('dean_results.php?details='+encodeURIComponent(id)+(src==='kept'?'&src=kept':''),{credentials:'same-origin'}).then(r=>r.json()).then(d=>{if(!d.ok){b.innerHTML='<div class="loading-eval"><i class="fa-solid fa-triangle-exclamation"></i>'+escDean(d.error||'Unable to load this evaluation.')+'</div>';return;}let h=`<div class="info-grid"><div><div class="info-label">Evaluator</div><div class="info-value"><i class="fa-solid fa-eye-slash"></i> Anonymous Evaluator</div></div><div><div class="info-label">Period</div><div class="info-value">${escDean(d.period_label||'—')}</div></div><div><div class="info-label">Submitted</div><div class="info-value">${escDean(d.submitted_at||'—')}</div></div><div><div class="info-label">Overall Score</div><div class="info-value score-big">${d.overall_score!==null&&d.overall_score!==undefined?parseFloat(Number(d.overall_score).toFixed(2)):'—'} / 5</div></div></div>`;if(d.categories?.length){h+='<h3 class="modal-section-title">Performance by Category</h3>';d.categories.forEach(c=>{const pct=Math.round((c.avg/5)*100);h+=`<div class="cat-row-modal"><div class="cat-name-modal">${escDean(c.category)}</div><div class="cat-bar"><div style="width:${pct}%"></div></div><div class="cat-score-modal">${Number(c.avg).toFixed(2)}</div></div>`})}if(d.questions?.length){h+='<h3 class="modal-section-title">Question-by-Question Results</h3>';d.questions.forEach((q,i)=>{h+=`<div class="q-result"><div class="q-no">Question ${i+1}</div><div class="q-text">${escDean(q.question_text)}</div><div class="q-score">${starsDean(q.score)} <span>Score: ${q.score} / 5</span></div></div>`})}h+='<h3 class="modal-section-title">Comments / Feedback</h3>';h+=d.comment?`<div class="comment-modal">“${escDean(d.comment)}”</div>`:'<div class="comment-modal empty">No written feedback was provided.</div>';b.innerHTML=h;}).catch(()=>{b.innerHTML='<div class="loading-eval"><i class="fa-solid fa-triangle-exclamation"></i>Something went wrong loading this evaluation.</div>';});}
function closeDeanEvalDetails(){document.getElementById('deanEvalModal').classList.remove('open');document.body.style.overflow='';if(window._deanLastFocus&&window._deanLastFocus.focus){window._deanLastFocus.focus();window._deanLastFocus=null;}}
document.getElementById('deanEvalModal').addEventListener('click',function(e){if(e.target===this)closeDeanEvalDetails();});
document.addEventListener('keydown',function(e){if(e.key==='Escape')closeDeanEvalDetails();});
</script>
</body>
</html>
<?php if (isset($mysqli) && $mysqli instanceof mysqli) { $mysqli->close(); } ?>