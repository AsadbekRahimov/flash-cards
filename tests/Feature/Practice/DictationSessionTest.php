<?php

declare(strict_types=1);

use App\Domain\Practice\DTO\Snapshot;
use App\Domain\Practice\Enums\SessionStatus;
use App\Models\Course;
use App\Models\Learner;
use App\Models\PracticeAttempt;
use App\Models\PracticeSession;
use App\Models\ProductEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Practice\DictationKit;
use Tests\Support\Practice\PracticeKit;
use Tests\Support\QueryLog;

/*
 * A solo session of "Listen and write" with the REAL mode (docs/16 §6, §7.2, §10.4): the element without a text, the answers and
 * the review, the summary and the repeat of the mistakes, the swap of a clip that does not play, the budgets of the requests.
 * The generic life cycle on a test handler is PracticeSessionTest; the mode alone is DictationModeTest.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-30 10:00:00');
    PracticeKit::openAccess();
    Storage::fake('practice_audio');
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** @param array<string, mixed> $body */
function dsOpen(object $test, Learner $learner, Course $course, array $body = []): TestResponse
{
    return $test->withHeaders(PracticeKit::headers($learner))->postJson('/api/twa/practice/sessions', ['mode' => 'dictation', 'course' => $course->slug, ...$body]);
}

/** @param array<string, mixed> $answer */
function dsAnswer(object $test, Learner $learner, int $sessionId, string $elementId, array $answer, ?string $requestId = null): TestResponse
{
    return $test->withHeaders(PracticeKit::headers($learner))->postJson("/api/twa/practice/sessions/{$sessionId}/answer", [
        'request_id' => $requestId ?? (string) Str::uuid(),
        'element_id' => $elementId,
        'answer' => $answer,
    ]);
}

function dsReplace(object $test, Learner $learner, int $sessionId, string $elementId, ?string $requestId = null): TestResponse
{
    return $test->withHeaders(PracticeKit::headers($learner))->postJson("/api/twa/practice/sessions/{$sessionId}/replace", [
        'request_id' => $requestId ?? (string) Str::uuid(),
        'element_id' => $elementId,
        'reason' => 'audio_unavailable',
    ]);
}

/** @return array<string, mixed> the snapshot of the session as the server keeps it (a test may read it) */
function dsSnapshot(int $sessionId): array
{
    return PracticeSession::query()->findOrFail($sessionId)->snapshot;
}

/** The text of an element, as the author wrote it. */
function dsDisplay(int $sessionId, string $elementId): string
{
    foreach (dsSnapshot($sessionId)['elements'] as $element) {
        if ($element['id'] === $elementId) {
            return $element['display'];
        }
    }

    throw new RuntimeException('No such element.');
}

/** The text with the first word misspelt by one transposition: "Phrase number one" -> "Pharse number one". */
function dsTypo(string $text): string
{
    $words = explode(' ', $text);
    $first = $words[0];
    $words[0] = substr($first, 0, 2).$first[3].$first[2].substr($first, 4);

    return implode(' ', $words);
}

/**
 * Plays a session of 2 words and 3 phrases to the end: a right answer in other capitals, a typo, a wrong text, a skip, a right answer.
 *
 * @return array{session: array<string, mixed>, id: int, elements: list<array<string, mixed>>}
 */
function dsPlayThrough(object $test, Learner $learner, Course $course): array
{
    $session = dsOpen($test, $learner, $course)->assertCreated()->json();
    $elements = dsSnapshot($session['id'])['elements'];

    $steps = [
        ['text' => mb_strtoupper($elements[0]['display']).'.'],
        ['text' => dsTypo($elements[1]['display'])],
        ['text' => 'something else entirely'],
        ['skip' => true],
        ['text' => $elements[4]['display']],
    ];
    foreach ($steps as $i => $answer) {
        $session = dsAnswer($test, $learner, $session['id'], $elements[$i]['id'], $answer)->assertOk()->json('session');
    }

    return ['session' => $session, 'id' => $session['id'], 'elements' => $elements];
}

// ------------------------------------------------------------------ opening

