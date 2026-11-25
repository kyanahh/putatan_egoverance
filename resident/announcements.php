
<?php
require_once '../config/database.php';
require_once '../includes/notification_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Authentication check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'resident') {
    header('Location: ../login.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];

// Escape helper
function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Fetch currently active published announcements
$stmt = $pdo->prepare("
    SELECT
        a.announcement_id,
        a.title,
        a.content,
        a.category,
        a.image_path,
        a.publish_date,
        a.expiry_date,
        a.created_at,
        u.full_name AS admin_name,
        ar.read_id,
        ar.viewed_at
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
        CASE WHEN ar.read_id IS NULL THEN 0 ELSE 1 END,
        COALESCE(a.publish_date, a.created_at) DESC
");

$stmt->execute([$user_id]);
$announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Automatically mark announcements as read when displayed
foreach ($announcements as $announcement) {

    if (empty($announcement['read_id'])) {

        try {

            $insert = $pdo->prepare("
                INSERT INTO announcement_reads
                    (announcement_id, user_id)
                VALUES (?, ?)
            ");

            $insert->execute([
                $announcement['announcement_id'],
                $user_id
            ]);

        } catch (PDOException $ex) {
            // Avoid interrupting the page if a read record already exists
        }
    }
}

// Counts
$total_announcements = count($announcements);

$unread_count = 0;

foreach ($announcements as $announcement) {
    if (empty($announcement['read_id'])) {
        $unread_count++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Announcements | Barangay Putatan</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
          rel="stylesheet">

    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        .announcement-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(0,0,0,0.06);
            transition: transform .2s ease, box-shadow .2s ease;
        }

        .announcement-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 18px rgba(0,0,0,0.09);
        }

        .announcement-content-preview {
            white-space: pre-line;
            color: #555;
        }

        .announcement-title {
            color: #10245a;
        }

        .announcement-unread {
            border-left: 5px solid #ffc107;
        }

        .category-badge {
            background-color: #e8eefc;
            color: #10245a;
        }

        .announcement-date {
            font-size: .85rem;
            color: #6c757d;
        }

        .announcement-modal-content {
            white-space: pre-line;
            line-height: 1.7;
        }
    </style>

</head>

<body>

<!-- NAVBAR -->
<nav class="navbar navbar-dark barangay-navbar">

    <div class="container-fluid px-4">

        <a class="navbar-brand d-flex align-items-center gap-2"
           href="dashboard.php">

            <img src="../assets/images/barangay-logo.png"
                 alt="Barangay Logo"
                 class="navbar-logo">

            <span class="fw-bold">
                Barangay Putatan
            </span>

        </a>

        <div class="d-flex align-items-center gap-2">

            <a href="dashboard.php"
               class="btn btn-light btn-sm">
                Dashboard
            </a>

            <a href="../logout.php"
               class="btn btn-yellow btn-sm">
                Logout
            </a>

        </div>

    </div>

</nav>

<!-- MAIN CONTENT -->
<div class="container py-4 py-md-5">

    <div class="mb-4">

        <h2 class="fw-bold text-primary-custom">
            <i class="bi bi-megaphone me-2"></i>
            Barangay Announcements
        </h2>

        <p class="text-muted mb-0">
            Stay updated with the latest announcements,
            programs, events, and notices from Barangay Putatan.
        </p>

    </div>

    <!-- SUMMARY -->
    <div class="row g-3 mb-4">

        <div class="col-md-6">

            <div class="stat-card">

                <small class="text-muted">
                    Active Announcements
                </small>

                <h2>
                    <?= (int)$total_announcements ?>
                </h2>

            </div>

        </div>

        <div class="col-md-6">

            <div class="stat-card stat-pending">

                <small class="text-muted">
                    Unread Announcements
                </small>

                <h2>
                    <?= (int)$unread_count ?>
                </h2>

            </div>

        </div>

    </div>

    <!-- SEARCH -->
    <div class="card border-0 shadow-sm mb-4">

        <div class="card-body">

            <div class="input-group">

                <span class="input-group-text bg-white">
                    <i class="bi bi-search"></i>
                </span>

                <input type="text"
                       id="searchAnnouncement"
                       class="form-control"
                       placeholder="Search announcements by title, category, or content...">

            </div>

        </div>

    </div>

    <!-- ANNOUNCEMENTS -->
    <?php if (empty($announcements)): ?>

        <div class="card border-0 shadow-sm">

            <div class="card-body text-center py-5">

                <i class="bi bi-megaphone display-4 text-muted"></i>

                <h5 class="fw-bold mt-3">
                    No Announcements Available
                </h5>

                <p class="text-muted mb-0">
                    There are currently no active announcements
                    from Barangay Putatan.
                </p>

            </div>

        </div>

    <?php else: ?>

        <div class="row g-4" id="announcementContainer">

            <?php foreach ($announcements as $announcement): ?>

                <?php
                    $isUnread = empty($announcement['read_id']);

                    $category = $announcement['category']
                        ?: 'General';
                ?>

                <div class="col-12 announcement-item">

                    <div class="card announcement-card
                        <?= $isUnread ? 'announcement-unread' : '' ?>">

                        <div class="card-body p-4">

                            <div class="d-flex flex-wrap justify-content-between
                                        align-items-start gap-2 mb-3">

                                <div>

                                    <span class="badge category-badge mb-2">

                                        <i class="bi bi-tag me-1"></i>

                                        <?= e($category) ?>

                                    </span>

                                    <?php if ($isUnread): ?>

                                        <span class="badge bg-warning text-dark ms-1">
                                            <i class="bi bi-circle-fill me-1"
                                               style="font-size:7px"></i>
                                            New
                                        </span>

                                    <?php endif; ?>

                                </div>

                                <small class="announcement-date">

                                    <i class="bi bi-calendar-event me-1"></i>

                                    <?= $announcement['publish_date']
                                        ? e(date(
                                            'M d, Y h:i A',
                                            strtotime($announcement['publish_date'])
                                        ))
                                        : e(date(
                                            'M d, Y',
                                            strtotime($announcement['created_at'])
                                        )) ?>

                                </small>

                            </div>

                            <h5 class="fw-bold announcement-title mb-3">

                                <?= e($announcement['title']) ?>

                            </h5>

                            <p class="announcement-content-preview mb-3">

                                <?= e(mb_strimwidth(
                                    $announcement['content'],
                                    0,
                                    250,
                                    '...'
                                )) ?>

                            </p>

                            <div class="d-flex flex-wrap
                                        justify-content-between
                                        align-items-center gap-2">

                                <small class="text-muted">

                                    <i class="bi bi-person-circle me-1"></i>

                                    <?= e($announcement['admin_name']
                                        ?: 'Barangay Administrator') ?>

                                </small>

                                <button type="button"
                                        class="btn btn-primary-custom btn-sm view-announcement"

                                        data-title="<?= e($announcement['title']) ?>"
                                        data-content="<?= e($announcement['content']) ?>"
                                        data-category="<?= e($category) ?>"
                                        data-date="<?= e(
                                            $announcement['publish_date']
                                                ? date(
                                                    'M d, Y h:i A',
                                                    strtotime($announcement['publish_date'])
                                                )
                                                : date(
                                                    'M d, Y',
                                                    strtotime($announcement['created_at'])
                                                )
                                        ) ?>"
                                        data-admin="<?= e($announcement['admin_name']
                                            ?: 'Barangay Administrator') ?>">

                                    <i class="bi bi-eye me-1"></i>
                                    Read Announcement

                                </button>

                            </div>

                        </div>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

        <!-- NO SEARCH RESULTS -->
        <div id="noSearchResults"
             class="alert alert-light text-center mt-4 d-none">

            <i class="bi bi-search me-2"></i>
            No announcements match your search.

        </div>

    <?php endif; ?>

</div>

<!-- VIEW ANNOUNCEMENT MODAL -->
<div class="modal fade"
     id="announcementModal"
     tabindex="-1">

    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">

        <div class="modal-content">

            <div class="modal-header">

                <div>

                    <span id="modalCategory"
                          class="badge category-badge mb-2">
                    </span>

                    <h5 class="modal-title fw-bold announcement-title"
                        id="modalTitle">
                    </h5>

                </div>

                <button type="button"
                        class="btn-close"
                        data-bs-dismiss="modal">
                </button>

            </div>

            <div class="modal-body">

                <div class="announcement-date mb-3">

                    <i class="bi bi-calendar-event me-1"></i>

                    <span id="modalDate"></span>

                    <span class="mx-2">|</span>

                    <i class="bi bi-person-circle me-1"></i>

                    <span id="modalAdmin"></span>

                </div>

                <hr>

                <div class="announcement-modal-content"
                     id="modalContent">
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

// SEARCH ANNOUNCEMENTS
const searchInput = document.getElementById('searchAnnouncement');
const announcementItems = document.querySelectorAll('.announcement-item');
const noSearchResults = document.getElementById('noSearchResults');

searchInput?.addEventListener('input', function() {

    const search = this.value.toLowerCase().trim();
    let visibleCount = 0;

    announcementItems.forEach(item => {

        const matches = item.textContent.toLowerCase().includes(search);

        item.classList.toggle('d-none', !matches);

        if (matches) {
            visibleCount++;
        }

    });

    if (noSearchResults) {
        noSearchResults.classList.toggle(
            'd-none',
            visibleCount > 0
        );
    }

});

// VIEW ANNOUNCEMENT
document.querySelectorAll('.view-announcement').forEach(button => {

    button.addEventListener('click', function() {

        document.getElementById('modalTitle').textContent =
            this.dataset.title;

        document.getElementById('modalContent').textContent =
            this.dataset.content;

        document.getElementById('modalCategory').textContent =
            this.dataset.category;

        document.getElementById('modalDate').textContent =
            this.dataset.date;

        document.getElementById('modalAdmin').textContent =
            this.dataset.admin;

        const modal = new bootstrap.Modal(
            document.getElementById('announcementModal')
        );

        modal.show();

    });

});

</script>

</body>
</html>
