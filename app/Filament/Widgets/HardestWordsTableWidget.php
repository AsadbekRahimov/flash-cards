<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Word;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class HardestWordsTableWidget extends TableWidget
{
    protected static ?string $heading = 'Hardest words — last 30 days';

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $since = now()->subDays(30);

        return $table
            ->query(
                Word::query()
                    ->select('words.*')
                    ->selectSub(
                        fn (QueryBuilder $q) => $q->from('training_reviews')
                            ->selectRaw('COUNT(*)')
                            ->whereColumn('training_reviews.word_id', 'words.id')
                            ->where('training_reviews.created_at', '>=', $since),
                        'attempts',
                    )
                    ->selectSub(
                        fn (QueryBuilder $q) => $q->from('training_reviews')
                            ->selectRaw('CAST(SUM(CASE WHEN quality < ? THEN ? ELSE ? END) AS FLOAT) / NULLIF(COUNT(*), 0)', [3, 1, 0])
                            ->whereColumn('training_reviews.word_id', 'words.id')
                            ->where('training_reviews.created_at', '>=', $since),
                        'hard_ratio',
                    )
                    ->whereHas('trainingReviews', fn (Builder $q): Builder => $q->where('created_at', '>=', $since), '>=', 5)
                    ->orderByDesc('hard_ratio')
                    ->limit(10),
            )
            ->columns([
                Tables\Columns\TextColumn::make('word')->weight('bold'),
                Tables\Columns\TextColumn::make('translation')->limit(40),
                Tables\Columns\TextColumn::make('attempts')->badge(),
                Tables\Columns\TextColumn::make('hard_ratio')
                    ->label('Hard ratio')
                    ->formatStateUsing(fn ($state): string => $state === null ? '—' : number_format((float) $state * 100, 0).'%')
                    ->badge()
                    ->color('danger'),
            ])
            ->paginated(false);
    }
}
