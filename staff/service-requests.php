
<?php

require_once '../includes/staff_auth.php';
require_once '../includes/audit_helper.php';

/*
|--------------------------------------------------------------------------
| STATUS CONFIGURATION
|--------------------------------------------------------------------------
*/

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

/*
|--------------------------------------------------------------------------
| HELPER FUNCTIONS
|--------------------------------------------------------------------------
*/

function staffRequestEscape($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function staffRequestStatusBadge(
    string $status,
    array $statusColors,
    array $statusLabels
): string {

    $status = strtolower(trim($status));

    $status = str_replace(
        ['-', ' '],
        '_',
        $status
    );

    $color = $statusColors[$status] ?? 'secondary';

    $label = $statusLabels[$status]
        ?? staffRequestFormatLabel($status);

    return '<span class="badge bg-' . $color . '">' .
        htmlspecialchars($label, ENT_QUOTES, 'UTF-8') .
        '</span>';
}

function staffRequestFormatLabel($value): string
{
    $value = trim((string)($value ?? ''));

    if ($value === '') {
        return 'N/A';
    }

    $value = str_replace(['_', '-'], ' ', $value);

    return ucwords(strtolower($value));
}

function staffRequestResidentName(array $request): string
{
    return trim(
        ($request['first_name'] ?? '') . ' ' .
        (($request['middle_name'] ?? '') !== ''
            ? $request['middle_name'] . ' '
            : '') .
        ($request['last_name'] ?? '') .
        (($request['suffix'] ?? '') !== ''
            ? ' ' . $request['suffix']
            : '')
    );
}

/*
|--------------------------------------------------------------------------
| CSRF TOKEN
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/*
|--------------------------------------------------------------------------
| HANDLE STATUS UPDATE
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['update_request'])
) {

    if (
        !hash_equals(
            $_SESSION['csrf_token'],
            $_POST['csrf_token'] ?? ''
        )
    ) {

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

                /*
                |--------------------------------------------------------------------------
                | LOCK REQUEST
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    SELECT request_id, reference_number, status
                    FROM service_requests
                    WHERE request_id = ?
                    FOR UPDATE
                ");

                $stmt->execute([$request_id]);

                $currentRequest = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$currentRequest) {
                    throw new Exception('Service request not found.');
                }

                $previous_status = $currentRequest['status'];
                $reference_number = $currentRequest['reference_number'];

                if ($previous_status === $new_status) {
                    throw new Exception(
                        'The request already has this status.'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | UPDATE SERVICE REQUEST
                |--------------------------------------------------------------------------
                */

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

                /*
                |--------------------------------------------------------------------------
                | INSERT STATUS HISTORY
                |--------------------------------------------------------------------------
                */

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

                $pdo->commit();

                /*
                |--------------------------------------------------------------------------
                | AUDIT LOG
                |--------------------------------------------------------------------------
                */

                logAudit(
                    $pdo,
                    'UPDATE SERVICE REQUEST',
                    'Staff Service Requests',
                    "Staff updated service request {$reference_number} from " .
                    staffRequestFormatLabel($previous_status) .
                    " to " .
                    staffRequestFormatLabel($new_status) .
                    ".",
                    $user_id,
                    'staff'
                );

                $message = 'Service request updated successfully!';
                $messageType = 'success';

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $message = $e->getMessage();
                $messageType = 'danger';

            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| SEARCH AND FILTER
|--------------------------------------------------------------------------
*/

$search = trim($_GET['search'] ?? '');
$filter_status = $_GET['status'] ?? '';

/*
|--------------------------------------------------------------------------
| FETCH SERVICE REQUESTS
|--------------------------------------------------------------------------
*/

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
            OR st.service_name LIKE ?
        )
    ";

    $term = '%' . $search . '%';

    $params = [
        $term,
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

/*
|--------------------------------------------------------------------------
| FETCH REQUEST STATUS HISTORY
|--------------------------------------------------------------------------
*/

$requestHistory = [];

if (!empty($requests)) {

    $requestIds = array_column($requests, 'request_id');

    $placeholders = implode(
        ',',
        array_fill(0, count($requestIds), '?')
    );

    try {

        /*
        |--------------------------------------------------------------------------
        | DETECT HISTORY TABLE COLUMNS
        |--------------------------------------------------------------------------
        */

        $historyColumnsStmt = $pdo->query(
            "SHOW COLUMNS FROM request_status_history"
        );

        $historyColumns = $historyColumnsStmt->fetchAll(
            PDO::FETCH_COLUMN
        );

        /*
        |--------------------------------------------------------------------------
        | DETECT TIMESTAMP COLUMN
        |--------------------------------------------------------------------------
        */

        $historyDateColumn = null;

        $possibleDateColumns = [
            'created_at',
            'changed_at',
            'updated_at',
            'timestamp',
            'date_changed'
        ];

        foreach ($possibleDateColumns as $column) {

            if (in_array($column, $historyColumns, true)) {

                $historyDateColumn = $column;
                break;

            }
        }

        /*
        |--------------------------------------------------------------------------
        | DETECT USER COLUMN
        |--------------------------------------------------------------------------
        */

        $historyUserColumn = null;

        $possibleUserColumns = [
            'changed_by',
            'user_id',
            'updated_by'
        ];

        foreach ($possibleUserColumns as $column) {

            if (in_array($column, $historyColumns, true)) {

                $historyUserColumn = $column;
                break;

            }
        }

        /*
        |--------------------------------------------------------------------------
        | BUILD HISTORY QUERY
        |--------------------------------------------------------------------------
        */

        $historySelect = "
            SELECT
                h.request_id,
                h.previous_status,
                h.new_status,
                h.remarks
        ";

        if ($historyDateColumn !== null) {

            $historySelect .= ",
                h.`$historyDateColumn` AS history_date
            ";

        } else {

            $historySelect .= ",
                NULL AS history_date
            ";

        }

        if ($historyUserColumn !== null) {

            $historySelect .= ",
                h.`$historyUserColumn` AS history_user_id,
                u.full_name AS changed_by_name
            ";

        } else {

            $historySelect .= ",
                NULL AS history_user_id,
                NULL AS changed_by_name
            ";

        }

        $historySelect .= "
            FROM request_status_history h
        ";

        if ($historyUserColumn !== null) {

            $historySelect .= "
                LEFT JOIN users u
                    ON h.`$historyUserColumn` = u.user_id
            ";

        }

        $historySelect .= "
            WHERE h.request_id IN ($placeholders)
        ";

        if ($historyDateColumn !== null) {

            $historySelect .= "
                ORDER BY h.`$historyDateColumn` DESC
            ";

        }

        /*
        |--------------------------------------------------------------------------
        | EXECUTE HISTORY QUERY
        |--------------------------------------------------------------------------
        */

        $historyStmt = $pdo->prepare($historySelect);

        $historyStmt->execute($requestIds);

        $historyRecords = $historyStmt->fetchAll(
            PDO::FETCH_ASSOC
        );

        /*
        |--------------------------------------------------------------------------
        | GROUP HISTORY BY REQUEST ID
        |--------------------------------------------------------------------------
        */

        foreach ($historyRecords as $history) {

            $requestHistory[$history['request_id']][] = $history;

        }

    } catch (PDOException $e) {

        $requestHistory = [];

        error_log(
            'Staff request history error: ' . $e->getMessage()
        );

    }
}

/*
|--------------------------------------------------------------------------
| AUDIT PAGE VIEW
|--------------------------------------------------------------------------
*/

logAudit(
    $pdo,
    'VIEW SERVICE REQUESTS',
    'Staff Service Requests',
    'Staff viewed the service requests management page.',
    (int)$_SESSION['user_id'],
    'staff'
);

/*
|--------------------------------------------------------------------------
| STATISTICS
|--------------------------------------------------------------------------
*/

$totalRequests = count($requests);

$submittedCount = 0;
$underReviewCount = 0;
$processingCount = 0;
$completedCount = 0;

foreach ($requests as $request) {

    $status = strtolower(
        str_replace(
            ['_', '-'],
            ' ',
            trim((string)$request['status'])
        )
    );

    if (
        in_array(
            $status,
            ['submitted', 'under review'],
            true
        )
    ) {
        $submittedCount++;
    }

    if ($status === 'approved') {
        $processingCount++;
    }

    if ($status === 'ready for release') {
        $processingCount++;
    }

    if ($status === 'completed') {
        $completedCount++;
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Service Requests | Barangay Putatan Staff</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="../assets/css/style.css"
    >

    <style>

        .request-table th {
            white-space: nowrap;
        }

        .request-details {
            white-space: pre-wrap;
            word-break: break-word;
        }

        .staff-request-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .staff-request-stat-icon {
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            font-size: 22px;
        }

        .modal-header {
            background: var(--primary-color, #172f80);
            color: white;
        }

        .modal-header .btn-close {
            filter: invert(1);
        }

        .history-item {
            border-left: 3px solid #172f80;
            padding-left: 15px;
            margin-bottom: 20px;
        }

    </style>

</head>

<body class="admin-page">

<!-- NAVBAR -->

<nav class="navbar navbar-dark barangay-navbar">

    <div class="container-fluid px-4">

        <a
            class="navbar-brand d-flex align-items-center gap-2"
            href="dashboard.php"
        >

            <img
                src="../assets/images/barangay-logo.png"
                alt="Barangay Logo"
                class="navbar-logo"
            >

            <span class="fw-bold">
                Barangay Putatan | Staff
            </span>

        </a>

        <div class="d-flex align-items-center gap-3">

            <span class="text-white">

                <i class="bi bi-person-circle me-1"></i>

                <?= staffRequestEscape($_SESSION['full_name'] ?? 'Staff Member') ?>

            </span>

            <a
                href="../logout.php"
                class="btn btn-yellow btn-sm"
            >

                <i class="bi bi-box-arrow-right me-1"></i>
                Logout

            </a>

        </div>

    </div>

</nav>

<!-- MAIN LAYOUT -->

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
                    class="nav-link admin-nav-link active"
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
                    class="nav-link admin-nav-link"
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

            <!-- PAGE HEADER -->

            <div class="mb-4">

                <h2 class="fw-bold text-primary-custom">

                    <i class="bi bi-file-earmark-text me-2"></i>
                    Service Requests

                </h2>

                <p class="text-muted mb-0">

                    Review, monitor, and update resident barangay service requests.

                </p>

            </div>

            <!-- ALERT -->

            <?php if ($message !== ''): ?>

                <div class="alert alert-<?= staffRequestEscape($messageType) ?> alert-dismissible fade show">

                    <?= staffRequestEscape($message) ?>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="alert"
                    ></button>

                </div>

            <?php endif; ?>

            <!-- STATISTICS -->

            <div class="row g-4 mb-4">

                <!-- TOTAL -->

                <div class="col-md-6 col-xl-3">

                    <div class="card staff-request-card h-100">

                        <div class="card-body">

                            <div class="d-flex justify-content-between align-items-center">

                                <div>

                                    <p class="text-muted mb-1">
                                        Total Requests
                                    </p>

                                    <h3 class="fw-bold mb-0">
                                        <?= number_format($totalRequests) ?>
                                    </h3>

                                </div>

                                <div class="staff-request-stat-icon bg-primary bg-opacity-10 text-primary">

                                    <i class="bi bi-file-earmark-text"></i>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

                <!-- SUBMITTED / UNDER REVIEW -->

                <div class="col-md-6 col-xl-3">

                    <div class="card staff-request-card h-100">

                        <div class="card-body">

                            <div class="d-flex justify-content-between align-items-center">

                                <div>

                                    <p class="text-muted mb-1">
                                        For Review
                                    </p>

                                    <h3 class="fw-bold mb-0">
                                        <?= number_format($submittedCount) ?>
                                    </h3>

                                </div>

                                <div class="staff-request-stat-icon bg-warning bg-opacity-10 text-warning">

                                    <i class="bi bi-hourglass-split"></i>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

                <!-- PROCESSING -->

                <div class="col-md-6 col-xl-3">

                    <div class="card staff-request-card h-100">

                        <div class="card-body">

                            <div class="d-flex justify-content-between align-items-center">

                                <div>

                                    <p class="text-muted mb-1">
                                        Approved / Release
                                    </p>

                                    <h3 class="fw-bold mb-0">
                                        <?= number_format($processingCount) ?>
                                    </h3>

                                </div>

                                <div class="staff-request-stat-icon bg-info bg-opacity-10 text-info">

                                    <i class="bi bi-arrow-repeat"></i>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

                <!-- COMPLETED -->

                <div class="col-md-6 col-xl-3">

                    <div class="card staff-request-card h-100">

                        <div class="card-body">

                            <div class="d-flex justify-content-between align-items-center">

                                <div>

                                    <p class="text-muted mb-1">
                                        Completed
                                    </p>

                                    <h3 class="fw-bold mb-0">
                                        <?= number_format($completedCount) ?>
                                    </h3>

                                </div>

                                <div class="staff-request-stat-icon bg-success bg-opacity-10 text-success">

                                    <i class="bi bi-check-circle"></i>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

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
                                placeholder="Reference number, resident name, or service"
                                value="<?= staffRequestEscape($search) ?>"
                            >

                        </div>

                        <div class="col-lg-4">

                            <label class="form-label fw-semibold">
                                Filter Status
                            </label>

                            <select
                                name="status"
                                class="form-select"
                            >

                                <option value="">
                                    All Statuses
                                </option>

                                <?php foreach ($allowedStatuses as $status): ?>

                                    <option
                                        value="<?= staffRequestEscape($status) ?>"
                                        <?= $filter_status === $status ? 'selected' : '' ?>
                                    >

                                        <?= staffRequestEscape($statusLabels[$status]) ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <div class="col-lg-2 d-flex align-items-end">

                            <button
                                type="submit"
                                class="btn btn-primary w-100"
                            >

                                <i class="bi bi-search me-1"></i>
                                Search

                            </button>

                        </div>

                    </form>

                </div>

            </div>

            <!-- REQUEST TABLE -->

            <div class="card border-0 shadow-sm">

                <div class="card-header bg-white py-3">

                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">

                        <div>

                            <h5 class="mb-1 fw-bold text-primary-custom">

                                <i class="bi bi-list-ul me-2"></i>
                                Submitted Service Requests

                            </h5>

                            <p class="text-muted small mb-0">

                                Review resident applications and manage their request status.

                            </p>

                        </div>

                    </div>

                </div>

                <div class="card-body">

                    <?php if (empty($requests)): ?>

                        <div class="text-center py-5">

                            <i class="bi bi-inbox fs-1 text-muted"></i>

                            <p class="text-muted mt-2 mb-0">
                                No service requests found.
                            </p>

                        </div>

                    <?php else: ?>

                        <div class="table-responsive">

                            <table class="table table-hover align-middle request-table">

                                <thead class="table-light">

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

                                                    <?= staffRequestEscape($request['reference_number']) ?>

                                                </strong>

                                            </td>

                                            <td>

                                                <?= staffRequestEscape(staffRequestResidentName($request)) ?>

                                            </td>

                                            <td>

                                                <?= staffRequestEscape($request['service_name']) ?>

                                            </td>

                                            <td>

                                                <?php

                                                $request_timestamp = strtotime(
                                                    $request['requested_at']
                                                );

                                                echo $request_timestamp
                                                    ? date('M d, Y h:i A', $request_timestamp)
                                                    : 'N/A';

                                                ?>

                                            </td>

                                            <td>

                                                <?= staffRequestStatusBadge(
                                                    $request['status'],
                                                    $statusColors,
                                                    $statusLabels
                                                ) ?>

                                            </td>

                                            <td>

                                                <button
                                                    type="button"
                                                    class="btn btn-outline-primary btn-sm"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#requestModal<?= (int)$request['request_id'] ?>"
                                                >

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

            <!-- FOOTER -->

            <div class="mt-4 text-muted small">

                <i class="bi bi-shield-check me-1"></i>

                Service request actions are recorded in the system audit trail.

            </div>

        </main>

    </div>

</div>

<!-- REQUEST DETAILS MODALS -->

<?php foreach ($requests as $request): ?>

<div
    class="modal fade"
    id="requestModal<?= (int)$request['request_id'] ?>"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">

        <div class="modal-content">

            <!-- MODAL HEADER -->

            <div class="modal-header">

                <h5 class="modal-title fw-bold">

                    <i class="bi bi-file-earmark-text me-2"></i>
                    Service Request Details

                </h5>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>

            </div>

            <!-- MODAL BODY -->

            <div class="modal-body">

                <!-- REQUEST INFORMATION -->

                <div class="row g-3 mb-3">

                    <div class="col-md-6">

                        <label class="form-label fw-bold">
                            Reference Number
                        </label>

                        <div>

                            <?= staffRequestEscape($request['reference_number']) ?>

                        </div>

                    </div>

                    <div class="col-md-6">

                        <label class="form-label fw-bold">
                            Resident
                        </label>

                        <div>

                            <?= staffRequestEscape(staffRequestResidentName($request)) ?>

                        </div>

                    </div>

                    <div class="col-md-6">

                        <label class="form-label fw-bold">
                            Service
                        </label>

                        <div>

                            <?= staffRequestEscape($request['service_name']) ?>

                        </div>

                    </div>

                    <div class="col-md-6">

                        <label class="form-label fw-bold">
                            Date Submitted
                        </label>

                        <div>

                            <?php

                            $modal_timestamp = strtotime(
                                $request['requested_at']
                            );

                            echo $modal_timestamp
                                ? date('M d, Y h:i A', $modal_timestamp)
                                : 'N/A';

                            ?>

                        </div>

                    </div>

                </div>

                <hr>

                <!-- STATUS -->

                <div class="mb-3">

                    <label class="form-label fw-bold">
                        Current Status
                    </label>

                    <div>

                        <?= staffRequestStatusBadge(
                            $request['status'],
                            $statusColors,
                            $statusLabels
                        ) ?>

                    </div>

                </div>

                <!-- PURPOSE -->

                <div class="mb-3">

                    <label class="form-label fw-bold">
                        Purpose
                    </label>

                    <div class="request-details">

                        <?= staffRequestEscape($request['purpose']) ?>

                    </div>

                </div>

                <!-- ADDITIONAL DETAILS -->

                <div class="mb-3">

                    <label class="form-label fw-bold">
                        Additional Details
                    </label>

                    <div class="request-details text-muted">

                        <?= staffRequestEscape(
                            $request['request_details'] ?: 'No additional details provided.'
                        ) ?>

                    </div>

                </div>

                <!-- EXISTING REMARKS -->

                <div class="mb-3">

                    <label class="form-label fw-bold">
                        Current Staff Remarks
                    </label>

                    <div class="request-details text-muted">

                        <?= staffRequestEscape(
                            $request['staff_remarks'] ?: 'No remarks available.'
                        ) ?>

                    </div>

                </div>

                <hr>

                <!-- STATUS UPDATE -->

                <h5 class="fw-bold text-primary-custom mb-3">

                    <i class="bi bi-pencil-square me-2"></i>
                    Update Request

                </h5>

                <form method="POST">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= staffRequestEscape($_SESSION['csrf_token']) ?>"
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

                        <select
                            name="new_status"
                            class="form-select"
                            required
                        >

                            <?php foreach ($allowedStatuses as $status): ?>

                                <option
                                    value="<?= staffRequestEscape($status) ?>"
                                    <?= $request['status'] === $status ? 'selected' : '' ?>
                                >

                                    <?= staffRequestEscape($statusLabels[$status]) ?>

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
                        ><?= staffRequestEscape($request['staff_remarks'] ?? '') ?></textarea>

                        <small class="text-muted">

                            Remarks will be saved together with the status update and request history.

                        </small>

                    </div>

                    <button
                        type="submit"
                        name="update_request"
                        value="1"
                        class="btn btn-primary"
                    >

                        <i class="bi bi-save me-1"></i>
                        Save Status Update

                    </button>

                </form>

                <hr>

                <!-- REQUEST HISTORY -->

                <h5 class="fw-bold text-primary-custom mb-3">

                    <i class="bi bi-clock-history me-2"></i>
                    Request History

                </h5>

                <?php

                $history = $requestHistory[$request['request_id']] ?? [];

                ?>

                <?php if (!empty($history)): ?>

                    <?php foreach ($history as $historyItem): ?>

                        <div class="history-item">

                        <div class="fw-semibold">

                            <?= staffRequestEscape(
                                staffRequestFormatLabel(
                                    $historyItem['previous_status'] ?? ''
                                )
                            ) ?>

                            <i class="bi bi-arrow-right mx-1"></i>

                            <?= staffRequestEscape(
                                staffRequestFormatLabel(
                                    $historyItem['new_status'] ?? ''
                                )
                            ) ?>

                        </div>

                            <div class="small text-muted mt-1">

                                <i class="bi bi-person me-1"></i>

                                <?= staffRequestEscape(
                                    $historyItem['changed_by_name'] ?? 'Unknown User'
                                ) ?>

                            </div>

                            <div class="small text-muted">

                                <?php

                                $history_timestamp = !empty($historyItem['history_date'])
                                    ? strtotime($historyItem['history_date'])
                                    : false;

                                echo $history_timestamp
                                    ? date('M d, Y h:i A', $history_timestamp)
                                    : 'N/A';

                                ?>

                            </div>

                            <?php if (!empty($historyItem['remarks'])): ?>

                                <div class="mt-2">

                                    <strong>Remarks:</strong>

                                    <div class="request-details">

                                        <?= staffRequestEscape($historyItem['remarks']) ?>

                                    </div>

                                </div>

                            <?php endif; ?>

                        </div>

                    <?php endforeach; ?>

                <?php else: ?>

                    <div class="text-muted small">

                        No previous status history recorded.

                    </div>

                <?php endif; ?>

            </div>

            <!-- MODAL FOOTER -->

            <div class="modal-footer">

                <button
                    type="button"
                    class="btn btn-secondary"
                    data-bs-dismiss="modal"
                >

                    Close

                </button>

            </div>

        </div>

    </div>

</div>

<?php endforeach; ?>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

</body>
</html>
