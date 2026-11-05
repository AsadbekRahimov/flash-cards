<?php

declare(strict_types=1);

use App\Domain\Practice\DTO\LocaleSet;
use App\Domain\Practice\DTO\MaterialScope;
use App\Domain\Practice\Enums\PracticeItemType;
use App\Domain\Practice\Services\PracticeMaterialService;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\PracticeItem;
use App\Models\PracticeItemVersion;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Practice\PracticeKit;
use Tests\Support\QueryLog;

/*
 * R12e: the availability of the modes (the hub, the options of a battle) is counted from plain rows, without hydrating a
 * PracticeItemVersion. The numbers below were taken from the code that hydrated the versions (before R12e): the real handlers,
 * a world with every state of a task - fit, locked, dead, a dictation voiced / pending / failed, a repeated text, a broken content.
 */

uses(RefreshDatabase::class);

/** The statements of the hub (the same number as the budget of PracticeQueryBudgetTest). */
const PARITY_BUDGET_HUB = 9;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-30 10:00:00');
    PracticeKit::openAccess();
    Storage::fake('local');
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * @return array{course: Course, lesson: Lesson, learner: Learner, empty: Learner}
 */
function parityWorld(): array
{
    [$course, $lesson] = PracticeKit::course('english-parity');
    $learner = Learner::factory()->create(['interface_locale' => 'ru']);
    $empty = Learner::factory()->create(['interface_locale' => 'ru']);

    // w1..w4 introduced, w5 not, w6 switched off.
    [$w1, $w2, $w3, $w4] = PracticeKit::words($lesson, 4, $learner, state: 'review');
    $unseen = PracticeKit::words($lesson, 1)[0];
    $dead = PracticeKit::words($lesson, 1, $learner, ['is_active' => false])[0];

    $make = function (string $type, array $words, string $audio = '', array $content = []) use ($lesson): void {
        $factory = PracticeItem::factory()->{$type}()->published()->forLesson($lesson)->targeting($words);
        $factory = match ($audio) {
            'ready' => $factory->audioReady(),
            'pending' => $factory->audioPending(),
            'failed' => $factory->audioFailed(),
            default => $factory,
        };
        ($content === [] ? $factory : $factory->withContent($content))->create();
    };

    // collocations: 2 fit, locked (one word / one of two), dead (one word / dead beats locked), a fit one that cannot be played
    $make('collocation', [$w1]);
    $make('collocation', [$w2]);
    $make('collocation', [$unseen]);
    $make('collocation', [$w1, $unseen]);
    $make('collocation', [$dead]);
    $make('collocation', [$unseen, $dead]);
    $make('collocation', [$w3], '', ['text' => 'no blank here']);

    // sentences: 2 fit, 1 locked, 1 dead, 1 fit that cannot be played
    $make('sentence', [$w1, $w2]);
    $make('sentence', [$w3]);
    $make('sentence', [$unseen]);
    $make('sentence', [$dead]);
    $make('sentence', [$w4], '', ['fragments' => []]);

    // dictations: two voiced with the same text (one candidate), pending, failed, voiced+locked, pending+locked, voiced+dead, voiced
    $make('dictation', [$w1], 'ready', ['text' => 'Hello there']);
    $make('dictation', [$w2], 'ready', ['text' => 'hello  there']);
    $make('dictation', [$w3], 'pending');
    $make('dictation', [$w3], 'failed');
    $make('dictation', [$unseen], 'ready');
    $make('dictation', [$unseen], 'pending');
    $make('dictation', [$dead], 'ready');
    $make('dictation', [$w4], 'ready', ['text' => 'Good morning']);

    // not published: nothing of it counts
    PracticeItem::factory()->collocation()->draft()->forLesson($lesson)->targeting([$w1])->create();

    return compact('course', 'lesson', 'learner', 'empty');
}

/**
 * @param  list<string>  $locales
 * @return array<string, array{int, int, string|null}> mode => [available, required, reason]
 */
function parityReport(Course $course, ?Lesson $lesson, ?Learner $learner, array $locales = ['ru']): array
{
    $report = [];
    foreach (app(PracticeMaterialService::class)->availability(MaterialScope::forCount($course, $lesson), $learner, new LocaleSet($locales)) as $mode => $a) {
        $report[$mode] = [$a->available, $a->required, $a->reason];
    }

    return $report;
}

