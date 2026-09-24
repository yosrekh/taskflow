<?php
// change-password.php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

require_login();

$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT password, must_change_password FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

$is_forced = ((int)$user['must_change_password'] === 1);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = "رمز التحقق غير صالح. يرجى إعادة المحاولة.";
    } else {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (!$is_forced) {
            if (empty($current_password) || !password_verify($current_password, $user['password'])) {
                $error = "كلمة المرور الحالية غير صحيحة.";
            } elseif ($current_password === $new_password) {
                $error = "يجب أن تكون كلمة المرور الجديدة مختلفة عن كلمة المرور الحالية.";
            }
        } else {
            if (password_verify($new_password, $user['password'])) {
                $error = "يجب أن تكون كلمة المرور الجديدة مختلفة عن كلمة المرور الحالية.";
            }
        }

        if (!$error) {
            if (mb_strlen($new_password) < 10) {
                $error = "يجب ألا تقل كلمة المرور الجديدة عن 10 أحرف.";
            } elseif ($new_password !== $confirm_password) {
                $error = "كلمة المرور الجديدة وتأكيدها غير متطابقين.";
            } else {
                try {
                    $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                    $updateStmt = $pdo->prepare("
                        UPDATE users 
                        SET password = ?, must_change_password = 0, password_changed_at = NOW() 
                        WHERE id = ?
                    ");
                    $updateStmt->execute([$hashed, $user_id]);

                    session_regenerate_id(true);
                    $_SESSION['login_time'] = (int)$pdo->query("SELECT UNIX_TIMESTAMP()")->fetchColumn();
                    $_SESSION['must_change_password'] = 0;

                    header("Location: dashboard.php?msg=password_changed");
                    exit;
                } catch (PDOException $e) {
                    error_log("Change password error: " . $e->getMessage());
                    $error = "حدث خطأ أثناء تغيير كلمة المرور. يرجى المحاولة لاحقاً.";
                }
            }
        }
    }
}
$base = '';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <?php
    $page_title = 'تغيير كلمة المرور';
    include __DIR__ . '/includes/header-meta.php';
    $branding = get_branding($base);
    ?>
</head>
<body class="auth-wrapper">
    <?php if (!$is_forced) {
        include_once __DIR__ . '/includes/nav.php';
        render_nav($base);
    } ?>

    <div class="auth-card">
        <div class="auth-header">
            <a href="dashboard.php" class="auth-brand" aria-label="<?= htmlspecialchars($branding['app_name']) ?>">
                <img src="<?= htmlspecialchars($branding['logo_light_url']) ?>" alt="<?= htmlspecialchars($branding['app_name']) ?>" class="brand-logo brand-logo-light">
                <img src="<?= htmlspecialchars($branding['logo_dark_url']) ?>" alt="<?= htmlspecialchars($branding['app_name']) ?>" class="brand-logo brand-logo-dark">
            </a>
            <h1 class="auth-title">تغيير كلمة المرور</h1>
            <p class="auth-subtitle">
                <?= $is_forced ? 'يجب عليك تعيين كلمة مرور جديدة للمتابعة' : 'قم بتحديث كلمة مرور حسابك بانتظام لأمان أعلى' ?>
            </p>
        </div>

        <?php if ($is_forced): ?>
            <div class="alert alert-warning" role="alert">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="flex-shrink-0"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                <span>تم فرض تغيير كلمة المرور من قِبل الإدارة لأسباب أمنية.</span>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error" role="alert">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="flex-shrink-0"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="change-password.php" novalidate>
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

            <?php if (!$is_forced): ?>
            <div class="form-group">
                <label for="current_password" class="form-label">كلمة المرور الحالية <span class="required">*</span></label>
                <div class="password-field-wrapper">
                    <input type="password" id="current_password" name="current_password" class="form-control" required autocomplete="current-password">
                    <button type="button" class="password-toggle-btn" aria-label="إظهار كلمة المرور">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                    </button>
                </div>
            </div>
            <?php endif; ?>

            <div class="form-group">
                <label for="new_password" class="form-label">كلمة المرور الجديدة <span class="required">*</span></label>
                <div class="password-field-wrapper">
                    <input type="password" id="new_password" name="new_password" class="form-control" placeholder="10 أحرف على الأقل" required minlength="10" autocomplete="new-password">
                    <button type="button" class="password-toggle-btn" aria-label="إظهار كلمة المرور">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                    </button>
                </div>
                <span class="form-hint">يجب أن تتكون من 10 خانات على الأقل وتكون مختلفة عن السابقة.</span>
            </div>

            <div class="form-group">
                <label for="confirm_password" class="form-label">تأكيد كلمة المرور الجديدة <span class="required">*</span></label>
                <div class="password-field-wrapper">
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" required minlength="10" autocomplete="new-password">
                    <button type="button" class="password-toggle-btn" aria-label="إظهار كلمة المرور">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-submit-auth">تحديث كلمة المرور</button>
        </form>
    </div>
</body>
</html>
