<?php

declare(strict_types=1);

namespace App\Domain\Learning\Exceptions;

use DomainException;

/**
 * The review of a lesson cannot be opened (docs/04 A.3): 409 `lesson_review_unavailable` with `details {reason}`.
 *
 *   - `disabled` - `learning.lesson_review.enabled` is off;
 *   - `no_new_words` - the learner met no word of this lesson today (the lesson was not studied today, or only its old words were);
 *   - `no_objective_exercise` - neither "pick the translation" nor "pick the word" is available (the config or the learner's switches).
 *
 * A lesson that does not exist or is not open to the learner is not this exception: it is 404 `lesson_not_found`, as for `mode: lesson`.
 */
final class LessonReviewException extends DomainException
{
    public const CODE = 'lesson_review_unavailable';

    public const REASON_DISABLED = 'disabled';

    public const REASON_NO_NEW_WORDS = 'no_new_words';

    public const REASON_NO_OBJECTIVE_EXERCISE = 'no_objective_exercise';

    public function __construct(public readonly string $reason)
    {
        parent::__construct("The review of the lesson is not available: {$reason}.");
    }

    /** @return array{reason: string} */
    public function details(): array
    {
        return ['reason' => $this->reason];
    }
}
