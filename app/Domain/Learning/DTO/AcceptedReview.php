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
 * For a reverse / audio card (the card was sent without what gives the right option away) it also carries what the serve
 * showed, to be sent back with the verdict: the word, its translation, the example of the serve, whether the serve would show
 * the hooks (decided before the grade moved the card's state) and whether the word was a leech.
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
    ) {}
}
