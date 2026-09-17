<?php
/**
 * Read a post.
 *
 * @package AgentActionReview
 */

namespace AgentActionReview\Actions;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Returns a post's ID, title, status and modified time.
 */
final class PostRead implements ActionHandler {

	/**
	 * Ability name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'agent-review/read-post';
	}

	/**
	 * Reads are direct by default.
	 *
	 * @return string
	 */
	public function default_policy(): string {
		return 'direct';
	}

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Read post', 'agent-action-review' );
	}

	/**
	 * Description shown to agents.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'Read the title and status of a post. Runs immediately.', 'agent-action-review' );
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
			'readonly'    => true,
			'destructive' => false,
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
	 * The user must be able to read the post.
	 *
	 * @param int                  $user_id User to check.
	 * @param array<string, mixed> $input   Validated input.
	 * @return bool
	 */
	public function authorize( int $user_id, array $input ): bool {
		return user_can( $user_id, 'read_post', PostInput::post_id( $input ) );
	}

	/**
	 * Summary for a read, used only if a site makes reads previewable.
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array{summary: string, preview: array<string, mixed>}|WP_Error
	 */
	public function describe( array $input ) {
		$post = PostInput::post( $input );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		return array(
			/* translators: %d: post ID. */
			'summary' => sprintf( __( 'Read post #%d', 'agent-action-review' ), $post->ID ),
			'preview' => array( 'post_id' => $post->ID ),
		);
	}

	/**
	 * Reads do not depend on a reviewed state.
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return string
	 */
	public function fingerprint( array $input ): string {
		return '';
	}

	/**
	 * Return the post fields.
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function apply( array $input ) {
		$post = PostInput::post( $input );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		return array(
			'post_id'           => $post->ID,
			'title'             => $post->post_title,
			'status'            => $post->post_status,
			'post_modified_gmt' => $post->post_modified_gmt,
		);
	}
}
