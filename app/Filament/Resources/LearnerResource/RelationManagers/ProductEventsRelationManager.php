<?php

declare(strict_types=1);

namespace App\Filament\Resources\LearnerResource\RelationManagers;

use App\Filament\Concerns\RequiresActiveAdmin;
use App\Models\ProductEvent;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The last 50 product events of the learner (read-only). Event props are already free of personal
 * data by design (docs/12 §7.7: no promo codes, no message text); only name, time and props are shown.
 * Admin only.
 */
class ProductEventsRelationManager extends RelationManager
{
    use RequiresActiveAdmin;

    public const LIMIT = 50;

    protected static string $relationship = 'productEvents';

    protected static ?string $title = 'Recent events';

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
                ->select(['id', 'name', 'learner_id', 'props', 'occurred_at'])
                ->reorder()
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->limit(self::LIMIT))
            ->columns([
                Tables\Columns\TextColumn::make('occurred_at')->label('When')->dateTime(),
                Tables\Columns\TextColumn::make('name')->badge(),
                Tables\Columns\TextColumn::make('props')
                    ->state(fn (ProductEvent $record): string => $record->props === [] ? '—' : (string) json_encode($record->props, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                    ->limit(80)
                    ->wrap(),
            ])
            ->paginated(false);
    }
}
