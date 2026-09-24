# دليل نشر TaskFlow على استضافة cPanel المشتركة

دليل شامل خطوة بخطوة لنشر وإدارة نظام **TaskFlow** على استضافات cPanel المشتركة (خوادم Apache أو LiteSpeed بدون الحاجة لصلاحيات SSH).

---

## المتطلبات الأساسية
- خادم يدعم **PHP 8.0** أو أحدث (يوصى بـ **PHP 8.1 / 8.2**).
- قاعدة بيانات **MySQL 5.7+** أو **MariaDB 10.3+**.
- ملحقات PHP: `pdo_mysql`, `mbstring`, `openssl`, `json`.
- تفعيل موديول `mod_rewrite` و `mod_headers` في Apache/LiteSpeed (مفعل افتراضياً في معظم استضافات cPanel).

---

## 1. إنشاء النطاق الفرعي (Subdomain)
1. سجّل الدخول إلى لوحة تحكم **cPanel**.
2. توجّه إلى قسم **Domains** واختر **Domains** أو **Subdomains**.
3. اضغط على **Create A New Domain**.
4. أدخل اسم النطاق الفرعي (مثال: `tasks.example.com`).
5. حدد مجلد المستندات (Document Root) الخاص به، مثل: `public_html/tasks` أو `/tasks.example.com`.
6. اضغط **Submit**.

---

## 2. تحديد إصدار PHP
1. من لوحة cPanel، ابحث عن **MultiPHP Manager** أو **Select PHP Version**.
2. اختر النطاق الفرعي الذي أنشأته (`tasks.example.com`).
3. عيّن إصدار PHP إلى **PHP 8.1** أو **PHP 8.2**.
4. تأكد من تفعيل ملحقات `pdo_mysql` و `mbstring` و `openssl`.

---

## 3. إنشاء قاعدة البيانات والمستخدم
1. توجّه إلى **MySQL Database Wizard** في cPanel.
2. **الخطوة 1**: أدخل اسم قاعدة البيانات (مثال: `user_taskflow`) واضغط **Next Step**.
3. **الخطوة 2**: أدخل اسم المستخدم (مثال: `user_taskusr`) وقم بتوليد كلمة مرور قوية، ثم اضغط **Create User**.
4. **الخطوة 3**: امنح المستخدم كافة الصلاحيات بالتأشير على **ALL PRIVILEGES** واضغط **Make Changes**.
5. احفظ اسم قاعدة البيانات واسم المستخدم وكلمة المرور لاستخدامها لاحقاً.

---

## 4. استيراد المخطط (Schema) عبر phpMyAdmin
1. ارجع للصفحة الرئيسية في cPanel وافتح **phpMyAdmin**.
2. من القائمة الجانبية، اختر قاعدة البيانات التي أنشأتها للتو (`user_taskflow`).
3. اضغط على تبويب **Import** في الشريط العلوي.
4. اضغط **Choose File** واختر الملف `db/taskflow_db.sql` من المشروع.
5. اضغط **Import** (أو **Go**) أسفل الصفحة للتنفيذ.
> **ملاحظة:** ملف `db/taskflow_db.sql` مهيأ خصيصاً للاستيراد المباشر ولا يحتوي على أوامر `CREATE DATABASE` أو `USE`، ليتم الاستيراد بسلاسة في أي قاعدة بيانات محددة.

---

## 5. رفع وفك ضغط ملفات النظام
1. على جهازك المحلي، قم بتوليد ملف الإصدار النظيف المضغوط (باستثناء ملفات التطوير):
   ```bash
   git archive --format=zip -o ../taskflow-release.zip main
   ```
2. في cPanel، افتح **File Manager** وتوجه إلى مجلد النطاق الفرعي (Document Root).
3. اضغط **Upload** وارفع ملف `taskflow-release.zip`.
4. بعد اكتمال الرفع، انقر بالزر الأيمن على الملف المضغوط واختر **Extract** لفك ضغط المحتويات في نفس المجلد.
5. احذف ملف الـ zip بعد فك الضغط.

---

