<?php

declare(strict_types=1);

namespace App\Filament\Resources\Support;

use App\Providers\Filament\FilamentDefaultsProvider;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Filament\Support\Facades\FilamentTimezone;

/**
 * A date or a moment inside a sentence of the admin panel («Pro, до 27.11.2026 12:00»), written the way the columns
 * and infolists of the panel write it (the formats of {@see FilamentDefaultsProvider}). A raw `toDateString()` in a text
 * would be «2026-11-27», the only ISO date on a screen of «27.11.2026».
 */
final class AdminDates
{
    /** «27.11.2026». For a calendar day (a league week), so there is no time zone to convert. */
    public static function date(DateTimeInterface $date): string
    {
        return $date->format(FilamentDefaultsProvider::DATE_FORMAT);
    }

    /**
     * «27.11.2026 12:00» in the time zone a datetime column shows (`FilamentTimezone`, UTC unless the panel sets another),
     * or in `$timezone` where the text belongs to a form that works in its own zone (the date picker of «Выдать Pro»).
     */
    public static function dateTime(DateTimeInterface $moment, ?string $timezone = null): string
    {
        return CarbonImmutable::instance($moment)
            ->setTimezone($timezone ?? FilamentTimezone::get())
            ->format(FilamentDefaultsProvider::DATE_TIME_FORMAT);
    }
}
