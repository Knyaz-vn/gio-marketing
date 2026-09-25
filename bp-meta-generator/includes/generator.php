<?php
/**
 * Генерація meta description: джерело тексту, запит до Claude API, очищення, збереження.
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Де зберігати опис
 * ---------------------------------------------------------------------- */

/**
 * @return string yoast|rankmath|none
 */
function bpmg_get_seo_plugin(): string {
	if ( defined( 'WPSEO_VERSION' ) ) {
		return 'yoast';
	}
	if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
		return 'rankmath';
	}
	return 'none';
}

function bpmg_get_meta_key(): string {
	switch ( bpmg_get_seo_plugin() ) {
		case 'yoast':
			return '_yoast_wpseo_metadesc';
		case 'rankmath':
			return 'rank_math_description';
		default:
			return '_bpmg_meta_description';
	}
}

function bpmg_get_description( int $post_id ): string {
	return trim( (string) get_post_meta( $post_id, bpmg_get_meta_key(), true ) );
}

function bpmg_save_description( int $post_id, string $description ): void {
	$key = bpmg_get_meta_key();

	if ( '' === $description ) {
		delete_post_meta( $post_id, $key );
	} else {
		update_post_meta( $post_id, $key, wp_slash( $description ) );
	}

	if ( 'yoast' === bpmg_get_seo_plugin() ) {
		bpmg_refresh_yoast_indexable( $post_id );
	}

	do_action( 'bpmg_description_saved', $post_id, $description, $key );
}

/**
 * Yoast 14+ бере опис для <head> з таблиці indexables, тому перебудовуємо запис.
 */
function bpmg_refresh_yoast_indexable( int $post_id ): void {
	if ( ! function_exists( 'YoastSEO' )
		|| ! class_exists( '\Yoast\WP\SEO\Builders\Indexable_Builder' )
		|| ! class_exists( '\Yoast\WP\SEO\Repositories\Indexable_Repository' ) ) {
		return;
	}
	try {
		$repo      = YoastSEO()->classes->get( \Yoast\WP\SEO\Repositories\Indexable_Repository::class );
		$builder   = YoastSEO()->classes->get( \Yoast\WP\SEO\Builders\Indexable_Builder::class );
		$indexable = $repo->find_by_id_and_type( $post_id, 'post', false );
		$builder->build_for_id_and_type( $post_id, 'post', $indexable );
	} catch ( \Throwable $e ) {
		bpmg_log( 'Не вдалося оновити Yoast indexable: ' . $e->getMessage(), 'warning', $post_id );
	}
}

/* -------------------------------------------------------------------------
 * Джерело тексту
 * ---------------------------------------------------------------------- */

/**
 * HTML/шорткоди → чистий текст.
 */
function bpmg_clean_source_text( string $html ): string {
	$text = strip_shortcodes( $html );
	// Незареєстровані шорткоди (конструктори, вимкнені плагіни) — прибираємо дужки, залишаючи вміст.
	$text = preg_replace( '/\[\/?[a-zA-Z0-9_\-]+(?:\s[^\]]*)?\]/u', ' ', $text ) ?? $text;
	$text = preg_replace( '@<(script|style|noscript|iframe|svg)[^>]*?>.*?</\1>@si', ' ', $text ) ?? $text;
	$text = preg_replace( '/<[^>]+>/', ' ', $text ) ?? $text; // пробіл замість тегу, щоб слова не зливались.
	$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$text = preg_replace( '/[ \t\x{00A0}]+/u', ' ', $text ) ?? $text;
	$text = preg_replace( '/\s*\n\s*/u', "\n", $text ) ?? $text;
	$text = preg_replace( '/\n{2,}/u', "\n", $text ) ?? $text;
	$text = preg_replace( '/ +([.,!?;:])/u', '$1', $text ) ?? $text;
	return trim( $text );
}

function bpmg_truncate_words( string $text, int $limit ): string {
	if ( mb_strlen( $text ) <= $limit ) {
		return $text;
	}
	$cut   = mb_substr( $text, 0, $limit );
	$space = mb_strrpos( $cut, ' ' );
	return rtrim( false !== $space && $space > $limit * 0.8 ? mb_substr( $cut, 0, $space ) : $cut );
}

/**
 * Зібрати заголовок, H1 і текст сторінки.
 *
 * @param array $override Необов'язково: 'title', 'content' з редактора (ще не збережені).
 * @return array{title: string, h1: string, text: string}
 */
