<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Analytics\Services\ExerciseMixStats;
use App\Filament\Concerns\RequiresActiveAdmin;
use App\Filament\Resources\Support\AdminLabels;
use App\Filament\Resources\Support\AdminNumbers;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * «Форматы заданий — 7 дней» (audit 2026-11-18, row 2 of «Что делать ученику в разные моменты»): the share of each exercise format
 * among the answered cards, for everybody and by interface language. Admin only: platform-wide data. The rows are the cached
 * array of {@see ExerciseMixStats} (`admin:widget:exercise_mix:v1:all`), keyed by the format; a warm open runs no query.
 */
class ExerciseMixTableWidget extends TableWidget
{
    use RequiresActiveAdmin;

    public static function canView(): bool
    {
        return self::isActiveAdmin();
    }

    protected static ?int $sort = 9;

    protected int|string|array $columnSpan = 'full';

    protected function getTableHeading(): string
    {
        return __('admin.widgets.exercise_mix.heading', ['days' => ExerciseMixStats::DAYS]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => array_column(app(ExerciseMixStats::class)->lastWeek()['rows'], null, 'id'))
            ->description(__('admin.widgets.exercise_mix.description'))
            ->columns([
                Tables\Columns\TextColumn::make('exercise')
                    ->label(__('admin.widgets.exercise_mix.exercise'))
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('widgets.exercise_mix.formats', $state))
                    ->weight('bold'),
                ...array_map(
                    fn (string $column): Tables\Columns\TextColumn => Tables\Columns\TextColumn::make($column)
                        ->label(__('admin.widgets.exercise_mix.columns.'.$column))
                        ->state(fn (array $record): ?string => self::share($record[$column] ?? null))
                        ->placeholder('—'),
                    ['all', 'ru', 'uz'],
                ),
            ])
            ->emptyStateHeading(__('admin.widgets.exercise_mix.empty', ['days' => ExerciseMixStats::DAYS]))
            ->paginated(false);
    }

    /** "12,5 % (45 из 360)"; null (a dash) when the language has no answered card at all. */
    public static function share(mixed $cell): ?string
    {
        if (! is_array($cell)) {
            return null;
        }

        return __('admin.widgets.exercise_mix.share', [
            'pct' => AdminNumbers::decimal((float) $cell['pct'], 1),
            'count' => (int) $cell['count'],
            'of' => (int) $cell['of'],
        ]);
    }
}
