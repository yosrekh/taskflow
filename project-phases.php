<?php
require_once __DIR__ . '/includes/auth.php';
require_login_json();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/includes/db.php';
global $pdo;

$userId = (int)($_SESSION['user_id'] ?? 0);
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Helper to extract JSON or POST payload
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

// 1. List Phases (GET or POST)
if ($action === 'list') {
    $projectId = (int)($_GET['project_id'] ?? $input['project_id'] ?? 0);
    if ($projectId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_project']);
        exit;
    }

    if (!can_view_project($pdo, $userId, $projectId)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'not_found']);
        exit;
    }

    $canManage = can_manage_project($pdo, $userId, $projectId);

    // Safe upgrade check for migration 006
    $has006 = false;
    try {
        $chk = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'project_phases'");
        $has006 = ((int)$chk->fetchColumn() > 0);
    } catch (\Throwable $e) {
        $has006 = false;
    }

    if (!$has006) {
        echo json_encode(['success' => true, 'can_manage' => $canManage, 'phases' => []]);
        exit;
    }

    try {
        if ($canManage) {
            $stmt = $pdo->prepare("
                SELECT pp.*, COUNT(t.id) AS total_tasks,
                       SUM(CASE WHEN t.status = 'Completed' THEN 1 ELSE 0 END) AS completed_tasks
                FROM project_phases pp
                LEFT JOIN tasks t ON t.phase_id = pp.id
                WHERE pp.project_id = ?
                GROUP BY pp.id
                ORDER BY pp.sort_order ASC, pp.id ASC
            ");
            $stmt->execute([$projectId]);
        } else {
            $stmt = $pdo->prepare("
                SELECT pp.*, COUNT(t.id) AS total_tasks,
                       SUM(CASE WHEN t.status = 'Completed' THEN 1 ELSE 0 END) AS completed_tasks
                FROM project_phases pp
                JOIN tasks t ON t.phase_id = pp.id AND t.assigned_to = ?
                WHERE pp.project_id = ?
                GROUP BY pp.id
                HAVING total_tasks > 0
                ORDER BY pp.sort_order ASC, pp.id ASC
            ");
            $stmt->execute([$userId, $projectId]);
        }
        $phases = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'can_manage' => $canManage, 'phases' => $phases]);
    } catch (PDOException $e) {
        error_log("List phases error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'db_error']);
    }
    exit;
}

// All write actions require POST and CSRF verification
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

// 2. Add Phase
if ($action === 'add') {
    $projectId = (int)($input['project_id'] ?? 0);
    $title = trim((string)($input['title'] ?? ''));

    if ($projectId <= 0 || $title === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_input', 'message' => 'اسم المرحلة مطلوب']);
        exit;
    }

    if (mb_strlen($title) > 100) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'title_too_long', 'message' => 'اسم المرحلة لا يتجاوز 100 حرف']);
        exit;
    }

    if (!can_manage_project($pdo, $userId, $projectId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'forbidden', 'message' => 'غير مصرح لك بإدارة مراحل هذا المشروع']);
        exit;
    }

    try {
        $sortStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM project_phases WHERE project_id = ?");
        $sortStmt->execute([$projectId]);
        $nextOrder = (int)$sortStmt->fetchColumn();

        $insStmt = $pdo->prepare("INSERT INTO project_phases (project_id, title, sort_order) VALUES (?, ?, ?)");
        $insStmt->execute([$projectId, $title, $nextOrder]);
        $newId = (int)$pdo->lastInsertId();

        echo json_encode([
            'success' => true,
            'phase' => [
                'id' => $newId,
                'project_id' => $projectId,
                'title' => $title,
                'sort_order' => $nextOrder,
                'total_tasks' => 0,
                'completed_tasks' => 0
            ]
        ]);
    } catch (PDOException $e) {
        error_log("Add phase error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'db_error']);
    }
    exit;
}

// 3. Rename Phase
if ($action === 'rename') {
    $phaseId = (int)($input['phase_id'] ?? 0);
    $title = trim((string)($input['title'] ?? ''));

    if ($phaseId <= 0 || $title === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_input', 'message' => 'اسم المرحلة مطلوب']);
        exit;
    }

    if (mb_strlen($title) > 100) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'title_too_long', 'message' => 'اسم المرحلة لا يتجاوز 100 حرف']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT project_id FROM project_phases WHERE id = ?");
        $stmt->execute([$phaseId]);
        $projectId = $stmt->fetchColumn();

        if ($projectId === false) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'not_found']);
            exit;
        }

        if (!can_manage_project($pdo, $userId, (int)$projectId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'forbidden']);
            exit;
        }

        $upStmt = $pdo->prepare("UPDATE project_phases SET title = ? WHERE id = ?");
        $upStmt->execute([$title, $phaseId]);

        echo json_encode(['success' => true, 'id' => $phaseId, 'title' => $title]);
    } catch (PDOException $e) {
        error_log("Rename phase error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'db_error']);
    }
    exit;
}

// 4. Reorder Phases
if ($action === 'reorder') {
    $projectId = (int)($input['project_id'] ?? 0);
    $phaseIds = $input['phase_ids'] ?? [];

    if ($projectId <= 0 || !is_array($phaseIds)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_input']);
        exit;
    }

    if (!can_manage_project($pdo, $userId, $projectId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'forbidden']);
        exit;
    }

    try {
        $upStmt = $pdo->prepare("UPDATE project_phases SET sort_order = ? WHERE id = ? AND project_id = ?");
        foreach ($phaseIds as $order => $pid) {
            $upStmt->execute([(int)$order, (int)$pid, $projectId]);
        }
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        error_log("Reorder phases error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'db_error']);
    }
    exit;
}

// 5. Delete Phase
if ($action === 'delete') {
    $phaseId = (int)($input['phase_id'] ?? 0);
    if ($phaseId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_id']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT project_id, title FROM project_phases WHERE id = ?");
        $stmt->execute([$phaseId]);
        $phase = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$phase) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'not_found']);
            exit;
        }

        if (!can_manage_project($pdo, $userId, (int)$phase['project_id'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'forbidden']);
            exit;
        }

        // Count affected tasks that will be moved to unphased (NULL)
        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE phase_id = ?");
        $cntStmt->execute([$phaseId]);
        $unphasedCount = (int)$cntStmt->fetchColumn();

        // Delete phase (FK ON DELETE SET NULL automatically sets phase_id to NULL on tasks)
        $delStmt = $pdo->prepare("DELETE FROM project_phases WHERE id = ?");
        $delStmt->execute([$phaseId]);

        echo json_encode([
            'success' => true,
            'deleted_id' => $phaseId,
            'unphased_tasks_count' => $unphasedCount
        ]);
    } catch (PDOException $e) {
        error_log("Delete phase error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'db_error']);
    }
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'unknown_action']);
