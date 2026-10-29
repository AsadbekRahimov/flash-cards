<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Forms\Components\DateTimePicker;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Support\ServiceProvider;

/**
 * Settings that hold for both panels, `/admin` and `/org`, configured once here instead of on every column.
 *
 * Dates read the way people write them in Russia and Uzbekistan: «28.10.2026» and «28.10.2026 09:27». Filament's own
 * default is «окт 28, 2026 09:27:19». It applies to tables (`TextColumn::date()` / `dateTime()`), to infolists
 * (`TextEntry`, which asks its schema) and to the display format of a non-native date picker; a column or an entry
 * with an explicit format keeps it. A native picker (`<input type="datetime-local">`) is shown by the browser itself.
 */
class FilamentDefaultsProvider extends ServiceProvider
{
    public const DATE_FORMAT = 'd.m.Y';

    public const DATE_TIME_FORMAT = 'd.m.Y H:i';

    public const DATE_TIME_WITH_SECONDS_FORMAT = 'd.m.Y H:i:s';

    public function boot(): void
    {
        Table::configureUsing(fn (Table $table): Table => $table
            ->defaultDateDisplayFormat(self::DATE_FORMAT)
            ->defaultDateTimeDisplayFormat(self::DATE_TIME_FORMAT));

        Schema::configureUsing(fn (Schema $schema): Schema => $schema
            ->defaultDateDisplayFormat(self::DATE_FORMAT)
            ->defaultDateTimeDisplayFormat(self::DATE_TIME_FORMAT));

        DateTimePicker::configureUsing(fn (DateTimePicker $picker): DateTimePicker => $picker
            ->defaultDateDisplayFormat(self::DATE_FORMAT)
            ->defaultDateTimeDisplayFormat(self::DATE_TIME_FORMAT)
            ->defaultDateTimeWithSecondsDisplayFormat(self::DATE_TIME_WITH_SECONDS_FORMAT));
    }
}
