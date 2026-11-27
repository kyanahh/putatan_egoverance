<?php
require_once '../includes/staff_auth.php';

/* =========================================================
   GET REQUEST ID
========================================================= */
$request_id = (int)($_GET['request_id'] ?? 0);

if ($request_id <= 0) {
    die('Invalid service request.');
}

/* =========================================================
   FETCH SERVICE REQUEST AND APPLICATION DATA
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
   CERTIFICATE ACCESS CHECK
========================================================= */
$printableStatuses = [
    'approved',
    'ready_for_release',
    'completed'
];

if (!in_array($request['status'], $printableStatuses, true)) {
    die('This request is not yet approved for certificate printing.');
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

/*
    Flatten nested arrays so that fields can be searched
    regardless of their JSON nesting.
*/
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

/*
    Search for possible field names.

    This allows the certificate to use common field names
    even if the application forms have slightly different
    naming conventions.
*/
function getApplicationValue($data, $possibleKeys, $default = '')
{
    foreach ($possibleKeys as $key) {

        if (isset($data[$key]) && $data[$key] !== '') {
            return $data[$key];
        }
    }

    return $default;
}

/* =========================================================
   DECODE APPLICATION SNAPSHOTS
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
   RESIDENT INFORMATION
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

$birthdate = getApplicationValue(
    $applicantFlat,
    ['birthdate', 'birth_date', 'date_of_birth', 'dob'],
    $request['birth_date'] ?? ''
);

/* =========================================================
   COMPUTE AGE
========================================================= */
$age = '';

if ($birthdate !== '' && strtotime($birthdate) !== false) {
    $age = date_diff(
        date_create($birthdate),
        date_create('today')
    )->y;
}

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
        'residential_address',
        'address',
        'home_address'
    ],
    $profileAddress
);

/* =========================================================
   CLEAN CERTIFICATE ADDRESS
========================================================= */

$certificateAddress = trim($address);

if (
    stripos($certificateAddress, 'Barangay Putatan') === false &&
    stripos($certificateAddress, 'Muntinlupa City') === false
) {
    $certificateAddress .= ', Barangay Putatan, Muntinlupa City';
}

/* =========================================================
   PURPOSE
========================================================= */
$purpose = $request['purpose'] ?? '';

/* =========================================================
   DATE ISSUED
========================================================= */
$dateIssued = date('F d, Y');

/* =========================================================
   SERVICE-BASED CERTIFICATE CONTENT
========================================================= */

$serviceName = strtolower(trim($request['service_name'] ?? ''));

$certificateTitle = 'CERTIFICATE';
$certificateBody = '';

/* =========================================================
   BARANGAY CLEARANCE
========================================================= */

if (strpos($serviceName, 'barangay clearance') !== false) {

    $certificateTitle = 'BARANGAY CLEARANCE';

    $certificateBody = '
        <div class="to-whom">
            TO WHOM IT MAY CONCERN:
        </div>

        <p>
            This is to certify that
            <strong>' . e($fullName) . '</strong>';

    if ($age !== '') {
        $certificateBody .= ',
            <strong>' . e($age) . '</strong> years old';
    }

    $certificateBody .= ',
            and a resident of Barangay Putatan, Muntinlupa City,
            is known to be of good moral character and a law-abiding
            citizen in the community.
        </p>

        <p>
            To certify further, that he/she has no derogatory
            and/or criminal records filed in this barangay.
        </p>

        <p>
            ISSUED this <strong>' . e(date('jS')) . '</strong>
            day of <strong>' . e(date('F Y')) . '</strong>,
            at Barangay Putatan, Muntinlupa City upon request
            of the interested party for whatever legal purposes
            it may serve.
        </p>
    ';
}

/* =========================================================
   CERTIFICATE OF RESIDENCY
========================================================= */

