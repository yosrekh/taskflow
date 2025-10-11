<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'unauthorized']);
    exit;
}
include 'includes/db.php';
$user_id = $_SESSION['user_id'];
if (isset($_GET['mark_read']) && $_GET['mark_read'] == '1') {
    // Mark all as read for this user
    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")->execute([$user_id]);
}
// Fetch all notifications, newest first
$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$user_id]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['success' => true, 'notifications' => $notifications]);
?>
