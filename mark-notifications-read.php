<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    exit;
}
include 'includes/db.php';
$user_id = $_SESSION['user_id'];
$pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")->execute([$user_id]);
?>