elseif (
    strpos($serviceName, 'certificate of residency') !== false ||
    strpos($serviceName, 'residency certificate') !== false
) {

    $certificateTitle = 'CERTIFICATE OF RESIDENCY';

    $certificateBody = '
        <div class="to-whom">
            TO WHOM IT MAY CONCERN:
        </div>

        <p>
            This is to certify that
            <strong>' . e($fullName) . '</strong>';

    if ($age !== '') {
        $certificateBody .= ',
            <strong>' . e($age) . '</strong> years old';
    }

    $certificateBody = '
        <p class="salutation">
            TO WHOM IT MAY CONCERN:
        </p>

        <p>
            This is to certify that <strong>' . strtoupper(e($fullName)) . '</strong>,
            of legal age, Filipino citizen, whose specimen signature appears below,
            is a <strong>PERMANENT RESIDENT</strong> of Barangay Putatan,
            Muntinlupa City.
        </p>

        <p>
            Based on records of this office, he/she has been residing at
            Barangay Putatan, Muntinlupa City.
        </p>

        <p>
            This <strong>CERTIFICATION</strong> is being issued upon the request
            of the above-named person for whatever legal purpose it may serve.
        </p>

        <p>
            Issued this ' . date('jS') . ' day of ' . date('F') . ',
            ' . date('Y') . ' at Barangay Putatan, Muntinlupa City, Philippines.
        </p>
    ';
}

/* =========================================================
   CERTIFICATE OF INDIGENCY
========================================================= */

elseif (
    strpos($serviceName, 'certificate of indigency') !== false ||
    strpos($serviceName, 'indigency') !== false
) {

    $certificateTitle = 'CERTIFICATE OF INDIGENCY';

    $certificateBody = '
        <div class="to-whom">
            TO WHOM IT MAY CONCERN:
        </div>

        <p>
            This is to certify that
            <strong>' . e($fullName) . '</strong>';

    if ($age !== '') {
        $certificateBody .= ',
            <strong>' . e($age) . '</strong> years old';
    }

    $certificateBody = '
        <p class="salutation">
            TO WHOM IT MAY CONCERN:
        </p>

        <p>
            This is to <strong>CERTIFY</strong> that
            <strong>' . strtoupper(e($fullName)) . '</strong>,
            ' . ($age !== '' ? e($age) . ' years old, ' : '') . '
            a bona fide resident of ' . e($certificateAddress) . ',
                        and belongs to an indigent family, unmarried, orphan,
                        and unemployed.
        </p>

        <p>
            This certification is issued this day of
            <strong>' . date('j') . '</strong> of
            <strong>' . date('F Y') . '</strong> upon request
            of the said person above at <em>Barangay Putatan</em>
            for legal/medical purposes.
        </p>
    ';
}

/* =========================================================
   FIRST-TIME JOBSEEKER CERTIFICATION
========================================================= */

elseif (
    strpos($serviceName, 'first time jobseeker') !== false ||
    strpos($serviceName, 'first-time jobseeker') !== false ||
    strpos($serviceName, 'job seeker') !== false
)
{
    $certificateTitle = 'BARANGAY CERTIFICATION';

    $certificateBody = '
        <p class="salutation">
            <strong>To Whom It May Concern:</strong>
        </p>

        <p>
            This is to certify that <strong>' . strtoupper(e($fullName)) . '</strong>,
            ' . ($age !== '' ? e($age) . ' years old, ' : '') . '
            resident of ' . e($certificateAddress) . ',
                        is a qualified
            availing of RA 11261 or the First Time Jobseeker Act of 2019.
        </p>

        <p>
            This is to <strong>CERTIFY</strong> further, that the bearer
            was informed of his/her rights, including the duties and
            responsibilities accorded by RA 11261 through the OATH OF
            UNDERTAKINGS he/she has signed and executed in the presence
            of our Barangay Officials.
        </p>

        <p>
            Issued and signed this ' . date('j') . '
            day of ' . date('F Y') . ' at Barangay Putatan,
            City of Muntinlupa.
        </p>
    ';

    $isFirstTimeJobseeker = true;
}

/* =========================================================
   BARANGAY BUSINESS CLEARANCE
========================================================= */

