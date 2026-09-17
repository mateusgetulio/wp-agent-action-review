<?php
/**
 * Agent Review admin screens.
 *
 * @package AgentActionReview
 */

namespace AgentActionReview\Admin;

defined( 'ABSPATH' ) || exit;

use AgentActionReview\Actions\ActionHandler;
use AgentActionReview\Audit\EventLog;
use AgentActionReview\Pending\PendingActionTable;
use AgentActionReview\Review\ReviewHandler;
use AgentsAPI\AI\Approvals\WP_Agent_Pending_Action;
use AgentsAPI\AI\Approvals\WP_Agent_Pending_Action_Status;

/**
 * Pending list, review screen with the diff, and the activity log.
 */
final class AdminPage {

	public const SLUG = 'agent-action-review';

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
	 * Handlers keyed by ability name.
	 *
	 * @var array<string, ActionHandler>
	 */
	private array $handlers = array();

	/**
	 * Hook suffix of the page.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param PendingActionTable $pending  Pending action storage.
	 * @param EventLog           $events   Decision log.
	 * @param ActionHandler[]    $handlers Handlers, for freshness checks on the review screen.
	 */
	public function __construct( PendingActionTable $pending, EventLog $events, array $handlers ) {
		$this->pending = $pending;
		$this->events  = $events;

		foreach ( $handlers as $handler ) {
			$this->handlers[ $handler->name() ] = $handler;
		}
	}

	/**
	 * Register the menu, assets and removable query arguments.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter(
			'removable_query_args',
			static fn( $args ): array => array_merge( (array) $args, array( 'agent_action_review_outcome' ) )
		);
	}

	/**
	 * Add the top-level menu.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		$this->hook_suffix = (string) add_menu_page(
			__( 'Agent Review', 'agent-action-review' ),
			__( 'Agent Review', 'agent-action-review' ),
			ReviewHandler::CAPABILITY,
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-shield',
			59
		);
	}

	/**
	 * Load the stylesheet on this page only.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue( $hook_suffix ): void {
		if ( $hook_suffix === $this->hook_suffix ) {
			wp_enqueue_style( 'agent-action-review-admin', plugins_url( 'assets/admin.css', AGENT_ACTION_REVIEW_FILE ), array(), AGENT_ACTION_REVIEW_VERSION );
		}
	}

	/**
	 * Render the requested view.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( ReviewHandler::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to review agent actions.', 'agent-action-review' ), '', array( 'response' => 403 ) );
		}

		$this->pending->expire();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only navigation and notices.
		$action_id = isset( $_GET['action_id'] ) ? sanitize_text_field( wp_unslash( $_GET['action_id'] ) ) : '';
		$view      = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'pending';
		$outcome   = isset( $_GET['agent_action_review_outcome'] ) ? sanitize_key( wp_unslash( $_GET['agent_action_review_outcome'] ) ) : '';
		// phpcs:enable

		echo '<div class="wrap agent-review">';

		if ( '' !== $action_id ) {
			$this->render_review( $action_id, $outcome );
		} else {
			$this->render_tabs( 'activity' === $view ? 'activity' : 'pending' );
		}

		echo '</div>';
	}

	/**
	 * Heading and tabs, then the list.
	 *
	 * @param string $view pending or activity.
	 * @return void
	 */
	private function render_tabs( string $view ): void {
		$tabs = array(
			'pending'  => __( 'Pending', 'agent-action-review' ),
			'activity' => __( 'Activity', 'agent-action-review' ),
		);
		?>
		<h1><?php esc_html_e( 'Agent Review', 'agent-action-review' ); ?></h1>
		<hr class="wp-header-end">
		<nav class="nav-tab-wrapper">
			<?php foreach ( $tabs as $key => $label ) : ?>
				<a href="<?php echo esc_url( self::url( array( 'view' => $key ) ) ); ?>" class="nav-tab<?php echo $key === $view ? ' nav-tab-active' : ''; ?>"<?php echo $key === $view ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
		if ( 'activity' === $view ) {
			$this->render_activity();
		} else {
			$this->render_pending();
		}
	}

