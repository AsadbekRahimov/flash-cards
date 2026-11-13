<?php

declare(strict_types=1);

use App\Domain\Practice\DTO\LocaleSet;
use App\Domain\Practice\DTO\MaterialScope;
use App\Domain\Practice\DTO\RetryMaterial;
use App\Domain\Practice\DTO\Snapshot;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Exceptions\BattleException;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\PracticeMaterialService;
use App\Domain\Practice\Services\PracticeSnapshotBuilder;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\PracticeSession;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Practice\BattleKit;
use Tests\Support\Practice\DictationKit;
use Tests\Support\Practice\PracticeKit;
use Tests\Support\QueryLog;

/*
 * A session or a battle on a WHOLE course is built from a sample of `practice.play_sample` words chosen by the seed of its snapshot, not
 * from every word the learner has started (a battle: every active word of the course). A lesson, a module and a retry are read whole.
 * What the sample cannot build is built again from all the words.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    PracticeKit::openAccess();
    Storage::fake('practice_audio');
    config()->set('practice.play_sample', 10);
});

/**
 * @param  Closure(int): bool|null  $fit  false = a translation no card can show
 * @return list<Word>
 */
function playWords(Lesson $lesson, ?Learner $learner, int $count, ?Closure $fit = null, int $from = 1): array
{
    $words = [];
    for ($i = $from; $i < $from + $count; $i++) {
        $word = Word::factory()->for($lesson)->create(['word' => "play{$lesson->id}x{$i}", 'translation' => ($fit === null || $fit($i)) ? "перевод {$lesson->id}x{$i}" : '(разг.)']);
        if ($learner !== null) {
            PracticeKit::introduce($learner, $word);
        }
        $words[] = $word;
    }

    return $words;
}

/** @return list<int> the ids of the words a snapshot is made of (elements and spares), sorted */
function playWordIds(Snapshot $snapshot): array
{
    $ids = [];
    foreach ([...$snapshot->elements, ...array_map(static fn (array $spare): Closure => static fn (): array => $spare, $snapshot->pool)] as $element) {
        $data = $element instanceof Closure ? $element() : $element->data;
        foreach ((array) ($data['left'] ?? []) as $card) {
            $ids[] = (int) $card['word_id'];
        }
        if (($data['word_id'] ?? null) !== null) {
            $ids[] = (int) $data['word_id'];
        }
    }
    sort($ids);

    return array_values(array_unique($ids));
}

function playSnapshot(PracticeMode $mode, MaterialScope $scope, ?Learner $learner, string $seed, ?LocaleSet $locales = null): Snapshot
{
    $battle = $learner === null;

    return app(PracticeSnapshotBuilder::class)->build($mode, $scope, $learner, $locales ?? LocaleSet::of('ru'), $battle ? 5 : ($mode === PracticeMode::Pairs ? 3 : 5), $battle, seed: $seed);
}

/** @return list<string> the statements that read whole rows of words */
function playRowQueries(array $queries): array
{
    return QueryLog::matching($queries, '/^select ["`]words["`]\.["`]id["`], ["`]words["`]\.["`]lesson_id["`]/');
}

/** @return list<string> the statements that read only the ids of words */
function playIdQueries(array $queries): array
{
    return QueryLog::matching($queries, '/^select ["`]words["`]\.["`]id["`] from/');
}

/** @return list<string> the statements of the chosen words (by id), and the probes (with a limit) - the two that read whole rows of a sample */
function playChosenQueries(array $queries): array
{
    return array_values(array_filter(playRowQueries($queries), static fn (string $sql): bool => str_contains($sql, '"words"."id" in (')));
}

/** @return list<string> */
function playProbeQueries(array $queries): array
{
    return array_values(array_filter(playRowQueries($queries), static fn (string $sql): bool => ! str_contains($sql, '"words"."id" in (')));
}

function playPlaceholders(string $sql): int
{
    return substr_count((string) preg_replace('/^.*?["`]words["`]\.["`]id["`] in \(/s', '', $sql, 1, $found), '?');
}

