<?php
// admin/documents.php
require_once 'session_bootstrap.php';   // starts session, connects DB, requires a logged-in admin-area user
// Login + role gate: only admin / superadmin may use this page.
if (!in_array($_SESSION['role'] ?? '', ['admin','superadmin'], true)) {
    header('Location: admin_login.php');
    exit;
}

// ── AUTO-CREATE TABLE ─────────────────────────────────────────
$mysqli->query("CREATE TABLE IF NOT EXISTS system_documents (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    display_name VARCHAR(255)  NOT NULL,
    storage_name VARCHAR(255)  NOT NULL,
    file_size    VARCHAR(30)   NOT NULL,
    category     VARCHAR(100)  NOT NULL DEFAULT 'General',
    visibility   ENUM('All','Teacher','Staff','Student','Admin') NOT NULL DEFAULT 'All',
    uploaded_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$uploadDir = __DIR__ . '/stored_docs/';
$uploadUrl = 'stored_docs/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

$toast      = '';
$toast_type = 'success';

// ── UPLOAD ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['doc_file'])) {
    $display_name = trim($_POST['doc_name']    ?? '');
    $category     = trim($_POST['category']    ?? 'General');
    $visibility   = $_POST['visibility']       ?? 'All';
    $file         = $_FILES['doc_file'];
    $allowed      = ['pdf','doc','docx','xls','xlsx','ppt','pptx','txt'];
    $allowed_vis  = ['All','Teacher','Staff','Student','Admin'];

    if (!in_array($visibility, $allowed_vis)) $visibility = 'All';

    if (empty($display_name)) {
        $toast = "Please enter a document title."; $toast_type = 'error';
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $toast = "File upload error. Please try again."; $toast_type = 'error';
    } else {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) {
            $toast = "File type not allowed. Accepted: PDF, Word, Excel, PowerPoint, TXT."; $toast_type = 'error';
        } elseif ($file['size'] > 100 * 1024 * 1024) {
            // ── UPDATED: max size raised from 20 MB → 100 MB ──
            $toast = "File too large. Maximum size is 100 MB."; $toast_type = 'error';
        } else {
            $safe_name    = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
            $target_path  = $uploadDir . $safe_name;
            if (move_uploaded_file($file['tmp_name'], $target_path)) {
                $file_size = $file['size'] >= 1048576
                    ? round($file['size']/1048576, 1) . ' MB'
                    : round($file['size']/1024, 1) . ' KB';
                $stmt = $mysqli->prepare("INSERT INTO system_documents (display_name, storage_name, file_size, category, visibility) VALUES (?,?,?,?,?)");
                $stmt->bind_param("sssss", $display_name, $safe_name, $file_size, $category, $visibility);
                $stmt->execute(); $stmt->close();
                $toast = "Document \"$display_name\" uploaded successfully.";
            } else {
                $toast = "Failed to save file. Check folder permissions."; $toast_type = 'error';
            }
        }
    }
}

// ── DELETE ────────────────────────────────────────────────────
if (isset($_GET['delete_id'])) {
    $id = intval($_GET['delete_id']);
    $r  = $mysqli->query("SELECT storage_name FROM system_documents WHERE id=$id");
    if ($r && $row = $r->fetch_assoc()) {
        $path = $uploadDir . $row['storage_name'];
        if (file_exists($path)) unlink($path);
        $mysqli->query("DELETE FROM system_documents WHERE id=$id");
        $_SESSION['doc_toast'] = "Document deleted.";
    }
    header("Location: documents.php"); exit;
}

$toast = $toast ?: ($_SESSION['doc_toast'] ?? ''); unset($_SESSION['doc_toast']);

// ── FETCH DOCUMENTS ───────────────────────────────────────────
$filter_vis = $_GET['vis'] ?? 'all';
$filter_cat = $_GET['cat'] ?? 'all';

$where = "WHERE 1=1";
$params = [];
$types  = '';
if ($filter_vis !== 'all') { $where .= " AND visibility=?"; $params[] = $filter_vis; $types .= 's'; }
if ($filter_cat !== 'all') { $where .= " AND category=?";   $params[] = $filter_cat;  $types .= 's'; }

