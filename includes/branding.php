<?php
// includes/branding.php
// Branding configuration, storage, dynamic retrieval, and secure upload processing

require_once __DIR__ . '/db.php';

// Per-request static cache for settings
function &get_settings_cache_ref(): array {
    static $cache = [
        'loaded' => false,
        'data' => []
    ];
    return $cache;
}

function reset_settings_cache(): void {
    $cache = &get_settings_cache_ref();
    $cache['loaded'] = false;
    $cache['data'] = [];
}

function get_all_settings(): array {
    $cache = &get_settings_cache_ref();
    if ($cache['loaded']) {
        return $cache['data'];
    }

    global $pdo;
    $cache['data'] = [];
    $cache['loaded'] = true;

    try {
        if ($pdo) {
            $stmt = $pdo->query("SELECT setting_key, setting_value, UNIX_TIMESTAMP(updated_at) AS updated_ts FROM settings");
            if ($stmt) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $cache['data'][$row['setting_key']] = [
                        'value' => $row['setting_value'],
                        'updated_at' => (int)($row['updated_ts'] ?: time())
                    ];
                }
            }
        }
    } catch (Exception $e) {
        // Fallback gracefully if table not yet created
    }

    return $cache['data'];
}

function get_setting(string $key, $default = null) {
    $settings = get_all_settings();
    return $settings[$key]['value'] ?? $default;
}

function get_setting_updated_at(string $key, int $default = 0): int {
    $settings = get_all_settings();
    return (int)($settings[$key]['updated_at'] ?? $default);
}

function set_setting(string $key, ?string $value): bool {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            INSERT INTO settings (setting_key, setting_value, updated_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
        ");
        $res = $stmt->execute([$key, $value]);
        reset_settings_cache();
        return $res;
    } catch (Exception $e) {
        error_log("Failed to save setting '{$key}': " . $e->getMessage());
        return false;
    }
}

function delete_setting(string $key): bool {
    global $pdo;
    try {
        $stmt = $pdo->prepare("DELETE FROM settings WHERE setting_key = ?");
        $res = $stmt->execute([$key]);
        reset_settings_cache();
        return $res;
    } catch (Exception $e) {
        error_log("Failed to delete setting '{$key}': " . $e->getMessage());
        return false;
    }
}

function get_branding(string $base = ''): array {
    $appName = get_setting('app_name', 'TaskFlow');
    if (!is_string($appName) || trim($appName) === '') {
        $appName = 'TaskFlow';
    }

    $logoDark = get_setting('logo_dark');
    $logoLight = get_setting('logo_light');
    $favicon = get_setting('favicon');
    $faviconDark = get_setting('favicon_dark');

    $logoDarkUpdated = get_setting_updated_at('logo_dark');
    $logoLightUpdated = get_setting_updated_at('logo_light');
    $faviconUpdated = get_setting_updated_at('favicon');
    $faviconDarkUpdated = get_setting_updated_at('favicon_dark');

    $projectRoot = dirname(__DIR__);

    $logoDarkUrl = ($logoDark && file_exists($projectRoot . '/' . $logoDark))
        ? $base . $logoDark . ($logoDarkUpdated ? '?v=' . $logoDarkUpdated : '')
        : $base . 'assets/logo-horizontal-dark.svg';

    $logoLightUrl = ($logoLight && file_exists($projectRoot . '/' . $logoLight))
        ? $base . $logoLight . ($logoLightUpdated ? '?v=' . $logoLightUpdated : '')
        : $base . 'assets/logo-horizontal-light.svg';

    $faviconUrl = ($favicon && file_exists($projectRoot . '/' . $favicon))
        ? $base . $favicon . ($faviconUpdated ? '?v=' . $faviconUpdated : '')
        : $base . 'assets/favicon.svg';

    $faviconDarkUrl = ($faviconDark && file_exists($projectRoot . '/' . $faviconDark))
        ? $base . $faviconDark . ($faviconDarkUpdated ? '?v=' . $faviconDarkUpdated : '')
        : null;

    return [
        'app_name' => $appName,
        'logo_dark_url' => $logoDarkUrl,
        'logo_light_url' => $logoLightUrl,
        'favicon_url' => $faviconUrl,
        'favicon_dark_url' => $faviconDarkUrl,
        'raw_logo_dark' => $logoDark,
        'raw_logo_light' => $logoLight,
        'raw_favicon' => $favicon,
        'raw_favicon_dark' => $faviconDark,
        'is_custom_logo_dark' => !empty($logoDark) && file_exists($projectRoot . '/' . $logoDark),
        'is_custom_logo_light' => !empty($logoLight) && file_exists($projectRoot . '/' . $logoLight),
        'is_custom_favicon' => !empty($favicon) && file_exists($projectRoot . '/' . $favicon),
        'is_custom_favicon_dark' => !empty($faviconDark) && file_exists($projectRoot . '/' . $faviconDark),
    ];
}

/**
 * Strict SVG Sanitizer using DOMDocument with LIBXML_NONET
 * Rejects DOCTYPE/ENTITY declarations
 * Strips disallowed elements, on* attributes, unsafe styles and external hrefs
 */
