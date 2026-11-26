<?php

declare(strict_types=1);

namespace App\Domain\Game\DTO;

use Closure;

/**
 * The farthest tier of the distractors of a run (docs/18 §12.3.1): the words of the public courses of the platform
 * ({@see RunPoolWord::TIER_PUBLIC}), read only when the composer asks - that is, when some word of the run did not find three
 * distractors among the words of the run and of its course. Most runs never ask, and then nothing is read for the tier at all:
 * neither the pool of the platform nor the translations of its words.
 *
 * Read once: every word of the run that asks gets the same list, so the composition stays a function of the pool and the seed.
 */
final class PublicDistractors
{
    /** @var list<RunPoolWord>|null */
    private ?array $words = null;

    /** @param  Closure(): list<RunPoolWord>  $load */
    public function __construct(private readonly Closure $load) {}

    /** @param  list<RunPoolWord>  $words */
    public static function of(array $words): self
    {
        return new self(static fn (): array => $words);
    }

    /** @return list<RunPoolWord> */
    public function all(): array
    {
        return $this->words ??= ($this->load)();
    }

    public function wasRead(): bool
    {
        return $this->words !== null;
    }
}
