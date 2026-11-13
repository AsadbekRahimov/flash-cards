<?php

declare(strict_types=1);

use App\Domain\Content\Services\WordAudioService;
use App\Domain\Practice\DTO\LocaleSet;
use App\Domain\Practice\DTO\MaterialScope;
use App\Domain\Practice\Services\PracticeMaterialService;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\Support\Practice\BattleKit;
use Tests\Support\Practice\DictationKit;
use Tests\Support\Practice\PracticeKit;
use Tests\Support\QueryLog;

/*
 * The hub and the options of a battle count what the modes have over a SAMPLE of the words of a course (`practice.count_sample`),
 * not over all of them; a mode that is short on a cut sample is counted again over every word, so `state`, `reason` and the numbers of
 * a shortage are those of the full count.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    PracticeKit::openAccess();
    Storage::fake('practice_audio');
    config()->set('practice.count_sample', 5);
});

/** @return list<Word> words of the lesson, the learner introduced to them; `$fit(i)`: false = no translation to put on a card */
function sampleWords(Lesson $lesson, ?Learner $learner, int $count, ?Closure $fit = null, int $from = 1): array
{
    $words = [];
    for ($i = $from; $i < $from + $count; $i++) {
        $word = Word::factory()->for($lesson)->create(['word' => "sample{$lesson->id}x{$i}", 'translation' => ($fit === null || $fit($i)) ? "перевод {$lesson->id}x{$i}" : '(разг.)']);
        if ($learner !== null) {
            PracticeKit::introduce($learner, $word);
        }
        $words[] = $word;
    }

    return $words;
}

/** @return array<string, array{state: bool, available: int, required: int, reason: string|null}> */
function sampleReport(MaterialScope $scope, ?Learner $learner, LocaleSet $locales): array
{
    $report = [];
    foreach (app(PracticeMaterialService::class)->availability($scope, $learner, $locales) as $mode => $availability) {
        $report[$mode] = ['state' => $availability->isAvailable, ...$availability->toArray()];
    }

    return $report;
}

it('puts the sample on a scope of a whole course only', function (): void {
    [$course, $lesson] = PracticeKit::course();

    expect(MaterialScope::forCount($course)->wordSample)->toBe(5)
        ->and(MaterialScope::forCount($course, $lesson)->wordSample)->toBeNull()
        ->and((new MaterialScope($course))->wordSample)->toBeNull()
        ->and(MaterialScope::forCount($course)->withoutSample()->wordSample)->toBeNull()
        ->and(MaterialScope::forCount($course)->withoutSample()->countOnly)->toBeTrue();

    config()->set('practice.count_sample', 0);
    expect(MaterialScope::forCount($course)->wordSample)->toBeNull();
});

it('counts the hub of a course over the sample and reads the words with a limit', function (): void {
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create(['interface_locale' => 'ru']);
    sampleWords($lesson, $learner, 12);

    $response = null;
    $queries = QueryLog::during(function () use (&$response, $course, $learner): void {
        $response = test()->withHeaders(PracticeKit::headers($learner))->getJson("/api/twa/practice/hub?course={$course->slug}");
    });
    $response->assertOk();

    $pairs = collect($response->json('modes'))->firstWhere('mode', 'pairs');
    expect($pairs['state'])->toBe('available')->and($pairs['available'])->toBe(5)->and($pairs['required'])->toBe(3);

    $wordQueries = QueryLog::matching($queries, '/from ["`]words["`]/');
    expect($wordQueries)->not->toBeEmpty();
    foreach ($wordQueries as $sql) {
        expect($sql)->toContain('limit');
    }
    foreach (QueryLog::matching($queries, '/word_translations/') as $sql) {
        expect(substr_count($sql, '?'))->toBeLessThanOrEqual(5 + 1 + 1);
    }
});

it('counts a course that is not bigger than the sample as before', function (): void {
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create(['interface_locale' => 'ru']);
    sampleWords($lesson, $learner, 5);

    $report = sampleReport(MaterialScope::forCount($course), $learner, LocaleSet::of('ru'));

    expect($report['pairs'])->toBe(['state' => true, 'available' => 5, 'required' => 3, 'reason' => null]);
});

it('falls back to every word when the sample has no pairs but the rest has', function (): void {
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create(['interface_locale' => 'ru']);
    sampleWords($lesson, $learner, 12, fn (int $i): bool => $i > 6);
    $locales = LocaleSet::of('ru');

    $sampled = sampleReport(MaterialScope::forCount($course), $learner, $locales);
    $full = sampleReport(MaterialScope::forCount($course)->withoutSample(), $learner, $locales);

    expect($sampled['pairs']['state'])->toBeTrue()->and($sampled['pairs'])->toBe($full['pairs'])->and($full['pairs']['available'])->toBe(6);
});

it('reports the exact shortage when even every word has no pairs', function (): void {
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create(['interface_locale' => 'ru']);
    sampleWords($lesson, $learner, 12, fn (int $i): bool => $i > 10);
    $locales = LocaleSet::of('ru');

    $sampled = sampleReport(MaterialScope::forCount($course), $learner, $locales);
    $full = sampleReport(MaterialScope::forCount($course)->withoutSample(), $learner, $locales);

    expect($sampled['pairs']['state'])->toBeFalse()->and($sampled['pairs'])->toBe($full['pairs'])
        ->and($full['pairs'])->toMatchArray(['available' => 2, 'required' => 3, 'reason' => 'no_words']);
});

