
<?php
require_once '../includes/admin_auth.php';

$search = trim($_GET['search'] ?? '');

$sql = "
    SELECT
        u.user_id, u.username, u.email, u.account_status,
        rp.resident_id, rp.first_name, rp.middle_name,
        rp.last_name, rp.suffix, rp.birth_date, rp.sex,
        rp.civil_status, rp.contact_number, rp.occupation,
        rp.years_of_residency, rp.residency_since,
        rp.profile_picture, rp.house_number, rp.street,
        rp.purok, rp.subdivision, rp.barangay, rp.city
    FROM users u
    INNER JOIN resident_profiles rp ON u.user_id = rp.user_id
    WHERE u.account_status = 'approved'
";

$params = [];

if ($search !== '') {
    $sql .= "
        AND (
            rp.first_name LIKE ?
            OR rp.middle_name LIKE ?
            OR rp.last_name LIKE ?
            OR u.username LIKE ?
            OR u.email LIKE ?
        )
    ";

    $term = "%$search%";
    $params = [$term, $term, $term, $term, $term];
}

$sql .= " ORDER BY rp.last_name ASC, rp.first_name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$residents = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* HELPERS */
function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function getResidentFullName($r) {
    $name = trim(
        ($r['first_name'] ?? '') . ' ' .
        ($r['middle_name'] ?? '') . ' ' .
        ($r['last_name'] ?? '')
    );

    if (!empty($r['suffix'])) {
        $name .= ' ' . $r['suffix'];
    }

    return trim($name);
}

function getResidentAddress($r) {
    $parts = [
        $r['house_number'] ?? '',
        $r['street'] ?? '',
        $r['purok'] ?? '',
        $r['subdivision'] ?? '',
        $r['barangay'] ?? '',
        $r['city'] ?? ''
    ];

    return implode(', ', array_filter($parts, function ($v) {
        return trim((string)$v) !== '';
    }));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Resident Directory | Barangay Putatan</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <link rel="stylesheet" href="../assets/css/style.css">

    <style>

/* =====================================================
   DIRECTORY UI
===================================================== */

.directory-header {
    display:flex;
    align-items:center;
    gap:15px;
    margin-bottom:25px;
}

.directory-header-icon {
    width:55px;
    height:55px;
    background:#e8edff;
    color:#173b8e;
    border-radius:14px;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:23px;
}

.directory-stat {
    background:white;
    border:0;
    border-radius:14px;
    box-shadow:0 3px 12px rgba(0,0,0,.04);
}

.directory-stat-icon {
    width:52px;
    height:52px;
    border-radius:12px;
    background:#e8edff;
    color:#173b8e;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:22px;
}

.directory-card {
    border:0;
    border-radius:14px;
    box-shadow:0 3px 12px rgba(0,0,0,.04);
    overflow:hidden;
}

.directory-card .card-header {
    background:white;
    border-bottom:1px solid #edf0f5;
}

.directory-search {
    position:relative;
}

.directory-search i {
    position:absolute;
    left:15px;
    top:50%;
    transform:translateY(-50%);
    color:#9ca3af;
}

.directory-search input {
    padding-left:42px;
    border-radius:9px;
}

.resident-avatar {
    width:42px;
    height:42px;
    border-radius:50%;
    background:#e8edff;
    color:#173b8e;
    display:flex;
    align-items:center;
    justify-content:center;
    font-weight:bold;
    flex-shrink:0;
    overflow:hidden;
}

.resident-avatar img {
    width:100%;
    height:100%;
    object-fit:cover;
}

.resident-table th {
    font-size:12px;
    text-transform:uppercase;
    color:#6b7280;
    letter-spacing:.4px;
    white-space:nowrap;
    background:#f8f9fc;
}

.resident-table td {
    font-size:13px;
    vertical-align:middle;
}

.resident-table tbody tr:hover {
    background:#fafbff;
}

.resident-profile-info {
    display:flex;
    align-items:center;
    gap:12px;
}

.resident-profile-info .name {
    font-weight:600;
    color:#1f2937;
}

.resident-profile-info .email {
    font-size:12px;
    color:#6b7280;
}

.approved-badge {
    display:inline-flex;
    align-items:center;
    gap:5px;
    background:#e6f7ee;
    color:#15803d;
    border-radius:30px;
    padding:6px 11px;
    font-size:11px;
    font-weight:600;
}

.view-profile-btn {
    background:#e8edff;
    color:#173b8e;
    border:0;
    border-radius:8px;
    padding:8px 12px;
    font-size:12px;
    font-weight:600;
}

.view-profile-btn:hover {
    background:#173b8e;
    color:white;
}

.resident-modal-profile {
    text-align:center;
    padding:20px 0;
    border-bottom:1px solid #eee;
    margin-bottom:20px;
}

.resident-modal-profile img {
    width:110px;
    height:110px;
    object-fit:cover;
    border-radius:50%;
    border:5px solid #e8edff;
    margin-bottom:15px;
}

.resident-modal-placeholder {
    width:110px;
    height:110px;
    border-radius:50%;
    background:#173b8e;
    color:white;
    display:flex;
    justify-content:center;
    align-items:center;
    font-size:35px;
    font-weight:bold;
    margin:0 auto 15px;
}

.detail-section-title {
    font-size:14px;
    font-weight:bold;
    color:#173b8e;
    margin-bottom:15px;
}

.detail-box {
    padding:15px;
    background:#f8f9fc;
    border:1px solid #edf0f5;
    border-radius:10px;
    height:100%;
}

.detail-box label {
    display:block;
    color:#6b7280;
    font-size:11px;
    font-weight:bold;
    text-transform:uppercase;
    margin-bottom:7px;
}

.detail-box span {
    font-size:14px;
    color:#1f2937;
    word-break:break-word;
}

@media(max-width:768px) {
    .directory-header {
        align-items:flex-start;
    }

    .resident-table {
        min-width:800px;
    }
}

/* =====================================================
   RESIDENT HISTORY TIMELINE
===================================================== */

#residentHistoryContent {
    position: relative;
    padding-left: 15px;
}

