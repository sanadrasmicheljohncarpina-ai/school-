<?php
// principal/principal_evaluations.php
// Principal "Evaluate Others" — same flow as the Dean portal's Evaluate Others:
//   1. Category cards (Faculty / Staff / Executive Assistant) with "x/y done" progress
//   2. Person cards for the selected category (Evaluate / View / Evaluation closed)
//   3. Rating modal loaded over AJAX (questions grouped by category, 5..1 scale,
//      live "n / N answered" counter, comment box, Submit Evaluation)
//
// Everything lives in this one file:
//   GET  ?get_questions=1&target_id=ID   -> JSON question set for the modal
//   GET  ?get_submission=1&target_id=ID  -> JSON of the Principal's own submitted answers (View)
//   POST submit_evaluation               -> validated, transactional save, then redirect
//
// Scope rules (unchanged from the previous Principal page):
//   Faculty -> teaching personnel assigned to Grade 7-12 (user_year_levels)  [shared Faculty bank]
//   Staff   -> non-teaching staff                                           [per-person questions]
//   EA      -> the single active Executive Assistant                        [per-person questions]
//
// Storage (unchanged, so Reports / Tracker / Results keep working):
//   evaluation_tracker  eval_type='school_head', eval_bucket='Faculty'|'Staff'|'EA', level='basic_education'
//   questionnaire_answers  one row per question
//
// principal_evaluate.php is now only a redirect into this page so old links keep working.

require_once 'principal_common.php';
require_once dirname(__DIR__) . '/shared/QuestionnaireService.php';
qn_migrate_legacy_once($mysqli);

$settings      = $schoolHeadSettings;
$period_id_int = (int)($settings['period_id'] ?? 0);
$hasPeriod     = $period_id_int > 0;
$evalOpen      = !empty($settings['school_head_is_open']);
$evaluator_id  = (int)$_SESSION['user_id'];
$user_id       = $evaluator_id;

$isAjax = isset($_GET['get_questions']) || isset($_GET['get_submission']);

