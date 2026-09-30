<?php
/**
 * Модуль "Кліки по телефону": конфіг phones.json, обгортання текстових номерів у tel:,
 * REST /bp/v1/phone-click і /bp/v1/binotel-calls, таблиці wp_bp_phone_clicks і wp_bp_binotel_calls.
 */

defined( 'ABSPATH' ) || exit;

/* ------------------------------------------------------------------ */
/* Конфіг                                                              */
/* ------------------------------------------------------------------ */

/**
 * phones.json плагіна + фільтр bp_phone_config.
 */
function bp_phone_config() {
	static $cfg = null;
	if ( null === $cfg ) {
		$json = file_get_contents( BP_ATTR_DIR . 'phones.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$cfg  = json_decode( (string) $json, true );
		$cfg  = apply_filters( 'bp_phone_config', is_array( $cfg ) ? $cfg : array( 'phones' => array() ) );
	}
	return $cfg;
}

/** Будь-який запис номера -> E.164 ('' якщо не номер). Та сама логіка, що normalize() у phone-core.js. */
function bp_phone_normalize( $raw ) {
	$raw = rawurldecode( (string) $raw );
	$raw = preg_split( '/[;,?]/', preg_replace( '/^tel:/i', '', $raw ) )[0];
	$d   = preg_replace( '/\D/', '', $raw );
	if ( preg_match( '/^380\d{9}$/', $d ) ) {
		return '+' . $d;
	}
	if ( preg_match( '/^80\d{9}$/', $d ) ) {
		return '+3' . $d;
	}
	if ( preg_match( '/^0\d{9}$/', $d ) ) {
		return '+38' . $d;
	}
	if ( preg_match( '/^\s*\+/', $raw ) && strlen( $d ) >= 8 && strlen( $d ) <= 15 ) {
		return '+' . $d;
	}
	return '';
}

/** E.164 -> ['phone_label', 'location', 'known']. */
function bp_phone_lookup( $e164 ) {
	foreach ( (array) ( bp_phone_config()['phones'] ?? array() ) as $p ) {
		if ( bp_phone_normalize( $p['number'] ?? '' ) === $e164 ) {
			return array( 'phone_label' => (string) $p['label'], 'location' => (string) $p['location'], 'known' => true );
		}
	}
	return array( 'phone_label' => $e164, 'location' => 'unknown', 'known' => false );
}

function bp_phone_locations() {
	$out = array();
	foreach ( (array) ( bp_phone_config()['phones'] ?? array() ) as $p ) {
		$out[ $p['location'] ] = $p['label'] . ( ! empty( $p['address'] ) ? ' (' . $p['address'] . ')' : '' );
	}
	return $out + array( 'unknown' => 'Невідомий номер' );
}

/* ------------------------------------------------------------------ */
/* Фронтенд                                                            */
/* ------------------------------------------------------------------ */

add_action( 'wp_enqueue_scripts', 'bp_phone_enqueue', 20 );

function bp_phone_enqueue() {
	$cfg = bp_phone_config();
	$min = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
	wp_enqueue_script( 'bp-phone', BP_ATTR_URL . "assets/dist/bp-phone$min.js", array( 'bp-attribution' ), BP_ATTR_VERSION, true );
	$s  = bp_attr_settings();
	$js = array(
		'phones'               => array_map(
			static function ( $p ) {
				return array( 'number' => $p['number'], 'location' => $p['location'], 'label' => $p['label'] );
			},
			(array) ( $cfg['phones'] ?? array() )
		),
		'track_messengers'     => ! empty( $cfg['track_messengers'] ),
		'dedupe_seconds'       => (int) ( $cfg['dedupe_seconds'] ?? 60 ),
		'session_minutes'      => (int) ( $cfg['session_minutes'] ?? 30 ),
		'doctor_path_prefixes' => (array) ( $cfg['doctor_path_prefixes'] ?? array() ),
		'doctor_specialty'     => (object) ( $cfg['doctor_specialty'] ?? array() ),
		'page_types'           => (object) ( $cfg['page_types'] ?? array() ),
		'endpoint'             => rest_url( 'bp/v1/phone-click' ),
		'ajax'                 => admin_url( 'admin-ajax.php?action=bp_phone_click' ), // резерв, якщо REST заблоковано
		'version'              => BP_ATTR_VERSION,
		'domain'               => $s['site_domain'],
		'excludeReferrers'     => bp_attr_lines( $s['exclude_referrers'] ),
		'page'                 => (object) bp_phone_page_hint(),
	);
	wp_add_inline_script( 'bp-phone', 'window.bpPhoneConfig=' . wp_json_encode( $js ) . ';', 'before' );
}

/* ---------------- Виключення з оптимізаторів JS ---------------- */

/**
 * Скрипти трекінгу мають виконуватися одразу: якщо оптимізатор відкладає їх "до першої взаємодії"
 * (WP Rocket Delay JS, LiteSpeed Delay, Cloudflare Rocket Loader…), перший тап по номеру не фіксується.
 */
function bp_attr_script_handles() {
	return array( 'bp-attribution', 'bp-phone' );
}

add_filter(
	'script_loader_tag',
	static function ( $tag, $handle ) {
		if ( in_array( $handle, bp_attr_script_handles(), true ) ) {
			// $tag містить і inline-конфіг (window.bpAttrConfig / bpPhoneConfig), і сам файл
			$tag = str_replace( '<script ', '<script data-no-optimize="1" data-no-defer="1" data-no-minify="1" data-no-delay="1" data-cfasync="false" data-pagespeed-no-defer ', $tag );
		}
		return $tag;
	},
	20,
	2
);

$bp_attr_opt_patterns = array( 'bp-attribution', 'bp-phone', 'bpAttrConfig', 'bpPhoneConfig' );
// WP Rocket: Delay JS, Defer, мініфікація/об'єднання.
foreach ( array( 'rocket_delay_js_exclusions', 'rocket_exclude_defer_js', 'rocket_exclude_js', 'rocket_exclude_inline_js', 'rocket_minify_excluded_external_js' ) as $bp_attr_f ) {
	add_filter(
		$bp_attr_f,
		static function ( $list ) use ( $bp_attr_opt_patterns ) {
			return array_merge( (array) $list, $bp_attr_opt_patterns );
		}
	);
}
// LiteSpeed Cache.
foreach ( array( 'litespeed_optm_js_defer_exc', 'litespeed_optimize_js_excludes', 'litespeed_optm_gm_js_exc' ) as $bp_attr_f ) {
	add_filter(
		$bp_attr_f,
		static function ( $list ) use ( $bp_attr_opt_patterns ) {
			return array_merge( (array) $list, $bp_attr_opt_patterns );
		}
	);
}
// SiteGround Optimizer (очікує handle-и).
foreach ( array( 'sgo_js_minify_exclude', 'sgo_javascript_combine_exclude', 'sgo_js_async_exclude' ) as $bp_attr_f ) {
	add_filter(
		$bp_attr_f,
		static function ( $list ) {
			return array_merge( (array) $list, bp_attr_script_handles() );
		}
	);
}
// Autoptimize (рядок через кому).
add_filter(
	'autoptimize_filter_js_exclude',
	static function ( $list ) use ( $bp_attr_opt_patterns ) {
		return trim( (string) $list . ', ' . implode( ', ', $bp_attr_opt_patterns ), ', ' );
	}
);
unset( $bp_attr_f );

/**
 * Підказка про поточну сторінку для JS (напр. сторінка лікаря - CPT з довільним URL).
 * Фільтр bp_phone_page_context дозволяє задати page_type / specialty / doctor_slug з теми або ACF.
 */
function bp_phone_page_hint() {
	$hint = array();
	if ( is_singular() ) {
		$post = get_queried_object();
		if ( $post && in_array( $post->post_type, apply_filters( 'bp_phone_doctor_post_types', array( 'doctor', 'doctors', 'vrach', 'likar', 'specialist' ) ), true ) ) {
			$hint['page_type']   = 'doctor';
			$hint['doctor_slug'] = $post->post_name;
			$map                 = (array) ( bp_phone_config()['doctor_specialty'] ?? array() );
			if ( isset( $map[ $post->post_name ] ) ) {
				$hint['specialty'] = $map[ $post->post_name ];
			}
		}
	}
	return apply_filters( 'bp_phone_page_context', $hint );
}

/* ---------------- Обгортання текстових номерів у <a href="tel:"> ---------------- */

/** Роздільник між цифрами: пробіл, nbsp (у т.ч. як сутність), дефіс/тире, крапка, дужки. */
function bp_phone_regex() {
	$sep = '(?:[\s\-.()\x{00A0}\x{2010}-\x{2013}]|&nbsp;|&#160;|&#8209;)';
	return '/(^|[^\d+])((?:\+?\s?3\s?8' . $sep . '{0,2})?\(?0(?:' . $sep . '{0,2}\d){9})(?!\d)/u';
}

/**
 * Обгортає номери з конфігу, що стоять у HTML текстом, у посилання tel:.
 * Не чіпає атрибути тегів, вміст існуючих <a>, script/style/textarea/select/button тощо.
 */
function bp_phone_wrap_html( $html ) {
	if ( ! is_string( $html ) || '' === $html || empty( bp_phone_config()['wrap_text_numbers'] ) || ! preg_match( '/\d(?:[^<>\d]{0,8}\d){9}/', $html ) ) {
		return $html;
	}
	$parts = preg_split( '/(<!--.*?-->|<[^>]*>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
	if ( ! $parts ) {
		return $html;
	}
	$skip  = 0;
	$skips = 'a|script|style|textarea|select|option|button|title|head|svg|noscript|code|pre|label';
	$re    = bp_phone_regex();
	foreach ( $parts as $i => $part ) {
		if ( '<' === $part[0] ) {
			if ( preg_match( '#^<(/?)(' . $skips . ')\b#i', $part, $m ) && '/>' !== substr( $part, -2 ) ) {
				$skip = max( 0, $skip + ( '/' === $m[1] ? -1 : 1 ) );
			}
			continue;
		}
		if ( $skip ) {
			continue;
		}
		$parts[ $i ] = preg_replace_callback(
			$re,
			static function ( $m ) {
				$e164 = bp_phone_normalize( html_entity_decode( $m[2], ENT_QUOTES, 'UTF-8' ) );
				if ( ! $e164 || ! bp_phone_lookup( $e164 )['known'] ) {
					return $m[0];
				}
				return $m[1] . '<a href="tel:' . esc_attr( $e164 ) . '" class="bp-phone-link" data-bp-phone-auto="1">' . $m[2] . '</a>';
			},
			$part
		);
	}
	return implode( '', $parts );
}

foreach ( array( 'the_content', 'widget_text_content', 'widget_block_content' ) as $bp_phone_hook ) {
	add_filter( $bp_phone_hook, 'bp_phone_wrap_html', 20 );
}
// Усі віджети Elementor, зокрема в шаблонах хедера, футера і попапів.
add_filter( 'elementor/widget/render_content', 'bp_phone_wrap_html', 20 );
unset( $bp_phone_hook );

/* ------------------------------------------------------------------ */
/* БД                                                                  */
/* ------------------------------------------------------------------ */

function bp_phone_clicks_table() {
	global $wpdb;
	return $wpdb->prefix . 'bp_phone_clicks';
}

function bp_phone_calls_table() {
	global $wpdb;
	return $wpdb->prefix . 'bp_binotel_calls';
}

function bp_phone_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$c      = $wpdb->get_charset_collate();
	$clicks = bp_phone_clicks_table();
	$calls  = bp_phone_calls_table();
	dbDelta(
		"CREATE TABLE $clicks (
id bigint unsigned NOT NULL AUTO_INCREMENT,
event_id varchar(36) NOT NULL,
created_at datetime NOT NULL,
client_ts datetime NULL DEFAULT NULL,
action varchar(10) NOT NULL DEFAULT '',
phone_e164 varchar(16) NOT NULL DEFAULT '',
phone_label varchar(100) NOT NULL DEFAULT '',
location varchar(30) NOT NULL DEFAULT '',
device varchar(10) NOT NULL DEFAULT '',
page_path varchar(255) NOT NULL DEFAULT '',
page_type varchar(12) NOT NULL DEFAULT '',
specialty varchar(100) NOT NULL DEFAULT '',
doctor_slug varchar(100) NOT NULL DEFAULT '',
element varchar(10) NOT NULL DEFAULT '',
ft_source varchar(100) NOT NULL DEFAULT '',
ft_medium varchar(50) NOT NULL DEFAULT '',
ft_campaign varchar(150) NOT NULL DEFAULT '',
lt_source varchar(100) NOT NULL DEFAULT '',
lt_medium varchar(50) NOT NULL DEFAULT '',
lt_campaign varchar(150) NOT NULL DEFAULT '',
lt_term varchar(150) NOT NULL DEFAULT '',
touch_count smallint unsigned NOT NULL DEFAULT 0,
paid_in_path tinyint(1) NOT NULL DEFAULT 0,
gclid varchar(255) NOT NULL DEFAULT '',
gbraid varchar(255) NOT NULL DEFAULT '',
wbraid varchar(255) NOT NULL DEFAULT '',
session_id varchar(64) NOT NULL DEFAULT '',
ip_hash varchar(64) NULL DEFAULT NULL,
call_id varchar(64) NULL DEFAULT NULL,
call_answered tinyint(1) NULL DEFAULT NULL,
call_duration int unsigned NULL DEFAULT NULL,
match_confidence varchar(4) NULL DEFAULT NULL,
PRIMARY KEY  (id),
UNIQUE KEY event_id (event_id),
KEY created_at (created_at),
KEY phone_time (phone_e164,created_at),
KEY session_id (session_id)
) $c;"
	);
	dbDelta(
		"CREATE TABLE $calls (
id bigint unsigned NOT NULL AUTO_INCREMENT,
call_id varchar(64) NOT NULL,
received_at datetime NOT NULL,
dialed_e164 varchar(16) NOT NULL DEFAULT '',
location varchar(30) NOT NULL DEFAULT '',
started_at datetime NOT NULL,
duration_sec int unsigned NOT NULL DEFAULT 0,
answered tinyint(1) NOT NULL DEFAULT 0,
click_id bigint unsigned NULL DEFAULT NULL,
match_confidence varchar(4) NULL DEFAULT NULL,
PRIMARY KEY  (id),
UNIQUE KEY call_id (call_id),
KEY started_at (started_at)
) $c;"
	);
	if ( ! wp_next_scheduled( 'bp_phone_daily' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'bp_phone_daily' );
	}
}
add_action( 'bp_attr_installed', 'bp_phone_install' );

