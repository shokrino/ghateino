# Ghateino (قطعینو)

افزونه `Ghateino` برای مدیریت درخواست‌های خروجی وردپرس، کاهش وابستگی به CDN، و پایدار نگه داشتن سایت در شرایط قطع یا محدودیت اینترنت طراحی شده است.

رویکرد جدید افزونه: `Local First + Block Fast`

- اگر asset خارجی معادل لوکال داشته باشد، با اولویت بالا جایگزین می‌شود.
- اگر معادل لوکال نداشته باشد و حالت سخت‌گیرانه فعال باشد، سریع بلاک می‌شود تا کندی شبکه ایجاد نشود.

## امکانات اصلی

- مدیریت درخواست‌های HTTP خروجی با سه حالت:
- `disabled`: بدون محدودیت
- `whitelist`: فقط دامنه‌های مجاز
- `blacklist`: مسدودسازی دامنه‌های مشخص
- غیرفعال‌سازی telemetry وردپرس (`wordpress.org`)
- غیرفعال‌سازی بررسی آپدیت هسته، قالب و افزونه
- غیرفعال‌سازی Gravatar و جایگزینی با تصویر محلی
- ثبت لاگ درخواست‌های مسدودشده با نگهداری زمان‌دار
- پاک‌سازی لاگ‌ها از پنل تنظیمات

## امکانات اضافه‌شده برای لوکال‌سازی Assetها

- جایگزینی خودکار اسکریپت‌های CDN با نسخه لوکال داخل پلاگین:
- `jquery.min.js`
- `jquery-migrate.min.js`
- `underscore.min.js`
- `backbone.min.js`
- `react.min.js`
- `react-dom.min.js`
- جایگزینی خودکار `Swiper` JS/CSS با نسخه لوکال
- جایگزینی خودکار `Font Awesome` CSS با نسخه لوکال:
- `all.min.css`
- `v4-shims.min.css`
- `ace.min.js`
- `ext-language_tools.js`
- جایگزینی `Google Fonts (Roboto)` با CSS/Font لوکال
- جایگزینی `dashicons` با فایل‌های لوکال (CSS + فونت)
- جایگزینی `eicons` (Elementor Icons) با فایل‌های لوکال (CSS + فونت)
- آماده‌سازی خودکار فایل‌های لوکال در اجرای افزونه (در صورت وجود منبع محلی)

## بلاک سریع Asset خارجی

- گزینه `strict_asset_block` اضافه شده و به‌صورت پیش‌فرض فعال است.
- وقتی فعال باشد، هر CSS/JS خارجی که معادل لوکال پیدا نکند، با فایل fallback داخلی جایگزین می‌شود:
- `assets/js/blocked-asset.js`
- `assets/css/blocked-asset.css`

## مدیریت Mixpanel

- مسدودسازی درخواست‌های Mixpanel از جمله:
- `api-eu.mixpanel.com`
- `api.mixpanel.com`
- `cdn.mxpnl.com`
- `api-js.mixpanel.com`
- جایگزینی اسکریپت Mixpanel با فایل داخلی امن:
- `assets/js/mixpanel-stub.js`

## مسیر فایل‌های محلی

- `assets/vendor/wp-core-js/`
- `assets/vendor/swiper/`
- `assets/vendor/fontawesome/css/`
- `assets/vendor/fontawesome/webfonts/`
- `assets/vendor/google-fonts/`
- `assets/vendor/google-fonts/fonts/`
- `assets/vendor/dashicons/css/`
- `assets/vendor/dashicons/fonts/`
- `assets/vendor/eicons/css/`
- `assets/vendor/eicons/fonts/`
- `assets/js/blocked-asset.js`
- `assets/css/blocked-asset.css`
- `assets/js/mixpanel-stub.js`

## نصب

1. پوشه افزونه را در مسیر `wp-content/plugins/ghateino` قرار دهید.
2. از پنل وردپرس افزونه را فعال کنید.
3. به مسیر زیر بروید:
- `Settings -> قطعینو`
4. تنظیمات مورد نظر را ذخیره کنید.

## تنظیمات مهم

- `حالت کاری فایروال`: تعیین رفتار کلی مسدودسازی
- `لیست سفید / لیست سیاه`: هر دامنه در یک خط
- `جایگزینی CDN با فایل محلی`: فعال برای لوکال‌سازی خودکار
- `مسدودسازی Mixpanel`: فعال برای جلوگیری از ارتباط با Mixpanel
- `بلاک سختگیرانه Asset خارجی`: جلوگیری فوری از لود هر asset خارجی بدون نسخه لوکال
- `ثبت لاگ درخواست‌ها`: فقط در زمان عیب‌یابی فعال شود
- `نگهداری لاگ‌ها`: 1، 3، 7، 15، 30 روز

## نکات فنی

- فیلترهای اصلی وردپرس مورد استفاده:
- `pre_http_request`
- `script_loader_src`
- `style_loader_src`
- نگاشت سفارشی قابل توسعه با فیلتر:
- `ghateino_local_script_rewrite`

## نکته سازگاری

- این افزونه برای جلوگیری از وابستگی بیرونی طراحی شده است. اگر سرویسی باید حتما خارجی بماند، `strict_asset_block` را خاموش کنید یا نگاشت لوکال برای آن اضافه کنید.

## نسخه

- فعلی: `1.1.0`

## توسعه‌دهنده

- Shokrino Team
- https://shokrino.com
