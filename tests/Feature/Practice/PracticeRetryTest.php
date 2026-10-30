<?php

declare(strict_types=1);

use App\Domain\Practice\Enums\AudioStatus;
use App\Domain\Practice\Enums\PracticeItemStatus;
use App\Domain\Practice\Services\PracticeAudioService;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\PracticeItem;
use App\Models\PracticeItemVersion;
use App\Models\PracticeSession;
use App\Models\Word;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Practice\DictationKit;
use Tests\Support\Practice\PracticeKit;
use Tests\Support\QueryLog;

/*
 * docs/16 §6.1, §6.4: "repeat the mistakes". The `retry_available` of the summary and the opening of the retry are ONE decision
 * (PracticeSessionService::retryBlocker): the button is shown exactly when the opening would be accepted - not when the mode has
 * been switched off since, nor when the tasks of the mistakes have been unpublished or lost their voice or their words. The real
 * handlers play here; what the summary may cost in statements is looked at at the end.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-30 10:00:00');
    PracticeKit::openAccess();
    Storage::fake('practice_audio');
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * A learner, a lesson with the words they know and five published tasks of the type aimed at them; a dictation has its voice
 * and its file.
 *
 * @return array{0: Learner, 1: Course, 2: Lesson, 3: list<PracticeItem>, 4: list<Word>}
 */
function retryWorld(string $type): array
{
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create();
    $words = PracticeKit::words($lesson, 3, $learner);

    $items = [];
    foreach (range(1, 5) as $n) {
        if ($type === 'dictation') {
            $items[] = DictationKit::phrase($lesson, $learner, "Phrase number {$n} here");
        } else {
            $items[] = PracticeItem::factory()->{$type}()->published()->forLesson($lesson)->targeting($words)->create();
        }
    }

    return [$learner, $course, $lesson, $items, $words];
}

/** @param array<string, mixed> $body */
function retryOpen(Learner $learner, Course $course, string $mode, array $body = []): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->postJson('/api/twa/practice/sessions', ['mode' => $mode, 'course' => $course->slug, ...$body]);
}

function retryGet(Learner $learner, int $sessionId): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->getJson("/api/twa/practice/sessions/{$sessionId}");
}

/** Plays a session of the mode to its end with a mistake in every element (a skip: "I don't know" is one in every mode). @return array<string, mixed> the finished session */
function retryFinished(Learner $learner, Course $course, Lesson $lesson, string $mode): array
{
    $session = retryOpen($learner, $course, $mode, ['lesson_id' => $lesson->id])->assertCreated()->json();
    for ($i = 0; $session['status'] === 'active' && $i < 10; $i++) {
        PracticeKit::resetLimiters($learner);
        $session = test()->withHeaders(PracticeKit::headers($learner))->postJson("/api/twa/practice/sessions/{$session['id']}/answer", [
            'request_id' => (string) Str::uuid(), 'element_id' => $session['current']['id'], 'answer' => ['skip' => true],
        ])->assertOk()->json('session');
    }
    expect($session['status'])->toBe('finished');

    return $session;
}

/** @return list<int> the ids of the tasks the session was made of */
function retryItemIds(int $sessionId): array
{
    return array_map(static fn (array $e): int => (int) $e['item_id'], PracticeSession::query()->findOrFail($sessionId)->snapshot['elements']);
}

// ---------------------------------------------------------------- the button is the opening

