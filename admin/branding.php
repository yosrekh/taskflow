<?php
// admin/branding.php - Branding Settings and Visual Identity Management
require_once __DIR__ . '/../includes/auth.php';
require_admin('../');
require_once __DIR__ . '/../includes/branding.php';

$base = '../';
$error = '';
$success = '';

// Handle Actions (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $_SESSION['branding_flash_error'] = "رمز التحقق غير صالح. يرجى إعادة المحاولة.";
        header("Location: branding.php");
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'save_branding') {
        $appName = trim($_POST['app_name'] ?? '');
        if ($appName === '') {
            $appName = 'TaskFlow';
        }
        set_setting('app_name', $appName);

        $uploadErrors = [];

        // Helper to process uploaded file
        function handle_upload(string $inputName, string $itemKey, array &$uploadErrors): void {
            if (!isset($_FILES[$inputName]) || $_FILES[$inputName]['error'] === UPLOAD_ERR_NO_FILE) {
                return;
            }

            $file = $_FILES[$inputName];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $uploadErrors[] = "خطأ في رفع ملف {$inputName}.";
                return;
            }

            $isFavicon = str_starts_with($itemKey, 'favicon');
            $maxBytes = $isFavicon ? 256 * 1024 : 1024 * 1024;
            $maxLabel = $isFavicon ? '256 كيلوبايت' : '1 ميجابايت';

            if ($file['size'] > $maxBytes) {
                $uploadErrors[] = "حجم ملف {$inputName} يتجاوز الحد الأقصى ({$maxLabel}).";
                return;
            }

            // Real MIME check
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);

            $ext = null;
            if ($mime === 'image/png') {
                $ext = 'png';
            } elseif ($mime === 'image/webp') {
                if ($isFavicon) {
                    $uploadErrors[] = "الأيقونة تدعم فقط صيغ SVG و PNG.";
                    return;
                }
                $ext = 'webp';
            } elseif ($mime === 'image/svg+xml' || $mime === 'text/xml' || $mime === 'text/plain') {
                $raw = file_get_contents($file['tmp_name']);
                if (stripos($raw, '<svg') !== false) {
                    $ext = 'svg';
                }
            }

            if (!$ext) {
                $uploadErrors[] = "صيغة الملف المرفوع لـ {$inputName} غير مدعومة ({$mime}).";
                return;
            }

            $sanitizedContent = null;
            if ($ext === 'svg') {
                $rawSvg = file_get_contents($file['tmp_name']);
                if (preg_match('/<!(?:DOCTYPE|ENTITY)\b/i', $rawSvg)) {
                    $uploadErrors[] = "ملف SVG يحتوي على تعريفات DOCTYPE أو ENTITY غير مسموح بها لأسباب أمنية.";
                    return;
                }

                $sanitizedContent = sanitize_svg($rawSvg);
                if ($sanitizedContent === null) {
                    $uploadErrors[] = "تعذر فحص أو تطهير ملف SVG المرفوع لـ {$inputName}.";
                    return;
                }

                if ($isFavicon) {
                    $sanitizedContent = ensure_square_svg_viewbox($sanitizedContent);
                }
            } elseif ($ext === 'png') {
                $imgInfo = @getimagesize($file['tmp_name']);
                if (!$imgInfo) {
                    $uploadErrors[] = "تعذر قراءة أبعاد ملف PNG المرفوع لـ {$inputName}.";
                    return;
                }
                if ($isFavicon && $imgInfo[0] !== $imgInfo[1]) {
                    $uploadErrors[] = "أيقونة Favicon بصيغة PNG يجب أن تكون مربعة الأبعاد (العرض = الارتفاع). الأبعاد الحالية: {$imgInfo[0]}×{$imgInfo[1]}.";
                    return;
                }
            }

            // Save file
            $uploadDir = dirname(__DIR__) . '/uploads/branding';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $randomName = bin2hex(random_bytes(16)) . '.' . $ext;
            $destPath = $uploadDir . '/' . $randomName;

            if ($ext === 'svg' && $sanitizedContent !== null) {
                file_put_contents($destPath, $sanitizedContent);
            } else {
                move_uploaded_file($file['tmp_name'], $destPath);
            }

            // Remove old custom file if replaced
            $oldSetting = get_setting($itemKey);
            if ($oldSetting && is_string($oldSetting)) {
                $oldPath = dirname(__DIR__) . '/' . $oldSetting;
                if (file_exists($oldPath) && str_contains($oldSetting, 'uploads/branding/')) {
                    @unlink($oldPath);
                }
            }

            set_setting($itemKey, 'uploads/branding/' . $randomName);
        }

        handle_upload('logo_dark', 'logo_dark', $uploadErrors);
        handle_upload('logo_light', 'logo_light', $uploadErrors);
        handle_upload('favicon', 'favicon', $uploadErrors);
        handle_upload('favicon_dark', 'favicon_dark', $uploadErrors);

        if (!empty($uploadErrors)) {
            $_SESSION['branding_flash_error'] = implode('<br>', $uploadErrors);
        } else {
            $_SESSION['branding_flash_success'] = "تم حفظ إعدادات الهوية البصرية بنجاح.";
        }

    } elseif ($action === 'reset_item') {
        $item = $_POST['item'] ?? '';
        $validItems = ['app_name', 'logo_dark', 'logo_light', 'favicon', 'favicon_dark'];

        if (in_array($item, $validItems, true)) {
            $oldSetting = get_setting($item);
            if ($oldSetting && is_string($oldSetting)) {
                $oldPath = dirname(__DIR__) . '/' . $oldSetting;
                if (file_exists($oldPath) && str_contains($oldSetting, 'uploads/branding/')) {
                    @unlink($oldPath);
                }
            }
            delete_setting($item);
            $_SESSION['branding_flash_success'] = "تمت استعادة الإعداد الافتراضي بنجاح.";
        } else {
            $_SESSION['branding_flash_error'] = "العنصر المطلوب غير صالح.";
        }
    }

    header("Location: branding.php");
    exit;
}

