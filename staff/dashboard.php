
<?php

require_once '../includes/staff_auth.php';

$staff_id = $_SESSION['user_id'];
$staff_name = $_SESSION['full_name'] ?? 'Staff Member';

$stats = [
    'total_requests' => 0,
    'pending_requests' => 0,
    'processing_requests' => 0,
    'completed_requests' => 0
];

$recent_requests = [];
$request_columns = [];

/*
|--------------------------------------------------------------------------
| FORMAT LABELS TO TITLE CASE
|--------------------------------------------------------------------------
*/
function staffFormatLabel($value): string
{
    $value = trim((string)($value ?? ''));

    if ($value === '') {
        return 'N/A';
    }

    $value = str_replace(['_', '-'], ' ', $value);

    return ucwords(strtolower($value));
}

/*
|--------------------------------------------------------------------------
| CHECK TABLE EXISTENCE
|--------------------------------------------------------------------------
*/
function staffTableExists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
        return $stmt && $stmt->fetch() !== false;
    } catch (PDOException $e) {
        return false;
    }
}

/*
|--------------------------------------------------------------------------
| GET TABLE COLUMNS
|--------------------------------------------------------------------------
*/
function staffGetColumns(PDO $pdo, string $table): array
{
    try {
        $stmt = $pdo->query("DESCRIBE `$table`");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return [];
    }
}

/*
|--------------------------------------------------------------------------
| FIND EXISTING COLUMN
|--------------------------------------------------------------------------
*/
function staffFindColumn(array $columns, array $possibleNames): ?string
{
    foreach ($possibleNames as $name) {
        if (in_array($name, $columns, true)) {
            return $name;
        }
    }

    return null;
}

