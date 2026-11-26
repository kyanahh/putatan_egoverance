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

/*
|--------------------------------------------------------------------------
| Notifications
|--------------------------------------------------------------------------
*/
try {
    $notifications = getUserNotifications($pdo, $user_id, 7);
    $notification_unread_count = getUnreadNotificationCount($pdo, $user_id);
} catch (Throwable $e) {
    error_log('Profile notification error: ' . $e->getMessage());
}

/*
|--------------------------------------------------------------------------
| Database Helpers
|--------------------------------------------------------------------------
*/
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

/*
|--------------------------------------------------------------------------
| Load User and Resident Information
|--------------------------------------------------------------------------
*/
try {

    $userCols = profileColumns($pdo, 'users');
    $residentCols = profileColumns($pdo, 'resident_profiles');

    $userPK = firstColumn($userCols, ['user_id', 'id']);

    $residentPK = firstColumn(
        $residentCols,
        ['resident_id', 'profile_id', 'id']
    );

    $linkCol = firstColumn($residentCols, ['user_id']);

    if (!$userPK || !$residentPK || !$linkCol) {
        throw new Exception(
            'Unable to identify the user/resident profile columns.'
        );
    }

    $uStmt = $pdo->prepare(
        "SELECT * FROM users WHERE `$userPK` = ? LIMIT 1"
    );

    $uStmt->execute([$user_id]);

    $user = $uStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new Exception('User record not found.');
    }

    $pStmt = $pdo->prepare(
        "SELECT * FROM resident_profiles WHERE `$linkCol` = ? LIMIT 1"
    );

    $pStmt->execute([$user_id]);

    $resident = $pStmt->fetch(PDO::FETCH_ASSOC);

    if (!$resident) {
        throw new Exception(
            'Resident profile not found. Please contact the barangay administrator.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Editable Resident Fields
    |--------------------------------------------------------------------------
    */

    $editable = [

        // Personal Information
        'first_name' => [
            'label' => 'First Name',
            'type' => 'text',
            'group' => 'personal'
        ],

        'middle_name' => [
            'label' => 'Middle Name',
            'type' => 'text',
            'group' => 'personal'
        ],

        'last_name' => [
            'label' => 'Last Name',
            'type' => 'text',
            'group' => 'personal'
        ],

        'suffix' => [
            'label' => 'Suffix',
            'type' => 'text',
            'group' => 'personal'
        ],

        'birth_date' => [
            'label' => 'Birth Date',
            'type' => 'date',
            'group' => 'personal'
        ],

        'date_of_birth' => [
            'label' => 'Birth Date',
            'type' => 'date',
            'group' => 'personal'
        ],

        'sex' => [
            'label' => 'Sex',
            'type' => 'select',
            'options' => [
                'Male',
                'Female',
                'Prefer not to say'
            ],
            'group' => 'personal'
        ],

        'gender' => [
            'label' => 'Gender',
            'type' => 'text',
            'group' => 'personal'
        ],

        'civil_status' => [
            'label' => 'Civil Status',
            'type' => 'select',
            'options' => [
                'Single',
                'Married',
                'Widowed',
                'Separated',
                'Divorced'
            ],
            'group' => 'personal'
        ],

        'occupation' => [
            'label' => 'Occupation',
            'type' => 'text',
            'group' => 'personal'
        ],

        // Contact Information
        'contact_number' => [
            'label' => 'Contact Number',
            'type' => 'text',
            'group' => 'contact'
        ],

        'phone' => [
            'label' => 'Contact Number',
            'type' => 'text',
            'group' => 'contact'
        ],

        // Residential Address
        'house_number' => [
            'label' => 'House Number / Block / Lot',
            'type' => 'text',
            'group' => 'address'
        ],

        'address' => [
            'label' => 'Address',
            'type' => 'text',
            'group' => 'address'
        ],

        'street' => [
            'label' => 'Street',
            'type' => 'text',
            'group' => 'address'
        ],

        'subdivision' => [
            'label' => 'Subdivision / Village',
            'type' => 'text',
            'group' => 'address'
        ],

        'purok' => [
            'label' => 'Purok',
            'type' => 'text',
            'group' => 'address'
        ],

        'barangay' => [
            'label' => 'Barangay',
            'type' => 'text',
            'group' => 'address'
        ],

        'city' => [
            'label' => 'City / Municipality',
            'type' => 'text',
            'group' => 'address'
        ],

        // Residency Information
        'residency_since' => [
            'label' => 'Date of Residency',
            'type' => 'date',
            'group' => 'residency'
        ],

        'years_of_residency' => [
            'label' => 'Years of Residency',
            'type' => 'number',
            'group' => 'residency'
        ]
    ];

    /*
    |--------------------------------------------------------------------------
    | Only Display Fields That Exist in the Database
    |--------------------------------------------------------------------------
    */

    foreach ($editable as $column => $meta) {

        if (in_array($column, $residentCols, true)) {

            $availableFields[$column] = $meta;

        }

    }

    $emailCol = firstColumn($userCols, ['email']);

    $nameCol = firstColumn($userCols, ['full_name', 'name']);

    $usernameCol = firstColumn($userCols, ['username']);

    /*
    |--------------------------------------------------------------------------
    | CSRF
    |--------------------------------------------------------------------------
    */

    if (!isset($_SESSION['profile_csrf'])) {

        $_SESSION['profile_csrf'] = bin2hex(random_bytes(32));

    }

    /*
    |--------------------------------------------------------------------------
    | Handle Profile Update
    |--------------------------------------------------------------------------
    */

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        if (!hash_equals(
            $_SESSION['profile_csrf'] ?? '',
            $_POST['csrf'] ?? ''
        )) {

            throw new Exception(
                'Invalid form session. Please refresh and try again.'
            );

        }

        $updates = [];

        foreach ($availableFields as $column => $meta) {

            if (!array_key_exists($column, $_POST)) {
                continue;
            }

            $value = trim((string) $_POST[$column]);

            /*
            |--------------------------------------------------------------------------
            | Date Validation
            |--------------------------------------------------------------------------
            */

            if ($meta['type'] === 'date' && $value !== '') {

                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {

                    throw new Exception(
                        'Please enter a valid date.'
                    );

                }

                $parts = explode('-', $value);

                if (!checkdate(
                    (int) $parts[1],
                    (int) $parts[2],
                    (int) $parts[0]
                )) {

                    throw new Exception(
                        'Please enter a valid date.'
                    );

                }

                if ($column !== 'residency_since' && $value > date('Y-m-d')) {

                    throw new Exception(
                        'Birth date cannot be in the future.'
                    );

                }

                if ($column === 'residency_since' && $value > date('Y-m-d')) {

                    throw new Exception(
                        'Date of residency cannot be in the future.'
                    );

                }

            }

            /*
            |--------------------------------------------------------------------------
            | Contact Number Validation
            |--------------------------------------------------------------------------
            */

            if ($column === 'contact_number' || $column === 'phone') {

                if (
                    $value !== '' &&
                    !preg_match('/^[0-9+()\-\\s]{7,20}$/', $value)
                ) {

                    throw new Exception(
                        'Please enter a valid contact number.'
                    );

                }

            }

            /*
            |--------------------------------------------------------------------------
            | Years of Residency Validation
            |--------------------------------------------------------------------------
            */

            if ($column === 'years_of_residency' && $value !== '') {

                if (
                    !ctype_digit($value) ||
                    (int) $value < 0 ||
                    (int) $value > 150
                ) {

                    throw new Exception(
                        'Please enter a valid number of years of residency.'
                    );

                }

            }

            /*
            |--------------------------------------------------------------------------
            | Civil Status Validation
            |--------------------------------------------------------------------------
            */

            if ($column === 'civil_status' && $value !== '') {

                $allowedCivilStatus = [
                    'Single',
                    'Married',
                    'Widowed',
                    'Separated',
                    'Divorced'
                ];

                if (!in_array($value, $allowedCivilStatus, true)) {

                    throw new Exception(
                        'Please select a valid civil status.'
                    );

                }

            }

            /*
            |--------------------------------------------------------------------------
            | Sex Validation
            |--------------------------------------------------------------------------
            */

            if ($column === 'sex' && $value !== '') {

                $allowedSex = [
                    'Male',
                    'Female',
                    'Prefer not to say'
                ];

                if (!in_array($value, $allowedSex, true)) {

                    throw new Exception(
                        'Please select a valid sex.'
                    );

                }

            }

            $updates[$column] = $value;

        }

        /*
        |--------------------------------------------------------------------------
        | Email Validation
        |--------------------------------------------------------------------------
        */

        $email = null;

        if ($emailCol && isset($_POST['email'])) {

            $email = trim($_POST['email']);

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

                throw new Exception(
                    'Please enter a valid email address.'
                );

            }

        }

        /*
        |--------------------------------------------------------------------------
        | Database Transaction
        |--------------------------------------------------------------------------
        */

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

            /*
            |--------------------------------------------------------------------------
            | Update Email
            |--------------------------------------------------------------------------
            */

            if ($emailCol && $email !== null) {

                $pdo->prepare(
                    "UPDATE users
                     SET `$emailCol` = ?
                     WHERE `$userPK` = ?"
                )->execute([$email, $user_id]);

            }

            $pdo->commit();

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {

                $pdo->rollBack();

            }

            throw $e;

        }

        /*
        |--------------------------------------------------------------------------
        | Reload Records
        |--------------------------------------------------------------------------
        */

        $uStmt->execute([$user_id]);

        $user = $uStmt->fetch(PDO::FETCH_ASSOC);

        $pStmt->execute([$user_id]);

        $resident = $pStmt->fetch(PDO::FETCH_ASSOC);

        $success = 'Your profile has been updated successfully.';

        $_SESSION['profile_csrf'] = bin2hex(random_bytes(32));

    }

} catch (Throwable $e) {

    $error = $e->getMessage();

    error_log('Resident profile error: ' . $e->getMessage());

    if (!isset($_SESSION['profile_csrf'])) {

        $_SESSION['profile_csrf'] = bin2hex(random_bytes(32));

    }

}

