<?php
// admin/settings.php
// Unified admin Settings center. Keeps configuration features inside one place.
require_once 'session_bootstrap.php';
require_once 'db.php';

$role = strtolower(trim((string)($_SESSION['role'] ?? '')));
$isSuperAdmin = in_array($role, ['superadmin','super admin'], true);
$defaultTab = $_GET['tab'] ?? 'system';
$allowedTabs = ['profile','system','archive','appearance'];
// System Archive now lives inside System & Period ("Schedule history"); old ?tab=archive links land there.
$openHistory = ($defaultTab === 'archive');
if ($openHistory) $defaultTab = 'system';
if (!in_array($defaultTab, $allowedTabs, true)) $defaultTab = 'system';

// ── PROFILE & SECURITY (account of the signed-in admin) ───────────────────────
if (!defined('UPLOAD_DIR')) define('UPLOAD_DIR', dirname(__DIR__) . '/image/');
if (!defined('UPLOAD_URL')) define('UPLOAD_URL', '../image/');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$meId = (int)($_SESSION['user_id'] ?? 0);
if (empty($_SESSION['settings_csrf'])) $_SESSION['settings_csrf'] = bin2hex(random_bytes(32));
$settingsCsrf = $_SESSION['settings_csrf'];

// ── SECURITY QUESTIONS POPUP: save (AJAX, returns JSON) ───────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['sq_ajax'] ?? '') === '1') {
    $out = ['ok' => false, 'message' => 'Something went wrong. Please try again.'];
    try {
        mysqli_report(MYSQLI_REPORT_OFF);
        if (!$meId) { throw new RuntimeException('not signed in'); }
        if (!hash_equals($settingsCsrf, (string)($_POST['csrf_token'] ?? ''))) {
            $out['message'] = 'Your session expired. Please reload the page and try again.';
        } else {
            if (!function_exists('sq_validate')) { require_once 'security.php'; }
            security_ensure_tables($mysqli);

            $sqUname = '';
            if ($stU = $mysqli->prepare('SELECT username FROM users WHERE id = ? LIMIT 1')) {
                $stU->bind_param('i', $meId); $stU->execute();
                $sqUname = (string)(($stU->get_result()->fetch_assoc())['username'] ?? ''); $stU->close();
            }

            // Find the stored password (supports the legacy plain-text column too).
            $storedPw = null;
            foreach (['password_hash', 'password'] as $col) {
                try {
                    $st = $mysqli->prepare("SELECT `$col` AS pw FROM users WHERE id = ? LIMIT 1");
                    if (!$st) continue;
                    $st->bind_param('i', $meId); $st->execute();
                    $r = $st->get_result()->fetch_assoc(); $st->close();
                    if ($r && isset($r['pw']) && (string)$r['pw'] !== '') { $storedPw = (string)$r['pw']; break; }
                } catch (Throwable $e) { /* column missing: try the next one */ }
            }
            $given = (string)($_POST['current_password'] ?? '');
            $pwOk = false;
            if ($storedPw !== null && $given !== '') {
                $pwOk = !empty(password_get_info($storedPw)['algo'])
                    ? password_verify($given, $storedPw)
                    : hash_equals($storedPw, $given);
            }
            if ((int)($_SESSION['sq_lock_until'] ?? 0) > time()) {
                $out['message'] = 'Too many incorrect attempts. Please wait a minute and try again.';
            } elseif (!$pwOk) {
                $_SESSION['sq_fail'] = (int)($_SESSION['sq_fail'] ?? 0) + 1;
                if ($_SESSION['sq_fail'] >= 5) { $_SESSION['sq_lock_until'] = time() + 60; $_SESSION['sq_fail'] = 0; }
                $out['message'] = 'Your current password is not correct.';
            } elseif (($_POST['sq_verify'] ?? '') === '1') {
                // Step 1: the password is confirmed; the questions can now be shown.
                unset($_SESSION['sq_fail']);
                $out = ['ok' => true, 'message' => 'Password confirmed.'];
            } else {
                unset($_SESSION['sq_fail']);
                // $existing lets a blank answer through for any question left unchanged (its saved answer is kept),
                // so a person can change just one of the three questions.
                [$sqErr, $sqRows] = sq_validate($_POST['sq_question'] ?? [], $_POST['sq_answer'] ?? [], $sqUname, sq_load($mysqli, $meId));
                if ($sqErr !== null) {
                    $out['message'] = $sqErr;
                } else {
                    $mysqli->begin_transaction();
                    try {
                        $del = $mysqli->prepare('DELETE FROM student_security_answers WHERE user_id = ?');
                        $del->bind_param('i', $meId); $del->execute(); $del->close();
                        sq_save($mysqli, $meId, $sqRows);
                        $mysqli->commit();
                        $_SESSION['settings_flash'] = ['type' => 'success', 'msg' => 'Security questions updated successfully.'];
                        $out = ['ok' => true, 'message' => 'Security questions updated successfully.'];
                    } catch (Throwable $e) {
                        try { $mysqli->rollback(); } catch (Throwable $ignored) {}
                        error_log('admin security questions save failed: ' . $e->getMessage());
                        $out['message'] = 'The security questions could not be saved. Please try again.';
                    }
                }
            }
        }
    } catch (Throwable $e) {
        error_log('admin security questions failed: ' . $e->getMessage());
    }
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($out);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['profile_action'])) {
    $flash = ['type' => 'error', 'msg' => 'Something went wrong. Please try again.'];
    try {
        if (!$meId) {
            $flash['msg'] = 'Please sign in again.';
        } elseif (!hash_equals($settingsCsrf, (string)($_POST['csrf_token'] ?? ''))) {
            $flash['msg'] = 'Your session expired. Please try again.';
        } else {
            $act = (string)$_POST['profile_action'];

            if ($act === 'update_profile') {
                $fullName = trim((string)($_POST['full_name'] ?? ''));
                $username = trim((string)($_POST['username'] ?? ''));
                if ($fullName === '' || mb_strlen($fullName) > 150) {
                    $flash['msg'] = 'Please enter your full name (up to 150 characters).';
                } elseif ($username === '' || mb_strlen($username) > 50 || preg_match('/\s/', $username)) {
                    $flash['msg'] = 'Username is required, up to 50 characters, with no spaces.';
                } else {
                    $chk = $mysqli->prepare("SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1");
                    $chk->bind_param('si', $username, $meId);
                    $chk->execute();
                    $taken = (bool)$chk->get_result()->fetch_assoc();
                    $chk->close();
                    if ($taken) {
                        $flash['msg'] = 'That username is already in use.';
                    } else {
                        $up = $mysqli->prepare("UPDATE users SET full_name = ?, username = ? WHERE id = ?");
                        $up->bind_param('ssi', $fullName, $username, $meId);
                        $up->execute();
                        $up->close();
                        $_SESSION['full_name'] = $fullName;
                        $flash = ['type' => 'success', 'msg' => 'Profile details updated. If you changed your username, use it the next time you sign in.'];
                    }
                }

            } elseif ($act === 'change_password') {
                $cur = (string)($_POST['current_password'] ?? '');
                $new = (string)($_POST['new_password'] ?? '');
                $con = (string)($_POST['confirm_password'] ?? '');
                $q = $mysqli->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
                $q->bind_param('i', $meId);
                $q->execute();
                $row = $q->get_result()->fetch_assoc();
                $q->close();
                if (!$row || !password_verify($cur, $row['password_hash'])) {
                    $flash['msg'] = 'Your current password is incorrect.';
                } elseif (strlen($new) < 8) {
                    $flash['msg'] = 'New password must be at least 8 characters.';
                } elseif (strlen($new) > 72) {
                    $flash['msg'] = 'New password must be 72 characters or fewer.';
                } elseif ($new !== $con) {
                    $flash['msg'] = 'New passwords do not match.';
                } elseif (password_verify($new, $row['password_hash'])) {
                    $flash['msg'] = 'New password must be different from your current password.';
                } else {
                    $hash = password_hash($new, PASSWORD_DEFAULT);
                    $up = $mysqli->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                    $up->bind_param('si', $hash, $meId);
                    $up->execute();
                    $up->close();
                    $flash = ['type' => 'success', 'msg' => 'Password updated.'];
                }

            } elseif ($act === 'change_photo') {
                $f = $_FILES['photo'] ?? null;
                if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) {
                    $flash['msg'] = 'Please choose a photo to upload.';
                } elseif ($f['error'] !== UPLOAD_ERR_OK) {
                    $flash['msg'] = 'The photo could not be uploaded.';
                } else {
                    $map  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
                    $mime = mime_content_type($f['tmp_name']);
                    if (!isset($map[$mime])) {
                        $flash['msg'] = 'Profile photo must be JPG, PNG, WebP, or GIF.';
                    } elseif ($f['size'] > 10 * 1024 * 1024) {
                        $flash['msg'] = 'Profile photo must be under 10 MB.';
                    } elseif (@getimagesize($f['tmp_name']) === false) {
                        $flash['msg'] = 'That file is not a valid image.';
                    } else {
                        if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
                        $fname = 'adm_' . bin2hex(random_bytes(12)) . '.' . $map[$mime];
                        if (!move_uploaded_file($f['tmp_name'], UPLOAD_DIR . $fname)) {
                            $flash['msg'] = 'Failed to save the photo. Check folder permissions on /image/.';
                        } else {
                            $o = $mysqli->prepare("SELECT photo FROM users WHERE id = ? LIMIT 1");
                            $o->bind_param('i', $meId);
                            $o->execute();
                            $oldRow = $o->get_result()->fetch_assoc();
                            $o->close();
                            $up = $mysqli->prepare("UPDATE users SET photo = ? WHERE id = ?");
                            $up->bind_param('si', $fname, $meId);
                            $up->execute();
                            $up->close();
                            $old = (string)($oldRow['photo'] ?? '');
                            if ($old !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $old) && is_file(UPLOAD_DIR . $old)) @unlink(UPLOAD_DIR . $old);
                            $flash = ['type' => 'success', 'msg' => 'Profile photo updated.'];
                        }
                    }
                }
            }
        }
    } catch (Throwable $e) {
        error_log('admin settings profile action failed: ' . $e->getMessage());
        $flash = ['type' => 'error', 'msg' => 'Something went wrong. Please try again.'];
    }
    $_SESSION['settings_flash'] = $flash;
    header('Location: settings.php?tab=profile');
    exit;
}

