<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Engagement\Exceptions\FriendshipException;
use App\Domain\Engagement\Services\FriendshipService;
use App\Domain\Operations\Services\LoadTestClassroom;
use App\Domain\Operations\Services\LoadTestTokenIssuer;
use App\Models\Course;
use App\Models\Learner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * What the k6 scenarios beyond the hot paths need in the database (tests/load/k6/README.md, audit 2026-11-10 §2 item 3). Run it
 * AFTER `load:tokens` (it works on the test learners that command made - the ones with `meta.load_test`), local / testing /
 * staging only:
 *
 *   php artisan db:seed --class=LoadScenarioSeeder --force
 *
 *   - Mature cards. Every test learner gets a `word_repetitions` row, graduated and DUE, for the first LOAD_SCENARIO_WORDS (default
 *     300) active words of the course: `repetitions` cycles 2..7, so the exercise selector serves flash, reverse and audio cards
 *     (the path with the distractors) and, for the Pro learners, spelling and gap-fill on every third pass. The same rows are what
 *     the practice, the battle and the game need - they hand out a task to a learner who has been introduced to all its words.
 *     Re-running it makes the cards due again (the way to "re-arm" learners after a long run), it never adds a second row.
 *   - The practice pilot (`practice_pilot_at`), for a stand with PRACTICE_ACCESS=pilot / GAME_ACCESS=pilot.
 *   - Friends. Learners are paired in the order of their numbers: 1 with 2, 3 with 4, ... - the pairs of the battle scenario.
 *   - Classes ({@see LoadTestClassroom}): LOAD_SCENARIO_CLASS_SHARE (default 0.2; 0 = none, and removes the classes of an earlier
 *     run) of the learners, chosen by token index with the rule of plan.js isClassLearner (index i = learner i + 1 is a member when
 *     floor((i + 1) x share) > floor(i x share): Load5, Load10, ... at 0.2), are members of test groups of LOAD_SCENARIO_CLASS_SIZE
 *     (default 25), five groups to a test center, each group with one active assignment on a lesson of the course; every second
 *     member has repeated about half of it. The members get no mature card of that lesson (their progress on it is what the seed says).
 *
 * Settings: LOAD_SCENARIO_COURSE (slug; default: the active course of the test learners, else the first published one),
 * LOAD_SCENARIO_WORDS, LOAD_SCENARIO_CLASS_SHARE, LOAD_SCENARIO_CLASS_SIZE. Nothing here touches a learner without the load-test mark.
 */
final class LoadScenarioSeeder extends Seeder
{
    private const CHUNK_LEARNERS = 200;

    private const UPSERT_ROWS = 1000;

    public function run(): void
    {
        if (! app()->environment(LoadTestTokenIssuer::ALLOWED_ENVIRONMENTS)) {
            throw new RuntimeException('LoadScenarioSeeder runs only in the '.implode(', ', LoadTestTokenIssuer::ALLOWED_ENVIRONMENTS).' environments.');
        }

        $share = $this->classShare();
        $classSize = $this->classSize();

        $learners = $this->testLearners();
        $learnerIds = array_keys($learners);
        if ($learnerIds === []) {
            throw new RuntimeException('No test learners: run `php artisan load:tokens` first.');
        }

        $course = $this->course($learnerIds);
        $wordIds = $this->wordIds($course);
        if ($wordIds === []) {
            throw new RuntimeException('The course "'.$course->slug.'" has no active words.');
        }

        $classroom = app(LoadTestClassroom::class);
        $memberIds = $classroom->members($learners, $share);
        $lesson = $memberIds === [] ? null : $classroom->lesson($course, $wordIds);

        // The members get no mature card of the class lesson: their progress on it is set by the classroom.
        $members = array_flip($memberIds);
        $soloIds = array_values(array_filter($learnerIds, static fn (int $id): bool => ! isset($members[$id])));
        $this->introduce($soloIds, $wordIds);
        if ($memberIds !== []) {
            $this->introduce($memberIds, $lesson === null ? $wordIds : array_values(array_diff($wordIds, $classroom->lessonWordIds($lesson))));
        }
        Learner::query()->whereIn('id', $learnerIds)->update(['practice_pilot_at' => now(), 'active_course_id' => $course->id]);
        [$made, $existing, $failed] = $this->befriend($learnerIds);
        $classes = $classroom->sync($memberIds, $course, $lesson, $classSize);

        $this->say(sprintf(
            'Load scenarios: %d learners, %d due mature cards each (course %s), friends: %d new, %d already, %d failed.',
            count($learnerIds),
            count($wordIds),
            $course->slug,
            $made,
            $existing,
            $failed,
        ));
        $this->say(sprintf(
            'Classes (share %s, size %d): %d members (%d with progress) in %d groups of %d centers, %d assignments%s; %d learners changed.',
            $share,
            $classSize,
            $classes['members'],
            $classes['with_progress'],
            $classes['groups'],
            $classes['centers'],
            $classes['assignments'],
            $lesson === null ? '' : ' on lesson '.$lesson->id,
            $classes['changed_learners'],
        ));
    }

