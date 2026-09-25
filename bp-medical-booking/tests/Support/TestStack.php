<?php
declare(strict_types=1);

namespace BPMedical\Booking\Tests\Support;

use BPMedical\Booking\Booking\BookingService;
use BPMedical\Booking\Booking\DbBusyProvider;
use BPMedical\Booking\Booking\Logger;
use BPMedical\Booking\Booking\NullNotifier;
use BPMedical\Booking\Calendar\CalendarSync;
use BPMedical\Booking\Calendar\MockCalendarClient;
use BPMedical\Booking\Core\AvailabilityService;
use BPMedical\Booking\Core\Catalog;
use BPMedical\Booking\Core\Clock;
use BPMedical\Booking\Core\Config;
use BPMedical\Booking\Core\EventClassifier;
use BPMedical\Booking\Core\ScheduleResolver;
use BPMedical\Booking\Core\SlotGenerator;
use BPMedical\Booking\Core\SurnameMatcher;
use BPMedical\Booking\Core\SystemClock;
use BPMedical\Booking\Storage\BookingRepository;
use BPMedical\Booking\Storage\EventCacheRepository;
use BPMedical\Booking\Storage\HoldRepository;
use BPMedical\Booking\Storage\PdoConnection;
use BPMedical\Booking\Storage\Schema;

/**
 * Повний стек модуля без WordPress: SQLite-файл + mock-календар у JSON + flock-локи.
 * Використовується інтеграційними тестами (в т.ч. з кількох процесів одночасно).
 */
final class TestStack
{
    public PdoConnection $db;
    public MockCalendarClient $calendar;
    public Catalog $catalog;
    public Config $config;
    public EventClassifier $classifier;
    public AvailabilityService $availability;
    public BookingService $bookings;
    public BookingRepository $bookingRepo;
    public HoldRepository $holds;
    public EventCacheRepository $events;
    public CalendarSync $sync;
    public NullNotifier $notifier;
    /** @var list<string> */
    public array $logLines = [];

    public function __construct(string $dir, ?Clock $clock = null, array $configOverrides = [])
    {
        $clock = $clock ?? new SystemClock();
        $this->db = PdoConnection::sqlite($dir . '/db.sqlite', $dir . '/locks');
        foreach (Schema::sqlite('wp_') as $sql) {
            $this->db->pdo()->exec($sql);
        }
        $data = dirname(__DIR__, 2) . '/data';
        $this->catalog = new Catalog(
            json_decode((string) file_get_contents("$data/doctors.json"), true),
            json_decode((string) file_get_contents("$data/specialties.json"), true),
            json_decode((string) file_get_contents("$data/services.json"), true),
            json_decode((string) file_get_contents("$data/locations.json"), true)
        );
        $this->config = new Config($configOverrides + json_decode((string) file_get_contents("$data/settings.json"), true));
        $this->calendar = new MockCalendarClient($dir . '/calendar.json');
        $this->classifier = new EventClassifier(new SurnameMatcher($this->catalog->aliasesByDoctor(), (string) $this->config->get('match_mode')));
        $this->events = new EventCacheRepository($this->db);
        $this->holds = new HoldRepository($this->db);
        $this->bookingRepo = new BookingRepository($this->db);
        $log = new Logger(function (string $l): void {
            $this->logLines[] = $l;
        });
        $this->availability = new AvailabilityService(
            $this->catalog,
            new SlotGenerator($this->config, new ScheduleResolver($this->config), $clock),
            new DbBusyProvider($this->events, $this->holds, $this->bookingRepo, $clock)
        );
        $this->sync = new CalendarSync($this->config, $this->calendar, $this->events, $this->classifier, $clock, $log, $this->bookingRepo);
        $this->notifier = new NullNotifier();
        $this->bookings = new BookingService(
            $this->db, $this->catalog, $this->config, $this->availability, $this->holds, $this->bookingRepo,
            $this->events, $this->classifier, $this->calendar, $this->sync, $this->notifier, $clock, $log
        );
    }

    public static function tmpDir(string $name): string
    {
        $dir = sys_get_temp_dir() . '/bpmb-test-' . $name . '-' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        return $dir;
    }

    /** @return array<string, mixed> */
    public static function patient(array $extra = []): array
    {
        return $extra + [
            'name' => 'Олена',
            'phone' => '067 123 45 67',
            'consent' => true,
            'comment' => '',
            'attribution' => ['utm_source' => 'google', 'utm_medium' => 'cpc', 'gclid' => 'Cj0KCQ-test_1'],
        ];
    }
}
