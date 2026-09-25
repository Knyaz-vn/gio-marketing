<?php
declare(strict_types=1);

namespace BPMedical\Booking\Frontend;

use BPMedical\Booking\Plugin;

/**
 * Підключення віджета:
 *  - шорткод [bp_booking doctor="slug" specialty="slug" entry="doctor_page" mode="inline|button" label="..."];
 *  - блок "bp/booking" (Gutenberg);
 *  - глобально (load_globally): модалка для будь-якої кнопки .bp-booking-open, перехоплення
 *    тригерів Popup Maker #popmake-13597, збереження UTM/click id на 90 днів.
 */
final class Frontend
{
    private Plugin $p;

    public function __construct(Plugin $p)
    {
        $this->p = $p;
    }

    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'assets']);
        add_shortcode('bp_booking', [$this, 'shortcode']);
        add_action('init', [$this, 'block']);
    }

    public function assets(): void
    {
        wp_register_style('bpmb-widget', plugins_url('assets/widget/booking.css', BPMB_FILE), [], BPMB_VERSION);
        wp_register_script('bpmb-widget', plugins_url('assets/widget/booking.js', BPMB_FILE), [], BPMB_VERSION, true);
        $cfg = $this->p->config();
        wp_add_inline_script('bpmb-widget', 'window.BPMB_CONFIG=' . wp_json_encode([
            'api' => esc_url_raw(rest_url(Plugin::REST_NS)),
            'privacyUrl' => $cfg->get('privacy_url'),
            'popupId' => (string) $cfg->get('intercept_popup_id'),
            'phone' => $cfg->get('callback_phone'),
        ]) . ';', 'before');
        if ($cfg->get('load_globally')) {
            $this->enqueue();
        }
    }

    public function enqueue(): void
    {
        wp_enqueue_style('bpmb-widget');
        wp_enqueue_script('bpmb-widget');
    }

    /** @param array<string, string>|string $atts */
    public function shortcode($atts): string
    {
        $a = shortcode_atts(['doctor' => '', 'specialty' => '', 'entry' => '', 'mode' => 'inline', 'label' => 'Записатися онлайн'], (array) $atts, 'bp_booking');
        $this->enqueue();
        $data = '';
        foreach (['doctor', 'specialty', 'entry'] as $k) {
            if ($a[$k] !== '') {
                $data .= ' data-' . $k . '="' . esc_attr($a[$k]) . '"';
            }
        }
        if ($a['mode'] === 'button') {
            return '<button type="button" class="bp-booking-open bpb-cta"' . $data . '>' . esc_html($a['label']) . '</button>';
        }
        return '<div class="bp-booking"' . $data . '></div>';
    }

    public function block(): void
    {
        if (!function_exists('register_block_type')) {
            return;
        }
        wp_register_script('bpmb-block', plugins_url('assets/widget/block.js', BPMB_FILE), ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components'], BPMB_VERSION, true);
        register_block_type('bp/booking', [
            'editor_script' => 'bpmb-block',
            'attributes' => [
                'doctor' => ['type' => 'string', 'default' => ''],
                'specialty' => ['type' => 'string', 'default' => ''],
                'entry' => ['type' => 'string', 'default' => ''],
                'mode' => ['type' => 'string', 'default' => 'inline'],
                'label' => ['type' => 'string', 'default' => 'Записатися онлайн'],
            ],
            'render_callback' => fn(array $attrs) => $this->shortcode($attrs),
        ]);
    }
}
