<?php
/**
 * Fails when the committed vendor/ directory contains development packages.
 *
 * The plugin ships vendor/ from git. PHPStan and its helpers are require-dev
 * packages: `composer install` puts them in vendor/ and rewrites the autoloader
 * to load files that would be missing on a user's site. Run this before any
 * Composer install in CI. Locally, undo an install with:
 *
 *     git restore vendor && git clean -fd vendor
 *
 * Usage: php .github/scripts/check-vendor.php [path-to-plugin-root]
 */

$root      = isset( $argv[1] ) ? rtrim( $argv[1], '/' ) : dirname( __DIR__, 2 );
$installed = json_decode( (string) file_get_contents( $root . '/vendor/composer/installed.json' ), true );
$lock      = json_decode( (string) file_get_contents( $root . '/composer.lock' ), true );

if ( ! is_array( $installed ) || ! is_array( $lock ) ) {
	fwrite( STDERR, "Could not read vendor/composer/installed.json or composer.lock.\n" );
	exit( 1 );
}

$errors = array();

if ( ! empty( $installed['dev-package-names'] ) ) {
	$errors[] = 'vendor/ contains dev packages: ' . implode( ', ', $installed['dev-package-names'] );
}

$expected = array();
foreach ( $lock['packages'] as $package ) {
	$expected[ $package['name'] ] = $package['version'];
}
$actual = array();
foreach ( $installed['packages'] as $package ) {
	$actual[ $package['name'] ] = $package['version'];
}
ksort( $expected );
ksort( $actual );

if ( $expected !== $actual ) {
	$errors[] = 'vendor/ does not match the production packages in composer.lock.';
}

if ( $errors ) {
	fwrite( STDERR, implode( "\n", $errors ) . "\n" );
	fwrite( STDERR, "Restore it with: git restore vendor && git clean -fd vendor\n" );
	exit( 1 );
}

echo "vendor/ holds only the production packages from composer.lock.\n";
