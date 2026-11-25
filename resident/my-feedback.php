
<?php
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/notification_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'resident') {
    header('Location: ../login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Escape output
function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Get resident ID
$stmt = $pdo->prepare("
    SELECT resident_id
    FROM resident_profiles
    WHERE user_id = ?
");
$stmt->execute([$user_id]);
$resident = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$resident) {
    die('Resident profile not found.');
}

$resident_id = (int)$resident['resident_id'];

// Notification direct-navigation target
$target_feedback_id = isset($_GET['feedback_id']) ? (int)$_GET['feedback_id'] : 0;

// Retrieve resident feedback and latest response
$stmt = $pdo->prepare("
    SELECT
        cf.feedback_id,
        cf.reference_number,
        cf.feedback_type,
        cf.subject,
        cf.message,
        cf.priority,
        cf.status,
        cf.is_anonymous,
        cf.submitted_at,
        cf.updated_at,
        cf.resolved_at,

        (
            SELECT fr.response_message
            FROM feedback_responses fr
            WHERE fr.feedback_id = cf.feedback_id
            ORDER BY fr.created_at DESC
            LIMIT 1
        ) AS latest_response,

        (
            SELECT fr.created_at
            FROM feedback_responses fr
            WHERE fr.feedback_id = cf.feedback_id
            ORDER BY fr.created_at DESC
            LIMIT 1
        ) AS response_date

    FROM citizen_feedback cf

    WHERE cf.resident_id = ?

    ORDER BY cf.submitted_at DESC
");

$stmt->execute([$resident_id]);
$feedback_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Status badge helper
function statusBadge($status) {
    return match($status) {
        'submitted'   => 'primary',
        'under_review'=> 'warning',
        'in_progress' => 'info',
        'resolved'    => 'success',
        'closed'      => 'secondary',
        'rejected'     => 'danger',
        default        => 'secondary'
    };
}

// Friendly status labels
function statusLabel($status) {
    return match($status) {
        'submitted'    => 'Submitted',
        'under_review' => 'Under Review',
        'in_progress'  => 'In Progress',
        'resolved'     => 'Resolved',
        'closed'       => 'Closed',
        'rejected'     => 'Rejected',
        default        => ucfirst(str_replace('_', ' ', $status))
    };
}

// Priority badge helper
function priorityBadge($priority) {
    return match($priority) {
        'low'    => 'success',
        'medium' => 'warning',
        'high'   => 'danger',
        'urgent' => 'dark',
        default  => 'secondary'
    };
}
?>

<!DOCTYPE html>
<html lang="en">
<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Feedback | Barangay Putatan</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
          rel="stylesheet">

    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        .feedback-status {
            white-space: nowrap;
        }

        .feedback-message {
            white-space: pre-wrap;
            word-break: break-word;
        }

        .feedback-card {
            transition: 0.2s ease;
        }

        .feedback-card:hover {
            transform: translateY(-2px);
        }
    </style>

</head>

<body>

<!-- NAVBAR -->
<nav class="navbar navbar-dark barangay-navbar">

    <div class="container-fluid px-3">

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
<div class="container py-4">

    <!-- PAGE HEADER -->
    <div class="mb-4">

        <h2 class="fw-bold text-primary-custom">
            <i class="bi bi-chat-left-text me-2"></i>
            My Complaints & Feedback
        </h2>

        <p class="text-muted mb-0">
            View your submitted feedback and monitor its progress.
        </p>

    </div>

    <!-- ACTION BUTTON -->
    <div class="mb-4">

        <a href="feedback.php"
           class="btn btn-primary-custom">

            <i class="bi bi-plus-circle me-1"></i>
            Submit New Feedback

        </a>

    </div>

    <!-- FEEDBACK LIST -->
    <div class="card border-0 shadow-sm">

        <div class="card-header bg-white py-3">

            <h5 class="fw-bold text-primary-custom mb-0">
                Feedback History
            </h5>

        </div>

        <div class="card-body">

            <?php if (empty($feedback_list)): ?>

                <div class="text-center py-5">

                    <i class="bi bi-chat-square-text display-4 text-muted"></i>

                    <h5 class="mt-3">
                        No feedback submitted yet
                    </h5>

                    <p class="text-muted">
                        Your complaints, suggestions, and other feedback
                        will appear here once submitted.
                    </p>

                    <a href="feedback.php"
                       class="btn btn-primary-custom">

                        Submit Feedback

                    </a>

                </div>

            <?php else: ?>

                <div class="table-responsive">

                    <table class="table table-hover align-middle">

                        <thead>

                            <tr>
                                <th>Reference Number</th>
                                <th>Subject</th>
                                <th>Type</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Date Submitted</th>
                                <th>Action</th>
                            </tr>

                        </thead>

                        <tbody>

                        <?php foreach ($feedback_list as $feedback): ?>

                            <tr id="feedback-row-<?= (int)$feedback['feedback_id'] ?>" class="<?= $target_feedback_id === (int)$feedback['feedback_id'] ? 'table-primary' : '' ?>">

                                <td class="fw-semibold">

                                    <?= e($feedback['reference_number']) ?>

                                </td>

                                <td>

                                    <?= e($feedback['subject']) ?>

                                </td>

                                <td>

                                    <?= e(ucfirst($feedback['feedback_type'])) ?>

                                </td>

                                <td>

                                    <span class="badge bg-<?= priorityBadge($feedback['priority']) ?>">

                                        <?= e(ucfirst($feedback['priority'] ?? 'Medium')) ?>

                                    </span>

                                </td>

                                <td>

                                    <span class="badge bg-<?= statusBadge($feedback['status']) ?> feedback-status">

                                        <?= e(statusLabel($feedback['status'])) ?>

                                    </span>

                                </td>

                                <td>

                                    <?= e(date(
                                        'M d, Y',
                                        strtotime($feedback['submitted_at'])
                                    )) ?>

                                </td>

                                <td>

                                    <button type="button"
                                            class="btn btn-outline-primary btn-sm view-feedback-btn"

                                            data-id="<?= (int)$feedback['feedback_id'] ?>"
                                            data-reference="<?= e($feedback['reference_number']) ?>"
                                            data-subject="<?= e($feedback['subject']) ?>"
                                            data-type="<?= e($feedback['feedback_type']) ?>"
                                            data-message="<?= e($feedback['message']) ?>"
                                            data-priority="<?= e($feedback['priority']) ?>"
                                            data-status="<?= e($feedback['status']) ?>"
                                            data-submitted="<?= e($feedback['submitted_at']) ?>"
                                            data-updated="<?= e($feedback['updated_at']) ?>"
                                            data-resolved="<?= e($feedback['resolved_at']) ?>"
                                            data-anonymous="<?= (int)$feedback['is_anonymous'] ?>"
                                            data-response="<?= e($feedback['latest_response'] ?? '') ?>"
                                            data-response-date="<?= e($feedback['response_date'] ?? '') ?>">

                                        <i class="bi bi-eye me-1"></i>
                                        View

                                    </button>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

        </div>

    </div>

</div>

<!-- VIEW FEEDBACK MODAL -->
<div class="modal fade"
     id="feedbackDetailsModal"
     tabindex="-1">

    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">

        <div class="modal-content">

            <div class="modal-header">

                <h5 class="modal-title fw-bold text-primary-custom">
                    Feedback Details
                </h5>

                <button type="button"
                        class="btn-close"
                        data-bs-dismiss="modal">
                </button>

            </div>

            <div class="modal-body">

                <!-- REFERENCE -->
                <div class="mb-3">

                    <small class="text-muted">
                        Reference Number
                    </small>

                    <h6 class="fw-bold"
                        id="modalReference">
                    </h6>

                </div>

                <hr>

                <!-- SUBJECT -->
                <div class="mb-3">

                    <label class="fw-bold">
                        Subject
                    </label>

                    <div id="modalSubject"></div>

                </div>

                <!-- TYPE AND PRIORITY -->
                <div class="row mb-3">

                    <div class="col-md-6">

                        <label class="fw-bold">
                            Feedback Type
                        </label>

                        <div id="modalType"></div>

                    </div>

                    <div class="col-md-6">

                        <label class="fw-bold">
                            Priority
                        </label>

                        <div id="modalPriority"></div>

                    </div>

                </div>

                <!-- MESSAGE -->
                <div class="mb-3">

                    <label class="fw-bold">
                        Your Message
                    </label>

                    <div class="bg-light p-3 rounded feedback-message"
                         id="modalMessage">
                    </div>

                </div>

                <!-- SUBMISSION DETAILS -->
                <div class="row mb-3">

                    <div class="col-md-6">

                        <label class="fw-bold">
                            Date Submitted
                        </label>

                        <div id="modalSubmitted"></div>

                    </div>

                    <div class="col-md-6">

                        <label class="fw-bold">
                            Status
                        </label>

                        <div id="modalStatus"></div>

                    </div>

                </div>

                <!-- ANONYMOUS -->
                <div class="mb-3"
                     id="anonymousContainer">

                    <span class="badge bg-secondary"
                          id="modalAnonymous">
                    </span>

                </div>

                <!-- BARANGAY RESPONSE -->
                <hr>

                <div class="mb-3">

                    <h6 class="fw-bold text-primary-custom">

                        <i class="bi bi-reply me-2"></i>
                        Barangay Response

                    </h6>

                    <div id="modalResponseContainer">

                        <div class="bg-light p-3 rounded feedback-message"
                             id="modalResponse">
                        </div>

                        <small class="text-muted mt-2 d-block"
                               id="modalResponseDate">
                        </small>

                    </div>

                    <div id="noResponseMessage"
                         class="alert alert-light mb-0">

                        <i class="bi bi-clock me-2"></i>
                        No response has been provided yet.

                    </div>

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

// FEEDBACK DETAILS
document.querySelectorAll('.view-feedback-btn').forEach(button => {

    button.addEventListener('click', function() {

        const data = this.dataset;

        document.getElementById('modalReference').textContent =
            data.reference;

        document.getElementById('modalSubject').textContent =
            data.subject;

        document.getElementById('modalType').textContent =
            data.type.charAt(0).toUpperCase() + data.type.slice(1);

        document.getElementById('modalMessage').textContent =
            data.message;

        document.getElementById('modalSubmitted').textContent =
            data.submitted
            ? new Date(data.submitted.replace(' ', 'T')).toLocaleString()
            : 'Not available';

        document.getElementById('modalStatus').innerHTML =
            '<span class="badge bg-' +
            getStatusColor(data.status) +
            '">' +
            escapeHtml(getStatusLabel(data.status)) +
            '</span>';

        document.getElementById('modalPriority').innerHTML =
            '<span class="badge bg-' +
            getPriorityColor(data.priority) +
            '">' +
            escapeHtml(data.priority.charAt(0).toUpperCase() + data.priority.slice(1)) +
            '</span>';

        // Anonymous indicator
        document.getElementById('modalAnonymous').textContent =
            data.anonymous === '1'
            ? 'Submitted Anonymously'
            : 'Submitted with Account Identity';

        // Response
        if (data.response && data.response.trim() !== '') {

            document.getElementById('modalResponseContainer').style.display = 'block';
            document.getElementById('noResponseMessage').style.display = 'none';

            document.getElementById('modalResponse').textContent =
                data.response;

            document.getElementById('modalResponseDate').textContent =
                data.responseDate
                ? 'Responded on: ' +
                    new Date(data.responseDate.replace(' ', 'T')).toLocaleString()
                : '';

        } else {

            document.getElementById('modalResponseContainer').style.display = 'none';
            document.getElementById('noResponseMessage').style.display = 'block';

        }

        new bootstrap.Modal(
            document.getElementById('feedbackDetailsModal')
        ).show();

    });

});

// STATUS HELPERS
function getStatusColor(status) {

    const colors = {
        submitted: 'primary',
        under_review: 'warning',
        in_progress: 'info',
        resolved: 'success',
        closed: 'secondary',
        rejected: 'danger'
    };

    return colors[status] || 'secondary';
}

function getStatusLabel(status) {

    const labels = {
        submitted: 'Submitted',
        under_review: 'Under Review',
        in_progress: 'In Progress',
        resolved: 'Resolved',
        closed: 'Closed',
        rejected: 'Rejected'
    };

    return labels[status] || status.replaceAll('_', ' ');
}

function getPriorityColor(priority) {

    const colors = {
        low: 'success',
        medium: 'warning',
        high: 'danger',
        urgent: 'dark'
    };

    return colors[priority] || 'secondary';
}

function escapeHtml(value) {

    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

}

</script>

<?php if ($target_feedback_id > 0 && array_filter($feedback_list, fn($f) => (int)$f['feedback_id'] === $target_feedback_id)): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const feedbackRow = document.getElementById('feedback-row-<?= $target_feedback_id ?>');
    const feedbackButton = document.querySelector('.view-feedback-btn[data-id="<?= $target_feedback_id ?>"]');

    if (feedbackRow) {
        feedbackRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    // Click the existing View button to populate and open the original modal.
    if (feedbackButton) {
        feedbackButton.click();
    }
});
</script>
<?php endif; ?>

</body>
</html>
