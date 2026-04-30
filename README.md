# Ghateino (قطعینو)

`Ghateino` یک افزونه WordPress با تمرکز روی پایداری شبکه است: کاهش وابستگی به CDN، کنترل خروجی HTTP، و جلوگیری از کندی ناشی از اتصال ناپایدار.

رویکرد اصلی پروژه:

`Local First + Block Fast`

- اگر برای asset خارجی نسخه محلی داشته باشیم، فوری rewrite می‌شود.
- اگر نسخه محلی نداشته باشیم و حالت سخت‌گیرانه فعال باشد، درخواست بلاک می‌شود.

---

## چرا Ghateino؟

در محیط‌هایی که دسترسی اینترنت متغیر یا محدود است، وابستگی مستقیم به CDN می‌تواند Time To Interactive را نابود کند. `Ghateino` به‌جای retryهای طولانی، مسیر deterministic می‌دهد:

- اولویت با فایل محلی
- fallback امن داخلی
- بلاک سریع درخواست‌های بدون نگاشت

نتیجه: رفتار قابل پیش‌بینی‌تر فرانت‌اند، کاهش timeoutهای آزاردهنده، و تجربه کاربری پایدارتر.

---

## Feature Highlights

### 1) کنترل درخواست‌های HTTP خروجی

سه حالت عملیاتی:

- `disabled`: بدون محدودیت
- `whitelist`: فقط دامنه‌های مجاز
- `blacklist`: مسدودسازی دامنه‌های مشخص

امکانات تکمیلی:

- غیرفعال‌سازی telemetry وردپرس (`wordpress.org`)
- غیرفعال‌سازی بررسی آپدیت هسته/قالب/افزونه
- غیرفعال‌سازی Gravatar + جایگزینی تصویر محلی
- ثبت لاگ درخواست‌های مسدودشده با retention قابل تنظیم

### 2) Local Asset Rewriting

جایگزینی خودکار CDN با فایل‌های local plugin:

- WP Core JS: `jquery`, `jquery-migrate`, `underscore`, `backbone`, `react`, `react-dom`
- `Swiper` (CSS/JS)
- `Font Awesome` (`all.min.css`, `v4-shims.min.css`)
- `Ace Editor` (`ace.min.js`, `ext-language_tools.js`)
- `Google Fonts (Roboto / Vazirmatn)` + فونت‌های محلی
- `dashicons` (CSS + fonts)
- `eicons` (Elementor Icons)

### 3) Strict External Asset Blocking

- گزینه `strict_asset_block` به‌صورت پیش‌فرض فعال است.
- هر CSS/JS خارجی بدون نگاشت لوکال، با fallback داخلی جایگزین می‌شود:
	- `assets/js/blocked-asset.js`
	- `assets/css/blocked-asset.css`

### 4) Mixpanel Isolation

مسدودسازی endpointهای رایج Mixpanel:

- `api-eu.mixpanel.com`
- `api.mixpanel.com`
- `cdn.mxpnl.com`
- `api-js.mixpanel.com`

و rewrite اسکریپت به نسخه امن داخلی:

- `assets/js/mixpanel-stub.js`

---

## نصب سریع

1. پوشه پلاگین را در `wp-content/plugins/ghateino` قرار دهید.
2. افزونه را از پنل WordPress فعال کنید.
3. مسیر `Settings -> قطعینو` را باز کنید.
4. تنظیمات را ذخیره کنید.

---

## تنظیمات مهم

- `حالت کاری فایروال`: رفتار کلی فیلتر شبکه
- `لیست سفید / لیست سیاه`: هر دامنه در یک خط
- `جایگزینی CDN با فایل محلی`: فعال برای local-first
- `مسدودسازی Mixpanel`: جلوگیری از ارسال telemetry
- `بلاک سخت‌گیرانه Asset خارجی`: جلوگیری فوری از لود external asset بدون نسخه local
- `لود فونت Vazirmatn در فرانت`: اختیاری و پیش‌فرض خاموش
- `ثبت لاگ درخواست‌ها`: فقط هنگام debugging
- `نگهداری لاگ`: `1`, `3`, `7`, `15`, `30` روز

---

## برای توسعه‌دهنده‌ها

### فیلترها و هوک‌های کلیدی WordPress

- `pre_http_request`
- `script_loader_src`
- `style_loader_src`

### فیلتر توسعه‌پذیری Ghateino

- `ghateino_local_script_rewrite`

نمونه استفاده:

```php
add_filter('ghateino_local_script_rewrite', function ($map) {
		$map['https://cdn.example.com/js/app.min.js'] =
				plugin_dir_url(__FILE__) . 'assets/vendor/custom/app.min.js';
		return $map;
});
```

### ساختار فایل‌های لوکال

- `assets/vendor/wp-core-js/`
- `assets/vendor/swiper/`
- `assets/vendor/fontawesome/css/`
- `assets/vendor/fontawesome/webfonts/`
- `assets/vendor/google-fonts/`
- `assets/vendor/google-fonts/fonts/`
- `assets/vendor/google-fonts/fonts/vazirmatn/`
- `assets/vendor/dashicons/css/`
- `assets/vendor/dashicons/fonts/`
- `assets/vendor/eicons/css/`
- `assets/vendor/eicons/fonts/`
- `assets/js/blocked-asset.js`
- `assets/css/blocked-asset.css`
- `assets/js/mixpanel-stub.js`

---

## نکات سازگاری

- اگر سرویسی باید حتما external بماند:
	- `strict_asset_block` را خاموش کنید
	- یا نگاشت local برای آن سرویس اضافه کنید
- برای عیب‌یابی ابتدا `request logging` را موقت فعال کنید، سپس بعد از تثبیت تنظیمات خاموشش کنید.

---

## نسخه

`1.1.0`

## تیم توسعه

- Shokrino Team
- https://shokrino.com
