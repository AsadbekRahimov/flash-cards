<?php

declare(strict_types=1);

namespace App\Domain\Learners\Services;

use App\Domain\Engagement\Enums\ScorePeriod;
use App\Domain\Engagement\Enums\ScoringMode;
use App\Domain\Engagement\Services\LeagueService;
use App\Domain\Engagement\Services\Scoring\ScoreRules;
use App\Domain\Learners\DTO\ReminderContent;
use App\Domain\Learners\Enums\ReminderKind;
use App\Jobs\SendLearnerReminderJob;
use App\Models\Learner;
use App\Models\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The reminder of the day after a lesson (docs/31 §5б, the deferred part of G): "the words of yesterday's lesson came up for review -
 * a review in time earns points". Not a message of its own: it is the WORDING of the personal reminder of that day
 * ({@see ReminderSender}) - so the common limit "one message about studies a day", the learner's reminder time and switch, the
 * reachability and the retries are the reminder's, and nothing is ever sent twice.
 *
 * Decided in two steps, so that the mass sending reads nothing more per learner:
 *  1. {@see ReminderDispatcher}, per chunk of claimed learners: {@see learnerIds()} - ONE statement for the chunk (none outside
 *     {@see enabled()}): the learners with a word first met in a lesson on their local yesterday that is due now. The answer goes
 *     into the job ({@see SendLearnerReminderJob::$scoreHint});
 *  2. {@see ReminderSender}, when the job runs: {@see applies()} - the points are still on and the reminder of the day is the one about
 *     due words (`Review`) to a learner who has not studied today (`idleDays >= 1`). Otherwise the usual text goes out.
 *
 * On ({@see enabled()}) = `leagues.scoring.mode = live` and the rating day of now counts by v2 in its week or its month: in `off` /
 * `shadow` or before the start of v2 a review earns no points and the hint is never even looked for.
 */
final class ReviewScoreHint
{
    /** The modes of a lesson: the words a learner met in one (`word_repetitions.introduced_mode`). */
    public const LESSON_MODES = [TrainingSession::MODE_LESSON, TrainingSession::MODE_GROUP_LESSON];

    public function __construct(
        private readonly ScoreRules $rules,
        private readonly LearnerClock $clock,
    ) {}

    /** Whether a review in time earns word points today: `live` and the rating day on v2 (its week or its month). Configuration only. */
    public function enabled(CarbonImmutable $now): bool
    {
        if ($this->rules->mode() !== ScoringMode::Live) {
            return false;
        }

        $day = $this->rules->ratingDay($now);

        return $this->rules->versionFor($this->rules->periodStart(ScorePeriod::Week, $day)) >= 2
            || $this->rules->versionFor($this->rules->periodStart(ScorePeriod::Month, $day)) >= 2;
    }

    /**
     * The learners among `$learners` - participants of the rating (not banned, not opted out of the leagues) - with a word first met
     * (and answered) in a lesson on their local yesterday that is due at `$now`: ONE statement whatever the number of learners (their local yesterdays are grouped by their UTC bounds), none outside
     * {@see enabled()} or without a learner with reminders on.
     *
     * Served by `word_repetitions (learner_id, created_at)`: per learner only the rows of one day are read (the new words of that
     * day), not their whole history.
     *
     * @param  iterable<Learner>  $learners
     * @return array<int, true> learner id => true
     */
    public function learnerIds(iterable $learners, CarbonImmutable $now): array
    {
        if (! $this->enabled($now)) {
            return [];
        }

        /** @var array<string, array{start: CarbonImmutable, end: CarbonImmutable, ids: list<int>}> $groups */
        $groups = [];
        foreach ($learners as $learner) {
            if ($learner->reminder_time === null || $learner->reminder_time === '') {
                continue;
            }

            // "A review earns rating points" is true only for a participant of the rating (as `participant` of the month and of
            // "all time"): not banned, not opted out of the leagues. Judged on the loaded chunk - no statement.
            if ($learner->blocked_at !== null || (bool) (($learner->meta ?? [])[LeagueService::OPT_OUT_META] ?? false)) {
                continue;
            }

            $today = $this->clock->dayBoundsUtc($learner, $now);
            $yesterday = $this->clock->localDateBoundsUtc($learner, CarbonImmutable::parse($today->date, 'UTC')->subDay()->toDateString());
            $key = $yesterday->startUtc->getTimestamp().':'.$yesterday->endUtc->getTimestamp();
            $groups[$key] ??= ['start' => $yesterday->startUtc, 'end' => $yesterday->endUtc, 'ids' => []];
            $groups[$key]['ids'][] = (int) $learner->id;
        }

        if ($groups === []) {
            return [];
        }

        $ids = DB::table('word_repetitions')
            ->distinct()
            ->where(function (Builder $query) use ($groups): void {
                foreach ($groups as $group) {
                    $query->orWhere(fn (Builder $day) => $day
                        ->whereIn('learner_id', $group['ids'])
                        ->where('created_at', '>=', $group['start']->utc()->format('Y-m-d H:i:s'))
                        ->where('created_at', '<', $group['end']->utc()->format('Y-m-d H:i:s')));
                }
            })
            ->whereIn('introduced_mode', self::LESSON_MODES)
            ->whereNotNull('last_reviewed_at')
            ->where('next_review_at', '<=', $now->utc()->format('Y-m-d H:i:s'))
            ->pluck('learner_id');

        $found = [];
        foreach ($ids as $id) {
            $found[(int) $id] = true;
        }

        return $found;
    }

    /**
     * Whether the reminder about to go out says it (the second step of the class comment): the dispatcher found the words, the points
     * are still on, the reminder is about due words and the learner has not studied today.
     */
    public function applies(bool $found, ReminderContent $content, CarbonImmutable $now): bool
    {
        return $found
            && $content->kind === ReminderKind::Review
            && $content->idleDays >= 1
            && $this->enabled($now);
    }
}