function bpmg_get_source_data( WP_Post $post, array $override = array() ): array {
	$title   = isset( $override['title'] ) && '' !== $override['title'] ? (string) $override['title'] : $post->post_title;
	$content = isset( $override['content'] ) && '' !== $override['content'] ? (string) $override['content'] : $post->post_content;
	$h1      = '';
	$text    = '';

	if ( bpmg_is_elementor_post( $post->ID ) ) {
		$el   = bpmg_elementor_extract( $post->ID );
		$h1   = $el['h1'];
		$text = bpmg_clean_source_text( $el['text'] );
	}

	if ( '' === $h1 && preg_match( '/<h1[^>]*>(.*?)<\/h1>/is', $content, $m ) ) {
		$h1 = bpmg_clean_source_text( $m[1] );
	}

	// post_content — основне джерело для звичайних сторінок і запасне для Elementor.
	if ( '' === $text ) {
		$text = bpmg_clean_source_text( $content );
	}

	if ( '' !== trim( $post->post_excerpt ) ) {
		$text = bpmg_clean_source_text( $post->post_excerpt ) . "\n" . $text;
	}

	$text = (string) apply_filters( 'bpmg_source_text', $text, $post );

	return array(
		'title' => bpmg_clean_source_text( $title ),
		'h1'    => $h1,
		'text'  => bpmg_truncate_words( $text, (int) apply_filters( 'bpmg_source_max_chars', 3000 ) ),
	);
}

function bpmg_build_user_message( WP_Post $post, array $source, int $min, int $max ): string {
	$type_obj = get_post_type_object( $post->post_type );
	$lines    = array(
		'Тип сторінки: ' . ( $type_obj ? $type_obj->labels->singular_name : $post->post_type ),
		'Заголовок: ' . $source['title'],
	);
	if ( '' !== $source['h1'] && $source['h1'] !== $source['title'] ) {
		$lines[] = 'H1: ' . $source['h1'];
	}
	$lines[] = 'URL: ' . rawurldecode( (string) get_permalink( $post ) );
	$lines[] = sprintf( 'Потрібна довжина опису: від %d до %d символів з пробілами.', $min, $max );
	$lines[] = '';
	$lines[] = 'Текст сторінки:';
	$lines[] = '' !== $source['text'] ? $source['text'] : '(текст відсутній, орієнтуйся на заголовок)';

	return implode( "\n", $lines );
}

/* -------------------------------------------------------------------------
 * Claude API
 * ---------------------------------------------------------------------- */

/**
 * Запит до Messages API з повтором при 429/529 (та мережевих/5xx помилках) з експоненційною затримкою.
 *
 * @return string|WP_Error Текст відповіді.
 */
function bpmg_api_request( string $system, array $messages, int $max_tokens = 400, ?int $max_retries = null ) {
	$key = bpmg_get_api_key();
	if ( '' === $key ) {
		return new WP_Error( 'bpmg_no_key', __( 'Не задано API-ключ Anthropic.', 'bp-meta-generator' ) );
	}

	$max_retries = $max_retries ?? (int) apply_filters( 'bpmg_max_retries', 3 );
	$body        = wp_json_encode(
		array(
			'model'      => bpmg_get_model(),
			'max_tokens' => $max_tokens,
			'system'     => $system,
			'messages'   => $messages,
		)
	);

	for ( $attempt = 0; ; $attempt++ ) {
		$response = wp_remote_post(
			BPMG_API_URL,
			array(
				'timeout' => 45,
				'headers' => array(
					'x-api-key'         => $key,
					'anthropic-version' => '2023-06-01',
					'content-type'      => 'application/json',
				),
				'body'    => $body,
			)
		);

		$retry_after = 0;
		if ( is_wp_error( $response ) ) {
			$code  = 0;
			$error = new WP_Error( 'bpmg_http', sprintf( __( 'Мережева помилка: %s', 'bp-meta-generator' ), $response->get_error_message() ) );
		} else {
			$code = (int) wp_remote_retrieve_response_code( $response );
			$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

			if ( 200 === $code && is_array( $data ) ) {
				$text = '';
				foreach ( (array) ( $data['content'] ?? array() ) as $block ) {
					if ( is_array( $block ) && 'text' === ( $block['type'] ?? '' ) ) {
						$text .= (string) $block['text'];
					}
				}
				if ( '' === trim( $text ) ) {
					return new WP_Error( 'bpmg_empty', __( 'API повернув порожню відповідь.', 'bp-meta-generator' ) );
				}
				return $text;
			}

			$api_msg     = is_array( $data ) ? (string) ( $data['error']['message'] ?? '' ) : '';
			$error       = new WP_Error( 'bpmg_api', sprintf( __( 'Помилка API (HTTP %1$d): %2$s', 'bp-meta-generator' ), $code, '' !== $api_msg ? $api_msg : __( 'невідома помилка', 'bp-meta-generator' ) ), array( 'status' => $code ) );
			$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
		}

		$retryable = in_array( $code, array( 0, 429, 500, 502, 503, 504, 529 ), true );
		if ( ! $retryable || $attempt >= $max_retries ) {
			return $error;
		}

		// 2, 4, 8, 16... секунд; поважаємо retry-after, але не довше 60 с.
		$delay = (int) min( 60, max( 2 ** ( $attempt + 1 ), $retry_after ) );
		bpmg_log( sprintf( '%s Повтор через %d с (спроба %d з %d).', $error->get_error_message(), $delay, $attempt + 2, $max_retries + 1 ), 'warning' );
		sleep( $delay );
	}
}

