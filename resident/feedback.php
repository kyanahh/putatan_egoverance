
<?php
require_once '../config/database.php';
require_once '../includes/notification_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Resident authentication
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'resident') {
    header('Location: ../login.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];

$success = '';
$error = '';

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Retrieve resident profile
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

$resident_id = (int) $resident['resident_id'];

// Dynamically identify category columns
$categories = [];

try {
    $columns = $pdo->query("SHOW COLUMNS FROM feedback_categories")
                   ->fetchAll(PDO::FETCH_COLUMN);

    $idColumn = in_array('category_id', $columns, true)
        ? 'category_id'
        : null;

    $nameColumn = null;

    foreach (['category_name', 'name', 'category', 'title'] as $candidate) {
        if (in_array($candidate, $columns, true)) {
            $nameColumn = $candidate;
            break;
        }
    }

    if ($idColumn && $nameColumn) {

        $sql = "SELECT `$idColumn` AS category_id,
                       `$nameColumn` AS category_name
                FROM feedback_categories";

        if (in_array('is_active', $columns, true)) {
            $sql .= " WHERE is_active = 1";
        } elseif (in_array('status', $columns, true)) {
            $sql .= " WHERE status = 'active'";
        }

        $sql .= " ORDER BY `$nameColumn` ASC";

        $categories = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $ex) {
    $categories = [];
}

// Handle feedback submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($csrf_token, $_POST['csrf_token'])
    ) {
        $error = 'Invalid form submission. Please try again.';
    } else {

        $feedback_type = $_POST['feedback_type'] ?? '';
        $category_id = !empty($_POST['category_id'])
            ? (int) $_POST['category_id']
            : null;

        $subject = trim($_POST['subject'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $priority = $_POST['priority'] ?? 'medium';
        $is_anonymous = isset($_POST['is_anonymous']) ? 1 : 0;

        $allowed_types = [
            'complaint',
            'suggestion',
            'compliment',
            'other'
        ];

        $allowed_priorities = [
            'low',
            'medium',
            'high',
            'urgent'
        ];

        if (!in_array($feedback_type, $allowed_types, true)) {
            $error = 'Please select a valid feedback type.';
        } elseif ($subject === '') {
            $error = 'Please enter a subject.';
        } elseif ($message === '') {
            $error = 'Please enter your feedback message.';
        } elseif (!in_array($priority, $allowed_priorities, true)) {
            $error = 'Invalid priority selection.';
        } else {

            // Validate category if provided
            if ($category_id !== null) {

                $categoryExists = false;

                foreach ($categories as $cat) {
                    if ((int)$cat['category_id'] === $category_id) {
                        $categoryExists = true;
                        break;
                    }
                }

                if (!$categoryExists) {
                    $error = 'Please select a valid feedback category.';
                }
            }

            if (!$error) {

                // Generate unique reference number
                $reference_number = 'FDB-' . date('Ymd') . '-' .
                    strtoupper(bin2hex(random_bytes(3)));

                try {

                    $stmt = $pdo->prepare("
                        INSERT INTO citizen_feedback
                        (
                            reference_number,
                            resident_id,
                            category_id,
                            feedback_type,
                            subject,
                            message,
                            priority,
                            status,
                            is_anonymous,
                            submitted_at
                        )
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'submitted', ?, NOW())
                    ");

                    $stmt->execute([
                        $reference_number,
                        $resident_id,
                        $category_id,
                        $feedback_type,
                        $subject,
                        $message,
                        $priority,
                        $is_anonymous
                    ]);

                    header(
                        'Location: feedback.php?success=1&ref=' .
                        urlencode($reference_number)
                    );
                    exit;

                } catch (PDOException $ex) {
                    $error = 'Unable to submit your feedback. Please try again.';
                }
            }
        }
    }
}

// Submission success message
if (isset($_GET['success']) && $_GET['success'] == '1') {
    $success = 'Your feedback has been submitted successfully. Reference Number: ' .
        ($_GET['ref'] ?? '');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Submit Feedback | Barangay Putatan</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
          rel="stylesheet">

    <link rel="stylesheet" href="../assets/css/style.css">

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
<div class="container py-5">

    <div class="row justify-content-center">

        <div class="col-lg-9 col-xl-8">

            <div class="mb-4">

                <h2 class="fw-bold text-primary-custom">
                    <i class="bi bi-chat-left-text me-2"></i>
                    Complaints & Feedback
                </h2>

                <p class="text-muted">
                    Share your complaints, suggestions, compliments,
                    or other feedback to help improve barangay services.
                </p>

            </div>

            <?php if ($success): ?>

                <div class="alert alert-success">

                    <h5 class="fw-bold">
                        <i class="bi bi-check-circle me-2"></i>
                        Feedback Submitted Successfully
                    </h5>

                    <p class="mb-2">
                        Thank you for sharing your feedback with Barangay Putatan.
                    </p>

                    <div class="bg-white border rounded p-3">

                        <small class="text-muted">
                            Your Reference Number
                        </small>

                        <h5 class="fw-bold text-primary-custom mb-0">
                            <?= e($_GET['ref'] ?? '') ?>
                        </h5>

                    </div>

                    <p class="small mt-3 mb-0">
                        Please keep your reference number for future tracking.
                    </p>

                </div>

            <?php endif; ?>

            <?php if ($error): ?>

                <div class="alert alert-danger">
                    <?= e($error) ?>
                </div>

            <?php endif; ?>

            <!-- FEEDBACK FORM -->
            <div class="card border-0 shadow-sm">

                <div class="card-header bg-white py-3">

                    <h5 class="fw-bold mb-0 text-primary-custom">
                        Submit Your Feedback
                    </h5>

                </div>

                <div class="card-body p-4">

                    <form method="POST">

                        <input type="hidden"
                               name="csrf_token"
                               value="<?= e($csrf_token) ?>">

                        <!-- Feedback Type -->
                        <div class="mb-3">

                            <label class="form-label fw-bold">
                                Feedback Type <span class="text-danger">*</span>
                            </label>

                            <select name="feedback_type"
                                    class="form-select"
                                    required>

                                <option value="">
                                    Select feedback type
                                </option>

                                <option value="complaint">
                                    Complaint
                                </option>

                                <option value="suggestion">
                                    Suggestion
                                </option>

                                <option value="compliment">
                                    Compliment
                                </option>

                                <option value="other">
                                    Other
                                </option>

                            </select>

                        </div>

                        <!-- Category -->
                        <div class="mb-3">

                            <label class="form-label fw-bold">
                                Category
                            </label>

                            <select name="category_id"
                                    class="form-select">

                                <option value="">
                                    Select category (optional)
                                </option>

                                <?php foreach ($categories as $category): ?>

                                    <option value="<?= (int)$category['category_id'] ?>">

                                        <?= e($category['category_name']) ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <!-- Subject -->
                        <div class="mb-3">

                            <label class="form-label fw-bold">
                                Subject <span class="text-danger">*</span>
                            </label>

                            <input type="text"
                                   name="subject"
                                   class="form-control"
                                   maxlength="200"
                                   placeholder="Enter the subject of your feedback"
                                   required>

                        </div>

                        <!-- Message -->
                        <div class="mb-3">

                            <label class="form-label fw-bold">
                                Message <span class="text-danger">*</span>
                            </label>

                            <textarea name="message"
                                      class="form-control"
                                      rows="6"
                                      placeholder="Describe your complaint, suggestion, compliment, or feedback..."
                                      required></textarea>

                            <small class="text-muted">
                                Please provide clear and accurate details.
                            </small>

                        </div>

                        <!-- Priority -->
                        <div class="mb-3">

                            <label class="form-label fw-bold">
                                Priority
                            </label>

                            <select name="priority"
                                    class="form-select">

                                <option value="low">
                                    Low
                                </option>

                                <option value="medium" selected>
                                    Medium
                                </option>

                                <option value="high">
                                    High
                                </option>

                                <option value="urgent">
                                    Urgent
                                </option>

                            </select>

                            <small class="text-muted">
                                Select the priority based on the urgency of your concern.
                            </small>

                        </div>

                        <!-- Anonymous -->
                        <div class="border rounded p-3 mb-4">

                            <div class="form-check">

                                <input class="form-check-input"
                                       type="checkbox"
                                       name="is_anonymous"
                                       id="anonymous">

                                <label class="form-check-label fw-bold"
                                       for="anonymous">

                                    Submit anonymously

                                </label>

                            </div>

                            <small class="text-muted d-block mt-2">

                                Your identity will be hidden from staff-facing
                                feedback displays where anonymous feedback is supported.
                                The system still retains the resident account association
                                for system records.

                            </small>

                        </div>

                        <!-- Submit -->
                        <div class="d-flex flex-wrap gap-2">

                            <button type="submit"
                                    class="btn btn-primary-custom">

                                <i class="bi bi-send me-2"></i>
                                Submit Feedback

                            </button>

                            <a href="dashboard.php"
                               class="btn btn-secondary">

                                Cancel

                            </a>

                        </div>

                    </form>

                </div>

            </div>

        </div>

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
