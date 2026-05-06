# 🚀 دليل النشر على cPanel عبر GitHub - Edarat365

## 📍 معلومات المستودع

- **GitHub Repo:** `lotksa/edarat365-production` (Private)
- **Clone URL:** `https://github.com/lotksa/edarat365-production.git`
- **Server cPanel:** `https://ls1ksa.lot-server.com:2083/`
- **User:** `edarat`

---

## 🎯 طريقة النشر الاحترافية: cPanel Git Version Control

> ✅ هذي أفضل طريقة - تسحب من GitHub مباشرة، وأي تحديث في المستقبل بضغطة زر.

### الخطوة 1: إنشاء Personal Access Token على GitHub

لأن المستودع **Private**، نحتاج Token للسحب:

1. روح على: https://github.com/settings/tokens
2. اضغط **Generate new token (classic)**
3. الاسم: `cpanel-edarat-deploy`
4. الصلاحيات: ✅ `repo` (كامل)
5. اضغط **Generate token** ← انسخ التوكن

### الخطوة 2: إنشاء Git Repo في cPanel

1. سجّل دخول: https://ls1ksa.lot-server.com:2083/
2. ابحث عن **"Git Version Control"** أو **"Git™ Version Control"**
3. اضغط **Create**
4. املأ:
   - **Clone URL:** `https://USERNAME:TOKEN@github.com/lotksa/edarat365-production.git`
     - استبدل `USERNAME` بـ `lotksa`
     - استبدل `TOKEN` بالتوكن من الخطوة 1
   - **Repository Path:** `/home/edarat/repositories/edarat365/`
   - **Repository Name:** `edarat365`
5. اضغط **Create**

سيتم سحب المشروع تلقائيًا.

### الخطوة 3: نشر الملفات تلقائيًا

1. في cPanel Git → اضغط **Manage** بجانب المستودع
2. روح لتبويب **Pull or Deploy**
3. اضغط **Deploy HEAD Commit**
4. الملف `.cpanel.yml` راح ينفذ تلقائيًا وينقل الملفات:
   - `public_html/` ← لـ `/home/edarat/public_html/`
   - `laravel-app/` ← لـ `/home/edarat/laravel-app/`

---

## 🗄️ الخطوة 4: إعداد قاعدة البيانات

### إنشاء قاعدة بيانات

1. cPanel → **MySQL Databases**
2. أنشئ قاعدة: `edarat_main` (الاسم الكامل سيكون `edarat_edarat_main`)
3. أنشئ مستخدم: `edarat_user` بكلمة سر قوية
4. أعطِه **All Privileges** على القاعدة

### استيراد البيانات

1. cPanel → **phpMyAdmin**
2. اختر قاعدة `edarat_edarat_main`
3. اضغط **Import**
4. ارفع: `/home/edarat/repositories/edarat365/database/edarat365.sql`
5. اضغط **Go**

---

## 🔐 الخطوة 5: إعداد ملف `.env`

1. cPanel → **File Manager** → روح لـ `/home/edarat/laravel-app/`
2. افتح `.env` وعدّل:

```ini
APP_NAME=Edarat365
APP_ENV=production
APP_DEBUG=false
APP_URL=https://YOUR-DOMAIN.com

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=edarat_edarat_main
DB_USERNAME=edarat_edarat_user
DB_PASSWORD=YOUR_DB_PASSWORD

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database

FRONTEND_URL=https://YOUR-DOMAIN.com
SANCTUM_STATEFUL_DOMAINS=YOUR-DOMAIN.com
SESSION_DOMAIN=.YOUR-DOMAIN.com
```

3. **مهم جدًا**: ولّد APP_KEY عبر Terminal (إذا متاح) أو ضع قيمة base64 جاهزة

---

## ⚙️ الخطوة 6: ضبط الصلاحيات

من **File Manager** أو **Terminal**:

```bash
chmod -R 755 /home/edarat/laravel-app
chmod -R 775 /home/edarat/laravel-app/storage
chmod -R 775 /home/edarat/laravel-app/bootstrap/cache
chmod 600 /home/edarat/laravel-app/.env
```

---

## 🌐 الخطوة 7: ربط الدومين عبر Cloudflare

### في Cloudflare Dashboard:

1. أضف الدومين أو الساب دومين
2. **DNS Records:**
   - Type: `A`
   - Name: `@` (أو الساب دومين)
   - Content: IP السيرفر (من cPanel → Server Information)
   - Proxy: 🟠 Proxied (سحاب برتقالي)

3. **SSL/TLS:**
   - SSL Mode: **Full (strict)** أو **Full**

### في cPanel:

1. **Domains** → أضف الدومين
2. **SSL/TLS** → فعّل Let's Encrypt إذا متاح

---

## ✅ الخطوة 8: اختبار النشر

افتح في المتصفح:
- `https://YOUR-DOMAIN.com` ← يجب أن تظهر صفحة الدخول
- `https://YOUR-DOMAIN.com/api/health` ← يجب أن ترجع `{"status":"ok"}`

---

## 🔄 التحديثات المستقبلية (سهلة جدًا)

عند أي تحديث في الكود:

```bash
# في جهازك المحلي:
cd deploy-package
git add -A
git commit -m "Update X"
git push
```

ثم في cPanel:
1. Git Version Control → Manage → **Update from Remote**
2. اضغط **Deploy HEAD Commit**

✨ تم! المشروع محدّث على السيرفر.

---

## ⚠️ ملاحظات مهمة

- ✅ المشروع **محمي من فهرسة محركات البحث** (robots.txt + meta tags + headers)
- ✅ Vendor مرفوع مع المشروع - لا تحتاج composer install على السيرفر
- ✅ Frontend مبني (dist) - لا تحتاج npm build على السيرفر
- ⚠️ التحديثات تتطلب فقط: git push من جهازك → Deploy في cPanel

---

## 🆘 استكشاف الأخطاء

### خطأ 500 على الـ API:
- تحقق من `.env` (DB credentials)
- تحقق من صلاحيات `storage/` و `bootstrap/cache/`
- فحص اللوقات: `/home/edarat/laravel-app/storage/logs/laravel.log`

### الفرونت يعمل لكن الـ API لا:
- تحقق من `.htaccess` في `public_html/api/`
- تحقق من مسار `index.php` يشير لـ `../../laravel-app`

### CORS errors:
- تحقق من `FRONTEND_URL` في `.env`
- تحقق من `SESSION_DOMAIN` يبدأ بـ `.`