it('counts the modes of a lesson, of a course and of a battle as the code that hydrated the versions did', function (): void {
    ['course' => $course, 'lesson' => $lesson, 'learner' => $learner] = parityWorld();

    $mode = ['pairs' => [4, 3, null], 'collocation' => [2, 3, 'items_locked'], 'sentence' => [2, 3, 'items_locked'], 'dictation' => [2, 3, 'audio_pending']];

    expect(parityReport($course, $lesson, $learner))->toBe($mode)
        ->and(parityReport($course, null, $learner, ['ru', 'uz']))->toBe($mode)
        ->and(parityReport($course, $lesson, null, ['ru', 'uz']))->toBe([
            'pairs' => [5, 20, 'no_words'],
            'collocation' => [4, 5, 'no_items'],
            'sentence' => [3, 5, 'no_items'],
            'dictation' => [3, 5, 'audio_pending'],
        ]);
});

it('tells a learner without words that the tasks are there and the words are not learned', function (): void {
    ['course' => $course, 'lesson' => $lesson, 'empty' => $empty] = parityWorld();

    expect(parityReport($course, $lesson, $empty))->toBe([
        'pairs' => [0, 3, 'no_words'],
        'collocation' => [0, 3, 'items_locked'],
        'sentence' => [0, 3, 'items_locked'],
        'dictation' => [0, 3, 'items_locked'],
    ]);
});

it('opens the modes when the minimum is low enough', function (): void {
    ['course' => $course, 'lesson' => $lesson, 'learner' => $learner] = parityWorld();
    config()->set('practice.solo.items.min', 1);

    expect(parityReport($course, $lesson, $learner))->toBe([
        'pairs' => [4, 3, null],
        'collocation' => [2, 1, null],
        'sentence' => [2, 1, null],
        'dictation' => [2, 1, null],
    ]);
});

it('gives the same numbers on the hub endpoint, with no model of a task or of a version and no more statements than the budget', function (): void {
    ['course' => $course, 'lesson' => $lesson, 'learner' => $learner] = parityWorld();

    $retrieved = [];
    foreach ([PracticeItem::class, PracticeItemVersion::class] as $model) {
        Event::listen("eloquent.retrieved: {$model}", function () use (&$retrieved, $model): void {
            $retrieved[] = $model;
        });
    }

    $modes = null;
    $queries = QueryLog::during(function () use (&$modes, $course, $lesson, $learner): void {
        $modes = test()->withHeaders(PracticeKit::headers($learner))
            ->getJson("/api/twa/practice/hub?course={$course->slug}&lesson_id={$lesson->id}")
            ->assertOk()->json('modes');
    });

    expect(array_column($modes, 'available', 'mode'))->toBe(['pairs' => 4, 'collocation' => 2, 'sentence' => 2, 'dictation' => 2])
        ->and(array_column($modes, 'reason', 'mode'))->toBe(['pairs' => null, 'collocation' => 'items_locked', 'sentence' => 'items_locked', 'dictation' => 'audio_pending'])
        ->and($retrieved)->toBe([])
        ->and(count($queries))->toBeLessThanOrEqual(PARITY_BUDGET_HUB, implode("\n", $queries));
});

it('does not hydrate a task or a version for the hub, and reads the versions of the fit tasks only', function (): void {
    ['course' => $course, 'lesson' => $lesson, 'learner' => $learner] = parityWorld();

    $retrieved = [];
    foreach ([PracticeItem::class, PracticeItemVersion::class] as $model) {
        Event::listen("eloquent.retrieved: {$model}", function () use (&$retrieved, $model): void {
            $retrieved[] = $model;
        });
    }
    $queries = QueryLog::during(fn () => parityReport($course, $lesson, $learner));

    expect($retrieved)->toBe([])
        ->and(count(QueryLog::matching($queries, '/from ["`]practice_items["`]/i')))->toBe(1)
        ->and(count(QueryLog::matching($queries, '/from ["`]practice_item_versions["`] where/i')))->toBe(1);
});

it('still hands the models to a session that is being opened', function (): void {
    ['course' => $course, 'lesson' => $lesson, 'learner' => $learner] = parityWorld();
    $scope = new MaterialScope($course, $lesson);
    $material = app(PracticeMaterialService::class);

    $models = $material->eligibleItems($scope, PracticeItemType::Collocation, $learner);
    $rows = $material->eligibleRows($scope, PracticeItemType::Collocation, $learner);

    expect($models)->toHaveCount(3)->each->toBeInstanceOf(PracticeItemVersion::class)
        ->and(array_map(fn ($v) => $v->id, $models))->toBe(array_column($rows, 'id'))
        ->and($material->eligibleItems($scope, PracticeItemType::Dictation, $learner))->toHaveCount(3)
        ->and($material->lockedItems($scope, PracticeItemType::Dictation, $learner))->toBe(1)
        ->and($material->pendingAudioItems($scope, $learner))->toBe(2);
});