it('opens a session of two words and three phrases, and shows the client no text at all', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    DictationKit::stock($lesson, $learner, 5, 6);

    $response = dsOpen($this, $learner, $course, ['lesson_id' => $lesson->id])->assertCreated();
    $session = $response->json();
    $snapshot = dsSnapshot($session['id']);

    expect($session['mode'])->toBe('dictation')
        ->and($session['progress'])->toBe(['done' => 0, 'total' => 5])
        ->and(array_keys($session['current']))->toBe(['id', 'kind', 'source', 'audio_url', 'words', 'replacements_left'])
        ->and($session['current']['kind'])->toBe('dictation')
        ->and($session['current']['source'])->toBe('word')
        ->and($session['current']['words'])->toBe(1)
        ->and($session['current']['replacements_left'])->toBe(2)
        ->and($session['current']['audio_url'])->toBe("/api/twa/practice/sessions/{$session['id']}/audio/{$session['current']['id']}")
        ->and(array_column($snapshot['elements'], 'source'))->toBe(['word', 'word', 'phrase', 'phrase', 'phrase'])
        ->and(array_column($snapshot['elements'], 'kind'))->toBe(array_fill(0, 5, 'dictation'))
        ->and($snapshot['pool'])->toHaveCount(6); // two phrases and four words did not make it into the session

    // Neither a text, nor a translation, nor a path, nor a disk is anywhere in what the client gets - the elements of the
    // other rounds included.
    $json = (string) $response->getContent();
    foreach ([...$snapshot['elements'], ...$snapshot['pool']] as $element) {
        expect($json)->not->toContain($element['display'])->not->toContain($element['audio_path'])->not->toContain('"translation"');
    }
    expect($json)->not->toContain('.mp3')->not->toContain('practice_audio')->not->toContain('"expected"')->not->toContain('"display"');
});

it('answers 422 with the numbers when there is nothing to play, and a session needs three', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();

    dsOpen($this, $learner, $course, ['lesson_id' => $lesson->id])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'practice_not_enough_material')
        ->assertJsonPath('error.details', ['mode' => 'dictation', 'available' => 0, 'required' => 3, 'reason' => 'no_words', 'course' => 'english-a1', 'lesson_id' => $lesson->id]);

    DictationKit::stock($lesson, $learner, 1, 1);
    dsOpen($this, $learner, $course, ['lesson_id' => $lesson->id])
        ->assertStatus(422)
        ->assertJsonPath('error.details.available', 2)
        ->assertJsonPath('error.details.reason', 'no_items');
    expect(PracticeSession::query()->count())->toBe(0);
});

it('shows the dictation in the hub with the numbers and the reason', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    DictationKit::stock($lesson, $learner, 2, 0);
    $hub = fn (): array => collect($this->withHeaders(PracticeKit::headers($learner))->getJson("/api/twa/practice/hub?course={$course->slug}&lesson_id={$lesson->id}")->assertOk()->json('modes'))->firstWhere('mode', 'dictation');

    expect($hub())->toBe(['mode' => 'dictation', 'state' => 'not_enough', 'available' => 2, 'required' => 3, 'reason' => 'no_audio_words', 'estimate_seconds' => 180]);

    DictationKit::word($lesson, $learner, 'mother');
    expect($hub())->toBe(['mode' => 'dictation', 'state' => 'available', 'available' => 3, 'required' => 3, 'reason' => null, 'estimate_seconds' => 180]);
});

// ------------------------------------------------------------------ answers and the review

