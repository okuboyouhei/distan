<?php
// Development-only test entry point; never run from a web request.
if ( PHP_SAPI !== 'cli' ) { http_response_code( 403 ); exit; }
/** Run: php tests/schema-audit.php. Includes the injection regression suite. */
require __DIR__ . '/schema.php';
$env = array( 'site' => 'http://dev.test/', 'site_url' => 'https://public.test/' );
function fixture( $json, $extra = '' ) {
	return '<!doctype html><html><head>' . $extra . '<script type="application/ld+json">' . $json . '</script></head><body></body></html>';
}
function codes( $row ) { return array_column( $row['issues'], 'code' ); }
$good = '{"@context":"https://schema.org","@type":"WebPage","url":"https://public.test/about/index.html"}';
$r = Distan_Schema_Audit::inspect( fixture( $good, '<link rel="canonical" href="https://public.test/about/">' ), 'added', $env );
check( empty( $r['issues'] ) && 1 === $r['blocks'] && array( 'WebPage' ) === $r['types'], 'Equivalent directory canonical is not flagged' );
check( 'existing' === Distan_Schema_Audit::inspect( fixture( $good ), 'disabled', $env )['status'], 'Existing JSON-LD is diagnosed even with injection off' );
check( 'existing' === Distan_Schema_Audit::inspect( fixture( $good ), 'excluded', $env )['status'], 'Exclusion does not hide existing JSON-LD diagnostics' );
foreach ( array( '' => 'empty_json', '{broken' => 'invalid_json', 'null' => 'invalid_root', '"hello"' => 'invalid_root', '[false]' => 'invalid_root', '{}' => 'no_type' ) as $json => $code ) {
	$r = Distan_Schema_Audit::inspect( fixture( $json ), 'existing', $env );
	check( in_array( $code, codes( $r ), true ), 'Detect ' . $code . ' for ' . $json );
}
$r = Distan_Schema_Audit::inspect( fixture( str_replace( 'public.test', 'dev.test', $good ) ), 'existing', $env );
check( in_array( 'dev_url', codes( $r ), true ), 'Development host found in decoded JSON' );
$r = Distan_Schema_Audit::inspect( fixture( '{"@type":"Thing","url":"http:\/\/dev.test\/test"}' ), 'existing', $env );
check( in_array( 'dev_url', codes( $r ), true ), 'Escaped slash URLs inspected structurally' );
$r = Distan_Schema_Audit::inspect( fixture( '{"@type":"Thing","url":"https://dev.test.evil.invalid/"}' ), 'existing', $env );
check( ! in_array( 'dev_url', codes( $r ), true ), 'Similar hostname is not misidentified' );
$r = Distan_Schema_Audit::inspect( fixture( '{"@type":"Thing","url":"http://dev.test/test"}' ), 'existing', array( 'site' => 'http://dev.test/', 'site_url' => 'http://dev.test/' ) );
check( ! in_array( 'dev_url', codes( $r ), true ), 'Same production and development base is not flagged' );
$r = Distan_Schema_Audit::inspect( fixture( $good, '<link rel="canonical" href="https://public.test/other/">' ), 'existing', $env );
check( in_array( 'canonical_mismatch', codes( $r ), true ), 'Different WebPage canonical flagged' );
$r = Distan_Schema_Audit::inspect( fixture( str_replace( 'WebPage', 'Organization', $good ), '<link rel="canonical" href="https://public.test/other/">' ), 'existing', $env );
check( ! in_array( 'canonical_mismatch', codes( $r ), true ), 'Organization URL is not compared to page canonical' );
$r = Distan_Schema_Audit::inspect( fixture( '{"@context":"https://schema.org","@graph":[' . $good . ']}' , '<link rel="canonical" href="https://public.test/other/">' ), 'existing', $env );
check( in_array( 'canonical_mismatch', codes( $r ), true ), '@graph WebPage is inspected' );
$r = Distan_Schema_Audit::inspect( fixture( '[' . $good . ']', '<link rel="canonical" href="https://public.test/other/">' ), 'existing', $env );
check( in_array( 'canonical_mismatch', codes( $r ), true ), 'Array of nodes is inspected' );
$r = Distan_Schema_Audit::inspect( fixture( $good, '<link rel="canonical" href="/about/">' ), 'existing', $env );
check( in_array( 'canonical_relative', codes( $r ), true ), 'Relative canonical comparison is explicitly skipped' );
$r = Distan_Schema_Audit::inspect( fixture( $good, '<link rel="canonical" href="https://public.test/about/"><link rel="canonical" href="https://public.test/about/">' ), 'existing', $env );
check( in_array( 'canonical_multiple', codes( $r ), true ), 'Multiple canonical tags flagged' );
$r = Distan_Schema_Audit::inspect( fixture( '{"@type":"WebPage","url":"https://public.test/日本語/index.html"}', '<link rel="canonical" href="https://public.test/%E6%97%A5%E6%9C%AC%E8%AA%9E/">' ), 'existing', $env );
check( ! in_array( 'canonical_mismatch', codes( $r ), true ), 'Raw and encoded UTF-8 canonical match' );
$r = Distan_Schema_Audit::inspect( fixture( '{"@type":"WebPage","url":"https://public.test/a%2Fb/"}', '<link rel="canonical" href="https://public.test/a/b/">' ), 'existing', $env );
check( in_array( 'canonical_mismatch', codes( $r ), true ), 'Encoded slashes are not silently collapsed' );
$reference = '{"@id":"https://public.test/#org"}';
$definition = '{"@id":"https://public.test/#org","@type":"Organization","name":"Example"}';
$r = Distan_Schema_Audit::inspect( fixture( '{"@graph":[' . $definition . ',' . $reference . ',' . $reference . ']}' ), 'existing', $env );
check( ! in_array( 'id_repeated', codes( $r ), true ) && ! in_array( 'id_conflict', codes( $r ), true ), 'Repeated @id references are legitimate' );
$r = Distan_Schema_Audit::inspect( fixture( '[' . $definition . ',' . $definition . ']' ), 'existing', $env );
check( in_array( 'id_repeated', codes( $r ), true ) && 'info' === $r['issues'][0]['level'], 'Repeated definition is informational, not invalidity' );
$r = Distan_Schema_Audit::inspect( fixture( '[' . $definition . ',' . str_replace( 'Example', 'Different', $definition ) . ']' ), 'existing', $env );
check( in_array( 'id_conflict', codes( $r ), true ), 'Conflicting facts for same @id flagged' );
$r = Distan_Schema_Audit::inspect( fixture( str_repeat( ' ', 262145 ) . $good ), 'existing', $env );
// Whitespace is trimmed; the actual JSON document stays small.
check( $r['complete'], 'Harmless surrounding whitespace does not invalidate JSON' );
$r = Distan_Schema_Audit::inspect( fixture( '{"@type":"Thing","name":"' . str_repeat( 'a', 262145 ) . '"}' ), 'existing', $env );
check( ! $r['complete'] && in_array( 'limit', codes( $r ), true ), 'Oversize JSON explicitly incomplete' );
$r = Distan_Schema_Audit::inspect( '<html><head>' . str_repeat( '<script type="application/ld+json">{}</script>', 101 ) . '</head></html>', 'existing', $env );
check( ! $r['complete'] && 101 === $r['blocks'] && count( $r['issues'] ) <= 40, 'Issue and block limits are bounded and visible' );
$r = Distan_Schema_Audit::inspect( fixture( '{"@type":"Thing","values":[' . implode( ',', array_fill( 0, 2100, '1' ) ) . ']}' ), 'existing', $env );
check( ! $r['complete'] && in_array( 'limit', codes( $r ), true ), 'Node budget exhaustion is visible' );
$r = Distan_Schema_Audit::inspect( '<html><head></head></html>', 'missing_url', $env );
check( in_array( 'missing_url', codes( $r ), true ) && 'missing_url' === $r['status'], 'Missing production URL has actionable explanation' );
$r = Distan_Schema_Audit::inspect( '', 'parse_failed', $env );
check( ! $r['complete'], 'Unparseable empty document not reported as successful' );

