<?php
/**
 * Черга генерації: масова обробка пачками через WP-Cron, прогрес через AJAX-опитування,
 * групова дія "Згенерувати опис" у списках записів і термінів. Елементи черги — "post:ID" / "term:ID".
 */

defined( 'ABSPATH' ) || exit;

function svtmg_bulk_default_state(): array {
	return array(
		'status'     => 'idle', // idle | running | done | cancelled
		'total'      => 0,
		'processed'  => 0,
		'success'    => 0,
		'skipped'    => 0,
		'errors'     => 0,
		'overwrite'  => 0,
		'started_at' => 0,
		'updated_at' => 0,
		'message'    => '',
	);
}

function svtmg_bulk_get_state(): array {
	// Скидаємо кеш, щоб бачити "Зупинити", натиснуте в іншому запиті.
	wp_cache_delete( 'svtmg_bulk_state', 'options' );
	$state = get_option( 'svtmg_bulk_state', array() );
	return wp_parse_args( is_array( $state ) ? $state : array(), svtmg_bulk_default_state() );
}

function svtmg_bulk_set_state( array $state ): void {
	$state['updated_at'] = time();
	update_option( 'svtmg_bulk_state', $state, false );
}

/**
 * @return string[]
 */
function svtmg_bulk_get_queue(): array {
	wp_cache_delete( 'svtmg_bulk_queue', 'options' );
	return array_values( array_filter( array_map( 'strval', (array) get_option( 'svtmg_bulk_queue', array() ) ), 'svtmg_parse_key' ) );
}

function svtmg_bulk_set_queue( array $queue ): void {
	update_option( 'svtmg_bulk_queue', array_values( $queue ), false );
}

/**
 * Знайти об'єкти для обробки.
 *
 * @param string $scope all | post_type:{name} | taxonomy:{name}
 * @param string $lang  '' = усі мови
 * @param int    $limit 0 = без обмеження
 * @return string[] Ключі черги.
 */
function svtmg_bulk_find( string $scope, string $lang, bool $overwrite, int $limit ): array {
	$types = svtmg_get_enabled_post_types();
	$taxes = svtmg_get_enabled_taxonomies();

	if ( str_starts_with( $scope, 'post_type:' ) ) {
		$types = array_intersect( $types, array( substr( $scope, 10 ) ) );
		$taxes = array();
	} elseif ( str_starts_with( $scope, 'taxonomy:' ) ) {
		$taxes = array_intersect( $taxes, array( substr( $scope, 9 ) ) );
		$types = array();
	}

	$is_pll = 'polylang' === svtmg_multilang_plugin();
	$keys   = array();

	// Записи: фільтр "порожній або шаблонний" прямо в SQL. Мову Polylang фільтрує в запиті,
	// а перевірка в PHP нижче страхує WPML і таксономії без перекладу.
	if ( $types ) {
		$args = array(
			'post_type'              => array_values( $types ),
			'post_status'            => 'publish',
			'fields'                 => 'ids',
			'posts_per_page'         => -1,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'suppress_filters'       => true, // WPML інакше обмежить поточною мовою адмінки; Polylang читає 'lang' при parse_query.
			'lang'                   => $is_pll ? $lang : '',
		);
		if ( ! $overwrite ) {
			$meta_key = svtmg_post_meta_key();
			$clauses  = array(
				'relation' => 'OR',
				array( 'key' => $meta_key, 'compare' => 'NOT EXISTS' ),
				array( 'key' => $meta_key, 'value' => '', 'compare' => '=' ),
			);
			foreach ( svtmg_template_patterns() as $pattern ) {
				$clauses[] = array( 'key' => $meta_key, 'value' => $pattern, 'compare' => 'LIKE' );
			}
			$args['meta_query'] = $clauses; // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		foreach ( get_posts( $args ) as $id ) {
			if ( '' !== $lang && svtmg_get_object_language( 'post', (int) $id ) !== $lang ) {
				continue;
			}
			$keys[] = svtmg_key( 'post', (int) $id );
		}
	}

	// Терміни: опис Yoast лежить в опції, тож фільтруємо в PHP.
	foreach ( $taxes as $tax ) {
		$ids = get_terms(
			array(
				'taxonomy'   => $tax,
				'hide_empty' => false,
				'fields'     => 'ids',
				'orderby'    => 'term_id',
				'lang'       => $is_pll ? $lang : '',
			)
		);
		if ( is_wp_error( $ids ) ) {
			continue;
		}
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( '' !== $lang && svtmg_get_object_language( 'term', $id ) !== $lang ) {
				continue;
			}
			if ( $overwrite || svtmg_needs_description( 'term', $id ) ) {
				$keys[] = svtmg_key( 'term', $id );
			}
		}
	}

	return $limit > 0 ? array_slice( $keys, 0, $limit ) : $keys;
}

