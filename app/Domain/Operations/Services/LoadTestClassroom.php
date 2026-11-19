<?php

declare(strict_types=1);

namespace App\Domain\Operations\Services;

use App\Domain\Administration\Services\PendingGroupsBadge;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Organizations\Enums\JoinedVia;
use App\Domain\Organizations\Enums\MemberStatus;
use App\Domain\Organizations\Enums\SeatState;
use App\Domain\Organizations\Services\AssignmentService;
use App\Domain\Organizations\Services\OrgOverviewService;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Organization;
use App\Models\TelegramGroup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The classes of the load test (tests/load/k6, `LoadScenarioSeeder`): a share of the test learners of `load:tokens` are members of
 * Telegram groups of test centers, each group with one active assignment on a lesson of the seed course - so the class path of
 * the burst (`arrival-burst.js`) reads a non-empty `/assignments` and `/today` says `assignments_possible: true` for them.
 *
 * WHO is a member is decided by the token index alone (learner N = index N - 1, the order of the token file), with the rule the k6
 * side mirrors (`plan.js` isClassLearner): index i is a member when floor((i + 1) x share) > floor(i x share) - evenly spread, the
 * same on every prefix of the file, so a smaller token file holds exactly the members of its range. Members are dealt to groups of
 * `classSize` in index order, five groups to a center.
 *
 * Everything here is marked as load-test data the way the learners are (`meta.load_test` / `settings.load_test`): centers
 * `load-test-center-N`, groups with chat id CHAT_ID_BASE - N (a range no real chat of ours is expected in; a row there WITHOUT the
 * mark is refused, nothing is written). `sync()` makes the database match the wanted state - members, groups and centers beyond it
 * are deleted - so a repeat changes nothing and a share of 0 removes all of it (the cleanup path).
 *
 * Rows are written DIRECTLY, not through the domain services, on purpose:
 *  - `GroupMembershipService::join` / `OrganizationGroupLinker::link` / an Eloquent relink run `OrganizationMembershipService::joinFromGroup`
 *    after the commit, which raises `OrganizationMemberJoined` (a bot DM per learner) - and the first is one Eloquent round trip per row;
 *  - `AssignmentService::create` queues `AnnounceAssignmentJob` (a post to the made-up chat) and records a product event. The rows are
 *    written with `announced_at` / `reminded_at` already claimed, so `assignments:refresh` never posts for them either.
 * What those services would invalidate is invalidated here after the commit: the learners' version (`EntitlementResolver::forgetMany`,
 * which also moves `/today` and `/me` snapshots), the `/org` overview of every center touched, the pending-groups badge. The progress
 * snapshots are computed by `AssignmentService::refreshResults` (bulk, no messages).
 */
final class LoadTestClassroom
{
    /** Chat id of group N (1-based) is CHAT_ID_BASE - N: "-100" followed by 8 000 000 00N, the supergroup form, apart from the learners' 8e9. */
    public const CHAT_ID_BASE = -1_008_000_000_000;

    public const SLUG_PREFIX = 'load-test-center-';

    public const META_KEY = LoadTestTokenIssuer::META_KEY;

    public const DEFAULT_SHARE = 0.2;

    public const DEFAULT_CLASS_SIZE = 25;

    public const MAX_CLASS_SIZE = 500;

    public const GROUPS_PER_CENTER = 5;

    /** The assignment is due this far ahead; a repeat run moves a due date closer than a day back out. */
    public const DUE_DAYS = 30;

    private const CHUNK = 500;

    /** The same epsilon as plan.js: (i + 1) x 0.2 is 0.9999999999999999 for i = 4 in binary floating point. */
    private const EPSILON = 1e-9;

    /**
     * What a sync() changed (reset at its start): learners whose cached version must move.
     *
     * @var array<int, true>
     */
    private array $changedLearners = [];

    /** @var array<int, true> */
    private array $touchedCenters = [];

    /** @var array<int, true> groups whose assignment snapshot must be recounted */
    private array $dirtyGroups = [];

    private bool $groupsChanged = false;

    private bool $progressChanged = false;

    public function __construct(
        private readonly EntitlementResolver $entitlements,
        private readonly AssignmentService $assignments,
    ) {}

    /** Whether the learner at token index `$index` (0-based: learner N has index N - 1) is a class member at `$share`. */
    public static function isMember(int $index, float $share): bool
    {
        return floor(($index + 1) * $share + self::EPSILON) > floor($index * $share + self::EPSILON);
    }

