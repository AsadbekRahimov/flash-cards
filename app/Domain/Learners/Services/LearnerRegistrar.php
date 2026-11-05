<?php

declare(strict_types=1);

namespace App\Domain\Learners\Services;

use App\Domain\Learners\DTO\StartPayload;
use App\Domain\Learners\Enums\StartPayloadKind;
use App\Domain\Learning\Services\SchedulerResolver;
use App\Domain\Localization\Services\LocaleResolver;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use stdClass;

/**
 * The single entry point that turns a Telegram user (initData, bot update,
 * chat_member) into a `learners` row: one learner per `telegram_user_id`.
 *
 * Two kinds of contact (docs/07 §10):
 *  - the person's own action (opening the Mini App or `/start` in DM):
 *    revives a soft-deleted learner and bumps `last_seen_at`;
 *  - a passive event about them (someone else added them, their group status changed) —
 *    `observeTelegramUser()`: never revives, never bumps `last_seen_at`.
 */
final class LearnerRegistrar
{
    /** Telegram always sends the whole `User` (optional fields are simply absent): an absent key means "no value". */
    private const SNAPSHOT_FIELDS = ['first_name' => 100, 'last_name' => 100, 'username' => 100];

    private const LANGUAGE_MAX = 10;

    public function __construct(
        private readonly LocaleResolver $locales,
        private readonly LearnerClock $clock,
        private readonly SchedulerResolver $schedulers,
        private readonly AccountRestoreNotice $restoreNotice,
    ) {}

    /**
     * The person acted themselves. Upsert by `telegram_user_id`: revives a soft-deleted
     * learner, refreshes the profile and `last_seen_at`. `first_name` / `last_name` /
     * `username` are a full snapshot (an absent key clears the stored value); `language_code`
     * is updated only when the payload carries a non-empty one, and only then is
     * `interface_locale` derived from it — and only for a learner who has not chosen one yet
     * (onboarding `new` and no `meta.locale_chosen` from the settings). `acquisition_source` is written once, when the learner is created;
     * `start_payload` — on the first contact that carries it.
     *
     * @param  array<string, mixed>  $tgUser  Telegram `User` object (`id`, `first_name`, ...)
     */
    public function fromTelegramUser(array $tgUser, ?string $source = null, ?string $startPayload = null): Learner
    {
        $learner = $this->register($tgUser, $source, $startPayload, selfInitiated: true);
        assert($learner !== null);

        return $learner;
    }

    /** @param array<string, mixed> $tgUser */
    public function fromMiniApp(array $tgUser, int $authDate): ?Learner
    {
        return $this->register($tgUser, null, null, selfInitiated: true, authDate: $authDate);
    }

    /**
     * A passive event about the person. Creates a learner for an unknown id (without
     * `last_seen_at`) and refreshes the profile of a live one, but returns null for a
     * soft-deleted learner: only their own action brings them back.
     *
     * @param  array<string, mixed>  $tgUser
     */
    public function observeTelegramUser(array $tgUser, ?string $source = null): ?Learner
    {
        return $this->register($tgUser, $source, null, selfInitiated: false);
    }

    /**
     * First-touch attribution from a `/start <payload>` (docs/12 §7.2): written only while
     * `acquisition_source` is still empty — a learner created earlier without a source (e.g. via
     * `/auth`) gets the source of their first deep link; one that already has a source (`group`,
     * an earlier link) keeps it, together with `start_payload` / `referrer_learner_id`. A
     * `ref_<code>` link with the learner's own code or an unknown / deleted / blocked inviter is
     * ignored entirely, so a later valid link can still attribute. The write is a conditional UPDATE,
     * so two parallel `/start` updates cannot overwrite each other. The `referrals` row itself
     * (reward machinery, docs/13 §7.1) is ReferralService::link's business, not attribution.
     *
     * Deliberately separate from {@see self::fromTelegramUser()}: it is the only place that
     * attributes after the learner exists, and the other paths keep their behaviour.
     *
     * @return bool whether the payload was recorded
     */
    public function attributeAcquisition(Learner $learner, StartPayload $payload): bool
    {
        $source = $payload->acquisitionSource();
        if ($source === null || $learner->acquisition_source !== null) {
            return false;
        }

        $values = ['acquisition_source' => $source];

        if ($payload->kind === StartPayloadKind::Ref) {
            $referrerId = Learner::query()
                ->where('referral_code', (string) $payload->referralCode())
                ->whereNull('blocked_at')
                ->value('id');
            if ($referrerId === null || (int) $referrerId === $learner->id) {
                return false;
            }
            $values['referrer_learner_id'] = (int) $referrerId;
        }

        if ($learner->start_payload === null) {
            $values['start_payload'] = $payload->raw;
        }

        $updated = Learner::query()
            ->whereKey($learner->id)
            ->whereNull('acquisition_source')
            ->update($values);

        if ($updated === 0) {
            // Lost the race: another request has attributed this learner first.
            $learner->refresh();

            return false;
        }

        $learner->forceFill($values)->syncOriginalAttributes(array_keys($values));

        return true;
    }

