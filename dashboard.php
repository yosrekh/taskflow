<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/db.php';

$base = '';
$user_id = (int)$_SESSION['user_id'];
$userName = $_SESSION['user_name'] ?? 'مستخدم';

$showBackupWarning = false;
$backupWarningMessage = '';

// Single aggregated query to fetch projects with task counts and progress (No N+1)
if (is_admin()) {
    require_once __DIR__ . '/includes/branding.php';
    $backupLastSuccess = get_setting('backup_last_success');
    if (empty($backupLastSuccess)) {
        $showBackupWarning = true;
        $backupWarningMessage = 'مفيش أي نسخة احتياطية لحد دلوقتي. اتأكد إن الـ <bdi dir="ltr">cron</bdi> شغال.';
    } else {
        $lastSuccessTs = strtotime($backupLastSuccess);
        if ($lastSuccessTs === false || (time() - $lastSuccessTs) > 172800) {
            $showBackupWarning = true;
            $diff = max(0, time() - ($lastSuccessTs ?: time()));
            $hours = (int)floor($diff / 3600);
            $days = (int)floor($hours / 24);
            if ($days === 2) {
                $relativeTime = 'يومين';
            } elseif ($days >= 3 && $days <= 10) {
                $relativeTime = "<bdi class=\"tabular-nums\">{$days}</bdi> أيام";
            } elseif ($days > 10) {
                $relativeTime = "<bdi class=\"tabular-nums\">{$days}</bdi> يوم";
            } else {
                $relativeTime = "<bdi class=\"tabular-nums\">{$hours}</bdi> ساعة";
            }
            $backupWarningMessage = "آخر نسخة احتياطية ناجحة كانت من {$relativeTime}. اتأكد إن الـ <bdi dir=\"ltr\">cron</bdi> شغال.";
        }
    }

    $stmt = $pdo->query("
        SELECT 
            projects.*, 
            users.name AS owner_name,
            COUNT(tasks.id) AS total_tasks,
            COALESCE(SUM(CASE WHEN tasks.status = 'Pending' THEN 1 ELSE 0 END), 0) AS pending_tasks,
            COALESCE(SUM(CASE WHEN tasks.status = 'In Progress' THEN 1 ELSE 0 END), 0) AS in_progress_tasks,
            COALESCE(SUM(CASE WHEN tasks.status = 'Completed' THEN 1 ELSE 0 END), 0) AS completed_tasks
        FROM projects 
        JOIN users ON projects.user_id = users.id 
        LEFT JOIN tasks ON tasks.project_id = projects.id 
        GROUP BY projects.id, users.name 
        ORDER BY projects.created_at DESC
    ");
    $allProjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $myProjects = array_values(array_filter($allProjects, fn($p) => (int)$p['user_id'] === $user_id));
    $teamProjects = array_values(array_filter($allProjects, fn($p) => (int)$p['user_id'] !== $user_id));
} else {
    $stmt = $pdo->prepare("
        SELECT 
            projects.*, 
            users.name AS owner_name,
            COUNT(tasks.id) AS total_tasks,
            COALESCE(SUM(CASE WHEN tasks.status = 'Pending' THEN 1 ELSE 0 END), 0) AS pending_tasks,
            COALESCE(SUM(CASE WHEN tasks.status = 'In Progress' THEN 1 ELSE 0 END), 0) AS in_progress_tasks,
            COALESCE(SUM(CASE WHEN tasks.status = 'Completed' THEN 1 ELSE 0 END), 0) AS completed_tasks
        FROM projects 
        JOIN users ON projects.user_id = users.id 
        LEFT JOIN tasks ON tasks.project_id = projects.id 
        WHERE projects.user_id = ? 
           OR EXISTS (
               SELECT 1 FROM tasks t2 
               WHERE t2.project_id = projects.id 
                 AND t2.assigned_to = ?
           )
        GROUP BY projects.id, users.name 
        ORDER BY projects.created_at DESC
    ");
    $stmt->execute([$user_id, $user_id]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function render_project_card($p, $pdo, $user_id, $base = '') {
    $total = (int)$p['total_tasks'];
    $done = (int)$p['completed_tasks'];
    $inProg = (int)$p['in_progress_tasks'];
    $pending = (int)$p['pending_tasks'];
    $pct = $total > 0 ? (int)round(($done / $total) * 100) : 0;
    $canManage = can_manage_project($pdo, $user_id, $p['id']);
    $ownerInitials = get_user_initials($p['owner_name']);
    ?>
    <article class="project-card">
        <div class="project-card-header">
            <h3 class="project-card-title">
                <a href="<?= $base ?>tasks/view-tasks.php?project_id=<?= $p['id'] ?>">
                    <?= htmlspecialchars($p['title']) ?>
                </a>
            </h3>
            <div class="project-card-actions">
                <?php if ($canManage): ?>
                    <a href="<?= $base ?>projects/edit-project.php?id=<?= $p['id'] ?>" class="btn-icon" aria-label="تعديل المشروع '<?= htmlspecialchars($p['title']) ?>'" title="تعديل">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                        </svg>
                    </a>
                    <button type="button" class="btn-icon btn-icon-danger open-delete-project-modal-btn" data-project-id="<?= (int)$p['id'] ?>" data-project-title="<?= htmlspecialchars($p['title'], ENT_QUOTES) ?>" aria-label="حذف المشروع '<?= htmlspecialchars($p['title']) ?>'" title="حذف">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <polyline points="3 6 5 6 21 6"></polyline>
                            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                        </svg>
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <p class="project-card-desc">
            <?= !empty($p['description']) ? htmlspecialchars($p['description']) : '<span class="text-muted-italic">لا يوجد وصف لهذا المشروع</span>' ?>
        </p>

        <div class="project-card-meta">
            <span class="avatar avatar-sm" title="<?= htmlspecialchars($p['owner_name']) ?>"><?= htmlspecialchars($ownerInitials) ?></span>
            <span>المالك: <strong><?= htmlspecialchars($p['owner_name']) ?></strong></span>
        </div>

        <div class="project-status-counts">
            <div class="count-item">
                <span class="count-number count-pending tabular-nums"><?= $pending ?></span>
                <span class="count-label">للتنفيذ</span>
            </div>
            <div class="count-item">
                <span class="count-number count-inprog tabular-nums"><?= $inProg ?></span>
                <span class="count-label">قيد التنفيذ</span>
            </div>
            <div class="count-item">
                <span class="count-number count-done tabular-nums"><?= $done ?></span>
                <span class="count-label">مكتملة</span>
            </div>
        </div>

        <div class="project-progress-wrap">
            <div class="project-progress-header">
                <span>نسبة الإنجاز</span>
                <strong><bdi class="tabular-nums"><?= $pct ?>%</bdi></strong>
            </div>
            <div class="progress-bar-container" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100">
                <div class="progress-bar-fill" style="width: <?= $pct ?>%;"></div>
            </div>
        </div>

        <div class="project-card-footer">
            <a href="<?= $base ?>tasks/view-tasks.php?project_id=<?= $p['id'] ?>" class="btn btn-secondary btn-sm btn-block">
                عرض المهام (<bdi class="tabular-nums"><?= $total ?></bdi>)
            </a>
        </div>
    </article>
    <?php
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <?php
    $page_title = 'لوحة التحكم';
    include __DIR__ . '/includes/header-meta.php';
    ?>
</head>
<body>
    <?php
    include __DIR__ . '/includes/nav.php';
    render_nav($base);
    ?>

    <main>
        <?php if ($showBackupWarning): ?>
            <div class="alert alert-warning" id="backupStaleBanner" role="alert" style="display: flex; justify-content: space-between; align-items: center;">
                <div style="display: flex; align-items: center; gap: var(--space-3);">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="flex-shrink-0" aria-hidden="true">
                        <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"></path>
                        <line x1="12" y1="9" x2="12" y2="13"></line>
                        <line x1="12" y1="17" x2="12.01" y2="17"></line>
                    </svg>
                    <span><?= $backupWarningMessage ?></span>
                </div>
                <button type="button" class="btn-icon" id="dismissBackupWarningBtn" aria-label="إغلاق التنبيه" title="إغلاق" style="border: none; background: transparent; cursor: pointer; color: inherit; font-size: 1.25rem; line-height: 1; padding: var(--space-1);">
                    &times;
                </button>
            </div>
            <script>
            (function() {
                var banner = document.getElementById('backupStaleBanner');
                var btn = document.getElementById('dismissBackupWarningBtn');
                if (sessionStorage.getItem('taskflow_dismiss_backup_warning') === '1') {
                    if (banner) banner.setAttribute('hidden', '');
                }
                if (btn && banner) {
                    btn.addEventListener('click', function() {
                        banner.setAttribute('hidden', '');
                        sessionStorage.setItem('taskflow_dismiss_backup_warning', '1');
                    });
                }
            })();
            </script>
        <?php endif; ?>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'password_changed'): ?>
            <div class="alert alert-success" role="alert">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="flex-shrink-0"><polyline points="20 6 9 17 4 12"></polyline></svg>
                <span>تم تحديث كلمة المرور بنجاح.</span>
            </div>
        <?php endif; ?>

        <!-- Page Header with Greeting + New Project CTA -->
        <header class="page-header">
            <div class="page-title-wrap">
                <h1 class="page-title">مرحباً، <?= htmlspecialchars($userName) ?> 👋</h1>
                <p class="page-subtitle">تابع سير أعمالك ومشاريع فريقك بكل سهولة</p>
            </div>
            <div class="page-actions">
                <a href="<?= $base ?>projects/add-project.php" class="btn btn-primary">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    مشروع جديد
                </a>
            </div>
        </header>

        <?php if (is_admin()): ?>
            <!-- Admin View: My Projects & Team Projects -->
            <?php if (empty($myProjects) && empty($teamProjects)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
                    </div>
                    <h2 class="empty-state-title">لا توجد أي مشاريع بعد</h2>
                    <p class="empty-state-text">ابدأ بإنشاء أول مشروع في النظام لتنظيم المهام وتوزيعها على أعضاء الفريق.</p>
                    <a href="<?= $base ?>projects/add-project.php" class="btn btn-primary">+ إنشاء مشروع جديد</a>
                </div>
            <?php else: ?>
                <?php if (!empty($myProjects)): ?>
                    <section aria-labelledby="section-my-projects">
                        <h2 id="section-my-projects" class="section-title">
                            <span>مشاريعي</span>
                            <span class="section-badge tabular-nums"><?= count($myProjects) ?></span>
                        </h2>
                        <div class="projects-grid">
                            <?php foreach ($myProjects as $p) { render_project_card($p, $pdo, $user_id, $base); } ?>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if (!empty($teamProjects)): ?>
                    <section aria-labelledby="section-team-projects">
                        <h2 id="section-team-projects" class="section-title">
                            <span>مشاريع الفريق</span>
                            <span class="section-badge tabular-nums"><?= count($teamProjects) ?></span>
                        </h2>
                        <div class="projects-grid">
                            <?php foreach ($teamProjects as $p) { render_project_card($p, $pdo, $user_id, $base); } ?>
                        </div>
                    </section>
                <?php endif; ?>
            <?php endif; ?>

        <?php else: ?>
            <!-- Member View -->
            <?php if (empty($projects)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
                    </div>
                    <h2 class="empty-state-title">لا توجد مشاريع متاحة لك</h2>
                    <p class="empty-state-text">لم تقم بإنشاء أي مشاريع بعد، ولم يتم إسناد أي مهام لك في مشاريع أخرى.</p>
                    <a href="<?= $base ?>projects/add-project.php" class="btn btn-primary">+ إنشاء مشروع جديد</a>
                </div>
            <?php else: ?>
                <div class="projects-grid">
                    <?php foreach ($projects as $p) { render_project_card($p, $pdo, $user_id, $base); } ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>

    <!-- Delete Project Modal -->
    <div class="modal" id="deleteProjectModal" role="dialog" aria-modal="true" aria-labelledby="deleteProjectTitle">
        <div class="modal-backdrop" data-dismiss="modal"></div>
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title" id="deleteProjectTitle">حذف المشروع</h2>
                    <button type="button" class="modal-close" data-dismiss="modal" aria-label="إغلاق">&times;</button>
                </div>
                <form method="POST" action="<?= $base ?>projects/delete-project.php" id="deleteProjectForm">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="id" id="delete_modal_project_id" value="">
                    <div class="modal-body">
                        <p class="modal-alert-text">
                            هل أنت متأكد من رغبتك في حذف المشروع:
                            <strong id="delete_modal_project_title"></strong>؟
                        </p>
                        <div class="alert alert-warning" role="alert" style="margin-top: 1rem;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="flex-shrink-0"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                            <span>تنبيه: سيؤدي حذف هذا المشروع إلى حذف كافة المهام التابعة له نهائياً.</span>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>
                        <button type="submit" class="btn btn-danger">تأكيد الحذف</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.open-delete-project-modal-btn');
        if (!btn) return;
        const pid = btn.dataset.projectId;
        const title = btn.dataset.projectTitle;
        document.getElementById('delete_modal_project_id').value = pid;
        document.getElementById('delete_modal_project_title').textContent = title;
        if (typeof openModal === 'function') {
            openModal('deleteProjectModal');
        }
    });
    </script>
</body>
</html>