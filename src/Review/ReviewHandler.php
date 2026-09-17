<?php
/**
 * Approve and reject pending actions from wp-admin.
 *
 * @package AgentActionReview
 */

namespace AgentActionReview\Review;

defined( 'ABSPATH' ) || exit;

use AgentActionReview\Actions\ActionHandler;
use AgentActionReview\Pending\Digest;
use AgentActionReview\Pending\Fingerprint;
use AgentActionReview\Pending\PendingActionTable;
use AgentActionReview\Policy\Executor;
use AgentsAPI\AI\Approvals\WP_Agent_Approval_Decision;
use AgentsAPI\AI\Approvals\WP_Agent_Pending_Action;
use AgentsAPI\AI\Tools\WP_Agent_Action_Policy;
use RuntimeException;

/**
 * The human side of the approval boundary.
 *
 * Approval requires a valid nonce from a logged-in wp-admin session,
 * manage_options, edit_post on the target, and a reviewer account different
 * from the creator account. The resolver identity always comes from the
 * session, never from the request. Accepting means the reviewer agreed; the
 * result or error fields say whether applying succeeded.
 */
final class ReviewHandler {

	public const APPROVE = 'agent_action_review_approve';

	public const REJECT = 'agent_action_review_reject';

	public const CAPABILITY = 'manage_options';

	/**
	 * Pending action storage.
	 *
	 * @var PendingActionTable
	 */
	private PendingActionTable $pending;

	/**
	 * Resolves the current policy again at approval time.
	 *
	 * @var Executor
	 */
	private Executor $executor;

	/**
	 * Handlers keyed by ability name.
	 *
	 * @var array<string, ActionHandler>
	 */
	private array $handlers;

	/**
	 * Constructor.
	 *
	 * @param PendingActionTable $pending  Pending action storage.
	 * @param Executor           $executor Resolves the current policy again at approval time.
	 * @param ActionHandler[]    $handlers Handlers that can apply stored actions.
	 */
	public function __construct( PendingActionTable $pending, Executor $executor, array $handlers ) {
		$this->pending  = $pending;
		$this->executor = $executor;
		$this->handlers = array();

		foreach ( $handlers as $handler ) {
			$this->handlers[ $handler->name() ] = $handler;
		}
	}

	/**
	 * Register the admin-post handlers. There are no nopriv variants.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::APPROVE, array( $this, 'handle_approve' ) );
		add_action( 'admin_post_' . self::REJECT, array( $this, 'handle_reject' ) );
	}

	/**
	 * Nonce action for one pending action.
	 *
	 * @param string $action_id Action ID.
	 * @return string
	 */
	public static function nonce_action( string $action_id ): string {
		return 'agent_action_review_resolve_' . $action_id;
	}

	/**
	 * Request handler for Approve and run.
	 *
	 * @return void
	 */
	public function handle_approve(): void {
		$action_id = $this->verified_action_id();

		$this->redirect( $action_id, $this->approve( $action_id, get_current_user_id() ) );
	}

	/**
	 * Request handler for Reject.
	 *
	 * @return void
	 */
	public function handle_reject(): void {
		$action_id = $this->verified_action_id();

		$this->redirect( $action_id, $this->reject( $action_id, get_current_user_id() ) );
	}

