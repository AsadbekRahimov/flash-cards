<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Analytics\Services\RegionDistributionStats;
use App\Domain\Learners\Support\Regions;
use App\Filament\Concerns\RequiresActiveAdmin;
use App\Filament\Resources\Support\AdminNumbers;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * «Ученики по регионам» (docs/35 §2.4, R5): the number and the share of the onboarded learners per region. Admin only: platform-wide
 * data. The rows are the cached array of {@see RegionDistributionStats} (`admin:widget:regions:v1:all`); a warm open runs no query.
 */
class RegionDistributionWidget extends TableWidget
{
    use RequiresActiveAdmin;

    public static function canView(): bool
    {
        return self::isActiveAdmin();
    }

    protected static ?int $sort = 12;

    protected int|string|array $columnSpan = 'full';

    protected function getTableHeading(): string
    {
        return __('admin.widgets.regions.heading');
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => array_column(app(RegionDistributionStats::class)->current()['rows'], null, 'id'))
            ->description(__('admin.widgets.regions.description'))
            ->columns([
                Tables\Columns\TextColumn::make('region')
                    ->label(__('admin.widgets.regions.region'))
                    ->formatStateUsing(fn (string $state): string => $state === RegionDistributionStats::NONE ? __('admin.widgets.regions.none') : Regions::name($state, 'ru'))
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('learners')
                    ->label(__('admin.widgets.regions.learners'))
                    ->formatStateUsing(fn (int $state): string => AdminNumbers::decimal($state, 0)),
                Tables\Columns\TextColumn::make('pct')
                    ->label(__('admin.widgets.regions.share'))
                    ->formatStateUsing(fn (float $state): string => AdminNumbers::decimal($state, 1).'%'),
            ])
            ->emptyStateHeading(__('admin.widgets.regions.empty'))
            ->paginated(false);
    }
}
