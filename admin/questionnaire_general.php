<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once 'db.php';
require_once '../shared/EvaluationContextService.php';
require_once '../shared/QuestionnaireService.php';

if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['superadmin','admin'], true)) {
    http_response_code(403);
    exit('Unauthorized');
}

qn_migrate_legacy_once($mysqli);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$scope = $_GET['scope'] ?? $_POST['scope'] ?? '';
if ($scope !== '' && !qn_scope_is_valid($scope)) $scope = '';

function qxm_sum_for(array $countsByUser, array $people): int {
    $t = 0;
    foreach ($people as $p) $t += (int)($countsByUser[(int)$p['id']] ?? 0);
    return $t;
}

function qxm_scope_target(string $scope): string {
    return match ($scope) {
        'faculty' => 'Faculty',
        'staff' => 'Staff',
        'school_head', 'ea' => 'EA',
        default => 'Faculty'
    };
}
function qxm_e(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}
function qxm_redirect(string $scope, ?int $userId = null, string $msg = ''): never {
    $url = 'questionnaire.php?scope=' . rawurlencode($scope);
    if ($userId) $url .= '&user_id=' . $userId;
    if ($msg !== '') $url .= '&msg=' . rawurlencode($msg);
    header('Location: ' . $url);
    exit;
}
function qxm_selected_user_scope(string $scope, int $userId): ?string {
    return $scope === 'staff' ? 'Staff'
        : ($scope === 'school_head' ? null
        : ($scope === 'ea' ? 'EA' : null));
}

function qxm_cat_label($c): string {
    $c = trim((string)$c);
    return strcasecmp($c, 'General') === 0 ? '' : $c; // legacy "General" shows as uncategorized
}
function qxm_category_options(array $cats, ?string $selected = null): void {
    $names = [];
    foreach ($cats as $c) $names[(string)$c['category_name']] = true;
    $names = array_keys($names);
    foreach ($names as $n) {
        if (strcasecmp($n, 'General') === 0) continue; // General category is no longer offered
        echo '<option value="' . qxm_e($n) . '"' . ($selected !== null && $n === $selected ? ' selected' : '') . '>' . qxm_e($n) . '</option>';
    }
}
function qxm_render_filterbar(string $targetId, array $cats, string $scope, int $userId, string $csrf, string $renameAction, string $deleteAction, bool $show = true): void {
    if (!$show) return;
    $ctx = ['csrf'=>$csrf,'scope'=>$scope,'user_id'=>$userId,'rename'=>$renameAction,'delete'=>$deleteAction];
    echo '<div class="filterbar" data-filter-target="' . qxm_e($targetId) . '" data-ctx="' . qxm_e(json_encode($ctx)) . '">';
    echo '<button type="button" class="filter-pill active" data-filter="all">All</button>';
    foreach ($cats as $cat) {
        $name = (string)$cat['category_name'];
        if (strcasecmp($name, 'General') === 0) continue; // hide General from the filter bar
        $id = (int)$cat['id'];
        echo '<span class="pill-group" data-cat-id="' . $id . '" data-cat-name="' . qxm_e($name) . '">';
        echo '<button type="button" class="filter-pill" data-filter="' . qxm_e($name) . '">' . qxm_e($name) . '</button>';
        echo '<button type="button" class="pill-icon pill-edit" title="Rename category" aria-label="Rename category"><i class="fa-solid fa-pen"></i></button>';
        echo '<button type="button" class="pill-icon pill-del" title="Delete category" aria-label="Delete category"><i class="fa-solid fa-xmark"></i></button>';
        echo '</span>';
    }
    echo '</div>';
}

