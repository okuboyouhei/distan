<?php
/**
 * URL mapping and rewriting.
 *
 * The conversion layer. Two responsibilities:
 *
 *   1. Map a site URL to an output file path.
 *   2. Rewrite every same-origin URL in a rendered document so it points at
 *      that file path, expressed relative to the document doing the pointing.
 *
 * Document-relative is the default because the deliverable gets unzipped and
 * opened by double-click. Under file:// a root-relative href resolves against
 * the filesystem root and dies. The two exceptions are canonical and OGP,
 * which the specs require to be absolute.
 *
 * Links always name the file explicitly (../about/index.html rather than
 * ../about/). A bare directory reference relies on a webserver index, which
 * file:// does not provide.
 *
 * @package Distan
 */

defined( 'ABSPATH' ) || exit;

/**
 * URL/path conversion.
 */
class Distan_Urls {

	/**
	 * Same-origin asset URLs discovered during the last rewrite.
	 *
	 * @var array<string, string> Output relative path => absolute source path.
	 */
	private static array $asset_queue = array();

	/**
	 * Home URL without a trailing slash, e.g. http://shfe.local or
	 * http://shfe.local/sub.
	 */
	public static function home_base(): string {
		return untrailingslashit( home_url() );
	}

	/**
	 * The path portion of home_url, e.g. '' or '/sub'.
	 */
	public static function home_path(): string {
		$path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		return untrailingslashit( $path );
	}

