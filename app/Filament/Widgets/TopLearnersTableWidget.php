<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Administration\Services\AdminDashboardStats;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * The ten learners with the most reviews in 30 days. The rows are the cached array of {@see AdminDashboardStats} (docs/20
 * §1.11: an aggregate table reads `->records()`, not a query per open), keyed by the learner's id.
 */
class TopLearnersTableWidget extends TableWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected function getTableHeading(): string
    {
        return __('admin.widgets.top_learners.heading');
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => array_column(app(AdminDashboardStats::class)->topLearners(), null, 'id'))
            ->columns([
                Tables\Columns\TextColumn::make('name')->label(__('admin.widgets.top_learners.learner')),
                Tables\Columns\TextColumn::make('groups')->label(__('admin.widgets.top_learners.groups')),
                Tables\Columns\TextColumn::make('reviews_count')->label(__('admin.widgets.top_learners.reviews'))->badge()->color('success'),
            ])
            ->emptyStateHeading(__('admin.widgets.top_learners.empty'))
            ->paginated(false);
    }
}
