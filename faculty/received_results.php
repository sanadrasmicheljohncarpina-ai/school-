<?php
/**
 * faculty/received_results.php
 *
 * Shared "Evaluation Received" page body for the Faculty and Staff portals.
 * Same layout as the Principal's Evaluation Received page:
 *   overview cards (Overall Average, Evaluations Received, Highest / Lowest-Rated Area),
 *   "Performance by Category" bars, and one filterable "Evaluations Received" list
 *   (term picker, sort, "With comments only") that opens the existing details modal,
 *   plus "Generate Report": the cumulative, anonymous report (per-question averages, Rating
 *   Legend, Total Score, comments) drawn in place of the list, with a Print button.
 *
 * Each portal keeps its own accent colour: everything here is driven by --teal
 * (Faculty = blue, Staff = green), so no colour is hard-coded to one portal.
 *
 * Used from faculty_dashboard.php and staff_dashboard.php:
 *     <?php render_received_results($mysqli, (int)$user_id); ?>
 *
 * The dashboards already provide openEvalDetails() and the details modal
 * (get_evaluation_details.php); this file reuses them.
 *
 * -- CONFIDENTIALITY ---------------------------------------------------------
 * Evaluator identity is never selected, joined or shown. Responses are labeled only
 * "Evaluation #1", "#2", ... in submission order. Keep it that way.
 */

require_once __DIR__ . '/kept_feedback.php';

