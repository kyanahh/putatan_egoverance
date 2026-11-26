
<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/notification_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'resident') {
    header("Location: ../login.php");
    exit;
}

// Fetch active services
$stmt = $pdo->prepare("
    SELECT *
    FROM service_types
    WHERE is_active = 1
    ORDER BY service_name ASC
");
$stmt->execute();
$services = $stmt->fetchAll(PDO::FETCH_ASSOC);

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function formatProcessingDays($days)
{
    $days = (int)$days;

    if ($days <= 1) {
        return '1 day';
    }

    return '1-' . $days . ' days';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Available Services | Barangay Putatan</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="../assets/css/style.css"
        rel="stylesheet"
    >

    <style>
        body {
            background-color: #f8f9fa;
        }

        .service-card {
            border: none;
            border-radius: 15px;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .service-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.08);
        }

        .service-description {
            min-height: 60px;
        }

        .service-details {
            background-color: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
        }
    </style>

</head>

<body class="bg-light">

<!-- NAVBAR -->
<nav class="navbar navbar-dark barangay-navbar">

    <div class="container-fluid">

        <a class="navbar-brand fw-bold" href="dashboard.php">

            <img
                src="../assets/images/barangay-logo.png"
                width="40"
                height="40"
                class="me-2"
                alt="Barangay Logo"
            >

            Barangay Putatan

        </a>

        <div>

            <a
                href="dashboard.php"
                class="btn btn-light btn-sm me-2"
            >
                Dashboard
            </a>

            <a
                href="../logout.php"
                class="btn btn-yellow btn-sm"
            >
                Logout
            </a>

        </div>

    </div>

</nav>

<!-- MAIN CONTENT -->
<div class="container py-5">

    <div class="mb-4">

        <h2 class="fw-bold text-primary-custom">
            Barangay Services
        </h2>

        <p class="text-muted">
            Browse available barangay services and submit your application online.
        </p>

    </div>

    <!-- SERVICES -->
    <div class="row g-4">

        <?php if (empty($services)): ?>

            <div class="col-12">

                <div class="alert alert-info">

                    No services are currently available.

                </div>

            </div>

        <?php else: ?>

            <?php foreach ($services as $service): ?>

                <div class="col-md-6 col-lg-4">

                    <div class="card service-card h-100 shadow-sm">

                        <div class="card-body d-flex flex-column">

                            <h5 class="card-title fw-bold text-primary-custom mb-3">

                                <?= e($service['service_name']) ?>

                            </h5>

                            <p class="text-muted service-description">

                                <?= e(
                                    $service['description']
                                    ?? 'No description available.'
                                ) ?>

                            </p>

                            <div class="service-details mb-3">

                                <div class="mb-2">

                                    <strong>
                                        Processing Time:
                                    </strong>

                                    <div>
                                        <?= e(
                                            formatProcessingDays(
                                                $service['processing_days']
                                            )
                                        ) ?>
                                    </div>

                                </div>

                                <div>

                                    <strong>
                                        Service Fee:
                                    </strong>

                                    <div>
                                        ₱<?= number_format(
                                            (float)$service['service_fee'],
                                            2
                                        ) ?>
                                    </div>

                                </div>

                            </div>

                            <?php if (!empty($service['requirements'])): ?>

                                <div class="mb-3">

                                    <strong>
                                        Requirements:
                                    </strong>

                                    <div class="small text-muted mt-1">

                                        <?= nl2br(
                                            e($service['requirements'])
                                        ) ?>

                                    </div>

                                </div>

                            <?php endif; ?>

                            <div class="mt-auto">

                                <a
                                    href="request-service.php?service_id=<?= (int)$service['service_type_id'] ?>"
                                    class="btn btn-primary w-100"
                                >

                                    Apply for This Service

                                </a>

                            </div>

                        </div>

                    </div>

                </div>

            <?php endforeach; ?>

        <?php endif; ?>

    </div>

</div>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

</body>
</html>