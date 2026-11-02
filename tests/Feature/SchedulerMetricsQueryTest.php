<?php

declare(strict_types=1);

use App\Domain\Learning\Enums\SchedulerKind;
use App\Domain\Learning\Services\SchedulerMetricsService;
use App\Models\Learner;
use App\Models\TrainingSession;
use App\Models\Word;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\QueryLog;

uses(RefreshDatabase::class);

/*
 * The D7 retention of the SM-2 / FSRS comparison (docs/13 §2.5, docs/06 §9.6) is ONE aggregate statement (docs/20 F7, docs/19 C8):
 * the cohort and, for each learner of it, an EXISTS over `training_reviews (learner_id, created_at)`. It must answer what the chunked
 * version answered (a `MAX(created_at)` per chunk of 1000 learners) down to the second, so that version is kept here as the reference,
 * and the statement count must not depend on the size of the cohort. The tests run on SQLite and on PostgreSQL (CI), which write
 * the date arithmetic differently.
 */

const SMQ_NOW = '2026-10-20 12:00:00';

/**
 * One learner of a test set: the bucket as stored (NULL = the config default), when he signed up, whether he was deleted since,
 * and the moments he graded a card.
 *
 * @param  list<CarbonImmutable>  $grades
 * @return array{scheduler: ?string, created_at: CarbonImmutable, deleted: bool, grades: list<CarbonImmutable>}
 */
function smqCase(?string $scheduler, CarbonImmutable $createdAt, array $grades = [], bool $deleted = false): array
{
    return ['scheduler' => $scheduler, 'created_at' => $createdAt, 'deleted' => $deleted, 'grades' => $grades];
}

/**
 * Learners and their grades by bulk INSERT in chunks (a factory per row would take seconds for a crowd).
 *
 * @param  list<array{scheduler: ?string, created_at: CarbonImmutable, deleted: bool, grades: list<CarbonImmutable>}>  $cases
 */
function smqInsert(array $cases): void
{
    $session = TrainingSession::factory()->create();
    $word = Word::factory()->create();
    $base = 800_000_000;

    foreach (array_chunk($cases, 250, true) as $chunk) {
        DB::table('learners')->insert(array_map(static fn (array $case, int $i): array => [
            'telegram_user_id' => $base + $i,
            'scheduler' => $case['scheduler'],
            'created_at' => $case['created_at'],
            'updated_at' => $case['created_at'],
            'deleted_at' => $case['deleted'] ? $case['created_at']->addDay() : null,
        ], $chunk, array_keys($chunk)));
    }

    $ids = DB::table('learners')->pluck('id', 'telegram_user_id');
    $grades = [];
    foreach ($cases as $i => $case) {
        foreach ($case['grades'] as $at) {
            $grades[] = [
                'training_session_id' => $session->id,
                'learner_id' => $ids->get($base + $i),
                'word_id' => $word->id,
                'quality' => 4,
                'time_spent_ms' => 1_000,
                'created_at' => $at,
            ];
        }
    }

    foreach (array_chunk($grades, 250) as $chunk) {
        DB::table('training_reviews')->insert($chunk);
    }
}

/**
 * Learners whose answer is not obvious, around `$now = SMQ_NOW`: the cohort is [now - 28 days, now - 7 days] = [2026-09-22 12:00:00,
 * 2026-10-13 12:00:00], both ends inside.
 *
 * With the default sm2: sm2 = C, E, F, G (4, retained C, F); fsrs = A, H, K (3, retained A, H).
 * With the default fsrs: E and F move to fsrs.
 *
 * @return list<array{scheduler: ?string, created_at: CarbonImmutable, deleted: bool, grades: list<CarbonImmutable>}>
 */
