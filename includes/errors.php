<?php
// includes/errors.php - Branded error handling and error rendering

// Global Safety Net Handlers
if (!defined('TASKFLOW_ERRORS_LOADED')) {
    define('TASKFLOW_ERRORS_LOADED', true);

    set_exception_handler(function (\Throwable $e) {
        error_log("Uncaught Exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "Uncaught Exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n");
            exit(1);
        }
        if (!headers_sent()) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            render_error(500, "حدث خطأ غير متوقع أثناء معالجة طلبك.");
        }
    });

    register_shutdown_function(function () {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            error_log("Fatal error: {$error['message']} in {$error['file']} on line {$error['line']}");
            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, "Fatal Error: {$error['message']} in {$error['file']} on line {$error['line']}\n");
                exit(1);
            }
            if (!headers_sent()) {
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                render_error(500, "حدث خطأ غير متوقع في النظام.");
            }
        }
    });
}

function is_json_request(): bool {
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $jsonEndpoints = [
        'get-tasks.php',
        'get-notifications.php',
        'update-status.php',
        'get-user-stats.php',
        'mark-notifications-read.php'
    ];
    if (in_array($script, $jsonEndpoints, true)) {
        return true;
    }
    if (!empty($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) {
        return true;
    }
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        return true;
    }
    return false;
}

