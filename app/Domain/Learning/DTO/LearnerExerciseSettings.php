<?php

declare(strict_types=1);

namespace App\Domain\Learning\DTO;

use App\Domain\Learning\Enums\ExerciseType;
use App\Models\Learner;

/**
 * Which exercise types the learner has switched on (`learners.meta.exercises`, docs/13 §8.2).
 * Flash cards cannot be switched off. Missing keys = on.
 */
final readonly class LearnerExerciseSettings
{
    public const META_KEY = 'exercises';

    /** @var list<string> the keys of `PATCH /me/settings {exercises}` */
    public const KEYS = ['reverse', 'audio', 'spelling', 'gap_fill'];

    public function __construct(
        public bool $reverse = true,
        public bool $audio = true,
        public bool $spelling = true,
        public bool $gapFill = true,
    ) {}

    public static function fromLearner(Learner $learner): self
    {
        $raw = $learner->meta[self::META_KEY] ?? null;

        return self::fromArray(is_array($raw) ? $raw : []);
    }

    /** @param  array<mixed>  $raw  values other than `false` count as on */
    public static function fromArray(array $raw): self
    {
        return new self(
            reverse: ($raw['reverse'] ?? true) !== false,
            audio: ($raw['audio'] ?? true) !== false,
            spelling: ($raw['spelling'] ?? true) !== false,
            gapFill: ($raw['gap_fill'] ?? true) !== false,
        );
    }

    public function allows(ExerciseType $type): bool
    {
        return match ($type) {
            ExerciseType::Flash => true,
            ExerciseType::Reverse => $this->reverse,
            ExerciseType::Audio => $this->audio,
            ExerciseType::Spelling => $this->spelling,
            ExerciseType::GapFill => $this->gapFill,
        };
    }

    /** @return array{reverse: bool, audio: bool, spelling: bool, gap_fill: bool} */
    public function toArray(): array
    {
        return [
            'reverse' => $this->reverse,
            'audio' => $this->audio,
            'spelling' => $this->spelling,
            'gap_fill' => $this->gapFill,
        ];
    }
}
