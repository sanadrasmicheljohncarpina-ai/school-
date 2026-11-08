<?php
// Dark-mode styling for the shared Dean top header (white bar). Included by every
// Dean page right after includes/dean_sidebar.php so the header follows the
// Dark/Light toggle no matter which copy of the shared header/theme files is live.
?>
<style id="dean-header-notif-scroll">
/* Notification bell: keep every notification in the list and let the list scroll
   inside a fixed-height panel. The heading stays put; only the list moves.
   Applies in both light and dark mode. */
body .dean-portal-topbar .dean-header-notif-dropdown{
  max-height:none !important;
  overflow:hidden !important;
}
body .dean-portal-topbar .dean-header-notif-list{
  max-height:min(340px, 60vh) !important;
  overflow-y:auto !important;
  overflow-x:hidden !important;
  overscroll-behavior:contain;
  -webkit-overflow-scrolling:touch;
  scrollbar-width:thin;
  scrollbar-color:#AEBAC8 transparent;
  padding-right:4px;
}
body .dean-portal-topbar .dean-header-notif-list::-webkit-scrollbar{width:8px;}
body .dean-portal-topbar .dean-header-notif-list::-webkit-scrollbar-track{background:transparent;}
body .dean-portal-topbar .dean-header-notif-list::-webkit-scrollbar-thumb{background:#AEBAC8;border-radius:8px;}
body .dean-portal-topbar .dean-header-notif-list::-webkit-scrollbar-thumb:hover{background:#8F9CAB;}
html.dark-theme body .dean-portal-topbar .dean-header-notif-list{scrollbar-color:#2A4468 transparent;}
html.dark-theme body .dean-portal-topbar .dean-header-notif-list::-webkit-scrollbar-thumb{background:#2A4468;}
html.dark-theme body .dean-portal-topbar .dean-header-notif-list::-webkit-scrollbar-thumb:hover{background:#385A86;}
</style>
<style id="dean-header-dark">
html.dark-theme body header.dean-portal-topbar,
html.dark-theme body .dean-portal-topbar{background:#0F1F3D !important;color:#E0E6F0 !important;border-bottom:1px solid rgba(255,255,255,.08) !important;box-shadow:0 2px 14px rgba(0,0,0,.35) !important;}
html.dark-theme body .dean-portal-topbar .dean-header-page-title{color:#E0E6F0 !important;}
html.dark-theme body .dean-portal-topbar .dean-header-period{background:rgba(156,133,240,.16) !important;border-color:rgba(156,133,240,.38) !important;color:#C4B5FD !important;}
html.dark-theme body .dean-portal-topbar .dean-header-bell{background:#172A45 !important;border-color:rgba(255,255,255,.12) !important;color:#FBBF24 !important;}
html.dark-theme body .dean-portal-topbar .dean-header-notif-badge{border-color:#0F1F3D !important;}
html.dark-theme body .dean-portal-topbar .dean-header-notif-dropdown{background:#172A45 !important;border-color:rgba(255,255,255,.12) !important;color:#E0E6F0 !important;box-shadow:0 18px 48px rgba(0,0,0,.55) !important;}
html.dark-theme body .dean-portal-topbar .dean-header-notif-heading{border-bottom-color:rgba(255,255,255,.08) !important;color:#A0B3C6 !important;}
html.dark-theme body .dean-portal-topbar .dean-header-notif-list li{background:#0F1F3D !important;border-color:rgba(255,255,255,.08) !important;color:#CBD5E1 !important;}
html.dark-theme body .dean-portal-topbar .dean-header-notif-list li.notif-link:hover{background:#1D3350 !important;}
html.dark-theme body .dean-portal-topbar .dean-header-notif-empty{color:#A0B3C6 !important;}
html.dark-theme body .dean-portal-topbar .dean-header-page-icon,html.dark-theme body .dean-portal-topbar .dean-header-page-icon i{color:#9C85F0 !important;}
html.dark-theme body .dean-portal-topbar{border-bottom-color:rgba(156,133,240,.38) !important;}
</style>
<script>
// Belt and braces: inline !important styles beat any stylesheet, and the
// MutationObserver re-applies them live when the Dark/Light toggle is used.
(function () {
    var bar = document.querySelector('.dean-portal-topbar');
    if (!bar) return;
    var rules = [
        ['', {'background':'#0F1F3D','color':'#E0E6F0','border-bottom-color':'rgba(156,133,240,.38)'}],
        ['.dean-header-page-title', {'color':'#E0E6F0'}],
        ['.dean-header-page-icon', {'color':'#9C85F0'}],
        ['.dean-header-period', {'background':'rgba(156,133,240,.16)','border-color':'rgba(156,133,240,.38)','color':'#C4B5FD'}],
        ['.dean-header-bell', {'background':'#172A45','border-color':'rgba(255,255,255,.12)','color':'#FBBF24'}],
        ['.dean-header-notif-dropdown', {'background':'#172A45','border-color':'rgba(255,255,255,.12)','color':'#E0E6F0'}]
    ];
    function apply() {
        var dark = document.documentElement.classList.contains('dark-theme');
        rules.forEach(function (r) {
            var el = r[0] ? bar.querySelector(r[0]) : bar;
            if (!el) return;
            Object.keys(r[1]).forEach(function (prop) {
                if (dark) el.style.setProperty(prop, r[1][prop], 'important');
                else el.style.removeProperty(prop);
            });
        });
    }
    new MutationObserver(apply).observe(document.documentElement, {attributes: true, attributeFilter: ['class']});
    apply();
})();
</script>