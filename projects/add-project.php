<?php
require_once __DIR__ . '/../includes/auth.php';
require_login('../');
require_once __DIR__ . '/../includes/db.php';

$base = '../';
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = "رمز التحقق غير صالح. يرجى إعادة المحاولة.";
    } else {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $user_id = (int)$_SESSION['user_id'];

        if (empty($title)) {
            $error = "يرجى إدخال عنوان للمشروع.";
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO projects (user_id, title, description) VALUES (?, ?, ?)");
                $stmt->execute([$user_id, $title, $description]);
                $new_proj_id = $pdo->lastInsertId();
                $success = "تم إنشاء المشروع بنجاح.";
                
                // Notify owner
                $msg = "تم إنشاء مشروع جديد '{$title}'";
                $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)")->execute([$user_id, $msg]);

                header("Location: ../dashboard.php?msg=project_created");
                exit;
            } catch (PDOException $e) {
                error_log("Add project error: " . $e->getMessage());
                $error = "فشل في إنشاء المشروع.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <?php
    $page_title = 'إنشاء مشروع جديد';
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
                    <a href="../dashboard.php" class="btn-ghost btn-sm" style="display:inline-flex;margin-block-end:var(--space-2);width:fit-content;">
                        ← العودة إلى لوحة التحكم
                    </a>
                    <h1 class="page-title">إنشاء مشروع جديد</h1>
                    <p class="page-subtitle">أدخل تفاصيل المشروع لتنظيم المهام وتعيين المسؤوليات</p>
                </div>
            </header>

            <?php if ($error): ?>
                <div class="alert alert-error" role="alert">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-body">
                    <form method="POST" action="add-project.php">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                        <div class="form-group">
                            <label for="title" class="form-label">عنوان المشروع <span class="required">*</span></label>
                            <input type="text" id="title" name="title" class="form-control" required autofocus placeholder="مثال: تطوير المنصة الرقمية" value="<?= htmlspecialchars($_POST['title'] ?? '') ?>">
                        </div>

                        <div class="form-group">
                            <label for="description" class="form-label">وصف المشروع</label>
                            <textarea id="description" name="description" class="form-textarea" rows="4" placeholder="أهداف المشروع ومتطلباته العامة..."><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                            <span class="form-hint">اختياري، يساعد فريق العمل على فهم سياق المشروع.</span>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">حفظ وإنشاء المشروع</button>
                            <a href="../dashboard.php" class="btn btn-secondary">إلغاء</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>
</body>
</html>