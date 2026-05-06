# 🚀 دليل النشر على cPanel - Edarat365

## 📦 محتويات الحزمة

```
deploy-package/
├── 1-public_html.zip         (0.88 MB) ← يُرفع لـ public_html
├── 2-laravel-app.zip         (7.31 MB) ← يُرفع لمجلد آمن خارج public_html
├── edarat365_database.sql    (1.2 MB)  ← يُستورد في MySQL
└── DEPLOYMENT_GUIDE.md       ← هذا الملف
```

## 🏗️ المعمارية الاحترافية

```
/home/edarat/
├── laravel-app/           ← الباك إند (محمي، خارج الويب)
│   ├── app/
│   ├── config/
│   ├── routes/
│   ├── vendor/
│   ├── storage/
│   └── .env              ← السر الكبير، لا يُكشف أبدا
│
└── public_html/           ← الواجهة العامة فقط
    ├── index.html         ← Vue (الفرونت إند)
    ├── assets/            ← Vue assets
    ├── brand/             ← الشعارات
    ├── .htaccess          ← SPA routing
    └── api/               ← فقط نقطة دخول Laravel
        ├── index.php      ← يستدعي laravel-app
        ├── .htaccess
        └── favicon.ico
```

---

## 📋 الخطوات بالترتيب

### 1️⃣ إنشاء قاعدة البيانات في cPanel

1. ادخل cPanel: `https://ls1ksa.lot-server.com:2083/`
2. ابحث عن **MySQL Databases**
3. أنشئ قاعدة بيانات جديدة:
   - الاسم: `edarat365` (سيصبح: `edarat_edarat365`)
4. أنشئ مستخدم جديد:
   - اسم المستخدم: `edaratuser`
   - كلمة مرور قوية (احفظها)
5. **Add User to Database** → أعطِ المستخدم **ALL PRIVILEGES**

سجّل هذه القيم:
```
DB_DATABASE = edarat_edarat365
DB_USERNAME = edarat_edaratuser
DB_PASSWORD = <كلمة المرور التي أنشأتها>
```

---

### 2️⃣ استيراد قاعدة البيانات

1. في cPanel ابحث عن **phpMyAdmin**
2. اختر `edarat_edarat365` من اليسار
3. تبويب **Import** → ارفع `edarat365_database.sql`
4. اضغط **Go**

---

### 3️⃣ رفع ملفات Laravel (المحمية)

1. في cPanel ابحث عن **File Manager**
2. اذهب إلى المسار الجذر: `/home/edarat/`
3. اضغط **Upload** وارفع `2-laravel-app.zip`
4. بعد الرفع، حدّد الملف واضغط **Extract**
5. أعد تسمية المجلد المستخرج إلى: `laravel-app`
6. ✅ تأكد المسار: `/home/edarat/laravel-app/`

**المهم:** هذا المجلد يجب أن يكون **خارج** `public_html` تماما للأمان.

---

### 4️⃣ تكوين ملف `.env`

1. ادخل `/home/edarat/laravel-app/`
2. حرّر ملف `.env` (Edit في File Manager)
3. عدّل القيم التالية:

```env
APP_URL=https://YOUR_DOMAIN.com
FRONTEND_URL=https://YOUR_DOMAIN.com

DB_DATABASE=edarat_edarat365
DB_USERNAME=edarat_edaratuser
DB_PASSWORD=YOUR_DB_PASSWORD_FROM_STEP_1

MAIL_HOST=mail.YOUR_DOMAIN.com
MAIL_USERNAME=noreply@YOUR_DOMAIN.com
MAIL_PASSWORD=YOUR_MAIL_PASSWORD
MAIL_FROM_ADDRESS=noreply@YOUR_DOMAIN.com
```

4. **توليد APP_KEY**: ادخل **Terminal** في cPanel (إذا متاح) أو اطلب من الـ Support:
```bash
cd /home/edarat/laravel-app
php artisan key:generate
```

أو ضع هذه القيمة المؤقتة (واطلب توليد جديد لاحقا):
```
APP_KEY=base64:VKxMONShL5dD0OeKFY8xaI5VpFotZoee+Isly+T/mtk=
```

---

### 5️⃣ رفع الفرونت إند والـ API entry point