$docs = [];
if ($params) {
    $stmt = $mysqli->prepare("SELECT * FROM system_documents $where ORDER BY uploaded_at DESC");
    $stmt->bind_param($types, ...$params); $stmt->execute();
    $docs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
} else {
    $res  = $mysqli->query("SELECT * FROM system_documents ORDER BY uploaded_at DESC");
    if ($res) $docs = $res->fetch_all(MYSQLI_ASSOC);
}

// Distinct categories for filter
$cats_res = $mysqli->query("SELECT DISTINCT category FROM system_documents ORDER BY category");
$all_cats = $cats_res ? $cats_res->fetch_all(MYSQLI_NUM) : [];

// Stats
$stats = [];
$sr = $mysqli->query("SELECT visibility, COUNT(*) as c FROM system_documents GROUP BY visibility");
if ($sr) while ($row=$sr->fetch_assoc()) $stats[$row['visibility']] = $row['c'];
$total_docs = array_sum($stats);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Documents — PBI Admin</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
<style>
:root{--dark:#F8FAFC;--mid:#FFFFFF;--inner:#F4F8FF;--accent:#2563EB;--hover:#1D4ED8;--teal:#0E7490;--gold:#C77A08;--light:#0B1F3A;--muted:#67819E;--danger:#D6455D;--border:rgba(30,82,144,.13);--radius:10px;--shadow:0 4px 20px rgba(30,82,144,.11);}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;background:var(--dark);color:var(--light);min-height:100vh;padding:28px;}

/* TOAST */
.toast{border-radius:8px;padding:12px 18px;font-size:13px;margin-bottom:22px;display:flex;align-items:center;gap:8px;animation:fadeIn .3s ease;}
.toast-success{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.3);color:#6BCFA9;}
.toast-error{background:rgba(240,84,84,.12);border:1px solid rgba(240,84,84,.3);color:#E78D9C;}
@keyframes fadeIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}

/* PAGE HEADER */
.page-header{margin-bottom:24px;}
.page-header h1{font-family:'Rajdhani',sans-serif;font-size:28px;font-weight:700;color:#fff;margin-bottom:4px;}
.page-header p{font-size:13px;color:var(--muted);}

/* STATS */
.stats-row{display:flex;gap:14px;margin-bottom:24px;flex-wrap:wrap;}
.stat-card{background:var(--mid);border:1px solid var(--border);border-radius:var(--radius);padding:14px 20px;flex:1;min-width:120px;}
.stat-label{font-size:10px;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);margin-bottom:5px;}
.stat-value{font-size:24px;font-weight:700;color:#fff;}

/* UPLOAD PANEL */
.upload-panel{background:var(--mid);border:1px solid var(--border);border-radius:14px;padding:22px 24px;margin-bottom:24px;}
.upload-panel-title{font-family:'Rajdhani',sans-serif;font-size:17px;font-weight:700;color:#fff;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.upload-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;}
.upload-grid .full{grid-column:1/-1;}
.form-group{display:flex;flex-direction:column;gap:5px;}
.form-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--muted);}
.form-input{padding:10px 13px;background:var(--inner);border:1px solid var(--border);border-radius:8px;color:var(--light);font-size:14px;font-family:'DM Sans',sans-serif;outline:none;transition:border-color .2s;}
.form-input:focus{border-color:var(--accent);}
.form-input::placeholder{color:rgba(160,179,198,.4);}
.file-input-wrap{background:var(--inner);border:2px dashed rgba(255,255,255,.12);border-radius:8px;padding:16px;text-align:center;cursor:pointer;transition:border-color .2s;}
.file-input-wrap:hover{border-color:var(--accent);}
.file-input-wrap input[type="file"]{display:none;}
.file-input-label{font-size:13px;color:var(--muted);cursor:pointer;}
.file-input-label i{display:block;font-size:24px;margin-bottom:6px;color:var(--accent);}
.file-chosen{font-size:12px;color:var(--teal);margin-top:6px;font-weight:600;}
.btn-upload{background:var(--accent);color:#fff;border:none;padding:11px 24px;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:7px;font-family:'DM Sans',sans-serif;transition:background .2s;}
.btn-upload:hover{background:var(--hover);}

/* SIZE HINT */
.size-hint{display:inline-flex;align-items:center;gap:5px;font-size:11px;color:var(--muted);margin-left:12px;background:rgba(43,108,176,.1);padding:3px 10px;border-radius:20px;}
.size-hint i{color:var(--accent);}

/* TOOLBAR */
.toolbar{display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap;align-items:center;}
.search-wrap{position:relative;flex:1;min-width:180px;}
.search-wrap i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:13px;}
.search-input{width:100%;padding:9px 12px 9px 36px;background:var(--inner);border:1px solid var(--border);border-radius:var(--radius);color:var(--light);font-size:14px;font-family:'DM Sans',sans-serif;outline:none;transition:border-color .2s;}
.search-input:focus{border-color:var(--accent);}
.search-input::placeholder{color:rgba(160,179,198,.4);}
.filter-select{padding:9px 14px;background:var(--inner);border:1px solid var(--border);border-radius:var(--radius);color:var(--light);font-size:13px;font-family:'DM Sans',sans-serif;outline:none;cursor:pointer;}

/* TABLE */
.table-wrap{background:var(--mid);border:1px solid var(--border);border-radius:14px;overflow:hidden;}
table{width:100%;border-collapse:collapse;}
thead th{padding:11px 16px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);background:var(--inner);text-align:left;white-space:nowrap;}
tbody tr{border-bottom:1px solid var(--border);transition:background .15s;}
tbody tr:last-child{border-bottom:none;}
tbody tr:hover{background:rgba(43,108,176,.06);}
tbody td{padding:13px 16px;font-size:14px;vertical-align:middle;}

