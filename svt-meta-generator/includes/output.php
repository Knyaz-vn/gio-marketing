<?php
/**
 * Вивід <meta name="description"> та og:description, якщо немає Yoast / Rank Math.
 * Захист від дублів: якщо в <head> вже є інший description (тема, інший SEO-плагін), наш тег прибирається.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Опис для поточної сторінки фронтенду (запис, сторінка магазину, архів терміна).
 */
function svtmg_get_current_description(): string {
	$meta = '_svtmg_meta_description';

	if ( function_exists( 'is_shop' ) && is_shop() && function_exists( 'wc_get_page_id' ) ) {
		return trim( (string) get_post_meta( (int) wc_get_page_id( 'shop' ), $meta, true ) );
	}
	if ( is_singular() ) {
		return trim( (string) get_post_meta( (int) get_queried_object_id(), $meta, true ) );
	}
	if ( is_home() && ! is_front_page() ) {
		return trim( (string) get_post_meta( (int) get_option( 'page_for_posts' ), $meta, true ) );
	}
	if ( is_category() || is_tag() || is_tax() ) {
		return trim( (string) get_term_meta( (int) get_queried_object_id(), $meta, true ) );
	}
	return '';
}

function svtmg_output_init(): void {
	if ( 'none' !== svtmg_get_seo_plugin()
		|| is_admin() || is_feed() || is_embed() || is_robots()
		|| wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST )
		|| ! apply_filters( 'svtmg_output_enabled', true ) ) {
		return;
	}

	$desc = svtmg_get_current_description();
	if ( '' === $desc ) {
		return;
	}

	$GLOBALS['svtmg_current_description'] = $desc;
	add_action( 'wp_head', 'svtmg_output_meta_tags', 1 );
	ob_start( 'svtmg_output_dedupe_buffer' );
}
add_action( 'template_redirect', 'svtmg_output_init', 99 );

function svtmg_output_meta_tags(): void {
	$desc = (string) ( $GLOBALS['svtmg_current_description'] ?? '' );
	if ( '' === $desc ) {
		return;
	}
	echo "<!-- svtmg:desc --><meta name=\"description\" content=\"" . esc_attr( $desc ) . "\" /><!-- /svtmg:desc -->\n";
	echo "<!-- svtmg:og --><meta property=\"og:description\" content=\"" . esc_attr( $desc ) . "\" /><!-- /svtmg:og -->\n";
}

/**
 * Прибирає наші теги, якщо в <head> є ще один такий самий тег з іншого джерела.
 */
function svtmg_output_dedupe_buffer( string $html ): string {
	$head_end = stripos( $html, '</head>' );
	if ( false === $head_end ) {
		return $html;
	}

	$head = substr( $html, 0, $head_end );
	$rest = substr( $html, $head_end );

	$checks = array(
		'desc' => '/<meta\s[^>]*name\s*=\s*["\']description["\'][^>]*>/i',
		'og'   => '/<meta\s[^>]*property\s*=\s*["\']og:description["\'][^>]*>/i',
	);

	foreach ( $checks as $marker => $pattern ) {
		$block = '/<!-- svtmg:' . $marker . ' -->(.*?)<!-- \/svtmg:' . $marker . ' -->\n?/s';
		if ( preg_match_all( $pattern, $head ) > 1 ) {
			$head = preg_replace( $block, '', $head ) ?? $head;
		} else {
			$head = preg_replace( $block, '$1' . "\n", $head ) ?? $head;
		}
	}

	return $head . $rest;
}
