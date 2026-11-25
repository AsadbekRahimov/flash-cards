<?php

declare(strict_types=1);

namespace App\Filament\Resources\LearnerResource\RelationManagers;

use App\Domain\Engagement\Enums\ScoreCategory;
use App\Filament\Concerns\RequiresActiveAdmin;
use App\Filament\Pages\LearnerScoreDayCredits;
use App\Filament\Resources\LearnerResource\Support\ScoreCorrectionActions;
use App\Filament\Resources\Support\AdminDates;
use App\Filament\Resources\Support\AdminLabels;
use App\Filament\Resources\Support\AdminTable;
use App\Models\Learner;
use App\Models\LearnerScoreDay;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * «Очки по дням» of a learner (docs/32 §2.4, §8.5, S15): the rating days with both counts — the category of the week and of the month,
 * their points, the long repetitions counted, whether the cap was reached — newest first, paged. A day opens its journal
 * ({@see LearnerScoreDayCredits}); «Аннулировать день» / «Восстановить день» go through {@see ScoreCorrectionActions} (the service,
 * a required reason, the right checked again). Admin only.
 *
 * One page = one statement (+ the count of the pager): the rows by the unique `(learner_id, rating_day)` (walked backwards for the
 * order, no sort), with the numbers of credits and of voided credits of each day as two scalar subqueries on the unique
 * `(learner_id, rating_day, lexeme_id)` of `score_credits` — what the row actions need, without a query per row (docs/20 §1.3).
 * `rating_day` is unique per learner, so no key is added to the order.
 */
class ScoreDaysRelationManager extends RelationManager
{
    use RequiresActiveAdmin;

