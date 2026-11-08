<?php
// admin/personnel_registry.php
require_once 'session_bootstrap.php';   // starts session, connects DB, requires a logged-in admin-area user
// Login + role gate: only admin / superadmin may use this page.
if (!in_array($_SESSION['role'] ?? '', ['admin','superadmin'], true)) {
    header('Location: admin_login.php');
    exit;
}
require_once 'permissions.php';
$can_edit = admin_can_edit($mysqli, 'personnel_registry');
// Every write (add / edit / assign / toggle / delete) needs edit rights; view-only admins can only look.
if (!$can_edit && ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['delete_id']) || isset($_GET['toggle_id']))) {
    http_response_code(403);
    exit('View-only access: you do not have permission to change the Personnel Registry.');
}

$UPLOAD_DIR = defined('UPLOAD_DIR') ? UPLOAD_DIR : '../image/';
$UPLOAD_URL = defined('UPLOAD_URL') ? UPLOAD_URL : '../image/';

$viewSector = $_GET['sector'] ?? 'Faculty';
$sectorRole = $viewSector === 'Staff' ? 'staff' : 'teacher';

// Suggestion list only — admin can still type any custom designation
$desig_options = [
    'faculty' => ['Teacher','Registrar','Cashier','Bookkeeper','Librarian','Guidance','Nurse','Personnel','Adviser','Coordinator','Department Head'],
    'staff'   => ['Personnel','Registrar','Cashier','Bookkeeper','Librarian','Guidance','Nurse','Coordinator'],
];
$all_desigs = array_values(array_unique(array_merge($desig_options['faculty'], $desig_options['staff'])));

// ── DELETE ───────────────────────────────────────────────────
if (isset($_GET['delete_id'])) {
    $id  = intval($_GET['delete_id']);
    $res = $mysqli->query("SELECT photo, source FROM users WHERE id=$id LIMIT 1");
    if ($res && $row = $res->fetch_assoc()) {
        if ($row['source'] === 'admin_nologin') {
            try {
                $stmt = $mysqli->prepare("DELETE FROM users WHERE id=? AND source='admin_nologin'");
                $stmt->bind_param("i", $id);
                $ok = $stmt->execute();
                $stmt->close();

                if ($ok) {
                    if ($row['photo'] && file_exists($UPLOAD_DIR . $row['photo'])) {
                        unlink($UPLOAD_DIR . $row['photo']);
                    }
                    $_SESSION['toast'] = "Personnel entry removed.";
                } elseif ($mysqli->errno === 1451) {
                    $_SESSION['toast_error'] = "Can't remove this entry — they still have evaluation records tied to them. Deactivate them instead.";
                } else {
                    $_SESSION['toast_error'] = "Failed to remove entry: " . $mysqli->error;
                }
            } catch (mysqli_sql_exception $e) {
                if ($e->getCode() === 1451) {
                    $_SESSION['toast_error'] = "Can't remove this entry — they still have evaluation records tied to them. Deactivate them instead.";
                } else {
                    $_SESSION['toast_error'] = "Failed to remove entry: " . $e->getMessage();
                }
            }
        } else {
            $_SESSION['toast_error'] = "Cannot delete login accounts from this page.";
        }
    }
    header("Location: personnel_registry.php?sector=" . urlencode($viewSector)); exit;
}

// ── TOGGLE VISIBILITY ────────────────────────────────────────
if (isset($_GET['toggle_id'])) {
    $id = intval($_GET['toggle_id']);
    $mysqli->query("UPDATE users SET is_active = IF(is_active=1,0,1) WHERE id=$id AND source='admin_nologin'");
    $_SESSION['toast'] = "Visibility updated.";
    header("Location: personnel_registry.php?sector=" . urlencode($viewSector)); exit;
}

// ── ASSIGN DESIGNATION(S) — now supports comma-separated Teacher/Staff ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_desig') {
    $uid = intval($_POST['user_id']);

    // Normalize: trim each tag, drop empties, remove duplicates, re-join
    $raw  = $_POST['designation'] ?? '';
    $tags = array_filter(array_map('trim', explode(',', $raw)), fn($t) => $t !== '');
    $tags = array_values(array_unique($tags));
    $desig = $mysqli->real_escape_string(implode(', ', $tags));

    $mysqli->query("UPDATE users SET designation='$desig' WHERE id=$uid AND source='admin_nologin'");
    $_SESSION['toast'] = "Designation updated to '" . ($desig ?: '—') . "'.";
    header("Location: personnel_registry.php?sector=" . urlencode($viewSector)); exit;
}

