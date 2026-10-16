<?php
/**
 * [smart_faq] shortcode.
 *
 * @package SmartFAQFeedback
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the accordion and enqueues assets only when the shortcode is used.
 */
class SFF_Shortcode {

	/**
	 * Number of lists rendered on this request (for unique element IDs).
	 *
	 * @var int
	 */
	private static $instances = 0;

	/**
	 * Recursion guard (an FAQ answer containing [smart_faq]).
	 *
	 * @var bool
	 */
	private static $rendering = false;

	/**
	 * Hooks.
	 */
	public function init() {
		add_shortcode( 'smart_faq', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Register (not enqueue) assets.
	 */
	public function register_assets() {
		wp_register_style( 'sff-frontend', SFF_PLUGIN_URL . 'assets/css/frontend.css', array(), SFF_VERSION );
		wp_register_script( 'sff-frontend', SFF_PLUGIN_URL . 'assets/js/frontend.js', array(), SFF_VERSION, true );
	}

	/**
	 * Enqueue assets and pass data to the script (once per request).
	 */
	private function enqueue_assets() {
		if ( ! wp_style_is( 'sff-frontend', 'registered' ) ) {
			$this->register_assets();
		}
		wp_enqueue_style( 'sff-frontend' );
		wp_enqueue_script( 'sff-frontend' );

		static $localized = false;
		if ( $localized ) {
			return;
		}
		$localized = true;

		wp_localize_script(
			'sff-frontend',
			'sffData',
			array(
				'voteUrl'  => esc_url_raw( rest_url( SFF_Feedback::REST_NAMESPACE . '/vote' ) ),
				'stateUrl' => esc_url_raw( rest_url( SFF_Feedback::REST_NAMESPACE . '/state' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'i18n'     => array(
					/* translators: %d: number of likes */
					'likeLabel'    => __( 'Like this answer. Current likes: %d', 'smart-faq-feedback' ),
					/* translators: %d: number of dislikes */
					'dislikeLabel' => __( 'Dislike this answer. Current dislikes: %d', 'smart-faq-feedback' ),
					'saving'       => __( 'Saving…', 'smart-faq-feedback' ),
					'liked'        => __( 'Thanks, you marked this answer as helpful.', 'smart-faq-feedback' ),
					'disliked'     => __( 'Thanks, you marked this answer as not helpful.', 'smart-faq-feedback' ),
					'removed'      => __( 'Your reaction was removed.', 'smart-faq-feedback' ),
					'error'        => __( 'Sorry, your feedback could not be saved. Please try again.', 'smart-faq-feedback' ),
					'network'      => __( 'Network problem. Please check your connection and try again.', 'smart-faq-feedback' ),
				),
			)
		);
	}

	/**
	 * Validate shortcode attributes.
	 *
	 * @param array $atts Raw attributes.
	 * @return array Clean attributes.
	 */
	private function parse_atts( $atts ) {
		$atts = shortcode_atts(
			array(
				'category'      => '',
				'orderby'       => 'menu_order',
				'order'         => 'ASC',
				'limit'         => '',
				'open'          => 'none',
				'feedback'      => 'yes',
				'heading_level' => '3',
			),
			is_array( $atts ) ? $atts : array(),
			'smart_faq'
		);

		$clean = array();

		$slugs             = array_filter( array_map( 'sanitize_title', explode( ',', (string) $atts['category'] ) ) );
		$clean['category'] = array_values( array_unique( $slugs ) );

		$orderby          = strtolower( sanitize_key( $atts['orderby'] ) );
		$clean['orderby'] = in_array( $orderby, array( 'menu_order', 'date', 'title', 'modified', 'rand' ), true ) ? $orderby : 'menu_order';

		$order          = strtoupper( sanitize_key( $atts['order'] ) );
		$clean['order'] = ( 'DESC' === $order ) ? 'DESC' : 'ASC';

		/**
		 * Maximum number of FAQs one shortcode may output.
		 */
		$max            = max( 1, (int) apply_filters( 'sff_max_faqs', 500 ) );
		$limit          = absint( $atts['limit'] );
		$clean['limit'] = ( $limit > 0 ) ? min( $limit, $max ) : $max;

		$open          = strtolower( sanitize_key( $atts['open'] ) );
		$clean['open'] = in_array( $open, array( 'none', 'first', 'all' ), true ) ? $open : 'none';

		$feedback          = strtolower( trim( (string) $atts['feedback'] ) );
		$clean['feedback'] = ! in_array( $feedback, array( 'no', 'false', '0', 'off', 'hide' ), true );

		$level                  = absint( $atts['heading_level'] );
		$clean['heading_level'] = ( $level >= 2 && $level <= 6 ) ? $level : 3;

		return $clean;
	}

	/**
	 * Render the shortcode.
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function render( $atts = array() ) {
		if ( self::$rendering ) {
			return ''; // An FAQ answer must not embed the FAQ list again.
		}

		$a        = $this->parse_atts( $atts );
		$settings = SFF_Plugin::get_settings();

		$args = array(
			'post_type'              => SFF_FAQ_Post_Type::POST_TYPE,
			'post_status'            => 'publish',
			'has_password'           => false,
			'posts_per_page'         => $a['limit'],
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'suppress_filters'       => false,
			'update_post_meta_cache' => false,
		);

		if ( 'menu_order' === $a['orderby'] ) {
			$args['orderby'] = array(
				'menu_order' => $a['order'],
				'date'       => 'DESC',
			);
		} else {
			$args['orderby'] = $a['orderby'];
			$args['order']   = $a['order'];
		}

		if ( ! empty( $a['category'] ) ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => SFF_FAQ_Post_Type::TAXONOMY,
					'field'    => 'slug',
					'terms'    => $a['category'],
					'operator' => 'IN',
				),
			);
		}

		$query = new WP_Query( $args );

		if ( ! $query->have_posts() ) {
			if ( ! empty( $settings['show_empty_message'] ) && '' !== trim( (string) $settings['empty_message'] ) ) {
				$this->enqueue_assets();
				return '<div class="sff-empty">' . esc_html( $settings['empty_message'] ) . '</div>';
			}
			return '';
		}

		$this->enqueue_assets();
		++self::$instances;
		$uid = self::$instances;

		$ids      = wp_list_pluck( $query->posts, 'ID' );
		$counts   = SFF_Database::get_counts( $ids );
		$voter    = $a['feedback'] ? SFF_Feedback::voter_hash( false ) : '';
		$mine     = $voter ? SFF_Database::get_reactions( $ids, $voter ) : array();
		$use_role = count( $ids ) <= 6;
		$tag      = 'h' . (int) $a['heading_level'];
		$helpful  = ! empty( $settings['show_helpful'] ) ? (string) $settings['helpful_text'] : '';
		$i        = 0;

		self::$rendering = true;
		ob_start();
		?>
		<div class="sff-faq-list" id="sff-faq-<?php echo esc_attr( $uid ); ?>" data-sff-list>
		<?php
		while ( $query->have_posts() ) {
			$query->the_post();
			$post    = get_post();
			$id      = (int) $post->ID;
			$is_open = ( 'all' === $a['open'] ) || ( 'first' === $a['open'] && 0 === $i );
			++$i;

			$likes    = isset( $counts[ $id ] ) ? $counts[ $id ]['likes'] : 0;
			$dislikes = isset( $counts[ $id ] ) ? $counts[ $id ]['dislikes'] : 0;
			$reaction = isset( $mine[ $id ] ) ? (int) $mine[ $id ] : 0;

			$q_id = 'sff-q-' . $uid . '-' . $id;
			$a_id = 'sff-a-' . $uid . '-' . $id;
			?>
			<div class="sff-item<?php echo $is_open ? ' is-open' : ''; ?>" data-faq-id="<?php echo esc_attr( $id ); ?>">
				<<?php echo esc_html( $tag ); ?> class="sff-question">
					<button type="button" class="sff-trigger" id="<?php echo esc_attr( $q_id ); ?>" aria-expanded="<?php echo $is_open ? 'true' : 'false'; ?>" aria-controls="<?php echo esc_attr( $a_id ); ?>">
						<span class="sff-q-text"><?php echo esc_html( get_the_title() ); ?></span>
						<span class="sff-chevron" aria-hidden="true"></span>
					</button>
				</<?php echo esc_html( $tag ); ?>>
				<div class="sff-panel" id="<?php echo esc_attr( $a_id ); ?>"<?php echo $use_role ? ' role="region"' : ''; ?> aria-labelledby="<?php echo esc_attr( $q_id ); ?>">
					<div class="sff-panel-inner">
						<div class="sff-answer"><?php echo $this->answer_html( $post ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitized with wp_kses_post() inside answer_html(). ?></div>
						<?php if ( $a['feedback'] ) : ?>
							<div class="sff-feedback" role="group" aria-label="<?php esc_attr_e( 'Answer feedback', 'smart-faq-feedback' ); ?>">
								<?php if ( '' !== $helpful ) : ?>
									<span class="sff-helpful"><?php echo esc_html( $helpful ); ?></span>
								<?php endif; ?>
								<button type="button" class="sff-vote sff-like<?php echo 1 === $reaction ? ' is-selected' : ''; ?>" data-reaction="like" aria-pressed="<?php echo 1 === $reaction ? 'true' : 'false'; ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: likes */ __( 'Like this answer. Current likes: %d', 'smart-faq-feedback' ), $likes ) ); ?>">
									<svg class="sff-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"/></svg>
									<span class="sff-count" data-count="like"><?php echo esc_html( number_format_i18n( $likes ) ); ?></span>
								</button>
								<button type="button" class="sff-vote sff-dislike<?php echo -1 === $reaction ? ' is-selected' : ''; ?>" data-reaction="dislike" aria-pressed="<?php echo -1 === $reaction ? 'true' : 'false'; ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: dislikes */ __( 'Dislike this answer. Current dislikes: %d', 'smart-faq-feedback' ), $dislikes ) ); ?>">
									<svg class="sff-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path d="M10 15v4a3 3 0 0 0 3 3l4-9V2H5.72a2 2 0 0 0-2 1.7l-1.38 9a2 2 0 0 0 2 2.3zm7-13h2.67A2.31 2.31 0 0 1 22 4v7a2.31 2.31 0 0 1-2.33 2H17"/></svg>
									<span class="sff-count" data-count="dislike"><?php echo esc_html( number_format_i18n( $dislikes ) ); ?></span>
								</button>
								<span class="sff-status" role="status" aria-live="polite"></span>
							</div>
						<?php endif; ?>
					</div>
				</div>
			</div>
			<?php
		}
		?>
		</div>
		<?php
		$html = ob_get_clean();
		self::$rendering = false;
		wp_reset_postdata();

		return $html;
	}

	/**
	 * Format an answer like the_content, without running third-party the_content filters.
	 *
	 * @param WP_Post $post FAQ post.
	 * @return string Safe HTML.
	 */
	private function answer_html( $post ) {
		$content = (string) $post->post_content;
		$content = do_blocks( $content );
		$content = wptexturize( $content );
		$content = wpautop( $content );
		$content = shortcode_unautop( $content );
		$content = do_shortcode( $content );
		if ( function_exists( 'wp_filter_content_tags' ) ) {
			$content = wp_filter_content_tags( $content );
		}
		return wp_kses_post( $content );
	}
}
