<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
include '../includes/db.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title = $_POST['title'];
    $description = $_POST['description'];
    $user_id = $_SESSION['user_id'];

    try {
        $stmt = $pdo->prepare("INSERT INTO projects (user_id, title, description) VALUES (?, ?, ?)");
        $stmt->execute([$user_id, $title, $description]);
        $success = "تم إنشاء المشروع بنجاح.";
    } catch (PDOException $e) {
        $error = "فشل في إنشاء المشروع.";
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>إنشاء مشروع - TaskFlow</title>
    <link rel="stylesheet" href="../../css/styles.css">
    <style>
        body {
            background: linear-gradient(135deg, #232526 0%, #414345 100%);
            min-height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .pro-form-container {
            background: rgba(255,255,255,0.07);
            border-radius: 24px;
            box-shadow: 0 8px 32px 0 rgba(31,38,135,0.37);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border: 1px solid rgba(255,255,255,0.18);
            padding: 48px 32px 32px 32px;
            text-align: center;
            max-width: 420px;
            width: 100%;
            animation: fadeInUp 1s cubic-bezier(.39,.575,.565,1.000) both;
        }
        .pro-form-container h2 {
            color: #1abc9c;
            margin-bottom: 18px;
            font-size: 1.5rem;
            text-shadow: 0 2px 8px rgba(26,188,156,0.15);
        }
        .pro-form-container label {
            color: #fff;
            display: block;
            text-align: right;
            margin-bottom: 6px;
            font-size: 1rem;
            opacity: 0.85;
        }
        .pro-form-container input,
        .pro-form-container textarea {
            width: 100%;
            padding: 12px;
            margin-bottom: 18px;
            border: none;
            border-radius: 8px;
            background: rgba(255,255,255,0.15);
            color: #FFF;
            font-size: 1rem;
            transition: box-shadow 0.2s;
        }
        .pro-form-container input:focus,
        .pro-form-container textarea:focus {
            outline: none;
            box-shadow: 0 0 0 2px #1abc9c;
        }
        .pro-form-container button {
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
         .btn {
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



        .pro-form-container button:hover {
            transform: translateY(-2px) scale(1.03);
            box-shadow: 0 8px 24px rgba(26,188,156,0.25);
        }
        .pro-form-container .error {
            color: #e74c3c;
            background: rgba(231,76,60,0.08);
            border-radius: 6px;
            padding: 10px;
            margin-bottom: 18px;
            font-size: 1rem;
        }
        .pro-form-container .success {
            color: #27ae60;
            background: rgba(39,174,96,0.08);
            border-radius: 6px;
            padding: 10px;
            margin-bottom: 18px;
            font-size: 1rem;
        }
        @keyframes fadeInUp {
            0% { opacity: 0; transform: translateY(40px); }
            100% { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>
<div class="pro-form-container">
<a href="../dashboard.php" class="btn"> الرجوع إلى قائمة المشاريع </a>

    <h2>إنشاء مشروع جديد</h2>
    <?php if ($error): ?>
        <p class="error"><?= $error ?></p>
    <?php elseif ($success): ?>
        <p class="success"><?= $success ?></p>
    <?php endif; ?>

    <form method="POST">
        <label>عنوان المشروع:</label>
        <input type="text" name="title" required>

        <label>وصف المشروع:</label>
        <textarea name="description" rows="5"></textarea>

        <button type="submit">حفظ المشروع</button>
    </form>
</div>

<script src="../../js/main.js"></script>
</body>
</html>