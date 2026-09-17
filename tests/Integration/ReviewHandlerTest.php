<?php

namespace AgentActionReview\Tests\Integration;

use AgentActionReview\Actions\PostTitleUpdate;
use AgentActionReview\Admin\AdminPage;
use AgentActionReview\Audit\EventLog;
use AgentActionReview\Pending\PendingActionTable;
use AgentActionReview\Plugin;
use AgentActionReview\Policy\Executor;
use AgentActionReview\Review\ReviewHandler;
use AgentsAPI\AI\Approvals\WP_Agent_Action_Policy;
use AgentsAPI\AI\Approvals\WP_Agent_Approval_Decision;
use AgentsAPI\AI\Approvals\WP_Agent_Pending_Action_Status;
use RuntimeException;
use WPDieException;
use WP_UnitTestCase;

final class ReviewHandlerTest extends WP_UnitTestCase {

	private PendingActionTable $pending;

	private ReviewHandler $review;

	private int $admin;

	private int $editor;

	private int $post_id;

	public function set_up(): void {
		parent::set_up();

		$this->pending = new PendingActionTable();
		$this->review  = new ReviewHandler( $this->pending, new Executor( $this->pending, new EventLog() ), Plugin::handlers() );
		$this->admin   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->editor  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->post_id = self::factory()->post->create(
			array(
				'post_title'  => 'Summer Sale',
				'post_status' => 'publish',
			)
		);
	}

	private function propose( string $title = 'Fall Sale', ?int $creator = null ): string {
		wp_set_current_user( $creator ?? $this->editor );

		$envelope = ( new Executor( $this->pending, new EventLog() ) )->run(
			new PostTitleUpdate(),
			array(
				'post_id' => $this->post_id,
				'title'   => $title,
			)
		);

		wp_set_current_user( $this->admin );

		return $envelope['payload']['action_id'];
	}

	private function title(): string {
		clean_post_cache( $this->post_id );

		return get_post_field( 'post_title', $this->post_id, 'raw' );
	}

	private function stored( string $action_id ) {
		return $this->pending->get( $action_id, true );
	}

	public function test_approve_applies_the_stored_title_and_records_the_result(): void {
		$action_id = $this->propose();

		$this->assertSame( 'applied', $this->review->approve( $action_id, $this->admin ) );
		$this->assertSame( 'Fall Sale', $this->title() );

		$action = $this->stored( $action_id );
		$this->assertSame( WP_Agent_Pending_Action_Status::ACCEPTED, $action->get_status() );
		$this->assertSame( 'user:' . $this->admin, $action->get_resolver() );
		$this->assertSame( 'Fall Sale', $action->get_resolution_result()['new_title'] );
		$this->assertSame( 'browser_session', $action->get_resolution_metadata()['auth_source'] );
		$this->assertSame( 'Accepted, applied', AdminPage::status_label( $action ) );
	}

	public function test_reject_changes_nothing(): void {
		$action_id = $this->propose();

		$this->assertSame( 'rejected', $this->review->reject( $action_id, $this->admin ) );
		$this->assertSame( 'Summer Sale', $this->title() );
		$this->assertSame( WP_Agent_Pending_Action_Status::REJECTED, $this->stored( $action_id )->get_status() );
	}

	/**
	 * INV-2: approval is valid only for the exact stored action and input.
	 */
	public function test_edited_stored_input_is_refused_as_inconsistent(): void {
		global $wpdb;

		$action_id = $this->propose();
		$wpdb->update(
			PendingActionTable::table(),
			array(
				'apply_input' => wp_json_encode(
					array(
						'post_id' => $this->post_id,
						'title'   => 'Buy crypto',
					)
				),
			),
			array( 'action_id' => $action_id )
		);

		$this->assertSame( 'inconsistent_state', $this->review->approve( $action_id, $this->admin ) );
		$this->assertSame( 'Summer Sale', $this->title() );
		$this->assertSame( 'inconsistent_state', $this->stored( $action_id )->get_resolution_error() );
	}

