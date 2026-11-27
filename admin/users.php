<?php
require_once '../includes/admin_auth.php';
require_once '../includes/audit_helper.php';

$message = '';
$message_type = 'success';

// Handle account suspension / restoration
// Admin accounts and pending/rejected accounts are intentionally not modified.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $target_user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $action = $_POST['action'] ?? '';

    if (!$target_user_id || !in_array($action, ['suspend', 'restore'], true)) {
        $message = 'Invalid account action.';
        $message_type = 'danger';

    } elseif ((int)$target_user_id === (int)($_SESSION['user_id'] ?? 0)) {
        $message = 'You cannot suspend or modify your own account.';
        $message_type = 'danger';

    } else {

        try {
            $check = $pdo->prepare(
                "SELECT user_id, full_name, username, role, account_status
                 FROM users
                 WHERE user_id = :user_id
                 LIMIT 1"
            );

            $check->execute([':user_id' => $target_user_id]);
            $target = $check->fetch(PDO::FETCH_ASSOC);

            if (!$target) {
                $message = 'Account not found.';
                $message_type = 'danger';

            } elseif ($target['role'] === 'admin') {
                $message = 'Administrator accounts cannot be modified through User Management.';
                $message_type = 'danger';

            } elseif ($action === 'suspend' && $target['account_status'] !== 'approved') {
                $message = 'Only approved accounts can be suspended.';
                $message_type = 'danger';

            } elseif ($action === 'restore' && $target['account_status'] !== 'suspended') {
                $message = 'Only suspended accounts can be restored.';
                $message_type = 'danger';

            } else {

                $new_status = ($action === 'suspend') ? 'suspended' : 'approved';

                $update = $pdo->prepare(
                    "UPDATE users
                     SET account_status = :status
                     WHERE user_id = :user_id
                     AND role <> 'admin'"
                );

                $update->execute([
                    ':status' => $new_status,
                    ':user_id' => $target_user_id
                ]);

                if ($update->rowCount() > 0) {

                    $audit_action = ($action === 'suspend')
                        ? 'SUSPEND USER'
                        : 'RESTORE USER';

                    $description = (
                        $action === 'suspend'
                            ? 'Administrator suspended account: '
                            : 'Administrator restored account: '
                    ) . $target['full_name']
                        . ' (Username: ' . $target['username']
                        . ', User ID: ' . $target_user_id
                        . ', Role: ' . $target['role'] . ')';

                    logAudit(
                        $pdo,
                        $audit_action,
                        'User Management',
                        $description,
                        (int)$_SESSION['user_id'],
                        $_SESSION['role'] ?? 'admin'
                    );

                    $message = ($action === 'suspend')
                        ? 'Account suspended successfully.'
                        : 'Account restored successfully.';

                } else {
                    $message = 'No account changes were made.';
                    $message_type = 'danger';
                }
            }

        } catch (Throwable $e) {
            error_log('[User Management] ' . $e->getMessage());
            $message = 'An error occurred while updating the account.';
            $message_type = 'danger';
        }
    }
}

// FILTERS AND PAGINATION
$search = trim($_GET['search'] ?? '');
$role_filter = $_GET['role'] ?? '';
$status_filter = $_GET['status'] ?? '';

$valid_roles = ['admin', 'staff', 'resident'];
$valid_statuses = ['pending', 'approved', 'rejected', 'suspended'];

if (!in_array($role_filter, $valid_roles, true)) {
    $role_filter = '';
}

if (!in_array($status_filter, $valid_statuses, true)) {
    $status_filter = '';
}

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;
$offset = ($page - 1) * $per_page;

// BUILD FILTER QUERY
$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(full_name LIKE :search_name
                  OR username LIKE :search_username
                  OR email LIKE :search_email)';

    $term = '%' . $search . '%';

    $params[':search_name'] = $term;
    $params[':search_username'] = $term;
    $params[':search_email'] = $term;
}

if ($role_filter !== '') {
    $where[] = 'role = :role';
    $params[':role'] = $role_filter;
}

if ($status_filter !== '') {
    $where[] = 'account_status = :status';
    $params[':status'] = $status_filter;
}

$where_sql = $where
    ? 'WHERE ' . implode(' AND ', $where)
    : '';

// GET TOTAL USERS
$count_stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM users $where_sql"
);

$count_stmt->execute($params);
$total_users = (int)$count_stmt->fetchColumn();

$total_pages = max(
    1,
    (int)ceil($total_users / $per_page)
);

