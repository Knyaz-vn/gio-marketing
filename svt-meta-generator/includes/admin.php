<?php
/**
 * Адмінка для записів/товарів: assets, метабокс, AJAX-генерація, колонка у списку.
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Assets
 * ---------------------------------------------------------------------- */

function svtmg_admin_assets( string $hook ): void {
	$screen = get_current_screen();
	if ( ! $screen ) {
		return;
	}

	$is_settings = 'settings_page_svtmg-settings' === $hook;
	$is_post     = in_array( $hook, array( 'post.php', 'post-new.php', 'edit.php' ), true ) && svtmg_is_post_type_enabled( (string) $screen->post_type );
	$is_term     = in_array( $hook, array( 'term.php', 'edit-tags.php' ), true ) && svtmg_is_taxonomy_enabled( (string) $screen->taxonomy );

	if ( ! $is_settings && ! $is_post && ! $is_term ) {
		return;
	}

	wp_enqueue_style( 'svtmg-admin', SVTMG_URL . 'assets/admin.css', array(), SVTMG_VERSION );

	if ( in_array( $hook, array( 'edit.php', 'edit-tags.php' ), true ) ) {
		return; // У списках лише стилі колонки.
	}

	wp_enqueue_script( 'svtmg-admin', SVTMG_URL . 'assets/admin.js', array( 'jquery' ), SVTMG_VERSION, true );
	wp_localize_script(
		'svtmg-admin',
		'svtmgData',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'svtmg_ajax' ),
			'min'     => (int) svtmg_get_setting( 'min_len' ),
			'max'     => (int) svtmg_get_setting( 'max_len' ),
			'seo'     => svtmg_get_seo_plugin(),
			'i18n'    => array(
				'generating'   => __( 'Генерація… це може зайняти до 30 секунд.', 'svt-meta-generator' ),
				'done'         => __( 'Готово, опис збережено.', 'svt-meta-generator' ),
				'error'        => __( 'Помилка:', 'svt-meta-generator' ),
				'confirmOver'  => __( 'Опис уже заповнений. Замінити його новим?', 'svt-meta-generator' ),
				'testing'      => __( 'Перевірка…', 'svt-meta-generator' ),
				'confirmBulk'  => __( 'Запустити масову генерацію? Кожен об\'єкт — окремий платний запит до API.', 'svt-meta-generator' ),
				'confirmClear' => __( 'Очистити лог?', 'svt-meta-generator' ),
				'statusRun'    => __( 'Обробка: %1$d з %2$d (успішно %3$d, пропущено %4$d, помилок %5$d)', 'svt-meta-generator' ),
				'statusDone'   => __( 'Завершено: %1$d з %2$d (успішно %3$d, пропущено %4$d, помилок %5$d)', 'svt-meta-generator' ),
				'statusCancel' => __( 'Зупинено: оброблено %1$d з %2$d (успішно %3$d, пропущено %4$d, помилок %5$d)', 'svt-meta-generator' ),
				'lastError'    => __( 'Остання помилка:', 'svt-meta-generator' ),
			),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'svtmg_admin_assets' );

/* -------------------------------------------------------------------------
 * Спільний блок поля опису (метабокс запису і форма терміна)
 * ---------------------------------------------------------------------- */

