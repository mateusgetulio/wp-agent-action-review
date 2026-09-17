<?php
/**
 * Composition root.
 *
 * @package AgentActionReview
 */

namespace AgentActionReview;

defined( 'ABSPATH' ) || exit;

use AgentActionReview\Abilities\Registrar;
use AgentActionReview\Admin\AdminPage;
use AgentActionReview\Actions\PostRead;
use AgentActionReview\Actions\PostTitleUpdate;
use AgentActionReview\Actions\PostTrash;
use AgentActionReview\Audit\EventLog;
use AgentActionReview\Pending\PendingActionTable;
use AgentActionReview\Policy\Executor;
use AgentActionReview\Review\ReviewHandler;

/**
 * Checks dependencies and wires the plugin's services.
 */
final class Plugin {

	public const DB_VERSION = '2';

	public const DB_VERSION_OPTION = 'agent_action_review_db_version';

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

		self::install_storage();
	}

	/**
	 * Create or update both tables.
	 *
	 * @return void
	 */
	public static function install_storage(): void {
		PendingActionTable::install();
		EventLog::install();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
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

		if ( self::DB_VERSION !== get_option( self::DB_VERSION_OPTION ) ) {
			self::install_storage();
		}

		$pending  = new PendingActionTable();
		$events   = new EventLog();
		$executor = new Executor( $pending, $events );

		$handlers = self::handlers();

		( new Registrar( $executor, $handlers ) )->register();
		( new ReviewHandler( $pending, $executor, $handlers ) )->register();

		if ( is_admin() ) {
			( new AdminPage( $pending, $events, $handlers ) )->register();
		}
	}

	/**
	 * The actions an agent can request.
	 *
	 * @return array<int, PostRead|PostTitleUpdate|PostTrash>
	 */
	public static function handlers(): array {
		return array( new PostRead(), new PostTitleUpdate(), new PostTrash() );
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
