<?php
/**
 * Генерація meta description: запит до Claude API, постобробка, збереження, автогенерація.
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Claude API
 * ---------------------------------------------------------------------- */

/**
 * Запит до Messages API з повтором при 429/529 (та мережевих/5xx помилках) з експоненційною затримкою.
 *
 * @return string|WP_Error Текст відповіді.
 */
function svtmg_api_request( string $system, array $messages, ?int $max_retries = null ) {
	$key = svtmg_get_api_key();
	if ( '' === $key ) {
		return new WP_Error( 'svtmg_no_key', __( 'Не задано API-ключ Anthropic.', 'svt-meta-generator' ) );
	}

	$max_retries = $max_retries ?? (int) apply_filters( 'svtmg_max_retries', 3 );
	$body        = wp_json_encode(
		array(
			'model'      => svtmg_get_model(),
			// Із запасом: деякі новіші моделі витрачають частину ліміту на міркування перед текстом.
			'max_tokens' => (int) apply_filters( 'svtmg_max_tokens', 1024 ),
			'system'     => $system,
			'messages'   => $messages,
		)
	);

	for ( $attempt = 0; ; $attempt++ ) {
		$response = wp_remote_post(
			SVTMG_API_URL,
			array(
				'timeout' => 60,
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
			$error = new WP_Error( 'svtmg_http', sprintf( __( 'Мережева помилка: %s', 'svt-meta-generator' ), $response->get_error_message() ) );
		} else {
			$code = (int) wp_remote_retrieve_response_code( $response );
			$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

			if ( 200 === $code && is_array( $data ) ) {
				if ( 'refusal' === ( $data['stop_reason'] ?? '' ) ) {
					return new WP_Error( 'svtmg_refusal', __( 'Модель відмовилася відповідати на цей запит.', 'svt-meta-generator' ) );
				}
				$text = '';
				foreach ( (array) ( $data['content'] ?? array() ) as $block ) {
					if ( is_array( $block ) && 'text' === ( $block['type'] ?? '' ) ) {
						$text .= (string) $block['text'];
					}
				}
				if ( '' === trim( $text ) ) {
					return new WP_Error( 'svtmg_empty', __( 'API повернув порожню відповідь.', 'svt-meta-generator' ) );
				}
				return $text;
			}

			$api_msg     = is_array( $data ) ? (string) ( $data['error']['message'] ?? '' ) : '';
			$error       = new WP_Error( 'svtmg_api', sprintf( __( 'Помилка API (HTTP %1$d): %2$s', 'svt-meta-generator' ), $code, '' !== $api_msg ? $api_msg : __( 'невідома помилка', 'svt-meta-generator' ) ), array( 'status' => $code ) );
			$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
		}

		$retryable = in_array( $code, array( 0, 429, 500, 502, 503, 504, 529 ), true );
		if ( ! $retryable || $attempt >= $max_retries ) {
			return $error;
		}

		// 2, 4, 8, 16... секунд; поважаємо retry-after, але не довше 60 с.
		$delay = (int) min( 60, max( 2 ** ( $attempt + 1 ), $retry_after ) );
		svtmg_log( sprintf( '%s Повтор через %d с (спроба %d з %d).', $error->get_error_message(), $delay, $attempt + 2, $max_retries + 1 ), 'warning' );
		sleep( $delay );
	}
}

/* -------------------------------------------------------------------------
 * Постобробка
 * ---------------------------------------------------------------------- */

function svtmg_clean_description( string $text ): string {
	$text = wp_strip_all_tags( $text );
	$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$text = preg_replace( '/\s+/u', ' ', $text ) ?? $text;
	$text = trim( $text );

	// Лапки на краях і префікс "Meta description:" / "Описание:" / "Опис:".
	$edge_quotes = '/^[\s"\'«»„“”‘’`]+|[\s"\'«»„“”‘’`]+$/u';
	$text        = preg_replace( $edge_quotes, '', $text ) ?? $text;
	$text        = preg_replace( '/^(meta[\s\-]*description|описание|опис|description)\s*[:\-]\s*/iu', '', $text ) ?? $text;
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
function svtmg_trim_to_length( string $text, int $max ): string {
	if ( mb_strlen( $text ) <= $max ) {
		return $text;
	}
	$cut   = mb_substr( $text, 0, $max );
	$space = mb_strrpos( $cut, ' ' );
	if ( false !== $space && $space > $max * 0.6 ) {
		$cut = mb_substr( $cut, 0, $space );
	}
	$cut = preg_replace( '/[\s,;:\-]+$/u', '', $cut ) ?? $cut;
	// Не закінчувати на висячому прийменнику / сполучнику (рос. і укр.).
	$cut = preg_replace( '/(\s+(у|в|во|з|із|зі|с|со|і|и|й|та|на|до|за|для|про|о|об|або|или|чи|что|що|а|но|від|от|по|из|к|як|как))+$/iu', '', $cut ) ?? $cut;
	$cut = preg_replace( '/[\s,;:\-]+$/u', '', $cut ) ?? $cut;
	if ( ! preg_match( '/[.!?]$/u', $cut ) && mb_strlen( $cut ) < $max ) {
		$cut .= '.';
	}
	return $cut;
}

function svtmg_length_distance( int $len, int $min, int $max ): int {
	return $len < $min ? $min - $len : max( 0, $len - $max );
}

/* -------------------------------------------------------------------------
 * Промпт
 * ---------------------------------------------------------------------- */

function svtmg_build_user_message( array $source, string $lang, int $min, int $max ): string {
	$lines = array(
		'Мова опису: ' . svtmg_language_label( $lang ),
		'Тип сторінки: ' . $source['kind'],
		'Назва: ' . $source['title'],
	);
	foreach ( $source['facts'] as $label => $value ) {
		$lines[] = $label . ':' . ( str_starts_with( $value, "\n" ) ? '' : ' ' ) . $value;
	}
	if ( '' !== $source['url'] ) {
		$lines[] = 'URL: ' . rawurldecode( $source['url'] );
	}
	$lines[] = sprintf( 'Потрібна довжина опису: від %d до %d символів з пробілами.', $min, $max );
	$lines[] = '';
	$lines[] = 'Текст сторінки:';
	$lines[] = '' !== $source['text'] ? $source['text'] : '(текст відсутній, орієнтуйся на назву і дані вище)';

	return implode( "\n", $lines );
}

function svtmg_length_fix_message( string $lang, int $len, int $min, int $max ): string {
	if ( 'uk' === $lang ) {
		return $len > $max
			? sprintf( 'Опис має %1$d символів, це забагато. Скороти до довжини від %2$d до %3$d символів з пробілами, збережи головне і заклик. Поверни тільки текст опису.', $len, $min, $max )
			: sprintf( 'Опис має %1$d символів, це замало. Розшир до довжини від %2$d до %3$d символів з пробілами, не вигадуй фактів. Поверни тільки текст опису.', $len, $min, $max );
	}
	return $len > $max
		? sprintf( 'В описании %1$d символов, это слишком много. Сократи до длины от %2$d до %3$d символов с пробелами, сохрани главное и призыв. Верни только текст описания.', $len, $min, $max )
		: sprintf( 'В описании %1$d символов, это слишком мало. Расширь до длины от %2$d до %3$d символов с пробелами, не выдумывай фактов. Верни только текст описания.', $len, $min, $max );
}

/* -------------------------------------------------------------------------
 * Головна функція
 * ---------------------------------------------------------------------- */

/**
 * Згенерувати і зберегти опис для запису або терміна.
 *
 * @param string $type      post|term
 * @param bool   $overwrite Перезаписати існуючий (не шаблонний) опис.
 * @param array  $override  Незбережені 'title' / 'content' з редактора.
 * @return string|WP_Error Згенерований опис.
 */
function svtmg_generate( string $type, int $id, bool $overwrite = false, array $override = array() ) {
	$log_id = svtmg_log_id( $type, $id );

	if ( ! $overwrite && ! svtmg_needs_description( $type, $id ) ) {
		return new WP_Error( 'svtmg_exists', __( 'Опис уже існує.', 'svt-meta-generator' ) );
	}

	$source = svtmg_get_source( $type, $id, $override );
	if ( is_wp_error( $source ) ) {
		return $source;
	}

	$lang     = svtmg_get_object_language( $type, $id );
	$min      = (int) svtmg_get_setting( 'min_len' );
	$max      = (int) svtmg_get_setting( 'max_len' );
	$system   = svtmg_get_prompt( $lang );
	$messages = array( array( 'role' => 'user', 'content' => svtmg_build_user_message( $source, $lang, $min, $max ) ) );

	$raw = svtmg_api_request( $system, $messages );
	if ( is_wp_error( $raw ) ) {
		svtmg_log( $raw->get_error_message(), 'error', $log_id );
		return $raw;
	}

	$desc = svtmg_clean_description( $raw );
	if ( '' === $desc ) {
		$err = new WP_Error( 'svtmg_empty', __( 'Модель повернула порожній опис.', 'svt-meta-generator' ) );
		svtmg_log( $err->get_error_message(), 'error', $log_id );
		return $err;
	}

	// Один повторний запит, якщо довжина поза межами.
	$len = mb_strlen( $desc );
	if ( $len < $min || $len > $max ) {
		$messages[] = array( 'role' => 'assistant', 'content' => $desc );
		$messages[] = array( 'role' => 'user', 'content' => svtmg_length_fix_message( $lang, $len, $min, $max ) );

		$retry = svtmg_api_request( $system, $messages );
		if ( is_wp_error( $retry ) ) {
			svtmg_log( 'Повторний запит (довжина): ' . $retry->get_error_message(), 'warning', $log_id );
		} else {
			$desc2 = svtmg_clean_description( $retry );
			if ( '' !== $desc2 && svtmg_length_distance( mb_strlen( $desc2 ), $min, $max ) <= svtmg_length_distance( $len, $min, $max ) ) {
				$desc = $desc2;
			}
		}

		$len = mb_strlen( $desc );
		if ( $len > $max ) {
			$desc = svtmg_trim_to_length( $desc, $max );
			svtmg_log( sprintf( 'Опис обрізано з %d до %d символів.', $len, mb_strlen( $desc ) ), 'warning', $log_id );
		} elseif ( $len < $min ) {
			svtmg_log( sprintf( 'Опис коротший за мінімум: %d символів (мін. %d).', $len, $min ), 'warning', $log_id );
		}
	}

	$desc = (string) apply_filters( 'svtmg_generated_description', $desc, $type, $id, $lang );
	svtmg_save_description( $type, $id, $desc );

	return $desc;
}

/* -------------------------------------------------------------------------
 * Автогенерація: у спільну чергу (з паузами), а не окремими подіями —
 * щоб масовий імпорт/синхронізація товарів не запускали сотні запитів одночасно.
 * ---------------------------------------------------------------------- */

function svtmg_auto_enabled(): bool {
	return ! empty( svtmg_get_setting( 'auto_generate' ) ) && '' !== svtmg_get_api_key();
}

function svtmg_on_save_post( int $post_id, WP_Post $post ): void {
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
		|| wp_is_post_revision( $post_id )
		|| wp_is_post_autosave( $post_id )
		|| 'publish' !== $post->post_status
		|| ! svtmg_auto_enabled()
		|| ! svtmg_is_post_type_enabled( $post->post_type )
		|| ! svtmg_needs_description( 'post', $post_id ) ) {
		return;
	}
	svtmg_bulk_enqueue( array( svtmg_key( 'post', $post_id ) ), false );
}
add_action( 'save_post', 'svtmg_on_save_post', 20, 2 );

function svtmg_on_created_term( int $term_id, int $tt_id, string $taxonomy ): void {
	if ( ! svtmg_auto_enabled() || ! svtmg_is_taxonomy_enabled( $taxonomy ) || ! svtmg_needs_description( 'term', $term_id ) ) {
		return;
	}
	svtmg_bulk_enqueue( array( svtmg_key( 'term', $term_id ) ), false );
}
add_action( 'created_term', 'svtmg_on_created_term', 20, 3 );
