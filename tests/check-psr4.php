<?php
/**
 * Confirm every file under src/ declares exactly the class its path implies, which is what the
 * autoloader assumes.
 */

declare( strict_types = 1 );

$root  = dirname( __DIR__ ) . '/src';
$bad   = [];
$count = 0;

$rii = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );

foreach ( $rii as $file ) {
	if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
		continue;
	}

	++$count;

	$path = str_replace( '\\', '/', $file->getPathname() );
	$rel  = substr( $path, strlen( $root ) + 1 );
	$rel  = substr( $rel, 0, -4 );

	$expected_ns   = 'OhMyCache';
	$parts         = explode( '/', $rel );
	$expected_name = array_pop( $parts );

	if ( $parts ) {
		$expected_ns .= chr( 92 ) . implode( chr( 92 ), $parts );
	}

	$src = (string) file_get_contents( $path );

	if ( ! preg_match( '/^namespace\s+([^;]+);/m', $src, $ns_match ) ) {
		$bad[] = "$rel: no namespace declaration";
		continue;
	}

	$actual_ns = trim( $ns_match[1] );

	if ( $actual_ns !== $expected_ns ) {
		$bad[] = "$rel: namespace is {$actual_ns}, expected {$expected_ns}";
	}

	if ( ! preg_match( '/^(?:final\s+|abstract\s+)?(class|interface|trait|enum)\s+([A-Za-z0-9_]+)/m', $src, $cls_match ) ) {
		$bad[] = "$rel: no type declaration found";
		continue;
	}

	if ( $cls_match[2] !== $expected_name ) {
		$bad[] = "$rel: declares {$cls_match[2]}, expected {$expected_name}";
	}
}

echo "files checked: {$count}" . PHP_EOL;

if ( $bad ) {
	echo 'PROBLEMS:' . PHP_EOL . implode( PHP_EOL, $bad ) . PHP_EOL;
	exit( 1 );
}

echo 'OK: every file matches its PSR-4 path' . PHP_EOL;
