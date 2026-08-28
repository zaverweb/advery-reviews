# Advery Reviews — Features & Roadmap

Status: **planning / recorded for approval.** Nothing in this file is built yet unless the changelog says so. Items are grouped, each with the requested behaviour, expert notes, and (where relevant) a compliance flag. Implementation is phased and approval-gated.

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
