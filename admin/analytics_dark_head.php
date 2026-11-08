<?php /* analytics_dark_head.php — shared dark-theme support for admin_analytics views */ ?>
<script>
/* Evaluation Report is often rendered inside the dark admin shell as an iframe.
   Keep this document synchronized with the parent theme before and after first
   paint, including theme changes made while this iframe remains open. */
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
<style id="evaluation-report-dark-overrides">
/* Evaluation Report: page-local light rules must not repaint report surfaces in dark mode. */
html[data-theme="dark"] .target-card,
html[data-theme="dark"] .sheet-header,
html[data-theme="dark"] .scale-bar,
html[data-theme="dark"] .comment-section,
html[data-theme="dark"] .avg-summary,
html[data-theme="dark"] .eval-card,
html[data-theme="dark"] .sum-card,
html[data-theme="dark"] .standing-panel,
html[data-theme="dark"] .person-row,
html[data-theme="dark"] .no-evaluated,
html[data-theme="dark"] .no-eval,
html[data-theme="dark"] .score-legend,
html[data-theme="dark"] .peer-info-note,
html[data-theme="dark"] .results-table-wrap,
html[data-theme="dark"] .evaluator-grid,
html[data-theme="dark"] .desig-subtabs,
html[data-theme="dark"] .ra-table-card,
html[data-theme="dark"] .ra-filters-panel {
    background: var(--panel-bg) !important;
    color: var(--text) !important;
    border-color: var(--panel-border) !important;
}

html[data-theme="dark"] .target-avatar-ph,
html[data-theme="dark"] .eval-avatar-ph,
html[data-theme="dark"] .person-photo-ph,
html[data-theme="dark"] .sheet-avatar-ph,
html[data-theme="dark"] .rating-badge,
html[data-theme="dark"] .score-legend,
html[data-theme="dark"] .avg-bar-bg {
    background: var(--input-bg) !important;
    color: var(--text) !important;
    border-color: var(--panel-border) !important;
}

html[data-theme="dark"] .target-name,
html[data-theme="dark"] .sheet-name,
html[data-theme="dark"] .section-title,
html[data-theme="dark"] .sum-value,
html[data-theme="dark"] .person-name,
html[data-theme="dark"] .score-legend-title,
html[data-theme="dark"] .ra-name-text a,
html[data-theme="dark"] table.results-table td,
html[data-theme="dark"] table.ra-table td {
    color: var(--text) !important;
}

html[data-theme="dark"] .target-desig,
html[data-theme="dark"] .sheet-desig,
html[data-theme="dark"] .eval-by-label,
html[data-theme="dark"] .eval-by-date,
html[data-theme="dark"] .eval-desc,
html[data-theme="dark"] .no-evaluated,
html[data-theme="dark"] .no-eval,
html[data-theme="dark"] .score-legend-row,
html[data-theme="dark"] .sum-label,
html[data-theme="dark"] .sum-sub,
html[data-theme="dark"] .pstat-lbl,
html[data-theme="dark"] .standing-rank,
html[data-theme="dark"] .standing-desig,
html[data-theme="dark"] .ra-pager-info {
    color: var(--muted) !important;
}

html[data-theme="dark"] table.results-table,
html[data-theme="dark"] table.ra-table,
html[data-theme="dark"] .q-table {
    background: var(--panel-bg) !important;
    color: var(--text) !important;
}

html[data-theme="dark"] table.results-table thead tr,
html[data-theme="dark"] table.ra-table thead tr,
html[data-theme="dark"] .q-table thead tr {
    background: var(--input-bg) !important;
}

html[data-theme="dark"] table.results-table th,
html[data-theme="dark"] table.results-table td,
html[data-theme="dark"] table.ra-table th,
html[data-theme="dark"] table.ra-table td,
html[data-theme="dark"] .q-table th,
html[data-theme="dark"] .q-table td {
    color: var(--text) !important;
    border-color: var(--panel-border) !important;
}

html[data-theme="dark"] table.results-table tbody tr:hover,
html[data-theme="dark"] table.ra-table tbody tr:hover,
html[data-theme="dark"] .q-table tr:hover td {
    background: var(--input-bg) !important;
}

html[data-theme="dark"] .score-bar-bg,
html[data-theme="dark"] .avg-bar-bg,
html[data-theme="dark"] .eval-bar-bg {
    background: var(--panel-border) !important;
}