## 6. إعداد ملف البيئة (.env)
لأقصى درجات الأمان وحماية بيانات الاتصال:
1. في **File Manager**، انتقل إلى المجلد الذي يقع **أعلى من مجلد الويب مباشرة** (خارج `public_html`، مثل المجلد الرئيسي للمستخدم `/home/username/`).
2. أنشئ ملفاً جديداً باسم `.env` (تأكد من تفعيل خيار "Show Hidden Files" في إعدادات File Manager لرؤية الملفات التي تبدأ بنقطة).
3. أضف الإعدادات التالية مع تعديل بيانات قاعدة البيانات الخاصة بك:
   ```ini
   DB_HOST=localhost
   DB_NAME=user_taskflow
   DB_USER=user_taskusr
   DB_PASS=your_strong_password_here

   APP_ENV=production
   APP_TIMEZONE=Africa/Cairo
   SESSION_NAME=TASKFLOW_SESSID
   ```
> يدعم TaskFlow قراءة `.env` من المجلد الأعلى تلقائياً قبل الرجوع لمجلد المشروع، مما يمنع نهائياً أي وصول لبياناتك الحساسة عبر المتصفح.

---

## 7. تفعيل شهادة الأمان SSL (AutoSSL)
1. في cPanel، افتح **SSL/TLS Status**.
2. اختر النطاق الفرعي الخاص بك واضغط **Run AutoSSL** لتثبيت شهادة أمان مجانية (Let's Encrypt / cPanel Sectigo).
3. ملف `.htaccess` المرفق بالنظام يقوم تلقائياً بإعادة توجيه كافة الاتصالات غير الآمنة إلى **HTTPS (301)** ما لم تكن البيئة خادماً محلياً (localhost).

---

## 8. إنشاء حساب المدير الأول (بدون الحاجة لـ SSH)
في الاستضافات المشتركة التي لا توفر وصول SSH عبر الطرفية:
1. على جهازك المحلي، شغّل الأمر التالي في مجلد المشروع:
   ```bash
   php bin/make-admin-sql.php "اسم المدير" admin@example.com
   ```
2. ستظهر لك في الشاشة كلمة مرور مؤقتة (انسخها واحتفظ بها)، ومرفق معها استعلام SQL جاهز بصيغة:
   ```sql
   INSERT INTO users (name, email, password, role, is_active, must_change_password) VALUES ('اسم المدير', 'admin@example.com', '$2y$12$...', 'admin', 1, 1);
   ```
3. افتح **phpMyAdmin** في cPanel، اختر قاعدة البيانات، واضغط على تبويب **SQL**.
4. الصق الاستعلام واضغط **Go** لتنفيذه.
5. توجّه إلى المتصفح وافتح رابط النظام: `https://tasks.example.com/login.php`.
6. سجّل الدخول بالبريد الإلكتروني وكلمة المرور المؤقتة؛ سيُطلب منك فوراً تعيين كلمة مرور جديدة دائمة لحسابك.

---

## 9. قائمة فحص ما بعد النشر (Post-Deploy Checklist)
- [ ] فتح الرابط والتأكد من عمل شهادة SSL والتحويل التلقائي لـ HTTPS.
- [ ] محاولة الوصول للمسارات الحساسة والتأكد من إرجاع **403 Forbidden**:
  - `https://tasks.example.com/.env`
  - `https://tasks.example.com/includes/db.php`
  - `https://tasks.example.com/bin/create-admin.php`
  - `https://tasks.example.com/db/taskflow_db.sql`
- [ ] تسجيل الدخول كمدير والتأكد من ظهور قسم "المستخدمين" في شريط التنقل.
- [ ] إنشاء مشروع جديد ومهمة تجريبية والتأكد من أن توقيت الإشعارات مطابق لتوقيت القاهرة (`Africa/Cairo`).
- [ ] فحص كوكيز الجلسة والتأكد من أنها تحمل الاسم `TASKFLOW_SESSID` وتتضمن وسوم `Secure`, `HttpOnly`, `SameSite=Lax`.

---

---

## 10. كيفية التحديث مستقبلاً (Updating)
1. قم بإنشاء ملف الحزمة الجديدة:
   ```bash
   git archive --format=zip -o ../taskflow-release-vX.zip main
   ```
2. ارفع الملف المضغوط إلى مجلد النطاق الفرعي عبر File Manager وقم بفك الضغط لاستبدال ملفات الكود.
   > **⚠️ تنبيه أمني وتخزيني هام:** يجب **عدم استبدال أو حذف مجلد `uploads/`** إطلاقاً أثناء التحديث؛ حيث يحتوي على شعارات وأيقونات الشركات المخصصة، وملف `.env` الموجود بالخارج لن يتأثر إطلاقاً بفك الضغط.
3. في حال وجود ترحيلات لقاعدة البيانات جديدة داخل `db/migrations/`:
   - افتح الملفات الجديدة (مثل `003_settings.sql`).
   - انسخ محتواها ونفّذه عبر تبويب **SQL** في phpMyAdmin.

---

## 11. إضافة شركة جديدة (Multi-Install Isolation)
يتيح TaskFlow تشغيل نسخ متعددة ومعزولة تماماً لكل شركة على نفس حساب cPanel (لكل شركة نطاقها الفرعي وقاعدة بياناتها وهويتها البصرية الخاصة):

1. **إنشاء النطاق الفرعي ومجلده**:
   - من cPanel > **Domains** > **Create A New Domain**.
   - أدخل النطاق الفرعي للشركة (مثال: `company-a.example.com`).
   - حدد مجلد المستندات الخاص به (مثال: `company-a.example.com`).
2. **إنشاء قاعدة بيانات جديدة**:
   - عبر **MySQL Database Wizard**، أنشئ قاعدة بيانات ومستخدم مخصصين للشركة (مثال: `user_comp_a`).
   - افتح **phpMyAdmin** واستورد ملف `db/taskflow_db.sql` داخل قاعدة البيانات الجديدة.
3. **إعداد ملف البيئة المعزول (.env.<folder>)**:
   - في المجلد الرئيسي أعلى مجلدات النطاقات الفرعية (`/home/username/`)، أنشئ ملف بيئة مخصصاً باسم مجلد النطاق الفرعي:
     `.env.<basename_of_folder>` (مثال: `/home/username/.env.company-a.example.com`).
   - أضف بيانات الاتصال الخاصة بقاعدة بيانات الشركة:
     ```ini
     DB_HOST=localhost
     DB_NAME=user_comp_a
     DB_USER=user_comp_a_usr
     DB_PASS=company_secret_password
     APP_ENV=production
     APP_TIMEZONE=Africa/Cairo
     SESSION_NAME=TASKFLOW_COMPA_SESSID
     ```
   - سيقوم TaskFlow بقراءة هذا الملف تلقائياً لخصوصية هذا النطاق، مما يضمن عزلاً كاملاً بين الشركات.
4. **نشر الكود وإنشاء المدير الأول**:
   - فك ضغط ملف الإصدار `taskflow-release.zip` داخل مجلد النطاق الفرعي الجديد (`company-a.example.com`).
   - ولّد استعلام إنشاء حساب المدير الأول عبر جهازك المحلي:
     ```bash
     php bin/make-admin-sql.php "مدير الشركة" admin@company-a.example.com
     ```
   - نفّذ الاستعلام في phpMyAdmin داخل قاعدة بيانات الشركة الجديدة.
5. **تخصيص الهوية البصرية (Branding)**:
   - سجّل الدخول كمدير في النظام الجديد `https://company-a.example.com/login.php`.
   - توجّه إلى القائمة العلوية > **الهوية البصرية** (`admin/branding.php`).
   - قم بتعيين اسم الشركة ورفع شعاراتها (للخلفيات الداكنة والفاتحة) وأيقونة الموقع Favicon لتكتمل هوية الشركة الخاصة.

---

## 12. النسخ الاحتياطي الدوري (Backups)
1. **نسخ قاعدة البيانات**:
   - افتح **phpMyAdmin**، اختر قاعدة البيانات، واضغط تبويب **Export**.
   - اختر طريقة التصدير السريعة **Quick** بصيغة **SQL** واضغط **Export**.
2. **نسخ الملفات**:
   - من cPanel، استخدم **Backup Wizard** أو توجّه إلى **File Manager**، حدد ملفات مجلد النطاق الفرعي (بما فيها مجلد `uploads/`) بالإضافة لملف `.env` أو `.env.<folder>`، واضغط **Compress** لحفظها بصيغة ZIP وتحميلها محلياً.