/** Хеш IP видаляється через 30 днів. */
add_action(
	'bp_phone_daily',
	static function () {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . bp_phone_clicks_table() . ' SET ip_hash = NULL WHERE ip_hash IS NOT NULL AND created_at < %s', gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
);

/* ------------------------------------------------------------------ */
/* REST                                                                */
/* ------------------------------------------------------------------ */

add_action( 'rest_api_init', 'bp_phone_rest_routes' );

function bp_phone_rest_routes() {
	register_rest_route(
		'bp/v1',
		'/phone-click',
		array(
			'methods'             => 'POST',
			'callback'            => 'bp_phone_rest_click',
			'permission_callback' => '__return_true', // публічний, без nonce (кешовані сторінки); захист - rate limit + схема
		)
	);
	register_rest_route(
		'bp/v1',
		'/binotel-calls',
		array(
			'methods'             => 'POST',
			'callback'            => 'bp_phone_rest_binotel',
			'permission_callback' => 'bp_phone_binotel_auth',
		)
	);
}

function bp_phone_client_ip() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	// За Cloudflare REMOTE_ADDR - адреса вузла CF, спільна для багатьох відвідувачів; справжня - у CF-Connecting-IP.
	// (IP використовується лише для rate limit і солоного хешу, сам не зберігається.)
	if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
		$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
	}
	return (string) apply_filters( 'bp_phone_client_ip', $ip ); // інший проксі - підставити реальний IP фільтром
}

