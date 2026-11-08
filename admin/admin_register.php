<?php
// admin/admin_register.php
session_start();
require_once 'db.php';   // provides $mysqli + UPLOAD_DIR + UPLOAD_URL
require_once 'security.php';   // security-question helpers (password recovery)
security_ensure_tables($mysqli);
$sq_all  = sq_questions();
$sq_rows = [];

// ── REGISTRATION LOCK ────────────────────────────────────────
// Once a superadmin account exists, this page closes itself automatically.
// To re-open it later (e.g. lost access, handing off to a second person),
// run this SQL once, register the new account, then flip it back to 0:
//   Set superadmin_reg_open=1 to keep registration enabled, or 0 to close it.
$reg_open = 1;
$tbl_check = $mysqli->query("SHOW TABLES LIKE 'system_settings'");
if ($tbl_check && $tbl_check->num_rows > 0) {
    $flag = $mysqli->query("SELECT setting_value FROM system_settings WHERE setting_key='superadmin_reg_open'");
    if ($flag && $flag->num_rows > 0) {
        // Registration is intentionally enabled in this build.
        // Keep it enabled even if an older database still contains a 0 flag.
        $reg_open = 1;
    }
}

$count_res = $mysqli->query("SELECT COUNT(*) as c FROM users WHERE role = 'superadmin'");
$superadmin_count = $count_res ? (int)$count_res->fetch_assoc()['c'] : 0;

if ($superadmin_count > 0 && !$reg_open) {
    http_response_code(403);
    die("Registration is closed. Contact your System Administrator.");
}

$error   = "";
$success = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $full_name  = trim($_POST['full_name']        ?? '');
    $username   = trim($_POST['username']         ?? '');
    $password   = $_POST['password']              ?? '';
    $confirm    = $_POST['confirm_password']      ?? '';

    // ── VALIDATION ────────────────────────────────────────────
    if (empty($full_name) || empty($username) || empty($password)) {
        $error = "Please fill in all required fields.";
    } elseif (strlen($password) < 8) {
        $error = "Password must be at least 8 characters.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
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
            if ($chk->num_rows > 0) $error = "Username is already in use.";
            $chk->close();
        }
    }

    // ── PHOTO UPLOAD ──────────────────────────────────────────
    $photo_filename = null;
    if (empty($error) && isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg','image/png','image/webp','image/gif'];
        $file_type     = mime_content_type($_FILES['photo']['tmp_name']);
        $file_size     = $_FILES['photo']['size'];

        if (!in_array($file_type, $allowed_types)) {
            $error = "Profile photo must be JPG, PNG, WebP, or GIF.";
        } elseif ($file_size > 10 * 1024 * 1024) {
            $error = "Profile photo must be under 10 MB.";
        } else {
            $ext            = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
            $photo_filename = uniqid('adm_', true) . '.' . strtolower($ext);
            if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
            if (!move_uploaded_file($_FILES['photo']['tmp_name'], UPLOAD_DIR . $photo_filename)) {
                $error = "Failed to save photo. Check folder permissions on /image/.";
                $photo_filename = null;
            }
        }
    }

    // ── INSERT ────────────────────────────────────────────────
    if (empty($error)) {
        $hash      = password_hash($password, PASSWORD_DEFAULT);

        try {
            // The account and its security answers are saved together (all or nothing).
            $mysqli->begin_transaction();
            $ins = $mysqli->prepare(
                "INSERT INTO users (full_name, username, password_hash, photo, role, is_active, created_at)
                 VALUES (?, ?, ?, ?, 'superadmin', 1, NOW())"
            );
            $ins->bind_param("ssss", $full_name, $username, $hash, $photo_filename);
            $ins->execute();
            $newId = (int)$mysqli->insert_id;
            $ins->close();

            sq_save($mysqli, $newId, $sq_rows);
            $mysqli->commit();
            $mysqli->close();
            $_SESSION['reg_success'] = "Admin account created! You can now sign in.";
            header("Location: admin_login.php");
            exit;
        } catch (Throwable $e) {
            try { $mysqli->rollback(); } catch (Throwable $ignored) {}
            error_log('admin_register.php failed: ' . $e->getMessage());
            if ($photo_filename && is_file(UPLOAD_DIR . $photo_filename)) @unlink(UPLOAD_DIR . $photo_filename);
            $duplicate = ($e instanceof mysqli_sql_exception && (int)$e->getCode() === 1062);
            $error = $duplicate ? "Username is already in use." : "Registration failed. Please try again in a moment.";
        }
    }
    if ($mysqli->ping()) $mysqli->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>PBI Admin — Create Account</title>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<link rel="stylesheet" href="admin_appearance.css">
