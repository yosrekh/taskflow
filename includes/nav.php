<?php
function render_nav($base = '') {
    if (!isset($_SESSION['user_id'])) return;

    $currentScript = basename($_SERVER['PHP_SELF'] ?? '');
    $isDashboard = ($currentScript === 'dashboard.php');
    $isUsers = ($currentScript === 'users.php');
    $userName = $_SESSION['user_name'] ?? 'مستخدم';
    $userInitials = function_exists('get_user_initials') ? get_user_initials($userName) : 'TF';
    $isAdminUser = function_exists('is_admin') && is_admin();
    $isBranding = ($currentScript === 'branding.php');
    $branding = get_branding($base);
    ?>
    <nav class="app-nav" aria-label="التنقل الرئيسي">
        <div class="nav-container">
            <div class="nav-start">
                <a href="<?= $base ?>dashboard.php" class="nav-brand" aria-label="<?= htmlspecialchars($branding['app_name']) ?> الرئيسية">
                    <img src="<?= htmlspecialchars($branding['logo_dark_url']) ?>" alt="<?= htmlspecialchars($branding['app_name']) ?>" class="brand-logo">
                </a>
                <ul class="nav-links" id="nav-links-menu">
                    <li>
                        <a href="<?= $base ?>dashboard.php" class="nav-link <?= $isDashboard ? 'is-active' : '' ?>">لوحة التحكم</a>
                    </li>
                    <?php if ($isAdminUser): ?>
                    <li>
                        <a href="<?= $base ?>admin/users.php" class="nav-link <?= $isUsers ? 'is-active' : '' ?>">المستخدمين</a>
                    </li>
                    <li>
                        <a href="<?= $base ?>admin/branding.php" class="nav-link <?= $isBranding ? 'is-active' : '' ?>">الهوية البصرية</a>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>

            <div class="nav-end">
                <!-- Theme Toggle Button -->
                <button type="button" class="nav-icon-btn theme-toggle-btn" onclick="toggleTheme()" aria-label="تبديل الوضع">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="5"></circle>
                        <line x1="12" y1="1" x2="12" y2="3"></line>
                        <line x1="12" y1="21" x2="12" y2="23"></line>
                        <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
                        <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
                        <line x1="1" y1="12" x2="3" y2="12"></line>
                        <line x1="21" y1="12" x2="23" y2="12"></line>
                        <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
                        <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
                    </svg>
                </button>

                <!-- Notification Bell -->
                <div class="nav-user-dropdown" id="notif-bell-container">
                    <button type="button" class="nav-icon-btn" id="notif-bell" aria-label="الإشعارات" aria-expanded="false">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                            <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                        </svg>
                        <span class="nav-badge d-none" id="notif-count">0</span>
                    </button>
                    <div class="dropdown-menu notif-dropdown-menu" id="notif-dropdown">
                        <div class="notif-dropdown-header">
                            <span class="card-title notif-title">الإشعارات</span>
                            <button type="button" class="btn btn-ghost btn-sm notif-mark-btn" id="notif-mark-read-btn">تحديد الكل كمقروء</button>
                        </div>
                        <div class="notif-dropdown-list" id="notif-list">
                            <div class="notif-empty">جاري تحميل الإشعارات...</div>
                        </div>
                    </div>
                </div>

                <!-- User Profile Menu -->
                <div class="nav-user-dropdown" id="user-menu-container">
                    <button type="button" class="nav-user-trigger" id="user-menu-btn" aria-expanded="false" aria-label="قائمة المستخدم">
                        <span class="avatar avatar-sm"><?= htmlspecialchars($userInitials) ?></span>
                        <span class="nav-user-name"><?= htmlspecialchars($userName) ?></span>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>
                    </button>
                    <div class="dropdown-menu" id="user-dropdown-menu">
                        <div class="dropdown-header">
                            <div class="dropdown-header-name"><?= htmlspecialchars($userName) ?></div>
                            <div class="role-badge-wrap">
                                <span class="badge <?= $isAdminUser ? 'badge-admin' : 'badge-member' ?>"><?= $isAdminUser ? 'مسؤول' : 'عضو' ?></span>
                            </div>
                        </div>
                        <a href="<?= $base ?>change-password.php" class="dropdown-item">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                            تغيير كلمة المرور
                        </a>
                        <div class="dropdown-divider"></div>
                        <form method="POST" action="<?= $base ?>logout.php" class="inline-form">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <button type="submit" class="dropdown-item dropdown-item-danger">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                                تسجيل الخروج
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Mobile Menu Button -->
                <button type="button" class="nav-mobile-btn" id="nav-mobile-btn" aria-label="فتح القائمة" aria-expanded="false">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
                </button>
            </div>
        </div>
    </nav>
    <div class="app-nav-spacer"></div>

    <script>
    (function () {
        // Notification bell & user dropdowns
        const notifBell = document.getElementById('notif-bell');
        const notifDropdown = document.getElementById('notif-dropdown');
        const userBtn = document.getElementById('user-menu-btn');
        const userDropdown = document.getElementById('user-dropdown-menu');
        const mobileBtn = document.getElementById('nav-mobile-btn');
        const navLinks = document.getElementById('nav-links-menu');
        const markReadBtn = document.getElementById('notif-mark-read-btn');

        function closeAllDropdowns() {
            if (notifDropdown) notifDropdown.classList.remove('is-open');
            if (userDropdown) userDropdown.classList.remove('is-open');
            if (notifBell) notifBell.setAttribute('aria-expanded', 'false');
            if (userBtn) userBtn.setAttribute('aria-expanded', 'false');
        }

        if (notifBell && notifDropdown) {
            notifBell.addEventListener('click', function(e) {
                e.stopPropagation();
                const isOpen = notifDropdown.classList.contains('is-open');
                closeAllDropdowns();
                if (!isOpen) {
                    notifDropdown.classList.add('is-open');
                    notifBell.setAttribute('aria-expanded', 'true');
                    fetchNotifications(false);
                }
            });
        }

        if (userBtn && userDropdown) {
            userBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                const isOpen = userDropdown.classList.contains('is-open');
                closeAllDropdowns();
                if (!isOpen) {
                    userDropdown.classList.add('is-open');
                    userBtn.setAttribute('aria-expanded', 'true');
                }
            });
        }

        if (mobileBtn && navLinks) {
            mobileBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                const isOpen = navLinks.classList.contains('is-open');
                navLinks.classList.toggle('is-open');
                mobileBtn.setAttribute('aria-expanded', !isOpen);
            });
        }

        document.addEventListener('click', function(e) {
            if (!e.target.closest('#notif-bell-container') && !e.target.closest('#user-menu-container')) {
                closeAllDropdowns();
            }
            if (navLinks && !e.target.closest('#nav-links-menu') && !e.target.closest('#nav-mobile-btn')) {
                navLinks.classList.remove('is-open');
                if (mobileBtn) mobileBtn.setAttribute('aria-expanded', 'false');
            }
        });

        // Notifications fetching
        function fetchNotifications(countOnly = false, markRead = false) {
            let url = '<?= $base ?>get-notifications.php';
            let fetchOptions = { method: 'GET' };
            if (markRead) {
                url += '?mark_read=1';
                fetchOptions = {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': '<?= csrf_token() ?>' }
                };
            }
            fetch(url, fetchOptions)
                .then(r => r.json())
                .then(data => {
                    if (!data.success) return;
                    const notifCount = document.getElementById('notif-count');
                    const unread = data.notifications ? data.notifications.filter(n => n.is_read == 0).length : 0;
                    if (notifCount) {
                        if (unread > 0) {
                            notifCount.textContent = unread > 99 ? '99+' : unread;
                            notifCount.classList.remove('d-none');
                        } else {
                            notifCount.classList.add('d-none');
                        }
                    }
                    if (!countOnly) {
                        renderNotificationList(data.notifications || []);
                    }
                })
                .catch(() => {});
        }

        function renderNotificationList(items) {
            const listEl = document.getElementById('notif-list');
            if (!listEl) return;
            if (items.length === 0) {
                listEl.innerHTML = '<div class="notif-empty">لا توجد إشعارات حالياً</div>';
                return;
            }
            listEl.innerHTML = items.map(n => {
                const unreadClass = (n.is_read == 0) ? 'is-unread' : '';
                return `
                    <div class="notif-item ${unreadClass}">
                        <div class="notif-item-msg">${escapeHtml(n.message)}</div>
                        <div class="notif-item-time">${escapeHtml(n.created_at)}</div>
                    </div>
                `;
            }).join('');
        }

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        if (markReadBtn) {
            markReadBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                fetchNotifications(false, true);
            });
        }

        // Visibility & interval polling
        let notifTimer = null;
        function poll() {
            fetchNotifications(true);
        }
        function start() {
            if (!notifTimer) notifTimer = setInterval(poll, 30000);
        }
        function stop() {
            if (notifTimer) { clearInterval(notifTimer); notifTimer = null; }
        }

        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                stop();
            } else {
                poll();
                start();
            }
        });

        start();
        poll();
    })();
    </script>
    <?php
}
