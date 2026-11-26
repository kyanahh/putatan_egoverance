
<?php
session_start();

require_once __DIR__ . '/../config/database.php';

/* =========================================================
   RESIDENT ACCESS
========================================================= */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'resident') {
    header("Location: ../login.php");
    exit;
}

$user_id = (int) $_SESSION['user_id'];

$errors = [];
$success = "";
$reference_number = "";

$selected_service = (int)($_GET['service_id'] ?? 0);
$purpose = "";
$request_details = "";

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];

/* =========================================================
   GET RESIDENT PROFILE AND EMAIL
========================================================= */
$stmt = $pdo->prepare("
    SELECT
        rp.*,
        u.email
    FROM resident_profiles rp
    JOIN users u ON u.user_id = rp.user_id
    WHERE rp.user_id = ?
");
$stmt->execute([$user_id]);

$resident = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$resident) {
    die("Resident profile not found. Please contact the administrator.");
}

$resident_id = (int) $resident['resident_id'];

/* =========================================================
   RESIDENT PROFILE SNAPSHOT
========================================================= */
$address_parts = [];

foreach ([
    $resident['house_number'] ?? '',
    $resident['street'] ?? '',
    $resident['subdivision'] ?? '',
    $resident['purok'] ?? '',
    $resident['barangay'] ?? '',
    $resident['city'] ?? ''
] as $part) {
    $part = trim((string) $part);

    if ($part !== '') {
        $address_parts[] = $part;
    }
}

$full_address = implode(', ', $address_parts);

$full_name = trim(
    ($resident['first_name'] ?? '') . ' ' .
    ($resident['middle_name'] ?? '') . ' ' .
    ($resident['last_name'] ?? '') . ' ' .
    ($resident['suffix'] ?? '')
);

$applicant_snapshot = [
    'resident_id' => $resident_id,
    'user_id' => $user_id,
    'first_name' => $resident['first_name'] ?? '',
    'middle_name' => $resident['middle_name'] ?? '',
    'last_name' => $resident['last_name'] ?? '',
    'suffix' => $resident['suffix'] ?? '',
    'full_name' => $full_name,
    'birth_date' => $resident['birth_date'] ?? '',
    'sex' => $resident['sex'] ?? '',
    'civil_status' => $resident['civil_status'] ?? '',
    'contact_number' => $resident['contact_number'] ?? '',
    'email' => $resident['email'] ?? '',
    'house_number' => $resident['house_number'] ?? '',
    'street' => $resident['street'] ?? '',
    'subdivision' => $resident['subdivision'] ?? '',
    'purok' => $resident['purok'] ?? '',
    'barangay' => $resident['barangay'] ?? '',
    'city' => $resident['city'] ?? '',
    'full_address' => $full_address,
    'occupation' => $resident['occupation'] ?? '',
    'years_of_residency' => $resident['years_of_residency'] ?? '',
    'residency_since' => $resident['residency_since'] ?? ''
];

