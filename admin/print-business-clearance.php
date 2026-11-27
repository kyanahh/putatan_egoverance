<?php
require_once '../includes/admin_auth.php';

/* =========================================================
   GET REQUEST ID
========================================================= */

$request_id = (int)($_GET['request_id'] ?? 0);

if ($request_id <= 0) {
    die('Invalid service request.');
}

/* =========================================================
   FETCH REQUEST AND APPLICATION DATA
========================================================= */

$stmt = $pdo->prepare("
    SELECT
        sr.request_id,
        sr.reference_number,
        sr.purpose,
        sr.request_details,
        sr.status,
        sr.requested_at,
        sr.completed_at,

        st.service_name,

        rp.first_name,
        rp.middle_name,
        rp.last_name,
        rp.suffix,
        rp.birth_date,
        rp.house_number,
        rp.street,
        rp.purok,
        rp.subdivision,
        rp.barangay,
        rp.city,

        sra.application_type,
        sra.applicant_snapshot,
        sra.application_data

    FROM service_requests sr

    INNER JOIN service_types st
        ON sr.service_type_id = st.service_type_id

    INNER JOIN resident_profiles rp
        ON sr.resident_id = rp.resident_id

    LEFT JOIN service_request_applications sra
        ON sr.request_id = sra.request_id

    WHERE sr.request_id = ?

    LIMIT 1
");

$stmt->execute([$request_id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    die('Service request not found.');
}

/* =========================================================
   STATUS VALIDATION
========================================================= */

$printableStatuses = [
    'approved',
    'ready_for_release',
    'completed'
];

if (!in_array($request['status'], $printableStatuses, true)) {
    die('This request is not yet approved for printing.');
}

/* =========================================================
   JSON HELPERS
========================================================= */

function decodeJSONData($json)
{
    if (empty($json)) {
        return [];
    }

    $data = json_decode($json, true);

    return is_array($data) ? $data : [];
}

function flattenApplicationData($data, &$result = [])
{
    if (!is_array($data)) {
        return $result;
    }

    foreach ($data as $key => $value) {

        if (is_array($value)) {
            flattenApplicationData($value, $result);
        } else {
            $result[$key] = $value;
        }
    }

    return $result;
}

function getApplicationValue($data, $keys, $default = '')
{
    foreach ($keys as $key) {

        if (
            isset($data[$key]) &&
            $data[$key] !== '' &&
            $data[$key] !== null
        ) {
            return $data[$key];
        }
    }

    return $default;
}

function e($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

/* =========================================================
   DECODE APPLICATION DATA
========================================================= */

$applicantData = decodeJSONData(
    $request['applicant_snapshot'] ?? ''
);

$applicationData = decodeJSONData(
    $request['application_data'] ?? ''
);

$applicantFlat = flattenApplicationData($applicantData);
$applicationFlat = flattenApplicationData($applicationData);

/* =========================================================
   RESIDENT / APPLICANT INFORMATION
========================================================= */

$firstName = getApplicationValue(
    $applicantFlat,
    ['first_name', 'firstname', 'given_name'],
    $request['first_name'] ?? ''
);

$middleName = getApplicationValue(
    $applicantFlat,
    ['middle_name', 'middlename'],
    $request['middle_name'] ?? ''
);

$lastName = getApplicationValue(
    $applicantFlat,
    ['last_name', 'lastname', 'surname'],
    $request['last_name'] ?? ''
);

$suffix = getApplicationValue(
    $applicantFlat,
    ['suffix', 'name_suffix'],
    $request['suffix'] ?? ''
);

$fullName = trim(
    $firstName . ' ' .
    ($middleName !== '' ? $middleName . ' ' : '') .
    $lastName .
    ($suffix !== '' ? ' ' . $suffix : '')
);

/* =========================================================
   ADDRESS
========================================================= */

$profileAddressParts = [
    $request['house_number'] ?? '',
    $request['street'] ?? '',
    $request['purok'] ?? '',
    $request['subdivision'] ?? '',
    $request['barangay'] ?? '',
    $request['city'] ?? ''
];

$profileAddressParts = array_filter(
    $profileAddressParts,
    function ($value) {
        return trim((string)$value) !== '';
    }
);

$profileAddress = implode(', ', $profileAddressParts);

$address = getApplicationValue(
    $applicantFlat,
    [
        'complete_address',
        'business_address',
        'residential_address',
        'address',
        'home_address'
    ],
    $profileAddress
);

/* =========================================================
   BUSINESS INFORMATION
========================================================= */

$businessName = getApplicationValue(
    $applicationFlat,
    [
        'business_name',
        'name_of_business',
        'registered_business_name',
        'businessname'
    ]
);

$lineOfBusiness = getApplicationValue(
    $applicationFlat,
    [
        'line_of_business',
        'business_nature',
        'nature_of_business',
        'business_type',
        'business_activity'
    ]
);

$businessAddress = getApplicationValue(
    $applicationFlat,
    [
        'business_address',
        'business_location',
        'complete_business_address',
        'business_site_address'
    ],
    $address
);

$taxpayerName = getApplicationValue(
    $applicationFlat,
    [
        'taxpayer_name',
        'tax_payer_name',
        'owner_name',
        'business_owner',
        'proprietor_name'
    ],
    $fullName
);

/* =========================================================
   PERMIT INFORMATION
========================================================= */

$permitNumber = getApplicationValue(
    $applicationFlat,
    [
        'barangay_permit_number',
        'permit_number',
        'business_permit_number',
        'bp_number'
    ],
    $request['reference_number'] ?? ''
);

$bpAccountNumber = getApplicationValue(
    $applicationFlat,
    [
        'bp_account_number',
        'account_number',
        'business_account_number'
    ]
);

$orNumber = getApplicationValue(
    $applicationFlat,
    [
        'or_number',
        'receipt_number',
        'official_receipt_number'
    ]
);

$amountPaid = getApplicationValue(
    $applicationFlat,
    [
        'amount_paid',
        'payment_amount',
        'fee',
        'total_amount'
    ]
);

$datePaid = getApplicationValue(
    $applicationFlat,
    [
        'date_paid',
        'payment_date',
        'paid_at'
    ]
);

/* =========================================================
   ISSUANCE / EXPIRATION / RENEWAL
========================================================= */

$dateIssued = getApplicationValue(
    $applicationFlat,
    [
        'date_issued',
        'issuance_date',
        'issued_date'
    ],
    date('Y-m-d')
);

$dateExpiration = getApplicationValue(
    $applicationFlat,
    [
        'date_expiration',
        'expiration_date',
        'expiry_date',
        'valid_until'
    ]
);

$dateRenewal = getApplicationValue(
    $applicationFlat,
    [
        'date_renewal',
        'renewal_date',
        'renew_date'
    ]
);

/* =========================================================
   ISSUED BY
========================================================= */

$issuedBy = getApplicationValue(
    $applicationFlat,
    [
        'issued_by',
        'processed_by_name',
        'admin_name'
    ],
    'Barangay Putatan'
);

/* =========================================================
   DATE FORMATTER
========================================================= */

function formatBusinessDate($date)
{
    if (
        empty($date) ||
        strtotime((string)$date) === false
    ) {
        return '';
    }

    return date('F d, Y', strtotime($date));
}

$dateIssuedFormatted = formatBusinessDate($dateIssued);
$dateExpirationFormatted = formatBusinessDate($dateExpiration);
$dateRenewalFormatted = formatBusinessDate($dateRenewal);
$datePaidFormatted = formatBusinessDate($datePaid);

/* =========================================================
   BUSINESS CLEARANCE TITLE
========================================================= */

$certificateTitle = 'BARANGAY CLEARANCE';

?>


<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Barangay Business Clearance</title>

<style>
    @page {
        size: A4 portrait;
        margin: 8mm;
    }

    * {
        box-sizing: border-box;
    }

    body {
        margin: 0;
        font-family: Arial, Helvetica, sans-serif;
        background: #e5e7eb;
        color: #333;
    }

    .print-controls {
        text-align: center;
        padding: 20px;
    }

    .print-button {
        background: #172f80;
        color: white;
        border: none;
        padding: 12px 25px;
        border-radius: 5px;
        cursor: pointer;
        font-weight: bold;
    }

    .back-button {
        display: inline-block;
        margin-left: 10px;
        padding: 12px 25px;
        background: #6c757d;
        color: white;
        text-decoration: none;
        border-radius: 5px;
    }

    .business-clearance {
        width: 210mm;
        min-height: 297mm;
        margin: 20px auto;
        background: white;
        position: relative;
        overflow: hidden;
        border: 5px solid #e4c46a;
        padding: 12mm 12mm 0;
    }

    /* TOP COLOR BORDER */

    .top-stripe {
        height: 12px;
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        background: linear-gradient(
            90deg,
            #1475b8 0%,
            #1475b8 35%,
            #d64d58 35%,
            #d64d58 60%,
            #e8c85a 60%,
            #e8c85a 100%
        );
    }

    /* WATERMARK */

    .watermark {
        position: absolute;
        width: 430px;
        height: 430px;
        top: 48%;
        left: 50%;
        transform: translate(-50%, -50%);
        background-image: url('../assets/images/barangay-logo.png');
        background-size: contain;
        background-position: center;
        background-repeat: no-repeat;
        opacity: 0.09;
        pointer-events: none;
        z-index: 0;
    }

    .content {
        position: relative;
        z-index: 1;
    }

    /* HEADER */

    .header {
        text-align: center;
        margin-top: 8px;
    }

    .header p {
        margin: 3px 0;
        font-weight: bold;
        font-size: 15px;
        letter-spacing: 1px;
    }

    .header h1 {
        margin: 8px 0 5px;
        font-size: 32px;
        color: #176fb2;
        font-weight: 900;
        letter-spacing: 1px;
    }

    .business-subtitle {
        font-size: 22px;
        letter-spacing: 4px;
        color: #176fb2;
        font-weight: bold;
    }

    .permit-number {
        position: absolute;
        top: 5px;
        right: 0;
        font-size: 13px;
        font-weight: bold;
        text-align: right;
    }

    .permit-number span {
        color: #bd5362;
        font-size: 18px;
    }

    /* BUSINESS DETAILS */

    .business-field {
        margin-top: 30px;
        text-align: center;
    }

    .field-label {
        font-size: 18px;
        font-weight: bold;
        color: #b65a65;
        margin-bottom: 8px;
        letter-spacing: 1px;
    }

    .field-value {
        background: rgba(220, 225, 228, 0.55);
        padding: 12px 10px;
        min-height: 40px;
        font-size: 16px;
        font-weight: bold;
        text-transform: uppercase;
        word-wrap: break-word;
    }

    /* DATES */

    .date-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 10px;
        margin-top: 35px;
        text-align: center;
    }

    .date-value {
        font-weight: bold;
        font-size: 14px;
        min-height: 25px;
    }

    .date-label {
        color: #b65a65;
        font-weight: bold;
        font-size: 13px;
        margin-top: 10px;
    }

    /* STATEMENT */

    .revocation-text {
        margin: 45px 15px 25px;
        text-align: center;
        font-size: 15px;
        line-height: 1.6;
        font-weight: bold;
    }

    /* PAYMENT INFORMATION */

    .payment-section {
        margin-top: 25px;
        width: 65%;
        font-size: 13px;
    }

    .payment-table {
        width: 100%;
        border-collapse: collapse;
    }

    .payment-table td {
        padding: 4px 2px;
        vertical-align: top;
    }

    .payment-table td:first-child {
        width: 45%;
    }

    .payment-table td:nth-child(2) {
        width: 5%;
    }

    .payment-table td:last-child {
        font-weight: bold;
        word-break: break-word;
    }

    /* CHAIRMAN */

    .chairman-section {
        margin-top: 45px;
        text-align: center;
    }

    .chairman-signature-space {
        height: 45px;
    }

    .chairman-name {
        font-size: 23px;
        font-weight: bold;
        color: #176fb2;
        border-bottom: 2px solid #176fb2;
        padding-bottom: 5px;
        display: inline-block;
        min-width: 350px;
    }

    .chairman-title {
        color: #b65a65;
        font-size: 18px;
        font-weight: bold;
        margin-top: 8px;
    }

    /* FOOTER BANNER */

    .footer-banner {
        position: absolute;
        bottom: 18mm;
        left: 0;
        right: 0;
        min-height: 42mm;
        background: #173d72;
        color: white;
        display: flex;
        align-items: center;
        justify-content: space-around;
        padding: 10px;
        border-top: 8px solid #e5bd52;
        border-bottom: 8px solid #e5bd52;
        z-index: 1;
    }

    .footer-brand {
        text-align: center;
        width: 25%;
        font-weight: bold;
    }

    .footer-brand .brand-big {
        font-size: 20px;
        letter-spacing: 2px;
    }

    .footer-brand .brand-small {
        font-size: 10px;
    }

    .footer-logo {
        width: 130px;
        height: 130px;
        object-fit: contain;
        background: white;
        border-radius: 50%;
        border: 8px solid #d5ae42;
        padding: 5px;
    }

    .footer-note {
        position: absolute;
        bottom: 6mm;
        left: 0;
        right: 0;
        text-align: center;
        font-size: 13px;
        font-weight: bold;
        color: #85651a;
        background: #f3d66d;
        padding: 7px;
        z-index: 2;
    }

    @media print {

        body {
            background: white;
        }

        .print-controls {
            display: none !important;
        }

        .business-clearance {
            margin: 0;
            width: 100%;
            height: 281mm;
            min-height: 281mm;
            border: 3px solid #e4c46a;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        .footer-banner,
        .footer-note,
        .watermark,
        .top-stripe {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
    }

</style>
</head>

<body>

<div class="print-controls">
    <button class="print-button" onclick="window.print()">
        Print Business Clearance
    </button>

    <a href="service-requests.php" class="back-button">
        Back to Service Requests
    </a>
</div>

<div class="business-clearance">

    <div class="top-stripe"></div>
    <div class="watermark"></div>

    <div class="content">

        <!-- PERMIT NUMBER -->

        <div class="permit-number">
            Barangay Permit<br>
            No. <span><?= e($permitNumber) ?></span>
        </div>

        <!-- HEADER -->

        <div class="header">

            <p>REPUBLIC OF THE PHILIPPINES</p>
            <p>CITY OF MUNTINLUPA</p>

            <h1>BARANGAY CLEARANCE</h1>

            <div class="business-subtitle">
                FOR BUSINESS
            </div>

        </div>

        <!-- BUSINESS NAME -->

        <div class="business-field">

            <div class="field-label">
                BUSINESS NAME
            </div>

            <div class="field-value">
                <?= e($businessName ?: 'BUSINESS NAME NOT PROVIDED') ?>
            </div>

        </div>

        <!-- LINE OF BUSINESS -->

        <div class="business-field">

            <div class="field-label">
                LINE OF BUSINESS
            </div>

            <div class="field-value">
                <?= e($lineOfBusiness ?: 'NOT PROVIDED') ?>
            </div>

        </div>

        <!-- BUSINESS ADDRESS -->

        <div class="business-field">

            <div class="field-label">
                BUSINESS ADDRESS
            </div>

            <div class="field-value">
                <?= e($businessAddress ?: 'ADDRESS NOT PROVIDED') ?>
            </div>

        </div>

        <!-- DATES -->

        <div class="date-grid">

            <div>

                <div class="date-value">
                    <?= e($dateIssuedFormatted ?: '________________') ?>
                </div>

                <div class="date-label">
                    DATE OF ISSUANCE
                </div>

            </div>

            <div>

                <div class="date-value">
                    <?= e($dateExpirationFormatted ?: '________________') ?>
                </div>

                <div class="date-label">
                    DATE OF EXPIRATION
                </div>

            </div>

            <div>

                <div class="date-value">
                    <?= e($dateRenewalFormatted ?: '________________') ?>
                </div>

                <div class="date-label">
                    DATE OF RENEWAL
                </div>

            </div>

        </div>

        <!-- REVOCATION STATEMENT -->

        <div class="revocation-text">

            This Barangay Clearance for business can be revoked
            anytime if any conditions and provisions set forth
            by the Barangay Revenue Code and existing laws and
            regulations are violated and/or the safety, health
            and security of the public is at stake.

        </div>

        <!-- PAYMENT DETAILS -->

        <div class="payment-section">

            <table class="payment-table">

                <tr>
                    <td>BP Account No.</td>
                    <td>:</td>
                    <td><?= e($bpAccountNumber ?: '________________') ?></td>
                </tr>

                <tr>
                    <td>Taxpayer's Name</td>
                    <td>:</td>
                    <td><?= e($taxpayerName ?: '________________') ?></td>
                </tr>

                <tr>
                    <td>Amount Paid</td>
                    <td>:</td>
                    <td>
                        <?= e(
                            $amountPaid !== ''
                            ? number_format((float)$amountPaid, 2)
                            : '________________'
                        ) ?>
                    </td>
                </tr>

                <tr>
                    <td>O.R. Number</td>
                    <td>:</td>
                    <td><?= e($orNumber ?: '________________') ?></td>
                </tr>

                <tr>
                    <td>Date Paid</td>
                    <td>:</td>
                    <td><?= e($datePaidFormatted ?: '________________') ?></td>
                </tr>

                <tr>
                    <td>Barangay Permit No.</td>
                    <td>:</td>
                    <td><?= e($permitNumber ?: '________________') ?></td>
                </tr>

                <tr>
                    <td>Issued by</td>
                    <td>:</td>
                    <td><?= e($issuedBy) ?></td>
                </tr>

            </table>

        </div>

        <!-- CHAIRMAN -->

        <div class="chairman-section">

            <div class="chairman-signature-space"></div>

            <div class="chairman-name">
                DANILO R. TEVES
            </div>

            <div class="chairman-title">
                BARANGAY CHAIRMAN
            </div>

        </div>

    </div>

    <!-- FOOTER BANNER -->

    <div class="footer-banner">

        <div class="footer-brand">

            <div class="brand-big">
                KAPITAN
            </div>

            <div class="brand-big">
                TEVES
            </div>

            <div class="brand-small">
                ALAGANG TEVES, SERBISYONG DEEP
            </div>

        </div>

        <img
            src="../assets/images/barangay-logo.png"
            class="footer-logo"
            alt="Barangay Putatan Logo">

        <div class="footer-brand">

            <div class="brand-big">
                PUTATAN
            </div>

            <div class="brand-small">
                GARANTISANG SERBISYO
            </div>

        </div>

    </div>

    <!-- FOOTER NOTE -->

    <div class="footer-note">
        NOTE: POST THIS CLEARANCE IN A CONSPICUOUS PLACE.
    </div>

</div>

</body>
</html>
