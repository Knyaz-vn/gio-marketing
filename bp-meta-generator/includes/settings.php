<?php
/**
 * Налаштування плагіна: опції, сторінка "Налаштування → BP Meta Generator".
 */

defined( 'ABSPATH' ) || exit;

function bpmg_default_system_prompt(): string {
	return "Ти пишеш meta description українською для сторінки приватної медичної клініки у Вінниці. Довжина від 140 до 160 символів з пробілами. Включи головну послугу або тему сторінки і природно згадай Вінницю, якщо це сторінка послуги чи лікаря. Заверши м'яким закликом (записатися, дізнатися, отримати консультацію). Заборонено: гарантії результату, слова 'найкращий', 'стовідсотково', 'вилікуємо', діагнози як обіцянки, довге тире, лапки-ялинки, емодзі, CAPS. Поверни тільки текст опису без пояснень.";
}

function bpmg_defaults(): array {
	return array(
		'api_key'       => '',
		'model'         => BPMG_DEFAULT_MODEL,
		'min_len'       => 140,
		'max_len'       => 160,
		'post_types'    => array( 'post', 'page' ),
		'system_prompt' => bpmg_default_system_prompt(),
		'auto_generate' => 1,
		'overwrite'     => 0,
	);
}

function bpmg_get_settings(): array {
	$saved = get_option( 'bpmg_settings', array() );
	return wp_parse_args( is_array( $saved ) ? $saved : array(), bpmg_defaults() );
}

/**
 * @return mixed
 */
function bpmg_get_setting( string $key ) {
	$settings = bpmg_get_settings();
	return $settings[ $key ] ?? null;
}

function bpmg_is_api_key_from_constant(): bool {
	return defined( 'BPMG_API_KEY' ) && is_string( BPMG_API_KEY ) && '' !== trim( BPMG_API_KEY );
}

/**
 * Константа BPMG_API_KEY у wp-config.php має пріоритет над ключем з налаштувань.
 */
function bpmg_get_api_key(): string {
	if ( bpmg_is_api_key_from_constant() ) {
		return trim( BPMG_API_KEY );
	}
	return trim( (string) bpmg_get_setting( 'api_key' ) );
}

function bpmg_get_model(): string {
	$model = trim( (string) bpmg_get_setting( 'model' ) );
	return '' !== $model ? $model : BPMG_DEFAULT_MODEL;
}

/**
 * Публічні типи записів, доступні для вибору.
 *
 * @return array<string, WP_Post_Type>
 */
function bpmg_get_available_post_types(): array {
	$types = get_post_types( array( 'public' => true ), 'objects' );
	unset( $types['attachment'], $types['elementor_library'], $types['e-landing-page'] );
	return $types;
}

/**
 * Типи записів, увімкнені в налаштуваннях.
 *
 * @return string[]
 */
function bpmg_get_enabled_post_types(): array {
	$enabled = (array) bpmg_get_setting( 'post_types' );
	return array_values( array_intersect( $enabled, array_keys( bpmg_get_available_post_types() ) ) );
}

function bpmg_is_post_type_enabled( string $post_type ): bool {
	return in_array( $post_type, bpmg_get_enabled_post_types(), true );
}

/* -------------------------------------------------------------------------
 * Реєстрація налаштувань
 * ---------------------------------------------------------------------- */

function bpmg_register_settings(): void {
	register_setting(
		'bpmg_settings_group',
		'bpmg_settings',
		array(
			'type'              => 'array',
			'sanitize_callback' => 'bpmg_sanitize_settings',
			'default'           => bpmg_defaults(),
		)
	);
}
add_action( 'admin_init', 'bpmg_register_settings' );

