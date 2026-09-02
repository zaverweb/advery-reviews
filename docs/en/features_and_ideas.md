# Advery Reviews — Features & Roadmap

## ▶ New queue (added 2026-09-02 — priority order D → A → B+C, then G; awaiting user’s go)
- **D — Block native-comment spam (CRITICAL, first). — DONE v0.30.0.** Spam still lands in `wp_comments` via direct POSTs to `wp-comments-post.php` (bots skip the page/form; "comments closed" is per-post so many posts stay open; our CommentsTakeover only changes display). Fix shipped: a "Guard the built-in WordPress comment form" option (Anti-spam → Native WordPress comments) with Off / Filter / Disable, using `preprocess_comment` + `pre_comment_approved` to run the content policy (links, blocked words, blocked/disposable emails) on native comments — reject / spam / hold — or refuse them entirely and force `comments_open` false. Pingbacks and moderators are exempt. Verified live via `curl` POST to `wp-comments-post.php` (link → 403, clean → 302, disable → 403). Manual `curl` test documented in the changelog.
- **A — Configurable link TLD/pattern list. — DONE v0.31.0.** Opt-in strict blocking (removed in v0.29.1): new Anti-spam → Links → "Strict link endings / patterns (optional)" (`antispam.link_tlds`); each line is a domain ending (`.com`, `ru`, `.co.uk`) making `word.ending` count as a link, or a `re:` raw regex; default empty (safe, v0.29.1 behavior unchanged). Applied in `SpamGuard::count_links($text,$extra)` for both reviews and (Filter-mode) native comments; action follows the existing link_action. Help documents the false-positive tradeoff. Verified live via `SpamGuard::evaluate`.
- **B+C — Spam log + efficient queries. — DONE v0.32.0.** New table `{prefix}advery_review_spam_log` (DB 1.5.0) logging blocked/held/spam submissions: time, source (review/comment), outcome, target (post/page), IP, reason, capped content. `AntiSpam\SpamLog` (record/query/purge_expired/clear_all/clear_cron); wired into `RestController::submit` + `CommentGuard::preprocess`. Settings `antispam.spam_log_enabled` (default OFF — PII) + `spam_log_retention_days` (10); daily WP-Cron `advery_reviews_spam_log_purge` (scheduled only while on). New standalone "Spam log" admin submenu + `SpamLogPanel.js` (filter source/outcome/search, paged) + REST `GET /spam-log` + `POST /spam-log/clear`. C: new `admin_per_page` (default 20) drives the admin Reviews list (was hardcoded 20) + the log view. Verified live.
- **G (LAST) — Elementor Loop-Grid** (see below).

## ▶ Active work queue (approved 2026-08-30, do one at a time; bump version + confirm before the next)
Order fixed by the user; **G is last**. Each item ships as its own version.
1. **Faster settings navigation (SPA)** — the 4 admin screens (Reviews / Reports / Settings / Migration) are separate WP sub-pages, so switching does a full reload. Add History-API client-side routing: intercept the in-app nav links, `pushState` to the same admin URLs (URL still changes), swap the screen instantly, handle back/forward (`popstate`), and keep direct-load/deep-link working. — **DONE v0.25.0.**
2. **Modern card-style front-end appearance** — a new, contemporary review-card look (avatar + name + stars + date + body, tidy spacing), inspired by the shared references (daneshjooyar gold-front, wpdavinci khabarina, nias befka-store) — treated as design *inspiration only*, driven by the existing CSS variables so it stays themeable. Likely a “style/skin” choice (classic vs card) in Appearance.
3. **WooCommerce review takeover + our stars** — option to take over the Woo product-review area so reviews collected here show with *our* stars/skin instead of Woo’s/theme’s. Must avoid a duplicate `aggregateRating` (one source only — coordinate with the existing WooSchema merge).
4. **Elementor widget (improve/verify)** — a widget already exists (`Elementor\ReviewsWidget`, v0.5.0); revisit it to expose the new appearance/skin + avatar options and confirm it’s current.
5. **(LAST) Elementor Loop-Grid design with review fields** — expose each review’s fields (name, rating, text, date, avatar) to Elementor as a query/loop source + Dynamic Tags so a user can visually design the review card like a Loop Grid. Big item: either a custom Elementor query/dynamic-tag layer over our tables, or mirror reviews into a hidden CPT for design. Plan separately before building.

---

