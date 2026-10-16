<?php
/**
 * Core plugin bootstrap.
 *
 * @package SmartFAQFeedback
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin components together.
 */
class SFF_Plugin {

	const OPTION_SETTINGS = 'sff_settings';

	/**
	 * Singleton instance.
	 *
	 * @var SFF_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton.
	 *
	 * @return SFF_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor: register hooks.
	 */
	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'boot' ) );
		add_action( 'init', array( $this, 'load_textdomain' ), 1 );
	}

	/**
	 * Boot components.
	 */
	public function boot() {
		// Create / upgrade the database table when the stored schema is old or missing.
		SFF_Database::maybe_upgrade();

		$post_type = new SFF_FAQ_Post_Type();
		$post_type->init();

		$feedback = new SFF_Feedback();
		$feedback->init();

		$shortcode = new SFF_Shortcode();
		$shortcode->init();

		if ( is_admin() ) {
			$admin = new SFF_Admin();
			$admin->init();
		}
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'smart-faq-feedback', false, dirname( plugin_basename( SFF_PLUGIN_FILE ) ) . '/languages' );
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function default_settings() {
		return array(
			'show_helpful'        => 1,
			'helpful_text'        => __( 'Was this answer helpful?', 'smart-faq-feedback' ),
			'show_empty_message'  => 1,
			'empty_message'       => __( 'No FAQs are available yet. Please check back soon.', 'smart-faq-feedback' ),
			'delete_on_uninstall' => 0,
		);
	}

	/**
	 * Get all settings merged with defaults.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$saved = get_option( self::OPTION_SETTINGS, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, self::default_settings() );
	}

	/**
	 * Activation: install schema, store default settings. Never deletes data.
	 */
	public static function activate() {
		SFF_Database::install();

		$post_type = new SFF_FAQ_Post_Type();
		$post_type->register();
		flush_rewrite_rules();

		if ( false === get_option( self::OPTION_SETTINGS, false ) ) {
			add_option( self::OPTION_SETTINGS, self::default_settings(), '', 'no' );
		}
	}

	/**
	 * Deactivation: non-destructive.
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}
