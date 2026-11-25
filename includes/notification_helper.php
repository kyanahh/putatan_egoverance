<?php
/* =========================================================
   NOTIFICATION HELPER
   Barangay Putatan E-Governance System
========================================================= */

/**
 * Create a notification for a specific user.
 */
if (!function_exists('createNotification')) {
    function createNotification(
        PDO $pdo,
        int $user_id,
        string $title,
        string $message,
        string $notification_type = 'general',
        ?int $related_id = null
    ): bool {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO notifications
                    (user_id, title, message, notification_type, related_id, is_read)
                VALUES
                    (:user_id, :title, :message, :notification_type, :related_id, 0)
            ");

            return $stmt->execute([
                ':user_id' => $user_id,
                ':title' => $title,
                ':message' => $message,
                ':notification_type' => $notification_type,
                ':related_id' => $related_id
            ]);

        } catch (PDOException $e) {
            error_log('Notification creation failed: ' . $e->getMessage());
            return false;
        }
    }
}

/**
 * Get notifications belonging to a specific user.
 */
if (!function_exists('getUserNotifications')) {
    function getUserNotifications(PDO $pdo, int $user_id, int $limit = 10): array {
        try {
            $limit = max(1, min(100, $limit));

            $stmt = $pdo->prepare("
                SELECT notification_id, title, message,
                       notification_type, related_id,
                       is_read, created_at
                FROM notifications
                WHERE user_id = :user_id
                ORDER BY created_at DESC
                LIMIT $limit
            ");

            $stmt->execute([':user_id' => $user_id]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (PDOException $e) {
            error_log('Notification retrieval failed: ' . $e->getMessage());
            return [];
        }
    }
}

/**
 * Count unread notifications for a user.
 */
if (!function_exists('getUnreadNotificationCount')) {
    function getUnreadNotificationCount(PDO $pdo, int $user_id): int {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM notifications
                WHERE user_id = :user_id
                  AND is_read = 0
            ");

            $stmt->execute([':user_id' => $user_id]);

            return (int) $stmt->fetchColumn();

        } catch (PDOException $e) {
            error_log('Unread notification count failed: ' . $e->getMessage());
            return 0;
        }
    }
}

/**
 * Mark a single notification as read.
 */
if (!function_exists('markNotificationAsRead')) {
    function markNotificationAsRead(PDO $pdo, int $user_id, int $notification_id): bool {
        try {
            $stmt = $pdo->prepare("
                UPDATE notifications
                SET is_read = 1
                WHERE notification_id = :notification_id
                  AND user_id = :user_id
            ");

            return $stmt->execute([
                ':notification_id' => $notification_id,
                ':user_id' => $user_id
            ]);

        } catch (PDOException $e) {
            error_log('Mark notification as read failed: ' . $e->getMessage());
            return false;
        }
    }
}

/**
 * Mark all notifications as read for a user.
 */
if (!function_exists('markAllNotificationsAsRead')) {
    function markAllNotificationsAsRead(PDO $pdo, int $user_id): bool {
        try {
            $stmt = $pdo->prepare("
                UPDATE notifications
                SET is_read = 1
                WHERE user_id = :user_id
                  AND is_read = 0
            ");

            return $stmt->execute([':user_id' => $user_id]);

        } catch (PDOException $e) {
            error_log('Mark all notifications as read failed: ' . $e->getMessage());
            return false;
        }
    }
}