.history-entry {
    position: relative;
    border: 0 !important;
    border-left: 3px solid #173b8e !important;
    border-radius: 10px !important;
    box-shadow: 0 3px 12px rgba(0, 0, 0, 0.04);
    margin-bottom: 20px;
    overflow: visible;
}

.history-entry::before {
    content: "";
    position: absolute;
    left: -10px;
    top: 24px;
    width: 17px;
    height: 17px;
    border-radius: 50%;
    background: #173b8e;
    border: 3px solid #fff;
    box-shadow: 0 0 0 2px #173b8e;
}

.history-entry .card-body {
    padding: 20px;
}

.history-entry .history-action-badge {
    display: inline-block;
    background: #e8edff;
    color: #173b8e;
    border-radius: 20px;
    padding: 6px 12px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
}

.history-entry .history-admin {
    color: #6b7280;
    font-size: 12px;
}

.history-entry .history-date {
    color: #6b7280;
    font-size: 12px;
}

.history-entry hr {
    border-color: #edf0f5;
}

.history-entry .history-field {
    background: #f8f9fc;
    border: 1px solid #edf0f5;
    border-radius: 8px;
    padding: 12px;
    margin-bottom: 10px;
}

.history-entry .history-field-name {
    font-weight: 600;
    color: #374151;
    margin-bottom: 8px;
}

.history-entry .history-old-value {
    color: #dc3545;
    background: #fff1f2;
    padding: 5px 8px;
    border-radius: 5px;
    word-break: break-word;
}

.history-entry .history-new-value {
    color: #198754;
    background: #eafaf1;
    padding: 5px 8px;
    border-radius: 5px;
    word-break: break-word;
}

@media (max-width: 576px) {

    #residentHistoryContent {
        padding-left: 10px;
    }

    .history-entry .card-body {
        padding: 15px;
    }

}

    </style>

</head>

<body class="admin-page">

