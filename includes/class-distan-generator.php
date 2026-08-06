<?php
/**
 * Generation engine.
 *
 * Pages are rendered by asking the site for them over HTTP. Rendering N pages
 * inside one PHP process is faster on paper but wrong in practice: WordPress
 * assumes one request equals one page, and the query globals, the once-only
 * wp_head actions and the enqueue registry all misbehave from the second page
 * onward.
 *
 * Work is batched and resumable, because the execution time limit is a
 * property of the host and not something a plugin gets to assume.
 *
 * @package Distan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Static site generator.
 */
class Distan_Generator {

	/**
	 * Transient holding the in-progress job.
	 */
	public const JOB_KEY = 'distan_job';

	/**
	 * Option holding the manifest of the last completed run.
	 */
	public const MANIFEST_KEY = 'distan_manifest';

	/**
	 * Start a run. Returns the job state.
	 *
	 * @return array<string, mixed>
	 */
	public static function start(): array {
		$queue = Distan_Collector::collect();

		$job = array(
			'queue'       => $queue,
			'index'       => 0,
			'total'       => count( $queue ),
			'written'     => array(),
			'assets'      => array(),
			'errors'      => array(),
			'md_started'  => false,
			'started'     => time(),
			'heartbeat'   => time(),
			'user_id'     => get_current_user_id(),
		);

		set_transient( self::JOB_KEY, $job, HOUR_IN_SECONDS );

		return self::public_state( $job );
	}

	/**
	 * Seconds after which a run with no heartbeat is treated as dead.
	 *
	 * A live run refreshes its heartbeat every batch, so this only needs to
	 * exceed the time one batch can take. It must be generous enough that a
	 * slow batch (several page fetches) is never mistaken for a crash, hence
	 * the two-minute default; filterable for very slow sites.
	 */
	public static function stale_after(): int {
		return (int) apply_filters( 'distan_job_stale_after', 120 );
	}

	/**
	 * The in-progress job, or null if none is running.
	 *
	 * A finished run deletes its transient, so any live job array means a run
	 * is in progress — unless its heartbeat has gone stale (the browser was
	 * closed mid-run), in which case it is treated as abandoned and a new run
	 * is allowed to take over. Used to keep two people (or two tabs) from
	 * starting overlapping generations against the single shared job and
	 * output directory.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function running_job(): ?array {
		$job = get_transient( self::JOB_KEY );

		if ( ! is_array( $job ) ) {
			return null;
		}

		$heartbeat = isset( $job['heartbeat'] ) ? (int) $job['heartbeat'] : (int) ( $job['started'] ?? 0 );

		if ( ( time() - $heartbeat ) > self::stale_after() ) {
			return null;
		}

		return $job;
	}

	/**
	 * Process one batch of pages.
	 *
	 * @param int $size Pages per batch.
	 * @return array<string, mixed>
	 */
	public static function process_batch( int $size = 5 ): array {
		$job = get_transient( self::JOB_KEY );

		if ( ! is_array( $job ) ) {
			return array(
				'done'    => true,
				'message' => __( '進行中のジョブが見つかりません。もう一度開始してください。', 'distan' ),
			);
		}

		// Keep the run's heartbeat fresh so running_job() sees it as alive.
		// It is written back with the job below (or cleared on completion).
		$job['heartbeat'] = time();

		$size = max( 1, min( 20, $size ) );
		$end  = min( $job['index'] + $size, $job['total'] );

		for ( ; $job['index'] < $end; $job['index']++ ) {
			$item = $job['queue'][ $job['index'] ];

			$result = self::render_one( $item );

			if ( isset( $result['error'] ) ) {
				$job['errors'][] = array(
					'url'     => $item['url'],
					'message' => $result['error'],
				);
				continue;
			}

			$job['written'][] = $item['path'];
			$job['assets']    = array_merge( $job['assets'], $result['assets'] );

			if ( ! empty( $result['has_modules'] ) ) {
				$job['has_modules'] = true;
			}

			if ( ! empty( $result['md_section'] ) ) {
				// Stream the page straight to content.md rather than holding
				// every section in the job transient until the end. md_started
				// only flips once a section has actually reached disk, so a
				// failed first write is retried (header and all) on the next
				// page instead of appending a headerless body.
				if ( Distan_Markdown::append_section( $result['md_section'], empty( $job['md_started'] ) ) ) {
					$job['md_started'] = true;
					$job['md_written'] = true;
				}
			}
		}

		$done = $job['index'] >= $job['total'];

		if ( $done ) {
			$assets_result        = self::copy_assets( $job['assets'] );
			$job['assets_copied'] = $assets_result['copied'];
			$job['large_files']   = $assets_result['large_files'];
			$job['broken']        = self::audit_links( $job['written'] );

			// The Markdown export (content.md) was streamed page by page during
			// the batches, so nothing is assembled here — $job['md_written']
			// already reflects whether any section reached disk.

			// Write sitemap.xml / robots.txt before the development-URL audit
			// so any leftover development host in them (e.g. when the
			// Production URL is unset) is caught too — a sitemap submitted to
			// search engines with the wrong host is exactly the accident the
			// audit exists to prevent.
			$job['sitemap'] = Distan_Sitemap::write( $job['queue'] );
			$job['robots']  = Distan_Sitemap::write_robots();

			$scan = $job['written'];
			foreach ( array( $job['sitemap'], $job['robots'] ) as $extra ) {
				if ( is_string( $extra ) && '' !== $extra ) {
					$scan[] = $extra;
				}
			}

			$job['dev_urls'] = self::audit_dev_urls( $scan );
			$job['cleaned']  = self::clean_output( $job );

			self::write_manifest( $job );

			Distan_Report::save(
				self::manifest(),
				array( 'link_style' => Distan::settings()['link_style'] )
			);

			/**
			 * Fires once, after a full generation run has finished and the
			 * manifest and report are written.
			 *
			 * Distan itself does not deploy — it only writes files to the
			 * output directory. This hook is the seam for wiring up whatever
			 * deploy step a project uses (git commit + push, rsync, an SFTP
			 * sync, a Netlify / Cloudflare Pages build webhook, and so on),
			 * so the plugin never has to hold deploy credentials or know
			 * about any particular host.
			 *
			 * @param array  $manifest    The generation manifest: files, added,
			 *                             removed, broken, cleaned, has_modules,
			 *                             finished (timestamp).
			 * @param string $output_root Absolute path to the output directory
			 *                             (uploads/distan/dist).
			 */
			do_action( 'distan_after_generate', self::manifest(), Distan_Paths::output_root() );

			delete_transient( self::JOB_KEY );
		} else {
			set_transient( self::JOB_KEY, $job, HOUR_IN_SECONDS );
		}

		$state         = self::public_state( $job );
		$state['done'] = $done;

		return $state;
	}

