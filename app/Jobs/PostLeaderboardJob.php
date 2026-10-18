<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Learning\Services\LeaderboardBuilder;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Models\ExamResult;
use App\Models\ExamSession;
use App\Models\Lesson;
use App\Models\Student;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Posts the final leaderboard for a closed exam session to the group chat.
 *
 * Called by the scheduler (`exams:close-expired`) and by the manual
 * `/close_exam` handler. A cache lock and persisted marker prevent repeated
 * or concurrent job deliveries from posting the leaderboard more than once.
 */
final class PostLeaderboardJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $examSessionId) {}

    public function handle(
        TelegramClient $telegram,
        LeaderboardBuilder $builder,
        ProductEventRecorder $events,
    ): void {
        /** @var ExamSession|null $session */
        $session = ExamSession::query()
            ->with(['group', 'lesson'])
            ->find($this->examSessionId);

        if (
            $session === null
            || $session->status !== 'closed'
            || $session->group === null
            || $session->leaderboard_posted_at !== null
        ) {
            return;
        }

        if (! ExamResult::query()->where('exam_session_id', $session->id)->exists()) {
            $builder->build($session);
        }

        /** @var Collection<int, ExamResult> $results */
        $results = ExamResult::query()
            ->with('student')
            ->where('exam_session_id', $session->id)
            ->orderBy('rank')
            ->limit(10)
            ->get();

        $text = $this->format($session, $results);

        Cache::lock("telegram:leaderboard:{$session->id}", 120)->block(10, function () use (
            $telegram,
            $events,
            $session,
            $text,
        ): void {
            /** @var ExamSession|null $fresh */
            $fresh = ExamSession::query()->find($session->id);
            if ($fresh === null || $fresh->leaderboard_posted_at !== null) {
                return;
            }

            try {
                $telegram->sendMessage($session->group->chat_id, $text, parseMode: 'HTML');
                $fresh->update(['leaderboard_posted_at' => now()]);
                $events->record(
                    ProductEventName::ExamLeaderboardPosted,
                    ['exam_session_id' => $session->id],
                    group: $session->group,
                );
            } catch (\Throwable $e) {
                Log::channel('daily')->warning('telegram.leaderboard_post_failed', [
                    'exam_session_id' => $session->id,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        });
    }

    /**
     * @param  Collection<int, ExamResult>  $results
     */
    private function format(ExamSession $session, $results): string
    {
        $lesson = $session->getRelation('lesson');
        $lessonNumber = $lesson instanceof Lesson ? $lesson->number : '?';
        $header = "🏁 <b>Итоги экзамена</b>\nУрок {$lessonNumber}";

        if ($results->isEmpty()) {
            return $header."\n\nНикто не ответил 😅";
        }

        $rows = $results->map(function (ExamResult $r): string {
            $medal = match ($r->rank) {
                1 => '🥇',
                2 => '🥈',
                3 => '🥉',
                default => "{$r->rank}.",
            };
            $student = $r->getRelation('student');
            $name = $this->escapeHtml($student instanceof Student ? ($student->first_name ?? '—') : '—');
            $score = $r->total_score;

            return "{$medal} {$name} — <b>{$score}</b>";
        })->implode("\n");

        return "{$header}\n\n{$rows}";
    }

    private function escapeHtml(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
