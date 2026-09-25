<?php
/**
 * Вивід <meta name="description"> та og:description, якщо немає Yoast / Rank Math.
 * Захист від дублів: якщо в <head> вже є інший description (тема, інший SEO-плагін), наш тег прибирається.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Опис для поточної сторінки фронтенду.
 */
function bpmg_get_current_description(): string {
	$post_id = 0;

	if ( is_singular() ) {
		$post_id = (int) get_queried_object_id();
	} elseif ( is_home() && ! is_front_page() ) {
		$post_id = (int) get_option( 'page_for_posts' );
	}

	if ( ! $post_id ) {
		return '';
	}
	return trim( (string) get_post_meta( $post_id, '_bpmg_meta_description', true ) );
}

function bpmg_output_init(): void {
	if ( 'none' !== bpmg_get_seo_plugin()
		|| is_admin() || is_feed() || is_embed() || is_robots()
		|| wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST )
		|| ! apply_filters( 'bpmg_output_enabled', true ) ) {
		return;
	}

	$desc = bpmg_get_current_description();
	if ( '' === $desc ) {
		return;
	}

	$GLOBALS['bpmg_current_description'] = $desc;
	add_action( 'wp_head', 'bpmg_output_meta_tags', 1 );
	ob_start( 'bpmg_output_dedupe_buffer' );
}
add_action( 'template_redirect', 'bpmg_output_init', 99 );

function bpmg_output_meta_tags(): void {
	$desc = (string) ( $GLOBALS['bpmg_current_description'] ?? '' );
	if ( '' === $desc ) {
		return;
	}
	echo "<!-- bpmg:desc --><meta name=\"description\" content=\"" . esc_attr( $desc ) . "\" /><!-- /bpmg:desc -->\n";
	echo "<!-- bpmg:og --><meta property=\"og:description\" content=\"" . esc_attr( $desc ) . "\" /><!-- /bpmg:og -->\n";
}

/**
 * Прибирає наші теги, якщо в <head> є ще один такий самий тег з іншого джерела.
 */
function bpmg_output_dedupe_buffer( string $html ): string {
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
		$block = '/<!-- bpmg:' . $marker . ' -->(.*?)<!-- \/bpmg:' . $marker . ' -->\n?/s';
		if ( preg_match_all( $pattern, $head ) > 1 ) {
			$head = preg_replace( $block, '', $head ) ?? $head;
		} else {
			$head = preg_replace( $block, '$1' . "\n", $head ) ?? $head;
		}
	}

	return $head . $rest;
}
