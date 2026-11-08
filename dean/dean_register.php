<?php
// dean/dean_register.php
// Place this file inside: htdocs/index/dean/
session_start();
require_once 'db.php';
require_once 'security.php';   // security-question helpers (password recovery)
security_ensure_tables($mysqli);
$sq_all  = sq_questions();
$sq_rows = [];

// ── REGISTRATION ENABLED ──────────────────────────────────────
// Match the enabled registration behavior used by the main/admin
// registration page. An older database may still contain
// superadmin_reg_open=0, but Dean registration remains available.
$reg_open = 1;

$tbl_check = $mysqli->query("SHOW TABLES LIKE 'system_settings'");
if ($tbl_check && $tbl_check->num_rows > 0) {
    $flag = $mysqli->query("SELECT setting_value FROM system_settings WHERE setting_key='superadmin_reg_open' LIMIT 1");
    if ($flag && $flag->num_rows > 0) {
        // Intentionally keep Dean registration enabled in this build.
        $reg_open = 1;
    }
}

if (!$reg_open) {
    http_response_code(403);
    die('Registration is closed. Contact your System Administrator.');
}

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
        $error = 'Session expired. Please refresh and try again.';
    } else {
        $fullName = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($fullName) || empty($username) || empty($password)) {
            $error = 'Full name, username, and password are required.';
        } elseif (mb_strlen($fullName) > 100 || mb_strlen($username) > 50) {
            $error = 'Full name or username is too long.';
        } elseif (preg_match('/\s/', $username)) {
            $error = 'Username cannot contain spaces.';
        } elseif ($password !== $confirmPassword) {
            $error = 'Passwords do not match.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } else {
            // Security questions (used for password recovery)
            [$sqErr, $sq_rows] = sq_validate($_POST['sq_question'] ?? [], $_POST['sq_answer'] ?? [], $username);
            if ($sqErr !== null) {
                $error = $sqErr;
            } else {
                $chk = $mysqli->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
                if ($chk) {
                    $chk->bind_param("s", $username);
                    $chk->execute();
                    $chk->store_result();
                    if ($chk->num_rows > 0) {
                        $error = 'Username is already taken.';
                    }
                    $chk->close();
                } else {
                    $error = 'Unable to validate the account details. Please try again.';
                }
            }
        }
        // Required profile photo.
        $photo_filename = null;
        if (empty($error) && (!isset($_FILES['photo']) || $_FILES['photo']['error'] === UPLOAD_ERR_NO_FILE)) {
            $error = 'Profile photo is required.';
        }
        if (empty($error) && isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
                $error = 'Failed to upload profile photo.';
            } else {
                $allowed = ['image/jpeg','image/png','image/webp','image/gif'];
                $file_type = mime_content_type($_FILES['photo']['tmp_name']);
                $file_size = $_FILES['photo']['size'];
                $ext_map   = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

                if (!in_array($file_type, $allowed, true)) {
                    $error = 'Profile photo must be JPG, PNG, WebP, or GIF.';
                } elseif ($file_size > 10 * 1024 * 1024) {
                    $error = 'Profile photo must be under 10 MB.';
                } elseif (@getimagesize($_FILES['photo']['tmp_name']) === false) {
                    $error = 'Profile photo is not a valid image.';
                } else {
                    // Extension comes from the detected MIME type, not the uploaded filename.
                    $photo_filename = 'usr_' . bin2hex(random_bytes(12)) . '.' . $ext_map[$file_type];
                    if (!is_dir(UPLOAD_DIR)) {
                        mkdir(UPLOAD_DIR, 0755, true);
                    }
                    if (!move_uploaded_file($_FILES['photo']['tmp_name'], UPLOAD_DIR . $photo_filename)) {
                        $error = 'Failed to save photo. Check folder permissions on /image/.';
                        $photo_filename = null;
                    }
                }
            }
        }

        if (empty($error)) {
            $result = ems_register_account($mysqli, 'dean', [
                'full_name'   => $fullName,
                'employee_id' => '',
                'username'    => $username,
                'email'       => '',   // email is no longer collected
                'password'    => $password,
                'department'  => '',
                'course'      => '',
                'designation' => '',
            ]);

            if ($result['ok']) {
                // Save the security answers for the account that was just created.
                // If that fails, remove the new account so nobody is left without recovery questions.
                try {
                    $idStmt = $mysqli->prepare("SELECT id FROM users WHERE username = ? AND role = 'dean' ORDER BY id DESC LIMIT 1");
                    $idStmt->bind_param("s", $username);
                    $idStmt->execute();
                    $newRow = $idStmt->get_result()->fetch_assoc();
                    $idStmt->close();
                    if (!$newRow) { throw new RuntimeException('new dean account not found'); }
                    $newId = (int)$newRow['id'];
                    $mysqli->begin_transaction();
                    sq_save($mysqli, $newId, $sq_rows);
                    $mysqli->commit();
                } catch (Throwable $e) {
                    try { $mysqli->rollback(); } catch (Throwable $ignored) {}
                    error_log('dean_register.php security answers failed: ' . $e->getMessage());
                    if (!empty($newId)) {
                        $del = $mysqli->prepare("DELETE FROM users WHERE id = ? AND role = 'dean' LIMIT 1");
                        $del->bind_param("i", $newId);
                        $del->execute();
                        $del->close();
                    }
                    if ($photo_filename && is_file(UPLOAD_DIR . $photo_filename)) { @unlink(UPLOAD_DIR . $photo_filename); }
                    $result = ['ok' => false, 'error' => 'Registration failed. Please try again in a moment.'];
                }
            }

            if ($result['ok']) {
                if ($photo_filename) {
                    $photoStmt = $mysqli->prepare("UPDATE users SET photo = ? WHERE username = ? AND role = 'dean' LIMIT 1");
                    if ($photoStmt) {
                        $photoStmt->bind_param("ss", $photo_filename, $username);
                        $photoStmt->execute();
                        $photoStmt->close();
                    }
                }
                $success = true;
            } else {
                if ($photo_filename && is_file(UPLOAD_DIR . $photo_filename)) {
                    @unlink(UPLOAD_DIR . $photo_filename);
                }
                $error = $result['error'];
            }
        }
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>PBI — Dean Registration</title>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<style>
:root{
    --dark:#0A192F;
    --dark-blue:#0A192F;
    --mid:#172A45;
    --inner:#0F1F3D;
    --violet:#7C5FD9;
    --violet-h:#9C85F0;
    --violet-soft:#C4B5FD;
    --light:#E0E6F0;
    --muted:#A0B3C6;
    --line:rgba(255,255,255,.09);
    --danger:#F87171;
    --success:#4ADE80;
    --radius:10px;
    --shadow:0 8px 32px rgba(0,0,0,.45);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
html{min-height:100%;background:var(--dark-blue);}
body{
    min-height:100vh;
    background:var(--dark-blue);
    color:var(--light);
    font-family:'DM Sans',sans-serif;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:24px;
    position:relative;
    overflow-x:hidden;
}
.bg-grid{
    position:fixed;
    inset:0;
    z-index:0;
    pointer-events:none;
    background-image:
        repeating-linear-gradient(45deg,rgba(124,95,217,.075) 0,rgba(124,95,217,.075) 1px,transparent 1px,transparent 26px),
        repeating-linear-gradient(-45deg,rgba(124,95,217,.055) 0,rgba(124,95,217,.055) 1px,transparent 1px,transparent 26px);
}
.bg-glow{display:none;}
.hex-deco{position:fixed;z-index:0;pointer-events:none;opacity:.5;}
.hex-1{top:-60px;left:-60px;}
.hex-2{bottom:-70px;right:-70px;}

.reg-card{
    position:relative;z-index:10;
    width:min(100%,580px);
    background:rgba(23,42,69,.82);
    border:1px solid rgba(255,255,255,.10);
    border-radius:20px;
    box-shadow:var(--shadow);
    backdrop-filter:blur(18px);
    -webkit-backdrop-filter:blur(18px);
    padding:30px 36px 24px;
    animation:cardIn .65s cubic-bezier(.22,1,.36,1) both;
}
@keyframes cardIn{from{opacity:0;transform:translateY(24px) scale(.98)}to{opacity:1;transform:none}}
.card-header{text-align:center;margin-bottom:18px;}
.logo-ring{
    width:62px;height:62px;display:block;object-fit:cover;margin:0 auto 15px;
    border-radius:50%;border:2px solid var(--violet);
    box-shadow:0 0 22px rgba(124,95,217,.40);
}
.card-title{font:700 24px 'Rajdhani',sans-serif;letter-spacing:2px;text-transform:uppercase;color:#fff;}
.card-subtitle{font-size:10.5px;color:var(--muted);letter-spacing:1.15px;text-transform:uppercase;margin-top:4px;}
.role-pill{
    display:inline-flex;align-items:center;gap:6px;margin-top:8px;
    padding:4px 11px;border-radius:999px;
    background:rgba(124,95,217,.12);border:1px solid rgba(124,95,217,.30);
    color:var(--violet-h);font-size:10px;font-weight:700;letter-spacing:.75px;text-transform:uppercase;
}
.divider{height:1px;background:linear-gradient(90deg,transparent,rgba(124,95,217,.32),transparent);margin-bottom:16px;}
.alert{display:flex;align-items:flex-start;gap:10px;border-radius:10px;padding:12px 14px;font-size:12px;line-height:1.5;margin-bottom:18px;}
.alert i{margin-top:2px;}
.alert-error{background:rgba(248,113,113,.10);border:1px solid rgba(248,113,113,.30);color:#FF9A9A;}
.alert-success{background:rgba(34,197,94,.10);border:1px solid rgba(34,197,94,.28);color:#86efac;}

.section-head{display:none;}
.section-title{font:700 17px 'Rajdhani',sans-serif;letter-spacing:1.2px;text-transform:uppercase;color:var(--violet-h);display:flex;align-items:center;gap:8px;}
.section-sub{font-size:11px;color:var(--muted);line-height:1.45;margin-top:3px;}
.required-note{font-size:10px;color:var(--muted);white-space:nowrap;}
.req{color:#FF9696;font-weight:700;}

.photo-box{
    display:grid;grid-template-columns:auto 1fr;align-items:center;gap:13px;
    padding:11px 12px;border:1px solid var(--line);border-radius:10px;
    background:rgba(10,25,47,.34);margin-bottom:16px;
}
.photo-preview{
    width:68px;height:68px;border-radius:50%;
    border:2px dashed rgba(124,95,217,.52);
    background:var(--inner);display:flex;align-items:center;justify-content:center;
    overflow:hidden;cursor:pointer;transition:.2s;flex-shrink:0;
}
.photo-preview:hover{border-color:var(--violet-h);box-shadow:0 0 0 4px rgba(124,95,217,.10);}
.photo-preview img{display:none;width:100%;height:100%;object-fit:cover;}
.ph-icon{font-size:23px;color:var(--muted);transition:.2s;}
.photo-preview:hover .ph-icon{color:var(--violet-h);}
.photo-copy strong{display:block;color:#fff;font-size:12.5px;margin-bottom:3px;}
.photo-copy p{color:var(--muted);font-size:10px;line-height:1.45;max-width:430px;}
.photo-actions{display:flex;gap:7px;flex-wrap:wrap;margin-top:7px;}
.btn-photo,.btn-clear-photo{
    border-radius:7px;padding:7px 10px;font:600 10.5px 'DM Sans',sans-serif;cursor:pointer;
    display:inline-flex;align-items:center;gap:6px;transition:.2s;
}
.btn-photo{background:rgba(124,95,217,.13);border:1px solid rgba(124,95,217,.34);color:var(--violet-h);}
.btn-photo:hover{background:rgba(124,95,217,.20);border-color:rgba(124,95,217,.55);}
.btn-clear-photo{background:transparent;border:1px solid var(--line);color:var(--muted);}
.btn-clear-photo:hover{border-color:rgba(255,255,255,.18);color:var(--light);}
input[type=file]{display:none;}

.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px 12px;}
.full{grid-column:1/-1;}
.form-group{display:flex;flex-direction:column;gap:5px;}
.form-label{font-size:10px;font-weight:700;letter-spacing:1.15px;text-transform:uppercase;color:var(--muted);}
.input-wrap{position:relative;}
.f-icon{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:13px;pointer-events:none;transition:color .2s;}
.form-input{
    width:100%;min-height:43px;padding:10px 40px 10px 36px;
    background:rgba(10,25,47,.68);border:1px solid rgba(255,255,255,.11);border-radius:var(--radius);
    color:var(--light);font:500 13px 'DM Sans',sans-serif;outline:none;
    transition:border-color .2s,box-shadow .2s,background .2s;
}
.form-input:hover{background:rgba(10,25,47,.76);}
.form-input:focus{border-color:var(--violet);background:rgba(10,25,47,.80);box-shadow:0 0 0 3px rgba(124,95,217,.18);}
.input-wrap:focus-within .f-icon{color:var(--violet-h);}
.form-input::placeholder{color:rgba(160,179,198,.40);}
.toggle-pw{position:absolute;right:12px;top:50%;transform:translateY(-50%);border:0;background:none;color:var(--muted);cursor:pointer;padding:4px;font-size:13px;}
.toggle-pw:hover{color:var(--violet-h);}
.field-help{font-size:10px;color:var(--muted);line-height:1.45;}

.pw-strength{margin-top:7px;display:none;}
.strength-bar{height:4px;background:rgba(255,255,255,.08);border-radius:99px;overflow:hidden;}
.strength-fill{height:100%;width:0;border-radius:99px;transition:width .25s,background .25s;}
.strength-meta{display:flex;justify-content:space-between;gap:8px;margin-top:5px;font-size:10px;color:var(--muted);}
#strengthLabel{font-weight:700;}
.match-note{display:none;font-size:10px;margin-top:6px;font-weight:600;}
.match-note.ok{display:block;color:#6EE7A0;}
.match-note.bad{display:block;color:#FF9A9A;}

 .form-note{
    margin-top:14px;padding:9px 11px;border-radius:9px;
    background:rgba(124,95,217,.07);border:1px solid rgba(124,95,217,.18);
    color:var(--muted);font-size:11px;line-height:1.5;display:flex;gap:9px;align-items:flex-start;
}
.form-note i{color:var(--violet-h);margin-top:2px;}

.btn-register{
    width:100%;min-height:46px;margin-top:14px;padding:11px 14px;
    border:0;border-radius:var(--radius);background:var(--violet);color:#fff;
    font:700 14px 'DM Sans',sans-serif;letter-spacing:.3px;cursor:pointer;
    display:flex;align-items:center;justify-content:center;gap:9px;
    box-shadow:0 7px 20px rgba(124,95,217,.30);transition:.2s;
}
.btn-register:hover{background:var(--violet-h);transform:translateY(-1px);box-shadow:0 10px 28px rgba(124,95,217,.38);}
.btn-register:disabled{opacity:.72;cursor:wait;transform:none;}
.card-footer{text-align:center;margin-top:14px;padding-top:12px;border-top:1px solid rgba(255,255,255,.07);font-size:12px;color:var(--muted);line-height:1.6;}
.card-footer a{color:var(--violet-h);font-weight:700;text-decoration:none;}
.card-footer a:hover{text-decoration:underline;}
 .secure-badge{display:inline-flex;align-items:center;gap:5px;font-size:9.5px;color:#8FA4B8;margin-top:7px;}
.secure-badge i{color:var(--success);font-size:9px;}
.success-panel{text-align:center;padding:8px 4px 4px;}
.success-icon{width:62px;height:62px;border-radius:50%;margin:0 auto 14px;display:flex;align-items:center;justify-content:center;background:rgba(74,222,128,.10);border:1px solid rgba(74,222,128,.25);color:var(--success);font-size:27px;}
.success-title{font:700 22px 'Rajdhani',sans-serif;letter-spacing:1px;color:#fff;text-transform:uppercase;margin-bottom:6px;}
.success-text{font-size:12px;color:var(--muted);line-height:1.6;max-width:500px;margin:0 auto;}
.success-actions{display:flex;justify-content:center;gap:10px;margin-top:18px;}
.back-btn{display:inline-flex;align-items:center;gap:7px;padding:10px 15px;border-radius:9px;background:rgba(124,95,217,.13);border:1px solid rgba(124,95,217,.28);color:var(--violet-h);text-decoration:none;font-size:12px;font-weight:700;}
.back-btn:hover{background:rgba(124,95,217,.20);}

@media(max-width:700px){
    body{padding:20px 12px;align-items:flex-start;}
    .reg-card{padding:28px 18px 22px;margin:10px 0;}
    .form-grid{grid-template-columns:1fr;gap:14px;}
    .full{grid-column:auto;}
    .section-head{display:block;}
    .required-note{display:block;margin-top:4px;}
    .photo-box{grid-template-columns:auto 1fr;align-items:start;}
    .hex-1,.hex-2{opacity:.24;}
}
@media(max-width:480px){
    .card-title{font-size:23px;}
    .card-subtitle{font-size:10px;}
    .logo-ring{width:66px;height:66px;}
    .photo-box{grid-template-columns:1fr;text-align:center;justify-items:center;}
    .photo-copy p{max-width:320px;}
    .photo-actions{justify-content:center;}
}
@media(prefers-reduced-motion:reduce){.reg-card{animation:none}.btn-register,.photo-preview{transition:none;}}

/* Compact registration layout — keeps the Dean theme while reducing visual footprint */
.reg-card{width:min(100%,620px);padding:28px 30px 20px;border-radius:18px;}
.card-header{margin-bottom:18px;}
.logo-ring{width:62px;height:62px;margin-bottom:10px;}
.card-title{font-size:24px;letter-spacing:1.7px;}
.card-subtitle{font-size:11px;letter-spacing:1px;}
.role-pill{margin-top:8px;padding:4px 11px;font-size:10px;}
.divider{margin-bottom:18px;}
.alert{padding:10px 12px;font-size:11px;margin-bottom:14px;}
.section-head{margin-bottom:9px;}
.section-title{font-size:15px;letter-spacing:1px;}
.section-sub{font-size:10px;}
.photo-box{gap:12px;padding:11px 12px;border-radius:12px;margin-bottom:16px;}
.photo-preview{width:70px;height:70px;}
.photo-copy strong{font-size:12px;margin-bottom:3px;}
.photo-copy p{font-size:10px;line-height:1.4;}
.photo-actions{margin-top:7px;}
.btn-photo,.btn-clear-photo{padding:7px 10px;font-size:10px;}
.form-grid{gap:12px 12px;}
.form-group{gap:5px;}
.form-label{font-size:10px;letter-spacing:1px;}
.form-input{min-height:44px;padding:10px 40px 10px 37px;font-size:12px;}
.f-icon{left:13px;font-size:12px;}
.toggle-pw{right:10px;font-size:12px;}
.field-help{font-size:9px;}
.pw-strength{margin-top:5px;}
.strength-bar{height:3px;}
.strength-meta,.match-note{font-size:9px;}
.form-note{margin-top:14px;padding:9px 11px;font-size:10px;gap:8px;}
.btn-register{min-height:48px;margin-top:14px;padding:11px 14px;font-size:14px;}
.card-footer{margin-top:14px;padding-top:12px;font-size:11px;}
.secure-badge{font-size:9px;margin-top:5px;}

@media(max-width:700px){
  .reg-card{width:min(100%,560px);padding:24px 18px 18px;}
}
@media(max-width:480px){
  .reg-card{padding:22px 16px 16px;}
  .logo-ring{width:58px;height:58px;}
  .card-title{font-size:22px;}
}


/* Final compact Dean registration pass */
.reg-card{
    width:min(100%,540px);
    padding:20px 22px 16px;
    border-radius:16px;
}
.card-header{margin-bottom:12px;}
.logo-ring{width:52px;height:52px;margin-bottom:7px;}
.card-title{font-size:21px;letter-spacing:1.45px;}
.card-subtitle{font-size:9.5px;letter-spacing:.85px;margin-top:2px;}
.role-pill{margin-top:6px;padding:3px 9px;font-size:9px;gap:5px;}
.divider{margin-bottom:12px;}
.alert{padding:8px 10px;font-size:10px;line-height:1.4;margin-bottom:10px;}
.section-head{margin-bottom:7px;gap:8px;}
.section-title{font-size:13px;letter-spacing:.85px;gap:6px;}
.section-sub{font-size:9px;margin-top:2px;line-height:1.35;}
.required-note{font-size:9px;}
.photo-box{gap:10px;padding:9px 10px;border-radius:11px;margin-bottom:12px;}
.photo-preview{width:58px;height:58px;}
.ph-icon{font-size:18px;}
.photo-copy strong{font-size:11px;margin-bottom:2px;}
.photo-copy p{font-size:9px;line-height:1.3;max-width:360px;}
.photo-actions{margin-top:5px;gap:6px;}
.btn-photo,.btn-clear-photo{padding:5px 8px;font-size:9px;border-radius:7px;}
.form-grid{gap:8px 10px;}
.form-group{gap:4px;}
.form-label{font-size:9px;letter-spacing:.9px;}
.form-input{min-height:40px;padding:8px 34px 8px 34px;font-size:11px;border-radius:8px;}
.f-icon{left:11px;font-size:11px;}
.toggle-pw{right:8px;font-size:11px;padding:3px;}
.field-help{font-size:8.5px;}
.pw-strength{margin-top:4px;}
.strength-meta,.match-note{font-size:8.5px;margin-top:4px;}
.strength-bar{height:2px;}
.form-note{margin-top:10px;padding:7px 9px;font-size:9px;line-height:1.35;gap:7px;border-radius:8px;}
.form-note i{margin-top:1px;}
.btn-register{min-height:42px;margin-top:10px;padding:9px 12px;font-size:13px;border-radius:8px;box-shadow:0 5px 14px rgba(124,95,217,.25);}
.card-footer{margin-top:10px;padding-top:9px;font-size:10px;line-height:1.45;}
.secure-badge{font-size:8.5px;margin-top:4px;}
.success-panel{padding:4px 2px 2px;}
.success-icon{width:54px;height:54px;font-size:23px;margin-bottom:10px;}
.success-title{font-size:20px;}
.success-text{font-size:10px;}
.success-actions{margin-top:12px;gap:8px;}
.back-btn{padding:8px 12px;font-size:10px;}

@media(max-width:700px){
    body{padding:14px 10px;align-items:center;}
    .reg-card{width:min(100%,540px);padding:18px 16px 14px;}
    .form-grid{grid-template-columns:1fr 1fr;gap:8px 8px;}
    .full{grid-column:1/-1;}
    .photo-box{grid-template-columns:auto 1fr;align-items:center;}
}
@media(max-width:520px){
    .form-grid{grid-template-columns:1fr;gap:9px;}
    .full{grid-column:auto;}
}
@media(max-width:480px){
    .card-title{font-size:20px;}
    .card-subtitle{font-size:9px;}
    .logo-ring{width:50px;height:50px;}
    .photo-box{grid-template-columns:auto 1fr;text-align:left;justify-items:initial;}
    .photo-copy p{max-width:260px;}
}
/* Natural Dean registration layout — follows the Student Registration composition while retaining Dean violet accents. */
.reg-card{width:min(100%,580px);padding:28px 36px 22px;border-radius:16px;}
.card-header{margin-bottom:16px;}
.logo-ring{width:58px;height:58px;margin-bottom:9px;border-width:2px;box-shadow:0 0 18px rgba(124,95,217,.32);}
.card-title{font-size:23px;letter-spacing:1.55px;}
.card-subtitle{font-size:10.5px;letter-spacing:.95px;margin-top:2px;}
.divider{margin-bottom:14px;}
.section-head{display:none;}
.photo-box{grid-template-columns:auto 1fr;gap:12px;padding:10px 12px;margin-bottom:15px;border-radius:10px;background:rgba(10,25,47,.30);}
.photo-preview{width:64px;height:64px;border-width:2px;}
.ph-icon{font-size:20px;}
.photo-copy strong{font-size:12px;margin-bottom:3px;}
.photo-copy p{font-size:9.5px;line-height:1.35;max-width:390px;}
.photo-actions{margin-top:6px;gap:6px;}
.btn-photo,.btn-clear-photo{padding:6px 9px;font-size:9.5px;border-radius:7px;}
.form-grid{gap:11px 10px;}
.form-group{gap:4px;}
.form-label{font-size:9.5px;letter-spacing:1px;}
.form-input{min-height:43px;padding:10px 38px 10px 35px;font-size:12px;border-radius:8px;}
.f-icon{left:12px;font-size:11.5px;}
.toggle-pw{right:9px;font-size:11.5px;}
.form-note{margin-top:11px;padding:8px 10px;font-size:9.5px;line-height:1.35;border-radius:8px;}
.btn-register{min-height:45px;margin-top:11px;padding:10px 13px;font-size:13.5px;border-radius:8px;}
.card-footer{margin-top:12px;padding-top:10px;font-size:10.5px;line-height:1.5;}
.secure-badge{font-size:9px;margin-top:4px;}
@media(max-width:700px){body{padding:16px 12px;align-items:flex-start;overflow-y:auto;}.reg-card{width:min(100%,580px);padding:24px 18px 18px;margin:auto 0;}}
@media(max-height:760px) and (min-width:701px){body{align-items:flex-start;overflow-y:auto;padding-top:16px;padding-bottom:16px;}.reg-card{margin:auto 0;}}
@media(max-width:520px){.form-grid{grid-template-columns:1fr;gap:10px;}.full{grid-column:auto;}}
@media(max-width:480px){.logo-ring{width:54px;height:54px;}.card-title{font-size:21px;}.photo-box{grid-template-columns:auto 1fr;}.photo-copy p{max-width:250px;}}

/* =====================================================================
   Ambient lighting — same treatment as the Student Portal auth pages
   (student_ui.css), recoloured from amber to the Dean violet.
   ===================================================================== */
html{background:var(--dark-blue);}
body{
    background:
        radial-gradient(circle at 10% 10%, rgba(124,95,217,.16), transparent 32%),
        radial-gradient(circle at 90% 90%, rgba(43,108,176,.12), transparent 30%),
        var(--dark-blue);
    background-attachment:fixed;
}
body::before{
    content:"";
    position:fixed;
    inset:0;
    z-index:0;
    pointer-events:none;
    background:linear-gradient(180deg,rgba(255,255,255,.025),transparent 26%,rgba(0,0,0,.12));
}
.bg-glow{display:none;}

.login-card,.reg-card{
    border-color:rgba(255,255,255,.12);
    box-shadow:0 24px 70px rgba(0,0,0,.36),0 0 0 1px rgba(255,255,255,.02),0 0 90px rgba(124,95,217,.09);
    transition:border-color .3s ease,box-shadow .3s ease;
}
.login-card:hover,.reg-card:hover{
    border-color:rgba(156,133,240,.30);
    box-shadow:0 24px 70px rgba(0,0,0,.36),0 0 0 1px rgba(255,255,255,.02),0 0 100px rgba(124,95,217,.15);
}
.form-input:hover:not(:focus){border-color:rgba(156,133,240,.42);}

button:focus-visible,a:focus-visible,.photo-preview:focus-visible{
    outline:3px solid rgba(156,133,240,.38);
    outline-offset:2px;
}
.btn-login:active,.btn-register:active{transform:translateY(1px);}

@media(prefers-reduced-motion:reduce){
    .login-card,.reg-card{transition:none;}
}

/* =====================================================================
   Card lighting — same treatment as the Faculty / Staff registration
   pages (lit top edge, soft vertical gradient, inner highlight, glowing
   logo, inset fields, glossy button), kept in the Dean violet.
   ===================================================================== */
:root{--violet-edge:#C4B5FD;--input-bg:#0B1A32;--input-bg-hover:#0D2038;}
html{background:var(--dark-blue);color-scheme:dark;}
body{
    background:
        radial-gradient(circle at 50% 36%,rgba(255,255,255,.028),transparent 34%),
        radial-gradient(circle at 88% 82%,rgba(43,108,176,.11),transparent 30%),
        radial-gradient(circle at 8% 12%,rgba(124,95,217,.10),transparent 26%),
        var(--dark-blue);
    background-attachment:fixed;
}
body::before{display:none;}
.bg-grid{
    background-image:
        repeating-linear-gradient(45deg,rgba(124,95,217,.08) 0,rgba(124,95,217,.08) 1px,transparent 1px,transparent 26px),
        repeating-linear-gradient(-45deg,rgba(124,95,217,.05) 0,rgba(124,95,217,.05) 1px,transparent 1px,transparent 26px);
}
.hex-deco{opacity:.46;filter:drop-shadow(0 0 10px rgba(124,95,217,.05));}

/* Card: gradient body, lit top edge, inner highlight, violet halo */
.reg-card{
    background:linear-gradient(180deg,#1D3555 0%,#172A45 38%,#122238 100%);
    border:1px solid rgba(124,95,217,.30);
    box-shadow:
        0 30px 80px rgba(0,0,0,.50),
        0 0 0 1px rgba(124,95,217,.08),
        inset 0 1px 0 rgba(255,255,255,.07),
        0 0 60px rgba(124,95,217,.07);
}
.reg-card::before{
    content:"";position:absolute;top:-1px;left:14%;right:14%;height:2px;border-radius:2px;pointer-events:none;
    background:linear-gradient(90deg,transparent,rgba(196,181,253,.95),rgba(43,108,176,.85),transparent);
}
.reg-card:hover{
    border-color:rgba(156,133,240,.45);
    box-shadow:
        0 30px 80px rgba(0,0,0,.55),
        0 0 0 1px rgba(124,95,217,.10),
        inset 0 1px 0 rgba(255,255,255,.07),
        0 0 80px rgba(124,95,217,.11);
}

/* Header: ringed, glowing logo + stronger divider */
.logo-ring{
    border:2px solid var(--violet);
    box-shadow:0 0 0 4px rgba(124,95,217,.12),0 0 24px rgba(124,95,217,.36);
}
.divider{background:linear-gradient(90deg,transparent,rgba(124,95,217,.55),transparent);}

/* Photo panel */
.photo-box{
    background:linear-gradient(135deg,rgba(124,95,217,.12),rgba(10,27,46,.80) 55%);
    border:1px solid rgba(160,179,198,.18);
    box-shadow:inset 0 1px 0 rgba(255,255,255,.04),0 8px 22px rgba(0,0,0,.22);
}
.photo-preview{
    border-color:rgba(124,95,217,.6);
    box-shadow:0 0 0 5px rgba(124,95,217,.08),inset 0 2px 8px rgba(0,0,0,.4);
}
.photo-preview:hover{
    border-color:var(--violet-h);
    box-shadow:0 0 0 5px rgba(124,95,217,.16),inset 0 2px 8px rgba(0,0,0,.4);
}
.photo-preview:hover .ph-icon{color:#fff;}
.btn-photo{background:rgba(124,95,217,.15);border-color:rgba(124,95,217,.42);color:var(--violet-edge);}
.btn-photo:hover{background:rgba(124,95,217,.26);border-color:rgba(156,133,240,.6);transform:translateY(-1px);}

/* Fields: inset depth, hover lift, glowing focus ring */
.form-input{
    background:var(--input-bg);
    border-color:rgba(255,255,255,.12);
    box-shadow:inset 0 2px 6px rgba(0,0,0,.30);
}
.form-input:hover:not(:focus){
    background:var(--input-bg-hover);
    border-color:rgba(255,255,255,.20);
}
.form-input:focus{
    background:var(--input-bg-hover);
    border-color:var(--violet);
    box-shadow:inset 0 2px 6px rgba(0,0,0,.22),0 0 0 3px rgba(124,95,217,.22);
}
.input-wrap:focus-within .f-icon{color:var(--violet-edge);}
.toggle-pw:hover{color:#fff;}
.strength-bar{background:rgba(255,255,255,.09);box-shadow:inset 0 1px 2px rgba(0,0,0,.4);}

/* Info note + button + footer */
.form-note{background:rgba(124,95,217,.08);border-color:rgba(124,95,217,.22);}
.btn-register{
    background:linear-gradient(180deg,#9078E8 0%,#7C5FD9 55%,#6A4FC8 100%);
    box-shadow:inset 0 1px 0 rgba(255,255,255,.24),0 10px 26px rgba(124,95,217,.36);
    transition:transform .15s,box-shadow .2s,filter .2s;
}
.btn-register:hover{
    background:linear-gradient(180deg,#9078E8 0%,#7C5FD9 55%,#6A4FC8 100%);
    filter:brightness(1.08);transform:translateY(-1px);
    box-shadow:inset 0 1px 0 rgba(255,255,255,.28),0 14px 32px rgba(124,95,217,.44);
}
.btn-register:active{transform:translateY(1px);}
.btn-register:disabled{filter:none;opacity:.72;}
.card-footer{border-top-color:rgba(255,255,255,.08);}
.card-footer a{color:var(--violet-h);}
.back-btn{background:rgba(124,95,217,.15);border-color:rgba(124,95,217,.34);color:var(--violet-edge);}

/* ── Security questions (collapsible panel) ── */
.sq-section{margin-top:14px;border:1px solid rgba(160,179,198,.18);border-radius:12px;background:linear-gradient(135deg,rgba(124,95,217,.12),rgba(10,27,46,.80) 55%);box-shadow:inset 0 1px 0 rgba(255,255,255,.04),0 8px 22px rgba(0,0,0,.22);overflow:hidden}
.sq-section[open]{border-color:rgba(124,95,217,.45)}
.sq-head{list-style:none;display:flex;align-items:center;gap:10px;width:100%;padding:13px 14px;cursor:pointer;font-weight:700;font-size:13.5px;letter-spacing:1.2px;text-transform:uppercase;color:#fff;user-select:none}
.sq-head::-webkit-details-marker{display:none}
.sq-head::after{content:'\f078';font-family:'Font Awesome 6 Free';font-weight:900;color:var(--muted);font-size:11px;margin-left:auto;transition:transform .2s ease}
.sq-section[open] .sq-head::after{transform:rotate(180deg)}
.sq-head i{color:var(--violet-h);font-size:14px}
.sq-head-text{display:flex;flex-direction:column;gap:2px;min-width:0}
.sq-head-subtitle{font-size:10.5px;font-weight:500;letter-spacing:.15px;text-transform:none;color:var(--muted);line-height:1.35}
.sq-content{padding:0 14px 4px}
.sq-note{font-size:11.5px;line-height:1.55;color:var(--muted);margin:0 0 12px}
.sq-item{display:flex;flex-direction:column;gap:6px;margin-bottom:12px}
.sq-item .form-input{padding:10px 13px}
.sq-item .sq-select{appearance:none;-webkit-appearance:none;cursor:pointer;padding-right:34px;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23A0B3C6' stroke-width='3'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
    background-repeat:no-repeat;background-position:right 13px center}
.sq-select option{background:#0B1A32;color:#E0E6F0}
.sq-select option:disabled{color:#7b8ea3}
.sq-chosen{font-size:12.5px;line-height:1.5;color:var(--light);padding:0 2px}
.sq-chosen:empty{display:none}
summary:focus-visible{outline:3px solid rgba(156,133,240,.40);outline-offset:2px}
</style>
</head>
<body>
<div class="bg-grid" aria-hidden="true"></div>
<div class="bg-glow bg-glow-1" aria-hidden="true"></div>
<div class="bg-glow bg-glow-2" aria-hidden="true"></div>

<svg class="hex-deco hex-1" width="260" height="260" viewBox="0 0 260 260" aria-hidden="true">
    <polygon points="130,10 240,70 240,190 130,250 20,190 20,70" fill="none" stroke="#7C5FD9" stroke-width="1"/>
    <polygon points="130,50 200,90 200,170 130,210 60,170 60,90" fill="none" stroke="#7C5FD9" stroke-width="1"/>
</svg>
<svg class="hex-deco hex-2" width="300" height="300" viewBox="0 0 300 300" aria-hidden="true">
    <polygon points="150,10 280,80 280,220 150,290 20,220 20,80" fill="none" stroke="#2B6CB0" stroke-width="1"/>
    <polygon points="150,60 220,100 220,200 150,240 80,200 80,100" fill="none" stroke="#2B6CB0" stroke-width="1"/>
</svg>

<div class="reg-card">
  <div class="card-header">
    <img class="logo-ring" src="../image/pbi_logo" alt="PBI Logo"/>
    <?php if (!$success): ?><div class="card-title">Dean Registration</div><?php endif; ?>
    <div class="card-subtitle">Employee Performance Evaluation &amp; Management System</div>
    <?php if ($success): ?><div class="role-pill"><i class="fa-solid fa-user-tie"></i> Dean Access</div><?php endif; ?>
  </div>
  <div class="divider"></div>

  <?php if ($success): ?>
    <div class="alert alert-success">
      <i class="fa-solid fa-circle-check"></i>
      <span>Registration submitted successfully. Your account is <strong>pending administrator approval</strong>. You will be able to log in once your account is approved.</span>
    </div>
    <div class="card-footer" style="border-top:none;margin-top:8px;">
      <a href="dean_login.php">Back to Login</a>
    </div>
  <?php else: ?>
    <?php if ($error): ?>
      <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><span><?= htmlspecialchars($error) ?></span></div>
    <?php endif; ?>

    <form method="POST" action="dean_register.php" enctype="multipart/form-data" id="regForm" autocomplete="off" onsubmit="return validateRegistration()">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

      <div class="photo-box">
        <div class="photo-preview" id="photoPreview" onclick="document.getElementById('photoFile').click()" role="button" tabindex="0" aria-label="Choose profile photo">
          <img id="photoImg" src="" alt="Profile photo preview"/>
          <i class="fa-solid fa-camera ph-icon" id="phIcon"></i>
        </div>
        <div class="photo-copy">
          <strong>Profile Photo <span class="req">*</span></strong>
          <p>Clear photo for identification. JPG, PNG, WebP, or GIF, up to 10 MB.</p>
          <div class="photo-actions">
            <label class="btn-photo" for="photoFile"><i class="fa-solid fa-upload"></i> Choose Photo</label>
            <button type="button" class="btn-clear-photo" id="clearPhoto" style="display:none;"><i class="fa-solid fa-xmark"></i> Remove</button>
          </div>
          <input type="file" id="photoFile" name="photo" accept="image/jpeg,image/png,image/webp,image/gif" required onchange="previewPhoto(this)"/>
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group full">
          <label class="form-label" for="full_name">Full Name <span class="req">*</span></label>
          <div class="input-wrap">
            <input class="form-input" type="text" id="full_name" name="full_name" placeholder="First Name M.I. Last Name" required autocomplete="name" value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>"/>
            <i class="fa-solid fa-id-card f-icon"></i>
          </div>
        </div>

        <div class="form-group full">
          <label class="form-label" for="username">Username <span class="req">*</span></label>
          <div class="input-wrap">
            <input class="form-input" type="text" id="username" name="username" placeholder="Choose a username" required autocomplete="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"/>
            <i class="fa-solid fa-user f-icon"></i>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="password">Password <span class="req">*</span></label>
          <div class="input-wrap">
            <input class="form-input" type="password" id="password" name="password" value="<?= $error ? htmlspecialchars(is_string($_POST['password'] ?? null) ? $_POST['password'] : '') : '' ?>" placeholder="Minimum 8 characters" required minlength="8" autocomplete="new-password" oninput="checkStrength(this.value); checkMatch();"/>
            <i class="fa-solid fa-lock f-icon"></i>
            <button type="button" class="toggle-pw" onclick="togglePw('password','eye1')" aria-label="Show password"><i class="fa-solid fa-eye-slash" id="eye1"></i></button>
          </div>
          <div class="pw-strength" id="pwStrength">
            <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
            <div class="strength-meta"><span>Password strength</span><span id="strengthLabel"></span></div>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="confirm_password">Confirm Password <span class="req">*</span></label>
          <div class="input-wrap">
            <input class="form-input" type="password" id="confirm_password" name="confirm_password" value="<?= $error ? htmlspecialchars(is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '') : '' ?>" placeholder="Re-enter your password" required minlength="8" autocomplete="new-password" oninput="checkMatch();"/>
            <i class="fa-solid fa-lock f-icon"></i>
            <button type="button" class="toggle-pw" onclick="togglePw('confirm_password','eye2')" aria-label="Show confirmation password"><i class="fa-solid fa-eye-slash" id="eye2"></i></button>
          </div>
          <div class="match-note" id="matchNote"></div>
        </div>
      </div>

      <!-- Security questions (collapsed until clicked, same as the other registration forms) -->
      <details class="sq-section" id="securityQuestions"<?= ($error && isset($_POST['sq_question'])) ? ' open' : '' ?>>
        <summary class="sq-head">
          <i class="fa-solid fa-shield-halved"></i>
          <span class="sq-head-text">
            <span class="sq-head-title">Security Questions</span>
            <span class="sq-head-subtitle">Required for password recovery · Choose 3 questions</span>
          </span>
        </summary>
        <div class="sq-content">
          <p class="sq-note"></p>
          <?php for ($n = 1; $n <= 3; $n++): ?>
          <div class="sq-item">
            <label class="form-label" for="sq_q<?= $n ?>">Question <?= $n ?> <span class="req">*</span></label>
            <select class="form-input sq-select" name="sq_question[<?= $n ?>]" id="sq_q<?= $n ?>" required>
              <option value="" disabled <?= empty($_POST['sq_question'][$n]) ? 'selected' : '' ?>>Choose a question</option>
              <?php foreach ($sq_all as $k => $q): ?>
              <option value="<?= htmlspecialchars($k) ?>" <?= (($_POST['sq_question'][$n] ?? '') === $k) ? 'selected' : '' ?>><?= htmlspecialchars($q) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="sq-chosen" id="sq_chosen<?= $n ?>" aria-live="polite"></div>
            <input class="form-input sq-answer" type="text" name="sq_answer[<?= $n ?>]" id="sq_a<?= $n ?>" maxlength="100" value="<?= $error ? htmlspecialchars(is_string($_POST['sq_answer'][$n] ?? null) ? $_POST['sq_answer'][$n] : '') : '' ?>" placeholder="Your answer" autocomplete="off" required/>
          </div>
          <?php endfor; ?>
        </div>
      </details>


      <button type="submit" class="btn-register" id="registerButton">
        <i class="fa-solid fa-user-plus"></i>
        <span id="regBtnLabel">Create Dean Account</span>
      </button>
    </form>

    <div class="card-footer">
      Already have an account? <a href="dean_login.php">Sign in here</a><br>
      <span class="secure-badge"><i class="fa-solid fa-circle-check"></i> Secured &amp; Encrypted Connection</span>
    </div>
  <?php endif; ?>
</div>

<script>
function togglePw(id, ic){
  const e=document.getElementById(id), i=document.getElementById(ic);
  e.type=e.type==='password'?'text':'password';
  i.className=e.type==='password'?'fa-solid fa-eye-slash':'fa-solid fa-eye';
}
function previewPhoto(input){
  const img=document.getElementById('photoImg'), icon=document.getElementById('phIcon'), clear=document.getElementById('clearPhoto');
  if(input.files&&input.files[0]){
    const file=input.files[0];
    if(file.size>10*1024*1024){ alert('Profile photo must be under 10 MB.'); input.value=''; return; }
    const r=new FileReader();
    r.onload=e=>{img.src=e.target.result;img.style.display='block';icon.style.display='none';clear.style.display='inline-flex';};
    r.readAsDataURL(file);
  }
}
function clearPhoto(){
  const input=document.getElementById('photoFile'), img=document.getElementById('photoImg'), icon=document.getElementById('phIcon'), clear=document.getElementById('clearPhoto');
  input.value='';img.src='';img.style.display='none';icon.style.display='block';clear.style.display='none';
}
function checkStrength(v){
  const wrap=document.getElementById('pwStrength'), bar=document.getElementById('strengthFill'), label=document.getElementById('strengthLabel');
  wrap.style.display=v?'block':'none';
  let s=0;
  if(v.length>=8)s++;
  if(/[A-Z]/.test(v))s++;
  if(/[0-9]/.test(v))s++;
  if(/[^A-Za-z0-9]/.test(v))s++;
  const levels=[
    {w:'25%',bg:'#F87171',lb:'Weak'},
    {w:'50%',bg:'#FB923C',lb:'Fair'},
    {w:'75%',bg:'#FACC15',lb:'Good'},
    {w:'100%',bg:'#4ADE80',lb:'Strong'}
  ];
  if(!s){bar.style.width='0%';label.textContent='';return;}
  const lv=levels[Math.max(0,s-1)];
  bar.style.width=lv.w;bar.style.background=lv.bg;label.textContent=lv.lb;label.style.color=lv.bg;
}
function checkMatch(){
  const pw=document.getElementById('password'), cpw=document.getElementById('confirm_password'), note=document.getElementById('matchNote');
  if(!cpw.value){note.textContent='';note.className='match-note';return;}
  if(pw.value===cpw.value){note.textContent='Passwords match';note.className='match-note ok';}
  else{note.textContent='Passwords do not match';note.className='match-note bad';}
}
function validateRegistration(){
  const form=document.getElementById('regForm');
  for(const field of form.querySelectorAll('[required]')){
    if(field.type==='file' && !field.files.length){ revealSq(field); field.focus(); return false; }
    if(field.type!=='file' && !field.value.trim()){ revealSq(field); field.focus(); return false; }
  }
  const password=document.getElementById('password'), confirmPassword=document.getElementById('confirm_password');
  if(password.value.length<8){password.focus();return false;}
  if(password.value!==confirmPassword.value){confirmPassword.focus();return false;}
  const btn=document.getElementById('registerButton');
  btn.disabled=true;
  btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i><span>Creating Account...</span>';
  return true;
}
document.getElementById('clearPhoto')?.addEventListener('click',clearPhoto);
document.getElementById('photoPreview')?.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();document.getElementById('photoFile').click();}});
checkStrength(document.getElementById('password')?.value||'');
checkMatch();
// Security questions: open the collapsed panel when a field inside it needs attention,
// keep the three dropdowns from repeating, and show the full chosen question.
function revealSq(el){ const b=el&&el.closest?el.closest('.sq-section'):null; if(b) b.open=true; }
(function(){
  const form=document.getElementById('regForm'), box=document.getElementById('securityQuestions');
  if(!form||!box) return;
  form.addEventListener('invalid',function(e){ revealSq(e.target); },true);
  const selects=[1,2,3].map(n=>document.getElementById('sq_q'+n));
  function sync(){
    const chosen=selects.map(s=>s.value).filter(Boolean);
    selects.forEach(function(s,i){
      Array.from(s.options).forEach(function(o){ o.disabled=o.value!==''&&chosen.includes(o.value)&&o.value!==s.value; });
      if(!s.value) s.options[0].disabled=true;
      const el=document.getElementById('sq_chosen'+(i+1));
      if(el) el.textContent=s.value?s.options[s.selectedIndex].text:'';
    });
  }
  selects.forEach(s=>s.addEventListener('change',sync));
  sync();
})();
</script>
<script>
window.addEventListener('DOMContentLoaded', function () {
  var p = document.getElementById('password');
  if (p && p.value) {
    if (typeof checkStrength === 'function') checkStrength(p.value);
    if (typeof checkMatch === 'function') checkMatch();
  }
});
</script>
</body>
</html>