
<?php
require_once '../includes/admin_auth.php';
require_once '../includes/audit_helper.php';
require_once '../includes/notification_helper.php';

// Status configuration
$allowedStatuses = [
    'submitted',
    'under_review',
    'approved',
    'rejected',
    'ready_for_release',
    'completed',
    'cancelled'
];

$statusLabels = [
    'submitted' => 'Submitted',
    'under_review' => 'Under Review',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'ready_for_release' => 'Ready for Release',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled'
];

$statusColors = [
    'submitted' => 'primary',
    'under_review' => 'warning',
    'approved' => 'success',
    'rejected' => 'danger',
    'ready_for_release' => 'info',
    'completed' => 'success',
    'cancelled' => 'secondary'
];

$message = '';
$messageType = 'success';

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_request'])) {

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = 'Invalid form submission. Please try again.';
        $messageType = 'danger';
    } else {

        $request_id = (int) ($_POST['request_id'] ?? 0);
        $new_status = $_POST['new_status'] ?? '';
        $remarks = trim($_POST['remarks'] ?? '');
        $user_id = (int) $_SESSION['user_id'];

        if (!in_array($new_status, $allowedStatuses, true)) {
            $message = 'Invalid request status.';
            $messageType = 'danger';

        } elseif ($request_id <= 0) {
            $message = 'Invalid service request.';
            $messageType = 'danger';

        } else {

            try {
                $pdo->beginTransaction();

                // Lock request and retrieve resident information
                $stmt = $pdo->prepare("
                    SELECT 
                        sr.request_id,
                        sr.status,
                        sr.reference_number,
                        st.service_name,
                        rp.user_id AS resident_user_id
                    FROM service_requests sr
                    INNER JOIN service_types st
                        ON sr.service_type_id = st.service_type_id
                    INNER JOIN resident_profiles rp
                        ON sr.resident_id = rp.resident_id
                    WHERE sr.request_id = ?
                    FOR UPDATE
                ");

                $stmt->execute([$request_id]);
                $currentRequest = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$currentRequest) {
                    throw new Exception('Service request not found.');
                }

                $previous_status = $currentRequest['status'];
                $resident_user_id = (int) $currentRequest['resident_user_id'];
                $reference_number = $currentRequest['reference_number'];
                $service_name = $currentRequest['service_name'];

                if ($previous_status === $new_status) {
                    throw new Exception('The request already has this status.');
                }

                // Update request
                $stmt = $pdo->prepare("
                    UPDATE service_requests
                    SET status = ?,
                        processed_by = ?,
                        staff_remarks = ?,
                        completed_at = CASE
                            WHEN ? = 'completed' THEN NOW()
                            ELSE NULL
                        END
                    WHERE request_id = ?
                ");

                $stmt->execute([
                    $new_status,
                    $user_id,
                    $remarks !== '' ? $remarks : null,
                    $new_status,
                    $request_id
                ]);

                // Save status history
                $stmt = $pdo->prepare("
                    INSERT INTO request_status_history
                    (
                        request_id,
                        previous_status,
                        new_status,
                        remarks,
                        changed_by
                    )
                    VALUES (?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $request_id,
                    $previous_status,
                    $new_status,
                    $remarks !== '' ? $remarks : null,
                    $user_id
                ]);

                // Commit request update and history
                $pdo->commit();

                // Create resident notification AFTER successful commit
                $statusLabel = $statusLabels[$new_status] ?? ucfirst($new_status);

                $notificationMessage =
                    "Your service request ({$reference_number}) for {$service_name} " .
                    "has been updated to: {$statusLabel}.";

                if ($remarks !== '') {
                    $notificationMessage .= " Staff remarks: {$remarks}";
                }

                createNotification(
                    $pdo,
                    $resident_user_id,
                    'Service Request Update',
                    $notificationMessage,
                    'service_request',
                    $request_id
                );

                $message = 'Service request updated successfully!';
                $messageType = 'success';

            } catch (Exception $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $message = $e->getMessage();
                $messageType = 'danger';
            }
        }
    }
}

// Search and filter
$search = trim($_GET['search'] ?? '');
$filter_status = $_GET['status'] ?? '';

$sql = "
    SELECT
        sr.request_id,
        sr.reference_number,
        sr.purpose,
        sr.request_details,
        sr.status,
        sr.staff_remarks,
        sr.requested_at,
        sr.updated_at,
        sr.completed_at,
        st.service_name,
        rp.first_name,
        rp.middle_name,
        rp.last_name,
        rp.suffix,
        u.full_name AS account_name
    FROM service_requests sr
    INNER JOIN service_types st
        ON sr.service_type_id = st.service_type_id
    INNER JOIN resident_profiles rp
        ON sr.resident_id = rp.resident_id
    INNER JOIN users u
        ON rp.user_id = u.user_id
    WHERE 1=1
