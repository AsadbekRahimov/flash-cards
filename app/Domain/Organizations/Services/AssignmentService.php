<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Learning\Services\SpacedRepetitionEngine;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Organizations\DTO\AssignmentProgress;
use App\Domain\Organizations\DTO\LearnerAssignment;
use App\Domain\Organizations\Exceptions\AssignmentException;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Services\TelegramMessageRateLimiter;
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
 *  - Progress = learner's `word_repetitions.repetitions >= 1` over the ACTIVE words of the lesson,
 *    counted by grouped queries (per lesson × learner) and written by ONE upsert per batch, never
 *    row by row (`writeSnapshots`); `completed_at` is the moment the threshold was first reached, set
 *    once by a conditional UPDATE (a repeated or parallel refresh changes nothing and raises no
 *    second event). The learner's own list (`forLearner`) counts live and writes only what changed.
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

    /** True once a post found no global Telegram slot in this run: the rest waits for the next `assignments:refresh`. */
    private bool $slotDenied = false;

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly ProductEventRecorder $events,
        private readonly LocaleResolver $locales,
        private readonly TelegramMessageRateLimiter $outbound,
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
     * it was already announced (or is closed), when Telegram refused, or when the bot-wide send budget
     * had no free slot (nothing is claimed then; `assignments:refresh` tries again). `$reserveSlot` is
     * false for the queued job: its middleware has already taken the slot.
     */
    public function announce(Assignment $assignment, ?CarbonImmutable $now = null, bool $reserveSlot = true): bool
    {
        return $this->post($assignment, 'announced_at', 'announce', $now ?? CarbonImmutable::now(), $reserveSlot);
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
        $this->slotDenied = false;

        Assignment::query()
            ->where('status', Assignment::STATUS_ACTIVE)
            ->ofActiveOrganization()
            ->inLinkedGroup()
            ->whereNull('announced_at')
            ->where('due_at', '>', $now)
            ->orderBy('id')
            ->each(function (Assignment $assignment) use ($now, &$posted): ?bool {
                if ($this->slotDenied) {
                    return false;
                }

                if ($this->failures($assignment, 'announced_at') < $this->maxAttempts() && $this->announce($assignment, $now)) {
                    $posted++;
                }

                return null;
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
        $this->slotDenied = false;

        Assignment::query()
            ->where('status', Assignment::STATUS_ACTIVE)
            ->ofActiveOrganization()
            ->inLinkedGroup()
            ->whereNull('reminded_at')
            ->where('due_at', '>', $now)
            ->where('due_at', '<=', $now->addHours($this->remindBeforeHours()))
            ->orderBy('id')
            ->each(function (Assignment $assignment) use ($now, &$posted): ?bool {
                if ($this->slotDenied) {
                    return false;
                }

                if ($this->failures($assignment, 'reminded_at') >= $this->maxAttempts()) {
                    return null;
                }

                $tooSoon = $assignment->announced_at !== null
                    && $assignment->announced_at->gt($assignment->due_at->subHours($this->remindBeforeHours()));

                if ($tooSoon) {
                    Assignment::query()->whereKey($assignment->id)->whereNull('reminded_at')->update(['reminded_at' => $now]);

                    return null;
                }

                if ($this->post($assignment, 'reminded_at', 'remind', $now)) {
                    $posted++;
                }

                return null;
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
     * learner's OWN progress counted live — "Today" must not lag behind a session — in a fixed
     * number of queries whatever the number of assignments: one grouped count over the lessons of
     * all of them, one read of the snapshot rows, and ONE upsert of just the rows the count changed
     * (a warm call writes nothing at all). A threshold reached for the first time is claimed here
     * exactly like in the group refresh (`writeSnapshots`), so the two paths cannot raise
     * `assignment.completed` twice between them.
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

        if ($assignments->isEmpty()) {
            return [];
        }

        /** @var list<int> $lessonIds */
        $lessonIds = $assignments->pluck('lesson_id')->unique()->values()->all();
        $totals = $this->activeWordTotals($lessonIds);
        $repeated = $this->repeatedCounts($lessonIds, [$learner->id]);
        $existing = $this->snapshots($assignments->modelKeys(), [$learner->id]);

        /** @var list<AssignmentProgress> $rows */
        $rows = $assignments
            ->map(fn (Assignment $assignment): AssignmentProgress => $this->progressOf(
                $assignment,
                $learner->id,
                $totals[$assignment->lesson_id] ?? 0,
                $repeated[$assignment->lesson_id][$learner->id] ?? 0,
            ))
            ->values()
            ->all();

        $changed = array_values(array_filter(
            $rows,
            fn (AssignmentProgress $row): bool => $this->needsWrite($row, $existing->get($row->key())),
        ));

        if ($changed !== []) {
            $this->writeSnapshots($changed, $existing, $now, [$learner->id => $learner]);
            // Re-read rather than guess: a parallel claim may have won the `completed_at` of a row.
            $existing = $this->snapshots($assignments->modelKeys(), [$learner->id]);
        }

        return array_map(
            static fn (AssignmentProgress $row): LearnerAssignment => new LearnerAssignment(
                $row->assignment,
                $row->progressPct,
                $existing->get($row->key())?->completed_at,
            ),
            $rows,
        );
    }

    /**
     * Snapshot of the given learners (one chunk of the group's active members) on one assignment:
     * two aggregate queries, one read of the rows, one upsert, plus a claim per threshold reached.
     * Every row is written — the group refresh re-stamps `computed_at`, the report's "as of".
     *
     * @param  list<int>  $learnerIds
     * @return int learners that reached the threshold for the first time in this call
     */
    private function compute(Assignment $assignment, array $learnerIds, CarbonImmutable $now): int
    {
        if ($learnerIds === []) {
            return 0;
        }

        $total = $this->activeWordTotals([$assignment->lesson_id])[$assignment->lesson_id] ?? 0;
        $repeated = $total === 0 ? [] : ($this->repeatedCounts([$assignment->lesson_id], $learnerIds)[$assignment->lesson_id] ?? []);
        $existing = $this->snapshots([$assignment->id], $learnerIds);

        $rows = array_map(
            fn (int $learnerId): AssignmentProgress => $this->progressOf($assignment, $learnerId, $total, $repeated[$learnerId] ?? 0),
            $learnerIds,
        );

        return $this->writeSnapshots($rows, $existing, $now);
    }

    /**
     * The snapshot rule (docs/13 §1.4): percent of the lesson's ACTIVE words the learner has
     * `repetitions >= 1` on; the threshold takes `ceil(total × threshold%)` words, at least one.
     * A lesson without active words never completes.
     */
    private function progressOf(Assignment $assignment, int $learnerId, int $total, int $repeated): AssignmentProgress
    {
        $needed = $total === 0 ? 0 : max(1, (int) ceil($total * $assignment->completion_threshold / 100));

        return new AssignmentProgress(
            $assignment,
            $learnerId,
            $total === 0 ? 0 : min(100, intdiv($repeated * 100, $total)),
            $needed > 0 && $repeated >= $needed,
            $needed,
        );
    }

    /**
     * Whether a counted row differs from its stored snapshot: no row yet, another percent, or a
     * reached threshold without a claim (a writer that died between the upsert and the claim is
     * made up for here).
     */
    private function needsWrite(AssignmentProgress $row, ?AssignmentResult $current): bool
    {
        return $current === null
            || $current->progress_pct !== $row->progressPct
            || ($row->reached && $current->completed_at === null);
    }

    /**
     * Writes the rows in ONE upsert by `(assignment_id, learner_id)` that never touches
     * `completed_at`, then claims every threshold reached for the first time by a conditional
     * `UPDATE … WHERE completed_at IS NULL` — the single place that decides who raises
     * `assignment.completed`: of two parallel writers (a learner's GET, `assignments:refresh`, the
     * report opening, `close`) exactly one wins that UPDATE, the other changes nothing and raises
     * nothing. No transaction spans the two steps on purpose (nothing is held across the journal
     * read): a process dying in between leaves the percent without a claim, which the next count
     * sees as `needsWrite` and completes.
     *
     * @param  list<AssignmentProgress>  $rows
     * @param  Collection<string, AssignmentResult>  $existing  the rows read before the count, by `AssignmentProgress::key()`
     * @param  array<int, Learner>  $learners  instances the caller already has (for the event)
     * @return int learners that reached the threshold for the first time in this call
     */
    private function writeSnapshots(array $rows, Collection $existing, CarbonImmutable $now, array $learners = []): int
    {
        if ($rows === []) {
            return 0;
        }

        AssignmentResult::query()->upsert(
            array_map(static fn (AssignmentProgress $row): array => [
                'assignment_id' => $row->assignment->id,
                'learner_id' => $row->learnerId,
                'progress_pct' => $row->progressPct,
                'computed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ], $rows),
            ['assignment_id', 'learner_id'],
            ['progress_pct', 'computed_at', 'updated_at'],
        );

        $pending = array_values(array_filter(
            $rows,
            static fn (AssignmentProgress $row): bool => $row->reached && $existing->get($row->key())?->completed_at === null,
        ));

        if ($pending === []) {
            return 0;
        }

        $moments = $this->completionMoments($pending, $now);
        $completed = 0;

        foreach ($pending as $row) {
            $moment = $moments[$row->key()] ?? $now;
            $claimed = AssignmentResult::query()
                ->where('assignment_id', $row->assignment->id)
                ->where('learner_id', $row->learnerId)
                ->whereNull('completed_at')
                ->update(['completed_at' => $moment, 'updated_at' => $now]);

            if ($claimed === 1) {
                $completed++;
                $this->recordCompleted($row->assignment, $learners[$row->learnerId] ?? Learner::query()->find($row->learnerId), $moment);
            }
        }

        return $completed;
    }

    /**
     * The stored snapshot rows of the given assignments × learners, one query.
     *
     * @param  array<int, mixed>  $assignmentIds
     * @param  list<int>  $learnerIds
     * @return Collection<string, AssignmentResult> by `AssignmentProgress::key()`
     */
    private function snapshots(array $assignmentIds, array $learnerIds): Collection
    {
        /** @var Collection<string, AssignmentResult> $rows */
        $rows = AssignmentResult::query()
            ->whereIn('assignment_id', $assignmentIds)
            ->whereIn('learner_id', $learnerIds)
            ->get()
            ->keyBy(static fn (AssignmentResult $row): string => $row->assignment_id.':'.$row->learner_id);

        return $rows;
    }

    /**
     * Number of ACTIVE words per lesson, one grouped query.
     *
     * @param  list<int>  $lessonIds
     * @return array<int, int> lesson_id => total
     */
    private function activeWordTotals(array $lessonIds): array
    {
        if ($lessonIds === []) {
            return [];
        }

        /** @var array<int, int> $totals */
        $totals = Word::query()
            ->selectRaw('lesson_id, count(*) as total')
            ->whereIn('lesson_id', $lessonIds)
            ->where('is_active', true)
            ->groupBy('lesson_id')
            ->pluck('total', 'lesson_id')
            ->mapWithKeys(static fn ($total, $lessonId): array => [(int) $lessonId => (int) $total])
            ->all();

        return $totals;
    }

    /**
     * Words with `repetitions >= 1` among the ACTIVE words of each lesson, per learner — one
     * grouped query for any number of lessons × learners (the unique `(learner_id, word_id)` index
     * of `word_repetitions` serves the join).
     *
     * @param  list<int>  $lessonIds
     * @param  list<int>  $learnerIds
     * @return array<int, array<int, int>> lesson_id => [learner_id => repeated words]
     */
    private function repeatedCounts(array $lessonIds, array $learnerIds): array
    {
        if ($lessonIds === [] || $learnerIds === []) {
            return [];
        }

        $counts = [];
        $rows = DB::table('word_repetitions')
            ->join('words', 'words.id', '=', 'word_repetitions.word_id')
            ->selectRaw('words.lesson_id, word_repetitions.learner_id, count(*) as repeated')
            ->whereIn('word_repetitions.learner_id', $learnerIds)
            ->where('word_repetitions.repetitions', '>=', 1)
            ->where('words.is_active', true)
            ->whereIn('words.lesson_id', $lessonIds)
            ->groupBy('words.lesson_id', 'word_repetitions.learner_id')
            ->get();

        foreach ($rows as $row) {
            $counts[(int) $row->lesson_id][(int) $row->learner_id] = (int) $row->repeated;
        }

        return $counts;
    }

    /**
     * When each threshold was first reached (docs/AUDIT F08): the `needed`-th earliest FIRST
     * accepted passing grade (`quality >= PASS_QUALITY`) among the learner's currently repeated
     * ACTIVE words of the lesson, read from the `training_reviews` journal, so repeating words after
     * the deadline cannot push the moment past it. A word with no passing journal row (legacy data)
     * falls back to its `word_repetitions` last review time — a later, i.e. conservative, estimate.
     * Each moment is kept between the creation of its assignment and now.
     *
     * Two queries for the whole batch, whatever its size: callers pass either the rows of one
     * assignment (many learners) or of one learner (many assignments), so the learners × lessons
     * read here is exactly the rows asked for.
     *
     * @param  list<AssignmentProgress>  $rows
     * @return array<string, CarbonImmutable> by `AssignmentProgress::key()`
     */
    private function completionMoments(array $rows, CarbonImmutable $now): array
    {
        if ($rows === []) {
            return [];
        }

        /** @var list<int> $learnerIds */
        $learnerIds = array_values(array_unique(array_map(static fn (AssignmentProgress $row): int => $row->learnerId, $rows)));
        /** @var list<int> $lessonIds */
        $lessonIds = array_values(array_unique(array_map(static fn (AssignmentProgress $row): int => $row->assignment->lesson_id, $rows)));

        /** @var array<int, array<int, array<int, int>>> $fallbacks learner_id => lesson_id => word_id => last review timestamp */
        $fallbacks = [];

        foreach (WordRepetition::query()
            ->join('words', 'words.id', '=', 'word_repetitions.word_id')
            ->whereIn('word_repetitions.learner_id', $learnerIds)
            ->where('word_repetitions.repetitions', '>=', 1)
            ->where('words.is_active', true)
            ->whereIn('words.lesson_id', $lessonIds)
            ->get(['word_repetitions.learner_id', 'word_repetitions.word_id', 'words.lesson_id', 'word_repetitions.last_reviewed_at', 'word_repetitions.updated_at']) as $r) {
            $fallbacks[(int) $r->learner_id][(int) $r->getAttribute('lesson_id')][(int) $r->word_id] = ($r->last_reviewed_at ?? $r->updated_at ?? $now)->getTimestamp();
        }

        /** @var array<int, array<int, int>> $first learner_id => word_id => first passing review timestamp */
        $first = [];

        if ($fallbacks !== []) {
            foreach (DB::table('training_reviews')
                ->join('words', 'words.id', '=', 'training_reviews.word_id')
                ->selectRaw('training_reviews.learner_id, training_reviews.word_id, MIN(training_reviews.created_at) as first_at')
                ->whereIn('training_reviews.learner_id', $learnerIds)
                ->whereIn('words.lesson_id', $lessonIds)
                ->where('words.is_active', true)
                ->where('training_reviews.quality', '>=', SpacedRepetitionEngine::PASS_QUALITY)
                ->groupBy('training_reviews.learner_id', 'training_reviews.word_id')
                ->get() as $row) {
                if (is_string($row->first_at)) {
                    $first[(int) $row->learner_id][(int) $row->word_id] = CarbonImmutable::parse($row->first_at)->getTimestamp();
                }
            }
        }

        $moments = [];

        foreach ($rows as $row) {
            $times = collect($fallbacks[$row->learnerId][$row->assignment->lesson_id] ?? [])
                ->map(static fn (int $fallback, int $wordId): int => $first[$row->learnerId][$wordId] ?? $fallback)
                ->sort()
                ->values();

            $at = $times->get($row->needed - 1);
            $moment = $at === null ? $now : CarbonImmutable::createFromTimestamp($at);
            $created = CarbonImmutable::instance($row->assignment->created_at ?? $now);

            $moments[$row->key()] = $moment->lt($created) ? $created : ($moment->gt($now) ? $now : $moment);
        }

        return $moments;
    }

    private function recordCompleted(Assignment $assignment, ?Learner $learner, CarbonImmutable $moment): void
    {
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
    private function post(Assignment $assignment, string $column, string $kind, CarbonImmutable $now, bool $reserveSlot = true): bool
    {
        // Before the claim, so a denied slot loses nothing: the row stays unclaimed for the next run.
        if ($reserveSlot && ! $this->outbound->awaitGlobal(max(0, (int) config('telegram.outbound.sync_wait_ms', 2000)))) {
            $this->slotDenied = true;

            return false;
        }

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
