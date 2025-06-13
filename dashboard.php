<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
include 'includes/db.php';

$user_id = $_SESSION['user_id'];

// Get user info
$stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Get all projects for all users
$stmt = $pdo->prepare("SELECT projects.*, users.name AS owner_name FROM projects JOIN users ON projects.user_id = users.id ORDER BY projects.created_at DESC");
$stmt->execute();
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>لوحة التحكم - TaskFlow</title>
    <link rel="stylesheet" href="css/styles.css">
    <style>
        body {
            background: linear-gradient(135deg, #44434b  0%, #414345 100%);
            min-height: 100vh;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            margin: 0;
        }
        .pro-dashboard-container {
            background: rgba(255,255,255,0.07);
            border-radius: 24px;
            box-shadow: 0 8px 32px 0 rgba(31,38,135,0.37);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border: 1px solid rgba(255,255,255,0.18);
            padding: 40px 32px 32px 32px;
            margin-top: 40px;
            min-width: 350px;
            width: 100%;
            max-width: 100%;
            animation: fadeInUp 1s cubic-bezier(.39,.575,.565,1.000) both;
        }
        .pro-dashboard-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }
        .pro-dashboard-header h1 {
            color: #1abc9c;
            font-size: 2rem;
            margin: 0;
            text-shadow: 0 2px 8px rgba(26,188,156,0.15);
        }
        .pro-dashboard-header .btn {
            background: linear-gradient(90deg, #1abc9c 0%, #16a085 100%);
            color: #fff;
            padding: 10px 22px;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: bold;
            cursor: pointer;
            text-decoration: none;
            margin-left: 8px;
            transition: transform 0.2s, box-shadow 0.2s;
            box-shadow: 0 4px 16px rgba(26,188,156,0.15);
        }
        .pro-dashboard-header .btn:hover {
            transform: translateY(-2px) scale(1.03);
            box-shadow: 0 8px 24px rgba(26,188,156,0.25);
        }
        .pro-dashboard-header .logout-btn {
            background: linear-gradient(90deg, #e74c3c 0%, #c0392b 100%);
            color: #fff;
            margin-left: 0;
            margin-right: 8px;
        }
        .projects {
            margin-top: 16px;
        }
        .projects h2 {
            color: #fff;
            margin-bottom: 18px;
        }
        .projects ul {
            list-style: none;
            padding: 0;
            display: flex;
            flex-wrap: wrap;
            gap: 24px;
            justify-content: flex-start;
        }
        .projects li {
            background: linear-gradient(120deg, rgba(26,188,156,0.13) 0%, rgba(52,152,219,0.10) 100%);
            padding: 24px 20px 18px 20px;
            border-radius: 16px;
            box-shadow: 0 4px 24px 0 rgba(31,38,135,0.10);
            transition: transform 0.25s cubic-bezier(.39,.575,.565,1.000), box-shadow 0.25s;
            color: #fff;
            position: relative;
            overflow: hidden;
            border: 1.5px solid rgba(26,188,156,0.13);
            display: flex;
            flex-direction: column;
            gap: 10px;
            animation: fadeInUp 0.7s cubic-bezier(.39,.575,.565,1.000);
            /* min-width: 300px; */
            max-width: 380px;
            flex: 1 1 320px;
        }
        .projects li::before {
            content: '';
            position: absolute;
            top: -40px;
            right: -40px;
            width: 100px;
            height: 100px;
            background: radial-gradient(circle, rgba(26,188,156,0.18) 0%, rgba(52,152,219,0.10) 100%);
            z-index: 0;
            border-radius: 50%;
        }
        .projects li strong {
            font-size: 1.25rem;
            color: #1abc9c;
            z-index: 1;
            position: relative;
            margin-bottom: 2px;
        }
        .projects li small {
            color: #ecf0f1;
            font-size: 1rem;
            opacity: 0.85;
            z-index: 1;
            position: relative;
        }
        .projects .project-actions {
            display: flex;
            align-items: center;
            gap: 0;
        }
        .projects .project-actions a {
            background: linear-gradient(90deg, #1abc9c 0%, #16a085 100%);
            color: #fff;
            padding: 7px 16px;
            border-radius: 6px;
            font-size: 0.98rem;
            font-weight: 500;
            text-decoration: none;
            transition: background 0.2s, transform 0.2s;
            box-shadow: 0 2px 8px rgba(26,188,156,0.10);
            border: none;
            margin-left: 8px;
        }
        .projects .project-actions a:hover {
            background: linear-gradient(90deg, #16a085 0%, #1abc9c 100%);
            transform: translateY(-2px) scale(1.04);
        }
        .projects .project-actions a:last-child {
            background: linear-gradient(90deg, #3498db 0%, #2980b9 100%);
        }
        .projects .project-actions a:last-child:hover {
            background: linear-gradient(90deg, #2980b9 0%, #3498db 100%);
        }
        .projects .project-actions a[href*='delete'] {
            background: linear-gradient(90deg, #e74c3c 0%, #c0392b 100%);
        }
        .projects .project-actions a[href*='delete']:hover {
            background: linear-gradient(90deg, #c0392b 0%, #e74c3c 100%);
        }
        @keyframes fadeInUp {
            0% { opacity: 0; transform: translateY(40px); }
            100% { opacity: 1; transform: translateY(0); }
        }
        .icon-btn {
            background: none;
            border: none;
            padding: 4px 7px;
            border-radius: 6px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            transition: background 0.18s, transform 0.18s;
            margin-left: 2px;
        }
        .icon-btn svg {
            display: inline-block;
            vertical-align: middle;
        }
        .icon-btn[title*='تعديل'] svg use {
            stroke: #2980b9;
            fill: #2980b9;
        }
        .icon-btn[title*='حذف'] svg use {
            stroke: #e74c3c;
            fill: #e74c3c;
        }
        .icon-btn:hover {
            background: rgba(26,188,156,0.08);
            transform: scale(1.13);
        }
        .project-actions {
            display: flex;
            align-items: center;
            gap: 0;
        }
        .list-btn {
            display: inline-flex;
            align-items: center;
            background: linear-gradient(90deg, #1abc9c 0%, #16a085 100%);
            color: #fff;
            padding: 7px 16px;
            border-radius: 6px;
            font-size: 0.98rem;
            font-weight: 500;
            text-decoration: none;
            transition: background 0.2s, transform 0.2s;
            box-shadow: 0 2px 8px rgba(26,188,156,0.10);
            border: none;
            margin-left: 8px;
        }
        .list-btn:hover {
            background: linear-gradient(90deg, #16a085 0%, #1abc9c 100%);
            transform: translateY(-2px) scale(1.04);
        }
        .left-icon {
            margin-left: 0;
            margin-right: 0;
            background: none;
            box-shadow: none;
            padding: 4px 7px;
        }
        .left-icon svg use {
            stroke-width: 1.5;
        }
        .left-icon[title*='تعديل'] svg use {
            stroke: #2980b9;
            fill: #2980b9;
        }
        .left-icon[title*='حذف'] svg use {
            stroke: #e74c3c;
            fill: #e74c3c;
        }
        .left-icon:hover {
            background: rgba(26,188,156,0.08);
            transform: scale(1.13);
        }
        svg {
            display: none;
        }
        .left-icon svg, .icon-btn svg {
            stroke: black !important;
            fill: none !important;
            stroke-width: 2 !important;
        }
        .left-icon[title*='تعديل'] svg use,
        .left-icon[title*='حذف'] svg use {
            stroke: black !important;
            fill: none !important;
        }
        .icon-btn, .left-icon {
            background: none !important;
            box-shadow: none !important;
        }
        .left-icon svg {
            stroke: #888 !important;
            fill: none !important;
            stroke-width: 2 !important;
        }
        .left-icon[title*='حذف'] svg {
            stroke: #e74c3c !important;
        }
        .left-icon:hover svg {
            stroke: #232526 !important;
        }
        .list-btn svg {
            stroke: black !important;
            fill: none !important;
            stroke-width: 2 !important;
        }
    </style>
</head>
<body>
    <div class="pro-dashboard-container">
        <div class="pro-dashboard-header">
            <h1>مرحبًا، <?= htmlspecialchars($user['name']) ?> 👋</h1>
            <div>
                <a href="projects/add-project.php" class="btn">+ مشروع جديد</a>
                <a href="logout.php" class="logout-btn btn">تسجيل الخروج</a>
            </div>
        </div>
        <main>
            <section class="projects">
                <h2>مشاريعك</h2>
                <?php if (!empty($projects)): ?>
                    <ul>
                        <?php foreach ($projects as $project): ?>
                            <li>
                                <strong><?= htmlspecialchars($project['title']) ?></strong><br>
                                <small><?= htmlspecialchars($project['description']) ?></small><br>
                                <span style="color:#b2dfdb;font-size:0.95em;">مالك المشروع: <?= htmlspecialchars($project['owner_name']) ?></span>
                                <div class="project-actions">
                                    <a href="tasks/view-tasks.php?project_id=<?= $project['id'] ?>" title="عرض المهام" class="list-btn">
                                        <svg width="20" height="20" style="vertical-align:middle; margin-left:4px;"><use href="#icon-tasks-alt"/></svg>
                                        <span>عرض المهام</span>
                                    </a>
                                    <span style="flex:1"></span>
                                    <a href="projects/edit-project.php?id=<?= $project['id'] ?>" title="تعديل المشروع" class="icon-btn left-icon">
                                        <svg width="22" height="22"><use href="#icon-edit-stylish"/></svg>
                                    </a>
                                    <a href="projects/delete-project.php?id=<?= $project['id'] ?>" title="حذف المشروع" class="icon-btn left-icon" onclick="return confirm('هل أنت متأكد من الحذف؟')">
                                        <svg width="22" height="22"><use href="#icon-trash-alt"/></svg>
                                    </a>
                                </div>
                            </li>
                        <?php endforeach    ; ?>
                    </ul>
                <?php else: ?>
                    <p>لا توجد مشاريع بعد. ابدأ بإنشاء أول مشروع لك.</p>
                <?php endif; ?>
            </section>
        </main>
    </div>
    <svg style="display:none">
        <symbol id="icon-tasks-alt" viewBox="0 0 24 24"><circle cx="7" cy="7" r="2" stroke="black" stroke-width="2" fill="none"/><circle cx="7" cy="17" r="2" stroke="black" stroke-width="2" fill="none"/><rect x="11" y="6" width="10" height="2" rx="1" stroke="black" stroke-width="2" fill="none"/><rect x="11" y="16" width="10" height="2" rx="1" stroke="black" stroke-width="2" fill="none"/></symbol>
        <symbol id="icon-edit-stylish" viewBox="0 0 24 24">
            <path d="M4 20h4.586a1 1 0 0 0 .707-.293l9.414-9.414a2 2 0 0 0 0-2.828l-2.172-2.172a2 2 0 0 0-2.828 0l-9.414 9.414A1 1 0 0 0 4 15.414V20z" stroke="#888" stroke-width="2" fill="none"/>
            <path d="M14.5 7.5l2 2" stroke="#888" stroke-width="2" fill="none" stroke-linecap="round"/>
        </symbol>
        <symbol id="icon-trash-alt" viewBox="0 0 24 24"><rect x="5" y="7" width="14" height="12" rx="2" stroke="#e74c3c" stroke-width="2" fill="none"/><path d="M3 7h18M10 11v4M14 11v4" stroke="#e74c3c" stroke-width="2" fill="none"/><rect x="9" y="3" width="6" height="4" rx="1" stroke="#e74c3c" stroke-width="2" fill="none"/></symbol>
    </svg>
    <script src="../js/main.js"></script>
</body>
</html>