	/**
	 * Pending actions waiting for a human.
	 *
	 * @return void
	 */
	private function render_pending(): void {
		$actions = $this->pending->list( array( 'status' => WP_Agent_Pending_Action_Status::PENDING ) );
		?>
		<table class="wp-list-table widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Proposal', 'agent-action-review' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Requested by', 'agent-action-review' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Agent', 'agent-action-review' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Created', 'agent-action-review' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Expires', 'agent-action-review' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( array() === $actions ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'Nothing is waiting for review.', 'agent-action-review' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $actions as $action ) : ?>
					<tr>
						<th scope="row"><a href="<?php echo esc_url( self::url( array( 'action_id' => $action->get_action_id() ) ) ); ?>"><?php echo esc_html( $action->get_summary() ); ?></a></th>
						<td><?php echo esc_html( self::principal_name( (string) $action->get_creator() ) ); ?></td>
						<td><?php echo esc_html( (string) $action->get_agent() ); ?></td>
						<td><?php echo esc_html( self::relative_time( $action->get_created_at() ) ); ?></td>
						<td><?php echo esc_html( self::relative_time( $action->get_expires_at() ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Every decision, newest first, with the pending action outcome for previews.
	 *
	 * @return void
	 */
	private function render_activity(): void {
		$events  = $this->events->latest( 50 );
		$actions = $this->pending->get_many( array_column( $events, 'action_id' ) );
		?>
		<table class="wp-list-table widefat striped agent-review-activity">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Time', 'agent-action-review' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Ability', 'agent-action-review' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Policy', 'agent-action-review' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Outcome', 'agent-action-review' ); ?></th>
					<th scope="col"><?php esc_html_e( 'User', 'agent-action-review' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Auth source', 'agent-action-review' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( array() === $events ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No agent activity yet.', 'agent-action-review' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $events as $event ) : ?>
					<?php $action = $actions[ (string) $event['action_id'] ] ?? null; ?>
					<tr>
						<td><?php echo esc_html( self::local_time( (string) $event['created_at'] ) ); ?></td>
						<td><code><?php echo esc_html( (string) $event['ability'] ); ?></code></td>
						<td><span class="agent-review-badge is-<?php echo esc_attr( (string) $event['policy'] ); ?>"><?php echo esc_html( (string) $event['policy'] ); ?></span></td>
						<td>
							<?php echo esc_html( (string) $event['outcome'] ); ?>
							<?php if ( null !== $action ) : ?>
								&rarr; <a href="<?php echo esc_url( self::url( array( 'action_id' => $action->get_action_id() ) ) ); ?>"><?php echo esc_html( self::status_label( $action ) ); ?></a>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( self::principal_name( 'user:' . (int) $event['user_id'] ) ); ?></td>
						<td><?php echo esc_html( (string) $event['auth_source'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * One action: the diff, who asked, its state, and the buttons.
	 *
	 * @param string $action_id Action ID.
	 * @param string $outcome   Outcome code from the last resolution attempt.
	 * @return void
	 */
	private function render_review( string $action_id, string $outcome ): void {
		$action = $this->pending->get( $action_id, true );
		?>
		<p><a href="<?php echo esc_url( self::url() ); ?>">&larr; <?php esc_html_e( 'Agent Review', 'agent-action-review' ); ?></a></p>
		<?php
		if ( '' !== $outcome ) {
			$this->render_outcome_notice( $outcome, $action );
		}

		if ( null === $action ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Pending action not found.', 'agent-action-review' ) . '</p></div>';

			return;
		}

		$preview    = (array) $action->get_preview();
		$metadata   = $action->get_metadata();
		$creator_id = ReviewHandler::user_id_from_principal( (string) $action->get_creator() );
		$is_pending = WP_Agent_Pending_Action_Status::PENDING === $action->get_status();
		$is_self    = get_current_user_id() === $creator_id;
		$handler    = $this->handlers[ $action->get_kind() ] ?? null;
		$is_fresh   = null !== $handler && ReviewHandler::is_fresh( $handler, (array) $action->get_apply_input(), (string) ( $metadata['resource_fingerprint'] ?? '' ) );
		?>
		<h1><?php echo esc_html( $action->get_summary() ); ?> <span class="agent-review-badge is-<?php echo esc_attr( $action->get_status() ); ?>"><?php echo esc_html( self::status_label( $action ) ); ?></span></h1>
		<hr class="wp-header-end">

		<?php if ( isset( $preview['field'] ) ) : ?>
			<figure class="agent-review-change">
				<figcaption><?php esc_html_e( 'Proposed change', 'agent-action-review' ); ?> <code><?php echo esc_html( (string) $preview['field'] ); ?></code></figcaption>
				<pre class="agent-review-diff"><span class="is-removed">- <?php echo esc_html( (string) ( $preview['before'] ?? '' ) ); ?></span>
<span class="is-added">+ <?php echo esc_html( (string) ( $preview['after'] ?? '' ) ); ?></span></pre>
			</figure>
		<?php endif; ?>

		<table class="form-table agent-review-meta" role="presentation">
			<tr><th scope="row"><?php esc_html_e( 'Requested by', 'agent-action-review' ); ?></th><td><?php echo esc_html( self::principal_name( (string) $action->get_creator() ) ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Agent', 'agent-action-review' ); ?></th><td><?php echo esc_html( (string) $action->get_agent() ); ?> <span class="description"><?php esc_html_e( '(display label, not identity)', 'agent-action-review' ); ?></span></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Auth source', 'agent-action-review' ); ?></th><td><?php echo esc_html( (string) ( $metadata['auth_source'] ?? '' ) ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Created', 'agent-action-review' ); ?></th><td><?php echo esc_html( self::relative_time( $action->get_created_at() ) ); ?></td></tr>
			<?php if ( $is_pending ) : ?>
				<tr><th scope="row"><?php esc_html_e( 'Expires', 'agent-action-review' ); ?></th><td><?php echo esc_html( self::relative_time( $action->get_expires_at() ) ); ?></td></tr>
			<?php else : ?>
				<tr><th scope="row"><?php esc_html_e( 'Resolved by', 'agent-action-review' ); ?></th><td><?php echo esc_html( null === $action->get_resolver() ? '' : self::principal_name( (string) $action->get_resolver() ) ); ?></td></tr>
			<?php endif; ?>
			<tr><th scope="row"><?php esc_html_e( 'Action ID', 'agent-action-review' ); ?></th><td><code><?php echo esc_html( $action->get_action_id() ); ?></code></td></tr>
		</table>

		<?php if ( null !== $action->get_resolution_error() ) : ?>
			<div class="notice notice-warning inline"><p><?php echo esc_html( self::reason_label( (string) $action->get_resolution_error() ) ); ?></p></div>
		<?php endif; ?>

		<?php if ( $is_pending && ! $is_fresh ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'The post changed after this was proposed. Approving will be refused; ask the agent for a fresh proposal.', 'agent-action-review' ); ?></p></div>
		<?php endif; ?>

		<?php if ( $is_pending && $is_self ) : ?>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'Your account requested this, so another account must review it.', 'agent-action-review' ); ?></p></div>
		<?php elseif ( $is_pending ) : ?>
			<div class="agent-review-actions">
				<?php
				$this->action_button( ReviewHandler::REJECT, $action->get_action_id(), __( 'Reject', 'agent-action-review' ), 'secondary' );
				$this->action_button( ReviewHandler::APPROVE, $action->get_action_id(), __( 'Approve & run', 'agent-action-review' ), 'primary' );
				?>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * A one-button form posting to admin-post.php with a nonce bound to the action.
	 *
	 * @param string $admin_action Admin-post action.
	 * @param string $action_id    Action ID.
	 * @param string $label        Button label.
	 * @param string $style        primary or secondary.
	 * @return void
	 */
	private function action_button( string $admin_action, string $action_id, string $label, string $style ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( $admin_action ); ?>">
			<input type="hidden" name="action_id" value="<?php echo esc_attr( $action_id ); ?>">
			<?php wp_nonce_field( ReviewHandler::nonce_action( $action_id ) ); ?>
			<?php submit_button( $label, $style, '', false ); ?>
		</form>
		<?php
	}

	/**
	 * Notice for an outcome code. Success notices only show when the stored row agrees.
	 *
	 * @param string                       $outcome Outcome code.
	 * @param WP_Agent_Pending_Action|null $action  The action as stored now.
	 * @return void
	 */
	private function render_outcome_notice( string $outcome, ?WP_Agent_Pending_Action $action ): void {
		$status = null === $action ? '' : $action->get_status();

		if ( ( 'applied' === $outcome && WP_Agent_Pending_Action_Status::ACCEPTED !== $status ) || ( 'rejected' === $outcome && WP_Agent_Pending_Action_Status::REJECTED !== $status ) ) {
			return;
		}

		$messages = array(
			'applied'             => array( 'success', __( 'Approved and applied.', 'agent-action-review' ) ),
			'applied_unrecorded'  => array( 'warning', __( 'Approved and applied, but the result could not be saved. Check the post.', 'agent-action-review' ) ),
			'rejected'            => array( 'success', __( 'Rejected. Nothing was changed.', 'agent-action-review' ) ),
			'rejected_unrecorded' => array( 'warning', __( 'Rejected, but the details could not be saved.', 'agent-action-review' ) ),
			'unrecorded'          => array( 'error', __( 'Nothing was applied, and the reason could not be saved.', 'agent-action-review' ) ),
			'resource_changed'    => array( 'warning', self::reason_label( 'resource_changed' ) ),
			'permission_revoked'  => array( 'error', self::reason_label( 'permission_revoked' ) ),
			'policy_forbidden'    => array( 'error', self::reason_label( 'policy_forbidden' ) ),
			'inconsistent_state'  => array( 'error', self::reason_label( 'inconsistent_state' ) ),
			'apply_failed'        => array( 'error', self::reason_label( 'apply_failed' ) ),
			'already_resolved'    => array( 'warning', __( 'This proposal was already resolved or has expired.', 'agent-action-review' ) ),
			'self_review'         => array( 'error', __( 'An account cannot resolve its own proposal.', 'agent-action-review' ) ),
			'not_allowed'         => array( 'error', __( 'You are not allowed to resolve this proposal.', 'agent-action-review' ) ),
			'not_found'           => array( 'error', __( 'Pending action not found.', 'agent-action-review' ) ),
			'storage_error'       => array( 'error', __( 'The decision could not be saved. Try again.', 'agent-action-review' ) ),
		);

		if ( isset( $messages[ $outcome ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $messages[ $outcome ][0] ), esc_html( $messages[ $outcome ][1] ) );
		}
	}

	/**
	 * Sentence for a stored reason code.
	 *
	 * @param string $reason Reason code.
	 * @return string
	 */
	public static function reason_label( string $reason ): string {
		$labels = array(
			'resource_changed'   => __( 'The post changed after this was proposed, so nothing was applied. Ask the agent for a fresh proposal.', 'agent-action-review' ),
			'permission_revoked' => __( 'The requesting user is no longer allowed to do this, so nothing was applied.', 'agent-action-review' ),
			'policy_forbidden'   => __( 'The site policy now forbids this action, so nothing was applied.', 'agent-action-review' ),
			'inconsistent_state' => __( 'The stored proposal is inconsistent, so it was not applied.', 'agent-action-review' ),
			'apply_failed'       => __( 'WordPress refused the change. It was not retried.', 'agent-action-review' ),
		);

		return $labels[ $reason ] ?? $reason;
	}

	/**
	 * Human label for an action's state, including outcome unknown.
	 *
	 * @param WP_Agent_Pending_Action $action Pending action.
	 * @return string
	 */
	public static function status_label( WP_Agent_Pending_Action $action ): string {
		switch ( $action->get_status() ) {
			case WP_Agent_Pending_Action_Status::PENDING:
				return __( 'Pending', 'agent-action-review' );
			case WP_Agent_Pending_Action_Status::REJECTED:
				return __( 'Rejected', 'agent-action-review' );
			case WP_Agent_Pending_Action_Status::EXPIRED:
				return null === $action->get_resolution_error()
					? __( 'Expired', 'agent-action-review' )
					: __( 'Expired, not applied', 'agent-action-review' );
			case WP_Agent_Pending_Action_Status::ACCEPTED:
				if ( null !== $action->get_resolution_error() ) {
					return __( 'Accepted, not applied', 'agent-action-review' );
				}

				return null === $action->get_resolution_result()
					? __( 'Accepted, outcome unknown: check the post', 'agent-action-review' )
					: __( 'Accepted, applied', 'agent-action-review' );
			default:
				return $action->get_status();
		}
	}

	/**
	 * Admin URL of this page.
	 *
	 * @param array<string, string> $args Query arguments.
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Display name for a user:N principal.
	 *
	 * @param string $principal Principal.
	 * @return string
	 */
	private static function principal_name( string $principal ): string {
		$user = get_userdata( ReviewHandler::user_id_from_principal( $principal ) );

		return false === $user ? $principal : sprintf( '%s (%s)', $user->display_name, $user->user_login );
	}

	/**
	 * "in 14 minutes" or "3 minutes ago" for an ISO time.
	 *
	 * @param string|null $iso ISO 8601 time.
	 * @return string
	 */
	private static function relative_time( ?string $iso ): string {
		$timestamp = null === $iso ? false : strtotime( $iso );

		if ( false === $timestamp ) {
			return '';
		}

		/* translators: %s: human time difference. */
		$format = $timestamp > time() ? __( 'in %s', 'agent-action-review' ) : __( '%s ago', 'agent-action-review' );

		return sprintf( $format, human_time_diff( $timestamp, time() ) );
	}

	/**
	 * A stored UTC DATETIME in the site timezone.
	 *
	 * @param string $utc Stored value.
	 * @return string
	 */
	private static function local_time( string $utc ): string {
		$timestamp = strtotime( $utc . ' UTC' );

		return false === $timestamp ? '' : wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}
}
