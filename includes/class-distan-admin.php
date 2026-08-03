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
		add_action( 'admin_post_distan_download_report', array( $this, 'download_report' ) );
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
		$zip_path = Distan_Report::build_zip(
			$manifest,
			array( 'link_style' => Distan::settings()['link_style'] )
		);

		if ( null === $zip_path || ! is_file( $zip_path ) ) {
			wp_die( esc_html__( 'ZIPの作成に失敗しました。先に生成を実行してください。', 'distan' ) );
		}

		self::stream_and_delete( $zip_path, 'application/zip' );
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
			'3.15.12',
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
			<h1><?php esc_html_e( 'Distan', 'distan' ); ?></h1>
			<p class="distan-tagline">WordPress Development Environment &rarr; Static Site Generator</p>

			<p class="distan-lede">
				<?php esc_html_e( 'WordPress で作ったページを、納品できる静的HTMLとして書き出します。出力内容はテーマの出力に従うため、納品前に実物を確認してください。', 'distan' ); ?>
			</p>

			<!-- Environment -->
			<section class="hgp-card">
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
			<section class="hgp-card">
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
					<div class="hgp-downloads">
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
					</div>
				</template>

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
								<dd x-text="fmtTime(manifest.finished)"></dd>
							</div>
							<div class="hgp-shipmeta__row">
								<dt><?php esc_html_e( '最終デプロイ', 'distan' ); ?></dt>
								<dd x-text="lastDispatch ? fmtTime(lastDispatch) : '—'"></dd>
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
						<div class="hgp-stat" :class="(manifest.broken || []).length ? 'is-warn' : ''">
							<span class="hgp-stat__num" x-text="(manifest.broken || []).length"></span>
							<span class="hgp-stat__label"><?php esc_html_e( 'リンク切れ', 'distan' ); ?></span>
						</div>
					</div>
				</template>

				<template x-if="genErrors.length">
					<details class="hgp-details is-error" open>
						<summary>
							<?php esc_html_e( '書き出せなかったページ', 'distan' ); ?>
							(<span x-text="genErrors.length"></span>)
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
					<details class="hgp-details is-warn">
						<summary>
							<?php esc_html_e( 'リンク切れ', 'distan' ); ?>
							(<span x-text="manifest.broken.length"></span>)
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
							(<span x-text="manifest.removed.length"></span>)
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
			<section class="hgp-card">
				<form method="post" action="options.php">
					<?php settings_fields( 'distan_settings_group' ); ?>

					<div class="hgp-card__head">
						<h2><?php esc_html_e( '設定', 'distan' ); ?></h2>
						<div class="hgp-card__actions">
							<?php submit_button( __( '設定を保存', 'distan' ), 'primary', 'submit-top', false ); ?>
						</div>
					</div>

					<h3 class="hgp-settings-group hgp-settings-group--first"><?php esc_html_e( '基本設定', 'distan' ); ?></h3>
					<p class="hgp-settings-group__note"><?php esc_html_e( '書き出す静的HTMLの基本設定です。案件に合わせて選びます。', 'distan' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
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
						<tr>
							<th scope="row"><?php esc_html_e( 'ファイル名の形式', 'distan' ); ?></th>
							<td>
								<fieldset>
									<label><input type="radio" name="<?php echo esc_attr( Distan::OPTION_KEY ); ?>[path_style]" value="directory" <?php checked( $settings['path_style'], 'directory' ); ?>> <code>/about/index.html</code></label><br>
									<label><input type="radio" name="<?php echo esc_attr( Distan::OPTION_KEY ); ?>[path_style]" value="flat" <?php checked( $settings['path_style'], 'flat' ); ?>> <code>/about.html</code></label>
								</fieldset>
							</td>
						</tr>
						<tr>
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
						<tr>
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
						<tr>
							<th scope="row"><?php esc_html_e( 'WordPress の痕跡を除く', 'distan' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( Distan::OPTION_KEY ); ?>[clean_html]" value="1" <?php checked( ! empty( $settings['clean_html'] ) ); ?>>
									<?php esc_html_e( '納品用にHTMLを整える', 'distan' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'generator、RSD/WLW、REST API、oEmbed、絵文字、ショートリンク、投機的読み込みを書き出しません。開発環境の noindex も常に除去します。', 'distan' ); ?>
								</p>
							</td>
						</tr>
						</table>

						<h3 class="hgp-settings-group"><?php esc_html_e( '追加オプション', 'distan' ); ?></h3>
						<p class="hgp-settings-group__note"><?php esc_html_e( '必要な場合だけ使うオプションです。通常の納品では設定しなくて構いません。', 'distan' ); ?></p>
						<table class="form-table" role="presentation">
							<tr>
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
						<tr>
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
						<tr>
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
						</table>

						<h3 class="hgp-settings-group"><?php esc_html_e( '公開・デプロイ', 'distan' ); ?></h3>
						<p class="hgp-settings-group__note"><?php esc_html_e( '書き出したあとの公開処理を自分で繋ぐ場合のオプションです。', 'distan' ); ?></p>
						<table class="form-table" role="presentation">
							<tr>
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
		</div>
		<?php
	}
}
