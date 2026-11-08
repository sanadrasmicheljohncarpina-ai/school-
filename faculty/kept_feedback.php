<?php
/**
 * faculty/kept_feedback.php
 *
 * Read-side helpers for the anonymous "Evaluations Received" copy that
 * admin/system_archive.php stores in portal_feedback_keep when the EA archives
 * an evaluation period.
 *
 * Archiving deletes the live evaluation_tracker / questionnaire_answers rows so the
 * next cycle starts clean. Faculty and staff must still be able to see the feedback
 * they received, so the dashboards combine live rows with these kept rows.
 *
 * Anonymity: the kept table never stores evaluator id / name / department, and nothing
 * in this file selects one.
 *
 * Every function is safe to call before the first archive: if portal_feedback_keep does
 * not exist yet, they simply return empty results and the dashboards behave as before.
 */

// Anonymity threshold: with fewer responses than this in a term, individual comments / scores /
// details stay hidden (a single response could otherwise point at one evaluator).
if (!defined('RR_MIN_RESPONSES')) define('RR_MIN_RESPONSES', 3);

if (!function_exists('kept_feedback_ready')) {

    /** True when the archive has created portal_feedback_keep. */
    function kept_feedback_ready(mysqli $db): bool {
        static $ready = null;
        if ($ready === null) {
            $r = $db->query("SHOW TABLES LIKE 'portal_feedback_keep'");
            $ready = $r && $r->num_rows > 0;
        }
        return $ready;
    }

    /**
     * Kept evaluations received by one user, newest first, shaped like the dashboards'
     * live rows (tracker_id, overall_score, eval_type, peer_group, submitted_at,
     * period_label, semester) plus keep_id, which marks the row as an archived copy.
     * A kept row is hidden if the same tracker is live again (archive restored).
     */
    function kept_feedback_rows(mysqli $db, int $userId, bool $withAnswers = false): array {
        if (!kept_feedback_ready($db)) return [];
        $cols = "k.id AS keep_id, k.tracker_id, k.eval_type, k.peer_group, k.submitted_at,
                 k.period_label, k.semester, k.school_year, k.score_sum, k.score_count, k.period_id, k.remarks"
              . ($withAnswers ? ", k.answers_json" : "");
        $stmt = $db->prepare("
            SELECT $cols
            FROM portal_feedback_keep k
            WHERE k.target_user_id = ?
              AND NOT EXISTS (
                    SELECT 1 FROM evaluation_tracker et
                    WHERE et.id = k.tracker_id
                      AND et.period_id = k.period_id
                      AND et.target_user_id = k.target_user_id)
            ORDER BY k.submitted_at DESC, k.id DESC
        ");
        if (!$stmt) return [];
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        foreach ($rows as &$r) {
            $cnt = (int)$r['score_count'];
            $r['overall_score'] = $cnt > 0 ? ((float)$r['score_sum'] / $cnt) : null;
        }
        unset($r);
        return $rows;
    }

    /** Totals over the kept answers: overall sum/count, evaluation count, per-category sums. */
    function kept_feedback_aggregate(mysqli $db, int $userId): array {
        $agg = ['sum' => 0.0, 'cnt' => 0, 'total' => 0, 'cats' => []];
        foreach (kept_feedback_rows($db, $userId, true) as $r) {
            $answers = json_decode((string)($r['answers_json'] ?? ''), true);
            if (!is_array($answers)) continue;
            $counted = false;
            foreach ($answers as $a) {
                if (!isset($a['score']) || $a['score'] === null) continue;
                $score = (float)$a['score'];
                $cat   = trim((string)($a['category'] ?? '')) ?: 'General';
                $agg['sum'] += $score;
                $agg['cnt']++;
                $agg['cats'][$cat]['sum'] = ($agg['cats'][$cat]['sum'] ?? 0.0) + $score;
                $agg['cats'][$cat]['cnt'] = ($agg['cats'][$cat]['cnt'] ?? 0) + 1;
                $counted = true;
            }
            // The live queries only count evaluations that have at least one answer.
            if ($counted) $agg['total']++;
        }
        return $agg;
    }

    /**
     * Combine the live totals the dashboards already query with the kept aggregate.
     * $liveCats rows need: category, sum_cat, cnt_cat.
     * Returns ['avg' => ?float, 'total' => int, 'scores' => [['category','avg_cat'], ...]].
     */
    function kept_feedback_combine(float $liveSum, int $liveCnt, int $liveTotal, array $liveCats, array $kept): array {
        $sum = $liveSum + $kept['sum'];
        $cnt = $liveCnt + $kept['cnt'];

        $cats = [];
        foreach ($liveCats as $c) {
            $name = trim((string)($c['category'] ?? '')) ?: 'General';
            $cats[$name]['sum'] = ($cats[$name]['sum'] ?? 0.0) + (float)($c['sum_cat'] ?? 0);
            $cats[$name]['cnt'] = ($cats[$name]['cnt'] ?? 0) + (int)($c['cnt_cat'] ?? 0);
        }
        foreach ($kept['cats'] as $name => $v) {
            $cats[$name]['sum'] = ($cats[$name]['sum'] ?? 0.0) + $v['sum'];
            $cats[$name]['cnt'] = ($cats[$name]['cnt'] ?? 0) + $v['cnt'];
        }
        $scores = [];
        foreach ($cats as $name => $v) {
            if ($v['cnt'] > 0) $scores[] = ['category' => $name, 'avg_cat' => $v['sum'] / $v['cnt']];
        }

        return [
            'avg'    => $cnt > 0 ? round($sum / $cnt, 2) : null,
            'total'  => $liveTotal + $kept['total'],
            'scores' => $scores,
        ];
    }

    /** Responses (live + kept) this user has received in one term. */
    function feedback_period_response_count(mysqli $db, int $userId, int $periodId): int {
        $n = 0;
        $st = $db->prepare("SELECT COUNT(*) FROM evaluation_tracker
                            WHERE target_user_id = ? AND period_id = ? AND status NOT IN ('draft','in_progress')");
        if ($st) { $st->bind_param('ii', $userId, $periodId); $st->execute(); $st->bind_result($n); $st->fetch(); $st->close(); }
        $n = (int)$n;
        foreach (kept_feedback_rows($db, $userId) as $r) { if ((int)($r['period_id'] ?? 0) === $periodId) $n++; }
        return $n;
    }

    /** Merge live and kept rows, newest first, optionally capped. */
    function kept_feedback_merge_lists(array $live, array $kept, ?int $limit = null): array {
        $all = array_merge($live, $kept);
        usort($all, fn($a, $b) => strcmp((string)($b['submitted_at'] ?? ''), (string)($a['submitted_at'] ?? '')));
        return $limit !== null ? array_slice($all, 0, $limit) : $all;
    }

    /**
     * Argument for openEvalDetails(): the tracker id for a live row, or 'k<keep id>' for an
     * archived copy. Kept rows use their own id so a reused tracker id can never collide.
     */
    function eval_detail_arg(array $row): string {
        return !empty($row['keep_id']) ? "'k" . (int)$row['keep_id'] . "'" : (string)(int)($row['tracker_id'] ?? 0);
    }
}
