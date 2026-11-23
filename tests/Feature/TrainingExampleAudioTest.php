<?php

declare(strict_types=1);

use App\Domain\Content\Contracts\SpeechSynthesizer;
use App\Domain\Content\Services\WordAudioService;
use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Twa\Services\JwtService;
use App\Jobs\PregenerateExampleAudioJob;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\Subscription;
use App\Models\TrainingSession;
use App\Models\Word;
use App\Models\WordExample;
use App\Models\WordRepetition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

/*
 * docs/26 §9.11 - `example_audio_url` next to `example`: `card.example_audio_url` of the training card (hidden with the example)
 * and `revealed.example_audio_url` after a meaning / reverse / audio pick. Only the anchor example is voiced: the URL is there when
 * `words.example_audio_path` is the file of exactly the sentence shown, null for a rotated example, a stale path, a hidden example
 * (meaning / reverse / audio, spelling / gap-fill, pretest) and a learner's own deck. The bucket is never asked.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'twa.tts.voice' => 'en-US-Neural2-F',
        'twa.tts.audio_version' => 1,
        'twa.tts.audio_disk' => 'public',
        // The parity ladder of docs/06 §12.1 (the early ladder is LearningSprintTest): a mature card is a four-option one.
        'learning.ladder.typed_from_interval_days' => 21,
        'learning.ladder.early.enabled' => false,
    ]);
    Storage::fake('public');
});

/**
 * A personal lesson session of five words with anchor examples; only `word0` is due (the others give the four options).
 *
 * @return array{learner: Learner, session: TrainingSession, word: Word, url: string, headers: array<string, string>}
 */
function eaScenario(?Course $course = null, ?Learner $learner = null, int $repetitions = 3, int $intervalDays = 15): array
{
    $learner ??= Learner::factory()->create();
    $course ??= Course::factory()->create(['status' => 'published', 'visibility' => 'public']);
    $lesson = Lesson::factory()->for(Stage::factory()->create(['course_id' => $course->id]))->create();
    $words = [];
    for ($i = 0; $i < 5; $i++) {
        $words[] = $word = Word::factory()->for($lesson)->create(['word' => "word{$i}", 'translation' => "перевод {$i}", 'example' => "I like word{$i} a lot."]);
        WordRepetition::factory()->create([
            'learner_id' => $learner->id,
            'word_id' => $word->id,
            'state' => RepetitionState::Review->value,
            'repetitions' => $repetitions,
            'interval_days' => $intervalDays,
            'next_review_at' => $i === 0 ? now()->subHour() : now()->addDay(),
            'introduced_mode' => 'lesson',
            'lapses' => 0,
            'is_hard' => false,
            'meta' => [],
        ]);
    }
    $session = TrainingSession::factory()->personal($learner, 'lesson')->create(['lesson_id' => $lesson->id]);

    return [
        'learner' => $learner, 'session' => $session, 'word' => $words[0],
        'url' => "/api/twa/training/sessions/{$session->id}",
        'headers' => ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']],
    ];
}

/** Only the given exercise ('flash' - none of the others) for the learner. */
function eaOnly(array $s, string $type): void
{
    $s['learner']->forceFill(['meta' => ['exercises' => [
        'meaning' => $type === 'meaning', 'reverse' => $type === 'reverse', 'audio' => $type === 'audio',
        'spelling' => $type === 'spelling', 'gap_fill' => $type === 'gap_fill',
    ]]])->save();
}

/** Records the voiced file of the word's current anchor example, as the job does. */
function eaVoice(Word $word): string
{
    $path = (string) app(WordAudioService::class)->examplePathFor($word->example);
    Storage::disk('public')->put($path, 'mp3');
    $word->forceFill(['example_audio_path' => $path])->save();

    return Storage::disk('public')->url($path);
}

function eaRepeat(array $s, int $repetitions, int $intervalDays = 15): void
{
    WordRepetition::query()->where('learner_id', $s['learner']->id)->where('word_id', $s['word']->id)->update([
        'state' => RepetitionState::Review->value, 'repetitions' => $repetitions, 'interval_days' => $intervalDays,
        'next_review_at' => now()->subHour(), 'served_ahead_token' => null, 'served_ahead_at' => null,
    ]);
}

/** @return array<string, mixed> */
function eaCard(array $s): array
{
    return test()->withHeaders($s['headers'])->getJson("{$s['url']}/next")->assertOk()->json('card');
}

it('gives the flash card the URL of its voiced anchor example', function (): void {
    $s = eaScenario();
    eaOnly($s, 'flash');

    // Not voiced yet: no button.
    $card = eaCard($s);
    expect($card['prompt']['type'])->toBe('flash')
        ->and($card['example'])->toBe('I like word0 a lot.')
        ->and($card['example_audio_url'])->toBeNull();

    $url = eaVoice($s['word']);
    expect(eaCard($s)['example_audio_url'])->toBe($url)->toEndWith('/storage/'.$s['word']->example_audio_path);

    // The start response carries the same card.
    $this->withHeaders($s['headers'])->postJson("{$s['url']}/start")->assertOk()->assertJsonPath('card.example_audio_url', $url);
});

