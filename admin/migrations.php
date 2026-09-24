<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_admin();
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/migrations.php';

$base = '../';
$flashSuccess = '';
$flashError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = get_csrf_token_from_request();
    if (!verify_csrf($token)) {
        render_error(403, "رمز التحقق غير صالح.");
    }

    $res = apply_pending_migrations($pdo);

    if ($res['failed'] !== null) {
        $failedFile = htmlspecialchars($res['failed']['migration'], ENT_QUOTES, 'UTF-8');
        $errorMsg = htmlspecialchars($res['failed']['error'], ENT_QUOTES, 'UTF-8');
        $flashError = "فشل تطبيق ملف التحديث <code>{$failedFile}</code>: {$errorMsg}";
    } else {
        $count = count($res['applied']);
        if ($count > 0) {
            $flashSuccess = "تم تطبيق كافة التحديثات بنجاح ({$count} ملفات).";
        } else {
            $flashSuccess = "قاعدة البيانات محدثة بالفعل، لا توجد تحديثات معلقة.";
        }
    }
}

$migrations = get_all_migrations_status($pdo);
$pendingCount = 0;
foreach ($migrations as $m) {
    if ($m['status'] === 'pending') {
        $pendingCount++;
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <?php
    $page_title = 'تحديثات قاعدة البيانات';
    include dirname(__DIR__) . '/includes/header-meta.php';
    ?>
</head>
<body>
    <?php
    include dirname(__DIR__) . '/includes/nav.php';
    render_nav($base);
    ?>

    <main class="page-container">
        <!-- Page Header -->
        <header class="page-header">
            <div class="page-title-wrap">
                <h1 class="page-title">تحديثات قاعدة البيانات</h1>
                <p class="page-subtitle">التحديثات اللي اتطبقت على قاعدة البيانات، وأي تحديث جديد مستني التطبيق</p>
            </div>
            <?php if ($pendingCount > 0): ?>
                <div class="page-actions">
                    <form method="POST" action="migrations.php" style="margin: 0;">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <button type="submit" class="btn btn-primary">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <polyline points="20 6 9 17 4 12"></polyline>
                            </svg>
                            تطبيق التحديثات (<?= $pendingCount ?>)
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </header>

        <?php if (!empty($flashSuccess)): ?>
            <div class="alert alert-success" role="alert">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="flex-shrink-0"><polyline points="20 6 9 17 4 12"></polyline></svg>
                <span><?= $flashSuccess ?></span>
            </div>
        <?php endif; ?>

        <?php if (!empty($flashError)): ?>
            <div class="alert alert-error" role="alert">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="flex-shrink-0"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                <span><?= $flashError ?></span>
            </div>
        <?php endif; ?>

        <div class="settings-card">
            <div class="card-header">
                <div>
                    <h2 class="card-title">سجل التحديثات</h2>
                    <p class="card-desc">قائمة بملفات التحديثات وحالة تطبيقها على قاعدة البيانات الحالية</p>
                </div>
                <?php if ($pendingCount > 0): ?>
                    <span class="badge badge-high" style="background-color: var(--orange-100); color: var(--orange-800); border: 1px solid var(--orange-300);">
                        يوجد <?= $pendingCount ?> تحديث معلق
                    </span>
                <?php else: ?>
                    <span class="badge badge-active">قاعدة البيانات محدثة</span>
                <?php endif; ?>
            </div>

            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>التحديث</th>
                            <th>الحالة</th>
                            <th>تاريخ التطبيق</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($migrations)): ?>
                            <tr>
                                <td colspan="3" class="text-center text-muted" style="padding: var(--space-6);">لا توجد أي ملفات تحديث في المجلد db/migrations/</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($migrations as $m): ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 600; color: var(--text-primary); margin-block-end: 2px;">
                                            <?= htmlspecialchars($m['description'] ?: $m['filename']) ?>
                                        </div>
                                        <div class="text-muted" style="font-size: 0.8rem; font-family: var(--font-mono, monospace);">
                                            <bdi dir="ltr"><?= htmlspecialchars($m['filename']) ?></bdi>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($m['status'] === 'applied'): ?>
                                            <span class="badge badge-active" style="display: inline-flex; align-items: center; gap: 4px;">
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                                تم التطبيق
                                            </span>
                                        <?php else: ?>
                                            <span class="badge" style="background-color: var(--orange-100); color: var(--orange-800); border: 1px solid var(--orange-300); display: inline-flex; align-items: center; gap: 4px;">
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                                                معلق
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-muted tabular-nums" style="font-size: var(--font-size-sm);">
                                        <?php if ($m['applied_at']): ?>
                                            <bdi dir="ltr"><?= htmlspecialchars($m['applied_at']) ?></bdi>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($pendingCount > 0): ?>
                <div style="padding: var(--space-4); border-top: 1px solid var(--border-subtle); display: flex; justify-content: flex-end;">
                    <form method="POST" action="migrations.php" style="margin: 0;">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <button type="submit" class="btn btn-primary">
                            تطبيق كافة التحديثات المعلقة
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
