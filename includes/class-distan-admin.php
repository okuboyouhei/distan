<?php
/**
 * Admin screen.
 *
 * @package Distan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI.
 */
class Distan_Admin {

	public const MENU_SLUG = 'distan';

	/**
	 * Hook suffix of our page, for scoped enqueueing.
	 */
	private string $hook_suffix = '';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_init', array( $this, 'ensure_secret' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_distan_download_zip', array( $this, 'download_zip' ) );
		add_action( 'admin_post_distan_download_diff', array( $this, 'download_diff' ) );
		add_action( 'admin_post_distan_download_template', array( $this, 'download_template' ) );
		add_action( 'admin_post_distan_download_report', array( $this, 'download_report' ) );
		add_action( 'admin_post_distan_save_takeup', array( $this, 'save_takeup' ) );
	}

	/**
	 * Stream a freshly built ZIP of the output directory, then delete it.
	 */
	public function download_zip(): void {
		if ( ! current_user_can( Distan::capability() ) ) {
			wp_die( esc_html__( '権限がありません。', 'distan' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'distan_download' );

		if ( ! Distan_Report::can_zip() ) {
			wp_die( esc_html__( 'この環境ではZIPを作成できません（ZipArchive が利用できません）。', 'distan' ) );
		}

		$manifest = Distan_Generator::manifest();
		$zip_path = Distan_Report::build_zip( $manifest );

		if ( null === $zip_path || ! is_file( $zip_path ) ) {
			wp_die( esc_html__( 'ZIPの作成に失敗しました。先に生成を実行してください。', 'distan' ) );
		}

		self::stream_and_delete( $zip_path, 'application/zip' );
	}

	/**
	 * Build and stream the differential ZIP: only the files added or changed
	 * since the last run, plus a delete list and delivery note.
	 */
	public function download_diff(): void {
		if ( ! current_user_can( Distan::capability() ) ) {
			wp_die( esc_html__( '権限がありません。', 'distan' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'distan_download' );

		if ( ! Distan_Report::can_zip() ) {
			wp_die( esc_html__( 'この環境ではZIPを作成できません（ZipArchive が利用できません）。', 'distan' ) );
		}

		$manifest = Distan_Generator::manifest();
		$zip_path = Distan_Report::build_diff_zip( $manifest );

		if ( null === $zip_path || ! is_file( $zip_path ) ) {
			wp_die( esc_html__( '差分ZIPを作成できませんでした。前回から変わったファイルが無いか、まだ一度も生成していない可能性があります。', 'distan' ) );
		}

		self::stream_and_delete( $zip_path, 'application/zip' );
	}

	/**
	 * Build and stream the template ZIP: one chosen page plus only the assets
	 * it references, for handing to an external coder as a page shell.
	 */
	public function download_template(): void {
		if ( ! current_user_can( Distan::capability() ) ) {
			wp_die( esc_html__( '権限がありません。', 'distan' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'distan_download' );

		if ( ! Distan_Report::can_zip() ) {
			wp_die( esc_html__( 'この環境ではZIPを作成できません（ZipArchive が利用できません）。', 'distan' ) );
		}

		// Nonce verified above; a distinct name avoids the reserved 'page' var.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page_path'] ) ? sanitize_text_field( wp_unslash( $_GET['page_path'] ) ) : '';

		if ( '' === $page ) {
			wp_die( esc_html__( 'テンプレートにするページが指定されていません。', 'distan' ) );
		}

		$manifest = Distan_Generator::manifest();
		$zip_path = Distan_Report::build_template_zip( $page, $manifest );

		if ( null === $zip_path || ! is_file( $zip_path ) ) {
			wp_die( esc_html__( 'テンプレートZIPを作成できませんでした。指定したページが生成物に見つからない可能性があります。先に生成を実行してください。', 'distan' ) );
		}

		self::stream_and_delete( $zip_path, 'application/zip' );
	}

	/**
	 * Save the operator's take-up decisions from the coverage panel: which
	 * uncovered URLs to include on the next run, which to stop offering, and
	 * any extra URLs typed in by hand. Redirects back to the generate screen.
	 */
	public function save_takeup(): void {
		if ( ! current_user_can( Distan::capability() ) ) {
			wp_die( esc_html__( '権限がありません。', 'distan' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'distan_takeup' );

		// Start from what is already remembered, then apply this form's choices
		// on top, so decisions about URLs not shown this time are preserved.
		$state   = Distan_Takeup::state();
		$include = array_fill_keys( $state['include'], true );
		$ignore  = array_fill_keys( $state['ignore'], true );

		// Array input is sanitised element-by-element at the boundary, so no
		// raw value reaches the logic below. Nonce is verified above.
		$urls    = isset( $_POST['url'] ) && is_array( $_POST['url'] ) ? map_deep( wp_unslash( $_POST['url'] ), 'esc_url_raw' ) : array();
		$actions = isset( $_POST['takeup'] ) && is_array( $_POST['takeup'] ) ? map_deep( wp_unslash( $_POST['takeup'] ), 'sanitize_key' ) : array();
		$restore = isset( $_POST['restore'] ) && is_array( $_POST['restore'] ) ? map_deep( wp_unslash( $_POST['restore'] ), 'esc_url_raw' ) : array();
		$extra   = isset( $_POST['extra'] ) ? sanitize_textarea_field( wp_unslash( $_POST['extra'] ) ) : '';

		// Candidate rows: each carries a URL and a chosen action.
		foreach ( $urls as $index => $url ) {
			$url = esc_url_raw( (string) $url );

			if ( '' === $url ) {
				continue;
			}

			$action = isset( $actions[ $index ] ) ? sanitize_key( $actions[ $index ] ) : 'pending';

			unset( $include[ $url ], $ignore[ $url ] );

			if ( 'include' === $action ) {
				$include[ $url ] = true;
			} elseif ( 'ignore' === $action ) {
				$ignore[ $url ] = true;
			}
		}

		// Restore checkboxes lift a URL back out of the ignore list.
		foreach ( $restore as $url ) {
			unset( $ignore[ esc_url_raw( (string) $url ) ] );
		}

		// Hand-typed URLs (one per line) are added to the include list.
		foreach ( preg_split( '/\r\n|\r|\n/', $extra ) as $line ) {
			$line = esc_url_raw( trim( $line ) );
			if ( '' !== $line ) {
				$include[ $line ] = true;
			}
		}

		Distan_Takeup::save( array_keys( $include ), array_keys( $ignore ) );

		wp_safe_redirect( admin_url( 'admin.php?page=distan&distan_takeup=saved#distan-generate' ) );
		exit;
	}

	/**
	 * The stored manifest reduced to the fields the results view (Alpine)
	 * reads, or null when nothing has been generated. Hashes and per-entry
	 * provenance are dropped — the client never uses them, and they are the
	 * heavy part of the manifest.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function client_manifest(): ?array {
		$stored = Distan_Generator::manifest();

		if ( empty( $stored['files'] ) ) {
			return null;
		}

		$finished = (int) ( $stored['finished'] ?? 0 );

		return array(
			'files'          => array_values( (array) $stored['files'] ),
			'added'          => array_values( (array) ( $stored['added'] ?? array() ) ),
			'cleaned'        => array_values( (array) ( $stored['cleaned'] ?? array() ) ),
			'broken'         => array_values( (array) ( $stored['broken'] ?? array() ) ),
			'removed'        => array_values( (array) ( $stored['removed'] ?? array() ) ),
			'has_modules'    => ! empty( $stored['has_modules'] ),
			'finished'       => $finished,
			// Pre-formatted in the site's timezone, matching the server-rendered
			// "前回生成" line and the report dates. The client shows this instead
			// of formatting the epoch itself, which would use the browser's
			// timezone and disagree with the rest of the screen.
			'finished_label' => $finished > 0 ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $finished ), 'Y-m-d H:i' ) : '',
		);
	}

	/**
	 * Stream the latest Markdown report.
	 */
	public function download_report(): void {
		if ( ! current_user_can( Distan::capability() ) ) {
			wp_die( esc_html__( '権限がありません。', 'distan' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'distan_download' );

		$path = (string) get_option( 'distan_last_report', '' );

		if ( '' === $path || ! is_file( $path ) ) {
			wp_die( esc_html__( 'レポートが見つかりません。先に生成を実行してください。', 'distan' ) );
		}

		// Containment: the report must live in our work directory.
		if ( ! Distan_Paths::is_contained( $path, Distan_Paths::work_root() ) ) {
			wp_die( esc_html__( '不正なパスです。', 'distan' ) );
		}

		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Send a file as a download and remove it afterwards.
	 */
	private static function stream_and_delete( string $path, string $mime ): void {
		if ( ! Distan_Paths::is_contained( $path, Distan_Paths::work_root() ) ) {
			wp_die( esc_html__( '不正なパスです。', 'distan' ) );
		}

		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );

		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile

		wp_delete_file( $path );
		exit;
	}

	/**
	 * Register the single option and its sanitizer.
	 */
	public function register_settings(): void {
		register_setting(
			'distan_settings_group',
			Distan::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => Distan::defaults(),
			)
		);
	}

	/**
	 * Sanitize submitted settings.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string, mixed>
	 */
	public function sanitize_settings( $input ): array {
		$defaults = Distan::defaults();

		if ( ! is_array( $input ) ) {
			return $defaults;
		}

		$out = array();

		$out['site_url'] = isset( $input['site_url'] )
			? esc_url_raw( trim( (string) $input['site_url'] ) )
			: '';

		$style              = isset( $input['path_style'] ) ? (string) $input['path_style'] : 'directory';
		$out['path_style']  = in_array( $style, array( 'directory', 'flat' ), true ) ? $style : 'directory';

		$link              = isset( $input['link_style'] ) ? (string) $input['link_style'] : 'relative';
		$out['link_style'] = in_array( $link, array( 'relative', 'absolute' ), true ) ? $link : 'relative';

		$out['clean_html']    = ! empty( $input['clean_html'] );
		$out['strip_noindex'] = ! empty( $input['strip_noindex'] );
		$out['keep_indent'] = isset( $input['keep_indent'] ) ? ! empty( $input['keep_indent'] ) : true;
		$out['export_markdown'] = ! empty( $input['export_markdown'] );
		$out['export_markdown_local'] = ! empty( $input['export_markdown_local'] );

		$out['sitemap']         = ! empty( $input['sitemap'] );
		$out['sitemap_exclude'] = isset( $input['sitemap_exclude'] )
			? sanitize_textarea_field( (string) $input['sitemap_exclude'] )
			: '';
		$out['robots']          = ! empty( $input['robots'] );
		$out['diff_zip']        = ! empty( $input['diff_zip'] );
		$out['template_export'] = ! empty( $input['template_export'] );
		$out['enable_dispatch'] = ! empty( $input['enable_dispatch'] );

		return $out;
	}

	/**
	 * Generate the render secret once, on first admin load.
	 */
	public function ensure_secret(): void {
		if ( ! get_option( 'distan_render_secret' ) ) {
			add_option( 'distan_render_secret', wp_generate_password( 40, false ), '', false );
		}
	}

	/**
	 * Top-level menu.
	 */
	public function register_menu(): void {
		$this->hook_suffix = (string) add_menu_page(
			__( 'Distan', 'distan' ),
			__( 'Distan', 'distan' ),
			Distan::capability(),
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'dashicons-download',
			80
		);
	}

	/**
	 * Assets, scoped to our page only.
	 *
	 * Alpine must be defined after our component registration, so our script
	 * is declared as a dependency of Alpine rather than the other way round.
	 * (Lesson carried over from HXFE.)
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( $hook ): void {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'distan-admin',
			DISTAN_URL . 'assets/css/distan-admin.css',
			array(),
			DISTAN_VERSION
		);

		wp_enqueue_script(
			'distan-admin',
			DISTAN_URL . 'assets/js/distan-admin.js',
			array(),
			DISTAN_VERSION,
			true
		);

		wp_localize_script(
			'distan-admin',
			'distanData',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'distan_ajax' ),
				'dispatchEnabled' => ! empty( Distan::settings()['enable_dispatch'] ),
				'lastDispatch'    => (int) get_option( 'distan_last_dispatch', 0 ),
				'lastDispatchLabel' => (int) get_option( 'distan_last_dispatch', 0 ) > 0
					? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) get_option( 'distan_last_dispatch', 0 ) ), 'Y-m-d H:i' )
					: '',
				// The stored manifest (trimmed to the fields the results view
				// reads) lets Alpine restore that view on a fresh load, so a
				// reload never blanks the stats and downloads and the
				// server-rendered panels below stay in step with them.
				'manifest'        => self::client_manifest(),
				'i18n'    => array(
					'checking' => __( '確認中…', 'distan' ),
					'failed'   => __( '確認に失敗しました。', 'distan' ),
					'dispatchFailed' => __( 'デプロイに失敗しました。', 'distan' ),
				),
			)
		);

		// Alpine depends on our script, so our x-data component exists first.
		wp_enqueue_script(
			'distan-alpine',
			DISTAN_URL . 'assets/js/alpine.min.js',
			array( 'distan-admin' ),
			'3.17.1',
			true
		);
	}

	/**
	 * Page markup.
	 */
	public function render_page(): void {
		if ( ! current_user_can( Distan::capability() ) ) {
			wp_die( esc_html__( 'このページを表示する権限がありません。', 'distan' ) );
		}

		$settings = Distan::settings();
		?>
		<div class="wrap distan-wrap" x-data="distanAdmin" x-cloak>
			<div class="distan-header">
				<div class="distan-header__titles">
					<h1><?php esc_html_e( 'Distan', 'distan' ); ?></h1>
					<p class="distan-lede">
						<?php esc_html_e( 'WordPress を制作環境に、納品用の静的HTML を書き出します。', 'distan' ); ?>
					</p>
				</div>
				<button
					type="button"
					class="distan-help-open"
					@click="$dispatch('distan-help-open')"
				>
					<span class="distan-help-open__mark" aria-hidden="true">?</span>
					<?php esc_html_e( '使い方', 'distan' ); ?>
				</button>
			</div>

			<nav class="distan-subnav" aria-label="<?php esc_attr_e( 'ページ内ナビゲーション', 'distan' ); ?>">
				<a href="#distan-env" :class="{ 'is-active': active === 'env' }"><?php esc_html_e( '環境', 'distan' ); ?></a>
				<a href="#distan-generate" :class="{ 'is-active': active === 'gen' }"><?php esc_html_e( '生成', 'distan' ); ?></a>
				<a href="#distan-settings" :class="{ 'is-active': active === 'settings' }"><?php esc_html_e( '設定', 'distan' ); ?></a>
			</nav>

			<!-- Environment -->
			<section class="hgp-card" id="distan-env">
				<div class="hgp-card__head">
					<h2><?php esc_html_e( '環境', 'distan' ); ?></h2>
					<button type="button" class="button" @click="runEnvCheck()" :disabled="envLoading">
						<span x-show="!envLoading"><?php esc_html_e( '確認する', 'distan' ); ?></span>
						<span x-show="envLoading" x-cloak><?php esc_html_e( '確認中…', 'distan' ); ?></span>
					</button>
				</div>

				<p class="hgp-empty" x-show="!envResults.length && !envError">
					<?php esc_html_e( '生成に必要な条件を満たしているか確認します。ループバック通信だけが必須です。', 'distan' ); ?>
				</p>

				<div class="hgp-alert is-error" x-show="envError" x-cloak x-text="envError"></div>

				<table class="hgp-table" x-show="envResults.length" x-cloak>
					<tbody>
						<template x-for="row in envResults" :key="row.id">
							<tr>
								<td class="hgp-table__status">
									<span class="hgp-badge" :class="'is-' + row.status" x-text="statusLabel(row.status)"></span>
								</td>
								<th scope="row" x-text="row.label"></th>
								<td>
									<code class="hgp-detail" x-text="row.detail"></code>
									<p class="hgp-hint" x-show="row.hint" x-text="row.hint"></p>
								</td>
							</tr>
						</template>
					</tbody>
				</table>
			</section>

			<!-- Generate -->
			<section class="hgp-card" id="distan-generate">
				<div class="hgp-card__head">
					<h2><?php esc_html_e( '生成', 'distan' ); ?></h2>
					<div class="hgp-card__actions">
						<label class="hgp-batch">
							<?php esc_html_e( '1回あたり', 'distan' ); ?>
							<input type="number" min="1" max="20" x-model.number="batchSize">
							<?php esc_html_e( 'ページ', 'distan' ); ?>
						</label>
						<button type="button" class="button button-primary" @click="startGeneration()" :disabled="genRunning">
							<span x-show="!genRunning"><?php esc_html_e( '静的HTMLを書き出す', 'distan' ); ?></span>
							<span x-show="genRunning" x-cloak><?php esc_html_e( '書き出し中…', 'distan' ); ?></span>
						</button>
					</div>
				</div>

				<div class="hgp-alert is-error" x-show="genError" x-cloak x-text="genError"></div>

				<div x-show="genTotal > 0" x-cloak class="hgp-progress">
					<div class="hgp-bar"><div class="hgp-bar__fill" :style="'width:' + percent() + '%'"></div></div>
					<p class="hgp-progress__label">
						<span x-text="genIndex"></span> / <span x-text="genTotal"></span>
						<?php esc_html_e( 'ページ', 'distan' ); ?>
					</p>
				</div>

				<template x-if="manifest">
					<div class="hgp-downloads" id="distan-downloads">
						<?php $dl_nonce = wp_create_nonce( 'distan_download' ); ?>
						<?php if ( Distan_Report::can_zip() ) : ?>
							<a class="button button-primary"
								href="<?php echo esc_url( admin_url( 'admin-post.php?action=distan_download_zip&_wpnonce=' . $dl_nonce ) ); ?>">
								<?php esc_html_e( 'ZIPをダウンロード', 'distan' ); ?>
							</a>
						<?php else : ?>
							<span class="hgp-hint">
								<?php esc_html_e( 'この環境ではZIPを作成できません（ZipArchive が無効）。', 'distan' ); ?>
							</span>
						<?php endif; ?>
						<a class="button"
							href="<?php echo esc_url( admin_url( 'admin-post.php?action=distan_download_report&_wpnonce=' . $dl_nonce ) ); ?>">
							<?php esc_html_e( '差分レポート（.md）', 'distan' ); ?>
						</a>
						<?php if ( ! empty( $settings['diff_zip'] ) && Distan_Report::can_zip() ) : ?>
							<a class="button"
								href="<?php echo esc_url( admin_url( 'admin-post.php?action=distan_download_diff&_wpnonce=' . $dl_nonce ) ); ?>">
								<?php esc_html_e( '差分ZIP（変更分のみ）', 'distan' ); ?>
							</a>
						<?php endif; ?>
					</div>
				</template>

				<?php if ( ! empty( $settings['diff_zip'] ) ) : ?>
					<?php
					$diff_source   = Distan_Generator::manifest_source();
					$diff_manifest = Distan_Generator::manifest();
					$diff_finished = isset( $diff_manifest['finished'] ) ? (int) $diff_manifest['finished'] : 0;
					$diff_has_base = ! empty( $diff_manifest['files'] );
					$diff_when     = $diff_finished > 0
						? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $diff_finished ), 'Y-m-d H:i' )
						: '';
					$diff_source_label = 'output' === $diff_source
						? __( 'output（成果物に同梱）', 'distan' )
						: __( 'db（データベース）', 'distan' );
					?>
					<p class="hgp-hint hgp-diff-status">
						<?php
						printf(
							/* translators: %s: db or output */
							esc_html__( '差分の基準: %s', 'distan' ),
							esc_html( $diff_source_label )
						);
						?>
						<?php esc_html_e( '（変更は distan_manifest_source フィルタで）', 'distan' ); ?>
						<?php if ( '' !== $diff_when ) : ?>
							<br>
							<?php
							/* translators: %s: date/time of the last generation */
							printf( esc_html__( '前回生成: %s', 'distan' ), esc_html( $diff_when ) );
							?>
						<?php endif; ?>
						<?php if ( ! $diff_has_base ) : ?>
							<br><strong><?php esc_html_e( '前回の記録がありません。次回の生成では、すべてのページが「追加」として扱われます。', 'distan' ); ?></strong>
						<?php endif; ?>
					</p>
				<?php endif; ?>

				<?php if ( ! empty( $settings['template_export'] ) && Distan_Report::can_zip() ) : ?>
					<?php
					$tpl_manifest = Distan_Generator::manifest();
					$tpl_files    = isset( $tpl_manifest['files'] ) && is_array( $tpl_manifest['files'] ) ? $tpl_manifest['files'] : array();
					$tpl_entries  = isset( $tpl_manifest['entries'] ) && is_array( $tpl_manifest['entries'] ) ? $tpl_manifest['entries'] : array();

					// Only real HTML pages make sense as a shell.
					$tpl_pages = array();
					foreach ( $tpl_files as $tpl_file ) {
						$tpl_file = (string) $tpl_file;
						if ( preg_match( '#\.html?$#i', $tpl_file ) ) {
							$tpl_pages[] = $tpl_file;
						}
					}
					sort( $tpl_pages );
					?>
					<?php if ( ! empty( $tpl_pages ) ) : ?>
						<form class="hgp-template" id="distan-template" method="get" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="distan_download_template">
							<?php wp_nonce_field( 'distan_download' ); ?>
							<h3 class="hgp-template__title"><?php esc_html_e( 'テンプレート書き出し', 'distan' ); ?></h3>
							<p class="hgp-hint hgp-template__note">
								<?php esc_html_e( '選んだ1ページと、それが参照するアセット（CSS・JS・フォント・画像）だけをまとめた ZIP を作ります。共通ヘッダー・フッターに沿った特設ページの制作を外部に依頼するときの雛形として渡せます。ナビ等のリンク先ページや、他ページ専用の素材は含まれません。', 'distan' ); ?>
							</p>
							<p class="hgp-template__row">
								<label class="screen-reader-text" for="distan-template-page"><?php esc_html_e( 'テンプレートにするページ', 'distan' ); ?></label>
								<select id="distan-template-page" name="page_path">
									<?php foreach ( $tpl_pages as $tpl_page ) : ?>
										<?php
										$tpl_label = $tpl_page;
										if ( isset( $tpl_entries[ $tpl_page ]['label'] ) && '' !== (string) $tpl_entries[ $tpl_page ]['label'] ) {
											$tpl_label = (string) $tpl_entries[ $tpl_page ]['label'] . ' — ' . $tpl_page;
										}
										?>
										<option value="<?php echo esc_attr( $tpl_page ); ?>"><?php echo esc_html( $tpl_label ); ?></option>
									<?php endforeach; ?>
								</select>
								<button type="submit" class="button"><?php esc_html_e( 'このページで書き出す', 'distan' ); ?></button>
							</p>
						</form>
					<?php endif; ?>
				<?php endif; ?>

				<?php
				$takeup_manifest = Distan_Generator::manifest();
				$takeup_missing  = ( isset( $takeup_manifest['sitemap_missing'] ) && is_array( $takeup_manifest['sitemap_missing'] ) ) ? $takeup_manifest['sitemap_missing'] : array();
				$takeup_state    = Distan_Takeup::state();
				$takeup_include  = array_fill_keys( $takeup_state['include'], true );
				$takeup_ignore   = array_fill_keys( $takeup_state['ignore'], true );

				// Active candidates: URLs core declares but this run did not
				// generate, minus the ones already set to ignore (those move to
				// the collapsed list below so they stop nagging).
				$takeup_active = array();
				foreach ( $takeup_missing as $missing_url ) {
					$missing_url = (string) $missing_url;
					if ( ! isset( $takeup_ignore[ $missing_url ] ) ) {
						$takeup_active[] = $missing_url;
					}
				}

				// Include URLs the current candidate list does not cover: ones
				// taken up on an earlier run (now generated, so no longer
				// "missing") or typed in by hand. Shown in the free-text box.
				$takeup_extra = array();
				foreach ( $takeup_state['include'] as $inc_url ) {
					if ( ! in_array( $inc_url, $takeup_active, true ) ) {
						$takeup_extra[] = $inc_url;
					}
				}

				$takeup_ignored_list = $takeup_state['ignore'];

				// Include URLs that map to no page on this site would be dropped
				// at write time without a trace. Surface them so a typo in the
				// free-text box fails loudly instead of silently.
				$takeup_unresolved = array();
				foreach ( $takeup_state['include'] as $inc_url ) {
					if ( null === Distan_Urls::url_to_output_path( $inc_url ) ) {
						$takeup_unresolved[] = $inc_url;
					}
				}

				$takeup_has_panel    = ! empty( $takeup_active ) || ! empty( $takeup_extra ) || ! empty( $takeup_ignored_list );
				?>
				<?php if ( $takeup_has_panel ) : ?>
					<form class="hgp-takeup" id="distan-takeup" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="distan_save_takeup">
						<?php wp_nonce_field( 'distan_takeup' ); ?>
						<h3 class="hgp-takeup__title"><?php esc_html_e( '取りこぼしの取り込み', 'distan' ); ?></h3>
						<?php
						// Display-only flag set by the save redirect; no state changes here.
						// phpcs:ignore WordPress.Security.NonceVerification.Recommended
						if ( isset( $_GET['distan_takeup'] ) && 'saved' === $_GET['distan_takeup'] ) :
							?>
							<div class="hgp-alert is-ok"><?php esc_html_e( '取り込みの設定を保存しました。次の生成から反映されます。', 'distan' ); ?></div>
						<?php endif; ?>
						<p class="hgp-hint hgp-takeup__note">
							<?php esc_html_e( 'WordPress コアのサイトマップにあるのに、今回の生成に含まれなかった URL です。プラグインが登録した完了画面など、リンクを辿るだけでは拾えないページがここに出ます。含めるものだけを選んでください（既定では何も取り込みません）。選んだ判断は記憶され、次回以降の生成に反映されます。', 'distan' ); ?>
						</p>

						<?php if ( ! empty( $takeup_active ) ) : ?>
							<div class="hgp-takeup__list">
								<table class="hgp-table hgp-takeup__table">
									<?php foreach ( $takeup_active as $i => $url ) : ?>
										<?php $included = isset( $takeup_include[ $url ] ); ?>
										<tr>
											<td class="hgp-takeup__url"><code><?php echo esc_html( $url ); ?></code>
												<input type="hidden" name="url[<?php echo esc_attr( (string) $i ); ?>]" value="<?php echo esc_attr( $url ); ?>">
											</td>
											<td class="hgp-takeup__choices">
												<label><input type="radio" name="takeup[<?php echo esc_attr( (string) $i ); ?>]" value="pending" <?php checked( ! $included ); ?>> <?php esc_html_e( '未決', 'distan' ); ?></label>
												<label><input type="radio" name="takeup[<?php echo esc_attr( (string) $i ); ?>]" value="include" <?php checked( $included ); ?>> <?php esc_html_e( '含める', 'distan' ); ?></label>
												<label><input type="radio" name="takeup[<?php echo esc_attr( (string) $i ); ?>]" value="ignore"> <?php esc_html_e( '今後表示しない', 'distan' ); ?></label>
											</td>
										</tr>
									<?php endforeach; ?>
								</table>
							</div>
						<?php endif; ?>

						<p class="hgp-takeup__extra">
							<label for="distan-takeup-extra"><?php esc_html_e( 'その他のURL（1行に1つ／このサイト内のみ）', 'distan' ); ?></label>
							<textarea id="distan-takeup-extra" name="extra" rows="3" class="large-text code" placeholder="<?php echo esc_attr( home_url( '/thanks/' ) ); ?>"><?php echo esc_textarea( implode( "\n", $takeup_extra ) ); ?></textarea>
							<span class="hgp-hint"><?php esc_html_e( 'リンク切れレポートなどで見つけた、生成したい URL をここに足せます。ここに書いた URL は毎回の生成に含まれます。', 'distan' ); ?></span>
						</p>

						<?php if ( ! empty( $takeup_unresolved ) ) : ?>
							<div class="hgp-alert is-warn">
								<p><?php esc_html_e( '次のURLはこのサイト内のページに対応しないため、取り込んでも生成されません。取り消すか、正しいURLに直してください。', 'distan' ); ?></p>
								<ul class="hgp-list">
									<?php foreach ( $takeup_unresolved as $bad_url ) : ?>
										<li><code><?php echo esc_html( $bad_url ); ?></code></li>
									<?php endforeach; ?>
								</ul>
							</div>
						<?php endif; ?>

						<?php if ( ! empty( $takeup_ignored_list ) ) : ?>
							<details class="hgp-details hgp-takeup__ignored">
								<summary><?php esc_html_e( '今後表示しないにした URL', 'distan' ); ?> <span class="hgp-count"><?php echo count( $takeup_ignored_list ); ?></span></summary>
								<?php foreach ( $takeup_ignored_list as $ig_url ) : ?>
									<label class="hgp-takeup__restore">
										<input type="checkbox" name="restore[]" value="<?php echo esc_attr( $ig_url ); ?>">
										<?php esc_html_e( '候補に戻す', 'distan' ); ?>
										<code><?php echo esc_html( $ig_url ); ?></code>
									</label>
								<?php endforeach; ?>
							</details>
						<?php endif; ?>

						<p class="hgp-takeup__save">
							<button type="submit" class="button"><?php esc_html_e( '保存', 'distan' ); ?></button>
							<span class="hgp-hint"><?php esc_html_e( '保存後、次に生成すると取り込んだ URL が含まれます。', 'distan' ); ?></span>
						</p>
					</form>
				<?php endif; ?>

				<template x-if="manifest && dispatchEnabled">
					<div class="hgp-dispatch">
						<div class="hgp-dispatch__action">
							<button type="button" class="button button-primary" @click="dispatch()" :disabled="dispatching">
								<span x-show="!dispatching"><?php esc_html_e( 'デプロイ', 'distan' ); ?></span>
								<span x-show="dispatching" x-cloak><?php esc_html_e( 'デプロイ中…', 'distan' ); ?></span>
							</button>
							<p class="hgp-hint">
								<?php esc_html_e( '確認できたら押してください。distan_dispatch アクションが発火し、繋いだ公開処理が動きます。', 'distan' ); ?>
							</p>
						</div>
						<div class="hgp-dispatch__error hgp-alert is-error" x-show="dispatchError" x-cloak x-text="dispatchError"></div>
						<dl class="hgp-shipmeta">
							<div class="hgp-shipmeta__row">
								<dt><?php esc_html_e( '最終生成', 'distan' ); ?></dt>
								<dd x-text="manifest.finished_label || '—'"></dd>
							</div>
							<div class="hgp-shipmeta__row">
								<dt><?php esc_html_e( '最終デプロイ', 'distan' ); ?></dt>
								<dd x-text="lastDispatchLabel || '—'"></dd>
							</div>
						</dl>
					</div>
				</template>

				<template x-if="manifest">
					<div class="hgp-stats">
						<div class="hgp-stat">
							<span class="hgp-stat__num" x-text="manifest.files.length"></span>
							<span class="hgp-stat__label"><?php esc_html_e( '出力ファイル', 'distan' ); ?></span>
						</div>
						<div class="hgp-stat">
							<span class="hgp-stat__num" x-text="manifest.added.length"></span>
							<span class="hgp-stat__label"><?php esc_html_e( '追加', 'distan' ); ?></span>
						</div>
						<div class="hgp-stat">
							<span class="hgp-stat__num" x-text="(manifest.cleaned || []).length"></span>
							<span class="hgp-stat__label"><?php esc_html_e( '出力先から削除', 'distan' ); ?></span>
						</div>
						<div class="hgp-stat" :class="(manifest.broken || []).length ? 'is-error' : ''">
							<span class="hgp-stat__num" x-text="(manifest.broken || []).length"></span>
							<span class="hgp-stat__label"><?php esc_html_e( 'リンク切れ', 'distan' ); ?></span>
						</div>
					</div>
				</template>

				<template x-if="genErrors.length">
					<details class="hgp-details is-error" open>
						<summary>
							<?php esc_html_e( '書き出せなかったページ', 'distan' ); ?>
							<span class="hgp-count" x-text="genErrors.length"></span>
						</summary>
						<table class="hgp-table hgp-table--pairs">
							<template x-for="e in genErrors" :key="e.url">
								<tr><td><code x-text="e.url"></code></td><td x-text="e.message"></td></tr>
							</template>
						</table>
					</details>
				</template>

				<template x-if="manifest && manifest.has_modules">
					<details class="hgp-details is-warn">
						<summary><?php esc_html_e( 'file:// で開くと一部の JavaScript が動作しません', 'distan' ); ?></summary>
						<p class="hgp-hint">
							<?php esc_html_e( '生成物に ES modules（type="module" / importmap）が含まれています。ブロックテーマでよく使われますが、ブラウザの仕様により、ファイルをダブルクリックして file:// で開くと読み込めません。', 'distan' ); ?>
						</p>
						<p class="hgp-hint">
							<?php esc_html_e( 'Web サーバー（納品先）では正常に動作します。ローカルで確認する場合は、出力先で簡易サーバーを立ててください。', 'distan' ); ?>
						</p>
						<pre class="hgp-code">python3 -m http.server 8000</pre>
						<p class="hgp-hint">
							<?php esc_html_e( 'クラシックテーマで ES modules を使わずに作ると、この制約は起きません。', 'distan' ); ?>
						</p>
					</details>
				</template>

				<template x-if="manifest && manifest.files.indexOf('404.html') !== -1">
					<details class="hgp-details">
						<summary><?php esc_html_e( '404ページのサーバー設定', 'distan' ); ?></summary>
						<p class="hgp-hint">
							<?php esc_html_e( '404.html を書き出しました。存在しないURLでこのページを表示するには、サーバー側の設定が必要です。設定できない場合、サーバー既定の404画面が表示されます。', 'distan' ); ?>
						</p>
						<p class="hgp-hint"><strong>Apache</strong>（<code>.htaccess</code>）</p>
						<pre class="hgp-code">ErrorDocument 404 /404.html</pre>
						<p class="hgp-hint"><strong>nginx</strong></p>
						<pre class="hgp-code">error_page 404 /404.html;</pre>
						<p class="hgp-hint">
							<?php esc_html_e( 'Netlify や Cloudflare Pages では、ルート直下の 404.html が自動的に使われるため設定は不要です。なお 404ページ内のリンクは、どの階層で表示されても壊れないよう常に公開URLで書き出しています。', 'distan' ); ?>
						</p>
					</details>
				</template>

				<template x-if="manifest && manifest.broken && manifest.broken.length">
					<details class="hgp-details is-error">
						<summary>
							<?php esc_html_e( 'リンク切れ', 'distan' ); ?>
							<span class="hgp-count" x-text="manifest.broken.length"></span>
						</summary>
						<p class="hgp-hint">
							<?php esc_html_e( 'リンク先のファイルが書き出されていません。テーマがカテゴリーや著者アーカイブへリンクしている場合に起きます。自動では修正しません。', 'distan' ); ?>
						</p>
						<table class="hgp-table hgp-table--pairs">
							<template x-for="b in manifest.broken" :key="b.from + b.to">
								<tr><td><code x-text="b.from"></code></td><td><code x-text="b.to"></code></td></tr>
							</template>
						</table>
					</details>
				</template>

				<template x-if="manifest && manifest.removed && manifest.removed.length">
					<details class="hgp-details is-warn">
						<summary>
							<?php esc_html_e( '本番から削除が必要なファイル', 'distan' ); ?>
							<span class="hgp-count" x-text="manifest.removed.length"></span>
						</summary>
						<p class="hgp-hint">
							<?php esc_html_e( '前回の納品物に含まれていたファイルです。出力先からは削除済みです。すでにアップロード済みの場合は、本番サーバーから手で削除してください。', 'distan' ); ?>
						</p>
						<ul class="hgp-list">
							<template x-for="f in manifest.removed" :key="f">
								<li><code x-text="f"></code></li>
							</template>
						</ul>
					</details>
				</template>
			</section>

			<!-- Settings -->
			<section class="hgp-card" id="distan-settings">
				<form method="post" action="options.php">
					<?php settings_fields( 'distan_settings_group' ); ?>

					<div class="hgp-card__head">
						<h2><?php esc_html_e( '設定', 'distan' ); ?></h2>
					</div>

					<h3 class="hgp-settings-group hgp-settings-group--first"><?php esc_html_e( '基本設定', 'distan' ); ?></h3>
					<p class="hgp-settings-group__note"><?php esc_html_e( '書き出す静的HTMLの基本設定です。案件に合わせて選んでください。', 'distan' ); ?></p>
					<table class="form-table" role="presentation">
						<tr id="set-site-url">
							<th scope="row">
								<label for="distan-site-url"><?php esc_html_e( '公開URL', 'distan' ); ?></label>
							</th>
							<td>
								<input type="url" id="distan-site-url" class="regular-text"
									name="<?php echo esc_attr( Distan::OPTION_KEY ); ?>[site_url]"
									value="<?php echo esc_attr( (string) $settings['site_url'] ); ?>"
									placeholder="https://example.com/">
								<p class="description">
									<?php esc_html_e( 'canonical と OGP にのみ使います。内部リンクはドキュメント相対で書き出すため、設置場所は選びません。', 'distan' ); ?>
								</p>
							</td>
						</tr>
						<tr id="set-path-style">
							<th scope="row"><?php esc_html_e( 'ファイル名の形式', 'distan' ); ?></th>
							<td>
								<fieldset>
									<label><input type="radio" name="<?php echo esc_attr( Distan::OPTION_KEY ); ?>[path_style]" value="directory" <?php checked( $settings['path_style'], 'directory' ); ?>> <code>/about/index.html</code></label><br>
									<label><input type="radio" name="<?php echo esc_attr( Distan::OPTION_KEY ); ?>[path_style]" value="flat" <?php checked( $settings['path_style'], 'flat' ); ?>> <code>/about.html</code></label>
								</fieldset>
							</td>
						</tr>
						<tr id="set-link-style">
							<th scope="row"><?php esc_html_e( '内部リンクの書き方', 'distan' ); ?></th>
							<td>
								<fieldset>
									<label>
										<input type="radio" name="<?php echo esc_attr( Distan::OPTION_KEY ); ?>[link_style]" value="relative" <?php checked( $settings['link_style'], 'relative' ); ?>>
										<?php esc_html_e( 'ドキュメント相対', 'distan' ); ?>
										<code>../about/index.html</code>
									</label>
									<p class="description">
										<?php esc_html_e( '納品向け。ZIPを解凍してそのまま開けます。設置場所も選びません。', 'distan' ); ?>
									</p>
									<label>
										<input type="radio" name="<?php echo esc_attr( Distan::OPTION_KEY ); ?>[link_style]" value="absolute" <?php checked( $settings['link_style'], 'absolute' ); ?>>
										<?php esc_html_e( '公開URLで絶対指定', 'distan' ); ?>
										<code>https://example.com/about/index.html</code>
									</label>
									<p class="description">
										<?php esc_html_e( 'バックアップ向け。障害時にサーバーのドキュメントルートへ置けば、階層を気にせずそのまま表示できます。公開URLの設定が必要です。', 'distan' ); ?>
									</p>
								</fieldset>
							</td>
						</tr>
						<tr id="set-noindex">
							<th scope="row"><?php esc_html_e( '検索エンジンの扱い', 'distan' ); ?></th>
							<td>
								<fieldset>
									<label>
										<input type="radio" name="<?php echo esc_attr( Distan::OPTION_KEY ); ?>[strip_noindex]" value="1" <?php checked( ! empty( $settings['strip_noindex'] ) ); ?>>
										<?php esc_html_e( 'noindex を除去する（本番納品）', 'distan' ); ?>
									</label>
									<p class="description">
										<?php esc_html_e( '開発環境の「検索エンジンでの表示を許可しない」設定が納品物に焼き込まれるのを防ぎます。通常はこちら。', 'distan' ); ?>
									</p>
									<label>
										<input type="radio" name="<?php echo esc_attr( Distan::OPTION_KEY ); ?>[strip_noindex]" value="0" <?php checked( empty( $settings['strip_noindex'] ) ); ?>>
										<?php esc_html_e( 'noindex を残す（テスト環境での確認）', 'distan' ); ?>
									</label>
									<p class="description">
										<?php esc_html_e( '公開前にテスト環境で見せる場合など、検索に拾われたくないときに使います。', 'distan' ); ?>
									</p>
								</fieldset>
								<?php if ( empty( $settings['strip_noindex'] ) ) : ?>
									<div class="hgp-inline-warn">
										<?php esc_html_e( '⚠ この設定のまま本番用に生成すると、noindex が残り本番が検索結果に表示されなくなります。納品前に「除去する」へ戻してください。', 'distan' ); ?>
									</div>
								<?php endif; ?>
							</td>
						</tr>
						<tr id="set-clean-html">
							<th scope="row"><?php esc_html_e( 'WordPress の痕跡を除く', 'distan' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( Distan::OPTION_KEY ); ?>[clean_html]" value="1" <?php checked( ! empty( $settings['clean_html'] ) ); ?>>
									<?php esc_html_e( '納品用にHTMLを整える', 'distan' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'generator、RSD/WLW、REST API、oEmbed、絵文字、ショートリンク、投機的読み込みを書き出しません。開発環境の noindex も常に除去します。消すのは見た目に影響しないメタ情報だけで、ブロック用 CSS（wp-block-library / global-styles）やプラグインの JS は対象外です。特定ページからそれらを外したいときは、テンプレート書き出しのマーカーを使ってください（テンプレートの <head> などに貼り付け）。', 'distan' ); ?>
								</p>
								<div class="distan-snippet" x-data="{ copied: false }">
									<code class="distan-snippet__code">&lt;!-- distan:no-block-styles --&gt;</code>
									<button type="button" class="button distan-snippet__copy" @click="navigator.clipboard && navigator.clipboard.writeText('<!-- distan:no-block-styles -->'); copied = true; setTimeout(() =&gt; copied = false, 1500)">
										<span x-show="!copied"><?php esc_html_e( 'コピー', 'distan' ); ?></span>
										<span x-show="copied" x-cloak><?php esc_html_e( 'コピー済み', 'distan' ); ?></span>
									</button>
								</div>
								<div class="distan-snippet" x-data="{ copied: false }">
									<code class="distan-snippet__code">&lt;!-- distan:drop-assets wp-includes/ wp-content/plugins/foo/ --&gt;</code>
									<button type="button" class="button distan-snippet__copy" @click="navigator.clipboard && navigator.clipboard.writeText('<!-- distan:drop-assets wp-includes/ wp-content/plugins/foo/ -->'); copied = true; setTimeout(() =&gt; copied = false, 1500)">
										<span x-show="!copied"><?php esc_html_e( 'コピー', 'distan' ); ?></span>
										<span x-show="copied" x-cloak><?php esc_html_e( 'コピー済み', 'distan' ); ?></span>
									</button>
								</div>
							</td>
						</tr>
						</table>

						<h3 class="hgp-settings-group"><?php esc_html_e( '追加オプション', 'distan' ); ?></h3>
						<p class="hgp-settings-group__note"><?php esc_html_e( '必要な場合だけ使うオプションです。通常の納品では設定しなくて構いません。', 'distan' ); ?></p>
						<table class="form-table" role="presentation">
							<tr id="set-markdown">
								<th scope="row"><?php esc_html_e( 'Markdown を書き出す', 'distan' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( Distan::OPTION_KEY ); ?>[export_markdown]" value="1" <?php checked( ! empty( $settings['export_markdown'] ) ); ?>>
									<?php esc_html_e( 'サイト全体を content.md にまとめる', 'distan' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( '全ページの本文を1つの Markdown ファイル（content.md）にまとめて書き出します。Gemini Notebook（旧NotebookLM）などの AI ツールにサイト内容を読み込ませる用途に。ヘッダー・フッター・ナビは除き、本文だけを抽出します。URL は公開URL（サイトURL設定）に置換されます。', 'distan' ); ?>
								</p>
								<label style="display:block;margin-top:.5em">
									<input type="checkbox" name="<?php echo esc_attr( Distan::OPTION_KEY ); ?>[export_markdown_local]" value="1" <?php checked( ! empty( $settings['export_markdown_local'] ) ); ?>>
									<?php esc_html_e( '制作環境URLのままの版（content.local.md）も書き出す', 'distan' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( '開発・データ管理用に、URL を置換していない版も出力します。納品物には通常不要です。', 'distan' ); ?>
								</p>
							</td>
						</tr>
						<tr id="set-sitemap">
							<th scope="row"><?php esc_html_e( 'サイトマップを書き出す', 'distan' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( Distan::OPTION_KEY ); ?>[sitemap]" value="1" <?php checked( ! empty( $settings['sitemap'] ) ); ?>>
									<?php esc_html_e( 'sitemap.xml を書き出す', 'distan' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( '書き出したページから sitemap.xml を生成します。Google Search Console に登録できる標準形式です。著者や日付のアーカイブは生成対象外のため、?author=1 のような ID がサイトマップに載ることはありません。URL は公開URL（サイトURL設定）になります。', 'distan' ); ?>
								</p>
								<label style="display:block;margin-top:.75em" for="distan-sitemap-exclude">
									<?php esc_html_e( 'サイトマップから除外する（1行に1つ）', 'distan' ); ?>
								</label>
								<textarea id="distan-sitemap-exclude" name="<?php echo esc_attr( Distan::OPTION_KEY ); ?>[sitemap_exclude]" rows="4" class="large-text code" placeholder="/private/&#10;draft"><?php echo esc_textarea( (string) $settings['sitemap_exclude'] ); ?></textarea>
								<p class="description">
									<?php esc_html_e( '「/private/」のようにスラッシュで始めると、そのスラッグ以下をすべて除外します。「draft」のように書くと、その語をパスに含むURLを除外します。', 'distan' ); ?>
								</p>
							</td>
						</tr>
						<tr id="set-robots">
							<th scope="row"><?php esc_html_e( 'robots.txt を書き出す', 'distan' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( Distan::OPTION_KEY ); ?>[robots]" value="1" <?php checked( ! empty( $settings['robots'] ) ); ?>>
									<?php esc_html_e( 'robots.txt を書き出す', 'distan' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( '「すべてクロールを許可」する最小の robots.txt を書き出します。上のサイトマップが有効なときは、その場所（Sitemap:）も記載します。すでにサーバーに robots.txt を置いている場合は、オフのままにしてください。', 'distan' ); ?>
								</p>
							</td>
						</tr>
						<tr id="set-diff-zip">
							<th scope="row"><?php esc_html_e( '差分ZIP', 'distan' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( Distan::OPTION_KEY ); ?>[diff_zip]" value="1" <?php checked( ! empty( $settings['diff_zip'] ) ); ?>>
									<?php esc_html_e( '生成画面に「差分ZIP（変更分のみ）」のダウンロードを表示する', 'distan' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( '前回の生成から追加・変更されたファイルだけをまとめた ZIP を出せます。解凍して本番の同じ場所に上げれば済むので、全体を上げ直す必要がありません。削除すべきファイルは同梱の DELETE.txt に一覧されます。オフにしてもページの変更検知は常に働くので、いつオンにしてもその時点から正しく差分が出ます。', 'distan' ); ?>
								</p>
								<p class="description">
									<?php esc_html_e( '生成した環境と別のサーバーへ納品する場合は、差分の基準を成果物側に持たせる distan_manifest_source フィルタ（output）の利用を検討してください。', 'distan' ); ?>
								</p>
							</td>
						</tr>
						<tr id="set-template-export">
							<th scope="row"><?php esc_html_e( 'テンプレート書き出し', 'distan' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( Distan::OPTION_KEY ); ?>[template_export]" value="1" <?php checked( ! empty( $settings['template_export'] ) ); ?>>
									<?php esc_html_e( '生成画面に「テンプレート書き出し」を表示する', 'distan' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( '生成済みのページを1枚選ぶと、そのページと、それが参照している CSS・JS・フォント・画像だけをまとめた ZIP を出せます。共通ヘッダー・フッターに沿った特設ページの制作を外部に依頼するときの雛形として渡せます。参照アセットのみを同梱するので、ナビの遷移先ページや他ページ専用の素材は含まれません。ZIP のルートには制作者向けの README.md（触ってよい範囲・相対パスの注意など）を同梱します。テンプレートに distan:no-block-styles / distan:drop-assets のマーカーを書いておくと、この雛形だけからブロック用 CSS や指定したスクリプトを外せます（サイト全体の「WordPress の痕跡を除く」とは別で、選んだ1ページにのみ効きます）。', 'distan' ); ?>
								</p>
							</td>
						</tr>
						</table>

						<h3 class="hgp-settings-group"><?php esc_html_e( '公開・デプロイ', 'distan' ); ?></h3>
						<p class="hgp-settings-group__note"><?php esc_html_e( '書き出したあとの公開処理を自分で繋ぐ場合のオプションです。', 'distan' ); ?></p>
						<table class="form-table" role="presentation">
							<tr id="set-dispatch-button">
								<th scope="row"><?php esc_html_e( 'デプロイボタン', 'distan' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( Distan::OPTION_KEY ); ?>[enable_dispatch]" value="1" <?php checked( ! empty( $settings['enable_dispatch'] ) ); ?>>
									<?php esc_html_e( '生成画面に「デプロイ」ボタンを表示する', 'distan' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( '生成物を目視で確認したあと、手動で押すためのボタンです。押すと distan_dispatch アクションが発火し、このアクションに繋いだ公開処理（git push / rsync / ビルドWebhook など）が動きます。処理を繋いでいなければ何も起きません。通常の書き出しだけなら不要です。', 'distan' ); ?>
								</p>
							</td>
						</tr>
					</table>
					<div class="hgp-settings-save">
						<?php submit_button( __( '設定を保存', 'distan' ), 'primary', 'submit', false ); ?>
					</div>
				</form>
			</section>

			<?php $this->render_help(); ?>
		</div>
		<?php
	}

	/**
	 * The floating help tool, pinned bottom-right of the admin screen.
	 *
	 * Behaviour is a small inline Alpine island (open/close only) — no new
	 * JavaScript file, no registered component. Content is authored in PHP so
	 * it is translatable. The tool orients a first-time user toward the flow
	 * (環境 → 生成 → 受け取り／公開) and points at the sections where the
	 * detailed, contextual help already lives; it does not duplicate it.
	 */
	private function render_help(): void {
		$doc_url = 'https://github.com/okuboyouhei/distan/blob/main/README.md';
		?>
		<div
			x-data="{ open: false }"
			@distan-help-open.window="open = true"
			@keydown.escape.window="open = false"
		>
			<div
				class="distan-modal"
				x-show="open"
				x-cloak
				x-transition.opacity
			>
				<div class="distan-modal__backdrop" @click="open = false"></div>

				<div
					class="distan-help__panel"
					role="dialog"
					aria-modal="true"
					aria-labelledby="distan-help-title"
				>
				<div class="distan-help__head">
					<h2 class="distan-help__title" id="distan-help-title"><?php esc_html_e( '使い方', 'distan' ); ?></h2>
					<button
						type="button"
						class="distan-help__close"
						@click="open = false"
						aria-label="<?php esc_attr_e( '閉じる', 'distan' ); ?>"
					>&times;</button>
				</div>

				<div class="distan-help__body">
					<h3 class="distan-help__group"><?php esc_html_e( 'Distan とは', 'distan' ); ?></h3>
					<p class="distan-help__doc-p">
						<?php esc_html_e( 'WordPress で作ったサイトを、そのまま表示できる静的な HTML として書き出す道具です。書き出した一式は PHP もデータベースも要らないので、どんなサーバーにも置け、環境が変わっても表示され続けます。WordPress は「作る場所」、書き出した HTML が「納品物」という考え方です。', 'distan' ); ?>
					</p>

					<h3 class="distan-help__group"><?php esc_html_e( '使い方の流れ', 'distan' ); ?></h3>
					<ol class="distan-help__doc-steps">
						<li>
							<strong><a href="#distan-env" @click="open = false"><?php esc_html_e( '環境を確認する', 'distan' ); ?></a></strong>
							<?php esc_html_e( '「環境」タブで、書き出しに必要な条件（ループバック通信）が通るか確認します。', 'distan' ); ?>
						</li>
						<li>
							<strong><a href="#distan-generate" @click="open = false"><?php esc_html_e( '書き出す', 'distan' ); ?></a></strong>
							<?php esc_html_e( '「生成」タブの「静的HTMLを書き出す」で、全ページを書き出します。', 'distan' ); ?>
						</li>
						<li>
							<strong><a href="#distan-downloads" @click="open = false"><?php esc_html_e( '受け取る・公開する', 'distan' ); ?></a></strong>
							<?php esc_html_e( 'ZIP をダウンロードして納品するか、「デプロイ」で公開処理につなげます。変わった分だけ渡すなら「差分ZIP」を使います。', 'distan' ); ?>
						</li>
					</ol>

					<h3 class="distan-help__group"><?php esc_html_e( '主な設定', 'distan' ); ?></h3>
					<dl class="distan-help__doc-defs">
						<dt><a class="distan-help__jump" href="#set-site-url" @click="open = false"><?php esc_html_e( '公開URL', 'distan' ); ?></a></dt>
						<dd><?php esc_html_e( 'canonical と OGP に使う本番の URL。内部リンクはドキュメント相対で書き出すので、置き場所は選びません。', 'distan' ); ?></dd>

						<dt><a class="distan-help__jump" href="#set-path-style" @click="open = false"><?php esc_html_e( 'ファイル名の形式', 'distan' ); ?></a></dt>
						<dd><?php esc_html_e( '/about/index.html 形式か、/about.html 形式かを選びます。', 'distan' ); ?></dd>

						<dt><a class="distan-help__jump" href="#set-link-style" @click="open = false"><?php esc_html_e( '内部リンクの書き方', 'distan' ); ?></a></dt>
						<dd><?php esc_html_e( '「ドキュメント相対」は納品向け（解凍してそのまま開けます）。「公開URLで絶対指定」はバックアップ向け（ドキュメントルートに置いて表示）。', 'distan' ); ?></dd>

						<dt><a class="distan-help__jump" href="#set-noindex" @click="open = false"><?php esc_html_e( '検索エンジンの扱い', 'distan' ); ?></a></dt>
						<dd><?php esc_html_e( '本番納品では noindex を除去します。テスト環境で見せるだけなら残します。', 'distan' ); ?></dd>

						<dt><a class="distan-help__jump" href="#set-clean-html" @click="open = false"><?php esc_html_e( 'WordPress の痕跡を除く', 'distan' ); ?></a></dt>
						<dd><?php esc_html_e( 'generator や絵文字などの余分な出力を全ページから省き、納品用に整えます（メタ情報のみ。ブロック用 CSS やプラグイン JS は対象外）。', 'distan' ); ?></dd>

						<dt><a class="distan-help__jump" href="#set-markdown" @click="open = false"><?php esc_html_e( 'Markdown を書き出す', 'distan' ); ?></a></dt>
						<dd><?php esc_html_e( '全ページの本文を content.md にまとめます。AI ツールにサイト内容を読ませる用途です。', 'distan' ); ?></dd>

						<dt><a class="distan-help__jump" href="#set-sitemap" @click="open = false"><?php esc_html_e( 'サイトマップ / robots.txt', 'distan' ); ?></a></dt>
						<dd><?php esc_html_e( '必要なら sitemap.xml と robots.txt を書き出します。', 'distan' ); ?></dd>

						<dt><a class="distan-help__jump" href="#set-diff-zip" @click="open = false"><?php esc_html_e( '差分ZIP', 'distan' ); ?></a></dt>
						<dd><?php esc_html_e( '前回の生成から追加・変更されたファイルだけをまとめた ZIP を出せます。削除すべきファイルは同梱の DELETE.txt に一覧されます。', 'distan' ); ?></dd>

						<dt><a class="distan-help__jump" href="#set-template-export" @click="open = false"><?php esc_html_e( 'テンプレート書き出し', 'distan' ); ?></a></dt>
						<dd><?php esc_html_e( '選んだ1ページと参照アセットだけをまとめて、外部コーダー向けの雛形として渡せます（この1枚だけが対象）。テンプレートに distan:no-block-styles / distan:drop-assets を書けば、その雛形からブロック用 CSS や指定スクリプトを外せます。', 'distan' ); ?></dd>

						<dt><a class="distan-help__jump" href="#set-dispatch-button" @click="open = false"><?php esc_html_e( 'デプロイ', 'distan' ); ?></a></dt>
						<dd><?php esc_html_e( '生成物を確認したあと、自分の公開処理（git push / rsync など）を手動でつなぐためのボタンです。', 'distan' ); ?></dd>
					</dl>

					<h3 class="distan-help__group"><?php esc_html_e( 'テンプレートのマーカー', 'distan' ); ?></h3>
					<p class="distan-help__doc-p">
						<?php esc_html_e( '特設ページのテンプレートに次のコメントを書いておくと、そのページをテンプレート書き出しで選んだとき、雛形から不要なものを外せます。以下をそのままコピーして、テンプレートの <head> などに貼り付けてください。', 'distan' ); ?>
					</p>

					<div class="distan-help__snippet" x-data="{ copied: false }">
						<pre class="distan-help__code"><code>&lt;!-- distan:no-block-styles --&gt;</code></pre>
						<button type="button" class="button distan-help__copy" @click="navigator.clipboard && navigator.clipboard.writeText('<!-- distan:no-block-styles -->'); copied = true; setTimeout(() =&gt; copied = false, 1500)">
							<span x-show="!copied"><?php esc_html_e( 'コピー', 'distan' ); ?></span>
							<span x-show="copied" x-cloak><?php esc_html_e( 'コピーしました', 'distan' ); ?></span>
						</button>
						<p class="distan-help__doc"><?php esc_html_e( 'ブロックエディタの標準スタイル（wp-block-library / global-styles とその関連インライン）を外します。自前で CSS を書く特設ページ向け。', 'distan' ); ?></p>
					</div>

					<div class="distan-help__snippet" x-data="{ copied: false }">
						<pre class="distan-help__code"><code>&lt;!-- distan:drop-assets wp-includes/ wp-content/plugins/foo/ --&gt;</code></pre>
						<button type="button" class="button distan-help__copy" @click="navigator.clipboard && navigator.clipboard.writeText('<!-- distan:drop-assets wp-includes/ wp-content/plugins/foo/ -->'); copied = true; setTimeout(() =&gt; copied = false, 1500)">
							<span x-show="!copied"><?php esc_html_e( 'コピー', 'distan' ); ?></span>
							<span x-show="copied" x-cloak><?php esc_html_e( 'コピーしました', 'distan' ); ?></span>
						</button>
						<p class="distan-help__doc"><?php esc_html_e( 'スペース区切りで書いたパス（前方一致）のスクリプト・スタイルを外します。ディレクトリ（wp-includes/ など）でもファイル単体でも指定できます。foo は実際のプラグイン名などに置き換えてください。', 'distan' ); ?></p>
					</div>

					<h3 class="distan-help__group"><?php esc_html_e( 'もっと詳しく', 'distan' ); ?></h3>
					<p class="distan-help__doc">
						<a href="<?php echo esc_url( $doc_url ); ?>" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'ドキュメント（GitHub）', 'distan' ); ?>
							<span class="distan-help__ext" aria-hidden="true">↗</span>
						</a>
					</p>
				</div>
				</div>
			</div>
		</div>
		<?php
	}
}
