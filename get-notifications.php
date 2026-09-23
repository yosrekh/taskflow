<?php
require_once __DIR__ . '/includes/auth.php';
require_login_json();
require_once __DIR__ . '/includes/db.php';

$user_id = $_SESSION['user_id'];

if (isset($_GET['mark_read']) && $_GET['mark_read'] == '1') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
        exit;
    }
    $csrfToken = get_csrf_token_from_request();
    if (!verify_csrf($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'invalid_csrf']);
        exit;
    }
    try {
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")->execute([$user_id]);
    } catch (PDOException $e) {
        error_log("Mark read in get-notifications error: " . $e->getMessage());
    }
}

// Fetch all notifications, newest first
try {
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$user_id]);
    $notifications = $stmt->fetchAll();
    echo json_encode(['success' => true, 'notifications' => $notifications]);
} catch (PDOException $e) {
    error_log("Get notifications error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'db_error']);
}
