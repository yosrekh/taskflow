<?php
// includes/config.php

// Minimum required PHP version: 8.0.0 (TaskFlow utilizes PHP 8.0+ features)
define('MIN_PHP_VERSION', '8.0.0');
if (PHP_VERSION_ID < 80000) {
    die("TaskFlow requires PHP 8.0.0 or higher. Current version: " . PHP_VERSION);
}

require_once __DIR__ . '/errors.php';

// Minimal .env file parser as fallback
function load_env_file($filePath) {
    if (!file_exists($filePath) || !is_file($filePath)) {
        return false;
    }
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return false;
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
    return true;
}

// Multi-Install Isolation Environment Loading Order:
// 1. ../.env.<basename of the project folder> (e.g. /home/user/.env.company-a.example.com)
// 2. ../.env (single-install fallback)
// 3. ./.env (local dev)
$projectDir = dirname(__DIR__);
$projectFolder = basename($projectDir);
$parentDir = dirname($projectDir);

$envCandidates = [
    $parentDir . '/.env.' . $projectFolder,
    $parentDir . '/.env',
    $projectDir . '/.env',
];

$loadedEnvFile = null;
foreach ($envCandidates as $candidate) {
    if (load_env_file($candidate)) {
        $loadedEnvFile = $candidate;
        break;
    }
}

// Helper to retrieve configuration value
function env($key, $default = null) {
    $val = getenv($key);
    if ($val === false) {
        $val = $_ENV[$key] ?? $_SERVER[$key] ?? $default;
    }
    return $val;
}

// Log (not display) which file was loaded when APP_ENV=development (in CLI only if --verbose is passed)
if (env('APP_ENV') === 'development' && $loadedEnvFile) {
    $shouldLog = true;
    if (PHP_SAPI === 'cli') {
        $argv = $_SERVER['argv'] ?? [];
        $shouldLog = in_array('--verbose', $argv, true);
    }
    if ($shouldLog) {
        error_log("[TaskFlow Config] Loaded environment file: " . $loadedEnvFile);
    }
}

// Environment configuration
define('APP_ENV', env('APP_ENV', 'development'));

// Test database override for automated test suites:
// Strictly opt-in: honored ONLY when APP_ENV === 'development', request is loopback/CLI,
// TEST_DB_NAME is defined in .env, ends with '_test', and incoming header matches TEST_DB_NAME exactly.
if (APP_ENV === 'development') {
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
    $isLoopback = in_array($remoteAddr, ['127.0.0.1', '::1'], true) || (PHP_SAPI === 'cli' && $remoteAddr === '');
    $testDbName = env('TEST_DB_NAME');
    $headerTestDb = $_SERVER['HTTP_X_TASKFLOW_TEST_DB'] ?? '';
    if ($isLoopback && !empty($testDbName) && str_ends_with($testDbName, '_test') && $headerTestDb === $testDbName) {
        putenv("DB_NAME={$testDbName}");
        $_ENV['DB_NAME'] = $testDbName;
        $_SERVER['DB_NAME'] = $testDbName;
    }
}

// Database configuration
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_NAME', env('DB_NAME', 'taskflow_db'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));

// Timezone configuration (default Africa/Cairo)
define('APP_TIMEZONE', env('APP_TIMEZONE', 'Africa/Cairo'));
date_default_timezone_set(APP_TIMEZONE);

// Session configuration
define('SESSION_NAME', env('SESSION_NAME', 'TASKFLOW_SESSID'));

// Optional custom log file
$logFile = env('LOG_FILE', null);
if (!empty($logFile)) {
    ini_set('error_log', $logFile);
}

// Error reporting and display based on environment
if (APP_ENV === 'production') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL & ~E_DEPRECATED);
} else {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);
}

// Global HTML escaping helper
if (!function_exists('e')) {
    function e($str) {
        return htmlspecialchars((string)($str ?? ''), ENT_QUOTES, 'UTF-8');
    }
}
