<?php
declare(strict_types=1);

namespace BPMedical\Booking\Cron;

use BPMedical\Booking\Plugin;

/**
 * Регулярні задачі WP-Cron:
 *  - bpmb_sync: читання календарів кожні 2 хв (+ bpmb_sync_now за push-сповіщенням);
 *  - bpmb_hourly: продовження каналів events.watch, очищення прострочених holds;
 *  - bpmb_daily: анонімізація заявок старших за retention_days.
 *
 * WP-Cron спрацьовує від відвідувань. Для стабільних 2 хв налаштуйте системний cron (див. README).
 */
final class Jobs
{
    public const SYNC = 'bpmb_sync';
    public const SYNC_NOW = 'bpmb_sync_now';
    public const HOURLY = 'bpmb_hourly';
    public const DAILY = 'bpmb_daily';
    public const SCHEDULE = 'bpmb_every_2_min';

    public static function register(): void
    {
        add_filter('cron_schedules', static function (array $s): array {
            $s[self::SCHEDULE] = ['interval' => 120, 'display' => 'BP Medical: кожні 2 хвилини'];
            return $s;
        });
        add_action(self::SYNC, [self::class, 'sync']);
        add_action(self::SYNC_NOW, [self::class, 'sync']);
        add_action(self::HOURLY, [self::class, 'hourly']);
        add_action(self::DAILY, [self::class, 'daily']);
        // Самовідновлення розкладу (наприклад, після міграції БД).
        add_action('init', static function (): void {
            if (!wp_next_scheduled(self::SYNC)) {
                self::schedule();
            }
        });
    }

    public static function schedule(): void
    {
        add_filter('cron_schedules', static function (array $s): array {
            $s[self::SCHEDULE] = ['interval' => 120, 'display' => 'BP Medical: кожні 2 хвилини'];
            return $s;
        });
        if (!wp_next_scheduled(self::SYNC)) {
            wp_schedule_event(time() + 10, self::SCHEDULE, self::SYNC);
        }
        if (!wp_next_scheduled(self::HOURLY)) {
            wp_schedule_event(time() + 60, 'hourly', self::HOURLY);
        }
        if (!wp_next_scheduled(self::DAILY)) {
            wp_schedule_event(strtotime('tomorrow 01:30'), 'daily', self::DAILY);
        }
    }

    public static function unschedule(): void
    {
        foreach ([self::SYNC, self::HOURLY, self::DAILY, self::SYNC_NOW] as $hook) {
            wp_clear_scheduled_hook($hook);
        }
    }

    public static function sync(): void
    {
        Plugin::instance()->runSync();
    }

    public static function hourly(): void
    {
        $p = Plugin::instance();
        $p->holdRepository()->purgeExpired(time());
        $p->ensureWatch();
    }

    public static function daily(): void
    {
        Plugin::instance()->runRetention();
    }
}