html[data-theme="dark"] .faculty-subtabs,
html[data-theme="dark"] .desig-subtabs,
html[data-theme="dark"] .ra-filters-panel {
    background: var(--panel-bg) !important;
    border-color: var(--panel-border) !important;
    color: var(--text) !important;
}

html[data-theme="dark"] .faculty-subtab:hover,
html[data-theme="dark"] .desig-subtab:hover {
    background: var(--input-bg) !important;
    color: var(--text) !important;
}

/* Evaluation Report roster/list view. The page-specific light rules below
   use var(--mid)/var(--inner); explicitly remap every visible report surface
   so the list view follows the dark admin appearance as well. */
html[data-theme="dark"] .ra-table-card{
    background:var(--panel-bg) !important;
    color:var(--text) !important;
    border-color:var(--panel-border) !important;
}
html[data-theme="dark"] .ra-tool-btn{
    background:var(--input-bg) !important;
    color:var(--text) !important;
    border-color:var(--panel-border) !important;
}
html[data-theme="dark"] .ra-tool-btn:hover{
    background:var(--panel-bg) !important;
    color:var(--ec) !important;
}
html[data-theme="dark"] .ra-search,
html[data-theme="dark"] .ra-filter-row select{
    background:var(--input-bg) !important;
    color:var(--text) !important;
    border-color:var(--panel-border) !important;
}
html[data-theme="dark"] .ra-search::placeholder{
    color:var(--muted) !important;
}
html[data-theme="dark"] .ra-filter-clear{
    color:var(--muted) !important;
    border-color:var(--panel-border) !important;
}
html[data-theme="dark"] .ra-filter-clear:hover{
    color:var(--ec) !important;
    border-color:var(--ec) !important;
}
html[data-theme="dark"] .ra-photo-ph{
    background:var(--input-bg) !important;
    color:var(--muted) !important;
    border-color:var(--panel-border) !important;
}
html[data-theme="dark"] .ra-table-wrap,
html[data-theme="dark"] table.ra-table{
    background:var(--panel-bg) !important;
}
html[data-theme="dark"] table.ra-table th,
html[data-theme="dark"] table.ra-table td{
    color:var(--text) !important;
    border-color:var(--panel-border) !important;
}
html[data-theme="dark"] table.ra-table th{
    background:var(--input-bg) !important;
}
html[data-theme="dark"] table.ra-table tbody tr:hover{
    background:var(--input-bg) !important;
}
html[data-theme="dark"] .ra-name-text a{
    color:var(--text) !important;
}
html[data-theme="dark"] .ra-name-text a:hover{
    color:var(--ec) !important;
}
html[data-theme="dark"] .ra-icon-btn,
html[data-theme="dark"] .ra-pager-btns button{
    background:var(--input-bg) !important;
    color:var(--text) !important;
    border-color:var(--panel-border) !important;
}
html[data-theme="dark"] .ra-pager-btns button.active{
    background:var(--ec) !important;
    border-color:var(--ec) !important;
    color:#fff !important;
}
html[data-theme="dark"] .ra-pager-info{
    color:var(--muted) !important;
}

/* The report's broad light reset sits later in the document than the shared
   stylesheet, so keep the root/background dark once the dark theme is active. */
html[data-theme="dark"]{
    background:var(--bg) !important;
    color-scheme:dark !important;
}
html[data-theme="dark"] body{
    background:var(--bg) !important;
    color:var(--text) !important;
}

/* ── REPORT MODE: the "Generate Report" action's clean, document-style
   look — the same appearance the print stylesheet below already produces —
   applied directly on screen (via body.report-mode) so a person doesn't
   have to open the print dialog just to see it. "View" doesn't add this
   class, so it keeps the normal interactive dashboard-styled sheet. */
