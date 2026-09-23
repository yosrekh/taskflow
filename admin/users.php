<?php
// admin/users.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

require_admin('../');

$base = '../';
$admin_id = (int)$_SESSION['user_id'];
$error = '';
$success = '';
$one_time_password = null;
$one_time_user_email = '';
$one_time_action = '';

function generate_temp_password($length = 14) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $pwd = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $pwd .= $chars[random_int(0, $max)];
    }
    return $pwd;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $_SESSION['admin_flash_error'] = "رمز التحقق غير صالح. يرجى إعادة المحاولة.";
        header("Location: users.php");
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create_user') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? 'member';

        if (empty($name) || empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['admin_flash_error'] = "يرجى إدخال اسم وبريد إلكتروني صالحين.";
        } elseif (!in_array($role, ['admin', 'member'], true)) {
            $_SESSION['admin_flash_error'] = "الدور المحدد غير صالح.";
        } else {
            try {
                $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $checkStmt->execute([$email]);
                if ($checkStmt->fetch()) {
                    $_SESSION['admin_flash_error'] = "البريد الإلكتروني مستخدم بالفعل.";
                } else {
                    $temp_pwd = generate_temp_password(14);
                    $hashed = password_hash($temp_pwd, PASSWORD_DEFAULT);

                    $insertStmt = $pdo->prepare("
                        INSERT INTO users (name, email, password, role, is_active, must_change_password) 
                        VALUES (?, ?, ?, ?, 1, 1)
                    ");
                    $insertStmt->execute([$name, $email, $hashed, $role]);

                    $_SESSION['one_time_pwd_flash'] = [
                        'password' => $temp_pwd,
                        'email' => $email,
                        'action' => 'create',
                        'success' => "تم إنشاء المستخدم بنجاح."
                    ];
                }
            } catch (PDOException $e) {
                error_log("Admin create user error: " . $e->getMessage());
                $_SESSION['admin_flash_error'] = "حدث خطأ أثناء إنشاء المستخدم.";
            }
        }
    } elseif ($action === 'reset_password') {
        $target_id = (int)($_POST['user_id'] ?? 0);
        try {
            $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE id = ?");
            $stmt->execute([$target_id]);
            $target = $stmt->fetch();

            if (!$target) {
                $_SESSION['admin_flash_error'] = "المستخدم غير موجود.";
            } else {
                $temp_pwd = generate_temp_password(14);
                $hashed = password_hash($temp_pwd, PASSWORD_DEFAULT);

                $updateStmt = $pdo->prepare("
                    UPDATE users 
                    SET password = ?, must_change_password = 1, password_changed_at = NOW() 
                    WHERE id = ?
                ");
                $updateStmt->execute([$hashed, $target_id]);

                $_SESSION['one_time_pwd_flash'] = [
                    'password' => $temp_pwd,
                    'email' => $target['email'],
                    'action' => 'reset',
                    'success' => "تمت إعادة تعيين كلمة المرور بنجاح للمستخدم '{$target['name']}'."
                ];
            }
        } catch (PDOException $e) {
            error_log("Admin reset password error: " . $e->getMessage());
            $_SESSION['admin_flash_error'] = "حدث خطأ أثناء إعادة تعيين كلمة المرور.";
        }
    } elseif ($action === 'toggle_active') {
        $target_id = (int)($_POST['user_id'] ?? 0);
        try {
            $stmt = $pdo->prepare("SELECT id, name, role, is_active FROM users WHERE id = ?");
            $stmt->execute([$target_id]);
            $target = $stmt->fetch();

            if (!$target) {
                $_SESSION['admin_flash_error'] = "المستخدم غير موجود.";
            } elseif ($target_id === $admin_id) {
                $_SESSION['admin_flash_error'] = "لا يمكنك تعطيل حسابك الخاص.";
            } else {
                $new_status = ((int)$target['is_active'] === 1) ? 0 : 1;

                // If deactivating an admin, ensure at least one other active admin remains
                if ($new_status === 0 && $target['role'] === 'admin') {
                    $checkAdmins = $pdo->prepare("
                        SELECT COUNT(*) FROM users 
                        WHERE role = 'admin' AND is_active = 1 AND id != ?
                    ");
                    $checkAdmins->execute([$target_id]);
                    if ((int)$checkAdmins->fetchColumn() < 1) {
                        $_SESSION['admin_flash_error'] = "لا يمكن تعطيل هذا المستخدم؛ يجب وجود مدير نشط واحد على الأقل في النظام.";
                    }
                }

                if (empty($_SESSION['admin_flash_error'])) {
                    $updateStmt = $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?");
                    $updateStmt->execute([$new_status, $target_id]);
                    $_SESSION['admin_flash_success'] = $new_status ? "تم تفعيل المستخدم بنجاح." : "تم تعطيل المستخدم بنجاح.";
                }
            }
        } catch (PDOException $e) {
            error_log("Admin toggle active error: " . $e->getMessage());
            $_SESSION['admin_flash_error'] = "حدث خطأ أثناء تعديل حالة الحساب.";
        }
    } elseif ($action === 'change_role') {
        $target_id = (int)($_POST['user_id'] ?? 0);
        $new_role = $_POST['role'] ?? '';

        if (!in_array($new_role, ['admin', 'member'], true)) {
            $_SESSION['admin_flash_error'] = "الدور المحدد غير صالح.";
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id, name, role, is_active FROM users WHERE id = ?");
                $stmt->execute([$target_id]);
                $target = $stmt->fetch();

                if (!$target) {
                    $_SESSION['admin_flash_error'] = "المستخدم غير موجود.";
                } elseif ($target_id === $admin_id && $new_role !== 'admin') {
                    $_SESSION['admin_flash_error'] = "لا يمكنك خفض صلاحيات حسابك الخاص.";
                } elseif ($target['role'] === 'admin' && $new_role !== 'admin') {
                    $checkAdmins = $pdo->prepare("
                        SELECT COUNT(*) FROM users 
                        WHERE role = 'admin' AND is_active = 1 AND id != ?
                    ");
                    $checkAdmins->execute([$target_id]);
                    if ((int)$checkAdmins->fetchColumn() < 1) {
                        $_SESSION['admin_flash_error'] = "لا يمكن خفض صلاحيات هذا المدير؛ يجب وجود مدير نشط واحد على الأقل في النظام.";
                    } else {
                        $updateStmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
                        $updateStmt->execute([$new_role, $target_id]);
                        $_SESSION['admin_flash_success'] = "تم تغيير دور المستخدم بنجاح.";
                    }
                } else {
                    $updateStmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
                    $updateStmt->execute([$new_role, $target_id]);
                    $_SESSION['admin_flash_success'] = "تم تغيير دور المستخدم بنجاح.";
                }
            } catch (PDOException $e) {
                error_log("Admin change role error: " . $e->getMessage());
                $_SESSION['admin_flash_error'] = "حدث خطأ أثناء تغيير الدور.";
            }
        }
    }

    header("Location: users.php");
    exit;
}

// Retrieve flash messages and immediately clear them from session
$error = $_SESSION['admin_flash_error'] ?? '';
unset($_SESSION['admin_flash_error']);

$success = $_SESSION['admin_flash_success'] ?? '';
unset($_SESSION['admin_flash_success']);

$one_time_password = null;
$one_time_user_email = '';
$one_time_action = '';

if (!empty($_SESSION['one_time_pwd_flash'])) {
    $one_time_password = $_SESSION['one_time_pwd_flash']['password'];
    $one_time_user_email = $_SESSION['one_time_pwd_flash']['email'];
    $one_time_action = $_SESSION['one_time_pwd_flash']['action'];
    $success = $_SESSION['one_time_pwd_flash']['success'];
    unset($_SESSION['one_time_pwd_flash']); // Cleared immediately!
}

// Fetch all users for the table
$stmt = $pdo->query("SELECT id, name, email, role, is_active, created_at FROM users ORDER BY created_at DESC");
$all_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>إدارة المستخدمين - TaskFlow</title>
    <link rel="stylesheet" href="<?= $base ?>css/styles.css">
    <style>
        body {
            background: linear-gradient(135deg, #232526 0%, #414345 100%);
            min-height: 100vh;
            margin: 0;
            color: #fff;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .admin-container {
            width: 95%;
            max-width: 1100px;
            margin: 30px auto;
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.14);
            border-radius: 20px;
            padding: 32px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.3);
            box-sizing: border-box;
        }
        .header-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid rgba(255,255,255,0.12);
            padding-bottom: 20px;
            margin-bottom: 24px;
        }
        .header-section h1 {
            margin: 0;
            font-size: 1.8rem;
            color: #1abc9c;
        }
        .alert {
            padding: 14px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 1rem;
        }
        .alert-error {
            background: rgba(231,76,60,0.18);
            border: 1px solid #e74c3c;
            color: #ff7675;
        }
        .alert-success {
            background: rgba(46,204,113,0.18);
            border: 1px solid #2ecc71;
            color: #2ecc71;
        }
        .one-time-pwd-box {
            background: rgba(243,156,18,0.15);
            border: 2px dashed #f39c12;
            border-radius: 14px;
            padding: 24px;
            margin-bottom: 28px;
            text-align: center;
        }
        .one-time-pwd-box h3 {
            margin: 0 0 10px 0;
            color: #f1c40f;
            font-size: 1.3rem;
        }
        .pwd-display {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 12px;
            margin: 16px 0;
        }
        .pwd-code {
            font-family: 'Courier New', Courier, monospace;
            font-size: 1.5rem;
            font-weight: bold;
            background: #111;
            color: #1abc9c;
            padding: 10px 24px;
            border-radius: 8px;
            letter-spacing: 2px;
            border: 1px solid #333;
            user-select: all;
        }
        .copy-btn {
            background: #1abc9c;
            color: #fff;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.2s, transform 0.1s;
        }
        .copy-btn:hover {
            background: #16a085;
            transform: scale(1.04);
        }
        .pwd-warning {
            color: #f39c12;
            font-size: 1.1rem;
            font-weight: bold;
            margin-top: 10px;
        }
        .create-form-card {
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 14px;
            padding: 24px;
            margin-bottom: 32px;
        }
        .create-form-card h2 {
            margin-top: 0;
            font-size: 1.25rem;
            color: #fff;
            margin-bottom: 16px;
        }
        .create-form {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            align-items: center;
        }
        .create-form input, .create-form select {
            padding: 10px 14px;
            border-radius: 8px;
            border: 1px solid rgba(255,255,255,0.2);
            background: rgba(255,255,255,0.12);
            color: #fff;
            font-size: 0.98rem;
            outline: none;
            flex: 1 1 200px;
        }
        .create-form input::placeholder {
            color: #bbb;
        }
        .create-form select option {
            background: #2c3e50;
            color: #fff;
        }
        .create-form button {
            background: linear-gradient(90deg, #1abc9c 0%, #16a085 100%);
            color: #fff;
            border: none;
            padding: 10px 24px;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: bold;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .create-form button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 14px rgba(26,188,156,0.3);
        }
        .table-responsive {
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            text-align: right;
        }
        th, td {
            padding: 14px 16px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        th {
            background: rgba(255,255,255,0.06);
            color: #1abc9c;
            font-weight: bold;
            font-size: 1rem;
        }
        tr:hover td {
            background: rgba(255,255,255,0.03);
        }
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: bold;
        }
        .badge-admin {
            background: rgba(155,89,182,0.25);
            color: #e056fd;
            border: 1px solid #9b59b6;
        }
        .badge-member {
            background: rgba(52,152,219,0.25);
            color: #3498db;
            border: 1px solid #2980b9;
        }
        .badge-active {
            background: rgba(46,204,113,0.2);
            color: #2ecc71;
            border: 1px solid #2ecc71;
        }
        .badge-inactive {
            background: rgba(231,76,60,0.2);
            color: #e74c3c;
            border: 1px solid #e74c3c;
        }
        .action-btns {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }
        .action-btns form {
            margin: 0;
            display: inline-block;
        }
        .btn-sm {
            padding: 6px 12px;
            border-radius: 6px;
            border: none;
            font-size: 0.85rem;
            font-weight: bold;
            cursor: pointer;
            transition: opacity 0.2s, transform 0.1s;
        }
        .btn-sm:hover:not(:disabled) {
            opacity: 0.85;
            transform: scale(1.03);
        }
        .btn-sm:disabled {
            opacity: 0.35;
            cursor: not-allowed;
        }
        .btn-reset {
            background: #f39c12;
            color: #fff;
        }
        .btn-deactivate {
            background: #e74c3c;
            color: #fff;
        }
        .btn-activate {
            background: #2ecc71;
            color: #fff;
        }
        .btn-role {
            background: #34495e;
            color: #ecf0f1;
            border: 1px solid #556b82;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/nav.php'; render_nav($base); ?>

    <div class="admin-container">
        <div class="header-section">
            <h1>إدارة حسابات المستخدمين</h1>
            <a href="<?= $base ?>dashboard.php" style="color:#1abc9c;text-decoration:none;font-weight:bold;">&larr; العودة للوحة التحكم</a>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?= e($success) ?></div>
        <?php endif; ?>

        <?php if ($one_time_password): ?>
            <div class="one-time-pwd-box">
                <h3><?= $one_time_action === 'create' ? 'تم إنشاء الحساب بكلمة مرور مؤقتة' : 'تم تعيين كلمة مرور مؤقتة جديدة' ?></h3>
                <div>المستخدم: <strong><?= e($one_time_user_email) ?></strong></div>
                <div class="pwd-display">
                    <span id="pwd-val" class="pwd-code"><?= e($one_time_password) ?></span>
                    <button type="button" class="copy-btn" onclick="copyPassword()">نسخ كلمة المرور</button>
                </div>
                <div class="pwd-warning">انسخ الباسورد دلوقتي، مش هتظهر تاني</div>
            </div>
        <?php endif; ?>

        <div class="create-form-card">
            <h2>إضافة مستخدم جديد</h2>
            <form method="POST" class="create-form">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="create_user">
                <input type="text" name="name" placeholder="الاسم بالكامل" required>
                <input type="email" name="email" placeholder="البريد الإلكتروني" required>
                <select name="role">
                    <option value="member">عضو (Member)</option>
                    <option value="admin">مدير (Admin)</option>
                </select>
                <button type="submit">إنشاء المستخدم</button>
            </form>
        </div>

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>الاسم</th>
                        <th>البريد الإلكتروني</th>
                        <th>الدور</th>
                        <th>الحالة</th>
                        <th>تاريخ الإنشاء</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_users as $u): ?>
                        <tr>
                            <td><strong><?= e($u['name']) ?></strong></td>
                            <td><?= e($u['email']) ?></td>
                            <td>
                                <?php if ($u['role'] === 'admin'): ?>
                                    <span class="badge badge-admin">مدير</span>
                                <?php else: ?>
                                    <span class="badge badge-member">عضو</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int)$u['is_active'] === 1): ?>
                                    <span class="badge badge-active">نشط</span>
                                <?php else: ?>
                                    <span class="badge badge-inactive">معطل</span>
                                <?php endif; ?>
                            </td>
                            <td><?= date('Y-m-d H:i', strtotime($u['created_at'])) ?></td>
                            <td>
                                <div class="action-btns">
                                    <!-- Reset Password -->
                                    <form method="POST" onsubmit="return confirm('هل أنت متأكد من إعادة تعيين كلمة المرور لهذا المستخدم؟ سيتم إنهاء جلساته النشطة فوراً.');">
                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="action" value="reset_password">
                                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                        <button type="submit" class="btn-sm btn-reset">إعادة تعيين الباسورد</button>
                                    </form>

                                    <!-- Deactivate / Activate -->
                                    <?php $isSelf = ((int)$u['id'] === $admin_id); ?>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="action" value="toggle_active">
                                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                        <?php if ((int)$u['is_active'] === 1): ?>
                                            <button type="submit" class="btn-sm btn-deactivate" <?= $isSelf ? 'disabled title="لا يمكنك تعطيل حسابك الخاص"' : '' ?> onclick="return confirm('هل تريد تعطيل هذا الحساب؟')">تعطيل</button>
                                        <?php else: ?>
                                            <button type="submit" class="btn-sm btn-activate" onclick="return confirm('هل تريد تفعيل هذا الحساب؟')">تفعيل</button>
                                        <?php endif; ?>
                                    </form>

                                    <!-- Change Role -->
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="action" value="change_role">
                                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                        <?php if ($u['role'] === 'admin'): ?>
                                            <input type="hidden" name="role" value="member">
                                            <button type="submit" class="btn-sm btn-role" <?= $isSelf ? 'disabled title="لا يمكنك خفض صلاحيات حسابك الخاص"' : '' ?> onclick="return confirm('تحويل المستخدم إلى عضو عادي؟')">خفض لعضو</button>
                                        <?php else: ?>
                                            <input type="hidden" name="role" value="admin">
                                            <button type="submit" class="btn-sm btn-role" onclick="return confirm('ترقية المستخدم إلى مدير؟')">ترقية لمدير</button>
                                        <?php endif; ?>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
    function copyPassword() {
        const text = document.getElementById('pwd-val').textContent.trim();
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(() => {
                const btn = document.querySelector('.copy-btn');
                const orig = btn.textContent;
                btn.textContent = 'تم النسخ!';
                btn.style.background = '#2ecc71';
                setTimeout(() => {
                    btn.textContent = orig;
                    btn.style.background = '#1abc9c';
                }, 2000);
            }).catch(() => fallbackCopy(text));
        } else {
            fallbackCopy(text);
        }
    }
    function fallbackCopy(text) {
        const temp = document.createElement('textarea');
        temp.value = text;
        document.body.appendChild(temp);
        temp.select();
        document.execCommand('copy');
        document.body.removeChild(temp);
        const btn = document.querySelector('.copy-btn');
        const orig = btn.textContent;
        btn.textContent = 'تم النسخ!';
        btn.style.background = '#2ecc71';
        setTimeout(() => {
            btn.textContent = orig;
            btn.style.background = '#1abc9c';
        }, 2000);
    }
    </script>
</body>
</html>
