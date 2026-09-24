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

// Handle JSON stats endpoint if called via users.php?action=get_counts&user_id=X
if (isset($_GET['action']) && $_GET['action'] === 'get_counts') {
    header('Content-Type: application/json; charset=utf-8');
    $uid = filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT);
    if (!$uid) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_user_id']);
        exit;
    }
    try {
        $stmtU = $pdo->prepare("SELECT id, name, email, role, is_active FROM users WHERE id = ?");
        $stmtU->execute([$uid]);
        $uInfo = $stmtU->fetch(PDO::FETCH_ASSOC);
        if (!$uInfo) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'user_not_found']);
            exit;
        }
        $stmtP = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE user_id = ?");
        $stmtP->execute([$uid]);
        $pCount = (int)$stmtP->fetchColumn();

        $stmtT = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to = ? AND status != 'Completed'");
        $stmtT->execute([$uid]);
        $tCount = (int)$stmtT->fetchColumn();

        echo json_encode([
            'success' => true,
            'user' => [
                'id' => (int)$uInfo['id'],
                'name' => $uInfo['name'],
                'email' => $uInfo['email'],
                'role' => $uInfo['role'],
                'is_active' => (int)$uInfo['is_active']
            ],
            'projects_count' => $pCount,
            'open_tasks_count' => $tCount
        ]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'database_error']);
        exit;
    }
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
        if ($target_id === $admin_id) {
            $_SESSION['admin_flash_error'] = "استخدم صفحة تغيير كلمة المرور لتغيير باسوردك.";
        } else {
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
    } elseif ($action === 'delete_user') {
        $target_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
        $confirm_email = trim($_POST['confirm_email'] ?? '');
        $new_owner_id = filter_input(INPUT_POST, 'new_owner_id', FILTER_VALIDATE_INT);

        if (!$target_id) {
            $_SESSION['admin_flash_error'] = "معرّف المستخدم غير صالح.";
        } elseif ($target_id === $admin_id) {
            $_SESSION['admin_flash_error'] = "لا يمكنك حذف حسابك الخاص.";
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id, name, email, role, is_active FROM users WHERE id = ?");
                $stmt->execute([$target_id]);
                $target = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$target) {
                    $_SESSION['admin_flash_error'] = "المستخدم غير موجود.";
                } elseif ($target['role'] !== 'member') {
                    $_SESSION['admin_flash_error'] = "يمكن فقط حذف حسابات الأعضاء. يرجى خفض رتبة المسؤول إلى عضو أولاً قبل حذفه.";
                } elseif ($confirm_email !== $target['email']) {
                    $_SESSION['admin_flash_error'] = "البريد الإلكتروني للتأكيد غير مطابق لبريد الحساب المراد حذفه.";
                } else {
                    // Check if target owns any projects
                    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE user_id = ?");
                    $stmtCount->execute([$target_id]);
                    $projects_count = (int)$stmtCount->fetchColumn();

                    $new_owner = null;
                    if ($projects_count > 0 && !$new_owner_id) {
                        $_SESSION['admin_flash_error'] = "يجب تحديد مستخدم لنقل ملكية المشاريع إليه.";
                    } elseif ($new_owner_id) {
                        if ($new_owner_id === $target_id) {
                            $_SESSION['admin_flash_error'] = "لا يمكن نقل ملكية المشاريع إلى الحساب المراد حذفه.";
                        } else {
                            $stmtOwner = $pdo->prepare("SELECT id, name, email, is_active FROM users WHERE id = ?");
                            $stmtOwner->execute([$new_owner_id]);
                            $new_owner = $stmtOwner->fetch(PDO::FETCH_ASSOC);

                            if (!$new_owner || (int)$new_owner['is_active'] !== 1) {
                                $_SESSION['admin_flash_error'] = "المستخدم المختار لنقل الملكية غير صالح أو حسابه معطّل.";
                            }
                        }
                    }

                    if (empty($_SESSION['admin_flash_error'])) {
                        $pdo->beginTransaction();

                        $admin_name = $_SESSION['user_name'] ?? 'المسؤول';
                        $action_time = date('Y-m-d H:i');

                        // 1. Collect projects owned by other users where target had assigned tasks
                        $stmtAffected = $pdo->prepare("
                            SELECT DISTINCT p.id, p.title, p.user_id AS owner_id 
                            FROM tasks t 
                            JOIN projects p ON t.project_id = p.id 
                            WHERE t.assigned_to = ? AND p.user_id != ?
                        ");
                        $stmtAffected->execute([$target_id, $target_id]);
                        $affected_projects = $stmtAffected->fetchAll(PDO::FETCH_ASSOC);

                        // 2. Transfer projects if target owns any
                        if ($projects_count > 0) {
                            $pdo->prepare("UPDATE projects SET user_id = ? WHERE user_id = ?")->execute([$new_owner_id, $target_id]);
                        }

                        // 3. Explicitly unassign tasks assigned to target
                        $pdo->prepare("UPDATE tasks SET assigned_to = NULL WHERE assigned_to = ?")->execute([$target_id]);

                        // 4. Send one notification per affected project owner
                        foreach ($affected_projects as $proj) {
                            $owner_id = (int)$proj['owner_id'];
                            $msg_unassigned = "[{$action_time}] {$admin_name} حذف حساب {$target['name']}، والمهام اللي كانت مسندة له بقت من غير إسناد في مشروع '{$proj['title']}'";
                            $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)")->execute([$owner_id, $msg_unassigned]);
                        }

                        // 5. If projects were transferred, notify the new owner
                        if ($projects_count > 0) {
                            $msg_transfer = "[{$action_time}] {$admin_name} نقل لك ملكية {$projects_count} مشروع من حساب {$target['name']}";
                            $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)")->execute([$new_owner_id, $msg_transfer]);
                        }

                        // 6. Delete login attempts
                        $pdo->prepare("DELETE FROM login_attempts WHERE email = ?")->execute([$target['email']]);

                        // 7. Delete user (FK cascades remaining items)
                        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$target_id]);

                        $pdo->commit();

                        $_SESSION['admin_flash_success'] = "تم حذف حساب المستخدم '{$target['name']}' بنجاح.";
                    }
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("Admin delete user error: " . $e->getMessage());
                $_SESSION['admin_flash_error'] = "حدث خطأ أثناء محاولة حذف المستخدم.";
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

