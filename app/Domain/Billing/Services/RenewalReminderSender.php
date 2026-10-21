<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Support\AutoRenewal;
use App\Domain\Billing\Support\SubscriptionLiveness;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Jobs\RenewalReminderJob;
use App\Mail\SubscriptionRenewalDueMail;
use App\Models\Learner;
use App\Models\NotificationLog;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The body of {@see RenewalReminderJob} (docs/13 §4.4): tells the subscriber that a non-renewing
 * subscription ends in N days.
 *
 *  - learner: a DM with a button into the TWA `/billing`; `notifications_log.type = renewal_reminder`;
 *    skipped for a blocked learner or `dm_blocked_at`; a Telegram 403 sets `dm_blocked_at`;
 *  - organization: an e-mail to every active owner (`organization_users.role = owner`) in the owner's
 *    language, plus a DM to an owner who has a Telegram link (`users.telegram_user_id`); without the
 *    link — e-mail only.
 *
 * The job is skipped when the subscription changed since the event (renewed, ended, not paid any more):
 * the reminder would be wrong.
 *
 * At most once per (recipient, subscription, period end, days left), including retries of the job:
 * the marker (a `notifications_log` row for a known learner, a cache key otherwise) is written BEFORE
 * the call — a timeout may mean "delivered anyway", and a missed reminder is better than a double one.
 * A Telegram 403 (certainly not delivered) removes the marker again; a failed e-mail transport also
 * releases its marker, and the exception makes the queue retry. A failure for one recipient never
 * blocks the others: the first exception is rethrown after all were tried.
 */
final class RenewalReminderSender
{
    public const NOTIFICATION_TYPE = 'renewal_reminder';

    public const TWA_PATH = '/twa/billing?from=renewal';

