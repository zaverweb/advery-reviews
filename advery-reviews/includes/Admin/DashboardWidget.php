<?php
namespace ZaverWeb\Reviews\Admin;

use ZaverWeb\Reviews\Support\Targets;
use ZaverWeb\Reviews\Database\ReviewRepository;

/**
 * A "Recent Reviews" widget on the WordPress dashboard home: status counts and
 * the latest few reviews with quick links into the moderation panel.
 */
class DashboardWidget {

	public function register() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_add_dashboard_widget(
			'zaverweb_reviews_dashboard',
			__( 'Zaver Web Reviews', 'zaverweb-reviews' ),
			[ $this, 'render' ]
		);
	}

	public function render() {
		$counts = ReviewRepository::status_counts();
		$recent = ReviewRepository::recent( 5 );
		$admin  = admin_url( 'admin.php?page=zaverweb-reviews' );

		printf(
			'<p><strong>%1$s</strong> %2$s · <strong>%3$s</strong> %4$s · <strong>%5$s</strong> %6$s</p>',
			(int) $counts['pending'],
			esc_html__( 'pending', 'zaverweb-reviews' ),
			(int) $counts['approved'],
			esc_html__( 'approved', 'zaverweb-reviews' ),
			(int) $counts['spam'],
			esc_html__( 'spam', 'zaverweb-reviews' )
		);

		if ( empty( $recent ) ) {
			echo '<p>' . esc_html__( 'No reviews yet.', 'zaverweb-reviews' ) . '</p>';
		} else {
			echo '<ul style="margin:0;">';
			foreach ( $recent as $r ) {
				$stars = $r['rating'] > 0 ? str_repeat( '★', $r['rating'] ) : '—';
				printf(
					'<li style="padding:6px 0;border-bottom:1px solid #f0f0f1;"><span style="color:#f5a623">%1$s</span> <strong>%2$s</strong> — %3$s <em style="color:#767676">(%4$s)</em></li>',
					esc_html( $stars ),
					esc_html( Targets::label( $r['object_type'], $r['object_id'] ) ),
					esc_html( wp_trim_words( wp_strip_all_tags( $r['content'] ), 12 ) ),
					esc_html( $r['status'] )
				);
			}
			echo '</ul>';
		}

		printf(
			'<p><a href="%1$s" class="button button-primary">%2$s</a></p>',
			esc_url( $admin ),
			esc_html__( 'Manage reviews', 'zaverweb-reviews' )
		);
	}
}
