<?php
require_once '../includes/admin_auth.php';
require_once '../includes/audit_helper.php';
require_once '../includes/notification_helper.php';

// Dashboard statistics
$total_residents = $pdo->query("
    SELECT COUNT(*) FROM users
    WHERE role = 'resident' AND account_status = 'approved'
")->fetchColumn();

$pending_residents = $pdo->query("
    SELECT COUNT(*) FROM users
    WHERE role = 'resident' AND account_status = 'pending'
")->fetchColumn();

$total_staff = $pdo->query("
    SELECT COUNT(*) FROM users
    WHERE role = 'staff' AND account_status = 'approved'
")->fetchColumn();

$total_requests = $pdo->query("
    SELECT COUNT(*) FROM service_requests
")->fetchColumn();

$total_feedback = $pdo->query("
    SELECT COUNT(*) FROM citizen_feedback
")->fetchColumn();

$recent_pending = $pdo->query("
    SELECT user_id, full_name, email, username, created_at
    FROM users
    WHERE role = 'resident' AND account_status = 'pending'
    ORDER BY created_at DESC
    LIMIT 5
")->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Admin Dashboard | Barangay Putatan</title>

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

            <a href="../logout.php" class="btn btn-yellow btn-sm">
                Logout
            </a>
        </div>
    </div>
</nav>

<div class="container-fluid">
    <div class="row">

        <!-- Sidebar -->
        <!-- Sidebar -->
        <aside class="col-md-3 col-lg-2 admin-sidebar p-3">

            <small class="text-muted text-uppercase fw-bold">
                Administration
            </small>

            <nav class="nav flex-column gap-2 mt-3">

                <a class="nav-link admin-nav-link active"
                   href="dashboard.php">
                    <i class="bi bi-grid me-2"></i>Dashboard
                </a>

                <a class="nav-link admin-nav-link"
                   href="residents.php">
                    <i class="bi bi-people me-2"></i>Resident Approvals
                </a>

                <a class="nav-link admin-nav-link"
                   href="resident-directory.php">
                    <i class="bi bi-person-vcard me-2"></i>Resident Directory
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

        <!-- Main content -->
        <main class="col-md-9 col-lg-10 px-4 py-4">

            <div class="mb-4">
                <h2 class="fw-bold text-primary-custom">
                    Admin Dashboard
                </h2>

                <p class="text-muted">
                    Barangay Putatan E-Governance System overview.
                </p>
            </div>

            <!-- Statistics -->
            <div class="row g-4 mb-4">

                <div class="col-sm-6 col-xl-3">
                    <div class="stat-card">
                        <small class="text-muted">Approved Residents</small>
                        <h2><?= $total_residents ?></h2>
                    </div>
                </div>

                <div class="col-sm-6 col-xl-3">
                    <div class="stat-card stat-pending">
                        <small class="text-muted">Pending Registrations</small>
                        <h2><?= $pending_residents ?></h2>

                        <a href="residents.php" class="small">
                            Review registrations
                        </a>
                    </div>
                </div>

                <div class="col-sm-6 col-xl-3">
                    <div class="stat-card">
                        <small class="text-muted">Barangay Staff</small>
                        <h2><?= $total_staff ?></h2>
                    </div>
                </div>

                <div class="col-sm-6 col-xl-3">
                    <div class="stat-card">
                        <small class="text-muted">Service Requests</small>
                        <h2><?= $total_requests ?></h2>
                    </div>
                </div>

            </div>

            <div class="row g-4 mb-4">

                <div class="col-lg-6">
                    <div class="stat-card">
                        <small class="text-muted">Citizen Feedback</small>
                        <h2><?= $total_feedback ?></h2>
                        <p class="mb-0">
                            Total submitted complaints, suggestions,
                            and other feedback.
                        </p>
                    </div>
                </div>

            </div>

            <!-- Recent pending registrations -->
            <div class="card border-0 shadow-sm">

                <div class="card-header bg-white py-3">

                    <div class="d-flex justify-content-between align-items-center">

                        <h5 class="mb-0 fw-bold text-primary-custom">
                            Recent Pending Registrations
                        </h5>

                        <a href="residents.php"
                           class="btn btn-primary-custom btn-sm">
                            View All
                        </a>

                    </div>

                </div>

                <div class="card-body">

                    <?php if (empty($recent_pending)): ?>

                        <div class="alert alert-light mb-0">
                            No pending resident registrations.
                        </div>

                    <?php else: ?>

                        <div class="table-responsive">

                            <table class="table table-hover align-middle">

                                <thead>
                                    <tr>
                                        <th>Full Name</th>
                                        <th>Email</th>
                                        <th>Username</th>
                                        <th>Registration Date</th>
                                    </tr>
                                </thead>

                                <tbody>

                                    <?php foreach ($recent_pending as $resident): ?>

                                        <tr>

                                            <td>
                                                <?= htmlspecialchars($resident['full_name'], ENT_QUOTES, 'UTF-8') ?>
                                            </td>

                                            <td>
                                                <?= htmlspecialchars($resident['email'], ENT_QUOTES, 'UTF-8') ?>
                                            </td>

                                            <td>
                                                <?= htmlspecialchars($resident['username'], ENT_QUOTES, 'UTF-8') ?>
                                            </td>

                                            <td>
                                                <?= htmlspecialchars($resident['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?>
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