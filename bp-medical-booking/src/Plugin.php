<?php
declare(strict_types=1);

namespace BPMedical\Booking;

use BPMedical\Booking\Admin\AdminPage;
use BPMedical\Booking\Admin\Export;
use BPMedical\Booking\Booking\BookingService;
use BPMedical\Booking\Booking\DbBusyProvider;
use BPMedical\Booking\Booking\Logger;
use BPMedical\Booking\Booking\Notifier;
use BPMedical\Booking\Calendar\CalendarClient;
use BPMedical\Booking\Calendar\CalendarSync;
use BPMedical\Booking\Calendar\GoogleCalendarClient;
use BPMedical\Booking\Calendar\MockCalendarClient;
use BPMedical\Booking\Calendar\ServiceAccountAuth;
use BPMedical\Booking\Calendar\WatchManager;
use BPMedical\Booking\Calendar\WpHttpTransport;
use BPMedical\Booking\Core\AvailabilityService;
use BPMedical\Booking\Core\Catalog;
use BPMedical\Booking\Core\Config;
use BPMedical\Booking\Core\EventClassifier;
use BPMedical\Booking\Core\ScheduleResolver;
use BPMedical\Booking\Core\SlotGenerator;
use BPMedical\Booking\Core\SurnameMatcher;
use BPMedical\Booking\Core\SystemClock;
use BPMedical\Booking\Cron\Jobs;
use BPMedical\Booking\Frontend\Frontend;
use BPMedical\Booking\Notify\CompositeNotifier;
use BPMedical\Booking\Notify\EmailNotifier;
use BPMedical\Booking\Notify\TelegramNotifier;
use BPMedical\Booking\Rest\AdminController;
use BPMedical\Booking\Rest\PublicController;
use BPMedical\Booking\Security\Cors;
use BPMedical\Booking\Security\Secrets;
use BPMedical\Booking\Storage\BookingRepository;
use BPMedical\Booking\Storage\CallbackRepository;
use BPMedical\Booking\Storage\EventCacheRepository;
use BPMedical\Booking\Storage\HoldRepository;
use BPMedical\Booking\Storage\WpdbConnection;

/**
 * Точка збирання: створює сервіси (ліниво) і реєструє хуки WordPress.
 */
final class Plugin
{
    public const REST_NS = 'bp-booking/v1';

    private static ?Plugin $instance = null;
    /** @var array<string, object> */
    private array $services = [];

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function boot(): void
    {
        Jobs::register();
        add_action('admin_init', [Installer::class, 'migrate']);
        add_action('rest_api_init', function (): void {
            (new PublicController($this))->register();
            (new AdminController($this))->register();
        });
        Cors::register((array) $this->config()->get('cors_origins'));
        (new Frontend($this))->register();
        if (is_admin()) {
            (new AdminPage($this))->register();
            (new Export($this))->register();
        }
    }

    /** Скидає кеш сервісів після зміни налаштувань у тому ж запиті. */
    public function reset(): void
    {
        $this->services = [];
    }

    /**
     * @template T of object
     * @param class-string<T>|string $key
     * @param callable(): T $factory
     * @return T
     */
    private function lazy(string $key, callable $factory)
    {
        if (!isset($this->services[$key])) {
            $this->services[$key] = $factory();
        }
        return $this->services[$key];
    }

    public function config(): Config
    {
        return $this->lazy('config', static fn() => new Config(Options::get(Options::SETTINGS)));
    }

    public function catalog(): Catalog
    {
        return $this->lazy('catalog', static fn() => new Catalog(
            Options::get(Options::DOCTORS),
            Options::get(Options::SPECIALTIES),
            Options::get(Options::SERVICES),
            Options::get(Options::LOCATIONS)
        ));
    }

    public function db(): WpdbConnection
    {
        return $this->lazy('db', static function () {
            global $wpdb;
            return new WpdbConnection($wpdb);
        });
    }

    public function logger(): Logger
    {
        return $this->lazy('logger', static fn() => new Logger());
    }

    public function clock(): SystemClock
    {
        return $this->lazy('clock', static fn() => new SystemClock());
    }

    /** google, якщо є ключ service account (або примусово), інакше mock. */
    public function calendarMode(): string
    {
        $mode = (string) $this->config()->get('calendar_mode');
        if ($mode === 'mock') {
            return 'mock';
        }
        if (Secrets::googleServiceAccount() !== null) {
            return 'google';
        }
        return $mode === 'google' ? 'google-missing-key' : 'mock';
    }

    public function calendarClient(): CalendarClient
    {
        return $this->lazy('calendar', function () {
            $sa = Secrets::googleServiceAccount();
            if ($this->calendarMode() === 'google' && $sa !== null) {
                $http = new WpHttpTransport();
                $cacheKey = 'bpmb_gtoken_' . substr(md5((string) $sa['client_email']), 0, 10);
                $auth = new ServiceAccountAuth(
                    $sa,
                    $http,
                    static fn() => get_transient($cacheKey) ?: null,
                    static function (array $t) use ($cacheKey): void {
                        set_transient($cacheKey, $t, max(60, $t['expires'] - time() - 60));
                    }
                );
                return new GoogleCalendarClient($auth, $http);
            }
            if ($this->calendarMode() === 'google-missing-key') {
                throw new \RuntimeException('calendar_mode=google, але ключ service account не знайдено (див. README)');
            }
            $client = new MockCalendarClient($this->mockFile());
            $client->seedDemo($this->config()->calendars(), time());
            return $client;
        });
    }

