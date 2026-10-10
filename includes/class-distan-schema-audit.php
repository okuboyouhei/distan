<?php
/**
 * Read-only, bounded checks of the exact HTML written by the generator.
 * No network/context expansion, no edits, and no live settings in consumers.
 *
 * @package Distan
 */
defined( 'ABSPATH' ) || exit;

class Distan_Schema_Audit {
	/** Translate stable outcome codes at the presentation boundary. */
	public static function status_label( string $status ): string {
		$labels = array(
			'added' => __( '自動追加', 'distan' ),
			'existing' => __( '既存データを保持', 'distan' ),
			'excluded' => __( '除外指定', 'distan' ),
			'disabled' => __( '自動追加オフ', 'distan' ),
			'not_found' => __( '404（追加対象外）', 'distan' ),
			'missing_url' => __( '公開URL未設定・無効', 'distan' ),
			'dom_unavailable' => __( 'DOM拡張なし・未検査', 'distan' ),
			'parse_failed' => __( 'HTML解析不可・未検査', 'distan' ),
			'missing_path' => __( '出力パスなし', 'distan' ),
			'missing_head' => __( 'head要素なし', 'distan' ),
			'encode_failed' => __( 'JSON生成失敗', 'distan' ),
			'failed' => __( 'ページ生成失敗・未検査', 'distan' ),
		);
		return $labels[ $status ] ?? __( '未検査', 'distan' );
	}

	/** A stable code, severity and actionable explanation, never a "schema valid" verdict. */
	public static function issue_text( string $code ): string {
		$messages = array(
			'empty_json' => __( 'JSON-LDが空です。出力元のテーマ・SEOプラグインを確認してください。', 'distan' ),
			'invalid_json' => __( 'JSONを解析できません（構文不正または深さ64超）。元データは保持しています。', 'distan' ),
			'invalid_root' => __( 'JSON-LDの最上位がオブジェクトまたはオブジェクトの配列になっていません。', 'distan' ),
			'no_type' => __( '@typeを検出できません。空のデータや独自contextの使用を確認してください。', 'distan' ),
			'dev_url' => __( 'JSON-LD内に開発環境のURLが残っています。公開URLと置換設定を確認してください。', 'distan' ),
			'canonical_mismatch' => __( 'ページを表すJSON-LDのURLとcanonicalが一致しません。意図したURLか確認してください。', 'distan' ),
			'canonical_multiple' => __( 'canonicalが複数あります。出力元が重複していないか確認してください。', 'distan' ),
			'canonical_relative' => __( 'canonicalが絶対HTTP(S) URLではないため、URLの照合を行っていません。', 'distan' ),
			'id_repeated' => __( '同じ@idの定義が複数あります。意図した分割定義か確認してください（参照のみは対象外）。', 'distan' ),
			'id_conflict' => __( '同じ@idに異なるnameまたはurlがあります。意図した併記か確認してください。', 'distan' ),
			'limit' => __( '解析上限に達したため一部は未検査です。外部検証ツールで対象データを確認してください。', 'distan' ),
			'missing_url' => __( '公開URLが未設定または無効のため自動追加できません。設定を保存して再生成してください。', 'distan' ),
			'unavailable' => __( 'HTMLを検査できません。DOM拡張と生成エラーを確認してください。', 'distan' ),
			'encode_failed' => __( 'JSON-LDを生成できませんでした。ページの文字コードを確認してください。', 'distan' ),
		);
		return $messages[ $code ] ?? __( '確認が必要です。', 'distan' );
	}

