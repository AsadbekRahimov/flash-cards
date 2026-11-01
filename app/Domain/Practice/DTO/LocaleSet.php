<?php

declare(strict_types=1);

namespace App\Domain\Practice\DTO;

use InvalidArgumentException;

/**
 * The locales a snapshot is built for (docs/16 §1.2): solo - the locale of the learner; a battle - the locales of
 * both players (unique). The unambiguity of pairs is checked in EACH of them, after the fallback (§4.4).
 */
final readonly class LocaleSet
{
    /** @var list<string> */
    public array $locales;

    /** @param list<string> $locales */
    public function __construct(array $locales)
    {
        $unique = array_values(array_unique($locales));
        if ($unique === []) {
            throw new InvalidArgumentException('A locale set needs at least one locale.');
        }

        $this->locales = $unique;
    }

    public static function of(string ...$locales): self
    {
        return new self(array_values($locales));
    }

    /** The first locale: the only one of a solo session, the challenger's in a battle. */
    public function primary(): string
    {
        return $this->locales[0];
    }

    public function contains(string $locale): bool
    {
        return in_array($locale, $this->locales, true);
    }
}
