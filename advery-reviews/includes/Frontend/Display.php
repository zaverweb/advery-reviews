<?php
namespace Advery\Reviews\Frontend;

use Advery\Reviews\Support\Settings;
use Advery\Reviews\Support\Targets;
use Advery\Reviews\Support\Aggregate;
use Advery\Reviews\AntiSpam\SpamGuard;
use Advery\Reviews\Database\ReviewRepository;

/**
 * Front-end rendering: a `[advery_reviews]` shortcode (and optional auto-append
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
		add_shortcode( 'advery_reviews', [ $this, 'shortcode' ] );
		add_filter( 'the_content', [ $this, 'maybe_append' ], 20 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function enqueue() {
		if ( ! Targets::current() ) {
			return;
		}
		wp_enqueue_style( 'advery-reviews-front', ADVERY_REVIEWS_URL . 'assets/front.css', [], ADVERY_REVIEWS_VERSION );
		wp_enqueue_script( 'advery-reviews-front', ADVERY_REVIEWS_URL . 'assets/front.js', [], ADVERY_REVIEWS_VERSION, true );

		$as       = Settings::antispam();
		$provider = ( '' !== $as['captcha_site_key'] ) ? $as['captcha_provider'] : 'none';
		$this->enqueue_captcha( $provider, $as['captcha_site_key'] );

		// Appearance settings → CSS custom properties on the widget (colors,
		// radius, density, size). Printed first so the owner's own custom CSS
		// below can still override anything.
		$appearance = Settings::appearance_css();
		if ( '' !== $appearance ) {
			wp_add_inline_style( 'advery-reviews-front', $appearance );
		}

		// Owner custom CSS, printed inline against our stylesheet handle.
		$css = (string) Settings::get( 'custom_css', '' );
		if ( '' !== trim( $css ) ) {
			wp_add_inline_style( 'advery-reviews-front', $css );
		}

		wp_localize_script(
			'advery-reviews-front',
			'AdveryReviewsFront',
			[
				'rest'     => esc_url_raw( rest_url( ADVERY_REVIEWS_REST_NAMESPACE . '/submit' ) ),
				'listBase' => esc_url_raw( rest_url( ADVERY_REVIEWS_REST_NAMESPACE . '/list' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'captcha'  => [ 'provider' => $provider, 'siteKey' => $provider !== 'none' ? $as['captcha_site_key'] : '' ],
				'i18n'     => [
					'sending'  => __( 'Sending…', 'advery-reviews' ),
					'submit'   => __( 'Submit review', 'advery-reviews' ),
					'error'    => __( 'Something went wrong. Please try again.', 'advery-reviews' ),
					'loadMore' => __( 'Load more reviews', 'advery-reviews' ),
					'loading'  => __( 'Loading…', 'advery-reviews' ),
					'anonymous' => __( 'Anonymous', 'advery-reviews' ),
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
			wp_enqueue_script( 'advery-reviews-captcha', $src, [], null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
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
	 * `[advery_reviews]` — optional type/id atts, else the current target.
	 *
	 * @param array $atts
	 * @return string
	 */
	public function shortcode( $atts ) {
		$atts   = shortcode_atts( [ 'type' => '', 'id' => 0 ], $atts, 'advery_reviews' );
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
	 * Render the whole widget for a target.
	 *
	 * @param string $object_type
	 * @param int    $object_id
	 * @return string
	 */
	public function render( $object_type, $object_id ) {
		if ( ! Targets::is_enabled( $object_type, $object_id ) ) {
			return '';
		}

		// Mark this target as printed so auto-append doesn't duplicate it when a
		// shortcode / block / Elementor widget already placed it.
		self::$printed[ $object_type . ':' . (int) $object_id ] = true;

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

		ob_start();
		?>
		<div class="advery-reviews" data-object-type="<?php echo esc_attr( $object_type ); ?>" data-object-id="<?php echo esc_attr( $object_id ); ?>" data-load-mode="<?php echo esc_attr( $mode ); ?>" data-per-page="<?php echo esc_attr( $per ); ?>" data-total="<?php echo esc_attr( $total ); ?>" data-page="1">
			<div class="advery-reviews__summary">
				<span class="advery-reviews__avg"><?php echo esc_html( number_format_i18n( $agg['avg'], 1 ) ); ?></span>
				<span class="advery-reviews__stars" aria-hidden="true"><?php echo esc_html( $this->stars( $agg['avg'] ) ); ?></span>
				<span class="advery-reviews__count">
					<?php
					printf(
						/* translators: %d: review count */
						esc_html( _n( '%d review', '%d reviews', $agg['review_count'], 'advery-reviews' ) ),
						(int) $agg['review_count']
					);
					?>
				</span>
			</div>

			<ul class="advery-reviews__list">
				<?php foreach ( $reviews as $r ) : ?>
					<li class="advery-reviews__item">
						<div class="advery-reviews__item-head">
							<strong class="advery-reviews__author"><?php echo esc_html( $r['author_name'] ); ?></strong>
							<?php if ( $r['rating'] > 0 ) : ?>
								<span class="advery-reviews__stars" aria-label="<?php echo esc_attr( sprintf( '%d / 5', $r['rating'] ) ); ?>"><?php echo esc_html( $this->stars( $r['rating'] ) ); ?></span>
							<?php endif; ?>
						</div>
						<?php if ( $r['title'] ) : ?>
							<div class="advery-reviews__title"><?php echo esc_html( $r['title'] ); ?></div>
						<?php endif; ?>
						<div class="advery-reviews__content"><?php echo wp_kses_post( wpautop( $r['content'] ) ); ?></div>
						<?php if ( ! empty( $r['meta']['reply'] ) ) : ?>
							<div class="advery-reviews__reply">
								<span class="advery-reviews__reply-label"><?php esc_html_e( 'Response from the owner', 'advery-reviews' ); ?></span>
								<div class="advery-reviews__reply-text"><?php echo wp_kses_post( wpautop( $r['meta']['reply'] ) ); ?></div>
							</div>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>

			<?php if ( 'load_more' === $mode && $total > $per ) : ?>
				<div class="advery-reviews__more">
					<button type="button" class="advery-reviews__loadmore"><?php esc_html_e( 'Load more reviews', 'advery-reviews' ); ?></button>
				</div>
			<?php elseif ( 'paginate' === $mode && $pages > 1 ) : ?>
				<nav class="advery-reviews__pager" aria-label="<?php esc_attr_e( 'Reviews pages', 'advery-reviews' ); ?>">
					<?php for ( $p = 1; $p <= $pages; $p++ ) : ?>
						<button type="button" class="advery-reviews__page<?php echo 1 === $p ? ' is-active' : ''; ?>" data-page="<?php echo (int) $p; ?>"><?php echo (int) $p; ?></button>
					<?php endfor; ?>
				</nav>
			<?php endif; ?>

			<?php if ( $can ) : ?>
				<form class="advery-reviews__form">
					<h3><?php esc_html_e( 'Write a review', 'advery-reviews' ); ?></h3>
					<div class="advery-reviews__rating-input" role="radiogroup" aria-label="<?php esc_attr_e( 'Your rating', 'advery-reviews' ); ?>">
						<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
							<button type="button" class="advery-reviews__star-btn" data-value="<?php echo (int) $i; ?>" aria-label="<?php echo esc_attr( sprintf( '%d', $i ) ); ?>">☆</button>
						<?php endfor; ?>
						<input type="hidden" name="rating" value="0" />
					</div>
					<?php if ( ! $logged ) : ?>
						<input type="text" name="author_name" placeholder="<?php esc_attr_e( 'Your name', 'advery-reviews' ); ?>" required />
						<input type="email" name="author_email" placeholder="<?php esc_attr_e( 'Your email', 'advery-reviews' ); ?>" required />
					<?php endif; ?>
					<input type="text" name="title" placeholder="<?php esc_attr_e( 'Title (optional)', 'advery-reviews' ); ?>" />
					<textarea name="content" rows="4" placeholder="<?php esc_attr_e( 'Your review', 'advery-reviews' ); ?>" required></textarea>
					<?php // Honeypot. Neutral name (no "website"/"url"/"email") so browsers never autofill it for a real visitor; hidden with an RTL-safe clip (never a negative offset that widens the page). Inline style is a belt-and-suspenders in case the stylesheet is cached/overridden. ?>
					<input type="text" name="advery_hp" class="advery-reviews__hp" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute!important;width:1px;height:1px;padding:0;margin:-1px;border:0;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;" />
					<input type="hidden" name="advery_ts" value="<?php echo esc_attr( $token['ts'] ); ?>" />
					<input type="hidden" name="advery_tk" value="<?php echo esc_attr( $token['tk'] ); ?>" />
					<?php if ( 'recaptcha_v2' === $captcha ) : ?>
						<div class="g-recaptcha" data-sitekey="<?php echo esc_attr( $as['captcha_site_key'] ); ?>"></div>
					<?php elseif ( 'hcaptcha' === $captcha ) : ?>
						<div class="h-captcha" data-sitekey="<?php echo esc_attr( $as['captcha_site_key'] ); ?>"></div>
					<?php elseif ( 'turnstile' === $captcha ) : ?>
						<div class="cf-turnstile" data-sitekey="<?php echo esc_attr( $as['captcha_site_key'] ); ?>"></div>
					<?php endif; ?>
					<button type="submit" class="advery-reviews__submit"><?php esc_html_e( 'Submit review', 'advery-reviews' ); ?></button>
					<p class="advery-reviews__msg" role="status" aria-live="polite"></p>
				</form>
			<?php else : ?>
				<p class="advery-reviews__login"><?php esc_html_e( 'Please log in to leave a review.', 'advery-reviews' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
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
