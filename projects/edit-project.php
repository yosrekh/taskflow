<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
include '../includes/db.php';

$project_id = $_GET['id'] ?? null;

if (!$project_id) {
    die("رقم المشروع غير موجود.");
}

// Fetch project data
$stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
$stmt->execute([$project_id]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$project) {
    die("المشروع غير موجود.");
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title = $_POST['title'];
    $description = $_POST['description'];

    try {
        $stmt = $pdo->prepare("UPDATE projects SET title = ?, description = ? WHERE id = ?");
        $stmt->execute([$title, $description, $project_id]);
        $success = "تم تحديث المشروع بنجاح.";
    } catch (PDOException $e) {
        $error = "فشل في تحديث المشروع.";
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>تعديل المشروع - <?= htmlspecialchars($project['title']) ?></title>
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
            color: #222;
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
        .back-btn {
            display: inline-block;
            margin-bottom: 18px;
            background: linear-gradient(90deg, #3498db 0%, #2980b9 100%);
            color: #fff;
            padding: 8px 22px;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: bold;
            text-decoration: none;
            transition: background 0.2s, transform 0.2s;
            box-shadow: 0 2px 8px rgba(52,152,219,0.10);
        }
        .back-btn:hover {
            background: linear-gradient(90deg, #2980b9 0%, #3498db 100%);
            transform: translateY(-2px) scale(1.04);
        }
        @keyframes fadeInUp {
            0% { opacity: 0; transform: translateY(40px); }
            100% { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>

<div class="pro-form-container">
    <a href="../dashboard.php" class="back-btn">&larr; العودة إلى لوحة التحكم</a>
    <h2>تعديل المشروع</h2>
    <?php if ($error): ?>
        <p class="error"><?= $error ?></p>
    <?php elseif ($success): ?>
        <p class="success"><?= $success ?></p>
    <?php endif; ?>

    <form method="POST">
        <label>عنوان المشروع:</label>
        <input type="text" name="title" value="<?= htmlspecialchars($project['title']) ?>" required>

        <label>وصف المشروع:</label>
        <textarea name="description" rows="5"><?= htmlspecialchars($project['description']) ?></textarea>

        <button type="submit">تحديث المشروع</button>
    </form>
</div>

<script src="../../js/main.js"></script>
</body>
</html>