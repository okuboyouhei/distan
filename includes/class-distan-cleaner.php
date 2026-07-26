<?php
/**
 * Clean HTML output.
 *
 * The deliverable is HTML that another human will open, read, and possibly
 * hand to a different agency two years from now. Anything that says
 * "this was WordPress" and serves no purpose in a static file is removed.
 *
 * Two passes:
 *   1. Head-level — unhook the actions that emit the markup in the first
 *      place. Always preferable: nothing is generated, nothing is stripped.
 *   2. Buffer-level — for markup that core prints unconditionally and cannot
 *      be unhooked. Deliberately conservative; regex over HTML is a last
 *      resort, so each pattern here is narrow and anchored.
 *
 * Everything engages only during a generation request, so the live site and
 * the block editor are untouched.
 *
 * @package Distan
 */

defined( 'ABSPATH' ) || exit;

/**
 * HTML cleaner.
 */
class Distan_Cleaner {

	/**
	 * $_SERVER key for the render marker.
	 */
	public const REQUEST_HEADER = 'HTTP_X_DISTAN_RENDER';

	/**
	 * Wire-format header name, as sent by the generator.
	 */
	public const HEADER_NAME = 'X-Distan-Render';

	/**
	 * Engage only when this request is a generation fetch.
	 */
	public static function maybe_engage(): void {
		if ( ! self::is_render_request() ) {
			return;
		}

		$settings = Distan::settings();
		if ( empty( $settings['clean_html'] ) ) {
			return;
		}

		add_action( 'wp', array( __CLASS__, 'strip_head_actions' ), 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'dequeue_assets' ), 100 );
		add_action( 'template_redirect', array( __CLASS__, 'start_buffer' ), 1 );

		// A development install almost always has "Discourage search engines"
		// switched on. Baking that noindex into a deliverable would remove the
		// production site from search results, so it is stripped by default.
		// Kept only when generating a staging preview that should stay out of
		// the index.
		if ( ! empty( $settings['strip_noindex'] ) ) {
			add_filter( 'wp_robots', array( __CLASS__, 'fix_robots' ), 999 );
			remove_filter( 'wp_robots', 'wp_robots_noindex' );
			remove_filter( 'wp_robots', 'wp_robots_noindex_search' );
		}

