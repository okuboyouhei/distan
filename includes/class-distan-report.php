<?php
/**
 * Deliverable packaging: the Markdown report and the download ZIP.
 *
 * The report exists because the on-screen diff vanishes on reload, and the
 * one line an operator must not miss — "delete these from production" — is
 * exactly the one a refresh erases. Written to disk, it becomes the checklist
 * you consult before opening an FTP client.
 *
 * @package Distan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds reports and ZIP archives.
 */
class Distan_Report {

	/**
	 * Report filename used inside the ZIP root.
	 */
	public const ZIP_REPORT_NAME = 'distan-report.md';

	/**
	 * Build the Markdown report for a finished run and save a timestamped
	 * copy to the work directory (kept as history, never inside dist/).
	 *
	 * @param array<string, mixed> $manifest Completed manifest.
	 * @param array<string, mixed> $meta     Extra context (link_style, etc.).
	 * @return string|null Absolute path to the saved report, or null.
	 */
	public static function save( array $manifest, array $meta = array() ): ?string {
		$markdown = self::render( $manifest, $meta );

		$work = Distan_Paths::work_root();

		if ( ! Distan_Paths::ensure_dir( $work ) ) {
			return null;
		}

		$name = 'distan-report-' . gmdate( 'Ymd-His', (int) ( $manifest['finished'] ?? time() ) ) . '.md';
		$path = $work . '/' . $name;

		if ( false === file_put_contents( $path, $markdown, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return null;
		}

		update_option( 'distan_last_report', $path, false );

		return $path;
	}

	/**
	 * Render the manifest as Markdown.
	 *
	 * @param array<string, mixed> $manifest Completed manifest.
	 * @param array<string, mixed> $meta     Extra context.
	 */
	public static function render( array $manifest, array $meta = array() ): string {
		$files   = isset( $manifest['files'] ) && is_array( $manifest['files'] ) ? $manifest['files'] : array();
		$added   = isset( $manifest['added'] ) && is_array( $manifest['added'] ) ? $manifest['added'] : array();
		$removed = isset( $manifest['removed'] ) && is_array( $manifest['removed'] ) ? $manifest['removed'] : array();
		$broken  = isset( $manifest['broken'] ) && is_array( $manifest['broken'] ) ? $manifest['broken'] : array();
		$cleaned = isset( $manifest['cleaned'] ) && is_array( $manifest['cleaned'] ) ? $manifest['cleaned'] : array();

		$finished = (int) ( $manifest['finished'] ?? time() );
		$when     = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $finished ), 'Y-m-d H:i' );

		$link_style = ( $meta['link_style'] ?? 'relative' ) === 'absolute'
			? '公開URLで絶対指定'
			: 'ドキュメント相対';

		$lines   = array();
		$lines[] = '# Distan 生成レポート';
		$lines[] = '';
		$lines[] = '- 生成日時: ' . $when;
		$lines[] = '- リンク書式: ' . $link_style;
		$lines[] = '- サイト: ' . home_url( '/' );
		$lines[] = '';
		$lines[] = '## サマリ';
		$lines[] = '';
		$lines[] = '| 項目 | 件数 |';
		$lines[] = '| --- | ---: |';
		$lines[] = '| 出力ファイル | ' . count( $files ) . ' |';
		$lines[] = '| 追加 | ' . count( $added ) . ' |';
		$lines[] = '| 出力先から削除 | ' . count( $cleaned ) . ' |';
		$lines[] = '| リンク切れ | ' . count( $broken ) . ' |';
		$lines[] = '';

		// The section that matters most goes near the top when it is non-empty.
		if ( ! empty( $removed ) ) {
			$lines[] = '## ⚠ 本番から削除が必要なファイル';
			$lines[] = '';
			$lines[] = '前回の納品物に含まれていたが、今回は生成されなかったファイルです。';
			$lines[] = 'すでにアップロード済みの場合、本番サーバーから手で削除してください。';
			$lines[] = '';
			foreach ( $removed as $file ) {
				$lines[] = '- `' . $file . '`';
			}
			$lines[] = '';
		}

		if ( ! empty( $broken ) ) {
			$lines[] = '## リンク切れ';
			$lines[] = '';
			$lines[] = 'リンク先のファイルが生成されていないものです。対象外のアーカイブや、';
			$lines[] = '本文中の外部・管理画面リンクが原因のことがあります。';
			$lines[] = '';
			$lines[] = '| ページ | リンク先 |';
			$lines[] = '| --- | --- |';
			foreach ( $broken as $b ) {
				$from = isset( $b['from'] ) ? (string) $b['from'] : '';
				$to   = isset( $b['to'] ) ? (string) $b['to'] : '';
				$lines[] = '| `' . $from . '` | `' . $to . '` |';
			}
			$lines[] = '';
		}

		if ( ! empty( $added ) ) {
			$lines[] = '## 追加されたファイル';
			$lines[] = '';
			foreach ( $added as $file ) {
				$lines[] = '- `' . $file . '`';
			}
			$lines[] = '';
		}

		if ( ! empty( $cleaned ) ) {
			$lines[] = '## 出力先から削除したファイル';
			$lines[] = '';
			$lines[] = '出力ディレクトリを納品物と一致させるため自動削除したものです（本番とは無関係）。';
			$lines[] = '';
			foreach ( $cleaned as $file ) {
				$lines[] = '- `' . $file . '`';
			}
			$lines[] = '';
		}

		$lines[] = '---';
		$lines[] = '';
		$lines[] = '_Distan ' . DISTAN_VERSION . ' が生成しました。_';

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Whether ZIP creation is available on this host.
	 */
	public static function can_zip(): bool {
		return class_exists( 'ZipArchive' );
	}

	/**
	 * Build a ZIP of the output directory in the work directory.
	 *
	 * The Markdown report is added at the ZIP root, never inside the dist
	 * tree, so the deliverable itself stays free of Distan artifacts.
	 *
	 * @param array<string, mixed> $manifest Manifest, for the bundled report.
	 * @param array<string, mixed> $meta     Extra context.
	 * @return string|null Absolute path to the ZIP, or null on failure.
	 */
	public static function build_zip( array $manifest, array $meta = array() ): ?string {
		if ( ! self::can_zip() ) {
			return null;
		}

		$root = Distan_Paths::output_root();

		if ( ! is_dir( $root ) ) {
			return null;
		}

		$work = Distan_Paths::work_root();

		if ( ! Distan_Paths::ensure_dir( $work ) ) {
			return null;
		}

		$slug     = sanitize_title( get_bloginfo( 'name' ) );
		$slug     = '' !== $slug ? $slug : 'site';
		$filename = $slug . '-' . gmdate( 'Ymd-His' ) . '.zip';
		$zip_path = $work . '/' . $filename;

		$zip = new ZipArchive();

		if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return null;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		$root_len = strlen( Distan_Paths::normalize( $root ) ) + 1;

		foreach ( $iterator as $entry ) {
			/** @var SplFileInfo $entry */
			$absolute = Distan_Paths::normalize( (string) $entry->getPathname() );
			$local    = substr( $absolute, $root_len );

			if ( '' === $local ) {
				continue;
			}

			if ( $entry->isDir() ) {
				$zip->addEmptyDir( $local );
			} elseif ( $entry->isFile() ) {
				$zip->addFile( $absolute, $local );
			}
		}

		// Report at the ZIP root, outside the deliverable tree.
		$zip->addFromString( self::ZIP_REPORT_NAME, self::render( $manifest, $meta ) );

		$zip->close();

		return is_file( $zip_path ) ? $zip_path : null;
	}
}
