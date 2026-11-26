
<?php
require_once '../includes/staff_auth.php';

$message = '';
$message_type = 'success';

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Helper
function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// HANDLE POST ACTIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submitted_token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrf_token, $submitted_token)) {

        $message = 'Invalid request. Please refresh the page and try again.';
        $message_type = 'danger';

    } else {

        $action = $_POST['action'] ?? '';
        $user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);

        try {

            if (!$user_id) {
                throw new Exception('Invalid resident account.');
            }

            // APPROVE RESIDENT
            if ($action === 'approve') {

                $check = $pdo->prepare("
                    SELECT user_id, full_name, proof_of_residency
                    FROM users
                    WHERE user_id = ?
                      AND role = 'resident'
                      AND account_status = 'pending'
                ");

                $check->execute([$user_id]);
                $resident = $check->fetch(PDO::FETCH_ASSOC);

                if (!$resident) {
                    throw new Exception('Resident not found or already processed.');
                }

                if (empty($resident['proof_of_residency'])) {
                    throw new Exception('Cannot approve the resident. Proof of residency is missing.');
                }

                $stmt = $pdo->prepare("
                    UPDATE users
                    SET account_status = 'approved',
                        approved_by = ?,
                        approved_at = NOW(),
                        rejection_reason = NULL
                    WHERE user_id = ?
                      AND role = 'resident'
                      AND account_status = 'pending'
                ");

                $stmt->execute([
                    $_SESSION['user_id'],
                    $user_id
                ]);

                if ($stmt->rowCount() > 0) {

                    $message = 'Resident account approved successfully.';

                } else {

                    throw new Exception('Unable to approve the resident account.');
                }

            }

            // REJECT RESIDENT
            elseif ($action === 'reject') {

                $reason = trim($_POST['rejection_reason'] ?? '');

                if ($reason === '') {
                    throw new Exception('Please provide a rejection reason.');
                }

                $check = $pdo->prepare("
                    SELECT user_id
                    FROM users
                    WHERE user_id = ?
                      AND role = 'resident'
                      AND account_status = 'pending'
                ");

                $check->execute([$user_id]);

                if (!$check->fetch()) {
                    throw new Exception('Resident not found or already processed.');
                }

                $stmt = $pdo->prepare("
                    UPDATE users
                    SET account_status = 'rejected',
                        rejection_reason = ?
                    WHERE user_id = ?
                      AND role = 'resident'
                      AND account_status = 'pending'
                ");

                $stmt->execute([
                    $reason,
                    $user_id
                ]);

                if ($stmt->rowCount() > 0) {

                    $message = 'Resident registration rejected successfully.';

                } else {

                    throw new Exception('Unable to reject the registration.');
                }

            }

            // EDIT RESIDENT
            elseif ($action === 'edit') {

                $full_name = trim($_POST['full_name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $username = trim($_POST['username'] ?? '');

                if ($full_name === '' || $email === '' || $username === '') {
                    throw new Exception('All resident information fields are required.');
                }

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('Please enter a valid email address.');
                }

                // Verify resident exists
                $check = $pdo->prepare("
                    SELECT user_id
                    FROM users
                    WHERE user_id = ?
                      AND role = 'resident'
                ");

                $check->execute([$user_id]);

                if (!$check->fetch()) {
                    throw new Exception('Resident account not found.');
                }

                // Check duplicate email
                $duplicate_email = $pdo->prepare("
                    SELECT user_id
                    FROM users
                    WHERE email = ?
                      AND user_id <> ?
                    LIMIT 1
                ");

                $duplicate_email->execute([
                    $email,
                    $user_id
                ]);

                if ($duplicate_email->fetch()) {
                    throw new Exception('Email address is already being used.');
                }

                // Check duplicate username
                $duplicate_username = $pdo->prepare("
                    SELECT user_id
                    FROM users
                    WHERE username = ?
                      AND user_id <> ?
                    LIMIT 1
                ");

                $duplicate_username->execute([
                    $username,
                    $user_id
                ]);

                if ($duplicate_username->fetch()) {
                    throw new Exception('Username is already being used.');
                }

                $stmt = $pdo->prepare("
                    UPDATE users
                    SET full_name = ?,
                        email = ?,
                        username = ?
                    WHERE user_id = ?
                      AND role = 'resident'
                ");

                $stmt->execute([
                    $full_name,
                    $email,
                    $username,
                    $user_id
                ]);

                $message = 'Resident information updated successfully.';

            }

            else {
                throw new Exception('Invalid action.');
            }

        } catch (Exception $ex) {

            $message = $ex->getMessage();
            $message_type = 'danger';

        } catch (PDOException $ex) {

            $message = 'A database error occurred while processing the request.';
            $message_type = 'danger';
        }
    }
}

// FETCH PENDING RESIDENTS
try {

    $pending_stmt = $pdo->query("
        SELECT
            user_id,
            full_name,
            email,
            username,
            proof_of_residency,
            created_at
        FROM users
        WHERE role = 'resident'
          AND account_status = 'pending'
        ORDER BY created_at ASC
    ");

    $pending_residents = $pending_stmt->fetchAll(PDO::FETCH_ASSOC);

    // FETCH APPROVED RESIDENTS
    $approved_stmt = $pdo->query("
        SELECT
            user_id,
            full_name,
            email,
            username,
            approved_at
        FROM users
        WHERE role = 'resident'
          AND account_status = 'approved'
        ORDER BY full_name ASC
    ");

    $approved_residents = $approved_stmt->fetchAll(PDO::FETCH_ASSOC);

    // FETCH REJECTED RESIDENTS
    $rejected_stmt = $pdo->query("
        SELECT
            user_id,
            full_name,
            email,
            username,
            rejection_reason,
            created_at
        FROM users
        WHERE role = 'resident'
          AND account_status = 'rejected'
        ORDER BY created_at DESC
    ");

    $rejected_residents = $rejected_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $ex) {

    $pending_residents = [];
    $approved_residents = [];
    $rejected_residents = [];

    $message = 'Unable to retrieve resident records.';
    $message_type = 'danger';
}

$total_residents = count($approved_residents);
$total_pending = count($pending_residents);
$total_rejected = count($rejected_residents);
?>

<!DOCTYPE html>
<html lang="en">
<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Residents | Barangay Putatan Staff</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <link rel="stylesheet" href="../assets/css/style.css">

    <style>

        .resident-stat-card {
            border: 0;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .resident-stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
        }

        .resident-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #e8f0ff;
            color: #0d6efd;
            font-weight: bold;
            flex-shrink: 0;
        }

        .resident-table th {
            white-space: nowrap;
        }

        .resident-empty-state {
            padding: 40px 20px;
            text-align: center;
        }

        .resident-empty-state i {
            font-size: 3rem;
            color: #adb5bd;
        }

    </style>

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
                <?= e($_SESSION['full_name'] ?? 'Staff') ?>
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
                    class="nav-link admin-nav-link active"
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

                <a class="nav-link admin-nav-link"
                    href="profile.php">
                    <i class="bi bi-person-circle me-2"></i>My Profile
                </a>

            </nav>

        </aside>

        <!-- MAIN CONTENT -->
        <main class="col-md-9 col-lg-10 px-4 py-4">

            <!-- HEADER -->
            <div class="mb-4">

                <h2 class="fw-bold text-primary-custom">
                    Resident Management
                </h2>

                <p class="text-muted">
                    Review registrations, approve or reject applications,
                    and update resident account information.
                </p>

            </div>

            <!-- MESSAGE -->
            <?php if ($message !== ''): ?>

                <div class="alert alert-<?= e($message_type) ?> alert-dismissible fade show">

                    <?= e($message) ?>

                    <button type="button"
                            class="btn-close"
                            data-bs-dismiss="alert">
                    </button>

                </div>

            <?php endif; ?>

            <!-- STATISTICS -->
            <div class="row g-3 mb-4">

                <div class="col-md-4">

                    <div class="card resident-stat-card">

                        <div class="card-body">

                            <div class="d-flex align-items-center justify-content-between">

                                <div>

                                    <p class="text-muted mb-1">
                                        Approved Residents
                                    </p>

                                    <h3 class="fw-bold mb-0">
                                        <?= number_format($total_residents) ?>
                                    </h3>

                                </div>

                                <div class="resident-stat-icon bg-primary-subtle text-primary">

                                    <i class="bi bi-people-fill"></i>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

                <div class="col-md-4">

                    <div class="card resident-stat-card">

                        <div class="card-body">

                            <div class="d-flex align-items-center justify-content-between">

                                <div>

                                    <p class="text-muted mb-1">
                                        Pending Registrations
                                    </p>

                                    <h3 class="fw-bold mb-0">
                                        <?= number_format($total_pending) ?>
                                    </h3>

                                </div>

                                <div class="resident-stat-icon bg-warning-subtle text-warning">

                                    <i class="bi bi-person-clock"></i>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

                <div class="col-md-4">

                    <div class="card resident-stat-card">

                        <div class="card-body">

                            <div class="d-flex align-items-center justify-content-between">

                                <div>

                                    <p class="text-muted mb-1">
                                        Rejected Registrations
                                    </p>

                                    <h3 class="fw-bold mb-0">
                                        <?= number_format($total_rejected) ?>
                                    </h3>

                                </div>

                                <div class="resident-stat-icon bg-danger-subtle text-danger">

                                    <i class="bi bi-person-x"></i>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

            <!-- PENDING REGISTRATIONS -->
            <div class="card border-0 shadow-sm mb-4">

                <div class="card-header bg-white py-3">

                    <h5 class="fw-bold text-primary-custom mb-0">

                        <i class="bi bi-person-lines-fill me-2"></i>
                        Pending Registrations

                        <span class="badge bg-warning text-dark ms-2">
                            <?= count($pending_residents) ?>
                        </span>

                    </h5>

                </div>

                <div class="card-body">

                    <?php if (empty($pending_residents)): ?>

                        <div class="resident-empty-state">

                            <i class="bi bi-person-check"></i>

                            <h5 class="mt-3">
                                No Pending Registrations
                            </h5>

                            <p class="text-muted mb-0">
                                There are currently no registrations awaiting approval.
                            </p>

                        </div>

                    <?php else: ?>

                        <div class="table-responsive">

                            <table class="table table-hover align-middle resident-table">

                                <thead class="table-light">

                                    <tr>

                                        <th>Resident</th>
                                        <th>Email</th>
                                        <th>Username</th>
                                        <th>Proof of Residency</th>
                                        <th>Registered</th>
                                        <th>Actions</th>

                                    </tr>

                                </thead>

                                <tbody>

                                    <?php foreach ($pending_residents as $resident): ?>

                                        <tr>

                                            <td>

                                                <div class="d-flex align-items-center gap-3">

                                                    <div class="resident-avatar">

                                                        <?= e(strtoupper(substr(trim($resident['full_name']), 0, 1))) ?>

                                                    </div>

                                                    <div>

                                                        <div class="fw-semibold">
                                                            <?= e($resident['full_name']) ?>
                                                        </div>

                                                        <small class="text-muted">
                                                            ID: <?= (int)$resident['user_id'] ?>
                                                        </small>

                                                    </div>

                                                </div>

                                            </td>

                                            <td>
                                                <?= e($resident['email']) ?>
                                            </td>

                                            <td>
                                                <?= e($resident['username']) ?>
                                            </td>

                                            <td>

                                                <?php if (!empty($resident['proof_of_residency'])): ?>

                                                    <span class="badge bg-success-subtle text-success border">
                                                        <i class="bi bi-file-earmark-check me-1"></i>
                                                        Uploaded
                                                    </span>

                                                <?php else: ?>

                                                    <span class="badge bg-danger">
                                                        Missing Document
                                                    </span>

                                                <?php endif; ?>

                                            </td>

                                            <td>

                                                <?= e(
                                                    date(
                                                        'M d, Y',
                                                        strtotime($resident['created_at'])
                                                    )
                                                ) ?>

                                            </td>

                                            <td>

                                                <div class="d-flex flex-wrap gap-2">

                                                    <!-- EDIT -->
                                                    <button type="button"
                                                            class="btn btn-outline-primary btn-sm"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#editModal<?= (int)$resident['user_id'] ?>">

                                                        <i class="bi bi-pencil-square"></i>

                                                    </button>

                                                    <!-- APPROVE -->
                                                    <button type="button"
                                                            class="btn btn-success btn-sm"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#approveModal<?= (int)$resident['user_id'] ?>"
                                                            <?= empty($resident['proof_of_residency']) ? 'disabled' : '' ?>>

                                                        <i class="bi bi-check-lg"></i>
                                                        Approve

                                                    </button>

                                                    <!-- REJECT -->
                                                    <button type="button"
                                                            class="btn btn-danger btn-sm"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#rejectModal<?= (int)$resident['user_id'] ?>">

                                                        <i class="bi bi-x-lg"></i>
                                                        Reject

                                                    </button>

                                                </div>

                                            </td>

                                        </tr>

                                        <!-- EDIT MODAL -->
                                        <div class="modal fade"
                                             id="editModal<?= (int)$resident['user_id'] ?>"
                                             tabindex="-1">

                                            <div class="modal-dialog modal-dialog-centered">

                                                <div class="modal-content">

                                                    <form method="POST">

                                                        <input type="hidden"
                                                               name="csrf_token"
                                                               value="<?= e($csrf_token) ?>">

                                                        <input type="hidden"
                                                               name="action"
                                                               value="edit">

                                                        <input type="hidden"
                                                               name="user_id"
                                                               value="<?= (int)$resident['user_id'] ?>">

                                                        <div class="modal-header">

                                                            <h5 class="modal-title fw-bold">
                                                                Edit Resident Information
                                                            </h5>

                                                            <button type="button"
                                                                    class="btn-close"
                                                                    data-bs-dismiss="modal">
                                                            </button>

                                                        </div>

                                                        <div class="modal-body">

                                                            <div class="mb-3">

                                                                <label class="form-label fw-semibold">
                                                                    Full Name
                                                                </label>

                                                                <input type="text"
                                                                       name="full_name"
                                                                       class="form-control"
                                                                       value="<?= e($resident['full_name']) ?>"
                                                                       required>

                                                            </div>

                                                            <div class="mb-3">

                                                                <label class="form-label fw-semibold">
                                                                    Email
                                                                </label>

                                                                <input type="email"
                                                                       name="email"
                                                                       class="form-control"
                                                                       value="<?= e($resident['email']) ?>"
                                                                       required>

                                                            </div>

                                                            <div class="mb-3">

                                                                <label class="form-label fw-semibold">
                                                                    Username
                                                                </label>

                                                                <input type="text"
                                                                       name="username"
                                                                       class="form-control"
                                                                       value="<?= e($resident['username']) ?>"
                                                                       required>

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

                                                                <i class="bi bi-save me-1"></i>
                                                                Save Changes

                                                            </button>

                                                        </div>

                                                    </form>

                                                </div>

                                            </div>

                                        </div>

                                        <!-- APPROVE MODAL -->
                                        <div class="modal fade"
                                             id="approveModal<?= (int)$resident['user_id'] ?>"
                                             tabindex="-1">

                                            <div class="modal-dialog modal-dialog-centered">

                                                <div class="modal-content">

                                                    <form method="POST">

                                                        <input type="hidden"
                                                               name="csrf_token"
                                                               value="<?= e($csrf_token) ?>">

                                                        <input type="hidden"
                                                               name="action"
                                                               value="approve">

                                                        <input type="hidden"
                                                               name="user_id"
                                                               value="<?= (int)$resident['user_id'] ?>">

                                                        <div class="modal-header">

                                                            <h5 class="modal-title fw-bold">
                                                                Approve Resident
                                                            </h5>

                                                            <button type="button"
                                                                    class="btn-close"
                                                                    data-bs-dismiss="modal">
                                                            </button>

                                                        </div>

                                                        <div class="modal-body">

                                                            <div class="text-center mb-3">

                                                                <i class="bi bi-person-check text-success"
                                                                   style="font-size:3rem;"></i>

                                                            </div>

                                                            <p class="text-center">
                                                                Approve the registration of:
                                                            </p>

                                                            <h5 class="text-center fw-bold">
                                                                <?= e($resident['full_name']) ?>
                                                            </h5>

                                                            <p class="text-center text-muted small">

                                                                <?php if (!empty($resident['proof_of_residency'])): ?>

                                                                    Proof of residency has been uploaded.

                                                                <?php else: ?>

                                                                    <span class="text-danger">
                                                                        Missing proof of residency.
                                                                    </span>

                                                                <?php endif; ?>

                                                            </p>

                                                        </div>

                                                        <div class="modal-footer">

                                                            <button type="button"
                                                                    class="btn btn-light border"
                                                                    data-bs-dismiss="modal">
                                                                Cancel
                                                            </button>

                                                            <button type="submit"
                                                                    class="btn btn-success"
                                                                    <?= empty($resident['proof_of_residency']) ? 'disabled' : '' ?>>

                                                                <i class="bi bi-check-circle me-1"></i>
                                                                Confirm Approval

                                                            </button>

                                                        </div>

                                                    </form>

                                                </div>

                                            </div>

                                        </div>

                                        <!-- REJECT MODAL -->
                                        <div class="modal fade"
                                             id="rejectModal<?= (int)$resident['user_id'] ?>"
                                             tabindex="-1">

                                            <div class="modal-dialog modal-dialog-centered">

                                                <div class="modal-content">

                                                    <form method="POST">

                                                        <input type="hidden"
                                                               name="csrf_token"
                                                               value="<?= e($csrf_token) ?>">

                                                        <input type="hidden"
                                                               name="action"
                                                               value="reject">

                                                        <input type="hidden"
                                                               name="user_id"
                                                               value="<?= (int)$resident['user_id'] ?>">

                                                        <div class="modal-header">

                                                            <h5 class="modal-title fw-bold">
                                                                Reject Registration
                                                            </h5>

                                                            <button type="button"
                                                                    class="btn-close"
                                                                    data-bs-dismiss="modal">
                                                            </button>

                                                        </div>

                                                        <div class="modal-body">

                                                            <p>
                                                                Reject the registration of:
                                                            </p>

                                                            <h6 class="fw-bold">
                                                                <?= e($resident['full_name']) ?>
                                                            </h6>

                                                            <label class="form-label fw-semibold mt-3">
                                                                Rejection Reason
                                                            </label>

                                                            <textarea name="rejection_reason"
                                                                      class="form-control"
                                                                      rows="4"
                                                                      required
                                                                      placeholder="Provide the reason for rejecting this registration"></textarea>

                                                        </div>

                                                        <div class="modal-footer">

                                                            <button type="button"
                                                                    class="btn btn-light border"
                                                                    data-bs-dismiss="modal">
                                                                Cancel
                                                            </button>

                                                            <button type="submit"
                                                                    class="btn btn-danger">

                                                                <i class="bi bi-x-circle me-1"></i>
                                                                Confirm Rejection

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

            <!-- APPROVED RESIDENTS -->
            <div class="card border-0 shadow-sm mb-4">

                <div class="card-header bg-white py-3">

                    <h5 class="fw-bold text-primary-custom mb-0">

                        <i class="bi bi-person-check me-2"></i>
                        Approved Residents

                        <span class="badge bg-primary ms-2">
                            <?= count($approved_residents) ?>
                        </span>

                    </h5>

                </div>

                <div class="card-body">

                    <div class="row mb-3">

                        <div class="col-md-6 col-lg-5">

                            <div class="input-group">

                                <span class="input-group-text bg-white">
                                    <i class="bi bi-search"></i>
                                </span>

                                <input type="text"
                                       id="approvedResidentSearch"
                                       class="form-control"
                                       placeholder="Search name, email, or username...">

                            </div>

                        </div>

                    </div>

                    <?php if (empty($approved_residents)): ?>

                        <div class="resident-empty-state">

                            <i class="bi bi-people"></i>

                            <h5 class="mt-3">
                                No Approved Residents
                            </h5>

                        </div>

                    <?php else: ?>

                        <div class="table-responsive">

                            <table class="table table-hover align-middle resident-table"
                                   id="approvedResidentTable">

                                <thead class="table-light">

                                    <tr>

                                        <th>Resident</th>
                                        <th>Email</th>
                                        <th>Username</th>
                                        <th>Approval Date</th>
                                        <th>Status</th>
                                        <th>Action</th>

                                    </tr>

                                </thead>

                                <tbody>

                                    <?php foreach ($approved_residents as $resident): ?>

                                        <tr class="approved-resident-row">

                                            <td>

                                                <div class="d-flex align-items-center gap-3">

                                                    <div class="resident-avatar">

                                                        <?= e(strtoupper(substr(trim($resident['full_name']), 0, 1))) ?>

                                                    </div>

                                                    <div>

                                                        <div class="fw-semibold resident-name">

                                                            <?= e($resident['full_name']) ?>

                                                        </div>

                                                        <small class="text-muted">

                                                            ID: <?= (int)$resident['user_id'] ?>

                                                        </small>

                                                    </div>

                                                </div>

                                            </td>

                                            <td class="resident-email">
                                                <?= e($resident['email']) ?>
                                            </td>

                                            <td class="resident-username">
                                                <?= e($resident['username']) ?>
                                            </td>

                                            <td>

                                                <?php if (!empty($resident['approved_at'])): ?>

                                                    <?= e(date('M d, Y', strtotime($resident['approved_at']))) ?>

                                                <?php else: ?>

                                                    <span class="text-muted">
                                                        Not available
                                                    </span>

                                                <?php endif; ?>

                                            </td>

                                            <td>

                                                <span class="badge bg-success-subtle text-success border">
                                                    <i class="bi bi-check-circle me-1"></i>
                                                    Approved
                                                </span>

                                            </td>

                                            <td>

                                                <button type="button"
                                                        class="btn btn-outline-primary btn-sm"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#approvedEditModal<?= (int)$resident['user_id'] ?>">

                                                    <i class="bi bi-pencil-square me-1"></i>
                                                    Edit

                                                </button>

                                            </td>

                                        </tr>

                                        <!-- EDIT APPROVED RESIDENT -->
                                        <div class="modal fade"
                                             id="approvedEditModal<?= (int)$resident['user_id'] ?>"
                                             tabindex="-1">

                                            <div class="modal-dialog modal-dialog-centered">

                                                <div class="modal-content">

                                                    <form method="POST">

                                                        <input type="hidden"
                                                               name="csrf_token"
                                                               value="<?= e($csrf_token) ?>">

                                                        <input type="hidden"
                                                               name="action"
                                                               value="edit">

                                                        <input type="hidden"
                                                               name="user_id"
                                                               value="<?= (int)$resident['user_id'] ?>">

                                                        <div class="modal-header">

                                                            <h5 class="modal-title fw-bold">
                                                                Edit Resident Information
                                                            </h5>

                                                            <button type="button"
                                                                    class="btn-close"
                                                                    data-bs-dismiss="modal">
                                                            </button>

                                                        </div>

                                                        <div class="modal-body">

                                                            <div class="mb-3">

                                                                <label class="form-label fw-semibold">
                                                                    Full Name
                                                                </label>

                                                                <input type="text"
                                                                       name="full_name"
                                                                       class="form-control"
                                                                       value="<?= e($resident['full_name']) ?>"
                                                                       required>

                                                            </div>

                                                            <div class="mb-3">

                                                                <label class="form-label fw-semibold">
                                                                    Email
                                                                </label>

                                                                <input type="email"
                                                                       name="email"
                                                                       class="form-control"
                                                                       value="<?= e($resident['email']) ?>"
                                                                       required>

                                                            </div>

                                                            <div class="mb-3">

                                                                <label class="form-label fw-semibold">
                                                                    Username
                                                                </label>

                                                                <input type="text"
                                                                       name="username"
                                                                       class="form-control"
                                                                       value="<?= e($resident['username']) ?>"
                                                                       required>

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

                                                                <i class="bi bi-save me-1"></i>
                                                                Save Changes

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

                        <div id="approvedNoResults"
                             class="alert alert-light text-center d-none">

                            <i class="bi bi-search me-2"></i>
                            No residents found matching your search.

                        </div>

                    <?php endif; ?>

                </div>

            </div>

            <!-- REJECTED REGISTRATIONS -->
            <div class="card border-0 shadow-sm">

                <div class="card-header bg-white py-3">

                    <h5 class="fw-bold text-primary-custom mb-0">

                        <i class="bi bi-person-x me-2"></i>
                        Rejected Registrations

                        <span class="badge bg-danger ms-2">
                            <?= count($rejected_residents) ?>
                        </span>

                    </h5>

                </div>

                <div class="card-body">

                    <?php if (empty($rejected_residents)): ?>

                        <div class="resident-empty-state">

                            <i class="bi bi-person-x"></i>

                            <h5 class="mt-3">
                                No Rejected Registrations
                            </h5>

                        </div>

                    <?php else: ?>

                        <div class="table-responsive">

                            <table class="table table-hover align-middle resident-table">

                                <thead class="table-light">

                                    <tr>

                                        <th>Resident</th>
                                        <th>Email</th>
                                        <th>Username</th>
                                        <th>Rejection Reason</th>
                                        <th>Registration Date</th>

                                    </tr>

                                </thead>

                                <tbody>

                                    <?php foreach ($rejected_residents as $resident): ?>

                                        <tr>

                                            <td class="fw-semibold">
                                                <?= e($resident['full_name']) ?>
                                            </td>

                                            <td>
                                                <?= e($resident['email']) ?>
                                            </td>

                                            <td>
                                                <?= e($resident['username']) ?>
                                            </td>

                                            <td>

                                                <span class="text-danger">

                                                    <?= e($resident['rejection_reason'] ?? 'No reason recorded') ?>

                                                </span>

                                            </td>

                                            <td>

                                                <?= e(
                                                    date(
                                                        'M d, Y',
                                                        strtotime($resident['created_at'])
                                                    )
                                                ) ?>

                                            </td>

                                        </tr>

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

<!-- BOOTSTRAP -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- SEARCH -->
<script>

document.addEventListener('DOMContentLoaded', function () {

    const searchInput = document.getElementById('approvedResidentSearch');
    const rows = document.querySelectorAll('.approved-resident-row');
    const noResults = document.getElementById('approvedNoResults');

    if (!searchInput) {
        return;
    }

    searchInput.addEventListener('input', function () {

        const value = this.value.toLowerCase().trim();
        let visibleCount = 0;

        rows.forEach(function (row) {

            const name = row.querySelector('.resident-name')
                ?.textContent.toLowerCase() || '';

            const email = row.querySelector('.resident-email')
                ?.textContent.toLowerCase() || '';

            const username = row.querySelector('.resident-username')
                ?.textContent.toLowerCase() || '';

            const match =
                name.includes(value) ||
                email.includes(value) ||
                username.includes(value);

            row.style.display = match ? '' : 'none';

            if (match) {
                visibleCount++;
            }

        });

        if (noResults) {

            if (visibleCount === 0 && rows.length > 0) {
                noResults.classList.remove('d-none');
            } else {
                noResults.classList.add('d-none');
            }

        }

    });

});

</script>

</body>
</html>
