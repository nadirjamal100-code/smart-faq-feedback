<?php
/**
 * FAQ custom post type and category taxonomy.
 *
 * @package SmartFAQFeedback
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `sff_faq` post type and `sff_faq_category` taxonomy.
 */
class SFF_FAQ_Post_Type {

	const POST_TYPE = 'sff_faq';
	const TAXONOMY  = 'sff_faq_category';

	/**
	 * Hook registration.
	 */
	public function init() {
		add_action( 'init', array( $this, 'register' ) );
		add_filter( 'enter_title_here', array( $this, 'title_placeholder' ), 10, 2 );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( $this, 'add_meta_boxes' ) );
		add_action( 'before_delete_post', array( $this, 'cleanup_feedback' ) );
	}

	/**
	 * Register post type and taxonomy.
	 */
	public function register() {
		register_taxonomy(
			self::TAXONOMY,
			self::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'FAQ Categories', 'smart-faq-feedback' ),
					'singular_name' => __( 'FAQ Category', 'smart-faq-feedback' ),
					'search_items'  => __( 'Search FAQ Categories', 'smart-faq-feedback' ),
					'all_items'     => __( 'All FAQ Categories', 'smart-faq-feedback' ),
					'edit_item'     => __( 'Edit FAQ Category', 'smart-faq-feedback' ),
					'update_item'   => __( 'Update FAQ Category', 'smart-faq-feedback' ),
					'add_new_item'  => __( 'Add New FAQ Category', 'smart-faq-feedback' ),
					'new_item_name' => __( 'New FAQ Category Name', 'smart-faq-feedback' ),
					'menu_name'     => __( 'Categories', 'smart-faq-feedback' ),
				),
				'hierarchical'      => true,
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'show_in_nav_menus' => false,
				'show_tagcloud'     => false,
				'query_var'         => self::TAXONOMY,
				'rewrite'           => false,
			)
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'               => __( 'Smart FAQ', 'smart-faq-feedback' ),
					'singular_name'      => __( 'FAQ', 'smart-faq-feedback' ),
					'menu_name'          => __( 'Smart FAQ', 'smart-faq-feedback' ),
					'all_items'          => __( 'All FAQs', 'smart-faq-feedback' ),
					'add_new'            => __( 'Add New', 'smart-faq-feedback' ),
					'add_new_item'       => __( 'Add New FAQ', 'smart-faq-feedback' ),
					'edit_item'          => __( 'Edit FAQ', 'smart-faq-feedback' ),
					'new_item'           => __( 'New FAQ', 'smart-faq-feedback' ),
					'view_item'          => __( 'View FAQ', 'smart-faq-feedback' ),
					'search_items'       => __( 'Search FAQs', 'smart-faq-feedback' ),
					'not_found'          => __( 'No FAQs found.', 'smart-faq-feedback' ),
					'not_found_in_trash' => __( 'No FAQs found in Trash.', 'smart-faq-feedback' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => true,
				'show_in_rest'        => true,
				'rest_base'           => 'sff-faqs',
				'menu_position'       => 25,
				'menu_icon'           => 'dashicons-editor-help',
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'hierarchical'        => false,
				'supports'            => array( 'title', 'editor', 'page-attributes', 'revisions' ),
				'taxonomies'          => array( self::TAXONOMY ),
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
			)
		);
	}

	/**
	 * Dedicated question field placeholder.
	 *
	 * @param string  $text Default text.
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public function title_placeholder( $text, $post ) {
		if ( $post && self::POST_TYPE === $post->post_type ) {
			return __( 'Enter the FAQ question', 'smart-faq-feedback' );
		}
		return $text;
	}

	/**
	 * Add the read-only feedback box.
	 */
	public function add_meta_boxes() {
		add_meta_box(
			'sff_feedback_box',
			__( 'Visitor feedback', 'smart-faq-feedback' ),
			array( $this, 'render_feedback_box' ),
			self::POST_TYPE,
			'side',
			'default',
			array( '__back_compat_meta_box' => true )
		);
	}

	/**
	 * Render the feedback box.
	 *
	 * @param WP_Post $post Post.
	 */
	public function render_feedback_box( $post ) {
		$counts = SFF_Database::get_counts( array( $post->ID ) );
		$row    = isset( $counts[ $post->ID ] ) ? $counts[ $post->ID ] : array(
			'likes'    => 0,
			'dislikes' => 0,
		);
		?>
		<p>
			<?php
			printf(
				/* translators: %s: number of likes */
				esc_html__( 'Likes: %s', 'smart-faq-feedback' ),
				'<strong>' . esc_html( number_format_i18n( $row['likes'] ) ) . '</strong>'
			);
			?>
			<br>
			<?php
			printf(
				/* translators: %s: number of dislikes */
				esc_html__( 'Dislikes: %s', 'smart-faq-feedback' ),
				'<strong>' . esc_html( number_format_i18n( $row['dislikes'] ) ) . '</strong>'
			);
			?>
		</p>
		<p class="description"><?php esc_html_e( 'Counts are recorded from visitors and cannot be edited here.', 'smart-faq-feedback' ); ?></p>
		<?php
	}

	/**
	 * Remove feedback rows when an FAQ is permanently deleted.
	 *
	 * @param int $post_id Post ID.
	 */
	public function cleanup_feedback( $post_id ) {
		if ( self::POST_TYPE === get_post_type( $post_id ) ) {
			SFF_Database::delete_for_faq( $post_id );
		}
	}
}