it('shows the button exactly when the opening would be accepted: the same answer from the summary and from the retry', function (string $type, string $scenario, bool $available, ?string $refusal): void {
    [$learner, $course, $lesson, $items, $words] = retryWorld($type);
    $finished = retryFinished($learner, $course, $lesson, $type);
    expect($finished['summary']['retry_available'])->toBeTrue();   // five mistakes, everything in its place

    $mistaken = retryItemIds($finished['id']);
    $versions = PracticeItemVersion::query()->whereIn('item_id', $mistaken)->pluck('id', 'item_id');

    match ($scenario) {
        'all as it was' => null,
        'mode switched off' => config(["practice.modes.{$type}" => false]),
        'one task left published' => PracticeItem::query()->whereIn('id', array_slice($mistaken, 1))->update(['status' => PracticeItemStatus::Archived->value]),
        'every task archived' => PracticeItem::query()->whereIn('id', $mistaken)->update(['status' => PracticeItemStatus::Archived->value]),
        'every task back to a draft' => PracticeItem::query()->whereIn('id', $mistaken)->update(['status' => PracticeItemStatus::Draft->value, 'published_version_id' => null]),
        'a target word switched off' => Word::query()->whereIn('id', array_map(fn (Word $w): int => $w->id, $words))->update(['is_active' => false]),
        'a target word not introduced' => WordRepetition::query()->where('learner_id', $learner->id)->delete(),
        'the voice lost' => PracticeItemVersion::query()->whereIn('id', $versions->values())->update(['audio_status' => AudioStatus::Failed->value]),
        'one voice left' => PracticeItemVersion::query()->whereIn('id', $versions->values()->slice(1))->update(['audio_status' => AudioStatus::Pending->value]),
    };

    $summary = retryGet($learner, $finished['id'])->assertOk()->json('summary.retry_available');
    PracticeKit::resetLimiters($learner);
    $opened = retryOpen($learner, $course, $type, ['retry_of' => $finished['id'], 'source' => 'retry']);

    expect($summary)->toBe($available, "the summary, {$type}: {$scenario}")
        ->and($opened->status() === 201)->toBe($available, "the opening, {$type}: {$scenario}");
    if ($refusal !== null) {
        $opened->assertStatus(409)->assertJsonPath('error.code', $refusal);
    }
})->with([
    'collocation: nothing changed' => ['collocation', 'all as it was', true, null],
    'collocation: the mode is switched off' => ['collocation', 'mode switched off', false, 'practice_mode_disabled'],
    'collocation: one task is still published' => ['collocation', 'one task left published', true, null],
    'collocation: every task is archived' => ['collocation', 'every task archived', false, 'retry_not_available'],
    'collocation: every task is a draft again' => ['collocation', 'every task back to a draft', false, 'retry_not_available'],
    'collocation: the target words are switched off' => ['collocation', 'a target word switched off', false, 'retry_not_available'],
    'collocation: the words are not introduced' => ['collocation', 'a target word not introduced', false, 'retry_not_available'],
    'sentence: nothing changed' => ['sentence', 'all as it was', true, null],
    'sentence: the mode is switched off' => ['sentence', 'mode switched off', false, 'practice_mode_disabled'],
    'sentence: every task is archived' => ['sentence', 'every task archived', false, 'retry_not_available'],
    'dictation: nothing changed' => ['dictation', 'all as it was', true, null],
    'dictation: the mode is switched off' => ['dictation', 'mode switched off', false, 'practice_mode_disabled'],
    'dictation: every phrase lost its voice' => ['dictation', 'the voice lost', false, 'retry_not_available'],
    'dictation: one phrase still has its voice' => ['dictation', 'one voice left', true, null],
    'dictation: every phrase is archived' => ['dictation', 'every task archived', false, 'retry_not_available'],
]);

it('brings the button back when the tasks come back: the summary is read, not stored', function (): void {
    [$learner, $course, $lesson] = retryWorld('collocation');
    $finished = retryFinished($learner, $course, $lesson, 'collocation');
    $mistaken = retryItemIds($finished['id']);

    PracticeItem::query()->whereIn('id', $mistaken)->update(['status' => PracticeItemStatus::Archived->value]);
    expect(retryGet($learner, $finished['id'])->json('summary.retry_available'))->toBeFalse();

    PracticeItem::query()->whereIn('id', $mistaken)->update(['status' => PracticeItemStatus::Published->value]);
    expect(retryGet($learner, $finished['id'])->json('summary.retry_available'))->toBeTrue();

    config(['practice.modes.collocation' => false]);
    expect(retryGet($learner, $finished['id'])->json('summary.retry_available'))->toBeFalse();
    config(['practice.modes.collocation' => true]);
    expect(retryGet($learner, $finished['id'])->json('summary.retry_available'))->toBeTrue();
});

