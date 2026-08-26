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
	 * Filename used at the root of the template ZIP: a handoff README with the
	 * ground rules for building a new page against the site's shared chrome.
	 */
	public const ZIP_TEMPLATE_README_NAME = 'README.md';

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

		$link_style = 'absolute' === self::link_style_of( $manifest, $meta )
			? '公開URLで絶対指定'
			: 'ドキュメント相対';

		$site = self::manifest_site( $manifest );

		$lines   = array();
		$lines[] = '# Distan 生成レポート';
		$lines[] = '';
		$lines[] = '- 生成日時: ' . $when;
		$lines[] = '- リンク書式: ' . $link_style;
		$lines[] = '- サイト: ' . $site;
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
			$lines[] = '生成物に開発環境のドメイン（' . $site . '）を指すURLが ' . $dev_count . ' 件残っています。';
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

		$filename = self::site_slug( $manifest ) . '-' . gmdate( 'Ymd-His' ) . '.zip';
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

		$filename = self::site_slug( $manifest ) . '-diff-' . gmdate( 'Ymd-His' ) . '.zip';
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
		$lines[] = '- サイト: ' . self::manifest_site( $manifest );
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
	 * Build a "template" ZIP for handing one page to an external coder as the
	 * shell for a new special page: a single generated HTML file plus only the
	 * assets that page actually references (its CSS/JS, fonts and images,
	 * followed recursively through stylesheet url() and @import), laid out at
	 * their real relative paths so the page opens and renders as-is.
	 *
	 * The whole-site asset tree is deliberately not bundled — the coder needs
	 * the shared chrome (header/footer/head), not every other page's imagery.
	 * A dynamically-loaded asset the scan cannot see shows up as a visibly
	 * broken preview, a loud and catchable failure; contrast the content-region
	 * carving we do not attempt, whose failures would be silent.
	 *
	 * @param string               $page     Output-relative path of the page to ship (e.g. 'info/index.html').
	 * @param array<string, mixed> $manifest Completed manifest, for the README summary.
	 * @param array<string, mixed> $meta     Extra context (link_style, etc.).
	 * @return string|null Absolute path to the ZIP, or null on failure.
	 */
	public static function build_template_zip( string $page, array $manifest, array $meta = array() ): ?string {
		if ( ! self::can_zip() ) {
			return null;
		}

		$root = Distan_Paths::output_root();

		if ( ! is_dir( $root ) ) {
			return null;
		}

		// The page must be a real, contained HTML file in the output tree.
		$relative = Distan_Paths::validate_relative( $page );

		if ( null === $relative || ! self::is_html_path( $relative ) ) {
			return null;
		}

		$page_abs = $root . '/' . $relative;

		if ( ! is_file( $page_abs ) || ! Distan_Paths::is_contained( $page_abs, $root ) ) {
			return null;
		}

		$assets = self::collect_page_assets( $relative, self::artifact_origins( $manifest ) );

		$work = Distan_Paths::work_root();

		if ( ! Distan_Paths::ensure_dir( $work ) ) {
			return null;
		}

		$filename = self::site_slug( $manifest ) . '-template-' . gmdate( 'Ymd-His' ) . '.zip';
		$zip_path = $work . '/' . $filename;

		$zip = new ZipArchive();

		if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return null;
		}

		// The page and its assets keep their real output-relative paths, so the
		// archive unzips into a working local copy of that one page.
		$zip->addFile( $page_abs, $relative );

		foreach ( $assets as $asset ) {
			$zip->addFile( $root . '/' . $asset, $asset );
		}

		// The README lives at the ZIP root, outside the page tree.
		$zip->addFromString( self::ZIP_TEMPLATE_README_NAME, self::render_template_readme( $relative, $assets, $manifest, $meta ) );

		$zip->close();

		return is_file( $zip_path ) ? $zip_path : null;
	}

	/**
	 * The output-relative asset paths a page references, followed recursively
	 * through stylesheets. Reads the finished output tree, so every path is
	 * resolved against files that already exist on disk. Returns a sorted,
	 * de-duplicated list; the page itself and any other HTML page are excluded
	 * (links to sibling pages are expected to dangle in a one-page handoff).
	 *
	 * @param string             $page    Output-relative path of the page.
	 * @param array<int, string> $origins Trailing-slashed same-origin prefixes to
	 *                                    strip from absolute-mode references.
	 * @return array<int, string> Output-relative asset paths.
	 */
	private static function collect_page_assets( string $page, array $origins ): array {
		$root = Distan_Paths::output_root();

		$html = self::read_output_file( $page );

		if ( '' === $html ) {
			return array();
		}

		$found    = array(); // output-relative path => true.
		$css_seen = array(); // stylesheets already walked.
		$css_todo = array();

		$consider = static function ( ?string $resolved ) use ( $root, &$found, &$css_seen, &$css_todo ): void {
			if ( null === $resolved || self::is_html_path( $resolved ) ) {
				return;
			}
			if ( ! is_file( $root . '/' . $resolved ) ) {
				return;
			}
			$found[ $resolved ] = true;
			if ( self::is_css_path( $resolved ) && ! isset( $css_seen[ $resolved ] ) ) {
				$css_todo[] = $resolved;
			}
		};

		$page_dir = self::dir_of( $page );
		foreach ( self::extract_refs( $html ) as $raw ) {
			$consider( self::resolve_ref( $raw, $page_dir, $origins ) );
		}

		// Follow stylesheets: their url() and @import pull in fonts, images and
		// nested CSS. Each reference resolves against that stylesheet's own
		// output location, matching how the generator wrote them.
		while ( ! empty( $css_todo ) ) {
			$css_path = array_shift( $css_todo );

			if ( isset( $css_seen[ $css_path ] ) ) {
				continue;
			}
			$css_seen[ $css_path ] = true;

			$css = self::read_output_file( $css_path );

			if ( '' === $css ) {
				continue;
			}

			$css_dir = self::dir_of( $css_path );
			foreach ( self::extract_css_refs( $css ) as $raw ) {
				$consider( self::resolve_ref( $raw, $css_dir, $origins ) );
			}
		}

		unset( $found[ $page ] );

		$list = array_keys( $found );
		sort( $list );

		return $list;
	}

	/**
	 * Read a file from the output tree, or '' when it is missing or escapes it.
	 *
	 * @param string $relative Output-relative path.
	 */
	private static function read_output_file( string $relative ): string {
		$root = Distan_Paths::output_root();
		$abs  = $root . '/' . $relative;

		if ( ! is_file( $abs ) || ! Distan_Paths::is_contained( $abs, $root ) ) {
			return '';
		}

		$contents = file_get_contents( $abs ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents

		return false === $contents ? '' : $contents;
	}

	/**
	 * Every candidate reference in an HTML document: asset URLs from the same
	 * attributes the rewriter touches, each srcset candidate, and url() in
	 * inline and embedded CSS. Raw strings; resolution and filtering follow.
	 *
	 * @param string $html Document markup.
	 * @return array<int, string>
	 */
	private static function extract_refs( string $html ): array {
		$refs = array();

		if ( preg_match_all( '#\s(?:href|src|poster|data-src)=(["\'])(.*?)\1#is', $html, $m ) ) {
			foreach ( $m[2] as $value ) {
				$refs[] = $value;
			}
		}

		if ( preg_match_all( '#\s(?:srcset|imagesrcset)=(["\'])(.*?)\1#is', $html, $m ) ) {
			foreach ( $m[2] as $value ) {
				foreach ( self::srcset_urls( $value ) as $url ) {
					$refs[] = $url;
				}
			}
		}

		foreach ( self::extract_css_refs( $html ) as $url ) {
			$refs[] = $url;
		}

		return $refs;
	}

	/**
	 * url() and @import targets in a stylesheet (or an embedded <style>). Raw.
	 *
	 * @param string $css Stylesheet text.
	 * @return array<int, string>
	 */
	private static function extract_css_refs( string $css ): array {
		$refs = array();

		if ( preg_match_all( '#url\(\s*([\'"]?)([^)\'"]+)\1\s*\)#i', $css, $m ) ) {
			foreach ( $m[2] as $value ) {
				$refs[] = trim( $value );
			}
		}

		// @import "x.css"; — the url(...) form is already caught above.
		if ( preg_match_all( '#@import\s+([\'"])(.*?)\1#i', $css, $m ) ) {
			foreach ( $m[2] as $value ) {
				$refs[] = trim( $value );
			}
		}

		return $refs;
	}

	/**
	 * The URL of each candidate in a srcset value ("a.jpg 1x, b.jpg 2x").
	 *
	 * @param string $value srcset attribute contents.
	 * @return array<int, string>
	 */
	private static function srcset_urls( string $value ): array {
		$urls = array();

		foreach ( explode( ',', $value ) as $candidate ) {
			$candidate = trim( $candidate );
			if ( '' === $candidate ) {
				continue;
			}
			$parts = preg_split( '#\s+#', $candidate );
			if ( ! empty( $parts[0] ) ) {
				$urls[] = $parts[0];
			}
		}

		return $urls;
	}

	/**
	 * Resolve one raw reference to an output-relative path, or null when it
	 * points outside the deliverable (external origin, data URI, fragment,
	 * mail/tel/js scheme, or a climb that escapes the output root).
	 *
	 * Handles both link styles: document-relative references ('../assets/…')
	 * resolve against the document's directory; production-absolute ones
	 * (link_style = absolute) have the site origin stripped back to their
	 * output-relative path. Query strings and fragments are dropped.
	 *
	 * @param string             $raw     The reference as written in the document.
	 * @param string             $doc_dir Output-relative directory of the document ('' at root).
	 * @param array<int, string> $origins Trailing-slashed same-origin prefixes (as generated).
	 */
	private static function resolve_ref( string $raw, string $doc_dir, array $origins ): ?string {
		$raw = trim( $raw );

		if ( '' === $raw
			|| str_starts_with( $raw, '#' )
			|| str_starts_with( $raw, 'data:' )
			|| str_starts_with( $raw, 'mailto:' )
			|| str_starts_with( $raw, 'tel:' )
			|| str_starts_with( $raw, 'javascript:' )
			|| str_starts_with( $raw, '//' )
		) {
			return null;
		}

		// Drop query and fragment for the filesystem lookup.
		$cut = strcspn( $raw, '?#' );
		if ( $cut < strlen( $raw ) ) {
			$raw = substr( $raw, 0, $cut );
		}

		if ( '' === $raw ) {
			return null;
		}

		if ( preg_match( '#^https?://#i', $raw ) ) {
			// Absolute-mode references carry the production origin; keep only
			// same-origin ones (as recorded in the manifest at generation) and
			// reduce them to a root-relative path.
			$candidate = null;

			foreach ( $origins as $origin ) {
				if ( '' !== $origin && str_starts_with( $raw, $origin ) ) {
					$candidate = substr( $raw, strlen( $origin ) );
					break;
				}
			}

			if ( null === $candidate ) {
				return null; // Another origin — a genuinely external asset.
			}
		} elseif ( str_starts_with( $raw, '/' ) ) {
			// Root-relative: relative to the output root.
			$candidate = ltrim( $raw, '/' );
		} else {
			// Document-relative: resolve against the document's directory.
			$candidate = '' !== $doc_dir ? $doc_dir . '/' . $raw : $raw;
		}

		$candidate = self::collapse( rawurldecode( $candidate ) );

		if ( '' === $candidate ) {
			return null;
		}

		// validate_relative rejects any residual traversal or scheme.
		return Distan_Paths::validate_relative( $candidate );
	}

	/**
	 * Resolve '.' and '..' segments in a path without touching disk.
	 */
	private static function collapse( string $path ): string {
		$out = array();

		foreach ( explode( '/', $path ) as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				array_pop( $out );
				continue;
			}
			$out[] = $part;
		}

		return implode( '/', $out );
	}

	/**
	 * The directory portion of an output-relative path ('' for a root file).
	 */
	private static function dir_of( string $relative ): string {
		$pos = strrpos( $relative, '/' );

		return false === $pos ? '' : substr( $relative, 0, $pos );
	}

	/**
	 * Whether a path names an HTML page.
	 */
	private static function is_html_path( string $path ): bool {
		return (bool) preg_match( '#\.html?$#i', $path );
	}

	/**
	 * Whether a path names a stylesheet.
	 */
	private static function is_css_path( string $path ): bool {
		return (bool) preg_match( '#\.css$#i', $path );
	}

	/**
	 * The handoff README bundled at the ZIP root.
	 *
	 * @param string               $page     Output-relative page path.
	 * @param array<int, string>   $assets   Bundled asset paths.
	 * @param array<string, mixed> $manifest Completed manifest.
	 * @param array<string, mixed> $meta     Extra context.
	 */
	private static function render_template_readme( string $page, array $assets, array $manifest, array $meta ): string {
		$link_absolute = 'absolute' === self::link_style_of( $manifest, $meta );

		// 生成日時 = when the site was last built (how fresh the chrome is).
		// 書き出し日時 = when this ZIP was cut, which can be later. Both are shown
		// in the site's timezone, formatted like the diff report's date line.
		$finished  = (int) ( $manifest['finished'] ?? time() );
		$generated = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $finished ), 'Y-m-d H:i' );
		$exported  = get_date_from_gmt( gmdate( 'Y-m-d H:i:s' ), 'Y-m-d H:i' );

		$lines   = array();
		$lines[] = '# 特設ページ テンプレート一式';
		$lines[] = '';
		$lines[] = '- 生成日時: ' . $generated . '（サイトを最後に静的化した時刻）';
		$lines[] = '- 書き出し日時: ' . $exported . '（この一式を書き出した時刻）';
		$lines[] = '- サイト: ' . self::manifest_site( $manifest );
		$lines[] = '- テンプレートページ: `' . $page . '`';
		$lines[] = '- 同梱アセット: ' . count( $assets ) . ' 件';
		$lines[] = '';
		$lines[] = 'このZIPは、既存サイトの共通ヘッダー・フッターに合わせて新しい特設ページを制作するための雛形です。';
		$lines[] = '`' . $page . '` が見本のHTMLで、そのページが参照しているCSS・JS・フォント・画像だけを、本番と同じ相対配置で同梱しています。';
		$lines[] = '';
		$lines[] = '## 作業のしかた';
		$lines[] = '';
		$lines[] = '1. ZIPを解凍し、`' . $page . '` をブラウザで開いて表示を確認してください。';
		$lines[] = '2. `<head>`・ヘッダー・フッターは変更しないでください。ここが本番とバイト単位で一致していることが、サイトに載せたときに見た目が揃う条件です。';
		$lines[] = '3. 本文（`<main>` などのコンテンツ領域）だけを、特設ページの内容に差し替えてください。';
		$lines[] = '4. 新しく使う画像・CSS・JSは分かりやすいフォルダ（例: `assets/special/`）にまとめ、相対パスで参照してください。';
		$lines[] = '5. 完成したHTML一式（追加したアセットを含む）を納品してください。';
		$lines[] = '';
		$lines[] = '## 注意';
		$lines[] = '';
		$lines[] = '- ヘッダー等のナビゲーションのリンク先ページは、この雛形には含まれていません。クリックすると表示できませんが、想定どおりです（本番では既存ページに繋がります）。';
		if ( $link_absolute ) {
			$lines[] = '- このサイトはリンクを公開URLの絶対指定で書き出しています。ローカルで開くと一部の参照が本番サーバーを見に行くため、確認はインターネット接続のある状態で行ってください。';
		} else {
			$lines[] = '- リンクはドキュメント相対で書き出しているため、解凍してそのまま開けばローカルでも見た目が再現されます。フォルダ構成（相対パス）は崩さないでください。';
		}
		$lines[] = '- 文字コードは UTF-8 です。';
		$lines[] = '';
		$lines[] = '## 同梱アセット';
		$lines[] = '';
		if ( ! empty( $assets ) ) {
			foreach ( $assets as $asset ) {
				$lines[] = '- `' . (string) $asset . '`';
			}
		} else {
			$lines[] = '（このページは同梱すべき外部アセットを参照していません）';
		}
		$lines[] = '';
		$lines[] = '---';
		$lines[] = '';
		$lines[] = '_Distan ' . DISTAN_VERSION . ' が生成しました。_';

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * The site's public URL as recorded in the manifest at generation time,
	 * falling back to the live value for manifests written before it was
	 * stamped. Consumers read this rather than calling home_url() directly, so
	 * the deliverable reflects the site as it was generated.
	 *
	 * @param array<string, mixed> $manifest Completed manifest.
	 */
	private static function manifest_site( array $manifest ): string {
		$site = isset( $manifest['site'] ) ? trim( (string) $manifest['site'] ) : '';

		return '' !== $site ? $site : home_url( '/' );
	}

	/**
	 * The download filename slug, from the site name recorded in the manifest
	 * (live blog name as a fallback). 'site' when both are empty.
	 *
	 * @param array<string, mixed> $manifest Completed manifest.
	 */
	private static function site_slug( array $manifest ): string {
		$name = isset( $manifest['name'] ) ? (string) $manifest['name'] : get_bloginfo( 'name' );
		$slug = sanitize_title( $name );

		return '' !== $slug ? $slug : 'site';
	}

	/**
	 * The link style the artifact was generated with. An explicit $meta value
	 * wins (the producer passes it right after a run); otherwise the manifest's
	 * as-generated value, then the live setting for older manifests.
	 *
	 * @param array<string, mixed> $manifest Completed manifest.
	 * @param array<string, mixed> $meta     Caller-supplied overrides.
	 */
	private static function link_style_of( array $manifest, array $meta ): string {
		if ( isset( $meta['link_style'] ) && '' !== (string) $meta['link_style'] ) {
			return (string) $meta['link_style'];
		}
		if ( isset( $manifest['link_style'] ) && '' !== (string) $manifest['link_style'] ) {
			return (string) $manifest['link_style'];
		}

		return (string) Distan::settings()['link_style'];
	}

	/**
	 * Same-origin prefixes to strip from absolute-mode references, taken from
	 * the manifest (production URL and site URL as generated), with the live
	 * values as a fallback. Trailing-slashed, de-duplicated, empties dropped.
	 *
	 * @param array<string, mixed> $manifest Completed manifest.
	 * @return array<int, string>
	 */
	private static function artifact_origins( array $manifest ): array {
		$candidates = array(
			isset( $manifest['site_url'] ) ? (string) $manifest['site_url'] : (string) Distan::settings()['site_url'],
			self::manifest_site( $manifest ),
		);

		$origins = array();
		foreach ( $candidates as $candidate ) {
			$candidate = trim( $candidate );
			if ( '' === $candidate ) {
				continue;
			}
			$origin = trailingslashit( $candidate );
			if ( ! in_array( $origin, $origins, true ) ) {
				$origins[] = $origin;
			}
		}

		return $origins;
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
