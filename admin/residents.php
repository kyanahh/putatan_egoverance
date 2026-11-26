<?php
require_once '../includes/admin_auth.php';
require_once '../includes/audit_helper.php';
require_once '../includes/notification_helper.php';

$message = "";
$message_type = "success";

// Handle approval and rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $action = $_POST['action'] ?? '';
    $rejection_reason = trim($_POST['rejection_reason'] ?? '');

    if (!$user_id) {

        $message = "Invalid resident account.";
        $message_type = "danger";

    } else {

        // Ensure the account is a pending resident
        $check = $pdo->prepare("
            SELECT user_id, full_name, username, proof_of_residency
            FROM users
            WHERE user_id = :user_id
              AND role = 'resident'
              AND account_status = 'pending'
        ");

        $check->execute([':user_id' => $user_id]);
        $pending_user = $check->fetch();

        if (!$pending_user) {

            $message = "Resident account not found or already processed.";
            $message_type = "danger";

        } else {

            if ($action === 'approve') {

                // Require proof of residency before approval
                if (empty($pending_user['proof_of_residency'])) {

                    $message = "Cannot approve this resident. Proof of residency is missing.";
                    $message_type = "danger";

                } else {

                    $stmt = $pdo->prepare("
                        UPDATE users
                        SET account_status = 'approved',
                            approved_by = :admin_id,
                            approved_at = NOW(),
                            rejection_reason = NULL
                        WHERE user_id = :user_id
                          AND role = 'resident'
                          AND account_status = 'pending'
                    ");

                    $stmt->execute([
                        ':admin_id' => $_SESSION['user_id'],
                        ':user_id' => $user_id
                    ]);

                    if ($stmt->rowCount() > 0) {

                        // CREATE APPROVAL NOTIFICATION
                        createNotification(
                            $pdo,
                            $user_id,
                            'Registration Approved',
                            'Your Barangay Putatan account has been approved. You may now log in and access the system.',
                            'account_approval',
                            $user_id
                        );

                        // RECORD APPROVAL IN AUDIT TRAIL
                        logAudit(
                            $pdo,
                            'APPROVE RESIDENT',
                            'Resident Approvals',
                            'Administrator approved resident registration: ' . $pending_user['full_name'] . ' (User ID: ' . $user_id . ')',
                            (int)$_SESSION['user_id'],
                            $_SESSION['role'] ?? 'admin'
                        );

                        $message = "Resident account approved successfully.";

                    } else {

                        $message = "Unable to approve account.";
                        $message_type = "danger";

                    }
                }

            } elseif ($action === 'reject') {

                if ($rejection_reason === '') {

                    $message = "Please provide a rejection reason.";
                    $message_type = "danger";

                } else {

                    $stmt = $pdo->prepare("
                        UPDATE users
                        SET account_status = 'rejected',
                            rejection_reason = :reason
                        WHERE user_id = :user_id
                          AND role = 'resident'
                          AND account_status = 'pending'
                    ");

                    $stmt->execute([
                        ':reason' => $rejection_reason,
                        ':user_id' => $user_id
                    ]);

                    if ($stmt->rowCount() > 0) {

                        // CREATE REJECTION NOTIFICATION
                        createNotification(
                            $pdo,
                            $user_id,
                            'Registration Rejected',
                            'Your Barangay Putatan registration has been rejected. Reason: ' . $rejection_reason,
                            'account_rejection',
                            $user_id
                        );

                        // RECORD REJECTION IN AUDIT TRAIL
                        logAudit(
                            $pdo,
                            'REJECT RESIDENT',
                            'Resident Approvals',
                            'Administrator rejected resident registration: ' . $pending_user['full_name'] . ' (User ID: ' . $user_id . '). Reason: ' . $rejection_reason,
                            (int)$_SESSION['user_id'],
                            $_SESSION['role'] ?? 'admin'
                        );

                        $message = "Resident registration rejected.";

                    } else {

                        $message = "Unable to reject registration.";
                        $message_type = "danger";

                    }
                }

            } else {

                $message = "Invalid action.";
                $message_type = "danger";

            }
        }
    }
}

// Fetch pending residents
$pending_stmt = $pdo->query("
    SELECT user_id,
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

$pending_residents = $pending_stmt->fetchAll();

// Fetch approved residents
$approved_stmt = $pdo->query("
    SELECT user_id,
           full_name,
           email,
           username,
           approved_at
    FROM users
    WHERE role = 'resident'
      AND account_status = 'approved'
    ORDER BY full_name ASC
");

$approved_residents = $approved_stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Resident Management | Barangay Putatan</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <link rel="stylesheet" href="../assets/css/style.css">

</head>

<body class="admin-page">

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
                <?= htmlspecialchars($_SESSION['full_name'], ENT_QUOTES, 'UTF-8') ?>
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

        <!-- Sidebar -->
        <aside class="col-md-3 col-lg-2 admin-sidebar p-3">

            <small class="text-muted text-uppercase fw-bold">
                Administration
            </small>

            <nav class="nav flex-column gap-2 mt-3">

                <a class="nav-link admin-nav-link"
                   href="dashboard.php">
                    <i class="bi bi-grid me-2"></i>Dashboard
                </a>

                <a class="nav-link admin-nav-link active"
                   href="residents.php">
                    <i class="bi bi-people me-2"></i>Resident Approvals
                </a>

                <a class="nav-link admin-nav-link"
                   href="users.php">
                    <i class="bi bi-person-gear me-2"></i>User Management
                </a>

                <a class="nav-link admin-nav-link"
                   href="staff.php">
                    <i class="bi bi-person-badge me-2"></i>Staff Management
                </a>

                <a class="nav-link admin-nav-link"
                   href="service-types.php">
                    <i class="bi bi-list-check me-2"></i>Service Types
                </a>

                <a class="nav-link admin-nav-link"
                   href="service-requests.php">
                    <i class="bi bi-card-checklist me-2"></i>Service Requests
                </a>

                <a class="nav-link admin-nav-link"
                   href="announcements.php">
                    <i class="bi bi-megaphone me-2"></i>Announcements
                </a>

                <a class="nav-link admin-nav-link"
                   href="feedback.php">
                    <i class="bi bi-chat-left-text me-2"></i>Complaints & Feedback
                </a>

                <a class="nav-link admin-nav-link"
                   href="analytics.php">
                    <i class="bi bi-bar-chart me-2"></i>Feedback Analytics
                </a>

                <a class="nav-link admin-nav-link"
                   href="reports.php">
                    <i class="bi bi-file-earmark-bar-graph me-2"></i>Reports
                </a>

                <a href="audit-trail.php" class="nav-link admin-nav-link">
                    <i class="bi bi-shield-check me-2"></i> Audit Trail
                </a>

                <a class="nav-link admin-nav-link"
                   href="settings.php">
                    <i class="bi bi-gear me-2"></i>Settings
                </a>

            </nav>

        </aside>

        <!-- Main Content -->
        <main class="col-md-9 col-lg-10 px-4 py-4">

            <div class="mb-4">

                <h2 class="fw-bold text-primary-custom">
                    Resident Management
                </h2>

                <p class="text-muted">
                    Verify proof of residency and manage resident registration requests.
                </p>

            </div>

            <?php if ($message !== ""): ?>

                <div class="alert alert-<?= $message_type ?> alert-dismissible fade show">

                    <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>

                    <button type="button"
                            class="btn-close"
                            data-bs-dismiss="alert">
                    </button>

                </div>

            <?php endif; ?>

            <!-- Pending Registrations -->
            <div class="card border-0 shadow-sm mb-4">

                <div class="card-header bg-white py-3">

                    <h5 class="mb-0 fw-bold text-primary-custom">

                        <i class="bi bi-person-lines-fill me-2"></i>
                        Pending Registrations

                        <span class="badge bg-warning text-dark">
                            <?= count($pending_residents) ?>
                        </span>

                    </h5>

                </div>

                <div class="card-body">

                    <?php if (empty($pending_residents)): ?>

                        <div class="alert alert-light mb-0">
                            No pending registrations at the moment.
                        </div>

                    <?php else: ?>

                        <div class="table-responsive">

                            <table class="table table-hover align-middle">

                                <thead>

                                    <tr>
                                        <th>Full Name</th>
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

                                            <td class="fw-semibold">
                                                <?= htmlspecialchars($resident['full_name'], ENT_QUOTES, 'UTF-8') ?>
                                            </td>

                                            <td>
                                                <?= htmlspecialchars($resident['email'], ENT_QUOTES, 'UTF-8') ?>
                                            </td>

                                            <td>
                                                <?= htmlspecialchars($resident['username'], ENT_QUOTES, 'UTF-8') ?>
                                            </td>

                                            <!-- Proof of Residency -->
                                            <td>

                                                <?php if (!empty($resident['proof_of_residency'])): ?>

                                                    <button type="button"
                                                            class="btn btn-outline-primary btn-sm"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#proofModal<?= (int)$resident['user_id'] ?>">

                                                        <i class="bi bi-file-earmark-check me-1"></i>
                                                        View Proof

                                                    </button>

                                                <?php else: ?>

                                                    <span class="badge bg-danger">
                                                        Missing Document
                                                    </span>

                                                <?php endif; ?>

                                            </td>

                                            <td>
                                                <?= htmlspecialchars($resident['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                            </td>

                                            <td>

                                                <div class="d-flex flex-wrap gap-2">

                                                    <!-- Approve Button -->
                                                    <button type="button"
                                                            class="btn btn-success btn-sm"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#approveModal<?= (int)$resident['user_id'] ?>">

                                                        <i class="bi bi-check-lg me-1"></i>
                                                        Approve

                                                    </button>

                                                    <!-- Reject Button -->
                                                    <button type="button"
                                                            class="btn btn-danger btn-sm"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#rejectModal<?= (int)$resident['user_id'] ?>">

                                                        <i class="bi bi-x-lg me-1"></i>
                                                        Reject

                                                    </button>

                                                </div>

                                            </td>

                                        </tr>

                                        <!-- Proof Preview Modal -->
                                        <div class="modal fade"
                                             id="proofModal<?= (int)$resident['user_id'] ?>"
                                             tabindex="-1"
                                             aria-hidden="true">

                                            <div class="modal-dialog modal-xl modal-dialog-centered">

                                                <div class="modal-content">

                                                    <div class="modal-header">

                                                        <h5 class="modal-title fw-bold">
                                                            Proof of Residency
                                                        </h5>

                                                        <button type="button"
                                                                class="btn-close"
                                                                data-bs-dismiss="modal">
                                                        </button>

                                                    </div>

                                                    <div class="modal-body">

                                                        <div class="mb-3">

                                                            <p class="mb-1">
                                                                <strong>Resident:</strong>
                                                                <?= htmlspecialchars($resident['full_name'], ENT_QUOTES, 'UTF-8') ?>
                                                            </p>

                                                            <p class="text-muted small mb-0">
                                                                Review the uploaded document before approving the registration.
                                                            </p>

                                                        </div>

                                                        <iframe
                                                            src="view-proof.php?user_id=<?= (int)$resident['user_id'] ?>"
                                                            width="100%"
                                                            height="600"
                                                            class="border rounded"
                                                            title="Proof of Residency Preview">
                                                        </iframe>

                                                    </div>

                                                    <div class="modal-footer">

                                                        <button type="button"
                                                                class="btn btn-secondary"
                                                                data-bs-dismiss="modal">
                                                            Close
                                                        </button>

                                                    </div>

                                                </div>

                                            </div>

                                        </div>

                                        <!-- Approval Confirmation Modal -->
                                        <div class="modal fade"
                                             id="approveModal<?= (int)$resident['user_id'] ?>"
                                             tabindex="-1"
                                             aria-hidden="true">

                                            <div class="modal-dialog modal-dialog-centered">

                                                <div class="modal-content">

                                                    <form method="POST">

                                                        <div class="modal-header">

                                                            <h5 class="modal-title fw-bold">
                                                                Confirm Resident Approval
                                                            </h5>

                                                            <button type="button"
                                                                    class="btn-close"
                                                                    data-bs-dismiss="modal">
                                                            </button>

                                                        </div>

                                                        <div class="modal-body">

                                                            <div class="text-center mb-3">

                                                                <i class="bi bi-person-check text-success"
                                                                   style="font-size: 3rem;">
                                                                </i>

                                                            </div>

                                                            <p class="text-center mb-2">
                                                                Are you sure you want to approve the registration of:
                                                            </p>

                                                            <h6 class="text-center fw-bold">
                                                                <?= htmlspecialchars($resident['full_name'], ENT_QUOTES, 'UTF-8') ?>
                                                            </h6>

                                                            <p class="text-muted text-center small">

                                                                <?php if (!empty($resident['proof_of_residency'])): ?>

                                                                    Proof of residency has been uploaded.
                                                                    Once approved, the resident will be able
                                                                    to access the system.

                                                                <?php else: ?>

                                                                    <span class="text-danger">
                                                                        No proof of residency has been uploaded.
                                                                        This account cannot be approved.
                                                                    </span>

                                                                <?php endif; ?>

                                                            </p>

                                                            <input type="hidden"
                                                                   name="user_id"
                                                                   value="<?= (int)$resident['user_id'] ?>">

                                                            <input type="hidden"
                                                                   name="action"
                                                                   value="approve">

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

                                        <!-- Rejection Modal -->
                                        <div class="modal fade"
                                             id="rejectModal<?= (int)$resident['user_id'] ?>"
                                             tabindex="-1"
                                             aria-hidden="true">

                                            <div class="modal-dialog modal-dialog-centered">

                                                <div class="modal-content">

                                                    <form method="POST">

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
                                                                You are rejecting the registration of:
                                                            </p>

                                                            <h6 class="fw-bold">
                                                                <?= htmlspecialchars($resident['full_name'], ENT_QUOTES, 'UTF-8') ?>
                                                            </h6>

                                                            <input type="hidden"
                                                                   name="user_id"
                                                                   value="<?= (int)$resident['user_id'] ?>">

                                                            <input type="hidden"
                                                                   name="action"
                                                                   value="reject">

                                                            <label class="form-label fw-semibold">
                                                                Rejection Reason
                                                            </label>

                                                            <textarea name="rejection_reason"
                                                                      class="form-control"
                                                                      rows="4"
                                                                      required
                                                                      placeholder="Explain why the registration is rejected"></textarea>

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

            <!-- Approved Residents -->
            <div class="card border-0 shadow-sm">

                <div class="card-header bg-white py-3">

                    <h5 class="mb-0 fw-bold text-primary-custom">

                        <i class="bi bi-person-check me-2"></i>
                        Approved Residents

                        <span class="badge bg-primary">
                            <?= count($approved_residents) ?>
                        </span>

                    </h5>

                </div>

                <div class="card-body">

                    <?php if (empty($approved_residents)): ?>

                        <div class="alert alert-light mb-0">
                            No approved residents yet.
                        </div>

                    <?php else: ?>

                        <div class="table-responsive">

                            <table class="table table-hover align-middle">

                                <thead>

                                    <tr>
                                        <th>Full Name</th>
                                        <th>Email</th>
                                        <th>Username</th>
                                        <th>Approval Date</th>
                                    </tr>

                                </thead>

                                <tbody>

                                    <?php foreach ($approved_residents as $resident): ?>

                                        <tr>

                                            <td class="fw-semibold">
                                                <?= htmlspecialchars($resident['full_name'], ENT_QUOTES, 'UTF-8') ?>
                                            </td>

                                            <td>
                                                <?= htmlspecialchars($resident['email'], ENT_QUOTES, 'UTF-8') ?>
                                            </td>

                                            <td>
                                                <?= htmlspecialchars($resident['username'], ENT_QUOTES, 'UTF-8') ?>
                                            </td>

                                            <td>
                                                <?= htmlspecialchars($resident['approved_at'] ?? '', ENT_QUOTES, 'UTF-8') ?>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
