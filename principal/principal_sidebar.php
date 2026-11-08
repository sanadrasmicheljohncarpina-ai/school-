<?php
// principal_sidebar.php — one shared Principal sidebar used by every Principal page.
// The visual structure follows the Staff portal sidebar; the Principal role keeps
// its own amber accent color. Appearance is handled globally by principal_theme.js.

if (!function_exists('principal_sidebar_assets')) {
    function principal_sidebar_assets(): void {
        static $done = false;
        if ($done) return;
        $done = true;
        ?>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<style id="principal-sidebar-unified">
#pbiSidebar{box-sizing:border-box!important;width:248px!important;min-width:248px!important;max-width:248px!important;flex:0 0 248px!important;position:fixed!important;top:0!important;left:0!important;height:100vh!important;min-height:100vh!important;margin:0!important;padding:0!important;overflow:hidden!important;display:flex!important;flex-direction:column!important;background:#0A192F!important;color:#E0E6F0!important;border-right:1px solid #172A45!important;box-shadow:6px 0 20px rgba(0,0,0,.16)!important;border-radius:0!important;z-index:40!important;font-family:'DM Sans',sans-serif!important;font-size:14px!important;line-height:normal!important;text-align:left!important;}
#pbiSidebar *,#pbiSidebar *::before,#pbiSidebar *::after{box-sizing:border-box!important;}
#pbiSidebar .portal-brand{min-height:68px!important;padding:14px 17px!important;border-bottom:1px solid rgba(255,255,255,.08)!important;display:flex!important;align-items:center!important;gap:11px!important;background:transparent!important;}
#pbiSidebar .portal-brand-logo{width:38px!important;height:38px!important;flex:0 0 38px!important;border-radius:11px!important;display:flex!important;align-items:center!important;justify-content:center!important;overflow:hidden!important;background:#0F1F3D!important;border:1px solid #D99A2B!important;box-shadow:0 0 14px rgba(217,154,43,.18)!important;}
#pbiSidebar .portal-brand-logo img{width:100%!important;height:100%!important;object-fit:cover!important;display:block!important;}
#pbiSidebar .portal-brand-copy{min-width:0!important;display:flex!important;flex-direction:column!important;line-height:1.2!important;}
#pbiSidebar .portal-brand-copy strong{font-family:'Rajdhani',sans-serif!important;font-size:15px!important;font-weight:700!important;color:#F8FAFC!important;letter-spacing:.25px!important;}
#pbiSidebar .portal-brand-copy span{margin-top:3px!important;font-size:10px!important;color:#8FA6BF!important;font-weight:600!important;letter-spacing:.15px!important;white-space:nowrap!important;}
#pbiSidebar .portal-sidebar-profile{padding:18px 16px 17px!important;text-align:center!important;border-bottom:1px solid rgba(255,255,255,.08)!important;background:transparent!important;}
#pbiSidebar .portal-profile-avatar-wrap{margin:0 auto 11px!important;display:flex!important;justify-content:center!important;}
#pbiSidebar .portal-profile-avatar{width:64px!important;height:64px!important;border-radius:50%!important;object-fit:cover!important;border:2px solid #D99A2B!important;background:#0F1F3D!important;box-shadow:0 0 15px rgba(217,154,43,.18)!important;display:flex!important;align-items:center!important;justify-content:center!important;}
#pbiSidebar .portal-profile-fallback{color:#F0B84D!important;font-family:'Rajdhani',sans-serif!important;font-size:17px!important;font-weight:700!important;}
#pbiSidebar .portal-profile-name{color:#F8FAFC!important;font-size:13px!important;line-height:1.35!important;font-weight:700!important;word-break:break-word!important;}
#pbiSidebar .portal-profile-role{margin-top:3px!important;color:#8FA6BF!important;font-size:10px!important;text-transform:uppercase!important;letter-spacing:.7px!important;font-weight:700!important;line-height:1.35!important;}
#pbiSidebar .portal-profile-scope{margin-top:3px!important;color:#8FA6BF!important;font-size:10px!important;line-height:1.35!important;}
#pbiSidebar .portal-sidebar-nav{flex:1!important;padding:16px 10px!important;overflow-y:auto!important;overflow-x:hidden!important;background:transparent!important;}
#pbiSidebar .portal-sidebar-nav .sb-nav-section-label{display:block!important;width:auto!important;padding:0 8px!important;margin:0 0 7px!important;font-family:'DM Sans',sans-serif!important;font-size:9.5px!important;font-weight:800!important;letter-spacing:1.25px!important;line-height:1.2!important;text-align:left!important;text-transform:uppercase!important;color:#8FA6BF!important;text-shadow:none!important;background:none!important;border:0!important;}
#pbiSidebar .portal-sidebar-nav .sb-nav-section-label.sidebar-section-secondary{margin-top:17px!important;}
#pbiSidebar .portal-sidebar-nav a{display:flex!important;align-items:center!important;gap:10px!important;width:100%!important;min-height:40px!important;margin:2px 2px!important;padding:9px 11px!important;border:0!important;border-radius:8px!important;background:transparent!important;color:#CBD8E8!important;font-family:'DM Sans',sans-serif!important;font-size:13px!important;font-weight:500!important;line-height:1.2!important;letter-spacing:0!important;text-decoration:none!important;transition:background .2s,color .2s,box-shadow .2s!important;}
#pbiSidebar .portal-sidebar-nav a::before,#pbiSidebar .portal-sidebar-nav a::after{content:none!important;display:none!important;}
#pbiSidebar .portal-sidebar-nav a i{display:inline-block!important;width:18px!important;flex:0 0 18px!important;font-size:14px!important;color:#8FA6BF!important;text-align:center!important;margin:0!important;}
#pbiSidebar .portal-sidebar-nav a:hover{background:rgba(255,255,255,.07)!important;color:#FFFFFF!important;}
#pbiSidebar .portal-sidebar-nav a:hover i{color:#F0B84D!important;}
#pbiSidebar .portal-sidebar-nav a.active{background:linear-gradient(90deg,rgba(217,154,43,.18),rgba(255,255,255,.025))!important;color:#FFFFFF!important;font-weight:700!important;box-shadow:inset 3px 0 0 #F0B84D!important;}
#pbiSidebar .portal-sidebar-nav a.active i{color:#F0B84D!important;}
#pbiSidebar .portal-sidebar-nav .nav-badge,#pbiSidebar .portal-sidebar-nav .side-nav-badge{margin-left:auto!important;background:rgba(217,154,43,.22)!important;color:#FDE68A!important;border-radius:20px!important;padding:2px 7px!important;font-size:9px!important;font-weight:800!important;}
#pbiSidebar .sidebar-footer{padding:13px 14px 15px!important;border-top:1px solid rgba(255,255,255,.08)!important;background:transparent!important;}
#pbiSidebar .btn-logout-side{display:flex!important;align-items:center!important;gap:7px!important;width:100%!important;padding:9px 11px!important;border:1px solid rgba(240,84,84,.30)!important;background:rgba(240,84,84,.08)!important;border-radius:8px!important;color:#FCA5A5!important;font-size:13px!important;font-weight:600!important;text-decoration:none!important;transition:all .2s!important;box-sizing:border-box!important;}
#pbiSidebar .btn-logout-side:hover{background:rgba(240,84,84,.15)!important;color:#fff!important;}
@media(max-width:768px){#pbiSidebar{position:static!important;width:100%!important;min-width:0!important;max-width:none!important;flex:0 0 auto!important;height:auto!important;min-height:auto!important;overflow:visible!important;} }
@media print{#pbiSidebar{display:none!important;}}

/* Shared Principal top bar — follows the Faculty portal's fixed header layout. */
#pbiPrincipalTopbar{box-sizing:border-box!important;position:fixed!important;top:0!important;left:248px!important;right:0!important;height:58px!important;z-index:35!important;display:flex!important;align-items:center!important;justify-content:space-between!important;gap:16px!important;padding:0 26px!important;background:#FFFFFF!important;color:#13263F!important;border-bottom:1px solid #DEE6EF!important;box-shadow:0 2px 14px rgba(15,34,55,.04)!important;font-family:'DM Sans',sans-serif!important;}
#pbiPrincipalTopbar *{box-sizing:border-box!important;}
#pbiPrincipalTopbar .pbi-topbar-left,#pbiPrincipalTopbar .pbi-topbar-right{display:flex!important;align-items:center!important;gap:13px!important;min-width:0!important;}
#pbiPrincipalTopbar .pbi-topbar-title{font-family:'Rajdhani',sans-serif!important;font-size:19px!important;font-weight:700!important;letter-spacing:.4px!important;line-height:1.2!important;color:#13263F!important;white-space:nowrap!important;}
#pbiPrincipalTopbar .pbi-topbar-title{display:inline-flex!important;align-items:center!important;gap:11px!important;}
#pbiPrincipalTopbar .pbi-topbar-title-icon{flex:0 0 auto!important;font-size:19px!important;line-height:1!important;color:#D99A2B!important;}
#pbiPrincipalTopbar .pbi-topbar-hamburger{display:none!important;width:36px!important;height:36px!important;align-items:center!important;justify-content:center!important;border:1px solid #D9E2EC!important;border-radius:9px!important;background:#F8FAFC!important;color:#334155!important;cursor:pointer!important;font-size:15px!important;}
#pbiPrincipalTopbar .pbi-topbar-period{display:inline-flex!important;align-items:center!important;gap:7px!important;max-width:min(52vw,520px)!important;padding:7px 12px!important;border:1px solid rgba(217,154,43,.28)!important;border-radius:22px!important;background:rgba(217,154,43,.10)!important;color:#9A6700!important;font-size:11px!important;font-weight:700!important;line-height:1.25!important;white-space:nowrap!important;overflow:hidden!important;text-overflow:ellipsis!important;}
#pbiPrincipalTopbar .pbi-topbar-period i{flex-shrink:0!important;color:#D99A2B!important;}
#pbiPrincipalTopbar .pbi-topbar-bell-wrap{position:relative!important;flex-shrink:0!important;}
#pbiPrincipalTopbar .pbi-topbar-bell{position:relative!important;display:flex!important;align-items:center!important;justify-content:center!important;width:36px!important;height:36px!important;border:1px solid #D9E2EC!important;border-radius:50%!important;background:#FFFFFF!important;color:#64748B!important;font-size:15px!important;cursor:pointer!important;transition:all .18s ease!important;}
#pbiPrincipalTopbar .pbi-topbar-bell:hover,#pbiPrincipalTopbar .pbi-topbar-bell[aria-expanded="true"]{background:rgba(217,154,43,.10)!important;border-color:rgba(217,154,43,.38)!important;color:#A16207!important;}
#pbiPrincipalTopbar .pbi-topbar-count{display:none!important;position:absolute!important;top:-4px!important;right:-5px!important;min-width:17px!important;height:17px!important;padding:0 4px!important;border:2px solid #FFFFFF!important;border-radius:20px!important;background:#DC2626!important;color:#FFFFFF!important;font-size:9px!important;font-weight:800!important;line-height:13px!important;text-align:center!important;}
#pbiPrincipalTopbar .pbi-topbar-count.show{display:block!important;}
#pbiPrincipalTopbar .pbi-topbar-panel{display:none!important;position:absolute!important;top:calc(100% + 12px)!important;right:0!important;width:min(370px,calc(100vw - 28px))!important;max-height:min(520px,75vh)!important;overflow:hidden!important;background:#FFFFFF!important;border:1px solid #E2E8F0!important;border-radius:14px!important;box-shadow:0 18px 44px rgba(15,23,42,.18)!important;color:#172033!important;}
#pbiPrincipalTopbar .pbi-topbar-panel.open{display:block!important;}
#pbiPrincipalTopbar .pbi-topbar-panel-head{display:flex!important;align-items:center!important;justify-content:space-between!important;gap:10px!important;padding:14px 16px!important;border-bottom:1px solid #E2E8F0!important;}
#pbiPrincipalTopbar .pbi-topbar-panel-head strong{font-size:13px!important;font-weight:800!important;color:#172033!important;}
#pbiPrincipalTopbar .pbi-topbar-mark-read{border:0!important;background:transparent!important;color:#A16207!important;font-family:inherit!important;font-size:11px!important;font-weight:700!important;cursor:pointer!important;white-space:nowrap!important;}
#pbiPrincipalTopbar .pbi-topbar-list{list-style:none!important;margin:0!important;padding:8px!important;max-height:350px!important;overflow:auto!important;}
#pbiPrincipalTopbar .pbi-topbar-list li{display:flex!important;align-items:stretch!important;gap:0!important;margin:0 0 5px!important;padding:0!important;border-radius:9px!important;background:#F8FAFC!important;color:#334155!important;font-size:12px!important;line-height:1.45!important;overflow:hidden!important;}
#pbiPrincipalTopbar .pbi-topbar-list{overscroll-behavior:contain!important;overflow-x:hidden!important;}
#pbiPrincipalTopbar .pbi-topbar-list{overflow-y:scroll!important;scrollbar-width:thin!important;scrollbar-color:#CBD5E1 transparent!important;}
#pbiPrincipalTopbar .pbi-topbar-list::-webkit-scrollbar{width:8px!important;}
#pbiPrincipalTopbar .pbi-topbar-list::-webkit-scrollbar-thumb{background:#CBD5E1!important;border-radius:8px!important;}
#pbiPrincipalTopbar .pbi-topbar-list li.unseen{background:rgba(217,154,43,.10)!important;}
#pbiPrincipalTopbar .pbi-topbar-list li a.pbi-topbar-notification-link{display:flex!important;align-items:flex-start!important;gap:10px!important;width:100%!important;min-width:0!important;padding:10px!important;border-radius:9px!important;color:inherit!important;text-decoration:none!important;cursor:pointer!important;}
#pbiPrincipalTopbar .pbi-topbar-list li a.pbi-topbar-notification-link:hover span{text-decoration:underline!important;text-underline-offset:2px!important;}
#pbiPrincipalTopbar .pbi-topbar-list li a.pbi-topbar-notification-link:focus-visible{outline:2px solid #B8801F!important;outline-offset:-2px!important;}
#pbiPrincipalTopbar .pbi-topbar-list li i{margin-top:2px!important;color:#B8801F!important;flex-shrink:0!important;}
#pbiPrincipalTopbar .pbi-topbar-list li span{min-width:0!important;overflow-wrap:anywhere!important;}
#pbiPrincipalTopbar .pbi-topbar-empty{padding:25px 16px!important;text-align:center!important;color:#64748B!important;font-size:12px!important;}
#pbiPrincipalTopbar .pbi-topbar-foot{padding:8px 14px!important;border-top:1px solid #E2E8F0!important;color:#64748B!important;font-size:10px!important;}
html.principal-theme-dark #pbiPrincipalTopbar{background:#0F1F3D!important;color:#E0E6F0!important;border-bottom-color:rgba(255,255,255,.10)!important;box-shadow:0 2px 14px rgba(0,0,0,.22)!important;}
html.principal-theme-dark #pbiPrincipalTopbar .pbi-topbar-title{color:#F8FAFC!important;}
html.principal-theme-dark #pbiPrincipalTopbar .pbi-topbar-hamburger,html.principal-theme-dark #pbiPrincipalTopbar .pbi-topbar-bell{background:#172A45!important;border-color:rgba(255,255,255,.12)!important;color:#D0DAE6!important;}
html.principal-theme-dark #pbiPrincipalTopbar .pbi-topbar-period{background:rgba(217,154,43,.17)!important;border-color:rgba(240,184,77,.34)!important;color:#F0B84D!important;}
html.principal-theme-dark #pbiPrincipalTopbar .pbi-topbar-panel{background:#0F1F3D!important;border-color:rgba(255,255,255,.12)!important;color:#E0E6F0!important;}
html.principal-theme-dark #pbiPrincipalTopbar .pbi-topbar-panel-head{border-color:rgba(255,255,255,.10)!important;}
html.principal-theme-dark #pbiPrincipalTopbar .pbi-topbar-panel-head strong{color:#F8FAFC!important;}
html.principal-theme-dark #pbiPrincipalTopbar .pbi-topbar-list li{background:#172A45!important;color:#DCE6F0!important;}
html.principal-theme-dark #pbiPrincipalTopbar .pbi-topbar-list li.unseen{background:rgba(217,154,43,.14)!important;}
html.principal-theme-dark #pbiPrincipalTopbar .pbi-topbar-foot{border-color:rgba(255,255,255,.10)!important;color:#A0B3C6!important;}
#pbiPrincipalTopbar ~ .main,#pbiPrincipalTopbar ~ main.main{margin-top:58px!important;}
@media(max-width:900px){
  #pbiSidebar{position:fixed!important;top:0!important;left:0!important;width:248px!important;min-width:248px!important;max-width:248px!important;height:100vh!important;min-height:100vh!important;transform:translateX(-105%)!important;transition:transform .22s ease!important;z-index:40!important;}
  #pbiSidebar.open{transform:translateX(0)!important;box-shadow:12px 0 34px rgba(0,0,0,.30)!important;}
  #pbiPrincipalTopbar{left:0!important;}
  #pbiPrincipalTopbar .pbi-topbar-hamburger{display:flex!important;}
  #pbiPrincipalTopbar ~ .main,#pbiPrincipalTopbar ~ main.main{margin-left:0!important;}
}
@media(max-width:768px){
  #pbiPrincipalTopbar{position:sticky!important;left:auto!important;right:auto!important;width:100%!important;height:auto!important;min-height:58px!important;padding:10px 14px!important;flex-wrap:wrap!important;gap:8px!important;}
  #pbiPrincipalTopbar .pbi-topbar-right{margin-left:auto!important;gap:8px!important;}
  #pbiPrincipalTopbar .pbi-topbar-period{max-width:46vw!important;font-size:10px!important;padding:6px 9px!important;}
  #pbiPrincipalTopbar .pbi-topbar-title{font-size:17px!important;}
  #pbiPrincipalTopbar .pbi-topbar-title-icon{font-size:17px!important;}
  #pbiPrincipalTopbar ~ .main,#pbiPrincipalTopbar ~ main.main{margin-top:0!important;margin-left:0!important;padding-top:22px!important;}
}
@media print{#pbiPrincipalTopbar{display:none!important;}#pbiPrincipalTopbar ~ .main,#pbiPrincipalTopbar ~ main.main{margin-top:0!important;padding-top:20px!important;}}

/* Shared Principal page workspace. Keep the Principal navy/amber identity,
   but give every non-dashboard page the same light, inset work surface as the
   dashboard. This affects presentation only; page data and actions are intact. */
#pbiPrincipalTopbar ~ main.main:not(.principal-dashboard){
  position:relative!important;
  isolation:isolate!important;
  background:#F3F6FA!important;
  color:#172033!important;
  border:0!important;
  border-radius:0!important;
  box-shadow:none!important;
  min-height:calc(100vh - 58px)!important;
}
#pbiPrincipalTopbar ~ main.main:not(.principal-dashboard)::before{
  content:""!important;
  position:absolute!important;
  top:14px!important;right:16px!important;bottom:0!important;left:16px!important;
  background:#FFFFFF!important;
  border:1px solid #E2E8F0!important;
  border-bottom:0!important;
  border-radius:18px 18px 0 0!important;
  box-shadow:0 2px 12px rgba(15,23,42,.025)!important;
  pointer-events:none!important;
  z-index:-1!important;
}
#pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) > .page-header,
#pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .page-header:first-child{
  background:transparent!important;
  border:0!important;
  box-shadow:none!important;
}
#pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .page-title,
#pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .page-header h1,
#pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .section-title{
  color:#12263F!important;
}
#pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .page-sub,
#pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .page-header p,
#pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .muted,
#pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .helper{
  color:#5B7186!important;
}
#pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .section,
#pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .card,
#pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .panel,
#pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .profile-card,
#pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .tracker-card,
#pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .de-panel,
#pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .stat-card,
#pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .response-card,
#pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .detail-person-card,
#pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .detail-results-panel,
#pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .table-card,
#pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .table-wrap,
#pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .content-card,
#pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .content-panel,
#pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .filter-bar{
  background:#FFFFFF!important;
  color:#172033!important;
  border-color:#D9E4EF!important;
  box-shadow:0 4px 16px rgba(20,42,67,.045)!important;
}
#pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) table thead th,
#pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .table-head,
#pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .table-header{
  background:#F4F8FF!important;color:#4B6580!important;border-color:#D9E4EF!important;
}
#pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) table tbody td{
  color:#334155!important;border-color:#E2E8F0!important;
}
html.principal-theme-dark #pbiPrincipalTopbar ~ main.main:not(.principal-dashboard){
  background:#0A192F!important;color:#E0E6F0!important;
}
html.principal-theme-dark #pbiPrincipalTopbar ~ main.main:not(.principal-dashboard)::before{
  background:#0F1F3D!important;border-color:rgba(255,255,255,.10)!important;
  box-shadow:0 4px 20px rgba(0,0,0,.20)!important;
}
html.principal-theme-dark #pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .page-title,
html.principal-theme-dark #pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .page-header h1,
html.principal-theme-dark #pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .section-title{color:#F8FAFC!important;}
html.principal-theme-dark #pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .page-sub,
html.principal-theme-dark #pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .page-header p,
html.principal-theme-dark #pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .muted,
html.principal-theme-dark #pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .helper{color:#A0B3C6!important;}
html.principal-theme-dark #pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .section,
html.principal-theme-dark #pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .card,
html.principal-theme-dark #pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .panel,
html.principal-theme-dark #pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .profile-card,
html.principal-theme-dark #pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .tracker-card,
html.principal-theme-dark #pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .de-panel,
html.principal-theme-dark #pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .stat-card,
html.principal-theme-dark #pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .response-card,
html.principal-theme-dark #pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .detail-person-card,
html.principal-theme-dark #pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .detail-results-panel,
html.principal-theme-dark #pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .table-card,
html.principal-theme-dark #pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .table-wrap,
html.principal-theme-dark #pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .content-card,
html.principal-theme-dark #pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .content-panel,
html.principal-theme-dark #pbiPrincipalTopbar ~ main.main:not(.principal-dashboard) .filter-bar{
  background:#172A45!important;color:#E0E6F0!important;
  border-color:rgba(255,255,255,.09)!important;box-shadow:0 4px 20px rgba(0,0,0,.24)!important;
}
@media(max-width:768px){
  #pbiPrincipalTopbar ~ main.main:not(.principal-dashboard)::before{top:8px!important;left:8px!important;right:8px!important;border-radius:12px 12px 0 0!important;}
}
@media print{
  #pbiPrincipalTopbar ~ main.main:not(.principal-dashboard){background:#FFFFFF!important;}
  #pbiPrincipalTopbar ~ main.main:not(.principal-dashboard)::before{background:#FFFFFF!important;border-color:transparent!important;box-shadow:none!important;}
}

