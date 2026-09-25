<?php
require_once __DIR__ . '/includes/auth.php';
require_login_json();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/includes/db.php';

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

$user_id = (int)($_SESSION['user_id'] ?? 0);
$id = 0;
if (isset($_POST['id'])) {
    $id = (int)$_POST['id'];
} else {
    $rawInput = file_get_contents('php://input');
    $jsonData = json_decode($rawInput, true);
    if (isset($jsonData['id'])) {
        $id = (int)$jsonData['id'];
    }
}

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_id']);
    exit;
}

try {
    // Check if notification exists and belongs to the authenticated user
    $checkStmt = $pdo->prepare("SELECT id, is_read FROM notifications WHERE id = ? AND user_id = ?");
    $checkStmt->execute([$id, $user_id]);
    $notif = $checkStmt->fetch();

    if (!$notif) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'not_found']);
        exit;
    }

    if ($notif['is_read'] == 0) {
        $updateStmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        $updateStmt->execute([$id, $user_id]);
    }

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    error_log("Mark notification read error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'db_error']);
}
