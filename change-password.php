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
    <meta charset="UTF-8">
    <title>تغيير كلمة المرور - TaskFlow</title>
    <link rel="stylesheet" href="<?= $base ?>css/styles.css">
    <style>
        body {
            background: linear-gradient(135deg, #232526 0%, #414345 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
        }
        .pro-auth-container {
            background: rgba(255,255,255,0.07);
            border-radius: 24px;
            box-shadow: 0 8px 32px 0 rgba(31,38,135,0.37);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border: 1px solid rgba(255,255,255,0.18);
            padding: 40px 32px 32px 32px;
            text-align: center;
            max-width: 440px;
            width: 100%;
            animation: fadeInUp 0.7s cubic-bezier(.39,.575,.565,1.000) both;
        }
        .pro-logo {
            font-size: 2rem;
            font-weight: bold;
            color: #1abc9c;
            margin-bottom: 12px;
            letter-spacing: 2px;
        }
        .pro-auth-container h2 {
            color: #fff;
            margin-bottom: 18px;
            font-size: 1.4rem;
        }
        .pro-auth-container input {
            width: 100%;
            padding: 12px;
            margin-bottom: 18px;
            border: none;
            border-radius: 8px;
            background: rgba(255,255,255,0.15);
            color: #222;
            font-size: 1rem;
            box-sizing: border-box;
            transition: box-shadow 0.2s;
        }
        .pro-auth-container input:focus {
            outline: none;
            box-shadow: 0 0 0 2px #1abc9c;
        }
        .pro-auth-container button {
            width: 100%;
            background: linear-gradient(90deg, #1abc9c 0%, #16a085 100%);
            color: #fff;
            padding: 12px 0;
            border: none;
            border-radius: 8px;
            font-size: 1.1rem;
            font-weight: bold;
            cursor: pointer;
            box-shadow: 0 4px 16px rgba(26,188,156,0.15);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .pro-auth-container button:hover {
            transform: translateY(-2px) scale(1.02);
            box-shadow: 0 8px 24px rgba(26,188,156,0.25);
        }
        .pro-auth-container .error {
            color: #e74c3c;
            background: rgba(231,76,60,0.1);
            border-radius: 6px;
            padding: 10px;
            margin-bottom: 18px;
            font-size: 0.95rem;
        }
        .pro-auth-container .info {
            color: #f39c12;
            background: rgba(243,156,18,0.15);
            border-radius: 6px;
            padding: 10px;
            margin-bottom: 18px;
            font-size: 0.95rem;
        }
        @keyframes fadeInUp {
            0% { opacity: 0; transform: translateY(30px); }
            100% { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/includes/nav.php'; render_nav($base); ?>

    <div class="pro-auth-container">
        <div class="pro-logo">TaskFlow</div>
        <h2>تغيير كلمة المرور</h2>

        <?php if ($is_forced): ?>
            <div class="info">يجب عليك تعيين كلمة مرور جديدة للمتابعة.</div>
        <?php endif; ?>

        <?php if ($error): ?>
            <p class="error"><?= e($error) ?></p>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <?php if (!$is_forced): ?>
                <input type="password" name="current_password" placeholder="كلمة المرور الحالية" required>
            <?php endif; ?>
            <input type="password" name="new_password" placeholder="كلمة المرور الجديدة (10 أحرف على الأقل)" required minlength="10">
            <input type="password" name="confirm_password" placeholder="تأكيد كلمة المرور الجديدة" required minlength="10">
            <button type="submit">تحديث كلمة المرور</button>
        </form>
    </div>
</body>
</html>
