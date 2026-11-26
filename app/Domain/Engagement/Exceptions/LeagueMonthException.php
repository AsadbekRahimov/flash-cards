<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Exceptions;

use RuntimeException;

/**
 * Why an action of the monthly rating's review was refused (docs/13 §7.6); the Filament page shows `errorCode` as a
 * notification (`admin.league_month.errors.<code>`).
 *
 *   month_not_pending            the action needs a month in `pending_review` (confirm, disqualify, reinstate)
 *   month_not_confirmed          "prize delivered" needs a confirmed month
 *   prizes_locked                the prizes of a confirmed month are history
 *   prizes_invalid               the list of prizes does not fit (places, lengths)
 *   candidate_not_found          no such candidate in THIS month
 *   candidate_not_winner         only a confirmed winner can get a prize
 *   candidate_already_disqualified / candidate_not_disqualified
 *   prize_already_delivered
 *   reason_invalid               a disqualification needs a reason (1..500 characters)
 */
final class LeagueMonthException extends RuntimeException
{
    public const MONTH_NOT_PENDING = 'month_not_pending';

    public const MONTH_NOT_CONFIRMED = 'month_not_confirmed';

    public const PRIZES_LOCKED = 'prizes_locked';

    public const PRIZES_INVALID = 'prizes_invalid';

    public const CANDIDATE_NOT_FOUND = 'candidate_not_found';

    public const CANDIDATE_NOT_WINNER = 'candidate_not_winner';

    public const CANDIDATE_ALREADY_DISQUALIFIED = 'candidate_already_disqualified';

    public const CANDIDATE_NOT_DISQUALIFIED = 'candidate_not_disqualified';

    public const PRIZE_ALREADY_DELIVERED = 'prize_already_delivered';

    public const REASON_INVALID = 'reason_invalid';

    public const TIE_BEYOND_RESERVE = 'tie_beyond_reserve';

    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    /** A shared prize place of a v2 category gives more winners than `places + tie_reserve` (docs/32 §7): the administrator decides. */
    public static function tieBeyondReserve(string $category, int $winners, int $allowed): self
    {
        return new self(self::TIE_BEYOND_RESERVE, "A shared prize place in {$category} gives {$winners} winners, the reserve allows {$allowed}: disqualify, or confirm accepting the overflow.");
    }

    public static function reasonInvalid(int $maxLength): self
    {
        return new self(self::REASON_INVALID, "A disqualification needs a reason of up to {$maxLength} characters.");
    }

    public static function monthNotPending(): self
    {
        return new self(self::MONTH_NOT_PENDING, 'The month is not waiting for a review.');
    }

    public static function monthNotConfirmed(): self
    {
        return new self(self::MONTH_NOT_CONFIRMED, 'The month is not confirmed yet.');
    }

    public static function prizesLocked(): self
    {
        return new self(self::PRIZES_LOCKED, 'The prizes of a confirmed month cannot be changed.');
    }

    public static function prizesInvalid(string $what): self
    {
        return new self(self::PRIZES_INVALID, "Invalid prizes: {$what}.");
    }

    public static function candidateNotFound(): self
    {
        return new self(self::CANDIDATE_NOT_FOUND, 'No such candidate in this month.');
    }

    public static function candidateNotWinner(): self
    {
        return new self(self::CANDIDATE_NOT_WINNER, 'Only a confirmed winner can receive a prize.');
    }

    public static function candidateAlreadyDisqualified(): self
    {
        return new self(self::CANDIDATE_ALREADY_DISQUALIFIED, 'The candidate is already disqualified.');
    }

    public static function candidateNotDisqualified(): self
    {
        return new self(self::CANDIDATE_NOT_DISQUALIFIED, 'The candidate is not disqualified.');
    }

    public static function prizeAlreadyDelivered(): self
    {
        return new self(self::PRIZE_ALREADY_DELIVERED, 'The prize is already marked as delivered.');
    }
}