function bpmg_sanitize_settings( $input ): array {
	$old      = bpmg_get_settings();
	$defaults = bpmg_defaults();
	$input    = is_array( $input ) ? $input : array();
	$out      = array();

	// API-ключ: порожнє поле = залишити попередній, галочка = видалити.
	$new_key = isset( $input['api_key'] ) ? trim( sanitize_text_field( $input['api_key'] ) ) : '';
	if ( ! empty( $input['api_key_clear'] ) ) {
		$out['api_key'] = '';
	} elseif ( '' !== $new_key ) {
		$out['api_key'] = $new_key;
	} else {
		$out['api_key'] = (string) $old['api_key'];
	}

	$model        = isset( $input['model'] ) ? preg_replace( '/[^A-Za-z0-9._:\-@]/', '', (string) $input['model'] ) : '';
	$out['model'] = '' !== $model ? $model : $defaults['model'];

	$min = isset( $input['min_len'] ) ? absint( $input['min_len'] ) : $defaults['min_len'];
	$max = isset( $input['max_len'] ) ? absint( $input['max_len'] ) : $defaults['max_len'];
	$min = max( 50, min( 300, $min ) );
	$max = max( 50, min( 320, $max ) );
	if ( $min > $max ) {
		list( $min, $max ) = array( $max, $min );
	}
	$out['min_len'] = $min;
	$out['max_len'] = $max;

	$available         = array_keys( bpmg_get_available_post_types() );
	$types             = isset( $input['post_types'] ) ? array_map( 'sanitize_key', (array) $input['post_types'] ) : array();
	$out['post_types'] = array_values( array_intersect( $types, $available ) );

	$prompt = isset( $input['system_prompt'] ) ? trim( sanitize_textarea_field( $input['system_prompt'] ) ) : '';
	if ( ! empty( $input['system_prompt_reset'] ) || '' === $prompt ) {
		$prompt = bpmg_default_system_prompt();
	}
	$out['system_prompt'] = $prompt;

	$out['auto_generate'] = empty( $input['auto_generate'] ) ? 0 : 1;
	$out['overwrite']     = empty( $input['overwrite'] ) ? 0 : 1;

	return $out;
}

/* -------------------------------------------------------------------------
 * Сторінка налаштувань
 * ---------------------------------------------------------------------- */

function bpmg_admin_menu(): void {
	add_options_page(
		__( 'BP Meta Generator', 'bp-meta-generator' ),
		__( 'BP Meta Generator', 'bp-meta-generator' ),
		'manage_options',
		'bpmg-settings',
		'bpmg_render_settings_page'
	);
}
add_action( 'admin_menu', 'bpmg_admin_menu' );

function bpmg_seo_target_label(): string {
	switch ( bpmg_get_seo_plugin() ) {
		case 'yoast':
			return 'Yoast SEO (_yoast_wpseo_metadesc) — власний тег не виводиться';
		case 'rankmath':
			return 'Rank Math (rank_math_description) — власний тег не виводиться';
		default:
			return 'Власне поле (_bpmg_meta_description) — плагін виводить meta description і og:description';
	}
}

