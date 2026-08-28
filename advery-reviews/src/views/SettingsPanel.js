import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Panel,
	PanelBody,
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

	const set = ( patch ) => setS( { ...s, ...patch } );
	const as = s.antispam || {};
	const setAs = ( patch ) => set( { antispam: { ...as, ...patch } } );
	const ai = s.ai || {};
	const setAi = ( patch ) => set( { ai: { ...ai, ...patch } } );
	const setAiTask = ( key, patch ) =>
		set( { ai: { ...ai, tasks: { ...( ai.tasks || {} ), [ key ]: { ...( ( ai.tasks || {} )[ key ] || {} ), ...patch } } } } );
	const [ aiTest, setAiTest ] = useState( null );
	const runAiTest = async () => {
		setAiTest( { busy: true } );
		try {
			const res = await api.ai( 'test' );
			setAiTest( res.ok ? { ok: true, sample: res.sample } : { ok: false, message: res.message } );
		} catch ( e ) {
			setAiTest( { ok: false, message: e.message } );
		}
	};
	const aiPrompts = ( boot.ai && boot.ai.prompts ) || {};
	const roles = s.roles || {};
	const setRole = ( pt, isListing ) => {
		const next = { ...roles };
		if ( isListing ) {
			next[ pt ] = 'listing';
		} else {
			delete next[ pt ];
		}
		set( { roles: next } );
	};
	const toggleIn = ( key, slug, on ) => {
		const cur = new Set( s[ key ] || [] );
		on ? cur.add( slug ) : cur.delete( slug );
		set( { [ key ]: Array.from( cur ) } );
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

	return (
		<div className="advery-rv-settings">
			<Panel>
				<PanelBody title={ __( 'Where reviews are collected', 'advery-reviews' ) } initialOpen>
					<p className="advery-rv-hint">{ __( 'Post types that can receive reviews (the form appears on these).', 'advery-reviews' ) }</p>
					{ ( boot.postTypes || [] ).map( ( pt ) => (
						<CheckboxControl
							key={ pt.slug }
							label={ `${ pt.label } (${ pt.slug })` }
							checked={ ( s.enabled_post_types || [] ).includes( pt.slug ) }
							onChange={ ( on ) => toggleIn( 'enabled_post_types', pt.slug, on ) }
							__nextHasNoMarginBottom
						/>
					) ) }
					<hr />
					<p className="advery-rv-hint">{ __( 'Taxonomies whose term archives can receive reviews.', 'advery-reviews' ) }</p>
					{ ( boot.taxonomies || [] ).map( ( tx ) => (
						<CheckboxControl
							key={ tx.slug }
							label={ `${ tx.label } (${ tx.slug })` }
							checked={ ( s.enabled_taxonomies || [] ).includes( tx.slug ) }
							onChange={ ( on ) => toggleIn( 'enabled_taxonomies', tx.slug, on ) }
							__nextHasNoMarginBottom
						/>
					) ) }
					{ boot.wooActive && (
						<>
							<hr />
							<ToggleControl
								label={ __( 'WooCommerce products', 'advery-reviews' ) }
								help={ __( 'Collect reviews on products and read WooCommerce’s native ratings.', 'advery-reviews' ) }
								checked={ !! s.woo_enabled }
								onChange={ ( v ) => set( { woo_enabled: v } ) }
								__nextHasNoMarginBottom
							/>
						</>
					) }
				</PanelBody>

				<PanelBody title={ __( 'Submission rules', 'advery-reviews' ) } initialOpen={ false }>
					<RadioControl
						label={ __( 'Who can submit', 'advery-reviews' ) }
						selected={ s.who_can_submit }
						options={ [
							{ label: __( 'Anyone (name + email)', 'advery-reviews' ), value: 'anyone' },
							{ label: __( 'Logged-in users only', 'advery-reviews' ), value: 'logged_in' },
						] }
						onChange={ ( v ) => set( { who_can_submit: v } ) }
					/>
					<RadioControl
						label={ __( 'Moderation', 'advery-reviews' ) }
						selected={ s.moderation }
						options={ [
							{ label: __( 'Hold for approval (manual)', 'advery-reviews' ), value: 'manual' },
							{ label: __( 'Auto-approve', 'advery-reviews' ), value: 'auto' },
						] }
						onChange={ ( v ) => set( { moderation: v } ) }
					/>
					<ToggleControl
						label={ __( 'One review per user / email', 'advery-reviews' ) }
						checked={ !! s.one_per_user }
						onChange={ ( v ) => set( { one_per_user: v } ) }
						__nextHasNoMarginBottom
					/>
					<ToggleControl
						label={ __( 'Rating required', 'advery-reviews' ) }
						checked={ !! s.rating_required }
						onChange={ ( v ) => set( { rating_required: v } ) }
						__nextHasNoMarginBottom
					/>
					<p className="advery-rv-hint">{ __( 'Length limits and injection/link protection are under Anti-spam.', 'advery-reviews' ) }</p>
				</PanelBody>

				<PanelBody title={ __( 'Anti-spam', 'advery-reviews' ) } initialOpen={ false }>
					<p className="advery-rv-hint">{ __( 'Layered, score-based. Each check adds to a spam score; the thresholds decide hold vs spam. Sensible defaults are on.', 'advery-reviews' ) }</p>

					<ToggleControl
						label={ __( 'Timing check (reject too-fast bot submissions)', 'advery-reviews' ) }
						checked={ !! as.timing_enabled }
						onChange={ ( v ) => setAs( { timing_enabled: v } ) }
						__nextHasNoMarginBottom
					/>
					<TextControl
						type="number"
						label={ __( 'Minimum seconds to fill the form', 'advery-reviews' ) }
						value={ as.timing_min }
						onChange={ ( v ) => setAs( { timing_min: parseInt( v, 10 ) || 0 } ) }
						__nextHasNoMarginBottom
					/>

					<TextControl
						type="number"
						label={ __( 'Max links allowed in a review (0 = none)', 'advery-reviews' ) }
						help={ __( 'Detects plain, marked-up and obfuscated links (example.com, 1.2.3.4, [url], “example dot com”, “example[.]com”) in the review, title and name.', 'advery-reviews' ) }
						value={ as.max_links }
						onChange={ ( v ) => setAs( { max_links: parseInt( v, 10 ) || 0 } ) }
						__nextHasNoMarginBottom
					/>
					<SelectControl
						label={ __( 'When over the link limit', 'advery-reviews' ) }
						value={ as.link_action }
						options={ [
							{ label: __( 'Ignore', 'advery-reviews' ), value: 'off' },
							{ label: __( 'Hold for moderation', 'advery-reviews' ), value: 'hold' },
							{ label: __( 'Mark as spam', 'advery-reviews' ), value: 'spam' },
							{ label: __( 'Reject with a message', 'advery-reviews' ), value: 'reject' },
						] }
						onChange={ ( v ) => setAs( { link_action: v } ) }
						__nextHasNoMarginBottom
					/>
					<TextControl
						type="number"
						label={ __( 'Minimum review length (characters)', 'advery-reviews' ) }
						value={ as.min_chars }
						onChange={ ( v ) => setAs( { min_chars: parseInt( v, 10 ) || 0 } ) }
						__nextHasNoMarginBottom
					/>
					<TextControl
						type="number"
						label={ __( 'Maximum review length (characters)', 'advery-reviews' ) }
						value={ as.max_chars }
						onChange={ ( v ) => setAs( { max_chars: parseInt( v, 10 ) || 0 } ) }
						__nextHasNoMarginBottom
					/>
					<TextControl
						type="number"
						label={ __( 'Maximum author name length (characters)', 'advery-reviews' ) }
						value={ as.max_name_chars }
						onChange={ ( v ) => setAs( { max_name_chars: parseInt( v, 10 ) || 1 } ) }
						__nextHasNoMarginBottom
					/>

					<TextControl
						label={ __( 'Blocked words / phrases (one per line; prefix re: for regex)', 'advery-reviews' ) }
						value={ as.blocklist_words }
						onChange={ ( v ) => setAs( { blocklist_words: v } ) }
						__nextHasNoMarginBottom
					/>
					<TextControl
						label={ __( 'Blocked emails / domains (one per line)', 'advery-reviews' ) }
						value={ as.blocklist_emails }
						onChange={ ( v ) => setAs( { blocklist_emails: v } ) }
						__nextHasNoMarginBottom
					/>
					<ToggleControl
						label={ __( 'Block disposable email domains', 'advery-reviews' ) }
						checked={ !! as.block_disposable }
						onChange={ ( v ) => setAs( { block_disposable: v } ) }
						__nextHasNoMarginBottom
					/>
					<ToggleControl
						label={ __( 'Reject duplicate content', 'advery-reviews' ) }
						checked={ !! as.duplicate_check }
						onChange={ ( v ) => setAs( { duplicate_check: v } ) }
						__nextHasNoMarginBottom
					/>
					<ToggleControl
						label={ __( 'Auto-approve trusted authors (logged-in with a prior approved review)', 'advery-reviews' ) }
						checked={ !! as.trusted_autoapprove }
						onChange={ ( v ) => setAs( { trusted_autoapprove: v } ) }
						__nextHasNoMarginBottom
					/>

					<hr />
					<ToggleControl
						label={ __( 'Rate limiting', 'advery-reviews' ) }
						checked={ !! as.rate_enabled }
						onChange={ ( v ) => setAs( { rate_enabled: v } ) }
						__nextHasNoMarginBottom
					/>
					<TextControl
						type="number"
						label={ __( 'Window (seconds)', 'advery-reviews' ) }
						value={ as.rate_window }
						onChange={ ( v ) => setAs( { rate_window: parseInt( v, 10 ) || 1 } ) }
						__nextHasNoMarginBottom
					/>
					<TextControl
						type="number"
						label={ __( 'Max submissions per window (per IP/email)', 'advery-reviews' ) }
						value={ as.rate_max }
						onChange={ ( v ) => setAs( { rate_max: parseInt( v, 10 ) || 1 } ) }
						__nextHasNoMarginBottom
					/>
					<TextControl
						type="number"
						label={ __( 'Max per day (0 = off)', 'advery-reviews' ) }
						value={ as.rate_day_max }
						onChange={ ( v ) => setAs( { rate_day_max: parseInt( v, 10 ) || 0 } ) }
						__nextHasNoMarginBottom
					/>

					<hr />
					<TextControl
						type="number"
						label={ __( 'Hold threshold (score ≥ ⇒ hold)', 'advery-reviews' ) }
						value={ as.hold_threshold }
						onChange={ ( v ) => setAs( { hold_threshold: parseInt( v, 10 ) || 1 } ) }
						__nextHasNoMarginBottom
					/>
					<TextControl
						type="number"
						label={ __( 'Spam threshold (score ≥ ⇒ spam)', 'advery-reviews' ) }
						value={ as.spam_threshold }
						onChange={ ( v ) => setAs( { spam_threshold: parseInt( v, 10 ) || 1 } ) }
						__nextHasNoMarginBottom
					/>

					<hr />
					<SelectControl
						label={ __( 'CAPTCHA provider', 'advery-reviews' ) }
						value={ as.captcha_provider }
						options={ [
							{ label: __( 'None', 'advery-reviews' ), value: 'none' },
							{ label: 'reCAPTCHA v3', value: 'recaptcha_v3' },
							{ label: 'reCAPTCHA v2', value: 'recaptcha_v2' },
							{ label: 'hCaptcha', value: 'hcaptcha' },
							{ label: 'Cloudflare Turnstile', value: 'turnstile' },
						] }
						onChange={ ( v ) => setAs( { captcha_provider: v } ) }
						__nextHasNoMarginBottom
					/>
					{ as.captcha_provider !== 'none' && (
						<>
							<TextControl
								label={ __( 'Site key', 'advery-reviews' ) }
								value={ as.captcha_site_key }
								onChange={ ( v ) => setAs( { captcha_site_key: v } ) }
								__nextHasNoMarginBottom
							/>
							<TextControl
								label={ __( 'Secret key', 'advery-reviews' ) }
								value={ as.captcha_secret_key }
								onChange={ ( v ) => setAs( { captcha_secret_key: v } ) }
								__nextHasNoMarginBottom
							/>
							{ as.captcha_provider === 'recaptcha_v3' && (
								<TextControl
									type="number"
									step="0.1"
									label={ __( 'reCAPTCHA v3 score threshold (0–1)', 'advery-reviews' ) }
									value={ as.captcha_threshold }
									onChange={ ( v ) => setAs( { captcha_threshold: parseFloat( v ) || 0 } ) }
									__nextHasNoMarginBottom
								/>
							) }
						</>
					) }
					<ToggleControl
						label={ __( 'Use Akismet as an extra signal (if configured)', 'advery-reviews' ) }
						checked={ !! as.akismet_enabled }
						onChange={ ( v ) => setAs( { akismet_enabled: v } ) }
						__nextHasNoMarginBottom
					/>
				</PanelBody>

				<PanelBody title={ __( 'Display', 'advery-reviews' ) } initialOpen={ false }>
					<ToggleControl
						label={ __( 'Automatically append to content', 'advery-reviews' ) }
						help={ __( 'Add the reviews widget after the content of enabled post types. Or use the [advery_reviews] shortcode.', 'advery-reviews' ) }
						checked={ !! s.auto_append }
						onChange={ ( v ) => set( { auto_append: v } ) }
						__nextHasNoMarginBottom
					/>
					<TextControl
						type="number"
						label={ __( 'Reviews shown per page', 'advery-reviews' ) }
						value={ s.reviews_per_page }
						onChange={ ( v ) => set( { reviews_per_page: parseInt( v, 10 ) || 10 } ) }
						__nextHasNoMarginBottom
					/>
					<SelectControl
						label={ __( 'Loading mode', 'advery-reviews' ) }
						help={ __( 'The page URL never changes and the first page is server-rendered, so SEO is unaffected.', 'advery-reviews' ) }
						value={ s.load_mode }
						options={ [
							{ label: __( 'All on one page', 'advery-reviews' ), value: 'all' },
							{ label: __( '“Load more” button (AJAX)', 'advery-reviews' ), value: 'load_more' },
							{ label: __( 'Numbered pagination (AJAX)', 'advery-reviews' ), value: 'paginate' },
						] }
						onChange={ ( v ) => set( { load_mode: v } ) }
						__nextHasNoMarginBottom
					/>
					<ToggleControl
						label={ __( 'Replace the theme’s native comments with reviews', 'advery-reviews' ) }
						help={ __( 'Takes over the comments area on enabled post types — no theme editing or page builder required.', 'advery-reviews' ) }
						checked={ !! s.replace_comments }
						onChange={ ( v ) => set( { replace_comments: v } ) }
						__nextHasNoMarginBottom
					/>
				</PanelBody>

				<PanelBody title={ __( 'AI (replies, moderation, translate)', 'advery-reviews' ) } initialOpen={ false }>
					<p className="advery-rv-hint">{ __( 'AI works on REAL reviews only — drafting owner replies, assisting moderation, translating and summarizing. It never generates fake reviews.', 'advery-reviews' ) }</p>
					<SelectControl
						label={ __( 'Provider', 'advery-reviews' ) }
						value={ ai.provider }
						options={ [
							{ label: 'Anthropic (Claude)', value: 'anthropic' },
							{ label: 'OpenAI', value: 'openai' },
							{ label: 'OpenRouter', value: 'openrouter' },
							{ label: 'Ollama (self-hosted)', value: 'ollama' },
							{ label: 'Google Gemini', value: 'gemini' },
						] }
						onChange={ ( v ) => setAi( { provider: v } ) }
						__nextHasNoMarginBottom
					/>
					<TextControl
						type="password"
						label={ __( 'API key', 'advery-reviews' ) }
						help={ ai.provider === 'ollama' ? __( 'Not required for Ollama.', 'advery-reviews' ) : '' }
						value={ ai.api_key }
						onChange={ ( v ) => setAi( { api_key: v } ) }
						__nextHasNoMarginBottom
					/>
					<TextControl
						label={ __( 'Model (blank = provider default)', 'advery-reviews' ) }
						value={ ai.model }
						onChange={ ( v ) => setAi( { model: v } ) }
						__nextHasNoMarginBottom
					/>
					<TextControl
						label={ __( 'Base URL (optional override)', 'advery-reviews' ) }
						value={ ai.base_url }
						onChange={ ( v ) => setAi( { base_url: v } ) }
						__nextHasNoMarginBottom
					/>
					<TextControl
						type="number"
						label={ __( 'Daily call limit (0 = unlimited)', 'advery-reviews' ) }
						value={ ai.daily_cap }
						onChange={ ( v ) => setAi( { daily_cap: parseInt( v, 10 ) || 0 } ) }
						__nextHasNoMarginBottom
					/>
					<TextareaControl
						label={ __( 'About your business (context for replies)', 'advery-reviews' ) }
						help={ __( 'Optional. A short description of who you are, used when drafting replies for your own products/services.', 'advery-reviews' ) }
						rows={ 3 }
						value={ ai.business_context || '' }
						onChange={ ( v ) => setAi( { business_context: v } ) }
						__nextHasNoMarginBottom
					/>
					<div style={ { borderTop: '1px solid #eee', paddingTop: 8, marginTop: 8 } }>
						<strong>{ __( 'Reply voice per content type', 'advery-reviews' ) }</strong>
						<p className="advery-rv-hint">{ __( 'For a directory of third-party businesses, mark the listing type below — replies will speak as the platform, never on the business’s behalf. Leave off for your own products/services.', 'advery-reviews' ) }</p>
						{ ( boot.postTypes || [] ).map( ( pt ) => (
							<CheckboxControl
								key={ pt.slug }
								label={ sprintf( __( '“%1$s” is a third-party directory listing (we are not the business)', 'advery-reviews' ), pt.label ) }
								checked={ roles[ pt.slug ] === 'listing' }
								onChange={ ( on ) => setRole( pt.slug, on ) }
								__nextHasNoMarginBottom
							/>
						) ) }
					</div>
					{ [
						[ 'reply', __( 'Reply drafting', 'advery-reviews' ) ],
						[ 'moderate', __( 'Moderation assist', 'advery-reviews' ) ],
						[ 'translate', __( 'Translate', 'advery-reviews' ) ],
						[ 'summarize', __( 'Summarize', 'advery-reviews' ) ],
					].map( ( [ key, label ] ) => (
						<div key={ key } style={ { borderTop: '1px solid #eee', paddingTop: 8, marginTop: 8 } }>
							<ToggleControl
								label={ label }
								checked={ !! ( ai.tasks && ai.tasks[ key ] && ai.tasks[ key ].enabled ) }
								onChange={ ( v ) => setAiTask( key, { enabled: v } ) }
								__nextHasNoMarginBottom
							/>
							<TextareaControl
								label={ __( 'Prompt (blank = built-in default)', 'advery-reviews' ) }
								placeholder={ aiPrompts[ key ] || '' }
								rows={ 3 }
								value={ ( ai.tasks && ai.tasks[ key ] && ai.tasks[ key ].prompt ) || '' }
								onChange={ ( v ) => setAiTask( key, { prompt: v } ) }
								__nextHasNoMarginBottom
							/>
						</div>
					) ) }
					<div style={ { marginTop: 12 } }>
						<Button variant="secondary" isBusy={ aiTest && aiTest.busy } onClick={ runAiTest }>
							{ __( 'Save, then test the connection', 'advery-reviews' ) }
						</Button>
					</div>
					{ aiTest && ! aiTest.busy && (
						<Notice status={ aiTest.ok ? 'success' : 'error' } isDismissible onRemove={ () => setAiTest( null ) }>
							{ aiTest.ok ? __( 'Working. Sample reply: ', 'advery-reviews' ) + aiTest.sample : aiTest.message }
						</Notice>
					) }
				</PanelBody>

				<PanelBody title={ __( 'Custom CSS', 'advery-reviews' ) } initialOpen={ false }>
					<TextareaControl
						label={ __( 'Custom CSS', 'advery-reviews' ) }
						help={ __( 'Printed wherever the reviews widget renders. Style any .advery-reviews__* class.', 'advery-reviews' ) }
						value={ s.custom_css }
						rows={ 8 }
						onChange={ ( v ) => set( { custom_css: v } ) }
						__nextHasNoMarginBottom
					/>
				</PanelBody>

				<PanelBody title={ __( 'Maintenance', 'advery-reviews' ) } initialOpen={ false }>
					<p className="advery-rv-hint">{ __( 'Remove reviews whose post/term was deleted, and optimize the tables.', 'advery-reviews' ) }</p>
					<Button variant="secondary" isBusy={ busy === 'purge' } disabled={ !! busy } onClick={ () => maintenance( 'purge' ) }>
						{ __( 'Purge orphaned reviews', 'advery-reviews' ) }
					</Button>
					{ ' ' }
					<Button variant="secondary" isBusy={ busy === 'optimize' } disabled={ !! busy } onClick={ () => maintenance( 'optimize' ) }>
						{ __( 'Optimize tables', 'advery-reviews' ) }
					</Button>
				</PanelBody>

				<PanelBody title={ __( 'Schema (JSON-LD)', 'advery-reviews' ) } initialOpen={ false }>
					{ ! boot.coreActive && (
						<Notice status="warning" isDismissible={ false }>
							{ __( 'Advery Schema Plus is not active — schema injection is idle until it is.', 'advery-reviews' ) }
						</Notice>
					) }
					<ToggleControl
						label={ __( 'Inject aggregateRating / review into the graph', 'advery-reviews' ) }
						checked={ !! s.schema_output }
						onChange={ ( v ) => set( { schema_output: v } ) }
						__nextHasNoMarginBottom
					/>
					{ boot.wooActive && (
						<ToggleControl
							label={ __( 'Merge WooCommerce native ratings into the aggregate', 'advery-reviews' ) }
							checked={ !! s.woo_merge_native }
							onChange={ ( v ) => set( { woo_merge_native: v } ) }
							__nextHasNoMarginBottom
						/>
					) }
				</PanelBody>

				<PanelBody title={ __( 'Email reports', 'advery-reviews' ) } initialOpen={ false }>
					<ToggleControl
						label={ __( 'Email me instantly on each new review', 'advery-reviews' ) }
						checked={ !! s.email_instant }
						onChange={ ( v ) => set( { email_instant: v } ) }
						__nextHasNoMarginBottom
					/>
					<TextControl
						label={ __( 'Recipient email (blank = site admin)', 'advery-reviews' ) }
						value={ s.email_recipient }
						onChange={ ( v ) => set( { email_recipient: v } ) }
						__nextHasNoMarginBottom
					/>
					<SelectControl
						label={ __( 'Digest email', 'advery-reviews' ) }
						value={ s.digest_frequency }
						options={ [
							{ label: __( 'Off', 'advery-reviews' ), value: 'off' },
							{ label: __( 'Weekly', 'advery-reviews' ), value: 'weekly' },
							{ label: __( 'Monthly', 'advery-reviews' ), value: 'monthly' },
						] }
						onChange={ ( v ) => set( { digest_frequency: v } ) }
						__nextHasNoMarginBottom
					/>
				</PanelBody>
			</Panel>

			<div className="advery-rv-save">
				<Button variant="primary" isBusy={ saving } disabled={ saving } onClick={ save }>
					{ __( 'Save settings', 'advery-reviews' ) }
				</Button>
			</div>
		</div>
	);
}
