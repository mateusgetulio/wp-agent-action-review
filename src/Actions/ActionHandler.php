<?php
/**
 * Contract for the concrete actions an agent can request.
 *
 * @package AgentActionReview
 */

namespace AgentActionReview\Actions;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * One narrow WordPress operation: who may request it, what the reviewer sees, and how it is applied.
 */
interface ActionHandler {

	/**
	 * Ability name, for example agent-review/update-post-title.
	 *
	 * @return string
	 */
	public function name(): string;

	/**
	 * Policy used when nothing else in the Agents API resolver decides.
	 *
	 * @return string One of direct, preview, forbidden.
	 */
	public function default_policy(): string;

	/**
	 * Human label for the ability.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Description shown to agents.
	 *
	 * @return string
	 */
	public function description(): string;

	/**
	 * JSON schema of the input.
	 *
	 * @return array<string, mixed>
	 */
	public function input_schema(): array;

	/**
	 * Advisory MCP annotations: readonly, destructive, idempotent. Never used for enforcement.
	 *
	 * @return array<string, bool>
	 */
	public function annotations(): array;

	/**
	 * The canonical input: only allowed keys, strict types, values cleaned once.
	 *
	 * This is what gets authorized, previewed, stored, digested and applied, so
	 * the reviewer approves exactly the value that is written.
	 *
	 * @param array<string, mixed> $input Input from the ability call.
	 * @return array<string, mixed>|WP_Error
	 */
	public function normalize_input( array $input );

	/**
	 * Whether a user may perform this action on this input right now.
	 *
	 * @param int                  $user_id User to check.
	 * @param array<string, mixed> $input   Validated input.
	 * @return bool
	 */
	public function authorize( int $user_id, array $input ): bool;

	/**
	 * Summary and reviewable preview of the change.
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array{summary: string, preview: array<string, mixed>}|WP_Error
	 */
	public function describe( array $input );

	/**
	 * Fingerprint of the resource this action depends on, or an empty string when there is none.
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return string
	 */
	public function fingerprint( array $input ): string;

	/**
	 * Perform the operation.
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function apply( array $input );
}
