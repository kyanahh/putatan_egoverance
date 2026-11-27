
<?php
require_once '../includes/admin_auth.php';

$user_id = filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT);

if (!$user_id) {
    header('Location: resident-directory.php');
    exit;
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* =====================================================
   FETCH APPROVED RESIDENT
===================================================== */

$stmt = $pdo->prepare("
    SELECT
        u.user_id,
        u.username,
        u.email,
        u.account_status,
        rp.first_name,
        rp.middle_name,
        rp.last_name,
        rp.suffix,
        rp.birth_date,
        rp.sex,
        rp.civil_status,
        rp.contact_number,
        rp.occupation,
        rp.years_of_residency,
        rp.residency_since,
        rp.house_number,
        rp.street,
        rp.purok,
        rp.subdivision,
        rp.barangay,
        rp.city
    FROM users u
    INNER JOIN resident_profiles rp ON u.user_id = rp.user_id
    WHERE u.user_id = ?
      AND u.account_status = 'approved'
");

$stmt->execute([$user_id]);
$resident = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$resident) {
    header('Location: resident-directory.php');
    exit;
}

$message = '';
$message_type = 'danger';

/* =====================================================
   UPDATE RESIDENT + RECORD CHANGES
===================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $fields = [
        'first_name' => trim($_POST['first_name'] ?? ''),
        'middle_name' => trim($_POST['middle_name'] ?? ''),
        'last_name' => trim($_POST['last_name'] ?? ''),
        'suffix' => trim($_POST['suffix'] ?? ''),
        'birth_date' => $_POST['birth_date'] ?? '',
        'sex' => trim($_POST['sex'] ?? ''),
        'civil_status' => trim($_POST['civil_status'] ?? ''),
        'contact_number' => trim($_POST['contact_number'] ?? ''),
        'occupation' => trim($_POST['occupation'] ?? ''),
        'years_of_residency' => trim($_POST['years_of_residency'] ?? ''),
        'residency_since' => trim($_POST['residency_since'] ?? ''),
        'house_number' => trim($_POST['house_number'] ?? ''),
        'street' => trim($_POST['street'] ?? ''),
        'purok' => trim($_POST['purok'] ?? ''),
        'subdivision' => trim($_POST['subdivision'] ?? ''),
        'barangay' => trim($_POST['barangay'] ?? ''),
        'city' => trim($_POST['city'] ?? '')
    ];

    if (
        $fields['first_name'] === '' ||
        $fields['last_name'] === ''
    ) {

        $message = 'First name and last name are required.';

        $resident = array_merge($resident, $fields);

    } else {

        /* =============================================
           COMPARE OLD AND NEW VALUES
        ============================================= */

        $changes = [];

        foreach ($fields as $field => $newValue) {

            $oldValue = $resident[$field] ?? '';

            if ($oldValue === null) {
                $oldValue = '';
            }

            if ($newValue === null) {
                $newValue = '';
            }

            if ((string)$oldValue !== (string)$newValue) {

                $changes[$field] = [
                    'old' => $oldValue,
                    'new' => $newValue
                ];

            }
        }

        try {

            $pdo->beginTransaction();

            /* =============================================
               UPDATE RESIDENT PROFILE
            ============================================= */

            $update = $pdo->prepare("
                UPDATE resident_profiles
                SET
                    first_name = ?,
                    middle_name = ?,
                    last_name = ?,
                    suffix = ?,
                    birth_date = ?,
                    sex = ?,
                    civil_status = ?,
                    contact_number = ?,
                    occupation = ?,
                    years_of_residency = ?,
                    residency_since = ?,
                    house_number = ?,
                    street = ?,
                    purok = ?,
                    subdivision = ?,
                    barangay = ?,
                    city = ?
                WHERE user_id = ?
            ");

            $update->execute([
                $fields['first_name'],
                $fields['middle_name'],
                $fields['last_name'],
                $fields['suffix'],
                $fields['birth_date'] ?: null,
                $fields['sex'],
                $fields['civil_status'],
                $fields['contact_number'],
                $fields['occupation'],
                $fields['years_of_residency'] !== ''
                    ? $fields['years_of_residency']
                    : null,
                $fields['residency_since'] ?: null,
                $fields['house_number'],
                $fields['street'],
                $fields['purok'],
                $fields['subdivision'],
                $fields['barangay'],
                $fields['city'],
                $user_id
            ]);

            /* =============================================
               SAVE CHANGE HISTORY
            ============================================= */

            if (!empty($changes)) {

                $admin_user_id = $_SESSION['user_id']
                    ?? $_SESSION['admin_id']
                    ?? null;

                $historyStmt = $pdo->prepare("
                    INSERT INTO resident_profile_history
                    (
                        resident_user_id,
                        admin_user_id,
                        action_type,
                        changed_fields
                    )
                    VALUES (?, ?, 'UPDATE', ?)
                ");

                $historyStmt->execute([
                    $user_id,
                    $admin_user_id,
                    json_encode(
                        $changes,
                        JSON_UNESCAPED_UNICODE
                    )
                ]);

            }

            $pdo->commit();

            header('Location: resident-directory.php?updated=1');
            exit;

        } catch (Exception $ex) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $message = 'Unable to update resident information. Please try again.';

            /* Keep submitted information in the form */
            $resident = array_merge($resident, $fields);

        }

    }

}
?>

