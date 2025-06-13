<?php
session_start();
// Pro skin redesign with animated loader and modern look
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>TaskFlow - الصفحة الرئيسية</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/styles.css">
    <style>
        body {
            background: linear-gradient(135deg, #232526 0%, #414345 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
        }
        .pro-container {
            background: rgba(255,255,255,0.05);
            border-radius: 24px;
            box-shadow: 0 8px 32px 0 rgba(31,38,135,0.37);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border: 1px solid rgba(255,255,255,0.18);
            padding: 48px 32px 32px 32px;
            text-align: center;
            max-width: 400px;
            width: 100%;
            animation: fadeInUp 1s cubic-bezier(.39,.575,.565,1.000) both;
        }
        .pro-logo {
            font-size: 2.5rem;
            font-weight: bold;
            color: #1abc9c;
            margin-bottom: 16px;
            letter-spacing: 2px;
            text-shadow: 0 2px 8px rgba(26,188,156,0.2);
        }
        .pro-desc {
            color: #fff;
            font-size: 1.2rem;
            margin-bottom: 32px;
            opacity: 0.85;
        }
        .pro-btn {
            display: inline-block;
            background: linear-gradient(90deg, #1abc9c 0%, #16a085 100%);
            color: #fff;
            padding: 12px 32px;
            border: none;
            border-radius: 8px;
            font-size: 1.1rem;
            font-weight: bold;
            cursor: pointer;
            box-shadow: 0 4px 16px rgba(26,188,156,0.15);
            transition: transform 0.2s, box-shadow 0.2s;
            text-decoration: none;
            margin: 0 8px;
        }
        .pro-btn:hover {
            transform: translateY(-3px) scale(1.04);
            box-shadow: 0 8px 24px rgba(26,188,156,0.25);
        }
        @keyframes fadeInUp {
            0% { opacity: 0; transform: translateY(40px); }
            100% { opacity: 1; transform: translateY(0); }
        }
        .loader {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #1abc9c;
            border-radius: 50%;
            width: 48px;
            height: 48px;
            animation: spin 1s linear infinite;
            margin: 0 auto 24px auto;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
    <script>
        // Loader animation before redirect
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                <?php if (isset($_SESSION['user_id'])): ?>
                    window.location.href = 'dashboard.php';
                <?php else: ?>
                    window.location.href = 'login.php';
                <?php endif; ?>
            }, 1500); // 1.5s loader
        });
    </script>
</head>
<body>
    <div class="pro-container">
        <div class="loader"></div>
        <div class="pro-logo">TaskFlow</div>
        <div class="pro-desc">منصة إدارة المهام والمشاريع باحترافية وسهولة</div>
        <a href="login.php" class="pro-btn">تسجيل الدخول</a>
        <a href="register.php" class="pro-btn" style="background:linear-gradient(90deg,#3498db 0%,#2980b9 100%)">إنشاء حساب</a>
    </div>
</body>
</html>