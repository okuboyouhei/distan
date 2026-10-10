<?php
// Development-only test entry point; never run from a web request.
if ( PHP_SAPI !== 'cli' ) { http_response_code( 403 ); exit; }
/** Standalone regression checks. Run: php tests/schema.php (DOM required). */
error_reporting( E_ALL );
set_error_handler( static function ( $severity, $message, $file, $line ) {
	if ( error_reporting() & $severity ) {
		throw new ErrorException( $message, 0, $severity, $file, $line );
	}
	return false;
} );
define( 'ABSPATH', __DIR__ );
$saved = array();
$home = 'https://dev.test';
$replacements = array();
function __( $text, $domain = '' ) { return $text; }
function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function plugin_dir_url( $file ) { return 'https://dev.test/plugins/distan/'; }
function register_activation_hook( ...$args ) {}
function add_action( ...$args ) {}
function get_option( $key, $fallback = false ) { global $saved, $test_options; return 'distan_settings' === $key ? $saved : ( $test_options[ $key ] ?? $fallback ); }
function home_url( $path = '' ) { global $home; return $home . $path; }
function trailingslashit( $value ) { return rtrim( $value, '/' ) . '/'; }
function untrailingslashit( $value ) { return rtrim( $value, '/' ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_json_encode( $data, $flags = 0 ) { return json_encode( $data, $flags ); }
function apply_filters( $name, $value, ...$args ) { global $replacements, $test_manifest_source; if ( 'distan_manifest_source' === $name ) { return $test_manifest_source ?? $value; } return 'distan_url_replacements' === $name ? $replacements : $value; }
function esc_url_raw( $value ) { return $value; }
function sanitize_textarea_field( $value ) { return strip_tags( $value ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_-]/', '', $value ) ); }
function get_post_types( ...$args ) { return array( 'post' => 'post' ); }
require dirname( __DIR__ ) . '/distan.php';

$count = 0;
function check( $condition, $label ) {
	global $count;
	if ( ! $condition ) {
		throw new RuntimeException( $label );
	}
	++$count;
}
function schema_data( $html ) {
	preg_match( '~<script type="application/ld\+json" id="distan-schema">(.*?)</script>~s', $html, $match );
	return isset( $match[1] ) ? json_decode( $match[1], true, 512, JSON_THROW_ON_ERROR ) : null;
}
$html = '<!doctype html><html lang="ja"><head><title>日本語 &amp; タイトル</title><meta name="description" content="紹介 &quot;文&quot;"></head><body>本文</body></html>';
$item = array( 'url' => 'https://dev.test/about/', 'path' => 'about/index.html', 'type' => 'page' );
check( Distan_Schema::inject( $html, $item ) === $html, 'Default off is byte-identical' );
$saved = array( 'schema' => true, 'site_url' => 'https://public.test/site' );
$out = Distan_Schema::inject( $html, $item );
$data = schema_data( $out );
check( 'WebPage' === $data['@type'] && 'https://schema.org' === $data['@context'], 'Basic schema vocabulary' );
check( 'https://public.test/site/about/index.html' === $data['url'], 'Production URL with deployment subdirectory' );
check( '日本語 & タイトル' === $data['name'] && '紹介 "文"' === $data['description'] && 'ja' === $data['inLanguage'], 'UTF-8 metadata and entities' );
check( Distan_Schema::inject( $out, $item ) === $out, 'Idempotent injection' );
check( preg_replace( '~\n<script type="application/ld\+json" id="distan-schema">.*?</script>\n~s', '', $out ) === $html, 'Original HTML preserved byte-for-byte' );

foreach ( array( 'type="application/ld+json"', "TYPE = 'APPLICATION/LD+JSON'", 'type=application/ld+json', 'type=" application/ld+json ; charset=utf-8"' ) as $attrs ) {
	foreach ( array( '{"@context":"https://schema.org","@graph":[]}', '', '{broken' ) as $json ) {
		$existing = str_replace( '</body>', '<script ' . $attrs . '>' . $json . '</script></body>', $html );
		check( Distan_Schema::inject( $existing, $item ) === $existing, 'Preserve all author JSON-LD: ' . $attrs );
	}
}
$sample = '<!-- <head><script type="application/ld+json">{}</script></head> -->' . $html;
$sample_out = Distan_Schema::inject( $sample, $item );
check( null !== schema_data( $sample_out ) && str_starts_with( $sample_out, '<!-- <head><script type="application/ld+json">{}</script></head> -->' ), 'Comment examples do not count as JSON-LD or insertion points' );
$dangerous = '<html><head><title>&lt;/script&gt;&lt;script&gt;alert(1)&lt;/script&gt;</title></head><body></body></html>';
$safe = Distan_Schema::inject( $dangerous, $item );
check( 1 === substr_count( $safe, '</script>' ), 'Metadata cannot break out of JSON script' );
check( '</script><script>alert(1)</script>' === schema_data( $safe )['name'], 'JSON preserves hostile text as data' );
check( null === schema_data( Distan_Schema::inject( $html, array_merge( $item, array( 'type' => '404' ) ) ) ), '404 excluded' );
check( '<div>fragment</div>' === Distan_Schema::inject( '<div>fragment</div>', $item ), 'No head: leave fragment unchanged' );
$saved['site_url'] = '';
check( $html === Distan_Schema::inject( $html, $item ), 'No production URL: no development-domain fallback' );
$saved['site_url'] = 'https://public.test';
foreach ( array( 'about.html', '日本語/index.html', 'guide/tab-faq/index.html', 'index.html' ) as $path ) {
	$variant = array_merge( $item, array( 'path' => $path ) );
	$expected = 'https://public.test/' . implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );
	check( $expected === schema_data( Distan_Schema::inject( $html, $variant ) )['url'], 'Actual output path: ' . $path );
}
$replacements = array( 'https://public.test/' => 'https://cdn.test/' );
check( 'https://cdn.test/about/index.html' === schema_data( Distan_Schema::inject( $html, $item ) )['url'], 'Custom production replacement' );
$replacements = array();

$saved['schema_exclude_dirs'] = "/private/\n/日本語/";
foreach ( array( '/private', '/private/', '/private/a/', '/%E6%97%A5%E6%9C%AC%E8%AA%9E/a/' ) as $path ) {
	check( $html === Distan_Schema::inject( $html, array_merge( $item, array( 'url' => $home . $path ) ) ), 'Directory exclusion: ' . $path );
}
check( null !== schema_data( Distan_Schema::inject( $html, array_merge( $item, array( 'url' => $home . '/private-other/' ) ) ) ), 'Directory boundary does not overmatch' );
$saved['schema_exclude_pages'] = '/about/';
check( $html === Distan_Schema::inject( $html, $item ), 'Exact page exclusion' );
check( $html === Distan_Schema::inject( $html, array_merge( $item, array( 'url' => $home . '/about?tab=faq' ) ) ), 'Query variants inherit page exclusion' );
check( null !== schema_data( Distan_Schema::inject( $html, array_merge( $item, array( 'url' => $home . '/about/child/' ) ) ) ), 'Page rule leaves children alone' );
$home = 'https://dev.test/wp';
check( $html === Distan_Schema::inject( $html, array_merge( $item, array( 'url' => $home . '/private/child/' ) ) ), 'WordPress subdirectory excluded relative to home' );
$saved['schema_exclude_pages'] = '/';
check( $html === Distan_Schema::inject( $html, array_merge( $item, array( 'url' => $home . '/' ) ) ), 'Home page exclusion' );
$saved['schema_exclude_dirs'] = '/';
check( $html === Distan_Schema::inject( $html, array_merge( $item, array( 'url' => $home . '/anything/' ) ) ), 'Root directory excludes all' );
$saved['schema'] = false;
check( $html === Distan_Schema::inject( $html, $item ), 'Switching off restores original output' );

$admin = ( new ReflectionClass( Distan_Admin::class ) )->newInstanceWithoutConstructor();
$input = array_merge( Distan::defaults(), array( 'schema' => '1', 'schema_exclude_dirs' => "/private/\n/日本語/", 'schema_exclude_pages' => '/about/' ) );
$stored = $admin->sanitize_settings( $input );
check( true === $stored['schema'] && $input['schema_exclude_dirs'] === $stored['schema_exclude_dirs'] && '/about/' === $stored['schema_exclude_pages'], 'Settings sanitize and retain paths' );
check( $stored === $admin->sanitize_settings( $stored ), 'Settings round trip' );
unset( $input['schema'] );
check( false === $admin->sanitize_settings( $input )['schema'], 'Unchecked checkbox saves off' );
$input['schema_exclude_dirs'] = array( '/private/' );
check( '' === $admin->sanitize_settings( $input )['schema_exclude_dirs'], 'Malformed setting arrays rejected' );

$home = 'https://dev.test';
$saved = array( 'schema' => true, 'site_url' => 'https://public.test' );
$custom = '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Article","url":"https://dev.test/about/"}</script>';
$authored = str_replace( '</head>', $custom . '</head>', $html );
$rewritten = Distan_Urls::rewrite( $authored, $item['path'] )['html'];
check( str_contains( $rewritten, '"url":"https://public.test/about/"' ), 'Existing JSON-LD keeps original production URL rewriting' );
check( $rewritten === Distan_Schema::inject( $rewritten, $item ), 'Existing JSON-LD wins after rewrite pipeline' );
$marked = str_replace( '</head>', '<!-- distan:no-block-styles --><style id="wp-block-library-inline-css">.x{color:red}</style><!-- remove me --></head>', $html );
$rewritten = Distan_Urls::rewrite( $marked, $item['path'] )['html'];
$marked = Distan_Report::apply_page_markers( $rewritten, $item['path'] );
$clean = Distan_Cleaner::strip_comments( $marked );
$final = Distan_Schema::inject( $clean, $item );
check( ! str_contains( $final, 'wp-block-library' ) && ! str_contains( $final, '<!--' ), 'Markers and comment cleanup still work' );
check( null !== schema_data( $final ), 'Schema survives combined transformation pipeline' );
check( sha1( $clean ) !== sha1( $final ), 'Adding schema changes delivered content hash' );
echo "PASS: {$count} schema regression checks\n";
