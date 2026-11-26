<?php

declare(strict_types=1);

namespace App\Domain\Learning\DTO;

use App\Domain\Content\DTO\ResolvedTranslation;
use App\Domain\Learning\Enums\ExerciseType;
use App\Domain\Learning\Services\ReviewSubmissionService;
use App\Models\Word;
use App\Models\WordRepetition;

/**
 * What {@see ReviewSubmissionService} applied: the updated card, the
 * exercise the grade was recorded under and — for a typed answer — the server's verdict.
 *
 * For a meaning / reverse / audio card (the card was sent without what gives the right option away) it also carries what the serve
 * showed, to be sent back with the verdict: the word, its translation, the example of the serve (and its translation), whether the serve would show
 * the hooks (decided before the grade moved the card's state) and whether the word was a leech.
 *
 * `score` — the word points of the answer (docs/32 §3.1); null only where no answer was recorded at all (never from the service itself:
 * with the points off it carries the reason `off`).
 */
final readonly class AcceptedReview
{
    public function __construct(
        public WordRepetition $repetition,
        public ExerciseType $exercise,
        public ?SpellingVerdict $verdict = null,
        public ?Word $word = null,
        public ?ResolvedTranslation $translation = null,
        public ?string $example = null,
        public bool $showedHook = false,
        public bool $wasLeech = false,
        public ?string $exampleTranslation = null,
        public ?WordScoreOutcome $score = null,
    ) {}
}
