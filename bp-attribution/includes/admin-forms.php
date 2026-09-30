<?php
/**
 * "Форми на сайті": інвентаризація форм, тема, активні плагіни.
 * Показує, які форми покриває атрибуція і чого в них бракує (поля self_reported / location).
 */

defined( 'ABSPATH' ) || exit;

/**
 * Рекурсивно шукає віджети форм у _elementor_data.
 */
function bp_attr_find_elementor_forms( $elements, array &$out ) {
	foreach ( (array) $elements as $el ) {
		if ( ! is_array( $el ) ) {
			continue;
		}
		if ( ( $el['widgetType'] ?? '' ) === 'form' ) {
			$ids = array();
			foreach ( (array) ( $el['settings']['form_fields'] ?? array() ) as $f ) {
				$ids[] = ( $f['custom_id'] ?? '?' ) . ':' . ( $f['field_type'] ?? 'text' );
			}
			$out[] = array(
				'id'      => $el['id'] ?? '',
				'name'    => $el['settings']['form_name'] ?? '',
				'fields'  => $ids,
				'actions' => (array) ( $el['settings']['submit_actions'] ?? array( 'email' ) ),
			);
		}
		if ( ! empty( $el['elements'] ) ) {
			bp_attr_find_elementor_forms( $el['elements'], $out );
		}
	}
}

function bp_attr_forms_inventory() {
	global $wpdb;
	$items = array();

	// Elementor Pro: сторінки, шаблони, Elementor Popups.
	$posts = $wpdb->get_results(
		"SELECT p.ID, p.post_title, p.post_type, p.post_status, m.meta_value FROM {$wpdb->posts} p
		 JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_elementor_data'
		 WHERE m.meta_value LIKE '%\"widgetType\":\"form\"%' AND p.post_status IN ('publish','private','draft')
		 AND p.post_type <> 'revision'"
	);
	foreach ( $posts as $p ) {
		$forms = array();
		bp_attr_find_elementor_forms( json_decode( $p->meta_value, true ), $forms );
		$tpl = 'elementor_library' === $p->post_type ? get_post_meta( $p->ID, '_elementor_template_type', true ) : '';
		foreach ( $forms as $f ) {
			$items[] = array(
				'type'    => 'Elementor Form' . ( $tpl ? " ($tpl)" : '' ),
				'form_id' => $f['id'],
				'name'    => $f['name'],
				'where'   => sprintf( '%s #%d "%s"', $p->post_type, $p->ID, $p->post_title ),
				'link'    => 'elementor_library' === $p->post_type ? '' : get_permalink( $p->ID ),
				'fields'  => implode( ', ', $f['fields'] ),
				'notes'   => 'Дії: ' . implode( ', ', $f['actions'] ),
			);
		}
	}

	// Popup Maker: вміст попапів (шорткоди форм).
	foreach ( get_posts( array( 'post_type' => 'popup', 'numberposts' => -1, 'post_status' => array( 'publish', 'draft' ) ) ) as $p ) {
		preg_match_all( '/\[(contact-form-7|wpforms|elementor-template|gravityform|ninja_form|fluentform)[^\]]*\]/', $p->post_content, $m );
		$has_form = $m[0] || false !== strpos( $p->post_content, '<form' ) || get_post_meta( $p->ID, '_elementor_data', true );
		$items[]  = array(
			'type'    => 'Popup Maker',
			'form_id' => 'popmake-' . $p->ID . ':…',
			'name'    => $p->post_title,
			'where'   => 'popup #' . $p->ID . ' (' . $p->post_status . ')',
			'link'    => '',
			'fields'  => $m[0] ? implode( ' ', $m[0] ) : ( $has_form ? 'HTML / Elementor' : 'форм не знайдено' ),
			'notes'   => 'Приховані поля додаються при відкритті попапу (MutationObserver).',
		);
	}

	// Contact Form 7 / WPForms.
	foreach ( array( 'wpcf7_contact_form' => 'Contact Form 7', 'wpforms' => 'WPForms' ) as $pt => $label ) {
		foreach ( get_posts( array( 'post_type' => $pt, 'numberposts' => -1 ) ) as $p ) {
			$items[] = array(
				'type'    => $label,
				'form_id' => ( 'wpforms' === $pt ? 'wpforms-' : 'cf7-' ) . $p->ID,
				'name'    => $p->post_title,
				'where'   => $pt . ' #' . $p->ID,
				'link'    => '',
				'fields'  => '',
				'notes'   => '',
			);
		}
	}
	return $items;
}

