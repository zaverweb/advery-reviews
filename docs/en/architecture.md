# Advery Reviews — Architecture

## Purpose
A fast, self-contained ratings & reviews plugin for any post type, taxonomy term, or WooCommerce product. Fully standalone; when **Advery Schema Plus ≥ 2.21.0** is active it also injects `aggregateRating` / `review` into the JSON-LD graph.

## Design principles
- **Own optimized storage**, not WordPress comments. Two purpose-built, indexed tables keep summary reads and schema output to a single indexed row.
- **Standalone first, integrated second.** No hard dependency on the core; the schema bridge is inert without it.
- **Everything configurable, sane defaults.** The site owner can tune every rule; defaults reflect good practice out of the box.
- **Compatibility:** WordPress 6.x and 7.x (tested to 7.1), PHP 7.4+. No hard dependency on Composer, React on the front end, or a build step at runtime (the admin `build/` is committed).

## Data model (v0.1.0)
- `{prefix}advery_reviews` — one row per review: `id, object_type(post|term|product), object_id, rating(0–5), author_name, author_email, author_user_id, title, content, status(pending|approved|spam|trash), author_ip, created_at`. Indexes: `(object_type,object_id,status)`, `status`, `created_at`, `author_user_id`.
- `{prefix}advery_review_stats` — per-object aggregate cache: `object_type, object_id (PK), review_count, rating_count, rating_sum, rating_avg, updated_at`. Recomputed from approved reviews on every status change → O(1) reads.

### Planned schema additions (roadmap, will bump `Installer::DB_VERSION`)
- `origin` (`user|import|ai|sample`) — internal provenance, never exposed publicly.
- `external_source` (e.g. `google`, `csv`) + `external_id` — for idempotent import/upsert & de-duplication.
- `spam_score` (int) + `meta` (JSON) — anti-spam scoring and per-review structured extras (e.g. AI reply, import payload).
- Optional `parent_id` — for owner/AI replies threaded under a review.

## Subsystems (v0.1.0)
- **Database\\**: `Installer` (tables + versioned upgrade), `ReviewRepository` (CRUD/query/moderation), `StatsRepository` (aggregate cache).
- **Support\\**: `Settings` (single option), `Targets` (resolve/validate the current review target + labels/links), `Aggregate` (own + optional Woo native).
- **Frontend\\Display**: `[advery_reviews]` shortcode + optional auto-append; vanilla-JS star picker + REST submission (no front-end framework).
- **Rest\\RestController**: public `/submit` (nonce), `/list`; admin bootstrap/reviews/status/bulk/settings.
- **Admin\\**: `AdminPage` (React panel + pending-count menu badge), `DashboardWidget`.
- **Email\\**: `Notifier` (instant), `Digest` (WP-Cron weekly/monthly).
- **Schema\\SchemaBridge**: hooks the core `advery_schema_render_node` filter; attaches `aggregateRating` / `review` to the item node (matched by directory-listing post id or node `url` == item permalink). Woo product schema left to Woo.
- **Integrations\\WooCommerce**: read-only native rating.

## Planned subsystems (roadmap — see features_and_ideas.md)
- **AntiSpam\\**: layered, score-based checks (honeypot, timing, link/word rules, blocklist, rate limit, disposable-email, optional CAPTCHA providers, optional Akismet). One `SpamGuard` returning a score + reason; thresholds map to auto-approve / hold / spam.
- **Integrations\\Elementor**: a native Elementor widget rendering the same `Display`, with an auto-append de-dupe guard.
- **AI\\**: a provider-agnostic client (`ProviderInterface` + adapters), a `TaskRunner` with per-task enable + prompt/model/temperature/limits, used only for **legitimate** tasks (moderation assistance, owner reply drafting, translation/summary). Cost/rate caps + audit log.
- **Sample\\** (demo content, compliance-gated): a clearly-labelled sample/demo review generator for **staging/demo** environments only — see the Compliance section in features_and_ideas.md. Sample rows carry `origin='sample'` and are excluded from public schema by default.
- **ImportExport\\**: CSV/JSON import with column mapping and idempotent upsert keyed on configurable unique fields (`post id` + `external_id`), skip/update on re-run; CSV/JSON export. Google reviews only via the **official Google Business Profile API** for the owner's own locations.

## Extensibility (hooks the plugin exposes)
- `advery_reviews_created` (action, review id) — already used by the emailer; the AI/reply/anti-spam subsystems will also hook here.
- Planned filters: `advery_reviews_spam_score`, `advery_reviews_before_insert`, `advery_reviews_ai_task`, `advery_reviews_import_row`.
