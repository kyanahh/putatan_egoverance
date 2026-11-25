<?php
date_default_timezone_set('Asia/Manila');

require_once '../includes/admin_auth.php';
require_once '../includes/audit_helper.php';
require_once '../includes/notification_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];
$admin_id = (int) $_SESSION['user_id'];

$success = '';
$error = '';

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/*
|--------------------------------------------------------------------------
| ANNOUNCEMENT NOTIFICATION HELPER
|--------------------------------------------------------------------------
| Sends a notification to approved residents when an announcement
| becomes published. Prevents duplicate notification records per resident.
|--------------------------------------------------------------------------
*/
function notifyResidentsOfAnnouncement(
    PDO $pdo,
    int $announcement_id,
    string $title,
    string $content
): void {

    try {

        // Retrieve approved resident accounts.
        $stmt = $pdo->prepare("
            SELECT user_id
            FROM users
            WHERE role = 'resident'
              AND account_status = 'approved'
        ");

        $stmt->execute();
        $residents = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $notification_title = 'New Barangay Announcement';
        $notification_message =
            'New announcement: ' . $title . '. ' .
            mb_substr(strip_tags($content), 0, 250);

        foreach ($residents as $resident_user_id) {

            $resident_user_id = (int)$resident_user_id;

            // Prevent duplicate notifications for this announcement.
            $check = $pdo->prepare("
                SELECT notification_id
                FROM notifications
                WHERE user_id = ?
                  AND notification_type = 'announcement'
                  AND related_id = ?
                LIMIT 1
            ");

            $check->execute([
                $resident_user_id,
                $announcement_id
            ]);

            if ($check->fetchColumn()) {
                continue;
            }

            createNotification(
                $pdo,
                $resident_user_id,
                $notification_title,
                $notification_message,
                'announcement',
                $announcement_id
            );
        }

    } catch (PDOException $ex) {

        error_log(
            'Announcement notification error: ' .
            $ex->getMessage()
        );
    }
}

/*
|--------------------------------------------------------------------------
| HANDLE ACTIONS
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {

        $error = 'Invalid form submission. Please try again.';

    } else {

        $action = $_POST['action'] ?? '';

        /*
        |--------------------------------------------------------------------------
        | CREATE ANNOUNCEMENT
        |--------------------------------------------------------------------------
        */
        if ($action === 'create') {

            $title = trim($_POST['title'] ?? '');
            $content = trim($_POST['content'] ?? '');
            $category = trim($_POST['category'] ?? '');
            $status = $_POST['status'] ?? 'draft';
            $publish_date = $_POST['publish_date'] ?? '';
            $expiry_date = $_POST['expiry_date'] ?? '';

            $allowed_statuses = ['draft', 'published', 'archived'];

            if ($title === '' || $content === '') {

                $error = 'Title and content are required.';

            } elseif (!in_array($status, $allowed_statuses, true)) {

                $error = 'Invalid announcement status.';

            } else {

                $publish_date = $publish_date !== ''
                    ? str_replace('T', ' ', $publish_date)
                    : null;

                $expiry_date = $expiry_date !== ''
                    ? str_replace('T', ' ', $expiry_date)
                    : null;

                try {

                    $stmt = $pdo->prepare("
                        INSERT INTO announcements
                        (
                            title,
                            content,
                            category,
                            published_by,
                            status,
                            publish_date,
                            expiry_date
                        )
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");

                    $stmt->execute([
                        $title,
                        $content,
                        $category ?: null,
                        $admin_id,
                        $status,
                        $publish_date,
                        $expiry_date
                    ]);

                    $announcement_id = (int)$pdo->lastInsertId();

                    /*
                     * Notify residents only if the announcement
                     * is published and its publication date has arrived.
                     */
                    if (
                        $status === 'published' &&
                        (
                            $publish_date === null ||
                            strtotime($publish_date) <= (time() + 60)
                        ) &&
                        (
                            $expiry_date === null ||
                            strtotime($expiry_date) > time()
                        )
                    ) {

                        notifyResidentsOfAnnouncement(
                            $pdo,
                            $announcement_id,
                            $title,
                            $content
                        );
                    }

                    header('Location: announcements.php?success=created');
                    exit;

                } catch (PDOException $ex) {

                    error_log('Announcement create error: ' . $ex->getMessage());
                    $error = 'Unable to create announcement.';
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | UPDATE ANNOUNCEMENT
        |--------------------------------------------------------------------------
        */
        elseif ($action === 'update') {

            $announcement_id = (int)($_POST['announcement_id'] ?? 0);
            $title = trim($_POST['title'] ?? '');
            $content = trim($_POST['content'] ?? '');
            $category = trim($_POST['category'] ?? '');
            $status = $_POST['status'] ?? 'draft';
            $publish_date = $_POST['publish_date'] ?? '';
            $expiry_date = $_POST['expiry_date'] ?? '';

            $allowed_statuses = ['draft', 'published', 'archived'];

            if (
                $announcement_id <= 0 ||
                $title === '' ||
                $content === ''
            ) {

                $error = 'Please provide a valid announcement title and content.';

            } elseif (!in_array($status, $allowed_statuses, true)) {

                $error = 'Invalid announcement status.';

            } else {

                $publish_date = $publish_date !== ''
                    ? str_replace('T', ' ', $publish_date)
                    : null;

                $expiry_date = $expiry_date !== ''
                    ? str_replace('T', ' ', $expiry_date)
                    : null;

                try {

                    // Retrieve the existing announcement status.
                    $old_stmt = $pdo->prepare("
                        SELECT status
                        FROM announcements
                        WHERE announcement_id = ?
                    ");

                    $old_stmt->execute([$announcement_id]);
                    $old_status = $old_stmt->fetchColumn();

                    if ($old_status === false) {

                        $error = 'Announcement not found.';

                    } else {

                        $stmt = $pdo->prepare("
                            UPDATE announcements
                            SET
                                title = ?,
                                content = ?,
                                category = ?,
                                status = ?,
                                publish_date = ?,
                                expiry_date = ?
                            WHERE announcement_id = ?
                        ");

                        $stmt->execute([
                            $title,
                            $content,
                            $category ?: null,
                            $status,
                            $publish_date,
                            $expiry_date,
                            $announcement_id
                        ]);

                        /*
                         * Notify only when the announcement transitions
                         * from a non-published state to published.
                         */
                        if (
                            $old_status !== 'published' &&
                            $status === 'published' &&
                            (
                                $publish_date === null ||
                                strtotime($publish_date) <= time()
                            ) &&
                            (
                                $expiry_date === null ||
                                strtotime($expiry_date) > time()
                            )
                        ) {

                            notifyResidentsOfAnnouncement(
                                $pdo,
                                $announcement_id,
                                $title,
                                $content
                            );
                        }

                        header('Location: announcements.php?success=updated');
                        exit;
                    }

                } catch (PDOException $ex) {

                    error_log('Announcement update error: ' . $ex->getMessage());
                    $error = 'Unable to update announcement.';
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | DELETE ANNOUNCEMENT
        |--------------------------------------------------------------------------
        */
        elseif ($action === 'delete') {

            $announcement_id = (int)($_POST['announcement_id'] ?? 0);

            if ($announcement_id <= 0) {

                $error = 'Invalid announcement.';

            } else {

                try {

                    $pdo->beginTransaction();

                    // Remove announcement read records first.
                    $stmt = $pdo->prepare("
                        DELETE FROM announcement_reads
                        WHERE announcement_id = ?
                    ");

                    $stmt->execute([$announcement_id]);

                    // Delete announcement.
                    $stmt = $pdo->prepare("
                        DELETE FROM announcements
                        WHERE announcement_id = ?
                    ");

                    $stmt->execute([$announcement_id]);

                    $pdo->commit();

                    header('Location: announcements.php?success=deleted');
                    exit;

                } catch (PDOException $ex) {

                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    error_log('Announcement delete error: ' . $ex->getMessage());
                    $error = 'Unable to delete announcement.';
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | QUICK STATUS UPDATE
        |--------------------------------------------------------------------------
        */
        elseif ($action === 'status') {

            $announcement_id = (int)($_POST['announcement_id'] ?? 0);
            $status = $_POST['status'] ?? '';

            $allowed_statuses = ['draft', 'published', 'archived'];

            if (
                $announcement_id <= 0 ||
                !in_array($status, $allowed_statuses, true)
            ) {

                $error = 'Invalid status update.';

            } else {

                try {

                    // Retrieve current status and publication details.
                    $old_stmt = $pdo->prepare("
                        SELECT
                            status,
                            title,
                            content,
                            publish_date,
                            expiry_date
                        FROM announcements
                        WHERE announcement_id = ?
                    ");

                    $old_stmt->execute([$announcement_id]);
                    $announcement = $old_stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$announcement) {

                        $error = 'Announcement not found.';

                    } else {

                        $stmt = $pdo->prepare("
                            UPDATE announcements
                            SET status = ?
                            WHERE announcement_id = ?
                        ");

                        $stmt->execute([
                            $status,
                            $announcement_id
                        ]);

                        /*
                         * Notify only when quick status changes
                         * a non-published announcement to published.
                         */
                        if (
                            $announcement['status'] !== 'published' &&
                            $status === 'published' &&
                            (
                                empty($announcement['publish_date']) ||
                                strtotime($announcement['publish_date']) <= time()
                            ) &&
                            (
                                empty($announcement['expiry_date']) ||
                                strtotime($announcement['expiry_date']) > time()
                            )
                        ) {

                            notifyResidentsOfAnnouncement(
                                $pdo,
                                $announcement_id,
                                $announcement['title'],
                                $announcement['content']
                            );
                        }

                        header('Location: announcements.php?success=status');
                        exit;
                    }

                } catch (PDOException $ex) {

                    error_log('Announcement status error: ' . $ex->getMessage());
                    $error = 'Unable to update announcement status.';
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| SUCCESS MESSAGES
|--------------------------------------------------------------------------
*/
if (isset($_GET['success'])) {

    switch ($_GET['success']) {

        case 'created':
            $success = 'Announcement created successfully.';
            break;

        case 'updated':
            $success = 'Announcement updated successfully.';
            break;

        case 'deleted':
            $success = 'Announcement deleted successfully.';
            break;

        case 'status':
            $success = 'Announcement status updated successfully.';
            break;
    }
}

/*
|--------------------------------------------------------------------------
| STATISTICS
|--------------------------------------------------------------------------
*/
$total_announcements = $pdo->query("
    SELECT COUNT(*) FROM announcements
")->fetchColumn();

$total_published = $pdo->query("
    SELECT COUNT(*) FROM announcements
    WHERE status = 'published'
")->fetchColumn();

$total_drafts = $pdo->query("
    SELECT COUNT(*) FROM announcements
    WHERE status = 'draft'
")->fetchColumn();

$total_archived = $pdo->query("
    SELECT COUNT(*) FROM announcements
    WHERE status = 'archived'
")->fetchColumn();

/*
|--------------------------------------------------------------------------
| FETCH ANNOUNCEMENTS
|--------------------------------------------------------------------------
*/
$stmt = $pdo->query("
    SELECT
        a.*,
        u.full_name AS admin_name
    FROM announcements a
    LEFT JOIN users u
        ON a.published_by = u.user_id
    ORDER BY a.created_at DESC
");

$announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Announcements | Barangay Putatan Admin</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <link rel="stylesheet" href="../assets/css/style.css">

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

            <span class="text-white">
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

                <a class="nav-link admin-nav-link active"
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

                <a class="nav-link admin-nav-link"
                   href="settings.php">
                    <i class="bi bi-gear me-2"></i>Settings
                </a>

            </nav>

        </aside>

        <!-- MAIN CONTENT -->
        <main class="col-md-9 col-lg-10 px-4 py-4">

            <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">

                <div>

                    <h2 class="fw-bold text-primary-custom">
                        Announcement Management
                    </h2>

                    <p class="text-muted mb-0">
                        Create, manage, and publish barangay announcements.
                    </p>

                </div>

                <button class="btn btn-primary-custom"
                        data-bs-toggle="modal"
                        data-bs-target="#createModal">

                    + Create Announcement

                </button>

            </div>

            <!-- ALERTS -->
            <?php if ($success): ?>

                <div class="alert alert-success alert-dismissible fade show">

                    <?= e($success) ?>

                    <button type="button"
                            class="btn-close"
                            data-bs-dismiss="alert">
                    </button>

                </div>

            <?php endif; ?>

            <?php if ($error): ?>

                <div class="alert alert-danger alert-dismissible fade show">

                    <?= e($error) ?>

                    <button type="button"
                            class="btn-close"
                            data-bs-dismiss="alert">
                    </button>

                </div>

            <?php endif; ?>

            <!-- STATISTICS -->
            <div class="row g-4 mb-4">

                <div class="col-sm-6 col-xl-3">

                    <div class="stat-card">

                        <small class="text-muted">
                            Total Announcements
                        </small>

                        <h2>
                            <?= (int)$total_announcements ?>
                        </h2>

                    </div>

                </div>

                <div class="col-sm-6 col-xl-3">

                    <div class="stat-card">

                        <small class="text-muted">
                            Published
                        </small>

                        <h2>
                            <?= (int)$total_published ?>
                        </h2>

                    </div>

                </div>

                <div class="col-sm-6 col-xl-3">

                    <div class="stat-card stat-pending">

                        <small class="text-muted">
                            Drafts
                        </small>

                        <h2>
                            <?= (int)$total_drafts ?>
                        </h2>

                    </div>

                </div>

                <div class="col-sm-6 col-xl-3">

                    <div class="stat-card">

                        <small class="text-muted">
                            Archived
                        </small>

                        <h2>
                            <?= (int)$total_archived ?>
                        </h2>

                    </div>

                </div>

            </div>

            <!-- ANNOUNCEMENT TABLE -->
            <div class="card border-0 shadow-sm">

                <div class="card-header bg-white py-3">

                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">

                        <h5 class="mb-0 fw-bold text-primary-custom">
                            Announcements
                        </h5>

                        <input type="text"
                               id="searchAnnouncement"
                               class="form-control"
                               style="max-width:300px"
                               placeholder="Search announcements...">

                    </div>

                </div>

                <div class="card-body">

                    <?php if (empty($announcements)): ?>

                        <div class="alert alert-light mb-0">
                            No announcements have been created yet.
                        </div>

                    <?php else: ?>

                        <div class="table-responsive">

                            <table class="table table-hover align-middle"
                                   id="announcementTable">

                                <thead>

                                    <tr>

                                        <th>Title</th>
                                        <th>Category</th>
                                        <th>Status</th>
                                        <th>Publication Date</th>
                                        <th>Expiration</th>
                                        <th>Actions</th>

                                    </tr>

                                </thead>

                                <tbody>

                                <?php foreach ($announcements as $announcement): ?>

                                    <?php

                                    $statusClass = match($announcement['status']) {
                                        'published' => 'success',
                                        'draft' => 'secondary',
                                        'archived' => 'dark',
                                        default => 'secondary'
                                    };

                                    ?>

                                    <tr>

                                        <td>

                                            <div class="fw-bold">
                                                <?= e($announcement['title']) ?>
                                            </div>

                                            <small class="text-muted">
                                                Created:
                                                <?= e(date(
                                                    'M d, Y',
                                                    strtotime($announcement['created_at'])
                                                )) ?>
                                            </small>

                                        </td>

                                        <td>

                                            <?= e(
                                                $announcement['category']
                                                ?: 'Uncategorized'
                                            ) ?>

                                        </td>

                                        <td>

                                            <span class="badge bg-<?= $statusClass ?>">

                                                <?= e(ucfirst($announcement['status'])) ?>

                                            </span>

                                        </td>

                                        <td>

                                            <?= $announcement['publish_date']
                                                ? e(date(
                                                    'M d, Y h:i A',
                                                    strtotime($announcement['publish_date'])
                                                ))
                                                : 'Not set' ?>

                                        </td>

                                        <td>

                                            <?= $announcement['expiry_date']
                                                ? e(date(
                                                    'M d, Y h:i A',
                                                    strtotime($announcement['expiry_date'])
                                                ))
                                                : 'No expiry' ?>

                                        </td>

                                        <td>

                                            <div class="d-flex flex-wrap gap-1">

                                                <!-- EDIT -->
                                                <button
                                                    type="button"
                                                    class="btn btn-outline-primary btn-sm edit-btn"

                                                    data-id="<?= (int)$announcement['announcement_id'] ?>"
                                                    data-title="<?= e($announcement['title']) ?>"
                                                    data-content="<?= e($announcement['content']) ?>"
                                                    data-category="<?= e($announcement['category']) ?>"
                                                    data-status="<?= e($announcement['status']) ?>"
                                                    data-publish="<?= e($announcement['publish_date']) ?>"
                                                    data-expiry="<?= e($announcement['expiry_date']) ?>">

                                                    Edit

                                                </button>

                                                <!-- QUICK STATUS -->
                                                <form method="POST">

                                                    <input type="hidden"
                                                           name="csrf_token"
                                                           value="<?= e($csrf_token) ?>">

                                                    <input type="hidden"
                                                           name="action"
                                                           value="status">

                                                    <input type="hidden"
                                                           name="announcement_id"
                                                           value="<?= (int)$announcement['announcement_id'] ?>">

                                                    <input type="hidden"
                                                           name="status"
                                                           value="<?= $announcement['status'] === 'published'
                                                                ? 'draft'
                                                                : 'published' ?>">

                                                    <button type="submit"
                                                            class="btn btn-outline-success btn-sm">

                                                        <?= $announcement['status'] === 'published'
                                                            ? 'Unpublish'
                                                            : 'Publish' ?>

                                                    </button>

                                                </form>

                                                <!-- DELETE -->
                                                <button
                                                    type="button"
                                                    class="btn btn-outline-danger btn-sm delete-btn"

                                                    data-id="<?= (int)$announcement['announcement_id'] ?>"
                                                    data-title="<?= e($announcement['title']) ?>">

                                                    Delete

                                                </button>

                                            </div>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                    <?php endif; ?>

                </div>

            </div>

        </main>

    </div>

</div>

<!-- CREATE MODAL -->
<div class="modal fade"
     id="createModal"
     tabindex="-1">

    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">

        <div class="modal-content">

            <form method="POST">

                <input type="hidden"
                       name="csrf_token"
                       value="<?= e($csrf_token) ?>">

                <input type="hidden"
                       name="action"
                       value="create">

                <div class="modal-header">

                    <h5 class="modal-title fw-bold">
                        Create Announcement
                    </h5>

                    <button type="button"
                            class="btn-close"
                            data-bs-dismiss="modal">
                    </button>

                </div>

                <div class="modal-body">

                    <div class="mb-3">

                        <label class="form-label fw-bold">
                            Announcement Title
                        </label>

                        <input type="text"
                               name="title"
                               class="form-control"
                               maxlength="200"
                               required>

                    </div>

                    <div class="mb-3">

                        <label class="form-label fw-bold">
                            Category
                        </label>

                        <input type="text"
                               name="category"
                               class="form-control"
                               placeholder="e.g. General, Health, Events, Emergency">

                    </div>

                    <div class="mb-3">

                        <label class="form-label fw-bold">
                            Content
                        </label>

                        <textarea name="content"
                                  class="form-control"
                                  rows="6"
                                  required></textarea>

                    </div>

                    <div class="row g-3">

                        <div class="col-md-6">

                            <label class="form-label fw-bold">
                                Publication Date
                            </label>

                            <input type="datetime-local"
                                   name="publish_date"
                                   class="form-control">

                        </div>

                        <div class="col-md-6">

                            <label class="form-label fw-bold">
                                Expiration Date
                            </label>

                            <input type="datetime-local"
                                   name="expiry_date"
                                   class="form-control">

                        </div>

                    </div>

                    <div class="mt-3">

                        <label class="form-label fw-bold">
                            Status
                        </label>

                        <select name="status"
                                class="form-select">

                            <option value="draft">
                                Draft
                            </option>

                            <option value="published">
                                Published
                            </option>

                            <option value="archived">
                                Archived
                            </option>

                        </select>

                    </div>

                </div>

                <div class="modal-footer">

                    <button type="button"
                            class="btn btn-secondary"
                            data-bs-dismiss="modal">
                        Cancel
                    </button>

                    <button type="submit"
                            class="btn btn-primary-custom">
                        Save Announcement
                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<!-- EDIT MODAL -->
<div class="modal fade"
     id="editModal"
     tabindex="-1">

    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">

        <div class="modal-content">

            <form method="POST">

                <input type="hidden"
                       name="csrf_token"
                       value="<?= e($csrf_token) ?>">

                <input type="hidden"
                       name="action"
                       value="update">

                <input type="hidden"
                       name="announcement_id"
                       id="editAnnouncementId">

                <div class="modal-header">

                    <h5 class="modal-title fw-bold">
                        Edit Announcement
                    </h5>

                    <button type="button"
                            class="btn-close"
                            data-bs-dismiss="modal">
                    </button>

                </div>

                <div class="modal-body">

                    <div class="mb-3">

                        <label class="form-label fw-bold">
                            Announcement Title
                        </label>

                        <input type="text"
                               name="title"
                               id="editTitle"
                               class="form-control"
                               maxlength="200"
                               required>

                    </div>

                    <div class="mb-3">

                        <label class="form-label fw-bold">
                            Category
                        </label>

                        <input type="text"
                               name="category"
                               id="editCategory"
                               class="form-control">

                    </div>

                    <div class="mb-3">

                        <label class="form-label fw-bold">
                            Content
                        </label>

                        <textarea name="content"
                                  id="editContent"
                                  class="form-control"
                                  rows="6"
                                  required></textarea>

                    </div>

                    <div class="row g-3">

                        <div class="col-md-6">

                            <label class="form-label fw-bold">
                                Publication Date
                            </label>

                            <input type="datetime-local"
                                   name="publish_date"
                                   id="editPublishDate"
                                   class="form-control">

                        </div>

                        <div class="col-md-6">

                            <label class="form-label fw-bold">
                                Expiration Date
                            </label>

                            <input type="datetime-local"
                                   name="expiry_date"
                                   id="editExpiryDate"
                                   class="form-control">

                        </div>

                    </div>

                    <div class="mt-3">

                        <label class="form-label fw-bold">
                            Status
                        </label>

                        <select name="status"
                                id="editStatus"
                                class="form-select">

                            <option value="draft">
                                Draft
                            </option>

                            <option value="published">
                                Published
                            </option>

                            <option value="archived">
                                Archived
                            </option>

                        </select>

                    </div>

                </div>

                <div class="modal-footer">

                    <button type="button"
                            class="btn btn-secondary"
                            data-bs-dismiss="modal">
                        Cancel
                    </button>

                    <button type="submit"
                            class="btn btn-primary-custom">
                        Update Announcement
                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<!-- DELETE MODAL -->
<div class="modal fade"
     id="deleteModal"
     tabindex="-1">

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <form method="POST">

                <input type="hidden"
                       name="csrf_token"
                       value="<?= e($csrf_token) ?>">

                <input type="hidden"
                       name="action"
                       value="delete">

                <input type="hidden"
                       name="announcement_id"
                       id="deleteAnnouncementId">

                <div class="modal-header">

                    <h5 class="modal-title fw-bold">
                        Delete Announcement
                    </h5>

                    <button type="button"
                            class="btn-close"
                            data-bs-dismiss="modal">
                    </button>

                </div>

                <div class="modal-body">

                    Are you sure you want to delete:

                    <strong id="deleteAnnouncementTitle"></strong>?

                    <p class="text-danger small mt-2 mb-0">
                        This action cannot be undone.
                    </p>

                </div>

                <div class="modal-footer">

                    <button type="button"
                            class="btn btn-secondary"
                            data-bs-dismiss="modal">
                        Cancel
                    </button>

                    <button type="submit"
                            class="btn btn-danger">
                        Delete Announcement
                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>

// SEARCH
document.getElementById('searchAnnouncement')
    ?.addEventListener('input', function() {

        const search = this.value.toLowerCase();
        const rows = document.querySelectorAll('#announcementTable tbody tr');

        rows.forEach(row => {

            row.style.display =
                row.textContent.toLowerCase().includes(search)
                ? ''
                : 'none';

        });

    });

// EDIT
document.querySelectorAll('.edit-btn').forEach(button => {

    button.addEventListener('click', function() {

        document.getElementById('editAnnouncementId').value =
            this.dataset.id;

        document.getElementById('editTitle').value =
            this.dataset.title;

        document.getElementById('editContent').value =
            this.dataset.content;

        document.getElementById('editCategory').value =
            this.dataset.category || '';

        document.getElementById('editStatus').value =
            this.dataset.status;

        document.getElementById('editPublishDate').value =
            this.dataset.publish
            ? this.dataset.publish.replace(' ', 'T').substring(0, 16)
            : '';

        document.getElementById('editExpiryDate').value =
            this.dataset.expiry
            ? this.dataset.expiry.replace(' ', 'T').substring(0, 16)
            : '';

        new bootstrap.Modal(
            document.getElementById('editModal')
        ).show();

    });

});

// DELETE
document.querySelectorAll('.delete-btn').forEach(button => {

    button.addEventListener('click', function() {

        document.getElementById('deleteAnnouncementId').value =
            this.dataset.id;

        document.getElementById('deleteAnnouncementTitle').textContent =
            this.dataset.title;

        new bootstrap.Modal(
            document.getElementById('deleteModal')
        ).show();

    });

});

</script>

</body>
</html>