/* =========================================================
   GET ACTIVE SERVICES
========================================================= */
$stmt = $pdo->query("
    SELECT
        service_type_id,
        service_name,
        description,
        requirements,
        processing_days,
        service_fee
    FROM service_types
    WHERE is_active = 1
    ORDER BY service_name ASC
");

$services = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================================================
   SERVICE NAME NORMALIZATION
========================================================= */
function normalizeServiceName($name)
{
    return strtolower(trim((string) $name));
}

function getApplicationType($serviceName)
{
    $name = normalizeServiceName($serviceName);

    if (strpos($name, 'first time jobseeker') !== false) {
        return 'First Time Jobseeker';
    }

    if (strpos($name, 'business clearance') !== false) {
        return 'Barangay Business Clearance';
    }

    if (strpos($name, 'indigency') !== false) {
        return 'Certificate of Indigency';
    }

    if (strpos($name, 'residency') !== false) {
        return 'Certificate of Residency';
    }

    if (strpos($name, 'barangay clearance') !== false) {
        return 'Barangay Clearance';
    }

    if (
        strpos($name, 'other') !== false ||
        strpos($name, 'inquiry') !== false
    ) {
        return 'Other Barangay Services';
    }

    return 'General Barangay Service';
}

/* =========================================================
   PROCESS FORM SUBMISSION
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $selected_service = (int) ($_POST['service_type_id'] ?? 0);

    $purpose = trim($_POST['purpose'] ?? '');

    $request_details = trim($_POST['request_details'] ?? '');

    $application_data = [];

    /* CSRF VALIDATION */
    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        $errors[] = "Invalid form submission. Please refresh the page and try again.";
    }

    /* SERVICE VALIDATION */
    if ($selected_service <= 0) {
        $errors[] = "Please select a service.";
    }

    /* PURPOSE VALIDATION */
    if ($purpose === '') {
        $errors[] = "Please enter the purpose of your request.";
    }

    /* VERIFY ACTIVE SERVICE */
    $stmt = $pdo->prepare("
        SELECT
            service_type_id,
            service_name,
            description,
            processing_days,
            service_fee
        FROM service_types
        WHERE service_type_id = ?
        AND is_active = 1
    ");

    $stmt->execute([$selected_service]);

    $service = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$service) {
        $errors[] = "The selected service is unavailable.";
    }

    /* =====================================================
       SERVICE-SPECIFIC APPLICATION DATA
    ===================================================== */
    if ($service && empty($errors)) {

        $application_type = getApplicationType($service['service_name']);

        switch ($application_type) {

            /* =============================================
               BARANGAY CLEARANCE
            ============================================= */
            case 'Barangay Clearance':

                $application_data = [
                    'application_type' => $application_type,
                    'applicant_name' => $full_name,
                    'birth_date' => $resident['birth_date'] ?? '',
                    'sex' => $resident['sex'] ?? '',
                    'civil_status' => $resident['civil_status'] ?? '',
                    'occupation' => $resident['occupation'] ?? '',
                    'contact_number' => $resident['contact_number'] ?? '',
                    'email' => $resident['email'] ?? '',
                    'address' => $full_address,
                    'purpose' => $purpose,
                    'additional_information' => $request_details
                ];

                break;

            /* =============================================
               CERTIFICATE OF RESIDENCY
            ============================================= */
            case 'Certificate of Residency':

                $application_data = [
                    'application_type' => $application_type,
                    'applicant_name' => $full_name,
                    'birth_date' => $resident['birth_date'] ?? '',
                    'civil_status' => $resident['civil_status'] ?? '',
                    'contact_number' => $resident['contact_number'] ?? '',
                    'email' => $resident['email'] ?? '',
                    'address' => $full_address,
                    'residency_since' => $resident['residency_since'] ?? '',
                    'years_of_residency' => $resident['years_of_residency'] ?? '',
                    'purpose' => $purpose,
                    'additional_information' => $request_details
                ];

                break;

            /* =============================================
               CERTIFICATE OF INDIGENCY
            ============================================= */
            case 'Certificate of Indigency':

                $application_data = [
                    'application_type' => $application_type,
                    'applicant_name' => $full_name,
                    'birth_date' => $resident['birth_date'] ?? '',
                    'civil_status' => $resident['civil_status'] ?? '',
                    'occupation' => $resident['occupation'] ?? '',
                    'contact_number' => $resident['contact_number'] ?? '',
                    'address' => $full_address,
                    'purpose' => $purpose,
                    'additional_information' => $request_details
                ];

                break;

            /* =============================================
               FIRST TIME JOBSEEKER
            ============================================= */
            case 'First Time Jobseeker':

                $application_data = [
                    'application_type' => $application_type,
                    'applicant_name' => $full_name,
                    'birth_date' => $resident['birth_date'] ?? '',
                    'sex' => $resident['sex'] ?? '',
                    'civil_status' => $resident['civil_status'] ?? '',
                    'contact_number' => $resident['contact_number'] ?? '',
                    'email' => $resident['email'] ?? '',
                    'address' => $full_address,
                    'purpose' => $purpose,
                    'employment_status' => trim($_POST['employment_status'] ?? ''),
                    'educational_attainment' => trim($_POST['educational_attainment'] ?? ''),
                    'additional_information' => $request_details
                ];

                if ($application_data['employment_status'] === '') {
                    $errors[] = "Please select your employment status.";
                }

                if ($application_data['educational_attainment'] === '') {
                    $errors[] = "Please select your educational attainment.";
                }

                break;

            /* =============================================
               BARANGAY BUSINESS CLEARANCE
            ============================================= */
            case 'Barangay Business Clearance':

                $application_data = [
                    'application_type' => $application_type,
                    'applicant_name' => $full_name,
                    'contact_number' => $resident['contact_number'] ?? '',
                    'email' => $resident['email'] ?? '',
                    'residential_address' => $full_address,
                    'business_name' => trim($_POST['business_name'] ?? ''),
                    'business_address' => trim($_POST['business_address'] ?? ''),
                    'business_type' => trim($_POST['business_type'] ?? ''),
                    'business_nature' => trim($_POST['business_nature'] ?? ''),
                    'business_ownership' => trim($_POST['business_ownership'] ?? ''),
                    'purpose' => $purpose,
                    'additional_information' => $request_details
                ];

                if ($application_data['business_name'] === '') {
                    $errors[] = "Please enter the business name.";
                }

                if ($application_data['business_address'] === '') {
                    $errors[] = "Please enter the business address.";
                }

                if ($application_data['business_type'] === '') {
                    $errors[] = "Please enter the business type.";
                }

                if ($application_data['business_nature'] === '') {
                    $errors[] = "Please describe the nature of the business.";
                }

                break;

            /* =============================================
               OTHER BARANGAY SERVICES
            ============================================= */
            case 'Other Barangay Services':

                $application_data = [
                    'application_type' => $application_type,
                    'applicant_name' => $full_name,
                    'contact_number' => $resident['contact_number'] ?? '',
                    'email' => $resident['email'] ?? '',
                    'address' => $full_address,
                    'inquiry' => trim($_POST['inquiry'] ?? ''),
                    'preferred_contact' => trim($_POST['preferred_contact'] ?? ''),
                    'purpose' => $purpose,
                    'additional_information' => $request_details,
                    'initial_fee' => 0
                ];

                if ($application_data['inquiry'] === '') {
                    $errors[] = "Please describe the service or assistance you need.";
                }

                if ($application_data['preferred_contact'] === '') {
                    $errors[] = "Please provide your preferred contact method.";
                }

                break;

            /* =============================================
               GENERAL SERVICE
            ============================================= */
            default:

                $application_data = [
                    'application_type' => $application_type,
                    'applicant_name' => $full_name,
                    'contact_number' => $resident['contact_number'] ?? '',
                    'email' => $resident['email'] ?? '',
                    'address' => $full_address,
                    'purpose' => $purpose,
                    'additional_information' => $request_details
                ];

                break;
        }
    }

    /* =====================================================
       SAVE REQUEST AND APPLICATION
    ===================================================== */
    if (empty($errors) && $service) {

        try {

            $pdo->beginTransaction();

            /* GENERATE REFERENCE NUMBER */
            $reference_number = 'BRG-' .
                date('Ymd') . '-' .
                strtoupper(bin2hex(random_bytes(4)));

            /* INSERT MAIN SERVICE REQUEST */
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

            /* INSERT APPLICATION SNAPSHOT */
            $stmt = $pdo->prepare("
                INSERT INTO service_request_applications (
                    request_id,
                    application_type,
                    applicant_snapshot,
                    application_data
                ) VALUES (
                    :request_id,
                    :application_type,
                    :applicant_snapshot,
                    :application_data
                )
            ");

            $stmt->execute([
                ':request_id' => $request_id,
                ':application_type' => $application_type,
                ':applicant_snapshot' => json_encode(
                    $applicant_snapshot,
                    JSON_UNESCAPED_UNICODE
                ),
                ':application_data' => json_encode(
                    $application_data,
                    JSON_UNESCAPED_UNICODE
                )
            ]);

            /* INSERT STATUS HISTORY */
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

            $success = "Your service request has been submitted successfully!";

            $purpose = "";
            $request_details = "";
            $selected_service = 0;

            /* RESET CSRF TOKEN */
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $csrf_token = $_SESSION['csrf_token'];

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors[] = "Unable to submit your request. Please try again.";
        }
    }
}

