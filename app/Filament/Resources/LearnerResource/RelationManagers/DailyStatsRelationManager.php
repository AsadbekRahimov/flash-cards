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

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.learners.daily_stats.title');
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
        return $table
            ->modelLabel(__('admin.learners.daily_stats.singular'))
            ->pluralModelLabel(__('admin.learners.daily_stats.plural'))
            ->emptyStateHeading(__('admin.learners.daily_stats.empty'))
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->where('date', '>=', now()->subDays(self::DAYS - 1)->toDateString()))
            ->columns([
                Tables\Columns\TextColumn::make('date')->label(__('admin.learners.daily_stats.columns.date'))->date(),
                Tables\Columns\TextColumn::make('reviews_count')->label(__('admin.learners.daily_stats.columns.reviews_count')),
                Tables\Columns\TextColumn::make('correct_count')->label(__('admin.learners.daily_stats.columns.correct_count')),
                Tables\Columns\TextColumn::make('new_words_count')->label(__('admin.learners.daily_stats.columns.new_words_count')),
                Tables\Columns\TextColumn::make('time_spent_ms')
                    ->label(__('admin.learners.daily_stats.columns.time'))
                    ->state(fn (LearnerDailyStats $record): string => number_format($record->time_spent_ms / 60000, 1)),
                Tables\Columns\IconColumn::make('goal_met_at')
                    ->label(__('admin.learners.daily_stats.columns.goal_met'))
                    ->boolean()
                    ->state(fn (LearnerDailyStats $record): bool => $record->goal_met_at !== null),
            ])
            ->paginated(false)
            ->defaultSort('date', 'desc');
    }
}
