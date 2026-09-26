<?php
require_once __DIR__ . '/../includes/auth.php';
require_login('../');
require_once __DIR__ . '/../includes/db.php';

$base = '../';
$project_id = $_GET['project_id'] ?? null;
if (!$project_id) {
    render_error(400, "رقم المشروع غير موجود.");
}

$user_id = (int)$_SESSION['user_id'];

// Load project data at the TOP
$stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
$stmt->execute([$project_id]);
$project = $stmt->fetch();

if (!$project || !can_view_project($pdo, $user_id, $project_id)) {
    render_error(404, "المشروع غير موجود.");
}

$project_owner_id = (int)$project['user_id'];
$is_project_owner = can_manage_project($pdo, $user_id, $project_id);

// Safe upgrade check for migration 006
$has006 = false;
try {
    $chk = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'task_checklist_items'");
    $has006 = ((int)$chk->fetchColumn() > 0);
} catch (\Throwable $e) {
    $has006 = false;
}

// Fetch project phases if schema has migration 006
$project_phases = [];
if ($has006) {
    try {
        $phStmt = $pdo->prepare("SELECT * FROM project_phases WHERE project_id = ? ORDER BY sort_order ASC, id ASC");
        $phStmt->execute([$project_id]);
        $project_phases = $phStmt->fetchAll();
    } catch (\Throwable $e) {
        $project_phases = [];
    }
}

// Handle Task Actions (POST)
$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = "رمز التحقق غير صالح. يرجى إعادة المحاولة.";
    } elseif (isset($_POST['add_task'])) {
        if (!$is_project_owner) {
            render_error(403, "غير مصرح لك بإضافة مهام في هذا المشروع.");
        }
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $priority = $_POST['priority'] ?? 'Medium';
        $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
        $assigned_to = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;
        $phase_id = null;
        if ($has006 && !empty($_POST['phase_id'])) {
            $candPhaseId = (int)$_POST['phase_id'];
            $vStmt = $pdo->prepare("SELECT 1 FROM project_phases WHERE id = ? AND project_id = ?");
            $vStmt->execute([$candPhaseId, $project_id]);
            if ($vStmt->fetchColumn()) {
                $phase_id = $candPhaseId;
            } else {
                $error = "المرحلة المحددة غير صالحة لهذا المشروع.";
            }
        }

        if (empty($error)) {
            try {
                if ($has006) {
                    $stmt = $pdo->prepare("INSERT INTO tasks (project_id, title, description, priority, due_date, assigned_to, status, phase_id) VALUES (?, ?, ?, ?, ?, ?, 'Pending', ?)");
                    $stmt->execute([$project_id, $title, $description, $priority, $due_date, $assigned_to, $phase_id]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO tasks (project_id, title, description, priority, due_date, assigned_to, status) VALUES (?, ?, ?, ?, ?, ?, 'Pending')");
                    $stmt->execute([$project_id, $title, $description, $priority, $due_date, $assigned_to]);
                }
                $success = "تمت إضافة المهمة بنجاح.";
                
                // Notify owner and assignee
                $actor_id = $user_id;
                $actor_stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
                $actor_stmt->execute([$actor_id]);
                $actor_name = $actor_stmt->fetchColumn();
                $project_title = $project['title'];
                $action_time = date('Y-m-d H:i');
                $msg = "[{$action_time}] {$actor_name} أضاف مهمة جديدة '{$title}' في مشروع '{$project_title}'";
                foreach (array_unique(array_filter([$project_owner_id, $assigned_to])) as $uid) {
                    if ($uid != $actor_id) {
                        $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)")->execute([$uid, $msg]);
                    }
                }
            } catch (PDOException $e) {
                error_log("Add task error: " . $e->getMessage());
                $error = "فشل في إضافة المهمة.";
            }
        }
    } elseif (isset($_POST['edit_task'])) {
        if (!$is_project_owner) {
            render_error(403, "غير مصرح لك بتعديل هذه المهمة.");
        }
        $task_id = $_POST['task_id'] ?? null;
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $priority = $_POST['priority'] ?? 'Medium';
        $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
        $assigned_to = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;
        $phase_id = null;
        if ($has006 && !empty($_POST['phase_id'])) {
            $candPhaseId = (int)$_POST['phase_id'];
            $vStmt = $pdo->prepare("SELECT 1 FROM project_phases WHERE id = ? AND project_id = ?");
            $vStmt->execute([$candPhaseId, $project_id]);
            if ($vStmt->fetchColumn()) {
                $phase_id = $candPhaseId;
            } else {
                $error = "المرحلة المحددة غير صالحة لهذا المشروع.";
            }
        }

        if (empty($error)) {
            try {
                $old_stmt = $pdo->prepare("SELECT assigned_to, title FROM tasks WHERE id = ? AND project_id = ?");
                $old_stmt->execute([$task_id, $project_id]);
                $old_task = $old_stmt->fetch();
                $old_assigned_to = $old_task ? $old_task['assigned_to'] : null;

                if ($has006) {
                    $stmt = $pdo->prepare("UPDATE tasks SET title=?, description=?, priority=?, due_date=?, assigned_to=?, phase_id=? WHERE id=? AND project_id=?");
                    $stmt->execute([$title, $description, $priority, $due_date, $assigned_to, $phase_id, $task_id, $project_id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE tasks SET title=?, description=?, priority=?, due_date=?, assigned_to=? WHERE id=? AND project_id=?");
                    $stmt->execute([$title, $description, $priority, $due_date, $assigned_to, $task_id, $project_id]);
                }
                $success = "تم تحديث المهمة بنجاح.";
                
                // Notify owner and assignee
                $actor_id = $user_id;
                $actor_stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
                $actor_stmt->execute([$actor_id]);
                $actor_name = $actor_stmt->fetchColumn();
                $project_title = $project['title'];
                $action_time = date('Y-m-d H:i');
                $msg = "[{$action_time}] {$actor_name} عدّل مهمة '{$title}' في مشروع '{$project_title}'";
                foreach (array_unique(array_filter([$project_owner_id, $assigned_to])) as $uid) {
                    if ($uid != $actor_id) {
                        $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)")->execute([$uid, $msg]);
                    }
                }

                // If assignee changed, notify the previous assignee
                if ($old_assigned_to && $old_assigned_to != $assigned_to && $old_assigned_to != $actor_id) {
                    $unassign_msg = "[{$action_time}] {$actor_name} ألغى إسناد المهمة '{$title}' لك في مشروع '{$project_title}'";
                    $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)")->execute([$old_assigned_to, $unassign_msg]);
                }
            } catch (PDOException $e) {
                error_log("Edit task error: " . $e->getMessage());
                $error = "فشل في تحديث المهمة.";
            }
        }
    } elseif (isset($_POST['delete_task'])) {
        if (!$is_project_owner) {
            render_error(403, "غير مصرح لك بحذف هذه المهمة.");
        }
        $task_id = $_POST['task_id'] ?? null;
        try {
            $task_stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ? AND project_id = ?");
            $task_stmt->execute([$task_id, $project_id]);
            $task = $task_stmt->fetch();
            if ($task) {
                $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ? AND project_id = ?");
                $stmt->execute([$task_id, $project_id]);
                $success = "تم حذف المهمة بنجاح.";
                
                $actor_id = $user_id;
                $actor_stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
                $actor_stmt->execute([$actor_id]);
                $actor_name = $actor_stmt->fetchColumn();
                $project_title = $project['title'];
                $action_time = date('Y-m-d H:i');
                $msg = "[{$action_time}] {$actor_name} حذف المهمة '{$task['title']}' في مشروع '{$project_title}'";
                foreach (array_unique(array_filter([$project_owner_id, $task['assigned_to']])) as $uid) {
                    if ($uid != $actor_id) {
                        $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)")->execute([$uid, $msg]);
                    }
                }
            }
        } catch (PDOException $e) {
            error_log("Delete task error: " . $e->getMessage());
            $error = "فشل في حذف المهمة.";
        }
    }
}

