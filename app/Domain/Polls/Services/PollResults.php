<?php

declare(strict_types=1);

namespace App\Domain\Polls\Services;

use App\Domain\Polls\Enums\PollDeliveryStatus;
use App\Models\Poll;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The totals of a poll (docs/35 §1.5 "Результаты"), test deliveries left out. O3 writes them into `polls.results_snapshot` at
 * closing ({@see PollLauncher::close()}); O7 builds the results page and its breakdowns on top of the same two statements.
 *
 * Two statements whatever the size of the poll:
 *  - the deliveries by status with the answered ones - `(poll_id, learner_id)` (the unique key; `learner_id IS NOT NULL` is the
 *    "not a test" of the CHECK `is_test = (learner_id IS NULL)` and an index condition);
 *  - the votes by option - `(poll_id, poll_option_id)`, the few test deliveries of the poll excluded by a hashed subplan.
 */
final class PollResults
{
    /**
     * @return array{at: string, recipients: int, statuses: array<string, int>, answered: int, options: array<int, int>}
     */
    public function totals(Poll $poll, ?CarbonImmutable $now = null): array
    {
        $statuses = array_fill_keys(array_map(static fn (PollDeliveryStatus $s): string => $s->value, PollDeliveryStatus::cases()), 0);
        $recipients = 0;
        $answered = 0;

        $rows = DB::table('poll_deliveries')
            ->where('poll_id', $poll->id)
            ->whereNotNull('learner_id')
            ->groupBy('status')
            ->selectRaw('status, count(*) as n, count(answered_at) as answered')
            ->get();

        foreach ($rows as $row) {
            $statuses[(string) $row->status] = (int) $row->n;
            $recipients += (int) $row->n;
            $answered += (int) $row->answered;
        }

        $options = [];
        foreach ($poll->options()->pluck('id') as $optionId) {
            $options[(int) $optionId] = 0;
        }

        $votes = DB::table('poll_votes')
            ->where('poll_id', $poll->id)
            ->whereNotIn('poll_delivery_id', DB::table('poll_deliveries')
                ->where('poll_id', $poll->id)
                ->whereNull('learner_id')
                ->select('id'))
            ->groupBy('poll_option_id')
            ->selectRaw('poll_option_id, count(*) as n')
            ->get();

        foreach ($votes as $vote) {
            $options[(int) $vote->poll_option_id] = (int) $vote->n;
        }

        return [
            'at' => ($now ?? CarbonImmutable::now())->utc()->toIso8601String(),
            'recipients' => $recipients,
            'statuses' => $statuses,
            'answered' => $answered,
            'options' => $options,
        ];
    }
}
