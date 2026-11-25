<?php
session_start();

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/notification_helper.php';

if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'resident')) {
    header('Location: ../login.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$success = '';
$error = '';
$user = [];
$resident = [];
$availableFields = [];
$emailCol = null;
$nameCol = null;
$usernameCol = null;

$notifications = [];
$notification_unread_count = 0;

try {
    $notifications = getUserNotifications($pdo, $user_id, 7);
    $notification_unread_count = getUnreadNotificationCount($pdo, $user_id);
} catch (Throwable $e) {
    error_log('Profile notification error: ' . $e->getMessage());
}

function profileColumns(PDO $pdo, string $table): array
{
    $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
    return array_map(
        fn($row) => $row['Field'],
        $stmt->fetchAll(PDO::FETCH_ASSOC)
    );
}

function firstColumn(array $columns, array $candidates): ?string
{
    foreach ($candidates as $column) {
        if (in_array($column, $columns, true)) {
            return $column;
        }
    }

    return null;
}

try {
    $userCols = profileColumns($pdo, 'users');
    $residentCols = profileColumns($pdo, 'resident_profiles');

    $userPK = firstColumn($userCols, ['user_id', 'id']);
    $residentPK = firstColumn($residentCols, ['resident_id', 'profile_id', 'id']);
    $linkCol = firstColumn($residentCols, ['user_id']);

    if (!$userPK || !$residentPK || !$linkCol) {
        throw new Exception('Unable to identify the user/resident profile columns.');
    }

    $uStmt = $pdo->prepare("SELECT * FROM users WHERE `$userPK` = ? LIMIT 1");
    $uStmt->execute([$user_id]);
    $user = $uStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new Exception('User record not found.');
    }

    $pStmt = $pdo->prepare("SELECT * FROM resident_profiles WHERE `$linkCol` = ? LIMIT 1");
    $pStmt->execute([$user_id]);
    $resident = $pStmt->fetch(PDO::FETCH_ASSOC);

    if (!$resident) {
        throw new Exception('Resident profile not found. Please contact the barangay administrator.');
    }

    $editable = [
        'first_name'      => ['label' => 'First Name', 'type' => 'text'],
        'middle_name'      => ['label' => 'Middle Name', 'type' => 'text'],
        'last_name'        => ['label' => 'Last Name', 'type' => 'text'],
        'suffix'            => ['label' => 'Suffix', 'type' => 'text'],
        'contact_number'    => ['label' => 'Contact Number', 'type' => 'text'],
        'phone'             => ['label' => 'Contact Number', 'type' => 'text'],
        'address'           => ['label' => 'Address', 'type' => 'text'],
        'street'            => ['label' => 'Street', 'type' => 'text'],
        'purok'             => ['label' => 'Purok', 'type' => 'text'],
        'birth_date'        => ['label' => 'Birth Date', 'type' => 'date'],
        'date_of_birth'     => ['label' => 'Birth Date', 'type' => 'date'],
        'gender'            => ['label' => 'Gender', 'type' => 'text'],
    ];

    foreach ($editable as $column => $meta) {
        if (in_array($column, $residentCols, true)) {
            $availableFields[$column] = $meta;
        }
    }

    $emailCol = firstColumn($userCols, ['email']);
    $nameCol = firstColumn($userCols, ['full_name', 'name']);
    $usernameCol = firstColumn($userCols, ['username']);

    if (!isset($_SESSION['profile_csrf'])) {
        $_SESSION['profile_csrf'] = bin2hex(random_bytes(32));
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        if (!hash_equals(
            $_SESSION['profile_csrf'] ?? '',
            $_POST['csrf'] ?? ''
        )) {
            throw new Exception('Invalid form session. Please refresh and try again.');
        }

        $updates = [];

        foreach ($availableFields as $column => $meta) {

            if (!array_key_exists($column, $_POST)) {
                continue;
            }

            $value = trim((string) $_POST[$column]);

            if ($meta['type'] === 'date' && $value !== '') {

                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                    throw new Exception('Please enter a valid date.');
                }

                $parts = explode('-', $value);

                if (!checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
                    throw new Exception('Please enter a valid birth date.');
                }

                if ($value > date('Y-m-d')) {
                    throw new Exception('Birth date cannot be in the future.');
                }
            }

            if ($column === 'contact_number' || $column === 'phone') {

                if ($value !== '' && !preg_match('/^[0-9+()\-\s]{7,20}$/', $value)) {
                    throw new Exception('Please enter a valid contact number.');
                }
            }

            $updates[$column] = $value;
        }

        $email = null;

        if ($emailCol && isset($_POST['email'])) {

            $email = trim($_POST['email']);

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Please enter a valid email address.');
            }
        }

        $pdo->beginTransaction();

        try {

            if (!empty($updates)) {

                $set = [];
                $params = [];

                foreach ($updates as $column => $value) {
                    $set[] = "`$column` = ?";
                    $params[] = $value === '' ? null : $value;
                }

                $params[] = $resident[$residentPK];

                $sql = "UPDATE resident_profiles
                        SET " . implode(', ', $set) . "
                        WHERE `$residentPK` = ?";

                $pdo->prepare($sql)->execute($params);
            }

            if ($emailCol && $email !== null) {

                $pdo->prepare(
                    "UPDATE users SET `$emailCol` = ? WHERE `$userPK` = ?"
                )->execute([$email, $user_id]);
            }

            $pdo->commit();

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        $uStmt->execute([$user_id]);
        $user = $uStmt->fetch(PDO::FETCH_ASSOC);

        $pStmt->execute([$user_id]);
        $resident = $pStmt->fetch(PDO::FETCH_ASSOC);

        $success = 'Your profile has been updated successfully.';

        $_SESSION['profile_csrf'] = bin2hex(random_bytes(32));
    }

} catch (Throwable $e) {

    $error = $e->getMessage();

    if (!isset($_SESSION['profile_csrf'])) {
        $_SESSION['profile_csrf'] = bin2hex(random_bytes(32));
    }
}

