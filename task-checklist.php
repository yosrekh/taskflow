<?php
require_once __DIR__ . '/includes/auth.php';
require_login_json();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/includes/db.php';
global $pdo;

$userId = (int)($_SESSION['user_id'] ?? 0);
$action = $_GET['action'] ?? $_POST['action'] ?? '';

function get_input_data(): array {
    $data = $_POST;
    $raw = file_get_contents('php://input');
    if ($raw) {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $data = array_merge($data, $json);
        }
    }
    return $data;
}

$input = get_input_data();
if (empty($action) && isset($input['action'])) {
    $action = $input['action'];
}

// 1. List checklist items for a task (GET or POST)
if ($action === 'list') {
    $taskId = (int)($_GET['task_id'] ?? $input['task_id'] ?? 0);
    if ($taskId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_task']);
        exit;
    }

    if (!can_view_task($pdo, $userId, $taskId)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'not_found']);
        exit;
    }

    $canEdit = can_edit_task_checklist($pdo, $userId, $taskId);

    try {
        $stmt = $pdo->prepare("SELECT * FROM task_checklist_items WHERE task_id = ? ORDER BY sort_order ASC, id ASC");
        $stmt->execute([$taskId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total = count($items);
        $done = 0;
        foreach ($items as $item) {
            if (!empty($item['is_done'])) $done++;
        }

        echo json_encode([
            'success' => true,
            'can_edit' => $canEdit,
            'items' => $items,
            'total' => $total,
            'done' => $done
        ]);
    } catch (PDOException $e) {
        error_log("List checklist error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'db_error']);
    }
    exit;
}

// Write actions require POST and CSRF token
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

$csrfToken = get_csrf_token_from_request();
if (!verify_csrf($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'invalid_csrf']);
    exit;
}

// 2. Add Checklist Item
if ($action === 'add') {
    $taskId = (int)($input['task_id'] ?? 0);
    $title = trim((string)($input['title'] ?? ''));

    if ($taskId <= 0 || $title === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_input', 'message' => 'نص المهمة الفرعية مطلوب']);
        exit;
    }

    if (mb_strlen($title) > 200) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'title_too_long', 'message' => 'نص المهمة الفرعية لا يتجاوز 200 حرف']);
        exit;
    }

    if (!can_view_task($pdo, $userId, $taskId)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'not_found']);
        exit;
    }

    if (!can_edit_task_checklist($pdo, $userId, $taskId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'forbidden']);
        exit;
    }

    try {
        // Enforce max 50 items per task
        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM task_checklist_items WHERE task_id = ?");
        $cntStmt->execute([$taskId]);
        $currentCount = (int)$cntStmt->fetchColumn();

        if ($currentCount >= 50) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'max_items_exceeded', 'message' => 'لا يمكن إضافة أكثر من 50 مهمة فرعية']);
            exit;
        }

        $sortStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM task_checklist_items WHERE task_id = ?");
        $sortStmt->execute([$taskId]);
        $nextOrder = (int)$sortStmt->fetchColumn();

        $insStmt = $pdo->prepare("INSERT INTO task_checklist_items (task_id, title, sort_order) VALUES (?, ?, ?)");
        $insStmt->execute([$taskId, $title, $nextOrder]);
        $newId = (int)$pdo->lastInsertId();

        $total = $currentCount + 1;
        $doneStmt = $pdo->prepare("SELECT COUNT(*) FROM task_checklist_items WHERE task_id = ? AND is_done = 1");
        $doneStmt->execute([$taskId]);
        $done = (int)$doneStmt->fetchColumn();

        echo json_encode([
            'success' => true,
            'item' => [
                'id' => $newId,
                'task_id' => $taskId,
                'title' => $title,
                'is_done' => 0,
                'sort_order' => $nextOrder
            ],
            'total' => $total,
            'done' => $done
        ]);
    } catch (PDOException $e) {
        error_log("Add checklist item error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'db_error']);
    }
    exit;
}