$pages = array( 'plain/index.html' => Distan_Schema_Audit::inspect( fixture( $good ), 'added', $env ), 'empty/index.html' => Distan_Schema_Audit::inspect( fixture( '' ), 'disabled', $env ), 'failed.html' => array( 'status' => 'failed', 'blocks' => null, 'types' => array(), 'issues' => array(), 'complete' => false ) );
$summary = Distan_Schema_Audit::summarize( $pages );
check( 1 === $summary['warning_pages'] && 1 === $summary['incomplete_pages'] && 1 === $summary['counts']['added'], 'Accurate aggregate counts, failures separate' );
$display = Distan_Schema_Audit::for_display( $summary );
check( 'empty/index.html' === $display['rows'][0]['path'] && 3 === $display['total'], 'Admin prioritizes issue pages' );
check( array() === Distan_Schema_Audit::for_display( array() ), 'Legacy manifests have no fabricated pass result' );
$report = new ReflectionMethod( Distan_Report::class, 'schema_section' );
$report->setAccessible( true );
$before = $report->invoke( null, $summary );
$saved = array( 'schema' => false, 'site_url' => 'https://changed.test/' );
check( $before === $report->invoke( null, $summary ), 'Report reads manifest snapshot, not live settings' );
check( str_contains( implode( "\n", $before ), 'empty_json' ), 'Report contains stable issue code' );
$hostile = $pages['plain/index.html'];
$hostile['types'] = array( '<script>alert(1)</script>|evil' );
$escaped = $report->invoke( null, Distan_Schema_Audit::summarize( array( "x|<img src=x>\ny.html" => $hostile ) ) );
check( ! str_contains( implode( "\n", $escaped ), '<script>' ) && str_contains( implode( "\n", $escaped ), '&#124;' ), 'Report safely escapes author-controlled cells' );
$many = array();
for ( $i = 0; $i < 205; ++$i ) { $many[ $i . '.html' ] = $pages['plain/index.html']; }
$many['bad.html'] = $pages['empty/index.html'];
$many_display = Distan_Schema_Audit::for_display( Distan_Schema_Audit::summarize( $many ) );
check( 200 === count( $many_display['rows'] ) && 206 === $many_display['total'] && 'bad.html' === $many_display['rows'][0]['path'], 'Admin cap preserves full count and prioritizes warnings' );

$saved = array( 'schema' => true, 'site_url' => '' );
Distan_Schema::inject( $html, $item, $reason );
check( 'missing_url' === $reason, 'Injection exposes missing URL gate' );
$saved['site_url'] = 'https://public.test';
Distan_Schema::inject( $html, $item, $reason );
check( 'added' === $reason, 'Injection exposes addition' );
$saved['schema_exclude_pages'] = '/about/';
Distan_Schema::inject( $html, $item, $reason );
check( 'excluded' === $reason, 'Injection exposes exclusion gate' );
echo "PASS: {$count} total injection + audit checks\n";
