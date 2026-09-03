<?php
namespace ZaverWeb\Reviews\Frontend;

use ZaverWeb\Reviews\Support\Settings;
use ZaverWeb\Reviews\Support\Targets;
use ZaverWeb\Reviews\Support\Aggregate;
use ZaverWeb\Reviews\Support\Avatar;
use ZaverWeb\Reviews\AntiSpam\SpamGuard;
use ZaverWeb\Reviews\Database\ReviewRepository;

/**
 * Front-end rendering: a `[zaverweb_reviews]` shortcode (and optional auto-append
 * to enabled post types) that shows the rating summary, the approved reviews,
 * and a submission form. A small vanilla-JS file (no framework on the front
 * end) handles the star picker and the REST submission.
 */
class Display {

	/** Targets already printed this request (de-dup: shortcode/block/Elementor vs auto-append). */
	private static $printed = [];

	/**
	 * @param string $object_type
	 * @param int    $object_id
	 * @return bool
	 */
	public static function already_printed( $object_type, $object_id ) {
		return isset( self::$printed[ $object_type . ':' . (int) $object_id ] );
	}

	public function register() {
		add_shortcode( 'zaverweb_reviews', [ $this, 'shortcode' ] );
		add_filter( 'the_content', [ $this, 'maybe_append' ], 20 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function enqueue() {
		if ( ! Targets::current() ) {
			return;
		}
		$this->ensure_assets();
	}

	/**
	 * Enqueue the front-end stylesheet + script (and inline appearance/custom
	 * CSS + config). Safe to call during render() as well as on the
	 * wp_enqueue_scripts hook, so the widget/shortcode/block are styled and
	 * interactive even on a page that is not itself an enabled review target
	 * (late-enqueued assets print in the footer). Idempotent.
	 */
	public function ensure_assets() {
		if ( wp_style_is( 'zaverweb-reviews-front', 'enqueued' ) ) {
			return;
		}
		wp_enqueue_style( 'zaverweb-reviews-front', ZAVERWEB_REVIEWS_URL . 'assets/front.css', [], ZAVERWEB_REVIEWS_VERSION );
		wp_enqueue_script( 'zaverweb-reviews-front', ZAVERWEB_REVIEWS_URL . 'assets/front.js', [], ZAVERWEB_REVIEWS_VERSION, true );

		$as       = Settings::antispam();
		$provider = ( '' !== $as['captcha_site_key'] ) ? $as['captcha_provider'] : 'none';
		$this->enqueue_captcha( $provider, $as['captcha_site_key'] );

		// Appearance settings → CSS custom properties on the widget (colors,
		// radius, density, size). Printed first so the owner's own custom CSS
		// below can still override anything.
		$appearance = Settings::appearance_css();
		if ( '' !== $appearance ) {
			wp_add_inline_style( 'zaverweb-reviews-front', $appearance );
		}

		// Owner custom CSS, printed inline against our stylesheet handle.
		$css = (string) Settings::get( 'custom_css', '' );
		if ( '' !== trim( $css ) ) {
			wp_add_inline_style( 'zaverweb-reviews-front', $css );
		}

		wp_localize_script(
			'zaverweb-reviews-front',
			'ZaverWebReviewsFront',
			[
				'rest'     => esc_url_raw( rest_url( ZAVERWEB_REVIEWS_REST_NAMESPACE . '/submit' ) ),
				'listBase' => esc_url_raw( rest_url( ZAVERWEB_REVIEWS_REST_NAMESPACE . '/list' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'captcha'  => [ 'provider' => $provider, 'siteKey' => $provider !== 'none' ? $as['captcha_site_key'] : '' ],
				'i18n'     => [
					'sending'  => __( 'Sending…', 'zaverweb-reviews' ),
					'submit'   => __( 'Submit review', 'zaverweb-reviews' ),
					'error'    => __( 'Something went wrong. Please try again.', 'zaverweb-reviews' ),
					'loadMore' => __( 'Load more reviews', 'zaverweb-reviews' ),
					'loading'  => __( 'Loading…', 'zaverweb-reviews' ),
					'anonymous' => __( 'Anonymous', 'zaverweb-reviews' ),
				],
			]
		);
	}

	/**
	 * Enqueue the provider's widget script (public site key only).
	 *
	 * @param string $provider
	 * @param string $site_key
	 */
	private function enqueue_captcha( $provider, $site_key ) {
		if ( 'none' === $provider || '' === $site_key ) {
			return;
		}
		$src = '';
		switch ( $provider ) {
			case 'recaptcha_v3':
				$src = 'https://www.google.com/recaptcha/api.js?render=' . rawurlencode( $site_key );
				break;
			case 'recaptcha_v2':
				$src = 'https://www.google.com/recaptcha/api.js';
				break;
			case 'hcaptcha':
				$src = 'https://js.hcaptcha.com/1/api.js';
				break;
			case 'turnstile':
				$src = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
				break;
		}
		if ( $src ) {
			wp_enqueue_script( 'zaverweb-reviews-captcha', $src, [], null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		}
	}

	/**
	 * Auto-append the widget to the content of enabled singular targets.
	 *
	 * @param string $content
	 * @return string
	 */
	public function maybe_append( $content ) {
		if ( is_admin() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		if ( ! Settings::get( 'auto_append' ) ) {
			return $content;
		}
		// Avoid double output: if we've taken over the comments area, that
		// renders the widget instead.
		if ( Settings::get( 'replace_comments' ) ) {
			return $content;
		}
		$target = Targets::current();
		if ( ! $target || 'term' === $target[0] ) {
			return $content; // term archives don't run the_content per-item
		}
		// Already placed by a shortcode / block / Elementor widget → don't repeat.
		if ( self::already_printed( $target[0], $target[1] ) ) {
			return $content;
		}
		return $content . $this->render( $target[0], $target[1] );
	}

	/**
	 * `[zaverweb_reviews]` — optional type/id atts, else the current target.
	 *
	 * @param array $atts
	 * @return string
	 */
	public function shortcode( $atts ) {
		$atts   = shortcode_atts( [ 'type' => '', 'id' => 0 ], $atts, 'zaverweb_reviews' );
		$type   = $atts['type'] ? sanitize_key( $atts['type'] ) : '';
		$id     = (int) $atts['id'];

		if ( ! $type || ! $id ) {
			$target = Targets::current();
			if ( ! $target ) {
				return '';
			}
			list( $type, $id ) = $target;
		}

		return $this->render( $type, $id );
	}

	/**
	 * Render the widget for a target without needing an existing instance
	 * (used by integrations such as the WooCommerce reviews-tab takeover).
	 *
	 * @param string $object_type
	 * @param int    $object_id
	 * @return string
	 */
	public static function widget( $object_type, $object_id, array $opts = [] ) {
		return ( new self() )->render( $object_type, $object_id, $opts );
	}

	/**
	 * Render the whole widget for a target.
	 *
	 * @param string $object_type
	 * @param int    $object_id
	 * @param array  $opts { skin?: string, avatar?: string } per-render overrides
	 *                     (e.g. from the Elementor widget); empty ⇒ global settings.
	 * @return string
	 */
	public function render( $object_type, $object_id, array $opts = [] ) {
		if ( ! Targets::is_enabled( $object_type, $object_id ) ) {
			return '';
		}

		// De-dup: only ever render a given target once per request. This makes
		// auto-append and an explicit placement (shortcode / block / Elementor
		// widget) mutually exclusive regardless of which runs first — e.g. an
		// Elementor single-post template that both prints the post content
		// (auto-append) and includes our widget no longer shows it twice.
		if ( self::already_printed( $object_type, $object_id ) ) {
			return '';
		}
		self::$printed[ $object_type . ':' . (int) $object_id ] = true;

		// Ensure the stylesheet + script load even when this page isn't itself a
		// review target (e.g. a shortcode/Elementor widget pointing at a specific
		// item on a landing page).
		$this->ensure_assets();

		$agg     = Aggregate::for( $object_type, $object_id );
		$total   = (int) $agg['review_count'];
		$per     = max( 1, (int) Settings::get( 'reviews_per_page', 10 ) );
		$mode    = Settings::get( 'load_mode', 'all' );
		// 'all' server-renders every review (best for bots); paged modes render
		// the first page server-side and fetch the rest over AJAX.
		$limit   = ( 'all' === $mode ) ? 1000 : $per;
		$reviews = ReviewRepository::approved_for( $object_type, $object_id, $limit );
		$logged  = is_user_logged_in();
		$can     = ! ( 'logged_in' === Settings::get( 'who_can_submit' ) && ! $logged );
		$token   = SpamGuard::issue_token();
		$as      = Settings::antispam();
		$captcha = ( '' !== $as['captcha_site_key'] ) ? $as['captcha_provider'] : 'none';
		$pages   = ( $per > 0 ) ? (int) ceil( $total / $per ) : 1;
		$skin    = ! empty( $opts['skin'] ) ? $opts['skin'] : ( Settings::appearance()['skin'] ?? 'card' );
		$avatar_override = $opts['avatar'] ?? null;

		ob_start();
		?>
		<div class="zaverweb-reviews zaverweb-reviews--<?php echo esc_attr( $skin ); ?>" data-object-type="<?php echo esc_attr( $object_type ); ?>" data-object-id="<?php echo esc_attr( $object_id ); ?>" data-load-mode="<?php echo esc_attr( $mode ); ?>" data-per-page="<?php echo esc_attr( $per ); ?>" data-total="<?php echo esc_attr( $total ); ?>" data-page="1">
			<div class="zaverweb-reviews__summary">
				<span class="zaverweb-reviews__avg"><?php echo esc_html( number_format_i18n( $agg['avg'], 1 ) ); ?></span>
				<span class="zaverweb-reviews__stars" aria-hidden="true"><?php echo esc_html( $this->stars( $agg['avg'] ) ); ?></span>
				<span class="zaverweb-reviews__count">
					<?php
					printf(
						/* translators: %d: review count */
						esc_html( _n( '%d review', '%d reviews', $agg['review_count'], 'zaverweb-reviews' ) ),
						(int) $agg['review_count']
					);
					?>
				</span>
			</div>

			<ul class="zaverweb-reviews__list">
				<?php foreach ( $reviews as $r ) : ?>
					<li class="zaverweb-reviews__item">
						<div class="zaverweb-reviews__item-head">
							<?php echo Avatar::html( $r, $avatar_override ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_* internally ?>
							<div class="zaverweb-reviews__meta-col">
								<strong class="zaverweb-reviews__author"><?php echo esc_html( $r['author_name'] ); ?></strong>
								<?php if ( ! empty( $r['created_at'] ) ) : ?>
									<span class="zaverweb-reviews__date"><?php echo esc_html( $this->review_date( $r['created_at'] ) ); ?></span>
								<?php endif; ?>
							</div>
							<?php if ( $r['rating'] > 0 ) : ?>
								<span class="zaverweb-reviews__stars" aria-label="<?php echo esc_attr( sprintf( '%d / 5', $r['rating'] ) ); ?>"><?php echo esc_html( $this->stars( $r['rating'] ) ); ?></span>
							<?php endif; ?>
						</div>
						<?php if ( $r['title'] ) : ?>
							<div class="zaverweb-reviews__title"><?php echo esc_html( $r['title'] ); ?></div>
						<?php endif; ?>
						<div class="zaverweb-reviews__content"><?php echo wp_kses_post( wpautop( $r['content'] ) ); ?></div>
						<?php if ( ! empty( $r['meta']['reply'] ) ) : ?>
							<div class="zaverweb-reviews__reply">
								<span class="zaverweb-reviews__reply-label"><?php esc_html_e( 'Response from the owner', 'zaverweb-reviews' ); ?></span>
								<div class="zaverweb-reviews__reply-text"><?php echo wp_kses_post( wpautop( $r['meta']['reply'] ) ); ?></div>
							</div>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>

			<?php if ( 'load_more' === $mode && $total > $per ) : ?>
				<div class="zaverweb-reviews__more">
					<button type="button" class="zaverweb-reviews__loadmore"><?php esc_html_e( 'Load more reviews', 'zaverweb-reviews' ); ?></button>
				</div>
			<?php elseif ( 'paginate' === $mode && $pages > 1 ) : ?>
				<nav class="zaverweb-reviews__pager" aria-label="<?php esc_attr_e( 'Reviews pages', 'zaverweb-reviews' ); ?>">
					<?php for ( $p = 1; $p <= $pages; $p++ ) : ?>
						<button type="button" class="zaverweb-reviews__page<?php echo 1 === $p ? ' is-active' : ''; ?>" data-page="<?php echo (int) $p; ?>"><?php echo (int) $p; ?></button>
					<?php endfor; ?>
				</nav>
			<?php endif; ?>

			<?php if ( $can ) : ?>
				<form class="zaverweb-reviews__form">
					<h3><?php esc_html_e( 'Write a review', 'zaverweb-reviews' ); ?></h3>
					<div class="zaverweb-reviews__rating-input" role="radiogroup" aria-label="<?php esc_attr_e( 'Your rating', 'zaverweb-reviews' ); ?>">
						<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
							<button type="button" class="zaverweb-reviews__star-btn" data-value="<?php echo (int) $i; ?>" aria-label="<?php echo esc_attr( sprintf( '%d', $i ) ); ?>">☆</button>
						<?php endfor; ?>
						<input type="hidden" name="rating" value="0" />
					</div>
					<?php if ( ! $logged ) : ?>
						<input type="text" name="author_name" placeholder="<?php esc_attr_e( 'Your name', 'zaverweb-reviews' ); ?>" required />
						<input type="email" name="author_email" placeholder="<?php esc_attr_e( 'Your email', 'zaverweb-reviews' ); ?>" required />
					<?php endif; ?>
					<input type="text" name="title" placeholder="<?php esc_attr_e( 'Title (optional)', 'zaverweb-reviews' ); ?>" />
					<textarea name="content" rows="4" placeholder="<?php esc_attr_e( 'Your review', 'zaverweb-reviews' ); ?>" required></textarea>
					<?php // Honeypot. Neutral name (no "website"/"url"/"email") so browsers never autofill it for a real visitor; hidden with an RTL-safe clip (never a negative offset that widens the page). Inline style is a belt-and-suspenders in case the stylesheet is cached/overridden. ?>
					<input type="text" name="zaverweb_hp" class="zaverweb-reviews__hp" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute!important;width:1px;height:1px;padding:0;margin:-1px;border:0;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;" />
					<input type="hidden" name="zaverweb_ts" value="<?php echo esc_attr( $token['ts'] ); ?>" />
					<input type="hidden" name="zaverweb_tk" value="<?php echo esc_attr( $token['tk'] ); ?>" />
					<?php if ( 'recaptcha_v2' === $captcha ) : ?>
						<div class="g-recaptcha" data-sitekey="<?php echo esc_attr( $as['captcha_site_key'] ); ?>"></div>
					<?php elseif ( 'hcaptcha' === $captcha ) : ?>
						<div class="h-captcha" data-sitekey="<?php echo esc_attr( $as['captcha_site_key'] ); ?>"></div>
					<?php elseif ( 'turnstile' === $captcha ) : ?>
						<div class="cf-turnstile" data-sitekey="<?php echo esc_attr( $as['captcha_site_key'] ); ?>"></div>
					<?php endif; ?>
					<button type="submit" class="zaverweb-reviews__submit"><?php esc_html_e( 'Submit review', 'zaverweb-reviews' ); ?></button>
					<p class="zaverweb-reviews__msg" role="status" aria-live="polite"></p>
				</form>
			<?php else : ?>
				<p class="zaverweb-reviews__login"><?php esc_html_e( 'Please log in to leave a review.', 'zaverweb-reviews' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Localised review date (site date format).
	 *
	 * @param string $mysql created_at
	 * @return string
	 */
	private function review_date( $mysql ) {
		$ts = strtotime( (string) $mysql );
		return $ts ? date_i18n( get_option( 'date_format' ), $ts ) : '';
	}

	/**
	 * @param float $value
	 * @return string
	 */
	private function stars( $value ) {
		$full = (int) round( $value );
		$full = max( 0, min( 5, $full ) );
		return str_repeat( '★', $full ) . str_repeat( '☆', 5 - $full );
	}
}