/*
|--------------------------------------------------------------------------
| SERVICE REQUEST STATISTICS
|--------------------------------------------------------------------------
*/
if (staffTableExists($pdo, 'service_requests')) {

    $request_columns = staffGetColumns($pdo, 'service_requests');

    $status_column = staffFindColumn(
        $request_columns,
        ['status', 'request_status']
    );

    $assigned_column = staffFindColumn(
        $request_columns,
        ['staff_id', 'assigned_staff_id', 'assigned_to']
    );

    try {

        $where_clause = '';
        $params = [];

        /*
        |--------------------------------------------------------------------------
        | STAFF ASSIGNMENT FILTER
        |--------------------------------------------------------------------------
        */

        if ($assigned_column !== null) {
            $where_clause = "WHERE `$assigned_column` = ?";
            $params[] = $staff_id;
        }

        /*
        |--------------------------------------------------------------------------
        | TOTAL REQUESTS
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM service_requests $where_clause"
        );

        $stmt->execute($params);

        $stats['total_requests'] = (int)$stmt->fetchColumn();

        /*
        |--------------------------------------------------------------------------
        | REQUEST STATUS COUNTS
        |--------------------------------------------------------------------------
        */

        if ($status_column !== null) {

            $stmt = $pdo->prepare(
                "SELECT `$status_column`, COUNT(*) 
                 FROM service_requests
                 $where_clause
                 GROUP BY `$status_column`"
            );

            $stmt->execute($params);

            $status_counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            foreach ($status_counts as $status => $count) {

                $normalized_status = strtolower(
                    str_replace(
                        ['_', '-'],
                        ' ',
                        trim((string)$status)
                    )
                );

                if (
                    in_array(
                        $normalized_status,
                        ['pending', 'submitted', 'new', 'under review'],
                        true
                    )
                ) {
                    $stats['pending_requests'] += (int)$count;
                }

                if (
                    in_array(
                        $normalized_status,
                        ['processing', 'in progress', 'ongoing', 'under review', 'ready for release'],
                        true
                    )
                ) {
                    $stats['processing_requests'] += (int)$count;
                }

                if (
                    in_array(
                        $normalized_status,
                        ['completed', 'released', 'resolved'],
                        true
                    )
                ) {
                    $stats['completed_requests'] += (int)$count;
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | RECENT REQUESTS
        |--------------------------------------------------------------------------
        */

        $id_column = staffFindColumn(
            $request_columns,
            ['request_id', 'id']
        );

        $date_column = staffFindColumn(
            $request_columns,
            ['requested_at', 'created_at', 'request_date', 'date_requested']
        );

        $order_column = $date_column ?? $id_column;

        if ($order_column !== null) {

            $sql = "SELECT * FROM service_requests";

            if ($where_clause !== '') {
                $sql .= " $where_clause";
            }

            $sql .= " ORDER BY `$order_column` DESC LIMIT 5";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $recent_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

    } catch (PDOException $e) {

        $recent_requests = [];

    }
}

/*
|--------------------------------------------------------------------------
| DETERMINE DISPLAY COLUMNS
|--------------------------------------------------------------------------
*/
$request_id_column = staffFindColumn(
    $request_columns,
    ['request_id', 'id']
);

$request_status_column = staffFindColumn(
    $request_columns,
    ['status', 'request_status']
);

$request_date_column = staffFindColumn(
    $request_columns,
    ['requested_at', 'created_at', 'request_date', 'date_requested']
);

$request_type_column = staffFindColumn(
    $request_columns,
    ['request_type', 'service_name', 'document_type', 'purpose']
);

$request_user_column = staffFindColumn(
    $request_columns,
    ['user_id', 'resident_id']
);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Staff Dashboard | Barangay Putatan</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="../assets/css/style.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >

    <style>

        .staff-stat-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: transform 0.2s ease;
        }

        .staff-stat-card:hover {
            transform: translateY(-3px);
        }

        .staff-stat-icon {
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            font-size: 22px;
        }

        .staff-content-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .staff-table th {
            white-space: nowrap;
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

                <?php
                echo htmlspecialchars(
                    $staff_name,
                    ENT_QUOTES,
                    'UTF-8'
                );
                ?>

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
                    class="nav-link admin-nav-link active"
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

                    <i class="bi bi-speedometer2 me-2"></i>
                    Staff Dashboard

                </h2>

                <p class="text-muted mb-0">

                    Welcome to the Barangay Putatan Staff Portal.
                    Monitor and manage your service responsibilities.

                </p>

            </div>

            <!-- STATISTICS -->

            <div class="row g-4 mb-4">

                <!-- TOTAL REQUESTS -->

                <div class="col-md-6 col-xl-3">

                    <div class="card staff-stat-card h-100">

                        <div class="card-body">

                            <div class="d-flex justify-content-between align-items-center">

                                <div>

                                    <p class="text-muted mb-1">
                                        Total Requests
                                    </p>

                                    <h3 class="fw-bold mb-0">
                                        <?= number_format($stats['total_requests']) ?>
                                    </h3>

                                </div>

                                <div class="staff-stat-icon bg-primary bg-opacity-10 text-primary">

                                    <i class="bi bi-file-earmark-text"></i>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

                <!-- PENDING -->

                <div class="col-md-6 col-xl-3">

                    <div class="card staff-stat-card h-100">

                        <div class="card-body">

                            <div class="d-flex justify-content-between align-items-center">

                                <div>

                                    <p class="text-muted mb-1">
                                        Pending
                                    </p>

                                    <h3 class="fw-bold mb-0">
                                        <?= number_format($stats['pending_requests']) ?>
                                    </h3>

                                </div>

                                <div class="staff-stat-icon bg-warning bg-opacity-10 text-warning">

                                    <i class="bi bi-hourglass-split"></i>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

                <!-- PROCESSING -->

                <div class="col-md-6 col-xl-3">

                    <div class="card staff-stat-card h-100">

                        <div class="card-body">

                            <div class="d-flex justify-content-between align-items-center">

                                <div>

                                    <p class="text-muted mb-1">
                                        Processing
                                    </p>

                                    <h3 class="fw-bold mb-0">
                                        <?= number_format($stats['processing_requests']) ?>
                                    </h3>

                                </div>

                                <div class="staff-stat-icon bg-info bg-opacity-10 text-info">

                                    <i class="bi bi-arrow-repeat"></i>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

                <!-- COMPLETED -->

                <div class="col-md-6 col-xl-3">

                    <div class="card staff-stat-card h-100">

                        <div class="card-body">

                            <div class="d-flex justify-content-between align-items-center">

                                <div>

                                    <p class="text-muted mb-1">
                                        Completed
                                    </p>

                                    <h3 class="fw-bold mb-0">
                                        <?= number_format($stats['completed_requests']) ?>
                                    </h3>

                                </div>

                                <div class="staff-stat-icon bg-success bg-opacity-10 text-success">

                                    <i class="bi bi-check-circle"></i>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

            <!-- RECENT SERVICE REQUESTS -->

            <div class="card staff-content-card">

                <div class="card-header bg-white border-0 pt-4 px-4">

                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">

                        <div>

                            <h5 class="fw-bold mb-1">

                                <i class="bi bi-clock-history me-2"></i>
                                Recent Service Requests

                            </h5>

                            <p class="text-muted mb-0 small">

                                Latest service requests available in your portal.

                            </p>

                        </div>

                        <a
                            href="service-requests.php"
                            class="btn btn-primary btn-sm"
                        >

                            View All
                            <i class="bi bi-arrow-right ms-1"></i>

                        </a>

                    </div>

                </div>

                <div class="card-body px-4 pb-4">

                    <div class="table-responsive">

                        <table class="table table-hover align-middle staff-table">

                            <thead class="table-light">

                                <tr>

                                    <th>Request ID</th>
                                    <th>Request Type</th>
                                    <th>Resident ID</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                    <th>Action</th>

                                </tr>

                            </thead>

                            <tbody>

                                <?php if (!empty($recent_requests)): ?>

                                    <?php foreach ($recent_requests as $request): ?>

                                        <?php

                                        $request_id = $request_id_column
                                            ? ($request[$request_id_column] ?? 'N/A')
                                            : 'N/A';

                                        $request_type_raw = $request_type_column
                                            ? ($request[$request_type_column] ?? 'Service Request')
                                            : 'Service Request';

                                        $request_type = staffFormatLabel(
                                            $request_type_raw
                                        );

                                        $resident_id = $request_user_column
                                            ? ($request[$request_user_column] ?? 'N/A')
                                            : 'N/A';

                                        $status_raw = $request_status_column
                                            ? ($request[$request_status_column] ?? 'Unknown')
                                            : 'Unknown';

                                        $status = staffFormatLabel($status_raw);

                                        $normalized_status = strtolower(
                                            str_replace(
                                                ['_', '-'],
                                                ' ',
                                                trim((string)$status_raw)
                                            )
                                        );

                                        $date = $request_date_column
                                            ? ($request[$request_date_column] ?? null)
                                            : null;

                                        /*
                                        |--------------------------------------------------------------------------
                                        | STATUS BADGE
                                        |--------------------------------------------------------------------------
                                        */

                                        $badge_class = 'bg-secondary';

                                        if (
                                            in_array(
                                                $normalized_status,
                                                ['pending', 'submitted', 'new', 'under review'],
                                                true
                                            )
                                        ) {
                                            $badge_class = 'bg-warning text-dark';
                                        }

                                        if (
                                            in_array(
                                                $normalized_status,
                                                ['processing', 'in progress', 'ongoing', 'under review', 'ready for release'],
                                                true
                                            )
                                        ) {
                                            $badge_class = 'bg-info text-dark';
                                        }

                                        if (
                                            in_array(
                                                $normalized_status,
                                                ['completed', 'released', 'resolved'],
                                                true
                                            )
                                        ) {
                                            $badge_class = 'bg-success';
                                        }

                                        if (in_array($normalized_status, ['rejected', 'cancelled'], true)) {
                                            $badge_class = 'bg-danger';
                                        }

                                        ?>

                                        <tr>

                                            <td>

                                                <strong>
                                                    <?= htmlspecialchars((string)$request_id, ENT_QUOTES, 'UTF-8') ?>
                                                </strong>

                                            </td>

                                            <td>

                                                <?= htmlspecialchars((string)$request_type, ENT_QUOTES, 'UTF-8') ?>

                                            </td>

                                            <td>

                                                <?= htmlspecialchars((string)$resident_id, ENT_QUOTES, 'UTF-8') ?>

                                            </td>

                                            <td>

                                                <span class="badge <?= $badge_class ?>">

                                                    <?= htmlspecialchars((string)$status, ENT_QUOTES, 'UTF-8') ?>

                                                </span>

                                            </td>

                                            <td>

                                                <?php

                                                if ($date) {

                                                    $timestamp = strtotime((string)$date);

                                                    echo $timestamp
                                                        ? htmlspecialchars(date('M d, Y', $timestamp), ENT_QUOTES, 'UTF-8')
                                                        : htmlspecialchars((string)$date, ENT_QUOTES, 'UTF-8');

                                                } else {

                                                    echo 'N/A';

                                                }

                                                ?>

                                            </td>

                                            <td>

                                                <?php if ($request_id_column): ?>

                                                    <a
                                                        href="service-requests.php"
                                                        class="btn btn-sm btn-outline-primary"
                                                    >

                                                        <i class="bi bi-eye"></i>
                                                        Manage

                                                    </a>

                                                <?php else: ?>

                                                    <span class="text-muted">
                                                        N/A
                                                    </span>

                                                <?php endif; ?>

                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                <?php else: ?>

                                    <tr>

                                        <td colspan="6" class="text-center py-5">

                                            <i class="bi bi-inbox fs-1 text-muted"></i>

                                            <p class="text-muted mt-2 mb-0">

                                                No service requests found.

                                            </p>

                                        </td>

                                    </tr>

                                <?php endif; ?>

                            </tbody>

                        </table>

                    </div>

                </div>

            </div>

            <!-- FOOTER NOTE -->

            <div class="mt-4 text-muted small">

                <i class="bi bi-shield-check me-1"></i>

                Staff access is limited to authorized barangay operational functions.

            </div>

        </main>

    </div>

</div>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

</body>

</html>
