<?php
/**
 * Plugin Name:       OliForge Session Control
 * Plugin URI:        https://oliforge.com/
 * Description:       Manage WordPress authentication cookie lifetimes and optional idle logout timeouts, with a searchable, filterable Active Sessions log and a network-wide aggregate view on multisite.
 * Version:           2.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            OliForge
 * Author URI:        https://oliforge.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       oliforge-session-control
 *
 * @package OliForge_Session_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OLIFORGE_SESSION_CONTROL_VERSION', '2.1.0' );
define( 'OLIFORGE_SESSION_CONTROL_FILE', __FILE__ );
define( 'OLIFORGE_SESSION_CONTROL_PATH', plugin_dir_path( __FILE__ ) );
define( 'OLIFORGE_SESSION_CONTROL_URL', plugin_dir_url( __FILE__ ) );

require_once OLIFORGE_SESSION_CONTROL_PATH . 'includes/class-oliforge-session-control.php';

/**
 * Boots the plugin.
 *
 * @return void
 */
function oliforge_session_control_boot(): void {
	OliForge_Session_Control::instance();
}
add_action( 'plugins_loaded', 'oliforge_session_control_boot' );

register_activation_hook( __FILE__, array( 'OliForge_Session_Control', 'activate' ) );