	/** Inspect output bytes. $environment is captured by the generator, not read here. */
	public static function inspect( string $html, string $status, array $environment ): array {
		$row = array( 'status' => $status, 'blocks' => 0, 'types' => array(), 'issues' => array(), 'complete' => true );
		if ( ! class_exists( 'DOMDocument' ) ) {
			$row['status'] = 'dom_unavailable';
			$row['complete'] = false;
			self::issue( $row, 'unavailable', 0, 'warning' );
			return $row;
		}
		$doc = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		try {
			$loaded = '' !== trim( $html ) && $doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
		if ( ! $loaded ) {
			$row['status'] = 'parse_failed';
			$row['complete'] = false;
			self::issue( $row, 'unavailable', 0, 'warning' );
			return $row;
		}
		$canonicals = array();
		foreach ( $doc->getElementsByTagName( 'link' ) as $link ) {
			$rels = preg_split( '/\s+/', strtolower( trim( $link->getAttribute( 'rel' ) ) ) );
			if ( in_array( 'canonical', $rels, true ) ) { $canonicals[] = trim( $link->getAttribute( 'href' ) ); }
		}
		$canonical = 1 === count( $canonicals ) ? self::page_url( $canonicals[0] ) : '';
		$environment['canonical'] = $canonicals[0] ?? '';
		$ids = array();
		$budget = 2000;
		foreach ( $doc->getElementsByTagName( 'script' ) as $script ) {
			if ( 'application/ld+json' !== strtolower( trim( explode( ';', $script->getAttribute( 'type' ) )[0] ) ) ) { continue; }
			++$row['blocks'];
			$block = $row['blocks'];
			$text = trim( $script->textContent );
			if ( $block > 100 || strlen( $text ) > 262144 || $budget <= 0 ) {
				$row['complete'] = false;
				self::issue( $row, 'limit', 0, 'warning' );
				continue;
			}
			if ( '' === $text ) { self::issue( $row, 'empty_json', $block, 'error' ); continue; }
			$data = json_decode( $text, false, 64 );
			if ( JSON_ERROR_NONE !== json_last_error() ) { self::issue( $row, 'invalid_json', $block, 'error' ); continue; }
			if ( ! is_object( $data ) && ! is_array( $data ) ) { self::issue( $row, 'invalid_root', $block, 'error' ); continue; }
			if ( is_array( $data ) ) {
				foreach ( $data as $entry ) {
					if ( ! is_object( $entry ) ) { self::issue( $row, 'invalid_root', $block, 'error' ); break; }
				}
			}
			$types = array();
			self::walk( $data, $row, $types, $ids, $budget, $block, $environment, $canonical, true, 0 );
			if ( empty( $types ) ) { self::issue( $row, 'no_type', $block, 'info' ); }
			$row['types'] = array_slice( array_values( array_unique( array_merge( $row['types'], $types ) ) ), 0, 30 );
		}
		if ( $row['blocks'] > 0 ) {
			// A disabled or excluded page may still correctly preserve author data.
			if ( 'added' !== $status ) { $row['status'] = 'existing'; }
			if ( count( $canonicals ) > 1 ) { self::issue( $row, 'canonical_multiple', 0, 'warning' ); }
			if ( 1 === count( $canonicals ) && '' === $canonical ) { self::issue( $row, 'canonical_relative', 0, 'info' ); }
		}
		if ( 'missing_url' === $row['status'] ) { self::issue( $row, 'missing_url', 0, 'warning' ); }
		if ( 'encode_failed' === $row['status'] ) { self::issue( $row, 'encode_failed', 0, 'error' ); }
		return $row;
	}

	/** Walk JSON values without fetching or expanding remote JSON-LD contexts. */
	private static function walk( $value, array &$row, array &$types, array &$ids, int &$budget, int $block, array $env, string $canonical, bool $page_candidate, int $depth ): void {
		if ( --$budget < 0 || $depth > 32 ) {
			$row['complete'] = false;
			self::issue( $row, 'limit', 0, 'warning' );
			return;
		}
		if ( is_string( $value ) ) {
			if ( Distan_Url_Audit::is_development_url( $value, $env ) ) { self::issue( $row, 'dev_url', $block, 'warning', $value ); }
			return;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $child ) {
				if ( $budget < 0 ) { break; }
				self::walk( $child, $row, $types, $ids, $budget, $block, $env, $canonical, $page_candidate, $depth + 1 );
			}
			return;
		}
		if ( ! is_object( $value ) ) { return; }
		$local_types = array();
		foreach ( (array) ( $value->{'@type'} ?? array() ) as $type ) {
			if ( is_string( $type ) ) {
				$type = substr( $type, 0, 160 );
				$types[] = $type;
				$local_types[] = preg_replace( '~^https?://schema\.org/~i', '', $type );
			}
		}
		$page_types = array( 'WebPage', 'AboutPage', 'ContactPage', 'CollectionPage', 'ItemPage', 'ProfilePage', 'FAQPage', 'QAPage', 'SearchResultsPage', 'CheckoutPage' );
		if ( $page_candidate && '' !== $canonical && array_intersect( $local_types, $page_types ) ) {
			$url = $value->url ?? ( $value->{'@id'} ?? '' );
			if ( is_string( $url ) && '' !== self::page_url( $url ) && self::page_url( $url ) !== $canonical ) {
				self::issue( $row, 'canonical_mismatch', $block, 'warning', 'JSON-LD: ' . $url . ' / canonical: ' . ( $env['canonical'] ?? $canonical ) );
			}
		}
		$id = $value->{'@id'} ?? '';
		if ( is_string( $id ) && '' !== $id && count( get_object_vars( $value ) ) > 1 ) {
			// @id-only references are normal, including repeated Organization references.
			$facts = array();
			foreach ( array( 'name', 'url' ) as $key ) {
				if ( isset( $value->$key ) && is_string( $value->$key ) ) { $facts[ $key ] = $value->$key; }
			}
			$id_key = hash( 'sha256', $id );
			if ( isset( $ids[ $id_key ] ) ) {
				$conflict = false;
				foreach ( $facts as $key => $fact ) {
					if ( isset( $ids[ $id_key ][ $key ] ) && $ids[ $id_key ][ $key ] !== $fact ) { $conflict = true; }
				}
				self::issue( $row, $conflict ? 'id_conflict' : 'id_repeated', $block, $conflict ? 'warning' : 'info', '@id: ' . $id );
			}
			$ids[ $id_key ] = array_merge( $ids[ $id_key ] ?? array(), $facts );
		}
		foreach ( get_object_vars( $value ) as $key => $child ) {
			if ( '@context' === $key ) { continue; }
			if ( $budget < 0 ) { break; }
			self::walk( $child, $row, $types, $ids, $budget, $block, $env, $canonical, '@graph' === $key, $depth + 1 );
		}
	}