    /**
     * The members among `$learners`, in index order.
     *
     * @param  array<int, int>  $learners  learner id => token index
     * @return list<int> learner ids
     */
    public function members(array $learners, float $share): array
    {
        asort($learners);
        $members = [];
        foreach ($learners as $id => $index) {
            if (self::isMember($index, $share)) {
                $members[] = $id;
            }
        }

        return $members;
    }

    /**
     * The lesson the classes get: the first lesson of the course (stage, then lesson number) with an active word the mature cards of
     * the seed do not cover - the members start it from nothing; when the cards cover the whole course, its last lesson with active
     * words (the members then get no mature cards of it: see the seeder). Null when the course has no active word at all.
     *
     * @param  list<int>  $introducedWordIds
     */
    public function lesson(Course $course, array $introducedWordIds): ?Lesson
    {
        $introduced = array_flip($introducedWordIds);
        $last = null;

        foreach ($this->courseLessons($course) as $lessonId => $wordIds) {
            $last = $lessonId;
            foreach ($wordIds as $wordId) {
                if (! isset($introduced[$wordId])) {
                    return Lesson::query()->findOrFail($lessonId);
                }
            }
        }

        return $last === null ? null : Lesson::query()->findOrFail($last);
    }

    /** @return list<int> the active words of the lesson, by id */
    public function lessonWordIds(Lesson $lesson): array
    {
        return array_values(array_map(intval(...), DB::table('words')
            ->where('lesson_id', $lesson->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->pluck('id')
            ->all()));
    }

    /**
     * How many of the lesson's words a member "with progress" has repeated: about half, never all (a completed assignment is skipped by
     * the client, the burst would find nothing to open), so a lesson of one word gives no progress.
     */
    public static function progressWords(int $total): int
    {
        return max(0, min($total - 1, max(1, intdiv($total, 2))));
    }

    /**
     * Makes the classes of the database match `$memberIds` (in index order): groups of `$classSize`, five to a center, one active
     * assignment on `$lesson` per group, every second member (ranks 0, 2, 4, ...) with progress on it. No members: everything of the
     * load test (centers, groups, memberships, assignments) is deleted.
     *
     * @param  list<int>  $memberIds
     * @return array{centers: int, groups: int, members: int, with_progress: int, assignments: int, changed_learners: int}
     */
    public function sync(array $memberIds, Course $course, ?Lesson $lesson, int $classSize, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        if ($classSize < 1 || $classSize > self::MAX_CLASS_SIZE) {
            throw new RuntimeException(sprintf('The class size must be between 1 and %d.', self::MAX_CLASS_SIZE));
        }
        if ($memberIds !== [] && $lesson === null) {
            throw new RuntimeException('The classes need a lesson with active words.');
        }

        $groupCount = intdiv(count($memberIds) + $classSize - 1, $classSize);
        $centerCount = intdiv($groupCount + self::GROUPS_PER_CENTER - 1, self::GROUPS_PER_CENTER);
        /** @var list<list<int>> $classes */
        $classes = $memberIds === [] ? [] : array_chunk($memberIds, $classSize);

        // Read and refused BEFORE anything is written: a row of ours without the mark is real data.
        $centers = $this->existingCenters();
        $groups = $this->existingGroups();

        $this->changedLearners = $this->touchedCenters = $this->dirtyGroups = [];
        $this->groupsChanged = $this->progressChanged = false;

        DB::transaction(function () use ($memberIds, $course, $lesson, $classes, $groupCount, $centerCount, $centers, $groups, $now): void {
            $this->dropSurplus($groups, $centers, $groupCount, $centerCount);

            $centerIds = [];
            for ($n = 0; $n < $centerCount; $n++) {
                $centerIds[$n] = $this->center($n, $centers[$n] ?? null);
            }

            foreach ($classes as $g => $members) {
                $centerId = $centerIds[intdiv($g, self::GROUPS_PER_CENTER)];
                $groupId = $this->group($g, $groups[$g] ?? null, $centerId, $course);
                $this->groupMembers($groupId, $members, $now);
                if ($lesson !== null) {
                    $this->assignment($groupId, $centerId, $lesson, $members, $now);
                }
            }

            foreach ($centerIds as $n => $centerId) {
                $this->centerMembers($centerId, array_merge(...array_slice($classes, $n * self::GROUPS_PER_CENTER, self::GROUPS_PER_CENTER)), $now);
            }

            if ($lesson !== null && $memberIds !== []) {
                $this->progress($memberIds, $lesson, $now);
            }
        });

        // What the domain services would have dropped, once the rows are visible.
        $this->entitlements->forgetMany(array_keys($this->changedLearners));
        foreach (array_keys($this->touchedCenters) as $centerId) {
            OrgOverviewService::forget($centerId);
        }
        if ($this->groupsChanged) {
            PendingGroupsBadge::forget();
        }

        // The progress snapshots of the report and of `/assignments`: the service's own bulk recount (no messages).
        $dirty = $this->progressChanged ? $this->loadAssignmentIds() : $this->assignmentIdsOf(array_keys($this->dirtyGroups));
        foreach (Assignment::query()->whereIn('id', $dirty)->orderBy('id')->get() as $assignment) {
            $this->assignments->refreshResults($assignment, $now);
        }

        $withProgress = 0;
        foreach (array_keys($memberIds) as $rank) {
            $withProgress += $rank % 2 === 0 ? 1 : 0;
        }

        return [
            'centers' => $centerCount,
            'groups' => $groupCount,
            'members' => count($memberIds),
            'with_progress' => $lesson !== null && self::progressWords(count($this->lessonWordIds($lesson))) > 0 ? $withProgress : 0,
            'assignments' => $lesson === null ? 0 : $groupCount,
            'changed_learners' => count($this->changedLearners),
        ];
    }

    /**
     * Lesson id => its active word ids, in the order of the course.
     *
     * @return array<int, list<int>>
     */
    private function courseLessons(Course $course): array
    {
        $lessons = [];

        DB::table('words')
            ->join('lessons', 'lessons.id', '=', 'words.lesson_id')
            ->join('stages', 'stages.id', '=', 'lessons.stage_id')
            ->where('stages.course_id', $course->id)
            ->where('words.is_active', true)
            ->orderBy('stages.number')
            ->orderBy('lessons.number')
            ->orderBy('words.id')
            ->get(['words.id', 'words.lesson_id'])
            ->each(function (object $row) use (&$lessons): void {
                $lessons[(int) $row->lesson_id][] = (int) $row->id;
            });

        return $lessons;
    }

    /** @return array<int, Organization> by number (0-based) */
    private function existingCenters(): array
    {
        $centers = [];

        foreach (Organization::query()->where('slug', 'like', self::SLUG_PREFIX.'%')->get() as $center) {
            $suffix = substr($center->slug, strlen(self::SLUG_PREFIX));
            if (! ctype_digit($suffix) || (int) $suffix < 1) {
                continue;
            }
            if (($center->settings[self::META_KEY] ?? false) !== true) {
                throw new RuntimeException(sprintf('Organization "%s" exists without the load-test mark: real data, refusing to touch it.', $center->slug));
            }
            $centers[(int) $suffix - 1] = $center;
        }

        return $centers;
    }

    /** @return array<int, TelegramGroup> by number (0-based) */
    private function existingGroups(): array
    {
        $groups = [];

        $rows = TelegramGroup::query()
            ->withoutGlobalScopes()
            ->whereBetween('chat_id', [self::CHAT_ID_BASE - LoadTestTokenIssuer::MAX_LEARNERS, self::CHAT_ID_BASE - 1])
            ->get();

        foreach ($rows as $group) {
            if (($group->meta[self::META_KEY] ?? false) !== true) {
                throw new RuntimeException(sprintf('Telegram group with chat id %d exists without the load-test mark: real data, refusing to touch it.', $group->chat_id));
            }
            $groups[self::CHAT_ID_BASE - $group->chat_id - 1] = $group;
        }

        return $groups;
    }

    /**
     * Groups and centers beyond the wanted numbers go, with their memberships and assignments (FK cascades). Directly: the observers'
     * delete path releases the center's members with an event (and a DM) each.
     *
     * @param  array<int, TelegramGroup>  $groups
     * @param  array<int, Organization>  $centers
     */
    private function dropSurplus(array $groups, array $centers, int $groupCount, int $centerCount): void
    {
        $groupIds = [];
        foreach ($groups as $n => $group) {
            if ($n >= $groupCount) {
                $groupIds[] = $group->id;
                if ($group->organization_id !== null) {
                    $this->touchedCenters[(int) $group->organization_id] = true;
                }
            }
        }

        $centerIds = [];
        foreach ($centers as $n => $center) {
            if ($n >= $centerCount) {
                $centerIds[] = $center->id;
            }
        }

        if ($groupIds !== []) {
            $this->touch(DB::table('group_members')->whereIn('telegram_group_id', $groupIds)->pluck('learner_id')->all());
            DB::table('telegram_groups')->whereIn('id', $groupIds)->delete();
            $this->groupsChanged = true;
        }

        if ($centerIds !== []) {
            $this->touch(DB::table('organization_members')->whereIn('organization_id', $centerIds)->pluck('learner_id')->all());
            // A group a test center still holds (linked by hand) is unlinked, not left pointing at nothing.
            $this->touch(DB::table('group_members')
                ->whereIn('telegram_group_id', DB::table('telegram_groups')->select('id')->whereIn('organization_id', $centerIds))
                ->pluck('learner_id')->all());
            DB::table('telegram_groups')->whereIn('organization_id', $centerIds)->update(['organization_id' => null, 'updated_at' => now()]);
            DB::table('organizations')->whereIn('id', $centerIds)->delete();
            foreach ($centerIds as $centerId) {
                $this->touchedCenters[$centerId] = true;
            }
        }
    }

    private function center(int $n, ?Organization $center): int
    {
        if ($center === null) {
            $center = Organization::query()->create([
                'name' => 'Load test center '.($n + 1),
                'slug' => self::SLUG_PREFIX.($n + 1),
                'type' => 'center',
                'status' => 'active',
                'timezone' => (string) config('learners.default_timezone'),
                'locale' => 'ru',
                'settings' => [self::META_KEY => true],
            ]);
            $this->touchedCenters[$center->id] = true;
        } elseif (! $center->isActive()) {
            // Through Eloquent: OrganizationObserver drops the entitlements of its members (no message on that path).
            $center->forceFill(['status' => 'active'])->save();
            $this->touchedCenters[$center->id] = true;
        }

        return $center->id;
    }

    private function group(int $g, ?TelegramGroup $group, int $centerId, Course $course): int
    {
        if ($group === null) {
            // Created already linked and active: the observer's `created` only drops the pending-groups badge.
            $group = TelegramGroup::query()->create([
                'chat_id' => self::CHAT_ID_BASE - ($g + 1),
                'title' => 'Load test class '.($g + 1),
                'status' => 'active',
                'organization_id' => $centerId,
                'course_id' => $course->id,
                'meta' => [self::META_KEY => true],
            ]);
            $this->touchedCenters[$centerId] = true;
            $this->dirtyGroups[$group->id] = true;

            return $group->id;
        }

        if ($group->organization_id !== $centerId || $group->course_id !== $course->id || $group->status !== 'active') {
            // Directly: an Eloquent relink would sync the members into the center with an event (and a DM) each.
            DB::table('telegram_groups')->where('id', $group->id)->update([
                'organization_id' => $centerId,
                'course_id' => $course->id,
                'status' => 'active',
                'updated_at' => now(),
            ]);
            // The open assignments of a group that changed center or course are not its classes' any more.
            DB::table('assignments')->where('telegram_group_id', $group->id)->delete();
            $this->touch(DB::table('group_members')->where('telegram_group_id', $group->id)->pluck('learner_id')->all());
            foreach (array_filter([$group->organization_id, $centerId]) as $id) {
                $this->touchedCenters[(int) $id] = true;
            }
            $this->groupsChanged = true;
            $this->dirtyGroups[$group->id] = true;
        }

        return $group->id;
    }

    /** @param  list<int>  $members */
    private function groupMembers(int $groupId, array $members, CarbonImmutable $now): void
    {
        /** @var array<int, string> $existing learner id => status */
        $existing = DB::table('group_members')->where('telegram_group_id', $groupId)->pluck('status', 'learner_id')
            ->mapWithKeys(static fn (mixed $status, mixed $id): array => [(int) $id => (string) $status])->all();
        $wanted = array_flip($members);

        $gone = array_values(array_filter(array_keys($existing), static fn (int $id): bool => ! isset($wanted[$id])));
        foreach (array_chunk($gone, self::CHUNK) as $chunk) {
            DB::table('group_members')->where('telegram_group_id', $groupId)->whereIn('learner_id', $chunk)->delete();
            DB::table('assignment_results')
                ->whereIn('assignment_id', DB::table('assignments')->select('id')->where('telegram_group_id', $groupId))
                ->whereIn('learner_id', $chunk)
                ->delete();
        }

        $rows = [];
        $back = [];
        foreach ($members as $id) {
            if (! isset($existing[$id])) {
                $rows[] = ['telegram_group_id' => $groupId, 'learner_id' => $id, 'status' => 'active', 'joined_at' => $now, 'left_at' => null, 'created_at' => $now, 'updated_at' => $now];
            } elseif ($existing[$id] !== 'active') {
                $back[] = $id;
            }
        }
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('group_members')->insert($chunk);
        }
        foreach (array_chunk($back, self::CHUNK) as $chunk) {
            DB::table('group_members')->where('telegram_group_id', $groupId)->whereIn('learner_id', $chunk)
                ->update(['status' => 'active', 'joined_at' => $now, 'left_at' => null, 'updated_at' => $now]);
        }

        $changed = [...$gone, ...array_column($rows, 'learner_id'), ...$back];
        if ($changed !== []) {
            $this->touch($changed);
            $this->dirtyGroups[$groupId] = true;
        }
    }

