<?php
require_once '../includes/admin_auth.php';
require_once '../includes/audit_helper.php';
require_once '../includes/notification_helper.php';

/* =========================================================
   PAGE CONFIGURATION
========================================================= */

$pageTitle = "System Settings";
$successMessage = "";
$errorMessage = "";

/* =========================================================
   CSRF TOKEN
========================================================= */

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* =========================================================
   FETCH SETTINGS
========================================================= */

$settings = [];

$stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

/* =========================================================
   DEFAULT SETTINGS
========================================================= */

$defaults = [
    'barangay_name' => 'Barangay Putatan',
    'barangay_address' => 'Putatan, Muntinlupa City, Philippines',
    'contact_number' => '',
    'email_address' => '',
    'system_name' => 'Barangay Putatan E-Governance System',
    'system_description' => 'Intelligent Barangay E-Governance System with Citizen Feedback Analytics',
    'maintenance_mode' => '0'
];

foreach ($defaults as $key => $value) {
    if (!isset($settings[$key])) {
        $settings[$key] = $value;
    }
}

/* =========================================================
   HELPER FUNCTION
========================================================= */

function settings_escape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* =========================================================
   UPDATE SETTINGS
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {

        $errorMessage = "Invalid request. Please refresh the page and try again.";

    } else {

        $barangay_name = trim($_POST['barangay_name'] ?? '');
        $barangay_address = trim($_POST['barangay_address'] ?? '');
        $contact_number = trim($_POST['contact_number'] ?? '');
        $email_address = trim($_POST['email_address'] ?? '');
        $system_name = trim($_POST['system_name'] ?? '');
        $system_description = trim($_POST['system_description'] ?? '');
        $maintenance_mode = isset($_POST['maintenance_mode']) ? '1' : '0';

        /* VALIDATION */

        if (
            $barangay_name === '' ||
            $barangay_address === '' ||
            $system_name === ''
        ) {

            $errorMessage = "Please fill in all required fields.";

        } elseif (
            $email_address !== '' &&
            !filter_var($email_address, FILTER_VALIDATE_EMAIL)
        ) {

            $errorMessage = "Please enter a valid email address.";

        } else {

            try {

                $pdo->beginTransaction();

                $updatedBy = isset($_SESSION['user_id'])
                    ? (int)$_SESSION['user_id']
                    : null;

                $newSettings = [
                    'barangay_name' => $barangay_name,
                    'barangay_address' => $barangay_address,
                    'contact_number' => $contact_number,
                    'email_address' => $email_address,
                    'system_name' => $system_name,
                    'system_description' => $system_description,
                    'maintenance_mode' => $maintenance_mode
                ];

                $updateStmt = $pdo->prepare("
                    INSERT INTO system_settings
                        (setting_key, setting_value, updated_by)
                    VALUES
                        (:setting_key, :setting_value, :updated_by)
                    ON DUPLICATE KEY UPDATE
                        setting_value = VALUES(setting_value),
                        updated_by = VALUES(updated_by)
                ");

                foreach ($newSettings as $key => $value) {

                    $updateStmt->execute([
                        ':setting_key' => $key,
                        ':setting_value' => $value,
                        ':updated_by' => $updatedBy
                    ]);
                }

                $pdo->commit();

                /* UPDATE LOCAL SETTINGS */

                $settings = array_merge($settings, $newSettings);

                /* AUDIT TRAIL */

                logAudit(
                    $pdo,
                    'UPDATE SETTINGS',
                    'Settings',
                    'Admin updated barangay and system configuration settings.'
                );

                $successMessage = "System settings successfully updated.";

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log('[Settings Update Error] ' . $e->getMessage());

                $errorMessage = "Unable to update settings. Please try again.";
            }
        }
    }
}

