<?php
// includes/header-meta.php
// Single source of truth for <head> tags across all TaskFlow pages
$meta_base = $base ?? '';
if (!isset($branding)) {
    if (!function_exists('get_branding')) {
        @include_once __DIR__ . '/branding.php';
    }
    $branding = function_exists('get_branding') ? get_branding($meta_base) : [
        'app_name' => 'TaskFlow',
        'logo_dark_url' => $meta_base . 'assets/logo-horizontal-dark.svg',
        'logo_light_url' => $meta_base . 'assets/logo-horizontal-light.svg',
        'favicon_url' => $meta_base . 'assets/favicon.svg',
        'favicon_dark_url' => null,
    ];
}
$appName = $branding['app_name'] ?? 'TaskFlow';
$title_text = !empty($page_title) ? htmlspecialchars($page_title) . ' - ' . htmlspecialchars($appName) : htmlspecialchars($appName);
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $title_text ?></title>

<!-- Theme Color Meta for Light & Dark -->
<meta name="theme-color" content="#0d4e65" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#071923" media="(prefers-color-scheme: dark)">

<!-- PWA Manifest -->
<link rel="manifest" href="<?= $meta_base ?>manifest.php">

<!-- Favicon (Adaptive Light & Dark) -->
<?php if (!empty($branding['favicon_dark_url'])): ?>
<link rel="icon" href="<?= htmlspecialchars($branding['favicon_url']) ?>" media="(prefers-color-scheme: light)">
<link rel="icon" href="<?= htmlspecialchars($branding['favicon_dark_url']) ?>" media="(prefers-color-scheme: dark)">
<?php else: ?>
<?php
$favPath = strtok($branding['favicon_url'], '?');
$favType = str_ends_with(strtolower($favPath), '.png') ? 'image/png' : 'image/svg+xml';
?>
<link rel="icon" type="<?= $favType ?>" href="<?= htmlspecialchars($branding['favicon_url']) ?>">
<?php endif; ?>

<!-- Application Stylesheet -->
<link rel="stylesheet" href="<?= $meta_base ?>css/styles.css">

<!-- Global Interactive Utilities (Toasts, Modals, Theme) -->
<script src="<?= $meta_base ?>js/main.js" defer></script>

<!-- Anti-FOUC Theme Initializer: Runs synchronously before first paint -->
<script>
(function () {
    try {
        var savedTheme = localStorage.getItem('taskflow_theme');
        if (savedTheme === 'dark' || savedTheme === 'light') {
            document.documentElement.setAttribute('data-theme', savedTheme);
        } else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
            document.documentElement.setAttribute('data-theme', 'dark');
        } else {
            document.documentElement.setAttribute('data-theme', 'light');
        }
    } catch (e) {}
})();
</script>
