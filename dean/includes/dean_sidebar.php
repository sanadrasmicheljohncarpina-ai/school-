<?php
// includes/dean_sidebar.php
// Shared Dean sidebar — SINGLE SOURCE OF TRUTH for nav items, so no
// individual dean_*.php page ever hardcodes its own copy again.
//
// Usage from any dean_*.php page (after $me and $photo_src are set):
//
//   $active = 'dashboard';                      // dashboard|evaluation|tracker|results|reports|settings
//   $sidebarScope = HIGHER_ED_LABEL . ' Division'; // or any string you want under the name/role
//   include __DIR__ . '/includes/dean_sidebar.php';
//
// Phase 2 revision: the Dean Portal is scoped to evaluation duties only
// (evaluate Teachers/Staff/Executive Assistant, monitor student
// participation, view own results, run reports). Personnel management
// (Faculty/Staff directories) is out of scope for the Dean and was
// removed from the nav — see dean_faculty.php / dean_staff.php, which
// are no longer linked from this portal. The underlying roster/service
// functions those pages used are untouched since other roles still rely
// on them.

$displayName = (string)($me['full_name'] ?? 'Dean');
$initials = '';
foreach (preg_split('/\s+/', trim($displayName)) as $part) {
    if ($part !== '') $initials .= strtoupper(substr($part, 0, 1));
    if (strlen($initials) >= 2) break;
}
if ($initials === '') $initials = 'D';
$hasPhoto = !empty($me['photo']);
$profileFallbackSrc = '../image/pbi_logo';

