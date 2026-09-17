<?php
/**
 * Metadata-only log of executor decisions.
 *
 * @package AgentActionReview
 */

namespace AgentActionReview\Audit;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; the activity view must be fresh.

/**
 * One row per agent request, so direct, preview and forbidden decisions can be shown together.
 *
 * Previewed actions keep their full audit in the pending action record; this
 * log only links to it. It never stores titles, request bodies, prompts,
 * tokens, headers or IP addresses.
 */
final class EventLog {

	public const EXECUTED  = 'executed';
	public const PROPOSED  = 'proposed';
	public const FORBIDDEN = 'forbidden';
	public const DENIED    = 'denied';
	public const FAILED    = 'failed';

	/**
	 * Table name with the site prefix.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'agent_review_events';
	}

	/**
	 * Create or update the table.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				ability varchar(191) NOT NULL,
				policy varchar(20) NOT NULL,
				outcome varchar(20) NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				auth_source varchar(40) NOT NULL DEFAULT '',
				action_id char(36) NULL,
				post_id bigint(20) unsigned NULL,
				PRIMARY KEY  (id),
				KEY created_at (created_at),
				KEY action_id (action_id)
			) {$charset};"
		);
	}

	/**
	 * Record one decision.
	 *
	 * @param string      $ability     Ability name.
	 * @param string      $policy      Resolved policy.
	 * @param string      $outcome     One of the outcome constants.
	 * @param int         $user_id     Requesting user.
	 * @param string      $auth_source How the request authenticated; display metadata only.
	 * @param string|null $action_id   Pending action ID, for previews.
	 * @param int|null    $post_id     Target post.
	 * @return void
	 */
	public function add( string $ability, string $policy, string $outcome, int $user_id, string $auth_source, ?string $action_id = null, ?int $post_id = null ): void {
		global $wpdb;

		$wpdb->insert(
			self::table(),
			array(
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
				'ability'     => $ability,
				'policy'      => $policy,
				'outcome'     => $outcome,
				'user_id'     => $user_id,
				'auth_source' => $auth_source,
				'action_id'   => $action_id,
				'post_id'     => $post_id,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d' )
		);
	}

	/**
	 * Most recent events, newest first.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function latest( int $limit = 50 ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', self::table(), max( 1, min( 200, $limit ) ) ),
			ARRAY_A
		);
	}
}