function smqBoundaryCases(): array
{
    $now = CarbonImmutable::parse(SMQ_NOW);
    $since = $now->subDays(28);
    $cohortEnd = $now->subDays(7);
    $at = static fn (string $time): CarbonImmutable => CarbonImmutable::parse($time);

    return [
        // A: signed up the second the window opens, graded exactly 7 days later: in, retained.
        smqCase('fsrs', $since, [$since->addDays(7)]),
        // B: a second before the window: out, however late he graded.
        smqCase('fsrs', $since->subSecond(), [$since->addDays(30)]),
        // C: signed up the second the cohort ends (his 7th day is "now"), graded on it: in, retained.
        smqCase('sm2', $cohortEnd, [$cohortEnd->addDays(7)]),
        // D: a second after the cohort ends: out.
        smqCase('sm2', $cohortEnd->addSecond(), [$cohortEnd->addDays(9)]),
        // E: no bucket (the config default), a grade a second short of 7 days: in, NOT retained.
        smqCase(null, $at('2026-10-01 00:00:00'), [$at('2026-10-07 23:59:59')]),
        // F: no bucket, a grade exactly 7 days after: in, retained.
        smqCase(null, $at('2026-10-01 00:00:01'), [$at('2026-10-08 00:00:01')]),
        // G: never graded: in the cohort only.
        smqCase('sm2', $at('2026-10-02 10:00:00')),
        // H: an early grade and a late one: the late one counts.
        smqCase('fsrs', $at('2026-10-03 10:00:00'), [$at('2026-10-04 10:00:00'), $at('2026-10-23 10:00:00')]),
        // I, J: deleted learners with late grades: not in the cohort at all.
        smqCase('sm2', $at('2026-10-04 10:00:00'), [$at('2026-10-14 10:00:00')], deleted: true),
        smqCase(null, $at('2026-10-05 10:00:00'), [$at('2026-10-15 10:00:00')], deleted: true),
        // K: a grade before he signed up and one a second short of 7 days: NOT retained.
        smqCase('fsrs', $at('2026-10-06 10:00:00'), [$at('2026-10-03 10:00:00'), $at('2026-10-13 09:59:59')]),
    ];
}

/**
 * A crowd around the window: signed up over 25 days (the 21 of the cohort and some on either side) in an order unrelated to the ids,
 * the three buckets, every 29th deleted, and grades clustered around the 7-day line: none, a second short of it, exactly on it,
 * a second past it, early and late ones, a far one.
 *
 * @return list<array{scheduler: ?string, created_at: CarbonImmutable, deleted: bool, grades: list<CarbonImmutable>}>
 */
function smqCrowd(int $count): array
{
    $start = CarbonImmutable::parse(SMQ_NOW)->subDays(30);
    $buckets = ['sm2', 'fsrs', null];
    $cases = [];

    for ($i = 0; $i < $count; $i++) {
        $created = $start->addSeconds(intdiv(($i * 7919 % $count) * 25 * 86_400, $count));
        $line = $created->addDays(SchedulerMetricsService::RETENTION_DAY);

        $cases[] = smqCase($buckets[$i % 3], $created, match ($i % 7) {
            0 => [],
            1 => [$line->subSecond()],
            2 => [$line],
            3 => [$line->addSecond()],
            4 => [$created->addDay(), $created->addDays(3 + $i % 20)],
            5 => [$created->subDay(), $line->subSeconds(2)],
            default => [$line->addDays(40)],
        }, deleted: $i % 29 === 0);
    }

    return $cases;
}

/**
 * The retention of the version before the single statement, kept as the reference: the cohort in chunks of 1000 learners by id, the
 * newest grade of each chunk by `MAX(created_at)`, retained = the newest grade is not before `created_at + 7 days`.
 *
 * @return array<string, array{cohort: int, retained: int}>
 */
function smqReferenceRetentionD7(CarbonImmutable $now, string $default): array
{
    $since = $now->subDays(SchedulerMetricsService::WINDOW_DAYS);
    $cohortEnd = $now->subDays(SchedulerMetricsService::RETENTION_DAY);

    /** @var array<string, int> $cohort */
    $cohort = [];
    /** @var array<string, int> $retained */
    $retained = [];
    foreach (SchedulerKind::cases() as $kind) {
        $cohort[$kind->value] = 0;
        $retained[$kind->value] = 0;
    }

    Learner::query()
        ->whereBetween('created_at', [$since, $cohortEnd])
        ->select(['id', 'created_at', 'scheduler'])
        ->chunkById(1000, function ($learners) use (&$cohort, &$retained, $default): void {
            /** @var array<int, CarbonImmutable> $createdAt */
            $createdAt = [];
            /** @var array<int, string> $bucket */
            $bucket = [];
            /** @var Learner $learner */
            foreach ($learners as $learner) {
                $createdAt[$learner->id] = CarbonImmutable::instance($learner->created_at ?? CarbonImmutable::now());
                $bucket[$learner->id] = $learner->scheduler instanceof SchedulerKind ? $learner->scheduler->value : $default;
                $cohort[$bucket[$learner->id]] = ($cohort[$bucket[$learner->id]] ?? 0) + 1;
            }

            $lastReview = DB::table('training_reviews')
                ->whereIn('learner_id', array_keys($createdAt))
                ->groupBy('learner_id')
                ->select('learner_id')
                ->selectRaw('MAX(created_at) AS last_at')
                ->get();

            foreach ($lastReview as $row) {
                $learnerId = (int) $row->learner_id;
                $lastAt = CarbonImmutable::parse((string) $row->last_at);
                if (! $lastAt->lessThan($createdAt[$learnerId]->addDays(SchedulerMetricsService::RETENTION_DAY))) {
                    $retained[$bucket[$learnerId]] = ($retained[$bucket[$learnerId]] ?? 0) + 1;
                }
            }
        });

    $out = [];
    foreach ($cohort as $kind => $count) {
        $out[$kind] = ['cohort' => $count, 'retained' => $retained[$kind] ?? 0];
    }

    return $out;
}