$selectedUser = isset($_GET['user_id']) ? (int)$_GET['user_id'] : (isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0);
$message = (string)($_GET['msg'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($csrf, (string)$_POST['csrf_token'])) {
        http_response_code(419);
        exit('Invalid CSRF token.');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add_shared_category' && $scope === 'faculty') {
        $name = trim($_POST['category_name'] ?? '');
        if ($name !== '') {
            $count = $mysqli->query("SELECT COUNT(*)+1 AS n FROM question_categories WHERE target_type='Faculty' AND eval_type='general' AND evaluator_role='shared'")
                ->fetch_assoc()['n'] ?? 0;
            qn_ensure_shared_category($mysqli, 'Faculty', $name, (int)$count);
            qxm_redirect($scope, null, 'Category added.');
        }
    }

    if ($action === 'rename_shared_category' && $scope === 'faculty') {
        $id = (int)($_POST['category_id'] ?? 0);
        $newName = trim($_POST['category_name'] ?? '');
        if ($id > 0 && $newName !== '') {
            $stmt = $mysqli->prepare("SELECT category_name FROM question_categories WHERE id=? AND target_type='Faculty' AND eval_type='general' AND evaluator_role='shared' LIMIT 1");
            $stmt->bind_param('i', $id); $stmt->execute(); $old = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if ($old) {
                $stmt = $mysqli->prepare("UPDATE question_categories SET category_name=? WHERE id=?");
                $stmt->bind_param('si', $newName, $id); $stmt->execute(); $stmt->close();
                $stmt = $mysqli->prepare("UPDATE evaluation_questions SET category=? WHERE category=? AND target_type='Faculty' AND eval_type='general' AND evaluator_role='shared'");
                $stmt->bind_param('ss', $newName, $old['category_name']); $stmt->execute(); $stmt->close();
            }
            qxm_redirect($scope, null, 'Category renamed.');
        }
    }

    if ($action === 'rename_shared_category' && $scope === 'faculty') {
        $id = (int)($_POST['category_id'] ?? 0);
        $newName = trim($_POST['category_name'] ?? '');
        if ($id > 0 && $newName !== '') {
            $stmt = $mysqli->prepare("SELECT category_name FROM question_categories WHERE id=? AND target_type='Faculty' AND eval_type='general' AND evaluator_role='shared' LIMIT 1");
            $stmt->bind_param('i', $id); $stmt->execute(); $old = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if ($old) {
                $stmt = $mysqli->prepare("UPDATE question_categories SET category_name=? WHERE id=?");
                $stmt->bind_param('si', $newName, $id); $stmt->execute(); $stmt->close();
                $stmt = $mysqli->prepare("UPDATE evaluation_questions SET category=? WHERE target_type='Faculty' AND eval_type='general' AND evaluator_role='shared' AND category=?");
                $stmt->bind_param('ss', $newName, $old['category_name']); $stmt->execute(); $stmt->close();
            }
            qxm_redirect($scope, null, 'Category renamed.');
        }
    }

    if ($action === 'delete_shared_category' && $scope === 'faculty') {
        $id = (int)($_POST['category_id'] ?? 0);
        if ($id > 0) {
            $stmt = $mysqli->prepare("SELECT category_name FROM question_categories WHERE id=? AND target_type='Faculty' AND eval_type='general' AND evaluator_role='shared' LIMIT 1");
            $stmt->bind_param('i', $id); $stmt->execute(); $old = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if ($old) {
                $oldName = $old['category_name'];
                $stmt = $mysqli->prepare("UPDATE evaluation_questions SET category='' WHERE target_type='Faculty' AND eval_type='general' AND evaluator_role='shared' AND category=?");
                $stmt->bind_param('s', $oldName); $stmt->execute(); $stmt->close();
                $stmt = $mysqli->prepare("DELETE FROM question_categories WHERE id=?");
                $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();
            }
            qxm_redirect($scope, null, 'Category removed; its questions are now uncategorized.');
        }
    }

    if ($action === 'add_question') {
        $category = trim($_POST['category'] ?? '');
        $text = trim($_POST['question_text'] ?? '');
        if ($text === '') qxm_redirect($scope, $selectedUser, 'Question text is required.');
        if ($category === '' || strcasecmp($category, 'General') === 0) qxm_redirect($scope, $selectedUser, 'Please select a category.');

        if ($scope === 'faculty') {
            qn_ensure_shared_question($mysqli, 'Faculty', $category, $text, 0);
        } else {
            if ($selectedUser <= 0) qxm_redirect($scope, null, 'Select a target person first.');
            $targetType = qxm_selected_user_scope($scope, $selectedUser);
            if ($scope === 'school_head') {
                $stmt = $mysqli->prepare("SELECT role FROM users WHERE id=? AND role IN ('dean','principal') AND is_active=1 AND account_status='approved' LIMIT 1");
                $stmt->bind_param('i', $selectedUser); $stmt->execute(); $u = $stmt->get_result()->fetch_assoc(); $stmt->close();
                $targetType = $u ? ucfirst(strtolower($u['role'])) : null;
            }
            if ($scope === 'ea') $targetType = 'EA';
            if (!$targetType) qxm_redirect($scope, null, 'Invalid target.');
            qn_ensure_user_question($mysqli, $selectedUser, $targetType, $category, $text, 0);
        }
        qxm_redirect($scope, $selectedUser, 'Question added.');
    }

    if ($action === 'update_question') {
        $qid = (int)($_POST['question_id'] ?? 0);
        $category = trim($_POST['category'] ?? '');
        $text = trim($_POST['question_text'] ?? '');
        if ($qid <= 0 || $text === '') qxm_redirect($scope, $selectedUser, 'Invalid question update.');
        if ($category === '' || strcasecmp($category, 'General') === 0) qxm_redirect($scope, $selectedUser, 'Please select a category.');

        if ($scope === 'faculty') {
            $stmt = $mysqli->prepare("UPDATE evaluation_questions SET question_text=?, category=? WHERE id=? AND target_type='Faculty' AND eval_type='general' AND evaluator_role='shared'");
            $stmt->bind_param('ssi', $text, $category, $qid); $stmt->execute(); $stmt->close();
        } else {
            $targetType = qxm_selected_user_scope($scope, $selectedUser);
            if ($scope === 'school_head') {
                $stmt = $mysqli->prepare("SELECT role FROM users WHERE id=? LIMIT 1");
                $stmt->bind_param('i', $selectedUser); $stmt->execute(); $u = $stmt->get_result()->fetch_assoc(); $stmt->close();
                $targetType = $u ? ucfirst(strtolower($u['role'])) : null;
            }
            if ($scope === 'ea') $targetType = 'EA';
            if ($targetType) {
                $stmt = $mysqli->prepare("UPDATE user_questions SET question_text=?, category=? WHERE id=? AND user_id=? AND target_type=? AND eval_type='general'");
                $stmt->bind_param('ssiis', $text, $category, $qid, $selectedUser, $targetType); $stmt->execute(); $stmt->close();
                qn_ensure_user_category($mysqli, $selectedUser, $targetType, $category, 0);
            }
        }
        qxm_redirect($scope, $selectedUser, 'Question updated.');
    }

    if ($action === 'delete_question') {
        $qid = (int)($_POST['question_id'] ?? 0);
        if ($scope === 'faculty') {
            $stmt = $mysqli->prepare("UPDATE evaluation_questions SET is_active=0 WHERE id=? AND target_type='Faculty' AND eval_type='general' AND evaluator_role='shared'");
            $stmt->bind_param('i', $qid); $stmt->execute(); $stmt->close();
        } else {
            $targetType = qxm_selected_user_scope($scope, $selectedUser);
            if ($scope === 'school_head') {
                $stmt = $mysqli->prepare("SELECT role FROM users WHERE id=? LIMIT 1");
                $stmt->bind_param('i', $selectedUser); $stmt->execute(); $u = $stmt->get_result()->fetch_assoc(); $stmt->close();
                $targetType = $u ? ucfirst(strtolower($u['role'])) : null;
            }
            if ($scope === 'ea') $targetType = 'EA';
            if ($targetType) {
                $stmt = $mysqli->prepare("DELETE FROM user_questions WHERE id=? AND user_id=? AND target_type=? AND eval_type='general'");
                $stmt->bind_param('iis', $qid, $selectedUser, $targetType); $stmt->execute(); $stmt->close();
            }
        }
        qxm_redirect($scope, $selectedUser, 'Question removed.');
    }

    if ($action === 'rename_user_category') {
        if ($selectedUser <= 0) qxm_redirect($scope, null, 'Select a target person first.');
        $catId = (int)($_POST['category_id'] ?? 0);
        $newName = trim($_POST['category_name'] ?? '');
        $targetType = qxm_selected_user_scope($scope, $selectedUser);
        if ($scope === 'school_head') {
            $stmt = $mysqli->prepare("SELECT role FROM users WHERE id=? AND role IN ('dean','principal') LIMIT 1");
            $stmt->bind_param('i', $selectedUser); $stmt->execute(); $u = $stmt->get_result()->fetch_assoc(); $stmt->close();
            $targetType = $u ? ucfirst(strtolower($u['role'])) : null;
        }
        if ($scope === 'ea') $targetType = 'EA';
        if ($catId > 0 && $newName !== '' && $targetType) {
            $stmt = $mysqli->prepare("SELECT category_name FROM user_question_categories WHERE id=? AND user_id=? AND target_type=? AND eval_type='general' LIMIT 1");
            $stmt->bind_param('iis', $catId, $selectedUser, $targetType);
            $stmt->execute(); $old = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if ($old) {
                $stmt = $mysqli->prepare("UPDATE user_question_categories SET category_name=? WHERE id=?");
                $stmt->bind_param('si', $newName, $catId); $stmt->execute(); $stmt->close();
                $stmt = $mysqli->prepare("UPDATE user_questions SET category=? WHERE user_id=? AND target_type=? AND eval_type='general' AND category=?");
                $stmt->bind_param('siss', $newName, $selectedUser, $targetType, $old['category_name']); $stmt->execute(); $stmt->close();
            }
        }
        qxm_redirect($scope, $selectedUser, 'Category renamed.');
    }

    if ($action === 'delete_user_category') {
        if ($selectedUser <= 0) qxm_redirect($scope, null, 'Select a target person first.');
        $catId = (int)($_POST['category_id'] ?? 0);
        $targetType = qxm_selected_user_scope($scope, $selectedUser);
        if ($scope === 'school_head') {
            $stmt = $mysqli->prepare("SELECT role FROM users WHERE id=? AND role IN ('dean','principal') LIMIT 1");
            $stmt->bind_param('i', $selectedUser); $stmt->execute(); $u = $stmt->get_result()->fetch_assoc(); $stmt->close();
            $targetType = $u ? ucfirst(strtolower($u['role'])) : null;
        }
        if ($scope === 'ea') $targetType = 'EA';
        if ($catId > 0 && $targetType) {
            $stmt = $mysqli->prepare("SELECT category_name FROM user_question_categories WHERE id=? AND user_id=? AND target_type=? AND eval_type='general' LIMIT 1");
            $stmt->bind_param('iis', $catId, $selectedUser, $targetType);
            $stmt->execute(); $old = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if ($old) {
                $stmt = $mysqli->prepare("UPDATE user_questions SET category='' WHERE user_id=? AND target_type=? AND eval_type='general' AND category=?");
                $stmt->bind_param('iss', $selectedUser, $targetType, $old['category_name']); $stmt->execute(); $stmt->close();
                $stmt = $mysqli->prepare("DELETE FROM user_question_categories WHERE id=?");
                $stmt->bind_param('i', $catId); $stmt->execute(); $stmt->close();
            }
        }
        qxm_redirect($scope, $selectedUser, 'Category removed; its questions are now uncategorized.');
    }

    if ($action === 'add_user_category') {
        if ($selectedUser <= 0) qxm_redirect($scope, null, 'Select a target person first.');
        $name = trim($_POST['category_name'] ?? '');
        if ($name !== '') {
            $targetType = qxm_selected_user_scope($scope, $selectedUser);
            if ($scope === 'school_head') {
                $stmt = $mysqli->prepare("SELECT role FROM users WHERE id=? AND role IN ('dean','principal') LIMIT 1");
                $stmt->bind_param('i', $selectedUser); $stmt->execute(); $u = $stmt->get_result()->fetch_assoc(); $stmt->close();
                $targetType = $u ? ucfirst(strtolower($u['role'])) : null;
            }
            if ($scope === 'ea') $targetType = 'EA';
            if ($targetType) {
                $r = $mysqli->query("SELECT COUNT(*)+1 AS n FROM user_question_categories WHERE user_id=".(int)$selectedUser." AND target_type='". $mysqli->real_escape_string($targetType) ."' AND eval_type='general'");
                $n = (int)($r?->fetch_assoc()['n'] ?? 0);
                qn_ensure_user_category($mysqli, $selectedUser, $targetType, $name, $n);
            }
        }
        qxm_redirect($scope, $selectedUser, 'Category added.');
    }
}

