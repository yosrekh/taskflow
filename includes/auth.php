<?php
// includes/auth.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// Secure Session Initialization
function start_secure_session() {
    if (session_status() === PHP_SESSION_NONE) {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                   (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

        ini_set('session.use_strict_mode', '1');

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

// Start secure session on file include
start_secure_session();

// CSRF Token Management
function csrf_token() {
    start_secure_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf($token) {
    start_secure_session();
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], (string)$token);
}

function get_csrf_token_from_request() {
    return $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
}

// Access Control Helpers
function check_current_user(): ?string {
    start_secure_session();
    if (!isset($_SESSION['user_id'])) {
        return 'no_session';
    }

    global $pdo;
    $stmt = $pdo->prepare("
        SELECT id, role, is_active, must_change_password, password_changed_at, 
               UNIX_TIMESTAMP(password_changed_at) AS password_changed_at_ts 
        FROM users 
        WHERE id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || (int)$user['is_active'] !== 1) {
        return 'inactive';
    }

    if (!empty($user['password_changed_at_ts'])) {
        $pwdChangedTime = (int)$user['password_changed_at_ts'];
        $loginTime = (int)($_SESSION['login_time'] ?? 0);
        if ($pwdChangedTime > $loginTime) {
            return 'session_expired';
        }
    }

    $_SESSION['role'] = $user['role'];
    $_SESSION['is_active'] = (int)$user['is_active'];
    $_SESSION['must_change_password'] = (int)$user['must_change_password'];

    if ((int)$user['must_change_password'] === 1) {
        return 'password_change_required';
    }

    return null;
}

function require_login($base = '') {
    $status = check_current_user();
    if ($status === null) {
        return;
    }

    if ($status === 'password_change_required') {
        $currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
        if (!in_array($currentScript, ['change-password.php', 'logout.php'], true)) {
            header("Location: " . $base . "change-password.php");
            exit;
        }
        return;
    }

    if ($status === 'inactive' || $status === 'session_expired') {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    header("Location: " . $base . "login.php");
    exit;
}

function require_login_json() {
    $status = check_current_user();
    if ($status === null) {
        return;
    }

    if ($status === 'password_change_required') {
        http_response_code(403);
        echo json_encode(['error' => 'password_change_required']);
        exit;
    }

    if ($status === 'inactive' || $status === 'session_expired') {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'unauthorized']);
    exit;
}

function require_admin($base = '') {
    require_login($base);
    if (($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        die("غير مصرح لك بالوصول لهذه الصفحة.");
    }
}

// Authorization Helpers
function can_view_project($pdo, $userId, $projectId) {
    if (!$userId || !$projectId) {
        return false;
    }
    $stmt = $pdo->prepare("
        SELECT 1 FROM projects p 
        WHERE p.id = ? 
          AND (
            p.user_id = ? 
            OR EXISTS (
              SELECT 1 FROM tasks t 
              WHERE t.project_id = p.id AND t.assigned_to = ?
            )
          )
        LIMIT 1
    ");
    $stmt->execute([$projectId, $userId, $userId]);
    return (bool)$stmt->fetchColumn();
}

function can_manage_project($pdo, $userId, $projectId) {
    if (!$userId || !$projectId) {
        return false;
    }
    $stmt = $pdo->prepare("SELECT user_id FROM projects WHERE id = ?");
    $stmt->execute([$projectId]);
    $ownerId = $stmt->fetchColumn();
    return $ownerId !== false && (int)$ownerId === (int)$userId;
}

function can_update_task_status($pdo, $userId, $taskId) {
    if (!$userId || !$taskId) {
        return false;
    }
    $stmt = $pdo->prepare("
        SELECT p.user_id AS owner_id, t.assigned_to 
        FROM tasks t 
        JOIN projects p ON t.project_id = p.id 
        WHERE t.id = ?
    ");
    $stmt->execute([$taskId]);
    $row = $stmt->fetch();
    if (!$row) {
        return false;
    }
    return ((int)$row['owner_id'] === (int)$userId) ||
           ($row['assigned_to'] !== null && (int)$row['assigned_to'] === (int)$userId);
}

