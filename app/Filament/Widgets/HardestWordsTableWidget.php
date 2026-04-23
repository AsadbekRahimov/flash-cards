<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Word;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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
                        fn (Builder $q) => $q->from('training_reviews')
                            ->selectRaw('COUNT(*)')
                            ->whereColumn('training_reviews.word_id', 'words.id')
                            ->where('training_reviews.created_at', '>=', $since),
                        'attempts'
                    )
                    ->selectSub(
                        fn (Builder $q) => $q->from('training_reviews')
                            ->selectRaw('SUM(CASE WHEN quality < 3 THEN 1 ELSE 0 END)::float / NULLIF(COUNT(*), 0)')
                            ->whereColumn('training_reviews.word_id', 'words.id')
                            ->where('training_reviews.created_at', '>=', $since),
                        'hard_ratio'
                    )
                    ->havingRaw('(SELECT COUNT(*) FROM training_reviews WHERE training_reviews.word_id = words.id AND training_reviews.created_at >= ?) >= 5', [$since])
                    ->orderByDesc(DB::raw('hard_ratio'))
                    ->limit(10)
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