/**
 * Додати в чергу і запустити обробку.
 *
 * @param string[] $keys
 * @return int Кількість доданих.
 */
function svtmg_bulk_enqueue( array $keys, bool $overwrite, bool $reset = false ): int {
	$state   = svtmg_bulk_get_state();
	$running = 'running' === $state['status'] && ! $reset;
	$queue   = $running ? svtmg_bulk_get_queue() : array();

	if ( ! $running ) {
		$state               = svtmg_bulk_default_state();
		$state['started_at'] = time();
	}

	$keys  = array_filter( array_map( 'strval', $keys ), 'svtmg_parse_key' );
	$new   = array_values( array_diff( array_unique( $keys ), $queue ) );
	$queue = array_merge( $queue, $new );

	if ( empty( $new ) ) {
		return 0;
	}

	$state['status']    = 'running';
	$state['total']    += count( $new );
	$state['overwrite'] = (int) ( $overwrite || ! empty( $state['overwrite'] ) );
	$state['message']   = '';

	svtmg_bulk_set_queue( $queue );
	svtmg_bulk_set_state( $state );

	if ( ! wp_next_scheduled( 'svtmg_bulk_process' ) ) {
		wp_schedule_single_event( time(), 'svtmg_bulk_process' );
	}

	return count( $new );
}

/**
 * Обробити одну пачку (WP-Cron).
 */
