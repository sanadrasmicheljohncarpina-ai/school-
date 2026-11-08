<?php
// Root logout handler for C:/xampp/htdocs/index/logout.php
// Redirects users back to the login page for the role they just logged out from.

session_start();

$role = strtolower(trim((string)($_SESSION['role'] ?? '')));

$redirects = [
    'principal'        => 'principal/principal_login.php',
    'dean'             => 'dean/dean_login.php',
    'superadmin'       => 'admin/admin_login.php',
    'admin'            => 'admin/admin_login.php',
    'ea'               => 'admin/admin_login.php',
    'executive assistant' => 'admin/admin_login.php',
    'teacher'          => 'faculty/faculty_login.php',
    'faculty'          => 'faculty/faculty_login.php',
    'staff'            => 'faculty/faculty_login.php',
    'student'          => 'student/student_login.php',
    'school_head'      => 'school_head/school_head_login.php',
];

// Clear the logged-in session before redirecting.
session_unset();
session_destroy();

$destination = $redirects[$role] ?? 'admin/admin_login.php';
header('Location: ' . $destination);
exit;