function bpmg_render_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$s          = bpmg_get_settings();
	$from_const = bpmg_is_api_key_from_constant();
	$has_key    = '' !== bpmg_get_api_key();
	$available  = bpmg_get_available_post_types();
	$state      = bpmg_bulk_get_state();
	$log        = bpmg_get_log( 50 );
	?>
	<div class="wrap bpmg-wrap">
		<h1><?php esc_html_e( 'BP Meta Generator', 'bp-meta-generator' ); ?></h1>

		<?php if ( ! $has_key ) : ?>
			<div class="notice notice-warning"><p><?php esc_html_e( 'Вкажіть API-ключ Anthropic, щоб почати генерацію.', 'bp-meta-generator' ); ?></p></div>
		<?php endif; ?>

		<p><strong><?php esc_html_e( 'Куди зберігаються описи:', 'bp-meta-generator' ); ?></strong> <?php echo esc_html( bpmg_seo_target_label() ); ?></p>

		<form method="post" action="options.php">
			<?php settings_fields( 'bpmg_settings_group' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="bpmg_api_key"><?php esc_html_e( 'API-ключ Anthropic', 'bp-meta-generator' ); ?></label></th>
					<td>
						<?php if ( $from_const ) : ?>
							<input type="password" id="bpmg_api_key" class="regular-text" value="********" disabled>
							<p class="description"><?php esc_html_e( 'Ключ задано константою BPMG_API_KEY у wp-config.php (має пріоритет).', 'bp-meta-generator' ); ?></p>
						<?php else : ?>
							<input type="password" id="bpmg_api_key" name="bpmg_settings[api_key]" class="regular-text" value="" autocomplete="new-password"
								placeholder="<?php echo esc_attr( $has_key ? __( 'Ключ збережено — залиште порожнім, щоб не змінювати', 'bp-meta-generator' ) : 'sk-ant-...' ); ?>">
							<?php if ( $has_key ) : ?>
								<label><input type="checkbox" name="bpmg_settings[api_key_clear]" value="1"> <?php esc_html_e( 'Видалити збережений ключ', 'bp-meta-generator' ); ?></label>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'Безпечніше: додайте в wp-config.php рядок define( \'BPMG_API_KEY\', \'sk-ant-...\' );', 'bp-meta-generator' ); ?></p>
						<?php endif; ?>
						<p>
							<button type="button" class="button" id="bpmg-test-connection" <?php disabled( ! $has_key ); ?>><?php esc_html_e( 'Перевірити з\'єднання', 'bp-meta-generator' ); ?></button>
							<span class="bpmg-inline-status" id="bpmg-test-status"></span>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bpmg_model"><?php esc_html_e( 'Модель', 'bp-meta-generator' ); ?></label></th>
					<td>
						<input type="text" id="bpmg_model" name="bpmg_settings[model]" class="regular-text" value="<?php echo esc_attr( $s['model'] ); ?>">
						<p class="description"><?php echo esc_html( sprintf( __( 'За замовчуванням: %s', 'bp-meta-generator' ), BPMG_DEFAULT_MODEL ) ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Довжина опису (символів)', 'bp-meta-generator' ); ?></th>
					<td>
						<label><?php esc_html_e( 'мін.', 'bp-meta-generator' ); ?> <input type="number" name="bpmg_settings[min_len]" min="50" max="300" class="small-text" value="<?php echo esc_attr( (string) $s['min_len'] ); ?>"></label>
						&nbsp;
						<label><?php esc_html_e( 'макс.', 'bp-meta-generator' ); ?> <input type="number" name="bpmg_settings[max_len]" min="50" max="320" class="small-text" value="<?php echo esc_attr( (string) $s['max_len'] ); ?>"></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Типи записів', 'bp-meta-generator' ); ?></th>
					<td>
						<fieldset>
							<?php foreach ( $available as $name => $obj ) : ?>
								<label style="display:block;margin-bottom:4px">
									<input type="checkbox" name="bpmg_settings[post_types][]" value="<?php echo esc_attr( $name ); ?>" <?php checked( in_array( $name, (array) $s['post_types'], true ) ); ?>>
									<?php echo esc_html( $obj->labels->name ); ?> <code><?php echo esc_html( $name ); ?></code>
								</label>
							<?php endforeach; ?>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bpmg_system_prompt"><?php esc_html_e( 'Системний промпт', 'bp-meta-generator' ); ?></label></th>
					<td>
						<textarea id="bpmg_system_prompt" name="bpmg_settings[system_prompt]" rows="8" class="large-text"><?php echo esc_textarea( $s['system_prompt'] ); ?></textarea>
						<label><input type="checkbox" name="bpmg_settings[system_prompt_reset]" value="1"> <?php esc_html_e( 'Скинути до стандартного промпту', 'bp-meta-generator' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Автоматизація', 'bp-meta-generator' ); ?></th>
					<td>
						<label style="display:block;margin-bottom:6px">
							<input type="checkbox" name="bpmg_settings[auto_generate]" value="1" <?php checked( ! empty( $s['auto_generate'] ) ); ?>>
							<?php esc_html_e( 'Генерувати автоматично при публікації, якщо опис порожній', 'bp-meta-generator' ); ?>
						</label>
						<label style="display:block">
							<input type="checkbox" name="bpmg_settings[overwrite]" value="1" <?php checked( ! empty( $s['overwrite'] ) ); ?>>
							<?php esc_html_e( 'Перезаписувати існуючі описи (масова генерація і bulk action)', 'bp-meta-generator' ); ?>
						</label>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<hr>

		<h2><?php esc_html_e( 'Масова генерація', 'bp-meta-generator' ); ?></h2>
		<p class="description">
			<?php
			echo esc_html(
				! empty( $s['overwrite'] )
					? __( 'Увімкнено перезапис: будуть оброблені ВСІ опубліковані записи вибраних типів.', 'bp-meta-generator' )
					: __( 'Будуть оброблені опубліковані записи вибраних типів, у яких опис порожній.', 'bp-meta-generator' )
			);
			?>
			<?php esc_html_e( 'Обробка йде пачками по 5 через WP-Cron; сторінку можна закрити.', 'bp-meta-generator' ); ?>
		</p>
		<p>
			<button type="button" class="button button-primary" id="bpmg-bulk-start" <?php disabled( ! $has_key ); ?>><?php esc_html_e( 'Згенерувати для всіх без опису', 'bp-meta-generator' ); ?></button>
			<button type="button" class="button" id="bpmg-bulk-cancel" <?php disabled( 'running' !== $state['status'] ); ?>><?php esc_html_e( 'Зупинити', 'bp-meta-generator' ); ?></button>
		</p>
		<div id="bpmg-bulk-progress" class="bpmg-progress" data-status="<?php echo esc_attr( $state['status'] ); ?>" <?php echo 'idle' === $state['status'] ? 'hidden' : ''; ?>>
			<div class="bpmg-progress__bar"><span class="bpmg-progress__fill" style="width:0"></span></div>
			<p class="bpmg-progress__text"></p>
		</div>

		<hr>

		<h2><?php esc_html_e( 'Лог (останні 50 записів)', 'bp-meta-generator' ); ?></h2>
		<?php if ( empty( $log ) ) : ?>
			<p><?php esc_html_e( 'Лог порожній.', 'bp-meta-generator' ); ?></p>
		<?php else : ?>
			<p><button type="button" class="button" id="bpmg-clear-log"><?php esc_html_e( 'Очистити лог', 'bp-meta-generator' ); ?></button></p>
			<table class="widefat striped bpmg-log">
				<thead><tr>
					<th><?php esc_html_e( 'Час', 'bp-meta-generator' ); ?></th>
					<th><?php esc_html_e( 'Рівень', 'bp-meta-generator' ); ?></th>
					<th><?php esc_html_e( 'Запис', 'bp-meta-generator' ); ?></th>
					<th><?php esc_html_e( 'Повідомлення', 'bp-meta-generator' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $log as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row->created_at ); ?></td>
						<td><span class="bpmg-level bpmg-level--<?php echo esc_attr( $row->level ); ?>"><?php echo esc_html( $row->level ); ?></span></td>
						<td>
							<?php
							$pid = (int) $row->post_id;
							if ( $pid && get_post( $pid ) ) {
								echo '<a href="' . esc_url( (string) get_edit_post_link( $pid ) ) . '">' . esc_html( get_the_title( $pid ) ?: '#' . $pid ) . '</a>';
							} elseif ( $pid ) {
								echo esc_html( '#' . $pid );
							} else {
								echo '&mdash;';
							}
							?>
						</td>
						<td><?php echo esc_html( $row->message ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}

/* -------------------------------------------------------------------------
 * AJAX: перевірка з'єднання, очищення логу
 * ---------------------------------------------------------------------- */

/**
 * Спільна перевірка для всіх AJAX-дій плагіна: nonce + edit_posts (+ додаткове право).
 */
function bpmg_ajax_check( string $extra_cap = '' ): void {
	if ( ! check_ajax_referer( 'bpmg_ajax', 'nonce', false ) ) {
		wp_send_json_error( array( 'message' => __( 'Сесія застаріла. Оновіть сторінку.', 'bp-meta-generator' ) ), 403 );
	}
	if ( ! current_user_can( 'edit_posts' ) || ( '' !== $extra_cap && ! current_user_can( $extra_cap ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Недостатньо прав.', 'bp-meta-generator' ) ), 403 );
	}
}

function bpmg_ajax_test_connection(): void {
	bpmg_ajax_check( 'manage_options' );

	$result = bpmg_api_request(
		'Ти тестуєш з\'єднання. Відповідай одним словом.',
		array( array( 'role' => 'user', 'content' => 'Напиши: OK' ) ),
		10,
		1
	);

	if ( is_wp_error( $result ) ) {
		bpmg_log( 'Перевірка з\'єднання: ' . $result->get_error_message() );
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}
	wp_send_json_success( array( 'message' => sprintf( __( 'З\'єднання працює (модель %s).', 'bp-meta-generator' ), bpmg_get_model() ) ) );
}
add_action( 'wp_ajax_bpmg_test_connection', 'bpmg_ajax_test_connection' );

function bpmg_ajax_clear_log(): void {
	bpmg_ajax_check( 'manage_options' );
	bpmg_clear_log();
	wp_send_json_success();
}
add_action( 'wp_ajax_bpmg_clear_log', 'bpmg_ajax_clear_log' );
