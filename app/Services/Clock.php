<?php

namespace App\Services;

use App\Models\ClubSetting;
use Carbon\CarbonImmutable;

class Clock
{
    public const ZONE = 'Europe/Brussels';

    /** F24 : heures de départ des créneaux, calculées depuis les réglages du club (9 h à 23 h, 90 minutes par défaut). */
    public static function slots(): array
    {
        return app(ClubSetting::class)->slots();
    }

    public static function slotMinutes(): int
    {
        return app(ClubSetting::class)->slot_minutes;
    }

    public static function local(CarbonImmutable $utc): CarbonImmutable
    {
        return $utc->setTimezone(self::ZONE);
    }

    // Heure locale de Bruxelles vers temps universel.
    public static function slotStart(string $date, string $time): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i', "$date $time", self::ZONE)->utc();
    }
}