elseif (
    strpos($serviceName, 'business clearance') !== false ||
    strpos($serviceName, 'business permit') !== false
)
{
    $certificateTitle = 'BARANGAY BUSINESS CLEARANCE';

    $businessName = getApplicationValue(
        $applicationFlat,
        ['business_name', 'establishment_name', 'name_of_business'],
        ''
    );

    $businessAddress = getApplicationValue(
        $applicationFlat,
        ['business_address', 'establishment_address', 'business_location'],
        $certificateAddress
    );

    $businessNature = getApplicationValue(
        $applicationFlat,
        ['business_nature', 'nature_of_business', 'business_type'],
        ''
    );

    $certificateBody = '
        <p class="salutation">
            TO WHOM IT MAY CONCERN:
        </p>

        <p>
            This is to certify that
            <strong>' . strtoupper(e($fullName)) . '</strong>,
            a bona fide resident of Barangay Putatan,
            Muntinlupa City, has requested a Barangay Business
            Clearance for the establishment/business named:
        </p>

        <p style="text-align:center;">
            <strong>' . e($businessName !== '' ? $businessName : '____________________________') . '</strong>
        </p>

        <p>
            Located at:
            <strong>' . e($businessAddress) . '</strong>
        </p>';

    if ($businessNature !== '') {
        $certificateBody .= '
        <p>
            Nature of Business:
            <strong>' . e($businessNature) . '</strong>
        </p>';
    }

    $certificateBody .= '
        <p>
            This certification is issued upon the request of the
            above-named applicant for business-related purposes,
            subject to applicable barangay requirements and verification.
        </p>

        <p>
            Issued this <strong>' . e(date('jS')) . '</strong>
            day of <strong>' . e(date('F Y')) . '</strong>
            at Barangay Putatan, City of Muntinlupa.
        </p>
    ';
}

/* =========================================================
   FALLBACK FOR UNSUPPORTED SERVICE
========================================================= */

else {

    $certificateTitle = strtoupper($request['service_name']);

    $certificateBody = '
        <div class="to-whom">
            CERTIFICATE TEMPLATE NOT YET AVAILABLE
        </div>

        <p>
            The printable template for
            <strong>' . e($request['service_name']) . '</strong>
            has not yet been configured.
        </p>
    ';
}

/* =========================================================
   ESCAPE OUTPUT
========================================================= */
function e($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}
?>

