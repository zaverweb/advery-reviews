# Advery Reviews

Fast, self-contained **ratings & reviews** for WordPress — for any post type, taxonomy term, or WooCommerce product — with first-class **Advery Schema Plus** JSON-LD integration.

## Why another reviews plugin

- **Purpose-built storage.** Reviews live in two lean, indexed tables (`wp_advery_reviews` + a per-object aggregate cache `wp_advery_review_stats`), not a comment row fanned out across many `commentmeta` rows. Rating summaries and schema output are a single indexed read.
- **Reviews anywhere.** Collect on posts, custom post types, taxonomy-term archives, and WooCommerce products. WooCommerce's own native ratings can be read and (optionally) merged.
- **Fully configurable.** Who can submit (anyone / logged-in), moderation (hold / auto-approve), one-per-user, rating-required, minimum length, per-page, auto-append vs `[advery_reviews]` shortcode.
- **Schema integration.** When Advery Schema Plus (≥ 2.21.0) is active, `aggregateRating` and `review` are attached to the reviewed item's node in the JSON-LD `@graph` via the core's `advery_schema_render_node` filter. Without the core, the plugin is still fully functional — the schema bridge is simply idle.
- **Owner tooling.** A smooth React moderation panel, a pending-count menu badge (like core Comments), a dashboard "Recent reviews" widget, and email reports — instant on each new review, plus an optional weekly/monthly digest.

## Structure

- `advery-reviews/` — the plugin (ships with a committed `build/`, so it runs without a build step).
  - `includes/` — PHP (Database, Frontend, Rest, Admin, Schema, Integrations, Email, Support).
  - `src/` — the React admin app (`@wordpress/scripts`).
  - `assets/` — front-end CSS/JS (no framework on the front end).

## Build

```bash
cd advery-reviews
npm install
npm run build
```

## Schema note

The plugin does **not** modify WooCommerce's own product schema (to avoid duplicate `aggregateRating`); products rely on Woo's structured data, while collected product reviews still appear on the front end and in the panel.
