<?php

namespace AgentActionReview\Tests\Unit;

use AgentActionReview\Pending\Fingerprint;
use PHPUnit\Framework\TestCase;

final class FingerprintTest extends TestCase {

	private function base(): string {
		return Fingerprint::of_post( 4, '2026-09-17 09:53:24', 'Summer Sale', 'publish' );
	}

	public function test_same_state_matches(): void {
		$this->assertTrue( Fingerprint::matches( $this->base(), Fingerprint::of_post( 4, '2026-09-17 09:53:24', 'Summer Sale', 'publish' ) ) );
	}

	/**
	 * @dataProvider changes
	 */
	public function test_any_change_does_not_match( int $id, string $modified, string $title, string $status ): void {
		$this->assertFalse( Fingerprint::matches( $this->base(), Fingerprint::of_post( $id, $modified, $title, $status ) ) );
	}

	public static function changes(): array {
		return array(
			'modified time' => array( 4, '2026-09-17 09:53:25', 'Summer Sale', 'publish' ),
			'title'         => array( 4, '2026-09-17 09:53:24', 'Summer sale', 'publish' ),
			'status'        => array( 4, '2026-09-17 09:53:24', 'Summer Sale', 'draft' ),
			'post'          => array( 5, '2026-09-17 09:53:24', 'Summer Sale', 'publish' ),
		);
	}

	public function test_invalid_utf8_title_throws(): void {
		$this->expectException( \JsonException::class );

		Fingerprint::of_post( 4, '2026-09-17 09:53:24', "Summer \xff", 'publish' );
	}

	public function test_empty_expected_never_matches(): void {
		$this->assertFalse( Fingerprint::matches( '', $this->base() ) );
	}
}
