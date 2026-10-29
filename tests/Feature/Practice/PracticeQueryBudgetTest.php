<?php

declare(strict_types=1);

use App\Domain\Practice\Enums\PracticeMode;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\PracticeItem;
use App\Models\PracticeSession;
use App\Models\ProductEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Practice\FakeEchoMode;
use Tests\Support\Practice\PracticeKit;
use Tests\Support\QueryLog;

/*
 * docs/16 §13: the budgets of SQL statements of the hot paths of the solo practice, and the sizes of the answers. `TwaAuth` reads
 * the learner (1 statement), it is included. The battle (poll, answer, invite, sweep) adds its budgets with 18.6.
 *
 * The test handlers read the material the way a real handler does (FakeEchoMode::$readsMaterial): the introduced words and
 * their translations, the published tasks and their versions - so the budget of the hub is the budget of the real thing.
 */

uses(RefreshDatabase::class);

const BUDGET_HUB = 9;
const BUDGET_OPEN = 12;
const BUDGET_SHOW = 3;
const BUDGET_ANSWER = 6;
const BUDGET_AUDIO = 2;

/** A response of a session must not exceed 8 KB (docs/16 §13), the snapshot - 32 KB. */
const BUDGET_SESSION_BYTES = 8 * 1024;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-30 10:00:00');
    PracticeKit::openAccess();
    Storage::fake(FakeEchoMode::AUDIO_DISK);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * A lesson with six words the learner has been introduced to and four published tasks of each of the three types, aimed at
 * them; all four modes are test handlers that read this material.
 *
 * @return array{0: Learner, 1: Course, 2: Lesson}
 */
function budgetWorld(string $locale = 'ru'): array
{
    [$course, $lesson] = PracticeKit::course("english-{$locale}");
    $learner = Learner::factory()->create(['interface_locale' => $locale]);
    $words = PracticeKit::words($lesson, 6, $learner, state: 'review');

    foreach ($words as $i => $word) {
        PracticeItem::factory()->collocation()->published()->forLesson($lesson)->targeting($word)->create();
        PracticeItem::factory()->sentence()->published()->forLesson($lesson)->targeting([$word, $words[($i + 1) % 6]])->create();
        PracticeItem::factory()->dictation()->published()->audioReady()->forLesson($lesson)->targeting($word)->create();
    }

    foreach (PracticeMode::cases() as $mode) {
        PracticeKit::fake($mode, candidates: 9, spares: 2)->readsMaterial = true;
    }

    return [$learner, $course, $lesson];
}

function budgetHub(Learner $learner, Course $course, ?Lesson $lesson = null): TestResponse
{
    $query = $lesson === null ? '' : "&lesson_id={$lesson->id}";

    return test()->withHeaders(PracticeKit::headers($learner))->getJson("/api/twa/practice/hub?course={$course->slug}{$query}");
}

/** @param array<string, mixed> $body */
function budgetOpen(Learner $learner, Course $course, string $mode, array $body = []): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->postJson('/api/twa/practice/sessions', ['mode' => $mode, 'course' => $course->slug, ...$body]);
}

/** @param array<string, mixed> $answer */
function budgetAnswer(Learner $learner, int $sessionId, string $elementId, array $answer): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->postJson("/api/twa/practice/sessions/{$sessionId}/answer", [
        'request_id' => (string) Str::uuid(), 'element_id' => $elementId, 'answer' => $answer,
    ]);
}

/** @param list<string> $queries */
function budgetReads(array $queries, string $table): int
{
    return count(QueryLog::matching($queries, '/^select .* from ["`]'.$table.'["`]/i'));
}