$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

// GET USER LIST
$list_stmt = $pdo->prepare(
    "SELECT user_id, full_name, email, username, role, account_status, created_at
     FROM users
     $where_sql
     ORDER BY created_at DESC
     LIMIT $per_page OFFSET $offset"
);

$list_stmt->execute($params);
$users = $list_stmt->fetchAll(PDO::FETCH_ASSOC);

// STATISTICS
$stats_stmt = $pdo->query(
    "SELECT
        COUNT(*) AS total,
        SUM(role = 'resident') AS residents,
        SUM(role = 'staff') AS staff,
        SUM(account_status = 'suspended') AS suspended
     FROM users"
);

$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC) ?: [
    'total' => 0,
    'residents' => 0,
    'staff' => 0,
    'suspended' => 0
];

// STATUS BADGE
function userStatusBadge(string $status): string {
    $classes = [
        'pending'   => 'bg-warning text-dark',
        'approved'  => 'bg-success',
        'rejected'  => 'bg-danger',
        'suspended' => 'bg-secondary'
    ];

    return '<span class="badge '
        . ($classes[$status] ?? 'bg-secondary')
        . '">'
        . htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8')
        . '</span>';
}

// ROLE BADGE
function userRoleBadge(string $role): string {
    $classes = [
        'admin'    => 'bg-dark',
        'staff'    => 'bg-info text-dark',
        'resident' => 'bg-primary'
    ];

    return '<span class="badge '
        . ($classes[$role] ?? 'bg-secondary')
        . '">'
        . htmlspecialchars(ucfirst($role), ENT_QUOTES, 'UTF-8')
        . '</span>';
}

