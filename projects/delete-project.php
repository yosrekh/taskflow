<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
include '../includes/db.php';

$project_id = $_GET['id'] ?? null;

if (!$project_id) {
    die("رقم المشروع غير موجود.");
}

try {
    // Delete tasks first due to foreign key constraint
    $pdo->prepare("DELETE FROM tasks WHERE project_id = ?")->execute([$project_id]);

    // Then delete the project
    $pdo->prepare("DELETE FROM projects WHERE id = ?")->execute([$project_id]);

    header("Location: ../dashboard.php");
    exit;
} catch (PDOException $e) {
    die("فشل في حذف المشروع.");
}
?>