/* =========================================================
   PROCESSING TIME DISPLAY
========================================================= */
function displayProcessingTime($days)
{
    $days = (int) $days;

    if ($days <= 1) {
        return "1 day";
    }

    return "1-" . $days . " days";
}

function e($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}
?>

<!DOCTYPE html>
<html lang="en">
<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Request Barangay Service</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="../assets/css/style.css"
    >

    <style>
        body {
            background-color: #f5f7fb;
        }

        .request-container {
            max-width: 1000px;
            margin: auto;
        }

        .request-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }

        .section-title {
            font-weight: 600;
            color: #1f2937;
        }

        .service-information {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
        }

        .application-section {
            display: none;
        }

        .form-label {
            font-weight: 500;
        }

        .readonly-field {
            background-color: #f1f3f5 !important;
        }

        .required-label::after {
            content: " *";
            color: #dc3545;
        }

        .profile-notice {
            background-color: #eaf3ff;
            border: 1px solid #cfe2ff;
            border-radius: 10px;
            padding: 15px;
        }
    </style>

</head>

<body>

<!-- =====================================================
     NAVBAR
===================================================== -->
<nav class="navbar navbar-dark barangay-navbar">

    <div class="container-fluid">

        <a class="navbar-brand fw-bold" href="dashboard.php">

            <img
                src="../assets/images/barangay-logo.png"
                width="40"
                height="40"
                class="me-2"
                alt="Barangay Logo"
            >

            Barangay Putatan

        </a>

        <div>

            <a
                href="dashboard.php"
                class="btn btn-light btn-sm me-2"
            >
                Dashboard
            </a>

            <a
                href="../logout.php"
                class="btn btn-yellow btn-sm"
            >
                Logout
            </a>

        </div>

    </div>

