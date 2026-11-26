
<?php
session_start();

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/notification_helper.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'resident') {
    header("Location: ../login.php");
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$full_name = $_SESSION['full_name'] ?? 'Resident';

// Direct-navigation target from announcement notifications
$target_announcement_id = isset($_GET['announcement_id']) ? (int)$_GET['announcement_id'] : 0;

$total_requests = 0;
$recent_requests = [];
$announcements = [];
$success = '';
$error = '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];


/* ==========================================
   NOTIFICATION AJAX ACTIONS
========================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['mark_notification_read', 'mark_all_notifications_read'], true)) {
    header('Content-Type: application/json');

    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid request.']);
        exit;
    }

    try {
        if (($_POST['action'] ?? '') === 'mark_notification_read') {
            $notification_id = (int)($_POST['notification_id'] ?? 0);
            if ($notification_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid notification.']);
                exit;
            }
            $ok = markNotificationAsRead($pdo, $user_id, $notification_id);
            echo json_encode(['success' => $ok]);
            exit;
        }

        $ok = markAllNotificationsAsRead($pdo, $user_id);
        echo json_encode(['success' => $ok]);
        exit;
    } catch (Throwable $e) {
        error_log('Resident notification action error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Unable to update notifications.']);
        exit;
    }
}

/* ==========================================
   FETCH RESIDENT NOTIFICATIONS
========================================== */
$notifications = [];
$notification_unread_count = 0;
try {
    $notifications = getUserNotifications($pdo, $user_id, 7);
    $notification_unread_count = getUnreadNotificationCount($pdo, $user_id);
} catch (Throwable $e) {
    error_log('Resident notification fetch error: ' . $e->getMessage());
}

/* ==========================================
   MARK ANNOUNCEMENT AS READ (AJAX)
========================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'mark_read') {

    header('Content-Type: application/json');

    if (!isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {

        echo json_encode([
            'success' => false,
            'message' => 'Invalid request.'
        ]);
        exit;
    }

    $announcement_id = (int) ($_POST['announcement_id'] ?? 0);

    if ($announcement_id <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid announcement.'
        ]);
        exit;
    }

    try {
        // Verify announcement is currently available
        $stmt = $pdo->prepare("
            SELECT announcement_id
            FROM announcements
            WHERE announcement_id = ?
              AND status = 'published'
              AND (publish_date IS NULL OR publish_date <= NOW())
              AND (expiry_date IS NULL OR expiry_date >= NOW())
        ");

        $stmt->execute([$announcement_id]);

        if (!$stmt->fetch()) {
            echo json_encode([
                'success' => false,
                'message' => 'Announcement is unavailable.'
            ]);
            exit;
        }

        // Record that the resident has viewed the announcement
        $stmt = $pdo->prepare("
            INSERT INTO announcement_reads
                (announcement_id, user_id)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE viewed_at = CURRENT_TIMESTAMP
        ");

        $stmt->execute([$announcement_id, $user_id]);

        echo json_encode([
            'success' => true
        ]);
        exit;

    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Unable to update read status.'
        ]);
        exit;
    }
}

/* ==========================================
   SERVICE REQUEST STATISTICS
========================================== */

$total_requests = 0;
$recent_requests = [];
$resident_id = 0;

try {

    // Get the actual resident ID associated with the logged-in user
    $stmt = $pdo->prepare("
        SELECT resident_id
        FROM resident_profiles
        WHERE user_id = ?
        LIMIT 1
    ");

    $stmt->execute([$user_id]);
    $resident_id = (int) $stmt->fetchColumn();

    if ($resident_id > 0) {

        // Get total submitted service requests
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM service_requests
            WHERE resident_id = ?
        ");

        $stmt->execute([$resident_id]);
        $total_requests = (int) $stmt->fetchColumn();

        // Get the five most recent service requests
        $stmt = $pdo->prepare("
            SELECT
                reference_number,
                purpose,
                status,
                requested_at
            FROM service_requests
            WHERE resident_id = ?
            ORDER BY requested_at DESC
            LIMIT 5
        ");

        $stmt->execute([$resident_id]);
        $recent_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    }

} catch (PDOException $e) {

    error_log('Resident dashboard service request error: ' . $e->getMessage());

    $total_requests = 0;
    $recent_requests = [];

}

/* ==========================================
   FETCH ACTIVE ANNOUNCEMENTS
========================================== */
try {

    $stmt = $pdo->prepare("
        SELECT
            a.announcement_id,
            a.title,
            a.content,
            a.category,
            a.image_path,
            a.publish_date,
            a.created_at,
            u.full_name AS admin_name,
            CASE
                WHEN ar.read_id IS NULL THEN 0
                ELSE 1
            END AS is_read
        FROM announcements a
        LEFT JOIN users u
            ON a.published_by = u.user_id
        LEFT JOIN announcement_reads ar
            ON ar.announcement_id = a.announcement_id
            AND ar.user_id = ?
        WHERE a.status = 'published'
          AND (a.publish_date IS NULL OR a.publish_date <= NOW())
          AND (a.expiry_date IS NULL OR a.expiry_date >= NOW())
        ORDER BY
            COALESCE(a.publish_date, a.created_at) DESC
    ");

    $stmt->execute([$user_id]);
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $announcements = [];
}

/* ==========================================
   STATUS BADGE
========================================== */
function residentStatusClass($status) {

    return match (strtolower($status)) {
        'approved', 'completed' => 'bg-success',
        'rejected', 'cancelled' => 'bg-danger',
        'processing', 'ready_for_release' => 'bg-warning text-dark',
        'under_review' => 'bg-warning text-dark',
        default => 'bg-primary'
    };
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Resident Dashboard | Barangay Putatan</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
          rel="stylesheet">

    <link href="../assets/css/style.css" rel="stylesheet">

    <style>

        body {
            background-color: #f4f6fa;
        }

        .resident-navbar {
            background-color: #142f82;
            padding: 12px 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }

        .resident-navbar .navbar-brand {
            display: flex;
            align-items: center;
            color: white;
            font-weight: 700;
        }

        .resident-navbar img {
            object-fit: contain;
            margin-right: 10px;
        }

        .notification-dropdown-menu {
            width: min(380px, calc(100vw - 30px));
            max-height: 430px;
            overflow-y: auto;
        }
        .notification-item {
            white-space: normal;
            border-bottom: 1px solid #eef0f4;
        }
        .notification-item.unread { background-color: #f3f6ff; }
        .notification-item:hover { background-color: #eef2ff; }
        .notification-message { color: #6c757d; font-size: .875rem; }
        .notification-badge { font-size: 10px; }

        .resident-dashboard {
            max-width: 1200px;
            margin: auto;
            padding: 40px 20px;
        }

        .welcome-section {
            margin-bottom: 30px;
        }

        .welcome-section h2 {
            color: #142f82;
            font-size: 30px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .welcome-section p {
            color: #6c757d;
            max-width: 850px;
            margin-bottom: 0;
            line-height: 1.7;
        }

        .dashboard-card {
            background: #ffffff;
            border: none;
            border-radius: 14px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            height: 100%;
            transition: all 0.2s ease;
        }

        .dashboard-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 18px rgba(0,0,0,0.08);
        }

        .dashboard-card .card-body {
            padding: 25px;
        }

        .dashboard-card h5 {
            color: #142f82;
            font-weight: 700;
            margin-bottom: 15px;
        }

        .dashboard-card p {
            color: #6c757d;
            min-height: 48px;
        }

        .request-count {
            font-size: 42px;
            font-weight: 700;
            color: #142f82;
            margin-bottom: 5px;
        }

        .dashboard-btn {
            background-color: #142f82;
            border: 1px solid #142f82;
            color: white;
            border-radius: 8px;
            padding: 10px 15px;
            font-weight: 500;
            width: 100%;
            transition: 0.2s ease;
        }

        .dashboard-btn:hover {
            background-color: #0e2465;
            border-color: #0e2465;
            color: white;
        }

        .dashboard-section {
            background: white;
            border: none;
            border-radius: 14px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            margin-top: 30px;
        }

        .dashboard-section .card-header {
            background: white;
            border-bottom: 1px solid #e9ecef;
            padding: 20px 25px;
            border-radius: 14px 14px 0 0;
        }

        .dashboard-section .card-header h5 {
            color: #142f82;
            font-weight: 700;
            margin: 0;
        }

        .dashboard-section .card-body {
            padding: 25px;
        }

        .empty-state {
            text-align: center;
            padding: 45px 20px;
        }

        .empty-state h6 {
            color: #142f82;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .empty-state p {
            color: #6c757d;
            margin-bottom: 20px;
        }

        .table thead th {
            background-color: #f4f6fa;
            color: #142f82;
            font-weight: 600;
            border-bottom: none;
            padding: 14px;
            white-space: nowrap;
        }

        .table tbody td {
            padding: 15px 14px;
        }

        .table tbody tr:hover {
            background-color: #f8faff;
        }

        .status-badge {
            padding: 7px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .btn-yellow {
            background-color: #ffdd00;
            color: #142f82;
            border: none;
            font-weight: 600;
        }

        .btn-yellow:hover {
            background-color: #f0ce00;
            color: #142f82;
        }

        .btn-profile {
            background-color: white;
            color: #142f82;
            border: none;
            font-weight: 600;
        }

        .btn-profile:hover {
            background-color: #e9eefb;
            color: #142f82;
        }

        /* Announcement styles */

        .announcement-item {
            border: 1px solid #e9ecef;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
            transition: 0.2s ease;
            cursor: pointer;
            background: #fff;
        }

        .announcement-item:hover {
            border-color: #142f82;
            box-shadow: 0 4px 12px rgba(20,47,130,0.08);
            transform: translateY(-2px);
        }

        .announcement-item:last-child {
            margin-bottom: 0;
        }

        .announcement-title {
            color: #142f82;
            font-weight: 700;
        }

        .announcement-content-preview {
            color: #6c757d;
            line-height: 1.6;
        }

        .announcement-category {
            font-size: 12px;
            font-weight: 600;
            padding: 6px 10px;
            border-radius: 20px;
            background: #eef2ff;
            color: #142f82;
        }

        .unread-dot {
            width: 9px;
            height: 9px;
            display: inline-block;
            background: #dc3545;
            border-radius: 50%;
            margin-right: 5px;
        }

        .announcement-read {
            opacity: 0.85;
        }

        .announcement-modal-content {
            white-space: pre-line;
            line-height: 1.8;
        }

        @media (max-width: 768px) {

            .resident-dashboard {
                padding: 25px 15px;
            }

            .welcome-section h2 {
                font-size: 24px;
            }

            .resident-navbar {
                padding: 12px 15px;
            }

            .dashboard-section .card-body {
                padding: 15px;
            }

        }

    </style>

</head>

<body>

<nav class="navbar navbar-expand-lg navbar-dark resident-navbar">

    <div class="container-fluid">

        <a class="navbar-brand" href="dashboard.php">

            <img src="../assets/images/barangay-logo.png"
                 width="42"
                 height="42"
                 alt="Barangay Logo">

            Barangay Putatan

        </a>

        <div class="ms-auto d-flex align-items-center gap-2">

            <!-- NOTIFICATION BELL -->
            <div class="dropdown">
                <button class="btn btn-outline-light btn-sm position-relative"
                        type="button" id="notificationDropdown"
                        data-bs-toggle="dropdown" aria-expanded="false"
                        aria-label="Notifications">
                    <i class="bi bi-bell fs-5"></i>
                    <?php if ($notification_unread_count > 0): ?>
                        <span id="notificationBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger notification-badge">
                            <?= $notification_unread_count > 99 ? '99+' : $notification_unread_count ?>
                        </span>
                    <?php endif; ?>
                </button>

                <div class="dropdown-menu dropdown-menu-end p-0 shadow notification-dropdown-menu" aria-labelledby="notificationDropdown">
                    <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
                        <strong>Notifications</strong>
                        <?php if ($notification_unread_count > 0): ?>
                            <button type="button" class="btn btn-link btn-sm p-0" id="markAllNotificationsRead">Mark all as read</button>
                        <?php endif; ?>
                    </div>
                    <?php if (empty($notifications)): ?>
                        <div class="text-center text-muted py-4 small">No notifications yet.</div>
                    <?php else: ?>
                        <?php foreach ($notifications as $notification): ?>
                            <?php
                                $notification_type = $notification['notification_type'] ?? 'general';
                                $related_id = !empty($notification['related_id']) ? (int)$notification['related_id'] : 0;
                                $notification_link = 'dashboard.php';
                                if ($notification_type === 'service_request' && $related_id > 0) {
                                    $notification_link = 'my-requests.php?request_id=' . $related_id;
                                } elseif ($notification_type === 'feedback_response' && $related_id > 0) {
                                    $notification_link = 'my-feedback.php?feedback_id=' . $related_id;
                                } elseif ($notification_type === 'announcement' && $related_id > 0) {
                                    $notification_link = 'dashboard.php?announcement_id=' . $related_id;
                                }
                            ?>
                            <a href="<?= htmlspecialchars($notification_link, ENT_QUOTES, 'UTF-8') ?>"
                               class="dropdown-item notification-item <?= (int)$notification['is_read'] === 0 ? 'unread' : '' ?>"
                               data-notification-id="<?= (int)$notification['notification_id'] ?>"
                               data-read="<?= (int)$notification['is_read'] ?>">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <strong class="small">
                                        <?= htmlspecialchars($notification['title'], ENT_QUOTES, 'UTF-8') ?>
                                    </strong>
                                    <?php if ((int)$notification['is_read'] === 0): ?>
                                        <span class="notification-unread-dot text-primary" aria-label="Unread"><i class="bi bi-circle-fill small"></i></span>
                                    <?php endif; ?>
                                </div>
                                <div class="notification-message mt-1">
                                    <?= htmlspecialchars($notification['message'], ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <small class="text-muted">
                                    <?= !empty($notification['created_at']) ? htmlspecialchars(date('M d, Y h:i A', strtotime($notification['created_at'])), ENT_QUOTES, 'UTF-8') : '' ?>
                                </small>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <div class="text-center border-top py-2">
                        <a href="notifications.php" class="small text-decoration-none">View all notifications</a>
                    </div>
                </div>
            </div>

            <a href="profile.php" class="btn btn-profile btn-sm">My Profile</a>
            <a href="../logout.php" class="btn btn-yellow btn-sm">Logout</a>

        </div>

    </div>

</nav>

<div class="resident-dashboard">

    <!-- WELCOME SECTION -->

    <div class="welcome-section">

        <h2>
            Welcome, <?= htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8') ?>!
        </h2>

        <p>
            Welcome to the Barangay Putatan Online E-Governance System.
            Manage your barangay service requests, view available services,
            and submit complaints, suggestions, or feedback through your dashboard.
        </p>

    </div>

    <!-- DASHBOARD CARDS -->

    <div class="row g-4">

        <!-- SERVICE REQUESTS -->

        <div class="col-md-6 col-lg-3">

            <div class="card dashboard-card">

                <div class="card-body d-flex flex-column">

                    <h5>My Service Requests</h5>

                    <div class="request-count">
                        <?= $total_requests ?>
                    </div>

                    <p>
                        Total submitted service requests.
                    </p>

                    <div class="mt-auto">

                        <a href="my-requests.php"
                           class="btn dashboard-btn">

                            View My Requests

                        </a>

                    </div>

                </div>

            </div>

        </div>

        <!-- BARANGAY SERVICES -->

        <div class="col-md-6 col-lg-3">

            <div class="card dashboard-card">

                <div class="card-body d-flex flex-column">

                    <h5>Barangay Services</h5>

                    <p>
                        Browse available barangay services and submit
                        applications online.
                    </p>

                    <div class="mt-auto">

                        <a href="services.php"
                           class="btn dashboard-btn">

                            Browse Services

                        </a>

                    </div>

                </div>

            </div>

        </div>

        <!-- MY FEEDBACK -->
        <div class="col-md-6 col-lg-3">

            <div class="card dashboard-card">

                <div class="card-body d-flex flex-column">

                    <h5>My Feedbacks</h5>

                    <p>
                        View the complaints, suggestions, and feedback you have previously submitted.
                    </p>

                    <div class="mt-auto">

                        <a href="my-feedback.php"
                           class="btn dashboard-btn">

                            My Feedbacks

                        </a>

                    </div>

                </div>

            </div>

        </div>

        <!-- CITIZEN FEEDBACK -->

        <div class="col-md-6 col-lg-3">

            <div class="card dashboard-card">

                <div class="card-body d-flex flex-column">

                    <h5>Citizen Feedback</h5>

                    <p>
                        Submit complaints, suggestions, or feedback
                        regarding barangay services.
                    </p>

                    <div class="mt-auto">

                        <a href="feedback.php"
                           class="btn dashboard-btn">

                            Submit Feedback

                        </a>

                    </div>

                </div>

            </div>

        </div>

    </div>

    <!-- ANNOUNCEMENTS -->

    <div class="card dashboard-section">

        <div class="card-header">

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">

                <div>

                    <h5>
                        <i class="bi bi-megaphone me-2"></i>
                        Barangay Announcements
                    </h5>

                    <small class="text-muted">
                        Updates and important information from Barangay Putatan.
                    </small>

                </div>

                <?php
                $unread_count = 0;

                foreach ($announcements as $announcement) {
                    if ((int)$announcement['is_read'] === 0) {
                        $unread_count++;
                    }
                }
                ?>

                <?php if ($unread_count > 0): ?>

                    <span class="badge bg-danger">
                        <?= $unread_count ?> unread
                    </span>

                <?php endif; ?>

            </div>

        </div>

        <div class="card-body">

            <?php if (empty($announcements)): ?>

                <div class="empty-state">

                    <i class="bi bi-megaphone text-muted"
                       style="font-size:40px;"></i>

                    <h6 class="mt-3">
                        No Announcements Available
                    </h6>

                    <p>
                        There are currently no published barangay announcements.
                    </p>

                </div>

            <?php else: ?>

                <?php foreach ($announcements as $announcement): ?>

                    <div class="announcement-item
                                <?= (int)$announcement['is_read'] === 1
                                    ? 'announcement-read'
                                    : '' ?>"

                         id="announcement-<?= (int)$announcement['announcement_id'] ?>"
                         data-id="<?= (int)$announcement['announcement_id'] ?>"
                         data-title="<?= htmlspecialchars($announcement['title'], ENT_QUOTES, 'UTF-8') ?>"
                         data-content="<?= htmlspecialchars($announcement['content'], ENT_QUOTES, 'UTF-8') ?>"
                         data-category="<?= htmlspecialchars($announcement['category'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                         data-date="<?= htmlspecialchars(
                            $announcement['publish_date']
                            ? date('M d, Y h:i A', strtotime($announcement['publish_date']))
                            : date('M d, Y h:i A', strtotime($announcement['created_at'])),
                            ENT_QUOTES,
                            'UTF-8'
                         ) ?>"
                         data-read="<?= (int)$announcement['is_read'] ?>">

                        <div class="d-flex justify-content-between
                                    align-items-start flex-wrap gap-2 mb-2">

                            <div class="announcement-title">

                                <?php if ((int)$announcement['is_read'] === 0): ?>

                                    <span class="unread-dot"></span>

                                <?php endif; ?>

                                <?= htmlspecialchars(
                                    $announcement['title'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>

                            </div>

                            <?php if (!empty($announcement['category'])): ?>

                                <span class="announcement-category">

                                    <?= htmlspecialchars(
                                        $announcement['category'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>

                                </span>

                            <?php endif; ?>

                        </div>

                        <div class="announcement-content-preview">

                            <?= htmlspecialchars(
                                mb_strimwidth(
                                    strip_tags($announcement['content']),
                                    0,
                                    220,
                                    '...'
                                ),
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>

                        </div>

                        <div class="d-flex justify-content-between
                                    align-items-center mt-3 flex-wrap gap-2">

                            <small class="text-muted">

                                <i class="bi bi-calendar3 me-1"></i>

                                <?= htmlspecialchars(
                                    $announcement['publish_date']
                                    ? date(
                                        'M d, Y h:i A',
                                        strtotime($announcement['publish_date'])
                                    )
                                    : date(
                                        'M d, Y h:i A',
                                        strtotime($announcement['created_at'])
                                    ),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>

                            </small>

                            <span class="text-primary small fw-semibold">

                                Read announcement
                                <i class="bi bi-arrow-right"></i>

                            </span>

                        </div>

                    </div>

                <?php endforeach; ?>

            <?php endif; ?>

        </div>

    </div>

    <!-- RECENT SERVICE REQUESTS -->

    <div class="card dashboard-section">

        <div class="card-header">

            <h5>Recent Service Requests</h5>

        </div>

        <div class="card-body">

            <?php if (empty($recent_requests)): ?>

                <div class="empty-state">

                    <h6>No Service Requests Yet</h6>

                    <p>
                        You haven't submitted any service requests yet.
                        Start by browsing the available barangay services.
                    </p>

                    <a href="services.php"
                       class="btn dashboard-btn mx-auto"
                       style="max-width:250px;">

                        Browse Barangay Services

                    </a>

                </div>

            <?php else: ?>

                <div class="table-responsive">

                    <table class="table table-hover align-middle">

                        <thead>

                            <tr>
                                <th>Reference Number</th>
                                <th>Purpose</th>
                                <th>Status</th>
                                <th>Date Submitted</th>
                            </tr>

                        </thead>

                        <tbody>

                            <?php foreach ($recent_requests as $request): ?>

                                <tr>

                                    <td class="fw-semibold">

                                        <?= htmlspecialchars(
                                            $request['reference_number'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>

                                    </td>

                                    <td>

                                        <?= htmlspecialchars(
                                            $request['purpose'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>

                                    </td>

                                    <td>

                                        <span class="badge status-badge
                                            <?= residentStatusClass($request['status']) ?>">

                                            <?= htmlspecialchars(
                                                ucwords(str_replace(
                                                    '_',
                                                    ' ',
                                                    $request['status']
                                                )),
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>

                                        </span>

                                    </td>

                                    <td>

                                        <?= date(
                                            'M d, Y',
                                            strtotime($request['requested_at'])
                                        ) ?>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

                <div class="text-end">

                    <a href="my-requests.php"
                       class="btn btn-outline-primary">

                        View All Requests

                    </a>

                </div>

            <?php endif; ?>

        </div>

    </div>

</div>

<!-- ANNOUNCEMENT DETAILS MODAL -->

<div class="modal fade"
     id="announcementModal"
     tabindex="-1">

    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">

        <div class="modal-content">

            <div class="modal-header">

                <div>

                    <span class="announcement-category"
                          id="modalAnnouncementCategory"></span>

                    <h5 class="modal-title fw-bold text-primary mt-2"
                        id="modalAnnouncementTitle">

                    </h5>

                </div>

                <button type="button"
                        class="btn-close"
                        data-bs-dismiss="modal">

                </button>

            </div>

            <div class="modal-body">

                <small class="text-muted d-block mb-3"
                       id="modalAnnouncementDate">

                </small>

                <div class="announcement-modal-content"
                     id="modalAnnouncementContent">

                </div>

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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>

const csrfToken = <?= json_encode($csrf_token) ?>;

const announcementModalElement =
    document.getElementById('announcementModal');

const announcementModal =
    new bootstrap.Modal(announcementModalElement);

document.querySelectorAll('.announcement-item').forEach(item => {

    item.addEventListener('click', async function() {

        const announcementId = this.dataset.id;

        document.getElementById('modalAnnouncementTitle').textContent =
            this.dataset.title;

        document.getElementById('modalAnnouncementCategory').textContent =
            this.dataset.category || 'General';

        document.getElementById('modalAnnouncementDate').textContent =
            this.dataset.date;

        document.getElementById('modalAnnouncementContent').textContent =
            this.dataset.content;

        announcementModal.show();

        // Only mark as read if previously unread
        if (this.dataset.read === '0') {

            try {

                const formData = new FormData();

                formData.append('action', 'mark_read');
                formData.append('announcement_id', announcementId);
                formData.append('csrf_token', csrfToken);

                const response = await fetch('dashboard.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {

                    this.dataset.read = '1';

                    this.classList.add('announcement-read');

                    const unreadDot =
                        this.querySelector('.unread-dot');

                    if (unreadDot) {
                        unreadDot.remove();
                    }

                    // Refresh dashboard to update unread count
                    // only when the modal is closed
                }

            } catch (error) {
                console.error('Unable to mark announcement as read.');
            }

        }

    });

});


// Direct-open an announcement when arriving from a notification.
<?php if ($target_announcement_id > 0): ?>
document.addEventListener('DOMContentLoaded', function () {
    const targetAnnouncement = document.getElementById('announcement-<?= $target_announcement_id ?>');
    if (targetAnnouncement) {
        targetAnnouncement.scrollIntoView({ behavior: 'smooth', block: 'center' });
        targetAnnouncement.click();
    }
});
<?php endif; ?>

// Mark a notification as read when its dropdown link is clicked.
document.querySelectorAll('.notification-item').forEach(item => {
    item.addEventListener('click', async function() {
        if (this.dataset.read === '1') return;
        const formData = new FormData();
        formData.append('action', 'mark_notification_read');
        formData.append('notification_id', this.dataset.notificationId);
        formData.append('csrf_token', csrfToken);
        try {
            await fetch('dashboard.php', { method: 'POST', body: formData });
        } catch (err) {
            console.error('Unable to mark notification as read.');
        }
    });
});

const markAllButton = document.getElementById('markAllNotificationsRead');
if (markAllButton) {
    markAllButton.addEventListener('click', async function(event) {
        event.preventDefault();
        event.stopPropagation();
        const formData = new FormData();
        formData.append('action', 'mark_all_notifications_read');
        formData.append('csrf_token', csrfToken);
        try {
            const response = await fetch('dashboard.php', { method: 'POST', body: formData });
            const result = await response.json();
            if (result.success) window.location.reload();
        } catch (err) {
            console.error('Unable to mark all notifications as read.');
        }
    });
}

</script>

</body>
</html>
