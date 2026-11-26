<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/database.php';
require_once 'includes/audit_helper.php';

// ======================================
// RECORD LOGOUT BEFORE CLEARING SESSION
// ======================================

if (isset($_SESSION['user_id'])) {

    $user_id = (int) $_SESSION['user_id'];
    $username = $_SESSION['username'] ?? 'Unknown User';
    $role = $_SESSION['role'] ?? 'unknown';

    logAudit(
        $pdo,
        'LOGOUT',
        'Authentication',
        'User logged out: ' . $username,
        $user_id,
        $role
    );
}

// ======================================
// CLEAR SESSION DATA
// ======================================

$_SESSION = [];

// Remove session cookie
if (ini_get("session.use_cookies")) {

    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Destroy session
session_destroy();

// Redirect to homepage
header("Location: index.php?logged_out=1");
exit;