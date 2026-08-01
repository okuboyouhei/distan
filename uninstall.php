<?php
/**
 * Uninstall cleanup.
 *
 * Runs when the plugin is deleted from the Plugins screen (not on
 * deactivation). Removes everything Distan wrote to the options table.
 *
 * Generated files are deliberately left alone by default: dist/ may still
 * hold a deliverable that has not been handed over yet, and once the plugin
 * is gone there is no way to regenerate it. Define DISTAN_REMOVE_FILES
 * in wp-config.php to remove those too.
 *
 * @package Distan
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Delete every option and transient for the current site.
 */
function distan_uninstall_site(): void {
	$options = array(
		'distan_settings',
		'distan_render_secret',
		'distan_manifest',
		'distan_last_report',
		'distan_last_dispatch',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	delete_transient( 'distan_job' );

	// Belt and braces: a transient stored while an object cache was active
	// can leave a stray row behind.
	delete_option( '_transient_distan_job' );
	delete_option( '_transient_timeout_distan_job' );

	if ( ! defined( 'DISTAN_REMOVE_FILES' ) || ! DISTAN_REMOVE_FILES ) {
		return;
	}

	$uploads = wp_get_upload_dir();

	if ( empty( $uploads['basedir'] ) ) {
		return;
	}

	$base = trailingslashit( $uploads['basedir'] ) . 'distan';
	$real = realpath( $base );

	// Only ever delete inside uploads, and only our own directory.
	$uploads_real = realpath( $uploads['basedir'] );

	if ( false === $real || false === $uploads_real ) {
		return;
	}

	if ( ! str_starts_with( $real, $uploads_real . DIRECTORY_SEPARATOR ) ) {
		return;
	}

	distan_uninstall_rmdir( $real );
}

/**
 * Recursively remove a directory.
 *
 * @param string $dir Absolute path.
 */
function distan_uninstall_rmdir( string $dir ): void {
	$items = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

	if ( false === $items ) {
		return;
	}

	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}

		$path = $dir . DIRECTORY_SEPARATOR . $item;

		if ( is_link( $path ) ) {
			// Never follow a symlink out of the tree.
			wp_delete_file( $path );
			continue;
		}

		if ( is_dir( $path ) ) {
			distan_uninstall_rmdir( $path );
			continue;
		}

		wp_delete_file( $path );
	}

	// WP_Filesystem is avoided on purpose (it may prompt for FTP credentials
	// during uninstall). Directory is inside uploads, checked by the caller.
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged
	@rmdir( $dir );
}

if ( is_multisite() ) {
	$distan_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $distan_site_ids as $distan_site_id ) {
		switch_to_blog( (int) $distan_site_id );
		distan_uninstall_site();
		restore_current_blog();
	}
} else {
	distan_uninstall_site();
}
