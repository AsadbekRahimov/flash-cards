<?php

declare(strict_types=1);

use App\Domain\Content\Contracts\SpeechSynthesizer;
use App\Domain\Content\Exceptions\SpeechSynthesisException;
use App\Domain\Content\Services\Tts\GoogleSpeechSynthesizer;
use App\Domain\Content\Services\Tts\NullSpeechSynthesizer;
use App\Domain\Content\Services\VocabularyImporter;
use App\Domain\Content\Services\WordAudioCoverageService;
use App\Domain\Content\Services\WordAudioDiskMigrator;
use App\Domain\Content\Services\WordAudioPruner;
use App\Domain\Content\Services\WordAudioScheduler;
use App\Domain\Content\Services\WordAudioService;
use App\Filament\Resources\LessonResource\Pages\EditLesson;
use App\Filament\Resources\LessonResource\RelationManagers\WordsRelationManager;
use App\Filament\Resources\WordResource\Pages\EditWord;
use App\Filament\Resources\WordResource\RelationManagers\WordExamplesRelationManager;
use App\Jobs\PregenerateAudioJob;
use App\Jobs\PregenerateExampleAudioJob;
use App\Jobs\PregenerateWordAudioJob;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\User;
use App\Models\Word;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

use function Pest\Livewire\livewire;

/*
 * docs/26 §5.7, §9.11 - the audio of the anchor example (`words.example` -> `words.example_audio_path`): the path of the sentence
 * as it goes to Google (case and punctuation kept, blanks collapsed), readiness by the path alone, the guarded update, the job and
 * every point that queues it, the reconciliation (words first, one limit), `audio:pregenerate`, `audio:prune` (keeps the files of
 * the examples), `audio:migrate-disk` (copies them) and `audio:coverage` (a line of its own). Plus docs/26 §9.11 item 6: with TTS
 * switched off an edit drops the path and queues nothing.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['twa.tts.voice' => 'en-US-Neural2-F', 'twa.tts.audio_version' => 1, 'twa.tts.audio_disk' => 'public']);
    Storage::fake('public');
    Queue::fake();
    exSynth();
});

/** A configured synthesizer that records the texts it was given. */
function exSynth(?SpeechSynthesisException $error = null): object
{
    $synth = new class($error) implements SpeechSynthesizer
    {
        /** @var list<string> */
        public array $texts = [];

        public function __construct(private readonly ?SpeechSynthesisException $error) {}

        public function synthesize(string $text, string $voice): array
        {
            $this->texts[] = $text;
            if ($this->error !== null) {
                throw $this->error;
            }

            return ['body' => 'mp3-of-'.$text, 'content_type' => 'audio/mpeg'];
        }
    };
    app()->instance(SpeechSynthesizer::class, $synth);

    return $synth;
}

function exSyncQueue(): void
{
    app()->forgetInstance('queue');
    Queue::clearResolvedInstance('queue');
}

function exLesson(?Course $course = null): Lesson
{
    return Lesson::factory()->for(Stage::factory()->create(['course_id' => ($course ?? Course::factory()->create())->id]))->create();
}

function exaWord(string $text, ?string $example, ?Lesson $lesson = null, array $attrs = []): Word
{
    return Word::factory()->for($lesson ?? exLesson())->create(['word' => $text, 'example' => $example] + $attrs);
}

/** The path of a sentence exactly as sent: no lower-casing. */
function exPath(string $spoken): string
{
    return 'audio/'.sha1($spoken.'|en-US-Neural2-F|1').'.mp3';
}

/** @return list<int> */
function exQueued(): array
{
    return Queue::pushed(PregenerateExampleAudioJob::class)->map(fn (PregenerateExampleAudioJob $job): int => $job->wordId)->values()->all();
}

/** @return list<int> */
function exWordsQueued(): array
{
    return Queue::pushed(PregenerateWordAudioJob::class)->map(fn (PregenerateWordAudioJob $job): int => $job->wordId)->values()->all();
}

function exAdmin(): User
{
    test()->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');

    return $admin;
}

/** @param  list<array<string, mixed>>  $words */
function exImport(Course $course, array $words): void
{
    $report = app(VocabularyImporter::class)->importAsSystem((string) json_encode([
        'course' => $course->slug,
        'stage' => ['number' => 1],
        'lesson' => ['number' => 1],
        'words' => $words,
    ]));

    expect($report->aborted)->toBeFalse();
}

// --- the schema -------------------------------------------------------------------------------------

it('has a nullable words.example_audio_path', function (): void {
    $word = exaWord('apple', 'An apple a day.');

    expect(Schema::hasColumn('words', 'example_audio_path'))->toBeTrue()
        ->and($word->fresh()?->example_audio_path)->toBeNull();
});

