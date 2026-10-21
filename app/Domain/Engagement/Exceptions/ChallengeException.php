<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Exceptions;

use RuntimeException;

/**
 * Why a challenge action was refused (docs/13 §7.5); see {@see FriendshipException} for the shape.
 *
 *   challenge_not_found    404  no such challenge among THIS learner's own
 *   not_friends            403  a challenge goes only to an accepted friend
 *   challenge_exists       409  the pair already has an open (pending / active) challenge
 *   challenge_invalid      422  unknown metric / length
 *   not_opponent           403  only the challenged side answers
 *   challenge_closed       409  the challenge is no longer open (declined, expired, finished, canceled)
 */
final class ChallengeException extends RuntimeException
{
    public const NOT_FOUND = 'challenge_not_found';

    public const NOT_FRIENDS = 'not_friends';

    public const EXISTS = 'challenge_exists';

    public const INVALID = 'challenge_invalid';

    public const NOT_OPPONENT = 'not_opponent';

    public const CLOSED = 'challenge_closed';

    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND, 'No such challenge.');
    }

    public static function notFriends(): self
    {
        return new self(self::NOT_FRIENDS, 'You can challenge only a friend.');
    }

    public static function exists(): self
    {
        return new self(self::EXISTS, 'There is already an open challenge between you.');
    }

    public static function invalid(string $what): self
    {
        return new self(self::INVALID, "Invalid challenge {$what}.");
    }

    public static function notOpponent(): self
    {
        return new self(self::NOT_OPPONENT, 'Only the challenged friend can answer.');
    }

    public static function closed(): self
    {
        return new self(self::CLOSED, 'The challenge is no longer open.');
    }

    public function httpStatus(): int
    {
        return match ($this->errorCode) {
            self::NOT_FOUND => 404,
            self::NOT_FRIENDS, self::NOT_OPPONENT => 403,
            self::INVALID => 422,
            default => 409,
        };
    }
}
