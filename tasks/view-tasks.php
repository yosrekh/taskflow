<?php
require_once __DIR__ . '/../includes/auth.php';
require_login('../');
require_once __DIR__ . '/../includes/db.php';

$base = '../';
$project_id = $_GET['project_id'] ?? null;
if (!$project_id) {
    http_response_code(400);
    die("رقم المشروع غير موجود.");
}

$user_id = (int)$_SESSION['user_id'];

// Load project data at the TOP
$stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
$stmt->execute([$project_id]);
$project = $stmt->fetch();

if (!$project || !can_view_project($pdo, $user_id, $project_id)) {
    http_response_code(404);
    die("المشروع غير موجود.");
}

$project_owner_id = (int)$project['user_id'];
$is_project_owner = can_manage_project($pdo, $user_id, $project_id);

// Handle Task Actions (POST)
$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = "رمز التحقق غير صالح. يرجى إعادة المحاولة.";
    } elseif (isset($_POST['add_task'])) {
        if (!$is_project_owner) {
            http_response_code(403);
            die("غير مصرح لك بإضافة مهام في هذا المشروع.");
        }
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $priority = $_POST['priority'] ?? 'Medium';
        $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
        $assigned_to = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;
        try {
            $stmt = $pdo->prepare("INSERT INTO tasks (project_id, title, description, priority, due_date, assigned_to, status) VALUES (?, ?, ?, ?, ?, ?, 'Pending')");
            $stmt->execute([$project_id, $title, $description, $priority, $due_date, $assigned_to]);
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
    } elseif (isset($_POST['edit_task'])) {
        if (!$is_project_owner) {
            http_response_code(403);
            die("غير مصرح لك بتعديل هذه المهمة.");
        }
        $task_id = $_POST['task_id'] ?? null;
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $priority = $_POST['priority'] ?? 'Medium';
        $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
        $assigned_to = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;
        try {
            $old_stmt = $pdo->prepare("SELECT assigned_to, title FROM tasks WHERE id = ? AND project_id = ?");
            $old_stmt->execute([$task_id, $project_id]);
            $old_task = $old_stmt->fetch();
            $old_assigned_to = $old_task ? $old_task['assigned_to'] : null;

            $stmt = $pdo->prepare("UPDATE tasks SET title=?, description=?, priority=?, due_date=?, assigned_to=? WHERE id=? AND project_id=?");
            $stmt->execute([$title, $description, $priority, $due_date, $assigned_to, $task_id, $project_id]);
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
    } elseif (isset($_POST['delete_task'])) {
        if (!$is_project_owner) {
            http_response_code(403);
            die("غير مصرح لك بحذف هذه المهمة.");
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
if ($is_project_owner) {
    $stmt = $pdo->prepare("
        SELECT t.*, u.name AS assignee_name 
        FROM tasks t
        LEFT JOIN users u ON t.assigned_to = u.id
        WHERE t.project_id = ?
        ORDER BY t.created_at DESC
    ");
    $stmt->execute([$project_id]);
    $tasks = $stmt->fetchAll();
} else {
    $stmt = $pdo->prepare("
        SELECT t.*, u.name AS assignee_name 
        FROM tasks t
        LEFT JOIN users u ON t.assigned_to = u.id
        WHERE t.project_id = ? AND t.assigned_to = ?
        ORDER BY t.created_at DESC
    ");
    $stmt->execute([$project_id, $user_id]);
    $tasks = $stmt->fetchAll();
}

// Get all active users for assignment
$users_stmt = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name ASC");
$users = $users_stmt->fetchAll();

// For edit form
$edit_task = null;
if (isset($_GET['edit_task_id'])) {
    if (!$is_project_owner) {
        http_response_code(403);
        die("غير مصرح لك بتعديل هذه المهمة.");
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
    <div class="kanban-card" data-task-id="<?= (int)$task['id'] ?>">
        <div class="kanban-card-title"><?= htmlspecialchars($task['title']) ?></div>
        <?php if (!empty($task['description'])): ?>
            <div class="kanban-card-desc"><?= htmlspecialchars($task['description']) ?></div>
        <?php endif; ?>

        <div class="kanban-card-meta">
            <span class="badge <?= $priorityClass ?>"><?= $priorityLabel ?></span>

            <?php if (!empty($task['due_date'])): ?>
                <span class="kanban-due-date <?= $isOverdue ? 'is-overdue' : '' ?>" title="<?= $isOverdue ? 'متأخرة عن موعدها' : 'تاريخ الاستحقاق' ?>">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                    <?= htmlspecialchars($task['due_date']) ?>
                    <?= $isOverdue ? '(متأخرة)' : '' ?>
                </span>
            <?php endif; ?>
        </div>

        <div class="form-group" style="margin-block-end: var(--space-3);">
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
                    <span style="font-size:var(--font-size-xs);color:var(--text-secondary);"><?= htmlspecialchars($assigneeName) ?></span>
                <?php else: ?>
                    <span style="font-size:var(--font-size-xs);color:var(--text-muted);">غير مسندة</span>
                <?php endif; ?>
            </div>

            <?php if ($is_project_owner): ?>
                <div class="kanban-card-actions">
                    <a href="view-tasks.php?project_id=<?= (int)$project_id ?>&edit_task_id=<?= (int)$task['id'] ?>" class="btn-icon" aria-label="تعديل المهمة '<?= htmlspecialchars($task['title']) ?>'" title="تعديل">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                    </a>
                    <form method="POST" style="margin:0;display:inline;">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>">
                        <button type="submit" name="delete_task" class="btn-icon btn-icon-danger" aria-label="حذف المهمة '<?= htmlspecialchars($task['title']) ?>'" title="حذف" onclick="return confirm('هل أنت متأكد من حذف هذه المهمة؟');">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        </button>
                    </form>
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
                <a href="../dashboard.php" class="btn-ghost btn-sm" style="display:inline-flex;margin-block-end:var(--space-2);width:fit-content;">
                    ← العودة إلى لوحة التحكم
                </a>
                <h1 class="page-title"><?= htmlspecialchars($project['title']) ?></h1>
                <p class="page-subtitle"><?= htmlspecialchars($project['description'] ?? 'لوحة متابعة مهام المشروع') ?></p>
            </div>
            <div class="page-actions">
                <?php if ($is_project_owner): ?>
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

        <!-- Kanban Board: Arabic Columns (للتنفيذ, قيد التنفيذ, مكتملة) -->
        <?php
        $todoTasks = array_values(array_filter($tasks, fn($t) => $t['status'] === 'Pending'));
        $progressTasks = array_values(array_filter($tasks, fn($t) => $t['status'] === 'In Progress'));
        $doneTasks = array_values(array_filter($tasks, fn($t) => $t['status'] === 'Completed'));
        ?>

        <div class="kanban-board-container">
            <div class="kanban-board">
                <!-- Column: Pending -->
                <section class="kanban-column col-todo" data-status="Pending">
                    <header class="kanban-column-header">
                        <div class="kanban-column-title">
                            <span style="color:var(--navy-600);font-size:1.2rem;">●</span>
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
                            <span style="color:var(--teal-600);font-size:1.2rem;">●</span>
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
                            <span style="color:var(--green-600);font-size:1.2rem;">●</span>
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

                        <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4);">
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
                                <input type="date" id="task_due_date" name="due_date" class="form-control" value="<?= htmlspecialchars($edit_task['due_date'] ?? '') ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="task_assigned_to" class="form-label">المسؤول عن المهمة</label>
                            <select id="task_assigned_to" name="assigned_to" class="form-select">
                                <option value="">-- بدون إسناد --</option>
                                <?php foreach ($users as $u): ?>
                                    <option value="<?= (int)$u['id'] ?>" <?= (isset($edit_task) && (int)$edit_task['assigned_to'] === (int)$u['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($u['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
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
            const parts = name.trim().split(/\s+/);
            if (parts.length >= 2) {
                return parts[0].charAt(0) + parts[1].charAt(0);
            }
            return parts[0].slice(0, 2);
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

        // Polling logic (10 seconds, pause on hidden)
        function renderCardHtml(task) {
            const todayStr = new Date().toISOString().split('T')[0];
            const isOverdue = (task.due_date && task.status !== 'Completed' && task.due_date < todayStr);
            const priorityClass = task.priority === 'High' ? 'badge-high' : (task.priority === 'Low' ? 'badge-low' : 'badge-medium');
            const priorityLabel = task.priority === 'High' ? 'عالية' : (task.priority === 'Low' ? 'منخفضة' : 'متوسطة');
            const assigneeName = task.assignee_name || '';
            const assigneeInitials = getInitials(assigneeName);

            let dueDateHtml = '';
            if (task.due_date) {
                const overdueClass = isOverdue ? 'is-overdue' : '';
                const overdueNote = isOverdue ? '(متأخرة)' : '';
                dueDateHtml = `
                    <span class="kanban-due-date ${overdueClass}">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                        ${escapeHtml(task.due_date)} ${overdueNote}
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
                        <form method="POST" style="margin:0;display:inline;">
                            <input type="hidden" name="csrf_token" value="${escapeHtml(csrfToken)}">
                            <input type="hidden" name="task_id" value="${escapeHtml(task.id)}">
                            <button type="submit" name="delete_task" class="btn-icon btn-icon-danger" aria-label="حذف المهمة" title="حذف" onclick="return confirm('هل أنت متأكد من حذف هذه المهمة؟');">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                            </button>
                        </form>
                    </div>
                `;
            }

            const assigneeHtml = assigneeName ? `
                <span class="avatar avatar-sm" title="${escapeHtml(assigneeName)}">${escapeHtml(assigneeInitials)}</span>
                <span style="font-size:var(--font-size-xs);color:var(--text-secondary);">${escapeHtml(assigneeName)}</span>
            ` : `<span style="font-size:var(--font-size-xs);color:var(--text-muted);">غير مسندة</span>`;

            return `
                <div class="kanban-card" data-task-id="${escapeHtml(task.id)}">
                    <div class="kanban-card-title">${escapeHtml(task.title)}</div>
                    ${descHtml}
                    <div class="kanban-card-meta">
                        <span class="badge ${priorityClass}">${priorityLabel}</span>
                        ${dueDateHtml}
                    </div>
                    <div class="form-group" style="margin-block-end: var(--space-3);">
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
        }

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
</body>
</html>