// ── ADD PERSONNEL ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_personnel') {
    $full_name = $mysqli->real_escape_string(trim($_POST['full_name']));
    $role      = ($_POST['role'] ?? '') === 'staff' ? 'staff' : 'teacher';

    $raw_desig = $_POST['designation'] ?? ($role === 'staff' ? $desig_options['staff'][0] : $desig_options['faculty'][0]);
    $tags = array_filter(array_map('trim', explode(',', $raw_desig)), fn($t) => $t !== '');
    $tags = array_values(array_unique($tags));
    if (empty($tags)) $tags = [($role === 'staff' ? $desig_options['staff'][0] : $desig_options['faculty'][0])];
    $designation = $mysqli->real_escape_string(implode(', ', $tags));

    $photo_file = '';
    if (!empty($_FILES['photo']['name'])) {
        $ext     = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','gif','webp'];
        if (in_array($ext, $allowed) && $_FILES['photo']['error'] === 0) {
            $uniq = uniqid('p_', true) . '.' . $ext;
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $UPLOAD_DIR . $uniq)) {
                $photo_file = $uniq;
            }
        }
    }

    $base_user = strtolower(preg_replace('/\s+/', '_', $full_name));
    $username  = $base_user . '_' . substr(uniqid(), -4);

    $stmt = $mysqli->prepare(
        "INSERT INTO users (full_name, username, role, designation, photo, is_active, source, account_status, created_at)
         VALUES (?, ?, ?, ?, ?, 1, 'admin_nologin', 'approved', NOW())"
    );
    $stmt->bind_param("sssss", $full_name, $username, $role, $designation, $photo_file);
    if ($stmt->execute()) {
        $_SESSION['toast'] = "'$full_name' added and will now appear in the questionnaire.";
    } else {
        $_SESSION['toast_error'] = "Failed to add: " . $stmt->error;
    }
    $stmt->close();
    header("Location: personnel_registry.php?sector=" . urlencode($viewSector)); exit;
}

// ── EDIT PERSONNEL ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_personnel') {
    $uid       = intval($_POST['user_id']);
    $full_name = $mysqli->real_escape_string(trim($_POST['full_name']));
    $role      = ($_POST['role'] ?? '') === 'staff' ? 'staff' : 'teacher';

    $raw_desig = $_POST['designation'] ?? '';
    $tags = array_filter(array_map('trim', explode(',', $raw_desig)), fn($t) => $t !== '');
    $tags = array_values(array_unique($tags));
    $designation = $mysqli->real_escape_string(implode(', ', $tags));

    $photo_file = null;
    if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === 0) {
        $ext     = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','gif','webp'];
        if (in_array($ext, $allowed)) {
            $uniq = uniqid('p_', true) . '.' . $ext;
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $UPLOAD_DIR . $uniq)) {
                $old = $mysqli->query("SELECT photo FROM users WHERE id=$uid")->fetch_assoc();
                if ($old && $old['photo'] && file_exists($UPLOAD_DIR . $old['photo'])) {
                    unlink($UPLOAD_DIR . $old['photo']);
                }
                $photo_file = $uniq;
            }
        }
    }

    if ($photo_file !== null) {
        $stmt = $mysqli->prepare("UPDATE users SET full_name=?, role=?, designation=?, photo=? WHERE id=? AND source='admin_nologin'");
        $stmt->bind_param("ssssi", $full_name, $role, $designation, $photo_file, $uid);
    } else {
        $stmt = $mysqli->prepare("UPDATE users SET full_name=?, role=?, designation=? WHERE id=? AND source='admin_nologin'");
        $stmt->bind_param("sssi", $full_name, $role, $designation, $uid);
    }
    $stmt->execute();
    $stmt->close();
    $_SESSION['toast'] = "'$full_name' updated successfully.";
    header("Location: personnel_registry.php?sector=" . urlencode($viewSector)); exit;
}

// Personnel created directly by the EA are trusted records, not self-registered
// accounts. Keep legacy admin_nologin records visible to the evaluation system.
$mysqli->query("UPDATE users SET account_status='approved' WHERE source='admin_nologin' AND (account_status IS NULL OR account_status <> 'approved')");

// ── FETCH DATA ───────────────────────────────────────────────
$stmt = $mysqli->prepare("SELECT * FROM users WHERE source='admin_nologin' AND role IN ('teacher','faculty') ORDER BY full_name ASC");

$stmt->execute();
$entries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$counts = [];
$facultyCount = $mysqli->query("SELECT COUNT(*) as c FROM users WHERE source='admin_nologin' AND role IN ('teacher','faculty')");
$staffCount   = $mysqli->query("SELECT COUNT(*) as c FROM users WHERE source='admin_nologin' AND role='staff'");
$counts['Faculty'] = $facultyCount ? ($facultyCount->fetch_assoc()['c'] ?? 0) : 0;
$counts['Staff']   = $staffCount ? ($staffCount->fetch_assoc()['c'] ?? 0) : 0;

$activeCount   = count(array_filter($entries, fn($u) => $u['is_active']));
$inactiveCount = count($entries) - $activeCount;

$toast       = $_SESSION['toast']       ?? ''; unset($_SESSION['toast']);
$toast_error = $_SESSION['toast_error'] ?? ''; unset($_SESSION['toast_error']);

