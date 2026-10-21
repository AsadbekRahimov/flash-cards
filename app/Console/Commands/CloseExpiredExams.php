<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Learning\Services\ExamSessionService;
use App\Domain\Learning\Services\LeaderboardBuilder;
use App\Domain\Learning\Services\MockExamService;
use App\Jobs\PostLeaderboardJob;
use App\Models\ExamSession;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Closes every exam session whose `ends_at` is in the past,
 * builds its leaderboard and queues the group announcement.
 * Abandoned personal mocks (docs/13 §8.4) are finalized by `MockExamService`
 * instead: no leaderboard, no group message.
 *
 * Intended to be scheduled `everyMinute()->withoutOverlapping()`.
 *
 * Safe to run concurrently on the same row — we do the state transition
 * inside a transaction with `lockForUpdate`, so a duplicate run is a no-op.
 */
final class CloseExpiredExams extends Command
{
    /** @var string */
    protected $signature = 'exams:close-expired {--grace=60 : grace period in seconds after ends_at}';

    /** @var string */
    protected $description = 'Close exams whose ends_at has passed and post leaderboards.';

    public function handle(
        ExamSessionService $service,
        LeaderboardBuilder $leaderboard,
        ProductEventRecorder $events,
        MockExamService $mocks,
    ): int {
        $grace = max(0, (int) $this->option('grace'));
        $cutoff = CarbonImmutable::now()->subSeconds($grace);

        /** @var Collection<int, ExamSession> $expiredMocks */
        $expiredMocks = ExamSession::query()
            ->where('status', 'open')
            ->where('mode', ExamSession::MODE_PERSONAL_MOCK)
            ->where('ends_at', '<=', $cutoff)
            ->get();

        foreach ($expiredMocks as $mock) {
            if ($mocks->finalize($mock, 'expired')->status === 'closed') {
                $this->info("Finalized mock #{$mock->id}");
            }
        }

        /** @var Collection<int, ExamSession> $expired */
        $expired = ExamSession::query()
            ->where('status', 'open')
            ->where('mode', ExamSession::MODE_GROUP_EXAM)
            ->where('ends_at', '<=', $cutoff)
            ->get();

        if ($expired->isEmpty()) {
            return self::SUCCESS;
        }

        foreach ($expired as $session) {
            $closed = DB::transaction(function () use ($service, $session): ?ExamSession {
                /** @var ExamSession|null $fresh */
                $fresh = ExamSession::query()
                    ->where('id', $session->id)
                    ->where('status', 'open')
                    ->lockForUpdate()
                    ->first();

                if ($fresh === null) {
                    return null; // concurrently closed by someone else
                }

                return $service->close($fresh);
            });

            if ($closed === null) {
                continue;
            }

            $events->record(
                ProductEventName::ExamClosed,
                ['exam_session_id' => $closed->id, 'source' => 'scheduler'],
                group: $closed->group,
            );

            $leaderboard->build($closed);

            PostLeaderboardJob::dispatch($closed->id)
                ->onQueue((string) config('telegram.queue', 'high'));

            $this->info("Closed exam #{$closed->id}");
        }

        return self::SUCCESS;
    }
}
