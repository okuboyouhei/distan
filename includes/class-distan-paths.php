<?php
/**
 * Path containment.
 *
 * Every destructive filesystem call in this plugin goes through here.
 * The lesson from HXMC review round 2: values that originate in the database
 * are untrusted input as far as the filesystem is concerned. Validate at the
 * entry point, then re-check containment with realpath() immediately before
 * the destructive call.
 *
 * @package Distan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Filesystem containment helper.
 */
class Distan_Paths {

	/**
	 * Absolute path to the output root.
	 *
	 * Defaults to uploads/distan/dist so that it is writable on every
	 * host without extra configuration.
	 */
	public static function output_root(): string {
		$settings = Distan::settings();

		if ( ! empty( $settings['output_dir'] ) ) {
			$custom = self::normalize( (string) $settings['output_dir'] );
			if ( '' !== $custom ) {
				return $custom;
			}
		}

		$uploads = wp_get_upload_dir();
		return self::normalize( trailingslashit( $uploads['basedir'] ) . 'distan/dist' );
	}

	/**
	 * Absolute path to the plugin's working directory (temp, manifests).
	 */
	public static function work_root(): string {
		$uploads = wp_get_upload_dir();
		return self::normalize( trailingslashit( $uploads['basedir'] ) . 'distan/work' );
	}

	/**
	 * Collapse separators and strip a trailing slash. Does not touch the disk.
	 */
	public static function normalize( string $path ): string {
		$path = str_replace( '\\', '/', $path );
		$path = preg_replace( '#/+#', '/', $path );

		if ( ! is_string( $path ) ) {
			return '';
		}

		return rtrim( $path, '/' );
	}

	/**
	 * Entry validation for a site-relative output path.
	 *
	 * Rejects anything that could escape the output root before we ever build
	 * an absolute path from it. Returns the cleaned relative path, or null.
	 */
	public static function validate_relative( string $relative ): ?string {
		$relative = self::normalize( $relative );
		$relative = ltrim( $relative, '/' );

		if ( '' === $relative ) {
			return null;
		}

		// Reject traversal, NUL bytes, and stream wrappers outright.
		if ( str_contains( $relative, '..' ) ) {
			return null;
		}
		if ( str_contains( $relative, "\0" ) ) {
			return null;
		}
		if ( preg_match( '#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $relative ) ) {
			return null;
		}
		// Reject absolute paths and Windows drive letters.
		if ( preg_match( '#^([a-zA-Z]:)?/#', $relative ) ) {
			return null;
		}

		// WordPress's own check, which also blocks a handful of edge cases.
		if ( validate_file( $relative ) !== 0 ) {
			return null;
		}

		return $relative;
	}

	/**
	 * Build an absolute output path from a relative one, or null if unsafe.
	 */
	public static function resolve_output( string $relative ): ?string {
		$relative = self::validate_relative( $relative );

		if ( null === $relative ) {
			return null;
		}

		return self::output_root() . '/' . $relative;
	}

	/**
	 * Containment re-check, to be called immediately before writing/deleting.
	 *
	 * Uses realpath() on the deepest existing ancestor so that it works for
	 * files that do not exist yet.
	 */
	public static function is_contained( string $absolute, ?string $root = null ): bool {
		$root = self::normalize( $root ?? self::output_root() );

		$real_root = realpath( $root );
		if ( false === $real_root ) {
			// The root does not exist yet; nothing can be contained in it.
			return false;
		}
		$real_root = self::normalize( $real_root );

		$candidate = self::normalize( $absolute );
		$probe     = $candidate;

		// Walk up until we find a path that exists on disk.
		while ( '' !== $probe && ! file_exists( $probe ) ) {
			$parent = dirname( $probe );
			if ( $parent === $probe ) {
				return false;
			}
			$probe = self::normalize( $parent );
		}

		$real_probe = realpath( $probe );
		if ( false === $real_probe ) {
			return false;
		}
		$real_probe = self::normalize( $real_probe );

		// The existing ancestor must live inside the root...
		if ( $real_probe !== $real_root && ! str_starts_with( $real_probe . '/', $real_root . '/' ) ) {
			return false;
		}

		// ...and the remaining (not-yet-existing) segments must not escape.
		$remainder = substr( $candidate, strlen( $real_probe ) );

		return ! str_contains( $remainder, '..' );
	}

	/**
	 * Create a directory inside the output root.
	 */
	public static function ensure_dir( string $absolute ): bool {
		$absolute = self::normalize( $absolute );

		if ( is_dir( $absolute ) ) {
			return true;
		}

		// Containment for a directory that does not exist yet is checked
		// against the root string, since realpath() cannot see it.
		$root = self::output_root();
		if ( $absolute !== $root && ! str_starts_with( $absolute . '/', $root . '/' ) ) {
			return false;
		}

		return wp_mkdir_p( $absolute );
	}

	/**
	 * Write a file inside the output root. Returns false on any doubt.
	 */
	public static function write( string $relative, string $contents ): bool {
		$absolute = self::resolve_output( $relative );

		if ( null === $absolute ) {
			return false;
		}

		if ( ! self::ensure_dir( self::output_root() ) ) {
			return false;
		}

		if ( ! self::ensure_dir( dirname( $absolute ) ) ) {
			return false;
		}

		// Final containment check, immediately before the write.
		if ( ! self::is_contained( $absolute ) ) {
			return false;
		}

		return false !== file_put_contents( $absolute, $contents, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}
}
