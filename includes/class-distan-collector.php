<?php
/**
 * URL collection.
 *
 * Enumerates what to generate by asking WordPress, rather than by crawling.
 * The tradeoff is deliberate: a crawler finds whatever happens to be linked,
 * which means omissions are silent. Enumeration produces a list that can be
 * counted and shown before anything runs.
 *
 * @package Distan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the generation queue.
 */
class Distan_Collector {

	/**
	 * Collect every URL to generate.
	 *
	 * @return array<int, array{url: string, path: string, label: string}>
	 */
	public static function collect(): array {
		$items = array();

		// Front page.
		$items[] = self::item( home_url( '/' ), __( 'フロントページ', 'distan' ), array( 'kind' => 'front' ) );

		// The 404 template. It has no real URL, so it is fetched by asking for
		// one that cannot exist and saved to a fixed filename.
		$items[] = self::not_found_item();

		// The posts archive and its pagination.
		foreach ( self::archive_items() as $archive_item ) {
			$items[] = $archive_item;
		}

		// Taxonomy archives (categories by default) and their pagination.
		foreach ( self::taxonomy_items() as $taxonomy_item ) {
			$items[] = $taxonomy_item;
		}

		// The posts page, when a static front page is in use.
		$posts_page = (int) get_option( 'page_for_posts' );
		if ( $posts_page > 0 ) {
			$permalink = get_permalink( $posts_page );
			if ( $permalink ) {
				$items[] = self::item( $permalink, get_the_title( $posts_page ), array( 'kind' => 'blog_home', 'id' => $posts_page ) );
			}
		}

		// Every published singular entry of every public post type.
		foreach ( self::post_types() as $post_type ) {
			$ids = get_posts(
				array(
					'post_type'              => $post_type,
					'post_status'            => 'publish',
					'posts_per_page'         => -1,
					'fields'                 => 'ids',
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);

			foreach ( $ids as $id ) {
				// The posts page is a real page but must not be generated
				// twice.
				if ( $id === $posts_page ) {
					continue;
				}

				$permalink = get_permalink( $id );
				if ( ! $permalink ) {
					continue;
				}

				$items[] = self::item(
					$permalink,
					get_the_title( $id ),
					array( 'kind' => 'post', 'id' => (int) $id, 'post_type' => $post_type )
				);
			}
		}

		// Custom sources (distan_sources). These declare URLs enumeration
		// cannot know about — plugin-generated routes, virtual pages — and are
		// merged in before dedup so they are deduplicated alongside the
		// built-in enumeration and carry provenance. Unlike distan_collect,
		// nothing spliced in here is second-class: it is counted and appears
		// in the diff like anything else.
		foreach ( self::provided_items() as $provided_item ) {
			$items[] = $provided_item;
		}

		// Operator take-ups: URLs chosen from the coverage report (or typed in)
		// that enumeration missed. Same footing as distan_sources — counted,
		// deduplicated, and carrying provenance — but driven by a saved choice
		// in the admin rather than a filter.
		if ( class_exists( 'Distan_Takeup' ) ) {
			foreach ( Distan_Takeup::included_items() as $takeup_item ) {
				$items[] = $takeup_item;
			}
		}

		// Deduplicate by output path: two URLs mapping to one file would have
		// the later one silently overwrite the earlier.
		$unique = self::dedupe_by_path( $items );

		/**
		 * Filter the collected generation queue.
		 *
		 * Last-resort raw editing point. Prefer `distan_sources`, whose entries
		 * are deduplicated and carry provenance; anything added here is
		 * deduplicated once more below but is otherwise unvalidated.
		 *
		 * @param array<int, array{url: string, path: string, label: string, type: string, source: array<string, mixed>}> $unique Queue.
		 */
		$unique = (array) apply_filters( 'distan_collect', $unique );

		// Dedup again: a raw distan_collect edit must not reintroduce a path
		// collision that silently overwrites another page at write time.
		return self::dedupe_by_path( $unique );
	}

	/**
	 * Remove entries whose output path repeats, keeping the first seen.
	 *
	 * Empty paths (URLs that map to no file) are dropped. Non-array members,
	 * which a careless distan_collect filter could introduce, are ignored
	 * rather than fatal.
	 *
	 * @param array<int, mixed> $items Candidate entries.
	 * @return array<int, array{url: string, path: string, label: string, type: string, source: array<string, mixed>}>
	 */
	private static function dedupe_by_path( array $items ): array {
		$seen   = array();
		$unique = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['path'] ) || isset( $seen[ $item['path'] ] ) ) {
				continue;
			}
			$seen[ $item['path'] ] = true;
			$unique[]              = $item;
		}

		return $unique;
	}

	/**
	 * Entries contributed by custom sources on the `distan_sources` filter.
	 *
	 * A provider is any callable returning an array of entries built with
	 * self::make_item(). Raw url/label/source arrays are accepted too and
	 * normalised through make_item(), so a provider cannot inject an
	 * inconsistent url/path pair.
	 *
	 * @return array<int, array{url: string, path: string, label: string, type: string, source: array<string, mixed>}>
	 */
	private static function provided_items(): array {
		/**
		 * Register custom URL sources.
		 *
		 * @param array<int, callable> $providers Callables returning make_item() arrays.
		 */
		$providers = (array) apply_filters( 'distan_sources', array() );

		$items = array();

		foreach ( $providers as $provider ) {
			if ( ! is_callable( $provider ) ) {
				continue;
			}

			foreach ( (array) call_user_func( $provider ) as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry['url'] ) ) {
					continue;
				}

				$items[] = self::make_item(
					(string) $entry['url'],
					isset( $entry['label'] ) ? (string) $entry['label'] : __( '外部ソース', 'distan' ),
					isset( $entry['source'] ) && is_array( $entry['source'] )
						? $entry['source']
						: array( 'kind' => 'extra' )
				);
			}
		}

		/**
		 * Opt in to seeding the queue from WordPress core's own sitemap
		 * providers (wp-sitemap). This pulls in URLs that plugins register
		 * with core — the plugin-generated routes pure enumeration misses —
		 * without crawling. Off by default: core sitemaps honour noindex and
		 * can be disabled, so the set is deliberately a supplement, not a
		 * replacement. Overlaps with the built-in enumeration are removed by
		 * the shared dedup.
		 *
		 * @param bool $enabled Whether to include core sitemap URLs.
		 */
		if ( apply_filters( 'distan_use_core_sitemap', false ) && class_exists( 'Distan_Sitemap_Audit' ) ) {
			foreach ( Distan_Sitemap_Audit::items() as $entry ) {
				$items[] = $entry;
			}
		}

		return $items;
	}

	/**
	 * The posts archive plus its pagination.
	 *
	 * Category archives are handled separately in taxonomy_items(). Tag,
	 * author and date archives stay off unless requested. Anything the theme
	 * links to but this method does not produce surfaces in the link audit, so
	 * the omission is visible rather than silent.
	 *
	 * @return array<int, array{url: string, path: string, label: string}>
	 */
	public static function archive_items(): array {
		$posts_page = (int) get_option( 'page_for_posts' );

		// Base URL of the archive: either the assigned posts page, or the
		// front page when it lists posts directly.
		if ( $posts_page > 0 ) {
			$base = get_permalink( $posts_page );
			if ( ! $base ) {
				return array();
			}
		} elseif ( 'posts' === get_option( 'show_on_front' ) ) {
			$base = home_url( '/' );
		} else {
			// No blog archive exists on this site.
			return array();
		}

		$per_page = (int) get_option( 'posts_per_page' );

		if ( $per_page < 1 ) {
			return array();
		}

		$query = new WP_Query(
			array(
				'post_type'              => 'post',
				'post_status'            => 'publish',
				'posts_per_page'         => $per_page,
				'fields'                 => 'ids',
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$max = (int) $query->max_num_pages;

		wp_reset_postdata();

		/**
		 * Filter the number of archive pages generated.
		 *
		 * @param int $max Page count.
		 */
		$max = (int) apply_filters( 'distan_archive_max_pages', $max );

		return self::paginate(
			$base,
			$max,
			/* translators: %d: page number */
			__( '投稿アーカイブ %d ページ目', 'distan' ),
			array( 'kind' => 'blog_archive' )
		);
	}

	/**
	 * Taxonomy term archives and their pagination.
	 *
	 * Categories only by default. Tags, custom taxonomies, authors and dates
	 * stay off unless asked for, because "every term of every taxonomy" is how
	 * a generation run explodes. Declaring the taxonomies keeps the count
	 * predictable — the same "say what you want, not everything" stance the
	 * rest of the toolset takes.
	 *
	 * @return array<int, array{url: string, path: string, label: string, type: string}>
	 */
	public static function taxonomy_items(): array {
		/**
		 * Filter which taxonomies are generated.
		 *
		 * @param array<int, string> $taxonomies Taxonomy slugs.
		 */
		$taxonomies = (array) apply_filters( 'distan_taxonomies', array( 'category' ) );

		$per_page = (int) get_option( 'posts_per_page' );

		if ( $per_page < 1 ) {
			return array();
		}

		$items = array();

		foreach ( $taxonomies as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => true,
					'fields'     => 'all',
				)
			);

			if ( is_wp_error( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				$link = get_term_link( $term );

				if ( is_wp_error( $link ) ) {
					continue;
				}

				// Page 1 of the term archive.
				$items[] = self::item(
					$link,
					sprintf(
						/* translators: 1: taxonomy label, 2: term name */
						__( '%1$s: %2$s', 'distan' ),
						$taxonomy,
						$term->name
					),
					array( 'kind' => 'term', 'taxonomy' => $taxonomy, 'id' => (int) $term->term_id, 'page' => 1 )
				);

				// How many pages this term needs.
				$max = (int) ceil( (int) $term->count / $per_page );

				/**
				 * Filter the number of pages generated per taxonomy term.
				 *
				 * @param int     $max  Page count.
				 * @param WP_Term $term The term.
				 */
				$max = (int) apply_filters( 'distan_term_max_pages', $max, $term );

				foreach ( self::paginate(
					$link,
					$max,
					/* translators: 1: term name, 2: page number */
					$term->name . ' %d ページ目',
					array( 'kind' => 'term', 'taxonomy' => $taxonomy, 'id' => (int) $term->term_id )
				) as $entry ) {
					$items[] = $entry;
				}
			}
		}

		return $items;
	}

	/**
	 * Build pagination entries for an archive base, from page 2 up to $max.
	 *
	 * Page 1 is the archive base itself and is queued separately.
	 *
	 * @param string $base        Archive base URL.
	 * @param int    $max         Highest page number.
	 * @param string $label_tmpl  sprintf template taking one %d (page number).
	 * @return array<int, array{url: string, path: string, label: string, type: string}>
	 */
	private static function paginate( string $base, int $max, string $label_tmpl, array $source = array() ): array {
		$items = array();

		for ( $page = 2; $page <= $max; $page++ ) {
			$url = trailingslashit( $base ) . 'page/' . $page . '/';

			$source['page'] = $page;

			$items[] = self::item( $url, sprintf( $label_tmpl, $page ), $source );
		}

		return $items;
	}

	/**
	 * Public post types that produce a front-end URL.
	 *
	 * @return array<int, string>
	 */
	public static function post_types(): array {
		$types = get_post_types(
			array(
				'public'             => true,
				'publicly_queryable' => true,
			),
			'names'
		);

		// 'page' is public but not publicly_queryable, so add it explicitly.
		$types['page'] = 'page';

		// Attachment pages are rarely wanted in a deliverable.
		unset( $types['attachment'] );

		/**
		 * Filter which post types are generated.
		 *
		 * @param array<string, string> $types Post type names.
		 */
		$types = (array) apply_filters( 'distan_post_types', $types );

		return array_values( $types );
	}

	/**
	 * The 404 page.
	 *
	 * Written to the output root as 404.html, which is what Apache's
	 * ErrorDocument and most static hosts expect.
	 *
	 * @return array{url: string, path: string, label: string, type: string}
	 */
	public static function not_found_item(): array {
		/**
		 * Filter the path requested to trigger the 404 template.
		 *
		 * @param string $slug Slug appended to the home URL.
		 */
		$slug = (string) apply_filters( 'distan_404_probe', 'distan-404-probe' );

		return array(
			'url'    => home_url( '/' . trim( $slug, '/' ) . '/' ),
			'path'   => '404.html',
			'label'  => __( '404ページ', 'distan' ),
			'type'   => '404',
			'source' => array( 'kind' => 'not_found' ),
		);
	}

	/**
	 * Shape one queue entry.
	 *
	 * Public so custom sources registered on the `distan_sources` filter can
	 * build correctly-shaped entries — path derived, provenance attached —
	 * instead of hand-assembling raw arrays that might drift from the schema.
	 *
	 * @param string               $url    Front-end URL to render.
	 * @param string               $label  Human label, shown in reports.
	 * @param array<string, mixed> $source Provenance: a 'kind' plus identifying
	 *                                     keys (e.g. id, taxonomy, page). Left
	 *                                     empty it produces an unattributed
	 *                                     entry, which still generates fine.
	 * @return array{url: string, path: string, label: string, type: string, source: array<string, mixed>}
	 */
	public static function make_item( string $url, string $label, array $source = array() ): array {
		$path = Distan_Urls::url_to_output_path( $url );

		return array(
			'url'    => $url,
			'path'   => null === $path ? '' : $path,
			'label'  => $label,
			'type'   => 'page',
			'source' => $source,
		);
	}

	/**
	 * Internal shorthand for a standard page entry.
	 *
	 * @param string               $url    URL.
	 * @param string               $label  Label.
	 * @param array<string, mixed> $source Provenance.
	 * @return array{url: string, path: string, label: string, type: string, source: array<string, mixed>}
	 */
	private static function item( string $url, string $label, array $source = array() ): array {
		return self::make_item( $url, $label, $source );
	}
}
