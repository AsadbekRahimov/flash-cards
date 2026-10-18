<?php

declare(strict_types=1);

namespace App\Domain\Learners\Services;

use App\Domain\Localization\Services\LocaleResolver;
use App\Models\Learner;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The single entry point that turns a Telegram user (initData, bot update,
 * chat_member) into a `learners` row: one learner per `telegram_user_id`.
 */
final class LearnerRegistrar
{
    public function __construct(private readonly LocaleResolver $locales) {}

    /**
     * Upsert by `telegram_user_id`. Refreshes name / username / language_code /
     * last_seen_at on every call. `interface_locale` is derived from the Telegram
     * language only for a learner who has not chosen one yet (onboarding `new`);
     * `acquisition_source` / `start_payload` are written once, on the first
     * contact that carries them.
     *
     * @param  array<string, mixed>  $tgUser  Telegram `User` object (`id`, `first_name`, ...)
     */
    public function fromTelegramUser(array $tgUser, ?string $source = null, ?string $startPayload = null): Learner
    {
        $telegramUserId = (int) ($tgUser['id'] ?? 0);
        if ($telegramUserId <= 0) {
            throw new \InvalidArgumentException('Telegram user id is required.');
        }

        $profile = [
            'first_name' => $this->nullableString($tgUser['first_name'] ?? null, 100),
            'last_name' => $this->nullableString($tgUser['last_name'] ?? null, 100),
            'username' => $this->nullableString($tgUser['username'] ?? null, 100),
            'language_code' => $this->nullableString($tgUser['language_code'] ?? null, 10),
        ];

        try {
            return $this->upsert($telegramUserId, $profile, $source, $startPayload);
        } catch (UniqueConstraintViolationException) {
            // Lost a creation race with a parallel request (e.g. /auth and a chat update): reuse its row.
            return $this->upsert($telegramUserId, $profile, $source, $startPayload);
        }
    }

    /**
     * @param  array<string, string|null>  $profile
     */
    private function upsert(int $telegramUserId, array $profile, ?string $source, ?string $startPayload): Learner
    {
        /** @var Learner|null $learner */
        $learner = Learner::withTrashed()->where('telegram_user_id', $telegramUserId)->first();

        if ($learner === null) {
            /** @var Learner $created */
            $created = Learner::query()->create([
                'telegram_user_id' => $telegramUserId,
                ...$profile,
                'interface_locale' => $this->locales->fromLanguageCode($profile['language_code']),
                'timezone' => (string) config('learners.default_timezone'),
                'daily_goal' => (int) config('learners.default_daily_goal'),
                'reminder_time' => (string) config('learners.default_reminder_time'),
                'onboarding_state' => 'new',
                'acquisition_source' => $source,
                'start_payload' => $startPayload,
                'last_seen_at' => now(),
                'meta' => [],
            ]);

            return $created;
        }

        if ($learner->trashed()) {
            // The unique telegram_user_id still points at the soft-deleted row; coming back revives it.
            $learner->restore();
        }

        $learner->fill($profile);
        $learner->last_seen_at = now();

        if ($learner->onboarding_state === 'new') {
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
