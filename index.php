<?php
// index/index.php
// Landing page — directs users to the portal that authenticates their role.
// Faculty and Staff intentionally share one portal; their assigned role
// determines the dashboard shown after sign-in.
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Pandan Bay Institute Inc. — Evaluation System</title>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<style>
:root{
    --dark:#0A192F;--mid:#172A45;--inner:#0F1F3D;
    --light:#E0E6F0;--muted:#A0B3C6;
    --border:rgba(255,255,255,0.08);--radius:14px;
    --shadow:0 8px 32px rgba(0,0,0,0.45);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{
    min-height:100vh;background:#0A192F;font-family:'DM Sans',sans-serif;
    color:var(--light);display:flex;align-items:center;justify-content:center;
    padding:32px;position:relative;overflow-x:hidden;
}
.bg-grid{
    position:fixed;inset:0;z-index:0;
    background-image:linear-gradient(rgba(43,108,176,.06) 1px,transparent 1px),
                      linear-gradient(90deg,rgba(43,108,176,.06) 1px,transparent 1px);
    background-size:48px 48px;
}
.bg-image-overlay{position:fixed;inset:0;z-index:0;background:rgba(10,25,47,.58);pointer-events:none;}
.wrap{position:relative;z-index:10;width:100%;max-width:1080px;text-align:center;margin:0 auto;}

.brand{margin-bottom:40px;}
.brand-logo{width:78px;height:78px;border-radius:50%;object-fit:cover;border:2.5px solid #D97706;
    box-shadow:0 0 22px rgba(217,119,6,.4);margin:0 auto 16px;display:block;}
.brand-title{font-family:'Rajdhani',sans-serif;font-size:30px;font-weight:700;letter-spacing:1.5px;color:#fff;}
.brand-sub{font-size:13px;color:var(--muted);letter-spacing:1px;margin-top:6px;text-transform:uppercase;}

.role-grid{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:18px;
    width:100%;
    max-width:1080px;
    margin:0 auto 8px;
    justify-content:center;
}
.role-card{
    background:var(--mid);border:1px solid var(--border);border-radius:var(--radius);
    padding:28px 22px;text-decoration:none;color:inherit;
    display:flex;flex-direction:column;align-items:center;gap:14px;
    transition:all .22s ease;position:relative;overflow:hidden;
}
.role-card:hover{transform:translateY(-4px);box-shadow:var(--shadow);border-color:var(--accent);}
.role-icon{
    width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center;
    font-size:22px;background:rgba(255,255,255,.06);color:var(--accent);
    border:2px solid var(--accent);
}
.role-name{font-family:'Rajdhani',sans-serif;font-size:18px;font-weight:700;color:#fff;}
.role-desc{font-size:12px;color:var(--muted);line-height:1.5;}

/* per-role accent colors, matched to each dashboard's existing theme */
.role-student   { --accent:#D97706; }
.role-faculty   { --accent:#2563EB; }
.role-staff     { --accent:#0D9488; }
.role-executive { --accent:#0D9488; }
.role-dean      { --accent:#7C5FD9; }
.role-principal { --accent:#D99A2B; }
.role-admin     { --accent:#4C78B8; }

.footer-note{margin-top:36px;font-size:12px;color:var(--muted);}
.footer-note a{color:#6ea8ff;text-decoration:none;font-weight:600;}
.footer-note a:hover{text-decoration:underline;}

@media(max-width:480px){.brand-title{font-size:24px;}}

.hex-deco{
    position:fixed;
    z-index:1;
    pointer-events:none;
    opacity:.9;
    filter:drop-shadow(0 0 8px rgba(43,108,176,.08));
}
/* Just two hexagons now, tucked into opposite corners so each one reads
   as a complete shape instead of being cropped behind the role cards. */
.hex-1{left:24px;top:24px;}
.hex-2{right:24px;bottom:24px;transform:scaleX(-1);}

@media(max-width:1200px){
    .hex-deco{opacity:.45;}
    .wrap{max-width:900px;}
}
@media(max-width:900px){
    .hex-deco{display:none;}
    .wrap{max-width:720px;}
    .role-grid{grid-template-columns:repeat(2,minmax(0,1fr));}
}
@media(max-width:600px){
    body{padding:22px 16px;}
    .wrap{max-width:100%;}
    .brand{margin-bottom:28px;}
    .role-grid{grid-template-columns:1fr;gap:14px;}
    .role-card{padding:24px 18px;}
}

</style>
</head>
<body>
<div class="bg-grid"></div>
<svg class="hex-deco hex-1" width="220" height="220" viewBox="0 0 260 260" aria-hidden="true">
    <polygon points="130,10 240,70 240,190 130,250 20,190 20,70" fill="none" stroke="#D97706" stroke-width="1"/>
    <polygon points="130,50 200,90 200,170 130,210 60,170 60,90" fill="none" stroke="#D97706" stroke-width="1"/>
</svg>
<svg class="hex-deco hex-2" width="220" height="220" viewBox="0 0 260 260" aria-hidden="true">
    <polygon points="130,10 240,70 240,190 130,250 20,190 20,70" fill="none" stroke="#2B6CB0" stroke-width="1"/>
    <polygon points="130,50 200,90 200,170 130,210 60,170 60,90" fill="none" stroke="#2B6CB0" stroke-width="1"/>
</svg>

<div class="wrap">
    <div class="brand">
        <img class="brand-logo" src="image/pbi_logo" alt="PBI Logo" onerror="this.style.display='none'"/>
        <div class="brand-title">Pandan Bay Institute Inc.</div>
        <div class="brand-sub">Employee Performance Evaluation and Management System</div>
    </div>

    <div class="role-grid">
        <a class="role-card role-executive" href="admin/admin_login.php">
            <div class="role-icon"><i class="fa-solid fa-briefcase"></i></div>
            <div class="role-name">Executive Assistant</div>
        </a>

        <a class="role-card role-dean" href="dean/dean_login.php">
            <div class="role-icon"><i class="fa-solid fa-building-columns"></i></div>
            <div class="role-name">Dean</div>
        </a>

        <a class="role-card role-principal" href="principal/principal_login.php">
            <div class="role-icon"><i class="fa-solid fa-school"></i></div>
            <div class="role-name">Principal</div>
        </a>

        <a class="role-card role-faculty" href="faculty/faculty_login.php">
            <div class="role-icon"><i class="fa-solid fa-chalkboard-user"></i></div>
            <div class="role-name">Faculty</div>
        </a>

       <a class="role-card role-staff" href="faculty/staff_login.php">
            <div class="role-icon"><i class="fa-solid fa-id-card-clip"></i></div>
            <div class="role-name">Staff</div>
        </a>

        <a class="role-card role-student" href="student/student_login.php">
            <div class="role-icon"><i class="fa-solid fa-user-graduate"></i></div>
            <div class="role-name">Student</div>
        </a>

    </div>

</div>

</body>
</html>