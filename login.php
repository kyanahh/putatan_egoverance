<?php
session_start();

require_once 'config/database.php';
require_once 'includes/audit_helper.php';

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {

        $error = "Please enter your username and password.";

    } else {

        $stmt = $pdo->prepare("
            SELECT user_id, full_name, username, email, password,
                   role, account_status, rejection_reason
            FROM users
            WHERE username = :username OR email = :username
            LIMIT 1
        ");

        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch();

        // ==============================
        // INVALID CREDENTIALS
        // ==============================
        if (!$user || !password_verify($password, $user['password'])) {

            logAudit(
                $pdo,
                'FAILED LOGIN',
                'Authentication',
                'Failed login attempt using username/email: ' . $username,
                null,
                'guest'
            );

            $error = "Invalid username/email or password.";

        } elseif ($user['account_status'] === 'pending') {

            logAudit(
                $pdo,
                'LOGIN DENIED',
                'Authentication',
                'Login attempt denied because account is pending approval: ' . $user['username'],
                (int)$user['user_id'],
                $user['role']
            );

            $error = "Your account is pending administrator approval.";

        } elseif ($user['account_status'] === 'rejected') {

            $reason = $user['rejection_reason'] ?? '';

            logAudit(
                $pdo,
                'LOGIN DENIED',
                'Authentication',
                'Login attempt denied because account registration was rejected: ' . $user['username'],
                (int)$user['user_id'],
                $user['role']
            );

            $error = "Your registration has been rejected.";

            if ($reason !== '') {
                $error .= " Reason: " . $reason;
            }

        } elseif ($user['account_status'] === 'suspended') {

            logAudit(
                $pdo,
                'LOGIN DENIED',
                'Authentication',
                'Login attempt denied because account is suspended: ' . $user['username'],
                (int)$user['user_id'],
                $user['role']
            );

            $error = "Your account is currently suspended.";

        } elseif ($user['account_status'] !== 'approved') {

            logAudit(
                $pdo,
                'LOGIN DENIED',
                'Authentication',
                'Login attempt denied due to account status: ' . $user['username'],
                (int)$user['user_id'],
                $user['role']
            );

            $error = "Your account cannot currently access the system.";

        } else {

            // ==============================
            // SUCCESSFUL LOGIN
            // ==============================

            session_regenerate_id(true);

            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];

            logAudit(
                $pdo,
                'LOGIN',
                'Authentication',
                'User successfully logged in: ' . $user['username'],
                (int)$user['user_id'],
                $user['role']
            );

            // Redirect according to role
            switch ($user['role']) {

                case 'admin':
                    header("Location: admin/dashboard.php");
                    exit;

                case 'staff':
                    header("Location: staff/dashboard.php");
                    exit;

                case 'resident':
                    header("Location: resident/dashboard.php");
                    exit;

                default:
                    session_destroy();
                    $error = "Invalid user role.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Login | Barangay Putatan</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-page">

<nav class="navbar navbar-dark barangay-navbar">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-3"
           href="index.php">

            <img src="assets/images/barangay-logo.png"
                 alt="Barangay Putatan Logo"
                 class="navbar-logo">

            <span class="fw-bold">Barangay Putatan</span>
        </a>
    </div>
</nav>

<div class="container py-5">

    <div class="row justify-content-center">

        <div class="col-md-7 col-lg-5">

            <div class="card login-card border-0 shadow">

                <div class="card-body p-4 p-md-5">

                    <div class="text-center mb-4">

                        <img src="assets/images/barangay-logo.png"
                             alt="Barangay Logo"
                             class="login-logo mb-3">

                        <h2 class="fw-bold text-primary-custom">
                            Welcome Back
                        </h2>

                        <p class="text-muted">
                            Login to your Barangay Putatan account
                        </p>

                    </div>

                    <?php if ($error !== ""): ?>
                        <div class="alert alert-danger">
                            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST">

                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                Username or Email
                            </label>

                            <input type="text"
                                   name="username"
                                   class="form-control form-control-lg"
                                   placeholder="Enter username or email"
                                   required
                                   autocomplete="username">
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-semibold">
                                Password
                            </label>

                            <input type="password"
                                   name="password"
                                   class="form-control form-control-lg"
                                   placeholder="Enter your password"
                                   required
                                   autocomplete="current-password">
                        </div>

                        <button type="submit"
                                class="btn btn-primary-custom btn-lg w-100">
                            Login
                        </button>

                    </form>

                    <div class="text-center mt-4">

                        <p class="mb-2">
                            Don't have an account?
                        </p>

                        <a href="register.php"
                           class="text-decoration-none fw-semibold">
                            Register as a Resident
                        </a>

                    </div>

                    <div class="text-center mt-3">
                        <a href="index.php"
                           class="text-muted small text-decoration-none">
                            Return to Homepage
                        </a>
                    </div>

                </div>
            </div>

        </div>
    </div>
</div>

<footer class="footer-section text-center py-3">
    <small>
        Barangay Putatan E-Governance System
    </small>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>