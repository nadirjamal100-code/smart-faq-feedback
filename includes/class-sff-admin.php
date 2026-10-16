<?php
/**
 * Admin UI: list-table columns, drag-and-drop ordering, category filter, settings page.
 *
 * @package SmartFAQFeedback
 */

defined( 'ABSPATH' ) || exit;

/**
 * Administration screens.
 */
class SFF_Admin {

	const SETTINGS_PAGE = 'sff-settings';
	const NONCE_REORDER = 'sff_reorder';

	/**
	 * Cached counts for the current list table page.
	 *
	 * @var array|null
	 */
	private $list_counts = null;

	/**
	 * Hooks.
	 */
	public function init() {
		$pt = SFF_FAQ_Post_Type::POST_TYPE;

		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_filter( "manage_{$pt}_posts_columns", array( $this, 'columns' ) );
		add_action( "manage_{$pt}_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
		add_filter( "manage_edit-{$pt}_sortable_columns", array( $this, 'sortable_columns' ) );
		add_action( 'pre_get_posts', array( $this, 'default_order' ) );
		add_action( 'restrict_manage_posts', array( $this, 'category_filter' ), 10, 2 );

		add_action( 'wp_ajax_sff_reorder', array( $this, 'ajax_reorder' ) );
	}

	/* ------------------------------------------------------------------
	 * List table
	 * ---------------------------------------------------------------- */

	/**
	 * Is the current admin screen the FAQ list table?
	 *
	 * @return bool
	 */
	private function is_list_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && 'edit' === $screen->base && SFF_FAQ_Post_Type::POST_TYPE === $screen->post_type;
	}

	/**
	 * Columns.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			if ( 'cb' === $key ) {
				$new[ $key ]        = $label;
				$new['sff_handle']  = '<span class="screen-reader-text">' . esc_html__( 'Reorder', 'smart-faq-feedback' ) . '</span>';
				continue;
			}
			if ( 'date' === $key ) {
				$new['sff_likes']    = __( 'Likes', 'smart-faq-feedback' );
				$new['sff_dislikes'] = __( 'Dislikes', 'smart-faq-feedback' );
				$new['sff_order']    = __( 'Order', 'smart-faq-feedback' );
			}
			$new[ $key ] = $label;
		}
		return $new;
	}

	/**
	 * Sortable columns.
	 *
	 * @param array $columns Sortable columns.
	 * @return array
	 */
	public function sortable_columns( $columns ) {
		$columns['sff_order'] = 'menu_order';
		return $columns;
	}

	/**
	 * Render a custom column.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public function render_column( $column, $post_id ) {
		switch ( $column ) {
			case 'sff_handle':
				echo '<span class="sff-drag-handle dashicons dashicons-menu" aria-hidden="true"></span>';
				break;

			case 'sff_likes':
			case 'sff_dislikes':
				if ( null === $this->list_counts ) {
					global $wp_query;
					$ids               = ( $wp_query && ! empty( $wp_query->posts ) ) ? wp_list_pluck( $wp_query->posts, 'ID' ) : array();
					$this->list_counts = SFF_Database::get_counts( $ids );
				}
				$key   = ( 'sff_likes' === $column ) ? 'likes' : 'dislikes';
				$value = isset( $this->list_counts[ $post_id ] ) ? $this->list_counts[ $post_id ][ $key ] : 0;
				echo esc_html( number_format_i18n( $value ) );
				break;

			case 'sff_order':
				echo esc_html( (string) (int) get_post_field( 'menu_order', $post_id ) );
				break;
		}
	}

	/**
	 * Default the admin list to custom order.
	 *
	 * @param WP_Query $query Query.
	 */
	public function default_order( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( SFF_FAQ_Post_Type::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}
		if ( ! $query->get( 'orderby' ) ) {
			$query->set( 'orderby', 'menu_order title' );
			$query->set( 'order', 'ASC' );
		}
	}

