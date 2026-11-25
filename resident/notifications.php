<?php
session_start();

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/notification_helper.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'resident') {
    header("Location: ../login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$full_name = $_SESSION['full_name'] ?? 'Resident';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

function notificationTarget(string $type, ?int $related_id = null): string {

    $type = strtolower($type);

    if (in_array($type, ['service_request', 'service_request_update'], true)) {
        return 'my-requests.php' . (
            $related_id ? '?request_id=' . $related_id : ''
        );
    }

    if (in_array($type, ['feedback_response', 'feedback', 'feedback_update'], true)) {
        return 'my-feedback.php' . (
            $related_id ? '?feedback_id=' . $related_id : ''
        );
    }

    if ($type === 'announcement') {
        return 'dashboard.php' . (
            $related_id ? '?announcement_id=' . $related_id : ''
        );
    }

    if (in_array($type, ['account_approval', 'account_rejection'], true)) {
        return 'dashboard.php';
    }

    return 'notifications.php';
}

/* Handle notification actions */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        header('Location: notifications.php?error=csrf');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'mark_one') {
        $notification_id = (int)($_POST['notification_id'] ?? 0);

        if ($notification_id > 0) {
            markNotificationAsRead($pdo, $user_id, $notification_id);
        }

        $target = $_POST['target'] ?? 'notifications.php';
        $allowed_targets = [
            'notifications.php',
            'dashboard.php',
            'my-requests.php',
            'my-feedback.php',
            'profile.php'
        ];

        if (!in_array($target, $allowed_targets, true)) {
            $target = 'notifications.php';
        }

        header('Location: ' . $target);
        exit;
    }

    if ($action === 'mark_all') {
        markAllNotificationsAsRead($pdo, $user_id);
        header('Location: notifications.php?success=all_read');
        exit;
    }
}

/* Filters */
$filter = $_GET['filter'] ?? 'all';
if (!in_array($filter, ['all', 'unread', 'read'], true)) {
    $filter = 'all';
}

$where = 'WHERE user_id = ?';
$params = [$user_id];

if ($filter === 'unread') {
    $where .= ' AND is_read = 0';
} elseif ($filter === 'read') {
    $where .= ' AND is_read = 1';
}

/* Fetch notification history for this resident only */
$stmt = $pdo->prepare("
    SELECT notification_id, title, message, notification_type,
           related_id, is_read, created_at
    FROM notifications
    $where
    ORDER BY created_at DESC, notification_id DESC
");
$stmt->execute($params);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Counts */
$stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS unread,
        SUM(CASE WHEN is_read = 1 THEN 1 ELSE 0 END) AS read_count
    FROM notifications
    WHERE user_id = ?
");
$stmt->execute([$user_id]);
$counts = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'unread' => 0, 'read_count' => 0];

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function notificationIcon(string $type): string {
    return match (strtolower($type)) {
        'announcement' => 'bi-megaphone',
        'service_request', 'service_request_update' => 'bi-file-earmark-text',
        'feedback_response', 'feedback', 'feedback_update' => 'bi-chat-left-text',
        'account_approval' => 'bi-check-circle',
        'account_rejection' => 'bi-x-circle',
        default => 'bi-bell'
    };
}

