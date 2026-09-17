<?php
/**
 * Move a post to the trash.
 *
 * @package AgentActionReview
 */

namespace AgentActionReview\Actions;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Forbidden by default. If a site's policy ever allows it, it only trashes, never deletes permanently.
 */
final class PostTrash implements ActionHandler {

	/**
	 * Ability name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'agent-review/delete-post';
	}

	/**
	 * Destructive actions are forbidden by default.
	 *
	 * @return string
	 */
	public function default_policy(): string {
		return 'forbidden';
	}

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Delete post', 'agent-action-review' );
	}

	/**
	 * Description shown to agents.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'Move a post to the trash. Forbidden for agents by default.', 'agent-action-review' );
	}

	/**
	 * JSON schema of the input.
	 *
	 * @return array<string, mixed>
	 */
	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'post_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Advisory MCP annotations.
	 *
	 * @return array<string, bool>
	 */
	public function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => true,
			'idempotent'  => true,
		);
	}

	/**
	 * Only post_id, as a positive integer.
	 *
	 * @param array<string, mixed> $input Input from the ability call.
	 * @return array<string, mixed>|WP_Error
	 */
	public function normalize_input( array $input ) {
		$post_id = PostInput::normalize_post_id( $input );

		return is_wp_error( $post_id ) ? $post_id : array( 'post_id' => $post_id );
	}

	/**
	 * The user must be able to delete the post.
	 *
	 * @param int                  $user_id User to check.
	 * @param array<string, mixed> $input   Validated input.
	 * @return bool
	 */
	public function authorize( int $user_id, array $input ): bool {
		return user_can( $user_id, 'delete_post', PostInput::post_id( $input ) );
	}

	/**
	 * Summary of the trash.
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array{summary: string, preview: array<string, mixed>}|WP_Error
	 */
	public function describe( array $input ) {
		if ( ! EMPTY_TRASH_DAYS ) {
			return self::trash_disabled();
		}

		$post = PostInput::post( $input );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		return array(
			/* translators: %d: post ID. */
			'summary' => sprintf( __( 'Move post #%d to the trash', 'agent-action-review' ), $post->ID ),
			'preview' => array(
				'field'  => 'post_status',
				'before' => $post->post_status,
				'after'  => 'trash',
			),
		);
	}

	/**
	 * Status-sensitive fingerprint of the post.
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return string
	 */
	public function fingerprint( array $input ): string {
		return PostInput::fingerprint( $input );
	}

	/**
	 * Move the post to the trash.
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function apply( array $input ) {
		// With the trash disabled, wp_trash_post() deletes permanently; this action must only ever trash.
		if ( ! EMPTY_TRASH_DAYS ) {
			return self::trash_disabled();
		}

		$post = PostInput::post( $input );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( ! wp_trash_post( $post->ID ) ) {
			return new WP_Error( 'agent_review_trash_failed', __( 'The post could not be moved to the trash.', 'agent-action-review' ) );
		}

		return array(
			'post_id' => $post->ID,
			'status'  => 'trash',
		);
	}

	/**
	 * Error returned when the site has no trash.
	 *
	 * @return WP_Error
	 */
	private static function trash_disabled(): WP_Error {
		return new WP_Error( 'agent_review_trash_disabled', __( 'The trash is disabled on this site, so posts are never deleted through this action.', 'agent-action-review' ), array( 'status' => 409 ) );
	}
}