function render_error(int $code, ?string $message = null): void {
    static $isRendering = false;
    if ($isRendering) {
        exit;
    }
    $isRendering = true;

    // Discard any partial output buffers
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($code);
    if ($code === 419) {
        header("HTTP/1.1 419 Page Expired", true, 419);
        header("Status: 419 Page Expired", true, 419);
    }

    $titles = [
        400 => 'طلب غير صالح',
        403 => 'غير مصرح لك بالوصول',
        404 => 'الصفحة غير موجودة',
        405 => 'طريقة الطلب غير مسموح بها',
        419 => 'انتهت صلاحية الصفحة',
        429 => 'عدد الطلبات كبير جداً',
        500 => 'حدث خطأ في النظام',
    ];

    $descriptions = [
        400 => 'الطلب الذي تم إرساله غير صالح أو تنقصه بعض البيانات المطلوبة.',
        403 => 'عذراً، ليس لديك الصلاحية الكافية للوصول إلى هذه الصفحة أو المورد المطلوب.',
        404 => 'عذراً، الصفحة أو المورد الذي تبحث عنه غير موجود أو قد تم نقله أو حذفه.',
        405 => 'طريقة الطلب المستخدمة غير مدعومة لهذا الإجراء.',
        419 => 'انتهت صلاحية الجلسة أو رمز التحقق (CSRF). يرجى تحديث الصفحة وإعادة المحاولة.',
        429 => 'لقد قمت بإرسال عدد كبير من الطلبات في وقت قصير. يرجى الانتظار قليلاً والمحاولة لاحقاً.',
        500 => 'حدث خطأ غير متوقع أثناء معالجة طلبك في النظام. تم تسجيل تفاصيل الخطأ للعمل على حله.',
    ];

    $title = $titles[$code] ?? "خطأ {$code}";
    $displayMessage = $message ?: ($descriptions[$code] ?? 'حدث خطأ غير متوقع أثناء معالجة طلبك.');

    // JSON response handling
    if (is_json_request()) {
        header('Content-Type: application/json; charset=utf-8');
        $errorKey = match ($code) {
            400 => 'bad_request',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            419 => 'csrf_token_expired',
            429 => 'too_many_requests',
            default => 'server_error',
        };
        echo json_encode([
            'success' => false,
            'error' => $errorKey,
            'message' => $displayMessage,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Determine relative base URL path to TaskFlow root
    $projectRoot = str_replace('\\', '/', dirname(__DIR__));
    $scriptDir = str_replace('\\', '/', dirname(realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: getcwd()));
    $rel = str_replace($projectRoot, '', $scriptDir);
    $rel = trim($rel, '/');
    if ($rel === '') {
        $base = './';
    } else {
        $depth = count(explode('/', $rel));
        $base = str_repeat('../', $depth);
    }

    // Default branding fallback (used when database is down or unconfigured)
    $appName = 'TaskFlow';
    $logoSrcLight = $base . 'assets/logo-horizontal-light.svg';
    $logoSrcDark = $base . 'assets/logo-horizontal-dark.svg';
    $faviconUrl = $base . 'assets/favicon.svg';
    $faviconDarkUrl = null;

    // Safely attempt dynamic branding only if PDO is connected and active
    try {
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            if (!function_exists('get_branding')) {
                @include_once __DIR__ . '/branding.php';
            }
            if (function_exists('get_branding')) {
                $customBranding = get_branding($base);
                if (!empty($customBranding['app_name'])) {
                    $appName = $customBranding['app_name'];
                }
                if (!empty($customBranding['logo_light_url'])) {
                    $logoSrcLight = $customBranding['logo_light_url'];
                }
                if (!empty($customBranding['logo_dark_url'])) {
                    $logoSrcDark = $customBranding['logo_dark_url'];
                }
                if (!empty($customBranding['favicon_url'])) {
                    $faviconUrl = $customBranding['favicon_url'];
                }
                if (!empty($customBranding['favicon_dark_url'])) {
                    $faviconDarkUrl = $customBranding['favicon_dark_url'];
                }
            }
        }
    } catch (\Throwable $e) {
        // Suppress and retain safe defaults - database down resilience
    }

    // Explicit branding structure for header-meta.php
    $branding = [
        'app_name' => $appName,
        'logo_light_url' => $logoSrcLight,
        'logo_dark_url' => $logoSrcDark,
        'favicon_url' => $faviconUrl,
        'favicon_dark_url' => $faviconDarkUrl,
        'is_custom_logo_dark' => false,
        'is_custom_logo_light' => false,
        'is_custom_favicon' => false,
        'is_custom_favicon_dark' => false,
    ];

    // Session & navigation links
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        @session_start();
    }
    $isLoggedIn = !empty($_SESSION['user_id']);
    $homeUrl = $isLoggedIn ? ($base . 'dashboard.php') : ($base . 'login.php');
    $homeLabel = $isLoggedIn ? 'لوحة التحكم' : 'تسجيل الدخول';
    $page_title = $title;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <?php include __DIR__ . '/header-meta.php'; ?>
</head>
<body class="auth-wrapper">
    <div class="auth-card error-card">
        <div class="auth-header">
            <a href="<?= htmlspecialchars($homeUrl) ?>" class="auth-brand" aria-label="<?= htmlspecialchars($appName) ?>">
                <img src="<?= htmlspecialchars($logoSrcLight) ?>" alt="<?= htmlspecialchars($appName) ?>" class="brand-logo brand-logo-light">
                <img src="<?= htmlspecialchars($logoSrcDark) ?>" alt="<?= htmlspecialchars($appName) ?>" class="brand-logo brand-logo-dark">
            </a>
            <div class="error-code-badge" aria-hidden="true"><?= (int)$code ?></div>
            <h1 class="auth-title"><?= htmlspecialchars($title) ?></h1>
            <p class="auth-subtitle"><?= htmlspecialchars($displayMessage) ?></p>
        </div>

        <div class="error-actions">
            <button type="button" class="btn btn-secondary" onclick="if (window.history.length > 1) { window.history.back(); } else { window.location.href = '<?= htmlspecialchars($homeUrl, ENT_QUOTES) ?>'; }">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"></polyline></svg>
                رجوع
            </button>
            <a href="<?= htmlspecialchars($homeUrl) ?>" class="btn btn-primary">
                <?= htmlspecialchars($homeLabel) ?>
            </a>
        </div>
    </div>
</body>
</html>
<?php
    exit;
}
