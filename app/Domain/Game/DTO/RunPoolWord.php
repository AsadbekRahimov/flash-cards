<?php

declare(strict_types=1);

namespace App\Domain\Game\DTO;

use App\Domain\Learning\DTO\GapFill;
use App\Domain\Practice\DTO\MaterialWord;

/**
 * A word of the pool of a run with the facts the composer decides by (docs/18 §12.3.1), all read BEFORE the transaction by
 * `RunSnapshotBuilder`, so the composer is a pure function:
 *
 * - `audioPath`: the recording of the CURRENT text of the word (`words.audio_path` equal to what `WordAudioService::pathFor` gives, the
 *   test the dictation of the practice makes), else null - no `listen`, no sandstorm on it;
 * - `gap`: the example with the word masked (`GapFillBuilder`), else null - no `gap`;
 * - `pairs`: the candidate of `PairsMode::candidate()` - the text of the card, the labels and the keys by which the word clashes with
 *   another (docs/16 §4.4), null when some locale has no variant - the word is unfit for the pairs, `translate_*` and `listen`
 *   (nothing to put on a card, or an option that could not be told from another);
 * - `tier`: where the word comes from as a distractor - 0 the pool of the run itself (the lesson / the city), 1 the rest of the
 *   course, 2 the public courses of the platform. The nearer tier is tried first.
 */
final readonly class RunPoolWord
{
    public const TIER_POOL = 0;

    public const TIER_COURSE = 1;

    public const TIER_PUBLIC = 2;

    /** @param array{word: MaterialWord, text: string, labels: array<string, string>, keys: list<string>}|null $pairs */
    public function __construct(
        public MaterialWord $word,
        public ?string $audioPath,
        public ?GapFill $gap,
        public ?array $pairs,
        public int $tier = self::TIER_POOL,
    ) {}

    public function id(): int
    {
        return $this->word->id;
    }

    /**
     * The keys by which the word clashes with another in some locale (docs/16 §4.4); none when the word is unfit for a card.
     *
     * @return list<string>
     */
    public function clashKeys(): array
    {
        return $this->pairs['keys'] ?? [];
    }

    public function hasAudio(): bool
    {
        return $this->audioPath !== null && $this->audioPath !== '';
    }
}