$settingsFlash = $_SESSION['settings_flash'] ?? null;
unset($_SESSION['settings_flash']);

$me = ['full_name' => '', 'username' => '', 'photo' => ''];
$sq_status = 'none';   // none | ok | retired
$sq_existing = [];
try { require_once 'security.php'; } catch (Throwable $e) { error_log('security.php failed: ' . $e->getMessage()); }
if ($meId) {
    try {
        $q = $mysqli->prepare("SELECT full_name, username, photo FROM users WHERE id = ? LIMIT 1");
        $q->bind_param('i', $meId);
        $q->execute();
        $me = array_merge($me, $q->get_result()->fetch_assoc() ?: []);
        $q->close();
        if (function_exists('sq_load')) {
            security_ensure_tables($mysqli);
            $sq_existing = sq_load($mysqli, $meId);
            if (count($sq_existing) === 3) {
                $sq_status = (function_exists('sq_needs_update') && sq_needs_update($sq_existing)) ? 'retired' : 'ok';
            }
        }
    } catch (Throwable $e) { error_log('admin settings profile load failed: ' . $e->getMessage()); }
}
$pfE = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<script>
// Apply saved appearance before first paint to avoid a flash of default styling.
(function(){
  try{
    var d=localStorage.getItem('pbiDensity')||'compact';
    var m=localStorage.getItem('pbiReduceMotion')==='1';
    var a=(localStorage.getItem('pbiAccent')||'#0F9F6E').replace(/^#1f9d63$/i,'#0F9F6E');
    var t=localStorage.getItem('pbiTheme')||'light';
    var resolved = t==='system' ? (window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light') : t;
    var html=document.documentElement;
    html.classList.toggle('density-comfortable', d==='comfortable');
    html.classList.toggle('reduce-motion', m);
    html.setAttribute('data-theme', resolved);
    html.style.setProperty('--accent', a);
  }catch(e){}
})();
</script>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Settings — PBI Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="admin_ui_theme.css">
<link rel="stylesheet" href="admin_compact_ui.css">
<style>
:root{--accent:#0F9F6E;--accent-soft:color-mix(in srgb,var(--accent) 12%,#fff);--bg:#f4f8ff;--text:#10243f;--muted:#66809c;--panel-bg:#fff;--panel-border:#dce8f5;--input-bg:#f9fbff;--input-border:#d4e2f2}
html[data-theme="dark"]{--bg:#0b1626;--text:#e7eef8;--muted:#8ba0bd;--panel-bg:#121f33;--panel-border:#22344d;--input-bg:#16243a;--input-border:#25374f;--accent-soft:color-mix(in srgb, var(--accent) 22%, #121f33)}
*{box-sizing:border-box}html,body{margin:0;min-height:100%;font-family:Inter,system-ui,sans-serif;background:var(--bg);color:var(--text)}
.settings-shell{padding:22px 24px 34px;max-width:1240px;margin:auto}
.settings-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-end;margin-bottom:16px}
.settings-title{font-size:25px;font-weight:800;letter-spacing:-.02em;margin:0 0 4px}.settings-sub{margin:0;color:var(--muted);font-size:13px}
.settings-badge{display:inline-flex;align-items:center;gap:7px;border:1px solid var(--panel-border);background:var(--panel-bg);border-radius:999px;padding:8px 11px;font-size:11px;font-weight:700;color:var(--muted)}
.layout{display:block}
.side{background:var(--panel-bg);border:1px solid var(--panel-border);border-radius:13px;padding:4px;box-shadow:0 4px 18px rgba(32,73,120,.07);display:flex;gap:3px;margin-bottom:15px;overflow-x:auto;-webkit-overflow-scrolling:touch}
.side button{flex:0 0 auto;border:0;background:transparent;text-align:left;padding:8px 12px;border-radius:8px;color:var(--muted);font:600 12px Inter;cursor:pointer;display:flex;gap:8px;align-items:center;white-space:nowrap}.side button:hover{background:var(--bg);color:var(--accent)}.side button.active{background:var(--accent-soft);color:var(--accent)}.side i{width:16px;text-align:center}
.toolbar{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:14px}
.toolbar .side{margin-bottom:0;max-width:100%}
.side-actions{margin-left:auto;display:flex;align-items:center;gap:12px}
.dirty-hint{display:inline-flex;align-items:center;gap:7px;font-size:12px;font-weight:600;color:#B26A06}
.dirty-hint[hidden]{display:none}
.dirty-hint i{width:8px;height:8px;border-radius:50%;background:#F0B429;box-shadow:0 0 0 3px rgba(240,180,41,.25)}
.side-save-btn{display:inline-flex;align-items:center;gap:8px;border:1px solid #0F9F6E;border-radius:10px;padding:9px 16px;font:700 12.5px Inter;cursor:pointer;background:#0F9F6E;color:#fff;white-space:nowrap;transition:background .15s,opacity .15s}
.side-save-btn:hover:not(:disabled){background:#0C7F59;border-color:#0C7F59}
.side-save-btn:disabled{background:transparent;color:var(--muted);border-color:var(--panel-border);cursor:not-allowed;opacity:.75}
/* Fill the workspace: only the form scrolls, not the whole page + the form */
html body.settings-page:not(.report-mode){height:100vh;overflow:hidden;box-sizing:border-box}
.settings-page .settings-shell{height:100%;display:flex;flex-direction:column}
.settings-page .layout{flex:1;min-height:0;display:flex;flex-direction:column}
.settings-page .panel{flex:1;min-height:0}
.settings-page .panel-card.section-panel{height:100%}
.settings-page .panel-card.section-panel > .frame{height:100%!important;min-height:0}
#section-system,#section-archive{border:0;box-shadow:none;border-radius:0;background:transparent}
.unsaved-banner{position:fixed;top:0;left:0;right:0;z-index:9999;display:flex;align-items:center;justify-content:center;gap:14px;padding:12px 20px;background:#FFF3D6;border-bottom:2px solid #F0B429;color:#7A5A0A;font:700 12.5px Inter;box-shadow:0 6px 16px rgba(122,90,10,.15);transform:translateY(-100%);opacity:0;transition:transform .22s ease,opacity .22s ease;pointer-events:none}
.unsaved-banner.show{transform:translateY(0);opacity:1;pointer-events:auto}
.unsaved-banner i{color:#D97706}
.unsaved-banner button{border:0;border-radius:8px;padding:6px 13px;font:700 11.5px Inter;cursor:pointer;background:#3B6FE0;color:#fff}
.unsaved-banner button:hover{background:#4C7CEA}
.panel{min-width:0}.panel-card{background:var(--panel-bg);border:1px solid var(--panel-border);border-radius:13px;box-shadow:0 4px 18px rgba(32,73,120,.07);overflow:hidden}.section-panel{display:block}
.panel-bar{padding:15px 17px;border-bottom:1px solid var(--panel-border);display:flex;align-items:center;justify-content:space-between;gap:10px}.panel-bar h2{margin:0;font-size:16px}.panel-bar p{margin:3px 0 0;color:var(--muted);font-size:11.5px}.panel-note{font-size:10.5px;color:var(--muted)}
.frame{width:100%;height:min(760px,calc(100vh - 205px));min-height:560px;border:0;display:block;background:var(--panel-bg);overflow:auto;overscroll-behavior:contain;}.appearance{padding:18px}.appearance-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.appearance-card{border:1px solid var(--panel-border);border-radius:11px;padding:15px}.appearance-card h3{margin:0 0 4px;font-size:13px}.appearance-card p{margin:0 0 12px;color:var(--muted);font-size:11px;line-height:1.45}.choice-row{display:flex;gap:7px;flex-wrap:wrap}.choice{border:1px solid var(--input-border);background:var(--input-bg);padding:8px 10px;border-radius:8px;font:600 11px Inter;color:var(--muted);cursor:pointer}.choice.active{border-color:var(--accent);background:var(--accent-soft);color:var(--accent)}.setting-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:11px 0;border-top:1px solid var(--panel-border)}.setting-row:first-child{border-top:0}.switch{position:relative;width:36px;height:20px;display:inline-block}.switch input{display:none}.track{position:absolute;inset:0;background:#cbd8e7;border-radius:99px;cursor:pointer}.track:after{content:'';position:absolute;width:14px;height:14px;top:3px;left:3px;border-radius:50%;background:#fff;transition:.18s}.switch input:checked+.track{background:var(--accent)}.switch input:checked+.track:after{left:19px}.toast{position:fixed;right:22px;bottom:22px;background:#10243f;color:#fff;padding:10px 13px;border-radius:9px;font-size:11px;opacity:0;transform:translateY(8px);pointer-events:none;transition:.2s}.toast.show{opacity:1;transform:none}
.swatch-row{display:flex;gap:9px;flex-wrap:wrap}
.swatch{width:26px;height:26px;border-radius:50%;border:0;cursor:pointer;background:var(--sw);box-shadow:0 0 0 1px var(--input-border);position:relative;padding:0}
.swatch.active{box-shadow:0 0 0 2px var(--panel-bg),0 0 0 4px var(--sw)}
.swatch.active::after{content:'\2713';position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px;font-weight:700;text-shadow:0 1px 1px rgba(0,0,0,.3)}
.reset-link{border:0;background:none;color:var(--muted);font:600 11px Inter;cursor:pointer;text-decoration:underline;white-space:nowrap;padding:0}
.reset-link:hover{color:var(--accent)}
html.reduce-motion *,html.reduce-motion *::before,html.reduce-motion *::after{transition-duration:0s !important;animation-duration:0s !important;scroll-behavior:auto !important}
html.density-comfortable .panel-bar{padding:20px 22px}
html.density-comfortable .appearance{padding:24px}
html.density-comfortable .appearance-grid{gap:16px}
html.density-comfortable .appearance-card{padding:18px}
html.density-comfortable .setting-row{padding:14px 0}
html.density-comfortable .side button{padding:10px 14px}
html.density-comfortable .choice{padding:10px 14px}
@media(max-width:820px){.appearance-grid{grid-template-columns:1fr}.settings-head{align-items:flex-start;flex-direction:column}}
/* ---- Profile & Security tab ---- */
#section-profile{overflow:auto}
.pf-alert{margin:16px 18px 0;padding:10px 13px;border-radius:9px;font-size:12.5px;font-weight:600;display:flex;gap:8px;align-items:center}
.pf-alert.success{background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.35);color:#0F9F6E}
.pf-alert.error{background:rgba(240,84,84,.10);border:1px solid rgba(240,84,84,.35);color:#E05252}
.pf-body{padding:18px;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;align-items:start}
.pf-card{border:1px solid var(--panel-border);border-radius:11px;padding:15px;background:var(--panel-bg)}
.pf-card.full{grid-column:1/-1}
.pf-card h3{margin:0 0 4px;font-size:13px}
.pf-card .pf-sub{margin:0 0 14px;color:var(--muted);font-size:11px;line-height:1.45}
.pf-photo{display:flex;align-items:center;gap:16px;flex-wrap:wrap}
.pf-avatar{width:72px;height:72px;border-radius:50%;object-fit:cover;border:2.5px solid var(--accent);display:flex;align-items:center;justify-content:center;background:var(--accent-soft);color:var(--accent);font-size:26px;flex:0 0 72px}
.pf-photo-form{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.pf-photo-form input[type=file]{font:500 12px Inter;color:var(--muted);max-width:260px}
.pf-help{font-size:10.5px;color:var(--muted);margin-top:6px;flex-basis:100%}
.pf-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-bottom:14px}
.pf-field label{display:block;margin:0 0 5px;font-size:10.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--muted)}
.pf-field input[type=text],.pf-field input[type=password]{width:100%;padding:10px 12px;border:1px solid var(--input-border);border-radius:9px;background:var(--input-bg);color:var(--text);font:500 13px Inter;outline:none}
.pf-field input:focus{border-color:var(--accent);box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 20%,transparent)}
.pf-pw{position:relative}.pf-pw input{padding-right:40px!important}
.pf-eye{position:absolute;right:8px;top:50%;transform:translateY(-50%);border:0;background:none;color:var(--muted);cursor:pointer;padding:5px;line-height:1}
.pf-eye:hover{color:var(--accent)}
.pf-btn{display:inline-flex;align-items:center;gap:8px;border:0;border-radius:9px;padding:10px 16px;font:700 12.5px Inter;color:#fff;background:var(--accent);cursor:pointer;text-decoration:none}
.pf-btn:hover{filter:brightness(1.08)}
.pf-status{display:inline-flex;align-items:center;gap:7px;padding:5px 11px;margin-bottom:14px;border-radius:999px;font-size:11.5px;font-weight:600;background:var(--accent-soft);border:1px solid var(--panel-border);color:var(--muted)}
.pf-status.ok{color:#10B981;border-color:rgba(16,185,129,.4)}
.pf-status.warn{color:#D97706;border-color:rgba(217,119,6,.45)}
@media(max-width:820px){.pf-body,.pf-grid{grid-template-columns:1fr}}
</style>
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
<link rel="stylesheet" href="admin_unified_ui.css?v=<?= (int)@filemtime(__DIR__ . '/admin_unified_ui.css') ?>">
</head>
<body class="settings-page">
<div class="unsaved-banner" id="unsavedBanner">
  <i class="fa-solid fa-triangle-exclamation"></i>
  <span>You have unsaved changes in System &amp; Period Settings.</span>
  <button id="bannerSaveBtn" type="button">Save now</button>
</div>
<div class="settings-shell">
  <div class="settings-head">
    <div><h1 class="settings-title">Settings</h1><p class="settings-sub"></p></div>
  </div>
  <div class="layout">
    <div class="toolbar">
    <aside class="side" aria-label="Settings sections">
      <button class="tab-btn" data-tab="system"><i class="fa-solid fa-sliders" style="color:#3B82F6"></i><span>System &amp; Period</span></button>
      <button class="tab-btn" data-tab="profile"><i class="fa-solid fa-user-gear" style="color:#10B981"></i><span>Profile &amp; Security</span></button>
      <button class="tab-btn" data-tab="appearance"><i class="fa-solid fa-palette" style="color:#EC4899"></i><span>Appearance</span></button>
    </aside>
    <div class="side-actions" id="sideActions" style="display:none">
      <span class="dirty-hint" id="dirtyHint" hidden><i></i>Unsaved changes</span>
      <button class="side-save-btn" id="sideSaveBtn" type="button" disabled><i class="fa-solid fa-floppy-disk"></i> Save changes</button>
    </div>
    </div>
    <main class="panel">
      <section class="panel-card section-panel" id="section-profile" style="display:none">
        <div class="panel-bar"><div><h2>Profile &amp; Security</h2><p>Manage your account details, password and recovery questions.</p></div></div>
        <?php if ($settingsFlash): ?>
        <div class="pf-alert <?= $settingsFlash['type'] === 'success' ? 'success' : 'error' ?>"><i class="fa-solid <?= $settingsFlash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i><span><?= $pfE($settingsFlash['msg']) ?></span></div>
        <?php endif; ?>
        <?php if (!$meId): ?>
        <div class="pf-alert error"><i class="fa-solid fa-circle-exclamation"></i><span>Please sign in again to manage your profile.</span></div>
        <?php else: ?>
        <div class="pf-body">
          <div class="pf-card full sq-top-card <?= $sq_status === 'ok' ? 'is-set' : 'is-unset' ?>" id="security-questions-settings">
            <div class="sq-top-row">
              <div class="sq-top-head">
                <span class="sq-top-icon"><i class="fa-solid <?= $sq_status === 'ok' ? 'fa-shield-halved' : 'fa-triangle-exclamation' ?>"></i></span>
                <div>
                  <h3>Security Questions</h3>
                  <p class="pf-sub">These questions are used to recover your account if you forget your password.</p>
                  <?php if ($sq_status === 'ok'): ?>
                  <div class="pf-status ok"><i class="fa-solid fa-circle-check"></i> Security questions are set</div>
                  <?php elseif ($sq_status === 'retired'): ?>
                  <div class="pf-status warn"><i class="fa-solid fa-triangle-exclamation"></i> One of your questions is no longer offered &mdash; please update them</div>
                  <?php else: ?>
                  <div class="pf-status warn"><i class="fa-solid fa-triangle-exclamation"></i> Not set up yet &mdash; you won&rsquo;t be able to recover your account without them</div>
                  <?php endif; ?>
                </div>
              </div>
              <div class="sq-top-action">
                <button type="button" class="pf-btn" id="sqOpen"><i class="fa-solid fa-pen-to-square"></i> <?= $sq_status === 'none' ? 'Set Security Questions' : 'Update Security Questions' ?></button>
              </div>
            </div>
          </div>

          <div class="pf-card full">
            <h3>Profile Photo</h3><p class="pf-sub">Update the photo shown in your sidebar and profile.</p>
            <div class="pf-photo">
              <?php if (!empty($me['photo'])): ?><img class="pf-avatar" src="<?= $pfE(UPLOAD_URL . $me['photo']) ?>" alt="Profile photo"><?php else: ?><div class="pf-avatar"><i class="fa-solid fa-user"></i></div><?php endif; ?>
              <form class="pf-photo-form" method="post" action="settings.php?tab=profile" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= $pfE($settingsCsrf) ?>"><input type="hidden" name="profile_action" value="change_photo">
                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp,image/gif" required>
                <button class="pf-btn" type="submit"><i class="fa-solid fa-upload"></i> Upload New Photo</button>
                <div class="pf-help">JPG, PNG, WEBP, or GIF only. Maximum file size: 10MB.</div>
              </form>
            </div>
          </div>

          <div class="pf-card full">
            <h3>Profile Details</h3><p class="pf-sub">Update your name and username. Changing your username changes what you sign in with.</p>
            <form method="post" action="settings.php?tab=profile" autocomplete="off">
              <input type="hidden" name="csrf_token" value="<?= $pfE($settingsCsrf) ?>"><input type="hidden" name="profile_action" value="update_profile">
              <div class="pf-grid">
                <div class="pf-field"><label for="pfName">Full Name</label><input id="pfName" type="text" name="full_name" maxlength="150" required value="<?= $pfE($me['full_name']) ?>"></div>
                <div class="pf-field"><label for="pfUser">Username</label><input id="pfUser" type="text" name="username" maxlength="50" pattern="\S+" title="Username cannot contain spaces" required value="<?= $pfE($me['username']) ?>"></div>
              </div>
              <button class="pf-btn" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button>
            </form>
          </div>

          <div class="pf-card full">
            <h3>Change Password</h3><p class="pf-sub">Your new password must be at least 8 characters and different from your current password.</p>
            <form method="post" action="settings.php?tab=profile" autocomplete="off" id="pfPwForm">
              <input type="hidden" name="csrf_token" value="<?= $pfE($settingsCsrf) ?>"><input type="hidden" name="profile_action" value="change_password">
              <div class="pf-grid">
                <div class="pf-field"><label for="pfCur">Current Password</label><div class="pf-pw"><input id="pfCur" type="password" name="current_password" required autocomplete="current-password"><button class="pf-eye" type="button" data-target="pfCur" aria-label="Show password"><i class="fa-solid fa-eye-slash"></i></button></div></div>
                <div></div>
                <div class="pf-field"><label for="pfNew">New Password</label><div class="pf-pw"><input id="pfNew" type="password" name="new_password" minlength="8" maxlength="72" required autocomplete="new-password"><button class="pf-eye" type="button" data-target="pfNew" aria-label="Show password"><i class="fa-solid fa-eye-slash"></i></button></div></div>
                <div class="pf-field"><label for="pfConf">Confirm New Password</label><div class="pf-pw"><input id="pfConf" type="password" name="confirm_password" minlength="8" maxlength="72" required autocomplete="new-password"><button class="pf-eye" type="button" data-target="pfConf" aria-label="Show password"><i class="fa-solid fa-eye-slash"></i></button></div></div>
              </div>
              <button class="pf-btn" type="submit"><i class="fa-solid fa-key"></i> Update Password</button>
            </form>
          </div>

        </div>
        <?php endif; ?>
      </section>
      <section class="panel-card section-panel" id="section-system">
        <iframe class="frame" title="System and Period Settings" src="system_settings.php<?= $openHistory ? '?view=history' : '' ?>" scrolling="yes" frameborder="0"></iframe>
      </section>
      <section class="panel-card section-panel" id="section-appearance" style="display:none">
        <div class="panel-bar"><div><h2>Appearance</h2><p>Adjust the admin interface to your preferred working style.</p></div><div style="display:flex;align-items:center;gap:12px"><span class="panel-note">Preferences are saved in this browser.</span><button class="reset-link" id="resetAppearance" type="button">Reset to defaults</button></div></div>
        <div class="appearance">
          <div class="appearance-grid">
            <div class="appearance-card">
              <h3>Interface Density</h3><p>Use compact spacing to fit more records and controls on a 100% desktop view.</p>
              <div class="choice-row" id="densityChoices"><button class="choice" data-density="compact">Compact</button><button class="choice" data-density="comfortable">Comfortable</button></div>
            </div>
            <div class="appearance-card">
              <h3>Motion</h3><p>Reduce transitions and animations across the admin interface.</p>
              <div class="setting-row"><span style="font-size:11px;font-weight:600">Reduce motion</span><label class="switch"><input type="checkbox" id="reduceMotion"><span class="track"></span></label></div>
            </div>
            <div class="appearance-card">
              <h3>Accent Color</h3><p>Sets the highlight color used for active tabs, buttons, and toggles.</p>
              <div class="swatch-row" id="accentChoices">
                <button class="swatch" data-accent="#2f6ee2" style="--sw:#2f6ee2" title="Blue" aria-label="Blue"></button>
                <button class="swatch" data-accent="#0F9F6E" style="--sw:#0F9F6E" title="Green" aria-label="Green"></button>
                <button class="swatch" data-accent="#8b5cf6" style="--sw:#8b5cf6" title="Purple" aria-label="Purple"></button>
                <button class="swatch" data-accent="#e2792f" style="--sw:#e2792f" title="Amber" aria-label="Amber"></button>
                <button class="swatch" data-accent="#e23f6c" style="--sw:#e23f6c" title="Rose" aria-label="Rose"></button>
              </div>
            </div>
            <div class="appearance-card">
              <h3>Theme</h3><p>Switch between light and dark, or follow your device setting.</p>
              <div class="choice-row" id="themeChoices"><button class="choice" data-theme="light">Light</button><button class="choice" data-theme="dark">Dark</button><button class="choice" data-theme="system">System</button></div>
            </div>
          </div>
        </div>
      </section>
    </main>
  </div>
</div>
<div class="toast" id="toast">Preference saved</div>
<script>
const initialTab=<?= json_encode($defaultTab) ?>;
const buttons=[...document.querySelectorAll('.tab-btn')], sections=[...document.querySelectorAll('.section-panel')];
function setTab(tab){buttons.forEach(b=>b.classList.toggle('active',b.dataset.tab===tab));sections.forEach(s=>s.style.display=s.id==='section-'+tab?'block':'none');history.replaceState(null,'','settings.php?tab='+encodeURIComponent(tab));const activeFrame=document.querySelector('#section-'+tab+' iframe.frame');if(activeFrame)prepareFrame(activeFrame);const sideActions=document.getElementById('sideActions');if(sideActions)sideActions.style.display=(tab==='system')?'flex':'none';}
// The System & Period iframe posts {type:'system-settings-dirty', dirty:bool}
// whenever its unsaved-changes state changes. We only ever use this to HIDE
// the banner once a save actually goes through (from either Save button, or
// the form's own bottom Save action) -- showing it is handled separately,
// only at the moment the user actually navigates away with changes pending
// (see the tab click handler below), not the instant they start typing.
window.addEventListener('message', (e) => {
  if (!e.data || e.data.type !== 'system-settings-dirty') return;
  const d = !!e.data.dirty;
  const sb = document.getElementById('sideSaveBtn'), hint = document.getElementById('dirtyHint');
  if (sb) sb.disabled = !d;
  if (hint) hint.hidden = !d;
  if (d) return;
  const banner = document.getElementById('unsavedBanner');
  if (banner) banner.classList.remove('show');
});
// ---- Hooks used by the admin dashboard's sidebar (admin_dashboard.php) ----
function systemFrameWin(){
  const f=document.querySelector('#section-system iframe.frame');
  return f ? f.contentWindow : null;
}
window.pbiUnsavedState=function(){
  const w=systemFrameWin();
  if(!w||typeof w.isSystemSettingsDirty!=='function') return {dirty:false,changes:[]};
  const dirty=!!w.isSystemSettingsDirty();
  return {dirty, changes: (dirty&&typeof w.getSystemSettingsChanges==='function') ? w.getSystemSettingsChanges() : []};
};
window.pbiSaveSystemSettings=function(){
  const w=systemFrameWin();
  return (w&&typeof w.saveSystemSettings==='function') ? Promise.resolve(w.saveSystemSettings()) : Promise.resolve(false);
};
window.pbiDiscardSystemSettings=function(){
  const w=systemFrameWin();
  try{ if(w&&typeof w.discardSystemSettingsChanges==='function') w.discardSystemSettingsChanges(); }catch(e){}
  location.reload(); // reload so the discarded edits are not still sitting in the hidden page
};
function triggerSystemSettingsSave(){
  const frame=document.querySelector('#section-system iframe.frame');
  if(frame&&frame.contentWindow&&typeof frame.contentWindow.saveSystemSettings==='function'){
    frame.contentWindow.saveSystemSettings();
  }
}
document.getElementById('sideSaveBtn').addEventListener('click', triggerSystemSettingsSave);
document.getElementById('bannerSaveBtn').addEventListener('click', triggerSystemSettingsSave);
function prepareFrame(iframe){
  // Keep a fixed viewport so long Settings forms scroll INSIDE the feature frame.
  // Do not redirect wheel events to window: Settings is itself commonly embedded
  // inside the dashboard content area, so window.scrollBy() can target the wrong scroller.
  /* height is handled by CSS (flex fill) so the page doesn't get a second scrollbar */
}
document.querySelectorAll('iframe.frame').forEach(f=>{
  f.addEventListener('load',()=>prepareFrame(f));
  prepareFrame(f);
});
buttons.forEach(b=>b.addEventListener('click',async ()=>{
  const current=buttons.find(x=>x.classList.contains('active'));
  const leavingSystemTab = current && current.dataset.tab==='system' && b.dataset.tab!=='system';
  if(leavingSystemTab){
    const frame=document.querySelector('#section-system iframe.frame');
    const dirty = frame && frame.contentWindow && typeof frame.contentWindow.isSystemSettingsDirty==='function' && frame.contentWindow.isSystemSettingsDirty();
    if(dirty){
      if(!(await PBI.confirm('You have unsaved changes in System & Period Settings. Are you sure you want to leave without saving?', {okLabel:'Leave without saving'}))){
        return;
      }
      // They chose to leave anyway -- surface the reminder banner now that
      // the change has actually been left behind unsaved.
      const banner=document.getElementById('unsavedBanner');
      if(banner) banner.classList.add('show');
    }
  }
  setTab(b.dataset.tab);
}));
setTab(initialTab);
const toast=document.getElementById('toast'); let timer; function saved(msg){clearTimeout(timer);toast.textContent=msg||'Preference saved';toast.classList.add('show');timer=setTimeout(()=>toast.classList.remove('show'),1400)}

// ---- Appearance ----
const APPEARANCE_DEFAULTS={density:'compact',motion:false,accent:'#0F9F6E',theme:'light'};
function readAppearance(){
  return {
    density: localStorage.getItem('pbiDensity')||APPEARANCE_DEFAULTS.density,
    motion: localStorage.getItem('pbiReduceMotion')==='1',
    accent: (localStorage.getItem('pbiAccent')||APPEARANCE_DEFAULTS.accent).replace(/^#1f9d63$/i,'#0F9F6E'),
    theme: localStorage.getItem('pbiTheme')||APPEARANCE_DEFAULTS.theme,
  };
}
function resolveTheme(pref){
  return pref==='system' ? (window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light') : pref;
}
function applyAppearance(){
  const a=readAppearance();
  const html=document.documentElement;
  html.classList.toggle('density-comfortable', a.density==='comfortable');
  html.classList.toggle('reduce-motion', a.motion);
  html.setAttribute('data-theme', resolveTheme(a.theme));
  html.style.setProperty('--accent', a.accent);
  document.querySelectorAll('#densityChoices [data-density]').forEach(b=>b.classList.toggle('active', b.dataset.density===a.density));
  document.getElementById('reduceMotion').checked=a.motion;
  document.querySelectorAll('#accentChoices [data-accent]').forEach(b=>b.classList.toggle('active', b.dataset.accent.toLowerCase()===a.accent.toLowerCase()));
  document.querySelectorAll('#themeChoices [data-theme]').forEach(b=>b.classList.toggle('active', b.dataset.theme===a.theme));
}
applyAppearance();

document.querySelectorAll('#densityChoices [data-density]').forEach(b=>b.addEventListener('click',()=>{localStorage.setItem('pbiDensity',b.dataset.density);applyAppearance();saved('Density updated');}));
document.getElementById('reduceMotion').addEventListener('change',e=>{localStorage.setItem('pbiReduceMotion',e.target.checked?'1':'0');applyAppearance();saved('Motion preference updated');});
document.querySelectorAll('#accentChoices [data-accent]').forEach(b=>b.addEventListener('click',()=>{localStorage.setItem('pbiAccent',b.dataset.accent);applyAppearance();saved('Accent color updated');}));
document.querySelectorAll('#themeChoices [data-theme]').forEach(b=>b.addEventListener('click',()=>{localStorage.setItem('pbiTheme',b.dataset.theme);applyAppearance();saved('Theme updated');}));

window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change',()=>{ if(readAppearance().theme==='system') applyAppearance(); });
window.addEventListener('storage', e=>{ if(['pbiDensity','pbiReduceMotion','pbiAccent','pbiTheme'].includes(e.key)) applyAppearance(); });

document.getElementById('resetAppearance').addEventListener('click',()=>{
  ['pbiDensity','pbiReduceMotion','pbiAccent','pbiTheme'].forEach(k=>localStorage.removeItem(k));
  applyAppearance();
  saved('Appearance reset to defaults');
});
</script>
<script>
// Profile & Security tab: show/hide password + confirm check
document.querySelectorAll('.pf-eye').forEach(function(btn){
  btn.addEventListener('click',function(){
    var el=document.getElementById(btn.dataset.target), ic=btn.querySelector('i'), show=el.type==='password';
    el.type=show?'text':'password';
    ic.className=show?'fa-solid fa-eye':'fa-solid fa-eye-slash';
    btn.setAttribute('aria-label',show?'Hide password':'Show password');
  });
});
var pfPw=document.getElementById('pfPwForm');
if(pfPw) pfPw.addEventListener('submit',function(e){
  if(document.getElementById('pfNew').value!==document.getElementById('pfConf').value){
    e.preventDefault(); alert('New passwords do not match.');
  }
});
</script>
<?php
$sqIsNew = ($sq_status === 'none');
$sq_existing = (isset($sq_existing) && is_array($sq_existing)) ? $sq_existing : [];
$sqQuestionList = [];
try { $sqQuestionList = sq_questions(); } catch (Throwable $e) { error_log('sq_questions failed: ' . $e->getMessage()); }
?>
<style id="sqm-styles">
.sq-top-card{position:relative;overflow:hidden;border-top:3px solid var(--accent)}
.sq-top-row{display:flex;align-items:center;gap:16px;flex-wrap:wrap}
.sq-top-head{display:flex;gap:14px;align-items:flex-start;flex:1;min-width:260px}
.sq-top-icon{flex:0 0 42px;height:42px;border-radius:12px;display:grid;place-items:center;background:var(--accent-soft);color:var(--accent);font-size:17px}
.sq-top-head h3{margin:0 0 3px;font-size:15px}
.sq-top-head .pf-sub{margin:0 0 8px}
.sq-top-head .pf-status{margin:0}
.sq-top-card.is-unset{border:2px solid #E2A33B;box-shadow:0 0 0 4px rgba(226,163,59,.16)}
.sq-top-card.is-unset .sq-top-icon{background:rgba(226,163,59,.18);color:#D97706}
.sqm-overlay{position:fixed;inset:0;z-index:2147483000;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(11,31,58,.55);overscroll-behavior:contain}
.sqm-overlay[hidden]{display:none}
.sqm-modal{--mid:var(--panel-bg,#fff);--light:var(--text,#0B1F3A);--inner:var(--input-bg,#F7FAFF);--sq-acc:var(--accent,#E8731A);--sq-acc-h:color-mix(in srgb,var(--accent,#E8731A) 82%,#fff);--sq-line:rgba(128,140,160,.30);width:min(620px,100%);max-height:calc(100vh - 40px);overflow:auto;background:var(--mid,#fff);border:1px solid var(--sq-line);border-radius:16px;padding:20px 22px;box-shadow:0 24px 60px rgba(11,31,58,.35);color:var(--light,#0B1F3A)}
.sqm-modal,.sqm-modal *{box-sizing:border-box;font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif}
.sqm-modal .fa-solid,.sqm-modal .fas{font-family:'Font Awesome 6 Free'!important;font-weight:900!important}
.sqm-modal:focus{outline:none}
.sqm-modal [hidden]{display:none !important}
.sqm-head{position:sticky;top:-20px;z-index:2;margin:-20px -22px 0;padding:20px 22px 6px;background:var(--mid,#fff);border-radius:16px 16px 0 0}
.sqm-head-row{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap}
.sqm-head-text{flex:1;min-width:220px}
.sqm-modal h3{font-size:18px;font-weight:700;line-height:1.25;color:var(--light,#0B1F3A);margin:0 0 4px}
.sqm-text{font-size:13.5px;line-height:1.5;color:var(--muted,#405A76);margin:0}
.sqm-head-actions{display:flex;gap:10px;flex:0 0 auto}
.sqm-btn{height:40px;padding:0 16px;border:1px solid var(--sq-line);background:transparent;color:var(--light,#0B1F3A);border-radius:10px;font-weight:700;font-size:13px;cursor:pointer}
.sqm-btn:hover{border-color:var(--sq-acc)}
.sqm-btn.primary{background:var(--sq-acc);border-color:var(--sq-acc);color:#fff}
.sqm-btn.primary:hover:not(:disabled){background:var(--sq-acc-h);border-color:var(--sq-acc-h)}
.sqm-btn.danger{background:#D6455D;border-color:#D6455D;color:#fff}
.sqm-btn:disabled{opacity:.55;cursor:not-allowed}
.sqm-error{margin-top:12px;padding:10px 12px;border-radius:10px;background:rgba(240,84,84,.12);border:1px solid rgba(240,84,84,.35);color:#E05252;font-size:12.5px;line-height:1.45;font-weight:600}
.sqm-discard{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:12px;padding:10px 12px;border:1px solid #F5D48A;background:rgba(245,212,138,.16);border-radius:10px;color:#B07400;font-size:13px}
.sqm-discard span{flex:1;min-width:180px}
.sqm-sec{margin:12px 0 0}
.sqm-sec-title{font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--muted,#67819E);margin:0 0 6px}
.sqm-confirm{display:flex;gap:12px;align-items:center;padding:12px 14px;border:2px solid var(--sq-acc);border-radius:12px;background:color-mix(in srgb,var(--sq-acc) 10%,transparent);box-shadow:0 0 0 4px color-mix(in srgb,var(--sq-acc) 12%,transparent)}
.sqm-lock{flex:0 0 38px;height:38px;border-radius:50%;display:grid;place-items:center;background:var(--sq-acc);color:#fff;font-size:15px}
.sqm-confirm-body{flex:1;min-width:0}
.sqm-confirm-title{font-size:14px;font-weight:800;color:var(--light,#0B1F3A)}
.sqm-confirm-sub{font-size:12px;color:var(--muted,#5C6B8A);margin:1px 0 6px}
.sqm-modal label{display:flex;flex-direction:column;gap:5px;font-size:12px;font-weight:700;color:var(--muted,#405A76)}
.sqm-modal input[type=text],.sqm-modal input[type=password],.sqm-modal select{width:100%;height:38px;padding:0 12px;border:1px solid var(--sq-line);border-radius:10px;font:500 14px Inter,system-ui,sans-serif;color:var(--light,#0B1F3A);background:var(--inner,#fff)}
.sqm-modal select{appearance:none;-webkit-appearance:none;cursor:pointer;padding-right:34px;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' fill='none' stroke='%2367819E' stroke-width='2' stroke-linecap='round'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center}
.sqm-modal input:focus,.sqm-modal select:focus{outline:2px solid color-mix(in srgb,var(--sq-acc) 35%,transparent);border-color:var(--sq-acc)}
.sqm-modal select.is-empty{color:var(--sq-acc);font-weight:700}
.sqm-modal select option{background:var(--mid,#fff);color:var(--light,#0B1F3A);font-weight:500}
.sqm-modal select option[value=""]{color:var(--sq-acc);font-weight:700}
.sqm-modal select option:disabled{color:#8A97A8}
.sqm-pw{position:relative}
.sqm-pw input{padding-right:44px !important}
.sqm-eye{position:absolute;right:6px;top:50%;transform:translateY(-50%);border:0;background:none;color:var(--muted,#67819E);cursor:pointer;padding:8px;line-height:1;display:flex;align-items:center;justify-content:center}
.sqm-eye:hover{color:var(--sq-acc)}
.sqm-q{display:flex;gap:12px;align-items:flex-start;margin:0 0 8px}
.sqm-num{flex:0 0 auto;width:28px;height:28px;margin-top:5px;border-radius:50%;display:grid;place-items:center;background:color-mix(in srgb,var(--sq-acc) 14%,transparent);color:var(--sq-acc);font-weight:800;font-size:12.5px}
.sqm-q-fields{flex:1;min-width:0;display:grid;gap:6px}
.sqm-chosen{font-size:12.5px;color:var(--muted,#405A76);line-height:1.4;padding:0 2px}
.sqm-chosen,.sqm-chosen:empty{display:none!important}
.sqm-hint{margin-top:4px;padding:9px 13px;border-radius:10px;font-size:12.5px;line-height:1.45;border:1px solid var(--sq-line);background:var(--inner,#F7FAFF);color:var(--muted,#405A76)}
.sqm-hint b{display:block;font-size:13.5px;color:var(--light,#0B1F3A)}
#sqmGate[hidden],#sqmQsec[hidden]{display:none!important}
.sqm-verify-row{display:flex;align-items:center;gap:12px;margin-top:10px;flex-wrap:wrap}
.sqm-verified{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;font-weight:700;color:#10B981}
.sqm-shake{animation:sqmShake .35s ease}
@keyframes sqmShake{0%,100%{transform:translateX(0)}25%{transform:translateX(-6px)}75%{transform:translateX(6px)}}
@media (max-height:720px){.sqm-modal{padding:16px 20px}.sqm-head{top:-16px;margin:-16px -20px 0;padding:16px 20px 4px}.sqm-sec{margin-top:8px}.sqm-modal input[type=text],.sqm-modal input[type=password],.sqm-modal select{height:34px}.sqm-q{margin-bottom:6px}.sqm-q-fields{gap:4px}.sqm-num{margin-top:3px}.sqm-confirm{padding:8px 12px}.sqm-hint{padding:6px 12px}}
</style>
<div class="sqm-overlay" id="sqmOverlay" hidden>
  <div class="sqm-modal" id="sqmModal" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="sqmTitle">
    <form id="sqmForm" autocomplete="off" novalidate>
      <input type="hidden" name="sq_ajax" value="1">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($settingsCsrf) ?>">
      <div class="sqm-head">
        <div class="sqm-head-row">
          <div class="sqm-head-text">
            <h3 id="sqmTitle"><?= $sqIsNew ? 'Set security questions' : 'Update security questions' ?></h3>
            <p class="sqm-text">Used to recover your account if you forget your password. Choose 3 different questions.</p>
          </div>
          <div class="sqm-head-actions">
            <button type="button" class="sqm-btn" id="sqmCancel">Cancel</button>
            <button type="submit" class="sqm-btn primary" id="sqmSave" disabled>Save questions</button>
          </div>
        </div>
        <div class="sqm-discard" id="sqmDiscard" hidden>
          <span>You have unsaved changes. Discard them?</span>
          <button type="button" class="sqm-btn" id="sqmKeep">Keep editing</button>
          <button type="button" class="sqm-btn danger" id="sqmDiscardGo">Discard</button>
        </div>
        <div class="sqm-error" id="sqmError" role="alert" hidden></div>
      </div>

      <div class="sqm-sec" id="sqmGate">
        <div class="sqm-sec-title">Confirm it&rsquo;s you</div>
        <div class="sqm-confirm">
          <span class="sqm-lock"><i class="fa-solid fa-lock"></i></span>
          <div class="sqm-confirm-body">
            <div class="sqm-confirm-title">Enter your current password first</div>
            <div class="sqm-confirm-sub">Confirm your password to unlock your questions.</div>
            <label>Current password
              <span class="sqm-pw">
                <input type="password" id="sqmPw" name="current_password" autocomplete="current-password" placeholder="Enter your current password">
                <button type="button" class="sqm-eye" id="sqmEye" aria-label="Show password" title="Show password"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.9 10.9 0 0 1 12 19C5.5 19 2 12 2 12a18.6 18.6 0 0 1 5.06-5.94"/><path d="M9.9 4.24A10.9 10.9 0 0 1 12 4c6.5 0 10 8 10 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12A3 3 0 1 1 9.88 9.88"/><path d="M1 1l22 22"/></svg></button>
              </span>
            </label>
            <div class="sqm-verify-row">
              <button type="button" class="sqm-btn primary" id="sqmVerify" disabled>Continue</button>
              <span class="sqm-verified" id="sqmVerified" hidden>&#10003; Password confirmed</span>
            </div>
          </div>
        </div>
      </div>

      <div class="sqm-sec" id="sqmQsec" hidden>
        <div class="sqm-sec-title">Your questions</div>
        <?php for ($n = 1; $n <= 3; $n++): ?>
        <div class="sqm-q">
          <span class="sqm-num"><?= $n ?></span>
          <div class="sqm-q-fields">
            <?php $sqCur = (string)($sq_existing[$n]['question_key'] ?? ''); if (!isset($sqQuestionList[$sqCur])) $sqCur = ''; ?>
            <select name="sq_question[<?= $n ?>]" id="sqmQ<?= $n ?>" class="<?= $sqCur === '' ? 'is-empty' : '' ?>" data-orig="<?= htmlspecialchars($sqCur) ?>" aria-label="Question <?= $n ?>">
              <option value="">Choose a question</option>
              <?php foreach ($sqQuestionList as $k => $q): ?><option value="<?= htmlspecialchars((string)$k) ?>"<?= ((string)$k === $sqCur) ? ' selected' : '' ?>><?= htmlspecialchars($q) ?></option><?php endforeach; ?>
            </select>
            <div class="sqm-chosen" id="sqmChosen<?= $n ?>" aria-live="polite"></div>
            <input type="text" name="sq_answer[<?= $n ?>]" id="sqmA<?= $n ?>" maxlength="100" placeholder="Your answer" autocomplete="off" aria-label="Answer <?= $n ?>">
          </div>
        </div>
        <?php endfor; ?>
        <div class="sqm-hint"><b><?= $sqIsNew ? 'Pick a question and give an answer only you would know.' : 'Your current questions are shown. Change just one if you like, and leave the other answers as they are to keep them.' ?></b>For your security, existing answers are never shown.</div>
      </div>
    </form>
  </div>
</div>
<script>
(function(){
  var ov=document.getElementById('sqmOverlay'), md=document.getElementById('sqmModal'), form=document.getElementById('sqmForm');
  var openBtn=document.getElementById('sqOpen'); if(!ov||!form||!openBtn) return;
  var save=document.getElementById('sqmSave'), err=document.getElementById('sqmError'), disc=document.getElementById('sqmDiscard');
  var pw=document.getElementById('sqmPw'), eye=document.getElementById('sqmEye');
  var qs=[1,2,3].map(function(n){return document.getElementById('sqmQ'+n);}), as=[1,2,3].map(function(n){return document.getElementById('sqmA'+n);});
  var busy=false, verified=false;
  function $q(id){ return (ov.ownerDocument||document).getElementById(id); }
  /* Show the dim background over the WHOLE page (sidebar and header too), not just this frame. */
  var VARS=['--accent','--accent-soft','--panel-bg','--text','--muted','--input-bg','--panel-border','--input-border'];
  function mountOnTop(){
    try{
      var T=window.top;
      if(!T||T===window||!T.document||!T.document.body) return;
      var cs=getComputedStyle(document.documentElement);
      VARS.forEach(function(v){ var val=cs.getPropertyValue(v); if(val) ov.style.setProperty(v,val.trim()); });
      var old=T.document.getElementById('sqmOverlay'); if(old&&old!==ov) old.remove();
      if(!T.document.getElementById('sqm-styles-top')){
        var st=T.document.createElement('style'); st.id='sqm-styles-top';
        st.textContent=document.getElementById('sqm-styles').textContent;
        T.document.head.appendChild(st);
      }
      if(ov.ownerDocument!==T.document) T.document.body.appendChild(ov);
    }catch(e){ /* different origin: keep the popup inside this frame */ }
  }
  window.addEventListener('pagehide',function(){ try{ if(ov.ownerDocument!==document) ov.remove(); }catch(e){} });
  function resetGate(){
    verified=false; pw.readOnly=false;
    $q('sqmGate').hidden=false;
    $q('sqmQsec').hidden=true;
    $q('sqmVerified').hidden=true;
    $q('sqmVerify').hidden=false;
  }
  var SQM_EYE='<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>';
  var SQM_SLASH='<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.9 10.9 0 0 1 12 19C5.5 19 2 12 2 12a18.6 18.6 0 0 1 5.06-5.94"/><path d="M9.9 4.24A10.9 10.9 0 0 1 12 4c6.5 0 10 8 10 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12A3 3 0 1 1 9.88 9.88"/><path d="M1 1l22 22"/></svg>';
  var orig=qs.map(function(s){return s.getAttribute('data-orig')||'';});
  function changedAt(i){ return qs[i].value!==orig[i] || as[i].value.trim()!==''; }
  function dirty(){ return (!verified && !!pw.value) || [0,1,2].some(changedAt); }
  function ready(){
    if(!verified || pw.value==='') return false;
    if(!qs.every(function(s){return s.value;})) return false;
    if(![0,1,2].some(changedAt)) return false;
    /* an answer is only optional for a question that is unchanged (its saved answer is kept) */
    return [0,1,2].every(function(i){ return as[i].value.trim()!=='' || (orig[i]!=='' && qs[i].value===orig[i]); });
  }
  function sync(){
    var chosen=qs.map(function(s){return s.value;}).filter(Boolean);
    qs.forEach(function(s,i){
      Array.prototype.forEach.call(s.options,function(o){ o.disabled=o.value!==''&&chosen.indexOf(o.value)!==-1&&o.value!==s.value; });
      s.classList.toggle('is-empty',!s.value);
      $q('sqmChosen'+(i+1)).textContent=s.value?s.options[s.selectedIndex].text:'';
    });
    as.forEach(function(a,i){ a.placeholder=(orig[i]!==''&&qs[i].value===orig[i])?'\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022':'Your answer'; });
    save.disabled=busy||!ready();
    $q('sqmVerify').disabled=busy||verified||pw.value==='';
  }
  function showErr(m){ err.textContent=m; err.hidden=!m; }
  function open(){
    form.reset(); resetGate(); showErr(''); disc.hidden=true; pw.type='password';
    eye.innerHTML=SQM_SLASH; eye.setAttribute('aria-label','Show password');
    sync(); mountOnTop(); ov.hidden=false; md.scrollTop=0;
    setTimeout(function(){ pw.focus({preventScroll:true}); },30);
  }
  function close(){ ov.hidden=true; openBtn.focus(); }
  function requestClose(){
    if(busy) return;
    if(dirty()){ disc.hidden=false; md.scrollTop=0; md.classList.remove('sqm-shake'); void md.offsetWidth; md.classList.add('sqm-shake'); }
    else close();
  }
  openBtn.addEventListener('click',open);
  $q('sqmCancel').addEventListener('click',requestClose);
  $q('sqmKeep').addEventListener('click',function(){ disc.hidden=true; });
  $q('sqmDiscardGo').addEventListener('click',close);
  ov.addEventListener('mousedown',function(e){ if(e.target===ov) requestClose(); });
  ov.addEventListener('keydown',function(e){ if(!ov.hidden&&e.key==='Escape'){ e.preventDefault(); requestClose(); } });
  form.addEventListener('input',sync); form.addEventListener('change',sync);
  eye.addEventListener('click',function(){
    var show=pw.type==='password'; pw.type=show?'text':'password';
    eye.innerHTML=show?SQM_EYE:SQM_SLASH;
    eye.setAttribute('aria-label',show?'Hide password':'Show password');
  });
  /* Step 1: the password must be confirmed on the server before the questions appear. */
  function verifyPw(){
    var vb=$q('sqmVerify');
    if(busy||verified||pw.value==='') return;
    busy=true; vb.disabled=true; vb.textContent='Checking\u2026'; showErr('');
    var fd=new FormData(form); fd.append('sq_verify','1');
    fetch('settings.php',{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(r){ return r.json(); })
      .then(function(d){
        if(d&&d.ok){
          verified=true; pw.readOnly=true;
          $q('sqmGate').hidden=true;
          $q('sqmQsec').hidden=false;
          $q('sqmVerified').hidden=false;
          vb.hidden=true;
          setTimeout(function(){ qs[0].focus({preventScroll:true}); },30);
        } else { showErr((d&&d.message)||'Your current password is not correct.'); pw.select(); }
      })
      .catch(function(){ showErr('Could not reach the server. Please try again.'); })
      .then(function(){ busy=false; vb.textContent='Continue'; sync(); });
  }
  $q('sqmVerify').addEventListener('click',verifyPw);
  pw.addEventListener('keydown',function(e){ if(e.key==='Enter'){ e.preventDefault(); verifyPw(); } });
  form.addEventListener('submit',function(e){
    e.preventDefault(); if(busy||!ready()) return;
    busy=true; save.disabled=true; save.textContent='Saving\u2026'; showErr('');
    fetch('settings.php',{method:'POST',body:new FormData(form),credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(r){ return r.json(); })
      .then(function(d){
        if(d&&d.ok){ location.href='settings.php?tab=profile'; return; }
        showErr((d&&d.message)||'The security questions could not be saved. Please try again.'); md.scrollTop=0;
      })
      .catch(function(){ showErr('Could not reach the server. Please try again.'); })
      .then(function(){ busy=false; save.textContent='Save questions'; sync(); });
  });
})();
</script>
<script src="admin_ajax.php"></script>
</body></html>