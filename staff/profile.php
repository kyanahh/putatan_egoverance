<?php
require_once '../includes/staff_auth.php';
require_once '../config/database.php';
require_once '../includes/audit_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];
$staff_id = (int)$_SESSION['user_id'];

$success = '';
$error = '';

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/*
|--------------------------------------------------------------------------
| FETCH STAFF PROFILE
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT user_id, full_name, username, email, role, created_at
    FROM users
    WHERE user_id = ?
      AND role = 'staff'
    LIMIT 1
");

$stmt->execute([$staff_id]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$profile) {
    die('Staff profile not found.');
}

/*
|--------------------------------------------------------------------------
| HANDLE PROFILE UPDATE
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        $error = 'Invalid form submission. Please try again.';
    } else {

        $action = $_POST['action'] ?? '';

        /*
        |--------------------------------------------------------------------------
        | UPDATE PROFILE INFORMATION
        |--------------------------------------------------------------------------
        */
        if ($action === 'update_profile') {

            $full_name = trim($_POST['full_name'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');

            if (
                $full_name === '' ||
                $username === '' ||
                $email === ''
            ) {
                $error = 'All profile fields are required.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address.';
            } else {

                try {

                    $duplicate_stmt = $pdo->prepare("
                        SELECT user_id
                        FROM users
                        WHERE (username = ? OR email = ?)
                          AND user_id != ?
                        LIMIT 1
                    ");

                    $duplicate_stmt->execute([
                        $username,
                        $email,
                        $staff_id
                    ]);

                    if ($duplicate_stmt->fetch()) {

                        $error = 'Username or email is already being used by another account.';

                    } else {

                        $old_name = $profile['full_name'];

                        $update_stmt = $pdo->prepare("
                            UPDATE users
                            SET full_name = ?,
                                username = ?,
                                email = ?
                            WHERE user_id = ?
                              AND role = 'staff'
                        ");

                        $update_stmt->execute([
                            $full_name,
                            $username,
                            $email,
                            $staff_id
                        ]);

                        $_SESSION['full_name'] = $full_name;

                        logAudit(
                            $pdo,
                            'UPDATE STAFF PROFILE',
                            'Staff Profile',
                            'Staff updated their profile information.',
                            $staff_id,
                            $_SESSION['role'] ?? 'staff'
                        );

                        header('Location: profile.php?success=profile');
                        exit;
                    }

                } catch (PDOException $ex) {

                    error_log('Staff profile update error: ' . $ex->getMessage());
                    $error = 'Unable to update profile information.';
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | CHANGE PASSWORD
        |--------------------------------------------------------------------------
        */
        elseif ($action === 'change_password') {

            $current_password = $_POST['current_password'] ?? '';
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';

            if (
                $current_password === '' ||
                $new_password === '' ||
                $confirm_password === ''
            ) {
                $error = 'Please complete all password fields.';
            } elseif ($new_password !== $confirm_password) {
                $error = 'New password and confirmation do not match.';
            } elseif (strlen($new_password) < 8) {
                $error = 'Your new password must be at least 8 characters.';
            } else {

                try {

                    $password_stmt = $pdo->prepare("
                        SELECT password
                        FROM users
                        WHERE user_id = ?
                          AND role = 'staff'
                        LIMIT 1
                    ");

                    $password_stmt->execute([$staff_id]);
                    $stored_password = $password_stmt->fetchColumn();

                    if (!$stored_password) {

                        $error = 'Unable to retrieve your current password.';

                    } elseif (!password_verify($current_password, $stored_password)) {

                        $error = 'Your current password is incorrect.';

                    } else {

                        $hashed_password = password_hash(
                            $new_password,
                            PASSWORD_DEFAULT
                        );

                        $update_password = $pdo->prepare("
                            UPDATE users
                            SET password = ?
                            WHERE user_id = ?
                              AND role = 'staff'
                        ");

                        $update_password->execute([
                            $hashed_password,
                            $staff_id
                        ]);

                        logAudit(
                            $pdo,
                            'CHANGE STAFF PASSWORD',
                            'Staff Profile',
                            'Staff changed their account password.',
                            $staff_id,
                            $_SESSION['role'] ?? 'staff'
                        );

                        header('Location: profile.php?success=password');
                        exit;
                    }

                } catch (PDOException $ex) {

                    error_log('Staff password change error: ' . $ex->getMessage());
                    $error = 'Unable to change password.';
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| SUCCESS MESSAGES
|--------------------------------------------------------------------------
*/
if (isset($_GET['success'])) {

    switch ($_GET['success']) {

        case 'profile':
            $success = 'Profile information updated successfully.';
            break;

        case 'password':
            $success = 'Password changed successfully.';
            break;
    }
}

/*
|--------------------------------------------------------------------------
| REFRESH PROFILE DATA
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT user_id, full_name, username, email, role, created_at
    FROM users
    WHERE user_id = ?
      AND role = 'staff'
    LIMIT 1
");

$stmt->execute([$staff_id]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Profile | Barangay Putatan Staff</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <link rel="stylesheet"
          href="../assets/css/style.css">

</head>

<body class="admin-page">

<!-- NAVBAR -->
<nav class="navbar navbar-dark barangay-navbar">

    <div class="container-fluid px-4">

        <a class="navbar-brand d-flex align-items-center gap-2"
           href="dashboard.php">

            <img src="../assets/images/barangay-logo.png"
                 alt="Barangay Logo"
                 class="navbar-logo">

            <span class="fw-bold">
                Barangay Putatan | Staff
            </span>

        </a>

        <div class="d-flex align-items-center gap-3">

            <span class="text-white">
                <?= e($_SESSION['full_name']) ?>
            </span>

            <a href="../logout.php"
               class="btn btn-yellow btn-sm">
                Logout
            </a>

        </div>

    </div>

</nav>

<div class="container-fluid">

    <div class="row">

        <!-- SIDEBAR -->

        <aside class="col-md-3 col-lg-2 admin-sidebar p-3">

            <small class="text-muted text-uppercase fw-bold">
                Staff Portal
            </small>

            <nav class="nav flex-column gap-2 mt-3">

                <a
                    class="nav-link admin-nav-link"
                    href="dashboard.php"
                >

                    <i class="bi bi-speedometer2 me-2"></i>
                    Dashboard

                </a>

                <a
                    class="nav-link admin-nav-link"
                    href="service-requests.php"
                >

                    <i class="bi bi-file-earmark-text me-2"></i>
                    Service Requests

                </a>

                <a
                    class="nav-link admin-nav-link"
                    href="feedback.php"
                >

                    <i class="bi bi-chat-left-text me-2"></i>
                    Complaints & Feedback

                </a>

                <a
                    class="nav-link admin-nav-link"
                    href="residents.php"
                >

                    <i class="bi bi-people me-2"></i>
                    Residents

                </a>

                <a
                    class="nav-link admin-nav-link"
                    href="announcements.php"
                >

                    <i class="bi bi-megaphone me-2"></i>
                    Announcements

                </a>

                <a class="nav-link admin-nav-link active"
                    href="profile.php">
                    <i class="bi bi-person-circle me-2"></i>My Profile
                </a>

            </nav>

        </aside>

        <!-- MAIN CONTENT -->
        <main class="col-md-9 col-lg-10 px-4 py-4">

            <div class="mb-4">

                <h2 class="fw-bold text-primary-custom">
                    My Profile
                </h2>

                <p class="text-muted mb-0">
                    Manage your personal account information and password.
                </p>

            </div>

            <!-- ALERTS -->
            <?php if ($success): ?>

                <div class="alert alert-success alert-dismissible fade show">

                    <?= e($success) ?>

                    <button type="button"
                            class="btn-close"
                            data-bs-dismiss="alert">
                    </button>

                </div>

            <?php endif; ?>

            <?php if ($error): ?>

                <div class="alert alert-danger alert-dismissible fade show">

                    <?= e($error) ?>

                    <button type="button"
                            class="btn-close"
                            data-bs-dismiss="alert">
                    </button>

                </div>

            <?php endif; ?>

            <div class="row g-4">

                <!-- PROFILE INFORMATION -->
                <div class="col-lg-7">

                    <div class="card border-0 shadow-sm">

                        <div class="card-header bg-white py-3">

                            <h5 class="fw-bold mb-0 text-primary-custom">
                                <i class="bi bi-person-lines-fill me-2"></i>
                                Profile Information
                            </h5>

                        </div>

                        <div class="card-body">

                            <form method="POST">

                                <input type="hidden"
                                       name="csrf_token"
                                       value="<?= e($csrf_token) ?>">

                                <input type="hidden"
                                       name="action"
                                       value="update_profile">

                                <div class="mb-3">

                                    <label class="form-label fw-bold">
                                        Full Name
                                    </label>

                                    <input type="text"
                                           name="full_name"
                                           class="form-control"
                                           value="<?= e($profile['full_name']) ?>"
                                           required>

                                </div>

                                <div class="mb-3">

                                    <label class="form-label fw-bold">
                                        Username
                                    </label>

                                    <input type="text"
                                           name="username"
                                           class="form-control"
                                           value="<?= e($profile['username']) ?>"
                                           required>

                                </div>

                                <div class="mb-3">

                                    <label class="form-label fw-bold">
                                        Email Address
                                    </label>

                                    <input type="email"
                                           name="email"
                                           class="form-control"
                                           value="<?= e($profile['email']) ?>"
                                           required>

                                </div>

                                <div class="mb-3">

                                    <label class="form-label fw-bold">
                                        Account Role
                                    </label>

                                    <input type="text"
                                           class="form-control"
                                           value="Staff"
                                           disabled>

                                </div>

                                <div class="mb-3">

                                    <label class="form-label fw-bold">
                                        Account Created
                                    </label>

                                    <input type="text"
                                           class="form-control"
                                           value="<?= !empty($profile['created_at'])
                                               ? e(date(
                                                   'F d, Y',
                                                   strtotime($profile['created_at'])
                                               ))
                                               : 'Not available' ?>"
                                           disabled>

                                </div>

                                <button type="submit"
                                        class="btn btn-primary-custom">

                                    <i class="bi bi-save me-1"></i>
                                    Save Profile

                                </button>

                            </form>

                        </div>

                    </div>

                </div>

                <!-- CHANGE PASSWORD -->
                <div class="col-lg-5">

                    <div class="card border-0 shadow-sm">

                        <div class="card-header bg-white py-3">

                            <h5 class="fw-bold mb-0 text-primary-custom">
                                <i class="bi bi-shield-lock me-2"></i>
                                Change Password
                            </h5>

                        </div>

                        <div class="card-body">

                            <form method="POST">

                                <input type="hidden"
                                       name="csrf_token"
                                       value="<?= e($csrf_token) ?>">

                                <input type="hidden"
                                       name="action"
                                       value="change_password">

                                <div class="mb-3">

                                    <label class="form-label fw-bold">
                                        Current Password
                                    </label>

                                    <input type="password"
                                           name="current_password"
                                           class="form-control"
                                           autocomplete="current-password"
                                           required>

                                </div>

                                <div class="mb-3">

                                    <label class="form-label fw-bold">
                                        New Password
                                    </label>

                                    <input type="password"
                                           name="new_password"
                                           class="form-control"
                                           minlength="8"
                                           autocomplete="new-password"
                                           required>

                                    <small class="text-muted">
                                        Minimum of 8 characters.
                                    </small>

                                </div>

                                <div class="mb-3">

                                    <label class="form-label fw-bold">
                                        Confirm New Password
                                    </label>

                                    <input type="password"
                                           name="confirm_password"
                                           class="form-control"
                                           minlength="8"
                                           autocomplete="new-password"
                                           required>

                                </div>

                                <button type="submit"
                                        class="btn btn-primary-custom">

                                    <i class="bi bi-key me-1"></i>
                                    Change Password

                                </button>

                            </form>

                        </div>

                    </div>

                    <!-- ACCOUNT CARD -->
                    <div class="card border-0 shadow-sm mt-4">

                        <div class="card-body">

                            <div class="d-flex align-items-center gap-3">

                                <div class="rounded-circle bg-light d-flex align-items-center justify-content-center"
                                     style="width:60px;height:60px;">

                                    <i class="bi bi-person-circle fs-1 text-primary"></i>

                                </div>

                                <div>

                                    <h5 class="fw-bold mb-1">
                                        <?= e($profile['full_name']) ?>
                                    </h5>

                                    <div class="text-muted">
                                        <?= e($profile['email']) ?>
                                    </div>

                                    <span class="badge bg-primary mt-2">
                                        Staff Account
                                    </span>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </main>

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>