/* -------------------------------------------------------------------------
 * Постобробка
 * ---------------------------------------------------------------------- */

function bpmg_clean_description( string $text ): string {
	$text = wp_strip_all_tags( $text );
	$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$text = preg_replace( '/\s+/u', ' ', $text ) ?? $text;
	$text = trim( $text );

	// Лапки на краях і префікс "Meta description:", який модель іноді додає.
	$edge_quotes = '/^[\s"\'«»„“”‘’`]+|[\s"\'«»„“”‘’`]+$/u';
	$text        = preg_replace( $edge_quotes, '', $text ) ?? $text;
	$text        = preg_replace( '/^(meta[\s\-]*description|опис|description)\s*[:\-]\s*/iu', '', $text ) ?? $text;
	$text        = preg_replace( $edge_quotes, '', $text ) ?? $text;

	// Заборонена типографіка: довге тире, ялинки, емодзі.
	$text = str_replace( array( '—', '–' ), '-', $text );
	$text = str_replace( array( '«', '»', '„', '“', '”' ), '"', $text );
	$text = preg_replace( '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u', '', $text ) ?? $text;

	$text = preg_replace( '/\s+/u', ' ', $text ) ?? $text;
	return trim( $text );
}

/**
 * Жорстке обрізання по межі слова, якщо модель двічі не влізла в ліміт.
 */
function bpmg_trim_to_length( string $text, int $max ): string {
	if ( mb_strlen( $text ) <= $max ) {
		return $text;
	}
	$cut   = mb_substr( $text, 0, $max );
	$space = mb_strrpos( $cut, ' ' );
	if ( false !== $space && $space > $max * 0.6 ) {
		$cut = mb_substr( $cut, 0, $space );
	}
	$cut = preg_replace( '/[\s,;:\-]+$/u', '', $cut ) ?? $cut;
	// Не закінчувати на висячому прийменнику / сполучнику ("... у.", "... та.").
	$cut = preg_replace( '/(\s+(у|в|з|із|зі|і|й|та|на|до|за|для|про|або|чи|що|а|від|по|як))+$/iu', '', $cut ) ?? $cut;
	$cut = preg_replace( '/[\s,;:\-]+$/u', '', $cut ) ?? $cut;
	if ( ! preg_match( '/[.!?]$/u', $cut ) && mb_strlen( $cut ) < $max ) {
		$cut .= '.';
	}
	return $cut;
}

function bpmg_length_distance( int $len, int $min, int $max ): int {
	return $len < $min ? $min - $len : max( 0, $len - $max );
}

/* -------------------------------------------------------------------------
 * Головна функція
 * ---------------------------------------------------------------------- */

/**
 * Згенерувати і зберегти опис для запису.
 *
 * @param bool  $overwrite Перезаписати існуючий опис.
 * @param array $override  Незбережені 'title' / 'content' з редактора.
 * @return string|WP_Error Згенерований опис.
 */