it('puts the sample on the scope of a whole course only', function (): void {
    [$course, $lesson] = PracticeKit::course();
    $retry = new RetryMaterial([1], []);

    $whole = MaterialScope::forPlay($course, null, null, 'seed');
    expect($whole->playSeed)->toBe('seed')->and($whole->playSample)->toBe(10)->and($whole->wordSample)->toBeNull()->and($whole->countOnly)->toBeFalse()
        ->and(MaterialScope::forPlay($course, $lesson, null, 'seed')->playSeed)->toBeNull()
        ->and(MaterialScope::forPlay($course, null, $retry, 'seed')->playSeed)->toBeNull()
        ->and(MaterialScope::forStage($course, $lesson->stage)->playSeed)->toBeNull()
        ->and(MaterialScope::forCount($course)->playSeed)->toBeNull()
        ->and($whole->withoutPlaySample()->playSeed)->toBeNull();

    config()->set('practice.play_sample', 0);
    expect(MaterialScope::forPlay($course, null, null, 'seed')->playSeed)->toBeNull();
});

it('opens a pairs session over a bounded sample of the started words', function (): void {
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create(['interface_locale' => 'ru']);
    $words = playWords($lesson, $learner, 40);

    $response = null;
    $queries = QueryLog::during(function () use (&$response, $course, $learner): void {
        $response = test()->withHeaders(PracticeKit::headers($learner))->postJson('/api/twa/practice/sessions', ['mode' => 'pairs', 'course' => $course->slug]);
    });
    $response->assertCreated();

    // The probe (a limit), the ids, the chosen words.
    $rows = playChosenQueries($queries);
    expect($rows)->toHaveCount(1)->and(playPlaceholders($rows[0]))->toBeLessThanOrEqual(10)
        ->and(playProbeQueries($queries))->toHaveCount(1)->and(playProbeQueries($queries)[0])->toContain('limit')
        ->and(playIdQueries($queries))->toHaveCount(1);
    foreach (playIdQueries($queries) as $sql) {
        expect($sql)->not->toContain('"words"."id" in (');
    }
    foreach (QueryLog::matching($queries, '/word_translations/') as $sql) {
        expect(substr_count($sql, '?'))->toBeLessThanOrEqual(10 + 2);
    }

    $session = PracticeSession::query()->firstOrFail();
    $ids = playWordIds(Snapshot::fromArray($session->snapshot));
    expect($ids)->not->toBeEmpty()->and(count($ids))->toBeLessThanOrEqual(10)
        ->and(array_diff($ids, array_map(static fn (Word $w): int => $w->id, $words)))->toBe([]);
});

it('reads the whole list of a learner who has not more words than the sample, as before', function (): void {
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create(['interface_locale' => 'ru']);
    playWords($lesson, $learner, 10);
    $locales = LocaleSet::of('ru');

    $played = playSnapshot(PracticeMode::Pairs, MaterialScope::forPlay($course, null, null, 'seed'), $learner, 'seed');
    $plain = playSnapshot(PracticeMode::Pairs, new MaterialScope($course), $learner, 'seed');
    $queries = QueryLog::during(fn () => app(PracticeMaterialService::class)->introducedWords(MaterialScope::forPlay($course, null, null, 'seed'), $learner, $locales));

    // The ids of the elements are random, the words of the rounds are not.
    expect(playWordIds($played))->toBe(playWordIds($plain))
        ->and(playRowQueries($queries))->toHaveCount(1)
        ->and(playIdQueries($queries))->toBe([])
        ->and(playChosenQueries($queries))->toBe([])
        ->and(playRowQueries($queries)[0])->toContain('limit');
});