function principal_json(array $payload, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// One CSRF token per session (the old evaluate page rotated it on every load,
// which broke a second open tab).
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$validTabs = ['faculty', 'staff', 'executive_assistant'];
$tab = $_GET['tab'] ?? '';
if (!in_array($tab, $validTabs, true)) $tab = '';   // '' = default view (no group selected)

$catMeta = [
    'faculty'             => ['label' => 'Faculty',             'icon' => 'fa-users',     'cls' => 'faculty', 'noun' => 'faculty'],
    'staff'               => ['label' => 'Staff',               'icon' => 'fa-briefcase', 'cls' => 'staff',   'noun' => 'staff'],
    'executive_assistant' => ['label' => 'Executive Assistant', 'icon' => 'fa-user-tie',  'cls' => 'ea',      'noun' => 'executive assistant'],
];

/* ───────────────────────── helpers ───────────────────────── */

function principal_roster_levels(mysqli $mysqli, int $userId): array {
    // Level VALUES come from user_year_levels ONLY. teaching_assignments keeps a
    // stale row on every reassignment, so it is only trusted as an existence signal.
    $rows = safe_rows($mysqli, "SELECT year_level FROM user_year_levels WHERE user_id=?", 'i', [$userId]);
    $levels = [];
    foreach ($rows as $r) {
        $v = trim((string)($r['year_level'] ?? ''));
        if ($v !== '') $levels[] = $v;
    }
    return array_values(array_unique($levels));
}

function principal_roster_is_high(string $level): bool {
    return (bool)preg_match('/^Grade\s*(7|8|9|10|11|12)\b/i', trim($level));
}

function principal_has_any_assignment(mysqli $mysqli, int $userId): bool {
    $rows = safe_rows(
        $mysqli,
        "SELECT 1 FROM teaching_assignments WHERE user_id=? UNION ALL SELECT 1 FROM user_year_levels WHERE user_id=? LIMIT 1",
        'ii',
        [$userId, $userId]
    );
    return !empty($rows);
}

function principal_roster_has_teaching(mysqli $mysqli, array $u): bool {
    if (($u['role'] ?? '') === 'teacher') return true;
    if (($u['secondary_role'] ?? '') === 'teacher') return true;
    if (($u['sector'] ?? '') === 'Teacher') return true;
    $uid = (int)($u['id'] ?? 0);
    return $uid > 0 && principal_has_any_assignment($mysqli, $uid);
}

/**
 * The ONE place that decides which bucket a teacher/staff user belongs to.
 * Used by the roster AND by the AJAX/submit re-checks, so they can never disagree.
 *   - Non-teaching Staff                              -> Staff
 *   - Teaching personnel with a Grade 7-12 level      -> Faculty
 *   - anyone else                                     -> not evaluable by the Principal
 */
function principal_resolve_bucket(mysqli $mysqli, array $u): ?array {
    $uid = (int)($u['id'] ?? 0);
    if ($uid <= 0) return null;

    if (($u['role'] ?? '') === 'staff' && !principal_has_any_assignment($mysqli, $uid)) {
        return ['bucket' => 'Staff', 'role_label' => 'Staff', 'route_tab' => 'staff'];
    }
    if (principal_roster_has_teaching($mysqli, $u)
        && count(array_filter(principal_roster_levels($mysqli, $uid), 'principal_roster_is_high')) > 0) {
        return [
            'bucket'     => 'Faculty',
            'role_label' => (($u['role'] ?? '') === 'staff') ? 'Teaching Staff' : 'Faculty',
            'route_tab'  => 'faculty',
        ];
    }
    return null;
}

/** The single active Executive Assistant. */
function principal_current_ea(mysqli $mysqli): ?array {
    $rows = safe_rows($mysqli, "SELECT id, full_name, designation, photo, role, department FROM users WHERE role='superadmin' AND is_active=1 AND account_status='approved' ORDER BY updated_at DESC, id DESC LIMIT 1");
    return $rows[0] ?? null;
}

/** Server-side eligibility: returns the target row + bucket info, or null. */
function principal_resolve_target(mysqli $mysqli, int $targetId): ?array {
    $rows = safe_rows(
        $mysqli,
        "SELECT id, full_name, designation, photo, department, role, secondary_role, sector FROM users WHERE id=? AND is_active=1 AND account_status='approved' LIMIT 1",
        'i',
        [$targetId]
    );
    $u = $rows[0] ?? null;
    if (!$u) return null;

    $role = strtolower((string)$u['role']);
    if ($role === 'superadmin') {
        $ea = principal_current_ea($mysqli);
        if (!$ea || (int)$ea['id'] !== $targetId) return null;
        return ['user' => $u, 'bucket' => 'EA', 'role_label' => 'Executive Assistant', 'route_tab' => 'executive_assistant'];
    }
    if ($role !== 'teacher' && $role !== 'staff') return null;

    $info = principal_resolve_bucket($mysqli, $u);
    if ($info === null) return null;
    return ['user' => $u] + $info;
}

function principal_load_questions(mysqli $mysqli, int $targetId, string $bucket): array {
    static $facultyCache = null;
    try {
        if ($bucket === 'Faculty') {
            if ($facultyCache === null) $facultyCache = qn_get_faculty_questions($mysqli) ?: [];
            $rows = $facultyCache;
            $source = 'evaluation';
        } elseif ($bucket === 'Staff') {
            $rows = qn_get_person_questions($mysqli, $targetId, 'Staff') ?: [];
            $source = 'user';
        } else {
            $rows = qn_get_person_questions($mysqli, $targetId, 'EA') ?: [];
            $source = 'user';
        }
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $q) {
        $cat = trim((string)($q['category'] ?? ''));
        $out[] = [
            'key'      => $source . ':' . (int)$q['id'],
            'id'       => (int)$q['id'],
            'source'   => $source,
            'category' => $cat !== '' ? $cat : 'General',
            'question' => (string)($q['question_text'] ?? ''),
        ];
    }
    return $out;
}

function principal_find_submission(mysqli $mysqli, int $evaluatorId, int $targetId, int $periodId): ?array {
    if ($periodId <= 0) return null;
    $rows = safe_rows($mysqli, "
        SELECT id, score, remarks AS comment, submitted_at
        FROM evaluation_tracker
        WHERE eval_type='school_head'
          AND evaluator_id=? AND target_user_id=? AND period_id=?
          AND status IN ('submitted','approved')
        ORDER BY submitted_at DESC, id DESC
        LIMIT 1
    ", 'iii', [$evaluatorId, $targetId, $periodId]);
    return $rows[0] ?? null;
}

function principal_existing_answers(mysqli $mysqli, int $trackerId): array {
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
    ", 'i', [$trackerId]);
}

/* ───────────────────────── AJAX: question set / submitted answers ───────────────────────── */

if ($isAjax) {
    if (!$structureActive) {
        principal_json(['success' => false, 'error' => BASIC_ED_LABEL . ' is not the active academic structure right now.'], 403);
    }
    $targetId = (int)($_GET['target_id'] ?? 0);
    $t = $targetId > 0 ? principal_resolve_target($mysqli, $targetId) : null;
    if (!$t) {
        principal_json(['success' => false, 'error' => 'This person is not available for evaluation.'], 403);
    }
    $existing = principal_find_submission($mysqli, $evaluator_id, $targetId, $period_id_int);

    if (isset($_GET['get_submission'])) {
        if (!$existing) {
            principal_json(['success' => false, 'error' => 'You have not evaluated this person in the current period.'], 404);
        }
        $answers = [];
        foreach (principal_existing_answers($mysqli, (int)$existing['id']) as $a) {
            $answers[] = [
                'category' => (string)$a['category'],
                'question' => (string)$a['question'],
                'score'    => (float)$a['score'],
            ];
        }
        principal_json([
            'success'      => true,
            'score'        => $existing['score'] !== null ? round((float)$existing['score'], 2) : null,
            'submitted_at' => $existing['submitted_at'] ? date('M j, Y g:i A', strtotime($existing['submitted_at'])) : '',
            'comment'      => (string)($existing['comment'] ?? ''),
            'answers'      => $answers,
        ]);
    }

    // get_questions
    if ($existing) {
        principal_json(['success' => false, 'error' => 'You have already evaluated this person this period.'], 409);
    }
    if (!$hasPeriod) {
        principal_json(['success' => false, 'error' => 'No evaluation period is currently open. Please check back later.'], 403);
    }
    if (!principal_schedule_is_open($settings) || !$evalOpen) {
        principal_json(['success' => false, 'error' => 'Evaluation is currently closed for this period.'], 403);
    }
    $questions = principal_load_questions($mysqli, $targetId, $t['bucket']);
    if (empty($questions)) {
        principal_json(['success' => false, 'error' => 'No questions have been set up for this person yet. Please contact your administrator.'], 404);
    }
    principal_json(['success' => true, 'questions' => $questions]);
}

/* ───────────────────────── POST: submit evaluation ───────────────────────── */

class PrincipalEvalException extends RuntimeException {}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_evaluation'])) {
    $postTab = $_POST['tab'] ?? 'faculty';
    if (!in_array($postTab, $validTabs, true)) $postTab = 'faculty';
    $redirectTab = $postTab;
    $submitError = '';
    $submitOk = '';

    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
        $submitError = 'Session expired. Please refresh and try again.';
    } elseif (!$structureActive) {
        $submitError = BASIC_ED_LABEL . ' is not the active academic structure right now.';
    } elseif (!$hasPeriod) {
        $submitError = 'No evaluation period is currently open. Please check back later.';
    } elseif (!principal_schedule_is_open($settings) || !$evalOpen) {
        // Fresh server-side boundary check: a modal left open in a browser must
        // not become submittable before the configured Manila opening instant.
        $submitError = 'Evaluation is currently closed for this period.';
    } else {
        $targetId = (int)($_POST['target_user_id'] ?? 0);
        // SERVER-SIDE ELIGIBILITY RE-CHECK — the modal only offers eligible people,
        // but nothing else stops a hand-crafted POST with any target_user_id.
        $t = $targetId > 0 ? principal_resolve_target($mysqli, $targetId) : null;
        if (!$t) {
            $submitError = 'This person is not available for evaluation.';
        } else {
            $redirectTab = $t['route_tab'];
            $questions = principal_load_questions($mysqli, $targetId, $t['bucket']);
            $expectedKeys = array_column($questions, 'key');
            $ratings = $_POST['rating'] ?? [];
            if (!is_array($ratings)) $ratings = [];
            $submittedKeys = array_map('strval', array_keys($ratings));
            $missing    = array_values(array_diff($expectedKeys, $submittedKeys));
            $unexpected = array_values(array_diff($submittedKeys, $expectedKeys));

            if (principal_find_submission($mysqli, $evaluator_id, $targetId, $period_id_int)) {
                $submitError = 'You have already evaluated this person this period.';
            } elseif (empty($expectedKeys)) {
                $submitError = 'No evaluation questions are configured for this person yet.';
            } elseif ($unexpected) {
                $submitError = 'Invalid evaluation question data. Please close and try again.';
            } elseif ($missing) {
                $n = count($missing);
                $submitError = 'Please answer all questions before submitting. You have ' . $n . ' unanswered question' . ($n === 1 ? '' : 's') . ' remaining.';
            } elseif (trim((string)($_POST['comment'] ?? '')) === '') {
                $submitError = 'Please write a comment before submitting. Comments, suggestions and areas for improvement are required.';
            } else {
                $answers = [];
                $scoreSum = 0;
                foreach ($questions as $q) {
                    $raw = $ratings[$q['key']] ?? '';
                    if (!is_string($raw) || !preg_match('/^[1-5]$/', $raw)) {
                        $submitError = 'Every rating must be a whole number from 1 to 5.';
                        break;
                    }
                    $score = (int)$raw;
                    $answers[] = ['id' => $q['id'], 'source' => $q['source'], 'score' => $score];
                    $scoreSum += $score;
                }

                if ($submitError === '') {
                    $comment   = trim((string)($_POST['comment'] ?? ''));
                    $avgScore  = round($scoreSum / count($answers), 2);
                    $level     = 'basic_education';
                    $bucket    = $t['bucket'];
                    $evalType  = 'school_head';
                    $formType  = $bucket === 'Faculty'
                        ? 'Principal Evaluation — Faculty'
                        : ($bucket === 'Staff' ? 'Principal Evaluation — Non-Teaching Staff' : 'Principal Evaluation — Executive Assistant');

                    try {
                        $mysqli->begin_transaction();

                        // Re-check inside the transaction and lock, so two simultaneous
                        // submits cannot both pass the duplicate check above.
                        $chk = $mysqli->prepare("SELECT id FROM evaluation_tracker WHERE eval_type='school_head' AND evaluator_id=? AND target_user_id=? AND period_id=? AND status IN ('submitted','approved') LIMIT 1 FOR UPDATE");
                        $chk->bind_param('iii', $evaluator_id, $targetId, $period_id_int);
                        $chk->execute();
                        $dup = $chk->get_result()->fetch_row();
                        $chk->close();
                        if ($dup) throw new PrincipalEvalException('You have already evaluated this person this period.');

                        $ins = $mysqli->prepare("
                            INSERT INTO evaluation_tracker
                                (evaluator_id, target_user_id, eval_bucket, level, form_type,
                                 form_id, period_id, score, remarks, eval_type, status, submitted_at)
                            VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, 'submitted', NOW())
                        ");
                        $ins->bind_param('iisssidss', $evaluator_id, $targetId, $bucket, $level, $formType, $period_id_int, $avgScore, $comment, $evalType);
                        if (!$ins->execute()) throw new PrincipalEvalException('Failed to save the evaluation.');
                        $trackerId = (int)$mysqli->insert_id;
                        $ins->close();

                        $ains = $mysqli->prepare("
                            INSERT INTO questionnaire_answers
                                (tracker_id, question_id, user_question_id, question_source,
                                 answer_text, answer_score, submitted_at)
                            VALUES (?, ?, ?, ?, ?, ?, NOW())
                        ");
                        foreach ($answers as $a) {
                            $questionId     = $a['source'] === 'evaluation' ? $a['id'] : null;
                            $userQuestionId = $a['source'] === 'user' ? $a['id'] : null;
                            $source         = $a['source'];
                            $answerText     = null;
                            $score          = (float)$a['score'];
                            $ains->bind_param('iiissd', $trackerId, $questionId, $userQuestionId, $source, $answerText, $score);
                            if (!$ains->execute()) throw new PrincipalEvalException('Failed to save a questionnaire answer.');
                        }
                        $ains->close();

                        $mysqli->commit();
                        $submitOk = 'Evaluation submitted for ' . $t['user']['full_name'] . '.';
                    } catch (PrincipalEvalException $e) {
                        $mysqli->rollback();
                        $submitError = $e->getMessage();
                    } catch (Throwable $e) {
                        $mysqli->rollback();
                        $submitError = 'Could not save this evaluation. Nothing was recorded — please try again.';
                    }
                }
            }
        }
    }

    if ($submitError !== '') $_SESSION['toast_error'] = $submitError;
    else                     $_SESSION['toast'] = $submitOk;
    $mysqli->close();
    header('Location: principal_evaluations.php?tab=' . urlencode($redirectTab));
    exit;
}

$toast       = $_SESSION['toast']       ?? ''; unset($_SESSION['toast']);
$toast_error = $_SESSION['toast_error'] ?? ''; unset($_SESSION['toast_error']);

/* ───────────────────────── roster ───────────────────────── */

$rosterByTab  = ['faculty' => [], 'staff' => [], 'executive_assistant' => []];
$doneByTarget = [];

if ($structureActive) {
    $ures = $mysqli->query("SELECT id, full_name, designation, photo, role, secondary_role, sector, department FROM users WHERE role IN ('teacher','staff') AND is_active=1 AND account_status='approved' ORDER BY full_name ASC");
    if ($ures) {
        while ($u = $ures->fetch_assoc()) {
            $info = principal_resolve_bucket($mysqli, $u);
            if ($info === null) continue;
            $u['role_label']  = $info['role_label'];
            $u['eval_bucket'] = $info['bucket'];
            $rosterByTab[$info['route_tab']][] = $u;
        }
    }

    if ($ea = principal_current_ea($mysqli)) {
        $ea['role_label']  = 'Executive Assistant';
        $ea['eval_bucket'] = 'EA';
        $rosterByTab['executive_assistant'][] = $ea;
    }

    // Question availability uses the SAME loader as the modal and the submit
    // handler, so the Evaluate button can never promise a form that then fails.
    foreach ($rosterByTab as $k => &$list) {
        foreach ($list as &$p) {
            $p['question_count'] = count(principal_load_questions($mysqli, (int)$p['id'], $p['eval_bucket']));
            $p['has_questions']  = $p['question_count'] > 0;
        }
        unset($p);
    }
    unset($list);

    // Completed status is keyed to this Principal + the current active period.
    if ($hasPeriod) {
        $stmt = $mysqli->prepare("SELECT target_user_id, submitted_at FROM evaluation_tracker WHERE evaluator_id=? AND eval_type='school_head' AND period_id=? AND status IN ('submitted','approved') ORDER BY submitted_at DESC, id DESC");
        if ($stmt) {
            $stmt->bind_param('ii', $evaluator_id, $period_id_int);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $uid = (int)$r['target_user_id'];
                if (!isset($doneByTarget[$uid])) $doneByTarget[$uid] = $r['submitted_at'];
            }
            $stmt->close();
        }
    }
}

