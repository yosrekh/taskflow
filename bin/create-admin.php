<?php
// bin/create-admin.php

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../includes/db.php';

if ($argc < 3) {
    echo "Usage: php bin/create-admin.php \"Name\" email@example.com\n";
    exit(1);
}

$name = trim($argv[1]);
$email = trim($argv[2]);

if (empty($name) || empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo "Error: Invalid name or email address.\n";
    exit(1);
}

function generate_temp_password($length = 14) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $password = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, $max)];
    }
    return $password;
}

try {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $existingUser = $stmt->fetch();

    $tempPassword = generate_temp_password(14);
    $hashedPassword = password_hash($tempPassword, PASSWORD_DEFAULT);

    if ($existingUser) {
        $updateStmt = $pdo->prepare("
            UPDATE users 
            SET name = ?, password = ?, role = 'admin', is_active = 1, must_change_password = 1, password_changed_at = NOW() 
            WHERE id = ?
        ");
        $updateStmt->execute([$name, $hashedPassword, $existingUser['id']]);
        echo "Admin user updated successfully.\n";
    } else {
        $insertStmt = $pdo->prepare("
            INSERT INTO users (name, email, password, role, is_active, must_change_password) 
            VALUES (?, ?, ?, 'admin', 1, 1)
        ");
        $insertStmt->execute([$name, $email, $hashedPassword]);
        echo "Admin user created successfully.\n";
    }

    echo "Name: {$name}\n";
    echo "Email: {$email}\n";
    echo "Temporary Password: {$tempPassword}\n";
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
    exit(1);
}
