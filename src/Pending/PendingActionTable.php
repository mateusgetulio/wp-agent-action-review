<?php
/**
 * Database store for Agents API pending actions.
 *
 * @package AgentActionReview
 */

namespace AgentActionReview\Pending;

defined( 'ABSPATH' ) || exit;

use AgentsAPI\AI\Approvals\WP_Agent_Approval_Decision;
use AgentsAPI\AI\Approvals\WP_Agent_Pending_Action;
use AgentsAPI\AI\Approvals\WP_Agent_Pending_Action_Status;
use AgentsAPI\AI\Approvals\WP_Agent_Pending_Action_Store;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; approval state must always be read fresh.

/**
 * Implements the upstream store contract, plus the atomic transitions the review flow needs.
 */
final class PendingActionTable implements WP_Agent_Pending_Action_Store {

	private const JSON_COLUMNS = array( 'preview', 'apply_input', 'workspace', 'resolution_result', 'resolution_metadata', 'metadata' );

	private const TIME_COLUMNS = array( 'created_at', 'expires_at', 'resolved_at' );

	/**
	 * Table name with the site prefix.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'agent_review_pending_actions';
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
				action_id char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				kind varchar(191) NOT NULL,
				summary text NOT NULL,
				preview longtext NOT NULL,
				apply_input longtext NOT NULL,
				workspace longtext NULL,
				agent varchar(191) NULL,
				creator varchar(191) NULL,
				status varchar(20) NOT NULL,
				created_at datetime NOT NULL,
				expires_at datetime NULL,
				resolved_at datetime NULL,
				resolver varchar(191) NULL,
				resolution_result longtext NULL,
				resolution_error varchar(191) NULL,
				resolution_metadata longtext NULL,
				metadata longtext NULL,
				PRIMARY KEY  (action_id),
				KEY status_expires (status,expires_at),
				KEY creator (creator)
			) {$charset};"
		);
	}

	/**
	 * Persist a new pending action record.
	 *
	 * @param WP_Agent_Pending_Action $action Durable pending action record.
	 * @return bool
	 */
	public function store( WP_Agent_Pending_Action $action ): bool {
		global $wpdb;

		$row = $this->to_row( $action->to_array() );

		if ( null === $row ) {
			return false;
		}

		return false !== $wpdb->insert( self::table(), $row );
	}

	/**
	 * Retrieve one action.
	 *
	 * Without $include_resolved only actionable rows are returned: pending and
	 * not past their expiry. A row that cannot be decoded or fails upstream
	 * validation is treated as missing rather than loaded with guessed values.
	 *
	 * @param string $action_id        Action ID.
	 * @param bool   $include_resolved Whether terminal rows may be returned.
	 * @return WP_Agent_Pending_Action|null
	 */
	public function get( string $action_id, bool $include_resolved = false ): ?WP_Agent_Pending_Action {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE action_id = %s', self::table(), $action_id ), ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		if ( ! $include_resolved && ( WP_Agent_Pending_Action_Status::PENDING !== $row['status'] || self::is_past( $row['expires_at'] ) ) ) {
			return null;
		}

