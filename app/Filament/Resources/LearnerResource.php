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
use App\Domain\Learning\Enums\SchedulerKind;
use App\Domain\Learning\Services\SchedulerResolver;
use App\Domain\Learning\Services\SchedulerSwitchService;
use App\Filament\Resources\LearnerResource\Pages;
use App\Filament\Resources\LearnerResource\RelationManagers;
use App\Filament\Resources\Support\AdminLabels;
use App\Models\Learner;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions;
use Filament\Forms\Components\Select;
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

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.administration');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.learners.nav');
    }

    public static function getModelLabel(): string
    {
        return __('admin.learners.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.learners.plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        $isAdmin = fn (): bool => app(PanelDataScope::class)->isAdmin();

        return $schema->components([
            TextEntry::make('first_name')->label(__('admin.learners.fields.first_name'))->placeholder('—'),
            TextEntry::make('last_name')->label(__('admin.learners.fields.last_name'))->placeholder('—'),
            TextEntry::make('username')->label(__('admin.learners.fields.username'))->prefix('@')->placeholder('—'),
            TextEntry::make('telegram_user_id')->label(__('admin.learners.fields.telegram_id'))->visible($isAdmin),
            TextEntry::make('groups_list')
                ->label(__('admin.learners.fields.groups'))
                ->state(fn (Learner $record): string => self::groupTitles($record)),
            TextEntry::make('last_seen_at')->label(__('admin.learners.fields.last_seen_at'))->dateTime()->placeholder(__('admin.learners.fields.never'))->visible($isAdmin),
            TextEntry::make('blocked_at')->label(__('admin.learners.fields.blocked_at'))->dateTime()->placeholder('—')->visible($isAdmin),
            TextEntry::make('onboarding_state')
                ->label(__('admin.learners.fields.onboarding'))
                ->formatStateUsing(fn (string $state): string => $state === OnboardingService::DONE
                    ? __('admin.learners.onboarding.done')
                    : __('admin.learners.onboarding.in_progress', ['step' => AdminLabels::value('learners.onboarding.step', $state)]))
                ->visible($isAdmin),
            TextEntry::make('effective_plan')
                ->label(__('admin.learners.fields.plan'))
                ->state(function (Learner $record): string {
                    $entitlement = app(EntitlementResolver::class)->resolve($record);

                    return self::planLabel($entitlement->plan, $entitlement->isSponsored())
                        .($entitlement->isSponsored() ? ', '.__('admin.learners.plan_center', ['id' => substr($entitlement->source, strlen('sponsor:organization:'))]) : '')
                        .($entitlement->until !== null ? ', '.__('admin.learners.plan_until', ['date' => $entitlement->until->toDateTimeString()]) : '');
                })
                ->visible($isAdmin),
            TextEntry::make('scheduler')
                ->label(__('admin.learners.fields.scheduler'))
                ->state(fn (Learner $record): string => self::schedulerLabel($record))
                ->visible($isAdmin),
            TextEntry::make('repetitions_count')->label(__('admin.learners.fields.repetitions_count'))->visible($isAdmin),
            TextEntry::make('training_reviews_count')->label(__('admin.learners.fields.training_reviews_count')),
            TextEntry::make('exam_answers_count')->label(__('admin.learners.fields.exam_answers_count')),
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
                    ->label(__('admin.learners.fields.name'))
                    ->searchable(['first_name', 'last_name']),
                Tables\Columns\TextColumn::make('username')
                    ->label(__('admin.learners.fields.username'))
                    ->prefix('@')
                    ->placeholder('—')
                    // "@name" and "name" both match; the leading @ is not part of the stored username.
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereLike('username', '%'.ltrim(trim($search), '@').'%')),
                // Searching by Telegram ID is exact (the number is an identity: never a partial match) and admin-only,
                // also here, because a hidden column still takes part in the table search.
                Tables\Columns\TextColumn::make('telegram_user_id')
                    ->label(__('admin.learners.fields.telegram_id'))
                    ->copyable()
                    ->toggleable()
                    ->visible($isAdmin)
                    ->searchable(query: fn (Builder $query, string $search): Builder => app(PanelDataScope::class)->isAdmin() && ctype_digit(trim($search)) && strlen(trim($search)) <= 18
                        ? $query->where('learners.telegram_user_id', (int) trim($search))
                        : $query->whereNull('learners.id')),
                Tables\Columns\TextColumn::make('plan')
                    ->label(__('admin.learners.fields.plan'))
                    ->badge()
                    ->state(fn (Learner $record): string => match (true) {
                        $record->getAttribute('personal_pro_id') !== null => self::planLabel(Plan::Pro, sponsored: false),
                        $record->getAttribute('sponsor_pro_id') !== null => self::planLabel(Plan::Pro, sponsored: true),
                        default => self::planLabel(Plan::Free, sponsored: false),
                    })
                    ->color(fn (string $state): string => $state === self::planLabel(Plan::Free, sponsored: false) ? 'gray' : 'success')
                    ->visible($isAdmin),
                Tables\Columns\IconColumn::make('onboarded')
                    ->label(__('admin.learners.fields.onboarded'))
                    ->state(fn (Learner $record): bool => $record->onboarding_state === OnboardingService::DONE)
                    ->boolean()
                    ->toggleable()
                    ->visible($isAdmin),
                Tables\Columns\TextColumn::make('groups_list')
                    ->label(__('admin.learners.fields.groups'))
                    ->state(fn (Learner $record): string => self::groupTitles($record)),
                Tables\Columns\IconColumn::make('blocked')
                    ->label(__('admin.learners.fields.blocked'))
                    ->state(fn (Learner $record): bool => $record->blocked_at !== null)
                    ->boolean()
                    ->trueColor('danger')
                    ->falseColor('gray')
                    ->visible($isAdmin),
                // Invited learners (docs/13 §7.1): all non-rejected referrals; the flagged ones are in the Referrals tab.
                Tables\Columns\TextColumn::make('referrals_count')
                    ->label(__('admin.learners.fields.referrals_count'))
                    ->counts(['referrals' => fn (Builder $q): Builder => $q->where('status', '<>', ReferralStatus::Rejected->value)])
                    ->sortable()
                    ->toggleable()
                    ->visible($isAdmin),
                Tables\Columns\TextColumn::make('last_seen_at')->label(__('admin.learners.fields.last_seen_at'))->dateTime()->sortable()->toggleable()->visible($isAdmin),
                Tables\Columns\TextColumn::make('created_at')->label(__('admin.learners.fields.created_at'))->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true)->visible($isAdmin),
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
                    ->label(__('admin.learners.filters.group')),
                // Platform filters: admin only. `visible()` hides the control; the query closures re-check the
                // role because the filter state comes from the client.
                Tables\Filters\SelectFilter::make('plan')
                    ->label(__('admin.learners.filters.plan'))
                    ->options([
                        'free' => AdminLabels::value('common.plan', 'free'),
                        'pro' => __('admin.learners.filters.plan_pro'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => self::filterByPlan($query, $data['value'] ?? null))
                    ->visible($isAdmin),
                Tables\Filters\TernaryFilter::make('onboarded')
                    ->label(__('admin.learners.filters.onboarding'))
                    ->placeholder(__('admin.common.all'))
                    ->trueLabel(__('admin.learners.filters.onboarding_done'))
                    ->falseLabel(__('admin.learners.filters.onboarding_not_done'))
                    ->queries(
                        true: fn (Builder $query): Builder => app(PanelDataScope::class)->isAdmin() ? $query->where('onboarding_state', OnboardingService::DONE) : $query,
                        false: fn (Builder $query): Builder => app(PanelDataScope::class)->isAdmin() ? $query->where('onboarding_state', '<>', OnboardingService::DONE) : $query,
                        blank: fn (Builder $query): Builder => $query,
                    )
                    ->visible($isAdmin),
            ])
            ->emptyStateHeading(__('admin.learners.empty'))
            ->actions([
                Actions\Action::make('toggleBlocked')
                    ->icon('heroicon-o-no-symbol')
                    ->label(fn (Learner $record): string => $record->blocked_at !== null ? __('admin.learners.actions.unblock') : __('admin.learners.actions.block'))
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
                // The SRS A/B arm (docs/13 §8.3): admin only, audited (`learner.scheduler_changed`).
                Actions\Action::make('switchScheduler')
                    ->icon('heroicon-o-arrows-right-left')
                    ->label(__('admin.learners.actions.scheduler'))
                    ->visible($isAdmin)
                    ->authorize('update')
                    ->schema([
                        Select::make('scheduler')
                            ->label(__('admin.learners.actions.scheduler_field'))
                            ->options(array_combine(
                                array_map(fn (SchedulerKind $k): string => $k->value, SchedulerKind::cases()),
                                array_map(fn (SchedulerKind $k): string => $k->label(), SchedulerKind::cases()),
                            ))
                            ->default(fn (Learner $record): string => app(SchedulerResolver::class)->kindFor($record)->value)
                            ->required()
                            ->native(false),
                    ])
                    ->modalDescription(__('admin.learners.actions.scheduler_help'))
                    ->action(function (Learner $record, array $data): void {
                        /** @var User $actor */
                        $actor = Auth::user();
                        $to = SchedulerKind::tryFrom((string) ($data['scheduler'] ?? ''));
                        if ($to === null) {
                            return;
                        }

                        app(SchedulerSwitchService::class)->switchTo($record, $to, $actor, request()->ip());
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

    /** The one plan caption of the platform view (list column and learner page): "Free", "Pro", "Pro (от центра)". */
    public static function planLabel(Plan $plan, bool $sponsored): string
    {
        return AdminLabels::value('common.plan', $plan->value)
            .($sponsored && $plan !== Plan::Free ? ' '.__('admin.learners.plan_sponsored') : '');
    }

    /** "SM-2" / "FSRS-5", with "(по умолчанию)" when the learner has no bucket of their own. */
    public static function schedulerLabel(Learner $record): string
    {
        $kind = app(SchedulerResolver::class)->kindFor($record);

        return $kind->label().($record->scheduler === null ? ' '.__('admin.learners.scheduler_default') : '');
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
