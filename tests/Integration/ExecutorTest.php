<?php

namespace AgentActionReview\Tests\Integration;

use AgentActionReview\Actions\ActionHandler;
use AgentActionReview\Actions\PostRead;
use AgentActionReview\Actions\PostTitleUpdate;
use AgentActionReview\Actions\PostTrash;
use AgentActionReview\Audit\EventLog;
use AgentActionReview\Pending\Digest;
use AgentActionReview\Pending\PendingActionTable;
use AgentActionReview\Policy\Executor;
use AgentsAPI\AI\Approvals\WP_Agent_Pending_Action_Status;
use WP_Error;
use WP_UnitTestCase;

final class ExecutorTest extends WP_UnitTestCase {

	private Executor $executor;

	private PendingActionTable $pending;

	private EventLog $events;

	private int $editor;

	private int $post_id;

	public function set_up(): void {
		parent::set_up();

		$this->pending  = new PendingActionTable();
		$this->events   = new EventLog();
		$this->executor = new Executor( $this->pending, $this->events );
		$this->editor   = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->post_id  = self::factory()->post->create(
			array(
				'post_title'  => 'Summer Sale',
				'post_status' => 'publish',
			)
		);

		wp_set_current_user( $this->editor );
	}

	private function latest_event(): array {
		return $this->events->latest( 1 )[0];
	}

	public function test_direct_read_runs_immediately_and_is_logged(): void {
		$result = $this->executor->run( new PostRead(), array( 'post_id' => $this->post_id ) );

		$this->assertSame( 'Summer Sale', $result['title'] );
		$this->assertSame( 'direct', $this->latest_event()['policy'] );
		$this->assertSame( EventLog::EXECUTED, $this->latest_event()['outcome'] );
	}

	/**
	 * INV-1: a preview action never mutates WordPress before human acceptance.
	 */
	public function test_preview_update_stores_a_pending_action_and_changes_nothing(): void {
		$modified = get_post_field( 'post_modified_gmt', $this->post_id );

		$envelope = $this->executor->run(
			new PostTitleUpdate(),
			array(
				'post_id' => $this->post_id,
				'title'   => 'Fall Sale',
			)
		);

		$this->assertSame( 'approval_required', $envelope['type'] );
		$this->assertSame( 'Summer Sale', get_post_field( 'post_title', $this->post_id ) );
		$this->assertSame( $modified, get_post_field( 'post_modified_gmt', $this->post_id ) );

		$action = $this->pending->get( $envelope['payload']['action_id'] );
		$this->assertSame( WP_Agent_Pending_Action_Status::PENDING, $action->get_status() );
		$this->assertSame( 'user:' . $this->editor, $action->get_creator() );
		$this->assertSame(
			array(
				'field'  => 'post_title',
				'before' => 'Summer Sale',
				'after'  => 'Fall Sale',
			),
			$action->get_preview()
		);
		$this->assertSame( EventLog::PROPOSED, $this->latest_event()['outcome'] );
		$this->assertSame( $action->get_action_id(), $this->latest_event()['action_id'] );
	}

	/**
	 * INV-2 support: the stored digest matches the reloaded row.
	 */
	public function test_stored_digest_matches_the_reloaded_row(): void {
		$envelope = $this->executor->run(
			new PostTitleUpdate(),
			array(
				'post_id' => $this->post_id,
				'title'   => 'Fall Sale',
			)
		);
		$action   = $this->pending->get( $envelope['payload']['action_id'] );
		$metadata = $action->get_metadata();

		$this->assertTrue( Digest::matches( $metadata['request_digest'], $action->get_kind(), $action->get_apply_input(), $action->get_creator(), $action->get_workspace()->to_array() ) );
		$this->assertNotEmpty( $metadata['resource_fingerprint'] );
		$this->assertSame( 'user', $metadata['auth_source'] );
	}

