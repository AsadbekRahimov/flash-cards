<?php

declare(strict_types=1);

namespace App\Domain\Cup\Enums;

/**
 * `cup_duels.end_reason` (docs/33 §11.9): why a duel ended the way it did.
 *
 *   declined          the addressee said no
 *   expired           nobody accepted within `cup.duel.challenge_minutes`
 *   cup_over          accepted when the cup had no current day any more (after the end of its last day)
 *   challenger        the challenger withdrew it
 *   cup_closed        the cup was finished / canceled before the acceptance (canceled), or before the result (done, not credited)
 *   left              the challenger is no active participant any more (left the chat) at the acceptance
 *   challenger_limit  a friendly duel: the Free challenger has no battle of the day left at the acceptance (owner's К5)
 *   material          the course of the cup has no material for the mode any more
 *   no_answers        the match closed before anybody answered (void: the slots are free again, docs/33 §5.7)
 *   battle_gone       the match was deleted (a learner was deleted) before the result (void)
 */
enum CupDuelEndReason: string
{
    case Declined = 'declined';
    case Expired = 'expired';
    case CupOver = 'cup_over';
    case Challenger = 'challenger';
    case CupClosed = 'cup_closed';
    case Left = 'left';
    case ChallengerLimit = 'challenger_limit';
    case Material = 'material';
    case NoAnswers = 'no_answers';
    case BattleGone = 'battle_gone';
}