$navItems = [
    'dashboard'  => ['dean_dashboard.php',          'fa-gauge',           'Dashboard'],
    'evaluation' => ['dean_evaluation.php',         'fa-clipboard-check', 'Evaluate Others'],
    'tracker'    => ['dean_evaluation_tracker.php', 'fa-satellite-dish',  'Evaluation Tracker'],
    'results'    => ['dean_results.php',            'fa-star-half-stroke',"Evaluation Received"],
    'reports'    => ['dean_reports.php',            'fa-chart-line',      'Evaluation Reports'],
    'settings'   => ['dean_account_settings.php',   'fa-gear',            'Settings'],
];
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand portal-brand">
        <div class="portal-brand-logo">
            <img src="../image/pbi_logo" alt="PBI" onerror="this.style.display='none'"/>
        </div>
        <div class="portal-brand-copy">
            <strong>Dean Workspace</strong>
        </div>
    </div>

    <div class="portal-sidebar-profile">
        <div class="portal-profile-avatar-wrap">
            <img class="portal-profile-avatar"
                 src="<?= htmlspecialchars($hasPhoto ? $photo_src : $profileFallbackSrc) ?>"
                 alt="Profile"
                 onerror="if (this.dataset.fallbackApplied !== '1') { this.dataset.fallbackApplied='1'; this.src='<?= htmlspecialchars($profileFallbackSrc) ?>'; }"/>
        </div>
        <div class="portal-profile-name"><?= htmlspecialchars($me['full_name'] ?? 'Dean') ?></div>
        <?php $sidebarDesignation = trim((string)($me['designation'] ?? '')); ?>
        <div class="portal-profile-role"><?= htmlspecialchars($sidebarDesignation !== '' ? $sidebarDesignation : 'Dean') ?></div>
    </div>

    <nav class="sidebar-nav portal-sidebar-nav">
        <div class="nav-section-label">Main</div>
        <?php foreach ($navItems as $key => [$href, $icon, $label]): ?>
            <?php if ($key === 'settings'): ?>
                <div class="nav-section-label sidebar-section-secondary">Account</div>
            <?php endif; ?>
            <a href="<?= htmlspecialchars($href) ?>" class="nav-link <?= (isset($active) && $active === $key) ? 'active' : '' ?>">
                <i class="fa-solid <?= htmlspecialchars($icon) ?>"></i><span><?= htmlspecialchars($label) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-footer">
        <a href="dean_logout.php" class="btn-logout-side" onclick="return deanLogoutPrompt(event)">
            <i class="fa-solid fa-power-off"></i><span>Log Out</span>
        </a>
    </div>

    <script>
    // Appearance toggle (Dark / Light) — shared PBI theme preference.
    function applyAppearance(mode) {
        const normalized = mode === 'dark' ? 'dark' : 'light';
        document.documentElement.classList.toggle('dark-theme', normalized === 'dark');
        const value = document.getElementById('appearanceVal');
        if (value) value.textContent = normalized === 'light' ? 'Light' : 'Dark';
        const lightBtn = document.getElementById('appearanceLightBtn');
        const darkBtn  = document.getElementById('appearanceDarkBtn');
        if (lightBtn) lightBtn.classList.toggle('active', normalized === 'light');
        if (darkBtn) darkBtn.classList.toggle('active', normalized === 'dark');
    }
    function setAppearance(mode) {
        const normalized = mode === 'dark' ? 'dark' : 'light';
        localStorage.setItem('pbi_theme', normalized);
        applyAppearance(normalized);
    }
    function toggleAppearance(e) {
        if (e) e.stopPropagation();
        const current = localStorage.getItem('pbi_theme') === 'dark' ? 'dark' : 'light';
        setAppearance(current === 'dark' ? 'light' : 'dark');
    }
    document.addEventListener('DOMContentLoaded', function() {
        const savedTheme = localStorage.getItem('pbi_theme');
        const theme = savedTheme === 'dark' ? 'dark' : 'light';
        localStorage.setItem('pbi_theme', theme);
        applyAppearance(theme);
    });
    </script>

    <style>
    /* Keep the Dean navigation anchored to the viewport while the page content scrolls. */
    .sidebar#sidebar{
        position:sticky !important;
        top:0 !important;
        align-self:flex-start !important;
        flex:0 0 248px !important;
        width:248px !important;
        height:100vh !important;
        min-height:100vh !important;
        max-height:100vh !important;
        overflow:hidden !important;
        z-index:1000 !important;
    }
    #sidebar .portal-sidebar-nav{
        min-height:0 !important;
        flex:1 1 auto !important;
        overflow-y:auto !important;
        overscroll-behavior:contain;
    }
    @media(max-width:768px){
        .sidebar#sidebar{
            position:relative !important;
            top:auto !important;
            align-self:stretch !important;
            flex:0 0 auto !important;
            width:100% !important;
            height:auto !important;
            min-height:0 !important;
            max-height:none !important;
        }
        #sidebar .portal-sidebar-nav{max-height:42vh !important;}
    }
    #deanLogoutModal{position:fixed;top:0;left:0;right:0;bottom:0;width:100vw;height:100vh;z-index:2147483000;display:none;align-items:center;justify-content:center;background:rgba(3,10,22,.65);backdrop-filter:blur(3px);}
    #deanLogoutModal.open{display:flex;}
    #deanLogoutModal .dlm-box{width:min(380px,90vw);background:#172A45;color:#E0E6F0;border:1px solid rgba(255,255,255,.10);border-radius:16px;padding:24px;box-shadow:0 20px 60px rgba(0,0,0,.55);font-family:inherit;}
    #deanLogoutModal .dlm-title{font-size:18px;font-weight:700;color:#fff;margin:0 0 6px;}
    #deanLogoutModal .dlm-text{font-size:14px;color:#A0B3C6;margin:0 0 20px;}
    #deanLogoutModal .dlm-actions{display:flex;justify-content:flex-end;gap:10px;}
    #deanLogoutModal button{font-family:inherit;font-size:14px;font-weight:600;padding:9px 18px;border-radius:10px;cursor:pointer;border:1px solid rgba(255,255,255,.12);}
    #deanLogoutModal .dlm-cancel{background:transparent;color:#E0E6F0;}
    #deanLogoutModal .dlm-cancel:hover{background:rgba(255,255,255,.06);}
    #deanLogoutModal .dlm-ok{background:#7C5FD9;border-color:#7C5FD9;color:#fff;}
    #deanLogoutModal .dlm-ok:hover{background:#9C85F0;}
    </style>
    <div id="deanLogoutModal" role="dialog" aria-modal="true" aria-labelledby="dlmTitle">
        <div class="dlm-box">
            <h3 class="dlm-title" id="dlmTitle">Log out</h3>
            <p class="dlm-text">Are you sure you want to logout?</p>
            <div class="dlm-actions">
                <button type="button" class="dlm-cancel" id="dlmCancel">Cancel</button>
                <button type="button" class="dlm-ok" id="dlmOk">Log Out</button>
            </div>
        </div>
    </div>
    <script>
    // Custom logout confirmation (replaces the browser's native confirm(), which
    // always shows a "localhost says" header that cannot be removed).
    // The modal is declared inside <aside class="sidebar">, which is position:sticky with
    // z-index:1000 -- that creates a stacking context, so the overlay could never rise
    // above the page header. Re-parent it to <body> so it dims the whole screen.
    function deanMountLogoutModal() {
        var m = document.getElementById('deanLogoutModal');
        if (m && m.parentNode !== document.body) document.body.appendChild(m);
        return m;
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', deanMountLogoutModal);
    else deanMountLogoutModal();
    function deanLogoutPrompt(e) {
        if (e) e.preventDefault();
        var m = deanMountLogoutModal();
        var link = document.querySelector('.btn-logout-side');
        var href = link ? link.getAttribute('href') : 'dean_logout.php';
        if (!m) { window.location.href = href; return false; }
        m.classList.add('open');
        var close = function(){ m.classList.remove('open'); };
        document.getElementById('dlmCancel').onclick = close;
        document.getElementById('dlmOk').onclick = function(){ window.location.href = href; };
        m.onclick = function(ev){ if (ev.target === m) close(); };
        document.addEventListener('keydown', function esc(ev){
            if (ev.key === 'Escape') { close(); document.removeEventListener('keydown', esc); }
        });
        document.getElementById('dlmCancel').focus();
        return false;
    }
    </script>
</aside>
<?php include __DIR__ . '/dean_portal_header.php'; ?>