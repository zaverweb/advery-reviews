# Advery Reviews — Changelog & Development Log

We record here what each version does, what was deliberately skipped, and any mistakes/lessons so they are not repeated.

## Lessons & principles
- **Reviews are not comments.** We store in two purpose-built tables with an aggregate cache, so summaries and schema are one indexed read — not a comment row fanned across many `commentmeta` rows.
- **Fake reviews are out of scope.** Generating reviews attributed to invented people and presenting them as genuine (in the UI or in `aggregateRating`) is illegal in major markets (FTC 2024, EU UCPD/Omnibus, UK DMCC) and violates Google's review policy. We build a clearly-labelled **sample/demo** generator (staging only, excluded from schema) and **compliant review-collection** tooling instead. See features_and_ideas.md §4.
- **Third-party review import must be rights-clean.** Only the owner's own reviews via official APIs (e.g. Google Business Profile). No Google Maps scraping; imported third-party reviews are attributed and kept out of your `aggregateRating` unless you own/collected them.
- **Optional integrations are feature-detected.** No fatal when WooCommerce, Elementor, or Advery Schema Plus are absent.

---

## Changelog

### Version 0.21.0 (Filters/reports list only review-enabled types)
- **Cleaner filters and reports.** The “Item type” filter (on both Reviews and Reports) and the item-search autocomplete previously listed *every* public post type and taxonomy — including builder/utility ones that never collect reviews (Elementor templates & floating buttons, JetEngine components, post formats, etc.). Now they list **only the post types and taxonomies enabled for reviews in Settings** (plus WooCommerce products when Woo reviews are on). Turn a type off in Settings and it disappears from the filters and reports.
- Empty groups are hidden, so if no taxonomy is enabled the “Taxonomies” group doesn’t show at all.
- The `GET /objects` search endpoint is restricted to the same enabled types server-side, so results stay clean too. **Verified live:** an `elementor_library` post (“Archive theme”) no longer appears in item search, while enabled Post/Category items still do.
- No DB or string changes.

### Version 0.20.0 (Configurable, responsive front-end appearance — backlog item 7/7)
- **Backlog item 7/7 — the last one.** A new **Appearance** settings section lets the owner restyle the front-end review widget to match their theme, with **no CSS**: accent color (buttons/links), text-on-accent, star color, body text, form/card background, border color, corner radius, base font size, density (comfortable/compact) and a max width. A **live preview** in the settings shows the result as you change it.
- **Driven entirely by CSS custom properties.** `front.css` was refactored so every color, radius, spacing and size reads from an `--ar-*` variable with a sensible fallback; the settings emit a single `.advery-reviews{ … }` var block inline (before any owner custom CSS, so that still wins). Leaving a color blank inherits the theme's own color.
- **Responsive.** The widget/form never exceed their container, the summary and review header wrap on narrow screens, and padding tightens under 600px. Density expands to concrete spacing so “compact” genuinely tightens the layout.
- **Safe.** Colors are validated (hex / rgb(a) / keyword only) — a CSS-injection attempt like `red;}body{display:none` is rejected and falls back to the default, so the settings can never break the page.
- **Fully translated:** the 25 new strings were added to the Persian pack (`.po`/`.mo`/JSON regenerated) — the whole Appearance UI, including the live preview, appears in Persian.
- **Verified live** on the sample site: saving a custom theme (green accent `#0e7a54`, orange stars, 14px radius, 640px max width) restyled the widget end-to-end (computed `submitBg` = the accent, `borderRadius` = 14px, stars orange, form width 640px); the injection attempt was sanitized away; the admin section + live preview render correctly in RTL Persian. (Note: LiteSpeed page cache needed a purge + the version bump busts the asset `?ver`.)