<!-- =====================================================
     NAVBAR - BASED ON residents.php
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
                <?= htmlspecialchars($_SESSION['full_name'], ENT_QUOTES, 'UTF-8') ?>
            </span>

            <a href="../logout.php" class="btn btn-yellow btn-sm">
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

        <!-- SIDEBAR - BASED ON residents.php -->

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

        <!-- =================================================
             CONTENT
        ================================================= -->

        <main class="col-md-9 col-lg-10 px-4 py-4">

            <!-- PAGE HEADER -->

            <div class="directory-header">

                <div class="directory-header-icon">
                    <i class="bi bi-people-fill"></i>
                </div>

                <div>

                    <h2 class="fw-bold text-primary-custom mb-1">
                        Resident Directory
                    </h2>

                    <p class="text-muted mb-0">
                        Manage and view registered residents of Barangay Putatan.
                    </p>

                </div>

            </div>

            <!-- STAT CARD -->

            <div class="card directory-stat mb-4">

                <div class="card-body p-4">

                    <div class="d-flex justify-content-between align-items-center">

                        <div>

                            <small class="text-muted">
                                Total Approved Residents
                            </small>

                            <h2 class="fw-bold text-primary-custom mb-0">
                                <?= count($residents) ?>
                            </h2>

                        </div>

                        <div class="directory-stat-icon">
                            <i class="bi bi-people-fill"></i>
                        </div>

                    </div>

                </div>

            </div>

            <!-- DIRECTORY TABLE -->

            <div class="card directory-card">

                <div class="card-header py-3 px-4">

                    <h5 class="fw-bold text-primary-custom mb-1">
                        Registered Residents
                    </h5>

                    <p class="text-muted small mb-0">
                        View resident information and account details.
                    </p>

                </div>

                <!-- SEARCH -->

                <form method="GET"
                      class="p-3 border-bottom">

                    <div class="row g-2">

                        <div class="col-md">

                            <div class="directory-search">

                                <i class="bi bi-search"></i>

                                <input
                                    type="text"
                                    name="search"
                                    class="form-control"
                                    placeholder="Search name, username or email..."
                                    value="<?= e($search) ?>">

                            </div>

                        </div>

                        <div class="col-auto">

                            <button type="submit"
                                    class="btn btn-primary-custom">

                                <i class="bi bi-search me-1"></i>
                                Search

                            </button>

                        </div>

                        <div class="col-auto">

                            <a href="resident-directory.php"
                               class="btn btn-light border">

                                <i class="bi bi-arrow-counterclockwise me-1"></i>
                                Reset

                            </a>

                        </div>

                    </div>

                </form>

                <!-- TABLE -->

                <div class="table-responsive">

                    <table class="table table-hover resident-table align-middle mb-0">

                        <thead>

                            <tr>
                                <th>#</th>
                                <th>Resident</th>
                                <th>Username</th>
                                <th>Address</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>

                        </thead>

                        <tbody>

                        <?php if (!empty($residents)): ?>

                            <?php foreach ($residents as $index => $resident): ?>

                                <?php
                                $fullName = getResidentFullName($resident);
                                $address = getResidentAddress($resident);

                                $initials = strtoupper(
                                    substr($resident['first_name'] ?? 'R', 0, 1) .
                                    substr($resident['last_name'] ?? '', 0, 1)
                                );
                                ?>

                                <tr>

                                    <td class="text-muted">
                                        <?= $index + 1 ?>
                                    </td>

                                    <td>

                                        <div class="resident-profile-info">

                                            <div class="resident-avatar">

                                                <?php if (!empty($resident['profile_picture'])): ?>

                                                    <img src="../<?= e($resident['profile_picture']) ?>"
                                                         alt="Resident">

                                                <?php else: ?>

                                                    <?= e($initials) ?>

                                                <?php endif; ?>

                                            </div>

                                            <div>

                                                <div class="name">
                                                    <?= e($fullName) ?>
                                                </div>

                                                <div class="email">
                                                    <?= e($resident['email']) ?>
                                                </div>

                                            </div>

                                        </div>

                                    </td>

                                    <td>
                                        <?= e($resident['username']) ?>
                                    </td>

                                    <td>
                                        <?= e($address) ?>
                                    </td>

                                    <td>

                                        <span class="approved-badge">
                                            <i class="bi bi-check-circle-fill"></i>
                                            Approved
                                        </span>

                                    </td>

                                    <td>

                                        <!-- VIEW PROFILE BUTTON -->
                                        <button
                                            type="button"
                                            class="view-profile-btn"
                                            onclick="viewResident(this)"

                                            data-name="<?= e($fullName) ?>"
                                            data-username="<?= e($resident['username']) ?>"
                                            data-email="<?= e($resident['email']) ?>"
                                            data-birthdate="<?= e($resident['birth_date'] ?? '') ?>"
                                            data-sex="<?= e($resident['sex'] ?? '') ?>"
                                            data-civilstatus="<?= e($resident['civil_status'] ?? '') ?>"
                                            data-contact="<?= e($resident['contact_number'] ?? '') ?>"
                                            data-occupation="<?= e($resident['occupation'] ?? '') ?>"
                                            data-years="<?= e($resident['years_of_residency'] ?? '') ?>"
                                            data-residencysince="<?= e($resident['residency_since'] ?? '') ?>"
                                            data-address="<?= e($address) ?>"
                                            data-picture="<?= e($resident['profile_picture'] ?? '') ?>">

                                            <i class="bi bi-eye me-1"></i>
                                            View Profile

                                        </button>

                                        <!-- EDIT BUTTON -->
                                        <a href="edit-resident.php?user_id=<?= (int)$resident['user_id'] ?>"
                                        class="btn btn-sm btn-outline-primary ms-1">

                                            <i class="bi bi-pencil-square"></i>
                                            Edit

                                        </a>

                                        <!-- HISTORY BUTTON -->
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-secondary ms-1"
                                            onclick="showResidentHistory(this)"
                                            data-user-id="<?= (int)$resident['user_id'] ?>"
                                            data-resident-name="<?= e($fullName) ?>">

                                            <i class="bi bi-clock-history me-1"></i>
                                            History

                                        </button>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php else: ?>

                            <tr>

                                <td colspan="6" class="text-center py-5">

                                    <div class="text-muted">

                                        <i class="bi bi-people display-5 mb-3 d-block"></i>

                                        <h5>No Residents Found</h5>

                                        <p class="mb-0">
                                            <?php if ($search !== ''): ?>
                                                No residents matched your search.
                                            <?php else: ?>
                                                No approved residents are currently available.
                                            <?php endif; ?>
                                        </p>

                                    </div>

                                </td>

                            </tr>

                        <?php endif; ?>

                        </tbody>

                    </table>

                </div>

                <!-- TABLE FOOTER -->

                <div class="card-footer bg-white py-3">

                    <small class="text-muted">

                        Showing
                        <strong><?= count($residents) ?></strong>
                        approved resident(s)

                    </small>

                </div>

            </div>

        </main>

    </div>

