<?php

declare(strict_types=1);

namespace App\Filament\Resources\SubscriptionResource\Support;

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Exceptions\SubscriptionException;
use App\Domain\Billing\Services\SubscriptionService;
use App\Domain\Billing\Support\AutoRenewal;
use App\Domain\Billing\Support\OrgTiers;
use App\Domain\Billing\Support\SubscriptionLiveness;
use App\Filament\Resources\Support\AdminDates;
use App\Filament\Resources\Support\AdminErrors;
use App\Filament\Resources\Support\AdminLabels;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Grant Pro" (header) and "Revoke" (row). Both go through {@see SubscriptionService}, which
 * validates, locks, writes the audit record and invalidates the entitlement cache; the action
 * only collects input and reports a {@see SubscriptionException} as a notification.
 */
final class SubscriptionActions
{
    public static function grantPro(): Action
    {
        return Action::make('grantPro')
            ->label(__('admin.subscriptions.actions.grant'))
            ->icon('heroicon-o-gift')
            ->color('success')
            ->authorize('create', Subscription::class)
            ->modalHeading(__('admin.subscriptions.actions.grant_heading'))
            ->modalDescription(__('admin.subscriptions.actions.grant_help'))
            ->schema([
                Forms\Components\Select::make('subscriber_type')
                    ->label(__('admin.subscriptions.grant.subscriber_type'))
                    ->options([
                        'learner' => AdminLabels::value('common.subscriber_type', 'learner'),
                        'organization' => __('admin.subscriptions.grant.subscriber_organization'),
                    ])
                    ->default('learner')
                    ->required()
                    ->live(),
                Forms\Components\Select::make('learner_id')
                    ->label(__('admin.subscriptions.grant.learner'))
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => self::searchLearners($search))
                    ->getOptionLabelUsing(fn (mixed $value): ?string => ($learner = Learner::query()->whereKey($value)->first()) instanceof Learner ? self::learnerLabel($learner) : null)
                    ->helperText(__('admin.subscriptions.grant.learner_help'))
                    ->live()
                    ->visible(fn (callable $get): bool => $get('subscriber_type') === 'learner')
                    ->required(fn (callable $get): bool => $get('subscriber_type') === 'learner'),
                Text::make(__('admin.subscriptions.grant.stars_warning'))
                    ->color('warning')
                    ->visible(fn (callable $get): bool => self::runningStars($get) !== null),
                Forms\Components\Checkbox::make('confirm_stars_autorenew')
                    ->label(__('admin.subscriptions.grant.stars_confirm'))
                    ->accepted()
                    ->visible(fn (callable $get): bool => self::runningStars($get) !== null)
                    ->validationMessages(['accepted' => __('admin.subscriptions.grant.stars_confirm_error')]),
                Forms\Components\Select::make('organization_id')
                    ->label(__('admin.subscriptions.grant.organization'))
                    ->options(fn (): array => Organization::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->visible(fn (callable $get): bool => $get('subscriber_type') === 'organization')
                    ->required(fn (callable $get): bool => $get('subscriber_type') === 'organization'),
                Forms\Components\Select::make('tier')
                    ->label(__('admin.subscriptions.grant.tier'))
                    ->options(fn (): array => self::tierOptions())
                    ->placeholder(__('admin.subscriptions.grant.tier_placeholder'))
                    ->helperText(__('admin.subscriptions.grant.tier_help'))
                    ->visible(fn (callable $get): bool => $get('subscriber_type') === 'organization'),
                Forms\Components\TextInput::make('seats_limit')
                    ->label(__('admin.subscriptions.grant.seats'))
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->helperText(__('admin.subscriptions.grant.seats_help'))
                    ->visible(fn (callable $get): bool => $get('subscriber_type') === 'organization' && filled($get('tier')) && OrgTiers::exists((string) $get('tier')) && OrgTiers::seats((string) $get('tier')) === null),
                Forms\Components\Toggle::make('perpetual')
                    ->label(__('admin.subscriptions.grant.perpetual'))
                    ->live(),
                Forms\Components\DateTimePicker::make('until')
                    ->label(__('admin.subscriptions.grant.until'))
                    ->timezone(config('learners.default_timezone'))
                    ->live()
                    ->helperText(fn (callable $get): string => __('admin.subscriptions.grant.until_help', ['tz' => config('learners.default_timezone')]).self::shortenWarning($get))
                    // Validated as typed (local wall-clock time), so "now" is expressed in the same zone.
                    ->minDate(fn () => now()->setTimezone(config('learners.default_timezone')))
                    ->visible(fn (callable $get): bool => ! $get('perpetual'))
                    ->required(fn (callable $get): bool => ! $get('perpetual')),
                Forms\Components\Textarea::make('note')
                    ->label(__('admin.subscriptions.grant.note'))
                    ->required()
                    ->maxLength(500)
                    ->rows(2),
            ])
            ->action(function (array $data): void {
                $user = auth()->user();
                if (! $user instanceof User) {
                    return;
                }

                $subscriber = $data['subscriber_type'] === 'organization'
                    ? Organization::query()->whereKey($data['organization_id'] ?? 0)->first()
                    : Learner::query()->whereKey($data['learner_id'] ?? 0)->first();

                if ($subscriber === null) {
                    self::failed(SubscriptionException::subscriberNotFound((string) $data['subscriber_type'], (int) ($data['learner_id'] ?? $data['organization_id'] ?? 0)));

                    return;
                }

                $until = ($data['perpetual'] ?? false) || empty($data['until']) ? null : CarbonImmutable::parse((string) $data['until']);

                try {
                    $tier = $data['subscriber_type'] === 'organization' && filled($data['tier'] ?? null) ? (string) $data['tier'] : null;

                    app(SubscriptionService::class)->grantManual($subscriber, Plan::Pro, $until, $user, (string) $data['note'], request()->ip(), tier: $tier, seatsLimit: $tier !== null && filled($data['seats_limit'] ?? null) ? (int) $data['seats_limit'] : null);
                } catch (SubscriptionException $e) {
                    self::failed($e);

                    return;
                }

                Notification::make()->title(__('admin.subscriptions.notifications.granted'))->success()->send();
            });
    }

