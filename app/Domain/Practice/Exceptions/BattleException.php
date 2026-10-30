<?php

declare(strict_types=1);

namespace App\Domain\Practice\Exceptions;

use App\Domain\Practice\DTO\ModeAvailability;
use App\Domain\Practice\Enums\BattleEndReason;
use App\Domain\Practice\Enums\BattlePhase;
use App\Domain\Practice\Enums\BattleStatus;
use App\Domain\Practice\Enums\PracticeMode;
use RuntimeException;

/**
 * Why a battle action was refused (docs/16 §7.6); see {@see PracticeException} for the shape. The codes of the practice
 * that a battle shares (`answer_already_accepted`, `invalid_answer_shape`, `unknown_element_id`, `course_not_found`...) are
 * thrown as PracticeException; `not_friends` / `friendship_not_found` come from the friends domain.
 *
 *   battle_disabled              409  the battle is switched off (blocks `invite` and `accept`)
 *   battle_busy                  409  {battle_id}: the learner is busy with another battle
 *   battle_friend_no_access      409  the friend has no access to the practice
 *   battle_material_unavailable  422  {mode, available, required, reason, course, lesson_id}
 *   battle_not_found             404  not a battle of the learner
 *   battle_not_recipient         403  only the invited side answers an invitation
 *   battle_not_challenger        403  only the inviting side withdraws it
 *   not_friends                  403  the pair is not an accepted friendship (the code of the challenges, docs/16 §7.6)
 *   battle_closed                409  {end_reason}: the battle is over
 *   battle_wrong_state           409  {status}: the action does not fit the status
 *   round_not_open               409  {round, phase}: the round has not started
 *   round_closed                 409  {round, phase}: the round is over for answers
 */
final class BattleException extends RuntimeException
{
    public const DISABLED = 'battle_disabled';

    public const BUSY = 'battle_busy';

    public const FRIEND_NO_ACCESS = 'battle_friend_no_access';

    public const MATERIAL_UNAVAILABLE = 'battle_material_unavailable';

    public const NOT_FOUND = 'battle_not_found';

    public const NOT_RECIPIENT = 'battle_not_recipient';

    public const NOT_CHALLENGER = 'battle_not_challenger';

    public const NOT_FRIENDS = 'not_friends';

    public const CLOSED = 'battle_closed';

    public const WRONG_STATE = 'battle_wrong_state';

    public const ROUND_NOT_OPEN = 'round_not_open';

    public const ROUND_CLOSED = 'round_closed';

    /** @param array<string, mixed> $details */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function disabled(): self
    {
        return new self(self::DISABLED, 'The battle is switched off.');
    }

    public static function busy(?int $battleId): self
    {
        return new self(self::BUSY, 'You are busy with another battle.', ['battle_id' => $battleId]);
    }

    public static function friendNoAccess(): self
    {
        return new self(self::FRIEND_NO_ACCESS, 'Your friend has no access to the practice.');
    }

    public static function materialUnavailable(PracticeMode $mode, ModeAvailability $availability, string $courseSlug, ?int $lessonId): self
    {
        return new self(
            self::MATERIAL_UNAVAILABLE,
            "Not enough material for a battle in the mode {$mode->value}: {$availability->available} of {$availability->required}.",
            ['mode' => $mode->value, ...$availability->toArray(), 'course' => $courseSlug, 'lesson_id' => $lessonId],
        );
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND, 'No such battle.');
    }

    public static function notRecipient(): self
    {
        return new self(self::NOT_RECIPIENT, 'Only the invited friend can answer the invitation.');
    }

    public static function notChallenger(): self
    {
        return new self(self::NOT_CHALLENGER, 'Only the one who invited can withdraw the invitation.');
    }

    public static function notFriends(): self
    {
        return new self(self::NOT_FRIENDS, 'A battle is played with a friend.');
    }

    public static function closed(?BattleEndReason $reason): self
    {
        return new self(self::CLOSED, 'The battle is over.', ['end_reason' => $reason?->value]);
    }

    public static function wrongState(BattleStatus $status): self
    {
        return new self(self::WRONG_STATE, "This does not fit a battle that is {$status->value}.", ['status' => $status->value]);
    }

    public static function roundNotOpen(int $round, ?BattlePhase $phase): self
    {
        return new self(self::ROUND_NOT_OPEN, 'This round has not started.', ['round' => $round, 'phase' => $phase?->value]);
    }

    public static function roundClosed(int $round, ?BattlePhase $phase): self
    {
        return new self(self::ROUND_CLOSED, 'This round no longer takes answers.', ['round' => $round, 'phase' => $phase?->value]);
    }

    public function httpStatus(): int
    {
        return match ($this->errorCode) {
            self::NOT_FOUND => 404,
            self::NOT_RECIPIENT, self::NOT_CHALLENGER, self::NOT_FRIENDS => 403,
            self::MATERIAL_UNAVAILABLE => 422,
            default => 409,
        };
    }
}
