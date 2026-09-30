<?php
/**
 * Спільні функції: схема БД, читання cookie bp_attr, поля атрибуції,
 * збереження заявки, рядок для листа, категорії звіту.
 */

defined( 'ABSPATH' ) || exit;

/* ------------------------------------------------------------------ */
/* Налаштування                                                        */
/* ------------------------------------------------------------------ */

function bp_attr_settings() {
	$host     = wp_parse_url( home_url(), PHP_URL_HOST );
	$defaults = array(
		'site_domain'         => preg_replace( '/^www\./', '', (string) $host ),
		'cookie_domain'       => '',
		'consent'             => 'auto', // auto | require | off
		'exclude_referrers'   => "liqpay.ua\nwayforpay.com\nprivatbank.ua\nmonobank.ua",
		'self_reported_forms' => '',
		'non_lead_forms'      => '',
		'capability'          => 'manage_options',
	);
	$saved = get_option( 'bp_attr_settings', array() );
	$s     = array_merge( $defaults, is_array( $saved ) ? $saved : array() );
	if ( '' === $s['site_domain'] ) {
		$s['site_domain'] = $defaults['site_domain'];
	}
	return $s;
}

/** Рядки / коми -> масив без порожніх значень. */
function bp_attr_lines( $text ) {
	return array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', (string) $text ) ) ) );
}

/* ------------------------------------------------------------------ */
/* БД                                                                  */
/* ------------------------------------------------------------------ */

function bp_attr_table() {
	global $wpdb;
	return $wpdb->prefix . 'bp_leads';
}

/**
 * Колонки атрибуції (використовуються і в wp_bp_leads, і в таблиці модуля запису).
 */
function bp_attr_column_defs() {
	$cols = array();
	foreach ( array( 'ft', 'lt' ) as $p ) {
		$cols[ "{$p}_source" ]   = 'varchar(100) NOT NULL DEFAULT \'\'';
		$cols[ "{$p}_medium" ]   = 'varchar(50) NOT NULL DEFAULT \'\'';
		$cols[ "{$p}_campaign" ] = 'varchar(150) NOT NULL DEFAULT \'\'';
		$cols[ "{$p}_term" ]     = 'varchar(150) NOT NULL DEFAULT \'\'';
		$cols[ "{$p}_content" ]  = 'varchar(150) NOT NULL DEFAULT \'\'';
		$cols[ "{$p}_landing" ]  = 'varchar(255) NOT NULL DEFAULT \'\'';
		$cols[ "{$p}_ts" ]       = 'datetime NULL DEFAULT NULL';
		$cols[ "{$p}_click_id" ] = 'varchar(255) NOT NULL DEFAULT \'\'';
	}
	$cols['touch_path']      = 'text NULL';
	$cols['touch_count']     = 'smallint unsigned NOT NULL DEFAULT 0';
	$cols['days_to_convert'] = 'smallint unsigned NULL DEFAULT NULL';
	$cols['paid_in_path']    = 'tinyint(1) NOT NULL DEFAULT 0';
	$cols['gclid']           = 'varchar(255) NOT NULL DEFAULT \'\'';
	$cols['gbraid']          = 'varchar(255) NOT NULL DEFAULT \'\'';
	$cols['wbraid']          = 'varchar(255) NOT NULL DEFAULT \'\'';
	$cols['fbclid']          = 'varchar(255) NOT NULL DEFAULT \'\'';
	$cols['page_url']        = 'varchar(500) NOT NULL DEFAULT \'\'';
	$cols['specialty']       = 'varchar(100) NOT NULL DEFAULT \'\'';
	$cols['location']        = 'varchar(100) NOT NULL DEFAULT \'\'';
	$cols['self_reported']   = 'varchar(50) NOT NULL DEFAULT \'\'';
	return $cols;
}

/** Ключі полів, які JS передає у формі як bp_attr[...]. */
function bp_attr_field_keys() {
	return array_keys( bp_attr_column_defs() );
}

