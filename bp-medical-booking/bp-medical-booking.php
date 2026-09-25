<?php
/**
 * Plugin Name:       BP Medical: онлайн-запис до лікарів
 * Description:       Онлайн-запис на основі Google Calendar: вільні слоти за прізвищем лікаря в назві події, віджет запису, адмінка заявок.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            BP Medical
 * Text Domain:       bp-medical-booking
 * License:           Proprietary
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('BPMB_VERSION', '1.0.0');
define('BPMB_FILE', __FILE__);
define('BPMB_DIR', __DIR__);

// PSR-4 автолоадер (плагін не потребує composer у продакшені).
spl_autoload_register(static function (string $class): void {
    $prefix = 'BPMedical\\Booking\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $rel = substr($class, strlen($prefix));
    if (strpos($rel, 'Tests\\') === 0) {
        return;
    }
    $file = BPMB_DIR . '/src/' . str_replace('\\', '/', $rel) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

register_activation_hook(__FILE__, [\BPMedical\Booking\Installer::class, 'activate']);
register_deactivation_hook(__FILE__, [\BPMedical\Booking\Installer::class, 'deactivate']);

add_action('plugins_loaded', static function (): void {
    \BPMedical\Booking\Plugin::instance()->boot();
});
