<?php
require_once '../includes/admin_auth.php';
require_once '../includes/audit_helper.php';
require_once '../includes/notification_helper.php';

$user_id = filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT);

if (!$user_id) {
    http_response_code(400);
    exit('Invalid resident ID.');
}

$stmt = $pdo->prepare("
    SELECT proof_of_residency
    FROM users
    WHERE user_id = :user_id
      AND role = 'resident'
    LIMIT 1
");

$stmt->execute([':user_id' => $user_id]);
$resident = $stmt->fetch();

if (!$resident || empty($resident['proof_of_residency'])) {
    http_response_code(404);
    exit('Proof of residency not found in database.');
}

// Extract only the filename
$filename = basename($resident['proof_of_residency']);

// Build the actual server path
$file_path = __DIR__ . '/../assets/uploads/residency_proofs/' . $filename;

// Check if file exists
if (!file_exists($file_path)) {
    http_response_code(404);
    exit('Uploaded file does not exist on server.');
}

if (!is_file($file_path)) {
    http_response_code(404);
    exit('Invalid file.');
}

// Detect actual MIME type
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file_path);

$allowed_mimes = [
    'image/jpeg',
    'image/png',
    'application/pdf'
];

if (!in_array($mime, $allowed_mimes, true)) {
    http_response_code(403);
    exit('Unsupported document type.');
}

// Display file inline
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($file_path));

readfile($file_path);
exit;