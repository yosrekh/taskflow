<?php
// includes/auth.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/branding.php';

// Secure Session Initialization
function start_secure_session() {
    if (session_status() === PHP_SESSION_NONE) {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                   (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
        $isProduction = (defined('APP_ENV') && APP_ENV === 'production');

        ini_set('session.use_strict_mode', '1');

        $sessionName = defined('SESSION_NAME') ? SESSION_NAME : 'TASKFLOW_SESSID';
        session_name($sessionName);

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isProduction || $isHttps,
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
        SELECT id, name, role, is_active, must_change_password, password_changed_at, 
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

    $_SESSION['user_name'] = $user['name'] ?? '';
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
    header('Content-Type: application/json; charset=utf-8');
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

function is_admin(): bool {
    return ($_SESSION['role'] ?? '') === 'admin';
}

function require_admin($base = '') {
    require_login($base);
    if (!is_admin()) {
        render_error(403, "غير مصرح لك بالوصول لهذه الصفحة.");
    }
}

// Authorization Helpers
function can_view_project($pdo, $userId, $projectId) {
    if (is_admin()) {
        return true;
    }
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
    if (is_admin()) {
        return true;
    }
    if (!$userId || !$projectId) {
        return false;
    }
    $stmt = $pdo->prepare("SELECT user_id FROM projects WHERE id = ?");
    $stmt->execute([$projectId]);
    $ownerId = $stmt->fetchColumn();
    return $ownerId !== false && (int)$ownerId === (int)$userId;
}

function can_update_task_status($pdo, $userId, $taskId) {
    if (is_admin()) {
        return true;
    }
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

function can_view_task($pdo, $userId, $taskId): bool {
    if (!$userId || !$taskId) {
        return false;
    }
    if (is_admin()) {
        $stmt = $pdo->prepare("SELECT 1 FROM tasks WHERE id = ?");
        $stmt->execute([$taskId]);
        return (bool)$stmt->fetchColumn();
    }
    // DB role check fallback if session is not active
    $uStmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $uStmt->execute([$userId]);
    if ($uStmt->fetchColumn() === 'admin') {
        $stmt = $pdo->prepare("SELECT 1 FROM tasks WHERE id = ?");
        $stmt->execute([$taskId]);
        return (bool)$stmt->fetchColumn();
    }

    $stmt = $pdo->prepare("
        SELECT t.id, t.assigned_to, p.user_id AS owner_id, p.id AS project_id
        FROM tasks t
        JOIN projects p ON t.project_id = p.id
        WHERE t.id = ?
    ");
    $stmt->execute([$taskId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    if ((int)$row['owner_id'] === (int)$userId) {
        return true;
    }
    if ($row['assigned_to'] !== null && (int)$row['assigned_to'] === (int)$userId) {
        return true;
    }
    return can_view_project($pdo, $userId, (int)$row['project_id']);
}

function can_edit_task_checklist($pdo, $userId, $taskId): bool {
    if (!$userId || !$taskId) {
        return false;
    }
    if (is_admin()) {
        return true;
    }
    $uStmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $uStmt->execute([$userId]);
    if ($uStmt->fetchColumn() === 'admin') {
        return true;
    }
    $stmt = $pdo->prepare("
        SELECT p.user_id AS owner_id, t.assigned_to 
        FROM tasks t 
        JOIN projects p ON t.project_id = p.id 
        WHERE t.id = ?
    ");
    $stmt->execute([$taskId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    return ((int)$row['owner_id'] === (int)$userId) ||
           ($row['assigned_to'] !== null && (int)$row['assigned_to'] === (int)$userId);
}

function can_comment_task($pdo, $userId, $taskId): bool {
    return can_view_task($pdo, $userId, $taskId);
}

function can_edit_comment($pdo, $userId, $commentId): bool {
    if (!$userId || !$commentId) {
        return false;
    }
    $stmt = $pdo->prepare("SELECT user_id FROM task_comments WHERE id = ?");
    $stmt->execute([$commentId]);
    $authorId = $stmt->fetchColumn();
    return ($authorId !== false && $authorId !== null && (int)$authorId === (int)$userId);
}

function can_delete_comment($pdo, $userId, $commentId): bool {
    if (!$userId || !$commentId) {
        return false;
    }
    if (is_admin()) {
        $stmt = $pdo->prepare("SELECT 1 FROM task_comments WHERE id = ?");
        $stmt->execute([$commentId]);
        return (bool)$stmt->fetchColumn();
    }
    // DB role check fallback if session is not active
    $uStmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $uStmt->execute([$userId]);
    if ($uStmt->fetchColumn() === 'admin') {
        $stmt = $pdo->prepare("SELECT 1 FROM task_comments WHERE id = ?");
        $stmt->execute([$commentId]);
        return (bool)$stmt->fetchColumn();
    }

    $stmt = $pdo->prepare("
        SELECT c.user_id AS author_id, p.user_id AS project_owner_id
        FROM task_comments c
        JOIN tasks t ON c.task_id = t.id
        JOIN projects p ON t.project_id = p.id
        WHERE c.id = ?
    ");
    $stmt->execute([$commentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    return ($row['author_id'] !== null && (int)$row['author_id'] === (int)$userId) ||
           ((int)$row['project_owner_id'] === (int)$userId);
}

function get_user_initials(?string $name): string {
    if ($name === null) return 'TF';
    $clean = trim($name);
    if ($clean === '') return 'TF';
    $parts = preg_split('/\s+/u', $clean, -1, PREG_SPLIT_NO_EMPTY);
    if (empty($parts)) return 'TF';
    if (count($parts) >= 2) {
        $initials = mb_substr($parts[0], 0, 1, 'UTF-8') . mb_substr($parts[1], 0, 1, 'UTF-8');
    } else {
        $initials = mb_substr($parts[0], 0, 2, 'UTF-8');
    }
    return mb_strtoupper($initials, 'UTF-8');
}