// PAGINATION URL
function userPageUrl(int $pageNum): string {
    $params = $_GET;
    $params['page'] = $pageNum;

    return '?' . http_build_query($params);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>User Management | Barangay Putatan</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >

    <link rel="stylesheet" href="../assets/css/style.css">
</head>

<body class="admin-page">

<!-- NAVBAR -->
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
                <?= htmlspecialchars($_SESSION['full_name'] ?? 'Administrator', ENT_QUOTES, 'UTF-8') ?>
            </span>

            <a href="../logout.php" class="btn btn-yellow btn-sm">
                Logout
            </a>
        </div>

    </div>
</nav>

<!-- MAIN LAYOUT -->
<div class="container-fluid">
    <div class="row">

        <!-- SIDEBAR -->
        <!-- SIDEBAR -->
        <aside class="col-md-3 col-lg-2 admin-sidebar p-3">

            <small class="text-muted text-uppercase fw-bold">
                Administration
            </small>

            <nav class="nav flex-column gap-2 mt-3">

                <a class="nav-link admin-nav-link"
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

                <a class="nav-link admin-nav-link active"
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

                <a class="nav-link admin-nav-link"
                   href="audit-trail.php">
                    <i class="bi bi-shield-check me-2"></i>Audit Trail
                </a>

                <a class="nav-link admin-nav-link"
                   href="settings.php">
                    <i class="bi bi-gear me-2"></i>Settings
                </a>

            </nav>

        </aside>

        <!-- MAIN CONTENT -->
        <main class="col-md-9 col-lg-10 px-4 py-4">

            <!-- PAGE HEADER -->
            <div class="mb-4">
                <h2 class="fw-bold text-primary-custom">
                    User Management
                </h2>

                <p class="text-muted">
                    View, search, and manage registered user accounts.
                </p>
            </div>

            <!-- ALERT MESSAGE -->
            <?php if ($message !== ''): ?>
                <div class="alert alert-<?= htmlspecialchars($message_type, ENT_QUOTES, 'UTF-8') ?> alert-dismissible fade show">
                    <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="alert"
                    ></button>
                </div>
            <?php endif; ?>

            <!-- STATISTICS CARDS -->
            <div class="row g-3 mb-4">

                <div class="col-md-3">
                    <div class="card stat-card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="text-muted small">
                                Total Accounts
                            </div>

                            <h3 class="fw-bold mb-0">
                                <?= (int)$stats['total'] ?>
                            </h3>
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="card stat-card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="text-muted small">
                                Residents
                            </div>

                            <h3 class="fw-bold mb-0">
                                <?= (int)$stats['residents'] ?>
                            </h3>
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="card stat-card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="text-muted small">
                                Staff
                            </div>

                            <h3 class="fw-bold mb-0">
                                <?= (int)$stats['staff'] ?>
                            </h3>
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="card stat-pending border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="text-muted small">
                                Suspended
                            </div>

                            <h3 class="fw-bold mb-0">
                                <?= (int)$stats['suspended'] ?>
                            </h3>
                        </div>
                    </div>
                </div>

            </div>

            <!-- SEARCH AND FILTERS -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">

                    <form method="GET" class="row g-3 align-items-end">

                        <!-- SEARCH -->
                        <div class="col-md-5">
                            <label for="search" class="form-label fw-semibold">
                                Search
                            </label>

                            <input
                                type="text"
                                id="search"
                                name="search"
                                class="form-control"
                                value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                                placeholder="Name, username, or email"
                            >
                        </div>

                        <!-- ROLE FILTER -->
                        <div class="col-md-3">
                            <label for="role" class="form-label fw-semibold">
                                Role
                            </label>

                            <select id="role" name="role" class="form-select">
                                <option value="">All Roles</option>

                                <?php foreach ($valid_roles as $r): ?>
                                    <option
                                        value="<?= htmlspecialchars($r, ENT_QUOTES, 'UTF-8') ?>"
                                        <?= $role_filter === $r ? 'selected' : '' ?>
                                    >
                                        <?= ucfirst($r) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- STATUS FILTER -->
                        <div class="col-md-3">
                            <label for="status" class="form-label fw-semibold">
                                Status
                            </label>

                            <select id="status" name="status" class="form-select">
                                <option value="">All Statuses</option>

                                <?php foreach ($valid_statuses as $s): ?>
                                    <option
                                        value="<?= htmlspecialchars($s, ENT_QUOTES, 'UTF-8') ?>"
                                        <?= $status_filter === $s ? 'selected' : '' ?>
                                    >
                                        <?= ucfirst($s) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- SEARCH BUTTON -->
                        <div class="col-md-1 d-grid">
                            <button
                                class="btn btn-primary-custom"
                                type="submit"
                                title="Search"
                            >
                                <i class="bi bi-search"></i>
                            </button>
                        </div>

                        <!-- CLEAR FILTERS -->
                        <div class="col-12">
                            <a href="users.php" class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-arrow-counterclockwise me-1"></i>
                                Clear Filters
                            </a>
                        </div>

                    </form>

                </div>
            </div>

            <!-- USER ACCOUNTS TABLE -->
            <div class="card border-0 shadow-sm">

                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">

                    <h5 class="mb-0 fw-bold text-primary-custom">
                        <i class="bi bi-people me-2"></i>
                        User Accounts
                    </h5>

                    <span class="text-muted small">
                        <?= $total_users ?> result(s)
                    </span>

                </div>

                <div class="card-body">

                    <?php if (!$users): ?>

                        <div class="alert alert-light mb-0">
                            No user accounts match your filters.
                        </div>

                    <?php else: ?>

                        <div class="table-responsive">

                            <table class="table table-hover align-middle">

                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Username</th>
                                        <th>Email</th>
                                        <th>Role</th>
                                        <th>Status</th>
                                        <th>Registered</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>

                                <tbody>

                                    <?php foreach ($users as $u): ?>

                                        <tr>

                                            <td class="fw-semibold">
                                                <?= htmlspecialchars($u['full_name'], ENT_QUOTES, 'UTF-8') ?>
                                            </td>

                                            <td>
                                                <?= htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8') ?>
                                            </td>

                                            <td>
                                                <?= htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8') ?>
                                            </td>

                                            <td>
                                                <?= userRoleBadge($u['role']) ?>
                                            </td>

                                            <td>
                                                <?= userStatusBadge($u['account_status']) ?>
                                            </td>

                                            <td>
                                                <?= htmlspecialchars($u['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                            </td>

                                            <td>

                                                <?php if ($u['role'] !== 'admin' && $u['account_status'] === 'approved'): ?>

                                                    <button
                                                        type="button"
                                                        class="btn btn-danger btn-sm"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#actionModal<?= (int)$u['user_id'] ?>"
                                                    >
                                                        <i class="bi bi-person-slash me-1"></i>
                                                        Suspend
                                                    </button>

                                                <?php elseif ($u['role'] !== 'admin' && $u['account_status'] === 'suspended'): ?>

                                                    <button
                                                        type="button"
                                                        class="btn btn-success btn-sm"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#actionModal<?= (int)$u['user_id'] ?>"
                                                    >
                                                        <i class="bi bi-person-check me-1"></i>
                                                        Restore
                                                    </button>

                                                <?php else: ?>

                                                    <span class="text-muted small">
                                                        —
                                                    </span>

                                                <?php endif; ?>

                                            </td>

                                        </tr>

                                        <!-- SUSPEND / RESTORE MODAL -->
                                        <?php if (
                                            $u['role'] !== 'admin' &&
                                            in_array($u['account_status'], ['approved', 'suspended'], true)
                                        ): ?>

                                            <div
                                                class="modal fade"
                                                id="actionModal<?= (int)$u['user_id'] ?>"
                                                tabindex="-1"
                                                aria-hidden="true"
                                            >
                                                <div class="modal-dialog modal-dialog-centered">
                                                    <div class="modal-content">

                                                        <form method="POST">

                                                            <div class="modal-header">

                                                                <h5 class="modal-title fw-bold">
                                                                    Confirm Account
                                                                    <?= $u['account_status'] === 'approved'
                                                                        ? 'Suspension'
                                                                        : 'Restoration' ?>
                                                                </h5>

                                                                <button
                                                                    type="button"
                                                                    class="btn-close"
                                                                    data-bs-dismiss="modal"
                                                                ></button>

                                                            </div>

                                                            <div class="modal-body">

                                                                <p>
                                                                    Are you sure you want to
                                                                    <?= $u['account_status'] === 'approved'
                                                                        ? 'suspend'
                                                                        : 'restore' ?>
                                                                    this account?
                                                                </p>

                                                                <p class="fw-bold mb-1">
                                                                    <?= htmlspecialchars($u['full_name'], ENT_QUOTES, 'UTF-8') ?>
                                                                </p>

                                                                <p class="text-muted small mb-0">
                                                                    Username:
                                                                    <?= htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8') ?>
                                                                </p>

                                                                <input
                                                                    type="hidden"
                                                                    name="user_id"
                                                                    value="<?= (int)$u['user_id'] ?>"
                                                                >

                                                                <input
                                                                    type="hidden"
                                                                    name="action"
                                                                    value="<?= $u['account_status'] === 'approved'
                                                                        ? 'suspend'
                                                                        : 'restore' ?>"
                                                                >

                                                            </div>

                                                            <div class="modal-footer">

                                                                <button
                                                                    type="button"
                                                                    class="btn btn-light border"
                                                                    data-bs-dismiss="modal"
                                                                >
                                                                    Cancel
                                                                </button>

                                                                <button
                                                                    type="submit"
                                                                    class="btn <?= $u['account_status'] === 'approved'
                                                                        ? 'btn-danger'
                                                                        : 'btn-success' ?>"
                                                                >
                                                                    Confirm
                                                                </button>

                                                            </div>

                                                        </form>

                                                    </div>
                                                </div>
                                            </div>

                                        <?php endif; ?>

                                    <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                        <!-- PAGINATION -->
                        <?php if ($total_pages > 1): ?>

                            <nav aria-label="User pages">
                                <ul class="pagination justify-content-end mb-0">

                                    <!-- PREVIOUS -->
                                    <?php if ($page > 1): ?>

                                        <li class="page-item">
                                            <a
                                                class="page-link"
                                                href="<?= htmlspecialchars(userPageUrl($page - 1), ENT_QUOTES, 'UTF-8') ?>"
                                            >
                                                Previous
                                            </a>
                                        </li>

                                    <?php endif; ?>

                                    <!-- PAGE NUMBERS -->
                                    <?php
                                    for (
                                        $p = max(1, $page - 2);
                                        $p <= min($total_pages, $page + 2);
                                        $p++
                                    ):
                                    ?>

                                        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                            <a
                                                class="page-link"
                                                href="<?= htmlspecialchars(userPageUrl($p), ENT_QUOTES, 'UTF-8') ?>"
                                            >
                                                <?= $p ?>
                                            </a>
                                        </li>

                                    <?php endfor; ?>

                                    <!-- NEXT -->
                                    <?php if ($page < $total_pages): ?>

                                        <li class="page-item">
                                            <a
                                                class="page-link"
                                                href="<?= htmlspecialchars(userPageUrl($page + 1), ENT_QUOTES, 'UTF-8') ?>"
                                            >
                                                Next
                                            </a>
                                        </li>

                                    <?php endif; ?>

                                </ul>
                            </nav>

                        <?php endif; ?>

                    <?php endif; ?>

                </div>
            </div>

        </main>
    </div>
</div>

<!-- BOOTSTRAP JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>