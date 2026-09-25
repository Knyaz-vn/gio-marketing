<?php
/**
 * Calculator markup. Variables: $uid, $data, $modes, $fixed, $warranty.
 *
 * Override: copy to your-theme/ecodom-quote/calculator.php (see edq_template filter in readme).
 *
 * @package EcodomQuote
 */

defined( 'ABSPATH' ) || exit;

$edq_single = 1 === count( $data['models'] );
?>
<div class="edq-root" id="<?php echo esc_attr( $uid ); ?>" data-edq="<?php echo esc_attr( (string) wp_json_encode( $data ) ); ?>">
	<form class="edq-calc" data-edq-calc novalidate>
		<?php if ( count( $modes ) > 1 ) : ?>
			<fieldset class="edq-field edq-modes">
				<legend class="edq-label"><?php esc_html_e( 'What are we calculating?', 'ecodom-quote' ); ?></legend>
				<div class="edq-segmented">
					<label class="edq-seg"><input type="radio" name="mode" value="roof" checked><span><?php esc_html_e( 'Roof', 'ecodom-quote' ); ?></span></label>
					<label class="edq-seg"><input type="radio" name="mode" value="fence"><span><?php esc_html_e( 'Fence', 'ecodom-quote' ); ?></span></label>
				</div>
			</fieldset>
		<?php else : ?>
			<input type="hidden" name="mode" value="<?php echo esc_attr( $modes[0] ); ?>">
		<?php endif; ?>

		<div class="edq-grid">
			<div class="edq-field<?php echo $edq_single ? ' edq-hidden' : ''; ?>">
				<label class="edq-label" for="<?php echo esc_attr( $uid ); ?>-model"><?php esc_html_e( 'Model', 'ecodom-quote' ); ?></label>
				<select class="edq-input" id="<?php echo esc_attr( $uid ); ?>-model" name="model_id" required>
					<?php if ( ! $edq_single ) : ?>
						<option value=""><?php esc_html_e( '— select a model —', 'ecodom-quote' ); ?></option>
					<?php endif; ?>
					<?php foreach ( $data['models'] as $edq_m ) : ?>
						<option value="<?php echo esc_attr( (string) $edq_m['id'] ); ?>" data-type="<?php echo esc_attr( $edq_m['type'] ); ?>"><?php echo esc_html( $edq_m['title'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="edq-field">
				<label class="edq-label" for="<?php echo esc_attr( $uid ); ?>-thickness"><?php esc_html_e( 'Thickness, mm', 'ecodom-quote' ); ?></label>
				<select class="edq-input" id="<?php echo esc_attr( $uid ); ?>-thickness" name="thickness" required></select>
			</div>
			<div class="edq-field">
				<label class="edq-label" for="<?php echo esc_attr( $uid ); ?>-coating"><?php esc_html_e( 'Coating', 'ecodom-quote' ); ?></label>
				<select class="edq-input" id="<?php echo esc_attr( $uid ); ?>-coating" name="coating" required></select>
			</div>
		</div>

		<section class="edq-section" data-edq-mode="roof" hidden>
			<h3 class="edq-h"><?php esc_html_e( 'Roof slopes', 'ecodom-quote' ); ?></h3>
			<p class="edq-hint"><?php esc_html_e( 'Enter dimensions along the slope surface, in metres, including the eave overhang. A triangle is a trapezoid with a top of 0.', 'ecodom-quote' ); ?></p>
			<div class="edq-slopes" data-edq-slopes></div>
			<button type="button" class="edq-btn edq-btn--ghost" data-edq-add><?php esc_html_e( '+ Add slope', 'ecodom-quote' ); ?></button>
		</section>

		<section class="edq-section" data-edq-mode="fence" hidden>
			<h3 class="edq-h"><?php esc_html_e( 'Fence', 'ecodom-quote' ); ?></h3>
			<div class="edq-grid">
				<div class="edq-field">
					<label class="edq-label" for="<?php echo esc_attr( $uid ); ?>-flen"><?php esc_html_e( 'Fence length, m', 'ecodom-quote' ); ?></label>
					<input class="edq-input" id="<?php echo esc_attr( $uid ); ?>-flen" name="fence_length" type="text" inputmode="decimal" autocomplete="off" placeholder="30">
				</div>
				<div class="edq-field">
					<label class="edq-label" for="<?php echo esc_attr( $uid ); ?>-fh"><?php esc_html_e( 'Height, m', 'ecodom-quote' ); ?></label>
					<input class="edq-input" id="<?php echo esc_attr( $uid ); ?>-fh" name="fence_height" type="text" inputmode="decimal" autocomplete="off" placeholder="2">
				</div>
				<div class="edq-field">
					<label class="edq-label" for="<?php echo esc_attr( $uid ); ?>-fp"><?php esc_html_e( 'Post type', 'ecodom-quote' ); ?></label>
					<select class="edq-input" id="<?php echo esc_attr( $uid ); ?>-fp" name="fence_post">
						<?php foreach ( $data['posts'] as $edq_p ) : ?>
							<option value="<?php echo esc_attr( $edq_p['id'] ); ?>"><?php echo esc_html( $edq_p['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>
		</section>

		<div class="edq-actions">
			<button type="submit" class="edq-btn edq-btn--primary" data-edq-calc-btn><?php esc_html_e( 'Calculate', 'ecodom-quote' ); ?></button>
		</div>
		<p class="edq-msg" data-edq-calc-msg role="alert" aria-live="assertive"></p>
	</form>

	<template data-edq-slope-tpl>
		<fieldset class="edq-slope">
			<legend class="edq-slope__title"></legend>
			<div class="edq-slope__grid">
				<label class="edq-field">
					<span class="edq-label"><?php esc_html_e( 'Shape', 'ecodom-quote' ); ?></span>
					<select class="edq-input" data-k="shape">
						<option value="rect"><?php esc_html_e( 'Rectangle', 'ecodom-quote' ); ?></option>
						<option value="trap"><?php esc_html_e( 'Trapezoid / triangle', 'ecodom-quote' ); ?></option>
					</select>
				</label>
				<label class="edq-field">
					<span class="edq-label" data-label-rect="<?php esc_attr_e( 'Length along eave, m', 'ecodom-quote' ); ?>" data-label-trap="<?php esc_attr_e( 'Bottom (eave), m', 'ecodom-quote' ); ?>"><?php esc_html_e( 'Length along eave, m', 'ecodom-quote' ); ?></span>
					<input class="edq-input" data-k="a" type="text" inputmode="decimal" autocomplete="off" required>
				</label>
				<label class="edq-field" data-only="trap" hidden>
					<span class="edq-label"><?php esc_html_e( 'Top (ridge), m', 'ecodom-quote' ); ?></span>
					<input class="edq-input" data-k="b" type="text" inputmode="decimal" autocomplete="off" value="0">
				</label>
				<label class="edq-field">
					<span class="edq-label"><?php esc_html_e( 'Width: eave to ridge, m', 'ecodom-quote' ); ?></span>
					<input class="edq-input" data-k="h" type="text" inputmode="decimal" autocomplete="off" required>
				</label>
			</div>
			<button type="button" class="edq-slope__remove" data-edq-remove></button>
		</fieldset>
	</template>

	<section class="edq-result" data-edq-result hidden aria-live="polite">
		<h3 class="edq-h"><?php esc_html_e( 'Your estimate', 'ecodom-quote' ); ?></h3>
		<div class="edq-summary" data-edq-summary></div>
		<div class="edq-table-wrap">
			<table class="edq-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Item', 'ecodom-quote' ); ?></th>
						<th scope="col" class="edq-num"><?php esc_html_e( 'Qty', 'ecodom-quote' ); ?></th>
						<th scope="col" class="edq-num"><?php esc_html_e( 'Price', 'ecodom-quote' ); ?></th>
						<th scope="col" class="edq-num"><?php esc_html_e( 'Sum', 'ecodom-quote' ); ?></th>
					</tr>
				</thead>
				<tbody data-edq-rows></tbody>
			</table>
		</div>
		<div class="edq-price">
			<span class="edq-price__label"><?php esc_html_e( 'Estimated cost', 'ecodom-quote' ); ?></span>
			<strong class="edq-price__value" data-edq-range></strong>
			<span class="edq-price__note"><?php esc_html_e( 'The final price depends on the current price list and measurements.', 'ecodom-quote' ); ?></span>
		</div>
		<?php if ( '' !== trim( $warranty ) ) : ?>
			<p class="edq-warranty"><?php echo nl2br( esc_html( $warranty ) ); ?></p>
		<?php endif; ?>

		<form class="edq-lead" data-edq-lead novalidate>
			<h3 class="edq-h"><?php esc_html_e( 'Get the exact estimate in PDF and a manager consultation', 'ecodom-quote' ); ?></h3>
			<div class="edq-grid">
				<div class="edq-field">
					<label class="edq-label" for="<?php echo esc_attr( $uid ); ?>-name"><?php esc_html_e( 'Name', 'ecodom-quote' ); ?></label>
					<input class="edq-input" id="<?php echo esc_attr( $uid ); ?>-name" name="name" type="text" autocomplete="name" maxlength="80" required>
				</div>
				<div class="edq-field">
					<label class="edq-label" for="<?php echo esc_attr( $uid ); ?>-phone"><?php esc_html_e( 'Phone', 'ecodom-quote' ); ?></label>
					<input class="edq-input" id="<?php echo esc_attr( $uid ); ?>-phone" name="phone" type="tel" autocomplete="tel" inputmode="tel" placeholder="+380 (__) ___-__-__" required data-edq-phone>
				</div>
			</div>
			<fieldset class="edq-field">
				<legend class="edq-label"><?php esc_html_e( 'How should we contact you?', 'ecodom-quote' ); ?></legend>
				<div class="edq-segmented">
					<?php foreach ( \EcodomQuote\Lead_Service::channels() as $edq_val => $edq_label ) : ?>
						<label class="edq-seg"><input type="radio" name="channel" value="<?php echo esc_attr( $edq_val ); ?>" <?php checked( 'call', $edq_val ); ?>><span><?php echo esc_html( $edq_label ); ?></span></label>
					<?php endforeach; ?>
				</div>
			</fieldset>

			<?php foreach ( \EcodomQuote\Attribution::KEYS as $edq_key ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $edq_key ); ?>" value="">
			<?php endforeach; ?>
			<input type="hidden" name="page_url" value="">
			<input type="hidden" name="edq_ts" value="">
			<div class="edq-hp" aria-hidden="true">
				<label><?php esc_html_e( 'Leave this field empty', 'ecodom-quote' ); ?><input type="text" name="edq_website" tabindex="-1" autocomplete="off" value=""></label>
			</div>

			<div class="edq-actions">
				<button type="submit" class="edq-btn edq-btn--primary" data-edq-lead-btn><?php esc_html_e( 'Get estimate', 'ecodom-quote' ); ?></button>
			</div>
			<p class="edq-consent"><?php esc_html_e( 'By submitting the form you agree to the processing of personal data.', 'ecodom-quote' ); ?></p>
			<p class="edq-msg" data-edq-lead-msg role="alert" aria-live="assertive"></p>
		</form>
		<div class="edq-success" data-edq-success hidden tabindex="-1">
			<strong><?php esc_html_e( 'Thank you! Your request has been received.', 'ecodom-quote' ); ?></strong>
			<span><?php esc_html_e( 'A manager will contact you shortly.', 'ecodom-quote' ); ?></span>
		</div>
	</section>
</div>
