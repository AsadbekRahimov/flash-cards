<?php

declare(strict_types=1);

namespace App\Domain\Practice\Exceptions;

use App\Domain\Practice\DTO\ModeAvailability;
use App\Domain\Practice\Enums\FinishReason;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Enums\Verdict;
use RuntimeException;

/**
 * Why a practice action was refused (docs/16 §7.6). Same shape as the other domain exceptions: `errorCode` is the
 * stable machine-readable code, `httpStatus()` the status of the TWA answer, `details` the object that goes into
 * `error.details` of the envelope (empty for the codes that have none). A controller answers
 * `ApiErrorResource::respond($e->httpStatus(), $e->errorCode, $e->getMessage(), $e->details)`.
 *
 *   practice_unavailable            403  the practice is off / the learner is not in the pilot (one code: no hint)
 *   practice_pro_required           403  `PRACTICE_PLAN=pro` and the learner is on Free (the TWA opens the paywall `practice`)
 *   practice_mode_disabled          409  {mode}: the mode is switched off - blocks only the CREATION
 *   practice_not_enough_material    422  {mode, available, required, reason, course, lesson_id}
 *   practice_session_active         409  {session_id, mode, course, lesson_id, progress}
 *   practice_session_not_found      404  not the learner's own session (a stranger's id looks the same)
 *   practice_session_closed         410  {finish_reason}
 *   element_not_current             409  {current}: the id of the element to answer now
 *   answer_already_accepted         409  {verdict[, points]}: the element has its final answer
 *   pair_already_matched            409  {left_id}
 *   invalid_answer_shape            422  {expected}: see the EXPECTED_* constants
 *   unknown_element_id              422  an id that is not in the snapshot
 *   replace_not_allowed             409  {reason}: not_dictation | not_current | limit
 *   retry_not_available             409  nothing (enough) to repeat
 *   practice_audio_unavailable      503  the file of the audio is not there
 *   course_not_found                404  / lesson_not_found 404 / lesson_not_in_course 422
 */
final class PracticeException extends RuntimeException
{
    public const UNAVAILABLE = 'practice_unavailable';

    public const PRO_REQUIRED = 'practice_pro_required';

    public const MODE_DISABLED = 'practice_mode_disabled';

    public const NOT_ENOUGH_MATERIAL = 'practice_not_enough_material';

    public const SESSION_ACTIVE = 'practice_session_active';

    public const SESSION_NOT_FOUND = 'practice_session_not_found';

    public const SESSION_CLOSED = 'practice_session_closed';

    public const ELEMENT_NOT_CURRENT = 'element_not_current';

    public const ANSWER_ALREADY_ACCEPTED = 'answer_already_accepted';

    public const PAIR_ALREADY_MATCHED = 'pair_already_matched';

    public const INVALID_ANSWER_SHAPE = 'invalid_answer_shape';

    public const UNKNOWN_ELEMENT_ID = 'unknown_element_id';

    public const REPLACE_NOT_ALLOWED = 'replace_not_allowed';

    public const RETRY_NOT_AVAILABLE = 'retry_not_available';

    public const AUDIO_UNAVAILABLE = 'practice_audio_unavailable';

    public const COURSE_NOT_FOUND = 'course_not_found';

    public const LESSON_NOT_FOUND = 'lesson_not_found';

    public const LESSON_NOT_IN_COURSE = 'lesson_not_in_course';

    /** `details.expected` of `invalid_answer_shape`: the shape the answer should have had. */
    public const EXPECTED_ORDER = 'order_of_all_fragments';

    public const EXPECTED_OPTION = 'option_id';

    public const EXPECTED_LEFT_RIGHT = 'left_right';

    public const EXPECTED_TEXT_OR_SKIP = 'text_or_skip';

    public const EXPECTED_BIJECTION = 'bijection';

    /** `details.reason` of `replace_not_allowed`. */
    public const REPLACE_NOT_DICTATION = 'not_dictation';

    public const REPLACE_NOT_CURRENT = 'not_current';

    public const REPLACE_LIMIT = 'limit';

    /** @param array<string, mixed> $details */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function unavailable(): self
    {
        return new self(self::UNAVAILABLE, 'The practice is not available.');
    }

