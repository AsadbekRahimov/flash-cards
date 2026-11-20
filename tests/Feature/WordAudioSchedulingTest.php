<?php

declare(strict_types=1);

use App\Domain\Content\Contracts\SpeechSynthesizer;
use App\Domain\Content\Exceptions\SpeechSynthesisException;
use App\Domain\Content\Services\Tts\GoogleServiceAccount;
use App\Domain\Content\Services\Tts\GoogleSpeechSynthesizer;
use App\Domain\Content\Services\Tts\NullSpeechSynthesizer;
use App\Domain\Content\Services\Tts\TtsCharacterBudget;
use App\Domain\Content\Services\VocabularyImporter;
use App\Domain\Content\Services\WordAudioScheduler;
use App\Domain\Content\Services\WordAudioService;
use App\Filament\Resources\LessonResource\Pages\EditLesson;
use App\Filament\Resources\LessonResource\RelationManagers\WordsRelationManager;
use App\Filament\Resources\WordResource\Pages\EditWord;
use App\Jobs\PregenerateWordAudioJob;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\User;
use App\Models\Word;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

use function Pest\Livewire\livewire;

/*
 * docs/26 §5.6 «Когда ставится задание», one test (or more) per row: a new word in the admin panel, a lesson import, a word switched
 * off, the edit of the text (unchanged), the nightly reconciliation (`audio:reconcile`) and `audio:pregenerate --all`. Never for the
 * deck of a learner (§9.4), never while the synthesizer is `null` (§7 step 2).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['twa.tts.voice' => 'en-US-Neural2-F', 'twa.tts.audio_version' => 1, 'twa.tts.audio_disk' => 'public']);
    Storage::fake('public');
    Queue::fake();
    schedSynth();
    $this->schedKeyFile = null;
});

afterEach(function (): void {
    if (is_string($this->schedKeyFile)) {
        @unlink($this->schedKeyFile);
    }
});

/** A synthesizer that is configured (not `null`) and counts its calls (only the tests on the real sync queue reach it). */
function schedSynth(): object
{
    $synth = new class implements SpeechSynthesizer
    {
        public int $calls = 0;

        public function synthesize(string $text, string $voice): array
        {
            $this->calls++;

            return ['body' => 'mp3-bytes', 'content_type' => 'audio/mpeg'];
        }
    };
    app()->instance(SpeechSynthesizer::class, $synth);

    return $synth;
}

/** Back to the real `sync` queue of the tests: a fake ignores `afterCommit`, the dispatcher does not. */
function schedSyncQueue(): void
{
    app()->forgetInstance('queue');
    Queue::clearResolvedInstance('queue');
}

/** The real binding with `TTS_DRIVER=google`, a valid key file of its own (removed after the test) and a Google voice. */
function schedGoogle(): void
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $path = (string) tempnam(sys_get_temp_dir(), 'sched-google-');
    file_put_contents($path, json_encode([
        'type' => 'service_account',
        'private_key_id' => 'abc123',
        'private_key' => $pem,
        'client_email' => 'tts@lexiflow-test.iam.gserviceaccount.com',
    ], JSON_THROW_ON_ERROR));
    test()->schedKeyFile = $path;

    app()->forgetInstance(SpeechSynthesizer::class);
    app()->forgetInstance(GoogleServiceAccount::class);
    Http::preventStrayRequests();
    config(['twa.tts.driver' => 'google', 'twa.tts.google.credentials' => $path, 'twa.tts.voice' => 'en-US-Neural2-F']);
}

function schedLesson(?Course $course = null): Lesson
{
    $stage = Stage::factory()->create(['course_id' => ($course ?? Course::factory()->create())->id]);

    return Lesson::factory()->for($stage)->create();
}

function schedWord(string $text, ?Lesson $lesson = null, array $attrs = []): Word
{
    return Word::factory()->for($lesson ?? schedLesson())->create(['word' => $text] + $attrs);
}

function schedPath(string $text): string
{
    return (string) app(WordAudioService::class)->pathFor($text);
}

function schedAdmin(): User
{
    test()->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');

    return $admin;
}

