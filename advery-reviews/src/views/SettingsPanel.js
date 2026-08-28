import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Panel,
	PanelBody,
	ToggleControl,
	CheckboxControl,
	RadioControl,
	SelectControl,
	TextControl,
	Button,
	Notice,
} from '@wordpress/components';
import { api } from '../api';

export default function SettingsPanel( { boot, notify } ) {
	const [ s, setS ] = useState( boot.settings );
	const [ saving, setSaving ] = useState( false );

	const set = ( patch ) => setS( { ...s, ...patch } );
	const as = s.antispam || {};
	const setAs = ( patch ) => set( { antispam: { ...as, ...patch } } );
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
					<TextControl
						type="number"
						label={ __( 'Minimum review length (characters)', 'advery-reviews' ) }
						value={ s.min_content_length }
						onChange={ ( v ) => set( { min_content_length: parseInt( v, 10 ) || 0 } ) }
						__nextHasNoMarginBottom
					/>
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
						label={ __( 'Max links allowed in a review', 'advery-reviews' ) }
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
						] }
						onChange={ ( v ) => setAs( { link_action: v } ) }
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
