<?php
/**
 * Core-sitemap reconciliation.
 *
 * Reads WordPress core's own sitemap providers (wp-sitemap, since 5.5) in
 * process — no HTTP, no crawling — to answer two questions:
 *
 *   1. Coverage (default): which URLs does core declare that this run did not
 *      enumerate? Surfaced in the report so a gap is visible, not silent.
 *   2. Seeding (opt-in via distan_use_core_sitemap): feed those URLs into the
 *      queue as a supplementary source, picking up plugin-registered routes
 *      that pure enumeration cannot know about.
 *
 * It is deliberately a supplement. Core sitemaps honour noindex and can be
 * disabled or filtered, so the set never replaces the built-in enumeration —
 * the shared dedup removes overlaps either way.
 *
 * @package Distan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and reconciles the core sitemap.
 */
class Distan_Sitemap_Audit {

	/**
	 * URLs declared by core sitemap providers, de-duplicated.
	 *
	 * Guarded at every step: the sitemap server may be disabled, and provider
	 * methods, though public, have thin documentation and have shifted across
	 * releases, so their presence is checked rather than assumed. A per-provider
	 * page cap keeps a provider that reports an enormous page count from
	 * spinning.
	 *
	 * @return array<int, string> Absolute URLs.
	 */
	public static function core_sitemap_urls(): array {
		if ( ! function_exists( 'wp_sitemaps_get_server' ) ) {
			return array();
		}

		$server = wp_sitemaps_get_server();

		if ( ! is_object( $server ) || ! isset( $server->registry ) || ! method_exists( $server->registry, 'get_providers' ) ) {
			return array();
		}

		/**
		 * Cap on pages read per provider subtype, so a provider that reports a
		 * very large page count cannot stall a run.
		 *
		 * @param int $max Page cap.
		 */
		$page_cap = (int) apply_filters( 'distan_sitemap_audit_max_pages', 50 );
		$page_cap = max( 1, $page_cap );

		$urls = array();

		foreach ( (array) $server->registry->get_providers() as $provider ) {
			if ( ! is_object( $provider ) || ! method_exists( $provider, 'get_url_list' ) ) {
				continue;
			}

			$subtypes = method_exists( $provider, 'get_object_subtypes' ) ? (array) $provider->get_object_subtypes() : array();
			$keys     = ! empty( $subtypes ) ? array_keys( $subtypes ) : array( '' );

			foreach ( $keys as $subtype ) {
				$subtype = (string) $subtype;

				$max = method_exists( $provider, 'get_max_num_pages' ) ? (int) $provider->get_max_num_pages( $subtype ) : 1;
				$max = max( 1, min( $page_cap, $max ) );

				for ( $page = 1; $page <= $max; $page++ ) {
					$list = $provider->get_url_list( $page, $subtype );

					if ( ! is_array( $list ) ) {
						continue;
					}

					foreach ( $list as $entry ) {
						if ( is_array( $entry ) && ! empty( $entry['loc'] ) ) {
							$urls[] = (string) $entry['loc'];
						}
					}
				}
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Queue entries for the opt-in core-sitemap source.
	 *
	 * @return array<int, array{url: string, path: string, label: string, type: string, source: array<string, mixed>}>
	 */
	public static function items(): array {
		$items = array();

		foreach ( self::core_sitemap_urls() as $url ) {
			$items[] = Distan_Collector::make_item(
				$url,
				__( 'コア sitemap', 'distan' ),
				array( 'kind' => 'extra', 'origin' => 'core-sitemap' )
			);
		}

		return $items;
	}

	/**
	 * URLs the core sitemap declares that the given queue did not enumerate.
	 *
	 * Comparison is by a normalised form (scheme/fragment/trailing-slash
	 * folded) so a trailing-slash or http/https difference does not read as a
	 * missing page. Advisory: the result is shown, never auto-added.
	 *
	 * @param array<int, array<string, mixed>> $queue The collected queue.
	 * @return array<int, string> Sitemap URLs absent from the queue.
	 */
	public static function missing_from( array $queue ): array {
		$sitemap = self::core_sitemap_urls();

		if ( empty( $sitemap ) ) {
			return array();
		}

		$have = array();
		foreach ( $queue as $item ) {
			if ( is_array( $item ) && ! empty( $item['url'] ) ) {
				$have[ self::normalize( (string) $item['url'] ) ] = true;
			}
		}

		$missing = array();
		foreach ( $sitemap as $url ) {
			if ( ! isset( $have[ self::normalize( $url ) ] ) ) {
				$missing[] = $url;
			}
		}

		return array_values( array_unique( $missing ) );
	}

	/**
	 * Fold a URL to a comparison key: drop the fragment, lowercase the scheme
	 * and host, and strip a trailing slash. Enough to match the same page
	 * across the small formatting differences between get_permalink() output
	 * and sitemap loc values, without pulling in a full URL library.
	 *
	 * @param string $url URL.
	 */
	private static function normalize( string $url ): string {
		$url = strtok( $url, '#' );

		if ( false === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) ) {
			return rtrim( $url, '/' );
		}

		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) . '://' : '';
		$host   = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path   = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';
		$query  = isset( $parts['query'] ) ? '?' . $parts['query'] : '';

		return $scheme . $host . $port . $path . $query;
	}
}
