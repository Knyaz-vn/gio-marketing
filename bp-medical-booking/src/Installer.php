<?php
declare(strict_types=1);

namespace BPMedical\Booking;

use BPMedical\Booking\Admin\Roles;
use BPMedical\Booking\Cron\Jobs;
use BPMedical\Booking\Storage\Schema;

final class Installer
{
    public static function activate(): void
    {
        self::migrate();
        Options::seed(false);
        Roles::install();
        Jobs::schedule();
    }

    public static function deactivate(): void
    {
        Jobs::unschedule();
        try {
            Plugin::instance()->watchManager()->stopAll();
        } catch (\Throwable $e) {
            // канали самі протермінуються
        }
    }

    public static function migrate(): void
    {
        global $wpdb;
        if (get_option(Options::DB_VERSION) === Schema::VERSION) {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        foreach (Schema::mysql($wpdb->prefix, $wpdb->get_charset_collate()) as $sql) {
            dbDelta($sql);
        }
        update_option(Options::DB_VERSION, Schema::VERSION, true);
    }
}