/** Солений хеш IP (сам IP не зберігається ніде). */
function bp_phone_ip_hash( $ip ) {
	return substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ), 0, 32 );
}

/** 30 запитів/хв на IP. */
function bp_phone_rate_limited( $ip, $limit = 30 ) {
	$key = 'bp_phone_rl_' . substr( bp_phone_ip_hash( $ip . '|' . floor( time() / 60 ) ), 0, 20 );
	$n   = (int) get_transient( $key );
	if ( $n >= apply_filters( 'bp_phone_rate_limit', $limit ) ) {
		return true;
	}
	set_transient( $key, $n + 1, 90 );
	return false;
}

/**
 * Схема події: тип, обмеження. Невідомі поля відкидаються.
 */
function bp_phone_schema() {
	return array(
		'event_id'     => array( 'required' => true, 'pattern' => '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i' ),
		'ts'           => array( 'required' => true, 'pattern' => '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$/' ),
		'action'       => array( 'required' => true, 'enum' => array( 'tap', 'click', 'copy', 'messenger' ) ),
		'phone_e164'   => array( 'pattern' => '/^(\+\d{8,15})?$/' ),
		'phone_label'  => array( 'max' => 100 ),
		'location'     => array( 'max' => 30 ),
		'device'       => array( 'required' => true, 'enum' => array( 'mobile', 'desktop', 'tablet' ) ),
		'page_path'    => array( 'required' => true, 'pattern' => '#^/#', 'max' => 255 ),
		'page_type'    => array( 'required' => true, 'enum' => array( 'home', 'department', 'doctor', 'article', 'contacts', 'promo', 'other' ) ),
		'specialty'    => array( 'max' => 100 ),
		'doctor_slug'  => array( 'max' => 100 ),
		'element'      => array( 'required' => true, 'enum' => array( 'header', 'footer', 'banner', 'popup', 'content', 'sticky' ) ),
		'ft_source'    => array( 'max' => 100 ),
		'ft_medium'    => array( 'max' => 50 ),
		'ft_campaign'  => array( 'max' => 150 ),
		'lt_source'    => array( 'max' => 100 ),
		'lt_medium'    => array( 'max' => 50 ),
		'lt_campaign'  => array( 'max' => 150 ),
		'lt_term'      => array( 'max' => 150 ),
		'touch_count'  => array( 'int' => array( 0, 65535 ) ),
		'paid_in_path' => array( 'int' => array( 0, 1 ) ),
		'gclid'        => array( 'max' => 255 ),
		'gbraid'       => array( 'max' => 255 ),
		'wbraid'       => array( 'max' => 255 ),
		'session_id'   => array( 'required' => true, 'pattern' => '/^[0-9a-f-]{8,64}$/i' ),
	);
}

