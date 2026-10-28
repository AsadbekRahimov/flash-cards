<?php

declare(strict_types=1);

/*
 * The learner's progress in the catalog (`GET /courses`, the deck list, `GET /today` -> active_course):
 * `done` of `total` ACTIVE words of a course, "done" being a word the learner answered at least once
 * (`word_repetitions.repetitions >= 1`).
 *
 * The numbers are pinned against `legacyCatalogProgress()`, the original query that scanned the words of
 * the whole catalog on every open, so counting them from the course counter and the learner's own rows
 * cannot change a single figure that the API answers.
 */

use App\Domain\Content\DTO\CourseListing;
use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Twa\Services\JwtService;
use App\Models\Course;
use App\Models\Learner;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\HotPathFixtures as Fx;

uses(RefreshDatabase::class);

/**
 * The scan of the words of the catalog before the rewrite, verbatim.
 *
 * @param  list<int>  $courseIds
 * @return array<int, array{done: int, total: int}> course id => progress (a course without active words is absent)
 */
function legacyCatalogProgress(Learner $learner, array $courseIds): array
{
    $rows = DB::table('words')
        ->join('lessons', 'lessons.id', '=', 'words.lesson_id')
        ->join('stages', 'stages.id', '=', 'lessons.stage_id')
        ->leftJoin('word_repetitions', function (JoinClause $join) use ($learner): void {
            $join->on('word_repetitions.word_id', '=', 'words.id')
                ->where('word_repetitions.learner_id', '=', $learner->id)
                ->where('word_repetitions.repetitions', '>=', 1);
        })
        ->where('words.is_active', true)
        ->whereIn('stages.course_id', $courseIds)
        ->groupBy('stages.course_id')
        ->selectRaw('stages.course_id as course_id, COUNT(words.id) as total, COUNT(word_repetitions.id) as done')
        ->get();

    $progress = [];
    foreach ($rows as $row) {
        $progress[(int) $row->course_id] = ['done' => (int) $row->done, 'total' => (int) $row->total];
    }

    return $progress;
}

/**
 * What the listings say, keyed by course id.
 *
 * @param  list<CourseListing>  $listings
 * @return array<int, array{done: int, total: int}>
 */
function catalogProgressOf(array $listings): array
{
    $progress = [];
    foreach ($listings as $listing) {
        $progress[$listing->course->id] = ['done' => $listing->progress->done, 'total' => $listing->progress->total];
    }

    return $progress;
}

/**
 * What the original scan says for the same courses, with the "no active words" default of the old callers.
 *
 * @param  list<int>  $courseIds
 * @return array<int, array{done: int, total: int}>
 */
function legacyProgressFor(Learner $learner, array $courseIds): array
{
    $scan = legacyCatalogProgress($learner, $courseIds);
    $progress = [];
    foreach ($courseIds as $id) {
        $progress[$id] = $scan[$id] ?? ['done' => 0, 'total' => 0];
    }

    return $progress;
}

it('counts done and total of every course of the list like the original catalog scan', function (): void {
    // A: an inactive word, one served-only card, one learned card of a retired word. B: untouched. C: no words at all.
    ['course' => $a, 'words' => $aw] = Fx::course([1 => [1 => 4, 2 => [3, 2]]], ['position' => 1]);
    ['course' => $b] = Fx::course([1 => [1 => 2], 2 => [1 => 5]], ['position' => 2]);
    $c = Course::factory()->published()->create(['position' => 3]);
    ['course' => $d, 'words' => $dw] = Fx::course([1 => [1 => 3]], ['position' => 4]);

    $learner = Learner::factory()->create();
    $classmate = Learner::factory()->create();
    Fx::learn($learner, array_slice($aw['1.1']['active'], 0, 2));                    // two answered
    Fx::learn($learner, [$aw['1.1']['active'][2]], repetitions: 0);                    // served, not answered: not "done"
    Fx::learn($learner, [$aw['1.2']['active'][0], $aw['1.2']['inactive'][0]]);         // one active, one RETIRED word answered
    Fx::learn($learner, $dw['1.1']['active']);                                         // course D completed
    Fx::learn($classmate, [...$aw['1.1']['active'], ...$aw['1.2']['active']]);         // somebody else's rows

    $ids = [$a->id, $b->id, $c->id, $d->id];
    $listings = app(CourseCatalogService::class)->forLearner($learner);

    expect(catalogProgressOf($listings))->toBe(legacyProgressFor($learner, $ids))
        // The figures are what the fixture says, not just equal to each other.
        ->and(catalogProgressOf($listings))->toBe([
            $a->id => ['done' => 3, 'total' => 7],
            $b->id => ['done' => 0, 'total' => 7],
            $c->id => ['done' => 0, 'total' => 0],
            $d->id => ['done' => 3, 'total' => 3],
        ]);
});

