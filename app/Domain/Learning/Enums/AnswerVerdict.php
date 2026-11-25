<?php

declare(strict_types=1);

namespace App\Domain\Learning\Enums;

/**
 * What an answer is worth to the word points (docs/32 §3.2), as the answering domain classifies it before the scoring core sees it:
 *
 *   Passed       — an independent, checked answer that is right (a typo of a typed answer included: docs/31 §5, В8);
 *   Failed       — a checked answer that is wrong, a skip, "I don't know";
 *   Assisted     — an answer given after a hint of the element (the game);
 *   Revealed     — the learner's own grade with the translation open (flash on a card that is not new);
 *   Introductory — the first meeting of a new card (flash on a `new` card): no points and the attempt of the day stays open;
 *   NotChecked   — a grade the server could not check (the legacy `quality` of a four-option card).
 *
 * Every verdict but Passed and Introductory CLOSES the attempt of the rating day for the lexeme (docs/28: an error, a hint or a reveal
 * in any mode closes the word until the next suitable day).
 */
enum AnswerVerdict: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case Assisted = 'assisted';
    case Revealed = 'revealed';
    case Introductory = 'introductory';
    case NotChecked = 'not_checked';
}
