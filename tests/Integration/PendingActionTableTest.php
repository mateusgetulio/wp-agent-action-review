<?php

namespace AgentActionReview\Tests\Integration;

use AgentActionReview\Pending\PendingActionTable;
use AgentsAPI\AI\Approvals\WP_Agent_Approval_Decision;
use AgentsAPI\AI\Approvals\WP_Agent_Pending_Action;
use AgentsAPI\AI\Approvals\WP_Agent_Pending_Action_Status;
use WP_UnitTestCase;

final class PendingActionTableTest extends WP_UnitTestCase {

	private PendingActionTable $table;

	public function set_up(): void {
		parent::set_up();
		$this->table = new PendingActionTable();
	}

	private function pending( array $overrides = array() ): WP_Agent_Pending_Action {
		$now = time();

		return WP_Agent_Pending_Action::from_array(
			array_merge(
				array(
					'action_id'   => wp_generate_uuid4(),
					'kind'        => 'agent-review/update-post-title',
					'summary'     => 'Change the title of post #4',
					'preview'     => array(
						'field'  => 'post_title',
						'before' => 'Summer Sale',
						'after'  => 'Fall Sale',
					),
					'apply_input' => array(
						'post_id' => 4,
						'title'   => 'Fall Sale',
					),
					'workspace'   => array(
						'workspace_type' => 'wordpress_site',
						'workspace_id'   => '1',
					),
					'agent'       => 'mcp:test',
					'creator'     => 'user:2',
					'created_at'  => gmdate( 'c', $now ),
					'expires_at'  => gmdate( 'c', $now + 900 ),
					'metadata'    => array( 'request_digest' => 'abc' ),
				),
				$overrides
			)
		);
	}

	public function test_store_and_get_round_trip_the_upstream_shape(): void {
		$action = $this->pending();

		$this->assertTrue( $this->table->store( $action ) );
		$this->assertSame( $action->to_array(), $this->table->get( $action->get_action_id() )->to_array() );
	}

	/**
	 * INV-6: a pending action can transition out of pending only once.
	 */
	public function test_claim_succeeds_only_once(): void {
		$action = $this->pending();
		$this->table->store( $action );

		$this->assertTrue( $this->table->claim( $action->get_action_id(), WP_Agent_Approval_Decision::accepted(), 'user:1', time() ) );
		$this->assertFalse( $this->table->claim( $action->get_action_id(), WP_Agent_Approval_Decision::accepted(), 'user:1', time() ) );
		$this->assertFalse( $this->table->claim( $action->get_action_id(), WP_Agent_Approval_Decision::rejected(), 'user:3', time() ) );

		$stored = $this->table->get( $action->get_action_id(), true );
		$this->assertSame( WP_Agent_Pending_Action_Status::ACCEPTED, $stored->get_status() );
		$this->assertSame( 'user:1', $stored->get_resolver() );
		$this->assertNotNull( $stored->get_resolved_at() );
	}

	public function test_resolved_actions_are_hidden_unless_requested(): void {
		$action = $this->pending();
		$this->table->store( $action );
		$this->table->claim( $action->get_action_id(), WP_Agent_Approval_Decision::rejected(), 'user:1', time() );

		$this->assertNull( $this->table->get( $action->get_action_id() ) );
		$this->assertSame( WP_Agent_Pending_Action_Status::REJECTED, $this->table->get( $action->get_action_id(), true )->get_status() );
	}

	public function test_expired_action_cannot_be_claimed(): void {
		$action = $this->pending(
			array(
				'created_at' => gmdate( 'c', time() - 1000 ),
				'expires_at' => gmdate( 'c', time() - 100 ),
			)
		);
		$this->table->store( $action );

		$this->assertFalse( $this->table->claim( $action->get_action_id(), WP_Agent_Approval_Decision::accepted(), 'user:1', time() ) );
		$this->assertSame( 1, $this->table->expire() );
		$this->assertSame( WP_Agent_Pending_Action_Status::EXPIRED, $this->table->get( $action->get_action_id(), true )->get_status() );
	}

	public function test_expire_if_pending_records_the_reason_once(): void {
		$action = $this->pending();
		$this->table->store( $action );

		$this->assertTrue( $this->table->expire_if_pending( $action->get_action_id(), 'resource_changed', time() ) );
		$this->assertFalse( $this->table->expire_if_pending( $action->get_action_id(), 'resource_changed', time() ) );
		$this->assertFalse( $this->table->claim( $action->get_action_id(), WP_Agent_Approval_Decision::accepted(), 'user:1', time() ) );

		$stored = $this->table->get( $action->get_action_id(), true );
		$this->assertSame( WP_Agent_Pending_Action_Status::EXPIRED, $stored->get_status() );
		$this->assertSame( 'resource_changed', $stored->get_resolution_error() );
	}

	public function test_record_resolution_writes_result_on_the_claimed_row(): void {
		$action = $this->pending();
		$this->table->store( $action );
		$this->table->claim( $action->get_action_id(), WP_Agent_Approval_Decision::accepted(), 'user:1', time() );

		$this->assertTrue(
			$this->table->record_resolution(
				$action->get_action_id(),
				WP_Agent_Approval_Decision::accepted(),
				'user:1',
				array( 'new_title' => 'Fall Sale' ),
				null,
				array( 'auth_source' => 'browser_session' )
			)
		);

		$stored = $this->table->get( $action->get_action_id(), true );
		$this->assertSame( array( 'new_title' => 'Fall Sale' ), $stored->get_resolution_result() );
		$this->assertSame( array( 'auth_source' => 'browser_session' ), $stored->get_resolution_metadata() );
	}

