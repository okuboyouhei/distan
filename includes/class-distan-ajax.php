<?php
/**
 * AJAX endpoints.
 *
 * @package Distan
 */

defined( 'ABSPATH' ) || exit;

/**
 * AJAX handlers.
 */
class Distan_Ajax {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_ajax_distan_env_check', array( $this, 'env_check' ) );
		add_action( 'wp_ajax_distan_start', array( $this, 'start' ) );
		add_action( 'wp_ajax_distan_batch', array( $this, 'batch' ) );
		add_action( 'wp_ajax_distan_dispatch', array( $this, 'dispatch' ) );
	}

	/**
	 * Build the queue and report its size before anything is written.
	 */
	public function start(): void {
		$this->guard();

		// Refuse to start a second run over the first. The job and the output
		// directory are shared (a single transient, a single dist folder), so
		// two overlapping generations corrupt each other's progress and files.
		$running = Distan_Generator::running_job();

		if ( null !== $running ) {
			$user  = get_userdata( (int) ( $running['user_id'] ?? 0 ) );
			$who   = ( $user && '' !== $user->display_name )
				? $user->display_name
				: __( '別のユーザー', 'distan' );
			$since = isset( $running['started'] )
				? wp_date( 'H:i', (int) $running['started'] )
				: '';

			wp_send_json_error(
				array(
					'message' => '' !== $since
						/* translators: 1: user who started the run, 2: start time */
						? sprintf( __( '%1$s が生成を実行中です（開始 %2$s）。完了を待ってから、もう一度お試しください。', 'distan' ), $who, $since )
						/* translators: %s: user who started the run */
						: sprintf( __( '%s が生成を実行中です。完了を待ってから、もう一度お試しください。', 'distan' ), $who ),
				),
				409
			);
		}

		$env = Distan_Env::run_all();

		if ( ! Distan_Env::is_usable( $env ) ) {
			wp_send_json_error(
				array( 'message' => __( '環境チェックに失敗しています。先に環境を確認してください。', 'distan' ) ),
				400
			);
		}

		wp_send_json_success( Distan_Generator::start() );
	}

	/**
	 * Process one batch.
	 */
	public function batch(): void {
		$this->guard();

		// Nonce and capability are verified in guard() above.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$size = isset( $_POST['size'] ) ? absint( wp_unslash( $_POST['size'] ) ) : 5;

		$state = Distan_Generator::process_batch( $size );

		if ( ! empty( $state['done'] ) ) {
			$state['manifest'] = Distan_Generator::manifest();
		}

		wp_send_json_success( $state );
	}

	/**
	 * Fire the manual dispatch hook.
	 *
	 * This is the human gate: the site owner has looked at the generated
	 * output and pressed "デプロイ" (dispatch) to promote it. Distan holds no
	 * approval state — pressing the button IS the confirmation. All we
	 * record is when it last happened, so the screen can reassure the
	 * operator and prevent accidental double-clicks. The actual promotion
	 * (git push / rsync / build webhook) belongs to whatever the project
	 * hangs off the distan_dispatch action.
	 */
	public function dispatch(): void {
		$this->guard();

		$settings = Distan::settings();

		if ( empty( $settings['enable_dispatch'] ) ) {
			wp_send_json_error(
				array( 'message' => __( 'デプロイは無効です。設定で有効にしてください。', 'distan' ) ),
				400
			);
		}

		$manifest = Distan_Generator::manifest();

		if ( empty( $manifest['files'] ) ) {
			wp_send_json_error(
				array( 'message' => __( 'デプロイできる生成物がありません。先に生成を実行してください。', 'distan' ) ),
				400
			);
		}

		$now = time();

		/**
		 * Fires when the operator manually dispatches the current output.
		 *
		 * Distan does not deploy — pressing the button only announces that
		 * the human-reviewed output is cleared to go. Wire your promotion
		 * step (git push, rsync, an SFTP sync, a Netlify / Cloudflare Pages
		 * build webhook) to this action. No approval state is stored; the
		 * press itself is the confirmation.
		 *
		 * @param array $manifest The last generation manifest (files, added,
		 *                        removed, broken, cleaned, finished).
		 * @param int   $dispatched_at Unix timestamp of this dispatch.
		 */
		do_action( 'distan_dispatch', $manifest, $now );

		update_option( 'distan_last_dispatch', $now, false );

		wp_send_json_success( array( 'dispatched_at' => $now ) );
	}

	/**
	 * Verify nonce and capability, or die.
	 */
	private function guard(): void {
		if ( ! current_user_can( Distan::capability() ) ) {
			wp_send_json_error(
				array( 'message' => __( '権限がありません。', 'distan' ) ),
				403
			);
		}

		$nonce = isset( $_POST['nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['nonce'] ) )
			: '';

		if ( ! wp_verify_nonce( $nonce, 'distan_ajax' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'セッションが無効です。ページを再読み込みしてください。', 'distan' ) ),
				403
			);
		}
	}

	/**
	 * Run the environment checks.
	 */
	public function env_check(): void {
		$this->guard();

		$results = Distan_Env::run_all();

		wp_send_json_success(
			array(
				'results' => $results,
				'usable'  => Distan_Env::is_usable( $results ),
			)
		);
	}
}