function bp_attr_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table = bp_attr_table();
	$lines = array(
		'id bigint unsigned NOT NULL AUTO_INCREMENT',
		'created_at datetime NOT NULL',
		"form_type varchar(30) NOT NULL DEFAULT ''",
		"form_id varchar(150) NOT NULL DEFAULT ''",
		"form_name varchar(191) NOT NULL DEFAULT ''",
	);
	foreach ( bp_attr_column_defs() as $name => $def ) {
		$lines[] = "$name $def";
	}
	$lines[] = 'fields longtext NULL';
	$lines[] = 'PRIMARY KEY  (id)';
	$lines[] = 'KEY created_at (created_at)';
	$lines[] = 'KEY specialty (specialty)';
	$sql     = "CREATE TABLE $table (\n" . implode( ",\n", $lines ) . "\n) " . $wpdb->get_charset_collate() . ';';
	dbDelta( $sql );
	do_action( 'bp_attr_installed' ); // таблиці модулів (кліки по телефону тощо)
	update_option( 'bp_attr_db_version', BP_ATTR_DB_VERSION );
}

function bp_attr_maybe_upgrade() {
	if ( get_option( 'bp_attr_db_version' ) !== BP_ATTR_DB_VERSION ) {
		bp_attr_install();
	}
}

/**
 * Додає відсутні колонки атрибуції в довільну таблицю (напр. таблицю модуля онлайн-запису).
 */