function notificationIconColor(string $type): string {
    return match (strtolower($type)) {
        'account_approval' => 'text-success',
        'account_rejection' => 'text-danger',
        'announcement' => 'text-primary',
        'service_request', 'service_request_update' => 'text-warning',
        'feedback_response', 'feedback', 'feedback_update' => 'text-info',
        default => 'text-secondary'
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications | Barangay Putatan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6fa; }
        .resident-navbar { background-color: #142f82; padding: 12px 25px; box-shadow: 0 2px 8px rgba(0,0,0,.08); }
        .resident-navbar .navbar-brand { display:flex; align-items:center; color:white; font-weight:700; }
        .resident-navbar img { object-fit:contain; margin-right:10px; }
        .page-container { max-width:1200px; margin:auto; padding:35px 20px; }
        .page-title { color:#142f82; font-weight:700; }
        .notification-card { border:1px solid #e9ecef; border-radius:12px; background:#fff; padding:18px; transition:.2s ease; }
        .notification-card:hover { border-color:#142f82; box-shadow:0 4px 12px rgba(20,47,130,.07); }
        .notification-card.unread { border-left:4px solid #142f82; background:#f8faff; }
        .notification-icon { width:44px; height:44px; border-radius:50%; background:#f1f4fa; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:20px; }
        .btn-profile { background:white; color:#142f82; border:none; font-weight:600; }
        .btn-profile:hover { background:#e9eefb; color:#142f82; }
        .btn-yellow { background:#ffdd00; color:#142f82; border:none; font-weight:600; }
        .btn-yellow:hover { background:#f0ce00; color:#142f82; }
        .filter-link { text-decoration:none; border-radius:8px; padding:8px 14px; font-weight:500; }
        .filter-link.active { background:#142f82; color:white; }
        .filter-link:not(.active) { color:#142f82; background:#eef2ff; }
        .empty-state { text-align:center; padding:50px 20px; }
        @media(max-width:768px) { .resident-navbar { padding:12px 15px; } .page-container { padding:25px 15px; } }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark resident-navbar">
    <div class="container-fluid">
        <a class="navbar-brand" href="dashboard.php">
            <img src="../assets/images/barangay-logo.png" width="42" height="42" alt="Barangay Logo">
            Barangay Putatan
        </a>
        <div class="ms-auto d-flex gap-2">
            <a href="dashboard.php" class="btn btn-profile btn-sm">Dashboard</a>
            <a href="profile.php" class="btn btn-profile btn-sm">My Profile</a>
            <a href="../logout.php" class="btn btn-yellow btn-sm">Logout</a>
        </div>
    </div>
</nav>

<div class="page-container">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h2 class="page-title mb-1"><i class="bi bi-bell me-2"></i>Notifications</h2>
            <p class="text-muted mb-0">View updates and messages from Barangay Putatan.</p>
        </div>
        <?php if ((int)$counts['unread'] > 0): ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                <input type="hidden" name="action" value="mark_all">
                <button type="submit" class="btn btn-outline-primary">
                    <i class="bi bi-check2-all me-1"></i> Mark all as read
                </button>
            </form>
        <?php endif; ?>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4"><div class="card border-0 shadow-sm h-100"><div class="card-body"><small class="text-muted">All Notifications</small><h3 class="fw-bold text-primary mb-0"><?= (int)$counts['total'] ?></h3></div></div></div>
        <div class="col-md-4"><div class="card border-0 shadow-sm h-100"><div class="card-body"><small class="text-muted">Unread</small><h3 class="fw-bold text-danger mb-0"><?= (int)$counts['unread'] ?></h3></div></div></div>
        <div class="col-md-4"><div class="card border-0 shadow-sm h-100"><div class="card-body"><small class="text-muted">Read</small><h3 class="fw-bold text-success mb-0"><?= (int)$counts['read_count'] ?></h3></div></div></div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="d-flex flex-wrap gap-2 mb-4">
                <a class="filter-link <?= $filter === 'all' ? 'active' : '' ?>" href="notifications.php?filter=all">All (<?= (int)$counts['total'] ?>)</a>
                <a class="filter-link <?= $filter === 'unread' ? 'active' : '' ?>" href="notifications.php?filter=unread">Unread (<?= (int)$counts['unread'] ?>)</a>
                <a class="filter-link <?= $filter === 'read' ? 'active' : '' ?>" href="notifications.php?filter=read">Read (<?= (int)$counts['read_count'] ?>)</a>
            </div>

            <?php if (empty($notifications)): ?>
                <div class="empty-state">
                    <i class="bi bi-bell-slash text-muted" style="font-size:48px;"></i>
                    <h5 class="mt-3 text-primary fw-bold">No notifications found</h5>
                    <p class="text-muted mb-0">
                        <?= $filter === 'unread' ? 'You have no unread notifications.' : ($filter === 'read' ? 'You have no read notifications yet.' : 'Notifications from the barangay will appear here.') ?>
                    </p>
                </div>
            <?php else: ?>
                <div class="d-flex flex-column gap-3">
                    <?php foreach ($notifications as $notification):
                        $target = notificationTarget(
                        (string)$notification['notification_type'],
                        !empty($notification['related_id'])
                            ? (int)$notification['related_id']
                            : null
                    );
                        $isUnread = (int)$notification['is_read'] === 0;
                    ?>
                        <div class="notification-card <?= $isUnread ? 'unread' : '' ?>">
                            <div class="d-flex align-items-start gap-3">
                                <div class="notification-icon <?= notificationIconColor((string)$notification['notification_type']) ?>">
                                    <i class="bi <?= h(notificationIcon((string)$notification['notification_type'])) ?>"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                        <div>
                                            <h6 class="fw-bold mb-1"><?= h($notification['title']) ?> <?php if ($isUnread): ?><span class="badge bg-primary ms-1">Unread</span><?php endif; ?></h6>
                                            <p class="mb-2 text-secondary"><?= nl2br(h($notification['message'])) ?></p>
                                            <small class="text-muted"><i class="bi bi-clock me-1"></i><?= h(date('M d, Y h:i A', strtotime($notification['created_at']))) ?></small>
                                        </div>
                                    </div>
                                    <div class="d-flex flex-wrap gap-2 mt-3">
                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                                            <input type="hidden" name="action" value="mark_one">
                                            <input type="hidden" name="notification_id" value="<?= (int)$notification['notification_id'] ?>">
                                            <input type="hidden" name="target" value="<?= h($target) ?>">
                                            <button type="submit" class="btn btn-sm btn-primary">
                                                <i class="bi bi-arrow-right-circle me-1"></i><?= $isUnread ? 'Mark as read & open' : 'Open' ?>
                                            </button>
                                        </form>
                                        <?php if ($isUnread): ?>
                                            <form method="POST">
                                                <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                                                <input type="hidden" name="action" value="mark_one">
                                                <input type="hidden" name="notification_id" value="<?= (int)$notification['notification_id'] ?>">
                                                <input type="hidden" name="target" value="notifications.php?filter=unread">
                                                <button type="submit" class="btn btn-sm btn-outline-secondary">Mark read only</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="text-center mt-4">
        <a href="dashboard.php" class="btn btn-outline-primary"><i class="bi bi-arrow-left me-1"></i> Back to Dashboard</a>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