/**
 * @return array|WP_Error Очищена подія.
 */
function bp_phone_validate( $data ) {
	if ( ! is_array( $data ) ) {
		return new WP_Error( 'bp_phone_invalid', 'JSON object expected', array( 'status' => 400 ) );
	}
	$out = array();
	foreach ( bp_phone_schema() as $k => $rule ) {
		$v = $data[ $k ] ?? null;
		if ( null === $v || '' === $v ) {
			if ( ! empty( $rule['required'] ) ) {
				return new WP_Error( 'bp_phone_invalid', "Missing $k", array( 'status' => 400 ) );
			}
			$out[ $k ] = isset( $rule['int'] ) ? 0 : '';
			continue;
		}
		if ( isset( $rule['int'] ) ) {
			if ( ! is_numeric( $v ) || (int) $v < $rule['int'][0] || (int) $v > $rule['int'][1] ) {
				return new WP_Error( 'bp_phone_invalid', "Bad $k", array( 'status' => 400 ) );
			}
			$out[ $k ] = (int) $v;
			continue;
		}
		if ( ! is_scalar( $v ) ) {
			return new WP_Error( 'bp_phone_invalid', "Bad $k", array( 'status' => 400 ) );
		}
		$v = sanitize_text_field( (string) $v );
		if ( ( isset( $rule['enum'] ) && ! in_array( $v, $rule['enum'], true ) ) ||
			( isset( $rule['pattern'] ) && ! preg_match( $rule['pattern'], $v ) ) ||
			( isset( $rule['max'] ) && mb_strlen( $v ) > $rule['max'] ) ) {
			return new WP_Error( 'bp_phone_invalid', "Bad $k", array( 'status' => 400 ) );
		}
		$out[ $k ] = $v;
	}
	if ( '' === $out['phone_e164'] && 'messenger' !== $out['action'] ) {
		return new WP_Error( 'bp_phone_invalid', 'Missing phone_e164', array( 'status' => 400 ) );
	}
	return $out;
}

