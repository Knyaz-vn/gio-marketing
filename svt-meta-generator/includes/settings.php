<?php
/**
 * Налаштування плагіна: опції, сторінка "Налаштування → SVT Meta Generator".
 */

defined( 'ABSPATH' ) || exit;

function svtmg_default_prompts(): array {
	return array(
		'ru' => "Ты пишешь meta description на русском языке для страницы интернет-магазина SVT Market (svtmarket.com): сварочное оборудование, сварочные материалы, инструмент и средства защиты, работаем с 1997 года, доставка по Украине. Длина от 140 до 160 символов с пробелами. Для товара: назови тип товара, бренд и модель, добавь 1-2 ключевые характеристики из переданных данных. Для категории: назови группу товаров и что в ней можно подобрать (типы, бренды, назначение). Для информационной страницы: передай её суть. Заверши коротким призывом (купить, заказать, подобрать, узнать). Не указывай цены, скидки, наличие и сроки доставки, они меняются. Запрещено: выдумывать характеристики, которых нет в данных, слова 'лучший', 'самый дешёвый', 'гарантированно', CAPS, эмодзи, длинное тире, кавычки-ёлочки. Верни только текст описания без пояснений.",
		'uk' => "Ти пишеш meta description українською мовою для сторінки інтернет-магазину SVT Market (svtmarket.com): зварювальне обладнання, зварювальні матеріали, інструмент і засоби захисту, працюємо з 1997 року, доставка по Україні. Довжина від 140 до 160 символів з пробілами. Для товару: назви тип товару, бренд і модель, додай 1-2 ключові характеристики з переданих даних. Для категорії: назви групу товарів і що в ній можна підібрати (типи, бренди, призначення). Для інформаційної сторінки: передай її суть. Заверши коротким закликом (купити, замовити, підібрати, дізнатися). Не вказуй ціни, знижки, наявність і терміни доставки, вони змінюються. Заборонено: вигадувати характеристики, яких немає в даних, слова 'найкращий', 'найдешевший', 'гарантовано', CAPS, емодзі, довге тире, лапки-ялинки. Поверни тільки текст опису без пояснень.",
	);
}

function svtmg_defaults(): array {
	$prompts = svtmg_default_prompts();
	return array(
		'api_key'           => '',
		'model'             => SVTMG_DEFAULT_MODEL,
		'min_len'           => 140,
		'max_len'           => 160,
		'post_types'        => array( 'product', 'page', 'post' ),
		'taxonomies'        => array( 'product_cat' ),
		'prompt_ru'         => $prompts['ru'],
		'prompt_uk'         => $prompts['uk'],
		'default_lang'      => 'ru',
		'template_patterns' => '',
		'auto_generate'     => 1,
		'overwrite'         => 0,
		'batch_size'        => 5,
		'pause'             => 2,
	);
}

function svtmg_get_settings(): array {
	$saved = get_option( 'svtmg_settings', array() );
	return wp_parse_args( is_array( $saved ) ? $saved : array(), svtmg_defaults() );
}

/**
 * @return mixed
 */
function svtmg_get_setting( string $key ) {
	$settings = svtmg_get_settings();
	return $settings[ $key ] ?? null;
}

function svtmg_is_api_key_from_constant(): bool {
	return defined( 'SVTMG_API_KEY' ) && is_string( SVTMG_API_KEY ) && '' !== trim( SVTMG_API_KEY );
}

/**
 * Константа SVTMG_API_KEY у wp-config.php має пріоритет над ключем з налаштувань.
 */
function svtmg_get_api_key(): string {
	if ( svtmg_is_api_key_from_constant() ) {
		return trim( SVTMG_API_KEY );
	}
	return trim( (string) svtmg_get_setting( 'api_key' ) );
}

function svtmg_get_model(): string {
	$model = trim( (string) svtmg_get_setting( 'model' ) );
	return '' !== $model ? $model : SVTMG_DEFAULT_MODEL;
}

function svtmg_get_prompt( string $lang ): string {
	$prompt = trim( (string) svtmg_get_setting( 'uk' === $lang ? 'prompt_uk' : 'prompt_ru' ) );
	return '' !== $prompt ? $prompt : svtmg_default_prompts()[ 'uk' === $lang ? 'uk' : 'ru' ];
}

/**
 * @return array<string, WP_Post_Type>
 */
