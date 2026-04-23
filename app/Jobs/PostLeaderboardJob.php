<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Learning\Services\LeaderboardBuilder;
use App\Domain\Telegram\Services\TelegramApi;
use App\Models\ExamResult;
use App\Models\ExamSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Posts the final leaderboard for a closed exam session to the group chat.
 *
 * Called by the scheduler (`exams:close-expired`) and by the manual
 * `/close_exam` handler. Idempotent: if results already exist they are
 * rebuilt only when empty; posting itself is a single Telegram message.
 */
final class PostLeaderboardJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $examSessionId) {}

    public function handle(TelegramApi $api, LeaderboardBuilder $builder): void
    {
        /** @var ExamSession|null $session */
        $session = ExamSession::query()
            ->with(['group', 'lesson'])
            ->find($this->examSessionId);

        if ($session === null || $session->group === null) {
            return;
        }

        if (! ExamResult::query()->where('exam_session_id', $session->id)->exists()) {
            $builder->build($session);
        }

        /** @var \Illuminate\Support\Collection<int, ExamResult> $results */
        $results = ExamResult::query()
            ->with('student')
            ->where('exam_session_id', $session->id)
            ->orderBy('rank')
            ->limit(10)
            ->get();

        $text = $this->format($session, $results);

        try {
            $api->sendMessage($session->group->chat_id, $text, parseMode: 'HTML');
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('telegram.leaderboard_post_failed', [
                'exam_session_id' => $session->id,
                'error'           => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ExamResult>  $results
     */
    private function format(ExamSession $session, $results): string
    {
        $lessonNumber = $session->lesson?->number ?? '?';
        $header       = "🏁 <b>Итоги экзамена</b>\nУрок {$lessonNumber}";

        if ($results->isEmpty()) {
            return $header . "\n\nНикто не ответил 😅";
        }

        $rows = $results->map(function (ExamResult $r): string {
            $medal = match ($r->rank) {
                1       => '🥇',
                2       => '🥈',
                3       => '🥉',
                default => "{$r->rank}.",
            };
            $name  = $this->escapeHtml($r->student?->first_name ?? '—');
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
