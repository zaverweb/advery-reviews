import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	ToggleControl,
	CheckboxControl,
	RadioControl,
	SelectControl,
	TextControl,
	TextareaControl,
	Button,
	Notice,
} from '@wordpress/components';
import { api } from '../api';

export default function SettingsPanel( { boot, notify } ) {
	const [ s, setS ] = useState( boot.settings );
	const [ saving, setSaving ] = useState( false );
	const [ busy, setBusy ] = useState( '' );
	const [ active, setActive ] = useState( 'collection' );
	const [ aiTest, setAiTest ] = useState( null );

	const set = ( patch ) => setS( { ...s, ...patch } );
	const as = s.antispam || {};
	const setAs = ( patch ) => set( { antispam: { ...as, ...patch } } );
	const ai = s.ai || {};
	const setAi = ( patch ) => set( { ai: { ...ai, ...patch } } );
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
							</>
						) }
					</>
				);
			case 'submission':
				return (
					<>
						<RadioControl label={ __( 'Who can submit', 'advery-reviews' ) } help={ __( 'Example: “Anyone” for a public shop (visitors give a name + email); “Logged-in users only” for a members site to cut spam.', 'advery-reviews' ) } selected={ s.who_can_submit } options={ [ { label: __( 'Anyone (name + email)', 'advery-reviews' ), value: 'anyone' }, { label: __( 'Logged-in users only', 'advery-reviews' ), value: 'logged_in' } ] } onChange={ ( v ) => set( { who_can_submit: v } ) } />
						<RadioControl label={ __( 'Moderation', 'advery-reviews' ) } help={ __( 'Manual (recommended): a new review waits as “Pending” until you approve it. Auto-approve: it’s published immediately — faster, but riskier.', 'advery-reviews' ) } selected={ s.moderation } options={ [ { label: __( 'Hold for approval (manual)', 'advery-reviews' ), value: 'manual' }, { label: __( 'Auto-approve', 'advery-reviews' ), value: 'auto' } ] } onChange={ ( v ) => set( { moderation: v } ) } />
						<ToggleControl label={ __( 'One review per user / email', 'advery-reviews' ) } help={ __( 'Stops the same person reviewing one item repeatedly. Matched by their account when logged in, otherwise by email.', 'advery-reviews' ) } checked={ !! s.one_per_user } onChange={ ( v ) => set( { one_per_user: v } ) } __nextHasNoMarginBottom />
						<ToggleControl label={ __( 'Rating required', 'advery-reviews' ) } help={ __( 'On: the visitor must pick a star rating. Off: text-only comments (no stars) are allowed too.', 'advery-reviews' ) } checked={ !! s.rating_required } onChange={ ( v ) => set( { rating_required: v } ) } __nextHasNoMarginBottom />
						<p className="advery-rv-hint">{ __( 'Length limits and injection/link protection are under Anti-spam.', 'advery-reviews' ) }</p>
					</>
				);
			case 'antispam':
				return (
					<>
						<p className="advery-rv-hint">{ __( 'Layered and score-based: each check adds points to a “spam score”, and the two thresholds decide whether a review is held or marked spam. The defaults are sensible.', 'advery-reviews' ) }</p>
						<ToggleControl label={ __( 'Timing check (reject too-fast bot submissions)', 'advery-reviews' ) } help={ __( 'A real person needs a few seconds to write a review; bots submit instantly. Recommended: on.', 'advery-reviews' ) } checked={ !! as.timing_enabled } onChange={ ( v ) => setAs( { timing_enabled: v } ) } __nextHasNoMarginBottom />
						<TextControl type="number" label={ __( 'Minimum seconds to fill the form', 'advery-reviews' ) } help={ __( 'Example: 3. Faster than this is treated as a likely bot.', 'advery-reviews' ) } value={ as.timing_min } onChange={ ( v ) => setAs( { timing_min: parseInt( v, 10 ) || 0 } ) } __nextHasNoMarginBottom />
						<TextControl type="number" label={ __( 'Max links allowed in a review (0 = none)', 'advery-reviews' ) } help={ __( 'Recommended: 0. Detects plain, marked-up and obfuscated links (example.com, 1.2.3.4, [url], “example dot com”, “example[.]com”) across review, title and name.', 'advery-reviews' ) } value={ as.max_links } onChange={ ( v ) => setAs( { max_links: parseInt( v, 10 ) || 0 } ) } __nextHasNoMarginBottom />
						<SelectControl label={ __( 'When over the link limit', 'advery-reviews' ) } help={ __( 'Recommended: “Reject with a message”. “Spam”/“Hold” keep it hidden for you to review.', 'advery-reviews' ) } value={ as.link_action } options={ [ { label: __( 'Ignore', 'advery-reviews' ), value: 'off' }, { label: __( 'Hold for moderation', 'advery-reviews' ), value: 'hold' }, { label: __( 'Mark as spam', 'advery-reviews' ), value: 'spam' }, { label: __( 'Reject with a message', 'advery-reviews' ), value: 'reject' } ] } onChange={ ( v ) => setAs( { link_action: v } ) } __nextHasNoMarginBottom />
						<TextControl type="number" label={ __( 'Minimum review length (characters)', 'advery-reviews' ) } help={ __( 'Example: 10 — blocks one-word “ok”/“good”.', 'advery-reviews' ) } value={ as.min_chars } onChange={ ( v ) => setAs( { min_chars: parseInt( v, 10 ) || 0 } ) } __nextHasNoMarginBottom />
						<TextControl type="number" label={ __( 'Maximum review length (characters)', 'advery-reviews' ) } help={ __( 'Example: 1500.', 'advery-reviews' ) } value={ as.max_chars } onChange={ ( v ) => setAs( { max_chars: parseInt( v, 10 ) || 0 } ) } __nextHasNoMarginBottom />
						<TextControl type="number" label={ __( 'Maximum author name length (characters)', 'advery-reviews' ) } help={ __( 'Example: 35.', 'advery-reviews' ) } value={ as.max_name_chars } onChange={ ( v ) => setAs( { max_name_chars: parseInt( v, 10 ) || 1 } ) } __nextHasNoMarginBottom />
						<TextControl label={ __( 'Blocked words / phrases (one per line; prefix re: for regex)', 'advery-reviews' ) } help={ __( 'One per line. Example: viagra. Advanced: re:\\bcasino\\b for a regular expression.', 'advery-reviews' ) } value={ as.blocklist_words } onChange={ ( v ) => setAs( { blocklist_words: v } ) } __nextHasNoMarginBottom />
						<TextControl label={ __( 'Blocked emails / domains (one per line)', 'advery-reviews' ) } help={ __( 'A full address (spammer@bad.com) or a whole domain (bad.ru).', 'advery-reviews' ) } value={ as.blocklist_emails } onChange={ ( v ) => setAs( { blocklist_emails: v } ) } __nextHasNoMarginBottom />
						<ToggleControl label={ __( 'Block disposable email domains', 'advery-reviews' ) } help={ __( 'Blocks throwaway inboxes (mailinator.com, 10minutemail.com, …).', 'advery-reviews' ) } checked={ !! as.block_disposable } onChange={ ( v ) => setAs( { block_disposable: v } ) } __nextHasNoMarginBottom />
						<ToggleControl label={ __( 'Reject duplicate content', 'advery-reviews' ) } help={ __( 'Blocks the same review text being posted again on the same item.', 'advery-reviews' ) } checked={ !! as.duplicate_check } onChange={ ( v ) => setAs( { duplicate_check: v } ) } __nextHasNoMarginBottom />
						<ToggleControl label={ __( 'Auto-approve trusted authors', 'advery-reviews' ) } help={ __( 'A logged-in visitor who already has one approved review skips moderation next time.', 'advery-reviews' ) } checked={ !! as.trusted_autoapprove } onChange={ ( v ) => setAs( { trusted_autoapprove: v } ) } __nextHasNoMarginBottom />
						<hr />
						<ToggleControl label={ __( 'Rate limiting', 'advery-reviews' ) } help={ __( 'Caps how many reviews one person/IP can post in a short time.', 'advery-reviews' ) } checked={ !! as.rate_enabled } onChange={ ( v ) => setAs( { rate_enabled: v } ) } __nextHasNoMarginBottom />
						<TextControl type="number" label={ __( 'Window (seconds)', 'advery-reviews' ) } help={ __( 'Example: 600 = 10 minutes.', 'advery-reviews' ) } value={ as.rate_window } onChange={ ( v ) => setAs( { rate_window: parseInt( v, 10 ) || 1 } ) } __nextHasNoMarginBottom />
						<TextControl type="number" label={ __( 'Max submissions per window (per IP/email)', 'advery-reviews' ) } help={ __( 'Example: 3.', 'advery-reviews' ) } value={ as.rate_max } onChange={ ( v ) => setAs( { rate_max: parseInt( v, 10 ) || 1 } ) } __nextHasNoMarginBottom />
						<TextControl type="number" label={ __( 'Max per day (0 = off)', 'advery-reviews' ) } help={ __( 'Example: 20.', 'advery-reviews' ) } value={ as.rate_day_max } onChange={ ( v ) => setAs( { rate_day_max: parseInt( v, 10 ) || 0 } ) } __nextHasNoMarginBottom />
						<hr />
						<TextControl type="number" label={ __( 'Hold threshold (score ≥ ⇒ hold)', 'advery-reviews' ) } help={ __( 'Example: 2.', 'advery-reviews' ) } value={ as.hold_threshold } onChange={ ( v ) => setAs( { hold_threshold: parseInt( v, 10 ) || 1 } ) } __nextHasNoMarginBottom />
						<TextControl type="number" label={ __( 'Spam threshold (score ≥ ⇒ spam)', 'advery-reviews' ) } help={ __( 'Example: 5 (one strong signal like a blocked word).', 'advery-reviews' ) } value={ as.spam_threshold } onChange={ ( v ) => setAs( { spam_threshold: parseInt( v, 10 ) || 1 } ) } __nextHasNoMarginBottom />
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
						<TextControl type="number" label={ __( 'Reviews shown per page', 'advery-reviews' ) } help={ __( 'Example: 10. With “Load more”/pagination, the rest load on demand.', 'advery-reviews' ) } value={ s.reviews_per_page } onChange={ ( v ) => set( { reviews_per_page: parseInt( v, 10 ) || 10 } ) } __nextHasNoMarginBottom />
						<SelectControl label={ __( 'Loading mode', 'advery-reviews' ) } help={ __( 'All = everything at once. Load more = a button. Pagination = numbered pages. In every mode the URL never changes and the first page is server-rendered, so SEO is unaffected.', 'advery-reviews' ) } value={ s.load_mode } options={ [ { label: __( 'All on one page', 'advery-reviews' ), value: 'all' }, { label: __( '“Load more” button (AJAX)', 'advery-reviews' ), value: 'load_more' }, { label: __( 'Numbered pagination (AJAX)', 'advery-reviews' ), value: 'paginate' } ] } onChange={ ( v ) => set( { load_mode: v } ) } __nextHasNoMarginBottom />
						<ToggleControl label={ __( 'Replace the theme’s native comments with reviews', 'advery-reviews' ) } help={ __( 'Takes over the comments area on enabled post types — no theme editing or page builder needed. WooCommerce products are never taken over.', 'advery-reviews' ) } checked={ !! s.replace_comments } onChange={ ( v ) => set( { replace_comments: v } ) } __nextHasNoMarginBottom />
					</>
				);
			case 'ai':
				return (
					<>
						<p className="advery-rv-hint">{ __( 'AI works on REAL reviews only — drafting your replies, assisting moderation, translating and summarizing. It never generates fake reviews.', 'advery-reviews' ) }</p>
						<SelectControl label={ __( 'Provider', 'advery-reviews' ) } help={ __( 'Anthropic, OpenAI and Gemini need an API key; OpenRouter is a multi-model gateway; Ollama runs locally with no key.', 'advery-reviews' ) } value={ ai.provider } options={ [ { label: 'Anthropic (Claude)', value: 'anthropic' }, { label: 'OpenAI', value: 'openai' }, { label: 'OpenRouter', value: 'openrouter' }, { label: 'Ollama (self-hosted)', value: 'ollama' }, { label: 'Google Gemini', value: 'gemini' } ] } onChange={ ( v ) => setAi( { provider: v } ) } __nextHasNoMarginBottom />
						<TextControl type="password" label={ __( 'API key', 'advery-reviews' ) } help={ ai.provider === 'ollama' ? __( 'Not required for Ollama.', 'advery-reviews' ) : __( 'Paste the secret key from your provider’s dashboard. Stored on your server only.', 'advery-reviews' ) } value={ ai.api_key } onChange={ ( v ) => setAi( { api_key: v } ) } __nextHasNoMarginBottom />
						<TextControl label={ __( 'Model (blank = provider default)', 'advery-reviews' ) } help={ __( 'Example: claude-sonnet-4-5, gpt-4o-mini, gemini-1.5-flash, llama3.1.', 'advery-reviews' ) } value={ ai.model } onChange={ ( v ) => setAi( { model: v } ) } __nextHasNoMarginBottom />
						<TextControl label={ __( 'Base URL (optional override)', 'advery-reviews' ) } help={ __( 'Only for self-hosted/proxy endpoints. Example (Ollama): http://localhost:11434/v1.', 'advery-reviews' ) } value={ ai.base_url } onChange={ ( v ) => setAi( { base_url: v } ) } __nextHasNoMarginBottom />
						<TextControl type="number" label={ __( 'Daily call limit (0 = unlimited)', 'advery-reviews' ) } help={ __( 'Caps AI cost. Example: 200 calls/day.', 'advery-reviews' ) } value={ ai.daily_cap } onChange={ ( v ) => setAi( { daily_cap: parseInt( v, 10 ) || 0 } ) } __nextHasNoMarginBottom />
						<TextareaControl label={ __( 'About your business (context for replies)', 'advery-reviews' ) } help={ __( 'Optional. Example: “Family-run bakery in Toronto, open since 2015, known for sourdough.”', 'advery-reviews' ) } rows={ 3 } value={ ai.business_context || '' } onChange={ ( v ) => setAi( { business_context: v } ) } __nextHasNoMarginBottom />
						<hr />
						<strong>{ __( 'Reply voice per content type', 'advery-reviews' ) }</strong>
						<p className="advery-rv-hint">{ __( 'If a content type is a DIRECTORY of other businesses (you are not the business), tick it — AI replies then speak as the platform, never on the business’s behalf. Leave OFF for your own products/services.', 'advery-reviews' ) }</p>
						{ ( boot.postTypes || [] ).map( ( pt ) => (
							<CheckboxControl key={ pt.slug } label={ sprintf( __( '“%1$s” is a third-party directory listing', 'advery-reviews' ), pt.label ) } checked={ roles[ pt.slug ] === 'listing' } onChange={ ( on ) => setRole( pt.slug, on ) } __nextHasNoMarginBottom />
						) ) }
						<p className="advery-rv-hint" style={ { marginTop: 10 } }>{ __( 'Turn each task on/off and optionally replace its prompt (the grey placeholder is the built-in default).', 'advery-reviews' ) }</p>
						{ [ [ 'reply', __( 'Reply drafting', 'advery-reviews' ) ], [ 'moderate', __( 'Moderation assist', 'advery-reviews' ) ], [ 'translate', __( 'Translate', 'advery-reviews' ) ], [ 'summarize', __( 'Summarize', 'advery-reviews' ) ] ].map( ( [ key, label ] ) => (
							<div key={ key } style={ { borderTop: '1px solid #eef0f3', paddingTop: 8, marginTop: 8 } }>
								<ToggleControl label={ label } checked={ !! ( ai.tasks && ai.tasks[ key ] && ai.tasks[ key ].enabled ) } onChange={ ( v ) => setAiTask( key, { enabled: v } ) } __nextHasNoMarginBottom />
								<TextareaControl label={ __( 'Prompt (blank = built-in default)', 'advery-reviews' ) } placeholder={ aiPrompts[ key ] || '' } rows={ 3 } value={ ( ai.tasks && ai.tasks[ key ] && ai.tasks[ key ].prompt ) || '' } onChange={ ( v ) => setAiTask( key, { prompt: v } ) } __nextHasNoMarginBottom />
							</div>
						) ) }
						<div style={ { marginTop: 12 } }>
							<Button variant="secondary" isBusy={ aiTest && aiTest.busy } onClick={ runAiTest }>{ __( 'Save, then test the connection', 'advery-reviews' ) }</Button>
							<p className="advery-rv-hint">{ __( 'Click Save first, then this drafts a sample reply to confirm your key/model work.', 'advery-reviews' ) }</p>
						</div>
						{ aiTest && ! aiTest.busy && (
							<Notice status={ aiTest.ok ? 'success' : 'error' } isDismissible onRemove={ () => setAiTest( null ) }>
								{ aiTest.ok ? __( 'Working. Sample reply: ', 'advery-reviews' ) + aiTest.sample : aiTest.message }
							</Notice>
						) }
					</>
				);
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
						<TextControl label={ __( 'Recipient email (blank = site admin)', 'advery-reviews' ) } help={ __( 'Example: reviews@yoursite.com. Blank uses your WordPress admin email.', 'advery-reviews' ) } value={ s.email_recipient } onChange={ ( v ) => set( { email_recipient: v } ) } __nextHasNoMarginBottom />
						<SelectControl label={ __( 'Digest email', 'advery-reviews' ) } help={ __( 'An optional summary of new reviews, sent on a schedule (via WP-Cron).', 'advery-reviews' ) } value={ s.digest_frequency } options={ [ { label: __( 'Off', 'advery-reviews' ), value: 'off' }, { label: __( 'Weekly', 'advery-reviews' ), value: 'weekly' }, { label: __( 'Monthly', 'advery-reviews' ), value: 'monthly' } ] } onChange={ ( v ) => set( { digest_frequency: v } ) } __nextHasNoMarginBottom />
					</>
				);
			case 'css':
				return (
					<TextareaControl label={ __( 'Custom CSS', 'advery-reviews' ) } help={ __( 'Optional styling, printed wherever the widget shows. Example: .advery-reviews__stars { color: #e11; }', 'advery-reviews' ) } value={ s.custom_css } rows={ 10 } onChange={ ( v ) => set( { custom_css: v } ) } __nextHasNoMarginBottom />
				);
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
