<?php
// principal/principal_register.php
session_start();
require_once 'db.php';
require_once 'security.php';   // security-question helpers (password recovery)
security_ensure_tables($mysqli);
$sq_all  = sq_questions();
$sq_rows = [];

// Profile photos are saved to htdocs/index/image/ (same folder the Dean, Faculty and Staff
// pages use). Only define it here if this package's db.php doesn't already.
if (!defined('UPLOAD_DIR')) {
    define('UPLOAD_DIR', dirname(__DIR__) . '/image/');
}

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        $error = 'Session expired. Please refresh and try again.';
    } else {
        $fullName = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($fullName) || empty($username) || empty($password)) {
            $error = 'Full name, username, and password are required.';
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
                $chk->bind_param("s", $username);
                $chk->execute();
                $chk->store_result();
                if ($chk->num_rows > 0) {
                    $error = 'Username is already taken.';
                }
                $chk->close();
            }
        }

        // Required profile photo (same rules as the Dean registration).
        $photo_filename = null;
        if (empty($error) && (!isset($_FILES['photo']) || $_FILES['photo']['error'] === UPLOAD_ERR_NO_FILE)) {
            $error = 'Profile photo is required.';
        }
        if (empty($error) && isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
                $error = 'Failed to upload profile photo.';
            } else {
                $allowed   = ['image/jpeg','image/png','image/webp','image/gif'];
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
            // Create the Principal account directly here. The previous build
            // called ems_register_account(), but that helper is not included
            // in this Principal package, which caused the fatal error.
            $hash = password_hash($password, PASSWORD_DEFAULT);

            // Keep self-registration compatible with the approval workflow.
            $col = $mysqli->query("SHOW COLUMNS FROM users LIKE 'account_status'");
            if (!$col || $col->num_rows === 0) {
                $mysqli->query("ALTER TABLE users ADD COLUMN account_status VARCHAR(10) NOT NULL DEFAULT 'pending'");
            }

            try {
                // The account and its security answers are saved together (all or nothing).
                $mysqli->begin_transaction();
                $ins = $mysqli->prepare(
                    "INSERT INTO users
                        (full_name, username, password_hash, role, is_active, account_status, source, education_level, photo, created_at)
                     VALUES (?, ?, ?, 'principal', 1, 'pending', 'self_register', 'both', ?, NOW())"
                );
                $ins->bind_param('ssss', $fullName, $username, $hash, $photo_filename);
                $ins->execute();
                $newId = (int)$mysqli->insert_id;
                $ins->close();

                sq_save($mysqli, $newId, $sq_rows);
                $mysqli->commit();
                $success = true;
            } catch (Throwable $e) {
                try { $mysqli->rollback(); } catch (Throwable $ignored) {}
                error_log('principal_register.php failed: ' . $e->getMessage());
                $duplicate = ($e instanceof mysqli_sql_exception && (int)$e->getCode() === 1062);
                $error = $duplicate ? 'Username is already taken.' : 'Registration failed. Please try again in a moment.';
            }
        }

        // Don't leave an orphaned upload behind if the account wasn't created.
        if (!empty($error) && $photo_filename && is_file(UPLOAD_DIR . $photo_filename)) {
            @unlink(UPLOAD_DIR . $photo_filename);
        }
    }
}

