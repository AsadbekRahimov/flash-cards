<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Enums;

/**
 * `league_months.status` (docs/13 §7.6): `open` (points move) → `closing` (the barrier: no refresh writes; the closer recomputes
 * everything) → `pending_review` (the candidates are frozen, an administrator decides) → `confirmed` (the places are final).
 */
enum LeagueMonthStatus: string
{
    case Open = 'open';
    case Closing = 'closing';
    case PendingReview = 'pending_review';
    case Confirmed = 'confirmed';
}