		return $this->hydrate( $row );
	}

	/**
	 * List actions, newest first.
	 *
	 * Supported filters: status, kind, creator, limit (default 50, max 200), offset.
	 * Rows that cannot be decoded are skipped.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return WP_Agent_Pending_Action[]
	 */
	public function list( array $filters = array() ): array {
		global $wpdb;

		list( $where, $args ) = $this->where( $filters );
		$limit                = max( 1, min( 200, (int) ( $filters['limit'] ?? 50 ) ) );
		$offset               = max( 0, (int) ( $filters['offset'] ?? 0 ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM %i WHERE {$where} ORDER BY created_at DESC, action_id DESC LIMIT %d OFFSET %d", array_merge( array( self::table() ), $args, array( $limit, $offset ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where holds only fixed column clauses; its placeholders are passed in the merged array.
			ARRAY_A
		);

		return array_values( array_filter( array_map( array( $this, 'hydrate' ), $rows ) ) );
	}

	/**
	 * Count actions by status.
	 *
	 * @param array<string, mixed> $filters Filters, as in list().
	 * @return array<string, int>
	 */
	public function summary( array $filters = array() ): array {
		global $wpdb;

		list( $where, $args ) = $this->where( $filters );

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT status, COUNT(*) AS total FROM %i WHERE {$where} GROUP BY status", array_merge( array( self::table() ), $args ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where holds only fixed column clauses; its placeholders are passed in the merged array.
			ARRAY_A
		);

		$summary = array();
		foreach ( $rows as $row ) {
			$summary[ (string) $row['status'] ] = (int) $row['total'];
		}

		return $summary;
	}

	/**
	 * Record the outcome of a resolution that claim() already made.
	 *
	 * Write-once: it only succeeds on a row already resolved with this decision
	 * by this resolver, and only while no result or error has been recorded.
	 * It never moves a row out of pending.
	 *
	 * @param string                     $action_id Action ID.
	 * @param WP_Agent_Approval_Decision $decision  Accepted or rejected.
	 * @param string                     $resolver  Resolver principal.
	 * @param mixed                      $result    JSON-serializable result.
	 * @param string|null                $error     Error code.
	 * @param array<string, mixed>       $metadata  Resolution metadata.
	 * @return bool
	 */
	public function record_resolution( string $action_id, WP_Agent_Approval_Decision $decision, string $resolver, $result = null, ?string $error = null, array $metadata = array() ): bool {
		global $wpdb;

		// $wpdb->prepare() turns null into an empty string, so absent values are written as SQL NULL literals.
		$set  = array(
			null === $result ? 'resolution_result = NULL' : 'resolution_result = %s',
			null === $error ? 'resolution_error = NULL' : 'resolution_error = %s',
			'resolution_metadata = %s',
		);
		$args = array_merge(
			array( self::table() ),
			null === $result ? array() : array( (string) wp_json_encode( $result ) ),
			null === $error ? array() : array( $error ),
			array( (string) wp_json_encode( $metadata ), $action_id, $decision->value(), $resolver )
		);

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $set holds only fixed column assignments; values are passed in $args.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET ' . implode( ', ', $set ) . ' WHERE action_id = %s AND status = %s AND resolver = %s AND resolution_result IS NULL AND resolution_error IS NULL',
				$args
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return 1 === $updated;
	}

	/**
	 * Expire pending actions whose expiry has passed.
	 *
	 * @param string|null $before ISO 8601 time; defaults to now. Invalid values expire nothing.
	 * @return int Rows expired.
	 */
	public function expire( ?string $before = null ): int {
		global $wpdb;

		$cutoff_time = null === $before ? time() : strtotime( $before );

		if ( false === $cutoff_time ) {
			return 0;
		}

		$cutoff = self::db_time( $cutoff_time );

		return (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, resolved_at = %s WHERE status = %s AND expires_at IS NOT NULL AND expires_at <= %s',
				self::table(),
				WP_Agent_Pending_Action_Status::EXPIRED,
				self::db_time( time() ),
				WP_Agent_Pending_Action_Status::PENDING,
				$cutoff
			)
		);
	}

	/**
	 * Remove a record permanently.
	 *
	 * The upstream contract suggests keeping a deleted audit row, but its
	 * delete() receives no resolver while a deleted record requires one, so
	 * this store deletes deliberately. The review flow never calls it.
	 *
	 * @param string $action_id Action ID.
	 * @return bool
	 */
	public function delete( string $action_id ): bool {
		global $wpdb;

		return 1 === $wpdb->delete( self::table(), array( 'action_id' => $action_id ), array( '%s' ) );
	}

	/**
	 * Move a pending, unexpired action to accepted or rejected, once.
	 *
	 * The UPDATE only matches a row that is still pending, so two reviewers
	 * clicking at the same moment cannot both win.
	 *
	 * @param string                     $action_id Action ID.
	 * @param WP_Agent_Approval_Decision $decision  Accepted or rejected.
	 * @param string                     $resolver  Resolver principal derived from the session.
	 * @param int                        $now       Current Unix time.
	 * @return bool Whether this call made the transition.
	 * @throws \RuntimeException When the database reports an error, so a failure is not mistaken for a lost race.
	 */
	public function claim( string $action_id, WP_Agent_Approval_Decision $decision, string $resolver, int $now ): bool {
		global $wpdb;

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, resolver = %s, resolved_at = %s
				WHERE action_id = %s AND status = %s AND ( expires_at IS NULL OR expires_at > %s )',
				self::table(),
				$decision->value(),
				$resolver,
				self::db_time( $now ),
				$action_id,
				WP_Agent_Pending_Action_Status::PENDING,
				self::db_time( $now )
			)
		);

		self::throw_on_db_error( $updated );

		return 1 === $updated;
	}

	/**
	 * Move a pending action to expired with a reason, once.
	 *
	 * @param string $action_id Action ID.
	 * @param string $error     Reason code, for example resource_changed.
	 * @param int    $now       Current Unix time.
	 * @return bool Whether this call made the transition.
	 * @throws \RuntimeException When the database reports an error.
	 */
	public function expire_if_pending( string $action_id, string $error, int $now ): bool {
		global $wpdb;

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, resolved_at = %s, resolution_error = %s WHERE action_id = %s AND status = %s',
				self::table(),
				WP_Agent_Pending_Action_Status::EXPIRED,
				self::db_time( $now ),
				$error,
				$action_id,
				WP_Agent_Pending_Action_Status::PENDING
			)
		);

		self::throw_on_db_error( $updated );

		return 1 === $updated;
	}

	/**
	 * Build the WHERE clause for list and summary filters.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return array{0: string, 1: array<int, string>}
	 */
	private function where( array $filters ): array {
		$clauses = array( '1 = 1' );
		$args    = array();

		foreach ( array( 'status', 'kind', 'creator' ) as $column ) {
			if ( isset( $filters[ $column ] ) && is_string( $filters[ $column ] ) && '' !== $filters[ $column ] ) {
				$clauses[] = "{$column} = %s";
				$args[]    = $filters[ $column ];
			}
		}

		return array( implode( ' AND ', $clauses ), $args );
	}

	/**
	 * Upstream array shape to database row.
	 *
	 * Times are stored in UTC to the second; they come back as gmdate( 'c' ).
	 *
	 * @param array<string, mixed> $data Pending action array.
	 * @return array<string, string|null>|null Null when a time cannot be parsed or a value cannot be encoded.
	 */
	private function to_row( array $data ): ?array {
		$row = array();

		foreach ( $data as $key => $value ) {
			if ( in_array( $key, self::JSON_COLUMNS, true ) ) {
				$encoded = null === $value ? null : wp_json_encode( $value );
				if ( false === $encoded ) {
					return null;
				}
				$row[ $key ] = $encoded;
			} elseif ( in_array( $key, self::TIME_COLUMNS, true ) ) {
				$timestamp = null === $value ? null : strtotime( (string) $value );
				if ( false === $timestamp ) {
					return null;
				}
				$row[ $key ] = null === $timestamp ? null : self::db_time( $timestamp );
			} else {
				$row[ $key ] = null === $value ? null : (string) $value;
			}
		}

		return $row;
	}

	/**
	 * Database row to a pending action, or null when the row is corrupt.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return WP_Agent_Pending_Action|null
	 */
	private function hydrate( array $row ): ?WP_Agent_Pending_Action {
		try {
			return WP_Agent_Pending_Action::from_array( $this->from_row( $row ) );
		} catch ( \JsonException | \InvalidArgumentException $exception ) {
			return null;
		}
	}

	/**
	 * Database row to upstream array shape.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 * @throws \JsonException When a stored JSON column is malformed.
	 */
	private function from_row( array $row ): array {
		foreach ( self::JSON_COLUMNS as $column ) {
			$row[ $column ] = null === $row[ $column ] ? null : json_decode( (string) $row[ $column ], true, 512, JSON_THROW_ON_ERROR );
		}

		foreach ( self::TIME_COLUMNS as $column ) {
			$row[ $column ] = null === $row[ $column ] ? null : gmdate( 'c', (int) strtotime( $row[ $column ] . ' UTC' ) );
		}

		foreach ( array( 'agent', 'creator', 'resolver', 'resolution_error' ) as $column ) {
			$row[ $column ] = '' === $row[ $column ] ? null : $row[ $column ];
		}

		$row['resolution_metadata'] = is_array( $row['resolution_metadata'] ) ? $row['resolution_metadata'] : array();
		$row['metadata']            = is_array( $row['metadata'] ) ? $row['metadata'] : array();

		return $row;
	}

	/**
	 * Whether a stored UTC DATETIME is in the past.
	 *
	 * @param mixed $datetime Stored value or null.
	 * @return bool
	 */
	private static function is_past( $datetime ): bool {
		return is_string( $datetime ) && '' !== $datetime && (int) strtotime( $datetime . ' UTC' ) <= time();
	}

	/**
	 * Fail loudly when the last query errored instead of matching no rows.
	 *
	 * @param int|bool $result Return value of $wpdb->query().
	 * @return void
	 * @throws \RuntimeException When the database reported an error.
	 */
	private static function throw_on_db_error( $result ): void {
		global $wpdb;

		if ( false === $result || '' !== $wpdb->last_error ) {
			throw new \RuntimeException( 'Pending action update failed.' );
		}
	}

	/**
	 * Unix time as a UTC DATETIME string.
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	private static function db_time( int $timestamp ): string {
		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}
}