/* Dashboard-matched workspace wrapper for Evaluate Others and Evaluation Received.
   Keep the Principal's original navy sidebar and amber/gold identity. */
#pbiPrincipalTopbar ~ main.principal-feature-page::before{
  display:none!important;content:none!important;
}
#pbiPrincipalTopbar ~ main.principal-feature-page{
  background:#F3F6FB!important;
  color:#172033!important;
  padding:28px!important;
}
#principalFeatureWorkspace{
  box-sizing:border-box!important;
  display:block!important;
  width:100%!important;
  max-width:1680px!important;
  min-width:0!important;
  min-height:calc(100vh - 112px)!important;
  margin:0 auto!important;
  padding:27px 32px 34px!important;
  background:#FFFFFF!important;
  color:#172033!important;
  border:1px solid #DFE7F0!important;
  border-radius:23px!important;
  box-shadow:0 2px 10px rgba(15,23,42,.025)!important;
}
#principalFeatureWorkspace *{box-sizing:border-box;}
#principalFeatureWorkspace .principal-feature-header-row{
  display:flex!important;align-items:flex-start!important;justify-content:space-between!important;
  gap:18px 24px!important;flex-wrap:wrap!important;margin:0 0 24px!important;
}
#principalFeatureWorkspace .principal-feature-heading{flex:1 1 420px!important;min-width:0!important;margin:0!important;padding:0!important;background:transparent!important;border:0!important;box-shadow:none!important;}
#principalFeatureWorkspace .principal-feature-eyebrow{
  display:flex!important;align-items:center!important;gap:9px!important;margin:0 0 4px!important;
  color:#B8801F!important;font-family:'DM Sans',sans-serif!important;font-size:12px!important;
  font-weight:800!important;letter-spacing:1.25px!important;line-height:1.4!important;text-transform:uppercase!important;
}
#principalFeatureWorkspace .principal-feature-eyebrow i{color:#B8801F!important;font-size:13px!important;}
#principalFeatureWorkspace .principal-feature-heading h1{
  margin:0!important;color:#10223B!important;font-family:'Rajdhani',sans-serif!important;
  font-size:clamp(26px,2vw,34px)!important;font-weight:700!important;letter-spacing:.25px!important;line-height:1.18!important;
}
#principalFeatureWorkspace .principal-feature-heading p{
  margin:6px 0 0!important;color:#64748B!important;font-family:'DM Sans',sans-serif!important;
  font-size:14px!important;line-height:1.55!important;
}
#principalFeatureWorkspace .principal-feature-header-meta{display:flex!important;align-items:center!important;justify-content:flex-end!important;gap:10px!important;flex:0 1 auto!important;padding-top:22px!important;}
#principalFeatureWorkspace .principal-feature-term-picker{margin:5px 0 0!important;}
#principalFeatureWorkspace .de-section-label{margin-top:6px!important;}
#principalFeatureWorkspace .de-cat,
#principalFeatureWorkspace .de-panel,
#principalFeatureWorkspace .stat-card,
#principalFeatureWorkspace .section{box-shadow:0 3px 12px rgba(15,23,42,.04)!important;}
#principalFeatureWorkspace .de-panel{border-radius:16px!important;}
#principalFeatureWorkspace .section{border-radius:16px!important;}
html.principal-theme-dark #principalFeatureWorkspace{background:#0F1F3D!important;color:#E0E6F0!important;border-color:rgba(255,255,255,.12)!important;}
html.principal-theme-dark #principalFeatureWorkspace .principal-feature-heading h1{color:#F8FAFC!important;}
html.principal-theme-dark #principalFeatureWorkspace .principal-feature-heading p{color:#A0B3C6!important;}
html.principal-theme-dark #principalFeatureWorkspace .principal-feature-eyebrow,
html.principal-theme-dark #principalFeatureWorkspace .principal-feature-eyebrow i{color:#F0B84D!important;}
@media(max-width:1100px){
  #principalFeatureWorkspace{padding:24px!important;}
}
@media(max-width:768px){
  #pbiPrincipalTopbar ~ main.principal-feature-page{padding:18px 12px 26px!important;}
  #principalFeatureWorkspace{min-height:0!important;padding:22px 18px 25px!important;border-radius:16px!important;}
  #principalFeatureWorkspace .principal-feature-header-meta{padding-top:0!important;justify-content:flex-start!important;}
}
@media(max-width:520px){
  #principalFeatureWorkspace{padding:19px 14px 22px!important;}
  #principalFeatureWorkspace .principal-feature-heading-row{gap:12px!important;}
}

