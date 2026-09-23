<?php
// includes/header-meta.php
// Single source of truth for <head> tags across all TaskFlow pages
$meta_base = $base ?? '';
$title_text = !empty($page_title) ? htmlspecialchars($page_title) . ' - TaskFlow' : 'TaskFlow';
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $title_text ?></title>

<!-- Theme Color Meta for Light & Dark -->
<meta name="theme-color" content="#0d4e65" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#071923" media="(prefers-color-scheme: dark)">

<!-- PWA Manifest -->
<link rel="manifest" href="<?= $meta_base ?>site.webmanifest">

<!-- Favicon SVGs (Light & Dark Variants) -->
<link rel="icon" type="image/svg+xml" href="<?= $meta_base ?>assets/icon-light.svg" media="(prefers-color-scheme: light)">
<link rel="icon" type="image/svg+xml" href="<?= $meta_base ?>assets/icon-dark.svg" media="(prefers-color-scheme: dark)">

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