it('gives the same snapshot for the same seed and other words for other seeds', function (): void {
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create(['interface_locale' => 'ru']);
    $started = playWords($lesson, $learner, 100);
    $ids = array_map(static fn (Word $w): int => $w->id, $started);

    $sets = [];
    $union = [];
    foreach (range(1, 30) as $n) {
        $seed = "seed-{$n}";
        $one = playWordIds(playSnapshot(PracticeMode::Pairs, MaterialScope::forPlay($course, null, null, $seed), $learner, $seed));
        $two = playWordIds(playSnapshot(PracticeMode::Pairs, MaterialScope::forPlay($course, null, null, $seed), $learner, $seed));
        expect($one)->toBe($two)->and(array_diff($one, $ids))->toBe([]);
        $sets[implode(',', $one)] = true;
        $union = array_unique([...$union, ...$one]);
    }

    // Not the first words by the id only: the sample is spread over the whole vocabulary.
    sort($union);
    expect(count($sets))->toBeGreaterThan(20)
        ->and(count($union))->toBeGreaterThan(60)
        ->and($union[0])->toBeLessThanOrEqual($ids[24])
        ->and(end($union))->toBeGreaterThanOrEqual($ids[75]);
});

it('opens a dictation over a sample of the VOICED started words, however many are not', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    for ($i = 1; $i <= 30; $i++) {
        DictationKit::word($lesson, $learner, "plain{$i}", "tr{$i}", voiced: false);
    }
    $voiced = [];
    for ($i = 1; $i <= 14; $i++) {
        $voiced[] = DictationKit::word($lesson, $learner, "voiced{$i}", "vtr{$i}")->id;
    }

    $response = null;
    $queries = QueryLog::during(function () use (&$response, $course, $learner): void {
        $response = test()->withHeaders(PracticeKit::headers($learner))->postJson('/api/twa/practice/sessions', ['mode' => 'dictation', 'course' => $course->slug]);
    });
    $response->assertCreated();

    $rows = playChosenQueries($queries);
    expect($rows)->toHaveCount(1)->and(playPlaceholders($rows[0]))->toBeLessThanOrEqual(10)->and(playProbeQueries($queries))->toHaveCount(1);
    foreach (playIdQueries($queries) as $sql) {
        expect($sql)->not->toContain('"words"."id" in (')->and($sql)->toMatch('/audio_path["`] is not null/');
    }
    foreach (QueryLog::matching($queries, '/word_translations/') as $sql) {
        expect(substr_count($sql, '?'))->toBeLessThanOrEqual(10 + 2);
    }

    $ids = playWordIds(Snapshot::fromArray(PracticeSession::query()->firstOrFail()->snapshot));
    expect(count($ids))->toBeGreaterThanOrEqual(5)->and(array_diff($ids, $voiced))->toBe([]);
});

it('creates a battle from a sample of the active words of the course, once for both players', function (): void {
    config()->set('practice.play_sample', 25);
    [$me, , $course, , $friendship] = BattleKit::world(words: 60, items: 0);
    $active = Word::query()->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

    $battle = null;
    $queries = QueryLog::during(function () use (&$battle, $me, $friendship, $course): void {
        $battle = BattleKit::invite($me, $friendship, PracticeMode::Pairs, $course);
    });

    $rows = playChosenQueries($queries);
    expect($rows)->toHaveCount(1)->and(playPlaceholders($rows[0]))->toBeLessThanOrEqual(25)
        ->and(playProbeQueries($queries))->toHaveCount(1)->and(playProbeQueries($queries)[0])->toContain('limit')
        ->and(playIdQueries($queries))->toHaveCount(1);
    foreach (playIdQueries($queries) as $sql) {
        expect($sql)->not->toContain('"words"."id" in (');
    }
    foreach (QueryLog::matching($queries, '/word_translations/') as $sql) {
        expect(substr_count($sql, '?'))->toBeLessThanOrEqual(25 + 2);
    }

    $snapshot = Snapshot::fromArray($battle->snapshot);
    expect($snapshot->elements)->toHaveCount(5)->and($snapshot->locales)->toBe(['ru', 'uz'])
        ->and(array_diff(playWordIds($snapshot), $active))->toBe([]);
    foreach ($snapshot->elements as $round) {
        expect($round->get('right')[0]['text'])->toHaveKeys(['ru', 'uz']);
    }
});

