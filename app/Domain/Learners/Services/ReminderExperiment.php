<?php

declare(strict_types=1);

namespace App\Domain\Learners\Services;

use App\Domain\Analytics\Support\ExperimentBucket;
use App\Domain\Learners\DTO\ReminderContent;
use App\Domain\Learners\Enums\ReminderVariant;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The question vs the plain text in the reminder (audit 2026-11-18, «Причина вернуться завтра», row 1; owner decision: 50/50,
 * sticky per learner; config `learning.reminders.quiz.experiment`). Only a reminder that may be a question at all
 * ({@see ReminderQuizBuilder::eligible()}) gets an arm; everything else — and every reminder while the experiment is off or
 * before `starts_at` — gets `null` and is sent exactly as without the experiment. The arm is
 * {@see ExperimentBucket} of the learner id: no column, the same arm on every send. The time and the frequency of the
 * reminders are not touched: the arm only decides whether a question is built.
 */
final class ReminderExperiment
{
    public function __construct(private readonly ReminderQuizBuilder $quizzes) {}

    public function variantFor(Learner $learner, ReminderContent $content, CarbonImmutable $now): ?ReminderVariant
    {
        if (! $this->running($now) || ! $this->quizzes->eligible($content)) {
            return null;
        }

        return ExperimentBucket::in(self::salt(), $learner->id, $this->share())
            ? ReminderVariant::Quiz
            : ReminderVariant::Plain;
    }

    public function running(CarbonImmutable $now): bool
    {
        if (! (bool) config('learning.reminders.quiz.experiment.enabled', false)) {
            return false;
        }

        $startsAt = self::startsAt();
        if ($startsAt === null) {
            // Unset: running since it was enabled. Set but unreadable (a typo): not running — a split must not start by accident.
            return ! self::hasStartsAt();
        }

        return ! $now->isBefore($startsAt);
    }

    private static function hasStartsAt(): bool
    {
        $raw = config('learning.reminders.quiz.experiment.starts_at');

        return is_string($raw) && trim($raw) !== '';
    }

    /** `starts_at` as a UTC instant (a bare date = its 00:00 UTC); null when unset or unreadable. */
    public static function startsAt(): ?CarbonImmutable
    {
        $raw = config('learning.reminders.quiz.experiment.starts_at');
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(trim($raw), 'UTC')->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function share(): int
    {
        return min(100, max(0, (int) config('learning.reminders.quiz.experiment.share', 50)));
    }

    /**
     * The name of the experiment: its `salt`. It goes to the events and the log row next to the arm (`experiment`), so the report
     * tells this experiment's learners from those of an earlier one (a new salt reshuffles everybody: a new experiment).
     */
    public static function salt(): string
    {
        return (string) config('learning.reminders.quiz.experiment.salt', 'reminder-quiz-2026-11');
    }
}