<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Edit Resident | Barangay Putatan</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
      rel="stylesheet">

<link rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<link rel="stylesheet" href="../assets/css/style.css">

<style>

.edit-card {
    border: none;
    border-radius: 14px;
    box-shadow: 0 3px 12px rgba(0,0,0,.04);
}

.section-heading {
    color: #173b8e;
    font-weight: bold;
    font-size: 16px;
    margin-bottom: 20px;
}

.form-label {
    font-weight: 600;
    font-size: 13px;
}

.form-control,
.form-select {
    border-radius: 9px;
}

</style>

</head>

<body class="admin-page">

<!-- =====================================================
     NAVBAR
===================================================== -->

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
                <?= e($_SESSION['full_name'] ?? 'Administrator') ?>
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

            <div class="mb-4">

                <h2 class="fw-bold text-primary-custom">
                    Edit Resident Information
                </h2>

                <p class="text-muted">
                    Update the personal and residency information of an approved resident.
                </p>

            </div>

            <?php if ($message !== ''): ?>

                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-circle me-2"></i>
                    <?= e($message) ?>
                </div>

            <?php endif; ?>

            <div class="card edit-card">

                <div class="card-header bg-white py-3">

                    <h5 class="fw-bold text-primary-custom mb-0">

                        <i class="bi bi-person-gear me-2"></i>

                        <?= e(
                            trim(
                                ($resident['first_name'] ?? '') . ' ' .
                                ($resident['last_name'] ?? '')
                            )
                        ) ?>

                    </h5>

                </div>

                <div class="card-body p-4">

                    <form method="POST">

                        <!-- PERSONAL INFORMATION -->

                        <h6 class="section-heading">
                            <i class="bi bi-person me-2"></i>
                            Personal Information
                        </h6>

                        <div class="row g-3 mb-4">

                            <div class="col-md-4">

                                <label class="form-label">
                                    First Name *
                                </label>

                                <input type="text"
                                       name="first_name"
                                       class="form-control"
                                       value="<?= e($resident['first_name'] ?? '') ?>"
                                       required>

                            </div>

                            <div class="col-md-4">

                                <label class="form-label">
                                    Middle Name
                                </label>

                                <input type="text"
                                       name="middle_name"
                                       class="form-control"
                                       value="<?= e($resident['middle_name'] ?? '') ?>">

                            </div>

                            <div class="col-md-4">

                                <label class="form-label">
                                    Last Name *
                                </label>

                                <input type="text"
                                       name="last_name"
                                       class="form-control"
                                       value="<?= e($resident['last_name'] ?? '') ?>"
                                       required>

                            </div>

                            <div class="col-md-3">

                                <label class="form-label">
                                    Suffix
                                </label>

                                <input type="text"
                                       name="suffix"
                                       class="form-control"
                                       value="<?= e($resident['suffix'] ?? '') ?>"
                                       placeholder="Jr., Sr., III">

                            </div>

                            <div class="col-md-3">

                                <label class="form-label">
                                    Birth Date
                                </label>

                                <input type="date"
                                       name="birth_date"
                                       class="form-control"
                                       value="<?= e($resident['birth_date'] ?? '') ?>">

                            </div>

                            <div class="col-md-3">

                                <label class="form-label">
                                    Sex
                                </label>

                                <select name="sex" class="form-select">

                                    <option value="">Select</option>

                                    <option value="Male"
                                        <?= ($resident['sex'] ?? '') === 'Male' ? 'selected' : '' ?>>
                                        Male
                                    </option>

                                    <option value="Female"
                                        <?= ($resident['sex'] ?? '') === 'Female' ? 'selected' : '' ?>>
                                        Female
                                    </option>

                                </select>

                            </div>

                            <div class="col-md-3">

                                <label class="form-label">
                                    Civil Status
                                </label>

                                <select name="civil_status" class="form-select">

                                    <option value="">Select</option>

                                    <?php foreach (
                                        ['Single','Married','Widowed','Separated','Divorced']
                                        as $status
                                    ): ?>

                                        <option value="<?= e($status) ?>"
                                            <?= ($resident['civil_status'] ?? '') === $status ? 'selected' : '' ?>>

                                            <?= e($status) ?>

                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>

                            <div class="col-md-6">

                                <label class="form-label">
                                    Contact Number
                                </label>

                                <input type="text"
                                       name="contact_number"
                                       class="form-control"
                                       value="<?= e($resident['contact_number'] ?? '') ?>">

                            </div>

                            <div class="col-md-6">

                                <label class="form-label">
                                    Occupation
                                </label>

                                <input type="text"
                                       name="occupation"
                                       class="form-control"
                                       value="<?= e($resident['occupation'] ?? '') ?>">

                            </div>

                        </div>

                        <!-- RESIDENCY INFORMATION -->

                        <h6 class="section-heading">
                            <i class="bi bi-house me-2"></i>
                            Residency Information
                        </h6>

                        <div class="row g-3 mb-4">

                            <div class="col-md-6">

                                <label class="form-label">
                                    Years of Residency
                                </label>

                                <input type="number"
                                       min="0"
                                       name="years_of_residency"
                                       class="form-control"
                                       value="<?= e($resident['years_of_residency'] ?? '') ?>">

                            </div>

                            <div class="col-md-6">

                                <label class="form-label">
                                    Residency Since
                                </label>

                                <input type="date"
                                       name="residency_since"
                                       class="form-control"
                                       value="<?= e($resident['residency_since'] ?? '') ?>">

                            </div>

                        </div>

                        <!-- ADDRESS INFORMATION -->

                        <h6 class="section-heading">
                            <i class="bi bi-geo-alt me-2"></i>
                            Address Information
                        </h6>

                        <div class="row g-3 mb-4">

                            <div class="col-md-3">

                                <label class="form-label">
                                    House Number
                                </label>

                                <input type="text"
                                       name="house_number"
                                       class="form-control"
                                       value="<?= e($resident['house_number'] ?? '') ?>">

                            </div>

                            <div class="col-md-9">

                                <label class="form-label">
                                    Street
                                </label>

                                <input type="text"
                                       name="street"
                                       class="form-control"
                                       value="<?= e($resident['street'] ?? '') ?>">

                            </div>

                            <div class="col-md-4">

                                <label class="form-label">
                                    Purok
                                </label>

                                <input type="text"
                                       name="purok"
                                       class="form-control"
                                       value="<?= e($resident['purok'] ?? '') ?>">

                            </div>

                            <div class="col-md-4">

                                <label class="form-label">
                                    Subdivision
                                </label>

                                <input type="text"
                                       name="subdivision"
                                       class="form-control"
                                       value="<?= e($resident['subdivision'] ?? '') ?>">

                            </div>

                            <div class="col-md-4">

                                <label class="form-label">
                                    Barangay
                                </label>

                                <input type="text"
                                       name="barangay"
                                       class="form-control"
                                       value="<?= e($resident['barangay'] ?? '') ?>">

                            </div>

                            <div class="col-md-12">

                                <label class="form-label">
                                    City
                                </label>

                                <input type="text"
                                       name="city"
                                       class="form-control"
                                       value="<?= e($resident['city'] ?? '') ?>">

                            </div>

                        </div>

                        <!-- ACTION BUTTONS -->

                        <div class="d-flex justify-content-end gap-2 flex-wrap">

                            <a href="resident-directory.php"
                               class="btn btn-light border">

                                <i class="bi bi-arrow-left me-1"></i>
                                Cancel

                            </a>

                            <button type="submit"
                                    class="btn btn-primary-custom">

                                <i class="bi bi-save me-1"></i>
                                Save Changes

                            </button>

                        </div>

                    </form>

                </div>

            </div>

        </main>

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