it('counts the boundaries of the cohort and of the 7th day to the second, the deleted out and the learners without a bucket in the default arm', function (string $default, array $expected): void {
    config(['learning.algorithm' => $default]);
    smqInsert(smqBoundaryCases());

    $metrics = app(SchedulerMetricsService::class)->compare(CarbonImmutable::parse(SMQ_NOW));

    $got = [];
    foreach (SchedulerKind::cases() as $kind) {
        $got[$kind->value] = [$metrics[$kind->value]->cohortLearners, $metrics[$kind->value]->retainedD7];
    }

    expect($got)->toBe($expected);
})->with([
    'default sm2' => ['sm2', ['sm2' => [4, 2], 'fsrs' => [3, 2]]],
    'default fsrs' => ['fsrs', ['sm2' => [2, 1], 'fsrs' => [5, 3]]],
]);

it('answers what the chunked MAX() version answered, on a crowd that crosses its chunks of 1000 learners', function (string $default): void {
    config(['learning.algorithm' => $default]);
    $now = CarbonImmutable::parse(SMQ_NOW);
    smqInsert([...smqBoundaryCases(), ...smqCrowd(1_500)]);

    $reference = smqReferenceRetentionD7($now, $default);
    $metrics = app(SchedulerMetricsService::class)->compare($now);

    // The set is what the test needs it to be: more than one chunk of the reference, both arms with some retained and some lost.
    expect(array_sum(array_column($reference, 'cohort')))->toBeGreaterThan(1_000);

    foreach (SchedulerKind::cases() as $kind) {
        $expected = $reference[$kind->value];

        expect($expected['retained'])->toBeGreaterThan(0)->toBeLessThan($expected['cohort'])
            ->and($metrics[$kind->value]->cohortLearners)->toBe($expected['cohort'], "{$kind->value}: cohort")
            ->and($metrics[$kind->value]->retainedD7)->toBe($expected['retained'], "{$kind->value}: retained");
    }
})->with(['default sm2' => ['sm2'], 'default fsrs' => ['fsrs']]);

it('has both arms with zeros while there is no cohort', function (): void {
    $metrics = app(SchedulerMetricsService::class)->compare(CarbonImmutable::parse(SMQ_NOW));

    expect(array_keys($metrics))->toBe(['sm2', 'fsrs']);

    foreach ($metrics as $arm) {
        expect($arm->cohortLearners)->toBe(0)
            ->and($arm->retainedD7)->toBe(0)
            ->and($arm->retentionD7())->toBeNull();
    }
});

it('runs the comparison in exactly three statements, whatever the size of the cohort', function (int $learners): void {
    smqInsert(smqCrowd($learners));

    $queries = QueryLog::during(fn () => app(SchedulerMetricsService::class)->compare(CarbonImmutable::parse(SMQ_NOW)));

    expect($queries)->toHaveCount(3, implode("\n", $queries));

    // The retention is one of the three, and no value is spliced into its text: the dates and the 7 days are bindings.
    $retention = QueryLog::matching($queries, '/exists \(select 1 from ["`]?training_reviews["`]?/i');

    expect($retention)->toHaveCount(1)
        ->and($retention[0])->not->toMatch('/2026|7 days|interval \'/i');
})->with([
    '10 learners' => [10],
    '1500 learners' => [1_500],
]);
