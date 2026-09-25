<?php
declare(strict_types=1);

namespace BPMedical\Booking\Calendar;

use BPMedical\Booking\Booking\Logger;
use BPMedical\Booking\Core\Config;
use BPMedical\Booking\Core\Ids;

/**
 * Push-сповіщення Google Calendar (events.watch) з автопродовженням каналу.
 * Якщо webhook недоступний (немає HTTPS, mock-режим, помилка), модуль працює лише на polling.
 */
final class WatchManager
{
    private const TTL = 7 * 86400;
    private const RENEW_BEFORE = 24 * 3600;

    private Config $config;
    private CalendarClient $client;
    private Logger $log;
    /** @var callable(): array<string, array<string, mixed>> */
    private $load;
    /** @var callable(array<string, array<string, mixed>>): void */
    private $save;

    public function __construct(Config $config, CalendarClient $client, Logger $log, callable $load, callable $save)
    {
        $this->config = $config;
        $this->client = $client;
        $this->log = $log;
        $this->load = $load;
        $this->save = $save;
    }

    /**
     * Створює / продовжує канали для всіх календарів, зупиняє канали видалених календарів.
     *
     * @return array<string, array<string, mixed>> стан каналів
     */
    public function ensure(string $webhookUrl, int $now): array
    {
        $state = ($this->load)();
        if (!$this->client->supportsWatch() || stripos($webhookUrl, 'https://') !== 0) {
            return $state;
        }
        $active = [];
        foreach ($this->config->calendars() as $cal) {
            $cid = $cal['id'];
            $ch = $state[$cid] ?? null;
            if (is_array($ch) && ($ch['expiration'] ?? 0) - $now > self::RENEW_BEFORE && empty($ch['error'])) {
                $active[$cid] = $ch;
                continue;
            }
            try {
                $token = Ids::token();
                $res = $this->client->watch($cid, 'bpmb-' . Ids::token(), $webhookUrl, $token, self::TTL);
                if (is_array($ch) && !empty($ch['id']) && !empty($ch['resource_id'])) {
                    $this->client->stopChannel((string) $ch['id'], (string) $ch['resource_id']);
                }
                $active[$cid] = $res + ['token' => $token, 'error' => null, 'created' => $now];
            } catch (\Throwable $e) {
                $this->log->error('watch failed, polling only', ['calendar' => $cal['name'], 'error' => $e->getMessage()]);
                $active[$cid] = ['id' => '', 'resource_id' => '', 'expiration' => 0, 'token' => '', 'error' => substr($e->getMessage(), 0, 200), 'created' => $now];
            }
        }
        foreach ($state as $cid => $ch) {
            if (!isset($active[$cid]) && !empty($ch['id']) && !empty($ch['resource_id'])) {
                $this->client->stopChannel((string) $ch['id'], (string) $ch['resource_id']);
            }
        }
        ($this->save)($active);
        return $active;
    }

    /** Перевірка вхідного сповіщення за заголовками X-Goog-Channel-ID / X-Goog-Channel-Token. */
    public function verify(string $channelId, string $token): bool
    {
        if ($channelId === '' || $token === '') {
            return false;
        }
        foreach (($this->load)() as $ch) {
            if (($ch['id'] ?? '') === $channelId && !empty($ch['token']) && hash_equals((string) $ch['token'], $token)) {
                return true;
            }
        }
        return false;
    }

    public function stopAll(): void
    {
        foreach (($this->load)() as $ch) {
            if (!empty($ch['id']) && !empty($ch['resource_id'])) {
                $this->client->stopChannel((string) $ch['id'], (string) $ch['resource_id']);
            }
        }
        ($this->save)([]);
    }
}