	/** Compare common directory-index URL forms; do not treat HTTP and HTTPS as identical. */
	private static function page_url( string $url ): string {
		// PHP parse_url can replace raw UTF-8 C1 bytes with underscores.
		$url = (string) preg_replace_callback( '/[^\x00-\x7F]+/', static function ( $m ) { return rawurlencode( $m[0] ); }, $url );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) ) { return ''; }
		$scheme = strtolower( $parts['scheme'] );
		$port = $parts['port'] ?? ( 'https' === $scheme ? 443 : 80 );
		$path = preg_replace( '~/index\.html$~', '/', $parts['path'] ?? '/' );
		// Decode only unreserved escapes. An encoded slash is not a path separator.
		$path = preg_replace_callback( '/%[0-9a-f]{2}/i', static function ( $m ) {
			$char = rawurldecode( $m[0] );
			return preg_match( '/[a-zA-Z0-9._~-]/', $char ) ? $char : strtoupper( $m[0] );
		}, $path );
		$path = implode( '/', array_map( static function ( $segment ) {
			return str_replace( '%25', '%', rawurlencode( $segment ) );
		}, explode( '/', $path ) ) );
		return $scheme . '://' . strtolower( $parts['host'] ) . ':' . $port . rtrim( $path, '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
	}

	private static function issue( array &$row, string $code, int $block, string $level, string $detail = '' ): void {
		foreach ( $row['issues'] as $issue ) {
			if ( $issue['code'] === $code && $issue['block'] === $block ) { return; }
		}
		if ( count( $row['issues'] ) >= 40 ) {
			$row['complete'] = false;
			$row['issues'][39] = array( 'code' => 'limit', 'block' => 0, 'level' => 'warning' );
			return;
		}
		// One example per code/block, with a UTF-8-safe length cap.
		preg_match( '/^.{0,300}/us', $detail, $sample );
		$row['issues'][] = array( 'code' => $code, 'block' => $block, 'level' => $level, 'detail' => $sample[0] ?? '' );
	}

	/** Aggregate completed/failed page records without consulting WordPress. */
	public static function summarize( array $pages ): array {
		$summary = array( 'version' => 1, 'counts' => array(), 'warning_pages' => 0, 'incomplete_pages' => 0, 'pages' => $pages );
		foreach ( $pages as $row ) {
			$status = $row['status'];
			$summary['counts'][ $status ] = ( $summary['counts'][ $status ] ?? 0 ) + 1;
			if ( empty( $row['complete'] ) ) { ++$summary['incomplete_pages']; }
			foreach ( $row['issues'] as $issue ) {
				if ( 'info' !== $issue['level'] ) { ++$summary['warning_pages']; break; }
			}
		}
		return $summary;
	}

	/** Bounded admin payload, with issue pages first; full detail stays in the report. */
	public static function for_display( array $audit ): array {
		if ( ! isset( $audit['pages'] ) ) { return array(); }
		$counts = array();
		foreach ( $audit['counts'] as $status => $count ) { $counts[] = array( 'label' => self::status_label( $status ), 'count' => $count ); }
		$pages = $audit['pages'];
		uasort( $pages, static function ( $a, $b ) { return ( ! empty( $b['issues'] ) ) <=> ( ! empty( $a['issues'] ) ); } );
		$rows = array();
		foreach ( array_slice( $pages, 0, 200, true ) as $path => $row ) {
			$messages = array();
			foreach ( $row['issues'] as $issue ) {
				$level = array( 'error' => __( 'エラー', 'distan' ), 'warning' => __( '要確認', 'distan' ), 'info' => __( '参考', 'distan' ) )[ $issue['level'] ] ?? __( '要確認', 'distan' );
				$messages[] = $level . ( $issue['block'] ? ' #' . $issue['block'] : '' ) . ': ' . self::issue_text( $issue['code'] ) . ( ! empty( $issue['detail'] ) ? ' (' . $issue['detail'] . ')' : '' );
			}
			$rows[] = array( 'path' => $path, 'status' => self::status_label( $row['status'] ), 'blocks' => $row['blocks'], 'types' => implode( ', ', $row['types'] ), 'messages' => $messages );
		}
		return array( 'counts' => $counts, 'rows' => $rows, 'total' => count( $pages ), 'warning_pages' => $audit['warning_pages'], 'incomplete_pages' => $audit['incomplete_pages'] );
	}
}
