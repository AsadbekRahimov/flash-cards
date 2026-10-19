<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Administration\Services\PanelDataScope;
use App\Models\Learner;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class TopLearnersTableWidget extends TableWidget
{
    protected static ?string $heading = 'Top learners — last 30 days';

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $since = now()->subDays(30);
        $scope = app(PanelDataScope::class);
        $sessionIds = $scope->trainingSessionIds();

        // Reviews are counted only inside sessions the viewer may see, so a teacher's
        // ranking never includes a learner's personal practice or other groups' work.
        $reviewsInScope = fn (Builder $q): Builder => $q
            ->where('created_at', '>=', $since)
            ->whereIn('training_session_id', clone $sessionIds);

        return $table
            ->query(
                $scope->learners()
                    ->with(['groups' => fn ($groups) => $groups
                        ->wherePivot('status', 'active')
                        ->whereIn('telegram_groups.id', $scope->groups()->select('telegram_groups.id'))])
                    ->withCount(['trainingReviews as reviews_count' => $reviewsInScope])
                    ->whereHas('trainingReviews', $reviewsInScope)
                    ->orderByDesc('reviews_count')
                    ->limit(10),
            )
            ->columns([
                Tables\Columns\TextColumn::make('first_name')
                    ->formatStateUsing(fn (Learner $r): string => trim(($r->first_name ?? '—').' '.($r->last_name ?? '')))
                    ->label('Learner'),
                Tables\Columns\TextColumn::make('groups_list')
                    ->label('Groups')
                    ->state(fn (Learner $r): string => $r->groups->pluck('title')->implode(', ')),
                Tables\Columns\TextColumn::make('reviews_count')->label('Reviews')->badge()->color('success'),
            ])
            ->paginated(false);
    }
}
