<?php
/**
 * Адмінка для термінів (категорії товарів, бренди...): поле у формі редагування, колонка, групова дія.
 */

defined( 'ABSPATH' ) || exit;

function svtmg_register_term_hooks(): void {
	foreach ( svtmg_get_enabled_taxonomies() as $tax ) {
		add_action( "{$tax}_edit_form_fields", 'svtmg_render_term_field', 99 );
		add_filter( "manage_edit-{$tax}_columns", 'svtmg_add_column' );
		add_filter( "manage_{$tax}_custom_column", 'svtmg_render_term_column', 10, 3 );
		add_filter( "bulk_actions-edit-{$tax}", 'svtmg_add_bulk_action' );
		add_filter( "handle_bulk_actions-edit-{$tax}", 'svtmg_handle_term_bulk_action', 10, 3 );
	}
}
add_action( 'admin_init', 'svtmg_register_term_hooks' );

function svtmg_render_term_field( WP_Term $term ): void {
	?>
	<tr class="form-field svtmg-term-row">
		<th scope="row"><label for="svtmg-description"><?php esc_html_e( 'Meta description (SVT)', 'svt-meta-generator' ); ?></label></th>
		<td><?php svtmg_render_field( 'term', $term->term_id ); ?></td>
	</tr>
	<?php
}

/**
 * Після Yoast (він зберігає своє поле на edit_term з пріоритетом 99) — тому edited_term.
 */
function svtmg_save_term_field( int $term_id ): void {
	if ( ! current_user_can( 'edit_term', $term_id ) ) {
		return;
	}
	$value = svtmg_submitted_field_value();
	if ( null !== $value ) {
		svtmg_save_description( 'term', $term_id, $value );
	}
}
add_action( 'edited_term', 'svtmg_save_term_field', 99 );

function svtmg_render_term_column( string $content, string $column, int $term_id ): string {
	return 'svtmg_meta' === $column ? svtmg_column_html( svtmg_get_description( 'term', $term_id ) ) : $content;
}

function svtmg_handle_term_bulk_action( string $redirect, string $action, array $ids ): string {
	if ( 'svtmg_generate' !== $action ) {
		return $redirect;
	}
	$keys = array();
	foreach ( $ids as $id ) {
		if ( current_user_can( 'edit_term', (int) $id ) ) {
			$keys[] = svtmg_key( 'term', (int) $id );
		}
	}
	return svtmg_bulk_action_redirect( $redirect, $keys );
}