$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>PBI — Principal Registration</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<style>
:root{
    --navy:#0A192F;
    --amber:#D99A2B;
    --amber-hover:#F0B84D;
    --blue:#2B6CB0;
    --light:#E0E6F0;
    --muted:#A0B3C6;
    --input-bg:#0B1A32;
    --input-bg-hover:#0D2038;
    --input-border:rgba(255,255,255,.12);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{min-height:100%;background:var(--navy);color-scheme:dark}
body{
    min-height:100vh;display:flex;align-items:center;justify-content:center;
    padding:14px 20px;position:relative;overflow-x:hidden;
    font-family:'DM Sans',sans-serif;color:var(--light);
    background:
        radial-gradient(circle at 50% 36%,rgba(255,255,255,.028),transparent 34%),
        radial-gradient(circle at 88% 82%,rgba(43,108,176,.11),transparent 30%),
        radial-gradient(circle at 8% 12%,rgba(217,154,43,.09),transparent 26%),
        var(--navy);
    background-attachment:fixed;
}

/* ── Background: navy base, diagonal gold hatch, geometric hex accents ── */
.bg-grid{
    position:fixed;inset:0;z-index:0;pointer-events:none;background-color:var(--navy);
    background-image:
        repeating-linear-gradient(135deg,transparent 0,transparent 15px,rgba(217,154,43,.055) 15px,rgba(217,154,43,.055) 16px),
        repeating-linear-gradient(45deg,transparent 0,transparent 15px,rgba(217,154,43,.04) 15px,rgba(217,154,43,.04) 16px);
    background-size:32px 32px;
}
.portal-hex{position:fixed;width:320px;height:320px;z-index:1;pointer-events:none;opacity:.34;filter:drop-shadow(0 0 10px rgba(217,154,43,.05))}
.portal-hex svg{width:100%;height:100%;display:block;overflow:visible}
.portal-hex polygon{fill:none;stroke-width:2;vector-effect:non-scaling-stroke}
.portal-hex-gold{top:-95px;left:-95px;transform:rotate(10deg)}
.portal-hex-gold polygon{stroke:var(--amber)}
.portal-hex-blue{right:-100px;bottom:-100px;transform:rotate(-10deg);opacity:.26}
.portal-hex-blue polygon{stroke:var(--blue)}

/* ── Card: lit top edge, soft vertical gradient and inner highlight give it depth ── */
.reg-card{
    position:relative;z-index:10;width:min(100%,580px);
    padding:28px 36px 22px;
    background:linear-gradient(180deg,#1D3555 0%,#172A45 38%,#122238 100%);
    border:1px solid rgba(217,154,43,.30);border-radius:16px;
    box-shadow:
        0 30px 80px rgba(0,0,0,.50),
        0 0 0 1px rgba(217,154,43,.08),
        inset 0 1px 0 rgba(255,255,255,.07),
        0 0 60px rgba(217,154,43,.07);
    transition:border-color .3s ease,box-shadow .3s ease;
    animation:cardIn .65s cubic-bezier(.22,1,.36,1) both;
}
.reg-card::before{
    content:"";position:absolute;top:-1px;left:14%;right:14%;height:2px;border-radius:2px;
    background:linear-gradient(90deg,transparent,rgba(240,184,77,.95),rgba(43,108,176,.85),transparent);
}
.reg-card:hover{border-color:rgba(240,184,77,.45);box-shadow:0 30px 80px rgba(0,0,0,.55),0 0 0 1px rgba(217,154,43,.10),inset 0 1px 0 rgba(255,255,255,.07),0 0 80px rgba(217,154,43,.11)}
@keyframes cardIn{from{opacity:0;transform:translateY(24px) scale(.98)}to{opacity:1;transform:none}}

/* ── Header ── */
.card-header{text-align:center}
.logo-ring{
    width:58px;height:58px;border-radius:50%;object-fit:cover;display:block;margin:0 auto 9px;
    border:2px solid var(--amber);box-shadow:0 0 0 4px rgba(217,154,43,.12),0 0 24px rgba(217,154,43,.36);
}
.card-subtitle{
    margin:0 auto;font-size:10.5px;font-weight:500;line-height:1.5;letter-spacing:.95px;
    text-transform:uppercase;color:var(--muted);text-wrap:balance;
}
.role-pill{
    display:inline-flex;align-items:center;justify-content:center;gap:6px;
    margin-top:8px;padding:3px 12px;border-radius:20px;
    background:rgba(217,154,43,.12);border:1px solid rgba(217,154,43,.38);
    color:var(--amber-hover);font-size:10.5px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;
}
.divider{height:1px;margin:16px 0 14px;background:linear-gradient(90deg,transparent,rgba(217,154,43,.55),transparent)}

/* ── Alerts ── */
.alert{display:flex;align-items:flex-start;gap:9px;border-radius:10px;padding:10px 13px;font-size:12px;line-height:1.5;margin-bottom:14px}
.alert-error{background:rgba(240,84,84,.12);border:1px solid rgba(240,84,84,.32);color:#fca5a5}
.alert-success{background:rgba(34,197,94,.10);border:1px solid rgba(34,197,94,.28);color:#86efac}

/* ── Sections ── */
.section{margin-bottom:12px}
.section-title{
    display:flex;align-items:center;gap:9px;margin-bottom:8px;
    font-size:10.5px;font-weight:700;letter-spacing:1.6px;text-transform:uppercase;color:#8CA2BD;
}
.section-title i{color:var(--amber);font-size:11px}
.section-title::after{content:"";flex:1;height:1px;background:linear-gradient(90deg,rgba(217,154,43,.34),transparent)}

/* ── Fields ── */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:11px 10px}
.form-grid .full{grid-column:1/-1}
.form-group{display:flex;flex-direction:column;gap:4px}
.form-label{font-size:9.5px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--muted)}
.required{color:#f87171;font-weight:700;margin-left:3px}
.input-wrap{position:relative}
.f-icon{
    position:absolute;left:12px;top:50%;transform:translateY(-50%);width:18px;text-align:center;
    font-size:11.5px;color:var(--muted);pointer-events:none;z-index:2;transition:color .2s;
}
.form-input{
    width:100%;height:43px;padding:0 38px 0 35px;
    background:var(--input-bg);border:1px solid var(--input-border);border-radius:8px;
    color:var(--light);font:500 12px 'DM Sans',sans-serif;outline:none;
    box-shadow:inset 0 2px 6px rgba(0,0,0,.30);
    transition:border-color .2s,box-shadow .2s,background .2s;
}
.form-input::placeholder{color:rgba(160,179,198,.46)}
.form-input:hover:not(:focus){background:var(--input-bg-hover);border-color:rgba(255,255,255,.20)}
.form-input:focus{background:var(--input-bg-hover);border-color:var(--amber);box-shadow:inset 0 2px 6px rgba(0,0,0,.22),0 0 0 3px rgba(217,154,43,.20)}
.input-wrap:focus-within .f-icon{color:var(--amber-hover)}
.toggle-pw{
    position:absolute;right:9px;top:50%;transform:translateY(-50%);z-index:3;
    width:28px;height:30px;display:flex;align-items:center;justify-content:center;
    background:none;border:none;border-radius:6px;color:var(--muted);font-size:11.5px;cursor:pointer;
}
.toggle-pw:hover{color:#fff}

/* ── Password strength meter ── */
.pw-meter-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;font-size:10px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--muted)}
.strength-label{font-size:11px;letter-spacing:.4px;min-height:14px}
.strength-bar{height:5px;border-radius:99px;overflow:hidden;background:rgba(255,255,255,.09);box-shadow:inset 0 1px 2px rgba(0,0,0,.4)}
.strength-fill{height:100%;width:0;border-radius:99px;transition:width .3s,background .3s}

/* ── Button ── */
.btn-register{
    width:100%;height:45px;margin-top:2px;display:flex;align-items:center;justify-content:center;gap:9px;
    background:linear-gradient(180deg,#E6AC48 0%,#D99A2B 55%,#C98C22 100%);
    border:none;border-radius:8px;color:#1A1204;font:700 13.5px 'DM Sans',sans-serif;letter-spacing:.5px;cursor:pointer;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.28),0 10px 26px rgba(217,154,43,.30);
    transition:transform .15s,box-shadow .2s,filter .2s;
}
.btn-register:hover{transform:translateY(-1px);filter:brightness(1.07);box-shadow:inset 0 1px 0 rgba(255,255,255,.32),0 14px 32px rgba(217,154,43,.40)}
.btn-register:active{transform:translateY(1px)}

/* ── Footer ── */
.card-footer{text-align:center;margin-top:12px;padding-top:10px;border-top:1px solid rgba(255,255,255,.08);font-size:10.5px;line-height:1.5;color:var(--muted)}
.card-footer a{color:var(--amber-hover);font-weight:700;text-decoration:none}
.card-footer a:hover{text-decoration:underline;text-underline-offset:3px}

button:focus-visible,a:focus-visible{outline:3px solid rgba(240,184,77,.38);outline-offset:2px}

@media(prefers-reduced-motion:reduce){.reg-card{animation:none;transition:none}.btn-register{transition:none}}
@media(max-width:700px){
    body{padding:16px 12px;align-items:flex-start;overflow-y:auto}
    .reg-card{width:min(100%,580px);padding:24px 18px 18px;margin:auto 0}
    .portal-hex{width:240px;height:240px}
    .portal-hex-gold{top:-80px;left:-80px}
    .portal-hex-blue{right:-80px;bottom:-80px}
}
@media(max-height:760px) and (min-width:701px){body{align-items:flex-start;overflow-y:auto;padding-top:16px;padding-bottom:16px}.reg-card{margin:auto 0}}
@media(max-width:520px){
    .form-grid{grid-template-columns:1fr;gap:10px}
    .form-grid .full{grid-column:auto}
}
@media(max-width:480px){.logo-ring{width:54px;height:54px}}

/* ── Profile photo panel (same layout as the Dean page, amber theme) ── */
.photo-box{
    display:grid;grid-template-columns:auto 1fr;align-items:center;gap:12px;
    padding:10px 12px;margin-bottom:15px;border-radius:12px;
    background:linear-gradient(135deg,rgba(217,154,43,.12),rgba(10,27,46,.80) 55%);
    border:1px solid rgba(160,179,198,.18);
    box-shadow:inset 0 1px 0 rgba(255,255,255,.04),0 8px 22px rgba(0,0,0,.22);
}
.photo-box.field-invalid{border-color:#F05454;box-shadow:inset 0 1px 0 rgba(255,255,255,.04),0 0 0 3px rgba(240,84,84,.14)}
.photo-preview{
    width:64px;height:64px;border-radius:50%;background:#0F1F3D;
    border:2px dashed rgba(217,154,43,.6);overflow:hidden;display:flex;
    align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;
    box-shadow:0 0 0 5px rgba(217,154,43,.08),inset 0 2px 8px rgba(0,0,0,.4);
    transition:border-color .2s,box-shadow .2s;
}
.photo-preview:hover{border-color:var(--amber-hover);box-shadow:0 0 0 5px rgba(217,154,43,.16),inset 0 2px 8px rgba(0,0,0,.4)}
.photo-preview img{width:100%;height:100%;object-fit:cover;display:none}
.ph-icon{font-size:20px;color:var(--muted);transition:color .2s}
.photo-preview:hover .ph-icon{color:#fff}
.photo-copy strong{display:block;color:#fff;font-size:12px;margin-bottom:3px}
.photo-copy p{color:var(--muted);font-size:9.5px;line-height:1.35;max-width:390px}
.photo-actions{display:flex;gap:6px;flex-wrap:wrap;margin-top:6px}
.btn-photo,.btn-clear-photo{
    display:inline-flex;align-items:center;gap:6px;padding:6px 9px;border-radius:7px;
    font:600 9.5px 'DM Sans',sans-serif;cursor:pointer;transition:background .2s,border-color .2s,transform .15s;
}
.btn-photo{background:rgba(217,154,43,.15);border:1px solid rgba(217,154,43,.42);color:var(--amber-hover)}
.btn-photo:hover{background:rgba(217,154,43,.26);border-color:rgba(240,184,77,.6);transform:translateY(-1px)}
.btn-clear-photo{background:transparent;border:1px solid rgba(255,255,255,.09);color:var(--muted)}
.btn-clear-photo:hover{border-color:rgba(255,255,255,.18);color:var(--light)}
input[type="file"]{display:none}
.photo-preview:focus-visible{outline:3px solid rgba(240,184,77,.38);outline-offset:2px}
@media(max-width:480px){.photo-copy p{max-width:250px}}

/* ── Security questions (collapsible panel) ── */
.sq-section{margin-top:12px;margin-bottom:12px;border:1px solid rgba(160,179,198,.18);border-radius:12px;background:linear-gradient(135deg,rgba(217,154,43,.10),rgba(10,27,46,.80) 55%);box-shadow:inset 0 1px 0 rgba(255,255,255,.04),0 8px 22px rgba(0,0,0,.22);overflow:hidden}
.sq-section[open]{border-color:rgba(217,154,43,.40)}
.sq-head{list-style:none;display:flex;align-items:center;gap:10px;width:100%;padding:13px 14px;cursor:pointer;font-weight:700;font-size:13.5px;letter-spacing:1.2px;text-transform:uppercase;color:#fff;user-select:none}
.sq-head::-webkit-details-marker{display:none}
.sq-head::after{content:'\f078';font-family:'Font Awesome 6 Free';font-weight:900;color:var(--muted);font-size:11px;margin-left:auto;transition:transform .2s ease}
.sq-section[open] .sq-head::after{transform:rotate(180deg)}
.sq-head i{color:var(--amber-hover);font-size:14px}
.sq-head-text{display:flex;flex-direction:column;gap:2px;min-width:0}
.sq-head-subtitle{font-size:10.5px;font-weight:500;letter-spacing:.15px;text-transform:none;color:var(--muted);line-height:1.35}
.sq-content{padding:0 14px 4px}
.sq-note{font-size:11.5px;line-height:1.55;color:var(--muted);margin:0 0 12px}
.sq-item{display:flex;flex-direction:column;gap:6px;margin-bottom:12px}
.sq-item .form-input{padding:0 13px}
.sq-select{appearance:none;-webkit-appearance:none;cursor:pointer;padding-right:34px;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23A0B3C6' stroke-width='3'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
    background-repeat:no-repeat;background-position:right 13px center}
.sq-select option{background:#0B1A32;color:#E0E6F0}
.sq-select option:disabled{color:#7b8ea3}
.sq-chosen{font-size:12.5px;line-height:1.5;color:var(--light);padding:0 2px}
.sq-chosen:empty{display:none}
summary:focus-visible{outline:3px solid rgba(240,184,77,.38);outline-offset:2px}
</style>
</head>
<body>
<div class="bg-grid" aria-hidden="true"></div>
<div class="portal-hex portal-hex-gold" aria-hidden="true">
    <svg viewBox="0 0 300 300" role="presentation"><polygon points="150,18 269,86 269,214 150,282 31,214 31,86"/></svg>
</div>
<div class="portal-hex portal-hex-blue" aria-hidden="true">
    <svg viewBox="0 0 300 300" role="presentation"><polygon points="150,18 269,86 269,214 150,282 31,214 31,86"/></svg>
</div>

<div class="reg-card">
    <div class="card-header">
        <img class="logo-ring" src="../image/pbi_logo" alt="PBI Logo"/>
        <div class="card-subtitle">Employee Performance Evaluation &amp; Management System</div>
        <div class="role-pill"><i class="fa-solid fa-user-tie"></i> Principal Access</div>
    </div>
    <div class="divider"></div>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <i class="fa-solid fa-circle-check"></i>
            <span>Registration submitted successfully. Your account is <strong>pending administrator approval</strong>. You will be able to log in once your account is approved.</span>
        </div>
        <div class="card-footer" style="border-top:none;margin-top:8px;">
            <a href="principal_login.php">Back to Login</a>
        </div>
    <?php else: ?>
        <?php if ($error): ?>
            <div class="alert alert-error" role="alert"><i class="fa-solid fa-circle-exclamation"></i><span><?= htmlspecialchars($error) ?></span></div>
        <?php endif; ?>

        <form method="POST" action="principal_register.php" enctype="multipart/form-data" id="regForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

            <!-- Profile photo -->
            <div class="photo-box" id="photoBox">
                <div class="photo-preview" id="photoPreview" onclick="document.getElementById('photoFile').click()" role="button" tabindex="0" aria-label="Choose profile photo">
                    <img id="photoImg" src="" alt="Profile photo preview"/>
                    <i class="fa-solid fa-camera ph-icon" id="phIcon"></i>
                </div>
                <div class="photo-copy">
                    <strong>Profile Photo<span class="required">*</span></strong>
                    <p>Clear photo for identification. JPG, PNG, WebP, or GIF, up to 10 MB.</p>
                    <div class="photo-actions">
                        <label class="btn-photo" for="photoFile"><i class="fa-solid fa-upload"></i> Choose Photo</label>
                        <button type="button" class="btn-clear-photo" id="clearPhoto" style="display:none;"><i class="fa-solid fa-xmark"></i> Remove</button>
                    </div>
                    <input type="file" id="photoFile" name="photo" accept="image/jpeg,image/png,image/webp,image/gif" onchange="previewPhoto(this)"/>
                </div>
            </div>

            <!-- Account details -->
            <div class="section">
                <div class="section-title"><i class="fa-solid fa-id-card"></i> Account Details</div>
                <div class="form-grid">
                    <div class="form-group full">
                        <label class="form-label" for="full_name">Full Name<span class="required">*</span></label>
                        <div class="input-wrap">
                            <i class="fa-solid fa-id-card f-icon"></i>
                            <input class="form-input" type="text" id="full_name" name="full_name" placeholder="Last Name, First Name M.I." required value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>"/>
                        </div>
                    </div>

                    <div class="form-group full">
                        <label class="form-label" for="username">Username<span class="required">*</span></label>
                        <div class="input-wrap">
                            <i class="fa-solid fa-user f-icon"></i>
                            <input class="form-input" type="text" id="username" name="username" placeholder="Choose a username" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"/>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Security -->
            <div class="section">
                <div class="section-title"><i class="fa-solid fa-shield-halved"></i> Security</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label" for="password">Password<span class="required">*</span></label>
                        <div class="input-wrap">
                            <i class="fa-solid fa-lock f-icon"></i>
                            <input class="form-input" type="password" id="password" name="password" value="<?= $error ? htmlspecialchars(is_string($_POST['password'] ?? null) ? $_POST['password'] : '') : '' ?>" placeholder="Min. 8 characters" required minlength="8" oninput="checkStrength(this.value)"/>
                            <button type="button" class="toggle-pw" onclick="togglePw('password','eye1')" aria-label="Show password" title="Show password"><i class="fa-solid fa-eye-slash" id="eye1"></i></button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="confirm_password">Confirm Password<span class="required">*</span></label>
                        <div class="input-wrap">
                            <i class="fa-solid fa-lock f-icon"></i>
                            <input class="form-input" type="password" id="confirm_password" name="confirm_password" value="<?= $error ? htmlspecialchars(is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '') : '' ?>" placeholder="Re-enter password" required minlength="8"/>
                            <button type="button" class="toggle-pw" onclick="togglePw('confirm_password','eye2')" aria-label="Show password" title="Show password"><i class="fa-solid fa-eye-slash" id="eye2"></i></button>
                        </div>
                    </div>

                    <div class="full" id="pwStrength">
                        <div class="pw-meter-top"><span>Password Strength</span><span class="strength-label" id="strengthLabel"></span></div>
                        <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
                    </div>
                </div>
            </div>

            <!-- Security questions (collapsed until clicked, same as the student form) -->
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
                    <label class="form-label" for="sq_q<?= $n ?>">Question <?= $n ?><span class="required">*</span></label>
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

            <button type="submit" class="btn-register">
                <i class="fa-solid fa-user-plus"></i>
                <span>Create Principal Account</span>
            </button>
        </form>

        <div class="card-footer">
            Already have an account? <a href="principal_login.php">Sign in here</a>
        </div>
    <?php endif; ?>
</div>

<script>
function togglePw(id,ic){
    const e=document.getElementById(id),i=document.getElementById(ic);
    const showing=e.type==='password';
    e.type=showing?'text':'password';
    i.className=showing?'fa-solid fa-eye':'fa-solid fa-eye-slash';
    const label=showing?'Hide password':'Show password';
    const btn=i.closest('.toggle-pw');
    if(btn){btn.setAttribute('aria-label',label);btn.setAttribute('title',label);}
}
function checkStrength(v){
    const b=document.getElementById('strengthFill'),l=document.getElementById('strengthLabel');
    if(!v){b.style.width='0';l.textContent='';return;}
    let s=0;
    if(v.length>=8)s++;
    if(/[A-Z]/.test(v))s++;
    if(/[0-9]/.test(v))s++;
    if(/[^A-Za-z0-9]/.test(v))s++;
    const lv=[{w:'20%',bg:'#f87171',lb:'Weak'},{w:'45%',bg:'#fb923c',lb:'Fair'},{w:'70%',bg:'#facc15',lb:'Good'},{w:'100%',bg:'#4ade80',lb:'Strong'}][Math.max(0,s-1)];
    b.style.width=lv.w;b.style.background=lv.bg;l.textContent=lv.lb;l.style.color=lv.bg;
}

function previewPhoto(input){
    const img=document.getElementById('photoImg'),icon=document.getElementById('phIcon'),clear=document.getElementById('clearPhoto');
    if(input.files&&input.files[0]){
        const file=input.files[0];
        if(file.size>10*1024*1024){alert('Profile photo must be under 10 MB.');input.value='';return;}
        const r=new FileReader();
        r.onload=e=>{img.src=e.target.result;img.style.display='block';icon.style.display='none';clear.style.display='inline-flex';document.getElementById('photoBox').classList.remove('field-invalid');};
        r.readAsDataURL(file);
    }
}
function clearPhoto(){
    const input=document.getElementById('photoFile'),img=document.getElementById('photoImg'),icon=document.getElementById('phIcon'),clear=document.getElementById('clearPhoto');
    input.value='';img.src='';img.style.display='none';icon.style.display='block';clear.style.display='none';
}
document.getElementById('clearPhoto')?.addEventListener('click',clearPhoto);
document.getElementById('photoPreview')?.addEventListener('keydown',e=>{
    if(e.key==='Enter'||e.key===' '){e.preventDefault();document.getElementById('photoFile').click();}
});
// The file input is hidden, so the browser can't show its own "required" bubble — check it here.
document.getElementById('regForm')?.addEventListener('submit',function(e){
    const input=document.getElementById('photoFile');
    if(!input.files.length){
        e.preventDefault();
        const box=document.getElementById('photoBox');
        box.classList.add('field-invalid');
        box.scrollIntoView({block:'center',behavior:'smooth'});
    }
});
// Security questions: open the collapsed panel when something inside it needs attention,
// keep the three dropdowns from repeating, and show the full chosen question.
(function(){
    const form=document.getElementById('regForm'), box=document.getElementById('securityQuestions');
    if(!form||!box) return;
    form.addEventListener('invalid',function(e){ if(e.target.closest&&e.target.closest('.sq-section')) box.open=true; },true);
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