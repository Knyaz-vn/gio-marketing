<?php
declare(strict_types=1);

namespace BPMedical\Booking\Core;

/**
 * Довідники: лікарі, спеціальності, послуги, локації.
 */
final class Catalog
{
    /** @var array<string, array<string, mixed>> */
    private array $doctors = [];
    /** @var array<string, array<string, mixed>> */
    private array $specialties = [];
    /** @var array<string, array<string, mixed>> */
    private array $services = [];
    /** @var array<string, array<string, mixed>> */
    private array $locations = [];

    /**
     * @param list<array<string, mixed>> $doctors
     * @param list<array<string, mixed>> $specialties
     * @param list<array<string, mixed>> $services
     * @param list<array<string, mixed>> $locations
     */
    public function __construct(array $doctors, array $specialties, array $services, array $locations)
    {
        foreach ($doctors as $d) {
            $this->doctors[(string) $d['id']] = self::normalizeDoctor($d);
        }
        foreach ($specialties as $s) {
            $this->specialties[(string) $s['id']] = $s;
        }
        foreach ($services as $s) {
            $this->services[(string) $s['id']] = $s;
        }
        foreach ($locations as $l) {
            $this->locations[(string) $l['id']] = $l;
        }
    }

    /**
     * @param array<string, mixed> $d
     * @return array<string, mixed>
     */
    public static function normalizeDoctor(array $d): array
    {
        $d += [
            'slug' => $d['id'],
            'full_name' => '',
            'surname' => '',
            'position' => '',
            'aliases' => [],
            'specialties' => [],
            'profile_url' => '',
            'photo_url' => '',
            'bookable' => false,
            'schedule' => [],
            'schedule_overrides' => [],
            'default_service_ids' => [],
            'service_durations' => [],
        ];
        $d['bookable'] = (bool) $d['bookable'];
        return $d;
    }

    /** @return array<string, array<string, mixed>> */
    public function doctors(): array
    {
        return $this->doctors;
    }

    /** @return array<string, mixed>|null */
    public function doctor(string $id): ?array
    {
        return $this->doctors[$id] ?? null;
    }

    /** Лікар за id або slug. */
    public function findDoctor(string $idOrSlug): ?array
    {
        if (isset($this->doctors[$idOrSlug])) {
            return $this->doctors[$idOrSlug];
        }
        foreach ($this->doctors as $d) {
            if ($d['slug'] === $idOrSlug) {
                return $d;
            }
        }
        return null;
    }

    /** @return array<string, array<string, mixed>> */
    public function specialties(): array
    {
        return $this->specialties;
    }

    public function findSpecialty(string $idOrSlug): ?array
    {
        if (isset($this->specialties[$idOrSlug])) {
            return $this->specialties[$idOrSlug];
        }
        foreach ($this->specialties as $s) {
            if (($s['slug'] ?? '') === $idOrSlug) {
                return $s;
            }
        }
        return null;
    }

    /** @return array<string, array<string, mixed>> */
    public function services(): array
    {
        return $this->services;
    }

    public function service(string $id): ?array
    {
        return $this->services[$id] ?? null;
    }

    /** @return array<string, array<string, mixed>> */
    public function locations(): array
    {
        return $this->locations;
    }

    public function location(?string $id): ?array
    {
        return $id !== null ? ($this->locations[$id] ?? null) : null;
    }

    /**
     * aliases для SurnameMatcher: кожен лікар (включно з bookable=false, бо їх участь теж блокує час).
     *
     * @return array<string, string[]>
     */
    public function aliasesByDoctor(): array
    {
        $out = [];
        foreach ($this->doctors as $id => $d) {
            $out[$id] = array_values(array_unique(array_filter(array_merge([(string) $d['surname']], (array) $d['aliases']))));
        }
        return $out;
    }

    /**
     * Лікарі, доступні для онлайн-запису в спеціальності (включно з дочірніми спеціальностями).
     *
     * @return list<array<string, mixed>>
     */
    public function bookableDoctorsForSpecialty(string $specialtyId): array
    {
        $ids = array_merge([$specialtyId], $this->childSpecialtyIds($specialtyId));
        $out = [];
        foreach ($this->doctors as $d) {
            if ($d['bookable'] && array_intersect($ids, (array) $d['specialties'])) {
                $out[] = $d;
            }
        }
        return $out;
    }

    /** @return string[] */
    public function childSpecialtyIds(string $specialtyId): array
    {
        $out = [];
        foreach ($this->specialties as $s) {
            if (($s['parent_id'] ?? null) === $specialtyId) {
                $out[] = (string) $s['id'];
            }
        }
        return $out;
    }

    public function isChildSpecialty(?string $specialtyId): bool
    {
        return $specialtyId !== null && !empty($this->specialties[$specialtyId]['is_child']);
    }

    /**
     * Послуги лікаря: спершу default_service_ids (у порядку), далі універсальні
     * (specialty_id = null) і послуги його спеціальностей.
     *
     * @return list<array<string, mixed>> з уже застосованими duration_min / buffer_after_min
     */
    public function servicesForDoctor(string $doctorId): array
    {
        $d = $this->doctor($doctorId);
        if ($d === null) {
            return [];
        }
        $ids = [];
        foreach ((array) $d['default_service_ids'] as $sid) {
            if (isset($this->services[$sid])) {
                $ids[] = (string) $sid;
            }
        }
        foreach ($this->services as $sid => $s) {
            $spec = $s['specialty_id'] ?? null;
            if ($spec === null || $spec === '' || in_array($spec, (array) $d['specialties'], true)) {
                $ids[] = (string) $sid;
            }
        }
        $out = [];
        foreach (array_values(array_unique($ids)) as $sid) {
            $s = $this->services[$sid];
            [$dur, $buf] = $this->duration($doctorId, $sid);
            $s['duration_min'] = $dur;
            $s['buffer_after_min'] = $buf;
            $out[] = $s;
        }
        return $out;
    }

    public function doctorOffersService(string $doctorId, string $serviceId): bool
    {
        foreach ($this->servicesForDoctor($doctorId) as $s) {
            if ($s['id'] === $serviceId) {
                return true;
            }
        }
        return false;
    }

    /**
     * Тривалість і буфер для пари лікар+послуга.
     *
     * @return array{0:int,1:int}
     */
    public function duration(string $doctorId, string $serviceId): array
    {
        $s = $this->services[$serviceId] ?? [];
        $dur = (int) ($s['duration_min'] ?? 30);
        $buf = (int) ($s['buffer_after_min'] ?? 0);
        $d = $this->doctor($doctorId);
        $override = $d['service_durations'][$serviceId] ?? null;
        if (is_array($override)) {
            if (isset($override['duration_min']) && (int) $override['duration_min'] > 0) {
                $dur = (int) $override['duration_min'];
            }
            if (isset($override['buffer_after_min'])) {
                $buf = max(0, (int) $override['buffer_after_min']);
            }
        }
        return [$dur, $buf];
    }

    /** Основна спеціальність лікаря (для аналітики). */
    public function primarySpecialtyId(string $doctorId): ?string
    {
        $d = $this->doctor($doctorId);
        $specs = $d ? (array) $d['specialties'] : [];
        return $specs ? (string) $specs[0] : null;
    }
}