### Version 0.19.0 (Fully standard bilingual — Persian language file)
- **Complete i18n, the standard WordPress way.** Every user-facing string (PHP + the React admin + the vanilla-JS front end, whose strings are passed from PHP) is now translatable through the `advery-reviews` text domain, and a full **Persian (fa_IR)** translation ships with the plugin. On a Persian site the whole plugin — front-end review widget, moderation panel, reports, settings help text, emails — appears in Persian automatically; on any other locale it stays English.
- **Language files** in `languages/`: a `.pot` template, the `fa_IR` `.po` source, the compiled `.mo` (PHP), and the `wp i18n make-json` JSON bundles that `wp_set_script_translations` loads for the React admin. **357 strings** translated.
- **Verified live** on the fa_IR sample site: PHP (`__( 'Reports' )` → «گزارش‌ها»), the React admin JSON is found by WordPress at runtime (md5 of `build/index.js` matches the registered handle → «پرنظرترین آیتم‌ها»), and the front-end widget renders Persian (نوشتنِ نظر / نامِ شما / ثبتِ نظر / پاسخ از سویِ مالک / ۳ نظر).
- **Maintenance note:** when UI strings change, regenerate with `wp i18n make-pot . languages/advery-reviews.pot`, translate the new entries in `languages/advery-reviews-fa_IR.po`, then `wp i18n make-mo languages/ languages/` and `wp i18n make-json languages/ --no-purge`. The JS JSON is keyed by `md5('build/index.js')`, so it stays valid across rebuilds unless the strings themselves change.

### Version 0.18.0 (Filter by any custom post type / taxonomy + item search)
- **The type filter now understands every registered post type and taxonomy** — not just the three coarse buckets (post / product / term). Reviews store a coarse `object_type`, so the old dropdown could only offer "Posts / Products / Terms" and couldn't tell a `doctor` CPT from a `page`, or a custom `تخصص` taxonomy from `category`. Both the **Reviews** list and the **Reports** screen now show a grouped select — *Post types* (نوشته, برگه, doctor, treatment, …) and *Taxonomies* (دسته, برچسب, تخصص, …) — built from the site's actual registered types.
- **Resolved via a join, not the stored bucket.** A new shared `scope()` builder joins `wp_posts` (for a post-type filter, spanning post + product) or `wp_term_taxonomy` (for a taxonomy filter) so filtering by a real type is exact. Every query and every report section (summary, top items, by-type, rating breakdown, monthly trend) honours the filter consistently.
- **Search for one specific item.** A new autocomplete (`GET /objects`) lets you type a post/product/term title and filter the review list down to that single item's reviews — resolved by `object_type` + `object_id` so it's unambiguous. The free-text search now searches **review text** (author/title/content) as a separate control.
- **Verified live:** `post_type=post`→4, `doctor`/`page`→0 (none yet), `taxonomy=scpecialist`→2, `category`→1; the object search finds `Advery Reviews Demo` (post), `فیزیوتراپی` (تخصص term) and `سلامت و درمان` (category term); reports scope correctly to a chosen post type / taxonomy. Filter toolbar verified in an RTL preview.

### Version 0.17.0 (Honeypot fix + taxonomy review management + "add as me")
- **Backlog item 6/7 — honeypot fixed.** The hidden anti-bot field was named `website_hp`; because the name contains "website", browsers **autofilled it** with the visitor's saved URL, so genuine reviewers were rejected on *every* submission. Renamed to a neutral, autofill-proof name (`advery_hp`). The old field is no longer read, so any browser that had saved a value can no longer trip it.
- **Honeypot no longer causes a horizontal scrollbar.** It was hidden with `left: -9999px`; in an **RTL** page (Persian) a far-left absolute element widens the document and forces a big horizontal scroll. Switched to the accessible clip pattern (1×1px, `clip: rect(0 0 0 0)`), plus an inline-style fallback so a cached/overridden stylesheet can't reintroduce the scroll. Verified live on the RTL demo page: `scrollWidth == innerWidth` (no overflow), field hidden, new name in place.
- **Reviews on custom & built-in taxonomies are now manageable from the admin.** The code already supported term targets, but there was no admin UI on term screens. New **reviews section on the Edit Term screen** (for every enabled taxonomy — e.g. a custom `تخصص`/specialty on a doctor CPT, or the built-in `category`): list that term's reviews, add one, and approve / pending / spam / trash / delete inline — same as the post/product box. Injected via `{taxonomy}_edit_form`, reusing the existing lightweight metabox bundle.
- **"Add as me" on the add-review form.** The post/product/term box could add a review under any name; now a manager can also **add it as themselves** (a checkbox fills and locks name/email from the logged-in user, and the server takes the identity authoritatively and links the review to their user id). Unchecking it restores the free-form name/email fields, so both "as the logged-in user" and "with arbitrary details" are supported.
- **REST:** `POST /reviews` (admin create) gained an `as_current_user` flag. No DB change.
- **Verified live:** term targets enable correctly; test reviews added on a `تخصص` term (avg 4.5, 2 reviews) and a post `category` term (avg 5, 1 review) aggregate correctly and return from the public list; "add as me" stored the manager's identity + user id; the metabox UI (list, status actions, add form, the new toggle) verified in a rendered preview of the real script.