// --- the path and the readiness ---------------------------------------------------------------------

it('builds the path from the sentence as it is sent: case and punctuation kept, blanks collapsed', function (): void {
    $audio = app(WordAudioService::class);

    expect($audio->exampleText("  I ate an  apple,\n then a pear!  "))->toBe('I ate an apple, then a pear!')
        ->and($audio->examplePathFor("  I ate an  apple,\n then a pear!  "))->toBe(exPath('I ate an apple, then a pear!'))
        ->and($audio->examplePathFor('I ate an apple.'))->not->toBe($audio->examplePathFor('i ate an apple.'))
        ->and($audio->examplePathFor('I ate an apple.'))->not->toBe($audio->examplePathFor('I ate an apple'))
        // A sentence sent like a word is the same text, so the same file.
        ->and($audio->examplePathFor('apple'))->toBe($audio->pathFor('apple'))
        ->and($audio->examplePathFor('I ate an apple.', 'en-US-Neural2-C'))->toBe('audio/'.sha1('I ate an apple.|en-US-Neural2-C|1').'.mp3');
});

it('has nothing to voice for no example, a blank one or one longer than a request of Google', function (?string $example): void {
    expect(app(WordAudioService::class)->examplePathFor($example))->toBeNull();
})->with([
    'null' => [null],
    'empty' => [''],
    'blanks' => ["  \n\t "],
    'over 5 000 bytes' => [str_repeat('Ж', 2_501)],
]);

it('takes a sentence of exactly 5 000 bytes', function (): void {
    $text = str_repeat('a', GoogleSpeechSynthesizer::MAX_TEXT_BYTES);

    expect(app(WordAudioService::class)->examplePathFor($text))->toBe(exPath($text));
});

it('gives the URL of the example shown only when the path is the one of that sentence, without the disk', function (): void {
    $word = exaWord('apple', 'I ate an apple.', attrs: ['example_audio_path' => exPath('I ate an apple.')]);
    $audio = app(WordAudioService::class);
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('url')->andReturnUsing(fn (string $path): string => 'https://audio.example.uz/'.$path);
    Storage::set('public', $disk); // anything but url() would fail: there is no look at the disk

    expect($audio->exampleUrlFor($word, 'I ate an apple.'))->toBe('https://audio.example.uz/'.exPath('I ate an apple.'))
        ->and($audio->exampleUrlFor($word, ' I ate  an apple. '))->toBe('https://audio.example.uz/'.exPath('I ate an apple.'))
        // A rotated additional example is not voiced.
        ->and($audio->exampleUrlFor($word, 'Apples are red.'))->toBeNull()
        // No example on the card (hidden, or none at all).
        ->and($audio->exampleUrlFor($word, null))->toBeNull();

    // Edited, the path not caught up yet: the old sentence is not voiced for the new one.
    $word->example = 'She ate an apple.';
    expect($audio->exampleUrlFor($word, $word->example))->toBeNull();

    $word->example_audio_path = null;
    expect($audio->exampleUrlFor($word, 'I ate an apple.'))->toBeNull();

    // Another voice version: every path is stale.
    $word->example_audio_path = exPath('I ate an apple.');
    config(['twa.tts.audio_version' => 2]);
    expect($audio->exampleUrlFor($word, 'I ate an apple.'))->toBeNull();
});

// --- generateExample --------------------------------------------------------------------------------

it('voices the example as it is written and records its path', function (): void {
    $synth = exSynth();
    $word = exaWord('apple', "I ate  an apple.\n");

    expect(app(WordAudioService::class)->generateExample($word))->toBeTrue();

    expect($synth->texts)->toBe(['I ate an apple.'])
        ->and(Storage::disk('public')->get(exPath('I ate an apple.')))->toBe('mp3-of-I ate an apple.')
        ->and($word->example_audio_path)->toBe(exPath('I ate an apple.'))
        ->and($word->fresh()?->example_audio_path)->toBe(exPath('I ate an apple.'))
        ->and($word->fresh()?->audio_path)->toBeNull() // the word is a job of its own
        ->and(app(WordAudioService::class)->isExampleCurrent($word))->toBeTrue();

    // Idempotent: current - no call.
    expect(app(WordAudioService::class)->generateExample($word))->toBeFalse()
        ->and($synth->texts)->toHaveCount(1);

    // Forced: synthesized again.
    expect(app(WordAudioService::class)->generateExample($word, true))->toBeTrue()
        ->and($synth->texts)->toHaveCount(2);
});