function sanitize_svg(string $rawSvg): ?string {
    // 1. Strictly reject any DOCTYPE or ENTITY declaration
    if (preg_match('/<!(?:DOCTYPE|ENTITY)\b/i', $rawSvg)) {
        return null;
    }

    // 2. Load into DOMDocument with LIBXML_NONET
    $dom = new DOMDocument();
    if (\PHP_VERSION_ID < 80000 && function_exists('libxml_disable_entity_loader')) {
        @libxml_disable_entity_loader(true);
    }
    libxml_use_internal_errors(true);

    $loaded = $dom->loadXML($rawSvg, LIBXML_NONET | LIBXML_NOBLANKS);
    if (!$loaded) {
        libxml_clear_errors();
        return null;
    }
    libxml_clear_errors();

    $root = $dom->documentElement;
    if (!$root || strtolower($root->nodeName) !== 'svg') {
        return null;
    }

    // 3. Allowlist of safe SVG elements
    $allowedElements = [
        'svg', 'g', 'path', 'polygon', 'polyline', 'rect', 'circle', 'ellipse',
        'line', 'text', 'tspan', 'defs', 'lineargradient', 'radialgradient',
        'stop', 'mask', 'pattern', 'clippath', 'use', 'style', 'desc', 'title'
    ];

    // Collect all element nodes in document order
    $elements = [];
    $queue = [$root];
    while (!empty($queue)) {
        $node = array_shift($queue);
        $elements[] = $node;
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $queue[] = $child;
            }
        }
    }

    foreach ($elements as $element) {
        $tag = strtolower($element->nodeName);

        // Strip disallowed elements (e.g. script, foreignObject, iframe, audio, etc.)
        if (!in_array($tag, $allowedElements, true)) {
            if ($element->parentNode) {
                $element->parentNode->removeChild($element);
            }
            continue;
        }

        // Check <style> elements: strip if contains @import or url()
        if ($tag === 'style') {
            $styleContent = $element->textContent;
            if (preg_match('/@import\b|url\s*\(/i', $styleContent)) {
                if ($element->parentNode) {
                    $element->parentNode->removeChild($element);
                }
                continue;
            }
        }

        // Inspect and sanitize attributes
        if ($element->hasAttributes()) {
            $attrsToRemove = [];
            foreach ($element->attributes as $attr) {
                $attrName = strtolower($attr->nodeName);
                $attrVal = trim($attr->nodeValue);

                // Strip any on* event handler
                if (str_starts_with($attrName, 'on')) {
                    $attrsToRemove[] = $attr->nodeName;
                    continue;
                }

                // Check href and xlink:href (must start with '#')
                if ($attrName === 'href' || $attrName === 'xlink:href' || str_ends_with($attrName, ':href')) {
                    if (!str_starts_with($attrVal, '#')) {
                        $attrsToRemove[] = $attr->nodeName;
                        continue;
                    }
                }

                // Check inline style attribute
                if ($attrName === 'style') {
                    if (preg_match('/url\s*\(|@import\b|expression\s*\(|behavior\s*:/i', $attrVal)) {
                        $attrsToRemove[] = $attr->nodeName;
                        continue;
                    }
                }
            }

            foreach ($attrsToRemove as $removeName) {
                $element->removeAttribute($removeName);
            }
        }
    }

    return $dom->saveXML($dom->documentElement);
}

/**
 * Ensure an SVG favicon has a square viewBox (1:1 aspect ratio), centering artwork with padding
 */
function ensure_square_svg_viewbox(string $svgXml): string {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    if (!$dom->loadXML($svgXml, LIBXML_NONET)) {
        libxml_clear_errors();
        return $svgXml;
    }
    libxml_clear_errors();

    $svg = $dom->documentElement;
    if (!$svg || strtolower($svg->nodeName) !== 'svg') {
        return $svgXml;
    }

    $viewBox = $svg->getAttribute('viewBox');
    $width = (float)$svg->getAttribute('width');
    $height = (float)$svg->getAttribute('height');

    $minX = 0; $minY = 0; $w = 0; $h = 0;
    if ($viewBox) {
        $parts = preg_split('/[\s,]+/', trim($viewBox));
        if (count($parts) === 4) {
            $minX = (float)$parts[0];
            $minY = (float)$parts[1];
            $w = (float)$parts[2];
            $h = (float)$parts[3];
        }
    }

    if ($w <= 0 || $h <= 0) {
        $w = $width > 0 ? $width : 512;
        $h = $height > 0 ? $height : 512;
    }

    // If already square within small tolerance, return as-is
    if (abs($w - $h) < 0.01) {
        return $svgXml;
    }

    if ($w > $h) {
        $diff = $w - $h;
        $newMinY = $minY - ($diff / 2);
        $newViewBox = "{$minX} {$newMinY} {$w} {$w}";
    } else {
        $diff = $h - $w;
        $newMinX = $minX - ($diff / 2);
        $newViewBox = "{$newMinX} {$minY} {$h} {$h}";
    }

    $svg->setAttribute('viewBox', $newViewBox);
    $svg->removeAttribute('width');
    $svg->removeAttribute('height');

    return $dom->saveXML($svg);
}