### Version 0.16.0 (Reports — which pages and businesses get the most reviews)
- **Backlog item 5/7.** A new **Reports** admin screen (its own submenu under “Reviews”) answers the question the client cares about: *which page, product or category is pulling in the most reviews.*
- **What it shows:**
  - **Headline tiles** — total reviews, approved, pending, spam, distinct items reviewed, and the overall average rating (with stars).
  - **Most-reviewed items** — a ranked list of the top objects by review count, each with a split bar (green = approved, amber = the rest), a type chip (Post / Product / Category), the total, approved count, and that item’s average rating. Labels link straight to the front-end page.
  - **Rating breakdown** — approved reviews per star (5→1) with counts and percentages.
  - **By item type** — how reviews split across posts, products and terms.
  - **Reviews over time** — a 12-month trend column chart.
- **Filters:** a time range (Last 30 days / 90 days / 12 months / All time) scopes the whole report, and an item-type filter narrows the ranked list.
- **Efficient by design.** All figures are single grouped/aggregate SQL queries (no per-row PHP loops), reusing the existing indexes; nothing is computed on the front end. The charts are pure CSS/HTML — **no external chart library**, so nothing extra is loaded and there’s no third-party request.
- **No new tables or DB version bump** — reporting reads the existing reviews table. New REST route `GET /reports` (manage_options only).
- **Verified live** on the sample site: the route registers, and the endpoint returns correct labelled totals, the top-objects ranking, rating distribution, by-type split and the monthly trend. The screen’s design was verified in a rendered preview at desktop and mobile widths (stacks cleanly).

### Version 0.15.0 (AI-assisted approval + DB indexes for pagination)
- **Backlog item 4/7.** Moderation now has a third mode, **AI-assisted approval**, alongside manual (the default hold) and auto. In AI mode each new review is classified by the AI as approve / needs-review / spam and filed accordingly. It **fails safe**: on any AI error (unconfigured, over the daily cap, network) the review falls back to **Pending** — it is never auto-published by mistake. Verified live (unconfigured → Pending).
- **Pagination/query indexes.** Added composite indexes `(status, created_at)` and `(object_type, object_id, status, created_at)` (DB → 1.4.0) so the admin’s status-filtered, newest-first pagination and the front-end’s per-item approved list are fully index-served (no filesort). Confirmed with `EXPLAIN` that the new indexes are used. (The listing was already efficient — prepared statements, `LIMIT/OFFSET`, and an O(1) aggregate cache so the front end never runs `COUNT`/`AVG` per page; this makes the admin side scale cleanly too.)

### Version 0.14.0 (Filter by type + per-post reviews metabox)
- **Backlog item 3/7.** The admin review list gains a **type filter** (All / Posts / Products / Terms) next to search — so you can separate product reviews from blog/business reviews at a glance. The list query now also supports filtering by a specific object id.
- **A “Advery Reviews” metabox on the post/product edit screen** shows that item’s reviews and lets you **add a review under any name**, and **approve / mark pending / spam / trash / delete** each one right there — no leaving the editor. It’s a lightweight vanilla-JS box (the heavy React admin bundle isn’t loaded on edit screens) talking to the REST API. New admin-only `POST /reviews` endpoint creates a review directly, bypassing the public spam checks (an authenticated manager is trusted) and never emailing.
- **Verified live:** admin-create works, the list filters correctly by object, and the metabox registers on enabled post types.

### Version 0.13.1 (UI fixes)
- **Header stat tiles are readable again.** They became clickable links in 0.13.0 and inherited the blue link colour on the blue header; forced back to white text.
- **Settings inputs no longer stretch full width** — text/select fields are capped (~380px), numbers narrower (~130px), textareas (~560px), so the forms look tidy.
- **Migration screen redesigned** into clean card sections (icon + title + a short, plain-language description + status pills), replacing the bare accordion, with simpler wording throughout.