<script src="admin_appearance.js"></script>
<style>
:root{
    --navy-deep:#091727;
    --input-bg:#0B1B2E;
    --input-bg-hover:#0D2035;
    --input-border:#29405A;
    --text:#E7EEF7;
    --label:#98ADC3;
    --muted:#8FA6BE;
    --blue:#2F6EE2;
    --blue-hover:#3D7BF0;
    --blue-light:#8DB4FF;
    --gold:#F2C94C;
    --gold-hover:#FFD866;
    --gold-ring:#C9A227;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{min-height:100%;background:var(--navy-deep);color-scheme:dark}
body{
    min-height:100vh;display:flex;align-items:center;justify-content:center;
    padding:14px 20px;position:relative;overflow-x:hidden;
    font-family:'DM Sans',sans-serif;color:var(--text);
    background:
        radial-gradient(circle at 10% 8%,rgba(47,110,226,.14),transparent 30%),
        radial-gradient(circle at 90% 90%,rgba(242,201,76,.08),transparent 28%),
        var(--navy-deep);
    background-attachment:fixed;
}
body::before{
    content:"";position:fixed;inset:0;z-index:0;pointer-events:none;
    background:linear-gradient(180deg,rgba(15,30,51,.12),transparent 30%,rgba(47,110,226,.04));
}
.bg-grid{
    position:fixed;inset:0;z-index:0;pointer-events:none;
    background-image:
        repeating-linear-gradient(45deg,rgba(79,129,184,.055) 0,rgba(79,129,184,.055) 1px,transparent 1px,transparent 26px),
        repeating-linear-gradient(-45deg,rgba(79,129,184,.04) 0,rgba(79,129,184,.04) 1px,transparent 1px,transparent 26px);
}
.hex-deco{position:fixed;z-index:0;pointer-events:none;opacity:.5}
.hex-1{top:-60px;left:-60px}
.hex-2{bottom:-70px;right:-70px}

/* ── Card: lit top edge, soft vertical gradient and inner highlight give it depth ── */
.reg-card{
    position:relative;z-index:10;width:min(100%,540px);
    padding:24px 32px 18px;
    background:linear-gradient(180deg,#152A47 0%,#0F2038 36%,#0B192C 100%);
    border:1px solid rgba(152,178,207,.20);border-radius:20px;
    box-shadow:
        0 30px 80px rgba(0,0,0,.55),
        0 0 0 1px rgba(255,255,255,.02),
        inset 0 1px 0 rgba(255,255,255,.07),
        0 0 90px rgba(47,110,226,.08);
    transition:border-color .3s ease,box-shadow .3s ease;
    animation:cardIn .65s cubic-bezier(.22,1,.36,1) both;
}
.reg-card::before{
    content:"";position:absolute;top:-1px;left:14%;right:14%;height:2px;border-radius:2px;
    background:linear-gradient(90deg,transparent,rgba(98,144,230,.95),rgba(242,201,76,.75),transparent);
}
.reg-card:hover{
    border-color:rgba(98,144,201,.36);
    box-shadow:0 30px 80px rgba(0,0,0,.58),0 0 0 1px rgba(255,255,255,.02),inset 0 1px 0 rgba(255,255,255,.07),0 0 100px rgba(47,110,226,.13);
}
@keyframes cardIn{from{opacity:0;transform:translateY(24px) scale(.98)}to{opacity:1;transform:none}}

/* ── Header ── */
.card-header{text-align:center}
.logo-img{
    width:48px;height:48px;border-radius:50%;object-fit:cover;display:block;margin:0 auto 10px;
    border:2.5px solid var(--gold-ring);box-shadow:0 0 0 4px rgba(201,162,39,.10),0 0 26px rgba(201,162,39,.38);
}
.card-subtitle{
    margin:0 auto;
    font-size:11px;font-weight:500;line-height:1.5;letter-spacing:1.1px;text-transform:uppercase;
    color:#92A7BF;text-wrap:balance;
}
.role-pill{
    display:inline-flex;align-items:center;justify-content:center;gap:6px;
    margin-top:8px;padding:3px 12px;border-radius:20px;
    background:rgba(47,110,226,.14);border:1px solid rgba(98,144,230,.42);
    color:var(--blue-light);font-size:10.5px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;
}
.divider{height:1px;margin:12px 0 12px;background:linear-gradient(90deg,transparent,rgba(93,145,204,.38),transparent)}

/* ── Alerts ── */
.alert{display:flex;align-items:flex-start;gap:10px;border-radius:10px;padding:11px 14px;font-size:12.5px;line-height:1.5;margin-bottom:14px}
.alert-error{background:rgba(248,113,113,.10);border:1px solid rgba(248,113,113,.28);color:#FCA5A5}

/* ── Sections ── */
.section{margin-bottom:12px}
.section-title{
    display:flex;align-items:center;gap:9px;margin-bottom:8px;
    font-size:10.5px;font-weight:700;letter-spacing:1.6px;text-transform:uppercase;color:#7F95B0;
}
.section-title i{color:#5C8FE8;font-size:11px}
.section-title::after{content:"";flex:1;height:1px;background:linear-gradient(90deg,rgba(98,144,201,.32),transparent)}

/* ── Photo panel ── */
.photo-upload-area{
    display:grid;grid-template-columns:auto 1fr;align-items:center;gap:12px;
    padding:10px 12px;border-radius:12px;
    background:linear-gradient(135deg,rgba(47,110,226,.10),rgba(10,27,46,.80) 55%);
    border:1px solid rgba(152,178,207,.18);
    box-shadow:inset 0 1px 0 rgba(255,255,255,.04),0 8px 22px rgba(0,0,0,.22);
}
.photo-preview{
    width:50px;height:50px;border-radius:50%;background:#0A1B2E;
    border:2px dashed rgba(79,129,184,.60);overflow:hidden;display:flex;
    align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;
    box-shadow:0 0 0 5px rgba(47,110,226,.07),inset 0 2px 8px rgba(0,0,0,.4);
    transition:border-color .2s,box-shadow .2s;
}
.photo-preview:hover{border-color:var(--blue);box-shadow:0 0 0 5px rgba(47,110,226,.14),inset 0 2px 8px rgba(0,0,0,.4)}
.photo-preview img{width:100%;height:100%;object-fit:cover;display:none}
.photo-preview .ph-icon{color:#91A8BF;font-size:16px;transition:color .2s}
.photo-preview:hover .ph-icon{color:#BDD0E4}
.photo-info p{font-size:12.5px;font-weight:700;color:#E6EDF6;margin-bottom:2px}
.photo-info span{font-size:11px;line-height:1.4;color:var(--muted)}
.btn-photo{
    display:inline-flex;align-items:center;gap:7px;margin-top:5px;padding:5px 10px;border-radius:7px;
    background:rgba(242,201,76,.09);border:1px solid rgba(242,201,76,.32);color:var(--gold);
    font:600 11px 'DM Sans',sans-serif;cursor:pointer;transition:background .2s,border-color .2s,transform .15s;
}
.btn-photo:hover{background:rgba(242,201,76,.16);border-color:rgba(242,201,76,.55);transform:translateY(-1px)}
input[type="file"]{display:none}

/* ── Fields ──  (!important on the input skin so admin_appearance.css can't turn them white) */
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:10px 10px}
.form-row .full{grid-column:1/-1}
.form-group{display:flex;flex-direction:column;gap:4px}
.form-label{font-size:10px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--label)!important}
.required{color:#F87171!important;font-weight:700;margin-left:3px}
.input-wrap{position:relative}
.reg-card .input-wrap .f-icon{
    position:absolute!important;left:14px!important;top:50%;transform:translateY(-50%);width:18px!important;text-align:center!important;
    font-size:12px;color:#92A7BF!important;pointer-events:none;z-index:2!important;transition:color .2s;
}
.reg-card .input-wrap .form-input{
    width:100%;height:40px!important;min-height:0!important;padding:0 40px 0 40px!important;
    background:var(--input-bg)!important;border:1px solid var(--input-border)!important;border-radius:9px!important;
    color:var(--text)!important;font:500 12.5px 'DM Sans',sans-serif!important;outline:none;
    box-shadow:inset 0 2px 6px rgba(0,0,0,.32)!important;
    transition:border-color .2s,box-shadow .2s,background .2s;
}
.reg-card .input-wrap .form-input::placeholder{color:rgba(154,175,197,.50)!important}
.reg-card .input-wrap .form-input:hover:not(:focus){background:var(--input-bg-hover)!important;border-color:#345473!important}
.reg-card .input-wrap .form-input:focus{background:var(--input-bg-hover)!important;border-color:var(--blue)!important;box-shadow:inset 0 2px 6px rgba(0,0,0,.25),0 0 0 3px rgba(47,110,226,.22)!important}
.reg-card .input-wrap:focus-within .f-icon{color:#B7C9DB!important}
.reg-card .input-wrap .toggle-pw{
    position:absolute!important;right:9px!important;top:50%;transform:translateY(-50%);z-index:3!important;
    width:28px!important;height:30px!important;display:flex!important;align-items:center;justify-content:center;
    background:none!important;border:none!important;border-radius:6px;color:#92A7BF!important;font-size:13px;cursor:pointer;
}
.reg-card .input-wrap .toggle-pw i{display:block!important}
.reg-card .input-wrap .toggle-pw:hover{color:#B7C9DB!important}

/* ── Password strength meter ── */
.pw-meter-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;font-size:10px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--label)}
.pw-hint{font-size:11px;letter-spacing:.4px;min-height:14px}
.pw-strength{height:5px;border-radius:99px;overflow:hidden;background:rgba(152,178,207,.14);box-shadow:inset 0 1px 2px rgba(0,0,0,.4)}
.pw-strength span{display:block;height:100%;width:0;border-radius:99px;transition:width .3s,background .3s}

/* ── Button ── */
.btn-main{
    width:100%;height:44px;margin-top:2px;display:flex;align-items:center;justify-content:center;gap:10px;
    background:linear-gradient(180deg,#3D7BF0 0%,#2F6EE2 55%,#2860CC 100%);
    border:none;border-radius:10px;color:#fff;font:700 13px 'DM Sans',sans-serif;letter-spacing:.6px;cursor:pointer;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.22),0 10px 26px rgba(47,110,226,.38);
    transition:transform .15s,box-shadow .2s,filter .2s;
}
.btn-main:hover{transform:translateY(-1px);filter:brightness(1.08);box-shadow:inset 0 1px 0 rgba(255,255,255,.26),0 14px 32px rgba(47,110,226,.46)}
.btn-main:active{transform:translateY(1px)}

/* ── Footer ── */
.card-footer{text-align:center;margin-top:12px;padding-top:12px;border-top:1px solid rgba(152,178,207,.12);font-size:12px;color:var(--muted)}
.card-footer a{color:var(--gold);font-weight:700;text-decoration:none}
.card-footer a:hover{color:var(--gold-hover);text-decoration:underline;text-underline-offset:3px}

button:focus-visible,a:focus-visible,.photo-preview:focus-visible,.btn-photo:focus-within{outline:3px solid rgba(79,144,231,.38);outline-offset:2px}

@media(prefers-reduced-motion:reduce){.reg-card{animation:none;transition:none}.btn-main,.btn-photo{transition:none}}
@media(max-width:640px){
    body{padding:16px 12px;align-items:flex-start}
    .reg-card{padding:30px 20px 22px;margin:auto 0}
    .hex-1,.hex-2{opacity:.24}
}
@media(max-width:540px){
    .form-row{grid-template-columns:1fr;gap:14px}
    .form-row .full{grid-column:auto}
    .photo-upload-area{gap:14px;padding:14px}
}

/* ── Security questions (collapsible panel) ── */
.sq-section{margin-top:12px;margin-bottom:12px;border:1px solid rgba(160,179,198,.18);border-radius:12px;background:linear-gradient(135deg,rgba(242,201,76,.10),rgba(9,23,39,.82) 55%);box-shadow:inset 0 1px 0 rgba(255,255,255,.04),0 8px 22px rgba(0,0,0,.22);overflow:hidden}
.sq-section[open]{border-color:rgba(242,201,76,.40)}
.sq-head{list-style:none;display:flex;align-items:center;gap:10px;width:100%;padding:13px 14px;cursor:pointer;font-weight:700;font-size:13.5px;letter-spacing:1.2px;text-transform:uppercase;color:#fff;user-select:none}
.sq-head::-webkit-details-marker{display:none}
.sq-head::after{content:'\f078';font-family:'Font Awesome 6 Free';font-weight:900;color:var(--muted);font-size:11px;margin-left:auto;transition:transform .2s ease}
.sq-section[open] .sq-head::after{transform:rotate(180deg)}
.sq-head i{color:var(--gold-hover);font-size:14px}
.sq-head-text{display:flex;flex-direction:column;gap:2px;min-width:0}
.sq-head-subtitle{font-size:10.5px;font-weight:500;letter-spacing:.15px;text-transform:none;color:var(--muted);line-height:1.35}
.sq-content{padding:0 14px 4px}
.sq-note{font-size:11.5px;line-height:1.55;color:var(--muted);margin:0 0 12px}
.sq-item{display:flex;flex-direction:column;gap:6px;margin-bottom:12px}
.sq-item .form-input{width:100%;height:40px;min-height:0;padding:0 13px;background:var(--input-bg);border:1px solid var(--input-border);border-radius:9px;color:var(--text);font:500 12.5px 'DM Sans',sans-serif;outline:none;box-shadow:inset 0 2px 6px rgba(0,0,0,.32);transition:border-color .2s,box-shadow .2s}
.sq-item .form-input:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(47,110,226,.20)}
.sq-item .sq-select{appearance:none;-webkit-appearance:none;cursor:pointer;padding-right:34px;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23A0B3C6' stroke-width='3'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
    background-repeat:no-repeat;background-position:right 13px center}
.sq-select option{background:#0B1B2E;color:#E7EEF7}
.sq-select option:disabled{color:#7b8ea3}
.sq-chosen{font-size:12.5px;line-height:1.5;color:var(--text);padding:0 2px}
.sq-chosen:empty{display:none}
summary:focus-visible{outline:3px solid rgba(255,216,102,.40);outline-offset:2px}
</style>

</head>
<body>
<div class="bg-grid" aria-hidden="true"></div>

<svg class="hex-deco hex-1" width="260" height="260" viewBox="0 0 260 260" aria-hidden="true"><polygon points="130,10 240,70 240,190 130,250 20,190 20,70" fill="none" stroke="#C9A227" stroke-width="1"/><polygon points="130,50 200,90 200,170 130,210 60,170 60,90" fill="none" stroke="#C9A227" stroke-width="1"/></svg>
<svg class="hex-deco hex-2" width="300" height="300" viewBox="0 0 300 300" aria-hidden="true"><polygon points="150,10 280,80 280,220 150,290 20,220 20,80" fill="none" stroke="#9C7A12" stroke-width="1"/><polygon points="150,60 220,100 220,200 150,240 80,200 80,100" fill="none" stroke="#9C7A12" stroke-width="1"/></svg>

<div class="reg-card">
    <div class="card-header">
        <img class="logo-img" src="../image/pbi_logo" alt="PBI Logo"/>
        <div class="card-subtitle">Employee Performance Evaluation &amp; Management System</div>
        <div class="role-pill"><i class="fa-solid fa-user-shield"></i> Admin Access</div>
    </div>
    <div class="divider"></div>

    <?php if ($error): ?>
    <div class="alert alert-error" role="alert">
        <i class="fa-solid fa-circle-exclamation" style="flex-shrink:0;margin-top:2px"></i>
        <span><?= htmlspecialchars($error) ?></span>
    </div>
    <?php endif; ?>

    <form method="POST" action="admin_register.php" enctype="multipart/form-data" autocomplete="off">

        <!-- Profile Photo -->
        <div class="section">
            <div class="photo-upload-area">
                <div class="photo-preview" id="photoPreview" onclick="document.getElementById('photoFile').click()">
                    <img id="photoImg" src="" alt="Preview"/>
                    <i class="fa-solid fa-camera ph-icon" id="phIcon"></i>
                </div>
                <div class="photo-info">
                    <p>Profile Photo</p>
                    <span>Clear photo for identification · max 10 MB</span><br>
                    <label class="btn-photo" for="photoFile">
                        <i class="fa-solid fa-upload"></i> Choose Photo
                    </label>
                    <input type="file" id="photoFile" name="photo"
                           accept="image/jpeg,image/png,image/webp,image/gif"
                           onchange="previewPhoto(this)"/>
                </div>
            </div>
        </div>

        <!-- Account details -->
        <div class="section">
            <div class="section-title"><i class="fa-solid fa-id-card"></i> Account Details</div>
            <div class="form-row">
                <div class="form-group full">
                    <label class="form-label" for="full_name">Full Name<span class="required">*</span></label>
                    <div class="input-wrap">
                        <i class="fa-solid fa-user f-icon"></i>
                        <input class="form-input" type="text" id="full_name" name="full_name" placeholder="Juan dela Cruz"
                               value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>" required/>
                    </div>
                </div>

                <div class="form-group full">
                    <label class="form-label" for="username">Username<span class="required">*</span></label>
                    <div class="input-wrap">
                        <i class="fa-solid fa-at f-icon"></i>
                        <input class="form-input" type="text" id="username" name="username" placeholder="Choose a username"
                               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autocomplete="off"/>
                    </div>
                </div>
            </div>
        </div>

        <!-- Security -->
        <div class="section">
            <div class="section-title"><i class="fa-solid fa-shield-halved"></i> Security</div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="pw">Password<span class="required">*</span></label>
                    <div class="input-wrap">
                        <i class="fa-solid fa-lock f-icon"></i>
                        <input class="form-input" type="password" id="pw" name="password"
                               placeholder="Min. 8 characters" required oninput="checkStrength(this.value)"/>
                        <button type="button" class="toggle-pw" onclick="togglePw('pw','eye1',this)" aria-label="Show password" title="Show password">
                            <i class="fa-solid fa-eye-slash" id="eye1"></i>
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="pw2">Confirm Password<span class="required">*</span></label>
                    <div class="input-wrap">
                        <i class="fa-solid fa-lock f-icon"></i>
                        <input class="form-input" type="password" id="pw2" name="confirm_password"
                               placeholder="Repeat password" required/>
                        <button type="button" class="toggle-pw" onclick="togglePw('pw2','eye2',this)" aria-label="Show password" title="Show password">
                            <i class="fa-solid fa-eye-slash" id="eye2"></i>
                        </button>
                    </div>
                </div>

                <div class="full">
                    <div class="pw-meter-top"><span>Password Strength</span><span class="pw-hint" id="pw-hint"></span></div>
                    <div class="pw-strength" id="pw-bar"><span id="pw-fill"></span></div>
                </div>
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
                    <label class="form-label" for="sq_q<?= $n ?>">Question <?= $n ?><span class="required">*</span></label>
                    <select class="form-input sq-select" name="sq_question[<?= $n ?>]" id="sq_q<?= $n ?>" required>
                        <option value="" disabled <?= empty($_POST['sq_question'][$n]) ? 'selected' : '' ?>>Choose a question</option>
                        <?php foreach ($sq_all as $k => $q): ?>
                        <option value="<?= htmlspecialchars($k) ?>" <?= (($_POST['sq_question'][$n] ?? '') === $k) ? 'selected' : '' ?>><?= htmlspecialchars($q) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="sq-chosen" id="sq_chosen<?= $n ?>" aria-live="polite"></div>
                    <input class="form-input sq-answer" type="text" name="sq_answer[<?= $n ?>]" id="sq_a<?= $n ?>" maxlength="100" placeholder="Your answer" autocomplete="off" required/>
                </div>
                <?php endfor; ?>
            </div>
        </details>

        <button type="submit" class="btn-main">
            <i class="fa-solid fa-user-plus"></i> ADMIN REGISTRATION
        </button>
    </form>

    <div class="card-footer">
        Already have an account? <a href="admin_login.php">Sign in here</a>
    </div>
</div>

<script>
function togglePw(id, ic, btn) {
    const el = document.getElementById(id), i = document.getElementById(ic);
    const showing = el.type === 'password';
    el.type = showing ? 'text' : 'password';
    i.className = showing ? 'fa-solid fa-eye' : 'fa-solid fa-eye-slash';
    if (btn) {
        btn.setAttribute('aria-label', showing ? 'Hide password' : 'Show password');
        btn.setAttribute('title', showing ? 'Hide password' : 'Show password');
    }
}
function previewPhoto(input) {
    if (input.files && input.files[0]) {
        const r = new FileReader();
        r.onload = e => {
            const img = document.getElementById('photoImg');
            const ic  = document.getElementById('phIcon');
            img.src = e.target.result;
            img.style.display = 'block';
            ic.style.display  = 'none';
        };
        r.readAsDataURL(input.files[0]);
    }
}
function checkStrength(val) {
    const fill = document.getElementById('pw-fill'), hint = document.getElementById('pw-hint');
    let score = 0;
    if (val.length >= 8)           score++;
    if (/[A-Z]/.test(val))         score++;
    if (/[0-9]/.test(val))         score++;
    if (/[^A-Za-z0-9]/.test(val))  score++;
    const colors = ['#ff4444','#ff8800','#f0c040','#32B98A'];
    const labels = ['Weak','Fair','Good','Strong'];
    if (!val) { fill.style.width = '0'; hint.textContent = ''; return; }
    const level = Math.max(score, 1);
    fill.style.width      = (level * 25) + '%';
    fill.style.background = colors[level - 1];
    hint.textContent      = labels[level - 1];
    hint.style.color      = colors[level - 1];
}
// Security questions: open the collapsed panel when a field inside it needs attention,
// keep the three dropdowns from repeating, and show the full chosen question.
(function(){
    const form=document.querySelector('form[action="admin_register.php"]'), box=document.getElementById('securityQuestions');
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
</body>
</html>