1. اذهب إلى `/home/edarat/public_html/`
2. **احذف** أي ملفات افتراضية قديمة (مثل `index.html` التجريبي إذا موجود)
3. ارفع `1-public_html.zip`
4. **Extract** الملف
5. ✅ تأكد إن المسار: `/home/edarat/public_html/index.html` موجود
6. ✅ تأكد إن المسار: `/home/edarat/public_html/api/index.php` موجود

---

### 6️⃣ ضبط الصلاحيات

في File Manager، حدّد المجلدات التالية وضع صلاحية:

| المجلد | الصلاحية |
|--------|----------|
| `/home/edarat/laravel-app/storage/` | **775** (recursive) |
| `/home/edarat/laravel-app/bootstrap/cache/` | **775** (recursive) |
| `/home/edarat/public_html/` | **755** |
| `/home/edarat/laravel-app/.env` | **600** |

---

### 7️⃣ ربط الدومين الفرعي

في cPanel:

1. ابحث عن **Subdomains** أو **Domains**
2. أضف الدومين الفرعي (مثلا: `edarat365.lotksa.com`)
3. **Document Root** يجب أن يكون: `/home/edarat/public_html`

---

### 8️⃣ إعداد SSL في Cloudflare

1. ادخل Cloudflare → اختر الدومين
2. **DNS** → أضف A Record:
   - Type: A
   - Name: `edarat365` (أو الفرعي)
   - IPv4: IP السيرفر
   - Proxy: 🟠 Proxied
3. **SSL/TLS** → اختر **Full (strict)**
4. **Edge Certificates** → فعّل **Always Use HTTPS**

---

### 9️⃣ تشغيل أوامر Laravel النهائية

عبر **Terminal** في cPanel أو **Cron Jobs**:

```bash
cd /home/edarat/laravel-app

# تحسين الأداء
php artisan config:cache
php artisan route:cache
php artisan view:cache

# (اختياري) تنظيف الكاش القديم
php artisan cache:clear
```

---

### 🔟 إعداد Cron Jobs (للمهام المجدولة)

في cPanel → **Cron Jobs**، أضف:

```bash
* * * * * cd /home/edarat/laravel-app && php artisan schedule:run >> /dev/null 2>&1
```

---

## ✅ التحقق من نجاح النشر

1. افتح: `https://YOUR_DOMAIN.com` → يجب أن تظهر صفحة تسجيل الدخول
2. افتح: `https://YOUR_DOMAIN.com/api/v1/health` (أو أي endpoint) → يجب أن يرجع JSON
3. سجّل دخول بحساب admin
4. تأكد من تحميل البيانات

---

## 🔐 معلومات تسجيل الدخول الافتراضية

استخدم نفس بيانات الـ admin من قاعدة البيانات الحالية، أو أنشئ حساب جديد:

```bash
cd /home/edarat/laravel-app
php artisan tinker
> \App\Models\User::create(['name' => 'Admin', 'email' => 'admin@edarat.com', 'password' => bcrypt('YourStrongPassword')]);
```

---

## 🆘 استكشاف الأخطاء

### خطأ 500 على API:
- تحقق من `APP_KEY` في `.env`
- تحقق من صلاحيات `storage/` و `bootstrap/cache/`
- شاهد اللوقات في `/home/edarat/laravel-app/storage/logs/laravel.log`

### الفرونت إند يعرض 404:
- تأكد من وجود `.htaccess` في `public_html/`
- تحقق من تفعيل `mod_rewrite` على السيرفر

### قاعدة البيانات لا تتصل:
- تأكد من اسم القاعدة (يبدأ بـ `edarat_`)
- تأكد من كلمة المرور
- جرّب `DB_HOST=127.0.0.1` بدلا من `localhost`

### CORS errors:
- تأكد إن `FRONTEND_URL` في `.env` يطابق الدومين الفعلي
- أعد تشغيل: `php artisan config:cache`

---

## 📞 ملاحظات مهمة

- **النسخ الاحتياطي**: عبر cPanel → Backup يوميا
- **الأمان**: لا تشارك ملف `.env` أبدا
- **التحديثات**: عند رفع نسخة جديدة، استخدم نفس الهيكل
- **الفهرسة**: المشروع محمي من محركات البحث (5 طبقات)
