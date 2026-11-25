<?php
session_start();

require_once '../includes/admin_auth.php';
require_once '../includes/audit_helper.php';

// Admin access protection
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// Escape output
function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// ==============================
// FILTERS
// ==============================
$search = trim($_GET['search'] ?? '');
$module_filter = trim($_GET['module'] ?? '');
$action_filter = trim($_GET['action'] ?? '');
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page - 1) * $limit;

$where = [];
$params = [];

// Search filter
if ($search !== '') {
    $where[] = "(
        al.description LIKE :search1
        OR al.action LIKE :search2
        OR al.module LIKE :search3
        OR u.username LIKE :search4
        OR u.role LIKE :search5
    )";

    $search_value = '%' . $search . '%';

    $params[':search1'] = $search_value;
    $params[':search2'] = $search_value;
    $params[':search3'] = $search_value;
    $params[':search4'] = $search_value;
    $params[':search5'] = $search_value;
}

// Module filter
if ($module_filter !== '') {
    $where[] = "al.module = :module";
    $params[':module'] = $module_filter;
}

// Action filter
if ($action_filter !== '') {
    $where[] = "al.action = :action";
    $params[':action'] = $action_filter;
}

// Date From
if ($date_from !== '') {
    $where[] = "DATE(al.created_at) >= :date_from";
    $params[':date_from'] = $date_from;
}

// Date To
if ($date_to !== '') {
    $where[] = "DATE(al.created_at) <= :date_to";
    $params[':date_to'] = $date_to;
}

$where_sql = !empty($where)
    ? 'WHERE ' . implode(' AND ', $where)
    : '';


// ==============================
// TOTAL RECORDS
// ==============================
$count_sql = "
    SELECT COUNT(*)
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.user_id
    $where_sql
";

$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);

$total_records = (int)$count_stmt->fetchColumn();

$total_pages = max(1, (int)ceil($total_records / $limit));
$page = min($page, $total_pages);
$offset = ($page - 1) * $limit;


// ==============================
// FETCH AUDIT LOGS
// ==============================
$sql = "
    SELECT
        al.log_id,
        al.user_id,
        al.action,
        al.module,
        al.description,
        al.ip_address,
        al.created_at,
        u.username,
        u.role AS user_role
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.user_id
    $where_sql
    ORDER BY al.created_at DESC, al.log_id DESC
    LIMIT $limit OFFSET $offset
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$audit_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);


