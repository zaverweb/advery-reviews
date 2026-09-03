# Advery Reviews — Changelog & Development Log

We record here what each version does, what was deliberately skipped, and any mistakes/lessons so they are not repeated.

## Lessons & principles
- **Reviews are not comments.** We store in two purpose-built tables with an aggregate cache, so summaries and schema are one indexed read — not a comment row fanned across many `commentmeta` rows.
- **Fake reviews are out of scope.** Generating reviews attributed to invented people and presenting them as genuine (in the UI or in `aggregateRating`) is illegal in major markets (FTC 2024, EU UCPD/Omnibus, UK DMCC) and violates Google's review policy. We build a clearly-labelled **sample/demo** generator (staging only, excluded from schema) and **compliant review-collection** tooling instead. See features_and_ideas.md §4.
- **Third-party review import must be rights-clean.** Only the owner's own reviews via official APIs (e.g. Google Business Profile). No Google Maps scraping; imported third-party reviews are attributed and kept out of your `aggregateRating` unless you own/collected them.
- **Optional integrations are feature-detected.** No fatal when WooCommerce, Elementor, or Advery Schema Plus are absent.

---

## Changelog

### Version 0.33.0 (Three new front-end skins + author is now Zaver Web)
- **Three new layout skins** join Cards and Classic, all driven by the same `--ar-*` appearance variables (colors, radius, spacing all follow your settings):
  - **Minimal** — airy and borderless: no card, generous spacing, a hairline divider between reviews, the author name in your accent color and a small upper-case date.
  - **Bubble (chat)** — the review text sits in a rounded, tinted speech bubble with a small tail pointing up to the author header, like a chat message.
  - **Quote (testimonial)** — centered cards with a large decorative quotation mark, the avatar/name/stars stacked and centered, and the text centered below — ideal for a testimonials section.