function svtmg_render_field( string $type, int $id ): void {
	$desc = svtmg_get_description( $type, $id );
	$min  = (int) svtmg_get_setting( 'min_len' );
	$max  = (int) svtmg_get_setting( 'max_len' );
	$lang = svtmg_get_object_language( $type, $id );

	wp_nonce_field( 'svtmg_save_field', 'svtmg_field_nonce' );
	?>
	<div id="svtmg-box" data-object="<?php echo esc_attr( svtmg_key( $type, $id ) ); ?>">
		<textarea id="svtmg-description" name="svtmg_description" rows="5" class="widefat"><?php echo esc_textarea( $desc ); ?></textarea>
		<input type="hidden" name="svtmg_description_original" value="<?php echo esc_attr( $desc ); ?>">
		<p class="svtmg-counter">
			<span id="svtmg-count">0</span> / <?php echo esc_html( $min . '–' . $max ); ?> <?php esc_html_e( 'символів', 'svt-meta-generator' ); ?>
			<span class="svtmg-lang"><?php echo esc_html( strtoupper( $lang ) ); ?></span>
			<?php if ( '' !== $desc && svtmg_is_replaceable( $desc ) ) : ?>
				<span class="svtmg-template-badge"><?php esc_html_e( 'шаблон', 'svt-meta-generator' ); ?></span>
			<?php endif; ?>
		</p>
		<p class="svtmg-buttons">
			<button type="button" class="button button-primary" data-svtmg-mode="generate"><?php esc_html_e( 'Згенерувати', 'svt-meta-generator' ); ?></button>
			<button type="button" class="button" data-svtmg-mode="regenerate"><?php esc_html_e( 'Перегенерувати', 'svt-meta-generator' ); ?></button>
		</p>
		<p class="svtmg-status" id="svtmg-status" aria-live="polite"></p>
		<?php if ( '' === svtmg_get_api_key() ) : ?>
			<p class="description"><?php esc_html_e( 'API-ключ не задано.', 'svt-meta-generator' ); ?></p>
		<?php endif; ?>
		<p class="description">
			<?php
			$seo = svtmg_get_seo_plugin();
			echo esc_html(
				'none' === $seo
					? __( 'Опис виводиться плагіном у <head>.', 'svt-meta-generator' )
					: sprintf( __( 'Опис зберігається в полі %s.', 'svt-meta-generator' ), 'yoast' === $seo ? 'Yoast SEO' : 'Rank Math' )
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Значення поля з форми, якщо його змінили (null — не змінювали або форма не наша).
 */
function svtmg_submitted_field_value(): ?string {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- перевіряється тут же.
	if ( ! isset( $_POST['svtmg_field_nonce'], $_POST['svtmg_description'] )
		|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['svtmg_field_nonce'] ) ), 'svtmg_save_field' ) ) {
		return null;
	}
	$value    = svtmg_normalize_manual( sanitize_textarea_field( wp_unslash( $_POST['svtmg_description'] ) ) );
	$original = isset( $_POST['svtmg_description_original'] ) ? svtmg_normalize_manual( sanitize_textarea_field( wp_unslash( $_POST['svtmg_description_original'] ) ) ) : '';
	// phpcs:enable
	return $value === $original ? null : $value;
}

function svtmg_normalize_manual( string $text ): string {
	return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
}

/* -------------------------------------------------------------------------
 * Метабокс записів / товарів
 * ---------------------------------------------------------------------- */

function svtmg_add_metabox(): void {
	foreach ( svtmg_get_enabled_post_types() as $pt ) {
		add_meta_box( 'svtmg_metabox', __( 'Meta description (SVT)', 'svt-meta-generator' ), 'svtmg_render_metabox', $pt, 'side', 'high' );
	}
}
add_action( 'add_meta_boxes', 'svtmg_add_metabox' );

function svtmg_render_metabox( WP_Post $post ): void {
	svtmg_render_field( 'post', $post->ID );
}

/**
 * Збереження ручних правок. wp_insert_post з пріоритетом 99 — після Yoast/Rank Math,
 * щоб їхнє (можливо застаріле) поле не перезаписало наше. Зберігаємо лише змінене значення.
 */