/** @return list<int> the word ids of the pushed audio jobs, in order */
function schedQueued(): array
{
    return Queue::pushed(PregenerateWordAudioJob::class)->map(fn (PregenerateWordAudioJob $job): int => $job->wordId)->values()->all();
}

/** @param  list<array<string, mixed>>  $words */
function schedImport(Course $course, array $words): void
{
    $report = app(VocabularyImporter::class)->importAsSystem((string) json_encode([
        'course' => $course->slug,
        'stage' => ['number' => 1],
        'lesson' => ['number' => 1],
        'words' => $words,
    ]));

    expect($report->aborted)->toBeFalse();
}

// --- a new word in the admin panel ----------------------------------------------------------------

it('queues the audio of a word added on the lesson screen, after the save, on the tts queue', function (): void {
    $this->actingAs(schedAdmin());
    $lesson = schedLesson();

    livewire(WordsRelationManager::class, ['ownerRecord' => $lesson, 'pageClass' => EditLesson::class])
        ->callTableAction('create', data: ['word' => 'resilient', 'translation' => 'стойкий'])
        ->assertHasNoTableActionErrors();

    $word = Word::query()->where('word', 'resilient')->sole();
    Queue::assertPushed(PregenerateWordAudioJob::class, fn (PregenerateWordAudioJob $job): bool => $job->wordId === $word->id
        && $job->queue === 'tts'
        && $job->afterCommit === true
        && $job->force === false);
    expect(schedQueued())->toBe([$word->id]);
});

it('queues nothing for a new word of a learner\'s own deck', function (): void {
    $deck = Course::factory()->create(['owner_type' => 'learner']);
    $word = schedWord('resilient', schedLesson($deck));

    app(WordAudioScheduler::class)->wordCreated($word);

    Queue::assertNothingPushed();
});

it('queues nothing for a new word that is switched off, also when the model does not know the flag', function (): void {
    $word = schedWord('resilient', attrs: ['is_active' => false]);
    app(WordAudioScheduler::class)->wordCreated($word);

    // As the panel passes it: the form has no `is_active`, the row has it.
    $fromForm = Word::query()->findOrFail($word->id)->setRawAttributes(['id' => $word->id, 'word' => 'resilient']);
    app(WordAudioScheduler::class)->wordCreated($fromForm);

    Queue::assertNothingPushed();
});

it('queues nothing for a new word while TTS is not configured', function (): void {
    app()->instance(SpeechSynthesizer::class, new NullSpeechSynthesizer);

    app(WordAudioScheduler::class)->wordCreated(schedWord('resilient'));

    Queue::assertNothingPushed();
});

