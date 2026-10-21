<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Exceptions;

use RuntimeException;

/**
 * Why a friendship action was refused (docs/13 §7.5). Same shape as the other domain exceptions:
 * `errorCode` is the stable machine-readable code, `httpStatus()` the recommended TWA status.
 *
 *   friendship_self        422  a learner cannot befriend themselves
 *   friend_unavailable     404  the other learner is blocked or gone
 *   friendship_not_found   404  no such row among THIS learner's own pairs (a stranger's id looks the same)
 *   not_recipient          403  only the side that was asked can accept / decline
 *   friend_limit           409  the learner has `friends.max` friends / open requests
 *   friend_limit_other     409  the other side has `friends.max` friends
 *   friend_blocked         409  the pair is blocked (by the acting learner: unblock first)
 */
final class FriendshipException extends RuntimeException
{
    public const SELF = 'friendship_self';

    public const UNAVAILABLE = 'friend_unavailable';

    public const NOT_FOUND = 'friendship_not_found';

    public const NOT_RECIPIENT = 'not_recipient';

    public const LIMIT = 'friend_limit';

    public const LIMIT_OTHER = 'friend_limit_other';

    public const BLOCKED = 'friend_blocked';

    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function self(): self
    {
        return new self(self::SELF, 'A learner cannot be their own friend.');
    }

    public static function unavailable(): self
    {
        return new self(self::UNAVAILABLE, 'This learner is not available.');
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND, 'No such friendship.');
    }

    public static function notRecipient(): self
    {
        return new self(self::NOT_RECIPIENT, 'Only the side that was asked can answer a friend request.');
    }

    public static function limit(): self
    {
        return new self(self::LIMIT, 'The friend limit is reached.');
    }

    public static function limitOther(): self
    {
        return new self(self::LIMIT_OTHER, 'The other learner has reached the friend limit.');
    }

    public static function blocked(): self
    {
        return new self(self::BLOCKED, 'This learner is blocked.');
    }

    public function httpStatus(): int
    {
        return match ($this->errorCode) {
            self::SELF => 422,
            self::UNAVAILABLE, self::NOT_FOUND => 404,
            self::NOT_RECIPIENT => 403,
            default => 409,
        };
    }
}
