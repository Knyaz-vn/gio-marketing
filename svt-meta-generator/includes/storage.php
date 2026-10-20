<?php
/**
 * Де зберігається опис: записи (товари, сторінки) і терміни (категорії товарів тощо)
 * для Yoast SEO, Rank Math або власного поля.
 *
 * Об'єкт адресується парою (type, id), де type = post|term. У черзі — рядок "post:123" / "term:45".
 */

defined( 'ABSPATH' ) || exit;

/**
 * @return string yoast|rankmath|none
 */
function svtmg_get_seo_plugin(): string {
	if ( defined( 'WPSEO_VERSION' ) ) {
		return 'yoast';
	}
	if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
		return 'rankmath';
	}
	return 'none';
}

function svtmg_post_meta_key(): string {
	switch ( svtmg_get_seo_plugin() ) {
		case 'yoast':
			return '_yoast_wpseo_metadesc';
		case 'rankmath':
			return 'rank_math_description';
		default:
			return '_svtmg_meta_description';
	}
}

function svtmg_term_meta_key(): string {
	return 'rankmath' === svtmg_get_seo_plugin() ? 'rank_math_description' : '_svtmg_meta_description';
}

function svtmg_key( string $type, int $id ): string {
	return $type . ':' . $id;
}

/**
 * @return array{0: string, 1: int}|null
 */
function svtmg_parse_key( string $key ): ?array {
	if ( ! preg_match( '/^(post|term):(\d+)$/', $key, $m ) ) {
		return null;
	}
	return array( $m[1], (int) $m[2] );
}

/**
 * ID для колонки логу: терміни зберігаються зі знаком мінус.
 */
function svtmg_log_id( string $type, int $id ): int {
	return 'term' === $type ? -$id : $id;
}

/* -------------------------------------------------------------------------
 * Читання / запис
 * ---------------------------------------------------------------------- */

function svtmg_get_description( string $type, int $id ): string {
	if ( 'term' === $type ) {
		$term = get_term( $id );
		if ( ! $term instanceof WP_Term ) {
			return '';
		}
		if ( 'yoast' === svtmg_get_seo_plugin() ) {
			return svtmg_yoast_term_get( $term );
		}
		return trim( (string) get_term_meta( $id, svtmg_term_meta_key(), true ) );
	}
	return trim( (string) get_post_meta( $id, svtmg_post_meta_key(), true ) );
}

function svtmg_save_description( string $type, int $id, string $description ): void {
	$seo = svtmg_get_seo_plugin();

	if ( 'term' === $type ) {
		$term = get_term( $id );
		if ( ! $term instanceof WP_Term ) {
			return;
		}
		if ( 'yoast' === $seo ) {
			svtmg_yoast_term_set( $term, $description );
		} elseif ( '' === $description ) {
			delete_term_meta( $id, svtmg_term_meta_key() );
		} else {
			update_term_meta( $id, svtmg_term_meta_key(), wp_slash( $description ) );
		}
	} else {
		if ( '' === $description ) {
			delete_post_meta( $id, svtmg_post_meta_key() );
		} else {
			update_post_meta( $id, svtmg_post_meta_key(), wp_slash( $description ) );
		}
		if ( 'yoast' === $seo ) {
			svtmg_refresh_yoast_indexable( $id, 'post' );
		}
	}

	do_action( 'svtmg_description_saved', $type, $id, $description );
}

/**
 * Yoast зберігає SEO-дані термінів в опції wpseo_taxonomy_meta[taxonomy][term_id]['wpseo_desc'].
 */
function svtmg_yoast_term_get( WP_Term $term ): string {
	$all = get_option( 'wpseo_taxonomy_meta', array() );
	return is_array( $all ) ? trim( (string) ( $all[ $term->taxonomy ][ $term->term_id ]['wpseo_desc'] ?? '' ) ) : '';
}

function svtmg_yoast_term_set( WP_Term $term, string $description ): void {
	$all = get_option( 'wpseo_taxonomy_meta', array() );
	$all = is_array( $all ) ? $all : array();

	if ( '' === $description ) {
		unset( $all[ $term->taxonomy ][ $term->term_id ]['wpseo_desc'] );
	} else {
		$all[ $term->taxonomy ][ $term->term_id ]['wpseo_desc'] = $description;
	}
	update_option( 'wpseo_taxonomy_meta', $all );

	svtmg_refresh_yoast_indexable( $term->term_id, 'term' );
}