	/**
	 * Category dropdown above the list table.
	 *
	 * @param string $post_type Post type.
	 * @param string $which     Position.
	 */
	public function category_filter( $post_type, $which = 'top' ) {
		if ( SFF_FAQ_Post_Type::POST_TYPE !== $post_type ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter display.
		$selected = isset( $_GET[ SFF_FAQ_Post_Type::TAXONOMY ] ) ? sanitize_title( wp_unslash( $_GET[ SFF_FAQ_Post_Type::TAXONOMY ] ) ) : '';
		wp_dropdown_categories(
			array(
				'show_option_all' => __( 'All categories', 'smart-faq-feedback' ),
				'taxonomy'        => SFF_FAQ_Post_Type::TAXONOMY,
				'name'            => SFF_FAQ_Post_Type::TAXONOMY,
				'value_field'     => 'slug',
				'selected'        => $selected,
				'hierarchical'    => true,
				'hide_empty'      => false,
				'show_count'      => true,
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Assets
	 * ---------------------------------------------------------------- */

	/**
	 * Enqueue admin CSS/JS only on our screens.
	 *
	 * @param string $hook Admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		$is_settings = ( SFF_FAQ_Post_Type::POST_TYPE . '_page_' . self::SETTINGS_PAGE ) === $hook;
		$is_list     = $this->is_list_screen();

		if ( ! $is_settings && ! $is_list ) {
			return;
		}

		wp_enqueue_style( 'sff-admin', SFF_PLUGIN_URL . 'assets/css/admin.css', array(), SFF_VERSION );

		if ( ! $is_list ) {
			return;
		}

		wp_enqueue_script( 'sff-admin', SFF_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery', 'jquery-ui-sortable' ), SFF_VERSION, true );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only display state.
		$orderby    = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
		$order      = isset( $_GET['order'] ) ? strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) : 'asc';
		$status     = isset( $_GET['post_status'] ) ? sanitize_key( wp_unslash( $_GET['post_status'] ) ) : '';
		$has_search = ! empty( $_GET['s'] ) || ! empty( $_GET[ SFF_FAQ_Post_Type::TAXONOMY ] );
		$paged      = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable

		$per_page = (int) get_user_option( 'edit_' . SFF_FAQ_Post_Type::POST_TYPE . '_per_page' );
		if ( $per_page < 1 ) {
			$per_page = 20;
		}

		$sortable = ( '' === $orderby || 'menu_order' === $orderby )
			&& 'desc' !== $order
			&& 'trash' !== $status
			&& ! $has_search;

		wp_localize_script(
			'sff-admin',
			'sffAdmin',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( self::NONCE_REORDER ),
				'sortable' => $sortable,
				'isTrash'  => 'trash' === $status,
				'offset'   => ( $paged - 1 ) * $per_page,
				'i18n'     => array(
					'confirmTrash'  => __( 'Move this FAQ to the Trash? You can restore it later from the Trash.', 'smart-faq-feedback' ),
					'confirmDelete' => __( 'Permanently delete this FAQ and its like/dislike records? This cannot be undone.', 'smart-faq-feedback' ),
					'confirmBulk'   => __( 'Apply this bulk action to the selected FAQs? Permanently deleted FAQs cannot be recovered.', 'smart-faq-feedback' ),
					'confirmEmpty'  => __( 'Permanently delete ALL FAQs in the Trash? This cannot be undone.', 'smart-faq-feedback' ),
					'saved'         => __( 'Order saved.', 'smart-faq-feedback' ),
					'failed'        => __( 'Could not save the new order. Please reload and try again.', 'smart-faq-feedback' ),
				),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Reordering (AJAX)
	 * ---------------------------------------------------------------- */

	/**
	 * Persist a drag-and-drop order.
	 *
	 * The rows on the current page swap into the menu_order "slots" they already
	 * occupied, so other pages of a long list keep their relative position.
	 */
	public function ajax_reorder() {
		check_ajax_referer( self::NONCE_REORDER, 'nonce' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every element is cast with absint() below.
		$raw_ids = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? wp_unslash( $_POST['ids'] ) : array();
		$ids     = array_values( array_unique( array_filter( array_map( 'absint', $raw_ids ) ) ) );
		$offset  = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;

		if ( count( $ids ) < 2 || count( $ids ) > 500 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'smart-faq-feedback' ) ), 400 );
		}

		foreach ( $ids as $id ) {
			if ( SFF_FAQ_Post_Type::POST_TYPE !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
				wp_send_json_error( array( 'message' => __( 'You are not allowed to reorder these FAQs.', 'smart-faq-feedback' ) ), 403 );
			}
		}

		global $wpdb;

		$slots = array();
		foreach ( $ids as $id ) {
			$slots[] = (int) get_post_field( 'menu_order', $id );
		}
		sort( $slots );
		if ( count( array_unique( $slots ) ) !== count( $slots ) ) {
			$slots = range( $offset + 1, $offset + count( $ids ) ); // Unset or duplicate values: renumber.
		}

		foreach ( $ids as $index => $id ) {
			$wpdb->update( $wpdb->posts, array( 'menu_order' => $slots[ $index ] ), array( 'ID' => $id ), array( '%d' ), array( '%d' ) );
			clean_post_cache( $id );
		}

		wp_send_json_success( array( 'message' => __( 'Order saved.', 'smart-faq-feedback' ) ) );
	}

	/* ------------------------------------------------------------------
	 * Settings page
	 * ---------------------------------------------------------------- */

	/**
	 * Add Settings submenu.
	 */
	public function add_settings_page() {
		add_submenu_page(
			'edit.php?post_type=' . SFF_FAQ_Post_Type::POST_TYPE,
			__( 'Smart FAQ Settings', 'smart-faq-feedback' ),
			__( 'Settings', 'smart-faq-feedback' ),
			'manage_options',
			self::SETTINGS_PAGE,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register the settings option.
	 */
	public function register_settings() {
		register_setting(
			'sff_settings_group',
			SFF_Plugin::OPTION_SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => SFF_Plugin::default_settings(),
			)
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = SFF_Plugin::default_settings();

		return array(
			'show_helpful'        => empty( $input['show_helpful'] ) ? 0 : 1,
			'helpful_text'        => isset( $input['helpful_text'] ) ? sanitize_text_field( $input['helpful_text'] ) : $defaults['helpful_text'],
			'show_empty_message'  => empty( $input['show_empty_message'] ) ? 0 : 1,
			'empty_message'       => isset( $input['empty_message'] ) ? sanitize_text_field( $input['empty_message'] ) : $defaults['empty_message'],
			'delete_on_uninstall' => empty( $input['delete_on_uninstall'] ) ? 0 : 1,
		);
	}

	/**
	 * Render the settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'smart-faq-feedback' ), 403 );
		}

		$s      = SFF_Plugin::get_settings();
		$name   = SFF_Plugin::OPTION_SETTINGS;
		$counts = wp_count_posts( SFF_FAQ_Post_Type::POST_TYPE );
		?>
		<div class="wrap sff-settings">
			<h1><?php esc_html_e( 'Smart FAQ Settings', 'smart-faq-feedback' ); ?></h1>

			<p>
				<?php
				printf(
					/* translators: 1: published count, 2: draft count */
					esc_html__( 'Total FAQs: %1$s published, %2$s drafts.', 'smart-faq-feedback' ),
					'<strong>' . esc_html( number_format_i18n( (int) $counts->publish ) ) . '</strong>',
					'<strong>' . esc_html( number_format_i18n( (int) $counts->draft ) ) . '</strong>'
				);
				?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'sff_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Helpfulness question', 'smart-faq-feedback' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[show_helpful]" value="1" <?php checked( $s['show_helpful'], 1 ); ?>> <?php esc_html_e( 'Show a question next to the Like / Dislike buttons', 'smart-faq-feedback' ); ?></label>
							<p><input type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[helpful_text]" value="<?php echo esc_attr( $s['helpful_text'] ); ?>" aria-label="<?php esc_attr_e( 'Helpfulness question text', 'smart-faq-feedback' ); ?>"></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Empty-state message', 'smart-faq-feedback' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[show_empty_message]" value="1" <?php checked( $s['show_empty_message'], 1 ); ?>> <?php esc_html_e( 'Show a message when there are no published FAQs', 'smart-faq-feedback' ); ?></label>
							<p><input type="text" class="large-text" name="<?php echo esc_attr( $name ); ?>[empty_message]" value="<?php echo esc_attr( $s['empty_message'] ); ?>" aria-label="<?php esc_attr_e( 'Empty-state message text', 'smart-faq-feedback' ); ?>"></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Uninstall', 'smart-faq-feedback' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[delete_on_uninstall]" value="1" <?php checked( $s['delete_on_uninstall'], 1 ); ?>> <?php esc_html_e( 'Delete ALL plugin data (FAQs, categories, feedback table, settings) when the plugin is deleted', 'smart-faq-feedback' ); ?></label>
							<p class="description"><?php esc_html_e( 'Off by default. Deactivating or updating the plugin never deletes data.', 'smart-faq-feedback' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Shortcode reference', 'smart-faq-feedback' ); ?></h2>
			<p><code>[smart_faq]</code></p>
			<table class="widefat striped sff-ref">
				<thead><tr><th><?php esc_html_e( 'Attribute', 'smart-faq-feedback' ); ?></th><th><?php esc_html_e( 'Values', 'smart-faq-feedback' ); ?></th><th><?php esc_html_e( 'Default', 'smart-faq-feedback' ); ?></th></tr></thead>
				<tbody>
					<tr><td><code>category</code></td><td><?php esc_html_e( 'One or more category slugs, comma separated', 'smart-faq-feedback' ); ?></td><td><?php esc_html_e( 'all', 'smart-faq-feedback' ); ?></td></tr>
					<tr><td><code>orderby</code></td><td><code>menu_order</code>, <code>date</code>, <code>title</code>, <code>modified</code>, <code>rand</code></td><td><code>menu_order</code></td></tr>
					<tr><td><code>order</code></td><td><code>ASC</code>, <code>DESC</code></td><td><code>ASC</code></td></tr>
					<tr><td><code>limit</code></td><td><?php esc_html_e( 'Maximum number of FAQs (1–500)', 'smart-faq-feedback' ); ?></td><td><?php esc_html_e( 'all', 'smart-faq-feedback' ); ?></td></tr>
					<tr><td><code>open</code></td><td><code>none</code>, <code>first</code>, <code>all</code></td><td><code>none</code></td></tr>
					<tr><td><code>feedback</code></td><td><code>yes</code>, <code>no</code></td><td><code>yes</code></td></tr>
					<tr><td><code>heading_level</code></td><td>2–6</td><td>3</td></tr>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'Example: [smart_faq category="billing" orderby="menu_order" order="ASC" limit="10"]', 'smart-faq-feedback' ); ?></p>
		</div>
		<?php
	}
}
