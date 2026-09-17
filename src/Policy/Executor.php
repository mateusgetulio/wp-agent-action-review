<?php
/**
 * Enforces the Agents API action policy for every agent request.
 *
 * @package AgentActionReview
 */

namespace AgentActionReview\Policy;

defined( 'ABSPATH' ) || exit;

use AgentActionReview\Actions\ActionHandler;
use AgentActionReview\Actions\PostInput;
use AgentActionReview\Audit\EventLog;
use AgentActionReview\Pending\Digest;
use AgentActionReview\Pending\PendingActionTable;
use AgentsAPI\AI\Approvals\WP_Agent_Pending_Action;
use AgentsAPI\AI\Tools\WP_Agent_Action_Policy;
use WP_Agent_Action_Policy_Resolver;
use WP_Error;

/**
 * The only place an agent request turns into a WordPress side effect.
 *
 * Agents API supplies the policy vocabulary and resolver; it does not
 * intercept ability calls. This class asks the resolver and enforces the
 * answer: direct runs now, preview becomes a pending action, forbidden stops
 * before the handler is touched.
 */
final class Executor {

	public const PROPOSAL_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Pending action storage.
	 *
	 * @var PendingActionTable
	 */
	private PendingActionTable $pending;

	/**
	 * Decision log.
	 *
	 * @var EventLog
	 */
	private EventLog $events;

	/**
	 * Agents API policy resolver.
	 *
	 * @var WP_Agent_Action_Policy_Resolver
	 */
	private WP_Agent_Action_Policy_Resolver $resolver;

	/**
	 * Constructor.
	 *
	 * @param PendingActionTable                   $pending  Pending action storage.
	 * @param EventLog                             $events   Decision log.
	 * @param WP_Agent_Action_Policy_Resolver|null $resolver Agents API resolver; a default one when omitted.
	 */
	public function __construct( PendingActionTable $pending, EventLog $events, ?WP_Agent_Action_Policy_Resolver $resolver = null ) {
		$this->pending  = $pending;
		$this->events   = $events;
		$this->resolver = $resolver ?? new WP_Agent_Action_Policy_Resolver();
	}

	/**
	 * Resolve the policy for a request and enforce it.
	 *
	 * Order matters: forbidden is decided before any handler code runs; the
	 * input is then normalized once, and that canonical input is what gets
	 * authorized, applied or stored.
	 *
	 * @param ActionHandler        $handler The requested action.
	 * @param array<string, mixed> $raw     Input from the ability call.
	 * @return array<string, mixed>|WP_Error Apply result, approval envelope, or error.
	 */
	public function run( ActionHandler $handler, array $raw ) {
		$user_id     = get_current_user_id();
		$auth_source = self::auth_source();
		$post_id     = PostInput::post_id( $raw );
		$post_id     = $post_id > 0 ? $post_id : null;

		try {
			$policy = $this->resolve_policy( $handler, $user_id );
		} catch ( \Throwable $exception ) {
			$this->events->add( $handler->name(), WP_Agent_Action_Policy::FORBIDDEN, EventLog::FAILED, $user_id, $auth_source, null, $post_id );

			return new WP_Error( 'agent_review_policy_unavailable', __( 'The action policy could not be resolved, so nothing was run.', 'agent-action-review' ), array( 'status' => 500 ) );
		}

		if ( WP_Agent_Action_Policy::FORBIDDEN === $policy ) {
			$this->events->add( $handler->name(), $policy, EventLog::FORBIDDEN, $user_id, $auth_source, null, $post_id );

			return new WP_Error(
				'agent_review_forbidden',
				/* translators: %s: ability name. */
				sprintf( __( '%s is forbidden by policy. Nothing was changed.', 'agent-action-review' ), $handler->name() ),
				array( 'status' => 403 )
			);
		}

		$input = $handler->normalize_input( $raw );

		if ( is_wp_error( $input ) ) {
			$this->events->add( $handler->name(), $policy, EventLog::FAILED, $user_id, $auth_source, null, $post_id );

			return $input;
		}

		// A permission callback can be overridden by the wp_ability_permission_result filter, so authorization is checked here too.
		if ( ! $handler->authorize( $user_id, $input ) ) {
			$this->events->add( $handler->name(), $policy, EventLog::DENIED, $user_id, $auth_source, null, $post_id );

			return new WP_Error( 'agent_review_denied', __( 'You are not allowed to do that.', 'agent-action-review' ), array( 'status' => 403 ) );
		}

		if ( WP_Agent_Action_Policy::DIRECT === $policy ) {
			$result = $handler->apply( $input );
			$this->events->add( $handler->name(), $policy, is_wp_error( $result ) ? EventLog::FAILED : EventLog::EXECUTED, $user_id, $auth_source, null, $post_id );

			return $result;
		}

		return $this->propose( $handler, $input, $user_id, $auth_source, $post_id );
	}

