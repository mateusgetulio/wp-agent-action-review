<?php
/**
 * Plugin Name:       Agent Action Review
 * Description:       Agent writes become reviewable, one-time pending actions before they can change WordPress. A reference consumer of the Agents API approval primitives.
 * Version:           0.1.0
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Author:            Mateus Getulio
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       agent-action-review
 * Update URI:        false
 *
 * @package AgentActionReview
 */

defined( 'ABSPATH' ) || exit;

define( 'AGENT_ACTION_REVIEW_VERSION', '0.1.0' );
define( 'AGENT_ACTION_REVIEW_FILE', __FILE__ );

// Runtime classes load without Composer; vendor/ only holds development tools.
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'AgentActionReview\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';

		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( AgentActionReview\Plugin::class, 'activate' ) );

add_action( 'plugins_loaded', array( AgentActionReview\Plugin::class, 'boot' ), 20 );
