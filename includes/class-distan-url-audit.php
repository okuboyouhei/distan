<?php
/**
 * Shared, read-only development URL detection for output and JSON-LD audits.
 * Environment values are supplied by the generator; no settings or HTTP reads.
 *
 * @package Distan
 */
defined( 'ABSPATH' ) || exit;

class Distan_Url_Audit {
	/**
	 * Match the source host, port and path boundary, not a hostname prefix.
	 * HTTP/HTTPS default ports are equivalent for this development-host check.
	 * A production base identical to the source base is not a development leak.
	 */
	public static function is_development_url( string $url, array $environment ): bool {
		$dev = self::base( (string) ( $environment['site'] ?? '' ) );
		$pub = self::base( (string) ( $environment['site_url'] ?? '' ) );
		$target = self::base( $url );
		if ( null === $dev || null === $target ) {
			return false;
		}
		if ( $dev === $pub || $dev['host'] !== $target['host'] || $dev['port'] !== $target['port'] ) {
			return false;
		}
		return '' === $dev['path'] || $target['path'] === $dev['path'] || str_starts_with( $target['path'], $dev['path'] . '/' );
	}

	/** Count URL occurrences in an inspection copy; leave the saved bytes intact. */
	public static function count_development_urls( string $text, array $environment ): int {
		// Decode JSON Unicode escapes as well as escaped slashes in the copy.
		$scan = preg_replace_callback( '/\\\\u[0-9a-f]{4}/i', static function ( $match ) {
			$decoded = json_decode( '"' . $match[0] . '"' );
			return is_string( $decoded ) ? $decoded : $match[0];
		}, $text );
		$scan = str_replace( '\/', '/', $scan ?? $text );
		preg_match_all( '~(?<![a-z0-9+.:/-])(?:https?:)?//[^\s<>"\'\\\\]+~i', $scan, $matches );
		$count = 0;
		foreach ( $matches[0] as $url ) {
			if ( self::is_development_url( $url, $environment ) ) {
				++$count;
			}
		}
		return $count;
	}

	/** Normalize only what is needed for a source-base comparison. */
	private static function base( string $url ): ?array {
		if ( str_starts_with( $url, '//' ) ) {
			$url = 'https:' . $url;
		}
		// Keep raw UTF-8 path bytes out of PHP parse_url's control-byte handling.
		$url = (string) preg_replace_callback( '/[^\x00-\x7F]+/', static function ( $match ) {
			return rawurlencode( $match[0] );
		}, $url );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) ) {
			return null;
		}
		$scheme = strtolower( $parts['scheme'] );
		$port = (int) ( $parts['port'] ?? 0 );
		if ( ( 'https' === $scheme && 443 === $port ) || ( 'http' === $scheme && 80 === $port ) ) {
			$port = 0;
		}
		$path = preg_replace_callback( '/%[0-9a-f]{2}/i', static function ( $match ) {
			$char = rawurldecode( $match[0] );
			return preg_match( '/[a-zA-Z0-9._~-]/', $char ) ? $char : strtoupper( $match[0] );
		}, $parts['path'] ?? '' );
		return array( 'host' => strtolower( $parts['host'] ), 'port' => $port, 'path' => rtrim( $path, '/' ) );
	}
}