	/**
	 * The resolver's policy, failing closed to forbidden on anything unexpected.
	 *
	 * @param ActionHandler $handler The requested action.
	 * @param int           $user_id Requesting user.
	 * @return string
	 */
	public function resolve_policy( ActionHandler $handler, int $user_id ): string {
		$resolved = $this->resolver->resolve_for_tool(
			array(
				'tool_name' => $handler->name(),
				'tool_def'  => array(
					'action_policy' => $handler->default_policy(),
					'ability'       => $handler->name(),
					'category'      => 'agent-review',
				),
				'user_id'   => $user_id,
				'workspace' => self::workspace(),
			)
		);

		return WP_Agent_Action_Policy::normalize( $resolved ) ?? WP_Agent_Action_Policy::FORBIDDEN;
	}

	/**
	 * Store a pending action and return the upstream approval envelope.
	 *
	 * @param ActionHandler        $handler     The requested action.
	 * @param array<string, mixed> $input       Validated input.
	 * @param int                  $user_id     Requesting user.
	 * @param string               $auth_source How the request authenticated.
	 * @param int|null             $post_id     Target post.
	 * @return array<string, mixed>|WP_Error
	 */
	private function propose( ActionHandler $handler, array $input, int $user_id, string $auth_source, ?int $post_id ) {
		$description = $handler->describe( $input );

		if ( is_wp_error( $description ) ) {
			$this->events->add( $handler->name(), WP_Agent_Action_Policy::PREVIEW, EventLog::FAILED, $user_id, $auth_source, null, $post_id );

			return $description;
		}

		$now = time();

		try {
			$draft = WP_Agent_Pending_Action::from_array(
				array(
					'action_id'   => wp_generate_uuid4(),
					'kind'        => $handler->name(),
					'summary'     => $description['summary'],
					'preview'     => $description['preview'],
					'apply_input' => $input,
					'workspace'   => self::workspace(),
					'agent'       => self::agent_label(),
					'creator'     => 'user:' . $user_id,
					'created_at'  => gmdate( 'c', $now ),
					'expires_at'  => gmdate( 'c', $now + self::PROPOSAL_TTL ),
				)
			);

			$normalized = $draft->to_array();
			$action     = WP_Agent_Pending_Action::from_array(
				array_merge(
					$normalized,
					array(
						'metadata' => array(
							'request_digest'       => Digest::of( $normalized['kind'], (array) $normalized['apply_input'], (string) $normalized['creator'], $normalized['workspace'] ),
							'resource_fingerprint' => $handler->fingerprint( $input ),
							'auth_source'          => $auth_source,
						),
					)
				)
			);
		} catch ( \JsonException | \InvalidArgumentException $exception ) {
			$this->events->add( $handler->name(), WP_Agent_Action_Policy::PREVIEW, EventLog::FAILED, $user_id, $auth_source, null, $post_id );

			return new WP_Error( 'agent_review_invalid_proposal', __( 'This request cannot be turned into a reviewable proposal.', 'agent-action-review' ) );
		}

		if ( ! $this->pending->store( $action ) ) {
			$this->events->add( $handler->name(), WP_Agent_Action_Policy::PREVIEW, EventLog::FAILED, $user_id, $auth_source, null, $post_id );

			return new WP_Error( 'agent_review_store_failed', __( 'The proposal could not be saved.', 'agent-action-review' ) );
		}

		$this->events->add( $handler->name(), WP_Agent_Action_Policy::PREVIEW, EventLog::PROPOSED, $user_id, $auth_source, $action->get_action_id(), $post_id );

		return $action->to_approval_envelope(
			sprintf(
				/* translators: %s: URL of the review screen. */
				__( 'Nothing has changed yet. A person must review and approve this in wp-admin before it runs: %s', 'agent-action-review' ),
				self::review_url( $action->get_action_id() )
			)
		);
	}

	/**
	 * Review screen URL for one action.
	 *
	 * @param string $action_id Action ID.
	 * @return string
	 */
	public static function review_url( string $action_id ): string {
		return add_query_arg(
			array(
				'page'      => 'agent-action-review',
				'action_id' => $action_id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Workspace scope for this site.
	 *
	 * @return array<string, string>
	 */
	public static function workspace(): array {
		return array(
			'workspace_type' => 'wordpress_site',
			'workspace_id'   => (string) get_current_blog_id(),
		);
	}

	/**
	 * How the current request authenticated. Audit and display metadata only, never identity.
	 *
	 * @return string
	 */
	public static function auth_source(): string {
		if ( function_exists( 'rest_get_authenticated_app_password' ) && null !== rest_get_authenticated_app_password() ) {
			return 'application_password';
		}

		return is_user_logged_in() ? 'user' : 'anonymous';
	}

	/**
	 * Display label for the transport. MCP Adapter 0.6.1 does not keep the
	 * client name after initialize, so MCP calls are labeled mcp:unknown. A
	 * client could call itself anything anyway, so this is never identity.
	 *
	 * @return string
	 */
	public static function agent_label(): string {
		global $wp;

		$route = isset( $wp->query_vars['rest_route'] ) && is_string( $wp->query_vars['rest_route'] ) ? $wp->query_vars['rest_route'] : '';

		return 0 === strpos( $route, '/mcp/' ) ? 'mcp:unknown' : 'site';
	}
}
