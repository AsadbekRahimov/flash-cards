<?php

declare(strict_types=1);

namespace App\Domain\Localization\Services;

use App\Models\Student;
use App\Models\TelegramGroup;

/**
 * Decides which interface language (ru / uz) a bot message is written in.
 *
 * The resolver never touches the process-wide locale (queue workers are
 * long-lived): callers pass the result as the explicit `$locale` argument of
 * `__()` / `trans_choice()`.
 *
 *   - student (DM, reminders): `students.language_code` -> `uz*` => uz, else default
 *   - group chat:              `telegram_groups.meta['locale']` if supported, else default
 *   - teacher:                 default
 */
final class LocaleResolver
{
    /** @return list<string> */
    public function supported(): array
    {
        $supported = [];
        foreach ((array) config('localization.supported', []) as $locale) {
            if (is_string($locale) && $locale !== '') {
                $supported[] = $locale;
            }
        }

        return $supported !== [] ? $supported : ['ru'];
    }

    public function isSupported(?string $locale): bool
    {
        return $locale !== null && in_array($locale, $this->supported(), true);
    }

    public function fallback(): string
    {
        $fallback = config('localization.fallback');

        return is_string($fallback) && $this->isSupported($fallback)
            ? $fallback
            : $this->supported()[0];
    }

    public function default(): string
    {
        $default = config('localization.default');

        return is_string($default) && $this->isSupported($default)
            ? $default
            : $this->fallback();
    }

    /**
     * Maps a Telegram `language_code` (IETF tag such as `uz`, `uz-UZ`, `ru`,
     * `en-GB`) to a supported locale by its primary subtag.
     */
    public function fromLanguageCode(?string $languageCode): string
    {
        if ($languageCode === null || trim($languageCode) === '') {
            return $this->default();
        }

        $primary = strtolower(preg_split('/[-_]/', trim($languageCode), 2)[0] ?? '');

        return $this->isSupported($primary) ? $primary : $this->default();
    }

    public function forStudent(Student $student): string
    {
        return $this->fromLanguageCode($student->language_code);
    }

    public function forGroup(TelegramGroup $group): string
    {
        $locale = $group->meta['locale'] ?? null;

        return is_string($locale) && $this->isSupported($locale)
            ? $locale
            : $this->default();
    }

    public function forTeacher(): string
    {
        return $this->default();
    }
}
