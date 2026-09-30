<?php
/**
 * Інтеграції з формами: Elementor Pro Forms (у т.ч. всередині Popup Maker / Elementor Popup),
 * Contact Form 7, WPForms і загальний fallback для інших форм, що надсилають bp_attr[...].
 */

defined( 'ABSPATH' ) || exit;

class BP_Attr_Integrations {

	/** @var array|null Атрибуція заявки, оброблюваної в цьому запиті. */
	private static $current = null;

	/** @var string[] Email-и відправника заявки (листи на них не отримують блок атрибуції). */
	private static $submitter_emails = array();

	/** @var bool Заявку вже збережено конкретною інтеграцією. */
	private static $handled = false;

	public static function init() {
		add_action( 'elementor_pro/forms/process', array( __CLASS__, 'elementor' ), 5, 2 );
		add_action( 'wpcf7_before_send_mail', array( __CLASS__, 'cf7' ), 5, 3 );
		add_action( 'wpforms_process', array( __CLASS__, 'wpforms' ), 1000, 3 );
		add_filter( 'wp_mail', array( __CLASS__, 'mail' ), 20 );
		add_action( 'shutdown', array( __CLASS__, 'generic' ), 0 );
	}

	/**
	 * Позначає заявку як оброблену і запам'ятовує атрибуцію для листа.
	 */
	public static function handle( array $attr, array $lead ) {
		$emails = array();
		foreach ( (array) ( $lead['fields'] ?? array() ) as $v ) {
			if ( is_string( $v ) && is_email( $v ) ) {
				$emails[] = strtolower( $v );
			}
		}
		self::$handled          = true;
		self::$current          = $attr;
		self::$submitter_emails = $emails;
		return bp_attr_save_lead( $attr, $lead );
	}

	/* ---------------- Elementor Pro ---------------- */

