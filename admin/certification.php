x<?php
// admin/certification.php
// ══════════════════════════════════════════════════════════════
// CERTIFICATION OF RATINGS
//
// Gate:   100% of ELIGIBLE STUDENTS (matched by faculty_levels vs.
//         student's own level) must have submitted a student
//         evaluation for this person, this period. Peer evaluations
//         never block generation — they're optional and self-directed.
// Score:  final_rating = average(student_avg, peer_avg) if peer data
//         exists, otherwise just student_avg.
// Authority: System Admin, or the current Super Admin (displayed as Executive Assistant) if granted access
//         via admin_permissions (see permissions.php from earlier).
//
// ── ASSUMPTIONS ABOUT YOUR SCHEMA (adjust if your column names differ) ──
// - users.education_level  : student's own level, enum('junior_high','senior_high','college')
// - faculty_levels(user_id, level) : one row per level a faculty/staff
//                             member is assigned to, same enum values
// - evaluation_periods(id, period_label, is_active)
// - evaluation_tracker(id, evaluator_id, target_user_id, eval_type,
//                       period, submitted_at)
// - questionnaire_answers(tracker_id, question_id, answer_score)
// If any of these differ in your actual DB, the column names below
// are the only things that need changing — the logic stays the same.
// ══════════════════════════════════════════════════════════════
session_start();
require_once 'db.php';
require_once dirname(__DIR__) . '/shared/system_settings_service.php';

// Apply the schedule before reading evaluation_periods.is_active.
ss_sync_from_database($mysqli);

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin','superadmin'])) {
    header("Location: admin_login.php"); exit;
}

// If this admin is the second-tier Admin (not superadmin) and not the
// Executive Assistant, respect the same feature-gate pattern used elsewhere.
if ($_SESSION['role'] === 'admin') {
    require_once 'permissions.php';
    if (!admin_can_edit($mysqli, 'reports_analytics')) {
        die("You don't have access to this feature. Ask a Super Admin to enable it.");
    }
}

