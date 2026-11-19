<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Engagement\Support\LeaguePoints;
use App\Domain\Learners\DTO\DailyProgress;
use App\Domain\Learning\DTO\SessionSummary;
use App\Domain\Practice\DTO\PracticeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * POST /api/twa/training/sessions/{id}/finish: what the learner did in the session plus the goal
 * and streak of the current local day (TodayService::progress), the entry to the practice of the lesson / of the
 * course (`practice_entry`, docs/16 §11.1: `null` when there is none) and `league_points` — what the session brought to
 * the leagues and the monthly rating (docs/13 §7.6): the one formula of {@see LeaguePoints} over the session's own
 * numbers, plus the goal bonus when the daily goal was met during this session. No statement of its own: everything
 * is in hand already (the monthly place is a separate light request, `GET /league/month/me`).
 *
 * @property array{summary: SessionSummary, progress: DailyProgress, practice_entry: PracticeEntry|null, hard_round_offer?: array{available: bool, reason: string|null, words: int, open_session_id: int|null, resets_at: string|null}|null} $resource
 */
final class SessionSummaryResource extends TwaResource
{
    /**
     * @return array{
     *   started_at: string,
     *   reviewed: int, correct: int, new_words: int,
     *   goal: array{target: int, done: int, met: bool},
     *   streak: array{current: int, best: int, met_today: bool, at_risk: bool, freezes: int, frozen_yesterday: bool, held_by: string|null},
     *   practice_entry: array{course: string, lesson_id: int|null}|null,
     *   league_points: int,
     *   stages: array{graduated: int, promoted: int, mastered: int},
     *   hard_round: array{total: int, won: int}|null,
     *   corrected: array{word_id: int, word: string, translation: string, mistaken_at: string, answer: string|null}|null,
     *   hard_round_offer: array{available: bool, reason: string|null, words: int, open_session_id: int|null, resets_at: string|null}|null
     * }
     */
    public function toArray(Request $request): array
    {
        $summary = $this->resource['summary'];
        $progress = $this->resource['progress'];

        return [
            // When the session began, server time (ISO-8601, UTC): what "achieved in this session" is measured from.
            'started_at' => CarbonImmutable::instance($summary->session->started_at)->utc()->toIso8601String(),
            'reviewed' => $summary->reviewed,
            'correct' => $summary->correct,
            'new_words' => $summary->newWords,
            // The growth of the words in this session (docs/06 §13.3): new words that left the minute steps, older words
            // that climbed a stage (new → learning → known → mastered), words that reached the top.
            'stages' => [
                'graduated' => $summary->graduated,
                'promoted' => $summary->promoted,
                'mastered' => $summary->mastered,
            ],
            'goal' => $progress->goal->toArray(),
            'streak' => $progress->streak->toArray(),
            'practice_entry' => $this->resource['practice_entry']?->toArray(),
            'league_points' => LeaguePoints::ofCounts($summary->reviewed, $summary->correct, $summary->newWords, $this->goalMetInSession($summary, $progress)),
            // The round of hard words (docs/06 §14): how many of its set were won; null in every other mode.
            'hard_round' => $summary->hardTotal === null ? null : ['total' => $summary->hardTotal, 'won' => $summary->hardWon ?? 0],
            // One mistake of an earlier session corrected in the round of hard words (docs/06 §14): the word, its translation in the
            // learner's locale, when the mistake was made and what was typed then (spelling / gap-fill only, else null); null in every other mode.
            'corrected' => $summary->corrected?->toArray(),
            // The invitation to that round on the end of a daily round / a lesson: the block of `GET /today`; null after the round itself and while it is off.
            'hard_round_offer' => $this->resource['hard_round_offer'] ?? null,
        ];
    }

    /** The goal was met while this session ran: `goal_met_at` is at or after the session's start. */
    private function goalMetInSession(SessionSummary $summary, DailyProgress $progress): bool
    {
        $metAt = $progress->goal->metAt;
        $startedAt = $summary->session->started_at;

        return $metAt !== null && ! $metAt->isBefore(CarbonImmutable::instance($startedAt));
    }
}