Status: **phased build in progress.** Done: **anti-spam (v0.2.0)**; **input-security hardening, SEO-safe loading modes, native-comment replacement, custom CSS, and table hygiene / orphan cleanup / optimize (v0.3.0)**. **Immediate next: comment migration** (WP comments + WooCommerce reviews ⇄ the plugin, with field mapping so comments aren't lost, and re-run de-dup — see §5.1). Items below carry the requested behaviour, expert notes, and (where relevant) a compliance flag.

## 5.1 Comment migration (immediate next)
**Requested:** migrate comments from WooCommerce reviews and normal WordPress post comments into the plugin **and back** (vice versa); during migration also move the extra fields so nothing is lost; de-dup on re-run.

**Design (expert):**
- **Import** WP comments (`comment_type` in `''`/`comment`) and WooCommerce reviews (`comment_type = review`, rating in `commentmeta.rating`) into `advery_reviews`, mapping: comment → content, `comment_author`/`comment_author_email`/`user_id`, `comment_date` → created_at, approval → status (`1`→approved, `0`→pending, `spam`→spam), and Woo's `rating` → rating. Store provenance `external_source = 'wp_comment'|'wc_review'` + `external_id = comment_ID` (roadmap columns) so a **re-run upserts** (skip/update) instead of duplicating. Extra/unmapped comment meta is preserved in the review `meta` JSON.
- **Export back**: recreate WP comments / Woo reviews from plugin rows (guarded to avoid loops), for reversibility.
- **Non-destructive by default**: importing copies; a separate explicit option deletes the source comments only if the owner asks. After import, `Maintenance::rebuild_stats()` refreshes aggregates.
- Batched (WP-Cron or AJAX chunks) for large sites; a dry-run count first.

---

## 1. Anti-spam (own, layered, score-based)
**Requested:** honeypot; Google reCAPTCHA and other common free services; limit/deny links (e.g. `http`) in content; ban common spam words; rate-limit (block many comments in a short time); enforce min/max word count. All configurable, but with strong defaults informed by real spam patterns.

**Design (expert):** a single `SpamGuard` that runs cheap checks first and returns a **spam score + reasons**. Thresholds map to an outcome: `approve` / `hold for moderation` / `mark spam` / `hard-reject`. Layers (each individually toggleable, sensible defaults on):
- **Honeypot** (have it) + **submission timing token** (form filled in < N seconds ⇒ bot). *Default on.*
- **Link rules:** max N URLs (default 1–2); optionally auto-spam if any link when the author has no approved history. *Default: hold if > 2 links.*
- **Blocklist:** word/phrase and regex list (pharma/casino/loan/SEO spam seeds shipped, editable). Also an **email/domain blocklist** and **disposable-email** domain list. *Default seed list on.*
- **Rate limiting:** max submissions per IP / per email per rolling window (default e.g. 3 / 10 min, 10 / day). *Default on.*
- **Duplicate content:** identical/near-identical body already submitted ⇒ spam. *Default on.*
- **Length bounds:** min/max words and characters. *Default min 1 word, max 5000 chars.*
- **CAPTCHA providers (optional):** Google reCAPTCHA v3 (score) & v2, **hCaptcha**, **Cloudflare Turnstile** (both free, more privacy-friendly — recommended defaults to offer alongside reCAPTCHA). Keys in settings; server-side verification.
- **Akismet (optional):** if the site has Akismet configured, offload as one more signal.
- **Expert additions:** per-check weight → total score; a "spam score" column; an admin "why held/spammed" reason on each row; allow/deny lists for trusted users (logged-in with prior approved reviews auto-approve).

---

## 2. Elementor widget
**Requested:** an Elementor element; make sure it doesn't conflict with the default reviews.

**Design (expert):** register a native Elementor widget (`elementor/widgets/register`) that renders the same `Display` output, with controls (target override, count, show-form, heading). **De-dupe guard:** when the widget renders on a page, suppress `auto_append` for that request so reviews never appear twice. Also keep our reviews strictly separate from WordPress core comments and WooCommerce's own review tab (distinct `object_type`, no hijacking of the comment system) so there is no collision with "default reviews".

---

## 3. AI system (provider-agnostic, per-task, fully controllable)
**Requested:** pluggable AI providers (different API vendors); usable for different parts as needed — e.g. approve/reject a message, reply to a message; enable exactly where wanted; behaviour and standards fully controllable.

**Design (expert):**
- **Provider abstraction:** `ProviderInterface` + adapters (Anthropic Claude, OpenAI, Google Gemini, OpenRouter, self-hosted/Ollama). Per-provider API key, base URL, model.
- **Task runner:** each task independently enable-able, each with its own provider/model/prompt/temperature/limits:
  - **Moderation assist** — classify a submission (spam / needs-review / ok) as *one more signal* feeding the SpamGuard score (never the sole gate for anything destructive without owner confirmation).
  - **Reply drafting** — draft an owner reply to a review (stored as a reply, publishable by the owner; clearly the business replying — this is legitimate).
  - **Translate / summarize** — translate a review, or produce "review highlights" for a product/listing.
  - **Rating↔text consistency check** — flag when the text sentiment and the star rating disagree.
- **Guardrails:** hard cost/rate caps, per-task on/off, dry-run/preview, full audit log of every AI call, PII minimization, and no AI action auto-publishes anything user-facing without either owner approval or an explicit owner-enabled auto mode.

---

## 4. Sample / demo review generation  ⚠️ COMPLIANCE-GATED
**Requested:** a name library (first+last) in Persian & English, randomly combined, easily editable per language; generate in bulk — e.g. target a category or post type and, for ~30% of items, add 1–7 reviews; dates spread from now to +30 days; drip-publish gradually; also do it inside a single post; publish or draft; a **backend-only marker** so we know which are AI vs user while no one else can tell; comments relevant, in a real-user tone, with the rating matching the sentiment.

**Expert position — read before building.** Generating reviews that are written by AI / attributed to invented people and presented to the public as genuine customer reviews — and especially feeding them into `aggregateRating` shown in Google — is **fake-review creation**. It is:
- **Illegal in major markets.** The US FTC's 2024 rule bans fake/AI-generated consumer reviews and testimonials with civil penalties per violation; the EU Omnibus/UCPD and UK DMCC Act similarly prohibit fake reviews and undisclosed fake endorsements.
- **A platform-policy violation.** Google's structured-data review policy requires reviews to be genuine and collected directly; fake reviews risk manual actions, loss of rich results, and deindexing. Google Maps/Business content has its own prohibitions.
- **A reputation/liability risk** for you and every site owner who ships it.

A hidden "AI vs user" flag that "no one else can tell" is precisely what makes it deceptive rather than transparent, so I will **not build a generator intended to pass fabricated reviews off as real** (fake names, backdated, drip-published into public schema).

**What I will build instead (legitimate, and covers the real need):**
- A **Sample/Demo Data generator for staging & demos only**: creates clearly-labelled placeholder reviews with `origin='sample'`, a visible "Sample" badge on the front end, **excluded from `aggregateRating`/schema by default**, and a one-click purge. Perfect for theme demos, screenshots, and load-testing the UI. The editable multilingual name library (fa/en, JSON, easily extended) lives here and is genuinely useful for demo data.
- **Review-collection tooling to get *real* reviews** (the compliant way to grow reviews): post-purchase / post-visit **review-request emails**, a shareable review link/QR, and reminders. This grows genuine ratings that are safe for schema.

If you want the sample generator to ever run on production, it must keep the visible "sample" label and stay out of schema — I can make that a hard, non-removable rule.

---

## 5. Import / Export
**Requested:** import from other sources like Google Maps; import for one business or many at once; the importer can set post id, review ids, and other unique markers; specify "also import column X for each"; on the next run, check these two key factors and offer skip or update if they match; export too.

**Design (expert):**
- **CSV / JSON importer** with a column-mapping UI (map source columns → `object_id`, `rating`, `author_name`, `content`, `created_at`, `external_id`, …), batch import across many targets at once, and **idempotent upsert** keyed on configurable unique fields (default `object_id` + `external_id` + `external_source`) with **skip / update** on re-import. Needs the roadmap columns `external_source`, `external_id`.
- **CSV / JSON export** with the same fields (for backup/migration).
- **Google reviews — the compliant path:** import the owner's **own** locations' reviews via the **official Google Business Profile API** (OAuth), storing `external_source='google'` + Google's review id. ⚠️ **Scraping Google Maps** (or importing other businesses' reviews you don't own) violates Google's ToS and, if surfaced as your own `aggregateRating`, breaks Google's review policy and consumer law. So: own-business, official-API only; imported third-party reviews are shown with attribution and are **not** fed into your `aggregateRating` unless you own and collected them.

---

## 5.2 Generic file importer (SHIPPED v0.6.0) + external/scraped data
Rather than each provider's API, the plugin ships a **generic CSV/JSON importer** (`Migration\DataImporter`) for review data prepared elsewhere — a spreadsheet, another platform's export, or an externally-scraped dataset (the plugin does not scrape; the owner supplies the rows). The admin maps columns and sets the two **key** kinds: a **target key** (which column identifies each review's post, resolved by post id / slug / title / meta — if the target doesn't exist the row is skipped) and a **unique key** (`external_source` + an id column) so re-imports **update instead of duplicating**. `external_id` was widened to `varchar(191)` (DB 1.3.0) to hold provider ids (e.g. Google review ids). Compliance still applies: only import reviews you have rights to, and keep non-owned reviews out of your own `aggregateRating`.

## 9. Future: a standalone custom Import/Export plugin (separate project, after this)
A general WordPress content migrator (its own plugin): export/import **all post types, taxonomies, our custom review comments, custom fields, and third-party meta such as Rank Math** per post, so a whole site's content + SEO data moves cleanly between installs. Scope recorded for after the Reviews plugin is complete; not started.

## 5.3 Backlog requested 2026-08-28 (do ONE at a time, in order)
1. **Schema mode — works with OR without Advery Schema Plus, the owner's choice.** A `schema_mode` setting: `auto` (use the core plugin if active, else emit our own), `core` (only via Advery Schema Plus), `standalone` (always emit our own JSON-LD `aggregateRating`/`review`), `off`. Standalone emits a self-contained block for the reviewed item (configurable `@type`); products are left to WooCommerce to avoid duplicates. **(FIRST — in progress.)**
2. **Inline help + examples on every option.** This plugin has few installs and no tutorials online, so every setting/field in the admin must carry a clear description and a concrete example, so an operator can use it correctly.
3. **Categorised, filterable comments + per-post metabox.** Admin: filter by object type (product vs post vs term/business) and by a specific post/taxonomy. On each post's edit screen, a metabox showing that post's reviews, with the ability to add a review under any name/details and approve / reject / spam it right there.
4. **Approval modes like WordPress:** auto-approve, manual approval, and **AI approval** as an option; **default = hold for moderation (draft/pending)**.
5. **Reporting/analytics:** which category/page/business received the most reviews, over time.
6. **Honeypot fix:** the current hidden field `name="website_hp"` triggers browser/password-manager autofill (the word “website”), causing false-positive spam rejections for real users. Rename to a neutral, autofill-resistant field, keep it inert, and lean on the timing token.
7. **Configurable, responsive appearance for the front-end widget:** colours and styles tunable per site type via a settings section (e.g. border-radius on/off, primary/star colours, density, layout), with CSS-variable output and full responsiveness.

## 6. Carried-over v0.1 follow-ups
- **Gutenberg block** (currently shortcode + auto-append only).
- **Front-end "load more"** wired to the existing `/list` REST endpoint.
- **Merge Woo native reviews into Woo's own product schema** (currently merged only into our front-end aggregate).

---

## 7. Cross-cutting requirements (all phases)
- **Security:** capability checks, nonces, prepared SQL, output escaping, REST permission callbacks, rate-limited public endpoints, secrets (API keys) stored server-side and never exposed to the front end.
- **Performance:** aggregate cache (have it), indexed queries, lazy AI/CAPTCHA loading, no front-end framework, minimal queries per page.
- **UX:** smooth React admin matching the core's `@wordpress/components` style; clear empty/error/loading states; keyboard-accessible.
- **Compatibility:** WordPress 6.x–7.x (test to 7.1), PHP 7.4+; no fatal without Woo/Elementor/core (all optional integrations feature-detected).
- **Code quality:** PSR-4, small single-purpose classes, documented hooks, versioned DB migrations, translations (en + fa shipped).

---

## 8. Proposed phased plan (for approval)
1. **Phase 1 — Anti-spam** (high value, low risk): SpamGuard + settings + reasons column (DB adds `spam_score`, `meta`). Ship CAPTCHA (reCAPTCHA/hCaptcha/Turnstile) + blocklist + rate limit + link/length/duplicate + Akismet.
2. **Phase 2 — Elementor widget** + the v0.1 follow-ups (block, load-more, Woo schema merge).
3. **Phase 3 — Import/Export** (CSV/JSON + upsert; adds `external_source`/`external_id`), then **Google Business Profile API** (own locations) as a sub-step.
4. **Phase 4 — AI subsystem** (provider abstraction + moderation-assist + owner reply drafting + translate/summarize), with cost caps + audit log.
5. **Phase 5 — Sample/Demo generator** (compliance-gated, staging-labelled, out of schema) + the multilingual name library, **plus** the compliant review-request/collection tooling.

Each phase: build → verify live on the sample site → bilingual changelog → tag + GitHub release. DB changes bump `Installer::DB_VERSION` with a safe `maybe_upgrade`.
