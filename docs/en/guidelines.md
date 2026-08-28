# Advery Reviews — Development Guidelines

## Compatibility
- WordPress 6.x and 7.x, tested up to 7.1. PHP 7.4+ (no syntax/features newer than 7.4; nullsafe/enums/named-args are avoided).
- No hard dependency on Composer, WooCommerce, Elementor, or Advery Schema Plus — all optional and feature-detected. Never fatal when they are absent.

## Architecture & code
- PSR-4 autoload under `Advery\Reviews\`; one small, single-purpose class per file, mirroring the core plugin's structure.
- The React admin is built with `@wordpress/scripts`; the compiled `build/` is committed so the plugin runs without a build step. The front end uses plain CSS/JS (no framework).
- Versioned DB migrations via `Installer::DB_VERSION` + `maybe_upgrade()`; every schema change bumps the version and is additive/`dbDelta`-safe.
- Expose actions/filters for every extension point; document them in architecture.md.

## Security
- Capability checks (`manage_options` for admin, appropriate caps elsewhere) and nonces on every state change.
- All SQL via `$wpdb->prepare`; never interpolate untrusted input.
- Escape on output (`esc_html`/`esc_attr`/`esc_url`/`wp_kses_post`); sanitize on input.
- REST: explicit `permission_callback`; public endpoints are nonce-guarded and rate-limited.
- Secrets (AI keys, CAPTCHA secrets) stored server-side only; never localized to the front end or returned by REST.

## Data & privacy
- Store the minimum needed; IP kept for anti-spam only. Provide export/delete paths for GDPR requests.
- Internal provenance (`origin`) is never exposed publicly, but it exists for the owner's integrity — not to deceive third parties.

## Compliance (hard rules)
- No feature may present fabricated reviews as genuine to the public or in schema. Sample/demo content is visibly labelled and excluded from `aggregateRating` by default.
- Third-party reviews are imported only with rights (owner's own data via official APIs) and are attributed; they do not feed your `aggregateRating` unless you own and collected them.
- AI never auto-publishes public-facing content without owner approval or an explicit owner-enabled auto mode, and always within cost/rate caps with an audit log.

## Process
- Build → verify live on the sample site → update the **bilingual** changelog (docs/en + docs/fa) → tag + GitHub release with a zip asset.
- Record changed decisions and mistakes in changelog_and_logs.md so they are not repeated.
