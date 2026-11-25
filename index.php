<?php
session_start();

if (isset($_SESSION['user_id'])) {
    switch ($_SESSION['role']) {
        case 'admin':
            header("Location: admin/dashboard.php");
            exit;
        case 'staff':
            header("Location: staff/dashboard.php");
            exit;
        case 'resident':
            header("Location: resident/dashboard.php");
            exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Barangay Putatan E-Governance System</title>

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark barangay-navbar">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-3" href="index.php">
            <img src="assets/images/barangay-logo.png"
                 alt="Barangay Putatan Logo"
                 class="navbar-logo">
            <span class="fw-bold">Barangay Putatan</span>
        </a>

        <div class="ms-auto d-flex gap-2">
            <a href="login.php" class="btn btn-outline-light">Login</a>
            <a href="register.php" class="btn btn-yellow">Register</a>
        </div>
    </div>
</nav>

<section class="hero-section">
    <div class="container">
        <div class="row align-items-center min-vh-75">

            <div class="col-lg-7 text-center text-lg-start">
                <span class="badge hero-badge mb-3">
                    BARANGAY PUTATAN • MUNTINLUPA CITY
                </span>

                <h1 class="hero-title">
                    Welcome to the<br>
                    <span>Barangay Putatan</span><br>
                    E-Governance System
                </h1>

                <p class="hero-description mt-4">
                    A digital platform for accessible barangay services,
                    community announcements, service requests, and citizen
                    feedback to help improve public service delivery.
                </p>

                <div class="mt-4 d-flex gap-3 flex-wrap justify-content-center justify-content-lg-start">
                    <a href="login.php" class="btn btn-primary btn-lg">
                        Access the System
                    </a>

                    <a href="register.php" class="btn btn-outline-primary btn-lg">
                        Create Resident Account
                    </a>
                </div>
            </div>

            <div class="col-lg-5 text-center mt-5 mt-lg-0">
                <div class="logo-container">
                    <img src="assets/images/barangay-logo.png"
                         alt="Official Barangay Putatan Logo"
                         class="hero-logo">
                </div>
            </div>

        </div>
    </div>
</section>

<section class="services-section py-5">
    <div class="container">

        <div class="text-center mb-5">
            <h2 class="section-title">Our Online Services</h2>
            <p class="text-muted">
                Convenient access to community services and information.
            </p>
        </div>

        <div class="row g-4">

            <div class="col-md-6 col-lg-3">
                <div class="service-card h-100">
                    <div class="service-icon">📄</div>
                    <h5>Service Requests</h5>
                    <p>
                        Submit and monitor requests for barangay documents
                        and other available services.
                    </p>
                </div>
            </div>

            <div class="col-md-6 col-lg-3">
                <div class="service-card h-100">
                    <div class="service-icon">📢</div>
                    <h5>Announcements</h5>
                    <p>
                        Stay updated with barangay announcements,
                        activities, and important information.
                    </p>
                </div>
            </div>

            <div class="col-md-6 col-lg-3">
                <div class="service-card h-100">
                    <div class="service-icon">💬</div>
                    <h5>Citizen Feedback</h5>
                    <p>
                        Send complaints, suggestions, and feedback
                        regarding community services.
                    </p>
                </div>
            </div>

            <div class="col-md-6 col-lg-3">
                <div class="service-card h-100">
                    <div class="service-icon">📊</div>
                    <h5>Community Insights</h5>
                    <p>
                        Support better decision-making through feedback
                        trends and service-related analytics.
                    </p>
                </div>
            </div>

        </div>
    </div>
</section>

<footer class="footer-section">
    <div class="container text-center py-4">
        <p class="mb-1 fw-semibold">
            Barangay Putatan E-Governance System
        </p>
        <small>
            Barangay Putatan, Muntinlupa City
        </small>
        <p class="mt-2 mb-0 small">
            &copy; <?php echo date("Y"); ?> Barangay Putatan. All rights reserved.
        </p>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>