function bp_attr_render_forms() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	global $wpdb;
	$theme   = wp_get_theme();
	$plugins = get_plugins();
	$active  = (array) get_option( 'active_plugins', array() );
	$items   = bp_attr_forms_inventory();
	// Які form_id реально надсилали заявки (з приміткою про останню).
	$seen = $wpdb->get_results( 'SELECT form_type, form_id, form_name, COUNT(*) n, MAX(created_at) last FROM ' . bp_attr_table() . ' GROUP BY form_type, form_id, form_name ORDER BY n DESC', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	?>
	<div class="wrap">
		<h1>Форми на сайті</h1>
		<p>Тема: <strong><?php echo esc_html( $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) ); ?></strong>
			<?php if ( $theme->parent() ) : ?>(батьківська: <?php echo esc_html( $theme->parent()->get( 'Name' ) ); ?>)<?php endif; ?></p>

		<h2>Знайдені форми</h2>
		<p>Атрибуція додається до <em>усіх</em> форм на фронтенді (крім пошуку, коментарів і входу). Щоб поле "Звідки ви дізналися про нас?"
			з'явилося у формі, додайте її <code>form_id</code> у <a href="<?php echo esc_url( admin_url( 'admin.php?page=bp-attribution-settings' ) ); ?>">налаштуваннях</a>
			або створіть у формі Elementor поле Select з ID <code>self_reported</code>. Локація береться з поля, ID якого містить <code>location</code> / <code>filial</code> / <code>branch</code>.</p>
		<table class="widefat striped">
			<thead><tr><th>Тип</th><th>form_id</th><th>Назва</th><th>Де</th><th>Поля (ID:тип)</th><th>Примітки</th></tr></thead>
			<tbody>
			<?php foreach ( $items as $i ) : ?>
				<tr>
					<td><?php echo esc_html( $i['type'] ); ?></td>
					<td><code><?php echo esc_html( $i['form_id'] ); ?></code></td>
					<td><?php echo esc_html( $i['name'] ); ?></td>
					<td><?php echo $i['link'] ? '<a href="' . esc_url( $i['link'] ) . '" target="_blank">' . esc_html( $i['where'] ) . '</a>' : esc_html( $i['where'] ); ?></td>
					<td><small><?php echo esc_html( $i['fields'] ); ?></small></td>
					<td><small><?php echo esc_html( $i['notes'] ); ?></small></td>
				</tr>
			<?php endforeach; ?>
			<?php if ( ! $items ) : ?><tr><td colspan="6">Форм не знайдено.</td></tr><?php endif; ?>
			</tbody>
		</table>

		<h2>Форми, з яких уже надходили заявки</h2>
		<table class="widefat striped" style="max-width:900px">
			<thead><tr><th>Тип</th><th>form_id</th><th>Назва</th><th class="num">Заявок</th><th>Остання</th></tr></thead>
			<tbody>
			<?php foreach ( $seen as $s ) : ?>
				<tr><td><?php echo esc_html( $s['form_type'] ); ?></td><td><code><?php echo esc_html( $s['form_id'] ); ?></code></td><td><?php echo esc_html( $s['form_name'] ); ?></td><td><?php echo (int) $s['n']; ?></td><td><?php echo esc_html( get_date_from_gmt( $s['last'] ) ); ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<h2>Активні плагіни</h2>
		<ul style="columns:2">
			<?php foreach ( $active as $file ) : ?>
				<li><?php echo esc_html( ( $plugins[ $file ]['Name'] ?? $file ) . ' ' . ( $plugins[ $file ]['Version'] ?? '' ) ); ?></li>
			<?php endforeach; ?>
		</ul>
	</div>
	<?php
}
