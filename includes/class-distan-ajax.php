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
	}

	/**
	 * Build the queue and report its size before anything is written.
	 */
	public function start(): void {
		$this->guard();

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