function bp_phone_rest_click( WP_REST_Request $req ) {
	$data = $req->get_json_params();
	if ( null === $data ) { // sendBeacon з іншим Content-Type
		$data = json_decode( (string) $req->get_body(), true );
	}
	return bp_phone_handle_click( $data );
}

/**
 * Резервний канал: admin-ajax.php?action=bp_phone_click (тіло - той самий JSON).
 * Потрібен, коли плагін безпеки / WAF / "Disable REST API" блокує /wp-json/ для гостей.
 */
function bp_phone_ajax_click() {
	$res = bp_phone_handle_click( json_decode( (string) file_get_contents( 'php://input' ), true ) );
	if ( is_wp_error( $res ) ) {
		wp_send_json( array( 'code' => $res->get_error_code(), 'message' => $res->get_error_message() ), (int) ( $res->get_error_data()['status'] ?? 400 ) );
	}
	wp_send_json( $res->get_data() );
}
add_action( 'wp_ajax_nopriv_bp_phone_click', 'bp_phone_ajax_click' );
add_action( 'wp_ajax_bp_phone_click', 'bp_phone_ajax_click' );

/**
 * Спільна обробка події кліку (REST і admin-ajax).
 *
 * @return WP_REST_Response|WP_Error
 */
function bp_phone_handle_click( $data ) {
	global $wpdb;
	$ip = bp_phone_client_ip();
	if ( bp_phone_rate_limited( $ip ) ) {
		return new WP_Error( 'bp_phone_rate_limited', 'Too many requests', array( 'status' => 429 ) );
	}
	$ev = bp_phone_validate( $data );
	if ( is_wp_error( $ev ) ) {
		return $ev;
	}

	// Мітку і локацію визначає сервер за конфігом (клієнту не довіряємо).
	if ( $ev['phone_e164'] ) {
		$info              = bp_phone_lookup( $ev['phone_e164'] );
		$ev['phone_label'] = $info['phone_label'];
		$ev['location']    = $info['location'];
		if ( ! $info['known'] ) {
			bp_phone_log_unknown( $ev['phone_e164'], $ev['page_path'] );
		}
	} else {
		$ev['location'] = 'unknown';
	}

	$table  = bp_phone_clicks_table();
	$now    = time();
	$window = (int) ( bp_phone_config()['dedupe_seconds'] ?? 60 );
	$dup    = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM $table WHERE (event_id = %s) OR (session_id = %s AND phone_e164 = %s AND phone_label = %s AND created_at >= %s) LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
			$ev['event_id'],
			$ev['session_id'],
			$ev['phone_e164'],
			$ev['phone_label'],
			gmdate( 'Y-m-d H:i:s', $now - $window )
		)
	);
	if ( $dup ) {
		return rest_ensure_response( array( 'ok' => true, 'duplicate' => true ) );
	}
	$ts  = strtotime( $ev['ts'] );
	$row = array_merge(
		$ev,
		array(
			'created_at' => gmdate( 'Y-m-d H:i:s', $now ),
			'client_ts'  => $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : null,
			'ip_hash'    => bp_phone_ip_hash( $ip ),
		)
	);
	unset( $row['ts'] );
	$wpdb->insert( $table, $row );
	do_action( 'bp_phone_click_saved', (int) $wpdb->insert_id, $row );
	return rest_ensure_response( array( 'ok' => true ) );
}

