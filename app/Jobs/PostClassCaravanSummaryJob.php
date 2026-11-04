<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Game\Services\Class\ClassCaravanService;
use App\Domain\Game\Services\Class\ClassCaravanSummary;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Jobs\Middleware\ThrottleTelegramOutbound;
use App\Models\ClassCaravan;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Posts the summary of a finished class caravan to the group chat exactly once (docs/18 §12.11), the way PostLeaderboardJob posts
 * the leaderboard of an exam: the persisted marker `summary_posted_at` is read before, a cache lock serialises the workers, the
 * marker is read again under the lock and written by a conditional UPDATE after the message went out. Queued by the scheduler
 * (`game:class-caravans-close`) after the commit of the close; a second queueing (two schedulers, a retry) posts nothing.
 *
 * A group that is not active any more gets no message: the marker is set with `meta.summary_skipped = group_inactive`.
 */
final class PostClassCaravanSummaryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // Released jobs (no global Telegram slot) use up attempts; real failures stay capped at 3.
    public int $tries = 0;

    public int $maxExceptions = 3;

    public function __construct(public readonly int $classCaravanId) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [new ThrottleTelegramOutbound];
    }

    /** The summary of a week is worth posting late, but not a day later: 6 hours of waiting for a slot / failures at most. */
    public function retryUntil(): DateTimeInterface
    {
        return CarbonImmutable::now()->addHours(6);
    }

    public function handle(
        TelegramClient $telegram,
        ClassCaravanService $caravans,
        ClassCaravanSummary $summary,
        ProductEventRecorder $events,
        LocaleResolver $locales,
    ): void {
        /** @var ClassCaravan|null $caravan */
        $caravan = ClassCaravan::query()->with('group')->find($this->classCaravanId);
        if ($caravan === null || $caravan->status->value !== 'finished' || $caravan->group === null || $caravan->summary_posted_at !== null) {
            return;
        }

        Cache::lock(ClassCaravanService::SUMMARY_LOCK_PREFIX.$caravan->id, 120)->block(10, function () use ($telegram, $caravans, $summary, $events, $locales): void {
            /** @var ClassCaravan|null $fresh */
            $fresh = ClassCaravan::query()->with('group')->find($this->classCaravanId);
            $group = $fresh?->group;
            if ($fresh === null || $group === null || $fresh->summary_posted_at !== null) {
                return;
            }

            $now = CarbonImmutable::now();
            if ($group->status !== 'active') {
                $this->markPosted($fresh, $now, ClassCaravanService::SUMMARY_SKIPPED_GROUP_INACTIVE);

                return;
            }

            $text = $summary->text($fresh, $caravans->standings($fresh), $locales->forGroup($group));
            try {
                $telegram->sendMessage($group->chat_id, $text, parseMode: 'HTML');
            } catch (Throwable $e) {
                Log::channel('daily')->warning('game.class_summary_failed', [
                    'class_caravan_id' => $fresh->id,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }

            if ($this->markPosted($fresh, $now)) {
                $events->record(ProductEventName::GameClassSummaryPosted, ['class_caravan_id' => $fresh->id], group: $group);
            }
        });
    }

    /** The conditional UPDATE of the marker; true when this call set it. */
    private function markPosted(ClassCaravan $caravan, CarbonImmutable $now, ?string $skipped = null): bool
    {
        $values = ['summary_posted_at' => $now, 'updated_at' => $now];
        if ($skipped !== null) {
            $values['meta'] = json_encode([...$caravan->meta, 'summary_skipped' => $skipped], JSON_THROW_ON_ERROR);
        }

        return ClassCaravan::query()->whereKey($caravan->id)->whereNull('summary_posted_at')->update($values) === 1;
    }
}