// Fetch active users for project transfer select
$active_stmt = $pdo->query("SELECT id, name, email, role FROM users WHERE is_active = 1 ORDER BY name ASC");
$active_users = $active_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <?php
    $page_title = 'إدارة المستخدمين';
    include __DIR__ . '/../includes/header-meta.php';
    ?>
</head>
<body>
    <?php
    include __DIR__ . '/../includes/nav.php';
    render_nav($base);
    ?>

    <main>
        <!-- Header -->
        <header class="page-header">
            <div class="page-title-wrap">
                <h1 class="page-title">إدارة المستخدمين</h1>
                <p class="page-subtitle">إضافة حسابات جديدة، إدارة الصلاحيات وتعيين كلمات المرور المؤقتة</p>
            </div>
            <div class="page-actions">
                <button type="button" class="btn btn-primary" onclick="openModal('createUserModal')">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    إضافة مستخدم جديد
                </button>
            </div>
        </header>

        <!-- Alerts -->
        <?php if ($error): ?>
            <div class="alert alert-error" role="alert">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="flex-shrink-0"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success" role="alert">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="flex-shrink-0"><polyline points="20 6 9 17 4 12"></polyline></svg>
                <span><?= htmlspecialchars($success) ?></span>
            </div>
        <?php endif; ?>

        <!-- One-Time Temporary Password Display Box -->
        <?php if ($one_time_password): ?>
            <section class="one-time-pwd-box" aria-labelledby="oneTimeTitle">
                <h2 id="oneTimeTitle" class="one-time-title">
                    <?= $one_time_action === 'create' ? 'كلمة المرور المؤقتة للمستخدم الجديد' : 'كلمة المرور المؤقتة الجديدة' ?>
                    (<strong><bdi dir="ltr"><?= htmlspecialchars($one_time_user_email) ?></bdi></strong>)
                </h2>
                <div class="one-time-pwd-code ltr" id="pwd-val"><bdi dir="ltr"><?= htmlspecialchars($one_time_password) ?></bdi></div>
                <div class="one-time-warning">
                    ⚠️ انسخ كلمة المرور الآن، لن تظهر مرة أخرى بعد مغادرة أو تحديث الصفحة!
                </div>
                <button type="button" class="btn btn-secondary btn-sm copy-btn" onclick="copyPassword()">
                    نسخ كلمة المرور
                </button>
            </section>
        <?php endif; ?>

        <!-- Users Table -->
        <div class="table-container">
            <table class="table" aria-label="قائمة المستخدمين">
                <thead>
                    <tr>
                        <th scope="col">الاسم</th>
                        <th scope="col">البريد الإلكتروني</th>
                        <th scope="col">الدور</th>
                        <th scope="col">الحالة</th>
                        <th scope="col">تاريخ الإنشاء</th>
                        <th scope="col" class="text-center">الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_users as $u):
                        $is_self = ((int)$u['id'] === $admin_id);
                        $userInitials = get_user_initials($u['name']);
                    ?>
                    <tr class="<?= $is_self ? 'user-row-self' : '' ?> <?= (int)$u['is_active'] === 0 ? 'user-row-inactive' : '' ?>">
                        <td>
                            <div class="user-cell">
                                <span class="avatar avatar-sm"><?= htmlspecialchars($userInitials) ?></span>
                                <strong><?= htmlspecialchars($u['name']) ?></strong>
                                <?php if ($is_self): ?>
                                    <span class="badge badge-self">حسابك</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><bdi dir="ltr"><?= htmlspecialchars($u['email']) ?></bdi></td>
                        <td>
                            <span class="badge <?= $u['role'] === 'admin' ? 'badge-admin' : 'badge-member' ?>">
                                <?= $u['role'] === 'admin' ? 'مسؤول' : 'عضو' ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge <?= (int)$u['is_active'] === 1 ? 'badge-active' : 'badge-inactive' ?>">
                                <?= (int)$u['is_active'] === 1 ? 'نشط' : 'معطّل' ?>
                            </span>
                        </td>
                        <td class="text-cell-muted tabular-nums">
                            <bdi dir="ltr"><?= date('Y-m-d', strtotime($u['created_at'])) ?></bdi>
                        </td>
                        <td>
                            <div class="actions-cell">
                                <?php if ($is_self): ?>
                                    <span class="text-self">(حسابك)</span>
                                <?php else: ?>
                                    <!-- Toggle Active Button -->
                                    <form method="POST" class="inline-form">
                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="action" value="toggle_active">
                                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                        <button type="submit" 
                                                class="btn btn-sm btn-secondary action-confirm-btn"
                                                data-confirm-action="<?= (int)$u['is_active'] === 1 ? 'deactivate' : 'reactivate' ?>"
                                                data-user-name="<?= htmlspecialchars($u['name']) ?>">
                                            <?= (int)$u['is_active'] === 1 ? 'تعطيل' : 'تفعيل' ?>
                                        </button>
                                    </form>

                                    <!-- Reset Password Button -->
                                    <form method="POST" class="inline-form">
                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="action" value="reset_password">
                                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                        <button type="submit" 
                                                class="btn btn-ghost btn-sm action-confirm-btn"
                                                data-confirm-action="reset_password"
                                                data-user-name="<?= htmlspecialchars($u['name']) ?>">
                                            إعادة تعيين كلمة المرور
                                        </button>
                                    </form>

                                    <!-- Promote / Demote Role Button -->
                                    <form method="POST" class="inline-form">
                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="action" value="change_role">
                                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                        <input type="hidden" name="role" value="<?= $u['role'] === 'admin' ? 'member' : 'admin' ?>">
                                        <button type="submit" 
                                                class="btn btn-ghost btn-sm action-confirm-btn"
                                                data-confirm-action="<?= $u['role'] === 'admin' ? 'demote' : 'promote' ?>"
                                                data-user-name="<?= htmlspecialchars($u['name']) ?>">
                                            <?= $u['role'] === 'admin' ? 'خفض لعضو' : 'ترقية لمسؤول' ?>
                                        </button>
                                    </form>

                                    <?php if ($u['role'] === 'member'): ?>
                                        <!-- Delete Member Account Button -->
                                        <button type="button" 
                                                class="btn btn-sm btn-delete open-delete-modal-btn"
                                                data-user-id="<?= (int)$u['id'] ?>"
                                                data-user-name="<?= htmlspecialchars($u['name']) ?>"
                                                data-user-email="<?= htmlspecialchars($u['email']) ?>">
                                            حذف
                                        </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>

    <!-- Create User Modal -->
    <div class="modal" id="createUserModal" role="dialog" aria-modal="true" aria-labelledby="createUserTitle">
        <div class="modal-backdrop" data-dismiss="modal"></div>
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title" id="createUserTitle">إضافة مستخدم جديد</h2>
                    <button type="button" class="modal-close" data-dismiss="modal" aria-label="إغلاق">&times;</button>
                </div>
                <form method="POST" action="users.php">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="action" value="create_user">

                        <div class="form-group">
                            <label for="new_user_name" class="form-label">الاسم الكامل <span class="required">*</span></label>
                            <input type="text" id="new_user_name" name="name" class="form-control" required placeholder="مثال: أحمد محمد">
                        </div>

                        <div class="form-group">
                            <label for="new_user_email" class="form-label">البريد الإلكتروني <span class="required">*</span></label>
                            <input type="email" id="new_user_email" name="email" class="form-control" dir="ltr" required placeholder="name@example.com">
                        </div>

                        <div class="form-group">
                            <label for="new_user_role" class="form-label">الدور <span class="required">*</span></label>
                            <select id="new_user_role" name="role" class="form-select" required>
                                <option value="member" selected>عضو عادي (member)</option>
                                <option value="admin">مسؤول نظام (admin)</option>
                            </select>
                            <span class="form-hint">سيتم توليد كلمة مرور مؤقتة عشوائية مكوّنة من 14 خانة وعرضها لمرة واحدة فقط.</span>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>
                        <button type="submit" class="btn btn-primary">إنشاء المستخدم</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete User Modal -->
    <div class="modal" id="deleteUserModal" role="dialog" aria-modal="true" aria-labelledby="deleteUserTitle">
        <div class="modal-backdrop" data-dismiss="modal"></div>
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title" id="deleteUserTitle">حذف حساب مستخدم</h2>
                    <button type="button" class="modal-close" data-dismiss="modal" aria-label="إغلاق">&times;</button>
                </div>
                <form method="POST" action="users.php" id="deleteUserForm">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="action" value="delete_user">
                        <input type="hidden" name="user_id" id="delete_user_id" value="">

                        <!-- Permanent Danger Warning -->
                        <div class="alert alert-error" role="alert">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="flex-shrink-0" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                            <span>هذا الإجراء نهائي ولا يمكن التراجع عنه. سيتم نقل المشاريع وإلغاء إسناد المهام وحذف الحساب بالكامل.</span>
                        </div>

                        <!-- User Info Card with Live Stats -->
                        <div class="delete-user-card">
                            <div class="delete-user-info">
                                <span class="delete-user-label">المستخدم المراد حذفه:</span>
                                <strong id="delete_modal_user_name" class="delete-user-name">-</strong>
                                <span class="delete-user-email text-muted ltr"><bdi dir="ltr" id="delete_modal_user_email">-</bdi></span>
                            </div>

                            <div class="delete-stats-grid">
                                <div class="delete-stat-box">
                                    <div class="delete-stat-num tabular-nums" id="delete_modal_projects_count">0</div>
                                    <div class="delete-stat-label">مشاريع يملكها</div>
                                </div>
                                <div class="delete-stat-box">
                                    <div class="delete-stat-num tabular-nums" id="delete_modal_tasks_count">0</div>
                                    <div class="delete-stat-label">مهام مفتوحة مسندة له</div>
                                </div>
                            </div>
                        </div>

                        <!-- Transfer Projects Group -->
                        <div class="form-group transfer-group is-hidden" id="transferProjectsGroup">
                            <label for="delete_new_owner_id" class="form-label">
                                نقل ملكية المشاريع إلى <span class="required">*</span>
                            </label>
                            <select id="delete_new_owner_id" name="new_owner_id" class="form-select">
                                <?php foreach ($active_users as $au): ?>
                                    <option value="<?= (int)$au['id'] ?>" <?= (int)$au['id'] === $admin_id ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($au['name']) ?> (<?= htmlspecialchars($au['email']) ?>) - <?= $au['role'] === 'admin' ? 'مسؤول' : 'عضو' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="form-hint">يملك هذا المستخدم مشاريع، يجب اختيار مستخدم نشط لنقل الملكية إليه قبل الحذف.</span>
                        </div>

                        <!-- Confirm by typing email -->
                        <div class="form-group">
                            <label for="delete_confirm_email" class="form-label">
                                لتأكيد الحذف، اكتب البريد الإلكتروني للمستخدم (<strong class="ltr"><bdi dir="ltr" id="delete_confirm_email_hint"></bdi></strong>): <span class="required">*</span>
                            </label>
                            <input type="email" id="delete_confirm_email" name="confirm_email" class="form-control" dir="ltr" required autocomplete="off" placeholder="اكتب البريد الإلكتروني هنا">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>
                        <button type="submit" class="btn btn-danger" id="deleteUserSubmitBtn" disabled>
                            حذف الحساب نهائياً
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Confirmation Dialog Listener, Delete Modal & Password Copy -->
    <script>
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.action-confirm-btn');
        if (!btn) return;

        const action = btn.dataset.confirmAction;
        const userName = btn.dataset.userName || '';
        let msg = '';

        switch (action) {
            case 'deactivate':
                msg = `تعطيل حساب ${userName}؟ هيتم تسجيل خروجه فوراً.`;
                break;
            case 'reactivate':
                msg = `تفعيل حساب ${userName}؟`;
                break;
            case 'reset_password':
                msg = `إعادة تعيين كلمة المرور لحساب ${userName}؟ هيتم تسجيل خروجه فوراً.`;
                break;
            case 'promote':
                msg = `ترقية ${userName} إلى مدير؟`;
                break;
            case 'demote':
                msg = `خفض رتبة ${userName} إلى عضو عادي؟`;
                break;
        }

        if (msg && !window.confirm(msg)) {
            e.preventDefault();
            e.stopPropagation();
        }
    });

    // Delete Modal Logic
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.open-delete-modal-btn');
        if (!btn) return;

        const userId = btn.dataset.userId;
        const userName = btn.dataset.userName || '';
        const userEmail = btn.dataset.userEmail || '';

        document.getElementById('delete_user_id').value = userId;
        document.getElementById('delete_modal_user_name').textContent = userName;
        document.getElementById('delete_modal_user_email').textContent = userEmail;
        document.getElementById('delete_confirm_email_hint').textContent = userEmail;

        const emailInput = document.getElementById('delete_confirm_email');
        const submitBtn = document.getElementById('deleteUserSubmitBtn');
        emailInput.value = '';
        submitBtn.disabled = true;

        const projCountEl = document.getElementById('delete_modal_projects_count');
        const taskCountEl = document.getElementById('delete_modal_tasks_count');
        const transferGroup = document.getElementById('transferProjectsGroup');
        const newOwnerSelect = document.getElementById('delete_new_owner_id');

        projCountEl.textContent = '...';
        taskCountEl.textContent = '...';
        transferGroup.classList.add('is-hidden');
        newOwnerSelect.removeAttribute('required');

        // Filter new owner options (exclude target, default to admin)
        const currentAdminId = String(<?= (int)$admin_id ?>);
        let defaultSelected = false;
        for (let i = 0; i < newOwnerSelect.options.length; i++) {
            const opt = newOwnerSelect.options[i];
            if (opt.value === String(userId)) {
                opt.disabled = true;
                opt.hidden = true;
                if (opt.selected) opt.selected = false;
            } else {
                opt.disabled = false;
                opt.hidden = false;
                if (opt.value === currentAdminId && !defaultSelected) {
                    opt.selected = true;
                    defaultSelected = true;
                }
            }
        }
        if (!defaultSelected && newOwnerSelect.options.length > 0) {
            for (let i = 0; i < newOwnerSelect.options.length; i++) {
                if (!newOwnerSelect.options[i].disabled) {
                    newOwnerSelect.options[i].selected = true;
                    break;
                }
            }
        }

        if (typeof openModal === 'function') {
            openModal('deleteUserModal');
        }

        // Fetch live stats
        fetch(`get-user-stats.php?id=${encodeURIComponent(userId)}`)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    projCountEl.textContent = data.projects_count;
                    taskCountEl.textContent = data.open_tasks_count;
                    if (data.projects_count > 0) {
                        transferGroup.classList.remove('is-hidden');
                        newOwnerSelect.setAttribute('required', 'required');
                    } else {
                        transferGroup.classList.add('is-hidden');
                        newOwnerSelect.removeAttribute('required');
                    }
                } else {
                    projCountEl.textContent = '0';
                    taskCountEl.textContent = '0';
                }
            })
            .catch(() => {
                projCountEl.textContent = '0';
                taskCountEl.textContent = '0';
            });
    });

    // Real-time Email Confirmation matching
    const deleteEmailInput = document.getElementById('delete_confirm_email');
    const deleteSubmitBtn = document.getElementById('deleteUserSubmitBtn');

    if (deleteEmailInput && deleteSubmitBtn) {
        deleteEmailInput.addEventListener('input', function() {
            const targetEmail = (document.getElementById('delete_confirm_email_hint').textContent || '').trim().toLowerCase();
            const typedEmail = this.value.trim().toLowerCase();
            deleteSubmitBtn.disabled = !(targetEmail && typedEmail === targetEmail);
        });
    }

    const deleteUserForm = document.getElementById('deleteUserForm');
    if (deleteUserForm) {
        deleteUserForm.addEventListener('submit', function(e) {
            const targetEmail = (document.getElementById('delete_confirm_email_hint').textContent || '').trim().toLowerCase();
            const typedEmail = (document.getElementById('delete_confirm_email').value || '').trim().toLowerCase();
            if (targetEmail !== typedEmail) {
                e.preventDefault();
                alert('البريد الإلكتروني المدخل غير مطابق للبريد المطلوب.');
            }
        });
    }

    function copyPassword() {
        const text = document.getElementById('pwd-val').textContent.trim();
        const btn = document.querySelector('.copy-btn');
        const orig = btn ? btn.textContent : '';

        function feedback() {
            if (btn) {
                btn.textContent = 'تم النسخ!';
                setTimeout(() => { btn.textContent = orig; }, 2000);
            }
            if (typeof showToast === 'function') {
                showToast('تم نسخ كلمة المرور إلى الحافظة بنجاح', 'success');
            }
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(feedback).catch(() => fallbackCopy(text, feedback));
        } else {
            fallbackCopy(text, feedback);
        }
    }

    function fallbackCopy(text, cb) {
        const temp = document.createElement('textarea');
        temp.value = text;
        document.body.appendChild(temp);
        temp.select();
        document.execCommand('copy');
        document.body.removeChild(temp);
        if (cb) cb();
    }
    </script>
</body>
</html>
