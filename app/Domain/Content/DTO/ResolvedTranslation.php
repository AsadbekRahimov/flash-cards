<?php

declare(strict_types=1);

namespace App\Domain\Content\DTO;

/**
 * A word translation picked for a requested locale.
 *
 * `$locale` is the locale the text really is in; `$isFallback` is true when
 * it differs from the one requested (e.g. uz requested, ru shown).
 */
final readonly class ResolvedTranslation
{
    public function __construct(
        public string $text,
        public string $locale,
        public bool $isFallback,
    ) {}
}