it('finds the voiced words behind the first ones of the learner without loading all of them', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    for ($i = 1; $i <= 12; $i++) {
        DictationKit::word($lesson, $learner, "plain{$i}", "tr{$i}", voiced: false);
    }
    for ($i = 1; $i <= 4; $i++) {
        DictationKit::word($lesson, $learner, "voiced{$i}", "vtr{$i}");
    }

    $report = [];
    $queries = QueryLog::during(function () use (&$report, $course, $learner): void {
        $report = sampleReport(MaterialScope::forCount($course), $learner, LocaleSet::of('ru'));
    });

    expect($report['dictation'])->toBe(['state' => true, 'available' => 4, 'required' => 3, 'reason' => null]);
    foreach (QueryLog::matching($queries, '/from ["`]words["`]/') as $sql) {
        expect($sql)->toContain('limit');
    }
});

it('reports no_audio_words with the numbers of the full count when no word is voiced', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    for ($i = 1; $i <= 12; $i++) {
        DictationKit::word($lesson, $learner, "plain{$i}", voiced: false);
    }
    $locales = LocaleSet::of('ru');

    $sampled = sampleReport(MaterialScope::forCount($course), $learner, $locales);
    $full = sampleReport(MaterialScope::forCount($course)->withoutSample(), $learner, $locales);

    expect($sampled['dictation'])->toBe($full['dictation'])->and($sampled['dictation'])->toMatchArray(['state' => false, 'available' => 0, 'reason' => 'no_audio_words']);
});

it('counts the same as the full count over random worlds: state and reason always, the number when short', function (): void {
    foreach (range(1, 14) as $seed) {
        // Its own seeded generator: the global one is the faker's, it would repeat the unique words.
        $rng = new Randomizer(new Mt19937($seed));
        [$course, $lesson] = PracticeKit::course("english-rnd-{$seed}");
        $learner = Learner::factory()->create(['interface_locale' => 'ru']);
        config()->set('practice.count_sample', $rng->getInt(5, 10));
        $locales = LocaleSet::of('ru');

        $started = $rng->getInt(0, 40);
        for ($i = 1; $i <= $started; $i++) {
            // Not the factory: its unique faker word runs out over so many words.
            $word = Word::query()->forceCreate([
                'lesson_id' => $lesson->id,
                'word' => "rnd{$seed}x{$i}",
                'translation' => $rng->getInt(0, 3) === 0 ? '(разг.)' : 'общий '.$rng->getInt(1, 6),
                'audio_path' => $rng->getInt(0, 2) === 0 ? app(WordAudioService::class)->pathFor("rnd{$seed}x{$i}") : null,
            ]);
            PracticeKit::introduce($learner, $word);
        }
        // Words nobody has started: the course is bigger than what the learner has seen.
        sampleWords($lesson, null, $rng->getInt(0, 6), from: 100);

        $sampled = sampleReport(MaterialScope::forCount($course), $learner, $locales);
        $full = sampleReport(MaterialScope::forCount($course)->withoutSample(), $learner, $locales);

        foreach ($full as $mode => $expected) {
            expect($sampled[$mode]['state'])->toBe($expected['state'], "seed {$seed} {$mode} state")
                ->and($sampled[$mode]['reason'])->toBe($expected['reason'], "seed {$seed} {$mode} reason")
                ->and($sampled[$mode]['required'])->toBe($expected['required']);
            if ($expected['state']) {
                expect($sampled[$mode]['available'])->toBeLessThanOrEqual($expected['available']);
            } else {
                expect($sampled[$mode]['available'])->toBe($expected['available'], "seed {$seed} {$mode} available");
            }
        }
    }
});

it('counts the options of a battle over the first lessons of the course', function (): void {
    config()->set('practice.count_sample', 25);
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world(words: 0, items: 0);
    $stage = Stage::query()->findOrFail($lesson->stage_id);
    $second = Lesson::factory()->for($stage)->create(['number' => 2]);
    $third = Lesson::factory()->for($stage)->create(['number' => 3]);
    sampleWords($lesson, null, 15);
    sampleWords($second, null, 15);
    sampleWords($third, null, 30);

    $view = null;
    $queries = QueryLog::during(function () use (&$view, $me, $friendship, $course): void {
        $view = BattleKit::reader()->options($me, $friendship->id, $course->slug, null);
    });

    $pairs = collect($view->modes)->firstWhere('mode', 'pairs');
    expect($pairs['state'])->toBe('available')->and($pairs['available'])->toBe(25)->and($pairs['required'])->toBe(20);

    $wordQueries = QueryLog::matching($queries, '/from ["`]words["`]/');
    expect($wordQueries)->toHaveCount(1)
        ->and($wordQueries[0])->toMatch('/["`]words["`]\.["`]lesson_id["`] in \(\?, \?\)/')
        ->and($wordQueries[0])->not->toContain('limit');
});

it('counts the options of a course that is not bigger than the sample as before', function (): void {
    config()->set('practice.count_sample', 25);
    [$me, , $course, , $friendship] = BattleKit::world(words: 24, items: 0);

    $view = BattleKit::reader()->options($me, $friendship->id, $course->slug, null);

    $pairs = collect($view->modes)->firstWhere('mode', 'pairs');
    expect($pairs['state'])->toBe('available')->and($pairs['available'])->toBe(24);
});

it('reads a lesson and a played scope without a sample', function (): void {
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create(['interface_locale' => 'ru']);
    sampleWords($lesson, $learner, 12);
    $material = app(PracticeMaterialService::class);

    foreach ([MaterialScope::forCount($course, $lesson), new MaterialScope($course), new MaterialScope($course, $lesson)] as $scope) {
        $words = [];
        $queries = QueryLog::during(function () use (&$words, $material, $scope, $learner): void {
            $words = $material->introducedWords($scope, $learner, LocaleSet::of('ru'));
        });

        expect($words)->toHaveCount(12);
        foreach (QueryLog::matching($queries, '/from ["`]words["`]/') as $sql) {
            expect($sql)->not->toContain('limit');
        }
    }
});
