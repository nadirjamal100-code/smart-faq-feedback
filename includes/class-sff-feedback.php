<?php
/**
 * Like / dislike REST API, voter identification and rate limiting.
 *
 * @package SmartFAQFeedback
 */

defined( 'ABSPATH' ) || exit;

/**
 * Server-side feedback handling. The browser only ever sends "which FAQ" and
 * "like or dislike"; counts are always computed from database rows.
 */
class SFF_Feedback {

	const REST_NAMESPACE = 'smart-faq-feedback/v1';
	const COOKIE_NAME    = 'sff_voter';

	/**
	 * Per-request cache of voter hashes.
	 *
	 * @var array
	 */
	private static $hash_cache = array();

	/**
	 * Hooks.
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes. Both are intentionally public (anonymous voting is a
	 * requirement); integrity comes from server-side validation, the unique
	 * (faq_id, voter) key and rate limiting rather than from authentication.
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/vote',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_vote' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'faq_id'   => array(
						'required'          => true,
						'type'              => 'integer',
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
					'reaction' => array(
						'required'          => true,
						'type'              => 'string',
						'enum'              => array( 'like', 'dislike' ),
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/state',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_state' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'ids' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Is this a published, unprotected FAQ that visitors may react to?
	 *
	 * @param int $faq_id FAQ ID.
	 * @return bool
	 */
	public static function is_votable( $faq_id ) {
		$post = get_post( absint( $faq_id ) );
		return $post
			&& SFF_FAQ_Post_Type::POST_TYPE === $post->post_type
			&& 'publish' === $post->post_status
			&& '' === $post->post_password;
	}

	/**
	 * Anonymous, salted voter identifier.
	 *
	 * Logged-in users are identified by user ID (so their vote follows them
	 * across devices); everyone else by a random first-party cookie. Only an
	 * HMAC of the identifier is stored, never the raw value or an IP address.
	 *
	 * @param bool $create Create (and set a cookie for) a new anonymous ID if none exists.
	 * @return string 64-char hash, or '' when no identity exists and $create is false.
	 */
	public static function voter_hash( $create = false ) {
		if ( is_user_logged_in() ) {
			$raw = 'u:' . get_current_user_id();
		} else {
			$raw = '';
			if ( isset( $_COOKIE[ self::COOKIE_NAME ] ) ) {
				$cookie = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
				if ( preg_match( '/^[a-f0-9]{32}$/', $cookie ) ) {
					$raw = 'c:' . $cookie;
				}
			}
			if ( '' === $raw && $create ) {
				$token = bin2hex( random_bytes( 16 ) );
				self::set_cookie( $token );
				$raw = 'c:' . $token;
			}
		}

		if ( '' === $raw ) {
			return '';
		}
		if ( ! isset( self::$hash_cache[ $raw ] ) ) {
			self::$hash_cache[ $raw ] = hash_hmac( 'sha256', $raw, wp_salt( 'auth' ) );
		}
		return self::$hash_cache[ $raw ];
	}

