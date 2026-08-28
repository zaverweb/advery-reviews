# Advery Reviews — Changelog & Development Log

We record here what each version does, what was deliberately skipped, and any mistakes/lessons so they are not repeated.

## Lessons & principles
- **Reviews are not comments.** We store in two purpose-built tables with an aggregate cache, so summaries and schema are one indexed read — not a comment row fanned across many `commentmeta` rows.
- **Fake reviews are out of scope.** Generating reviews attributed to invented people and presenting them as genuine (in the UI or in `aggregateRating`) is illegal in major markets (FTC 2024, EU UCPD/Omnibus, UK DMCC) and violates Google's review policy. We build a clearly-labelled **sample/demo** generator (staging only, excluded from schema) and **compliant review-collection** tooling instead. See features_and_ideas.md §4.
- **Third-party review import must be rights-clean.** Only the owner's own reviews via official APIs (e.g. Google Business Profile). No Google Maps scraping; imported third-party reviews are attributed and kept out of your `aggregateRating` unless you own/collected them.
- **Optional integrations are feature-detected.** No fatal when WooCommerce, Elementor, or Advery Schema Plus are absent.

---

## Changelog

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
