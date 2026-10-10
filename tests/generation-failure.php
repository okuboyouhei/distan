<?php
if ( PHP_SAPI !== 'cli' ) { exit; }
require __DIR__ . '/schema-generator.php';
$test_uploads = sys_get_temp_dir() . '/distan-failure-' . bin2hex( random_bytes( 6 ) );
mkdir( $test_uploads . '/distan/dist', 0777, true );
mkdir( $test_uploads . '/distan/work', 0777, true );
$test_options = array();
$test_transients = array();
$saved = array( 'schema' => false, 'site_url' => 'https://public.test' );
function failure_item( $path, $fail = false ) { return array( 'url' => 'https://dev.test/' . ( $fail ? 'fail/' : 'ok/' ) . $path, 'path' => $path . '/index.html', 'label' => $path, 'type' => 'page' ); }
function run_fixture( $queue ) {
 set_transient( Distan_Generator::JOB_KEY, new_job( $queue ), 3600 );
 Distan_Generator::process_batch( 20 );
 return Distan_Generator::manifest();
}
$remote_html = '<html><head><title>Before</title></head><body>Original</body></html>';
$before = run_fixture( array( failure_item( 'a' ), failure_item( 'b' ), failure_item( 'removed' ) ) );
$remote_html = '<html><head><title>After</title></head><body>Updated</body></html>';
$failed = run_fixture( array( failure_item( 'a' ), failure_item( 'b', true ) ) );
check( is_file( $test_uploads . '/distan/dist/b/index.html' ) && is_file( $test_uploads . '/distan/dist/removed/index.html' ), 'Failure preserves existing files' );
check( empty( $failed['removed'] ), 'Failed pages must not be reported for production deletion' );
check( empty( $failed['complete'] ), 'Manifest explicitly marks an incomplete run' );
$again = run_fixture( array( failure_item( 'a' ), failure_item( 'b', true ) ) );
check( empty( $again['removed'] ), 'Repeated failures still suppress deletion advice' );
$fixed = run_fixture( array( failure_item( 'a' ), failure_item( 'b' ) ) );
check( array( 'removed/index.html' ) === $fixed['removed'], 'Retry compares with the last successful run' );
check( array( 'a/index.html', 'b/index.html' ) === $fixed['modified'], 'Changes written during a failed run still appear in successful retry' );
check( empty( $fixed['added'] ), 'Previously successful page is not misclassified as new' );
check( ! is_file( $test_uploads . '/distan/dist/removed/index.html' ), 'Successful retry cleans genuinely removed files' );
$diff = Distan_Report::build_diff_zip( $fixed );
$zip = new ZipArchive();
check( is_string( $diff ) && true === $zip->open( $diff ), 'Retry differential ZIP is available' );
check( false !== $zip->getFromName( 'a/index.html' ) && false !== $zip->getFromName( 'b/index.html' ), 'Retry ZIP includes every changed page' );
check( str_contains( $zip->getFromName( 'DELETE.txt' ), 'removed/index.html' ) && ! str_contains( $zip->getFromName( 'DELETE.txt' ), 'b/index.html' ), 'Delete list contains only genuine removals' );
$zip->close();
check( null === Distan_Report::build_diff_zip( $failed ), 'Incomplete run cannot produce differential ZIP' );
check( str_contains( Distan_Report::render( $failed ), '納品前に再生成' ), 'Report clearly flags incomplete output' );
check( 1 === count( $failed['errors'] ), 'Failure details persist after transient deletion' );
check( ! isset( $again['diff_baseline']['diff_baseline'] ), 'Repeated failure stores a flat baseline' );
check( true === $fixed['complete'] && ! isset( $fixed['diff_baseline'] ), 'Successful run discards fallback snapshot' );
foreach ( array( 'db', 'output' ) as $test_manifest_source ) {
 $test_uploads = sys_get_temp_dir() . '/distan-failure-' . bin2hex( random_bytes( 6 ) );
 mkdir( $test_uploads . '/distan/dist', 0777, true );
 mkdir( $test_uploads . '/distan/work', 0777, true );
 $test_options = array();
 $first = run_fixture( array( failure_item( 'new', true ) ) );
 check( empty( $first['diff_baseline'] ) && empty( $first['removed'] ), $test_manifest_source . ': initial failure has no successful baseline' );
 $success = run_fixture( array( failure_item( 'new' ), failure_item( 'old' ) ) );
 check( array( 'new/index.html', 'old/index.html' ) === $success['added'], $test_manifest_source . ': first success includes all pages' );
 // Simulate a manifest written by a previous version without completion fields.
 unset( $success['complete'], $success['errors'] );
 update_option( Distan_Generator::MANIFEST_KEY, $success );
 if ( 'output' === $test_manifest_source ) {
  Distan_Paths::write( Distan_Generator::MANIFEST_FILE, wp_json_encode( $success ) );
 }
 $all_failed = run_fixture( array( failure_item( 'new', true ) ) );
 check( empty( $all_failed['removed'] ) && $success['files'] === $all_failed['diff_baseline']['files'], $test_manifest_source . ': all-page failure preserves legacy baseline' );
 if ( 'output' === $test_manifest_source ) {
  // The portable record must survive losing the same-environment option.
  unset( $test_options[ Distan_Generator::MANIFEST_KEY ] );
 }
 $retry = run_fixture( array( failure_item( 'new' ) ) );
 check( array( 'old/index.html' ) === $retry['removed'] && empty( $retry['added'] ) && empty( $retry['modified'] ), $test_manifest_source . ': retry detects genuine removal and unchanged content' );
}
// Delivery boundaries: server-side rejection, success hook, and restored UI state.
function current_user_can( $cap ) { return true; }
function sanitize_text_field( $text ) { return $text; }
function wp_unslash( $value ) { return $value; }
function wp_verify_nonce( $value, $action ) { return true; }
class Fixture_Json extends RuntimeException {
 public $success;
 public function __construct( $success ) { parent::__construct( 'AJAX completed' ); $this->success = $success; }
}
function wp_send_json_error( $data, $status = null ) { throw new Fixture_Json( false ); }
function wp_send_json_success( $data ) { throw new Fixture_Json( true ); }
function fixture_dispatch() {
 $ajax = ( new ReflectionClass( Distan_Ajax::class ) )->newInstanceWithoutConstructor();
 try { $ajax->dispatch(); } catch ( Fixture_Json $result ) { return $result->success; }
 throw new RuntimeException( 'AJAX did not terminate' );
}
$saved['enable_dispatch'] = true;
foreach ( array( 'db', 'output' ) as $test_manifest_source ) {
 $test_actions = array();
 $base = run_fixture( array( failure_item( 'new' ), failure_item( 'old' ) ) );
 check( in_array( 'distan_after_generate', $test_actions, true ), $test_manifest_source . ': successful generation fires hook' );
 $test_actions = array();
 $failed_run = run_fixture( array( failure_item( 'new', true ) ) );
 check( ! in_array( 'distan_after_generate', $test_actions, true ), $test_manifest_source . ': failure does not fire delivery hook' );
 check( null === Distan_Report::build_zip( $failed_run ) && null === Distan_Report::build_template_zip( 'new/index.html', $failed_run ), $test_manifest_source . ': full and template ZIPs reject failure' );
 check( ! fixture_dispatch() && ! in_array( 'distan_dispatch', $test_actions, true ), $test_manifest_source . ': failed run cannot dispatch' );
 $client = $client_method->invoke( null );
 check( false === $client['complete'] && 1 === count( $client['errors'] ), $test_manifest_source . ': all-page failure survives UI reload' );
 $remote_html = '<html><head><title>Interrupted</title></head><body>New</body></html>';
 set_transient( Distan_Generator::JOB_KEY, new_job( array( failure_item( 'new' ), failure_item( 'old' ) ) ), 3600 );
 $state = Distan_Generator::process_batch( 1 );
 $interrupted = Distan_Generator::manifest();
 check( ! $state['done'] && false === $interrupted['complete'] && $base['files'] === $interrupted['diff_baseline']['files'], $test_manifest_source . ': partial batch marks delivery incomplete and retains success baseline' );
 delete_transient( Distan_Generator::JOB_KEY );
 check( null === Distan_Report::build_zip( Distan_Generator::manifest() ) && ! fixture_dispatch(), $test_manifest_source . ': transient expiry cannot reopen delivery' );
 $retried = run_fixture( array( failure_item( 'new' ) ) );
 check( array( 'old/index.html' ) === $retried['removed'] && array( 'new/index.html' ) === $retried['modified'], $test_manifest_source . ': interrupted retry retains actual diff' );
 check( fixture_dispatch() && in_array( 'distan_dispatch', $test_actions, true ), $test_manifest_source . ': successful retry reopens dispatch' );
 $remote_html = '<html><head><title>Reset</title></head><body>Baseline</body></html>';
}
// Inject queued assets directly to exercise unreadable and rejected destinations.
$source = $test_uploads . '/asset.bin';
file_put_contents( $source, 'asset bytes' );
$job = new_job( array( failure_item( 'new' ) ) );
$job['assets'] = array( 'missing.bin' => $source . '.missing', '../escape.bin' => $source, 'valid.bin' => $source );
set_transient( Distan_Generator::JOB_KEY, $job, 3600 );
Distan_Generator::process_batch( 20 );
$asset_failed = Distan_Generator::manifest();
check( false === $asset_failed['complete'] && 2 === count( $asset_failed['errors'] ), 'Missing source and failed asset copy mark generation incomplete' );
check( in_array( 'valid.bin', $asset_failed['files'], true ) && ! in_array( 'missing.bin', $asset_failed['files'], true ), 'Only successfully copied assets enter the output record' );
check( empty( $asset_failed['removed'] ) && null === Distan_Report::build_diff_zip( $asset_failed ), 'Asset failure suppresses deletion and differential ZIP' );
check( Distan_Report::is_complete( array( 'files' => array( 'legacy.html' ) ) ), 'Legacy completed manifests remain deliverable' );
function get_stylesheet_directory() { return ABSPATH . '/theme'; }
function get_template_directory() { return ABSPATH . '/theme'; }
$fixture_name = 'distan-assets-' . bin2hex( random_bytes( 6 ) );
$fixture_dir = ABSPATH . '/' . $fixture_name;
mkdir( $fixture_dir );
try {
 file_put_contents( $fixture_dir . '/style.css', 'body { background: url(image.svg); }' );
 file_put_contents( $fixture_dir . '/image.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>' );
 $job = new_job( array( failure_item( 'new' ) ) );
 $job['assets'] = array( $fixture_name . '/style.css' => $fixture_dir . '/style.css' );
 set_transient( Distan_Generator::JOB_KEY, $job, 3600 );
 Distan_Generator::process_batch( 20 );
 $css_result = Distan_Generator::manifest();
 check( true === $css_result['complete'] && in_array( $fixture_name . '/image.svg', $css_result['files'], true ), 'CSS-discovered image is included in completed manifest' );
 check( is_file( Distan_Paths::output_root() . '/' . $fixture_name . '/image.svg' ), 'Cleanup retains CSS-discovered image' );
 $css_zip_path = Distan_Report::build_zip( $css_result );
 $css_zip = new ZipArchive();
 check( is_string( $css_zip_path ) && true === $css_zip->open( $css_zip_path ), 'Successful asset run creates full ZIP' );
 check( false !== $css_zip->getFromName( $fixture_name . '/image.svg' ), 'Delivered ZIP includes CSS-discovered image' );
 $css_zip->close();
} finally {
 unlink( $fixture_dir . '/style.css' );
 unlink( $fixture_dir . '/image.svg' );
 rmdir( $fixture_dir );
}
echo "PASS: {$count} checks including failure/retry consistency\n";
