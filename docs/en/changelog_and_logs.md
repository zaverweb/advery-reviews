# Advery Reviews — Changelog & Development Log

We record here what each version does, what was deliberately skipped, and any mistakes/lessons so they are not repeated.

## Lessons & principles
- **Reviews are not comments.** We store in two purpose-built tables with an aggregate cache, so summaries and schema are one indexed read — not a comment row fanned across many `commentmeta` rows.
- **Fake reviews are out of scope.** Generating reviews attributed to invented people and presenting them as genuine (in the UI or in `aggregateRating`) is illegal in major markets (FTC 2024, EU UCPD/Omnibus, UK DMCC) and violates Google's review policy. We build a clearly-labelled **sample/demo** generator (staging only, excluded from schema) and **compliant review-collection** tooling instead. See features_and_ideas.md §4.
- **Third-party review import must be rights-clean.** Only the owner's own reviews via official APIs (e.g. Google Business Profile). No Google Maps scraping; imported third-party reviews are attributed and kept out of your `aggregateRating` unless you own/collected them.
- **Optional integrations are feature-detected.** No fatal when WooCommerce, Elementor, or Advery Schema Plus are absent.

---

## Changelog

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
