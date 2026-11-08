<?php
// Shared faculty-inspired top navigation for the Dean workspace.
$deanHeaderScript = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
$deanHeaderTitles = [
    'dean_dashboard.php'          => 'Dashboard',
    'dean_evaluation.php'         => 'Evaluate Others',
    'dean_evaluate.php'           => 'Evaluate Others',
    'dean_evaluation_tracker.php' => 'Evaluation Tracker',
    'dean_evaluations.php'        => 'Evaluation Tracker',
    'dean_results.php'            => 'Evaluation Received',
    'dean_reports.php'            => 'Evaluation Reports',
    'dean_account_settings.php'   => 'Account Settings',
    'dean_faculty.php'            => 'Faculty Directory',
    'dean_staff.php'              => 'Staff Directory',
];
$deanHeaderTitle = $deanHeaderTitles[$deanHeaderScript] ?? 'Dean Workspace';
$deanHeaderIcons = [
    'dean_dashboard.php'          => 'fa-gauge',
    'dean_evaluation.php'         => 'fa-clipboard-check',
    'dean_evaluate.php'           => 'fa-clipboard-check',
    'dean_evaluation_tracker.php' => 'fa-satellite-dish',
    'dean_evaluations.php'        => 'fa-satellite-dish',
    'dean_results.php'            => 'fa-star-half-stroke',
    'dean_reports.php'            => 'fa-chart-line',
    'dean_account_settings.php'   => 'fa-gear',
    'dean_faculty.php'            => 'fa-users',
    'dean_staff.php'              => 'fa-user-tie',
];
$deanHeaderIcon = $deanHeaderIcons[$deanHeaderScript] ?? 'fa-gauge';

$deanHeaderSettings = (isset($GLOBALS['settings']) && is_array($GLOBALS['settings']))
    ? $GLOBALS['settings']
    : ((isset($settings) && is_array($settings)) ? $settings : []);
$deanHeaderYear = trim((string)($deanHeaderSettings['academic_year'] ?? ''));
$deanHeaderTerm = trim((string)($deanHeaderSettings['academic_term'] ?? ''));

