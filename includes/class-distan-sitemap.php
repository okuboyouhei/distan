<?php
/**
 * Sitemap generation.
 *
 * Builds a standards-compliant sitemap.xml from the pages Distan actually
 * wrote, using the production URL. Because it draws only on the generated
 * queue — front page, singles, page/CPT, post and term archives — it never
 * lists author or date archives or query-string URLs, so IDs such as
 * ?author=1 cannot leak into it. Entries can be excluded by slug prefix or
 * substring, via a setting or the distan_sitemap_exclude filter.
 *
 * @package Distan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sitemap builder.
 */
class Distan_Sitemap {

	/**
	 * Output filename, at the dist root.
	 */
	public const FILENAME = 'sitemap.xml';

	/**
	 * Whether sitemap output is switched on in settings.
	 */
	public static function is_enabled(): bool {
		$settings = Distan::settings();

		return ! empty( $settings['sitemap'] );
	}

	/**
	 * Build and write sitemap.xml for a finished run.
	 *
	 * @param array $queue The generation queue (each item: url, path, type).
	 * @return string|null Relative path written, or null when nothing to write.
	 */
	public static function write( array $queue ): ?string {
		if ( ! self::is_enabled() ) {
			return null;
		}

		$base    = self::production_base();
		$exclude = self::exclude_patterns();

		$entries = array();
		$seen    = array();

		foreach ( $queue as $item ) {
			// Only real pages. The 404 template has no canonical URL and must
			// never appear in a sitemap.
			if ( ! isset( $item['type'] ) || 'page' !== $item['type'] ) {
				continue;
			}

			$source = isset( $item['url'] ) ? (string) $item['url'] : '';
			if ( '' === $source ) {
				continue;
			}

			$loc = self::to_production( $source, $base );

			if ( isset( $seen[ $loc ] ) ) {
				continue;
			}

			if ( self::is_excluded( $loc, $exclude ) ) {
				continue;
			}

			$seen[ $loc ] = true;

			$entries[] = array(
				'loc'     => $loc,
				'lastmod' => self::lastmod_for( $source ),
			);
		}

		/**
		 * Filter the final sitemap entries.
		 *
		 * @param array $entries List of array{loc:string, lastmod:string}.
		 * @param array $queue   The generation queue.
		 */
		$entries = (array) apply_filters( 'distan_sitemap_entries', $entries, $queue );

		if ( empty( $entries ) ) {
			return null;
		}

		$xml = self::render( $entries );

		if ( Distan_Paths::write( self::FILENAME, $xml ) ) {
			return self::FILENAME;
		}

		return null;
	}

	/**
	 * Production base URL (no trailing slash). Falls back to the site's own
	 * URL when no production URL is set, mirroring the rest of Distan.
	 */
	private static function production_base(): string {
		$settings = Distan::settings();
		$configured = isset( $settings['site_url'] ) ? trim( (string) $settings['site_url'] ) : '';

		$base = '' !== $configured ? $configured : home_url();

		return untrailingslashit( $base );
	}

	/**
	 * Swap the development origin for the production base, preserving the path.
	 */
	private static function to_production( string $url, string $base ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		if ( '' === $path ) {
			$path = '/';
		}

		$query    = (string) wp_parse_url( $url, PHP_URL_QUERY );
		$fragment = (string) wp_parse_url( $url, PHP_URL_FRAGMENT );

		$out = $base . $path;

		if ( '' !== $query ) {
			$out .= '?' . $query;
		}
		if ( '' !== $fragment ) {
			$out .= '#' . $fragment;
		}

		return $out;
	}

	/**
	 * Exclusion patterns from settings and filter.
	 *
	 * Each pattern is matched two ways against the URL path: as a prefix
	 * (a leading slash means "this slug and everything under it") and as a
	 * plain substring (a bare word means "any URL containing this").
	 *
	 * @return array<int, string>
	 */
	private static function exclude_patterns(): array {
		$settings = Distan::settings();
		$raw      = isset( $settings['sitemap_exclude'] ) ? (string) $settings['sitemap_exclude'] : '';

		$patterns = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$line = trim( (string) $line );
			if ( '' !== $line ) {
				$patterns[] = $line;
			}
		}

