<?php

declare(strict_types=1);

namespace App\Domain\Learners\DTO;

use Carbon\CarbonImmutable;

/**
 * The placement test handed to the client (docs/40 §2.1a, docs/04 A.27): the questions without their answers - `id` is the
 * position the answers come back by - and when the test runs out. The key stays in `learners.meta.twa_onboarding.placement`.
 *
 * @phpstan-type Question array{id: int, word: string, options: list<string>}
 */
final readonly class PlacementQuestions
{
    /** @param  list<Question>  $questions */
    public function __construct(
        public array $questions,
        public CarbonImmutable $expiresAt,
    ) {}
}
