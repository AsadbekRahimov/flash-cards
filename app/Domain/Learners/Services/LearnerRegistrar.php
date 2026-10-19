<?php

declare(strict_types=1);

namespace App\Domain\Learners\Services;

use App\Domain\Learners\DTO\StartPayload;
use App\Domain\Learners\Enums\StartPayloadKind;
use App\Domain\Localization\Services\LocaleResolver;
use App\Models\Learner;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * The single entry point that turns a Telegram user (initData, bot update,
 * chat_member) into a `learners` row: one learner per `telegram_user_id`.
 *
 * Two kinds of contact (docs/07 §10):
 *  - the person's own action (`/auth` in the TWA, `/start` in DM) — `fromTelegramUser()`:
 *    revives a soft-deleted learner and bumps `last_seen_at`;
 *  - a passive event about them (someone else added them, their group status changed) —
 *    `observeTelegramUser()`: never revives, never bumps `last_seen_at`.
 */
final class LearnerRegistrar
{
    /** Telegram always sends the whole `User` (optional fields are simply absent): an absent key means "no value". */
    private const SNAPSHOT_FIELDS = ['first_name' => 100, 'last_name' => 100, 'username' => 100];

    private const LANGUAGE_MAX = 10;

    public function __construct(private readonly LocaleResolver $locales) {}

    /**
     * The person acted themselves. Upsert by `telegram_user_id`: revives a soft-deleted
     * learner, refreshes the profile and `last_seen_at`. `first_name` / `last_name` /
     * `username` are a full snapshot (an absent key clears the stored value); `language_code`
     * is updated only when the payload carries a non-empty one, and only then is
     * `interface_locale` derived from it — and only for a learner who has not chosen one yet
     * (onboarding `new`). `acquisition_source` is written once, when the learner is created;
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
     * `ref_` link to the learner themself or to an unknown / deleted / blocked learner is ignored
     * entirely, so a later valid link can still attribute. The write is a conditional UPDATE,
     * so two parallel `/start` updates cannot overwrite each other.
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
            $referrerId = (int) $payload->referrerId();
            if ($referrerId === $learner->id
                || ! Learner::query()->whereKey($referrerId)->whereNull('blocked_at')->exists()
            ) {
                return false;
            }
            $values['referrer_learner_id'] = $referrerId;
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
    private function register(array $tgUser, ?string $source, ?string $startPayload, bool $selfInitiated): ?Learner
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
            return $this->upsert($telegramUserId, $profile, $source, $startPayload, $selfInitiated);
        } catch (UniqueConstraintViolationException) {
            // Lost a creation race with a parallel request (e.g. /auth and a chat update): reuse its row.
            return $this->upsert($telegramUserId, $profile, $source, $startPayload, $selfInitiated);
        }
    }

    /**
     * @param  array<string, string|null>  $profile  `language_code` only when present
     */
    private function upsert(int $telegramUserId, array $profile, ?string $source, ?string $startPayload, bool $selfInitiated): ?Learner
    {
        /** @var Learner|null $learner */
        $learner = Learner::withTrashed()->where('telegram_user_id', $telegramUserId)->first();

        if ($learner === null) {
            /** @var Learner $created */
            $created = Learner::query()->create([
                'telegram_user_id' => $telegramUserId,
                'first_name' => null,
                'last_name' => null,
                'username' => null,
                'language_code' => null,
                ...$profile,
                'interface_locale' => $this->locales->fromLanguageCode($profile['language_code'] ?? null),
                'timezone' => (string) config('learners.default_timezone'),
                'daily_goal' => (int) config('learners.default_daily_goal'),
                'reminder_time' => (string) config('learners.default_reminder_time'),
                'onboarding_state' => 'new',
                'acquisition_source' => $source,
                'start_payload' => $startPayload,
                'last_seen_at' => $selfInitiated ? now() : null,
                'meta' => [],
            ]);

            return $created;
        }

        if ($learner->trashed()) {
            if (! $selfInitiated) {
                Log::debug('learner.passive_event_skipped_trashed', ['learner_id' => $learner->id]);

                return null;
            }

            // The unique telegram_user_id still points at the soft-deleted row; coming back revives it.
            $learner->restore();
        }

        $learner->fill($profile);
        if ($selfInitiated) {
            $learner->last_seen_at = now();
        }

        if ($learner->onboarding_state === 'new' && ($profile['language_code'] ?? null) !== null) {
            $learner->interface_locale = $this->locales->fromLanguageCode($profile['language_code']);
        }
        if ($learner->start_payload === null && $startPayload !== null) {
            $learner->start_payload = $startPayload;
        }

        $learner->save();

        return $learner;
    }

    private function nullableString(mixed $value, int $max): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr($value, 0, $max);
    }
}
