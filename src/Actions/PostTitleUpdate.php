<?php
/**
 * Change a post's title.
 *
 * @package AgentActionReview
 */

namespace AgentActionReview\Actions;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * The only write this plugin demonstrates, kept narrow on purpose: the title and nothing else.
 */
final class PostTitleUpdate implements ActionHandler {

	public const MAX_TITLE_LENGTH = 200;

	/**
	 * Ability name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'agent-review/update-post-title';
	}

	/**
	 * Writes are previewed by default.
	 *
	 * @return string
	 */
	public function default_policy(): string {
		return 'preview';
	}

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Update post title', 'agent-action-review' );
	}

	/**
	 * Description shown to agents.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'Propose a new title for a post. A person reviews the change in wp-admin before it runs.', 'agent-action-review' );
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
				'title'   => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => self::MAX_TITLE_LENGTH,
				),
			),
			'required'             => array( 'post_id', 'title' ),
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
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	/**
	 * The post_id as a positive integer and the title cleaned once: tags stripped, trimmed, not empty, at most 200 characters.
	 *
	 * @param array<string, mixed> $input Input from the ability call.
	 * @return array<string, mixed>|WP_Error
	 */
	public function normalize_input( array $input ) {
		$post_id = PostInput::normalize_post_id( $input );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( ! isset( $input['title'] ) || ! is_string( $input['title'] ) ) {
			return new WP_Error( 'agent_review_invalid_title', __( 'title must be a string.', 'agent-action-review' ), array( 'status' => 400 ) );
		}

		$title = trim( wp_strip_all_tags( $input['title'] ) );

		if ( '' === $title || mb_strlen( $title ) > self::MAX_TITLE_LENGTH ) {
			return new WP_Error( 'agent_review_invalid_title', __( 'title must be 1 to 200 characters of text.', 'agent-action-review' ), array( 'status' => 400 ) );
		}

		return array(
			'post_id' => $post_id,
			'title'   => $title,
		);
	}

	/**
	 * The user must be able to edit the post.
	 *
	 * @param int                  $user_id User to check.
	 * @param array<string, mixed> $input   Validated input.
	 * @return bool
	 */
	public function authorize( int $user_id, array $input ): bool {
		return user_can( $user_id, 'edit_post', PostInput::post_id( $input ) );
	}

	/**
	 * The before and after title.
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
			'summary' => sprintf( __( 'Change the title of post #%d', 'agent-action-review' ), $post->ID ),
			'preview' => array(
				'field'  => 'post_title',
				'before' => $post->post_title,
				'after'  => (string) $input['title'],
			),
		);
	}

	/**
	 * Modified time, title and status of the post as they are now.
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return string
	 */
	public function fingerprint( array $input ): string {
		return PostInput::fingerprint( $input );
	}

	/**
	 * Update the title through the normal post API, so core hooks and revisions run.
	 *
	 * The stored, normalized title is written as is. wp_update_post() expects
	 * slashed data, so it is slashed here to keep backslashes intact.
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function apply( array $input ) {
		$post = PostInput::post( $input );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$old_title = $post->post_title;
		$updated   = wp_update_post(
			array(
				'ID'         => $post->ID,
				'post_title' => wp_slash( (string) $input['title'] ),
			),
			true
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return array(
			'post_id'   => $post->ID,
			'old_title' => $old_title,
			'new_title' => get_post_field( 'post_title', $post->ID, 'raw' ),
		);
	}
}
