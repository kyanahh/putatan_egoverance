
<?php
require_once '../includes/staff_auth.php';
require_once '../includes/audit_helper.php';
require_once '../includes/notification_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];
$staff_id = (int)$_SESSION['user_id'];

$success = '';
$error = '';

function staffFeedbackEscape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function staffFeedbackStatusLabel($status) {
    return match(strtolower((string)$status)) {
        'submitted' => 'Submitted',
        'under_review' => 'Under Review',
        'in_progress' => 'In Progress',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
        'rejected' => 'Rejected',
        default => ucwords(str_replace('_', ' ', (string)$status))
    };
}

function staffFeedbackStatusClass($status) {
    return match(strtolower((string)$status)) {
        'submitted' => 'primary',
        'under_review' => 'warning',
        'in_progress' => 'info',
        'resolved' => 'success',
        'closed' => 'secondary',
        'rejected' => 'danger',
        default => 'secondary'
    };
}

function staffFeedbackTypeLabel($type) {
    return match(strtolower((string)$type)) {
        'complaint' => 'Complaint',
        'suggestion' => 'Suggestion',
        'compliment' => 'Compliment',
        'other' => 'Other',
        default => ucwords(str_replace('_', ' ', (string)$type))
    };
}

/*
|--------------------------------------------------------------------------
| HANDLE POST ACTIONS
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
        | UPDATE FEEDBACK STATUS
        |--------------------------------------------------------------------------
        */

        if ($action === 'update_status') {

            $feedback_id = (int)($_POST['feedback_id'] ?? 0);
            $status = $_POST['status'] ?? '';

            $allowed_statuses = [
                'submitted',
                'under_review',
                'in_progress',
                'resolved',
                'closed',
                'rejected'
            ];

            if (
                $feedback_id <= 0 ||
                !in_array($status, $allowed_statuses, true)
            ) {

                $error = 'Invalid feedback status.';

            } else {

                try {

                    $stmt = $pdo->prepare("
                        SELECT reference_number, subject, status
                        FROM citizen_feedback
                        WHERE feedback_id = ?
                    ");

                    $stmt->execute([$feedback_id]);

                    $feedbackRecord = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$feedbackRecord) {
                        throw new Exception('Feedback not found.');
                    }

                    $old_status = $feedbackRecord['status'];

                    $resolved_at = ($status === 'resolved')
                        ? date('Y-m-d H:i:s')
                        : null;

                    $stmt = $pdo->prepare("
                        UPDATE citizen_feedback
                        SET status = ?,
                            resolved_at = ?
                        WHERE feedback_id = ?
                    ");

                    $stmt->execute([
                        $status,
                        $resolved_at,
                        $feedback_id
                    ]);

                    if ($old_status !== $status) {

                        logAudit(
                            $pdo,
                            'UPDATE FEEDBACK STATUS',
                            'Staff Complaints & Feedback',
                            'Staff updated feedback ' .
                            $feedbackRecord['reference_number'] .
                            ' (' . $feedbackRecord['subject'] .
                            ') status from ' .
                            staffFeedbackStatusLabel($old_status) .
                            ' to ' .
                            staffFeedbackStatusLabel($status) . '.',
                            $staff_id,
                            'staff'
                        );
                    }

                    header('Location: feedback.php?success=status');
                    exit;

                } catch (Exception $ex) {

                    $error = 'Unable to update feedback status.';

                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | SEND OFFICIAL RESPONSE
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'respond') {

            $feedback_id = (int)($_POST['feedback_id'] ?? 0);
            $response_message = trim($_POST['response_message'] ?? '');

            if ($feedback_id <= 0 || $response_message === '') {

                $error = 'Please provide a valid response.';

            } else {

                try {

                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare("
                        SELECT
                            cf.feedback_id,
                            cf.reference_number,
                            cf.subject,
                            rp.user_id AS resident_user_id
                        FROM citizen_feedback cf
                        LEFT JOIN resident_profiles rp
                            ON cf.resident_id = rp.resident_id
                        WHERE cf.feedback_id = ?
                        FOR UPDATE
                    ");

                    $stmt->execute([$feedback_id]);

                    $feedbackRecord = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$feedbackRecord) {
                        throw new Exception('Feedback not found.');
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | INSERT RESPONSE
                    |--------------------------------------------------------------------------
                    */

                    $stmt = $pdo->prepare("
                        INSERT INTO feedback_responses
                        (
                            feedback_id,
                            responded_by,
                            response_message
                        )
                        VALUES (?, ?, ?)
                    ");

                    $stmt->execute([
                        $feedback_id,
                        $staff_id,
                        $response_message
                    ]);

                    /*
                    |--------------------------------------------------------------------------
                    | UPDATE SUBMITTED FEEDBACK
                    |--------------------------------------------------------------------------
                    */

                    $stmt = $pdo->prepare("
                        UPDATE citizen_feedback
                        SET status = 'under_review'
                        WHERE feedback_id = ?
                          AND status = 'submitted'
                    ");

                    $stmt->execute([$feedback_id]);

                    $pdo->commit();

                    /*
                    |--------------------------------------------------------------------------
                    | AUDIT LOG
                    |--------------------------------------------------------------------------
                    */

                    logAudit(
                        $pdo,
                        'SEND FEEDBACK RESPONSE',
                        'Staff Complaints & Feedback',
                        'Staff sent an official response to feedback ' .
                        $feedbackRecord['reference_number'] .
                        ' (' . $feedbackRecord['subject'] . ').',
                        $staff_id,
                        'staff'
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | NOTIFY RESIDENT
                    |--------------------------------------------------------------------------
                    */

                    $resident_user_id = (int)(
                        $feedbackRecord['resident_user_id'] ?? 0
                    );

                    if ($resident_user_id > 0) {

                        $reference_number =
                            $feedbackRecord['reference_number'];

                        $subject =
                            $feedbackRecord['subject'];

                        $notificationMessage =
                            "The barangay has responded to your feedback " .
                            "({$reference_number}) regarding '{$subject}'. " .
                            "Response: {$response_message}";

                        createNotification(
                            $pdo,
                            $resident_user_id,
                            'Official Feedback Response',
                            $notificationMessage,
                            'feedback_response',
                            $feedback_id
                        );
                    }

                    header('Location: feedback.php?success=response');
                    exit;

                } catch (Exception $ex) {

                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    $error = 'Unable to submit response.';

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

        case 'status':
            $success = 'Feedback status updated successfully.';
            break;

        case 'response':
            $success = 'Official response submitted successfully.';
            break;
    }
}

/*
|--------------------------------------------------------------------------
| STATISTICS
|--------------------------------------------------------------------------
*/

$total_feedback = (int)$pdo->query("
    SELECT COUNT(*) FROM citizen_feedback
")->fetchColumn();

$total_submitted = (int)$pdo->query("
    SELECT COUNT(*) FROM citizen_feedback
    WHERE status = 'submitted'
")->fetchColumn();

$total_in_progress = (int)$pdo->query("
    SELECT COUNT(*) FROM citizen_feedback
    WHERE status IN ('under_review', 'in_progress')
")->fetchColumn();

$total_resolved = (int)$pdo->query("
    SELECT COUNT(*) FROM citizen_feedback
    WHERE status = 'resolved'
")->fetchColumn();

/*
|--------------------------------------------------------------------------
| FETCH FEEDBACK
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT
        cf.*,
        rp.first_name,
        rp.middle_name,
        rp.last_name,
        rp.suffix
    FROM citizen_feedback cf
    LEFT JOIN resident_profiles rp
        ON cf.resident_id = rp.resident_id
    ORDER BY cf.submitted_at DESC
");

$feedback_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| FETCH RESPONSES
|--------------------------------------------------------------------------
*/

$response_stmt = $pdo->query("
    SELECT
        fr.feedback_id,
        fr.response_message,
        fr.created_at,
        u.full_name AS responder_name
    FROM feedback_responses fr
    LEFT JOIN users u
        ON fr.responded_by = u.user_id
    ORDER BY fr.created_at ASC
");

$response_rows = $response_stmt->fetchAll(PDO::FETCH_ASSOC);

$responses_by_feedback = [];

foreach ($response_rows as $response) {
    $responses_by_feedback[$response['feedback_id']][] = $response;
}

/*
|--------------------------------------------------------------------------
| AUDIT PAGE VIEW
|--------------------------------------------------------------------------
*/

logAudit(
    $pdo,
    'VIEW COMPLAINTS AND FEEDBACK',
    'Staff Complaints & Feedback',
    'Staff viewed the complaints and feedback management page.',
    $staff_id,
    'staff'
);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Complaints & Feedback | Barangay Putatan Staff</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="../assets/css/style.css"
    >

    <style>

        .staff-feedback-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .staff-feedback-stat-icon {
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            font-size: 22px;
        }

        .feedback-table th {
            white-space: nowrap;
        }

        .feedback-message {
            white-space: pre-wrap;
            word-break: break-word;
        }

        .modal-header {
            background: #183487;
            color: white;
        }

        .modal-header .btn-close {
            filter: invert(1);
        }

    </style>

</head>

<body class="admin-page">

<!-- NAVBAR -->

<nav class="navbar navbar-dark barangay-navbar">

    <div class="container-fluid px-4">

        <a
            class="navbar-brand d-flex align-items-center gap-2"
            href="dashboard.php"
        >

            <img
                src="../assets/images/barangay-logo.png"
                alt="Barangay Logo"
                class="navbar-logo"
            >

            <span class="fw-bold">
                Barangay Putatan | Staff
            </span>

        </a>

        <div class="d-flex align-items-center gap-3">

            <span class="text-white">

                <i class="bi bi-person-circle me-1"></i>

                <?= staffFeedbackEscape($_SESSION['full_name'] ?? 'Staff Member') ?>

            </span>

            <a
                href="../logout.php"
                class="btn btn-yellow btn-sm"
            >

                <i class="bi bi-box-arrow-right me-1"></i>
                Logout

            </a>

        </div>

    </div>

</nav>

<!-- MAIN LAYOUT -->

<div class="container-fluid">

    <div class="row">

        <!-- SIDEBAR -->

        <aside class="col-md-3 col-lg-2 admin-sidebar p-3">

            <small class="text-muted text-uppercase fw-bold">
                Staff Portal
            </small>

            <nav class="nav flex-column gap-2 mt-3">

                <a
                    class="nav-link admin-nav-link"
                    href="dashboard.php"
                >

                    <i class="bi bi-speedometer2 me-2"></i>
                    Dashboard

                </a>

                <a
                    class="nav-link admin-nav-link"
                    href="service-requests.php"
                >

                    <i class="bi bi-file-earmark-text me-2"></i>
                    Service Requests

                </a>

                <a
                    class="nav-link admin-nav-link active"
                    href="feedback.php"
                >

                    <i class="bi bi-chat-left-text me-2"></i>
                    Complaints & Feedback

                </a>

                <a
                    class="nav-link admin-nav-link"
                    href="residents.php"
                >

                    <i class="bi bi-people me-2"></i>
                    Residents

                </a>

                <a
                    class="nav-link admin-nav-link"
                    href="announcements.php"
                >

                    <i class="bi bi-megaphone me-2"></i>
                    Announcements

                </a>

                <a class="nav-link admin-nav-link"
                    href="profile.php">
                    <i class="bi bi-person-circle me-2"></i>My Profile
                </a>

            </nav>

        </aside>

        <!-- MAIN CONTENT -->

        <main class="col-md-9 col-lg-10 px-4 py-4">

            <!-- PAGE HEADER -->

            <div class="mb-4">

                <h2 class="fw-bold text-primary-custom">

                    <i class="bi bi-chat-left-text me-2"></i>
                    Complaints & Feedback

                </h2>

                <p class="text-muted mb-0">

                    Review citizen feedback, manage concerns, and provide official responses.

                </p>

            </div>

            <!-- ALERTS -->

            <?php if ($success): ?>

                <div class="alert alert-success alert-dismissible fade show">

                    <?= staffFeedbackEscape($success) ?>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="alert"
                    ></button>

                </div>

            <?php endif; ?>

            <?php if ($error): ?>

                <div class="alert alert-danger alert-dismissible fade show">

                    <?= staffFeedbackEscape($error) ?>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="alert"
                    ></button>

                </div>

            <?php endif; ?>

            <!-- STATISTICS -->

            <div class="row g-4 mb-4">

                <!-- TOTAL -->

                <div class="col-sm-6 col-xl-3">

                    <div class="card staff-feedback-card h-100">

                        <div class="card-body">

                            <div class="d-flex justify-content-between align-items-center">

                                <div>

                                    <small class="text-muted">
                                        Total Feedback
                                    </small>

                                    <h3 class="fw-bold mb-0">
                                        <?= number_format($total_feedback) ?>
                                    </h3>

                                </div>

                                <div class="staff-feedback-stat-icon bg-primary bg-opacity-10 text-primary">

                                    <i class="bi bi-chat-left-text"></i>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

                <!-- SUBMITTED -->

                <div class="col-sm-6 col-xl-3">

                    <div class="card staff-feedback-card h-100">

                        <div class="card-body">

                            <div class="d-flex justify-content-between align-items-center">

                                <div>

                                    <small class="text-muted">
                                        Submitted
                                    </small>

                                    <h3 class="fw-bold mb-0">
                                        <?= number_format($total_submitted) ?>
                                    </h3>

                                </div>

                                <div class="staff-feedback-stat-icon bg-warning bg-opacity-10 text-warning">

                                    <i class="bi bi-hourglass-split"></i>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

                <!-- IN PROGRESS -->

                <div class="col-sm-6 col-xl-3">

                    <div class="card staff-feedback-card h-100">

                        <div class="card-body">

                            <div class="d-flex justify-content-between align-items-center">

                                <div>

                                    <small class="text-muted">
                                        Under Review / In Progress
                                    </small>

                                    <h3 class="fw-bold mb-0">
                                        <?= number_format($total_in_progress) ?>
                                    </h3>

                                </div>

                                <div class="staff-feedback-stat-icon bg-info bg-opacity-10 text-info">

                                    <i class="bi bi-arrow-repeat"></i>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

                <!-- RESOLVED -->

                <div class="col-sm-6 col-xl-3">

                    <div class="card staff-feedback-card h-100">

                        <div class="card-body">

                            <div class="d-flex justify-content-between align-items-center">

                                <div>

                                    <small class="text-muted">
                                        Resolved
                                    </small>

                                    <h3 class="fw-bold mb-0">
                                        <?= number_format($total_resolved) ?>
                                    </h3>

                                </div>

                                <div class="staff-feedback-stat-icon bg-success bg-opacity-10 text-success">

                                    <i class="bi bi-check-circle"></i>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

            <!-- FEEDBACK TABLE -->

            <div class="card border-0 shadow-sm">

                <div class="card-header bg-white py-3">

                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">

                        <h5 class="mb-0 fw-bold text-primary-custom">

                            <i class="bi bi-list-ul me-2"></i>
                            Citizen Feedback

                        </h5>

                        <div class="d-flex flex-wrap gap-2">

                            <input
                                type="text"
                                id="searchFeedback"
                                class="form-control"
                                style="max-width:250px"
                                placeholder="Search feedback..."
                            >

                            <select
                                id="filterType"
                                class="form-select"
                                style="max-width:180px"
                            >

                                <option value="">All Types</option>
                                <option value="complaint">Complaint</option>
                                <option value="suggestion">Suggestion</option>
                                <option value="compliment">Compliment</option>
                                <option value="other">Other</option>

                            </select>

                            <select
                                id="filterStatus"
                                class="form-select"
                                style="max-width:180px"
                            >

                                <option value="">All Statuses</option>
                                <option value="submitted">Submitted</option>
                                <option value="under_review">Under Review</option>
                                <option value="in_progress">In Progress</option>
                                <option value="resolved">Resolved</option>
                                <option value="closed">Closed</option>
                                <option value="rejected">Rejected</option>

                            </select>

                        </div>

                    </div>

                </div>

                <div class="card-body">

                    <?php if (empty($feedback_list)): ?>

                        <div class="text-center py-5">

                            <i class="bi bi-inbox fs-1 text-muted"></i>

                            <p class="text-muted mt-2 mb-0">

                                No citizen feedback has been submitted.

                            </p>

                        </div>

                    <?php else: ?>

                        <div class="table-responsive">

                            <table
                                class="table table-hover align-middle feedback-table"
                                id="feedbackTable"
                            >

                                <thead class="table-light">

                                    <tr>

                                        <th>Reference Number</th>
                                        <th>Citizen</th>
                                        <th>Type</th>
                                        <th>Subject</th>
                                        <th>Priority</th>
                                        <th>Status</th>
                                        <th>Date Submitted</th>
                                        <th>Actions</th>

                                    </tr>

                                </thead>

                                <tbody>

                                <?php foreach ($feedback_list as $feedback): ?>

                                    <?php

                                    $resident_name = trim(
                                        ($feedback['first_name'] ?? '') . ' ' .
                                        ($feedback['middle_name'] ?? '') . ' ' .
                                        ($feedback['last_name'] ?? '') . ' ' .
                                        ($feedback['suffix'] ?? '')
                                    );

                                    $resident_name = preg_replace(
                                        '/\s+/',
                                        ' ',
                                        $resident_name
                                    );

                                    if ((int)$feedback['is_anonymous'] === 1) {

                                        $display_name = 'Anonymous';

                                    } else {

                                        $display_name = $resident_name !== ''
                                            ? $resident_name
                                            : 'Resident';

                                    }

                                    $status_class = staffFeedbackStatusClass(
                                        $feedback['status']
                                    );

                                    $priority_class = match(
                                        strtolower((string)$feedback['priority'])
                                    ) {
                                        'low' => 'success',
                                        'medium' => 'secondary',
                                        'high' => 'warning',
                                        'urgent' => 'danger',
                                        default => 'secondary'
                                    };

                                    ?>

                                    <tr
                                        data-type="<?= staffFeedbackEscape($feedback['feedback_type']) ?>"
                                        data-status="<?= staffFeedbackEscape($feedback['status']) ?>"
                                    >

                                        <td>

                                            <small class="fw-bold">

                                                <?= staffFeedbackEscape($feedback['reference_number']) ?>

                                            </small>

                                        </td>

                                        <td>

                                            <?= staffFeedbackEscape($display_name) ?>

                                            <?php if ((int)$feedback['is_anonymous'] === 1): ?>

                                                <br>

                                                <span class="badge bg-dark">

                                                    <i class="bi bi-incognito me-1"></i>
                                                    Anonymous

                                                </span>

                                            <?php endif; ?>

                                        </td>

                                        <td>

                                            <?= staffFeedbackEscape(
                                                staffFeedbackTypeLabel($feedback['feedback_type'])
                                            ) ?>

                                        </td>

                                        <td>

                                            <div class="fw-semibold">

                                                <?= staffFeedbackEscape($feedback['subject']) ?>

                                            </div>

                                        </td>

                                        <td>

                                            <span class="badge bg-<?= $priority_class ?>">

                                                <?= staffFeedbackEscape(
                                                    ucfirst($feedback['priority'] ?? 'medium')
                                                ) ?>

                                            </span>

                                        </td>

                                        <td>

                                            <span class="badge bg-<?= $status_class ?>">

                                                <?= staffFeedbackEscape(
                                                    staffFeedbackStatusLabel($feedback['status'])
                                                ) ?>

                                            </span>

                                        </td>

                                        <td>

                                            <?php

                                            $submitted_timestamp = strtotime(
                                                $feedback['submitted_at']
                                            );

                                            echo $submitted_timestamp
                                                ? date('M d, Y h:i A', $submitted_timestamp)
                                                : 'N/A';

                                            ?>

                                        </td>

                                        <td>

                                            <button
                                                type="button"
                                                class="btn btn-outline-primary btn-sm view-feedback-btn"

                                                data-id="<?= (int)$feedback['feedback_id'] ?>"
                                                data-reference="<?= staffFeedbackEscape($feedback['reference_number']) ?>"
                                                data-name="<?= staffFeedbackEscape($display_name) ?>"
                                                data-type="<?= staffFeedbackEscape(staffFeedbackTypeLabel($feedback['feedback_type'])) ?>"
                                                data-subject="<?= staffFeedbackEscape($feedback['subject']) ?>"
                                                data-message="<?= staffFeedbackEscape($feedback['message']) ?>"
                                                data-priority="<?= staffFeedbackEscape(ucfirst($feedback['priority'] ?? 'medium')) ?>"
                                                data-status="<?= staffFeedbackEscape($feedback['status']) ?>"
                                                data-date="<?= staffFeedbackEscape(
                                                    $submitted_timestamp
                                                        ? date('M d, Y h:i A', $submitted_timestamp)
                                                        : 'N/A'
                                                ) ?>"
                                                data-anonymous="<?= (int)$feedback['is_anonymous'] ?>"
                                            >

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

            <!-- FOOTER -->

            <div class="mt-4 text-muted small">

                <i class="bi bi-shield-check me-1"></i>

                Feedback responses and status changes are recorded in the system audit trail.

            </div>

        </main>

    </div>

</div>

<!-- FEEDBACK DETAILS MODAL -->

<div
    class="modal fade"
    id="feedbackModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">

        <div class="modal-content">

            <!-- MODAL HEADER -->

            <div class="modal-header">

                <h5 class="modal-title fw-bold">

                    <i class="bi bi-chat-left-text me-2"></i>
                    Feedback Details

                </h5>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>

            </div>

            <!-- MODAL BODY -->

            <div class="modal-body">

                <div class="mb-3">

                    <small class="text-muted">
                        Reference Number
                    </small>

                    <div class="fw-bold" id="modalReference"></div>

                </div>

                <div class="row g-3 mb-3">

                    <div class="col-md-6">

                        <small class="text-muted">
                            Citizen
                        </small>

                        <div id="modalName"></div>

                    </div>

                    <div class="col-md-6">

                        <small class="text-muted">
                            Feedback Type
                        </small>

                        <div id="modalType"></div>

                    </div>

                    <div class="col-md-6">

                        <small class="text-muted">
                            Priority
                        </small>

                        <div id="modalPriority"></div>

                    </div>

                    <div class="col-md-6">

                        <small class="text-muted">
                            Date Submitted
                        </small>

                        <div id="modalDate"></div>

                    </div>

                </div>

                <hr>

                <div class="mb-3">

                    <small class="text-muted">
                        Subject
                    </small>

                    <h5
                        id="modalSubject"
                        class="fw-bold mt-1"
                    ></h5>

                </div>

                <div class="mb-4">

                    <small class="text-muted">
                        Message
                    </small>

                    <div
                        class="border rounded p-3 bg-light mt-2 feedback-message"
                        id="modalMessage"
                    ></div>

                </div>

                <hr>

                <!-- RESPONSE HISTORY -->

                <h6 class="fw-bold text-primary-custom mb-3">

                    <i class="bi bi-chat-square-text me-2"></i>
                    Official Responses

                </h6>

                <div id="responseHistory"></div>

                <hr>

                <!-- STATUS UPDATE -->

                <form method="POST" class="mb-4">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= staffFeedbackEscape($csrf_token) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="update_status"
                    >

                    <input
                        type="hidden"
                        name="feedback_id"
                        id="statusFeedbackId"
                    >

                    <label class="form-label fw-bold">
                        Update Feedback Status
                    </label>

                    <div class="d-flex flex-wrap gap-2">

                        <select
                            name="status"
                            id="modalStatus"
                            class="form-select"
                            required
                        >

                            <option value="submitted">Submitted</option>
                            <option value="under_review">Under Review</option>
                            <option value="in_progress">In Progress</option>
                            <option value="resolved">Resolved</option>
                            <option value="closed">Closed</option>
                            <option value="rejected">Rejected</option>

                        </select>

                        <button
                            type="submit"
                            class="btn btn-outline-primary"
                        >

                            <i class="bi bi-arrow-repeat me-1"></i>
                            Update Status

                        </button>

                    </div>

                </form>

                <!-- RESPONSE FORM -->

                <form method="POST">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= staffFeedbackEscape($csrf_token) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="respond"
                    >

                    <input
                        type="hidden"
                        name="feedback_id"
                        id="responseFeedbackId"
                    >

                    <label class="form-label fw-bold">

                        <i class="bi bi-send me-1"></i>
                        Send Official Response

                    </label>

                    <textarea
                        name="response_message"
                        class="form-control"
                        rows="4"
                        placeholder="Write an official response..."
                        required
                    ></textarea>

                    <div class="text-end mt-3">

                        <button
                            type="submit"
                            class="btn btn-primary"
                        >

                            <i class="bi bi-send me-1"></i>
                            Submit Response

                        </button>

                    </div>

                </form>

            </div>

            <!-- MODAL FOOTER -->

            <div class="modal-footer">

                <button
                    type="button"
                    class="btn btn-secondary"
                    data-bs-dismiss="modal"
                >

                    Close

                </button>

            </div>

        </div>

    </div>

</div>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

<script>

/*
|--------------------------------------------------------------------------
| SEARCH AND FILTER
|--------------------------------------------------------------------------
*/

function filterFeedback() {

    const search = document.getElementById('searchFeedback')
        .value.toLowerCase();

    const type = document.getElementById('filterType')
        .value.toLowerCase();

    const status = document.getElementById('filterStatus')
        .value.toLowerCase();

    document.querySelectorAll('#feedbackTable tbody tr')
        .forEach(row => {

            const rowText = row.textContent.toLowerCase();
            const rowType = row.dataset.type.toLowerCase();
            const rowStatus = row.dataset.status.toLowerCase();

            const matchesSearch = rowText.includes(search);
            const matchesType = type === '' || rowType === type;
            const matchesStatus = status === '' || rowStatus === status;

            row.style.display =
                matchesSearch && matchesType && matchesStatus
                ? ''
                : 'none';

        });
}

document.getElementById('searchFeedback')
    ?.addEventListener('input', filterFeedback);

document.getElementById('filterType')
    ?.addEventListener('change', filterFeedback);

document.getElementById('filterStatus')
    ?.addEventListener('change', filterFeedback);


/*
|--------------------------------------------------------------------------
| VIEW FEEDBACK DETAILS
|--------------------------------------------------------------------------
*/

document.querySelectorAll('.view-feedback-btn')
    .forEach(button => {

        button.addEventListener('click', function() {

            const feedbackId = this.dataset.id;

            document.getElementById('modalReference').textContent =
                this.dataset.reference;

            document.getElementById('modalName').textContent =
                this.dataset.name;

            document.getElementById('modalType').textContent =
                this.dataset.type;

            document.getElementById('modalSubject').textContent =
                this.dataset.subject;

            document.getElementById('modalMessage').textContent =
                this.dataset.message;

            document.getElementById('modalPriority').textContent =
                this.dataset.priority;

            document.getElementById('modalDate').textContent =
                this.dataset.date;

            document.getElementById('statusFeedbackId').value =
                feedbackId;

            document.getElementById('responseFeedbackId').value =
                feedbackId;

            document.getElementById('modalStatus').value =
                this.dataset.status;

            /*
            |--------------------------------------------------------------------------
            | LOAD RESPONSE HISTORY
            |--------------------------------------------------------------------------
            */

            const responses =
                <?= json_encode(
                    $responses_by_feedback,
                    JSON_HEX_TAG |
                    JSON_HEX_APOS |
                    JSON_HEX_QUOT |
                    JSON_HEX_AMP
                ) ?>;

            const feedbackResponses = responses[feedbackId] || [];

            const responseContainer =
                document.getElementById('responseHistory');

            responseContainer.innerHTML = '';

            if (feedbackResponses.length === 0) {

                responseContainer.innerHTML =
                    '<div class="alert alert-light">' +
                    'No official responses yet.' +
                    '</div>';

            } else {

                feedbackResponses.forEach(response => {

                    const card = document.createElement('div');

                    card.className =
                        'border rounded p-3 mb-3';

                    const heading = document.createElement('div');

                    heading.className =
                        'fw-bold text-primary mb-1';

                    heading.textContent =
                        response.responder_name || 'Staff Member';

                    const date = document.createElement('small');

                    date.className =
                        'text-muted d-block mb-2';

                    date.textContent =
                        response.created_at;

                    const message = document.createElement('div');

                    message.style.whiteSpace =
                        'pre-wrap';

                    message.textContent =
                        response.response_message;

                    card.appendChild(heading);
                    card.appendChild(date);
                    card.appendChild(message);

                    responseContainer.appendChild(card);

                });

            }

            new bootstrap.Modal(
                document.getElementById('feedbackModal')
            ).show();

        });

    });

</script>

</body>
</html>
