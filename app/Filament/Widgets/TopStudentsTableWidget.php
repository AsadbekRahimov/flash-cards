<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Student;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class TopStudentsTableWidget extends TableWidget
{
    protected static ?string $heading = 'Top students — last 30 days';

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $since = now()->subDays(30);

        return $table
            ->query(
                Student::query()
                    ->withCount(['trainingReviews as reviews_count' => fn (Builder $q): Builder => $q->where('created_at', '>=', $since)])
                    ->having('reviews_count', '>', 0)
                    ->orderByDesc('reviews_count')
                    ->limit(10)
            )
            ->columns([
                Tables\Columns\TextColumn::make('first_name')
                    ->formatStateUsing(fn (Student $r): string => trim($r->first_name.' '.($r->last_name ?? '')))
                    ->label('Student'),
                Tables\Columns\TextColumn::make('group.title')->label('Group'),
                Tables\Columns\TextColumn::make('reviews_count')->label('Reviews')->badge()->color('success'),
            ])
            ->paginated(false);
    }
}
