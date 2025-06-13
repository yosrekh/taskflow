<?php
session_start();
include 'includes/db.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = $_POST['name'];
    $email = $_POST['email'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

    try {
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
        $stmt->execute([$name, $email, $password]);
        $success = "تم التسجيل بنجاح! يمكنك تسجيل الدخول الآن.";
    } catch (PDOException $e) {
        $error = "فشل التسجيل. البريد موجود مسبقًا.";
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>إنشاء حساب - TaskFlow</title>
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
        .pro-auth-container {
            background: rgba(255,255,255,0.07);
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
            font-size: 2.2rem;
            font-weight: bold;
            color: #1abc9c;
            margin-bottom: 12px;
            letter-spacing: 2px;
            text-shadow: 0 2px 8px rgba(26,188,156,0.2);
        }
        .pro-auth-container h2 {
            color: #fff;
            margin-bottom: 18px;
            font-size: 1.5rem;
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
            transform: translateY(-2px) scale(1.03);
            box-shadow: 0 8px 24px rgba(26,188,156,0.25);
        }
        .pro-auth-container .error {
            color: #e74c3c;
            background: rgba(231,76,60,0.08);
            border-radius: 6px;
            padding: 10px;
            margin-bottom: 18px;
            font-size: 1rem;
        }
        .pro-auth-container .success {
            color: #27ae60;
            background: rgba(39,174,96,0.08);
            border-radius: 6px;
            padding: 10px;
            margin-bottom: 18px;
            font-size: 1rem;
        }
        .pro-auth-container p {
            color: #fff;
            opacity: 0.85;
        }
        .pro-auth-container a {
            color: #1abc9c;
            text-decoration: none;
            font-weight: bold;
            transition: color 0.2s;
        }
        .pro-auth-container a:hover {
            color: #16a085;
        }
        @keyframes fadeInUp {
            0% { opacity: 0; transform: translateY(40px); }
            100% { opacity: 1; transform: translateY(0); }
        }
        .loader {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #1abc9c;
            border-radius: 50%;
            width: 44px;
            height: 44px;
            animation: spin 1s linear infinite;
            margin: 0 auto 18px auto;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
    <script>
        // Optional: Animate loader on submit
        document.addEventListener('DOMContentLoaded', function() {
            var form = document.querySelector('form');
            if(form) {
                form.addEventListener('submit', function() {
                    document.getElementById('pro-loader').style.display = 'block';
                });
            }
        });
    </script>
</head>
<body>
    <div class="pro-auth-container">
        <div class="pro-logo">TaskFlow</div>
        <div id="pro-loader" class="loader" style="display:none;"></div>
        <h2>إنشاء حساب جديد</h2>
        <?php if ($error): ?>
            <p class="error"><?= $error ?></p>
        <?php elseif ($success): ?>
            <p class="success"><?= $success ?></p>
        <?php endif; ?>
        <form method="POST">
            <input type="text" name="name" placeholder="اسمك الكامل" required>
            <input type="email" name="email" placeholder="البريد الإلكتروني" required>
            <input type="password" name="password" placeholder="كلمة المرور" required>
            <input type="password" name="confirm_password" placeholder="تأكيد كلمة المرور" required>
            <button type="submit">تسجيل</button>
        </form>
        <p>لديك حساب بالفعل؟ <a href="login.php">سجل دخولك</a></p>
    </div>
</body>
</html>