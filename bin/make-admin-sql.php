<?php
// bin/make-admin-sql.php

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit;
}

if ($argc < 3) {
    echo "Usage: php bin/make-admin-sql.php \"Name\" email@example.com\n";
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

$tempPassword = generate_temp_password(14);
$hashedPassword = password_hash($tempPassword, PASSWORD_DEFAULT);

// Properly escape single quotes and backslashes for SQL literal string
$escapedName = str_replace(["\\", "'"], ["\\\\", "''"], $name);
$escapedEmail = str_replace(["\\", "'"], ["\\\\", "''"], $email);
$escapedHash = str_replace(["\\", "'"], ["\\\\", "''"], $hashedPassword);

$sql = "INSERT INTO users (name, email, password, role, is_active, must_change_password) VALUES ('{$escapedName}', '{$escapedEmail}', '{$escapedHash}', 'admin', 1, 1);";

echo "========================================\n";
echo "TaskFlow Admin Account Generator\n";
echo "========================================\n";
echo "Name: {$name}\n";
echo "Email: {$email}\n";
echo "Temporary Password: {$tempPassword}\n\n";
echo "Copy and run this SQL query in phpMyAdmin:\n";
echo "----------------------------------------\n";
echo "{$sql}\n";
echo "----------------------------------------\n";