	/**
	 * INV-3: the requesting account cannot resolve its own proposal.
	 */
	public function test_creator_cannot_resolve_their_own_proposal(): void {
		$action_id = $this->propose( 'Fall Sale', $this->admin );

		$this->assertSame( 'self_review', $this->review->approve( $action_id, $this->admin ) );
		$this->assertSame( 'self_review', $this->review->reject( $action_id, $this->admin ) );
		$this->assertSame( WP_Agent_Pending_Action_Status::PENDING, $this->stored( $action_id )->get_status() );
		$this->assertSame( 'Summer Sale', $this->title() );
	}

	/**
	 * INV-3: reviewer authorization is required.
	 */
	public function test_reviewer_without_manage_options_is_refused(): void {
		$action_id = $this->propose();
		$other     = self::factory()->user->create( array( 'role' => 'editor' ) );

		$this->assertSame( 'not_allowed', $this->review->approve( $action_id, $other ) );
		$this->assertSame( WP_Agent_Pending_Action_Status::PENDING, $this->stored( $action_id )->get_status() );
	}

	/**
	 * INV-3: the request handler requires a valid nonce before anything happens.
	 */
	public function test_approve_request_without_a_valid_nonce_dies(): void {
		$action_id = $this->propose();

		$_POST['action_id']   = $action_id;
		$_REQUEST['_wpnonce'] = 'forged';

		$this->assertHandlerDies( 'handle_approve' );
		$this->assertSame( WP_Agent_Pending_Action_Status::PENDING, $this->stored( $action_id )->get_status() );
		$this->assertSame( 'Summer Sale', $this->title() );
	}

	public function test_reject_request_without_a_valid_nonce_dies(): void {
		$action_id = $this->propose();

		$_POST['action_id']   = $action_id;
		$_REQUEST['_wpnonce'] = 'forged';

		$this->assertHandlerDies( 'handle_reject' );
		$this->assertSame( WP_Agent_Pending_Action_Status::PENDING, $this->stored( $action_id )->get_status() );
	}

	/**
	 * INV-2: a nonce is bound to one action ID.
	 */
	public function test_nonce_for_another_action_dies(): void {
		$first  = $this->propose();
		$second = $this->propose( 'Winter Sale' );

		$_POST['action_id']   = $second;
		$_REQUEST['_wpnonce'] = wp_create_nonce( ReviewHandler::nonce_action( $first ) );

		$this->assertHandlerDies( 'handle_approve' );
		$this->assertSame( WP_Agent_Pending_Action_Status::PENDING, $this->stored( $second )->get_status() );
		$this->assertSame( 'Summer Sale', $this->title() );
	}

	/**
	 * Positive control for the nonce tests: the same request with the right nonce applies.
	 */
	public function test_approve_request_with_a_valid_nonce_applies_and_redirects(): void {
		$action_id = $this->propose();

		$_POST['action_id']   = $action_id;
		$_REQUEST['_wpnonce'] = wp_create_nonce( ReviewHandler::nonce_action( $action_id ) );

		$location = $this->capture_redirect( 'handle_approve' );

		$this->assertStringContainsString( 'agent_action_review_outcome=applied', $location );
		$this->assertSame( 'Fall Sale', $this->title() );
	}