/* FILE ICON */
.file-icon{width:36px;height:36px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;}
.icon-pdf{background:rgba(240,84,84,.15);color:#E6788A;}
.icon-word{background:rgba(43,108,176,.15);color:#93c5fd;}
.icon-excel{background:rgba(34,197,94,.12);color:#32B98A;}
.icon-ppt{background:rgba(251,146,60,.12);color:#fb923c;}
.icon-txt{background:rgba(160,179,198,.1);color:var(--muted);}

.doc-cell{display:flex;align-items:center;gap:12px;}
.doc-name{font-weight:600;color:#fff;font-size:14px;}
.doc-meta{font-size:11px;color:var(--muted);}

/* VISIBILITY BADGE */
.vis-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;}
.vis-all{background:rgba(34,197,94,.12);color:#32B98A;}
.vis-teacher{background:rgba(43,108,176,.15);color:#93c5fd;}
.vis-staff{background:rgba(14,116,144,.10);color:#5eead4;}
.vis-student{background:rgba(217,119,6,.15);color:#fcd34d;}
.vis-admin{background:rgba(160,179,198,.1);color:var(--muted);}

/* ACTIONS */
.action-row{display:flex;gap:8px;align-items:center;}
.btn-download{background:rgba(43,108,176,.15);border:1px solid rgba(43,108,176,.3);color:#93c5fd;padding:5px 12px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;display:flex;align-items:center;gap:5px;transition:all .2s;}
.btn-download:hover{background:var(--accent);border-color:var(--accent);color:#fff;}
.btn-delete{background:rgba(240,84,84,.1);border:1px solid rgba(240,84,84,.2);color:#E6788A;padding:5px 10px;border-radius:6px;font-size:12px;cursor:pointer;transition:all .2s;}
.btn-delete:hover{background:rgba(240,84,84,.2);}

/* EMPTY */
.empty-state{text-align:center;padding:60px 20px;color:var(--muted);}
.empty-state i{font-size:40px;opacity:.25;display:block;margin-bottom:14px;}
.empty-state p{font-size:14px;}

@media(max-width:700px){
    .upload-grid{grid-template-columns:1fr;}
    .upload-grid .full{grid-column:1;}
    .hide-mobile{display:none;}
    body{padding:16px;}
}

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
</style>
    <link rel="stylesheet" href="admin_ui_theme.css">
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
</head>
<body class="feature-compact">

<?php if ($toast): ?>
<div class="toast toast-<?= $toast_type ?>">
    <i class="fa-solid <?= $toast_type==='success'?'fa-circle-check':'fa-circle-exclamation' ?>"></i>
    <?= htmlspecialchars($toast) ?>
</div>
<?php endif; ?>

<div class="page-header">
    <h1>Documents</h1>
    <p>Upload and manage institutional documents — control who can see each file</p>
</div>

<!-- STATS -->
<div class="stats-row">
    <div class="stat-card"><div class="stat-label">Total Documents</div><div class="stat-value"><?= $total_docs ?></div></div>
    <div class="stat-card"><div class="stat-label">Visible to All</div><div class="stat-value" style="color:#32B98A"><?= $stats['All']??0 ?></div></div>
    <div class="stat-card"><div class="stat-label">Faculty Only</div><div class="stat-value" style="color:#93c5fd"><?= $stats['Teacher']??0 ?></div></div>
    <div class="stat-card"><div class="stat-label">Staff Only</div><div class="stat-value" style="color:#5eead4"><?= $stats['Staff']??0 ?></div></div>
    <div class="stat-card"><div class="stat-label">Student Only</div><div class="stat-value" style="color:#fcd34d"><?= $stats['Student']??0 ?></div></div>
</div>

<!-- UPLOAD PANEL -->
<div class="upload-panel">
    <div class="upload-panel-title">
        <i class="fa-solid fa-cloud-arrow-up" style="color:var(--accent)"></i>
        Upload New Document
        <span class="size-hint"><i class="fa-solid fa-circle-info"></i> Max 100 MB</span>
    </div>
    <form method="POST" enctype="multipart/form-data" id="uploadForm">
        <div class="upload-grid">
            <div class="form-group full">
                <label class="form-label">Document Title <span style="color:#E6788A">*</span></label>
                <input class="form-input" type="text" name="doc_name" placeholder="e.g. Faculty Evaluation Guide 2025-2026" required/>
            </div>
            <div class="form-group">
                <label class="form-label">Category</label>
                <input class="form-input" type="text" name="category" placeholder="e.g. Evaluation, Policy, Memo"/>
            </div>
            <div class="form-group">
                <label class="form-label">Visible To</label>
                <select class="form-input" name="visibility">
                    <option value="All">All Users</option>
                    <option value="Teacher">Faculty Only</option>
                    <option value="Staff">Staff Only</option>
                    <option value="Student">Students Only</option>
                    <option value="Admin">Admin Only</option>
                </select>
            </div>
            <div class="form-group full">
                <label class="form-label">File <span style="color:#E6788A">*</span></label>
                <div class="file-input-wrap" onclick="document.getElementById('docFile').click()">
                    <input type="file" id="docFile" name="doc_file"
                           accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt"
                           required onchange="showFileName(this)"/>
                    <label class="file-input-label">
                        <i class="fa-solid fa-file-arrow-up"></i>
                        Click to choose a file — PDF, Word, Excel, PowerPoint, TXT
                        <span style="color:var(--accent);font-weight:700;">(max 100 MB)</span>
                    </label>
                    <div class="file-chosen" id="fileChosen" style="display:none"></div>
                </div>
            </div>
        </div>
        <button type="submit" class="btn-upload">
            <i class="fa-solid fa-cloud-arrow-up"></i> Upload Document
        </button>
    </form>
</div>

<!-- TOOLBAR -->
<div class="toolbar">
    <div class="search-wrap">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input class="search-input" type="text" id="searchInput" placeholder="Search documents..." oninput="filterDocs()"/>
    </div>
    <select class="filter-select" id="visFilter" onchange="filterDocs()">
        <option value="all">All Visibility</option>
        <option value="All">All Users</option>
        <option value="Teacher">Faculty</option>
        <option value="Staff">Staff</option>
        <option value="Student">Student</option>
        <option value="Admin">Admin</option>
    </select>
    <?php if (!empty($all_cats)): ?>
    <select class="filter-select" id="catFilter" onchange="filterDocs()">
        <option value="all">All Categories</option>
        <?php foreach ($all_cats as $c): ?>
        <option value="<?= htmlspecialchars($c[0]) ?>"><?= htmlspecialchars($c[0]) ?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
</div>

<!-- TABLE -->
<div class="table-wrap">
    <table id="docsTable">
        <thead>
            <tr>
                <th>#</th>
                <th>Document</th>
                <th class="hide-mobile">Category</th>
                <th>Visible To</th>
                <th class="hide-mobile">Size</th>
                <th class="hide-mobile">Uploaded</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($docs)): ?>
        <tr><td colspan="7">
            <div class="empty-state">
                <i class="fa-solid fa-folder-open"></i>
                <p>No documents uploaded yet.<br>Use the form above to upload your first document.</p>
            </div>
        </td></tr>
        <?php else: foreach ($docs as $i => $d):
            $ext = strtolower(pathinfo($d['storage_name'], PATHINFO_EXTENSION));
            $icon_class = match($ext) {
                'pdf'           => ['fa-file-pdf',        'icon-pdf'],
                'doc','docx'    => ['fa-file-word',       'icon-word'],
                'xls','xlsx'    => ['fa-file-excel',      'icon-excel'],
                'ppt','pptx'    => ['fa-file-powerpoint', 'icon-ppt'],
                default         => ['fa-file-lines',      'icon-txt'],
            };
            $vis_class = match($d['visibility']) {
                'All'     => 'vis-all',
                'Teacher' => 'vis-teacher',
                'Staff'   => 'vis-staff',
                'Student' => 'vis-student',
                default   => 'vis-admin',
            };
        ?>
        <tr data-name="<?= strtolower($d['display_name']) ?>"
            data-vis="<?= $d['visibility'] ?>"
            data-cat="<?= strtolower($d['category']) ?>">
            <td style="color:var(--muted);font-size:13px;"><?= $i+1 ?></td>
            <td>
                <div class="doc-cell">
                    <div class="file-icon <?= $icon_class[1] ?>"><i class="fa-solid <?= $icon_class[0] ?>"></i></div>
                    <div>
                        <div class="doc-name"><?= htmlspecialchars($d['display_name']) ?></div>
                        <div class="doc-meta"><?= strtoupper($ext) ?> · <?= $d['file_size'] ?></div>
                    </div>
                </div>
            </td>
            <td class="hide-mobile" style="font-size:13px;color:var(--muted);"><?= htmlspecialchars($d['category']) ?></td>
            <td><span class="vis-badge <?= $vis_class ?>"><?= $d['visibility'] ?></span></td>
            <td class="hide-mobile" style="font-size:13px;color:var(--muted);"><?= $d['file_size'] ?></td>
            <td class="hide-mobile" style="font-size:13px;color:var(--muted);"><?= date('M d, Y', strtotime($d['uploaded_at'])) ?></td>
            <td>
                <div class="action-row">
                    <a class="btn-download"
                       href="<?= $uploadUrl.htmlspecialchars($d['storage_name']) ?>"
                       target="_blank" download>
                        <i class="fa-solid fa-download"></i> Download
                    </a>
                    <a href="documents.php?delete_id=<?= $d['id'] ?>"
                       onclick="return confirmNav(this, 'Delete this document permanently?', {okLabel:'Delete'})"
                       class="btn-delete" title="Delete">
                        <i class="fa-solid fa-trash-can"></i>
                    </a>
                </div>
            </td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<script>
function showFileName(input) {
    const el = document.getElementById('fileChosen');
    if (input.files && input.files[0]) {
        const mb = (input.files[0].size / 1048576).toFixed(1);
        el.textContent = '✓ ' + input.files[0].name + '  (' + mb + ' MB)';
        el.style.display = 'block';
    }
}
function filterDocs() {
    const q   = document.getElementById('searchInput').value.toLowerCase();
    const vis = document.getElementById('visFilter').value;
    const cat = document.getElementById('catFilter')?.value || 'all';
    document.querySelectorAll('#docsTable tbody tr[data-name]').forEach(row => {
        const nm = row.dataset.name.includes(q);
        const vm = vis === 'all' || row.dataset.vis === vis;
        const cm = cat === 'all' || row.dataset.cat.includes(cat.toLowerCase());
        row.style.display = nm && vm && cm ? '' : 'none';
    });
}
// Navigates to a link's href only after the custom (non-native) confirm modal is accepted.
function confirmNav(link, message, opts){
    (async () => {
        if (await PBI.confirm(message, opts)) {
            window.location.href = link.href;
        }
    })();
    return false;
}
</script>
<script src="admin_ajax.php"></script>

<?php $mysqli->close(); ?>
</body>
</html>