<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Organizations\DTO\LearnerAssignment;
use App\Domain\Organizations\Exceptions\AssignmentException;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Jobs\AnnounceAssignmentJob;
use App\Models\Assignment;
use App\Models\AssignmentResult;
use App\Models\Course;
use App\Models\GroupMember;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Organization;
use App\Models\Stage;
use App\Models\TelegramGroup;
use App\Models\User;
use App\Models\Word;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Assignments of a center (docs/13 §1.4, §2.3 "Реализация 2B.4"): set a lesson of the group's
 * course for a group, tell the group once, remind once, and keep a progress snapshot per learner.
 *
 *  - Progress = learner's `word_repetitions.repetitions >= 1` over the ACTIVE words of the lesson;
 *    `completed_at` is the moment the threshold was first reached, set once by a conditional
 *    UPDATE (a repeated or parallel refresh changes nothing and raises no second event).
 *  - Nothing here holds a lock across a Telegram call: the one-time posts (`announced_at`,
 *    `reminded_at`) are CLAIMED by a conditional UPDATE first and sent afterwards; a definite
 *    Telegram refusal releases the claim (retried by `assignments:refresh`, a few times), an
 *    ambiguous failure keeps it — a missed post is better than a double one.
 *  - Lock order (docs/13 §3) is not involved: no row of the billing chain is touched.
 */
final class AssignmentService
{
    public const TWA_PATH = '/twa/?from=assignment&lesson=';

