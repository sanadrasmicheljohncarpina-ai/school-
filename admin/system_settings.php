<?php
session_start();
require_once 'db.php';
require_once dirname(__DIR__) . '/shared/system_settings_service.php';

if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin','superadmin'], true)) {
    http_response_code(403);
    exit('Unauthorized.');
}

// ── Notify Teacher/Staff accounts about period or schedule changes made by the EA ──
function ss_notify_faculty_staff(mysqli $mysqli, string $type, string $message): void {
    $hasStatus = $mysqli->query("SHOW COLUMNS FROM users LIKE 'account_status'");
    $statusSql = ($hasStatus && $hasStatus->num_rows > 0) ? " AND (account_status = 'approved' OR account_status IS NULL)" : '';
    $stmt = $mysqli->prepare("INSERT INTO notifications (type, user_id, message) SELECT ?, id, ? FROM users WHERE role IN ('teacher','staff')" . $statusSql);
    if (!$stmt) return; // never block saving settings because of notifications
    $stmt->bind_param('ss', $type, $message);
    $stmt->execute();
    $stmt->close();
}
function ss_fmt_manila(string $v): string {
    if ($v === '') return '';
    try {
        $d = new DateTime($v, new DateTimeZone('Asia/Manila'));
        return $d->format('M j, Y g:i A');
    } catch (Exception $e) { return $v; }
}

// Keep the database-backed evaluation period in sync with the configured schedule.
ss_sync_from_database($mysqli);

