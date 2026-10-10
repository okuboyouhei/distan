<?php
if ( PHP_SAPI !== 'cli' ) { http_response_code( 403 ); exit; }
require __DIR__ . '/schema-generator.php';
$home = 'https://dev.test';
$saved = array( 'schema' => false, 'site_url' => 'https://public.test' );
$audit = new ReflectionMethod( Distan_Generator::class, 'audit_dev_urls' );
$audit->setAccessible( true );
$fixtures = array(
	'escaped.html' => array( '<script type="application/ld+json">{"url":"https:\/\/dev.test\/page\/"}</script>', 1 ),
	'other-host.html' => array( '<a href="https://dev.test.example/page/">other</a>', 0 ),
	'user-info.html' => array( '<a href="https://dev.test@example.com/page/">other</a>', 0 ),
	'ordinary.html' => array( '<a href="https://dev.test/page/">a</a><a href="http://DEV.TEST:80/">b</a>', 2 ),
	 'unicode.html' => array( '<script>{"url":"https\u003a\u002f\u002fdev.test/"}</script>', 1 ),
	'unicode-host.html' => array( '<script>{"url":"https://\u0064ev.test/"}</script>', 1 ),
	'protocol-relative.html' => array( '<a href="//dev.test/page/">relative protocol</a>', 1 ),
	'other-port.html' => array( '<a href="https://dev.test:8080/">other port</a>', 0 ),
	'ftp.html' => array( '<a href="ftp://dev.test/">ftp</a>', 0 ),
	'public.html' => array( '<a href="https://public.test/page/">public</a>', 0 ),
	'mixed.html' => array( '<link href="https://dev.test/a/"><script type="application/ld+json">{"url":"https:\/\/dev.test\/b\/"}</script>', 2 ),
);
foreach ( $fixtures as $name => $fixture ) {
	$file = $test_uploads . '/distan/dist/' . $name;
	file_put_contents( $file, $fixture[0] );
	$result = $audit->invoke( null, array( $name ) );
	check( $fixture[1] === $result['count'], 'Development URL count: ' . $name );
	check( ( $fixture[1] ? array( $name ) : array() ) === $result['files'], 'Development URL file list: ' . $name );
	check( $fixture[0] === file_get_contents( $file ), 'Audit does not modify output: ' . $name );
}
$remote_html = $fixtures['escaped.html'][0];
set_transient( Distan_Generator::JOB_KEY, new_job( array( $queue[0] ) ), 3600 );
Distan_Generator::process_batch( 1 );
$manifest = Distan_Generator::manifest();
check( 1 === $manifest['dev_urls']['count'], 'Batch manifest includes escaped development URL' );
check( in_array( 'dev_url', array_column( $manifest['schema_audit']['pages']['ok/index.html']['issues'], 'code' ), true ), 'JSON-LD diagnostic agrees with summary' );
check( str_contains( Distan_Report::render( $manifest ), '| 開発URLの残り | 1 |' ), 'Report summary includes escaped development URL' );
echo "PASS: {$count} total checks including development URL regressions\n";

$cases = array(
	array( 'https://dev.test/page/', 'https://dev.test/', 'https://public.test/', true ),
	array( 'https://dev.test.example/', 'https://dev.test/', '', false ),
	array( 'https://dev.test@example.com/', 'https://dev.test/', '', false ),
	array( 'https://dev.test/path/', 'https://dev.test/', 'https://dev.test/', false ),
	array( 'https://dev.test:443/path/', 'https://dev.test/', 'https://dev.test:443/', false ),
	array( 'http://dev.test:80/wp/a/', 'http://dev.test/wp/', 'https://public.test/', true ),
	array( 'https://dev.test:8443/wp/a/', 'https://dev.test:8443/wp/', 'https://public.test/', true ),
	array( 'https://dev.test/wp/a/', 'https://dev.test:8443/wp/', 'https://public.test/', false ),
	array( 'https://dev.test/wp-other/a/', 'https://dev.test/wp/', 'https://public.test/', false ),
	array( 'https://dev.test/wp', 'https://dev.test/wp/', 'https://dev.test/public/', true ),
	array( 'https://dev.test/public/a/', 'https://dev.test/wp/', 'https://dev.test/public/', false ),
	array( 'https://dev.test/wp%2Fchild/', 'https://dev.test/wp/', '', false ),
	array( 'https://dev.test/%77p/a/', 'https://dev.test/wp/', '', true ),
	array( 'https://dev.test/日本語/a/', 'https://dev.test/%E6%97%A5%E6%9C%AC%E8%AA%9E/', '', true ),
	array( '//dev.test/wp/a/', 'https://dev.test/wp/', '', true ),
	array( '/wp/a/', 'https://dev.test/wp/', '', false ),
	array( 'https://[::1]:8080/wp/a/', 'http://[::1]:8080/wp/', 'https://public.test/', true ),
	array( 'https://dev.test/', 'not a URL', '', false ),
);
foreach ( $cases as $i => $case ) {
	$environment = array( 'site' => $case[1], 'site_url' => $case[2] );
	check( $case[3] === Distan_Url_Audit::is_development_url( $case[0], $environment ), 'Shared development base rule ' . $i );
	$markup = fixture( json_encode( array( '@type' => 'Thing', 'url' => $case[0] ), JSON_UNESCAPED_UNICODE ) );
	$row = Distan_Schema_Audit::inspect( $markup, 'existing', $environment );
	check( $case[3] === in_array( 'dev_url', codes( $row ), true ), 'JSON-LD uses shared base rule ' . $i );
	check( (int) $case[3] === Distan_Url_Audit::count_development_urls( $markup, $environment ), 'Text audit uses shared base rule ' . $i );
}
echo "PASS: {$count} total checks including shared URL rules\n";

$environment_check = Distan_Env::check_dev_urls();
check( 'warning' === $environment_check['status'] && '1 件' === $environment_check['detail'], 'Environment check uses saved development URL findings' );
$saved['site_url'] = 'https://changed.test';
check( $environment_check === Distan_Env::check_dev_urls(), 'Environment result is unchanged by later settings changes' );
echo "PASS: {$count} total checks including environment snapshot\n";
