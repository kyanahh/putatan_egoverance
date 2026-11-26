<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../includes/admin_auth.php';
require_once '../includes/audit_helper.php';

/* =========================================================
   CSRF TOKEN
========================================================= */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION['csrf_token'];
$message = '';
$messageType = 'success';

/* =========================================================
   AUDIT LOG HELPER
========================================================= */
function st_log(PDO $pdo, string $action, string $description): void
{
    logAudit($pdo, $action, 'Service Types', $description);
}

/* =========================================================
   HANDLE POST REQUESTS
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        $message = 'Invalid form submission. Please try again.';
        $messageType = 'danger';

    } else {

        $action = $_POST['action'] ?? '';

        try {

            /* =================================================
               CREATE SERVICE TYPE
            ================================================= */
            if ($action === 'create') {

                $name = trim($_POST['service_name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $requirements = trim($_POST['requirements'] ?? '');
                $days = trim($_POST['processing_days'] ?? '0');
                $fee = trim($_POST['service_fee'] ?? '0');

                if (
                    $name === '' ||
                    !is_numeric($days) ||
                    !is_numeric($fee) ||
                    (float)$days < 0 ||
                    (float)$fee < 0
                ) {
                    throw new Exception(
                        'Please enter a service name and valid processing days and fee.'
                    );
                }

                $stmt = $pdo->prepare("
                    INSERT INTO service_types
                    (
                        service_name,
                        description,
                        requirements,
                        processing_days,
                        service_fee,
                        is_active
                    )
                    VALUES (?, ?, ?, ?, ?, 1)
                ");

                $stmt->execute([
                    $name,
                    $description,
                    $requirements,
                    $days,
                    $fee
                ]);

                $serviceId = (int)$pdo->lastInsertId();

                st_log(
                    $pdo,
                    'CREATE SERVICE TYPE',
                    'Administrator created service type: ' .
                    $name . ' (ID: ' . $serviceId . ')'
                );

                $message = 'Service type created successfully.';
            }

            /* =================================================
               UPDATE SERVICE TYPE
            ================================================= */
            elseif ($action === 'update') {

                $id = (int)($_POST['service_type_id'] ?? 0);
                $name = trim($_POST['service_name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $requirements = trim($_POST['requirements'] ?? '');
                $days = trim($_POST['processing_days'] ?? '0');
                $fee = trim($_POST['service_fee'] ?? '0');

                if (
                    $id <= 0 ||
                    $name === '' ||
                    !is_numeric($days) ||
                    !is_numeric($fee) ||
                    (float)$days < 0 ||
                    (float)$fee < 0
                ) {
                    throw new Exception('Please enter valid service details.');
                }

                $check = $pdo->prepare("
                    SELECT service_name
                    FROM service_types
                    WHERE service_type_id = ?
                ");

                $check->execute([$id]);
                $oldName = $check->fetchColumn();

                if ($oldName === false) {
                    throw new Exception('Service type not found.');
                }

                $stmt = $pdo->prepare("
                    UPDATE service_types
                    SET service_name = ?,
                        description = ?,
                        requirements = ?,
                        processing_days = ?,
                        service_fee = ?
                    WHERE service_type_id = ?
                ");

                $stmt->execute([
                    $name,
                    $description,
                    $requirements,
                    $days,
                    $fee,
                    $id
                ]);

                st_log(
                    $pdo,
                    'UPDATE SERVICE TYPE',
                    'Administrator updated service type: ' .
                    $name . ' (ID: ' . $id . ')'
                );

                $message = 'Service type updated successfully.';
            }

            /* =================================================
               ACTIVATE / DEACTIVATE SERVICE TYPE
            ================================================= */
            elseif ($action === 'toggle') {

                $id = (int)($_POST['service_type_id'] ?? 0);

                $stmt = $pdo->prepare("
                    SELECT service_name, is_active
                    FROM service_types
                    WHERE service_type_id = ?
                ");

                $stmt->execute([$id]);
                $service = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$service) {
                    throw new Exception('Service type not found.');
                }

                $newStatus = (int)!((int)$service['is_active']);

                $upd = $pdo->prepare("
                    UPDATE service_types
                    SET is_active = ?
                    WHERE service_type_id = ?
                ");

                $upd->execute([$newStatus, $id]);

                $auditAction = $newStatus
                    ? 'ACTIVATE SERVICE TYPE'
                    : 'DEACTIVATE SERVICE TYPE';

                st_log(
                    $pdo,
                    $auditAction,
                    'Administrator ' .
                    ($newStatus ? 'activated' : 'deactivated') .
                    ' service type: ' .
                    $service['service_name'] .
                    ' (ID: ' . $id . ')'
                );

                $message = 'Service type ' .
                    ($newStatus ? 'activated' : 'deactivated') .
                    ' successfully.';
            }

        } catch (Throwable $e) {

            $message = $e->getMessage();
            $messageType = 'danger';
        }
    }
}

/* =========================================================
   SEARCH AND FILTER
========================================================= */
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';

$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(service_name LIKE ? OR description LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

if ($statusFilter === 'active' || $statusFilter === 'inactive') {
    $where[] = 'is_active = ?';
    $params[] = ($statusFilter === 'active') ? 1 : 0;
}

$whereSql = $where
    ? 'WHERE ' . implode(' AND ', $where)
    : '';

/* =========================================================
   FETCH SERVICE TYPES
========================================================= */
$stmt = $pdo->prepare("
    SELECT *
    FROM service_types
    $whereSql
    ORDER BY created_at DESC, service_type_id DESC
");

$stmt->execute($params);
$services = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================================================
   STATISTICS
========================================================= */
$total = (int)$pdo->query("
    SELECT COUNT(*) FROM service_types
")->fetchColumn();

$active = (int)$pdo->query("
    SELECT COUNT(*) FROM service_types
    WHERE is_active = 1
")->fetchColumn();

$inactive = $total - $active;
?>

<!DOCTYPE html>
<html lang="en">
<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Service Types | Barangay Putatan</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <link rel="stylesheet"
          href="../assets/css/style.css">

</head>

<body class="admin-page">

<!-- =====================================================
     NAVBAR: SAME STRUCTURE AS DASHBOARD
===================================================== -->
<nav class="navbar navbar-dark barangay-navbar">
    <div class="container-fluid px-4">

        <a class="navbar-brand d-flex align-items-center gap-2"
           href="dashboard.php">

            <img src="../assets/images/barangay-logo.png"
                 alt="Barangay Logo"
                 class="navbar-logo">

            <span class="fw-bold">
                Barangay Putatan | Admin
            </span>
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

<!-- =====================================================
     MAIN LAYOUT
===================================================== -->
<div class="container-fluid">
    <div class="row">

        <!-- SIDEBAR: SAME STRUCTURE AS DASHBOARD -->
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
                   href="users.php">
                    <i class="bi bi-person-gear me-2"></i>User Management
                </a>

                <a class="nav-link admin-nav-link"
                   href="staff.php">
                    <i class="bi bi-person-badge me-2"></i>Staff Management
                </a>

                <a class="nav-link admin-nav-link active"
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

                <a href="audit-trail.php"
                   class="nav-link admin-nav-link">
                    <i class="bi bi-shield-check me-2"></i>Audit Trail
                </a>

                <a class="nav-link admin-nav-link"
                   href="settings.php">
                    <i class="bi bi-gear me-2"></i>Settings
                </a>

            </nav>

        </aside>

        <!-- =================================================
             MAIN CONTENT: SAME STRUCTURE AS DASHBOARD
        ================================================= -->
        <main class="col-md-9 col-lg-10 px-4 py-4">

            <!-- PAGE HEADER -->
            <div class="mb-4">

                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">

                    <div>
                        <h2 class="fw-bold text-primary-custom">
                            Service Types Management
                        </h2>

                        <p class="text-muted mb-0">
                            Create and manage barangay services available to residents.
                        </p>
                    </div>

                    <button class="btn btn-primary-custom"
                            data-bs-toggle="modal"
                            data-bs-target="#serviceModal"
                            onclick="prepareAdd()">

                        <i class="bi bi-plus-circle me-1"></i>
                        Add Service Type
                    </button>

                </div>

            </div>

            <!-- ALERT -->
            <?php if ($message !== ''): ?>

                <div class="alert alert-<?= htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8') ?> alert-dismissible fade show">

                    <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>

                    <button type="button"
                            class="btn-close"
                            data-bs-dismiss="alert"
                            aria-label="Close">
                    </button>

                </div>

            <?php endif; ?>

            <!-- STATISTICS -->
            <div class="row g-4 mb-4">

                <div class="col-sm-6 col-xl-4">
                    <div class="stat-card">

                        <small class="text-muted">
                            Total Services
                        </small>

                        <h2><?= number_format($total) ?></h2>

                    </div>
                </div>

                <div class="col-sm-6 col-xl-4">
                    <div class="stat-card">

                        <small class="text-muted">
                            Active Services
                        </small>

                        <h2><?= number_format($active) ?></h2>

                    </div>
                </div>

                <div class="col-sm-6 col-xl-4">
                    <div class="stat-card">

                        <small class="text-muted">
                            Inactive Services
                        </small>

                        <h2><?= number_format($inactive) ?></h2>

                    </div>
                </div>

            </div>

            <!-- SEARCH AND FILTER -->
            <div class="card border-0 shadow-sm mb-4">

                <div class="card-header bg-white py-3">

                    <h5 class="mb-0 fw-bold text-primary-custom">
                        <i class="bi bi-funnel me-2"></i>
                        Search and Filter Services
                    </h5>

                </div>

                <div class="card-body">

                    <form method="GET" class="row g-3 align-items-end">

                        <div class="col-md-6">

                            <label for="search" class="form-label">
                                Search Services
                            </label>

                            <input type="text"
                                   id="search"
                                   name="search"
                                   class="form-control"
                                   value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                                   placeholder="Search service name or description...">

                        </div>

                        <div class="col-md-3">

                            <label for="status" class="form-label">
                                Status
                            </label>

                            <select name="status"
                                    id="status"
                                    class="form-select">

                                <option value="">All Statuses</option>

                                <option value="active"
                                    <?= $statusFilter === 'active' ? 'selected' : '' ?>>
                                    Active
                                </option>

                                <option value="inactive"
                                    <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>
                                    Inactive
                                </option>

                            </select>

                        </div>

                        <div class="col-md-3 d-flex gap-2">

                            <button type="submit"
                                    class="btn btn-primary-custom">

                                <i class="bi bi-search me-1"></i>
                                Filter
                            </button>

                            <a href="service-types.php"
                               class="btn btn-outline-secondary">

                                Reset
                            </a>

                        </div>

                    </form>

                </div>

            </div>

            <!-- SERVICE TYPES TABLE -->
            <div class="card border-0 shadow-sm mb-4">

                <div class="card-header bg-white py-3">

                    <h5 class="mb-0 fw-bold text-primary-custom">
                        <i class="bi bi-list-check me-2"></i>
                        Service Types
                    </h5>

                </div>

                <div class="card-body">

                    <div class="table-responsive">

                        <table class="table table-hover align-middle">

                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Service</th>
                                    <th>Description</th>
                                    <th>Requirements</th>
                                    <th>Processing</th>
                                    <th>Fee</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php if (empty($services)): ?>

                                    <tr>
                                        <td colspan="8" class="text-center py-4">

                                            <i class="bi bi-inbox fs-3 d-block mb-2"></i>

                                            <span class="text-muted">
                                                No service types found.
                                            </span>

                                        </td>
                                    </tr>

                                <?php else: ?>

                                    <?php foreach ($services as $s): ?>

                                        <tr>

                                            <td>
                                                <?= (int)$s['service_type_id'] ?>
                                            </td>

                                            <td class="fw-semibold">
                                                <?= htmlspecialchars($s['service_name'], ENT_QUOTES, 'UTF-8') ?>
                                            </td>

                                            <td>
                                                <?= htmlspecialchars($s['description'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                            </td>

                                            <td>
                                                <?= htmlspecialchars($s['requirements'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                            </td>

                                            <td>
                                                <?= htmlspecialchars($s['processing_days'] ?? '0', ENT_QUOTES, 'UTF-8') ?>
                                                day(s)
                                            </td>

                                            <td>
                                                ₱<?= number_format((float)$s['service_fee'], 2) ?>
                                            </td>

                                            <td>

                                                <?php if ((int)$s['is_active'] === 1): ?>

                                                    <span class="badge bg-success">
                                                        Active
                                                    </span>

                                                <?php else: ?>

                                                    <span class="badge bg-secondary">
                                                        Inactive
                                                    </span>

                                                <?php endif; ?>

                                            </td>

                                            <td>

                                                <div class="d-flex gap-2">

                                                    <!-- EDIT -->
                                                    <button type="button"
                                                            class="btn btn-sm btn-outline-primary"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#serviceModal"
                                                            onclick='prepareEdit(<?= json_encode($s, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>

                                                        <i class="bi bi-pencil"></i>
                                                    </button>

                                                    <!-- ACTIVATE / DEACTIVATE -->
                                                    <form method="POST"
                                                          onsubmit="return confirm('Are you sure you want to <?= ((int)$s['is_active'] === 1) ? 'deactivate' : 'activate' ?> this service?');">

                                                        <input type="hidden"
                                                               name="csrf_token"
                                                               value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">

                                                        <input type="hidden"
                                                               name="action"
                                                               value="toggle">

                                                        <input type="hidden"
                                                               name="service_type_id"
                                                               value="<?= (int)$s['service_type_id'] ?>">

                                                        <button type="submit"
                                                                class="btn btn-sm btn-outline-<?= ((int)$s['is_active'] === 1) ? 'danger' : 'success' ?>">

                                                            <i class="bi bi-<?= ((int)$s['is_active'] === 1) ? 'pause-circle' : 'play-circle' ?>"></i>

                                                        </button>

                                                    </form>

                                                </div>

                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                <?php endif; ?>

                            </tbody>

                        </table>

                    </div>

                </div>

            </div>

        </main>

    </div>
</div>

<!-- =====================================================
     ADD / EDIT SERVICE MODAL
===================================================== -->
<div class="modal fade"
     id="serviceModal"
     tabindex="-1"
     aria-hidden="true">

    <div class="modal-dialog modal-lg modal-dialog-centered">

        <div class="modal-content">

            <form method="POST">

                <div class="modal-header">

                    <h5 class="modal-title fw-bold text-primary-custom"
                        id="modalTitle">

                        Add Service Type

                    </h5>

                    <button type="button"
                            class="btn-close"
                            data-bs-dismiss="modal"
                            aria-label="Close">
                    </button>

                </div>

                <div class="modal-body">

                    <input type="hidden"
                           name="csrf_token"
                           value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">

                    <input type="hidden"
                           name="action"
                           id="formAction"
                           value="create">

                    <input type="hidden"
                           name="service_type_id"
                           id="serviceTypeId">

                    <div class="row g-3">

                        <div class="col-md-8">

                            <label for="serviceName"
                                   class="form-label fw-semibold">

                                Service Name <span class="text-danger">*</span>

                            </label>

                            <input type="text"
                                   class="form-control"
                                   name="service_name"
                                   id="serviceName"
                                   maxlength="150"
                                   required>

                        </div>

                        <div class="col-md-4">

                            <label for="serviceFee"
                                   class="form-label fw-semibold">

                                Service Fee (₱)

                            </label>

                            <input type="number"
                                   class="form-control"
                                   name="service_fee"
                                   id="serviceFee"
                                   min="0"
                                   step="0.01"
                                   value="0"
                                   required>

                        </div>

                        <div class="col-md-12">

                            <label for="serviceDescription"
                                   class="form-label fw-semibold">

                                Description

                            </label>

                            <textarea class="form-control"
                                      name="description"
                                      id="serviceDescription"
                                      rows="3"></textarea>

                        </div>

                        <div class="col-md-12">

                            <label for="serviceRequirements"
                                   class="form-label fw-semibold">

                                Requirements

                            </label>

                            <textarea class="form-control"
                                      name="requirements"
                                      id="serviceRequirements"
                                      rows="3"
                                      placeholder="List the documents or requirements"></textarea>

                        </div>

                        <div class="col-md-4">

                            <label for="processingDays"
                                   class="form-label fw-semibold">

                                Processing Days

                            </label>

                            <input type="number"
                                   class="form-control"
                                   name="processing_days"
                                   id="processingDays"
                                   min="0"
                                   step="1"
                                   value="0"
                                   required>

                        </div>

                    </div>

                </div>

                <div class="modal-footer">

                    <button type="button"
                            class="btn btn-outline-secondary"
                            data-bs-dismiss="modal">

                        Cancel

                    </button>

                    <button type="submit"
                            class="btn btn-primary-custom">

                        <i class="bi bi-save me-1"></i>
                        Save Service

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>

/* =========================================================
   PREPARE ADD FORM
========================================================= */
function prepareAdd() {

    document.getElementById('modalTitle').textContent = 'Add Service Type';

    document.getElementById('formAction').value = 'create';

    document.getElementById('serviceTypeId').value = '';

    document.getElementById('serviceName').value = '';

    document.getElementById('serviceFee').value = '0';

    document.getElementById('serviceDescription').value = '';

    document.getElementById('serviceRequirements').value = '';

    document.getElementById('processingDays').value = '0';
}

/* =========================================================
   PREPARE EDIT FORM
========================================================= */
function prepareEdit(s) {

    document.getElementById('modalTitle').textContent = 'Edit Service Type';

    document.getElementById('formAction').value = 'update';

    document.getElementById('serviceTypeId').value = s.service_type_id;

    document.getElementById('serviceName').value = s.service_name || '';

    document.getElementById('serviceFee').value = s.service_fee || '0';

    document.getElementById('serviceDescription').value = s.description || '';

    document.getElementById('serviceRequirements').value = s.requirements || '';

    document.getElementById('processingDays').value = s.processing_days || '0';
}

</script>

</body>
</html>