	/**
	 * INV-3: an application password does not authenticate a wp-admin request.
	 */
	public function test_application_password_does_not_authenticate_outside_the_api(): void {
		$created = \WP_Application_Passwords::create_new_application_password( $this->admin, array( 'name' => 'agent' ) );
		$user    = get_userdata( $this->admin );

		wp_set_current_user( 0 );
		$_SERVER['PHP_AUTH_USER'] = $user->user_login;
		$_SERVER['PHP_AUTH_PW']   = $created[0];

		try {
			$this->assertFalse( apply_filters( 'determine_current_user', false ) );
		} finally {
			unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] );
		}
	}

	/**
	 * Runs a request handler and asserts it died before doing anything.
	 *
	 * @param string $method handle_approve or handle_reject.
	 */
	private function assertHandlerDies( string $method ): void {
		try {
			$this->review->{$method}();
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $exception ) {
			$this->assertNotEmpty( $exception->getMessage() );
		} finally {
			unset( $_POST['action_id'], $_REQUEST['_wpnonce'] );
		}
	}

	/**
	 * Runs a request handler and returns the redirect location instead of exiting.
	 *
	 * @param string $method handle_approve or handle_reject.
	 */
	private function capture_redirect( string $method ): string {
		$stop = static function ( $location ) {
			throw new RuntimeException( $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test control flow, never output.
		};

		add_filter( 'wp_redirect', $stop );

		try {
			$this->review->{$method}();
			$this->fail( 'Expected a redirect.' );
		} catch ( RuntimeException $exception ) {
			return $exception->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $stop );
			unset( $_POST['action_id'], $_REQUEST['_wpnonce'] );
		}
	}

	/**
	 * INV-4: authorization for the creator is checked again immediately before apply.
	 */
	public function test_creator_losing_permission_after_proposal_blocks_apply(): void {
		$action_id = $this->propose();
		( new \WP_User( $this->editor ) )->set_role( 'subscriber' );

		$this->assertSame( 'permission_revoked', $this->review->approve( $action_id, $this->admin ) );
		$this->assertSame( 'Summer Sale', $this->title() );

		$action = $this->stored( $action_id );
		$this->assertSame( WP_Agent_Pending_Action_Status::ACCEPTED, $action->get_status() );
		$this->assertSame( 'permission_revoked', $action->get_resolution_error() );
		$this->assertSame( 'Accepted, not applied', AdminPage::status_label( $action ) );
	}

	/**
	 * INV-5: a proposal that is stale at review time is refused and expires.
	 */
	public function test_post_edited_after_proposal_is_refused_and_expires(): void {
		$action_id = $this->propose();
		wp_update_post(
			array(
				'ID'         => $this->post_id,
				'post_title' => 'Winter Sale',
			)
		);

		$this->assertSame( 'resource_changed', $this->review->approve( $action_id, $this->admin ) );
		$this->assertSame( 'Winter Sale', $this->title() );

		$action = $this->stored( $action_id );
		$this->assertSame( WP_Agent_Pending_Action_Status::EXPIRED, $action->get_status() );
		$this->assertSame( 'resource_changed', $action->get_resolution_error() );
	}

	/**
	 * INV-5: a change landing between the claim and the apply is caught by the second check.
	 */
	public function test_post_changed_after_the_claim_is_not_applied(): void {
		global $wpdb;

		$action_id = $this->propose();
		$post_id   = $this->post_id;
		$editor    = $this->editor;
		$changed   = false;

		$change_during_recheck = static function ( $caps, $cap, $user_id ) use ( &$changed, $post_id, $editor, $wpdb ) {
			if ( ! $changed && 'edit_post' === $cap && $editor === $user_id ) {
				$changed = true;
				$wpdb->update( $wpdb->posts, array( 'post_title' => 'Concurrent edit' ), array( 'ID' => $post_id ) );
				clean_post_cache( $post_id );
			}

			return $caps;
		};

		add_filter( 'map_meta_cap', $change_during_recheck, 10, 3 );
		$outcome = $this->review->approve( $action_id, $this->admin );
		remove_filter( 'map_meta_cap', $change_during_recheck, 10 );

		$this->assertTrue( $changed );
		$this->assertSame( 'resource_changed', $outcome );
		$this->assertSame( 'Concurrent edit', $this->title() );
		$this->assertSame( WP_Agent_Pending_Action_Status::ACCEPTED, $this->stored( $action_id )->get_status() );
		$this->assertSame( 'resource_changed', $this->stored( $action_id )->get_resolution_error() );
	}

	/**
	 * INV-6: a pending action can transition out of pending only once.
	 */
	public function test_second_approval_or_rejection_is_refused(): void {
		$action_id = $this->propose();
		$second    = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->assertSame( 'applied', $this->review->approve( $action_id, $this->admin ) );
		$this->assertSame( 'already_resolved', $this->review->approve( $action_id, $second ) );
		$this->assertSame( 'already_resolved', $this->review->reject( $action_id, $second ) );
		$this->assertSame( 'user:' . $this->admin, $this->stored( $action_id )->get_resolver() );
	}

	/**
	 * INV-7: accepted with no result is shown as outcome unknown and never retried.
	 */
	public function test_accepted_without_result_is_outcome_unknown_and_not_retried(): void {
		$action_id = $this->propose();
		$this->pending->claim( $action_id, WP_Agent_Approval_Decision::accepted(), 'user:' . $this->admin, time() );

		$this->assertSame( 'Accepted, outcome unknown: check the post', AdminPage::status_label( $this->stored( $action_id ) ) );
		$this->assertSame( 'already_resolved', $this->review->approve( $action_id, $this->admin ) );
		$this->assertSame( 'Summer Sale', $this->title() );
	}

	public function test_expired_proposal_cannot_be_approved(): void {
		global $wpdb;

		$action_id = $this->propose();
		$wpdb->update( PendingActionTable::table(), array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ), array( 'action_id' => $action_id ) );

		$this->assertSame( 'already_resolved', $this->review->approve( $action_id, $this->admin ) );
		$this->assertSame( WP_Agent_Pending_Action_Status::EXPIRED, $this->stored( $action_id )->get_status() );
		$this->assertSame( 'Summer Sale', $this->title() );
	}

	/**
	 * INV-3: the reviewer also needs edit_post on the target.
	 */
	public function test_reviewer_without_edit_post_is_refused(): void {
		$action_id = $this->propose();
		$post_id   = $this->post_id;
		$admin     = $this->admin;
		$deny      = static function ( $caps, $cap, $user_id, $args ) use ( $post_id, $admin ) {
			return 'edit_post' === $cap && $admin === $user_id && (int) ( $args[0] ?? 0 ) === $post_id ? array( 'do_not_allow' ) : $caps;
		};

		add_filter( 'map_meta_cap', $deny, 10, 4 );
		$approve = $this->review->approve( $action_id, $this->admin );
		$reject  = $this->review->reject( $action_id, $this->admin );
		remove_filter( 'map_meta_cap', $deny, 10 );

		$this->assertSame( 'not_allowed', $approve );
		$this->assertSame( 'not_allowed', $reject );
		$this->assertSame( WP_Agent_Pending_Action_Status::PENDING, $this->stored( $action_id )->get_status() );
		$this->assertSame( 'Summer Sale', $this->title() );
	}

	public function test_reject_needs_manage_options_and_a_live_proposal(): void {
		global $wpdb;

		$action_id = $this->propose();
		$other     = self::factory()->user->create( array( 'role' => 'editor' ) );

		$this->assertSame( 'not_allowed', $this->review->reject( $action_id, $other ) );

		$wpdb->update( PendingActionTable::table(), array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ), array( 'action_id' => $action_id ) );

		$this->assertSame( 'already_resolved', $this->review->reject( $action_id, $this->admin ) );
		$this->assertSame( WP_Agent_Pending_Action_Status::EXPIRED, $this->stored( $action_id )->get_status() );
	}

	public function test_wordpress_refusing_the_update_is_recorded_as_apply_failed(): void {
		$action_id = $this->propose();

		add_filter( 'wp_insert_post_empty_content', '__return_true' );
		$outcome = $this->review->approve( $action_id, $this->admin );
		remove_filter( 'wp_insert_post_empty_content', '__return_true' );

		$this->assertSame( 'apply_failed', $outcome );
		$this->assertSame( 'Summer Sale', $this->title() );

		$action = $this->stored( $action_id );
		$this->assertSame( WP_Agent_Pending_Action_Status::ACCEPTED, $action->get_status() );
		$this->assertSame( 'apply_failed', $action->get_resolution_error() );
		$this->assertSame( 'empty_content', $action->get_resolution_metadata()['wp_error_code'] );
	}

	/**
	 * INV-2: the creator principal is part of the digest.
	 */
	public function test_tampered_creator_is_refused_as_inconsistent(): void {
		global $wpdb;

		$action_id = $this->propose();
		$other     = self::factory()->user->create( array( 'role' => 'editor' ) );
		$wpdb->update( PendingActionTable::table(), array( 'creator' => 'user:' . $other ), array( 'action_id' => $action_id ) );

		$this->assertSame( 'inconsistent_state', $this->review->approve( $action_id, $this->admin ) );
		$this->assertSame( 'Summer Sale', $this->title() );
	}

	public function test_policy_changed_to_forbidden_after_proposal_is_not_applied(): void {
		$action_id = $this->propose();
		$forbid    = static fn() => WP_Agent_Action_Policy::FORBIDDEN;

		add_filter( 'agents_api_tool_action_policy', $forbid );
		$outcome = $this->review->approve( $action_id, $this->admin );
		remove_filter( 'agents_api_tool_action_policy', $forbid );

		$this->assertSame( 'policy_forbidden', $outcome );
		$this->assertSame( 'Summer Sale', $this->title() );
		$this->assertSame( WP_Agent_Pending_Action_Status::EXPIRED, $this->stored( $action_id )->get_status() );
		$this->assertSame( 'policy_forbidden', $this->stored( $action_id )->get_resolution_error() );
	}

	public function test_expiring_an_action_someone_else_just_resolved_reports_already_resolved(): void {
		$action_id = $this->propose();
		$second    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$pending   = $this->pending;
		$raced     = false;

		wp_update_post(
			array(
				'ID'         => $this->post_id,
				'post_title' => 'Winter Sale',
			)
		);

		$race = static function ( $policy ) use ( &$raced, $pending, $action_id, $second ) {
			if ( ! $raced ) {
				$raced = true;
				$pending->claim( $action_id, WP_Agent_Approval_Decision::rejected(), 'user:' . $second, time() );
			}

			return $policy;
		};

		add_filter( 'agents_api_tool_action_policy', $race );
		$outcome = $this->review->approve( $action_id, $this->admin );
		remove_filter( 'agents_api_tool_action_policy', $race );

		$this->assertTrue( $raced );
		$this->assertSame( 'already_resolved', $outcome );
		$this->assertSame( WP_Agent_Pending_Action_Status::REJECTED, $this->stored( $action_id )->get_status() );
	}

	public function test_deleted_post_can_be_rejected_but_not_approved(): void {
		$approve_id = $this->propose();
		$reject_id  = $this->propose( 'Winter Sale' );

		wp_delete_post( $this->post_id, true );

		$this->assertSame( 'rejected', $this->review->reject( $reject_id, $this->admin ) );
		$this->assertSame( 'resource_changed', $this->review->approve( $approve_id, $this->admin ) );
		$this->assertSame( WP_Agent_Pending_Action_Status::EXPIRED, $this->stored( $approve_id )->get_status() );
	}

	public function test_unknown_action_is_not_found(): void {
		$this->assertSame( 'not_found', $this->review->approve( wp_generate_uuid4(), $this->admin ) );
	}

	public function test_backslashes_survive_the_approval_round_trip(): void {
		$action_id = $this->propose( 'Fall \\o/ 20%Cashback' );

		$this->assertSame( 'applied', $this->review->approve( $action_id, $this->admin ) );
		$this->assertSame( 'Fall \\o/ 20%Cashback', $this->title() );
	}
}
