import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	ToggleControl,
	CheckboxControl,
	RadioControl,
	SelectControl,
	TextControl,
	TextareaControl,
	RangeControl,
	Button,
	Notice,
} from '@wordpress/components';
import { api } from '../api';

export default function SettingsPanel( { boot, notify } ) {
	const [ s, setS ] = useState( boot.settings );
	const [ saving, setSaving ] = useState( false );
	const [ busy, setBusy ] = useState( '' );
	const [ active, setActive ] = useState( 'collection' );
	const [ aiTab, setAiTab ] = useState( 'settings' );
	const [ aiTest, setAiTest ] = useState( null );

	const set = ( patch ) => setS( { ...s, ...patch } );
	const as = s.antispam || {};
	const setAs = ( patch ) => set( { antispam: { ...as, ...patch } } );
	const ai = s.ai || {};
	const setAi = ( patch ) => set( { ai: { ...ai, ...patch } } );
	const ap = s.appearance || {};
	const setAp = ( patch ) => set( { appearance: { ...ap, ...patch } } );
	const setAiTask = ( key, patch ) =>
		set( { ai: { ...ai, tasks: { ...( ai.tasks || {} ), [ key ]: { ...( ( ai.tasks || {} )[ key ] || {} ), ...patch } } } } );
	const aiPrompts = ( boot.ai && boot.ai.prompts ) || {};
	const roles = s.roles || {};
	const setRole = ( pt, isListing ) => {
		const next = { ...roles };
		isListing ? ( next[ pt ] = 'listing' ) : delete next[ pt ];
		set( { roles: next } );
	};
	const toggleIn = ( key, slug, on ) => {
		const cur = new Set( s[ key ] || [] );
		on ? cur.add( slug ) : cur.delete( slug );
		set( { [ key ]: Array.from( cur ) } );
	};

	const runAiTest = async () => {
		setAiTest( { busy: true } );
		try {
			const res = await api.ai( 'test' );
			setAiTest( res.ok ? { ok: true, sample: res.sample } : { ok: false, message: res.message } );
		} catch ( e ) {
			setAiTest( { ok: false, message: e.message } );
		}
	};
	const maintenance = async ( action ) => {
		setBusy( action );
		try {
			const res = await api.maintenance( action );
			notify( 'success', action === 'optimize'
				? __( 'Tables optimized.', 'advery-reviews' )
				: __( 'Removed', 'advery-reviews' ) + ' ' + res.removed + ' ' + __( 'orphaned reviews.', 'advery-reviews' ) );
		} catch ( e ) {
			notify( 'error', e.message );
		} finally {
			setBusy( '' );
		}
	};
	const save = async () => {
		setSaving( true );
		try {
			const res = await api.saveSettings( s );
			setS( res.settings );
			notify( 'success', __( 'Settings saved.', 'advery-reviews' ) );
		} catch ( e ) {
			notify( 'error', e.message );
		} finally {
			setSaving( false );
		}
	};

	const sections = [
		{ key: 'collection', title: __( 'Collection', 'advery-reviews' ), icon: '📍' },
		{ key: 'submission', title: __( 'Submission rules', 'advery-reviews' ), icon: '📝' },
		{ key: 'antispam', title: __( 'Anti-spam', 'advery-reviews' ), icon: '🛡️' },
		{ key: 'display', title: __( 'Display', 'advery-reviews' ), icon: '🎨' },
		{ key: 'appearance', title: __( 'Appearance', 'advery-reviews' ), icon: '🎨' },
		{ key: 'ai', title: __( 'AI', 'advery-reviews' ), icon: '🤖' },
		{ key: 'schema', title: __( 'Schema', 'advery-reviews' ), icon: '🔎' },
		{ key: 'email', title: __( 'Email reports', 'advery-reviews' ), icon: '✉️' },
		{ key: 'css', title: __( 'Custom CSS', 'advery-reviews' ), icon: '💅' },
		{ key: 'maint', title: __( 'Maintenance', 'advery-reviews' ), icon: '🧹' },
	];
	const activeSection = sections.find( ( x ) => x.key === active ) || sections[ 0 ];

	const renderSection = () => {
		switch ( active ) {
			case 'collection':
				return (
					<>
						<p className="advery-rv-hint">{ __( 'Tick each post type that should accept reviews — the review form and list then appear on those pages. Example: tick “Post” for a blog, or a “Service” custom type for a services site.', 'advery-reviews' ) }</p>
						{ ( boot.postTypes || [] ).map( ( pt ) => (
							<CheckboxControl key={ pt.slug } label={ `${ pt.label } (${ pt.slug })` } checked={ ( s.enabled_post_types || [] ).includes( pt.slug ) } onChange={ ( on ) => toggleIn( 'enabled_post_types', pt.slug, on ) } __nextHasNoMarginBottom />
						) ) }
						<hr />
						<p className="advery-rv-hint">{ __( 'Tick a taxonomy to allow reviews on its term (archive) pages — e.g. reviewing a whole “Brand” or “City” page. Leave off if not needed.', 'advery-reviews' ) }</p>
						{ ( boot.taxonomies || [] ).map( ( tx ) => (
							<CheckboxControl key={ tx.slug } label={ `${ tx.label } (${ tx.slug })` } checked={ ( s.enabled_taxonomies || [] ).includes( tx.slug ) } onChange={ ( on ) => toggleIn( 'enabled_taxonomies', tx.slug, on ) } __nextHasNoMarginBottom />
						) ) }
						{ boot.wooActive && (
							<>
								<hr />
								<ToggleControl label={ __( 'WooCommerce products', 'advery-reviews' ) } help={ __( 'Collect reviews on your products and read WooCommerce’s own star ratings. Turn off to leave products entirely to WooCommerce.', 'advery-reviews' ) } checked={ !! s.woo_enabled } onChange={ ( v ) => set( { woo_enabled: v } ) } __nextHasNoMarginBottom />
								{ s.woo_enabled && (
									<ToggleControl label={ __( 'Take over the product “Reviews” tab', 'advery-reviews' ) } help={ __( 'Show this plugin’s reviews (with our stars and card skin) in the WooCommerce product Reviews tab instead of Woo’s own list. Schema is unaffected — WooCommerce still owns the product’s structured data, so there’s never a duplicate rating. For the rating to reflect these reviews in search results, also enable “Merge WooCommerce native ratings” under Schema.', 'advery-reviews' ) } checked={ !! s.woo_takeover } onChange={ ( v ) => set( { woo_takeover: v } ) } __nextHasNoMarginBottom />
								) }
							</>
						) }
					</>
				);
			case 'submission':
				return (
					<>
						<RadioControl label={ __( 'Who can submit', 'advery-reviews' ) } help={ __( 'Example: “Anyone” for a public shop (visitors give a name + email); “Logged-in users only” for a members site to cut spam.', 'advery-reviews' ) } selected={ s.who_can_submit } options={ [ { label: __( 'Anyone (name + email)', 'advery-reviews' ), value: 'anyone' }, { label: __( 'Logged-in users only', 'advery-reviews' ), value: 'logged_in' } ] } onChange={ ( v ) => set( { who_can_submit: v } ) } />
						<RadioControl label={ __( 'Moderation', 'advery-reviews' ) } help={ __( 'Manual (default & recommended): a new review waits as “Pending” until you approve it. Auto-approve: published immediately — faster, riskier. AI-assisted: the AI judges each review as approve / needs-review / spam (needs the AI section set up; on any AI error it safely falls back to “Pending”).', 'advery-reviews' ) } selected={ s.moderation } options={ [ { label: __( 'Hold for approval (manual)', 'advery-reviews' ), value: 'manual' }, { label: __( 'Auto-approve', 'advery-reviews' ), value: 'auto' }, { label: __( 'AI-assisted approval', 'advery-reviews' ), value: 'ai' } ] } onChange={ ( v ) => set( { moderation: v } ) } />
						<ToggleControl label={ __( 'One review per user / email', 'advery-reviews' ) } help={ __( 'Stops the same person reviewing one item repeatedly. Matched by their account when logged in, otherwise by email.', 'advery-reviews' ) } checked={ !! s.one_per_user } onChange={ ( v ) => set( { one_per_user: v } ) } __nextHasNoMarginBottom />
						<ToggleControl label={ __( 'Rating required', 'advery-reviews' ) } help={ __( 'On: the visitor must pick a star rating. Off: text-only comments (no stars) are allowed too.', 'advery-reviews' ) } checked={ !! s.rating_required } onChange={ ( v ) => set( { rating_required: v } ) } __nextHasNoMarginBottom />
						<p className="advery-rv-hint">{ __( 'Length limits and injection/link protection are under Anti-spam.', 'advery-reviews' ) }</p>
					</>
				);
			case 'antispam':
				return (
					<>
						<p className="advery-rv-hint">{ __( 'Layered and score-based: each check adds points to a “spam score”, and the two thresholds decide whether a review is held or marked spam. The defaults are sensible.', 'advery-reviews' ) }</p>
						<div className="advery-rv-fields">
							<ToggleControl label={ __( 'Timing check (reject too-fast bot submissions)', 'advery-reviews' ) } help={ __( 'A real person needs a few seconds to write a review; bots submit instantly. Recommended: on.', 'advery-reviews' ) } checked={ !! as.timing_enabled } onChange={ ( v ) => setAs( { timing_enabled: v } ) } __nextHasNoMarginBottom />
							<TextControl type="number" label={ __( 'Minimum seconds to fill the form', 'advery-reviews' ) } help={ __( 'Example: 3. Faster than this is treated as a likely bot.', 'advery-reviews' ) } value={ as.timing_min } onChange={ ( v ) => setAs( { timing_min: parseInt( v, 10 ) || 0 } ) } __nextHasNoMarginBottom />
						</div>
						<div className="advery-rv-fieldgroup-title">{ __( 'Links', 'advery-reviews' ) }</div>
						<div className="advery-rv-fields">
							<TextControl type="number" label={ __( 'Max links allowed in a review (0 = none)', 'advery-reviews' ) } help={ __( 'Recommended: 0.', 'advery-reviews' ) } value={ as.max_links } onChange={ ( v ) => setAs( { max_links: parseInt( v, 10 ) || 0 } ) } __nextHasNoMarginBottom />
							<SelectControl label={ __( 'When over the link limit', 'advery-reviews' ) } value={ as.link_action } options={ [ { label: __( 'Ignore', 'advery-reviews' ), value: 'off' }, { label: __( 'Hold for moderation', 'advery-reviews' ), value: 'hold' }, { label: __( 'Mark as spam', 'advery-reviews' ), value: 'spam' }, { label: __( 'Reject with a message', 'advery-reviews' ), value: 'reject' } ] } onChange={ ( v ) => setAs( { link_action: v } ) } __nextHasNoMarginBottom />
						</div>
						<p className="advery-rv-hint" style={ { marginTop: 0 } }>{ __( 'Detects plain, marked-up and obfuscated links (example.com, 1.2.3.4, [url], “example dot com”) across review, title and name. “Reject” blocks them for everyone; “Spam”/“Hold” keep them hidden for you to review.', 'advery-reviews' ) }</p>
						<div className="advery-rv-fieldgroup-title">{ __( 'Length limits', 'advery-reviews' ) }</div>
						<div className="advery-rv-fields">
							<TextControl type="number" label={ __( 'Minimum review length (characters)', 'advery-reviews' ) } help={ __( 'Example: 10.', 'advery-reviews' ) } value={ as.min_chars } onChange={ ( v ) => setAs( { min_chars: parseInt( v, 10 ) || 0 } ) } __nextHasNoMarginBottom />
							<TextControl type="number" label={ __( 'Maximum review length (characters)', 'advery-reviews' ) } help={ __( 'Example: 1500.', 'advery-reviews' ) } value={ as.max_chars } onChange={ ( v ) => setAs( { max_chars: parseInt( v, 10 ) || 0 } ) } __nextHasNoMarginBottom />
							<TextControl type="number" label={ __( 'Maximum author name length (characters)', 'advery-reviews' ) } help={ __( 'Example: 35.', 'advery-reviews' ) } value={ as.max_name_chars } onChange={ ( v ) => setAs( { max_name_chars: parseInt( v, 10 ) || 1 } ) } __nextHasNoMarginBottom />
						</div>
						<div className="advery-rv-fieldgroup-title">{ __( 'Blocklists', 'advery-reviews' ) }</div>
						<div className="advery-rv-fields advery-rv-fields--wide">
							<TextareaControl rows={ 5 } label={ __( 'Blocked words / phrases (one per line; prefix re: for regex)', 'advery-reviews' ) } help={ __( 'One per line. Example: viagra. Advanced: re:\\bcasino\\b for a regular expression.', 'advery-reviews' ) } value={ as.blocklist_words } onChange={ ( v ) => setAs( { blocklist_words: v } ) } __nextHasNoMarginBottom />
							<TextareaControl rows={ 5 } label={ __( 'Blocked emails / domains (one per line)', 'advery-reviews' ) } help={ __( 'A full address (spammer@bad.com) or a whole domain (bad.ru).', 'advery-reviews' ) } value={ as.blocklist_emails } onChange={ ( v ) => setAs( { blocklist_emails: v } ) } __nextHasNoMarginBottom />
						</div>
						<ToggleControl label={ __( 'Block disposable email domains', 'advery-reviews' ) } help={ __( 'Blocks throwaway inboxes (mailinator.com, 10minutemail.com, …).', 'advery-reviews' ) } checked={ !! as.block_disposable } onChange={ ( v ) => setAs( { block_disposable: v } ) } __nextHasNoMarginBottom />
						<ToggleControl label={ __( 'Reject duplicate content', 'advery-reviews' ) } help={ __( 'Blocks the same review text being posted again on the same item.', 'advery-reviews' ) } checked={ !! as.duplicate_check } onChange={ ( v ) => setAs( { duplicate_check: v } ) } __nextHasNoMarginBottom />
						<ToggleControl label={ __( 'Auto-approve trusted authors', 'advery-reviews' ) } help={ __( 'A logged-in visitor who already has one approved review skips moderation next time (but a blocked link still rejects it).', 'advery-reviews' ) } checked={ !! as.trusted_autoapprove } onChange={ ( v ) => setAs( { trusted_autoapprove: v } ) } __nextHasNoMarginBottom />
						<hr />
						<ToggleControl label={ __( 'Rate limiting', 'advery-reviews' ) } help={ __( 'Caps how many reviews one person/IP can post in a short time.', 'advery-reviews' ) } checked={ !! as.rate_enabled } onChange={ ( v ) => setAs( { rate_enabled: v } ) } __nextHasNoMarginBottom />
						<div className="advery-rv-fields">
							<TextControl type="number" label={ __( 'Window (seconds)', 'advery-reviews' ) } help={ __( 'Example: 600 = 10 minutes.', 'advery-reviews' ) } value={ as.rate_window } onChange={ ( v ) => setAs( { rate_window: parseInt( v, 10 ) || 1 } ) } __nextHasNoMarginBottom />
							<TextControl type="number" label={ __( 'Max submissions per window (per IP/email)', 'advery-reviews' ) } help={ __( 'Example: 3.', 'advery-reviews' ) } value={ as.rate_max } onChange={ ( v ) => setAs( { rate_max: parseInt( v, 10 ) || 1 } ) } __nextHasNoMarginBottom />
							<TextControl type="number" label={ __( 'Max per day (0 = off)', 'advery-reviews' ) } help={ __( 'Example: 20.', 'advery-reviews' ) } value={ as.rate_day_max } onChange={ ( v ) => setAs( { rate_day_max: parseInt( v, 10 ) || 0 } ) } __nextHasNoMarginBottom />
						</div>
						<hr />
						<div className="advery-rv-fieldgroup-title">{ __( 'Score thresholds', 'advery-reviews' ) }</div>
						<div className="advery-rv-fields">
							<TextControl type="number" label={ __( 'Hold threshold (score ≥ ⇒ hold)', 'advery-reviews' ) } help={ __( 'Example: 2.', 'advery-reviews' ) } value={ as.hold_threshold } onChange={ ( v ) => setAs( { hold_threshold: parseInt( v, 10 ) || 1 } ) } __nextHasNoMarginBottom />
							<TextControl type="number" label={ __( 'Spam threshold (score ≥ ⇒ spam)', 'advery-reviews' ) } help={ __( 'Example: 5 (one strong signal like a blocked word).', 'advery-reviews' ) } value={ as.spam_threshold } onChange={ ( v ) => setAs( { spam_threshold: parseInt( v, 10 ) || 1 } ) } __nextHasNoMarginBottom />
						</div>
						<hr />
						<SelectControl label={ __( 'CAPTCHA provider', 'advery-reviews' ) } help={ __( 'Optional. hCaptcha & Cloudflare Turnstile are free and privacy-friendly; reCAPTCHA is Google’s. Keys come from the provider dashboard. Not required — other layers already protect you.', 'advery-reviews' ) } value={ as.captcha_provider } options={ [ { label: __( 'None', 'advery-reviews' ), value: 'none' }, { label: 'reCAPTCHA v3', value: 'recaptcha_v3' }, { label: 'reCAPTCHA v2', value: 'recaptcha_v2' }, { label: 'hCaptcha', value: 'hcaptcha' }, { label: 'Cloudflare Turnstile', value: 'turnstile' } ] } onChange={ ( v ) => setAs( { captcha_provider: v } ) } __nextHasNoMarginBottom />
						{ as.captcha_provider !== 'none' && (
							<>
								<TextControl label={ __( 'Site key', 'advery-reviews' ) } help={ __( 'The public key from your CAPTCHA dashboard.', 'advery-reviews' ) } value={ as.captcha_site_key } onChange={ ( v ) => setAs( { captcha_site_key: v } ) } __nextHasNoMarginBottom />
								<TextControl label={ __( 'Secret key', 'advery-reviews' ) } help={ __( 'The private key (used server-side; never shown to visitors).', 'advery-reviews' ) } value={ as.captcha_secret_key } onChange={ ( v ) => setAs( { captcha_secret_key: v } ) } __nextHasNoMarginBottom />
								{ as.captcha_provider === 'recaptcha_v3' && (
									<TextControl type="number" step="0.1" label={ __( 'reCAPTCHA v3 score threshold (0–1)', 'advery-reviews' ) } help={ __( 'Reject below this. Example: 0.5.', 'advery-reviews' ) } value={ as.captcha_threshold } onChange={ ( v ) => setAs( { captcha_threshold: parseFloat( v ) || 0 } ) } __nextHasNoMarginBottom />
								) }
							</>
						) }
						<ToggleControl label={ __( 'Use Akismet as an extra signal (if configured)', 'advery-reviews' ) } help={ __( 'If Akismet is installed with an API key, its verdict is one more spam signal.', 'advery-reviews' ) } checked={ !! as.akismet_enabled } onChange={ ( v ) => setAs( { akismet_enabled: v } ) } __nextHasNoMarginBottom />
					</>
				);
			case 'display':
				return (
					<>
						<ToggleControl label={ __( 'Automatically append to content', 'advery-reviews' ) } help={ __( 'On: the widget is added after the content of enabled post types. Off: place it yourself with the [advery_reviews] shortcode, the block, or the Elementor widget.', 'advery-reviews' ) } checked={ !! s.auto_append } onChange={ ( v ) => set( { auto_append: v } ) } __nextHasNoMarginBottom />
						<div className="advery-rv-fields advery-rv-fields--wide">
							<TextControl type="number" label={ __( 'Reviews shown per page', 'advery-reviews' ) } help={ __( 'Example: 10. With “Load more”/pagination, the rest load on demand.', 'advery-reviews' ) } value={ s.reviews_per_page } onChange={ ( v ) => set( { reviews_per_page: parseInt( v, 10 ) || 10 } ) } __nextHasNoMarginBottom />
							<SelectControl label={ __( 'Loading mode', 'advery-reviews' ) } help={ __( 'All = at once. Load more = a button. Pagination = numbered pages. The URL never changes and the first page is server-rendered, so SEO is unaffected.', 'advery-reviews' ) } value={ s.load_mode } options={ [ { label: __( 'All on one page', 'advery-reviews' ), value: 'all' }, { label: __( '“Load more” button (AJAX)', 'advery-reviews' ), value: 'load_more' }, { label: __( 'Numbered pagination (AJAX)', 'advery-reviews' ), value: 'paginate' } ] } onChange={ ( v ) => set( { load_mode: v } ) } __nextHasNoMarginBottom />
						</div>
						<ToggleControl label={ __( 'Replace the theme’s native comments with reviews', 'advery-reviews' ) } help={ __( 'Takes over the comments area on enabled post types — no theme editing or page builder needed. WooCommerce products are never taken over.', 'advery-reviews' ) } checked={ !! s.replace_comments } onChange={ ( v ) => set( { replace_comments: v } ) } __nextHasNoMarginBottom />
					</>
				);
			case 'appearance': {
				const colorRow = ( key, label, help, fallback ) => (
					<div className="advery-rv-color">
						<input
							type="color"
							className="advery-rv-color__swatch"
							value={ /^#[0-9a-fA-F]{6}$/.test( ap[ key ] || '' ) ? ap[ key ] : fallback }
							onChange={ ( e ) => setAp( { [ key ]: e.target.value } ) }
							aria-label={ label }
						/>
						<TextControl
							label={ label }
							help={ help }
							value={ ap[ key ] || '' }
							onChange={ ( v ) => setAp( { [ key ]: v } ) }
							__nextHasNoMarginBottom
						/>
					</div>
				);
				const density = ap.density || 'comfortable';
				const pad = density === 'compact' ? '0.9em' : '1.2em';
				return (
					<>
						<p className="advery-rv-hint">{ __( 'Style the front-end review widget to match your theme — all live via CSS variables, no coding. Leave a color blank to inherit the theme’s own color.', 'advery-reviews' ) }</p>

						<div className="advery-rv-fieldgroup-title">{ __( 'Layout', 'advery-reviews' ) }</div>
						<div className="advery-rv-fields advery-rv-fields--wide">
							<SelectControl label={ __( 'Layout style', 'advery-reviews' ) } help={ __( 'Cards = each review in its own bordered card (modern). Classic = a simple divided list.', 'advery-reviews' ) } value={ ap.skin || 'card' } options={ [ { label: __( 'Cards (modern)', 'advery-reviews' ), value: 'card' }, { label: __( 'Classic list', 'advery-reviews' ), value: 'classic' } ] } onChange={ ( v ) => setAp( { skin: v } ) } __nextHasNoMarginBottom />
							<SelectControl label={ __( 'Density', 'advery-reviews' ) } help={ __( 'Comfortable = roomier. Compact = tighter.', 'advery-reviews' ) } value={ density } options={ [ { label: __( 'Comfortable', 'advery-reviews' ), value: 'comfortable' }, { label: __( 'Compact', 'advery-reviews' ), value: 'compact' } ] } onChange={ ( v ) => setAp( { density: v } ) } __nextHasNoMarginBottom />
						</div>

						<div className="advery-rv-fieldgroup-title">{ __( 'Colors', 'advery-reviews' ) }</div>
						<div className="advery-rv-color-grid">
							{ colorRow( 'accent', __( 'Accent (buttons, links)', 'advery-reviews' ), __( 'Submit button, “load more”, active page. e.g. #2271b1.', 'advery-reviews' ), '#2271b1' ) }
							{ colorRow( 'accent_ink', __( 'Text on the accent', 'advery-reviews' ), __( 'Text/icon on top of the accent. Usually white.', 'advery-reviews' ), '#ffffff' ) }
							{ colorRow( 'star', __( 'Star color', 'advery-reviews' ), __( 'The rating stars. e.g. #f5a623.', 'advery-reviews' ), '#f5a623' ) }
							{ colorRow( 'text', __( 'Text (blank = theme)', 'advery-reviews' ), __( 'Body text of reviews. Blank inherits the theme.', 'advery-reviews' ), '#1f2937' ) }
							{ colorRow( 'surface', __( 'Card / form background', 'advery-reviews' ), __( 'Blank keeps a subtle default.', 'advery-reviews' ), '#f7f8fa' ) }
							{ colorRow( 'border', __( 'Borders & dividers', 'advery-reviews' ), __( 'Blank keeps a subtle default.', 'advery-reviews' ), '#e5e7eb' ) }
						</div>

						<div className="advery-rv-fieldgroup-title">{ __( 'Sizes', 'advery-reviews' ) }</div>
						<div className="advery-rv-fields">
							<RangeControl label={ __( 'Star size (px)', 'advery-reviews' ) } value={ Number( ap.star_size ?? 18 ) } min={ 12 } max={ 40 } onChange={ ( v ) => setAp( { star_size: v } ) } __nextHasNoMarginBottom __next40pxDefaultSize />
							<RangeControl label={ __( 'Base font size (px)', 'advery-reviews' ) } value={ Number( ap.font_size ?? 15 ) } min={ 12 } max={ 20 } onChange={ ( v ) => setAp( { font_size: v } ) } __nextHasNoMarginBottom __next40pxDefaultSize />
							<RangeControl label={ __( 'Corner radius (px)', 'advery-reviews' ) } value={ Number( ap.radius ?? 8 ) } min={ 0 } max={ 40 } onChange={ ( v ) => setAp( { radius: v } ) } __nextHasNoMarginBottom __next40pxDefaultSize />
						</div>
						<TextControl type="number" label={ __( 'Max width (px, 0 = full width)', 'advery-reviews' ) } help={ __( 'Caps the whole widget so the reviews and the form share one width. 0 = fill the container.', 'advery-reviews' ) } value={ ap.max_width ?? 0 } onChange={ ( v ) => setAp( { max_width: parseInt( v, 10 ) || 0 } ) } __nextHasNoMarginBottom />

						<hr />
						<div className="advery-rv-fieldgroup-title">{ __( 'Reviewer avatar', 'advery-reviews' ) }</div>
						<SelectControl label={ __( 'Avatar style', 'advery-reviews' ) } help={ __( 'How each reviewer’s picture shows. “Gravatar” is the only option that contacts an external service — with the others the front end makes no avatar request at all.', 'advery-reviews' ) } value={ s.avatar_mode || 'initials' } options={ [ { label: __( 'Initials (local, no request)', 'advery-reviews' ), value: 'initials' }, { label: __( 'One default image', 'advery-reviews' ), value: 'default' }, { label: __( 'Gravatar (external request)', 'advery-reviews' ), value: 'gravatar' }, { label: __( 'No avatar', 'advery-reviews' ), value: 'none' } ] } onChange={ ( v ) => set( { avatar_mode: v } ) } __nextHasNoMarginBottom />
						{ s.avatar_mode === 'default' && (
							<TextControl label={ __( 'Default avatar image URL', 'advery-reviews' ) } help={ __( 'Shown for every reviewer. Example: https://your-site.com/wp-content/uploads/avatar.png', 'advery-reviews' ) } value={ s.avatar_default || '' } onChange={ ( v ) => set( { avatar_default: v } ) } __nextHasNoMarginBottom />
						) }
						{ s.avatar_mode === 'gravatar' && (
							<p className="advery-rv-hint">{ __( 'Note: Gravatar loads reviewer images from gravatar.com by email — an external request on every review. Account photos in WordPress are served through Gravatar too.', 'advery-reviews' ) }</p>
						) }
						<hr />
						<strong>{ __( 'Live preview', 'advery-reviews' ) }</strong>
						<div
							className="advery-rv-appearance-preview"
							style={ {
								marginTop: 10,
								border: '1px solid ' + ( ap.border || '#e5e7eb' ),
								background: ap.surface || '#ffffff',
								borderRadius: ( ap.radius ?? 8 ) + 'px',
								padding: pad,
								color: ap.text || 'inherit',
								fontSize: ( ap.font_size ?? 15 ) + 'px',
								maxWidth: ap.max_width ? ap.max_width + 'px' : '100%',
							} }
						>
							<div style={ { display: 'flex', alignItems: 'center', gap: 8, marginBottom: 12, paddingBottom: 8, borderBottom: '1px solid ' + ( ap.border || '#e5e7eb' ) } }>
								<span style={ { color: ap.star || '#f5a623', letterSpacing: 1, fontSize: ( ap.star_size ?? 18 ) + 'px', lineHeight: 1 } }>★★★★★</span>
								<strong>4.8</strong>
								<span style={ { opacity: 0.7 } }>{ sprintf( __( '%d reviews', 'advery-reviews' ), 3 ) }</span>
							</div>
							<div style={ ( ap.skin || 'card' ) === 'card' ? { border: '1px solid ' + ( ap.border || '#e5e7eb' ), borderRadius: ( ap.radius ?? 8 ) + 'px', padding: '0.9em 1em', marginBottom: 12, boxShadow: '0 1px 3px rgba(0,0,0,0.05)' } : { marginBottom: 12 } }>
								<div style={ { display: 'flex', alignItems: 'center', gap: 8, marginBottom: 6 } }>
									<span style={ { flex: '0 0 auto', width: 34, height: 34, borderRadius: '50%', background: ap.surface || '#f7f8fa', border: '1px solid ' + ( ap.border || '#e5e7eb' ), color: ap.accent || '#2271b1', display: 'inline-flex', alignItems: 'center', justifyContent: 'center', fontWeight: 700 } }>N</span>
									<span style={ { display: 'flex', flexDirection: 'column', lineHeight: 1.2 } }>
										<strong>{ __( 'Your name', 'advery-reviews' ) }</strong>
										<span style={ { fontSize: '0.8em', opacity: 0.7 } }>Aug 30, 2026</span>
									</span>
									<span style={ { marginInlineStart: 'auto', color: ap.star || '#f5a623', fontSize: ( ap.star_size ?? 18 ) + 'px', lineHeight: 1 } }>★★★★★</span>
								</div>
								<p style={ { margin: 0 } }>{ __( 'Absolutely wonderful experience — the team was friendly and helpful.', 'advery-reviews' ) }</p>
							</div>
							<button
								type="button"
								style={ {
									background: ap.accent || '#2271b1',
									color: ap.accent_ink || '#ffffff',
									border: 'none',
									borderRadius: ( ap.radius ?? 8 ) + 'px',
									padding: '0.55em 1.3em',
									cursor: 'default',
								} }
							>{ __( 'Submit review', 'advery-reviews' ) }</button>
						</div>
					</>
				);
			}
			case 'ai': {
				const guides = {
					anthropic: { keyUrl: 'https://console.anthropic.com/settings/keys', docs: 'https://docs.anthropic.com/en/docs/about-claude/models', models: 'claude-sonnet-4-5, claude-3-5-haiku-latest' },
					openai: { keyUrl: 'https://platform.openai.com/api-keys', docs: 'https://platform.openai.com/docs/models', models: 'gpt-4o-mini, gpt-4o' },
					openrouter: { keyUrl: 'https://openrouter.ai/keys', docs: 'https://openrouter.ai/models', models: 'anthropic/claude-3.5-sonnet, openai/gpt-4o-mini' },
					gemini: { keyUrl: 'https://aistudio.google.com/app/apikey', docs: 'https://ai.google.dev/gemini-api/docs/models/gemini', models: 'gemini-1.5-flash, gemini-1.5-pro' },
					deepseek: { keyUrl: 'https://platform.deepseek.com/api_keys', docs: 'https://api-docs.deepseek.com/quick_start/pricing', models: 'deepseek-chat, deepseek-reasoner' },
					gapgpt: { keyUrl: 'https://gapgpt.app', docs: 'https://docs.gapgpt.app', models: 'gpt-4o-mini, gpt-4o, claude-3-5-sonnet', note: true },
					avalai: { keyUrl: 'https://avalai.ir', docs: 'https://docs.avalai.ir', models: 'gpt-4o-mini, gpt-4o, gemini-1.5-flash', note: true },
					ollama: { local: true, docs: 'https://ollama.com/library', models: 'llama3.1, mistral, qwen2.5' },
				};
				const g = guides[ ai.provider ] || guides.anthropic;
				const variables = ( boot.ai && boot.ai.variables ) || {};
				const usage = ( boot.ai && boot.ai.usage ) || {};
				return (
					<>
						<p className="advery-rv-hint">{ __( 'AI works on REAL reviews only — drafting your replies, assisting moderation, translating and summarizing. It never generates fake reviews.', 'advery-reviews' ) }</p>
						<div className="advery-rv-subtabs">
							<button type="button" className={ 'advery-rv-subtab' + ( aiTab === 'settings' ? ' is-active' : '' ) } onClick={ () => setAiTab( 'settings' ) }>{ __( 'Settings', 'advery-reviews' ) }</button>
							<button type="button" className={ 'advery-rv-subtab' + ( aiTab === 'prompts' ? ' is-active' : '' ) } onClick={ () => setAiTab( 'prompts' ) }>{ __( 'Tone & prompts', 'advery-reviews' ) }</button>
						</div>

						{ aiTab === 'settings' && (
							<>
								<SelectControl label={ __( 'Provider', 'advery-reviews' ) } value={ ai.provider } options={ [ { label: 'Anthropic (Claude)', value: 'anthropic' }, { label: 'OpenAI', value: 'openai' }, { label: 'OpenRouter', value: 'openrouter' }, { label: 'Google Gemini', value: 'gemini' }, { label: 'DeepSeek', value: 'deepseek' }, { label: 'GapGPT', value: 'gapgpt' }, { label: 'AvalAI', value: 'avalai' }, { label: 'Ollama (self-hosted)', value: 'ollama' } ] } onChange={ ( v ) => setAi( { provider: v } ) } __nextHasNoMarginBottom />
								<div className="advery-rv-guide">
									{ g.local ? (
										<p key="local">{ '🖥️ ' + __( 'Ollama runs locally — no API key needed. Install a model, then put its name in the Model field.', 'advery-reviews' ) }</p>
									) : (
										<p key="keyline">{ '🔑 ' + __( 'Get your API key:', 'advery-reviews' ) + ' ' }<a href={ g.keyUrl } target="_blank" rel="noreferrer">{ g.keyUrl }</a></p>
									) }
									<p key="models">{ '💡 ' + __( 'Example models:', 'advery-reviews' ) + ' ' }<code>{ g.models }</code></p>
									<p key="docs">{ '📚 ' + __( 'Full model list & docs:', 'advery-reviews' ) + ' ' }<a href={ g.docs } target="_blank" rel="noreferrer">{ g.docs }</a></p>
									{ g.note && <p key="note" className="advery-rv-hint" style={ { margin: '4px 0 0' } }>{ __( 'OpenAI-compatible gateway. If the endpoint differs from the default, set it in Base URL below.', 'advery-reviews' ) }</p> }
								</div>
								<TextControl type="password" label={ __( 'API key', 'advery-reviews' ) } help={ ai.provider === 'ollama' ? __( 'Not needed for Ollama (local). Leave blank.', 'advery-reviews' ) : __( 'Paste the secret key from your provider’s dashboard (link above). Stored on your server only.', 'advery-reviews' ) } value={ ai.api_key } onChange={ ( v ) => setAi( { api_key: v } ) } __nextHasNoMarginBottom />
								<div className="advery-rv-fields advery-rv-fields--wide">
									<TextControl label={ __( 'Model (blank = provider default)', 'advery-reviews' ) } help={ sprintf( __( 'e.g. %s — any model name your provider supports.', 'advery-reviews' ), g.models.split( ',' )[ 0 ] ) } value={ ai.model } onChange={ ( v ) => setAi( { model: v } ) } __nextHasNoMarginBottom />
									<TextControl type="number" label={ __( 'Daily call limit (0 = unlimited)', 'advery-reviews' ) } help={ __( 'Caps AI cost. Example: 200/day.', 'advery-reviews' ) } value={ ai.daily_cap } onChange={ ( v ) => setAi( { daily_cap: parseInt( v, 10 ) || 0 } ) } __nextHasNoMarginBottom />
								</div>
								<TextControl label={ __( 'Base URL (optional override)', 'advery-reviews' ) } help={ __( 'Only for self-hosted / proxy / OpenAI-compatible endpoints. Example (Ollama): http://localhost:11434/v1.', 'advery-reviews' ) } value={ ai.base_url } onChange={ ( v ) => setAi( { base_url: v } ) } __nextHasNoMarginBottom />
								<div style={ { marginTop: 12 } }>
									<Button variant="secondary" isBusy={ aiTest && aiTest.busy } onClick={ runAiTest }>{ __( 'Save, then test the connection', 'advery-reviews' ) }</Button>
									<p className="advery-rv-hint">{ __( 'Click Save first, then this drafts a sample reply to confirm your key/model work.', 'advery-reviews' ) }</p>
								</div>
								{ aiTest && ! aiTest.busy && (
									<Notice status={ aiTest.ok ? 'success' : 'error' } isDismissible onRemove={ () => setAiTest( null ) }>
										{ aiTest.ok ? __( 'Working. Sample reply: ', 'advery-reviews' ) + aiTest.sample : aiTest.message }
									</Notice>
								) }
								<div className="advery-rv-usage">
									<strong>{ __( 'Usage', 'advery-reviews' ) }</strong>
									<div className="advery-rv-usage__grid">
										<div><span>{ usage.calls_total || 0 }</span><label>{ __( 'AI tasks run', 'advery-reviews' ) }</label></div>
										<div><span>{ usage.today || 0 }</span><label>{ __( 'today', 'advery-reviews' ) }</label></div>
										<div><span>{ ( ( usage.tokens_in || 0 ) + ( usage.tokens_out || 0 ) ).toLocaleString() }</span><label>{ __( 'tokens (in+out)', 'advery-reviews' ) }</label></div>
										<div><span>≈ ${ ( usage.cost_total || 0 ).toFixed( 3 ) }</span><label>{ __( 'estimated cost', 'advery-reviews' ) }</label></div>
									</div>
									<p className="advery-rv-hint" style={ { marginTop: 6 } }>{ __( 'Tokens come from the provider’s response; cost is an estimate from public list prices (0 for local models). Not a billing source of truth.', 'advery-reviews' ) }</p>
								</div>
							</>
						) }

						{ aiTab === 'prompts' && (
							<>
								<TextareaControl label={ __( 'About your business (context for replies)', 'advery-reviews' ) } help={ __( 'Optional. Example: “Family-run bakery in Toronto, open since 2015, known for sourdough.” Available in prompts as {business_context}.', 'advery-reviews' ) } rows={ 3 } value={ ai.business_context || '' } onChange={ ( v ) => setAi( { business_context: v } ) } __nextHasNoMarginBottom />
								<div className="advery-rv-vars">
									<strong>{ __( 'Variables you can use in any prompt', 'advery-reviews' ) }</strong>
									<p className="advery-rv-hint" style={ { marginTop: 2 } }>{ __( 'Type these tokens into a prompt and they’re replaced for each review before it’s sent to the AI.', 'advery-reviews' ) }</p>
									<ul>
										{ Object.keys( variables ).map( ( tok ) => (
											<li key={ tok }><code>{ tok }</code> <span>— { variables[ tok ] }</span></li>
										) ) }
									</ul>
								</div>
								<hr />
								<strong>{ __( 'Reply voice per content type', 'advery-reviews' ) }</strong>
								<p className="advery-rv-hint">{ __( 'If a content type is a DIRECTORY of other businesses (you are not the business), tick it — AI replies then speak as the platform, never on the business’s behalf. Leave OFF for your own products/services.', 'advery-reviews' ) }</p>
								{ ( boot.postTypes || [] ).map( ( pt ) => (
									<CheckboxControl key={ pt.slug } label={ sprintf( __( '“%1$s” is a third-party directory listing', 'advery-reviews' ), pt.label ) } checked={ roles[ pt.slug ] === 'listing' } onChange={ ( on ) => setRole( pt.slug, on ) } __nextHasNoMarginBottom />
								) ) }
								<hr />
								<p className="advery-rv-hint">{ __( 'Turn each task on/off and optionally replace its prompt (the grey placeholder is the built-in default). Use the variables above.', 'advery-reviews' ) }</p>
								{ [ [ 'reply', __( 'Reply drafting', 'advery-reviews' ) ], [ 'moderate', __( 'Moderation assist', 'advery-reviews' ) ], [ 'translate', __( 'Translate', 'advery-reviews' ) ], [ 'summarize', __( 'Summarize', 'advery-reviews' ) ] ].map( ( [ key, label ] ) => (
									<div key={ key } style={ { borderTop: '1px solid #eef0f3', paddingTop: 8, marginTop: 8 } }>
										<ToggleControl label={ label } checked={ !! ( ai.tasks && ai.tasks[ key ] && ai.tasks[ key ].enabled ) } onChange={ ( v ) => setAiTask( key, { enabled: v } ) } __nextHasNoMarginBottom />
										<TextareaControl label={ __( 'Prompt (blank = built-in default)', 'advery-reviews' ) } placeholder={ aiPrompts[ key ] || '' } rows={ 3 } value={ ( ai.tasks && ai.tasks[ key ] && ai.tasks[ key ].prompt ) || '' } onChange={ ( v ) => setAiTask( key, { prompt: v } ) } __nextHasNoMarginBottom />
									</div>
								) ) }
							</>
						) }
					</>
				);
			}
			case 'schema':
				return (
					<>
						<p className="advery-rv-hint">{ __( 'Outputs star-rating structured data so Google can show rating stars in search results. Works with or without the Advery Schema Plus plugin.', 'advery-reviews' ) }</p>
						<ToggleControl label={ __( 'Enable rating/review schema', 'advery-reviews' ) } help={ __( 'Master switch for the JSON-LD output below.', 'advery-reviews' ) } checked={ !! s.schema_output } onChange={ ( v ) => set( { schema_output: v } ) } __nextHasNoMarginBottom />
						{ s.schema_output && (
							<>
								<SelectControl label={ __( 'How to output schema', 'advery-reviews' ) } help={ boot.coreActive ? __( 'Auto (recommended): uses Advery Schema Plus (detected) so ratings merge into your page’s connected @graph. Standalone: prints its own JSON-LD. Core-only: only via Advery Schema Plus. Off: none.', 'advery-reviews' ) : __( 'Auto/Standalone: prints its own JSON-LD (Advery Schema Plus not installed, which is fine). Example: leave on “Auto”.', 'advery-reviews' ) } value={ s.schema_mode } options={ [ { label: __( 'Auto — core if present, else standalone', 'advery-reviews' ), value: 'auto' }, { label: __( 'Standalone — always our own JSON-LD', 'advery-reviews' ), value: 'standalone' }, { label: __( 'Core only — via Advery Schema Plus', 'advery-reviews' ), value: 'core' }, { label: __( 'Off', 'advery-reviews' ), value: 'off' } ] } onChange={ ( v ) => set( { schema_mode: v } ) } __nextHasNoMarginBottom />
								{ ( s.schema_mode === 'standalone' || ( s.schema_mode === 'auto' && ! boot.coreActive ) ) && (
									<TextControl label={ __( 'Standalone @type for non-product items', 'advery-reviews' ) } help={ __( 'Examples: LocalBusiness, Service, Product, Organization, Book, Recipe. Products always use “Product”.', 'advery-reviews' ) } value={ s.schema_type } onChange={ ( v ) => set( { schema_type: v } ) } __nextHasNoMarginBottom />
								) }
								{ s.schema_mode === 'core' && ! boot.coreActive && (
									<Notice status="warning" isDismissible={ false }>{ __( 'Core-only is selected but Advery Schema Plus is not active — no schema will be output. Switch to Auto or Standalone.', 'advery-reviews' ) }</Notice>
								) }
							</>
						) }
						{ boot.wooActive && (
							<ToggleControl label={ __( 'Merge WooCommerce native ratings into the aggregate', 'advery-reviews' ) } help={ __( 'Combines your collected product reviews with WooCommerce’s own ratings into one aggregateRating in Woo’s product schema.', 'advery-reviews' ) } checked={ !! s.woo_merge_native } onChange={ ( v ) => set( { woo_merge_native: v } ) } __nextHasNoMarginBottom />
						) }
					</>
				);
			case 'email':
				return (
					<>
						<ToggleControl label={ __( 'Email me instantly on each new review', 'advery-reviews' ) } help={ __( 'Sends a notification the moment a review is submitted (spam is not emailed).', 'advery-reviews' ) } checked={ !! s.email_instant } onChange={ ( v ) => set( { email_instant: v } ) } __nextHasNoMarginBottom />
						<div className="advery-rv-fields advery-rv-fields--wide">
							<TextControl label={ __( 'Recipient email (blank = site admin)', 'advery-reviews' ) } help={ __( 'Example: reviews@yoursite.com. Blank uses your WordPress admin email.', 'advery-reviews' ) } value={ s.email_recipient } onChange={ ( v ) => set( { email_recipient: v } ) } __nextHasNoMarginBottom />
							<SelectControl label={ __( 'Digest email', 'advery-reviews' ) } help={ __( 'An optional summary of new reviews, sent on a schedule (via WP-Cron).', 'advery-reviews' ) } value={ s.digest_frequency } options={ [ { label: __( 'Off', 'advery-reviews' ), value: 'off' }, { label: __( 'Weekly', 'advery-reviews' ), value: 'weekly' }, { label: __( 'Monthly', 'advery-reviews' ), value: 'monthly' } ] } onChange={ ( v ) => set( { digest_frequency: v } ) } __nextHasNoMarginBottom />
						</div>
					</>
				);
			case 'css': {
				const cssClasses = [
					[ '.advery-reviews', __( 'The whole widget container', 'advery-reviews' ) ],
					[ '.advery-reviews__summary', __( 'The rating summary bar (average + stars + count)', 'advery-reviews' ) ],
					[ '.advery-reviews__avg', __( 'The big average-rating number', 'advery-reviews' ) ],
					[ '.advery-reviews__stars', __( 'A row of stars (in the summary and each review)', 'advery-reviews' ) ],
					[ '.advery-reviews__count', __( 'The “N reviews” text', 'advery-reviews' ) ],
					[ '.advery-reviews__list', __( 'The list that holds all reviews', 'advery-reviews' ) ],
					[ '.advery-reviews__item', __( 'A single review', 'advery-reviews' ) ],
					[ '.advery-reviews__author', __( 'A reviewer’s name', 'advery-reviews' ) ],
					[ '.advery-reviews__title', __( 'A review’s title', 'advery-reviews' ) ],
					[ '.advery-reviews__content', __( 'A review’s body text', 'advery-reviews' ) ],
					[ '.advery-reviews__reply', __( 'The owner’s reply block under a review', 'advery-reviews' ) ],
					[ '.advery-reviews__form', __( 'The “write a review” form', 'advery-reviews' ) ],
					[ '.advery-reviews__star-btn', __( 'The clickable rating stars in the form', 'advery-reviews' ) ],
					[ '.advery-reviews__submit', __( 'The submit button', 'advery-reviews' ) ],
					[ '.advery-reviews__loadmore', __( 'The “load more” button', 'advery-reviews' ) ],
					[ '.advery-reviews__pager', __( 'The numbered pagination bar', 'advery-reviews' ) ],
				];
				return (
					<>
						<TextareaControl label={ __( 'Custom CSS', 'advery-reviews' ) } help={ __( 'Advanced overrides, printed wherever the widget shows. For colors, radius and spacing prefer the Appearance tab (no code). Example: .advery-reviews__stars { color: #e11; }', 'advery-reviews' ) } value={ s.custom_css } rows={ 8 } onChange={ ( v ) => set( { custom_css: v } ) } __nextHasNoMarginBottom />
						<div className="advery-rv-cssref">
							<strong>{ __( 'Widget CSS classes', 'advery-reviews' ) }</strong>
							<p className="advery-rv-hint" style={ { marginTop: 2 } }>{ __( 'Target these classes in your CSS above. Each maps to a part of the front-end widget.', 'advery-reviews' ) }</p>
							<dl>
								{ cssClasses.map( ( [ cls, desc ] ) => (
									<div key={ cls } className="advery-rv-cssref__row">
										<dt><code>{ cls }</code></dt>
										<dd>{ desc }</dd>
									</div>
								) ) }
							</dl>
						</div>
					</>
				);
			}
			case 'maint':
				return (
					<>
						<p className="advery-rv-hint">{ __( 'Reviews are removed automatically when their post/term is deleted. “Purge” removes reviews whose target no longer exists; “Optimize” compacts the database tables.', 'advery-reviews' ) }</p>
						<Button variant="secondary" isBusy={ busy === 'purge' } disabled={ !! busy } onClick={ () => maintenance( 'purge' ) }>{ __( 'Purge orphaned reviews', 'advery-reviews' ) }</Button>
						{ ' ' }
						<Button variant="secondary" isBusy={ busy === 'optimize' } disabled={ !! busy } onClick={ () => maintenance( 'optimize' ) }>{ __( 'Optimize tables', 'advery-reviews' ) }</Button>
					</>
				);
			default:
				return null;
		}
	};

	const showSave = active !== 'maint';

	return (
		<div className="advery-rv-settings">
			<aside className="advery-rv-settings__nav" aria-label={ __( 'Settings sections', 'advery-reviews' ) }>
				{ sections.map( ( sec ) => (
					<button
						key={ sec.key }
						className={ 'advery-rv-settings__navitem' + ( active === sec.key ? ' is-active' : '' ) }
						onClick={ () => setActive( sec.key ) }
					>
						<span className="advery-rv-settings__navicon" aria-hidden="true">{ sec.icon }</span>
						{ sec.title }
					</button>
				) ) }
			</aside>

			<section className="advery-rv-settings__pane">
				<h2 className="advery-rv-settings__title">{ activeSection.title }</h2>
				{ renderSection() }
				{ showSave && (
					<div className="advery-rv-save">
						<Button variant="primary" isBusy={ saving } disabled={ saving } onClick={ save }>
							{ __( 'Save settings', 'advery-reviews' ) }
						</Button>
					</div>
				) }
			</section>
		</div>
	);
}