$labels = qn_scope_labels();
$counts = qn_question_counts($mysqli);

$facultyQuestions = qn_get_faculty_questions($mysqli);
usort($facultyQuestions, fn($x, $y) => (int)$y['id'] <=> (int)$x['id']); // newest first

$facultyUsers = [];
$staffUsers = [];
$headUsers = [];
$eaUsers = [];

$ures = $mysqli->query("SELECT id, full_name, designation, photo, role, secondary_role
                        FROM users
                        WHERE role IN ('teacher','staff') AND is_active=1 AND account_status='approved'
                        ORDER BY full_name");
if ($ures) {
    while ($u = $ures->fetch_assoc()) {
        $u['id'] = (int)$u['id'];
        $isTeaching = ec_has_teacher_function($u);
        if (!$isTeaching) {
            $stmt = $mysqli->prepare("SELECT
                    NOT EXISTS(SELECT 1 FROM teaching_assignments WHERE user_id=?) AS no_teaching,
                    NOT EXISTS(SELECT 1 FROM user_year_levels WHERE user_id=?) AS no_levels");
            $stmt->bind_param('ii', $u['id'], $u['id']);
            $stmt->execute();
            $x = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $isTeaching = !((int)($x['no_teaching'] ?? 0) && (int)($x['no_levels'] ?? 0));
        }
        if ($isTeaching) $facultyUsers[] = $u;
        if (ec_has_staff_function($u) && ec_is_non_teaching_staff($mysqli, $u['id'])) $staffUsers[] = $u;
    }
}
$hres = $mysqli->query("SELECT id, full_name, designation, photo, role
                        FROM users
                        WHERE role IN ('dean','principal') AND is_active=1 AND account_status='approved'
                        ORDER BY full_name");
if ($hres) $headUsers = $hres->fetch_all(MYSQLI_ASSOC);
$eres = $mysqli->query("SELECT id, full_name, designation, photo, role
                        FROM users
                        WHERE role='superadmin' AND is_active=1 AND account_status='approved'
                        ORDER BY updated_at DESC, full_name");
if ($eres) $eaUsers = $eres->fetch_all(MYSQLI_ASSOC);

$selectedTargetType = null;
$selectedPerson = null;
$personQuestions = [];
$personCategories = [];
if ($selectedUser > 0 && $scope !== 'faculty') {
    if ($scope === 'staff') {
        foreach ($staffUsers as $u) if ((int)$u['id'] === $selectedUser) $selectedPerson = $u;
        $selectedTargetType = 'Staff';
    } elseif ($scope === 'school_head') {
        foreach ($headUsers as $u) if ((int)$u['id'] === $selectedUser) $selectedPerson = $u;
        if ($selectedPerson) $selectedTargetType = ucfirst(strtolower($selectedPerson['role']));
    } elseif ($scope === 'ea') {
        foreach ($eaUsers as $u) if ((int)$u['id'] === $selectedUser) $selectedPerson = $u;
        $selectedTargetType = 'EA';
    }
    if ($selectedPerson && $selectedTargetType) {
        $personQuestions = qn_get_person_questions($mysqli, $selectedUser, $selectedTargetType);
        usort($personQuestions, fn($x, $y) => (int)$y['id'] <=> (int)$x['id']); // newest first
        $stmt = $mysqli->prepare("SELECT id, category_name, 0 AS sort_order
                                  FROM user_question_categories
                                  WHERE user_id=? AND target_type=? AND eval_type='general'
                                  ORDER BY category_name, id");
        $stmt->bind_param('is', $selectedUser, $selectedTargetType);
        $stmt->execute();
        $personCategories = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

$facultyCategories = [];
$cr = $mysqli->query("SELECT id, category_name, 0 AS sort_order
                      FROM question_categories
                      WHERE target_type='Faculty' AND eval_type='general' AND evaluator_role='shared'
                      ORDER BY category_name, id");
if ($cr) $facultyCategories = $cr->fetch_all(MYSQLI_ASSOC);

$personQuestionCounts = [];
if ($scope !== 'faculty') {
    $r = $mysqli->query("SELECT user_id, target_type, COUNT(*) AS c FROM user_questions WHERE eval_type='general' GROUP BY user_id,target_type");
    if ($r) while ($row=$r->fetch_assoc()) $personQuestionCounts[(int)$row['user_id']][(string)$row['target_type']] = (int)$row['c'];
}

$scopePeople = match ($scope) {
    'staff' => $staffUsers,
    'school_head' => $headUsers,
    'ea' => $eaUsers,
    default => [],
};
$selectedPersonName = $selectedPerson['full_name'] ?? '';

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Questionnaire — PBI</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{--bg:#f8fafc;--card:#fff;--line:#e2e8f0;--text:#0f172a;--muted:#64748b;--blue:#2563eb;--green:#059669;--danger:#dc2626;--navy:#0a192f}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,system-ui,sans-serif}.wrap{padding:24px 28px 40px;max-width:1400px;margin:auto}.landing-center{min-height:100vh;display:flex;align-items:center;justify-content:center}.landing-center .wrap{width:100%;padding-top:24px;padding-bottom:24px}
.header{display:flex;justify-content:space-between;gap:16px;align-items:flex-end;margin-bottom:18px}.title h1{margin:0;font-size:28px}.title p{margin:6px 0 0;color:var(--muted);font-size:13px}
.tabs{display:flex;gap:6px;flex-wrap:wrap;background:#fff;border:1px solid var(--line);padding:6px;border-radius:12px;box-shadow:0 4px 16px rgba(15,23,42,.05);margin-bottom:18px}.back-link{display:inline-flex;align-items:center;gap:7px;padding:9px 12px;border:1px solid #cbd5e1;border-radius:9px;background:#fff;color:#334155;text-decoration:none;font-size:12px;font-weight:800}.back-link:hover{background:#f8fafc}.tab{display:flex;align-items:center;gap:9px;padding:10px 15px;border-radius:9px;color:var(--muted);text-decoration:none;font-weight:700;font-size:13px}.tab.active{background:var(--navy);color:#fff}.tab .count{font-size:11px;opacity:.8}.grid{display:grid;grid-template-columns:340px 1fr;gap:18px}.card{background:#fff;border:1px solid var(--line);border-radius:14px;box-shadow:0 4px 16px rgba(15,23,42,.05)}.card h2{font-size:16px;margin:0}.cardhead{padding:16px 18px;border-bottom:1px solid var(--line)}.cardbody{padding:16px 18px}.person{display:flex;justify-content:space-between;gap:10px;padding:11px 10px;border-radius:10px;text-decoration:none;color:var(--text);border:1px solid transparent;margin-bottom:6px}.person:hover,.person.active{background:#f8fafc;border-color:#cbd5e1}.person-main{display:flex;align-items:center;gap:10px;min-width:0}.avatar{width:40px;height:40px;border-radius:50%;background:#eef2f7;display:flex;align-items:center;justify-content:center;overflow:hidden;color:#94a3b8;border:1px solid #e2e8f0;flex-shrink:0}.avatar img{width:100%;height:100%;object-fit:cover;object-position:top center}.person-name{display:block;font-weight:700;font-size:13px}.person-meta{display:block;font-size:11px;color:var(--muted);margin-top:2px}.badge{font-size:11px;font-weight:800;padding:4px 8px;border-radius:999px;background:#f1f5f9;color:#475569;white-space:nowrap}.badge.has{background:#ecfdf5;color:#047857}.empty{padding:30px 10px;text-align:center;color:var(--muted)}.empty i{font-size:28px;margin-bottom:10px;opacity:.35}.toolbar{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:12px}.subtle{color:var(--muted);font-size:12px}.list{display:grid;gap:10px}.qrow{border:1px solid var(--line);border-radius:12px;padding:12px}.qtop{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}.qtext{font-size:13.5px;line-height:1.45}.qcat{font-size:11px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:999px;padding:3px 8px;white-space:nowrap}.actions{display:flex;gap:6px}.btn{border:1px solid #cbd5e1;background:#fff;color:#334155;padding:8px 11px;border-radius:8px;text-decoration:none;font-size:12px;font-weight:700;cursor:pointer}.btn.primary{background:var(--accent);border-color:var(--accent);color:#0a192f}.btn.danger{border-color:#fecaca;color:#b91c1c;background:#fff}.btn.small{padding:6px 9px;font-size:11px}.form{display:grid;gap:8px;margin-top:12px}.manage-actions{display:grid;grid-template-columns:minmax(250px,.7fr) minmax(0,1.3fr);gap:12px;margin:14px 0 16px}.manage-action{border:1px solid var(--line);border-radius:12px;background:#f8fafc;padding:12px}.manage-action-title{font-size:12px;font-weight:800;color:#334155;margin-bottom:8px}.manage-action .form{margin-top:0}.manage-action .question-add-fields{display:grid;grid-template-columns:180px minmax(0,1fr) auto;gap:8px;align-items:start}.manage-action textarea.field{min-height:42px}.manage-action .question-add-fields .btn{height:42px;white-space:nowrap}@media(max-width:900px){.manage-actions{grid-template-columns:1fr}.manage-action .question-add-fields{grid-template-columns:1fr}.manage-action .question-add-fields .btn{width:100%}}.field,.select{width:100%;padding:10px 11px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:var(--text)}textarea.field{min-height:80px;resize:vertical}.catbar{display:flex;flex-wrap:wrap;gap:7px;margin-bottom:14px}.chip{display:flex;align-items:center;gap:6px;border:1px solid #cbd5e1;border-radius:999px;padding:5px 9px;background:#fff;font-size:11px}.chip form{display:inline}.notice{padding:10px 12px;border-radius:10px;background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;font-size:12px;margin-bottom:12px}.success{background:#ecfdf5;border-color:#a7f3d0;color:#047857}.dangerbox{background:#fef2f2;border-color:#fecaca;color:#b91c1c}.questionnaire-landing{padding-top:8px}.landing-head{display:flex;justify-content:space-between;gap:20px;align-items:flex-end;margin-bottom:18px}.landing-kicker{font-size:11px;letter-spacing:.18em;font-weight:800;color:#64748b;margin-bottom:6px}.landing-head h2{margin:0;font-size:25px;letter-spacing:-.02em}.landing-head p{margin:7px 0 0;color:var(--muted);font-size:13px}.questionnaire-cards{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.questionnaire-card{background:var(--card);border:1px solid var(--line);border-radius:16px;box-shadow:0 8px 24px rgba(15,23,42,.06);padding:22px 24px;min-height:180px;display:flex;flex-direction:column;justify-content:space-between;transition:transform .15s ease,box-shadow .15s ease,border-color .15s ease}.questionnaire-card:hover{transform:translateY(-2px);box-shadow:0 12px 30px rgba(15,23,42,.1);border-color:#cbd5e1}.questionnaire-card-top{display:flex;align-items:center;gap:15px}.questionnaire-icon{width:54px;height:54px;border-radius:50%;display:grid;place-items:center;background:#eef4ff;font-size:21px}.card-faculty .questionnaire-icon{color:#2563eb}.card-staff .questionnaire-icon{color:#7c3aed}.card-leadership .questionnaire-icon{color:#d97706}.card-ea .questionnaire-icon{color:#059669}.questionnaire-card h3{margin:0;font-size:20px}.questionnaire-card p{margin:6px 0 0;color:var(--muted);font-size:12.5px}.questionnaire-card-bottom{display:flex;justify-content:flex-end;align-items:center;gap:14px;margin-top:24px}.question-count{font-size:14px;color:#475569}.question-count strong{font-size:17px;color:#2563eb}.manage-card{display:inline-flex;align-items:center;gap:8px;text-decoration:none;font-size:12px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;background:#eef4ff;color:#2563eb;padding:10px 14px;border-radius:9px}.manage-card:hover{filter:brightness(.97)}@media(max-width:900px){.grid{grid-template-columns:1fr}.questionnaire-cards{grid-template-columns:1fr}.wrap{padding:18px}.tabs{overflow:auto;flex-wrap:nowrap}.tab{white-space:nowrap}}
.filterbar{display:flex;flex-wrap:wrap;gap:7px;margin-bottom:12px}.filter-pill{border:1px solid #cbd5e1;background:#fff;color:#475569;padding:6px 12px;border-radius:999px;font-size:11px;font-weight:700;cursor:pointer}.filter-pill:hover{background:#f8fafc}.filter-pill.active{background:var(--navy);border-color:var(--navy);color:#fff}
.qxm-modal-backdrop{position:fixed;inset:0;background:rgba(10,25,47,.55);display:none;align-items:center;justify-content:center;z-index:1000;padding:20px}
.qxm-modal-backdrop.open{display:flex}
.qxm-modal{background:#fff;border-radius:14px;max-width:380px;width:100%;padding:22px 22px 18px;box-shadow:0 20px 60px rgba(15,23,42,.25);animation:qxm-pop .15s ease}
@keyframes qxm-pop{from{transform:scale(.96);opacity:0}to{transform:scale(1);opacity:1}}
.qxm-modal-icon{width:38px;height:38px;border-radius:50%;background:#fef2f2;color:var(--danger);display:flex;align-items:center;justify-content:center;font-size:15px;margin-bottom:12px}
.qxm-modal h3{margin:0 0 8px;font-size:16px;color:var(--text)}
.qxm-modal p{margin:0 0 20px;font-size:13px;color:var(--muted);line-height:1.5}
.qxm-modal-actions{display:flex;justify-content:flex-end;gap:8px}
.pill-group{display:inline-flex;align-items:center;gap:2px}
.pill-icon{border:0;background:transparent;color:#94a3b8;width:22px;height:22px;border-radius:50%;font-size:10px;cursor:pointer;display:none;align-items:center;justify-content:center;padding:0}
.pill-group:hover .pill-icon,.pill-group:focus-within .pill-icon{display:inline-flex}
.pill-icon:hover{background:#f1f5f9;color:#334155}.pill-del:hover{background:#fef2f2;color:var(--danger)}
@media(hover:none){.pill-icon{display:inline-flex}}
.qxm-modal.edit{max-width:460px}.qxm-modal.edit .qxm-modal-icon{background:#eff6ff;color:var(--blue)}.qxm-modal.edit .select,.qxm-modal.edit .field{margin-bottom:12px}.qxm-modal.edit .field{min-height:90px}
.qxm-modal.rename{max-width:400px}.qxm-modal.rename .qxm-modal-icon{background:#eff6ff;color:var(--blue)}.qxm-modal.rename .field{margin-bottom:16px}
</style>

<link rel="stylesheet" href="admin_appearance.css?v=<?= (int)@filemtime(__DIR__ . '/admin_appearance.css') ?>">
<style>
/* Landing: same layout as Evaluate Others (panel > step label > group cards with colored bottom border) */
.qx-panel{background:#fff;border:1px solid var(--line);border-radius:18px;box-shadow:0 8px 24px rgba(15,23,42,.06);padding:28px 32px 32px}
.qx-title{display:flex;align-items:center;gap:12px;margin:0 0 18px;font-size:28px;letter-spacing:-.01em;color:var(--text)}
.qx-title i{color:var(--blue);font-size:22px}
.qx-step{display:flex;align-items:center;gap:8px;margin-bottom:12px;font-size:12px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#64748b}
.qx-groups{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:22px}
.qx-group{--c:#2563eb;--c-bg:#eaf1ff;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;min-height:230px;padding:28px 18px 24px;text-decoration:none;color:var(--text);background:#fff;border:1px solid var(--line);border-bottom:4px solid var(--c);border-radius:16px;transition:transform .15s ease,box-shadow .15s ease,border-color .15s ease,background .15s ease}
.qx-group:hover,.qx-group:focus-visible{background:#f5f9ff;border-color:var(--c);border-bottom-color:var(--c);box-shadow:0 10px 26px rgba(15,23,42,.1);transform:translateY(-2px);outline:none}
.qx-group-icon{width:80px;height:80px;border-radius:50%;display:grid;place-items:center;background:var(--c-bg);color:var(--c);font-size:30px;margin-bottom:12px}
.qx-group-name{font-size:20px;font-weight:800;text-align:center}
.qx-group-count{font-size:14px;color:var(--muted)}
.qx-group.card-faculty{--c:#2563eb;--c-bg:#e6efff}
.qx-group.card-staff{--c:#7c3aed;--c-bg:#f0e9ff}
.qx-group.card-leadership{--c:#f59e0b;--c-bg:#fff3de}
.qx-group.card-ea{--c:#22c55e;--c-bg:#e6f8ed}
@media(max-width:1100px){.qx-groups{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:600px){.qx-groups{grid-template-columns:1fr}.qx-panel{padding:20px}}
html[data-theme="dark"] .qx-panel,html[data-theme="dark"] .qx-group{background:var(--panel-bg);border-color:var(--panel-border)}
html[data-theme="dark"] .qx-group{border-bottom-color:var(--c)}
html[data-theme="dark"] .qx-group:hover,html[data-theme="dark"] .qx-group:focus-visible{border-color:var(--c)}
html[data-theme="dark"] .qx-title,html[data-theme="dark"] .qx-group-name{color:var(--text)}
</style>
<script src="admin_appearance.js"></script>
<link rel="stylesheet" href="admin_unified_ui.css?v=<?= (int)@filemtime(__DIR__ . '/admin_unified_ui.css') ?>">
<style id="qx-polish">
/* ------------------------------------------------------------------
   Questionnaire polish: clearer hierarchy, bigger readable type and
   higher contrast.  Colours are light-theme only; dark mode keeps the
   palette from admin_appearance.css.  Loaded last on purpose.
   ------------------------------------------------------------------ */
html:not([data-theme="dark"]) body:not(#qxp){--muted:#475569;--line:#d3dce8;}

/* ═══ Landing (group picker) ═══ */
html body:not(#qxp) .qx-panel{padding:32px 36px 38px!important;}
html body:not(#qxp) .qx-step{font-size:13px!important;font-weight:800!important;letter-spacing:.09em!important;margin-bottom:6px!important;}
html body:not(#qxp) .qx-step i{color:var(--accent,#0F9F6E);}
html body:not(#qxp) .qx-lead{margin:0 0 26px;font-size:14.5px;line-height:1.5;color:var(--muted);}
html body:not(#qxp) .qx-groups{gap:22px!important;}
html body:not(#qxp) .qx-group{position:relative;overflow:hidden;justify-content:flex-start!important;gap:8px!important;min-height:340px!important;padding:38px 22px 26px!important;border-radius:18px!important;
    border:1px solid var(--line)!important;transition:transform .16s ease,box-shadow .16s ease,border-color .16s ease!important;}
html body:not(#qxp) .qx-group::before{content:"";position:absolute;left:0;right:0;top:0;height:5px;background:var(--c);}
html body:not(#qxp) .qx-group-icon{width:92px!important;height:92px!important;font-size:34px!important;margin-bottom:14px!important;}
html body:not(#qxp) .qx-group-name{font-size:22px!important;font-weight:800!important;line-height:1.25!important;}
html body:not(#qxp) .qx-group-desc{font-size:13.5px;line-height:1.4;text-align:center;max-width:240px;color:var(--muted);}
html body:not(#qxp) .qx-group-count{display:inline-flex;align-items:baseline;gap:6px;margin-top:14px;padding:7px 16px;border-radius:999px;font-size:13.5px!important;font-weight:700;}
html body:not(#qxp) .qx-group-count strong{font-size:20px;font-weight:800;line-height:1;}
html body:not(#qxp) .qx-group-cta{display:inline-flex;align-items:center;gap:8px;margin-top:auto;padding-top:20px;font-size:13px;font-weight:800;}
html body:not(#qxp) .qx-group-cta i{font-size:12px;transition:transform .15s ease;}
html body:not(#qxp) .qx-group:hover .qx-group-cta i,html body:not(#qxp) .qx-group:focus-visible .qx-group-cta i{transform:translateX(4px);}
html body:not(#qxp) .qx-group:focus-visible{outline:3px solid var(--c);outline-offset:3px;}
html body:not(#qxp) .qx-group.card-faculty{--c-ink:#1d4ed8;--c-bg:#e1ebff;}
html body:not(#qxp) .qx-group.card-staff{--c-ink:#6d28d9;--c-bg:#ece3ff;}
html body:not(#qxp) .qx-group.card-leadership{--c-ink:#b45309;--c-bg:#ffefcf;}
html body:not(#qxp) .qx-group.card-ea{--c-ink:#15803d;--c-bg:#ddf5e6;}

html:not([data-theme="dark"]) body:not(#qxp) .qx-panel{background:#fff!important;border-color:var(--line)!important;}
html:not([data-theme="dark"]) body:not(#qxp) .qx-step{color:#1e3a5f!important;}
html:not([data-theme="dark"]) body:not(#qxp) .qx-group{background:#fff!important;box-shadow:0 1px 2px rgba(11,31,58,.06),0 8px 22px rgba(11,31,58,.06)!important;}
html:not([data-theme="dark"]) body:not(#qxp) .qx-group:hover,html:not([data-theme="dark"]) body:not(#qxp) .qx-group:focus-visible{background:#fbfdff!important;border-color:var(--c)!important;box-shadow:0 14px 32px rgba(11,31,58,.13)!important;transform:translateY(-3px)!important;}
html:not([data-theme="dark"]) body:not(#qxp) .qx-group-name{color:#0b1f3a!important;}
html:not([data-theme="dark"]) body:not(#qxp) .qx-group-count{background:var(--c-bg);color:var(--c-ink);}
html:not([data-theme="dark"]) body:not(#qxp) .qx-group-cta{color:var(--c-ink);}
html[data-theme="dark"] body:not(#qxp) .qx-group-count{background:rgba(255,255,255,.08);color:var(--text);}
html[data-theme="dark"] body:not(#qxp) .qx-group-cta{color:var(--c);}
@media(max-width:1100px){html body:not(#qxp) .qx-group{min-height:300px!important;}}
@media(max-width:600px){html body:not(#qxp) .qx-group{min-height:0!important;}html body:not(#qxp) .qx-panel{padding:20px!important;}}

/* ═══ Manage views (question bank / target questionnaire) ═══ */
html body:not(#qxp) .cardhead{padding:18px 22px!important;}
html body:not(#qxp) .cardhead h2{font-size:18px!important;font-weight:800!important;}
html body:not(#qxp) .cardbody{padding:20px 22px!important;}
html body:not(#qxp) .subtle{font-size:13px!important;}
html body:not(#qxp) .back-link{padding:10px 14px!important;font-size:13px!important;border-radius:10px!important;}
html body:not(#qxp) .person{padding:12px!important;border-radius:12px!important;}
html body:not(#qxp) .avatar{width:44px!important;height:44px!important;}
html body:not(#qxp) .person-name{font-size:14px!important;}
html body:not(#qxp) .person-meta{font-size:12px!important;}
html body:not(#qxp) .badge{font-size:12px!important;padding:4px 10px!important;}
html body:not(#qxp) .manage-actions{gap:16px!important;margin:6px 0 20px!important;}
html body:not(#qxp) .manage-action{padding:16px 18px!important;border-radius:12px!important;}
html body:not(#qxp) .manage-action-title{font-size:12px!important;letter-spacing:.07em;text-transform:uppercase;margin-bottom:10px!important;}
html body:not(#qxp) .field,html body:not(#qxp) .select{padding:11px 12px!important;font-size:14px!important;border-radius:9px!important;}
html body:not(#qxp) .field:focus,html body:not(#qxp) .select:focus{outline:none;border-color:#2563eb!important;box-shadow:0 0 0 3px rgba(37,99,235,.18);}
html body:not(#qxp) .btn{font-size:13px!important;font-weight:700!important;border-radius:9px!important;}
html body:not(#qxp) .btn.small{padding:7px 13px!important;font-size:12px!important;}
html body:not(#qxp) .filter-pill{font-size:12px!important;padding:7px 14px!important;}
html body:not(#qxp) .list{gap:12px!important;}
html body:not(#qxp) .qrow{padding:15px 18px!important;border-radius:12px!important;transition:border-color .15s ease,box-shadow .15s ease;}
html body:not(#qxp) .qtext{font-size:14.5px!important;line-height:1.55!important;}
html body:not(#qxp) .qcat{font-size:11.5px!important;font-weight:700;}
html body:not(#qxp) .empty{padding:40px 12px!important;}

html:not([data-theme="dark"]) body:not(#qxp) .card{border-color:var(--line)!important;box-shadow:0 1px 2px rgba(11,31,58,.06),0 6px 18px rgba(11,31,58,.05)!important;}
html:not([data-theme="dark"]) body:not(#qxp) .cardhead{background:#f6f8fc;border-bottom-color:var(--line)!important;border-radius:14px 14px 0 0;}
html:not([data-theme="dark"]) body:not(#qxp) .cardhead h2,html:not([data-theme="dark"]) body:not(#qxp) .qtext,html:not([data-theme="dark"]) body:not(#qxp) .person-name,html:not([data-theme="dark"]) body:not(#qxp) .toolbar strong{color:#0b1f3a!important;}
html:not([data-theme="dark"]) body:not(#qxp) .person-meta{color:#475569!important;}
html:not([data-theme="dark"]) body:not(#qxp) .person:hover{background:#f1f5fb!important;border-color:#c3cee0!important;}
html:not([data-theme="dark"]) body:not(#qxp) .person.active{background:#eaf1ff!important;border-color:#9db8f2!important;box-shadow:inset 3px 0 0 #2563eb;}
html:not([data-theme="dark"]) body:not(#qxp) .badge{background:#e4e9f2!important;color:#334155!important;}
html:not([data-theme="dark"]) body:not(#qxp) .badge.has{background:#d6f3e4!important;color:#0a6a3b!important;}
html:not([data-theme="dark"]) body:not(#qxp) .manage-action{background:#f5f8fc!important;border-color:var(--line)!important;}
html:not([data-theme="dark"]) body:not(#qxp) .manage-action-title{color:#334155!important;}
html:not([data-theme="dark"]) body:not(#qxp) .field,html:not([data-theme="dark"]) body:not(#qxp) .select{border-color:#b6c3d6!important;}
html:not([data-theme="dark"]) body:not(#qxp) .btn{border-color:#b6c3d6!important;color:#1e293b!important;}
html:not([data-theme="dark"]) body:not(#qxp) .btn:hover{background:#f1f5fb!important;}
html:not([data-theme="dark"]) body:not(#qxp) .btn.primary{background:var(--accent,#0F9F6E)!important;border-color:color-mix(in srgb,var(--accent,#0F9F6E) 80%,#000)!important;color:#fff!important;}
html:not([data-theme="dark"]) body:not(#qxp) .btn.primary:hover{background:#c98a1c!important;}
html:not([data-theme="dark"]) body:not(#qxp) .btn.danger{border-color:#efb1b1!important;color:#a61b1b!important;background:#fff!important;}
html:not([data-theme="dark"]) body:not(#qxp) .btn.danger:hover{background:#fef2f2!important;}
html:not([data-theme="dark"]) body:not(#qxp) .back-link{border-color:#b6c3d6!important;color:#1e293b!important;}
html:not([data-theme="dark"]) body:not(#qxp) .filter-pill{border-color:#b6c3d6!important;color:#334155!important;}
html:not([data-theme="dark"]) body:not(#qxp) .filter-pill.active{background:#0a192f!important;border-color:#0a192f!important;color:#fff!important;}
html:not([data-theme="dark"]) body:not(#qxp) .qrow{border-color:var(--line)!important;background:#fff;}
html:not([data-theme="dark"]) body:not(#qxp) .qrow:hover{border-color:#aebcd1!important;box-shadow:0 4px 14px rgba(11,31,58,.07);}
html:not([data-theme="dark"]) body:not(#qxp) .qcat{color:#7a4a05!important;background:#fff3d6!important;border-color:#efcf86!important;}
</style>
</head>
<body>
<div class="wrap">
  <?php if ($scope !== ''): ?>
  <div class="header">

    <a class="back-link" href="questionnaire.php"><i class="fa-solid fa-arrow-left"></i> Back to questionnaires</a>
  </div>
  <?php endif; ?>


  <?php if ($message !== ''): ?><div class="notice success"><?= qxm_e($message) ?></div><?php endif; ?>

  <?php if ($scope === ''): ?>
    <?php
      $questionnaireCards = [
        'faculty' => [
          'label' => 'Faculty',
          'desc'  => 'Shared Faculty question bank',
          'count' => (int)$counts['faculty'],
          'icon'  => 'fa-chalkboard-user',
          'class' => 'card-faculty',
        ],
        'staff' => [
          'label' => 'Staff',
          'desc'  => 'Staff-target questionnaires',
          'count' => qxm_sum_for($counts['staff'] ?? [], $staffUsers),
          'icon'  => 'fa-briefcase',
          'class' => 'card-staff',
        ],
        'school_head' => [
          'label' => 'Dean / Principal',
          'desc'  => 'Leadership-target questionnaires',
          'count' => qxm_sum_for($counts['school_head'] ?? [], $headUsers),
          'icon'  => 'fa-user-tie',
          'class' => 'card-leadership',
        ],
        'ea' => [
          'label' => 'Executive Assistant (EA)',
          'desc'  => 'EA-target questionnaire',
          'count' => qxm_sum_for($counts['ea'] ?? [], $eaUsers),
          'icon'  => 'fa-user-shield',
          'class' => 'card-ea',
        ],
      ];
    ?>
    <section class="qx-panel">
      <h1 class="qx-title"><i class="fa-solid fa-clipboard-question"></i> Questionnaire</h1>
      <div class="qx-step"><i class="fa-solid fa-bolt"></i>Assign Questions</div>
      <p class="qx-lead">Choose a group to view and manage the evaluation questions assigned to it.</p>
      <div class="qx-groups">
        <?php foreach ($questionnaireCards as $key => $card): ?>
          <?php $n = (int)$card['count']; ?>
          <a class="qx-group <?= qxm_e($card['class']) ?>" href="questionnaire.php?scope=<?= urlencode($key) ?>">
            <span class="qx-group-icon"><i class="fa-solid <?= qxm_e($card['icon']) ?>"></i></span>
            <span class="qx-group-name"><?= qxm_e($card['label']) ?></span>
            <span class="qx-group-desc"><?= qxm_e($card['desc']) ?></span>
            <span class="qx-group-count"><strong><?= $n ?></strong> question<?= $n === 1 ? '' : 's' ?></span>
            <span class="qx-group-cta">Manage questions <i class="fa-solid fa-arrow-right"></i></span>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
  <?php elseif ($scope === 'faculty'): ?>
      <div class="card">
      <div class="cardhead"><h2>Faculty question bank</h2></div>
      <div class="cardbody">
        <div class="manage-actions">
          <div class="manage-action">
            <div class="manage-action-title">Add category</div>
            <form method="post" class="form">
              <input type="hidden" name="csrf_token" value="<?= qxm_e($csrf) ?>"><input type="hidden" name="scope" value="faculty"><input type="hidden" name="action" value="add_shared_category">
              <div style="display:flex;gap:8px"><input class="field" name="category_name" placeholder="New category" required><button class="btn primary" type="submit">Add Category</button></div>
            </form>
          </div>
          <div class="manage-action">
            <div class="manage-action-title">Add question</div>
            <form method="post" class="form">
              <input type="hidden" name="csrf_token" value="<?= qxm_e($csrf) ?>"><input type="hidden" name="scope" value="faculty"><input type="hidden" name="action" value="add_question">
              <div class="question-add-fields">
                <select class="select" name="category"><?php qxm_category_options($facultyCategories); ?></select>
                <textarea class="field" name="question_text" placeholder="Enter a Faculty evaluation question..." required></textarea>
                <button class="btn primary" type="submit">Add Question</button>
              </div>
            </form>
          </div>
        </div>
        <div style="border-top:1px solid var(--line);margin:4px 0 0;padding-top:18px">
          <div class="toolbar"><div><strong><?= count($facultyQuestions) ?> questions</strong><div class="subtle">Shared across all applicable Faculty evaluations</div></div></div>
          <?php qxm_render_filterbar("faculty-question-list", $facultyCategories, "faculty", 0, $csrf, "rename_shared_category", "delete_shared_category", (bool)$facultyQuestions || (bool)$facultyCategories); ?>
          <div class="list" id="faculty-question-list">
          <?php if (!$facultyQuestions): ?><div class="empty"><i class="fa-regular fa-clipboard"></i><div>No Faculty questions yet.</div></div><?php endif; ?>
          <?php foreach ($facultyQuestions as $q): ?>
            <div class="qrow" data-category="<?= qxm_e(qxm_cat_label($q['category'])) ?>">
              <div class="qtop"><div class="qtext"><?= qxm_e($q['question_text']) ?></div><?php if (qxm_cat_label($q['category']) !== ''): ?><span class="qcat"><?= qxm_e(qxm_cat_label($q['category'])) ?></span><?php endif; ?></div>
              <div class="actions" style="margin-top:9px">
                <button type="button" class="btn small qxm-edit-btn" data-qid="<?= (int)$q['id'] ?>" data-text="<?= qxm_e($q['question_text']) ?>" data-cat="<?= qxm_e(qxm_cat_label($q['category'])) ?>">Edit</button>
                <form method="post" onsubmit="return qxmConfirm(this,'Remove this question?','It will be taken out of the active Faculty questionnaire.');">
                  <input type="hidden" name="csrf_token" value="<?= qxm_e($csrf) ?>"><input type="hidden" name="scope" value="faculty"><input type="hidden" name="action" value="delete_question"><input type="hidden" name="question_id" value="<?= (int)$q['id'] ?>">
                  <button class="btn small danger" type="submit">Remove</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  <?php else: ?>
    <div class="grid">
      <div class="card">
        <div class="cardhead">
          <h2><?= qxm_e($labels[$scope]) ?> targets</h2>
          <div class="subtle" style="margin-top:4px"><?= count($scopePeople) ?> eligible target<?= count($scopePeople)===1?'':'s' ?></div>
        </div>
        <div class="cardbody">
          <?php if (!$scopePeople): ?>
            <div class="empty"><i class="fa-regular fa-user"></i><div>No eligible targets.</div></div>
          <?php endif; ?>
          <?php foreach($scopePeople as $p): ?>
            <?php
              $keyType = $scope==='school_head' ? ucfirst(strtolower($p['role'])) : ($scope==='ea' ? 'EA' : 'Staff');
              $cnt = $personQuestionCounts[(int)$p['id']][$keyType] ?? 0;
            ?>
            <a class="person <?= $selectedPerson && (int)$selectedPerson['id']===(int)$p['id']?'active':'' ?>" href="questionnaire.php?scope=<?= urlencode($scope) ?>&user_id=<?= (int)$p['id'] ?>">
              <span class="person-main"><span class="avatar"><?php if(!empty($p['photo'])): ?><img src="../image/<?= qxm_e($p['photo']) ?>" alt=""><?php else: ?><i class="fa-solid <?= $scope==='ea'?'fa-user-shield':($scope==='school_head'?'fa-user-tie':'fa-briefcase') ?>"></i><?php endif; ?></span><span><span class="person-name"><?= qxm_e($p['full_name']) ?></span><span class="person-meta"><?= qxm_e($scope==='school_head'?ucfirst($p['role']):($p['designation'] ?: ($scope==='ea'?'Executive Assistant':'Staff'))) ?></span></span></span>
              <span class="badge <?= $cnt>0?'has':'' ?>"><?= (int)$cnt ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="card">
        <div class="cardhead">
          <h2><?= $selectedPerson ? qxm_e($selectedPersonName) : 'Select a target' ?></h2>
          <?php if ($selectedPerson): ?><div class="subtle" style="margin-top:4px">One centralized questionnaire for this target, regardless of evaluator type.</div><?php endif; ?>
        </div>
        <div class="cardbody">
          <?php if (!$selectedPerson): ?>
            <div class="empty"><i class="fa-solid fa-hand-pointer"></i><div>Select a target to manage its questionnaire.</div></div>
          <?php else: ?>
            <div class="manage-actions">
              <div class="manage-action">
                <div class="manage-action-title">Add category</div>
                <form method="post" class="form">
                  <input type="hidden" name="csrf_token" value="<?= qxm_e($csrf) ?>"><input type="hidden" name="scope" value="<?= qxm_e($scope) ?>"><input type="hidden" name="user_id" value="<?= (int)$selectedUser ?>"><input type="hidden" name="action" value="add_user_category">
                  <div style="display:flex;gap:8px"><input class="field" name="category_name" placeholder="Add category" required><button class="btn" type="submit">Add Category</button></div>
                </form>
              </div>
              <div class="manage-action">
                <div class="manage-action-title">Add question</div>
                <form method="post" class="form">
                  <input type="hidden" name="csrf_token" value="<?= qxm_e($csrf) ?>"><input type="hidden" name="scope" value="<?= qxm_e($scope) ?>"><input type="hidden" name="user_id" value="<?= (int)$selectedUser ?>"><input type="hidden" name="action" value="add_question">
                  <div class="question-add-fields">
                    <select class="select" name="category"><?php qxm_category_options($personCategories); ?></select>
                    <textarea class="field" name="question_text" placeholder="Enter a question for this target..." required></textarea>
                    <button class="btn primary" type="submit">Add Question</button>
                  </div>
                </form>
              </div>
            </div>
            <div style="border-top:1px solid var(--line);margin-top:4px;padding-top:16px">
              <div class="subtle" style="margin-bottom:10px"><strong><?= count($personQuestions) ?></strong> questions</div>
              <?php qxm_render_filterbar("person-question-list", $personCategories, $scope, (int)$selectedUser, $csrf, "rename_user_category", "delete_user_category", (bool)$personQuestions || (bool)$personCategories); ?>
              <div class="list" id="person-question-list">
              <?php if (!$personQuestions): ?><div class="empty"><i class="fa-regular fa-clipboard"></i><div>No questions assigned yet.</div></div><?php endif; ?>
              <?php foreach($personQuestions as $q): ?>
                <div class="qrow" data-category="<?= qxm_e(qxm_cat_label($q['category'])) ?>">
                  <div class="qtop"><div class="qtext"><?= qxm_e($q['question_text']) ?></div><?php if (qxm_cat_label($q['category']) !== ''): ?><span class="qcat"><?= qxm_e(qxm_cat_label($q['category'])) ?></span><?php endif; ?></div>
                  <div class="actions" style="margin-top:9px">
                    <button type="button" class="btn small qxm-edit-btn" data-qid="<?= (int)$q['id'] ?>" data-text="<?= qxm_e($q['question_text']) ?>" data-cat="<?= qxm_e(qxm_cat_label($q['category'])) ?>">Edit</button>
                    <form method="post" onsubmit="return qxmConfirm(this,'Remove this question?','It will be taken out of this target\'s questionnaire.');">
                      <input type="hidden" name="csrf_token" value="<?= qxm_e($csrf) ?>"><input type="hidden" name="scope" value="<?= qxm_e($scope) ?>"><input type="hidden" name="user_id" value="<?= (int)$selectedUser ?>"><input type="hidden" name="action" value="delete_question"><input type="hidden" name="question_id" value="<?= (int)$q['id'] ?>">
                      <button class="btn small danger" type="submit">Remove</button>
                    </form>
                  </div>
                </div>
              <?php endforeach; ?>
              </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<div class="qxm-modal-backdrop" id="qxm-modal-backdrop">
  <div class="qxm-modal">
    <div class="qxm-modal-icon"><i class="fa-solid fa-trash-can"></i></div>
    <h3 id="qxm-modal-title">Remove this?</h3>
    <p id="qxm-modal-text"></p>
    <div class="qxm-modal-actions">
      <button type="button" class="btn small" id="qxm-modal-cancel">Cancel</button>
      <button type="button" class="btn small danger" id="qxm-modal-ok">Remove</button>
    </div>
  </div>
</div>
<div class="qxm-modal-backdrop" id="qxm-rename-backdrop">
  <form class="qxm-modal rename" method="post" id="qxm-rename-form">
    <div class="qxm-modal-icon"><i class="fa-solid fa-pen"></i></div>
    <h3>Rename category</h3>
    <p>Questions in this category will be updated to the new name.</p>
    <input type="hidden" name="csrf_token" value="">
    <input type="hidden" name="scope" value="">
    <input type="hidden" name="user_id" value="">
    <input type="hidden" name="category_id" value="">
    <input type="hidden" name="action" value="">
    <input class="field" name="category_name" id="qxm-rename-input" maxlength="120" required>
    <div class="qxm-modal-actions">
      <button type="button" class="btn small" id="qxm-rename-cancel">Cancel</button>
      <button type="submit" class="btn small primary">Save</button>
    </div>
  </form>
</div>
<div class="qxm-modal-backdrop" id="qxm-edit-backdrop">
  <form class="qxm-modal edit" method="post" id="qxm-edit-form">
    <div class="qxm-modal-icon"><i class="fa-solid fa-pen"></i></div>
    <h3>Edit this question</h3>
    <p>Changes apply to the active questionnaire.</p>
    <input type="hidden" name="csrf_token" value="<?= qxm_e($csrf) ?>">
    <input type="hidden" name="scope" value="<?= qxm_e($scope) ?>">
    <input type="hidden" name="user_id" value="<?= (int)$selectedUser ?>">
    <input type="hidden" name="action" value="update_question">
    <input type="hidden" name="question_id" value="">
    <select class="select" name="category" id="qxm-edit-cat" required></select>
    <textarea class="field" name="question_text" id="qxm-edit-text" required></textarea>
    <div class="qxm-modal-actions">
      <button type="button" class="btn small" id="qxm-edit-cancel">Cancel</button>
      <button type="submit" class="btn small primary">Save</button>
    </div>
  </form>
</div>
<form method="post" id="qxm-delcat-form" style="display:none">
  <input type="hidden" name="csrf_token" value="">
  <input type="hidden" name="scope" value="">
  <input type="hidden" name="user_id" value="">
  <input type="hidden" name="category_id" value="">
  <input type="hidden" name="action" value="">
</form>
<script>
var qxmEditCats = <?= json_encode(array_values(array_filter(array_map(fn($c) => (string)$c['category_name'], $scope === 'faculty' ? $facultyCategories : $personCategories), fn($n) => strcasecmp($n, 'General') !== 0)), JSON_UNESCAPED_UNICODE) ?>;
document.addEventListener('click', function (e) {
  var b = e.target.closest('.qxm-edit-btn');
  if (!b) return;
  var f = document.getElementById('qxm-edit-form');
  var sel = document.getElementById('qxm-edit-cat');
  var cur = b.getAttribute('data-cat') || '';
  sel.innerHTML = '';
  if (!cur) {
    var ph = document.createElement('option');
    ph.value = ''; ph.textContent = 'Select category'; ph.disabled = true; ph.selected = true;
    sel.appendChild(ph);
  }
  qxmEditCats.forEach(function (n) {
    var o = document.createElement('option');
    o.value = n; o.textContent = n; if (n === cur) o.selected = true;
    sel.appendChild(o);
  });
  f.elements['question_id'].value = b.getAttribute('data-qid');
  document.getElementById('qxm-edit-text').value = b.getAttribute('data-text');
  document.getElementById('qxm-edit-backdrop').classList.add('open');
  document.getElementById('qxm-edit-text').focus();
});
document.getElementById('qxm-edit-cancel').addEventListener('click', function () {
  document.getElementById('qxm-edit-backdrop').classList.remove('open');
});
document.getElementById('qxm-edit-backdrop').addEventListener('click', function (e) {
  if (e.target === this) this.classList.remove('open');
});
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') document.getElementById('qxm-edit-backdrop').classList.remove('open');
});
</script>
<script>
var qxmPendingForm = null;
function qxmConfirm(form, title, text) {
  if (form.dataset.qxmConfirmed === '1') { form.dataset.qxmConfirmed = ''; return true; }
  qxmPendingForm = form;
  document.getElementById('qxm-modal-title').textContent = title || 'Remove this?';
  document.getElementById('qxm-modal-text').textContent = text || '';
  document.getElementById('qxm-modal-backdrop').classList.add('open');
  return false;
}
document.getElementById('qxm-modal-ok').addEventListener('click', function () {
  document.getElementById('qxm-modal-backdrop').classList.remove('open');
  if (qxmPendingForm) {
    qxmPendingForm.dataset.qxmConfirmed = '1';
    if (qxmPendingForm.requestSubmit) qxmPendingForm.requestSubmit(); else qxmPendingForm.submit();
    qxmPendingForm = null;
  }
});
document.getElementById('qxm-modal-cancel').addEventListener('click', function () {
  document.getElementById('qxm-modal-backdrop').classList.remove('open');
  qxmPendingForm = null;
});
document.getElementById('qxm-modal-backdrop').addEventListener('click', function (e) {
  if (e.target === this) { this.classList.remove('open'); qxmPendingForm = null; }
});
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') { document.getElementById('qxm-modal-backdrop').classList.remove('open'); qxmPendingForm = null; }
});
// Tell the admin dashboard (parent window) when a modal is open so it can dim the
// sidebar and top bar too, instead of only this frame.
(function () {
  if (window.parent === window) return;
  var last = null;
  function notify() {
    var open = !!document.querySelector('.qxm-modal-backdrop.open');
    if (open === last) return;
    last = open;
    try { window.parent.postMessage({ type: 'pbi-modal-dim', open: open }, location.origin); } catch (e) {}
  }
  var mo = new MutationObserver(notify);
  document.querySelectorAll('.qxm-modal-backdrop').forEach(function (b) {
    mo.observe(b, { attributes: true, attributeFilter: ['class'] });
  });
  window.addEventListener('pagehide', function () {
    try { window.parent.postMessage({ type: 'pbi-modal-dim', open: false }, location.origin); } catch (e) {}
  });
})();
document.querySelectorAll('.filterbar').forEach(function (bar) {
  var list = document.getElementById(bar.getAttribute('data-filter-target'));
  if (!list) return;
  bar.addEventListener('click', function (e) {
    var btn = e.target.closest('.filter-pill');
    if (!btn) return;
    bar.querySelectorAll('.filter-pill').forEach(function (b) { b.classList.remove('active'); });
    btn.classList.add('active');
    var filter = btn.getAttribute('data-filter');
    list.querySelectorAll('.qrow').forEach(function (row) {
      var match = (filter === 'all' || row.getAttribute('data-category') === filter);
      row.style.display = match ? '' : 'none';
    });
  });
});

function qxmFill(form, ctx, catId) {
  form.csrf_token.value = ctx.csrf;
  form.scope.value = ctx.scope;
  form.user_id.value = ctx.user_id || '';
  form.category_id.value = catId;
}
document.querySelectorAll('.filterbar').forEach(function (bar) {
  var ctx = {};
  try { ctx = JSON.parse(bar.getAttribute('data-ctx') || '{}'); } catch (e) {}
  bar.addEventListener('click', function (e) {
    var group = e.target.closest('.pill-group');
    if (!group) return;
    var id = group.getAttribute('data-cat-id');
    var name = group.getAttribute('data-cat-name');
    if (e.target.closest('.pill-edit')) {
      var f = document.getElementById('qxm-rename-form');
      qxmFill(f, ctx, id);
      f.elements['action'].value = ctx.rename;
      var inp = document.getElementById('qxm-rename-input');
      inp.value = name;
      document.getElementById('qxm-rename-backdrop').classList.add('open');
      inp.focus(); inp.select();
    } else if (e.target.closest('.pill-del')) {
      var d = document.getElementById('qxm-delcat-form');
      qxmFill(d, ctx, id);
      d.elements['action'].value = ctx.delete;
      qxmConfirm(d, 'Delete "' + name + '"?', 'Questions in it will become uncategorized.');
    }
  });
});
document.getElementById('qxm-rename-cancel').addEventListener('click', function () {
  document.getElementById('qxm-rename-backdrop').classList.remove('open');
});
document.getElementById('qxm-rename-backdrop').addEventListener('click', function (e) {
  if (e.target === this) this.classList.remove('open');
});
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') document.getElementById('qxm-rename-backdrop').classList.remove('open');
});
</script>
</body>
</html>