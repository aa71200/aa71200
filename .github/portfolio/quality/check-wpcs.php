<?php
declare(strict_types=1);

// Keep historical findings visible without allowing additional violations.
[$script, $mode, $root, $reportPath] = $argv;
$root                                = rtrim( str_replace( '\\', '/', realpath( $root ) ?: '' ), '/' ) . '/';
$report                              = json_decode( file_get_contents( $reportPath ), true, 512, JSON_THROW_ON_ERROR );
if ( ! isset( $report['totals'], $report['files'] ) || ! is_array( $report['files'] ) ) {
	throw new RuntimeException( 'Invalid PHPCS report.' );
}

$findings = array();
foreach ( $report['files'] as $name => $file ) {
	$name = str_replace( '\\', '/', $name );
	if ( ! str_starts_with( $name, $root ) ) {
		throw new RuntimeException( "PHPCS reported a file outside the repository: $name" );
	}
	$relative = substr( $name, strlen( $root ) );
	foreach ( $file['messages'] as $message ) {
		$key                           = $message['type'] . ' ' . $message['source'];
		$findings[ $relative ][ $key ] = ( $findings[ $relative ][ $key ] ?? 0 ) + 1;
	}
}
ksort( $findings );
foreach ( $findings as &$rules ) {
	ksort( $rules );
}
unset( $rules );

if ( 'snapshot' === $mode ) {
	echo json_encode(
		array(
			'_comment' => 'Pre-existing findings from the portfolio review. Counts are limited to this file and sniff. Remove entries as code is corrected.',
			'files'    => (object) $findings,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
	) . PHP_EOL;
	exit;
}
if ( 'check' !== $mode ) {
	throw new InvalidArgumentException( 'Use snapshot or check.' );
}

$baselinePath = $root . '.github/phpcs-baseline.json';
$baseline     = is_file( $baselinePath )
	? json_decode( file_get_contents( $baselinePath ), true, 512, JSON_THROW_ON_ERROR )['files']
	: array();
$new          = 0;
foreach ( $findings as $file => $rules ) {
	foreach ( $rules as $rule => $count ) {
		$additional = $count - ( $baseline[ $file ][ $rule ] ?? 0 );
		if ( $additional > 0 ) {
			fwrite( STDERR, "$file: $additional new finding(s) for $rule\n" );
			$new += $additional;
		}
	}
}
printf(
	"WordPress Coding Standards: %d errors, %d warnings, %d new or unlisted findings.\n",
	$report['totals']['errors'],
	$report['totals']['warnings'],
	$new
);
exit( $new ? 1 : 0 );