function bpmg_generate_for_post( int $post_id, bool $overwrite = false, array $override = array() ) {
	$post = get_post( $post_id );
	if ( ! $post || wp_is_post_revision( $post ) ) {
		return new WP_Error( 'bpmg_no_post', __( 'Запис не знайдено.', 'bp-meta-generator' ) );
	}
	if ( ! $overwrite && '' !== bpmg_get_description( $post_id ) ) {
		return new WP_Error( 'bpmg_exists', __( 'Опис уже існує.', 'bp-meta-generator' ) );
	}

	$min     = (int) bpmg_get_setting( 'min_len' );
	$max     = (int) bpmg_get_setting( 'max_len' );
	$system  = (string) bpmg_get_setting( 'system_prompt' );
	$source  = bpmg_get_source_data( $post, $override );
	$message = bpmg_build_user_message( $post, $source, $min, $max );

	$messages = array( array( 'role' => 'user', 'content' => $message ) );
	$raw      = bpmg_api_request( $system, $messages );
	if ( is_wp_error( $raw ) ) {
		bpmg_log( $raw->get_error_message(), 'error', $post_id );
		return $raw;
	}

	$desc = bpmg_clean_description( $raw );
	if ( '' === $desc ) {
		$err = new WP_Error( 'bpmg_empty', __( 'Модель повернула порожній опис.', 'bp-meta-generator' ) );
		bpmg_log( $err->get_error_message(), 'error', $post_id );
		return $err;
	}

	// Один повторний запит, якщо довжина поза межами.
	$len = mb_strlen( $desc );
	if ( $len < $min || $len > $max ) {
		$messages[] = array( 'role' => 'assistant', 'content' => $desc );
		$messages[] = array(
			'role'    => 'user',
			'content' => $len > $max
				? sprintf( 'Опис має %1$d символів, це забагато. Скороти до довжини від %2$d до %3$d символів з пробілами, збережи головну послугу і заклик. Поверни тільки текст опису.', $len, $min, $max )
				: sprintf( 'Опис має %1$d символів, це замало. Розшир до довжини від %2$d до %3$d символів з пробілами, не вигадуй фактів. Поверни тільки текст опису.', $len, $min, $max ),
		);

		$retry = bpmg_api_request( $system, $messages );
		if ( is_wp_error( $retry ) ) {
			bpmg_log( 'Повторний запит (довжина): ' . $retry->get_error_message(), 'warning', $post_id );
		} else {
			$desc2 = bpmg_clean_description( $retry );
			if ( '' !== $desc2 && bpmg_length_distance( mb_strlen( $desc2 ), $min, $max ) <= bpmg_length_distance( $len, $min, $max ) ) {
				$desc = $desc2;
			}
		}

		$len = mb_strlen( $desc );
		if ( $len > $max ) {
			$desc = bpmg_trim_to_length( $desc, $max );
			bpmg_log( sprintf( 'Опис обрізано з %d до %d символів.', $len, mb_strlen( $desc ) ), 'warning', $post_id );
		} elseif ( $len < $min ) {
			bpmg_log( sprintf( 'Опис коротший за мінімум: %d символів (мін. %d).', $len, $min ), 'warning', $post_id );
		}
	}

	$desc = (string) apply_filters( 'bpmg_generated_description', $desc, $post_id );
	bpmg_save_description( $post_id, $desc );

	return $desc;
}

/* -------------------------------------------------------------------------
 * Автогенерація при публікації (асинхронно)
 * ---------------------------------------------------------------------- */

function bpmg_on_save_post( int $post_id, WP_Post $post ): void {
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
		|| wp_is_post_revision( $post_id )
		|| wp_is_post_autosave( $post_id )
		|| 'publish' !== $post->post_status
		|| empty( bpmg_get_setting( 'auto_generate' ) )
		|| ! bpmg_is_post_type_enabled( $post->post_type )
		|| '' === bpmg_get_api_key()
		|| '' !== bpmg_get_description( $post_id ) ) {
		return;
	}

	$args = array( $post_id );
	if ( ! wp_next_scheduled( 'bpmg_generate_single', $args ) ) {
		// Невелика затримка: даємо редактору/Elementor/SEO-плагіну дозберегти мета-поля.
		wp_schedule_single_event( time() + 15, 'bpmg_generate_single', $args );
	}
}
add_action( 'save_post', 'bpmg_on_save_post', 20, 2 );

function bpmg_cron_generate_single( $post_id ): void {
	$post_id = (int) $post_id;
	$post    = get_post( $post_id );

	// Перевіряємо ще раз: за 15 секунд опис міг з'явитися або запис зняли з публікації.
	if ( ! $post || 'publish' !== $post->post_status || '' !== bpmg_get_description( $post_id ) ) {
		return;
	}
	bpmg_generate_for_post( $post_id, false );
}
add_action( 'bpmg_generate_single', 'bpmg_cron_generate_single' );
