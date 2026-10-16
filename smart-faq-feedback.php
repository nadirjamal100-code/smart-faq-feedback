<?php
/**
 * Plugin Name:       Smart FAQ & Feedback
 * Description:       A WordPress FAQ plugin that allows administrators to manage frequently asked questions and answers, while visitors can like or dislike individual answers.
 * Version:           1.0.0
 * Author:            Nadir Jamal
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       smart-faq-feedback
 * Domain Path:       /languages
 *
 * @package SmartFAQFeedback
 */

defined( 'ABSPATH' ) || exit;

define( 'SFF_VERSION', '1.0.0' );
define( 'SFF_DB_VERSION', '1' );
define( 'SFF_PLUGIN_FILE', __FILE__ );
define( 'SFF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SFF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once SFF_PLUGIN_DIR . 'includes/class-sff-database.php';
require_once SFF_PLUGIN_DIR . 'includes/class-sff-faq-post-type.php';
require_once SFF_PLUGIN_DIR . 'includes/class-sff-feedback.php';
require_once SFF_PLUGIN_DIR . 'includes/class-sff-shortcode.php';
require_once SFF_PLUGIN_DIR . 'includes/class-sff-admin.php';
require_once SFF_PLUGIN_DIR . 'includes/class-sff-plugin.php';

register_activation_hook( __FILE__, array( 'SFF_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SFF_Plugin', 'deactivate' ) );

SFF_Plugin::instance();