### Version 0.13.0 (Separate admin pages + sidebar settings — far less scrolling)
- **Settings and Migration are now their own admin sub-pages** (submenus under “Reviews”), instead of tabs on one screen — cleaner navigation and no giant scroll. A slim in-app nav (Reviews / Settings / Migration) mirrors the WordPress submenu.
- **Settings uses a sidebar + single-section layout:** a left list of sections (Collection, Submission rules, Anti-spam, Display, AI, Schema, Email, Custom CSS, Maintenance) shows **one focused section at a time** on the right — much less scrolling, better accessibility, far less clutter. Fully responsive (the sidebar wraps on top on mobile). Verified the layout in a rendered preview at both mobile and desktop widths.
- No behaviour change to the settings themselves.

### Version 0.12.0 (Polished, app-like admin design)
- **A refined visual pass on the whole admin** so it feels like a smooth, purpose-built app rather than a bare settings screen: a soft page palette, generous padding, rounded **card surfaces** with gentle shadows, a gradient header, colour-coded review cards (approved/pending/spam left-accent), avatar initials, pill filters, and clearly-styled reply editors and buttons. Settings/Migration `PanelBody` sections are now soft cards that lift when opened. **Fully responsive** (stacks cleanly on mobile). Verified the design in a rendered preview.

### Version 0.11.0 (Inline help + examples on every setting)
- **Backlog item 2/7.** Because the plugin has few installs and no online tutorials, **every option in Settings now carries a clear description and a concrete example** so an operator can configure it correctly without guessing: who-can-submit, moderation, one-per-user, rating-required; all anti-spam controls (timing, link limit + action, min/max characters, name cap, blocklists with a regex example, disposable-email, duplicate, trusted, rate-limit window/max/day, hold/spam thresholds, every CAPTCHA field, Akismet); display (auto-append, per-page, loading mode, comment replacement); AI (provider, key, model, base URL, daily cap, business context, per-type reply voice, per-task prompts, test); custom CSS; maintenance; schema; and email reports. No behaviour change — documentation only.

