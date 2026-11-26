<?php

declare(strict_types=1);

namespace App\Domain\Learning\Enums;

/**
 * Why an answer earned what it earned (docs/32 §3.1, §8.1 `score.reason`):
 *
 *   credited      — a credit was written (`score_credits`) and added points in at least one count (or every count of the day still
 *                   counts by the v1 rules: `shadow`, or `live` before the v2 start - recorded, nothing shown);
 *   capped        — a credit was written but the limits of the day (the slots of the category, the day cap) left 0 in every v2 count;
 *   already_today — the lexeme had its attempt of this rating day already (nothing is written);
 *   closed        — the answer was not a passed independent check: it closes the attempt of the day;
 *   early         — the scheduled due of the card has not come yet (a card pulled to now by the game or the round of hard words, a learn-ahead
 *                   card, a card studied earlier the same rating day);
 *   same_cycle    — the repetition of this scheduled due was paid for already (by the game, by a copy in another course);
 *   not_due       — the card is in its minute steps;
 *   introductory  — the first meeting of a new card: nothing written, the attempt stays open;
 *   no_catalog    — the word has no lexeme (a deck word the catalog does not know): no public points;
 *   off           — the word points are off (`leagues.scoring.mode = off`, no valid version, the game switched off).
 */
enum WordScoreReason: string
{
    case Credited = 'credited';
    case Capped = 'capped';
    case AlreadyToday = 'already_today';
    case Closed = 'closed';
    case Early = 'early';
    case SameCycle = 'same_cycle';
    case NotDue = 'not_due';
    case Introductory = 'introductory';
    case NoCatalog = 'no_catalog';
    case Off = 'off';
}