</div>


<!-- =====================================================
     RESIDENT PROFILE MODAL
===================================================== -->

<div class="modal fade"
     id="residentModal"
     tabindex="-1"
     aria-hidden="true">

    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">

        <div class="modal-content border-0 shadow">

            <div class="modal-header">

                <h5 class="modal-title fw-bold text-primary-custom">

                    <i class="bi bi-person-vcard me-2"></i>
                    Resident Profile

                </h5>

                <button type="button"
                        class="btn-close"
                        data-bs-dismiss="modal">
                </button>

            </div>

            <div class="modal-body p-4">

                <!-- PROFILE -->

                <div class="resident-modal-profile">

                    <img id="modalPicture"
                         src=""
                         alt="Resident Profile"
                         style="display:none;">

                    <div id="modalPlaceholder"
                         class="resident-modal-placeholder">
                        R
                    </div>

                    <h4 id="modalName" class="fw-bold mb-2"></h4>

                    <span class="approved-badge">
                        <i class="bi bi-check-circle-fill"></i>
                        Approved Resident
                    </span>

                </div>

                <!-- ACCOUNT INFORMATION -->

                <h6 class="detail-section-title">
                    <i class="bi bi-person-lock me-2"></i>
                    Account Information
                </h6>

                <div class="row g-3 mb-4">

                    <div class="col-md-6">

                        <div class="detail-box">
                            <label>Username</label>
                            <span id="modalUsername"></span>
                        </div>

                    </div>

                    <div class="col-md-6">

                        <div class="detail-box">
                            <label>Email Address</label>
                            <span id="modalEmail"></span>
                        </div>

                    </div>

                </div>

                <!-- PERSONAL INFORMATION -->

                <h6 class="detail-section-title">
                    <i class="bi bi-person me-2"></i>
                    Personal Information
                </h6>

                <div class="row g-3 mb-4">

                    <div class="col-md-6">

                        <div class="detail-box">
                            <label>Birth Date</label>
                            <span id="modalBirthdate"></span>
                        </div>

                    </div>

                    <div class="col-md-6">

                        <div class="detail-box">
                            <label>Sex</label>
                            <span id="modalSex"></span>
                        </div>

                    </div>

                    <div class="col-md-6">

                        <div class="detail-box">
                            <label>Civil Status</label>
                            <span id="modalCivilStatus"></span>
                        </div>

                    </div>

                    <div class="col-md-6">

                        <div class="detail-box">
                            <label>Contact Number</label>
                            <span id="modalContact"></span>
                        </div>

                    </div>

                    <div class="col-12">

                        <div class="detail-box">
                            <label>Occupation</label>
                            <span id="modalOccupation"></span>
                        </div>

                    </div>

                </div>

                <!-- RESIDENCY INFORMATION -->

                <h6 class="detail-section-title">
                    <i class="bi bi-house me-2"></i>
                    Residency Information
                </h6>

                <div class="row g-3">

                    <div class="col-md-6">

                        <div class="detail-box">
                            <label>Years of Residency</label>
                            <span id="modalYears"></span>
                        </div>

                    </div>

                    <div class="col-md-6">

                        <div class="detail-box">
                            <label>Residency Since</label>
                            <span id="modalResidencySince"></span>
                        </div>

                    </div>

                    <div class="col-12">

                        <div class="detail-box">
                            <label>Complete Address</label>
                            <span id="modalAddress"></span>
                        </div>

                    </div>

                </div>

            </div>

            <div class="modal-footer">

                <button type="button"
                        class="btn btn-light border"
                        data-bs-dismiss="modal">

                    Close

                </button>

            </div>

        </div>

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>


