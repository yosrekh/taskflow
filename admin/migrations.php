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

// System Environment & Extension Check
$requiredExtensions = [
    'pdo_mysql' => 'الاتصال بقاعدة بيانات MySQL (PDO)',
    'mbstring'  => 'معالجة النصوص العربية وUTF-8',
    'fileinfo'  => 'التحقق الآمن من أنواع الملفات المرفوعة',
    'dom'       => 'فحص وتطهير ملفات SVG البرمجية',
    'libxml'    => 'محرك معالجة مستندات XML',
    'json'      => 'تبادل بيانات JSON للواجهات التفاعلية',
    'openssl'   => 'التشفير وتوليد الرموز الأمنية العشوائية',
    'zlib'      => 'ضغط النسخ الاحتياطية بصيغة gzip',
    'zip'       => 'أرشفة المرفقات والهوية البصرية (ZipArchive)',
    'gd'        => 'تحليل ألوان وتبويب الأيقونات والشعارات',
];

$projectRoot = dirname(__DIR__);

// Backup Dir Check
$backupDir = env('BACKUP_DIR');
if (empty($backupDir)) {
    $folderName = basename($projectRoot);
    $backupDir = dirname($projectRoot) . '/taskflow-backups/' . $folderName;
}
$backupDirExists = is_dir($backupDir);
if ($backupDirExists) {
    $backupDirWritable = is_writable($backupDir);
} else {
    $checkDir = $backupDir;
    while (!file_exists($checkDir) && dirname($checkDir) !== $checkDir) {
        $checkDir = dirname($checkDir);
    }
    $backupDirWritable = is_dir($checkDir) && is_writable($checkDir);
}

// Log File Check
$logFile = env('LOG_FILE');
if (empty($logFile)) {
    $logFile = $projectRoot . '/bin/cron-daily.log';
}
if (file_exists($logFile)) {
    $logFileWritable = is_writable($logFile);
} else {
    $logFileWritable = is_dir(dirname($logFile)) && is_writable(dirname($logFile));
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

        <!-- System Status Section (حالة النظام) -->
        <div class="settings-card" style="margin-top: var(--space-6);">
            <div class="card-header">
                <div>
                    <h2 class="card-title">حالة النظام والبيئة التشغيلية</h2>
                    <p class="card-desc">فحص توافق إصدار PHP والامتدادات المطلوبة وصلاحيات مسارات التخزين والنسخ الاحتياطي</p>
                </div>
                <span class="badge <?= version_compare(PHP_VERSION, '8.0.0', '>=') ? 'badge-active' : 'badge-inactive' ?>" style="font-family: var(--font-mono, monospace);">
                    PHP <bdi dir="ltr" class="tabular-nums"><?= PHP_VERSION ?></bdi>
                </span>
            </div>

            <div style="padding: var(--space-5);">
                <!-- Extensions Grid -->
                <h3 style="font-size: var(--font-size-sm); color: var(--text-muted); margin-block-end: var(--space-3); font-weight: 600;">
                    الامتدادات البرمجية (PHP Extensions)
                </h3>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: var(--space-3); margin-block-end: var(--space-6);">
                    <?php foreach ($requiredExtensions as $ext => $desc):
                        $isLoaded = extension_loaded($ext);
                    ?>
                        <div style="display: flex; align-items: center; justify-content: space-between; padding: var(--space-3); background-color: var(--bg-surface-subtle); border: 1px solid var(--border-subtle); border-radius: var(--radius-md);">
                            <div>
                                <div style="font-family: var(--font-mono, monospace); font-weight: 600; font-size: 0.9rem;">
                                    <bdi dir="ltr"><?= htmlspecialchars($ext) ?></bdi>
                                </div>
                                <div class="text-muted" style="font-size: 0.75rem; margin-top: 2px;">
                                    <?= htmlspecialchars($desc) ?>
                                </div>
                            </div>
                            <?php if ($isLoaded): ?>
                                <span class="badge badge-active" style="display: inline-flex; align-items: center; gap: 4px;">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                    مفعّل
                                </span>
                            <?php else: ?>
                                <span class="badge badge-inactive" style="display: inline-flex; align-items: center; gap: 4px;">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                                    غير متوفر
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Storage & Log Write Permissions -->
                <h3 style="font-size: var(--font-size-sm); color: var(--text-muted); margin-block-end: var(--space-3); font-weight: 600;">
                    صلاحيات مسارات التخزين والمهام اليومية (Write Permissions)
                </h3>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: var(--space-3);">
                    <!-- Backup Dir -->
                    <div style="padding: var(--space-3); background-color: var(--bg-surface-subtle); border: 1px solid var(--border-subtle); border-radius: var(--radius-md);">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-block-end: var(--space-2);">
                            <span style="font-weight: 600; font-size: 0.9rem;">مجلد النسخ الاحتياطي (BACKUP_DIR)</span>
                            <?php if ($backupDirWritable): ?>
                                <span class="badge badge-active">قابل للكتابة</span>
                            <?php else: ?>
                                <span class="badge badge-inactive">غير متاح للكتابة</span>
                            <?php endif; ?>
                        </div>
                        <div class="text-muted" style="font-size: 0.75rem; font-family: var(--font-mono, monospace); word-break: break-all;">
                            <bdi dir="ltr"><?= htmlspecialchars($backupDir) ?></bdi>
                        </div>
                    </div>

                    <!-- Log File -->
                    <div style="padding: var(--space-3); background-color: var(--bg-surface-subtle); border: 1px solid var(--border-subtle); border-radius: var(--radius-md);">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-block-end: var(--space-2);">
                            <span style="font-weight: 600; font-size: 0.9rem;">ملف سجل Cron اليومي (LOG_FILE)</span>
                            <?php if ($logFileWritable): ?>
                                <span class="badge badge-active">قابل للكتابة</span>
                            <?php else: ?>
                                <span class="badge badge-inactive">غير متاح للكتابة</span>
                            <?php endif; ?>
                        </div>
                        <div class="text-muted" style="font-size: 0.75rem; font-family: var(--font-mono, monospace); word-break: break-all;">
                            <bdi dir="ltr"><?= htmlspecialchars($logFile) ?></bdi>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</body>
</html>
