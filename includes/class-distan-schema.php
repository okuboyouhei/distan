<?php
/**
 * Optional, conservative WebPage JSON-LD for generated HTML only.
 *
 * @package Distan
 */

defined( 'ABSPATH' ) || exit;

class Distan_Schema {

	/**
	 * Supplement a page only when its author has supplied no JSON-LD.
	 * DOM is used for inspection only: never reserialize the delivered HTML.
	 *
	 * @param string $html Rewritten, final page HTML.
	 * @param array  $item Generation queue item.
	 * @param string|null $reason Receives the stable injection outcome code.
	 * @return string
	 */
	public static function inject( string $html, array $item, ?string &$reason = null ): string {
		$settings = Distan::settings();
		$base     = trim( (string) $settings['site_url'] );
		$reason = 'disabled';
		if ( empty( $settings['schema'] ) ) { return $html; }
		$reason = 'not_found';
		if ( '404' === ( $item['type'] ?? '' ) ) { return $html; }
		$reason = 'missing_url';
		if ( ! preg_match( '~^https?://[^/]+~i', $base ) ) { return $html; }
		$reason = 'dom_unavailable';
		if ( ! class_exists( 'DOMDocument' ) ) { return $html; }
		$reason = 'excluded';
		if ( self::is_excluded( $item, $settings ) ) { return $html; }
		$reason = 'parse_failed';

		$previous = libxml_use_internal_errors( true );
		$doc      = new DOMDocument();
		try {
			$loaded = $doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
		if ( ! $loaded ) {
			return $html;
		}

		foreach ( $doc->getElementsByTagName( 'script' ) as $script ) {
			$type = strtolower( trim( explode( ';', $script->getAttribute( 'type' ) )[0] ) );
			if ( 'application/ld+json' === $type ) {
				// Even malformed/empty custom data is the author's responsibility.
				$reason = 'existing';
				return $html;
			}
		}

		$path = isset( $item['path'] ) ? ltrim( (string) $item['path'], '/' ) : '';
		$reason = 'missing_path';
		if ( '' === $path ) {
			return $html;
		}
		$url = rtrim( $base, '/' ) . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );
		$url = Distan_Urls::to_public_urls( $url );
		$data = array(
			'@context' => 'https://schema.org',
			'@type'    => 'WebPage',
			'url'      => $url,
		);
		$title = $doc->getElementsByTagName( 'title' )->item( 0 );
		if ( $title && '' !== trim( $title->textContent ) ) {
			$data['name'] = trim( $title->textContent );
		}
		foreach ( $doc->getElementsByTagName( 'meta' ) as $meta ) {
			if ( 'description' === strtolower( trim( $meta->getAttribute( 'name' ) ) ) ) {
				$description = trim( $meta->getAttribute( 'content' ) );
				if ( '' !== $description ) {
					$data['description'] = $description;
				}
				break;
			}
		}
		$element = $doc->getElementsByTagName( 'html' )->item( 0 );
		if ( $element && '' !== trim( $element->getAttribute( 'lang' ) ) ) {
			$data['inLanguage'] = trim( $element->getAttribute( 'lang' ) );
		}

		// HEX flags prevent page metadata from terminating the script element.
		$json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		$reason = 'encode_failed';
		if ( ! is_string( $json ) ) {
			return $html;
		}
		// Skip comments and raw text: a sample "<head>" inside them is not markup.
		$pattern = '~<!--.*?-->(*SKIP)(*F)|<(script|style|textarea|title)\b[^>]*>.*?</\1\s*>(*SKIP)(*F)|<head\b(?:"[^"]*"|\x27[^\x27]*\x27|[^\x27">])*>~is';
		$reason = 'missing_head';
		if ( 1 !== preg_match( $pattern, $html, $match, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}
		$offset = $match[0][1] + strlen( $match[0][0] );
		$block  = "\n" . '<script type="application/ld+json" id="distan-schema">' . $json . '</script>' . "\n";
		$reason = 'added';
		return substr( $html, 0, $offset ) . $block . substr( $html, $offset );
	}

	/**
	 * Match decoded source paths relative to the WordPress home directory.
	 * Directory rules include their root; page rules ignore a trailing slash.
	 * Query variants share the source page's exclusion rule.
	 */
	private static function is_excluded( array $item, array $settings ): bool {
		$path = (string) wp_parse_url( (string) ( $item['url'] ?? '' ), PHP_URL_PATH );
		$home = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $home && ( $path === $home || str_starts_with( $path, $home . '/' ) ) ) {
			$path = substr( $path, strlen( $home ) );
		}
		$path = '/' . trim( rawurldecode( $path ), '/' );
		foreach ( array( 'schema_exclude_dirs', 'schema_exclude_pages' ) as $key ) {
			foreach ( preg_split( '/\r\n|\r|\n/', (string) ( $settings[ $key ] ?? '' ) ) as $line ) {
				$line = trim( $line );
				if ( '' === $line || '/' !== $line[0] ) {
					continue;
				}
				$rule = '/' . trim( rawurldecode( $line ), '/' );
				if ( $path === $rule || ( 'schema_exclude_dirs' === $key
					&& ( '/' === $rule || str_starts_with( $path, $rule . '/' ) ) ) ) {
					return true;
				}
			}
		}
		return false;
	}
}
