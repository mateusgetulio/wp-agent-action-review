<?php
/**
 * Composition root.
 *
 * @package AgentActionReview
 */

namespace AgentActionReview;

defined( 'ABSPATH' ) || exit;

use AgentActionReview\Pending\PendingActionTable;

/**
 * Checks dependencies and wires the plugin's services.
 */
final class Plugin {

	/**
	 * Classes and functions this plugin cannot run without.
	 */
	private const REQUIRED = array(
		'classes'   => array(
			'WP_Agent_Action_Policy_Resolver',
			'AgentsAPI\\AI\\Tools\\WP_Agent_Action_Policy',
			'AgentsAPI\\AI\\Approvals\\WP_Agent_Pending_Action',
			'AgentsAPI\\AI\\Approvals\\WP_Agent_Pending_Action_Store',
		),
		'functions' => array( 'wp_register_ability' ),
	);

	/**
	 * Create the storage on activation.
	 *
	 * @return void
	 */
	public static function activate(): void {
		if ( array() !== self::missing_dependencies() ) {
			return;
		}

		PendingActionTable::install();
	}

	/**
	 * Register everything, or only a notice when a dependency is missing.
	 *
	 * Runs on plugins_loaded at priority 20, after Agents API and MCP Adapter
	 * have loaded, including a copy another plugin boots on plugins_loaded.
	 *
	 * @return void
	 */
	public static function boot(): void {
		$missing = self::missing_dependencies();

		if ( array() !== $missing ) {
			add_action(
				'admin_notices',
				static function () use ( $missing ): void {
					if ( ! current_user_can( 'activate_plugins' ) ) {
						return;
					}

					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html(
							sprintf(
								/* translators: %s: comma-separated list of missing classes or functions. */
								__( 'Agent Action Review needs Agents API and the Abilities API. Missing: %s', 'agent-action-review' ),
								implode( ', ', $missing )
							)
						)
					);
				}
			);

			return;
		}

		PendingActionTable::maybe_install();
	}

	/**
	 * Names of missing classes and functions.
	 *
	 * @return string[]
	 */
	public static function missing_dependencies(): array {
		$missing = array();

		foreach ( self::REQUIRED['classes'] as $class_name ) {
			if ( ! class_exists( $class_name ) && ! interface_exists( $class_name ) ) {
				$missing[] = $class_name;
			}
		}

		foreach ( self::REQUIRED['functions'] as $function_name ) {
			if ( ! function_exists( $function_name ) ) {
				$missing[] = $function_name;
			}
		}

		return $missing;
	}
}
