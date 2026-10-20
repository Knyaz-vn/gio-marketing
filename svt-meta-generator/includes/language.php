<?php
/**
 * Мова запису / терміна: Polylang, WPML або мова за замовчуванням з налаштувань.
 */

defined( 'ABSPATH' ) || exit;

/**
 * @return array<string, string> код => назва
 */
function svtmg_supported_languages(): array {
	return array(
		'ru' => 'Русский',
		'uk' => 'Українська',
	);
}

function svtmg_normalize_lang( string $code ): string {
	$code = strtolower( substr( trim( $code ), 0, 2 ) );
	if ( 'ua' === $code ) {
		$code = 'uk';
	}
	return isset( svtmg_supported_languages()[ $code ] ) ? $code : '';
}

/**
 * @return string polylang|wpml|''
 */
function svtmg_multilang_plugin(): string {
	if ( function_exists( 'pll_get_post_language' ) ) {
		return 'polylang';
	}
	if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
		return 'wpml';
	}
	return '';
}

/**
 * Мова, якою генерувати опис для об'єкта.
 *
 * @param string $type post|term
 */
function svtmg_get_object_language( string $type, int $id ): string {
	$lang = '';

	switch ( svtmg_multilang_plugin() ) {
		case 'polylang':
			$lang = 'term' === $type ? pll_get_term_language( $id, 'slug' ) : pll_get_post_language( $id, 'slug' );
			break;

		case 'wpml':
			if ( 'term' === $type ) {
				$term = get_term( $id );
				if ( $term instanceof WP_Term ) {
					$lang = apply_filters( 'wpml_element_language_code', null, array( 'element_id' => $term->term_taxonomy_id, 'element_type' => 'tax_' . $term->taxonomy ) );
				}
			} else {
				$details = apply_filters( 'wpml_post_language_details', null, $id );
				$lang    = is_array( $details ) ? ( $details['language_code'] ?? '' ) : '';
			}
			break;
	}

	$lang = svtmg_normalize_lang( (string) $lang );
	if ( '' === $lang ) {
		$lang = svtmg_normalize_lang( (string) svtmg_get_setting( 'default_lang' ) ) ?: 'ru';
	}
	return $lang;
}

/**
 * Назва мови для промпту користувача (в родовому відмінку не потрібна — лише позначка).
 */
function svtmg_language_label( string $lang ): string {
	return 'uk' === $lang ? 'українська (uk)' : 'російська (ru)';
}