// Get all tasks in this project
if ($has006) {
    if ($is_project_owner) {
        $stmt = $pdo->prepare("
            SELECT t.*, u.name AS assignee_name,
                   pp.title AS phase_title,
                   (SELECT COUNT(*) FROM task_comments tc WHERE tc.task_id = t.id) AS comments_count,
                   (SELECT COUNT(*) FROM task_checklist_items ci WHERE ci.task_id = t.id) AS checklist_total,
                   (SELECT COUNT(*) FROM task_checklist_items ci WHERE ci.task_id = t.id AND ci.is_done = 1) AS checklist_done
            FROM tasks t
            LEFT JOIN users u ON t.assigned_to = u.id
            LEFT JOIN project_phases pp ON t.phase_id = pp.id
            WHERE t.project_id = ?
            ORDER BY t.created_at DESC
        ");
        $stmt->execute([$project_id]);
        $tasks = $stmt->fetchAll();
    } else {
        $stmt = $pdo->prepare("
            SELECT t.*, u.name AS assignee_name,
                   pp.title AS phase_title,
                   (SELECT COUNT(*) FROM task_comments tc WHERE tc.task_id = t.id) AS comments_count,
                   (SELECT COUNT(*) FROM task_checklist_items ci WHERE ci.task_id = t.id) AS checklist_total,
                   (SELECT COUNT(*) FROM task_checklist_items ci WHERE ci.task_id = t.id AND ci.is_done = 1) AS checklist_done
            FROM tasks t
            LEFT JOIN users u ON t.assigned_to = u.id
            LEFT JOIN project_phases pp ON t.phase_id = pp.id
            WHERE t.project_id = ? AND t.assigned_to = ?
            ORDER BY t.created_at DESC
        ");
        $stmt->execute([$project_id, $user_id]);
        $tasks = $stmt->fetchAll();
    }
} else {
    if ($is_project_owner) {
        $stmt = $pdo->prepare("
            SELECT t.*, u.name AS assignee_name,
                   (SELECT COUNT(*) FROM task_comments tc WHERE tc.task_id = t.id) AS comments_count
            FROM tasks t
            LEFT JOIN users u ON t.assigned_to = u.id
            WHERE t.project_id = ?
            ORDER BY t.created_at DESC
        ");
        $stmt->execute([$project_id]);
        $tasks = $stmt->fetchAll();
    } else {
        $stmt = $pdo->prepare("
            SELECT t.*, u.name AS assignee_name,
                   (SELECT COUNT(*) FROM task_comments tc WHERE tc.task_id = t.id) AS comments_count
            FROM tasks t
            LEFT JOIN users u ON t.assigned_to = u.id
            WHERE t.project_id = ? AND t.assigned_to = ?
            ORDER BY t.created_at DESC
        ");
        $stmt->execute([$project_id, $user_id]);
        $tasks = $stmt->fetchAll();
    }
}

// Compute phase counts and visible phases for chip row
$totalVisibleTasks = count($tasks);
$unphasedCount = 0;
$phaseCounts = [];
foreach ($project_phases as $ph) {
    $phaseCounts[$ph['id']] = ['total' => 0, 'completed' => 0];
}
foreach ($tasks as $t) {
    $pid = $t['phase_id'] ?? null;
    if ($pid && isset($phaseCounts[$pid])) {
        $phaseCounts[$pid]['total']++;
        if ($t['status'] === 'Completed') {
            $phaseCounts[$pid]['completed']++;
        }
    } else {
        $unphasedCount++;
    }
}

$visiblePhases = [];
foreach ($project_phases as $ph) {
    $pInfo = $phaseCounts[$ph['id']];
    if ($is_project_owner || $pInfo['total'] > 0) {
        $pct = $pInfo['total'] > 0 ? (int)round(($pInfo['completed'] / $pInfo['total']) * 100) : 0;
        $visiblePhases[] = [
            'id' => (int)$ph['id'],
            'title' => $ph['title'],
            'total' => $pInfo['total'],
            'completed' => $pInfo['completed'],
            'pct' => $pct
        ];
    }
}

// Get all active users for assignment
$users_stmt = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name ASC");
$users = $users_stmt->fetchAll();

// For edit form
$edit_task = null;
if (isset($_GET['edit_task_id'])) {
    if (!$is_project_owner) {
        render_error(403, "غير مصرح لك بتعديل هذه المهمة.");
    }
    $edit_id = (int)$_GET['edit_task_id'];
    $stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ? AND project_id = ?");
    $stmt->execute([$edit_id, $project_id]);
    $edit_task = $stmt->fetch();
}

function render_kanban_card($task, $is_project_owner, $project_id, $pdo, $user_id) {
    $isOverdue = (!empty($task['due_date']) && $task['status'] !== 'Completed' && strtotime($task['due_date']) < strtotime(date('Y-m-d')));
    $assigneeName = $task['assignee_name'] ?? '';
    $assigneeInitials = get_user_initials($assigneeName);
    $commentsCount = (int)($task['comments_count'] ?? 0);
    $phaseId = !empty($task['phase_id']) ? (int)$task['phase_id'] : 'none';
    $phaseTitle = $task['phase_title'] ?? '';
    $checklistTotal = (int)($task['checklist_total'] ?? 0);
    $checklistDone = (int)($task['checklist_done'] ?? 0);
    
    $priorityClass = 'badge-medium';
    $priorityLabel = 'متوسطة';
    if ($task['priority'] === 'High') {
        $priorityClass = 'badge-high';
        $priorityLabel = 'عالية';
    } elseif ($task['priority'] === 'Low') {
        $priorityClass = 'badge-low';
        $priorityLabel = 'منخفضة';
    }
    ?>
    <div class="kanban-card"
         data-task-id="<?= (int)$task['id'] ?>"
         data-title="<?= htmlspecialchars($task['title'], ENT_QUOTES) ?>"
         data-desc="<?= htmlspecialchars($task['description'] ?? '', ENT_QUOTES) ?>"
         data-priority="<?= htmlspecialchars($task['priority'], ENT_QUOTES) ?>"
         data-status="<?= htmlspecialchars($task['status'], ENT_QUOTES) ?>"
         data-due-date="<?= htmlspecialchars($task['due_date'] ?? '', ENT_QUOTES) ?>"
         data-assignee="<?= htmlspecialchars($assigneeName, ENT_QUOTES) ?>"
         data-comments-count="<?= $commentsCount ?>"
         data-phase-id="<?= $phaseId ?>"
         data-phase-title="<?= htmlspecialchars($phaseTitle, ENT_QUOTES) ?>"
         data-checklist-total="<?= $checklistTotal ?>"
         data-checklist-done="<?= $checklistDone ?>"
         tabindex="0"
         role="button"
         aria-haspopup="dialog"
         aria-label="عرض تفاصيل المهمة: <?= htmlspecialchars($task['title']) ?>">
        <div class="kanban-card-title"><?= htmlspecialchars($task['title']) ?></div>
        <?php if (!empty($task['description'])): ?>
            <div class="kanban-card-desc"><?= htmlspecialchars($task['description']) ?></div>
        <?php endif; ?>

        <div class="kanban-card-meta">
            <span class="badge <?= $priorityClass ?>"><?= $priorityLabel ?></span>

            <?php if (!empty($phaseTitle)): ?>
                <span class="kanban-phase-tag" title="المرحلة: <?= htmlspecialchars($phaseTitle) ?>">
                    <?= htmlspecialchars($phaseTitle) ?>
                </span>
            <?php endif; ?>

            <?php if (!empty($task['due_date'])): ?>
                <span class="kanban-due-date <?= $isOverdue ? 'is-overdue' : '' ?>" title="<?= $isOverdue ? 'متأخرة عن موعدها' : 'تاريخ الاستحقاق' ?>">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                    <bdi dir="ltr" class="tabular-nums"><?= htmlspecialchars($task['due_date']) ?></bdi>
                    <?= $isOverdue ? ' (متأخرة)' : '' ?>
                </span>
            <?php endif; ?>

            <?php if ($checklistTotal > 0): ?>
                <span class="kanban-checklist-badge <?= ($checklistTotal === $checklistDone) ? 'is-complete' : '' ?>" title="<?= $checklistDone ?> من <?= $checklistTotal ?> مهام فرعية مكتملة">
                    <span class="checklist-icon">✓</span>
                    <bdi dir="ltr" class="tabular-nums"><?= $checklistDone ?>/<?= $checklistTotal ?></bdi>
                </span>
            <?php endif; ?>

            <?php if ($commentsCount > 0): ?>
                <span class="kanban-comment-badge" title="<?= $commentsCount ?> تعليق">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                    <span class="comment-count-val"><?= $commentsCount ?></span>
                </span>
            <?php endif; ?>
        </div>

        <div class="form-group kanban-card-status-wrap">
            <select class="form-select task-status-select" data-task-id="<?= (int)$task['id'] ?>" aria-label="تغيير حالة المهمة">
                <option value="Pending" <?= $task['status'] === 'Pending' ? 'selected' : '' ?>>للتنفيذ</option>
                <option value="In Progress" <?= $task['status'] === 'In Progress' ? 'selected' : '' ?>>قيد التنفيذ</option>
                <option value="Completed" <?= $task['status'] === 'Completed' ? 'selected' : '' ?>>مكتملة</option>
            </select>
        </div>

        <div class="kanban-card-footer">
            <div class="kanban-card-assignee">
                <?php if ($assigneeName): ?>
                    <span class="avatar avatar-sm" title="<?= htmlspecialchars($assigneeName) ?>"><?= htmlspecialchars($assigneeInitials) ?></span>
                    <span class="assignee-name-label"><?= htmlspecialchars($assigneeName) ?></span>
                <?php else: ?>
                    <span class="assignee-unassigned-label">غير مسندة</span>
                <?php endif; ?>
            </div>

            <?php if ($is_project_owner): ?>
                <div class="kanban-card-actions">
                    <a href="view-tasks.php?project_id=<?= (int)$project_id ?>&edit_task_id=<?= (int)$task['id'] ?>" class="btn-icon" aria-label="تعديل المهمة '<?= htmlspecialchars($task['title']) ?>'" title="تعديل">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                    </a>
                    <button type="button" class="btn-icon btn-icon-danger open-delete-task-modal-btn" data-task-id="<?= (int)$task['id'] ?>" data-task-title="<?= htmlspecialchars($task['title'], ENT_QUOTES) ?>" aria-label="حذف المهمة '<?= htmlspecialchars($task['title']) ?>'" title="حذف">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <?php
    $page_title = 'مهام المشروع: ' . $project['title'];
    include __DIR__ . '/../includes/header-meta.php';
    ?>
</head>
<body>
    <?php
    include __DIR__ . '/../includes/nav.php';
    render_nav($base);
    ?>

    <main>
        <!-- Header & Breadcrumb -->
        <header class="page-header">
            <div class="page-title-wrap">
                <a href="../dashboard.php" class="btn-ghost btn-sm btn-back-link">
                    ← العودة إلى لوحة التحكم
                </a>
                <h1 class="page-title"><?= htmlspecialchars($project['title']) ?></h1>
                <p class="page-subtitle"><?= htmlspecialchars($project['description'] ?? 'لوحة متابعة مهام المشروع') ?></p>
            </div>
            <div class="page-actions">
                <?php if ($is_project_owner): ?>
                    <?php if (count($project_phases) > 0): ?>
                        <button type="button" class="btn btn-secondary btn-sm" id="btnManagePhases" onclick="openPhasesModal()">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                            إدارة المراحل
                        </button>
                    <?php else: ?>
                        <button type="button" class="btn btn-secondary btn-sm" id="btnManagePhases" onclick="openPhasesModal()">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                            إضافة مرحلة
                        </button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-primary" onclick="openModal('taskModal')">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                        مهمة جديدة
                    </button>
                <?php endif; ?>
            </div>
        </header>

        <?php if ($error): ?>
            <div class="alert alert-error" role="alert">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php elseif ($success): ?>
            <div class="alert alert-success" role="alert">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
                <span><?= htmlspecialchars($success) ?></span>
            </div>
        <?php endif; ?>

        <!-- Phase Filter Chips (shown only if project has phases) -->
        <?php if (count($project_phases) > 0): ?>
            <nav class="phase-chips-bar" aria-label="تصفية حسب المرحلة">
                <div class="phase-chips-scroll">
                    <button type="button" class="phase-chip is-active" data-phase="all">
                        <span class="chip-title">الكل</span>
                        <span class="chip-count">(<?= $totalVisibleTasks ?>)</span>
                    </button>
                    <?php foreach ($visiblePhases as $vPh): ?>
                        <button type="button" class="phase-chip" data-phase="<?= (int)$vPh['id'] ?>">
                            <span class="chip-title"><?= htmlspecialchars($vPh['title']) ?></span>
                            <span class="chip-count">(<?= (int)$vPh['total'] ?>)</span>
                            <span class="chip-pct tabular-nums"><?= (int)$vPh['pct'] ?>%</span>
                        </button>
                    <?php endforeach; ?>
                    <?php if ($unphasedCount > 0): ?>
                        <button type="button" class="phase-chip" data-phase="none">
                            <span class="chip-title">بدون مرحلة</span>
                            <span class="chip-count">(<?= $unphasedCount ?>)</span>
                        </button>
                    <?php endif; ?>
                </div>
            </nav>
        <?php endif; ?>

        <!-- Kanban Board: Arabic Columns (للتنفيذ, قيد التنفيذ, مكتملة) -->
        <?php
        $todoTasks = array_values(array_filter($tasks, fn($t) => $t['status'] === 'Pending'));
        $progressTasks = array_values(array_filter($tasks, fn($t) => $t['status'] === 'In Progress'));
        $doneTasks = array_values(array_filter($tasks, fn($t) => $t['status'] === 'Completed'));
        ?>

        <div class="kanban-board-container" data-filter-phase="all">
            <div class="kanban-board">
                <!-- Column: Pending -->
                <section class="kanban-column col-todo" data-status="Pending">
                    <header class="kanban-column-header">
                        <div class="kanban-column-title">
                            <span class="dot-todo">●</span>
                            <span>للتنفيذ</span>
                        </div>
                        <span class="kanban-count-badge count-todo"><?= count($todoTasks) ?></span>
                    </header>
                    <div class="kanban-cards-list cards-todo">
                        <?php if (empty($todoTasks)): ?>
                            <div class="kanban-empty-placeholder">لا توجد مهام للتنفيذ</div>
                        <?php else: ?>
                            <?php foreach ($todoTasks as $t) { render_kanban_card($t, $is_project_owner, $project_id, $pdo, $user_id); } ?>
                        <?php endif; ?>
                    </div>
                </section>

                <!-- Column: In Progress -->
                <section class="kanban-column col-progress" data-status="In Progress">
                    <header class="kanban-column-header">
                        <div class="kanban-column-title">
                            <span class="dot-progress">●</span>
                            <span>قيد التنفيذ</span>
                        </div>
                        <span class="kanban-count-badge count-progress"><?= count($progressTasks) ?></span>
                    </header>
                    <div class="kanban-cards-list cards-progress">
                        <?php if (empty($progressTasks)): ?>
                            <div class="kanban-empty-placeholder">لا توجد مهام قيد التنفيذ</div>
                        <?php else: ?>
                            <?php foreach ($progressTasks as $t) { render_kanban_card($t, $is_project_owner, $project_id, $pdo, $user_id); } ?>
                        <?php endif; ?>
                    </div>
                </section>

                <!-- Column: Completed -->
                <section class="kanban-column col-done" data-status="Completed">
                    <header class="kanban-column-header">
                        <div class="kanban-column-title">
                            <span class="dot-done">●</span>
                            <span>مكتملة</span>
                        </div>
                        <span class="kanban-count-badge count-done"><?= count($doneTasks) ?></span>
                    </header>
                    <div class="kanban-cards-list cards-done">
                        <?php if (empty($doneTasks)): ?>
                            <div class="kanban-empty-placeholder">لا توجد مهام مكتملة</div>
                        <?php else: ?>
                            <?php foreach ($doneTasks as $t) { render_kanban_card($t, $is_project_owner, $project_id, $pdo, $user_id); } ?>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
        </div>
    </main>

    <!-- Task Form Modal (Add / Edit) -->
    <div class="modal <?= $edit_task ? 'is-open' : '' ?>" id="taskModal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        <div class="modal-backdrop" data-dismiss="modal"></div>
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title" id="modalTitle"><?= $edit_task ? 'تعديل المهمة' : 'إضافة مهمة جديدة' ?></h2>
                    <button type="button" class="modal-close" data-dismiss="modal" aria-label="إغلاق">&times;</button>
                </div>
                <form method="POST" action="view-tasks.php?project_id=<?= (int)$project_id ?>">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <?php if ($edit_task): ?>
                            <input type="hidden" name="task_id" value="<?= (int)$edit_task['id'] ?>">
                        <?php endif; ?>

                        <div class="form-group">
                            <label for="task_title" class="form-label">عنوان المهمة <span class="required">*</span></label>
                            <input type="text" id="task_title" name="title" class="form-control" value="<?= htmlspecialchars($edit_task['title'] ?? '') ?>" required autofocus placeholder="مثال: إعداد وثائق المشروع">
                        </div>

                        <div class="form-group">
                            <label for="task_desc" class="form-label">الوصف</label>
                            <textarea id="task_desc" name="description" class="form-textarea" rows="3" placeholder="تفاصيل ومتطلبات المهمة..."><?= htmlspecialchars($edit_task['description'] ?? '') ?></textarea>
                        </div>

                        <div class="form-row-2col">
                            <div class="form-group">
                                <label for="task_priority" class="form-label">الأولوية <span class="required">*</span></label>
                                <select id="task_priority" name="priority" class="form-select" required>
                                    <option value="Low" <?= (isset($edit_task) && $edit_task['priority'] === 'Low') ? 'selected' : '' ?>>منخفضة</option>
                                    <option value="Medium" <?= (!isset($edit_task) || $edit_task['priority'] === 'Medium') ? 'selected' : '' ?>>متوسطة</option>
                                    <option value="High" <?= (isset($edit_task) && $edit_task['priority'] === 'High') ? 'selected' : '' ?>>عالية</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="task_due_date" class="form-label">تاريخ الاستحقاق</label>
                                <input type="date" id="task_due_date" name="due_date" class="form-control tabular-nums" dir="ltr" value="<?= htmlspecialchars($edit_task['due_date'] ?? '') ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="task_assigned_to" class="form-label">المسؤول عن المهمة</label>
                            <select id="task_assigned_to" name="assigned_to" class="form-select">
                                <option value="">-- بدون إسناد --</option>
                                <?php foreach ($users as $u): ?>
                                    <option value="<?= (int)$u['id'] ?>" <?= (isset($edit_task) && (int)$edit_task['assigned_to'] === (int)$u['id']) ? 'selected' : '' ?>><?= htmlspecialchars($u['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php if (count($project_phases) > 0): ?>
                            <div class="form-group">
                                <label for="task_phase_id" class="form-label">المرحلة</label>
                                <select id="task_phase_id" name="phase_id" class="form-select">
                                    <option value="">-- بدون مرحلة --</option>
                                    <?php foreach ($project_phases as $ph): ?>
                                        <option value="<?= (int)$ph['id'] ?>" <?= (isset($edit_task) && (int)($edit_task['phase_id'] ?? 0) === (int)$ph['id']) ? 'selected' : '' ?>><?= htmlspecialchars($ph['title']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>
                        <button type="submit" name="<?= $edit_task ? 'edit_task' : 'add_task' ?>" class="btn btn-primary">
                            <?= $edit_task ? 'حفظ التعديلات' : 'إنشاء المهمة' ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if ($is_project_owner): ?>
    <!-- Manage Phases Modal -->
    <div class="modal" id="phasesModal" role="dialog" aria-modal="true" aria-labelledby="phasesModalTitle">
        <div class="modal-backdrop" data-dismiss="modal"></div>
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title" id="phasesModalTitle">إدارة مراحل المشروع</h2>
                    <button type="button" class="modal-close" data-dismiss="modal" aria-label="إغلاق">&times;</button>
                </div>
                <div class="modal-body">
                    <!-- Add Phase Form -->
                    <form id="addPhaseForm" class="phase-add-form" autocomplete="off">
                        <div class="form-group">
                            <label for="newPhaseTitle" class="form-label">إضافة مرحلة جديدة</label>
                            <div class="checklist-add-input-wrap">
                                <input type="text" id="newPhaseTitle" class="form-control" maxlength="100" placeholder="اسم المرحلة (مثال: التخطيط، التطوير...)" required>
                                <button type="submit" class="btn btn-primary btn-sm" id="btnAddPhase">إضافة</button>
                            </div>
                        </div>
                    </form>

                    <!-- Phases List -->
                    <div class="phase-manage-list" id="phaseManageList" role="list">
                        <!-- Loaded via JS -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">إغلاق</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Confirm Delete Phase Modal -->
    <div class="modal" id="deletePhaseModal" role="dialog" aria-modal="true" aria-labelledby="deletePhaseTitle">
        <div class="modal-backdrop" data-dismiss="modal"></div>
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title" id="deletePhaseTitle">حذف المرحلة</h2>
                    <button type="button" class="modal-close" data-dismiss="modal" aria-label="إغلاق">&times;</button>
                </div>
                <div class="modal-body">
                    <p class="modal-alert-text" id="deletePhaseConfirmText">
                        هل أنت متأكد من حذف هذه المرحلة؟
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>
                    <button type="button" class="btn btn-danger" id="btnConfirmDeletePhase">تأكيد الحذف</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Client-side Status Change, Polling & Toast Integration -->
    <script>
    (function () {
        const csrfToken = '<?= csrf_token() ?>';
        const projectId = '<?= (int)$project_id ?>';
        const isProjectOwner = <?= $is_project_owner ? 'true' : 'false' ?>;
        let lastTasksJson = '';

        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function getInitials(name) {
            if (!name) return 'TF';
            const parts = name.trim().split(/\s+/).filter(Boolean);
            if (parts.length >= 2) {
                const c1 = Array.from(parts[0])[0] || '';
                const c2 = Array.from(parts[1])[0] || '';
                return (c1 + c2).toUpperCase();
            }
            if (parts.length === 1) {
                const chars = Array.from(parts[0]);
                return chars.slice(0, 2).join('').toUpperCase();
            }
            return 'TF';
        }

        // Status change listener (AJAX update + Toast)
        document.addEventListener('change', function (e) {
            if (e.target.matches('.task-status-select')) {
                const select = e.target;
                const taskId = select.dataset.taskId;
                const newStatus = select.value;

                const formData = new FormData();
                formData.append('task_id', taskId);
                formData.append('status', newStatus);
                formData.append('csrf_token', csrfToken);

                fetch('../tasks/update-status.php', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': csrfToken },
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        if (typeof showToast === 'function') {
                            showToast('تم تحديث حالة المهمة بنجاح!', 'success');
                        }
                        pollTasks(); // Re-render boards
                    } else {
                        if (typeof showToast === 'function') {
                            showToast('فشل في تحديث حالة المهمة!', 'error');
                        }
                    }
                })
                .catch(() => {
                    if (typeof showToast === 'function') {
                        showToast('فشل في الاتصال بالخادم!', 'error');
                    }
                });
            }
        });

        // Phase Filter state
        let currentActivePhase = (new URLSearchParams(window.location.search)).get('phase') || 'all';

        function applyPhaseFilter(phase) {
            currentActivePhase = phase;
            const container = document.querySelector('.kanban-board-container');
            if (container) {
                container.dataset.filterPhase = phase;
            }

            document.querySelectorAll('.phase-chip').forEach(chip => {
                if (chip.dataset.phase === String(phase)) {
                    chip.classList.add('is-active');
                } else {
                    chip.classList.remove('is-active');
                }
            });

            ['Pending', 'In Progress', 'Completed'].forEach(status => {
                const colClass = status === 'Pending' ? '.cards-todo' : (status === 'In Progress' ? '.cards-progress' : '.cards-done');
                const countClass = status === 'Pending' ? '.count-todo' : (status === 'In Progress' ? '.count-progress' : '.count-done');
                const emptyText = status === 'Pending' ? 'لا توجد مهام للتنفيذ' : (status === 'In Progress' ? 'لا توجد مهام قيد التنفيذ' : 'لا توجد مهام مكتملة');

                const col = document.querySelector(colClass);
                const countBadge = document.querySelector(countClass);
                if (!col) return;

                const cards = col.querySelectorAll('.kanban-card');
                let visibleCount = 0;
                cards.forEach(card => {
                    const cardPhase = card.dataset.phaseId || 'none';
                    let isVisible = false;
                    if (phase === 'all') {
                        isVisible = true;
                    } else if (phase === 'none') {
                        isVisible = (cardPhase === 'none' || cardPhase === '' || cardPhase === '0');
                    } else {
                        isVisible = (cardPhase === String(phase));
                    }

                    if (isVisible) {
                        card.style.display = '';
                        visibleCount++;
                    } else {
                        card.style.display = 'none';
                    }
                });

                if (countBadge) {
                    countBadge.textContent = visibleCount;
                }

                let placeholder = col.querySelector('.kanban-empty-placeholder');
                if (visibleCount === 0) {
                    if (!placeholder) {
                        placeholder = document.createElement('div');
                        placeholder.className = 'kanban-empty-placeholder';
                        placeholder.textContent = emptyText;
                        col.appendChild(placeholder);
                    }
                    placeholder.style.display = '';
                } else if (placeholder) {
                    placeholder.style.display = 'none';
                }
            });
        }

        function updatePhaseChipCounts(tasks) {
            const allChip = document.querySelector('.phase-chip[data-phase="all"] .chip-count');
            if (allChip) allChip.textContent = `(${tasks.length})`;

            const phaseMap = {};
            let unphased = 0;
            tasks.forEach(t => {
                if (t.phase_id) {
                    if (!phaseMap[t.phase_id]) phaseMap[t.phase_id] = { total: 0, done: 0 };
                    phaseMap[t.phase_id].total++;
                    if (t.status === 'Completed') phaseMap[t.phase_id].done++;
                } else {
                    unphased++;
                }
            });

            document.querySelectorAll('.phase-chip').forEach(chip => {
                const ph = chip.dataset.phase;
                if (ph === 'all') return;
                if (ph === 'none') {
                    const countEl = chip.querySelector('.chip-count');
                    if (countEl) countEl.textContent = `(${unphased})`;
                    chip.style.display = unphased > 0 ? '' : 'none';
                } else {
                    const info = phaseMap[ph] || { total: 0, done: 0 };
                    const countEl = chip.querySelector('.chip-count');
                    const pctEl = chip.querySelector('.chip-pct');
                    if (countEl) countEl.textContent = `(${info.total})`;
                    if (pctEl) {
                        const pct = info.total > 0 ? Math.round((info.done / info.total) * 100) : 0;
                        pctEl.textContent = `${pct}%`;
                    }
                }
            });
        }

        // Chip click listener (filters board + syncs URL ?phase=ID|none|all)
        document.addEventListener('click', function(e) {
            const chip = e.target.closest('.phase-chip');
            if (!chip) return;
            const phase = chip.dataset.phase;
            if (!phase) return;

            const url = new URL(window.location);
            if (phase === 'all') {
                url.searchParams.set('phase', 'all');
            } else {
                url.searchParams.set('phase', phase);
            }
            window.history.replaceState({}, '', url);
            applyPhaseFilter(phase);
        });

        // Default phase in Add Task form based on active chip
        const openTaskModalBtns = document.querySelectorAll('[onclick*="taskModal"]');
        openTaskModalBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                const phaseSelect = document.getElementById('task_phase_id');
                if (phaseSelect) {
                    if (currentActivePhase && currentActivePhase !== 'all' && currentActivePhase !== 'none') {
                        phaseSelect.value = currentActivePhase;
                    } else {
                        phaseSelect.value = '';
                    }
                }
            });
        });

        // Polling logic (10 seconds, pause on hidden)
        function renderCardHtml(task) {
            const todayStr = new Date().toISOString().split('T')[0];
            const isOverdue = (task.due_date && task.status !== 'Completed' && task.due_date < todayStr);
            const priorityClass = task.priority === 'High' ? 'badge-high' : (task.priority === 'Low' ? 'badge-low' : 'badge-medium');
            const priorityLabel = task.priority === 'High' ? 'عالية' : (task.priority === 'Low' ? 'منخفضة' : 'متوسطة');
            const assigneeName = task.assignee_name || '';
            const assigneeInitials = getInitials(assigneeName);
            const commentsCount = parseInt(task.comments_count || 0, 10);
            const phaseId = task.phase_id ? task.phase_id : 'none';
            const phaseTitle = task.phase_title || '';
            const chTotal = parseInt(task.checklist_total || 0, 10);
            const chDone = parseInt(task.checklist_done || 0, 10);

            let phaseTagHtml = '';
            if (phaseTitle) {
                phaseTagHtml = `<span class="kanban-phase-tag" title="المرحلة: ${escapeHtml(phaseTitle)}">${escapeHtml(phaseTitle)}</span>`;
            }

            let dueDateHtml = '';
            if (task.due_date) {
                const overdueClass = isOverdue ? 'is-overdue' : '';
                const overdueNote = isOverdue ? ' (متأخرة)' : '';
                dueDateHtml = `
                    <span class="kanban-due-date ${overdueClass}">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                        <bdi dir="ltr" class="tabular-nums">${escapeHtml(task.due_date)}</bdi>${overdueNote}
                    </span>
                `;
            }

            let checklistBadgeHtml = '';
            if (chTotal > 0) {
                const isComplete = (chTotal === chDone);
                checklistBadgeHtml = `
                    <span class="kanban-checklist-badge ${isComplete ? 'is-complete' : ''}" title="${chDone} من ${chTotal} مهام فرعية مكتملة">
                        <span class="checklist-icon">✓</span>
                        <bdi dir="ltr" class="tabular-nums">${chDone}/${chTotal}</bdi>
                    </span>
                `;
            }

            let commentsBadgeHtml = '';
            if (commentsCount > 0) {
                commentsBadgeHtml = `
                    <span class="kanban-comment-badge" title="${commentsCount} تعليق">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                        <span class="comment-count-val">${commentsCount}</span>
                    </span>
                `;
            }

            let descHtml = '';
            if (task.description) {
                descHtml = `<div class="kanban-card-desc">${escapeHtml(task.description)}</div>`;
            }

            let actionsHtml = '';
            if (isProjectOwner) {
                actionsHtml = `
                    <div class="kanban-card-actions">
                        <a href="view-tasks.php?project_id=${projectId}&edit_task_id=${encodeURIComponent(task.id)}" class="btn-icon" aria-label="تعديل المهمة" title="تعديل">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        </a>
                        <button type="button" class="btn-icon btn-icon-danger open-delete-task-modal-btn" data-task-id="${escapeHtml(task.id)}" data-task-title="${escapeHtml(task.title)}" aria-label="حذف المهمة" title="حذف">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        </button>
                    </div>
                `;
            }

            const assigneeHtml = assigneeName ? `
                <span class="avatar avatar-sm" title="${escapeHtml(assigneeName)}">${escapeHtml(assigneeInitials)}</span>
                <span class="assignee-name-label">${escapeHtml(assigneeName)}</span>
            ` : `<span class="assignee-unassigned-label">غير مسندة</span>`;

            return `
                <div class="kanban-card"
                     data-task-id="${escapeHtml(task.id)}"
                     data-title="${escapeHtml(task.title)}"
                     data-desc="${escapeHtml(task.description || '')}"
                     data-priority="${escapeHtml(task.priority)}"
                     data-status="${escapeHtml(task.status)}"
                     data-due-date="${escapeHtml(task.due_date || '')}"
                     data-assignee="${escapeHtml(assigneeName)}"
                     data-comments-count="${commentsCount}"
                     data-phase-id="${escapeHtml(phaseId)}"
                     data-phase-title="${escapeHtml(phaseTitle)}"
                     data-checklist-total="${chTotal}"
                     data-checklist-done="${chDone}"
                     tabindex="0"
                     role="button"
                     aria-haspopup="dialog"
                     aria-label="عرض تفاصيل المهمة: ${escapeHtml(task.title)}">
                    <div class="kanban-card-title">${escapeHtml(task.title)}</div>
                    ${descHtml}
                    <div class="kanban-card-meta">
                        <span class="badge ${priorityClass}">${priorityLabel}</span>
                        ${phaseTagHtml}
                        ${dueDateHtml}
                        ${checklistBadgeHtml}
                        ${commentsBadgeHtml}
                    </div>
                    <div class="form-group kanban-card-status-wrap">
                        <select class="form-select task-status-select" data-task-id="${escapeHtml(task.id)}" aria-label="تغيير حالة المهمة">
                            <option value="Pending" ${task.status === 'Pending' ? 'selected' : ''}>للتنفيذ</option>
                            <option value="In Progress" ${task.status === 'In Progress' ? 'selected' : ''}>قيد التنفيذ</option>
                            <option value="Completed" ${task.status === 'Completed' ? 'selected' : ''}>مكتملة</option>
                        </select>
                    </div>
                    <div class="kanban-card-footer">
                        <div class="kanban-card-assignee">${assigneeHtml}</div>
                        ${actionsHtml}
                    </div>
                </div>
            `;
        }

        function renderKanban(tasks) {
            const listTodo = document.querySelector('.cards-todo');
            const listProgress = document.querySelector('.cards-progress');
            const listDone = document.querySelector('.cards-done');

            const todoTasks = tasks.filter(t => t.status === 'Pending');
            const progressTasks = tasks.filter(t => t.status === 'In Progress');
            const doneTasks = tasks.filter(t => t.status === 'Completed');

            if (listTodo) {
                listTodo.innerHTML = todoTasks.length ? todoTasks.map(renderCardHtml).join('') : '<div class="kanban-empty-placeholder">لا توجد مهام للتنفيذ</div>';
            }
            if (listProgress) {
                listProgress.innerHTML = progressTasks.length ? progressTasks.map(renderCardHtml).join('') : '<div class="kanban-empty-placeholder">لا توجد مهام قيد التنفيذ</div>';
            }
            if (listDone) {
                listDone.innerHTML = doneTasks.length ? doneTasks.map(renderCardHtml).join('') : '<div class="kanban-empty-placeholder">لا توجد مهام مكتملة</div>';
            }

            const cTodo = document.querySelector('.count-todo');
            const cProg = document.querySelector('.count-progress');
            const cDone = document.querySelector('.count-done');
            if (cTodo) cTodo.textContent = todoTasks.length;
            if (cProg) cProg.textContent = progressTasks.length;
            if (cDone) cDone.textContent = doneTasks.length;

            lastTasksJson = JSON.stringify(tasks);

            // Re-apply phase filter and update chip counters
            applyPhaseFilter(currentActivePhase);
            updatePhaseChipCounts(tasks);
        }

        let pendingDeletePhaseId = null;

        window.openPhasesModal = function() {
            if (typeof openModal === 'function') {
                openModal('phasesModal');
            }
            loadPhasesList();
        };

        function loadPhasesList() {
            const listEl = document.getElementById('phaseManageList');
            if (!listEl) return;
            listEl.innerHTML = '<div style="padding:12px;color:var(--text-muted);font-size:var(--font-size-sm);">جارٍ التحميل...</div>';

            fetch(`../project-phases.php?action=list&project_id=${projectId}`)
                .then(res => res.json())
                .then(data => {
                    if (!data.success) {
                        listEl.innerHTML = '<div class="alert alert-error">فشل في تحميل المراحل.</div>';
                        return;
                    }
                    renderPhasesList(data.phases || []);
                })
                .catch(() => {
                    listEl.innerHTML = '<div class="alert alert-error">فشل في الاتصال بالخادم.</div>';
                });
        }

        function renderPhasesList(phases) {
            const listEl = document.getElementById('phaseManageList');
            if (!listEl) return;
            listEl.innerHTML = '';
            if (phases.length === 0) {
                listEl.innerHTML = '<div style="padding:12px;color:var(--text-muted);font-size:var(--font-size-sm);text-align:center;">لا توجد مراحل بعد. أضف أول مرحلة أعلاه.</div>';
                return;
            }

            phases.forEach((ph, idx) => {
                const item = document.createElement('div');
                item.className = 'phase-manage-item';
                item.dataset.phaseId = ph.id;

                const info = document.createElement('div');
                info.className = 'phase-manage-title';
                const titleSpan = document.createElement('span');
                titleSpan.textContent = ph.title;
                info.appendChild(titleSpan);

                const countSpan = document.createElement('span');
                countSpan.className = 'phase-manage-count';
                countSpan.textContent = ` (${ph.total_tasks || 0} مهام)`;
                info.appendChild(countSpan);

                item.appendChild(info);

                const actions = document.createElement('div');
                actions.className = 'phase-manage-actions';

                // Up button
                if (idx > 0) {
                    const upBtn = document.createElement('button');
                    upBtn.type = 'button';
                    upBtn.className = 'btn-icon btn-sm';
                    upBtn.title = 'تحريك لأعلى';
                    upBtn.setAttribute('aria-label', 'تحريك لأعلى');
                    upBtn.innerHTML = '▲';
                    upBtn.addEventListener('click', () => movePhase(phases, idx, -1));
                    actions.appendChild(upBtn);
                }

                // Down button
                if (idx < phases.length - 1) {
                    const downBtn = document.createElement('button');
                    downBtn.type = 'button';
                    downBtn.className = 'btn-icon btn-sm';
                    downBtn.title = 'تحريك لأسفل';
                    downBtn.setAttribute('aria-label', 'تحريك لأسفل');
                    downBtn.innerHTML = '▼';
                    downBtn.addEventListener('click', () => movePhase(phases, idx, 1));
                    actions.appendChild(downBtn);
                }

                // Rename button
                const renameBtn = document.createElement('button');
                renameBtn.type = 'button';
                renameBtn.className = 'btn-icon btn-sm';
                renameBtn.title = 'تعديل الاسم';
                renameBtn.setAttribute('aria-label', 'تعديل اسم المرحلة');
                renameBtn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>';
                renameBtn.addEventListener('click', () => renamePhase(ph));
                actions.appendChild(renameBtn);

                // Delete button
                const delBtn = document.createElement('button');
                delBtn.type = 'button';
                delBtn.className = 'btn-icon btn-icon-danger btn-sm';
                delBtn.title = 'حذف';
                delBtn.setAttribute('aria-label', 'حذف المرحلة');
                delBtn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>';
                delBtn.addEventListener('click', () => promptDeletePhase(ph));
                actions.appendChild(delBtn);

                item.appendChild(actions);
                listEl.appendChild(item);
            });
        }

        function movePhase(phases, idx, delta) {
            const targetIdx = idx + delta;
            const temp = phases[idx];
            phases[idx] = phases[targetIdx];
            phases[targetIdx] = temp;
            const ids = phases.map(p => p.id);
            fetch('../project-phases.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({ action: 'reorder', project_id: projectId, phase_ids: ids, csrf_token: csrfToken })
            }).then(res => res.json()).then(data => {
                if (data.success) {
                    window.location.reload();
                }
            });
        }

        function renamePhase(ph) {
            const newTitle = prompt('تعديل اسم المرحلة:', ph.title);
            if (!newTitle || newTitle.trim() === '' || newTitle.trim() === ph.title) return;
            fetch('../project-phases.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({ action: 'rename', phase_id: ph.id, title: newTitle.trim(), csrf_token: csrfToken })
            }).then(res => res.json()).then(data => {
                if (data.success) {
                    window.location.reload();
                } else {
                    alert(data.message || 'فشل في تعديل اسم المرحلة');
                }
            });
        }

        function promptDeletePhase(ph) {
            pendingDeletePhaseId = ph.id;
            const confirmText = document.getElementById('deletePhaseConfirmText');
            const count = ph.total_tasks || 0;
            if (confirmText) {
                confirmText.textContent = `هل أنت متأكد من حذف المرحلة "${ph.title}"؟ سيتم نقل ${count} مهمة إلى "بدون مرحلة".`;
            }
            if (typeof openModal === 'function') {
                openModal('deletePhaseModal');
            }
        }

        const btnConfirmDeletePhase = document.getElementById('btnConfirmDeletePhase');
        if (btnConfirmDeletePhase) {
            btnConfirmDeletePhase.addEventListener('click', function() {
                if (!pendingDeletePhaseId) return;
                fetch('../project-phases.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ action: 'delete', phase_id: pendingDeletePhaseId, csrf_token: csrfToken })
                }).then(res => res.json()).then(data => {
                    if (data.success) {
                        const url = new URL(window.location);
                        if (url.searchParams.get('phase') === String(pendingDeletePhaseId)) {
                            url.searchParams.set('phase', 'all');
                        }
                        window.location.href = url.toString();
                    } else {
                        alert(data.message || 'فشل في حذف المرحلة');
                    }
                });
            });
        }

        const addPhaseForm = document.getElementById('addPhaseForm');
        if (addPhaseForm) {
            addPhaseForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const titleInput = document.getElementById('newPhaseTitle');
                const title = titleInput.value.trim();
                if (!title) return;
                fetch('../project-phases.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ action: 'add', project_id: projectId, title: title, csrf_token: csrfToken })
                }).then(res => res.json()).then(data => {
                    if (data.success) {
                        window.location.reload();
                    } else {
                        alert(data.message || 'فشل في إضافة المرحلة');
                    }
                });
            });
        }

        // Apply phase filter on load
        window.addEventListener('DOMContentLoaded', function() {
            applyPhaseFilter(currentActivePhase);
        });

        function pollTasks() {
            fetch(`../get-tasks.php?project_id=${projectId}`)
                .then(res => {
                    if (res.status === 401) { window.location.href = '../login.php'; return null; }
                    if (res.status === 404) { window.location.href = '../dashboard.php'; return null; }
                    if (!res.ok) return null;
                    return res.json();
                })
                .then(data => {
                    if (data && data.success && data.tasks) {
                        const newJson = JSON.stringify(data.tasks);
                        if (newJson !== lastTasksJson) {
                            renderKanban(data.tasks);
                        }
                    }
                })
                .catch(() => {});
        }

        let pollTimer = null;
        function start() {
            if (!pollTimer) pollTimer = setInterval(pollTasks, 10000); // 10s polling
        }
        function stop() {
            if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
        }

        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                stop();
            } else {
                pollTasks();
                start();
            }
        });

        start();
    })();
    </script>

    <!-- Delete Task Modal -->
    <div class="modal" id="deleteTaskModal" role="dialog" aria-modal="true" aria-labelledby="deleteTaskTitle">
        <div class="modal-backdrop" data-dismiss="modal"></div>
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title" id="deleteTaskTitle">حذف المهمة</h2>
                    <button type="button" class="modal-close" data-dismiss="modal" aria-label="إغلاق">&times;</button>
                </div>
                <form method="POST" id="deleteTaskForm">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="task_id" id="delete_modal_task_id" value="">
                    <input type="hidden" name="delete_task" value="1">
                    <div class="modal-body">
                        <p class="modal-alert-text">
                            هل أنت متأكد من رغبتك في حذف المهمة:
                            <strong id="delete_modal_task_title"></strong>؟
                        </p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>
                        <button type="submit" class="btn btn-danger">تأكيد الحذف</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.open-delete-task-modal-btn');
        if (!btn) return;
        const tid = btn.dataset.taskId;
        const title = btn.dataset.taskTitle;
        document.getElementById('delete_modal_task_id').value = tid;
        document.getElementById('delete_modal_task_title').textContent = title;
        if (typeof openModal === 'function') {
            openModal('deleteTaskModal');
        }
    });
    </script>

    <!-- Task Details & Comments Side Drawer -->
    <div class="task-drawer-backdrop" id="taskDrawerBackdrop" aria-hidden="true"></div>
    <aside class="task-drawer" id="taskDrawer" role="dialog" aria-modal="true" aria-labelledby="drawerTaskTitle" hidden>
        <div class="task-drawer-header">
            <div class="task-drawer-header-info">
                <span class="badge" id="drawerTaskPriority">متوسطة</span>
                <span class="badge" id="drawerTaskStatus">للتنفيذ</span>
            </div>
            <button type="button" class="btn-icon drawer-close-btn" id="drawerCloseBtn" aria-label="إغلاق اللوحة" title="إغلاق">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>

        <div class="task-drawer-body">
            <h2 class="task-drawer-title" id="drawerTaskTitle"></h2>
            
            <div class="task-drawer-section">
                <h3 class="task-drawer-section-title">الوصف</h3>
                <div class="task-drawer-desc" id="drawerTaskDesc"></div>
            </div>

            <div class="task-drawer-meta-grid">
                <div class="task-drawer-meta-item">
                    <span class="meta-label">تاريخ الاستحقاق</span>
                    <span class="meta-value tabular-nums" id="drawerTaskDueDate">--</span>
                </div>
                <div class="task-drawer-meta-item">
                    <span class="meta-label">المسؤول عن المهمة</span>
                    <span class="meta-value" id="drawerTaskAssignee">--</span>
                </div>
            </div>

            <hr class="task-drawer-divider">

            <!-- Checklist Section -->
            <section class="task-drawer-checklist-section" aria-labelledby="drawerChecklistHeading">
                <div class="checklist-section-header">
                    <h3 class="task-drawer-section-title" id="drawerChecklistHeading">
                        <span>المهام الفرعية</span>
                        <span class="checklist-count-pill" id="drawerChecklistCount">0/0</span>
                    </h3>
                </div>

                <!-- Suggestion Banner: All items done but status != Completed -->
                <div class="checklist-suggestion-banner" id="checklistDoneSuggestion" hidden>
                    <span class="suggestion-text">كل المهام الفرعية خلصت، تنقل المهمة لمكتملة؟</span>
                    <button type="button" class="btn btn-sm btn-primary" id="btnMarkTaskCompletedFromChecklist">
                        نقل لمكتملة
                    </button>
                </div>

                <!-- Checklist items container -->
                <div class="checklist-items-list" id="drawerChecklistItems" role="list">
                    <!-- Loaded via JS -->
                </div>

                <!-- Add checklist item field -->
                <form id="drawerAddChecklistForm" class="checklist-add-form" autocomplete="off" hidden>
                    <div class="checklist-add-input-wrap">
                        <input type="text" id="newChecklistTitle" class="form-control checklist-input" maxlength="200" placeholder="إضافة مهمة فرعية... (اضغط Enter للإضافة)" aria-label="إضافة مهمة فرعية">
                        <button type="submit" class="btn btn-secondary btn-sm" id="submitChecklistBtn">إضافة</button>
                    </div>
                    <div class="checklist-error-msg" id="checklistErrorMsg" hidden></div>
                </form>
            </section>

            <hr class="task-drawer-divider">

            <!-- Comments Section -->
            <section class="task-drawer-comments-section" aria-labelledby="drawerCommentsHeading">
                <div class="comments-section-header">
                    <h3 class="task-drawer-section-title" id="drawerCommentsHeading">
                        <span>التعليقات</span>
                        <span class="comments-count-pill" id="drawerCommentsCount">0</span>
                    </h3>
                </div>

                <div class="comments-thread" id="drawerCommentsThread" role="feed" aria-busy="false">
                    <!-- Comments loaded via AJAX -->
                </div>

                <!-- Add Comment Form -->
                <form id="drawerAddCommentForm" class="comment-add-form" autocomplete="off">
                    <div class="form-group">
                        <label for="newCommentBody" class="visually-hidden">إضافة تعليق</label>
                        <textarea id="newCommentBody" class="form-textarea comment-input" rows="3" maxlength="2000" placeholder="أضف تعليقاً... (Ctrl+Enter للإرسال)"></textarea>
                        <div class="comment-form-footer">
                            <span class="char-counter" id="commentCharCount">0/2000</span>
                            <button type="submit" class="btn btn-primary btn-sm" id="submitCommentBtn" disabled>
                                إرسال
                            </button>
                        </div>
                    </div>
                </form>
            </section>
        </div>
    </aside>

    <!-- Delete Comment Confirmation Modal -->
    <div class="modal" id="deleteCommentModal" role="dialog" aria-modal="true" aria-labelledby="deleteCommentTitle">
        <div class="modal-backdrop" data-dismiss="modal"></div>
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title" id="deleteCommentTitle">حذف التعليق</h2>
                    <button type="button" class="modal-close" data-dismiss="modal" aria-label="إغلاق">&times;</button>
                </div>
                <div class="modal-body">
                    <p class="modal-alert-text">هل أنت متأكد من رغبتك في حذف هذا التعليق؟ لا يمكن التراجع عن هذا الإجراء.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteCommentBtn">تأكيد الحذف</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Task Drawer & Comments Controller -->
    <script>
    (function () {
        const csrfToken = '<?= csrf_token() ?>';
        const projectId = '<?= (int)$project_id ?>';
        const drawer = document.getElementById('taskDrawer');
        const drawerBackdrop = document.getElementById('taskDrawerBackdrop');
        const drawerCloseBtn = document.getElementById('drawerCloseBtn');
        const commentsThread = document.getElementById('drawerCommentsThread');
        const drawerCommentsCount = document.getElementById('drawerCommentsCount');
        const addCommentForm = document.getElementById('drawerAddCommentForm');
        const newCommentBody = document.getElementById('newCommentBody');
        const commentCharCount = document.getElementById('commentCharCount');
        const submitCommentBtn = document.getElementById('submitCommentBtn');
        const confirmDeleteCommentBtn = document.getElementById('confirmDeleteCommentBtn');

        let currentDrawerTaskId = null;
        let lastActiveElement = null;
        let drawerPollTimer = null;
        let pendingDeleteCommentId = null;

        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function formatArabicRelativeTime(dateStr) {
            if (!dateStr) return '';
            const parts = dateStr.split(/[- :]/);
            let date;
            if (parts.length >= 6) {
                date = new Date(parts[0], parts[1] - 1, parts[2], parts[3], parts[4], parts[5]);
            } else {
                date = new Date(dateStr);
            }
            const now = new Date();
            const diffSec = Math.max(0, Math.floor((now - date) / 1000));

            if (diffSec < 60) return 'دلوقتي';
            const diffMin = Math.floor(diffSec / 60);
            if (diffMin < 60) {
                if (diffMin === 1) return 'من دقيقة';
                if (diffMin === 2) return 'من دقيقتين';
                if (diffMin >= 3 && diffMin <= 10) return `من <bdi class="tabular-nums">${diffMin}</bdi> دقايق`;
                return `من <bdi class="tabular-nums">${diffMin}</bdi> دقيقة`;
            }
            const diffHours = Math.floor(diffMin / 60);
            if (diffHours < 24) {
                if (diffHours === 1) return 'من ساعة';
                if (diffHours === 2) return 'من ساعتين';
                if (diffHours >= 3 && diffHours <= 10) return `من <bdi class="tabular-nums">${diffHours}</bdi> ساعات`;
                return `من <bdi class="tabular-nums">${diffHours}</bdi> ساعة`;
            }
            const diffDays = Math.floor(diffHours / 24);
            if (diffDays === 1) return 'امبارح';
            if (diffDays === 2) return 'من يومين';
            if (diffDays >= 3 && diffDays <= 10) return `من <bdi class="tabular-nums">${diffDays}</bdi> أيام`;
            return `<bdi dir="ltr" class="tabular-nums">${dateStr.substring(0, 10)}</bdi>`;
        }

        function updateCardCommentCountBadge(taskId, count) {
            const card = document.querySelector(`.kanban-card[data-task-id="${taskId}"]`);
            if (!card) return;
            card.dataset.commentsCount = count;
            const meta = card.querySelector('.kanban-card-meta');
            if (!meta) return;
            let badge = meta.querySelector('.kanban-comment-badge');
            if (count > 0) {
                if (badge) {
                    const countSpan = badge.querySelector('.comment-count-val');
                    if (countSpan) countSpan.textContent = count;
                    badge.title = `${count} تعليق`;
                } else {
                    const badgeHtml = `
                        <span class="kanban-comment-badge" title="${count} تعليق">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                            <span class="comment-count-val">${count}</span>
                        </span>
                    `;
                    meta.insertAdjacentHTML('beforeend', badgeHtml);
                }
            } else if (badge) {
                badge.remove();
            }
        }

        function openTaskDrawer(taskId, triggerEl) {
            currentDrawerTaskId = taskId;
            lastActiveElement = triggerEl || document.activeElement;

            const card = document.querySelector(`.kanban-card[data-task-id="${taskId}"]`);
            const titleEl = document.getElementById('drawerTaskTitle');
            const descEl = document.getElementById('drawerTaskDesc');
            const priorityEl = document.getElementById('drawerTaskPriority');
            const statusEl = document.getElementById('drawerTaskStatus');
            const dueDateEl = document.getElementById('drawerTaskDueDate');
            const assigneeEl = document.getElementById('drawerTaskAssignee');

            if (card) {
                titleEl.textContent = card.dataset.title || '';
                const desc = (card.dataset.desc || '').trim();
                if (desc) {
                    descEl.textContent = desc;
                    descEl.classList.remove('is-empty');
                } else {
                    descEl.textContent = 'لا يوجد وصف للمهمة.';
                    descEl.classList.add('is-empty');
                }

                // Priority
                const priority = card.dataset.priority || 'Medium';
                priorityEl.className = 'badge';
                if (priority === 'High') {
                    priorityEl.classList.add('badge-high');
                    priorityEl.textContent = 'عالية';
                } else if (priority === 'Low') {
                    priorityEl.classList.add('badge-low');
                    priorityEl.textContent = 'منخفضة';
                } else {
                    priorityEl.classList.add('badge-medium');
                    priorityEl.textContent = 'متوسطة';
                }

                // Status
                const status = card.dataset.status || 'Pending';
                statusEl.className = 'badge';
                if (status === 'Completed') {
                    statusEl.classList.add('badge-done');
                    statusEl.textContent = 'مكتملة';
                } else if (status === 'In Progress') {
                    statusEl.classList.add('badge-progress');
                    statusEl.textContent = 'قيد التنفيذ';
                } else {
                    statusEl.classList.add('badge-todo');
                    statusEl.textContent = 'للتنفيذ';
                }

                dueDateEl.innerHTML = card.dataset.dueDate ? '<bdi dir="ltr">' + escapeHtml(card.dataset.dueDate) + '</bdi>' : 'غير محدد';
                assigneeEl.textContent = card.dataset.assignee || 'غير مسندة';
            } else {
                titleEl.textContent = `مهمة #${taskId}`;
                descEl.textContent = 'جارٍ تحميل البيانات...';
                descEl.classList.remove('is-empty');
            }

            // Open drawer
            drawer.hidden = false;
            drawerBackdrop.classList.add('is-open');
            drawer.classList.add('is-open');
            document.body.classList.add('drawer-open');

            // Reset input
            newCommentBody.value = '';
            commentCharCount.textContent = '0/2000';
            commentCharCount.classList.remove('is-over-limit');
            submitCommentBtn.disabled = true;

            // Deep link update in URL
            const url = new URL(window.location);
            url.searchParams.set('task', taskId);
            window.history.replaceState({}, '', url);

            // Fetch comments and checklist
            fetchComments(taskId);
            fetchChecklist(taskId);
            startDrawerPolling();

            // Set focus
            drawerCloseBtn.focus();
        }

        function closeTaskDrawer() {
            if (!drawer.classList.contains('is-open')) return;

            drawer.classList.remove('is-open');
            drawerBackdrop.classList.remove('is-open');
            drawer.hidden = true;
            document.body.classList.remove('drawer-open');

            const itemsList = document.getElementById('drawerChecklistItems');
            if (itemsList) itemsList.innerHTML = '';
            const sug = document.getElementById('checklistDoneSuggestion');
            if (sug) sug.hidden = true;

            stopDrawerPolling();
            currentDrawerTaskId = null;

            // Clean task from URL
            const url = new URL(window.location);
            url.searchParams.delete('task');
            window.history.replaceState({}, '', url);

            // Return focus to triggering card or element
            if (lastActiveElement && typeof lastActiveElement.focus === 'function') {
                lastActiveElement.focus();
            }
        }

        function fetchComments(taskId, isBackground = false) {
            if (!taskId) return;
            if (!isBackground) {
                commentsThread.setAttribute('aria-busy', 'true');
            }

            fetch(`../tasks/comments.php?task_id=${encodeURIComponent(taskId)}`)
                .then(res => {
                    if (res.status === 401) {
                        window.location.href = '../login.php';
                        return null;
                    }
                    if (res.status === 404) {
                        if (!isBackground) {
                            if (typeof showToast === 'function') {
                                showToast('المهمة غير موجودة أو غير مصرح لك بعرضها.', 'error');
                            }
                            closeTaskDrawer();
                        }
                        return null;
                    }
                    if (!res.ok) return null;
                    return res.json();
                })
                .then(data => {
                    commentsThread.setAttribute('aria-busy', 'false');
                    if (data && data.success && Array.isArray(data.comments)) {
                        renderCommentsList(data.comments);
                        drawerCommentsCount.textContent = data.comments.length;
                        updateCardCommentCountBadge(taskId, data.comments.length);
                    }
                })
                .catch(() => {
                    commentsThread.setAttribute('aria-busy', 'false');
                });
        }

        function renderCommentsList(comments) {
            commentsThread.innerHTML = '';
            if (comments.length === 0) {
                commentsThread.innerHTML = '<div class="comments-empty-placeholder">لا توجد تعليقات بعد. كن أول من يعلّق!</div>';
                return;
            }

            comments.forEach(comment => {
                const card = document.createElement('div');
                card.className = 'comment-card';
                card.dataset.commentId = comment.id;

                const header = document.createElement('div');
                header.className = 'comment-card-header';

                const authorInfo = document.createElement('div');
                authorInfo.className = 'comment-card-author-info';

                const avatar = document.createElement('span');
                avatar.className = 'avatar avatar-sm';
                avatar.textContent = comment.initials || 'TF';
                authorInfo.appendChild(avatar);

                const nameWrap = document.createElement('div');
                const authorName = document.createElement('span');
                authorName.className = 'comment-card-author-name';
                authorName.textContent = comment.author_name;
                nameWrap.appendChild(authorName);

                if (comment.edited) {
                    const editedTag = document.createElement('span');
                    editedTag.className = 'comment-edited-tag';
                    editedTag.textContent = ' (معدّل)';
                    nameWrap.appendChild(editedTag);
                }

                const timeEl = document.createElement('div');
                timeEl.className = 'comment-card-time tabular-nums';
                timeEl.title = comment.created_at;
                timeEl.innerHTML = formatArabicRelativeTime(comment.created_at);
                nameWrap.appendChild(timeEl);

                authorInfo.appendChild(nameWrap);
                header.appendChild(authorInfo);

                // Actions (Edit / Delete)
                if (comment.can_edit || comment.can_delete) {
                    const actions = document.createElement('div');
                    actions.className = 'comment-card-actions';

                    if (comment.can_edit) {
                        const editBtn = document.createElement('button');
                        editBtn.type = 'button';
                        editBtn.className = 'btn-icon btn-sm edit-comment-btn';
                        editBtn.title = 'تعديل';
                        editBtn.setAttribute('aria-label', 'تعديل التعليق');
                        editBtn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>';
                        editBtn.addEventListener('click', () => startEditComment(card, comment));
                        actions.appendChild(editBtn);
                    }

                    if (comment.can_delete) {
                        const delBtn = document.createElement('button');
                        delBtn.type = 'button';
                        delBtn.className = 'btn-icon btn-icon-danger btn-sm delete-comment-btn';
                        delBtn.title = 'حذف';
                        delBtn.setAttribute('aria-label', 'حذف التعليق');
                        delBtn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>';
                        delBtn.addEventListener('click', () => {
                            pendingDeleteCommentId = comment.id;
                            if (typeof openModal === 'function') {
                                openModal('deleteCommentModal');
                            }
                        });
                        actions.appendChild(delBtn);
                    }

                    header.appendChild(actions);
                }

                card.appendChild(header);

                // Body: MUST use textContent only (XSS protection)
                const bodyEl = document.createElement('div');
                bodyEl.className = 'comment-body-text';
                bodyEl.textContent = comment.body;
                card.appendChild(bodyEl);

                commentsThread.appendChild(card);
            });
        }

        function startEditComment(card, comment) {
            const bodyEl = card.querySelector('.comment-body-text');
            if (!bodyEl || card.querySelector('.comment-inline-edit')) return;

            bodyEl.hidden = true;

            const editBox = document.createElement('div');
            editBox.className = 'comment-inline-edit';

            const textarea = document.createElement('textarea');
            textarea.className = 'form-textarea';
            textarea.rows = 3;
            textarea.maxLength = 2000;
            textarea.value = comment.body;

            const footer = document.createElement('div');
            footer.className = 'comment-inline-edit-actions';

            const counter = document.createElement('span');
            counter.className = 'char-counter';
            counter.textContent = `${textarea.value.length}/2000`;
            footer.appendChild(counter);

            const cancelBtn = document.createElement('button');
            cancelBtn.type = 'button';
            cancelBtn.className = 'btn btn-secondary btn-sm';
            cancelBtn.textContent = 'إلغاء';
            cancelBtn.addEventListener('click', () => {
                editBox.remove();
                bodyEl.hidden = false;
            });
            footer.appendChild(cancelBtn);

            const saveBtn = document.createElement('button');
            saveBtn.type = 'button';
            saveBtn.className = 'btn btn-primary btn-sm';
            saveBtn.textContent = 'حفظ';
            footer.appendChild(saveBtn);

            textarea.addEventListener('input', () => {
                const len = textarea.value.length;
                counter.textContent = `${len}/2000`;
                if (len > 2000) {
                    counter.classList.add('is-over-limit');
                    saveBtn.disabled = true;
                } else {
                    counter.classList.remove('is-over-limit');
                    saveBtn.disabled = (len < 1);
                }
            });

            textarea.addEventListener('keydown', (e) => {
                if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                    e.preventDefault();
                    if (!saveBtn.disabled) saveBtn.click();
                }
            });

            saveBtn.addEventListener('click', () => {
                const newText = textarea.value.trim();
                if (!newText || newText.length > 2000) return;

                saveBtn.disabled = true;
                cancelBtn.disabled = true;

                const formData = new FormData();
                formData.append('action', 'edit');
                formData.append('comment_id', comment.id);
                formData.append('body', newText);
                formData.append('csrf_token', csrfToken);

                fetch('../tasks/comments.php', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': csrfToken },
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data && data.success) {
                        comment.body = newText;
                        comment.edited = true;
                        bodyEl.textContent = newText;
                        bodyEl.hidden = false;
                        editBox.remove();

                        // Add edited tag if not present
                        const nameWrap = card.querySelector('.comment-card-author-info > div');
                        if (nameWrap && !nameWrap.querySelector('.comment-edited-tag')) {
                            const editedTag = document.createElement('span');
                            editedTag.className = 'comment-edited-tag';
                            editedTag.textContent = ' (معدّل)';
                            nameWrap.insertBefore(editedTag, nameWrap.querySelector('.comment-card-time'));
                        }

                        if (typeof showToast === 'function') {
                            showToast('تم تحديث التعليق بنجاح.', 'success');
                        }
                    } else {
                        saveBtn.disabled = false;
                        cancelBtn.disabled = false;
                        if (typeof showToast === 'function') {
                            showToast(data.message || 'فشل في تحديث التعليق.', 'error');
                        }
                    }
                })
                .catch(() => {
                    saveBtn.disabled = false;
                    cancelBtn.disabled = false;
                    if (typeof showToast === 'function') {
                        showToast('فشل في الاتصال بالخادم.', 'error');
                    }
                });
            });

            editBox.appendChild(textarea);
            editBox.appendChild(footer);
            card.appendChild(editBox);
            textarea.focus();
        }

        // Delete comment confirmation handler
        if (confirmDeleteCommentBtn) {
            confirmDeleteCommentBtn.addEventListener('click', function () {
                if (!pendingDeleteCommentId) return;

                confirmDeleteCommentBtn.disabled = true;
                const formData = new FormData();
                formData.append('action', 'delete');
                formData.append('comment_id', pendingDeleteCommentId);
                formData.append('csrf_token', csrfToken);

                fetch('../tasks/comments.php', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': csrfToken },
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    confirmDeleteCommentBtn.disabled = false;
                    if (data && data.success) {
                        if (typeof closeModal === 'function') {
                            closeModal('deleteCommentModal');
                        }
                        const card = commentsThread.querySelector(`.comment-card[data-comment-id="${pendingDeleteCommentId}"]`);
                        if (card) card.remove();

                        const count = Math.max(0, parseInt(drawerCommentsCount.textContent || 0, 10) - 1);
                        drawerCommentsCount.textContent = count;
                        if (currentDrawerTaskId) {
                            updateCardCommentCountBadge(currentDrawerTaskId, count);
                        }

                        if (commentsThread.children.length === 0) {
                            commentsThread.innerHTML = '<div class="comments-empty-placeholder">لا توجد تعليقات بعد. كن أول من يعلّق!</div>';
                        }

                        if (typeof showToast === 'function') {
                            showToast('تم حذف التعليق بنجاح.', 'success');
                        }
                    } else {
                        if (typeof showToast === 'function') {
                            showToast(data.message || 'فشل في حذف التعليق.', 'error');
                        }
                    }
                    pendingDeleteCommentId = null;
                })
                .catch(() => {
                    confirmDeleteCommentBtn.disabled = false;
                    pendingDeleteCommentId = null;
                    if (typeof showToast === 'function') {
                        showToast('فشل في الاتصال بالخادم.', 'error');
                    }
                });
            });
        }

        // Add Comment form submission
        function handleAddComment() {
            const body = newCommentBody.value.trim();
            if (!body || body.length > 2000 || !currentDrawerTaskId) return;

            submitCommentBtn.disabled = true;

            const formData = new FormData();
            formData.append('action', 'add');
            formData.append('task_id', currentDrawerTaskId);
            formData.append('body', body);
            formData.append('csrf_token', csrfToken);

            fetch('../tasks/comments.php', {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrfToken },
                body: formData
            })
            .then(res => {
                if (res.status === 429) {
                    if (typeof showToast === 'function') {
                        showToast('تجاوزت الحد الأقصى للتعليقات (10 تعليقات في الدقيقة). يرجى الانتظار قليلاً.', 'error');
                    }
                    submitCommentBtn.disabled = false;
                    return null;
                }
                return res.json();
            })
            .then(data => {
                if (!data) return;
                submitCommentBtn.disabled = false;
                if (data.success) {
                    newCommentBody.value = '';
                    commentCharCount.textContent = '0/2000';
                    submitCommentBtn.disabled = true;

                    if (typeof showToast === 'function') {
                        showToast('تمت إضافة التعليق بنجاح!', 'success');
                    }

                    fetchComments(currentDrawerTaskId);
                    // Scroll thread to bottom
                    setTimeout(() => {
                        const drawerBody = document.querySelector('.task-drawer-body');
                        if (drawerBody) drawerBody.scrollTop = drawerBody.scrollHeight;
                    }, 100);
                } else {
                    if (typeof showToast === 'function') {
                        showToast(data.message || 'فشل في إضافة التعليق.', 'error');
                    }
                }
            })
            .catch(() => {
                submitCommentBtn.disabled = false;
                if (typeof showToast === 'function') {
                    showToast('فشل في الاتصال بالخادم.', 'error');
                }
            });
        }

        if (addCommentForm) {
            addCommentForm.addEventListener('submit', function (e) {
                e.preventDefault();
                handleAddComment();
            });
        }

        if (newCommentBody) {
            newCommentBody.addEventListener('input', function () {
                const len = newCommentBody.value.length;
                commentCharCount.textContent = `${len}/2000`;
                if (len > 2000) {
                    commentCharCount.classList.add('is-over-limit');
                    submitCommentBtn.disabled = true;
                } else {
                    commentCharCount.classList.remove('is-over-limit');
                    submitCommentBtn.disabled = (len < 1);
                }
            });

            newCommentBody.addEventListener('keydown', function (e) {
                if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                    e.preventDefault();
                    if (!submitCommentBtn.disabled) {
                        handleAddComment();
                    }
                }
            });
        }

        // 15s Polling for Drawer Comments
        function startDrawerPolling() {
            stopDrawerPolling();
            drawerPollTimer = setInterval(() => {
                if (!document.hidden && currentDrawerTaskId && drawer.classList.contains('is-open')) {
                    fetchComments(currentDrawerTaskId, true);
                }
            }, 15000);
        }

        function stopDrawerPolling() {
            if (drawerPollTimer) {
                clearInterval(drawerPollTimer);
                drawerPollTimer = null;
            }
        }

        // Card Click Listener (delegated)
        document.addEventListener('click', function (e) {
            // Ignore if inside a modal
            if (e.target.closest('#taskModal, #deleteTaskModal, #deleteCommentModal')) return;

            const card = e.target.closest('.kanban-card');
            if (!card) return;

            // Ignore interactive elements inside card
            if (e.target.closest('button, select, a, input, .btn-icon, .form-select')) {
                return;
            }

            const taskId = card.dataset.taskId;
            if (taskId) {
                openTaskDrawer(taskId, card);
            }
        });

        // Keyboard navigation on cards (Enter or Space)
        document.addEventListener('keydown', function (e) {
            if ((e.key === 'Enter' || e.key === ' ') && e.target.classList && e.target.classList.contains('kanban-card')) {
                e.preventDefault();
                const taskId = e.target.dataset.taskId;
                if (taskId) {
                    openTaskDrawer(taskId, e.target);
                }
            }
        });

        // Close Drawer Listeners
        if (drawerCloseBtn) {
            drawerCloseBtn.addEventListener('click', closeTaskDrawer);
        }
        if (drawerBackdrop) {
            drawerBackdrop.addEventListener('click', closeTaskDrawer);
        }

        // Drawer Keydown (Esc close + Focus Trap)
        if (drawer) {
            drawer.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    closeTaskDrawer();
                    return;
                }

                if (e.key === 'Tab') {
                    const focusables = drawer.querySelectorAll(
                        'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
                    );
                    if (focusables.length === 0) return;
                    const first = focusables[0];
                    const last = focusables[focusables.length - 1];

                    if (e.shiftKey && document.activeElement === first) {
                        e.preventDefault();
                        last.focus();
                    } else if (!e.shiftKey && document.activeElement === last) {
                        e.preventDefault();
                        first.focus();
                    }
                }
            });
        }

        // ==========================================
        // Task Checklist Controller
        // ==========================================
        const drawerChecklistItems = document.getElementById('drawerChecklistItems');
        const drawerChecklistCount = document.getElementById('drawerChecklistCount');
        const drawerAddChecklistForm = document.getElementById('drawerAddChecklistForm');
        const newChecklistTitle = document.getElementById('newChecklistTitle');
        const checklistErrorMsg = document.getElementById('checklistErrorMsg');
        const checklistDoneSuggestion = document.getElementById('checklistDoneSuggestion');
        const btnMarkTaskCompletedFromChecklist = document.getElementById('btnMarkTaskCompletedFromChecklist');

        function updateCardChecklistBadge(taskId, total, done) {
            const card = document.querySelector(`.kanban-card[data-task-id="${taskId}"]`);
            if (!card) return;
            card.dataset.checklistTotal = total;
            card.dataset.checklistDone = done;
            const meta = card.querySelector('.kanban-card-meta');
            if (!meta) return;
            let badge = meta.querySelector('.kanban-checklist-badge');
            if (total > 0) {
                const isComplete = (total === done);
                if (badge) {
                    badge.className = `kanban-checklist-badge ${isComplete ? 'is-complete' : ''}`;
                    badge.title = `${done} من ${total} مهام فرعية مكتملة`;
                    const bdi = badge.querySelector('bdi');
                    if (bdi) bdi.textContent = `${done}/${total}`;
                } else {
                    const badgeHtml = `
                        <span class="kanban-checklist-badge ${isComplete ? 'is-complete' : ''}" title="${done} من ${total} مهام فرعية مكتملة">
                            <span class="checklist-icon">✓</span>
                            <bdi dir="ltr" class="tabular-nums">${done}/${total}</bdi>
                        </span>
                    `;
                    meta.insertAdjacentHTML('beforeend', badgeHtml);
                }
            } else if (badge) {
                badge.remove();
            }
        }

        function fetchChecklist(taskId) {
            if (!taskId || !drawerChecklistItems) return;
            drawerChecklistItems.innerHTML = '<div style="padding:8px 0;color:var(--text-muted);font-size:var(--font-size-sm);">جارٍ التحميل...</div>';

            fetch(`../task-checklist.php?action=list&task_id=${encodeURIComponent(taskId)}`)
                .then(res => {
                    if (res.status === 401) { window.location.href = '../login.php'; return null; }
                    if (res.status === 404) return null;
                    if (!res.ok) return null;
                    return res.json();
                })
                .then(data => {
                    if (data && data.success) {
                        renderChecklist(data.items || [], data.can_edit, data.total, data.done);
                    }
                })
                .catch(() => {});
        }

        function renderChecklist(items, canEdit, total, done) {
            if (!drawerChecklistItems) return;
            drawerChecklistItems.innerHTML = '';

            if (drawerChecklistCount) {
                drawerChecklistCount.textContent = `${done}/${total}`;
            }

            if (drawerAddChecklistForm) {
                drawerAddChecklistForm.hidden = !canEdit;
            }

            // Update card badge on the board
            updateCardChecklistBadge(currentDrawerTaskId, total, done);

            // Suggestion banner when all items done and task not completed
            const card = document.querySelector(`.kanban-card[data-task-id="${currentDrawerTaskId}"]`);
            const currentStatus = card ? card.dataset.status : (document.getElementById('drawerTaskStatus') ? document.getElementById('drawerTaskStatus').textContent.trim() : '');
            if (checklistDoneSuggestion) {
                if (total > 0 && done === total && currentStatus !== 'Completed' && currentStatus !== 'مكتملة') {
                    checklistDoneSuggestion.hidden = false;
                } else {
                    checklistDoneSuggestion.hidden = true;
                }
            }

            if (items.length === 0) {
                drawerChecklistItems.innerHTML = '<div class="comments-empty-placeholder">لا توجد مهام فرعية بعد.</div>';
                return;
            }

            items.forEach((item, index) => {
                const itemEl = document.createElement('div');
                itemEl.className = 'checklist-item' + (item.is_done ? ' is-done' : '');
                itemEl.dataset.itemId = item.id;
                itemEl.setAttribute('role', 'listitem');

                // Checkbox
                const checkBtn = document.createElement('button');
                checkBtn.type = 'button';
                checkBtn.className = 'checklist-checkbox' + (item.is_done ? ' is-checked' : '');
                checkBtn.setAttribute('aria-label', item.is_done ? 'تحديد كغير مكتملة' : 'تحديد كمكتملة');
                checkBtn.disabled = !canEdit;
                checkBtn.innerHTML = item.is_done ? '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>' : '';
                checkBtn.addEventListener('click', () => toggleChecklistItem(item.id));
                itemEl.appendChild(checkBtn);

                // Title rendered with textContent only
                const titleSpan = document.createElement('span');
                titleSpan.className = 'checklist-item-title';
                titleSpan.textContent = item.title;
                itemEl.appendChild(titleSpan);

                // Edit/Delete/Reorder actions if canEdit
                if (canEdit) {
                    const actionsEl = document.createElement('div');
                    actionsEl.className = 'checklist-item-actions';

                    if (index > 0) {
                        const upBtn = document.createElement('button');
                        upBtn.type = 'button';
                        upBtn.className = 'btn-icon btn-sm';
                        upBtn.title = 'تحريك لأعلى';
                        upBtn.setAttribute('aria-label', 'تحريك لأعلى');
                        upBtn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 15 12 9 6 15"></polyline></svg>';
                        upBtn.addEventListener('click', () => moveChecklistItem(items, index, -1));
                        actionsEl.appendChild(upBtn);
                    }

                    if (index < items.length - 1) {
                        const downBtn = document.createElement('button');
                        downBtn.type = 'button';
                        downBtn.className = 'btn-icon btn-sm';
                        downBtn.title = 'تحريك لأسفل';
                        downBtn.setAttribute('aria-label', 'تحريك لأسفل');
                        downBtn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>';
                        downBtn.addEventListener('click', () => moveChecklistItem(items, index, 1));
                        actionsEl.appendChild(downBtn);
                    }

                    const editBtn = document.createElement('button');
                    editBtn.type = 'button';
                    editBtn.className = 'btn-icon btn-sm';
                    editBtn.title = 'تعديل';
                    editBtn.setAttribute('aria-label', 'تعديل المهمة الفرعية');
                    editBtn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>';
                    editBtn.addEventListener('click', () => startEditChecklistItem(itemEl, item));
                    actionsEl.appendChild(editBtn);

                    const delBtn = document.createElement('button');
                    delBtn.type = 'button';
                    delBtn.className = 'btn-icon btn-icon-danger btn-sm';
                    delBtn.title = 'حذف';
                    delBtn.setAttribute('aria-label', 'حذف المهمة الفرعية');
                    delBtn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';
                    delBtn.addEventListener('click', () => deleteChecklistItem(item.id));
                    actionsEl.appendChild(delBtn);

                    itemEl.appendChild(actionsEl);
                }

                drawerChecklistItems.appendChild(itemEl);
            });
        }

        function toggleChecklistItem(itemId) {
            fetch('../task-checklist.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({ action: 'toggle', item_id: itemId, csrf_token: csrfToken })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    fetchChecklist(currentDrawerTaskId);
                }
            });
        }

        function startEditChecklistItem(itemEl, item) {
            const titleSpan = itemEl.querySelector('.checklist-item-title');
            const actionsEl = itemEl.querySelector('.checklist-item-actions');
            if (!titleSpan) return;

            const originalTitle = item.title;
            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'form-control form-control-sm';
            input.maxLength = 200;
            input.value = originalTitle;
            input.style.flex = '1';

            const saveBtn = document.createElement('button');
            saveBtn.type = 'button';
            saveBtn.className = 'btn btn-primary btn-sm';
            saveBtn.textContent = 'حفظ';

            const cancelBtn = document.createElement('button');
            cancelBtn.type = 'button';
            cancelBtn.className = 'btn btn-secondary btn-sm';
            cancelBtn.textContent = 'إلغاء';

            const editWrap = document.createElement('div');
            editWrap.style.display = 'flex';
            editWrap.style.alignItems = 'center';
            editWrap.style.gap = '4px';
            editWrap.style.flex = '1';
            editWrap.appendChild(input);
            editWrap.appendChild(saveBtn);
            editWrap.appendChild(cancelBtn);

            titleSpan.replaceWith(editWrap);
            if (actionsEl) actionsEl.style.display = 'none';

            function doSave() {
                const val = input.value.trim();
                if (!val || val === originalTitle) {
                    editWrap.replaceWith(titleSpan);
                    if (actionsEl) actionsEl.style.display = '';
                    return;
                }
                fetch('../task-checklist.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ action: 'edit', item_id: item.id, title: val, csrf_token: csrfToken })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        fetchChecklist(currentDrawerTaskId);
                    } else {
                        alert(data.message || 'فشل في تعديل المهمة الفرعية');
                        editWrap.replaceWith(titleSpan);
                        if (actionsEl) actionsEl.style.display = '';
                    }
                });
            }

            saveBtn.addEventListener('click', doSave);
            cancelBtn.addEventListener('click', () => {
                editWrap.replaceWith(titleSpan);
                if (actionsEl) actionsEl.style.display = '';
            });
            input.addEventListener('keydown', e => {
                if (e.key === 'Enter') { e.preventDefault(); doSave(); }
                if (e.key === 'Escape') { e.preventDefault(); editWrap.replaceWith(titleSpan); if (actionsEl) actionsEl.style.display = ''; }
            });
            input.focus();
        }

        function deleteChecklistItem(itemId) {
            fetch('../task-checklist.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({ action: 'delete', item_id: itemId, csrf_token: csrfToken })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    fetchChecklist(currentDrawerTaskId);
                }
            });
        }

        function moveChecklistItem(items, idx, delta) {
            const targetIdx = idx + delta;
            const temp = items[idx];
            items[idx] = items[targetIdx];
            items[targetIdx] = temp;
            const ids = items.map(it => it.id);

            fetch('../task-checklist.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({ action: 'reorder', task_id: currentDrawerTaskId, item_ids: ids, csrf_token: csrfToken })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    fetchChecklist(currentDrawerTaskId);
                }
            });
        }

        if (drawerAddChecklistForm) {
            drawerAddChecklistForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const title = newChecklistTitle.value.trim();
                if (!title || !currentDrawerTaskId) return;

                fetch('../task-checklist.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ action: 'add', task_id: currentDrawerTaskId, title: title, csrf_token: csrfToken })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        newChecklistTitle.value = '';
                        if (checklistErrorMsg) { checklistErrorMsg.textContent = ''; checklistErrorMsg.hidden = true; }
                        fetchChecklist(currentDrawerTaskId);
                    } else {
                        if (checklistErrorMsg) {
                            checklistErrorMsg.textContent = data.message || 'فشل في إضافة المهمة الفرعية';
                            checklistErrorMsg.hidden = false;
                        }
                    }
                })
                .catch(() => {
                    if (checklistErrorMsg) {
                        checklistErrorMsg.textContent = 'فشل في الاتصال بالخادم';
                        checklistErrorMsg.hidden = false;
                    }
                });
            });
        }

        if (btnMarkTaskCompletedFromChecklist) {
            btnMarkTaskCompletedFromChecklist.addEventListener('click', function() {
                if (!currentDrawerTaskId) return;
                const formData = new FormData();
                formData.append('task_id', currentDrawerTaskId);
                formData.append('status', 'Completed');
                formData.append('csrf_token', csrfToken);

                fetch('../tasks/update-status.php', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': csrfToken },
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        if (typeof showToast === 'function') {
                            showToast('تم نقل المهمة إلى مكتملة بنجاح!', 'success');
                        }
                        const statusBadge = document.getElementById('drawerTaskStatus');
                        if (statusBadge) {
                            statusBadge.className = 'badge badge-done';
                            statusBadge.textContent = 'مكتملة';
                        }
                        if (checklistDoneSuggestion) {
                            checklistDoneSuggestion.hidden = true;
                        }
                        const card = document.querySelector(`.kanban-card[data-task-id="${currentDrawerTaskId}"]`);
                        if (card) {
                            card.dataset.status = 'Completed';
                            const select = card.querySelector('.task-status-select');
                            if (select) select.value = 'Completed';
                        }
                        if (typeof pollTasks === 'function') {
                            pollTasks();
                        }
                    } else {
                        if (typeof showToast === 'function') {
                            showToast('فشل في تحديث حالة المهمة.', 'error');
                        }
                    }
                })
                .catch(() => {
                    if (typeof showToast === 'function') {
                        showToast('فشل في الاتصال بالخادم.', 'error');
                    }
                });
            });
        }

        // Deep linking: auto-open drawer on page load if ?task=Y is present
        window.addEventListener('DOMContentLoaded', function () {
            const urlParams = new URLSearchParams(window.location.search);
            const deepTaskId = urlParams.get('task');
            if (deepTaskId) {
                const card = document.querySelector(`.kanban-card[data-task-id="${deepTaskId}"]`);
                openTaskDrawer(deepTaskId, card);
            }
        });
    })();
    </script>
</body>
</html>