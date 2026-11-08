<?php
// session_bootstrap.php — include this BEFORE session_start() everywhere
session_set_cookie_params([
    'lifetime' => 0,        // session cookie, dies when browser closes
    'path'     => '/',      // available across the whole site, not just /admin/
    'domain'   => '',       // let the browser infer it — avoids localhost vs IP mismatches
    'secure'   => false,    // set true only if you're on https
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
require_once 'db.php';

// ── AUTH GUARD ────────────────────────────────────────────
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'dean') {
    header("Location: dean_login.php");
    exit;
}

@$mysqli->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS academic_level VARCHAR(20) NULL");

function safe_scalar(mysqli $mysqli, string $sql, string $types = '', array $params = []) {
    try {
        $stmt = @$mysqli->prepare($sql);
        if (!$stmt) return null;
        if ($types !== '') { $stmt->bind_param($types, ...$params); }
        if (!@$stmt->execute()) { $stmt->close(); return null; }
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row ? reset($row) : null;
    } catch (mysqli_sql_exception $e) {
        return null;
    }
}
function safe_rows(mysqli $mysqli, string $sql, string $types = '', array $params = []): array {
    try {
        $stmt = @$mysqli->prepare($sql);
        if (!$stmt) return [];
        if ($types !== '') { $stmt->bind_param($types, ...$params); }
        if (!@$stmt->execute()) { $stmt->close(); return []; }
        $res = $stmt->get_result();
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $rows;
    } catch (mysqli_sql_exception $e) {
        return [];
    }
}

// ── DEAN PROFILE (for sidebar) ─────────────────────────────
$stmt = $mysqli->prepare("SELECT full_name, designation, photo FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc();
$stmt->close();
$photo_src = !empty($me['photo']) ? '../image/' . $me['photo'] : '../image/pbi_logo';

// ── ACTIVE EVALUATION PERIOD ──────────────────────────────
$period = null;
$pr = $mysqli->query("SELECT * FROM evaluation_periods WHERE is_active=1 LIMIT 1");
if ($pr) $period = $pr->fetch_assoc();
$period_id_int = $period ? (int)$period['id'] : 0;

$daysRemaining = null;
if ($period) {
    $endRaw = $period['end_date'] ?? $period['period_end'] ?? $period['deadline'] ?? null;
    if ($endRaw) {
        $diff = (strtotime($endRaw) - strtotime(date('Y-m-d')));
        $daysRemaining = (int)ceil($diff / 86400);
    }
}

// ── FILTER INPUT (GET, applied via the Apply button) ─────────────────
$department = trim($_GET['department'] ?? '');
$program    = trim($_GET['program'] ?? '');
$yearLevel  = trim($_GET['year_level'] ?? '');

// Fixed list — college year levels don't vary by department/program, so
// this isn't pulled from the database. users.year_level is confirmed to
// exist (varchar(30)) but the actual stored string values are still
// unconfirmed — these snake_case guesses match the style of
// education_level ('junior_high'/'senior_high'/'both') elsewhere in this
// schema. Run: SELECT DISTINCT year_level FROM users WHERE role='student'
// AND education_level='college' — send me the results and I'll correct
// these to match exactly.
$yearLevelOptions = [
    '1st_year' => '1st Year',
    '2nd_year' => '2nd Year',
    '3rd_year' => '3rd Year',
    '4th_year' => '4th Year',
];

// Dropdown option lists — pulled unfiltered so the dropdowns stay stable
// regardless of what's currently applied.
$deptOptions = array_column(safe_rows($mysqli, "
    SELECT DISTINCT department FROM users
    WHERE role='teacher' AND is_active=1 AND account_status='approved'
      AND academic_level='college' AND department IS NOT NULL AND department <> ''
    ORDER BY department
"), 'department');

$progOptions = array_column(safe_rows($mysqli, "
    SELECT DISTINCT course FROM users
    WHERE is_active=1 AND account_status='approved' AND academic_level IS NOT NULL
      AND course IS NOT NULL AND course <> '' AND role='teacher' AND academic_level='college'
    UNION
    SELECT DISTINCT course FROM users
    WHERE is_active=1 AND account_status='approved'
      AND course IS NOT NULL AND course <> '' AND role='student' AND education_level='college'
    ORDER BY course
"), 'course');
sort($progOptions);

// ── FACULTY IN SCOPE (filtered by Department + Program) ────────────────
$facSql = "SELECT id, full_name, department, course FROM users
           WHERE role='teacher' AND is_active=1 AND account_status='approved' AND academic_level='college'";
$facTypes = '';
$facParams = [];
if ($department !== '') { $facSql .= " AND department=?"; $facTypes .= 's'; $facParams[] = $department; }
if ($program !== '')    { $facSql .= " AND course=?";     $facTypes .= 's'; $facParams[] = $program; }
$facSql .= " ORDER BY full_name";
$facultyRows = safe_rows($mysqli, $facSql, $facTypes, $facParams);

$tracker = [];
$fullyEvaluated = 0;
foreach ($facultyRows as $row) {
    $fid = (int)$row['id'];

    $received = $period ? (int)(safe_scalar($mysqli, "
        SELECT COUNT(DISTINCT evaluator_id) c FROM evaluation_tracker
        WHERE eval_type='student' AND eval_bucket='Faculty' AND level='college'
          AND status IN ('submitted','approved')
          AND period_id=? AND target_user_id=?
    ", "ii", [$period_id_int, $fid]) ?? 0) : 0;

    $avgRating = $period ? safe_scalar($mysqli, "
        SELECT AVG(score) v FROM evaluation_tracker
        WHERE eval_type='student' AND eval_bucket='Faculty' AND level='college'
          AND status IN ('submitted','approved')
          AND target_user_id=? AND period_id=?
    ", "ii", [$fid, $period_id_int]) : null;

    $status = $received > 0 ? 'completed' : 'pending';
    if ($status === 'completed') $fullyEvaluated++;

    $tracker[] = [
        'id'         => $fid,
        'name'       => $row['full_name'],
        'department' => $row['department'] ?: '—',
        'course'     => $row['course'] ?: '—',
        'received'   => $received,
        'avg'        => $avgRating !== null ? round((float)$avgRating, 2) : null,
        'status'     => $status,
    ];
}
$peopleInView = count($tracker);

// ── STUDENTS IN SCOPE (filtered by Program + Year Level — see note #2) ──
$stuSql = "SELECT id, full_name, course, year_level FROM users
           WHERE role='student' AND is_active=1 AND account_status='approved' AND education_level='college'";
$stuTypes = '';
$stuParams = [];
if ($program !== '')    { $stuSql .= " AND course=?";      $stuTypes .= 's'; $stuParams[] = $program; }
if ($yearLevel !== '')  { $stuSql .= " AND year_level=?";  $stuTypes .= 's'; $stuParams[] = $yearLevel; }
$stuSql .= " ORDER BY full_name";
$studentRows = safe_rows($mysqli, $stuSql, $stuTypes, $stuParams);
$studentsInScope = count($studentRows);

$notSubmitted = [];
if ($period) {
    foreach ($studentRows as $s) {
        $sid = (int)$s['id'];
        $hasSubmitted = (int)(safe_scalar($mysqli, "
            SELECT COUNT(*) c FROM evaluation_tracker
            WHERE eval_type='student' AND eval_bucket='Faculty' AND level='college'
              AND status IN ('submitted','approved')
              AND period_id=? AND evaluator_id=?
        ", "ii", [$period_id_int, $sid]) ?? 0);
        if ($hasSubmitted === 0) {
            $notSubmitted[] = ['id' => $sid, 'name' => $s['full_name'], 'course' => $s['course'] ?: '—'];
        }
    }
} else {
    // No active period — nobody can have submitted anything yet.
    foreach ($studentRows as $s) {
        $notSubmitted[] = ['id' => (int)$s['id'], 'name' => $s['full_name'], 'course' => $s['course'] ?: '—'];
    }
}
$notSubmittedCount = count($notSubmitted);

$scopeParts = [];
if ($yearLevel !== '') $scopeParts[] = $yearLevelOptions[$yearLevel] ?? $yearLevel;
if ($program !== '')   $scopeParts[] = $program;
if ($department !== '' && $program === '') $scopeParts[] = $department;
$scopeLabel = $scopeParts ? implode(' — ', $scopeParts) : 'College Division';

$mysqli->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>PBI — Evaluation Tracker</title>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<style>
:root{--dark:#0A192F;--mid:#172A45;--inner:#0F1F3D;--violet:#7C5FD9;--violet-h:#9C85F0;--violet-dark:#5F45B8;--light:#E0E6F0;--muted:#A0B3C6;--radius:10px;--shadow:0 8px 32px rgba(0,0,0,0.45);--danger:#f05454;--good:#10B981;}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{min-height:100vh;background:var(--dark);font-family:'DM Sans',sans-serif;color:var(--light);display:flex;}

.sidebar{width:250px;flex-shrink:0;background:#0F1F33;border-right:1px solid #060E18;min-height:100vh;padding:28px 20px;display:flex;flex-direction:column;}
.sb-profile{text-align:center;margin-bottom:26px;}
.sb-photo{width:72px;height:72px;border-radius:50%;object-fit:cover;border:2.5px solid var(--violet);box-shadow:0 0 18px rgba(124,95,217,.4);margin:0 auto 10px;display:block;}
.sb-name{font-weight:700;font-size:15px;color:#fff;}
.sb-role{font-size:11px;color:var(--violet-h);text-transform:uppercase;letter-spacing:.6px;margin-top:2px;}
.sb-scope{font-size:10px;color:var(--muted);margin-top:4px;}
.sb-nav{display:flex;flex-direction:column;gap:4px;margin-top:10px;}
.sb-nav a{display:flex;align-items:center;gap:10px;padding:11px 14px;border-radius:8px;color:var(--muted);text-decoration:none;font-size:14px;font-weight:500;transition:background .2s,color .2s;}
.sb-nav a:hover,.sb-nav a.active{background:rgba(124,95,217,.15);color:#fff;}
.sb-nav a i{width:18px;text-align:center;color:var(--violet-h);}
.sb-logout{margin-top:auto;}
.sb-logout a{display:flex;align-items:center;gap:10px;padding:11px 14px;border-radius:8px;color:#fca5a5;text-decoration:none;font-size:14px;font-weight:500;transition:background .2s;}
.sb-logout a:hover{background:rgba(240,84,84,.12);}

.main{flex:1;padding:36px 44px;}
.page-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:22px;flex-wrap:wrap;gap:14px;}
.page-title{font-family:'Rajdhani',sans-serif;font-size:30px;font-weight:700;color:#fff;letter-spacing:1px;}
.page-sub{font-size:13px;color:var(--muted);margin-top:4px;}

.period-badge{background:rgba(124,95,217,.14);border:1px solid rgba(124,95,217,.3);color:var(--violet-h);padding:8px 16px;border-radius:20px;font-size:13px;font-weight:700;display:flex;align-items:center;gap:8px;}
.period-badge.closed{background:rgba(240,84,84,.1);border-color:rgba(240,84,84,.3);color:#fca5a5;}

.filter-bar{background:rgba(23,42,69,.85);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:18px 22px;box-shadow:var(--shadow);margin-bottom:22px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;}
.filter-field{display:flex;align-items:center;gap:10px;}
.filter-field label{font-size:11px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--muted);}
.filter-field select{background:rgba(10,25,47,.7);border:1px solid rgba(255,255,255,.12);border-radius:8px;color:var(--light);font-size:13px;font-family:'DM Sans',sans-serif;padding:9px 34px 9px 12px;outline:none;cursor:pointer;min-width:160px;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%23A0B3C6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center;}
.filter-field select:focus{border-color:var(--violet);}
.btn-apply{background:var(--violet);border:none;color:#fff;font-size:13px;font-weight:700;padding:10px 18px;border-radius:8px;cursor:pointer;display:inline-flex;align-items:center;gap:8px;font-family:'DM Sans',sans-serif;box-shadow:0 4px 14px rgba(124,95,217,.35);transition:background .2s;}
.btn-apply:hover{background:var(--violet-h);}
.clear-link{color:var(--muted);font-size:13px;text-decoration:none;}
.clear-link:hover{color:var(--light);text-decoration:underline;}

.card-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:26px;}
.stat-card{background:rgba(23,42,69,.85);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:20px;box-shadow:var(--shadow);}
.stat-card i{color:var(--violet-h);font-size:20px;margin-bottom:10px;}
.stat-card .num{font-size:28px;font-weight:700;color:#fff;}
.stat-card .label{font-size:12px;color:var(--muted);margin-top:4px;}

.section{background:rgba(23,42,69,.85);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:24px;box-shadow:var(--shadow);margin-bottom:26px;}
.section h2{font-family:'Rajdhani',sans-serif;font-size:19px;color:#fff;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.section h2 i{color:var(--violet-h);font-size:16px;}

table.data{width:100%;border-collapse:collapse;font-size:13px;}
table.data th{text-align:left;color:var(--muted);font-weight:600;padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.08);text-transform:uppercase;font-size:11px;letter-spacing:.4px;cursor:pointer;user-select:none;white-space:nowrap;}
table.data th:hover{color:var(--light);}
table.data th .fa-sort,table.data th .fa-sort-up,table.data th .fa-sort-down{font-size:10px;margin-left:4px;color:var(--muted);}
table.data td{padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.05);}
table.data tr:last-child td{border-bottom:none;}
.pill{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;}
.pill.good{background:rgba(16,185,129,.14);color:var(--good);}
.pill.warn{background:rgba(124,95,217,.14);color:var(--violet-h);}
.pill.bad{background:rgba(240,84,84,.12);color:#fca5a5;}

.mini-list{list-style:none;font-size:13px;}
.mini-list li{display:flex;justify-content:space-between;padding:9px 0;border-bottom:1px solid rgba(255,255,255,.05);}
.mini-list li:last-child{border-bottom:none;}
.mini-list .name{color:var(--light);}
.mini-list .course{color:var(--muted);font-size:12px;}

.empty-note{color:var(--muted);font-size:13px;font-style:italic;}

@media(max-width:768px){body{flex-direction:column;}.sidebar{width:100%;min-height:auto;}.filter-bar{flex-direction:column;align-items:stretch;}}
</style>
<link rel="stylesheet" href="includes/dean_light_theme.css?v=dashboard-ui-20261009"/>
</head>
<body>

<?php
$active = 'tracker';
$sidebarScope = 'Higher Education Division';
include __DIR__ . '/includes/dean_sidebar.php'; if (is_file(__DIR__ . '/dean_header_dark.php')) include __DIR__ . '/dean_header_dark.php';
?>

<main class="main">
    <div class="page-header">
        <div>
            <div class="page-title">Evaluation Tracker</div>
            <div class="page-sub">Pandan Bay Institute — College Division Live Monitoring</div>
        </div>
        <?php if ($period): ?>
        <div class="period-badge"><i class="fa-solid fa-calendar-check"></i> <?= htmlspecialchars($period['period_label'] ?? ($period['semester'] ?? 'Active Period')) ?><?= $daysRemaining !== null ? " — {$daysRemaining}d left" : '' ?></div>
        <?php else: ?>
        <div class="period-badge closed"><i class="fa-solid fa-calendar-xmark"></i> No Active Period</div>
        <?php endif; ?>
    </div>

    <!-- FILTER BAR -->
    <form class="filter-bar" method="GET" action="dean_evaluations.php">
        <div class="filter-field">
            <label for="department">Department</label>
            <select name="department" id="department">
                <option value="">All Departments</option>
                <?php foreach ($deptOptions as $d): ?>
                    <option value="<?= htmlspecialchars($d) ?>" <?= $department === $d ? 'selected' : '' ?>><?= htmlspecialchars($d) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-field">
            <label for="program">Program</label>
            <select name="program" id="program">
                <option value="">All Programs</option>
                <?php foreach ($progOptions as $p): ?>
                    <option value="<?= htmlspecialchars($p) ?>" <?= $program === $p ? 'selected' : '' ?>><?= htmlspecialchars($p) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-field">
            <label for="year_level">Year Level</label>
            <select name="year_level" id="year_level">
                <option value="">All Year Levels</option>
                <?php foreach ($yearLevelOptions as $val => $lbl): ?>
                    <option value="<?= htmlspecialchars($val) ?>" <?= $yearLevel === $val ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn-apply"><i class="fa-solid fa-filter"></i> Apply</button>
        <?php if ($department !== '' || $program !== '' || $yearLevel !== ''): ?>
            <a href="dean_evaluations.php" class="clear-link">Clear filters</a>
        <?php endif; ?>
    </form>

    <!-- STAT CARDS -->
    <div class="card-grid">
        <div class="stat-card"><i class="fa-solid fa-users"></i><div class="num"><?= $peopleInView ?></div><div class="label">Faculty in View</div></div>
        <div class="stat-card"><i class="fa-solid fa-circle-check"></i><div class="num"><?= $fullyEvaluated ?></div><div class="label">Fully Evaluated</div></div>
        <div class="stat-card"><i class="fa-solid fa-user-graduate"></i><div class="num"><?= $studentsInScope ?></div><div class="label">Students in Scope</div></div>
        <div class="stat-card"><i class="fa-solid fa-hourglass-half"></i><div class="num"><?= $notSubmittedCount ?></div><div class="label">Students Not Submitted</div></div>
    </div>

    <!-- PER-PERSON COMPLETION -->
    <div class="section">
        <h2><i class="fa-solid fa-clipboard-list"></i> Per-Person Completion</h2>
        <?php if (empty($tracker)): ?>
            <p class="empty-note">No faculty match the current filters.</p>
        <?php else: ?>
        <table class="data" id="trackerTable">
            <thead>
                <tr>
                    <th data-key="name" data-type="text">Faculty <i class="fa-solid fa-sort"></i></th>
                    <th data-key="department" data-type="text">Department <i class="fa-solid fa-sort"></i></th>
                    <th data-key="course" data-type="text">Program <i class="fa-solid fa-sort"></i></th>
                    <th data-key="received" data-type="num">Evaluations Received <i class="fa-solid fa-sort"></i></th>
                    <th data-key="avg" data-type="num">Avg Rating <i class="fa-solid fa-sort"></i></th>
                    <th data-key="status" data-type="text">Status <i class="fa-solid fa-sort"></i></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tracker as $t): ?>
                <tr data-name="<?= htmlspecialchars(strtolower($t['name'])) ?>"
                    data-department="<?= htmlspecialchars($t['department']) ?>"
                    data-course="<?= htmlspecialchars($t['course']) ?>"
                    data-status="<?= $t['status'] ?>"
                    data-received="<?= $t['received'] ?>"
                    data-avg="<?= $t['avg'] ?? -1 ?>">
                    <td><?= htmlspecialchars($t['name']) ?></td>
                    <td><?= htmlspecialchars($t['department']) ?></td>
                    <td><?= htmlspecialchars($t['course']) ?></td>
                    <td><?= $t['received'] ?></td>
                    <td><?= $t['avg'] !== null ? $t['avg'] : '<span class="empty-note">N/A</span>' ?></td>
                    <td>
                        <?php if ($t['status'] === 'completed'): ?>
                            <span class="pill good">Evaluated</span>
                        <?php else: ?>
                            <span class="pill warn">Pending</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- STUDENTS WHO HAVEN'T SUBMITTED -->
    <div class="section">
        <h2><i class="fa-solid fa-user-clock"></i> Students Who Haven't Submitted — <?= htmlspecialchars($scopeLabel) ?></h2>
        <?php if (!$period): ?>
            <p class="empty-note">No active evaluation period right now.</p>
        <?php elseif (empty($notSubmitted)): ?>
            <p class="empty-note">Everyone in scope has submitted. Nice.</p>
        <?php else: ?>
        <ul class="mini-list">
            <?php foreach ($notSubmitted as $s): ?>
                <li><span class="name"><?= htmlspecialchars($s['name']) ?></span><span class="course"><?= htmlspecialchars($s['course']) ?></span></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>

</main>

<script>
// Sortable columns on the Per-Person Completion table
const table = document.getElementById('trackerTable');
let sortState = { key: null, dir: 1 };
if (table) {
    document.querySelectorAll('#trackerTable th[data-key]').forEach(th => {
        th.addEventListener('click', () => {
            const key = th.dataset.key;
            const type = th.dataset.type;
            sortState.dir = (sortState.key === key) ? -sortState.dir : 1;
            sortState.key = key;

            document.querySelectorAll('#trackerTable th[data-key] i').forEach(i => i.className = 'fa-solid fa-sort');
            th.querySelector('i').className = sortState.dir === 1 ? 'fa-solid fa-sort-up' : 'fa-solid fa-sort-down';

            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            rows.sort((a, b) => {
                let av = a.dataset[key], bv = b.dataset[key];
                if (type === 'num') { av = parseFloat(av); bv = parseFloat(bv); }
                if (av < bv) return -1 * sortState.dir;
                if (av > bv) return 1 * sortState.dir;
                return 0;
            });
            rows.forEach(r => tbody.appendChild(r));
        });
    });
}
</script>
</body>
<link rel="stylesheet" href="includes/dean_light_theme.css?v=dashboard-ui-20261009" id="dean-light-theme-final"/>
</html>