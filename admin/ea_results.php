<?php
// admin/ea_results.php
// Anonymous evaluation results received by the Executive Assistant (EA).
// All submitted evaluations of the EA (Principal, Dean, Staff, ...). Evaluator
// identity is deliberately never exposed on this page, including in the
// detail view, to preserve anonymous feedback.

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => false,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
require_once 'db.php';
require_once dirname(__DIR__) . '/shared/system_settings_service.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$role = $_SESSION['role'] ?? '';
if (empty($_SESSION['user_id']) || !in_array($role, ['superadmin', 'executive_assistant'], true)) {
    http_response_code(403);
    exit('Unauthorized');
}

$ea_id = (int)$_SESSION['user_id'];
$escape = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

function safe_rows(mysqli $mysqli, string $sql, string $types = '', array $params = []): array {
    try {
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) return [];
        if ($types !== '') { $stmt->bind_param($types, ...$params); }
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $rows;
    } catch (mysqli_sql_exception $e) {
        return [];
    }
}

// Placeholder comments such as "N/A" or "none" count as no written comment.
function ea_clean_comment($v): string {
    $t = trim((string)$v);
    if ($t === '' || preg_match('/^(n\/?a|none|nil|no comments?|no feedback|not applicable|[\s.\-–—]+)$/iu', $t)) return '';
    return $t;
}

// Resolve the currently signed-in EA. The role check above makes this the EA
// whose own results should be displayed, rather than another person's records.
$ea = $mysqli->prepare("SELECT id, full_name, designation, photo FROM users WHERE id=? LIMIT 1");
$ea->bind_param('i', $ea_id);
$ea->execute();
$eaRow = $ea->get_result()->fetch_assoc();
$ea->close();

if (!$eaRow) {
    http_response_code(404);
    exit('Executive Assistant account not found.');
}

// Received evaluations = every submitted evaluation whose target is this EA
// account, whoever the authorized evaluator group is (Principal, Dean, Staff,
// Faculty ...) and whichever eval_type their portal writes. The EA's own
// evaluations of others are excluded (evaluator_id<>?), and drafts are ignored.
// NOTE: evaluator_id is used ONLY as a filter — no evaluator column is ever
// selected, returned or rendered. Keep it that way.
const EA_RECEIVED_FROM = "
    FROM evaluation_tracker et
    LEFT JOIN evaluation_periods ep ON ep.id = et.period_id";
const EA_RECEIVED_WHERE = "et.target_user_id=? AND et.evaluator_id<>? AND et.status IN ('submitted','approved')";