    /**
     * The center memberships the group join hook would have made (`joined_via = group`; waitlisted - the test centers have no
     * subscription, so no seat and no sponsored Pro: the learners stay on their own plan). Without them `organizations:sync-members`
     * would make them itself, with an event (and a DM) each.
     *
     * @param  list<int>  $members
     */
    private function centerMembers(int $centerId, array $members, CarbonImmutable $now): void
    {
        /** @var array<int, string> $existing learner id => status */
        $existing = DB::table('organization_members')->where('organization_id', $centerId)->pluck('status', 'learner_id')
            ->mapWithKeys(static fn (mixed $status, mixed $id): array => [(int) $id => (string) $status])->all();
        $wanted = array_flip($members);

        $gone = array_values(array_filter(array_keys($existing), static fn (int $id): bool => ! isset($wanted[$id])));
        foreach (array_chunk($gone, self::CHUNK) as $chunk) {
            DB::table('organization_members')->where('organization_id', $centerId)->whereIn('learner_id', $chunk)->delete();
        }

        $active = [
            'status' => MemberStatus::Active->value,
            'seat_state' => SeatState::Waitlisted->value,
            'joined_via' => JoinedVia::Group->value,
            'invite_id' => null,
            'seated_at' => null,
            'waitlisted_at' => $now,
            'joined_at' => $now,
            'left_at' => null,
            'updated_at' => $now,
        ];

        $rows = [];
        $back = [];
        foreach ($members as $id) {
            if (! isset($existing[$id])) {
                $rows[] = ['organization_id' => $centerId, 'learner_id' => $id, 'meta' => '{}', 'created_at' => $now, ...$active];
            } elseif ($existing[$id] !== MemberStatus::Active->value) {
                $back[] = $id;
            }
        }
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('organization_members')->insert($chunk);
        }
        foreach (array_chunk($back, self::CHUNK) as $chunk) {
            DB::table('organization_members')->where('organization_id', $centerId)->whereIn('learner_id', $chunk)->update($active);
        }

