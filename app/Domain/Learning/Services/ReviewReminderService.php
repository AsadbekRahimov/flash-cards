<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Learning\Support\DueRepetitionCriteria;
use App\Jobs\SendReviewReminderJob;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Due cards for the reminder threshold are defined by DueRepetitionCriteria
 * (docs/06 §6): graduated (`review`) cards plus (re)learning cards abandoned
 * long enough ago. Fresh minute steps belong to the running session and must
 * not trigger "N words due" messages.
 */
final class ReviewReminderService
{
    public const DEFAULT_THRESHOLD = 5;

    public function __construct(private readonly DueRepetitionCriteria $due) {}

    public function queueDueReminders(int $threshold = self::DEFAULT_THRESHOLD): int
    {
        $queued = 0;
        $now = CarbonImmutable::now();

        // One reminder per person, however many groups they study in. Only learners
        // reachable through at least one active membership of an active group are
        // reminded (personal reminders for everyone else come with docs/12 task 1.5b).
        Learner::query()
            ->whereNull('blocked_at')
            ->whereHas(
                'memberships',
                fn (Builder $q) => $q->where('status', 'active')
                    ->whereHas('group', fn (Builder $g) => $g->where('status', 'active')),
            )
            ->whereHas(
                'repetitions',
                fn (Builder $q) => $this->due->apply($q, $now),
                '>=',
                $threshold,
            )
            ->withCount([
                'repetitions as due_repetitions_count' => fn (Builder $q) => $this->due->apply($q, $now),
            ])
            ->whereDoesntHave('notifications', function ($q) use ($now): void {
                $q->where('type', 'review_reminder')
                    ->where('sent_at', '>=', $now->subHours(24));
            })
            ->chunkById(200, function ($learners) use (&$queued): void {
                foreach ($learners as $learner) {
                    $dueCount = (int) ($learner->due_repetitions_count ?? 0);

                    SendReviewReminderJob::dispatch($learner->id, $dueCount)
                        ->onQueue((string) config('telegram.queue', 'high'));

                    $queued++;
                }
            });

        return $queued;
    }
}
