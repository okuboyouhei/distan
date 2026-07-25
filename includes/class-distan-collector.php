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
		$items[] = self::item( home_url( '/' ), __( 'フロントページ', 'distan' ) );

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
				$items[] = self::item( $permalink, get_the_title( $posts_page ) );
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

				$items[] = self::item( $permalink, get_the_title( $id ) );
			}
		}

		// Deduplicate by output path: two URLs mapping to one file would have
		// the later one silently overwrite the earlier.
		$seen   = array();
		$unique = array();

		foreach ( $items as $item ) {
			if ( '' === $item['path'] || isset( $seen[ $item['path'] ] ) ) {
				continue;
			}
			$seen[ $item['path'] ] = true;
			$unique[]              = $item;
		}

		/**
		 * Filter the collected generation queue.
		 *
		 * @param array<int, array{url: string, path: string, label: string}> $unique Queue.
		 */
		return (array) apply_filters( 'distan_collect', $unique );
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
			__( '投稿アーカイブ %d ページ目', 'distan' )
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
					)
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
					$term->name . ' %d ページ目'
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
	private static function paginate( string $base, int $max, string $label_tmpl ): array {
		$items = array();

		for ( $page = 2; $page <= $max; $page++ ) {
			$url = trailingslashit( $base ) . 'page/' . $page . '/';

			$items[] = self::item( $url, sprintf( $label_tmpl, $page ) );
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
			'url'   => home_url( '/' . trim( $slug, '/' ) . '/' ),
			'path'  => '404.html',
			'label' => __( '404ページ', 'distan' ),
			'type'  => '404',
		);
	}

	/**
	 * Shape one queue entry.
	 *
	 * @return array{url: string, path: string, label: string, type: string}
	 */
	private static function item( string $url, string $label ): array {
		$path = Distan_Urls::url_to_output_path( $url );

		return array(
			'url'   => $url,
			'path'  => null === $path ? '' : $path,
			'label' => $label,
			'type'  => 'page',
		);
	}
}