it('queues no audio for a new word whose save was rolled back', function (): void {
    schedSyncQueue();
    $synth = schedSynth();

    try {
        DB::transaction(function (): void {
            app(WordAudioScheduler::class)->wordCreated(schedWord('resilient'));

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect($synth->calls)->toBe(0);

    // Committed, the same call voices the word.
    DB::transaction(fn () => app(WordAudioScheduler::class)->wordCreated(schedWord('durable')));
    expect($synth->calls)->toBe(1)
        ->and(Word::query()->where('word', 'durable')->sole()->audio_path)->toBe(schedPath('durable'));
});

// --- the edit of the text (unchanged) and a word switched off --------------------------------------

it('still queues the audio when the text of a word is edited', function (): void {
    $this->actingAs(schedAdmin());
    $word = schedWord('resilient', attrs: ['audio_path' => schedPath('resilient')]);

    livewire(EditWord::class, ['record' => $word->getRouteKey()])
        ->fillForm(['word' => 'durable'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(schedQueued())->toBe([$word->id])
        ->and($word->fresh()?->audio_path)->toBeNull();
});

it('queues nothing when a word is switched off, and its file stays', function (): void {
    $this->actingAs(schedAdmin());
    $path = schedPath('resilient');
    Storage::disk('public')->put($path, 'mp3-bytes');
    $word = schedWord('resilient', attrs: ['audio_path' => $path]);

    livewire(EditWord::class, ['record' => $word->getRouteKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    Queue::assertNothingPushed();
    expect($word->fresh()?->audio_path)->toBe($path)
        ->and(Storage::disk('public')->exists($path))->toBeTrue();
});

// --- a lesson import -------------------------------------------------------------------------------

it('queues one job per new and changed word of an import, and none for a word that is current, unchanged or switched off', function (): void {
    $course = Course::factory()->create(['slug' => 'english-a1']);
    schedImport($course, [
        ['word' => 'apple', 'translation' => 'яблоко'],
        ['word' => 'pear', 'translation' => 'груша'],
        ['word' => 'plum', 'translation' => 'слива'],
        ['word' => 'fig', 'translation' => 'инжир'],
    ]);
    $words = Word::query()->get()->keyBy('word');
    expect(schedQueued())->toEqualCanonicalizing($words->pluck('id')->all()); // the first import: all new

    $words['apple']->update(['audio_path' => schedPath('apple')]);
    $words['fig']->update(['is_active' => false]);
    Queue::fake();

    schedImport($course, [
        ['word' => 'apple', 'translation' => 'яблочко'],      // changed, its audio is current
        ['word' => 'pear', 'translation' => 'спелая груша'],  // changed, no audio
        ['word' => 'plum', 'translation' => 'слива'],         // unchanged: the nightly run is its insurance
        ['word' => 'fig', 'translation' => 'смоква'],         // changed, switched off
        ['word' => 'banana', 'translation' => 'банан'],       // new
        ['word' => 'cherry', 'translation' => 'вишня'],       // new
    ]);

    $banana = Word::query()->where('word', 'banana')->sole();
    $cherry = Word::query()->where('word', 'cherry')->sole();

    expect(schedQueued())->toEqualCanonicalizing([$words['pear']->id, $banana->id, $cherry->id]);
    Queue::assertPushed(PregenerateWordAudioJob::class, fn (PregenerateWordAudioJob $job): bool => $job->afterCommit === true && $job->queue === 'tts');
});

it('queues nothing when the import is rolled back with the transaction around it', function (): void {
    schedSyncQueue();
    $synth = schedSynth();
    $course = Course::factory()->create(['slug' => 'english-a1']);

    try {
        DB::transaction(function () use ($course): void {
            schedImport($course, [['word' => 'banana', 'translation' => 'банан']]);

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect(Word::query()->where('word', 'banana')->exists())->toBeFalse()
        ->and($synth->calls)->toBe(0);

    // Committed: voiced after the commit of the import.
    schedImport($course, [['word' => 'banana', 'translation' => 'банан']]);
    expect($synth->calls)->toBe(1)
        ->and(Word::query()->where('word', 'banana')->sole()->audio_path)->toBe(schedPath('banana'));
});

it('queues nothing for an import into a learner\'s deck or while TTS is not configured', function (): void {
    schedImport(Course::factory()->create(['slug' => 'my-deck', 'owner_type' => 'learner']), [['word' => 'banana', 'translation' => 'банан']]);
    Queue::assertNothingPushed();

    app()->instance(SpeechSynthesizer::class, new NullSpeechSynthesizer);
    schedImport(Course::factory()->create(['slug' => 'english-a1']), [['word' => 'banana', 'translation' => 'банан']]);
    Queue::assertNothingPushed();
});

it('queues a word once even when it is passed twice', function (): void {
    $course = Course::factory()->create();
    $word = schedWord('banana', schedLesson($course));

    DB::transaction(fn () => app(WordAudioScheduler::class)->wordsImported($course, [$word, $word, Word::query()->findOrFail($word->id)]));

    expect(schedQueued())->toBe([$word->id]);
});

// --- the nightly reconciliation ---------------------------------------------------------------------

it('queues the active course words without a current path, and not the voiced, the switched off or the learners\' words', function (): void {
    $lesson = schedLesson();
    $missing = schedWord('apple', $lesson);
    $stale = schedWord('pear', $lesson, ['audio_path' => 'audio/old.mp3']);
    $otherVoice = schedWord('plum', $lesson, ['audio_path' => schedPath('plum')]);
    config(['twa.tts.audio_version' => 2]); // a new version: every path is stale now, plum too
    $voiced = schedWord('fig', $lesson, ['audio_path' => schedPath('fig')]);
    schedWord('kiwi', $lesson, ['is_active' => false]);
    $deck = Course::factory()->create(['owner_type' => 'learner']);
    schedWord('lime', schedLesson($deck));

    $this->artisan('audio:reconcile')->expectsOutput('Queued: 3')->assertSuccessful();

    expect(schedQueued())->toBe([$missing->id, $stale->id, $otherVoice->id])
        ->and(schedQueued())->not->toContain($voiced->id);
    Queue::assertPushed(PregenerateWordAudioJob::class, fn (PregenerateWordAudioJob $job): bool => $job->queue === 'tts' && $job->force === false);
});

it('queues at most --limit words per run, in chunks, the lowest ids first', function (): void {
    $lesson = schedLesson();
    $ids = [];
    foreach (range(1, 7) as $i) {
        $ids[] = schedWord("word{$i}", $lesson)->id;
    }

    $this->artisan('audio:reconcile', ['--limit' => 5])->expectsOutput('Queued: 5')->assertSuccessful();

    expect(schedQueued())->toBe(array_slice($ids, 0, 5))
        ->and(app(WordAudioScheduler::class)->reconcile(1000))->toBe(['queued' => 7, 'reason' => null]);
});

it('reads the words in chunks across the limit', function (): void {
    $lesson = schedLesson();
    foreach (range(1, WordAudioScheduler::CHUNK + 3) as $i) {
        Word::query()->insert(['lesson_id' => $lesson->id, 'word' => "w{$i}", 'translation' => 't', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    expect(app(WordAudioScheduler::class)->reconcile(WordAudioScheduler::CHUNK + 1))->toBe(['queued' => WordAudioScheduler::CHUNK + 1, 'reason' => null])
        ->and(app(WordAudioScheduler::class)->reconcile())->toMatchArray(['queued' => WordAudioScheduler::CHUNK + 3]);
});

it('is idempotent: once the words are voiced a second run queues nothing', function (): void {
    $lesson = schedLesson();
    schedWord('apple', $lesson);
    schedWord('pear', $lesson);

    $this->artisan('audio:reconcile')->expectsOutput('Queued: 2')->assertSuccessful();

    // The jobs ran: every word now points at its file.
    Word::query()->get()->each(fn (Word $word) => $word->update(['audio_path' => schedPath($word->word)]));
    Queue::fake();

    $this->artisan('audio:reconcile')->expectsOutput('Queued: 0')->assertSuccessful();
    Queue::assertNothingPushed();
});

it('queues nothing while the characters of the month are spent', function (): void {
    schedWord('apple');
    config(['twa.tts.monthly_char_limit' => 100]);
    DB::table(TtsCharacterBudget::TABLE)->insert(['month' => app(TtsCharacterBudget::class)->month(), 'characters' => 100, 'requests' => 9, 'created_at' => now(), 'updated_at' => now()]);

    $this->artisan('audio:reconcile')
        ->expectsOutputToContain('monthly TTS character limit is spent')
        ->assertSuccessful();
    Queue::assertNothingPushed();

    // One character short of the limit: it queues again.
    DB::table(TtsCharacterBudget::TABLE)->update(['characters' => 99]);
    $this->artisan('audio:reconcile')->expectsOutput('Queued: 1')->assertSuccessful();
});

it('queues nothing while the limit is 0', function (): void {
    schedWord('apple');
    config(['twa.tts.monthly_char_limit' => 0]);

    expect(app(WordAudioScheduler::class)->reconcile())->toBe(['queued' => 0, 'reason' => 'budget_exhausted']);
    Queue::assertNothingPushed();
});

it('queues nothing while TTS is not configured', function (): void {
    schedWord('apple');
    app()->instance(SpeechSynthesizer::class, new NullSpeechSynthesizer);

    $this->artisan('audio:reconcile')->expectsOutputToContain('TTS is not configured')->assertSuccessful();
    Queue::assertNothingPushed();
});

it('queues nothing while TTS_DRIVER is a value nobody knows, and says so', function (): void {
    schedWord('apple');
    app()->forgetInstance(SpeechSynthesizer::class);
    config(['twa.tts.driver' => 'gogle']);

    $this->artisan('audio:reconcile')->expectsOutputToContain('TTS_DRIVER is none of google, http or null')->assertSuccessful();
    Queue::assertNothingPushed();
});

it('with google queues nothing while Google refused the sign-in, the key is missing or invalid, or the voice is not Google\'s', function (Closure $break, string $reason, string $message): void {
    schedWord('apple');
    schedGoogle();
    $break();

    expect(app(WordAudioScheduler::class)->reconcile())->toBe(['queued' => 0, 'reason' => $reason]);
    $this->artisan('audio:reconcile')->expectsOutputToContain($message)->assertSuccessful();
    Queue::assertNothingPushed();
})->with([
    'halted (401)' => [fn () => Cache::put(GoogleSpeechSynthesizer::HALT_KEY, ['code' => 'tts_auth_failed'], 600), WordAudioScheduler::SKIP_HALTED, 'audio:sample --clear-halt'],
    'halted (403)' => [fn () => Cache::put(GoogleSpeechSynthesizer::HALT_KEY, ['code' => 'tts_forbidden'], 600), WordAudioScheduler::SKIP_HALTED, 'Google refused the sign-in'],
    'no key path' => [fn () => config(['twa.tts.google.credentials' => '']), WordAudioScheduler::SKIP_CREDENTIALS_MISSING, 'GOOGLE_TTS_CREDENTIALS is empty'],
    'no file' => [fn () => config(['twa.tts.google.credentials' => '/nonexistent/google-tts.json']), WordAudioScheduler::SKIP_CREDENTIALS_INVALID, 'tts_credentials_invalid'],
    'an empty file (/dev/null of compose)' => [fn () => config(['twa.tts.google.credentials' => '/dev/null']), WordAudioScheduler::SKIP_CREDENTIALS_INVALID, 'tts_credentials_invalid'],
    'not a service-account key' => [fn () => file_put_contents((string) config('twa.tts.google.credentials'), '{"type":"authorized_user","secret":"s3cr3t"}'), WordAudioScheduler::SKIP_CREDENTIALS_INVALID, 'tts_credentials_invalid'],
    'an edge-tts voice' => [fn () => config(['twa.tts.voice' => 'en-US-AriaNeural']), WordAudioScheduler::SKIP_VOICE_UNSUPPORTED, 'TTS_VOICE is not a voice of Google'],
]);

it('with google and a valid key queues the words, and again once the halt marker is gone', function (): void {
    $apple = schedWord('apple');
    schedGoogle();
    Cache::put(GoogleSpeechSynthesizer::HALT_KEY, ['code' => 'tts_auth_failed'], 600);
    expect(app(WordAudioScheduler::class)->reconcile()['reason'])->toBe(WordAudioScheduler::SKIP_HALTED);

    GoogleSpeechSynthesizer::clearHalt();

    $this->artisan('audio:reconcile')->expectsOutput('Queued: 1')->assertSuccessful();
    expect(schedQueued())->toBe([$apple->id]);
    Http::assertNothingSent();
});

it('a word waiting in the queue is not queued again by the next night, a forced job is a job of its own', function (): void {
    $apple = schedWord('apple');
    $pear = schedWord('pear');

    expect(app(WordAudioScheduler::class)->reconcile())->toMatchArray(['reason' => null]);
    app(WordAudioScheduler::class)->reconcile();
    PregenerateWordAudioJob::dispatch($apple->id, true);
    PregenerateWordAudioJob::dispatch($apple->id, true);

    expect(schedQueued())->toBe([$apple->id, $pear->id, $apple->id])
        ->and(Queue::pushed(PregenerateWordAudioJob::class)->map(fn (PregenerateWordAudioJob $job): string => $job->uniqueId())->all())
        ->toBe([(string) $apple->id, (string) $pear->id, $apple->id.':force']);
});

it('the job is unique only until it is picked up: an edit during a synthesis still voices the new text', function (): void {
    schedSyncQueue();
    $word = schedWord('apple');
    $calls = [];
    // The admin edits the text while the job of the old text is synthesizing: its own job must not be dropped by the lock.
    app()->instance(SpeechSynthesizer::class, new class($word->id, $calls) implements SpeechSynthesizer
    {
        /** @param  list<string>  $calls */
        public function __construct(private readonly int $wordId, private array &$calls) {}

        public function synthesize(string $text, string $voice): array
        {
            $this->calls[] = $text;
            if ($text === 'apple') {
                $word = Word::query()->findOrFail($this->wordId);
                $word->update(['word' => 'pear']);
                app(WordAudioService::class)->textChanged($word);
            }

            return ['body' => 'mp3-of-'.$text, 'content_type' => 'audio/mpeg'];
        }
    });

    PregenerateWordAudioJob::dispatch($word->id);

    expect($calls)->toBe(['apple', 'pear'])
        ->and($word->fresh()?->audio_path)->toBe(schedPath('pear'));

    // Done: the lock is free, the word can be queued again.
    PregenerateWordAudioJob::dispatch($word->id, true);
    expect($calls)->toBe(['apple', 'pear', 'pear']);
});

it('a failed job frees its word for the next night', function (): void {
    schedSyncQueue();
    $word = schedWord('apple');
    app()->instance(SpeechSynthesizer::class, new class implements SpeechSynthesizer
    {
        public int $calls = 0;

        public function synthesize(string $text, string $voice): array
        {
            $this->calls++;

            throw SpeechSynthesisException::rejected();
        }
    });

    PregenerateWordAudioJob::dispatch($word->id);
    PregenerateWordAudioJob::dispatch($word->id);

    expect(app(SpeechSynthesizer::class)->calls)->toBe(2);
});

it('refuses a --limit that is not a whole number of 1 or more', function (string $limit): void {
    $this->artisan('audio:reconcile', ['--limit' => $limit])->assertExitCode(2);
    Queue::assertNothingPushed();
})->with(['0', '-3', 'many', '2.5']);

it('runs the reconciliation once a night, away from the backups and the purge, without overlapping', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'audio:reconcile'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 2 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

// --- audio:pregenerate --all -----------------------------------------------------------------------

it('audio:pregenerate --all queues the words of every course but the learners\' decks', function (): void {
    $a1 = schedWord('apple', schedLesson(Course::factory()->create(['slug' => 'a1'])));
    $b1 = schedWord('pear', schedLesson(Course::factory()->published()->create(['slug' => 'b1'])));
    schedWord('plum', schedLesson(Course::factory()->create(['owner_type' => 'learner'])));
    schedWord('kiwi', schedLesson(Course::factory()->create(['slug' => 'c1'])), ['is_active' => false]);

    $this->artisan('audio:pregenerate', ['--all' => true])
        ->expectsOutput('a1: queued 1, already up to date 0')
        ->expectsOutput('Queued: 2, already up to date: 0')
        ->assertSuccessful();

    expect(schedQueued())->toBe([$a1->id, $b1->id]);
});

it('audio:pregenerate --all --force re-synthesizes one text once across the courses', function (): void {
    $first = schedWord('apple', schedLesson(Course::factory()->create()));
    $second = schedWord('Apple', schedLesson(Course::factory()->create()));

    $this->artisan('audio:pregenerate', ['--all' => true, '--force' => true])->assertSuccessful();

    $jobs = Queue::pushed(PregenerateWordAudioJob::class)->keyBy(fn (PregenerateWordAudioJob $job): int => $job->wordId);
    expect($jobs)->toHaveCount(2)
        ->and($jobs[$first->id]->force)->toBeTrue()
        ->and($jobs[$second->id]->force)->toBeFalse();
});

it('audio:pregenerate --all skips the words whose file is current', function (): void {
    $path = schedPath('apple');
    Storage::disk('public')->put($path, 'mp3-bytes');
    schedWord('apple', attrs: ['audio_path' => $path]);
    $pear = schedWord('pear');

    $this->artisan('audio:pregenerate', ['--all' => true])->expectsOutput('Queued: 1, already up to date: 1')->assertSuccessful();

    expect(schedQueued())->toBe([$pear->id]);
});

it('audio:pregenerate wants either a course or --all', function (array $args): void {
    $this->artisan('audio:pregenerate', $args)->expectsOutput('Give either a course slug or --all.')->assertExitCode(2);
    Queue::assertNothingPushed();
})->with([
    'neither' => [[]],
    'both' => [['course' => 'a1', '--all' => true]],
]);