// ── SECTOR THEME (Faculty = blue, mirrors "Teacher" from the
//    questionnaire page; Staff = purple, mirrors "Staff" there) ──
$is_staff_sector = ($viewSector === 'Staff');
$desigKey = $is_staff_sector ? 'staff' : 'faculty';
$sector_color    = $is_staff_sector ? '#4968C8' : '#2563EB';
$sector_bg       = $is_staff_sector ? 'rgba(73,104,200,.08)' : 'rgba(37,99,235,.08)';
$sector_border   = $is_staff_sector ? 'rgba(73,104,200,.15)' : 'rgba(37,99,235,.16)';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Personnel Registry — PBI Admin</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
<style>
:root{
  --page-bg:#EEF2F8;--card-bg:#FFFFFF;--card-border:#D8E5F4;
  --text-dark:#1E2A3A;--text-dim:#67819E;--track-bg:#D8E5F4;
  --radius:10px;--card-shadow:0 1px 2px rgba(30,82,144,.05),0 4px 12px rgba(30,82,144,.06);
  --danger:#D6455D;--danger-bg:rgba(220,38,38,.08);--danger-border:rgba(220,38,38,.22);
  --success:#0F9F6E;--success-bg:rgba(5,150,105,.1);--success-border:rgba(5,150,105,.25);
  --sector:<?= $sector_color ?>;--sector-bg:<?= $sector_bg ?>;--sector-border:<?= $sector_border ?>;
  --staff:#4968C8;--staff-bg:rgba(73,104,200,.08);--staff-border:rgba(73,104,200,.15);
  --mr:#C77A08;--mr-bg:rgba(217,119,6,.08);--mr-border:rgba(217,119,6,.24);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',sans-serif;background:var(--page-bg);color:var(--text-dark);min-height:100vh;padding:36px 28px;}

.toast{position:fixed;top:20px;right:20px;z-index:9999;display:flex;align-items:center;gap:10px;background:#E8F8F1;border:1px solid #BEEBD8;border-radius:8px;padding:12px 18px;font-size:14px;color:#0D7A4E;box-shadow:var(--card-shadow);animation:slideIn .3s ease,fadeOut .4s ease 3.5s forwards;max-width:380px;}
.toast.error{background:var(--danger-bg);border-color:var(--danger-border);color:var(--danger);}
@keyframes slideIn{from{opacity:0;transform:translateX(20px)}to{opacity:1;transform:none}}
@keyframes fadeOut{to{opacity:0;pointer-events:none}}

.page-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:24px;padding-bottom:20px;border-bottom:1px solid var(--card-border);gap:16px;flex-wrap:wrap;}
.page-header h1{font-family:'Rajdhani',sans-serif;font-size:30px;font-weight:700;color:var(--text-dark);margin-bottom:4px;}
.page-header p{font-size:13px;color:var(--text-dim);}
.btn-add{display:inline-flex;align-items:center;gap:8px;background:var(--sector);color:#fff;border:none;padding:10px 20px;border-radius:var(--radius);font-size:13px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;transition:opacity .2s;white-space:nowrap;box-shadow:var(--card-shadow);}
.btn-add:hover{opacity:.88;}

.sector-tabs{display:flex;gap:0;background:var(--card-bg);border:1px solid var(--card-border);border-radius:12px;overflow:hidden;margin-bottom:28px;width:fit-content;box-shadow:var(--card-shadow);}
.sector-tab{display:flex;align-items:center;gap:9px;padding:13px 26px;font-size:14px;font-weight:600;cursor:pointer;text-decoration:none;color:var(--text-dim);border:none;background:none;font-family:'Inter',sans-serif;transition:all .22s;position:relative;}
.sector-tab:hover{color:var(--text-dark);background:var(--page-bg);}
.sector-tab.active.tab-faculty{color:#2563EB;background:rgba(37,99,235,.08);}
.sector-tab.active.tab-staff{color:#4968C8;background:rgba(73,104,200,.08);}
.sector-tab.active.tab-faculty::after,.sector-tab.active.tab-staff::after{content:'';position:absolute;bottom:0;left:0;right:0;height:2px;border-radius:2px 2px 0 0;}
.sector-tab.active.tab-faculty::after{background:#2563EB;}
.sector-tab.active.tab-staff::after{background:#4968C8;}
.tab-badge{font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;background:var(--page-bg);color:var(--text-dim);}
.sector-tab.active.tab-faculty .tab-badge{background:rgba(37,99,235,.10);color:#2563EB;}
.sector-tab.active.tab-staff .tab-badge{background:rgba(73,104,200,.10);color:#4968C8;}

.stats-row{display:flex;gap:16px;margin-bottom:24px;flex-wrap:wrap;}
.stat-card{background:var(--card-bg);border:1px solid var(--card-border);border-top:4px solid var(--sector);border-radius:14px;padding:16px 22px;flex:1;min-width:130px;box-shadow:var(--card-shadow);}
.stat-label{font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:var(--text-dim);margin-bottom:6px;}
.stat-value{font-size:26px;font-weight:700;color:var(--text-dark);}
.stat-value.green{color:var(--success);}
.stat-value.red{color:var(--danger);}

.toolbar{display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap;align-items:center;}
.search-wrap{position:relative;flex:1;min-width:200px;}
.search-wrap i{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--text-dim);font-size:13px;}
.search-input{width:100%;padding:10px 13px 10px 38px;background:var(--card-bg);border:1px solid var(--card-border);border-radius:var(--radius);color:var(--text-dark);font-size:14px;font-family:'Inter',sans-serif;outline:none;transition:border-color .2s;}
.search-input::placeholder{color:#A6B2C4;}
.search-input:focus{border-color:var(--sector);}
.filter-select{padding:10px 14px;background:var(--card-bg);border:1px solid var(--card-border);border-radius:var(--radius);color:var(--text-dark);font-size:13px;font-family:'Inter',sans-serif;outline:none;cursor:pointer;}

.table-wrap{background:var(--card-bg);border:1px solid var(--card-border);border-radius:14px;overflow:hidden;box-shadow:var(--card-shadow);}
table{width:100%;border-collapse:collapse;}
thead tr{background:var(--page-bg);}
thead th{padding:12px 16px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--text-dim);text-align:left;white-space:nowrap;border-bottom:1px solid var(--card-border);}
tbody tr{border-bottom:1px solid var(--card-border);transition:background .15s;}
tbody tr:last-child{border-bottom:none;}
tbody tr:hover{background:var(--page-bg);}
tbody td{padding:14px 16px;font-size:14px;vertical-align:middle;}

.user-cell{display:flex;align-items:center;gap:12px;}
.user-avatar{width:44px;height:44px;border-radius:50%;object-fit:cover;border:2px solid var(--card-border);background:var(--page-bg);flex-shrink:0;}
.avatar-placeholder{width:44px;height:44px;border-radius:50%;background:var(--page-bg);border:2px solid var(--card-border);display:flex;align-items:center;justify-content:center;color:var(--text-dim);font-size:17px;flex-shrink:0;}
.user-name{font-weight:600;color:var(--text-dark);font-size:14px;}

/* ── TEACHER/STAFF TAG SYSTEM ────────────────────────────────────── */
.tag-input-wrap{min-width:220px;}
.tag-list{display:flex;flex-wrap:wrap;gap:5px;margin-bottom:6px;min-height:22px;}
.tag-chip{display:inline-flex;align-items:center;gap:6px;background:var(--sector-bg);border:1px solid var(--sector-border);color:var(--sector);border-radius:14px;padding:3px 6px 3px 10px;font-size:11px;font-weight:700;}
.tag-chip .tag-remove{cursor:pointer;font-size:10px;color:var(--sector);opacity:.65;transition:opacity .15s,color .15s;width:14px;height:14px;display:flex;align-items:center;justify-content:center;border-radius:50%;}
.tag-chip .tag-remove:hover{opacity:1;color:var(--danger);background:var(--danger-bg);}
.tag-empty-hint{font-size:11px;color:var(--text-dim);font-style:italic;}

.tag-input-row{display:flex;gap:6px;}
.tag-text-input{flex:1;background:var(--card-bg);border:1px solid var(--card-border);color:var(--text-dark);padding:6px 10px;border-radius:6px;font-size:12px;font-family:'Inter',sans-serif;outline:none;transition:border-color .2s;min-width:120px;}
.tag-text-input:focus{border-color:var(--sector);}
.tag-text-input::placeholder{color:#A6B2C4;}
.btn-tag-add{background:var(--page-bg);border:1px solid var(--card-border);color:var(--text-dark);padding:6px 10px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;transition:all .2s;font-family:'Inter',sans-serif;white-space:nowrap;}
.btn-tag-add:hover{border-color:var(--sector);color:var(--sector);}
.btn-assign{background:var(--sector);color:#fff;border:none;padding:6px 14px;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;transition:opacity .2s;white-space:nowrap;font-family:'Inter',sans-serif;margin-top:6px;}
.btn-assign:hover{opacity:.88;}
.tag-hint{font-size:10px;color:var(--text-dim);margin-top:4px;}

.qs-pill{display:inline-flex;align-items:center;gap:5px;background:var(--success-bg);border:1px solid var(--success-border);color:var(--success);border-radius:20px;padding:3px 10px;font-size:11px;font-weight:700;}
.hidden-pill{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;background:var(--danger-bg);color:var(--danger);}

.action-wrap{display:flex;gap:6px;align-items:center;}
.btn-icon{background:var(--card-bg);border:1px solid var(--card-border);border-radius:6px;padding:6px 10px;color:var(--text-dim);cursor:pointer;font-size:13px;transition:all .2s;font-family:'Inter',sans-serif;}
.btn-icon:hover{background:var(--page-bg);color:var(--text-dark);}
.btn-icon.edit:hover{border-color:var(--sector-border);color:var(--sector);}
.btn-icon.toggle:hover{border-color:var(--success-border);color:var(--success);}
.btn-icon.danger:hover{border-color:var(--danger-border);color:var(--danger);}

.empty-state{text-align:center;padding:64px 20px;color:var(--text-dim);}
.empty-state i{font-size:44px;margin-bottom:16px;display:block;opacity:.3;}
.empty-state p{font-size:14px;line-height:1.7;}
.empty-cta{display:inline-flex;align-items:center;gap:7px;margin-top:16px;background:var(--sector);color:#fff;border:none;padding:10px 20px;border-radius:var(--radius);font-size:13px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;}
.empty-cta:hover{opacity:.88;}

.modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:200;display:none;align-items:center;justify-content:center;padding:20px;}
.modal-overlay.open{display:flex;}
.modal{background:var(--card-bg);border:1px solid var(--card-border);border-radius:16px;padding:30px;width:100%;max-width:500px;box-shadow:0 24px 64px rgba(15,23,42,.35);max-height:90vh;overflow-y:auto;}
.modal-head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:22px;}
.modal-title{font-family:'Rajdhani',sans-serif;font-size:22px;font-weight:700;color:var(--text-dark);}
.modal-sub{font-size:13px;color:var(--text-dim);margin-top:3px;}
.modal-close{background:none;border:none;color:var(--text-dim);font-size:18px;cursor:pointer;padding:3px 7px;border-radius:5px;transition:color .2s;}
.modal-close:hover{color:var(--text-dark);}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;}
.form-grid.full{grid-template-columns:1fr;}
.fg{display:flex;flex-direction:column;gap:5px;}
.fg label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--text-dim);}
.fg input,.fg select{background:var(--card-bg);border:1px solid var(--card-border);border-radius:8px;padding:10px 12px;color:var(--text-dark);font-size:13px;font-family:'Inter',sans-serif;outline:none;transition:border-color .2s,box-shadow .2s;width:100%;}
.fg input::placeholder{color:#A6B2C4;}
.fg input:focus,.fg select:focus{border-color:var(--sector);box-shadow:0 0 0 3px var(--sector-bg);}
.fg .hint{font-size:11px;color:var(--text-dim);}
.photo-preview{width:72px;height:72px;border-radius:50%;object-fit:cover;border:2px solid var(--sector);display:none;}
.photo-preview.visible{display:block;}
.modal-actions{display:flex;gap:10px;margin-top:24px;}
.btn-cancel{flex:1;padding:10px;background:var(--page-bg);border:1px solid var(--card-border);border-radius:var(--radius);color:var(--text-dark);font-size:14px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;}
.btn-cancel:hover{background:#D8E5F4;}
.btn-submit{flex:2;padding:10px;background:var(--sector);border:none;border-radius:var(--radius);color:#fff;font-size:14px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;display:flex;align-items:center;justify-content:center;gap:7px;}
.btn-submit:hover{opacity:.88;}
.btn-confirm-del{flex:1;padding:10px;background:var(--danger);border:none;border-radius:var(--radius);color:#fff;font-size:14px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;}
.btn-confirm-del:hover{opacity:.88;}

@media(max-width:860px){.hide-sm{display:none;}body{padding:20px 14px;}.form-grid{grid-template-columns:1fr;}.page-header{flex-direction:column;align-items:stretch;}}
@media(max-width:560px){.sector-tabs{width:100%;}.sector-tab{flex:1;justify-content:center;padding:9px 8px;font-size:12px;}.tag-input-wrap{min-width:0;}}

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

/* Persistent icon coding */
.btn-add > i{color:#FFFFFF !important;}
.sector-tab:has(.fa-chalkboard-user) > i{color:#2563EB !important;}
.sector-tab:has(.fa-briefcase) > i{color:#4968C8 !important;}

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
<div class="toast"><i class="fa-solid fa-circle-check"></i><?= htmlspecialchars($toast) ?></div>
<?php endif; ?>
<?php if ($toast_error): ?>
<div class="toast error"><i class="fa-solid fa-circle-exclamation"></i><?= htmlspecialchars($toast_error) ?></div>
<?php endif; ?>

<div class="page-header">
    <div>
        <h1>Personnel Registry</h1>
        <p>Add faculty &amp; staff who haven't created an account — they'll appear in the questionnaire automatically</p>
    </div>
    <button class="btn-add" onclick="openAddModal()">
        <i class="fa-solid fa-user-plus"></i> Add Personnel
    </button>
</div>

<div class="sector-tabs">
    <?php foreach (['Faculty'=>['fa-chalkboard-user','tab-faculty'],'Staff'=>['fa-briefcase','tab-staff']] as $sector => $meta): ?>
    <a class="sector-tab <?= $viewSector===$sector ? 'active '.$meta[1] : '' ?>"
       href="personnel_registry.php?sector=<?= $sector ?>">
        <i class="fa-solid <?= $meta[0] ?>"></i> <?= $sector ?>
        <span class="tab-badge"><?= $counts[$sector] ?></span>
    </a>
    <?php endforeach; ?>
</div>

<div class="stats-row">
    <div class="stat-card"><div class="stat-label">Total <?= $viewSector ?></div><div class="stat-value"><?= count($entries) ?></div></div>
    <div class="stat-card"><div class="stat-label">Visible in Questionnaire</div><div class="stat-value green"><?= $activeCount ?></div></div>
    <div class="stat-card"><div class="stat-label">Hidden</div><div class="stat-value red"><?= $inactiveCount ?></div></div>
</div>

<div class="toolbar">
    <div class="search-wrap">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input class="search-input" id="searchInput" type="text"
               placeholder="Search by name or designation…" oninput="filterTable()"/>
    </div>
    <select class="filter-select" id="statusFilter" onchange="filterTable()">
        <option value="all">All Visibility</option>
        <option value="active">Visible</option>
        <option value="inactive">Hidden</option>
    </select>
    <select class="filter-select" id="desigFilter" onchange="filterTable()">
        <option value="all">All Designations</option>
        <?php foreach ($desig_options[$desigKey] as $d): ?>
        <option value="<?= strtolower($d) ?>"><?= $d ?></option>
        <?php endforeach; ?>
    </select>
</div>

<div class="table-wrap">
<table id="mainTable">
    <thead>
        <tr>
            <th>#</th>
            <th>Name</th>
            <th style="min-width:240px;">Designation</th>
            <th class="hide-sm">Questionnaire</th>
            <th class="hide-sm">Added</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($entries)): ?>
    <tr><td colspan="6">
        <div class="empty-state">
            <i class="fa-solid fa-user-slash"></i>
            <p>No <?= strtolower($viewSector) ?> entries yet.<br>
               Add someone and they'll appear in the questionnaire right away.</p>
            <button class="empty-cta" onclick="openAddModal()">
                <i class="fa-solid fa-user-plus"></i> Add First Entry
            </button>
        </div>
    </td></tr>
    <?php else: foreach ($entries as $i => $u):
        $desigList = array_filter(array_map('trim', explode(',', $u['designation'] ?? '')), fn($t) => $t !== '');
        $wrapId    = 'tagwrap_' . $u['id'];
    ?>
    <tr data-name="<?= strtolower($u['full_name']) ?>"
        data-desig="<?= strtolower(implode(' ', $desigList)) ?>"
        data-status="<?= $u['is_active'] ? 'active' : 'inactive' ?>">

        <td style="color:var(--text-dim);font-size:13px;"><?= $i+1 ?></td>

        <!-- NAME + PHOTO -->
        <td>
            <div class="user-cell">
                <?php if (!empty($u['photo']) && file_exists($UPLOAD_DIR . $u['photo'])): ?>
                <img class="user-avatar" src="<?= $UPLOAD_URL . htmlspecialchars($u['photo']) ?>" alt=""/>
                <?php else: ?>
                <div class="avatar-placeholder"><i class="fa-solid fa-user"></i></div>
                <?php endif; ?>
                <div class="user-name"><?= htmlspecialchars($u['full_name']) ?></div>
            </div>
        </td>

        <!-- DESIGNATION — Teacher/Staff tag editor -->
        <td>
            <div class="tag-input-wrap" id="<?= $wrapId ?>">
                <div class="tag-list"></div>
                <div class="tag-input-row">
                    <input type="text" class="tag-text-input" list="desigSuggestions"
                           placeholder="Type a role + Enter"
                           onkeydown="if(event.key==='Enter'){event.preventDefault();addFromInput('<?= $wrapId ?>');}"/>
                    <button type="button" class="btn-tag-add" onclick="addFromInput('<?= $wrapId ?>')">
                        <i class="fa-solid fa-plus"></i>
                    </button>
                </div>
                <form method="POST" action="personnel_registry.php?sector=<?= urlencode($viewSector) ?>">
                    <input type="hidden" name="action"      value="assign_desig"/>
                    <input type="hidden" name="user_id"     value="<?= $u['id'] ?>"/>
                    <input type="hidden" name="designation" class="tag-hidden"
                           value="<?= htmlspecialchars($u['designation'] ?? '') ?>"/>
                    <button type="submit" class="btn-assign"><i class="fa-solid fa-floppy-disk"></i> Save Roles</button>
                </form>
            </div>
        </td>

        <!-- QUESTIONNAIRE STATUS -->
        <td class="hide-sm">
            <?php if ($u['is_active']): ?>
            <span class="qs-pill"><i class="fa-solid fa-circle" style="font-size:7px"></i> Visible</span>
            <?php else: ?>
            <span class="hidden-pill"><i class="fa-solid fa-eye-slash" style="font-size:9px"></i> Hidden</span>
            <?php endif; ?>
        </td>

        <!-- ADDED DATE -->
        <td class="hide-sm" style="font-size:13px;color:var(--text-dim);">
            <?= date('M d, Y', strtotime($u['created_at'])) ?>
        </td>

        <!-- ACTIONS -->
        <td>
            <div class="action-wrap">
                <button class="btn-icon edit" title="Edit"
                        onclick='openEditModal(<?= json_encode($u) ?>)'>
                    <i class="fa-solid fa-pen-to-square"></i>
                </button>
                <button class="btn-icon toggle"
                        title="<?= $u['is_active'] ? 'Hide' : 'Show' ?>"
                        onclick="window.location.href='personnel_registry.php?sector=<?= urlencode($viewSector) ?>&toggle_id=<?= $u['id'] ?>'">
                    <i class="fa-solid <?= $u['is_active'] ? 'fa-eye-slash' : 'fa-eye' ?>"></i>
                </button>
                <button class="btn-icon danger" title="Remove"
                        onclick="confirmDelete(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['full_name'])) ?>')">
                    <i class="fa-solid fa-trash-can"></i>
                </button>
            </div>
        </td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>

<!-- Shared datalist of designation suggestions — admin can still type anything custom -->
<datalist id="desigSuggestions">
    <?php foreach ($all_desigs as $d): ?>
    <option value="<?= htmlspecialchars($d) ?>"></option>
    <?php endforeach; ?>
</datalist>

<!-- ADD MODAL -->
<div class="modal-overlay" id="addModal">
<div class="modal">
    <div class="modal-head">
        <div>
            <div class="modal-title"><i class="fa-solid fa-user-plus" style="color:var(--sector);margin-right:8px"></i>Add Personnel</div>
            <div class="modal-sub">No login required — they'll appear in the questionnaire once saved</div>
        </div>
        <button class="modal-close" onclick="closeModal('addModal')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form method="POST" action="personnel_registry.php?sector=<?= urlencode($viewSector) ?>" enctype="multipart/form-data">
        <input type="hidden" name="action" value="add_personnel"/>
        <div class="form-grid">
            <div class="fg" style="grid-column:1/-1;">
                <label>Full Name</label>
                <input type="text" name="full_name" placeholder="e.g. Maria Santos A." required/>
            </div>
        </div>
        <div class="form-grid">
            <div class="fg">
                <label>Role / Sector</label>
                <select name="role" id="addRoleSelect">
                    <option value="teacher" <?= $sectorRole==='teacher'?'selected':'' ?>>Faculty</option>
                    <option value="staff"   <?= $sectorRole==='staff'?'selected':'' ?>>Staff</option>
                </select>
            </div>
            <div class="fg">
                <label>Designation(s)</label>
                <div class="tag-input-wrap" id="tagwrap_add">
                    <div class="tag-list"></div>
                    <div class="tag-input-row">
                        <input type="text" class="tag-text-input" list="desigSuggestions"
                               placeholder="Type a role + Enter"
                               onkeydown="if(event.key==='Enter'){event.preventDefault();addFromInput('tagwrap_add');}"/>
                        <button type="button" class="btn-tag-add" onclick="addFromInput('tagwrap_add')">
                            <i class="fa-solid fa-plus"></i>
                        </button>
                    </div>
                    <input type="hidden" name="designation" class="tag-hidden" value=""/>
                </div>
                <span class="tag-hint">Add one or more roles — e.g. Faculty, Adviser</span>
            </div>
        </div>
        <div class="form-grid full">
            <div class="fg">
                <label>Photo <span style="font-weight:400;text-transform:none;letter-spacing:0;color:var(--text-dim)">(optional)</span></label>
                <input type="file" name="photo" accept="image/*" onchange="previewPhoto(this,'addPreview')"/>
                <div style="margin-top:8px;"><img id="addPreview" class="photo-preview" src="" alt=""/></div>
                <span class="hint">JPG, PNG, WebP — max 10MB</span>
            </div>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn-cancel" onclick="closeModal('addModal')">Cancel</button>
            <button type="submit" class="btn-submit"><i class="fa-solid fa-user-plus"></i> Add &amp; Sync</button>
        </div>
    </form>
</div>
</div>

<!-- EDIT MODAL -->
<div class="modal-overlay" id="editModal">
<div class="modal">
    <div class="modal-head">
        <div>
            <div class="modal-title"><i class="fa-solid fa-pen-to-square" style="color:var(--sector);margin-right:8px"></i>Edit Personnel</div>
            <div class="modal-sub" id="editSub">Update details</div>
        </div>
        <button class="modal-close" onclick="closeModal('editModal')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form method="POST" action="personnel_registry.php?sector=<?= urlencode($viewSector) ?>" enctype="multipart/form-data">
        <input type="hidden" name="action"  value="edit_personnel"/>
        <input type="hidden" name="user_id" id="editUserId"/>
        <div class="form-grid">
            <div class="fg" style="grid-column:1/-1;">
                <label>Full Name</label>
                <input type="text" name="full_name" id="editFullName" required/>
            </div>
        </div>
        <div class="form-grid">
            <div class="fg">
                <label>Role / Sector</label>
                <select name="role" id="editRoleSelect">
                    <option value="teacher">Faculty</option>
                    <option value="staff">Staff</option>
                </select>
            </div>
            <div class="fg">
                <label>Designation(s)</label>
                <div class="tag-input-wrap" id="tagwrap_edit">
                    <div class="tag-list"></div>
                    <div class="tag-input-row">
                        <input type="text" class="tag-text-input" list="desigSuggestions"
                               placeholder="Type a role + Enter"
                               onkeydown="if(event.key==='Enter'){event.preventDefault();addFromInput('tagwrap_edit');}"/>
                        <button type="button" class="btn-tag-add" onclick="addFromInput('tagwrap_edit')">
                            <i class="fa-solid fa-plus"></i>
                        </button>
                    </div>
                    <input type="hidden" name="designation" class="tag-hidden" value=""/>
                </div>
                <span class="tag-hint">Add or remove roles — e.g. Faculty, Adviser, Coordinator</span>
            </div>
        </div>
        <div class="form-grid full">
            <div class="fg">
                <label>Replace Photo <span style="font-weight:400;text-transform:none;letter-spacing:0;color:var(--text-dim)">(leave blank to keep current)</span></label>
                <input type="file" name="photo" accept="image/*" onchange="previewPhoto(this,'editPreview')"/>
                <div style="margin-top:8px;"><img id="editPreview" class="photo-preview" src="" alt=""/></div>
            </div>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn-cancel" onclick="closeModal('editModal')">Cancel</button>
            <button type="submit" class="btn-submit"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button>
        </div>
    </form>
</div>
</div>

<!-- DELETE MODAL -->
<div class="modal-overlay" id="deleteModal">
<div class="modal" style="max-width:420px;">
    <div class="modal-title" style="margin-bottom:8px;">Remove Entry</div>
    <p id="deleteSubText" style="font-size:13px;color:var(--text-dim);margin-bottom:22px;line-height:1.6;"></p>
    <div class="modal-actions">
        <button class="btn-cancel" onclick="closeModal('deleteModal')">Cancel</button>
        <button class="btn-confirm-del" onclick="doDelete()" style="flex:1;">
            <i class="fa-solid fa-trash-can"></i> Yes, Remove
        </button>
    </div>
</div>
</div>

<script>
let _deleteUrl = '';

/* ── TEACHER/STAFF TAG SYSTEM ──────────────────────────────────────
   Each .tag-input-wrap holds:
     - .tag-list      (rendered chips)
     - .tag-text-input (typed entry, Enter or + button to add)
     - .tag-hidden    (hidden input, comma-separated values sent on submit)
*/
function escapeHtml(s) {
    return String(s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function getTags(wrapId) {
    const hidden = document.querySelector('#' + wrapId + ' .tag-hidden');
    return hidden.value.split(',').map(t => t.trim()).filter(t => t !== '');
}

function setTags(wrapId, tags) {
    // de-duplicate (case-insensitive) while preserving original casing of first occurrence
    const seen = new Set();
    const unique = [];
    tags.forEach(t => {
        const key = t.toLowerCase();
        if (!seen.has(key)) { seen.add(key); unique.push(t); }
    });

    const hidden = document.querySelector('#' + wrapId + ' .tag-hidden');
    hidden.value = unique.join(', ');
    renderTags(wrapId);
}

function renderTags(wrapId) {
    const wrap = document.getElementById(wrapId);
    const list = wrap.querySelector('.tag-list');
    const tags = getTags(wrapId);

    if (tags.length === 0) {
        list.innerHTML = '<span class="tag-empty-hint">No role assigned yet</span>';
        return;
    }
    list.innerHTML = tags.map((t, i) => `
        <span class="tag-chip">
            ${escapeHtml(t)}
            <span class="tag-remove" onclick="removeTag('${wrapId}', ${i})" title="Remove">
                <i class="fa-solid fa-xmark"></i>
            </span>
        </span>
    `).join('');
}

function addFromInput(wrapId) {
    const wrap  = document.getElementById(wrapId);
    const input = wrap.querySelector('.tag-text-input');
    const val   = input.value.trim();
    if (!val) return;
    const tags = getTags(wrapId);
    tags.push(val);
    setTags(wrapId, tags);
    input.value = '';
    input.focus();
}

function removeTag(wrapId, idx) {
    const tags = getTags(wrapId);
    tags.splice(idx, 1);
    setTags(wrapId, tags);
}

/* Initial render of all tag widgets on page load */
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.tag-input-wrap').forEach(w => renderTags(w.id));
});

/* ── Table search/filter ── */
function filterTable() {
    const q  = document.getElementById('searchInput').value.toLowerCase();
    const st = document.getElementById('statusFilter').value;
    const dg = document.getElementById('desigFilter').value;
    document.querySelectorAll('#mainTable tbody tr[data-name]').forEach(row => {
        const nm = row.dataset.name.includes(q) || row.dataset.desig.includes(q);
        const sm = st === 'all' || row.dataset.status === st;
        const dm = dg === 'all' || row.dataset.desig.includes(dg);
        row.style.display = nm && sm && dm ? '' : 'none';
    });
}

/* ── Modals ── */
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(el =>
    el.addEventListener('click', e => { if (e.target === el) closeModal(el.id); })
);

function previewPhoto(input, previewId) {
    const img = document.getElementById(previewId);
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => { img.src = e.target.result; img.classList.add('visible'); };
        reader.readAsDataURL(input.files[0]);
    }
}

function openAddModal() {
    document.getElementById('addPreview').classList.remove('visible');
    setTags('tagwrap_add', []);
    openModal('addModal');
}

function openEditModal(u) {
    document.getElementById('editUserId').value    = u.id;
    document.getElementById('editFullName').value  = u.full_name;
    document.getElementById('editRoleSelect').value = u.role;
    document.getElementById('editSub').textContent  = 'Editing: ' + u.full_name;
    document.getElementById('editPreview').classList.remove('visible');

    const existing = (u.designation || '').split(',').map(t => t.trim()).filter(t => t !== '');
    setTags('tagwrap_edit', existing);

    openModal('editModal');
}

function confirmDelete(id, name) {
    document.getElementById('deleteSubText').textContent =
        `Remove "${name}" from the registry? They will no longer appear in the questionnaire.`;
    _deleteUrl = `personnel_registry.php?sector=<?= urlencode($viewSector) ?>&delete_id=${id}`;
    openModal('deleteModal');
}

function doDelete() { if (_deleteUrl) window.location.href = _deleteUrl; }
</script>

<?php $mysqli->close(); ?>
</body>
</html>