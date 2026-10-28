<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Administration\Services\PanelDataScope;
use App\Models\Word;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class HardestWordsTableWidget extends TableWidget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    protected function getTableHeading(): string
    {
        return __('admin.widgets.hardest_words.heading');
    }

    public function table(Table $table): Table
    {
        $since = now()->subDays(30);
        $sessionIds = app(PanelDataScope::class)->trainingSessionIds();

        return $table
            ->query(
                Word::query()
                    ->select('words.*')
                    ->selectSub(
                        fn (QueryBuilder $q) => $q->from('training_reviews')
                            ->selectRaw('COUNT(*)')
                            ->whereColumn('training_reviews.word_id', 'words.id')
                            ->whereIn('training_reviews.training_session_id', clone $sessionIds)
                            ->where('training_reviews.created_at', '>=', $since),
                        'attempts',
                    )
                    ->selectSub(
                        fn (QueryBuilder $q) => $q->from('training_reviews')
                            ->selectRaw('CAST(SUM(CASE WHEN quality < ? THEN 1 ELSE 0 END) AS FLOAT) / NULLIF(COUNT(*), 0)', [3])
                            ->whereColumn('training_reviews.word_id', 'words.id')
                            ->whereIn('training_reviews.training_session_id', clone $sessionIds)
                            ->where('training_reviews.created_at', '>=', $since),
                        'hard_ratio',
                    )
                    ->whereHas(
                        'trainingReviews',
                        fn (Builder $q): Builder => $q
                            ->whereIn('training_session_id', clone $sessionIds)
                            ->where('created_at', '>=', $since),
                        '>=',
                        5,
                    )
                    ->orderByDesc('hard_ratio')
                    ->limit(10),
            )
            ->columns([
                Tables\Columns\TextColumn::make('word')->label(__('admin.widgets.hardest_words.word'))->weight('bold'),
                Tables\Columns\TextColumn::make('translation')->label(__('admin.widgets.hardest_words.translation'))->limit(40),
                Tables\Columns\TextColumn::make('attempts')->label(__('admin.widgets.hardest_words.attempts'))->badge(),
                Tables\Columns\TextColumn::make('hard_ratio')
                    ->label(__('admin.widgets.hardest_words.hard_ratio'))
                    ->formatStateUsing(fn ($state): string => $state === null ? '—' : number_format((float) $state * 100, 0).'%')
                    ->badge()
                    ->color('danger'),
            ])
            ->emptyStateHeading(__('admin.widgets.hardest_words.empty'))
            ->paginated(false);
    }
}