it('plays a session through: the verdicts, the review after each answer, the summary of the mistakes', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    DictationKit::stock($lesson, $learner, 5, 6);
    $headers = PracticeKit::headers($learner);

    $session = dsOpen($this, $learner, $course)->assertCreated()->json();
    $id = $session['id'];
    $elements = dsSnapshot($id)['elements'];

    // 1. A word in other capitals with a full stop: right.
    $right = dsAnswer($this, $learner, $id, $elements[0]['id'], ['text' => mb_strtoupper($elements[0]['display']).'.'])->assertOk();
    expect($right->json('verdict'))->toBe('correct')
        ->and($right->json('first_attempt'))->toBeTrue()
        ->and($right->json('reveal.expected'))->toBe($elements[0]['display'])
        ->and($right->json('reveal.translation'))->toBe($elements[0]['translation']['ru'])
        ->and($right->json('reveal.explanation'))->toBeNull()
        ->and($right->json('session.progress'))->toBe(['done' => 1, 'total' => 5])
        // the answer shows the NEXT element without its text
        ->and((string) $right->getContent())->not->toContain($elements[1]['display'])->not->toContain($elements[1]['audio_path']);

    // 2. A typo: shown as one, and not a right first try.
    $typo = dsAnswer($this, $learner, $id, $elements[1]['id'], ['text' => dsTypo($elements[1]['display'])])->assertOk();
    expect($typo->json('verdict'))->toBe('typo')
        ->and($typo->json('first_attempt'))->toBeTrue()
        ->and($typo->json('reveal.expected'))->toBe($elements[1]['display'])
        ->and($typo->json('reveal.your_text'))->toBe(dsTypo($elements[1]['display']))
        ->and($typo->json('reveal.diff'))->toHaveCount(1)
        ->and($typo->json('reveal.diff.0.ok'))->toBeFalse()
        ->and($typo->json('session.counters'))->toBe(['correct_first' => 1, 'hints' => 0, 'mismatches' => 0]);

    // 3. A wrong text of the same number of words: all the words are marked.
    $wrong = dsAnswer($this, $learner, $id, $elements[2]['id'], ['text' => 'something else entirely'])->assertOk();
    expect($wrong->json('verdict'))->toBe('wrong')
        ->and($wrong->json('reveal.expected'))->toBe($elements[2]['display'])
        ->and(array_column($wrong->json('reveal.diff'), 'ok'))->toBe([false, false, false])
        ->and($wrong->json('reveal.translation'))->toBe($elements[2]['translation']['ru']);

    // 4. "I don't know": a hint, with the review.
    $skip = dsAnswer($this, $learner, $id, $elements[3]['id'], ['skip' => true])->assertOk();
    expect($skip->json('verdict'))->toBe('skipped')
        ->and($skip->json('reveal.your_text'))->toBeNull()
        ->and($skip->json('reveal.expected'))->toBe($elements[3]['display'])
        ->and($skip->json('session.counters.hints'))->toBe(1);

    // 5. The last one: right, the session is over and the summary tells the mistakes.
    $last = dsAnswer($this, $learner, $id, $elements[4]['id'], ['text' => $elements[4]['display']])->assertOk();
    $summary = $last->json('session.summary');

    expect($last->json('session.status'))->toBe('finished')
        ->and($last->json('session.current'))->toBeNull()
        ->and($summary)->toMatchArray(['total' => 5, 'answered' => 5, 'correct_first' => 2, 'typos' => 1, 'hints' => 1, 'mismatches' => null, 'finish_reason' => 'completed', 'retry_available' => true])
        ->and($summary['mistakes'])->toBe([
            ['element_id' => $elements[1]['id'], 'kind' => 'dictation', 'label' => $elements[1]['display'], 'your_answer' => dsTypo($elements[1]['display']), 'verdict' => 'typo'],
            ['element_id' => $elements[2]['id'], 'kind' => 'dictation', 'label' => $elements[2]['display'], 'your_answer' => 'something else entirely', 'verdict' => 'wrong'],
            ['element_id' => $elements[3]['id'], 'kind' => 'dictation', 'label' => $elements[3]['display'], 'your_answer' => null, 'verdict' => 'skipped'],
        ]);

    // The finished session is read again with the same summary.
    $this->withHeaders($headers)->getJson("/api/twa/practice/sessions/{$id}")->assertOk()->assertJsonPath('summary.mistakes.1.label', $elements[2]['display']);
    expect(PracticeAttempt::query()->where('session_id', $id)->orderBy('id')->pluck('verdict')->map(fn ($v) => $v->value)->all())->toBe(['correct', 'typo', 'wrong', 'skipped', 'correct']);
});

it('counts a repeated request once and gives the same review back', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    DictationKit::stock($lesson, $learner, 3, 2);
    $session = dsOpen($this, $learner, $course)->assertCreated()->json();
    $element = dsSnapshot($session['id'])['elements'][0];
    $requestId = (string) Str::uuid();

    $first = dsAnswer($this, $learner, $session['id'], $element['id'], ['text' => dsTypo($element['display'])], $requestId)->assertOk();
    $again = dsAnswer($this, $learner, $session['id'], $element['id'], ['text' => dsTypo($element['display'])], $requestId)->assertOk();

    expect($again->json('verdict'))->toBe($first->json('verdict'))
        ->and(json_encode($again->json('reveal')))->toBe(json_encode($first->json('reveal')))
        ->and(PracticeAttempt::query()->where('session_id', $session['id'])->count())->toBe(1);

    // Another request for the element that has its answer is refused with the verdict of the first.
    dsAnswer($this, $learner, $session['id'], $element['id'], ['text' => $element['display']])
        ->assertStatus(409)->assertJsonPath('error.code', 'answer_already_accepted')->assertJsonPath('error.details.verdict', 'typo');
});

