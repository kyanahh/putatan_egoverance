<?php

/**
 * Audit Trail Helper
 * Barangay Putatan E-Governance System
 *
 * Compatible with the actual audit_logs table:
 * log_id, user_id, action, module, description,
 * ip_address, created_at
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

            // Use session user ID if none was explicitly provided
            if ($user_id === null && isset($_SESSION['user_id'])) {
                $user_id = (int)$_SESSION['user_id'];
            }

            // Prevent invalid user IDs
            if ($user_id !== null && $user_id <= 0) {
                $user_id = null;
            }

            // Capture visitor IP address
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;

            // Insert using the actual database columns
            $stmt = $pdo->prepare("
                INSERT INTO audit_logs
                (
                    user_id,
                    action,
                    module,
                    description,
                    ip_address
                )
                VALUES
                (
                    :user_id,
                    :action,
                    :module,
                    :description,
                    :ip_address
                )
            ");

            $stmt->execute([
                ':user_id'     => $user_id,
                ':action'      => $action,
                ':module'      => $module,
                ':description'  => $description,
                ':ip_address'  => $ip_address
            ]);

            return true;

        } catch (Throwable $e) {

            // Record the error for troubleshooting
            error_log(
                '[Barangay Putatan Audit Error] ' .
                $e->getMessage()
            );

            return false;
        }
    }
}