function svtmg_get_available_post_types(): array {
	$types = get_post_types( array( 'public' => true ), 'objects' );
	unset( $types['attachment'], $types['elementor_library'], $types['e-landing-page'] );
	return $types;
}

/**
 * Публічні таксономії (категорії товарів, мітки, атрибути pa_* з архівами).
 *
 * @return array<string, WP_Taxonomy>
 */
function svtmg_get_available_taxonomies(): array {
	$taxes = get_taxonomies( array( 'public' => true ), 'objects' );
	unset( $taxes['post_format'], $taxes['language'], $taxes['term_language'], $taxes['post_translations'], $taxes['term_translations'], $taxes['elementor_library_type'], $taxes['product_shipping_class'] );
	foreach ( $taxes as $name => $tax ) {
		if ( ! $tax->publicly_queryable && ! $tax->public ) {
			unset( $taxes[ $name ] );
		}
	}
	return $taxes;
}

/**
 * @return string[]
 */
function svtmg_get_enabled_post_types(): array {
	return array_values( array_intersect( (array) svtmg_get_setting( 'post_types' ), array_keys( svtmg_get_available_post_types() ) ) );
}

/**
 * @return string[]
 */
function svtmg_get_enabled_taxonomies(): array {
	return array_values( array_intersect( (array) svtmg_get_setting( 'taxonomies' ), array_keys( svtmg_get_available_taxonomies() ) ) );
}

function svtmg_is_post_type_enabled( string $post_type ): bool {
	return in_array( $post_type, svtmg_get_enabled_post_types(), true );
}

function svtmg_is_taxonomy_enabled( string $taxonomy ): bool {
	return in_array( $taxonomy, svtmg_get_enabled_taxonomies(), true );
}

/* -------------------------------------------------------------------------
 * Реєстрація і санітизація
 * ---------------------------------------------------------------------- */

function svtmg_register_settings(): void {
	register_setting(
		'svtmg_settings_group',
		'svtmg_settings',
		array(
			'type'              => 'array',
			'sanitize_callback' => 'svtmg_sanitize_settings',
			'default'           => svtmg_defaults(),
		)
	);
}
add_action( 'admin_init', 'svtmg_register_settings' );

function svtmg_sanitize_settings( $input ): array {
	$old      = svtmg_get_settings();
	$defaults = svtmg_defaults();
	$input    = is_array( $input ) ? $input : array();
	$out      = array();

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

	$types             = isset( $input['post_types'] ) ? array_map( 'sanitize_key', (array) $input['post_types'] ) : array();
	$out['post_types'] = array_values( array_intersect( $types, array_keys( svtmg_get_available_post_types() ) ) );

	$taxes             = isset( $input['taxonomies'] ) ? array_map( 'sanitize_key', (array) $input['taxonomies'] ) : array();
	$out['taxonomies'] = array_values( array_intersect( $taxes, array_keys( svtmg_get_available_taxonomies() ) ) );

	foreach ( array( 'ru', 'uk' ) as $lang ) {
		$prompt = isset( $input[ 'prompt_' . $lang ] ) ? trim( sanitize_textarea_field( $input[ 'prompt_' . $lang ] ) ) : '';
		if ( ! empty( $input[ 'prompt_' . $lang . '_reset' ] ) || '' === $prompt ) {
			$prompt = svtmg_default_prompts()[ $lang ];
		}
		$out[ 'prompt_' . $lang ] = $prompt;
	}

	$lang                = svtmg_normalize_lang( (string) ( $input['default_lang'] ?? '' ) );
	$out['default_lang'] = '' !== $lang ? $lang : 'ru';

	$patterns = isset( $input['template_patterns'] ) ? sanitize_textarea_field( $input['template_patterns'] ) : '';
	$lines    = array_filter( array_map( 'trim', explode( "\n", $patterns ) ), static fn( $l ) => mb_strlen( $l ) >= 5 );
	$out['template_patterns'] = implode( "\n", array_slice( array_unique( $lines ), 0, 20 ) );

	$out['auto_generate'] = empty( $input['auto_generate'] ) ? 0 : 1;
	$out['overwrite']     = empty( $input['overwrite'] ) ? 0 : 1;
	$out['batch_size']    = max( 1, min( 20, absint( $input['batch_size'] ?? $defaults['batch_size'] ) ) );
	$out['pause']         = max( 0, min( 30, absint( $input['pause'] ?? $defaults['pause'] ) ) );

	return $out;
}