	/**
	 * Fetch and write a single page.
	 *
	 * @param array{url: string, path: string, label: string} $item Queue entry.
	 * @return array{assets: array<string, string>}|array{error: string}
	 */
	private static function render_one( array $item ): array {
		$secret = (string) get_option( 'distan_render_secret', '' );

		$response = wp_remote_get(
			$item['url'],
			array(
				'timeout'     => 30,
				'sslverify'   => false,
				'redirection' => 2,
				'headers'     => array(
					Distan_Cleaner::HEADER_NAME => $secret,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'error' => $response->get_error_message() );
		}

		$code     = (int) wp_remote_retrieve_response_code( $response );
		$is_404   = isset( $item['type'] ) && '404' === $item['type'];
		$expected = $is_404 ? 404 : 200;

		if ( $code !== $expected ) {
			return array(
				'error' => $is_404
					? sprintf(
						/* translators: %d: HTTP status code */
						__( '404テンプレートを取得できませんでした（HTTP %d）。プローブ用のURLが実在するページと衝突している可能性があります。', 'distan' ),
						$code
					)
					: sprintf(
						/* translators: %d: HTTP status code */
						__( 'HTTP %d が返りました。', 'distan' ),
						$code
					),
			);
		}

		$html = (string) wp_remote_retrieve_body( $response );

		if ( '' === trim( $html ) ) {
			return array( 'error' => __( '空のレスポンスです。', 'distan' ) );
		}

		// The 404 page is served from arbitrary depths, so document-relative
		// links from the output root would resolve against the wrong
		// directory. It always gets absolute URLs.
		$rewritten = Distan_Urls::rewrite( $html, $item['path'], $is_404 );

		if ( ! Distan_Paths::write( $item['path'], $rewritten['html'] ) ) {
			return array(
				'error' => sprintf(
					/* translators: %s: output path */
					__( '書き込みに失敗しました: %s', 'distan' ),
					$item['path']
				),
			);
		}

		// Detect ES modules. They are valid on a web server but cannot load
		// over file://, so a deliverable that ships them will look broken when
		// opened by double-click. Flag it so the run can warn, once, at the end.
		// This inspects generated output; it does not emit any script tag.
		$script_tag  = '<' . 'script';
		$has_modules = (bool) preg_match(
			'#' . $script_tag . '[^>]+type=([\'"])module\1#i',
			$rewritten['html']
		) || false !== stripos( $rewritten['html'], 'type="importmap"' )
			|| false !== stripos( $rewritten['html'], "type='importmap'" );

		$section = null;
		if ( ! $is_404 && Distan_Markdown::is_enabled() ) {
			$section = Distan_Markdown::extract( $html, $item );
		}

		return array(
			'assets'      => $rewritten['assets'],
			'has_modules' => $has_modules,
			'md_section'  => $section,
		);
	}

	/**
	 * Copy queued assets into the output tree.
	 *
	 * @param array<string, string> $assets Output path => source path.
	 */
	private static function copy_assets( array $assets ): array {
		$copied      = 0;
		$large_files = array(); // relative => size in bytes (for the report).

		/**
		 * Filter the size (in bytes) above which a copied asset is flagged
		 * as "large" in the report. The file is still copied; this only
		 * controls the warning. Default 10 MB.
		 *
		 * @param int $threshold Size in bytes.
		 */
		$threshold = (int) apply_filters( 'distan_large_file_threshold', 10 * 1024 * 1024 );

		// A queue so assets discovered inside CSS (fonts, images) are copied
		// too. Seeded with the assets collected from HTML.
		$queue = $assets;
		$done  = array();

		while ( ! empty( $queue ) ) {
			$relative = (string) array_key_first( $queue );
			$source   = $queue[ $relative ];
			unset( $queue[ $relative ] );

			if ( isset( $done[ $relative ] ) ) {
				continue;
			}
			$done[ $relative ] = true;

			if ( ! is_file( $source ) || ! is_readable( $source ) ) {
				continue;
			}

			// Flag large files for the report. They are still copied below;
			// this is a heads-up, not an exclusion.
			$size = (int) filesize( $source );
			if ( $size > $threshold ) {
				$large_files[ $relative ] = $size;
			}

			// For stylesheets, rewrite url() references and pull in whatever
			// they point at (theme fonts/images, uploads) so the delivered CSS
			// resolves and no wp-content path leaks through. This needs the
			// contents in memory; stylesheets are small.
			if ( 'css' === strtolower( (string) pathinfo( $relative, PATHINFO_EXTENSION ) ) ) {
				$contents = file_get_contents( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents

				if ( false === $contents ) {
					continue;
				}

				$source_url = self::source_url_for( $source );
				$result     = Distan_Urls::rewrite_css( $contents, $source_url, $relative );
				$contents   = $result['css'];

				foreach ( $result['assets'] as $out => $src ) {
					if ( ! isset( $done[ $out ] ) && ! isset( $queue[ $out ] ) ) {
						$queue[ $out ] = $src;
					}
				}

				if ( Distan_Paths::write( $relative, $contents ) ) {
					$copied++;
				}
			} else {
				// Binary assets (images, PDFs, fonts, video) are not
				// rewritten, so stream-copy them to avoid loading large
				// files whole into memory.
				if ( Distan_Paths::copy_stream( $relative, $source ) ) {
					$copied++;
				}
			}
		}

		return array(
			'copied'      => $copied,
			'large_files' => $large_files,
		);
	}

	/**
	 * Reconstruct a stylesheet's site-root-relative URL from its file path,
	 * so url() references inside it can be resolved. Falls back to the file's
	 * basename when the path is outside ABSPATH.
	 */
	private static function source_url_for( string $file ): string {
		$root = Distan_Paths::normalize( untrailingslashit( ABSPATH ) );
		$norm = Distan_Paths::normalize( $file );

		if ( str_starts_with( $norm, $root . '/' ) ) {
			return '/' . ltrim( substr( $norm, strlen( $root ) ), '/' );
		}

		return '/' . basename( $file );
	}

	/**
	 * Report internal links whose target file was never written.
	 *
	 * A generated site links to whatever the theme decided to link to, which
	 * routinely includes archives and taxonomies that are not in the queue.
	 * Those become dead links in the deliverable, and nothing else will catch
	 * them. As with the removal list, this reports and does not act: silently
	 * rewriting or deleting a link would hide a decision the operator should
	 * be making.
	 *
	 * @param array<int, string> $written Output paths that were written.
	 * @return array<int, array{from: string, to: string}>
	 */
	/**
	 * Scan the written output for URLs that still carry the development
	 * domain (the host of home_url()). These appear in places Distan preserves
	 * rather than rewrites — most importantly JSON-LD and canonical/OGP tags —
	 * when the Production URL setting is empty, so there is nothing to replace
	 * the development host with. Leaving the development domain in structured
	 * data means the live site points at a host that does not exist in
	 * production, so this is surfaced (fact-based) in the report and, from the
	 * saved count, in the pre-generation environment check.
	 *
	 * @param array $written Relative paths of written HTML files.
	 * @return array{count:int, files:array<int,string>} Detection summary.
	 */
	private static function audit_dev_urls( array $written ): array {
		$root = Distan_Paths::output_root();

		$home = wp_parse_url( home_url( '/' ) );
		$host = isset( $home['host'] ) ? (string) $home['host'] : '';

		if ( '' === $host ) {
			return array(
				'count' => 0,
				'files' => array(),
			);
		}

		// Match the development host as it appears inside URLs, e.g.
		// "http://distan.local/…". Both raw and percent-encoded slugs keep the
		// host in ASCII, so a plain host match is enough.
		$needle = '#https?://' . preg_quote( $host, '#' ) . '#i';

		$count = 0;
		$files = array();

		foreach ( $written as $relative ) {
			$file = $root . '/' . $relative;

			if ( ! is_file( $file ) ) {
				continue;
			}

			$html = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents

			if ( false === $html ) {
				continue;
			}

			$hits = preg_match_all( $needle, $html );

			if ( $hits ) {
				$count   += $hits;
				$files[] = $relative;
			}
		}

		return array(
			'count' => $count,
			'files' => $files,
		);
	}

	private static function audit_links( array $written ): array {
		$root   = Distan_Paths::output_root();
		$broken = array();
		$seen   = array();

		foreach ( $written as $relative ) {
			$file = $root . '/' . $relative;

			if ( ! is_file( $file ) ) {
				continue;
			}

			$html = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents

			if ( false === $html ) {
				continue;
			}

			if ( ! preg_match_all( '#\s(?:href|src)=(["\'])(.*?)\1#i', $html, $matches ) ) {
				continue;
			}

			$dir = dirname( $file );

			foreach ( $matches[2] as $link ) {
				$link = trim( $link );

				if ( '' === $link || str_starts_with( $link, '#' ) ) {
					continue;
				}

				$absolute_target = null;

				// In absolute mode our own links carry the production URL.
				// Strip it so they can still be verified against the tree.
				$public = trim( (string) Distan::settings()['site_url'] );

				if ( '' !== $public && str_starts_with( $link, trailingslashit( $public ) ) ) {
					$link            = substr( $link, strlen( trailingslashit( $public ) ) );
					$absolute_target = true;
				} elseif ( preg_match( '#^([a-z][a-z0-9+.-]*:|//|/)#i', $link ) ) {
					// External, or root-relative to something we do not own.
					continue;
				}

				$link = explode( '#', $link, 2 )[0];
				$link = explode( '?', $link, 2 )[0];

				if ( '' === $link ) {
					continue;
				}

				// Files are written under their real (decoded) name, while the
				// href carries the percent-encoded form. Decode the link before
				// probing the filesystem so "%E3%83%86.../index.html" is matched
				// against the actual "テスト/index.html" on disk; probing the
				// encoded string would look for a literal "%E3%83%86..." name
				// that no longer exists and report a false broken link.
				$decoded_link = rawurldecode( $link );

				$target = $absolute_target
					? self::collapse( $root . '/' . $decoded_link )
					: self::collapse( $dir . '/' . $decoded_link );

				if ( file_exists( $target ) ) {
					continue;
				}

				$key = $relative . '|' . $link;

				if ( isset( $seen[ $key ] ) ) {
					continue;
				}

				$seen[ $key ] = true;

				$broken[] = array(
					'from' => $relative,
					'to'   => $link,
				);
			}
		}

		return $broken;
	}

	/**
	 * Resolve ".." segments without touching the disk.
	 */
	private static function collapse( string $path ): string {
		$parts = explode( '/', str_replace( '\\', '/', $path ) );
		$out   = array();

		foreach ( $parts as $part ) {
			if ( '.' === $part || '' === $part ) {
				// Preserve the leading empty segment of an absolute path.
				if ( empty( $out ) && '' === $part ) {
					$out[] = '';
				}
				continue;
			}
			if ( '..' === $part ) {
				if ( count( $out ) > 1 ) {
					array_pop( $out );
				}
				continue;
			}
			$out[] = $part;
		}

		return implode( '/', $out );
	}

	/**
	 * Remove files in the output directory that this run did not produce.
	 *
	 * The output directory is machine-generated and reproducible: every file
	 * in it came from here and can be recreated by running again. Leaving
	 * stale files behind corrupts the deliverable, so they go automatically.
	 * (This is the opposite of how user media should be treated, where
	 * deletion is irreversible and belongs to the operator.)
	 *
	 * Skipped entirely when any page failed, because a partial run's file
	 * list is not a reliable statement of what the site contains.
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return array<int, string> Relative paths that were deleted.
	 */
	private static function clean_output( array $job ): array {
		if ( ! empty( $job['errors'] ) ) {
			return array();
		}

		/**
		 * Filter whether stale files are removed from the output directory.
		 *
		 * @param bool $enabled Defaults to true.
		 */
		if ( ! apply_filters( 'distan_clean_output', true ) ) {
			return array();
		}

		$root = Distan_Paths::output_root();

		if ( ! is_dir( $root ) ) {
			return array();
		}

		$keep_extra = array();
		foreach ( array(
			isset( $job['sitemap'] ) ? $job['sitemap'] : null,
			isset( $job['robots'] ) ? $job['robots'] : null,
		) as $extra ) {
			if ( is_string( $extra ) && '' !== $extra ) {
				$keep_extra[] = $extra;
			}
		}
		// Markdown deliverables, when they were written this run.
		if ( ! empty( $job['md_written'] ) ) {
			$keep_extra[] = Distan_Markdown::FILENAME;
			if ( Distan_Markdown::wants_local_copy() ) {
				$keep_extra[] = Distan_Markdown::FILENAME_LOCAL;
			}
		}

		$keep = array_flip(
			array_merge( $job['written'], array_keys( $job['assets'] ), $keep_extra )
		);

		$deleted = array();

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $entry ) {
			/** @var SplFileInfo $entry */
			$absolute = Distan_Paths::normalize( (string) $entry->getPathname() );
			$relative = ltrim( substr( $absolute, strlen( $root ) ), '/' );

			if ( '' === $relative ) {
				continue;
			}

			if ( $entry->isDir() ) {
				// Prune directories only once they are empty.
				if ( Distan_Paths::is_contained( $absolute ) && self::is_empty_dir( $absolute ) ) {
					// WP_Filesystem is avoided on purpose: it may prompt for FTP
					// credentials, which would break unattended generation. The
					// path is contained-checked immediately above.
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged
					@rmdir( $absolute );
				}
				continue;
			}

			if ( isset( $keep[ $relative ] ) ) {
				continue;
			}

			// Containment re-check immediately before the destructive call.
			if ( ! Distan_Paths::is_contained( $absolute ) ) {
				continue;
			}

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( wp_delete_file_from_directory( $absolute, Distan_Paths::output_root() ) ) {
				$deleted[] = $relative;
			}
		}

		return $deleted;
	}

	/**
	 * Whether a directory has no remaining entries.
	 */
	private static function is_empty_dir( string $path ): bool {
		$handle = @scandir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $handle ) {
			return false;
		}

		return 2 === count( $handle );
	}