<!-- =====================================================
     RESIDENT PROFILE HISTORY MODAL
===================================================== -->

<div class="modal fade"
     id="residentHistoryModal"
     tabindex="-1"
     aria-hidden="true">

    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">

        <div class="modal-content border-0 shadow">

            <!-- MODAL HEADER -->

            <div class="modal-header">

                <h5 class="modal-title fw-bold text-primary-custom">

                    <i class="bi bi-clock-history me-2"></i>
                    Resident Profile History

                </h5>

                <button type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close">
                </button>

            </div>

            <!-- MODAL BODY -->

            <div class="modal-body p-4">

                <div class="mb-4">

                    <h5 id="historyResidentName"
                        class="fw-bold mb-1">
                    </h5>

                    <p class="text-muted small mb-0">
                        Record of changes made to this resident's profile.
                    </p>

                </div>

                <!-- HISTORY CONTENT -->

                <div id="residentHistoryContent">

                    <div class="text-center py-4 text-muted">

                        <div class="spinner-border text-primary"
                             role="status">

                            <span class="visually-hidden">
                                Loading...
                            </span>

                        </div>

                        <p class="mt-3 mb-0">
                            Loading resident history...
                        </p>

                    </div>

                </div>

            </div>

            <!-- MODAL FOOTER -->

            <div class="modal-footer">

                <button type="button"
                        class="btn btn-light border"
                        data-bs-dismiss="modal">

                    <i class="bi bi-x-lg me-1"></i>
                    Close

                </button>

            </div>

        </div>

    </div>

</div>


<!-- =====================================================
     JAVASCRIPT
===================================================== -->

<script>

