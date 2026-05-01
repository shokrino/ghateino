# Ghateino (قطعینو)

`Ghateino` یک افزونه وردپرس برای مدیریت درخواست های خارجی و کاهش وابستگی به CDN در شرایط اینترنت ناپایدار است.

رویکرد افزونه:

`Local First + Block Fast`

- اگر asset خارجی نگاشت محلی داشته باشد، سریع به نسخه محلی rewrite می شود.
- اگر نگاشت محلی وجود نداشته باشد و حالت سخت گیرانه فعال باشد، درخواست خارجی سریع بلاک می شود.

## مزیت اصلی

در شرایط اختلال اینترنت، Ghateino باعث می شود:

- زمان انتظار برای requestهای خارجی کمتر شود.
- رفتار سایت قابل پیش بینی تر باشد.
- وابستگی به CDNهای خارجی کاهش یابد.
- مسیر عیب یابی از طریق لاگ ها ساده تر شود.

## قابلیت ها

### 1) کنترل HTTP خروجی

سه حالت کاری:

- `disabled`: بدون محدودیت
- `whitelist`: فقط دامنه های مجاز
- `blacklist`: مسدودسازی دامنه های مشخص

امکانات تکمیلی:

- محدودسازی timeout درخواست های خارجی
- غیرفعال سازی telemetry وردپرس (`wordpress.org`)
- غیرفعال سازی بررسی آپدیت هسته/قالب/افزونه
- امکان استثنا برای آپدیت از دامنه های whitelist
- جایگزینی Gravatar با تصویر محلی
- لاگ گیری با retention و سقف تعداد رکورد

### 2) بازنویسی assetهای خارجی به نسخه محلی

موارد پشتیبانی شده:

- WP Core JS: `jquery`, `jquery-migrate`, `underscore`, `backbone`, `react`, `react-dom`
- `Swiper` (CSS/JS)
- `Ace Editor` (`ace.min.js`, `ext-language_tools.js`)
- `Font Awesome` (`all.min.css`, `v4-shims.min.css`)
- `Google Fonts` (Roboto / Vazirmatn)
- `dashicons` (CSS + fonts)
- `eicons` (Elementor Icons)

### 3) بلاک سخت گیرانه asset خارجی

اگر `strict_asset_block` فعال باشد، هر CSS/JS خارجی بدون نگاشت محلی با fallback داخلی جایگزین می شود:

- `assets/js/blocked-asset.js`
- `assets/css/blocked-asset.css`

### 4) ایزوله سازی Mixpanel

دامنه های رایج Mixpanel بلاک می شوند:

- `api-eu.mixpanel.com`
- `api.mixpanel.com`
- `cdn.mxpnl.com`
- `api-js.mixpanel.com`

و script با نسخه امن داخلی جایگزین می شود:

- `assets/js/mixpanel-stub.js`

### 5) بهینه سازی جدید عملکرد (نسخه فعلی)

- آماده سازی assetهای محلی فقط یک بار به ازای هر نسخه افزونه انجام می شود.
- اسکن بازگشتی سنگین کل `wp-content` برای پیدا کردن فایل ها حذف شده است.

نتیجه: کاهش overhead در runtime و پایداری بهتر روی هاست های shared یا کند.

## نصب

1. پوشه افزونه را در `wp-content/plugins/ghateino` قرار دهید.
2. افزونه را از پنل وردپرس فعال کنید.
3. مسیر `Settings -> قطعینو` را باز کنید.
4. تنظیمات را ذخیره کنید.

## راهنمای تنظیمات پیشنهادی

### سناریوی عمومی (ایمن)

- `mode = disabled`
- `local_asset_rewrite = yes`
- `strict_asset_block = yes`
- `enable_timeout_guard = yes`
- `max_request_timeout = 3`

### سناریوی اینترنت محدود

- `mode = whitelist`
- دامنه های ضروری را در whitelist وارد کنید.
- در صورت نیاز `allow_whitelisted_updates = yes` را فعال نگه دارید.

### سناریوی عیب یابی

- موقت `enable_logging = yes`
- در صورت نیاز `log_asset_events = yes`
- پس از تثبیت تنظیمات، لاگ گیری را محدود یا غیرفعال کنید.

## توسعه دهنده ها

### هوک های کلیدی وردپرس

- `pre_http_request`
- `http_request_args`
- `script_loader_src`
- `style_loader_src`

### فیلتر توسعه پذیری Ghateino

- `ghateino_local_script_rewrite`

امضای فیلتر:

```php
apply_filters( 'ghateino_local_script_rewrite', '', $path, $original_src );
```

نمونه صحیح:

```php
add_filter('ghateino_local_script_rewrite', function ($replacement, $path, $original_src) {
    if (strpos($path, '/custom-lib.min.js') !== false) {
        return plugin_dir_url(__FILE__) . 'assets/vendor/custom/custom-lib.min.js';
    }

    return $replacement;
}, 10, 3);
```

## نکات سازگاری

- اگر asset خاصی باید external باقی بماند، `strict_asset_block` را خاموش کنید یا برای آن نگاشت محلی اضافه کنید.
- اگر Customizer استفاده می شود، بازنویسی asset در context مربوط به customizer bypass می شود.
- برای سایت های پربازدید، روشن نگه داشتن دائمی `log_asset_events` توصیه نمی شود.

## ساختار مسیرهای مهم

- `ghateino.php`
- `assets/js/blocked-asset.js`
- `assets/js/mixpanel-stub.js`
- `assets/css/blocked-asset.css`
- `assets/vendor/wp-core-js/`
- `assets/vendor/swiper/`
- `assets/vendor/ace-builds/`
- `assets/vendor/fontawesome/`
- `assets/vendor/google-fonts/`
- `assets/vendor/dashicons/`
- `assets/vendor/eicons/`

## نسخه

`1.2.0`

## توسعه دهنده

- Shokrino Team
- https://shokrino.com
