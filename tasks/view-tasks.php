<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
include '../includes/db.php';

$project_id = $_GET['project_id'] ?? null;
if (!$project_id) {
    die("رقم المشروع غير موجود.");
}

// Handle Add Task
$error = '';
$success = '';
if (isset($_POST['add_task'])) {
    $title = $_POST['title'];
    $description = $_POST['description'];
    $priority = $_POST['priority'];
    $due_date = $_POST['due_date'];
    $assigned_to = $_POST['assigned_to'];
    try {
        $stmt = $pdo->prepare("INSERT INTO tasks (project_id, title, description, priority, due_date, assigned_to, status) VALUES (?, ?, ?, ?, ?, ?, 'Pending')");
        $stmt->execute([$project_id, $title, $description, $priority, $due_date, $assigned_to]);
        $success = "تم إضافة المهمة بنجاح.";
    } catch (PDOException $e) {
        $error = "فشل في إضافة المهمة.";
    }
}

// Handle Edit Task
if (isset($_POST['edit_task'])) {
    $task_id = $_POST['task_id'];
    $title = $_POST['title'];
    $description = $_POST['description'];
    $priority = $_POST['priority'];
    $due_date = $_POST['due_date'];
    $assigned_to = $_POST['assigned_to'];
    try {
        $stmt = $pdo->prepare("UPDATE tasks SET title=?, description=?, priority=?, due_date=?, assigned_to=? WHERE id=?");
        $stmt->execute([$title, $description, $priority, $due_date, $assigned_to, $task_id]);
        $success = "تم تحديث المهمة بنجاح.";
    } catch (PDOException $e) {
        $error = "فشل في تحديث المهمة.";
    }
}

// Handle Delete Task
if (isset($_POST['delete_task'])) {
    $task_id = $_POST['task_id'];
    try {
        $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ?");
        $stmt->execute([$task_id]);
        $success = "تم حذف المهمة بنجاح.";
    } catch (PDOException $e) {
        $error = "فشل في حذف المهمة.";
    }
}

// Get project title
$stmt = $pdo->prepare("SELECT title FROM projects WHERE id = ?");
$stmt->execute([$project_id]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);

