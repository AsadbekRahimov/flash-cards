<?php

declare(strict_types=1);

namespace App\Filament\Resources\LearnerResource\RelationManagers;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Filament\Concerns\RequiresActiveAdmin;
use App\Models\ProductEvent;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The last 50 product events of the learner (read-only), without the login noise `twa.authenticated` unless picked in the filter. Event props are already free of personal
 * data by design (docs/12 §7.7: no promo codes, no message text); only name, time and props are shown.
 * Admin only.
 */
class ProductEventsRelationManager extends RelationManager
{
    use RequiresActiveAdmin;

    public const LIMIT = 50;

    protected static string $relationship = 'productEvents';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.learners.events.title');
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
            ->modelLabel(__('admin.learners.events.singular'))
            ->pluralModelLabel(__('admin.learners.events.plural'))
            ->emptyStateHeading(__('admin.learners.events.empty'))
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->select(['id', 'name', 'learner_id', 'props', 'occurred_at'])
                ->reorder()
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->limit(self::LIMIT))
            ->columns([
                Tables\Columns\TextColumn::make('occurred_at')->label(__('admin.learners.events.columns.occurred_at'))->dateTime(),
                // The event name is a technical identifier (`course.selected`), shown as it is stored.
                Tables\Columns\TextColumn::make('name')->label(__('admin.learners.events.columns.name'))->badge(),
                Tables\Columns\TextColumn::make('props')
                    ->label(__('admin.learners.events.columns.props'))
                    ->state(fn (ProductEvent $record): string => $record->props === [] ? '—' : (string) json_encode($record->props, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                    ->limit(80)
                    ->wrap(),
            ])
            ->filters([
                // `twa.authenticated` is written on every TWA login and would push the useful events out of
                // the 50: hidden unless that very event is picked. The value comes from the client, so it
                // is checked against the known names.
                Tables\Filters\SelectFilter::make('name')
                    ->label(__('admin.learners.events.columns.name'))
                    ->placeholder(__('admin.learners.events.all_except', ['event' => ProductEventName::TwaAuthenticated->value]))
                    ->options(array_column(ProductEventName::cases(), 'value', 'value'))
                    ->query(function (Builder $query, array $data): Builder {
                        $name = ProductEventName::tryFrom((string) ($data['value'] ?? ''));

                        return $name === null
                            ? $query->where('name', '<>', ProductEventName::TwaAuthenticated->value)
                            : $query->where('name', $name->value);
                    }),
            ])
            ->paginated(false);
    }
}
