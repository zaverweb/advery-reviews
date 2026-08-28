<?php
namespace Advery\Reviews\Rest;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Advery\Reviews\Support\Settings;
use Advery\Reviews\Support\Targets;
use Advery\Reviews\Support\Aggregate;
use Advery\Reviews\Support\Sanitizer;
use Advery\Reviews\Database\ReviewRepository;
use Advery\Reviews\Database\StatsRepository;
use Advery\Reviews\AntiSpam\SpamGuard;
use Advery\Reviews\Support\Maintenance;
use Advery\Reviews\Migration\CommentImporter;
use Advery\Reviews\Migration\CommentExporter;
use Advery\Reviews\Migration\CsvExporter;
use Advery\Reviews\Migration\DataImporter;
use Advery\Reviews\AI\Client as AIClient;
use Advery\Reviews\AI\Tasks as AITasks;
use Advery\Reviews\AI\AuditLog;
use Advery\Reviews\Email\Digest;

/**
 * REST API for both the public submission/display and the React moderation
 * panel. Public routes are nonce-guarded; admin routes require manage_options.
 */
class RestController {

	public function register_routes() {
		$ns = ADVERY_REVIEWS_REST_NAMESPACE;

		// ---- Public ----
		register_rest_route(
			$ns,
			'/submit',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'submit' ],
				'permission_callback' => '__return_true',
			]
		);
		register_rest_route(
			$ns,
			'/list/(?P<type>[a-z]+)/(?P<id>\d+)',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'public_list' ],
				'permission_callback' => '__return_true',
			]
		);

		// ---- Admin ----
		register_rest_route(
			$ns,
			'/bootstrap',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'bootstrap' ],
				'permission_callback' => [ $this, 'can_manage' ],
			]
		);
		register_rest_route(
			$ns,
			'/reviews',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'list_reviews' ],
				'permission_callback' => [ $this, 'can_manage' ],
			]
		);
		register_rest_route(
			$ns,
			'/reviews/(?P<id>\d+)/status',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'update_status' ],
				'permission_callback' => [ $this, 'can_manage' ],
			]
		);
		register_rest_route(
			$ns,
			'/reviews/(?P<id>\d+)',
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_review' ],
				'permission_callback' => [ $this, 'can_manage' ],
			]
		);
		register_rest_route(
			$ns,
			'/reviews/bulk',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'bulk' ],
				'permission_callback' => [ $this, 'can_manage' ],
			]
		);
		register_rest_route(
			$ns,
			'/settings',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'save_settings' ],
				'permission_callback' => [ $this, 'can_manage' ],
			]
		);
		register_rest_route(
			$ns,
			'/maintenance',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'maintenance' ],
				'permission_callback' => [ $this, 'can_manage' ],
			]
		);
		register_rest_route(
			$ns,
			'/migration/preview',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'migration_preview' ],
				'permission_callback' => [ $this, 'can_manage' ],
			]
		);
		register_rest_route(
			$ns,
			'/migration/import',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'migration_import' ],
				'permission_callback' => [ $this, 'can_manage' ],
			]
		);
		register_rest_route(
			$ns,
			'/migration/export',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'migration_export' ],
				'permission_callback' => [ $this, 'can_manage' ],
			]
		);
		register_rest_route(
			$ns,
			'/export-csv',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'export_csv' ],
				'permission_callback' => [ $this, 'can_manage' ],
			]
		);
		register_rest_route(
			$ns,
			'/migration/import-data',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'import_data' ],
				'permission_callback' => [ $this, 'can_manage' ],
			]
		);
		register_rest_route(
			$ns,
			'/ai/(?P<task>[a-z]+)',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'ai_task' ],
				'permission_callback' => [ $this, 'can_manage' ],
			]
		);
		register_rest_route(
			$ns,
			'/reviews/(?P<id>\d+)/reply',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'save_reply' ],
				'permission_callback' => [ $this, 'can_manage' ],
			]
		);
	}

	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/* ---------------- Public ---------------- */

	/**
	 * Handle a front-end review submission.
	 *
	 * @param WP_REST_Request $req
	 * @return WP_REST_Response|WP_Error
	 */
	public function submit( WP_REST_Request $req ) {
		// Nonce (best-effort; the form prints a fresh one).
		$nonce = $req->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'advery_reviews_nonce', __( 'Your session expired. Please refresh and try again.', 'advery-reviews' ), [ 'status' => 403 ] );
		}

		// Honeypot: bots fill hidden fields.
		if ( '' !== trim( (string) $req->get_param( 'website_hp' ) ) ) {
			return new WP_Error( 'advery_reviews_spam', __( 'Rejected.', 'advery-reviews' ), [ 'status' => 400 ] );
		}

		$object_type = sanitize_key( (string) $req->get_param( 'object_type' ) );
		$object_id   = (int) $req->get_param( 'object_id' );

		if ( ! Targets::is_enabled( $object_type, $object_id ) ) {
			return new WP_Error( 'advery_reviews_target', __( 'Reviews are not enabled here.', 'advery-reviews' ), [ 'status' => 400 ] );
		}

		$logged_in = is_user_logged_in();
		if ( 'logged_in' === Settings::get( 'who_can_submit' ) && ! $logged_in ) {
			return new WP_Error( 'advery_reviews_login', __( 'Please log in to leave a review.', 'advery-reviews' ), [ 'status' => 401 ] );
		}

		$as     = Settings::antispam();
		$rating = max( 0, min( 5, (int) $req->get_param( 'rating' ) ) );

		// Keep the raw input for link/injection detection (before HTML is
		// stripped), and the sanitized values for storage. Every field is length-
		// capped and cleaned; the review body is reduced to plain text.
		$raw_content = (string) $req->get_param( 'content' );
		$raw_title   = (string) $req->get_param( 'title' );
		$content     = Sanitizer::content( $raw_content, (int) $as['max_chars'] );
		$title       = Sanitizer::text( $raw_title, 120 );

		if ( Settings::get( 'rating_required' ) && $rating < 1 ) {
			return new WP_Error( 'advery_reviews_rating', __( 'Please choose a rating.', 'advery-reviews' ), [ 'status' => 400 ] );
		}
		if ( '' === $content ) {
			return new WP_Error( 'advery_reviews_content', __( 'Please write your review.', 'advery-reviews' ), [ 'status' => 400 ] );
		}

		if ( $logged_in ) {
			$user         = wp_get_current_user();
			$user_id      = (int) $user->ID;
			$raw_name     = (string) $user->display_name;
			$author_name  = Sanitizer::text( $raw_name, (int) $as['max_name_chars'] );
			$author_email = Sanitizer::email( $user->user_email );
		} else {
			$user_id      = 0;
			$raw_name     = (string) $req->get_param( 'author_name' );
			$author_name  = Sanitizer::text( $raw_name, (int) $as['max_name_chars'] );
			$author_email = Sanitizer::email( (string) $req->get_param( 'author_email' ) );
			if ( '' === $author_name || '' === $author_email ) {
				return new WP_Error( 'advery_reviews_author', __( 'Please enter your name and a valid email.', 'advery-reviews' ), [ 'status' => 400 ] );
			}
		}

		if ( Settings::get( 'one_per_user' )
			&& ReviewRepository::has_reviewed( $object_type, $object_id, $user_id, $author_email ) ) {
			return new WP_Error( 'advery_reviews_dupe', __( 'You have already reviewed this.', 'advery-reviews' ), [ 'status' => 409 ] );
		}

		$ip = $this->client_ip();

		// Layered anti-spam. Returns an outcome the status derives from.
		$guard = SpamGuard::evaluate(
			[
				'object_type'    => $object_type,
				'object_id'      => $object_id,
				'content'        => $content,
				'title'          => $title,
				'author_name'    => $author_name,
				'raw_content'    => $raw_content,
				'raw_title'      => $raw_title,
				'raw_name'       => $raw_name,
				'author_email'   => $author_email,
				'author_user_id' => $user_id,
				'author_ip'      => $ip,
				'ts'             => (int) $req->get_param( 'advery_ts' ),
				'tk'             => (string) $req->get_param( 'advery_tk' ),
				'website_hp'     => (string) $req->get_param( 'website_hp' ),
				'captcha_token'  => (string) $req->get_param( 'captcha_token' ),
			]
		);

		if ( 'reject' === $guard['outcome'] ) {
			return new WP_Error(
				'advery_reviews_rejected',
				isset( $guard['message'] ) ? $guard['message'] : __( 'Your review could not be accepted.', 'advery-reviews' ),
				[ 'status' => 400 ]
			);
		}

		if ( 'spam' === $guard['outcome'] ) {
			$status = 'spam';
		} elseif ( 'hold' === $guard['outcome'] ) {
			$status = 'pending';
		} else {
			$status = 'auto' === Settings::get( 'moderation' ) ? 'approved' : 'pending';
		}

		$id = ReviewRepository::create(
			[
				'object_type'    => $object_type,
				'object_id'      => $object_id,
				'rating'         => $rating,
				'author_name'    => $author_name,
				'author_email'   => $author_email,
				'author_user_id' => $user_id,
				'title'          => $title,
				'content'        => $content, // already sanitized via Sanitizer::content()
				'status'         => $status,
				'author_ip'      => $ip,
				'spam_score'     => (int) $guard['score'],
				'meta'           => [ 'spam_reasons' => $guard['reasons'] ],
			]
		);

		if ( ! $id ) {
			return new WP_Error( 'advery_reviews_failed', __( 'Could not save your review.', 'advery-reviews' ), [ 'status' => 500 ] );
		}

		// Don't email the owner for auto-classified spam.
		if ( 'spam' !== $status ) {
			do_action( 'advery_reviews_created', $id );
		}

		return new WP_REST_Response(
			[
				'ok'      => true,
				'status'  => $status,
				'message' => 'approved' === $status
					? __( 'Thanks! Your review is published.', 'advery-reviews' )
					: __( 'Thanks! Your review is awaiting moderation.', 'advery-reviews' ),
			],
			201
		);
	}

	/**
	 * Public list of approved reviews + aggregate for one object.
	 *
	 * @param WP_REST_Request $req
	 * @return WP_REST_Response
	 */
	public function public_list( WP_REST_Request $req ) {
		$type = sanitize_key( (string) $req['type'] );
		$id   = (int) $req['id'];
		$page = max( 1, (int) $req->get_param( 'page' ) );
		$per  = max( 1, min( 50, (int) ( $req->get_param( 'per_page' ) ?: Settings::get( 'reviews_per_page', 10 ) ) ) );

		$items = ReviewRepository::approved_for( $type, $id, $per, ( $page - 1 ) * $per );
		$agg   = Aggregate::for( $type, $id );

		return new WP_REST_Response(
			[
				'aggregate' => $agg,
				'items'     => array_map( [ $this, 'public_shape' ], $items ),
				'page'      => $page,
				'per_page'  => $per,
				'total'     => (int) $agg['review_count'],
			],
			200
		);
	}

	/* ---------------- Admin ---------------- */

	public function bootstrap() {
		return new WP_REST_Response(
			[
				'settings'   => Settings::all(),
				'postTypes'  => $this->post_types(),
				'taxonomies' => $this->taxonomies(),
				'counts'     => ReviewRepository::status_counts(),
				'wooActive'  => Targets::woo_active(),
				'coreActive' => defined( 'ADVERY_SCHEMA_VERSION' ),
				'version'    => ADVERY_REVIEWS_VERSION,
				'ai'         => [
					'configured' => AIClient::configured(),
					'today'      => AuditLog::today(),
					'prompts'    => [
						'reply'     => AITasks::default_prompt( 'reply' ),
						'moderate'  => AITasks::default_prompt( 'moderate' ),
						'translate' => AITasks::default_prompt( 'translate' ),
						'summarize' => AITasks::default_prompt( 'summarize' ),
					],
				],
			],
			200
		);
	}

	public function list_reviews( WP_REST_Request $req ) {
		$result = ReviewRepository::query(
			[
				'status'      => sanitize_key( (string) $req->get_param( 'status' ) ),
				'object_type' => sanitize_key( (string) $req->get_param( 'object_type' ) ),
				'rating'      => (int) $req->get_param( 'rating' ),
				'search'      => sanitize_text_field( (string) $req->get_param( 'search' ) ),
				'per_page'    => (int) ( $req->get_param( 'per_page' ) ?: 20 ),
				'page'        => (int) ( $req->get_param( 'page' ) ?: 1 ),
				'orderby'     => sanitize_key( (string) $req->get_param( 'orderby' ) ),
				'order'       => sanitize_key( (string) $req->get_param( 'order' ) ),
			]
		);

		$result['items'] = array_map( [ $this, 'admin_shape' ], $result['items'] );
		$result['counts'] = ReviewRepository::status_counts();

		return new WP_REST_Response( $result, 200 );
	}

	public function update_status( WP_REST_Request $req ) {
		$id     = (int) $req['id'];
		$status = sanitize_key( (string) $req->get_param( 'status' ) );
		$ok     = ReviewRepository::set_status( $id, $status );
		return new WP_REST_Response( [ 'ok' => $ok, 'counts' => ReviewRepository::status_counts() ], $ok ? 200 : 400 );
	}

	public function delete_review( WP_REST_Request $req ) {
		$ok = ReviewRepository::delete( (int) $req['id'] );
		return new WP_REST_Response( [ 'ok' => $ok, 'counts' => ReviewRepository::status_counts() ], $ok ? 200 : 400 );
	}

	public function bulk( WP_REST_Request $req ) {
		$ids    = array_map( 'intval', (array) $req->get_param( 'ids' ) );
		$action = sanitize_key( (string) $req->get_param( 'action' ) );
		$done   = 0;

		foreach ( $ids as $id ) {
			if ( 'delete' === $action ) {
				$done += ReviewRepository::delete( $id ) ? 1 : 0;
			} elseif ( in_array( $action, ReviewRepository::STATUSES, true ) ) {
				$done += ReviewRepository::set_status( $id, $action ) ? 1 : 0;
			}
		}

		return new WP_REST_Response( [ 'ok' => true, 'done' => $done, 'counts' => ReviewRepository::status_counts() ], 200 );
	}

	public function save_settings( WP_REST_Request $req ) {
		$in    = (array) $req->get_param( 'settings' );
		$clean = $this->sanitize_settings( $in );
		Settings::save( $clean );

		// Digest schedule follows the frequency setting.
		Digest::reschedule();

		return new WP_REST_Response( [ 'ok' => true, 'settings' => Settings::all() ], 200 );
	}

	/**
	 * Table maintenance: purge orphaned reviews and/or optimize the tables.
	 *
	 * @param WP_REST_Request $req
	 * @return WP_REST_Response
	 */
	public function maintenance( WP_REST_Request $req ) {
		$action  = sanitize_key( (string) $req->get_param( 'action' ) );
		$removed = 0;

		if ( 'purge' === $action || 'all' === $action ) {
			$removed = Maintenance::purge_orphans();
		}
		if ( 'optimize' === $action || 'all' === $action ) {
			Maintenance::optimize();
		}

		return new WP_REST_Response(
			[
				'ok'      => true,
				'removed' => $removed,
				'counts'  => ReviewRepository::status_counts(),
			],
			200
		);
	}

	/* ---------------- Migration ---------------- */

	public function migration_preview() {
		return new WP_REST_Response(
			[
				'import' => CommentImporter::preview(),
				'export' => CommentExporter::preview(),
			],
			200
		);
	}

	public function migration_import( WP_REST_Request $req ) {
		$result = CommentImporter::run(
			[
				'sources'         => array_map( 'sanitize_key', (array) $req->get_param( 'sources' ) ),
				'update_existing' => (bool) $req->get_param( 'update_existing' ),
				'delete_source'   => (bool) $req->get_param( 'delete_source' ),
				'limit'           => (int) ( $req->get_param( 'limit' ) ?: 100 ),
				'offset'          => (int) ( $req->get_param( 'offset' ) ?: 0 ),
			]
		);
		$result['counts'] = ReviewRepository::status_counts();
		return new WP_REST_Response( $result, 200 );
	}

	public function migration_export( WP_REST_Request $req ) {
		$result = CommentExporter::run(
			[
				'limit'  => (int) ( $req->get_param( 'limit' ) ?: 100 ),
				'offset' => (int) ( $req->get_param( 'offset' ) ?: 0 ),
			]
		);
		return new WP_REST_Response( $result, 200 );
	}

	public function export_csv() {
		return new WP_REST_Response(
			[
				'filename' => 'advery-reviews-' . gmdate( 'Ymd-His' ) . '.csv',
				'csv'      => CsvExporter::generate(),
			],
			200
		);
	}

	/**
	 * Generic data import (CSV/JSON parsed client-side into rows). Processed in
	 * batches the admin sends one at a time.
	 *
	 * @param WP_REST_Request $req
	 * @return WP_REST_Response
	 */
	public function import_data( WP_REST_Request $req ) {
		$rows    = (array) $req->get_param( 'rows' );
		$mapping = (array) $req->get_param( 'mapping' );
		$options = [ 'update_existing' => (bool) $req->get_param( 'update_existing' ) ];

		$result           = DataImporter::import( $rows, $mapping, $options );
		$result['counts'] = ReviewRepository::status_counts();

		return new WP_REST_Response( $result, 200 );
	}

	/* ---------------- AI ---------------- */

	/**
	 * Run an AI task. `test` verifies the provider; `reply`/`translate` operate
	 * on a review; `summarize` on an object's approved reviews.
	 *
	 * @param WP_REST_Request $req
	 * @return WP_REST_Response|WP_Error
	 */
	public function ai_task( WP_REST_Request $req ) {
		$task = sanitize_key( (string) $req['task'] );

		if ( 'test' === $task ) {
			$out = AIClient::run( 'reply', [ 'business' => get_bloginfo( 'name' ), 'rating' => 5, 'author' => 'Test', 'content' => 'Everything was great, thank you!' ] );
			if ( is_wp_error( $out ) ) {
				return new WP_REST_Response( [ 'ok' => false, 'message' => $out->get_error_message() ], 200 );
			}
			return new WP_REST_Response( [ 'ok' => true, 'sample' => $out ], 200 );
		}

		if ( 'summarize' === $task ) {
			$type  = sanitize_key( (string) $req->get_param( 'object_type' ) );
			$id    = (int) $req->get_param( 'object_id' );
			$parts = [];
			foreach ( ReviewRepository::approved_for( $type, $id, 30 ) as $r ) {
				$parts[] = '- (' . $r['rating'] . '/5) ' . $r['content'];
			}
			if ( empty( $parts ) ) {
				return new WP_Error( 'advery_ai_none', __( 'No reviews to summarize.', 'advery-reviews' ), [ 'status' => 400 ] );
			}
			$out = AIClient::run( 'summarize', [ 'content' => implode( "\n", $parts ) ] );
			return $this->ai_result( $out );
		}

		// reply / translate / moderate operate on a single review.
		$review = ReviewRepository::find( (int) $req->get_param( 'review_id' ) );
		if ( ! $review ) {
			return new WP_Error( 'advery_ai_review', __( 'Review not found.', 'advery-reviews' ), [ 'status' => 404 ] );
		}
		$ctx = [
			'business' => Targets::label( $review['object_type'], $review['object_id'] ),
			'rating'   => $review['rating'],
			'author'   => $review['author_name'],
			'content'  => $review['content'],
			'target'   => sanitize_text_field( (string) $req->get_param( 'target' ) ) ?: 'English',
		];

		$out = AIClient::run( $task, $ctx );
		if ( 'moderate' === $task && ! is_wp_error( $out ) ) {
			return new WP_REST_Response( [ 'ok' => true, 'verdict' => AIClient::moderation_verdict( $out ), 'raw' => $out ], 200 );
		}
		return $this->ai_result( $out );
	}

	private function ai_result( $out ) {
		if ( is_wp_error( $out ) ) {
			return new WP_Error( $out->get_error_code(), $out->get_error_message(), [ 'status' => 400 ] );
		}
		return new WP_REST_Response( [ 'ok' => true, 'text' => $out ], 200 );
	}

	/**
	 * Save (or clear) the owner reply on a review.
	 *
	 * @param WP_REST_Request $req
	 * @return WP_REST_Response
	 */
	public function save_reply( WP_REST_Request $req ) {
		$id   = (int) $req['id'];
		$text = \Advery\Reviews\Support\Sanitizer::content( (string) $req->get_param( 'text' ), 2000 );
		$by   = wp_get_current_user()->display_name;
		$ok   = ReviewRepository::set_reply( $id, $text, $by );
		return new WP_REST_Response( [ 'ok' => $ok ], $ok ? 200 : 400 );
	}

	/* ---------------- Helpers ---------------- */

	private function sanitize_settings( array $in ) {
		$d = Settings::defaults();

		$post_types = [];
		foreach ( (array) ( $in['enabled_post_types'] ?? [] ) as $pt ) {
			$post_types[] = sanitize_key( $pt );
		}
		$taxes = [];
		foreach ( (array) ( $in['enabled_taxonomies'] ?? [] ) as $tx ) {
			$taxes[] = sanitize_key( $tx );
		}

		return [
			'enabled_post_types' => array_values( array_filter( $post_types ) ),
			'enabled_taxonomies' => array_values( array_filter( $taxes ) ),
			'woo_enabled'        => ! empty( $in['woo_enabled'] ),
			'who_can_submit'     => in_array( ( $in['who_can_submit'] ?? '' ), [ 'anyone', 'logged_in' ], true ) ? $in['who_can_submit'] : $d['who_can_submit'],
			'moderation'         => in_array( ( $in['moderation'] ?? '' ), [ 'manual', 'auto' ], true ) ? $in['moderation'] : $d['moderation'],
			'one_per_user'       => ! empty( $in['one_per_user'] ),
			'rating_required'    => ! empty( $in['rating_required'] ),
			'min_content_length' => max( 0, (int) ( $in['min_content_length'] ?? 0 ) ),
			'auto_append'        => ! empty( $in['auto_append'] ),
			'reviews_per_page'   => max( 1, min( 50, (int) ( $in['reviews_per_page'] ?? $d['reviews_per_page'] ) ) ),
			'load_mode'          => in_array( ( $in['load_mode'] ?? '' ), [ 'all', 'load_more', 'paginate' ], true ) ? $in['load_mode'] : $d['load_mode'],
			'replace_comments'   => ! empty( $in['replace_comments'] ),
			// CSS never needs '<'; removing it prevents a </style> breakout.
			'custom_css'         => str_replace( '<', '', (string) ( $in['custom_css'] ?? '' ) ),
			'schema_output'      => ! empty( $in['schema_output'] ),
			'woo_merge_native'   => ! empty( $in['woo_merge_native'] ),
			'email_instant'      => ! empty( $in['email_instant'] ),
			'email_recipient'    => sanitize_email( (string) ( $in['email_recipient'] ?? '' ) ),
			'digest_frequency'   => in_array( ( $in['digest_frequency'] ?? '' ), [ 'off', 'weekly', 'monthly' ], true ) ? $in['digest_frequency'] : $d['digest_frequency'],
			'antispam'           => $this->sanitize_antispam( is_array( $in['antispam'] ?? null ) ? $in['antispam'] : [] ),
			'ai'                 => $this->sanitize_ai( is_array( $in['ai'] ?? null ) ? $in['ai'] : [] ),
		];
	}

	/**
	 * Whitelist + coerce the nested AI config.
	 *
	 * @param array $in
	 * @return array
	 */
	private function sanitize_ai( array $in ) {
		$d = Settings::ai_defaults();

		$tasks = [];
		foreach ( $d['tasks'] as $key => $def ) {
			$t             = is_array( $in['tasks'][ $key ] ?? null ) ? $in['tasks'][ $key ] : [];
			$tasks[ $key ] = [
				'enabled' => ! empty( $t['enabled'] ),
				'prompt'  => sanitize_textarea_field( (string) ( $t['prompt'] ?? '' ) ),
			];
		}

		return [
			'provider'            => in_array( ( $in['provider'] ?? '' ), [ 'anthropic', 'openai', 'openrouter', 'ollama', 'gemini' ], true ) ? $in['provider'] : $d['provider'],
			'api_key'             => trim( sanitize_text_field( (string) ( $in['api_key'] ?? '' ) ) ),
			'base_url'            => esc_url_raw( (string) ( $in['base_url'] ?? '' ) ),
			'model'               => sanitize_text_field( (string) ( $in['model'] ?? '' ) ),
			'temperature'         => min( 2.0, max( 0.0, (float) ( $in['temperature'] ?? $d['temperature'] ) ) ),
			'max_tokens'          => min( 4000, max( 32, (int) ( $in['max_tokens'] ?? $d['max_tokens'] ) ) ),
			'daily_cap'           => max( 0, (int) ( $in['daily_cap'] ?? $d['daily_cap'] ) ),
			'moderation_autospam' => ! empty( $in['moderation_autospam'] ),
			'tasks'               => $tasks,
		];
	}

	/**
	 * Whitelist + coerce the nested anti-spam config.
	 *
	 * @param array $in
	 * @return array
	 */
	private function sanitize_antispam( array $in ) {
		$d = Settings::antispam_defaults();

		return [
			'timing_enabled'      => ! empty( $in['timing_enabled'] ),
			'timing_min'          => max( 0, (int) ( $in['timing_min'] ?? $d['timing_min'] ) ),
			'max_links'           => max( 0, (int) ( $in['max_links'] ?? $d['max_links'] ) ),
			'link_action'         => in_array( ( $in['link_action'] ?? '' ), [ 'off', 'hold', 'spam', 'reject' ], true ) ? $in['link_action'] : $d['link_action'],
			'blocklist_words'     => sanitize_textarea_field( (string) ( $in['blocklist_words'] ?? '' ) ),
			'blocklist_emails'    => sanitize_textarea_field( (string) ( $in['blocklist_emails'] ?? '' ) ),
			'block_disposable'    => ! empty( $in['block_disposable'] ),
			'rate_enabled'        => ! empty( $in['rate_enabled'] ),
			'rate_window'         => max( 1, (int) ( $in['rate_window'] ?? $d['rate_window'] ) ),
			'rate_max'            => max( 1, (int) ( $in['rate_max'] ?? $d['rate_max'] ) ),
			'rate_day_max'        => max( 0, (int) ( $in['rate_day_max'] ?? $d['rate_day_max'] ) ),
			'duplicate_check'     => ! empty( $in['duplicate_check'] ),
			'min_chars'           => max( 0, (int) ( $in['min_chars'] ?? $d['min_chars'] ) ),
			'max_chars'           => max( 0, (int) ( $in['max_chars'] ?? $d['max_chars'] ) ),
			'max_name_chars'      => max( 1, (int) ( $in['max_name_chars'] ?? $d['max_name_chars'] ) ),
			'trusted_autoapprove' => ! empty( $in['trusted_autoapprove'] ),
			'hold_threshold'      => max( 1, (int) ( $in['hold_threshold'] ?? $d['hold_threshold'] ) ),
			'spam_threshold'      => max( 1, (int) ( $in['spam_threshold'] ?? $d['spam_threshold'] ) ),
			'captcha_provider'    => in_array( ( $in['captcha_provider'] ?? '' ), [ 'none', 'recaptcha_v2', 'recaptcha_v3', 'hcaptcha', 'turnstile' ], true ) ? $in['captcha_provider'] : 'none',
			'captcha_site_key'    => sanitize_text_field( (string) ( $in['captcha_site_key'] ?? '' ) ),
			'captcha_secret_key'  => sanitize_text_field( (string) ( $in['captcha_secret_key'] ?? '' ) ),
			'captcha_threshold'   => min( 1.0, max( 0.0, (float) ( $in['captcha_threshold'] ?? $d['captcha_threshold'] ) ) ),
			'akismet_enabled'     => ! empty( $in['akismet_enabled'] ),
		];
	}

	private function admin_shape( array $r ) {
		$r['label'] = Targets::label( $r['object_type'], $r['object_id'] );
		$r['link']  = Targets::link( $r['object_type'], $r['object_id'] );
		return $r;
	}

	private function public_shape( array $r ) {
		return [
			'id'          => $r['id'],
			'rating'      => $r['rating'],
			'author_name' => $r['author_name'],
			'title'       => $r['title'],
			'content'     => wp_kses_post( $r['content'] ),
			'created_at'  => $r['created_at'],
		];
	}

	private function post_types() {
		$out = [];
		foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $pt ) {
			if ( 'attachment' === $pt->name ) {
				continue;
			}
			$out[] = [ 'slug' => $pt->name, 'label' => $pt->labels->singular_name ?? $pt->label ];
		}
		return $out;
	}

	private function taxonomies() {
		$out = [];
		foreach ( get_taxonomies( [ 'public' => true ], 'objects' ) as $tx ) {
			$out[] = [ 'slug' => $tx->name, 'label' => $tx->labels->singular_name ?? $tx->label ];
		}
		return $out;
	}

	private function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		return sanitize_text_field( $ip );
	}
}
