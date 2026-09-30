<?php
/**
 * Налаштування плагіна.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_init', 'bp_attr_register_settings' );

function bp_attr_register_settings() {
	register_setting(
		'bp_attr',
		'bp_attr_settings',
		array(
			'type'              => 'array',
			'sanitize_callback' => 'bp_attr_sanitize_settings',
		)
	);
}

function bp_attr_sanitize_settings( $in ) {
	$in  = is_array( $in ) ? $in : array();
	$out = array(
		'site_domain'         => preg_replace( '/[^a-z0-9.\-]/', '', strtolower( $in['site_domain'] ?? '' ) ),
		'cookie_domain'       => preg_replace( '/[^a-z0-9.\-]/', '', strtolower( $in['cookie_domain'] ?? '' ) ),
		'consent'             => in_array( $in['consent'] ?? '', array( 'auto', 'require', 'off' ), true ) ? $in['consent'] : 'auto',
		'exclude_referrers'   => sanitize_textarea_field( $in['exclude_referrers'] ?? '' ),
		'self_reported_forms' => sanitize_textarea_field( $in['self_reported_forms'] ?? '' ),
		'non_lead_forms'      => sanitize_textarea_field( $in['non_lead_forms'] ?? '' ),
	);
	return $out;
}

function bp_attr_render_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$s = bp_attr_settings();
	$f = static function ( $k ) {
		return 'bp_attr_settings[' . $k . ']';
	};
	?>
	<div class="wrap">
		<h1>Налаштування атрибуції</h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'bp_attr' ); ?>
			<table class="form-table" role="presentation">
				<tr><th>Домен сайту</th><td>
					<input class="regular-text" name="<?php echo esc_attr( $f( 'site_domain' ) ); ?>" value="<?php echo esc_attr( $s['site_domain'] ); ?>">
					<p class="description">Переходи з цього домену і всіх його піддоменів вважаються внутрішніми.</p></td></tr>
				<tr><th>Домен cookie</th><td>
					<input class="regular-text" name="<?php echo esc_attr( $f( 'cookie_domain' ) ); ?>" value="<?php echo esc_attr( $s['cookie_domain'] ); ?>" placeholder=".bpmedical.com.ua">
					<p class="description">Заповніть <code>.bpmedical.com.ua</code>, якщо онлайн-запис працює на піддомені. Порожньо - лише поточний хост.</p></td></tr>
				<tr><th>Згода на cookies</th><td>
					<select name="<?php echo esc_attr( $f( 'consent' ) ); ?>">
						<option value="auto" <?php selected( $s['consent'], 'auto' ); ?>>Авто: чекати analytics_storage=granted, якщо є Consent Mode / банер</option>
						<option value="require" <?php selected( $s['consent'], 'require' ); ?>>Завжди чекати явної згоди</option>
						<option value="off" <?php selected( $s['consent'], 'off' ); ?>>Банера немає - писати cookie одразу</option>
					</select>
					<p class="description">До згоди дотики зберігаються в sessionStorage і переносяться в cookie після згоди.</p></td></tr>
				<tr><th>Виключені referrer-и</th><td>
					<textarea class="large-text" rows="4" name="<?php echo esc_attr( $f( 'exclude_referrers' ) ); ?>"><?php echo esc_textarea( $s['exclude_referrers'] ); ?></textarea>
					<p class="description">Домени, повернення з яких не є новим дотиком (платіжні шлюзи тощо). По одному в рядку.</p></td></tr>
				<tr><th>Поле "Звідки ви дізналися про нас?"</th><td>
					<textarea class="large-text" rows="3" name="<?php echo esc_attr( $f( 'self_reported_forms' ) ); ?>"><?php echo esc_textarea( $s['self_reported_forms'] ); ?></textarea>
					<p class="description">form_id форм запису, у які автоматично додати необов'язковий select (див. "Форми на сайті"). <code>*</code> - в усі форми.</p></td></tr>
				<tr><th>Форми, що не є заявками</th><td>
					<textarea class="large-text" rows="3" name="<?php echo esc_attr( $f( 'non_lead_forms' ) ); ?>"><?php echo esc_textarea( $s['non_lead_forms'] ); ?></textarea>
					<p class="description">form_id форм (напр. відгук "Нам прикро", підписка), які зберігаються, але за замовчуванням не рахуються у звіті.</p></td></tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}
