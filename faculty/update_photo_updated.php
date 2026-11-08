<?php
// Shared profile-photo upload endpoint for Faculty and Staff accounts.
session_set_cookie_params(['lifetime'=>0,'path'=>'/','domain'=>'','secure'=>false,'httponly'=>true,'samesite'=>'Lax']);
session_start();
require_once 'db.php';

$role = (string)($_SESSION['role'] ?? '');
if (empty($_SESSION['user_id']) || !in_array($role, ['teacher', 'staff'], true)) {
    header('Location: ' . ($role === 'staff' ? 'staff_login.php' : 'faculty_login.php'));
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$dashboard = $role === 'staff' ? 'staff_dashboard.php' : 'faculty_dashboard.php';
$returnPage = (string)($_POST['return_page'] ?? 'profile');
if (!in_array($returnPage, ['settings', 'profile'], true)) $returnPage = 'profile';
$redirect = $dashboard . '?page=' . $returnPage;

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
    $_SESSION['toast_error'] = 'Your session expired. Please refresh the page and try again.';
    header('Location: ' . $redirect); exit;
}
if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES['photo']['tmp_name'])) {
    $_SESSION['toast_error'] = 'Please choose a photo to upload.';
    header('Location: ' . $redirect); exit;
}

$file = $_FILES['photo'];
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']) ?: '';
$allowed = ['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp', 'image/gif'=>'gif'];
if (!isset($allowed[$mime])) {
    $_SESSION['toast_error'] = 'Please upload a JPG, PNG, WEBP, or GIF image.';
    header('Location: ' . $redirect); exit;
}
if ((int)$file['size'] > 10 * 1024 * 1024) {
    $_SESSION['toast_error'] = 'Photo must be 10MB or smaller.';
    header('Location: ' . $redirect); exit;
}

$uploadDir = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR;
if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true)) {
    $_SESSION['toast_error'] = 'The profile photo folder is not available.';
    header('Location: ' . $redirect); exit;
}
$prefix = $role === 'staff' ? 'staff_' : 'fac_';
$filename = $prefix . bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
$destination = $uploadDir . $filename;
if (!move_uploaded_file($file['tmp_name'], $destination)) {
    $_SESSION['toast_error'] = 'Could not save the uploaded photo. Please try again.';
    header('Location: ' . $redirect); exit;
}

try {
    $oldStmt = $mysqli->prepare('SELECT photo FROM users WHERE id=? LIMIT 1');
    $oldStmt->bind_param('i', $user_id);
    $oldStmt->execute();
    $oldPhoto = (string)($oldStmt->get_result()->fetch_assoc()['photo'] ?? '');
    $oldStmt->close();

    $updateStmt = $mysqli->prepare('UPDATE users SET photo=? WHERE id=?');
    $updateStmt->bind_param('si', $filename, $user_id);
    $updateStmt->execute();
    $updateStmt->close();

    // Delete only generated profile files, not shared or default images.
    $isManagedPhoto = str_starts_with($oldPhoto, 'fac_') || str_starts_with($oldPhoto, 'staff_');
    if ($oldPhoto !== '' && basename($oldPhoto) === $oldPhoto && $isManagedPhoto && is_file($uploadDir . $oldPhoto)) {
        @unlink($uploadDir . $oldPhoto);
    }
    $_SESSION['toast'] = 'Profile photo updated successfully!';
} catch (Throwable $e) {
    if (is_file($destination)) @unlink($destination);
    error_log('[update_photo] Profile photo update failed for user ' . $user_id . ': ' . $e->getMessage());
    $_SESSION['toast_error'] = 'Unable to update your profile photo right now. Please try again.';
}

header('Location: ' . $redirect);
exit;