	/**
	 * Record what this run produced, so the next run can report a diff.
	 *
	 * @param array<string, mixed> $job Job state.
	 */
	private static function write_manifest( array $job ): void {
		$previous = get_option( self::MANIFEST_KEY, array() );
		$previous = is_array( $previous ) && isset( $previous['files'] ) && is_array( $previous['files'] )
			? $previous['files']
			: array();

		$current = array_values( array_unique( array_merge(
			$job['written'],
			array_keys( $job['assets'] )
		) ) );

		update_option(
			self::MANIFEST_KEY,
			array(
				'files'    => $current,
				'removed'  => array_values( array_diff( $previous, $current ) ),
				'added'    => array_values( array_diff( $current, $previous ) ),
				'broken'   => isset( $job['broken'] ) ? $job['broken'] : array(),
				'cleaned'  => isset( $job['cleaned'] ) ? $job['cleaned'] : array(),
				'dev_urls' => isset( $job['dev_urls'] ) ? $job['dev_urls'] : array(
					'count' => 0,
					'files' => array(),
				),
				'has_modules' => ! empty( $job['has_modules'] ),
				'large_files' => isset( $job['large_files'] ) ? $job['large_files'] : array(),
				'finished' => time(),
			),
			false
		);
	}

	/**
	 * The last manifest, for reporting.
	 *
	 * @return array<string, mixed>
	 */
	public static function manifest(): array {
		$manifest = get_option( self::MANIFEST_KEY, array() );

		return is_array( $manifest ) ? $manifest : array();
	}

	/**
	 * Strip the queue out of the job before sending it to the browser.
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return array<string, mixed>
	 */
	private static function public_state( array $job ): array {
		return array(
			'index'   => (int) $job['index'],
			'total'   => (int) $job['total'],
			'written' => count( $job['written'] ),
			'assets'  => count( $job['assets'] ),
			'errors'  => $job['errors'],
			'done'    => false,
		);
	}
}