    /**
     * @param  array<string, mixed>  $tgUser
     */
    private function register(array $tgUser, ?string $source, ?string $startPayload, bool $selfInitiated, ?int $authDate = null): ?Learner
    {
        $telegramUserId = (int) ($tgUser['id'] ?? 0);
        if ($telegramUserId <= 0) {
            throw new \InvalidArgumentException('Telegram user id is required.');
        }

        $profile = [];
        foreach (self::SNAPSHOT_FIELDS as $field => $max) {
            $profile[$field] = $this->nullableString($tgUser[$field] ?? null, $max);
        }
        // A bare User without `language_code` must not reset the stored language and the chosen locale.
        $language = $this->nullableString($tgUser['language_code'] ?? null, self::LANGUAGE_MAX);
        if ($language !== null) {
            $profile['language_code'] = $language;
        }

        try {
            return $this->upsert($telegramUserId, $profile, $source, $startPayload, $selfInitiated, $authDate);
        } catch (UniqueConstraintViolationException) {
            // Lost a creation race with a parallel request (e.g. /auth and a chat update): reuse its row.
            return $this->upsert($telegramUserId, $profile, $source, $startPayload, $selfInitiated, $authDate);
        }
    }

    /**
     * @param  array<string, string|null>  $profile  `language_code` only when present
     * @param  bool  $retrying  second pass after the soft-deleted row vanished under us (purged): no third one
     */
    private function upsert(int $telegramUserId, array $profile, ?string $source, ?string $startPayload, bool $selfInitiated, ?int $authDate, bool $retrying = false): ?Learner
    {
        /** @var Learner|null $learner */
        $learner = Learner::withTrashed()->where('telegram_user_id', $telegramUserId)->first();

        if ($learner === null) {
            $timezone = (string) config('learners.default_timezone');
            $reminderTime = trim((string) config('learners.default_reminder_time'));

            /** @var Learner $created */
            $created = Learner::query()->create([
                'telegram_user_id' => $telegramUserId,
                'first_name' => null,
                'last_name' => null,
                'username' => null,
                'language_code' => null,
                ...$profile,
                'interface_locale' => $this->locales->fromLanguageCode($profile['language_code'] ?? null),
                'timezone' => $timezone,
                'daily_goal' => (int) config('learners.default_daily_goal'),
                'reminder_time' => $reminderTime === '' ? null : $reminderTime,
                // Reminders start with the defaults (docs/12 §7.5): a learner who never writes
                // to the bot gets one attempt, a 403 then sets `dm_blocked_at`. The onboarding
                // recomputes this when the zone or the time is chosen.
                'next_reminder_at' => $this->firstReminderAt($timezone, $reminderTime),
                'onboarding_state' => 'new',
                // The SRS A/B bucket is fixed here, once (docs/13 §8.3): a stable hash of the Telegram id
                // against `learning.fsrs.rollout_percent`; a later change of the percentage never moves anyone.
                'scheduler' => $this->schedulers->bucketFor($telegramUserId),
                'acquisition_source' => $source,
                'start_payload' => $startPayload,
                'last_seen_at' => $selfInitiated ? now() : null,
                // An object, like the column default `{}`: the `array` cast would store an empty PHP array as `[]`.
                'meta' => new stdClass,
            ]);

            return $created;
        }

        if ($learner->trashed()) {
            if (! $selfInitiated) {
                Log::debug('learner.passive_event_skipped_trashed', ['learner_id' => $learner->id]);

                return null;
            }

            // The unique telegram_user_id still points at the soft-deleted row; coming back revives it. A conditional
            // UPDATE, not restore(): the final deletion (LearnerPurgeService, docs/03 §8 rule 2) may have removed the row
            // between our read and now — then there is nothing to revive and the person starts afresh with a new row.
            $deleted = Learner::withTrashed()->whereKey($learner->id)->whereNotNull('deleted_at');
            if ($authDate !== null) {
                // An automatic JWT refresh from an already open Mini App must not undo a deletion.
                if ($learner->deleted_at?->timestamp >= $authDate) {
                    return null;
                }
                $deleted->where('deleted_at', '<', CarbonImmutable::createFromTimestampUTC($authDate));
            }
            $revived = $deleted->update(['deleted_at' => null]);

            if ($revived !== 1) {
                if ($retrying) {
                    return null;
                }

                return $this->upsert($telegramUserId, $profile, $source, $startPayload, $selfInitiated, $authDate, retrying: true);
            }

            $learner->setAttribute('deleted_at', null);
            $learner->syncOriginalAttribute('deleted_at');

            // R11b: the bot's /start or the Mini App tells "the account is restored" once; a new row gets no notice.
            $this->restoreNotice->markPending($learner);
        }

        $learner->fill($profile);
        if ($selfInitiated) {
            $learner->last_seen_at = now();
        }

        // `meta.locale_chosen`: the learner picked a language in the settings before `/start` — it stays.
        if ($learner->onboarding_state === 'new'
            && ($learner->meta[LearnerSettingsService::LOCALE_CHOSEN] ?? false) !== true
            && ($profile['language_code'] ?? null) !== null
        ) {
            $learner->interface_locale = $this->locales->fromLanguageCode($profile['language_code']);
        }
        if ($learner->start_payload === null && $startPayload !== null) {
            $learner->start_payload = $startPayload;
        }

        $learner->save();

        return $learner;
    }

    private function firstReminderAt(string $timezone, string $reminderTime): ?Carbon
    {
        if ($reminderTime === '') {
            return null;
        }

        $transient = new Learner(['timezone' => $timezone]);

        return Carbon::instance($this->clock->nextOccurrenceUtc($transient, $reminderTime, CarbonImmutable::now()));
    }

    private function nullableString(mixed $value, int $max): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr($value, 0, $max);
    }
}
