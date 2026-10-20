<?php
/**
 * Джерело тексту для генерації: сторінки/записи (з Elementor), товари WooCommerce, терміни (категорії, бренди).
 *
 * Кожне джерело повертає масив:
 *   kind  — тип об'єкта для промпту (Товар, Категорія товарів, Сторінка...)
 *   title — назва
 *   facts — [мітка => значення] структуровані дані (бренд, характеристики, розділ...)
 *   text  — очищений текст, до ~3000 символів
 *   url   — адреса
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Очищення тексту
 * ---------------------------------------------------------------------- */

function svtmg_clean_source_text( string $html ): string {
	$text = strip_shortcodes( $html );
	$text = preg_replace( '/\[\/?[a-zA-Z0-9_\-]+(?:\s[^\]]*)?\]/u', ' ', $text ) ?? $text;
	$text = preg_replace( '@<(script|style|noscript|iframe|svg)[^>]*?>.*?</\1>@si', ' ', $text ) ?? $text;
	$text = preg_replace( '/<[^>]+>/', ' ', $text ) ?? $text;
	$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$text = preg_replace( '/[ \t\x{00A0}]+/u', ' ', $text ) ?? $text;
	$text = preg_replace( '/\s*\n\s*/u', "\n", $text ) ?? $text;
	$text = preg_replace( '/\n{2,}/u', "\n", $text ) ?? $text;
	$text = preg_replace( '/ +([.,!?;:])/u', '$1', $text ) ?? $text;
	return trim( $text );
}

function svtmg_truncate_words( string $text, int $limit ): string {
	if ( mb_strlen( $text ) <= $limit ) {
		return $text;
	}
	$cut   = mb_substr( $text, 0, $limit );
	$space = mb_strrpos( $cut, ' ' );
	return rtrim( false !== $space && $space > $limit * 0.8 ? mb_substr( $cut, 0, $space ) : $cut );
}

function svtmg_source_limit(): int {
	return (int) apply_filters( 'svtmg_source_max_chars', 3000 );
}

/* -------------------------------------------------------------------------
 * Записи і сторінки
 * ---------------------------------------------------------------------- */

/**
 * @param array $override Незбережені 'title' / 'content' з редактора.
 */
function svtmg_post_source( WP_Post $post, array $override = array() ): array {
	$title   = isset( $override['title'] ) && '' !== $override['title'] ? (string) $override['title'] : $post->post_title;
	$content = isset( $override['content'] ) && '' !== $override['content'] ? (string) $override['content'] : $post->post_content;
	$facts   = array();
	$text    = '';
	$h1      = '';

	if ( svtmg_is_elementor_post( $post->ID ) ) {
		$el   = svtmg_elementor_extract( $post->ID );
		$h1   = $el['h1'];
		$text = svtmg_clean_source_text( $el['text'] );
	}
	if ( '' === $h1 && preg_match( '/<h1[^>]*>(.*?)<\/h1>/is', $content, $m ) ) {
		$h1 = svtmg_clean_source_text( $m[1] );
	}

	if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
		$product = wc_get_product( $post->ID );
		if ( $product ) {
			$facts = svtmg_product_facts( $product );
			$short = svtmg_clean_source_text( (string) $product->get_short_description() );
			if ( '' !== $short ) {
				$text = $short . ( '' !== $text ? "\n" . $text : '' );
			}
		}
	}

	if ( '' === $text || ( 'product' === $post->post_type && mb_strlen( $text ) < 300 ) ) {
		$body = svtmg_clean_source_text( $content );
		$text = '' === $text ? $body : $text . "\n" . $body;
	}

	if ( 'product' !== $post->post_type && '' !== trim( $post->post_excerpt ) ) {
		$text = svtmg_clean_source_text( $post->post_excerpt ) . "\n" . $text;
	}

	if ( '' !== $h1 && $h1 !== $title ) {
		$facts = array( 'H1' => $h1 ) + $facts;
	}

	$type_obj = get_post_type_object( $post->post_type );

	return array(
		'kind'  => 'product' === $post->post_type ? 'Товар інтернет-магазину' : ( $type_obj ? $type_obj->labels->singular_name : $post->post_type ),
		'title' => svtmg_clean_source_text( $title ),
		'facts' => $facts,
		'text'  => svtmg_truncate_words( (string) apply_filters( 'svtmg_source_text', trim( $text ), 'post', $post ), svtmg_source_limit() ),
		'url'   => (string) get_permalink( $post ),
	);
}

/**
 * Бренд, категорія і характеристики товару. Ціну й наявність свідомо не передаємо: вони змінюються.
 *
 * @param WC_Product $product
 * @return array<string, string>
 */
function svtmg_product_facts( $product ): array {
	$id    = $product->get_id();
	$facts = array();

	$brand = svtmg_product_brand( $product );
	if ( '' !== $brand ) {
		$facts['Бренд'] = $brand;
	}

	$cats = wp_get_post_terms( $id, 'product_cat', array( 'fields' => 'all' ) );
	if ( ! is_wp_error( $cats ) && $cats ) {
		// Найглибші категорії: ті, що не є батьківськими для інших призначених.
		$parents = wp_list_pluck( $cats, 'parent' );
		$leaf    = array_filter( $cats, static fn( $c ) => ! in_array( $c->term_id, $parents, true ) );
		$facts['Категорія'] = implode( ', ', array_slice( wp_list_pluck( $leaf ?: $cats, 'name' ), 0, 3 ) );
	}

	$specs = array();
	foreach ( $product->get_attributes() as $attr ) {
		if ( ! $attr instanceof WC_Product_Attribute || ( ! $attr->get_visible() && ! $attr->get_variation() ) ) {
			continue;
		}
		$name = $attr->get_name();
		if ( in_array( $name, svtmg_brand_attributes(), true ) ) {
			continue;
		}
		$values = $attr->is_taxonomy()
			? wc_get_product_terms( $id, $name, array( 'fields' => 'names' ) )
			: $attr->get_options();
		$values = array_filter( array_map( 'trim', array_map( 'strval', (array) $values ) ) );
		if ( $values ) {
			$specs[] = wc_attribute_label( $name, $product ) . ': ' . mb_substr( implode( ', ', array_slice( $values, 0, 8 ) ), 0, 120 );
		}
		if ( count( $specs ) >= 15 ) {
			break;
		}
	}
	if ( $specs ) {
		$facts['Характеристики'] = "\n- " . implode( "\n- ", $specs );
	}

	return (array) apply_filters( 'svtmg_product_facts', $facts, $product );
}