$error = $_SESSION['branding_flash_error'] ?? '';
unset($_SESSION['branding_flash_error']);

$success = $_SESSION['branding_flash_success'] ?? '';
unset($_SESSION['branding_flash_success']);

$branding = get_branding($base);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <?php
    $page_title = 'الهوية البصرية';
    include __DIR__ . '/../includes/header-meta.php';

    // Helpers for rendering favicon preview element
    function render_tab_icon_markup(string $url): string {
        return '<img src="' . htmlspecialchars($url) . '" alt="" class="mock-img-icon">';
    }

    $darkFaviconUrl = !empty($branding['favicon_dark_url']) ? $branding['favicon_dark_url'] : $branding['favicon_url'];
    ?>
</head>
<body>
    <?php
    include __DIR__ . '/../includes/nav.php';
    render_nav($base);
    ?>

    <main class="page-container">
        <!-- Header -->
        <header class="page-header">
            <div class="page-title-wrap">
                <h1 class="page-title">الهوية البصرية</h1>
                <p class="page-subtitle">تخصيص اسم التطبيق وشعاراته وأيقونة الموقع لتلائم هوية شركتك</p>
            </div>
        </header>

        <!-- Alerts -->
        <?php if ($error): ?>
            <div class="alert alert-error" role="alert">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="flex-shrink-0"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                <div><?= $error ?></div>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success" role="alert">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="flex-shrink-0"><polyline points="20 6 9 17 4 12"></polyline></svg>
                <span><?= htmlspecialchars($success) ?></span>
            </div>
        <?php endif; ?>

        <!-- Form for Saving Branding -->
        <form method="POST" action="branding.php" enctype="multipart/form-data" class="branding-form" id="brandingForm">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="save_branding">

            <!-- SECTION 1: APP NAME -->
            <section class="branding-section">
                <div class="branding-section-header">
                    <h2 class="branding-section-title">اسم التطبيق</h2>
                    <p class="branding-section-desc">يظهر في شريط التنقل وعناوين الصفحات وعند تثبيت التطبيق على الأجهزة.</p>
                </div>
                <div class="card branding-card">
                    <div class="card-header branding-card-header">
                        <div class="branding-card-title-wrap">
                            <h3 class="card-title">اسم التطبيق</h3>
                        </div>
                        <span class="badge <?= $branding['app_name'] !== 'TaskFlow' ? 'badge-custom' : 'badge-default' ?>">
                            <?= $branding['app_name'] !== 'TaskFlow' ? 'مخصص' : 'افتراضي' ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="app_name_input" class="form-label">اسم التطبيق <span class="required">*</span></label>
                            <input type="text" id="app_name_input" name="app_name" class="form-control" value="<?= htmlspecialchars($branding['app_name']) ?>" required placeholder="TaskFlow">
                        </div>
                    </div>
                    <?php if ($branding['app_name'] !== 'TaskFlow'): ?>
                    <div class="card-footer branding-card-footer">
                        <button type="submit" form="reset-app_name-form" class="btn btn-secondary btn-sm">استرجاع الافتراضي</button>
                    </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- SECTION 2: LOGOS -->
            <section class="branding-section">
                <div class="branding-section-header">
                    <h2 class="branding-section-title">الشعارات</h2>
                    <p class="branding-section-desc">شعاران منفصلان لضمان التباين والوضوح التام عبر الخلفيات الداكنة والفاتحة في النظام.</p>
                </div>

                <div class="branding-grid">
                    <!-- Dark Background Logo Card -->
                    <div class="card branding-card">
                        <div class="card-header branding-card-header">
                            <div class="branding-card-title-wrap">
                                <h3 class="card-title">الشعار للخلفيات الداكنة</h3>
                                <span class="card-desc">يُستخدم في شريط التنقل العلوي (الداكن دائماً) وفي صفحة تسجيل الدخول بالوضع الداكن.</span>
                            </div>
                            <span class="badge <?= $branding['is_custom_logo_dark'] ? 'badge-custom' : 'badge-default' ?>">
                                <?= $branding['is_custom_logo_dark'] ? 'مخصص' : 'افتراضي' ?>
                            </span>
                        </div>
                        <div class="card-body">
                            <!-- Single Preview: ONLY on Navy -->
                            <div class="preview-box preview-navy">
                                <span class="preview-box-label">معاينة على خلفية داكنة (شريط التنقل)</span>
                                <img src="<?= htmlspecialchars($branding['logo_dark_url']) ?>" alt="معاينة الشعار الداكن" class="preview-img" id="preview_logo_dark">
                            </div>

                            <!-- Styled Arabic Drop Zone -->
                            <div class="drop-zone-container">
                                <label class="form-label">رفع شعار جديد</label>
                                <div class="drop-zone" id="dropzone_logo_dark" tabindex="0" role="button" aria-label="رفع شعار للخلفيات الداكنة">
                                    <input type="file" id="file_logo_dark" name="logo_dark" accept=".svg,.png,.webp" class="drop-zone-input visually-hidden" data-max-bytes="1048576" data-field="logo_dark">

                                    <div class="drop-zone-prompt" id="prompt_logo_dark">
                                        <div class="drop-zone-icon-circle">
                                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                                        </div>
                                        <span class="drop-zone-text">اسحب الملف هنا أو اضغط للاختيار</span>
                                        <span class="drop-zone-hint">SVG أو PNG أو WebP — الحد الأقصى: 1 ميجابايت</span>
                                    </div>

                                    <div class="drop-zone-staged" id="staged_logo_dark" hidden>
                                        <div class="staged-preview-wrap">
                                            <img src="" alt="" class="staged-thumb" id="thumb_logo_dark">
                                        </div>
                                        <div class="staged-meta">
                                            <span class="staged-name" id="name_logo_dark"></span>
                                            <span class="staged-size" id="size_logo_dark"></span>
                                        </div>
                                        <button type="button" class="staged-remove-btn" id="remove_logo_dark" title="إزالة الملف المحدد">
                                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                                            <span>إزالة</span>
                                        </button>
                                    </div>
                                </div>
                                <div class="drop-zone-error" id="error_logo_dark" hidden></div>
                            </div>
                        </div>
                        <?php if ($branding['is_custom_logo_dark']): ?>
                        <div class="card-footer branding-card-footer">
                            <button type="submit" form="reset-logo_dark-form" class="btn btn-secondary btn-sm">استرجاع الافتراضي</button>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Light Background Logo Card -->
                    <div class="card branding-card">
                        <div class="card-header branding-card-header">
                            <div class="branding-card-title-wrap">
                                <h3 class="card-title">الشعار للخلفيات الفاتحة</h3>
                                <span class="card-desc">يُستخدم في بطاقة تسجيل الدخول وتغيير كلمة المرور بالوضع الفاتح.</span>
                            </div>
                            <span class="badge <?= $branding['is_custom_logo_light'] ? 'badge-custom' : 'badge-default' ?>">
                                <?= $branding['is_custom_logo_light'] ? 'مخصص' : 'افتراضي' ?>
                            </span>
                        </div>
                        <div class="card-body">
                            <!-- Single Preview: ONLY on White -->
                            <div class="preview-box preview-white">
                                <span class="preview-box-label">معاينة على خلفية فاتحة (صفحة الدخول)</span>
                                <img src="<?= htmlspecialchars($branding['logo_light_url']) ?>" alt="معاينة الشعار الفاتح" class="preview-img" id="preview_logo_light">
                            </div>

                            <!-- Styled Arabic Drop Zone -->
                            <div class="drop-zone-container">
                                <label class="form-label">رفع شعار جديد</label>
                                <div class="drop-zone" id="dropzone_logo_light" tabindex="0" role="button" aria-label="رفع شعار للخلفيات الفاتحة">
                                    <input type="file" id="file_logo_light" name="logo_light" accept=".svg,.png,.webp" class="drop-zone-input visually-hidden" data-max-bytes="1048576" data-field="logo_light">

                                    <div class="drop-zone-prompt" id="prompt_logo_light">
                                        <div class="drop-zone-icon-circle">
                                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                                        </div>
                                        <span class="drop-zone-text">اسحب الملف هنا أو اضغط للاختيار</span>
                                        <span class="drop-zone-hint">SVG أو PNG أو WebP — الحد الأقصى: 1 ميجابايت</span>
                                    </div>

                                    <div class="drop-zone-staged" id="staged_logo_light" hidden>
                                        <div class="staged-preview-wrap">
                                            <img src="" alt="" class="staged-thumb" id="thumb_logo_light">
                                        </div>
                                        <div class="staged-meta">
                                            <span class="staged-name" id="name_logo_light"></span>
                                            <span class="staged-size" id="size_logo_light"></span>
                                        </div>
                                        <button type="button" class="staged-remove-btn" id="remove_logo_light" title="إزالة الملف المحدد">
                                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                                            <span>إزالة</span>
                                        </button>
                                    </div>
                                </div>
                                <div class="drop-zone-error" id="error_logo_light" hidden></div>
                            </div>
                        </div>
                        <?php if ($branding['is_custom_logo_light']): ?>
                        <div class="card-footer branding-card-footer">
                            <button type="submit" form="reset-logo_light-form" class="btn btn-secondary btn-sm">استرجاع الافتراضي</button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <!-- SECTION 3: BROWSER FAVICON -->
            <section class="branding-section">
                <div class="branding-section-header">
                    <h2 class="branding-section-title">أيقونة المتصفح</h2>
                    <p class="branding-section-desc">تظهر في تبويب المتصفح وشريط الإشارات المرجعية وعند تثبيت التطبيق على الهواتف والأجهزة.</p>
                </div>

                <div class="card branding-card" style="margin-block-end: var(--space-5);">
                    <div class="card-header branding-card-header">
                        <div class="branding-card-title-wrap">
                            <h3 class="card-title">معاينة واقعية داخل تبويبات المتصفح</h3>
                            <span class="card-desc">محاكاة لشكل الأيقونة بمقاس 16 بكسل و32 بكسل في الوضعين الفاتح والداكن للمتصفح</span>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Dual Mock Browser Tabs -->
                        <div class="mock-browser-tabs">
                            <!-- Light Browser Tab -->
                            <div class="mock-tab mock-tab-light">
                                <div class="mock-tab-chrome">
                                    <div class="mock-tab-pill">
                                        <span class="mock-tab-icon size-16" id="mockLightTabIcon">
                                            <?= render_tab_icon_markup($branding['favicon_url']) ?>
                                        </span>
                                        <span class="mock-tab-title" id="mockLightTabTitle"><?= htmlspecialchars($branding['app_name']) ?></span>
                                        <span class="mock-tab-close" aria-hidden="true">&times;</span>
                                    </div>
                                </div>
                                <div class="mock-tab-body">
                                    <span class="mock-tab-body-label">تبويب فاتح</span>
                                    <div class="mock-tab-sizes">
                                        <div class="mock-size-badge">
                                            <span>16 بكسل</span>
                                            <div class="mock-icon-box size-16" id="mockLightBox16">
                                                <?= render_tab_icon_markup($branding['favicon_url']) ?>
                                            </div>
                                        </div>
                                        <div class="mock-size-badge">
                                            <span>32 بكسل</span>
                                            <div class="mock-icon-box size-32" id="mockLightBox32">
                                                <?= render_tab_icon_markup($branding['favicon_url']) ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Dark Browser Tab -->
                            <div class="mock-tab mock-tab-dark">
                                <div class="mock-tab-chrome">
                                    <div class="mock-tab-pill">
                                        <span class="mock-tab-icon size-16" id="mockDarkTabIcon">
                                            <?= render_tab_icon_markup($darkFaviconUrl) ?>
                                        </span>
                                        <span class="mock-tab-title" id="mockDarkTabTitle"><?= htmlspecialchars($branding['app_name']) ?></span>
                                        <span class="mock-tab-close" aria-hidden="true">&times;</span>
                                    </div>
                                </div>
                                <div class="mock-tab-body">
                                    <span class="mock-tab-body-label">تبويب داكن</span>
                                    <div class="mock-tab-sizes">
                                        <div class="mock-size-badge">
                                            <span>16 بكسل</span>
                                            <div class="mock-icon-box size-16" id="mockDarkBox16">
                                                <?= render_tab_icon_markup($darkFaviconUrl) ?>
                                            </div>
                                        </div>
                                        <div class="mock-size-badge">
                                            <span>32 بكسل</span>
                                            <div class="mock-icon-box size-32" id="mockDarkBox32">
                                                <?= render_tab_icon_markup($darkFaviconUrl) ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="branding-grid">
                    <!-- Main Favicon Card -->
                    <div class="card branding-card">
                        <div class="card-header branding-card-header">
                            <div class="branding-card-title-wrap">
                                <h3 class="card-title">الأيقونة الأساسية</h3>
                                <span class="card-desc">تُستخدم في كافة تبويبات المتصفح والإشارات المرجعية.</span>
                            </div>
                            <span class="badge <?= $branding['is_custom_favicon'] ? 'badge-custom' : 'badge-default' ?>">
                                <?= $branding['is_custom_favicon'] ? 'مخصص' : 'افتراضي' ?>
                            </span>
                        </div>
                        <div class="card-body">
                            <div class="drop-zone-container">
                                <label class="form-label">رفع أيقونة مربعة</label>
                                <div class="drop-zone" id="dropzone_favicon" tabindex="0" role="button" aria-label="رفع الأيقونة الأساسية">
                                    <input type="file" id="file_favicon" name="favicon" accept=".svg,.png" class="drop-zone-input visually-hidden" data-max-bytes="262144" data-field="favicon" data-is-favicon="true">

                                    <div class="drop-zone-prompt" id="prompt_favicon">
                                        <div class="drop-zone-icon-circle">
                                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                                        </div>
                                        <span class="drop-zone-text">اسحب الملف هنا أو اضغط للاختيار</span>
                                        <span class="drop-zone-hint">SVG أو PNG مربعة — الحد الأقصى: 256 كيلوبايت</span>
                                    </div>

                                    <div class="drop-zone-staged" id="staged_favicon" hidden>
                                        <div class="staged-preview-wrap">
                                            <img src="" alt="" class="staged-thumb" id="thumb_favicon">
                                        </div>
                                        <div class="staged-meta">
                                            <span class="staged-name" id="name_favicon"></span>
                                            <span class="staged-size" id="size_favicon"></span>
                                        </div>
                                        <button type="button" class="staged-remove-btn" id="remove_favicon" title="إزالة الملف المحدد">
                                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                                            <span>إزالة</span>
                                        </button>
                                    </div>
                                </div>
                                <div class="drop-zone-error" id="error_favicon" hidden></div>
                                <div class="drop-zone-warning" id="warning_favicon" hidden></div>
                                <span class="form-hint" style="margin-top: var(--space-2); display: block;">إذا تم رفع ملف SVG غير مربع، سيتم ضبط إحداثياته تلقائياً ليتوسط مساحة مربعة.</span>
                            </div>
                        </div>
                        <?php if ($branding['is_custom_favicon']): ?>
                        <div class="card-footer branding-card-footer">
                            <button type="submit" form="reset-favicon-form" class="btn btn-secondary btn-sm">استرجاع الافتراضي</button>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Dark Favicon Variant Card -->
                    <div class="card branding-card">
                        <div class="card-header branding-card-header">
                            <div class="branding-card-title-wrap">
                                <h3 class="card-title">أيقونة التبويبات الداكنة (اختياري)</h3>
                                <span class="card-desc">نسخة مخصصة تظهر فقط عندما يكون المتصفح في الوضع الداكن.</span>
                            </div>
                            <span class="badge <?= $branding['is_custom_favicon_dark'] ? 'badge-custom' : 'badge-default' ?>">
                                <?= $branding['is_custom_favicon_dark'] ? 'مخصص' : 'غير محدد (تلقائي)' ?>
                            </span>
                        </div>
                        <div class="card-body">
                            <div class="drop-zone-container">
                                <label class="form-label">رفع أيقونة للوضع الداكن</label>
                                <div class="drop-zone" id="dropzone_favicon_dark" tabindex="0" role="button" aria-label="رفع أيقونة التبويبات الداكنة">
                                    <input type="file" id="file_favicon_dark" name="favicon_dark" accept=".svg,.png" class="drop-zone-input visually-hidden" data-max-bytes="262144" data-field="favicon_dark" data-is-favicon="true">

                                    <div class="drop-zone-prompt" id="prompt_favicon_dark">
                                        <div class="drop-zone-icon-circle">
                                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                                        </div>
                                        <span class="drop-zone-text">اسحب الملف هنا أو اضغط للاختيار</span>
                                        <span class="drop-zone-hint">SVG أو PNG مربعة — الحد الأقصى: 256 كيلوبايت</span>
                                    </div>

                                    <div class="drop-zone-staged" id="staged_favicon_dark" hidden>
                                        <div class="staged-preview-wrap">
                                            <img src="" alt="" class="staged-thumb" id="thumb_favicon_dark">
                                        </div>
                                        <div class="staged-meta">
                                            <span class="staged-name" id="name_favicon_dark"></span>
                                            <span class="staged-size" id="size_favicon_dark"></span>
                                        </div>
                                        <button type="button" class="staged-remove-btn" id="remove_favicon_dark" title="إزالة الملف المحدد">
                                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                                            <span>إزالة</span>
                                        </button>
                                    </div>
                                </div>
                                <div class="drop-zone-error" id="error_favicon_dark" hidden></div>
                                <div class="drop-zone-warning" id="warning_favicon_dark" hidden></div>
                                <span class="form-hint" style="margin-top: var(--space-2); display: block;">إذا لم يتم رفع هذه النسخة، ستُستخدم الأيقونة الأساسية في التبويبات الداكنة أيضاً.</span>
                            </div>
                        </div>
                        <?php if ($branding['is_custom_favicon_dark']): ?>
                        <div class="card-footer branding-card-footer">
                            <button type="submit" form="reset-favicon_dark-form" class="btn btn-secondary btn-sm">استرجاع الافتراضي</button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        </form>

        <!-- Hidden Reset Forms -->
        <form id="reset-app_name-form" method="POST" action="branding.php">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="reset_item">
            <input type="hidden" name="item" value="app_name">
        </form>
        <form id="reset-logo_dark-form" method="POST" action="branding.php">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="reset_item">
            <input type="hidden" name="item" value="logo_dark">
        </form>
        <form id="reset-logo_light-form" method="POST" action="branding.php">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="reset_item">
            <input type="hidden" name="item" value="logo_light">
        </form>
        <form id="reset-favicon-form" method="POST" action="branding.php">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="reset_item">
            <input type="hidden" name="item" value="favicon">
        </form>
        <form id="reset-favicon_dark-form" method="POST" action="branding.php">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="reset_item">
            <input type="hidden" name="item" value="favicon_dark">
        </form>
    </main>

    <!-- Sticky Unsaved Changes Bar -->
    <div class="unsaved-changes-bar" id="unsavedChangesBar" role="region" aria-label="شريط التغييرات غير المحفوظة" hidden>
        <div class="unsaved-changes-content">
            <div class="unsaved-changes-message">
                <span class="unsaved-dot" aria-hidden="true"></span>
                <span>عندك تغييرات مش محفوظة</span>
            </div>
            <div class="unsaved-changes-actions">
                <button type="button" class="btn btn-secondary btn-sm" id="discardChangesBtn">إلغاء</button>
                <button type="submit" form="brandingForm" class="btn btn-primary btn-sm" id="saveChangesBtn">حفظ</button>
            </div>
        </div>
    </div>

    <!-- Client-side Interaction, Validation & Preview Script -->
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const appNameInput = document.getElementById('app_name_input');
        const initialAppName = appNameInput ? appNameInput.value : '';
        const brandingForm = document.getElementById('brandingForm');
        const unsavedBar = document.getElementById('unsavedChangesBar');
        const discardBtn = document.getElementById('discardChangesBtn');

        // Original image sources for reversion
        const initialPreviews = {
            logo_dark: document.getElementById('preview_logo_dark') ? document.getElementById('preview_logo_dark').src : '',
            logo_light: document.getElementById('preview_logo_light') ? document.getElementById('preview_logo_light').src : '',
            favicon: <?= json_encode($branding['favicon_url']) ?>,
            favicon_dark: <?= json_encode($branding['favicon_dark_url']) ?>
        };

        const savedFavicon = <?= json_encode($branding['favicon_url']) ?>;
        const savedFaviconDark = <?= json_encode($branding['favicon_dark_url']) ?>;
        const defaultFavicon = <?= json_encode($base . 'assets/favicon.svg') ?>;

        const stagedFavicons = {
            favicon: null,
            favicon_dark: null
        };

        let hasUnsavedChanges = false;

        function formatSize(bytes) {
            if (bytes < 1024) return bytes + ' بايت';
            if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' كيلوبايت';
            return (bytes / (1024 * 1024)).toFixed(2) + ' ميجابايت';
        }

        function checkUnsavedChanges() {
            let changed = false;
            if (appNameInput && appNameInput.value !== initialAppName) {
                changed = true;
            }
            ['logo_dark', 'logo_light', 'favicon', 'favicon_dark'].forEach(field => {
                const input = document.getElementById('file_' + field);
                if (input && input.files && input.files.length > 0) {
                    changed = true;
                }
            });

            hasUnsavedChanges = changed;
            if (unsavedBar) {
                if (changed) {
                    unsavedBar.hidden = false;
                    unsavedBar.classList.add('show');
                } else {
                    unsavedBar.hidden = true;
                    unsavedBar.classList.remove('show');
                }
            }
        }

        // Live title update in mock tabs
        if (appNameInput) {
            appNameInput.addEventListener('input', function () {
                const title = this.value.trim() || 'TaskFlow';
                const lightTitle = document.getElementById('mockLightTabTitle');
                const darkTitle = document.getElementById('mockDarkTabTitle');
                if (lightTitle) lightTitle.textContent = title;
                if (darkTitle) darkTitle.textContent = title;
                checkUnsavedChanges();
            });
        }

        // Update mock tab icons
        function renderMockIcon(containerId, url) {
            const el = document.getElementById(containerId);
            if (!el) return;
            el.innerHTML = '';
            const img = document.createElement('img');
            img.src = url;
            img.alt = 'معاينة أيقونة المتصفح';
            img.className = 'mock-img-icon';
            el.appendChild(img);
        }

        function updateLightMockTabs(url) {
            renderMockIcon('mockLightTabIcon', url);
            renderMockIcon('mockLightBox16', url);
            renderMockIcon('mockLightBox32', url);
        }

        function updateDarkMockTabs(url) {
            renderMockIcon('mockDarkTabIcon', url);
            renderMockIcon('mockDarkBox16', url);
            renderMockIcon('mockDarkBox32', url);
        }

        function getActiveLightFaviconUrl() {
            if (stagedFavicons.favicon) {
                return stagedFavicons.favicon;
            }
            return savedFavicon || defaultFavicon;
        }

        function getActiveDarkFaviconUrl() {
            if (stagedFavicons.favicon_dark) {
                return stagedFavicons.favicon_dark;
            }
            if (savedFaviconDark) {
                return savedFaviconDark;
            }
            return getActiveLightFaviconUrl();
        }

        function refreshFaviconMockTabs() {
            updateLightMockTabs(getActiveLightFaviconUrl());
            updateDarkMockTabs(getActiveDarkFaviconUrl());
        }

        // Client-side image analysis for non-blocking warnings
        function analyzeFaviconImage(file, fieldKey) {
            const warningEl = document.getElementById('warning_' + fieldKey);
            if (!warningEl) return;
            warningEl.textContent = '';
            warningEl.hidden = true;

            try {
                const url = URL.createObjectURL(file);
                const img = new Image();
                img.onload = function () {
                    try {
                        const w = img.naturalWidth || img.width;
                        const h = img.naturalHeight || img.height;
                        const warnings = [];

                        // 1. Aspect ratio warning for wide images (> 1.5:1)
                        if (h > 0 && (w / h) > 1.5) {
                            warnings.push("الصورة دي شكلها لوجو عرضي، وفي التبويب هتظهر صغيرة جداً ومش مقروءة. استخدم الرمز لوحده من غير الاسم.");
                        }

                        // 2. Relative luminance check
                        const canvas = document.createElement('canvas');
                        const size = 32;
                        canvas.width = size;
                        canvas.height = size;
                        const ctx = canvas.getContext('2d');
                        if (ctx) {
                            ctx.drawImage(img, 0, 0, size, size);
                            const imgData = ctx.getImageData(0, 0, size, size).data;
                            let totalLum = 0;
                            let count = 0;
                            for (let i = 0; i < imgData.length; i += 4) {
                                const alpha = imgData[i + 3];
                                if (alpha > 20) {
                                    const r = imgData[i] / 255;
                                    const g = imgData[i + 1] / 255;
                                    const b = imgData[i + 2] / 255;
                                    const rLin = r <= 0.04045 ? r / 12.92 : Math.pow((r + 0.055) / 1.055, 2.4);
                                    const gLin = g <= 0.04045 ? g / 12.92 : Math.pow((g + 0.055) / 1.055, 2.4);
                                    const bLin = b <= 0.04045 ? b / 12.92 : Math.pow((b + 0.055) / 1.055, 2.4);
                                    const lum = 0.2126 * rLin + 0.7152 * gLin + 0.0722 * bLin;
                                    totalLum += lum;
                                    count++;
                                }
                            }

                            if (count > 0) {
                                const avgLum = totalLum / count;
                                if (fieldKey === 'favicon' && avgLum > 0.85) {
                                    warnings.push("الأيقونة دي فاتحة جداً ومش هتبان في التبويبات الفاتحة. ارفع نسخة غامقة هنا، والنسخة الفاتحة في أيقونة التبويبات الداكنة.");
                                } else if (fieldKey === 'favicon_dark' && avgLum < 0.15) {
                                    warnings.push("الأيقونة دي غامقة جداً ومش هتبان في التبويبات الداكنة. ارفع نسخة فاتحة هنا.");
                                }
                            }
                        }

                        if (warnings.length > 0) {
                            warningEl.textContent = warnings.join(' ');
                            warningEl.hidden = false;
                        }
                    } catch (e) {
                        // Skip warning on failure
                    } finally {
                        URL.revokeObjectURL(url);
                    }
                };
                img.onerror = function () {
                    URL.revokeObjectURL(url);
                };
                img.src = url;
            } catch (e) {
                // Skip analysis on error
            }
        }

        // Drop Zone Handler setup
        function setupDropZone(fieldKey) {
            const dropzone = document.getElementById('dropzone_' + fieldKey);
            const input = document.getElementById('file_' + fieldKey);
            const promptBox = document.getElementById('prompt_' + fieldKey);
            const stagedBox = document.getElementById('staged_' + fieldKey);
            const thumbImg = document.getElementById('thumb_' + fieldKey);
            const nameEl = document.getElementById('name_' + fieldKey);
            const sizeEl = document.getElementById('size_' + fieldKey);
            const removeBtn = document.getElementById('remove_' + fieldKey);
            const errorEl = document.getElementById('error_' + fieldKey);
            const warningEl = document.getElementById('warning_' + fieldKey);

            if (!dropzone || !input) return;

            const isFavicon = input.dataset.isFavicon === 'true';
            const maxBytes = parseInt(input.dataset.maxBytes, 10) || (isFavicon ? 262144 : 1048576);

            function showError(msg) {
                if (errorEl) {
                    errorEl.textContent = msg;
                    errorEl.hidden = false;
                }
            }

            function clearError() {
                if (errorEl) {
                    errorEl.textContent = '';
                    errorEl.hidden = true;
                }
            }

            function clearWarning() {
                if (warningEl) {
                    warningEl.textContent = '';
                    warningEl.hidden = true;
                }
            }

            function resetStaged() {
                input.value = '';
                clearError();
                clearWarning();
                if (stagedBox) {
                    stagedBox.hidden = true;
                }
                if (promptBox) {
                    promptBox.hidden = false;
                }
                if (thumbImg) thumbImg.src = '';
                if (nameEl) nameEl.textContent = '';
                if (sizeEl) sizeEl.textContent = '';

                // Restore previews
                if (fieldKey === 'logo_dark') {
                    const img = document.getElementById('preview_logo_dark');
                    if (img) img.src = initialPreviews.logo_dark;
                } else if (fieldKey === 'logo_light') {
                    const img = document.getElementById('preview_logo_light');
                    if (img) img.src = initialPreviews.logo_light;
                } else if (fieldKey === 'favicon' || fieldKey === 'favicon_dark') {
                    stagedFavicons[fieldKey] = null;
                    refreshFaviconMockTabs();
                }

                checkUnsavedChanges();
            }

            function stageFile(file) {
                clearError();
                clearWarning();
                const fileName = file.name || '';
                const ext = fileName.split('.').pop().toLowerCase();
                const validExts = isFavicon ? ['svg', 'png'] : ['svg', 'png', 'webp'];

                // 1. Extension / Type Validation
                if (!validExts.includes(ext)) {
                    showError(isFavicon ? 'أيقونة المتصفح تدعم صيغ SVG و PNG فقط.' : 'صيغة الملف غير مدعومة. الصيغ المسموح بها هي SVG و PNG و WebP.');
                    input.value = '';
                    return;
                }

                // 2. Size Validation
                if (file.size > maxBytes) {
                    const limitLabel = isFavicon ? '256 كيلوبايت' : '1 ميجابايت';
                    showError('حجم الملف يتجاوز الحد الأقصى المسموح به (' + limitLabel + ').');
                    input.value = '';
                    return;
                }

                // 3. For PNG Favicon: Verify square aspect ratio
                if (isFavicon && ext === 'png') {
                    const testImg = new Image();
                    testImg.onload = function () {
                        if (testImg.naturalWidth !== testImg.naturalHeight) {
                            showError('أيقونة Favicon بصيغة PNG يجب أن تكون مربعة الأبعاد (العرض = الارتفاع). الأبعاد: ' + testImg.naturalWidth + '×' + testImg.naturalHeight + '.');
                            input.value = '';
                            return;
                        }
                        applyStaging(file, ext);
                    };
                    testImg.onerror = function () {
                        showError('تعذر التحقق من أبعاد صورة PNG.');
                        input.value = '';
                    };
                    testImg.src = URL.createObjectURL(file);
                    return;
                }

                applyStaging(file, ext);
            }

            function applyStaging(file, ext) {
                const objectUrl = URL.createObjectURL(file);

                if (nameEl) nameEl.textContent = file.name;
                if (sizeEl) sizeEl.textContent = formatSize(file.size);
                if (thumbImg) thumbImg.src = objectUrl;

                if (promptBox) {
                    promptBox.hidden = true;
                }
                if (stagedBox) {
                    stagedBox.hidden = false;
                }

                // Live Preview Updates
                if (fieldKey === 'logo_dark') {
                    const preview = document.getElementById('preview_logo_dark');
                    if (preview) preview.src = objectUrl;
                } else if (fieldKey === 'logo_light') {
                    const preview = document.getElementById('preview_logo_light');
                    if (preview) preview.src = objectUrl;
                } else if (fieldKey === 'favicon' || fieldKey === 'favicon_dark') {
                    stagedFavicons[fieldKey] = objectUrl;
                    refreshFaviconMockTabs();
                    analyzeFaviconImage(file, fieldKey);
                }

                checkUnsavedChanges();
            }

            // Click to pick file
            dropzone.addEventListener('click', function (e) {
                if (e.target.closest('.staged-remove-btn')) return;
                input.click();
            });

            // Keyboard accessibility (Enter or Space)
            dropzone.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    if (!e.target.closest('.staged-remove-btn')) {
                        input.click();
                    }
                }
            });

            // Native input change
            input.addEventListener('change', function () {
                if (this.files && this.files[0]) {
                    stageFile(this.files[0]);
                }
            });

            // Remove button
            if (removeBtn) {
                removeBtn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    resetStaged();
                });
            }

            // Drag and drop events
            ['dragenter', 'dragover'].forEach(eventName => {
                dropzone.addEventListener(eventName, function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    dropzone.classList.add('dragover');
                });
            });

            ['dragleave', 'drop'].forEach(eventName => {
                dropzone.addEventListener(eventName, function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    dropzone.classList.remove('dragover');
                });
            });

            dropzone.addEventListener('drop', function (e) {
                const dt = e.dataTransfer;
                if (dt && dt.files && dt.files.length > 0) {
                    const file = dt.files[0];
                    try {
                        const dataTransfer = new DataTransfer();
                        dataTransfer.items.add(file);
                        input.files = dataTransfer.files;
                    } catch (err) {
                        // Fallback if DataTransfer not constructible
                    }
                    stageFile(file);
                }
            });
        }

        // Initialize drop zones
        ['logo_dark', 'logo_light', 'favicon', 'favicon_dark'].forEach(setupDropZone);

        // Discard unsaved changes button
        if (discardBtn) {
            discardBtn.addEventListener('click', function () {
                if (appNameInput) {
                    appNameInput.value = initialAppName;
                    const lightTitle = document.getElementById('mockLightTabTitle');
                    const darkTitle = document.getElementById('mockDarkTabTitle');
                    if (lightTitle) lightTitle.textContent = initialAppName;
                    if (darkTitle) darkTitle.textContent = initialAppName;
                }

                ['logo_dark', 'logo_light', 'favicon', 'favicon_dark'].forEach(fieldKey => {
                    const removeBtn = document.getElementById('remove_' + fieldKey);
                    if (removeBtn) removeBtn.click();
                });

                hasUnsavedChanges = false;
                if (unsavedBar) unsavedBar.classList.remove('show');
            });
        }

        // Form Submission disables beforeunload warning
        if (brandingForm) {
            brandingForm.addEventListener('submit', function () {
                hasUnsavedChanges = false;
            });
        }

        // Beforeunload navigation guard
        window.addEventListener('beforeunload', function (e) {
            if (hasUnsavedChanges) {
                e.preventDefault();
                e.returnValue = 'عندك تغييرات مش محفوظة. هل أنت متأكد من مغادرة الصفحة؟';
                return e.returnValue;
            }
        });
    });
    </script>
</body>
</html>