// Account Settings and a few legacy views do not load the period object
// themselves, so safely obtain the same source-of-truth period where available.
if (($deanHeaderYear === '' || $deanHeaderTerm === '')
    && isset($GLOBALS['mysqli'])
    && $GLOBALS['mysqli'] instanceof mysqli) {
    $deanSettingsService = dirname(__DIR__, 2) . '/shared/system_settings_service.php';
    if (is_file($deanSettingsService)) {
        require_once $deanSettingsService;
        try {
            if (function_exists('get_school_head_settings')) {
                $deanFallbackSettings = get_school_head_settings($GLOBALS['mysqli'], 'dean');
            } elseif (function_exists('get_system_settings')) {
                $deanFallbackSettings = get_system_settings($GLOBALS['mysqli']);
            } else {
                $deanFallbackSettings = [];
            }
            if (is_array($deanFallbackSettings)) {
                $deanHeaderYear = $deanHeaderYear !== ''
                    ? $deanHeaderYear
                    : trim((string)($deanFallbackSettings['academic_year'] ?? ''));
                $deanHeaderTerm = $deanHeaderTerm !== ''
                    ? $deanHeaderTerm
                    : trim((string)($deanFallbackSettings['academic_term'] ?? ''));
            }
        } catch (Throwable $deanHeaderPeriodError) {
            // Keep the header usable if period settings are temporarily unavailable.
        }
    }
}
$deanHeaderPeriod = trim(
    $deanHeaderYear
    . (($deanHeaderYear !== '' && $deanHeaderTerm !== '') ? ' — ' : '')
    . $deanHeaderTerm
);
if ($deanHeaderPeriod === '') $deanHeaderPeriod = 'Current Academic Period';
?>
<style>
.dean-portal-topbar,
.dean-portal-topbar * { box-sizing: border-box; }
.dean-portal-topbar {
    position: fixed;
    top: 0;
    left: 248px;
    right: 0;
    height: 66px;
    padding: 0 28px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    background: #FFFFFF;
    color: #12263A;
    border-bottom: 1px solid #DCE7F1;
    box-shadow: 0 2px 8px rgba(22, 48, 76, .035);
    font-family: 'DM Sans', Inter, Arial, sans-serif;
    z-index: 9000;
}
.dean-header-page-title {
    min-width: 0;
    font-family: 'Rajdhani', 'DM Sans', Arial, sans-serif;
    color: #12263A;
    font-size: 22px;
    line-height: 1.1;
    font-weight: 700;
    letter-spacing: .25px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.dean-header-actions { display:flex; align-items:center; justify-content:flex-end; gap:14px; min-width:0; }
.dean-header-period {
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    min-height:34px;
    max-width:min(440px, 48vw);
    padding:7px 14px;
    border:1px solid #C9DAFF;
    border-radius:999px;
    background:#F0F5FF;
    color:#2458DA;
    font-size:12px;
    font-weight:700;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}
.dean-header-period i { flex:0 0 auto; font-size:12px; }
.dean-header-period span { overflow:hidden; text-overflow:ellipsis; }
.dean-header-notif-wrap { position:relative; flex:0 0 auto; }
.dean-header-bell {
    position:relative;
    display:flex;
    align-items:center;
    justify-content:center;
    width:42px;
    height:42px;
    padding:0;
    border:1px solid #DCE7F1;
    border-radius:50%;
    background:#F8FAFC;
    color:#D97706;
    cursor:pointer;
    font:inherit;
    transition:background .16s ease, border-color .16s ease, transform .16s ease;
}
.dean-header-bell:hover { background:#FFF7ED; border-color:#F3C98B; transform:translateY(-1px); }
.dean-header-bell > i { font-size:16px; }
.dean-header-notif-badge {
    position:absolute;
    top:-5px;
    right:-5px;
    min-width:18px;
    height:18px;
    padding:0 4px;
    display:none;
    align-items:center;
    justify-content:center;
    border:2px solid #FFFFFF;
    border-radius:99px;
    background:#EF4444;
    color:#FFFFFF;
    font-size:10px;
    line-height:1;
    font-weight:800;
}
.dean-header-notif-dropdown {
    position:absolute;
    top:calc(100% + 12px);
    right:0;
    width:min(360px, calc(100vw - 28px));
    max-height:none;
    overflow:hidden;
    padding:15px;
    border:1px solid #DCE7F1;
    border-radius:14px;
    background:#FFFFFF;
    color:#12263A;
    box-shadow:0 18px 48px rgba(15, 35, 60, .18);
    opacity:0;
    visibility:hidden;
    transform:translateY(-5px);
    transition:opacity .16s ease, transform .16s ease, visibility .16s ease;
    z-index:9010;
}
.dean-header-notif-dropdown.open { opacity:1; visibility:visible; transform:translateY(0); }
.dean-header-notif-heading {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    margin-bottom:11px;
    padding-bottom:10px;
    border-bottom:1px solid #E7EDF4;
    color:#52677D;
    font-size:11px;
    font-weight:800;
    letter-spacing:.7px;
    text-transform:uppercase;
}
.dean-header-live-dot { width:7px; height:7px; border-radius:50%; background:#10B981; box-shadow:0 0 0 4px rgba(16,185,129,.10); }
.dean-header-live-dot.stale { background:#94A3B8; box-shadow:none; }
.dean-header-notif-list {
    display:flex; flex-direction:column; gap:8px; list-style:none; margin:0; padding:0 4px 0 0;
    max-height:min(340px, 60vh);
    overflow-y:auto; overflow-x:hidden;
    overscroll-behavior:contain;
    -webkit-overflow-scrolling:touch;
    scrollbar-width:thin; scrollbar-color:#AEBAC8 transparent;
}
.dean-header-notif-list::-webkit-scrollbar { width:8px; }
.dean-header-notif-list::-webkit-scrollbar-track { background:transparent; }
.dean-header-notif-list::-webkit-scrollbar-thumb { background:#AEBAC8; border-radius:8px; }
.dean-header-notif-list::-webkit-scrollbar-thumb:hover { background:#8F9CAB; }
html.dark-theme .dean-header-notif-list { scrollbar-color:#2A4468 transparent; }
html.dark-theme .dean-header-notif-list::-webkit-scrollbar-thumb { background:#2A4468; }
html.dark-theme .dean-header-notif-list::-webkit-scrollbar-thumb:hover { background:#385A86; }
.dean-header-notif-list li {
    flex:0 0 auto;   /* never squash items when the list is taller than its panel */
    display:flex;
    align-items:flex-start;
    gap:9px;
    padding:10px 11px;
    border:1px solid #E8EEF5;
    border-radius:9px;
    background:#F8FAFC;
    color:#334155;
    font-size:12px;
    line-height:1.45;
    overflow-wrap:anywhere;
}
.dean-header-notif-list li > i { margin-top:2px; color:#D97706; flex:0 0 auto; }
.dean-header-notif-list li.notif-link { cursor:pointer; transition:background-color .15s ease, border-color .15s ease; }
.dean-header-notif-list li.notif-link:hover, .dean-header-notif-list li.notif-link:focus-visible { background:#EEF4FF; border-color:#BFDBFE; outline:none; }
.dean-header-notif-list li.notif-evaluation > i { color:#2563EB; }
.dean-header-notif-empty { color:#64748B !important; font-style:italic; }
/* The light-theme stylesheet gives main.main its own important padding.
   Use a more specific selector so content begins below the fixed header on every Dean page. */
html body main.main { padding-top:74px !important; }
@media (max-width:1000px) {
    .dean-portal-topbar { left:248px; padding:0 20px; }
    .dean-header-actions { gap:9px; }
    .dean-header-period { max-width:42vw; }
}
@media (max-width:768px) {
    .dean-portal-topbar {
        position:sticky;
        top:0;
        left:auto;
        right:auto;
        width:100%;
        height:auto;
        min-height:64px;
        flex:0 0 auto;
        padding:10px 16px;
        gap:10px;
        z-index:900;
    }
    .dean-header-page-title { font-size:21px; }
    .dean-header-actions { gap:8px; }
    .dean-header-period { max-width:54vw; padding:7px 10px; font-size:11px; }
    html body main.main { padding-top:26px !important; }
}
@media (max-width:480px) {
    .dean-portal-topbar { align-items:flex-start; flex-direction:column; }
    .dean-header-page-title { max-width:100%; }
    .dean-header-actions { width:100%; justify-content:space-between; }
    .dean-header-period { max-width:calc(100vw - 92px); }
    .dean-header-notif-dropdown { right:-2px; }
}

/* ---- Dean accent (purple, matches the Dean sidebar / --portal-accent) ---- */
.dean-portal-topbar { --dean-accent:#7C5FD9; --dean-accent-h:#5F45B8; --dean-accent-bg:rgba(124,95,217,.10); --dean-accent-line:rgba(124,95,217,.30); }
html.dark-theme .dean-portal-topbar { --dean-accent:#9C85F0; --dean-accent-h:#C4B5FD; --dean-accent-bg:rgba(156,133,240,.16); --dean-accent-line:rgba(156,133,240,.38); }
.dean-header-title-wrap { display:flex; align-items:center; gap:11px; min-width:0; }
.dean-header-title-wrap .dean-header-page-title { min-width:0; }
.dean-header-page-icon { flex:0 0 auto; display:inline-flex; align-items:center; justify-content:center; color:var(--dean-accent); font-size:22px; line-height:1; }
.dean-header-page-icon i { color:var(--dean-accent); }
.dean-portal-topbar { border-bottom-color:var(--dean-accent-line); }
.dean-portal-topbar .dean-header-period { background:var(--dean-accent-bg); border-color:var(--dean-accent-line); color:var(--dean-accent-h); }
html.dark-theme .dean-portal-topbar .dean-header-period { color:var(--dean-accent-h); }
.dean-header-notif-list li.notif-evaluation > i { color:var(--dean-accent); }
@media (max-width:768px) { .dean-header-page-icon { font-size:20px; } }
</style>
<header class="dean-portal-topbar" aria-label="Dean workspace header">
    <div class="dean-header-title-wrap">
        <span class="dean-header-page-icon" aria-hidden="true"><i class="fa-solid <?= htmlspecialchars($deanHeaderIcon, ENT_QUOTES, 'UTF-8') ?>"></i></span>
        <div class="dean-header-page-title"><?= htmlspecialchars($deanHeaderTitle, ENT_QUOTES, 'UTF-8') ?></div>
    </div>
    <div class="dean-header-actions">
        <div class="dean-header-period" title="Current academic year and term">
            <i class="fa-solid fa-calendar-check" aria-hidden="true"></i>
            <span><?= htmlspecialchars($deanHeaderPeriod, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="dean-header-notif-wrap">
            <button type="button" class="dean-header-bell" id="deanHeaderBellBtn" aria-haspopup="true" aria-expanded="false" aria-controls="deanHeaderNotifDropdown" aria-label="Notifications">
                <i class="fa-solid fa-bell" aria-hidden="true"></i>
                <span class="dean-header-notif-badge" id="deanHeaderNotifBadge"></span>
            </button>
            <div class="dean-header-notif-dropdown" id="deanHeaderNotifDropdown" role="menu" aria-hidden="true">
                <div class="dean-header-notif-heading">
                    <span>Notifications</span>
                    <span class="dean-header-live-dot" id="deanHeaderNotifLiveDot" title="Live updates"></span>
                </div>
                <ul class="dean-header-notif-list" id="deanHeaderNotifList" aria-live="polite">
                    <li class="dean-header-notif-empty">Loading notifications…</li>
                </ul>
            </div>
        </div>
    </div>
</header>
<script>
(function () {
    const bell = document.getElementById('deanHeaderBellBtn');
    const dropdown = document.getElementById('deanHeaderNotifDropdown');
    const badge = document.getElementById('deanHeaderNotifBadge');
    const list = document.getElementById('deanHeaderNotifList');
    const liveDot = document.getElementById('deanHeaderNotifLiveDot');
    if (!bell || !dropdown || !badge || !list || bell.dataset.initialized === '1') return;
    bell.dataset.initialized = '1';

    const STORE_KEY = 'pbiDeanPersistentNotifications';
    const MAX_ITEMS = 1000;   // storage safety ceiling only; the list shows everything and scrolls
    let stored = [];
    try {
        const raw = localStorage.getItem(STORE_KEY);
        const parsed = raw ? JSON.parse(raw) : [];
        stored = Array.isArray(parsed) ? parsed : [];
    } catch (e) { stored = []; }

    function persist() {
        try { localStorage.setItem(STORE_KEY, JSON.stringify(stored.slice(0, MAX_ITEMS))); } catch (e) {}
    }
    function normalize(item) {
        if (typeof item === 'string') return { key: 'notice:' + item.trim(), text: item, type: 'notice', created_at: null };
        item = item || {};
        const text = String(item.text || '');
        return {
            key: String(item.key || ('notice:' + text.trim())),
            text: text,
            type: String(item.type || 'notice'),
            created_at: item.created_at || null,
            url: safeUrl(item.url)
        };
    }
    // Only same-site portal pages are clickable (e.g. dean_reports.php?...).
    function safeUrl(u) {
        u = typeof u === 'string' ? u.trim() : '';
        return /^[A-Za-z0-9_.\-]+\.php(\?[^\s]*)?$/.test(u) ? u : null;
    }
    function merge(incoming) {
        const map = new Map();
        stored.forEach(function (item) {
            if (item && item.key && item.text) map.set(item.key, item);
        });
        (Array.isArray(incoming) ? incoming : []).map(normalize).forEach(function (item) {
            if (!item.text || item.text === 'No urgent items right now.') return;
            const old = map.get(item.key);
            if (old) {
                map.set(item.key, Object.assign({}, old, item, { read: !!old.read }));
            } else {
                item.read = false;
                if (!item.created_at) item.created_at = new Date().toISOString();
                map.set(item.key, item);
            }
        });
        stored = Array.from(map.values()).sort(function (a, b) {
            const da = a.created_at ? new Date(a.created_at).getTime() : 0;
            const db = b.created_at ? new Date(b.created_at).getTime() : 0;
            return db - da;
        }).slice(0, MAX_ITEMS);
        persist();
    }
    function render() {
        list.innerHTML = '';
        const visible = stored;   // show every notification; the list scrolls instead of truncating
        if (!visible.length) {
            const empty = document.createElement('li');
            empty.className = 'dean-header-notif-empty';
            empty.textContent = 'No recent notifications.';
            list.appendChild(empty);
        } else {
            visible.forEach(function (item) {
                const li = document.createElement('li');
                if (item.type === 'evaluation') li.classList.add('notif-evaluation');
                const icon = document.createElement('i');
                icon.className = item.type === 'evaluation'
                    ? 'fa-solid fa-clipboard-check'
                    : (item.type === 'ea_period' ? 'fa-solid fa-calendar-days' : 'fa-solid fa-circle-exclamation');
                icon.setAttribute('aria-hidden', 'true');
                li.appendChild(icon);
                li.appendChild(document.createTextNode(item.text));
                const target = safeUrl(item.url);
                if (target) {
                    li.classList.add('notif-link');
                    li.setAttribute('role', 'link');
                    li.tabIndex = 0;
                    li.title = 'Open';
                    li.addEventListener('click', function () { window.location.href = target; });
                    li.addEventListener('keydown', function (e) {
                        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); window.location.href = target; }
                    });
                }
                list.appendChild(li);
            });
        }
        const unread = stored.filter(function (item) { return item && !item.read; }).length;
        if (unread > 0) {
            badge.textContent = unread > 9 ? '9+' : String(unread);
            badge.style.display = 'flex';
        } else {
            badge.style.display = 'none';
            badge.textContent = '';
        }
    }
    function closeDropdown() {
        dropdown.classList.remove('open');
        bell.setAttribute('aria-expanded', 'false');
        dropdown.setAttribute('aria-hidden', 'true');
    }
    bell.addEventListener('click', function (event) {
        event.stopPropagation();
        const isOpen = dropdown.classList.toggle('open');
        bell.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        dropdown.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
        if (isOpen) {
            stored = stored.map(function (item) { return Object.assign({}, item, { read: true }); });
            persist();
            render();
        }
    });
    document.addEventListener('click', function (event) {
        if (!dropdown.contains(event.target) && event.target !== bell && !bell.contains(event.target)) closeDropdown();
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeDropdown();
    });
    function poll() {
        fetch('dean_notifications_api.php', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (response) { if (!response.ok) throw new Error('Notification request failed'); return response.json(); })
            .then(function (data) {
                merge(data.notifications);
                render();
                if (liveDot) liveDot.classList.remove('stale');
            })
            .catch(function () { if (liveDot) liveDot.classList.add('stale'); });
    }
    render();
    poll();
    window.setInterval(poll, 15000);
})();
</script>