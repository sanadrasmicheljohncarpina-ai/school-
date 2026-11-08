<?php
require_once 'principal_common.php';

$viewId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ═══════════════════════════════════════════════════════════
// DETAIL VIEW — a single teacher
// ═══════════════════════════════════════════════════════════
if ($viewId > 0) {
    $stmt = $mysqli->prepare("
        SELECT id, full_name, username, email, designation, photo, academic_level
        FROM users
        WHERE id=? AND role='teacher' AND academic_level IN ($scopeAcademicIn)
        LIMIT 1
    ");
    $stmt->bind_param("i", $viewId);
    $stmt->execute();
    $teacher = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$teacher) {
        html_head_open('PBI — Teacher Not Found');
        render_principal_sidebar('teachers', $me, $scopeLabel, $photo_src);
        ?>
        
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
            <a class="back-link" href="principal_teachers.php"><i class="fa-solid fa-arrow-left"></i> Back to Teachers</a>
            <div class="section"><p class="empty-note">That teacher wasn't found, or isn't within your scope.</p></div>
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

</body></html>
        <?php
        $mysqli->close();
        exit;
    }

    $tPhoto = !empty($teacher['photo']) ? '../image/' . $teacher['photo'] : '../image/pbi_logo';

    $assignedGrades = safe_rows($mysqli, "
        SELECT year_level FROM user_year_levels WHERE user_id=? ORDER BY year_level
    ", "i", [$viewId]);
    $assignedGradeList = array_column($assignedGrades, 'year_level');

    $completed = $period ? (int)(safe_scalar($mysqli, "
        SELECT COUNT(DISTINCT evaluator_id) c FROM evaluation_tracker
        WHERE eval_type='student' AND period_id=? AND target_user_id=?
    ", "ii", [$period_id_int, $viewId]) ?? 0) : 0;

    // Pool of possible evaluators = students in the teacher's assigned grade(s),
    // falling back to the principal's whole scope if no grades are assigned yet.
    $poolGrades = !empty($assignedGradeList) ? $assignedGradeList : $scopeGrades;
    $poolGradesIn = esc_list($mysqli, $poolGrades);
    $possibleEvaluators = (int)(safe_scalar($mysqli, "
        SELECT COUNT(*) c FROM users
        WHERE role='student' AND is_active=1 AND account_status='approved' AND grade_level IN ($poolGradesIn)
    ") ?? 0);
    $completionPct = $possibleEvaluators > 0 ? round($completed / $possibleEvaluators * 100) : 0;

    $avgRating = $period ? safe_scalar($mysqli, "
        SELECT AVG(qa.answer_score) v
        FROM questionnaire_answers qa
        INNER JOIN evaluation_tracker et ON et.id = qa.tracker_id
        WHERE et.eval_type='student' AND et.target_user_id=? AND et.period_id=?
    ", "ii", [$viewId, $period_id_int]) : safe_scalar($mysqli, "
        SELECT AVG(qa.answer_score) v
        FROM questionnaire_answers qa
        INNER JOIN evaluation_tracker et ON et.id = qa.tracker_id
        WHERE et.eval_type='student' AND et.target_user_id=?
    ", "i", [$viewId]);

    // Per-question breakdown, if a questionnaire_questions table exists.
    $questionBreakdown = safe_rows($mysqli, "
        SELECT qq.question_text AS question, AVG(qa.answer_score) avg_score, COUNT(qa.id) responses
        FROM questionnaire_answers qa
        INNER JOIN evaluation_tracker et ON et.id = qa.tracker_id
        INNER JOIN questionnaire_questions qq ON qq.id = qa.question_id
        WHERE et.eval_type='student' AND et.target_user_id=?" . ($period ? " AND et.period_id=?" : "") . "
        GROUP BY qq.id, qq.question_text
        ORDER BY qq.id
    ", $period ? "ii" : "i", $period ? [$viewId, $period_id_int] : [$viewId]);

    html_head_open('PBI — ' . ($teacher['full_name'] ?? 'Teacher'));
    render_principal_sidebar('teachers', $me, $scopeLabel, $photo_src);
    ?>
    <main class="main">
        <a class="back-link" href="principal_teachers.php"><i class="fa-solid fa-arrow-left"></i> Back to Teachers</a>

        <div class="section">
            <div class="profile-card">
                <img class="profile-photo-lg" src="<?= htmlspecialchars($tPhoto) ?>" alt="">
                <div>
                    <div class="page-title" style="font-size:22px;"><?= htmlspecialchars($teacher['full_name']) ?></div>
                    <div class="page-sub"><?= htmlspecialchars($teacher['designation'] ?? 'Teacher') ?> · <?= htmlspecialchars($teacher['email'] ?? '') ?></div>
                    <div class="page-sub">
                        <?= !empty($assignedGradeList) ? 'Grade ' . htmlspecialchars(implode(', ', $assignedGradeList)) : 'No grade level assigned yet' ?>
                    </div>
                </div>
            </div>

            <div class="tracker-grid">
                <div class="tracker-item"><div class="big"><?= $completionPct ?>%</div><div class="lbl">Evaluation Completion</div></div>
                <div class="tracker-item"><div class="big"><?= $completed ?> / <?= $possibleEvaluators ?></div><div class="lbl">Evaluations Received</div></div>
                <div class="tracker-item"><div class="big"><?= $avgRating !== null ? round((float)$avgRating, 2) : '—' ?></div><div class="lbl">Average Rating</div></div>
            </div>
        </div>

        <div class="section">
            <h2><i class="fa-solid fa-list-check"></i> Rating Breakdown by Question</h2>
            <?php if (empty($questionBreakdown)): ?>
                <p class="empty-note">No per-question breakdown is available yet for this teacher.</p>
            <?php else: ?>
                <table class="data">
                    <thead><tr><th>Question</th><th>Average Score</th><th>Responses</th></tr></thead>
                    <tbody>
                    <?php foreach ($questionBreakdown as $q): ?>
                        <tr>
                            <td><?= htmlspecialchars($q['question']) ?></td>
                            <td style="min-width:140px;">
                                <div class="bar-wrap"><div class="bar-fill" style="width:<?= min(100, round(((float)$q['avg_score']) / 5 * 100)) ?>%"></div></div>
                                <span style="font-size:11px;color:var(--muted);"><?= round((float)$q['avg_score'], 2) ?></span>
                            </td>
                            <td><?= (int)$q['responses'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </main>
    </body></html>
    <?php
    $mysqli->close();
    exit;
}

// ═══════════════════════════════════════════════════════════
// LIST VIEW — all teachers in scope, searchable / filterable
// ═══════════════════════════════════════════════════════════
$q  = trim($_GET['q'] ?? '');
$yr = trim($_GET['yr'] ?? '');

$types = '';
$params = [];
$joinYear = '';

if ($yr !== '' && in_array($yr, $scopeGrades, true)) {
    $joinYear = "INNER JOIN user_year_levels uyl ON uyl.user_id = u.id AND uyl.year_level = ?";
    $types .= 's';
    $params[] = $yr;
}

$sql = "
    SELECT u.id, u.full_name, u.photo FROM users u
    $joinYear
    WHERE u.role='teacher' AND u.is_active=1 AND u.account_status='approved'
      AND u.academic_level IN ($scopeAcademicIn)
";
if ($q !== '') {
    $sql .= " AND u.full_name LIKE ?";
    $types .= 's';
    $params[] = '%' . $q . '%';
}
$sql .= " ORDER BY u.full_name";
$teachers = safe_rows($mysqli, $sql, $types, $params);

$teacherRows = [];
foreach ($teachers as $row) {
    $tid = (int)$row['id'];
    $grades = array_column(safe_rows($mysqli, "SELECT year_level FROM user_year_levels WHERE user_id=? ORDER BY year_level", "i", [$tid]), 'year_level');

    $completed = $period ? (int)(safe_scalar($mysqli, "
        SELECT COUNT(DISTINCT evaluator_id) c FROM evaluation_tracker
        WHERE eval_type='student' AND period_id=? AND target_user_id=?
    ", "ii", [$period_id_int, $tid]) ?? 0) : 0;

    $avgRating = safe_scalar($mysqli, "
        SELECT AVG(qa.answer_score) v
        FROM questionnaire_answers qa
        INNER JOIN evaluation_tracker et ON et.id = qa.tracker_id
        WHERE et.eval_type='student' AND et.target_user_id=?" . ($period ? " AND et.period_id=?" : ""),
        $period ? "ii" : "i",
        $period ? [$tid, $period_id_int] : [$tid]
    );

    $teacherRows[] = [
        'id'        => $tid,
        'name'      => $row['full_name'],
        'photo'     => !empty($row['photo']) ? '../image/' . $row['photo'] : '../image/pbi_logo',
        'grades'    => $grades,
        'completed' => $completed,
        'avg'       => $avgRating !== null ? round((float)$avgRating, 2) : null,
    ];
}

html_head_open('PBI — Teachers');
render_principal_sidebar('teachers', $me, $scopeLabel, $photo_src);
?>
<main class="main">
    <div class="page-header">
        <div>
            <div class="page-title">Teachers</div>
            <div class="page-sub"><?= count($teacherRows) ?> teacher<?= count($teacherRows) === 1 ? '' : 's' ?> · <?= htmlspecialchars($scopeLabel) ?></div>
        </div>
        <?php render_period_badge($settings); ?>
    </div>

    <div class="section">
        <form class="search-box" method="get">
            <input type="text" name="q" placeholder="Search by name…" value="<?= htmlspecialchars($q) ?>">
            <select name="yr">
                <option value="">All Grades</option>
                <?php foreach ($scopeGrades as $g): ?>
                    <option value="<?= htmlspecialchars($g) ?>" <?= $yr === $g ? 'selected' : '' ?>>Grade <?= htmlspecialchars($g) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
            <?php if ($q !== '' || $yr !== ''): ?><a class="btn" href="principal_teachers.php"><i class="fa-solid fa-xmark"></i> Clear</a><?php endif; ?>
        </form>

        <table class="data">
            <thead><tr><th>Teacher</th><th>Grade(s)</th><th>Evaluations Received</th><th>Avg Rating</th><th></th></tr></thead>
            <tbody>
            <?php if (empty($teacherRows)): ?>
                <tr><td colspan="5" class="empty-note">No teachers match this search.</td></tr>
            <?php else: foreach ($teacherRows as $t): ?>
                <tr class="row-link" onclick="window.location='principal_teachers.php?id=<?= $t['id'] ?>'">
                    <td><img class="avatar-sm" src="<?= htmlspecialchars($t['photo']) ?>" alt=""><?= htmlspecialchars($t['name']) ?></td>
                    <td><?= !empty($t['grades']) ? htmlspecialchars(implode(', ', $t['grades'])) : '<span class="empty-note">Unassigned</span>' ?></td>
                    <td><?= $t['completed'] ?></td>
                    <td><?= $t['avg'] !== null ? $t['avg'] : '<span class="empty-note">N/A</span>' ?></td>
                    <td><a class="btn" href="principal_teachers.php?id=<?= $t['id'] ?>"><i class="fa-solid fa-eye"></i> View</a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</main>
</body></html>
<?php $mysqli->close(); ?>
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

<link rel="stylesheet" href="includes/principal_dark_repairs.css?v=20261011.4" id="principal-dark-repairs"/>
