<?php
function render_nav($base = '') {
    if (!isset($_SESSION['user_id'])) return;
    ?>
    <nav style="position:fixed;top:0;right:0;left:0;height:58px;background:rgba(30,42,60,0.92);backdrop-filter:blur(6px);display:flex;align-items:center;justify-content:space-between;padding:0 32px;z-index:1001;box-shadow:0 2px 12px rgba(26,188,156,0.10);">
        <div style="display:flex;align-items:center;gap:18px;">
            <a href="<?= $base ?>dashboard.php" style="color:#1abc9c;font-weight:bold;font-size:1.2rem;text-decoration:none;letter-spacing:1px;">TaskFlow</a>
            <a href="<?= $base ?>projects/add-project.php" style="color:#fff;font-size:1rem;text-decoration:none;opacity:0.85;">+ مشروع جديد</a>
            <a href="<?= $base ?>dashboard.php" style="color:#fff;font-size:1rem;text-decoration:none;opacity:0.85;">لوحة التحكم</a>
            <a href="<?= $base ?>change-password.php" style="color:#fff;font-size:1rem;text-decoration:none;opacity:0.85;">تغيير كلمة المرور</a>
            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                <a href="<?= $base ?>admin/users.php" style="color:#fff;font-size:1rem;text-decoration:none;opacity:0.85;">المستخدمين</a>
            <?php endif; ?>
        </div>
        <div style="display:flex;align-items:center;gap:24px;position:relative;">
            <div id="notif-bell-container" style="position:relative;">
                <button id="notif-bell" style="background:none;border:none;cursor:pointer;padding:0;position:relative;">
                    <svg width="28" height="28" fill="#1abc9c" viewBox="0 0 24 24"><path d="M12 2C8.13 2 5 5.13 5 9v5c0 .55-.45 1-1 1H3v2h18v-2h-1c-.55 0-1-.45-1-1V9c0-3.87-3.13-7-7-7zm0 19c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2z"/></svg>
                    <span id="notif-count" style="position:absolute;top:-6px;right:-6px;background:#e74c3c;color:#fff;border-radius:50%;padding:2px 7px;font-size:0.8rem;font-weight:bold;display:none;">0</span>
                </button>
                <div id="notif-dropdown" style="display:none;position:absolute;top:40px;right:auto;left:0;background:#fff;border-radius:10px;box-shadow:0 4px 16px rgba(0,0,0,0.13);min-width:260px;max-width:320px;z-index:10002;padding:12px 0;">
                    <div id="notif-list" style="max-height:320px;overflow-y:auto;"></div>
                </div>
            </div>
            <form method="POST" action="<?= $base ?>logout.php" style="display:inline;margin:0;">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <button type="submit" class="btn logout-btn" style="background:linear-gradient(90deg,#e74c3c 0%,#c0392b 100%);color:#fff;padding:8px 18px;border-radius:8px;font-weight:bold;text-decoration:none;border:none;cursor:pointer;font-family:inherit;font-size:1rem;">تسجيل الخروج</button>
            </form>
        </div>
    </nav>
    <div style="height:58px;"></div>
    <script>
    // Notification Bell Logic
    function fetchNotifications(updateCountOnly = false, markRead = false) {
        let url = '<?= $base ?>get-notifications.php';
        let fetchOptions = { method: 'GET' };
        if (markRead) {
            url += '?mark_read=1';
            fetchOptions = {
                method: 'POST',
                headers: {
                    'X-CSRF-Token': '<?= csrf_token() ?>'
                }
            };
        }
        fetch(url, fetchOptions)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    const notifCount = document.getElementById('notif-count');
                    if (markRead || !data.notifications.some(n => n.is_read == 0)) {
                        notifCount.style.display = 'none';
                    } else if (data.notifications.length > 0) {
                        notifCount.textContent = data.notifications.filter(n => n.is_read == 0).length;
                        notifCount.style.display = 'inline-block';
                    } else {
                        notifCount.style.display = 'none';
                    }
                    if (!updateCountOnly) {
                        const notifList = document.getElementById('notif-list');
                        notifList.innerHTML = '';
                        if (data.notifications.length > 0) {
                            data.notifications.forEach(n => {
                                let msg = n.message;
                                let time = '';
                                let actor = '';
                                let action = '';
                                let task = '';
                                let project = '';
                                let status = '';
                                const match = msg.match(/^\[(.*?)\]\s+(.*?)\s+(أضاف مهمة جديدة|عدّل مهمة|حذف المهمة|غيّر حالة المهمة)\s+'(.*?)'\s+(?:إلى\s+(.*?)\s+)?في مشروع\s+'(.*?)'/);
                                if (match) {
                                    time = match[1];
                                    actor = match[2];
                                    action = match[3];
                                    task = match[4];
                                    status = match[5] || '';
                                    project = match[6];
                                }
                                const item = document.createElement('div');
                                item.style.padding = '12px 18px';
                                item.style.borderBottom = '1px solid #eee';
                                item.style.fontSize = '1rem';
                                item.style.wordBreak = 'break-word';
                                item.style.display = 'flex';
                                item.style.flexDirection = 'column';
                                if (n.is_read == 0) {
                                    item.style.background = '#eafaf1';
                                    item.style.fontWeight = 'bold';
                                } else {
                                    item.style.background = '#fff';
                                    item.style.opacity = '0.7';
                                }
                                if (match) {
                                    const actorEl = document.createElement('div');
                                    actorEl.style.cssText = 'font-size:0.97em;color:#1abc9c;font-weight:bold;';
                                    actorEl.textContent = actor;

                                    const actionEl = document.createElement('div');
                                    actionEl.style.fontSize = '0.97em';
                                    actionEl.textContent = action + " '" + task + "'";
                                    if (action === 'غيّر حالة المهمة' && status) {
                                        actionEl.textContent += ' إلى ' + status;
                                    }

                                    const projectEl = document.createElement('div');
                                    projectEl.style.cssText = 'font-size:0.95em;color:#888;';
                                    projectEl.textContent = project;

                                    const timeEl = document.createElement('div');
                                    timeEl.style.cssText = 'font-size:0.92em;color:#aaa;';
                                    timeEl.textContent = time;

                                    item.appendChild(actorEl);
                                    item.appendChild(actionEl);
                                    item.appendChild(projectEl);
                                    item.appendChild(timeEl);
                                } else {
                                    item.textContent = msg;
                                }
                                notifList.appendChild(item);
                            });
                        } else {
                            notifList.innerHTML = '<div style="padding:14px;color:#888;text-align:center;">لا توجد إشعارات</div>';
                        }
                    }
                }
            });
    }
    let notifOpen = false;
    document.getElementById('notif-bell').addEventListener('click', function(e) {
        const dropdown = document.getElementById('notif-dropdown');
        const notifCount = document.getElementById('notif-count');
        notifOpen = !notifOpen;
        dropdown.style.display = notifOpen ? 'block' : 'none';
        notifCount.style.display = notifOpen ? 'none' : (notifCount.textContent !== '0' ? 'inline-block' : 'none');
        if (notifOpen) {
            fetchNotifications(false, true);
            setTimeout(function() {
                const rect = dropdown.getBoundingClientRect();
                let offset = 0;
                if (rect.right > window.innerWidth) {
                    offset = rect.right - window.innerWidth + 16;
                }
                if (rect.left - offset < 0) {
                    offset = rect.left - 8;
                }
                dropdown.style.transform = offset ? `translateX(-${offset}px)` : '';
            }, 50);
        } else {
            dropdown.style.transform = '';
        }
        e.stopPropagation();
    });
    document.addEventListener('click', function(e) {
        const dropdown = document.getElementById('notif-dropdown');
        if (dropdown && !dropdown.contains(e.target) && e.target.id !== 'notif-bell') {
            dropdown.style.display = 'none';
            notifOpen = false;
        }
    });
    setInterval(function() {
        fetchNotifications(true);
        if (notifOpen) fetchNotifications(false);
    }, 10000);
    fetchNotifications(true);
    </script>
    <?php
}
?>