/* -------------------------------------------------------------------------
 * Сторінка налаштувань
 * ---------------------------------------------------------------------- */

function svtmg_admin_menu(): void {
	add_options_page(
		__( 'SVT Meta Generator', 'svt-meta-generator' ),
		__( 'SVT Meta Generator', 'svt-meta-generator' ),
		'manage_options',
		'svtmg-settings',
		'svtmg_render_settings_page'
	);
}
add_action( 'admin_menu', 'svtmg_admin_menu' );

function svtmg_seo_target_label(): string {
	switch ( svtmg_get_seo_plugin() ) {
		case 'yoast':
			return 'Yoast SEO (товари/сторінки: _yoast_wpseo_metadesc, категорії: опис Yoast для терміна). Власний тег не виводиться.';
		case 'rankmath':
			return 'Rank Math (rank_math_description). Власний тег не виводиться.';
		default:
			return 'Власне поле (_svtmg_meta_description). Плагін сам виводить meta description і og:description.';
	}
}

function svtmg_render_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$s          = svtmg_get_settings();
	$from_const = svtmg_is_api_key_from_constant();
	$has_key    = '' !== svtmg_get_api_key();
	$state      = svtmg_bulk_get_state();
	$log        = svtmg_get_log( 50 );
	$ml         = svtmg_multilang_plugin();
	$f          = static fn( string $k ) => 'svtmg_settings[' . $k . ']';
	?>
	<div class="wrap svtmg-wrap">
		<h1><?php esc_html_e( 'SVT Meta Generator', 'svt-meta-generator' ); ?></h1>

		<?php if ( ! $has_key ) : ?>
			<div class="notice notice-warning"><p><?php esc_html_e( 'Вкажіть API-ключ Anthropic, щоб почати генерацію.', 'svt-meta-generator' ); ?></p></div>
		<?php endif; ?>

		<p>
			<strong><?php esc_html_e( 'Куди зберігаються описи:', 'svt-meta-generator' ); ?></strong> <?php echo esc_html( svtmg_seo_target_label() ); ?><br>
			<strong><?php esc_html_e( 'Мультимовність:', 'svt-meta-generator' ); ?></strong>
			<?php echo esc_html( 'polylang' === $ml ? 'Polylang' : ( 'wpml' === $ml ? 'WPML' : __( 'не виявлено, використовується мова за замовчуванням', 'svt-meta-generator' ) ) ); ?>
		</p>

		<form method="post" action="options.php">
			<?php settings_fields( 'svtmg_settings_group' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="svtmg_api_key"><?php esc_html_e( 'API-ключ Anthropic', 'svt-meta-generator' ); ?></label></th>
					<td>
						<?php if ( $from_const ) : ?>
							<input type="password" id="svtmg_api_key" class="regular-text" value="********" disabled>
							<p class="description"><?php esc_html_e( 'Ключ задано константою SVTMG_API_KEY у wp-config.php (має пріоритет).', 'svt-meta-generator' ); ?></p>
						<?php else : ?>
							<input type="password" id="svtmg_api_key" name="<?php echo esc_attr( $f( 'api_key' ) ); ?>" class="regular-text" value="" autocomplete="new-password"
								placeholder="<?php echo esc_attr( $has_key ? __( 'Ключ збережено. Залиште порожнім, щоб не змінювати', 'svt-meta-generator' ) : 'sk-ant-...' ); ?>">
							<?php if ( $has_key ) : ?>
								<label><input type="checkbox" name="<?php echo esc_attr( $f( 'api_key_clear' ) ); ?>" value="1"> <?php esc_html_e( 'Видалити збережений ключ', 'svt-meta-generator' ); ?></label>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'Безпечніше: додайте в wp-config.php рядок define( \'SVTMG_API_KEY\', \'sk-ant-...\' );', 'svt-meta-generator' ); ?></p>
						<?php endif; ?>
						<p>
							<button type="button" class="button" id="svtmg-test-connection" <?php disabled( ! $has_key ); ?>><?php esc_html_e( 'Перевірити з\'єднання', 'svt-meta-generator' ); ?></button>
							<span class="svtmg-inline-status" id="svtmg-test-status"></span>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="svtmg_model"><?php esc_html_e( 'Модель', 'svt-meta-generator' ); ?></label></th>
					<td>
						<input type="text" id="svtmg_model" name="<?php echo esc_attr( $f( 'model' ) ); ?>" class="regular-text" value="<?php echo esc_attr( $s['model'] ); ?>">
						<p class="description"><?php echo esc_html( sprintf( __( 'За замовчуванням: %s', 'svt-meta-generator' ), SVTMG_DEFAULT_MODEL ) ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Довжина опису (символів)', 'svt-meta-generator' ); ?></th>
					<td>
						<label><?php esc_html_e( 'мін.', 'svt-meta-generator' ); ?> <input type="number" name="<?php echo esc_attr( $f( 'min_len' ) ); ?>" min="50" max="300" class="small-text" value="<?php echo esc_attr( (string) $s['min_len'] ); ?>"></label>
						&nbsp;
						<label><?php esc_html_e( 'макс.', 'svt-meta-generator' ); ?> <input type="number" name="<?php echo esc_attr( $f( 'max_len' ) ); ?>" min="50" max="320" class="small-text" value="<?php echo esc_attr( (string) $s['max_len'] ); ?>"></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Типи записів', 'svt-meta-generator' ); ?></th>
					<td><fieldset class="svtmg-checklist">
						<?php foreach ( svtmg_get_available_post_types() as $name => $obj ) : ?>
							<label><input type="checkbox" name="<?php echo esc_attr( $f( 'post_types' ) ); ?>[]" value="<?php echo esc_attr( $name ); ?>" <?php checked( in_array( $name, (array) $s['post_types'], true ) ); ?>>
							<?php echo esc_html( $obj->labels->name ); ?> <code><?php echo esc_html( $name ); ?></code></label>
						<?php endforeach; ?>
					</fieldset></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Таксономії (категорії, бренди)', 'svt-meta-generator' ); ?></th>
					<td><fieldset class="svtmg-checklist svtmg-checklist--cols">
						<?php foreach ( svtmg_get_available_taxonomies() as $name => $obj ) : ?>
							<label><input type="checkbox" name="<?php echo esc_attr( $f( 'taxonomies' ) ); ?>[]" value="<?php echo esc_attr( $name ); ?>" <?php checked( in_array( $name, (array) $s['taxonomies'], true ) ); ?>>
							<?php echo esc_html( $obj->labels->name ); ?> <code><?php echo esc_html( $name ); ?></code></label>
						<?php endforeach; ?>
					</fieldset></td>
				</tr>
				<?php foreach ( svtmg_supported_languages() as $code => $label ) : ?>
					<tr>
						<th scope="row"><label for="svtmg_prompt_<?php echo esc_attr( $code ); ?>"><?php echo esc_html( sprintf( __( 'Системний промпт: %s', 'svt-meta-generator' ), $label ) ); ?></label></th>
						<td>
							<textarea id="svtmg_prompt_<?php echo esc_attr( $code ); ?>" name="<?php echo esc_attr( $f( 'prompt_' . $code ) ); ?>" rows="7" class="large-text"><?php echo esc_textarea( $s[ 'prompt_' . $code ] ); ?></textarea>
							<label><input type="checkbox" name="<?php echo esc_attr( $f( 'prompt_' . $code . '_reset' ) ); ?>" value="1"> <?php esc_html_e( 'Скинути до стандартного', 'svt-meta-generator' ); ?></label>
						</td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<th scope="row"><label for="svtmg_default_lang"><?php esc_html_e( 'Мова за замовчуванням', 'svt-meta-generator' ); ?></label></th>
					<td>
						<select id="svtmg_default_lang" name="<?php echo esc_attr( $f( 'default_lang' ) ); ?>">
							<?php foreach ( svtmg_supported_languages() as $code => $label ) : ?>
								<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $s['default_lang'], $code ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Для записів, мову яких Polylang/WPML не визначив.', 'svt-meta-generator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="svtmg_template_patterns"><?php esc_html_e( 'Шаблонні описи', 'svt-meta-generator' ); ?></label></th>
					<td>
						<textarea id="svtmg_template_patterns" name="<?php echo esc_attr( $f( 'template_patterns' ) ); ?>" rows="3" class="large-text" placeholder="На рынке с 1997 года"><?php echo esc_textarea( $s['template_patterns'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Один фрагмент на рядок (від 5 символів). Описи, що містять такий фрагмент, вважаються порожніми і будуть замінені навіть без перезапису. Наприклад: На рынке с 1997 года', 'svt-meta-generator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Автоматизація', 'svt-meta-generator' ); ?></th>
					<td>
						<label class="svtmg-block"><input type="checkbox" name="<?php echo esc_attr( $f( 'auto_generate' ) ); ?>" value="1" <?php checked( ! empty( $s['auto_generate'] ) ); ?>>
							<?php esc_html_e( 'Генерувати автоматично при публікації товару/сторінки і створенні категорії, якщо опис порожній', 'svt-meta-generator' ); ?></label>
						<label class="svtmg-block"><input type="checkbox" name="<?php echo esc_attr( $f( 'overwrite' ) ); ?>" value="1" <?php checked( ! empty( $s['overwrite'] ) ); ?>>
							<?php esc_html_e( 'Перезаписувати існуючі описи (масова генерація і групова дія)', 'svt-meta-generator' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Темп обробки', 'svt-meta-generator' ); ?></th>
					<td>
						<label><?php esc_html_e( 'пачка', 'svt-meta-generator' ); ?> <input type="number" name="<?php echo esc_attr( $f( 'batch_size' ) ); ?>" min="1" max="20" class="small-text" value="<?php echo esc_attr( (string) $s['batch_size'] ); ?>"></label>
						&nbsp;
						<label><?php esc_html_e( 'пауза між запитами, с', 'svt-meta-generator' ); ?> <input type="number" name="<?php echo esc_attr( $f( 'pause' ) ); ?>" min="0" max="30" class="small-text" value="<?php echo esc_attr( (string) $s['pause'] ); ?>"></label>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<hr>

		<h2><?php esc_html_e( 'Масова генерація', 'svt-meta-generator' ); ?></h2>
		<p class="description">
			<?php
			echo esc_html(
				! empty( $s['overwrite'] )
					? __( 'Перезапис увімкнено: будуть оброблені ВСІ опубліковані об\'єкти вибраного обсягу.', 'svt-meta-generator' )
					: __( 'Будуть оброблені об\'єкти з порожнім або шаблонним описом.', 'svt-meta-generator' )
			);
			?>
			<?php esc_html_e( 'Обробка йде пачками через WP-Cron, сторінку можна закрити.', 'svt-meta-generator' ); ?>
		</p>
		<p class="svtmg-bulk-controls">
			<label><?php esc_html_e( 'Що обробити:', 'svt-meta-generator' ); ?>
				<select id="svtmg-bulk-scope">
					<option value="all"><?php esc_html_e( 'Усе вибране в налаштуваннях', 'svt-meta-generator' ); ?></option>
					<?php foreach ( svtmg_get_enabled_post_types() as $pt ) : ?>
						<option value="<?php echo esc_attr( 'post_type:' . $pt ); ?>"><?php echo esc_html( get_post_type_object( $pt )->labels->name ); ?></option>
					<?php endforeach; ?>
					<?php foreach ( svtmg_get_enabled_taxonomies() as $tax ) : ?>
						<option value="<?php echo esc_attr( 'taxonomy:' . $tax ); ?>"><?php echo esc_html( get_taxonomy( $tax )->labels->name ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<?php if ( '' !== $ml ) : ?>
				<label><?php esc_html_e( 'Мова:', 'svt-meta-generator' ); ?>
					<select id="svtmg-bulk-lang">
						<option value=""><?php esc_html_e( 'усі', 'svt-meta-generator' ); ?></option>
						<?php foreach ( svtmg_supported_languages() as $code => $label ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			<?php endif; ?>
			<label><?php esc_html_e( 'Не більше:', 'svt-meta-generator' ); ?>
				<input type="number" id="svtmg-bulk-limit" min="0" class="small-text" value="20">
				<span class="description"><?php esc_html_e( '(0 = без обмеження; почніть з 20 для перевірки якості)', 'svt-meta-generator' ); ?></span>
			</label>
		</p>
		<p>
			<button type="button" class="button button-primary" id="svtmg-bulk-start" <?php disabled( ! $has_key ); ?>><?php esc_html_e( 'Згенерувати для всіх без опису', 'svt-meta-generator' ); ?></button>
			<button type="button" class="button" id="svtmg-bulk-cancel" <?php disabled( 'running' !== $state['status'] ); ?>><?php esc_html_e( 'Зупинити', 'svt-meta-generator' ); ?></button>
		</p>
		<div id="svtmg-bulk-progress" class="svtmg-progress" data-status="<?php echo esc_attr( $state['status'] ); ?>" <?php echo 'idle' === $state['status'] ? 'hidden' : ''; ?>>
			<div class="svtmg-progress__bar"><span class="svtmg-progress__fill" style="width:0"></span></div>
			<p class="svtmg-progress__text"></p>
		</div>

		<hr>

		<h2><?php esc_html_e( 'Лог (останні 50 записів)', 'svt-meta-generator' ); ?></h2>
		<?php if ( empty( $log ) ) : ?>
			<p><?php esc_html_e( 'Лог порожній.', 'svt-meta-generator' ); ?></p>
		<?php else : ?>
			<p><button type="button" class="button" id="svtmg-clear-log"><?php esc_html_e( 'Очистити лог', 'svt-meta-generator' ); ?></button></p>
			<table class="widefat striped svtmg-log">
				<thead><tr>
					<th><?php esc_html_e( 'Час', 'svt-meta-generator' ); ?></th>
					<th><?php esc_html_e( 'Рівень', 'svt-meta-generator' ); ?></th>
					<th><?php esc_html_e( 'Об\'єкт', 'svt-meta-generator' ); ?></th>
					<th><?php esc_html_e( 'Повідомлення', 'svt-meta-generator' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $log as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row->created_at ); ?></td>
						<td><span class="svtmg-level svtmg-level--<?php echo esc_attr( $row->level ); ?>"><?php echo esc_html( $row->level ); ?></span></td>
						<td><?php echo svtmg_log_object_link( (int) $row->post_id ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?></td>
						<td><?php echo esc_html( $row->message ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * У колонці post_id логу терміни зберігаються як від'ємні ID.
 */
function svtmg_log_object_link( int $logged_id ): string {
	if ( 0 === $logged_id ) {
		return '&mdash;';
	}
	if ( $logged_id < 0 ) {
		$term = get_term( -$logged_id );
		if ( $term instanceof WP_Term ) {
			return '<a href="' . esc_url( (string) get_edit_term_link( $term ) ) . '">' . esc_html( $term->name ) . '</a> <span class="description">(' . esc_html( $term->taxonomy ) . ')</span>';
		}
		return esc_html( 'term #' . ( -$logged_id ) );
	}
	if ( get_post( $logged_id ) ) {
		return '<a href="' . esc_url( (string) get_edit_post_link( $logged_id ) ) . '">' . esc_html( get_the_title( $logged_id ) ?: '#' . $logged_id ) . '</a>';
	}
	return esc_html( '#' . $logged_id );
}

/* -------------------------------------------------------------------------
 * AJAX: перевірка з'єднання, очищення логу
 * ---------------------------------------------------------------------- */

function svtmg_ajax_check( string $extra_cap = '' ): void {
	if ( ! check_ajax_referer( 'svtmg_ajax', 'nonce', false ) ) {
		wp_send_json_error( array( 'message' => __( 'Сесія застаріла. Оновіть сторінку.', 'svt-meta-generator' ) ), 403 );
	}
	if ( ! current_user_can( 'edit_posts' ) || ( '' !== $extra_cap && ! current_user_can( $extra_cap ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Недостатньо прав.', 'svt-meta-generator' ) ), 403 );
	}
}

function svtmg_ajax_test_connection(): void {
	svtmg_ajax_check( 'manage_options' );

	$result = svtmg_api_request(
		'Ты проверяешь соединение. Ответь одним словом.',
		array( array( 'role' => 'user', 'content' => 'Напиши: OK' ) ),
		1
	);

	if ( is_wp_error( $result ) ) {
		svtmg_log( 'Перевірка з\'єднання: ' . $result->get_error_message() );
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}
	wp_send_json_success( array( 'message' => sprintf( __( 'З\'єднання працює (модель %s).', 'svt-meta-generator' ), svtmg_get_model() ) ) );
}
add_action( 'wp_ajax_svtmg_test_connection', 'svtmg_ajax_test_connection' );

function svtmg_ajax_clear_log(): void {
	svtmg_ajax_check( 'manage_options' );
	svtmg_clear_log();
	wp_send_json_success();
}
add_action( 'wp_ajax_svtmg_clear_log', 'svtmg_ajax_clear_log' );
