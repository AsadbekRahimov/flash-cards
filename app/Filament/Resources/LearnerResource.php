<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Administration\Services\PanelDataScope;
use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Billing\Support\PaidSubscriptionQuery;
use App\Domain\Engagement\Enums\ReferralStatus;
use App\Domain\Learners\Services\LearnerModerationService;
use App\Domain\Learners\Services\OnboardingService;
use App\Filament\Resources\LearnerResource\Pages;
use App\Filament\Resources\LearnerResource\RelationManagers;
use App\Models\Learner;
use App\Models\User;
use Carbon\CarbonImmutable;
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
 * moderation stay admin-only (docs/12 §4.4). The platform view — Telegram ID search, plan
 * (Free / Pro by effective plan), onboarding state, subscriptions / events / daily stats tabs — is admin-only too.
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
            TextEntry::make('onboarding_state')
                ->label('Onboarding')
                ->formatStateUsing(fn (string $state): string => $state === OnboardingService::DONE ? 'Done' : 'In progress ('.$state.')')
                ->visible($isAdmin),
            TextEntry::make('effective_plan')
                ->label('Plan')
                ->state(function (Learner $record): string {
                    $entitlement = app(EntitlementResolver::class)->resolve($record);

                    return self::planLabel($entitlement->plan, $entitlement->isSponsored())
                        .($entitlement->isSponsored() ? ', organization #'.substr($entitlement->source, strlen('sponsor:organization:')) : '')
                        .($entitlement->until !== null ? ', until '.$entitlement->until->toDateTimeString() : '');
                })
                ->visible($isAdmin),
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
                    // "@name" and "name" both match; the leading @ is not part of the stored username.
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereLike('username', '%'.ltrim(trim($search), '@').'%')),
                // Searching by Telegram ID is exact (the number is an identity: never a partial match) and admin-only,
                // also here, because a hidden column still takes part in the table search.
                Tables\Columns\TextColumn::make('telegram_user_id')
                    ->label('TG ID')
                    ->copyable()
                    ->toggleable()
                    ->visible($isAdmin)
                    ->searchable(query: fn (Builder $query, string $search): Builder => app(PanelDataScope::class)->isAdmin() && ctype_digit(trim($search)) && strlen(trim($search)) <= 18
                        ? $query->where('learners.telegram_user_id', (int) trim($search))
                        : $query->whereNull('learners.id')),
                Tables\Columns\TextColumn::make('plan')
                    ->label('Plan')
                    ->badge()
                    ->state(fn (Learner $record): string => match (true) {
                        $record->getAttribute('personal_pro_id') !== null => self::planLabel(Plan::Pro, sponsored: false),
                        $record->getAttribute('sponsor_pro_id') !== null => self::planLabel(Plan::Pro, sponsored: true),
                        default => self::planLabel(Plan::Free, sponsored: false),
                    })
                    ->color(fn (string $state): string => $state === self::planLabel(Plan::Free, sponsored: false) ? 'gray' : 'success')
                    ->visible($isAdmin),
                Tables\Columns\IconColumn::make('onboarded')
                    ->label('Onboarded')
                    ->state(fn (Learner $record): bool => $record->onboarding_state === OnboardingService::DONE)
                    ->boolean()
                    ->toggleable()
                    ->visible($isAdmin),
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
                // Invited learners (docs/13 §7.1): all non-rejected referrals; the flagged ones are in the Referrals tab.
                Tables\Columns\TextColumn::make('referrals_count')
                    ->label('Referrals')
                    ->counts(['referrals' => fn (Builder $q): Builder => $q->where('status', '<>', ReferralStatus::Rejected->value)])
                    ->sortable()
                    ->toggleable()
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
                    // The value comes from the client (Livewire state): restrict it to the viewer's own groups.
                    ->query(fn (Builder $query, array $data): Builder => ($data['value'] ?? null) === null || $data['value'] === ''
                        ? $query
                        : $query->whereHas(
                            'memberships',
                            fn (Builder $members): Builder => $members
                                ->where('telegram_group_id', $data['value'])
                                ->whereIn('telegram_group_id', app(PanelDataScope::class)->groups()->select('telegram_groups.id'))
                                ->where('status', 'active'),
                        ))
                    ->label('Group'),
                // Platform filters: admin only. `visible()` hides the control; the query closures re-check the
                // role because the filter state comes from the client.
                Tables\Filters\SelectFilter::make('plan')
                    ->label('Plan')
                    ->options(['free' => 'Free', 'pro' => 'Pro (own or sponsored)'])
                    ->query(fn (Builder $query, array $data): Builder => self::filterByPlan($query, $data['value'] ?? null))
                    ->visible($isAdmin),
                Tables\Filters\TernaryFilter::make('onboarded')
                    ->label('Onboarding')
                    ->placeholder('All')
                    ->trueLabel('Done')
                    ->falseLabel('Not done')
                    ->queries(
                        true: fn (Builder $query): Builder => app(PanelDataScope::class)->isAdmin() ? $query->where('onboarding_state', OnboardingService::DONE) : $query,
                        false: fn (Builder $query): Builder => app(PanelDataScope::class)->isAdmin() ? $query->where('onboarding_state', '<>', OnboardingService::DONE) : $query,
                        blank: fn (Builder $query): Builder => $query,
                    )
                    ->visible($isAdmin),
            ])
            ->actions([
                Actions\Action::make('toggleBlocked')
                    ->icon('heroicon-o-no-symbol')
                    ->label(fn (Learner $record): string => $record->blocked_at !== null ? 'Unblock' : 'Block')
                    ->color(fn (Learner $record): string => $record->blocked_at !== null ? 'success' : 'danger')
                    ->visible($isAdmin)
                    ->authorize('update')
                    ->requiresConfirmation()
                    ->action(function (Learner $record): void {
                        /** @var User $actor */
                        $actor = Auth::user();
                        $moderation = app(LearnerModerationService::class);

                        $record->blocked_at === null
                            ? $moderation->block($record, $actor, request()->ip())
                            : $moderation->unblock($record, $actor, request()->ip());
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

        $query = $scope
            ->learners()
            ->with(['groups' => fn ($groups) => $groups
                ->wherePivot('status', 'active')
                ->whereIn('telegram_groups.id', $scope->groups()->select('telegram_groups.id'))])
            ->withCount([
                'repetitions',
                'trainingReviews' => fn (Builder $q): Builder => $q->whereIn('training_session_id', clone $sessionIds),
                'examAnswers' => fn (Builder $q): Builder => $q->whereIn('exam_session_id', clone $examIds),
            ]);

        if ($scope->isAdmin()) {
            // The plan column in one query (no per-row entitlement lookups): the id of a granting own /
            // sponsoring subscription, or NULL.
            $now = CarbonImmutable::now();
            $query->addSelect([
                'personal_pro_id' => PaidSubscriptionQuery::personalFor($now)->select('subscriptions.id')->limit(1),
                'sponsor_pro_id' => PaidSubscriptionQuery::sponsorFor($now)->select('subscriptions.id')->limit(1),
            ]);
        }

        return $query;
    }

    /**
     * Free / Pro by EFFECTIVE plan, as two correlated EXISTS (own granting subscription, or a granting
     * subscription of an active organization the learner is sponsored by), evaluated by the database for
     * the page being rendered instead of resolving every learner in PHP. The rule is the SQL twin of
     * EntitlementResolver (see PaidSubscriptionQuery); admin only.
     *
     * @param  Builder<Learner>  $query
     * @return Builder<Learner>
     */
    private static function filterByPlan(Builder $query, mixed $plan): Builder
    {
        if (! app(PanelDataScope::class)->isAdmin() || ! in_array($plan, ['free', 'pro'], true)) {
            return $query;
        }

        $now = CarbonImmutable::now();

        return $plan === 'pro'
            ? $query->where(fn (Builder $q): Builder => self::wherePro($q, $now))
            : $query->whereNot(fn (Builder $q): Builder => self::wherePro($q, $now));
    }

    /**
     * @param  Builder<Learner>  $query
     * @return Builder<Learner>
     */
    private static function wherePro(Builder $query, CarbonImmutable $now): Builder
    {
        return $query
            ->whereIn('learners.id', PaidSubscriptionQuery::personalLearnerIds($now))
            ->orWhereIn('learners.id', PaidSubscriptionQuery::sponsoredLearnerIds($now));
    }

    /** The one plan caption of the platform view (list column and learner page): "Free", "Pro", "Pro (sponsored)". */
    public static function planLabel(Plan $plan, bool $sponsored): string
    {
        return ucfirst($plan->value).($sponsored && $plan !== Plan::Free ? ' (sponsored)' : '');
    }

    /** @return array<int, class-string> */
    public static function getRelations(): array
    {
        return [
            RelationManagers\SubscriptionsRelationManager::class,
            RelationManagers\OffersRelationManager::class,
            RelationManagers\ReferralsRelationManager::class,
            RelationManagers\ProductEventsRelationManager::class,
            RelationManagers\DailyStatsRelationManager::class,
        ];
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
