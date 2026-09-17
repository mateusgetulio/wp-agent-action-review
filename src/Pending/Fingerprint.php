<?php
/**
 * Snapshot of the resource a reviewer looked at.
 *
 * @package AgentActionReview
 */

namespace AgentActionReview\Pending;

defined( 'ABSPATH' ) || exit;

/**
 * Detects that a post changed between the proposal and the apply.
 */
final class Fingerprint {

	/**
	 * Fingerprint of the fields a title change depends on.
	 *
	 * @param int    $post_id           Post ID.
	 * @param string $post_modified_gmt Last modified time as stored by WordPress.
	 * @param string $post_title        Current raw title.
	 * @param string $post_status       Current status.
	 * @return string
	 * @throws \JsonException When a field cannot be encoded, for example invalid UTF-8 in a title.
	 */
	public static function of_post( int $post_id, string $post_modified_gmt, string $post_title, string $post_status ): string {
		return hash( 'sha256', Digest::canonical_json( array( $post_id, $post_modified_gmt, $post_title, $post_status ) ) );
	}

	/**
	 * Whether two fingerprints are the same.
	 *
	 * @param string $expected Fingerprint saved with the proposal.
	 * @param string $current  Fingerprint of the resource now.
	 * @return bool
	 */
	public static function matches( string $expected, string $current ): bool {
		return '' !== $expected && hash_equals( $expected, $current );
	}
}
