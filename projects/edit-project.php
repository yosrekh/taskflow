<?php
require_once __DIR__ . '/../includes/auth.php';
require_login('../');
require_once __DIR__ . '/../includes/db.php';

$base = '../';
$project_id = $_GET['id'] ?? null;

if (!$project_id) {
    render_error(400, "رقم المشروع غير موجود.");
}

$user_id = (int)$_SESSION['user_id'];

// Fetch project data
$stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
$stmt->execute([$project_id]);
$project = $stmt->fetch();

if (!$project || !can_view_project($pdo, $user_id, $project_id)) {
    render_error(404, "المشروع غير موجود.");
}

if (!can_manage_project($pdo, $user_id, $project_id)) {
    render_error(403, "غير مصرح لك بتعديل هذا المشروع.");
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = "رمز التحقق غير صالح. يرجى إعادة المحاولة.";
    } else {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if (empty($title)) {
            $error = "يرجى إدخال عنوان للمشروع.";
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE projects SET title = ?, description = ? WHERE id = ?");
                $stmt->execute([$title, $description, $project_id]);
                $project['title'] = $title;
                $project['description'] = $description;
                $success = "تم تحديث المشروع بنجاح.";
                
                // Notify owner if actor is not the owner
                $owner_id = (int)$project['user_id'];
                if ($owner_id !== $user_id) {
                    $actor_stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
                    $actor_stmt->execute([$user_id]);
                    $actor_name = $actor_stmt->fetchColumn() ?: 'المسؤول';
                    $action_time = date('Y-m-d H:i');
                    $msg = "[{$action_time}] {$actor_name} عدّل المشروع '{$title}'";
                    $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)")->execute([$owner_id, $msg]);
                }
            } catch (PDOException $e) {
                error_log("Edit project error: " . $e->getMessage());
                $error = "فشل في تحديث المشروع.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <?php
    $page_title = 'تعديل المشروع: ' . $project['title'];
    include __DIR__ . '/../includes/header-meta.php';
    ?>
</head>
<body>
    <?php
    include __DIR__ . '/../includes/nav.php';
    render_nav($base);
    ?>

    <main>
        <div class="form-page-container">
            <header class="page-header">
                <div class="page-title-wrap">
                    <a href="../dashboard.php" class="btn-ghost btn-sm btn-back-link">
                        ← العودة إلى لوحة التحكم
                    </a>
                    <h1 class="page-title">تعديل المشروع</h1>
                    <p class="page-subtitle">تعديل بيانات وإعدادات المشروع</p>
                </div>
            </header>

            <?php if ($error): ?>
                <div class="alert alert-error" role="alert">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="flex-shrink-0"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php elseif ($success): ?>
                <div class="alert alert-success" role="alert">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="flex-shrink-0"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    <span><?= htmlspecialchars($success) ?></span>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-body">
                    <form method="POST" action="edit-project.php?id=<?= (int)$project_id ?>">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                        <div class="form-group">
                            <label for="title" class="form-label">عنوان المشروع <span class="required">*</span></label>
                            <input type="text" id="title" name="title" class="form-control" required value="<?= htmlspecialchars($project['title']) ?>">
                        </div>

                        <div class="form-group">
                            <label for="description" class="form-label">وصف المشروع</label>
                            <textarea id="description" name="description" class="form-textarea" rows="4"><?= htmlspecialchars($project['description'] ?? '') ?></textarea>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">حفظ التعديلات</button>
                            <a href="../dashboard.php" class="btn btn-secondary">إلغاء</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>
</body>
</html>