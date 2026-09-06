<?php
/**
 * Plugin Name: Distan
 * Plugin URI:  https://github.com/okuboyouhei/distan
 * Description: dist で開発する、WordPress静的サイトジェネレーター。HTML納品案件のために、WordPressを制作環境として使い、余計なものを含まない静的HTMLを書き出します。
 * Version:     1.6.4
 * Author:      Youhei Okubo
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: distan
 * Requires at least: 6.0
 * Requires PHP: 8.0
 *
 * @package Distan
 */

defined( 'ABSPATH' ) || exit;

define( 'DISTAN_VERSION', '1.6.4' );
define( 'DISTAN_FILE', __FILE__ );
define( 'DISTAN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DISTAN_URL', plugin_dir_url( __FILE__ ) );

require_once DISTAN_DIR . 'includes/class-distan-paths.php';
require_once DISTAN_DIR . 'includes/class-distan-env.php';
require_once DISTAN_DIR . 'includes/class-distan-cleaner.php';
require_once DISTAN_DIR . 'includes/class-distan-urls.php';
require_once DISTAN_DIR . 'includes/class-distan-collector.php';
require_once DISTAN_DIR . 'includes/class-distan-generator.php';
require_once DISTAN_DIR . 'includes/class-distan-report.php';
require_once DISTAN_DIR . 'includes/class-distan-markdown.php';
require_once DISTAN_DIR . 'includes/class-distan-sitemap.php';
require_once DISTAN_DIR . 'includes/class-distan-sitemap-audit.php';
require_once DISTAN_DIR . 'includes/class-distan-takeup.php';
require_once DISTAN_DIR . 'includes/class-distan-admin.php';
require_once DISTAN_DIR . 'includes/class-distan-ajax.php';

/**
 * Create the output tree on activation, so that a "check" never has to.
 */
function distan_activate(): void {
	$uploads = wp_get_upload_dir();
	$base    = trailingslashit( $uploads['basedir'] ) . 'distan';

	wp_mkdir_p( $base . '/dist' );
	wp_mkdir_p( $base . '/work' );

	// Block directory listing on hosts that have it enabled. Deliberately
	// NOT applied to dist/: that directory is the deliverable root, and a
	// stray index.php would end up in the client's hands.
	foreach ( array( $base, $base . '/work' ) as $dir ) {
		$index = $dir . '/index.php';
		if ( is_dir( $dir ) && ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	if ( ! get_option( 'distan_render_secret' ) ) {
		add_option( 'distan_render_secret', wp_generate_password( 40, false ), '', false );
	}
}
register_activation_hook( __FILE__, 'distan_activate' );

/**
 * Plugin bootstrap.
 */
final class Distan {

	/**
	 * Singleton instance.
	 *
	 * @var Distan|null
	 */
	private static ?Distan $instance = null;

	/**
	 * Option key for all settings.
	 */
	public const OPTION_KEY = 'distan_settings';

	/**
	 * Retrieve the singleton.
	 */
	public static function instance(): Distan {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire everything up.
	 */
	private function __construct() {
		new Distan_Admin();
		new Distan_Ajax();

		// The cleaner only engages during a generation request. It must never
		// alter the live admin/preview output, so registration is deferred to
		// the request-detection check inside the class itself.
		Distan_Cleaner::maybe_engage();
	}

	/**
	 * Default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'site_url'      => '',      // Production URL. Used for canonical / OGP only.
			'path_style'    => 'directory', // 'directory' => /about/index.html, 'flat' => /about.html
			'link_style'    => 'relative',  // 'relative' => document-relative, 'absolute' => production URL
			'clean_html'    => true,
			'strip_noindex' => true,   // false keeps robots noindex (staging preview).
			'keep_indent'   => true,
			'export_markdown' => false, // Write a combined content.md for AI tools (Gemini Notebook, formerly NotebookLM).
			'export_markdown_local' => false, // Also write content.local.md keeping development URLs.
			'sitemap'         => false, // Write a standards-compliant sitemap.xml from the generated pages.
			'sitemap_exclude' => '',    // Newline-separated slug prefixes (/private/) or substrings (draft) to exclude.
			'robots'          => false, // Write a minimal robots.txt (Allow: /, plus Sitemap: when sitemap is on).
			'diff_zip'        => true,  // Offer the differential ZIP download (changed files only). Hashing runs regardless.
			'template_export' => true,  // Offer the per-page template ZIP (one page + only its referenced assets) for external handoff.
			'enable_dispatch' => false, // Show the manual "デプロイ" button that fires the distan_dispatch hook.
		);
	}

	/**
	 * Read settings merged with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function settings(): array {
		$saved = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array_merge( self::defaults(), $saved );
	}

	/**
	 * Capability required to operate the plugin.
	 */
	public static function capability(): string {
		/**
		 * Filter the capability required to run Distan.
		 *
		 * @param string $capability Capability name.
		 */
		return (string) apply_filters( 'distan_capability', 'manage_options' );
	}
}

add_action( 'plugins_loaded', array( 'Distan', 'instance' ) );
