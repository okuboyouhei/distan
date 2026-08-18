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
	 * Filenames used at the root of the differential ZIP: a human-readable
	 * delivery note, and a plain list of paths to delete from production.
	 */
	public const ZIP_DIFF_NOTE_NAME = 'distan-diff.md';
	public const ZIP_DELETE_NAME    = 'DELETE.txt';

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
		$dev     = isset( $manifest['dev_urls'] ) && is_array( $manifest['dev_urls'] ) ? $manifest['dev_urls'] : array();
		$dev_count = isset( $dev['count'] ) ? (int) $dev['count'] : 0;
		$dev_files = isset( $dev['files'] ) && is_array( $dev['files'] ) ? $dev['files'] : array();
		$large   = isset( $manifest['large_files'] ) && is_array( $manifest['large_files'] ) ? $manifest['large_files'] : array();
			$entries = isset( $manifest['entries'] ) && is_array( $manifest['entries'] ) ? $manifest['entries'] : array();
			$missing = isset( $manifest['sitemap_missing'] ) && is_array( $manifest['sitemap_missing'] ) ? $manifest['sitemap_missing'] : array();

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
		$lines[] = '| 開発URLの残り | ' . $dev_count . ' |';
		$lines[] = '| 大きいファイル | ' . count( $large ) . ' |';
		$lines[] = '';

		// A production-correctness issue: development-domain URLs left in the
		// output (typically in JSON-LD or canonical/OGP when the Production URL
		// is unset). Surfaced near the top when present.
		if ( $dev_count > 0 ) {
			$lines[] = '## ⚠ 開発環境のURLが残っています';
			$lines[] = '';
			$lines[] = '生成物に開発環境のドメイン（' . home_url( '/' ) . '）を指すURLが ' . $dev_count . ' 件残っています。';
			$lines[] = 'canonical・OGP・構造化データ（JSON-LD）など、Distan が書き換えずに運ぶ絶対URLで起きます。';
			$lines[] = '「公開URL」を設定して再生成すると、本番のドメインに置き換わります。';
			$lines[] = '';
			if ( ! empty( $dev_files ) ) {
				$lines[] = '| 残っているページ |';
				$lines[] = '| --- |';
				foreach ( $dev_files as $file ) {
					$lines[] = '| `' . (string) $file . '` |';
				}
				$lines[] = '';
			}
		}

		// The section that matters most goes near the top when it is non-empty.
		if ( ! empty( $removed ) ) {
			$lines[] = '## ⚠ 本番から削除が必要なファイル';
			$lines[] = '';
			$lines[] = '前回の納品物に含まれていたが、今回は生成されなかったファイルです。';
			$lines[] = 'すでにアップロード済みの場合、本番サーバーから手で削除してください。';
			$lines[] = '';
			foreach ( $removed as $file ) {
				$lines[] = '- ' . self::describe( (string) $file, $entries );
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

		if ( ! empty( $missing ) ) {
			$lines[] = '## コア sitemap にあって未生成のURL';
			$lines[] = '';
			$lines[] = 'WordPress コアの sitemap が挙げているが、今回の列挙では生成されなかったURLです。';
			$lines[] = 'プラグインが登録した経路など、列挙が把握していないページの可能性があります。';
			$lines[] = '内容を確認し、必要なら `distan_sources` で追加してください（不要なら無視して構いません）。';
			$lines[] = '';
			$lines[] = '| URL |';
			$lines[] = '| --- |';
			foreach ( $missing as $url ) {
				$lines[] = '| `' . (string) $url . '` |';
			}
			$lines[] = '';
		}

		if ( ! empty( $large ) ) {
			$lines[] = '## 大きいファイル';
			$lines[] = '';
			$lines[] = '一定サイズを超えるファイルです。これらは自動でコピー済みで、手作業は不要です。';
			$lines[] = '納品物のサイズや配信方法（CDN など）を検討する際の参考にしてください。';
			$lines[] = '';
			$lines[] = '| ファイル | サイズ |';
			$lines[] = '| --- | ---: |';
			arsort( $large );
			foreach ( $large as $file => $bytes ) {
				$lines[] = '| `' . (string) $file . '` | ' . self::format_size( (int) $bytes ) . ' |';
			}
			$lines[] = '';
		}

		if ( ! empty( $added ) ) {
			$lines[] = '## 追加されたファイル';
			$lines[] = '';
			foreach ( $added as $file ) {
				$lines[] = '- ' . self::describe( (string) $file, $entries );
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
	 * A diff line for one output path: its human label and a provenance tag
	 * when known, falling back to the bare path (assets, or manifests
	 * written before provenance existed).
	 *
	 * @param string                              $path    Output path.
	 * @param array<string, array<string, mixed>> $entries path => { label, source }.
	 */
	private static function describe( string $path, array $entries ): string {
		if ( ! isset( $entries[ $path ] ) ) {
			return '`' . $path . '`';
		}

		$label = isset( $entries[ $path ]['label'] ) ? (string) $entries[ $path ]['label'] : '';
		$src   = isset( $entries[ $path ]['source'] ) && is_array( $entries[ $path ]['source'] ) ? $entries[ $path ]['source'] : array();
		$tag   = self::source_tag( $src );
		$head  = '' !== $label ? $label : $path;

		return $head . ( '' !== $tag ? ' ' . $tag : '' ) . ' — `' . $path . '`';
	}

	/**
	 * A short provenance tag like "[投稿 #123]" from an entry's source.
	 *
	 * @param array<string, mixed> $source Provenance.
	 */
	private static function source_tag( array $source ): string {
		if ( empty( $source['kind'] ) ) {
			return '';
		}

		$labels = array(
			'front'        => 'フロントページ',
			'blog_home'    => '投稿ページ',
			'blog_archive' => '投稿アーカイブ',
			'post'         => '投稿',
			'term'         => 'タクソノミー',
			'not_found'    => '404',
			'extra'        => '外部ソース',
		);

		$kind = (string) $source['kind'];
		$name = isset( $labels[ $kind ] ) ? $labels[ $kind ] : $kind;

		if ( isset( $source['id'] ) ) {
			$name .= ' #' . (int) $source['id'];
		}

		if ( isset( $source['page'] ) && (int) $source['page'] > 1 ) {
			$name .= ' p' . (int) $source['page'];
		}

		return '[' . $name . ']';
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

	/**
	 * Build a differential ZIP: only the files added or changed since the last
	 * run, laid out at their real relative paths so the archive unzips straight
	 * onto production. A DELETE.txt lists paths to remove, and a short delivery
	 * note summarises the drop. This is the sibling of {@see build_zip()} for
	 * routine updates; the full ZIP remains for milestones and first delivery.
	 *
	 * Returns null when there is nothing to deliver (no adds, changes, or
	 * deletions) or when ZIP support is unavailable.
	 *
	 * @param array<string, mixed> $manifest Completed manifest.
	 * @param array<string, mixed> $meta     Extra context.
	 * @return string|null Absolute path to the ZIP, or null.
	 */
	public static function build_diff_zip( array $manifest, array $meta = array() ): ?string {
		if ( ! self::can_zip() ) {
			return null;
		}

		$root = Distan_Paths::output_root();

		if ( ! is_dir( $root ) ) {
			return null;
		}

		$added    = isset( $manifest['added'] ) && is_array( $manifest['added'] ) ? $manifest['added'] : array();
		$modified = isset( $manifest['modified'] ) && is_array( $manifest['modified'] ) ? $manifest['modified'] : array();
		$removed  = isset( $manifest['removed'] ) && is_array( $manifest['removed'] ) ? $manifest['removed'] : array();

		// Files to upload: everything added or changed this run, in path order,
		// restricted to those that actually exist on disk right now.
		$targets = array();
		foreach ( array_values( array_unique( array_merge( $added, $modified ) ) ) as $path ) {
			$path     = (string) $path;
			$absolute = $root . '/' . $path;
			if ( is_file( $absolute ) ) {
				$targets[ $path ] = $absolute;
			}
		}

		// Nothing changed and nothing to delete — no deliverable to build.
		if ( empty( $targets ) && empty( $removed ) ) {
			return null;
		}

		$work = Distan_Paths::work_root();

		if ( ! Distan_Paths::ensure_dir( $work ) ) {
			return null;
		}

		$slug     = sanitize_title( get_bloginfo( 'name' ) );
		$slug     = '' !== $slug ? $slug : 'site';
		$filename = $slug . '-diff-' . gmdate( 'Ymd-His' ) . '.zip';
		$zip_path = $work . '/' . $filename;

		$zip = new ZipArchive();

		if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return null;
		}

		foreach ( $targets as $local => $absolute ) {
			$zip->addFile( $absolute, $local );
		}

		// The delivery note and the delete list live at the ZIP root, outside
		// the tree that unzips onto production.
		$zip->addFromString( self::ZIP_DIFF_NOTE_NAME, self::render_diff_note( $manifest, $meta, $targets ) );

		if ( ! empty( $removed ) ) {
			$zip->addFromString( self::ZIP_DELETE_NAME, self::render_delete_list( $removed ) );
		}

		$zip->close();

		return is_file( $zip_path ) ? $zip_path : null;
	}

	/**
	 * The differential delivery note bundled at the ZIP root.
	 *
	 * @param array<string, mixed> $manifest Completed manifest.
	 * @param array<string, mixed> $meta     Extra context.
	 * @param array<string, string> $targets  Uploaded path => absolute path.
	 */
	private static function render_diff_note( array $manifest, array $meta, array $targets ): string {
		$added    = isset( $manifest['added'] ) && is_array( $manifest['added'] ) ? $manifest['added'] : array();
		$modified = isset( $manifest['modified'] ) && is_array( $manifest['modified'] ) ? $manifest['modified'] : array();
		$removed  = isset( $manifest['removed'] ) && is_array( $manifest['removed'] ) ? $manifest['removed'] : array();
		$entries  = isset( $manifest['entries'] ) && is_array( $manifest['entries'] ) ? $manifest['entries'] : array();

		$upload_paths = array_keys( $targets );
		$added_set    = array_flip( array_map( 'strval', $added ) );

		$finished = (int) ( $manifest['finished'] ?? time() );
		$when     = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $finished ), 'Y-m-d H:i' );

		$lines   = array();
		$lines[] = '# Distan 差分納品';
		$lines[] = '';
		$lines[] = '- 生成日時: ' . $when;
		$lines[] = '- サイト: ' . home_url( '/' );
		$lines[] = '- アップロード対象: ' . count( $upload_paths ) . ' 件';
		$lines[] = '- 本番から削除: ' . count( $removed ) . ' 件';
		$lines[] = '';
		$lines[] = 'この ZIP を本番の公開ディレクトリと同じ場所に展開してください。';
		$lines[] = 'フォルダ構成はそのまま本番の配置に対応します。';
		$lines[] = '';

		if ( ! empty( $upload_paths ) ) {
			$lines[] = '## アップロードするファイル';
			$lines[] = '';
			foreach ( $upload_paths as $path ) {
				$tag     = isset( $added_set[ $path ] ) ? '追加' : '変更';
				$lines[] = '- [' . $tag . '] ' . self::describe( (string) $path, $entries );
			}
			$lines[] = '';
		}

		if ( ! empty( $removed ) ) {
			$lines[] = '## 本番から削除するファイル';
			$lines[] = '';
			$lines[] = '前回は納品したが今回は生成されなかったものです。`' . self::ZIP_DELETE_NAME . '` に同じ一覧があります。';
			$lines[] = '本番サーバーから手で削除してください（この ZIP には含まれていません）。';
			$lines[] = '';
			foreach ( $removed as $path ) {
				$lines[] = '- ' . self::describe( (string) $path, $entries );
			}
			$lines[] = '';
		}

		$lines[] = '---';
		$lines[] = '';
		$lines[] = '_Distan ' . DISTAN_VERSION . ' が生成しました。全体を納品したい場合は「ZIPをダウンロード」から一式を取得してください。_';

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * The plain path-per-line delete list bundled as DELETE.txt.
	 *
	 * @param array<int, string> $removed Paths removed since the last run.
	 */
	private static function render_delete_list( array $removed ): string {
		$lines   = array();
		$lines[] = '# 本番サーバーから削除してください';
		$lines[] = '# 前回は納品したが今回は生成されなかったファイルです。';
		$lines[] = '# 1 行 1 パス。公開ディレクトリからの相対パスです。';
		$lines[] = '';
		foreach ( $removed as $path ) {
			$lines[] = (string) $path;
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Format a byte count as a human-readable size (KB / MB / GB).
	 *
	 * @param int $bytes Size in bytes.
	 */
	private static function format_size( int $bytes ): string {
		if ( $bytes >= 1073741824 ) {
			return number_format( $bytes / 1073741824, 1 ) . ' GB';
		}
		if ( $bytes >= 1048576 ) {
			return number_format( $bytes / 1048576, 1 ) . ' MB';
		}
		if ( $bytes >= 1024 ) {
			return number_format( $bytes / 1024, 1 ) . ' KB';
		}
		return $bytes . ' B';
	}
}