	/**
	 * Map a site URL to its output relative path.
	 *
	 * Returns null for anything outside the site or otherwise unmappable.
	 */
	public static function url_to_output_path( string $url ): ?string {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) ) {
			return null;
		}

		// Reject other origins.
		if ( isset( $parts['host'] ) ) {
			$home = wp_parse_url( home_url( '/' ) );
			if ( empty( $home['host'] ) || strtolower( $parts['host'] ) !== strtolower( $home['host'] ) ) {
				return null;
			}
		}

		$path = isset( $parts['path'] ) ? $parts['path'] : '/';

		// Strip the subdirectory install prefix.
		$prefix = self::home_path();
		if ( '' !== $prefix ) {
			if ( ! str_starts_with( $path, $prefix . '/' ) && $path !== $prefix ) {
				return null;
			}
			$path = substr( $path, strlen( $prefix ) );
		}

		$path = '/' . ltrim( (string) $path, '/' );

		// A path with a file extension is an asset: remap known directories.
		if ( self::looks_like_file( $path ) ) {
			return self::remap_asset( ltrim( rawurldecode( $path ), '/' ) );
		}

		return self::page_path_for( $path );
	}

	/**
	 * Remap an asset path, stripping WordPress-specific prefixes.
	 *
	 * Theme directories collapse to their own root (mytheme/assets/x ->
	 * assets/x); uploads move under a neutral directory (wp-content/uploads/
	 * 2026/07/x -> media/2026/07/x). Both remove the "this is WordPress"
	 * signal from the deliverable. The uploads year/month structure is kept so
	 * that same-named files from different months cannot collide.
	 */
	public static function remap_asset( string $relative ): string {
		return self::flatten_uploads( self::flatten_theme( $relative ) );
	}

	/**
	 * Uploads base directory as it appears in URLs, relative to the site root.
	 *
	 * Derived from the uploads base URL rather than the filesystem path, so it
	 * holds even when uploads live outside ABSPATH (a custom UPLOADS location,
	 * a CDN offload rewriting URLs, etc.). Used only to recognise and remap
	 * upload URLs in generated HTML — never for writing to disk.
	 */
	public static function uploads_dir(): string {
		$uploads  = wp_get_upload_dir();
		$base_url = isset( $uploads['baseurl'] ) ? (string) $uploads['baseurl'] : '';
		$home     = home_url();

		// Prefer the path portion of the uploads URL relative to home.
		if ( '' !== $base_url ) {
			$base_path = (string) wp_parse_url( $base_url, PHP_URL_PATH );
			$home_path = (string) wp_parse_url( $home, PHP_URL_PATH );

			if ( '' !== $base_path ) {
				$relative = $base_path;
				if ( '' !== $home_path && str_starts_with( $base_path, $home_path ) ) {
					$relative = substr( $base_path, strlen( $home_path ) );
				}
				$relative = trim( $relative, '/' );
				if ( '' !== $relative ) {
					return $relative;
				}
			}
		}

		return 'wp-content/uploads';
	}

	/**
	 * Move an uploads path under the neutral output directory.
	 *
	 * wp-content/uploads/2026/07/photo.webp -> media/2026/07/photo.webp
	 *
	 * Named "media" rather than "images" because the uploads directory holds
	 * whatever was uploaded — PDFs, archives, documents — and routing a
	 * recruitment PDF into a folder called images looks like sloppy work to
	 * whoever opens the deliverable. The word also matches WordPress's own
	 * Media Library, so the mapping reads as intentional.
	 */
	public static function flatten_uploads( string $relative ): string {
		/**
		 * Filter the output directory that uploads are mapped into.
		 * Return an empty string to leave uploads paths untouched.
		 *
		 * @param string $dir Defaults to 'media'.
		 */
		$target = (string) apply_filters( 'distan_uploads_dir', 'media' );
		$target = trim( $target, '/' );

		if ( '' === $target ) {
			return $relative;
		}

		$uploads = self::uploads_dir();

		if ( str_starts_with( $relative, $uploads . '/' ) ) {
			return $target . '/' . substr( $relative, strlen( $uploads ) + 1 );
		}

		return $relative;
	}

	/**
	 * Theme directories, relative to ABSPATH.
	 *
	 * Stylesheet first: with a child theme active, a file present in both
	 * should resolve to the child's copy, which is what the browser gets.
	 *
	 * @return array<int, string>
	 */
	public static function theme_dirs(): array {
		$root = Distan_Paths::normalize( untrailingslashit( ABSPATH ) );
		$dirs = array();

		foreach ( array( get_stylesheet_directory(), get_template_directory() ) as $dir ) {
			$dir = Distan_Paths::normalize( (string) $dir );

			if ( ! str_starts_with( $dir, $root . '/' ) ) {
				continue;
			}

			$relative = ltrim( substr( $dir, strlen( $root ) ), '/' );

			if ( '' !== $relative && ! in_array( $relative, $dirs, true ) ) {
				$dirs[] = $relative;
			}
		}

		return $dirs;
	}

	/**
	 * Strip the theme directory prefix from an asset path.
	 *
	 * wp-content/themes/mytheme/assets/img/logo.svg -> assets/img/logo.svg
	 *
	 * The theme's own structure becomes the deliverable's structure, which is
	 * both tidier and one less place the client can tell this was WordPress.
	 * Safe to flatten because exactly one theme is ever active; uploads are
	 * deliberately left alone, since filenames there can collide across months.
	 */
	public static function flatten_theme( string $relative ): string {
		/**
		 * Filter whether theme asset paths are flattened.
		 *
		 * @param bool $enabled Defaults to true.
		 */
		if ( ! apply_filters( 'distan_flatten_theme', true ) ) {
			return $relative;
		}

		foreach ( self::theme_dirs() as $dir ) {
			if ( str_starts_with( $relative, $dir . '/' ) ) {
				return substr( $relative, strlen( $dir ) + 1 );
			}
		}

		return $relative;
	}

	/**
	 * Output path for a page URL path (no extension).
	 */
	public static function page_path_for( string $path ): string {
		$settings = Distan::settings();
		$flat     = 'flat' === $settings['path_style'];

		$trimmed = trim( rawurldecode( $path ), '/' );

		if ( '' === $trimmed ) {
			return 'index.html';
		}

		return $flat ? $trimmed . '.html' : $trimmed . '/index.html';
	}

	/**
	 * Whether a path names a file rather than a page.
	 */
	private static function looks_like_file( string $path ): bool {
		$basename = basename( $path );

		if ( ! str_contains( $basename, '.' ) ) {
			return false;
		}

		$ext = strtolower( (string) pathinfo( $basename, PATHINFO_EXTENSION ) );

		// Treat .html/.htm as pages already in final form.
		return '' !== $ext && ! in_array( $ext, array( 'html', 'htm' ), true );
	}

	/**
	 * The prefix prepended to every internal target.
	 *
	 * In relative mode this is the "../../" climb from the document to the
	 * output root. In absolute mode it is the production URL, which makes the
	 * output independent of where in the tree a file sits — useful when the
	 * generated copy is being kept as a fallback and may need to be dropped
	 * straight into a document root during an outage.
	 *
	 * Absolute mode silently falls back to relative when no production URL is
	 * configured, because emitting links to an empty origin would be worse
	 * than emitting relative ones.
	 */
	public static function prefix_for( string $document_path, bool $force_absolute = false ): string {
		$settings = Distan::settings();

		if ( $force_absolute || 'absolute' === $settings['link_style'] ) {
			$base = trim( (string) $settings['site_url'] );

			if ( '' !== $base ) {
				return trailingslashit( $base );
			}
		}

		$depth = substr_count( trim( $document_path, '/' ), '/' );

		return 0 === $depth ? '' : str_repeat( '../', $depth );
	}

	/**
	 * Rewrite a rendered document for a given output path.
	 *
	 * @param string $html            Rendered HTML.
	 * @param string $document_path   Output relative path of this document.
	 * @param bool   $force_absolute  Ignore the configured link style.
	 * @return array{html: string, assets: array<string, string>}
	 */
	public static function rewrite( string $html, string $document_path, bool $force_absolute = false ): array {
		self::$asset_queue = array();

		$prefix = self::prefix_for( $document_path, $force_absolute );

		// Canonical and OGP must stay absolute, so pull them out first and
		// restore them after the sweep.
		$html = self::protect_absolute_tags( $html );

		$attributes = array( 'href', 'src', 'action', 'poster', 'data-src' );

		foreach ( $attributes as $attribute ) {
			$html = (string) preg_replace_callback(
				'#(\s' . preg_quote( $attribute, '#' ) . '=)(["\'])(.*?)\2#is',
				static function ( $matches ) use ( $prefix ) {
					$converted = self::convert_single( $matches[3], $prefix );
					return $matches[1] . $matches[2] . $converted . $matches[2];
				},
				$html
			);
		}

		// srcset and imagesrcset carry comma-separated candidates.
		$html = (string) preg_replace_callback(
			'#(\s(?:srcset|imagesrcset)=)(["\'])(.*?)\2#is',
			static function ( $matches ) use ( $prefix ) {
				return $matches[1] . $matches[2] . self::convert_srcset( $matches[3], $prefix ) . $matches[2];
			},
			$html
		);

		// url() inside inline styles.
		$html = (string) preg_replace_callback(
			'#url\(\s*([\'"]?)([^)\'"]+)\1\s*\)#i',
			static function ( $matches ) use ( $prefix ) {
				$converted = self::convert_single( $matches[2], $prefix );
				return 'url(' . $matches[1] . $converted . $matches[1] . ')';
			},
			$html
		);

		// The import map is JSON inside a <script>, so the attribute sweep
		// above never sees it. Left alone it keeps the development origin.
		$html = (string) preg_replace_callback(
			'#(<script[^>]+type=["\']importmap["\'][^>]*>)(.*?)(</script>)#is',
			static function ( $matches ) use ( $prefix ) {
				return $matches[1] . self::convert_in_text( $matches[2], $prefix ) . $matches[3];
			},
			$html
		);

		$html = self::restore_absolute_tags( $html );

		// Apply user-defined replacement pairs last, across the whole document
		// (body URLs, JSON-LD, everything). This runs after the site_url swap
		// so custom rules can target the already-production URLs, and it is
		// applied in definition order so callers can put longer, more specific
		// patterns first.
		$html = self::apply_custom_replacements( $html );

		return array(
			'html'   => $html,
			'assets' => self::$asset_queue,
		);
	}

	/**
	 * User-defined replacement pairs, applied in definition order.
	 *
	 * Filter `distan_url_replacements` returns an associative array of
	 * search => replace pairs. Intended for production URL adjustments the
	 * base site_url swap does not cover — CDN-hosted assets, staging-only
	 * paths, logo/OG image URLs in structured data, and so on. Kept in code
	 * (not the admin UI) on purpose, so the rules live in version control and
	 * travel with the project.
	 *
	 * @return array<string, string>
	 */
	private static function custom_replacements(): array {
		$pairs = apply_filters( 'distan_url_replacements', array() );

		if ( ! is_array( $pairs ) ) {
			return array();
		}

		$clean = array();

		foreach ( $pairs as $from => $to ) {
			// Keys must be non-empty strings; values must be strings.
			if ( ! is_string( $from ) || '' === $from || ! is_string( $to ) ) {
				continue;
			}
			$clean[ $from ] = $to;
		}

		return $clean;
	}

	/**
	 * Run the custom replacement pairs over a blob of text.
	 *
	 * Definition order is preserved (PHP associative arrays are ordered), so
	 * a caller listing a longer pattern before a shorter overlapping one gets
	 * the longer match applied first.
	 */
	private static function apply_custom_replacements( string $text ): string {
		$pairs = self::custom_replacements();

		if ( empty( $pairs ) ) {
			return $text;
		}

		return str_replace( array_keys( $pairs ), array_values( $pairs ), $text );
	}

	/**
	 * Convert one URL. Returns the original if it should not be touched.
	 */
	private static function convert_single( string $url, string $prefix ): string {
		$url = trim( $url );

		if ( '' === $url ) {
			return $url;
		}

		// Leave fragments, mailto:, tel:, data:, javascript:, protocol-relative
		// external URLs and anything already relative to itself.
		if ( str_starts_with( $url, '#' ) ) {
			return $url;
		}
		if ( preg_match( '#^(mailto:|tel:|data:|javascript:|//)#i', $url ) ) {
			return $url;
		}

		$fragment = '';
		if ( str_contains( $url, '#' ) ) {
			[ $url, $fragment ] = explode( '#', $url, 2 );
			$fragment           = '#' . $fragment;
		}

		// Query strings are stripped from page URLs: a static file cannot
		// serve /search/?q=foo. They are KEPT on asset URLs, so that
		// cache-busting written by the theme — filemtime(), ?ver=, ?v= —
		// survives. With filename-hashing rejected (it breaks FTP overwrite),
		// the query is the only cache-buster that works for an overwrite-based
		// deployment, and no internal asset query is worth discarding.
		$query = '';
		if ( str_contains( $url, '?' ) ) {
			[ $url, $query ] = explode( '?', $url, 2 );
		}

		if ( '' === $url ) {
			return $fragment;
		}

		// Absolute same-origin, or root-relative. Anything else is external
		// or already relative, and is left alone.
		$is_absolute = preg_match( '#^https?://#i', $url );

		if ( ! $is_absolute && ! str_starts_with( $url, '/' ) ) {
			return $url . ( '' !== $query ? '?' . $query : '' ) . $fragment;
		}

		if ( ! $is_absolute ) {
			$url = self::home_base() . $url;
			// Re-check: a root-relative path outside the subdirectory install
			// is not ours.
			if ( '' !== self::home_path() && ! str_starts_with( $url, self::home_base() ) ) {
				return $url . $fragment;
			}
		}

		$target = self::url_to_output_path( $url );

		if ( null === $target ) {
			return $url . ( '' !== $query ? '?' . $query : '' ) . $fragment;
		}

		self::maybe_queue_asset( $target );

		// Keep the query only for real files (assets); drop it for pages.
		$keep_query = '' !== $query && self::looks_like_file( '/' . $target );

		return $prefix . $target . ( $keep_query ? '?' . $query : '' ) . $fragment;
	}

	/**
	 * Convert a srcset attribute value.
	 */
	private static function convert_srcset( string $value, string $prefix ): string {
		$candidates = array_map( 'trim', explode( ',', $value ) );
		$converted  = array();

		foreach ( $candidates as $candidate ) {
			if ( '' === $candidate ) {
				continue;
			}

			$bits       = preg_split( '#\s+#', $candidate, 2 );
			$url        = $bits[0];
			$descriptor = isset( $bits[1] ) ? ' ' . $bits[1] : '';

			$converted[] = self::convert_single( $url, $prefix ) . $descriptor;
		}

		return implode( ', ', $converted );
	}

	/**
	 * Convert every same-origin absolute URL inside a blob of text.
	 *
	 * Used for JSON and script payloads, where URLs are not in attributes.
	 */
	private static function convert_in_text( string $text, string $prefix ): string {
		$base = preg_quote( self::home_base(), '#' );

		return (string) preg_replace_callback(
			'#' . $base . '[^"\'\s\\\\]*#',
			static function ( $matches ) use ( $prefix ) {
				return self::convert_single( $matches[0], $prefix );
			},
			$text
		);
	}

	/**
	 * Record an asset for copying, if it resolves to a real file.
	 */
	private static function maybe_queue_asset( string $target ): void {
		if ( ! self::looks_like_file( '/' . $target ) ) {
			return;
		}

		if ( isset( self::$asset_queue[ $target ] ) ) {
			return;
		}

		// Never copy anything executable into a deliverable. wp-comments-post.php
		// arrives here via the comment form's action attribute; a static site
		// has no use for it and the client should not receive PHP.
		$ext = strtolower( (string) pathinfo( $target, PATHINFO_EXTENSION ) );

		$blocked = array( 'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'phar', 'htaccess', 'cgi', 'pl', 'py', 'sh' );

		/**
		 * Filter the file extensions that are never copied into the output.
		 *
		 * @param array<int, string> $blocked Lowercase extensions.
		 */
		$blocked = (array) apply_filters( 'distan_blocked_extensions', $blocked );

		if ( in_array( $ext, $blocked, true ) ) {
			return;
		}

		$source = self::source_for( $target );

		if ( null === $source ) {
			return;
		}

		self::$asset_queue[ $target ] = $source;
	}

	/**
	 * Locate the file on disk that an output path came from.
	 *
	 * Flattening throws away the theme prefix, so the lookup has to try it
	 * again from the other direction: the literal path first (uploads,
	 * wp-includes), then inside each theme directory.
	 */
	private static function source_for( string $target ): ?string {
		$root = Distan_Paths::normalize( untrailingslashit( ABSPATH ) );

		$candidates = array( $root . '/' . $target );

		// Reverse the theme flattening.
		foreach ( self::theme_dirs() as $dir ) {
			$candidates[] = $root . '/' . $dir . '/' . $target;
		}

		// Reverse the uploads remapping: media/2026/07/x -> uploads/2026/07/x.
		$upload_target = trim( (string) apply_filters( 'distan_uploads_dir', 'media' ), '/' );

		if ( '' !== $upload_target && str_starts_with( $target, $upload_target . '/' ) ) {
			$remainder    = substr( $target, strlen( $upload_target ) + 1 );
			$candidates[] = $root . '/' . self::uploads_dir() . '/' . $remainder;
		}

		foreach ( $candidates as $candidate ) {
			if ( is_file( $candidate ) ) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * Placeholder token for tags that must keep absolute URLs.
	 */
	private const TOKEN = '<!--HGP_ABS_%d-->';

	/**
	 * Stashed absolute tags.
	 *
	 * @var array<int, string>
	 */
	private static array $stash = array();

	/**
	 * Replace canonical/OGP/JSON-LD blocks with tokens before rewriting.
	 */
	private static function protect_absolute_tags( string $html ): string {
		self::$stash = array();

		// These match tags in generated output so their URLs can be preserved
		// as absolute; the tag names are assembled from fragments so they do
		// not read as markup this plugin emits.
		$link_tag     = '<' . 'link';
		$meta_tag     = '<' . 'meta';
		$open_script  = '<' . 'script';
		$close_script = '</' . 'script>';

		$patterns = array(
			// link rel="canonical"
			'#' . $link_tag . '[^>]+rel=["\']canonical["\'][^>]*>#i',
			// link rel="alternate"
			'#' . $link_tag . '[^>]+rel=["\']alternate["\'][^>]*>#i',
			// OGP / Twitter meta tags carrying URLs.
			'#' . $meta_tag . '[^>]+(?:property|name)=["\'](?:og:[^"\']*|twitter:[^"\']*)["\'][^>]*>#i',
			// Structured data.
			'#' . $open_script . '[^>]+type=["\']application/ld\+json["\'][^>]*>.*?' . $close_script . '#is',
		);

		foreach ( $patterns as $pattern ) {
			$html = (string) preg_replace_callback(
				$pattern,
				static function ( $matches ) {
					$index                 = count( self::$stash );
					self::$stash[ $index ] = self::absolutize( $matches[0] );
					return sprintf( self::TOKEN, $index );
				},
				$html
			);
		}

		return $html;
	}

	/**
	 * Swap the development origin for the production URL inside a stashed tag.
	 */
	/**
	 * Convert development URLs to production URLs in an arbitrary blob of text.
	 *
	 * Applies the same two-stage swap used for HTML output — the site_url
	 * domain swap, then the user-defined `distan_url_replacements` pairs — but
	 * for plain text such as the Markdown export. Returns the text unchanged
	 * when no site_url is configured (nothing to swap to).
	 */
	public static function to_public_urls( string $text ): string {
		$settings = Distan::settings();
		$site     = trim( (string) $settings['site_url'] );

		if ( '' !== $site ) {
			$text = str_replace(
				array( trailingslashit( home_url( '/' ) ), self::home_base() ),
				array( trailingslashit( $site ), untrailingslashit( $site ) ),
				$text
			);
		}

		return self::apply_custom_replacements( $text );
	}

	private static function absolutize( string $fragment ): string {
		$settings = Distan::settings();
		$site     = trim( (string) $settings['site_url'] );

		if ( '' === $site ) {
			return $fragment;
		}

		return str_replace(
			array( trailingslashit( home_url( '/' ) ), self::home_base() ),
			array( trailingslashit( $site ), untrailingslashit( $site ) ),
			$fragment
		);
	}

	/**
	 * Put the protected tags back.
	 */
	private static function restore_absolute_tags( string $html ): string {
		foreach ( self::$stash as $index => $original ) {
			$html = str_replace( sprintf( self::TOKEN, $index ), $original, $html );
		}

		self::$stash = array();

		return $html;
	}
}