    private const CHUNK = 500;

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly ProductEventRecorder $events,
        private readonly LocaleResolver $locales,
    ) {}

    /**
     * Sets a lesson for a group. The lesson must belong to the group's course and the group to the
     * organization; the announcement is queued after the commit.
     *
     * @throws AssignmentException
     */
    public function create(
        Organization $organization,
        TelegramGroup $group,
        Lesson $lesson,
        CarbonInterface $dueAt,
        ?string $title = null,
        int $threshold = 100,
        ?User $by = null,
        ?CarbonImmutable $now = null,
    ): Assignment {
        $now ??= CarbonImmutable::now();

        if (! $organization->isActive()) {
            throw AssignmentException::organizationSuspended($organization->id);
        }

        return DB::transaction(function () use ($organization, $group, $lesson, $dueAt, $title, $threshold, $by, $now): Assignment {
            // The group row is locked: a parallel unlink (TelegramGroupObserver) either commits before
            // this check (and the group is no longer the center's) or waits until the assignment exists
            // (and then closes it).
            $group = TelegramGroup::query()->withoutGlobalScopes()->lockForUpdate()->find($group->id)
                ?? throw AssignmentException::foreignGroup($group->id, $organization->id);

            if ($group->organization_id !== $organization->id) {
                throw AssignmentException::foreignGroup($group->id, $organization->id);
            }

            if ($group->status !== 'active') {
                throw AssignmentException::groupInactive($group->id);
            }

            if ($group->course_id === null) {
                throw AssignmentException::groupWithoutCourse($group->id);
            }

            if ((int) Stage::query()->whereKey($lesson->stage_id)->value('course_id') !== $group->course_id) {
                throw AssignmentException::lessonNotInCourse($lesson->id, $group->id);
            }

            // Private is fine (learners of the group open the assigned lesson, TrainingSessionService); a draft is not.
            if (Course::query()->whereKey($group->course_id)->value('status') !== 'published') {
                throw AssignmentException::lessonNotAvailable($lesson->id);
            }

            if ($dueAt->lte($now)) {
                throw AssignmentException::invalidDue();
            }

            if ($threshold < 1 || $threshold > 100) {
                throw AssignmentException::invalidThreshold($threshold);
            }

            $title = $title === null ? '' : trim($title);
            $title = $title !== '' ? $title : ($lesson->title !== null ? trim($lesson->title) : '');

            $assignment = Assignment::query()->create([
                'organization_id' => $organization->id,
                'telegram_group_id' => $group->id,
                'lesson_id' => $lesson->id,
                'title' => $title !== '' ? Str::limit($title, 120, '') : null,
                'due_at' => CarbonImmutable::instance($dueAt),
                'status' => Assignment::STATUS_ACTIVE,
                'completion_threshold' => $threshold,
                'created_by_user_id' => $by?->id,
                'meta' => [],
            ]);

            $this->events->record(
                ProductEventName::AssignmentCreated,
                ['assignment_id' => $assignment->id, 'lesson_id' => $lesson->id, 'organization_id' => $organization->id],
                group: $group,
                user: $by,
            );

            AnnounceAssignmentJob::dispatch($assignment->id)->afterCommit();

            return $assignment;
        });
    }

    /**
     * Closes an assignment: one last snapshot, then `status = closed` (a conditional UPDATE, so a
     * second call or a parallel one is a no-op). The snapshot stays as the final report.
     *
     * @return bool whether THIS call closed it
     */
    public function close(Assignment $assignment, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        if ($assignment->status !== Assignment::STATUS_ACTIVE) {
            return false;
        }

        $this->refreshResults($assignment, $now);

        $closed = Assignment::query()
            ->whereKey($assignment->id)
            ->where('status', Assignment::STATUS_ACTIVE)
            ->update(['status' => Assignment::STATUS_CLOSED, 'updated_at' => $now]) === 1;

        $assignment->refresh();

        return $closed;
    }

    /**
     * Active assignments whose due date passed more than the grace period ago are closed
     * (`organizations.assignments.overdue_grace_days`): late completions are tracked until then.
     *
     * @return int how many were closed
     */
    public function closeExpired(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $limit = $now->subDays((int) config('organizations.assignments.overdue_grace_days', 14));
        $closed = 0;

        Assignment::query()
            ->where('status', Assignment::STATUS_ACTIVE)
            ->where('due_at', '<', $limit)
            ->orderBy('id')
            ->each(function (Assignment $assignment) use ($now, &$closed): void {
                if ($this->close($assignment, $now)) {
                    $closed++;
                }
            });

        return $closed;
    }

    /**
     * The one-time post to the group chat with a web_app button into the lesson. Returns false when
     * it was already announced (or is closed), or when Telegram refused.
     */
    public function announce(Assignment $assignment, ?CarbonImmutable $now = null): bool
    {
        return $this->post($assignment, 'announced_at', 'announce', $now ?? CarbonImmutable::now());
    }

    /**
     * Retries announcements that failed (Telegram refused): active, not announced, fewer failures
     * than `announce_attempts`.
     *
     * @return int how many were posted
     */
    public function announcePending(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $posted = 0;

        Assignment::query()
            ->where('status', Assignment::STATUS_ACTIVE)
            ->ofActiveOrganization()
            ->inLinkedGroup()
            ->whereNull('announced_at')
            ->where('due_at', '>', $now)
            ->orderBy('id')
            ->each(function (Assignment $assignment) use ($now, &$posted): void {
                if ($this->failures($assignment, 'announced_at') < $this->maxAttempts() && $this->announce($assignment, $now)) {
                    $posted++;
                }
            });

        return $posted;
    }

    /**
     * The reminder for assignments due within `remind_before_hours`: one message per assignment to
     * the group chat (never a DM to each learner), claimed by `reminded_at`. An assignment announced
     * less than that long before its deadline is marked reminded without a second message — the
     * announcement already said the deadline.
     *
     * @return int how many reminders were posted
     */
    public function remindDue(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $posted = 0;

        Assignment::query()
            ->where('status', Assignment::STATUS_ACTIVE)
            ->ofActiveOrganization()
            ->inLinkedGroup()
            ->whereNull('reminded_at')
            ->where('due_at', '>', $now)
            ->where('due_at', '<=', $now->addHours($this->remindBeforeHours()))
            ->orderBy('id')
            ->each(function (Assignment $assignment) use ($now, &$posted): void {
                if ($this->failures($assignment, 'reminded_at') >= $this->maxAttempts()) {
                    return;
                }

                $tooSoon = $assignment->announced_at !== null
                    && $assignment->announced_at->gt($assignment->due_at->subHours($this->remindBeforeHours()));

                if ($tooSoon) {
                    Assignment::query()->whereKey($assignment->id)->whereNull('reminded_at')->update(['reminded_at' => $now]);

                    return;
                }

                if ($this->post($assignment, 'reminded_at', 'remind', $now)) {
                    $posted++;
                }
            });

        return $posted;
    }

    /**
     * Recomputes the snapshot of every ACTIVE learner of the assignment's group.
     *
     * @return int learners that reached the threshold during this call
     */
    public function refreshResults(Assignment $assignment, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $completed = 0;

        // The group left the center (unlinked / moved): its members are not this center's to measure.
        if (! TelegramGroup::query()->withoutGlobalScopes()->whereKey($assignment->telegram_group_id)->where('organization_id', $assignment->organization_id)->exists()) {
            return 0;
        }

        GroupMember::query()
            ->where('telegram_group_id', $assignment->telegram_group_id)
            ->where('status', 'active')
            ->whereIn('learner_id', Learner::query()->select('id'))
            ->orderBy('id')
            ->pluck('learner_id')
            ->map(static fn ($id): int => (int) $id)
            ->chunk(self::CHUNK)
            ->each(function ($chunk) use ($assignment, $now, &$completed): void {
                $completed += $this->compute($assignment, array_values($chunk->all()), $now);
            });

        return $completed;
    }

    /**
     * Recomputes the snapshot when the newest row is older than `stale_after_minutes` (or there are
     * none): the report opens fresh without a recount on every page view.
     *
     * @return bool whether a recount happened
     */
    public function refreshIfStale(Assignment $assignment, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        if (! $assignment->isActive()) {
            return false;
        }

        $newest = AssignmentResult::query()->where('assignment_id', $assignment->id)->max('computed_at');

        if ($newest !== null && CarbonImmutable::parse((string) $newest)->gt($now->subMinutes((int) config('organizations.assignments.stale_after_minutes', 10)))) {
            return false;
        }

        $this->refreshResults($assignment, $now);

        return true;
    }

    /**
     * `assignments:refresh`: recounts every active assignment.
     *
     * @return int assignments processed
     */
    public function refreshActive(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $processed = 0;

        Assignment::query()
            ->where('status', Assignment::STATUS_ACTIVE)
            ->ofActiveOrganization()
            ->inLinkedGroup()
            ->orderBy('id')
            ->each(function (Assignment $assignment) use ($now, &$processed): void {
                try {
                    $this->refreshResults($assignment, $now);
                    $processed++;
                } catch (Throwable $e) {
                    // One broken assignment must not stop the others.
                    Log::warning('assignments.refresh_failed', ['assignment_id' => $assignment->id, 'error' => Str::limit($e->getMessage(), 200)]);
                }
            });

        return $processed;
    }

    /**
     * Active assignments of the groups the learner is active in (organization active), with the
     * learner's OWN progress recounted now — "Today" must not lag 30 minutes behind a session.
     *
     * @return list<LearnerAssignment> due soonest first
     */
    public function forLearner(Learner $learner, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        $assignments = Assignment::query()
            ->with(['lesson.stage', 'group'])
            ->where('status', Assignment::STATUS_ACTIVE)
            ->ofActiveOrganization()
            ->inLinkedGroup()
            ->whereIn('telegram_group_id', GroupMember::query()
                ->select('telegram_group_id')
                ->where('learner_id', $learner->id)
                ->where('status', 'active'))
            ->orderBy('due_at')
            ->orderBy('id')
            ->limit((int) config('organizations.assignments.twa_limit', 20))
            ->get();

        // Only snapshots older than `stale_after_minutes` are recounted (a read path must not write
        // on every call), and the lessons' words are loaded once for all of them.
        // A snapshot is also stale once the learner reviewed something after it was taken.
        $threshold = $now->subMinutes((int) config('organizations.assignments.stale_after_minutes', 10));
        $lastActivity = WordRepetition::query()->where('learner_id', $learner->id)->max('updated_at');
        $lastActivity = $lastActivity === null ? null : CarbonImmutable::parse((string) $lastActivity);

        $fresh = AssignmentResult::query()
            ->where('learner_id', $learner->id)
            ->whereIn('assignment_id', $assignments->modelKeys())
            ->where('computed_at', '>', $threshold)
            ->when($lastActivity !== null, static fn ($query) => $query->where('computed_at', '>=', $lastActivity))
            ->pluck('assignment_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $stale = $assignments->reject(static fn (Assignment $a): bool => in_array($a->id, $fresh, true));

        if ($stale->isNotEmpty()) {
            /** @var array<int, list<int>> $wordsByLesson */
            $wordsByLesson = [];

            foreach (Word::query()->whereIn('lesson_id', $stale->pluck('lesson_id')->unique()->all())->where('is_active', true)->get(['id', 'lesson_id']) as $word) {
                $wordsByLesson[(int) $word->lesson_id][] = (int) $word->id;
            }

            foreach ($stale as $assignment) {
                $this->compute($assignment, [$learner->id], $now, $wordsByLesson[$assignment->lesson_id] ?? []);
            }
        }

        /** @var Collection<int, AssignmentResult> $results */
        $results = AssignmentResult::query()
            ->where('learner_id', $learner->id)
            ->whereIn('assignment_id', $assignments->modelKeys())
            ->get()
            ->keyBy('assignment_id');

        return array_values($assignments
            ->map(static fn (Assignment $assignment): LearnerAssignment => new LearnerAssignment(
                $assignment,
                $results->get($assignment->id)->progress_pct ?? 0,
                $results->get($assignment->id)?->completed_at,
            ))
            ->all());
    }

    /**
     * Progress of the given learners (all active members of the group) on one assignment.
     *
     * @param  list<int>  $learnerIds
     * @param  list<int>|null  $wordIds  active words of the lesson when the caller already has them
     * @return int learners that reached the threshold for the first time in this call
     */
    private function compute(Assignment $assignment, array $learnerIds, CarbonImmutable $now, ?array $wordIds = null): int
    {
        if ($learnerIds === []) {
            return 0;
        }

        if ($wordIds === null) {
            /** @var list<int> $wordIds */
            $wordIds = Word::query()
                ->where('lesson_id', $assignment->lesson_id)
                ->where('is_active', true)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->values()
                ->all();
        }

        $total = count($wordIds);
        $needed = $total === 0 ? 0 : max(1, (int) ceil($total * $assignment->completion_threshold / 100));

        /** @var array<int, int> $repeated */
        $repeated = $total === 0 ? [] : WordRepetition::query()
            ->selectRaw('learner_id, count(*) as repeated')
            ->whereIn('word_id', $wordIds)
            ->whereIn('learner_id', $learnerIds)
            ->where('repetitions', '>=', 1)
            ->groupBy('learner_id')
            ->pluck('repeated', 'learner_id')
            ->map(static fn ($count): int => (int) $count)
            ->all();

        $existing = AssignmentResult::query()
            ->where('assignment_id', $assignment->id)
            ->whereIn('learner_id', $learnerIds)
            ->get()
            ->keyBy('learner_id');

        $completed = 0;
        $untouched = [];

        foreach ($learnerIds as $learnerId) {
            $count = $repeated[$learnerId] ?? 0;
            $pct = $total === 0 ? 0 : min(100, intdiv($count * 100, $total));
            $reached = $needed > 0 && $count >= $needed;
            $row = $existing->get($learnerId);

            if ($row === null) {
                $moment = $reached ? $this->completionMoment($assignment, $learnerId, $wordIds, $needed, $now) : null;
                $inserted = AssignmentResult::query()->insertOrIgnore([
                    'assignment_id' => $assignment->id,
                    'learner_id' => $learnerId,
                    'progress_pct' => $pct,
                    'completed_at' => $moment,
                    'computed_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                if ($inserted === 1) {
                    if ($moment !== null) {
                        $completed++;
                        $this->recordCompleted($assignment, $learnerId, $moment);
                    }

                    continue;
                }

                // A parallel refresh inserted the row between our read and the insert.
                $row = AssignmentResult::query()->where('assignment_id', $assignment->id)->where('learner_id', $learnerId)->first();

                if ($row === null) {
                    continue;
                }
            }

            if ($row->progress_pct !== $pct) {
                $row->forceFill(['progress_pct' => $pct, 'computed_at' => $now])->save();
            } else {
                $untouched[] = $row->id;
            }

            if ($reached && $row->completed_at === null) {
                $moment = $this->completionMoment($assignment, $learnerId, $wordIds, $needed, $now);
                $claimed = AssignmentResult::query()
                    ->whereKey($row->id)
                    ->whereNull('completed_at')
                    ->update(['completed_at' => $moment, 'updated_at' => $now]);

                if ($claimed === 1) {
                    $completed++;
                    $this->recordCompleted($assignment, $learnerId, $moment);
                }
            }
        }

        if ($untouched !== []) {
            AssignmentResult::query()->whereKey($untouched)->update(['computed_at' => $now]);
        }

        return $completed;
    }

    /**
     * When the threshold was first reached: the `needed`-th earliest last review among the
     * learner's repeated words of the lesson, kept between the creation of the assignment and now.
     * (`word_repetitions` keeps only the LAST review time; this is the closest honest estimate and
     * never later than the refresh itself.)
     *
     * @param  list<int>  $wordIds
     */
    private function completionMoment(Assignment $assignment, int $learnerId, array $wordIds, int $needed, CarbonImmutable $now): CarbonImmutable
    {
        $times = WordRepetition::query()
            ->where('learner_id', $learnerId)
            ->whereIn('word_id', $wordIds)
            ->where('repetitions', '>=', 1)
            ->get(['last_reviewed_at', 'updated_at'])
            ->map(static fn (WordRepetition $r): int => ($r->last_reviewed_at ?? $r->updated_at ?? $now)->getTimestamp())
            ->sort()
            ->values();

        $at = $times->get($needed - 1);
        $moment = $at === null ? $now : CarbonImmutable::createFromTimestamp($at);
        $created = CarbonImmutable::instance($assignment->created_at ?? $now);

        return $moment->lt($created) ? $created : ($moment->gt($now) ? $now : $moment);
    }

    private function recordCompleted(Assignment $assignment, int $learnerId, CarbonImmutable $moment): void
    {
        $learner = Learner::query()->find($learnerId);

        $this->events->record(
            ProductEventName::AssignmentCompleted,
            ['assignment_id' => $assignment->id, 'late' => $moment->gt($assignment->due_at)],
            learner: $learner,
            group: $assignment->group,
        );
    }

    /**
     * Claims a one-time post by a conditional UPDATE of `$column`, then sends it. A definite
     * Telegram refusal (API error / 403) releases the claim and counts a failure in `meta`; any
     * transport failure keeps the claim (it may have been delivered), flags `meta.{kind}_uncertain`
     * and returns false — it never propagates, so one chat cannot stop the whole run.
     *
     * @param  'announced_at'|'reminded_at'  $column
     * @param  'announce'|'remind'  $kind
     */
    private function post(Assignment $assignment, string $column, string $kind, CarbonImmutable $now): bool
    {
        $claimed = Assignment::query()
            ->whereKey($assignment->id)
            ->where('status', Assignment::STATUS_ACTIVE)
            ->ofActiveOrganization()
            ->inLinkedGroup()
            ->whereNull($column)
            ->update([$column => $now, 'updated_at' => $now]);

        if ($claimed !== 1) {
            return false;
        }

        /** @var Assignment $fresh */
        $fresh = Assignment::query()->with(['organization', 'group', 'lesson.stage'])->findOrFail($assignment->id);
        $locale = $this->locales->forGroup($fresh->group);
        $due = $fresh->due_at->toImmutable()->setTimezone($fresh->organization->timezone);
        $stage = $fresh->lesson->stage;

        $text = __('assignments.'.$kind, [
            'title' => $fresh->displayTitle($locale),
            'stage' => $stage->number ?? 0, // stage_id is NOT NULL; the fallback only satisfies the nullable relation type
            'lesson' => $fresh->lesson->number,
            'due' => $due->format('d.m.Y H:i'),
        ], $locale);

        try {
            $this->telegram->sendWebAppButton(
                chatId: $fresh->group->chat_id,
                text: $text,
                buttonText: __('assignments.open_button', [], $locale),
                url: rtrim((string) config('twa.base_url'), '/').self::TWA_PATH.$fresh->lesson_id,
            );
        } catch (TelegramApiException|TelegramForbiddenException $e) {
            // A transport failure (timeout, cURL error, 5xx) may have been delivered: keep the claim,
            // flag it for the owner and go on — one dead chat must not stop `assignments:refresh`.
            if ($e instanceof TelegramApiException && ! $e->isApiResponse()) {
                $meta = $fresh->meta;
                $meta[$kind.'_uncertain'] = true;
                $fresh->forceFill(['meta' => $meta])->save();

                Log::warning('assignments.post_uncertain', [
                    'assignment_id' => $fresh->id,
                    'kind' => $kind,
                    'error' => Str::limit($e->getMessage(), 200),
                ]);

                return false;
            }

            $meta = $fresh->meta;
            $meta[$column.'_failures'] = $this->failures($fresh, $column) + 1;
            $fresh->forceFill([$column => null, 'meta' => $meta])->save();

            Log::warning('assignments.post_failed', [
                'assignment_id' => $fresh->id,
                'kind' => $kind,
                'error' => Str::limit($e->getMessage(), 200),
            ]);

            return false;
        }

        $assignment->refresh();

        return true;
    }

    private function failures(Assignment $assignment, string $column): int
    {
        $value = $assignment->meta[$column.'_failures'] ?? 0;

        return is_int($value) ? $value : 0;
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('organizations.assignments.announce_attempts', 3));
    }

    private function remindBeforeHours(): int
    {
        return max(1, (int) config('organizations.assignments.remind_before_hours', 24));
    }
}