// Get all tasks in this project
$stmt = $pdo->prepare("
    SELECT t.*, u.name AS assignee_name 
    FROM tasks t
    LEFT JOIN users u ON t.assigned_to = u.id
    WHERE t.project_id = ?
");
$stmt->execute([$project_id]);
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all users for assignment
$users_stmt = $pdo->query("SELECT id, name FROM users");
$users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

// For edit form
$edit_task = null;
if (isset($_GET['edit_task_id'])) {
    $edit_id = $_GET['edit_task_id'];
    $stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_task = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>مهام المشروع - <?= htmlspecialchars($project['title']) ?></title>
    <link rel="stylesheet" href="../css/styles.css">
    <style>
        .pro-form-container {
            background: rgba(255,255,255,0.07);
            border-radius: 24px;
            box-shadow: 0 8px 32px 0 rgba(31,38,135,0.37);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            /* border: 1px solid rgba(255,255,255,0.18); */
            padding: 36px 28px 28px 28px;
            text-align: center;
            /* max-width: 420px; */
            width: 100%;
            margin: 0 auto 32px auto;
            animation: fadeInUp 1s cubic-bezier(.39,.575,.565,1.000) both;
        }
        .popup-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(30, 42, 60, 0.85);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(2px);
            transition: background 0.3s;
        }
        .popup-overlay.active {
            display: flex;
        }
        .popup-content {
            background: linear-gradient(135deg, rgba(255,255,255,0.18) 0%, rgba(26,188,156,0.10) 100%);
            border-radius: 28px;
            box-shadow: 0 12px 40px 0 rgba(31,38,135,0.25);
            padding: 0;
            max-width: 440px;
            width: 100%;
            position: relative;
            animation: fadeInUp 0.7s cubic-bezier(.39,.575,.565,1.000);
            border: 1.5px solid rgba(26,188,156,0.18);
            overflow: auto;
            max-height: 90vh;
            min-height: unset;
        }
        @media (max-width: 600px) {
            .popup-content {
                max-width: 98vw;
                padding: 0;
                border-radius: 16px;
            }
            .pro-form-container {
                padding: 32px 8px 24px 8px;
            }
        }
        .popup-close {
            position: absolute;
            top: 18px;
            left: 18px;
            background: linear-gradient(90deg, #e74c3c 0%, #c0392b 100%);
            color: #fff;
            border: none;
            border-radius: 50%;
            width: 36px;
            height: 36px;
            font-size: 1.3rem;
            cursor: pointer;
            z-index: 10;
            box-shadow: 0 2px 8px rgba(231,76,60,0.15);
            transition: background 0.2s, transform 0.2s;
        }
        .popup-close:hover {
            background: linear-gradient(90deg, #c0392b 0%, #e74c3c 100%);
            transform: scale(1.08);
        }
        .pro-form-container {
            background: transparent;
            box-shadow: none;
            border-radius: 0;
            padding: 48px 32px 32px 32px;
            margin: 0;
        }
        .pro-form-container h3 {
            color: #1abc9c;
            margin-bottom: 18px;
            font-size: 1.4rem;
            text-shadow: 0 2px 8px rgba(26,188,156,0.18);
            letter-spacing: 1px;
        }
        .pro-form-container label {
            color: #fff;
            display: block;
            text-align: right;
            margin-bottom: 6px;
            font-size: 1rem;
            opacity: 0.9;
        }
        .pro-form-container input,
        .pro-form-container textarea,
        .pro-form-container select {
            width: 100%;
            padding: 13px;
            margin-bottom: 18px;
            border: none;
            border-radius: 10px;
            background: rgba(255,255,255,0.22);
            color: #232526;
            font-size: 1.05rem;
            transition: box-shadow 0.2s, background 0.2s;
            box-shadow: 0 2px 8px rgba(26,188,156,0.07);
        }
        .pro-form-container input:focus,
        .pro-form-container textarea:focus,
        .pro-form-container select:focus {
            outline: none;
            box-shadow: 0 0 0 2px #1abc9c, 0 2px 8px rgba(26,188,156,0.13);
            background: rgba(255,255,255,0.32);
        }
        .pro-form-container button[type="submit"] {
            width: 100%;
            background: linear-gradient(90deg, #1abc9c 0%, #16a085 100%);
            color: #fff;
            padding: 13px 0;
            border: none;
            border-radius: 10px;
            font-size: 1.13rem;
            font-weight: bold;
            cursor: pointer;
            box-shadow: 0 4px 16px rgba(26,188,156,0.15);
            transition: transform 0.2s, box-shadow 0.2s;
            margin-top: 8px;
        }
        .pro-form-container button[type="submit"]:hover {
            transform: translateY(-2px) scale(1.03);
            box-shadow: 0 8px 24px rgba(26,188,156,0.25);
        }
        .pro-form-container .btn {
            width: auto;
            display: inline-block;
            margin-top: 10px;
            background: linear-gradient(90deg, #3498db 0%, #2980b9 100%);
            color: #fff;
            padding: 8px 22px;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: bold;
            text-decoration: none;
            transition: background 0.2s, transform 0.2s;
            box-shadow: 0 2px 8px rgba(52,152,219,0.10);
        }
        .pro-form-container .btn:hover {
            background: linear-gradient(90deg, #2980b9 0%, #3498db 100%);
            transform: translateY(-2px) scale(1.04);
        }
        .tasks-kanban {
            margin-top: 32px;
        }
        .kanban-board {
            display: flex;
            /* justify-content: space-between; */
            flex-wrap: nowrap;
            overflow-x: auto;
            padding: 0 16px;
        }
        .kanban-column {
            background: rgba(255,255,255,0.1);
            border-radius: 12px;
            padding: 16px;
            /* margin-right: 16px; */
            /* min-width: 280px; */
            flex: 0 0 auto;
            box-shadow: 0 4px 16px rgba(0,0,0,0.1);
        }
        .kanban-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        .kanban-header span {
            font-size: 1.2rem;
            font-weight: bold;
            color: #333;
        }
        .kanban-count {
            background: #1abc9c;
            color: #fff;
            border-radius: 12px;
            padding: 4px 8px;
            font-size: 0.9rem;
            font-weight: bold;
        }
        .kanban-card {
            background: rgba(255,255,255,0.15);
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 12px;
            transition: transform 0.2s, box-shadow 0.2s;
            position: relative;
            box-shadow: 0 4px 16px rgba(0,0,0,0.1);
        }
        .kanban-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.15);
        }
        .kanban-card-title {
            font-size: 1.1rem;
            font-weight: bold;
            margin-bottom: 8px;
            color: #1abc9c;
        }
        .kanban-card-title .priority-sign {
            margin-right: 6px;
            font-size: 1.15em;
            vertical-align: middle;
            font-weight: bold;
            opacity: 0.85;
        }
        .kanban-card-title .priority-sign.high { color: #e74c3c; }
        .kanban-card-title .priority-sign.medium { color: #f1c40f; }
        .kanban-card-title .priority-sign.low { color: #3498db; }
        .kanban-card-assignee,
        .kanban-card-desc {
            font-size: 0.9rem;
            margin-bottom: 8px;
            color: #555;
        }
        .kanban-card-status {
            margin-bottom: 12px;
        }
        .kanban-card-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.8rem;
            color: #777;
        }
        .kanban-card-actions {
            display: flex;
            gap: 8px;
        }
        .kanban-delete {
            background: none;
            border: none;
            cursor: pointer;
            transition: transform 0.2s;
        }
        .kanban-delete:hover {
            transform: scale(1.1);
        }
        @keyframes fadeInUp {
            0% { opacity: 0; transform: translateY(40px); }
            100% { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>

<svg style="display:none">
    <symbol id="icon-edit" viewBox="0 0 24 24">
        <path d="M3 17.25V21h3.75l11.06-11.06-3.75-3.75L3 17.25zm14.71-9.04a1.003 1.003 0 0 0 0-1.42l-2.5-2.5a1.003 1.003 0 0 0-1.42 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/>
    </symbol>
    <symbol id="icon-trash" viewBox="0 0 24 24">
        <path d="M3 6h18v2H3V6zm2 3h14l-1.5 12.5c-.1.8-.8 1.5-1.6 1.5H8.1c-.8 0-1.5-.7-1.6-1.5L5 9zm3 2v8h2v-8H8zm4 0v8h2v-8h-2z"/>
    </symbol>
</svg>

<header>
    <h1>مشروع: <?= htmlspecialchars($project['title']) ?></h1>
    <div class="header-actions">
        <button class="add-task-btn" id="openAddTask">+ إضافة مهمة</button>
        <a href="../dashboard.php" class="btn btn-back">← العودة للوحة التحكم</a>
    </div>
</header>

<main>
    <section class="tasks">
        <h2>قائمة المهام</h2>
        <?php if ($error): ?>
            <p class="error"><?= $error ?></p>
        <?php elseif ($success): ?>
            <p class="success"><?= $success ?></p>
        <?php endif; ?>

        <!-- Add/Edit Task Popup -->
        <div class="popup-overlay" id="taskPopup">
            <div class="popup-content">
                <button class="popup-close" id="closePopup" style="padding:0">×</button>
                <div class="pro-form-container" style="margin-bottom:0;box-shadow:none;">
                    <h3><?= $edit_task ? 'تعديل مهمة' : 'إضافة مهمة جديدة' ?></h3>
                    <form method="POST" id="taskForm">
                        <?php if ($edit_task): ?>
                            <input type="hidden" name="task_id" value="<?= $edit_task['id'] ?>">
                        <?php endif; ?>
                        <label>عنوان المهمة:</label>
                        <input type="text" name="title" value="<?= $edit_task['title'] ?? '' ?>" required>
                        <label>الوصف:</label>
                        <textarea name="description" rows="4"><?= $edit_task['description'] ?? '' ?></textarea>
                        <label>الأولوية:</label>
                        <select name="priority" required>
                            <option value="Low" <?= (isset($edit_task) && $edit_task['priority']=='Low') ? 'selected' : '' ?>>منخفضة</option>
                            <option value="Medium" <?= (isset($edit_task) && $edit_task['priority']=='Medium') ? 'selected' : '' ?>>متوسطة</option>
                            <option value="High" <?= (isset($edit_task) && $edit_task['priority']=='High') ? 'selected' : '' ?>>عالية</option>
                        </select>
                        <label>تاريخ الاستحقاق:</label>
                        <input type="date" name="due_date" value="<?= $edit_task['due_date'] ?? '' ?>" required>
                        <label>تعيين إلى:</label>
                        <select name="assigned_to" required>
                            <?php foreach ($users as $user_option): ?>
                                <option value="<?= $user_option['id'] ?>" <?= (isset($edit_task) && $edit_task['assigned_to']==$user_option['id']) ? 'selected' : '' ?>><?= htmlspecialchars($user_option['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" name="<?= $edit_task ? 'edit_task' : 'add_task' ?>">
                            <?= $edit_task ? 'تحديث المهمة' : 'حفظ المهمة' ?>
                        </button>
                        <?php if ($edit_task): ?>
                            <a href="view-tasks.php?project_id=<?= $project_id ?>" class="btn" style="margin-top:10px;display:inline-block;">إلغاء التعديل</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>

        <script>
        // Popup logic
        const popup = document.getElementById('taskPopup');
        const openBtn = document.getElementById('openAddTask');
        const closeBtn = document.getElementById('closePopup');
        <?php if (!$edit_task): ?>
        openBtn.addEventListener('click', function() {
            popup.classList.add('active');
        });
        closeBtn.addEventListener('click', function() {
            popup.classList.remove('active');
        });
        window.addEventListener('click', function(e) {
            if (e.target === popup) popup.classList.remove('active');
        });
        // ESC key closes popup
        window.addEventListener('keydown', function(e) {
            if (popup.classList.contains('active') && e.key === 'Escape') {
                popup.classList.remove('active');
            }
        });
        <?php else: ?>
        // If editing, open popup automatically
        popup.classList.add('active');
        closeBtn.addEventListener('click', function() {
            popup.classList.remove('active');
            window.location.href = 'view-tasks.php?project_id=<?= $project_id ?>';
        });
        window.addEventListener('click', function(e) {
            if (e.target === popup) {
                popup.classList.remove('active');
                window.location.href = 'view-tasks.php?project_id=<?= $project_id ?>';
            }
        });
        // ESC key closes popup and returns to main view
        window.addEventListener('keydown', function(e) {
            if (popup.classList.contains('active') && e.key === 'Escape') {
                popup.classList.remove('active');
                window.location.href = 'view-tasks.php?project_id=<?= $project_id ?>';
            }
        });
        <?php endif; ?>
        </script>

        <section class="tasks-kanban">
            <div class="kanban-board">
                <div class="kanban-column todo">
                    <div class="kanban-header">
                        <span>To Do</span>
                        <span class="kanban-count"><?= count(array_filter($tasks, fn($t) => $t['status'] == 'Pending')) ?></span>
                    </div>
                    <?php foreach ($tasks as $task): if ($task['status'] !== 'Pending') continue; ?>
                    <div class="kanban-card">
                        <div class="kanban-card-title">
                            <strong><?= htmlspecialchars($task['title']) ?></strong>
                            <span class="priority-sign <?= strtolower($task['priority']) ?>" title="الأولوية: <?= htmlspecialchars($task['priority']) ?>">
                                <?php if ($task['priority'] == 'High'): ?>
                                    &#9888;
                                <?php elseif ($task['priority'] == 'Medium'): ?>
                                    &#9733;
                                <?php else: ?>
                                    &#9675;
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="kanban-card-assignee">Assignee: <?= htmlspecialchars($task['assignee_name'] ?? 'No one') ?></div>
                        <div class="kanban-card-desc"><?= htmlspecialchars($task['description']) ?></div>
                        <div class="kanban-card-status">
                            <select class="task-status" data-task-id="<?= $task['id'] ?>">
                                <option value="Pending" <?= $task['status'] == 'Pending' ? 'selected' : '' ?>>To Do</option>
                                <option value="In Progress" <?= $task['status'] == 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                                <option value="Completed" <?= $task['status'] == 'Completed' ? 'selected' : '' ?>>Done</option>
                            </select>
                        </div>
                        <div class="kanban-card-footer">
                            <span>Created: <?= date('n/j/Y', strtotime($task['created_at'] ?? $task['due_date'])) ?></span>
                            <div class="kanban-card-actions">
                                <a href="view-tasks.php?project_id=<?= $project_id ?>&edit_task_id=<?= $task['id'] ?>" title="Edit"><svg width="18" height="18" fill="#888"><use href="#icon-edit"/></svg></a>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                                    <button type="submit" name="delete_task" class="kanban-delete" title="Delete" onclick="return confirm('Delete this task?')"><svg width="18" height="18" fill="#e74c3c"><use href="#icon-trash"/></svg></button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="kanban-column inprogress">
                    <div class="kanban-header">
                        <span>In Progress</span>
                        <span class="kanban-count"><?= count(array_filter($tasks, fn($t) => $t['status'] == 'In Progress')) ?></span>
                    </div>
                    <?php foreach ($tasks as $task): if ($task['status'] !== 'In Progress') continue; ?>
                    <div class="kanban-card">
                        <div class="kanban-card-title">
                            <strong><?= htmlspecialchars($task['title']) ?></strong>
                            <span class="priority-sign <?= strtolower($task['priority']) ?>" title="الأولوية: <?= htmlspecialchars($task['priority']) ?>">
                                <?php if ($task['priority'] == 'High'): ?>
                                    &#9888;
                                <?php elseif ($task['priority'] == 'Medium'): ?>
                                    &#9733;
                                <?php else: ?>
                                    &#9675;
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="kanban-card-assignee">Assignee: <?= htmlspecialchars($task['assignee_name'] ?? 'No one') ?></div>
                        <div class="kanban-card-desc"><?= htmlspecialchars($task['description']) ?></div>
                        <div class="kanban-card-status">
                            <select class="task-status" data-task-id="<?= $task['id'] ?>">
                                <option value="Pending" <?= $task['status'] == 'Pending' ? 'selected' : '' ?>>To Do</option>
                                <option value="In Progress" <?= $task['status'] == 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                                <option value="Completed" <?= $task['status'] == 'Completed' ? 'selected' : '' ?>>Done</option>
                            </select>
                        </div>
                        <div class="kanban-card-footer">
                            <span>Created: <?= date('n/j/Y', strtotime($task['created_at'] ?? $task['due_date'])) ?></span>
                            <div class="kanban-card-actions">
                                <a href="view-tasks.php?project_id=<?= $project_id ?>&edit_task_id=<?= $task['id'] ?>" title="Edit"><svg width="18" height="18" fill="#888"><use href="#icon-edit"/></svg></a>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                                    <button type="submit" name="delete_task" class="kanban-delete" title="Delete" onclick="return confirm('Delete this task?')"><svg width="18" height="18" fill="#e74c3c"><use href="#icon-trash"/></svg></button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="kanban-column done">
                    <div class="kanban-header">
                        <span>Done</span>
                        <span class="kanban-count"><?= count(array_filter($tasks, fn($t) => $t['status'] == 'Completed')) ?></span>
                    </div>
                    <?php foreach ($tasks as $task): if ($task['status'] !== 'Completed') continue; ?>
                    <div class="kanban-card">
                        <div class="kanban-card-title">
                            <strong><?= htmlspecialchars($task['title']) ?></strong>
                            <span class="priority-sign <?= strtolower($task['priority']) ?>" title="الأولوية: <?= htmlspecialchars($task['priority']) ?>">
                                <?php if ($task['priority'] == 'High'): ?>
                                    &#9888;
                                <?php elseif ($task['priority'] == 'Medium'): ?>
                                    &#9733;
                                <?php else: ?>
                                    &#9675;
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="kanban-card-assignee">Assignee: <?= htmlspecialchars($task['assignee_name'] ?? 'No one') ?></div>
                        <div class="kanban-card-desc"><?= htmlspecialchars($task['description']) ?></div>
                        <div class="kanban-card-status">
                            <select class="task-status" data-task-id="<?= $task['id'] ?>">
                                <option value="Pending" <?= $task['status'] == 'Pending' ? 'selected' : '' ?>>To Do</option>
                                <option value="In Progress" <?= $task['status'] == 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                                <option value="Completed" <?= $task['status'] == 'Completed' ? 'selected' : '' ?>>Done</option>
                            </select>
                        </div>
                        <div class="kanban-card-footer">
                            <span>Created: <?= date('n/j/Y', strtotime($task['created_at'] ?? $task['due_date'])) ?></span>
                            <div class="kanban-card-actions">
                                <a href="view-tasks.php?project_id=<?= $project_id ?>&edit_task_id=<?= $task['id'] ?>" title="Edit"><svg width="18" height="18" fill="#888"><use href="#icon-edit"/></svg></a>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                                    <button type="submit" name="delete_task" class="kanban-delete" title="Delete" onclick="return confirm('Delete this task?')"><svg width="18" height="18" fill="#e74c3c"><use href="#icon-trash"/></svg></button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    </section>
</main>

<script src="../js/main.js"></script>
<script>
// Move task on status change (AJAX)
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.task-status').forEach(function(select) {
        select.addEventListener('change', function() {
            const taskId = this.dataset.taskId;
            const newStatus = this.value;
            const formData = new FormData();
            formData.append('task_id', taskId);
            formData.append('status', newStatus);
            fetch('../tasks/update-status.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    // Show notification
                    const notif = document.createElement('div');
                    notif.textContent = 'تم تحديث حالة المهمة!';
                    notif.style.position = 'fixed';
                    notif.style.top = '32px';
                    notif.style.left = '50%';
                    notif.style.transform = 'translateX(-50%)';
                    notif.style.background = '#1abc9c';
                    notif.style.color = '#fff';
                    notif.style.padding = '12px 32px';
                    notif.style.borderRadius = '8px';
                    notif.style.fontSize = '1.1rem';
                    notif.style.boxShadow = '0 2px 12px rgba(26,188,156,0.13)';
                    notif.style.zIndex = 9999;
                    document.body.appendChild(notif);
                    setTimeout(() => notif.remove(), 1500);
                    // Move task card to new column
                    const card = this.closest('.kanban-card');
                    const board = card.closest('.kanban-board');
                    const newCol = board.querySelector('.kanban-column.' + (newStatus === 'Pending' ? 'todo' : newStatus === 'In Progress' ? 'inprogress' : 'done'));
                    if (newCol) newCol.appendChild(card);
                } else {
                    alert('فشل في تحديث الحالة!');
                }
            })
            .catch(() => alert('فشل في الاتصال بالخادم!'));
        });
    });
});
</script>

<?php if ($success && !$edit_task): ?>
<script>
// Close popup after add
document.addEventListener('DOMContentLoaded', function() {
    const popup = document.getElementById('taskPopup');
    if (popup) popup.classList.remove('active');
});
</script>
<?php elseif ($success && $edit_task): ?>
<script>
// Close popup and reload after edit
document.addEventListener('DOMContentLoaded', function() {
    const popup = document.getElementById('taskPopup');
    if (popup) popup.classList.remove('active');
    window.location.href = 'view-tasks.php?project_id=<?= $project_id ?>';
});
</script>
<?php endif; ?>

</body>
</html>