it('builds from all the words when the sample cannot, with the same seed', function (): void {
    config()->set('practice.play_sample', 2);
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create(['interface_locale' => 'ru']);
    // Three words that fit among thirty: a sample of two cannot make a round, all the words can.
    $started = playWords($lesson, $learner, 30, fn (int $i): bool => $i > 27);

    $response = test()->withHeaders(PracticeKit::headers($learner))->postJson('/api/twa/practice/sessions', ['mode' => 'pairs', 'course' => $course->slug]);

    $response->assertCreated();
    $ids = playWordIds(Snapshot::fromArray(PracticeSession::query()->firstOrFail()->snapshot));
    expect($ids)->toBe(array_map(static fn (Word $w): int => $w->id, array_slice($started, 27)));
});

it('refuses with the numbers of the whole material when even all the words cannot build', function (): void {
    config()->set('practice.play_sample', 2);
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create(['interface_locale' => 'ru']);
    playWords($lesson, $learner, 30, fn (int $i): bool => $i > 28);
    $open = fn () => test()->withHeaders(PracticeKit::headers($learner))->postJson('/api/twa/practice/sessions', ['mode' => 'pairs', 'course' => $course->slug]);

    $sampled = $open();
    config()->set('practice.play_sample', 0);
    $whole = $open();

    $sampled->assertStatus(422);
    expect($sampled->json())->toBe($whole->json())->and($sampled->json('error.code'))->toBe(PracticeException::NOT_ENOUGH_MATERIAL);
    expect(PracticeSession::query()->count())->toBe(0);
});

it('creates a battle from all the words when the sample cannot, and refuses like the whole material when they cannot', function (): void {
    config()->set('practice.play_sample', 10);
    [$me, , $course, , $friendship] = BattleKit::world(words: 24, items: 0);

    $battle = BattleKit::invite($me, $friendship, PracticeMode::Pairs, $course);
    expect(Snapshot::fromArray($battle->snapshot)->elements)->toHaveCount(5);

    [$me2, , $course2, , $friendship2] = BattleKit::world(words: 12, items: 0, slug: 'english-b1');
    $refusal = static function () use ($me2, $friendship2, $course2): BattleException {
        try {
            BattleKit::invite($me2, $friendship2, PracticeMode::Pairs, $course2);
        } catch (BattleException $e) {
            return $e;
        }

        throw new LogicException('The battle was created.');
    };
    $sampled = $refusal();
    config()->set('practice.play_sample', 0);
    $whole = $refusal();

    expect($sampled->errorCode)->toBe(BattleException::MATERIAL_UNAVAILABLE)->and($sampled->errorCode)->toBe($whole->errorCode)->and($sampled->details)->toBe($whole->details)->and($sampled->getMessage())->toBe($whole->getMessage());
});

it('reads a lesson, a module and a retry without a sample', function (): void {
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create(['interface_locale' => 'ru']);
    $started = playWords($lesson, $learner, 30);
    $material = app(PracticeMaterialService::class);
    $retry = new RetryMaterial(array_map(static fn (Word $w): int => $w->id, array_slice($started, 0, 20)), []);

    $scopes = [
        'lesson' => [MaterialScope::forPlay($course, $lesson, null, 'seed'), 30],
        'module' => [MaterialScope::forStage($course, $lesson->stage), 30],
        'retry' => [MaterialScope::forPlay($course, null, $retry, 'seed'), 20],
    ];
    foreach ($scopes as $name => [$scope, $expected]) {
        $words = [];
        $queries = QueryLog::during(function () use (&$words, $material, $scope, $learner): void {
            $words = $material->introducedWords($scope, $learner, LocaleSet::of('ru'));
        });

        expect($words)->toHaveCount($expected, $name)->and(playIdQueries($queries))->toBe([], $name);
        foreach (playRowQueries($queries) as $sql) {
            expect($sql)->not->toContain('"words"."id" in (', $name);
        }
    }

    // A session of one lesson opens the same way.
    $queries = QueryLog::during(fn () => test()->withHeaders(PracticeKit::headers($learner))->postJson('/api/twa/practice/sessions', ['mode' => 'pairs', 'course' => $course->slug, 'lesson_id' => $lesson->id])->assertCreated());
    expect(playIdQueries($queries))->toBe([]);
});
