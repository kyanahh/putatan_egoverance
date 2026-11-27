<?php
require_once '../includes/admin_auth.php';
require_once '../includes/audit_helper.php';
require_once '../includes/notification_helper.php';

/* =========================================================
   STATUS CONFIGURATION
========================================================= */
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

/* =========================================================
   CSRF TOKEN
========================================================= */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* =========================================================
   HANDLE SERVICE REQUEST STATUS UPDATE
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_request'])) {

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {

        $message = 'Invalid form submission. Please try again.';
        $messageType = 'danger';

    } else {

        $request_id = (int)($_POST['request_id'] ?? 0);
        $new_status = $_POST['new_status'] ?? '';
        $remarks = trim($_POST['remarks'] ?? '');
        $user_id = (int)$_SESSION['user_id'];

        if (!in_array($new_status, $allowedStatuses, true)) {

            $message = 'Invalid request status.';
            $messageType = 'danger';

        } elseif ($request_id <= 0) {

            $message = 'Invalid service request.';
            $messageType = 'danger';

        } else {

            try {

                $pdo->beginTransaction();

                /* -----------------------------------------
                   GET REQUEST DETAILS AND LOCK RECORD
                ----------------------------------------- */
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
                $resident_user_id = (int)$currentRequest['resident_user_id'];
                $reference_number = $currentRequest['reference_number'];
                $service_name = $currentRequest['service_name'];

                if ($previous_status === $new_status) {
                    throw new Exception('The request already has this status.');
                }

                /* -----------------------------------------
                   UPDATE SERVICE REQUEST
                ----------------------------------------- */
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

                /* -----------------------------------------
                   SAVE REQUEST STATUS HISTORY
                ----------------------------------------- */
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

                /* -----------------------------------------
                   COMMIT REQUEST UPDATE AND HISTORY
                ----------------------------------------- */
                $pdo->commit();

                /* -----------------------------------------
                   AUDIT TRAIL
                   LOG ONLY AFTER SUCCESSFUL COMMIT
                ----------------------------------------- */
                $previousStatusLabel = $statusLabels[$previous_status]
                    ?? ucfirst($previous_status);

                $newStatusLabel = $statusLabels[$new_status]
                    ?? ucfirst($new_status);

                $auditDescription =
                    'Administrator updated service request ' .
                    $reference_number .
                    ' for ' . $service_name .
                    ' from ' . $previousStatusLabel .
                    ' to ' . $newStatusLabel . '.';

                if ($remarks !== '') {
                    $auditDescription .= ' Remarks: ' . $remarks;
                }

                logAudit(
                    $pdo,
                    'UPDATE SERVICE REQUEST STATUS',
                    'Service Requests',
                    $auditDescription,
                    $user_id,
                    $_SESSION['role'] ?? 'admin'
                );

                /* -----------------------------------------
                   CREATE RESIDENT NOTIFICATION
                ----------------------------------------- */
                $statusLabel = $statusLabels[$new_status]
                    ?? ucfirst($new_status);

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

/* =========================================================
   SEARCH AND FILTER
========================================================= */
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
        u.full_name AS account_name,
        sra.application_type,
        sra.applicant_snapshot,
        sra.application_data
    FROM service_requests sr
    INNER JOIN service_types st
        ON sr.service_type_id = st.service_type_id
    INNER JOIN resident_profiles rp
        ON sr.resident_id = rp.resident_id
    INNER JOIN users u
        ON rp.user_id = u.user_id
    LEFT JOIN service_request_applications sra
        ON sr.request_id = sra.request_id
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

/* =========================================================
   RESIDENT NAME HELPER
========================================================= */
function getResidentName($request)
{
    return trim(
        $request['first_name'] . ' ' .
        ($request['middle_name'] ? $request['middle_name'] . ' ' : '') .
        $request['last_name'] .
        ($request['suffix'] ? ' ' . $request['suffix'] : '')
    );
}

/* =========================================================
   STATUS BADGE HELPER
========================================================= */
function getStatusBadge($status, $statusColors, $statusLabels)
{
    $color = $statusColors[$status] ?? 'secondary';
    $label = $statusLabels[$status] ?? ucfirst($status);

    return '<span class="badge bg-' . $color . '">' .
        htmlspecialchars($label, ENT_QUOTES, 'UTF-8') .
        '</span>';
}

/* =========================================================
   JSON APPLICATION DATA HELPERS
========================================================= */

function decodeApplicationJSON($json)
{
    if (empty($json)) {
        return [];
    }

    $decoded = json_decode($json, true);

    return is_array($decoded) ? $decoded : [];
}

function formatApplicationLabel($key)
{
    $key = str_replace(['_', '-'], ' ', $key);

    return ucwords($key);
}

function renderApplicationData($data)
{
    if (!is_array($data) || empty($data)) {
        return '<p class="text-muted mb-0">No information available.</p>';
    }

    $html = '<div class="table-responsive">';
    $html .= '<table class="table table-sm table-bordered align-middle mb-0">';
    $html .= '<tbody>';

    foreach ($data as $key => $value) {

        if (is_array($value)) {

            $html .= '<tr>';
            $html .= '<th colspan="2" class="table-light">';
            $html .= htmlspecialchars(
                formatApplicationLabel($key),
                ENT_QUOTES,
                'UTF-8'
            );
            $html .= '</th>';
            $html .= '</tr>';

            $html .= '<tr><td colspan="2">';
            $html .= renderApplicationData($value);
            $html .= '</td></tr>';

            continue;
        }

        if ($value === null || $value === '') {
            $value = 'Not provided';
        } elseif (is_bool($value)) {
            $value = $value ? 'Yes' : 'No';
        }

        $html .= '<tr>';

        $html .= '<th style="width:40%">';
        $html .= htmlspecialchars(
            formatApplicationLabel($key),
            ENT_QUOTES,
            'UTF-8'
        );
        $html .= '</th>';

        $html .= '<td>';
        $html .= htmlspecialchars(
            (string)$value,
            ENT_QUOTES,
            'UTF-8'
        );
        $html .= '</td>';

        $html .= '</tr>';
    }

    $html .= '</tbody>';
    $html .= '</table>';
    $html .= '</div>';

    return $html;
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

    <link rel="stylesheet"
          href="../assets/css/style.css">

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

<!-- =====================================================
     NAVBAR: SAME AS DASHBOARD
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

            <a href="../logout.php"
               class="btn btn-yellow btn-sm">
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

        <!-- MAIN CONTENT -->
        <main class="col-md-9 col-lg-10 px-4 py-4">

            <!-- PAGE HEADER -->
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

                <div class="alert alert-<?= htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8') ?> alert-dismissible fade show">

                    <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>

                    <button type="button"
                            class="btn-close"
                            data-bs-dismiss="alert"
                            aria-label="Close">
                    </button>

                </div>

            <?php endif; ?>

            <!-- SEARCH AND FILTER -->
            <div class="card border-0 shadow-sm mb-4">

                <div class="card-header bg-white py-3">

                    <h5 class="mb-0 fw-bold text-primary-custom">
                        <i class="bi bi-funnel me-2"></i>
                        Search and Filter Requests
                    </h5>

                </div>

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

                            <select name="status"
                                    class="form-select">

                                <option value="">
                                    All Statuses
                                </option>

                                <?php foreach ($allowedStatuses as $status): ?>

                                    <option
                                        value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"
                                        <?= $filter_status === $status ? 'selected' : '' ?>
                                    >
                                        <?= htmlspecialchars($statusLabels[$status], ENT_QUOTES, 'UTF-8') ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <div class="col-lg-2 d-flex align-items-end gap-2">

                            <button type="submit"
                                    class="btn btn-primary-custom w-100">

                                <i class="bi bi-search me-1"></i>
                                Search

                            </button>

                        </div>

                    </form>

                    <?php if ($search !== '' || $filter_status !== ''): ?>

                        <div class="mt-3">
                            <a href="service-requests.php"
                               class="btn btn-outline-secondary btn-sm">

                                <i class="bi bi-arrow-counterclockwise me-1"></i>
                                Reset Filters

                            </a>
                        </div>

                    <?php endif; ?>

                </div>

            </div>

            <!-- REQUEST TABLE -->
            <div class="card border-0 shadow-sm">

                <div class="card-header bg-white py-3">

                    <h5 class="mb-0 fw-bold text-primary-custom">
                        <i class="bi bi-card-checklist me-2"></i>
                        Submitted Service Requests
                    </h5>

                </div>

                <div class="card-body">

                    <?php if (empty($requests)): ?>

                        <div class="alert alert-light mb-0">
                            <i class="bi bi-inbox me-2"></i>
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
                                                    data-bs-target="#requestModal<?= (int)$request['request_id'] ?>">

                                                    <i class="bi bi-eye me-1"></i>
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

<!-- =====================================================
     REQUEST MODALS
===================================================== -->
<?php foreach ($requests as $request): ?>

<?php
    $applicantData = decodeApplicationJSON(
        $request['applicant_snapshot'] ?? ''
    );

    $applicationData = decodeApplicationJSON(
        $request['application_data'] ?? ''
    );
?>

<div class="modal fade"
     id="requestModal<?= (int)$request['request_id'] ?>"
     tabindex="-1"
     aria-hidden="true">

    <div class="modal-dialog modal-lg modal-dialog-centered">

        <div class="modal-content">

            <div class="modal-header">

                <h5 class="modal-title fw-bold">
                    <i class="bi bi-card-checklist me-2"></i>
                    Service Request Details
                </h5>

                <button type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close">
                </button>

            </div>

            <div class="modal-body">

            <!-- APPLICANT INFORMATION -->
            <div class="mb-4">

                <h5 class="fw-bold text-primary-custom mb-3">
                    <i class="bi bi-person-lines-fill me-2"></i>
                    Applicant Information
                </h5>

                <?php if (!empty($applicantData)): ?>

                    <?= renderApplicationData($applicantData) ?>

                <?php else: ?>

                    <div class="alert alert-light mb-0">
                        Applicant information snapshot is unavailable for this request.
                    </div>

                <?php endif; ?>

            </div>

            <hr>

            <!-- SERVICE-SPECIFIC APPLICATION DETAILS -->
            <div class="mb-4">

                <h5 class="fw-bold text-primary-custom mb-3">
                    <i class="bi bi-file-earmark-text me-2"></i>
                    Application Details
                </h5>

                <?php if (!empty($applicationData)): ?>

                    <?= renderApplicationData($applicationData) ?>

                <?php else: ?>

                    <div class="alert alert-light mb-0">
                        No additional application data is available.
                    </div>

                <?php endif; ?>

            </div>

            <hr>

                <!-- REQUEST INFORMATION -->
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

                <!-- CURRENT STATUS -->
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

                <!-- PURPOSE -->
                <div class="mb-3">

                    <label class="fw-bold">
                        Purpose
                    </label>

                    <div class="request-details">
                        <?= htmlspecialchars($request['purpose'], ENT_QUOTES, 'UTF-8') ?>
                    </div>

                </div>

                <!-- ADDITIONAL DETAILS -->
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

                <!-- =====================================================
                    PRINT CERTIFICATE
                ===================================================== -->

                <?php if (in_array($request['status'], ['approved', 'ready_for_release', 'completed'], true)): ?>

                    <div class="mb-4">

                        <div class="alert alert-success">

                            <i class="bi bi-file-earmark-check me-2"></i>

                            This request is approved for certificate printing.

                        </div>

                        <?php
                        $serviceNameForPrint = strtolower(trim($request['service_name']));

                        if (
                            strpos($serviceNameForPrint, 'business clearance') !== false ||
                            strpos($serviceNameForPrint, 'barangay business clearance') !== false
                        ) {
                            $printLink = 'print-business-clearance.php?request_id=' . (int)$request['request_id'];
                            $printLabel = 'Print Business Clearance';
                        } else {
                            $printLink = 'print-certificate.php?request_id=' . (int)$request['request_id'];
                            $printLabel = 'Print Document / Certificate';
                        }
                        ?>

                        <a
                            href="<?= htmlspecialchars($printLink, ENT_QUOTES, 'UTF-8') ?>"
                            target="_blank"
                            class="btn btn-success">

                            <i class="bi bi-printer me-2"></i>
                            <?= htmlspecialchars($printLabel, ENT_QUOTES, 'UTF-8') ?>

                        </a>

                    </div>

                <?php endif; ?>

                <hr>

                <!-- STATUS UPDATE FORM -->
                <h5 class="fw-bold text-primary-custom mb-3">
                    <i class="bi bi-arrow-repeat me-2"></i>
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
                                    value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"
                                    <?= $request['status'] === $status ? 'selected' : '' ?>
                                >
                                    <?= htmlspecialchars($statusLabels[$status], ENT_QUOTES, 'UTF-8') ?>
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
                            placeholder="Enter remarks or an update for the resident..."><?= htmlspecialchars($request['staff_remarks'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>

                    </div>

                    <button type="submit"
                            name="update_request"
                            value="1"
                            class="btn btn-primary-custom">

                        <i class="bi bi-save me-1"></i>
                        Save Status Update

                    </button>

                </form>

            </div>

            <div class="modal-footer">

                <button type="button"
                        class="btn btn-outline-secondary"
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