<!DOCTYPE html>
<html lang="en">
<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Barangay Clearance | Barangay Putatan</title>

    <style>

        @page {
            size: A4 portrait;
            margin: 15mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: "Times New Roman", serif;
            color: #000;
            background: #f2f2f2;
            margin: 0;
        }

        .print-container {
            width: 210mm;
            min-height: 297mm;
            margin: 20px auto;
            padding: 15mm;
            background: #fff;
            position: relative;
            overflow: hidden;
        }

        .watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            width: 420px;
            height: 420px;
            transform: translate(-50%, -50%);
            background-image: url('../assets/images/barangay-logo.png');
            background-repeat: no-repeat;
            background-position: center;
            background-size: contain;
            opacity: 0.08;
            pointer-events: none;
            z-index: 0;
        }

        .document-content {
            position: relative;
            z-index: 1;
        }

        .header {
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            text-align: center;
            margin-bottom: 25px;
        }

        .header-logo {
            width: 100px;
            height: 100px;
            object-fit: contain;
            position: absolute;
            left: 0;
            top: 0;
        }

        .header-text {
            width: 100%;
            padding-left: 100px;
            padding-right: 20px;
        }

        .header-text p {
            margin: 3px 0;
            font-size: 16px;
            font-weight: bold;
        }

        .header-text h2 {
            margin: 5px 0;
            font-size: 21px;
        }

        .document-title {
            text-align: center;
            font-weight: bold;
            font-size: 20px;
            margin: 30px 0;
        }

        .body-text {
            font-size: 16px;
            line-height: 1.9;
            text-align: justify;
            margin-top: 30px;
        }

        .body-text p {
            margin-bottom: 25px;
        }

        .to-whom {
            font-weight: bold;
            margin-bottom: 35px;
        }

        .signatory-section {
            margin-top: 70px;
            display: flex;
            justify-content: flex-end;
        }

        .chairman-signatory {
            text-align: center;
            width: 350px;
            font-size: 15px;
            position: relative;
        }

        .authority-text {
            font-style: italic;
            font-weight: bold;
            text-align: center;
        }

        .chairman-title {
            font-weight: bold;
            text-align: center;
            margin-top: 3px;
        }

        .signatory {
            text-align: center;
            width: 280px;
            font-size: 15px;
        }

        .signatory-name {
            font-weight: bold;
            text-transform: uppercase;
        }

        .signatory-title {
            margin-top: 3px;
        }

        .secretary-section {
            margin-top: 45px;
            display: flex;
            justify-content: flex-end;
        }

        .footer {
            position: absolute;
            bottom: 10mm;
            left: 15mm;
            right: 15mm;
            text-align: center;
            font-size: 10px;
            line-height: 1.5;
        }

        .footer p {
            margin: 2px 0;
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
            font-size: 15px;
            border-radius: 5px;
            cursor: pointer;
        }

        .print-button:hover {
            background: #0e205a;
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

        @media print {

            body {
                background: #fff;
            }

            .print-container {
                margin: 0;
                width: 100%;
                min-height: 267mm;
                padding: 0;
            }

            .print-controls {
                display: none !important;
            }

            .watermark {
                opacity: 0.08;
            }

        }

       /* =========================================================
        FIRST-TIME JOBSEEKER - PAGE LAYOUT
        ========================================================= */

        .jobseeker-page {
            position: relative;
            width: 100%;
            height: 267mm;
            min-height: 267mm;
            page-break-inside: avoid;
            break-inside: avoid;
            overflow: hidden;
        }

        .jobseeker-page-2 {
            page-break-before: always;
            break-before: page;
        }

        .jobseeker-page .watermark {
            position: absolute;
            width: 380px;
            height: 380px;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background-image: url('../assets/images/barangay-logo.png');
            background-repeat: no-repeat;
            background-position: center;
            background-size: contain;
            opacity: 0.08;
            z-index: 0;
            pointer-events: none;
        }

        .jobseeker-page .document-content {
            position: relative;
            z-index: 1;
        }

        .jobseeker-page .footer {
            position: absolute;
            bottom: 5mm;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 10px;
            line-height: 1.5;
        }

        .oath-title {
            text-align: center;
            font-weight: bold;
            font-size: 20px;
            margin: 25px 0;
        }

        .oath-introduction {
            font-size: 14px;
            line-height: 1.6;
            text-align: justify;
            margin-bottom: 20px;
        }

        .oath-list {
            font-size: 13px;
            line-height: 1.5;
            text-align: justify;
            padding-left: 25px;
        }

        .oath-list li {
            margin-bottom: 8px;
        }

        .jobseeker-signature {
            margin-top: 25px;
        }

        .jobseeker-signature-line {
            width: 300px;
            margin-top: 40px;
            text-align: center;
        }

        .jobseeker-signature-name {
            font-weight: bold;
            text-transform: uppercase;
        }

        @media print {

            .jobseeker-page {
                height: 267mm;
                min-height: 267mm;
                width: 100%;
                overflow: hidden;
            }

            .jobseeker-page-2 {
                page-break-before: always;
                break-before: page;
            }

            .jobseeker-page .watermark {
                opacity: 0.08;
            }

            .oath-list {
                font-size: 12px;
                line-height: 1.4;
            }

        }

    </style>

</head>

<body>

<div class="print-controls">

    <button
        class="print-button"
        onclick="window.print()">

        Print <?= e($certificateTitle) ?>

    </button>

    <a
        href="service-requests.php"
        class="back-button">

        Back to Service Requests

    </a>

</div>

<div class="print-container">

    <?php if (empty($isFirstTimeJobseeker)): ?>
        <div class="watermark"></div>
    <?php endif; ?>

        <div class="document-content">

            <?php if (!empty($isFirstTimeJobseeker)): ?>

                <!-- =================================================
                    PAGE 1: FIRST-TIME JOBSEEKER CERTIFICATION
                ================================================== -->

                <div class="jobseeker-page">

                    <div class="watermark"></div>

                    <div class="header">

                        <img
                            src="../assets/images/barangay-logo.png"
                            class="header-logo"
                            alt="Barangay Putatan Logo">

                        <div class="header-text">
                            <p>REPUBLIKA NG PILIPINAS</p>
                            <p>SANGGUNIANG BARANGAY NG PUTATAN</p>
                            <h2>LUNGSOD NG MUNTINLUPA</h2>
                        </div>

                    </div>

                    <div class="document-title">
                        BARANGAY CERTIFICATION
                    </div>

                    <div class="body-text">

                        <div class="to-whom">
                            To Whom It May Concern:
                        </div>

                        <p>
                            This is to certify that
                            <strong><?= e($fullName) ?></strong>,
                            <?php if ($age !== ''): ?>
                                <?= e($age) ?> years old,
                            <?php endif; ?>
                            Filipino citizen, a resident of
                            <?= e($address) ?>,
                            Barangay Putatan, Muntinlupa City is a
                            qualified availing of RA 11261 or the
                            First Time Jobseeker Act of 2019.
                        </p>

                        <p>
                            This is to <strong>CERTIFY</strong> further,
                            that the bearer was informed of his/her rights,
                            including the duties and responsibilities
                            accorded by RA 11261 through the
                            <strong>OATH OF UNDERTAKINGS</strong>
                            he/she has signed and executed in the presence
                            of our Barangay Officials.
                        </p>

                        <p>
                            Issued and signed this
                            <strong><?= e(date('jS')) ?></strong>
                            day of
                            <strong><?= e(date('F Y')) ?></strong>
                            at Barangay Putatan, City of Muntinlupa.
                        </p>

                    </div>

                </div>


                <!-- =================================================
                    PAGE 2: OATH OF UNDERTAKINGS
                ================================================== -->

                <div class="jobseeker-page jobseeker-page-2">

                    <div class="watermark"></div>

                    <div class="header">

                        <img
                            src="../assets/images/barangay-logo.png"
                            class="header-logo"
                            alt="Barangay Putatan Logo">

                        <div class="header-text">
                            <p>REPUBLIKA NG PILIPINAS</p>
                            <p>SANGGUNIANG BARANGAY NG PUTATAN</p>
                            <h2>LUNGSOD NG MUNTINLUPA</h2>
                        </div>

                    </div>

                    <div class="oath-title">
                        OATH OF UNDERTAKINGS
                    </div>

                    <div class="oath-introduction">

                        I,
                        <strong><?= strtoupper(e($fullName)) ?></strong>,
                        <?php if ($age !== ''): ?>
                            <?= e($age) ?> years old,
                        <?php endif; ?>
                        resident of <strong><?= e($certificateAddress) ?></strong>,
                                                availing the
                        benefits of Republic Act 11261, otherwise known
                        as the First Time Jobseekers Assistance Act of
                        2019, do hereby declare, agree and undertake to
                        abide and be bound by the following:

                    </div>

                    <ol class="oath-list">

                        <li>
                            That this is the first time I will actively
                            look for a job, and therefore requiring that
                            a Barangay Certification be issued in my
                            favor to avail the benefit of the law;
                        </li>

                        <li>
                            That I am aware the benefit and privilege
                            under the said law shall be valid only for
                            one (1) year from the date that the Barangay
                            Certification is issued;
                        </li>

                        <li>
                            That I can avail the benefits of the law
                            only once;
                        </li>

                        <li>
                            That I understand that my personal information
                            shall be included in the roster/List of First
                            Time Jobseekers and will not be used for any
                            unlawful purpose;
                        </li>

                        <li>
                            That I will inform and/or report to the
                            Barangay personally, through text or other
                            means, or through my family/relatives once
                            I get employed;
                        </li>

                        <li>
                            That I am not a beneficiary of the JobStart
                            Program under R.A. No. 10869 and other laws
                            that give similar exemptions for the documents
                            or transactions exempted under R.A. 11261;
                        </li>

                        <li>
                            That if issued the requested Certification,
                            I will not use the same in any fraud, neither
                            falsify nor help and/or assist in the
                            fabrication of the said certification;
                        </li>

                        <li>
                            That this undertaking is made solely for the
                            purpose of obtaining a Barangay Certification
                            consistent with the objective of R.A. 11261
                            and not for any other purpose; and
                        </li>

                        <li>
                            That I consent to the use of my personal
                            information pursuant to the Data Privacy Act
                            and other applicable laws, rules and regulations.
                        </li>

                    </ol>

                    <div class="jobseeker-signature">

                        <p>
                            Signed this
                            <strong><?= e(date('jS')) ?></strong>
                            day of
                            <strong><?= e(date('F Y')) ?></strong>
                            in Barangay Putatan, City of Muntinlupa.
                        </p>

                        <div class="jobseeker-signature-line">

                            <div class="jobseeker-signature-name">
                                <?= strtoupper(e($fullName)) ?>
                            </div>

                            <div>
                                First Time Jobseeker
                            </div>

                        </div>

                    </div>

                    <!-- CHAIRMAN -->

                    <div class="signatory-section">

                        <div class="chairman-signatory">

                            <div class="authority-text">
                                With the authority of Hon.
                                <strong>DANILO R. TEVES</strong>
                            </div>

                            <div class="chairman-title">
                                Barangay Chairman
                            </div>

                        </div>

                    </div>

                    <!-- SECRETARY -->

                    <div class="secretary-section">

                        <div class="signatory">

                            <div class="signatory-name">
                                MARCOS JR. B. ELLEMA
                            </div>

                            <div class="signatory-title">
                                Barangay Secretary
                            </div>

                        </div>

                    </div>

                </div>

            <?php else: ?>

                <!-- =================================================
                    EXISTING SINGLE-PAGE CERTIFICATES
                ================================================== -->

                <div class="header">

                    <img
                        src="../assets/images/barangay-logo.png"
                        class="header-logo"
                        alt="Barangay Putatan Logo">

                    <div class="header-text">
                        <p>REPUBLIKA NG PILIPINAS</p>
                        <p>SANGGUNIANG BARANGAY NG PUTATAN</p>
                        <h2>LUNGSOD NG MUNTINLUPA</h2>
                    </div>

                </div>

                <div class="document-title">
                    <?= e($certificateTitle) ?>
                </div>

                <div class="body-text">
                    <?= $certificateBody ?>
                </div>

                <!-- EXISTING SIGNATORIES -->

                <div class="signatory-section">

                    <div class="signatory">

                        <div class="signatory-name">
                            DANILO R. TEVES
                        </div>

                        <div class="signatory-title">
                            Barangay Chairman
                        </div>

                    </div>

                </div>

                <div class="secretary-section">

                    <div class="signatory">

                        <div class="signatory-name">
                            MARCOS JR. B. ELLEMA
                        </div>

                        <div class="signatory-title">
                            Barangay Secretary
                        </div>

                    </div>

                </div>

            <?php endif; ?>

        </div>

    <!-- =================================================
         BARANGAY FOOTER
    ================================================== -->

    <?php if (empty($isFirstTimeJobseeker)): ?>

        <div class="footer">

            <p>
                Barangay Hall Bruger St., Putatan, Muntinlupa City
            </p>

            <p>
                Contact Number: Tel: 8862-0407 |
                Barangay Police Tel: 8862-0408 |
                Rescue/BDRRM: 8832-1148
            </p>

            <p>
                Email Address: putatan.muntinlupacity@gmail.com |
                FB: Diretsong Serbisyo - Official
            </p>

        </div>

    <?php endif; ?>

</div>

</body>
</html>