    private const MARKER_TTL_DAYS = 45;

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
    ) {}

    /** @return int how many messages (DM + e-mail) went out */
    public function send(int $subscriptionId, int $daysLeft, CarbonImmutable $endsAt, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();

        /** @var Subscription|null $subscription */
        $subscription = Subscription::query()->find($subscriptionId);

        if ($subscription === null || ! $this->stillDue($subscription, $endsAt, $now)) {
            return 0;
        }

        $key = $subscription->id.':'.$daysLeft.'@'.$endsAt->utc()->toIso8601String();

        if ($subscription->subscriber_type === 'learner') {
            /** @var Learner|null $learner */
            $learner = Learner::query()->find($subscription->subscriber_id);

            return $learner === null ? 0 : (int) $this->learnerDm($learner, $subscription, $daysLeft, $endsAt, $key);
        }

        /** @var Organization|null $organization */
        $organization = Organization::query()->find($subscription->subscriber_id);

        return $organization === null || ! $organization->isActive()
            ? 0
            : $this->notifyOwners($organization, $subscription, $daysLeft, $endsAt, $key);
    }

    private function stillDue(Subscription $subscription, CarbonImmutable $endsAt, CarbonImmutable $now): bool
    {
        $provider = SubscriptionProvider::tryFrom($subscription->provider);
        $period = $subscription->current_period_ends_at;

        return $provider !== null
            && $provider->isPaid()
            && $period !== null
            && CarbonImmutable::instance($period)->equalTo($endsAt)
            && SubscriptionLiveness::isLive($subscription, $now)
            && ($subscription->status === SubscriptionStatus::Canceled->value || ! AutoRenewal::renews($subscription));
    }

    private function learnerDm(Learner $learner, Subscription $subscription, int $daysLeft, CarbonImmutable $endsAt, string $key): bool
    {
        if ($learner->blocked_at !== null || $learner->dm_blocked_at !== null) {
            return false;
        }

        $locale = $this->locales->forLearner($learner);

        return $this->dm(
            $learner,
            $learner->telegram_user_id,
            $this->dmText('billing.renewal.dm', $subscription, $daysLeft, $endsAt, $learner->timezone, $locale),
            $locale,
            $key,
            null,
        );
    }

    private function notifyOwners(Organization $organization, Subscription $subscription, int $daysLeft, CarbonImmutable $endsAt, string $key): int
    {
        $owners = User::query()
            ->whereIn('id', $organization->staff()->where('role', OrganizationRole::Owner->value)->select('user_id'))
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        $sent = 0;
        $failure = null;

        foreach ($owners as $owner) {
            try {
                $sent += $this->notifyOwner($owner, $organization, $subscription, $daysLeft, $endsAt, $key);
            } catch (Throwable $e) {
                $failure ??= $e;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }

        return $sent;
    }

    private function notifyOwner(User $owner, Organization $organization, Subscription $subscription, int $daysLeft, CarbonImmutable $endsAt, string $key): int
    {
        // A learner row exists only when the owner wrote to the bot before the account was linked.
        $learner = $owner->telegram_user_id === null
            ? null
            : Learner::query()->where('telegram_user_id', $owner->telegram_user_id)->first();

        $locale = $learner !== null
            ? $this->locales->forLearner($learner)
            : ($this->locales->isSupported($organization->locale) ? $organization->locale : $this->locales->default());

        $sent = 0;
        $failure = null;

        try {
            if ($this->mail($owner, $organization, $daysLeft, $endsAt, $locale, $key)) {
                $sent++;
            }
        } catch (Throwable $e) {
            $failure = $e;
        }

        $chatId = $owner->telegram_user_id;
        $reachable = $chatId !== null && ($learner === null || ($learner->blocked_at === null && $learner->dm_blocked_at === null));

        if ($reachable) {
            try {
                $text = $this->dmText('billing.renewal.org_dm', $subscription, $daysLeft, $endsAt, $organization->timezone, $locale, $organization->name);

                if ($this->dm($learner, (int) $chatId, $text, $locale, $key.':user'.$owner->id, $this->panelUrl())) {
                    $sent++;
                }
            } catch (Throwable $e) {
                $failure ??= $e;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }

        return $sent;
    }

    private function mail(User $owner, Organization $organization, int $daysLeft, CarbonImmutable $endsAt, string $locale, string $key): bool
    {
        $email = trim((string) $owner->email);

        if ($email === '') {
            return false;
        }

        $marker = 'billing:renewal-reminder:mail:'.$key.':user'.$owner->id;

        if (! Cache::add($marker, 1, now()->addDays(self::MARKER_TTL_DAYS))) {
            return false;
        }

        $contact = trim((string) config('billing.paywall.contact', ''));

        try {
            Mail::to($email)->send(new SubscriptionRenewalDueMail(
                organization: $organization->name,
                daysLeft: $daysLeft,
                date: $endsAt->setTimezone($organization->timezone)->format('d.m.Y'),
                panelUrl: $this->panelUrl(),
                contact: $contact === '' ? null : $contact,
                language: $locale,
            ));
        } catch (Throwable $e) {
            Cache::forget($marker);

            throw $e;
        }

        return true;
    }

    /**
     * One DM with the "Renew" button: into the TWA `/billing` for a learner, a link to the `/org` panel
     * (`$panelUrl`) for an owner. `$learner` (when known) carries the `notifications_log` marker and
     * `dm_blocked_at`; without a learner row the marker is a cache key.
     */
    private function dm(?Learner $learner, int $chatId, string $text, string $locale, string $key, ?string $panelUrl): bool
    {
        return Cache::lock('billing:renewal-reminder:lock:'.$key, 60)->block(10, function () use ($learner, $chatId, $text, $locale, $key, $panelUrl): bool {
            $log = null;

            if ($learner !== null) {
                $exists = NotificationLog::query()
                    ->where('learner_id', $learner->id)
                    ->where('type', self::NOTIFICATION_TYPE)
                    ->where('payload->key', $key)
                    ->exists();

                if ($exists) {
                    return false;
                }

                $log = NotificationLog::query()->create([
                    'learner_id' => $learner->id,
                    'type' => self::NOTIFICATION_TYPE,
                    'payload' => ['key' => $key],
                    'sent_at' => now(),
                ]);
            } elseif (! Cache::add('billing:renewal-reminder:dm:'.$key, 1, now()->addDays(self::MARKER_TTL_DAYS))) {
                return false;
            }

            try {
                $button = __('billing.renewal.button', [], $locale);

                if ($panelUrl === null) {
                    $this->telegram->sendWebAppButton(
                        chatId: $chatId,
                        text: $text,
                        buttonText: $button,
                        url: rtrim((string) config('twa.base_url'), '/').self::TWA_PATH,
                    );
                } else {
                    $this->telegram->sendMessage($chatId, $text, replyMarkup: ['inline_keyboard' => [[['text' => $button, 'url' => $panelUrl]]]]);
                }
            } catch (TelegramForbiddenException $e) {
                // Certainly not delivered: it must not count, and the learner leaves the DM selections.
                $log?->delete();

                if ($learner !== null) {
                    Learner::query()->whereKey($learner->id)->update(['dm_blocked_at' => now()->utc()->format('Y-m-d H:i:s')]);
                }

                Log::warning('telegram.renewal_reminder_forbidden', ['learner_id' => $learner?->id, 'reason' => $e->reason]);

                return false;
            }

            return true;
        });
    }

    private function dmText(string $key, Subscription $subscription, int $daysLeft, CarbonImmutable $endsAt, string $timezone, string $locale, string $organization = ''): string
    {
        $text = trans_choice($key, $daysLeft, [
            'date' => $endsAt->setTimezone($timezone)->format('d.m.Y'),
            'organization' => $organization,
        ], $locale);

        // A stopped Stars auto-renewal needs a different hint than a Payme / Click period.
        $hint = match (true) {
            $organization !== '' => 'billing.renewal.org_hint',
            $subscription->status === SubscriptionStatus::Canceled->value => 'billing.renewal.hint_canceled',
            default => 'billing.renewal.hint',
        };

        return $text."\n".__($hint, [], $locale);
    }

    private function panelUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/org';
    }
}