</nav>

<!-- =====================================================
     MAIN CONTENT
===================================================== -->
<div class="container py-5">

    <div class="mb-4">

        <h2 class="fw-bold">
            Request Barangay Service
        </h2>

        <p class="text-muted">
            Complete your application form to submit a barangay service request.
            Your saved resident information will be automatically used where applicable.
        </p>

    </div>

    <!-- PROFILE NOTICE -->
    <div class="profile-notice mb-4">

        <strong>Resident Information</strong>

        <p class="mb-0 mt-2">
            Some application information is automatically retrieved from your resident profile.
            Please make sure your profile information is updated before submitting your request.
        </p>

        <a
            href="profile.php"
            class="btn btn-sm btn-outline-primary mt-2"
        >
            Update My Profile
        </a>

    </div>

    <!-- =================================================
         ALERTS
    ================================================== -->
    <?php if (!empty($errors)): ?>

        <div class="alert alert-danger">

            <strong>Please correct the following:</strong>

            <ul class="mb-0 mt-2">

                <?php foreach ($errors as $error): ?>

                    <li><?= e($error) ?></li>

                <?php endforeach; ?>

            </ul>

        </div>

    <?php endif; ?>

    <?php if ($success): ?>

        <div class="alert alert-success">

            <h5 class="mb-2">
                Request Submitted Successfully!
            </h5>

            <p class="mb-2">
                <?= e($success) ?>
            </p>

            <strong>
                Reference Number:
                <?= e($reference_number) ?>
            </strong>

            <p class="mt-2 mb-0">
                Please save your reference number for tracking your application.
            </p>

        </div>

    <?php endif; ?>

    <!-- =================================================
         REQUEST FORM
    ================================================== -->
    <div class="card request-card">

        <div class="card-body p-4">

            <form
                method="POST"
                action=""
                id="requestForm"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($csrf_token) ?>"
                >

                <!-- SERVICE SELECTION -->
                <div class="mb-4">

                    <label
                        for="service_type_id"
                        class="form-label required-label"
                    >
                        Select Barangay Service
                    </label>

                    <select
                        name="service_type_id"
                        id="service_type_id"
                        class="form-select"
                        required
                    >

                        <option value="">
                            -- Select Service --
                        </option>

                        <?php foreach ($services as $item): ?>

                            <option
                                value="<?= (int) $item['service_type_id'] ?>"
                                data-name="<?= e($item['service_name']) ?>"
                                data-description="<?= e($item['description'] ?? '') ?>"
                                data-requirements="<?= e($item['requirements'] ?? '') ?>"
                                data-days="<?= (int) ($item['processing_days'] ?? 0) ?>"
                                data-fee="<?= e($item['service_fee'] ?? 0) ?>"
                                <?= (
                                    $selected_service ==
                                    $item['service_type_id']
                                ) ? 'selected' : '' ?>
                            >

                                <?= e($item['service_name']) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <!-- SERVICE INFORMATION -->
                <div
                    id="serviceInformation"
                    class="service-information mb-4"
                    style="display:none;"
                >

                    <h5 class="section-title">
                        Service Information
                    </h5>

                    <hr>

                    <div class="row">

                        <div class="col-md-6 mb-3">

                            <strong>Service Fee</strong>

                            <p id="serviceFee" class="mb-0">
                                ₱0.00
                            </p>

                        </div>

                        <div class="col-md-6 mb-3">

                            <strong>Processing Time</strong>

                            <p id="processingDays" class="mb-0">
                                -
                            </p>

                        </div>

                    </div>

                    <div class="mb-3">

                        <strong>Description</strong>

                        <p id="serviceDescription" class="mb-0">
                            -
                        </p>

                    </div>

                    <div>

                        <strong>Requirements</strong>

                        <p
                            id="serviceRequirements"
                            class="mb-0"
                        >
                            -
                        </p>

                    </div>

                </div>

                <!-- =================================================
                     PERSONAL INFORMATION
                ================================================== -->
                <div
                    id="personalInformation"
                    class="application-section mb-4"
                >

                    <h5 class="section-title">
                        Applicant Information
                    </h5>

                    <hr>

                    <div class="row">

                        <div class="col-md-6 mb-3">

                            <label class="form-label">
                                Full Name
                            </label>

                            <input
                                type="text"
                                class="form-control readonly-field"
                                value="<?= e($full_name) ?>"
                                readonly
                            >

                        </div>

                        <div class="col-md-6 mb-3">

                            <label class="form-label">
                                Birth Date
                            </label>

                            <input
                                type="text"
                                class="form-control readonly-field"
                                value="<?= e($resident['birth_date'] ?? '') ?>"
                                readonly
                            >

                        </div>

                        <div class="col-md-6 mb-3">

                            <label class="form-label">
                                Sex
                            </label>

                            <input
                                type="text"
                                class="form-control readonly-field"
                                value="<?= e($resident['sex'] ?? '') ?>"
                                readonly
                            >

                        </div>

                        <div class="col-md-6 mb-3">

                            <label class="form-label">
                                Civil Status
                            </label>

                            <input
                                type="text"
                                class="form-control readonly-field"
                                value="<?= e($resident['civil_status'] ?? '') ?>"
                                readonly
                            >

                        </div>

                        <div class="col-md-6 mb-3">

                            <label class="form-label">
                                Contact Number
                            </label>

                            <input
                                type="text"
                                class="form-control readonly-field"
                                value="<?= e($resident['contact_number'] ?? '') ?>"
                                readonly
                            >

                        </div>

                        <div class="col-md-6 mb-3">

                            <label class="form-label">
                                Email
                            </label>

                            <input
                                type="text"
                                class="form-control readonly-field"
                                value="<?= e($resident['email'] ?? '') ?>"
                                readonly
                            >

                        </div>

                        <div class="col-12 mb-3">

                            <label class="form-label">
                                Complete Address
                            </label>

                            <textarea
                                class="form-control readonly-field"
                                rows="2"
                                readonly
                            ><?= e($full_address) ?></textarea>

                        </div>

                    </div>

                </div>

                <!-- =================================================
                     FIRST TIME JOBSEEKER
                ================================================== -->
                <div
                    id="jobseekerForm"
                    class="application-section mb-4"
                >

                    <h5 class="section-title">
                        First Time Jobseeker Application
                    </h5>

                    <hr>

                    <div class="mb-3">

                        <label class="form-label required-label">
                            Employment Status
                        </label>

                        <select
                            name="employment_status"
                            class="form-select"
                            data-required="true"
                        >

                            <option value="">
                                Select employment status
                            </option>

                            <option value="Unemployed">
                                Unemployed
                            </option>

                            <option value="Currently Seeking Employment">
                                Currently Seeking Employment
                            </option>

                        </select>

                    </div>

                    <div class="mb-3">

                        <label class="form-label required-label">
                            Educational Attainment
                        </label>

                        <select
                            name="educational_attainment"
                            class="form-select"
                            data-required="true"
                        >

                            <option value="">
                                Select educational attainment
                            </option>

                            <option value="Elementary">
                                Elementary
                            </option>

                            <option value="Junior High School">
                                Junior High School
                            </option>

                            <option value="Senior High School">
                                Senior High School
                            </option>

                            <option value="Vocational">
                                Vocational
                            </option>

                            <option value="College">
                                College
                            </option>

                            <option value="College Graduate">
                                College Graduate
                            </option>

                            <option value="Postgraduate">
                                Postgraduate
                            </option>

                        </select>

                    </div>

                    <div class="alert alert-info">

                        <strong>First Time Jobseeker Reminder</strong>

                        <p class="mb-0 mt-2">
                            Your application may require an oath of undertaking
                            and other supporting documents during processing.
                            Barangay personnel will verify the requirements.
                        </p>

                    </div>

                </div>

                <!-- =================================================
                     BUSINESS CLEARANCE
                ================================================== -->
                <div
                    id="businessForm"
                    class="application-section mb-4"
                >

                    <h5 class="section-title">
                        Business Application Information
                    </h5>

                    <hr>

                    <div class="mb-3">

                        <label class="form-label required-label">
                            Business Name
                        </label>

                        <input
                            type="text"
                            name="business_name"
                            class="form-control"
                            data-required="true"
                        >

                    </div>

                    <div class="mb-3">

                        <label class="form-label required-label">
                            Business Address
                        </label>

                        <textarea
                            name="business_address"
                            class="form-control"
                            rows="2"
                            data-required="true"
                        ></textarea>

                    </div>

                    <div class="mb-3">

                        <label class="form-label required-label">
                            Business Type
                        </label>

                        <input
                            type="text"
                            name="business_type"
                            class="form-control"
                            placeholder="e.g. Sole Proprietorship, Partnership, Corporation"
                            data-required="true"
                        >

                    </div>

                    <div class="mb-3">

                        <label class="form-label required-label">
                            Nature of Business
                        </label>

                        <textarea
                            name="business_nature"
                            class="form-control"
                            rows="3"
                            data-required="true"
                        ></textarea>

                    </div>

                    <div class="mb-3">

                        <label class="form-label">
                            Business Ownership
                        </label>

                        <input
                            type="text"
                            name="business_ownership"
                            class="form-control"
                            placeholder="Optional"
                        >

                    </div>

                </div>

                <!-- =================================================
                     OTHER BARANGAY SERVICES
                ================================================== -->
                <div
                    id="otherServicesForm"
                    class="application-section mb-4"
                >

                    <h5 class="section-title">
                        Other Barangay Services Inquiry
                    </h5>

                    <hr>

                    <div class="alert alert-info">

                        <strong>Inquiry-Based Service</strong>

                        <p class="mb-0 mt-2">
                            This option is for services or assistance not listed
                            among the available barangay applications.
                            Barangay personnel will review your inquiry and
                            contact you regarding the appropriate process.
                        </p>

                        <p class="mb-0 mt-2">
                            <strong>Initial Fee: ₱0.00</strong>
                        </p>

                        <small>
                            The displayed amount is an initial inquiry-stage
                            value and does not establish the final fee for
                            any service that may be assessed.
                        </small>

                    </div>

                    <div class="mb-3">

                        <label class="form-label required-label">
                            Describe the Service or Assistance Needed
                        </label>

                        <textarea
                            name="inquiry"
                            class="form-control"
                            rows="4"
                            data-required="true"
                            placeholder="Describe the service, concern, or assistance you are requesting."
                        ></textarea>

                    </div>

                    <div class="mb-3">

                        <label class="form-label required-label">
                            Preferred Contact Method
                        </label>

                        <select
                            name="preferred_contact"
                            class="form-select"
                            data-required="true"
                        >

                            <option value="">
                                Select preferred contact method
                            </option>

                            <option value="Phone">
                                Phone
                            </option>

                            <option value="Email">
                                Email
                            </option>

                            <option value="Phone and Email">
                                Phone and Email
                            </option>

                        </select>

                    </div>

                </div>

                <!-- =================================================
                     PURPOSE
                ================================================== -->
                <div class="mb-4">

                    <label
                        for="purpose"
                        class="form-label required-label"
                    >
                        Purpose of Request
                    </label>

                    <input
                        type="text"
                        name="purpose"
                        id="purpose"
                        class="form-control"
                        value="<?= e($purpose) ?>"
                        required
                        placeholder="Enter the purpose of your application"
                    >

                </div>

                <!-- =================================================
                     ADDITIONAL INFORMATION
                ================================================== -->
                <div class="mb-4">

                    <label
                        for="request_details"
                        class="form-label"
                    >
                        Additional Information
                    </label>

                    <textarea
                        name="request_details"
                        id="request_details"
                        class="form-control"
                        rows="4"
                        placeholder="Enter additional information, if applicable"
                    ><?= e($request_details) ?></textarea>

                </div>

                <!-- =================================================
                     SUBMIT
                ================================================== -->
                <div class="d-flex flex-wrap gap-2">

                    <button
                        type="submit"
                        class="btn btn-primary"
                    >
                        Submit Application
                    </button>

                    <a
                        href="dashboard.php"
                        class="btn btn-outline-secondary"
                    >
                        Cancel
                    </a>

                </div>

            </form>

        </div>

    </div>