### Version 0.10.0 (Schema mode — works with or without Advery Schema Plus)
- **Backlog item 1/7.** A **`schema_mode`** setting gives the owner the choice: **Auto** (use Advery Schema Plus when it's active so ratings merge into the page's connected `@graph`; otherwise emit our own), **Standalone** (always print this plugin's own JSON-LD `aggregateRating` + `review`, no core plugin needed), **Core only** (only via Advery Schema Plus), or **Off**. New `Schema\StandaloneSchema` prints a self-contained block on the reviewed item's page with a configurable `@type` (default `LocalBusiness`; products always `Product` and are left to WooCommerce). `SchemaBridge` now stands down in standalone/off mode, so the two never duplicate.
- **Full inline help** on the schema settings (what each mode does, with examples) — the start of the documentation pass requested for every option.
- **Verified live:** standalone emits `LocalBusiness` + `aggregateRating` (4.5) for the demo post; auto correctly defers to the active core plugin; core/off stay silent.

### Version 0.9.0 (Relationship-aware AI replies + admin redesign)
- **AI replies now know our relationship to the reviewed item.** A per-post-type role (Settings → AI): **owner/seller** (our own product/service — the AI replies as the business, drawing on the reviewed page's content, the site name/description, and an optional “About your business” context) vs **directory listing** (a third-party business we merely list — the AI replies as the platform and is explicitly told **not** to speak for the business, apologise or make promises on its behalf, or pretend to be its owner). Products default to owner; mark directory CPTs as listings. Verified live: the role clause switches correctly.
- **The reply prompt gets real page context** — the reviewed post/product excerpt, the site name + tagline, and the owner's business description — so replies are specific and accurate rather than generic.
- **Admin redesign.** A clean, card-based moderation UI: a gradient header with **stat tiles** (pending / approved / spam / total), status **pill** filters, and each review as a **card** (avatar, author, stars, status badge, content, item link, spam score) with inline reply editing and clear actions. Replaces the cramped table.
- **A live demo** post with sample reviews (and a native comment) is set up on the sample site for testing.

### Version 0.8.0 (AI subsystem — replies, moderation, translate, summarize)
- **Provider-agnostic AI** (`AI\`): a `ProviderInterface` + adapters for **Anthropic Claude, OpenAI, OpenRouter, Ollama (self-hosted), and Google Gemini**. One `Client` picks the configured provider, enforces a **daily call cap** and per-task enable, builds the prompts, calls the model, writes an **audit log** (task/provider/ok/time — no prompt text), and returns clean text or a `WP_Error`. Ollama needs no key.
- **Clear prompt system** (`AI\Tasks`) tuned to sound natural and human. Tasks, each with an editable prompt:
  - **Reply drafting** — drafts the owner's reply to a real review in the review's own language, warm and specific, no invented facts, never pretending to be the customer. Admin: a **Reply** action per review with **“Draft with AI”**, an editable box, and Save; the saved reply shows under the review on the front end (“Response from the owner”).
  - **Moderation assist** — an **AI check** action returns APPROVE / REVIEW / SPAM as a signal (never auto-destructive).
  - **Translate** and **Summarize** (review highlights).
- **AI settings tab**: provider, key, model, base-URL override, temperature, max-tokens, daily cap, per-task toggles + prompt overrides, and a **test** button.
- **No fake reviews.** The AI operates only on genuine reviews (reply/moderate/translate/summarize). It does not — and will not — generate fabricated reviews; that is illegal (FTC 2024, EU UCPD, UK DMCC) and a Google-penalty risk. To grow real ratings, use review-request collection (roadmap).
- **Verified live:** graceful, no-fatal handling when unconfigured; all five providers instantiate with correct default models; owner-reply storage and front-end display.

### Version 0.7.0 (Anti-spam hardening — links, length, injection, all fields)
- **No links by default.** `max_links` defaults to **0** and the link detector now catches every form the user listed — `https://` / `http://`, `www.`, bare domains (`example.com`, `example.co.uk`), IPv4 (`192.168.1.1`), `<a href>`, `[url]…[/url]`, and obfuscated links (`example dot com`, `example[.]com`, `example (dot) com`, `example . com`). Obfuscation is normalised to a dot first, then bare domains match only a known-TLD list, so ordinary phrases ("dot matrix", "Mr. Smith", "4.99", "e.g.") are **not** false-positives (verified: 11/11 link forms caught, 0/6 false positives). Detection runs across the **review, title and name**. New `link_action` option **Reject with a message** is the default.
- **Character limits.** Default **10–1500 characters** for the review (measured on the visible plain text), plus a configurable **author-name cap (default 35)**. Enforced as hard rejects with clear messages.
- **Injection-proofed, every field.** The review body is now reduced to **plain text** — `<script>`/`<style>`/`<iframe>`/… and their contents are removed, all remaining tags stripped, event handlers dropped, null/control bytes and invalid UTF-8 removed. Names/titles keep no HTML and are length-capped. The importers keep a larger content cap so migrated reviews aren't truncated.
- **Honeypot** stays enforced (hidden field ⇒ immediate reject) as one layer alongside timing, rate-limit, blocklist and duplicate checks.
- **Verified live:** normal review approved; a plain link and an obfuscated "… dot com" both rejected; a too-short review rejected; `<script>`/`<b>` stored as plain text; an over-long name capped to 35.

### Version 0.6.0 (Comments-everywhere-except-Woo + generic file importer)
- **Native comments replaced everywhere the owner enables it — except WooCommerce products.** `replace_comments` now takes over the comments area on any enabled post type (posts, pages, CPTs), but **never on products** (Woo keeps its own review system/tab). Verified live: a post target loads our widget; products fall through to the theme/Woo.
- **Generic file importer** (`Migration\DataImporter`): import review data prepared elsewhere — a spreadsheet, another platform's export, or an externally-scraped dataset (the plugin doesn't scrape; the owner supplies the rows as CSV/JSON, parsed in the browser). Map columns and set two keys: a **target key** (which column identifies each review's post — by post id, slug, title, or a meta value; **if the target post doesn't exist the row is skipped**) and a **unique key** (`external_source` + an id column) so **re-imports update instead of duplicating**. Auto-maps common headers; batched; per-row skip reasons. New REST route `migration/import-data`; a "Import from a file" section in the Migration tab.
- **DB → 1.3.0:** `external_id` widened `bigint`→`varchar(191)` so provider ids (e.g. Google review ids) fit, not just numeric comment ids. Additive `dbDelta` migration; `ReviewRepository`/`CommentImporter` handle it as a string.
- **Verified live:** import by post-id + by slug lookup, skip when the target business doesn't exist (with reason), and re-run de-dup → update.
- Recorded for later: a **separate, standalone Import/Export plugin** (all post types, taxonomies, our review comments, custom fields, Rank Math meta) — see features_and_ideas.md §9.

### Version 0.5.0 (Phase 2 — Elementor, Gutenberg block, Woo schema merge)
- **Elementor widget** (`Integrations\ElementorBridge` + `Elementor\ReviewsWidget`): a native "Advery Reviews" widget that renders the exact same server-side markup as the shortcode (same styling, loading modes, schema). Controls for current-page vs a specific post id; editor placeholder when the page isn't a review target. Feature-detected — inert without Elementor; supports both the 3.5+ and legacy registration hooks. Verified live (Elementor + Pro active on the sample site): the widget registers.
- **Gutenberg block** (`Integrations\GutenbergBlock`, `advery/reviews`): a dynamic, server-rendered block with a `ServerSideRender` editor preview via a tiny no-build script — so the front-end HTML is crawlable and identical to the other placements. Verified live: block registers and its `render_callback` outputs the widget.
- **De-dup guard:** the shortcode, block and Elementor widget mark the target as printed; **auto-append is suppressed** when the widget was already placed on the page, so reviews never appear twice. Verified live.
- **WooCommerce schema merge** (`Integrations\WooSchema`): when "merge Woo native ratings" is on, our collected product reviews are merged into **WooCommerce's own** `woocommerce_structured_data_product` output (a single combined `aggregateRating` + appended `review` nodes) instead of a competing block — no duplicate/conflicting product schema. Feature-detected (only with WooCommerce active).

### Version 0.4.0 (Comment migration — WP/Woo ⇄ plugin)
- **Import** (`Migration\CommentImporter`): brings WordPress post comments and WooCommerce product reviews into the plugin's tables, mapping every field — content, author name/email/user, date → created_at, approval → status (`1`→approved, `0`→pending, `spam`/`trash` preserved), Woo's `rating` → rating. Extra comment meta is preserved in the review `meta` JSON so nothing is lost. **Non-destructive by default** (copies; an explicit opt-in can delete the source comments). De-duplicates on re-run via `(external_source, external_id)`; batched for large sites.
- **Export** (`Migration\CommentExporter`): recreates native WP comments / Woo reviews from natively-collected plugin reviews (reversible, idempotent). Each exported comment is flagged (`_advery_exported`) and the importer skips it, so import and export **can never loop**.
- **CSV backup** (`Migration\CsvExporter`): download every review as CSV.
- **DB → 1.2.0:** `advery_reviews` gains `external_source` (varchar) + `external_id` (bigint) with an index, for import de-duplication. Additive `dbDelta` migration.
- **Admin:** a new **Migration** tab — preview counts, source selection (WP comments / Woo reviews), update-existing and delete-source options, batched progress, reverse export, and CSV download.
- **Verified live:** import (3 comments → reviews, statuses mapped, `<script>` sanitized), re-run de-dup (all skipped), update-existing, reverse export (comment created with rating + loop-guard flag), loop guard (exported comment excluded from import), idempotent re-export, CSV output.

### Version 0.3.0 (Security hardening + display & integration)
- **Input security (`Support\Sanitizer`)** — one place all visitor input passes through before storage. Forces valid UTF-8, strips null bytes and C0/C1 control characters (the "disallowed character" injection vector), removes `<script>/<style>/<iframe>/<object>/<embed>/<form>` and all `on*=` event handlers, and restricts the review body to a tiny HTML allowlist (kses also neutralises `javascript:` URLs). Names/titles keep no HTML at all. Length caps are enforced. Verified live: `<script>`, `onclick`, `javascript:`, null/control bytes all stripped; safe formatting (`<b>`) kept.
- **CAPTCHA is not load-bearing:** the layered SpamGuard (honeypot + signed timing + rate limit + blocklist + duplicate) protects a site that never configures a CAPTCHA; CAPTCHA is one optional extra layer.
- **Loading modes (SEO-safe):** all-on-one-page (fully server-rendered), a **“Load more”** button (AJAX append), or **numbered pagination** (AJAX replace). The **page URL never changes** (no query params, no history writes) and the **first page is server-rendered**, so crawlers read reviews and canonical/SEO is untouched. Per-page count configurable; `/list` returns `total`/`page`/`per_page`.
- **Native comment replacement (`Frontend\CommentsTakeover`):** an option to take over the theme's comments area with the reviews widget via the `comments_template` filter — no theme edits, **no page builder / Elementor required**. Auto-append is suppressed when this is on (no double output).
- **Custom CSS:** an owner CSS box printed inline wherever the widget renders (`<` stripped to prevent a `</style>` breakout).
- **Table hygiene (`Support\Maintenance`):** reviews are removed automatically when their post/product (`deleted_post`) or term (`delete_term`) is deleted; an admin can **purge orphaned reviews** and **OPTIMIZE** the tables from Settings → Maintenance. Verified live: auto-cleanup on delete, orphan purge, optimize.

### Version 0.2.0 (Phase 1 — layered anti-spam)
- **New `SpamGuard`** — a layered, score-based evaluator run on every submission. Cheap local checks first; each adds to a spam score with a human-readable reason; two hard-reject checks short-circuit. Score → outcome: `reject` / `spam` / `hold` / `approve` (approve still respects the moderation setting).
- **Layers (all toggleable, sensible defaults on):** honeypot; **signed timing token** (form filled faster than N seconds ⇒ bot); **link limit** (hold/spam over the max); **word/phrase blocklist** (plain + `re:` regex, seed list shipped); **email/domain blocklist** + **disposable-email** list; **rate limiting** (per IP/email, per window + per day); **duplicate content**; **min/max words** and **max chars**; **trusted fast-track** (logged-in author with a prior approved review auto-approves); optional **Akismet** signal.
- **CAPTCHA providers:** reCAPTCHA v2 & v3 (score threshold), **hCaptcha**, **Cloudflare Turnstile** — front-end widget + server-side verification; keys in settings, secret never exposed to the front end. Fails open on a provider network error.
- **Admin:** an "Anti-spam" settings section for every layer + thresholds + CAPTCHA; the review list shows each row's **spam score and reasons**.
- **DB:** `advery_reviews` gains `spam_score` (int) and `meta` (JSON, stores spam reasons). `Installer::DB_VERSION` → 1.1.0 with a `dbDelta`-safe additive migration.
- **Verified live** on the sample site (guest submissions): clean → approved, too-fast → hold, blocklisted word → spam, too-many-links → hold, disposable email → spam, duplicate → spam, rate limit → reject; scores/reasons stored and shown.

### Version 0.1.0 (initial release)
- Standalone ratings & reviews for post types, taxonomy terms, and WooCommerce products.
- Two optimized tables: `advery_reviews` + `advery_review_stats` (per-object aggregate cache recomputed on every status change).
- Configurable submission rules: who can submit (anyone/logged-in), moderation (hold/auto), one-per-user, rating-required, min length; honeypot; REST + nonce.
- Front end: `[advery_reviews]` shortcode + optional auto-append; vanilla-JS star picker (no front-end framework).
- Admin: React moderation panel (filter/search/bulk/paginate), pending-count menu badge, dashboard "recent reviews" widget.
- Email: instant on each new review + optional weekly/monthly digest (WP-Cron).
- Schema: attaches `aggregateRating`/`review` to the item's node via the core `advery_schema_render_node` filter (needs Advery Schema Plus ≥ 2.21.0; idle without it). Woo product schema left to Woo to avoid duplication.
- Verified live on the sample site: submission + validation, auto/manual moderation, aggregate recompute on status change, front-end widget, and schema injection.

### Unreleased / planned
See features_and_ideas.md for the phased roadmap (anti-spam, Elementor, import/export, AI, sample-data + review-collection). Recorded 2026-08-28; awaiting approval before phased implementation.