// ==============================
// MODULE DROPDOWN
// ==============================
$module_stmt = $pdo->query("
    SELECT DISTINCT module
    FROM audit_logs
    WHERE module IS NOT NULL AND module <> ''
    ORDER BY module ASC
");

$modules = $module_stmt->fetchAll(PDO::FETCH_COLUMN);


// ==============================
// ACTION DROPDOWN
// ==============================
$action_stmt = $pdo->query("
    SELECT DISTINCT action
    FROM audit_logs
    WHERE action IS NOT NULL AND action <> ''
    ORDER BY action ASC
");

$actions = $action_stmt->fetchAll(PDO::FETCH_COLUMN);


// ==============================
// SUMMARY COUNTS
// ==============================
$total_logs = (int)$pdo->query("
    SELECT COUNT(*) FROM audit_logs
")->fetchColumn();

$today_logs = (int)$pdo->query("
    SELECT COUNT(*)
    FROM audit_logs
    WHERE DATE(created_at) = CURDATE()
")->fetchColumn();

$unique_modules = (int)$pdo->query("
    SELECT COUNT(DISTINCT module)
    FROM audit_logs
")->fetchColumn();

$active_users = (int)$pdo->query("
    SELECT COUNT(DISTINCT user_id)
    FROM audit_logs
    WHERE user_id IS NOT NULL
")->fetchColumn();


// ==============================
// PAGINATION PARAMETERS
// ==============================
$query_params = [
    'search' => $search,
    'module' => $module_filter,
    'action' => $action_filter,
    'date_from' => $date_from,
    'date_to' => $date_to
];

$query_params = array_filter(
    $query_params,
    fn($value) => $value !== ''
);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Audit Trail | Barangay Putatan</title>

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <!-- Shared CSS -->
    <link rel="stylesheet" href="../assets/css/style.css">

    <style>

        .audit-description {
            min-width: 250px;
            max-width: 450px;
            white-space: normal;
            word-break: break-word;
        }

        .audit-table th {
            white-space: nowrap;
        }

        .audit-table td {
            vertical-align: middle;
        }

        .audit-badge {
            font-size: 0.75rem;
            padding: 6px 10px;
        }

        .filter-card {
            border-radius: 12px;
        }

        .audit-pagination .page-link {
            color: #1f3c88;
        }

        .audit-pagination .active .page-link {
            background-color: #1f3c88;
            border-color: #1f3c88;
            color: white;
        }

        .audit-table {
            font-size: 0.9rem;
        }

    </style>

</head>

<body class="admin-page">


<!-- ============================== -->
<!-- NAVBAR -->
<!-- ============================== -->

<nav class="navbar navbar-dark barangay-navbar">

    <div class="container-fluid px-4">

        <a class="navbar-brand d-flex align-items-center gap-2" href="dashboard.php">

            <img
                src="../assets/images/barangay-logo.png"
                alt="Barangay Logo"
                class="navbar-logo"
            >

            <span class="fw-bold">
                Barangay Putatan | Admin
            </span>

        </a>

        <div class="d-flex align-items-center gap-3">

            <span class="text-white">

                <?= e($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Administrator') ?>

            </span>

            <a href="../logout.php" class="btn btn-yellow btn-sm">

                <i class="bi bi-box-arrow-right me-1"></i>
                Logout

            </a>

        </div>

    </div>

</nav>


<!-- ============================== -->
<!-- PAGE LAYOUT -->
<!-- ============================== -->

<div class="container-fluid">

    <div class="row">


        <!-- ============================== -->
        <!-- SIDEBAR -->
        <!-- ============================== -->

        <aside class="col-md-3 col-lg-2 admin-sidebar p-3">

            <small class="text-muted text-uppercase fw-bold">
                Administration
            </small>

            <nav class="nav flex-column gap-2 mt-3">


                <a href="dashboard.php" class="nav-link admin-nav-link">

                    <i class="bi bi-grid me-2"></i>
                    Dashboard

                </a>


                <a href="residents.php" class="nav-link admin-nav-link">

                    <i class="bi bi-people me-2"></i>
                    Resident Approvals

                </a>


                <a href="users.php" class="nav-link admin-nav-link">

                    <i class="bi bi-person-gear me-2"></i>
                    User Management

                </a>


                <a href="staff.php" class="nav-link admin-nav-link">

                    <i class="bi bi-person-badge me-2"></i>
                    Staff Management

                </a>


                <a href="service-types.php" class="nav-link admin-nav-link">

                    <i class="bi bi-list-check me-2"></i>
                    Service Types

                </a>


                <a href="service-requests.php" class="nav-link admin-nav-link">

                    <i class="bi bi-card-checklist me-2"></i>
                    Service Requests

                </a>


                <a href="announcements.php" class="nav-link admin-nav-link">

                    <i class="bi bi-megaphone me-2"></i>
                    Announcements

                </a>


                <a href="feedback.php" class="nav-link admin-nav-link">

                    <i class="bi bi-chat-left-text me-2"></i>
                    Complaints & Feedback

                </a>


                <a href="analytics.php" class="nav-link admin-nav-link">

                    <i class="bi bi-bar-chart me-2"></i>
                    Feedback Analytics

                </a>


                <a href="reports.php" class="nav-link admin-nav-link">

                    <i class="bi bi-file-earmark-bar-graph me-2"></i>
                    Reports

                </a>


                <a href="audit-trail.php" class="nav-link admin-nav-link active">

                    <i class="bi bi-shield-check me-2"></i>
                    Audit Trail

                </a>


                <a href="settings.php" class="nav-link admin-nav-link">

                    <i class="bi bi-gear me-2"></i>
                    Settings

                </a>


            </nav>

        </aside>


        <!-- ============================== -->
        <!-- MAIN CONTENT -->
        <!-- ============================== -->

        <main class="col-md-9 col-lg-10 px-4 py-4">


            <!-- PAGE HEADER -->

            <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">

                <div>

                    <h2 class="fw-bold text-primary-custom">

                        <i class="bi bi-shield-check me-2"></i>
                        Audit Trail

                    </h2>

                    <p class="text-muted mb-0">

                        Monitor and review recorded system activities.

                    </p>

                </div>

            </div>


            <!-- ============================== -->
            <!-- SUMMARY CARDS -->
            <!-- ============================== -->

            <div class="row g-4 mb-4">


                <!-- TOTAL LOGS -->

                <div class="col-md-6 col-xl-3">

                    <div class="card border-0 shadow-sm stat-card h-100">

                        <div class="card-body">

                            <div class="text-muted small">
                                Total Activity Logs
                            </div>

                            <h3 class="fw-bold mb-0">
                                <?= number_format($total_logs) ?>
                            </h3>

                            <i class="bi bi-journal-text text-primary fs-4"></i>

                        </div>

                    </div>

                </div>


                <!-- TODAY -->

                <div class="col-md-6 col-xl-3">

                    <div class="card border-0 shadow-sm stat-card h-100">

                        <div class="card-body">

                            <div class="text-muted small">
                                Activities Today
                            </div>

                            <h3 class="fw-bold mb-0">
                                <?= number_format($today_logs) ?>
                            </h3>

                            <i class="bi bi-calendar-check text-success fs-4"></i>

                        </div>

                    </div>

                </div>


                <!-- MODULES -->

                <div class="col-md-6 col-xl-3">

                    <div class="card border-0 shadow-sm stat-card h-100">

                        <div class="card-body">

                            <div class="text-muted small">
                                Recorded Modules
                            </div>

                            <h3 class="fw-bold mb-0">
                                <?= number_format($unique_modules) ?>
                            </h3>

                            <i class="bi bi-grid text-warning fs-4"></i>

                        </div>

                    </div>

                </div>


                <!-- USERS -->

                <div class="col-md-6 col-xl-3">

                    <div class="card border-0 shadow-sm stat-card h-100">

                        <div class="card-body">

                            <div class="text-muted small">
                                Recorded Users
                            </div>

                            <h3 class="fw-bold mb-0">
                                <?= number_format($active_users) ?>
                            </h3>

                            <i class="bi bi-person-lines-fill text-info fs-4"></i>

                        </div>

                    </div>

                </div>


            </div>


            <!-- ============================== -->
            <!-- FILTER CARD -->
            <!-- ============================== -->

            <div class="card border-0 shadow-sm mb-4 filter-card">


                <div class="card-header bg-white py-3">

                    <h5 class="fw-bold mb-0">

                        <i class="bi bi-funnel me-2"></i>
                        Filter Activity Logs

                    </h5>

                </div>


                <div class="card-body">


                    <form method="GET">

                        <div class="row g-3">


                            <!-- SEARCH -->

                            <div class="col-lg-4">

                                <label class="form-label">
                                    Search
                                </label>

                                <input
                                    type="text"
                                    name="search"
                                    class="form-control"
                                    placeholder="Search description, user, action..."
                                    value="<?= e($search) ?>"
                                >

                            </div>


                            <!-- MODULE -->

                            <div class="col-lg-2">

                                <label class="form-label">
                                    Module
                                </label>

                                <select name="module" class="form-select">

                                    <option value="">
                                        All Modules
                                    </option>

                                    <?php foreach ($modules as $module): ?>

                                        <option
                                            value="<?= e($module) ?>"
                                            <?= $module_filter === $module ? 'selected' : '' ?>
                                        >

                                            <?= e($module) ?>

                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>


                            <!-- ACTION -->

                            <div class="col-lg-2">

                                <label class="form-label">
                                    Action
                                </label>

                                <select name="action" class="form-select">

                                    <option value="">
                                        All Actions
                                    </option>

                                    <?php foreach ($actions as $action): ?>

                                        <option
                                            value="<?= e($action) ?>"
                                            <?= $action_filter === $action ? 'selected' : '' ?>
                                        >

                                            <?= e($action) ?>

                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>


                            <!-- DATE FROM -->

                            <div class="col-lg-2">

                                <label class="form-label">
                                    Date From
                                </label>

                                <input
                                    type="date"
                                    name="date_from"
                                    class="form-control"
                                    value="<?= e($date_from) ?>"
                                >

                            </div>


                            <!-- DATE TO -->

                            <div class="col-lg-2">

                                <label class="form-label">
                                    Date To
                                </label>

                                <input
                                    type="date"
                                    name="date_to"
                                    class="form-control"
                                    value="<?= e($date_to) ?>"
                                >

                            </div>


                        </div>


                        <!-- BUTTONS -->

                        <div class="d-flex flex-wrap gap-2 mt-3">

                            <button type="submit" class="btn btn-primary-custom">

                                <i class="bi bi-search me-1"></i>
                                Apply Filters

                            </button>


                            <a href="audit-trail.php" class="btn btn-outline-secondary">

                                <i class="bi bi-arrow-counterclockwise me-1"></i>
                                Reset

                            </a>

                        </div>


                    </form>


                </div>

            </div>


            <!-- ============================== -->
            <!-- AUDIT LOG TABLE -->
            <!-- ============================== -->

            <div class="card border-0 shadow-sm">


                <!-- TABLE HEADER -->

                <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center">

                    <h5 class="fw-bold mb-0">

                        <i class="bi bi-journal-text me-2"></i>
                        Activity Records

                    </h5>

                    <span class="text-muted small">

                        <?= number_format($total_records) ?> matching records

                    </span>

                </div>


                <div class="card-body">


                    <div class="table-responsive">


                        <table class="table table-hover audit-table align-middle">


                            <thead class="table-light">

                                <tr>

                                    <th>ID</th>
                                    <th>Date & Time</th>
                                    <th>User</th>
                                    <th>Role</th>
                                    <th>Module</th>
                                    <th>Action</th>
                                    <th>Description</th>
                                    <th>IP Address</th>

                                </tr>

                            </thead>


                            <tbody>


                                <?php if (!empty($audit_logs)): ?>


                                    <?php foreach ($audit_logs as $log): ?>


                                        <tr>


                                            <!-- ID -->

                                            <td>

                                                #<?= (int)$log['log_id'] ?>

                                            </td>


                                            <!-- DATE -->

                                            <td>

                                                <div class="fw-semibold">

                                                    <?= e(date('M d, Y', strtotime($log['created_at']))) ?>

                                                </div>

                                                <small class="text-muted">

                                                    <?= e(date('h:i A', strtotime($log['created_at']))) ?>

                                                </small>

                                            </td>


                                            <!-- USER -->

                                            <td>

                                                <?php if (!empty($log['username'])): ?>

                                                    <div class="fw-semibold">

                                                        <?= e($log['username']) ?>

                                                    </div>

                                                <?php else: ?>

                                                    <span class="text-muted">

                                                        Deleted/Unknown User

                                                    </span>

                                                <?php endif; ?>


                                                <?php if (!empty($log['user_id'])): ?>

                                                    <small class="text-muted">

                                                        UID: <?= (int)$log['user_id'] ?>

                                                    </small>

                                                <?php endif; ?>

                                            </td>


                                            <!-- ROLE -->

                                            <td>

                                                <?= e(ucfirst($log['user_role'] ?? 'Unknown')) ?>

                                            </td>


                                            <!-- MODULE -->

                                            <td>

                                                <span class="badge bg-light text-dark border audit-badge">

                                                    <?= e($log['module']) ?>

                                                </span>

                                            </td>


                                            <!-- ACTION -->

                                            <td>

                                                <?php

                                                $badge_class = 'bg-secondary';

                                                switch (strtoupper($log['action'])) {

                                                    case 'CREATE':
                                                    case 'ADD':
                                                    case 'INSERT':
                                                    case 'APPROVE':

                                                        $badge_class = 'bg-success';
                                                        break;

                                                    case 'UPDATE':
                                                    case 'EDIT':

                                                        $badge_class = 'bg-primary';
                                                        break;

                                                    case 'DELETE':
                                                    case 'REMOVE':

                                                        $badge_class = 'bg-danger';
                                                        break;

                                                    case 'LOGIN':

                                                        $badge_class = 'bg-info text-dark';
                                                        break;

                                                    case 'LOGOUT':

                                                        $badge_class = 'bg-dark';
                                                        break;

                                                    case 'REJECT':
                                                    case 'REJECTION':

                                                        $badge_class = 'bg-warning text-dark';
                                                        break;
                                                }

                                                ?>

                                                <span class="badge <?= $badge_class ?> audit-badge">

                                                    <?= e($log['action']) ?>

                                                </span>

                                            </td>


                                            <!-- DESCRIPTION -->

                                            <td class="audit-description">

                                                <?= e($log['description'] ?? 'No description provided.') ?>

                                            </td>


                                            <!-- IP ADDRESS -->

                                            <td>

                                                <small class="text-muted">

                                                    <?= e($log['ip_address'] ?? 'N/A') ?>

                                                </small>

                                            </td>


                                        </tr>


                                    <?php endforeach; ?>


                                <?php else: ?>


                                    <tr>

                                        <td colspan="8" class="text-center py-5">


                                            <i class="bi bi-journal-x fs-1 text-muted"></i>


                                            <h6 class="mt-3 text-muted">

                                                No activity records found.

                                            </h6>


                                            <p class="text-muted small mb-0">

                                                Activity logs will appear here once system actions
                                                are integrated with the audit logging helper.

                                            </p>


                                        </td>

                                    </tr>


                                <?php endif; ?>


                            </tbody>


                        </table>


                    </div>


                    <!-- ============================== -->
                    <!-- PAGINATION -->
                    <!-- ============================== -->

                    <?php if ($total_pages > 1): ?>


                        <nav class="mt-4">

                            <ul class="pagination justify-content-center audit-pagination">


                                <?php

                                $previous_page = max(1, $page - 1);
                                $next_page = min($total_pages, $page + 1);

                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);

                                ?>


                                <!-- PREVIOUS -->

                                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">

                                    <a
                                        class="page-link"
                                        href="?<?= http_build_query(array_merge($query_params, ['page' => $previous_page])) ?>"
                                    >

                                        Previous

                                    </a>

                                </li>


                                <!-- PAGE NUMBERS -->

                                <?php for ($i = $start_page; $i <= $end_page; $i++): ?>


                                    <li class="page-item <?= $page == $i ? 'active' : '' ?>">

                                        <a
                                            class="page-link"
                                            href="?<?= http_build_query(array_merge($query_params, ['page' => $i])) ?>"
                                        >

                                            <?= $i ?>

                                        </a>

                                    </li>


                                <?php endfor; ?>


                                <!-- NEXT -->

                                <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">

                                    <a
                                        class="page-link"
                                        href="?<?= http_build_query(array_merge($query_params, ['page' => $next_page])) ?>"
                                    >

                                        Next

                                    </a>

                                </li>


                            </ul>

                        </nav>


                    <?php endif; ?>


                </div>

            </div>


        </main>


    </div>

</div>


<!-- Bootstrap JS -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>


</body>

</html>