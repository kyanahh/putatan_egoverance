
<?php
require_once '../config/database.php';
require_once '../includes/notification_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'resident') {
    header('Location: ../login.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];

// Retrieve resident profile
$stmt = $pdo->prepare("
    SELECT resident_id
    FROM resident_profiles
    WHERE user_id = ?
");
$stmt->execute([$user_id]);
$resident = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$resident) {
    exit('Resident profile not found.');
}

$resident_id = (int) $resident['resident_id'];

// Notification direct-navigation target
$target_request_id = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;

// Retrieve resident service requests
$stmt = $pdo->prepare("
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
        st.service_name
    FROM service_requests sr
    INNER JOIN service_types st
        ON sr.service_type_id = st.service_type_id
    WHERE sr.resident_id = ?
    ORDER BY sr.requested_at DESC
");

$stmt->execute([$resident_id]);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Retrieve status history for each request
$historyData = [];

$stmtHistory = $pdo->prepare("
    SELECT
        h.previous_status,
        h.new_status,
        h.remarks,
        h.changed_at,
        h.changed_by,
        u.full_name AS staff_name
    FROM request_status_history h
    LEFT JOIN users u
        ON h.changed_by = u.user_id
    WHERE h.request_id = ?
    ORDER BY h.changed_at ASC, h.history_id ASC
");

// Status labels
$statusLabels = [
    'submitted' => 'Submitted',
    'under_review' => 'Under Review',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'ready_for_release' => 'Ready for Release',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled'
];

// Badge colors
$statusColors = [
    'submitted' => 'primary',
    'under_review' => 'warning',
    'approved' => 'success',
    'rejected' => 'danger',
    'ready_for_release' => 'info',
    'completed' => 'success',
    'cancelled' => 'secondary'
];

// Retrieve history for each request
foreach ($requests as $request) {

    $stmtHistory->execute([$request['request_id']]);

    $historyData[$request['request_id']] =
        $stmtHistory->fetchAll(PDO::FETCH_ASSOC);
}

// Helpers
function requestStatusLabel($status, $statusLabels) {
    return $statusLabels[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

function requestStatusBadge($status, $statusColors, $statusLabels) {

    $color = $statusColors[$status] ?? 'secondary';
    $label = requestStatusLabel($status, $statusLabels);

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

    <title>My Service Requests | Barangay Putatan</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <link rel="stylesheet" href="../assets/css/style.css">

    <style>

        .request-timeline {
            position: relative;
            padding-left: 35px;
        }

        .timeline-item {
            position: relative;
            padding-bottom: 25px;
        }

        .timeline-item:not(:last-child)::before {
            content: '';
            position: absolute;
            left: -22px;
            top: 25px;
            width: 2px;
            height: calc(100% - 5px);
            background: #dee2e6;
        }

        .timeline-icon {
            position: absolute;
            left: -35px;
            top: 0;
            width: 26px;
            height: 26px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 13px;
        }

        .timeline-item:last-child {
            padding-bottom: 0;
        }

        .timeline-remarks {
            background: #f8f9fa;
            padding: 12px;
            border-radius: 8px;
            margin-top: 8px;
        }

        .request-details {
            white-space: pre-wrap;
            word-break: break-word;
        }

    </style>

</head>

<body class="resident-page">

<!-- NAVBAR -->
<nav class="navbar navbar-dark barangay-navbar">

    <div class="container-fluid px-4">

        <a class="navbar-brand d-flex align-items-center gap-2"
           href="dashboard.php">

            <img src="../assets/images/barangay-logo.png"
                 alt="Barangay Logo"
                 class="navbar-logo">

            <span class="fw-bold">
                Barangay Putatan
            </span>

        </a>

        <div class="d-flex gap-2">

            <a href="dashboard.php"
               class="btn btn-light btn-sm">
                Dashboard
            </a>

            <a href="../logout.php"
               class="btn btn-yellow btn-sm">
                Logout
            </a>

        </div>

    </div>

</nav>

<!-- MAIN CONTENT -->
<div class="container py-5">

    <div class="mb-4">

        <h2 class="fw-bold text-primary-custom">
            My Service Requests
        </h2>

        <p class="text-muted">
            Track and monitor the status of your submitted barangay service requests.
        </p>

        <a href="services.php"
           class="btn btn-primary-custom">
            Submit New Request
        </a>

    </div>

    <!-- REQUEST TABLE -->
    <div class="card border-0 shadow-sm">

        <div class="card-header bg-white py-3">

            <h5 class="mb-0 fw-bold text-primary-custom">
                Request History
            </h5>

        </div>

        <div class="card-body">

            <?php if (empty($requests)): ?>

                <div class="alert alert-light mb-0">
                    You have not submitted any service requests yet.
                </div>

            <?php else: ?>

                <div class="table-responsive">

                    <table class="table table-hover align-middle">

                        <thead>

                            <tr>
                                <th>Reference Number</th>
                                <th>Service</th>
                                <th>Date Submitted</th>
                                <th>Status</th>
                                <th>Details</th>
                            </tr>

                        </thead>

                        <tbody>

                        <?php foreach ($requests as $request): ?>

                            <tr class="<?= $target_request_id === (int)$request['request_id'] ? 'table-primary' : '' ?>" id="request-row-<?= (int)$request['request_id'] ?>">

                                <td>
                                    <strong>
                                        <?= htmlspecialchars(
                                            $request['reference_number'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </strong>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $request['service_name'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </td>

                                <td>
                                    <?= date(
                                        'M d, Y h:i A',
                                        strtotime($request['requested_at'])
                                    ) ?>
                                </td>

                                <td>
                                    <?= requestStatusBadge(
                                        $request['status'],
                                        $statusColors,
                                        $statusLabels
                                    ) ?>
                                </td>

                                <td>

                                    <button
                                        class="btn btn-outline-primary btn-sm"
                                        data-bs-toggle="modal"
                                        data-bs-target="#requestModal<?= (int)$request['request_id'] ?>">

                                        View

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

</div>

<!-- REQUEST DETAILS MODALS -->
<?php foreach ($requests as $request): ?>

<div class="modal fade"
     id="requestModal<?= (int)$request['request_id'] ?>"
     tabindex="-1"
     aria-hidden="true">

    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">

        <div class="modal-content">

            <div class="modal-header">

                <h5 class="modal-title fw-bold">
                    Service Request Details
                </h5>

                <button type="button"
                        class="btn-close"
                        data-bs-dismiss="modal">
                </button>

            </div>

            <div class="modal-body">

                <!-- REQUEST INFORMATION -->
                <div class="mb-4">

                    <h6 class="fw-bold text-primary-custom">
                        Request Information
                    </h6>

                    <hr>

                    <div class="mb-3">

                        <label class="fw-bold">
                            Reference Number:
                        </label>

                        <div>
                            <?= htmlspecialchars(
                                $request['reference_number'],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </div>

                    </div>

                    <div class="mb-3">

                        <label class="fw-bold">
                            Service:
                        </label>

                        <div>
                            <?= htmlspecialchars(
                                $request['service_name'],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </div>

                    </div>

                    <div class="mb-3">

                        <label class="fw-bold">
                            Date Submitted:
                        </label>

                        <div>
                            <?= date(
                                'M d, Y h:i A',
                                strtotime($request['requested_at'])
                            ) ?>
                        </div>

                    </div>

                    <div class="mb-3">

                        <label class="fw-bold">
                            Current Status:
                        </label>

                        <div>
                            <?= requestStatusBadge(
                                $request['status'],
                                $statusColors,
                                $statusLabels
                            ) ?>
                        </div>

                    </div>

                    <div class="mb-3">

                        <label class="fw-bold">
                            Purpose:
                        </label>

                        <div class="request-details">
                            <?= htmlspecialchars(
                                $request['purpose'],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </div>

                    </div>

                    <div class="mb-3">

                        <label class="fw-bold">
                            Additional Details:
                        </label>

                        <div class="request-details text-muted">
                            <?= htmlspecialchars(
                                $request['request_details'] ?: 'No additional details provided.',
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </div>

                    </div>

                </div>

                <!-- STATUS TRACKING -->
                <div class="mb-4">

                    <h6 class="fw-bold text-primary-custom">
                        Request Status Tracking
                    </h6>

                    <hr>

                    <?php
                    $history = $historyData[$request['request_id']] ?? [];
                    ?>

                    <?php if (empty($history)): ?>

                        <div class="alert alert-light">
                            Status history is not yet available.
                        </div>

                    <?php else: ?>

                        <div class="request-timeline">

                            <?php foreach ($history as $index => $entry): ?>

                                <?php
                                $historyStatus = $entry['new_status'];
                                $historyColor = $statusColors[$historyStatus] ?? 'secondary';

                                $icon = '✓';

                                if ($historyStatus === 'rejected') {
                                    $icon = '!';
                                } elseif ($historyStatus === 'cancelled') {
                                    $icon = '×';
                                } elseif ($historyStatus === 'under_review') {
                                    $icon = '↻';
                                }
                                ?>

                                <div class="timeline-item">

                                    <div class="timeline-icon bg-<?= htmlspecialchars($historyColor) ?>">
                                        <?= htmlspecialchars($icon) ?>
                                    </div>

                                    <div>

                                        <div class="fw-bold">

                                            <?= htmlspecialchars(
                                                requestStatusLabel(
                                                    $historyStatus,
                                                    $statusLabels
                                                ),
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>

                                        </div>

                                        <small class="text-muted">

                                            <?= date(
                                                'M d, Y h:i A',
                                                strtotime($entry['changed_at'])
                                            ) ?>

                                        </small>

                                        <?php if (!empty($entry['staff_name'])): ?>

                                            <div class="small text-muted mt-1">

                                                Updated by:
                                                <?= htmlspecialchars(
                                                    $entry['staff_name'],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>

                                            </div>

                                        <?php endif; ?>

                                        <?php if (!empty($entry['remarks'])): ?>

                                            <div class="timeline-remarks">

                                                <small class="fw-bold">
                                                    Remarks:
                                                </small>

                                                <div class="request-details">

                                                    <?= htmlspecialchars(
                                                        $entry['remarks'],
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ) ?>

                                                </div>

                                            </div>

                                        <?php endif; ?>

                                    </div>

                                </div>

                            <?php endforeach; ?>

                        </div>

                    <?php endif; ?>

                </div>

                <!-- CURRENT STAFF REMARKS -->
                <?php if (!empty($request['staff_remarks'])): ?>

                    <div class="alert alert-light border">

                        <h6 class="fw-bold mb-2">
                            Latest Staff Remarks
                        </h6>

                        <div class="request-details">

                            <?= htmlspecialchars(
                                $request['staff_remarks'],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>

                        </div>

                    </div>

                <?php endif; ?>

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

<?php if ($target_request_id > 0 && isset($historyData[$target_request_id]) && array_filter($requests, fn($r) => (int)$r['request_id'] === $target_request_id)): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const requestModal = document.getElementById('requestModal<?= $target_request_id ?>');
    const requestRow = document.getElementById('request-row-<?= $target_request_id ?>');

    if (requestModal) {
        const modal = new bootstrap.Modal(requestModal);
        modal.show();
    }

    if (requestRow) {
        requestRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
});
</script>
<?php endif; ?>

</body>

</html>
