<?php
/**
 * Environment checks.
 *
 * Distan makes exactly one hard demand on its host: the site must be able
 * to make an HTTP request to itself. Everything else degrades gracefully.
 * This class reports the state of that demand, plus the soft requirements,
 * so the user finds out before a generation run rather than during one.
 *
 * @package Distan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Environment probe.
 */
class Distan_Env {

	public const STATUS_OK      = 'ok';
	public const STATUS_WARNING = 'warning';
	public const STATUS_ERROR   = 'error';

	/**
	 * Run every check.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function run_all(): array {
		return array(
			self::check_loopback(),
			self::check_permalinks(),
			self::check_output_dir(),
			self::check_image_library(),
			self::check_execution_time(),
		);
	}

	/**
	 * Whether the environment is usable at all.
	 *
	 * @param array<int, array<string, string>> $results Check results.
	 */
	public static function is_usable( array $results ): bool {
		foreach ( $results as $result ) {
			if ( self::STATUS_ERROR === $result['status'] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The one hard requirement: can the site fetch itself over HTTP?
	 *
	 * @return array<string, string>
	 */
	public static function check_loopback(): array {
		$url = add_query_arg( 'hgp_probe', wp_generate_password( 12, false ), home_url( '/' ) );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'sslverify'   => false, // Local certs are frequently self-signed.
				'redirection' => 2,
				'headers'     => array( 'X-Distan-Probe' => '1' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::result(
				'loopback',
				__( 'ループバック通信', 'distan' ),
				self::STATUS_ERROR,
				sprintf(
					/* translators: %s: error message */
					__( '自サイトへのHTTPリクエストが失敗しました: %s', 'distan' ),
					$response->get_error_message()
				),
				__( 'Basic認証やhostsの設定、内部DNSの解決を確認してください。この項目が通らない限り生成はできません。', 'distan' )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 401 === $code || 403 === $code ) {
			return self::result(
				'loopback',
				__( 'ループバック通信', 'distan' ),
				self::STATUS_ERROR,
				sprintf(
					/* translators: %d: HTTP status code */
					__( '認証に阻まれました（HTTP %d）', 'distan' ),
					$code
				),
				__( 'Basic認証がかかっている場合、自分自身へのリクエストも弾かれます。ローカル環境では認証を外してください。', 'distan' )
			);
		}

		if ( $code < 200 || $code >= 400 ) {
			return self::result(
				'loopback',
				__( 'ループバック通信', 'distan' ),
				self::STATUS_ERROR,
				sprintf(
					/* translators: %d: HTTP status code */
					__( '想定外の応答です（HTTP %d）', 'distan' ),
					$code
				),
				''
			);
		}

		return self::result(
			'loopback',
			__( 'ループバック通信', 'distan' ),
			self::STATUS_OK,
			__( '自サイトへのHTTPリクエストが通りました。', 'distan' ),
			''
		);
	}

	/**
	 * Pretty permalinks. Plain permalinks cannot map to a file tree.
	 *
	 * @return array<string, string>
	 */
	public static function check_permalinks(): array {
		$structure = get_option( 'permalink_structure' );

		if ( empty( $structure ) ) {
			return self::result(
				'permalinks',
				__( 'パーマリンク設定', 'distan' ),
				self::STATUS_ERROR,
				__( '「基本」（?p=123）が設定されています。', 'distan' ),
				__( 'クエリ文字列のURLは静的ファイルに対応付けられません。「投稿名」などに変更してください。', 'distan' )
			);
		}

		return self::result(
			'permalinks',
			__( 'パーマリンク設定', 'distan' ),
			self::STATUS_OK,
			$structure,
			''
		);
	}

	/**
	 * Output directory writability.
	 *
	 * @return array<string, string>
	 */
	public static function check_output_dir(): array {
		$root = Distan_Paths::output_root();

		// A check must not change state. If the directory does not exist yet,
		// report on whether it could be created rather than creating it.
		if ( ! is_dir( $root ) ) {
			$parent = dirname( $root );

			while ( ! is_dir( $parent ) && dirname( $parent ) !== $parent ) {
				$parent = dirname( $parent );
			}

			if ( ! wp_is_writable( $parent ) ) {
				return self::result(
					'output',
					__( '出力先ディレクトリ', 'distan' ),
					self::STATUS_ERROR,
					sprintf(
						/* translators: %s: directory path */
						__( '未作成。親ディレクトリに書き込めません: %s', 'distan' ),
						$parent
					),
					__( 'uploads配下の権限を確認してください。', 'distan' )
				);
			}

			return self::result(
				'output',
				__( '出力先ディレクトリ', 'distan' ),
				self::STATUS_WARNING,
				sprintf(
					/* translators: %s: directory path */
					__( '未作成（生成開始時に作られます）: %s', 'distan' ),
					$root
				),
				''
			);
		}

		if ( ! wp_is_writable( $root ) ) {
			return self::result(
				'output',
				__( '出力先ディレクトリ', 'distan' ),
				self::STATUS_ERROR,
				sprintf(
					/* translators: %s: directory path */
					__( '書き込みできません: %s', 'distan' ),
					$root
				),
				''
			);
		}

		return self::result(
			'output',
			__( '出力先ディレクトリ', 'distan' ),
			self::STATUS_OK,
			$root,
			''
		);
	}

	/**
	 * GD or Imagick. Only needed if image processing is used.
	 *
	 * @return array<string, string>
	 */
	public static function check_image_library(): array {
		$available = array();

		if ( extension_loaded( 'gd' ) ) {
			$available[] = 'GD';
		}
		if ( extension_loaded( 'imagick' ) ) {
			$available[] = 'Imagick';
		}

		if ( empty( $available ) ) {
			return self::result(
				'image',
				__( '画像ライブラリ', 'distan' ),
				self::STATUS_WARNING,
				__( 'GD・Imagickのどちらも利用できません。', 'distan' ),
				__( '生成そのものは可能ですが、画像処理を伴う機能は使えません。', 'distan' )
			);
		}

		return self::result(
			'image',
			__( '画像ライブラリ', 'distan' ),
			self::STATUS_OK,
			implode( ' / ', $available ),
			''
		);
	}

	/**
	 * Execution time. Informational: generation is batched regardless.
	 *
	 * @return array<string, string>
	 */
	public static function check_execution_time(): array {
		$limit = (int) ini_get( 'max_execution_time' );

		if ( 0 === $limit ) {
			return self::result(
				'exec_time',
				__( '実行時間制限', 'distan' ),
				self::STATUS_OK,
				__( '無制限', 'distan' ),
				''
			);
		}

		$status = $limit < 30 ? self::STATUS_WARNING : self::STATUS_OK;

		return self::result(
			'exec_time',
			__( '実行時間制限', 'distan' ),
			$status,
			sprintf(
				/* translators: %d: seconds */
				__( '%d 秒', 'distan' ),
				$limit
			),
			self::STATUS_WARNING === $status
				? __( '短いため、1バッチあたりの生成件数を小さくしてください。生成自体は分割実行されます。', 'distan' )
				: ''
		);
	}

	/**
	 * Shape a result row.
	 *
	 * @return array<string, string>
	 */
	private static function result( string $id, string $label, string $status, string $detail, string $hint ): array {
		return array(
			'id'     => $id,
			'label'  => $label,
			'status' => $status,
			'detail' => $detail,
			'hint'   => $hint,
		);
	}
}