it('reads the hub in at most 9 statements, whatever the language, and asks for the words and the tasks once for all four modes', function (): void {
    foreach (['ru', 'uz'] as $locale) {
        [$learner, $course, $lesson] = budgetWorld($locale);

        $queries = QueryLog::during(fn () => budgetHub($learner, $course, $lesson)->assertOk()->assertJsonPath('modes.0.available', 9));
        // The same for a whole course.
        $courseQueries = QueryLog::during(fn () => budgetHub($learner, $course)->assertOk());

        expect(count($queries))->toBeLessThanOrEqual(BUDGET_HUB, "hub of a lesson ({$locale}): ".implode("\n", $queries))
            ->and(count($courseQueries))->toBeLessThanOrEqual(BUDGET_HUB, "hub of a course ({$locale}): ".implode("\n", $courseQueries))
            ->and(budgetReads($queries, 'words'))->toBe(1)
            ->and(budgetReads($queries, 'word_translations'))->toBe(1)
            ->and(budgetReads($queries, 'practice_items'))->toBe(1)
            ->and(budgetReads($queries, 'practice_item_versions'))->toBe(1)
            ->and(budgetReads($queries, 'practice_sessions'))->toBe(1)
            ->and(budgetReads($queries, 'battles'))->toBe(1);
    }
});

it('reads the hub within the budget with an unfinished session, and for a Pro learner at PRACTICE_PLAN=pro (the entitlement is cached)', function (): void {
    [$learner, $course, $lesson] = budgetWorld();
    budgetOpen($learner, $course, 'collocation', ['lesson_id' => $lesson->id])->assertCreated();

    $queries = QueryLog::during(fn () => budgetHub($learner, $course, $lesson)->assertOk()->assertJsonPath('active_session.mode', 'collocation'));
    expect(count($queries))->toBeLessThanOrEqual(BUDGET_HUB, implode("\n", $queries));

    config(['practice.plan' => 'pro']);
    PracticeKit::pro($learner);
    budgetHub($learner, $course, $lesson)->assertOk(); // warms the cache of the entitlement, the way the previous request would
    $queries = QueryLog::during(fn () => budgetHub($learner, $course, $lesson)->assertOk()->assertJsonPath('modes.1.state', 'available'));

    expect(count($queries))->toBeLessThanOrEqual(BUDGET_HUB, implode("\n", $queries));
});

it('opens a session in at most 12 statements: a plain one, and one that replaces the unfinished one', function (): void {
    [$learner, $course, $lesson] = budgetWorld();

    $plain = QueryLog::during(fn () => budgetOpen($learner, $course, 'sentence', ['lesson_id' => $lesson->id])->assertCreated());
    $replacing = QueryLog::during(fn () => budgetOpen($learner, $course, 'dictation', ['lesson_id' => $lesson->id, 'replace_active' => true])->assertCreated());

    expect(count($plain))->toBeLessThanOrEqual(BUDGET_OPEN, implode("\n", $plain))
        ->and(count($replacing))->toBeLessThanOrEqual(BUDGET_OPEN, implode("\n", $replacing))
        ->and(budgetReads($plain, 'words'))->toBe(0) // a sentence reads tasks, not words
        ->and(budgetReads($plain, 'practice_items'))->toBe(1)
        ->and(budgetReads($replacing, 'words'))->toBe(1)
        ->and(budgetReads($replacing, 'practice_items'))->toBe(1)
        ->and(PracticeSession::query()->where('status', 'active')->count())->toBe(1)
        ->and(ProductEvent::query()->where('name', 'practice.session_started')->count())->toBe(2);
});

it('shows a session in at most 3 statements, a short one and a long one alike', function (): void {
    [$learner, $course, $lesson] = budgetWorld();
    config(['practice.solo.items.size' => 12]);
    PracticeKit::fake(PracticeMode::Collocation, candidates: 20);

    $short = budgetOpen($learner, $course, 'sentence', ['lesson_id' => $lesson->id])->assertCreated()->json();
    $queries = QueryLog::during(fn () => test()->withHeaders(PracticeKit::headers($learner))->getJson("/api/twa/practice/sessions/{$short['id']}")->assertOk());
    expect(count($queries))->toBeLessThanOrEqual(BUDGET_SHOW, implode("\n", $queries));

    $long = budgetOpen($learner, $course, 'collocation', ['replace_active' => true])->assertCreated();
    expect($long->json('progress.total'))->toBe(12);
    $queries = QueryLog::during(fn () => test()->withHeaders(PracticeKit::headers($learner))->getJson("/api/twa/practice/sessions/{$long->json('id')}")->assertOk());
    expect(count($queries))->toBeLessThanOrEqual(BUDGET_SHOW, implode("\n", $queries));
});

