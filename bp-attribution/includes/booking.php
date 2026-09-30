<?php
/**
 * Хелпери для модуля онлайн-запису.
 *
 * Модуль читає ту саму cookie bp_attr (або приховані поля bp_attr[...], якщо форма запису -
 * звичайна HTML-форма на сайті) і пише ті самі поля.
 *
 * Приклад у обробнику запису:
 *
 *   $booking_id = $wpdb->insert_id; // запис у власній таблиці модуля
 *   bp_attr_record_booking(
 *       array( 'doctor' => $doctor, 'date' => $date ), // поля заявки (зберігаються в wp_bp_leads.fields)
 *       array(
 *           'form_id'   => 'booking',
 *           'location'  => $location_slug,
 *           'specialty' => $specialty_slug,
 *           'table'     => $wpdb->prefix . 'bp_bookings', // опційно: таблиця модуля
 *           'row_id'    => $booking_id,                    // опційно: ID рядка в ній
 *       )
 *   );
 *   $email_body .= "\n\n" . bp_attr_email_line( bp_attr_booking_fields() );
 */

defined( 'ABSPATH' ) || exit;

/**
 * Поля атрибуції для запису (з форми або з cookie bp_attr).
 */
function bp_attr_booking_fields( array $override = array() ) {
	return bp_attr_collect( $override );
}

/**
 * Зберігає запис в wp_bp_leads і, якщо передано table + row_id, дописує ті самі поля
 * у рядок таблиці модуля запису (відсутні колонки створюються автоматично).
 *
 * @param array $booking Поля запису (ім'я, телефон тощо - лише в БД, не в dataLayer).
 * @param array $args    form_id, form_name, location, specialty, self_reported, table, row_id, id_column.
 * @return int ID у wp_bp_leads.
 */
function bp_attr_record_booking( array $booking, array $args = array() ) {
	global $wpdb;
	$attr = bp_attr_collect(
		array_intersect_key( $args, array_flip( array( 'location', 'specialty', 'self_reported' ) ) )
	);
	$id = BP_Attr_Integrations::handle(
		$attr,
		array(
			'form_type' => 'booking',
			'form_id'   => $args['form_id'] ?? 'booking',
			'form_name' => $args['form_name'] ?? 'Онлайн-запис',
			'fields'    => $booking,
		)
	);

	if ( ! empty( $args['table'] ) && ! empty( $args['row_id'] ) && bp_attr_ensure_columns( $args['table'] ) ) {
		$id_col = preg_replace( '/[^A-Za-z0-9_]/', '', $args['id_column'] ?? 'id' );
		$wpdb->update( $args['table'], bp_attr_db_values( bp_attr_sanitize_fields( $attr ) ), array( $id_col => $args['row_id'] ) );
	}
	do_action( 'bp_attr_booking_submitted', $id, $attr, $booking, $args );
	return $id;
}
