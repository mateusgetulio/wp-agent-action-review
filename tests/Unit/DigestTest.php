<?php

namespace AgentActionReview\Tests\Unit;

use AgentActionReview\Pending\Digest;
use PHPUnit\Framework\TestCase;

final class DigestTest extends TestCase {

	private const WORKSPACE = array(
		'workspace_type' => 'wordpress_site',
		'workspace_id'   => '1',
	);

	private function digest( array $apply_input, string $kind = 'agent-review/update-post-title', string $creator = 'user:2', ?array $workspace = self::WORKSPACE ): string {
		return Digest::of( $kind, $apply_input, $creator, $workspace );
	}

	public function test_is_a_sha256_hex_string(): void {
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $this->digest( array( 'post_id' => 4 ) ) );
	}

	public function test_key_order_does_not_change_the_digest(): void {
		$a = $this->digest(
			array(
				'post_id' => 4,
				'title'   => 'Fall Sale',
			)
		);
		$b = $this->digest(
			array(
				'title'   => 'Fall Sale',
				'post_id' => 4,
			)
		);

		$this->assertSame( $a, $b );
	}

	public function test_nested_key_order_does_not_change_the_digest(): void {
		$a = Digest::of(
			'k',
			array( 'x' => 1 ),
			'user:2',
			array(
				'workspace_type' => 'wordpress_site',
				'workspace_id'   => '1',
			)
		);
		$b = Digest::of(
			'k',
			array( 'x' => 1 ),
			'user:2',
			array(
				'workspace_id'   => '1',
				'workspace_type' => 'wordpress_site',
			)
		);

		$this->assertSame( $a, $b );
	}

	/**
	 * @dataProvider changed_fields
	 */
	public function test_any_identifying_field_change_changes_the_digest( array $apply_input, string $kind, string $creator, ?array $workspace ): void {
		$original = $this->digest(
			array(
				'post_id' => 4,
				'title'   => 'Fall Sale',
			)
		);

		$this->assertNotSame( $original, Digest::of( $kind, $apply_input, $creator, $workspace ) );
	}

	public static function changed_fields(): array {
		$input = array(
			'post_id' => 4,
			'title'   => 'Fall Sale',
		);

		return array(
			'title'        => array(
				array(
					'post_id' => 4,
					'title'   => 'Buy crypto',
				),
				'agent-review/update-post-title',
				'user:2',
				self::WORKSPACE,
			),
			'post id'      => array(
				array(
					'post_id' => 5,
					'title'   => 'Fall Sale',
				),
				'agent-review/update-post-title',
				'user:2',
				self::WORKSPACE,
			),
			'kind'         => array( $input, 'agent-review/delete-post', 'user:2', self::WORKSPACE ),
			'creator'      => array( $input, 'agent-review/update-post-title', 'user:3', self::WORKSPACE ),
			'workspace'    => array(
				$input,
				'agent-review/update-post-title',
				'user:2',
				array(
					'workspace_type' => 'wordpress_site',
					'workspace_id'   => '2',
				),
			),
			'no workspace' => array( $input, 'agent-review/update-post-title', 'user:2', null ),
			'string id'    => array(
				array(
					'post_id' => '4',
					'title'   => 'Fall Sale',
				),
				'agent-review/update-post-title',
				'user:2',
				self::WORKSPACE,
			),
		);
	}

	public function test_list_order_is_significant(): void {
		$this->assertNotSame(
			Digest::of( 'k', array( 'ids' => array( 1, 2 ) ), 'user:2', null ),
			Digest::of( 'k', array( 'ids' => array( 2, 1 ) ), 'user:2', null )
		);
	}

	public function test_matches_uses_the_same_fields(): void {
		$input  = array( 'post_id' => 4 );
		$digest = Digest::of( 'k', $input, 'user:2', null );

		$this->assertTrue( Digest::matches( $digest, 'k', $input, 'user:2', null ) );
		$this->assertFalse( Digest::matches( $digest, 'k', array( 'post_id' => 9 ), 'user:2', null ) );
	}

	public function test_invalid_utf8_throws_instead_of_hashing_an_empty_string(): void {
		$this->expectException( \JsonException::class );

		Digest::of( 'k', array( 't' => "\xff" ), 'user:1', null );
	}

	public function test_float_digest_matches_after_a_json_round_trip(): void {
		$input    = array( 'amount' => 1.0 );
		$reloaded = json_decode( json_encode( $input ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Unit test without WordPress.

		$this->assertSame( Digest::of( 'k', $input, 'user:1', null ), Digest::of( 'k', $reloaded, 'user:1', null ) );
	}

	public function test_unicode_and_slashes_are_stable(): void {
		$this->assertSame(
			'{"a":"Promoção 50/50"}',
			Digest::canonical_json( array( 'a' => 'Promoção 50/50' ) )
		);
	}
}