if (!function_exists('render_received_results')) {

    function rr_rows(mysqli $db, string $sql, string $types = '', array $params = []): array {
        try {
            $stmt = $db->prepare($sql);
            if (!$stmt) return [];
            if ($types !== '') { $stmt->bind_param($types, ...$params); }
            $stmt->execute();
            $res  = $stmt->get_result();
            $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
            $stmt->close();
            return $rows;
        } catch (Throwable $e) {
            return [];
        }
    }

    function rr_num($v): string {
        return rtrim(rtrim(number_format((float)$v, 2), '0'), '.');
    }

    /** Placeholder comments such as "N/A" or "none" count as no written comment. */
    function rr_clean_comment($v): string {
        $t = trim((string)$v);
        if ($t === '' || preg_match('/^(n\/?a|none|nil|no comments?|no feedback|not applicable|[\s.\-–—]+)$/iu', $t)) return '';
        return $t;
    }

    function rr_score_class($score): string {
        if ($score === null) return 'none';
        if ($score >= 4) return 'high';
        if ($score >= 3) return 'mid';
        return 'low';
    }

    function rr_score_color($s): string {
        if ($s === null) return '#6b7280';
        if ($s >= 4.5) return '#32B98A';
        if ($s >= 3.5) return '#6BCFA9';
        if ($s >= 2.5) return '#facc15';
        if ($s >= 1.5) return '#fb923c';
        return '#E6788A';
    }

    function rr_sat_label($s): string {
        if ($s === null) return '—';
        if ($s >= 4.5) return 'Very Satisfied';
        if ($s >= 3.5) return 'Satisfied';
        if ($s >= 2.5) return 'Neither Satisfied nor Dissatisfied';
        if ($s >= 1.5) return 'Dissatisfied';
        return 'Very Dissatisfied';
    }

    /**
     * GENERATE REPORT — cumulative, fully anonymous report for the logged-in Faculty/Staff.
     * Drawn inside the normal portal layout in place of the list. $c carries the evaluations
     * already filtered by the page (selected term or all terms):
     *   live (tracker ids), kept (answers_json of archived copies), evals, dates, comments,
     *   term (label), back (url).
     * No evaluator identity is read here; evaluators are never listed individually.
     */
    function rr_render_report(mysqli $db, int $userId, array $c): void {
        $h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

        // Per-question aggregation, merging identical wording (old + new question ids).
        $qAgg = []; $ord = 0;
        $add = function ($cat, $text, $sum, $cnt) use (&$qAgg, &$ord) {
            $cat  = trim((string)$cat) !== '' ? trim((string)$cat) : 'General';
            $text = trim((string)$text) !== '' ? trim((string)$text) : 'Question';
            $key  = mb_strtolower($text);
            if (!isset($qAgg[$key])) { $qAgg[$key] = ['cat' => $cat, 'text' => $text, 'sum' => 0.0, 'cnt' => 0, 'ord' => $ord++]; }
            $qAgg[$key]['sum'] += (float)$sum;
            $qAgg[$key]['cnt'] += (int)$cnt;
        };
        if ($c['live']) {
            $in = implode(',', array_map('intval', $c['live']));
            foreach (rr_rows($db, "
                SELECT COALESCE(eq.category, uq.category, 'General') AS category,
                       COALESCE(eq.question_text, uq.question_text,
                                CONCAT('Question #', COALESCE(qa.question_id, qa.user_question_id, qa.id))) AS question_text,
                       MIN(qa.id) AS first_id,
                       SUM(qa.answer_score) AS s, COUNT(qa.answer_score) AS c
                FROM questionnaire_answers qa
                JOIN evaluation_tracker et ON et.id = qa.tracker_id AND et.target_user_id = ?
                LEFT JOIN evaluation_questions eq ON qa.question_source='evaluation' AND eq.id = qa.question_id
                LEFT JOIN user_questions uq ON qa.question_source='user' AND uq.id = qa.user_question_id
                WHERE qa.tracker_id IN ($in) AND qa.answer_score IS NOT NULL
                GROUP BY 1, 2
                ORDER BY 1, first_id", 'i', [$userId]) as $r) {
                $add($r['category'], $r['question_text'], $r['s'], $r['c']);
            }
        }
        foreach ($c['kept'] as $json) {
            $list = json_decode((string)$json, true);
            if (!is_array($list)) continue;
            foreach ($list as $a) {
                if (!isset($a['score']) || $a['score'] === null) continue;
                $add($a['category'] ?? 'General', $a['question_text'] ?? 'Question', (float)$a['score'], 1);
            }
        }

        $byCat = []; $sumAll = 0.0; $nAll = 0;
        uasort($qAgg, fn($a, $b) => $a['ord'] <=> $b['ord']);
        foreach ($qAgg as $q) {
            if ($q['cnt'] < 1) continue;
            $byCat[$q['cat']][] = ['text' => $q['text'], 'avg' => round($q['sum'] / $q['cnt'], 2)];
            $sumAll += $q['sum']; $nAll += $q['cnt'];
        }
        // Overall = flat average of every individual answer (same method as the page's Overall Average).
        $overall = $nAll > 0 ? round($sumAll / $nAll, 2) : null;

        $dates = $c['dates'];
        if ($dates) {
            $dFrom = min($dates); $dTo = max($dates);
            $dateText = date('Y-m-d', $dFrom) === date('Y-m-d', $dTo) ? date('M j, Y', $dTo) : 'Earliest: ' . date('M j, Y', $dFrom) . "\n" . 'Latest: ' . date('M j, Y', $dTo);
        } else { $dateText = '—'; }

        // Date Evaluated: same rule as the Dean's Evaluation Received report. A single term shows its
        // open/close schedule (current term: System Settings; past term: the dates saved on that term);
        // otherwise the submission dates above (date only, never time of day).
        $fmtSched = static function (string $v): string {
            $v = trim($v);
            if ($v === '') return '';
            try {
                $hasTime = (bool)preg_match('/\d{1,2}:\d{2}/', $v);
                return (new DateTime($v, new DateTimeZone('Asia/Manila')))->format($hasTime ? 'M j, Y, g:i A' : 'M j, Y');
            } catch (Exception $e) { return ''; }
        };
        $selTerm = (int)($c['sel'] ?? 0);
        if ($selTerm > 0) {
            $schedOpen = ''; $schedClose = '';
            if (!empty($c['is_current'])) {
                $sched = ['eval_start' => '', 'eval_end' => ''];
                foreach (rr_rows($db, "SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('eval_start','eval_end')") as $sr) {
                    $sched[$sr['setting_key']] = trim((string)$sr['setting_value']);
                }
                $schedOpen = $fmtSched($sched['eval_start']); $schedClose = $fmtSched($sched['eval_end']);
            } else {
                $pr = rr_rows($db, "SELECT * FROM evaluation_periods WHERE id = ? LIMIT 1", 'i', [$selTerm])[0] ?? null;
                if ($pr) {
                    $schedOpen  = $fmtSched((string)($pr['eval_start_at'] ?? $pr['date_start'] ?? $pr['start_date'] ?? ''));
                    $schedClose = $fmtSched((string)($pr['eval_end_at']   ?? $pr['date_end']   ?? $pr['end_date']   ?? ''));
                }
            }
            if ($schedOpen !== '' && $schedClose !== '') $dateText = 'Open: ' . $schedOpen . "\n" . 'Close: ' . $schedClose;
        }

        $isStaff = (($_SESSION['role'] ?? '') === 'staff');
        $name    = (string)($GLOBALS['full_name'] ?? ($_SESSION['full_name'] ?? ''));
        $desig   = trim((string)($GLOBALS['designation'] ?? ($_SESSION['designation'] ?? '')));
        $photo   = null;
        if (!empty($GLOBALS['photo_url'])) { $photo = (string)$GLOBALS['photo_url']; }
        elseif (!empty($GLOBALS['faculty_photo']) && defined('UPLOAD_URL')) { $photo = UPLOAD_URL . $GLOBALS['faculty_photo']; }
        $evalCount = count($c['evals']);
        ?>
<style>
/* ── Generate Report — uses the portal's own theme variables (blue = Faculty, green = Staff) ── */
.rr-rep{--panel:var(--mid);--line:var(--border);--text:var(--light);color:var(--text);font-family:'DM Sans',Inter,Segoe UI,Arial,sans-serif}
.rr-rep *{box-sizing:border-box}
.rr-rep .top-actions{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:16px}
.rr-rep .back-btn,.rr-rep .btn-print{display:inline-flex;align-items:center;gap:8px;padding:9px 18px;border-radius:10px;font:700 13px 'DM Sans',sans-serif;text-decoration:none;cursor:pointer}
.rr-rep .back-btn,.rr-rep .back-btn:link,.rr-rep .back-btn:visited{background:var(--panel);border:1px solid var(--line);color:var(--text)}
.rr-rep .btn-print{background:var(--teal);border:0;color:#fff}
.rr-rep .btn-print:hover{opacity:.9}
.rr-rep .cum-topbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;font-size:12px;margin-bottom:10px;padding:8px 14px;border:1px solid var(--line);background:var(--inner)}
.rr-rep .cum-type{font-weight:700;text-transform:uppercase;letter-spacing:.8px}
.rr-rep .sheet-header{background:var(--panel);border:1px solid var(--line);padding:14px 18px;margin-bottom:12px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}
.rr-rep .sheet-avatar,.rr-rep .sheet-avatar-ph{width:56px;height:56px;border-radius:50%;object-fit:cover;border:2px solid var(--line);flex-shrink:0}
.rr-rep .sheet-avatar-ph{background:var(--inner);display:flex;align-items:center;justify-content:center;color:var(--muted)}
.rr-rep .sheet-name{font-family:'Rajdhani',sans-serif;font-size:22px;font-weight:700}
.rr-rep .sheet-desig{font-size:12.5px;color:var(--muted);margin-top:2px}
.rr-rep .cum-date{margin-left:auto;text-align:right}
.rr-rep .cum-date-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);margin-bottom:2px}
.rr-rep .cum-date-val{font-size:14px;font-weight:700}
.rr-rep .cat-section{margin-bottom:16px}
.rr-rep .cat-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1.2px;margin-bottom:8px;padding-bottom:5px;border-bottom:1px solid var(--line)}
.rr-rep table.q-table{width:100%;border-collapse:collapse;background:var(--panel);border:1px solid var(--line)}
.rr-rep .q-table thead tr{background:var(--inner)}
.rr-rep .q-table th{padding:9px 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);text-align:left}
.rr-rep .q-table th.rating-col{text-align:center;width:150px}
.rr-rep .q-table td{padding:10px 14px;border-top:1px solid var(--line);font-size:13px;line-height:1.45;vertical-align:middle}
.rr-rep .q-table td.q-num{width:44px;font-weight:700;color:var(--muted)}
.rr-rep .q-table td.rating-cell{text-align:center}
.rr-rep .rating-badge{display:inline-flex;align-items:center;justify-content:center;width:110px;height:36px;border-radius:8px;background:var(--inner)}
.rr-rep .rating-num{font-size:15px;font-weight:700}
.rr-rep .avg-summary{background:var(--panel);border:1px solid var(--line);padding:16px 22px;display:flex;flex-direction:column;align-items:flex-start;justify-content:flex-start;gap:12px;margin-bottom:12px}
.rr-rep .cum-total-label{font-size:14px;font-weight:700;text-transform:uppercase;letter-spacing:1px}
.rr-rep .avg-summary .cum-total-label{flex:0 0 auto}
.rr-rep .cum-total-result{text-align:left}
.rr-rep .cum-legend-rows{display:flex;flex-direction:column;gap:5px;min-width:0}
.rr-rep .cum-legend-row{display:flex;align-items:center;gap:10px;font-size:13px}
.rr-rep .cum-legend-dot{width:12px;height:12px;border-radius:3px;flex-shrink:0;background-color:var(--c);-webkit-print-color-adjust:exact;print-color-adjust:exact}
.rr-rep .cum-legend-range{font-weight:700;width:92px;flex-shrink:0}
.rr-rep .cum-legend-text{flex:0 1 auto;text-align:left}
.rr-rep .avg-score-big{font-family:'Rajdhani',sans-serif;font-size:46px;font-weight:700;line-height:1}
.rr-rep .avg-score-label{font-size:13px;font-weight:700;margin-top:4px}
.rr-rep .avg-out-of{font-size:12px;color:var(--muted);margin-top:4px}
.rr-rep .comment-section{background:var(--panel);border:1px solid var(--line);padding:16px 20px;margin-bottom:20px}
.rr-rep .comment-title{font-size:13px;font-weight:700;margin-bottom:10px;display:flex;align-items:center;gap:8px}
.rr-rep .cum-remark{font-size:13.5px;line-height:1.55;font-style:italic;margin-bottom:8px;white-space:pre-wrap}
.rr-rep .no-comment{font-size:13px;color:var(--muted);font-style:italic}
.rr-rep .empty-rep{text-align:center;padding:30px;border:1px solid var(--line);background:var(--panel);font-style:italic}
.rr-rep .print-row{display:flex;justify-content:center;margin:6px 0 18px}
@media(max-width:640px){.rr-rep .avg-summary .cum-total-label{flex:0 0 auto}.rr-rep .avg-summary,.rr-rep .cum-legend{flex-direction:column;align-items:flex-start}.rr-rep .cum-legend-rows{min-width:0;width:100%}.rr-rep .cum-total-result{text-align:left}}
@media print{
  html,body{background:#fff!important}
  .sidebar,.top-nav,.hamburger,.toast,.no-print,.eval-modal-overlay{display:none!important}
  .main{margin:0!important;padding:6mm 10mm!important;min-height:0!important;background:#fff!important}
  .rr-rep,.rr-rep *{color:#000!important;background-color:transparent!important;border-color:#ddd!important;box-shadow:none!important}
  .rr-rep .cum-topbar{background:#f5f5f5!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .rr-rep .cat-section{break-inside:auto}.rr-rep .cat-title{break-after:avoid}
  .rr-rep .q-table thead{display:table-header-group}.rr-rep .q-table tr{break-inside:avoid}
  .rr-rep .q-table td{padding:5px 12px!important}
  .rr-rep .rating-badge{height:26px!important;width:70px!important;border:1px solid #ccc}
  .rr-rep .cum-legend-rows{gap:1px!important;min-width:300px!important}
  .rr-rep .cum-legend-row{font-size:11px!important;gap:6px!important}
  .rr-rep .cum-legend-dot{width:9px!important;height:9px!important;background-color:var(--c)!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .rr-rep .cum-legend-range{width:74px!important}
  .rr-rep .avg-summary,.rr-rep .comment-section{break-inside:avoid}
  .rr-rep .cum-comments-page{break-before:page;padding-top:14mm}
}
</style>

<div class="rr-rep">
<div class="top-actions no-print">
    <a href="<?= $h($c['back']) ?>" class="back-btn"><i class="fa-solid fa-arrow-left"></i> Back to Evaluations Received</a>
</div>

<div class="cum-topbar">
    <span class="cum-type"><?= $isStaff ? 'Staff' : 'Faculty' ?> Evaluation</span>
    <span>Evaluated by: <strong><?= (int)$evalCount ?> Evaluator<?= $evalCount === 1 ? '' : 's' ?></strong> · <?= $h($c['term']) ?></span>
</div>

<div class="sheet-header">
    <?php if ($photo): ?><img class="sheet-avatar" src="<?= $h($photo) ?>" alt=""/>
    <?php else: ?><div class="sheet-avatar-ph"><i class="fa-solid fa-user"></i></div><?php endif; ?>
    <div>
        <div class="sheet-name"><?= $h($name) ?></div>
        <div class="sheet-desig"><?= $h($desig !== '' ? $desig : ($isStaff ? 'Staff' : 'Faculty')) ?></div>
    </div>
    <div class="cum-date">
        <div class="cum-date-label">Date Evaluated</div>
        <div class="cum-date-val"><?= nl2br($h($dateText)) ?></div>
    </div>
</div>

<?php $num = 1; foreach ($byCat as $cat => $rows): ?>
<div class="cat-section">
    <div class="cat-title"><i class="fa-solid fa-layer-group" style="font-size:10px"></i> <?= $h($cat) ?></div>
    <table class="q-table">
        <thead><tr><th style="width:44px"></th><th></th><th class="rating-col">Avg. Rating</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): $col = rr_score_color($row['avg']); ?>
        <tr>
            <td class="q-num"><?= $num++ ?></td>
            <td><?= $h($row['text']) ?></td>
            <td class="rating-cell"><div class="rating-badge" style="border:1px solid <?= $col ?>33"><span class="rating-num" style="color:<?= $col ?>"><?= number_format($row['avg'], 2) ?></span></div></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endforeach; ?>

<?php if (!$byCat): ?><div class="empty-rep">No scored answers found for this selection.</div><?php endif; ?>


<?php if ($overall !== null): ?>
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
        <div class="avg-score-big" style="color:<?= rr_score_color($overall) ?>"><?= number_format($overall, 2) ?></div>
        <div class="avg-score-label" style="color:<?= rr_score_color($overall) ?>"><?= $h(rr_sat_label($overall)) ?></div>
        <div class="avg-out-of">Overall cumulative rating · out of 5.00</div>
    </div>
</div>
<?php endif; ?>

<div class="cum-comments-page">
    <div class="comment-section">
        <div class="comment-title"><i class="fa-solid fa-comment-dots"></i> Comments, Suggestions &amp; Areas for Improvement</div>
        <?php if ($c['comments']): foreach ($c['comments'] as $rc): ?>
        <div class="cum-remark">&ldquo;<?= $h($rc) ?>&rdquo;</div>
        <?php endforeach; else: ?>
        <div class="no-comment">No comments or suggestions were provided.</div>
        <?php endif; ?>
    </div>
</div>

</div>
<?php
    }

    function render_received_results(mysqli $db, int $userId): void {
        $h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

        // Current term (the dashboards already loaded the active period into $period).
        $curPeriodId = (int)($GLOBALS['period']['id'] ?? 0);
        if ($curPeriodId <= 0) {
            $r = rr_rows($db, "SELECT id FROM evaluation_periods WHERE is_active=1 LIMIT 1");
            $curPeriodId = (int)($r[0]['id'] ?? 0);
        }

        // ── LIVE + KEPT responses, identity-free ───────────────────────────────
        $items = [];
        $live = rr_rows($db, "
            SELECT et.id, et.period_id, et.remarks AS comment, et.submitted_at,
                   ep.period_label, ep.semester, ep.school_year,
                   (SELECT SUM(qa.answer_score)   FROM questionnaire_answers qa WHERE qa.tracker_id = et.id) AS score_sum,
                   (SELECT COUNT(qa.answer_score) FROM questionnaire_answers qa WHERE qa.tracker_id = et.id) AS score_count
            FROM evaluation_tracker et
            LEFT JOIN evaluation_periods ep ON ep.id = et.period_id
            WHERE et.target_user_id = ? AND et.status NOT IN ('draft','in_progress')", 'i', [$userId]);
        foreach ($live as $r) {
            $items[] = ['arg' => (string)(int)$r['id'], 'live_id' => (int)$r['id'], 'pid' => (int)$r['period_id'], 'comment' => (string)($r['comment'] ?? ''),
                'submitted_at' => $r['submitted_at'], 'sum' => (float)($r['score_sum'] ?? 0), 'cnt' => (int)($r['score_count'] ?? 0),
                'school_year' => $r['school_year'] ?? '', 'semester' => $r['semester'] ?? '', 'period_label' => $r['period_label'] ?? ''];
        }
        foreach (kept_feedback_rows($db, $userId, true) as $r) {
            $items[] = ['arg' => "'k" . (int)$r['keep_id'] . "'", 'answers_json' => (string)($r['answers_json'] ?? ''), 'pid' => (int)($r['period_id'] ?? 0), 'comment' => (string)($r['remarks'] ?? ''),
                'submitted_at' => $r['submitted_at'], 'sum' => (float)$r['score_sum'], 'cnt' => (int)$r['score_count'],
                'school_year' => $r['school_year'] ?? '', 'semester' => $r['semester'] ?? '', 'period_label' => $r['period_label'] ?? '',
                'kept' => true];
        }

        // Live rows' arg is a bare number; kept rows' arg is already quoted ('k12').
        // ── TERMS (dropdown) ───────────────────────────────────────────────────
        $termIds = [];
        if ($curPeriodId > 0) $termIds[$curPeriodId] = true;
        foreach ($items as $it) { if ($it['pid'] > 0) $termIds[$it['pid']] = true; }

        $terms = [];
        if ($termIds) {
            $in = implode(',', array_map('intval', array_keys($termIds)));
            foreach (rr_rows($db, "SELECT * FROM evaluation_periods WHERE id IN ($in)") as $ep) {
                $terms[(int)$ep['id']] = ['id' => (int)$ep['id'], 'school_year' => $ep['school_year'] ?? '',
                    'period_label' => $ep['period_label'] ?? ($ep['semester'] ?? ''), 'date_start' => $ep['date_start'] ?? ''];
            }
            // A period that no longer exists can still have kept feedback — use its snapshot labels.
            foreach (array_keys($termIds) as $pid) {
                if (isset($terms[$pid])) continue;
                $snap = ['school_year' => '', 'period_label' => '', 'date_start' => ''];
                foreach ($items as $it) {
                    if ($it['pid'] === $pid) { $snap['school_year'] = $it['school_year']; $snap['period_label'] = $it['period_label'] ?: $it['semester']; break; }
                }
                $terms[$pid] = ['id' => $pid] + $snap;
            }
            $keptPids = [];
            foreach ($items as $it) { if (!empty($it['kept'])) $keptPids[$it['pid']] = true; }
            foreach ($terms as $pid => &$t) {
                $t['current']  = ($curPeriodId > 0 && $pid === $curPeriodId);
                $t['archived'] = isset($keptPids[$pid]);
                $t['label']    = ($t['school_year'] !== '' && $t['period_label'] !== '')
                    ? $t['school_year'] . ' — ' . $t['period_label']
                    : ($t['period_label'] !== '' ? $t['period_label'] : ($t['school_year'] !== '' ? $t['school_year'] : 'Period #' . $pid));
            }
            unset($t);
            uasort($terms, function ($a, $b) {
                if ($a['current'] !== $b['current']) return $a['current'] ? -1 : 1;
                $c = strcmp((string)$b['date_start'], (string)$a['date_start']);
                return $c ?: ($b['id'] <=> $a['id']);
            });
        }

        $req    = $_GET['period'] ?? 'all';
        $allSel = !is_string($req) || $req === '' || strtolower(trim($req)) === 'all';
        $sel    = $allSel ? 0 : (int)$req;
        if (!$allSel && !isset($terms[$sel])) { $allSel = true; $sel = 0; }

        $allItems = $items;   // kept for the "vs previous term" trend
        if (!$allSel) $items = array_values(array_filter($items, fn($it) => $it['pid'] === $sel));

        // Submission order only — never evaluator identity.
        usort($items, function ($a, $b) {
            $ta = $a['submitted_at'] ? strtotime($a['submitted_at']) : 0;
            $tb = $b['submitted_at'] ? strtotime($b['submitted_at']) : 0;
            return $ta <=> $tb;
        });

        // Responses per term, used for the anonymity threshold (RR_MIN_RESPONSES).
        $termCounts = [];
        foreach ($items as $it) { $termCounts[$it['pid']] = ($termCounts[$it['pid']] ?? 0) + 1; }
        $repExcluded = 0;

        $history = []; $totSum = 0.0; $totCnt = 0; $n = 1;
        $scoredCount = 0; $commentCount = 0; $liveIds = []; $keptAnswers = [];
        $repLive = []; $repKept = []; $repEvals = []; $repDates = []; $repComments = [];
        foreach ($items as $it) {
            $totSum += $it['sum']; $totCnt += $it['cnt'];
            $clean = rr_clean_comment($it['comment']);
            $score = $it['cnt'] > 0 ? round($it['sum'] / $it['cnt'], 2) : null;
            $hidden = (($termCounts[$it['pid']] ?? 0) < RR_MIN_RESPONSES);
            if ($score !== null) $scoredCount++;
            if ($clean !== '') $commentCount++;
            // Generate Report counts only evaluations that carry a score.
            if ($score !== null && $hidden) { $repExcluded++; }
            if ($score !== null && !$hidden) {
                $tsRow = $it['submitted_at'] ? (int)strtotime(date('Y-m-d', strtotime($it['submitted_at']))) : 0;
                $repEvals[] = ['ts' => $tsRow, 'score' => (float)$score];
                if (!empty($it['kept'])) { $repKept[] = (string)($it['answers_json'] ?? ''); }
                elseif (!empty($it['live_id'])) { $repLive[] = (int)$it['live_id']; }
                if ($tsRow) $repDates[] = $tsRow;
                if ($clean !== '') $repComments[] = $clean;
            }
            if (!empty($it['kept'])) { $keptAnswers[] = $it['answers_json'] ?? ''; }
            elseif (!empty($it['live_id'])) { $liveIds[] = (int)$it['live_id']; }
            $history[] = [
                'arg'     => $it['arg'],
                'idx'     => $n,
                'label'   => 'Evaluation #' . $n++,
                'score'   => $score,
                'comment' => $hidden ? '' : $clean,
                'hidden'  => $hidden,
                'ts'      => $it['submitted_at'] ? (int)strtotime(date('Y-m-d', strtotime($it['submitted_at']))) : 0,   // day only
                'when'    => $it['submitted_at'] ? date('M d, Y', strtotime($it['submitted_at'])) : 'Unknown date',   // no time of day
                'period'  => (!empty($it['school_year']) && !empty($it['semester'])) ? ($it['school_year'] . ' · ' . $it['semester']) : ($it['period_label'] ?: $it['semester']),
            ];
        }
        $respCount = count($history);
        $overall   = $totCnt > 0 ? round($totSum / $totCnt, 2) : null;
        $starsOn   = $overall !== null ? (int)round($overall) : 0;
        $scopeLabel = $allSel ? 'All Terms' : 'This Term';

        // ── CATEGORY AVERAGES (aggregates only, no evaluator identity) ─────────
        $catAgg = [];
        if ($liveIds) {
            $inLive = implode(',', array_map('intval', $liveIds));
            foreach (rr_rows($db, "
                SELECT COALESCE(uq.category, eq.category, 'General') AS category,
                       SUM(qa.answer_score) AS s, COUNT(qa.answer_score) AS c
                FROM questionnaire_answers qa
                JOIN evaluation_tracker et ON et.id = qa.tracker_id AND et.target_user_id = ?
                LEFT JOIN user_questions uq ON qa.question_source='user' AND uq.id = qa.user_question_id
                LEFT JOIN evaluation_questions eq ON qa.question_source='evaluation' AND eq.id = qa.question_id
                WHERE qa.tracker_id IN ($inLive)
                GROUP BY COALESCE(uq.category, eq.category, 'General')", 'i', [$userId]) as $r) {
                $cat = trim((string)$r['category']) !== '' ? trim((string)$r['category']) : 'General';
                if (!isset($catAgg[$cat])) $catAgg[$cat] = [0.0, 0];
                $catAgg[$cat][0] += (float)$r['s'];
                $catAgg[$cat][1] += (int)$r['c'];
            }
        }
        foreach ($keptAnswers as $json) {
            $list = json_decode($json, true);
            if (!is_array($list)) continue;
            foreach ($list as $row) {
                if (!isset($row['score']) || $row['score'] === null) continue;
                $cat = trim((string)($row['category'] ?? '')) !== '' ? trim((string)$row['category']) : 'General';
                if (!isset($catAgg[$cat])) $catAgg[$cat] = [0.0, 0];
                $catAgg[$cat][0] += (float)$row['score'];
                $catAgg[$cat][1] += 1;
            }
        }
        $catStats = [];
        foreach ($catAgg as $name => $v) { if ($v[1] > 0) $catStats[] = ['name' => (string)$name, 'avg' => round($v[0] / $v[1], 2)]; }
        usort($catStats, fn($a, $b) => $b['avg'] <=> $a['avg']);
        $topCat = $catStats[0] ?? null;
        $lowCat = count($catStats) > 1 ? $catStats[count($catStats) - 1] : null;

        // ── CHANGE VS THE PREVIOUS TERM (only when one whole term is selected) ──
        $trendText = ''; $trendClass = ''; $prevLabel = '';
        if (!$allSel && $overall !== null) {
            $keys = array_keys($terms);
            $pos  = array_search($sel, $keys, true);
            if ($pos !== false && isset($keys[$pos + 1])) {
                $prevId = (int)$keys[$pos + 1]; $pS = 0.0; $pC = 0;
                foreach ($allItems as $it) { if ($it['pid'] === $prevId) { $pS += $it['sum']; $pC += $it['cnt']; } }
                if ($pC > 0) {
                    $d = round($overall - ($pS / $pC), 2);
                    $prevLabel = $terms[$prevId]['label'];
                    if ($d > 0)      { $trendClass = 'up';   $trendText = '▲ +' . number_format($d, 2); }
                    elseif ($d < 0)  { $trendClass = 'down'; $trendText = '▼ -' . number_format(abs($d), 2); }
                    else             { $trendClass = 'flat'; $trendText = 'No change'; }
                }
            }
        }

        $displayList = array_reverse($history);   // newest first by default
        $periodQs  = $allSel ? 'all' : (string)$sel;
        $termLabel = (!$allSel && isset($terms[$sel])) ? $terms[$sel]['label'] : 'All Evaluation Terms';

        // ── GENERATE REPORT: drawn in place of the list, inside the normal portal layout ──
        if (($_GET['report'] ?? '') === '1') {
            rr_render_report($db, $userId, [
                'live' => $repLive, 'kept' => $repKept, 'evals' => $repEvals, 'dates' => $repDates,
                'comments' => $repComments, 'term' => $termLabel,
                'sel' => $allSel ? 0 : $sel, 'is_current' => (!$allSel && !empty($terms[$sel]['current'])), 'excluded' => $repExcluded,
                'back' => '?page=my_results&period=' . $periodQs,
            ]);
            return;
        }
        ?>
<style>
/* ── Evaluation Received — same layout as the Principal page ──────────────────
   Shared by the Faculty and Staff portals. Every accent comes from --teal, so
   Faculty stays blue and Staff stays green. Neutral surfaces use the portal's own
   --mid / --inner / --border / --light / --muted, so light and dark themes both work. */
.rr-shell{--rr-card:var(--mid);--rr-line:var(--border);--rr-soft:color-mix(in srgb,var(--teal) 14%,transparent)}

.rr-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px;margin-bottom:20px}
.rr-stat{display:flex;align-items:flex-start;gap:14px;background:var(--rr-card);border:1px solid var(--rr-line);border-radius:14px;padding:16px 18px}
.rr-stat-icon{flex:0 0 40px;width:40px;height:40px;border-radius:12px;background:var(--rr-soft);color:var(--teal);display:flex;align-items:center;justify-content:center;font-size:17px}
.rr-stat-body{min-width:0}
.rr-num{font-size:28px;line-height:1.1;font-weight:700;color:var(--light)}
.rr-num small{font-size:14px;font-weight:600;color:var(--muted);margin-left:4px}
.rr-lbl{font-size:12.5px;color:var(--muted);margin-top:4px}
.rr-sub{font-size:12px;color:var(--muted);margin-top:6px;line-height:1.5;overflow-wrap:anywhere}
.rr-stars{letter-spacing:2px;font-size:14px;margin-right:6px}
.rr-stars span{color:rgba(148,163,184,.45)}
.rr-stars span.on{color:#F5B301}
.rr-trend{font-weight:700}
.rr-trend.up{color:#059669}.rr-trend.down{color:#DC2626}.rr-trend.flat{color:var(--muted)}

.rr-panel{background:var(--rr-card);border:1px solid var(--rr-line);border-radius:14px;padding:24px;margin-bottom:20px}
.rr-panel:last-child{margin-bottom:0}
.rr-head{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:14px}
.rr-head h2{margin:0;font-family:'Rajdhani',sans-serif;font-size:19px;font-weight:700;color:var(--teal);display:flex;align-items:center;gap:8px}
.rr-head-right{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.rr-report-btn,.rr-report-btn:link,.rr-report-btn:visited{display:inline-flex;align-items:center;gap:8px;padding:10px 18px;border-radius:10px;background:var(--teal);color:#fff;font:700 13px 'DM Sans',sans-serif;text-decoration:none;white-space:nowrap;transition:opacity .15s}
.rr-report-btn:hover{opacity:.88}
.rr-report-btn i{color:#fff}
.rr-count{font:700 12px 'DM Sans',sans-serif;background:var(--rr-soft);color:var(--teal);padding:2px 10px;border-radius:12px;margin-left:2px}
.rr-shown{font-size:12px;color:var(--muted)}

.rr-picker{display:flex;flex-direction:row;align-items:center;gap:12px;margin:0}
.rr-picker label{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);white-space:nowrap}
.rr-picker .sel-wrap{position:relative;display:inline-flex;align-items:center}
.rr-picker .sel-wrap i{position:absolute;right:15px;top:50%;transform:translateY(-50%);pointer-events:none;color:var(--light);font-size:12px}
.rr-picker select{appearance:none;-webkit-appearance:none;min-width:320px;max-width:100%;padding:9px 40px 9px 14px;border-radius:10px;border:1px solid var(--rr-line);background:var(--inner);color:var(--light);font:500 14px 'DM Sans',sans-serif;cursor:pointer}
.rr-picker select:hover,.rr-picker select:focus{border-color:var(--teal);outline:none}
.rr-picker select:focus{box-shadow:0 0 0 3px color-mix(in srgb,var(--teal) 22%,transparent)}

.rr-cat{display:grid;grid-template-columns:minmax(150px,230px) 1fr 70px;align-items:center;gap:16px;padding:10px 0}
.rr-cat-name{font-size:13.5px;font-weight:600;color:var(--light);line-height:1.35}
.rr-cat-track{height:10px;background:color-mix(in srgb,var(--muted) 22%,transparent);border-radius:8px;overflow:hidden}
.rr-cat-fill{height:100%;border-radius:8px}
.rr-cat-fill.high{background:linear-gradient(90deg,#059669,#34D399)}
.rr-cat-fill.mid{background:linear-gradient(90deg,#D97706,#FBBF24)}
.rr-cat-fill.low{background:linear-gradient(90deg,#DC2626,#F87171)}
.rr-cat-score{text-align:right;font-size:15px;font-weight:800;color:var(--light);white-space:nowrap}

.rr-toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding:12px 0 14px;border-top:1px solid var(--rr-line)}
.rr-controls{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.rr-select{padding:7px 12px;border-radius:9px;border:1px solid var(--rr-line);background:var(--inner);color:var(--light);font:500 13px 'DM Sans',sans-serif;cursor:pointer}
.rr-select:focus{outline:3px solid color-mix(in srgb,var(--teal) 28%,transparent);outline-offset:1px;border-color:var(--teal)}
.rr-chip{display:inline-flex;align-items:center;gap:7px;padding:7px 12px;border-radius:9px;border:1px solid var(--rr-line);background:var(--inner);font:500 13px 'DM Sans',sans-serif;color:var(--light);cursor:pointer;user-select:none}
.rr-chip input{accent-color:var(--teal)}

.rr-item{display:flex;justify-content:space-between;align-items:center;gap:18px;background:var(--inner);border:1px solid var(--rr-line);border-radius:12px;padding:14px 18px;margin-bottom:10px;cursor:pointer;transition:border-color .15s,box-shadow .15s}
.rr-item:last-child{margin-bottom:0}
.rr-item:hover,.rr-item:focus-visible{border-color:var(--teal);box-shadow:0 2px 12px color-mix(in srgb,var(--teal) 20%,transparent);outline:none}
.rr-main{min-width:0;flex:1}
.rr-eyebrow{font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);display:flex;align-items:center;gap:6px}
.rr-meta{font-size:12px;color:var(--muted);margin-top:3px}
.rr-comment{margin:8px 0 0;font-size:13.5px;line-height:1.55;font-style:italic;color:var(--light);display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.rr-comment.none{font-style:normal;color:var(--muted)}
.rr-side{display:flex;flex-direction:column;align-items:flex-end;gap:8px;flex-shrink:0}
.rr-badge{font-size:13px;font-weight:800;padding:4px 12px;border-radius:18px;border:1px solid transparent;white-space:nowrap}
.rr-badge small{font-size:11px;font-weight:600;opacity:.75}
.rr-badge.high{background:rgba(16,185,129,.12);color:#059669;border-color:rgba(16,185,129,.3)}
.rr-badge.mid{background:rgba(245,158,11,.14);color:#B45309;border-color:rgba(245,158,11,.35)}
.rr-badge.low{background:rgba(239,68,68,.12);color:#DC2626;border-color:rgba(239,68,68,.32)}
.rr-badge.none{background:rgba(100,116,139,.12);color:var(--muted);border-color:rgba(100,116,139,.25);font-size:12px;font-weight:700}
.rr-detail-btn{background:var(--rr-soft);border:1px solid color-mix(in srgb,var(--teal) 30%,transparent);color:var(--teal);font:700 11px 'DM Sans',sans-serif;padding:6px 12px;border-radius:18px;cursor:pointer}
.rr-detail-btn:hover{border-color:var(--teal)}
.rr-empty{margin:0;color:var(--muted);font-size:13px}

@media(max-width:640px){
  .rr-head-right{flex-direction:column;align-items:stretch;width:100%}
  .rr-report-btn{justify-content:center}
  .rr-picker{flex-direction:column;align-items:stretch;gap:5px;width:100%}
  .rr-picker .sel-wrap{width:100%}.rr-picker select{width:100%;min-width:0}
  .rr-item{flex-direction:column;align-items:flex-start;gap:12px}
  .rr-side{flex-direction:row;align-items:center;justify-content:space-between;width:100%}
  .rr-cat{grid-template-columns:1fr 60px}.rr-cat-name{grid-column:1/-1}.rr-cat-track{grid-column:1}
}
</style>

<div class="dashboard-shell rr-shell">

    <div class="rr-stats">
        <div class="rr-stat">
            <div class="rr-stat-icon"><i class="fa-solid fa-star"></i></div>
            <div class="rr-stat-body">
                <div class="rr-num"><?= $overall !== null ? $h(rr_num($overall)) : '—' ?><?php if ($overall !== null): ?><small>/ 5</small><?php endif; ?></div>
                <div class="rr-lbl">Average Rating · <?= $h($scopeLabel) ?></div>
                <?php if ($overall !== null): ?>
                <div class="rr-sub">
                    <span class="rr-stars" aria-hidden="true"><?php for ($i = 1; $i <= 5; $i++): ?><span class="<?= $i <= $starsOn ? 'on' : '' ?>">★</span><?php endfor; ?></span>
                    <?php if ($trendText !== ''): ?><span class="rr-trend <?= $h($trendClass) ?>"><?= $h($trendText) ?></span> vs <?= $h($prevLabel) ?><?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="rr-stat">
            <div class="rr-stat-icon"><i class="fa-solid fa-comments"></i></div>
            <div class="rr-stat-body">
                <div class="rr-num"><?= (int)$respCount ?></div>
                <div class="rr-lbl">Evaluations Received · <?= $h($scopeLabel) ?></div>
                <?php if ($respCount > 0): ?><div class="rr-sub"><?= (int)$scoredCount ?> scored · <?= (int)$commentCount ?> with comments</div><?php endif; ?>
            </div>
        </div>
        <?php if ($topCat): ?>
        <div class="rr-stat">
            <div class="rr-stat-icon"><i class="fa-solid fa-arrow-trend-up"></i></div>
            <div class="rr-stat-body">
                <div class="rr-num"><?= $h(rr_num($topCat['avg'])) ?><small>/ 5</small></div>
                <div class="rr-lbl">Highest-Rated Area</div>
                <div class="rr-sub"><?= $h($topCat['name']) ?></div>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($lowCat): ?>
        <div class="rr-stat">
            <div class="rr-stat-icon"><i class="fa-solid fa-arrow-trend-down"></i></div>
            <div class="rr-stat-body">
                <div class="rr-num"><?= $h(rr_num($lowCat['avg'])) ?><small>/ 5</small></div>
                <div class="rr-lbl">Lowest-Rated Area</div>
                <div class="rr-sub"><?= $h($lowCat['name']) ?></div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <section class="rr-panel" aria-label="Evaluations received">
        <div class="rr-head">
            <h2><i class="fa-solid fa-clock-rotate-left"></i> Evaluations Received <span class="rr-count"><?= (int)$respCount ?></span></h2>
            <div class="rr-head-right">
            <?php if ($history): ?>
            <a class="rr-report-btn" href="?page=my_results&amp;report=1&amp;period=<?= $h($periodQs) ?>"><i class="fa-solid fa-file-lines"></i> Generate Report</a>
            <?php endif; ?>
            <?php if ($terms): ?>
            <form method="get" class="rr-picker">
                <input type="hidden" name="page" value="my_results">
                <label for="rrTermSelect">Evaluation Term</label>
                <span class="sel-wrap">
                    <select id="rrTermSelect" name="period" onchange="this.form.submit()">
                        <option value="all"<?= $allSel ? ' selected' : '' ?>>All Evaluation Terms — Past &amp; Current</option>
                        <?php foreach ($terms as $t): ?>
                        <option value="<?= (int)$t['id'] ?>"<?= !$allSel && (int)$t['id'] === $sel ? ' selected' : '' ?>><?= $h($t['label']) ?><?= $t['current'] ? ' (Current)' : ($t['archived'] ? ' (Archived)' : '') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                </span>
            </form>
            <?php endif; ?>
            </div>
        </div>

        <?php if (!$history): ?>
            <p class="rr-empty"><?= $allSel ? 'No evaluations have been received yet.' : 'No evaluations received for this term.' ?></p>
        <?php else: ?>
        <div class="rr-toolbar">
            <div class="rr-controls">
                <select id="rrSort" class="rr-select" onchange="rrApply()" aria-label="Sort evaluations">
                    <option value="newest">Newest first</option>
                    <option value="oldest">Oldest first</option>
                    <option value="high">Highest score</option>
                    <option value="low">Lowest score</option>
                </select>
                <label class="rr-chip"><input type="checkbox" id="rrOnlyComments" onchange="rrApply()"> With comments only</label>
            </div>
            <span class="rr-shown" id="rrShown">Showing <?= (int)$respCount ?> of <?= (int)$respCount ?></span>
        </div>
        <div id="rrList">
            <?php foreach ($displayList as $e): $cls = rr_score_class($e['score']); $open = 'openEvalDetails(' . $e['arg'] . ')'; $hid = !empty($e['hidden']); ?>
            <div class="rr-item"<?= $hid ? ' style="cursor:default"' : ' role="button" tabindex="0"' ?>
                 data-ts="<?= (int)$e['ts'] ?>" data-idx="<?= (int)$e['idx'] ?>"
                 data-score="<?= (!$hid && $e['score'] !== null) ? $h((string)$e['score']) : '-1' ?>"
                 data-comment="<?= $e['comment'] !== '' ? '1' : '0' ?>"
                 <?php if (!$hid): ?>onclick="<?= $open ?>"
                 onkeydown="if(event.target===this&&(event.key==='Enter'||event.key===' ')){event.preventDefault();<?= $open ?>}"<?php endif; ?>>
                <div class="rr-main">
                    <div class="rr-eyebrow"><i class="fa-solid fa-user-secret"></i> Anonymous — <?= $h($e['label']) ?></div>
                    <div class="rr-meta"><?= $h($e['when']) ?><?= $e['period'] !== '' ? ' · ' . $h($e['period']) : '' ?></div>
                    <?php if ($hid): ?>
                        <p class="rr-comment none">Details hidden until this term has at least <?= (int)RR_MIN_RESPONSES ?> responses (protects evaluator anonymity).</p>
                    <?php elseif ($e['comment'] !== ''): ?>
                        <p class="rr-comment">&ldquo;<?= $h($e['comment']) ?>&rdquo;</p>
                    <?php else: ?>
                        <p class="rr-comment none">No written comment</p>
                    <?php endif; ?>
                </div>
                <div class="rr-side">
                    <?php if ($hid): ?>
                        <span class="rr-badge none">Hidden</span>
                    <?php elseif ($e['score'] !== null): ?>
                        <span class="rr-badge <?= $cls ?>"><?= $h(rr_num($e['score'])) ?><small> / 5</small></span>
                    <?php else: ?>
                        <span class="rr-badge none">No score</span>
                    <?php endif; ?>
                    <?php if (!$hid): ?><button type="button" class="rr-detail-btn" onclick="event.stopPropagation();<?= $open ?>">View Details <i class="fa-solid fa-chevron-right"></i></button><?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <p class="rr-empty" id="rrNoMatch" style="display:none;">No evaluations match this filter.</p>
        <?php endif; ?>
    </section>

</div>
<script>
function rrApply(){
    var list=document.getElementById('rrList'); if(!list) return;
    var items=[].slice.call(list.querySelectorAll('.rr-item'));
    var mode=document.getElementById('rrSort').value;
    var only=document.getElementById('rrOnlyComments').checked;
    var n=function(el,k){return parseFloat(el.dataset[k])||0;};
    var lowKey=function(el){var v=parseFloat(el.dataset.score);return v<0?Infinity:v;};
    items.sort(function(a,b){
        if(mode==='oldest') return n(a,'ts')-n(b,'ts')||n(a,'idx')-n(b,'idx');
        if(mode==='high')   return parseFloat(b.dataset.score)-parseFloat(a.dataset.score)||n(b,'ts')-n(a,'ts');
        if(mode==='low'){var x=lowKey(a),y=lowKey(b);return x===y?n(b,'ts')-n(a,'ts'):(x<y?-1:1);}
        return n(b,'ts')-n(a,'ts')||n(b,'idx')-n(a,'idx');
    });
    var shown=0;
    items.forEach(function(el){var ok=!only||el.dataset.comment==='1';el.style.display=ok?'':'none';if(ok)shown++;list.appendChild(el);});
    document.getElementById('rrShown').textContent='Showing '+shown+' of '+items.length;
    document.getElementById('rrNoMatch').style.display=shown?'none':'block';
}
</script>
<?php
    }
}