		/**
		 * Filter the sitemap exclusion patterns.
		 *
		 * @param array<int, string> $patterns Slug prefixes or substrings.
		 */
		$patterns = (array) apply_filters( 'distan_sitemap_exclude', $patterns );

		return array_values( array_filter( array_map( 'strval', $patterns ) ) );
	}

	/**
	 * Whether a URL matches any exclusion pattern.
	 *
	 * @param string             $url      Absolute production URL.
	 * @param array<int, string> $patterns Exclusion patterns.
	 */
	private static function is_excluded( string $url, array $patterns ): bool {
		if ( empty( $patterns ) ) {
			return false;
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$path = rawurldecode( $path );

		foreach ( $patterns as $pattern ) {
			$needle = rawurldecode( $pattern );

			// A leading slash means "this path prefix": /private/ excludes
			// /private/ and everything beneath it.
			if ( str_starts_with( $needle, '/' ) ) {
				$prefix = '/' . trim( $needle, '/' );
				if ( $path === $prefix || str_starts_with( $path, $prefix . '/' ) ) {
					return true;
				}
				continue;
			}

			// Otherwise, a substring match anywhere in the path.
			if ( '' !== $needle && str_contains( $path, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Last-modified date (W3C format) for a page URL, when resolvable.
	 * Archives and other non-post URLs return an empty string and simply omit
	 * <lastmod>.
	 */
	private static function lastmod_for( string $url ): string {
		$post_id = url_to_postid( $url );

		if ( $post_id <= 0 ) {
			return '';
		}

		$modified = get_post_modified_time( 'c', true, $post_id );

		return is_string( $modified ) ? $modified : '';
	}

	/**
	 * Render the sitemap XML.
	 *
	 * @param array<int, array{loc:string, lastmod:string}> $entries Entries.
	 */
	private static function render( array $entries ): string {
		$lines   = array();
		$lines[] = '<?xml version="1.0" encoding="UTF-8"?>';
		$lines[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

		foreach ( $entries as $entry ) {
			$loc = isset( $entry['loc'] ) ? (string) $entry['loc'] : '';
			if ( '' === $loc ) {
				continue;
			}

			$lines[] = "\t<url>";
			$lines[] = "\t\t<loc>" . self::xml_escape( $loc ) . '</loc>';

			$lastmod = isset( $entry['lastmod'] ) ? (string) $entry['lastmod'] : '';
			if ( '' !== $lastmod ) {
				$lines[] = "\t\t<lastmod>" . self::xml_escape( $lastmod ) . '</lastmod>';
			}

			$lines[] = "\t</url>";
		}

		$lines[] = '</urlset>';
		$lines[] = '';

		return implode( "\n", $lines );
	}

	/**
	 * Escape a value for XML text content.
	 */
	private static function xml_escape( string $value ): string {
		return htmlspecialchars( $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Whether robots.txt output is switched on in settings.
	 */
	public static function robots_enabled(): bool {
		$settings = Distan::settings();

		return ! empty( $settings['robots'] );
	}

	/**
	 * Build and write robots.txt at the dist root.
	 *
	 * Kept deliberately minimal: allow everything, and point crawlers at the
	 * sitemap when one is being written. A static site has no virtual
	 * robots.txt, so this becomes the real file. The Sitemap line is only
	 * added when sitemap output is on, so it never points at a missing file.
	 *
	 * @return string|null Relative path written, or null.
	 */
	public static function write_robots(): ?string {
		if ( ! self::robots_enabled() ) {
			return null;
		}

		$lines   = array();
		$lines[] = 'User-agent: *';
		$lines[] = 'Allow: /';

		if ( self::is_enabled() ) {
			$lines[] = '';
			$lines[] = 'Sitemap: ' . self::production_base() . '/' . self::FILENAME;
		}

		$lines[] = '';

		/**
		 * Filter the robots.txt lines before they are written.
		 *
		 * @param array<int, string> $lines The robots.txt lines.
		 */
		$lines = (array) apply_filters( 'distan_robots_lines', $lines );

		$body = implode( "\n", array_map( 'strval', $lines ) );

		if ( Distan_Paths::write( 'robots.txt', $body ) ) {
			return 'robots.txt';
		}

		return null;
	}
}