	/**
	 * @param \ElementorPro\Modules\Forms\Classes\Form_Record $record
	 */
	public static function elementor( $record, $handler ) {
		$fields = array();
		$types  = array();
		foreach ( (array) $record->get( 'fields' ) as $id => $f ) {
			$fields[ $id ] = is_array( $f['value'] ?? null ) ? implode( ', ', $f['value'] ) : (string) ( $f['value'] ?? '' );
			$types[ $id ]  = $f['type'] ?? '';
		}
		// Поля, які могли бути додані в Elementor вручну (field ID = self_reported / location / specialty).
		$override = array();
		foreach ( array( 'self_reported', 'location', 'specialty' ) as $k ) {
			if ( ! empty( $fields[ $k ] ) ) {
				$override[ $k ] = $fields[ $k ];
			}
		}
		// Не дублюємо службові поля атрибуції (якщо їх додали в Elementor як Hidden) у JSON полів.
		$fields = array_diff_key( $fields, array_flip( bp_attr_field_keys() ) );

		$attr = bp_attr_collect( $override );
		$post = isset( $_POST['form_id'] ) ? sanitize_text_field( wp_unslash( $_POST['form_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		self::handle(
			$attr,
			array(
				'form_type' => 'elementor',
				'form_id'   => $attr['form_id'] ? $attr['form_id'] : $post,
				'form_name' => (string) $record->get_form_settings( 'form_name' ),
				'fields'    => $fields,
			)
		);
	}

	/* ---------------- Contact Form 7 ---------------- */

	public static function cf7( $contact_form, $abort = null, $submission = null ) {
		$submission = $submission ? $submission : ( class_exists( 'WPCF7_Submission' ) ? WPCF7_Submission::get_instance() : null );
		$data       = $submission ? (array) $submission->get_posted_data() : array();
		unset( $data['bp_attr'] );
		$fields = array();
		foreach ( $data as $k => $v ) {
			$fields[ $k ] = is_array( $v ) ? implode( ', ', $v ) : (string) $v;
		}
		$attr = bp_attr_collect( array_intersect_key( $fields, array_flip( array( 'self_reported', 'location' ) ) ) );
		self::handle(
			$attr,
			array(
				'form_type' => 'cf7',
				'form_id'   => $attr['form_id'] ? $attr['form_id'] : 'cf7-' . $contact_form->id(),
				'form_name' => $contact_form->title(),
				'fields'    => $fields,
			)
		);
	}

	/* ---------------- WPForms ---------------- */

	public static function wpforms( $fields, $entry, $form_data ) {
		$form_id = (int) ( $form_data['id'] ?? 0 );
		if ( function_exists( 'wpforms' ) && ! empty( wpforms()->process->errors[ $form_id ] ) ) {
			return;
		}
		$flat = array();
		foreach ( (array) $fields as $f ) {
			$flat[ sanitize_key( $f['name'] ?? $f['id'] ?? '' ) ] = (string) ( $f['value'] ?? '' );
		}
		$attr = bp_attr_collect();
		self::handle(
			$attr,
			array(
				'form_type' => 'wpforms',
				'form_id'   => $attr['form_id'] ? $attr['form_id'] : 'wpforms-' . $form_id,
				'form_name' => (string) ( $form_data['settings']['form_title'] ?? '' ),
				'fields'    => $flat,
			)
		);
	}

	/* ---------------- Лист адміністратору ---------------- */

	public static function mail( $args ) {
		if ( ! self::$current || ! apply_filters( 'bp_attr_append_to_email', true, $args ) ) {
			return $args;
		}
		$to = is_array( $args['to'] ) ? $args['to'] : explode( ',', (string) $args['to'] );
		foreach ( $to as $addr ) {
			if ( preg_match( '/[^\s<>]+@[^\s<>]+/', $addr, $m ) && in_array( strtolower( $m[0] ), self::$submitter_emails, true ) ) {
				return $args; // лист-підтвердження самому пацієнту - без атрибуції
			}
		}
		$line    = bp_attr_email_line( self::$current );
		$headers = is_array( $args['headers'] ) ? implode( "\n", $args['headers'] ) : (string) $args['headers'];
		$is_html = stripos( $headers, 'text/html' ) !== false || preg_match( '/<(br|p|div|table)\b/i', (string) $args['message'] );
		$args['message'] .= $is_html ? '<br><br>' . esc_html( $line ) : "\n\n" . $line;
		return $args;
	}

	/* ---------------- Інші форми (fallback) ---------------- */

	/**
	 * Будь-яка інша форма, яку JS доповнив полями bp_attr[...] (кастомні форми теми,
	 * форма "Нам прикро", форми акцій на admin-ajax / admin-post тощо).
	 */
	public static function generic() {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( self::$handled || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['bp_attr'] ) || ! is_array( $_POST['bp_attr'] ) ) {
			return;
		}
		if ( ! apply_filters( 'bp_attr_generic_capture', true ) ) {
			return;
		}
		$fields = array();
		foreach ( wp_unslash( $_POST ) as $k => $v ) {
			if ( in_array( $k, array( 'bp_attr', 'action', '_wpnonce', '_wp_http_referer', 'g-recaptcha-response' ), true ) ) {
				continue;
			}
			if ( is_array( $v ) ) {
				// form_fields[...] та подібні вкладені масиви
				foreach ( $v as $k2 => $v2 ) {
					if ( is_scalar( $v2 ) ) {
						$fields[ sanitize_key( $k2 ) ] = sanitize_text_field( (string) $v2 );
					}
				}
			} elseif ( is_scalar( $v ) ) {
				$fields[ sanitize_key( $k ) ] = sanitize_text_field( (string) $v );
			}
		}
		// Зберігаємо лише справжні заявки: має бути контакт (телефон або email).
		$has_contact = false;
		foreach ( $fields as $k => $v ) {
			if ( is_email( $v ) || ( preg_match( '/phone|tel|телефон/i', $k ) && preg_match( '/\d{6,}/', preg_replace( '/\D/', '', $v ) ) ) ) {
				$has_contact = true;
			}
		}
		if ( ! $has_contact ) {
			return;
		}
		$attr = bp_attr_collect();
		bp_attr_save_lead(
			$attr,
			array(
				'form_type' => 'generic',
				'form_id'   => $attr['form_id'],
				'fields'    => $fields,
			)
		);
		// phpcs:enable
	}
}