    protected static string $relationship = 'scoreDays';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.learners.score_days.title');
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
        return AdminTable::bounded($table)
            ->modelLabel(__('admin.learners.score_days.singular'))
            ->pluralModelLabel(__('admin.learners.score_days.plural'))
            ->emptyStateHeading(__('admin.learners.score_days.empty'))
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->select([
                    'learner_score_days.id', 'learner_score_days.learner_id', 'learner_score_days.rating_day',
                    'week_category', 'week_points', 'week_long', 'week_capped_at',
                    'month_category', 'month_points', 'month_long', 'month_capped_at',
                    'raw_points', 'revision',
                ])
                ->addSelect([
                    'credits_count' => self::creditsOfDay(),
                    'voided_count' => self::creditsOfDay()->whereNotNull('c.voided_at'),
                ]))
            ->columns([
                Tables\Columns\TextColumn::make('rating_day')->label(__('admin.learners.score_days.columns.rating_day'))->date()->sortable(),
                Tables\Columns\TextColumn::make('week_category')
                    ->label(__('admin.learners.score_days.columns.week_category'))
                    ->badge()
                    ->formatStateUsing(fn (ScoreCategory $state): string => AdminLabels::value('learners.score_days.category', $state->value))
                    ->color(fn (ScoreCategory $state): string => self::categoryColor($state)),
                Tables\Columns\TextColumn::make('week_points')->label(__('admin.learners.score_days.columns.week_points')),
                Tables\Columns\TextColumn::make('month_category')
                    ->label(__('admin.learners.score_days.columns.month_category'))
                    ->badge()
                    ->formatStateUsing(fn (ScoreCategory $state): string => AdminLabels::value('learners.score_days.category', $state->value))
                    ->color(fn (ScoreCategory $state): string => self::categoryColor($state)),
                Tables\Columns\TextColumn::make('month_points')->label(__('admin.learners.score_days.columns.month_points')),
                Tables\Columns\TextColumn::make('month_long')
                    ->label(__('admin.learners.score_days.columns.long'))
                    ->tooltip(__('admin.learners.score_days.columns.long_hint'))
                    ->state(fn (LearnerScoreDay $record): string => "{$record->week_long} / {$record->month_long}"),
                Tables\Columns\IconColumn::make('capped')
                    ->label(__('admin.learners.score_days.columns.capped'))
                    ->tooltip(fn (LearnerScoreDay $record): ?string => self::cappedHint($record))
                    ->boolean()
                    ->state(fn (LearnerScoreDay $record): bool => $record->week_capped_at !== null || $record->month_capped_at !== null),
                Tables\Columns\TextColumn::make('raw_points')
                    ->label(__('admin.learners.score_days.columns.raw_points'))
                    ->tooltip(__('admin.learners.score_days.columns.raw_points_hint')),
                Tables\Columns\TextColumn::make('credits_count')
                    ->label(__('admin.learners.score_days.columns.credits'))
                    ->state(fn (LearnerScoreDay $record): string => self::creditsState($record)),
                Tables\Columns\TextColumn::make('revision')
                    ->label(__('admin.learners.score_days.columns.revision'))
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? (string) $state : '—'),
            ])
            ->recordActions([
                Action::make('journal')
                    ->label(__('admin.learners.score_days.actions.journal'))
                    ->icon('heroicon-o-list-bullet')
                    ->color('gray')
                    ->url(fn (LearnerScoreDay $record): string => LearnerScoreDayCredits::getUrl(['learner' => $record->learner_id, 'day' => $record->rating_day->toDateString()])),
                Action::make('voidDay')
                    ->label(__('admin.learners.score_days.actions.void_day'))
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (LearnerScoreDay $record): bool => self::isActiveAdmin() && self::count($record, 'credits_count') > self::count($record, 'voided_count'))
                    ->requiresConfirmation()
                    ->modalHeading(fn (LearnerScoreDay $record): string => __('admin.learners.score_days.actions.void_day_heading', ['day' => AdminDates::date($record->rating_day)]))
                    ->modalDescription(__('admin.learners.score_days.actions.void_day_description'))
                    ->schema([ScoreCorrectionActions::reasonField()])
                    ->action(fn (LearnerScoreDay $record, array $data) => ScoreCorrectionActions::run($this->learner(), $record->rating_day->toDateString(), null, (string) ($data['reason'] ?? ''))),
                Action::make('restoreDay')
                    ->label(__('admin.learners.score_days.actions.restore_day'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (LearnerScoreDay $record): bool => self::isActiveAdmin() && self::count($record, 'voided_count') > 0)
                    ->requiresConfirmation()
                    ->modalHeading(fn (LearnerScoreDay $record): string => __('admin.learners.score_days.actions.restore_day_heading', ['day' => AdminDates::date($record->rating_day)]))
                    ->modalDescription(__('admin.learners.score_days.actions.restore_description'))
                    ->action(fn (LearnerScoreDay $record) => ScoreCorrectionActions::run($this->learner(), $record->rating_day->toDateString(), null, null)),
            ])
            ->defaultSort('rating_day', 'desc')
            ->defaultKeySort(false);
    }

    private function learner(): Learner
    {
        $owner = $this->getOwnerRecord();
        abort_unless($owner instanceof Learner, 404);

        return $owner;
    }

    /** `count(*)` of the credits of the row's (learner, rating day): a scalar subquery of the page statement. */
    private static function creditsOfDay(): QueryBuilder
    {
        return DB::table('score_credits as c')
            ->selectRaw('count(*)')
            ->whereColumn('c.learner_id', 'learner_score_days.learner_id')
            ->whereColumn('c.rating_day', 'learner_score_days.rating_day');
    }

    private static function count(LearnerScoreDay $record, string $attribute): int
    {
        return (int) $record->getAttribute($attribute);
    }

    /** «12» or «12, аннулировано 3»; «—» once the journal of the day is pruned (`credits_retention_days`). */
    private static function creditsState(LearnerScoreDay $record): string
    {
        $credits = self::count($record, 'credits_count');
        $voided = self::count($record, 'voided_count');

        return match (true) {
            $credits === 0 => '—',
            $voided === 0 => (string) $credits,
            default => __('admin.learners.score_days.columns.credits_voided', ['count' => $credits, 'voided' => $voided]),
        };
    }

    private static function cappedHint(LearnerScoreDay $record): ?string
    {
        $parts = [];
        if ($record->week_capped_at !== null) {
            $parts[] = __('admin.learners.score_days.columns.capped_week');
        }
        if ($record->month_capped_at !== null) {
            $parts[] = __('admin.learners.score_days.columns.capped_month');
        }

        return $parts === [] ? null : implode(', ', $parts);
    }

    public static function categoryColor(ScoreCategory $category): string
    {
        return $category === ScoreCategory::Pro ? 'warning' : 'gray';
    }
}
