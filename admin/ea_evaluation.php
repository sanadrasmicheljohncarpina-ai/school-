<?php
// admin/ea_evaluation.php
// Executive Assistant "My Evaluations" landing page.
// The page follows the same group-selection structure used by the faculty
// evaluation view: choose an evaluation group first, then select a person.
// Eligibility and completion logic remain server-side and unchanged.
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/', 'domain' => '',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'), 'httponly' => true, 'samesite' => 'Lax',
]);
session_start();
require_once 'db.php';
require_once dirname(__DIR__) . '/shared/system_settings_service.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!isset($_SESSION['user_id']) ||
    !in_array($_SESSION['role'] ?? '', ['admin', 'superadmin', 'executive_assistant'], true)) {
    header('Location: admin_login.php');
    exit;
}

ss_sync_from_database($mysqli);
$ea_id = (int) $_SESSION['user_id'];

// Schema upgrades for older installations. Each change runs only when it is
// actually missing, instead of issuing ALTER TABLE on every page load.
// (Ideally move these into a one-time migration script.)
try {
    $col = $mysqli->query("SHOW COLUMNS FROM evaluation_tracker LIKE 'eval_type'")->fetch_assoc();
    if ($col && stripos((string) $col['Type'], 'varchar') === false) {
        $mysqli->query("ALTER TABLE evaluation_tracker MODIFY eval_type VARCHAR(30) NOT NULL DEFAULT 'student'");
    }
    foreach ([
        'evaluator_id'   => "ADD COLUMN evaluator_id INT UNSIGNED NULL",
        'target_user_id' => "ADD COLUMN target_user_id INT UNSIGNED NULL",
        'period_id'      => "ADD COLUMN period_id INT UNSIGNED NULL",
        'status'         => "ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'submitted'",
    ] as $name => $clause) {
        if (!$mysqli->query("SHOW COLUMNS FROM evaluation_tracker LIKE '$name'")->num_rows) {
            $mysqli->query("ALTER TABLE evaluation_tracker $clause");
        }
    }
} catch (Throwable $e) {
    error_log('ea_evaluation schema check failed: ' . $e->getMessage());
}

