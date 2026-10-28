<?php
declare(strict_types=1);

namespace BPMedical\Booking\Frontend;

use BPMedical\Booking\Plugin;
use BPMedical\Booking\Security\Turnstile;

/**
 * Підключення запису на сайті:
 *  - окрема сторінка /zapys/ без теми сайту (BookingPage), діплінки ?doctor=slug, ?specialty=slug, ?entry=header;
 *  - шорткод [bp_booking doctor="slug" specialty="slug" entry="doctor_page" mode="inline|button" label="..."];
 *  - блок "bp/booking" (Gutenberg);
 *  - глобально (load_globally): кнопки .bp-booking-open і тригери Popup Maker #popmake-13597 ведуть на
 *    сторінку запису (button_mode=page) або відкривають модалку (button_mode=modal); збереження UTM/click id на 90 днів.
 */
final class Frontend
{
    public const QUERY_VAR = 'bpmb_booking_page';

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
        add_action('init', [$this, 'rewrite']);
        add_filter('query_vars', static function (array $vars): array {
            $vars[] = self::QUERY_VAR;
            return $vars;
        });
        add_action('template_redirect', [$this, 'maybeRenderPage'], 0);
    }

    /** Правило /zapys/ → окрема сторінка запису. Правила перебудовуються, лише коли змінився slug. */
    public function rewrite(): void
    {
        $cfg = $this->p->config();
        if (!$cfg->get('booking_page_enabled')) {
            return;
        }
        $slug = (string) $cfg->get('booking_page_slug');
        add_rewrite_rule('^' . preg_quote($slug, '#') . '/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top');
        $key = $slug . '|' . BPMB_VERSION;
        if (get_option('bpmb_rewrite_key') !== $key) {
            // Після init інших плагінів, щоб не загубити їхні правила.
            add_action('wp_loaded', static function () use ($key): void {
                flush_rewrite_rules(false);
                update_option('bpmb_rewrite_key', $key, true);
            });
        }
    }

    public function maybeRenderPage(): void
    {
        if (!get_query_var(self::QUERY_VAR) || !$this->p->config()->get('booking_page_enabled')) {
            return;
        }
        if (!$this->p->widgetVisible()) {
            // Тестовий режим: для відвідувачів сторінки не існує.
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
            nocache_headers();
            return;
        }
        (new BookingPage($this->p, $this))->render();
        exit;
    }

    /** @return array<string, mixed> window.BPMB_CONFIG */
    public function widgetConfig(): array
    {
        $cfg = $this->p->config();
        $page = (bool) $cfg->get('booking_page_enabled');
        return [
            'api' => esc_url_raw(rest_url(Plugin::REST_NS)),
            'privacyUrl' => $cfg->get('privacy_url'),
            'popupId' => (string) $cfg->get('intercept_popup_id'),
            'phone' => $cfg->get('callback_phone'),
            'cssUrl' => plugins_url('assets/widget/booking.css', BPMB_FILE) . '?ver=' . BPMB_VERSION,
            // Turnstile не гарантує роботу всередині Shadow DOM, тож з ним віджет рендериться без ізоляції.
            'shadow' => !Turnstile::enabled((string) $cfg->get('turnstile_site_key')),
            'testMode' => (bool) $cfg->get('test_mode'),
            'demo' => $this->p->calendarMode() !== 'google',
            'nonce' => $cfg->get('test_mode') ? wp_create_nonce('wp_rest') : null,
            'buttonMode' => $page && $cfg->get('button_mode') === 'page' ? 'page' : 'modal',
            'pageUrl' => $page ? $this->p->bookingPageUrl() : null,
        ];
    }

    public function assets(): void
    {
        if (!$this->p->widgetVisible()) {
            return; // тестовий режим: звичайні відвідувачі не отримують ні віджета, ні скриптів
        }
        wp_register_script('bpmb-widget', plugins_url('assets/widget/booking.js', BPMB_FILE), [], BPMB_VERSION, true);
        wp_add_inline_script('bpmb-widget', 'window.BPMB_CONFIG=' . wp_json_encode($this->widgetConfig()) . ';', 'before');
        if ($this->p->config()->get('load_globally')) {
            $this->enqueue();
        }
    }

    public function enqueue(): void
    {
        wp_enqueue_script('bpmb-widget');
    }

    /** @param array<string, string>|string $atts */
    public function shortcode($atts): string
    {
        $a = shortcode_atts(['doctor' => '', 'specialty' => '', 'entry' => '', 'mode' => 'inline', 'label' => 'Записатися онлайн'], (array) $atts, 'bp_booking');
        if (!$this->p->widgetVisible()) {
            return '';
        }
        if ($a['doctor'] === '' && $a['specialty'] === '') {
            // Один шорткод для спільного шаблону сторінок лікарів: лікаря визначаємо за адресою сторінки.
            $a['doctor'] = $this->doctorForCurrentPage();
            if ($a['doctor'] !== '' && $a['entry'] === '') {
                $a['entry'] = 'doctor_page';
            }
        }
        $this->enqueue();
        $data = '';
        foreach (['doctor', 'specialty', 'entry'] as $k) {
            if ($a[$k] !== '') {
                $data .= ' data-' . $k . '="' . esc_attr($a[$k]) . '"';
            }
        }
        if ($a['mode'] === 'button') {
            // Звичайне посилання на сторінку запису: працює навіть без JS; у режимі modal JS відкриє вікно.
            $href = $this->p->config()->get('booking_page_enabled')
                ? $this->p->bookingPageUrl(['doctor' => $a['doctor'], 'specialty' => $a['specialty'], 'entry' => $a['entry']])
                : '#';
            return '<a href="' . esc_url($href) . '" class="bp-booking-open bpb-cta"' . $data . '>' . esc_html($a['label']) . '</a>';
        }
        return '<div class="bp-booking"' . $data . '></div>';
    }

    /** Slug лікаря, якщо поточна сторінка є його профілем (profile_url), інакше ''. */
    private function doctorForCurrentPage(): string
    {
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $path = '/' . trim(rawurldecode($path), '/') . '/';
        foreach ($this->p->catalog()->doctors() as $d) {
            if (!$d['bookable'] || empty($d['profile_url'])) {
                continue;
            }
            $own = '/' . trim(rawurldecode((string) parse_url((string) $d['profile_url'], PHP_URL_PATH)), '/') . '/';
            if ($own !== '//' && $own === $path) {
                return (string) $d['slug'];
            }
        }
        return '';
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
