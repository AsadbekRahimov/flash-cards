<?php

declare(strict_types=1);

namespace App\Domain\Learners\Support;

use App\Models\Learner;
use Illuminate\Support\Facades\DB;

/**
 * The lock of a learner's own row - the FIRST lock of every transaction that serialises the learner's own actions (the picker of
 * the cards, docs/12 §5.3; the Free limits of the practice, docs/16 §9 rule 16; the Free limits and the tickets of the game,
 * docs/18 §12.5.1 rule 18). `SELECT ... FOR NO KEY UPDATE` on PostgreSQL: it serialises the learner's transactions without blocking
 * the `FOR KEY SHARE` of the rows that merely reference the learner (an insert with a foreign key to `learners` does not wait);
 * `FOR UPDATE` elsewhere (SQLite ignores it: one connection, the whole database is the lock).
 */
final class LearnerRowLock
{
    /** Takes the lock inside the caller's transaction; the row must exist (a missing learner is the caller's bug, nothing is locked). */
    public static function take(int $learnerId): void
    {
        $lock = Learner::query()->whereKey($learnerId);
        (DB::connection()->getDriverName() === 'pgsql' ? $lock->lock('for no key update') : $lock->lockForUpdate())->value('id');
    }
}
