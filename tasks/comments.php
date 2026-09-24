<?php
/**
 * Tasks Comments API Endpoint
 * Handles GET (listing comments) and POST (add, edit, delete comment).
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_login_json();

header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/includes/db.php';
global $pdo;

$userId = (int)$_SESSION['user_id'];


// -----------------------------------------------------------------------------
// GET: Fetch comments for a task
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $taskId = isset($_GET['task_id']) ? (int)$_GET['task_id'] : 0;

    if (!$taskId || !can_view_task($pdo, $userId, $taskId)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'not_found', 'message' => 'المهمة غير موجودة أو غير مصرح لك بعرضها.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT c.id, c.task_id, c.user_id, c.body, c.created_at, c.updated_at,
                   u.name AS author_name
            FROM task_comments c
            LEFT JOIN users u ON c.user_id = u.id
            WHERE c.task_id = ?
            ORDER BY c.created_at ASC, c.id ASC
        ");
        $stmt->execute([$taskId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $comments = [];
        foreach ($rows as $row) {
            $authorName = $row['author_name'] ?? 'مستخدم محذوف';
            $initials = get_user_initials($row['author_name']);
            $isAuthor = ($row['user_id'] !== null && (int)$row['user_id'] === $userId);

            $comments[] = [
                'id' => (int)$row['id'],
                'task_id' => (int)$row['task_id'],
                'user_id' => $row['user_id'] !== null ? (int)$row['user_id'] : null,
                'author_name' => $authorName,
                'initials' => $initials,
                'body' => $row['body'],
                'created_at' => $row['created_at'],
                'edited' => ($row['updated_at'] !== null),
                'can_edit' => $isAuthor,
                'can_delete' => can_delete_comment($pdo, $userId, (int)$row['id'])
            ];
        }

        echo json_encode(['success' => true, 'comments' => $comments]);
        exit;
    } catch (PDOException $e) {
        error_log("Get comments error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'db_error']);
        exit;
    }
}

// -----------------------------------------------------------------------------
// POST: Add, Edit, Delete Actions
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = get_csrf_token_from_request();
    if (!verify_csrf($token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'invalid_csrf', 'message' => 'رمز التحقق غير صالح.']);
        exit;
    }

    $action = $_POST['action'] ?? '';

    // ACTION: ADD
    if ($action === 'add') {
        $taskId = (int)($_POST['task_id'] ?? 0);
        if (!$taskId || !can_view_task($pdo, $userId, $taskId)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'not_found', 'message' => 'المهمة غير موجودة أو غير مصرح لك بالتعليق عليها.']);
            exit;
        }

        // Rate Limit: 10 comments per minute per user -> 429
        $stmtRate = $pdo->prepare("
            SELECT COUNT(*) FROM task_comments 
            WHERE user_id = ? AND created_at >= (NOW() - INTERVAL 1 MINUTE)
        ");
        $stmtRate->execute([$userId]);
        if ((int)$stmtRate->fetchColumn() >= 10) {
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => 'rate_limited', 'message' => 'تجاوزت الحد الأقصى للتعليقات (10 تعليقات في الدقيقة). يرجى الانتظار قليلاً.']);
            exit;
        }

        $body = trim($_POST['body'] ?? '');
        $len = mb_strlen($body, 'UTF-8');
        if ($len < 1 || $len > 2000) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'invalid_length', 'message' => 'يجب أن يكون نص التعليق بين حرف واحد و 2000 حرف.']);
            exit;
        }

        try {
            $ins = $pdo->prepare("INSERT INTO task_comments (task_id, user_id, body) VALUES (?, ?, ?)");
            $ins->execute([$taskId, $userId, $body]);
            $commentId = (int)$pdo->lastInsertId();

            // Fetch task & project details for notifications
            $tStmt = $pdo->prepare("
                SELECT t.title, t.project_id, t.assigned_to, p.user_id AS project_owner_id
                FROM tasks t
                JOIN projects p ON t.project_id = p.id
                WHERE t.id = ?
            ");
            $tStmt->execute([$taskId]);
            $taskInfo = $tStmt->fetch(PDO::FETCH_ASSOC);

            // Fetch previous commenters on this task
            $cUsersStmt = $pdo->prepare("SELECT DISTINCT user_id FROM task_comments WHERE task_id = ? AND user_id IS NOT NULL");
            $cUsersStmt->execute([$taskId]);
            $previousCommenters = $cUsersStmt->fetchAll(PDO::FETCH_COLUMN);

            // Collect candidate recipients: assignee, project owner, previous commenters
            $recipients = [];
            if (!empty($taskInfo['assigned_to'])) {
                $recipients[] = (int)$taskInfo['assigned_to'];
            }
            if (!empty($taskInfo['project_owner_id'])) {
                $recipients[] = (int)$taskInfo['project_owner_id'];
            }
            foreach ($previousCommenters as $uid) {
                if ($uid !== null) {
                    $recipients[] = (int)$uid;
                }
            }

            // Deduplicate and exclude the author
            $recipients = array_unique($recipients);
            $recipients = array_filter($recipients, fn($id) => (int)$id !== $userId);

            // Only notify active users
            if (!empty($recipients)) {
                $placeholders = implode(',', array_fill(0, count($recipients), '?'));
                $activeStmt = $pdo->prepare("SELECT id FROM users WHERE id IN ($placeholders) AND is_active = 1");
                $activeStmt->execute(array_values($recipients));
                $activeRecipients = $activeStmt->fetchAll(PDO::FETCH_COLUMN);

                $actionTime = date('Y-m-d H:i');
                $authorName = $_SESSION['user_name'] ?? 'مستخدم';
                $snippet = mb_substr($body, 0, 60, 'UTF-8');
                if ($len > 60) {
                    $snippet .= '…';
                }
                $notifMsg = "[{$actionTime}] {$authorName} علّق على المهمة '{$taskInfo['title']}': {$snippet}";
                $notifLink = "tasks/view-tasks.php?project_id={$taskInfo['project_id']}&task={$taskId}";

                $insNotif = $pdo->prepare("INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)");
                foreach ($activeRecipients as $rcpId) {
                    $insNotif->execute([(int)$rcpId, $notifMsg, $notifLink]);
                }
            }

            echo json_encode([
                'success' => true,
                'comment' => [
                    'id' => $commentId,
                    'task_id' => $taskId,
                    'user_id' => $userId,
                    'author_name' => $_SESSION['user_name'] ?? 'مستخدم',
                    'initials' => get_user_initials($_SESSION['user_name'] ?? ''),
                    'body' => $body,
                    'created_at' => date('Y-m-d H:i:s'),
                    'edited' => false,
                    'can_edit' => true,
                    'can_delete' => true
                ]
            ]);
            exit;
        } catch (PDOException $e) {
            error_log("Add comment error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'db_error']);
            exit;
        }
    }

    // ACTION: EDIT
    if ($action === 'edit') {
        $commentId = (int)($_POST['comment_id'] ?? 0);
        if (!$commentId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'missing_id']);
            exit;
        }

        if (!can_edit_comment($pdo, $userId, $commentId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'forbidden', 'message' => 'غير مصرح لك بتعديل هذا التعليق.']);
            exit;
        }

        $body = trim($_POST['body'] ?? '');
        $len = mb_strlen($body, 'UTF-8');
        if ($len < 1 || $len > 2000) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'invalid_length', 'message' => 'يجب أن يكون نص التعليق بين حرف واحد و 2000 حرف.']);
            exit;
        }

        try {
            $upd = $pdo->prepare("UPDATE task_comments SET body = ?, updated_at = NOW() WHERE id = ?");
            $upd->execute([$body, $commentId]);

            echo json_encode([
                'success' => true,
                'comment' => [
                    'id' => $commentId,
                    'body' => $body,
                    'edited' => true
                ]
            ]);
            exit;
        } catch (PDOException $e) {
            error_log("Edit comment error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'db_error']);
            exit;
        }
    }

    // ACTION: DELETE
    if ($action === 'delete') {
        $commentId = (int)($_POST['comment_id'] ?? 0);
        if (!$commentId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'missing_id']);
            exit;
        }

        if (!can_delete_comment($pdo, $userId, $commentId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'forbidden', 'message' => 'غير مصرح لك بحذف هذا التعليق.']);
            exit;
        }

        try {
            $del = $pdo->prepare("DELETE FROM task_comments WHERE id = ?");
            $del->execute([$commentId]);

            echo json_encode(['success' => true]);
            exit;
        } catch (PDOException $e) {
            error_log("Delete comment error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'db_error']);
            exit;
        }
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_action']);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
exit;