/* Layout-only rules (theme independent) */
body.report-mode .btn-print{color:#fff!important;}
body.report-mode .eval-type-chip{display:none!important;}
body.report-mode .scale-bar{display:none!important;}
body.report-mode .score-legend{display:none!important;}
body.report-mode .sheet-eval-by.no-print{display:none!important;}
body.report-mode .print-anon-notice{display:block!important;}
body.report-mode .sheet-header{border:1px solid #ddd;padding:12px 16px!important;margin-bottom:10px!important;gap:12px!important;border-radius:0!important;}
body.report-mode .cat-section{margin-bottom:12px!important;}
body.report-mode .cat-title{margin-bottom:6px!important;padding-bottom:3px!important;}
body.report-mode .q-table{border-radius:0!important;}
body.report-mode .q-table th,body.report-mode .q-table td{padding:6px 10px!important;}
body.report-mode .q-table tr:hover td{background:transparent!important;}
body.report-mode .rating-badge{padding:3px 8px!important;gap:0!important;}
body.report-mode .comment-section{border-radius:0!important;padding:10px 14px!important;margin-bottom:12px!important;}
body.report-mode .avg-summary{border-radius:0!important;padding:12px 14px!important;gap:14px!important;}

/* LIGHT theme: white paper look */
html:not([data-theme="dark"]) body.report-mode{background:#fff!important;color:#000!important;}
html:not([data-theme="dark"]) body.report-mode *{color:#000!important;}
html:not([data-theme="dark"]) body.report-mode .btn-print{color:#fff!important;}
html:not([data-theme="dark"]) body.report-mode .back-btn{color:#294765!important;}
html:not([data-theme="dark"]) body.report-mode .sheet-header,
html:not([data-theme="dark"]) body.report-mode .q-table,
html:not([data-theme="dark"]) body.report-mode .rating-badge,
html:not([data-theme="dark"]) body.report-mode .comment-section,
html:not([data-theme="dark"]) body.report-mode .avg-summary{background:#fff!important;}
html:not([data-theme="dark"]) body.report-mode .cat-title{border-color:#ddd!important;}
html:not([data-theme="dark"]) body.report-mode .q-table{border:1px solid #ddd!important;}
html:not([data-theme="dark"]) body.report-mode .q-table thead tr{background:#f5f5f5!important;}
html:not([data-theme="dark"]) body.report-mode .q-table th,
html:not([data-theme="dark"]) body.report-mode .q-table td{border-color:#eee!important;}
html:not([data-theme="dark"]) body.report-mode .rating-badge{border:1px solid #ccc!important;}
html:not([data-theme="dark"]) body.report-mode .comment-section,
html:not([data-theme="dark"]) body.report-mode .avg-summary{border:1px solid #ddd!important;}

/* DARK theme (screen only; printing always stays white, see below) */
@media screen{
html[data-theme="dark"] body.report-mode{background:var(--bg)!important;color:var(--text)!important;}
html[data-theme="dark"] body.report-mode .cum-topbar,
html[data-theme="dark"] body.report-mode .sheet-header,
html[data-theme="dark"] body.report-mode .q-table,
html[data-theme="dark"] body.report-mode .comment-section,
html[data-theme="dark"] body.report-mode .avg-summary{background:var(--panel-bg)!important;border:1px solid var(--panel-border)!important;color:var(--text)!important;}
html[data-theme="dark"] body.report-mode .q-table thead tr{background:var(--input-bg)!important;}
html[data-theme="dark"] body.report-mode .q-table th,
html[data-theme="dark"] body.report-mode .q-table td{border-color:var(--panel-border)!important;color:var(--text)!important;}
html[data-theme="dark"] body.report-mode .cat-title{color:var(--text)!important;border-color:var(--panel-border)!important;}
html[data-theme="dark"] body.report-mode .rating-badge{background:var(--input-bg)!important;border:1px solid var(--panel-border)!important;}
html[data-theme="dark"] body.report-mode .sheet-name,
html[data-theme="dark"] body.report-mode .cum-type,
html[data-theme="dark"] body.report-mode .cum-by,
html[data-theme="dark"] body.report-mode .cum-date,
html[data-theme="dark"] body.report-mode .cum-date-val,
html[data-theme="dark"] body.report-mode .cum-total-label,
html[data-theme="dark"] body.report-mode .comment-title,
html[data-theme="dark"] body.report-mode .cum-remark{color:var(--text)!important;}
html[data-theme="dark"] body.report-mode .sheet-desig,
html[data-theme="dark"] body.report-mode .cum-date-label,
html[data-theme="dark"] body.report-mode .avg-out-of,
html[data-theme="dark"] body.report-mode .no-comment{color:var(--muted)!important;}
html[data-theme="dark"] body.report-mode .sheet-avatar-ph{background:var(--input-bg)!important;color:var(--muted)!important;}
html[data-theme="dark"] body.report-mode .back-btn{background:var(--panel-bg)!important;color:var(--text)!important;border-color:var(--panel-border)!important;}
/* rating numbers / total score keep their inline score colours */
}

/* Printing in dark mode: force the white paper look regardless of theme */
@media print{
html[data-theme="dark"],html[data-theme="dark"] body{background:#fff!important;color:#000!important;}
html[data-theme="dark"] .sheet-header,
html[data-theme="dark"] .cum-topbar,
html[data-theme="dark"] .q-table,
html[data-theme="dark"] .q-table thead tr,
html[data-theme="dark"] .rating-badge,
html[data-theme="dark"] .comment-section,
html[data-theme="dark"] .avg-summary{background:#fff!important;border-color:#ddd!important;}
}

</style>

<style id="analytics-list-dark-overrides">
/* Dark mode for the list / evaluators / archived views */
html[data-theme="dark"],html[data-theme="dark"] body{background:var(--bg)!important;color:var(--text)!important;color-scheme:dark;}
html[data-theme="dark"] .page-header,
html[data-theme="dark"] .eval-switcher,
html[data-theme="dark"] .sector-tabs,
html[data-theme="dark"] .tabs,
html[data-theme="dark"] .level-tabs,
html[data-theme="dark"] .status-tabs,
html[data-theme="dark"] .eval-banner,
html[data-theme="dark"] .table-wrap,
html[data-theme="dark"] .content-panel,
html[data-theme="dark"] .section,
html[data-theme="dark"] .history-card,
html[data-theme="dark"] .card,html[data-theme="dark"] .panel,
html[data-theme="dark"] .ra-table-card,
html[data-theme="dark"] .ra-filters-panel,
html[data-theme="dark"] .group-tab,
html[data-theme="dark"] .role-tab,
html[data-theme="dark"] .modal,html[data-theme="dark"] .modal-box{
  background:var(--panel-bg)!important;color:var(--text)!important;border-color:var(--panel-border)!important;box-shadow:none!important;}
html[data-theme="dark"] .eval-tab,html[data-theme="dark"] .sector-tab,html[data-theme="dark"] .tab,
html[data-theme="dark"] .level-tab,html[data-theme="dark"] .status-tab,
html[data-theme="dark"] .group-tab,html[data-theme="dark"] .role-tab{color:var(--muted)!important;background:transparent!important;border-color:var(--panel-border)!important;}
html[data-theme="dark"] .eval-tab:hover,html[data-theme="dark"] .sector-tab:hover{color:var(--text)!important;background:var(--input-bg)!important;}
html[data-theme="dark"] .eval-tab.active,html[data-theme="dark"] .sector-tab.active,
html[data-theme="dark"] .group-tab.active,html[data-theme="dark"] .role-tab.active{background:color-mix(in srgb,var(--accent) 22%,var(--panel-bg))!important;color:var(--text)!important;}
html[data-theme="dark"] .eval-divider{background:var(--panel-border)!important;}
html[data-theme="dark"] .page-header h1,html[data-theme="dark"] .page-title,
html[data-theme="dark"] .ra-title,html[data-theme="dark"] h1,html[data-theme="dark"] h2,html[data-theme="dark"] h3,
html[data-theme="dark"] .eval-banner-title,html[data-theme="dark"] td,html[data-theme="dark"] th{color:var(--text)!important;}
html[data-theme="dark"] .page-header p,html[data-theme="dark"] .eval-banner-desc,
html[data-theme="dark"] .muted,html[data-theme="dark"] .hint{color:var(--muted)!important;}
html[data-theme="dark"] thead tr,html[data-theme="dark"] thead th{background:var(--input-bg)!important;}
html[data-theme="dark"] tbody tr:hover{background:var(--input-bg)!important;}
html[data-theme="dark"] tbody td,html[data-theme="dark"] thead th{border-color:var(--panel-border)!important;}
html[data-theme="dark"] .btn-cancel,html[data-theme="dark"] .btn-icon,html[data-theme="dark"] .btn-back,
html[data-theme="dark"] .back-btn,html[data-theme="dark"] .btn-archived-link,
html[data-theme="dark"] .ra-tool-btn,html[data-theme="dark"] .ra-search{
  background:var(--input-bg)!important;color:var(--text)!important;border-color:var(--panel-border)!important;}
html[data-theme="dark"] .btn-print{color:#fff!important;}
html[data-theme="dark"] input,html[data-theme="dark"] select,html[data-theme="dark"] textarea{
  background:var(--input-bg)!important;color:var(--text)!important;border-color:var(--panel-border)!important;}
html[data-theme="dark"] input::placeholder{color:var(--muted)!important;}
html[data-theme="dark"] ::-webkit-scrollbar-track{background:transparent!important;}
html[data-theme="dark"] ::-webkit-scrollbar-thumb{background:var(--panel-border)!important;border-color:var(--bg)!important;}
</style>

<link rel="stylesheet" href="admin_appearance.css">
<script src="admin_appearance.js"></script>
