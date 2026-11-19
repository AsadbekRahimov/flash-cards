<?php

declare(strict_types=1);

namespace App\Domain\Content\DTO;

/**
 * Outcome of `audio:coverage` (WordAudioCoverageService::report): the active words of a course by module and in total,
 * how many of them have `words.audio_path` recorded and, after a file check (`--verify`), how many are really voiced
 * (the path is the one the current text hashes to and the file is there and not empty, as WordAudioService::isCurrent).
 * `problems` lists the first words whose recorded audio is broken; `problemCount` is how many there are in all.
 */
final readonly class AudioCoverageReport
{
    /**
     * @param  list<array{number: int, title: string, words: int, with_audio: int, verified: int|null}>  $stages  by module number
     * @param  int|null  $verified  null when the files were not checked
     * @param  list<array{id: int, word: string, reason: 'missing'|'empty'|'stale', path: string}>  $problems  the first ones, by word id
     */
    public function __construct(
        public array $stages,
        public int $words,
        public int $withAudio,
        public ?int $verified,
        public array $problems,
        public int $problemCount,
    ) {}

    /** The voiced words the coverage is read from: checked on the disk when verified, else recorded in the DB. */
    public function covered(): int
    {
        return $this->verified ?? $this->withAudio;
    }

    /** Coverage in percent, one decimal, rounded down (99.96% is not 100%); 0 for a course without active words. */
    public function percent(): float
    {
        return self::share($this->covered(), $this->words);
    }

    /** True when the coverage is at least $min percent; a course without active words never is (unless $min is 0). */
    public function meets(float $min): bool
    {
        if ($this->words === 0) {
            return $min <= 0;
        }

        return $this->covered() * 100 >= $min * $this->words;
    }

    public static function share(int $part, int $total): float
    {
        return $total > 0 ? floor(1000 * $part / $total) / 10 : 0.0;
    }
}
