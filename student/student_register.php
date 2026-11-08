<?php
	// student/student_register.php
	require_once 'security.php';
	secure_session_start();
	require_once 'db.php';
	security_ensure_tables($mysqli);

	$error = '';

	// School level -> allowed year/grade levels (validated on the server, not just in the dropdown)
	$levels = [
		'JHS'     => ['Grade 7','Grade 8','Grade 9','Grade 10'],
		'SHS'     => ['Grade 11','Grade 12'],
		'College' => ['1st Year','2nd Year','3rd Year','4th Year'],
	];
	// JHS/SHS/College -> education_level enum used across the system
	$dept_to_level = [
		'JHS'     => 'junior_high',
		'SHS'     => 'senior_high',
		'College' => 'college',
	];
	$sq_all = sq_questions();

	if ($_SERVER['REQUEST_METHOD'] === 'POST') {

		$full_name  = trim(preg_replace('/\s+/', ' ', $_POST['full_name'] ?? ''));
		$username   = trim($_POST['username']   ?? '');
		$password   = $_POST['password']        ?? '';
		$confirm_pw = $_POST['confirm_password']?? '';
		$department = trim($_POST['department'] ?? '');
		$year_level = trim($_POST['year_level'] ?? '');
		$education_level = $dept_to_level[$department] ?? null;

		$sq_rows = [];

		if (!csrf_valid($_POST['csrf_token'] ?? '')) { $error = "Your session expired. Please try again."; }
		elseif (empty($full_name))  { $error = "Full name is required."; }
		elseif (empty($username))  { $error = "Username is required."; }
		elseif (mb_strlen($full_name) > 100 || mb_strlen($username) > 50) { $error = "Full name or username is too long."; }
		elseif (preg_match('/\s/', $username)) { $error = "Username cannot contain spaces."; }
		elseif (empty($department) || !isset($dept_to_level[$department])) { $error = "Please select your school level."; }
		elseif (empty($password))  { $error = "Password is required."; }
		elseif (strlen($password) < 8) { $error = "Password must be at least 8 characters."; }
		elseif (strlen($password) > 72) { $error = "Password must be 72 characters or fewer."; }
		elseif ($password !== $confirm_pw) { $error = "Passwords do not match."; }
		elseif (!in_array($year_level, $levels[$department], true)) { $error = "Please select a valid year/grade level."; }
		else {
			// Security questions (used for password recovery)
			[$sqErr, $sq_rows] = sq_validate($_POST['sq_question'] ?? [], $_POST['sq_answer'] ?? [], $username);
			if ($sqErr) { $error = $sqErr; }
		}

		if (empty($error)) {
			// Check duplicate username
			$chk = $mysqli->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
			$chk->bind_param("s", $username);
			$chk->execute();
			$chk->store_result();
			if ($chk->num_rows > 0) {
				$error = "Username already taken. Please choose another.";
			}
			$chk->close();
		}

		// Insert the account and its security answers together (all or nothing)
		if (empty($error)) {
			$hash = password_hash($password, PASSWORD_DEFAULT);
			try {
				$mysqli->begin_transaction();
				$stmt = $mysqli->prepare(
					"INSERT INTO users
					 (full_name, username, password_hash, role, designation, department, education_level, year_level, is_active)
					 VALUES (?, ?, ?, 'student', 'Student', ?, ?, ?, 1)"
				);
				$stmt->bind_param(
					"ssssss",
					$full_name,
					$username,
					$hash,
					$department,
					$education_level,
					$year_level
				);
				$stmt->execute();
				$newId = (int)$mysqli->insert_id;
				$stmt->close();

				sq_save($mysqli, $newId, $sq_rows);
				$mysqli->commit();

				$_SESSION['reg_success'] = "Account created! Please log in with your username and password.";
				header("Location: student_login.php");
				exit;
			} catch (Throwable $e) {
				try { $mysqli->rollback(); } catch (Throwable $ignored) {}
				error_log('student_register failed: ' . $e->getMessage());
				$duplicate = ($e instanceof mysqli_sql_exception && (int)$e->getCode() === 1062);
				$error = $duplicate
					? "That username is already registered."
					: "Registration failed. Please try again in a moment.";
			}
		}
	}
	?>