$csrf = $_SESSION['profile_csrf'] ?? '';

$displayName = trim(
    ($resident['first_name'] ?? '') . ' ' .
    ($resident['middle_name'] ?? '') . ' ' .
    ($resident['last_name'] ?? '') . ' ' .
    ($resident['suffix'] ?? '')
);

if (!$displayName) {
    $displayName = $user[$nameCol ?? 'full_name'] ?? 'Resident';
}

$accountStatus = $user['account_status'] ?? 'Resident';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Profile | Barangay Putatan</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
          rel="stylesheet">

    <link href="../assets/css/style.css" rel="stylesheet">

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

        .profile-page {
            max-width: 1200px;
            margin: auto;
            padding: 40px 20px;
        }

        .profile-heading {
            margin-bottom: 30px;
        }

        .profile-heading h2 {
            color: #142f82;
            font-size: 30px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .profile-heading p {
            color: #6c757d;
            margin-bottom: 0;
        }

        .profile-card {
            background: white;
            border: none;
            border-radius: 14px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }

        .profile-summary {
            padding: 25px;
        }

        .profile-avatar {
            width: 85px;
            height: 85px;
            min-width: 85px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #e8efff;
            color: #142f82;
            font-size: 35px;
        }

        .profile-name {
            color: #142f82;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .profile-status {
            font-size: 12px;
            font-weight: 600;
            padding: 6px 10px;
            border-radius: 20px;
        }

        .profile-card-header {
            background: white;
            border-bottom: 1px solid #e9ecef;
            padding: 20px 25px;
            border-radius: 14px 14px 0 0;
        }

        .profile-card-header h5 {
            color: #142f82;
            font-weight: 700;
            margin: 0;
        }

        .profile-card-body {
            padding: 25px;
        }

        .form-label {
            font-weight: 600;
            color: #343a40;
            margin-bottom: 8px;
        }

        .form-control,
        .form-select {
            border-radius: 8px;
            padding: 10px 12px;
            border-color: #dce1e8;
        }

        .form-control:focus,
        .form-select:focus {
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

        .profile-notice {
            font-size: 13px;
            color: #6c757d;
        }

        .notification-dropdown-menu {
            width: min(380px, calc(100vw - 30px));
            max-height: 430px;
            overflow-y: auto;
        }

        .notification-item {
            white-space: normal;
            border-bottom: 1px solid #eef0f4;
        }

        .notification-item.unread {
            background-color: #f3f6ff;
        }

        .notification-item:hover {
            background-color: #eef2ff;
        }

        .notification-message {
            color: #6c757d;
            font-size: .875rem;
        }

        .notification-badge {
            font-size: 10px;
        }

        @media(max-width:768px) {

            .resident-navbar {
                padding: 12px 15px;
            }

            .profile-page {
                padding: 25px 15px;
            }

            .profile-heading h2 {
                font-size: 24px;
            }

            .profile-summary,
            .profile-card-body {
                padding: 18px;
            }

            .profile-card-header {
                padding: 18px;
            }

            .profile-avatar {
                width: 70px;
                height: 70px;
                min-width: 70px;
                font-size: 28px;
            }

        }

    </style>

</head>

<body>

<!-- NAVBAR -->
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

            <!-- NOTIFICATION BELL -->
            <div class="dropdown">

                <button class="btn btn-outline-light btn-sm position-relative"
                        type="button"
                        data-bs-toggle="dropdown"
                        aria-expanded="false"
                        aria-label="Notifications">

                    <i class="bi bi-bell fs-5"></i>

                    <?php if ($notification_unread_count > 0): ?>

                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger notification-badge">

                            <?= $notification_unread_count > 99 ? '99+' : $notification_unread_count ?>

                        </span>

                    <?php endif; ?>

                </button>

                <div class="dropdown-menu dropdown-menu-end p-0 shadow notification-dropdown-menu">

                    <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">

                        <strong>Notifications</strong>

                    </div>

                    <?php if (empty($notifications)): ?>

                        <div class="text-center text-muted py-4 small">
                            No notifications yet.
                        </div>

                    <?php else: ?>

                        <?php foreach ($notifications as $notification): ?>

                            <?php

                                $notification_type = $notification['notification_type'] ?? 'general';
                                $related_id = (int)($notification['related_id'] ?? 0);
                                $notification_link = 'dashboard.php';

                                if ($notification_type === 'service_request' && $related_id > 0) {
                                    $notification_link = 'my-requests.php?request_id=' . $related_id;
                                } elseif ($notification_type === 'feedback_response' && $related_id > 0) {
                                    $notification_link = 'my-feedback.php?feedback_id=' . $related_id;
                                } elseif ($notification_type === 'announcement' && $related_id > 0) {
                                    $notification_link = 'dashboard.php?announcement_id=' . $related_id;
                                } else {
                                    $notification_link = 'notifications.php';
                                }

                            ?>

                            <a href="<?= htmlspecialchars($notification_link, ENT_QUOTES, 'UTF-8') ?>"
                               class="dropdown-item notification-item <?= (int)$notification['is_read'] === 0 ? 'unread' : '' ?>">

                                <strong class="small">
                                    <?= htmlspecialchars($notification['title'], ENT_QUOTES, 'UTF-8') ?>
                                </strong>

                                <div class="notification-message mt-1">
                                    <?= htmlspecialchars($notification['message'], ENT_QUOTES, 'UTF-8') ?>
                                </div>

                                <small class="text-muted">
                                    <?= !empty($notification['created_at'])
                                        ? htmlspecialchars(date('M d, Y h:i A', strtotime($notification['created_at'])), ENT_QUOTES, 'UTF-8')
                                        : '' ?>
                                </small>

                            </a>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    <div class="text-center border-top py-2">

                        <a href="notifications.php" class="small text-decoration-none">
                            View all notifications
                        </a>

                    </div>

                </div>

            </div>

            <a href="profile.php" class="btn btn-profile btn-sm">
                My Profile
            </a>

            <a href="../logout.php" class="btn btn-yellow btn-sm">
                Logout
            </a>

        </div>

    </div>

</nav>

<!-- MAIN CONTENT -->
<div class="profile-page">

    <!-- PAGE HEADING -->
    <div class="profile-heading">

        <h2>
            <i class="bi bi-person-circle me-2"></i>
            My Profile
        </h2>

        <p>
            View and update your personal information.
        </p>

    </div>

    <!-- ALERTS -->
    <?php if ($success): ?>

        <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">

            <i class="bi bi-check-circle me-2"></i>
            <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>

            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>

        </div>

    <?php endif; ?>

    <?php if ($error): ?>

        <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">

            <i class="bi bi-exclamation-circle me-2"></i>
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>

            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>

        </div>

    <?php endif; ?>

    <!-- PROFILE SUMMARY -->
    <div class="card profile-card mb-4">

        <div class="card-body profile-summary">

            <div class="d-flex align-items-center gap-4 flex-wrap">

                <div class="profile-avatar">

                    <i class="bi bi-person"></i>

                </div>

                <div>

                    <h4 class="profile-name">
                        <?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>
                    </h4>

                    <div class="text-muted mb-2">

                        <i class="bi bi-person-check me-1"></i>
                        Resident Account

                    </div>

                    <span class="badge bg-success profile-status">

                        <?= htmlspecialchars((string)$accountStatus, ENT_QUOTES, 'UTF-8') ?>

                    </span>

                </div>

            </div>

        </div>

    </div>

    <!-- PERSONAL INFORMATION -->
    <div class="card profile-card">

        <div class="profile-card-header">

            <h5>
                <i class="bi bi-pencil-square me-2"></i>
                Personal Information
            </h5>

        </div>

        <div class="profile-card-body">

            <form method="POST" autocomplete="on">

                <input type="hidden"
                       name="csrf"
                       value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">

                <div class="row g-4">

                    <?php foreach ($availableFields as $column => $meta): ?>

                        <div class="col-md-6">

                            <label class="form-label"
                                   for="<?= htmlspecialchars($column, ENT_QUOTES, 'UTF-8') ?>">

                                <?= htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8') ?>

                            </label>

                            <input
                                class="form-control"
                                type="<?= htmlspecialchars($meta['type'], ENT_QUOTES, 'UTF-8') ?>"
                                id="<?= htmlspecialchars($column, ENT_QUOTES, 'UTF-8') ?>"
                                name="<?= htmlspecialchars($column, ENT_QUOTES, 'UTF-8') ?>"
                                value="<?= htmlspecialchars((string)($resident[$column] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                <?php if ($meta['type'] === 'date'): ?>
                                    max="<?= date('Y-m-d') ?>"
                                <?php endif; ?>
                            >

                        </div>

                    <?php endforeach; ?>

                    <?php if ($emailCol): ?>

                        <div class="col-md-6">

                            <label class="form-label" for="email">
                                Email Address
                            </label>

                            <input
                                class="form-control"
                                type="email"
                                id="email"
                                name="email"
                                value="<?= htmlspecialchars((string)($user[$emailCol] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                required
                            >

                        </div>

                    <?php endif; ?>

                    <?php if ($usernameCol): ?>

                        <div class="col-md-6">

                            <label class="form-label" for="username">
                                Username
                            </label>

                            <input
                                class="form-control"
                                type="text"
                                id="username"
                                value="<?= htmlspecialchars((string)($user[$usernameCol] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                disabled
                            >

                            <small class="text-muted">
                                Username cannot be changed here.
                            </small>

                        </div>

                    <?php endif; ?>

                </div>

                <hr class="my-4">

                <div class="d-flex flex-wrap gap-2">

                    <button type="submit" class="btn btn-save">

                        <i class="bi bi-save me-2"></i>
                        Save Changes

                    </button>

                    <a href="dashboard.php" class="btn btn-outline-secondary btn-cancel">

                        <i class="bi bi-arrow-left me-2"></i>
                        Back to Dashboard

                    </a>

                    <a href="change-password.php" class="btn btn-outline-primary btn-cancel">

                        <i class="bi bi-shield-lock me-2"></i>
                        Change Password

                    </a>

                </div>

            </form>

        </div>

    </div>

    <!-- SECURITY NOTICE -->
    <div class="profile-notice mt-3">

        <i class="bi bi-shield-lock me-1"></i>

        Your personal information is protected. Only your own resident profile
        can be updated through this page.

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>