        $changed = [...$gone, ...array_column($rows, 'learner_id'), ...$back];
        if ($changed !== []) {
            $this->touch($changed);
            $this->touchedCenters[$centerId] = true;
        }
    }

    /**
     * One active assignment of the group on `$lesson`, due DUE_DAYS ahead, its posts claimed (nothing is ever sent to the made-up chat).
     *
     * @param  list<int>  $members
     */
    private function assignment(int $groupId, int $centerId, Lesson $lesson, array $members, CarbonImmutable $now): void
    {
        $existing = DB::table('assignments')->where('telegram_group_id', $groupId)->orderBy('id')->get(['id', 'organization_id', 'lesson_id', 'status', 'due_at']);
        $first = $existing->first();

        $extra = $existing->slice(1)->pluck('id')->all();
        if ($extra !== []) {
            DB::table('assignments')->whereIn('id', $extra)->delete();
            $this->touch($members);
        }

        $wanted = [
            'organization_id' => $centerId,
            'lesson_id' => $lesson->id,
            'status' => Assignment::STATUS_ACTIVE,
            'due_at' => $now->addDays(self::DUE_DAYS),
            'announced_at' => $now,
            'reminded_at' => $now,
            'updated_at' => $now,
        ];

        if ($first === null) {
            $id = DB::table('assignments')->insertGetId([
                'telegram_group_id' => $groupId,
                'title' => 'Load test',
                'completion_threshold' => 100,
                'created_by_user_id' => null,
                'meta' => json_encode([self::META_KEY => true], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                ...$wanted,
            ]);
            $this->dirtyGroups[$groupId] = true;
            $this->touch($members);

            return;
        }

        $stale = (int) $first->organization_id !== $centerId
            || (int) $first->lesson_id !== $lesson->id
            || $first->status !== Assignment::STATUS_ACTIVE
            || CarbonImmutable::parse((string) $first->due_at)->lt($now->addDay());

        if ($stale) {
            if ((int) $first->lesson_id !== $lesson->id) {
                DB::table('assignment_results')->where('assignment_id', $first->id)->delete();
            }
            DB::table('assignments')->where('id', $first->id)->update($wanted);
            $this->touch($members);
            $this->dirtyGroups[$groupId] = true;
        }
    }

    /**
     * Members of rank 0, 2, 4, ... have repeated the first progressWords() words of the lesson (repetitions 1, not due before tomorrow),
     * the others none of it; any other row of a member on the lesson (an earlier seed, a run) is removed so the list is what it says.
     *
     * @param  list<int>  $memberIds
     */
    private function progress(array $memberIds, Lesson $lesson, CarbonImmutable $now): void
    {
        $words = $this->lessonWordIds($lesson);
        $done = array_slice($words, 0, self::progressWords(count($words)));
        $rest = array_slice($words, count($done));

        $with = [];
        $without = [];
        foreach ($memberIds as $rank => $id) {
            if ($rank % 2 === 0 && $done !== []) {
                $with[] = $id;
            } else {
                $without[] = $id;
            }
        }

        $changed = 0;
        foreach (array_chunk($without, self::CHUNK) as $chunk) {
            $changed += DB::table('word_repetitions')->whereIn('learner_id', $chunk)->whereIn('word_id', $words)->delete();
        }
        if ($rest !== []) {
            foreach (array_chunk($with, self::CHUNK) as $chunk) {
                $changed += DB::table('word_repetitions')->whereIn('learner_id', $chunk)->whereIn('word_id', $rest)->delete();
            }
        }

        $stamp = $now->toDateTimeString();
        $rows = [];
        foreach ($with as $learnerId) {
            foreach ($done as $wordId) {
                $rows[] = [
                    'learner_id' => $learnerId,
                    'word_id' => $wordId,
                    'repetitions' => 1,
                    'interval_days' => 1,
                    'easiness_factor' => 2.5,
                    'last_quality' => 4,
                    'last_reviewed_at' => $now->subDay()->toDateTimeString(),
                    'next_review_at' => $now->addDay()->toDateTimeString(),
                    'is_hard' => false,
                    'introduced_mode' => 'lesson',
                    'state' => 'review',
                    'learning_step' => 0,
                    'lapses' => 0,
                    'scheduler' => 'sm2',
                    'meta' => '{}',
                    'created_at' => $stamp,
                    'updated_at' => $stamp,
                ];
            }
        }
        foreach (array_chunk($rows, 1000) as $chunk) {
            // Only the missing ones: a row a run has taken further still counts as progress, and a repeat writes nothing.
            $changed += DB::table('word_repetitions')->insertOrIgnore($chunk);
        }

        if ($changed > 0) {
            $this->progressChanged = true;
        }
    }

    /** @return list<int> the assignments of the load-test groups */
    private function loadAssignmentIds(): array
    {
        return array_values(array_map(intval(...), DB::table('assignments')
            ->join('telegram_groups', 'telegram_groups.id', '=', 'assignments.telegram_group_id')
            ->whereBetween('telegram_groups.chat_id', [self::CHAT_ID_BASE - LoadTestTokenIssuer::MAX_LEARNERS, self::CHAT_ID_BASE - 1])
            ->pluck('assignments.id')
            ->all()));
    }

    /**
     * @param  list<int>  $groupIds
     * @return list<int>
     */
    private function assignmentIdsOf(array $groupIds): array
    {
        return $groupIds === [] ? [] : array_values(array_map(intval(...), DB::table('assignments')->whereIn('telegram_group_id', $groupIds)->pluck('id')->all()));
    }

    /** @param  array<int, mixed>  $learnerIds */
    private function touch(array $learnerIds): void
    {
        foreach ($learnerIds as $id) {
            $this->changedLearners[(int) $id] = true;
        }
    }
}
