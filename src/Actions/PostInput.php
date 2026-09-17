<?php
/**
 * Shared post lookup for the actions.
 *
 * @package AgentActionReview
 */

namespace AgentActionReview\Actions;

defined( 'ABSPATH' ) || exit;

use AgentActionReview\Pending\Fingerprint;
use WP_Error;
use WP_Post;

/**
 * Reads the post an action targets.
 */
final class PostInput {

	public const EDITABLE_STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private' );

	/**
	 * Canonical post ID from raw input: a positive integer or a canonical integer string.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return int|WP_Error
	 */
	public static function normalize_post_id( array $input ) {
		$value = $input['post_id'] ?? null;

		if ( is_int( $value ) && $value > 0 ) {
			return $value;
		}

		if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/', $value ) ) {
			return (int) $value;
		}

		return new WP_Error( 'agent_review_invalid_post_id', __( 'post_id must be a positive integer.', 'agent-action-review' ), array( 'status' => 400 ) );
	}

	/**
	 * The post ID from the input, or 0.
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return int
	 */
	public static function post_id( array $input ): int {
		return max( 0, (int) ( $input['post_id'] ?? 0 ) );
	}

	/**
	 * The target post, limited to regular posts in an editable status (no trash, auto-draft or revision).
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return WP_Post|WP_Error
	 */
	public static function post( array $input ) {
		$post = get_post( self::post_id( $input ) );

		if ( ! $post instanceof WP_Post || 'post' !== $post->post_type || ! in_array( $post->post_status, self::EDITABLE_STATUSES, true ) ) {
			return new WP_Error( 'agent_review_post_not_found', __( 'Post not found.', 'agent-action-review' ), array( 'status' => 404 ) );
		}

		return $post;
	}

	/**
	 * Fingerprint of the post's modified time, title and status, or an empty string when it is gone.
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return string
	 */
	public static function fingerprint( array $input ): string {
		$post = self::post( $input );

		if ( is_wp_error( $post ) ) {
			return '';
		}

		return Fingerprint::of_post( $post->ID, $post->post_modified_gmt, $post->post_title, $post->post_status );
	}
}
