<?php
session_start();

require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'resident')) {
    header('Location: ../login.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];

$success = '';
$error = '';

/* =========================
   CSRF TOKEN
========================= */

if (!isset($_SESSION['change_password_csrf'])) {
    $_SESSION['change_password_csrf'] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION['change_password_csrf'];

/* =========================
   DATABASE COLUMN DETECTION
========================= */

function getPasswordTableColumns(PDO $pdo, string $table): array
{
    $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");

    return array_map(
        fn($row) => $row['Field'],
        $stmt->fetchAll(PDO::FETCH_ASSOC)
    );
}

function getPasswordColumn(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

/* =========================
   PROCESS PASSWORD CHANGE
========================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        /* CSRF VALIDATION */

        if (!hash_equals(
            $_SESSION['change_password_csrf'] ?? '',
            $_POST['csrf'] ?? ''
        )) {
            throw new Exception('Invalid form session. Please refresh the page and try again.');
        }

        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        /* REQUIRED FIELDS */

        if (
            $current_password === '' ||
            $new_password === '' ||
            $confirm_password === ''
        ) {
            throw new Exception('Please fill in all password fields.');
        }

        /* PASSWORD LENGTH */

        if (strlen($new_password) < 8) {
            throw new Exception('Your new password must contain at least 8 characters.');
        }

        /* CONFIRMATION */

        if ($new_password !== $confirm_password) {
            throw new Exception('New password and confirmation password do not match.');
        }

        /* PREVENT SAME PASSWORD */

        if ($current_password === $new_password) {
            throw new Exception('Your new password must be different from your current password.');
        }

        /* GET USER TABLE STRUCTURE */

        $userColumns = getPasswordTableColumns($pdo, 'users');

        $userPK = getPasswordColumn(
            $userColumns,
            ['user_id', 'id']
        );

        $passwordColumn = getPasswordColumn(
            $userColumns,
            ['password']
        );

        if (!$userPK || !$passwordColumn) {
            throw new Exception('Unable to identify your account information.');
        }

        /* FETCH CURRENT PASSWORD */

        $stmt = $pdo->prepare(
            "SELECT `$passwordColumn`
             FROM users
             WHERE `$userPK` = ?
             LIMIT 1"
        );

        $stmt->execute([$user_id]);

        $storedPassword = $stmt->fetchColumn();

        if ($storedPassword === false) {
            throw new Exception('Account not found.');
        }

        /* VERIFY CURRENT PASSWORD */

        $passwordInfo = password_get_info((string)$storedPassword);

        $isHashed = isset($passwordInfo['algoName'])
            && $passwordInfo['algoName'] !== 'unknown';

        if ($isHashed) {

            $validCurrentPassword = password_verify(
                $current_password,
                (string)$storedPassword
            );

        } else {

            // Legacy plaintext password compatibility.
            $validCurrentPassword = hash_equals(
                (string)$storedPassword,
                (string)$current_password
            );
        }

        if (!$validCurrentPassword) {
            throw new Exception('Your current password is incorrect.');
        }

        /* HASH NEW PASSWORD */

        $newPasswordHash = password_hash(
            $new_password,
            PASSWORD_DEFAULT
        );

        if (!$newPasswordHash) {
            throw new Exception('Unable to secure your new password.');
        }

        /* UPDATE DATABASE */

        $updateStmt = $pdo->prepare(
            "UPDATE users
             SET `$passwordColumn` = ?
             WHERE `$userPK` = ?"
        );

        $updateStmt->execute([
            $newPasswordHash,
            $user_id
        ]);

        /* SUCCESS */

        $success = 'Your password has been changed successfully.';

        $_SESSION['change_password_csrf'] = bin2hex(random_bytes(32));
        $csrf = $_SESSION['change_password_csrf'];

    } catch (Throwable $e) {

        $error = $e->getMessage();

        error_log('Change password error: ' . $e->getMessage());
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Change Password | Barangay Putatan</title>

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
          rel="stylesheet">

    <!-- Shared CSS -->
    <link href="../assets/css/style.css"
          rel="stylesheet">

    <style>

        body {
            background-color: #f4f6fa;
        }

        .resident-navbar {
            background-color: #142f82;
            padding: 12px 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }

        .resident-navbar .navbar-brand {
            display: flex;
            align-items: center;
            color: white;
            font-weight: 700;
        }

        .resident-navbar img {
            object-fit: contain;
            margin-right: 10px;
        }

        .btn-yellow {
            background-color: #ffdd00;
            color: #142f82;
            border: none;
            font-weight: 600;
        }

        .btn-yellow:hover {
            background-color: #f0ce00;
            color: #142f82;
        }

        .btn-profile {
            background-color: white;
            color: #142f82;
            border: none;
            font-weight: 600;
        }

        .btn-profile:hover {
            background-color: #e9eefb;
            color: #142f82;
        }

        .password-page {
            max-width: 1200px;
            margin: auto;
            padding: 40px 20px;
        }

        .page-heading {
            margin-bottom: 30px;
        }

        .page-heading h2 {
            color: #142f82;
            font-size: 30px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .page-heading p {
            color: #6c757d;
            margin-bottom: 0;
        }

        .password-card {
            background: white;
            border: none;
            border-radius: 14px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }

        .password-card-header {
            background: white;
            border-bottom: 1px solid #e9ecef;
            padding: 20px 25px;
            border-radius: 14px 14px 0 0;
        }

        .password-card-header h5 {
            color: #142f82;
            font-weight: 700;
            margin: 0;
        }

        .password-card-body {
            padding: 25px;
        }

        .form-label {
            font-weight: 600;
            color: #343a40;
            margin-bottom: 8px;
        }

        .form-control {
            border-radius: 8px;
            padding: 10px 12px;
            border-color: #dce1e8;
        }

        .form-control:focus {
            border-color: #142f82;
            box-shadow: 0 0 0 0.2rem rgba(20,47,130,0.12);
        }

        .btn-save {
            background-color: #142f82;
            color: white;
            border: 1px solid #142f82;
            border-radius: 8px;
            padding: 10px 20px;
            font-weight: 600;
        }

        .btn-save:hover {
            background-color: #0e2465;
            border-color: #0e2465;
            color: white;
        }

        .btn-cancel {
            border-radius: 8px;
            padding: 10px 20px;
            font-weight: 600;
        }

        .password-guidelines {
            background: #f8f9fc;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 18px;
        }

        .password-guidelines h6 {
            color: #142f82;
            font-weight: 700;
        }

        .password-guidelines ul {
            margin-bottom: 0;
        }

        .password-guidelines li {
            margin-bottom: 5px;
            color: #6c757d;
        }

        .password-guidelines li:last-child {
            margin-bottom: 0;
        }

        .password-input-group .btn {
            border-color: #dce1e8;
        }

        @media(max-width:768px) {

            .resident-navbar {
                padding: 12px 15px;
            }

            .password-page {
                padding: 25px 15px;
            }

            .page-heading h2 {
                font-size: 24px;
            }

            .password-card-body {
                padding: 18px;
            }

            .password-card-header {
                padding: 18px;
            }

        }

    </style>

</head>

<body>

<!-- =========================
     NAVBAR
========================= -->

<nav class="navbar navbar-expand-lg navbar-dark resident-navbar">

    <div class="container-fluid">

        <a class="navbar-brand" href="dashboard.php">

            <img src="../assets/images/barangay-logo.png"
                 width="42"
                 height="42"
                 alt="Barangay Logo">

            Barangay Putatan

        </a>

        <div class="ms-auto d-flex align-items-center gap-2">

            <a href="profile.php"
               class="btn btn-profile btn-sm">

                <i class="bi bi-person-circle me-1"></i>
                My Profile

            </a>

            <a href="../logout.php"
               class="btn btn-yellow btn-sm">

                Logout

            </a>

        </div>

    </div>

</nav>

<!-- =========================
     MAIN CONTENT
========================= -->

<div class="password-page">

    <!-- PAGE HEADING -->

    <div class="page-heading">

        <h2>
            <i class="bi bi-shield-lock me-2"></i>
            Change Password
        </h2>

        <p>
            Update your password to keep your account secure.
        </p>

    </div>

    <!-- SUCCESS ALERT -->

    <?php if ($success): ?>

        <div class="alert alert-success alert-dismissible fade show mb-4">

            <i class="bi bi-check-circle me-2"></i>

            <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>

            <button type="button"
                    class="btn-close"
                    data-bs-dismiss="alert">
            </button>

        </div>

    <?php endif; ?>

    <!-- ERROR ALERT -->

    <?php if ($error): ?>

        <div class="alert alert-danger alert-dismissible fade show mb-4">

            <i class="bi bi-exclamation-circle me-2"></i>

            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>

            <button type="button"
                    class="btn-close"
                    data-bs-dismiss="alert">
            </button>

        </div>

    <?php endif; ?>

    <!-- PASSWORD CARD -->

    <div class="card password-card">

        <div class="password-card-header">

            <h5>

                <i class="bi bi-key me-2"></i>
                Password Information

            </h5>

        </div>

        <div class="password-card-body">

            <form method="POST"
                  autocomplete="off">

                <input type="hidden"
                       name="csrf"
                       value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">

                <!-- CURRENT PASSWORD -->

                <div class="mb-4">

                    <label class="form-label"
                           for="current_password">

                        Current Password

                    </label>

                    <div class="input-group password-input-group">

                        <input type="password"
                               class="form-control"
                               id="current_password"
                               name="current_password"
                               required
                               autocomplete="current-password">

                        <button class="btn btn-outline-secondary toggle-password"
                                type="button"
                                data-target="current_password">

                            <i class="bi bi-eye"></i>

                        </button>

                    </div>

                </div>

                <!-- NEW PASSWORD -->

                <div class="mb-4">

                    <label class="form-label"
                           for="new_password">

                        New Password

                    </label>

                    <div class="input-group password-input-group">

                        <input type="password"
                               class="form-control"
                               id="new_password"
                               name="new_password"
                               minlength="8"
                               required
                               autocomplete="new-password">

                        <button class="btn btn-outline-secondary toggle-password"
                                type="button"
                                data-target="new_password">

                            <i class="bi bi-eye"></i>

                        </button>

                    </div>

                </div>

                <!-- CONFIRM PASSWORD -->

                <div class="mb-4">

                    <label class="form-label"
                           for="confirm_password">

                        Confirm New Password

                    </label>

                    <div class="input-group password-input-group">

                        <input type="password"
                               class="form-control"
                               id="confirm_password"
                               name="confirm_password"
                               minlength="8"
                               required
                               autocomplete="new-password">

                        <button class="btn btn-outline-secondary toggle-password"
                                type="button"
                                data-target="confirm_password">

                            <i class="bi bi-eye"></i>

                        </button>

                    </div>

                </div>

                <!-- GUIDELINES -->

                <div class="password-guidelines mb-4">

                    <h6>
                        <i class="bi bi-info-circle me-2"></i>
                        Password Requirements
                    </h6>

                    <ul>

                        <li>At least 8 characters long.</li>

                        <li>Must be different from your current password.</li>

                        <li>Both new password fields must match.</li>

                        <li>Enter your current password correctly before updating.</li>

                    </ul>

                </div>

                <!-- BUTTONS -->

                <div class="d-flex flex-wrap gap-2">

                    <button type="submit"
                            class="btn btn-save">

                        <i class="bi bi-shield-check me-2"></i>
                        Change Password

                    </button>

                    <a href="profile.php"
                       class="btn btn-outline-secondary btn-cancel">

                        <i class="bi bi-arrow-left me-2"></i>
                        Back to Profile

                    </a>

                </div>

            </form>

        </div>

    </div>

</div>

<!-- =========================
     JAVASCRIPT
========================= -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>

// Toggle password visibility
document.querySelectorAll('.toggle-password').forEach(function(button) {

    button.addEventListener('click', function() {

        const targetId = this.dataset.target;
        const input = document.getElementById(targetId);
        const icon = this.querySelector('i');

        if (input.type === 'password') {

            input.type = 'text';
            icon.classList.remove('bi-eye');
            icon.classList.add('bi-eye-slash');

        } else {

            input.type = 'password';
            icon.classList.remove('bi-eye-slash');
            icon.classList.add('bi-eye');

        }

    });

});

// Client-side password confirmation
document.querySelector('form').addEventListener('submit', function(e) {

    const newPassword = document.getElementById('new_password').value;
    const confirmPassword = document.getElementById('confirm_password').value;

    if (newPassword !== confirmPassword) {

        e.preventDefault();

        document.getElementById('confirm_password').setCustomValidity(
            'Passwords do not match.'
        );

        document.getElementById('confirm_password').reportValidity();

    } else {

        document.getElementById('confirm_password').setCustomValidity('');

    }

});

document.getElementById('confirm_password').addEventListener('input', function() {

    this.setCustomValidity('');

});

</script>

</body>
</html>