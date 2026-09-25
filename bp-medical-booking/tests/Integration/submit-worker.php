<?php
/**
 * Окремий PHP-процес для тесту паралельного бронювання.
 * argv: <tmpDir> <doctorId> <serviceId> <startTs> <barrierMicrotime> <name>
 */
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use BPMedical\Booking\Booking\SlotUnavailable;
use BPMedical\Booking\Tests\Support\TestStack;

[, $dir, $doctor, $service, $start, $barrier, $name] = $argv;
$stack = new TestStack($dir);

// Обидва процеси стартують сабміт в одну й ту саму мить.
while (microtime(true) < (float) $barrier) {
    usleep(500);
}
try {
    $r = $stack->bookings->submit(TestStack::patient([
        'doctor_id' => $doctor,
        'service_id' => $service,
        'start' => (int) $start,
        'name' => $name,
    ]));
    echo json_encode(['ok' => true, 'lead' => $r['booking']['lead_id']]);
} catch (SlotUnavailable $e) {
    echo json_encode(['ok' => false, 'error' => 'slot_unavailable', 'alternatives' => count($e->alternatives)]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => get_class($e) . ': ' . $e->getMessage()]);
}