/* =========================================================
   AUDIT TRAIL - VIEW SETTINGS
========================================================= */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    logAudit(
        $pdo,
        'VIEW SETTINGS',
        'Settings',
        'Admin viewed the System Settings page.'
    );
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= settings_escape($pageTitle) ?> | Barangay Putatan</title>

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <!-- Bootstrap Icons -->
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <!-- Shared Stylesheet -->
    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        /* =====================================================
           SETTINGS PAGE
        ===================================================== */

        .settings-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.06);
            margin-bottom: 24px;
        }

        .settings-card .card-header {
            background: #ffffff;
            border-bottom: 1px solid #eee;
            font-weight: 600;
            padding: 18px 20px;
        }

        .settings-card .card-body {
            padding: 24px;
        }

        .settings-icon {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            background: #f1f5ff;
            color: #0d6efd;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            flex-shrink: 0;
        }

        .form-label {
            font-weight: 600;
        }

        .settings-description {
            color: #6c757d;
            font-size: 14px;
            font-weight: normal;
        }

        .maintenance-box {
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 18px;
        }

        .settings-save-btn {
            min-width: 160px;
        }

        .settings-section-title {
            font-weight: 600;
            margin-bottom: 4px;
        }

        @media (max-width: 767px) {

            .settings-card .card-body {
                padding: 18px;
            }

            .settings-save-btn {
                width: 100%;
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

            <span class="fw-bold">
                Barangay Putatan | Admin
            </span>

        </a>

        <div class="d-flex align-items-center gap-3">

            <span class="text-white">
                <?= settings_escape($_SESSION['full_name'] ?? 'Administrator') ?>
            </span>

            <a href="../logout.php"
               class="btn btn-yellow btn-sm">

                <i class="bi bi-box-arrow-right me-1"></i>
                Logout

            </a>

        </div>

    </div>

</nav>

<!-- =========================================================
     MAIN LAYOUT
========================================================= -->

<div class="container-fluid">

    <div class="row">

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

                <a class="nav-link admin-nav-link active"
                   href="settings.php">
                    <i class="bi bi-gear me-2"></i>Settings
                </a>

            </nav>

        </aside>

        <!-- =================================================
             MAIN CONTENT
        ================================================== -->

        <main class="col-md-9 col-lg-10 px-4 py-4">

            <!-- PAGE HEADER -->

            <div class="mb-4">

                <h2 class="fw-bold text-primary-custom">

                    <i class="bi bi-gear me-2"></i>
                    System Settings

                </h2>

                <p class="text-muted mb-0">
                    Manage barangay information and system configuration.
                </p>

            </div>

            <!-- =================================================
                 ALERT MESSAGES
            ================================================== -->

            <?php if ($successMessage): ?>

                <div class="alert alert-success alert-dismissible fade show"
                     role="alert">

                    <i class="bi bi-check-circle-fill me-2"></i>

                    <?= settings_escape($successMessage) ?>

                    <button type="button"
                            class="btn-close"
                            data-bs-dismiss="alert"
                            aria-label="Close">
                    </button>

                </div>

            <?php endif; ?>

            <?php if ($errorMessage): ?>

                <div class="alert alert-danger alert-dismissible fade show"
                     role="alert">

                    <i class="bi bi-exclamation-circle-fill me-2"></i>

                    <?= settings_escape($errorMessage) ?>

                    <button type="button"
                            class="btn-close"
                            data-bs-dismiss="alert"
                            aria-label="Close">
                    </button>

                </div>

            <?php endif; ?>

            <!-- =================================================
                 SETTINGS FORM
            ================================================== -->

            <form method="POST" action="settings.php">

                <input type="hidden"
                       name="csrf_token"
                       value="<?= settings_escape($_SESSION['csrf_token']) ?>">

                <!-- BARANGAY INFORMATION -->

                <div class="card settings-card">

                    <div class="card-header">

                        <div class="d-flex align-items-center">

                            <span class="settings-icon">
                                <i class="bi bi-building"></i>
                            </span>

                            <div>

                                <div class="settings-section-title">
                                    Barangay Information
                                </div>

                                <small class="settings-description">
                                    Official barangay contact and identification details.
                                </small>

                            </div>

                        </div>

                    </div>

                    <div class="card-body">

                        <div class="mb-3">

                            <label class="form-label">
                                Barangay Name
                                <span class="text-danger">*</span>
                            </label>

                            <input type="text"
                                   name="barangay_name"
                                   class="form-control"
                                   value="<?= settings_escape($settings['barangay_name']) ?>"
                                   required>

                        </div>

                        <div class="mb-3">

                            <label class="form-label">
                                Barangay Address
                                <span class="text-danger">*</span>
                            </label>

                            <textarea name="barangay_address"
                                      class="form-control"
                                      rows="2"
                                      required><?= settings_escape($settings['barangay_address']) ?></textarea>

                        </div>

                        <div class="row">

                            <div class="col-md-6 mb-3">

                                <label class="form-label">
                                    Contact Number
                                </label>

                                <input type="text"
                                       name="contact_number"
                                       class="form-control"
                                       value="<?= settings_escape($settings['contact_number']) ?>"
                                       placeholder="Enter barangay contact number">

                            </div>

                            <div class="col-md-6 mb-3">

                                <label class="form-label">
                                    Official Email Address
                                </label>

                                <input type="email"
                                       name="email_address"
                                       class="form-control"
                                       value="<?= settings_escape($settings['email_address']) ?>"
                                       placeholder="Enter official email address">

                            </div>

                        </div>

                    </div>

                </div>

                <!-- SYSTEM INFORMATION -->

                <div class="card settings-card">

                    <div class="card-header">

                        <div class="d-flex align-items-center">

                            <span class="settings-icon">
                                <i class="bi bi-display"></i>
                            </span>

                            <div>

                                <div class="settings-section-title">
                                    System Information
                                </div>

                                <small class="settings-description">
                                    Configure the system name and description.
                                </small>

                            </div>

                        </div>

                    </div>

                    <div class="card-body">

                        <div class="mb-3">

                            <label class="form-label">
                                System Name
                                <span class="text-danger">*</span>
                            </label>

                            <input type="text"
                                   name="system_name"
                                   class="form-control"
                                   value="<?= settings_escape($settings['system_name']) ?>"
                                   required>

                        </div>

                        <div class="mb-3">

                            <label class="form-label">
                                System Description
                            </label>

                            <textarea name="system_description"
                                      class="form-control"
                                      rows="3"><?= settings_escape($settings['system_description']) ?></textarea>

                        </div>

                    </div>

                </div>

                <!-- MAINTENANCE MODE -->

                <div class="card settings-card">

                    <div class="card-header">

                        <div class="d-flex align-items-center">

                            <span class="settings-icon">
                                <i class="bi bi-tools"></i>
                            </span>

                            <div>

                                <div class="settings-section-title">
                                    Maintenance Mode
                                </div>

                                <small class="settings-description">
                                    Manage the system maintenance setting.
                                </small>

                            </div>

                        </div>

                    </div>

                    <div class="card-body">

                        <div class="maintenance-box">

                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">

                                <div>

                                    <h6 class="fw-bold mb-1">
                                        Enable Maintenance Mode
                                    </h6>

                                    <p class="text-muted mb-0">
                                        This setting is saved in the database.
                                        It does not yet restrict resident access.
                                    </p>

                                </div>

                                <div class="form-check form-switch">

                                    <input class="form-check-input"
                                           type="checkbox"
                                           role="switch"
                                           id="maintenance_mode"
                                           name="maintenance_mode"
                                           value="1"
                                           <?= $settings['maintenance_mode'] === '1' ? 'checked' : '' ?>>

                                    <label class="form-check-label"
                                           for="maintenance_mode">

                                        <?= $settings['maintenance_mode'] === '1' ? 'Enabled' : 'Disabled' ?>

                                    </label>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

                <!-- ADMINISTRATOR INFORMATION -->

                <div class="card settings-card">

                    <div class="card-header">

                        <div class="d-flex align-items-center">

                            <span class="settings-icon">
                                <i class="bi bi-person-circle"></i>
                            </span>

                            <div>

                                <div class="settings-section-title">
                                    Administrator Information
                                </div>

                                <small class="settings-description">
                                    Information about the currently logged-in administrator.
                                </small>

                            </div>

                        </div>

                    </div>

                    <div class="card-body">

                        <div class="row">

                            <div class="col-md-6 mb-3">

                                <label class="form-label">
                                    Logged-in Administrator
                                </label>

                                <input type="text"
                                       class="form-control"
                                       value="<?= settings_escape($_SESSION['full_name'] ?? 'Administrator') ?>"
                                       readonly>

                            </div>

                            <div class="col-md-6 mb-3">

                                <label class="form-label">
                                    User ID
                                </label>

                                <input type="text"
                                       class="form-control"
                                       value="<?= settings_escape($_SESSION['user_id'] ?? '') ?>"
                                       readonly>

                            </div>

                        </div>

                        <p class="text-muted mb-0">

                            <i class="bi bi-info-circle me-1"></i>

                            Administrator account management is handled through User Management.

                        </p>

                    </div>

                </div>

                <!-- SAVE BUTTON -->

                <div class="d-flex justify-content-end mb-4">

                    <button type="submit"
                            class="btn btn-primary-custom settings-save-btn">

                        <i class="bi bi-save me-2"></i>
                        Save Settings

                    </button>

                </div>

            </form>

        </main>

    </div>

</div>

<!-- =========================================================
     SCRIPTS
========================================================= -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>