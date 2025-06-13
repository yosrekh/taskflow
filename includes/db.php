<?php

$host = "localhost";
$dbname = "taskflow_db";
$username = "root"; // عادة root في XAMPP
$password = "";     // عادة فارغ في XAMPP

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>