";

$params = [];

if ($search !== '') {
    $sql .= "
        AND (
            sr.reference_number LIKE ?
            OR rp.first_name LIKE ?
            OR rp.middle_name LIKE ?
            OR rp.last_name LIKE ?
            OR u.full_name LIKE ?
        )
    ";

    $term = '%' . $search . '%';

    $params = [
        $term,
        $term,
        $term,
        $term,
        $term
    ];
}

if (in_array($filter_status, $allowedStatuses, true)) {
    $sql .= " AND sr.status = ? ";
    $params[] = $filter_status;
}

$sql .= " ORDER BY sr.requested_at DESC ";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Resident name helper
function getResidentName($request) {
    return trim(
        $request['first_name'] . ' ' .
        ($request['middle_name'] ? $request['middle_name'] . ' ' : '') .
        $request['last_name'] .
        ($request['suffix'] ? ' ' . $request['suffix'] : '')
    );
}

// Status badge helper
function getStatusBadge($status, $statusColors, $statusLabels) {
    $color = $statusColors[$status] ?? 'secondary';
    $label = $statusLabels[$status] ?? ucfirst($status);

    return '<span class="badge bg-' . $color . '">' .
        htmlspecialchars($label, ENT_QUOTES, 'UTF-8') .
        '</span>';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Service Request Management | Barangay Putatan</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">
    
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        .request-table th {
            white-space: nowrap;
        }

        .request-details {
            white-space: pre-wrap;
            word-break: break-word;
        }

        .modal-header {
            background: var(--primary-color, #172f80);
            color: white;
        }

        .modal-header .btn-close {
            filter: invert(1);
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

        <!-- SIDEBAR -->
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

                <a class="nav-link admin-nav-link"
                   href="service-types.php">
                    <i class="bi bi-list-check me-2"></i>Service Types
                </a>

                <a class="nav-link admin-nav-link active"
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

        <!-- MAIN CONTENT -->
        <main class="col-md-9 col-lg-10 px-4 py-4">

            <div class="mb-4">

                <h2 class="fw-bold text-primary-custom">
                    Service Request Management
                </h2>

                <p class="text-muted">
                    Review, monitor, and manage resident barangay service requests.
                </p>

            </div>

            <!-- ALERT -->
            <?php if ($message !== ''): ?>

                <div class="alert alert-<?= htmlspecialchars($messageType) ?> alert-dismissible fade show">

                    <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>

                    <button type="button"
                            class="btn-close"
                            data-bs-dismiss="alert">
                    </button>

                </div>

            <?php endif; ?>

            <!-- SEARCH AND FILTER -->
            <div class="card border-0 shadow-sm mb-4">

                <div class="card-body">

                    <form method="GET" class="row g-3">

                        <div class="col-lg-6">

                            <label class="form-label fw-semibold">
                                Search Requests
                            </label>

                            <input
                                type="text"
                                name="search"
                                class="form-control"
                                placeholder="Reference number or resident name"
                                value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                            >

                        </div>

                        <div class="col-lg-4">

                            <label class="form-label fw-semibold">
                                Status
                            </label>

                            <select name="status" class="form-select">

                                <option value="">
                                    All Statuses
                                </option>

                                <?php foreach ($allowedStatuses as $status): ?>

                                    <option
                                        value="<?= htmlspecialchars($status) ?>"
                                        <?= $filter_status === $status ? 'selected' : '' ?>
                                    >
                                        <?= htmlspecialchars($statusLabels[$status]) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <div class="col-lg-2 d-flex align-items-end">

                            <button type="submit"
                                    class="btn btn-primary-custom w-100">
                                Search
                            </button>

                        </div>

                    </form>

                </div>

            </div>

            <!-- REQUEST TABLE -->
            <div class="card border-0 shadow-sm">

                <div class="card-header bg-white py-3">

                    <h5 class="mb-0 fw-bold text-primary-custom">
                        Submitted Service Requests
                    </h5>

                </div>

                <div class="card-body">

                    <?php if (empty($requests)): ?>

                        <div class="alert alert-light mb-0">
                            No service requests found.
                        </div>

                    <?php else: ?>

                        <div class="table-responsive">

                            <table class="table table-hover align-middle request-table">

                                <thead>
                                    <tr>
                                        <th>Reference Number</th>
                                        <th>Resident</th>
                                        <th>Service</th>
                                        <th>Date Submitted</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>

                                <tbody>

                                <?php foreach ($requests as $request): ?>

                                    <tr>

                                        <td>
                                            <strong>
                                                <?= htmlspecialchars($request['reference_number'], ENT_QUOTES, 'UTF-8') ?>
                                            </strong>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars(getResidentName($request), ENT_QUOTES, 'UTF-8') ?>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars($request['service_name'], ENT_QUOTES, 'UTF-8') ?>
                                        </td>

                                        <td>
                                            <?= date(
                                                'M d, Y h:i A',
                                                strtotime($request['requested_at'])
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= getStatusBadge(
                                                $request['status'],
                                                $statusColors,
                                                $statusLabels
                                            ) ?>
                                        </td>

                                        <td>

                                            <button
                                                type="button"
                                                class="btn btn-primary-custom btn-sm"
                                                data-bs-toggle="modal"
                                                data-bs-target="#requestModal<?= (int)$request['request_id'] ?>"
                                            >
                                                View / Update
                                            </button>

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

<!-- REQUEST MODALS -->
<?php foreach ($requests as $request): ?>

<div class="modal fade"
     id="requestModal<?= (int)$request['request_id'] ?>"
     tabindex="-1"
     aria-hidden="true">

    <div class="modal-dialog modal-lg modal-dialog-centered">

        <div class="modal-content">

            <div class="modal-header">

                <h5 class="modal-title">
                    Service Request Details
                </h5>

                <button type="button"
                        class="btn-close"
                        data-bs-dismiss="modal">
                </button>

            </div>

            <div class="modal-body">

                <div class="row g-3 mb-3">

                    <div class="col-md-6">

                        <label class="fw-bold">
                            Reference Number
                        </label>

                        <div>
                            <?= htmlspecialchars($request['reference_number'], ENT_QUOTES, 'UTF-8') ?>
                        </div>

                    </div>

                    <div class="col-md-6">

                        <label class="fw-bold">
                            Resident
                        </label>

                        <div>
                            <?= htmlspecialchars(getResidentName($request), ENT_QUOTES, 'UTF-8') ?>
                        </div>

                    </div>

                    <div class="col-md-6">

                        <label class="fw-bold">
                            Service
                        </label>

                        <div>
                            <?= htmlspecialchars($request['service_name'], ENT_QUOTES, 'UTF-8') ?>
                        </div>

                    </div>

                    <div class="col-md-6">

                        <label class="fw-bold">
                            Date Submitted
                        </label>

                        <div>
                            <?= date(
                                'M d, Y h:i A',
                                strtotime($request['requested_at'])
                            ) ?>
                        </div>

                    </div>

                </div>

                <div class="mb-3">

                    <label class="fw-bold">
                        Current Status
                    </label>

                    <div>
                        <?= getStatusBadge(
                            $request['status'],
                            $statusColors,
                            $statusLabels
                        ) ?>
                    </div>

                </div>

                <div class="mb-3">

                    <label class="fw-bold">
                        Purpose
                    </label>

                    <div class="request-details">
                        <?= htmlspecialchars($request['purpose'], ENT_QUOTES, 'UTF-8') ?>
                    </div>

                </div>

                <div class="mb-3">

                    <label class="fw-bold">
                        Additional Details
                    </label>

                    <div class="request-details text-muted">
                        <?= htmlspecialchars(
                            $request['request_details'] ?: 'No additional details provided.',
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </div>

                </div>

                <hr>

                <h5 class="fw-bold text-primary-custom">
                    Update Request Status
                </h5>

                <form method="POST">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>"
                    >

                    <input
                        type="hidden"
                        name="request_id"
                        value="<?= (int)$request['request_id'] ?>"
                    >

                    <div class="mb-3">

                        <label class="form-label fw-semibold">
                            New Status
                        </label>

                        <select name="new_status"
                                class="form-select"
                                required>

                            <?php foreach ($allowedStatuses as $status): ?>

                                <option
                                    value="<?= htmlspecialchars($status) ?>"
                                    <?= $request['status'] === $status ? 'selected' : '' ?>
                                >
                                    <?= htmlspecialchars($statusLabels[$status]) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="mb-3">

                        <label class="form-label fw-semibold">
                            Staff Remarks
                        </label>

                        <textarea
                            name="remarks"
                            class="form-control"
                            rows="3"
                            placeholder="Enter remarks or an update for the resident..."
                        ><?= htmlspecialchars($request['staff_remarks'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>

                    </div>

                    <button type="submit"
                            name="update_request"
                            value="1"
                            class="btn btn-primary-custom">

                        Save Status Update

                    </button>

                </form>

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

<?php endforeach; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
