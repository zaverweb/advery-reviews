# Advery Reviews — معماری

## هدف
یک پلاگین سریع و خودبسنده‌ی امتیاز و نظر برای هر پست‌تایپ، ترمِ تکسونومی، یا محصول ووکامرس. کاملاً مستقل؛ و وقتی **Advery Schema Plus ≥ 2.21.0** فعال باشد، `aggregateRating`/`review` را هم به گرافِ JSON-LD تزریق می‌کند.

## اصول طراحی
- **ذخیره‌سازیِ بهینه‌ی خودمان**، نه کامنت‌های وردپرس. دو جدولِ هدف‌ساخته و ایندکس‌شده، خواندنِ خلاصه و خروجیِ اسکیما را به یک ردیفِ ایندکس‌شده می‌رسانند.
- **اول مستقل، بعد یکپارچه.** وابستگیِ سخت به هسته نیست؛ پلِ اسکیما بدون هسته بی‌اثر است.
- **همه‌چیز قابل‌تنظیم، با پیش‌فرض‌های درست.** مالک سایت هر قاعده را می‌تواند تنظیم کند؛ پیش‌فرض‌ها از همان ابتدا رفتار خوب دارند.
- **سازگاری:** وردپرس ۶ و ۷ (تست تا ۷.۱)، PHP نسخه‌ی ۷.۴ به بالا. بدون وابستگیِ سختِ زمانِ اجرا به Composer، React در فرانت، یا مرحله‌ی build (پوشه‌ی `build/` ادمین کامیت شده است).

## مدل داده (v0.1.0)
- `{prefix}advery_reviews` — یک ردیف به‌ازای هر نظر: `id, object_type(post|term|product), object_id, rating(0–5), author_name, author_email, author_user_id, title, content, status(pending|approved|spam|trash), author_ip, created_at`. ایندکس‌ها: `(object_type,object_id,status)`, `status`, `created_at`, `author_user_id`.
- `{prefix}advery_review_stats` — کشِ aggregate به‌ازای هر آبجکت: `object_type, object_id (PK), review_count, rating_count, rating_sum, rating_avg, updated_at`. با هر تغییرِ وضعیت از نظراتِ تأییدشده بازمحاسبه می‌شود → خواندنِ O(1).

### افزوده‌های plan-شده‌ی اسکیما (نقشه‌راه، `Installer::DB_VERSION` را بالا می‌برد)
- `origin` (`user|import|ai|sample`) — منشأِ داخلی، هرگز عمومی نمی‌شود.
- `external_source` (مثلاً `google`, `csv`) + `external_id` — برای ایمپورت/upsertِ idempotent و حذف تکراری.
- `spam_score` (int) + `meta` (JSON) — امتیازدهیِ ضداسپم و اطلاعاتِ ساختاریِ اضافی به‌ازای هر نظر (مثل پاسخِ AI یا payloadِ ایمپورت).
- `parent_id` اختیاری — برای پاسخِ مالک/AI به‌صورت تودرتو زیر یک نظر.

## زیرسیستم‌ها (v0.1.0)
- **Database\\**: `Installer` (جدول‌ها + ارتقای نسخه‌دار)، `ReviewRepository` (CRUD/کوئری/مودریشن)، `StatsRepository` (کش aggregate).
- **Support\\**: `Settings` (یک option)، `Targets` (تشخیص/اعتبارسنجیِ هدفِ جاری + برچسب/لینک)، `Aggregate` (خودمان + نیتیوِ وو به‌صورت اختیاری).
- **Frontend\\Display**: شورت‌کد `[advery_reviews]` + auto-append اختیاری؛ ستاره‌گذارِ vanilla-JS + ثبت از راه REST (بدون فریم‌ورک فرانت).
- **Rest\\RestController**: عمومیِ `/submit` (nonce)، `/list`؛ ادمینِ bootstrap/reviews/status/bulk/settings.
- **Admin\\**: `AdminPage` (پنل React + حبابِ شمارِ در انتظار)، `DashboardWidget`.
- **Email\\**: `Notifier` (فوری)، `Digest` (WP-Cron هفتگی/ماهانه).
- **Schema\\SchemaBridge**: به فیلترِ `advery_schema_render_node` هسته هوک می‌زند؛ `aggregateRating`/`review` را به نودِ آیتم می‌چسباند (تطبیق با idِ پستِ لیستینگ دایرکتوری یا `url` نود == پرمالینکِ آیتم). اسکیمای محصولِ وو به خودِ وو واگذار می‌شود.
- **Integrations\\WooCommerce**: خواندنِ فقط‌خواندنیِ ریتینگِ نیتیو.

## زیرسیستم‌های افزوده در v0.2.0
- **AntiSpam\\** (منتشرشده): `SpamGuard` (لایه‌ای و امتیازمحور؛ هانی‌پات، توکنِ امضاشده‌ی زمان‌سنجی، قواعد لینک/کلمه/طول، بلاک‌لیست، محدودیتِ نرخ، ایمیلِ یکبارمصرف، تکراری، آستانه‌ها → reject/spam/hold/approve)، `CaptchaVerifier` (reCAPTCHA v2/v3، hCaptcha، Turnstile)، `DisposableDomains`، `Akismet` (سیگنالِ اختیاری). جدولِ نظرات ستون‌های `spam_score` + `meta` گرفت (DB 1.1.0).

## زیرسیستم‌های planned (نقشه‌راه — features_and_ideas.md را ببینید)
- **Integrations\\Elementor**: ویجتِ نیتیوِ المنتور که همان `Display` را رندر می‌کند، با گاردِ حذفِ تکرارِ auto-append.
- **AI\\**: کلاینتِ مستقل‌از‌ارائه‌دهنده (`ProviderInterface` + آداپترها)، یک `TaskRunner` با فعال‌سازیِ per-task و prompt/model/temperature/محدودیت، فقط برای کارهای **مشروع** (کمکِ مودریشن، پیش‌نویسِ پاسخِ مالک، ترجمه/خلاصه). سقفِ هزینه/نرخ + لاگِ حسابرسی.
- **Sample\\** (محتوای دمو، مشروط به انطباق): تولیدکننده‌ی نظرِ نمونه/دمو با برچسبِ واضح، فقط برای محیطِ **staging/دمو** — بخشِ انطباق در features_and_ideas.md را ببینید. ردیف‌های نمونه `origin='sample'` دارند و به‌طور پیش‌فرض از اسکیمای عمومی حذف‌اند.
- **ImportExport\\**: ایمپورتِ CSV/JSON با نگاشتِ ستون و upsertِ idempotent بر پایه‌ی فیلدهای یکتای قابل‌تنظیم (`post id` + `external_id`)، با skip/update در اجرای بعدی؛ اکسپورتِ CSV/JSON. نظراتِ گوگل فقط از راه **API رسمیِ Google Business Profile** برای مکان‌های خودِ مالک.

## توسعه‌پذیری (هوک‌هایی که پلاگین می‌دهد)
- `advery_reviews_created` (اکشن، idِ نظر) — همین حالا توسط ایمیل استفاده می‌شود؛ زیرسیستم‌های AI/پاسخ/ضداسپم هم به آن هوک می‌زنند.
- فیلترهای planned: `advery_reviews_spam_score`, `advery_reviews_before_insert`, `advery_reviews_ai_task`, `advery_reviews_import_row`.
