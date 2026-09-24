<?php
require_once __DIR__ . '/includes/auth.php';

// Immediate redirect based on session authentication state
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
} else {
    header("Location: login.php");
}
exit;