function viewResident(button) {

    const modal = new bootstrap.Modal(
        document.getElementById('residentModal')
    );

    document.getElementById('modalName').textContent =
        button.dataset.name || 'N/A';

    document.getElementById('modalUsername').textContent =
        button.dataset.username || 'N/A';

    document.getElementById('modalEmail').textContent =
        button.dataset.email || 'N/A';

    document.getElementById('modalBirthdate').textContent =
        button.dataset.birthdate || 'N/A';

    document.getElementById('modalSex').textContent =
        button.dataset.sex || 'N/A';

    document.getElementById('modalCivilStatus').textContent =
        button.dataset.civilstatus || 'N/A';

    document.getElementById('modalContact').textContent =
        button.dataset.contact || 'N/A';

    document.getElementById('modalOccupation').textContent =
        button.dataset.occupation || 'N/A';

    document.getElementById('modalYears').textContent =
        button.dataset.years || 'N/A';

    document.getElementById('modalResidencySince').textContent =
        button.dataset.residencysince || 'N/A';

    document.getElementById('modalAddress').textContent =
        button.dataset.address || 'N/A';

    const picture = document.getElementById('modalPicture');
    const placeholder = document.getElementById('modalPlaceholder');

    const picturePath = button.dataset.picture || '';

    picture.onerror = function() {
        picture.style.display = 'none';
        placeholder.style.display = 'flex';
    };

    if (picturePath !== '') {

        picture.src = '../' + picturePath;
        picture.style.display = 'block';
        placeholder.style.display = 'none';

    } else {

        picture.style.display = 'none';
        placeholder.style.display = 'flex';

    }

    modal.show();
}

// =====================================================
// RESIDENT PROFILE HISTORY
// =====================================================

