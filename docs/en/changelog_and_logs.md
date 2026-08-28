# Advery Reviews — Changelog & Development Log

We record here what each version does, what was deliberately skipped, and any mistakes/lessons so they are not repeated.

## Lessons & principles
- **Reviews are not comments.** We store in two purpose-built tables with an aggregate cache, so summaries and schema are one indexed read — not a comment row fanned across many `commentmeta` rows.
- **Fake reviews are out of scope.** Generating reviews attributed to invented people and presenting them as genuine (in the UI or in `aggregateRating`) is illegal in major markets (FTC 2024, EU UCPD/Omnibus, UK DMCC) and violates Google's review policy. We build a clearly-labelled **sample/demo** generator (staging only, excluded from schema) and **compliant review-collection** tooling instead. See features_and_ideas.md §4.
- **Third-party review import must be rights-clean.** Only the owner's own reviews via official APIs (e.g. Google Business Profile). No Google Maps scraping; imported third-party reviews are attributed and kept out of your `aggregateRating` unless you own/collected them.
- **Optional integrations are feature-detected.** No fatal when WooCommerce, Elementor, or Advery Schema Plus are absent.

---

## Changelog

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