    public static function revoke(): Action
    {
        return Action::make('revoke')
            ->label(__('admin.subscriptions.actions.revoke'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (Subscription $record): bool => in_array($record->status, SubscriptionStatus::liveValues(), true))
            ->authorize('update')
            ->requiresConfirmation()
            ->modalDescription(__('admin.subscriptions.actions.revoke_help'))
            ->schema([
                Forms\Components\Textarea::make('note')->label(__('admin.subscriptions.actions.reason'))->required()->maxLength(500)->rows(2),
            ])
            ->action(function (Subscription $record, array $data): void {
                $user = auth()->user();
                if (! $user instanceof User) {
                    return;
                }

                try {
                    app(SubscriptionService::class)->revoke($record, $user, (string) $data['note'], request()->ip());
                } catch (SubscriptionException $e) {
                    self::failed($e);

                    return;
                }

                Notification::make()->title(__('admin.subscriptions.notifications.revoked'))->success()->send();
            });
    }

    /** The learner picked in the form, when their Stars subscription still renews (Telegram keeps charging). */
    private static function runningStars(callable $get): ?Subscription
    {
        $learnerId = $get('learner_id');

        if ($get('subscriber_type') !== 'learner' || ! is_numeric($learnerId)) {
            return null;
        }

        return AutoRenewal::forLearner((int) $learnerId, CarbonImmutable::now());
    }

    /** Text to append to the "Pro until" hint when the date is earlier than the end of the period the learner already paid for. */
    private static function shortenWarning(callable $get): string
    {
        $learnerId = $get('learner_id');
        // `$get()` gives the state AFTER the casts of the field (DateTimeStateCast::get): the clock time typed in the zone of the
        // picker (`learners.default_timezone`) is already converted to the zone of the app. So it is parsed in the app zone below;
        // parsing it in the zone of the picker would shift it a second time (FilamentDateDisplayTest pins the boundary in three zones).
        $until = $get('until');

        if ($get('subscriber_type') !== 'learner' || ! is_numeric($learnerId) || ! is_string($until) || $until === '') {
            return '';
        }

        $now = CarbonImmutable::now();

        /** @var Subscription|null $live */
        $live = Subscription::query()
            ->where('subscriber_type', 'learner')
            ->where('subscriber_id', (int) $learnerId)
            ->whereIn('status', SubscriptionStatus::liveValues())
            ->orderBy('id')
            ->first();

        $paidUntil = $live !== null && SubscriptionLiveness::isLive($live, $now) ? $live->current_period_ends_at : null;

        if ($paidUntil === null || ! CarbonImmutable::parse($until)->lessThan(CarbonImmutable::instance($paidUntil))) {
            return '';
        }

        // In the zone of the date picker above, whose help line names it.
        return __('admin.subscriptions.grant.until_warning', ['date' => AdminDates::dateTime($paidUntil, (string) config('learners.default_timezone'))]);
    }

    /** @return array<string, string> tier key => "Центр S · мест: 100" */
    private static function tierOptions(): array
    {
        $options = [];

        foreach (OrgTiers::keys() as $tier) {
            $seats = OrgTiers::seats($tier);
            $name = AdminLabels::value('common.tier', $tier);
            $options[$tier] = $seats === null
                ? __('admin.subscriptions.grant.tier_option_unlimited', ['tier' => $name])
                : __('admin.subscriptions.grant.tier_option', ['tier' => $name, 'seats' => $seats]);
        }

        return $options;
    }

    private static function failed(SubscriptionException $e): void
    {
        Notification::make()->title(__('admin.subscriptions.notifications.failed'))->body(AdminErrors::for('subscriptions', $e))->danger()->persistent()->send();
    }

    /** @return array<int, string> */
    private static function searchLearners(string $search): array
    {
        $like = '%'.mb_strtolower(trim($search)).'%';

        return Learner::query()
            ->where(function (Builder $query) use ($like, $search): void {
                $query->whereRaw('lower(first_name) like ?', [$like])
                    ->orWhereRaw('lower(last_name) like ?', [$like])
                    ->orWhereRaw('lower(username) like ?', [$like]);

                if (ctype_digit(trim($search))) {
                    $query->orWhere('telegram_user_id', (int) trim($search));
                }
            })
            ->orderBy('first_name')
            ->limit(30)
            ->get()
            ->mapWithKeys(fn (Learner $learner): array => [$learner->id => self::learnerLabel($learner)])
            ->all();
    }

    private static function learnerLabel(Learner $learner): string
    {
        $name = trim(($learner->first_name ?? '').' '.($learner->last_name ?? ''));
        $username = $learner->username !== null ? ' @'.$learner->username : '';

        return __('admin.subscriptions.grant.learner_label', ['name' => trim($name.$username), 'id' => $learner->telegram_user_id]);
    }
}
