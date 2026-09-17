<?php

$tests_dir = getenv( 'WP_TESTS_DIR' );

if ( false === $tests_dir || '' === $tests_dir ) {
	$tests_dir = dirname( __DIR__, 2 ) . '/vendor/wp-phpunit/wp-phpunit';
}

if ( ! file_exists( $tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test library not found in {$tests_dir}. Run the integration tests through bin/integration-tests.sh.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WordPress is not loaded yet.
	exit( 1 );
}

require_once dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
require_once $tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		$plugins = dirname( __DIR__, 3 );

		require $plugins . '/agents-api/agents-api.php';
		require $plugins . '/mcp-adapter/mcp-adapter.php';
		require dirname( __DIR__, 2 ) . '/agent-action-review.php';
	}
);

tests_add_filter(
	'setup_theme',
	static function (): void {
		AgentActionReview\Pending\PendingActionTable::install();
	}
);

require $tests_dir . '/includes/bootstrap.php';
