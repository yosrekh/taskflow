<?php
// error.php - Entry point for web server redirects (Apache ErrorDocument, mod_rewrite)
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/errors.php';

$code = 404;
if (isset($_GET['code']) && is_numeric($_GET['code'])) {
    $code = (int)$_GET['code'];
} elseif (isset($_SERVER['REDIRECT_STATUS']) && is_numeric($_SERVER['REDIRECT_STATUS'])) {
    $code = (int)$_SERVER['REDIRECT_STATUS'];
}

$validCodes = [400, 401, 403, 404, 405, 419, 429, 500, 502, 503];
if (!in_array($code, $validCodes, true)) {
    $code = 404;
}

render_error($code);