	/**
	 * Set the anonymous voter cookie (first-party, HttpOnly, SameSite=Lax).
	 *
	 * @param string $token Random token.
	 */
	private static function set_cookie( $token ) {
		$_COOKIE[ self::COOKIE_NAME ] = $token; // Make it visible for the rest of this request.

		if ( headers_sent() ) {
			return;
		}
		setcookie(
			self::COOKIE_NAME,
			$token,
			array(
				'expires'  => time() + YEAR_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? (string) COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Sliding fixed-window rate limit using transients.
	 *
	 * @param string $key    Bucket key.
	 * @param int    $limit  Max hits per window.
	 * @param int    $window Window length in seconds.
	 * @return bool True if the hit is allowed.
	 */
	private static function rate_hit( $key, $limit, $window ) {
		$now  = time();
		$data = get_transient( $key );
		if ( ! is_array( $data ) || ! isset( $data['c'], $data['s'] ) || ( $now - (int) $data['s'] ) >= $window ) {
			$data = array(
				'c' => 0,
				's' => $now,
			);
		}
		if ( (int) $data['c'] >= $limit ) {
			return false;
		}
		++$data['c'];
		set_transient( $key, $data, max( 1, $window - ( $now - (int) $data['s'] ) ) );
		return true;
	}

	/**
	 * Visitor IP (REMOTE_ADDR only, filterable for reverse-proxy setups). Used
	 * solely, in hashed form, as a short-lived rate-limit key; never stored in the database.
	 *
	 * @return string
	 */
	private static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip = apply_filters( 'sff_client_ip', $ip );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Check both the per-voter and per-IP rate limits.
	 *
	 * @param string $voter Voter hash.
	 * @return bool True if allowed.
	 */
	private static function within_rate_limit( $voter ) {
		/**
		 * Filter rate limits: array( votes, seconds ) for each bucket.
		 */
		$voter_limit = apply_filters( 'sff_rate_limit_voter', array( 20, MINUTE_IN_SECONDS ) );
		$ip_limit    = apply_filters( 'sff_rate_limit_ip', array( 100, 10 * MINUTE_IN_SECONDS ) );

		if ( ! self::rate_hit( 'sff_rlv_' . substr( $voter, 0, 32 ), (int) $voter_limit[0], (int) $voter_limit[1] ) ) {
			return false;
		}
		$ip = self::client_ip();
		if ( '' !== $ip && ! self::rate_hit( 'sff_rli_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ), 0, 32 ), (int) $ip_limit[0], (int) $ip_limit[1] ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Human reaction name.
	 *
	 * @param int $value 1, -1, 0.
	 * @return string
	 */
	private static function reaction_name( $value ) {
		if ( 1 === $value ) {
			return 'like';
		}
		if ( -1 === $value ) {
			return 'dislike';
		}
		return 'none';
	}

	/**
	 * Build a response with no-cache headers.
	 *
	 * @param array $data   Payload.
	 * @param int   $status HTTP status.
	 * @return WP_REST_Response
	 */
	private static function response( $data, $status = 200 ) {
		$response = new WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store, private, max-age=0' );
		return $response;
	}

	/**
	 * POST /vote
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_vote( WP_REST_Request $request ) {
		$faq_id   = absint( $request->get_param( 'faq_id' ) );
		$reaction = sanitize_key( (string) $request->get_param( 'reaction' ) );

		if ( ! $faq_id || ! in_array( $reaction, array( 'like', 'dislike' ), true ) ) {
			return new WP_Error( 'sff_invalid_request', __( 'Invalid request.', 'smart-faq-feedback' ), array( 'status' => 400 ) );
		}
		if ( ! self::is_votable( $faq_id ) ) {
			return new WP_Error( 'sff_not_found', __( 'This FAQ is not available for feedback.', 'smart-faq-feedback' ), array( 'status' => 404 ) );
		}

		$voter = self::voter_hash( true );
		if ( '' === $voter ) {
			return new WP_Error( 'sff_no_voter', __( 'Could not identify the visitor.', 'smart-faq-feedback' ), array( 'status' => 500 ) );
		}
		if ( ! self::within_rate_limit( $voter ) ) {
			return new WP_Error( 'sff_rate_limited', __( 'You are voting too quickly. Please wait a moment and try again.', 'smart-faq-feedback' ), array( 'status' => 429 ) );
		}

		$requested = ( 'like' === $reaction ) ? 1 : -1;
		$current   = SFF_Database::get_reaction( $faq_id, $voter );

		if ( $current === $requested ) {
			// Clicking the selected reaction again removes it.
			$ok  = SFF_Database::remove_reaction( $faq_id, $voter );
			$new = 0;
		} else {
			$ok  = SFF_Database::save_reaction( $faq_id, $voter, $requested );
			$new = $requested;
		}

		if ( ! $ok ) {
			return new WP_Error( 'sff_db_error', __( 'Your feedback could not be saved. Please try again.', 'smart-faq-feedback' ), array( 'status' => 500 ) );
		}

		$counts = SFF_Database::get_counts( array( $faq_id ) );

		return self::response(
			array(
				'success'  => true,
				'faq_id'   => $faq_id,
				'reaction' => self::reaction_name( $new ),
				'likes'    => $counts[ $faq_id ]['likes'],
				'dislikes' => $counts[ $faq_id ]['dislikes'],
			)
		);
	}

	/**
	 * GET /state — fresh counts and the caller's reactions (keeps cached pages accurate).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_state( WP_REST_Request $request ) {
		$ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', explode( ',', (string) $request->get_param( 'ids' ) ) ) ) ) ), 0, 100 );

		$items = array();
		if ( $ids ) {
			$published = get_posts(
				array(
					'post_type'              => SFF_FAQ_Post_Type::POST_TYPE,
					'post_status'            => 'publish',
					'post__in'               => $ids,
					'has_password'           => false,
					'posts_per_page'         => 100,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			$counts    = SFF_Database::get_counts( $published );
			$voter     = self::voter_hash( false );
			$mine      = $voter ? SFF_Database::get_reactions( $published, $voter ) : array();

			foreach ( $published as $id ) {
				$id           = (int) $id;
				$items[ $id ] = array(
					'likes'    => $counts[ $id ]['likes'],
					'dislikes' => $counts[ $id ]['dislikes'],
					'reaction' => self::reaction_name( isset( $mine[ $id ] ) ? $mine[ $id ] : 0 ),
				);
			}
		}

		return self::response( array( 'items' => (object) $items ) );
	}
}
