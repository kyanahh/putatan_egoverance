<?php
require_once '../includes/admin_auth.php';
require_once '../includes/audit_helper.php';
require_once '../includes/notification_helper.php';

$message = "";
$message_type = "success";
$generated_password = "";

/*
|--------------------------------------------------------------------------
| HELPER
|--------------------------------------------------------------------------
*/
function staff_admin_escape($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/*
|--------------------------------------------------------------------------
| POST ACTIONS
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    /*
    |--------------------------------------------------------------------------
    | ADD STAFF
    |--------------------------------------------------------------------------
    */
    if ($action === 'add_staff') {

        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $position = trim($_POST['position'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $contact_number = trim($_POST['contact_number'] ?? '');
        $date_hired = trim($_POST['date_hired'] ?? '');

        if (
            $full_name === '' ||
            $email === '' ||
            $username === '' ||
            $position === ''
        ) {

            $message = "Please complete all required fields.";
            $message_type = "danger";

        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

            $message = "Please enter a valid email address.";
            $message_type = "danger";

        } else {

            try {

                $check = $pdo->prepare("
                    SELECT user_id
                    FROM users
                    WHERE email = :email
                       OR username = :username
                    LIMIT 1
                ");

                $check->execute([
                    ':email' => $email,
                    ':username' => $username
                ]);

                if ($check->fetch()) {

                    $message = "Email or username already exists.";
                    $message_type = "danger";

                } else {

                    $generated_password = bin2hex(random_bytes(4));
                    $hashed_password = password_hash(
                        $generated_password,
                        PASSWORD_DEFAULT
                    );

                    $pdo->beginTransaction();

                    /*
                    |--------------------------------------------------------------------------
                    | Create user
                    |--------------------------------------------------------------------------
                    */

                    $user_stmt = $pdo->prepare("
                        INSERT INTO users (
                            full_name,
                            email,
                            username,
                            password,
                            role,
                            account_status
                        ) VALUES (
                            :full_name,
                            :email,
                            :username,
                            :password,
                            'staff',
                            'approved'
                        )
                    ");

                    $user_stmt->execute([
                        ':full_name' => $full_name,
                        ':email' => $email,
                        ':username' => $username,
                        ':password' => $hashed_password
                    ]);

                    $new_user_id = (int)$pdo->lastInsertId();

                    /*
                    |--------------------------------------------------------------------------
                    | Automatically generate employee number
                    |--------------------------------------------------------------------------
                    */

                    $employee_number = 'EMP-' . str_pad(
                        (string)$new_user_id,
                        6,
                        '0',
                        STR_PAD_LEFT
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | Create staff profile
                    |--------------------------------------------------------------------------
                    */

                    $profile_stmt = $pdo->prepare("
                        INSERT INTO staff_profiles (
                            user_id,
                            employee_number,
                            position,
                            department,
                            contact_number,
                            date_hired
                        ) VALUES (
                            :user_id,
                            :employee_number,
                            :position,
                            :department,
                            :contact_number,
                            :date_hired
                        )
                    ");

                    $profile_stmt->execute([
                        ':user_id' => $new_user_id,
                        ':employee_number' => $employee_number,
                        ':position' => $position,
                        ':department' => $department !== '' ? $department : null,
                        ':contact_number' => $contact_number !== '' ? $contact_number : null,
                        ':date_hired' => $date_hired !== '' ? $date_hired : null
                    ]);

                    $pdo->commit();

                    logAudit(
                        $pdo,
                        'CREATE STAFF ACCOUNT',
                        'Staff Management',
                        "Created staff account for {$full_name} ({$employee_number}).",
                        (int)$_SESSION['user_id'],
                        'admin'
                    );

                    $message = "Staff account created successfully. Employee Number: {$employee_number}";
                    $message_type = "success";

                }

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $message = "Unable to create staff account. Please check your inputs.";
                $message_type = "danger";
                $generated_password = "";

            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | EDIT STAFF
    |--------------------------------------------------------------------------
    */
    elseif ($action === 'edit_staff') {

        $user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);

        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $position = trim($_POST['position'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $contact_number = trim($_POST['contact_number'] ?? '');
        $date_hired = trim($_POST['date_hired'] ?? '');

        if (
            !$user_id ||
            $full_name === '' ||
            $email === '' ||
            $username === '' ||
            $position === ''
        ) {

            $message = "Please complete the required fields.";
            $message_type = "danger";

        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

            $message = "Invalid email address.";
            $message_type = "danger";

        } else {

            try {

                $check = $pdo->prepare("
                    SELECT user_id
                    FROM users
                    WHERE (email = :email OR username = :username)
                      AND user_id != :user_id
                    LIMIT 1
                ");

                $check->execute([
                    ':email' => $email,
                    ':username' => $username,
                    ':user_id' => $user_id
                ]);

                if ($check->fetch()) {

                    $message = "Email or username is already being used.";
                    $message_type = "danger";

                } else {

                    $pdo->beginTransaction();

                    $user_stmt = $pdo->prepare("
                        UPDATE users
                        SET full_name = :full_name,
                            email = :email,
                            username = :username
                        WHERE user_id = :user_id
                          AND role = 'staff'
                    ");

                    $user_stmt->execute([
                        ':full_name' => $full_name,
                        ':email' => $email,
                        ':username' => $username,
                        ':user_id' => $user_id
                    ]);

                    $profile_stmt = $pdo->prepare("
                        UPDATE staff_profiles
                        SET position = :position,
                            department = :department,
                            contact_number = :contact_number,
                            date_hired = :date_hired
                        WHERE user_id = :user_id
                    ");

                    $profile_stmt->execute([
                        ':position' => $position,
                        ':department' => $department !== '' ? $department : null,
                        ':contact_number' => $contact_number !== '' ? $contact_number : null,
                        ':date_hired' => $date_hired !== '' ? $date_hired : null,
                        ':user_id' => $user_id
                    ]);

                    $pdo->commit();

                    logAudit(
                        $pdo,
                        'UPDATE STAFF ACCOUNT',
                        'Staff Management',
                        "Updated staff information for {$full_name}.",
                        (int)$_SESSION['user_id'],
                        'admin'
                    );

                    $message = "Staff information updated successfully.";
                    $message_type = "success";

                }

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $message = "Unable to update staff information.";
                $message_type = "danger";

            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CHANGE STAFF PASSWORD
    |--------------------------------------------------------------------------
    */
    elseif ($action === 'change_password') {

        $user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (!$user_id) {

            $message = "Invalid staff account.";
            $message_type = "danger";

        } elseif (strlen($new_password) < 8) {

            $message = "Password must contain at least 8 characters.";
            $message_type = "danger";

        } elseif ($new_password !== $confirm_password) {

            $message = "Passwords do not match.";
            $message_type = "danger";

        } else {

            try {

                // Confirm that the account belongs to staff
                $check = $pdo->prepare("
                    SELECT full_name
                    FROM users
                    WHERE user_id = :user_id
                      AND role = 'staff'
                    LIMIT 1
                ");

                $check->execute([
                    ':user_id' => $user_id
                ]);

                $staff_account = $check->fetch();

                if (!$staff_account) {

                    $message = "Staff account not found.";
                    $message_type = "danger";

                } else {

                    $hashed_password = password_hash(
                        $new_password,
                        PASSWORD_DEFAULT
                    );

                    $update = $pdo->prepare("
                        UPDATE users
                        SET password = :password
                        WHERE user_id = :user_id
                          AND role = 'staff'
                    ");

                    $update->execute([
                        ':password' => $hashed_password,
                        ':user_id' => $user_id
                    ]);

                    logAudit(
                        $pdo,
                        'CHANGE STAFF PASSWORD',
                        'Staff Management',
                        "Admin changed the password for staff account {$staff_account['full_name']}.",
                        (int)$_SESSION['user_id'],
                        'admin'
                    );

                    $message = "Staff password changed successfully.";
                    $message_type = "success";

                }

            } catch (Throwable $e) {

                $message = "Unable to change staff password.";
                $message_type = "danger";

            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | SUSPEND / REACTIVATE
    |--------------------------------------------------------------------------
    */
    elseif ($action === 'toggle_status') {

        $user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
        $new_status = $_POST['new_status'] ?? '';

        if (
            !$user_id ||
            !in_array($new_status, ['approved', 'suspended'], true)
        ) {

            $message = "Invalid staff account action.";
            $message_type = "danger";

        } else {

            try {

                $stmt = $pdo->prepare("
                    UPDATE users
                    SET account_status = :status
                    WHERE user_id = :user_id
                      AND role = 'staff'
                ");

                $stmt->execute([
                    ':status' => $new_status,
                    ':user_id' => $user_id
                ]);

                if ($stmt->rowCount() > 0) {

                    $message = $new_status === 'suspended'
                        ? "Staff account suspended."
                        : "Staff account reactivated.";

                    $message_type = "success";

                    logAudit(
                        $pdo,
                        $new_status === 'suspended'
                            ? 'SUSPEND STAFF ACCOUNT'
                            : 'REACTIVATE STAFF ACCOUNT',
                        'Staff Management',
                        "Staff account ID {$user_id} status changed to {$new_status}.",
                        (int)$_SESSION['user_id'],
                        'admin'
                    );

                } else {

                    $message = "No changes were made.";

                }

            } catch (Throwable $e) {

                $message = "Unable to update staff account status.";
                $message_type = "danger";

            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| SEARCH STAFF
|--------------------------------------------------------------------------
*/

$search = trim($_GET['search'] ?? '');

$sql = "
    SELECT
        u.user_id,
        u.full_name,
        u.email,
        u.username,
        u.account_status,
        sp.staff_id,
        sp.employee_number,
        sp.position,
        sp.department,
        sp.contact_number,
        sp.date_hired
    FROM users u
    INNER JOIN staff_profiles sp
        ON u.user_id = sp.user_id
    WHERE u.role = 'staff'
";

$params = [];

if ($search !== '') {

    $sql .= "
        AND (
            u.full_name LIKE :search
            OR u.email LIKE :search
            OR u.username LIKE :search
            OR sp.employee_number LIKE :search
            OR sp.position LIKE :search
            OR sp.department LIKE :search
        )
    ";

    $params[':search'] = '%' . $search . '%';
}

$sql .= " ORDER BY u.full_name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$staff_members = $stmt->fetchAll();

/*
|--------------------------------------------------------------------------
| COUNTS
|--------------------------------------------------------------------------
*/

$total_staff = count($staff_members);
$active_staff = 0;
$suspended_staff = 0;

foreach ($staff_members as $staff) {

    if ($staff['account_status'] === 'approved') {
        $active_staff++;
    } elseif ($staff['account_status'] === 'suspended') {
        $suspended_staff++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Staff Management | Barangay Putatan</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <link rel="stylesheet" href="../assets/css/style.css">

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

            <span class="fw-bold">Barangay Putatan | Admin</span>

        </a>

        <div class="d-flex align-items-center gap-3">

            <span class="text-white">
                <?= staff_admin_escape($_SESSION['full_name'] ?? 'Admin') ?>
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
                Administration
            </small>

            <nav class="nav flex-column gap-2 mt-3">

                <a class="nav-link admin-nav-link" href="dashboard.php">
                    <i class="bi bi-grid me-2"></i>Dashboard
                </a>

                <a class="nav-link admin-nav-link" href="residents.php">
                    <i class="bi bi-people me-2"></i>Resident Approvals
                </a>

                <a class="nav-link admin-nav-link"
                   href="resident-directory.php">
                    <i class="bi bi-person-vcard me-2"></i>Resident Directory
                </a>

                <a class="nav-link admin-nav-link" href="users.php">
                    <i class="bi bi-person-gear me-2"></i>User Management
                </a>

                <a class="nav-link admin-nav-link active" href="staff.php">
                    <i class="bi bi-person-badge me-2"></i>Staff Management
                </a>

                <a class="nav-link admin-nav-link" href="service-types.php">
                    <i class="bi bi-list-check me-2"></i>Service Types
                </a>

                <a class="nav-link admin-nav-link" href="service-requests.php">
                    <i class="bi bi-card-checklist me-2"></i>Service Requests
                </a>

                <a class="nav-link admin-nav-link" href="announcements.php">
                    <i class="bi bi-megaphone me-2"></i>Announcements
                </a>

                <a class="nav-link admin-nav-link" href="feedback.php">
                    <i class="bi bi-chat-left-text me-2"></i>Complaints & Feedback
                </a>

                <a class="nav-link admin-nav-link" href="analytics.php">
                    <i class="bi bi-bar-chart me-2"></i>Feedback Analytics
                </a>

                <a class="nav-link admin-nav-link" href="reports.php">
                    <i class="bi bi-file-earmark-bar-graph me-2"></i>Reports
                </a>

                <a href="audit-trail.php" class="nav-link admin-nav-link">
                    <i class="bi bi-shield-check me-2"></i>Audit Trail
                </a>

                <a class="nav-link admin-nav-link" href="settings.php">
                    <i class="bi bi-gear me-2"></i>Settings
                </a>

            </nav>

        </aside>

        <!-- MAIN CONTENT -->

        <main class="col-md-9 col-lg-10 px-4 py-4">

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">

                <div>

                    <h2 class="fw-bold text-primary-custom mb-1">
                        Staff Management
                    </h2>

                    <p class="text-muted mb-0">
                        Create, manage, and monitor Barangay Staff accounts.
                    </p>

                </div>

                <button type="button"
                        class="btn btn-primary"
                        data-bs-toggle="modal"
                        data-bs-target="#addStaffModal">

                    <i class="bi bi-person-plus me-1"></i>
                    Add Staff

                </button>

            </div>

            <!-- MESSAGE -->

            <?php if ($message !== ""): ?>

                <div class="alert alert-<?= staff_admin_escape($message_type) ?> alert-dismissible fade show">

                    <?= staff_admin_escape($message) ?>

                    <button type="button"
                            class="btn-close"
                            data-bs-dismiss="alert">
                    </button>

                </div>

            <?php endif; ?>

            <!-- STATISTICS -->

            <div class="row g-3 mb-4">

                <div class="col-md-4">

                    <div class="card border-0 shadow-sm h-100">

                        <div class="card-body">

                            <div class="text-muted small">
                                Total Staff
                            </div>

                            <h3 class="fw-bold text-primary-custom">
                                <?= number_format($total_staff) ?>
                            </h3>

                        </div>

                    </div>

                </div>

                <div class="col-md-4">

                    <div class="card border-0 shadow-sm h-100">

                        <div class="card-body">

                            <div class="text-muted small">
                                Active Staff
                            </div>

                            <h3 class="fw-bold text-success">
                                <?= number_format($active_staff) ?>
                            </h3>

                        </div>

                    </div>

                </div>

                <div class="col-md-4">

                    <div class="card border-0 shadow-sm h-100">

                        <div class="card-body">

                            <div class="text-muted small">
                                Suspended Staff
                            </div>

                            <h3 class="fw-bold text-danger">
                                <?= number_format($suspended_staff) ?>
                            </h3>

                        </div>

                    </div>

                </div>

            </div>

            <!-- SEARCH -->

            <div class="card border-0 shadow-sm mb-4">

                <div class="card-body">

                    <form method="GET" class="row g-2">

                        <div class="col-md-10">

                            <input type="text"
                                   name="search"
                                   class="form-control"
                                   placeholder="Search name, email, username, employee number, position..."
                                   value="<?= staff_admin_escape($search) ?>">

                        </div>

                        <div class="col-md-2">

                            <button type="submit"
                                    class="btn btn-primary w-100">

                                <i class="bi bi-search me-1"></i>
                                Search

                            </button>

                        </div>

                    </form>

                </div>

            </div>

            <!-- STAFF TABLE -->

            <div class="card border-0 shadow-sm">

                <div class="card-header bg-white py-3">

                    <h5 class="mb-0 fw-bold text-primary-custom">
                        Staff Accounts
                    </h5>

                </div>

                <div class="card-body">

                    <?php if (empty($staff_members)): ?>

                        <div class="alert alert-light mb-0">
                            No staff accounts found.
                        </div>

                    <?php else: ?>

                        <div class="table-responsive">

                            <table class="table table-hover align-middle">

                                <thead>

                                    <tr>
                                        <th>Employee No.</th>
                                        <th>Name</th>
                                        <th>Position</th>
                                        <th>Department</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>

                                </thead>

                                <tbody>

                                    <?php foreach ($staff_members as $staff): ?>

                                        <tr>

                                            <td>
                                                <span class="fw-semibold">
                                                    <?= staff_admin_escape($staff['employee_number']) ?>
                                                </span>
                                            </td>

                                            <td>

                                                <div class="fw-semibold">
                                                    <?= staff_admin_escape($staff['full_name']) ?>
                                                </div>

                                                <small class="text-muted">
                                                    <?= staff_admin_escape($staff['email']) ?>
                                                </small>

                                            </td>

                                            <td>
                                                <?= staff_admin_escape($staff['position']) ?>
                                            </td>

                                            <td>
                                                <?= staff_admin_escape($staff['department'] ?? '—') ?>
                                            </td>

                                            <td>

                                                <?php if ($staff['account_status'] === 'approved'): ?>

                                                    <span class="badge bg-success">
                                                        Active
                                                    </span>

                                                <?php else: ?>

                                                    <span class="badge bg-danger">
                                                        Suspended
                                                    </span>

                                                <?php endif; ?>

                                            </td>

                                            <td>

                                                <div class="d-flex gap-2 flex-wrap">

                                                    <!-- EDIT -->

                                                    <button type="button"
                                                            class="btn btn-outline-primary btn-sm"
                                                            title="Edit Staff"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#editStaffModal<?= (int)$staff['user_id'] ?>">

                                                        <i class="bi bi-pencil"></i>

                                                    </button>

                                                    <!-- CHANGE PASSWORD -->

                                                    <button type="button"
                                                            class="btn btn-outline-warning btn-sm"
                                                            title="Change Password"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#passwordModal<?= (int)$staff['user_id'] ?>">

                                                        <i class="bi bi-key"></i>

                                                    </button>

                                                    <!-- STATUS -->

                                                    <button type="button"
                                                            class="btn btn-outline-<?= $staff['account_status'] === 'approved' ? 'danger' : 'success' ?> btn-sm"
                                                            title="<?= $staff['account_status'] === 'approved' ? 'Suspend' : 'Reactivate' ?>"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#statusModal<?= (int)$staff['user_id'] ?>">

                                                        <i class="bi bi-<?= $staff['account_status'] === 'approved' ? 'person-x' : 'person-check' ?>"></i>

                                                    </button>

                                                </div>

                                            </td>

                                        </tr>

                                        <!-- EDIT STAFF MODAL -->

                                        <div class="modal fade"
                                             id="editStaffModal<?= (int)$staff['user_id'] ?>"
                                             tabindex="-1">

                                            <div class="modal-dialog modal-lg modal-dialog-centered">

                                                <div class="modal-content">

                                                    <form method="POST">

                                                        <div class="modal-header">

                                                            <h5 class="modal-title fw-bold">
                                                                Edit Staff Information
                                                            </h5>

                                                            <button type="button"
                                                                    class="btn-close"
                                                                    data-bs-dismiss="modal">
                                                            </button>

                                                        </div>

                                                        <div class="modal-body">

                                                            <input type="hidden"
                                                                   name="action"
                                                                   value="edit_staff">

                                                            <input type="hidden"
                                                                   name="user_id"
                                                                   value="<?= (int)$staff['user_id'] ?>">

                                                            <div class="row g-3">

                                                                <div class="col-md-6">

                                                                    <label class="form-label">
                                                                        Full Name *
                                                                    </label>

                                                                    <input type="text"
                                                                           name="full_name"
                                                                           class="form-control"
                                                                           required
                                                                           value="<?= staff_admin_escape($staff['full_name']) ?>">

                                                                </div>

                                                                <div class="col-md-6">

                                                                    <label class="form-label">
                                                                        Email *
                                                                    </label>

                                                                    <input type="email"
                                                                           name="email"
                                                                           class="form-control"
                                                                           required
                                                                           value="<?= staff_admin_escape($staff['email']) ?>">

                                                                </div>

                                                                <div class="col-md-6">

                                                                    <label class="form-label">
                                                                        Username *
                                                                    </label>

                                                                    <input type="text"
                                                                           name="username"
                                                                           class="form-control"
                                                                           required
                                                                           value="<?= staff_admin_escape($staff['username']) ?>">

                                                                </div>

                                                                <div class="col-md-6">

                                                                    <label class="form-label">
                                                                        Employee Number
                                                                    </label>

                                                                    <input type="text"
                                                                           class="form-control"
                                                                           value="<?= staff_admin_escape($staff['employee_number']) ?>"
                                                                           readonly>

                                                                    <small class="text-muted">
                                                                        Automatically generated and cannot be changed.
                                                                    </small>

                                                                </div>

                                                                <div class="col-md-6">

                                                                    <label class="form-label">
                                                                        Position *
                                                                    </label>

                                                                    <input type="text"
                                                                           name="position"
                                                                           class="form-control"
                                                                           required
                                                                           value="<?= staff_admin_escape($staff['position']) ?>">

                                                                </div>

                                                                <div class="col-md-6">

                                                                    <label class="form-label">
                                                                        Department
                                                                    </label>

                                                                    <input type="text"
                                                                           name="department"
                                                                           class="form-control"
                                                                           value="<?= staff_admin_escape($staff['department'] ?? '') ?>">

                                                                </div>

                                                                <div class="col-md-6">

                                                                    <label class="form-label">
                                                                        Contact Number
                                                                    </label>

                                                                    <input type="text"
                                                                           name="contact_number"
                                                                           class="form-control"
                                                                           value="<?= staff_admin_escape($staff['contact_number'] ?? '') ?>">

                                                                </div>

                                                                <div class="col-md-6">

                                                                    <label class="form-label">
                                                                        Date Hired
                                                                    </label>

                                                                    <input type="date"
                                                                           name="date_hired"
                                                                           class="form-control"
                                                                           value="<?= staff_admin_escape($staff['date_hired'] ?? '') ?>">

                                                                </div>

                                                            </div>

                                                        </div>

                                                        <div class="modal-footer">

                                                            <button type="button"
                                                                    class="btn btn-light border"
                                                                    data-bs-dismiss="modal">
                                                                Cancel
                                                            </button>

                                                            <button type="submit"
                                                                    class="btn btn-primary">
                                                                Save Changes
                                                            </button>

                                                        </div>

                                                    </form>

                                                </div>

                                            </div>

                                        </div>

                                        <!-- CHANGE PASSWORD MODAL -->

                                        <div class="modal fade"
                                             id="passwordModal<?= (int)$staff['user_id'] ?>"
                                             tabindex="-1">

                                            <div class="modal-dialog modal-dialog-centered">

                                                <div class="modal-content">

                                                    <form method="POST">

                                                        <div class="modal-header">

                                                            <h5 class="modal-title fw-bold">

                                                                <i class="bi bi-key me-2"></i>
                                                                Change Staff Password

                                                            </h5>

                                                            <button type="button"
                                                                    class="btn-close"
                                                                    data-bs-dismiss="modal">
                                                            </button>

                                                        </div>

                                                        <div class="modal-body">

                                                            <input type="hidden"
                                                                   name="action"
                                                                   value="change_password">

                                                            <input type="hidden"
                                                                   name="user_id"
                                                                   value="<?= (int)$staff['user_id'] ?>">

                                                            <div class="mb-3">

                                                                <label class="form-label fw-semibold">
                                                                    Staff Member
                                                                </label>

                                                                <input type="text"
                                                                       class="form-control"
                                                                       value="<?= staff_admin_escape($staff['full_name']) ?>"
                                                                       readonly>

                                                            </div>

                                                            <div class="mb-3">

                                                                <label class="form-label">
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

                                                                <label class="form-label">
                                                                    Confirm New Password
                                                                </label>

                                                                <input type="password"
                                                                       name="confirm_password"
                                                                       class="form-control"
                                                                       minlength="8"
                                                                       autocomplete="new-password"
                                                                       required>

                                                            </div>

                                                            <div class="alert alert-warning mb-0">

                                                                <i class="bi bi-exclamation-triangle me-2"></i>

                                                                Changing this password will replace the staff member's current password.

                                                            </div>

                                                        </div>

                                                        <div class="modal-footer">

                                                            <button type="button"
                                                                    class="btn btn-light border"
                                                                    data-bs-dismiss="modal">
                                                                Cancel
                                                            </button>

                                                            <button type="submit"
                                                                    class="btn btn-warning">

                                                                <i class="bi bi-key me-1"></i>
                                                                Change Password

                                                            </button>

                                                        </div>

                                                    </form>

                                                </div>

                                            </div>

                                        </div>

                                        <!-- STATUS MODAL -->

                                        <div class="modal fade"
                                             id="statusModal<?= (int)$staff['user_id'] ?>"
                                             tabindex="-1">

                                            <div class="modal-dialog modal-dialog-centered">

                                                <div class="modal-content">

                                                    <form method="POST">

                                                        <div class="modal-header">

                                                            <h5 class="modal-title fw-bold">

                                                                <?= $staff['account_status'] === 'approved'
                                                                    ? 'Suspend Staff Account'
                                                                    : 'Reactivate Staff Account' ?>

                                                            </h5>

                                                            <button type="button"
                                                                    class="btn-close"
                                                                    data-bs-dismiss="modal">
                                                            </button>

                                                        </div>

                                                        <div class="modal-body">

                                                            <p>

                                                                <?= $staff['account_status'] === 'approved'
                                                                    ? 'Are you sure you want to suspend this staff account?'
                                                                    : 'Are you sure you want to reactivate this staff account?' ?>

                                                            </p>

                                                            <h6 class="fw-bold">
                                                                <?= staff_admin_escape($staff['full_name']) ?>
                                                            </h6>

                                                            <input type="hidden"
                                                                   name="action"
                                                                   value="toggle_status">

                                                            <input type="hidden"
                                                                   name="user_id"
                                                                   value="<?= (int)$staff['user_id'] ?>">

                                                            <input type="hidden"
                                                                   name="new_status"
                                                                   value="<?= $staff['account_status'] === 'approved' ? 'suspended' : 'approved' ?>">

                                                        </div>

                                                        <div class="modal-footer">

                                                            <button type="button"
                                                                    class="btn btn-light border"
                                                                    data-bs-dismiss="modal">
                                                                Cancel
                                                            </button>

                                                            <button type="submit"
                                                                    class="btn btn-<?= $staff['account_status'] === 'approved' ? 'danger' : 'success' ?>">

                                                                <?= $staff['account_status'] === 'approved'
                                                                    ? 'Confirm Suspension'
                                                                    : 'Confirm Reactivation' ?>

                                                            </button>

                                                        </div>

                                                    </form>

                                                </div>

                                            </div>

                                        </div>

                                    <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                    <?php endif; ?>

                </div>

            </div>

        </main>

    </div>

</div>

<!-- ADD STAFF MODAL -->

<div class="modal fade"
     id="addStaffModal"
     tabindex="-1">

    <div class="modal-dialog modal-lg modal-dialog-centered">

        <div class="modal-content">

            <form method="POST">

                <div class="modal-header">

                    <h5 class="modal-title fw-bold">

                        <i class="bi bi-person-plus me-2"></i>
                        Add New Staff

                    </h5>

                    <button type="button"
                            class="btn-close"
                            data-bs-dismiss="modal">
                    </button>

                </div>

                <div class="modal-body">

                    <input type="hidden"
                           name="action"
                           value="add_staff">

                    <div class="row g-3">

                        <div class="col-md-6">

                            <label class="form-label">
                                Full Name *
                            </label>

                            <input type="text"
                                   name="full_name"
                                   class="form-control"
                                   required>

                        </div>

                        <div class="col-md-6">

                            <label class="form-label">
                                Email *
                            </label>

                            <input type="email"
                                   name="email"
                                   class="form-control"
                                   required>

                        </div>

                        <div class="col-md-6">

                            <label class="form-label">
                                Username *
                            </label>

                            <input type="text"
                                   name="username"
                                   class="form-control"
                                   required>

                        </div>

                        <!-- AUTOMATIC EMPLOYEE NUMBER -->

                        <div class="col-md-6">

                            <label class="form-label">
                                Employee Number
                            </label>

                            <input type="text"
                                   class="form-control"
                                   value="Automatically generated"
                                   readonly>

                            <small class="text-muted">
                                Generated automatically after account creation.
                            </small>

                        </div>

                        <div class="col-md-6">

                            <label class="form-label">
                                Position *
                            </label>

                            <input type="text"
                                   name="position"
                                   class="form-control"
                                   placeholder="e.g. Barangay Secretary"
                                   required>

                        </div>

                        <div class="col-md-6">

                            <label class="form-label">
                                Department
                            </label>

                            <input type="text"
                                   name="department"
                                   class="form-control"
                                   placeholder="e.g. Administrative Office">

                        </div>

                        <div class="col-md-6">

                            <label class="form-label">
                                Contact Number
                            </label>

                            <input type="text"
                                   name="contact_number"
                                   class="form-control">

                        </div>

                        <div class="col-md-6">

                            <label class="form-label">
                                Date Hired
                            </label>

                            <input type="date"
                                   name="date_hired"
                                   class="form-control">

                        </div>

                    </div>

                    <div class="alert alert-info mt-3 mb-0">

                        <i class="bi bi-info-circle me-2"></i>

                        A temporary password will be generated automatically after the staff account is created.

                    </div>

                </div>

                <div class="modal-footer">

                    <button type="button"
                            class="btn btn-light border"
                            data-bs-dismiss="modal">
                        Cancel
                    </button>

                    <button type="submit"
                            class="btn btn-primary">

                        <i class="bi bi-person-plus me-1"></i>
                        Create Staff Account

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>