/* ==========================================================================
   Fixed-height workspace (same behaviour as the Dean portal).
   The sidebar, top bar and white work box keep a fixed size; the content
   scrolls INSIDE the white box instead of growing it and scrolling the page.
   Desktop only - below 901px the page flows and scrolls normally.
   ========================================================================== */
@media screen and (min-width:901px){
  html{background:#F3F6FA!important;}
  html.principal-theme-dark{background:#0A192F!important;}
  html,html body{height:100vh!important;max-height:100vh!important;overflow:hidden!important;}
  html body{margin:0!important;}

  /* A) Pages that already have an inner white box (dashboard, Evaluate Others,
        Evaluation Received): the page frame is fixed, the white box scrolls. */
  html body #pbiPrincipalTopbar ~ main.main.principal-dashboard,
  html body #pbiPrincipalTopbar ~ main.main.principal-feature-page{
    box-sizing:border-box!important;
    height:calc(100vh - 58px)!important;max-height:calc(100vh - 58px)!important;min-height:0!important;
    padding-top:14px!important;padding-bottom:16px!important;
    overflow:hidden!important;display:flex!important;flex-direction:column!important;
  }
  html body #pbiPrincipalTopbar ~ main.main.principal-dashboard > .principal-dashboard-shell,
  html body #pbiPrincipalTopbar ~ main.main.principal-feature-page > #principalFeatureWorkspace{
    flex:1 1 auto!important;height:auto!important;min-height:0!important;
    overflow-x:hidden!important;overflow-y:auto!important;
  }
  html body #pbiPrincipalTopbar ~ main.main.principal-dashboard > .principal-dashboard-shell > *{flex-shrink:0;}

  /* B) Plain pages (Tracker, Reports, Settings, ...): the main area itself is the
        white box - fixed height, scrolls inside, no pseudo-element panel. */
  html body #pbiPrincipalTopbar ~ main.main:not(.principal-dashboard):not(.principal-feature-page){
    box-sizing:border-box!important;
    margin:72px 16px 14px 264px!important;
    height:calc(100vh - 86px)!important;max-height:calc(100vh - 86px)!important;min-height:0!important;
    overflow-x:hidden!important;overflow-y:auto!important;
    background:#FFFFFF!important;
    border:1px solid #E2E8F0!important;border-radius:18px!important;
    box-shadow:0 2px 12px rgba(15,23,42,.025)!important;
  }
  html body #pbiPrincipalTopbar ~ main.main:not(.principal-dashboard):not(.principal-feature-page)::before{display:none!important;}
  html.principal-theme-dark body #pbiPrincipalTopbar ~ main.main:not(.principal-dashboard):not(.principal-feature-page){
    background:#0F1F3D!important;border-color:rgba(255,255,255,.10)!important;
    box-shadow:0 4px 20px rgba(0,0,0,.20)!important;
  }

  /* Scrollbars live inside the white box and don't shift the layout. */
  html body #pbiPrincipalTopbar ~ main.main:not(.principal-feature-page):not(.principal-dashboard),
  html body #pbiPrincipalTopbar ~ main.main > .principal-dashboard-shell,
  html body #pbiPrincipalTopbar ~ main.main > #principalFeatureWorkspace{
    overscroll-behavior:contain;scrollbar-gutter:stable;
    scrollbar-width:thin;scrollbar-color:#AEBAC8 #EEF2F6;
  }
  html.principal-theme-dark body #pbiPrincipalTopbar ~ main.main:not(.principal-feature-page):not(.principal-dashboard),
  html.principal-theme-dark body #pbiPrincipalTopbar ~ main.main > .principal-dashboard-shell,
  html.principal-theme-dark body #pbiPrincipalTopbar ~ main.main > #principalFeatureWorkspace{scrollbar-color:#2A4468 #0F1F3D;}
  #pbiPrincipalTopbar ~ main.main::-webkit-scrollbar,
  #pbiPrincipalTopbar ~ main.main > .principal-dashboard-shell::-webkit-scrollbar,
  #pbiPrincipalTopbar ~ main.main > #principalFeatureWorkspace::-webkit-scrollbar{width:10px;}
  #pbiPrincipalTopbar ~ main.main::-webkit-scrollbar-track,
  #pbiPrincipalTopbar ~ main.main > .principal-dashboard-shell::-webkit-scrollbar-track,
  #pbiPrincipalTopbar ~ main.main > #principalFeatureWorkspace::-webkit-scrollbar-track{background:#EEF2F6;border-radius:10px;}
  #pbiPrincipalTopbar ~ main.main::-webkit-scrollbar-thumb,
  #pbiPrincipalTopbar ~ main.main > .principal-dashboard-shell::-webkit-scrollbar-thumb,
  #pbiPrincipalTopbar ~ main.main > #principalFeatureWorkspace::-webkit-scrollbar-thumb{background:#AEBAC8;border:2px solid #EEF2F6;border-radius:10px;}
  html.principal-theme-dark #pbiPrincipalTopbar ~ main.main::-webkit-scrollbar-track,
  html.principal-theme-dark #pbiPrincipalTopbar ~ main.main > .principal-dashboard-shell::-webkit-scrollbar-track,
  html.principal-theme-dark #pbiPrincipalTopbar ~ main.main > #principalFeatureWorkspace::-webkit-scrollbar-track{background:#0F1F3D;}
  html.principal-theme-dark #pbiPrincipalTopbar ~ main.main::-webkit-scrollbar-thumb,
  html.principal-theme-dark #pbiPrincipalTopbar ~ main.main > .principal-dashboard-shell::-webkit-scrollbar-thumb,
  html.principal-theme-dark #pbiPrincipalTopbar ~ main.main > #principalFeatureWorkspace::-webkit-scrollbar-thumb{background:#2A4468;border-color:#0F1F3D;}
}
@media print{html,html body{height:auto!important;max-height:none!important;overflow:visible!important;}}
</style>
        <?php
    }
}

