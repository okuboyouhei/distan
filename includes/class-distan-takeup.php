<?php
/**
 * Take-up of uncovered URLs.
 *
 * Enumeration cannot know about URLs a plugin registers dynamically (a form's
 * thank-you page, a virtual route). The coverage report already surfaces them
 * — core sitemap declares them, or a generated page links to them — but acting
 * on that used to mean hand-writing a distan_sources filter.
 *
 * This records the operator's decision instead, per URL, and remembers it:
 *
 *   - include: add to the queue on every run (until removed).
 *   - ignore:  never offer as a candidate again.
 *   - neither: still pending — offered again next time.
 *
 * The default is opt-in: nothing is taken up unless chosen, so a URL the site
 * should not ship never sneaks into the deliverable. The two lists are kept
 * disjoint, with ignore winning, so a URL cannot be both added and hidden.
 *
 * @package Distan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores and applies the operator's take-up decisions.
 */
class Distan_Takeup {

	/**
	 * Option holding the two URL lists.
	 */
	public const OPTION_KEY = 'distan_takeup';

	/**
	 * The current decisions, normalised to two disjoint URL lists.
	 *
	 * @return array{include: array<int, string>, ignore: array<int, string>}
	 */
	public static function state(): array {
		$stored = get_option( self::OPTION_KEY, array() );

		$include = ( is_array( $stored ) && isset( $stored['include'] ) && is_array( $stored['include'] ) ) ? $stored['include'] : array();
		$ignore  = ( is_array( $stored ) && isset( $stored['ignore'] ) && is_array( $stored['ignore'] ) ) ? $stored['ignore'] : array();

		return self::normalise( $include, $ignore );
	}

	/**
	 * Persist the decisions. Both lists are cleaned to same-origin URLs,
	 * de-duplicated, and made disjoint (ignore wins) before saving.
	 *
	 * @param array<int, string> $include URLs to add on every run.
	 * @param array<int, string> $ignore  URLs to stop offering.
	 */
	public static function save( array $include, array $ignore ): void {
		update_option( self::OPTION_KEY, self::normalise( $include, $ignore ), false );
	}

	/**
	 * Queue entries for the included URLs, ready to merge into collection.
	 * Mirrors distan_sources: each carries provenance and is deduplicated
	 * alongside the built-in enumeration.
	 *
	 * @return array<int, array{url: string, path: string, label: string, type: string, source: array<string, mixed>}>
	 */
	public static function included_items(): array {
		$items = array();

		foreach ( self::state()['include'] as $url ) {
			$items[] = Distan_Collector::make_item(
				$url,
				__( '取り込み', 'distan' ),
				array( 'kind' => 'extra', 'origin' => 'takeup' )
			);
		}

		return $items;
	}

	/**
	 * Clean, de-duplicate, and disjoin the two lists. Only same-origin URLs
	 * survive: a take-up names a page on this site, so a foreign or malformed
	 * URL is dropped rather than stored. A URL in both lists stays in ignore.
	 *
	 * @param array<int, string> $include Raw include URLs.
	 * @param array<int, string> $ignore  Raw ignore URLs.
	 * @return array{include: array<int, string>, ignore: array<int, string>}
	 */
	private static function normalise( array $include, array $ignore ): array {
		$ignore  = self::clean( $ignore );
		$include = array_values( array_diff( self::clean( $include ), $ignore ) );

		return array(
			'include' => $include,
			'ignore'  => $ignore,
		);
	}

	/**
	 * Reduce a raw URL list to same-origin, de-duplicated, non-empty URLs.
	 *
	 * @param array<int, mixed> $urls Raw URLs.
	 * @return array<int, string>
	 */
	private static function clean( array $urls ): array {
		$home  = trailingslashit( home_url( '/' ) );
		$site  = trim( (string) ( Distan::settings()['site_url'] ?? '' ) );
		$site  = '' !== $site ? trailingslashit( $site ) : '';
		$clean = array();

		foreach ( $urls as $url ) {
			$url = esc_url_raw( trim( (string) $url ) );

			if ( '' === $url ) {
				continue;
			}

			// Must belong to this site: the dev origin, or the production URL
			// the operator configured. Anything else is not ours to generate.
			$same = str_starts_with( $url, $home ) || ( '' !== $site && str_starts_with( $url, $site ) );

			if ( ! $same ) {
				continue;
			}

			$clean[ $url ] = true;
		}

		return array_keys( $clean );
	}
}