    /**
     * The test learners in the order of their numbers (telegram id = base + N): id => token index (N - 1, the place of the learner
     * in the token file of `load:tokens`).
     *
     * @return array<int, int>
     */
    private function testLearners(): array
    {
        $learners = [];

        Learner::query()
            ->whereBetween('telegram_user_id', [LoadTestTokenIssuer::TELEGRAM_USER_BASE + 1, LoadTestTokenIssuer::TELEGRAM_USER_BASE + LoadTestTokenIssuer::MAX_LEARNERS])
            ->orderBy('telegram_user_id')
            ->get(['id', 'telegram_user_id', 'meta'])
            ->each(function (Learner $learner) use (&$learners): void {
                if (($learner->meta[LoadTestTokenIssuer::META_KEY] ?? false) === true) {
                    $learners[(int) $learner->id] = (int) $learner->telegram_user_id - LoadTestTokenIssuer::TELEGRAM_USER_BASE - 1;
                }
            });

        return $learners;
    }

    private function classShare(): float
    {
        $value = trim($this->setting('LOAD_SCENARIO_CLASS_SHARE'));
        if ($value === '') {
            return LoadTestClassroom::DEFAULT_SHARE;
        }
        if (! is_numeric($value) || (float) $value < 0 || (float) $value > 1) {
            throw new RuntimeException('LOAD_SCENARIO_CLASS_SHARE must be a number from 0 to 1, got "'.$value.'".');
        }

        return (float) $value;
    }

    private function classSize(): int
    {
        $value = trim($this->setting('LOAD_SCENARIO_CLASS_SIZE'));
        if ($value === '') {
            return LoadTestClassroom::DEFAULT_CLASS_SIZE;
        }
        if (! ctype_digit($value) || (int) $value < 1 || (int) $value > LoadTestClassroom::MAX_CLASS_SIZE) {
            throw new RuntimeException(sprintf('LOAD_SCENARIO_CLASS_SIZE must be a whole number from 1 to %d, got "%s".', LoadTestClassroom::MAX_CLASS_SIZE, $value));
        }

        return (int) $value;
    }

    /** @param  list<int>  $learnerIds */
    private function course(array $learnerIds): Course
    {
        $slug = trim($this->setting('LOAD_SCENARIO_COURSE'));
        $query = Course::query()->available()->orderBy('id');

        if ($slug !== '') {
            return $query->where('slug', $slug)->first() ?? throw new RuntimeException('No published course with the slug "'.$slug.'".');
        }

        $activeId = Learner::query()->whereIn('id', $learnerIds)->whereNotNull('active_course_id')->value('active_course_id');

        return ($activeId === null ? null : Course::query()->whereKey($activeId)->first()) ?? $query->first() ?? throw new RuntimeException('No published course.');
    }

    /** @return list<int> */
    private function wordIds(Course $course): array
    {
        $limit = max(1, (int) ($this->setting('LOAD_SCENARIO_WORDS') ?: 300));

        $ids = DB::table('words')
            ->join('lessons', 'lessons.id', '=', 'words.lesson_id')
            ->join('stages', 'stages.id', '=', 'lessons.stage_id')
            ->where('stages.course_id', $course->id)
            ->where('words.is_active', true)
            ->orderBy('stages.number')
            ->orderBy('lessons.number')
            ->orderBy('words.id')
            ->limit($limit)
            ->pluck('words.id')
            ->all();

        return array_values(array_map(intval(...), $ids));
    }

    /**
     * @param  list<int>  $learnerIds
     * @param  list<int>  $wordIds
     */
    private function introduce(array $learnerIds, array $wordIds): void
    {
        $now = now();
        $due = $now->copy()->subHour()->toDateTimeString();
        $stamp = $now->toDateTimeString();

        foreach (array_chunk($learnerIds, self::CHUNK_LEARNERS) as $chunk) {
            $rows = [];
            foreach ($chunk as $learnerId) {
                foreach ($wordIds as $index => $wordId) {
                    $repetitions = 2 + ($index % 6);
                    $rows[] = [
                        'learner_id' => $learnerId,
                        'word_id' => $wordId,
                        'repetitions' => $repetitions,
                        'interval_days' => 6,
                        'easiness_factor' => 2.5,
                        'last_quality' => 4,
                        'last_reviewed_at' => $now->copy()->subDays(6)->toDateTimeString(),
                        'next_review_at' => $due,
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

            foreach (array_chunk($rows, self::UPSERT_ROWS) as $batch) {
                DB::table('word_repetitions')->upsert(
                    $batch,
                    ['learner_id', 'word_id'],
                    ['repetitions', 'interval_days', 'easiness_factor', 'last_quality', 'last_reviewed_at', 'next_review_at', 'is_hard', 'state', 'learning_step', 'lapses', 'scheduler', 'updated_at'],
                );
            }
        }
    }

    /**
     * @param  list<int>  $learnerIds
     * @return array{0: int, 1: int, 2: int} new, already friends, failed
     */
    private function befriend(array $learnerIds): array
    {
        $friends = app(FriendshipService::class);
        $made = $existing = $failed = 0;

        foreach (array_chunk($learnerIds, 2) as $pair) {
            if (count($pair) < 2) {
                break;
            }

            $first = Learner::query()->findOrFail($pair[0]);
            $second = Learner::query()->findOrFail($pair[1]);

            if ($friends->areFriends($first, $second)) {
                $existing++;

                continue;
            }

            try {
                $friendship = $friends->request($first, $second);
                $friends->accept($second, (int) $friendship->id);
                $made++;
            } catch (FriendshipException) {
                $failed++;
            }
        }

        return [$made, $existing, $failed];
    }

    private function say(string $message): void
    {
        if (isset($this->command)) {
            $this->command->info($message);
        }
    }

    /** A variable of the process environment ("" when unset): the seeder is a tool, not config - `env()` is empty once the config is cached. */
    private function setting(string $name): string
    {
        $value = getenv($name);

        return $value === false ? '' : $value;
    }
}
