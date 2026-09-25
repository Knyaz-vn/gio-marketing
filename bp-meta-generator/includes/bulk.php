<?php
/**
 * Масова генерація: черга в опції, пачки по 5 через WP-Cron, прогрес через AJAX-опитування,
 * bulk action "Згенерувати опис" у списку записів.
 */

defined( 'ABSPATH' ) || exit;

function bpmg_bulk_default_state(): array {
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

function bpmg_bulk_get_state(): array {
	// Скидаємо кеш, щоб бачити "Зупинити", натиснуте в іншому запиті.
	wp_cache_delete( 'bpmg_bulk_state', 'options' );
	$state = get_option( 'bpmg_bulk_state', array() );
	return wp_parse_args( is_array( $state ) ? $state : array(), bpmg_bulk_default_state() );
}

function bpmg_bulk_set_state( array $state ): void {
	$state['updated_at'] = time();
	update_option( 'bpmg_bulk_state', $state, false );
}

function bpmg_bulk_get_queue(): array {
	wp_cache_delete( 'bpmg_bulk_queue', 'options' );
	return array_map( 'intval', (array) get_option( 'bpmg_bulk_queue', array() ) );
}

function bpmg_bulk_set_queue( array $queue ): void {
	update_option( 'bpmg_bulk_queue', array_values( $queue ), false );
}

function bpmg_bulk_batch_size(): int {
	return max( 1, (int) apply_filters( 'bpmg_bulk_batch_size', 5 ) );
}

/**
 * ID опублікованих записів вибраних типів (без опису, якщо перезапис вимкнено).
 *
 * @return int[]
 */
function bpmg_bulk_find_posts( bool $overwrite ): array {
	$types = bpmg_get_enabled_post_types();
	if ( empty( $types ) ) {
		return array();
	}

	$args = array(
		'post_type'              => $types,
		'post_status'            => 'publish',
		'fields'                 => 'ids',
		'posts_per_page'         => -1,
		'orderby'                => 'ID',
		'order'                  => 'ASC',
		'no_found_rows'          => true,
		'update_post_term_cache' => false,
	);

	if ( ! $overwrite ) {
		$key                = bpmg_get_meta_key();
		$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
			'relation' => 'OR',
			array(
				'key'     => $key,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => $key,
				'value'   => '',
				'compare' => '=',
			),
		);
	}

	return array_map( 'intval', get_posts( $args ) );
}

/**
 * Додати записи в чергу і запустити обробку.
 *
 * @param int[] $ids
 * @return int Кількість доданих.
 */
function bpmg_bulk_enqueue( array $ids, bool $overwrite, bool $reset = false ): int {
	$state = bpmg_bulk_get_state();
	$queue = $reset || 'running' !== $state['status'] ? array() : bpmg_bulk_get_queue();

	if ( $reset || 'running' !== $state['status'] ) {
		$state              = bpmg_bulk_default_state();
		$state['started_at'] = time();
	}

	$new   = array_values( array_diff( array_unique( array_map( 'intval', $ids ) ), $queue ) );
	$queue = array_merge( $queue, $new );

	if ( empty( $queue ) ) {
		return 0;
	}

	$state['status']     = 'running';
	$state['total']     += count( $new );
	$state['overwrite']  = (int) ( $overwrite || ! empty( $state['overwrite'] ) );
	$state['message']    = '';

	bpmg_bulk_set_queue( $queue );
	bpmg_bulk_set_state( $state );

	if ( ! wp_next_scheduled( 'bpmg_bulk_process' ) ) {
		wp_schedule_single_event( time(), 'bpmg_bulk_process' );
	}

	return count( $new );
}

/**
 * Обробити одну пачку (WP-Cron).
 */
