<?php
/**
 * Registers the demo abilities.
 *
 * @package AgentActionReview
 */

namespace AgentActionReview\Abilities;

defined( 'ABSPATH' ) || exit;

use AgentActionReview\Actions\ActionHandler;
use AgentActionReview\Policy\Executor;

/**
 * Exposes each action handler as a WordPress ability that MCP Adapter can publish.
 */
final class Registrar {

	public const CATEGORY = 'agent-review';

	/**
	 * Enforces policy for every call.
	 *
	 * @var Executor
	 */
	private Executor $executor;

	/**
	 * Handlers to register.
	 *
	 * @var ActionHandler[]
	 */
	private array $handlers;

	/**
	 * Constructor.
	 *
	 * @param Executor        $executor Enforces policy for every call.
	 * @param ActionHandler[] $handlers Handlers to register.
	 */
	public function __construct( Executor $executor, array $handlers ) {
		$this->executor = $executor;
		$this->handlers = $handlers;
	}

	/**
	 * Hook into the Abilities API.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Register the category.
	 *
	 * @return void
	 */
	public function register_category(): void {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Agent Action Review', 'agent-action-review' ),
				'description' => __( 'Post actions an agent can request, under direct, preview or forbidden policy.', 'agent-action-review' ),
			)
		);
	}

	/**
	 * Register one ability per handler.
	 *
	 * The permission callback checks the requesting user's capability; the
	 * executor checks it again and then enforces the policy.
	 *
	 * @return void
	 */
	public function register_abilities(): void {
		foreach ( $this->handlers as $handler ) {
			wp_register_ability(
				$handler->name(),
				array(
					'label'               => $handler->label(),
					'description'         => $handler->description(),
					'category'            => self::CATEGORY,
					'input_schema'        => $handler->input_schema(),
					'permission_callback' => static function ( $input ) use ( $handler ): bool {
						$normalized = $handler->normalize_input( is_array( $input ) ? $input : array() );

						// Invalid input is let through here so the executor returns the precise validation error; it authorizes again before anything runs.
						return is_wp_error( $normalized ) || $handler->authorize( get_current_user_id(), $normalized );
					},
					'execute_callback'    => fn( $input ) => $this->executor->run( $handler, is_array( $input ) ? $input : array() ),
					'meta'                => array(
						'mcp'         => array( 'public' => true ),
						'annotations' => $handler->annotations(),
					),
				)
			);
		}
	}
}
