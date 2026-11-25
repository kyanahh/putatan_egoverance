
<?php
session_start();

require_once 'config/database.php';

$errors = [];
$success = "";

$full_name = "";
$email = "";
$username = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $proof = $_FILES['proof_of_residency'] ?? null;

    // Required fields
    if ($full_name === '' || $email === '' || $username === '' ||
        $password === '' || $confirm_password === '') {

        $errors[] = "Please complete all required fields.";

    }

    // Name validation
    if ($full_name !== '' && strlen($full_name) < 3) {
        $errors[] = "Please enter a valid full name.";
    }

    // Email validation
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    }

    // Username validation
    if ($username !== '' && !preg_match('/^[a-zA-Z0-9_.-]{4,30}$/', $username)) {
        $errors[] = "Username must be 4-30 characters and may contain letters, numbers, underscores, dots, and hyphens.";
    }

    // Password validation
    if ($password !== '' && strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters.";
    }

    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match.";
    }

    // Check for duplicate username/email
    if (empty($errors)) {

        $check = $pdo->prepare("
            SELECT user_id
            FROM users
            WHERE username = :username OR email = :email
            LIMIT 1
        ");

        $check->execute([
            ':username' => $username,
            ':email' => $email
        ]);

        if ($check->fetch()) {
            $errors[] = "Username or email is already registered.";
        }
    }

    // Proof of residency validation
    if (!$proof || $proof['error'] === UPLOAD_ERR_NO_FILE) {

        $errors[] = "Please upload your proof of residency.";

    } elseif ($proof['error'] !== UPLOAD_ERR_OK) {

        $errors[] = "There was an error uploading your document.";

    } else {

        $max_size = 5 * 1024 * 1024; // 5 MB

        if ($proof['size'] > $max_size) {
            $errors[] = "Proof of residency must not exceed 5 MB.";
        }

        $allowed_extensions = ['jpg', 'jpeg', 'png', 'pdf'];

        $extension = strtolower(
            pathinfo($proof['name'], PATHINFO_EXTENSION)
        );

        if (!in_array($extension, $allowed_extensions, true)) {
            $errors[] = "Only JPG, JPEG, PNG, and PDF files are allowed.";
        }

        // Verify the actual file type, not just its filename
        if (!empty($proof['tmp_name']) && is_uploaded_file($proof['tmp_name'])) {

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime_type = $finfo->file($proof['tmp_name']);

            $allowed_mime_types = [
                'jpg'  => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png'  => 'image/png',
                'pdf'  => 'application/pdf'
            ];

            if (
                !isset($allowed_mime_types[$extension]) ||
                $mime_type !== $allowed_mime_types[$extension]
            ) {
                $errors[] = "The uploaded document does not match its file type.";
            }

        } else {

            $errors[] = "Invalid uploaded document.";

        }
    }

    // Insert resident account
    if (empty($errors)) {

        try {

            $upload_dir = __DIR__ . '/assets/uploads/residency_proofs/';

            if (!is_dir($upload_dir)) {
                if (!mkdir($upload_dir, 0750, true)) {
                    throw new RuntimeException("Unable to create upload directory.");
                }
            }

            $extension = strtolower(
                pathinfo($proof['name'], PATHINFO_EXTENSION)
            );

            $new_filename = bin2hex(random_bytes(16)) . '.' . $extension;

            $destination = $upload_dir . $new_filename;

            $relative_path = 'assets/uploads/residency_proofs/' . $new_filename;

            if (!move_uploaded_file($proof['tmp_name'], $destination)) {
                throw new RuntimeException("Unable to save uploaded document.");
            }

            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("
                INSERT INTO users (
                    full_name,
                    email,
                    username,
                    password,
                    proof_of_residency,
                    role,
                    account_status
                ) VALUES (
                    :full_name,
                    :email,
                    :username,
                    :password,
                    :proof_of_residency,
                    'resident',
                    'pending'
                )
            ");

            $stmt->execute([
                ':full_name' => $full_name,
                ':email' => $email,
                ':username' => $username,
                ':password' => $hashed_password,
                ':proof_of_residency' => $relative_path
            ]);

            $success = "Registration submitted successfully! Your account is awaiting administrator approval.";

            $full_name = "";
            $email = "";
            $username = "";

        } catch (Throwable $e) {

            // Remove the uploaded document if database insertion fails
            if (isset($destination) && file_exists($destination)) {
                unlink($destination);
            }

            $errors[] = "Unable to complete registration. Please try again.";

        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Resident Registration | Barangay Putatan</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-page">

<nav class="navbar navbar-dark barangay-navbar">
    <div class="container">

        <a class="navbar-brand d-flex align-items-center gap-3"
           href="index.php">

            <img src="assets/images/barangay-logo.png"
                 alt="Barangay Putatan Logo"
                 class="navbar-logo">

            <span class="fw-bold">Barangay Putatan</span>
        </a>

    </div>
</nav>

<div class="container py-5">

    <div class="row justify-content-center">

        <div class="col-md-8 col-lg-6">

            <div class="card login-card border-0 shadow">

                <div class="card-body p-4 p-md-5">

                    <div class="text-center mb-4">

                        <img src="assets/images/barangay-logo.png"
                             alt="Barangay Logo"
                             class="login-logo mb-3">

                        <h2 class="fw-bold text-primary-custom">
                            Resident Registration
                        </h2>

                        <p class="text-muted">
                            Create your Barangay Putatan account
                        </p>

                    </div>

                    <?php if (!empty($errors)): ?>

                        <div class="alert alert-danger">
                            <ul class="mb-0">
                                <?php foreach ($errors as $error): ?>
                                    <li>
                                        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>

                    <?php endif; ?>

                    <?php if ($success !== ""): ?>

                        <div class="alert alert-success">
                            <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>

                            <hr>

                            <a href="login.php"
                               class="btn btn-primary-custom">
                                Go to Login
                            </a>
                        </div>

                    <?php endif; ?>

                    <form method="POST" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                Full Name
                            </label>

                            <input type="text"
                                   name="full_name"
                                   class="form-control"
                                   placeholder="Enter your full name"
                                   value="<?= htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8') ?>"
                                   required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                Email Address
                            </label>

                            <input type="email"
                                   name="email"
                                   class="form-control"
                                   placeholder="Enter your email"
                                   value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"
                                   required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                Username
                            </label>

                            <input type="text"
                                   name="username"
                                   class="form-control"
                                   placeholder="Choose a username"
                                   value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>"
                                   required>
                            
                            <small class="text-muted">
                                4-30 characters. Letters, numbers, dots,
                                underscores, and hyphens allowed.
                            </small>
                        </div>

                        <div class="mb-4">

                            <label class="form-label fw-semibold">
                                Proof of Residency
                            </label>

                            <input type="file"
                                name="proof_of_residency"
                                class="form-control"
                                accept=".jpg,.jpeg,.png,.pdf"
                                required>

                            <small class="text-muted">
                                Upload a Barangay Certificate of Residency, valid ID,
                                or another document accepted by the barangay.
                                JPG, PNG, or PDF only. Maximum file size: 5 MB.
                            </small>

                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                Password
                            </label>

                            <input type="password"
                                   name="password"
                                   class="form-control"
                                   placeholder="Create a password"
                                   minlength="8"
                                   required>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-semibold">
                                Confirm Password
                            </label>

                            <input type="password"
                                   name="confirm_password"
                                   class="form-control"
                                   placeholder="Confirm your password"
                                   minlength="8"
                                   required>
                        </div>

                        <button type="submit"
                                class="btn btn-primary-custom btn-lg w-100">
                            Submit Registration
                        </button>

                    </form>

                    <div class="text-center mt-4">

                        <p class="mb-2">
                            Already have an account?
                        </p>

                        <a href="login.php"
                           class="text-decoration-none fw-semibold">
                            Login Here
                        </a>

                    </div>

                    <div class="text-center mt-3">
                        <a href="index.php"
                           class="text-muted small text-decoration-none">
                            Return to Homepage
                        </a>
                    </div>

                </div>
            </div>

        </div>
    </div>
</div>

<footer class="footer-section text-center py-3">
    <small>
        Barangay Putatan E-Governance System
    </small>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