if (!function_exists('render_principal_sidebar')) {
    function render_principal_sidebar(string $active, array $me, string $scopeLabel, string $photo_src): void {
        static $principalShellRendered = false;
        if ($principalShellRendered) return;
        $principalShellRendered = true;
        principal_sidebar_assets();
        if ($active === 'evaluation') $active = 'evaluations';

        $headerTitles = [
            'dashboard' => 'Dashboard',
            'evaluations' => 'Evaluate Others',
            'tracker' => 'Evaluation Tracker',
            'results' => 'Evaluation Received',
            'reports' => 'Evaluation Reports',
            'settings' => 'Settings',
            'teachers' => 'Teachers',
            'staff' => 'School Staff',
        ];
        $headerTitle = $headerTitles[$active] ?? 'Principal Workspace';
        // One icon per page (same icons as the sidebar), drawn in the single Principal accent.
        $headerIcons = [
            'dashboard'   => 'fa-house',
            'evaluations' => 'fa-clipboard-list',
            'tracker'     => 'fa-satellite-dish',
            'results'     => 'fa-chart-bar',
            'reports'     => 'fa-chart-line',
            'settings'    => 'fa-gear',
            'teachers'    => 'fa-chalkboard-user',
            'staff'       => 'fa-users',
        ];
        $headerIcon = $headerIcons[$active] ?? 'fa-gauge';
        $headerSettings = isset($GLOBALS['settings']) && is_array($GLOBALS['settings']) ? $GLOBALS['settings'] : [];
        $headerPeriodParts = [];
        foreach ([
            $headerSettings['academic_year'] ?? '',
            $headerSettings['academic_term'] ?? $headerSettings['semester'] ?? '',
        ] as $part) {
            $part = trim((string)$part);
            if ($part !== '') $headerPeriodParts[] = $part;
        }
        if (!$headerPeriodParts && isset($GLOBALS['period']) && is_array($GLOBALS['period'])) {
            $fallbackPeriod = trim((string)($GLOBALS['period']['period_label'] ?? $GLOBALS['period']['semester'] ?? ''));
            if ($fallbackPeriod !== '') $headerPeriodParts[] = $fallbackPeriod;
        }
        $headerPeriodLabel = implode(' · ', $headerPeriodParts);
        $headerStatusData = isset($headerSettings['status']) && is_array($headerSettings['status']) ? $headerSettings['status'] : [];
        $headerStatus = strtolower((string)($headerStatusData['cls'] ?? 'gray'));
        if (!in_array($headerStatus, ['open', 'closed', 'amber', 'gray'], true)) $headerStatus = 'gray';
        $headerStatusLabel = trim((string)($headerStatusData['label'] ?? ''));
        $headerUserId = (int)($_SESSION['user_id'] ?? 0);

        $fullName = (string)($me['full_name'] ?? 'Principal');
        $parts = preg_split('/\s+/', trim($fullName));
        $initials = strtoupper(substr($parts[0] ?? 'P', 0, 1) . substr($parts[1] ?? '', 0, 1));
        if ($initials === '') $initials = 'P';

        $links = [
            'dashboard'   => ['principal_dashboard.php',          'fa-house',            'Dashboard'],
            'evaluations' => ['principal_evaluations.php',        'fa-clipboard-list',   'Evaluate Others'],
            'tracker'     => ['principal_evaluation_tracker.php', 'fa-satellite-dish',   'Evaluation Tracker'],
            'results'     => ['principal_results.php',            'fa-chart-bar',        "Evaluation Received"],
            'reports'     => ['principal_reports.php',             'fa-chart-line',      'Evaluation Reports'],
            'settings'    => ['principal_account_settings.php',   'fa-gear',            'Settings'],
        ];
        ?>
<aside class="sidebar" id="pbiSidebar">
    <div class="portal-brand sidebar-brand">
        <div class="portal-brand-logo"><img src="../image/pbi_logo" alt="PBI" onerror="this.style.display='none'"/></div>
        <div class="portal-brand-copy">
            <strong>Principal Workspace</strong>
        </div>
    </div>

    <div class="portal-sidebar-profile">
        <div class="portal-profile-avatar-wrap">
            <?php if (!empty($photo_src)): ?>
                <img class="portal-profile-avatar" src="<?= htmlspecialchars($photo_src) ?>" alt="<?= htmlspecialchars($fullName) ?>"/>
            <?php else: ?>
                <span class="portal-profile-avatar portal-profile-fallback"><?= htmlspecialchars($initials) ?></span>
            <?php endif; ?>
        </div>
        <div class="portal-profile-name"><?= htmlspecialchars($fullName) ?></div>
        <?php $sidebarDesignation = trim((string)($me['designation'] ?? '')); ?>
        <div class="portal-profile-role"><?= htmlspecialchars($sidebarDesignation !== '' ? $sidebarDesignation : 'Principal') ?></div>
    </div>

    <nav class="sidebar-nav portal-sidebar-nav" aria-label="Principal navigation">
        <div class="nav-section-label sb-nav-section-label">Main</div>
        <?php foreach (['dashboard', 'evaluations', 'tracker', 'results', 'reports'] as $key): ?>
            <?php [$href, $icon, $label] = $links[$key]; ?>
            <a href="<?= htmlspecialchars($href) ?>" class="nav-link <?= $key === $active ? 'active' : '' ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>>
                <i class="fa-solid <?= htmlspecialchars($icon) ?>"></i><span><?= htmlspecialchars($label) ?></span>
            </a>
        <?php endforeach; ?>

        <div class="nav-section-label sb-nav-section-label sidebar-section-secondary">Administration</div>
        <?php [$href, $icon, $label] = $links['settings']; ?>
        <a href="<?= htmlspecialchars($href) ?>" class="nav-link <?= $active === 'settings' ? 'active' : '' ?>"<?= $active === 'settings' ? ' aria-current="page"' : '' ?>>
            <i class="fa-solid <?= htmlspecialchars($icon) ?>"></i><span><?= htmlspecialchars($label) ?></span>
        </a>
    </nav>

    <div class="sidebar-footer">
        <a href="../logout.php" class="btn-logout-side" onclick="return principalLogoutPrompt(event)">
            <i class="fa-solid fa-power-off"></i><span>Log Out</span>
        </a>
    </div>
</aside>

<nav id="pbiPrincipalTopbar" aria-label="Principal page header">
    <div class="pbi-topbar-left">
        <button type="button" class="pbi-topbar-hamburger" id="pbiPrincipalMenuBtn" aria-label="Open navigation" aria-expanded="false">
            <i class="fa-solid fa-bars"></i>
        </button>
        <div class="pbi-topbar-title"><i class="fa-solid <?= htmlspecialchars($headerIcon, ENT_QUOTES, 'UTF-8') ?> pbi-topbar-title-icon" aria-hidden="true"></i><span><?= htmlspecialchars($headerTitle, ENT_QUOTES, 'UTF-8') ?></span></div>
    </div>
    <div class="pbi-topbar-right">
        <?php if ($headerPeriodLabel !== ''): ?>
        <div class="pbi-topbar-period <?= htmlspecialchars($headerStatus, ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($headerPeriodLabel . ($headerStatusLabel !== '' ? ' — ' . $headerStatusLabel : ''), ENT_QUOTES, 'UTF-8') ?>">
            <i class="fa-solid fa-calendar-check"></i>
            <span><?= htmlspecialchars($headerPeriodLabel, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <?php endif; ?>
        <div class="pbi-topbar-bell-wrap" id="pbiPrincipalHeaderBell">
            <button type="button" class="pbi-topbar-bell" id="pbiPrincipalHeaderBellBtn" aria-label="Notifications" aria-haspopup="true" aria-expanded="false">
                <i class="fa-regular fa-bell"></i>
                <span class="pbi-topbar-count" id="pbiPrincipalHeaderBellCount">0</span>
            </button>
            <div class="pbi-topbar-panel" id="pbiPrincipalHeaderBellPanel" role="dialog" aria-label="Notifications">
                <div class="pbi-topbar-panel-head">
                    <strong><i class="fa-solid fa-bell" style="color:#B8801F;margin-right:6px;"></i>Notifications</strong>
                    <button type="button" class="pbi-topbar-mark-read" id="pbiPrincipalHeaderMarkRead">Mark all read</button>
                </div>
                <ul class="pbi-topbar-list" id="pbiPrincipalHeaderBellList"></ul>
                <div class="pbi-topbar-foot" id="pbiPrincipalHeaderBellFoot">Checking for updates…</div>
            </div>
        </div>
    </div>
</nav>
<script>
(function(){
    const topbar = document.getElementById('pbiPrincipalTopbar');
    const sidebar = document.getElementById('pbiSidebar');
    const menuBtn = document.getElementById('pbiPrincipalMenuBtn');
    const bell = document.getElementById('pbiPrincipalHeaderBell');
    const bellBtn = document.getElementById('pbiPrincipalHeaderBellBtn');
    const bellPanel = document.getElementById('pbiPrincipalHeaderBellPanel');
    const bellCount = document.getElementById('pbiPrincipalHeaderBellCount');
    const bellList = document.getElementById('pbiPrincipalHeaderBellList');
    const markReadBtn = document.getElementById('pbiPrincipalHeaderMarkRead');
    const bellFoot = document.getElementById('pbiPrincipalHeaderBellFoot');
    if (!topbar || !sidebar || !bell || !bellBtn || !bellPanel || !bellList) return;

    const endpoint = 'principal_notifications.php';
    const storeKey = 'pbi_principal_notifications_<?= $headerUserId ?>';
    const iconMap = { warn: 'fa-triangle-exclamation', good: 'fa-circle-check', info: 'fa-circle-info' };
    // Notifications are kept permanently in this browser (read or unread) and the
    // list simply scrolls. This ceiling only exists to protect localStorage.
    const MAX_STORED = 5000;
    let stored = [];
    let lastSig = '';
    try {
        const saved = JSON.parse(localStorage.getItem(storeKey) || '[]');
        if (Array.isArray(saved)) stored = saved.filter(n => n && n.id && n.text);
    } catch (e) {}

    function save(){ try { localStorage.setItem(storeKey, JSON.stringify(stored.slice(0, MAX_STORED))); } catch (e) {} }
    // Only same-site Principal pages are followed (e.g. principal_reports.php?...).
    function safeHref(u){
        u = typeof u === 'string' ? u.trim() : '';
        return /^principal_[a-z_]+\.php(\?[^\s]*)?$/.test(u) ? u : '';
    }
    function notificationHref(item){
        const direct = safeHref(item && item.url);
        if (direct) return direct;
        const id = String(item && item.id || '');
        if (/^evaluation-\d+$/.test(id)) return 'principal_results.php';
        if (id === 'students-pending' || id === 'targets-complete' || /^closing-/.test(id)) return 'principal_evaluation_tracker.php';
        if (id === 'no-roster') return 'principal_dashboard.php';
        if (id === 'no-period' || id === 'structure-inactive' || id === 'all-clear' || /^ea-period-/.test(id) || /^ea-schedule-/.test(id) || /^sys-/.test(id)) return 'principal_dashboard.php';
        return 'principal_dashboard.php';
    }
    function updateCount(){
        const count = stored.filter(n => !n.read).length;
        bellCount.textContent = count > 9 ? '9+' : String(count);
        bellCount.classList.toggle('show', count > 0);
    }
    function render(items){
        const byId = new Map(stored.map(n => [String(n.id), n]));
        (Array.isArray(items) ? items : []).forEach(raw => {
            if (!raw || raw.id == null || !String(raw.text || '').trim()) return;
            const id = String(raw.id);
            const level = Object.prototype.hasOwnProperty.call(iconMap, raw.level) ? raw.level : 'info';
            const prior = byId.get(id);
            byId.set(id, { id, text: String(raw.text), level, icon: /^fa-[a-z0-9-]+$/.test(raw.icon || '') ? raw.icon : '', url: safeHref(raw.url) || (prior && prior.url) || '', created_at: raw.created_at || (prior && prior.created_at) || new Date().toISOString(), read: prior ? !!prior.read : false });
        });
        stored = Array.from(byId.values()).sort((a,b) => new Date(b.created_at || 0) - new Date(a.created_at || 0)).slice(0, MAX_STORED);
        save();
        // Skip the rebuild when nothing changed, so the 2-second refresh never resets the scroll
        // position or swallows a click.
        const sig = JSON.stringify(stored.map(n => [n.id, n.text, n.read ? 1 : 0, n.url, n.icon, n.level]));
        if (sig === lastSig) { updateCount(); return; }
        lastSig = sig;
        const prevTop = bellList.scrollTop;
        bellList.innerHTML = '';
        if (!stored.length) {
            const empty = document.createElement('li');
            empty.className = 'pbi-topbar-empty';
            empty.textContent = 'No notifications right now.';
            bellList.appendChild(empty);
        } else {
            stored.forEach(item => {
                const li = document.createElement('li');
                li.dataset.id = String(item.id);
                if (!item.read) li.classList.add('unseen');
                const link = document.createElement('a');
                link.className = 'pbi-topbar-notification-link';
                link.href = notificationHref(item);
                link.dataset.notificationId = String(item.id);
                link.setAttribute('aria-label', item.text + ' Open related page');
                const icon = document.createElement('i');
                icon.className = 'fa-solid ' + (item.icon || iconMap[item.level] || iconMap.info);
                icon.setAttribute('aria-hidden', 'true');
                const label = document.createElement('span');
                label.textContent = item.text;
                link.append(icon, label);
                li.appendChild(link);
                bellList.appendChild(li);
            });
        }
        bellList.scrollTop = prevTop;
        updateCount();
    }
    async function poll(){
        try {
            const response = await fetch(endpoint + '?_=' + Date.now(), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
            if (response.status === 401) { bellFoot.textContent = 'Session expired — reload this page'; return; }
            if (!response.ok) throw new Error('http ' + response.status);
            const data = await response.json();
            if (!data || !data.ok) throw new Error('feed unavailable');
            render(data.items || data.notifications || []);
            bellFoot.textContent = 'Updated ' + new Date((data.ts || Date.now()/1000) * 1000).toLocaleTimeString();
        } catch (e) { bellFoot.textContent = 'Offline — retrying'; }
    }
    if (menuBtn) menuBtn.addEventListener('click', function(){
        const isOpen = sidebar.classList.toggle('open');
        menuBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        menuBtn.setAttribute('aria-label', isOpen ? 'Close navigation' : 'Open navigation');
    });
    bellBtn.addEventListener('click', function(event){
        event.stopPropagation();
        const isOpen = bellPanel.classList.toggle('open');
        bellBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        if (isOpen) poll();
    });
    markReadBtn.addEventListener('click', function(event){
        event.stopPropagation();
        stored = stored.map(n => Object.assign({}, n, { read: true }));
        save();
        render(stored);
    });
    bellList.addEventListener('click', function(event){
        const link = event.target.closest('a[data-notification-id]');
        if (!link) return;
        const id = link.dataset.notificationId;
        stored = stored.map(n => String(n.id) === id ? Object.assign({}, n, { read: true }) : n);
        save();
        const item = link.closest('li[data-id]');
        if (item) item.classList.remove('unseen');
        updateCount();
        // Let the anchor's default action navigate to the related page.
    });
    document.addEventListener('click', function(event){
        if (!bell.contains(event.target)) { bellPanel.classList.remove('open'); bellBtn.setAttribute('aria-expanded', 'false'); }
        if (window.matchMedia('(max-width: 900px)').matches && !sidebar.contains(event.target) && !(menuBtn && menuBtn.contains(event.target))) {
            sidebar.classList.remove('open');
            if (menuBtn) menuBtn.setAttribute('aria-expanded', 'false');
        }
    });
    document.addEventListener('keydown', function(event){
        if (event.key === 'Escape') {
            bellPanel.classList.remove('open'); bellBtn.setAttribute('aria-expanded', 'false');
            sidebar.classList.remove('open'); if (menuBtn) menuBtn.setAttribute('aria-expanded', 'false');
        }
    });
    render(stored);
    poll();
    setInterval(function(){ if (!document.hidden) poll(); }, 2000);
    document.addEventListener('visibilitychange', function(){ if (!document.hidden) poll(); });
})();
</script>
        <?php
        // Custom logout confirmation. The browser's native confirm() always prints a
        // "<site> says" header that cannot be removed, so we use an in-page modal instead.
        // It sits outside the fixed sidebar so it always overlays the whole page, and it is
        // only printed once even if the sidebar is rendered from several code paths.
        static $logoutModalPrinted = false;
        if (!$logoutModalPrinted) {
            $logoutModalPrinted = true;
            ?>
<style>
#principalLogoutModal{position:fixed;inset:0;z-index:100000;display:none;align-items:center;justify-content:center;background:rgba(3,10,22,.65);backdrop-filter:blur(3px);}
#principalLogoutModal.open{display:flex;}
#principalLogoutModal .plm-box{width:min(380px,90vw);background:#172A45;color:#E0E6F0;border:1px solid rgba(255,255,255,.10);border-radius:16px;padding:24px;box-shadow:0 20px 60px rgba(0,0,0,.55);font-family:'DM Sans',sans-serif;}
#principalLogoutModal .plm-title{font-size:18px;font-weight:700;color:#fff;margin:0 0 6px;}
#principalLogoutModal .plm-text{font-size:14px;color:#A0B3C6;margin:0 0 20px;}
#principalLogoutModal .plm-actions{display:flex;justify-content:flex-end;gap:10px;}
#principalLogoutModal button{font-family:inherit;font-size:14px;font-weight:600;padding:9px 18px;border-radius:10px;cursor:pointer;border:1px solid rgba(255,255,255,.12);}
#principalLogoutModal .plm-cancel{background:transparent;color:#E0E6F0;}
#principalLogoutModal .plm-cancel:hover{background:rgba(255,255,255,.06);}
#principalLogoutModal .plm-ok{background:#D99A2B;border-color:#D99A2B;color:#0A192F;}
#principalLogoutModal .plm-ok:hover{background:#E8AC3E;border-color:#E8AC3E;}
</style>
<div id="principalLogoutModal" role="dialog" aria-modal="true" aria-labelledby="plmTitle">
    <div class="plm-box">
        <h3 class="plm-title" id="plmTitle">Log out</h3>
        <p class="plm-text">Are you sure you want to log out?</p>
        <div class="plm-actions">
            <button type="button" class="plm-cancel" id="plmCancel">Cancel</button>
            <button type="button" class="plm-ok" id="plmOk">Log Out</button>
        </div>
    </div>
</div>
<script>
function principalLogoutPrompt(e) {
    if (e) e.preventDefault();
    var m = document.getElementById('principalLogoutModal');
    var link = document.querySelector('.btn-logout-side');
    var href = link ? link.getAttribute('href') : '../logout.php';
    if (!m) { window.location.href = href; return false; }
    var close = function () { m.classList.remove('open'); };
    m.classList.add('open');
    document.getElementById('plmCancel').onclick = close;
    document.getElementById('plmOk').onclick = function () { window.location.href = href; };
    m.onclick = function (ev) { if (ev.target === m) close(); };
    document.addEventListener('keydown', function esc(ev) {
        if (ev.key === 'Escape') { close(); document.removeEventListener('keydown', esc); }
    });
    document.getElementById('plmCancel').focus();
    return false;
}
</script>
            <?php
        }
        ?>
        <?php
    }
}