    /** Файл mock-календаря в захищеній теці uploads (закрито від вебу). */
    public function mockFile(): string
    {
        $dir = trailingslashit(wp_upload_dir()['basedir']) . 'bpmb-private';
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
            @file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
            @file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
        }
        return $dir . '/mock-calendar-' . substr(hash('sha256', wp_salt('auth')), 0, 16) . '.json';
    }

    public function matcher(): SurnameMatcher
    {
        return $this->lazy('matcher', fn() => new SurnameMatcher($this->catalog()->aliasesByDoctor(), (string) $this->config()->get('match_mode')));
    }

    public function classifier(): EventClassifier
    {
        return $this->lazy('classifier', fn() => new EventClassifier($this->matcher()));
    }

    public function eventRepository(): EventCacheRepository
    {
        return $this->lazy('events', fn() => new EventCacheRepository($this->db()));
    }

    public function holdRepository(): HoldRepository
    {
        return $this->lazy('holds', fn() => new HoldRepository($this->db()));
    }

    public function bookingRepository(): BookingRepository
    {
        return $this->lazy('bookings', fn() => new BookingRepository($this->db()));
    }

    public function callbackRepository(): CallbackRepository
    {
        return $this->lazy('callbacks', fn() => new CallbackRepository($this->db()));
    }

    public function availability(): AvailabilityService
    {
        return $this->lazy('availability', fn() => new AvailabilityService(
            $this->catalog(),
            new SlotGenerator($this->config(), new ScheduleResolver($this->config()), $this->clock()),
            new DbBusyProvider($this->eventRepository(), $this->holdRepository(), $this->bookingRepository(), $this->clock())
        ));
    }

    public function calendarSync(): CalendarSync
    {
        return $this->lazy('sync', fn() => new CalendarSync(
            $this->config(),
            $this->calendarClient(),
            $this->eventRepository(),
            $this->classifier(),
            $this->clock(),
            $this->logger(),
            $this->bookingRepository()
        ));
    }

    public function bookingService(): BookingService
    {
        return $this->lazy('booking', fn() => new BookingService(
            $this->db(),
            $this->catalog(),
            $this->config(),
            $this->availability(),
            $this->holdRepository(),
            $this->bookingRepository(),
            $this->eventRepository(),
            $this->classifier(),
            $this->calendarClient(),
            $this->calendarSync(),
            $this->notifier(),
            $this->clock(),
            $this->logger()
        ));
    }

    public function notifier(): Notifier
    {
        return $this->lazy('notifier', function () {
            $channels = [];
            $token = Secrets::get('BPMB_TELEGRAM_BOT_TOKEN');
            $chats = array_filter(array_map('trim', explode(',', (string) $this->config()->get('telegram_chat_id'))));
            if ($token !== null && $chats) {
                $channels[] = new TelegramNotifier($token, $chats, new WpHttpTransport());
            }
            $emails = array_filter((array) $this->config()->get('notify_emails'), 'is_email');
            if ($emails) {
                $channels[] = new EmailNotifier(array_values($emails), admin_url('admin.php?page=bp-booking#bookings'));
            }
            return new CompositeNotifier($channels, $this->logger());
        });
    }

    public function watchManager(): WatchManager
    {
        return $this->lazy('watch', fn() => new WatchManager(
            $this->config(),
            $this->calendarClient(),
            $this->logger(),
            static fn() => Options::get(Options::WATCH),
            static function (array $s): void {
                update_option(Options::WATCH, $s, false);
            }
        ));
    }

    public function webhookUrl(): string
    {
        return rest_url(self::REST_NS . '/calendar/webhook');
    }

    /**
     * Синхронізація кешу подій. Захищена від паралельного запуску (cron + webhook + кнопка).
     *
     * @return array<string, mixed>
     */
    public function runSync(): array
    {
        try {
            $report = $this->db()->withLock('bpmb_sync', 1, fn() => $this->calendarSync()->syncAll());
        } catch (\BPMedical\Booking\Storage\LockTimeout $e) {
            return ['ok' => true, 'skipped' => 'sync already running'];
        } catch (\Throwable $e) {
            $report = ['ok' => false, 'error' => $e->getMessage(), 'at' => time(), 'calendars' => []];
        }
        $report['mode'] = $this->calendarMode();
        update_option(Options::SYNC_REPORT, $report, false);
        Options::bumpCache();
        return $report;
    }

    /** @return array<string, mixed> */
    public function ensureWatch(): array
    {
        try {
            return $this->watchManager()->ensure($this->webhookUrl(), time());
        } catch (\Throwable $e) {
            $this->logger()->error('ensureWatch failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /** Перерахунок doctor_ids у кеші після зміни лікарів / aliases / match_mode. */
    public function reclassify(): int
    {
        $this->reset();
        return $this->eventRepository()->reclassifyAll($this->classifier());
    }

    public function runRetention(): void
    {
        $days = max(1, $this->config()->int('retention_days'));
        $before = time() - $days * 86400;
        $n = $this->bookingRepository()->anonymizeBefore($before, time());
        $m = $this->callbackRepository()->anonymizeBefore($before, time());
        if ($n || $m) {
            $this->logger()->info('retention anonymized', ['bookings' => $n, 'callbacks' => $m]);
        }
    }

    /** Токен для посилання на .ics (без окремої колонки в БД). */
    public function icsToken(string $leadId): string
    {
        return substr(hash_hmac('sha256', $leadId, wp_salt('auth')), 0, 20);
    }
}