	/**
	 * Accept and apply one action on behalf of an already verified reviewer.
	 *
	 * @param string $action_id   Action ID.
	 * @param int    $reviewer_id Reviewer user ID from the session.
	 * @return string Outcome code shown to the reviewer.
	 */
	public function approve( string $action_id, int $reviewer_id ): string {
		$checked = $this->check_resolvable( $action_id, $reviewer_id );

		if ( is_string( $checked ) ) {
			return $checked;
		}

		list( $action, $handler, $creator_id ) = $checked;

		$input       = (array) $action->get_apply_input();
		$fingerprint = (string) ( $action->get_metadata()['resource_fingerprint'] ?? '' );

		if ( ! $this->is_consistent( $action ) ) {
			return $this->expire( $action_id, 'inconsistent_state' );
		}

		if ( WP_Agent_Action_Policy::FORBIDDEN === $this->current_policy( $handler, $creator_id ) ) {
			return $this->expire( $action_id, 'policy_forbidden' );
		}

		if ( ! self::is_fresh( $handler, $input, $fingerprint ) ) {
			return $this->expire( $action_id, 'resource_changed' );
		}

		$resolver = 'user:' . $reviewer_id;

		try {
			if ( ! $this->pending->claim( $action_id, WP_Agent_Approval_Decision::accepted(), $resolver, time() ) ) {
				return 'already_resolved';
			}
		} catch ( RuntimeException $exception ) {
			return 'storage_error';
		}

		if ( ! $handler->authorize( $creator_id, $input ) ) {
			$reason = self::is_fresh( $handler, $input, $fingerprint ) ? 'permission_revoked' : 'resource_changed';

			return $this->record( $action_id, $resolver, null, $reason, array(), $reason );
		}

		// Checked again right before applying; a concurrent edit after this line can still race wp_update_post() (see README).
		if ( ! self::is_fresh( $handler, $input, $fingerprint ) ) {
			return $this->record( $action_id, $resolver, null, 'resource_changed', array(), 'resource_changed' );
		}

		$result = $handler->apply( $input );

		if ( is_wp_error( $result ) ) {
			return $this->record( $action_id, $resolver, null, 'apply_failed', array( 'wp_error_code' => $result->get_error_code() ), 'apply_failed' );
		}

		return $this->record( $action_id, $resolver, $result, null, array(), 'applied' );
	}

	/**
	 * Reject one action on behalf of an already verified reviewer.
	 *
	 * @param string $action_id   Action ID.
	 * @param int    $reviewer_id Reviewer user ID from the session.
	 * @return string Outcome code shown to the reviewer.
	 */
	public function reject( string $action_id, int $reviewer_id ): string {
		$checked = $this->check_resolvable( $action_id, $reviewer_id );

		if ( is_string( $checked ) ) {
			return $checked;
		}

		$resolver = 'user:' . $reviewer_id;

		try {
			if ( ! $this->pending->claim( $action_id, WP_Agent_Approval_Decision::rejected(), $resolver, time() ) ) {
				return 'already_resolved';
			}

			$recorded = $this->pending->record_resolution( $action_id, WP_Agent_Approval_Decision::rejected(), $resolver, null, null, array( 'auth_source' => 'browser_session' ) );
		} catch ( RuntimeException $exception ) {
			return 'storage_error';
		}

		return $recorded ? 'rejected' : 'rejected_unrecorded';
	}

	/**
	 * Move a pending action to expired with a reason.
	 *
	 * @param string $action_id Action ID.
	 * @param string $reason    Reason code, also returned as the outcome.
	 * @return string
	 */
	private function expire( string $action_id, string $reason ): string {
		try {
			return $this->pending->expire_if_pending( $action_id, $reason, time() ) ? $reason : 'already_resolved';
		} catch ( RuntimeException $exception ) {
			return 'storage_error';
		}
	}

	/**
	 * Write the result or error of an accepted action.
	 *
	 * @param string               $action_id Action ID.
	 * @param string               $resolver  Resolver principal.
	 * @param mixed                $result    Apply result.
	 * @param string|null          $error     Error code.
	 * @param array<string, mixed> $extra     Extra resolution metadata.
	 * @param string               $outcome   Outcome to return when recorded.
	 * @return string
	 */
	private function record( string $action_id, string $resolver, $result, ?string $error, array $extra, string $outcome ): string {
		try {
			$recorded = $this->pending->record_resolution( $action_id, WP_Agent_Approval_Decision::accepted(), $resolver, $result, $error, array( 'auth_source' => 'browser_session' ) + $extra );
		} catch ( RuntimeException $exception ) {
			$recorded = false;
		}

		if ( $recorded ) {
			return $outcome;
		}

		return 'applied' === $outcome ? 'applied_unrecorded' : 'unrecorded';
	}

	/**
	 * The policy that would apply to this request now, failing closed.
	 *
	 * @param ActionHandler $handler    Handler.
	 * @param int           $creator_id Requesting user.
	 * @return string
	 */
	private function current_policy( ActionHandler $handler, int $creator_id ): string {
		try {
			return $this->executor->resolve_policy( $handler, $creator_id );
		} catch ( \Throwable $exception ) {
			return WP_Agent_Action_Policy::FORBIDDEN;
		}
	}