$sys = ss_raw($mysqli);
// ── New evaluation schedule: the current evaluation must be archived first ──
function ss_json(array $data, int $code = 200): void {
    while (ob_get_level() > 0) { ob_end_clean(); }   // drop any notices/warnings printed earlier
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}
function ss_table_exists(mysqli $mysqli, string $table): bool {
    $ok = false;
    if ($r = $mysqli->query("SHOW TABLES LIKE '" . $mysqli->real_escape_string($table) . "'")) { $ok = $r->num_rows > 0; $r->free(); }
    return $ok;
}
// Returns the current evaluation period(s) that still hold live, un-archived evaluations.
// "Current" = the active period, or the period matching the academic year/term in settings.
function ss_current_evaluation_gate(mysqli $mysqli, array $sys): array {
    $year = (string)($sys['acad_year'] ?? '');
    $term = (string)($sys['acad_term'] ?? '');
    $hasArchive = ss_table_exists($mysqli, 'system_archives');
    $join  = $hasArchive ? "LEFT JOIN system_archives sa ON sa.period_id = ep.id AND sa.status = 'archived'" : '';
    $where = $hasArchive ? 'sa.id IS NULL AND ' : '';
    $stmt = $mysqli->prepare("
        SELECT ep.id, ep.school_year, ep.semester, ep.is_active, COUNT(et.id) AS live
        FROM evaluation_periods ep
        LEFT JOIN evaluation_tracker et ON et.period_id = ep.id
        $join
        WHERE {$where}(ep.is_active = 1 OR (ep.school_year = ? AND ep.semester = ?))
        GROUP BY ep.id, ep.school_year, ep.semester, ep.is_active
        HAVING COUNT(et.id) > 0
        ORDER BY ep.is_active DESC, ep.id DESC");
    if (!$stmt) {
        // Fail closed: if we cannot verify, do not let a new round start on top of old data.
        return ['ok' => false, 'blocked' => true, 'periods' => [], 'error' => 'The current evaluation could not be checked. Please try again.'];
    }
    $stmt->bind_param('ss', $year, $term);
    $stmt->execute();
    $res = $stmt->get_result();
    $periods = [];
    while ($row = $res->fetch_assoc()) {
        $periods[] = ['id' => (int)$row['id'], 'label' => $row['school_year'] . ' — ' . $row['semester'],
                      'live' => (int)$row['live'], 'active' => (int)$row['is_active'] === 1];
    }
    $stmt->close();
    return ['ok' => true, 'blocked' => count($periods) > 0, 'periods' => $periods];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'schedule_gate') {
    ss_json(ss_current_evaluation_gate($mysqli, $sys));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_new_schedule') {
    // Enforced on the server too, so it cannot be skipped from the browser.
    $gate = ss_current_evaluation_gate($mysqli, $sys);
    if ($gate['blocked']) {
        ss_json(['ok' => false, 'code' => 'archive_required', 'periods' => $gate['periods'],
                 'error' => $gate['error'] ?? null,
                 'message' => 'Archive the current evaluation before creating a new schedule.'], 409);
    }
    $structure = (string)($sys['acad_structure'] ?? 'college');
    $terms = ss_structure_terms();
    if (!isset($terms[$structure])) $structure = 'college';
    $acad_year = trim($_POST['acad_year'] ?? '');
    $acad_term = trim($_POST['acad_term'] ?? '');
    $eval_start = trim($_POST['eval_start'] ?? '');
    $eval_end = trim($_POST['eval_end'] ?? '');

    if (!preg_match('/^(\d{4})-(\d{4})$/', $acad_year, $ym) || (int)$ym[2] !== (int)$ym[1] + 1) {
        ss_json(['ok' => false, 'message' => 'Enter the academic year like 2026-2027.'], 422);
    }
    if (!in_array($acad_term, $terms[$structure], true)) {
        ss_json(['ok' => false, 'message' => 'Choose a valid academic term.'], 422);
    }
    $ps = ss_parse_datetime($eval_start);
    $pe = ss_parse_datetime($eval_end);
    if (!$ps || !$pe) ss_json(['ok' => false, 'message' => 'Set both the opening and closing time.'], 422);
    if ($pe <= $ps)   ss_json(['ok' => false, 'message' => 'The closing time must be after the opening time.'], 422);
    if ($pe->getTimestamp() <= time()) ss_json(['ok' => false, 'message' => 'The closing time must be in the future.'], 422);

    // Re-using a year/term that is already archived would attach new evaluations to an archived period.
    if (ss_table_exists($mysqli, 'system_archives')) {
        $dup = $mysqli->prepare("SELECT ep.id FROM evaluation_periods ep
            JOIN system_archives sa ON sa.period_id = ep.id AND sa.status = 'archived'
            WHERE ep.school_year = ? AND ep.semester = ? LIMIT 1");
        if ($dup) {
            $dup->bind_param('ss', $acad_year, $acad_term);
            $dup->execute();
            $dup->store_result();
            $exists = $dup->num_rows > 0;
            $dup->close();
            if ($exists) {
                ss_json(['ok' => false, 'message' => $acad_year . ' · ' . $acad_term . ' is already archived. Choose a different term or year, or restore it from System Archive.'], 422);
            }
        }
    }

    try {
    $new = ['acad_year' => $acad_year, 'acad_term' => $acad_term,
            'eval_start' => $eval_start, 'eval_end' => $eval_end,
            'control_mode' => 'schedule', 'auto_schedule' => 1];
    foreach ($new as $k => $v) {
        $stmt = $mysqli->prepare("INSERT INTO system_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
        $sv = (string)$v; $stmt->bind_param('ss', $k, $sv); $stmt->execute(); $stmt->close();
    }
    $sys = ss_raw($mysqli);
    ss_sync_evaluation_period($mysqli, $sys);
    ss_notify_faculty_staff($mysqli, 'evaluation_schedule',
        'The Executive Assistant created a new evaluation schedule for ' . $acad_year . ' · ' . $acad_term . ': '
        . ss_fmt_manila($eval_start) . ' – ' . ss_fmt_manila($eval_end) . '.');
    ss_json(['ok' => true, 'message' => 'Now set for ' . $acad_year . ' · ' . $acad_term . '. Automatic Schedule is on and Faculty and Staff have been notified.']);
    } catch (Throwable $e) {
        error_log('create_new_schedule failed: ' . $e->getMessage());
        ss_json(['ok' => false, 'message' => 'The schedule could not be saved: ' . $e->getMessage()], 500);
    }
}

$saveMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_system_settings') {
    $acad_year = trim($_POST['acad_year'] ?? '');
    $acad_structure = $_POST['acad_structure'] ?? 'college';
    $terms = ss_structure_terms();
    if (!isset($terms[$acad_structure])) $acad_structure = 'college';
    $acad_term = $_POST['acad_term'] ?? $terms[$acad_structure][0];
    if (!in_array($acad_term, $terms[$acad_structure], true)) $acad_term = $terms[$acad_structure][0];

    $control_mode = $_POST['control_mode'] ?? 'schedule';
    if (!in_array($control_mode, ['schedule','open','closed'], true)) $control_mode = 'schedule';
    $auto_schedule = isset($_POST['auto_schedule']) ? 1 : 0;
    $maintenance = isset($_POST['maintenance']) ? 1 : 0;
    $eval_start = trim($_POST['eval_start'] ?? '');
    $eval_end = trim($_POST['eval_end'] ?? '');

    $parsedStart = ss_parse_datetime($eval_start);
    $parsedEnd   = ss_parse_datetime($eval_end);
    if (($eval_start !== '' || $eval_end !== '') && (!$parsedStart || !$parsedEnd || $parsedEnd <= $parsedStart)) {
        $saveMsg = 'Invalid evaluation schedule. The closing time must be after the opening time.';
    }

    $ruleKeys = ['rule_countdown'];
    if ($saveMsg === '') {
    $oldSys = $sys;
    $new = [
        'acad_year'=>$acad_year,
        'acad_structure'=>$acad_structure,
        'acad_term'=>$acad_term,
        'auto_schedule'=>$auto_schedule,
        'control_mode'=>$control_mode,
        'eval_start'=>$eval_start,
        'eval_end'=>$eval_end,
        'maintenance'=>$maintenance,
    ];
    foreach ($ruleKeys as $k) $new[$k] = isset($_POST[$k]) ? 1 : 0;
    foreach ($new as $k=>$v) {
        $stmt=$mysqli->prepare("INSERT INTO system_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
        $sv=(string)$v; $stmt->bind_param('ss',$k,$sv); $stmt->execute(); $stmt->close();
    }
    $sys = ss_raw($mysqli);
    ss_sync_evaluation_period($mysqli, $sys);

    // Tell Faculty and Staff what the EA changed (only when something actually changed).
    $periodChanged = (string)($oldSys['acad_year'] ?? '') !== (string)$acad_year
        || (string)($oldSys['acad_structure'] ?? '') !== (string)$acad_structure
        || (string)($oldSys['acad_term'] ?? '') !== (string)$acad_term;
    if ($periodChanged) {
        ss_notify_faculty_staff($mysqli, 'academic_period',
            'The Executive Assistant set the academic period to ' . $acad_year . ' · ' . $acad_term . '.');
    }
    $scheduleChanged = (string)($oldSys['eval_start'] ?? '') !== (string)$eval_start
        || (string)($oldSys['eval_end'] ?? '') !== (string)$eval_end;
    if ($scheduleChanged && $eval_start !== '' && $eval_end !== '') {
        ss_notify_faculty_staff($mysqli, 'evaluation_schedule',
            'The Executive Assistant set the evaluation schedule: ' . ss_fmt_manila($eval_start) . ' – ' . ss_fmt_manila($eval_end) . '.');
    }

    $saveMsg = 'System & Period Settings saved.';
    }
}

function ss_h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>System & Period Settings</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root{
    --bg:          #0A1628;
    --surface:     #FFFFFF;
    --surface-2:   #F8FAFC;
    --surface-3:   #F4F8FF;
    --border:      rgba(30,82,144,.13);
    --border-strong: rgba(15,23,42,.18);
    --text:        #0B1F3A;
    --text-muted:  #8291B3;
    --text-faint:  #5C6B8A;
    --blue:        #3B6FE0;
    --blue-hover:  #4C7CEA;
    --blue-soft:   rgba(59,111,224,0.16);
    --green:       #34D399;
    --green-soft:  rgba(52,211,153,0.14);
    --red:         #E6788A;
    --red-soft:    rgba(248,113,113,0.14);
    --amber:       #FBBF24;
    --amber-soft:  rgba(251,191,36,0.14);
    --radius:      14px;
    font-size: 16px;
  }

  *{ box-sizing: border-box; }
  html,body{ margin:0; padding:0; }

  body{
    background: var(--bg);
    color: var(--text);
    font-family: 'Inter', sans-serif;
    -webkit-font-smoothing: antialiased;
    min-height: 100vh;
  }

  .shell{
    max-width: 1000px;
    margin: 0 auto;
    padding: 40px 28px 90px;
  }

  /* ---------- header ---------- */
  .top{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap: 20px;
    margin-bottom: 22px;
  }
  .page-title{
    font-size: 28px;
    font-weight: 800;
    letter-spacing: -0.01em;
    margin: 0 0 6px;
  }
  .page-sub{
    color: var(--text-muted);
    font-size: 14px;
    margin: 0;
  }
  .btn{
    font-family:'Inter', sans-serif;
    font-size: 14px;
    font-weight: 600;
    padding: 11px 18px;
    border-radius: 10px;
    border: 1px solid var(--border);
    background: var(--surface-2);
    color: var(--text);
    cursor: pointer;
    transition: background .15s ease, border-color .15s ease, transform .1s ease;
    display:inline-flex; align-items:center; gap:8px;
    white-space: nowrap;
  }
  .btn:hover{ border-color: var(--border-strong); }
  .btn:active{ transform: translateY(1px); }
  .btn-primary{
    background: var(--blue);
    border-color: var(--blue);
    color: #fff;
  }
  .btn-primary:hover{ background: var(--blue-hover); }

  /* ---------- tabs ---------- */
  .tabs{
    display: inline-flex;
    gap: 4px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 4px;
    margin-bottom: 26px;
  }
  .tab{
    font-size: 13.5px;
    font-weight: 600;
    color: var(--text-muted);
    padding: 9px 16px;
    border-radius: 8px;
    cursor: default;
    user-select: none;
    transition: background .15s ease, color .15s ease;
  }
  .tab.active{
    color: #fff;
    background: var(--blue);
  }

  /* ---------- stat row (snapshot) ---------- */
  .stat-row{
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin-bottom: 26px;
  }
  @media (max-width: 720px){ .stat-row{ grid-template-columns: 1fr; } }
  .stat-card{
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 20px 22px;
  }
  .stat-label{
    font-size: 11.5px;
    font-weight: 600;
    letter-spacing: 0.07em;
    text-transform: uppercase;
    color: var(--text-muted);
    margin-bottom: 10px;
  }
  .stat-value{
    font-size: 26px;
    font-weight: 700;
    color: var(--text);
    line-height: 1.15;
  }
  .stat-value.small{ font-size: 18px; }
  .stat-value.green{ color: var(--green); }
  .stat-value.red{ color: var(--red); }
  .stat-value.amber{ color: var(--amber); }
  .stat-note{
    font-family: 'IBM Plex Mono', monospace;
    font-size: 11.5px;
    color: var(--text-faint);
    margin-top: 8px;
  }

  /* ---------- sections ---------- */
  .section{
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    margin-bottom: 18px;
    overflow: hidden;
  }
  .section-head{
    display:flex; align-items:baseline; justify-content:space-between;
    gap: 12px;
    padding: 18px 22px;
    background: var(--surface-2);
    border-bottom: 1px solid var(--border);
  }
  .section-eyebrow{
    font-size: 11.5px;
    font-weight: 700;
    letter-spacing: 0.08em;
    color: #9DB4E8;
    text-transform: uppercase;
  }
  .section-hint{
    font-size: 12.5px;
    color: var(--text-faint);
  }
  .section-body{
    padding: 20px 22px 22px;
    display: grid;
    gap: 16px;
  }

  .field-row{
    display:grid;
    grid-template-columns: 230px 1fr;
    align-items: center;
    gap: 14px;
  }
  @media (max-width: 620px){
    .field-row{ grid-template-columns: 1fr; }
  }
  .field-label{
    font-size: 14px;
    font-weight: 600;
    color: var(--text);
  }
  .field-help{
    display:block;
    font-size: 12px;
    font-weight: 400;
    color: var(--text-faint);
    margin-top: 3px;
  }

  input[type="text"], input[type="datetime-local"], select{
    width: 100%;
    font-family: 'Inter', sans-serif;
    font-size: 14px;
    color: var(--text);
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 9px;
    padding: 10px 12px;
    transition: border-color .15s ease, box-shadow .15s ease;
  }
  input::placeholder{ color: var(--text-faint); }
  input[type="datetime-local"]{ color-scheme: light; }
  input[type="datetime-local"]::-webkit-calendar-picker-indicator{
    opacity: 1;
    cursor: pointer;
    padding: 4px;
    margin-left: 6px;
    border-radius: 6px;
    filter: invert(46%) sepia(56%) saturate(1934%) hue-rotate(196deg) brightness(94%) contrast(93%);
  }
  input[type="datetime-local"]::-webkit-calendar-picker-indicator:hover{
    background: var(--blue-soft);
  }
  input:focus, select:focus{
    outline: none;
    border-color: var(--blue);
    box-shadow: 0 0 0 3px var(--blue-soft);
  }
  select:disabled, input:disabled{
    opacity: 0.5;
    cursor: not-allowed;
  }
  select{ appearance: none; -webkit-appearance:none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%238291B3' stroke-width='1.5' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    padding-right: 32px;
  }

  /* Visible dropdown arrows for all select fields */
  .select-wrap{
    position:relative;
    width:100%;
  }
  .select-wrap select{
    width:100%;
    appearance:none !important;
    -webkit-appearance:none !important;
    -moz-appearance:none !important;
    padding-right:46px !important;
    background-image:none !important;
  }
  .select-wrap::after{
    content:'';
    position:absolute;
    right:16px;
    top:50%;
    width:9px;
    height:9px;
    border-right:2px solid #294765;
    border-bottom:2px solid #294765;
    transform:translateY(-65%) rotate(45deg);
    pointer-events:none;
  }
  .select-wrap:hover::after{
    border-color:#2563EB;
  }
  .select-wrap select:focus + * + &{}



  /* toggle switch */
  .switch{
    position: relative;
    width: 42px; height: 24px;
    flex: none;
    display:inline-block;
  }
  .switch input{ display:none; }
  .switch .track{
    position:absolute; inset:0;
    background: var(--surface-3);
    border: 1px solid var(--border);
    border-radius: 999px;
    transition: background .15s ease;
    cursor:pointer;
  }
  .switch .thumb{
    position:absolute; top:2px; left:2px;
    width:18px; height:18px;
    background:#fff;
    border-radius:50%;
    box-shadow: 0 1px 3px rgba(0,0,0,0.4);
    transition: transform .15s ease;
  }
  .switch input:checked + .track{ background: var(--blue); border-color: var(--blue); }
  .switch input:checked + .track .thumb{ transform: translateX(18px); }

  .toggle-row{
    display:flex; align-items:center; justify-content:space-between;
    gap: 14px;
    padding: 12px 0;
    border-bottom: 1px solid var(--border);
  }
  .toggle-row:last-child{ border-bottom:none; padding-bottom:0; }
  .toggle-copy .field-label{ margin:0; }

  /* radio control mode */
  .control-modes{
    display:grid;
    gap: 8px;
  }
  .control-option{
    display:flex; align-items:flex-start; gap: 10px;
    border: 1px solid var(--border);
    background: var(--surface-2);
    border-radius: 10px;
    padding: 12px 14px;
    cursor: pointer;
    transition: border-color .15s ease, background .15s ease;
  }
  .control-option:hover{ border-color: var(--border-strong); }
  .control-option input{ margin-top: 3px; accent-color: var(--blue); }
  .control-option.selected{ border-color: var(--blue); background: var(--blue-soft); }
  .control-option .co-title{ font-size: 14px; font-weight: 600; color: var(--text); }
  .control-option .co-desc{ font-size: 12px; color: var(--text-faint); margin-top:1px; }

  .maint-banner{
    display:none;
    align-items:center; gap:10px;
    background: var(--amber-soft);
    color: var(--amber);
    border: 1px solid rgba(251,191,36,0.35);
    border-radius: 10px;
    padding: 12px 16px;
    font-size: 13px;
    margin-bottom: 18px;
  }
  .maint-banner.show{ display:flex; }

  /* footer actions */
  .actions{
    display:flex; justify-content:flex-end; gap: 10px;
    padding-top: 8px;
  }

  .toast{
    position: fixed;
    top: 18px; left: 50%;
    z-index: 1000;
    transform: translateX(-50%) translateY(-20px);
    background: var(--surface-3);
    border: 1px solid var(--border-strong);
    color: var(--text);
    font-size: 13px;
    padding: 12px 20px;
    border-radius: 10px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.4);
    opacity: 0;
    pointer-events: none;
    transition: opacity .2s ease, transform .2s ease;
    display:flex; align-items:center; gap:8px;
  }
  .toast.show{ opacity: 1; transform: translateX(-50%) translateY(0); }
  .toast .dot{ width:7px; height:7px; border-radius:50%; background: var(--green); }

  ::-webkit-scrollbar{ width: 10px; }
  ::-webkit-scrollbar-thumb{ background: var(--surface-3); border-radius: 999px; }
  ::-webkit-scrollbar-track{ background: transparent; }

/* Admin Module light design system — matches the dashboard */
:root{
  --page-bg:#FFFFFF; --card-bg:#FFFFFF; --card-border:#D8E5F4;
  --inner:#F7FAFF; --text-dark:#0B1F3A; --text-dim:#67819E;
  --light:#0B1F3A; --muted:#67819E; --dark:#FFFFFF; --mid:#FFFFFF;
  --border:#D8E5F4; --accent:#2563EB; --blue:#2563EB;
  --gold:#C77A08; --gold-h:#D69612; --teal:#0E7490; --violet:#4968C8;
  --danger:#D6455D; --success:#0F9F6E; --radius:12px;
  --card-shadow:0 2px 4px rgba(30,82,144,.06),0 6px 16px rgba(30,82,144,.08);
}
html{background:#FFFFFF;color-scheme:light;}
body{background:#FFFFFF !important;color:#0B1F3A !important;}
a{color:inherit;}
.page-header h1,.page-title,.et-title,.section-title{color:#0B1F3A !important;}
.page-header p,.page-sub,.et-sub,.et-updated,.muted,.hint{color:#67819E !important;}
input,select,textarea{background:#fff !important;color:#0B1F3A !important;border-color:#B9CDE5 !important;}
button{font-family:inherit;}
.table-wrap,.content-panel,.create-panel,.period-card,.stat-card,.sector-card,.person-row,
.sum-card,.standing-panel,.eval-card,.eval-banner,.info-banner,.section,.shell .section,
.history-card,.gl-card,.amber-card,.green-card,.red-card{
  background:#fff !important;border-color:#D8E5F4 !important;box-shadow:0 2px 4px rgba(30,82,144,.05),0 6px 16px rgba(30,82,144,.06) !important;
}
.sector-tabs,.eval-switcher,.tabs,.level-tabs,.status-tabs{
  background:#fff !important;border-color:#D8E5F4 !important;box-shadow:0 2px 4px rgba(30,82,144,.05) !important;
}
.sector-tab,.eval-tab,.tab,.level-tab,.status-tab{color:#67819E !important;}
.sector-tab:hover,.eval-tab:hover,.tab:hover,.level-tab:hover,.status-tab:hover{color:#0B1F3A !important;background:#F7FAFF !important;}
thead tr{background:#F8FAFC !important;}
tbody tr:hover{background:#F8FAFC !important;}
.btn-cancel,.btn-icon,.btn-back{background:#fff !important;color:#0B1F3A !important;border-color:#B9CDE5 !important;}
.empty-state,.empty-cta{color:#67819E !important;}
::-webkit-scrollbar-track{background:#fff;}
::-webkit-scrollbar-thumb{background:#B9CDE5;border:2px solid #fff;}

body{background:#fff !important;}
.shell{max-width:none !important;margin:0 !important;padding:28px !important;}
.section{background:#fff !important;}
.maint-banner{background:#FFFBEB !important;color:#92400E !important;border-color:#FDE68A !important;}
.control-option,.toggle-row{background:#F8FAFC !important;border-color:#D8E5F4 !important;}


/* ── SHARP LIGHT ADMIN UI ── */
html { background:#F8FAFC; }
body {
  color:#0B1F3A !important;
  background:#F8FAFC !important;
  -webkit-font-smoothing:antialiased;
  text-rendering:optimizeLegibility;
}
h1,h2,h3,h4,h5,h6 { color:#0B1F3A; letter-spacing:-.01em; }
p, .subtitle, .description, .helper, .muted, small { color:#67819E; }
label, th { color:#294765; font-weight:600; }
td { color:#0B1F3A; }
input, select, textarea {
  color:#0B1F3A;
  background:#FFFFFF;
  border-color:#B9CDE5;
}
input::placeholder, textarea::placeholder { color:#91A6BE; }
.card, .panel, .section, .table-card, .content-card {
  border-color:#B9CDE5;
  box-shadow:0 4px 14px rgba(30,82,144,.09);
}
button, .btn { font-weight:700; }
a { color:inherit; }
</style>
    <link rel="stylesheet" href="admin_ui_theme.css">
    <link rel="stylesheet" href="admin_compact_ui.css">
<style id="ss-friendly">
/* readable status colours on a white card */
.stat-value.green{color:#0F9F6E}
.stat-value.amber{color:#C77A08}
.stat-value.red{color:#D6455D}
.stat-card{position:relative}
.stat-card.tone-green{border-left:4px solid #0F9F6E !important}
.stat-card.tone-amber{border-left:4px solid #E2A33B !important}
.stat-card.tone-red{border-left:4px solid #D6455D !important}
.stat-explain{margin-top:8px;font-size:12.5px;line-height:1.5;color:#405A76}
.stat-explain:empty{display:none}
/* heads-up alerts with an action */
.ss-alerts{display:grid;gap:10px;margin-bottom:16px}
.ss-alerts:empty{display:none}
.ss-alert{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:12px 16px;border-radius:10px;font-size:13px;line-height:1.5}
.ss-alert.amber{background:#FFF7E6;border:1px solid #F5D48A;color:#8D5D00}
.ss-alert.red{background:#FFF0F2;border:1px solid #F1B7C0;color:#A62A42}
.ss-alert button{flex:0 0 auto;border:1px solid currentColor;background:#fff;color:inherit;border-radius:8px;padding:7px 12px;font:700 12px Inter,sans-serif;cursor:pointer;white-space:nowrap}
.ss-alert button:hover{background:rgba(255,255,255,.6)}
/* inline field feedback */
.field-note{font-size:12px;margin-top:5px;color:#67819E}
.field-note.warn{color:#C77A08}
.field-note:empty{display:none}
input.has-warn{border-color:#E2A33B !important}
/* quick date buttons */
/* evaluation access as three clear cards */
.control-modes{grid-template-columns:repeat(3,1fr);gap:12px}
@media(max-width:760px){.control-modes{grid-template-columns:1fr}}
.control-option{flex-direction:row;padding:14px 15px;border-width:1.5px}
.control-option:hover{border-color:#9DBBE6 !important}
.control-option.selected{box-shadow:0 0 0 3px rgba(37,99,235,.12)}
.control-option.selected[data-mode="schedule"]{background:#EEF4FF !important;border-color:#2563EB !important}
.control-option.selected[data-mode="open"]{background:#FFF7E6 !important;border-color:#E2A33B !important;box-shadow:0 0 0 3px rgba(226,163,59,.18)}
.control-option.selected[data-mode="closed"]{background:#FFF0F2 !important;border-color:#D6455D !important;box-shadow:0 0 0 3px rgba(214,69,93,.14)}
.control-option .co-title{font-size:14px}
/* sticky save bar */
.actions{position:sticky;bottom:0;z-index:5;align-items:center;margin-top:6px;padding:14px 0 8px;background:linear-gradient(to bottom,rgba(248,250,252,0),#F8FAFC 35%)}
.dirty-note{margin-right:auto;font-size:12.5px;font-weight:600;color:#67819E;display:inline-flex;align-items:center;gap:8px}
.dirty-note::before{content:'';width:8px;height:8px;border-radius:50%;background:#0F9F6E}
.dirty-note.dirty{color:#B26A06}
.dirty-note.dirty::before{background:#F0B429;box-shadow:0 0 0 3px rgba(240,180,41,.25)}
.btn:disabled{opacity:.5;cursor:not-allowed}
.btn-primary:disabled{background:#9DB6E8 !important;border-color:#9DB6E8 !important}
</style>
<style id="ns-styles">
.newsched-banner{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin:0 0 10px;padding:14px 16px;border:1px solid #CFE0F7;border-radius:12px;background:#F4F8FF}
.newsched-copy{display:flex;flex-direction:column;gap:2px;min-width:220px;flex:1}
.newsched-copy strong{font-size:14px;color:#0B1F3A}
.newsched-copy span{font-size:12.5px;color:#5C6B8A;line-height:1.45}
.ns-overlay{position:fixed;inset:0;z-index:2147483000;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(11,31,58,.55);overscroll-behavior:contain}
.ns-overlay[hidden]{display:none}
.ns-modal{width:min(540px,100%);max-height:calc(100vh - 40px);overflow:auto;background:#fff;border-radius:16px;padding:24px;box-shadow:0 24px 60px rgba(11,31,58,.35)}
.ns-modal h3{font-size:18px;color:#0B1F3A;margin:0 0 6px}
.ns-text{font-size:13.5px;line-height:1.55;color:#405A76;margin:0 0 12px}
.ns-note{font-size:12px;line-height:1.5;color:#67819E;margin:12px 0 0}
.ns-busy{padding:28px 0;text-align:center;color:#67819E;font-size:13.5px}
.ns-badge{width:40px;height:40px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:20px;margin-bottom:12px}
.ns-badge.warn{background:#FFF7E6;color:#C77A08}
.ns-list{list-style:none;margin:0 0 4px;padding:0;display:grid;gap:8px}
.ns-list li{padding:10px 12px;border:1px solid #F5D48A;background:#FFFBF0;border-radius:10px;font-size:13px;color:#7A5200}
.ns-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:520px){.ns-grid{grid-template-columns:1fr}}
.ns-grid label{display:flex;flex-direction:column;gap:5px;font-size:12px;font-weight:700;color:#405A76}
.ns-grid input,.ns-grid select{height:42px;padding:0 12px;border:1px solid #B9CDE5;border-radius:10px;font:500 14px Inter,system-ui,sans-serif;color:#0B1F3A;background:#fff}
.ns-grid input:focus,.ns-grid select:focus{outline:2px solid rgba(37,99,235,.35);border-color:#2563EB}
.ns-quick{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}
.ns-q{border:1px solid #B9CDE5;background:#fff;color:#2563EB;border-radius:999px;padding:6px 12px;font:700 12px Inter,sans-serif;cursor:pointer}
.ns-q:hover{background:#EEF4FF;border-color:#2563EB}
.ns-error{margin-top:12px;padding:10px 12px;border-radius:10px;background:#FFF0F2;border:1px solid #F1B7C0;color:#A62A42;font-size:12.5px;line-height:1.45}
.ns-error[hidden]{display:none}
.ns-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:18px}
.ns-modal,.ns-modal *{box-sizing:border-box;font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif}
.ns-modal:focus{outline:none}
.ns-modal [hidden]{display:none!important}
.ns-modal h3{font-weight:700;line-height:1.25}
.ns-btn{height:40px;padding:0 16px;border:1px solid #B9CDE5;background:#fff;color:#0B1F3A;border-radius:10px;font-weight:700;font-size:13px;cursor:pointer}
.ns-btn:hover{border-color:#2563EB}
.ns-btn.primary{background:#2563EB;border-color:#2563EB;color:#fff}
.ns-btn.primary:hover{background:#1E4FBF}
.ns-btn:disabled{opacity:.55;cursor:not-allowed}
.sched-current{border:1px solid #D8E5F4;border-radius:12px;background:#fff;padding:14px 16px;margin:0 0 12px}
.sched-current-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px}
.sched-current-label{font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#67819E}
.sched-current-row{display:flex;align-items:center;gap:20px;flex-wrap:wrap}
.sched-cell{display:flex;flex-direction:column;gap:2px;min-width:150px}
.sched-cell span{font-size:11.5px;color:#67819E;font-weight:600}
.sched-cell b{font-size:15px;color:#0B1F3A}
.sched-arrow{color:#9DB6E8;font-size:18px}
.ss-success{display:flex;align-items:center;gap:14px;margin:0 0 16px;padding:14px 16px;border:1px solid #B9E4D3;border-radius:12px;background:#E8F8F1;color:#0A7A55}
.ss-success[hidden]{display:none}
.ss-success-icon{flex:0 0 auto;width:30px;height:30px;border-radius:50%;background:#0F9F6E;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:16px}
.ss-success-text{flex:1;display:flex;flex-direction:column;gap:2px;min-width:0}
.ss-success-text strong{font-size:15px;color:#075E42}
.ss-success-text span{font-size:12.5px;line-height:1.45}
.ss-success-close{flex:0 0 auto;border:0;background:transparent;color:#0A7A55;font-size:22px;line-height:1;cursor:pointer;padding:2px 6px;border-radius:6px}
.ss-success-close:hover{background:rgba(15,159,110,.14)}
.sched-flash{animation:schedFlash 2.4s ease}
@keyframes schedFlash{0%{box-shadow:0 0 0 0 rgba(15,159,110,.55);border-color:#0F9F6E}70%{box-shadow:0 0 0 10px rgba(15,159,110,0);border-color:#0F9F6E}100%{box-shadow:0 0 0 0 rgba(15,159,110,0)}}
.sched-chip{display:inline-flex;align-items:center;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:800;letter-spacing:.03em}
.sched-chip.green{background:#E8F8F1;color:#0A7A55}
.sched-chip.amber{background:#FFF7E6;color:#A86400}
.sched-chip.red{background:#FFF0F2;color:#C23850}
.sched-chip.gray{background:#EEF2F7;color:#5C6B8A}
.ns-head{position:sticky;top:-24px;z-index:2;margin:-24px -24px 0;padding:24px 24px 12px;background:#fff;border-radius:16px 16px 0 0}
.ns-head-row{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap}
.ns-head-text{flex:1;min-width:220px}
.ns-head-text .ns-text{margin-bottom:0}
.ns-head-actions{display:flex;gap:10px;flex:0 0 auto}
.ns-discard{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:12px;padding:10px 12px;border:1px solid #F5D48A;background:#FFFBF0;border-radius:10px;color:#7A5200;font-size:13px}
.ns-discard span{flex:1;min-width:180px}
.ns-btn.danger{background:#D6455D;border-color:#D6455D;color:#fff}
.ns-btn.danger:hover{background:#B8364C}
.ns-shake{animation:nsShake .35s ease}
@keyframes nsShake{0%,100%{transform:translateX(0)}25%{transform:translateX(-6px)}75%{transform:translateX(6px)}}
.ns-sec{margin:16px 0 0}
.ns-sec-title{font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#67819E;margin:0 0 8px}
.ns-quick-label{font-size:11.5px;color:#67819E;font-weight:700;margin:12px 0 6px}
.ns-quick{margin-top:0}
.ns-preview{margin-top:12px;padding:11px 13px;border-radius:10px;font-size:12.5px;line-height:1.5;border:1px solid #D8E5F4;background:#F7FAFF;color:#405A76}
.ns-preview b{display:block;font-size:13.5px;color:#0B1F3A}
.ns-preview.good{background:#E8F8F1;border-color:#B9E4D3;color:#0A7A55}
.ns-preview.good b{color:#075E42}
.ns-preview.bad{background:#FFF0F2;border-color:#F1B7C0;color:#A62A42}
.ns-preview.bad b{color:#A62A42}
</style>
<style id="ss-v2">
.shell{max-width:1180px !important;margin:0 auto !important}
/* status hero */
.ss2-hero{padding:22px 24px !important;margin:0 0 18px;border-radius:16px;background:#fff;border:1px solid #D8E5F4;box-shadow:0 2px 4px rgba(30,82,144,.05),0 8px 20px rgba(30,82,144,.06)}
.ss2-hero-top{display:flex;justify-content:space-between;gap:28px;flex-wrap:wrap}
.ss2-hero-status{flex:1;min-width:260px}
.ss2-eyebrow{font-size:11px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;color:#67819E}
.ss2-hero .stat-value{font-size:30px;font-weight:800;margin-top:4px;letter-spacing:-.01em}
.ss2-hero .stat-explain{font-size:14px;line-height:1.55;margin-top:6px;max-width:560px;color:#405A76}
.ss2-hero-facts{display:flex;gap:28px;flex-wrap:wrap;align-items:flex-start}
.ss2-fact{display:flex;flex-direction:column;gap:2px;min-width:150px}
.ss2-fact span{font-size:11.5px;color:#67819E;font-weight:600}
.ss2-fact b{font-size:15px;color:#0B1F3A}
.ss2-fact small{font-size:12.5px;color:#5C6B8A}
.ss2-timeline{margin-top:18px;padding-top:16px;border-top:1px solid #E6EEF8}
.ss2-tl-ends{display:flex;justify-content:space-between;gap:16px;margin-bottom:8px}
.ss2-tl-ends span{display:block;font-size:11.5px;color:#67819E;font-weight:600}
.ss2-tl-ends b{font-size:14px;color:#0B1F3A}
.ss2-tl-ends .r{text-align:right}
.ss2-tl-bar{height:8px;border-radius:999px;background:#E9F0F9;overflow:hidden}
.ss2-tl-bar i{display:block;height:100%;width:0;border-radius:999px;background:#2563EB;transition:width .4s}
.stat-card.tone-green .ss2-tl-bar i{background:#0F9F6E}
.stat-card.tone-amber .ss2-tl-bar i{background:#E2A33B}
.stat-card.tone-red .ss2-tl-bar i{background:#D6455D}
.ss2-tl-note{margin-top:8px;font-size:12.5px;color:#5C6B8A}
/* numbered cards */
.ss2-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;align-items:start}
@media(max-width:980px){.ss2-grid{grid-template-columns:1fr}}
.ss2-card{background:#fff;border:1px solid #D8E5F4;border-radius:16px;padding:20px 22px;box-shadow:0 1px 3px rgba(30,82,144,.05)}
.ss2-card-head{display:flex;gap:14px;align-items:flex-start;margin-bottom:16px}
.ss2-num{flex:0 0 auto;width:28px;height:28px;border-radius:50%;background:#EEF4FF;color:#2563EB;font-weight:800;font-size:13px;display:flex;align-items:center;justify-content:center}
.ss2-card-head h2{margin:0;font-size:16px;font-weight:700;color:#0B1F3A}
.ss2-card-head p{margin:3px 0 0;font-size:12.5px;line-height:1.5;color:#5C6B8A}
.ss2-fields{display:grid;gap:14px}
.ss2-field label{display:block;font-size:12px;font-weight:700;color:#405A76;margin-bottom:6px}
.ss2-help{font-size:12px;color:#67819E;margin-top:5px}
.ss2-card .ss2-cta{display:flex;width:100%;justify-content:center;align-items:center;gap:10px;padding:15px 18px;font-size:15px;font-weight:700;letter-spacing:.01em;color:#fff !important;background:linear-gradient(135deg,#2F6BF2,#1D4ED8) !important;border:1px solid #1B45BF !important;border-radius:12px;box-shadow:0 6px 16px rgba(37,99,235,.35),inset 0 1px 0 rgba(255,255,255,.22);cursor:pointer;transition:transform .12s ease,box-shadow .12s ease,filter .12s ease}
.ss2-card .ss2-cta:hover{filter:brightness(1.07);box-shadow:0 8px 22px rgba(37,99,235,.45),inset 0 1px 0 rgba(255,255,255,.25);transform:translateY(-1px)}
.ss2-card .ss2-cta:active{transform:translateY(0);box-shadow:0 3px 8px rgba(37,99,235,.35)}
.ss2-card .ss2-cta:focus-visible{outline:3px solid rgba(37,99,235,.4);outline-offset:2px}
.ss2-cta-plus{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:rgba(255,255,255,.22);font-size:18px;line-height:1;font-weight:800}
/* draws the eye when a new schedule is actually needed (ended or not set) */
.ss2-card .ss2-cta.attn{animation:ss2Pulse 2s ease-out infinite}
@keyframes ss2Pulse{0%{box-shadow:0 6px 16px rgba(37,99,235,.35),0 0 0 0 rgba(37,99,235,.45)}70%{box-shadow:0 6px 16px rgba(37,99,235,.35),0 0 0 12px rgba(37,99,235,0)}100%{box-shadow:0 6px 16px rgba(37,99,235,.35),0 0 0 0 rgba(37,99,235,0)}}
@media (prefers-reduced-motion:reduce){.ss2-card .ss2-cta.attn{animation:none}}
.ss2-steps{display:flex;gap:6px 14px;flex-wrap:wrap;margin:12px 0 14px;font-size:12px;color:#5C6B8A}
.ss2-steps span{display:inline-flex;align-items:center;gap:6px}
.ss2-steps b{width:18px;height:18px;border-radius:50%;background:#EEF4FF;color:#2563EB;font-size:11px;display:inline-flex;align-items:center;justify-content:center}
.ss2-card .toggle-row,.ss2-card .toggle-row:last-child{padding:12px 14px;border:1px solid #D8E5F4;border-radius:12px;margin-top:10px}
.ss2-card .control-modes{display:grid;grid-template-columns:1fr;gap:10px}
.ss2-tag{margin-left:8px;padding:2px 8px;border-radius:999px;background:#E8F8F1;color:#0A7A55;font-size:10.5px;font-weight:800;vertical-align:middle}
.ss2-auto[hidden]{display:none}
.ss2-danger{border-color:#F1D3D9}
.ss2-danger .ss2-num{background:#FFF0F2;color:#D6455D}
</style>
<style id="pbi-feature-scrollbar">

/* PBI FEATURE SCROLLBAR — consistent with the compact page scrollbar */
html, body {
  scrollbar-width: thin !important;
  scrollbar-color: #888 transparent !important;
}
html::-webkit-scrollbar, body::-webkit-scrollbar,
.feature-compact ::-webkit-scrollbar {
  width: 10px !important;
  height: 10px !important;
}
html::-webkit-scrollbar-track, body::-webkit-scrollbar-track,
.feature-compact ::-webkit-scrollbar-track {
  background: transparent !important;
}
html::-webkit-scrollbar-thumb, body::-webkit-scrollbar-thumb,
.feature-compact ::-webkit-scrollbar-thumb {
  background: #888 !important;
  border-radius: 999px !important;
  border: 2px solid transparent !important;
  background-clip: padding-box !important;
}
html::-webkit-scrollbar-thumb:hover, body::-webkit-scrollbar-thumb:hover,
.feature-compact ::-webkit-scrollbar-thumb:hover {
  background: #777 !important;
  background-clip: padding-box !important;
}
html::-webkit-scrollbar-button, body::-webkit-scrollbar-button,
.feature-compact ::-webkit-scrollbar-button {
  display: block !important;
  width: 10px !important;
  height: 10px !important;
  background-color: transparent !important;
}
/* Small native-looking arrow hints on classic scrollbars */
html::-webkit-scrollbar-button:single-button:vertical:decrement,
body::-webkit-scrollbar-button:single-button:vertical:decrement,
.feature-compact ::-webkit-scrollbar-button:single-button:vertical:decrement {
  background:
    linear-gradient(135deg, transparent 50%, #777 50%) 3px 5px/5px 5px no-repeat !important;
}
html::-webkit-scrollbar-button:single-button:vertical:increment,
body::-webkit-scrollbar-button:single-button:vertical:increment,
.feature-compact ::-webkit-scrollbar-button:single-button:vertical:increment {
  background:
    linear-gradient(315deg, transparent 50%, #777 50%) 3px 0/5px 5px no-repeat !important;
}
html::-webkit-scrollbar-button:single-button:horizontal:decrement,
body::-webkit-scrollbar-button:single-button:horizontal:decrement,
.feature-compact ::-webkit-scrollbar-button:single-button:horizontal:decrement {
  background:
    linear-gradient(45deg, transparent 50%, #777 50%) 5px 3px/5px 5px no-repeat !important;
}
html::-webkit-scrollbar-button:single-button:horizontal:increment,
body::-webkit-scrollbar-button:single-button:horizontal:increment,
.feature-compact ::-webkit-scrollbar-button:single-button:horizontal:increment {
  background:
    linear-gradient(225deg, transparent 50%, #777 50%) 0 3px/5px 5px no-repeat !important;
}

</style>

<link rel="stylesheet" href="admin_appearance.css">
<script src="admin_appearance.js"></script>
<link rel="stylesheet" href="admin_unified_ui.css?v=<?= (int)@filemtime(__DIR__ . '/admin_unified_ui.css') ?>">
<style id="ss-subtabs-css">
.ss-subtabs{display:inline-flex;gap:4px;padding:4px;margin:0 0 16px;background:#F1F5FA;border:1px solid #D8E5F4;border-radius:12px}
.ss-subtab{appearance:none;border:0;background:transparent;color:#49647F;font:600 13px Inter,system-ui,sans-serif;padding:8px 16px;border-radius:9px;cursor:pointer}
.ss-subtab:hover{color:#102746}
.ss-subtab.active{background:#fff;color:#C2570C;box-shadow:0 1px 3px rgba(30,82,144,.12)}
.ss-subtab:focus-visible{outline:3px solid rgba(242,140,40,.35);outline-offset:1px}
.ss-history-frame{display:block;width:100%;height:calc(100vh - 120px);min-height:420px;border:0;background:transparent}
</style>
</head>
<body class="feature-compact ux-nested">
<div class="shell">

  <!-- Sub-tabs: current schedule, previous (not yet archived) schedules, archived history -->
  <nav class="ss-subtabs" role="tablist" aria-label="System and period sections">
    <button type="button" class="ss-subtab active" id="subCurrent" role="tab" aria-selected="true" data-pane="current">Current schedule</button>
    <button type="button" class="ss-subtab" id="subPrevious" role="tab" aria-selected="false" data-pane="previous">Previous schedules</button>
    <button type="button" class="ss-subtab" id="subHistory" role="tab" aria-selected="false" data-pane="history">Schedule history</button>
  </nav>
  <div id="paneCurrent">

  <?php if ($saveMsg): ?><div class="maint-banner show" style="margin-bottom:14px"><strong><?=ss_h($saveMsg)?></strong></div><?php endif; ?>

  <!-- Hidden hook: the script below attaches a click handler to this button -->
  <button class="btn btn-primary" id="saveBtnTop" style="display:none">Save System Settings</button>

  <!-- STATUS: what is happening right now, in plain words -->
  <section class="stat-card ss2-hero" id="heroCard">
    <div class="ss2-hero-top">
      <div class="ss2-hero-status">
        <span class="ss2-eyebrow">Evaluation right now</span>
        <div class="stat-value" id="statStatus">&mdash;</div>
        <div class="stat-explain" id="statExplain"></div>
      </div>
      <div class="ss2-hero-facts">
        <div class="ss2-fact"><span>Academic period</span><b id="statAcademic">&mdash;</b><small id="statTerm"></small></div>
        <div class="ss2-fact"><span>Who can submit</span><b id="statMode">&mdash;</b></div>
      </div>
    </div>
    <div class="ss2-timeline">
      <div class="ss2-tl-ends">
        <div><span>Opens</span><b id="tlOpens">Not set</b></div>
        <div class="r"><span>Closes</span><b id="tlCloses">Not set</b></div>
      </div>
      <div class="ss2-tl-bar"><i id="tlFill"></i></div>
      <div class="ss2-tl-note" id="tlNote"></div>
    </div>
  </section>

  <div class="ss-success" id="ssSuccess" role="status" aria-live="polite" hidden>
    <span class="ss-success-icon" aria-hidden="true">&#10003;</span>
    <div class="ss-success-text"><strong>Schedule created successfully</strong><span id="ssSuccessDetail"></span></div>
    <button type="button" class="ss-success-close" id="ssSuccessClose" aria-label="Dismiss">&times;</button>
  </div>

  <div class="ss-alerts" id="ssAlerts"></div>

  <div class="maint-banner" id="maintBanner">
    <strong>Maintenance mode is on.</strong>&nbsp;Only administrators can access the system right now.
  </div>

  <div class="ss2-grid">

    <!-- 1 · ACADEMIC PERIOD -->
    <section class="ss2-card">
      <header class="ss2-card-head">
        <span class="ss2-num">1</span>
        <div><h2>Academic period</h2><p>The school year and term these evaluations belong to.</p></div>
      </header>
      <div class="ss2-fields">
        <div class="ss2-field">
          <label for="academicYear">School year</label>
          <input type="text" id="academicYear" value="<?=ss_h($sys['acad_year'] ?? '')?>" placeholder="e.g. 2026-2027" autocomplete="off" />
          <div class="field-note" id="yearNote"></div>
        </div>
        <div class="ss2-field">
          <label for="academicStructure">Level</label>
          <div class="select-wrap"><select id="academicStructure">
          <option value="college" <?=($sys['acad_structure']==='college'?'selected':'')?>>Higher Education</option>
          <option value="jhs" <?=(in_array($sys['acad_structure'], ['jhs','shs'], true)?'selected':'')?>>Basic Education</option>
        </select></div>
        </div>
        <div class="ss2-field">
          <label for="academicTerm">Term</label>
          <div class="select-wrap"><select id="academicTerm"></select></div>
          <div class="ss2-help">The choices depend on the level.</div>
        </div>
      </div>
    </section>

    <!-- 2 · EVALUATION SCHEDULE -->
    <section class="ss2-card">
      <header class="ss2-card-head">
        <span class="ss2-num">2</span>
        <div><h2>Evaluation schedule</h2><p>When people can submit evaluations. Dates are set by creating a new schedule.</p></div>
      </header>
      <button type="button" class="btn btn-primary ss2-cta" id="newScheduleBtn"><span class="ss2-cta-plus" aria-hidden="true">+</span><span>Create new evaluation schedule</span></button>
      <div class="ss2-steps" aria-label="How it works">
        <span><b>1</b> Archive the finished round</span>
        <span><b>2</b> Pick the new dates</span>
        <span><b>3</b> It opens by itself</span>
      </div>
      <div class="toggle-row">
        <div class="toggle-copy">
          <div class="field-label">Show a countdown</div>
          <span class="field-help">Dashboards show how long until evaluations open or close.</span>
        </div>
        <label class="switch">
          <input type="checkbox" id="rule_countdown" <?=!empty($sys['rule_countdown'])?'checked':''?> >
          <span class="track"><span class="thumb"></span></span>
        </label>
      </div>
      <!-- Saved dates. They are changed only through "Create new evaluation schedule". -->
      <div hidden>
        <input type="datetime-local" id="evalOpens" value="<?=ss_h($sys['eval_start'] ?? '')?>" />
        <input type="datetime-local" id="evalCloses" value="<?=ss_h($sys['eval_end'] ?? '')?>" />
      </div>
    </section>

    <!-- 3 · WHO CAN SUBMIT -->
    <section class="ss2-card">
      <header class="ss2-card-head">
        <span class="ss2-num">3</span>
        <div><h2>Who can submit evaluations?</h2><p>Leave this on &ldquo;Follow the schedule&rdquo; unless you need to override it.</p></div>
      </header>
      <div class="control-modes" id="controlModes">
        <label class="control-option" data-mode="schedule">
          <input type="radio" name="controlMode" value="schedule" <?=($sys['control_mode']??'schedule')==='schedule'?'checked':''?> >
          <div>
            <div class="co-title">Follow the schedule <span class="ss2-tag">Recommended</span></div>
            <div class="co-desc">Opens and closes by itself at the scheduled dates.</div>
          </div>
        </label>
        <label class="control-option" data-mode="open">
          <input type="radio" name="controlMode" value="open" <?=($sys['control_mode']??'schedule')==='open'?'checked':''?> >
          <div>
            <div class="co-title">Keep open</div>
            <div class="co-desc">Everyone can submit right now, even outside the schedule.</div>
          </div>
        </label>
        <label class="control-option" data-mode="closed">
          <input type="radio" name="controlMode" value="closed" <?=($sys['control_mode']??'schedule')==='closed'?'checked':''?> >
          <div>
            <div class="co-title">Keep closed</div>
            <div class="co-desc">No one can submit, even during the schedule.</div>
          </div>
        </label>
      </div>
      <div class="toggle-row ss2-auto" id="autoRow">
        <div class="toggle-copy">
          <div class="field-label">Open and close automatically</div>
          <span class="field-help">Turn off to keep evaluations closed until you choose &ldquo;Keep open&rdquo;.</span>
        </div>
        <label class="switch">
          <input type="checkbox" id="autoSchedule" <?=!empty($sys['auto_schedule'])?'checked':''?> >
          <span class="track"><span class="thumb"></span></span>
        </label>
      </div>
    </section>

    <!-- 4 · MAINTENANCE -->
    <section class="ss2-card ss2-danger">
      <header class="ss2-card-head">
        <span class="ss2-num">4</span>
        <div><h2>Maintenance mode</h2><p>Temporarily locks the system for everyone except administrators. Useful while updating.</p></div>
      </header>
      <div class="toggle-row">
        <div class="toggle-copy">
          <div class="field-label">Lock the system for non-administrators</div>
          <span class="field-help">Administrators keep full access. Turn it off when you are done.</span>
        </div>
        <label class="switch">
          <input type="checkbox" id="maintenanceMode" <?=!empty($sys['maintenance'])?'checked':''?> >
          <span class="track"><span class="thumb"></span></span>
        </label>
      </div>
    </section>

  </div>

  <div class="actions">
    <span class="dirty-note" id="dirtyNote">All changes saved</span>
    <button class="btn" id="cancelBtn" disabled>Discard changes</button>
    <button class="btn btn-primary" id="saveBtn" disabled>Save System Settings</button>
  </div>

  </div><!-- /paneCurrent -->

  <div id="panePrevious" hidden>
    <iframe id="previousFrame" class="ss-history-frame" title="Previous schedules" data-src="system_archive.php?embed=previous" frameborder="0"></iframe>
  </div>

  <div id="paneHistory" hidden>
    <iframe id="historyFrame" class="ss-history-frame" title="Schedule history" data-src="system_archive.php?embed=history" frameborder="0"></iframe>
  </div>

</div>

<div class="ns-overlay" id="nsOverlay" hidden>
  <div class="ns-modal" id="nsModal" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="nsHeading">
    <div id="nsBusy" class="ns-busy">Checking the current evaluation…</div>

    <div id="nsGate" hidden>
      <div class="ns-badge warn" aria-hidden="true">!</div>
      <h3 id="nsHeading">Archive the current evaluation first</h3>
      <p class="ns-text" id="nsGateText"></p>
      <ul class="ns-list" id="nsGateList"></ul>
      <p class="ns-note">Archiving saves every submission and result to System Archive and clears them from the live dashboards. You can restore them later.</p>
      <div class="ns-actions">
        <button type="button" class="ns-btn" data-ns-close>Cancel</button>
        <button type="button" class="ns-btn primary" id="nsGoArchive">Archive it now</button>
      </div>
    </div>

    <div id="nsForm" hidden>
      <div class="ns-head">
        <div class="ns-head-row">
          <div class="ns-head-text">
            <h3>Create new evaluation schedule</h3>
            <p class="ns-text">Set the academic period and when the next evaluation opens and closes.</p>
          </div>
          <div class="ns-head-actions">
            <button type="button" class="ns-btn" data-ns-close>Cancel</button>
            <button type="button" class="ns-btn primary" id="nsSubmit">Create schedule</button>
          </div>
        </div>
        <div class="ns-discard" id="nsDiscard" hidden>
          <span>You have unsaved changes. Discard this new schedule?</span>
          <button type="button" class="ns-btn" id="nsKeep">Keep editing</button>
          <button type="button" class="ns-btn danger" id="nsDiscardGo">Discard</button>
        </div>
        <div class="ns-error" id="nsError" hidden></div>
      </div>

      <div class="ns-sec">
        <div class="ns-sec-title">Academic period</div>
        <div class="ns-grid">
          <label>Academic year<input type="text" id="nsYear" placeholder="e.g. 2026-2027" autocomplete="off"></label>
          <label>Academic term<select id="nsTerm"></select></label>
        </div>
      </div>

      <div class="ns-sec">
        <div class="ns-sec-title">Evaluation window</div>
        <div class="ns-grid">
          <label>Evaluation opens<input type="datetime-local" id="nsOpens"></label>
          <label>Evaluation closes<input type="datetime-local" id="nsCloses"></label>
        </div>
        <div class="ns-quick-label">Opens</div>
        <div class="ns-quick">
          <button type="button" class="ns-q" data-act="now">Now</button>
          <button type="button" class="ns-q" data-act="tomorrow">Tomorrow, 8:00 AM</button>
        </div>
        <div class="ns-quick-label">Stays open for</div>
        <div class="ns-quick">
          <button type="button" class="ns-q" data-days="7">1 week</button>
          <button type="button" class="ns-q" data-days="14">2 weeks</button>
          <button type="button" class="ns-q" data-days="30">1 month</button>
        </div>
        <div class="ns-preview" id="nsPreview"></div>
      </div>

    </div>
  </div>
</div>

<div class="toast" id="toast"><span class="dot"></span><span id="toastText">Saved</span></div>

<script>
  const TERM_OPTIONS = {
    college: ['1st Semester', '2nd Semester', 'Summer'],
    jhs: ['School Year'],
    shs: ['School Year']
  };
  const STRUCTURE_LABEL = {
    college: 'Higher Education',
    jhs: 'Basic Education',
    shs: 'Basic Education'
  };

  const els = {
    structure: document.getElementById('academicStructure'),
    term: document.getElementById('academicTerm'),
    year: document.getElementById('academicYear'),
    opens: document.getElementById('evalOpens'),
    closes: document.getElementById('evalCloses'),
    auto: document.getElementById('autoSchedule'),
    maintenance: document.getElementById('maintenanceMode'),
    maintBanner: document.getElementById('maintBanner'),
    statAcademic: document.getElementById('statAcademic'),
    statTerm: document.getElementById('statTerm'),
    statStatus: document.getElementById('statStatus'),
    statMode: document.getElementById('statMode'),
    countdown: document.getElementById('rule_countdown'),
    controlModes: document.getElementById('controlModes'),
    toast: document.getElementById('toast'),
    toastText: document.getElementById('toastText'),
  };

  const MODE_LABEL = { schedule: 'Follow the schedule', open: 'Keep open', closed: 'Keep closed' };
  // Plain-language names for the status codes used internally.
  const FRIENDLY_STATUS = {
    'OPEN': 'Open now', 'UPCOMING': 'Not open yet', 'FORCED OPEN': 'Open (manual)',
    'FORCED CLOSED': 'Closed (manual)', 'NOT CONFIGURED': 'No schedule yet',
    'INVALID SCHEDULE': 'Check the dates', 'MAINTENANCE': 'Maintenance mode',
    'CLOSED \u00B7 MANUAL': 'Closed', 'CLOSED \u00B7 ENDED': 'Closed'
  };

  function populateTerms(selected) {
    const structure = els.structure.value;
    const options = TERM_OPTIONS[structure];
    els.term.innerHTML = '';
    options.forEach(opt => {
      const o = document.createElement('option');
      o.value = opt; o.textContent = opt;
      els.term.appendChild(o);
    });
    if (selected && options.includes(selected)) {
      els.term.value = selected;
    }
    els.term.disabled = options.length === 1;
  }

  const SCHEDULE_TIMEZONE = 'Asia/Manila';
  // datetime-local has no timezone information. Interpret every configured value
  // explicitly as Manila time so an administrator in another timezone sees the
  // same schedule status as the server.
  function parseManilaLocal(value) {
    if (!value) return NaN;
    const m = String(value).match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/);
    if (!m) return NaN;
    const y = Number(m[1]), mo = Number(m[2]), d = Number(m[3]);
    const h = Number(m[4]), mi = Number(m[5]);
    // Asia/Manila is UTC+08:00 and has no DST transition.
    return Date.UTC(y, mo - 1, d, h - 8, mi);
  }

  function formatDateTime(value) {
    const ts = parseManilaLocal(value);
    if (!Number.isFinite(ts)) return '—';
    return new Intl.DateTimeFormat('en-US', {
      timeZone: SCHEDULE_TIMEZONE,
      month: 'short',
      day: 'numeric',
      year: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
      hour12: true
    }).format(new Date(ts));
  }

  function getControlMode() {
    const checked = els.controlModes.querySelector('input[name="controlMode"]:checked');
    return checked ? checked.value : 'schedule';
  }

  function refreshControlOptionStyles() {
    els.controlModes.querySelectorAll('.control-option').forEach(opt => {
      const input = opt.querySelector('input');
      opt.classList.toggle('selected', input.checked);
    });
  }

  function computeStatus() {
    if (els.maintenance.checked) return { cls: '', color: 'text-muted', label: 'MAINTENANCE' };
    const mode = getControlMode();
    if (mode === 'open') return { color: 'amber', label: 'FORCED OPEN' };
    if (mode === 'closed') return { color: 'amber', label: 'FORCED CLOSED' };
    if (!els.auto.checked) return { color: '', label: 'CLOSED · MANUAL' };
    const now = Date.now();
    const opens = parseManilaLocal(els.opens.value);
    const closes = parseManilaLocal(els.closes.value);
    if (!Number.isFinite(opens) || !Number.isFinite(closes)) return { color: '', label: 'NOT CONFIGURED' };
    if (closes <= opens) return { color: 'red', label: 'INVALID SCHEDULE' };
    if (now < opens) return { color: '', label: 'UPCOMING' };
    if (now >= closes) return { color: 'red', label: 'CLOSED · ENDED' };
    return { color: 'green', label: 'OPEN' };
  }

  function render() {
    const structureLabel = STRUCTURE_LABEL[els.structure.value];
    els.statAcademic.textContent = `${els.year.value} · ${structureLabel}`;
    els.statTerm.textContent = els.term.value;
    els.statMode.textContent = MODE_LABEL[getControlMode()];

    els.maintBanner.classList.toggle('show', els.maintenance.checked);
    refreshControlOptionStyles();

    const status = computeStatus();
    els.statStatus.textContent = FRIENDLY_STATUS[status.label] || status.label;
    els.statStatus.className = 'stat-value' + (status.color ? ' ' + status.color : '');
    renderInsights(status);
  }

  els.structure.addEventListener('change', () => { populateTerms(); render(); });
  els.term.addEventListener('change', render);
  els.year.addEventListener('input', render);
  els.opens.addEventListener('input', render);
  els.closes.addEventListener('input', render);
  els.auto.addEventListener('change', render);
  els.maintenance.addEventListener('change', render);
  els.controlModes.addEventListener('change', render);

  // ── UNSAVED CHANGES TRACKING ───────────────────────────────
  // Anything the user actually edits after the form has loaded flips
  // this flag. Reset it once a save succeeds. Exposed on window so the
  // parent Settings shell can ask "is it safe to switch tabs?" before
  // it hides this iframe, and beforeunload below covers a refresh,
  // browser-back, or closing the tab outright.
  let isDirty = false;
  function postDirty(d){
    try { parent.postMessage({type:'system-settings-dirty', dirty:d}, '*'); } catch(e){}
  }
  // markDirty() is wired to every field. It re-compares the form with the values it
  // had when it was loaded/last saved, so changing something back to its original
  // value correctly clears the "unsaved" state.
  function markDirty(){
    const d = getChanges().length > 0;
    if (d === isDirty) return;
    isDirty = d;
    postDirty(d);
    updateActions();
  }
  function clearDirty(){
    takeBaseline();
    isDirty = false;
    postDirty(false);
    updateActions();
  }
  [els.structure, els.term, els.opens, els.closes, els.auto, els.countdown, els.maintenance, els.controlModes]
    .forEach(el => el.addEventListener('change', markDirty));
  [els.year, els.opens, els.closes].forEach(el => el.addEventListener('input', markDirty));
  window.isSystemSettingsDirty = () => isDirty;
  window.getSystemSettingsChanges = () => getChanges();
  window.discardSystemSettingsChanges = () => { isDirty = false; postDirty(false); updateActions(); };
  window.addEventListener('beforeunload', (e) => {
    if (!isDirty) return;
    e.preventDefault();
    e.returnValue = '';
  });

  // ── CHANGE SNAPSHOT (what exactly is unsaved) ──────────────
  const onOff = v => v ? 'On' : 'Off';
  const FIELD_DEFS = [
    { label: 'School year',         get: () => els.year.value.trim(), fmt: v => v || 'Not set' },
    { label: 'Level',               get: () => els.structure.value,   fmt: v => STRUCTURE_LABEL[v] || v },
    { label: 'Term',                get: () => els.term.value },
    { label: 'Evaluation Opens',   get: () => els.opens.value,  fmt: v => v ? formatDateTime(v) : 'Not set' },
    { label: 'Evaluation Closes',  get: () => els.closes.value, fmt: v => v ? formatDateTime(v) : 'Not set' },
    { label: 'Open and close automatically', get: () => els.auto.checked, fmt: onOff },
    { label: 'Show a countdown',    get: () => els.countdown.checked, fmt: onOff },
    { label: 'Who can submit',      get: () => getControlMode(), fmt: v => MODE_LABEL[v] || v },
    { label: 'Maintenance mode',    get: () => els.maintenance.checked, fmt: onOff },
  ];
  let baseline = null;
  function takeBaseline(){ baseline = FIELD_DEFS.map(d => d.get()); }
  function getChanges(){
    if (!baseline) return [];
    const out = [];
    FIELD_DEFS.forEach((d, i) => {
      const now = d.get();
      if (now !== baseline[i]) {
        const f = d.fmt || (v => String(v));
        out.push(d.label + ': ' + f(baseline[i]) + ' \u2192 ' + f(now));
      }
    });
    return out;
  }

  // ── FRIENDLIER UI HELPERS ─────────────────────────────────
  function updateActions(){
    const sv=document.getElementById('saveBtn'), cb=document.getElementById('cancelBtn'), n=document.getElementById('dirtyNote');
    if (sv && !sv.dataset.busy) sv.disabled = !isDirty;
    if (cb) cb.disabled = !isDirty;
    if (n) { n.textContent = isDirty ? 'You have unsaved changes' : 'All changes saved'; n.classList.toggle('dirty', isDirty); }
  }
  function relTime(ms){
    ms=Math.abs(ms); const m=Math.floor(ms/60000), h=Math.floor(m/60), d=Math.floor(h/24);
    if (d>=1) return d+' day'+(d===1?'':'s')+(h%24?' '+(h%24)+' hr':'');
    if (h>=1) return h+' hr'+(m%60?' '+(m%60)+' min':'');
    return Math.max(m,1)+' min';
  }
  function setMode(m){
    const r=els.controlModes.querySelector('input[value="'+m+'"]');
    if (!r) return; r.checked=true; r.dispatchEvent(new Event('change',{bubbles:true}));
  }
  function renderInsights(status){
    const now=Date.now(), opens=parseManilaLocal(els.opens.value), closes=parseManilaLocal(els.closes.value);
    const valid=Number.isFinite(opens)&&Number.isFinite(closes)&&closes>opens;
    const ended=valid&&now>=closes, mode=getControlMode();

    // Academic year format hint (soft - does not block saving)
    const yn=document.getElementById('yearNote'), y=els.year.value.trim(), ym=y.match(/^(\d{4})-(\d{4})$/);
    let ymsg='';
    if (!y) ymsg='Enter the academic year, for example 2026-2027.';
    else if (!ym) ymsg='Use the format 2026-2027 so archives and reports group correctly.';
    else if (Number(ym[2])!==Number(ym[1])+1) ymsg='The second year should follow the first, for example 2026-2027.';
    if (yn) { yn.textContent=ymsg; yn.classList.toggle('warn',!!ymsg); }
    els.year.classList.toggle('has-warn',!!ymsg);

    // Plain-language explanation of the current status
    let text='';
    switch(status.label){
      case 'MAINTENANCE': text='Maintenance mode is on. Only administrators can use the system right now.'; break;
      case 'FORCED OPEN': text='Everyone can submit because \u201CKeep open\u201D is on'+(ended?'. The schedule has already ended, so this is the only reason it is still open.':', regardless of the schedule.'); break;
      case 'FORCED CLOSED': text='No one can submit because \u201CKeep closed\u201D is on, regardless of the schedule.'; break;
      case 'CLOSED \u00B7 MANUAL': text='Automatic opening is off, so evaluations stay closed until you choose \u201CKeep open\u201D.'; break;
      case 'NOT CONFIGURED': text='No evaluation window has been set yet. Use \u201CCreate new evaluation schedule\u201D to set one.'; break;
      case 'INVALID SCHEDULE': text='The closing time is before the opening time. Create the schedule again with valid dates.'; break;
      case 'UPCOMING': text='Not open yet. Evaluations will open automatically in '+relTime(opens-now)+'.'; break;
      case 'CLOSED \u00B7 ENDED': text='The evaluation period has ended. Create a new evaluation schedule to start another round.'; break;
      case 'OPEN': text='Open now. Everyone can submit evaluations until it closes in '+relTime(closes-now)+'.'; break;
    }
    els.statExplain = els.statExplain || document.getElementById('statExplain');
    els.statExplain.textContent=text;
    const tlO=document.getElementById('tlOpens'), tlC=document.getElementById('tlCloses'), tlF=document.getElementById('tlFill'), tlN=document.getElementById('tlNote');
    if (tlO) tlO.textContent = els.opens.value ? formatDateTime(els.opens.value) : 'Not set';
    if (tlC) tlC.textContent = els.closes.value ? formatDateTime(els.closes.value) : 'Not set';
    if (tlF) tlF.style.width = (valid ? Math.max(0, Math.min(100, (now-opens)/(closes-opens)*100)) : 0) + '%';
    if (tlN) tlN.textContent = !valid ? 'No evaluation window is set yet.'
      : (now < opens ? 'Opens in ' + relTime(opens-now) + ' \u00B7 stays open for ' + relTime(closes-opens)
      : (now >= closes ? 'Ended ' + relTime(now-closes) + ' ago' : relTime(closes-now) + ' left of ' + relTime(closes-opens)));
    // The automatic-opening switch only matters while following the schedule.
    const autoRow=document.getElementById('autoRow'); if (autoRow) autoRow.hidden = mode!=='schedule';
    const cta=document.getElementById('newScheduleBtn'); if (cta) cta.classList.toggle('attn', ended || !valid);
    const card=els.statStatus.closest('.stat-card');
    if (card) { card.classList.remove('tone-green','tone-amber','tone-red'); if (status.color) card.classList.add('tone-'+status.color); }

    // Heads-up alerts that offer a one-click fix
    const alerts=[];
    if (!els.maintenance.checked && mode==='open' && (ended||!valid))
      alerts.push({tone:'amber',text:'\u201CKeep open\u201D is on and the schedule '+(ended?'has already ended':'is not set')+'. Evaluations stay open for everyone until you switch back.',action:'Switch to \u201CFollow the schedule\u201D',fn:()=>setMode('schedule')});
    if (!els.maintenance.checked && mode==='schedule' && els.auto.checked && ended)
      alerts.push({tone:'red',text:'The evaluation window ended '+relTime(now-closes)+' ago.',action:'Create new schedule',fn:()=>{const nb=document.getElementById('newScheduleBtn'); if(nb) nb.click();}});
    const box=document.getElementById('ssAlerts');
    if (box) {
      box.textContent='';
      alerts.forEach(a=>{
        const d=document.createElement('div'); d.className='ss-alert '+a.tone;
        const sp=document.createElement('span'); sp.textContent=a.text;
        const b=document.createElement('button'); b.type='button'; b.textContent=a.action; b.addEventListener('click',a.fn);
        d.appendChild(sp); d.appendChild(b); box.appendChild(d);
      });
    }
  }
  populateTerms(<?=json_encode($sys['acad_term'] ?? '1st Semester')?>);
  render();
  takeBaseline();
  updateActions();
  try { parent.postMessage({type:'system-settings-dirty', dirty:false}, '*'); } catch(e){}
  // Back on the page after creating a schedule: confirm clearly, then tidy the address.
  try {
    const qs = new URLSearchParams(location.search);
    if (qs.has('created')) {
      const detail = qs.get('created') || '';
      history.replaceState(null, '', location.pathname);
      const bar = document.getElementById('ssSuccess');
      if (bar) {
        document.getElementById('ssSuccessDetail').textContent = detail;
        bar.hidden = false;
        window.scrollTo(0, 0);
        const hide = () => { bar.hidden = true; };
        document.getElementById('ssSuccessClose').addEventListener('click', hide);
        setTimeout(hide, 12000);
      }
      const card = document.getElementById('heroCard');
      if (card) { card.classList.add('sched-flash'); setTimeout(() => card.classList.remove('sched-flash'), 2600); }
    }
  } catch(e){}

  // ── CREATE NEW EVALUATION SCHEDULE (requires the current evaluation to be archived) ──
  (function(){
    const overlay=document.getElementById('nsOverlay'), btn=document.getElementById('newScheduleBtn');
    if(!overlay||!btn) return;
    // Render the dialog in the top-most window so the dim layer covers the whole page
    // (sidebar, header and tabs included) instead of only this embedded frame.
    let host=document;
    try{ if(window.top&&window.top!==window&&window.top.document&&window.top.document.body) host=window.top.document; }catch(e){ host=document; }
    if(host!==document){
      const prev=host.getElementById('nsOverlay'); if(prev&&prev!==overlay) prev.remove();
      const prevCss=host.getElementById('ns-styles-top'); if(prevCss) prevCss.remove();
      const st=host.createElement('style'); st.id='ns-styles-top';
      st.textContent=document.getElementById('ns-styles').textContent;
      host.head.appendChild(st);
      host.body.appendChild(host.adoptNode(overlay));
      // Clean up if this frame is reloaded or closed while the dialog is mounted up there.
      window.addEventListener('pagehide',()=>{ try{ overlay.remove(); st.remove(); }catch(e){} });
    }
    const $=id=>host.getElementById(id);
    function show(which){ ['nsBusy','nsGate','nsForm'].forEach(id=>{ $(id).hidden=(id!==which); }); }
    function open(){ overlay.hidden=false; const m=$('nsModal'); if(m) m.focus({preventScroll:true}); }
    let formDirty=false;
    function close(){ overlay.hidden=true; const d=$('nsDiscard'); if(d) d.hidden=true; }
    // Clicking the dim area or pressing Esc must not throw away what was typed:
    // ask first when the form has been changed.
    function requestClose(){
      if(!$('nsForm').hidden && formDirty){
        $('nsDiscard').hidden=false;
        const m=$('nsModal'); m.classList.remove('ns-shake'); void m.offsetWidth; m.classList.add('ns-shake');
        return;
      }
      close();
    }
    overlay.addEventListener('click',e=>{ if(e.target===overlay) requestClose(); });
    $('nsKeep').addEventListener('click',()=>{ $('nsDiscard').hidden=true; });
    $('nsDiscardGo').addEventListener('click',()=>{ formDirty=false; close(); });
    overlay.querySelectorAll('[data-ns-close]').forEach(b=>b.addEventListener('click',close));
    const onKey=e=>{ if(e.key==='Escape'&&!overlay.hidden) requestClose(); };
    document.addEventListener('keydown',onKey);
    if(host!==document) host.addEventListener('keydown',onKey);

    let gatePeriods=[];
    function fillGate(g){
      gatePeriods=g.periods||[];
      const list=$('nsGateList'); list.textContent='';
      gatePeriods.forEach(p=>{
        const li=document.createElement('li');
        const b=document.createElement('b'); b.textContent=p.label;
        li.appendChild(b);
        li.appendChild(document.createTextNode(' — '+p.live.toLocaleString()+' live evaluation'+(p.live===1?'':'s')+(p.active?' (active)':'')));
        list.appendChild(li);
      });
      $('nsGateText').textContent = g.ok===false && !gatePeriods.length
        ? (g.error||'The current evaluation could not be checked. Please try again.')
        : 'A new schedule cannot be created while the current evaluation still has live submissions. Archive it first so the new round starts clean:';
      $('nsGoArchive').hidden = !gatePeriods.length;
    }
    $('nsGoArchive').addEventListener('click',()=>{
      try{ window.ssShowPane('previous', gatePeriods.map(p=>p.id)); }catch(e){}
      close();
    });

    function nextPeriod(){
      const opts=TERM_OPTIONS[els.structure.value]||[], m=els.year.value.trim().match(/^(\d{4})-(\d{4})$/);
      const a=m?Number(m[1]):new Date().getFullYear(), i=opts.indexOf(els.term.value);
      if(opts.length>1&&i>=0&&i<opts.length-1) return {year:a+'-'+(a+1),term:opts[i+1]};
      return {year:(a+1)+'-'+(a+2),term:opts[0]||els.term.value};
    }
    function prepareForm(){
      const opts=TERM_OPTIONS[els.structure.value]||[], sel=$('nsTerm'), nx=nextPeriod();
      sel.textContent='';
      opts.forEach(o=>{ const op=document.createElement('option'); op.value=o; op.textContent=o; sel.appendChild(op); });
      sel.value=nx.term; sel.disabled=opts.length===1;
      $('nsYear').value=nx.year; $('nsOpens').value=''; $('nsCloses').value=''; $('nsError').hidden=true;
      formDirty=false; $('nsDiscard').hidden=true;
      update();
    }
    function ms(ts){ return new Date(ts+8*3600000).toISOString().slice(0,16); }
    function check(){
      const y=$('nsYear').value.trim(), ym=y.match(/^(\d{4})-(\d{4})$/);
      const oV=$('nsOpens').value, cV=$('nsCloses').value;
      if(!ym||Number(ym[2])!==Number(ym[1])+1) return {ok:false,tone:'bad',title:'Check the academic year',msg:'Enter it like 2026-2027.'};
      if(!oV||!cV) return {ok:false,tone:'muted',title:'Set the evaluation window',msg:'Pick the opening and closing time, or use the quick buttons above.'};
      const o=parseManilaLocal(oV), c=parseManilaLocal(cV);
      if(!Number.isFinite(o)||!Number.isFinite(c)) return {ok:false,tone:'bad',title:'Check the dates',msg:'One of the dates is not valid.'};
      if(c<=o) return {ok:false,tone:'bad',title:'Check the dates',msg:'The closing time must be after the opening time.'};
      if(c<=Date.now()) return {ok:false,tone:'bad',title:'Check the dates',msg:'The closing time must be in the future.'};
      const when=o<=Date.now()?'Opens immediately':'Opens in '+relTime(o-Date.now());
      return {ok:true,tone:'good',title:formatDateTime(oV)+'  \u2192  '+formatDateTime(cV),msg:when+' \u00B7 stays open for '+relTime(c-o)+'.'};
    }
    function update(){
      const r=check(), pv=$('nsPreview');
      $('nsError').hidden=true;
      $('nsCloses').min=$('nsOpens').value||'';
      pv.className='ns-preview '+r.tone;
      pv.textContent='';
      const b=document.createElement('b'); b.textContent=r.title;
      pv.appendChild(b); pv.appendChild(document.createTextNode(r.msg));
      $('nsSubmit').disabled=!r.ok;
    }
    overlay.querySelectorAll('.ns-q').forEach(b=>b.addEventListener('click',()=>{
      formDirty=true;
      const now=Date.now();
      if(b.dataset.act==='now'){ $('nsOpens').value=ms(now); update(); return; }
      if(b.dataset.act==='tomorrow'){
        const t=new Date(now+8*3600000); t.setUTCDate(t.getUTCDate()+1);
        $('nsOpens').value=t.toISOString().slice(0,10)+'T08:00'; update(); return;
      }
      let base=parseManilaLocal($('nsOpens').value);
      if(!Number.isFinite(base)){ base=now; $('nsOpens').value=ms(now); }
      $('nsCloses').value=ms(base+Number(b.dataset.days)*86400000);
      update();
    }));
    ['nsYear','nsTerm','nsOpens','nsCloses'].forEach(id=>{ const el=$(id); const h=()=>{ formDirty=true; update(); }; el.addEventListener('input',h); el.addEventListener('change',h); });

    btn.addEventListener('click',()=>{
      if(isDirty){ showToast('Save or discard your current changes first.'); return; }
      show('nsBusy'); open();
      fetch('system_settings.php?action=schedule_gate',{credentials:'same-origin',cache:'no-store'})
        .then(r=>r.json())
        .then(g=>{
          if(g&&g.blocked){ fillGate(g); show('nsGate'); }
          else if(g&&g.ok){ prepareForm(); show('nsForm'); $('nsYear').focus(); }
          else { close(); showToast('Unable to check the current evaluation'); }
        })
        .catch(()=>{ close(); showToast('Unable to check the current evaluation'); });
    });

    $('nsSubmit').addEventListener('click',()=>{
      const err=$('nsError'), sb=$('nsSubmit');
      const fail=m=>{ err.textContent=m; err.hidden=false; };
      err.hidden=true;
      const y=$('nsYear').value.trim(), ym=y.match(/^(\d{4})-(\d{4})$/);
      if(!ym||Number(ym[2])!==Number(ym[1])+1) return fail('Enter the academic year like 2026-2027.');
      const o=parseManilaLocal($('nsOpens').value), c=parseManilaLocal($('nsCloses').value);
      if(!Number.isFinite(o)||!Number.isFinite(c)) return fail('Set both the opening and closing time.');
      if(c<=o) return fail('The closing time must be after the opening time.');
      if(c<=Date.now()) return fail('The closing time must be in the future.');
      const fd=new FormData();
      fd.append('action','create_new_schedule'); fd.append('acad_year',y); fd.append('acad_term',$('nsTerm').value);
      fd.append('eval_start',$('nsOpens').value); fd.append('eval_end',$('nsCloses').value);
      sb.disabled=true; sb.textContent='Creating…';
      fetch('system_settings.php',{method:'POST',body:fd,credentials:'same-origin'})
        .then(async r=>({status:r.status,d:await r.json().catch(()=>null)}))
        .then(({status,d})=>{
          if(status===409&&d&&d.code==='archive_required'){ fillGate(d); show('nsGate'); return; }
          if(d&&d.ok){ close(); location.replace('system_settings.php?created='+encodeURIComponent(d.message||'The new schedule is now active.')); return; }
          fail((d&&d.message)||'Could not create the schedule. Please try again.');
        })
        .catch(()=>fail('Could not reach the server. Please try again.'))
        .finally(()=>{ sb.textContent='Create schedule'; update(); });
    });
  })();

  function showToast(text) {
    els.toastText.textContent = text;
    els.toast.classList.add('show');
    setTimeout(() => els.toast.classList.remove('show'), 2200);
  }
  function saveSystemSettings(){
    const fd = new FormData();
    fd.append('action','save_system_settings');
    fd.append('acad_year', els.year.value.trim());
    fd.append('acad_structure', els.structure.value);
    fd.append('acad_term', els.term.value);
    const openValue = els.opens.value.trim();
    const closeValue = els.closes.value.trim();
    if ((openValue && !closeValue) || (!openValue && closeValue)) {
      showToast('Please set both opening and closing times.');
      return Promise.resolve(false);
    }
    if (openValue && closeValue && parseManilaLocal(closeValue) <= parseManilaLocal(openValue)) {
      showToast('Closing time must be after opening time (Asia/Manila).');
      return Promise.resolve(false);
    }
    fd.append('eval_start', openValue);
    fd.append('eval_end', closeValue);
    fd.append('control_mode', getControlMode());
    if (els.auto.checked) fd.append('auto_schedule','1');
    if (els.maintenance.checked) fd.append('maintenance','1');
    if (els.countdown.checked) fd.append('rule_countdown','1');
    const sb = document.getElementById('saveBtn');
    if (sb && sb.dataset.busy) return Promise.resolve(false);
    if (sb) { sb.dataset.busy = '1'; sb.disabled = true; sb.textContent = 'Saving\u2026'; }
    const done = () => { if (sb) { delete sb.dataset.busy; sb.textContent = 'Save System Settings'; updateActions(); } };
    return fetch('system_settings.php',{method:'POST',body:fd,credentials:'same-origin'})
      .then(r=>r.ok?r.text():Promise.reject(new Error('save failed')))
      .then(()=>{ clearDirty(); done(); showToast('System settings saved'); return true; })
      .catch(()=>{ done(); showToast('Unable to save system settings'); return false; });
  }
  document.getElementById('saveBtn').addEventListener('click', saveSystemSettings);
  document.getElementById('saveBtnTop').addEventListener('click', saveSystemSettings);
  document.getElementById('cancelBtn').addEventListener('click', () => location.reload());

  setInterval(render, 60000);
</script>
<script>
(function(){
  var panes={
    current:{pane:document.getElementById('paneCurrent'),btn:document.getElementById('subCurrent')},
    previous:{pane:document.getElementById('panePrevious'),btn:document.getElementById('subPrevious'),frame:document.getElementById('previousFrame')},
    history:{pane:document.getElementById('paneHistory'),btn:document.getElementById('subHistory'),frame:document.getElementById('historyFrame')}
  };
  window.ssShowPane=function(which, preselectIds){
    if(!panes[which]) which='current';
    Object.keys(panes).forEach(function(k){
      var on=k===which;
      panes[k].pane.hidden=!on;
      panes[k].btn.classList.toggle('active',on);
      panes[k].btn.setAttribute('aria-selected',String(on));
    });
    var f=panes[which].frame;
    if(f){   // reload each time so the lists always reflect the latest archive / restore
      var url=f.getAttribute('data-src');
      if(which==='previous' && preselectIds && preselectIds.length) url+='&preselect='+encodeURIComponent(preselectIds.join(','));
      f.src=url;
    }
  };
  Object.keys(panes).forEach(function(k){ panes[k].btn.addEventListener('click',function(){ window.ssShowPane(k); }); });
  // Opened straight to a list (e.g. old settings.php?tab=archive links)
  var m=location.search.match(/[?&]view=(previous|history)\b/);
  if(m) window.ssShowPane(m[1]);
})();
</script>
</body>
</html>