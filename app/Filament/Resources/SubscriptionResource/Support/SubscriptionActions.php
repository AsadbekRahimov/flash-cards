<?php

declare(strict_types=1);

namespace App\Filament\Resources\SubscriptionResource\Support;

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Exceptions\SubscriptionException;
use App\Domain\Billing\Services\SubscriptionService;
use App\Domain\Billing\Support\OrgTiers;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
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
            ->label('Grant Pro')
            ->icon('heroicon-o-gift')
            ->color('success')
            ->authorize('create', Subscription::class)
            ->modalHeading('Grant Pro manually')
            ->modalDescription('Extends the subject\'s live subscription if it has one. The reason is stored in the audit log.')
            ->schema([
                Forms\Components\Select::make('subscriber_type')
                    ->label('Subject')
                    ->options(['learner' => 'Learner', 'organization' => 'Organization (sponsors its groups)'])
                    ->default('learner')
                    ->required()
                    ->live(),
                Forms\Components\Select::make('learner_id')
                    ->label('Learner')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => self::searchLearners($search))
                    ->getOptionLabelUsing(fn (mixed $value): ?string => ($learner = Learner::query()->whereKey($value)->first()) instanceof Learner ? self::learnerLabel($learner) : null)
                    ->helperText('Search by name, @username or Telegram ID.')
                    ->visible(fn (callable $get): bool => $get('subscriber_type') === 'learner')
                    ->required(fn (callable $get): bool => $get('subscriber_type') === 'learner'),
                Forms\Components\Select::make('organization_id')
                    ->label('Organization')
                    ->options(fn (): array => Organization::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->visible(fn (callable $get): bool => $get('subscriber_type') === 'organization')
                    ->required(fn (callable $get): bool => $get('subscriber_type') === 'organization'),
                Forms\Components\Select::make('tier')
                    ->label('Tier')
                    ->options(fn (): array => self::tierOptions())
                    ->placeholder('No tier (seats not limited)')
                    ->helperText('Sets the organization\'s tier and seats limit from the tariff table.')
                    ->visible(fn (callable $get): bool => $get('subscriber_type') === 'organization'),
                Forms\Components\TextInput::make('seats_limit')
                    ->label('Seats')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->helperText('For a tier without fixed seats (by agreement). Empty = not limited.')
                    ->visible(fn (callable $get): bool => $get('subscriber_type') === 'organization' && filled($get('tier')) && OrgTiers::exists((string) $get('tier')) && OrgTiers::seats((string) $get('tier')) === null),
                Forms\Components\Toggle::make('perpetual')
                    ->label('No end date')
                    ->live(),
                Forms\Components\DateTimePicker::make('until')
                    ->label('Pro until')
                    ->timezone(config('learners.default_timezone'))
                    ->helperText('Time zone: '.config('learners.default_timezone').'.')
                    // Validated as typed (local wall-clock time), so "now" is expressed in the same zone.
                    ->minDate(fn () => now()->setTimezone(config('learners.default_timezone')))
                    ->visible(fn (callable $get): bool => ! $get('perpetual'))
                    ->required(fn (callable $get): bool => ! $get('perpetual')),
                Forms\Components\Textarea::make('note')
                    ->label('Reason')
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

                Notification::make()->title('Pro granted')->success()->send();
            });
    }

    public static function revoke(): Action
    {
        return Action::make('revoke')
            ->label('Revoke')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (Subscription $record): bool => in_array($record->status, SubscriptionStatus::liveValues(), true))
            ->authorize('update')
            ->requiresConfirmation()
            ->modalDescription('The subscription ends now. The reason is stored in the audit log.')
            ->schema([
                Forms\Components\Textarea::make('note')->label('Reason')->required()->maxLength(500)->rows(2),
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

                Notification::make()->title('Subscription revoked')->success()->send();
            });
    }

    /** @return array<string, string> tier key => "Center s · 100 seats" */
    private static function tierOptions(): array
    {
        $options = [];

        foreach (OrgTiers::keys() as $tier) {
            $seats = OrgTiers::seats($tier);
            $options[$tier] = ucfirst(str_replace('_', ' ', $tier)).' · '.($seats === null ? 'unlimited seats' : $seats.' seats');
        }

        return $options;
    }

    private static function failed(SubscriptionException $e): void
    {
        Notification::make()->title('Not done')->body($e->getMessage())->danger()->persistent()->send();
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

        return trim($name.$username).' · TG '.$learner->telegram_user_id;
    }
}