/** Невідомі номери: лог + список в адмінці, щоб поповнити phones.json. */
function bp_phone_log_unknown( $e164, $path ) {
	$list          = get_option( 'bp_phone_unknown', array() );
	$list          = is_array( $list ) ? $list : array();
	$item          = $list[ $e164 ] ?? array( 'count' => 0 );
	$item['count'] = (int) $item['count'] + 1;
	$item['last']  = gmdate( 'Y-m-d H:i:s' );
	$item['page']  = $path;
	$list[ $e164 ] = $item;
	update_option( 'bp_phone_unknown', array_slice( $list, -100, null, true ), false );
	error_log( "[bp-phone] unknown phone number $e164 on $path - add it to phones.json" ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
}

/* ---------------- Binotel (Make.com) ---------------- */

function bp_phone_binotel_secret() {
	return defined( 'BP_BINOTEL_SECRET' ) ? (string) BP_BINOTEL_SECRET : (string) get_option( 'bp_phone_binotel_secret', '' );
}

function bp_phone_binotel_enabled() {
	return strlen( bp_phone_binotel_secret() ) >= 16;
}

function bp_phone_binotel_auth( WP_REST_Request $req ) {
	$given = (string) $req->get_header( 'x-bp-secret' );
	if ( ! bp_phone_binotel_enabled() || '' === $given || ! hash_equals( bp_phone_binotel_secret(), $given ) ) {
		return new WP_Error( 'bp_phone_forbidden', 'Forbidden', array( 'status' => 403 ) );
	}
	return true;
}

/** Дата з Binotel/Make: ISO 8601 з поясом або 'Y-m-d H:i:s' у часовому поясі сайту, або unix timestamp. */
function bp_phone_parse_time( $v ) {
	if ( is_numeric( $v ) ) {
		return (int) $v;
	}
	try {
		$dt = new DateTimeImmutable( (string) $v, wp_timezone() );
		return $dt->getTimestamp();
	} catch ( Exception $e ) {
		return 0;
	}
}

function bp_phone_rest_binotel( WP_REST_Request $req ) {
	$data  = $req->get_json_params();
	$calls = isset( $data['calls'] ) ? $data['calls'] : ( isset( $data['call_id'] ) ? array( $data ) : $data );
	if ( ! is_array( $calls ) ) {
		return new WP_Error( 'bp_phone_invalid', 'Expected call object or array', array( 'status' => 400 ) );
	}
	$res = array( 'received' => 0, 'matched' => 0, 'errors' => array() );
	foreach ( array_slice( $calls, 0, 500 ) as $i => $call ) {
		$r = bp_phone_save_call( $call );
		if ( is_wp_error( $r ) ) {
			$res['errors'][] = array( 'index' => $i, 'error' => $r->get_error_message() );
			continue;
		}
		++$res['received'];
		$res['matched'] += $r ? 1 : 0;
	}
	return rest_ensure_response( $res );
}

/**
 * Зберігає дзвінок (лише call_id, набраний номер клініки, час, тривалість, статус -
 * номер абонента НЕ зберігається) і зіставляє його з кліком.
 *
 * @return bool|WP_Error true - зіставлено.
 */
function bp_phone_save_call( $call ) {
	global $wpdb;
	if ( ! is_array( $call ) ) {
		return new WP_Error( 'bad', 'not an object' );
	}
	$call_id = sanitize_text_field( (string) ( $call['call_id'] ?? '' ) );
	$e164    = bp_phone_normalize( $call['dialed_number'] ?? '' );
	$started = bp_phone_parse_time( $call['started_at'] ?? '' );
	if ( '' === $call_id || strlen( $call_id ) > 64 || ! $e164 || ! $started ) {
		return new WP_Error( 'bad', 'call_id, dialed_number and started_at are required' );
	}
	$answered = filter_var( $call['answered'] ?? false, FILTER_VALIDATE_BOOLEAN ) ? 1 : 0;
	$row      = array(
		'call_id'      => $call_id,
		'received_at'  => gmdate( 'Y-m-d H:i:s' ),
		'dialed_e164'  => $e164,
		'location'     => bp_phone_lookup( $e164 )['location'],
		'started_at'   => gmdate( 'Y-m-d H:i:s', $started ),
		'duration_sec' => max( 0, (int) ( $call['duration_sec'] ?? 0 ) ),
		'answered'     => $answered,
	);
	$calls    = bp_phone_calls_table();
	$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, click_id FROM $calls WHERE call_id = %s", $call_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	if ( $existing ) {
		$wpdb->update( $calls, $row, array( 'id' => $existing['id'] ) );
		if ( $existing['click_id'] ) { // оновлюємо статус уже зіставленого кліку
			$wpdb->update( bp_phone_clicks_table(), array( 'call_answered' => $answered, 'call_duration' => $row['duration_sec'] ), array( 'id' => $existing['click_id'] ) );
			return true;
		}
		$call_row_id = (int) $existing['id'];
	} else {
		$wpdb->insert( $calls, $row );
		$call_row_id = (int) $wpdb->insert_id;
	}
	return bp_phone_match_call( $call_row_id, $call_id, $e164, $started, $answered, $row['duration_sec'] );
}