	/**
	 * INV-8: forbidden actions never invoke their mutation handler.
	 */
	public function test_forbidden_delete_never_calls_the_handler(): void {
		$spy = new class() implements ActionHandler {
			public int $calls = 0;

			public function name(): string {
				return 'agent-review/spy-delete';
			}

			public function default_policy(): string {
				return 'forbidden';
			}

			public function label(): string {
				return 'Spy';
			}

			public function description(): string {
				return 'Spy';
			}

			public function input_schema(): array {
				return array();
			}

			public function normalize_input( array $input ) {
				++$this->calls;
				return $input;
			}

			public function annotations(): array {
				return array();
			}

			public function authorize( int $user_id, array $input ): bool {
				++$this->calls;
				return true;
			}

			public function describe( array $input ) {
				++$this->calls;
				return new WP_Error( 'spy', 'spy' );
			}

			public function fingerprint( array $input ): string {
				++$this->calls;
				return '';
			}

			public function apply( array $input ) {
				++$this->calls;
				return array();
			}
		};

		$result = $this->executor->run( $spy, array( 'post_id' => $this->post_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'agent_review_forbidden', $result->get_error_code() );
		$this->assertSame( 0, $spy->calls );
		$this->assertSame( EventLog::FORBIDDEN, $this->latest_event()['outcome'] );
	}

	public function test_real_delete_is_forbidden_and_the_post_survives(): void {
		$result = $this->executor->run( new PostTrash(), array( 'post_id' => $this->post_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'publish', get_post_status( $this->post_id ) );
	}

	public function test_user_without_capability_is_denied_and_nothing_is_stored(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = $this->executor->run(
			new PostTitleUpdate(),
			array(
				'post_id' => $this->post_id,
				'title'   => 'Fall Sale',
			)
		);

		$this->assertSame( 'agent_review_denied', $result->get_error_code() );
		$this->assertSame( array(), $this->pending->summary() );
		$this->assertSame( EventLog::DENIED, $this->latest_event()['outcome'] );
	}

	public function test_executor_enforces_whatever_the_upstream_resolver_returns(): void {
		$make_direct = static fn( string $policy, string $tool ): string => 'agent-review/update-post-title' === $tool ? 'direct' : $policy;
		add_filter( 'agents_api_tool_action_policy', $make_direct, 10, 2 );

		$result = $this->executor->run(
			new PostTitleUpdate(),
			array(
				'post_id' => $this->post_id,
				'title'   => 'Fall Sale',
			)
		);

		remove_filter( 'agents_api_tool_action_policy', $make_direct, 10 );

		$this->assertSame( 'Fall Sale', $result['new_title'] );
		$this->assertSame( 'Fall Sale', get_post_field( 'post_title', $this->post_id ) );
	}

	public function test_post_the_editor_cannot_edit_is_denied_without_storing_a_proposal(): void {
		$result = $this->executor->run(
			new PostTitleUpdate(),
			array(
				'post_id' => 999999,
				'title'   => 'Fall Sale',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( array(), $this->pending->summary() );
	}

	/**
	 * @dataProvider unreviewable_posts
	 */
	public function test_non_post_targets_fail_without_storing_a_proposal( array $args ): void {
		$target = self::factory()->post->create( $args );

		$result = $this->executor->run(
			new PostTitleUpdate(),
			array(
				'post_id' => $target,
				'title'   => 'Fall Sale',
			)
		);

		$this->assertSame( 'agent_review_post_not_found', $result->get_error_code() );
		$this->assertSame( array(), $this->pending->summary() );
		$this->assertSame( EventLog::FAILED, $this->latest_event()['outcome'] );
	}

	public static function unreviewable_posts(): array {
		return array(
			'page'       => array(
				array(
					'post_type'   => 'page',
					'post_status' => 'publish',
				),
			),
			'trashed'    => array( array( 'post_status' => 'trash' ) ),
			'auto-draft' => array( array( 'post_status' => 'auto-draft' ) ),
		);
	}

	/**
	 * @dataProvider invalid_titles
	 */
	public function test_invalid_titles_are_refused_before_storing( $title ): void {
		$result = $this->executor->run(
			new PostTitleUpdate(),
			array(
				'post_id' => $this->post_id,
				'title'   => $title,
			)
		);

		$this->assertSame( 'agent_review_invalid_title', $result->get_error_code() );
		$this->assertSame( array(), $this->pending->summary() );
	}

	public static function invalid_titles(): array {
		return array(
			'only tags'  => array( '<b></b>' ),
			'whitespace' => array( '   ' ),
			'too long'   => array( str_repeat( 'a', 201 ) ),
			'not string' => array( array( 'Fall Sale' ) ),
		);
	}

	public function test_stored_title_is_the_cleaned_title_and_the_preview_matches_it(): void {
		$envelope = $this->executor->run(
			new PostTitleUpdate(),
			array(
				'post_id' => (string) $this->post_id,
				'title'   => '  <em>Fall</em> \\o/ 20%Cashback  ',
			)
		);
		$action   = $this->pending->get( $envelope['payload']['action_id'] );

		$this->assertSame( 'Fall \\o/ 20%Cashback', $action->get_apply_input()['title'] );
		$this->assertSame( $this->post_id, $action->get_apply_input()['post_id'] );
		$this->assertSame( $action->get_apply_input()['title'], $action->get_preview()['after'] );
	}

	public function test_applied_title_keeps_backslashes_and_percent_signs_byte_for_byte(): void {
		$make_direct = static fn( string $policy, string $tool ): string => 'agent-review/update-post-title' === $tool ? 'direct' : $policy;
		add_filter( 'agents_api_tool_action_policy', $make_direct, 10, 2 );

		$this->executor->run(
			new PostTitleUpdate(),
			array(
				'post_id' => $this->post_id,
				'title'   => 'Fall \\o/ 20%Cashback',
			)
		);

		remove_filter( 'agents_api_tool_action_policy', $make_direct, 10 );

		$this->assertSame( 'Fall \\o/ 20%Cashback', get_post_field( 'post_title', $this->post_id, 'raw' ) );
	}

	public function test_a_filter_can_make_a_preview_forbidden_and_nothing_is_stored(): void {
		$forbid = static fn( string $policy, string $tool ): string => 'agent-review/update-post-title' === $tool ? 'forbidden' : $policy;
		add_filter( 'agents_api_tool_action_policy', $forbid, 10, 2 );

		$result = $this->executor->run(
			new PostTitleUpdate(),
			array(
				'post_id' => $this->post_id,
				'title'   => 'Fall Sale',
			)
		);

		remove_filter( 'agents_api_tool_action_policy', $forbid, 10 );

		$this->assertSame( 'agent_review_forbidden', $result->get_error_code() );
		$this->assertSame( array(), $this->pending->summary() );
		$this->assertSame( 'Summer Sale', get_post_field( 'post_title', $this->post_id ) );
	}

	/**
	 * INV-1 with a spy: a previewed action never reaches apply().
	 */
	public function test_preview_never_calls_apply(): void {
		$spy = new class() implements ActionHandler {
			public int $applies = 0;

			public function name(): string {
				return 'agent-review/spy-preview';
			}

			public function default_policy(): string {
				return 'preview';
			}

			public function label(): string {
				return 'Spy';
			}

			public function description(): string {
				return 'Spy';
			}

			public function input_schema(): array {
				return array();
			}

			public function annotations(): array {
				return array();
			}

			public function normalize_input( array $input ) {
				return array( 'post_id' => (int) $input['post_id'] );
			}

			public function authorize( int $user_id, array $input ): bool {
				return true;
			}

			public function describe( array $input ) {
				return array(
					'summary' => 'Spy',
					'preview' => array( 'x' => 1 ),
				);
			}

			public function fingerprint( array $input ): string {
				return 'f';
			}

			public function apply( array $input ) {
				++$this->applies;
				return array();
			}
		};

		$result = $this->executor->run( $spy, array( 'post_id' => $this->post_id ) );

		$this->assertSame( 'approval_required', $result['type'] );
		$this->assertSame( 0, $spy->applies );
	}

	public function test_delete_ability_execution_is_refused_and_the_post_survives(): void {
		$result = wp_get_ability( 'agent-review/delete-post' )->execute( array( 'post_id' => $this->post_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'publish', get_post_status( $this->post_id ) );
	}

	public function test_if_a_site_allows_delete_it_only_trashes(): void {
		$allow = static fn( string $policy, string $tool ): string => 'agent-review/delete-post' === $tool ? 'direct' : $policy;
		add_filter( 'agents_api_tool_action_policy', $allow, 10, 2 );

		$result = $this->executor->run( new PostTrash(), array( 'post_id' => $this->post_id ) );

		remove_filter( 'agents_api_tool_action_policy', $allow, 10 );

		$this->assertSame( 'trash', $result['status'] );
		$this->assertSame( 'trash', get_post_status( $this->post_id ) );
	}

	public function test_extra_properties_are_rejected_by_the_ability_schema(): void {
		$result = wp_get_ability( 'agent-review/update-post-title' )->execute(
			array(
				'post_id'     => $this->post_id,
				'title'       => 'Fall Sale',
				'post_status' => 'draft',
			)
		);

		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
		$this->assertSame( array(), $this->pending->summary() );
	}

	public function test_application_password_requests_are_labelled_as_metadata(): void {
		$GLOBALS['wp_rest_application_password_uuid'] = wp_generate_uuid4();

		$envelope = $this->executor->run(
			new PostTitleUpdate(),
			array(
				'post_id' => $this->post_id,
				'title'   => 'Fall Sale',
			)
		);

		unset( $GLOBALS['wp_rest_application_password_uuid'] );

		$this->assertSame( 'application_password', $this->pending->get( $envelope['payload']['action_id'] )->get_metadata()['auth_source'] );
		$this->assertSame( 'application_password', $this->latest_event()['auth_source'] );
	}

	public function test_abilities_are_registered_and_public_to_mcp(): void {
		foreach ( array( 'agent-review/read-post', 'agent-review/update-post-title', 'agent-review/delete-post' ) as $name ) {
			$ability = wp_get_ability( $name );

			$this->assertNotNull( $ability, $name );
			$this->assertTrue( $ability->get_meta()['mcp']['public'], $name );
		}
	}

	public function test_ability_execution_goes_through_the_executor(): void {
		$envelope = wp_get_ability( 'agent-review/update-post-title' )->execute(
			array(
				'post_id' => $this->post_id,
				'title'   => 'Fall Sale',
			)
		);

		$this->assertSame( 'approval_required', $envelope['type'] );
		$this->assertSame( 'Summer Sale', get_post_field( 'post_title', $this->post_id ) );
	}
}
