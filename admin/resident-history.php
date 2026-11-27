<?php
require_once '../includes/admin_auth.php';

header('Content-Type: application/json; charset=utf-8');

$user_id = filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT);

if (!$user_id) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid resident ID.'
    ]);
    exit;
}

try {

    /* Verify the resident exists and is approved */
    $residentStmt = $pdo->prepare("
        SELECT user_id
        FROM users
        WHERE user_id = ?
          AND account_status = 'approved'
    ");

    $residentStmt->execute([$user_id]);

    if (!$residentStmt->fetch()) {
        echo json_encode([
            'success' => false,
            'message' => 'Resident not found.'
        ]);
        exit;
    }

    /* Fetch profile history */
    $stmt = $pdo->prepare("
        SELECT
            h.history_id,
            h.resident_user_id,
            h.admin_user_id,
            h.action_type,
            h.changed_fields,
            h.created_at,
            u.username AS admin_username
        FROM resident_profile_history h
        LEFT JOIN users u
            ON u.user_id = h.admin_user_id
        WHERE h.resident_user_id = ?
        ORDER BY h.created_at DESC, h.history_id DESC
    ");

    $stmt->execute([$user_id]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($history as &$row) {

        $row['changed_fields'] = json_decode(
            $row['changed_fields'],
            true
        );

        if (!is_array($row['changed_fields'])) {
            $row['changed_fields'] = [];
        }

    }

    unset($row);

    echo json_encode([
        'success' => true,
        'history' => $history
    ]);

} catch (Exception $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to retrieve resident history.'
    ]);

}