async function showResidentHistory(button) {

    const userId = button.dataset.userId;
    const residentName = button.dataset.residentName;

    const modalElement = document.getElementById('residentHistoryModal');
    const modal = bootstrap.Modal.getOrCreateInstance(modalElement);

    const nameElement = document.getElementById('historyResidentName');
    const contentElement = document.getElementById('residentHistoryContent');

    nameElement.textContent = residentName || 'Resident';

    // Loading state
    contentElement.innerHTML = `
        <div class="text-center py-4 text-muted">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-3 mb-0">Loading resident history...</p>
        </div>
    `;

    modal.show();

    try {

        const response = await fetch(
            'resident-history.php?user_id=' + encodeURIComponent(userId)
        );

        if (!response.ok) {
            throw new Error('Failed to retrieve resident history.');
        }

        const data = await response.json();

        if (!data.success) {
            throw new Error(data.message || 'Unable to load history records.');
        }

        const history = data.history || [];

        // EMPTY HISTORY
        if (history.length === 0) {

            contentElement.innerHTML = `
                <div class="text-center py-5">

                    <i class="bi bi-clock-history display-4 text-muted"></i>

                    <h6 class="fw-bold mt-3">
                        No History Records
                    </h6>

                    <p class="text-muted mb-0">
                        No profile changes have been recorded for this resident yet.
                    </p>

                </div>
            `;

            return;
        }

        // DISPLAY HISTORY RECORDS
        contentElement.innerHTML = '';

        history.forEach(record => {

            const card = document.createElement('div');

            card.className = 'card history-entry';

            const date = record.created_at
                ? new Date(record.created_at.replace(' ', 'T'))
                : null;

            const formattedDate = date && !isNaN(date.getTime())
                ? date.toLocaleString()
                : (record.created_at || 'Unknown date');

            const adminName = record.admin_username || 'Unknown Admin';

            const actionType = record.action_type || 'UPDATE';

            let changedFields = record.changed_fields || {};

            if (typeof changedFields === 'string') {
                try {
                    changedFields = JSON.parse(changedFields);
                } catch (error) {
                    changedFields = {};
                }
            }

            let changesHTML = '';

            if (Array.isArray(changedFields)) {

                changedFields.forEach(field => {

                    if (typeof field === 'object' && field !== null) {

                        const fieldName = field.field || field.name || 'Field';
                        const oldValue = field.old_value ?? field.old ?? 'N/A';
                        const newValue = field.new_value ?? field.new ?? 'N/A';

                        changesHTML += `
                            <div class="mb-2">
                                <strong>${escapeHistoryHTML(fieldName)}</strong>
                                <div class="small text-muted">
                                    <span class="text-danger">
                                        ${escapeHistoryHTML(oldValue)}
                                    </span>
                                    <i class="bi bi-arrow-right mx-1"></i>
                                    <span class="text-success">
                                        ${escapeHistoryHTML(newValue)}
                                    </span>
                                </div>
                            </div>
                        `;
                    } else {

                        changesHTML += `
                            <div class="mb-2">
                                ${escapeHistoryHTML(field)}
                            </div>
                        `;
                    }

                });

            } else if (typeof changedFields === 'object' && changedFields !== null) {

                Object.entries(changedFields).forEach(([fieldName, values]) => {

                    if (typeof values === 'object' && values !== null) {

                        const oldValue = values.old_value ?? values.old ?? 'N/A';
                        const newValue = values.new_value ?? values.new ?? 'N/A';

                        changesHTML += `
                            <div class="history-field">

                                <div class="history-field-name">
                                    ${escapeHistoryHTML(formatHistoryFieldName(fieldName))}
                                </div>

                                <div class="d-flex flex-column flex-md-row
                                            align-items-md-center gap-2">

                                    <div class="history-old-value">
                                        ${escapeHistoryHTML(oldValue)}
                                    </div>

                                    <i class="bi bi-arrow-right text-muted"></i>

                                    <div class="history-new-value">
                                        ${escapeHistoryHTML(newValue)}
                                    </div>

                                </div>

                            </div>
                        `;

                    } else {

                        changesHTML += `
                            <div class="mb-2">
                                <strong>${escapeHistoryHTML(fieldName)}</strong>
                                <div class="small text-muted">
                                    ${escapeHistoryHTML(values)}
                                </div>
                            </div>
                        `;

                    }

                });

            }

            if (!changesHTML) {
                changesHTML = `
                    <p class="text-muted mb-0">
                        No detailed change information available.
                    </p>
                `;
            }

            card.innerHTML = `
                <div class="card-body">

                    <div class="d-flex justify-content-between
                                align-items-start flex-wrap gap-2 mb-3">

                        <div>

                            <span class="history-action-badge">
                                ${escapeHistoryHTML(actionType)}
                            </span>

                            <div class="history-admin mt-2">
                                <i class="bi bi-person-gear me-1"></i>
                                Updated by: ${escapeHistoryHTML(adminName)}
                            </div>

                        </div>

                        <div class="history-date">
                            <i class="bi bi-calendar3 me-1"></i>
                            ${escapeHistoryHTML(formattedDate)}
                        </div>

                    </div>

                    <hr>

                    <div>
                        ${changesHTML}
                    </div>

                </div>
            `;

            contentElement.appendChild(card);

        });

    } catch (error) {

        console.error('Resident history error:', error);

        contentElement.innerHTML = `
            <div class="alert alert-danger mb-0">

                <i class="bi bi-exclamation-triangle me-2"></i>

                Unable to load resident history.
                Please try again.

            </div>
        `;

    }

}


// =====================================================
// HTML ESCAPE HELPER
// =====================================================

function escapeHistoryHTML(value) {

    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

}

function formatHistoryFieldName(fieldName) {

    const fieldLabels = {
        first_name: 'First Name',
        middle_name: 'Middle Name',
        last_name: 'Last Name',
        suffix: 'Suffix',
        birth_date: 'Birth Date',
        gender: 'Gender',
        civil_status: 'Civil Status',
        contact_number: 'Contact Number',
        email: 'Email Address',
        address: 'Address',
        residency_since: 'Residency Since',
        occupation: 'Occupation',
        nationality: 'Nationality',
        religion: 'Religion',
        emergency_contact: 'Emergency Contact',
        emergency_contact_number: 'Emergency Contact Number'
    };

    if (fieldLabels[fieldName]) {
        return fieldLabels[fieldName];
    }

    // Automatically convert unknown database fields
    return fieldName
        .replace(/_/g, ' ')
        .replace(/\b\w/g, letter => letter.toUpperCase());
}

</script>

</body>
</html>