it('has nothing to repeat in a session without mistakes: no button, and the opening says so', function (): void {
    [$learner, $course, $lesson] = retryWorld('collocation');
    $session = retryOpen($learner, $course, 'collocation', ['lesson_id' => $lesson->id])->assertCreated()->json();
    $stored = PracticeSession::query()->findOrFail($session['id'])->snapshot['elements'];

    for ($i = 0; $session['status'] === 'active' && $i < 10; $i++) {
        $element = collect($stored)->firstWhere('id', $session['current']['id']);
        $session = test()->withHeaders(PracticeKit::headers($learner))->postJson("/api/twa/practice/sessions/{$session['id']}/answer", [
            'request_id' => (string) Str::uuid(), 'element_id' => $element['id'], 'answer' => ['option_id' => $element['correct_ids'][0]],
        ])->assertOk()->json('session');
    }

    expect($session['summary']['mistakes'])->toBe([])
        ->and($session['summary']['retry_available'])->toBeFalse();
    retryOpen($learner, $course, 'collocation', ['retry_of' => $session['id'], 'source' => 'retry'])->assertStatus(409)->assertJsonPath('error.code', 'retry_not_available');
});

// ---------------------------------------------------------------- what it costs

it('costs one statement more than the plain read of a finished session when its mistakes are tasks, and none when they are words', function (): void {
    [$learner, $course, $lesson] = retryWorld('collocation');
    $finished = retryFinished($learner, $course, $lesson, 'collocation');

    $queries = QueryLog::during(fn () => retryGet($learner, $finished['id'])->assertOk()->assertJsonPath('summary.retry_available', true));
    $stock = QueryLog::matching($queries, '/^select .* from ["`]practice_items["`]/i');

    // The learner, the session, and the one statement that asks about the tasks - docs/16 §13: «≤ 3».
    expect(count($queries))->toBe(3, implode("\n", $queries))
        ->and($stock)->toHaveCount(1)
        ->and(QueryLog::matching($queries, '/practice_item_versions/'))->toHaveCount(1);   // the audio status comes in the same statement as a sub-select

    // A session of pairs: the mistakes are words, the summary asks nothing of the tasks.
    $reader = Learner::factory()->create();
    foreach (range(1, 4) as $n) {
        PracticeKit::introduce($reader, Word::factory()->for($lesson)->create(['word' => "pairword{$n}", 'translation' => "парный перевод {$n}"]));
    }
    $session = retryOpen($reader, $course, 'pairs', ['lesson_id' => $lesson->id])->assertCreated()->json();
    $round = PracticeSession::query()->findOrFail($session['id'])->snapshot['elements'][0];
    $matched = [];
    foreach ($round['left'] as $left) {
        // A mistake first (a card nobody has taken yet; the last word has no such card), then the right card.
        $free = collect($round['right'])->pluck('id')->reject(fn (string $id): bool => in_array($id, $matched, true) || $id === $round['pairs'][$left['id']])->values();
        foreach ([...$free->take(1), $round['pairs'][$left['id']]] as $right) {
            PracticeKit::resetLimiters($reader);
            $answered = test()->withHeaders(PracticeKit::headers($reader))->postJson("/api/twa/practice/sessions/{$session['id']}/answer", [
                'request_id' => (string) Str::uuid(), 'element_id' => $round['id'], 'answer' => ['left_id' => $left['id'], 'right_id' => $right],
            ])->assertOk();
        }
        $matched[] = $round['pairs'][$left['id']];
    }
    expect($answered->json('session.status'))->toBe('finished')
        ->and($answered->json('session.summary.retry_available'))->toBeTrue();

    $paired = QueryLog::during(fn () => retryGet($reader, $session['id'])->assertOk()->assertJsonPath('summary.retry_available', true));

    expect(count($paired))->toBe(2, implode("\n", $paired));
});

it('does not ask about the tasks when the cheap checks already say no: nothing to repeat, mode off', function (): void {
    [$learner, $course, $lesson] = retryWorld('collocation');
    $finished = retryFinished($learner, $course, $lesson, 'collocation');
    config(['practice.modes.collocation' => false]);

    $queries = QueryLog::during(fn () => retryGet($learner, $finished['id'])->assertOk()->assertJsonPath('summary.retry_available', false));

    expect(count($queries))->toBe(2, implode("\n", $queries));
});

it('keeps the file of a dictation out of the decision: the status says whether it can be voiced, the disk is not read for a summary', function (): void {
    [$learner, $course, $lesson] = retryWorld('dictation');
    $finished = retryFinished($learner, $course, $lesson, 'dictation');
    // The files are gone from the disk, the rows say `ready`: the summary does not look (a clip that does not play is swapped in the game).
    Storage::fake('practice_audio');

    expect(retryGet($learner, $finished['id'])->json('summary.retry_available'))->toBeTrue()
        ->and(app(PracticeAudioService::class)->diskName())->toBe('practice_audio');
});