$period = $mysqli->query("
    SELECT id, period_label, is_active
    FROM evaluation_periods
    WHERE is_active=1
    ORDER BY id DESC
    LIMIT 1
")->fetch_assoc();
$period_id = (int) ($period['id'] ?? 0);
$is_open = $period_id > 0;
$periodName = trim((string) ($period['period_label'] ?? ''));

// ── ELIGIBLE TARGETS ──────────────────────────────────────────────────
// EA may evaluate the active Principal, active Dean, and Staff members
// without teaching/year-level assignments.
$heads = ['Principal' => [], 'Dean' => []];
$headStmt = $mysqli->prepare("
    SELECT id, full_name, designation, photo, role
    FROM users
    WHERE role IN ('principal', 'dean')
      AND is_active=1
      AND account_status='approved'
    ORDER BY full_name
");
$headStmt->execute();
foreach ($headStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $user) {
    $bucket = $user['role'] === 'principal' ? 'Principal' : 'Dean';
    $heads[$bucket][] = $user;
}
$headStmt->close();

$staffStmt = $mysqli->prepare("
    SELECT u.id, u.full_name, u.designation, u.photo, u.role
    FROM users u
    WHERE u.role='staff'
      AND u.is_active=1
      AND (u.account_status='approved' OR u.source='admin_nologin')
      AND NOT EXISTS (SELECT 1 FROM teaching_assignments ta WHERE ta.user_id=u.id)
      AND NOT EXISTS (SELECT 1 FROM user_year_levels yl WHERE yl.user_id=u.id)
    ORDER BY u.full_name
");
$staffStmt->execute();
$staff = $staffStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$staffStmt->close();

$categories = [
    'Principal' => $heads['Principal'],
    'Dean'      => $heads['Dean'],
    'Staff'     => $staff,
];

$groupMeta = [
    'Principal' => [
        'icon' => 'fa-user-tie',
        'class' => 'group-principal',
    ],
    'Dean' => [
        'icon' => 'fa-graduation-cap',
        'class' => 'group-dean',
    ],
    'Staff' => [
        'icon' => 'fa-briefcase',
        'class' => 'group-staff',
    ],
];

$selectedType = $_GET['type'] ?? '';
if (!array_key_exists($selectedType, $categories)) {
    $selectedType = '';
}

// Completion state is scoped to EA evaluations for the active period.
$done = [];
{
    $doneStmt = $mysqli->prepare("
        SELECT target_user_id
        FROM evaluation_tracker
        WHERE evaluator_id=?
          AND period_id=?
          AND eval_type='ea'
          AND status='submitted'
    ");
    $doneStmt->bind_param('ii', $ea_id, $period_id);
    $doneStmt->execute();
    $rows = $doneStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $done = array_flip(array_map('intval', array_column($rows, 'target_user_id')));
    $doneStmt->close();
}

$justSubmitted = isset($_GET['submitted']);
$mysqli->close();

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function personPhoto(?string $photo): string {
    return !empty($photo) ? '../image/' . e($photo) : '';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Evaluate Others — PBI</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="admin_appearance.css">
<script src="admin_appearance.js"></script>
<style>
:root{
  /* Light mode defaults. These tokens intentionally follow the shared
     PBI Admin appearance engine instead of hard-locking this page to dark. */
  --ea-page-bg:var(--bg,#f4f8ff);
  --ea-panel:var(--panel-bg,#ffffff);
  --ea-panel-soft:#f8fbff;
  --ea-panel-deep:#eef4fb;
  --ea-line:var(--panel-border,#dce8f5);
  --ea-text:var(--text,#10243f);
  --ea-muted:var(--muted,#587089);
  --ea-muted-2:#7e93ab;
  --ea-card:#ffffff;
  --ea-card-border:#d7e3ef;
  --ea-card-hover:#f4f8ff;
  --ea-card-selected:#edf5ff;
  --ea-icon-neutral:#edf4fd;
  --ea-photo-bg:#f2f6fb;
  --ea-photo-border:#c9d9ea;
  --ea-shadow:0 8px 24px rgba(30,82,144,.08);
  --ea-shadow-hover:0 10px 24px rgba(30,82,144,.12);
  --ea-info:#58718c;
  --blue:#2F6EE2;
  --blue-soft:rgba(59,130,246,.10);
  --blue-border:rgba(59,130,246,.35);
  --green:#22C55E;
  --green-soft:rgba(34,197,94,.10);
  --green-border:rgba(34,197,94,.26);
  --gold:#F59E0B;
  --gold-soft:rgba(245,158,11,.10);
  --gold-border:rgba(245,158,11,.28);
}

/* Dark mode — preserve the established dark visual palette. */
html[data-theme="dark"]{
  --ea-page-bg:#07192D;
  --ea-panel:#132844;
  --ea-panel-soft:#142A47;
  --ea-panel-deep:#10233D;
  --ea-line:#213A5C;
  --ea-text:#F5F7FB;
  --ea-muted:#9CB0CA;
  --ea-muted-2:#8298B4;
  --ea-card:#10233E;
  --ea-card-border:#223A5B;
  --ea-card-hover:#142C4B;
  --ea-card-selected:#142C4B;
  --ea-icon-neutral:#18304E;
  --ea-photo-bg:#18304E;
  --ea-photo-border:#2B476A;
  --ea-shadow:0 12px 32px rgba(0,0,0,.15);
  --ea-shadow-hover:0 10px 24px rgba(0,0,0,.18);
  --ea-info:#8FA7C6;
}

*{box-sizing:border-box}
html,body{
  margin:0;
  min-height:100%;
  background:var(--ea-page-bg);
  color:var(--ea-text);
  font-family:'Inter',Segoe UI,Arial,sans-serif;
}
body{overflow-x:hidden}
a{color:inherit}
.wrap{max-width:1390px;margin:0 auto;padding:28px 30px 34px}

/* Main evaluation panel — intentionally mirrors the faculty evaluation card. */
.evaluation-panel{
  background:var(--ea-panel);
  border:1px solid var(--ea-line);
  border-radius:14px;
  padding:24px 28px 22px;
  box-shadow:var(--ea-shadow);
}
.panel-title{display:flex;align-items:center;gap:11px;margin:0 0 14px;font-family:'Rajdhani',sans-serif;font-size:26px;font-weight:700;letter-spacing:.1px;color:var(--ea-text)}
.panel-title i{color:var(--ux-header-icon,#D97706)!important;font-size:22px}  /* matches the dashboard header icon */

.group-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}
.group-card{
  position:relative;
  display:flex;
  flex-direction:column;
  align-items:center;
  justify-content:center;
  min-height:188px;
  padding:22px 18px;
  text-decoration:none;
  background:var(--ea-card);
  border:1px solid var(--ea-card-border);
  border-radius:14px;
  transition:transform .16s ease,border-color .16s ease,background .16s ease,box-shadow .16s ease;
  overflow:hidden;
}
.group-card::after{content:'';position:absolute;inset:auto 0 0;height:3px;background:transparent;opacity:0;transition:opacity .16s ease}
.group-card:hover::after,.group-card.selected::after{opacity:1}
.group-card:focus-visible,.btn-action:focus-visible{outline:2px solid #2F6EE2;outline-offset:2px}
.group-card:hover{transform:translateY(-2px);background:var(--ea-card-hover);border-color:#8fb5e2;box-shadow:var(--ea-shadow-hover)}
.group-card.selected{background:var(--ea-card-selected);border-color:#9fc5f5;box-shadow:0 8px 20px rgba(30,82,144,.10)}
.group-icon{width:64px;height:64px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:25px;margin-bottom:15px}
.group-card h3{margin:0;color:var(--ea-text);font-family:'Rajdhani',sans-serif;font-size:20px;font-weight:700;letter-spacing:.1px}
.group-count{margin-top:3px;color:var(--ea-muted);font-size:13.5px}
.group-principal .group-icon{background:var(--blue-soft);color:#3D8BFF}
.group-principal::after{background:#3B82F6}
.group-dean .group-icon{background:var(--gold-soft);color:#F59E0B}
.group-dean::after{background:#F59E0B}
.group-staff .group-icon{background:var(--green-soft);color:#22C55E}
.group-staff::after{background:#22C55E}
.group-card.selected.group-principal{border-color:var(--blue-border)}
.group-card.selected.group-dean{border-color:var(--gold-border)}
.group-card.selected.group-staff{border-color:var(--green-border)}


.alert{display:flex;align-items:center;gap:9px;margin:14px 0 0;padding:11px 13px;border-radius:9px;font-size:12.5px}
.alert-success{color:#087f5b;background:rgba(16,185,129,.10);border:1px solid rgba(52,211,153,.24)}
html[data-theme="dark"] .alert-success{color:#A7F3D0;background:rgba(16,185,129,.11);border-color:rgba(52,211,153,.22)}

/* Step 2 appears only after a group is selected. */
.roster-panel{
  margin-top:20px;
  background:var(--ea-panel);
  border:1px solid var(--ea-line);
  border-radius:14px;
  overflow:hidden;
  box-shadow:var(--ea-shadow);
}
.roster-head{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px 16px;padding:18px 22px;border-bottom:1px solid var(--ea-line);background:var(--ea-panel-soft)}
.roster-heading{display:flex;align-items:center;gap:11px}
.roster-heading .roster-icon{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:var(--ea-icon-neutral);color:#2F6EE2;font-size:15px}
.roster-heading h2{margin:0;font-family:'Rajdhani',sans-serif;font-size:20px;font-weight:700;color:var(--ea-text)}
.roster-heading p{margin:2px 0 0;color:var(--ea-muted-2);font-size:11.5px}
.person-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;padding:18px;background:var(--ea-panel)}
.person-card{
  display:flex;align-items:center;gap:13px;
  background:var(--ea-card);
  border:1px solid var(--ea-card-border);
  border-radius:11px;
  padding:13px 14px;
}
.person-photo{width:44px;height:44px;border-radius:50%;object-fit:cover;background:var(--ea-photo-bg);border:1px solid var(--ea-photo-border);display:flex;align-items:center;justify-content:center;color:var(--ea-muted);flex-shrink:0;overflow:hidden}
.person-photo img{width:100%;height:100%;object-fit:cover;display:block}
.person-info{min-width:0;flex:1}
.person-name{font-size:14.5px;font-weight:800;color:var(--ea-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.person-meta{margin-top:3px;color:var(--ea-muted);font-size:12.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.status-pill{display:inline-flex;align-items:center;gap:5px;margin-top:7px;padding:4px 10px;border-radius:999px;font-size:11.5px;font-weight:800}
.status-pill.done{background:rgba(52,211,153,.12);color:#11805f;border:1px solid rgba(52,211,153,.18)}
.status-pill.pending{background:rgba(245,158,11,.12);color:#a66000;border:1px solid rgba(245,158,11,.18)}
html[data-theme="dark"] .status-pill.done{color:#6EE7B7}
html[data-theme="dark"] .status-pill.pending{color:#F8C465}
.person-action{flex-shrink:0}
.btn-action{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:10px 14px;border-radius:8px;text-decoration:none;font-size:12.5px;font-weight:800;border:1px solid transparent}
.btn-action.evaluate{background:#2563EB;color:#fff;border-color:#3B82F6}
.btn-action.evaluate:hover{background:#1D4ED8}
.btn-action.view{background:transparent;color:var(--ea-muted);border-color:#9fb4ca}
.btn-action.view:hover{background:var(--ea-card-hover);color:var(--ea-text)}
html[data-theme="dark"] .btn-action.view{color:#B1C1D4;border-color:#34506F}
html[data-theme="dark"] .btn-action.view:hover{background:#172E4C;color:#fff}
.roster-panel{scroll-margin-top:12px}
.roster-stats{display:flex;align-items:center;flex-wrap:wrap;gap:8px 16px;color:var(--ea-muted);font-size:12.5px}
.roster-stats strong{color:var(--ea-text)}
.roster-stats .period{display:inline-flex;align-items:center;gap:6px}
.alert-warn{color:#8a5300;background:rgba(245,158,11,.10);border:1px solid rgba(245,158,11,.30)}
html[data-theme="dark"] .alert-warn{color:#F8C465;background:rgba(245,158,11,.10);border-color:rgba(245,158,11,.28)}
.roster-empty{text-align:center;padding:44px 20px;color:var(--ea-muted-2);font-size:12.5px}
.roster-empty i{display:block;margin-bottom:10px;font-size:26px;opacity:.45}


/* Keep the light palette readable even though the shared dark-surface observer
   adds classes only while dark mode is active. */
html[data-theme="dark"] .group-card:hover,
html[data-theme="dark"] .group-card.selected{border-color:#31557D}
html[data-theme="dark"] .roster-heading .roster-icon{background:#1D3A60;color:#78AFFF}
html[data-theme="dark"] .person-photo{background:#18304E;border-color:#2B476A;color:#91A7C3}
html[data-theme="dark"] .btn-action.view{background:transparent}

@media(max-width:900px){
  .wrap{padding:18px}
  .evaluation-panel{padding:20px}
  .group-grid{grid-template-columns:1fr}
  .group-card{min-height:160px}
  .person-grid{grid-template-columns:1fr}
}
@media(max-width:560px){
  .wrap{padding:12px}
  .evaluation-panel{padding:16px}
  .panel-title{font-size:23px}
  .group-card{min-height:150px}
  .roster-head{align-items:flex-start;flex-direction:column}
  .person-card{align-items:flex-start;flex-wrap:wrap}
  .person-action{width:100%}
  .person-action .btn-action{width:100%}
}

/* Evaluate / View popup (the form itself is ea_evaluate.php?embed=1) */
.eval-overlay{position:fixed;inset:0;z-index:200;display:none;align-items:center;justify-content:center;padding:10px 20px;background:rgba(9,17,30,.64);backdrop-filter:blur(4px)}
.eval-overlay.open{display:flex}
.eval-frame-wrap{width:100%;max-width:780px;height:96vh;max-height:calc(100vh - 20px)}
.eval-frame-wrap iframe{width:100%;height:100%;border:0;background:transparent;border-radius:18px;color-scheme:normal}
@media(max-width:700px){.eval-overlay{padding:10px}}
</style>
<link rel="stylesheet" href="admin_unified_ui.css?v=<?= (int)@filemtime(__DIR__ . '/admin_unified_ui.css') ?>">
<script>
/* Match the title icon to the dashboard header icon colour (falls back to #D97706). */
(function(){try{var i=parent.document.getElementById('pbiAdminTopbarIcon');if(i){document.documentElement.style.setProperty('--ux-header-icon',parent.getComputedStyle(i).color);}}catch(e){}})();
</script>
<style id="ea-polish">
/* ------------------------------------------------------------------
   Evaluate Others polish: clearer hierarchy, per-group progress,
   stronger contrast. Colours are light-theme only; dark mode keeps
   the palette from the page's own dark tokens. Loaded last on purpose.
   ------------------------------------------------------------------ */
html:not([data-theme="dark"]) body:not(#eap){--ea-muted:#475569;--ea-muted-2:#556579;--ea-line:#d3dce8;--ea-card-border:#cdd8e6;--ea-text:#0b1f3a;}

/* ── Header ── */
html body:not(#eap) .panel-head{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px 16px;margin-bottom:6px;}
html body:not(#eap) .panel-head .panel-title{margin:0!important;}
html body:not(#eap) .period-chip{display:inline-flex;align-items:center;gap:8px;padding:7px 14px;border-radius:999px;font-size:12.5px;font-weight:700;border:1px solid transparent;}
html body:not(#eap) .panel-lead{margin:0 0 22px;font-size:14.5px;line-height:1.5;color:var(--ea-muted);}
html:not([data-theme="dark"]) body:not(#eap) .period-chip{background:#EEF2FF;color:#3730A3;border-color:#C7D2FE;}
html[data-theme="dark"] body:not(#eap) .period-chip{background:rgba(129,140,248,.14);color:#C7D2FE;border-color:rgba(129,140,248,.35);}

/* ── Group cards ── */
html body:not(#eap) .evaluation-panel{padding:28px 32px 30px!important;}
html body:not(#eap) .group-grid{gap:22px!important;}
html body:not(#eap) .group-card{--g:#2563EB;--g-ink:#1d4ed8;--g-bg:#e1ebff;
    justify-content:flex-start!important;gap:0;min-height:320px!important;padding:36px 22px 24px!important;border-radius:18px!important;
    border:1px solid var(--ea-card-border)!important;}
html body:not(#eap) .group-card.group-dean{--g:#D97706;--g-ink:#b45309;--g-bg:#ffefcf;}
html body:not(#eap) .group-card.group-staff{--g:#16A34A;--g-ink:#15803d;--g-bg:#ddf5e6;}
html body:not(#eap) .group-card::after{display:none!important;}
html body:not(#eap) .group-card::before{content:"";position:absolute;left:0;right:0;top:0;height:5px;background:var(--g);}
html body:not(#eap) .group-icon{width:88px!important;height:88px!important;font-size:34px!important;margin-bottom:16px!important;}
html body:not(#eap) .group-card h3{font-size:24px!important;margin-bottom:2px;}
html body:not(#eap) .group-count{display:inline-flex;align-items:baseline;gap:6px;margin-top:10px!important;padding:6px 16px;border-radius:999px;font-size:13.5px!important;font-weight:700;}
html body:not(#eap) .group-count strong{font-size:19px;font-weight:800;line-height:1;}
html:not([data-theme="dark"]) body:not(#eap) .group-count{background:var(--g-bg);color:var(--g-ink);}
html[data-theme="dark"] body:not(#eap) .group-count{background:rgba(255,255,255,.08);color:var(--ea-text);}
html body:not(#eap) .group-progress{display:flex;flex-direction:column;align-items:center;gap:7px;width:100%;max-width:230px;margin-top:20px;}
html body:not(#eap) .group-progress-bar{display:block;width:100%;height:8px;border-radius:999px;overflow:hidden;background:rgba(100,116,139,.2);}
html body:not(#eap) .group-progress-bar i{display:block;height:100%;border-radius:999px;background:var(--g);transition:width .3s ease;}
html body:not(#eap) .group-progress.complete .group-progress-bar i{background:#16A34A;}
html body:not(#eap) .group-progress-text{font-size:13px;font-weight:500;color:var(--ea-muted);}
html body:not(#eap) .group-progress-text strong{color:var(--ea-text);font-weight:800;}
html body:not(#eap) .group-progress-text.empty{margin-top:20px;}
html body:not(#eap) .group-cta{display:inline-flex;align-items:center;gap:8px;margin-top:auto;padding-top:20px;font-size:13px;font-weight:800;color:var(--g-ink);}
html[data-theme="dark"] body:not(#eap) .group-cta{color:var(--g);}
html body:not(#eap) .group-cta i{font-size:12px;transition:transform .15s ease;}
html body:not(#eap) .group-card:hover .group-cta i{transform:translateX(4px);}
html body:not(#eap) .group-card.selected .group-cta i{transform:none;}
html body:not(#eap) .group-card:focus-visible{outline:3px solid var(--g);outline-offset:3px;}
html:not([data-theme="dark"]) body:not(#eap) .group-card{background:#fff!important;box-shadow:0 1px 2px rgba(11,31,58,.06),0 8px 22px rgba(11,31,58,.06)!important;}
html:not([data-theme="dark"]) body:not(#eap) .group-card:hover{background:#fbfdff!important;border-color:var(--g)!important;box-shadow:0 14px 32px rgba(11,31,58,.13)!important;transform:translateY(-3px)!important;}
html:not([data-theme="dark"]) body:not(#eap) .group-card.selected{background:#fff!important;border-color:var(--g)!important;box-shadow:0 0 0 3px color-mix(in srgb,var(--g) 22%,transparent),0 12px 28px rgba(11,31,58,.12)!important;}
html[data-theme="dark"] body:not(#eap) .group-card.selected{border-color:var(--g)!important;box-shadow:0 0 0 3px rgba(255,255,255,.07)!important;}

/* ── Roster (after choosing a group) ── */
html body:not(#eap) .roster-panel{margin-top:24px!important;border-radius:18px!important;}
html body:not(#eap) .roster-head{padding:20px 26px!important;}
html body:not(#eap) .roster-heading .roster-icon{width:42px!important;height:42px!important;font-size:17px!important;}
html body:not(#eap) .roster-heading h2{font-size:24px!important;}
html body:not(#eap) .roster-stats{gap:8px 10px!important;}
html body:not(#eap) .roster-stats span{padding:6px 14px;border-radius:999px;font-size:13px;font-weight:600;border:1px solid transparent;}
html body:not(#eap) .roster-stats strong{font-size:15px;font-weight:800;}
html:not([data-theme="dark"]) body:not(#eap) .roster-stats span{background:#EEF2F7;color:#334155;border-color:#D3DCE8;}
html:not([data-theme="dark"]) body:not(#eap) .roster-stats span:nth-child(2){background:#DDF4E6;color:#0A6A3B;border-color:#9ED8B7;}
html:not([data-theme="dark"]) body:not(#eap) .roster-stats span:nth-child(3){background:#FFF1D4;color:#8A5300;border-color:#EBCB88;}
html:not([data-theme="dark"]) body:not(#eap) .roster-stats span.period{background:#EEF2FF;color:#3730A3;border-color:#C7D2FE;}
html:not([data-theme="dark"]) body:not(#eap) .roster-stats strong{color:inherit;}
html body:not(#eap) .person-grid{gap:14px!important;padding:22px!important;}
html body:not(#eap) .person-card{gap:16px!important;padding:16px 18px!important;border-radius:14px!important;border-left-width:4px!important;transition:box-shadow .15s ease,border-color .15s ease;}
html body:not(#eap) .person-card.is-done{border-left-color:#16A34A!important;}
html body:not(#eap) .person-card.is-pending{border-left-color:#F59E0B!important;}
html body:not(#eap) .person-card:hover{box-shadow:0 6px 18px rgba(11,31,58,.10);}
html body:not(#eap) .person-photo{width:56px!important;height:56px!important;}
html body:not(#eap) .person-name{font-size:15.5px!important;}
html body:not(#eap) .person-meta{font-size:13px!important;margin-top:2px!important;}
html body:not(#eap) .status-pill{font-size:12px!important;padding:4px 11px!important;margin-top:8px!important;}
html:not([data-theme="dark"]) body:not(#eap) .status-pill.done{background:#D6F2E4!important;color:#0A6A3B!important;border-color:#8ED0AD!important;}
html:not([data-theme="dark"]) body:not(#eap) .status-pill.pending{background:#FFF1D4!important;color:#8A5300!important;border-color:#EBCB88!important;}
html body:not(#eap) .btn-action{padding:11px 18px!important;border-radius:10px!important;font-size:13px!important;min-width:112px;}
html:not([data-theme="dark"]) body:not(#eap) .btn-action.view{background:#fff!important;color:#1e293b!important;border-color:#b6c3d6!important;}
html:not([data-theme="dark"]) body:not(#eap) .btn-action.view:hover{background:#F1F5FB!important;}
html body:not(#eap) .roster-empty{padding:56px 20px!important;font-size:14px!important;}

@media(max-width:900px){html body:not(#eap) .group-card{min-height:260px!important;}html body:not(#eap) .evaluation-panel{padding:20px!important;}}
@media(max-width:560px){html body:not(#eap) .group-card{min-height:0!important;}html body:not(#eap) .person-action .btn-action{width:100%;}}
</style>
</head>
<body class="feature-compact">
<main class="wrap">
  <section class="evaluation-panel">
    <div class="panel-head">
      <h1 class="panel-title"><i class="fa-solid fa-clipboard-check"></i>Your Evaluation</h1>
      <?php if ($periodName !== ''): ?><span class="period-chip"><i class="fa-regular fa-calendar"></i> <?= e($periodName) ?></span><?php endif; ?>
    </div>
    <p class="panel-lead">Choose a group, then evaluate each member. Your progress for the active period is shown on every card.</p>

    <div class="group-grid">
      <?php foreach ($categories as $type => $people):
          $meta = $groupMeta[$type];
          $isSelected = $selectedType === $type;
          $count = count($people);
          $groupDone = 0;
          foreach ($people as $gp) { if (isset($done[(int) $gp['id']])) $groupDone++; }
          $groupPct = $count > 0 ? (int) round($groupDone / $count * 100) : 0;
      ?>
        <a class="group-card <?= e($meta['class']) ?> <?= $isSelected ? 'selected' : '' ?>" href="<?= $isSelected ? '?' : '?type=' . urlencode($type) . '#roster' ?>" aria-label="<?= $isSelected ? 'Deselect' : 'Select' ?> <?= e($type) ?>"<?= $isSelected ? ' aria-current="true"' : '' ?>>
          <div class="group-icon"><i class="fa-solid <?= e($meta['icon']) ?>"></i></div>
          <h3><?= e($type) ?></h3>
          <div class="group-count"><strong><?= $count ?></strong> <?= $count === 1 ? 'member' : 'members' ?></div>
          <?php if ($count > 0): ?>
            <div class="group-progress<?= $groupPct === 100 ? ' complete' : '' ?>" role="img" aria-label="<?= $groupDone ?> of <?= $count ?> evaluated">
              <span class="group-progress-bar"><i style="width:<?= $groupPct ?>%"></i></span>
              <span class="group-progress-text"><strong><?= $groupDone ?></strong> of <?= $count ?> evaluated</span>
            </div>
          <?php else: ?>
            <div class="group-progress-text empty">No eligible members</div>
          <?php endif; ?>
          <span class="group-cta"><?= $isSelected ? 'Selected' : 'Select group' ?> <i class="fa-solid <?= $isSelected ? 'fa-check' : 'fa-arrow-right' ?>"></i></span>
        </a>
      <?php endforeach; ?>
    </div>


    <?php if (!$is_open): ?>
      <div class="alert alert-warn" role="status"><i class="fa-solid fa-circle-info"></i> No evaluation period is active. EA evaluations stay available, but anything submitted now is not tied to a period.</div>
    <?php endif; ?>

    <?php if ($justSubmitted): ?>
      <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> Evaluation submitted successfully.</div>
    <?php endif; ?>
  </section>

  <?php if ($selectedType !== ''):
      $people = $categories[$selectedType];
      $selectedMeta = $groupMeta[$selectedType];
      $groupCompleted = 0;
      foreach ($people as $person) {
          if (isset($done[(int) $person['id']])) $groupCompleted++;
      }
  ?>
    <section class="roster-panel" id="roster">
      <div class="roster-head">
        <div class="roster-heading">
          <div class="roster-icon"><i class="fa-solid <?= e($selectedMeta['icon']) ?>"></i></div>
          <h2><?= e($selectedType) ?></h2>
        </div>
        <div class="roster-stats">
          <span><strong><?= count($people) ?></strong> <?= count($people) === 1 ? 'member' : 'members' ?></span>
          <span><strong><?= $groupCompleted ?></strong> completed</span>
          <span><strong><?= max(0, count($people) - $groupCompleted) ?></strong> pending</span>
          <?php if ($periodName !== ''): ?><span class="period"><i class="fa-regular fa-calendar"></i> <?= e($periodName) ?></span><?php endif; ?>
        </div>
      </div>

      <?php if (!$people): ?>
        <div class="roster-empty">
          <i class="fa-solid fa-user-slash"></i>
          No eligible <?= e($selectedType) ?> personnel are currently available.
        </div>
      <?php else: ?>
        <div class="person-grid">
          <?php foreach ($people as $person):
              $pid = (int) $person['id'];
              $isDone = isset($done[$pid]);
          ?>
            <article class="person-card <?= $isDone ? 'is-done' : 'is-pending' ?>">
              <?php if (!empty($person['photo'])): ?>
                <div class="person-photo"><img src="<?= personPhoto($person['photo']) ?>" alt="" loading="lazy" onerror="this.parentNode.innerHTML='<i class=&quot;fa-solid fa-user&quot;></i>'"></div>
              <?php else: ?>
                <div class="person-photo"><i class="fa-solid fa-user"></i></div>
              <?php endif; ?>

              <div class="person-info">
                <div class="person-name" title="<?= e($person['full_name']) ?>"><?= e($person['full_name']) ?></div>
                <?php $designation = $person['designation'] ?: $selectedType; ?>
                <div class="person-meta" title="<?= e($designation) ?>"><?= e($designation) ?></div>
                <span class="status-pill <?= $isDone ? 'done' : 'pending' ?>">
                  <i class="fa-solid <?= $isDone ? 'fa-check' : 'fa-hourglass-half' ?>"></i>
                  <?= $isDone ? 'Completed' : 'Pending' ?>
                </span>
              </div>

              <div class="person-action">
                <a class="btn-action <?= $isDone ? 'view' : 'evaluate' ?>" href="ea_evaluate.php?type=<?= urlencode($selectedType) ?>&user_id=<?= $pid ?>" data-eval-url="ea_evaluate.php?type=<?= urlencode($selectedType) ?>&user_id=<?= $pid ?>&embed=1">
                  <i class="fa-solid <?= $isDone ? 'fa-eye' : 'fa-pen' ?>"></i>
                  <?= $isDone ? 'View' : 'Evaluate' ?>
                </a>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</main>

<div class="eval-overlay" id="evalOverlay" aria-hidden="true">
  <div class="eval-frame-wrap"><iframe id="evalFrame" title="Evaluation form" allowtransparency="true"></iframe></div>
</div>
<script>
(function(){
  var overlay = document.getElementById('evalOverlay'), frame = document.getElementById('evalFrame');
  function open(url){
    frame.src = url;
    overlay.classList.add('open'); overlay.setAttribute('aria-hidden','false');
    document.body.style.overflow = 'hidden';
  }
  window.closeEvalModal = function(){
    overlay.classList.remove('open'); overlay.setAttribute('aria-hidden','true');
    document.body.style.overflow = '';
    frame.src = 'about:blank';
  };
  document.addEventListener('click', function(ev){
    var a = ev.target.closest('a[data-eval-url]');
    if (!a) return;
    ev.preventDefault();
    var url = a.getAttribute('data-eval-url');
    try {
      if (window.parent && window.parent !== window && typeof window.parent.openEvalModal === 'function') {
        window.parent.openEvalModal(url);   // full-screen popup hosted by the dashboard
        return;
      }
    } catch (err) {}
    open(url);
  });
  /* Ask before discarding a half-filled evaluation (form exposes window.isEvalDirty) */
  function evalIsDirty(){
    try { return !!(frame.contentWindow && frame.contentWindow.isEvalDirty && frame.contentWindow.isEvalDirty()); }
    catch (err) { return false; }
  }
  window.requestCloseEvalModal = function(){
    // use the form's own styled dialog (askEvalLeave resolves true when it's safe to leave)
    var w = frame.contentWindow, ask = null;
    try { ask = (w && typeof w.askEvalLeave === 'function') ? w.askEvalLeave() : null; } catch (err) {}
    if (!ask) { window.closeEvalModal(); return; }
    ask.then(function(ok){ if (ok) window.closeEvalModal(); });
  };
  overlay.addEventListener('click', function(ev){ if (ev.target === overlay) window.requestCloseEvalModal(); });
  document.addEventListener('keydown', function(ev){ if (ev.key === 'Escape' && overlay.classList.contains('open')) window.requestCloseEvalModal(); });
})();
</script>
</body>
</html>