it('counts the same for a single listing, a list of decks and the learner\'s unlisted active course', function (): void {
    $learner = Learner::factory()->create();
    ['course' => $deck, 'words' => $deckWords] = Fx::course([1 => [1 => [4, 1]]], [
        'owner_type' => 'learner', 'owner_id' => $learner->id, 'source' => 'learner', 'visibility' => 'private',
    ]);
    ['course' => $unlisted, 'words' => $unlistedWords] = Fx::course([1 => [1 => 2, 2 => 2]], ['visibility' => 'unlisted']);
    ['course' => $public] = Fx::course([1 => [1 => 1]]);
    Fx::learn($learner, array_slice($deckWords['1.1']['active'], 0, 3));
    Fx::learn($learner, [$deckWords['1.1']['inactive'][0]]);
    Fx::learn($learner, [$unlistedWords['1.1']['active'][0]]);
    $learner->forceFill(['active_course_id' => $unlisted->id])->save();

    $catalog = app(CourseCatalogService::class);

    expect(catalogProgressOf([$catalog->listing($deck, $learner)]))->toBe(legacyProgressFor($learner, [$deck->id]))
        ->and(catalogProgressOf([$catalog->listing($deck, $learner)]))->toBe([$deck->id => ['done' => 3, 'total' => 4]])
        ->and(catalogProgressOf($catalog->listings([$deck, $unlisted], $learner)))->toBe(legacyProgressFor($learner, [$deck->id, $unlisted->id]))
        // `unlisted` is not in the public list, but it is the learner's active course: listed, with its progress.
        ->and(catalogProgressOf($catalog->forLearner($learner)))->toBe(legacyProgressFor($learner, [$public->id, $unlisted->id]))
        ->and(catalogProgressOf($catalog->forLearner($learner))[$unlisted->id])->toBe(['done' => 1, 'total' => 4]);
});

it('says the same over HTTP: GET /courses progress of each course', function (): void {
    ['course' => $a, 'words' => $aw] = Fx::course([1 => [1 => 3, 2 => [2, 1]]], ['position' => 1]);
    ['course' => $b] = Fx::course([1 => [1 => 2]], ['position' => 2]);
    $learner = Learner::factory()->create();
    Fx::learn($learner, [...array_slice($aw['1.1']['active'], 0, 2), $aw['1.2']['inactive'][0]]);

    $rows = collect($this->withHeaders(['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']])
        ->getJson('/api/twa/courses')->assertOk()->json('courses'))->keyBy('slug');

    expect($rows[$a->slug]['progress'])->toBe(['done' => 2, 'total' => 5])
        ->and($rows[$b->slug]['progress'])->toBe(['done' => 0, 'total' => 2])
        ->and($rows[$a->slug]['words_count'])->toBe(5);
});

it('agrees with the original scan on random catalogs and progress', function (): void {
    $mismatches = [];
    $seenDone = [];

    foreach (range(1, 40) as $seed) {
        $rng = Fx::rng($seed);
        $learner = Learner::factory()->create();
        $other = Learner::factory()->create();

        // 1-4 courses of 1-3 stages x 1-3 lessons, 0-3 active and 0-2 retired words per lesson.
        $ids = [];
        $allWords = [];
        foreach (range(1, $rng(1, 4)) as $position) {
            $layout = [];
            foreach (Fx::scramble(range(1, $rng(1, 3)), $rng) as $stageNumber) {
                foreach (Fx::scramble(range(1, $rng(1, 3)), $rng) as $lessonNumber) {
                    $layout[$stageNumber][$lessonNumber] = [$rng(0, 3), $rng(0, 2)];
                }
            }
            ['course' => $course, 'words' => $words] = Fx::course($layout, ['position' => $position + $seed * 10]);
            $ids[] = $course->id;
            foreach ($words as $lessonWords) {
                array_push($allWords, ...Fx::all($lessonWords));
            }
        }

        foreach ($allWords as $wordId) {
            $roll = $rng(1, 10);
            if ($roll <= 3) {
                Fx::learn($learner, [$wordId], repetitions: 0);
            } elseif ($roll <= 7) {
                Fx::learn($learner, [$wordId], repetitions: $rng(1, 4));
            }
            if ($rng(1, 3) === 1) {
                Fx::learn($other, [$wordId], repetitions: $rng(0, 3));
            }
        }

        $actual = catalogProgressOf(app(CourseCatalogService::class)->forLearner($learner));
        // The catalog of this seed is the courses just built plus the ones of the earlier seeds.
        $expected = legacyProgressFor($learner, array_keys($actual));
        if ($actual !== $expected) {
            $mismatches[] = $seed;
        }
        $seenDone[] = array_sum(array_column($actual, 'done'));
    }

    expect($mismatches)->toBe([])
        // Not a degenerate generator: some learners have progress, some have none.
        ->and(max($seenDone))->toBeGreaterThan(0)
        ->and(count(array_unique($seenDone)))->toBeGreaterThan(5);
});