		// Speculative loading rules do nothing in a static site, and the
		// generated JSON names wp-admin, wp-content and the active theme.
		add_filter( 'wp_speculation_rules_configuration', '__return_null' );
	}

	/**
	 * Strip development-only robots directives.
	 *
	 * @param array<string, mixed> $robots Robots directives.
	 * @return array<string, mixed>
	 */
	public static function fix_robots( $robots ): array {
		if ( ! is_array( $robots ) ) {
			return array();
		}

		unset( $robots['noindex'], $robots['nofollow'], $robots['noarchive'] );

		/**
		 * Filter the robots directives written into generated pages.
		 *
		 * @param array<string, mixed> $robots Directives.
		 */
		return (array) apply_filters( 'distan_robots', $robots );
	}

	/**
	 * Whether the current request is a Distan render.
	 *
	 * Checked against a nonce-free shared secret in the header. The generator
	 * is same-origin and server-side, so this is an identification mechanism,
	 * not an authorization one — nothing privileged is exposed.
	 */
	public static function is_render_request(): bool {
		if ( empty( $_SERVER[ self::REQUEST_HEADER ] ) ) {
			return false;
		}

		$sent   = sanitize_text_field( wp_unslash( $_SERVER[ self::REQUEST_HEADER ] ) );
		$secret = get_option( 'distan_render_secret', '' );

		return '' !== $secret && hash_equals( (string) $secret, $sent );
	}

	/**
	 * Pass 1 — unhook everything core adds to wp_head that a static
	 * deliverable has no use for.
	 */
	public static function strip_head_actions(): void {
		$actions = self::head_actions();

		foreach ( $actions as $hook => $callbacks ) {
			foreach ( $callbacks as $callback => $priority ) {
				remove_action( $hook, $callback, $priority );
			}
		}

		// Emoji: several filters in addition to the head actions above.
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
		add_filter( 'emoji_svg_url', '__return_false' );
		add_filter(
			'tiny_mce_plugins',
			static function ( $plugins ) {
				return is_array( $plugins ) ? array_diff( $plugins, array( 'wpemoji' ) ) : $plugins;
			}
		);

		// Resource hints exist to speed up a live site; they are noise in a
		// deliverable and point at s.w.org.
		add_filter( 'wp_resource_hints', '__return_empty_array', 99 );

		// The REST API link header is sent even when the tag is removed.
		remove_action( 'template_redirect', 'rest_output_link_header', 11 );

		// XML-RPC advertisement.
		add_filter( 'wp_headers', array( __CLASS__, 'strip_headers' ) );
	}

	/**
	 * The removal list, as a filterable map of hook => [callback => priority].
	 *
	 * @return array<string, array<string, int>>
	 */
	public static function head_actions(): array {
		$actions = array(
			'wp_head' => array(
				// Identifies the CMS and its version.
				'wp_generator'                         => 10,
				// Legacy blog-client discovery.
				'rsd_link'                             => 10,
				'wlwmanifest_link'                     => 10,
				// Navigation rel links: meaningless once static.
				'adjacent_posts_rel_link_wp_head'      => 10,
				'start_post_rel_link'                  => 10,
				'index_rel_link'                       => 10,
				'parent_post_rel_link'                 => 10,
				// REST API discovery. Nothing to discover in a static site.
				'rest_output_link_wp_head'             => 10,
				// oEmbed discovery + the host JS bundle.
				'wp_oembed_add_discovery_links'        => 10,
				'wp_oembed_add_host_js'                => 10,
				// Feeds. Removed by default; re-added via filter if wanted.
				'feed_links'                           => 2,
				'feed_links_extra'                     => 3,
				// ?p=123 shortlink.
				'wp_shortlink_wp_head'                 => 10,
				// Emoji detection script and its styles.
				'print_emoji_detection_script'         => 7,
				'wp_print_styles'                      => 8,
			),
			'wp_print_styles' => array(
				'print_emoji_styles' => 10,
			),
			'admin_print_scripts' => array(
				'print_emoji_detection_script' => 10,
			),
			'wp_footer' => array(
				'wp_shortlink_wp_head' => 10,
			),
		);

		/**
		 * Filter the wp_head actions Distan removes during generation.
		 *
		 * @param array<string, array<string, int>> $actions Hook => callback => priority.
		 */
		return (array) apply_filters( 'distan_head_actions', $actions );
	}

	/**
	 * Dequeue stylesheets and scripts that only exist to support a dynamic
	 * WordPress front end.
	 *
	 * Note: block library styles are NOT removed by default. If the theme
	 * uses blocks, removing them breaks the layout. Site owners who write
	 * their own CSS can opt in via the filter.
	 */
	public static function dequeue_assets(): void {
		$handles = array(
			'wp-embed',              // The oEmbed iframe resizer.
			'classic-theme-styles',  // Injected for non-block themes.
		);

		/**
		 * Filter the asset handles dequeued during generation.
		 *
		 * @param array<int, string> $handles Script/style handles.
		 */
		$handles = (array) apply_filters( 'distan_dequeue_handles', $handles );

		foreach ( $handles as $handle ) {
			wp_dequeue_script( $handle );
			wp_deregister_script( $handle );
			wp_dequeue_style( $handle );
			wp_deregister_style( $handle );
		}

		// Global styles: emitted as a large inline <style> block. Kept by
		// default for the same reason as block library styles.
		if ( apply_filters( 'distan_remove_global_styles', false ) ) {
			wp_dequeue_style( 'global-styles' );
			remove_action( 'wp_enqueue_scripts', 'wp_enqueue_global_styles' );
			remove_action( 'wp_footer', 'wp_enqueue_global_styles', 1 );
		}
	}

	/**
	 * Remove response headers that advertise WordPress.
	 *
	 * @param array<string, string> $headers Headers.
	 * @return array<string, string>
	 */
	public static function strip_headers( $headers ): array {
		if ( ! is_array( $headers ) ) {
			return array();
		}

		unset( $headers['X-Pingback'] );
		unset( $headers['Link'] );

		return $headers;
	}

	/**
	 * Pass 2 — start buffering so the final HTML can be post-processed.
	 *
	 * Only ever runs on Distan's own render request (gated by
	 * is_render_request() in boot()), never for a normal visitor. The buffer
	 * is closed explicitly on shutdown so it is never left open for other
	 * code to trip over.
	 */
	public static function start_buffer(): void {
		ob_start( array( __CLASS__, 'filter_buffer' ) );
		add_action( 'shutdown', array( __CLASS__, 'close_buffer' ), 0 );
	}

	/**
	 * Close our buffer, if it is still the active one, before shutdown
	 * handlers run. Guards against leaving the buffer stack misaligned.
	 */
	public static function close_buffer(): void {
		if ( ob_get_level() > 0 ) {
			ob_end_flush();
		}
	}

	/**
	 * Post-process the complete document.
	 *
	 * Applied at the very end, for the same reason HXMC swaps image URLs at
	 * the final HTML stage: intervening earlier makes core bail out of work
	 * we still want it to do.
	 *
	 * @param string $html Full document.
	 */
	public static function filter_buffer( $html ): string {
		$html = (string) $html;

		if ( '' === trim( $html ) ) {
			return $html;
		}

		// Only touch documents, never JSON/XML responses.
		if ( ! preg_match( '#<html[\s>]#i', $html ) ) {
			return $html;
		}

		// Core annotates inlined block stylesheets with a sourceURL comment
		// carrying the development hostname. Harmless to a browser, but it
		// leaks shfe.local (or whatever the local host is) into a file that
		// gets handed to a client.
		$html = preg_replace( '~/\*#\s*sourceURL=[^*]*\*/~', '', $html );

		// Speculation rules, for cores that ignore the configuration filter.
		$html = preg_replace(
			'#<script[^>]+type=["\']speculationrules["\'][^>]*>.*?</script>\s*#is',
			'',
			$html
		);

		// Empty inline style blocks left behind by dequeued handles.
		$html = preg_replace(
			'#<style id=["\'][^"\']*-inline-css["\'][^>]*>\s*</style>\s*#i',
			'',
			$html
		);

		// The emoji settings object, if a plugin re-added it.
		$html = preg_replace(
			'#<script[^>]*>\s*window\._wpemojiSettings\s*=.*?</script>\s*#is',
			'',
			$html
		);

		// type="text/javascript" and type="text/css" are invalid-by-default
		// noise in HTML5.
		$html = preg_replace( '#\s+type=["\']text/(javascript|css)["\']#i', '', $html );

		// Collapse the run of blank lines that removals leave in <head>.
		$html = preg_replace( "#(\r?\n)[ \t]*(\r?\n){2,}#", "\n\n", $html );

		/**
		 * Filter the fully cleaned document before it is written to disk.
		 *
		 * @param string $html Cleaned HTML.
		 */
		return (string) apply_filters( 'distan_clean_html', $html );
	}
}