- Selectable under **Settings → Appearance → Layout style**, and per-widget in the Elementor widget’s **Appearance → Layout style** (which can also inherit the global setting). Same markup as the other skins, so loading modes, schema and the owner-reply block are unchanged.
- **Verified live** on the sample site (RTL): each new skin renders correctly — bubble tails, the centered quote layout with the quote glyph, and the borderless minimal list.
- **Plugin author is now “Zaver Web” (https://zaverweb.com)** in the plugin header (was “Advery”). No functional change.
- 5 new strings translated to Persian.

### Version 0.32.0 (Spam log + efficient queries + configurable admin page size — queue item B+C)
- **New “Spam log” (opt-in, auto-purged).** A dedicated menu + screen that records every submission the anti-spam layer **rejected, held, or marked as spam** — from both the review form and (in Filter mode) native WordPress comments — so you can see *what* was filtered and *why*.
  - Each row stores: time, source (review / native comment), result (rejected / spam / held), the **target** page (linked), the visitor **IP**, the **reason** (e.g. `links(1)`, `blocklisted-word`, `disposable-email`) and a capped **content** snippet.
  - The admin view is filterable (source / result / free-text search across content, IP, email, reason) and paged.
  - **Off by default** — the rows include IPs and submitted text (personal data), so you enable it deliberately under **Settings → Anti-spam → Spam log**, with a configurable **retention (days)**.
  - A **daily WP-Cron job** auto-purges rows older than the retention window; the event is scheduled only while the log is on and removed when you turn it off or deactivate the plugin. A **“Clear log”** button empties it on demand.
- **New table** `{prefix}advery_review_spam_log` (DB schema → 1.5.0, additive/`dbDelta`), kept separate from the lean reviews table and indexed by time (for the paged view + purge) and by IP (to spot a repeat offender). All reads are prepared and paged. Dropped on uninstall.
- **Configurable admin page size (item C).** New **Settings → Display → “Rows per page in the admin”** (`admin_per_page`, default 20) now drives both the admin **Reviews** list (previously hard-coded to 20) and the **Spam log** — no more fixed page size.
- **Verified live:** the spam-log table was created (DB 1.5.0); a link comment was blocked and logged (`reject`, `links(1)`), a disposable-email comment logged (`spam`); the daily purge removed a 20-day-old row while keeping a fresh one; the purge cron schedules when enabled and unschedules when disabled; the admin screen renders in Persian RTL with colored result pills, linked targets, filters and pagination. Test data cleaned up.
- 30 new strings translated to Persian.

### Version 0.31.1 (Persian translation clarity pass — Anti-spam settings)
- **Rewrote confusing Persian help/labels in the Anti-spam section** with clearer wording and a consistent, polite tone (the user flagged several as hard to follow).
- **Fixed a real display bug:** the Persian for the “Blocked words / phrases” help had an exploded backslash run (`re:\\\\…\\bcasino…`) instead of `re:\bcasino\b`, so the regex example rendered as gibberish. Now correct.
- **Fixed a misleading translation:** “trusted authors skip moderation next time” was translated as if the review *is rejected* next time; it now correctly reads “is published next time without needing review (but a blocked link still rejects it)”.
- **Consistent tone for the native-comment options:** the Off/Filter/Disable labels and the strict-link help used informal commands (“don’t touch”, “apply”, “leave empty”); rephrased as neutral, polite noun phrases matching the rest of the panel.
- **Clearer Akismet + native-comment-guard descriptions.**
- Translation-only (English source unchanged); regenerated `.mo` + the React-admin `.json`. Verified the exact new strings are present in both the compiled MO and the served JSON.

### Version 0.31.0 (Configurable strict link endings / patterns — queue item A)
- **Re-adds opt-in strict link blocking** that was removed in v0.29.1, but **owner-controlled** so it can’t cause silent false positives. A new **Anti-spam → Links → “Strict link endings / patterns (optional)”** field lets the owner list, one per line:
  - a **domain ending** — `.com`, `ru`, `.co.uk` — which makes a plain `word.ending` (e.g. `shop.com`, `myshop.ru`) count as a link even without `http://`/`www.`;
  - or an **advanced regex** on a line starting with `re:` (e.g. `re:\bt\.me/` to catch Telegram links), matching the existing blocklist-words convention.
- **Empty by default = safe** — with the field blank, link detection is unchanged from v0.29.1 (only unambiguous signals: `http(s)://`, `www.`, `<a>`, `[url]`, IPv4, and spelled-out/bracketed obfuscated dots). A plain `word.tld` is still ignored unless the owner opts in here.
- **Clearly documented tradeoff.** The field’s help explains this is for when bots post bare domains like `myshop.ru` without `http://`, and warns it can also flag look-alikes (a normal sentence containing `file.com`). The matched action still follows the existing **“When over the link limit”** setting (Reject / Spam / Hold / Ignore).
- **Applies to both** submitted reviews and, when the native-comment guard (v0.30.0) is in Filter mode, native comments — one list governs both.
- **Verified live** (sample site, `SpamGuard::evaluate`): with the list **empty**, a review containing `myshop.ru` and `ali@gmail.com` is **not** flagged as a link; with the list `ru`/`com`, `myshop.ru` is **rejected** (and `gmail.com` too — the documented tradeoff); a `re:\bt\.me/` line rejects `t.me/spamchannel`. Setting restored to empty after testing.
- 2 new strings + 1 updated help translated to Persian.

### Version 0.30.0 (Guard native WordPress comments against direct-POST spam — queue item D)
- **The problem.** Our review form is well protected, but spam bots don’t use it — they POST straight to WordPress’s own `wp-comments-post.php`, landing spam in `wp_comments`. Taking over the comments *display* (v0.3.0) never changed what WordPress *accepts*, and “comments closed” is a **per-post** setting, so any post left open still takes them.
- **New setting: “Guard the built-in WordPress comment form”** (Anti-spam → Native WordPress comments), site-wide, with three modes:
  - **Off** (default) — WordPress behaves exactly as before.
  - **Filter** — the same content-policy checks already configured for reviews (link rule, blocked words/phrases, blocked & disposable email domains) are applied to native comments via the `preprocess_comment` hook, which fires for **every** comment including a direct POST. A blocked comment is **rejected** (403); a link hit becomes **spam** or is **held** for moderation per your link action; a blocked/disposable email is stored as **spam**.
  - **Disable** — every native comment is refused and the comment form is hidden site-wide (`comments_open` forced false).
- **Safe by design.** Pingbacks/trackbacks are left to WordPress, and any logged-in user who can moderate comments is never blocked (so admins/editors can always reply). Reuses the existing anti-spam settings, so you configure the rules once.
- **Verified live** (sample site, post “Advery Reviews Demo”) with direct `curl` POSTs to `wp-comments-post.php`: **before** — a comment containing a link was accepted (302, stored); **after (Filter)** — the same link comment is blocked (`403 “Links are not allowed in comments.”`), a blocked word is blocked (`403`), and a clean comment is still accepted (302); **Disable** — even a clean comment is refused (403) and `comments_open()` returns false. Test comments cleaned up afterwards.
- **Manual test (owner):** with the guard on, from a terminal:
  ```
  curl -i -A "Mozilla/5.0" \
    --data-urlencode "author=Test" \
    --data-urlencode "email=test@gmail.com" \
    --data-urlencode "comment=hello http://spam.example buy now" \
    --data-urlencode "comment_post_ID=<A_POST_ID_WITH_COMMENTS_OPEN>" \
    "https://YOUR-SITE/wp-comments-post.php"
  ```
  A link/blocked comment returns **HTTP 403**; a clean one returns **HTTP 302** (accepted, then handled by your normal moderation).
- 12 new strings translated to Persian.

### Version 0.29.1 (Fix: false “links are not allowed” rejections)
- **Bug fix.** The link detector flagged any `word.tld` with a literal dot, so ordinary reviews were wrongly rejected when they happened to contain an **email domain** (`name@gmail.com`), a **tech term** (`asp.net`, `node.js`), or a **version** (`15.pro`) — the TLD list includes many everyday words (net, io, app, pro, …).
- Link detection is now limited to **unambiguous** signals: real URLs (`http(s)://`, `www.`, an `<a>` tag, `[url]` BBCode, IPv4) **and deliberately obfuscated** domains that spell the dot out or bracket it (`example dot com`, `example [dot] com`, `example[.]com`). A plain `word.tld` is no longer treated as a link — genuine link spam almost always uses http/www, and moderation + the word blocklist cover the rest.
- **Verified live:** the false-positive samples (`user@gmail.com`, `asp.net`, `15.pro`, `node.js`, `vue.js`, `shop.online`, `report.pdf`, `25.5`) now score **0**, while real and obfuscated links (`https://…`, `www.…`, `example dot com`, `example[.]com`, `spam [dot] shop`, `<a>`, `[url]`, `1.2.3.4`) are still caught.

### Version 0.29.0 (Appearance & UX polish: star size, no double-output, matching widths, richer Elementor)
- **Star size is now its own setting** (Appearance → Sizes), independent of the base font size, and the stars in submitted reviews are **bigger by default** (18px). Drives a new `--ar-star-size` variable.
- **No more double output.** On an Elementor theme-builder single-post template that both prints the post content (auto-append) and includes our widget, reviews showed **twice**. Rendering a given target now happens at most **once per request** (the de-dup applies whichever runs first), so auto-append and an explicit placement can’t both print.
- **The submission form now matches the reviews’ width.** The max-width cap moved from the form to the whole widget, so the review list and the “write a review” form always share one width (and `Max width = 0` = full width for both). Fixes the form looking narrower than the reviews.
- **Tidier Appearance settings.** Reorganised into clear groups — **Layout** (style + density), **Colors** (a two-column grid of swatches), **Sizes** (star / font / radius sliders) and **Avatar** — with the live preview reflecting the star size.
- **Richer Elementor widget.** The widget’s **Appearance** panel now also exposes per-widget **color** overrides (accent, text-on-accent, star, text, background, borders) and **size** sliders (star, font, radius, max width) via scoped CSS variables — empty = inherit the global Appearance. (These are standard Elementor selector controls, so they appear in the editor.)
- **Widget/shortcode/block assets load anywhere** (carried over): rendering ensures the CSS+JS are enqueued on demand.
- **Verified live:** star size applies (18px) and the form width equals the review width (both 900px at a 1000px viewport); the de-dup renders the target once (second render returns empty; a single container in the page HTML); the Appearance groups render in Persian; Elementor selector controls behave exactly like Elementor’s own (confirmed against the core Heading widget). 26 new strings translated to Persian.

### Version 0.28.0 (Elementor widget modernised — queue item 4/5)
- **The Elementor “Advery Reviews” widget is brought up to date.** New controls: an optional **Heading**, a **target** that can now be the current page or a specific **Post / Product / Taxonomy term** by ID (previously post only), and — in a new **Appearance** control group — per-widget **Layout style** (Use global / Cards / Classic) and **Avatar style** (Use global / Initials / Default image / Gravatar / None) overrides. So a designer can drop reviews anywhere and style that instance without touching the global settings.
- **Widget/shortcode/block now work on any page.** The front-end assets used to load only when the page was itself a review target; rendering now **ensures the stylesheet + script are enqueued on demand** (idempotent, prints in the footer when late), so a widget pointing at a specific product on a custom landing page is styled and interactive.
- **Under the hood:** `Display::render()`/`widget()` accept per-render `skin` / `avatar` overrides (threaded into `Avatar::html()`); the widget renders via the shared `Display::widget()`.
- **Verified live** (Elementor is active on the sample site): the widget is registered in Elementor (title “Advery Reviews”, star icon, General category); the skin/avatar overrides produce the right markup (`--classic` + 0 avatars; `--card` + initials); the widget class loads with no fatal. 6 new strings translated to Persian.

### Version 0.27.0 (WooCommerce product-reviews takeover — queue item 3/5)
- **Optionally show our reviews on WooCommerce products.** A new toggle (Collection → “Take over the product ‘Reviews’ tab”, shown when Woo is active) replaces the content of the Woo product **Reviews** tab with this plugin’s widget — so product reviews render with **our stars and card skin** instead of Woo’s own list — and sets the tab count to our approved review count. Implemented cleanly via the `woocommerce_product_tabs` filter (a new `Integrations\WooTakeover`), feature-detected so it’s completely inert without WooCommerce.
- **No duplicate `aggregateRating` — by design.** The takeover is display-only. Our own schema always leaves products to WooCommerce (both StandaloneSchema and SchemaBridge skip products), so a product page still has exactly one product schema — WooCommerce’s — into which our reviews are folded by `WooSchema` when “Merge WooCommerce native ratings” is on. The setting help points this out so ratings show correctly in search results.
- **Verified** (WooCommerce isn’t installed on the sample site, so via a faithful simulation on the live server): with Woo absent the integration registers with **no fatal**; with Woo simulated, the `reviews` tab is replaced with our callback, the title becomes “Reviews (N)” with our count, and the tab renders our widget HTML. 3 new strings translated to Persian.

### Version 0.26.0 (Modern card front-end skin — queue item 2/5)
- **A modern “card” look for the review widget.** A new **Layout style** choice (Appearance): **Cards** (default) puts each review in its own bordered, rounded, softly-shadowed card with the avatar, the reviewer’s name over the **review date**, and the stars on the trailing edge; **Classic** keeps the previous simple divided list. Inspired by the shared references, but built entirely from the existing `--ar-*` CSS variables, so it follows the site’s Appearance colors/radius and stays theme-friendly and responsive.
- **Review date** is now shown (localised via `date_i18n`), and the avatar + name + date share a tidy header row.
- **Consistent everywhere.** The avatar renderer moved to a shared `Support\Avatar` helper used by both the server-rendered list and the AJAX “load more”/pagination items; the public REST shape now returns the avatar HTML + formatted date, so lazily-loaded reviews look identical to the first page. (Only Gravatar mode still makes an external request.)
- **Verified live** on the RTL sample site: the widget renders as `advery-reviews--card`, each review is a card (border, 8px radius, shadow), the Persian date shows (“آگوست 28, 2026”), and the avatar/meta column/stars lay out correctly. 4 new strings translated to Persian.

### Version 0.25.0 (Instant settings navigation — queue item 1/5)
- **Switching between the admin screens is now instant.** Reviews / Reports / Settings / Migration are still four addressable WP sub-pages (the URL changes, so links and the browser back/forward button keep working), but moving between them no longer does a full page reload. The app now uses History-API client-side routing: the in-app nav (and the header stat tiles) intercept the click, `pushState` the same admin URL, and swap the screen instantly — bootstrap data is fetched once instead of on every switch.
- **Back / forward** buttons restore the right screen (`popstate`), a **direct load / deep link** to any of the four URLs still opens the correct screen, and modified clicks (⌘/Ctrl/middle-click) still open a new tab normally.
- The **WordPress left-sidebar submenu highlight** is kept in sync with the active screen (since `pushState` doesn’t reload, WP wouldn’t otherwise move the “current” mark).
- **Verified live** in wp-admin: switching Reviews↔Settings↔Reports↔Migration does not reload the page (a window marker survives), the URL updates each time, and back/forward + the sidebar highlight track correctly. No new strings.

### Version 0.24.0 (Ollama fix, more AI providers, usage stats, reviewer avatars)
- **Bug fix — selecting Ollama blanked the AI settings.** Switching the provider to Ollama crashed the panel (`removeChild … not a child`) because the guide line mixed several adjacent text nodes (emoji + translated text) that the browser merges, and React then failed to reconcile them. Each guide line is now a single text expression with distinct keys — Ollama (and every provider) selects cleanly.
- **More AI providers.** Added **DeepSeek**, **GapGPT** and **AvalAI** (the last two are popular OpenAI-compatible gateways in Iran) alongside Anthropic/OpenAI/OpenRouter/Gemini/Ollama. They use the OpenAI request format; each has a guide card with the API-key link, example models and docs, and a note that you can point Base URL at a different endpoint. The Model field is free text, so any custom model name works.
- **Usage stats.** The AI Settings tab now shows **AI tasks run** (all-time + today), **tokens** (in+out, read from each provider’s response) and an **estimated cost** from public list prices (0 for local models; clearly labelled “not a billing source of truth”).
- **Reviewer avatars — with a privacy guarantee.** A new *Reviewer avatar* setting (under Appearance): **Initials** (local, default), **One default image**, **Gravatar**, or **None**. Only Gravatar contacts an external service — in every other mode the front end makes **no avatar request at all** (verified live: initials mode issues zero Gravatar requests from the widget; Gravatar mode issues them). Avatars render as a neat circle beside each reviewer.
- **Verified live** in wp-admin: Ollama + all new providers select without crashing; provider adapters instantiate; token/cost accounting is correct; the avatar modes behave as specified. 20 new strings translated to Persian (451 total).

### Version 0.23.0 (CSS class reference, AI split into two tabs, prompt variables)
- **Custom CSS section now has a guide.** Below the CSS box is a reference of the widget’s CSS classes — `.advery-reviews`, `.advery-reviews__summary`, `__stars`, `__item`, `__content`, `__reply`, `__form`, `__submit`, `__loadmore`, `__pager`, etc. — each with a plain-language description of the front-end part it targets, plus a pointer to the Appearance tab for no-code styling.
- **AI section split into two tabs.**
  - **Settings** — provider, key, model, base URL, daily cap, test — now with a **per-provider guide card**: a direct link to get the API key, example model IDs, and a link to the full model list/docs (Anthropic, OpenAI, OpenRouter, Gemini, Ollama). The API-key field hides for Ollama (local, no key).
  - **Tone & prompts** — the business context, the reply-voice-per-content-type toggles, and the per-task prompts, together in one place.
- **Prompt variables.** Prompts can now contain tokens that are filled per review before the call: `{business}`, `{reviewer_name}`, `{rating}`, `{review}`, `{site_name}`, `{site_description}`, `{business_context}`, `{target}`, and **`{field:META_KEY}`** — any custom field of the reviewed post/term (e.g. `{field:phone}`). The Prompts tab lists them all with descriptions; `Tasks::fill()` does the substitution (post meta via `get_post_meta`, term meta via `get_term_meta`). Every task now receives site + object context so variables resolve in any prompt, not just replies.
- **Verified live:** a prompt with `{reviewer_name}`, `{rating}`, `{business}`, `{site_name}`, `{business_context}` and `{field:business_phone}` filled correctly (incl. the custom field). The new UI + all strings render in RTL Persian (40 strings added to the pack).

### Version 0.22.0 (Anti-spam fix: trusted authors can't bypass the link rule + settings layout)
- **Security fix — a trusted author could post links.** The “Auto-approve trusted authors” fast-track (a logged-in visitor with a prior approved review) ran **before** the link check and returned *approve*, so such a user — including the admin — could submit a review containing a link even with “links = reject”. Links are a **content policy**, not a bot heuristic, so the link check now runs **before** the trusted fast-track: `Reject` is a hard block for everyone, and any link hit also disqualifies the fast-track (so `Hold`/`Spam` link actions apply to trusted authors too). Verified live: a trusted admin submitting a link is now rejected, while a clean trusted submission still auto-approves.
- **Settings layout — cleaner, less scrolling.** The two blocklist fields (**blocked words**, **blocked emails**) are now proper **multi-line textareas** (they’re “one per line”), side by side. Short/numeric fields are grouped into responsive rows under small headings — **Links** (max links + action), **Length limits** (min/max review + name), **Score thresholds** (hold + spam), rate-limit (window/max/day), plus Display (per-page + loading mode) and Email (recipient + digest) — instead of a long vertical stack of half-width inputs. On mobile the rows stack. Verified in an RTL preview with the compiled CSS.
- The new/adjusted strings were added to the Persian pack (`.po`/`.mo`/JSON regenerated).

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
