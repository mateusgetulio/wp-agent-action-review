<?php
/**
 * Deterministic fingerprint of a proposal.
 *
 * @package AgentActionReview
 */

namespace AgentActionReview\Pending;

defined( 'ABSPATH' ) || exit;

/**
 * Identifies the exact proposal a reviewer approves.
 *
 * It detects inconsistent or corrupted stored state. It is not tamper
 * protection: anyone who can write the row can rewrite the digest too.
 */
final class Digest {

	/**
	 * SHA-256 of the canonical JSON of the proposal's identifying fields.
	 *
	 * @param string                    $kind        Ability name.
	 * @param array<string, mixed>      $apply_input Input the handler will apply.
	 * @param string                    $creator     Creator principal, for example user:2.
	 * @param array<string, mixed>|null $workspace   Workspace scope array.
	 * @return string
	 * @throws \JsonException When a value cannot be encoded, so callers refuse instead of comparing an empty hash.
	 */
	public static function of( string $kind, array $apply_input, string $creator, ?array $workspace ): string {
		return hash(
			'sha256',
			self::canonical_json(
				array(
					'apply_input' => $apply_input,
					'creator'     => $creator,
					'kind'        => $kind,
					'workspace'   => $workspace,
				)
			)
		);
	}

	/**
	 * Whether a stored digest matches the stored fields.
	 *
	 * @param string                    $expected    Digest saved with the proposal.
	 * @param string                    $kind        Ability name.
	 * @param array<string, mixed>      $apply_input Input the handler will apply.
	 * @param string                    $creator     Creator principal.
	 * @param array<string, mixed>|null $workspace   Workspace scope array.
	 * @return bool
	 * @throws \JsonException When a value cannot be encoded.
	 */
	public static function matches( string $expected, string $kind, array $apply_input, string $creator, ?array $workspace ): bool {
		return hash_equals( $expected, self::of( $kind, $apply_input, $creator, $workspace ) );
	}

	/**
	 * JSON with object keys sorted at every level, so key order never changes the digest.
	 *
	 * Floats are encoded the way WordPress stores them, so a value reloaded
	 * from the database digests the same as the one first proposed.
	 *
	 * @param mixed $value Value to encode.
	 * @return string
	 * @throws \JsonException When the value cannot be encoded.
	 */
	public static function canonical_json( $value ): string {
		return json_encode( self::sort_keys( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure class with no WordPress dependency; flags are fixed for determinism.
	}

	/**
	 * Recursively sort associative array keys, leaving lists in order.
	 *
	 * @param mixed $value Value to sort.
	 * @return mixed
	 */
	private static function sort_keys( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$value = array_map( array( self::class, 'sort_keys' ), $value );

		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}

		return $value;
	}
}