/**
 * Зіставлення: той самий номер + клік за 0..180 с ДО початку дзвінка; найближчий за часом.
 * match_confidence: high - один кандидат, low - кілька.
 */
function bp_phone_match_call( $call_row_id, $call_id, $e164, $started, $answered, $duration ) {
	global $wpdb;
	$clicks     = bp_phone_clicks_table();
	$window     = (int) ( bp_phone_config()['binotel_match_window_sec'] ?? 180 );
	$candidates = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT id FROM $clicks WHERE phone_e164 = %s AND call_id IS NULL AND action IN ('tap','click','copy') AND created_at BETWEEN %s AND %s ORDER BY created_at DESC, id DESC", // phpcs:ignore WordPress.DB.PreparedSQL
			$e164,
			gmdate( 'Y-m-d H:i:s', $started - $window ),
			gmdate( 'Y-m-d H:i:s', $started )
		)
	);
	if ( ! $candidates ) {
		return false;
	}
	$confidence = 1 === count( $candidates ) ? 'high' : 'low';
	$click_id   = (int) $candidates[0];
	$wpdb->update(
		$clicks,
		array( 'call_id' => $call_id, 'call_answered' => $answered, 'call_duration' => $duration, 'match_confidence' => $confidence ),
		array( 'id' => $click_id )
	);
	$wpdb->update( bp_phone_calls_table(), array( 'click_id' => $click_id, 'match_confidence' => $confidence ), array( 'id' => $call_row_id ) );
	return true;
}
