<?php
/**
 * Адмінка: метабокс у редакторі, AJAX-генерація, колонка у списку записів, підключення assets.
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Assets
 * ---------------------------------------------------------------------- */

function bpmg_admin_assets( string $hook ): void {
	$screen      = get_current_screen();
	$is_settings = 'settings_page_bpmg-settings' === $hook;
	$is_editor   = in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && $screen && bpmg_is_post_type_enabled( (string) $screen->post_type );
	$is_list     = 'edit.php' === $hook && $screen && bpmg_is_post_type_enabled( (string) $screen->post_type );

	if ( ! $is_settings && ! $is_editor && ! $is_list ) {
		return;
	}

	wp_enqueue_style( 'bpmg-admin', BPMG_URL . 'assets/admin.css', array(), BPMG_VERSION );

	if ( $is_list ) {
		return;
	}

	wp_enqueue_script( 'bpmg-admin', BPMG_URL . 'assets/admin.js', array( 'jquery' ), BPMG_VERSION, true );
	wp_localize_script(
		'bpmg-admin',
		'bpmgData',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'bpmg_ajax' ),
			'min'     => (int) bpmg_get_setting( 'min_len' ),
			'max'     => (int) bpmg_get_setting( 'max_len' ),
			'seo'     => bpmg_get_seo_plugin(),
			'i18n'    => array(
				'generating'   => __( 'Генерація… це може зайняти до 30 секунд.', 'bp-meta-generator' ),
				'done'         => __( 'Готово, опис збережено.', 'bp-meta-generator' ),
				'error'        => __( 'Помилка:', 'bp-meta-generator' ),
				'confirmOver'  => __( 'Поле вже заповнене. Замінити опис новим?', 'bp-meta-generator' ),
				'chars'        => __( 'символів', 'bp-meta-generator' ),
				'testing'      => __( 'Перевірка…', 'bp-meta-generator' ),
				'confirmBulk'  => __( 'Запустити масову генерацію? Кожен запис — окремий платний запит до API.', 'bp-meta-generator' ),
				'confirmClear' => __( 'Очистити лог?', 'bp-meta-generator' ),
				'statusRun'    => __( 'Обробка: %1$d з %2$d (успішно %3$d, пропущено %4$d, помилок %5$d)', 'bp-meta-generator' ),
				'statusDone'   => __( 'Завершено: %1$d з %2$d (успішно %3$d, пропущено %4$d, помилок %5$d)', 'bp-meta-generator' ),
				'statusCancel' => __( 'Зупинено: оброблено %1$d з %2$d (успішно %3$d, пропущено %4$d, помилок %5$d)', 'bp-meta-generator' ),
				'lastError'    => __( 'Остання помилка:', 'bp-meta-generator' ),
			),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'bpmg_admin_assets' );

/* -------------------------------------------------------------------------
 * Метабокс
 * ---------------------------------------------------------------------- */

function bpmg_add_metabox(): void {
	foreach ( bpmg_get_enabled_post_types() as $pt ) {
		add_meta_box( 'bpmg_metabox', __( 'Meta description (BP)', 'bp-meta-generator' ), 'bpmg_render_metabox', $pt, 'side', 'high' );
	}
}
add_action( 'add_meta_boxes', 'bpmg_add_metabox' );