// ── ENSURE TABLE EXISTS ────────────────────────────────────────
$mysqli->query("CREATE TABLE IF NOT EXISTS rating_certifications (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    rated_user_id INT UNSIGNED NOT NULL,
    period_id INT UNSIGNED NOT NULL,
    issued_by INT UNSIGNED NOT NULL,
    student_avg DECIMAL(4,2) NULL,
    peer_avg DECIMAL(4,2) NULL,
    final_rating DECIMAL(4,2) NOT NULL,
    adjectival_rating VARCHAR(20) NOT NULL,
    status ENUM('issued','revoked') NOT NULL DEFAULT 'issued',
    issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_person_period (rated_user_id, period_id),
    KEY idx_period (period_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function adjectival($score) {
    if ($score >= 4.5) return 'Outstanding';
    if ($score >= 3.5) return 'Very Satisfactory';
    if ($score >= 2.5) return 'Satisfactory';
    if ($score >= 1.5) return 'Fair';
    return 'Needs Improvement';
}
function scoreColor($s) {
    if ($s === null) return '#6b7280';
    if ($s >= 4.5) return '#32B98A';
    if ($s >= 3.5) return '#6BCFA9';
    if ($s >= 2.5) return '#facc15';
    if ($s >= 1.5) return '#fb923c';
    return '#E6788A';
}

// ── ACTIVE PERIOD ───────────────────────────────────────────────
$period = $mysqli->query("SELECT id, period_label FROM evaluation_periods WHERE is_active=1 LIMIT 1")->fetch_assoc();
$period_id = $period['id'] ?? 0;

// ── GENERATE CERTIFICATE ────────────────────────────────────────
if (isset($_GET['generate_id']) && $period_id) {
    $target_id = intval($_GET['generate_id']);

    // Required students = active students whose level matches ANY of
    // this person's assigned faculty_levels.
    $req = $mysqli->query("
        SELECT COUNT(DISTINCT s.id) AS c
        FROM users s
        JOIN faculty_levels fl ON fl.level = s.education_level
        WHERE fl.user_id = $target_id AND s.role='student' AND s.is_active=1
    ")->fetch_assoc()['c'] ?? 0;

    $done = $mysqli->query("
        SELECT COUNT(DISTINCT evaluator_id) AS c
        FROM evaluation_tracker
        WHERE target_user_id=$target_id AND eval_type='student' AND period_id=$period_id
    ")->fetch_assoc()['c'] ?? 0;

    if ($req == 0 || $done < $req) {
        $_SESSION['cert_toast'] = "Cannot generate: student evaluations are not yet at 100% ($done of $req).";
        header("Location: certification.php"); exit;
    }

    // student_avg
    $sRow = $mysqli->query("
        SELECT AVG(qa.answer_score) AS avg_score
        FROM evaluation_tracker et
        JOIN questionnaire_answers qa ON qa.tracker_id = et.id
        WHERE et.target_user_id=$target_id AND et.eval_type='student' AND et.period_id=$period_id
    ")->fetch_assoc();
    $student_avg = $sRow['avg_score'] !== null ? round($sRow['avg_score'], 2) : null;

    // peer_avg (optional — never blocks generation)
    $pRow = $mysqli->query("
        SELECT AVG(qa.answer_score) AS avg_score
        FROM evaluation_tracker et
        JOIN questionnaire_answers qa ON qa.tracker_id = et.id
        WHERE et.target_user_id=$target_id AND et.eval_type IN ('peer','faculty_peer','staff_peer') AND et.period_id=$period_id
    ")->fetch_assoc();
    $peer_avg = $pRow['avg_score'] !== null ? round($pRow['avg_score'], 2) : null;

    if ($student_avg === null) {
        $_SESSION['cert_toast'] = "Cannot generate: no scored student answers found.";
        header("Location: certification.php"); exit;
    }

    $final = $peer_avg !== null ? round(($student_avg + $peer_avg) / 2, 2) : $student_avg;
    $adj   = adjectival($final);
    $by    = $_SESSION['user_id'];

    $stmt = $mysqli->prepare("
        INSERT INTO rating_certifications
            (rated_user_id, period_id, issued_by, student_avg, peer_avg, final_rating, adjectival_rating, status, issued_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'issued', NOW())
        ON DUPLICATE KEY UPDATE
            student_avg=VALUES(student_avg), peer_avg=VALUES(peer_avg),
            final_rating=VALUES(final_rating), adjectival_rating=VALUES(adjectival_rating),
            issued_by=VALUES(issued_by), issued_at=NOW(), status='issued'
    ");
    $stmt->bind_param("iiiddds", $target_id, $period_id, $by, $student_avg, $peer_avg, $final, $adj);
    $stmt->execute();
    $stmt->close();

    $_SESSION['cert_toast'] = "Certificate generated successfully.";
    header("Location: certification.php?view=certificate&target_id=$target_id&period_id=$period_id"); exit;
}

$toast = $_SESSION['cert_toast'] ?? ''; unset($_SESSION['cert_toast']);

// ══════════════════════════════════════════════════════════════
// VIEW: SINGLE CERTIFICATE (printable)
// ══════════════════════════════════════════════════════════════
if (($_GET['view'] ?? '') === 'certificate' && isset($_GET['target_id']) && isset($_GET['period_id'])) {
    $tid = intval($_GET['target_id']);
    $pid = intval($_GET['period_id']);
    $cert = $mysqli->query("SELECT * FROM rating_certifications WHERE rated_user_id=$tid AND period_id=$pid LIMIT 1")->fetch_assoc();
    $person = $mysqli->query("SELECT full_name, designation, role FROM users WHERE id=$tid LIMIT 1")->fetch_assoc();
    $per = $mysqli->query("SELECT period_label FROM evaluation_periods WHERE id=$pid LIMIT 1")->fetch_assoc();
    $issuer = $mysqli->query("SELECT full_name FROM users WHERE id={$cert['issued_by']} LIMIT 1")->fetch_assoc();

    if (!$cert || !$person) { echo "Certificate not found."; exit; }
    ?>
<!DOCTYPE html><html><head><meta charset="UTF-8"/>
<title>Certificate of Rating — <?= htmlspecialchars($person['full_name']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
<style>
body{font-family:'DM Sans',sans-serif;background:#F8FAFC;color:#0B1F3A;padding:40px;display:flex;justify-content:center;}
.cert{background:#fff;color:#111;max-width:760px;width:100%;padding:60px;border:6px double #2563EB;text-align:center;}
.cert h1{font-family:'Rajdhani',sans-serif;font-size:26px;letter-spacing:2px;color:#F8FAFC;margin-bottom:6px;}
.cert .sub{font-size:12px;color:#666;margin-bottom:32px;text-transform:uppercase;letter-spacing:1.5px;}
.cert .name{font-family:'Rajdhani',sans-serif;font-size:32px;font-weight:700;margin:24px 0 6px;color:#F8FAFC;}
.cert .desig{font-size:14px;color:#555;margin-bottom:28px;}
.cert .score{font-family:'Rajdhani',sans-serif;font-size:56px;font-weight:700;color:<?= scoreColor($cert['final_rating']) ?>;}
.cert .adj{font-size:18px;font-weight:600;margin-bottom:28px;}
.cert .meta{font-size:12px;color:#777;margin-top:32px;border-top:1px solid #ddd;padding-top:16px;line-height:1.8;}
.no-print{margin-top:20px;text-align:center;}
.btn{background:#2563EB;color:#fff;border:none;padding:10px 22px;border-radius:6px;cursor:pointer;font-size:13px;}
@media print{.no-print{display:none}body{background:#fff;padding:0}}

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
body{padding:28px !important;}

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
</style>    <link rel="stylesheet" href="admin_ui_theme.css">
    <link rel="stylesheet" href="admin_compact_ui.css">
<style id="pbi-feature-scrollbar">

/* PBI FEATURE SCROLLBAR — consistent with the compact page scrollbar */
html, body {
  scrollbar-width: thin !important;
  scrollbar-color: #888 transparent !important;
}
html::-webkit-scrollbar, body::-webkit-scrollbar,
.feature-compact ::-webkit-scrollbar { width: 10px !important; height: 10px !important; }
html::-webkit-scrollbar-track, body::-webkit-scrollbar-track,
.feature-compact ::-webkit-scrollbar-track { background: transparent !important; }
html::-webkit-scrollbar-thumb, body::-webkit-scrollbar-thumb,
.feature-compact ::-webkit-scrollbar-thumb {
  background: #888 !important; border-radius: 999px !important;
  border: 2px solid transparent !important; background-clip: padding-box !important;
}
html::-webkit-scrollbar-thumb:hover, body::-webkit-scrollbar-thumb:hover,
.feature-compact ::-webkit-scrollbar-thumb:hover { background: #777 !important; background-clip: padding-box !important; }
html::-webkit-scrollbar-button, body::-webkit-scrollbar-button,
.feature-compact ::-webkit-scrollbar-button { display: block !important; width: 10px !important; height: 10px !important; background-color: transparent !important; }

</style>

<link rel="stylesheet" href="admin_appearance.css">
<script src="admin_appearance.js"></script>
</head><body class="feature-compact">
<div class="cert">
    <h1>Certificate of Rating</h1>
    <div class="sub"><?= htmlspecialchars($per['period_label'] ?? '') ?></div>
    <div>This certifies the official performance rating of</div>
    <div class="name"><?= htmlspecialchars($person['full_name']) ?></div>
    <div class="desig"><?= htmlspecialchars($person['designation']) ?> · <?= $person['role']==='teacher'?'Faculty':'Staff' ?></div>
    <div class="score"><?= number_format($cert['final_rating'],2) ?></div>
    <div class="adj" style="color:<?= scoreColor($cert['final_rating']) ?>"><?= htmlspecialchars($cert['adjectival_rating']) ?></div>
    <div class="meta">
        Student evaluation average: <?= $cert['student_avg'] !== null ? number_format($cert['student_avg'],2) : '—' ?><br>
        Peer evaluation average: <?= $cert['peer_avg'] !== null ? number_format($cert['peer_avg'],2) : 'No peer evaluations submitted' ?><br>
        Issued by <?= htmlspecialchars($issuer['full_name'] ?? 'Unknown') ?> on <?= date('F d, Y', strtotime($cert['issued_at'])) ?>
    </div>
</div>
<div class="no-print"><button class="btn" onclick="window.print()">Print / Save PDF</button> <a href="admin_dashboard.php" class="btn" style="background:#FFFFFF;color:#0B1F3A;display:inline-block;text-decoration:none;">Back to Dashboard</a></div>
</body></html>
<?php exit; }

// ══════════════════════════════════════════════════════════════
// VIEW: MAIN LIST — completion status per person
// ══════════════════════════════════════════════════════════════
$people = [];
$res = $mysqli->query("SELECT id, full_name, designation, photo, role FROM users WHERE role IN ('teacher','staff') AND is_active=1 ORDER BY full_name ASC");
if ($res) $people = $res->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html><html><head><meta charset="UTF-8"/>
<title>Certification of Ratings — PBI Admin</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
<style>
:root{--dark:#F8FAFC;--mid:#FFFFFF;--inner:#F4F8FF;--gold-h:#D69612;--light:#0B1F3A;--muted:#67819E;--border:rgba(30,82,144,.13);}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;background:var(--dark);color:var(--light);padding:28px;}
.page-title{font-family:'Rajdhani',sans-serif;font-size:26px;font-weight:700;margin-bottom:4px;}
.back-btn{display:inline-flex;align-items:center;gap:8px;padding:9px 18px;background:var(--mid);border:1px solid var(--border);border-radius:8px;color:var(--light);font-size:13px;font-weight:600;text-decoration:none;margin-bottom:18px;transition:background .2s;}
.back-btn:hover{background:#2563EB;}
.page-sub{font-size:13px;color:var(--muted);margin-bottom:24px;}
.toast{background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.35);color:#fcd34d;padding:10px 16px;border-radius:8px;font-size:13px;margin-bottom:18px;}
.row{background:var(--mid);border:1px solid var(--border);border-radius:12px;padding:16px 20px;display:flex;align-items:center;gap:16px;margin-bottom:12px;flex-wrap:wrap;}
.avatar{width:44px;height:44px;border-radius:50%;object-fit:cover;border:2px solid var(--border);}
.avatar-ph{width:44px;height:44px;border-radius:50%;background:var(--inner);display:flex;align-items:center;justify-content:center;color:var(--muted);}
.info{flex:1;min-width:180px;}
.name{font-weight:700;font-size:14px;}
.desig{font-size:12px;color:var(--muted);}
.bar-wrap{width:160px;}
.bar-bg{height:6px;background:rgba(30,82,144,.13);border-radius:3px;overflow:hidden;}
.bar-fill{height:100%;border-radius:3px;}
.pct{font-size:12px;color:var(--muted);margin-top:4px;}
.btn{padding:9px 16px;border-radius:8px;font-size:12px;font-weight:700;border:none;cursor:pointer;text-decoration:none;display:inline-block;}
.btn-generate{background:#2563EB;color:#fff;}
.btn-disabled{background:var(--inner);color:var(--muted);border:1px solid var(--border);cursor:not-allowed;}
.btn-view{background:rgba(74,222,128,.15);color:#32B98A;border:1px solid rgba(74,222,128,.3);}

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
</style></head><body>
<a href="admin_dashboard.php" class="back-btn"><i class="fa-solid fa-arrow-left"></i> Back to Dashboard</a>
<div class="page-title">Certification of Ratings</div>
<div class="page-sub"><?= $period ? 'Active period: '.htmlspecialchars($period['period_label']) : 'No active evaluation period — set one under Manage Periods.' ?></div>
<?php if ($toast): ?><div class="toast"><?= htmlspecialchars($toast) ?></div><?php endif; ?>

<?php foreach ($people as $p):
    $req = $mysqli->query("
        SELECT COUNT(DISTINCT s.id) AS c FROM users s
        JOIN faculty_levels fl ON fl.level = s.education_level
        WHERE fl.user_id = {$p['id']} AND s.role='student' AND s.is_active=1
    ")->fetch_assoc()['c'] ?? 0;
    $done = $period_id ? ($mysqli->query("
        SELECT COUNT(DISTINCT evaluator_id) AS c FROM evaluation_tracker
        WHERE target_user_id={$p['id']} AND eval_type='student' AND period_id=$period_id
    ")->fetch_assoc()['c'] ?? 0) : 0;
    $pct = $req > 0 ? round(($done/$req)*100) : 0;
    $ready = ($req > 0 && $done >= $req);
    $existing = $period_id ? $mysqli->query("SELECT id FROM rating_certifications WHERE rated_user_id={$p['id']} AND period_id=$period_id")->fetch_assoc() : null;
?>
<div class="row">
    <?php if ($p['photo']): ?><img class="avatar" src="../image/<?= htmlspecialchars($p['photo']) ?>" alt=""/>
    <?php else: ?><div class="avatar-ph"><i class="fa-solid fa-user"></i></div><?php endif; ?>
    <div class="info">
        <div class="name"><?= htmlspecialchars($p['full_name']) ?></div>
        <div class="desig"><?= $p['role']==='teacher'?'Faculty':'Staff' ?> · <?= htmlspecialchars($p['designation']) ?></div>
    </div>
    <div class="bar-wrap">
        <div class="bar-bg"><div class="bar-fill" style="width:<?= $pct ?>%;background:<?= $ready?'#32B98A':'#D69612' ?>"></div></div>
        <div class="pct"><?= $done ?> of <?= $req ?> students (<?= $pct ?>%)</div>
    </div>
    <?php if ($existing): ?>
        <a class="btn btn-view" href="?view=certificate&target_id=<?= $p['id'] ?>&period_id=<?= $period_id ?>">View certificate</a>
    <?php elseif ($ready): ?>
        <a class="btn btn-generate" href="?generate_id=<?= $p['id'] ?>">Generate certificate</a>
    <?php else: ?>
        <span class="btn btn-disabled">Not yet at 100%</span>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<?php if (empty($people)): ?>
<div style="text-align:center;padding:40px;color:var(--muted)">No faculty or staff on file yet.</div>
<?php endif; ?>
</body></html>
<?php $mysqli->close(); ?>