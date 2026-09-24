<?php
require_once __DIR__ . '/config.php';

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

    // Sync MySQL timezone with PHP's timezone offset
    $offset = date('P');
    if (preg_match('/^[+-]\d{2}:\d{2}$/', $offset)) {
        $pdo->prepare("SET time_zone = ?")->execute([$offset]);
    }
} catch (PDOException $e) {
    error_log("Database connection error: " . $e->getMessage());
    render_error(500, "خطأ في الاتصال بقاعدة البيانات. يرجى المحاولة لاحقاً.");
}