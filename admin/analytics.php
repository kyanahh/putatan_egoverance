<?php
require_once '../includes/admin_auth.php';
require_once '../includes/audit_helper.php';
require_once '../includes/notification_helper.php';

/* =========================
   HELPER FUNCTION
========================= */

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* =========================
   AUDIT TRAIL
========================= */

logAudit(
    $pdo,
    'VIEW FEEDBACK ANALYTICS',
    'Feedback Analytics',
    'Admin viewed the feedback analytics dashboard.'
);

/* =========================
   SUMMARY STATISTICS
========================= */

$totalFeedback = (int)$pdo->query("
    SELECT COUNT(*) FROM citizen_feedback
")->fetchColumn();

$submittedCount = (int)$pdo->query("
    SELECT COUNT(*) FROM citizen_feedback
    WHERE status = 'submitted'
")->fetchColumn();

$underReviewCount = (int)$pdo->query("
    SELECT COUNT(*) FROM citizen_feedback
    WHERE status IN ('under_review', 'in_progress')
")->fetchColumn();

$resolvedCount = (int)$pdo->query("
    SELECT COUNT(*) FROM citizen_feedback
    WHERE status IN ('resolved', 'closed')
")->fetchColumn();

$urgentCount = (int)$pdo->query("
    SELECT COUNT(*) FROM citizen_feedback
    WHERE priority = 'urgent'
")->fetchColumn();

/* =========================
   FEEDBACK TYPE
========================= */

$typeStmt = $pdo->query("
    SELECT feedback_type, COUNT(*) AS total
    FROM citizen_feedback
    GROUP BY feedback_type
");

$typeLabels = [];
$typeData = [];

while ($row = $typeStmt->fetch(PDO::FETCH_ASSOC)) {
    $typeLabels[] = ucfirst($row['feedback_type']);
    $typeData[] = (int)$row['total'];
}

/* =========================
   STATUS DISTRIBUTION
========================= */

$statusStmt = $pdo->query("
    SELECT status, COUNT(*) AS total
    FROM citizen_feedback
    GROUP BY status
");

$statusLabels = [];
$statusData = [];

while ($row = $statusStmt->fetch(PDO::FETCH_ASSOC)) {
    $statusLabels[] = ucwords(str_replace('_', ' ', $row['status']));
    $statusData[] = (int)$row['total'];
}

/* =========================
   PRIORITY DISTRIBUTION
========================= */

$priorityStmt = $pdo->query("
    SELECT priority, COUNT(*) AS total
    FROM citizen_feedback
    GROUP BY priority
");

$priorityLabels = [];
$priorityData = [];

while ($row = $priorityStmt->fetch(PDO::FETCH_ASSOC)) {
    $priorityLabels[] = ucfirst($row['priority'] ?? 'Unspecified');
    $priorityData[] = (int)$row['total'];
}

/* =========================
   CATEGORY DISTRIBUTION
========================= */

$categoryStmt = $pdo->query("
    SELECT
        COALESCE(fc.category_name, 'Uncategorized') AS category_name,
        COUNT(cf.feedback_id) AS total
    FROM citizen_feedback cf
    LEFT JOIN feedback_categories fc
        ON cf.category_id = fc.category_id
    GROUP BY category_name
    ORDER BY total DESC
");

$categoryLabels = [];
$categoryData = [];

while ($row = $categoryStmt->fetch(PDO::FETCH_ASSOC)) {
    $categoryLabels[] = $row['category_name'];
    $categoryData[] = (int)$row['total'];
}

/* =========================
   MONTHLY TREND
========================= */

$monthlyStmt = $pdo->query("
    SELECT
        DATE_FORMAT(submitted_at, '%Y-%m') AS month_year,
        COUNT(*) AS total
    FROM citizen_feedback
    WHERE submitted_at >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
    GROUP BY month_year
    ORDER BY month_year ASC
");

$monthlyLabels = [];
$monthlyData = [];

while ($row = $monthlyStmt->fetch(PDO::FETCH_ASSOC)) {
    $monthlyLabels[] = date('M Y', strtotime($row['month_year'] . '-01'));
    $monthlyData[] = (int)$row['total'];
}

/* =========================
   AUTOMATED INSIGHTS
========================= */

$insights = [];

if ($totalFeedback === 0) {

    $insights[] = "No feedback records have been submitted yet.";

} else {

    if (!empty($typeData)) {
        $maxType = max($typeData);
        $maxTypeIndex = array_search($maxType, $typeData);

        $insights[] = "Most common feedback type: "
            . $typeLabels[$maxTypeIndex]
            . " (" . $maxType . " submission(s)).";
    }

    if (!empty($categoryData)) {
        $maxCategory = max($categoryData);
        $maxCategoryIndex = array_search($maxCategory, $categoryData);

        $insights[] = "Highest feedback category: "
            . $categoryLabels[$maxCategoryIndex]
            . " (" . $maxCategory . " submission(s)).";
    }

    if ($urgentCount > 0) {
        $insights[] = $urgentCount
            . " urgent feedback record(s) require attention.";
    } else {
        $insights[] = "No feedback records are currently marked urgent.";
    }

    if ($submittedCount + $underReviewCount > 0) {
        $insights[] = ($submittedCount + $underReviewCount)
            . " feedback record(s) are awaiting review or further action.";
    } else {
        $insights[] = "No feedback records are awaiting initial review.";
    }

    $resolutionRate = $totalFeedback > 0
        ? round(($resolvedCount / $totalFeedback) * 100, 1)
        : 0;

    $insights[] = "Resolution/closure rate: "
        . $resolutionRate . "%.";
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Feedback Analytics | Barangay Putatan Admin</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
          rel="stylesheet">

    <link rel="stylesheet" href="../assets/css/style.css">

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        /* ANALYTICS CONTENT ONLY */

        .analytics-chart-card {
            background: #fff;
            border: 0;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,.05);
            padding: 16px;
            height: 100%;
        }

        .analytics-chart-title {
            color: #183487;
            font-weight: 700;
            font-size: 15px;
            margin-bottom: 12px;
        }

        .analytics-chart-container {
            position: relative;
            height: 220px;
        }

        .analytics-chart-container.short {
            height: 200px;
        }

        .analytics-insights {
            background: #fff;
            border: 0;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,.05);
            padding: 16px;
        }

        .analytics-insight-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            font-size: 14px;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }

        .analytics-insight-item:last-child {
            border-bottom: none;
        }

        .analytics-insight-item i {
            color: #183487;
            margin-top: 2px;
        }

        .analytics-summary-card {
            min-height: 105px;
        }

        .analytics-summary-card .number {
            font-size: 28px;
            font-weight: 700;
            color: #183487;
        }

        .urgent-strip {
            border-radius: 10px;
            font-size: 14px;
        }

        @media (max-width: 768px) {
            .analytics-chart-container,
            .analytics-chart-container.short {
                height: 200px;
            }
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

            <span class="fw-bold">
                Barangay Putatan | Admin
            </span>

        </a>

        <div class="d-flex align-items-center gap-3">

            <span class="text-white d-none d-md-inline">
                <?= e($_SESSION['full_name']) ?>
            </span>

            <a href="../logout.php"
               class="btn btn-yellow btn-sm">
                Logout
            </a>

        </div>

    </div>

</nav>

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

                <a class="nav-link admin-nav-link active"
                   href="analytics.php">
                    <i class="bi bi-bar-chart me-2"></i>Feedback Analytics
                </a>

                <a class="nav-link admin-nav-link"
                   href="reports.php">
                    <i class="bi bi-file-earmark-bar-graph me-2"></i>Reports
                </a>

                <a href="audit-trail.php" class="nav-link admin-nav-link">
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
                    <i class="bi bi-bar-chart-line me-2"></i>
                    Feedback Analytics
                </h2>

                <p class="text-muted mb-0">
                    Citizen feedback overview and data-driven insights.
                </p>

            </div>

            <!-- SUMMARY CARDS -->
            <div class="row g-3 mb-4">

                <div class="col-sm-6 col-xl-3">

                    <div class="stat-card analytics-summary-card">

                        <small class="text-muted">
                            Total Feedback
                        </small>

                        <div class="d-flex justify-content-between align-items-center">

                            <div class="number">
                                <?= $totalFeedback ?>
                            </div>

                            <i class="bi bi-chat-square-text fs-3 text-primary"></i>

                        </div>

                    </div>

                </div>

                <div class="col-sm-6 col-xl-3">

                    <div class="stat-card analytics-summary-card">

                        <small class="text-muted">
                            Submitted
                        </small>

                        <div class="d-flex justify-content-between align-items-center">

                            <div class="number">
                                <?= $submittedCount ?>
                            </div>

                            <i class="bi bi-inbox fs-3 text-primary"></i>

                        </div>

                    </div>

                </div>

                <div class="col-sm-6 col-xl-3">

                    <div class="stat-card analytics-summary-card">

                        <small class="text-muted">
                            Under Review / In Progress
                        </small>

                        <div class="d-flex justify-content-between align-items-center">

                            <div class="number">
                                <?= $underReviewCount ?>
                            </div>

                            <i class="bi bi-hourglass-split fs-3 text-primary"></i>

                        </div>

                    </div>

                </div>

                <div class="col-sm-6 col-xl-3">

                    <div class="stat-card analytics-summary-card">

                        <small class="text-muted">
                            Resolved / Closed
                        </small>

                        <div class="d-flex justify-content-between align-items-center">

                            <div class="number">
                                <?= $resolvedCount ?>
                            </div>

                            <i class="bi bi-check-circle fs-3 text-primary"></i>

                        </div>

                    </div>

                </div>

            </div>

            <!-- URGENT FEEDBACK -->
            <div class="alert alert-danger urgent-strip py-2 px-3 mb-4">

                <div class="d-flex justify-content-between align-items-center">

                    <div>
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong>Urgent Feedback Requiring Attention</strong>
                    </div>

                    <strong class="fs-5">
                        <?= $urgentCount ?>
                    </strong>

                </div>

            </div>

            <!-- CHART ROW 1 -->
            <div class="row g-3 mb-4">

                <div class="col-xl-4 col-md-6">

                    <div class="analytics-chart-card">

                        <h6 class="analytics-chart-title">
                            Feedback by Type
                        </h6>

                        <div class="analytics-chart-container short">
                            <canvas id="typeChart"></canvas>
                        </div>

                    </div>

                </div>

                <div class="col-xl-4 col-md-6">

                    <div class="analytics-chart-card">

                        <h6 class="analytics-chart-title">
                            Feedback Status
                        </h6>

                        <div class="analytics-chart-container short">
                            <canvas id="statusChart"></canvas>
                        </div>

                    </div>

                </div>

                <div class="col-xl-4 col-md-12">

                    <div class="analytics-chart-card">

                        <h6 class="analytics-chart-title">
                            Feedback by Priority
                        </h6>

                        <div class="analytics-chart-container short">
                            <canvas id="priorityChart"></canvas>
                        </div>

                    </div>

                </div>

            </div>

            <!-- CHART ROW 2 -->
            <div class="row g-3 mb-4">

                <div class="col-xl-6">

                    <div class="analytics-chart-card">

                        <h6 class="analytics-chart-title">
                            Feedback by Category
                        </h6>

                        <div class="analytics-chart-container">
                            <canvas id="categoryChart"></canvas>
                        </div>

                    </div>

                </div>

                <div class="col-xl-6">

                    <div class="analytics-chart-card">

                        <h6 class="analytics-chart-title">
                            Monthly Feedback Trend
                        </h6>

                        <div class="analytics-chart-container">
                            <canvas id="monthlyChart"></canvas>
                        </div>

                    </div>

                </div>

            </div>

            <!-- AUTOMATED INSIGHTS -->
            <div class="analytics-insights mb-3">

                <h5 class="fw-bold text-primary-custom mb-2">
                    <i class="bi bi-lightbulb me-2"></i>
                    Automated Feedback Insights
                </h5>

                <p class="text-muted small mb-3">
                    Automatically generated observations based on recorded feedback.
                </p>

                <?php foreach ($insights as $insight): ?>

                    <div class="analytics-insight-item">

                        <i class="bi bi-graph-up-arrow"></i>

                        <span>
                            <?= e($insight) ?>
                        </span>

                    </div>

                <?php endforeach; ?>

            </div>

            <div class="text-muted small text-end mt-3">
                Analytics are based on feedback records currently stored in the system.
            </div>

        </main>

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>

/* PHP DATA */

const typeLabels = <?= json_encode($typeLabels) ?>;
const typeData = <?= json_encode($typeData) ?>;

const statusLabels = <?= json_encode($statusLabels) ?>;
const statusData = <?= json_encode($statusData) ?>;

const priorityLabels = <?= json_encode($priorityLabels) ?>;
const priorityData = <?= json_encode($priorityData) ?>;

const categoryLabels = <?= json_encode($categoryLabels) ?>;
const categoryData = <?= json_encode($categoryData) ?>;

const monthlyLabels = <?= json_encode($monthlyLabels) ?>;
const monthlyData = <?= json_encode($monthlyData) ?>;

/* COMMON OPTIONS */

const commonOptions = {
    responsive: true,
    maintainAspectRatio: false
};

/* TYPE */

new Chart(document.getElementById('typeChart'), {
    type: 'doughnut',
    data: {
        labels: typeLabels,
        datasets: [{
            data: typeData
        }]
    },
    options: {
        ...commonOptions,
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    boxWidth: 12,
                    padding: 12
                }
            }
        }
    }
});

/* STATUS */

new Chart(document.getElementById('statusChart'), {
    type: 'pie',
    data: {
        labels: statusLabels,
        datasets: [{
            data: statusData
        }]
    },
    options: {
        ...commonOptions,
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    boxWidth: 12,
                    padding: 12
                }
            }
        }
    }
});

/* PRIORITY */

new Chart(document.getElementById('priorityChart'), {
    type: 'bar',
    data: {
        labels: priorityLabels,
        datasets: [{
            label: 'Feedback',
            data: priorityData
        }]
    },
    options: {
        ...commonOptions,
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    precision: 0
                }
            }
        },
        plugins: {
            legend: {
                display: false
            }
        }
    }
});

/* CATEGORY */

new Chart(document.getElementById('categoryChart'), {
    type: 'bar',
    data: {
        labels: categoryLabels,
        datasets: [{
            label: 'Feedback',
            data: categoryData
        }]
    },
    options: {
        ...commonOptions,
        indexAxis: 'y',
        scales: {
            x: {
                beginAtZero: true,
                ticks: {
                    precision: 0
                }
            }
        },
        plugins: {
            legend: {
                display: false
            }
        }
    }
});

/* MONTHLY TREND */

new Chart(document.getElementById('monthlyChart'), {
    type: 'line',
    data: {
        labels: monthlyLabels,
        datasets: [{
            label: 'Feedback Submitted',
            data: monthlyData,
            tension: 0.3,
            fill: false
        }]
    },
    options: {
        ...commonOptions,
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    precision: 0
                }
            }
        }
    }
});

</script>

</body>
</html>