it('refuses an answer that is not a text or a skip', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    DictationKit::stock($lesson, $learner, 3, 2);
    $session = dsOpen($this, $learner, $course)->assertCreated()->json();

    dsAnswer($this, $learner, $session['id'], $session['current']['id'], ['option_id' => 'oAAAAAAAAA'])
        ->assertStatus(422)->assertJsonPath('error.code', 'invalid_answer_shape')->assertJsonPath('error.details', ['expected' => 'text_or_skip']);
    // A blank text goes through the middleware as null: it is a skip, not an error.
    dsAnswer($this, $learner, $session['id'], $session['current']['id'], ['text' => ''])
        ->assertOk()->assertJsonPath('verdict', 'skipped');
});

// ------------------------------------------------------------------ the repeat of the mistakes

it('repeats the mistakes of a dictation: the words and the phrases it got wrong, as a session of their own', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    DictationKit::stock($lesson, $learner, 5, 6);
    $played = dsPlayThrough($this, $learner, $course);
    $elements = $played['elements'];

    $retry = $this->withHeaders(PracticeKit::headers($learner))
        ->postJson('/api/twa/practice/sessions', ['mode' => 'dictation', 'course' => $course->slug, 'retry_of' => $played['id'], 'source' => 'retry'])
        ->assertCreated();

    $snapshot = dsSnapshot($retry->json('id'));
    expect($retry->json('retry_of'))->toBe($played['id'])
        ->and($retry->json('progress.total'))->toBe(3)
        // the word (a typo) first, then the two phrases (a mistake, a hint)
        ->and(array_column($snapshot['elements'], 'source'))->toBe(['word', 'phrase', 'phrase'])
        ->and(array_column($snapshot['elements'], 'display'))->toEqualCanonicalizing([$elements[1]['display'], $elements[2]['display'], $elements[3]['display']]);
});

// ------------------------------------------------------------------ the swap of a clip that does not play

it('swaps a word for the next word without a penalty, and writes down what happened', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    DictationKit::stock($lesson, $learner, 5, 6);
    $session = dsOpen($this, $learner, $course)->assertCreated()->json();
    $before = dsSnapshot($session['id']);
    $firstSpareWord = collect($before['pool'])->firstWhere('source', 'word');

    $response = dsReplace($this, $learner, $session['id'], $session['current']['id'])->assertOk();

    $after = dsSnapshot($session['id']);
    expect($response->json('current.source'))->toBe('word')
        ->and($response->json('current.id'))->not->toBe($session['current']['id'])
        ->and($response->json('current.replacements_left'))->toBe(1)
        ->and($response->json('progress'))->toBe(['done' => 0, 'total' => 5])
        ->and($response->json('counters'))->toBe(['correct_first' => 0, 'hints' => 0, 'mismatches' => 0])
        ->and($after['elements'][0]['word_id'])->toBe($firstSpareWord['word_id'])
        ->and(array_column($after['elements'], 'word_id'))->not->toContain($before['elements'][0]['word_id'])
        ->and($after['pool'])->toHaveCount(5)
        ->and(array_column($after['pool'], 'word_id'))->not->toContain($firstSpareWord['word_id']);

    $attempt = PracticeAttempt::query()->where('session_id', $session['id'])->firstOrFail();
    expect($attempt->kind)->toBe(PracticeAttempt::KIND_REPLACE)
        ->and($attempt->element_id)->toBe($session['current']['id'])
        ->and($attempt->verdict)->toBeNull();
    $event = ProductEvent::query()->where('name', 'practice.audio_failed')->firstOrFail();
    expect($event->props)->toMatchArray(['session_id' => $session['id'], 'source' => 'word', 'reason' => 'client_report', 'verified' => false]);
});

