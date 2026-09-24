<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$error = '';
$base = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = "رمز التحقق غير صالح. يرجى إعادة المحاولة.";
    } else {
        // Opportunistic cleanup of rows older than 24 hours and login throttling check
        try {
            $pdo->exec("DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 24 HOUR)");

            // Check login throttling: 5 failures per email OR 30 failures per IP within 15 minutes
            $emailStmt = $pdo->prepare("
                SELECT COUNT(*) FROM login_attempts 
                WHERE email = ? 
                  AND attempted_at >= (NOW() - INTERVAL 15 MINUTE)
            ");
            $emailStmt->execute([$email]);
            $emailFailures = (int)$emailStmt->fetchColumn();

            $ipStmt = $pdo->prepare("
                SELECT COUNT(*) FROM login_attempts 
                WHERE ip = ? 
                  AND attempted_at >= (NOW() - INTERVAL 15 MINUTE)
            ");
            $ipStmt->execute([$ip]);
            $ipFailures = (int)$ipStmt->fetchColumn();

            if ($emailFailures >= 5 || $ipFailures >= 30) {
                $error = "تم حظر محاولات تسجيل الدخول مؤقتاً لكثرة المحاولات الفاشلة. يرجى المحاولة بعد 15 دقيقة.";
            } else {
                $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password'])) {
                    if ((int)$user['is_active'] !== 1) {
                        // Reject inactive users with identical generic message
                        $logStmt = $pdo->prepare("INSERT INTO login_attempts (email, ip) VALUES (?, ?)");
                        $logStmt->execute([$email, $ip]);
                        $error = "البريد أو كلمة المرور غير صحيحة.";
                    } else {
                        session_regenerate_id(true);
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['user_name'] = $user['name'] ?? '';
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['is_active'] = 1;
                        $_SESSION['must_change_password'] = (int)$user['must_change_password'];
                        $_SESSION['login_time'] = (int)$pdo->query("SELECT UNIX_TIMESTAMP()")->fetchColumn();

                        // Clear failed attempts for this email upon successful login
                        $clearStmt = $pdo->prepare("DELETE FROM login_attempts WHERE email = ?");
                        $clearStmt->execute([$email]);

                        if ((int)$user['must_change_password'] === 1) {
                            header("Location: change-password.php");
                        } else {
                            header("Location: dashboard.php");
                        }
                        exit;
                    }
                } else {
                    // Record failed attempt
                    $logStmt = $pdo->prepare("INSERT INTO login_attempts (email, ip) VALUES (?, ?)");
                    $logStmt->execute([$email, $ip]);
                    $error = "البريد أو كلمة المرور غير صحيحة.";
                }
            }
        } catch (PDOException $e) {
            error_log("Login error: " . $e->getMessage());
            $error = "حدث خطأ أثناء تسجيل الدخول. يرجى المحاولة لاحقاً.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <?php
    $page_title = 'تسجيل الدخول';
    include __DIR__ . '/includes/header-meta.php';
    ?>
</head>
<body class="auth-wrapper">
    <div class="auth-card">
        <div class="auth-header">
            <a href="login.php" class="auth-brand" aria-label="TaskFlow">
                <img src="<?= $base ?>assets/logo-horizontal-light.svg" alt="TaskFlow" class="brand-logo brand-logo-light">
                <img src="<?= $base ?>assets/logo-horizontal-dark.svg" alt="TaskFlow" class="brand-logo brand-logo-dark">
            </a>
            <h1 class="auth-title">تسجيل الدخول</h1>
            <p class="auth-subtitle">أدخل بيانات حسابك للوصول إلى مشاريعك</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error" role="alert">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php" novalidate>
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

            <div class="form-group">
                <label for="email" class="form-label">البريد الإلكتروني <span class="required">*</span></label>
                <input type="email" id="email" name="email" class="form-control" value="<?= htmlspecialchars($email ?? '') ?>" placeholder="name@example.com" required autofocus autocomplete="username">
            </div>

            <div class="form-group">
                <label for="password" class="form-label">كلمة المرور <span class="required">*</span></label>
                <div class="password-field-wrapper">
                    <input type="password" id="password" name="password" class="form-control" placeholder="••••••••••••" required autocomplete="current-password">
                    <button type="button" class="password-toggle-btn" aria-label="إظهار كلمة المرور">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; margin-block-start: var(--space-3);">تسجيل الدخول</button>
        </form>
    </div>
</body>
</html>