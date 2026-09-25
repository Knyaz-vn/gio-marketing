<?php
/**
 * PDF estimate template (rendered by dompdf: CSS 2.1, tables, DejaVu Sans for Cyrillic).
 * Variables: $lead (array), $settings (array).
 *
 * Override: copy to your-theme/ecodom-quote/pdf.php.
 *
 * @package EcodomQuote
 */

use EcodomQuote\Lead_Service;
use EcodomQuote\Util;

defined( 'ABSPATH' ) || exit;

$edq_r = $lead['result'];
$edq_c = $lead['contact'];
?>
<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="utf-8">
<title><?php echo esc_html( sprintf( /* translators: %d: lead id */ __( 'Estimate #%d', 'ecodom-quote' ), (int) $lead['id'] ) ); ?></title>
<style>
	@page { margin: 18mm 15mm; }
	body { font-family: "DejaVu Sans", sans-serif; font-size: 10pt; color: #1d2327; }
	h1 { font-size: 16pt; margin: 0 0 2mm; }
	h2 { font-size: 11pt; margin: 6mm 0 2mm; }
	.muted { color: #646970; }
	.head { width: 100%; border-bottom: 2px solid #2e7d32; padding-bottom: 3mm; margin-bottom: 4mm; }
	.head td { vertical-align: top; }
	.right { text-align: right; }
	table.items { width: 100%; border-collapse: collapse; }
	table.items th { background: #f0f5f0; text-align: left; font-size: 9pt; }
	table.items th, table.items td { border: 1px solid #d0d7de; padding: 2mm; }
	table.items td.num, table.items th.num { text-align: right; white-space: nowrap; }
	table.kv td { padding: 1mm 4mm 1mm 0; }
	.total { margin-top: 4mm; padding: 3mm; background: #f0f5f0; border-left: 3px solid #2e7d32; }
	.total strong { font-size: 13pt; }
	.warranty { margin-top: 6mm; padding: 3mm; border: 1px dashed #9aa5b1; font-size: 9pt; }
	.foot { margin-top: 8mm; font-size: 8pt; color: #646970; }
</style>
</head>
<body>
	<table class="head">
		<tr>
			<td>
				<h1><?php echo esc_html( (string) $settings['company_name'] ); ?></h1>
				<?php if ( ! empty( $settings['company_phone'] ) ) : ?>
					<div><?php echo esc_html( (string) $settings['company_phone'] ); ?></div>
				<?php endif; ?>
				<div class="muted"><?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?></div>
			</td>
			<td class="right">
				<strong><?php echo esc_html( sprintf( /* translators: %d: lead id */ __( 'Estimate #%d', 'ecodom-quote' ), (int) $lead['id'] ) ); ?></strong><br>
				<span class="muted"><?php echo esc_html( mysql2date( 'd.m.Y H:i', (string) $lead['created_at'] ) ); ?></span>
			</td>
		</tr>
	</table>

	<table class="kv">
		<tr><td class="muted"><?php esc_html_e( 'Customer', 'ecodom-quote' ); ?></td><td><?php echo esc_html( $edq_c['name'] ); ?>, <?php echo esc_html( $edq_c['phone'] ); ?> (<?php echo esc_html( Lead_Service::channels()[ $edq_c['channel'] ] ?? '' ); ?>)</td></tr>
		<tr><td class="muted"><?php esc_html_e( 'Object', 'ecodom-quote' ); ?></td><td><?php echo esc_html( 'fence' === $edq_r['mode'] ? __( 'Fence', 'ecodom-quote' ) : __( 'Roof', 'ecodom-quote' ) ); ?></td></tr>
		<tr><td class="muted"><?php esc_html_e( 'Model', 'ecodom-quote' ); ?></td><td><?php echo esc_html( $edq_r['model_title'] . ', ' . $edq_r['thickness'] . ' ' . __( 'mm', 'ecodom-quote' ) . ', ' . $edq_r['coating_txt'] ); ?></td></tr>
		<tr><td class="muted"><?php esc_html_e( 'Sheet area', 'ecodom-quote' ); ?></td><td><?php echo esc_html( Util::num( (float) $edq_r['sqm'] ) . ' ' . __( 'm²', 'ecodom-quote' ) ); ?> (<?php echo esc_html( __( 'covered', 'ecodom-quote' ) . ' ' . Util::num( (float) $edq_r['useful_sqm'] ) . ' ' . __( 'm²', 'ecodom-quote' ) ); ?>)</td></tr>
	</table>

	<?php if ( 'roof' === $edq_r['mode'] && ! empty( $edq_r['slopes'] ) ) : ?>
		<h2><?php esc_html_e( 'Slopes', 'ecodom-quote' ); ?></h2>
		<table class="items">
			<tr>
				<th>#</th>
				<th><?php esc_html_e( 'Shape', 'ecodom-quote' ); ?></th>
				<th class="num"><?php esc_html_e( 'Bottom, m', 'ecodom-quote' ); ?></th>
				<th class="num"><?php esc_html_e( 'Top, m', 'ecodom-quote' ); ?></th>
				<th class="num"><?php esc_html_e( 'Width, m', 'ecodom-quote' ); ?></th>
				<th class="num"><?php esc_html_e( 'Area, m²', 'ecodom-quote' ); ?></th>
				<th class="num"><?php esc_html_e( 'Sheets', 'ecodom-quote' ); ?></th>
			</tr>
			<?php foreach ( $edq_r['slopes'] as $edq_i => $edq_s ) : ?>
				<tr>
					<td><?php echo (int) $edq_i + 1; ?></td>
					<td><?php echo esc_html( 'trap' === $edq_s['shape'] ? __( 'Trapezoid / triangle', 'ecodom-quote' ) : __( 'Rectangle', 'ecodom-quote' ) ); ?></td>
					<td class="num"><?php echo esc_html( Util::num( (float) $edq_s['a'] ) ); ?></td>
					<td class="num"><?php echo esc_html( Util::num( (float) $edq_s['b'] ) ); ?></td>
					<td class="num"><?php echo esc_html( Util::num( (float) $edq_s['h'] ) ); ?></td>
					<td class="num"><?php echo esc_html( Util::num( (float) $edq_s['area'] ) ); ?></td>
					<td class="num"><?php echo (int) $edq_s['sheets']; ?></td>
				</tr>
			<?php endforeach; ?>
		</table>
	<?php elseif ( 'fence' === $edq_r['mode'] && ! empty( $edq_r['fence'] ) ) : ?>
		<h2><?php esc_html_e( 'Fence parameters', 'ecodom-quote' ); ?></h2>
		<table class="kv">
			<tr><td class="muted"><?php esc_html_e( 'Length', 'ecodom-quote' ); ?></td><td><?php echo esc_html( Util::num( (float) $edq_r['fence']['length'] ) . ' ' . __( 'm', 'ecodom-quote' ) ); ?></td></tr>
			<tr><td class="muted"><?php esc_html_e( 'Height', 'ecodom-quote' ); ?></td><td><?php echo esc_html( Util::num( (float) $edq_r['fence']['height'] ) . ' ' . __( 'm', 'ecodom-quote' ) ); ?></td></tr>
			<tr><td class="muted"><?php esc_html_e( 'Posts', 'ecodom-quote' ); ?></td><td><?php echo esc_html( $edq_r['fence']['post'] . ', ' . sprintf( /* translators: %s: spacing */ __( 'spacing %s m', 'ecodom-quote' ), Util::num( (float) $edq_r['fence']['post_step'] ) ) ); ?></td></tr>
		</table>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Materials', 'ecodom-quote' ); ?></h2>
	<table class="items">
		<tr>
			<th><?php esc_html_e( 'Item', 'ecodom-quote' ); ?></th>
			<th class="num"><?php esc_html_e( 'Qty', 'ecodom-quote' ); ?></th>
			<th class="num"><?php esc_html_e( 'Price', 'ecodom-quote' ); ?></th>
			<th class="num"><?php esc_html_e( 'Sum', 'ecodom-quote' ); ?></th>
		</tr>
		<?php foreach ( $edq_r['positions'] as $edq_p ) : ?>
			<tr>
				<td><?php echo esc_html( $edq_p['name'] ); ?></td>
				<td class="num">
					<?php echo esc_html( $edq_p['qty'] . ' ' . $edq_p['unit'] ); ?>
					<?php if ( null !== $edq_p['sqm'] ) : ?>
						<br><span class="muted"><?php echo esc_html( Util::num( (float) $edq_p['sqm'] ) . ' ' . __( 'm²', 'ecodom-quote' ) ); ?></span>
					<?php endif; ?>
				</td>
				<td class="num"><?php echo esc_html( Util::money( (float) $edq_p['price'] ) ); ?></td>
				<td class="num"><?php echo esc_html( Util::money( (float) $edq_p['sum'] ) ); ?></td>
			</tr>
		<?php endforeach; ?>
	</table>

	<div class="total">
		<?php esc_html_e( 'Estimated cost:', 'ecodom-quote' ); ?>
		<strong><?php echo esc_html( Util::money( (float) $edq_r['min'] ) . ' – ' . Util::money( (float) $edq_r['max'] ) ); ?></strong>
		<br><span class="muted">
			<?php
			/* translators: %s: amount */
			echo esc_html( sprintf( __( 'Base calculation: %s. The final price is confirmed by the manager after measurements.', 'ecodom-quote' ), Util::money( (float) $edq_r['total'] ) ) );
			?>
		</span>
	</div>

	<?php if ( ! empty( $settings['warranty_text'] ) ) : ?>
		<div class="warranty">
			<strong><?php esc_html_e( 'Warranty', 'ecodom-quote' ); ?></strong><br>
			<?php echo nl2br( esc_html( (string) $settings['warranty_text'] ) ); ?>
		</div>
	<?php endif; ?>

	<div class="foot">
		<?php esc_html_e( 'This estimate is preliminary and is not a public offer.', 'ecodom-quote' ); ?>
	</div>
</body>
</html>