<!DOCTYPE html>
	<html lang="en">
	<head>
	<meta charset="UTF-8"/>
	<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
	<title>PBI — Student Registration</title>
	<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<link rel="stylesheet" href="student_ui.css"/>
	<style>
	:root{--dark-blue:#0A192F;--blue-mid:#172A45;--blue-inner:#0F1F3D;--gold:#D97706;--gold-hover:#F59E0B;--light:#E0E6F0;--muted:#A0B3C6;--radius:8px;--shadow:0 8px 32px rgba(0,0,0,0.45);}
	*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
	body{min-height:100vh;background:#0A192F;font-family:'DM Sans',sans-serif;color:var(--light);display:flex;align-items:center;justify-content:center;padding:28px 20px;position:relative;overflow-x:hidden;}
	.bg-grid{display:block;position:fixed;inset:0;z-index:0;background-image:repeating-linear-gradient(45deg,rgba(217,119,6,.07) 0px,rgba(217,119,6,.07) 1px,transparent 1px,transparent 26px),repeating-linear-gradient(-45deg,rgba(217,119,6,.05) 0px,rgba(217,119,6,.05) 1px,transparent 1px,transparent 26px);}
	.hex-deco{position:fixed;z-index:0;pointer-events:none;opacity:.5;}
	.hex-1{top:-60px;left:-60px;}
	.hex-2{bottom:-70px;right:-70px;}
	.reg-card{position:relative;z-index:10;background:rgba(23,42,69,.88);backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,.09);border-radius:16px;padding:30px 32px 28px;width:100%;max-width:500px;box-shadow:var(--shadow);animation:cardIn .65s cubic-bezier(.22,1,.36,1) both;}
	@keyframes cardIn{from{opacity:0;transform:translateY(28px) scale(.97)}to{opacity:1;transform:none}}
	.card-header{text-align:center;margin-bottom:18px;}
	.logo-ring{width:54px;height:54px;border-radius:50%;display:block;object-fit:cover;border:2px solid var(--gold);box-shadow:0 0 16px rgba(217,119,6,.4);margin:0 auto 10px;}
	.card-title{font-family:'Rajdhani',sans-serif;font-size:21px;font-weight:700;letter-spacing:1.6px;color:#fff;text-transform:uppercase;}
	.card-subtitle{font-size:10.5px;color:var(--muted);letter-spacing:1.1px;text-transform:uppercase;margin-top:3px;}
	.divider{height:1px;background:linear-gradient(90deg,transparent,rgba(217,119,6,.4),transparent);margin-bottom:15px;}
	.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
	.form-grid .full{grid-column:1/-1;}
	.form-group{display:flex;flex-direction:column;gap:5px;}
	.form-label{font-size:10px;font-weight:600;letter-spacing:1.1px;text-transform:uppercase;color:var(--muted);}
	.req{color:#f87171;}
	.input-wrap{position:relative;}
	.input-wrap .f-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:12px;pointer-events:none;}
	.form-input{width:100%;padding:9px 11px 9px 34px;background:rgba(10,25,47,.7);border:1px solid rgba(255,255,255,.1);border-radius:var(--radius);color:var(--light);font-size:13px;font-family:'DM Sans',sans-serif;outline:none;transition:border-color .25s,box-shadow .25s;appearance:none;}
	.form-input::placeholder{color:rgba(160,179,198,.42);}
	.form-input:focus{border-color:var(--gold);box-shadow:0 0 0 3px rgba(217,119,6,.18);}
	.select-arr::after{content:'\f078';font-family:'Font Awesome 6 Free';font-weight:900;position:absolute;right:12px;top:50%;transform:translateY(-50%);color:var(--muted);pointer-events:none;font-size:10px;}
	.toggle-pw{position:absolute;right:11px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--muted);cursor:pointer;font-size:12px;padding:0;}
	.alert{display:flex;align-items:flex-start;gap:8px;border-radius:8px;padding:9px 12px;font-size:12px;margin-bottom:14px;}
	.alert-error{background:rgba(240,84,84,.12);border:1px solid rgba(240,84,84,.3);color:#fca5a5;}
	.btn-register{width:100%;padding:11px;background:var(--gold);border:none;border-radius:var(--radius);color:#fff;font-size:14px;font-weight:600;font-family:'DM Sans',sans-serif;cursor:pointer;margin-top:14px;transition:background .2s,transform .15s;box-shadow:0 4px 16px rgba(217,119,6,.38);display:flex;align-items:center;justify-content:center;gap:8px;}
	.btn-register:hover{background:var(--gold-hover);transform:translateY(-1px);}
	.card-footer{text-align:center;margin-top:16px;font-size:11px;color:var(--muted);border-top:1px solid rgba(255,255,255,.06);padding-top:13px;}
	.card-footer a{color:var(--gold-hover);text-decoration:none;font-weight:600;}
	.college-only{display:none;}
	@media(max-width:540px){.reg-card{padding:20px 14px 18px;}.form-grid{grid-template-columns:1fr;}.form-grid .full{grid-column:1;}}

	.sq-section{margin-top:16px;border:1px solid rgba(255,255,255,.08);border-radius:12px;background:rgba(10,25,47,.34);overflow:hidden;}
	.sq-head{list-style:none;display:flex;align-items:center;gap:8px;width:100%;padding:14px;cursor:pointer;font-family:'Rajdhani',sans-serif;font-size:15px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:#fff;user-select:none;}
	.sq-head::-webkit-details-marker{display:none;}
	.sq-head::after{content:'\f078';font-family:'Font Awesome 6 Free';font-weight:900;color:var(--muted);font-size:11px;margin-left:auto;transition:transform .2s ease;}
	.sq-section[open] .sq-head::after{transform:rotate(180deg);}
	.sq-head i{color:var(--gold-hover);font-size:13px;}
	.sq-head-text{display:flex;flex-direction:column;gap:2px;min-width:0;}
	.sq-head-title{color:#fff;}
	.sq-head-subtitle{font-family:'DM Sans',sans-serif;font-size:10.5px;font-weight:500;letter-spacing:.15px;text-transform:none;color:var(--muted);line-height:1.35;}
	.sq-content{padding:0 14px 4px;}
	.sq-note{font-size:11px;line-height:1.5;color:var(--muted);margin:0 0 12px;}
	.sq-item{margin-bottom:12px;display:flex;flex-direction:column;gap:6px;}
	.sq-item select.form-input{padding-left:12px;padding-right:32px;}
	.sq-item .form-input{padding-left:12px;}
	.client-error{display:none;margin-top:12px;}
	.client-error.show{display:flex;}
	/* Readability: slightly larger type across the form, larger still in the security section */
	.form-label{font-size:11px;}
	.form-input{font-size:14px;}
	.alert{font-size:13px;}
	.btn-register{font-size:15px;}
	.card-footer{font-size:12.5px;}
	.sq-head{font-size:17px;}
	.sq-head-subtitle{font-size:11px;}
	.sq-note{font-size:12.5px;line-height:1.6;}
	.sq-item .form-input{font-size:14.5px;min-height:46px;}
	.sq-chosen{font-size:13px;line-height:1.5;color:var(--light);padding:0 2px;}
	.sq-chosen:empty{display:none;}
	
/* =====================================================================
   Card lighting — same treatment as the Faculty / Staff / Dean / Principal
   pages (lit top edge, soft vertical gradient, inner highlight, glowing
   logo, inset fields, glossy button), kept in the Student gold.
   ===================================================================== */
:root{--gold-edge:#FBBF24;--input-bg:#0B1A32;--input-bg-hover:#0D2038;}
html{background:#0A192F;color-scheme:dark;}
body{
    background:
        radial-gradient(circle at 50% 36%,rgba(255,255,255,.028),transparent 34%),
        radial-gradient(circle at 88% 82%,rgba(43,108,176,.11),transparent 30%),
        radial-gradient(circle at 8% 12%,rgba(217,119,6,.09),transparent 26%),
        #0A192F;
    background-attachment:fixed;
}
body::before{display:none;}
.hex-deco{opacity:.46;filter:drop-shadow(0 0 10px rgba(217,119,6,.05));}

/* Card: gradient body, lit top edge, inner highlight, gold halo */
.reg-card{
    background:linear-gradient(180deg,#1D3555 0%,#172A45 38%,#122238 100%);
    border:1px solid rgba(217,119,6,.30);
    box-shadow:
        0 30px 80px rgba(0,0,0,.50),
        0 0 0 1px rgba(217,119,6,.08),
        inset 0 1px 0 rgba(255,255,255,.07),
        0 0 60px rgba(217,119,6,.07);
    transition:border-color .3s ease,box-shadow .3s ease;
}
.reg-card::before{
    content:"";position:absolute;top:-1px;left:14%;right:14%;height:2px;border-radius:2px;pointer-events:none;
    background:linear-gradient(90deg,transparent,rgba(251,191,36,.95),rgba(43,108,176,.85),transparent);
}
.reg-card:hover{
    border-color:rgba(245,158,11,.45);
    box-shadow:
        0 30px 80px rgba(0,0,0,.55),
        0 0 0 1px rgba(217,119,6,.10),
        inset 0 1px 0 rgba(255,255,255,.07),
        0 0 80px rgba(217,119,6,.11);
}

/* Header: ringed, glowing logo + stronger divider */
.logo-ring{
    width:76px;height:76px;margin:0 auto 14px;
    border:3px solid var(--gold-edge);
    box-shadow:0 0 0 5px rgba(251,191,36,.14),0 0 28px rgba(251,191,36,.45);
}
.card-header{margin-bottom:20px;}
.card-title{font-size:22px;letter-spacing:2px;}
.card-subtitle{font-size:11px;letter-spacing:1.6px;margin-top:5px;}
.access-badge{
    display:inline-flex;align-items:center;gap:8px;margin-top:14px;padding:7px 18px;
    border-radius:999px;border:1px solid rgba(251,191,36,.38);
    background:rgba(251,191,36,.08);
    font-size:12px;font-weight:700;letter-spacing:1.3px;text-transform:uppercase;color:var(--gold-edge);
}
.access-badge i{font-size:12px;}
.divider{background:linear-gradient(90deg,transparent,rgba(217,119,6,.55),transparent);}

/* Fields: inset depth, hover lift, glowing focus ring */
.form-input{
    background:var(--input-bg);
    border-color:rgba(255,255,255,.12);
    box-shadow:inset 0 2px 6px rgba(0,0,0,.30);
}
.form-input:hover:not(:focus){background:var(--input-bg-hover);border-color:rgba(255,255,255,.20);}
.form-input:focus{
    background:var(--input-bg-hover);
    border-color:var(--gold);
    box-shadow:inset 0 2px 6px rgba(0,0,0,.22),0 0 0 3px rgba(217,119,6,.22);
}
.input-wrap:focus-within .f-icon{color:var(--gold-edge);}
.toggle-pw:hover{color:#fff;}

/* Button: glossy gradient, highlight edge, glow */
.btn-register{
    background:linear-gradient(180deg,#F0A020 0%,#D97706 55%,#BE6405 100%);
    box-shadow:inset 0 1px 0 rgba(255,255,255,.26),0 10px 26px rgba(217,119,6,.34);
    transition:transform .15s,box-shadow .2s,filter .2s;
}
.btn-register:hover{
    background:linear-gradient(180deg,#F0A020 0%,#D97706 55%,#BE6405 100%);
    filter:brightness(1.08);transform:translateY(-1px);
    box-shadow:inset 0 1px 0 rgba(255,255,255,.30),0 14px 32px rgba(217,119,6,.44);
}
.btn-register:active{transform:translateY(1px);}
.btn-register:disabled{filter:none;opacity:.72;transform:none;}

.card-footer{border-top-color:rgba(255,255,255,.08);}
button:focus-visible,a:focus-visible,summary:focus-visible{outline:3px solid rgba(251,191,36,.38);outline-offset:2px;}
@media(prefers-reduced-motion:reduce){.reg-card{transition:none;}.btn-register{transition:none;}}

/* Security-questions panel — same gradient panel as the profile-photo box elsewhere */
.sq-section{
    background:linear-gradient(135deg,rgba(217,119,6,.10),rgba(10,27,46,.80) 55%);
    border:1px solid rgba(160,179,198,.18);
    box-shadow:inset 0 1px 0 rgba(255,255,255,.04),0 8px 22px rgba(0,0,0,.22);
}
.sq-section[open]{border-color:rgba(217,119,6,.34);}
.sq-head i{color:var(--gold-edge);}
</style>
	</head>
	<body>
	<div class="bg-grid"></div>
	<svg class="hex-deco hex-1" width="260" height="260" viewBox="0 0 260 260"><polygon points="130,10 240,70 240,190 130,250 20,190 20,70" fill="none" stroke="#D97706" stroke-width="1"/><polygon points="130,50 200,90 200,170 130,210 60,170 60,90" fill="none" stroke="#D97706" stroke-width="1"/></svg>
	<svg class="hex-deco hex-2" width="300" height="300" viewBox="0 0 300 300"><polygon points="150,10 280,80 280,220 150,290 20,220 20,80" fill="none" stroke="#2B6CB0" stroke-width="1"/><polygon points="150,60 220,100 220,200 150,240 80,200 80,100" fill="none" stroke="#2B6CB0" stroke-width="1"/></svg>

	<div class="reg-card">
		<div class="card-header">
			<img class="logo-ring" src="../image/pbi_logo" alt="PBI Logo"/>
			<div class="card-title">Student Registration</div>
			<div class="card-subtitle">Pandan Bay Institute Inc.</div>
			<div class="access-badge"><i class="fa-solid fa-user-graduate"></i> Student Access</div>
		</div>

		<?php if ($error): ?>
		<div class="alert alert-error">
			<i class="fa-solid fa-circle-exclamation" style="flex-shrink:0;margin-top:1px"></i>
			<span><?= htmlspecialchars($error) ?></span>
		</div>
		<?php endif; ?>

		<form method="POST" action="student_register.php" id="regForm" autocomplete="on" novalidate>
			<?= csrf_field() ?>
			<div class="divider"></div>

			<div class="form-group" style="margin-bottom:15px;">
				<label class="form-label">Full Name <span class="req">*</span></label>
				<div class="input-wrap">
					<input class="form-input" type="text" name="full_name"
						   placeholder="First Name, M.I. Last Name" autocomplete="name" required
						   value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>"/>
					<i class="fa-solid fa-id-card f-icon"></i>
				</div>
			</div>

			<div class="form-group" style="margin-bottom:15px;">
				<label class="form-label">School Level <span class="req">*</span></label>
				<div class="input-wrap select-arr">
					<select class="form-input" name="department" id="dept_input" required onchange="setDept(this.value)">
						<option value="" disabled <?= empty($_POST['department']) ? 'selected' : '' ?>>Select school level</option>
						<option value="JHS" <?= (($_POST['department'] ?? '') === 'JHS') ? 'selected' : '' ?>>JHS</option>
						<option value="SHS" <?= (($_POST['department'] ?? '') === 'SHS') ? 'selected' : '' ?>>SHS</option>
						<option value="College" <?= (($_POST['department'] ?? '') === 'College') ? 'selected' : '' ?>>College</option>
					</select>
					<i class="fa-solid fa-school f-icon"></i>
				</div>
			</div>

			<div class="form-grid">
				<div class="form-group">
					<label class="form-label">Year / Grade Level <span class="req">*</span></label>
					<div class="input-wrap select-arr">
						<select class="form-input" name="year_level" id="year_level" required>
							<option value="" disabled>Select level</option>
						</select>
						<i class="fa-solid fa-layer-group f-icon"></i>
					</div>
				</div>

				<div class="form-group">
					<label class="form-label">Username <span class="req">*</span></label>
					<div class="input-wrap">
						<input class="form-input" type="text" name="username"
							   placeholder="Choose a username" autocomplete="username" required
							   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"/>
						<i class="fa-solid fa-user f-icon"></i>
					</div>
				</div>

				<div class="form-group">
					<label class="form-label">Password <span class="req">*</span></label>
					<div class="input-wrap">
						<input class="form-input" type="password" id="pw1" name="password"
							   placeholder="Min. 8 characters" autocomplete="new-password" required/>
						<i class="fa-solid fa-lock f-icon"></i>
						<button type="button" class="toggle-pw" onclick="togglePw('pw1','e1')">
							<i class="fa-solid fa-eye-slash" id="e1"></i>
						</button>
					</div>
				</div>

				<div class="form-group">
					<label class="form-label">Confirm Password <span class="req">*</span></label>
					<div class="input-wrap">
						<input class="form-input" type="password" id="pw2" name="confirm_password"
							   placeholder="Re-enter password" autocomplete="new-password" required/>
						<i class="fa-solid fa-lock f-icon"></i>
						<button type="button" class="toggle-pw" onclick="togglePw('pw2','e2')">
							<i class="fa-solid fa-eye-slash" id="e2"></i>
						</button>
					</div>
				</div>
			</div>

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
				<div class="sq-item">
					<div class="input-wrap select-arr">
						<select class="form-input sq-select" name="sq_question[1]" id="sq_q1" required>
							<option value="" disabled selected>Question 1 — choose one</option>
							<?php foreach ($sq_all as $k => $q): ?><option value="<?= htmlspecialchars($k) ?>" <?= (($_POST['sq_question'][1] ?? '') === $k) ? 'selected' : '' ?>><?= htmlspecialchars($q) ?></option><?php endforeach; ?>
						</select>
					</div>
					<div class="sq-chosen" id="sq_chosen1" aria-live="polite"></div>
					<input class="form-input" type="text" name="sq_answer[1]" id="sq_a1" maxlength="100"
						   placeholder="Your answer" autocomplete="off" required/>
				</div>
				<div class="sq-item">
					<div class="input-wrap select-arr">
						<select class="form-input sq-select" name="sq_question[2]" id="sq_q2" required>
							<option value="" disabled selected>Question 2 — choose one</option>
							<?php foreach ($sq_all as $k => $q): ?><option value="<?= htmlspecialchars($k) ?>" <?= (($_POST['sq_question'][2] ?? '') === $k) ? 'selected' : '' ?>><?= htmlspecialchars($q) ?></option><?php endforeach; ?>
						</select>
					</div>
					<div class="sq-chosen" id="sq_chosen2" aria-live="polite"></div>
					<input class="form-input" type="text" name="sq_answer[2]" id="sq_a2" maxlength="100"
						   placeholder="Your answer" autocomplete="off" required/>
				</div>
				<div class="sq-item">
					<div class="input-wrap select-arr">
						<select class="form-input sq-select" name="sq_question[3]" id="sq_q3" required>
							<option value="" disabled selected>Question 3 — choose one</option>
							<?php foreach ($sq_all as $k => $q): ?><option value="<?= htmlspecialchars($k) ?>" <?= (($_POST['sq_question'][3] ?? '') === $k) ? 'selected' : '' ?>><?= htmlspecialchars($q) ?></option><?php endforeach; ?>
						</select>
					</div>
					<div class="sq-chosen" id="sq_chosen3" aria-live="polite"></div>
					<input class="form-input" type="text" name="sq_answer[3]" id="sq_a3" maxlength="100"
						   placeholder="Your answer" autocomplete="off" required/>
				</div>
				</div>
			</details>

			<div class="alert alert-error client-error" id="clientError" role="alert">
				<i class="fa-solid fa-circle-exclamation" style="flex-shrink:0;margin-top:1px"></i>
				<span id="clientErrorText"></span>
			</div>

			<button type="submit" class="btn-register" id="regSubmitBtn">
				<i class="fa-solid fa-user-plus"></i>
				<span id="regBtnLabel">Create JHS Account</span>
			</button>
		</form>

		<div class="card-footer">
			Already have an account? <a href="student_login.php">Sign in here</a><br><br>
		</div>
	</div>

	<script>
	const levels = {
		JHS:     ['Grade 7','Grade 8','Grade 9','Grade 10'],
		SHS:     ['Grade 11','Grade 12'],
		College: ['1st Year','2nd Year','3rd Year','4th Year']
	};
	function setDept(d) {
		document.getElementById('regBtnLabel').textContent = d ? ('Create ' + d + ' Account') : 'Create Account';

		const sel = document.getElementById('year_level');
		const previousLevel = <?= json_encode($_POST['year_level'] ?? '') ?>;

		sel.innerHTML = '<option value="" disabled selected>Select level</option>';

		(levels[d] || []).forEach(l => {
			const o = document.createElement('option');
			o.value = l;
			o.textContent = l;
			if (l === previousLevel) o.selected = true;
			sel.appendChild(o);
		});
	}
	// Returns an error message, or '' when the form looks fine (the server re-checks everything).
	function validateRegistration() {
		const form = document.getElementById('regForm');
		for (const field of form.querySelectorAll('[required]')) {
			if (!field.value.trim()) { field.focus(); return 'Please fill in every required field.'; }
		}
		const username = form.querySelector('[name="username"]');
		if (username && /\s/.test(username.value.trim())) { username.focus(); return 'Username cannot contain spaces.'; }
		const pw = document.getElementById('pw1'), pw2 = document.getElementById('pw2');
		if (pw.value.length < 8)  { pw.focus();  return 'Password must be at least 8 characters.'; }
		if (pw.value.length > 72) { pw.focus();  return 'Password must be 72 characters or fewer.'; }
		if (pw.value !== pw2.value) { pw2.focus(); return 'Passwords do not match.'; }
		const qs = [1,2,3].map(n => document.getElementById('sq_q' + n).value);
		if (new Set(qs).size !== 3) return 'Please choose three different security questions.';
		const ans = [1,2,3].map(n => document.getElementById('sq_a' + n).value.trim().toLowerCase().replace(/[^\p{L}\p{N}]+/gu, ''));
		for (let i = 0; i < 3; i++) {
			if (ans[i].length < 3) { document.getElementById('sq_a' + (i + 1)).focus(); return 'Answer ' + (i + 1) + ' is too short. Use at least 3 letters or numbers.'; }
		}
		return '';
	}

	// Keep the three question dropdowns from repeating each other.
	function syncQuestionChoices() {
		const selects = [1,2,3].map(n => document.getElementById('sq_q' + n));
		const chosen = selects.map(s => s.value).filter(Boolean);
		selects.forEach((s, i) => {
			for (const o of s.options) { o.disabled = o.value !== '' && chosen.includes(o.value) && o.value !== s.value; }
			if (!s.value) s.options[0].disabled = true;
			// Show the full chosen question (the dropdown itself cuts long text off)
			document.getElementById('sq_chosen' + (i + 1)).textContent = s.value ? s.options[s.selectedIndex].text : '';
		});
	}

	function togglePw(id, ic) {
		const e = document.getElementById(id), i = document.getElementById(ic);
		e.type = e.type === 'password' ? 'text' : 'password';
		i.className = e.type === 'password' ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
	}
	setDept(<?= json_encode($_POST['department'] ?? '') ?>);
	const regForm = document.getElementById('regForm');
	const regSubmitBtn = document.getElementById('regSubmitBtn');
	const clientError = document.getElementById('clientError');
	const securityQuestions = document.getElementById('securityQuestions');
	if (regForm && securityQuestions) {
		regForm.addEventListener('invalid', (e) => {
			if (e.target.closest('.sq-section')) securityQuestions.open = true;
		}, true);
	}
	if (regForm && regSubmitBtn) {
		regForm.addEventListener('submit', (e) => {
			const msg = validateRegistration();
			if (msg) {                       // stop here and tell the student what to fix; the button stays usable
				e.preventDefault();
				document.getElementById('clientErrorText').textContent = msg;
				clientError.classList.add('show');
				return;
			}
			clientError.classList.remove('show');
			regSubmitBtn.disabled = true;
			regSubmitBtn.querySelector('i').className = 'fa-solid fa-spinner fa-spin';
		});
		window.addEventListener('pageshow', (e) => {   // Back button: make the button usable again
			if (!e.persisted) return;
			regSubmitBtn.disabled = false;
			regSubmitBtn.querySelector('i').className = 'fa-solid fa-user-plus';
		});
	}
	document.querySelectorAll('.sq-select').forEach(s => s.addEventListener('change', syncQuestionChoices));
	syncQuestionChoices();
	</script>
	</body>
	</html>