function bp_attr_ensure_columns( $table ) {
	global $wpdb;
	$table = preg_replace( '/[^A-Za-z0-9_]/', '', $table );
	$have  = $wpdb->get_col( "SHOW COLUMNS FROM $table", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL
	if ( ! $have ) {
		return false;
	}
	foreach ( bp_attr_column_defs() as $name => $def ) {
		if ( ! in_array( $name, $have, true ) ) {
			$wpdb->query( "ALTER TABLE `$table` ADD COLUMN `$name` $def" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
	}
	return true;
}

/* ------------------------------------------------------------------ */
/* Cookie bp_attr (та сама логіка, що й buildFields у classify.js)     */
/* ------------------------------------------------------------------ */

function bp_attr_paid_mediums() {
	return apply_filters( 'bp_attr_paid_mediums', array( 'cpc', 'paid_social', 'display', 'video' ) );
}

/**
 * Розбирає значення cookie bp_attr (base64url JSON з компактними ключами).
 *
 * @return array|null ['n','p','la','ids','l','h'] з дотиками у повному форматі.
 */
function bp_attr_decode_cookie( $raw ) {
	if ( ! is_string( $raw ) || '' === $raw || strlen( $raw ) > 8000 ) {
		return null;
	}
	$b64 = strtr( $raw, '-_', '+/' );
	$b64 .= str_repeat( '=', ( 4 - strlen( $b64 ) % 4 ) % 4 );
	$json = base64_decode( $b64, true );
	$o    = $json ? json_decode( $json, true ) : null;
	if ( ! is_array( $o ) || empty( $o['h'] ) || ! is_array( $o['h'] ) ) {
		return null;
	}
	$unpack = static function ( $t ) {
		if ( ! is_array( $t ) ) {
			return null;
		}
		return array(
			'source'        => (string) ( $t['s'] ?? '' ),
			'medium'        => (string) ( $t['m'] ?? '' ),
			'campaign'      => (string) ( $t['c'] ?? '' ),
			'term'          => (string) ( $t['t'] ?? '' ),
			'content'       => (string) ( $t['o'] ?? '' ),
			'click_id_type' => (string) ( $t['k'] ?? '' ),
			'click_id'      => (string) ( $t['i'] ?? '' ),
			'landing_path'  => (string) ( $t['p'] ?? '' ),
			'ts'            => (int) ( $t['ts'] ?? 0 ),
		);
	};
	$h = array_values( array_filter( array_map( $unpack, $o['h'] ) ) );
	if ( ! $h ) {
		return null;
	}
	return array(
		'n'   => (int) ( $o['n'] ?? count( $h ) ),
		'p'   => ! empty( $o['p'] ) ? 1 : 0,
		'la'  => (int) ( $o['la'] ?? 0 ),
		'ids' => is_array( $o['ids'] ?? null ) ? $o['ids'] : array(),
		'l'   => $unpack( $o['l'] ?? null ),
		'h'   => $h,
	);
}

function bp_attr_is_direct( $t ) {
	return $t && '(direct)' === $t['source'] && '(none)' === $t['medium'];
}

function bp_attr_specialty_from_url( $url ) {
	$path = wp_parse_url( (string) $url, PHP_URL_PATH );
	if ( $path && preg_match( '#/departments/([^/?\#]+)#', $path, $m ) ) {
		return strtolower( rawurldecode( $m[1] ) );
	}
	return '';
}

/**
 * Поля атрибуції зі стану cookie.
 */
function bp_attr_fields_from_state( $s, $page_url = '', $location = '' ) {
	$f   = array_fill_keys( bp_attr_field_keys(), '' );
	$now = time() * 1000;
	if ( $s ) {
		$ft = $s['h'][0];
		$lt = $s['l'] ? $s['l'] : end( $s['h'] );
		foreach ( array( 'ft' => $ft, 'lt' => $lt ) as $p => $t ) {
			$f[ "{$p}_source" ]   = $t['source'];
			$f[ "{$p}_medium" ]   = $t['medium'];
			$f[ "{$p}_campaign" ] = $t['campaign'];
			$f[ "{$p}_term" ]     = $t['term'];
			$f[ "{$p}_content" ]  = $t['content'];
			$f[ "{$p}_landing" ]  = $t['landing_path'];
			$f[ "{$p}_ts" ]       = $t['ts'] ? gmdate( 'Y-m-d\TH:i:s\Z', (int) ( $t['ts'] / 1000 ) ) : '';
			$f[ "{$p}_click_id" ] = $t['click_id'];
		}
		$path = array_map(
			static function ( $t ) {
				return bp_attr_is_direct( $t ) ? 'direct' : $t['source'] . '/' . $t['medium'];
			},
			$s['h']
		);
		if ( $s['n'] > count( $s['h'] ) && count( $path ) > 1 ) {
			array_splice( $path, 1, 0, '…' );
		}
		$paid = $s['p'];
		foreach ( $s['h'] as $t ) {
			$paid = $paid || in_array( $t['medium'], bp_attr_paid_mediums(), true );
		}
		$f['touch_path']      = implode( ' > ', $path );
		$f['touch_count']     = (string) $s['n'];
		$f['days_to_convert'] = (string) max( 0, (int) floor( ( $now - $ft['ts'] ) / 86400000 ) );
		$f['paid_in_path']    = $paid ? '1' : '0';
		foreach ( array( 'gclid', 'gbraid', 'wbraid', 'fbclid' ) as $id ) {
			$f[ $id ] = (string) ( $s['ids'][ $id ] ?? '' );
		}
	}
	$f['page_url']  = (string) $page_url;
	$f['specialty'] = bp_attr_specialty_from_url( $page_url );
	$f['location']  = (string) $location;
	return $f;
}

/**
 * Поля атрибуції для поточного запиту.
 * Пріоритет: приховані поля bp_attr[...] із форми (JS, включно з дотиками до згоди на cookie),
 * інакше cookie bp_attr.
 *
 * @param array $override Значення, що мають пріоритет (напр. location з форми запису).
 */
function bp_attr_collect( array $override = array() ) {
	// phpcs:disable WordPress.Security.NonceVerification
	$posted = isset( $_POST['bp_attr'] ) && is_array( $_POST['bp_attr'] ) ? wp_unslash( $_POST['bp_attr'] ) : array();
	$page   = isset( $posted['page_url'] ) ? $posted['page_url'] : (string) wp_get_referer();

	if ( ! empty( $posted['ft_medium'] ) ) {
		$f = array_fill_keys( bp_attr_field_keys(), '' );
		foreach ( $f as $k => $v ) {
			if ( isset( $posted[ $k ] ) && is_scalar( $posted[ $k ] ) ) {
				$f[ $k ] = (string) $posted[ $k ];
			}
		}
	} else {
		$raw = isset( $_COOKIE['bp_attr'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['bp_attr'] ) ) : '';
		$f   = bp_attr_fields_from_state( bp_attr_decode_cookie( $raw ), $page, $posted['location'] ?? '' );
	}
	if ( isset( $posted['self_reported'] ) ) {
		$f['self_reported'] = (string) $posted['self_reported'];
	}
	if ( isset( $posted['form_id'] ) ) {
		$f['form_id'] = (string) $posted['form_id'];
	}
	// phpcs:enable
	foreach ( $override as $k => $v ) {
		if ( '' !== (string) $v ) {
			$f[ $k ] = (string) $v;
		}
	}
	return bp_attr_sanitize_fields( $f );
}

function bp_attr_sanitize_fields( array $f ) {
	$out = array();
	foreach ( array_merge( bp_attr_field_keys(), array( 'form_id' ) ) as $k ) {
		$v = isset( $f[ $k ] ) ? (string) $f[ $k ] : '';
		if ( 'page_url' === $k ) {
			$v = esc_url_raw( $v );
		} else {
			$v = sanitize_text_field( $v );
		}
		$out[ $k ] = mb_substr( $v, 0, 'page_url' === $k || 'touch_path' === $k ? 500 : 255 );
	}
	foreach ( array( 'touch_count', 'days_to_convert' ) as $k ) {
		$out[ $k ] = '' === $out[ $k ] ? '' : (string) max( 0, min( 65535, (int) $out[ $k ] ) );
	}
	$out['paid_in_path']  = '1' === $out['paid_in_path'] ? '1' : '0';
	$out['self_reported'] = bp_attr_normalize_self_reported( $out['self_reported'] );
	if ( '' === $out['specialty'] ) {
		$out['specialty'] = bp_attr_specialty_from_url( $out['page_url'] );
	}
	return $out;
}

/* ------------------------------------------------------------------ */
/* "Звідки ви дізналися про нас?"                                      */
/* ------------------------------------------------------------------ */

function bp_attr_self_reported_options() {
	return apply_filters(
		'bp_attr_self_reported_options',
		array(
			'google_search'    => 'Google пошук',
			'google_maps'      => 'Google Карти',
			'instagram'        => 'Instagram',
			'facebook'         => 'Facebook',
			'friends'          => 'Порада знайомих',
			'doctor'           => 'Порада лікаря',
			'returning'        => 'Вже був(ла) пацієнтом',
			'other'            => 'Інше',
		)
	);
}

/** Приймає код або підпис (з Elementor select) і повертає код. */
function bp_attr_normalize_self_reported( $v ) {
	$v = trim( (string) $v );
	if ( '' === $v ) {
		return '';
	}
	$opts = bp_attr_self_reported_options();
	if ( isset( $opts[ $v ] ) ) {
		return $v;
	}
	foreach ( $opts as $code => $label ) {
		if ( mb_strtolower( $label ) === mb_strtolower( $v ) ) {
			return $code;
		}
	}
	return 'other';
}

/** HTML select для власних форм (модуль запису). */
function bp_attr_self_reported_select( $name = 'bp_attr[self_reported]' ) {
	$html = '<select name="' . esc_attr( $name ) . '" class="bp-attr-self-reported">';
	$html .= '<option value="">' . esc_html__( 'Звідки ви дізналися про нас?', 'bp-attribution' ) . '</option>';
	foreach ( bp_attr_self_reported_options() as $code => $label ) {
		$html .= '<option value="' . esc_attr( $code ) . '">' . esc_html( $label ) . '</option>';
	}
	return $html . '</select>';
}

/* ------------------------------------------------------------------ */
/* Збереження заявки                                                   */
/* ------------------------------------------------------------------ */

/**
 * Зберігає заявку в wp_bp_leads.
 *
 * @param array $attr Поля атрибуції (bp_attr_collect()).
 * @param array $lead form_type, form_id, form_name, fields (масив полів форми).
 * @return int ID запису або 0.
 */
function bp_attr_save_lead( array $attr, array $lead = array() ) {
	global $wpdb;
	$attr = bp_attr_sanitize_fields( $attr );
	$row  = array(
		'created_at' => current_time( 'mysql', true ),
		'form_type'  => sanitize_key( $lead['form_type'] ?? 'generic' ),
		'form_id'    => mb_substr( sanitize_text_field( $lead['form_id'] ?? $attr['form_id'] ), 0, 150 ),
		'form_name'  => mb_substr( sanitize_text_field( $lead['form_name'] ?? '' ), 0, 191 ),
		'fields'     => wp_json_encode( $lead['fields'] ?? array(), JSON_UNESCAPED_UNICODE ),
	);
	$row  = array_merge( $row, bp_attr_db_values( $attr ) );
	$row  = apply_filters( 'bp_attr_lead_row', $row, $lead );
	$ok   = $wpdb->insert( bp_attr_table(), $row );
	$id   = $ok ? (int) $wpdb->insert_id : 0;
	do_action( 'bp_attr_lead_saved', $id, $row, $lead );
	return $id;
}

/** Значення колонок атрибуції для INSERT/UPDATE. */
function bp_attr_db_values( array $attr ) {
	$vals = array();
	foreach ( bp_attr_field_keys() as $k ) {
		$vals[ $k ] = $attr[ $k ] ?? '';
	}
	foreach ( array( 'ft_ts', 'lt_ts' ) as $k ) {
		$ts         = $vals[ $k ] ? strtotime( $vals[ $k ] ) : false;
		$vals[ $k ] = $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : null;
	}
	$vals['touch_count']     = (int) $vals['touch_count'];
	$vals['days_to_convert'] = '' === $vals['days_to_convert'] ? null : (int) $vals['days_to_convert'];
	$vals['paid_in_path']    = (int) $vals['paid_in_path'];
	return $vals;
}

/* ------------------------------------------------------------------ */
/* Лист адміністратору                                                 */
/* ------------------------------------------------------------------ */

function bp_attr_touch_label( $source, $medium, $campaign = '' ) {
	if ( '' === $source && '' === $medium ) {
		return 'невідомо';
	}
	$label = ( '(direct)' === $source && '(none)' === $medium ) ? 'direct' : $source . '/' . $medium;
	return $campaign ? $label . ' (' . $campaign . ')' : $label;
}

/** "Перше джерело: google/cpc (кампанія) | Останнє: google/organic | Дотиків: 3" */
function bp_attr_email_line( array $a ) {
	$line = sprintf(
		'Перше джерело: %s | Останнє: %s | Дотиків: %d',
		bp_attr_touch_label( $a['ft_source'] ?? '', $a['ft_medium'] ?? '', $a['ft_campaign'] ?? '' ),
		bp_attr_touch_label( $a['lt_source'] ?? '', $a['lt_medium'] ?? '', $a['lt_campaign'] ?? '' ),
		(int) ( $a['touch_count'] ?? 0 )
	);
	$opts = bp_attr_self_reported_options();
	if ( ! empty( $a['self_reported'] ) && isset( $opts[ $a['self_reported'] ] ) ) {
		$line .= ' | Сам вказав: ' . $opts[ $a['self_reported'] ];
	}
	return apply_filters( 'bp_attr_email_line', $line, $a );
}

/* ------------------------------------------------------------------ */
/* Категорії звіту                                                     */
/* ------------------------------------------------------------------ */

function bp_attr_categories() {
	return array(
		'paid_last'     => 'Платна реклама (last touch)',
		'paid_assisted' => 'Платна реклама (асистована)',
		'organic'       => 'Органічний пошук',
		'maps'          => 'Google Карти',
		'social'        => 'Соцмережі (органіка)',
		'referral'      => 'Реферали',
		'direct'        => 'Прямі',
		'other'         => 'Інші мітки UTM (email, месенджери тощо)',
	);
}

/**
 * Одна категорія на заявку. Правила - див. bp_attr_category_rules().
 */
function bp_attr_category( array $r ) {
	$lt   = (string) ( $r['lt_medium'] ?? '' );
	$paid = bp_attr_paid_mediums();
	if ( in_array( $lt, $paid, true ) ) {
		return 'paid_last';
	}
	if ( ! empty( $r['paid_in_path'] ) ) {
		return 'paid_assisted';
	}
	switch ( $lt ) {
		case 'organic':
			return 'organic';
		case 'maps':
		case 'gbp':
			return 'maps';
		case 'social':
			return 'social';
		case 'referral':
			return 'referral';
		case '(none)':
		case '':
			return 'direct';
	}
	return 'other';
}

function bp_attr_category_rules() {
	return array(
		'Платна реклама (last touch)'  => 'останнє непряме джерело має платний канал: utm_medium = cpc / paid_social / display / video, або перехід з gclid / gbraid / wbraid (Google Ads) чи msclkid (Microsoft Ads).',
		'Платна реклама (асистована)' => 'останнє джерело не платне, але в шляху пацієнта був хоча б один платний дотик (paid_in_path = 1). Реклама привела людину раніше, а заявку вона залишила після повернення через пошук, карти, соцмережі або напряму.',
		'Органічний пошук'             => 'останнє джерело - перехід із Google / Bing / DuckDuckGo / Yahoo без рекламних міток; платних дотиків у шляху немає.',
		'Google Карти'                 => 'перехід з Google Maps (maps.app.goo.gl, google.com/maps) або з профілю Google Business з міткою utm_medium=gbp; платних дотиків немає.',
		'Соцмережі (органіка)'         => 'перехід з Facebook, Instagram, TikTok, LinkedIn, X без позначки платної реклами (fbclid без utm - це органіка); платних дотиків немає.',
		'Реферали'                     => 'перехід з інших сайтів (каталоги клінік, статті, партнери); платних дотиків немає.',
		'Прямі'                        => 'за весь період (до 90 днів) пацієнт жодного разу не прийшов із зовнішнього джерела - лише набирав адресу, закладки тощо. Прямий захід ніколи не "перебиває" попереднє джерело.',
		'Інші мітки UTM'               => 'посилання з UTM-мітками інших каналів (email, месенджери, QR тощо) без платних дотиків у шляху.',
	);
}