it('gives no URL to a rotated example, and the anchor\'s again when the rotation comes back to it', function (): void {
    $s = eaScenario();
    eaOnly($s, 'flash');
    WordExample::query()->create(['word_id' => $s['word']->id, 'position' => 1, 'text' => 'We word0 together.', 'translations' => []]);
    $url = eaVoice($s['word']);

    // Graduated: index `repetitions mod 2` - 3 -> the extra sentence, 4 -> the anchor.
    eaRepeat($s, 3);
    expect(eaCard($s))->toMatchArray(['example' => 'We word0 together.', 'example_audio_url' => null]);
    eaRepeat($s, 4);
    expect(eaCard($s))->toMatchArray(['example' => 'I like word0 a lot.', 'example_audio_url' => $url]);
});

it('gives no URL while the recorded path is not the file of the current sentence', function (string $change): void {
    $s = eaScenario();
    eaOnly($s, 'flash');
    eaVoice($s['word']);

    if ($change === 'text') {
        // Edited past the refresher (the path is not reset yet): the file is of the old sentence.
        Word::query()->whereKey($s['word']->id)->update(['example' => 'I love word0 dearly.']);
    } else {
        config(['twa.tts.audio_version' => 2]);
    }

    $card = eaCard($s);
    expect($card['example'])->not->toBeNull()
        ->and($card['example_audio_url'])->toBeNull();
})->with(['text', 'voice version']);

it('hides the URL with the example on a card that hides it, and returns it in `revealed`', function (string $type): void {
    $s = eaScenario(repetitions: $type === 'meaning' ? 2 : 3, intervalDays: $type === 'meaning' ? 6 : 15);
    eaOnly($s, $type);
    $url = eaVoice($s['word']);

    $card = eaCard($s);
    expect($card['prompt']['type'])->toBe($type)
        ->and($card['example'])->toBeNull()
        ->and($card['example_audio_url'])->toBeNull();

    $answer = $type === 'reverse' ? 'word0' : 'перевод 0';
    $response = $this->withHeaders($s['headers'])
        ->postJson("{$s['url']}/review", ['word_id' => $card['word_id'], 'answer' => $answer, 'exercise' => $type, 'time_spent_ms' => 700])
        ->assertOk();
    expect($response->json('revealed.example'))->toBe('I like word0 a lot.')
        ->and($response->json('revealed.example_audio_url'))->toBe($url)
        ->and($response->json('graded.correct'))->toBeTrue();
})->with(['meaning', 'reverse', 'audio']);

it('hides the URL with the example on a typed card, the masked sentence of a gap-fill carrying none', function (string $type): void {
    // The typed rung of the ladder (`interval_days` >= 21): gap-fill when the example carries the word, else spelling.
    $s = eaScenario(repetitions: 4, intervalDays: 30);
    Subscription::factory()->create(['subscriber_id' => $s['learner']->id, 'provider' => 'promo']); // the typed exercises are Pro
    eaOnly($s, $type);
    eaVoice($s['word']);

    $card = eaCard($s);
    expect($card['prompt']['type'])->toBe($type)
        ->and($card['example'])->toBeNull()
        ->and($card['example_audio_url'])->toBeNull()
        ->and($card['prompt'])->not->toHaveKey('example_audio_url');
    if ($type === 'gap_fill') {
        expect($card['prompt']['masked_example'])->toBeString()->not->toContain('word0');
    }
})->with(['spelling', 'gap_fill']);

it('hides the URL with the example on a pretest card', function (): void {
    config(['learning.pretest.enabled' => true]);
    $s = eaScenario();
    WordRepetition::query()->where('learner_id', $s['learner']->id)->where('word_id', $s['word']->id)->update([
        'state' => RepetitionState::New->value, 'repetitions' => 0, 'interval_days' => 0, 'next_review_at' => now()->subMinute(),
    ]);
    eaOnly($s, 'flash');
    eaVoice($s['word']);

    $card = eaCard($s);
    expect($card['prompt']['type'])->toBe('pretest')
        ->and($card['example'])->toBeNull()
        ->and($card['example_audio_url'])->toBeNull();
});

it('returns no URL in `revealed` for a rotated example', function (): void {
    $s = eaScenario();
    eaOnly($s, 'reverse');
    WordExample::query()->create(['word_id' => $s['word']->id, 'position' => 1, 'text' => 'We word0 together.', 'translations' => []]);
    eaVoice($s['word']);

    $card = eaCard($s);
    $response = $this->withHeaders($s['headers'])
        ->postJson("{$s['url']}/review", ['word_id' => $card['word_id'], 'answer' => 'word0', 'exercise' => 'reverse', 'time_spent_ms' => 700])
        ->assertOk();
    expect($response->json('revealed.example'))->toBe('We word0 together.')
        ->and($response->json('revealed.example_audio_url'))->toBeNull();
});

it('gives no URL on a learner\'s own deck: its examples are never voiced', function (): void {
    $learner = Learner::factory()->create();
    $s = eaScenario(Course::factory()->deckOf((int) $learner->id)->create(), $learner);
    eaOnly($s, 'flash');
    $synth = new class implements SpeechSynthesizer
    {
        public int $calls = 0;

        public function synthesize(string $text, string $voice): array
        {
            $this->calls++;

            return ['body' => 'mp3', 'content_type' => 'audio/mpeg'];
        }
    };
    app()->instance(SpeechSynthesizer::class, $synth);

    (new PregenerateExampleAudioJob((int) $s['word']->id))->handle(app(WordAudioService::class));

    $card = eaCard($s);
    expect($synth->calls)->toBe(0)
        ->and($card['example'])->toBe('I like word0 a lot.')
        ->and($card['example_audio_url'])->toBeNull();
});
