<?php

declare(strict_types=1);

namespace App\Filament\Resources\LearnerResource\RelationManagers;

use App\Filament\Concerns\RequiresActiveAdmin;
use App\Models\LearnerDailyStats;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** `learner_daily_stats` of the last 14 days (read-only). Admin only. */
class DailyStatsRelationManager extends RelationManager
{
    use RequiresActiveAdmin;

    public const DAYS = 14;

    protected static string $relationship = 'dailyStats';

    protected static ?string $title = 'Last 14 days';

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
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->where('date', '>=', now()->subDays(self::DAYS - 1)->toDateString()))
            ->columns([
                Tables\Columns\TextColumn::make('date')->date(),
                Tables\Columns\TextColumn::make('reviews_count')->label('Reviews'),
                Tables\Columns\TextColumn::make('correct_count')->label('Correct'),
                Tables\Columns\TextColumn::make('new_words_count')->label('New words'),
                Tables\Columns\TextColumn::make('time_spent_ms')
                    ->label('Time, min')
                    ->state(fn (LearnerDailyStats $record): string => number_format($record->time_spent_ms / 60000, 1)),
                Tables\Columns\IconColumn::make('goal_met_at')
                    ->label('Goal met')
                    ->boolean()
                    ->state(fn (LearnerDailyStats $record): bool => $record->goal_met_at !== null),
            ])
            ->paginated(false)
            ->defaultSort('date', 'desc');
    }
}
