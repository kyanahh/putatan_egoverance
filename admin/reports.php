<?php
require_once '../includes/admin_auth.php';
require_once '../includes/audit_helper.php';
require_once '../includes/notification_helper.php';

/* =========================================================
   HELPERS
========================================================= */
function reports_escape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function reports_valid_date($date) {
    if (empty($date)) return false;
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

/* =========================================================
   DATE FILTER
========================================================= */
$start_date = $_GET['start_date'] ?? '';
$end_date   = $_GET['end_date'] ?? '';

if (!reports_valid_date($start_date)) $start_date = '';
if (!reports_valid_date($end_date)) $end_date = '';

/* Prevent reversed date range */
if ($start_date && $end_date && $start_date > $end_date) {
    $temp = $start_date;
    $start_date = $end_date;
    $end_date = $temp;
}

/* =========================================================
   DATE CONDITIONS
========================================================= */
$request_condition = '';
$feedback_condition = '';

$params = [];

if ($start_date && $end_date) {
    $request_condition = " WHERE DATE(sr.requested_at) BETWEEN :start_date AND :end_date";
    $feedback_condition = " WHERE DATE(cf.submitted_at) BETWEEN :start_date AND :end_date";

    $params = [
        ':start_date' => $start_date,
        ':end_date'   => $end_date
    ];

} elseif ($start_date) {
    $request_condition = " WHERE DATE(sr.requested_at) >= :start_date";
    $feedback_condition = " WHERE DATE(cf.submitted_at) >= :start_date";

    $params = [':start_date' => $start_date];

} elseif ($end_date) {
    $request_condition = " WHERE DATE(sr.requested_at) <= :end_date";
    $feedback_condition = " WHERE DATE(cf.submitted_at) <= :end_date";

    $params = [':end_date' => $end_date];
}

/* =========================================================
   SUMMARY STATISTICS
========================================================= */
$total_residents = 0;
$pending_residents = 0;
$total_staff = 0;
$total_requests = 0;
$total_feedback = 0;

try {

    /* Overall residents */
    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM users
        WHERE role = 'resident'
        AND account_status = 'approved'
    ");
    $total_residents = (int)$stmt->fetchColumn();

    /* Pending residents */
    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM users
        WHERE role = 'resident'
        AND account_status = 'pending'
    ");
    $pending_residents = (int)$stmt->fetchColumn();

    /* Overall staff */
    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM users
        WHERE role = 'staff'
        AND account_status = 'approved'
    ");
    $total_staff = (int)$stmt->fetchColumn();

    /* Service requests */
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM service_requests sr
        $request_condition
    ");
    $stmt->execute($params);
    $total_requests = (int)$stmt->fetchColumn();

    /* Citizen feedback */
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM citizen_feedback cf
        $feedback_condition
    ");
    $stmt->execute($params);
    $total_feedback = (int)$stmt->fetchColumn();

} catch (PDOException $e) {
    error_log("Reports summary error: " . $e->getMessage());
}

/* =========================================================
   SERVICE REQUESTS BY STATUS
========================================================= */
$request_status_data = [];

try {
    $stmt = $pdo->prepare("
        SELECT sr.status, COUNT(*) AS total
        FROM service_requests sr
        $request_condition
        GROUP BY sr.status
        ORDER BY total DESC
    ");

    $stmt->execute($params);
    $request_status_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Request status report error: " . $e->getMessage());
}

/* =========================================================
   SERVICE REQUESTS BY SERVICE TYPE
========================================================= */
$request_type_data = [];

try {

    $dateJoinCondition = '';

    if ($start_date && $end_date) {
        $dateJoinCondition = "
            AND DATE(sr.requested_at)
            BETWEEN :start_date AND :end_date
        ";
    } elseif ($start_date) {
        $dateJoinCondition = "
            AND DATE(sr.requested_at) >= :start_date
        ";
    } elseif ($end_date) {
        $dateJoinCondition = "
            AND DATE(sr.requested_at) <= :end_date
        ";
    }

    $stmt = $pdo->prepare("
        SELECT
            st.service_name,
            COUNT(sr.request_id) AS total
        FROM service_types st
        LEFT JOIN service_requests sr
            ON sr.service_type_id = st.service_type_id
            $dateJoinCondition
        GROUP BY st.service_type_id, st.service_name
        ORDER BY total DESC, st.service_name ASC
    ");

    $stmt->execute($params);
    $request_type_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Request type report error: " . $e->getMessage());
}

/* =========================================================
   FEEDBACK BY STATUS
========================================================= */
$feedback_status_data = [];

try {

    $stmt = $pdo->prepare("
        SELECT cf.status, COUNT(*) AS total
        FROM citizen_feedback cf
        $feedback_condition
        GROUP BY cf.status
        ORDER BY total DESC
    ");

    $stmt->execute($params);
    $feedback_status_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Feedback status report error: " . $e->getMessage());
}

/* =========================================================
   FEEDBACK BY CATEGORY
========================================================= */
$feedback_category_data = [];

try {

    $dateJoinCondition = '';

    if ($start_date && $end_date) {
        $dateJoinCondition = "
            AND DATE(cf.submitted_at)
            BETWEEN :start_date AND :end_date
        ";
    } elseif ($start_date) {
        $dateJoinCondition = "
            AND DATE(cf.submitted_at) >= :start_date
        ";
    } elseif ($end_date) {
        $dateJoinCondition = "
            AND DATE(cf.submitted_at) <= :end_date
        ";
    }

    $stmt = $pdo->prepare("
        SELECT
            fc.category_name,
            COUNT(cf.feedback_id) AS total
        FROM feedback_categories fc
        LEFT JOIN citizen_feedback cf
            ON cf.category_id = fc.category_id
            $dateJoinCondition
        GROUP BY fc.category_id, fc.category_name
        ORDER BY total DESC, fc.category_name ASC
    ");

    $stmt->execute($params);
    $feedback_category_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Feedback category report error: " . $e->getMessage());
}

/* =========================================================
   RECENT SERVICE REQUESTS
========================================================= */
$recent_requests = [];

try {

    $stmt = $pdo->prepare("
        SELECT
            sr.request_id,
            sr.status,
            sr.requested_at,
            st.service_name
        FROM service_requests sr
        LEFT JOIN service_types st
            ON sr.service_type_id = st.service_type_id
        $request_condition
        ORDER BY sr.requested_at DESC
        LIMIT 10
    ");

    $stmt->execute($params);
    $recent_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Recent requests report error: " . $e->getMessage());
}

/* =========================================================
   RECENT FEEDBACK
========================================================= */
$recent_feedback = [];

try {

    $stmt = $pdo->prepare("
        SELECT
            cf.feedback_id,
            cf.feedback_type,
            cf.status,
            cf.priority,
            cf.submitted_at
        FROM citizen_feedback cf
        $feedback_condition
        ORDER BY cf.submitted_at DESC
        LIMIT 10
    ");

    $stmt->execute($params);
    $recent_feedback = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Recent feedback report error: " . $e->getMessage());
}

/* =========================================================
   REPORT PERIOD
========================================================= */
if ($start_date && $end_date) {
    $report_period = date('F d, Y', strtotime($start_date))
        . ' - '
        . date('F d, Y', strtotime($end_date));
} elseif ($start_date) {
    $report_period = 'From ' . date('F d, Y', strtotime($start_date));
} elseif ($end_date) {
    $report_period = 'Until ' . date('F d, Y', strtotime($end_date));
} else {
    $report_period = 'All Records';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Reports | Barangay Putatan</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
      rel="stylesheet">

<link rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<link rel="stylesheet" href="../assets/css/style.css">

<style>

/* =========================================================
   SCREEN
========================================================= */
.report-table th {
    background-color: #f1f4f9;
    font-weight: 600;
    color: #142f86;
}

.report-table td,
.report-table th {
    padding: 12px;
    vertical-align: middle;
}

.report-section {
    margin-bottom: 28px;
}

.report-section-heading {
    color: #142f86;
    font-weight: bold;
    margin-bottom: 5px;
}

.report-section-description {
    color: #6c757d;
    margin-bottom: 18px;
}

.report-total {
    font-weight: bold;
    color: #142f86;
}

.print-header,
.print-signature {
    display: none;
}

/* =========================================================
   PRINT
========================================================= */
@media print {

    @page {
        size: A4 portrait;
        margin: 15mm;
    }

    html,
    body {
        background: #fff !important;
        color: #000 !important;
        font-family: Arial, Helvetica, sans-serif !important;
        font-size: 10pt !important;
    }

    .barangay-navbar,
    .admin-sidebar,
    .no-print,
    nav,
    form,
    .btn,
    .alert {
        display: none !important;
    }

    .container-fluid,
    .row,
    main {
        width: 100% !important;
        max-width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    main {
        display: block !important;
    }

    .print-header {
        display: block !important;
        text-align: center !important;
        margin-bottom: 25px !important;
    }

    .print-header-content {
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        gap: 15px !important;
    }

    .print-logo {
        width: 80px !important;
        height: 80px !important;
        object-fit: contain !important;
    }

    .print-header-text {
        text-align: center !important;
    }

    .print-header-text h4 {
        font-size: 10pt !important;
        font-weight: normal !important;
        margin: 2px 0 !important;
    }

    .print-header-text h2 {
        font-size: 16pt !important;
        font-weight: bold !important;
        margin: 4px 0 !important;
    }

    .print-header-text p {
        font-size: 8.5pt !important;
        margin: 2px 0 !important;
    }

    .print-header hr {
        border: 1px solid #000 !important;
        margin: 12px 0 !important;
    }

    .print-report-title {
        font-size: 18pt !important;
        font-weight: bold !important;
        text-align: center !important;
        margin: 10px 0 5px !important;
    }

    .print-report-subtitle,
    .print-period {
        text-align: center !important;
        font-size: 10pt !important;
        margin: 4px 0 !important;
    }

    .report-section-heading {
        color: #000 !important;
        font-size: 13pt !important;
        font-weight: bold !important;
        margin-bottom: 8px !important;
    }

    .report-section-description {
        color: #000 !important;
        margin-bottom: 12px !important;
    }

    .card {
        border: 1px solid #888 !important;
        box-shadow: none !important;
        border-radius: 0 !important;
        margin-bottom: 15px !important;
    }

    .card-header {
        background: #fff !important;
        border-bottom: 1px solid #888 !important;
        color: #000 !important;
        font-weight: bold !important;
        padding: 10px !important;
    }

    .card-body {
        padding: 10px !important;
    }

    table {
        width: 100% !important;
        border-collapse: collapse !important;
        font-size: 9pt !important;
    }

    th,
    td {
        border: 1px solid #777 !important;
        color: #000 !important;
        padding: 7px !important;
    }

    th {
        background: #eee !important;
        font-weight: bold !important;
    }

    thead {
        display: table-header-group !important;
    }

    tr {
        break-inside: avoid !important;
        page-break-inside: avoid !important;
    }

    .report-section {
        margin-bottom: 25px !important;
    }

    .print-signature {
        display: block !important;
        margin-top: 45px !important;
        color: #000 !important;
        break-inside: avoid !important;
        page-break-inside: avoid !important;
    }

    .generated-label {
        font-weight: bold !important;
        margin-bottom: 45px !important;
    }

    .signature-block {
        width: 300px !important;
        text-align: left !important;
    }

    .signature-line {
        border-bottom: 1px solid #000 !important;
        margin-bottom: 8px !important;
        height: 1px !important;
    }

    .signature-name {
        font-weight: bold !important;
        text-transform: uppercase !important;
        margin-bottom: 2px !important;
    }

    .signature-position,
    .signature-office {
        margin: 2px 0 !important;
    }

    .signature-caption {
        font-style: italic !important;
        font-size: 9pt !important;
        margin-top: 8px !important;
    }

    .print-generated-date {
        text-align: center !important;
        font-size: 9pt !important;
        margin-top: 20px !important;
    }

    .print-system-note {
        text-align: center !important;
        font-style: italic !important;
        font-size: 9pt !important;
        margin-top: 10px !important;
    }

    .text-primary-custom {
        color: #000 !important;
    }

    .report-total {
        color: #000 !important;
    }

    .table-responsive {
        overflow: visible !important;
    }

    .print-break-before {
        page-break-before: always !important;
        break-before: page !important;
    }
}

</style>
</head>

<body class="admin-page">

<!-- =========================================================
     NAVBAR
========================================================= -->
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
                <?= reports_escape($_SESSION['full_name'] ?? 'System Administrator') ?>
            </span>

            <a href="../logout.php" class="btn btn-yellow btn-sm">
                Logout
            </a>
        </div>

    </div>
</nav>

<div class="container-fluid">
<div class="row">

<!-- =========================================================
     SIDEBAR
========================================================= -->
<aside class="col-md-3 col-lg-2 admin-sidebar p-3">

    <small class="text-muted text-uppercase fw-bold">
        Administration
    </small>

    <nav class="nav flex-column gap-2 mt-3">

        <a class="nav-link admin-nav-link" href="dashboard.php">
            <i class="bi bi-grid me-2"></i>Dashboard
        </a>

        <a class="nav-link admin-nav-link" href="residents.php">
            <i class="bi bi-people me-2"></i>Resident Approvals
        </a>

        <a class="nav-link admin-nav-link" href="users.php">
            <i class="bi bi-person-gear me-2"></i>User Management
        </a>

        <a class="nav-link admin-nav-link" href="staff.php">
            <i class="bi bi-person-badge me-2"></i>Staff Management
        </a>

        <a class="nav-link admin-nav-link" href="service-types.php">
            <i class="bi bi-list-check me-2"></i>Service Types
        </a>

        <a class="nav-link admin-nav-link" href="service-requests.php">
            <i class="bi bi-card-checklist me-2"></i>Service Requests
        </a>

        <a class="nav-link admin-nav-link" href="announcements.php">
            <i class="bi bi-megaphone me-2"></i>Announcements
        </a>

        <a class="nav-link admin-nav-link" href="feedback.php">
            <i class="bi bi-chat-left-text me-2"></i>Complaints & Feedback
        </a>

        <a class="nav-link admin-nav-link" href="analytics.php">
            <i class="bi bi-bar-chart me-2"></i>Feedback Analytics
        </a>

        <a class="nav-link admin-nav-link active" href="reports.php">
            <i class="bi bi-file-earmark-bar-graph me-2"></i>Reports
        </a>

        <a href="audit-trail.php" class="nav-link admin-nav-link">
            <i class="bi bi-shield-check me-2"></i> Audit Trail
        </a>

        <a class="nav-link admin-nav-link" href="settings.php">
            <i class="bi bi-gear me-2"></i>Settings
        </a>

    </nav>

</aside>

<!-- =========================================================
     MAIN CONTENT
========================================================= -->
<main class="col-md-9 col-lg-10 px-4 py-4">

<!-- =========================================================
     PRINT HEADER
========================================================= -->
<div class="print-header">

    <div class="print-header-content">

        <img src="../assets/images/barangay-logo.png"
             class="print-logo"
             alt="Barangay Putatan Logo">

        <div class="print-header-text">

            <h4>REPUBLIC OF THE PHILIPPINES</h4>
            <h4>CITY OF MUNTINLUPA</h4>
            <h2>BARANGAY PUTATAN</h2>

            <p>Bruger St, Putatan, Muntinlupa City, 1772 Metro Manila</p>
            <p>(02) 8 862-0407 / (02) 8 862-4228</p>
            <p>putatan.muntinlupacity@gmail.com</p>

        </div>

    </div>

    <hr>

    <h2 class="print-report-title">BARANGAY REPORT</h2>
    <p class="print-report-subtitle">Administrative and Operational Report</p>
    <p class="print-period">
        Reporting Period: <?= reports_escape($report_period) ?>
    </p>

    <hr>

</div>

<!-- =========================================================
     SCREEN HEADER
========================================================= -->
<div class="mb-4 no-print">

    <h2 class="fw-bold text-primary-custom">
        Barangay Reports
    </h2>

    <p class="text-muted">
        Barangay Putatan E-Governance System reports and operational summaries.
    </p>

</div>

<!-- =========================================================
     FILTER
========================================================= -->
<div class="card border-0 shadow-sm mb-4 no-print">

    <div class="card-header bg-white py-3">

        <h5 class="mb-0 fw-bold text-primary-custom">
            <i class="bi bi-funnel me-2"></i>Report Filters
        </h5>

    </div>

    <div class="card-body">

        <form method="GET" class="row g-3 align-items-end">

            <div class="col-md-4">

                <label class="form-label">Start Date</label>

                <input type="date"
                       name="start_date"
                       class="form-control"
                       value="<?= reports_escape($start_date) ?>">

            </div>

            <div class="col-md-4">

                <label class="form-label">End Date</label>

                <input type="date"
                       name="end_date"
                       class="form-control"
                       value="<?= reports_escape($end_date) ?>">

            </div>

            <div class="col-md-4 d-flex gap-2 flex-wrap">

                <button type="submit" class="btn btn-primary-custom">
                    <i class="bi bi-filter me-1"></i>Apply Filter
                </button>

                <a href="reports.php" class="btn btn-outline-secondary">
                    Reset
                </a>

                <button type="button"
                        class="btn btn-yellow"
                        onclick="window.print()">
                    <i class="bi bi-printer me-1"></i>Print
                </button>

            </div>

        </form>

    </div>

</div>

<!-- =========================================================
     EXECUTIVE SUMMARY
========================================================= -->
<div class="report-section">

    <h4 class="report-section-heading">
        <i class="bi bi-clipboard-data me-2"></i>Executive Summary
    </h4>

    <p class="report-section-description">
        Summary of barangay administrative records and activities.
    </p>

    <div class="card border-0 shadow-sm">

        <div class="card-body">

            <table class="table table-bordered report-table mb-0">

                <thead>
                    <tr>
                        <th>Indicator</th>
                        <th class="text-center">Total</th>
                    </tr>
                </thead>

                <tbody>

                    <tr>
                        <td>Approved Residents</td>
                        <td class="text-center report-total">
                            <?= number_format($total_residents) ?>
                        </td>
                    </tr>

                    <tr>
                        <td>Pending Registrations</td>
                        <td class="text-center report-total">
                            <?= number_format($pending_residents) ?>
                        </td>
                    </tr>

                    <tr>
                        <td>Barangay Staff</td>
                        <td class="text-center report-total">
                            <?= number_format($total_staff) ?>
                        </td>
                    </tr>

                    <tr>
                        <td>Service Requests</td>
                        <td class="text-center report-total">
                            <?= number_format($total_requests) ?>
                        </td>
                    </tr>

                    <tr>
                        <td>Citizen Feedback</td>
                        <td class="text-center report-total">
                            <?= number_format($total_feedback) ?>
                        </td>
                    </tr>

                </tbody>

            </table>

        </div>

    </div>

</div>

<!-- =========================================================
     SERVICE REQUEST REPORTS
========================================================= -->
<div class="report-section">

    <h4 class="report-section-heading">
        <i class="bi bi-card-checklist me-2"></i>Service Request Reports
    </h4>

    <p class="report-section-description">
        Summary of service requests submitted to the barangay.
    </p>

    <!-- BY STATUS -->
    <div class="card border-0 shadow-sm mb-4">

        <div class="card-header bg-white py-3">
            Requests by Status
        </div>

        <div class="card-body">

            <table class="table table-bordered report-table mb-0">

                <thead>
                    <tr>
                        <th>Status</th>
                        <th class="text-center">Total Requests</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (empty($request_status_data)): ?>

                        <tr>
                            <td colspan="2" class="text-center">
                                No records found.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($request_status_data as $row): ?>

                            <tr>
                                <td>
                                    <?= reports_escape(ucwords(str_replace('_', ' ', $row['status']))) ?>
                                </td>

                                <td class="text-center report-total">
                                    <?= number_format((int)$row['total']) ?>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

    <!-- BY SERVICE TYPE -->
    <div class="card border-0 shadow-sm mb-4">

        <div class="card-header bg-white py-3">
            Requests by Service Type
        </div>

        <div class="card-body">

            <table class="table table-bordered report-table mb-0">

                <thead>
                    <tr>
                        <th>Service Type</th>
                        <th class="text-center">Total Requests</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (empty($request_type_data)): ?>

                        <tr>
                            <td colspan="2" class="text-center">
                                No records found.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($request_type_data as $row): ?>

                            <tr>
                                <td>
                                    <?= reports_escape($row['service_name']) ?>
                                </td>

                                <td class="text-center report-total">
                                    <?= number_format((int)$row['total']) ?>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

<!-- =========================================================
     CITIZEN FEEDBACK REPORTS
========================================================= -->
<div class="report-section">

    <h4 class="report-section-heading">
        <i class="bi bi-chat-left-text me-2"></i>Citizen Feedback Reports
    </h4>

    <p class="report-section-description">
        Summary of citizen complaints, suggestions, and feedback.
    </p>

    <!-- BY STATUS -->
    <div class="card border-0 shadow-sm mb-4">

        <div class="card-header bg-white py-3">
            Feedback by Status
        </div>

        <div class="card-body">

            <table class="table table-bordered report-table mb-0">

                <thead>
                    <tr>
                        <th>Status</th>
                        <th class="text-center">Total Feedback</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (empty($feedback_status_data)): ?>

                        <tr>
                            <td colspan="2" class="text-center">
                                No records found.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($feedback_status_data as $row): ?>

                            <tr>
                                <td>
                                    <?= reports_escape(ucwords(str_replace('_', ' ', $row['status']))) ?>
                                </td>

                                <td class="text-center report-total">
                                    <?= number_format((int)$row['total']) ?>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

    <!-- BY CATEGORY -->
    <div class="card border-0 shadow-sm mb-4">

        <div class="card-header bg-white py-3">
            Feedback by Category
        </div>

        <div class="card-body">

            <table class="table table-bordered report-table mb-0">

                <thead>
                    <tr>
                        <th>Feedback Category</th>
                        <th class="text-center">Total Feedback</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (empty($feedback_category_data)): ?>

                        <tr>
                            <td colspan="2" class="text-center">
                                No records found.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($feedback_category_data as $row): ?>

                            <tr>
                                <td>
                                    <?= reports_escape($row['category_name']) ?>
                                </td>

                                <td class="text-center report-total">
                                    <?= number_format((int)$row['total']) ?>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

<!-- =========================================================
     DETAILED SERVICE REQUEST RECORDS
========================================================= -->
<div class="report-section">

    <h4 class="report-section-heading">
        <i class="bi bi-list-ul me-2"></i>Detailed Service Request Records
    </h4>

    <div class="card border-0 shadow-sm mb-4">

        <div class="card-body">

            <div class="table-responsive">

                <table class="table table-bordered report-table mb-0">

                    <thead>
                        <tr>
                            <th>Request ID</th>
                            <th>Service Type</th>
                            <th>Status</th>
                            <th>Date Requested</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php if (empty($recent_requests)): ?>

                            <tr>
                                <td colspan="4" class="text-center">
                                    No service requests found for the selected period.
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($recent_requests as $request): ?>

                                <tr>

                                    <td>
                                        #<?= reports_escape($request['request_id']) ?>
                                    </td>

                                    <td>
                                        <?= reports_escape($request['service_name'] ?? 'N/A') ?>
                                    </td>

                                    <td>
                                        <?= reports_escape(ucwords(str_replace('_', ' ', $request['status']))) ?>
                                    </td>

                                    <td>
                                        <?= reports_escape(
                                            !empty($request['requested_at'])
                                            ? date('M d, Y', strtotime($request['requested_at']))
                                            : 'N/A'
                                        ) ?>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

</div>

<!-- =========================================================
     DETAILED FEEDBACK RECORDS
========================================================= -->
<div class="report-section">

    <h4 class="report-section-heading">
        <i class="bi bi-list-ul me-2"></i>Detailed Citizen Feedback Records
    </h4>

    <div class="card border-0 shadow-sm mb-4">

        <div class="card-body">

            <div class="table-responsive">

                <table class="table table-bordered report-table mb-0">

                    <thead>
                        <tr>
                            <th>Feedback ID</th>
                            <th>Type</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Date Submitted</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php if (empty($recent_feedback)): ?>

                            <tr>
                                <td colspan="5" class="text-center">
                                    No feedback found for the selected period.
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($recent_feedback as $feedback): ?>

                                <tr>

                                    <td>
                                        #<?= reports_escape($feedback['feedback_id']) ?>
                                    </td>

                                    <td>
                                        <?= reports_escape(ucwords(str_replace('_', ' ', $feedback['feedback_type']))) ?>
                                    </td>

                                    <td>
                                        <?= reports_escape(ucwords($feedback['priority'])) ?>
                                    </td>

                                    <td>
                                        <?= reports_escape(ucwords(str_replace('_', ' ', $feedback['status']))) ?>
                                    </td>

                                    <td>
                                        <?= reports_escape(
                                            !empty($feedback['submitted_at'])
                                            ? date('M d, Y', strtotime($feedback['submitted_at']))
                                            : 'N/A'
                                        ) ?>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

</div>

<!-- =========================================================
     SCREEN FOOTER
========================================================= -->
<div class="text-muted small text-center mt-4 no-print">

    Barangay Putatan E-Governance System<br>
    Report generated: <?= date('F d, Y h:i A') ?>

</div>

<!-- =========================================================
     PRINT SIGNATURE
========================================================= -->
<div class="print-signature">

    <p class="generated-label">
        Prepared and Generated by:
    </p>

    <div class="signature-block">

        <div class="signature-line"></div>

        <p class="signature-name">
            <?= reports_escape($_SESSION['full_name'] ?? 'System Administrator') ?>
        </p>

        <p class="signature-position">
            System Administrator
        </p>

        <p class="signature-office">
            Barangay Putatan
        </p>

        <p class="signature-caption">
            Signature over Printed Name
        </p>

    </div>

    <p class="print-generated-date">
        Generated on: <?= date('F d, Y h:i A') ?>
    </p>

    <p class="print-system-note">
        This report was generated electronically through the
        Barangay Putatan E-Governance System.
    </p>

</div>

</main>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>