it('takes an answer in at most 6 statements, the last one that ends the session included', function (): void {
    [$learner, $course] = budgetWorld();
    PracticeKit::fake(PracticeMode::Collocation, candidates: 3);
    $session = budgetOpen($learner, $course, 'collocation')->assertCreated()->json();

    $middle = QueryLog::during(function () use ($learner, &$session): void {
        $session = budgetAnswer($learner, $session['id'], $session['current']['id'], ['text' => 'nonsense'])->assertOk()->json('session');
    });
    $session = budgetAnswer($learner, $session['id'], $session['current']['id'], ['skip' => true])->assertOk()->json('session');
    $last = QueryLog::during(function () use ($learner, &$session): void {
        $session = budgetAnswer($learner, $session['id'], $session['current']['id'], ['skip' => true])->assertOk()->json('session');
    });

    expect($session['status'])->toBe('finished')
        ->and(count($middle))->toBeLessThanOrEqual(BUDGET_ANSWER, implode("\n", $middle))
        ->and(count($last))->toBeLessThanOrEqual(BUDGET_ANSWER, implode("\n", $last))
        ->and(ProductEvent::query()->where('name', 'practice.session_finished')->count())->toBe(1);
});

it('takes a match of pairs in at most 6 statements as well', function (): void {
    [$learner, $course] = budgetWorld();
    $session = budgetOpen($learner, $course, 'pairs')->assertCreated()->json();
    $round = PracticeSession::query()->findOrFail($session['id'])->snapshot['elements'][0];
    $left = $round['left'][0]['id'];

    $queries = QueryLog::during(fn () => budgetAnswer($learner, $session['id'], $round['id'], ['left_id' => $left, 'right_id' => $round['pairs'][$left]])->assertOk());

    expect(count($queries))->toBeLessThanOrEqual(BUDGET_ANSWER, implode("\n", $queries));
});

it('hands out a clip in at most 2 statements before the file itself', function (): void {
    [$learner, $course] = budgetWorld();
    $session = budgetOpen($learner, $course, 'dictation')->assertCreated()->json();
    $element = collect(PracticeSession::query()->findOrFail($session['id'])->snapshot['elements'])->firstWhere('id', $session['current']['id']);
    Storage::disk(FakeEchoMode::AUDIO_DISK)->put($element['audio_path'], 'mp3');

    $queries = QueryLog::during(fn () => test()->withHeaders(PracticeKit::headers($learner))->get($session['current']['audio_url'])->assertOk()->streamedContent());

    expect(count($queries))->toBeLessThanOrEqual(BUDGET_AUDIO, implode("\n", $queries));
});

it('keeps the response of a session under 8 KB and refuses to store a snapshot over 32 KB', function (): void {
    [$learner, $course] = budgetWorld();
    $fake = PracticeKit::fake(PracticeMode::Collocation, candidates: 9);

    $response = budgetOpen($learner, $course, 'collocation')->assertCreated();
    expect(strlen((string) $response->getContent()))->toBeLessThan(BUDGET_SESSION_BYTES);

    // 5 elements of 5 KB: 25 KB and a little, under the limit.
    PracticeSession::query()->delete();
    $fake->padding = 5_000;
    $stored = budgetOpen($learner, $course, 'collocation')->assertCreated();
    $bytes = strlen(json_encode(PracticeSession::query()->findOrFail($stored->json('id'))->snapshot, JSON_THROW_ON_ERROR));
    expect($bytes)->toBeGreaterThan(25_000)->toBeLessThan(32 * 1024)
        ->and(strlen((string) $stored->getContent()))->toBeLessThan(BUDGET_SESSION_BYTES); // the padding stays in the snapshot

    // 5 elements of 8 KB: a LogicException, no row, no event, and the learner is free to start another session.
    PracticeSession::query()->delete();
    ProductEvent::query()->delete();
    $fake->padding = 8_000;
    $this->withoutExceptionHandling();
    expect(fn () => budgetOpen($learner, $course, 'collocation'))->toThrow(LogicException::class, 'exceeds the limit');
    expect(PracticeSession::query()->count())->toBe(0)
        ->and(ProductEvent::query()->count())->toBe(0);

    $fake->padding = 0;
    budgetOpen($learner, $course, 'collocation')->assertCreated();
});
