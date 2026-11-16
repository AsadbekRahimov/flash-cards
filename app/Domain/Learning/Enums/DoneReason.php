<?php

declare(strict_types=1);

namespace App\Domain\Learning\Enums;

/**
 * Why the training screen got `card: null` (docs/12 §3.3, docs/04 A.3 `done_reason`).
 */
enum DoneReason: string
{
    /** Lesson scope: every word of the lesson was served and nothing is due or in a learning step. */
    case LessonComplete = 'lesson_complete';

    /** Daily scope: nothing is due, no new words are available from the course (or no course). */
    case NothingDue = 'nothing_due';

    /** New words were available but the daily new-card budget (Free plan) stopped them; due cards are exhausted. */
    case NewQuotaExhausted = 'new_quota_exhausted';

    /** `twa.training.max_cards_per_session` grades were accepted in this session. */
    case SessionCap = 'session_cap';

    /**
     * Daily scope: the round's portion is done (`learning.sprint.*`, docs/06 §13.2) while more cards would be there for
     * another round — due reviews beyond the round's cap, or unseen words within the day's quota. `can_continue` is true.
     */
    case SprintComplete = 'sprint_complete';
}