it('swaps for a phrase when no spare word is left, and the new clip is the phrase\'s own', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    DictationKit::stock($lesson, $learner, 6, 2); // two words in the session and none left over; three phrases are left over
    $session = dsOpen($this, $learner, $course)->assertCreated()->json();
    expect(array_column(dsSnapshot($session['id'])['pool'], 'source'))->toBe(['phrase', 'phrase', 'phrase']);

    $response = dsReplace($this, $learner, $session['id'], $session['current']['id'])->assertOk();

    $swapped = dsSnapshot($session['id'])['elements'][0];
    expect($response->json('current.source'))->toBe('phrase')
        ->and($response->json('progress.total'))->toBe(5)
        ->and($swapped['source'])->toBe('phrase')
        ->and($swapped['audio_disk'])->toBe('practice_audio');
    // The clip of the new element is streamed from the disk of the phrases; the one that went has no URL any more.
    $this->withHeaders(PracticeKit::headers($learner))->get($response->json('current.audio_url'))->assertOk();
    $this->withHeaders(PracticeKit::headers($learner))->get($session['current']['audio_url'])->assertNotFound();
});

it('drops the element and shortens the session when there is nothing to swap it for', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    DictationKit::stock($lesson, $learner, 3, 2); // exactly what the session takes: no spares
    $session = dsOpen($this, $learner, $course)->assertCreated()->json();
    $elements = dsSnapshot($session['id'])['elements'];
    expect(dsSnapshot($session['id'])['pool'])->toBe([]);

    $response = dsReplace($this, $learner, $session['id'], $elements[0]['id'])->assertOk();

    expect($response->json('progress'))->toBe(['done' => 0, 'total' => 4])
        ->and($response->json('current.id'))->toBe($elements[1]['id'])
        ->and($response->json('current.replacements_left'))->toBe(1)
        ->and(PracticeSession::query()->findOrFail($session['id'])->total)->toBe(4);

    // The rest is played (a skip each); the element that went is not in the summary, and does not count.
    $current = $response->json('current');
    $last = null;
    for ($i = 1; $i < 5; $i++) {
        $last = dsAnswer($this, $learner, $session['id'], $elements[$i]['id'], ['skip' => true])->assertOk();
    }
    expect($last?->json('session.status'))->toBe('finished')
        ->and($current['id'])->toBe($elements[1]['id'])
        ->and($last?->json('session.summary.total'))->toBe(4)
        ->and($last?->json('session.summary.answered'))->toBe(4)
        ->and(array_column($last?->json('session.summary.mistakes') ?? [], 'element_id'))->toBe([$elements[1]['id'], $elements[2]['id'], $elements[3]['id'], $elements[4]['id']])
        ->and(array_column($last?->json('session.summary.mistakes') ?? [], 'label'))->not->toContain($elements[0]['display']);
});

it('never takes the last element away: a session keeps one', function (): void {
    config(['practice.solo.dictation.max_replacements' => 10]);
    [$learner, $course, $lesson] = DictationKit::world();
    DictationKit::stock($lesson, $learner, 3, 0);
    $session = dsOpen($this, $learner, $course)->assertCreated()->json();
    $elements = dsSnapshot($session['id'])['elements'];

    dsReplace($this, $learner, $session['id'], $elements[0]['id'])->assertOk()->assertJsonPath('progress.total', 2);
    dsReplace($this, $learner, $session['id'], $elements[1]['id'])->assertOk()->assertJsonPath('progress.total', 1);
    dsReplace($this, $learner, $session['id'], $elements[2]['id'])
        ->assertStatus(409)->assertJsonPath('error.code', 'replace_not_allowed')->assertJsonPath('error.details', ['reason' => 'limit']);

    // The session is still on, with its one element.
    $this->withHeaders(PracticeKit::headers($learner))->getJson("/api/twa/practice/sessions/{$session['id']}")
        ->assertOk()->assertJsonPath('status', 'active')->assertJsonPath('current.id', $elements[2]['id']);
    expect(PracticeSession::query()->findOrFail($session['id'])->status)->toBe(SessionStatus::Active);
});

