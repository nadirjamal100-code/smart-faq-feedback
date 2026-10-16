<?php
/**
 * Uninstall routine.
 *
 * Runs only when the plugin is DELETED from the Plugins screen (not on
 * deactivation or update). It removes data ONLY if the administrator enabled
 * "Delete ALL plugin data" under Smart FAQ → Settings.
 *
 * @package SmartFAQFeedback
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean up one site.
 */
function sff_uninstall_current_site() {
	global $wpdb;

	$settings = get_option( 'sff_settings', array() );
	if ( ! is_array( $settings ) || empty( $settings['delete_on_uninstall'] ) ) {
		return; // Default: keep everything.
	}

	// FAQs (the post type is not registered during uninstall, so query directly).
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'sff_faq' ) );
	foreach ( $ids as $id ) {
		wp_delete_post( (int) $id, true );
	}

	// Categories.
	register_taxonomy( 'sff_faq_category', 'sff_faq' );
	$terms = get_terms(
		array(
			'taxonomy'   => 'sff_faq_category',
			'hide_empty' => false,
			'fields'     => 'ids',
		)
	);
	if ( ! is_wp_error( $terms ) ) {
		foreach ( $terms as $term_id ) {
			wp_delete_term( (int) $term_id, 'sff_faq_category' );
		}
	}

	// Feedback table.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sff_feedback" );

	delete_option( 'sff_settings' );
	delete_option( 'sff_db_version' );

	// Rate-limit transients.
	$like = $wpdb->esc_like( '_transient_sff_rl' ) . '%';
	$tlike = $wpdb->esc_like( '_transient_timeout_sff_rl' ) . '%';
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $like, $tlike ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $sff_blog_id ) {
		switch_to_blog( $sff_blog_id );
		sff_uninstall_current_site();
		restore_current_blog();
	}
} else {
	sff_uninstall_current_site();
}