foreach ($rosterByTab as $k => &$list) {
    foreach ($list as &$r) {
        $uid = (int)$r['id'];
        $r['evaluation_status']    = isset($doneByTarget[$uid]) ? 'completed' : 'not_started';
        $r['last_evaluation_date'] = $doneByTarget[$uid] ?? null;
    }
    unset($r);
    usort($list, fn($a, $b) => strcasecmp($a['full_name'], $b['full_name']));
}
unset($list);

$openId      = (int)($_GET['open'] ?? 0);
$openView    = isset($_GET['view']);
$periodLabel = trim(($settings['academic_year'] ?? '') . ' — ' . ($settings['academic_term'] ?? ''), ' —');

$mysqli->close();

function principal_person_photo(?string $photo): string {
    return !empty($photo) ? '../image/' . $photo : '../image/pbi_logo';
}

html_head_open('PBI — Evaluate Others');
?>
<style id="principal-evaluate-others">
/* Evaluate Others: category cards → person cards → rating modal (same flow as the Dean portal, Principal amber accent) */
.main{--de-accent:#D99A2B;--de-accent-dark:#B45309;--de-accent-soft:#FFF7ED;--de-accent-line:#FED7AA;--de-border:#D9E4EF;--de-ink:#172033;--de-muted:#64748B;--de-inner:#F5F7FB;}
.de-flash{display:flex;align-items:center;gap:10px;padding:12px 16px;margin-bottom:18px;border-radius:12px;font-size:13px;font-weight:600;}
.de-flash.success{background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.35);color:#0f9d6e;}
.de-flash.error{background:rgba(240,84,84,.1);border:1px solid rgba(240,84,84,.35);color:#d64545;}
.de-choose-title{display:flex;align-items:center;gap:10px;margin:0 0 16px;font-family:'Rajdhani',sans-serif;font-size:24px;font-weight:700;line-height:1.1;color:#0f172a;}
.de-choose-title i{font-size:18px;color:#d97706;}
@media(max-width:600px){.de-choose-title{font-size:21px;margin-bottom:12px;}.de-choose-title i{font-size:16px;}}
.de-section-label{font-size:11px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--de-muted);margin:6px 0 12px;}

.de-cats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-bottom:22px;}
.de-cat{position:relative;text-align:center;cursor:pointer;font-family:inherit;color:var(--de-ink);background:#fff;border:1px solid var(--de-border);border-bottom-width:3px;border-radius:14px;padding:20px 14px 16px;transition:transform .15s,box-shadow .15s,border-color .15s;}
.de-cat:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(28,64,92,.12);}
.de-cat:focus-visible{outline:2px solid var(--de-accent);outline-offset:3px;}
.de-cat .de-cat-ico{width:62px;height:62px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;font-size:24px;}
.de-cat .de-cat-name{font-family:'Rajdhani',sans-serif;font-size:20px;font-weight:700;}
.de-cat .de-cat-count{font-size:14.5px;color:var(--de-muted);margin-top:2px;}
.de-cat .de-cat-done{display:inline-flex;align-items:center;gap:5px;margin-top:10px;padding:3px 11px;border-radius:20px;font-size:12px;font-weight:700;}
.de-cat.faculty{border-bottom-color:#38bdf8;} .de-cat.faculty .de-cat-ico{background:rgba(56,189,248,.16);color:#0284c7;} .de-cat.faculty .de-cat-done{background:rgba(56,189,248,.14);color:#0284c7;}
.de-cat.staff{border-bottom-color:#10b981;}   .de-cat.staff .de-cat-ico{background:rgba(16,185,129,.16);color:#059669;}   .de-cat.staff .de-cat-done{background:rgba(16,185,129,.14);color:#059669;}
.de-cat.ea{border-bottom-color:#7C5FD9;}      .de-cat.ea .de-cat-ico{background:rgba(124,95,217,.16);color:#7C5FD9;}      .de-cat.ea .de-cat-done{background:rgba(124,95,217,.14);color:#7C5FD9;}
.de-cat.active{box-shadow:0 8px 24px rgba(28,64,92,.16);}
.de-cat.active::after{content:"";position:absolute;left:50%;bottom:-11px;transform:translateX(-50%);border:8px solid transparent;border-top-color:var(--de-accent);border-bottom:0;}
.de-cat.faculty.active{border-color:#38bdf8;} .de-cat.faculty.active::after{border-top-color:#38bdf8;}
.de-cat.staff.active{border-color:#10b981;}   .de-cat.staff.active::after{border-top-color:#10b981;}
.de-cat.ea.active{border-color:#7C5FD9;}      .de-cat.ea.active::after{border-top-color:#7C5FD9;}

.de-panel{display:none;background:#fff;border:1px solid var(--de-border);border-radius:16px;box-shadow:0 4px 16px rgba(28,64,92,.09);overflow:hidden;margin-top:6px;}
.de-panel.active{display:block;}
.de-panel-head{display:flex;align-items:center;gap:10px;padding:11px 18px;border-bottom:1px solid var(--de-border);flex-wrap:wrap;}
.de-panel-ico{width:30px;height:30px;font-size:13px;border-radius:8px;display:flex;align-items:center;justify-content:center;background:rgba(217,154,43,.16);color:var(--de-accent-dark);}
.de-panel-title{font-family:'Rajdhani',sans-serif;font-size:17px;font-weight:700;color:var(--de-ink);}
.de-panel-tools{margin-left:auto;display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.de-panel-tools select{background:var(--de-inner);border:1px solid var(--de-border);color:var(--de-ink);padding:6px 9px;border-radius:8px;font-size:12px;font-family:inherit;min-width:160px;}

.de-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;padding:14px 18px;}
.de-person{display:flex;align-items:center;gap:11px;padding:9px 13px;border:1px solid var(--de-border);border-radius:12px;background:var(--de-inner);}
.de-person:hover{border-color:var(--de-accent);}
.de-avatar{width:38px;height:38px;border-radius:50%;object-fit:cover;flex-shrink:0;background:#fff;border:2px solid var(--de-border);}
.de-person-info{flex:1;min-width:0;}
.de-person-name{font-size:13.5px;font-weight:700;color:var(--de-ink);}
.de-person-desig{font-size:11.5px;color:var(--de-muted);margin:0 0 4px;overflow-wrap:anywhere;}
.de-pill{display:inline-flex;align-items:center;gap:5px;padding:1px 8px;border-radius:20px;font-size:10.5px;font-weight:700;}
.de-pill.pending{background:rgba(245,158,11,.14);color:#b7791f;}
.de-pill.done{background:rgba(16,185,129,.14);color:#0f9d6e;}
.de-pill-date{font-size:11px;color:var(--de-muted);margin-left:6px;}
.de-btn{display:inline-flex;align-items:center;gap:6px;padding:6px 13px;border-radius:7px;font-size:12px;font-weight:600;font-family:inherit;cursor:pointer;white-space:nowrap;border:1px solid transparent;}
.de-btn-eval{background:var(--de-accent);color:#fff;border-color:var(--de-accent);}
.de-btn-eval:hover{background:var(--de-accent-dark);border-color:var(--de-accent-dark);}
.de-btn-view{background:transparent;color:var(--de-ink);border-color:#CBD5E1;}
.de-btn-view:hover{border-color:var(--de-accent);color:var(--de-accent-dark);}
.de-btn[disabled]{opacity:.5;cursor:not-allowed;}
.de-muted{color:var(--de-muted);font-size:12.5px;}
.de-empty{grid-column:1/-1;text-align:center;padding:44px 20px;color:var(--de-muted);}
.de-empty i{font-size:34px;display:block;margin-bottom:12px;opacity:.3;}
.de-panel-foot{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:9px 18px;border-top:1px solid var(--de-border);font-size:12px;color:var(--de-muted);}
.de-panel-foot b{color:var(--de-accent-dark);}

/* modal */
.de-overlay{position:fixed;inset:0;z-index:2000;background:rgba(10,25,47,.55);-webkit-backdrop-filter:blur(6px);backdrop-filter:blur(6px);display:none;align-items:center;justify-content:center;padding:10px 20px;overflow-y:auto;}
.de-overlay.open{display:flex;animation:deFade .18s ease-out;}
@keyframes deFade{from{opacity:0}to{opacity:1}}
@keyframes deRise{from{opacity:0;transform:translateY(10px) scale(.98)}to{opacity:1;transform:none}}
.de-overlay.open .de-modal{animation:deRise .22s ease-out;}
.de-modal{--de-accent:#D99A2B;--de-accent-dark:#B45309;--de-border:#D9E4EF;--de-ink:#172033;--de-muted:#64748B;--de-inner:#F5F7FB;width:100%;max-width:780px;height:96vh;max-height:calc(100vh - 20px);background:#fff;color:var(--de-ink);border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.35);display:flex;flex-direction:column;}
.de-modal-head{display:flex;align-items:center;gap:16px;padding:20px 28px;border-bottom:1px solid var(--de-border);}
.de-modal-head img{width:52px;height:52px;border-radius:50%;object-fit:cover;border:2px solid var(--de-border);}
.de-modal-name{font-family:'Rajdhani',sans-serif;font-size:22px;font-weight:700;}
.de-modal-sub{font-size:13px;color:var(--de-muted);}
.de-modal-x{margin-left:auto;background:none;border:none;font-size:20px;color:var(--de-muted);cursor:pointer;padding:6px 10px;}
.de-modal-x:hover{color:var(--de-ink);}
.de-modal-body{padding:24px 28px 18px;overflow-y:auto;flex:1;min-height:120px;}
.de-modal-foot{display:flex;gap:12px;padding:20px 28px;border-top:1px solid var(--de-border);}
.de-modal-foot .de-btn{justify-content:center;padding:13px 20px;font-size:15px;}
.de-modal-foot .de-btn-cancel{background:var(--de-inner);color:var(--de-ink);border-color:#CBD5E1;}
.de-modal-foot .de-btn{border-radius:12px;}
.de-modal-foot .de-btn-submit{flex:1;background:var(--de-accent);color:#fff;border-color:var(--de-accent);box-shadow:0 8px 20px rgba(217,154,43,.30);}
.de-modal-foot .de-btn-submit:hover{background:var(--de-accent-dark);border-color:var(--de-accent-dark);}
.de-loading,.de-error{text-align:center;padding:36px 10px;color:var(--de-muted);font-size:14px;}
.de-error{color:#d64545;}

.de-progress{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:11px 16px;margin-bottom:14px;border-radius:10px;font-size:13px;font-weight:600;background:#FFF7ED;border:1px solid #FED7AA;}
.de-progress .de-progress-status{color:var(--de-accent-dark);font-weight:700;}
.de-validation{padding:11px 16px;margin-bottom:14px;border-radius:10px;font-size:13px;background:rgba(240,84,84,.1);border:1px solid rgba(240,84,84,.35);color:#c53030;}
.de-validation[hidden]{display:none;}
.de-legend{display:flex;gap:16px;flex-wrap:wrap;padding:10px 14px;margin-bottom:16px;border-radius:10px;background:var(--de-inner);border:1px solid var(--de-border);font-size:12.5px;}
.de-legend-item{display:flex;align-items:center;gap:7px;}
.de-legend-dot{width:22px;height:22px;border-radius:6px;background:var(--de-accent);color:#fff;font-weight:700;font-size:12px;display:flex;align-items:center;justify-content:center;}
.de-cat-title{display:flex;align-items:center;gap:9px;margin:18px 0 8px;font-size:12px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--de-accent-dark);}
.de-cat-title::before{content:"";width:9px;height:9px;border-radius:50%;background:var(--de-accent);flex:0 0 auto;}
.de-table-wrap{border:1px solid var(--de-border);border-radius:12px;overflow-x:auto;}
.de-table{width:100%;border-collapse:collapse;}
.de-table th{background:#F4F8FF;color:var(--de-muted);font-size:11px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;text-align:center;padding:11px 8px;border-bottom:1px solid var(--de-border);}
.de-table th:first-child{text-align:left;padding-left:16px;}
.de-table th:not(:first-child){width:58px;}
.de-table col.c-rate{width:58px;}
.de-table td{padding:12px 8px;border-bottom:1px solid #E8EEF5;text-align:center;vertical-align:middle;}
.de-table td:first-child{text-align:left;padding-left:16px;}
.de-table tr:last-child td{border-bottom:none;}
.de-qtext{font-size:14px;line-height:1.45;}
.de-qno{color:var(--de-accent-dark);font-weight:700;margin-right:6px;}
.de-rate{position:relative;display:inline-block;}
.de-rate input{position:absolute;opacity:0;pointer-events:none;}
.de-rate label{display:inline-flex;align-items:center;justify-content:center;width:40px;height:36px;border-radius:9px;border:1px solid #CBD5E1;background:var(--de-inner);color:var(--de-muted);font-size:13px;font-weight:600;cursor:pointer;transition:all .12s;}
.de-rate label:hover{border-color:var(--de-accent);color:var(--de-accent-dark);}
.de-rate input:checked + label{background:var(--de-accent);border-color:var(--de-accent);color:#fff;box-shadow:0 4px 12px rgba(217,154,43,.35);}
.de-rate input:focus-visible + label{outline:2px solid var(--de-accent-dark);outline-offset:2px;}
.de-table tr.unanswered td{background:rgba(240,84,84,.06);}
.de-table tr.unanswered td:first-child{box-shadow:inset 3px 0 0 #ef4444;}
.de-table tr.unanswered .de-qno{color:#ef4444;}
.de-comment{margin-top:20px;}
.de-comment.has-error{background:#F8FAFF;border:1px solid #F87171;border-radius:14px;padding:16px 20px 14px;box-shadow:0 0 0 3px rgba(239,68,68,.10);}
.de-comment.has-error .de-comment-label{margin-bottom:10px;}
.de-comment.has-error textarea{border-color:#EF4444;}
.de-comment-error{margin-top:10px;font-size:13px;font-weight:700;color:#B91C1C;}
.de-comment-error[hidden]{display:none;}
.de-comment-label{font-size:13px;font-weight:700;color:var(--de-accent-dark);margin-bottom:8px;}
.de-comment textarea{width:100%;min-height:96px;resize:vertical;background:var(--de-inner);color:var(--de-ink);border:1px solid #CBD5E1;border-radius:10px;padding:12px;font-family:inherit;font-size:13.5px;}
.de-comment textarea:focus{outline:none;border-color:var(--de-accent);}
.de-comment-readonly{font-size:13.5px;line-height:1.55;white-space:pre-wrap;}
.de-summary{display:flex;align-items:baseline;gap:8px;margin-bottom:6px;}
.de-summary .num{font-family:'Rajdhani',sans-serif;font-size:34px;font-weight:700;}
.de-summary .of{font-size:13px;color:var(--de-muted);}
.de-score-chip{display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:28px;padding:0 8px;border-radius:7px;background:var(--de-accent);color:#fff;font-weight:700;font-size:13px;}

/* "Leave without submitting?" confirmation (Principal amber accent) */
.de-confirm-overlay{position:fixed;inset:0;z-index:2100;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(10,25,47,.40);-webkit-backdrop-filter:blur(8px);backdrop-filter:blur(8px);}
.de-confirm-overlay.open{display:flex;animation:deFade .15s ease-out;}
.de-confirm{width:100%;max-width:525px;background:#fff;color:#172033;border-radius:20px;padding:34px 36px 36px;box-shadow:0 24px 70px rgba(0,0,0,.35);font-family:'DM Sans',sans-serif;animation:deRise .2s ease-out;}
.de-confirm-title{margin:0 0 10px;font-family:'DM Sans',sans-serif;font-size:18px;font-weight:700;line-height:1.3;color:#172033;}
.de-confirm-text{margin:0 0 26px;font-size:15px;line-height:1.8;color:#64748B;}
.de-confirm-actions{display:flex;gap:16px;}
.de-confirm-btn{flex:1;height:54px;border-radius:14px;font-family:'DM Sans',sans-serif;font-size:16px;font-weight:700;cursor:pointer;transition:transform .12s,box-shadow .12s,background .12s,border-color .12s;}
.de-confirm-btn:focus-visible{outline:2px solid #B45309;outline-offset:3px;}
.de-confirm-btn.stay{background:#fff;color:#172033;border:1px solid #D9E4EF;}
.de-confirm-btn.stay:hover{border-color:#D99A2B;background:#FFF7ED;}
.de-confirm-btn.leave{background:#D99A2B;color:#fff;border:1px solid #D99A2B;box-shadow:0 10px 24px rgba(217,154,43,.38);}
.de-confirm-btn.leave:hover{background:#B45309;border-color:#B45309;transform:translateY(-1px);}
@media(max-width:480px){.de-confirm{padding:26px 22px 24px;}.de-confirm-actions{gap:10px;}.de-confirm-btn{height:48px;font-size:15px;}}
@media(prefers-reduced-motion:reduce){.de-overlay.open,.de-overlay.open .de-modal,.de-confirm-overlay.open,.de-confirm{animation:none;}}

@media(max-width:900px){.de-cats{grid-template-columns:1fr;}.de-grid{grid-template-columns:1fr;}}
@media(max-width:700px){.de-overlay{padding:10px;}}
@media(max-width:640px){.de-modal-head{padding:16px 18px;gap:12px;}.de-modal-head img{width:52px;height:52px;}.de-modal-body{padding:18px;}.de-modal-foot{padding:14px 18px;gap:10px;}}
@media(max-width:768px){.de-table th:not(:first-child),.de-table col.c-rate{width:46px;}.de-rate label{width:30px;height:28px;}}
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

<main class="main principal-feature-page">
  <div id="principalFeatureWorkspace" class="principal-feature-shell">
    <div class="principal-feature-header-row">
      <section class="principal-feature-heading" aria-labelledby="principal-evaluate-others-title">
        <h1 id="principal-evaluate-others-title"></h1>
      </section>
    </div>

    <?php if ($toast): ?>
    <div class="de-flash success" role="status"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($toast) ?></div>
    <?php endif; ?>
    <?php if ($toast_error): ?>
    <div class="de-flash error" role="status"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($toast_error) ?></div>
    <?php endif; ?>

    <?php if (!$structureActive): ?>
        <?php render_scope_status($settings, 'evaluation'); ?>
        <div class="alert error"><i class="fa-solid fa-hourglass-half"></i> Waiting for <?= BASIC_ED_LABEL ?> evaluation period</div>
    <?php else: ?>

    <?php if (!$hasPeriod): ?>
    <div class="alert error"><i class="fa-solid fa-hourglass-half"></i> Waiting for <?= BASIC_ED_LABEL ?> evaluation period</div>
    <?php endif; ?>

    <div class="de-choose-title"><i class="fa-solid fa-clipboard-check" aria-hidden="true"></i><span>Your Evaluation</span>
        <?php if (!empty($periodLabel)): ?><span class="de-period-chip"><i class="fa-regular fa-calendar"></i> <?= htmlspecialchars($periodLabel) ?></span><?php endif; ?>
    </div>
    <p class="de-lead">Choose a group, then evaluate each member. Your progress for the active period is shown on every card.</p>
    <div class="de-cats" role="tablist">
        <?php foreach ($validTabs as $t):
            $list = $rosterByTab[$t];
            $n = count($list);
            $done = count(array_filter($list, fn($r) => $r['evaluation_status'] === 'completed'));
        ?>
        <button type="button" role="tab" class="de-cat <?= $catMeta[$t]['cls'] ?> <?= $tab === $t ? 'active' : '' ?>" data-tab="<?= $t ?>" aria-selected="<?= $tab === $t ? 'true' : 'false' ?>">
            <div class="de-cat-ico"><i class="fa-solid <?= $catMeta[$t]['icon'] ?>"></i></div>
            <div class="de-cat-name"><?= htmlspecialchars($catMeta[$t]['label']) ?></div>
            <div class="de-cat-count"><?= $n ?> member<?= $n === 1 ? '' : 's' ?></div>
            <span class="de-cat-done"><i class="fa-solid fa-check" style="font-size:9px;"></i> <?= $done ?>/<?= $n ?> done</span>
            <span class="de-cat-bar<?= ($n > 0 && $done === $n) ? ' complete' : '' ?>" aria-hidden="true"><i style="width:<?= $n > 0 ? (int) round($done / $n * 100) : 0 ?>%"></i></span>
            <span class="de-cat-cta">
                <span class="cta-select">Select group <i class="fa-solid fa-arrow-right"></i></span>
                <span class="cta-selected"><i class="fa-solid fa-check"></i> Selected</span>
            </span>
        </button>
        <?php endforeach; ?>
    </div>

    <?php foreach ($validTabs as $t):
        $list = $rosterByTab[$t];
        $n = count($list);
        $done = count(array_filter($list, fn($r) => $r['evaluation_status'] === 'completed'));
        $depts = [];
        foreach ($list as $r) { $d = trim((string)($r['department'] ?? '')); if ($d !== '') $depts[$d] = true; }
        $depts = array_keys($depts); sort($depts);
    ?>
    <section class="de-panel <?= $tab === $t ? 'active' : '' ?>" data-tab="<?= $t ?>">
        <div class="de-panel-head">
            <div class="de-panel-ico"><i class="fa-solid <?= $catMeta[$t]['icon'] ?>"></i></div>
            <div class="de-panel-title"><?= htmlspecialchars($catMeta[$t]['label']) ?></div>
            <div class="de-panel-tools">
                <?php if (!empty($depts)): ?>
                <select class="de-dept" aria-label="Filter by department">
                    <option value="all">All Departments</option>
                    <?php foreach ($depts as $d): ?><option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?></option><?php endforeach; ?>
                </select>
                <?php endif; ?>
            </div>
        </div>

        <div class="de-grid">
        <?php if (empty($list)): ?>
            <div class="de-empty"><i class="fa-solid fa-user-slash"></i><p>No <?= htmlspecialchars($catMeta[$t]['noun']) ?> are available for evaluation.</p></div>
        <?php else: foreach ($list as $p):
            $isDone = $p['evaluation_status'] === 'completed';
            $sub = $p['role_label'] . (!empty($p['department']) ? ' · ' . $p['department'] : '');
        ?>
            <div class="de-person" data-id="<?= (int)$p['id'] ?>" data-dept="<?= htmlspecialchars((string)($p['department'] ?? '')) ?>" data-status="<?= $isDone ? 'completed' : 'pending' ?>">
                <img class="de-avatar" src="<?= htmlspecialchars(principal_person_photo($p['photo'] ?? null)) ?>" alt=""/>
                <div class="de-person-info">
                    <div class="de-person-name"><?= htmlspecialchars($p['full_name']) ?></div>
                    <div class="de-person-desig"><?= htmlspecialchars($p['designation'] ?: $p['role_label']) ?></div>
                    <?php if ($isDone): ?>
                        <span class="de-pill done"><i class="fa-solid fa-check" style="font-size:9px;"></i> Completed</span>
                        <?php if ($p['last_evaluation_date']): ?><span class="de-pill-date"><?= htmlspecialchars(date('M j, Y', strtotime($p['last_evaluation_date']))) ?></span><?php endif; ?>
                    <?php else: ?>
                        <span class="de-pill pending"><i class="fa-solid fa-hourglass-half" style="font-size:9px;"></i> Pending</span>
                    <?php endif; ?>
                </div>
                <div class="de-person-action">
                <?php if ($isDone): ?>
                    <button type="button" class="de-btn de-btn-view" data-mode="view" data-id="<?= (int)$p['id'] ?>" data-name="<?= htmlspecialchars($p['full_name']) ?>" data-sub="<?= htmlspecialchars($sub) ?>" data-photo="<?= htmlspecialchars(principal_person_photo($p['photo'] ?? null)) ?>" data-tab="<?= $t ?>"><i class="fa-regular fa-eye"></i> View</button>
                <?php elseif (!$evalOpen || !$hasPeriod): ?>
                    <span class="de-muted">Evaluation closed</span>
                <?php elseif (empty($p['has_questions'])): ?>
                    <button type="button" class="de-btn de-btn-view" disabled title="No questions have been set up for this person yet."><i class="fa-solid fa-ban"></i> No questions</button>
                <?php else: ?>
                    <button type="button" class="de-btn de-btn-eval" data-mode="evaluate" data-id="<?= (int)$p['id'] ?>" data-name="<?= htmlspecialchars($p['full_name']) ?>" data-sub="<?= htmlspecialchars($sub) ?>" data-photo="<?= htmlspecialchars(principal_person_photo($p['photo'] ?? null)) ?>" data-tab="<?= $t ?>"><i class="fa-solid fa-pen"></i> Evaluate</button>
                <?php endif; ?>
                </div>
            </div>
        <?php endforeach; endif; ?>
        </div>

        <div class="de-panel-foot">
            <div><b class="js-total"><?= $n ?></b> members &nbsp; <b class="js-done"><?= $done ?></b> completed &nbsp; <b class="js-pending"><?= $n - $done ?></b> pending</div>
            <div><i class="fa-regular fa-calendar"></i> <?= htmlspecialchars($periodLabel) ?></div>
        </div>
    </section>
    <?php endforeach; ?>

    <?php endif; ?>
  </div>
</main>

<?php if ($structureActive): ?>
<div class="de-overlay" id="deOverlay" aria-hidden="true">
    <div class="de-modal" role="dialog" aria-modal="true" aria-labelledby="deName">
        <div class="de-modal-head">
            <img id="dePhoto" src="../image/pbi_logo" alt=""/>
            <div>
                <div class="de-modal-name" id="deName"></div>
                <div class="de-modal-sub" id="deSub"></div>
            </div>
            <button type="button" class="de-modal-x" id="deClose" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" action="principal_evaluations.php" id="deForm" novalidate style="display:flex;flex-direction:column;min-height:0;flex:1;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"/>
            <input type="hidden" name="submit_evaluation" value="1"/>
            <input type="hidden" name="target_user_id" id="deTarget" value=""/>
            <input type="hidden" name="tab" id="deTab" value="<?= htmlspecialchars($tab) ?>"/>
            <div class="de-modal-body" id="deBody"><div class="de-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading questions...</div></div>
            <div class="de-modal-foot">
                <button type="button" class="de-btn de-btn-cancel" id="deCancel">Cancel</button>
                <button type="submit" class="de-btn de-btn-submit" id="deSubmit"><i class="fa-solid fa-paper-plane"></i> Submit Evaluation</button>
            </div>
        </form>
    </div>
</div>

<div class="de-confirm-overlay" id="deConfirm" aria-hidden="true">
    <div class="de-confirm" role="alertdialog" aria-modal="true" aria-labelledby="deConfirmTitle" aria-describedby="deConfirmText">
        <h3 class="de-confirm-title" id="deConfirmTitle">Leave without submitting?</h3>
        <p class="de-confirm-text" id="deConfirmText">You have an unsubmitted evaluation. If you leave now, your answers will be lost.</p>
        <div class="de-confirm-actions">
            <button type="button" class="de-confirm-btn stay" id="deStay">Stay</button>
            <button type="button" class="de-confirm-btn leave" id="deLeave">Leave</button>
        </div>
    </div>
</div>

<script>
(function(){
    const OPEN_ID   = <?= (int)$openId ?>;
    const OPEN_VIEW = <?= $openView ? 'true' : 'false' ?>;
    const PAGE_URL  = 'principal_evaluations.php';

    const $ = (id) => document.getElementById(id);
    const overlay = $('deOverlay'), form = $('deForm'), body = $('deBody');
    const submitBtn = $('deSubmit'), cancelBtn = $('deCancel');
    let mode = 'evaluate', loaded = false, requestToken = 0, submitting = false;
    const confirmBox = $('deConfirm'), stayBtn = $('deStay'), leaveBtn = $('deLeave');

    function esc(s){
        return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    /* ── category cards / panels ── */
    const cats = document.querySelectorAll('.de-cat');
    const panels = document.querySelectorAll('.de-panel');
    function showTab(tab, push){
        cats.forEach(c => { const on = c.dataset.tab === tab; c.classList.toggle('active', on); c.setAttribute('aria-selected', on ? 'true' : 'false'); });
        panels.forEach(p => p.classList.toggle('active', p.dataset.tab === tab));
        if (push && window.history && history.replaceState) {
            const u = new URL(window.location.href);
            if (tab) u.searchParams.set('tab', tab); else u.searchParams.delete('tab');
            u.searchParams.delete('open'); u.searchParams.delete('view');
            history.replaceState(null, '', u.toString());
        }
    }
    // Clicking the already-selected group a second time returns to the default view.
    cats.forEach(c => c.addEventListener('click', () => showTab(c.classList.contains('active') ? '' : c.dataset.tab, true)));

    /* ── department filter (per panel) ── */
    panels.forEach(panel => {
        const sel = panel.querySelector('.de-dept');
        function apply(){
            const d = sel ? sel.value : 'all';
            let total = 0, done = 0;
            panel.querySelectorAll('.de-person').forEach(card => {
                const show = d === 'all' || card.dataset.dept === d;
                card.style.display = show ? '' : 'none';
                if (show) { total++; if (card.dataset.status === 'completed') done++; }
            });
            panel.querySelector('.js-total').textContent = total;
            panel.querySelector('.js-done').textContent = done;
            panel.querySelector('.js-pending').textContent = total - done;
        }
        if (sel) sel.addEventListener('change', apply);
    });

    /* ── modal open / close ── */
    function openModal(btn){
        mode = btn.dataset.mode === 'view' ? 'view' : 'evaluate';
        const id = btn.dataset.id;
        $('deName').textContent = btn.dataset.name || '';
        $('deSub').textContent = btn.dataset.sub || '';
        $('dePhoto').src = btn.dataset.photo || '../image/pbi_logo';
        $('deTarget').value = id;
        $('deTab').value = btn.dataset.tab || 'faculty';
        body.innerHTML = '<div class="de-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading ' + (mode === 'view' ? 'your evaluation' : 'questions') + '...</div>';
        loaded = false;
        submitBtn.style.display = mode === 'view' ? 'none' : '';
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit Evaluation';
        cancelBtn.textContent = mode === 'view' ? 'Close' : 'Cancel';
        overlay.classList.add('open'); overlay.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        mode === 'view' ? loadSubmission(id) : loadQuestions(id);
    }
    function closeModal(){
        requestToken++;
        hideConfirm(false);
        overlay.classList.remove('open'); overlay.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        loaded = false;
    }

    /* ── leave-without-submitting guard ── */
    function isDirty(){
        if (mode !== 'evaluate' || !loaded) return false;
        if (form.querySelector('input[type="radio"]:checked')) return true;
        const c = form.querySelector('textarea[name="comment"]');
        return !!(c && c.value.trim() !== '');
    }
    function showConfirm(){
        confirmBox.classList.add('open'); confirmBox.setAttribute('aria-hidden', 'false');
        stayBtn.focus();
    }
    function hideConfirm(restoreFocus){
        if (!confirmBox.classList.contains('open')) return;
        confirmBox.classList.remove('open'); confirmBox.setAttribute('aria-hidden', 'true');
        if (restoreFocus) $('deClose').focus();
    }
    function requestClose(){
        if (isDirty()) showConfirm(); else closeModal();
    }
    stayBtn.addEventListener('click', () => hideConfirm(true));
    leaveBtn.addEventListener('click', closeModal);
    confirmBox.addEventListener('click', e => { if (e.target === confirmBox) hideConfirm(true); });
    confirmBox.addEventListener('keydown', e => {
        if (e.key !== 'Tab') return;
        e.preventDefault();
        (document.activeElement === stayBtn ? leaveBtn : stayBtn).focus();
    });
    // Browser back / refresh / tab close while answers are unsaved.
    window.addEventListener('beforeunload', e => {
        if (!submitting && overlay.classList.contains('open') && isDirty()) { e.preventDefault(); e.returnValue = ''; }
    });
    document.querySelectorAll('.de-person-action button[data-id]').forEach(b => b.addEventListener('click', () => openModal(b)));
    $('deClose').addEventListener('click', requestClose);
    cancelBtn.addEventListener('click', requestClose);
    overlay.addEventListener('click', e => { if (e.target === overlay) requestClose(); });
    document.addEventListener('keydown', e => {
        if (e.key !== 'Escape' || !overlay.classList.contains('open')) return;
        if (confirmBox.classList.contains('open')) hideConfirm(true); else requestClose();
    });

    function showError(msg){
        body.innerHTML = '<div class="de-error"><i class="fa-solid fa-triangle-exclamation"></i><p style="margin-top:10px">' + esc(msg) + '</p></div>';
        submitBtn.style.display = 'none';
        cancelBtn.textContent = 'Close';
    }
    function fetchJson(url){
        return fetch(url, {credentials: 'same-origin'}).then(r => r.json().catch(() => ({success:false, error:'Unexpected response from the server.'})));
    }

    /* ── evaluate mode ── */
    function loadQuestions(id){
        const token = ++requestToken;
        fetchJson(PAGE_URL + '?get_questions=1&target_id=' + encodeURIComponent(id)).then(data => {
            if (token !== requestToken) return;
            if (!data.success) return showError(data.error || 'Failed to load questions.');
            const qs = data.questions || [];
            const grouped = {};
            qs.forEach(q => { (grouped[q.category || 'General'] = grouped[q.category || 'General'] || []).push(q); });

            let html = '<div class="de-validation" id="deValidation" role="alert" aria-live="assertive" hidden></div>';
            html += '<div class="de-legend">' + [[5,'Always'],[4,'Often'],[3,'Sometimes'],[2,'Rarely'],[1,'Never']]
                .map(x => '<div class="de-legend-item"><div class="de-legend-dot">' + x[0] + '</div> ' + x[1] + '</div>').join('') + '</div>';

            let n = 1;
            Object.keys(grouped).forEach(cat => {
                html += '<div class="de-cat-title">' + esc(cat) + '</div>';
                html += '<div class="de-table-wrap"><table class="de-table"><colgroup><col><col class="c-rate"><col class="c-rate"><col class="c-rate"><col class="c-rate"><col class="c-rate"></colgroup><tbody>';
                grouped[cat].forEach(q => {
                    const num = n++;
                    html += '<tr data-number="' + num + '"><td><div class="de-qtext"><span class="de-qno">' + num + '.</span>' + esc(q.question) + '</div></td>';
                    [5,4,3,2,1].forEach(v => {
                        const oid = 'r_' + q.source + '_' + q.id + '_' + v;
                        html += '<td><div class="de-rate"><input type="radio" name="rating[' + esc(q.key) + ']" id="' + oid + '" value="' + v + '" aria-label="Question ' + num + ', rating ' + v + '"><label for="' + oid + '">' + v + '</label></div></td>';
                    });
                    html += '</tr>';
                });
                html += '</tbody></table></div>';
            });
            html += '<div class="de-comment"><div class="de-comment-label"><i class="fa-solid fa-comment-dots"></i> Comments, Suggestions &amp; Areas for Improvement <span style="color:#ef4444">*</span></div>' +
                    '<textarea name="comment" rows="4" maxlength="2000" required aria-required="true" placeholder="Share your thoughts, suggestions, or concerns about this person\'s performance...."></textarea>' +
                    '<div class="de-comment-error" id="deCommentError" role="alert" hidden>Required — you can\'t submit this evaluation without a comment.</div></div>';
            body.innerHTML = html;
            loaded = true;
        }).catch(err => { if (token === requestToken) showError('Failed to load questions. ' + (err && err.message ? err.message : '')); });
    }

    function radioNames(){
        return [...new Set([...form.querySelectorAll('input[type="radio"][name^="rating["]')].map(r => r.name))];
    }
    function isAnswered(name){
        return !!form.querySelector('input[name="' + CSS.escape(name) + '"]:checked');
    }
    function setCommentError(on){
        const c = form.querySelector('textarea[name="comment"]'); if (!c) return;
        const wrap = c.closest('.de-comment'); if (wrap) wrap.classList.toggle('has-error', !!on);
        const msg = $('deCommentError'); if (msg) msg.hidden = !on;
        c.setAttribute('aria-invalid', on ? 'true' : 'false');
    }
    function updateProgress(){
        const status = $('deProgress'); if (!status) return;
        const names = radioNames();
        const answered = names.filter(isAnswered).length;
        status.textContent = answered + ' / ' + names.length + ' answered';
        status.style.color = answered === names.length && names.length ? '#10b981' : '';
    }
    form.addEventListener('change', e => {
        if (!e.target.matches('input[type="radio"]')) return;
        const row = e.target.closest('tr'); if (row) row.classList.remove('unanswered');
        updateProgress();
        const box = $('deValidation');
        if (box && radioNames().every(isAnswered)) { box.hidden = true; box.innerHTML = ''; }
    });
    form.addEventListener('input', e => {
        if (!e.target.matches('textarea[name="comment"]')) return;
        if (e.target.value.trim() !== '') {
            setCommentError(false);
            const box = $('deValidation');
            if (box && !box.hidden && radioNames().every(isAnswered)) { box.hidden = true; box.innerHTML = ''; }
        }
    });
    form.addEventListener('submit', e => {
        if (mode !== 'evaluate' || !loaded) { e.preventDefault(); return; }
        const missing = radioNames().filter(n => !isAnswered(n));
        const cEl = form.querySelector('textarea[name="comment"]');
        const noComment = !!cEl && cEl.value.trim() === '';
        if (missing.length || noComment) {
            e.preventDefault();
            form.querySelectorAll('tr.unanswered').forEach(r => r.classList.remove('unanswered'));
            const rows = missing.map(n => form.querySelector('input[name="' + CSS.escape(n) + '"]').closest('tr'));
            rows.forEach(r => r.classList.add('unanswered'));
            const nums = rows.map(r => r.dataset.number);
            if (cEl) setCommentError(noComment);
            const msgs = [];
            if (missing.length) msgs.push('<strong>Please answer all questions before submitting.</strong> You have ' + missing.length + ' unanswered question' + (missing.length === 1 ? '' : 's') +
                (nums.length <= 8 ? ' (' + nums.join(', ') + ')' : '') + '.');
            if (noComment) msgs.push('<strong>A comment is required.</strong> Please share your comments, suggestions or areas for improvement.');
            const box = $('deValidation');
            if (box) {
                box.hidden = false;
                box.innerHTML = msgs.join('<br>');
                if (missing.length) box.scrollIntoView({behavior: 'smooth', block: 'center'});
            }
            if (!missing.length && cEl) { cEl.scrollIntoView({behavior: 'smooth', block: 'center'}); cEl.focus({preventScroll: true}); }
            return;
        }
        submitting = true;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting...';
    });

    /* ── view mode ── */
    function loadSubmission(id){
        const token = ++requestToken;
        fetchJson(PAGE_URL + '?get_submission=1&target_id=' + encodeURIComponent(id)).then(data => {
            if (token !== requestToken) return;
            if (!data.success) return showError(data.error || 'Could not load this evaluation.');
            let html = '<div class="de-summary"><span class="num">' + (data.score != null ? Number(data.score).toFixed(2) : '—') + '</span><span class="of">/ 5.00 overall</span></div>' +
                       '<div class="de-modal-sub" style="margin-bottom:6px">Submitted ' + esc(data.submitted_at) + '</div>';
            const grouped = {};
            (data.answers || []).forEach(a => { (grouped[a.category || 'General'] = grouped[a.category || 'General'] || []).push(a); });
            let n = 1;
            Object.keys(grouped).forEach(cat => {
                html += '<div class="de-cat-title">' + esc(cat) + '</div>';
                html += '<div class="de-table-wrap"><table class="de-table"><thead><tr><th>Question</th><th>Your rating</th></tr></thead><tbody>';
                grouped[cat].forEach(a => {
                    html += '<tr><td><div class="de-qtext"><span class="de-qno">' + (n++) + '.</span>' + esc(a.question) + '</div></td><td><span class="de-score-chip">' + esc(Math.round(a.score)) + '</span></td></tr>';
                });
                html += '</tbody></table></div>';
            });
            html += '<div class="de-comment"><div class="de-comment-label"><i class="fa-solid fa-comment-dots"></i> Comment</div><p class="de-comment-readonly">' +
                    (data.comment ? esc(data.comment) : 'No written comment.') + '</p></div>';
            body.innerHTML = html;
            loaded = true;
        }).catch(() => { if (token === requestToken) showError('Could not load this evaluation.'); });
    }

    /* deep link from the old principal_evaluate.php URL: ?tab=…&open=ID[&view=1] */
    if (OPEN_ID > 0) {
        const btn = document.querySelector('.de-person-action button[data-id="' + OPEN_ID + '"]');
        if (btn) {
            const panel = btn.closest('.de-panel'); if (panel) showTab(panel.dataset.tab, false);
            if (!OPEN_VIEW || btn.dataset.mode === 'view') openModal(btn);
        }
    }
})();
</script>
<?php endif; ?>
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
<script src="../admin/eval_status_poll.js" defer></script>
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

/* Pull the content up: collapse the empty heading row and trim the top spacing. */
.principal-feature-header-row,
.principal-feature-heading{
  min-height:0 !important;
  height:0 !important;
  margin:0 !important;
  padding:0 !important;
  overflow:hidden !important;
}
.principal-feature-page .principal-feature-shell{
  padding-top:0 !important;
  margin-top:0 !important;
}
.principal-feature-page .de-choose-title{
  margin-top:0 !important;
}
main.main.principal-feature-page{
  padding-top:12px !important;
}
</style>

<link rel="stylesheet" href="includes/principal_dark_repairs.css?v=20261011.4" id="principal-dark-repairs"/>

<style id="de-polish">
/* ------------------------------------------------------------------
   Evaluate Others polish (same treatment as the admin EA page):
   clearer hierarchy, per-group progress bar, stronger contrast.
   Light-theme colours only; dark mode keeps its own palette.
   Presentation only: the tabs, filters and evaluation modal are untouched.
   ------------------------------------------------------------------ */
html:not(.dark-theme) body:not(#dep){--muted:#475569;--border:#d3dce8;}

/* ── Title row ── */
html body:not(#dep) .de-choose-title{flex-wrap:wrap;margin-bottom:6px!important;}
html body:not(#dep) .de-period-chip{margin-left:auto;display:inline-flex;align-items:center;gap:8px;padding:7px 14px;border-radius:999px;font-family:'DM Sans','Inter',sans-serif;font-size:12.5px;font-weight:700;border:1px solid transparent;}
html:not(.dark-theme) body:not(#dep) .de-period-chip{background:#EEF2FF;color:#3730A3;border-color:#C7D2FE;}
html.dark-theme body:not(#dep) .de-period-chip{background:rgba(156,133,240,.16);color:#C4B5FD;border-color:rgba(156,133,240,.35);}
html body:not(#dep) .de-lead{margin:0 0 22px;font-size:14.5px;line-height:1.5;color:var(--muted);}

/* ── Group cards ── */
html body:not(#dep) .de-cats{gap:22px!important;margin-bottom:28px!important;}
html body:not(#dep) .de-cat{--g:#7C5FD9;--g-ink:#5F45B8;--g-bg:#ECE3FF;--g-ring:rgba(124,95,217,.28);
    display:flex!important;flex-direction:column;align-items:center;min-height:310px;padding:32px 22px 22px!important;
    border-radius:18px!important;border-width:1px!important;border-bottom-width:1px!important;border-top:5px solid var(--g)!important;}
html body:not(#dep) .de-cat.faculty{--g:#0EA5E9;--g-ink:#0369A1;--g-bg:#DFF1FD;--g-ring:rgba(14,165,233,.28);}
html body:not(#dep) .de-cat.staff{--g:#16A34A;--g-ink:#15803D;--g-bg:#DDF5E6;--g-ring:rgba(22,163,74,.28);}
html body:not(#dep) .de-cat.ea{--g:#7C5FD9;--g-ink:#5F45B8;--g-bg:#ECE3FF;--g-ring:rgba(124,95,217,.28);}
/* top accent = the card's own top border, so it follows the rounded corners */
html body:not(#dep) .de-cat::before{display:none!important;}
html body:not(#dep) .de-cat.active::after{border-top-color:var(--g)!important;}
html body:not(#dep) .de-cat .de-cat-ico{width:88px!important;height:88px!important;font-size:34px!important;margin-bottom:16px!important;}
html body:not(#dep) .de-cat .de-cat-name{font-size:24px!important;}
html body:not(#dep) .de-cat .de-cat-count{display:inline-flex;margin-top:10px!important;padding:6px 16px;border-radius:999px;font-size:13.5px!important;font-weight:700;}
html body:not(#dep) .de-cat .de-cat-done{margin-top:20px!important;padding:0!important;background:none!important;font-size:13px!important;font-weight:700;}
html body:not(#dep) .de-cat-bar{display:block;width:100%;max-width:230px;height:8px;margin-top:8px;border-radius:999px;overflow:hidden;background:rgba(100,116,139,.22);}
html body:not(#dep) .de-cat-bar i{display:block;height:100%;border-radius:999px;background:var(--g);transition:width .3s ease;}
html body:not(#dep) .de-cat-bar.complete i{background:#16A34A;}
html body:not(#dep) .de-cat-cta{display:inline-flex;margin-top:auto;padding-top:20px;font-size:13px;font-weight:800;color:var(--g-ink);}
html body:not(#dep) .de-cat-cta .cta-select,html body:not(#dep) .de-cat-cta .cta-selected{display:inline-flex;align-items:center;gap:8px;}
html body:not(#dep) .de-cat-cta .cta-selected{display:none;}
html body:not(#dep) .de-cat.active .de-cat-cta .cta-select{display:none;}
html body:not(#dep) .de-cat.active .de-cat-cta .cta-selected{display:inline-flex;}
html body:not(#dep) .de-cat-cta i{font-size:12px;transition:transform .15s ease;}
html body:not(#dep) .de-cat:hover .de-cat-cta .cta-select i{transform:translateX(4px);}
html body:not(#dep) .de-cat:focus-visible{outline:3px solid var(--g);outline-offset:3px;}
html:not(.dark-theme) body:not(#dep) .de-cat{background:#fff!important;border-color:#CDD8E6!important;box-shadow:0 1px 2px rgba(11,31,58,.06),0 8px 22px rgba(11,31,58,.06)!important;color:#0b1f3a;}
html:not(.dark-theme) body:not(#dep) .de-cat{border-top-color:var(--g)!important;}
html:not(.dark-theme) body:not(#dep) .de-cat:hover{border-color:var(--g)!important;box-shadow:0 14px 32px rgba(11,31,58,.13)!important;transform:translateY(-3px)!important;}
html:not(.dark-theme) body:not(#dep) .de-cat.active{border-color:var(--g)!important;box-shadow:0 0 0 3px var(--g-ring),0 12px 28px rgba(11,31,58,.12)!important;}
html:not(.dark-theme) body:not(#dep) .de-cat .de-cat-name{color:#0b1f3a!important;}
html:not(.dark-theme) body:not(#dep) .de-cat .de-cat-count{background:var(--g-bg)!important;color:var(--g-ink)!important;}
html:not(.dark-theme) body:not(#dep) .de-cat .de-cat-done{color:#334155!important;}
html.dark-theme body:not(#dep) .de-cat .de-cat-count{background:rgba(255,255,255,.08)!important;color:var(--light)!important;}
html.dark-theme body:not(#dep) .de-cat-cta{color:var(--g);}

/* ── Roster panel ── */
html body:not(#dep) .de-panel{border-radius:18px!important;margin-top:16px!important;}
html body:not(#dep) .de-panel-head{padding:18px 24px!important;gap:12px!important;}
html body:not(#dep) .de-panel-ico{width:40px!important;height:40px!important;font-size:16px!important;border-radius:10px!important;}
html body:not(#dep) .de-panel-title{font-size:24px!important;}
html body:not(#dep) .de-panel-tools select{padding:9px 12px!important;font-size:13px!important;border-radius:9px!important;min-width:190px!important;}
html body:not(#dep) .de-grid{gap:14px!important;padding:20px 22px!important;}
html body:not(#dep) .de-person{gap:16px!important;padding:16px 18px!important;border-radius:14px!important;border-left-width:4px!important;transition:box-shadow .15s ease,border-color .15s ease;}
html body:not(#dep) .de-person[data-status="completed"]{border-left-color:#16A34A!important;}
html body:not(#dep) .de-person[data-status="pending"]{border-left-color:#F59E0B!important;}
html body:not(#dep) .de-person:hover{box-shadow:0 6px 18px rgba(11,31,58,.10);}
html body:not(#dep) .de-avatar{width:56px!important;height:56px!important;}
html body:not(#dep) .de-person-name{font-size:15.5px!important;}
html body:not(#dep) .de-person-desig{font-size:13px!important;margin:2px 0 7px!important;}
html body:not(#dep) .de-pill{font-size:12px!important;padding:4px 11px!important;border:1px solid transparent;}
html body:not(#dep) .de-pill-date{font-size:12px!important;}
html body:not(#dep) .de-btn{padding:10px 18px!important;font-size:13px!important;font-weight:700!important;border-radius:10px!important;min-width:112px;justify-content:center;}
html body:not(#dep) .de-panel-foot{padding:14px 24px!important;font-size:13px!important;}
html body:not(#dep) .de-panel-foot b{font-size:15px;font-weight:800;}
html:not(.dark-theme) body:not(#dep) .de-person{background:#fff!important;border-color:#CDD8E6!important;}
html:not(.dark-theme) body:not(#dep) .de-person-name{color:#0b1f3a!important;}
html:not(.dark-theme) body:not(#dep) .de-pill.done{background:#D6F2E4!important;color:#0A6A3B!important;border-color:#8ED0AD!important;}
html:not(.dark-theme) body:not(#dep) .de-pill.pending{background:#FFF1D4!important;color:#8A5300!important;border-color:#EBCB88!important;}
html:not(.dark-theme) body:not(#dep) .de-btn-view:not([disabled]){background:#fff!important;color:#1e293b!important;border-color:#b6c3d6!important;}
html:not(.dark-theme) body:not(#dep) .de-btn-view:not([disabled]):hover{background:#F1F5FB!important;}

@media(max-width:900px){html body:not(#dep) .de-cats{grid-template-columns:1fr!important;}html body:not(#dep) .de-cat{min-height:250px;}html body:not(#dep) .de-grid{grid-template-columns:1fr!important;}}
@media(max-width:560px){html body:not(#dep) .de-cat{min-height:0;}html body:not(#dep) .de-person{flex-wrap:wrap;}}
</style>

<style id="de-form-ea-sizing">
/* Evaluation popup sizing matched to the admin EA evaluation form
   (ea_evaluate.php): same header, progress bar, legend, category titles,
   row heights, rating buttons, comment box and footer dimensions. */
html body .de-modal{border-radius:18px;}
html body .de-modal .de-modal-head{padding:24px 28px 16px;gap:14px;}
html body .de-modal .de-modal-head img{width:52px;height:52px;border-width:2px;}
html body .de-modal .de-modal-name{font-size:20px;}
html body .de-modal .de-modal-sub{font-size:12px;}
html body .de-modal .de-modal-body{padding:24px 28px;}

html body .de-modal .de-progress{padding:9px 12px;margin:0 0 14px;border-radius:8px;font-size:11.5px;}
html body .de-modal .de-validation{padding:11px 13px;margin:0 0 16px;border-radius:9px;font-size:12.5px;line-height:1.5;}
html body .de-modal .de-legend{gap:16px;margin-bottom:18px;padding:10px 14px;border-radius:8px;font-size:11px;}
html body .de-modal .de-legend-item{gap:5px;}
html body .de-modal .de-legend-dot{width:18px;height:18px;border-radius:4px;font-size:10px;}

html body .de-modal .de-cat-title{margin:18px 0 8px;gap:0;font-size:10px;font-weight:800;letter-spacing:.9px;}
html body .de-modal .de-cat-title:first-of-type{margin-top:0;}
html body .de-modal .de-cat-title::before{content:"\f5fd";font-family:"Font Awesome 6 Free","Font Awesome 5 Free";font-weight:900;width:auto;height:auto;border-radius:0;background:none;color:inherit;font-size:10px;margin-right:5px;}
html body .de-modal .de-table-wrap{margin:0 0 16px;border-radius:12px;}
html body .de-modal .de-table td{padding:10px 6px;}
html body .de-modal .de-table td:first-child{padding-left:14px;padding-right:10px;}
html body .de-modal .de-table col.c-rate{width:52px;}
html body .de-modal .de-qtext{font-size:12.5px;line-height:1.45;}
html body .de-modal .de-qno{font-weight:800;}
html body .de-modal .de-rate label{width:34px;height:30px;border-radius:7px;font-size:12px;font-weight:800;box-shadow:0 2px 5px rgba(15,23,42,.03);}
html body .de-modal .de-rate input:checked + label{box-shadow:none;}

html body .de-modal .de-comment{margin-top:24px;}
html body .de-modal .de-comment-label{font-size:12px;margin-bottom:10px;}
html body .de-modal .de-comment textarea{min-height:100px;padding:12px 14px;border-radius:8px;font-size:13px;line-height:1.5;}

html body .de-modal .de-modal-foot{padding:16px 28px 24px;}
html body .de-modal .de-modal-foot .de-btn{padding:13px;border-radius:10px;font-size:15px;}
html body .de-modal .de-modal-foot .de-btn-cancel{padding:13px 22px;font-size:14px;font-weight:600;}
html body .de-modal .de-modal-foot .de-btn-submit{box-shadow:none;font-weight:700;}

@media(max-width:768px){html body .de-modal .de-table col.c-rate{width:46px;}html body .de-modal .de-rate label{width:30px;height:28px;}}
@media(max-width:700px){html body .de-modal .de-modal-body,html body .de-modal .de-modal-head,html body .de-modal .de-modal-foot{padding-left:18px;padding-right:18px;}}
</style>
