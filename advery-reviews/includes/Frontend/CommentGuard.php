<?php
namespace Advery\Reviews\Frontend;

use Advery\Reviews\Support\Settings;
use Advery\Reviews\AntiSpam\SpamGuard;
use Advery\Reviews\AntiSpam\SpamLog;

/**
 * Guards WordPress's OWN native comment form. Our review form is already
 * protected, but spam bots POST straight to `wp-comments-post.php`, which
 * bypasses it completely — and "comments closed" is a per-post setting, so any
 * post left open still accepts them. This hooks `preprocess_comment` (which runs
 * for every incoming comment, including a direct POST) to apply the same
 * content-policy checks, or to switch native commenting off site-wide.
 *
 * Modes (setting `antispam.native_comment_guard`):
 *   off     → do nothing (WordPress behaves normally)
 *   filter  → run the content policy: reject blocked comments, or store links/
 *             disposable-email hits as spam / hold them for moderation
 *   disable → refuse every native comment and force comment forms closed
 *
 * Only native visitor comments are touched. Pingbacks/trackbacks are left alone,
 * and users who can moderate comments are never blocked (so admins/editors can
 * always reply). Our own reviews never flow through here.
 */
class CommentGuard {

	/** Stashed decision from preprocess_comment, applied in pre_comment_approved. */
	private $decision = '';

	public function register() {
		$mode = Settings::get( 'antispam' )['native_comment_guard'] ?? 'off';
		if ( 'off' === $mode || ! in_array( $mode, [ 'filter', 'disable' ], true ) ) {
			return;
		}

		add_filter( 'preprocess_comment', [ $this, 'preprocess' ], 1 );
		add_filter( 'pre_comment_approved', [ $this, 'decide_approval' ], 999 );

		if ( 'disable' === $mode ) {
			// Belt-and-braces: also hide the form everywhere so humans aren't
			// invited to write comments that would be refused anyway.
			add_filter( 'comments_open', '__return_false', 999 );
			add_filter( 'pings_open', '__return_false', 999 );
		}
	}

	/**
	 * Runs for every incoming comment. Rejects (wp_die) blocked comments; stashes
	 * a spam/hold decision for `decide_approval` to apply.
	 *
	 * @param array $commentdata
	 * @return array
	 */
	public function preprocess( $commentdata ) {
		$this->decision = '';

		// Leave pingbacks/trackbacks and non-standard comment types to WordPress.
		$type = (string) ( $commentdata['comment_type'] ?? '' );
		if ( '' !== $type && 'comment' !== $type ) {
			return $commentdata;
		}

		// Never block a user who can moderate comments (admins/editors replying).
		if ( function_exists( 'current_user_can' ) && current_user_can( 'moderate_comments' ) ) {
			return $commentdata;
		}

		$mode = Settings::get( 'antispam' )['native_comment_guard'] ?? 'off';

		if ( 'disable' === $mode ) {
			wp_die(
				esc_html__( 'Comments are closed.', 'advery-reviews' ),
				esc_html__( 'Comments closed', 'advery-reviews' ),
				[ 'response' => 403 ]
			);
		}

		$result = SpamGuard::evaluate_comment(
			[
				'content'      => (string) ( $commentdata['comment_content'] ?? '' ),
				'author_name'  => (string) ( $commentdata['comment_author'] ?? '' ),
				'author_email' => (string) ( $commentdata['comment_author_email'] ?? '' ),
				'author_url'   => (string) ( $commentdata['comment_author_url'] ?? '' ),
			]
		);

		// Diagnostic log (opt-in) of anything we stop or hold on the native form.
		if ( in_array( $result['outcome'], [ 'reject', 'spam', 'hold' ], true ) ) {
			$post_id = (int) ( $commentdata['comment_post_ID'] ?? 0 );
			$pt      = $post_id ? get_post_type( $post_id ) : '';
			SpamLog::record(
				[
					'source'       => 'comment',
					'outcome'      => $result['outcome'],
					'object_type'  => ( 'product' === $pt ) ? 'product' : 'post',
					'object_id'    => $post_id,
					'author_name'  => (string) ( $commentdata['comment_author'] ?? '' ),
					'author_email' => (string) ( $commentdata['comment_author_email'] ?? '' ),
					'author_ip'    => (string) ( $commentdata['comment_author_IP'] ?? '' ),
					'reason'       => implode( ', ', (array) ( $result['reasons'] ?? [] ) ),
					'content'      => (string) ( $commentdata['comment_content'] ?? '' ),
				]
			);
		}

		if ( 'reject' === $result['outcome'] ) {
			$message = $result['message'] ?? __( 'Your comment could not be accepted.', 'advery-reviews' );
			wp_die(
				esc_html( $message ),
				esc_html__( 'Comment blocked', 'advery-reviews' ),
				[ 'response' => 403, 'back_link' => true ]
			);
		}

		if ( 'spam' === $result['outcome'] ) {
			$this->decision = 'spam';
		} elseif ( 'hold' === $result['outcome'] ) {
			$this->decision = 'hold';
		}

		return $commentdata;
	}

	/**
	 * Applies the stashed decision to the comment's approval status.
	 *
	 * @param int|string $approved WordPress's own decision (1, 0, 'spam', 'trash').
	 * @return int|string
	 */
	public function decide_approval( $approved ) {
		if ( 'spam' === $this->decision ) {
			return 'spam';
		}
		if ( 'hold' === $this->decision ) {
			return 0; // pending moderation
		}
		return $approved;
	}
}
