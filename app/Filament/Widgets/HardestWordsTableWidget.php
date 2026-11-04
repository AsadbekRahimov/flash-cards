<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Administration\Services\AdminDashboardStats;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * The ten words learners miss most in 30 days. The heaviest aggregate of the dashboard (docs/20 §4), so the rows are the
 * cached array of {@see AdminDashboardStats} (`->records()`), keyed by the word's id.
 */
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
        return $table
            ->records(fn (): array => array_column(app(AdminDashboardStats::class)->hardestWords(), null, 'id'))
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
