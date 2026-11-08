<?php
    // faculty/faculty_register.php
    require_once 'security.php';
    secure_session_start();
    require_once 'db.php';   // gives $mysqli + UPLOAD_DIR + UPLOAD_URL
    security_ensure_tables($mysqli);
    $sq_all  = sq_questions();
    $sq_rows = [];

    $error   = "";
    $success = "";

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        $full_name  = trim($_POST['full_name']  ?? '');
        $username   = trim($_POST['username']   ?? '');
        $password   = $_POST['password']        ?? '';
        $confirm_pw = $_POST['confirm_password']?? '';
        
        // ── VALIDATION ───────────────────────────────────────────
        if (empty($full_name) || empty($username) || empty($password) || empty($confirm_pw)) {
            $error = "All required fields must be completed.";
        } elseif ($password !== $confirm_pw) {
            $error = "Passwords do not match.";
        } elseif (strlen($password) < 8) {
            $error = "Password must be at least 8 characters.";
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
                if ($chk->num_rows > 0) $error = "Username is already taken.";
                $chk->close();
            }
        }

        // ── PHOTO UPLOAD ─────────────────────────────────────────
        $photo_filename = null;
        if (empty($error) && (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK)) {
            $error = "Profile photo is required.";
        }
        if (empty($error) && isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $allowed   = ['image/jpeg','image/png','image/webp','image/gif'];
            $file_type = mime_content_type($_FILES['photo']['tmp_name']);
            $file_size = $_FILES['photo']['size'];

            if (!in_array($file_type, $allowed)) {
                $error = "Profile photo must be JPG, PNG, WebP, or GIF.";
            } elseif ($file_size > 10 * 1024 * 1024) {
                $error = "Profile photo must be under 10 MB.";
            } else {
                $ext            = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
                $photo_filename = uniqid('usr_', true) . '.' . strtolower($ext);
                // UPLOAD_DIR = absolute path to index/image/ — correct from any subfolder
                if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
                if (!move_uploaded_file($_FILES['photo']['tmp_name'], UPLOAD_DIR . $photo_filename)) {
                    $error = "Failed to save photo. Check folder permissions on /image/.";
                    $photo_filename = null;
                }
            }
        }

        // ── INSERT ───────────────────────────────────────────────
        if (empty($error)) {
            $password_hash = password_hash($password, PASSWORD_BCRYPT);
            $designation   = 'Teacher';
            $role          = 'teacher';
            $sector        = 'Teacher';
            $is_active     = 1;

            try {
                $mysqli->begin_transaction();
                $stmt = $mysqli->prepare(
                    "INSERT INTO users (full_name, username, password_hash, role, sector, designation, photo, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->bind_param("sssssssi",
                    $full_name, $username,
                    $password_hash, $role, $sector, $designation,
                    $photo_filename, $is_active
                );
                $stmt->execute();
                $newId = (int)$mysqli->insert_id;
                $stmt->close();

                // Account and security answers are saved together (all or nothing)
                sq_save($mysqli, $newId, $sq_rows);
                $mysqli->commit();
                $mysqli->close();

                $_SESSION['reg_success'] = "Account created! Please log in. Your designation will be assigned by the admin.";
                header("Location: faculty_login.php");
                exit;
            } catch (Throwable $e) {
                try { $mysqli->rollback(); } catch (Throwable $ignored) {}
                error_log('faculty_register.php failed: ' . $e->getMessage());
                if ($photo_filename && is_file(UPLOAD_DIR . $photo_filename)) @unlink(UPLOAD_DIR . $photo_filename);
                $duplicate = ($e instanceof mysqli_sql_exception && (int)$e->getCode() === 1062);
                $error = $duplicate ? "Username is already taken." : "Registration failed. Please try again in a moment.";
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
<title>PBI — Faculty Registration</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<style>
:root{
    --navy:#0A192F;
    --teal:#2563EB;
    --teal-hover:#3B82F6;
    --teal-light:#60A5FA;
    --blue:#2B6CB0;
    --light:#E0E6F0;
    --muted:#A0B3C6;
    --danger:#F05454;
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
        radial-gradient(circle at 8% 12%,rgba(37,99,235,.08),transparent 26%),
        var(--navy);
}
.bg-grid{
    position:fixed;inset:0;z-index:0;pointer-events:none;
    background-image:
        repeating-linear-gradient(45deg,rgba(37,99,235,.08) 0,rgba(37,99,235,.08) 1px,transparent 1px,transparent 26px),
        repeating-linear-gradient(-45deg,rgba(37,99,235,.05) 0,rgba(37,99,235,.05) 1px,transparent 1px,transparent 26px);
}
.hex-deco{position:fixed;z-index:0;pointer-events:none;opacity:.46;filter:drop-shadow(0 0 10px rgba(37,99,235,.05))}
.hex-1{top:-60px;left:-60px}
.hex-2{bottom:-70px;right:-70px}

/* ── Card: lit top edge, soft vertical gradient and inner highlight give it depth ── */
.reg-card{
    position:relative;z-index:10;width:min(100%,540px);
    padding:24px 32px 18px;
    background:linear-gradient(180deg,#1D3555 0%,#172A45 38%,#122238 100%);
    border:1px solid rgba(37,99,235,.30);border-radius:20px;
    box-shadow:
        0 30px 80px rgba(0,0,0,.50),
        0 0 0 1px rgba(37,99,235,.08),
        inset 0 1px 0 rgba(255,255,255,.07),
        0 0 60px rgba(37,99,235,.07);
    transition:border-color .3s ease,box-shadow .3s ease;
    animation:cardIn .65s cubic-bezier(.22,1,.36,1) both;
}
.reg-card::before{
    content:"";position:absolute;top:-1px;left:14%;right:14%;height:2px;border-radius:2px;
    background:linear-gradient(90deg,transparent,rgba(96,165,250,.95),rgba(43,108,176,.85),transparent);
}
.reg-card:hover{border-color:rgba(59,130,246,.45);box-shadow:0 30px 80px rgba(0,0,0,.55),0 0 0 1px rgba(37,99,235,.10),inset 0 1px 0 rgba(255,255,255,.07),0 0 80px rgba(37,99,235,.11)}
@keyframes cardIn{from{opacity:0;transform:translateY(24px) scale(.98)}to{opacity:1;transform:none}}

/* ── Header ── */
.card-header{text-align:center}
.logo-ring{
    width:48px;height:48px;border-radius:50%;object-fit:cover;display:block;margin:0 auto 10px;
    border:2px solid var(--teal);box-shadow:0 0 0 4px rgba(37,99,235,.12),0 0 24px rgba(37,99,235,.36);
}
.card-subtitle{
    margin:0 auto;font-size:11px;font-weight:500;line-height:1.5;letter-spacing:1.1px;
    text-transform:uppercase;color:var(--muted);text-wrap:balance;
}
.role-pill{
    display:inline-flex;align-items:center;justify-content:center;gap:6px;
    margin-top:8px;padding:3px 12px;border-radius:20px;
    background:rgba(37,99,235,.14);border:1px solid rgba(96,165,250,.38);
    color:var(--teal-light);font-size:10.5px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;
}
.divider{height:1px;margin:12px 0;background:linear-gradient(90deg,transparent,rgba(37,99,235,.55),transparent)}

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
.section-title i{color:var(--teal-light);font-size:11px}
.section-title::after{content:"";flex:1;height:1px;background:linear-gradient(90deg,rgba(37,99,235,.40),transparent)}

/* ── Photo panel ── */
.photo-upload-area{
    display:grid;grid-template-columns:auto 1fr;align-items:center;gap:12px;
    padding:10px 12px;border-radius:12px;
    background:linear-gradient(135deg,rgba(37,99,235,.12),rgba(10,27,46,.80) 55%);
    border:1px solid rgba(160,179,198,.18);
    box-shadow:inset 0 1px 0 rgba(255,255,255,.04),0 8px 22px rgba(0,0,0,.22);
}
.photo-preview{
    width:50px;height:50px;border-radius:50%;background:#0F1F3D;
    border:2px dashed rgba(37,99,235,.6);overflow:hidden;display:flex;
    align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;
    box-shadow:0 0 0 5px rgba(37,99,235,.08),inset 0 2px 8px rgba(0,0,0,.4);
    transition:border-color .2s,box-shadow .2s;
}
.photo-preview:hover{border-color:var(--teal-hover);box-shadow:0 0 0 5px rgba(37,99,235,.16),inset 0 2px 8px rgba(0,0,0,.4)}
.photo-preview img{width:100%;height:100%;object-fit:cover;display:none}
.photo-preview .ph-icon{color:var(--muted);font-size:16px;transition:color .2s}
.photo-preview:hover .ph-icon{color:#fff}
.photo-info p{font-size:12.5px;font-weight:700;color:#E6EDF6;margin-bottom:2px}
.photo-info span{font-size:11px;line-height:1.4;color:var(--muted)}
.btn-photo{
    display:inline-flex;align-items:center;gap:7px;margin-top:5px;padding:5px 10px;border-radius:7px;
    background:rgba(37,99,235,.15);border:1px solid rgba(37,99,235,.42);color:var(--teal-light);
    font:600 11px 'DM Sans',sans-serif;cursor:pointer;transition:background .2s,border-color .2s,transform .15s;
}
.btn-photo:hover{background:rgba(37,99,235,.26);border-color:rgba(59,130,246,.6);transform:translateY(-1px)}
input[type="file"]{display:none}

/* ── Fields ── */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.form-grid .full{grid-column:1/-1}
.form-group{display:flex;flex-direction:column;gap:4px}
.form-label{font-size:10px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--muted)}
.required{color:#f87171;font-weight:700;margin-left:3px}
.input-wrap{position:relative}
.f-icon{
    position:absolute;left:14px;top:50%;transform:translateY(-50%);width:18px;text-align:center;
    font-size:12px;color:var(--muted);pointer-events:none;z-index:2;transition:color .2s;
}
.form-input{
    width:100%;height:40px;padding:0 40px 0 40px;
    background:var(--input-bg);border:1px solid var(--input-border);border-radius:9px;
    color:var(--light);font:500 12.5px 'DM Sans',sans-serif;outline:none;
    box-shadow:inset 0 2px 6px rgba(0,0,0,.30);
    transition:border-color .2s,box-shadow .2s,background .2s;
}
.form-input::placeholder{color:rgba(160,179,198,.46)}
.form-input:hover:not(:focus){background:var(--input-bg-hover);border-color:rgba(255,255,255,.20)}
.form-input:focus{background:var(--input-bg-hover);border-color:var(--teal);box-shadow:inset 0 2px 6px rgba(0,0,0,.22),0 0 0 3px rgba(37,99,235,.22)}
.form-input.field-invalid,.form-input.field-invalid:hover:not(:focus){border-color:var(--danger);box-shadow:inset 0 2px 6px rgba(0,0,0,.30),0 0 0 3px rgba(240,84,84,.14)}
.input-wrap:focus-within .f-icon{color:var(--teal-light)}
.toggle-pw{
    position:absolute;right:9px;top:50%;transform:translateY(-50%);z-index:3;
    width:28px;height:30px;display:flex;align-items:center;justify-content:center;
    background:none;border:none;border-radius:6px;color:var(--muted);font-size:13px;cursor:pointer;transition:color .2s;
}
.toggle-pw:hover{color:#fff}

/* ── Password strength meter ── */
.pw-meter-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;font-size:10px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--muted)}
.strength-label{font-size:11px;letter-spacing:.4px;min-height:14px}
.strength-bar{height:5px;border-radius:99px;overflow:hidden;background:rgba(255,255,255,.09);box-shadow:inset 0 1px 2px rgba(0,0,0,.4)}
.strength-fill{height:100%;width:0;border-radius:99px;transition:width .3s,background .3s}

/* ── Button ── */
.btn-register{
    width:100%;height:44px;margin-top:2px;display:flex;align-items:center;justify-content:center;gap:9px;
    background:linear-gradient(180deg,#3B82F6 0%,#2563EB 55%,#1D4ED8 100%);
    border:none;border-radius:10px;color:#fff;font:700 13px 'DM Sans',sans-serif;letter-spacing:.5px;cursor:pointer;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.24),0 10px 26px rgba(37,99,235,.36);
    transition:transform .15s,box-shadow .2s,filter .2s;
}
.btn-register:hover{transform:translateY(-1px);filter:brightness(1.08);box-shadow:inset 0 1px 0 rgba(255,255,255,.28),0 14px 32px rgba(37,99,235,.44)}
.btn-register:active{transform:translateY(1px)}

/* ── Footer ── */
.card-footer{text-align:center;margin-top:12px;padding-top:12px;border-top:1px solid rgba(255,255,255,.08);font-size:12px;color:var(--muted)}
.card-footer a{color:var(--teal-hover);font-weight:700;text-decoration:none}
.card-footer a:hover{text-decoration:underline;text-underline-offset:3px}

button:focus-visible,a:focus-visible,.photo-preview:focus-visible{outline:3px solid rgba(96,165,250,.38);outline-offset:2px}

@media(prefers-reduced-motion:reduce){.reg-card{animation:none;transition:none}.btn-register,.btn-photo{transition:none}}
@media(max-width:600px){
    body{padding:16px 12px;align-items:flex-start}
    .reg-card{padding:26px 18px 20px;margin:auto 0}
    .hex-deco{opacity:.26}
}
@media(max-width:540px){
    .form-grid{grid-template-columns:1fr}
    .form-grid .full{grid-column:auto}
    .photo-upload-area{gap:12px;padding:12px}
}

/* ── Security questions ── */
.sq-note{font-size:11.5px;line-height:1.55;color:var(--muted);margin:0 0 12px}
.sq-item{display:flex;flex-direction:column;gap:6px;margin-bottom:12px}
.sq-item .form-input{padding:0 13px}
.sq-select{appearance:none;-webkit-appearance:none;cursor:pointer;padding-right:34px;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23A0B3C6' stroke-width='3'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
    background-repeat:no-repeat;background-position:right 13px center}
.sq-select option{background:#0B1A32;color:#E0E6F0}
.sq-select option:disabled{color:#7b8ea3}
.sq-section{margin-top:16px;border:1px solid rgba(160,179,198,.18);border-radius:12px;background:linear-gradient(135deg,rgba(255,255,255,.04),rgba(10,27,46,.55) 55%);box-shadow:inset 0 1px 0 rgba(255,255,255,.04),0 8px 22px rgba(0,0,0,.22);overflow:hidden}
.sq-section[open]{border-color:var(--teal)}
.sq-head{list-style:none;display:flex;align-items:center;gap:10px;width:100%;padding:14px;cursor:pointer;font-weight:700;font-size:14px;letter-spacing:1.2px;text-transform:uppercase;color:#fff;user-select:none}
.sq-head::-webkit-details-marker{display:none}
.sq-head::after{content:'\f078';font-family:'Font Awesome 6 Free';font-weight:900;color:var(--muted);font-size:11px;margin-left:auto;transition:transform .2s ease}
.sq-section[open] .sq-head::after{transform:rotate(180deg)}
.sq-head i{color:var(--teal-light);font-size:14px}
.sq-head-text{display:flex;flex-direction:column;gap:2px;min-width:0}
.sq-head-subtitle{font-size:11px;font-weight:500;letter-spacing:.15px;text-transform:none;color:var(--muted);line-height:1.35}
.sq-content{padding:0 14px 4px}
.sq-chosen{font-size:12.5px;line-height:1.5;color:#E0E6F0;padding:0 2px}
.sq-chosen:empty{display:none}
summary:focus-visible{outline:3px solid rgba(255,255,255,.3);outline-offset:2px}
</style>
</head>
<body>
<div class="bg-grid" aria-hidden="true"></div>
<svg class="hex-deco hex-1" width="260" height="260" viewBox="0 0 260 260" aria-hidden="true"><polygon points="130,10 240,70 240,190 130,250 20,190 20,70" fill="none" stroke="#2563EB" stroke-width="1"/><polygon points="130,50 200,90 200,170 130,210 60,170 60,90" fill="none" stroke="#2563EB" stroke-width="1"/></svg>
<svg class="hex-deco hex-2" width="300" height="300" viewBox="0 0 300 300" aria-hidden="true"><polygon points="150,10 280,80 280,220 150,290 20,220 20,80" fill="none" stroke="#2B6CB0" stroke-width="1"/><polygon points="150,60 220,100 220,200 150,240 80,200 80,100" fill="none" stroke="#2B6CB0" stroke-width="1"/></svg>

<div class="reg-card">
    <div class="card-header">
        <img class="logo-ring" src="../image/pbi_logo" alt="PBI Logo"/>
        <div class="card-subtitle">Employee Performance Evaluation &amp; Management System</div>
        <div class="role-pill"><i class="fa-solid fa-id-badge"></i> Faculty Access</div>
    </div>
    <div class="divider"></div>

    <?php if ($error): ?>
    <div class="alert alert-error" role="alert"><i class="fa-solid fa-circle-exclamation" style="flex-shrink:0;margin-top:1px"></i><span><?= htmlspecialchars($error) ?></span></div>
    <?php endif; ?>
    <?php if ($success): ?>
    <div class="alert alert-success"><i class="fa-solid fa-circle-check" style="flex-shrink:0;margin-top:1px"></i>
        <span><?= htmlspecialchars($success) ?> <a href="faculty_login.php" style="color:#4ade80;font-weight:700;">Log in now →</a></span>
    </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="regForm" autocomplete="off">

        <!-- Profile Photo -->
        <div class="section">
            <div class="photo-upload-area">
                <div class="photo-preview" id="photoPreview" onclick="document.getElementById('photoFile').click()">
                    <img id="photoImg" src="" alt="Preview"/>
                    <i class="fa-solid fa-camera ph-icon" id="phIcon"></i>
                </div>
                <div class="photo-info">
                    <p>Profile Photo<span class="required">*</span></p>
                    <span>Required · JPG, PNG, WebP or GIF · max 10 MB</span><br>
                    <label class="btn-photo" for="photoFile"><i class="fa-solid fa-upload"></i> Upload Photo</label>
                    <input type="file" id="photoFile" name="photo" accept="image/jpeg,image/png,image/webp,image/gif" required onchange="previewPhoto(this)"/>
                </div>
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
                        <input class="form-input" type="password" id="password" name="password" placeholder="Min. 8 characters" required oninput="checkStrength(this.value)"/>
                        <button type="button" class="toggle-pw" onclick="togglePw('password','eye1')" aria-label="Show password" title="Show password"><i class="fa-solid fa-eye-slash" id="eye1"></i></button>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="confirm_password">Confirm Password<span class="required">*</span></label>
                    <div class="input-wrap">
                        <i class="fa-solid fa-lock f-icon"></i>
                        <input class="form-input" type="password" id="confirm_password" name="confirm_password" placeholder="Re-enter password" required/>
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
                <input class="form-input sq-answer" type="text" name="sq_answer[<?= $n ?>]" id="sq_a<?= $n ?>" maxlength="100" placeholder="Your answer" autocomplete="off" required/>
            </div>
            <?php endfor; ?>
            </div>
        </details>

        <button type="submit" class="btn-register">
            <i class="fa-solid fa-user-plus"></i>
            <span id="regBtnLabel">Create Faculty Account</span>
        </button>
    </form>

    <div class="card-footer">
        Already have an account? <a href="faculty_login.php">Sign in here</a>
    </div>
</div>

<script>
const regForm = document.getElementById('regForm');
regForm.addEventListener('invalid', function(e){ const b = e.target.closest && e.target.closest('.sq-section'); if (b) b.open = true; }, true);
regForm.addEventListener('submit', function(e){
    const requiredFields = regForm.querySelectorAll('[required]');
    let firstInvalid = null;

    requiredFields.forEach(function(field){
        const empty = field.type === 'file'
            ? field.files.length === 0
            : !field.value.trim();

        field.classList.toggle('field-invalid', empty);
        if (empty && !firstInvalid) firstInvalid = field;
    });

    const password = document.getElementById('password');
    const confirmPassword = document.getElementById('confirm_password');

    if (password.value.length < 8) {
        password.classList.add('field-invalid');
        if (!firstInvalid) firstInvalid = password;
    }
    if (confirmPassword.value !== password.value) {
        confirmPassword.classList.add('field-invalid');
        if (!firstInvalid) firstInvalid = confirmPassword;
    }


    // Security questions: three different questions, answers long enough and different from each other
    const sqSelects = [1,2,3].map(n => document.getElementById('sq_q' + n));
    const sqAnswers = [1,2,3].map(n => document.getElementById('sq_a' + n));
    if (new Set(sqSelects.map(s => s.value)).size !== 3) {
        sqSelects.forEach(s => s.classList.add('field-invalid'));
        if (!firstInvalid) firstInvalid = sqSelects[0];
    }
    const sqNorm = sqAnswers.map(a => a.value.trim().toLowerCase().replace(/[^\p{L}\p{N}]+/gu, ''));
    sqNorm.forEach(function(v, i){
        if (v.length < 3 || sqNorm.indexOf(v) !== i) {
            sqAnswers[i].classList.add('field-invalid');
            if (!firstInvalid) firstInvalid = sqAnswers[i];
        }
    });

    if (firstInvalid) {
        e.preventDefault();
        const sqBox = firstInvalid.closest('.sq-section');
        if (sqBox) sqBox.open = true;   // reveal the collapsed questions so the error is visible
        if (firstInvalid.type === 'file') {
            document.getElementById('photoPreview').scrollIntoView({block:'center'});
        } else {
            firstInvalid.focus();
        }
    }
});

regForm.addEventListener('input', function(e){
    if (e.target.matches('[required]')) e.target.classList.remove('field-invalid');
});
regForm.addEventListener('change', function(e){
    if (e.target.matches('[required]')) e.target.classList.remove('field-invalid');
});

// Keep the three question dropdowns from repeating each other.
function syncQuestionChoices(){
    const selects = [1,2,3].map(n => document.getElementById('sq_q' + n));
    selects.forEach(function(s){
        const chosenEl = document.getElementById('sq_chosen' + s.id.slice(4));
        if (chosenEl) chosenEl.textContent = s.value ? s.options[s.selectedIndex].text : '';
        Array.from(s.options).forEach(function(o){
            if (!o.value) return;
            o.disabled = selects.some(function(t){ return t !== s && t.value === o.value; });
        });
    });
}
document.querySelectorAll('.sq-select').forEach(s => s.addEventListener('change', syncQuestionChoices));
syncQuestionChoices();

function togglePw(id,ic){
    const e=document.getElementById(id),i=document.getElementById(ic);
    const showing=e.type==='password';
    e.type=showing?'text':'password';
    i.className=showing?'fa-solid fa-eye':'fa-solid fa-eye-slash';
    const btn=i.closest('.toggle-pw');
    if(btn){const label=showing?'Hide password':'Show password';btn.setAttribute('aria-label',label);btn.setAttribute('title',label);}
}
function previewPhoto(input){
    if(input.files&&input.files[0]){
        const r=new FileReader();
        r.onload=e=>{
            const img=document.getElementById('photoImg'),ic=document.getElementById('phIcon');
            img.src=e.target.result;img.style.display='block';ic.style.display='none';
        };
        r.readAsDataURL(input.files[0]);
    }
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
</script>
</body>
</html>