function svtmg_bulk_process_batch(): void {
	if ( get_transient( 'svtmg_bulk_lock' ) ) {
		return;
	}
	set_transient( 'svtmg_bulk_lock', time(), 10 * MINUTE_IN_SECONDS );

	try {
		$state = svtmg_bulk_get_state();
		if ( 'running' !== $state['status'] ) {
			return;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$pause = max( 0, (int) apply_filters( 'svtmg_request_pause', (int) svtmg_get_setting( 'pause' ) ) );
		$size  = max( 1, (int) apply_filters( 'svtmg_bulk_batch_size', (int) svtmg_get_setting( 'batch_size' ) ) );

		for ( $i = 0; $i < $size; $i++ ) {
			$queue = svtmg_bulk_get_queue();
			$state = svtmg_bulk_get_state();
			if ( empty( $queue ) || 'running' !== $state['status'] ) {
				break;
			}

			$key = (string) array_shift( $queue );
			svtmg_bulk_set_queue( $queue );

			list( $type, $id ) = svtmg_parse_key( $key );
			$result            = svtmg_generate( $type, $id, ! empty( $state['overwrite'] ) );

			$state = svtmg_bulk_get_state();
			$state['processed']++;
			if ( is_wp_error( $result ) ) {
				if ( 'svtmg_exists' === $result->get_error_code() ) {
					$state['skipped']++;
				} else {
					$state['errors']++;
					$state['message'] = sprintf( '%s: %s', $key, $result->get_error_message() );
				}
			} else {
				$state['success']++;
			}
			svtmg_bulk_set_state( $state );

			if ( ! empty( $queue ) && $i < $size - 1 && $pause ) {
				sleep( $pause );
			}
		}

		$state = svtmg_bulk_get_state();
		if ( 'running' !== $state['status'] ) {
			return;
		}

		if ( empty( svtmg_bulk_get_queue() ) ) {
			$state['status'] = 'done';
			svtmg_bulk_set_state( $state );
			if ( $state['total'] > 1 ) {
				svtmg_log( sprintf( 'Генерацію завершено: успішно %d, пропущено %d, помилок %d.', $state['success'], $state['skipped'], $state['errors'] ), 'info' );
			}
		} else {
			wp_schedule_single_event( time() + $pause, 'svtmg_bulk_process' );
		}
	} finally {
		delete_transient( 'svtmg_bulk_lock' );
	}
}
add_action( 'svtmg_bulk_process', 'svtmg_bulk_process_batch' );

/**
 * Якщо WP-Cron не спрацьовує (DISABLE_WP_CRON, мало відвідувачів) — обробляємо пачку прямо в запиті опитування.
 */
function svtmg_bulk_kick(): void {
	$state = svtmg_bulk_get_state();
	if ( 'running' !== $state['status'] || get_transient( 'svtmg_bulk_lock' ) ) {
		return;
	}

	$next = wp_next_scheduled( 'svtmg_bulk_process' );
	if ( ! $next ) {
		wp_schedule_single_event( time(), 'svtmg_bulk_process' );
		return;
	}
	if ( $next < time() - 30 && time() - (int) $state['updated_at'] > 30 ) {
		svtmg_bulk_process_batch();
	}
}

function svtmg_bulk_public_state(): array {
	$state = svtmg_bulk_get_state();
	return array(
		'status'    => $state['status'],
		'total'     => (int) $state['total'],
		'processed' => (int) $state['processed'],
		'success'   => (int) $state['success'],
		'skipped'   => (int) $state['skipped'],
		'errors'    => (int) $state['errors'],
		'remaining' => count( svtmg_bulk_get_queue() ),
		'message'   => (string) $state['message'],
	);
}

/* -------------------------------------------------------------------------
 * AJAX
 * ---------------------------------------------------------------------- */

function svtmg_ajax_bulk_start(): void {
	svtmg_ajax_check( 'manage_options' );

	if ( '' === svtmg_get_api_key() ) {
		wp_send_json_error( array( 'message' => __( 'Не задано API-ключ.', 'svt-meta-generator' ) ) );
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- перевірено в svtmg_ajax_check().
	$scope = isset( $_POST['scope'] ) ? sanitize_text_field( wp_unslash( $_POST['scope'] ) ) : 'all';
	$lang  = isset( $_POST['lang'] ) ? svtmg_normalize_lang( sanitize_key( wp_unslash( $_POST['lang'] ) ) ) : '';
	$limit = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 0;
	// phpcs:enable

	if ( ! preg_match( '/^(all|post_type:[a-z0-9_\-]+|taxonomy:[a-z0-9_\-]+)$/', $scope ) ) {
		$scope = 'all';
	}

	$overwrite = ! empty( svtmg_get_setting( 'overwrite' ) );
	$keys      = svtmg_bulk_find( $scope, $lang, $overwrite, $limit );
	if ( empty( $keys ) ) {
		wp_send_json_error( array( 'message' => __( 'Немає об\'єктів для обробки: усі вже мають опис.', 'svt-meta-generator' ) ) );
	}

	$added = svtmg_bulk_enqueue( $keys, $overwrite, true );
	svtmg_log( sprintf( 'Запущено масову генерацію: %d об\'єктів (обсяг: %s, мова: %s).', $added, $scope, '' !== $lang ? $lang : 'усі' ), 'info' );
	wp_send_json_success( svtmg_bulk_public_state() );
}
add_action( 'wp_ajax_svtmg_bulk_start', 'svtmg_ajax_bulk_start' );

function svtmg_ajax_bulk_status(): void {
	svtmg_ajax_check();
	svtmg_bulk_kick();
	wp_send_json_success( svtmg_bulk_public_state() );
}
add_action( 'wp_ajax_svtmg_bulk_status', 'svtmg_ajax_bulk_status' );

function svtmg_ajax_bulk_cancel(): void {
	svtmg_ajax_check( 'manage_options' );

	$state = svtmg_bulk_get_state();
	if ( 'running' === $state['status'] ) {
		$state['status'] = 'cancelled';
		svtmg_bulk_set_state( $state );
	}
	svtmg_bulk_set_queue( array() );
	wp_clear_scheduled_hook( 'svtmg_bulk_process' );
	wp_send_json_success( svtmg_bulk_public_state() );
}
add_action( 'wp_ajax_svtmg_bulk_cancel', 'svtmg_ajax_bulk_cancel' );

/* -------------------------------------------------------------------------
 * Групова дія у списку записів / товарів
 * ---------------------------------------------------------------------- */

function svtmg_register_bulk_actions(): void {
	foreach ( svtmg_get_enabled_post_types() as $pt ) {
		add_filter( "bulk_actions-edit-{$pt}", 'svtmg_add_bulk_action' );
		add_filter( "handle_bulk_actions-edit-{$pt}", 'svtmg_handle_post_bulk_action', 10, 3 );
	}
}
add_action( 'admin_init', 'svtmg_register_bulk_actions' );

function svtmg_add_bulk_action( array $actions ): array {
	if ( current_user_can( 'edit_posts' ) ) {
		$actions['svtmg_generate'] = __( 'Згенерувати опис', 'svt-meta-generator' );
	}
	return $actions;
}

/**
 * Nonce для групових дій перевіряє сам WordPress (edit.php / edit-tags.php).
 */
function svtmg_handle_post_bulk_action( string $redirect, string $action, array $ids ): string {
	if ( 'svtmg_generate' !== $action ) {
		return $redirect;
	}
	$keys = array();
	foreach ( $ids as $id ) {
		if ( current_user_can( 'edit_post', (int) $id ) ) {
			$keys[] = svtmg_key( 'post', (int) $id );
		}
	}
	return svtmg_bulk_action_redirect( $redirect, $keys );
}

function svtmg_bulk_action_redirect( string $redirect, array $keys ): string {
	$redirect = remove_query_arg( array( 'svtmg_queued', 'svtmg_bulk_error' ), $redirect );
	if ( ! current_user_can( 'edit_posts' ) || '' === svtmg_get_api_key() ) {
		return add_query_arg( 'svtmg_bulk_error', 1, $redirect );
	}
	$added = svtmg_bulk_enqueue( $keys, ! empty( svtmg_get_setting( 'overwrite' ) ) );
	return add_query_arg( 'svtmg_queued', $added, $redirect );
}

function svtmg_bulk_admin_notice(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['svtmg_bulk_error'] ) ) {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'SVT Meta Generator: не задано API-ключ або недостатньо прав.', 'svt-meta-generator' ) . '</p></div>';
	}
	if ( isset( $_GET['svtmg_queued'] ) ) {
		$msg = sprintf(
			/* translators: %d: number of objects */
			__( 'SVT Meta Generator: %d об\'єктів додано в чергу генерації. Прогрес: Налаштування → SVT Meta Generator.', 'svt-meta-generator' ),
			absint( $_GET['svtmg_queued'] )
		);
		if ( empty( svtmg_get_setting( 'overwrite' ) ) ) {
			$msg .= ' ' . __( 'Об\'єкти з уже заповненим (не шаблонним) описом буде пропущено, бо перезапис вимкнено.', 'svt-meta-generator' );
		}
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
	}
	// phpcs:enable
}
add_action( 'admin_notices', 'svtmg_bulk_admin_notice' );