/**
 * Yoast 14+ бере опис для <head> з таблиці indexables, тому перебудовуємо запис.
 *
 * @param string $object_type post|term
 */
function svtmg_refresh_yoast_indexable( int $id, string $object_type ): void {
	if ( ! function_exists( 'YoastSEO' )
		|| ! class_exists( '\Yoast\WP\SEO\Builders\Indexable_Builder' )
		|| ! class_exists( '\Yoast\WP\SEO\Repositories\Indexable_Repository' ) ) {
		return;
	}
	try {
		$repo      = YoastSEO()->classes->get( \Yoast\WP\SEO\Repositories\Indexable_Repository::class );
		$builder   = YoastSEO()->classes->get( \Yoast\WP\SEO\Builders\Indexable_Builder::class );
		$indexable = $repo->find_by_id_and_type( $id, $object_type, false );
		$builder->build_for_id_and_type( $id, $object_type, $indexable );
	} catch ( \Throwable $e ) {
		svtmg_log( 'Не вдалося оновити Yoast indexable: ' . $e->getMessage(), 'warning', 'term' === $object_type ? -$id : $id );
	}
}

/* -------------------------------------------------------------------------
 * Порожній / шаблонний опис
 * ---------------------------------------------------------------------- */

/**
 * @return string[]
 */
function svtmg_template_patterns(): array {
	$raw = (string) svtmg_get_setting( 'template_patterns' );
	return array_values( array_filter( array_map( 'trim', explode( "\n", $raw ) ) ) );
}

/**
 * Опис порожній або містить фрагмент шаблонного тексту — його можна замінювати без перезапису.
 */
function svtmg_is_replaceable( string $description ): bool {
	if ( '' === trim( $description ) ) {
		return true;
	}
	foreach ( svtmg_template_patterns() as $pattern ) {
		if ( false !== mb_stripos( $description, $pattern ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Мова тексту за буквами, які є лише в одній з мов: укр. і ї є ґ / рос. ы э ъ ё.
 *
 * @return string ru|uk|'' (не визначено)
 */
function svtmg_detect_text_lang( string $text ): string {
	$uk = (int) preg_match_all( '/[іїєґ]/iu', $text );
	$ru = (int) preg_match_all( '/[ыэъё]/iu', $text );
	if ( $uk >= 2 && $uk >= $ru * 3 ) {
		return 'uk';
	}
	if ( $ru >= 2 && $ru >= $uk * 3 ) {
		return 'ru';
	}
	return '';
}

/**
 * Стан опису об'єкта.
 *
 * @return string ok | empty | template | wrong_lang
 */
function svtmg_description_status( string $type, int $id ): string {
	$desc = svtmg_get_description( $type, $id );
	if ( '' === $desc ) {
		return 'empty';
	}
	if ( svtmg_is_replaceable( $desc ) ) {
		return 'template';
	}
	// Напр., Polylang скопіював російський опис в український переклад.
	$text_lang = svtmg_detect_text_lang( $desc );
	if ( '' !== $text_lang && $text_lang !== svtmg_get_object_language( $type, $id ) ) {
		return 'wrong_lang';
	}
	return 'ok';
}

/**
 * Опис порожній, шаблонний або не тією мовою — його можна замінювати без перезапису.
 */
function svtmg_needs_description( string $type, int $id ): bool {
	return 'ok' !== svtmg_description_status( $type, $id );
}

/* -------------------------------------------------------------------------
 * Polylang: не копіювати опис у новий переклад (і при синхронізації полів),
 * інакше український товар отримає російський опис.
 * ---------------------------------------------------------------------- */

function svtmg_pll_exclude_metas( $keys ): array {
	$exclude = (array) apply_filters( 'svtmg_pll_exclude_meta_keys', array( '_svtmg_meta_description', '_yoast_wpseo_metadesc', 'rank_math_description' ) );
	return array_values( array_diff( (array) $keys, $exclude ) );
}
add_filter( 'pll_copy_post_metas', 'svtmg_pll_exclude_metas', 99 );
add_filter( 'pll_copy_term_metas', 'svtmg_pll_exclude_metas', 99 );
