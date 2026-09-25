<?php
/**
 * Витягування тексту зі сторінок Elementor (_elementor_data).
 */

defined( 'ABSPATH' ) || exit;

function bpmg_is_elementor_post( int $post_id ): bool {
	return 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true )
		&& ! empty( bpmg_elementor_get_data( $post_id ) );
}

/**
 * Розпарсити JSON з метаполя _elementor_data.
 */
function bpmg_elementor_get_data( int $post_id ): array {
	$raw = get_post_meta( $post_id, '_elementor_data', true );

	if ( is_array( $raw ) ) {
		return $raw;
	}
	if ( ! is_string( $raw ) || '' === $raw ) {
		return array();
	}

	$data = json_decode( $raw, true );
	if ( ! is_array( $data ) ) {
		$data = json_decode( wp_unslash( $raw ), true );
	}
	return is_array( $data ) ? $data : array();
}

/**
 * @return array{h1: string, text: string}
 */
function bpmg_elementor_extract( int $post_id ): array {
	$state = array(
		'h1'    => '',
		'parts' => array(),
	);

	bpmg_elementor_walk( bpmg_elementor_get_data( $post_id ), $state, array( $post_id ) );

	return array(
		'h1'   => $state['h1'],
		'text' => implode( "\n", $state['parts'] ),
	);
}

/**
 * Рекурсивний обхід секцій / контейнерів / колонок / віджетів.
 *
 * @param int[] $visited ID шаблонів, щоб уникнути циклів.
 */
function bpmg_elementor_walk( array $elements, array &$state, array $visited ): void {
	foreach ( $elements as $el ) {
		if ( ! is_array( $el ) ) {
			continue;
		}
		if ( 'widget' === ( $el['elType'] ?? '' ) ) {
			bpmg_elementor_widget_text( $el, $state, $visited );
		}
		if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
			bpmg_elementor_walk( $el['elements'], $state, $visited );
		}
	}
}

function bpmg_elementor_widget_text( array $el, array &$state, array $visited ): void {
	$s    = isset( $el['settings'] ) && is_array( $el['settings'] ) ? $el['settings'] : array();
	$type = (string) ( $el['widgetType'] ?? '' );
	$add  = static function ( $value ) use ( &$state ): void {
		if ( is_string( $value ) && '' !== trim( $value ) ) {
			$state['parts'][] = $value;
		}
	};

	switch ( $type ) {
		case 'heading':
			$title = (string) ( $s['title'] ?? '' );
			if ( 'h1' === ( $s['header_size'] ?? 'h2' ) && '' === $state['h1'] ) {
				$state['h1'] = bpmg_clean_source_text( $title );
			}
			$add( $title );
			break;

		case 'text-editor':
			$add( $s['editor'] ?? '' );
			break;

		case 'icon-list':
			foreach ( (array) ( $s['icon_list'] ?? array() ) as $item ) {
				$add( is_array( $item ) ? ( $item['text'] ?? '' ) : '' );
			}
			break;

		case 'accordion':
		case 'toggle':
		case 'tabs':
			foreach ( (array) ( $s['tabs'] ?? array() ) as $item ) {
				if ( is_array( $item ) ) {
					$add( $item['tab_title'] ?? '' );
					$add( $item['tab_content'] ?? '' );
				}
			}
			break;

		case 'nested-accordion':
		case 'nested-tabs':
			// Вміст вкладених віджетів обходиться через 'elements', тут лише заголовки.
			foreach ( (array) ( $s['items'] ?? $s['tabs'] ?? array() ) as $item ) {
				if ( is_array( $item ) ) {
					$add( $item['item_title'] ?? $item['tab_title'] ?? '' );
				}
			}
			break;

		case 'icon-box':
		case 'image-box':
			$add( $s['title_text'] ?? '' );
			$add( $s['description_text'] ?? '' );
			break;

		case 'global':
		case 'template':
			// Глобальний віджет / вставлений шаблон — читаємо дані шаблону.
			$tpl_id = (int) ( $el['templateID'] ?? $s['template_id'] ?? 0 );
			if ( $tpl_id && ! in_array( $tpl_id, $visited, true ) && count( $visited ) < 10 ) {
				$visited[] = $tpl_id;
				bpmg_elementor_walk( bpmg_elementor_get_data( $tpl_id ), $state, $visited );
			}
			break;

		default:
			/**
			 * Дозволяє додати текст інших віджетів.
			 *
			 * @param string $text Текст (порожній за замовчуванням).
			 * @param string $type Тип віджета.
			 * @param array  $s    Налаштування віджета.
			 */
			$add( (string) apply_filters( 'bpmg_elementor_widget_text', '', $type, $s ) );
	}
}
