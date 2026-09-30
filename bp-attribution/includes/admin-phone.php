<?php
/**
 * Адмінка модуля телефонів: звіт "Кліки по телефону", CSV, сканер "Номери на сайті".
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'bp_phone_admin_menu', 20 );
add_action( 'admin_post_bp_phone_csv', 'bp_phone_export_csv' );
add_action( 'wp_ajax_bp_phone_scan_urls', 'bp_phone_ajax_scan_urls' );
add_action( 'wp_ajax_bp_phone_scan', 'bp_phone_ajax_scan' );

function bp_phone_admin_menu() {
	$cap = bp_attr_settings()['capability'];
	add_submenu_page( 'bp-attribution', 'Кліки по телефону', 'Кліки по телефону', $cap, 'bp-phone-clicks', 'bp_phone_render_report' );
	add_submenu_page( 'bp-attribution', 'Номери на сайті', 'Номери на сайті', 'manage_options', 'bp-phone-scan', 'bp_phone_render_scan' );
}

/* ------------------------------------------------------------------ */
/* Дані                                                                */
/* ------------------------------------------------------------------ */

function bp_phone_filters() {
	// phpcs:disable WordPress.Security.NonceVerification
	$get  = wp_unslash( $_GET );
	$date = static function ( $v, $def ) {
		return is_string( $v ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : $def;
	};
	return array(
		'from'     => $date( $get['from'] ?? '', wp_date( 'Y-m-d', strtotime( '-30 days' ) ) ),
		'to'       => $date( $get['to'] ?? '', wp_date( 'Y-m-d' ) ),
		'location' => sanitize_key( $get['location'] ?? '' ),
		'device'   => in_array( $get['device'] ?? '', array( 'mobile', 'desktop', 'tablet' ), true ) ? $get['device'] : '',
	);
	// phpcs:enable
}

function bp_phone_rows( array $f ) {
	global $wpdb;
	$where = array( 'created_at BETWEEN %s AND %s' );
	$args  = array( get_gmt_from_date( $f['from'] . ' 00:00:00' ), get_gmt_from_date( $f['to'] . ' 23:59:59' ) );
	foreach ( array( 'location', 'device' ) as $k ) {
		if ( '' !== $f[ $k ] ) {
			$where[] = "$k = %s";
			$args[]  = $f[ $k ];
		}
	}
	$sql  = 'SELECT * FROM ' . bp_phone_clicks_table() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY created_at DESC LIMIT 50000';
	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	foreach ( $rows as &$r ) {
		$local        = get_date_from_gmt( $r['created_at'], 'Y-m-d H:i:s' );
		$ts           = strtotime( $local );
		$r['local']   = $local;
		$r['day']     = substr( $local, 0, 10 );
		$r['wday']    = (int) gmdate( 'N', $ts ); // 1 = Пн
		$r['hour']    = (int) gmdate( 'G', $ts );
		$r['off']     = bp_phone_is_off_hours( $r['wday'], $r['hour'] );
		$r['src']     = bp_attr_touch_label( $r['lt_source'], $r['lt_medium'] );
		$r['has_call'] = null !== $r['call_id'] && '' !== $r['call_id'];
	}
	return $rows;
}

/** Неробочий час за phones.json working_hours (за замовчуванням: Пн-Пт 8-18, Сб 8-15, Нд вихідний). */
function bp_phone_is_off_hours( $wday, $hour ) {
	$wh  = (array) ( bp_phone_config()['working_hours'] ?? array() );
	$key = $wday <= 5 ? 'weekdays' : ( 6 === $wday ? 'saturday' : 'sunday' );
	$win = $wh[ $key ] ?? null;
	if ( ! is_array( $win ) || count( $win ) < 2 ) {
		return true;
	}
	return $hour < (int) $win[0] || $hour >= (int) $win[1];
}

/**
 * Групування: [ключ => ['n','paid','calls','answered','dur_sum','dur_n']].
 */
function bp_phone_group( array $rows, $key ) {
	$out = array();
	foreach ( $rows as $r ) {
		$k = is_callable( $key ) ? $key( $r ) : ( '' !== (string) $r[ $key ] ? $r[ $key ] : '—' );
		if ( ! isset( $out[ $k ] ) ) {
			$out[ $k ] = array( 'n' => 0, 'paid' => 0, 'calls' => 0, 'answered' => 0, 'dur_sum' => 0, 'dur_n' => 0 );
		}
		$g = &$out[ $k ];
		++$g['n'];
		$g['paid'] += (int) $r['paid_in_path'];
		if ( $r['has_call'] ) {
			++$g['calls'];
			if ( (int) $r['call_answered'] ) {
				++$g['answered'];
				$g['dur_sum'] += (int) $r['call_duration'];
				++$g['dur_n'];
			}
		}
		unset( $g );
	}
	uasort(
		$out,
		static function ( $a, $b ) {
			return $b['n'] <=> $a['n'];
		}
	);
	return $out;
}

/* ------------------------------------------------------------------ */
/* Звіт                                                                */
/* ------------------------------------------------------------------ */

function bp_phone_table( $title, array $groups, $total, $labels = array(), $binotel = false ) {
	?>
	<h2><?php echo esc_html( $title ); ?></h2>
	<table class="widefat striped bp-phone-t">
		<thead><tr><th></th><th class="num">Кліків</th><th class="num">%</th><th class="num">З платним дотиком</th>
			<?php if ( $binotel ) : ?><th class="num">→ дзвінок</th><th class="num">Відповіли</th><th class="num">Сер. тривалість, с</th><?php endif; ?>
		</tr></thead>
		<tbody>
		<?php foreach ( $groups as $k => $g ) : ?>
			<tr>
				<td><?php echo esc_html( $labels[ $k ] ?? $k ); ?></td>
				<td class="num"><?php echo (int) $g['n']; ?></td>
				<td class="num"><?php echo esc_html( bp_attr_pct( $g['n'], $total ) ); ?></td>
				<td class="num"><?php echo esc_html( bp_attr_pct( $g['paid'], $g['n'] ) ); ?></td>
				<?php if ( $binotel ) : ?>
					<td class="num"><?php echo esc_html( bp_attr_pct( $g['calls'], $g['n'] ) ); ?></td>
					<td class="num"><?php echo esc_html( bp_attr_pct( $g['answered'], $g['n'] ) ); ?></td>
					<td class="num"><?php echo $g['dur_n'] ? (int) round( $g['dur_sum'] / $g['dur_n'] ) : '—'; ?></td>
				<?php endif; ?>
			</tr>
		<?php endforeach; ?>
		<?php if ( ! $groups ) : ?><tr><td colspan="<?php echo $binotel ? 7 : 4; ?>">Немає даних за обраний період.</td></tr><?php endif; ?>
		</tbody>
	</table>
	<?php
}

/** Стовпчикова діаграма по днях (inline SVG, одна серія). */
function bp_phone_chart_days( array $rows, $from, $to ) {
	$days = array();
	for ( $t = strtotime( $from ); $t <= strtotime( $to ); $t += DAY_IN_SECONDS ) {
		$days[ gmdate( 'Y-m-d', $t ) ] = 0;
	}
	foreach ( $rows as $r ) {
		if ( isset( $days[ $r['day'] ] ) ) {
			++$days[ $r['day'] ];
		}
	}
	$n    = max( 1, count( $days ) );
	$max  = max( 1, max( $days ) );
	$w    = 900;
	$h    = 180;
	$pad  = 28;
	$slot = ( $w - $pad ) / $n;
	$bw   = max( 2, $slot - 2 ); // 2px зазор між стовпчиками
	echo '<svg class="bp-days" viewBox="0 0 ' . (int) $w . ' ' . (int) ( $h + 24 ) . '" role="img" aria-label="Кліки по днях">';
	foreach ( array( 0, 0.5, 1 ) as $f ) { // рецесивна сітка
		$y = $h - $f * ( $h - 10 );
		echo '<line x1="' . (int) $pad . '" x2="' . (int) $w . '" y1="' . esc_attr( $y ) . '" y2="' . esc_attr( $y ) . '" class="grid"/>';
		echo '<text x="' . (int) ( $pad - 6 ) . '" y="' . esc_attr( $y + 4 ) . '" text-anchor="end" class="tick">' . (int) round( $f * $max ) . '</text>';
	}
	$i    = 0;
	$step = max( 1, (int) ceil( $n / 10 ) );
	foreach ( $days as $day => $c ) {
		$x   = $pad + $i * $slot + 1;
		$bh  = $c ? max( 2, ( $h - 10 ) * $c / $max ) : 0;
		$dow = (int) gmdate( 'N', strtotime( $day ) );
		echo '<g><rect class="hit" x="' . esc_attr( $x ) . '" y="0" width="' . esc_attr( $slot ) . '" height="' . (int) $h . '"/>';
		if ( $bh ) {
			$r = min( 4, $bw / 2, $bh );
			// скруглений лише верх, низ прикріплено до базової лінії
			printf(
				'<path class="bar" d="M%1$s,%2$s v-%3$s q0,-%4$s %4$s,-%4$s h%5$s q%4$s,0 %4$s,%4$s v%3$s z"/>',
				esc_attr( $x ),
				esc_attr( $h ),
				esc_attr( $bh - $r ),
				esc_attr( $r ),
				esc_attr( $bw - 2 * $r )
			);
		}
		echo '<title>' . esc_html( wp_date( 'D, d.m', strtotime( $day . ' 12:00' ) ) . ': ' . $c . ' кліків' ) . '</title></g>';
		if ( 0 === $i % $step ) {
			echo '<text x="' . esc_attr( $x + $bw / 2 ) . '" y="' . (int) ( $h + 16 ) . '" text-anchor="middle" class="tick' . ( $dow >= 6 ? ' we' : '' ) . '">' . esc_html( gmdate( 'd.m', strtotime( $day ) ) ) . '</text>';
		}
		++$i;
	}
	echo '</svg>';
}

/** Теплова карта день тижня × година; неробочий час - штрихування (не лише колір). */
function bp_phone_chart_hours( array $rows ) {
	$grid = array_fill( 1, 7, array_fill( 0, 24, 0 ) );
	foreach ( $rows as $r ) {
		++$grid[ $r['wday'] ][ $r['hour'] ];
	}
	$max   = max( 1, max( array_map( 'max', $grid ) ) );
	$ramp  = array( '#cde2fb', '#9ec5f4', '#6da7ec', '#3987e5', '#256abf', '#184f95', '#0d366b' );
	$names = array( 1 => 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Нд' );
	echo '<table class="bp-heat" role="grid" aria-label="Кліки за днем тижня і годиною"><thead><tr><th></th>';
	for ( $hr = 0; $hr < 24; $hr++ ) {
		echo '<th>' . (int) $hr . '</th>';
	}
	echo '</tr></thead><tbody>';
	foreach ( $grid as $d => $hours ) {
		echo '<tr><th>' . esc_html( $names[ $d ] ) . '</th>';
		foreach ( $hours as $hr => $c ) {
			$off   = bp_phone_is_off_hours( $d, $hr );
			$idx   = $c ? min( 6, (int) floor( 6 * $c / $max ) ) : -1;
			$style = $idx >= 0 ? 'background-color:' . $ramp[ $idx ] . ';color:' . ( $idx >= 3 ? '#fff' : '#1d2327' ) : '';
			$tip   = sprintf( '%s %02d:00-%02d:59: %d кліків%s', $names[ $d ], $hr, $hr, $c, $off ? ' (неробочий час)' : '' );
			echo '<td class="' . ( $off ? 'off' : '' ) . '" style="' . esc_attr( $style ) . '" title="' . esc_attr( $tip ) . '">' . ( $c ? (int) $c : '' ) . '</td>';
		}
		echo '</tr>';
	}
	echo '</tbody></table>';
}

function bp_phone_render_report() {
	if ( ! current_user_can( bp_attr_settings()['capability'] ) ) {
		return;
	}
	$f       = bp_phone_filters();
	$rows    = bp_phone_rows( $f );
	$total   = count( $rows );
	$binotel = bp_phone_binotel_enabled() || array_filter( array_column( $rows, 'has_call' ) );
	$all     = bp_phone_group( $rows, static function () {
		return 'all';
	} )['all'] ?? array( 'n' => 0, 'paid' => 0, 'calls' => 0, 'answered' => 0, 'dur_sum' => 0, 'dur_n' => 0 );
	$off     = count( array_filter( array_column( $rows, 'off' ) ) );
	$csv     = wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'bp_phone_csv' ), $f ), admin_url( 'admin-post.php' ) ), 'bp_phone_csv' );
	$locs    = bp_phone_locations();
	$unknown = get_option( 'bp_phone_unknown', array() );
	?>
	<div class="wrap bp-attr bp-phone">
		<h1>Кліки по телефону</h1>
		<style>
			.bp-phone .kpis{display:flex;gap:12px;flex-wrap:wrap;margin:12px 0}
			.bp-phone .kpi{background:#fff;border:1px solid #c3c4c7;padding:10px 16px;min-width:170px}
			.bp-phone .kpi b{display:block;font-size:22px;line-height:1.4}
			.bp-phone table.widefat{margin:8px 0 16px;max-width:1100px}
			.bp-phone td.num,.bp-phone th.num{text-align:right;white-space:nowrap}
			.bp-phone .grid-2{display:grid;grid-template-columns:repeat(auto-fit,minmax(420px,1fr));gap:0 24px;max-width:1100px}
			.bp-days{width:100%;max-width:1000px;height:auto;background:#fff;border:1px solid #c3c4c7}
			.bp-days .bar{fill:#2a78d6}.bp-days g:hover .bar{fill:#184f95}.bp-days .hit{fill:transparent}
			.bp-days .grid{stroke:#e0e0e0;stroke-width:1}.bp-days .tick{font-size:11px;fill:#646970}.bp-days .tick.we{fill:#1d2327;font-weight:600}
			.bp-heat{border-collapse:separate;border-spacing:2px;background:#fff;border:1px solid #c3c4c7;padding:6px}
			.bp-heat td{width:30px;height:24px;text-align:center;font-size:11px;background:#f6f7f7;border-radius:2px}
			.bp-heat th{font-size:11px;font-weight:400;color:#646970;padding:0 4px}
			.bp-heat td.off{background-image:repeating-linear-gradient(45deg,rgba(29,35,39,.28) 0 1px,transparent 1px 5px)}
			.bp-phone .legend{display:flex;gap:16px;align-items:center;font-size:12px;color:#50575e;margin:6px 0 20px}
			.bp-phone .sw{display:inline-block;width:14px;height:14px;vertical-align:middle;margin-right:4px;border-radius:2px}
		</style>

		<form method="get">
			<input type="hidden" name="page" value="bp-phone-clicks">
			<label>З <input type="date" name="from" value="<?php echo esc_attr( $f['from'] ); ?>"></label>
			<label>по <input type="date" name="to" value="<?php echo esc_attr( $f['to'] ); ?>"></label>
			<select name="location"><option value="">Усі номери / локації</option>
				<?php foreach ( $locs as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $f['location'], $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?>
			</select>
			<select name="device"><option value="">Усі пристрої</option>
				<?php foreach ( array( 'mobile' => 'Мобільні', 'desktop' => 'Десктоп', 'tablet' => 'Планшети' ) as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $f['device'], $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?>
			</select>
			<?php submit_button( 'Показати', 'primary', '', false ); ?>
			<a class="button" href="<?php echo esc_url( $csv ); ?>">Експорт CSV</a>
		</form>

		<div class="kpis">
			<div class="kpi">Кліків по номерах<b><?php echo (int) $total; ?></b></div>
			<div class="kpi">З платною рекламою в шляху<b><?php echo esc_html( bp_attr_pct( $all['paid'], $total ) ); ?></b><?php echo (int) $all['paid']; ?> кліків</div>
			<div class="kpi">У неробочий час<b><?php echo esc_html( bp_attr_pct( $off, $total ) ); ?></b><?php echo (int) $off; ?> кліків</div>
			<?php if ( $binotel ) : ?>
				<div class="kpi">Перейшли в дзвінок<b><?php echo esc_html( bp_attr_pct( $all['calls'], $total ) ); ?></b><?php echo (int) $all['calls']; ?> з <?php echo (int) $total; ?></div>
				<div class="kpi">Відповіли<b><?php echo esc_html( bp_attr_pct( $all['answered'], $total ) ); ?></b>сер. <?php echo $all['dur_n'] ? (int) round( $all['dur_sum'] / $all['dur_n'] ) : 0; ?> с</div>
			<?php endif; ?>
		</div>

		<?php bp_phone_table( 'За джерелом (останнє непряме джерело)', bp_phone_group( $rows, 'src' ), $total, array(), (bool) $binotel ); ?>

		<div class="grid-2">
			<div><?php bp_phone_table( 'За номером / локацією', bp_phone_group( $rows, 'location' ), $total, $locs ); ?></div>
			<div><?php bp_phone_table( 'За спеціальністю', bp_phone_group( $rows, 'specialty' ), $total ); ?></div>
			<div><?php bp_phone_table( 'За типом сторінки', bp_phone_group( $rows, 'page_type' ), $total, array( 'home' => 'Головна', 'department' => 'Відділення', 'doctor' => 'Лікар', 'article' => 'Стаття', 'contacts' => 'Контакти', 'promo' => 'Акція', 'other' => 'Інше' ) ); ?></div>
			<div><?php bp_phone_table( 'За місцем кнопки', bp_phone_group( $rows, 'element' ), $total, array( 'header' => 'Хедер', 'footer' => 'Футер', 'banner' => 'Банер', 'popup' => 'Попап', 'content' => 'Контент', 'sticky' => 'Закріплена кнопка' ) ); ?></div>
			<div><?php bp_phone_table( 'За дією і пристроєм', bp_phone_group( $rows, static function ( $r ) { return $r['action'] . ' / ' . $r['device']; } ), $total ); ?></div>
		</div>

		<h2>Кліки по днях</h2>
		<?php bp_phone_chart_days( $rows, $f['from'], $f['to'] ); ?>
		<p class="description">Наведіть на стовпчик, щоб побачити кількість. Жирним - вихідні.</p>

		<h2>Кліки за днем тижня і годиною</h2>
		<?php bp_phone_chart_hours( $rows ); ?>
		<div class="legend">
			<span>Менше <span class="sw" style="background:#cde2fb"></span><span class="sw" style="background:#6da7ec"></span><span class="sw" style="background:#256abf"></span><span class="sw" style="background:#0d366b"></span> більше кліків</span>
			<span><span class="sw" style="background:#f6f7f7;background-image:repeating-linear-gradient(45deg,rgba(29,35,39,.28) 0 1px,transparent 1px 5px);border:1px solid #c3c4c7"></span>неробочий час (Пн-Пт після 18:00, Сб після 15:00, неділя)</span>
		</div>

		<?php if ( $unknown ) : ?>
			<h2>Невідомі номери (додайте в phones.json)</h2>
			<table class="widefat striped" style="max-width:700px"><thead><tr><th>Номер</th><th class="num">Кліків</th><th>Остання сторінка</th><th>Останній клік</th></tr></thead><tbody>
			<?php foreach ( $unknown as $num => $u ) : ?>
				<tr><td><code><?php echo esc_html( $num ); ?></code></td><td class="num"><?php echo (int) $u['count']; ?></td><td><?php echo esc_html( $u['page'] ?? '' ); ?></td><td><?php echo esc_html( get_date_from_gmt( $u['last'] ?? '' ) ); ?></td></tr>
			<?php endforeach; ?>
			</tbody></table>
		<?php endif; ?>

		<div class="rules" style="max-width:1000px">
			<p><strong>Як читати.</strong> Клік по номеру - це намір зателефонувати, а не дзвінок. Той самий номер у межах
			однієї сесії протягом 60 с рахується один раз. Джерело - останнє непряме джерело відвідувача (реклама, пошук, карти, соцмережі…)
			за тими самими правилами, що й у звіті "Атрибуція заявок"; "З платним дотиком" - у шляху відвідувача була платна реклама.
			<?php if ( $binotel ) : ?>"→ дзвінок": протягом 3 хв після кліку на цей номер надійшов дзвінок у Binotel (найближчий за часом).<?php endif; ?></p>
		</div>
	</div>
	<?php
}

/* ------------------------------------------------------------------ */
/* CSV                                                                 */
/* ------------------------------------------------------------------ */

function bp_phone_export_csv() {
	if ( ! current_user_can( bp_attr_settings()['capability'] ) ) {
		wp_die( 'Forbidden', 403 );
	}
	check_admin_referer( 'bp_phone_csv' );
	$f    = bp_phone_filters();
	$rows = bp_phone_rows( $f );
	$cols = array( 'id', 'event_id', 'local', 'action', 'phone_e164', 'phone_label', 'location', 'device', 'page_path', 'page_type', 'specialty', 'doctor_slug', 'element', 'ft_source', 'ft_medium', 'ft_campaign', 'lt_source', 'lt_medium', 'lt_campaign', 'lt_term', 'touch_count', 'paid_in_path', 'gclid', 'gbraid', 'wbraid', 'session_id', 'off', 'call_id', 'call_answered', 'call_duration', 'match_confidence' );
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="bp-phone-clicks-' . $f['from'] . '_' . $f['to'] . '.csv"' );
	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" );
	fputcsv( $out, array_map( static function ( $c ) { return 'local' === $c ? 'datetime' : ( 'off' === $c ? 'off_hours' : $c ); }, $cols ), ',', '"', '' );
	foreach ( $rows as $r ) {
		$line = array();
		foreach ( $cols as $c ) {
			$v      = $r[ $c ] ?? '';
			$line[] = bp_attr_csv_cell( is_bool( $v ) ? (int) $v : $v );
		}
		fputcsv( $out, $line, ',', '"', '' );
	}
	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
}

/* ------------------------------------------------------------------ */
/* Сканер "Номери на сайті"                                            */
/* ------------------------------------------------------------------ */

function bp_phone_scan_url_list() {
	$urls  = array( home_url( '/' ) );
	$types = array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment', 'elementor_library', 'popup', 'e-landing-page' ) );
	foreach ( $types as $pt ) {
		$ids = get_posts( array( 'post_type' => $pt, 'post_status' => 'publish', 'numberposts' => 'post' === $pt ? 30 : 300, 'fields' => 'ids' ) );
		foreach ( $ids as $id ) {
			$urls[] = get_permalink( $id );
		}
	}
	return array_slice( array_values( array_unique( array_filter( $urls ) ) ), 0, 500 );
}

function bp_phone_ajax_scan_urls() {
	check_ajax_referer( 'bp_phone_scan' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( null, 403 );
	}
	wp_send_json_success( bp_phone_scan_url_list() );
}

/** Місце номера у відрендереній сторінці (як element() у phone-core.js, без computed style). */
function bp_phone_scan_element( DOMNode $node ) {
	$chain = array();
	for ( $n = $node; $n && XML_ELEMENT_NODE === $n->nodeType; $n = $n->parentNode ) {
		$chain[] = array(
			'tag'   => strtolower( $n->nodeName ),
			'id'    => $n->getAttribute( 'id' ),
			'cls'   => ' ' . $n->getAttribute( 'class' ) . ' ',
			'role'  => $n->getAttribute( 'role' ),
			'type'  => $n->getAttribute( 'data-elementor-type' ),
			'style' => $n->getAttribute( 'style' ),
		);
	}
	$any = static function ( $fn ) use ( $chain ) {
		foreach ( $chain as $a ) {
			if ( $fn( $a ) ) {
				return true;
			}
		}
		return false;
	};
	if ( $any( static function ( $a ) { return preg_match( '/^(popmake-|pum-)/', $a['id'] ) || 'dialog' === $a['role'] || 'popup' === $a['type'] || preg_match( '/\s(pum|pum-container|popmake|elementor-popup-modal|dialog-widget|modal)\s/', $a['cls'] ); } ) ) {
		return 'popup';
	}
	if ( $any( static function ( $a ) { return 'header' === $a['tag'] || 'header' === $a['type'] || preg_match( '/^(masthead|header|site-header)$/', $a['id'] ) || preg_match( '/\s(elementor-location-header|site-header)\s/', $a['cls'] ); } ) ) {
		return 'header';
	}
	if ( $any( static function ( $a ) { return 'footer' === $a['tag'] || 'footer' === $a['type'] || preg_match( '/^(colophon|footer|site-footer)$/', $a['id'] ) || preg_match( '/\s(elementor-location-footer|site-footer)\s/', $a['cls'] ); } ) ) {
		return 'footer';
	}
	if ( $any( static function ( $a ) { return preg_match( '/position:\s*(fixed|sticky)/', $a['style'] ) || preg_match( '/\s(sticky|is-sticky|fixed|floating|sticky-button|floating-button)\s/', $a['cls'] ); } ) ) {
		return 'sticky';
	}
	if ( $any( static function ( $a ) { return preg_match( '/[\s-](banner|hero|elementor-widget-call-to-action|elementor-slides|elementor-widget-slides|swiper)[\s-]/', $a['cls'] ); } ) ) {
		return 'banner';
	}
	return 'content';
}

/**
 * Сканує відрендерену сторінку: номери-посилання tel:, обгорнуті плагіном і текстові.
 */
function bp_phone_scan_page( $url ) {
	$res = wp_remote_get( $url, array( 'timeout' => 25, 'redirection' => 3, 'sslverify' => apply_filters( 'https_local_ssl_verify', false ), 'headers' => array( 'Cache-Control' => 'no-cache' ) ) );
	if ( is_wp_error( $res ) ) {
		return array( array( 'url' => $url, 'error' => $res->get_error_message() ) );
	}
	$html = wp_remote_retrieve_body( $res );
	if ( ! $html ) {
		return array( array( 'url' => $url, 'error' => 'HTTP ' . wp_remote_retrieve_response_code( $res ) ) );
	}
	$dom = new DOMDocument();
	libxml_use_internal_errors( true );
	$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
	libxml_clear_errors();
	$xp   = new DOMXPath( $dom );
	$rows = array();
	$add  = static function ( $node, $e164, $raw, $status ) use ( &$rows, $url ) {
		$info = bp_phone_lookup( $e164 );
		$el   = bp_phone_scan_element( XML_ELEMENT_NODE === $node->nodeType ? $node : $node->parentNode );
		$key  = $e164 . '|' . $status . '|' . $el;
		if ( ! isset( $rows[ $key ] ) ) {
			$rows[ $key ] = array( 'url' => $url, 'e164' => $e164, 'raw' => trim( preg_replace( '/\s+/u', ' ', $raw ) ), 'label' => $info['known'] ? $info['phone_label'] : 'невідомий', 'element' => $el, 'status' => $status, 'n' => 0 );
		}
		++$rows[ $key ]['n'];
	};
	foreach ( $xp->query( '//a[starts-with(translate(normalize-space(@href),"TEL","tel"),"tel:")]' ) as $a ) {
		$e164 = bp_phone_normalize( $a->getAttribute( 'href' ) );
		if ( $e164 ) {
			$auto = $a->hasAttribute( 'data-bp-phone-auto' );
			$add( $a, $e164, $a->textContent ? $a->textContent : $a->getAttribute( 'href' ), $auto ? 'auto' : 'link' );
		}
	}
	$texts = $xp->query( '//body//text()[not(ancestor::a) and not(ancestor::script) and not(ancestor::style) and not(ancestor::noscript)]' );
	foreach ( $texts as $t ) {
		if ( preg_match_all( bp_phone_regex(), $t->nodeValue, $m ) ) {
			foreach ( $m[2] as $raw ) {
				$e164 = bp_phone_normalize( $raw );
				if ( $e164 ) {
					$add( $t, $e164, $raw, 'text' );
				}
			}
		}
	}
	return array_values( $rows );
}

function bp_phone_ajax_scan() {
	check_ajax_referer( 'bp_phone_scan' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( null, 403 );
	}
	$urls = array_slice( (array) wp_unslash( $_POST['urls'] ?? array() ), 0, 10 );
	$home = wp_parse_url( home_url(), PHP_URL_HOST );
	$out  = array();
	foreach ( $urls as $u ) {
		$u = esc_url_raw( $u );
		if ( wp_parse_url( $u, PHP_URL_HOST ) === $home ) { // лише свій сайт
			$out = array_merge( $out, bp_phone_scan_page( $u ) );
		}
	}
	wp_send_json_success( $out );
}

function bp_phone_render_scan() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1>Номери на сайті</h1>
		<p>Сканер відкриває опубліковані сторінки сайту (як відвідувач) і знаходить усі номери телефону: у хедері, футері, банерах,
			попапах (їхня розмітка є на сторінках), сторінках лікарів і спеціальностей, контактах.</p>
		<ul style="list-style:disc;margin-left:20px">
			<li><strong>tel:-посилання</strong> - номер уже клікабельний, кліки рахуються;</li>
			<li><strong>текст → обгорнуто плагіном</strong> - номер був текстом, плагін зробив його посиланням tel:;</li>
			<li><strong>текст</strong> - номер лишився текстом (невідомий номер, або виводиться поза фільтрами контенту/Elementor): кліки не рахуються, лише копіювання.</li>
		</ul>
		<p><button type="button" class="button button-primary" id="bp-scan">Сканувати</button> <span id="bp-scan-status"></span></p>
		<table class="widefat striped" id="bp-scan-t">
			<thead><tr><th>Сторінка</th><th>Місце</th><th>Номер</th><th>Як на сайті</th><th>Статус</th><th>Разів</th></tr></thead>
			<tbody></tbody>
		</table>
	</div>
	<script>
	(function () {
		var btn = document.getElementById('bp-scan'), st = document.getElementById('bp-scan-status');
		var tb = document.querySelector('#bp-scan-t tbody'), nonce = <?php echo wp_json_encode( wp_create_nonce( 'bp_phone_scan' ) ); ?>;
		var names = { link: 'tel:-посилання', auto: 'текст → обгорнуто плагіном', text: 'текст' };
		var totals = { link: 0, auto: 0, text: 0 };
		function post(data) {
			var fd = new FormData();
			fd.append('_ajax_nonce', nonce);
			for (var k in data) [].concat(data[k]).forEach(function (v) { fd.append(Array.isArray(data[k]) ? k + '[]' : k, v); });
			return fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
		}
		function row(cells, cls) {
			var tr = document.createElement('tr');
			if (cls) tr.className = cls;
			cells.forEach(function (c) { var td = document.createElement('td'); td.textContent = c; tr.appendChild(td); });
			tb.appendChild(tr);
		}
		btn.addEventListener('click', function () {
			btn.disabled = true; tb.innerHTML = ''; totals = { link: 0, auto: 0, text: 0 };
			post({ action: 'bp_phone_scan_urls' }).then(function (res) {
				var urls = res.data || [], i = 0;
				(function next() {
					if (i >= urls.length) {
						st.textContent = 'Готово: ' + urls.length + ' сторінок. tel:-посилань: ' + totals.link + ', обгорнуто: ' + totals.auto + ', текстом: ' + totals.text + '.';
						btn.disabled = false;
						return;
					}
					st.textContent = 'Сканую ' + (i + 1) + '-' + Math.min(i + 5, urls.length) + ' з ' + urls.length + '…';
					post({ action: 'bp_phone_scan', urls: urls.slice(i, i + 5) }).then(function (r) {
						(r.data || []).forEach(function (x) {
							if (x.error) return row([x.url.replace(location.origin, ''), '', '', '', 'помилка: ' + x.error, '']);
							totals[x.status] += x.n;
							row([x.url.replace(/^https?:\/\/[^\/]+/, ''), x.element, x.e164 + ' (' + x.label + ')', x.raw, names[x.status], x.n], 'bp-' + x.status);
						});
						i += 5;
						next();
					}, function () { i += 5; next(); });
				})();
			});
		});
	})();
	</script>
	<?php
}
