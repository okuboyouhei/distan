<?php
// Development-only test entry point; never run from a web request.
if ( PHP_SAPI !== 'cli' ) { http_response_code( 403 ); exit; }
/** Batch -> output -> manifest -> report/ZIP integration with isolated WP stubs. */
require __DIR__ . '/schema-audit.php';
define( 'HOUR_IN_SECONDS', 3600 );
$test_uploads = sys_get_temp_dir() . '/distan-schema-' . bin2hex( random_bytes( 6 ) );
mkdir( $test_uploads . '/distan/dist', 0777, true );
mkdir( $test_uploads . '/distan/work', 0777, true );
$test_options = array();
$test_transients = array();
$test_actions = array();
$remote_html = '<html lang="ja"><head><title>Integration</title></head><body>Test</body></html>';
function wp_get_upload_dir() { global $test_uploads; return array( 'basedir' => $test_uploads ); }
function wp_mkdir_p( $path ) { return is_dir( $path ) || mkdir( $path, 0777, true ); }
function validate_file( $file ) { return str_contains( $file, '..' ) ? 1 : 0; }
function update_option( $key, $value, $autoload = null ) { global $test_options; $test_options[ $key ] = $value; return true; }
function get_transient( $key ) { global $test_transients; return $test_transients[ $key ] ?? false; }
function set_transient( $key, $value, $expires ) { global $test_transients; $test_transients[ $key ] = $value; }
function delete_transient( $key ) { global $test_transients; unset( $test_transients[ $key ] ); }
function wp_remote_get( $url, $args ) { global $remote_html; return array( 'code' => str_contains( $url, '/fail/' ) ? 500 : 200, 'body' => $remote_html ); }
function is_wp_error( $response ) { return false; }
function wp_remote_retrieve_response_code( $response ) { return $response['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function get_bloginfo( $key ) { return 'Integration'; }
function get_date_from_gmt( $date, $format ) { return gmdate( $format, strtotime( $date . ' UTC' ) ); }
function do_action( $name, ...$args ) { global $test_actions; $test_actions[] = $name; }
function sanitize_title( $value ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_-]/', '-', $value ) ); }
function wp_delete_file_from_directory( $path, $root ) { return unlink( $path ); }
function get_current_user_id() { return 1; }
function new_job( array $queue ): array {
	return array( 'queue' => $queue, 'index' => 0, 'total' => count( $queue ), 'written' => array(), 'hashes' => array(), 'schema_pages' => array(), 'assets' => array(), 'errors' => array(), 'md_started' => false, 'started' => time(), 'heartbeat' => time(), 'user_id' => 1 );
}
$home = 'https://dev.test';
$saved = array( 'schema' => true, 'site_url' => 'https://public.test' );
$queue = array(
	array( 'url' => $home . '/ok/', 'path' => 'ok/index.html', 'type' => 'page', 'label' => 'OK' ),
	array( 'url' => $home . '/fail/', 'path' => 'fail/index.html', 'type' => 'page', 'label' => 'Fail' ),
);
set_transient( Distan_Generator::JOB_KEY, new_job( $queue ), 3600 );
$state = Distan_Generator::process_batch( 1 );
check( ! $state['done'] && 1 === $state['written'], 'Real batch writes successful page' );
$job = get_transient( Distan_Generator::JOB_KEY );
check( 'added' === $job['schema_pages']['ok/index.html']['status'], 'Batch persists diagnostic across requests' );
$bytes = file_get_contents( $test_uploads . '/distan/dist/ok/index.html' );
check( str_contains( $bytes, 'id="distan-schema"' ) && sha1( $bytes ) === $job['hashes']['ok/index.html'], 'Actual file, schema and recorded hash agree' );
$state = Distan_Generator::process_batch( 1 );
check( $state['done'] && 1 === count( $state['errors'] ), 'Failed page does not abort remaining report workflow' );
$manifest = Distan_Generator::manifest();
check( 1 === $manifest['schema_audit']['counts']['added'] && 1 === $manifest['schema_audit']['counts']['failed'] && 1 === $manifest['schema_audit']['incomplete_pages'], 'Manifest marks failed page as uninspected' );
$report = file_get_contents( get_option( 'distan_last_report' ) );
check( str_contains( $report, '構造化データの診断' ) && str_contains( $report, 'ページ生成失敗・未検査' ), 'Saved Markdown contains diagnostic outcomes' );
$client_method = new ReflectionMethod( Distan_Admin::class, 'client_manifest' );
$client_method->setAccessible( true );
$client = $client_method->invoke( null );
check( 2 === $client['schema_diagnostics']['total'] && 1 === $client['schema_diagnostics']['incomplete_pages'], 'Reloaded admin uses persisted diagnostic snapshot' );
$zip_path = Distan_Report::build_zip( $manifest );
$zip = new ZipArchive();
check( is_string( $zip_path ) && true === $zip->open( $zip_path ), 'Full ZIP created from actual generation' );
check( $bytes === $zip->getFromName( 'ok/index.html' ) && str_contains( $zip->getFromName( 'distan-report.md' ), '構造化データの診断' ), 'ZIP preserves output and includes audit report' );
$zip->close();
$saved = array( 'schema' => false, 'site_url' => 'https://different.test' );
check( $report === Distan_Report::render( $manifest ), 'Changing settings does not change report from completed run' );
set_transient( Distan_Generator::JOB_KEY, new_job( array( $queue[0] ) ), 3600 );
Distan_Generator::process_batch( 1 );
$next = Distan_Generator::manifest();
check( array( 'ok/index.html' ) === $next['modified'] && 'disabled' === $next['schema_audit']['pages']['ok/index.html']['status'], 'Switching off changes page hash and records disabled outcome' );
check( ! str_contains( file_get_contents( $test_uploads . '/distan/dist/ok/index.html' ), 'distan-schema' ), 'Only auto-added markup disappears from file' );
$diff = Distan_Report::build_diff_zip( $next );
$zip = new ZipArchive();
check( is_string( $diff ) && true === $zip->open( $diff ) && false !== $zip->getFromName( 'ok/index.html' ), 'Differential ZIP contains changed page' );
check( str_contains( $zip->getFromName( 'distan-diff.md' ), '構造化データの診断' ), 'Differential delivery note includes shipped-page diagnostics' );
$zip->close();
$template = Distan_Report::build_template_zip( 'ok/index.html', $next );
$zip = new ZipArchive();
check( is_string( $template ) && true === $zip->open( $template ), 'Template ZIP created' );
check( str_contains( $zip->getFromName( 'README.md' ), '構造化データの診断' ) && str_contains( $zip->getFromName( 'README.md' ), 'ページ固有情報' ), 'Template instructions preserve original audit and require metadata update' );
$zip->close();
echo "PASS: {$count} total checks; isolated artifacts: {$test_uploads}\n";