it('allows two swaps and refuses the third with the reason "limit"', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    DictationKit::stock($lesson, $learner, 5, 6);
    $session = dsOpen($this, $learner, $course)->assertCreated()->json();

    $one = dsReplace($this, $learner, $session['id'], $session['current']['id'])->assertOk();
    $two = dsReplace($this, $learner, $session['id'], $one->json('current.id'))->assertOk();
    expect($two->json('current.replacements_left'))->toBe(0);

    dsReplace($this, $learner, $session['id'], $two->json('current.id'))
        ->assertStatus(409)->assertJsonPath('error.code', 'replace_not_allowed')->assertJsonPath('error.details', ['reason' => 'limit']);
    expect(PracticeAttempt::query()->where('session_id', $session['id'])->where('kind', PracticeAttempt::KIND_REPLACE)->count())->toBe(2);
});

it('swaps only the current element, and names the reason when it cannot', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    DictationKit::stock($lesson, $learner, 5, 6);
    $session = dsOpen($this, $learner, $course)->assertCreated()->json();
    $elements = dsSnapshot($session['id'])['elements'];

    dsReplace($this, $learner, $session['id'], $elements[3]['id'])
        ->assertStatus(409)->assertJsonPath('error.details', ['reason' => 'not_current']);
    dsReplace($this, $learner, $session['id'], 'eNoSuchEl00')->assertStatus(422)->assertJsonPath('error.code', 'unknown_element_id');
});

it('passes over a spare whose file is gone and takes the next', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    DictationKit::stock($lesson, $learner, 5, 6);
    $session = dsOpen($this, $learner, $course)->assertCreated()->json();
    $spareWords = collect(dsSnapshot($session['id'])['pool'])->where('source', 'word')->values();
    Storage::disk('public')->delete($spareWords[0]['audio_path']);

    $response = dsReplace($this, $learner, $session['id'], $session['current']['id'])->assertOk();

    $after = dsSnapshot($session['id']);
    expect($after['elements'][0]['word_id'])->toBe($spareWords[1]['word_id'])
        ->and(array_column($after['pool'], 'word_id'))->not->toContain($spareWords[0]['word_id'])->not->toContain($spareWords[1]['word_id'])
        ->and($response->json('progress.total'))->toBe(5);
});

it('does not count a swapped element in the summary', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    DictationKit::stock($lesson, $learner, 5, 6);
    $session = dsOpen($this, $learner, $course)->assertCreated()->json();
    $gone = dsSnapshot($session['id'])['elements'][0];
    dsReplace($this, $learner, $session['id'], $gone['id'])->assertOk();

    $elements = dsSnapshot($session['id'])['elements'];
    $last = null;
    foreach ($elements as $element) {
        $last = dsAnswer($this, $learner, $session['id'], $element['id'], ['skip' => true])->assertOk();
    }

    expect($last?->json('session.summary.total'))->toBe(5)
        ->and(array_column($last?->json('session.summary.mistakes') ?? [], 'label'))->not->toContain($gone['display'])
        ->and(array_column($last?->json('session.summary.mistakes') ?? [], 'element_id'))->not->toContain($gone['id']);
});

// ------------------------------------------------------------------ the budgets of the requests

it('reads the hub in at most 9 statements, and the words and the tasks once each', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    DictationKit::stock($lesson, $learner, 5, 6);

    $queries = QueryLog::during(fn () => $this->withHeaders(PracticeKit::headers($learner))->getJson("/api/twa/practice/hub?course={$course->slug}&lesson_id={$lesson->id}")->assertOk()->assertJsonPath('modes.3.available', 11));

    expect(count($queries))->toBeLessThanOrEqual(9, implode("\n", $queries))
        ->and(count(QueryLog::matching($queries, '/^select .* from ["`]words["`]/i')))->toBe(1)
        ->and(count(QueryLog::matching($queries, '/^select .* from ["`]word_translations["`]/i')))->toBe(1)
        ->and(count(QueryLog::matching($queries, '/^select .* from ["`]practice_items["`]/i')))->toBe(1)
        ->and(count(QueryLog::matching($queries, '/^select .* from ["`]practice_item_versions["`]/i')))->toBe(1);
});