/**
 * @return string[] Атрибути, що містять бренд.
 */
function svtmg_brand_attributes(): array {
	return (array) apply_filters( 'svtmg_brand_attributes', array( 'pa_trademark', 'pa_brand', 'pa_brend' ) );
}

/**
 * @param WC_Product $product
 */
function svtmg_product_brand( $product ): string {
	$id = $product->get_id();

	foreach ( array( 'product_brand', 'pwb-brand', 'yith_product_brand' ) as $tax ) {
		if ( taxonomy_exists( $tax ) ) {
			$names = wp_get_post_terms( $id, $tax, array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $names ) && $names ) {
				return implode( ', ', $names );
			}
		}
	}
	foreach ( svtmg_brand_attributes() as $tax ) {
		$value = trim( (string) $product->get_attribute( $tax ) );
		if ( '' !== $value ) {
			return $value;
		}
	}
	return '';
}

/* -------------------------------------------------------------------------
 * Терміни: категорії товарів, бренди, мітки
 * ---------------------------------------------------------------------- */

/**
 * @param array $override Незбережені 'title' / 'content' з форми редагування терміна.
 */
function svtmg_term_source( WP_Term $term, array $override = array() ): array {
	$tax   = get_taxonomy( $term->taxonomy );
	$name  = isset( $override['title'] ) && '' !== $override['title'] ? (string) $override['title'] : $term->name;
	$desc  = isset( $override['content'] ) && '' !== $override['content'] ? (string) $override['content'] : $term->description;
	$facts = array();

	if ( $term->parent ) {
		$chain = array();
		foreach ( array_reverse( get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' ) ) as $ancestor_id ) {
			$ancestor = get_term( $ancestor_id, $term->taxonomy );
			if ( $ancestor instanceof WP_Term ) {
				$chain[] = $ancestor->name;
			}
		}
		if ( $chain ) {
			$facts['Розділ каталогу'] = implode( ' > ', $chain );
		}
	}

	$children = get_terms(
		array(
			'taxonomy'   => $term->taxonomy,
			'parent'     => $term->term_id,
			'hide_empty' => false,
			'number'     => 20,
			'fields'     => 'names',
			'lang'       => '', // Polylang: дочірні терміни вже тієї самої мови.
		)
	);
	if ( ! is_wp_error( $children ) && $children ) {
		$facts['Підкатегорії'] = implode( ', ', $children );
	}

	// Приклади товарів дають моделі контекст, коли опис категорії порожній.
	$object_types = $tax ? (array) $tax->object_type : array( 'post' );
	$samples      = get_posts(
		array(
			'post_type'      => $object_types,
			'post_status'    => 'publish',
			'posts_per_page' => 12,
			'no_found_rows'  => true,
			'lang'           => '',
			'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'taxonomy'         => $term->taxonomy,
					'terms'            => $term->term_id,
					'include_children' => true,
				),
			),
		)
	);
	if ( $samples ) {
		$facts['Приклади товарів'] = implode( '; ', array_map( static fn( $p ) => svtmg_truncate_words( $p->post_title, 90 ), $samples ) );

		if ( 'product_cat' === $term->taxonomy && function_exists( 'wc_get_product' ) ) {
			$brands = array();
			foreach ( $samples as $p ) {
				$product = wc_get_product( $p->ID );
				$brand   = $product ? svtmg_product_brand( $product ) : '';
				if ( '' !== $brand ) {
					$brands[ $brand ] = true;
				}
			}
			if ( $brands ) {
				$facts['Бренди в категорії'] = implode( ', ', array_slice( array_keys( $brands ), 0, 10 ) );
			}
		}
	}
	if ( $term->count ) {
		$facts['Кількість товарів'] = (string) $term->count;
	}

	$link = get_term_link( $term );

	return array(
		'kind'  => $tax ? $tax->labels->singular_name . ' (' . $term->taxonomy . ')' : $term->taxonomy,
		'title' => svtmg_clean_source_text( $name ),
		'facts' => (array) apply_filters( 'svtmg_term_facts', $facts, $term ),
		'text'  => svtmg_truncate_words( (string) apply_filters( 'svtmg_source_text', svtmg_clean_source_text( $desc ), 'term', $term ), svtmg_source_limit() ),
		'url'   => is_wp_error( $link ) ? '' : $link,
	);
}

/**
 * Зібрати дані для об'єкта.
 *
 * @return array|WP_Error
 */
function svtmg_get_source( string $type, int $id, array $override = array() ) {
	if ( 'term' === $type ) {
		$term = get_term( $id );
		return $term instanceof WP_Term ? svtmg_term_source( $term, $override ) : new WP_Error( 'svtmg_no_object', __( 'Термін не знайдено.', 'svt-meta-generator' ) );
	}
	$post = get_post( $id );
	return $post && ! wp_is_post_revision( $post ) ? svtmg_post_source( $post, $override ) : new WP_Error( 'svtmg_no_object', __( 'Запис не знайдено.', 'svt-meta-generator' ) );
}