// 3. Toggle Item Done/Undone
if ($action === 'toggle') {
    $itemId = (int)($input['item_id'] ?? 0);
    if ($itemId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_id']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM task_checklist_items WHERE id = ?");
        $stmt->execute([$itemId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'not_found']);
            exit;
        }

        $taskId = (int)$item['task_id'];
        if (!can_view_task($pdo, $userId, $taskId)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'not_found']);
            exit;
        }

        if (!can_edit_task_checklist($pdo, $userId, $taskId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'forbidden']);
            exit;
        }

        $newDone = $item['is_done'] ? 0 : 1;
        $doneAt = $newDone ? date('Y-m-d H:i:s') : null;

        $upStmt = $pdo->prepare("UPDATE task_checklist_items SET is_done = ?, done_at = ? WHERE id = ?");
        $upStmt->execute([$newDone, $doneAt, $itemId]);

        // Recalculate totals
        $cStmt = $pdo->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN is_done = 1 THEN 1 ELSE 0 END) AS done FROM task_checklist_items WHERE task_id = ?");
        $cStmt->execute([$taskId]);
        $counts = $cStmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'item_id' => $itemId,
            'is_done' => $newDone,
            'total' => (int)($counts['total'] ?? 0),
            'done' => (int)($counts['done'] ?? 0)
        ]);
    } catch (PDOException $e) {
        error_log("Toggle checklist error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'db_error']);
    }
    exit;
}

// 4. Edit Item Title
if ($action === 'edit') {
    $itemId = (int)($input['item_id'] ?? 0);
    $title = trim((string)($input['title'] ?? ''));

    if ($itemId <= 0 || $title === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_input', 'message' => 'نص المهمة الفرعية مطلوب']);
        exit;
    }

    if (mb_strlen($title) > 200) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'title_too_long', 'message' => 'نص المهمة الفرعية لا يتجاوز 200 حرف']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT task_id FROM task_checklist_items WHERE id = ?");
        $stmt->execute([$itemId]);
        $taskId = $stmt->fetchColumn();

        if ($taskId === false) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'not_found']);
            exit;
        }

        $taskId = (int)$taskId;
        if (!can_view_task($pdo, $userId, $taskId)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'not_found']);
            exit;
        }

        if (!can_edit_task_checklist($pdo, $userId, $taskId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'forbidden']);
            exit;
        }

        $upStmt = $pdo->prepare("UPDATE task_checklist_items SET title = ? WHERE id = ?");
        $upStmt->execute([$title, $itemId]);

        echo json_encode(['success' => true, 'item_id' => $itemId, 'title' => $title]);
    } catch (PDOException $e) {
        error_log("Edit checklist item error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'db_error']);
    }
    exit;
}

// 5. Delete Item
if ($action === 'delete') {
    $itemId = (int)($input['item_id'] ?? 0);
    if ($itemId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_id']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT task_id FROM task_checklist_items WHERE id = ?");
        $stmt->execute([$itemId]);
        $taskId = $stmt->fetchColumn();

        if ($taskId === false) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'not_found']);
            exit;
        }

        $taskId = (int)$taskId;
        if (!can_view_task($pdo, $userId, $taskId)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'not_found']);
            exit;
        }

        if (!can_edit_task_checklist($pdo, $userId, $taskId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'forbidden']);
            exit;
        }

        $delStmt = $pdo->prepare("DELETE FROM task_checklist_items WHERE id = ?");
        $delStmt->execute([$itemId]);

        // Recalculate totals
        $cStmt = $pdo->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN is_done = 1 THEN 1 ELSE 0 END) AS done FROM task_checklist_items WHERE task_id = ?");
        $cStmt->execute([$taskId]);
        $counts = $cStmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'deleted_id' => $itemId,
            'total' => (int)($counts['total'] ?? 0),
            'done' => (int)($counts['done'] ?? 0)
        ]);
    } catch (PDOException $e) {
        error_log("Delete checklist item error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'db_error']);
    }
    exit;
}

// 6. Reorder Items
if ($action === 'reorder') {
    $taskId = (int)($input['task_id'] ?? 0);
    $itemIds = $input['item_ids'] ?? [];

    if ($taskId <= 0 || !is_array($itemIds)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_input']);
        exit;
    }

    if (!can_view_task($pdo, $userId, $taskId)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'not_found']);
        exit;
    }

    if (!can_edit_task_checklist($pdo, $userId, $taskId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'forbidden']);
        exit;
    }

    try {
        $upStmt = $pdo->prepare("UPDATE task_checklist_items SET sort_order = ? WHERE id = ? AND task_id = ?");
        foreach ($itemIds as $order => $iid) {
            $upStmt->execute([(int)$order, (int)$iid, $taskId]);
        }
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        error_log("Reorder checklist items error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'db_error']);
    }
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'unknown_action']);