it('opens a session in at most 12 statements and takes an answer in at most 6 and a swap in at most 7 (one read of the session before the lock: the disk is looked at outside it)', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    DictationKit::stock($lesson, $learner, 5, 6);
    $session = null;

    $open = QueryLog::during(function () use ($learner, $course, $lesson, &$session): void {
        $session = dsOpen($this, $learner, $course, ['lesson_id' => $lesson->id])->assertCreated()->json();
    });
    $elements = dsSnapshot($session['id'])['elements'];
    $answer = QueryLog::during(fn () => dsAnswer($this, $learner, $session['id'], $elements[0]['id'], ['text' => $elements[0]['display']])->assertOk());
    $swap = QueryLog::during(fn () => dsReplace($this, $learner, $session['id'], $elements[1]['id'])->assertOk());

    expect(count($open))->toBeLessThanOrEqual(12, implode("\n", $open))
        ->and(count($answer))->toBeLessThanOrEqual(6, implode("\n", $answer))
        ->and(count($swap))->toBeLessThanOrEqual(7, implode("\n", $swap))
        ->and(strlen((string) json_encode($session)))->toBeLessThan(8 * 1024)
        ->and(strlen((string) json_encode(dsSnapshot($session['id']))))->toBeLessThan(Snapshot::MAX_BYTES);
});

it('looks at the clips of the spares BEFORE the lock of the swap, never inside its transaction (docs/16 §9 rule 10), and swaps for a spare whose clip is there', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    DictationKit::stock($lesson, $learner, 5, 6);
    $session = dsOpen($this, $learner, $course, ['lesson_id' => $lesson->id])->assertCreated()->json();
    $snapshot = dsSnapshot($session['id']);
    $pool = $snapshot['pool'];
    expect($pool)->not->toBeEmpty();

    // The first spare of the same source has lost its clip: the swap must pass it by.
    $sameSource = array_values(array_filter($pool, fn (array $spare): bool => $spare['source'] === $snapshot['elements'][0]['source']));
    Storage::disk($sameSource[0]['audio_disk'])->delete($sameSource[0]['audio_path']);

    // Every look at a disk is noted with the depth of the transactions at that moment.
    $levels = [];
    foreach (['practice_audio', 'public'] as $name) {
        $real = Storage::disk($name);
        $spy = Mockery::mock($real);
        foreach (['exists', 'size'] as $method) {
            $spy->shouldReceive($method)->andReturnUsing(function (string $path) use ($real, $method, $name, &$levels) {
                $levels[] = ['disk' => $name, 'call' => $method, 'level' => DB::transactionLevel()];

                return $real->{$method}($path);
            });
        }
        Storage::set($name, $spy);
    }
    $outside = DB::transactionLevel();   // the transaction the test itself runs in

    $swapped = dsReplace($this, $learner, $session['id'], $session['current']['id'])->assertOk();

    // The disk was read (the spares were looked at) ...
    expect($levels)->not->toBeEmpty()
        // ... and not once with the transaction of the swap open.
        ->and(array_values(array_unique(array_column($levels, 'level'))))->toBe([$outside]);

    // The swap took a spare whose clip is there, not the one that lost it; and that one left the pool.
    $after = dsSnapshot($session['id']);
    $current = collect($after['elements'])->firstWhere('id', $swapped->json('current.id'));
    expect($current['audio_path'])->not->toBe($sameSource[0]['audio_path'])
        ->and(array_column($after['pool'], 'audio_path'))->not->toContain($sameSource[0]['audio_path'])
        ->and(array_column($after['pool'], 'audio_path'))->not->toContain($current['audio_path']);
});

it('takes the element out when no spare has its clip any more - without reading the disk under the lock either', function (): void {
    [$learner, $course, $lesson] = DictationKit::world();
    DictationKit::stock($lesson, $learner, 5, 6);
    $session = dsOpen($this, $learner, $course, ['lesson_id' => $lesson->id])->assertCreated()->json();
    $spares = dsSnapshot($session['id'])['pool'];
    foreach ($spares as $spare) {
        Storage::disk($spare['audio_disk'])->delete($spare['audio_path']);
    }

    $swapped = dsReplace($this, $learner, $session['id'], $session['current']['id'])->assertOk();

    // No clip to play: the element goes and the session gets shorter, as it did when the disk was read under the lock.
    expect($swapped->json('progress.total'))->toBe(4)
        ->and(count(dsSnapshot($session['id'])['pool']))->toBe(count($spares))
        ->and(array_column(dsSnapshot($session['id'])['elements'], 'id'))->not->toContain($session['current']['id']);
});
