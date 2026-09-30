<?php
/**
 * Plugin Name: BP Attribution E2E fixture
 * Description: Імітація Popup Maker (#popmake-13597) з формою Elementor Pro для E2E-тестів. НЕ встановлювати на робочий сайт.
 */

add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_script( 'jquery' );
} );

// ?consent=denied - Google Consent Mode з analytics_storage=denied за замовчуванням.
add_action( 'wp_head', function () {
	if ( isset( $_GET['consent'] ) && 'denied' === $_GET['consent'] ) {
		echo "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag('consent','default',{analytics_storage:'denied',ad_storage:'denied'});</script>\n";
	}
}, 1 );

// Хедер (як Elementor Theme Builder) і закріплена кнопка дзвінка.
add_action( 'wp_body_open', function () {
	?>
	<header class="elementor elementor-location-header" data-elementor-type="header">
		<a id="hdr-066" href="tel:+380662119922">066 211 99 22</a>
		<a id="hdr-050" href="tel:+380502119922">050 211 99 22</a>
	</header>
	<div class="call-btn" style="position:fixed;bottom:10px;right:10px;z-index:9"><a id="sticky-050" href="tel:+380502119922">Подзвонити</a></div>
	<?php
} );

add_action( 'wp_footer', function () {
	?>
	<p><a id="unknown-tel" href="tel:+380931112233">093 111 22 33</a></p>
	<p><button type="button" id="open-popup">Записатися</button> <a id="call" href="tel:+380441234567">+38 044 123 45 67</a></p>
	<template id="bp-popup-tpl">
		<div id="pum-13597" class="pum pum-overlay"><div id="popmake-13597" class="pum-container popmake">
			<form class="elementor-form" method="post" name="Запис (попап)">
				<input type="hidden" name="post_id" value="1">
				<input type="hidden" name="form_id" value="a1b2c3d">
				<input type="text" name="form_fields[name]" placeholder="Ім'я">
				<input type="tel" name="form_fields[phone]" placeholder="Телефон">
				<select name="form_fields[location]"><option value="">Локація</option><option value="lviv">Львів</option><option value="kyiv">Київ</option></select>
				<button type="submit">Надіслати</button>
			</form>
			<div class="bp-e2e-done" hidden>Дякуємо!</div>
		</div></div>
	</template>
	<script>
	jQuery(function ($) {
		$('#open-popup').on('click', function () {
			document.body.appendChild(document.getElementById('bp-popup-tpl').content.cloneNode(true));
			// як Elementor Pro: AJAX-сабміт через FormData + jQuery-подія submit_success
			$('#popmake-13597 form').on('submit', function (e) {
				e.preventDefault();
				var form = this, fd = new FormData(form);
				fd.append('action', 'bp_e2e_elementor');
				fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', { method: 'POST', body: fd })
					.then(function (r) { return r.json(); })
					.then(function (res) { if (res.success) { $(form).trigger('submit_success'); $('.bp-e2e-done').prop('hidden', false); } });
			});
		});
	});
	</script>
	<?php
} );

/** Мінімальна заглушка ElementorPro\Modules\Forms\Classes\Form_Record. */
class BP_E2E_Record {
	private $fields;
	public function __construct( $fields ) { $this->fields = $fields; }
	public function get( $k ) { return 'fields' === $k ? $this->fields : null; }
	public function get_form_settings( $k ) { return 'form_name' === $k ? 'Запис (попап)' : null; }
}

$bp_e2e_handler = function () {
	$fields = array();
	foreach ( (array) wp_unslash( $_POST['form_fields'] ?? array() ) as $id => $v ) {
		$fields[ $id ] = array( 'id' => $id, 'type' => 'phone' === $id ? 'tel' : 'text', 'value' => sanitize_text_field( $v ), 'title' => $id );
	}
	// Та сама послідовність, що в Elementor Pro Ajax_Handler: process -> дії (email).
	do_action( 'elementor_pro/forms/process', new BP_E2E_Record( $fields ), null );
	wp_mail( get_option( 'admin_email' ), 'Нова заявка', "Ім'я: " . $fields['name']['value'] . "\nТелефон: " . $fields['phone']['value'] );
	wp_send_json_success();
};
add_action( 'wp_ajax_nopriv_bp_e2e_elementor', $bp_e2e_handler );
add_action( 'wp_ajax_bp_e2e_elementor', $bp_e2e_handler );

// Листи не відправляємо - зберігаємо останній для перевірки.
add_filter( 'pre_wp_mail', function ( $ret, $atts ) {
	update_option( 'bp_e2e_last_mail', $atts, false );
	return true;
}, 10, 2 );

// Імітація модуля онлайн-запису зі своєю таблицею: атрибуція лише з cookie bp_attr.
$bp_e2e_booking = function () {
	global $wpdb;
	$table = $wpdb->prefix . 'bp_bookings';
	$wpdb->query( "CREATE TABLE IF NOT EXISTS $table (id bigint unsigned NOT NULL AUTO_INCREMENT, patient varchar(100), PRIMARY KEY (id))" );
	$wpdb->insert( $table, array( 'patient' => 'Пацієнт' ) );
	$row_id = $wpdb->insert_id;
	$lead   = bp_attr_record_booking(
		array( 'patient' => 'Пацієнт', 'doctor' => 'Лікар' ),
		array( 'form_id' => 'booking', 'location' => 'kyiv', 'table' => $table, 'row_id' => $row_id )
	);
	wp_send_json_success(
		array(
			'lead'    => $lead,
			'booking' => $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $row_id ), ARRAY_A ),
			'line'    => bp_attr_email_line( bp_attr_booking_fields() ),
		)
	);
};
add_action( 'wp_ajax_nopriv_bp_e2e_booking', $bp_e2e_booking );
add_action( 'wp_ajax_bp_e2e_booking', $bp_e2e_booking );

// Імітація плагіна безпеки, що блокує REST API для гостей (вмикається опцією з тесту).
add_filter( 'rest_authentication_errors', function ( $r ) {
	return get_option( 'bp_e2e_block_rest' ) ? new WP_Error( 'rest_forbidden', 'REST API disabled', array( 'status' => 401 ) ) : $r;
} );