it('shares one file between two words with the same example: one call', function (): void {
    $synth = exSynth();
    $first = exaWord('apple', 'Fruit is good.');
    $second = exaWord('pear', 'Fruit is good.');

    app(WordAudioService::class)->generateExample($first);
    expect(app(WordAudioService::class)->generateExample($second))->toBeFalse();

    expect($synth->texts)->toBe(['Fruit is good.'])
        ->and($second->fresh()?->example_audio_path)->toBe(exPath('Fruit is good.'));
});

it('does nothing for a word without an example', function (): void {
    $synth = exSynth();
    $word = exaWord('apple', null);

    expect(app(WordAudioService::class)->generateExample($word))->toBeFalse()
        ->and($synth->texts)->toBe([]);
});

it('does not record the path when the example changed during the synthesis (docs/26 §9.6)', function (): void {
    $word = exaWord('apple', 'I ate an apple.');
    $newPath = exPath('She ate an apple.');
    app()->instance(SpeechSynthesizer::class, new class($word->id, $newPath) implements SpeechSynthesizer
    {
        public function __construct(private readonly int $wordId, private readonly string $newPath) {}

        public function synthesize(string $text, string $voice): array
        {
            // The admin edits the example; the job of the new sentence finishes first.
            Word::query()->whereKey($this->wordId)->update(['example' => 'She ate an apple.', 'example_audio_path' => $this->newPath]);

            return ['body' => 'mp3-of-'.$text, 'content_type' => 'audio/mpeg'];
        }
    });

    expect(app(WordAudioService::class)->generateExample($word))->toBeTrue();

    expect($word->fresh()?->example_audio_path)->toBe($newPath)
        ->and($word->example_audio_path)->toBeNull()
        ->and(Storage::disk('public')->exists(exPath('I ate an apple.')))->toBeTrue();
});

// --- the job ----------------------------------------------------------------------------------------

it('the example job shares the rules of the word job: tts queue, unique until processing, five tries, backoff', function (): void {
    $job = new PregenerateExampleAudioJob(42, true);

    expect($job)->toBeInstanceOf(PregenerateAudioJob::class)
        ->and($job)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
        ->and($job->queue)->toBe('tts')
        ->and($job->uniqueId())->toBe('42:force')
        ->and($job->tries)->toBe(5)
        ->and($job->backoff())->toBe((new PregenerateWordAudioJob(42))->backoff())
        ->and(PregenerateExampleAudioJob::STORE_ATTEMPTS)->toBe(2);
});

it('the word and its example wait in the queue side by side: the locks are per class', function (): void {
    $word = exaWord('apple', 'I ate an apple.');

    PregenerateWordAudioJob::dispatch($word->id);
    PregenerateExampleAudioJob::dispatch($word->id);
    PregenerateExampleAudioJob::dispatch($word->id); // waiting: dropped

    expect(exWordsQueued())->toBe([$word->id])
        ->and(exQueued())->toBe([$word->id]);
});

it('the example job voices the example and not the word', function (): void {
    exSyncQueue();
    $synth = exSynth();
    $word = exaWord('apple', 'I ate an apple.');

    PregenerateExampleAudioJob::dispatch($word->id);

    expect($synth->texts)->toBe(['I ate an apple.'])
        ->and($word->fresh()?->example_audio_path)->toBe(exPath('I ate an apple.'))
        ->and($word->fresh()?->audio_path)->toBeNull();
});

it('the example job skips a learner\'s deck, a switched-off and a deleted word', function (): void {
    $synth = exSynth();
    $deck = exaWord('apple', 'I ate an apple.', exLesson(Course::factory()->create(['owner_type' => 'learner'])));
    $off = exaWord('pear', 'A pear.', attrs: ['is_active' => false]);
    $audio = app(WordAudioService::class);

    (new PregenerateExampleAudioJob($deck->id))->handle($audio);
    (new PregenerateExampleAudioJob($off->id))->handle($audio);
    (new PregenerateExampleAudioJob(999_999))->handle($audio);

    expect($synth->texts)->toBe([])
        ->and($deck->fresh()?->example_audio_path)->toBeNull();
});

it('the example job ends quietly at the monthly limit and fails at once on a final error', function (): void {
    $word = exaWord('apple', 'I ate an apple.');
    Log::spy();

    exSynth(SpeechSynthesisException::monthlyLimitReached());
    $quiet = Mockery::mock(QueueJob::class);
    $quiet->shouldNotReceive('fail');
    $job = new PregenerateExampleAudioJob($word->id);
    $job->setJob($quiet);
    $job->handle(app(WordAudioService::class));

    exSynth(SpeechSynthesisException::rejected());
    $failing = Mockery::mock(QueueJob::class);
    $failing->shouldReceive('fail')->once();
    $job = new PregenerateExampleAudioJob($word->id);
    $job->setJob($failing);
    $job->handle(app(WordAudioService::class));

    $job->failed(new RuntimeException('tts_request_rejected'));
    Log::shouldHaveReceived('warning')->once()->with('audio.pregenerate_example_failed', ['word_id' => $word->id, 'reason' => 'tts_request_rejected']);
});