function bpmg_render_metabox( WP_Post $post ): void {
	$desc = bpmg_get_description( $post->ID );
	$min  = (int) bpmg_get_setting( 'min_len' );
	$max  = (int) bpmg_get_setting( 'max_len' );

	wp_nonce_field( 'bpmg_save_metabox', 'bpmg_metabox_nonce' );
	?>
	<div id="bpmg-metabox" data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>">
		<textarea id="bpmg-description" name="bpmg_description" rows="5" class="widefat"><?php echo esc_textarea( $desc ); ?></textarea>
		<input type="hidden" name="bpmg_description_original" value="<?php echo esc_attr( $desc ); ?>">
		<p class="bpmg-counter">
			<span id="bpmg-count">0</span> / <?php echo esc_html( $min . '–' . $max ); ?> <?php esc_html_e( 'символів', 'bp-meta-generator' ); ?>
		</p>
		<p class="bpmg-buttons">
			<button type="button" class="button button-primary" data-bpmg-mode="generate"><?php esc_html_e( 'Згенерувати', 'bp-meta-generator' ); ?></button>
			<button type="button" class="button" data-bpmg-mode="regenerate"><?php esc_html_e( 'Перегенерувати', 'bp-meta-generator' ); ?></button>
		</p>
		<p class="bpmg-status" id="bpmg-status" aria-live="polite"></p>
		<?php if ( '' === bpmg_get_api_key() ) : ?>
			<p class="description"><?php esc_html_e( 'API-ключ не задано.', 'bp-meta-generator' ); ?></p>
		<?php endif; ?>
		<p class="description">
			<?php
			echo esc_html(
				'none' === bpmg_get_seo_plugin()
					? __( 'Опис виводиться плагіном у <head>.', 'bp-meta-generator' )
					: sprintf( __( 'Опис синхронізується з полем %s.', 'bp-meta-generator' ), 'yoast' === bpmg_get_seo_plugin() ? 'Yoast SEO' : 'Rank Math' )
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Збереження ручних правок. Висить на wp_insert_post з пріоритетом 99 —
 * після Yoast/Rank Math, щоб їхнє (можливо застаріле) поле не перезаписало наше.
 * Зберігаємо лише якщо значення змінили в нашому полі.
 */
function bpmg_save_metabox( int $post_id, WP_Post $post ): void {
	if ( ! isset( $_POST['bpmg_metabox_nonce'] )
		|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bpmg_metabox_nonce'] ) ), 'bpmg_save_metabox' )
		|| ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
		|| wp_is_post_revision( $post_id )
		|| ! current_user_can( 'edit_post', $post_id )
		|| ! isset( $_POST['bpmg_description'] ) ) {
		return;
	}

	$value    = bpmg_normalize_manual( sanitize_textarea_field( wp_unslash( $_POST['bpmg_description'] ) ) );
	$original = isset( $_POST['bpmg_description_original'] ) ? bpmg_normalize_manual( sanitize_textarea_field( wp_unslash( $_POST['bpmg_description_original'] ) ) ) : '';

	if ( $value === $original ) {
		return;
	}

	// Щоб не зациклитися, якщо хтось викличе wp_update_post у хуку збереження.
	unset( $_POST['bpmg_metabox_nonce'] );
	bpmg_save_description( $post_id, $value );
}
add_action( 'wp_insert_post', 'bpmg_save_metabox', 99, 2 );

function bpmg_normalize_manual( string $text ): string {
	return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
}

/**
 * AJAX: згенерувати опис з метабоксу.
 */
function bpmg_ajax_generate(): void {
	bpmg_ajax_check();

	$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
	$mode    = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'generate';

	if ( ! $post_id || ! get_post( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
		wp_send_json_error( array( 'message' => __( 'Недостатньо прав для цього запису.', 'bp-meta-generator' ) ), 403 );
	}

	// Незбережений вміст редактора (щоб генерувати з актуального тексту ще до натискання "Оновити").
	$override = array();
	if ( isset( $_POST['title'] ) ) {
		$override['title'] = sanitize_text_field( wp_unslash( $_POST['title'] ) );
	}
	if ( isset( $_POST['content'] ) ) {
		$override['content'] = mb_substr( wp_kses_post( wp_unslash( $_POST['content'] ) ), 0, 200000 );
	}

	$result = bpmg_generate_for_post( $post_id, 'regenerate' === $mode, $override );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ) );
	}

	wp_send_json_success(
		array(
			'description' => $result,
			'length'      => mb_strlen( $result ),
		)
	);
}
add_action( 'wp_ajax_bpmg_generate', 'bpmg_ajax_generate' );

/* -------------------------------------------------------------------------
 * Колонка "Meta description" у списку записів
 * ---------------------------------------------------------------------- */

function bpmg_register_columns(): void {
	foreach ( bpmg_get_enabled_post_types() as $pt ) {
		add_filter( "manage_{$pt}_posts_columns", 'bpmg_add_column' );
		add_action( "manage_{$pt}_posts_custom_column", 'bpmg_render_column', 10, 2 );
	}
}
add_action( 'admin_init', 'bpmg_register_columns' );

function bpmg_add_column( array $columns ): array {
	$new = array();
	foreach ( $columns as $key => $label ) {
		$new[ $key ] = $label;
		if ( 'title' === $key ) {
			$new['bpmg_meta'] = __( 'Meta description', 'bp-meta-generator' );
		}
	}
	if ( ! isset( $new['bpmg_meta'] ) ) {
		$new['bpmg_meta'] = __( 'Meta description', 'bp-meta-generator' );
	}
	return $new;
}

function bpmg_render_column( string $column, int $post_id ): void {
	if ( 'bpmg_meta' !== $column ) {
		return;
	}

	$desc = bpmg_get_description( $post_id );
	if ( '' === $desc ) {
		echo '<span class="bpmg-col-empty">' . esc_html__( 'немає', 'bp-meta-generator' ) . '</span>';
		return;
	}

	$len = mb_strlen( $desc );
	$ok  = $len >= (int) bpmg_get_setting( 'min_len' ) && $len <= (int) bpmg_get_setting( 'max_len' );

	echo '<div class="bpmg-col-text">' . esc_html( $desc ) . '</div>';
	echo '<span class="bpmg-col-len ' . ( $ok ? 'is-ok' : 'is-warn' ) . '">' . esc_html( (string) $len ) . '</span>';
}
