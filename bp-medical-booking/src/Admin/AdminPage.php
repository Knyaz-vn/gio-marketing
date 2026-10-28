<?php
declare(strict_types=1);

namespace BPMedical\Booking\Admin;

use BPMedical\Booking\Core\BookingStatus;
use BPMedical\Booking\Plugin;

/** Сторінка "BP Запис": SPA на assets/admin/admin.js поверх REST /admin/*. */
final class AdminPage
{
    private Plugin $p;

    public function __construct(Plugin $p)
    {
        $this->p = $p;
    }

    public function register(): void
    {
        add_action('admin_menu', function (): void {
            add_menu_page('BP Запис', 'BP Запис', Roles::CAP_BOOKINGS, 'bp-booking', [$this, 'render'], 'dashicons-calendar-alt', 26);
        });
        add_action('admin_enqueue_scripts', function (string $hook): void {
            if ($hook !== 'toplevel_page_bp-booking') {
                return;
            }
            wp_enqueue_style('bpmb-admin', plugins_url('assets/admin/admin.css', BPMB_FILE), [], BPMB_VERSION);
            wp_enqueue_script('bpmb-admin', plugins_url('assets/admin/admin.js', BPMB_FILE), [], BPMB_VERSION, true);
            wp_localize_script('bpmb-admin', 'BPMB_ADMIN', [
                'api' => esc_url_raw(rest_url(Plugin::REST_NS . '/admin')),
                'nonce' => wp_create_nonce('wp_rest'),
                'canSettings' => current_user_can(Roles::CAP_SETTINGS),
                'statuses' => BookingStatus::LABELS,
                'exportUrl' => wp_nonce_url(admin_url('admin-post.php?action=bpmb_export'), 'bpmb_export'),
                'mode' => $this->p->calendarMode(),
                'pageUrl' => $this->p->config()->get('booking_page_enabled') ? $this->p->bookingPageUrl() : null,
            ]);
        });
    }

    public function render(): void
    {
        echo '<div class="wrap"><h1>BP Medical: онлайн-запис</h1>';
        if ($this->p->config()->get('booking_page_enabled')) {
            $url = $this->p->bookingPageUrl();
            echo '<p class="bpmb-pagelink">Сторінка запису: <a href="' . esc_url($url) . '" target="_blank" rel="noopener"><strong>' . esc_html($url) . '</strong></a>'
                . ' · посилання для кнопок на сторінках лікарів: вкладка «Лікарі та графіки».</p>';
        }
        if ($this->p->config()->get('test_mode')) {
            echo '<div class="notice notice-info"><p><strong>Тестовий режим:</strong> сторінку й віджет запису бачать лише адміністратори й реєстратори, '
                . 'для пацієнтів їх немає (і старий попап Popup Maker працює як раніше). Щоб запустити для всіх, вимкніть у вкладці «Налаштування».</p></div>';
        }
        if ($this->p->calendarMode() !== 'google') {
            echo '<div class="notice notice-warning"><p><strong>Демо-режим:</strong> ключ service account не знайдено, використовується mock-календар. '
                . 'Див. README, розділ "Service account".</p></div>';
        }
        echo '<div id="bpmb-admin"><p>Завантаження…</p></div></div>';
    }
}
