<?php

declare(strict_types=1);

namespace App\Domain\Learners\Services;

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
    private const PROFILE_FIELDS = ['first_name' => 100, 'last_name' => 100, 'username' => 100, 'language_code' => 10];

    public function __construct(private readonly LocaleResolver $locales) {}

    /**
     * The person acted themselves. Upsert by `telegram_user_id`: revives a soft-deleted
     * learner, refreshes the profile fields the payload carries and `last_seen_at`.
     * `interface_locale` is derived from the Telegram language only for a learner who has
     * not chosen one yet (onboarding `new`) and only when the payload carries a language;
     * `acquisition_source` / `start_payload` are written once, on the first contact that
     * carries them.
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
     * @param  array<string, mixed>  $tgUser
     */
    private function register(array $tgUser, ?string $source, ?string $startPayload, bool $selfInitiated): ?Learner
    {
        $telegramUserId = (int) ($tgUser['id'] ?? 0);
        if ($telegramUserId <= 0) {
            throw new \InvalidArgumentException('Telegram user id is required.');
        }

        // Only the keys Telegram actually sent: an absent key must not erase what is stored.
        $profile = [];
        foreach (self::PROFILE_FIELDS as $field => $max) {
            if (array_key_exists($field, $tgUser)) {
                $profile[$field] = $this->nullableString($tgUser[$field], $max);
            }
        }

        try {
            return $this->upsert($telegramUserId, $profile, $source, $startPayload, $selfInitiated);
        } catch (UniqueConstraintViolationException) {
            // Lost a creation race with a parallel request (e.g. /auth and a chat update): reuse its row.
            return $this->upsert($telegramUserId, $profile, $source, $startPayload, $selfInitiated);
        }
    }

    /**
     * @param  array<string, string|null>  $profile
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
        if ($learner->acquisition_source === null && $source !== null) {
            $learner->acquisition_source = $source;
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