function bpmg_bulk_process_batch(): void {
	if ( get_transient( 'bpmg_bulk_lock' ) ) {
		return;
	}
	set_transient( 'bpmg_bulk_lock', time(), 5 * MINUTE_IN_SECONDS );

	try {
		$state = bpmg_bulk_get_state();
		if ( 'running' !== $state['status'] ) {
			return;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$pause = max( 0, (int) apply_filters( 'bpmg_request_pause', 2 ) );
		$size  = bpmg_bulk_batch_size();

		for ( $i = 0; $i < $size; $i++ ) {
			$queue = bpmg_bulk_get_queue();
			$state = bpmg_bulk_get_state();
			if ( empty( $queue ) || 'running' !== $state['status'] ) {
				break;
			}

			$post_id = (int) array_shift( $queue );
			bpmg_bulk_set_queue( $queue );

			$result = bpmg_generate_for_post( $post_id, ! empty( $state['overwrite'] ) );

			$state = bpmg_bulk_get_state();
			$state['processed']++;
			if ( is_wp_error( $result ) ) {
				if ( 'bpmg_exists' === $result->get_error_code() ) {
					$state['skipped']++;
				} else {
					$state['errors']++;
					$state['message'] = sprintf( '#%d: %s', $post_id, $result->get_error_message() );
				}
			} else {
				$state['success']++;
			}
			bpmg_bulk_set_state( $state );

			if ( ! empty( $queue ) && $i < $size - 1 && $pause ) {
				sleep( $pause );
			}
		}

		$state = bpmg_bulk_get_state();
		if ( 'running' !== $state['status'] ) {
			return;
		}

		if ( empty( bpmg_bulk_get_queue() ) ) {
			$state['status'] = 'done';
			bpmg_bulk_set_state( $state );
			bpmg_log( sprintf( 'Масову генерацію завершено: успішно %d, пропущено %d, помилок %d.', $state['success'], $state['skipped'], $state['errors'] ), 'info' );
		} else {
			wp_schedule_single_event( time() + $pause, 'bpmg_bulk_process' );
		}
	} finally {
		delete_transient( 'bpmg_bulk_lock' );
	}
}
add_action( 'bpmg_bulk_process', 'bpmg_bulk_process_batch' );

/**
 * Якщо WP-Cron не спрацьовує (DISABLE_WP_CRON, мало відвідувачів) — обробляємо пачку прямо в запиті опитування.
 */
function bpmg_bulk_kick(): void {
	$state = bpmg_bulk_get_state();
	if ( 'running' !== $state['status'] || get_transient( 'bpmg_bulk_lock' ) ) {
		return;
	}

	$next = wp_next_scheduled( 'bpmg_bulk_process' );
	if ( ! $next ) {
		wp_schedule_single_event( time(), 'bpmg_bulk_process' );
		return;
	}
	if ( $next < time() - 30 && time() - (int) $state['updated_at'] > 30 ) {
		bpmg_bulk_process_batch();
	}
}

function bpmg_bulk_public_state(): array {
	$state = bpmg_bulk_get_state();
	return array(
		'status'    => $state['status'],
		'total'     => (int) $state['total'],
		'processed' => (int) $state['processed'],
		'success'   => (int) $state['success'],
		'skipped'   => (int) $state['skipped'],
		'errors'    => (int) $state['errors'],
		'remaining' => count( bpmg_bulk_get_queue() ),
		'message'   => (string) $state['message'],
	);
}

/* -------------------------------------------------------------------------
 * AJAX
 * ---------------------------------------------------------------------- */

function bpmg_ajax_bulk_start(): void {
	bpmg_ajax_check( 'manage_options' );

	if ( '' === bpmg_get_api_key() ) {
		wp_send_json_error( array( 'message' => __( 'Не задано API-ключ.', 'bp-meta-generator' ) ) );
	}

	$overwrite = ! empty( bpmg_get_setting( 'overwrite' ) );
	$ids       = bpmg_bulk_find_posts( $overwrite );
	if ( empty( $ids ) ) {
		wp_send_json_error( array( 'message' => __( 'Немає записів для обробки: усі вже мають опис.', 'bp-meta-generator' ) ) );
	}

	$added = bpmg_bulk_enqueue( $ids, $overwrite, true );
	bpmg_log( sprintf( 'Запущено масову генерацію: %d записів.', $added ), 'info' );
	wp_send_json_success( bpmg_bulk_public_state() );
}
add_action( 'wp_ajax_bpmg_bulk_start', 'bpmg_ajax_bulk_start' );

function bpmg_ajax_bulk_status(): void {
	bpmg_ajax_check();
	bpmg_bulk_kick();
	wp_send_json_success( bpmg_bulk_public_state() );
}
add_action( 'wp_ajax_bpmg_bulk_status', 'bpmg_ajax_bulk_status' );

function bpmg_ajax_bulk_cancel(): void {
	bpmg_ajax_check( 'manage_options' );

	$state = bpmg_bulk_get_state();
	if ( 'running' === $state['status'] ) {
		$state['status'] = 'cancelled';
		bpmg_bulk_set_state( $state );
	}
	bpmg_bulk_set_queue( array() );
	wp_clear_scheduled_hook( 'bpmg_bulk_process' );
	wp_send_json_success( bpmg_bulk_public_state() );
}
add_action( 'wp_ajax_bpmg_bulk_cancel', 'bpmg_ajax_bulk_cancel' );

/* -------------------------------------------------------------------------
 * Bulk action у списку записів
 * ---------------------------------------------------------------------- */

function bpmg_register_bulk_actions(): void {
	foreach ( bpmg_get_enabled_post_types() as $pt ) {
		add_filter( "bulk_actions-edit-{$pt}", 'bpmg_add_bulk_action' );
		add_filter( "handle_bulk_actions-edit-{$pt}", 'bpmg_handle_bulk_action', 10, 3 );
	}
}
add_action( 'admin_init', 'bpmg_register_bulk_actions' );

function bpmg_add_bulk_action( array $actions ): array {
	if ( current_user_can( 'edit_posts' ) ) {
		$actions['bpmg_generate'] = __( 'Згенерувати опис', 'bp-meta-generator' );
	}
	return $actions;
}

/**
 * Nonce для bulk action перевіряє сам WordPress (check_admin_referer('bulk-posts') у edit.php).
 */
function bpmg_handle_bulk_action( string $redirect, string $action, array $post_ids ): string {
	if ( 'bpmg_generate' !== $action ) {
		return $redirect;
	}

	$redirect = remove_query_arg( array( 'bpmg_queued', 'bpmg_bulk_error' ), $redirect );

	if ( ! current_user_can( 'edit_posts' ) || '' === bpmg_get_api_key() ) {
		return add_query_arg( 'bpmg_bulk_error', 1, $redirect );
	}

	$ids = array();
	foreach ( $post_ids as $id ) {
		$id = (int) $id;
		if ( current_user_can( 'edit_post', $id ) ) {
			$ids[] = $id;
		}
	}

	$added = bpmg_bulk_enqueue( $ids, ! empty( bpmg_get_setting( 'overwrite' ) ) );
	return add_query_arg( 'bpmg_queued', $added, $redirect );
}

function bpmg_bulk_admin_notice(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['bpmg_bulk_error'] ) ) {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'BP Meta Generator: не задано API-ключ або недостатньо прав.', 'bp-meta-generator' ) . '</p></div>';
	}
	if ( isset( $_GET['bpmg_queued'] ) ) {
		$n = absint( $_GET['bpmg_queued'] );
		$msg = sprintf(
			/* translators: %d: number of posts */
			__( 'BP Meta Generator: %d записів додано в чергу генерації. Прогрес — у Налаштування → BP Meta Generator.', 'bp-meta-generator' ),
			$n
		);
		if ( empty( bpmg_get_setting( 'overwrite' ) ) ) {
			$msg .= ' ' . __( 'Записи, що вже мають опис, буде пропущено (перезапис вимкнено).', 'bp-meta-generator' );
		}
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
	}
	// phpcs:enable
}
add_action( 'admin_notices', 'bpmg_bulk_admin_notice' );