    public static function proRequired(): self
    {
        return new self(self::PRO_REQUIRED, 'The practice is a Pro feature.');
    }

    public static function modeDisabled(PracticeMode $mode): self
    {
        return new self(self::MODE_DISABLED, "The mode {$mode->value} is switched off.", ['mode' => $mode->value]);
    }

    /** The material is not enough for the mode: `details` carry the numbers and the reason (docs/16 §4.3). */
    public static function notEnoughMaterial(PracticeMode $mode, ModeAvailability $availability, string $courseSlug, ?int $lessonId): self
    {
        return new self(
            self::NOT_ENOUGH_MATERIAL,
            "Not enough material for the mode {$mode->value}: {$availability->available} of {$availability->required}.",
            ['mode' => $mode->value, ...$availability->toArray(), 'course' => $courseSlug, 'lesson_id' => $lessonId],
        );
    }

    /**
     * @param  array<string, mixed>  $details  {session_id, mode, course, lesson_id, progress}
     */
    public static function sessionActive(array $details): self
    {
        return new self(self::SESSION_ACTIVE, 'There is an unfinished practice session.', $details);
    }

    public static function sessionNotFound(): self
    {
        return new self(self::SESSION_NOT_FOUND, 'No such practice session.');
    }

    public static function sessionClosed(?FinishReason $reason): self
    {
        return new self(self::SESSION_CLOSED, 'This practice session is closed.', ['finish_reason' => $reason?->value]);
    }

    public static function elementNotCurrent(?string $currentId): self
    {
        return new self(self::ELEMENT_NOT_CURRENT, 'This is not the element to answer now.', ['current' => $currentId]);
    }

    public static function answerAlreadyAccepted(?Verdict $verdict, ?int $points = null): self
    {
        $details = ['verdict' => $verdict?->value];
        if ($points !== null) {
            $details['points'] = $points;
        }

        return new self(self::ANSWER_ALREADY_ACCEPTED, 'The answer to this element is already accepted.', $details);
    }

    public static function pairAlreadyMatched(string $leftId): self
    {
        return new self(self::PAIR_ALREADY_MATCHED, 'This word is already matched.', ['left_id' => $leftId]);
    }

    /** @param string $expected one of the EXPECTED_* constants */
    public static function invalidAnswerShape(string $expected): self
    {
        return new self(self::INVALID_ANSWER_SHAPE, 'The answer has the wrong shape.', ['expected' => $expected]);
    }

    public static function unknownElementId(): self
    {
        return new self(self::UNKNOWN_ELEMENT_ID, 'The answer names an element, an option or a fragment that is not in the issue.');
    }

    /** @param string $reason one of the REPLACE_* constants */
    public static function replaceNotAllowed(string $reason): self
    {
        return new self(self::REPLACE_NOT_ALLOWED, 'This element cannot be replaced.', ['reason' => $reason]);
    }

    public static function retryNotAvailable(): self
    {
        return new self(self::RETRY_NOT_AVAILABLE, 'There is nothing to repeat in this session.');
    }

    public static function audioUnavailable(): self
    {
        return new self(self::AUDIO_UNAVAILABLE, 'The audio is not available.');
    }

    public static function courseNotFound(): self
    {
        return new self(self::COURSE_NOT_FOUND, 'No such course.');
    }

    public static function lessonNotFound(): self
    {
        return new self(self::LESSON_NOT_FOUND, 'No such lesson.');
    }

    public static function lessonNotInCourse(): self
    {
        return new self(self::LESSON_NOT_IN_COURSE, 'The lesson is not a lesson of this course.');
    }

    public function httpStatus(): int
    {
        return match ($this->errorCode) {
            self::UNAVAILABLE, self::PRO_REQUIRED => 403,
            self::SESSION_NOT_FOUND, self::COURSE_NOT_FOUND, self::LESSON_NOT_FOUND => 404,
            self::SESSION_CLOSED => 410,
            self::NOT_ENOUGH_MATERIAL, self::INVALID_ANSWER_SHAPE, self::UNKNOWN_ELEMENT_ID, self::LESSON_NOT_IN_COURSE => 422,
            self::AUDIO_UNAVAILABLE => 503,
            default => 409,
        };
    }
}
