<?php
session_start();

require_once __DIR__ . '/../config/database.php';
require_once '../includes/notification_helper.php';

// Resident-only access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'resident') {
    header("Location: ../login.php");
    exit;
}

$user_id = (int) $_SESSION['user_id'];

$errors = [];
$success = "";

$purpose = "";
$request_details = "";

// Get service ID from GET or POST
$selected_service = (int) (
    $_POST['service_type_id'] ?? $_GET['service_id'] ?? 0
);

// Get resident ID
$stmt = $pdo->prepare("
    SELECT resident_id
    FROM resident_profiles
    WHERE user_id = ?
");
$stmt->execute([$user_id]);
$resident = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$resident) {
    die("Resident profile not found. Please contact the administrator.");
}

$resident_id = (int) $resident['resident_id'];

// Processing time helper
function displayProcessingTime($days) {
    $days = (int) $days;

    if ($days <= 1) {
        return "1 day";
    }

    return "1-" . $days . " days";
}

// Retrieve selected active service
$service = null;

if ($selected_service > 0) {
    $stmt = $pdo->prepare("
        SELECT service_type_id, service_name, description,
               requirements, processing_days, service_fee
        FROM service_types
        WHERE service_type_id = ? AND is_active = 1
    ");

    $stmt->execute([$selected_service]);
    $service = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $purpose = trim($_POST['purpose'] ?? '');
    $request_details = trim($_POST['request_details'] ?? '');

    if ($selected_service <= 0 || !$service) {
        $errors[] = "The selected barangay service is unavailable.";
    }

    if ($purpose === '') {
        $errors[] = "Please enter the purpose of your request.";
    }

    if (empty($errors)) {

        try {

            $pdo->beginTransaction();

            // Generate reference number
            $reference_number = 'BRG-' . date('Ymd') . '-' .
                strtoupper(bin2hex(random_bytes(4)));

            // Insert service request
            $stmt = $pdo->prepare("
                INSERT INTO service_requests (
                    reference_number,
                    resident_id,
                    service_type_id,
                    purpose,
                    request_details,
                    status
                ) VALUES (
                    :reference_number,
                    :resident_id,
                    :service_type_id,
                    :purpose,
                    :request_details,
                    'submitted'
                )
            ");

            $stmt->execute([
                ':reference_number' => $reference_number,
                ':resident_id' => $resident_id,
                ':service_type_id' => $selected_service,
                ':purpose' => $purpose,
                ':request_details' => $request_details !== ''
                    ? $request_details
                    : null
            ]);

            $request_id = (int) $pdo->lastInsertId();

            // Record initial status history
            $stmt = $pdo->prepare("
                INSERT INTO request_status_history (
                    request_id,
                    previous_status,
                    new_status,
                    remarks,
                    changed_by
                ) VALUES (
                    :request_id,
                    NULL,
                    'submitted',
                    'Service request submitted by resident.',
                    :changed_by
                )
            ");

            $stmt->execute([
                ':request_id' => $request_id,
                ':changed_by' => $user_id
            ]);

            $pdo->commit();

            $success = "Your service request has been submitted successfully! Reference Number: " . $reference_number;

            // Clear form fields
            $purpose = "";
            $request_details = "";

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors[] = "Unable to submit your request. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Submit Service Request | Barangay Putatan</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <link rel="stylesheet" href="../assets/css/style.css">

</head>

<body class="bg-light">

<nav class="navbar navbar-dark barangay-navbar px-3">

    <a class="navbar-brand fw-bold" href="dashboard.php">
        <img src="../assets/images/barangay-logo.png"
             alt="Barangay Logo"
             width="40"
             height="40"
             class="me-2">
        Barangay Putatan
    </a>

    <a href="../logout.php" class="btn btn-yellow btn-sm">
        Logout
    </a>

</nav>

<div class="container py-5">

    <div class="mb-4">
        <a href="services.php" class="text-decoration-none">
            &larr; Back to Barangay Services
        </a>
    </div>

    <div class="card shadow-sm border-0">

        <div class="card-body p-4 p-md-5">

            <h2 class="fw-bold mb-2">
                Submit a Service Request
            </h2>

            <p class="text-muted mb-4">
                Review your selected service and complete the request form below.
            </p>

            <?php if (!empty($errors)): ?>

                <div class="alert alert-danger">

                    <ul class="mb-0">

                        <?php foreach ($errors as $error): ?>

                            <li>
                                <?= htmlspecialchars($error) ?>
                            </li>

                        <?php endforeach; ?>

                    </ul>

                </div>

            <?php endif; ?>

            <?php if ($success): ?>

                <div class="alert alert-success">
                    <?= htmlspecialchars($success) ?>
                </div>

            <?php endif; ?>

            <?php if ($service): ?>

                <form method="POST">

                    <!-- Selected Service -->
                    <div class="mb-4">

                        <label class="form-label fw-semibold">
                            Selected Barangay Service
                        </label>

                        <div class="card bg-light border">

                            <div class="card-body">

                                <h4 class="fw-bold mb-3">
                                    <?= htmlspecialchars($service['service_name']) ?>
                                </h4>

                                <div class="row mb-3">

                                    <div class="col-md-6 mb-2">

                                        <strong>Service Fee:</strong>

                                        <div>
                                            <?php if ((float)$service['service_fee'] <= 0): ?>

                                                <span class="text-success fw-semibold">
                                                    FREE
                                                </span>

                                            <?php else: ?>

                                                ₱<?= number_format((float)$service['service_fee'], 2) ?>

                                            <?php endif; ?>
                                        </div>

                                    </div>

                                    <div class="col-md-6 mb-2">

                                        <strong>Estimated Processing Time:</strong>

                                        <div>
                                            <?= htmlspecialchars(
                                                displayProcessingTime($service['processing_days'])
                                            ) ?>
                                        </div>

                                    </div>

                                </div>

                                <div class="mb-3">

                                    <strong>Description:</strong>

                                    <p class="mb-0">
                                        <?= htmlspecialchars(
                                            $service['description'] ?? 'No description available.'
                                        ) ?>
                                    </p>

                                </div>

                                <div>

                                    <strong>Requirements:</strong>

                                    <p class="mb-0">
                                        <?= htmlspecialchars(
                                            $service['requirements'] ?? 'No requirements listed.'
                                        ) ?>
                                    </p>

                                </div>

                            </div>

                        </div>

                        <!-- Preserve selected service ID -->
                        <input type="hidden"
                               name="service_type_id"
                               value="<?= (int)$service['service_type_id'] ?>">

                    </div>

                    <!-- Purpose -->
                    <div class="mb-4">

                        <label for="purpose" class="form-label fw-semibold">
                            Purpose of Request
                        </label>

                        <input type="text"
                               name="purpose"
                               id="purpose"
                               class="form-control"
                               placeholder="Example: Employment requirement"
                               value="<?= htmlspecialchars($purpose) ?>"
                               required>

                    </div>

                    <!-- Additional Details -->
                    <div class="mb-4">

                        <label for="request_details" class="form-label fw-semibold">
                            Additional Request Details
                        </label>

                        <textarea name="request_details"
                                  id="request_details"
                                  class="form-control"
                                  rows="4"
                                  placeholder="Enter additional information relevant to your request..."><?= htmlspecialchars($request_details) ?></textarea>

                    </div>

                    <!-- Buttons -->
                    <div class="d-flex gap-2 flex-wrap">

                        <button type="submit"
                                class="btn btn-primary-custom px-4">

                            Submit Request

                        </button>

                        <a href="services.php"
                           class="btn btn-secondary">

                            Cancel

                        </a>

                    </div>

                </form>

            <?php else: ?>

                <div class="alert alert-warning">

                    No service selected or the selected service is unavailable.

                </div>

                <a href="services.php"
                   class="btn btn-primary-custom">

                    Browse Barangay Services

                </a>

            <?php endif; ?>

        </div>

    </div>

</div>

</body>
</html>