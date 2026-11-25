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

// Fetch active service types
$stmt = $pdo->prepare("
    SELECT *
    FROM service_types
    WHERE is_active = 1
    ORDER BY service_name ASC
");
$stmt->execute();
$services = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Available Services | Barangay Putatan</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>

<body class="bg-light">

<nav class="navbar navbar-dark barangay-navbar">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold" href="dashboard.php">
            <img src="../assets/images/barangay-logo.png"
                 width="40"
                 height="40"
                 class="me-2"
                 alt="Barangay Logo">
            Barangay Putatan
        </a>

        <div>
            <a href="dashboard.php" class="btn btn-light btn-sm me-2">
                Dashboard
            </a>

            <a href="../logout.php" class="btn btn-yellow btn-sm">
                Logout
            </a>
        </div>
    </div>
</nav>

<div class="container py-5">

    <div class="mb-4">
        <h2 class="fw-bold text-primary-custom">Barangay Services</h2>
        <p class="text-muted">
            Browse the available barangay services and submit your request online.
        </p>
    </div>

    <div class="row g-4">

        <?php if (empty($services)): ?>

            <div class="col-12">
                <div class="alert alert-info">
                    No services are currently available.
                </div>
            </div>

        <?php else: ?>

            <?php foreach ($services as $service): ?>

                <div class="col-md-6">

                    <div class="card h-100 shadow-sm border-0">

                        <div class="card-body d-flex flex-column">

                            <h5 class="card-title fw-bold text-primary-custom mb-2">
                                <?= htmlspecialchars($service['service_name']) ?>
                            </h5>

                            <p class="text-muted mb-3">
                                <?= htmlspecialchars($service['description'] ?? 'No description available.') ?>
                            </p>

                            <div class="row mb-3">

                                <div class="col-sm-6 mb-2">
                                    <strong>Processing Time:</strong><br>

                                    <?php if ((int)$service['processing_days'] === 1): ?>
                                        1 day
                                    <?php elseif ((int)$service['processing_days'] === 3): ?>
                                        1–3 days
                                    <?php else: ?>
                                        <?= (int)$service['processing_days'] ?> days
                                    <?php endif; ?>
                                </div>

                                <div class="col-sm-6 mb-2">
                                    <strong>Service Fee:</strong><br>
                                    ₱<?= number_format((float)$service['service_fee'], 2) ?>
                                </div>

                            </div>

                            <?php if (!empty($service['requirements'])): ?>

                                <div class="mb-3">
                                    <strong>Requirements:</strong>

                                    <p class="small mb-0">
                                        <?= nl2br(htmlspecialchars($service['requirements'])) ?>
                                    </p>
                                </div>

                            <?php endif; ?>

                            <div class="mt-auto">

                                <a href="request-service.php?service_id=<?= (int)$service['service_type_id'] ?>"
                                      class="btn btn-primary w-100">
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>