function svtmg_save_metabox( int $post_id, WP_Post $post ): void {
	// Лише запис, якому належить форма: WooCommerce зберігає варіації (теж wp_insert_post) раніше за сам товар.
	$form_post_id = isset( $_POST['post_ID'] ) ? absint( $_POST['post_ID'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce нижче.
	if ( $form_post_id !== $post_id
		|| ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
		|| wp_is_post_revision( $post_id )
		|| ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$value = svtmg_submitted_field_value();
	if ( null === $value ) {
		return;
	}
	unset( $_POST['svtmg_field_nonce'] ); // Щоб не зберегти вдруге при вкладених wp_update_post.
	svtmg_save_description( 'post', $post_id, $value );
}
add_action( 'wp_insert_post', 'svtmg_save_metabox', 99, 2 );

/* -------------------------------------------------------------------------
 * AJAX: згенерувати з метабоксу / форми терміна
 * ---------------------------------------------------------------------- */

function svtmg_ajax_generate(): void {
	svtmg_ajax_check();

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- перевірено в svtmg_ajax_check().
	$parsed = svtmg_parse_key( isset( $_POST['object'] ) ? sanitize_text_field( wp_unslash( $_POST['object'] ) ) : '' );
	$mode   = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'generate';

	if ( ! $parsed ) {
		wp_send_json_error( array( 'message' => __( 'Невідомий об\'єкт.', 'svt-meta-generator' ) ), 400 );
	}
	list( $type, $id ) = $parsed;

	$allowed = 'term' === $type ? current_user_can( 'edit_term', $id ) : current_user_can( 'edit_post', $id );
	if ( ! $allowed ) {
		wp_send_json_error( array( 'message' => __( 'Недостатньо прав для цього об\'єкта.', 'svt-meta-generator' ) ), 403 );
	}

	// Незбережений вміст редактора (генеруємо з актуального тексту ще до збереження).
	$override = array();
	if ( isset( $_POST['title'] ) ) {
		$override['title'] = sanitize_text_field( wp_unslash( $_POST['title'] ) );
	}
	if ( isset( $_POST['content'] ) ) {
		$override['content'] = mb_substr( wp_kses_post( wp_unslash( $_POST['content'] ) ), 0, 200000 );
	}
	// phpcs:enable

	$result = svtmg_generate( $type, $id, 'regenerate' === $mode, $override );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ) );
	}
	wp_send_json_success( array( 'description' => $result, 'length' => mb_strlen( $result ) ) );
}
add_action( 'wp_ajax_svtmg_generate', 'svtmg_ajax_generate' );

/* -------------------------------------------------------------------------
 * Колонка "Meta description"
 * ---------------------------------------------------------------------- */

function svtmg_register_post_columns(): void {
	foreach ( svtmg_get_enabled_post_types() as $pt ) {
		add_filter( "manage_{$pt}_posts_columns", 'svtmg_add_column' );
		add_action( "manage_{$pt}_posts_custom_column", 'svtmg_render_post_column', 10, 2 );
	}
}
add_action( 'admin_init', 'svtmg_register_post_columns' );

function svtmg_add_column( array $columns ): array {
	$new = array();
	foreach ( $columns as $key => $label ) {
		$new[ $key ] = $label;
		if ( in_array( $key, array( 'title', 'name' ), true ) ) {
			$new['svtmg_meta'] = __( 'Meta description', 'svt-meta-generator' );
		}
	}
	if ( ! isset( $new['svtmg_meta'] ) ) {
		$new['svtmg_meta'] = __( 'Meta description', 'svt-meta-generator' );
	}
	return $new;
}

function svtmg_render_post_column( string $column, int $post_id ): void {
	if ( 'svtmg_meta' === $column ) {
		echo svtmg_column_html( svtmg_get_description( 'post', $post_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
	}
}

function svtmg_column_html( string $desc ): string {
	if ( '' === $desc ) {
		return '<span class="svtmg-col-empty">' . esc_html__( 'немає', 'svt-meta-generator' ) . '</span>';
	}

	$len      = mb_strlen( $desc );
	$ok       = $len >= (int) svtmg_get_setting( 'min_len' ) && $len <= (int) svtmg_get_setting( 'max_len' );
	$template = svtmg_is_replaceable( $desc );

	return '<div class="svtmg-col-text">' . esc_html( $desc ) . '</div>'
		. '<span class="svtmg-col-len ' . ( $ok ? 'is-ok' : 'is-warn' ) . '">' . esc_html( (string) $len ) . '</span>'
		. ( $template ? ' <span class="svtmg-template-badge">' . esc_html__( 'шаблон', 'svt-meta-generator' ) . '</span>' : '' );
}
