<?php
/**
 * Адмін-сторінка "Атрибуція заявок": звіт, CSV-експорт.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'bp_attr_admin_menu' );
add_action( 'admin_post_bp_attr_csv', 'bp_attr_export_csv' );

function bp_attr_admin_menu() {
	$cap = bp_attr_settings()['capability'];
	add_menu_page( 'Атрибуція заявок', 'Атрибуція заявок', $cap, 'bp-attribution', 'bp_attr_render_report', 'dashicons-chart-pie', 26 );
	add_submenu_page( 'bp-attribution', 'Атрибуція заявок', 'Звіт', $cap, 'bp-attribution', 'bp_attr_render_report' );
	add_submenu_page( 'bp-attribution', 'Форми на сайті', 'Форми на сайті', 'manage_options', 'bp-attribution-forms', 'bp_attr_render_forms' );
	add_submenu_page( 'bp-attribution', 'Налаштування атрибуції', 'Налаштування', 'manage_options', 'bp-attribution-settings', 'bp_attr_render_settings' );
}

/* ------------------------------------------------------------------ */
/* Дані                                                                */
/* ------------------------------------------------------------------ */

function bp_attr_report_filters() {
	// phpcs:disable WordPress.Security.NonceVerification
	$get  = wp_unslash( $_GET );
	$date = static function ( $v, $def ) {
		return is_string( $v ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : $def;
	};
	return array(
		'from'      => $date( $get['from'] ?? '', wp_date( 'Y-m-d', strtotime( '-30 days' ) ) ),
		'to'        => $date( $get['to'] ?? '', wp_date( 'Y-m-d' ) ),
		'specialty' => sanitize_text_field( $get['specialty'] ?? '' ),
		'location'  => sanitize_text_field( $get['location'] ?? '' ),
		'all_forms' => ! empty( $get['all_forms'] ),
	);
	// phpcs:enable
}

function bp_attr_report_rows( array $flt, $columns = null ) {
	global $wpdb;
	$table = bp_attr_table();
	$where = array( 'created_at BETWEEN %s AND %s' );
	$args  = array( get_gmt_from_date( $flt['from'] . ' 00:00:00' ), get_gmt_from_date( $flt['to'] . ' 23:59:59' ) );
	if ( '' !== $flt['specialty'] ) {
		$where[] = 'specialty = %s';
		$args[]  = $flt['specialty'];
	}
	if ( '' !== $flt['location'] ) {
		$where[] = 'location = %s';
		$args[]  = $flt['location'];
	}
	$non_leads = bp_attr_lines( bp_attr_settings()['non_lead_forms'] );
	if ( ! $flt['all_forms'] && $non_leads ) {
		$where[] = 'form_id NOT IN (' . implode( ',', array_fill( 0, count( $non_leads ), '%s' ) ) . ')';
		$args    = array_merge( $args, $non_leads );
	}
	$cols = $columns ? $columns : 'id, created_at, form_type, form_id, form_name, ' . implode( ', ', bp_attr_field_keys() );
	$sql  = "SELECT $cols FROM $table WHERE " . implode( ' AND ', $where ) . ' ORDER BY created_at DESC';
	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	foreach ( $rows as &$r ) {
		$r['category'] = bp_attr_category( $r );
	}
	return $rows;
}

function bp_attr_distinct( $col ) {
	global $wpdb;
	$col = preg_replace( '/[^a-z_]/', '', $col );
	return $wpdb->get_col( "SELECT DISTINCT $col FROM " . bp_attr_table() . " WHERE $col <> '' ORDER BY $col" ); // phpcs:ignore WordPress.DB.PreparedSQL
}

/**
 * Агрегати для звіту.
 */
function bp_attr_report_data( array $rows ) {
	$cats  = bp_attr_categories();
	$total = count( $rows );
	$by    = array_fill_keys( array_keys( $cats ), 0 );
	$spec  = array();
	$cell  = array();
	$paths = array();
	$sr    = array();
	$zero  = array( 'n' => 0, 'days' => 0, 'days_n' => 0, 'touches' => 0 );
	$grp   = array( 'paid' => $zero, 'free' => $zero );

	foreach ( $rows as $r ) {
		$c = $r['category'];
		$s = '' !== $r['specialty'] ? $r['specialty'] : '—';
		++$by[ $c ];
		$spec[ $s ]          = ( $spec[ $s ] ?? 0 ) + 1;
		$cell[ $c ][ $s ]    = ( $cell[ $c ][ $s ] ?? 0 ) + 1;
		$p                   = '' !== (string) $r['touch_path'] ? $r['touch_path'] : '—';
		$paths[ $p ]         = ( $paths[ $p ] ?? 0 ) + 1;
		$k                   = '' !== $r['self_reported'] ? $r['self_reported'] : '';
		$sr[ $c ][ $k ]      = ( $sr[ $c ][ $k ] ?? 0 ) + 1;
		$g                   = $r['paid_in_path'] ? 'paid' : 'free';
		++$grp[ $g ]['n'];
		$grp[ $g ]['touches'] += (int) $r['touch_count'];
		if ( null !== $r['days_to_convert'] && '' !== $r['days_to_convert'] ) {
			$grp[ $g ]['days'] += (int) $r['days_to_convert'];
			++$grp[ $g ]['days_n'];
		}
	}
	arsort( $spec );
	arsort( $paths );
	return array(
		'total' => $total,
		'by'    => $by,
		'spec'  => $spec,
		'cell'  => $cell,
		'paths' => array_slice( $paths, 0, 10, true ),
		'sr'    => $sr,
		'paid'  => $grp['paid'],
		'free'  => $grp['free'],
	);
}

/* ------------------------------------------------------------------ */
/* Сторінка                                                            */
/* ------------------------------------------------------------------ */

function bp_attr_pct( $n, $total ) {
	return $total ? number_format_i18n( 100 * $n / $total, 1 ) . '%' : '—';
}

function bp_attr_avg( array $b, $key, $n_key = 'n' ) {
	return $b[ $n_key ] ? number_format_i18n( $b[ $key ] / $b[ $n_key ], 1 ) : '—';
}

function bp_attr_render_report() {
	if ( ! current_user_can( bp_attr_settings()['capability'] ) ) {
		return;
	}
	$flt   = bp_attr_report_filters();
	$rows  = bp_attr_report_rows( $flt );
	$d     = bp_attr_report_data( $rows );
	$cats  = bp_attr_categories();
	$specs = array_keys( $d['spec'] );
	$sro   = bp_attr_self_reported_options() + array( '' => 'Не вказано' );
	$csv   = wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'bp_attr_csv' ), array_map( 'strval', $flt ) ), admin_url( 'admin-post.php' ) ), 'bp_attr_csv' );
	?>
	<div class="wrap bp-attr">
		<h1>Атрибуція заявок</h1>
		<style>
			.bp-attr table.widefat{margin:12px 0 8px;max-width:100%}
			.bp-attr td.num,.bp-attr th.num{text-align:right;white-space:nowrap}
			.bp-attr .rules{background:#fff;border:1px solid #c3c4c7;padding:8px 16px;max-width:1000px}
			.bp-attr .rules dt{font-weight:600;margin-top:8px}
			.bp-attr .kpis{display:flex;gap:12px;flex-wrap:wrap}
			.bp-attr .kpi{background:#fff;border:1px solid #c3c4c7;padding:10px 16px;min-width:180px}
			.bp-attr .kpi b{display:block;font-size:22px;line-height:1.4}
			.bp-attr tr.paid td{background:#f0f6fc}
		</style>

		<form method="get" style="margin:12px 0">
			<input type="hidden" name="page" value="bp-attribution">
			<label>З <input type="date" name="from" value="<?php echo esc_attr( $flt['from'] ); ?>"></label>
			<label>по <input type="date" name="to" value="<?php echo esc_attr( $flt['to'] ); ?>"></label>
			<select name="specialty">
				<option value="">Усі спеціальності</option>
				<?php foreach ( bp_attr_distinct( 'specialty' ) as $s ) : ?>
					<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $flt['specialty'], $s ); ?>><?php echo esc_html( $s ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="location">
				<option value="">Усі локації</option>
				<?php foreach ( bp_attr_distinct( 'location' ) as $s ) : ?>
					<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $flt['location'], $s ); ?>><?php echo esc_html( $s ); ?></option>
				<?php endforeach; ?>
			</select>
			<label><input type="checkbox" name="all_forms" value="1" <?php checked( $flt['all_forms'] ); ?>> включно з формами, що не є заявками</label>
			<?php submit_button( 'Показати', 'primary', '', false ); ?>
			<a class="button" href="<?php echo esc_url( $csv ); ?>">Експорт CSV</a>
		</form>

		<div class="kpis">
			<div class="kpi">Усього заявок<b><?php echo (int) $d['total']; ?></b></div>
			<div class="kpi">Платна реклама в шляху<b><?php echo esc_html( bp_attr_pct( $d['paid']['n'], $d['total'] ) ); ?></b><?php echo (int) $d['paid']['n']; ?> заявок</div>
			<div class="kpi">Із них last-click платний<b><?php echo esc_html( bp_attr_pct( $d['by']['paid_last'], $d['total'] ) ); ?></b><?php echo (int) $d['by']['paid_last']; ?> заявок</div>
		</div>

		<h2>Заявки за категоріями</h2>
		<table class="widefat striped">
			<thead><tr>
				<th>Категорія</th><th class="num">Заявок</th><th class="num">% від усіх</th>
				<?php foreach ( $specs as $s ) : ?><th class="num"><?php echo esc_html( $s ); ?></th><?php endforeach; ?>
			</tr></thead>
			<tbody>
			<?php foreach ( $cats as $key => $label ) : ?>
				<?php
				if ( 'other' === $key && ! $d['by']['other'] ) {
					continue;
				}
				?>
				<tr class="<?php echo 0 === strpos( $key, 'paid' ) ? 'paid' : ''; ?>">
					<td><?php echo esc_html( $label ); ?></td>
					<td class="num"><?php echo (int) $d['by'][ $key ]; ?></td>
					<td class="num"><?php echo esc_html( bp_attr_pct( $d['by'][ $key ], $d['total'] ) ); ?></td>
					<?php foreach ( $specs as $s ) : ?><td class="num"><?php echo (int) ( $d['cell'][ $key ][ $s ] ?? 0 ); ?></td><?php endforeach; ?>
				</tr>
			<?php endforeach; ?>
			</tbody>
			<tfoot><tr>
				<th>Разом</th><th class="num"><?php echo (int) $d['total']; ?></th><th class="num"><?php echo $d['total'] ? '100%' : '—'; ?></th>
				<?php foreach ( $specs as $s ) : ?><th class="num"><?php echo (int) $d['spec'][ $s ]; ?></th><?php endforeach; ?>
			</tr></tfoot>
		</table>

		<div class="rules">
			<p><strong>Як рахується.</strong> Кожна заявка потрапляє рівно в одну категорію. Джерела визначаються лише за фактичними
			параметрами посилання (gclid, utm-мітки, fbclid) і сайтом, з якого прийшов відвідувач; жодного ручного
			перепризначення немає. Сайт пам'ятає до 10 останніх заходів пацієнта протягом 90 днів і завжди - найперший.</p>
			<dl>
				<?php foreach ( bp_attr_category_rules() as $label => $rule ) : ?>
					<dt><?php echo esc_html( $label ); ?></dt><dd><?php echo esc_html( $rule ); ?></dd>
				<?php endforeach; ?>
			</dl>
			<p>Спеціальність - з адреси сторінки заявки (/departments/{назва}/); "—" - заявка з іншої сторінки.</p>
		</div>

		<h2>Заявки з платним дотиком у шляху</h2>
		<table class="widefat striped" style="max-width:700px">
			<thead><tr><th></th><th class="num">Заявок</th><th class="num">Сер. днів до заявки</th><th class="num">Сер. кількість дотиків</th></tr></thead>
			<tbody>
				<tr><td>З платним дотиком</td><td class="num"><?php echo (int) $d['paid']['n']; ?></td><td class="num"><?php echo esc_html( bp_attr_avg( $d['paid'], 'days', 'days_n' ) ); ?></td><td class="num"><?php echo esc_html( bp_attr_avg( $d['paid'], 'touches' ) ); ?></td></tr>
				<tr><td>Без платних дотиків</td><td class="num"><?php echo (int) $d['free']['n']; ?></td><td class="num"><?php echo esc_html( bp_attr_avg( $d['free'], 'days', 'days_n' ) ); ?></td><td class="num"><?php echo esc_html( bp_attr_avg( $d['free'], 'touches' ) ); ?></td></tr>
			</tbody>
		</table>

		<h2>Топ-10 шляхів дотиків</h2>
		<table class="widefat striped" style="max-width:1000px">
			<thead><tr><th>Шлях (від першого до останнього заходу)</th><th class="num">Заявок</th><th class="num">%</th></tr></thead>
			<tbody>
			<?php foreach ( $d['paths'] as $path => $n ) : ?>
				<tr><td><code><?php echo esc_html( $path ); ?></code></td><td class="num"><?php echo (int) $n; ?></td><td class="num"><?php echo esc_html( bp_attr_pct( $n, $d['total'] ) ); ?></td></tr>
			<?php endforeach; ?>
			<?php if ( ! $d['paths'] ) : ?><tr><td colspan="3">Немає заявок за обраний період.</td></tr><?php endif; ?>
			</tbody>
		</table>

		<h2>Автоматична класифікація vs "Звідки ви дізналися про нас?"</h2>
		<p>Рядки - категорія за технічними даними, стовпці - відповідь пацієнта. Відповідь пацієнта на класифікацію не впливає.</p>
		<table class="widefat striped">
			<thead><tr><th>Категорія \ Відповідь</th>
				<?php foreach ( $sro as $label ) : ?><th class="num"><?php echo esc_html( $label ); ?></th><?php endforeach; ?>
			</tr></thead>
			<tbody>
			<?php foreach ( $cats as $key => $label ) : ?>
				<?php
				if ( empty( $d['sr'][ $key ] ) ) {
					continue;
				}
				?>
				<tr><td><?php echo esc_html( $label ); ?></td>
					<?php foreach ( $sro as $code => $l ) : ?><td class="num"><?php echo (int) ( $d['sr'][ $key ][ $code ] ?? 0 ); ?></td><?php endforeach; ?>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}

/* ------------------------------------------------------------------ */
/* CSV                                                                 */
/* ------------------------------------------------------------------ */

function bp_attr_csv_cell( $v ) {
	$v = (string) $v;
	// захист від формул у Excel / Google Sheets (utm-мітки - дані від користувача)
	return preg_match( '/^[=+\-@\t\r]/', $v ) ? "'" . $v : $v;
}

function bp_attr_export_csv() {
	if ( ! current_user_can( bp_attr_settings()['capability'] ) ) {
		wp_die( 'Forbidden', 403 );
	}
	check_admin_referer( 'bp_attr_csv' );
	$flt  = bp_attr_report_filters();
	$rows = bp_attr_report_rows( $flt );
	$cats = bp_attr_categories();
	$sro  = bp_attr_self_reported_options();

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="bp-attribution-' . $flt['from'] . '_' . $flt['to'] . '.csv"' );
	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" ); // BOM для Excel
	$cols = array_merge( array( 'id', 'created_at', 'form_type', 'form_id', 'form_name', 'category' ), bp_attr_field_keys(), array( 'self_reported_label' ) );
	fputcsv( $out, $cols, ',', '"', '' );
	foreach ( $rows as $r ) {
		$r['created_at']          = get_date_from_gmt( $r['created_at'] );
		$r['category']            = $cats[ $r['category'] ];
		$r['self_reported_label'] = $sro[ $r['self_reported'] ] ?? '';
		$line                     = array();
		foreach ( $cols as $c ) {
			$line[] = bp_attr_csv_cell( $r[ $c ] ?? '' );
		}
		fputcsv( $out, $line, ',', '"', '' );
	}
	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
}