	public function test_record_resolution_never_moves_a_pending_row(): void {
		$action = $this->pending();
		$this->table->store( $action );

		$this->assertFalse( $this->table->record_resolution( $action->get_action_id(), WP_Agent_Approval_Decision::accepted(), 'user:1', array( 'x' => 1 ) ) );
		$this->assertSame( WP_Agent_Pending_Action_Status::PENDING, $this->table->get( $action->get_action_id() )->get_status() );
	}

	public function test_record_resolution_is_write_once_for_the_claiming_resolver_and_decision(): void {
		$action = $this->pending();
		$this->table->store( $action );
		$this->table->claim( $action->get_action_id(), WP_Agent_Approval_Decision::accepted(), 'user:1', time() );

		$this->assertFalse( $this->table->record_resolution( $action->get_action_id(), WP_Agent_Approval_Decision::rejected(), 'user:1', array( 'x' => 1 ) ), 'decision mismatch' );
		$this->assertFalse( $this->table->record_resolution( $action->get_action_id(), WP_Agent_Approval_Decision::accepted(), 'user:9', array( 'x' => 1 ) ), 'different resolver' );
		$this->assertTrue( $this->table->record_resolution( $action->get_action_id(), WP_Agent_Approval_Decision::accepted(), 'user:1', null, 'permission_revoked' ) );
		$this->assertFalse( $this->table->record_resolution( $action->get_action_id(), WP_Agent_Approval_Decision::accepted(), 'user:1', array( 'x' => 1 ) ), 'second write' );

		$stored = $this->table->get( $action->get_action_id(), true );
		$this->assertNull( $stored->get_resolution_result() );
		$this->assertSame( 'permission_revoked', $stored->get_resolution_error() );
	}

	public function test_corrupt_json_row_is_treated_as_missing(): void {
		global $wpdb;

		$good    = $this->pending();
		$corrupt = $this->pending();
		$this->table->store( $good );
		$this->table->store( $corrupt );
		$wpdb->update( PendingActionTable::table(), array( 'apply_input' => '{not json' ), array( 'action_id' => $corrupt->get_action_id() ) );

		$this->assertNull( $this->table->get( $corrupt->get_action_id(), true ) );
		$this->assertSame( array( $good->get_action_id() ), array_map( fn( $a ) => $a->get_action_id(), $this->table->list() ) );
	}

	public function test_invalid_timestamp_is_not_stored(): void {
		$this->assertFalse( $this->table->store( $this->pending( array( 'expires_at' => 'garbage' ) ) ) );
		$this->assertSame( 0, $this->table->expire( 'garbage' ) );
	}

	public function test_expired_but_unswept_pending_row_is_not_actionable(): void {
		$action = $this->pending(
			array(
				'created_at' => gmdate( 'c', time() - 1000 ),
				'expires_at' => gmdate( 'c', time() - 1 ),
			)
		);
		$this->table->store( $action );

		$this->assertNull( $this->table->get( $action->get_action_id() ) );
		$this->assertSame( WP_Agent_Pending_Action_Status::PENDING, $this->table->get( $action->get_action_id(), true )->get_status() );
	}

	public function test_expire_records_the_real_resolution_time(): void {
		$action = $this->pending(
			array(
				'created_at' => gmdate( 'c', time() - 5000 ),
				'expires_at' => gmdate( 'c', time() - 4000 ),
			)
		);
		$this->table->store( $action );

		$this->assertSame( 1, $this->table->expire( gmdate( 'c', time() - 3000 ) ) );
		$resolved_at = strtotime( $this->table->get( $action->get_action_id(), true )->get_resolved_at() );
		$this->assertGreaterThan( time() - 60, $resolved_at );
	}

	public function test_list_orders_newest_first_with_offset(): void {
		$old = $this->pending( array( 'created_at' => gmdate( 'c', time() - 100 ) ) );
		$new = $this->pending();
		$this->table->store( $old );
		$this->table->store( $new );

		$this->assertSame( $new->get_action_id(), $this->table->list( array( 'limit' => 1 ) )[0]->get_action_id() );
		$this->assertSame(
			$old->get_action_id(),
			$this->table->list(
				array(
					'limit'  => 1,
					'offset' => 1,
				)
			)[0]->get_action_id()
		);
	}

	public function test_accepted_without_result_or_error_stays_readable(): void {
		$action = $this->pending();
		$this->table->store( $action );
		$this->table->claim( $action->get_action_id(), WP_Agent_Approval_Decision::accepted(), 'user:1', time() );

		$stored = $this->table->get( $action->get_action_id(), true );
		$this->assertSame( WP_Agent_Pending_Action_Status::ACCEPTED, $stored->get_status() );
		$this->assertNull( $stored->get_resolution_result() );
		$this->assertNull( $stored->get_resolution_error() );
	}

	public function test_list_and_summary_filter_by_status_and_creator(): void {
		$first  = $this->pending();
		$second = $this->pending( array( 'creator' => 'user:3' ) );
		$this->table->store( $first );
		$this->table->store( $second );
		$this->table->claim( $second->get_action_id(), WP_Agent_Approval_Decision::rejected(), 'user:1', time() );

		$this->assertCount( 1, $this->table->list( array( 'status' => 'pending' ) ) );
		$this->assertCount( 1, $this->table->list( array( 'creator' => 'user:3' ) ) );
		$this->assertSame(
			array(
				'pending'  => 1,
				'rejected' => 1,
			),
			$this->table->summary()
		);
	}
}
