
<?php

/**
 * Audit Trail Helper
 * Barangay Putatan E-Governance System
 */

if (!function_exists('logAudit')) {

    function logAudit(
        PDO $pdo,
        string $action,
        string $module,
        string $description,
        ?int $user_id = null,
        ?string $user_role = null
    ): bool {

        try {

            // Use session details if user ID was not explicitly provided
            if ($user_id === null && isset($_SESSION['user_id'])) {
                $user_id = (int) $_SESSION['user_id'];
            }

            // Use session role if role was not explicitly provided
            if ($user_role === null && isset($_SESSION['role'])) {
                $user_role = (string) $_SESSION['role'];
            }

            // Fallback role for system-generated activities
            if (empty($user_role)) {
                $user_role = 'system';
            }

            // Capture IP address
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;

            // Prepare SQL statement
            $stmt = $pdo->prepare("
                INSERT INTO audit_logs
                (
                    user_id,
                    user_role,
                    action,
                    module,
                    description,
                    ip_address
                )
                VALUES
                (
                    :user_id,
                    :user_role,
                    :action,
                    :module,
                    :description,
                    :ip_address
                )
            ");

            $stmt->execute([
                ':user_id'     => $user_id,
                ':user_role'   => $user_role,
                ':action'      => $action,
                ':module'       => $module,
                ':description'  => $description,
                ':ip_address'  => $ip_address
            ]);

            return true;

        } catch (Throwable $e) {

            // Audit failures should not crash the main system
            error_log("Audit log error: " . $e->getMessage());

            return false;
        }
    }
}