/*
|--------------------------------------------------------------------------
| Display Information
|--------------------------------------------------------------------------
*/

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

    <!-- PROFILE INFORMATION -->
    <div class="card profile-card">

        <div class="profile-card-header">

            <h5>
                <i class="bi bi-person-vcard me-2"></i>
                Resident Information
            </h5>

        </div>

        <div class="profile-card-body">

            <div class="alert alert-info border-0 mb-4">

                <i class="bi bi-info-circle me-2"></i>

                <strong>Keep your information updated.</strong>

                Your personal information and residential address will
                be used to automatically fill in the details of your
                future barangay service applications.

            </div>

            <form method="POST" autocomplete="on">

                <input type="hidden"
                    name="csrf"
                    value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">

                <?php
                $fieldGroups = [

                    'personal' => [
                        'title' => 'Personal Information',
                        'description' => 'Basic information used for barangay documents.',
                        'icon' => 'person'
                    ],

                    'contact' => [
                        'title' => 'Contact Information',
                        'description' => 'Contact information used for communication.',
                        'icon' => 'telephone'
                    ],

                    'address' => [
                        'title' => 'Residential Address',
                        'description' => 'Your complete residential address.',
                        'icon' => 'geo-alt'
                    ],

                    'residency' => [
                        'title' => 'Residency Information',
                        'description' => 'Information about your residency in the barangay.',
                        'icon' => 'house-check'
                    ]

                ];
                ?>

                <?php foreach ($fieldGroups as $groupKey => $group): ?>

                    <?php

                    $groupFields = array_filter(
                        $availableFields,
                        fn($meta) => ($meta['group'] ?? '') === $groupKey
                    );

                    ?>

                    <?php if (!empty($groupFields) || ($groupKey === 'contact' && $emailCol)): ?>

                        <div class="mb-4">

                            <div class="mb-3">

                                <h6 class="fw-bold text-primary-custom mb-1">

                                    <i class="bi bi-<?= htmlspecialchars($group['icon'], ENT_QUOTES, 'UTF-8') ?> me-2"></i>

                                    <?= htmlspecialchars($group['title'], ENT_QUOTES, 'UTF-8') ?>

                                </h6>

                                <small class="text-muted">

                                    <?= htmlspecialchars($group['description'], ENT_QUOTES, 'UTF-8') ?>

                                </small>

                            </div>

                            <div class="row g-3">

                                <?php foreach ($groupFields as $column => $meta): ?>

                                    <div class="col-md-6">

                                        <label class="form-label"
                                            for="<?= htmlspecialchars($column, ENT_QUOTES, 'UTF-8') ?>">

                                            <?= htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8') ?>

                                        </label>

                                        <?php if ($meta['type'] === 'select'): ?>

                                            <select class="form-select"
                                                    id="<?= htmlspecialchars($column, ENT_QUOTES, 'UTF-8') ?>"
                                                    name="<?= htmlspecialchars($column, ENT_QUOTES, 'UTF-8') ?>">

                                                <option value="">Select <?= htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8') ?></option>

                                                <?php foreach ($meta['options'] as $option): ?>

                                                    <option value="<?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>"
                                                        <?= (($resident[$column] ?? '') === $option) ? 'selected' : '' ?>>

                                                        <?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>

                                                    </option>

                                                <?php endforeach; ?>

                                            </select>

                                        <?php else: ?>

                                            <input
                                                class="form-control"
                                                type="<?= htmlspecialchars($meta['type'], ENT_QUOTES, 'UTF-8') ?>"
                                                id="<?= htmlspecialchars($column, ENT_QUOTES, 'UTF-8') ?>"
                                                name="<?= htmlspecialchars($column, ENT_QUOTES, 'UTF-8') ?>"
                                                value="<?= htmlspecialchars((string)($resident[$column] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                <?php if ($meta['type'] === 'date'): ?>
                                                    max="<?= date('Y-m-d') ?>"
                                                <?php endif; ?>
                                                <?php if ($meta['type'] === 'number'): ?>
                                                    min="0"
                                                    max="150"
                                                <?php endif; ?>
                                            >

                                        <?php endif; ?>

                                    </div>

                                <?php endforeach; ?>

                                <?php if ($groupKey === 'contact' && $emailCol): ?>

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

                            </div>

                        </div>

                        <?php if ($groupKey !== 'residency'): ?>

                            <hr class="my-4">

                        <?php endif; ?>

                    <?php endif; ?>

                <?php endforeach; ?>

                <?php if ($usernameCol): ?>

                    <hr class="my-4">

                    <div class="mb-4">

                        <h6 class="fw-bold text-primary-custom mb-1">

                            <i class="bi bi-person-lock me-2"></i>

                            Account Information

                        </h6>

                        <small class="text-muted">

                            Your username is used when logging in.

                        </small>

                        <div class="row g-3 mt-1">

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

                        </div>

                    </div>

                <?php endif; ?>

                <hr class="my-4">

                <div class="d-flex flex-wrap gap-2">

                    <button type="submit" class="btn btn-save">

                        <i class="bi bi-save me-2"></i>

                        Save Changes

                    </button>

                    <a href="dashboard.php"
                    class="btn btn-outline-secondary btn-cancel">

                        <i class="bi bi-arrow-left me-2"></i>

                        Back to Dashboard

                    </a>

                    <a href="change-password.php"
                    class="btn btn-outline-primary btn-cancel">

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