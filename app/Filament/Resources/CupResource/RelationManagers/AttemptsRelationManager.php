<?php

declare(strict_types=1);

namespace App\Filament\Resources\CupResource\RelationManagers;

use App\Domain\Cup\Enums\CupStatus;
use App\Domain\Cup\Support\CupClock;
use App\Filament\Concerns\RequiresActiveAdmin;
use App\Filament\Resources\Support\AdminLabels;
use App\Filament\Resources\Support\AdminTable;
use App\Models\Cup;
use App\Models\CupDay;
use App\Models\CupRoundAttempt;
use Carbon\CarbonImmutable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The attempts of ONE day of a cup (docs/33 §8, K9). `cup_round_attempts` is the second biggest table of the cup and `cup_id` has no
 * index there (docs/33 §11.2 п. 4): the table is ALWAYS narrowed by the day - the filter has no "all" - and read through the unique
 * `(cup_day_id, participant_id)` in its own order; simple pagination (no `COUNT`). The heavy `order` / `state` are not selected; the
 * names come by one `with` per page.
 */
class AttemptsRelationManager extends RelationManager
{
    use RequiresActiveAdmin;

    protected static string $relationship = 'roundAttempts';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.cups.attempts.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return self::isActiveAdmin();
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        /** @var Cup $cup */
        $cup = $this->getOwnerRecord();
        $default = self::defaultDay($cup);

        return AdminTable::bounded($table)
            ->paginationMode(PaginationMode::Simple)
            ->modelLabel(__('admin.cups.attempts.singular'))
            ->pluralModelLabel(__('admin.cups.attempts.plural'))
            ->heading(__('admin.cups.attempts.title'))
            ->emptyStateHeading(__('admin.cups.attempts.empty'))
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->select(['id', 'cup_id', 'cup_day_id', 'participant_id', 'status', 'finish_reason', 'answered', 'correct', 'points', 'started_at', 'expires_at', 'finished_at'])
                ->with('participant:id,display_name'))
            ->columns([
                Tables\Columns\TextColumn::make('participant.display_name')->label(__('admin.cups.attempts.columns.participant')),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('admin.cups.attempts.columns.status'))
                    ->badge()
                    ->state(fn (CupRoundAttempt $record): string => ($record->finish_reason ?? $record->status)->value)
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('cups.attempt_status', $state)),
                Tables\Columns\TextColumn::make('answered')->label(__('admin.cups.attempts.columns.answered')),
                Tables\Columns\TextColumn::make('correct')->label(__('admin.cups.attempts.columns.correct')),
                Tables\Columns\TextColumn::make('points')->label(__('admin.cups.attempts.columns.points')),
                Tables\Columns\TextColumn::make('started_at')->label(__('admin.cups.attempts.columns.started_at'))->dateTime(),
                Tables\Columns\TextColumn::make('finished_at')->label(__('admin.cups.attempts.columns.finished_at'))->dateTime()->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('day')
                    ->label(__('admin.cups.attempts.day'))
                    ->options(self::dayOptions($cup))
                    ->default((string) $default)
                    ->selectablePlaceholder(false)
                    ->query(fn (Builder $query, array $data): Builder => $query->where(
                        'cup_round_attempts.cup_day_id',
                        CupDay::query()->select('id')->where('cup_id', $cup->id)->where('day', self::chosenDay($data['value'] ?? null, $cup, $default)),
                    )),
            ])
            ->defaultSort('participant_id');
    }

    /** The day of now while the cup runs, the last day after it, the first before. */
    public static function defaultDay(Cup $cup): int
    {
        if ($cup->starts_at === null || $cup->status === CupStatus::Draft || $cup->status === CupStatus::Scheduled) {
            return 1;
        }

        return max(1, min($cup->days, CupClock::dayNumber($cup->starts_at, $cup->timezone, CarbonImmutable::now())));
    }

    /** @return array<int, string> day => caption */
    private static function dayOptions(Cup $cup): array
    {
        $options = [];
        for ($day = 1; $day <= max(1, $cup->days); $day++) {
            $options[$day] = (string) __('admin.cups.attempts.day_n', ['day' => $day]);
        }

        return $options;
    }

    private static function chosenDay(mixed $value, Cup $cup, int $default): int
    {
        $day = is_numeric($value) ? (int) $value : $default;

        return $day >= 1 && $day <= $cup->days ? $day : $default;
    }
}
