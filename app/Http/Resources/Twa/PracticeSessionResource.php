<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Practice\DTO\SessionView;
use App\Domain\Practice\DTO\SummaryView;
use App\Domain\Practice\Support\IsoTime;
use Illuminate\Http\Request;

/**
 * A solo session (docs/16 §7.2): GET /practice/sessions/{id}, the 201 of POST /practice/sessions and the `session` of an answer.
 *
 * A white list. The current element comes from the `project()` of its mode - the ONLY way an element reaches a response -
 * and is cleaned once more of the keys that live in the snapshot only: should a mode ever put one into its projection, it
 * would not leave the server (the contract test lists the same keys).
 *
 * @property SessionView $resource
 */
final class PracticeSessionResource extends TwaResource
{
    /**
     * Keys of the snapshot that a client never gets in `current`: the expected answers, the text of a dictation and its translation,
     * the explanation, and the ids of the database (docs/16 §2.8, §12). Cleaned from the projection of the ELEMENT only: the verdict's
     * `reveal` is another matter - it is shown AFTER the answer (with `translation` and `explanation` in it), and is not cleaned here.
     */
    public const NEVER_SHOWN = [
        'pairs', 'correct_ids', 'accepted', 'accepted_display', 'expected', 'full_text', 'display', 'translation', 'explanation',
        'audio_path', 'audio_disk', 'word_id', 'version_id', 'item_id', 'pool', 'error_id', 'wrong_text',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $view = $this->resource;

        return [
            'id' => $view->id,
            'status' => $view->status,
            'mode' => $view->mode,
            'source' => $view->source,
            'retry_of' => $view->retryOf,
            'course' => $view->course,
            'lesson' => $view->lesson,
            'progress' => ['done' => $view->done, 'total' => $view->total],
            'counters' => ['correct_first' => $view->correctFirst, 'hints' => $view->hints, 'mismatches' => $view->mismatches],
            'expires_at' => IsoTime::format($view->expiresAt),
            'server_now' => IsoTime::format($view->serverNow),
            'current' => $view->current === null ? null : self::clean($view->current),
            'summary' => $view->summary === null ? null : self::summary($view->summary),
            // The clock of a timed game; null for the other modes. `deadline_at` moves closer with every wrong match; the time left is
            // `deadline_at - server_now` (this very response), the client counts it down on its own clock.
            'timed' => $view->timed === null ? null : [
                'seconds' => $view->timed['seconds'],
                'deadline_at' => $view->timed['deadline_at'] === null ? null : IsoTime::format($view->timed['deadline_at']),
                'started' => $view->timed['started'],
                'score' => $view->timed['score'],
                'penalty_ms' => $view->timed['penalty_ms'],
            ],
        ];
    }

    /**
     * @return array{total: int, answered: int, correct_first: int, typos: int, hints: int, mismatches: int|null, duration_ms: int, finish_reason: string, retry_available: bool, mistakes: list<array{element_id: string, kind: string, label: string, your_answer: string|null, verdict: string}>, score?: int, best_score?: int|null, is_record?: bool}
     */
    public static function summary(SummaryView $summary): array
    {
        $body = [
            'total' => $summary->total,
            'answered' => $summary->answered,
            'correct_first' => $summary->correctFirst,
            'typos' => $summary->typos,
            'hints' => $summary->hints,
            'mismatches' => $summary->mismatches,
            'duration_ms' => $summary->durationMs,
            'finish_reason' => $summary->finishReason,
            'retry_available' => $summary->retryAvailable,
            'mistakes' => $summary->mistakes,
        ];
        // Only a timed game has them: the pairs, the best score now, whether this game set it.
        if ($summary->score !== null) {
            $body['score'] = $summary->score;
            $body['best_score'] = $summary->bestScore;
            $body['is_record'] = $summary->isRecord ?? false;
        }

        return $body;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function clean(array $value): array
    {
        $clean = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, self::NEVER_SHOWN, true)) {
                continue;
            }
            $clean[$key] = is_array($item) ? self::clean($item) : $item;
        }

        return $clean;
    }
}