</div>

<!-- =====================================================
     JAVASCRIPT
===================================================== -->
<script>

const serviceSelect = document.getElementById('service_type_id');

const serviceInformation = document.getElementById('serviceInformation');

const serviceFee = document.getElementById('serviceFee');

const processingDays = document.getElementById('processingDays');

const serviceDescription = document.getElementById('serviceDescription');

const serviceRequirements = document.getElementById('serviceRequirements');

const personalInformation = document.getElementById('personalInformation');

const jobseekerForm = document.getElementById('jobseekerForm');

const businessForm = document.getElementById('businessForm');

const otherServicesForm = document.getElementById('otherServicesForm');

const requestForm = document.getElementById('requestForm');

const purposeInput = document.getElementById('purpose');

function normalizeName(name) {
    return (name || '').trim().toLowerCase();
}

function getServiceType(name) {

    name = normalizeName(name);

    if (name.includes('first time jobseeker')) {
        return 'jobseeker';
    }

    if (name.includes('business clearance')) {
        return 'business';
    }

    if (name.includes('indigency')) {
        return 'indigency';
    }

    if (name.includes('residency')) {
        return 'residency';
    }

    if (name.includes('barangay clearance')) {
        return 'clearance';
    }

    if (name.includes('other') || name.includes('inquiry')) {
        return 'other';
    }

    return 'general';
}