it('the example job throws a retryable error for the retry', function (): void {
    $word = exaWord('apple', 'I ate an apple.');
    exSynth(SpeechSynthesisException::rateLimited());

    (new PregenerateExampleAudioJob($word->id))->handle(app(WordAudioService::class));
})->throws(SpeechSynthesisException::class);

// --- a new word in the admin panel ------------------------------------------------------------------

it('queues the word and its example when a word with an example is added on the lesson screen', function (): void {
    $this->actingAs(exAdmin());
    $lesson = exLesson();

    livewire(WordsRelationManager::class, ['ownerRecord' => $lesson, 'pageClass' => EditLesson::class])
        ->callTableAction('create', data: ['word' => 'resilient', 'translation' => 'стойкий', 'example' => 'She is resilient.'])
        ->assertHasNoTableActionErrors();
    livewire(WordsRelationManager::class, ['ownerRecord' => $lesson, 'pageClass' => EditLesson::class])
        ->callTableAction('create', data: ['word' => 'durable', 'translation' => 'прочный'])
        ->assertHasNoTableActionErrors();

    $resilient = Word::query()->where('word', 'resilient')->sole();
    $durable = Word::query()->where('word', 'durable')->sole();
    expect(exWordsQueued())->toBe([$resilient->id, $durable->id])
        ->and(exQueued())->toBe([$resilient->id]);
    Queue::assertPushed(PregenerateExampleAudioJob::class, fn (PregenerateExampleAudioJob $job): bool => $job->afterCommit === true && $job->queue === 'tts' && ! $job->force);
});

it('queues no example of a new word of a learner\'s deck, a switched-off word or while TTS is not configured', function (): void {
    app(WordAudioScheduler::class)->wordCreated(exaWord('apple', 'An apple.', exLesson(Course::factory()->create(['owner_type' => 'learner']))));
    app(WordAudioScheduler::class)->wordCreated(exaWord('pear', 'A pear.', attrs: ['is_active' => false]));
    app()->instance(SpeechSynthesizer::class, new NullSpeechSynthesizer);
    app(WordAudioScheduler::class)->wordCreated(exaWord('plum', 'A plum.'));

    Queue::assertNothingPushed();
});

// --- the edit of the example in the admin panel -----------------------------------------------------

