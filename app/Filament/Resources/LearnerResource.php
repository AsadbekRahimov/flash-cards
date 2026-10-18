<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Administration\Services\PanelDataScope;
use App\Filament\Resources\LearnerResource\Pages;
use App\Models\AuditLog;
use App\Models\Learner;
use Filament\Actions;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Learners (one row per person). A teacher sees only the people with an active
 * membership in their groups, and only name / username plus learning metrics
 * limited to THEIR groups; identity (Telegram ID), global progress, settings and
 * moderation stay admin-only (docs/12 §4.4).
 */
class LearnerResource extends Resource
{
    protected static ?string $model = Learner::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-academic-cap';

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 30;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        $isAdmin = fn (): bool => app(PanelDataScope::class)->isAdmin();

        return $schema->components([
            TextEntry::make('first_name')->label('First name')->placeholder('—'),
            TextEntry::make('last_name')->label('Last name')->placeholder('—'),
            TextEntry::make('username')->prefix('@')->placeholder('—'),
            TextEntry::make('telegram_user_id')->label('Telegram ID')->visible($isAdmin),
            TextEntry::make('groups_list')
                ->label('Groups')
                ->state(fn (Learner $record): string => self::groupTitles($record)),
            TextEntry::make('last_seen_at')->dateTime()->placeholder('Never')->visible($isAdmin),
            TextEntry::make('blocked_at')->dateTime()->placeholder('—')->visible($isAdmin),
            TextEntry::make('repetitions_count')->label('Cards started')->visible($isAdmin),
            TextEntry::make('training_reviews_count')->label('Training reviews'),
            TextEntry::make('exam_answers_count')->label('Exam answers'),
        ]);
    }

    public static function table(Table $table): Table
    {
        $isAdmin = fn (): bool => app(PanelDataScope::class)->isAdmin();

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('first_name')
                    ->formatStateUsing(fn (Learner $record): string => trim(($record->first_name ?? '').' '.($record->last_name ?? '')))
                    ->placeholder('—')
                    ->label('Name')
                    ->searchable(['first_name', 'last_name']),
                Tables\Columns\TextColumn::make('username')
                    ->prefix('@')
                    ->placeholder('—')
                    ->searchable(),
                Tables\Columns\TextColumn::make('telegram_user_id')->label('TG ID')->copyable()->toggleable()->visible($isAdmin),
                Tables\Columns\TextColumn::make('groups_list')
                    ->label('Groups')
                    ->state(fn (Learner $record): string => self::groupTitles($record)),
                Tables\Columns\IconColumn::make('blocked')
                    ->label('Blocked')
                    ->state(fn (Learner $record): bool => $record->blocked_at !== null)
                    ->boolean()
                    ->trueColor('danger')
                    ->falseColor('gray')
                    ->visible($isAdmin),
                Tables\Columns\TextColumn::make('last_seen_at')->dateTime()->sortable()->toggleable()->visible($isAdmin),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true)->visible($isAdmin),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('telegram_group_id')
                    ->options(fn (): array => app(PanelDataScope::class)
                        ->groups()
                        ->orderBy('title')
                        ->pluck('title', 'id')
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => ($data['value'] ?? null) === null || $data['value'] === ''
                        ? $query
                        : $query->whereHas(
                            'memberships',
                            fn (Builder $members): Builder => $members
                                ->where('telegram_group_id', $data['value'])
                                ->where('status', 'active'),
                        ))
                    ->label('Group'),
            ])
            ->actions([
                Actions\Action::make('toggleBlocked')
                    ->icon('heroicon-o-no-symbol')
                    ->label(fn (Learner $record): string => $record->blocked_at !== null ? 'Unblock' : 'Block')
                    ->color(fn (Learner $record): string => $record->blocked_at !== null ? 'success' : 'danger')
                    ->visible($isAdmin)
                    ->requiresConfirmation()
                    ->action(function (Learner $record): void {
                        $blocked = $record->blocked_at === null;
                        $record->update(['blocked_at' => $blocked ? now() : null]);

                        AuditLog::create([
                            'user_id' => Auth::id(),
                            'action' => $blocked ? 'learner.blocked' : 'learner.unblocked',
                            'entity_type' => Learner::class,
                            'entity_id' => $record->id,
                            'ip' => request()->ip(),
                            'meta' => [],
                        ]);
                    }),
                Actions\ViewAction::make(),
            ])
            ->defaultSort('first_name');
    }

    /**
     * Counts are limited to what the viewer may see: a teacher gets only reviews of
     * their groups' sessions and answers of their groups' exams, never a learner's
     * personal sessions or other groups' activity.
     *
     * @return Builder<Learner>
     */
    public static function getEloquentQuery(): Builder
    {
        $scope = app(PanelDataScope::class);
        $sessionIds = $scope->trainingSessionIds();
        $examIds = $scope->exams()->select('exam_sessions.id');

        return $scope
            ->learners()
            ->with(['groups' => fn ($groups) => $groups
                ->wherePivot('status', 'active')
                ->whereIn('telegram_groups.id', $scope->groups()->select('telegram_groups.id'))])
            ->withCount([
                'repetitions',
                'trainingReviews' => fn (Builder $q): Builder => $q->whereIn('training_session_id', clone $sessionIds),
                'examAnswers' => fn (Builder $q): Builder => $q->whereIn('exam_session_id', clone $examIds),
            ]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLearners::route('/'),
            'view' => Pages\ViewLearner::route('/{record}'),
        ];
    }

    /** Titles of the viewer-visible groups the learner is an active member of. */
    private static function groupTitles(Learner $record): string
    {
        $titles = $record->groups->pluck('title')->all();

        return $titles === [] ? '—' : implode(', ', $titles);
    }
}
