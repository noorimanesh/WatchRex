<div align="center">

# 🦖 WatchRex
### Infrastructure & Uptime Monitoring

**توسعه و به‌روزرسانی توسط [فابا پارس (Fabapars)](https://fabapars.com)**

Laravel 13 · PHP 8.3+ · بدون Node/Build · RTL و تقویم شمسی · Multi-tenant

</div>

---

## فهرست

- [WatchRex چیست؟](#watchrex-چیست)
- [قابلیت‌ها](#قابلیتها)
- [معماری و مصرف منابع](#معماری-و-مصرف-منابع)
- [نصب](#نصب)
  - [VPS / سرور اختصاصی (Nginx + PHP-FPM)](#vps--سرور-اختصاصی-nginx--php-fpm)
  - [cPanel / WHM](#cpanel--whm)
  - [DirectAdmin](#directadmin)
  - [Plesk](#plesk)
- [نصب ایجنت سرور](#نصب-ایجنت-سرور)
- [پایش چندموقعیتی (پراب‌ها)](#پایش-چندموقعیتی-پراب‌ها)
- [تیم‌ها](#تیم‌ها)
- [ورود یکپارچه (SSO)](#ورود-یکپارچه-sso)
- [ایجنت ویندوز سرور](#ایجنت-ویندوز-سرور)
- [نقشه وابستگی](#نقشه-وابستگی)
- [فروش پلن و پرداخت (زرین‌پال)](#فروش-پلن-و-پرداخت-زرینپال)
- [تشخیص تغییر محتوا و ظاهر](#تشخیص-تغییر-محتوا-و-ظاهر)
- [مانیتورهای Push برای کران‌جاب](#مانیتورهای-push-برای-کرانجاب)
- [REST API](#rest-api)
- [امنیت](#امنیت)
- [English summary](#english-summary)

---

## WatchRex چیست؟

WatchRex یک پلتفرم سبک پایش زیرساخت است؛ جایگزینی پیشرفته‌تر برای Uptime Kuma که علاوه بر «بالا/پایین بودن»، **دلیل** مشکل را هم تشخیص می‌دهد: DNS کند است؟ PHP-FPM از کار افتاده؟ SSL منقضی شده؟ سرور ایمیل در لیست سیاه است؟ صف ایمیل پر شده؟ رمز یک صندوق ایمیل Brute-force می‌شود؟

هر کاربر فقط مانیتورها، سرورها، دامنه‌ها و صفحات وضعیت **خودش** را می‌بیند؛ مدیر کل همه را می‌بیند و می‌تواند بر اساس کاربر، وضعیت، دسته، نوع، گروه و برچسب فیلتر کند.

## قابلیت‌ها

### انواع مانیتور (۱۹ نوع)
| دسته | انواع |
|---|---|
| وب و API | HTTP(S)، کلمه کلیدی (وجود/عدم وجود)، JSON path، **API چندمرحله‌ای (Synthetic)** با استخراج متغیر (Login → Token → Profile)، WebSocket |
| ایمیل | **SMTP** (بنر، EHLO، STARTTLS، گواهی، تست AUTH، **تست Open Relay**، **بررسی RBL**)، **IMAP** (ورود، تعداد پیام، **سهمیه/فضای صندوق**)، **POP3** (ورود، حجم صندوق) |
| دیتابیس | MySQL/MariaDB (بدون نیاز به رمز: نسخه از handshake؛ با رمز: اتصال‌ها و Uptime)، PostgreSQL، Redis (حافظه، کلاینت‌ها) |
| شبکه | Ping (ICMP با fallback به TCP)، پورت TCP/UDP، DNS (با تشخیص تغییر رکورد)، SSL/TLS هر پورت، FTP/FTPS، SSH/SFTP |
| غیرفعال | Push/Cron (heartbeat)، Server Agent |

### تشخیص هوشمند
- **آبشار درخواست**: DNS / Connect / TLS / پردازش سرور (TTFB) / دانلود
- **علت احتمالی** برای هر خطا: 502 → PHP-FPM، 503/508 → محدودیت LVE در CloudLinux، 403 → ModSecurity/Imunify360، 52x → Cloudflare و …
- **تشخیص ناهنجاری** (EWMA، حافظه O(1)): «زمان پاسخ ۷.۴ برابر حالت عادی است» حتی وقتی سایت هنوز Up است
- **تشخیص فیلترینگ**: ریدایرکت به `10.10.34.x` / peyvandha به‌عنوان «فیلتر شده» گزارش می‌شود
- **وابستگی‌ها (Dependency Map)**: اگر دیتابیس Down باشد، هشدار وب‌سایت‌های وابسته سرکوب و علت اعلام می‌شود
- Retries، آستانه زمان پاسخ، یادآوری دوره‌ای در زمان قطعی

### دامنه و SSL
انقضای دامنه (RDAP با fallback به WHOIS و پشتیبانی `.ir`)، ثبت‌کننده، نیم‌سرورها، همه رکوردهای DNS و **هشدار تغییر DNS با diff**، گواهی SSL (صادرکننده، SAN، پروتکل، اثرانگشت، تشخیص تغییر گواهی)، **کشف زیردامنه‌ها** (Certificate Transparency + DNS) با وضعیت SSL هرکدام و دکمه «پایش» یک‌کلیکی، **امتیاز امنیت ایمیل** (SPF، DKIM، DMARC، MTA-STS، TLS-RPT، BIMI، PTR)، IP و **موقعیت جغرافیایی/ASN**، تاریخچه IP هاست، **«آنلاین از»** (اولین نسخه Wayback)، و بررسی **لیست‌های سیاه**.

### ایجنت سرور (bash خالص، بدون وابستگی)
CPU، RAM، Swap، IO-wait، Load، دیسک‌ها و inode، شبکه، دما، سرویس‌ها (Nginx/Apache/LiteSpeed/Exim/Postfix/Dovecot/MySQL/PHP-FPM/CSF/Imunify…)، **کانتینرهای Docker** (وضعیت، CPU، RAM، ری‌استارت، health)، پردازش‌های پرمصرف و برای سرورهای ایمیل:
- **صف ایمیل** (Exim/Postfix)
- ارسال‌شده، دریافتی، **Bounce، Defer، Reject**، آخرین برگشتی‌ها
- **ورودهای موفق/ناموفق IMAP/POP3/SMTP-AUTH**، IPها و حساب‌های با بیشترین ورود ناموفق
- تلاش‌های ناموفق SSH
- **حساب‌های cPanel / DirectAdmin / Plesk با فضای مصرفی/باقی‌مانده و وضعیت تعلیق**

### هشدار
ایمیل، **تلگرام** (با API base قابل تنظیم برای ایران)، **بله**، **پیامک کاوه‌نگار**، WhatsApp Cloud API، Slack، Discord، Microsoft Teams، ntfy (Push)، Webhook با امضای HMAC. هشدارها به زبان هر کاربر ارسال می‌شوند.

### آپ‌تایم و گزارش
آپ‌تایم ۲۴ ساعت، ۷، ۳۰، ۹۰ و ۳۶۵ روز؛ نمودار زمان پاسخ (1h/24h/7d/30d) با میانگین/کمینه/بیشینه/P95؛ نوار ۹۰ روزه؛ رخدادها با خط زمانی، Acknowledge و یادداشت؛ Badge‌های SVG.

### صفحات وضعیت عمومی
دامنه اختصاصی (`status.company.com`)، لوگو و رنگ برند، White-label، نمایش نگهداری‌های زمان‌بندی‌شده و رخدادها، **JSON API و RSS** و **عضویت ایمیلی بازدیدکنندگان** (تأیید دومرحله‌ای، اعلان شروع/رفع رخداد و به‌روزرسانی‌های عمومی، لغو عضویت یک‌کلیکی).

### پایش چندموقعیتی
هر مانیتور می‌تواند هم‌زمان از این سرور و چند **پراب** (ایران، آلمان، هلند، …) بررسی شود. اگر فقط از یک موقعیت قطع باشد، به جای Down با پیام «احتمالاً مشکل شبکه، ISP یا جغرافیایی/فیلترینگ» به‌عنوان افت کیفیت گزارش می‌شود. حد نصاب قابل تنظیم است (هر موقعیت / اکثریت / همه).

### تیم‌ها و SSO
تیم با نقش‌های مالک، ادمین، توسعه‌دهنده و بیننده؛ هر منبع می‌تواند خصوصی بماند یا با یک تیم به اشتراک گذاشته شود. ورود یکپارچه OpenID Connect (Keycloak، Microsoft Entra ID، Google Workspace، Authentik، Okta).

### تشخیص تغییر (Defacement)
مقایسه متن قابل‌مشاهده صفحه در هر بررسی با diff خط‌به‌خط و آستانه درصدی و الگوی نادیده‌گیری؛ و به‌صورت اختیاری **اسکرین‌شات با Chrome بدون رابط** و مقایسه پیکسلی.

### چندکاربره و تجاری
نقش‌ها (مدیر / کاربر / بیننده فقط‌خواندنی)، پلن‌ها (Free / Pro / Business / Enterprise) با سقف مانیتور، حداقل بازه، سرور، دامنه و صفحه وضعیت — قابل override برای هر کاربر.

## معماری و مصرف منابع

```
schedule:run (هر دقیقه)
 └─ watchrex:dispatch  (هر ۱۰ ثانیه)  → صف checks → MonitorRunner → heartbeats + daily_stats + incidents → صف alerts
 └─ watchrex:domains   (هر ۳۰ دقیقه)  → RDAP/WHOIS، DNS، SSL، زیردامنه، RBL
 └─ watchrex:prune     (روزانه)       → پاک‌سازی بر اساس Retention
Agent (bash) ── POST /api/agent/report
Cron/Script  ── GET  /api/push/{token}
```

چرا سبک است:
- **بدون Node، بدون Build، بدون فریم‌ورک JS** — یک فایل CSS و یک فایل JS کوچک؛ نمودارها **SVG سمت سرور** هستند.
- آپ‌تایم‌های بلندمدت از جدول تجمیعی روزانه خوانده می‌شوند (آپ‌تایم یک‌ساله = ~۳۶۵ ردیف، نه میلیون‌ها heartbeat).
- جزئیات کامل فقط برای heartbeatهای خطا ذخیره می‌شود.
- تشخیص ناهنجاری با EWMA بدون نگهداری تاریخچه.
- ایجنت لاگ‌ها را **افزایشی** (با offset) می‌خواند؛ هر اجرا چند میلی‌ثانیه CPU.
- با SQLite روی هاست اشتراکی هم اجرا می‌شود.

## نصب

پیش‌نیاز: PHP **8.3+** (Laravel 13 حداقل 8.3 می‌خواهد) با افزونه‌های `curl`، `openssl`، `pdo_*`، `mbstring`، `intl` و Composer.

```bash
git clone <repo> watchrex && cd watchrex
composer install --no-dev --optimize-autoloader
cp .env.example .env && php artisan key:generate
# برای SQLite:
touch database/database.sqlite
php artisan migrate --force
php artisan watchrex:install          # ساخت مدیر کل
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

دو چیز باید همیشه اجرا شوند:

```cron
* * * * * cd /path/to/watchrex && php artisan schedule:run >> /dev/null 2>&1
```
```bash
php artisan queue:work --queue=checks,alerts,default --sleep=1 --max-time=3600
```

> روی هاست اشتراکی بدون Supervisor می‌توانید `QUEUE_CONNECTION=sync` بگذارید تا بررسی‌ها داخل همان کران اجرا شوند (برای تعداد کم مانیتور).

### VPS / سرور اختصاصی (Nginx + PHP-FPM)

`/etc/nginx/sites-available/watchrex.conf`:
```nginx
server {
    listen 443 ssl http2;
    server_name watch.example.com;
    root /var/www/watchrex/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/watch.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/watch.example.com/privkey.pem;

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }
    location ~ /\.(?!well-known) { deny all; }
}
```
دسترسی‌ها:
```bash
chown -R www-data:www-data storage bootstrap/cache database
chmod -R 775 storage bootstrap/cache
```
Supervisor (`/etc/supervisor/conf.d/watchrex.conf`):
```ini
[program:watchrex-worker]
command=php /var/www/watchrex/artisan queue:work --queue=checks,alerts,default --sleep=1 --max-time=3600
numprocs=2
process_name=%(program_name)s_%(process_num)02d
user=www-data
autostart=true
autorestart=true
stopwaitsecs=120
```
هر ۲ پراسس worker حدود ۶۰ بررسی در دقیقه را راحت پوشش می‌دهند؛ برای مانیتورهای بیشتر `numprocs` را افزایش دهید.

### cPanel / WHM
1. در cPanel یک ساب‌دامنه (مثلاً `watch.example.com`) بسازید و **Document Root** را روی `watchrex/public` بگذارید (نه `public_html`).
2. **MultiPHP Manager** → PHP 8.3 یا بالاتر. در **Select PHP Version** (CloudLinux) افزونه‌های `intl`، `pdo_sqlite`/`pdo_mysql` را فعال کنید.
3. از **Terminal** مراحل نصب بالا را اجرا کنید (از `ea-php83` استفاده کنید: `/opt/cpanel/ea-php83/root/usr/bin/php artisan …`).
4. **Cron Jobs** → هر دقیقه: `/opt/cpanel/ea-php83/root/usr/bin/php /home/USER/watchrex/artisan schedule:run >/dev/null 2>&1`
5. بدون Supervisor: یا `QUEUE_CONNECTION=sync`، یا یک کران اضافه هر دقیقه: `… artisan queue:work --stop-when-empty --max-time=55`
6. SSL را از **SSL/TLS Status → Run AutoSSL** بگیرید.

### DirectAdmin
1. **Domain Setup** → دامنه/ساب‌دامنه؛ سپس در **Custom HTTPD Configuration** (یا از طریق SSH) `public_html` را به `watchrex/public` symlink کنید: `rm -rf public_html && ln -s watchrex/public public_html`.
2. نسخه PHP را در **PHP Version Selector** روی 8.3+ بگذارید.
3. **Cron Jobs** در پنل کاربر: `* * * * * /usr/local/php83/bin/php /home/USER/domains/DOMAIN/watchrex/artisan schedule:run`
4. SSL: **SSL Certificates → Let's Encrypt**.

### Plesk
1. **Hosting Settings** → Document root: `watchrex/public`، PHP 8.3+ (FPM served by nginx).
2. در **Apache & nginx Settings** گزینه «Proxy mode» را بگذارید و در «Additional nginx directives»: `location / { try_files $uri $uri/ /index.php?$query_string; }`
3. **Scheduled Tasks** → Run a PHP script: `watchrex/artisan` با آرگومان `schedule:run` هر دقیقه.
4. برای Worker می‌توانید از افزونه **Laravel Toolkit** در Plesk استفاده کنید (بخش Queue).
5. SSL: **SSL/TLS Certificates → Let's Encrypt**.

## نصب ایجنت سرور
در WatchRex: **سرورها → افزودن سرور**. یک دستور یک‌خطی دریافت می‌کنید (توکن فقط یک بار نمایش داده می‌شود و هش‌شده ذخیره می‌شود):

```bash
curl -fsSL https://watch.example.com/agent/install.sh | sudo bash -s -- wrx_XXXXXXXX 60
```
- روی cPanel/WHM، DirectAdmin، Plesk، CyberPanel، aaPanel، Ubuntu، Debian، AlmaLinux، Rocky، CentOS کار می‌کند.
- systemd timer (یا cron) نصب می‌کند؛ پیکربندی در `/etc/watchrex/agent.conf` با مجوز `600`.
- حذف: `systemctl disable --now watchrex-agent.timer; rm -rf /opt/watchrex /etc/watchrex /var/lib/watchrex /etc/systemd/system/watchrex-agent.*`
- اگر CSF دارید، آدرس WatchRex را در خروجی (Outgoing) مجاز کنید.

## پایش چندموقعیتی (پراب‌ها)
1. در WatchRex: **مدیریت → موقعیت‌های پایش → افزودن پراب** (مثلاً `de-falkenstein`, کد کشور `DE`). توکن فقط یک بار نمایش داده می‌شود.
2. روی سرور مقصد، همین کد را مستقر کنید (`composer install --no-dev`) — **دیتابیس لازم نیست**. در `.env`:
   ```env
   APP_KEY=base64:…            # php artisan key:generate
   WATCHREX_HUB_URL=https://watch.example.com
   WATCHREX_PROBE_TOKEN=wrx_probe_…
   WATCHREX_PROBE_CONCURRENCY=4
   CACHE_STORE=file
   QUEUE_CONNECTION=sync
   ```
3. به‌صورت سرویس اجرا کنید:
   ```ini
   # /etc/systemd/system/watchrex-probe.service
   [Service]
   User=www-data
   WorkingDirectory=/opt/watchrex
   ExecStart=/usr/bin/php artisan watchrex:probe
   Restart=always
   [Install]
   WantedBy=multi-user.target
   ```
4. در فرم مانیتور، بخش «موقعیت‌های بررسی» پراب‌ها و حد نصاب را انتخاب کنید.

> پراب هر ۶۰ ثانیه فهرست کار را از هاب می‌گیرد، بررسی‌ها را (با `pcntl` به‌صورت موازی) اجرا می‌کند و نتایج را برمی‌گرداند. اطلاعات ورود مانیتورها از طریق HTTPS برای پراب ارسال می‌شود؛ پراب‌ها را فقط روی سرورهای مورد اعتماد اجرا کنید.

## تیم‌ها
**تیم‌ها → تیم جدید**، سپس اعضا را با ایمیل (کاربران موجود) اضافه کنید. در فرم هر مانیتور، سرور، دامنه، کانال هشدار، صفحه وضعیت یا بازه نگهداری گزینه «اشتراک با تیم» وجود دارد. کانال‌های پیش‌فرض تیم برای منابع تیم هم هشدار می‌گیرند.

| نقش | مشاهده | ایجاد/ویرایش منابع تیم | مدیریت اعضا |
|---|---|---|---|
| مالک / ادمین | ✓ | ✓ | ✓ |
| توسعه‌دهنده | ✓ | ✓ | — |
| بیننده | ✓ | — | — |

## ورود یکپارچه (SSO)
```env
OIDC_ENABLED=true
OIDC_LABEL="Fabapars SSO"
OIDC_ISSUER=https://sso.example.com/realms/fabapars   # آدرس issuer (discovery خودکار)
OIDC_CLIENT_ID=watchrex
OIDC_CLIENT_SECRET=…
OIDC_AUTO_CREATE=true            # ساخت خودکار کاربر در اولین ورود
OIDC_ALLOWED_DOMAINS=fabapars.com
OIDC_DISABLE_PASSWORD_LOGIN=true # فقط مدیران کل با رمز وارد می‌شوند (break-glass)
```
Redirect URI در IdP: `https://watch.example.com/auth/sso/callback`. جریان Authorization Code با PKCE، و state/nonce/iss/aud/exp اعتبارسنجی می‌شوند.

## تشخیص تغییر محتوا و ظاهر
در فرم مانیتور HTTP: «هشدار هنگام تغییر متن قابل‌مشاهده صفحه»، آستانه درصد و regex برای نادیده گرفتن بخش‌های پویا (ساعت، شمارنده بازدید). برای مقایسه تصویری:
```env
WATCHREX_CHROME_PATH=/usr/bin/chromium        # apt install chromium
WATCHREX_SCREENSHOTS_FOR_TENANTS=false        # پیش‌فرض فقط برای مدیران (مرورگر به منابع داخلی دسترسی دارد)
```

## ایجنت ویندوز سرور
در صفحه سرور، زبانه **Windows Server** را انتخاب کنید و دستور را در PowerShell با دسترسی Administrator اجرا کنید:
```powershell
iwr -UseBasicParsing https://watch.example.com/agent/install.ps1 -OutFile $env:TEMP\wrx.ps1; & $env:TEMP\wrx.ps1 -Token wrx_XXXX
```
- سازگار با Windows Server 2016/2019/2022/2025 (PowerShell 5.1، بدون ماژول اضافه) و ویندوز با هر زبانی (از کلاس‌های CIM غیرمحلی استفاده می‌کند).
- CPU، RAM، Page file، دیسک‌ها، شبکه، پردازش‌های پرمصرف، سرویس‌ها (IIS، SQL Server، MySQL، Exchange، hMailServer، Plesk، RDP…)، **سایت‌ها و Application Poolهای IIS**، صف Exchange / IIS SMTP.
- **ورودهای ناموفق ویندوز** (Event 4625) با IP مهاجم و حساب‌های هدف، و ورودهای RDP موفق.
- به‌صورت Scheduled Task با حساب SYSTEM هر دقیقه اجرا می‌شود؛ فایل تنظیمات فقط برای SYSTEM و Administrators قابل خواندن است. حذف: `& $env:TEMP\wrx.ps1 -Uninstall`

## نقشه وابستگی
منوی **نقشه وابستگی** همه وابستگی‌ها را به‌صورت درخت نمایش می‌دهد (مثلاً سرور ← MySQL ← وب‌سایت). وقتی والدی قطع شود، لینک‌ها قرمز و متحرک می‌شوند، سرویس‌های وابسته «تحت تأثیر» علامت می‌خورند و بنر «MySQL قطع است و روی ۴ سرویس اثر گذاشته» نمایش داده می‌شود. وابستگی چرخشی مجاز نیست.

## فروش پلن و پرداخت (زرین‌پال)
```env
WATCHREX_BILLING=true
ZARINPAL_MERCHANT_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
ZARINPAL_SANDBOX=false
WATCHREX_PRICE_PRO=290000          # تومان در ماه
WATCHREX_PRICE_BUSINESS=1490000
WATCHREX_VAT_PERCENT=10
WATCHREX_SELLER_NAME="فابا پارس"
WATCHREX_SELLER_NATIONAL_ID=…
WATCHREX_SELLER_ECONOMIC_CODE=…
```
- کاربران از **پلن و صورت‌حساب** پلن را ماهانه یا سالانه (۱۰ ماه هزینه برای ۱۲ ماه) می‌خرند؛ مالیات بر ارزش افزوده اضافه و فاکتور قابل چاپ/PDF صادر می‌شود.
- مبلغ فقط سمت سرور محاسبه می‌شود، تأیید تراکنش سرور-به-سرور و **یک‌بار مصرف** است (قفل ردیف؛ callback تکراری پلن را دوبار تمدید نمی‌کند).
- تمدید از تاریخ انقضای فعلی ادامه می‌یابد؛ ارتقا از همان لحظه شروع می‌شود.
- ۷ روز و ۱ روز قبل از انقضا یادآوری ارسال می‌شود؛ پس از مهلت (`WATCHREX_BILLING_GRACE_DAYS`) حساب به پلن رایگان منتقل و مانیتورهای مازاد متوقف می‌شوند (`php artisan watchrex:plans` — روزانه در زمان‌بند).
- مدیر کل در **مدیریت → صورت‌حساب‌ها** درآمد، مالیات، سفارش‌ها و پرداخت‌های کارت‌به‌کارت (ثبت دستی با شماره پیگیری) را مدیریت می‌کند.

## مانیتورهای Push برای کران‌جاب
```bash
# بکاپ شبانه — اگر تا بازه + مهلت خبری نشود، هشدار
0 2 * * * /root/backup.sh && curl -fsS -m 10 "https://watch.example.com/api/push/<token>?status=up&msg=OK" >/dev/null || curl -fsS "https://watch.example.com/api/push/<token>?status=down&msg=backup+failed"
```

## REST API
در **پروفایل → توکن‌های API** یک توکن بسازید (فقط خواندنی یا با مجوز نوشتن، با تاریخ انقضا).
```bash
curl -H "Authorization: Bearer wrx_api_…" https://watch.example.com/api/v1/summary
```
| متد | مسیر |
|---|---|
| GET | `/api/v1/summary` · `/api/v1/monitors` · `/api/v1/monitors/{id}` · `/api/v1/monitors/{id}/heartbeats?limit=100` · `/api/v1/incidents` · `/api/v1/servers` |
| POST | `/api/v1/monitors/{id}/toggle` (`active=true|false`، نیاز به توکن نوشتنی) |
| عمومی | `/status/{slug}/json` · `/status/{slug}/rss` · `/badge/{uuid}/status.svg|uptime.svg|response.svg` |

## امنیت
- ورود دومرحله‌ای **TOTP** با QR و کدهای بازیابی یک‌بارمصرف؛ جلوگیری از Replay کد
- محدودیت نرخ ورود (۵ تلاش / ۵ دقیقه به ازای ایمیل+IP)، پاسخ ثابت‌زمان برای جلوگیری از کشف ایمیل
- اطلاعات ورود مانیتورها و تنظیمات کانال‌ها **رمزنگاری‌شده** در دیتابیس (`encrypted` cast با APP_KEY)
- توکن‌های ایجنت و API فقط به‌صورت **SHA-256** ذخیره می‌شوند
- **محافظت SSRF**: کاربران غیرمدیر نمی‌توانند شبکه‌های داخلی/metadata را پایش کنند؛ IP ترجمه‌شده pin می‌شود و هر ریدایرکت دوباره اعتبارسنجی می‌شود
- هدرهای امنیتی (CSP بدون inline script، HSTS، X-Frame-Options، Referrer-Policy، Permissions-Policy)
- جداسازی کامل داده بین کاربران، نقش فقط‌خواندنی، گزارش ممیزی از ورودها و تمام تغییرات
- خروج از سایر نشست‌ها پس از تغییر رمز؛ نشست‌های رمزنگاری‌شده

## دستورات
| دستور | کاربرد |
|---|---|
| `php artisan watchrex:install` | ساخت/به‌روزرسانی مدیر کل |
| `php artisan watchrex:check {id} [--dry]` | اجرای یک بررسی و نمایش خروجی کامل تشخیص |
| `php artisan watchrex:dispatch [--sync]` | ارسال بررسی‌های سررسید به صف |
| `php artisan watchrex:domains [--all]` | بروزرسانی اطلاعات دامنه‌ها |
| `php artisan watchrex:prune` | پاک‌سازی داده‌های قدیمی |
| `php artisan watchrex:probe [--once]` | اجرا به‌عنوان پراب راه دور |
| `php artisan watchrex:plans` | یادآوری تمدید و اعمال انقضای پلن‌ها |

## نقشه راه
- درگاه‌های پرداخت بیشتر (IDPay، زیبال، Stripe) — رابط `PaymentGateway` آماده است
- SAML 2.0 در کنار OIDC
- اپلیکیشن موبایل / Push notification بومی
- گزارش SLA ماهانه PDF برای مشتریان

---

## English summary

**WatchRex** is a lightweight, multi-tenant infrastructure & uptime monitoring platform built on **Laravel 13 / PHP 8.3+** by [Fabapars](https://fabapars.com). It goes beyond up/down: it explains *why* something is failing.

- **19 monitor types**: HTTP(S), keyword, JSON assertions, multi-step synthetic API flows, WebSocket, SMTP (STARTTLS, AUTH test, open-relay test, RBL), IMAP (login, message count, quota), POP3, MySQL/MariaDB, PostgreSQL, Redis, Ping, TCP/UDP, DNS (change detection), SSL on any port, FTP, SSH, push/cron heartbeats and server agents.
- **Smart diagnosis**: request waterfall (DNS/connect/TLS/server/download), probable-cause hints (PHP-FPM, CloudLinux LVE, WAF, Cloudflare…), EWMA anomaly detection, dependency-aware alert suppression, national-filtering detection.
- **Domain intelligence**: expiry (RDAP/WHOIS incl. `.ir`), DNS records with diffed change alerts, SSL details, subdomain discovery (CT logs + DNS), SPF/DKIM/DMARC/MTA-STS score, hosting IP + geo/ASN, IP history, blacklist checks.
- **Dependency-free bash agent**: CPU/RAM/disk/load/network/services/Docker plus mail queue, sent/bounced/deferred/rejected mail, IMAP/POP3/SMTP login success & failures (top IPs/users) and cPanel/DirectAdmin/Plesk account disk usage.
- **Alerts**: e-mail, Telegram, Bale, Kavenegar SMS, WhatsApp, Slack, Discord, Teams, ntfy, signed webhooks — sent in each user's language.
- **Status pages** with custom domains, branding, white-label, JSON, RSS and double opt-in e-mail subscriptions; SVG badges; REST API with scoped tokens.
- **Multi-location probes**: `php artisan watchrex:probe` on any server (no database) pulls jobs from the hub; quorum-based evaluation turns single-location failures into "network/ISP/geo-specific" warnings.
- **Teams** (owner/admin/developer/viewer) with per-resource sharing, and **OpenID Connect SSO** (PKCE, state/nonce, domain allow-list, optional auto-provisioning).
- **Windows Server agent** (PowerShell 5.1): system metrics, IIS sites/app pools, SQL/Exchange services and queues, failed/RDP logons from the Security log.
- **Dependency map**: server-rendered SVG tree with impact analysis and cycle protection.
- **SaaS billing**: Zarinpal (v4) checkout, VAT, printable invoices, renewals, reminders and automatic downgrade on expiry; manual bank-transfer activation.
- **CI**: GitHub Actions for tests (PHP 8.3/8.4), Pint, ShellCheck, PowerShell parsing and Blade compilation.
- **Change detection**: visible-text diffing on every check plus optional headless-Chrome screenshots with pixel comparison.
- **Security**: TOTP 2FA, rate limiting, encrypted credentials, hashed tokens, SSRF guard, strict CSP, audit log, read-only viewer role.
- **Lightweight**: no Node/build step, server-rendered SVG charts, daily aggregates for long-range uptime, SQLite-friendly.

Install: see the steps above (`composer install`, `migrate`, `watchrex:install`, a `schedule:run` cron entry and a `queue:work` worker).

<div align="center"><sub>© Fabapars — WatchRex · Infrastructure & Uptime Monitoring</sub></div>