it('an edit of the example on the word screen drops its path and queues it, not the word', function (): void {
    $this->actingAs(exAdmin());
    $word = exaWord('apple', 'I ate an apple.', attrs: ['example_audio_path' => exPath('I ate an apple.')]);

    livewire(EditWord::class, ['record' => $word->getRouteKey()])
        ->fillForm(['example' => 'She ate an apple.'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($word->fresh()?->example_audio_path)->toBeNull()
        ->and(exQueued())->toBe([$word->id])
        ->and(exWordsQueued())->toBe([]);
});

it('an edit of the example on the lesson screen drops its path and queues it', function (): void {
    $this->actingAs(exAdmin());
    $word = exaWord('apple', 'I ate an apple.', attrs: ['example_audio_path' => exPath('I ate an apple.')]);

    livewire(WordsRelationManager::class, ['ownerRecord' => Lesson::query()->findOrFail($word->lesson_id), 'pageClass' => EditLesson::class])
        ->callTableAction('edit', $word, data: ['word' => 'apple', 'translation' => $word->translation, 'example' => 'She ate an apple.'])
        ->assertHasNoTableActionErrors();

    expect($word->fresh()?->example_audio_path)->toBeNull()
        ->and(exQueued())->toBe([$word->id]);
});

it('an edit of the blanks of the example keeps its path and queues nothing; a cleared example drops it', function (): void {
    $path = exPath('I ate an apple.');
    $word = exaWord('apple', 'I ate an apple.', attrs: ['example_audio_path' => $path]);

    $word->update(['example' => 'I ate  an apple. ']);
    app(WordAudioService::class)->exampleChanged($word);
    expect($word->fresh()?->example_audio_path)->toBe($path);
    Queue::assertNothingPushed();

    $word->update(['example' => null]);
    app(WordAudioService::class)->exampleChanged($word);
    expect($word->fresh()?->example_audio_path)->toBeNull();
    Queue::assertNothingPushed();
});

it('drops the path of the example also when the model was read without the column', function (): void {
    $word = exaWord('apple', 'I ate an apple.', attrs: ['example_audio_path' => exPath('I ate an apple.')]);
    $listed = Word::query()->select(['id', 'word', 'example'])->findOrFail($word->id);
    $listed->example = 'She ate an apple.';

    app(WordAudioService::class)->exampleChanged($listed);

    expect($word->fresh()?->example_audio_path)->toBeNull()
        ->and(exQueued())->toBe([$word->id]);
});

it('the additional examples (word_examples) are not voiced: adding one queues nothing', function (): void {
    $this->actingAs(exAdmin());
    $word = exaWord('apple', 'I ate an apple.');

    livewire(WordExamplesRelationManager::class, ['ownerRecord' => $word, 'pageClass' => EditWord::class])
        ->callTableAction('create', data: ['text' => 'Apples are red.', 'translations' => ['ru' => 'Яблоки красные.']])
        ->assertHasNoTableActionErrors();

    Queue::assertNothingPushed();
});

// --- docs/26 §9.11 item 6: TTS switched off ---------------------------------------------------------

it('with TTS switched off an edit of the text or of the example drops the path and queues nothing', function (): void {
    app()->instance(SpeechSynthesizer::class, new NullSpeechSynthesizer);
    $word = exaWord('apple', 'I ate an apple.', attrs: ['audio_path' => exPath('apple'), 'example_audio_path' => exPath('I ate an apple.')]);

    $word->update(['word' => 'pear', 'example' => 'I ate a pear.']);
    app(WordAudioService::class)->textChanged($word);
    app(WordAudioService::class)->exampleChanged($word);

    expect($word->fresh()?->audio_path)->toBeNull()
        ->and($word->fresh()?->example_audio_path)->toBeNull();
    Queue::assertNothingPushed();
});

it('with TTS_DRIVER=null on the real binding the edit of the word screen leaves no failed job', function (): void {
    exSyncQueue();
    app()->forgetInstance(SpeechSynthesizer::class);
    config(['twa.tts.driver' => 'null', 'twa.tts.service_url' => '']);
    $this->actingAs(exAdmin());
    $word = exaWord('apple', 'I ate an apple.', attrs: ['audio_path' => exPath('apple')]);

    livewire(EditWord::class, ['record' => $word->getRouteKey()])
        ->fillForm(['word' => 'pear', 'example' => 'I ate a pear.'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and($word->fresh()?->audio_path)->toBeNull();
});

// --- a lesson import --------------------------------------------------------------------------------

it('an import queues the examples of new words and of words whose example changed, after the commit', function (): void {
    $course = Course::factory()->create(['slug' => 'english-a1']);
    exImport($course, [
        ['word' => 'apple', 'translation' => 'яблоко', 'example' => 'I ate an apple.'],
        ['word' => 'pear', 'translation' => 'груша', 'example' => 'A ripe pear.'],
        ['word' => 'plum', 'translation' => 'слива'],
    ]);
    $words = Word::query()->get()->keyBy('word');
    expect(exQueued())->toEqualCanonicalizing([$words['apple']->id, $words['pear']->id]);
    Queue::assertPushed(PregenerateExampleAudioJob::class, fn (PregenerateExampleAudioJob $job): bool => $job->afterCommit === true);

    $words['apple']->forceFill(['example_audio_path' => exPath('I ate an apple.'), 'audio_path' => app(WordAudioService::class)->pathFor('apple')])->save();
    $words['pear']->forceFill(['example_audio_path' => exPath('A ripe pear.'), 'audio_path' => app(WordAudioService::class)->pathFor('pear')])->save();
    Queue::fake();

    exImport($course, [
        ['word' => 'apple', 'translation' => 'яблочко', 'example' => 'I ate an apple.'],  // changed, its example is current
        ['word' => 'pear', 'translation' => 'груша', 'example' => 'A sweet pear.'],      // its example changed
        ['word' => 'plum', 'translation' => 'слива'],                                   // unchanged
        ['word' => 'fig', 'translation' => 'инжир', 'example' => 'A fig tree.'],         // new
    ]);

    expect(exQueued())->toEqualCanonicalizing([$words['pear']->id, Word::query()->where('word', 'fig')->sole()->id])
        ->and(exWordsQueued())->toEqualCanonicalizing([Word::query()->where('word', 'fig')->sole()->id]);
});

it('a rolled-back import voices no example; committed, the example is voiced', function (): void {
    exSyncQueue();
    $synth = exSynth();
    $course = Course::factory()->create(['slug' => 'english-a1']);

    try {
        DB::transaction(function () use ($course): void {
            exImport($course, [['word' => 'apple', 'translation' => 'яблоко', 'example' => 'I ate an apple.']]);

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }
    expect($synth->texts)->toBe([]);

    exImport($course, [['word' => 'apple', 'translation' => 'яблоко', 'example' => 'I ate an apple.']]);
    expect($synth->texts)->toEqualCanonicalizing(['apple', 'I ate an apple.'])
        ->and(Word::query()->sole()->example_audio_path)->toBe(exPath('I ate an apple.'));
});

it('an import into a learner\'s deck queues no example', function (): void {
    exImport(Course::factory()->create(['slug' => 'my-deck', 'owner_type' => 'learner']), [['word' => 'apple', 'translation' => 'яблоко', 'example' => 'I ate an apple.']]);

    Queue::assertNothingPushed();
});

// --- the nightly reconciliation ---------------------------------------------------------------------

it('the reconciliation queues the words first, then the examples, within one limit', function (): void {
    $lesson = exLesson();
    $apple = exaWord('apple', 'I ate an apple.', $lesson);
    $pear = exaWord('pear', 'A ripe pear.', $lesson, ['audio_path' => app(WordAudioService::class)->pathFor('pear')]);
    $plum = exaWord('plum', 'A plum.', $lesson, ['audio_path' => app(WordAudioService::class)->pathFor('plum'), 'example_audio_path' => exPath('A plum.')]);
    $fig = exaWord('fig', 'A fig.', $lesson, ['audio_path' => app(WordAudioService::class)->pathFor('fig'), 'example_audio_path' => 'audio/stale.mp3']);
    exaWord('kiwi', 'A kiwi.', $lesson, ['is_active' => false]);
    exaWord('lime', 'A lime.', exLesson(Course::factory()->create(['owner_type' => 'learner'])));
    exaWord('date', null, $lesson, ['audio_path' => app(WordAudioService::class)->pathFor('date')]);

    $this->artisan('audio:reconcile', ['--limit' => 3])
        ->expectsOutput('Queued: 3')
        ->expectsOutput('Words: 1, examples: 2')
        ->assertSuccessful();

    expect(exWordsQueued())->toBe([$apple->id])
        ->and(exQueued())->toBe([$apple->id, $pear->id])
        ->and(exQueued())->not->toContain($plum->id);
    Queue::assertPushed(PregenerateExampleAudioJob::class, fn (PregenerateExampleAudioJob $job): bool => $job->queue === 'tts' && ! $job->force && $job->afterCommit !== true);

    // A limit the words use up leaves nothing for the examples.
    Queue::fake();
    expect(app(WordAudioScheduler::class)->reconcile(1))->toBe(['queued' => 1, 'words' => 1, 'examples' => 0, 'reason' => null]);

    // Without a limit: every example that is not current, the stale one too.
    Queue::fake();
    expect(app(WordAudioScheduler::class)->reconcile())->toBe(['queued' => 4, 'words' => 1, 'examples' => 3, 'reason' => null])
        ->and(exQueued())->toBe([$apple->id, $pear->id, $fig->id]);
});

it('the reconciliation reads the examples in chunks past the limit', function (): void {
    $lesson = exLesson();
    $audio = app(WordAudioService::class);
    foreach (range(1, WordAudioScheduler::CHUNK + 3) as $i) {
        // Every word is voiced, no example is.
        Word::query()->insert(['lesson_id' => $lesson->id, 'word' => "w{$i}", 'translation' => 't', 'example' => "Sentence {$i}.", 'audio_path' => $audio->pathFor("w{$i}"), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    expect(app(WordAudioScheduler::class)->reconcile(WordAudioScheduler::CHUNK + 1))->toMatchArray(['words' => 0, 'examples' => WordAudioScheduler::CHUNK + 1]);
});

it('the reconciliation queues no example while TTS is not configured or the month is spent', function (): void {
    exaWord('apple', 'I ate an apple.', attrs: ['audio_path' => app(WordAudioService::class)->pathFor('apple')]);

    config(['twa.tts.monthly_char_limit' => 0]);
    expect(app(WordAudioScheduler::class)->reconcile()['reason'])->toBe(WordAudioScheduler::SKIP_BUDGET_EXHAUSTED);

    config(['twa.tts.monthly_char_limit' => 900_000]);
    app()->instance(SpeechSynthesizer::class, new NullSpeechSynthesizer);
    expect(app(WordAudioScheduler::class)->reconcile()['reason'])->toBe(WordAudioScheduler::SKIP_NOT_CONFIGURED);

    Queue::assertNothingPushed();
});

// --- audio:pregenerate ------------------------------------------------------------------------------

it('audio:pregenerate queues the words and the examples by default, one of the two with --only', function (): void {
    $course = Course::factory()->create(['slug' => 'a1']);
    $lesson = exLesson($course);
    $apple = exaWord('apple', 'I ate an apple.', $lesson);
    $pear = exaWord('pear', null, $lesson);
    Storage::disk('public')->put(exPath('A plum.'), 'mp3');
    $plum = exaWord('plum', 'A plum.', $lesson, ['example_audio_path' => exPath('A plum.')]);

    $this->artisan('audio:pregenerate', ['course' => 'a1'])
        ->expectsOutput('Queued: 3, already up to date: 0')
        ->expectsOutput('Examples queued: 1, already up to date: 1')
        ->assertSuccessful();
    expect(exWordsQueued())->toBe([$apple->id, $pear->id, $plum->id])
        ->and(exQueued())->toBe([$apple->id]);

    Queue::fake();
    $this->artisan('audio:pregenerate', ['course' => 'a1', '--only' => 'examples'])
        ->expectsOutput('Examples queued: 1, already up to date: 1')
        ->doesntExpectOutputToContain('Queued: ')
        ->assertSuccessful();
    expect(exWordsQueued())->toBe([])->and(exQueued())->toBe([$apple->id]);

    Queue::fake();
    $this->artisan('audio:pregenerate', ['course' => 'a1', '--only' => 'words'])
        ->expectsOutput('Queued: 3, already up to date: 0')
        ->doesntExpectOutputToContain('Examples queued')
        ->assertSuccessful();
    expect(exQueued())->toBe([]);
});

it('audio:pregenerate --all prints a line of examples per course and skips the decks of learners', function (): void {
    exaWord('apple', 'I ate an apple.', exLesson(Course::factory()->create(['slug' => 'a1'])));
    exaWord('pear', 'A pear.', exLesson(Course::factory()->create(['owner_type' => 'learner'])));

    $this->artisan('audio:pregenerate', ['--all' => true])
        ->expectsOutput('a1: queued 1, already up to date 0')
        ->expectsOutput('a1 examples: queued 1, already up to date 0')
        ->expectsOutput('Examples queued: 1, already up to date: 0')
        ->assertSuccessful();
});

it('audio:pregenerate --force re-synthesizes one sentence once, across words, examples and courses', function (): void {
    $a = exaWord('apple', 'Fruit is good.', exLesson(Course::factory()->create()));
    $b = exaWord('pear', 'Fruit is good.', exLesson(Course::factory()->create()));
    $c = exaWord('fruit', 'fruit');  // the example is sent like the word "fruit": the same file

    $this->artisan('audio:pregenerate', ['--all' => true, '--force' => true])->assertSuccessful();

    $examples = Queue::pushed(PregenerateExampleAudioJob::class)->keyBy(fn (PregenerateExampleAudioJob $job): int => $job->wordId);
    $words = Queue::pushed(PregenerateWordAudioJob::class)->keyBy(fn (PregenerateWordAudioJob $job): int => $job->wordId);
    expect($examples[$a->id]->force)->toBeTrue()
        ->and($examples[$b->id]->force)->toBeFalse()
        ->and($words[$c->id]->force)->toBeTrue()
        ->and($examples[$c->id]->force)->toBeFalse();
});

it('audio:pregenerate refuses an unknown --only', function (): void {
    $this->artisan('audio:pregenerate', ['--all' => true, '--only' => 'phrases'])
        ->expectsOutput('--only must be words or examples.')
        ->assertExitCode(2);
    Queue::assertNothingPushed();
});

// --- audio:prune, audio:migrate-disk, audio:coverage ------------------------------------------------

it('audio:prune keeps the files of the examples: recorded, of the current sentence, of a switched-off word', function (): void {
    $old = time() - 90 * 86400;
    $files = [
        exPath('I ate an apple.') => true,   // recorded
        exPath('A ripe pear.') => true,      // the current sentence, the row not caught up yet
        exPath('A plum.') => true,           // a switched-off word
        exPath('An old sentence.') => false, // nobody's
    ];
    foreach (array_keys($files) as $path) {
        Storage::disk('public')->put($path, 'mp3');
        touch(Storage::disk('public')->path($path), $old);
    }
    exaWord('apple', 'I ate an apple.', attrs: ['example_audio_path' => exPath('I ate an apple.')]);
    exaWord('pear', 'A ripe pear.');
    exaWord('plum', 'A plum.', attrs: ['example_audio_path' => exPath('A plum.'), 'is_active' => false]);

    $report = app(WordAudioPruner::class)->prune(delete: true);

    expect($report->linked)->toBe(3)
        ->and($report->deleted)->toBe(1);
    foreach ($files as $path => $kept) {
        expect(Storage::disk('public')->exists($path))->toBe($kept);
    }
});

it('audio:prune takes a database whose only recorded paths are of examples for a real one', function (): void {
    Storage::disk('public')->put(exPath('I ate an apple.'), 'mp3');
    exaWord('apple', 'I ate an apple.', attrs: ['example_audio_path' => exPath('I ate an apple.')]);

    expect(app(WordAudioPruner::class)->prune()->linked)->toBe(1);
});

it('audio:migrate-disk copies the files of the examples too, a shared one once', function (): void {
    Storage::fake('audio');
    Storage::disk('public')->put(exPath('apple'), 'mp3-apple');
    Storage::disk('public')->put(exPath('I ate an apple.'), 'mp3-example');
    exaWord('apple', 'I ate an apple.', attrs: ['audio_path' => exPath('apple'), 'example_audio_path' => exPath('I ate an apple.')]);
    exaWord('fruit', 'apple', attrs: ['example_audio_path' => exPath('apple')]); // the example is the file of the word "apple"

    $report = app(WordAudioDiskMigrator::class)->migrate('public', 'audio');

    expect($report->files)->toBe(2)
        ->and($report->copied)->toBe(2)
        ->and(Storage::disk('audio')->get(exPath('I ate an apple.')))->toBe('mp3-example')
        ->and(Storage::disk('audio')->get(exPath('apple')))->toBe('mp3-apple');
});

it('audio:coverage reports the examples on a line of their own, outside the --min gate', function (): void {
    $course = Course::factory()->create(['slug' => 'a1']);
    $lesson = exLesson($course);
    $audio = app(WordAudioService::class);
    foreach (['apple', 'pear', 'plum', 'fig'] as $text) {
        Storage::disk('public')->put((string) $audio->pathFor($text), 'mp3');
    }
    Storage::disk('public')->put(exPath('I ate an apple.'), 'mp3');
    exaWord('apple', 'I ate an apple.', $lesson, ['audio_path' => $audio->pathFor('apple'), 'example_audio_path' => exPath('I ate an apple.')]);
    exaWord('pear', 'A ripe pear.', $lesson, ['audio_path' => $audio->pathFor('pear'), 'example_audio_path' => exPath('A ripe pear.')]); // file gone
    exaWord('plum', 'A plum.', $lesson, ['audio_path' => $audio->pathFor('plum')]);
    exaWord('fig', '  ', $lesson, ['audio_path' => $audio->pathFor('fig')]); // nothing to voice
    exaWord('kiwi', 'A kiwi.', $lesson, ['is_active' => false, 'example_audio_path' => exPath('A kiwi.')]);

    $report = app(WordAudioCoverageService::class)->report($course);
    expect($report->examples)->toBe(3)
        ->and($report->examplesWithAudio)->toBe(2)
        ->and($report->examplesVerified)->toBeNull()
        ->and($report->percent())->toBe(100.0);

    $verified = app(WordAudioCoverageService::class)->report($course, verify: true);
    expect($verified->examplesVerified)->toBe(1)
        ->and($verified->problemCount)->toBe(0);

    $this->artisan('audio:coverage', ['course' => 'a1', '--min' => 100])
        ->expectsOutputToContain('Examples: 2 of 3 voiced (66.6%), by the DB.')
        ->assertExitCode(0);
    $this->artisan('audio:coverage', ['course' => 'a1', '--verify' => true, '--min' => 100])
        ->expectsOutputToContain('Examples: 1 of 3 voiced (33.3%), files checked.')
        ->assertExitCode(0);
});

it('audio:coverage counts only the examples that are voiced: no blanks alone, none over one request of Google', function (): void {
    $course = Course::factory()->create(['slug' => 'a1']);
    $lesson = exLesson($course);
    $long = str_repeat('я', 2_501); // 5 002 bytes: never queued
    $edge = str_repeat('a', 5_000); // exactly the limit: voiced
    exaWord('apple', "\t I ate an apple. \n", $lesson, ['example_audio_path' => exPath('I ate an apple.')]);
    exaWord('pear', $edge, $lesson);
    exaWord('plum', "\u{A0}\t\n\u{3000} ", $lesson);          // blanks alone, the Unicode ones too
    exaWord('fig', '  '.$long.'  ', $lesson, ['example_audio_path' => 'audio/stale.mp3']);
    exaWord('kiwi', null, $lesson);

    $audio = app(WordAudioService::class);
    expect($audio->exampleText($long))->toBeNull()
        ->and($audio->exampleText("\u{A0}\t\n\u{3000} "))->toBeNull()
        ->and($audio->exampleText($edge))->toBe($edge);

    $report = app(WordAudioCoverageService::class)->report($course);
    expect($report->examples)->toBe(2)
        ->and($report->examplesWithAudio)->toBe(1);
});
