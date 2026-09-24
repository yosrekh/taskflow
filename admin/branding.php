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
                <p class="page-subtitle">تخصيص اسم النظام وشعاراته وأيقونة الموقع لتلائم هوية شركتك</p>
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
        <form method="POST" action="branding.php" enctype="multipart/form-data" class="branding-form">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="save_branding">

            <div class="branding-grid">
                <!-- 1. App Name Card -->
                <div class="card branding-card">
                    <div class="card-header branding-card-header">
                        <div class="branding-card-title-wrap">
                            <h2 class="card-title">اسم التطبيق (App Name)</h2>
                            <span class="card-desc">يظهر في عنوان جميع الصفحات، شريط التنقل، وملف PWA Manifest.</span>
                        </div>
                        <span class="badge <?= $branding['app_name'] !== 'TaskFlow' ? 'badge-custom' : 'badge-default' ?>">
                            <?= $branding['app_name'] !== 'TaskFlow' ? 'مخصص' : 'افتراضي' ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="form-group">
                            <label for="app_name_input" class="form-label">الاسم الكامل <span class="required">*</span></label>
                            <input type="text" id="app_name_input" name="app_name" class="form-control" value="<?= htmlspecialchars($branding['app_name']) ?>" required placeholder="TaskFlow">
                        </div>
                    </div>
                    <?php if ($branding['app_name'] !== 'TaskFlow'): ?>
                    <div class="card-footer branding-card-footer">
                        <button type="submit" form="reset-app_name-form" class="btn btn-secondary btn-sm">استرجاع الافتراضي</button>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- 2. Dark Background Logo Card -->
                <div class="card branding-card">
                    <div class="card-header branding-card-header">
                        <div class="branding-card-title-wrap">
                            <h2 class="card-title">الشعار للخلفيات الداكنة (Dark Backgrounds)</h2>
                            <span class="card-desc">يُستخدم في شريط التنقل العلوي (الداكن دائماً) وفي صفحة تسجيل الدخول بالوضع الداكن.</span>
                        </div>
                        <span class="badge <?= $branding['is_custom_logo_dark'] ? 'badge-custom' : 'badge-default' ?>">
                            <?= $branding['is_custom_logo_dark'] ? 'مخصص' : 'افتراضي' ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <!-- Dual Preview: Navy vs White -->
                        <div class="branding-dual-preview">
                            <div class="preview-box preview-navy">
                                <span class="preview-box-label">خلفية داكنة (شريط التنقل)</span>
                                <img src="<?= htmlspecialchars($branding['logo_dark_url']) ?>" alt="معاينة الشعار الداكن" class="preview-img" id="preview_logo_dark_navy">
                            </div>
                            <div class="preview-box preview-white">
                                <span class="preview-box-label">خلفية فاتحة</span>
                                <img src="<?= htmlspecialchars($branding['logo_dark_url']) ?>" alt="معاينة الشعار الداكن" class="preview-img" id="preview_logo_dark_white">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="file_logo_dark" class="form-label">رفع شعار جديد (SVG, PNG, WebP — أقصى حجم 1 ميجابايت)</label>
                            <input type="file" id="file_logo_dark" name="logo_dark" accept=".svg,.png,.webp" class="form-control branding-file-input" data-preview-targets="preview_logo_dark_navy,preview_logo_dark_white">
                        </div>
                    </div>
                    <?php if ($branding['is_custom_logo_dark']): ?>
                    <div class="card-footer branding-card-footer">
                        <button type="submit" form="reset-logo_dark-form" class="btn btn-secondary btn-sm">استرجاع الافتراضي</button>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- 3. Light Background Logo Card -->
                <div class="card branding-card">
                    <div class="card-header branding-card-header">
                        <div class="branding-card-title-wrap">
                            <h2 class="card-title">الشعار للخلفيات الفاتحة (Light Backgrounds)</h2>
                            <span class="card-desc">يُستخدم في بطاقة تسجيل الدخول وتغيير كلمة المرور بالوضع الفاتح.</span>
                        </div>
                        <span class="badge <?= $branding['is_custom_logo_light'] ? 'badge-custom' : 'badge-default' ?>">
                            <?= $branding['is_custom_logo_light'] ? 'مخصص' : 'افتراضي' ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <!-- Dual Preview -->
                        <div class="branding-dual-preview">
                            <div class="preview-box preview-white">
                                <span class="preview-box-label">خلفية فاتحة (بطاقة تسجيل الدخول)</span>
                                <img src="<?= htmlspecialchars($branding['logo_light_url']) ?>" alt="معاينة الشعار الفاتح" class="preview-img" id="preview_logo_light_white">
                            </div>
                            <div class="preview-box preview-navy">
                                <span class="preview-box-label">خلفية داكنة</span>
                                <img src="<?= htmlspecialchars($branding['logo_light_url']) ?>" alt="معاينة الشعار الفاتح" class="preview-img" id="preview_logo_light_navy">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="file_logo_light" class="form-label">رفع شعار جديد (SVG, PNG, WebP — أقصى حجم 1 ميجابايت)</label>
                            <input type="file" id="file_logo_light" name="logo_light" accept=".svg,.png,.webp" class="form-control branding-file-input" data-preview-targets="preview_logo_light_white,preview_logo_light_navy">
                        </div>
                    </div>
                    <?php if ($branding['is_custom_logo_light']): ?>
                    <div class="card-footer branding-card-footer">
                        <button type="submit" form="reset-logo_light-form" class="btn btn-secondary btn-sm">استرجاع الافتراضي</button>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- 4. Favicon Card -->
                <div class="card branding-card">
                    <div class="card-header branding-card-header">
                        <div class="branding-card-title-wrap">
                            <h2 class="card-title">أيقونة الموقع (Favicon)</h2>
                            <span class="card-desc">تظهر في تبويب المتصفح والإشارات المرجعية. يجب أن تكون مربعة الأبعاد (أقصى حجم 256 كيلوبايت).</span>
                        </div>
                        <span class="badge <?= $branding['is_custom_favicon'] ? 'badge-custom' : 'badge-default' ?>">
                            <?= $branding['is_custom_favicon'] ? 'مخصص' : 'افتراضي' ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <!-- Dual Preview -->
                        <div class="branding-dual-preview">
                            <div class="preview-box preview-navy">
                                <span class="preview-box-label">تبويب داكن</span>
                                <img src="<?= htmlspecialchars($branding['favicon_url']) ?>" alt="معاينة Favicon" class="preview-img preview-img-fav" id="preview_favicon_navy">
                            </div>
                            <div class="preview-box preview-white">
                                <span class="preview-box-label">تبويب فاتح</span>
                                <img src="<?= htmlspecialchars($branding['favicon_url']) ?>" alt="معاينة Favicon" class="preview-img preview-img-fav" id="preview_favicon_white">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="file_favicon" class="form-label">رفع أيقونة Favicon مربعة (SVG أو PNG — أقصى حجم 256 كيلوبايت)</label>
                            <input type="file" id="file_favicon" name="favicon" accept=".svg,.png" class="form-control branding-file-input" data-preview-targets="preview_favicon_navy,preview_favicon_white">
                            <span class="form-hint">إذا تم رفع SVG غير مربع الأبعاد، سيتم ضبط إحداثيات viewBox تلقائياً لتتوسط الأيقونة مساحة مربعة.</span>
                        </div>
                    </div>
                    <?php if ($branding['is_custom_favicon']): ?>
                    <div class="card-footer branding-card-footer">
                        <button type="submit" form="reset-favicon-form" class="btn btn-secondary btn-sm">استرجاع الافتراضي</button>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- 5. Dark Favicon Variant (Optional) -->
                <div class="card branding-card">
                    <div class="card-header branding-card-header">
                        <div class="branding-card-title-wrap">
                            <h2 class="card-title">أيقونة خاصة بتبويبات المتصفح الداكنة (اختياري)</h2>
                            <span class="card-desc">نسخة مخصصة تظهر فقط عندما يكون المتصفح في الوضع الداكن (Dark Mode).</span>
                        </div>
                        <span class="badge <?= $branding['is_custom_favicon_dark'] ? 'badge-custom' : 'badge-default' ?>">
                            <?= $branding['is_custom_favicon_dark'] ? 'مخصص' : 'غير محدد (تلقائي)' ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <!-- Dual Preview -->
                        <div class="branding-dual-preview">
                            <div class="preview-box preview-navy">
                                <span class="preview-box-label">تبويب داكن</span>
                                <img src="<?= htmlspecialchars($branding['favicon_dark_url'] ?: $branding['favicon_url']) ?>" alt="معاينة Favicon الداكن" class="preview-img preview-img-fav" id="preview_fav_dark_navy">
                            </div>
                            <div class="preview-box preview-white">
                                <span class="preview-box-label">تبويب فاتح</span>
                                <img src="<?= htmlspecialchars($branding['favicon_dark_url'] ?: $branding['favicon_url']) ?>" alt="معاينة Favicon الداكن" class="preview-img preview-img-fav" id="preview_fav_dark_white">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="file_favicon_dark" class="form-label">رفع أيقونة للوضع الداكن (SVG أو PNG — أقصى حجم 256 كيلوبايت)</label>
                            <input type="file" id="file_favicon_dark" name="favicon_dark" accept=".svg,.png" class="form-control branding-file-input" data-preview-targets="preview_fav_dark_navy,preview_fav_dark_white">
                        </div>
                    </div>
                    <?php if ($branding['is_custom_favicon_dark']): ?>
                    <div class="card-footer branding-card-footer">
                        <button type="submit" form="reset-favicon_dark-form" class="btn btn-secondary btn-sm">استرجاع الافتراضي</button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Submit Section -->
            <div class="branding-actions-bar">
                <button type="submit" class="btn btn-primary btn-lg">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                    حفظ كافة التغييرات
                </button>
            </div>
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

    <!-- Client-side Real-time Preview Script -->
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const fileInputs = document.querySelectorAll('.branding-file-input');

        fileInputs.forEach(input => {
            input.addEventListener('change', function () {
                const targets = (this.dataset.previewTargets || '').split(',');
                if (!this.files || !this.files[0]) return;

                const file = this.files[0];
                const objectUrl = URL.createObjectURL(file);

                targets.forEach(targetId => {
                    const imgEl = document.getElementById(targetId.trim());
                    if (imgEl) {
                        imgEl.src = objectUrl;
                    }
                });

                if (typeof showToast === 'function') {
                    showToast('تم تحديث المعاينة الحية. لا تنس الضغط على حفظ التغييرات.', 'info');
                }
            });
        });
    });
    </script>
</body>
</html>
