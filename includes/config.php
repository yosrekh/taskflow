<?php
// includes/config.php

// Minimal .env file parser as fallback
function load_env_file($filePath) {
    if (!file_exists($filePath)) {
        return;
    }
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $val = trim($parts[1]);
            // Remove matching enclosing single or double quotes
            if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
                (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
                $val = substr($val, 1, -1);
            }
            if (getenv($key) === false && !isset($_ENV[$key])) {
                putenv("{$key}={$val}");
                $_ENV[$key] = $val;
                $_SERVER[$key] = $val;
            }
        }
    }
}

// Load .env from project root if it exists
load_env_file(__DIR__ . '/../.env');

// Helper to retrieve configuration value
function env($key, $default = null) {
    $val = getenv($key);
    if ($val === false) {
        $val = $_ENV[$key] ?? $_SERVER[$key] ?? $default;
    }
    return $val;
}

// Database configuration
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_NAME', env('DB_NAME', 'taskflow_db'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));

// Environment configuration
define('APP_ENV', env('APP_ENV', 'development'));

// Registration setting (default false)
$allowRegistrationVal = env('ALLOW_REGISTRATION', false);
if (is_string($allowRegistrationVal)) {
    $allowRegistrationVal = in_array(strtolower($allowRegistrationVal), ['true', '1', 'yes', 'on'], true);
}
define('ALLOW_REGISTRATION', (bool)$allowRegistrationVal);

// Error reporting and display based on environment
if (APP_ENV === 'production') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
} else {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);
}
