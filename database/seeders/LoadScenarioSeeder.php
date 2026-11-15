<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Engagement\Exceptions\FriendshipException;
use App\Domain\Engagement\Services\FriendshipService;
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
 *
 * Settings: LOAD_SCENARIO_COURSE (slug; default: the active course of the test learners, else the first published one),
 * LOAD_SCENARIO_WORDS. Nothing here touches a learner without the load-test mark.
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

        $learnerIds = $this->testLearnerIds();
        if ($learnerIds === []) {
            throw new RuntimeException('No test learners: run `php artisan load:tokens` first.');
        }

        $course = $this->course($learnerIds);
        $wordIds = $this->wordIds($course);
        if ($wordIds === []) {
            throw new RuntimeException('The course "'.$course->slug.'" has no active words.');
        }

        $this->introduce($learnerIds, $wordIds);
        Learner::query()->whereIn('id', $learnerIds)->update(['practice_pilot_at' => now(), 'active_course_id' => $course->id]);
        [$made, $existing, $failed] = $this->befriend($learnerIds);

        $this->say(sprintf(
            'Load scenarios: %d learners, %d due mature cards each (course %s), friends: %d new, %d already, %d failed.',
            count($learnerIds),
            count($wordIds),
            $course->slug,
            $made,
            $existing,
            $failed,
        ));
    }

    /**
     * Ids of the test learners, in the order of their numbers (telegram id = base + N).
     *
     * @return list<int>
     */
    private function testLearnerIds(): array
    {
        $ids = [];

        Learner::query()
            ->whereBetween('telegram_user_id', [LoadTestTokenIssuer::TELEGRAM_USER_BASE + 1, LoadTestTokenIssuer::TELEGRAM_USER_BASE + LoadTestTokenIssuer::MAX_LEARNERS])
            ->orderBy('telegram_user_id')
            ->get(['id', 'meta'])
            ->each(function (Learner $learner) use (&$ids): void {
                if (($learner->meta[LoadTestTokenIssuer::META_KEY] ?? false) === true) {
                    $ids[] = (int) $learner->id;
                }
            });

        return $ids;
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