	/**
	 * Checks shared by approve and reject.
	 *
	 * @param string $action_id   Action ID.
	 * @param int    $reviewer_id Reviewer user ID.
	 * @return array{0: WP_Agent_Pending_Action, 1: ActionHandler, 2: int}|string The action, its handler and the creator ID, or an outcome code.
	 */
	private function check_resolvable( string $action_id, int $reviewer_id ) {
		if ( $reviewer_id <= 0 || ! user_can( $reviewer_id, self::CAPABILITY ) ) {
			return 'not_allowed';
		}

		$action = $this->pending->get( $action_id );

		if ( null === $action ) {
			$this->pending->expire();

			return null === $this->pending->get( $action_id, true ) ? 'not_found' : 'already_resolved';
		}

		$creator_id = self::user_id_from_principal( (string) $action->get_creator() );

		if ( $creator_id <= 0 ) {
			return 'inconsistent_state';
		}

		if ( $creator_id === $reviewer_id ) {
			return 'self_review';
		}

		$handler = $this->handlers[ $action->get_kind() ] ?? null;

		if ( null === $handler ) {
			return 'inconsistent_state';
		}

		$input = (array) $action->get_apply_input();

		// A post that no longer exists cannot be checked for edit_post; approving it fails the freshness check instead, and rejecting stays possible.
		if ( isset( $input['post_id'] ) && null !== get_post( (int) $input['post_id'] ) && ! user_can( $reviewer_id, 'edit_post', (int) $input['post_id'] ) ) {
			return 'not_allowed';
		}

		return array( $action, $handler, $creator_id );
	}

	/**
	 * Whether the stored digest still matches the stored proposal fields.
	 *
	 * @param WP_Agent_Pending_Action $action Stored action.
	 * @return bool
	 */
	private function is_consistent( WP_Agent_Pending_Action $action ): bool {
		$digest    = (string) ( $action->get_metadata()['request_digest'] ?? '' );
		$workspace = $action->get_workspace();

		if ( '' === $digest ) {
			return false;
		}

		try {
			return Digest::matches( $digest, $action->get_kind(), (array) $action->get_apply_input(), (string) $action->get_creator(), null === $workspace ? null : $workspace->to_array() );
		} catch ( \JsonException $exception ) {
			return false;
		}
	}

	/**
	 * Whether the resource still looks exactly as it did when proposed.
	 *
	 * An action that depends on no resource stores an empty fingerprint and
	 * is fresh only while its handler still reports none.
	 *
	 * @phpstan-impure
	 * @param ActionHandler        $handler  Handler.
	 * @param array<string, mixed> $input    Stored input.
	 * @param string               $expected Stored fingerprint.
	 * @return bool
	 */
	public static function is_fresh( ActionHandler $handler, array $input, string $expected ): bool {
		try {
			$current = $handler->fingerprint( $input );
		} catch ( \JsonException $exception ) {
			return false;
		}

		return '' === $expected ? '' === $current : Fingerprint::matches( $expected, $current );
	}

	/**
	 * User ID from a user:N principal, or 0.
	 *
	 * @param string $principal Principal string.
	 * @return int
	 */
	public static function user_id_from_principal( string $principal ): int {
		return 1 === preg_match( '/^user:([1-9][0-9]*)$/', $principal, $matches ) ? (int) $matches[1] : 0;
	}

	/**
	 * Check the nonce and capability and return the posted action ID.
	 *
	 * @return string
	 */
	private function verified_action_id(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read only to build the nonce action, verified on the next line.
		$action_id = isset( $_POST['action_id'] ) ? sanitize_text_field( wp_unslash( $_POST['action_id'] ) ) : '';

		if ( 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $action_id ) ) {
			wp_die( esc_html__( 'Invalid action.', 'agent-action-review' ), '', array( 'response' => 400 ) );
		}

		check_admin_referer( self::nonce_action( $action_id ) );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to review agent actions.', 'agent-action-review' ), '', array( 'response' => 403 ) );
		}

		return $action_id;
	}

	/**
	 * Back to the review screen with an outcome notice.
	 *
	 * @param string $action_id Action ID.
	 * @param string $outcome   Outcome code.
	 * @return void
	 */
	private function redirect( string $action_id, string $outcome ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                        => 'agent-action-review',
					'action_id'                   => $action_id,
					'agent_action_review_outcome' => $outcome,
				),
				admin_url( 'admin.php' )
			),
			303
		);
		exit;
	}
}