function hideApplicationForms() {

    document.querySelectorAll('.application-section').forEach(section => {
        section.style.display = 'none';
    });

    document.querySelectorAll('[data-required="true"]').forEach(field => {
        field.required = false;
    });

    personalInformation.style.display = 'none';
}

function showRequiredFields(container) {

    container.querySelectorAll('[data-required="true"]').forEach(field => {
        field.required = true;
    });

}

function updateServiceInformation() {

    hideApplicationForms();

    const selectedOption = serviceSelect.options[serviceSelect.selectedIndex];

    if (!selectedOption || !selectedOption.value) {
        serviceInformation.style.display = 'none';
        return;
    }

    serviceInformation.style.display = 'block';

    const name = selectedOption.dataset.name || '';
    const description = selectedOption.dataset.description || '';
    const requirements = selectedOption.dataset.requirements || '';
    const days = parseInt(selectedOption.dataset.days || '0', 10);
    const fee = parseFloat(selectedOption.dataset.fee || '0');

    const type = getServiceType(name);

    /* SERVICE FEE */
    if (type === 'other') {
        serviceFee.textContent = '₱0.00 (Initial inquiry fee)';
    } else {
        serviceFee.textContent = '₱' + fee.toFixed(2);
    }

    /* PROCESSING TIME */
    if (days <= 1) {
        processingDays.textContent = '1 day';
    } else {
        processingDays.textContent = '1-' + days + ' days';
    }

    /* DESCRIPTION */
    serviceDescription.textContent = description || 'No description available.';

    /* REQUIREMENTS */
    serviceRequirements.textContent = requirements || 'No requirements listed.';

    /* DISPLAY APPLICANT INFORMATION */
    if (
        type === 'clearance' ||
        type === 'residency' ||
        type === 'indigency' ||
        type === 'jobseeker' ||
        type === 'business' ||
        type === 'other' ||
        type === 'general'
    ) {
        personalInformation.style.display = 'block';
    }

    /* FIRST TIME JOBSEEKER */
    if (type === 'jobseeker') {
        jobseekerForm.style.display = 'block';
        showRequiredFields(jobseekerForm);
    }

    /* BUSINESS CLEARANCE */
    if (type === 'business') {
        businessForm.style.display = 'block';
        showRequiredFields(businessForm);
    }

    /* OTHER SERVICES */
    if (type === 'other') {
        otherServicesForm.style.display = 'block';
        showRequiredFields(otherServicesForm);
    }
}

serviceSelect.addEventListener('change', updateServiceInformation);

requestForm.addEventListener('submit', function(event) {

    const selectedOption = serviceSelect.options[serviceSelect.selectedIndex];

    if (!selectedOption || !selectedOption.value) {

        event.preventDefault();

        alert('Please select a barangay service.');

        return;

    }

    const type = getServiceType(selectedOption.dataset.name || '');

    if (
        type === 'jobseeker' ||
        type === 'business' ||
        type === 'other'
    ) {

        const visibleRequiredFields = document.querySelectorAll(
            '[data-required="true"]'
        );

        for (const field of visibleRequiredFields) {

            if (!field.required) {
                continue;
            }

            if (!field.value.trim()) {

                event.preventDefault();

                field.focus();

                alert('Please complete all required application fields.');

                return;

            }

        }

    }

});

updateServiceInformation();

</script>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

</body>
</html>