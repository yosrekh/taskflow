<?php
// manifest.php - Dynamic Web App Manifest reflecting custom branding
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/manifest+json; charset=utf-8');

$branding = get_branding();
$appName = $branding['app_name'];

// Favicon URL for manifest icon
$faviconUrl = $branding['favicon_url'];
$cleanFavicon = strtok($faviconUrl, '?');
$isPng = str_ends_with(strtolower($cleanFavicon), '.png');

$manifest = [
    'name' => $appName,
    'short_name' => $appName,
    'description' => 'نظام إدارة المهام والمشاريع',
    'start_url' => './',
    'display' => 'standalone',
    'background_color' => '#0d4e65',
    'theme_color' => '#0d4e65',
    'icons' => [
        [
            'src' => $cleanFavicon,
            'sizes' => 'any',
            'type' => $isPng ? 'image/png' : 'image/svg+xml',
            'purpose' => 'any maskable'
        ]
    ]
];

echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