// Detail endpoint used by the modal. It returns no evaluator identity at all.
// ?details=<id>            -> live evaluation_tracker row
// ?details=<id>&src=kept   -> identity-free copy kept by System Archive
if (isset($_GET['details'])) {
    header('Content-Type: application/json; charset=utf-8');
    $detailId = (int)$_GET['details'];
    $isKept   = (($_GET['src'] ?? '') === 'kept');

    if ($isKept) {
        $rows = safe_rows($mysqli, "SELECT remarks, submitted_at, period_label, school_year, semester, answers_json
            FROM feedback_received_keep WHERE id=? AND target_user_id=? LIMIT 1", 'ii', [$detailId, $ea_id]);
        $kept = $rows[0] ?? null;
        if (!$kept) {
            http_response_code(404);
            echo json_encode(['error' => 'Evaluation not found.']);
            exit;
        }
        $answers = [];
        foreach ((json_decode((string)$kept['answers_json'], true) ?: []) as $a) {
            $answers[] = [
                'category'      => $a['category'] ?? 'General',
                'question_text' => $a['question_text'] ?? 'Question',
                'answer_score'  => isset($a['score']) ? (float)$a['score'] : null,
            ];
        }
        $remarks     = (string)($kept['remarks'] ?? '');
        $submittedAt = $kept['submitted_at'];
        $periodLabel = (!empty($kept['school_year']) && !empty($kept['semester']))
            ? $kept['school_year'] . ' · ' . $kept['semester']
            : ((string)($kept['period_label'] ?? '') ?: 'Evaluation Period');
    } else {
        $rows = safe_rows($mysqli, "
            SELECT et.id, et.submitted_at, et.remarks,
                   COALESCE(ep.period_label, et.period, 'Evaluation Period') AS period_label
            " . EA_RECEIVED_FROM . "
            WHERE et.id=? AND " . EA_RECEIVED_WHERE . "
            LIMIT 1", 'iii', [$detailId, $ea_id, $ea_id]);
        $tracker = $rows[0] ?? null;
        if (!$tracker) {
            http_response_code(404);
            echo json_encode(['error' => 'Evaluation not found.']);
            exit;
        }
        $answers = safe_rows($mysqli, "
            SELECT qa.answer_score,
                   COALESCE(eq.category, uq.category, 'General') AS category,
                   COALESCE(eq.question_text, uq.question_text,
                            CONCAT('Question #', COALESCE(qa.question_id, qa.user_question_id, qa.id))) AS question_text
            FROM questionnaire_answers qa
            LEFT JOIN evaluation_questions eq ON qa.question_source='evaluation' AND eq.id=qa.question_id
            LEFT JOIN user_questions uq ON qa.question_source='user' AND uq.id=COALESCE(qa.user_question_id, qa.question_id)
            WHERE qa.tracker_id=?
            ORDER BY category, qa.id", 'i', [$detailId]);
        $remarks     = (string)($tracker['remarks'] ?? '');
        $submittedAt = $tracker['submitted_at'];
        $periodLabel = $tracker['period_label'];
    }

    $categories = [];
    $sumAll = 0.0; $cntAll = 0;
    foreach ($answers as $answer) {
        $cat = (string)($answer['category'] ?? 'General');
        if (!isset($categories[$cat])) { $categories[$cat] = ['sum' => 0.0, 'count' => 0]; }
        if ($answer['answer_score'] !== null) {
            $categories[$cat]['sum'] += (float)$answer['answer_score'];
            $categories[$cat]['count']++;
            $sumAll += (float)$answer['answer_score'];
            $cntAll++;
        }
    }
    $categoryRows = [];
    foreach ($categories as $cat => $stats) {
        $categoryRows[] = [
            'category' => $cat,
            'score'    => $stats['count'] ? round($stats['sum'] / $stats['count'], 2) : null,
        ];
    }

    $remarks = ea_clean_comment($remarks);

    echo json_encode([
        'evaluator'     => 'Anonymous Evaluator',
        'period'        => $periodLabel,
        'submitted'     => $submittedAt ? date('F j, Y g:i A', strtotime($submittedAt)) : '—',
        'overall_score' => $cntAll ? round($sumAll / $cntAll, 2) : null,
        'categories'    => $categoryRows,
        'answers'       => array_map(static function ($row) {
            return [
                'category' => $row['category'] ?? 'General',
                'question' => $row['question_text'] ?? 'Question',
                'score'    => $row['answer_score'] !== null ? (float)$row['answer_score'] : null,
            ];
        }, $answers),
        'remarks'       => $remarks,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── GLOBAL SYSTEM SETTINGS (current evaluation term) ────────────────
$settings      = get_system_settings($mysqli);
$period_id_int = (int)($settings['period_id'] ?? 0);
$hasPeriod     = $period_id_int > 0;

// ── EVALUATION TERMS (dropdown) ─────────────────────────────────────
// A term is listed when it is the active period, still has live feedback for
// this EA, or has feedback kept by System Archive (feedback_received_keep).
$termIds = [];
if ($hasPeriod) { $termIds[$period_id_int] = true; }

foreach (safe_rows($mysqli, "SELECT DISTINCT et.period_id AS pid " . EA_RECEIVED_FROM . " WHERE " . EA_RECEIVED_WHERE,
        'ii', [$ea_id, $ea_id]) as $r) {
    if ((int)$r['pid'] > 0) $termIds[(int)$r['pid']] = true;
}
$keptTerms = [];
foreach (safe_rows($mysqli, "SELECT period_id AS pid, MAX(school_year) AS school_year, MAX(semester) AS semester, MAX(period_label) AS period_label
        FROM feedback_received_keep WHERE target_user_id=? GROUP BY period_id", 'i', [$ea_id]) as $r) {
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
        $t['current']  = ($hasPeriod && $pid === $period_id_int);
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

$requestedPeriod    = $_GET['period'] ?? 'all';
$allPeriodsSelected = !is_string($requestedPeriod) || strtolower(trim($requestedPeriod)) === 'all' || $requestedPeriod === '';
$selPeriod          = $allPeriodsSelected ? 0 : (int)$requestedPeriod;
if (!$allPeriodsSelected && !isset($terms[$selPeriod])) { $allPeriodsSelected = true; $selPeriod = 0; }

// ── RESPONSES (live + kept), identity-free ──────────────────────────
// Selects ONLY tracker id, comment, score totals, period labels and submission
// time. No evaluator_id / name / photo / department column — do not add one.
$liveSql = "
    SELECT et.id, et.remarks AS comment, et.submitted_at,
           COALESCE(ep.period_label, et.period) AS period_label, ep.semester, ep.school_year,
           (SELECT SUM(qa2.answer_score)   FROM questionnaire_answers qa2 WHERE qa2.tracker_id = et.id) AS score_sum,
           (SELECT COUNT(qa2.answer_score) FROM questionnaire_answers qa2 WHERE qa2.tracker_id = et.id) AS score_count
    " . EA_RECEIVED_FROM . "
    WHERE " . EA_RECEIVED_WHERE . ($allPeriodsSelected ? '' : ' AND et.period_id=?');
$liveRows = $allPeriodsSelected
    ? safe_rows($mysqli, $liveSql, 'ii',  [$ea_id, $ea_id])
    : safe_rows($mysqli, $liveSql, 'iii', [$ea_id, $ea_id, $selPeriod]);

$keptSql = "SELECT id, remarks, submitted_at, school_year, semester, period_label, score_sum, score_count
    FROM feedback_received_keep WHERE target_user_id=?" . ($allPeriodsSelected ? '' : ' AND period_id=?');
$keptRows = $allPeriodsSelected
    ? safe_rows($mysqli, $keptSql, 'i',  [$ea_id])
    : safe_rows($mysqli, $keptSql, 'ii', [$ea_id, $selPeriod]);

$items = [];
foreach ($liveRows as $r) {
    $items[] = ['source' => 'live', 'key' => (int)$r['id'], 'comment' => $r['comment'] ?: '', 'submitted_at' => $r['submitted_at'],
        'score_sum' => (float)($r['score_sum'] ?? 0), 'score_count' => (int)($r['score_count'] ?? 0),
        'school_year' => $r['school_year'] ?? '', 'semester' => $r['semester'] ?? '', 'period_label' => $r['period_label'] ?? ''];
}
foreach ($keptRows as $r) {
    $items[] = ['source' => 'kept', 'key' => (int)$r['id'], 'comment' => $r['remarks'] ?: '', 'submitted_at' => $r['submitted_at'],
        'score_sum' => (float)$r['score_sum'], 'score_count' => (int)$r['score_count'],
        'school_year' => $r['school_year'] ?? '', 'semester' => $r['semester'] ?? '', 'period_label' => $r['period_label'] ?? ''];
}

// Submission order only — never evaluator identity.
usort($items, function ($a, $b) {
    $ta = $a['submitted_at'] ? strtotime($a['submitted_at']) : 0;
    $tb = $b['submitted_at'] ? strtotime($b['submitted_at']) : 0;
    return $ta <=> $tb ?: ($a['key'] <=> $b['key']);
});

$history = []; $totalSum = 0.0; $totalCount = 0; $n = 1;
foreach ($items as $it) {
    $totalSum   += $it['score_sum'];
    $totalCount += $it['score_count'];
    $cleanComment = ea_clean_comment($it['comment']);
    $history[] = [
        'id'                 => $it['key'],
        'ts'                 => $it['submitted_at'] ? (int)strtotime($it['submitted_at']) : 0,
        'idx'                => $n,
        'comment_ok'         => $cleanComment !== '',
        'source'             => $it['source'],
        'label'              => 'Evaluation #' . $n,
        'score'              => $it['score_count'] > 0 ? round($it['score_sum'] / $it['score_count'], 2) : null,
        'comment'            => $cleanComment,
        'submitted_at_label' => $it['submitted_at'] ? date('M j, Y, g:i A', strtotime($it['submitted_at'])) : 'Unknown date',
        'period_label'       => (!empty($it['school_year']) && !empty($it['semester'])) ? ($it['school_year'] . ' · ' . $it['semester']) : ($it['period_label'] ?: $it['semester']),
    ];
    $n++;
}
$responseCount = count($history);
$overallAvg    = $totalCount > 0 ? round($totalSum / $totalCount, 2) : null;

// ── SUMMARY STATS FOR THE OVERVIEW CARDS ────────────────────────────
// Aggregates only — nothing here touches evaluator identity.
$fmt = static function ($v) { return rtrim(rtrim(number_format((float)$v, 2), '0'), '.'); };
$scoredCount = 0; $commentCount = 0;
foreach ($history as $h) {
    if ($h['score'] !== null) $scoredCount++;
    if ($h['comment_ok']) $commentCount++;
}

// Category averages across every response shown.
$catAgg = []; $liveIds = []; $keptIds = [];
foreach ($history as $h) {
    if ($h['source'] === 'kept') { $keptIds[] = (int)$h['id']; } else { $liveIds[] = (int)$h['id']; }
}
if ($liveIds) {
    $inLive = implode(',', array_map('intval', $liveIds));
    $catRows = safe_rows($mysqli, "
        SELECT COALESCE(eq.category, uq.category, 'General') AS category, SUM(qa.answer_score) AS s, COUNT(qa.answer_score) AS c
        FROM questionnaire_answers qa
        LEFT JOIN evaluation_questions eq ON qa.question_source='evaluation' AND eq.id=qa.question_id
        LEFT JOIN user_questions uq ON qa.question_source='user' AND uq.id=COALESCE(qa.user_question_id, qa.question_id)
        WHERE qa.tracker_id IN ($inLive)
        GROUP BY COALESCE(eq.category, uq.category, 'General')
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
    $keptAns = safe_rows($mysqli, "SELECT answers_json FROM feedback_received_keep WHERE target_user_id=? AND id IN ($inKept)", 'i', [$ea_id]);
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
            WHERE " . EA_RECEIVED_WHERE . " AND et.period_id=?", 'iii', [$ea_id, $ea_id, $prevId]);
        if ($pl) { $pSum += (float)$pl[0]['s']; $pCnt += (int)$pl[0]['c']; }
        $pk = safe_rows($mysqli, "SELECT SUM(score_sum) AS s, SUM(score_count) AS c FROM feedback_received_keep WHERE target_user_id=? AND period_id=?", 'ii', [$ea_id, $prevId]);
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

// ── GENERATE REPORT ─────────────────────────────────────────────────
// One combined report for the selected term (or all terms): every question's
// average rating across ALL evaluators, the overall cumulative rating, and
// every written comment. Fully anonymous — it reuses the identity-free
// $history rows above and never selects evaluator_id / name / photo.
// Submissions with no scored answers are left out, so "Evaluators" matches
// the ratings the averages are actually built from.
if (($_GET['report'] ?? '') === '1') {
    $repLive = []; $repKept = []; $repDates = []; $repComments = []; $repEvals = [];
    foreach ($history as $h) {
        if ($h['score'] === null) continue;
        $repEvals[] = ['ts' => (int)$h['ts'], 'score' => (float)$h['score']];
        if ($h['source'] === 'kept') { $repKept[] = (int)$h['id']; } else { $repLive[] = (int)$h['id']; }
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
        foreach (safe_rows($mysqli, "SELECT answers_json FROM feedback_received_keep WHERE target_user_id=? AND id IN ($inRepK)", 'i', [$ea_id]) as $r) {
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

    $repTermLabel = (!$allPeriodsSelected && isset($terms[$selPeriod])) ? $terms[$selPeriod]['label'] : 'All Evaluation Terms — Past & Current';
    // Date Evaluated — same term-based rules as the Dean's Evaluation Received report:
    //  1. Current term  -> the evaluation's open/close times from System Settings.
    //  2. Past term     -> the open/close saved on its evaluation_periods row.
    //  3. All terms (or no schedule saved) -> Earliest / Latest submission date.
    $repDateLines = [];
    $repTz = new DateTimeZone('Asia/Manila');
    $repFmtSched = static function (string $v) use ($repTz): string {
        if ($v === '') return '';
        try { return (new DateTime($v, $repTz))->format('M j, Y, g:i A'); }
        catch (Exception $e) { return ''; }
    };
    $repFmtP = static function (string $v) use ($repTz): string {
        try {
            $hasTime = (bool)preg_match('/\d{1,2}:\d{2}/', $v);
            return (new DateTime($v, $repTz))->format($hasTime ? 'M j, Y, g:i A' : 'M j, Y');
        } catch (Exception $e) { return ''; }
    };
    $repFmtTs = static fn(int $ts): string => (new DateTime('@' . $ts))->setTimezone($repTz)->format('M j, Y');

    $repSched = ['eval_start' => '', 'eval_end' => ''];
    if ($repSchedRes = $mysqli->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('eval_start','eval_end')")) {
        while ($repSchedRow = $repSchedRes->fetch_assoc()) $repSched[$repSchedRow['setting_key']] = trim((string)$repSchedRow['setting_value']);
    }
    $repOpen  = $repFmtSched($repSched['eval_start']);
    $repClose = $repFmtSched($repSched['eval_end']);
    // The System Settings schedule belongs to the ACTIVE term only.
    $repIsCurrentTerm = !$allPeriodsSelected && !empty($terms[$selPeriod]['current']);

    if ($repIsCurrentTerm && $repOpen !== '' && $repClose !== '') {
        $repDateLines = ['Open: ' . $repOpen, 'Close: ' . $repClose];
    } elseif (!$allPeriodsSelected
              && ($repPeriodRow = safe_rows($mysqli, "SELECT * FROM evaluation_periods WHERE id=? LIMIT 1", 'i', [$selPeriod])[0] ?? null)
              && ($repPStart = trim((string)($repPeriodRow['eval_start_at'] ?? $repPeriodRow['date_start'] ?? $repPeriodRow['start_date'] ?? ''))) !== ''
              && ($repPEnd   = trim((string)($repPeriodRow['eval_end_at']   ?? $repPeriodRow['date_end']   ?? $repPeriodRow['end_date']   ?? ''))) !== '') {
        $repDateLines = ['Open: ' . $repFmtP($repPStart), 'Close: ' . $repFmtP($repPEnd)];
    } elseif ($repDates) {
        $repDMin = min($repDates); $repDMax = max($repDates);
        $repDateLines = ($repFmtTs($repDMin) === $repFmtTs($repDMax))
            ? [$repFmtTs($repDMin)]
            : ['Earliest: ' . $repFmtTs($repDMin), 'Latest: ' . $repFmtTs($repDMax)];
    } else { $repDateLines = ['—']; }

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
    $backHref = 'ea_results.php' . ($allPeriodsSelected ? '' : '?period=' . (int)$selPeriod);
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Evaluation Report — PBI</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script>
(function(){
  function theme(){
    try{ if(window.parent&&window.parent!==window){var t=window.parent.document.documentElement.getAttribute('data-theme'); if(t==='dark'||t==='light') return t;} }catch(e){}
    var s=null; try{s=localStorage.getItem('pbiTheme');}catch(e){} return s==='dark'?'dark':'light';
  }
  function sync(){var t=theme();document.documentElement.setAttribute('data-theme',t);document.documentElement.style.colorScheme=t;}
  sync();
  try{var pr=window.parent&&window.parent!==window?window.parent.document.documentElement:null;
      if(pr&&window.MutationObserver) new MutationObserver(sync).observe(pr,{attributes:true,attributeFilter:['data-theme']});}catch(e){}
})();
</script>
<style>
:root{--bg:#F8FAFC;--panel:#fff;--border:#D7E3EF;--text:#0B1F3A;--muted:#647B96;--inner:#F4F8FF;--accent:#2563EB}
html[data-theme="dark"]{--bg:#0A192F;--panel:#172A45;--border:#24395A;--text:#E7ECF3;--muted:#93A5BE;--inner:#1E314F}
*{box-sizing:border-box}
body{margin:0;padding:28px;font-family:Inter,Segoe UI,Arial,sans-serif;background:var(--bg);color:var(--text)}
.top-actions{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:16px}
.back-btn,.btn-print{display:inline-flex;align-items:center;gap:8px;padding:9px 18px;border-radius:10px;font:700 13px Inter,sans-serif;text-decoration:none;cursor:pointer}
.back-btn{background:var(--panel);border:1px solid var(--border);color:var(--text)}
.btn-print{background:var(--accent);border:0;color:#fff}
.cum-topbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;font-size:12px;margin-bottom:10px;padding:8px 14px;border:1px solid var(--border);background:var(--inner)}
.cum-type{font-weight:700;text-transform:uppercase;letter-spacing:.8px}
.sheet-header{background:var(--panel);border:1px solid var(--border);padding:14px 18px;margin-bottom:12px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}
.sheet-avatar{width:56px;height:56px;border-radius:50%;object-fit:cover;border:2px solid var(--border)}
.sheet-avatar-ph{width:56px;height:56px;border-radius:50%;background:var(--inner);border:2px solid var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:22px}
.sheet-name{font-family:Rajdhani,sans-serif;font-size:22px;font-weight:700}
.sheet-desig{font-size:12.5px;color:var(--muted);margin-top:2px}
.cum-date{margin-left:auto;text-align:right}
.cum-date-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);margin-bottom:2px}
.cum-date-val{font-size:14px;font-weight:700;line-height:1.5}
.cat-section{margin-bottom:16px}
.cat-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1.2px;margin-bottom:8px;padding-bottom:5px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:7px}
table.q-table{width:100%;border-collapse:collapse;background:var(--panel);border:1px solid var(--border)}
.q-table thead tr{background:var(--inner)}
.q-table th{padding:9px 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);text-align:left}
.q-table th.rating-col{text-align:center;width:150px}
.q-table td{padding:10px 14px;border-top:1px solid var(--border);font-size:13px;line-height:1.45;vertical-align:middle}
.q-table td.q-num{width:44px;font-weight:700;color:var(--muted)}
.q-table td.rating-cell{text-align:center}
.rating-badge{display:inline-flex;align-items:center;justify-content:center;width:110px;height:36px;border-radius:8px;background:var(--inner)}
.rating-num{font-size:15px;font-weight:700}
.avg-summary{background:var(--panel);border:1px solid var(--border);padding:16px 22px;display:flex;flex-direction:column;align-items:flex-start;gap:10px;margin-bottom:14px}
.cum-total-label{font-size:14px;font-weight:700;text-transform:uppercase;letter-spacing:1px}
.cum-total-result{text-align:left}
.avg-score-big{font-family:Rajdhani,sans-serif;font-size:46px;font-weight:700;line-height:1}
.avg-score-label{font-size:13px;font-weight:700;margin-top:4px}
.avg-out-of{font-size:12px;color:var(--muted);margin-top:4px}
.comment-section{background:var(--panel);border:1px solid var(--border);padding:16px 20px;margin-bottom:20px}
.comment-title{font-size:13px;font-weight:700;margin-bottom:10px;display:flex;align-items:center;gap:8px}
.cum-remark{font-size:13.5px;line-height:1.55;font-style:italic;margin-bottom:8px;white-space:pre-wrap}
.no-comment{font-size:13px;color:var(--muted);font-style:italic}
.anon-note{font-size:11px;color:var(--muted);font-style:italic;margin-top:6px}
.empty-rep{text-align:center;padding:30px;border:1px solid var(--border);background:var(--panel);font-style:italic}
.legend-section{background:var(--panel);border:1px solid var(--border);padding:16px 20px;margin-bottom:14px}
.legend-title{font-size:13px;font-weight:700;margin-bottom:10px;display:flex;align-items:center;gap:8px}
.legend-rows{display:flex;flex-direction:column;gap:7px}
.legend-row{display:flex;align-items:center;gap:12px;font-size:13px}
.legend-swatch{width:13px;height:13px;border-radius:3px;flex-shrink:0;background:var(--sw)}
.legend-range{font-weight:700;min-width:110px}
.legend-name{color:var(--text)}
@page{margin:12mm 0}
@media print{
  html[data-theme="dark"],html[data-theme="dark"] body,body{background:#fff!important;color:#000!important}
  body{padding:6mm 16mm!important}
  body *{color:#000!important;background-color:transparent!important;border-color:#ddd!important}
  .no-print{display:none!important}
  .cum-topbar{background:#f5f5f5!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .rating-num,.avg-score-big,.avg-score-label{color:inherit}
  .legend-swatch{background:var(--sw) !important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .legend-range,.legend-name{color:#000 !important}
  .cat-section{break-inside:auto}
  .cat-title{break-after:avoid}
  .q-table thead{display:table-header-group}
  .q-table tr{break-inside:avoid}
  .q-table td{padding:5px 12px!important}
  .rating-badge{height:26px!important;width:70px!important;border:1px solid #ccc}
  .cum-comments-page{break-before:page;padding-top:14mm}
}
</style>
</head>
<body>
<div class="top-actions no-print">
    <a href="<?= $escape($backHref) ?>" class="back-btn"><i class="fa-solid fa-arrow-left"></i> Back to Evaluation Received</a>
    <button type="button" class="btn-print" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
</div>

<div class="cum-topbar">
    <span class="cum-type">Executive Assistant Evaluation</span>
    <span>Evaluated by: <strong><?= (int)$repCount ?> Evaluator<?= $repCount === 1 ? '' : 's' ?></strong> · <?= $escape($repTermLabel) ?></span>
</div>

<div class="sheet-header">
    <?php if (!empty($eaRow['photo'])): ?><img class="sheet-avatar" src="../image/<?= $escape($eaRow['photo']) ?>" alt=""/>
    <?php else: ?><div class="sheet-avatar-ph"><i class="fa-solid fa-user"></i></div><?php endif; ?>
    <div>
        <div class="sheet-name"><?= $escape($eaRow['full_name']) ?></div>
        <div class="sheet-desig"><?= $escape($eaRow['designation']) ?> · Executive Assistant</div>
    </div>
    <div class="cum-date">
        <div class="cum-date-label">Date Evaluated</div>
        <?php foreach ($repDateLines as $line): ?>
        <div class="cum-date-val"><?= $escape($line) ?></div>
        <?php endforeach; ?>
    </div>
</div>

<?php $repNum = 1; foreach ($repByCat as $cat => $rows): ?>
<div class="cat-section">
    <div class="cat-title"><i class="fa-solid fa-layer-group" style="font-size:10px"></i> <?= $escape($cat) ?></div>
    <table class="q-table">
        <colgroup><col style="width:44px"><col><col style="width:150px"></colgroup>
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

<?php if (!$repByCat): ?><div class="empty-rep">No scored answers found for this selection.</div><?php endif; ?>

<div class="legend-section">
    <div class="legend-title"><i class="fa-solid fa-circle-info"></i> Rating Legend</div>
    <div class="legend-rows">
        <div class="legend-row"><span class="legend-swatch" style="--sw:#32B98A"></span><span class="legend-range">4.50 – 5.00</span><span class="legend-name">Very Satisfied</span></div>
        <div class="legend-row"><span class="legend-swatch" style="--sw:#6BCFA9"></span><span class="legend-range">3.50 – 4.49</span><span class="legend-name">Satisfied</span></div>
        <div class="legend-row"><span class="legend-swatch" style="--sw:#facc15"></span><span class="legend-range">2.50 – 3.49</span><span class="legend-name">Neither Satisfied nor Dissatisfied</span></div>
        <div class="legend-row"><span class="legend-swatch" style="--sw:#fb923c"></span><span class="legend-range">1.50 – 2.49</span><span class="legend-name">Dissatisfied</span></div>
        <div class="legend-row"><span class="legend-swatch" style="--sw:#E6788A"></span><span class="legend-range">1.00 – 1.49</span><span class="legend-name">Very Dissatisfied</span></div>
    </div>
</div>

<?php if ($repOverall !== null): ?>
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
    </div>
</div>
</body>
</html>
<?php
    $mysqli->close();
    exit;
}

function er_score_class($score) {
    if ($score === null) return 'none';
    if ($score >= 4) return 'high';
    if ($score >= 3) return 'mid';
    return 'low';
}
$displayList = array_reverse($history);   // newest first by default
$starsOn = $overallAvg !== null ? (int)round($overallAvg) : 0;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Evaluation Received — PBI</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
    --page:#F8FAFC;--card:#FFFFFF;--border:#D7E3EF;--border-soft:#E8EEF5;
    --text:#0B1F3A;--muted:#647B96;--accent-bg:color-mix(in srgb,var(--accent,#0F9F6E) 9%,#fff);--accent-border:color-mix(in srgb,var(--accent,#0F9F6E) 35%,#fff);
    --blue:#2563EB;--shadow:0 7px 22px rgba(30,82,144,.08);--radius:14px;
}
*{box-sizing:border-box}
html,body{margin:0;background:var(--page);color:var(--text);font-family:Inter,Segoe UI,Arial,sans-serif}
body{min-height:100vh}
.wrap{max-width:1450px;margin:0 auto;padding:34px 38px 46px}
.page-head{display:flex;align-items:flex-end;justify-content:space-between;gap:18px;margin-bottom:18px;flex-wrap:wrap}
.page-head h1{font-size:27px;line-height:1.15;margin:0;font-weight:800;letter-spacing:-.02em}
.page-head p{margin:7px 0 0;font-size:12.5px;color:var(--muted)}
.stats{display:flex;gap:10px;flex-wrap:wrap}
.stat{min-width:150px;background:var(--card);border:1px solid var(--border);border-radius:12px;padding:12px 15px;box-shadow:0 3px 12px rgba(30,82,144,.05)}
.stat-label{font-size:10px;text-transform:uppercase;letter-spacing:.75px;font-weight:800;color:#7389A1}
.stat-value{margin-top:4px;font-size:18px;font-weight:800;color:var(--text)}
.banner{background:var(--accent-bg);border:1px solid var(--accent-border);color:var(--accent);padding:14px 18px;border-radius:12px;font-size:13.5px;font-weight:800;margin:0 0 17px}
.banner i{margin-right:8px}
.results{display:flex;flex-direction:column;gap:12px}
.result-card{background:var(--card);border:1px solid var(--border);border-radius:15px;min-height:108px;padding:16px 21px;display:flex;align-items:center;justify-content:space-between;gap:22px;box-shadow:var(--shadow)}
.result-main{min-width:0}
.anonymous{font-size:15px;font-weight:800;color:#101E35}
.meta{margin-top:6px;color:#58718C;font-size:12px;display:flex;align-items:center;gap:7px;flex-wrap:wrap}
.dot{color:#A7B7C7}
.right{display:flex;align-items:center;gap:12px;flex-shrink:0}
.score-pill{min-width:88px;text-align:center;border:1px solid var(--accent-border);background:color-mix(in srgb,var(--accent,#0F9F6E) 6%,#fff);color:var(--accent);border-radius:21px;padding:9px 13px;font-size:13px;font-weight:800}
.view-btn{border:1px solid var(--accent-border);background:color-mix(in srgb,var(--accent,#0F9F6E) 4%,#fff);color:var(--accent);border-radius:20px;padding:8px 16px;font-size:12px;font-weight:800;cursor:pointer}
.view-btn:hover{background:#FFF6E6}
.empty{background:var(--card);border:1px dashed #C7D5E4;border-radius:15px;padding:62px 24px;text-align:center;color:var(--muted)}
.empty i{font-size:31px;opacity:.4;margin-bottom:12px}
.empty strong{display:block;color:var(--text);font-size:15px;margin-bottom:5px}
.modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.55);display:none;align-items:center;justify-content:center;padding:24px;z-index:1000}
.modal-backdrop.open{display:flex}
.modal{width:min(840px,100%);max-height:min(88vh,900px);overflow:auto;background:#fff;border-radius:18px;box-shadow:0 24px 70px rgba(15,23,42,.35)}
.modal-head{padding:22px 26px 18px;border-bottom:1px solid var(--border-soft);display:flex;align-items:center;justify-content:space-between;gap:16px}
.modal-title{font-size:24px;font-weight:800;letter-spacing:-.02em}
.close{width:34px;height:34px;border-radius:50%;border:1px solid var(--border);background:#fff;color:#60758C;cursor:pointer;display:flex;align-items:center;justify-content:center}
.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;padding:24px 26px 4px}
.detail-box{border:1px solid var(--border);border-radius:12px;padding:13px 16px;background:#fff;box-shadow:0 4px 12px rgba(30,82,144,.05)}
.detail-label{font-size:10px;text-transform:uppercase;letter-spacing:.65px;color:#70849C;margin-bottom:5px}
.detail-value{font-size:14px;font-weight:800;color:var(--text)}
.detail-value.score{color:var(--accent)}
.section{padding:18px 26px 0}
.section-title{font-size:15px;font-weight:800;margin:0 0 12px}
.category-row{display:grid;grid-template-columns:170px 1fr 50px;align-items:center;gap:14px;margin:9px 0}
.category-name{font-size:12.5px;font-weight:700;color:#344B63}
.bar{height:9px;background:#E8EEF5;border-radius:999px;overflow:hidden}.bar > span{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,#C17D19,#F3BA54)}
.category-score{font-size:12px;font-weight:800;color:var(--accent);text-align:right}
.answers{display:flex;flex-direction:column;gap:10px}
.answer{border:1px solid var(--border);border-radius:12px;padding:14px 16px;background:#fff}
.q-label{font-size:10px;text-transform:uppercase;letter-spacing:.65px;color:#70849C;margin-bottom:6px}
.q-text{font-size:13.5px;font-weight:700;line-height:1.35;color:#162A43}
.q-score{margin-top:9px;font-size:12px;font-weight:800;color:#526A82}
.comments{margin:18px 26px 26px;border:1px solid var(--border);border-radius:12px;padding:15px 16px;background:#fff}
.comments .label{font-size:15px;font-weight:800;margin-bottom:10px}
.comments .text{font-size:13px;color:#354C63;line-height:1.55;white-space:pre-wrap}
.loading{text-align:center;padding:50px 20px;color:var(--muted)}
@media(max-width:760px){
  .wrap{padding:22px 16px 32px}.page-head{align-items:flex-start}.stats{width:100%}.stat{flex:1;min-width:130px}
  .result-card{align-items:flex-start;flex-direction:column}.right{width:100%;justify-content:space-between}
  .detail-grid{grid-template-columns:1fr}.category-row{grid-template-columns:1fr 1fr 48px;gap:10px}.category-name{grid-column:1 / -1}
}

/* ── Dean-style layout ── */
.term-picker{display:flex;flex-direction:column;gap:5px;min-width:260px}
.term-picker label{font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);display:flex;align-items:center;gap:6px}
.term-picker select{appearance:none;-webkit-appearance:none;width:100%;padding:10px 38px 10px 14px;border-radius:10px;border:1px solid var(--border);background:var(--card) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' fill='none' stroke='%2367819E' stroke-width='2' stroke-linecap='round'/%3E%3C/svg%3E") no-repeat right 14px center;color:var(--text);font:600 13px Inter,Segoe UI,Arial,sans-serif;cursor:pointer}
.term-picker select:hover{border-color:var(--blue)}
.term-picker select:focus{outline:3px solid rgba(37,99,235,.22);outline-offset:1px;border-color:var(--blue)}
.confidentiality-note{display:flex;align-items:flex-start;gap:14px;padding:16px 20px;background:rgba(16,185,129,.09);border:1px solid rgba(16,185,129,.32);border-radius:12px;margin-bottom:22px}
.confidentiality-note i{color:#10B981;font-size:18px;margin-top:2px}
.confidentiality-note p{margin:0;font-size:12.5px;color:var(--text);line-height:1.6}
.card-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:22px}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:22px 24px;box-shadow:var(--shadow)}
.stat-card i{color:var(--blue);font-size:22px;margin-bottom:12px;display:block}
.stat-card .num{font-size:30px;font-weight:700;color:var(--text)}
.stat-card .label{font-size:12.5px;color:var(--muted);margin-top:4px}
.panel{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:24px;box-shadow:var(--shadow);margin-bottom:22px}
.panel h2{font-size:18px;font-weight:700;margin:0 0 16px;display:flex;align-items:center;gap:9px;color:var(--text)}
.panel h2 i{color:var(--blue);font-size:16px}
.response-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:16px 20px;margin-bottom:12px}
.response-card:last-child{margin-bottom:0}
.response-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;gap:12px}
.response-label{font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;display:flex;align-items:center;gap:7px}
.response-score{font-size:13px;font-weight:700;color:#0F8F66;background:rgba(16,185,129,.13);padding:3px 12px;border-radius:12px}
.response-comment{margin:0;font-size:13.5px;color:var(--text);line-height:1.6;font-style:italic;white-space:pre-wrap}
.empty-note{margin:0;color:var(--muted);font-size:13px;font-style:italic}
.view-evals-btn{width:100%;display:flex;align-items:center;gap:10px;background:var(--accent-bg);border:1px solid var(--accent-border);color:var(--accent);padding:12px 14px;border-radius:10px;font:700 13px Inter,Segoe UI,Arial,sans-serif;cursor:pointer}
.view-evals-btn .caret{margin-left:auto;transition:transform .2s}
.view-evals-btn.open .caret{transform:rotate(180deg)}
.result-card{cursor:pointer}
.result-card:hover{border-color:var(--accent-border)}

/* ── DARK THEME ──
   This page had no dark support at all (unlike admin_analytics.php,
   which already has this). Re-declares the page's own local tokens
   under html[data-theme="dark"] plus the handful of colors that were
   hardcoded instead of using a variable. */
html[data-theme="dark"]{
    --page:#0A192F;--card:#172A45;--border:#24395A;--border-soft:#1E314F;
    --text:#E7ECF3;--muted:#93A5BE;--accent-bg:color-mix(in srgb,var(--accent,#0F9F6E) 18%,#172A45);--accent-border:color-mix(in srgb,var(--accent,#0F9F6E) 35%,#172A45);
    color-scheme:dark;
}
html[data-theme="dark"] .stat-value,html[data-theme="dark"] .detail-value{color:var(--text) !important;}
html[data-theme="dark"] .anonymous{color:#F2F5F9 !important;}
html[data-theme="dark"] .meta,html[data-theme="dark"] .q-score{color:var(--muted) !important;}
html[data-theme="dark"] .dot{color:#54688A !important;}
html[data-theme="dark"] .score-pill,html[data-theme="dark"] .view-btn{background:var(--accent-bg) !important;}
html[data-theme="dark"] .view-btn:hover{background:#332612 !important;}
html[data-theme="dark"] .empty{border-color:#2C4269 !important;}
html[data-theme="dark"] .term-picker select{background-color:var(--card) !important;color:var(--text) !important;}
html[data-theme="dark"] .response-score{color:#6EE7B7;}
html[data-theme="dark"] .stat-card .num,html[data-theme="dark"] .panel h2,html[data-theme="dark"] .response-comment{color:var(--text) !important;}
html[data-theme="dark"] .modal,html[data-theme="dark"] .detail-box,html[data-theme="dark"] .answer,
html[data-theme="dark"] .comments,html[data-theme="dark"] .close{
  background:var(--card) !important;
}
html[data-theme="dark"] .close{color:var(--muted) !important;}
html[data-theme="dark"] .detail-label,html[data-theme="dark"] .q-label{color:var(--muted) !important;}
html[data-theme="dark"] .category-name{color:#B9C6DA !important;}
html[data-theme="dark"] .bar{background:#233A5B !important;}
html[data-theme="dark"] .q-text{color:#EAEFF5 !important;}
html[data-theme="dark"] .comments .text{color:#C7D2E3 !important;}

/* ── Evaluation Received — refreshed layout ── */
.er-stats{grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px;margin-bottom:20px}
.er-stats .stat-card{display:flex;align-items:flex-start;gap:14px;padding:16px 18px}
.er-stat-icon{flex:0 0 40px;width:40px;height:40px;border-radius:12px;background:rgba(37,99,235,.10);display:flex;align-items:center;justify-content:center}
.er-stats .stat-card .er-stat-icon i{margin:0;font-size:17px;display:inline-block}
.er-stat-body{min-width:0}
.er-stats .stat-card .num{font-size:28px;line-height:1.1}
.er-num-unit{font-size:14px;font-weight:600;color:var(--muted);margin-left:4px}
.er-stat-sub{font-size:12px;color:var(--muted);margin-top:6px;line-height:1.5;overflow-wrap:anywhere}
.er-stars{letter-spacing:2px;font-size:14px;margin-right:6px}
.er-stars span{color:#D5DDE6}
.er-stars span.on{color:#F5B301}
.er-trend{font-weight:700}
.er-trend.up{color:#059669}
.er-trend.down{color:#DC2626}
.er-trend.flat{color:var(--muted)}

.er-head{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:14px}
.er-head h2{margin-bottom:0}
.er-count{font:700 12px Inter,Segoe UI,Arial,sans-serif;background:rgba(37,99,235,.10);color:#1D4ED8;padding:2px 10px;border-radius:12px;margin-left:4px}
.er-head .term-picker{flex-direction:row;align-items:center;gap:10px;min-width:0}
.er-head .term-picker select{width:auto;min-width:270px}

.er-toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding:12px 0 14px;border-top:1px solid var(--border-soft)}
.er-controls{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.er-select{padding:7px 12px;border-radius:9px;border:1px solid var(--border);background:var(--card);color:var(--text);font:600 13px Inter,Segoe UI,Arial,sans-serif;cursor:pointer}
.er-select:focus{outline:3px solid rgba(37,99,235,.22);outline-offset:1px;border-color:var(--blue)}
.er-chip{display:inline-flex;align-items:center;gap:7px;padding:7px 12px;border-radius:9px;border:1px solid var(--border);font:600 13px Inter,Segoe UI,Arial,sans-serif;color:var(--text);cursor:pointer;user-select:none}
.er-chip input{accent-color:var(--blue)}
.er-shown{font-size:12px;color:var(--muted)}

.er-item{display:flex;justify-content:space-between;align-items:center;gap:18px;background:var(--page);border:1px solid var(--border);border-radius:12px;padding:14px 18px;margin-bottom:10px;cursor:pointer;transition:border-color .15s,box-shadow .15s}
.er-item:last-child{margin-bottom:0}
.er-item:hover,.er-item:focus-visible{border-color:var(--accent);box-shadow:0 2px 12px color-mix(in srgb,var(--accent,#0F9F6E) 18%,transparent);outline:none}
.er-main{min-width:0;flex:1}
.er-eyebrow{font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);display:flex;align-items:center;gap:6px}
.er-meta{font-size:12px;color:var(--muted);margin-top:3px}
.er-comment{margin:8px 0 0;font-size:13.5px;line-height:1.55;font-style:italic;color:var(--text);display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.er-comment.none{font-style:normal;color:var(--muted)}
.er-side{display:flex;flex-direction:column;align-items:flex-end;gap:8px;flex-shrink:0}
.er-badge{font-size:13px;font-weight:800;padding:4px 12px;border-radius:18px;border:1px solid transparent;white-space:nowrap}
.er-badge small{font-size:11px;font-weight:600;opacity:.75}
.er-badge.high{background:rgba(16,185,129,.12);color:#047857;border-color:rgba(16,185,129,.3)}
.er-badge.mid{background:rgba(245,158,11,.14);color:#B45309;border-color:rgba(245,158,11,.35)}
.er-badge.low{background:rgba(239,68,68,.12);color:#B91C1C;border-color:rgba(239,68,68,.32)}
.er-badge.none{background:rgba(100,123,150,.12);color:var(--muted);border-color:rgba(100,123,150,.25);font-weight:700;font-size:12px}

/* Performance by Category */
.er-cat{display:grid;grid-template-columns:minmax(150px,230px) 1fr 84px;align-items:center;gap:16px;padding:10px 0}
.er-cat-name{font-size:13.5px;font-weight:600;color:var(--text);line-height:1.35}
.er-cat-tag{display:inline-block;margin-left:6px;font-size:10px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;padding:2px 7px;border-radius:10px;vertical-align:middle}
.er-cat-tag.highest{background:rgba(16,185,129,.12);color:#047857}
.er-cat-tag.lowest{background:rgba(245,158,11,.14);color:#B45309}
.er-cat-track{height:10px;background:#E8EEF5;border-radius:8px;overflow:hidden}
.er-cat-fill{height:100%;border-radius:8px}
.er-cat-fill.high{background:linear-gradient(90deg,#059669,#34D399)}
.er-cat-fill.mid{background:linear-gradient(90deg,#D97706,#FBBF24)}
.er-cat-fill.low{background:linear-gradient(90deg,#DC2626,#F87171)}
.er-cat-score{text-align:right;font-size:15px;font-weight:800;color:var(--text);white-space:nowrap}
.er-cat-score small{font-size:11px;font-weight:600;color:var(--muted)}

/* dark theme */
html[data-theme="dark"] .er-stat-icon{background:rgba(96,165,250,.14)}
html[data-theme="dark"] .er-stars span{color:#334867}
html[data-theme="dark"] .er-stars span.on{color:#FBBF24}
html[data-theme="dark"] .er-trend.up{color:#34D399}
html[data-theme="dark"] .er-trend.down{color:#F87171}
html[data-theme="dark"] .er-count{background:rgba(96,165,250,.16);color:#93C5FD}
html[data-theme="dark"] .er-select{background:var(--card) !important;color:var(--text) !important}
html[data-theme="dark"] .er-badge.high,html[data-theme="dark"] .er-cat-tag.highest{color:#6EE7B7}
html[data-theme="dark"] .er-badge.mid,html[data-theme="dark"] .er-cat-tag.lowest{color:#FCD34D}
html[data-theme="dark"] .er-badge.low{color:#FCA5A5}
html[data-theme="dark"] .er-cat-track{background:#233A5B}

@media(max-width:640px){
  .er-item{flex-direction:column;align-items:flex-start;gap:12px}
  .er-side{flex-direction:row;align-items:center;justify-content:space-between;width:100%}
  .er-head .term-picker{flex-direction:column;align-items:stretch;width:100%}
  .er-head .term-picker select{width:100%;min-width:0}
  .er-cat{grid-template-columns:1fr 70px}.er-cat-name{grid-column:1/-1}.er-cat-track{grid-column:1}
}

.er-head-right{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.er-report-btn,.er-report-btn:link,.er-report-btn:visited{display:inline-flex;align-items:center;gap:8px;padding:10px 18px;border-radius:10px;background:var(--blue);color:#fff;font:700 13px Inter,Segoe UI,Arial,sans-serif;text-decoration:none;border:1px solid var(--blue)}
.er-report-btn:hover{opacity:.88}
.er-report-btn i{color:#fff}
@media(max-width:640px){.er-head-right{flex-direction:column;align-items:stretch;width:100%}}

</style>
<link rel="stylesheet" href="admin_appearance.css">
<script src="admin_appearance.js"></script>
<script>
/* This report is often rendered inside the dark admin shell as an iframe.
   Keep this document synchronized with the parent theme before and after
   first paint, including theme changes made while this iframe remains open. */
(function () {
  function getParentTheme() {
    try {
      if (window.parent && window.parent !== window) {
        var t = window.parent.document.documentElement.getAttribute('data-theme');
        if (t === 'dark' || t === 'light') return t;
      }
    } catch (e) {}
    var saved = null;
    try { saved = localStorage.getItem('pbiTheme'); } catch (e) {}
    return saved === 'dark' ? 'dark' : 'light';
  }

  function syncTheme() {
    var theme = getParentTheme();
    document.documentElement.setAttribute('data-theme', theme);
    document.documentElement.style.colorScheme = theme;
  }

  syncTheme();

  try {
    var parentRoot = window.parent && window.parent !== window
      ? window.parent.document.documentElement
      : null;
    if (parentRoot && window.MutationObserver) {
      new MutationObserver(syncTheme).observe(parentRoot, {
        attributes: true,
        attributeFilter: ['data-theme']
      });
    }
  } catch (e) {}

  window.addEventListener('storage', function (e) {
    if (e.key === 'pbiTheme') syncTheme();
  });
})();
</script>
<link rel="stylesheet" href="admin_unified_ui.css?v=<?= (int)@filemtime(__DIR__ . '/admin_unified_ui.css') ?>">
</head>
<body>
<div class="wrap">
    <div class="page-head">
        <div>
            <h1>Evaluation Received</h1>
        </div>
    </div>

    <div class="card-grid er-stats">
        <div class="stat-card">
            <div class="er-stat-icon"><i class="fa-solid fa-star"></i></div>
            <div class="er-stat-body">
                <div class="num"><?= $overallAvg !== null ? $escape($fmt($overallAvg)) : '—' ?><?php if ($overallAvg !== null): ?><span class="er-num-unit">/ 5</span><?php endif; ?></div>
                <div class="label">Average Rating</div>
                <?php if ($overallAvg !== null): ?>
                <div class="er-stat-sub">
                    <span class="er-stars" aria-hidden="true"><?php for ($i = 1; $i <= 5; $i++): ?><span class="<?= $i <= $starsOn ? 'on' : '' ?>">★</span><?php endfor; ?></span>
                    <?php if ($trendText !== ''): ?>
                        <span class="er-trend <?= $escape($trendClass) ?>"><?= $escape($trendText) ?></span> vs <?= $escape($prevTermLabel) ?>
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
                <div class="num"><?= $escape($fmt($topCat['avg'])) ?><span class="er-num-unit">/ 5</span></div>
                <div class="label">Highest-Rated Area</div>
                <div class="er-stat-sub"><?= $escape($topCat['name']) ?></div>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($lowCat): ?>
        <div class="stat-card">
            <div class="er-stat-icon"><i class="fa-solid fa-arrow-trend-down"></i></div>
            <div class="er-stat-body">
                <div class="num"><?= $escape($fmt($lowCat['avg'])) ?><span class="er-num-unit">/ 5</span></div>
                <div class="label">Lowest-Rated Area</div>
                <div class="er-stat-sub"><?= $escape($lowCat['name']) ?></div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="panel">
        <div class="er-head">
            <h2><i class="fa-solid fa-clock-rotate-left"></i> Evaluations Received <span class="er-count"><?= (int)$responseCount ?></span></h2>
            <div class="er-head-right">
            <?php if ($history): ?>
            <a class="er-report-btn" href="?report=1&amp;period=<?= $allPeriodsSelected ? 'all' : (int)$selPeriod ?>"><i class="fa-solid fa-file-lines"></i> Generate Report</a>
            <?php endif; ?>
            <?php if ($terms): ?>
            <form method="get" class="term-picker">
                <label for="termSelect"><i class="fa-solid fa-calendar-days"></i> Evaluation Term</label>
                <select id="termSelect" name="period" onchange="this.form.submit()">
                    <option value="all"<?= $allPeriodsSelected ? ' selected' : '' ?>>All Evaluation Terms — Past &amp; Current</option>
                    <?php foreach ($terms as $t): ?>
                    <option value="<?= (int)$t['id'] ?>"<?= !$allPeriodsSelected && (int)$t['id'] === $selPeriod ? ' selected' : '' ?>><?= $escape($t['label']) ?><?= $t['current'] ? ' (Current)' : ($t['archived'] ? ' (Archived)' : '') ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php endif; ?>
            </div>
        </div>

        <?php if (!$history): ?>
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
                $src  = $h['source'] === 'kept' ? 'kept' : 'live';
                $cls  = er_score_class($h['score']);
                $open = 'openDetails(' . (int)$h['id'] . ",'" . $src . "')";
            ?>
                <div class="er-item" role="button" tabindex="0"
                     data-ts="<?= (int)$h['ts'] ?>" data-idx="<?= (int)$h['idx'] ?>"
                     data-score="<?= $h['score'] !== null ? $escape($h['score']) : '-1' ?>"
                     data-comment="<?= $h['comment_ok'] ? '1' : '0' ?>"
                     onclick="<?= $open ?>"
                     onkeydown="if(event.target===this&&(event.key==='Enter'||event.key===' ')){event.preventDefault();<?= $open ?>}">
                    <div class="er-main">
                        <div class="er-eyebrow"><i class="fa-solid fa-user-secret"></i> Anonymous — <?= $escape($h['label']) ?></div>
                        <div class="er-meta"><?= $escape($h['submitted_at_label']) ?><?= !empty($h['period_label']) ? ' · ' . $escape($h['period_label']) : '' ?></div>
                        <?php if ($h['comment_ok']): ?>
                            <p class="er-comment">&ldquo;<?= $escape($h['comment']) ?>&rdquo;</p>
                        <?php else: ?>
                            <p class="er-comment none">No written comment</p>
                        <?php endif; ?>
                    </div>
                    <div class="er-side">
                        <?php if ($h['score'] !== null): ?>
                            <span class="er-badge <?= $cls ?>"><?= $escape($fmt($h['score'])) ?><small> / 5</small></span>
                        <?php else: ?>
                            <span class="er-badge none">No score</span>
                        <?php endif; ?>
                        <button type="button" class="view-btn" onclick="event.stopPropagation();<?= $open ?>">View Details <i class="fa-solid fa-chevron-right" style="font-size:10px;margin-left:4px"></i></button>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
            <p class="empty-note" id="erNoMatch" style="display:none;">No evaluations match this filter.</p>
        <?php endif; ?>
    </div>
</div>

<script>
function esc(value){
    return String(value ?? '').replace(/[&<>'"]/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[ch]));
}
function scoreWidth(score){
    if(score === null || score === undefined || Number.isNaN(Number(score))) return 0;
    return Math.max(0, Math.min(100, (Number(score) / 5) * 100));
}
function erApply(){
    const list=document.getElementById('erList'); if(!list) return;
    const items=[].slice.call(list.querySelectorAll('.er-item'));
    const mode=document.getElementById('erSort').value;
    const only=document.getElementById('erOnlyComments').checked;
    const n=(el,k)=>parseFloat(el.dataset[k])||0;
    const lowKey=el=>{const v=parseFloat(el.dataset.score);return v<0?Infinity:v;};
    items.sort((a,b)=>{
        if(mode==='oldest') return n(a,'ts')-n(b,'ts')||n(a,'idx')-n(b,'idx');
        if(mode==='high') return parseFloat(b.dataset.score)-parseFloat(a.dataset.score)||n(b,'ts')-n(a,'ts');
        if(mode==='low'){const x=lowKey(a),y=lowKey(b);return x===y?n(b,'ts')-n(a,'ts'):(x<y?-1:1);}
        return n(b,'ts')-n(a,'ts')||n(b,'idx')-n(a,'idx');
    });
    let shown=0;
    items.forEach(el=>{const ok=!only||el.dataset.comment==='1';el.style.display=ok?'':'none';if(ok)shown++;list.appendChild(el);});
    document.getElementById('erShown').textContent='Showing '+shown+' of '+items.length;
    document.getElementById('erNoMatch').style.display=shown?'none':'block';
}

/* ── Evaluation Details popup ─────────────────────────────────────────
   This page usually runs inside an iframe in the admin shell. A modal drawn
   inside the iframe can only dim the iframe's own area, so the popup is built
   in the parent document (same origin) and covers the whole screen —
   sidebar and header included. Falls back to this document when standalone. */
const PEV_HOST = (function(){
    try {
        if (window.parent && window.parent !== window && window.parent.document && window.parent.document.body) return window.parent;
    } catch (e) {}
    return window;
})();
let pevLocked = null;

function pevEnsureStyle(){
    const d = PEV_HOST.document;
    if (d.getElementById('pev-style')) return;
    const st = d.createElement('style');
    st.id = 'pev-style';
    st.textContent = `
.pev-backdrop{--c-card:#fff;--c-border:#D7E3EF;--c-soft:#E8EEF5;--c-text:#0B1F3A;--c-muted:#647B96;--c-label:#70849C;--c-accent:var(--accent,#0F9F6E);--c-track:#E8EEF5;--c-cat:#344B63;--c-q:#162A43;--c-q2:#526A82;--c-body:#354C63;
  position:fixed;inset:0;z-index:2147483000;display:none;align-items:center;justify-content:center;padding:24px;
  background:rgba(3,10,22,.55);-webkit-backdrop-filter:blur(4px);backdrop-filter:blur(4px);
  font-family:Inter,'Segoe UI',Arial,sans-serif;color:var(--c-text);line-height:1.4}
html[data-theme="dark"] .pev-backdrop{--c-card:#172A45;--c-border:#24395A;--c-soft:#1E314F;--c-text:#E7ECF3;--c-muted:#93A5BE;--c-label:#93A5BE;--c-track:#233A5B;--c-cat:#B9C6DA;--c-q:#EAEFF5;--c-q2:#93A5BE;--c-body:#C7D2E3;background:rgba(0,0,0,.65)}
.pev-backdrop,.pev-backdrop *{box-sizing:border-box}
.pev-backdrop.open{display:flex}
.pev-modal{width:min(840px,100%);max-height:min(88vh,900px);overflow:auto;background:var(--c-card);border-radius:18px;box-shadow:0 24px 70px rgba(15,23,42,.35)}
.pev-head{padding:22px 26px 18px;border-bottom:1px solid var(--c-soft);display:flex;align-items:center;justify-content:space-between;gap:16px}
.pev-title{font-size:24px;font-weight:800;letter-spacing:-.02em;margin:0}
.pev-close{width:34px;height:34px;border-radius:50%;border:1px solid var(--c-border);background:var(--c-card);color:var(--c-muted);cursor:pointer;display:flex;align-items:center;justify-content:center;padding:0}
.pev-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;padding:24px 26px 4px}
.pev-box{border:1px solid var(--c-border);border-radius:12px;padding:13px 16px;background:var(--c-card);box-shadow:0 4px 12px rgba(30,82,144,.05)}
.pev-label{font-size:10px;text-transform:uppercase;letter-spacing:.65px;color:var(--c-label);margin-bottom:5px}
.pev-value{font-size:14px;font-weight:800;color:var(--c-text)}
.pev-value.score{color:var(--c-accent)}
.pev-section{padding:18px 26px 0}
.pev-section-title{font-size:15px;font-weight:800;margin:0 0 12px}
.pev-cat{display:grid;grid-template-columns:170px 1fr 50px;align-items:center;gap:14px;margin:9px 0}
.pev-cat-name{font-size:12.5px;font-weight:700;color:var(--c-cat)}
.pev-bar{height:9px;background:var(--c-track);border-radius:999px;overflow:hidden}
.pev-bar>span{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,#C17D19,#F3BA54)}
.pev-cat-score{font-size:12px;font-weight:800;color:var(--c-accent);text-align:right}
.pev-answers{display:flex;flex-direction:column;gap:10px}
.pev-answer{border:1px solid var(--c-border);border-radius:12px;padding:14px 16px;background:var(--c-card)}
.pev-qlabel{font-size:10px;text-transform:uppercase;letter-spacing:.65px;color:var(--c-label);margin-bottom:6px}
.pev-qtext{font-size:13.5px;font-weight:700;line-height:1.35;color:var(--c-q)}
.pev-qscore{margin-top:9px;font-size:12px;font-weight:800;color:var(--c-q2)}
.pev-comments{margin:18px 26px 26px;border:1px solid var(--c-border);border-radius:12px;padding:15px 16px;background:var(--c-card)}
.pev-comments .pev-label{font-size:15px;font-weight:800;margin-bottom:10px;text-transform:none;letter-spacing:0;color:var(--c-text)}
.pev-text{font-size:13px;color:var(--c-body);line-height:1.55;white-space:pre-wrap}
.pev-none{font-style:italic;color:var(--c-muted)}
.pev-loading{text-align:center;padding:50px 20px;color:var(--c-muted);font-size:13.5px}
@media(max-width:760px){.pev-grid{grid-template-columns:1fr}.pev-cat{grid-template-columns:1fr 1fr 48px;gap:10px}.pev-cat-name{grid-column:1/-1}}`;
    d.head.appendChild(st);
}

function closeDetails(){
    const d = PEV_HOST.document;
    const bd = d.getElementById('pev-backdrop');
    if (bd) bd.remove();
    if (pevLocked){
        d.body.style.overflow = pevLocked.host;
        document.body.style.overflow = pevLocked.self;
        pevLocked = null;
    }
}

function openDetails(id, src){
    const d = PEV_HOST.document;
    pevEnsureStyle();
    closeDetails();

    const bd = d.createElement('div');
    bd.id = 'pev-backdrop';
    bd.className = 'pev-backdrop open';
    bd.innerHTML = `
        <div class="pev-modal" role="dialog" aria-modal="true" aria-labelledby="pev-title">
            <div class="pev-head">
                <h2 class="pev-title" id="pev-title">Evaluation Details</h2>
                <button type="button" class="pev-close" aria-label="Close">
                    <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M2 2l10 10M12 2L2 12"/></svg>
                </button>
            </div>
            <div class="pev-content pev-loading">Loading evaluation details…</div>
        </div>`;
    d.body.appendChild(bd);
    bd.addEventListener('click', e => { if (e.target === bd) closeDetails(); });
    bd.querySelector('.pev-close').addEventListener('click', closeDetails);

    pevLocked = { host: d.body.style.overflow, self: document.body.style.overflow };
    d.body.style.overflow = 'hidden';
    document.body.style.overflow = 'hidden';

    const content = bd.querySelector('.pev-content');
    fetch(`ea_results.php?details=${encodeURIComponent(id)}${src === 'kept' ? '&src=kept' : ''}`, {credentials:'same-origin'})
        .then(r => r.ok ? r.json() : Promise.reject(new Error('Unable to load this evaluation.')))
        .then(data => {
            if (data.error) throw new Error(data.error);
            const overall = data.overall_score !== null && data.overall_score !== undefined ? `${parseFloat(Number(data.overall_score).toFixed(2))} / 5` : '—';
            const categories = (data.categories || []).map(row => `
                <div class="pev-cat">
                    <div class="pev-cat-name">${esc(row.category)}</div>
                    <div class="pev-bar"><span style="width:${scoreWidth(row.score)}%"></span></div>
                    <div class="pev-cat-score">${row.score !== null && row.score !== undefined ? Number(row.score).toFixed(2) : '—'}</div>
                </div>`).join('');
            const answers = (data.answers || []).map((row, index) => `
                <div class="pev-answer">
                    <div class="pev-qlabel">Question ${index + 1}</div>
                    <div class="pev-qtext">${esc(row.question)}</div>
                    <div class="pev-qscore">Score: ${row.score !== null && row.score !== undefined ? Number(row.score).toFixed(0) : '—'} / 5</div>
                </div>`).join('');

            content.className = 'pev-content';
            content.innerHTML = `
                <div class="pev-grid">
                    <div class="pev-box"><div class="pev-label">Evaluator</div><div class="pev-value">Anonymous Evaluator</div></div>
                    <div class="pev-box"><div class="pev-label">Period</div><div class="pev-value">${esc(data.period)}</div></div>
                    <div class="pev-box"><div class="pev-label">Submitted</div><div class="pev-value">${esc(data.submitted)}</div></div>
                    <div class="pev-box"><div class="pev-label">Overall Score</div><div class="pev-value score">${overall}</div></div>
                </div>
                <div class="pev-section">
                    <div class="pev-section-title">Performance by Category</div>
                    ${categories || '<div class="pev-text pev-none" style="font-style:normal">No category data available.</div>'}
                </div>
                <div class="pev-section">
                    <div class="pev-section-title">Question-by-Question Results</div>
                    <div class="pev-answers">${answers || '<div class="pev-text pev-none" style="font-style:normal">No question responses available.</div>'}</div>
                </div>
                <div class="pev-comments">
                    <div class="pev-label">Comments / Feedback</div>
                    <div class="pev-text">${data.remarks ? esc(data.remarks) : '<span class="pev-none">No written feedback was provided.</span>'}</div>
                </div>`;
        })
        .catch(err => {
            content.className = 'pev-content pev-loading';
            content.innerHTML = `<span style="color:#B42318">${esc(err.message || 'Unable to load this evaluation.')}</span>`;
        });
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeDetails(); });
if (PEV_HOST !== window) PEV_HOST.document.addEventListener('keydown', e => { if (e.key === 'Escape') closeDetails(); });
// If this iframe navigates away, don't leave the popup behind in the parent page.
window.addEventListener('pagehide', closeDetails);
</script>
</body>
</html>
<?php $mysqli->close(); ?>