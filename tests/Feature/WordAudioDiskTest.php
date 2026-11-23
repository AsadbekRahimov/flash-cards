<?php

declare(strict_types=1);

use App\Domain\Content\Contracts\SpeechSynthesizer;
use App\Domain\Content\Services\WordAudioService;
use App\Domain\Practice\DTO\AudioClip;
use App\Domain\Practice\DTO\LocaleSet;
use App\Domain\Practice\DTO\MaterialScope;
use App\Domain\Practice\DTO\SnapshotElement;
use App\Domain\Practice\Services\Modes\DictationMode;
use App\Domain\Practice\Services\PracticeAudioService;
use App\Domain\Twa\Services\JwtService;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\PracticeSession;
use App\Models\Stage;
use App\Models\TelegramGroup;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\Word;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToCheckFileExistence;
use Tests\Support\Practice\DictationKit;
use Tests\Support\Practice\PracticeKit;

/*
 * The word audio on Cloudflare R2 (docs/26 §5.4, §9.5, stage 4 and 6): the disk `audio`, one PUT with the headers of the object,
 * the public address of the bucket, no look at the bucket on the way of a learner (a card, a dictation, the audio endpoint of the
 * practice answers the public address in JSON, docs/26 §9.9), `audio:coverage --verify` and `audio:migrate-disk`. `Storage::fake('audio')` keeps the config of the disk
 * (`driver: s3`), so the code takes the R2 branch while the files land in a local folder.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('twa.tts.voice', 'en-US-AriaNeural');
    Config::set('twa.tts.audio_version', 1);
    Config::set('twa.tts.audio_disk', 'audio');
    Storage::fake('audio');
});

function r2PathOf(string $text): string
{
    return 'audio/'.sha1(mb_strtolower($text).'|en-US-AriaNeural|1').'.mp3';
}

function r2Word(string $text, array $attrs = [], ?Stage $stage = null): Word
{
    $lesson = Lesson::factory()->for($stage ?? Stage::factory()->create())->create();

    return Word::factory()->for($lesson)->create(['word' => $text] + $attrs);
}

function r2Synthesizer(): void
{
    $tts = Mockery::mock(SpeechSynthesizer::class);
    $tts->shouldReceive('synthesize')->andReturn(['body' => 'mp3-bytes', 'content_type' => 'audio/mpeg']);
    app()->instance(SpeechSynthesizer::class, $tts);
}

/** The `audio` disk behind a spy whose `put` is recorded (and still written to the fake). */
function r2SpyPut(string $disk, array &$puts, bool $result = true): void
{
    $fake = Storage::disk($disk);
    $spy = Mockery::mock($fake)->makePartial();
    $spy->shouldReceive('put')->andReturnUsing(function (string $path, $contents, $options = []) use ($fake, &$puts, $result) {
        $puts[] = ['path' => $path, 'options' => $options];

        return $result && $fake->put($path, $contents, $options);
    });
    Storage::set($disk, $spy);
}

/** A disk that answers nothing but `url()`: any other call fails the test (there must be no look at the bucket). */
function r2UrlOnlyDisk(string $base = 'https://audio.example.uz'): void
{
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('url')->andReturnUsing(fn (string $path): string => $base.'/'.$path);
    Storage::set('audio', $disk);
}

/** @return array<string, string> */
function r2Headers(Learner $learner): array
{
    return ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
}

// ------------------------------------------------------------------ the config

it('describes the R2 disk with keys of its own, the public URL, a private ACL and reported errors; public stays the default', function (): void {
    $disk = config('filesystems.disks.audio');

    expect($disk['driver'])->toBe('s3')
        ->and($disk['visibility'])->toBe('private')
        ->and($disk['throw'])->toBeFalse()
        ->and($disk['report'])->toBeTrue()
        ->and($disk['region'])->toBe('auto')
        ->and(array_keys($disk))->toContain('url', 'endpoint', 'bucket', 'key', 'secret', 'use_path_style_endpoint');

    $config = (string) file_get_contents(config_path('filesystems.php'));
    expect($config)->toContain("env('AUDIO_ACCESS_KEY_ID')", "env('AUDIO_SECRET_ACCESS_KEY')", "env('AUDIO_BUCKET')", "env('AUDIO_ENDPOINT')", "env('AUDIO_PUBLIC_URL')")
        ->and((string) file_get_contents(config_path('twa.php')))->toContain("'audio_disk' => env('TTS_AUDIO_DISK', 'public')");
});

// ------------------------------------------------------------------ the write

it('writes a word to R2 with one PUT on the final name, with Cache-Control and Content-Type of the object', function (): void {
    r2Synthesizer();
    $puts = [];
    r2SpyPut('audio', $puts);
    $word = r2Word('Resilient');

    expect(app(WordAudioService::class)->generate($word))->toBeTrue();

    expect($puts)->toBe([['path' => r2PathOf('resilient'), 'options' => [
        'CacheControl' => 'public, max-age=31536000, immutable',
        'ContentType' => 'audio/mpeg',
    ]]])
        ->and(Storage::disk('audio')->get(r2PathOf('resilient')))->toBe('mp3-bytes')
        ->and(Storage::disk('audio')->allFiles())->toBe([r2PathOf('resilient')])
        ->and($word->fresh()?->audio_path)->toBe(r2PathOf('resilient'))
        // nothing went to the disk of this server
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('says tts_audio_not_stored and leaves audio_path alone when R2 refuses the PUT', function (): void {
    r2Synthesizer();
    $puts = [];
    r2SpyPut('audio', $puts, result: false);
    $word = r2Word('Resilient');

    expect(fn () => app(WordAudioService::class)->generate($word))->toThrow(RuntimeException::class, 'tts_audio_not_stored');

    expect($puts)->toHaveCount(1)
        ->and($word->fresh()?->audio_path)->toBeNull();
});

it('keeps the temporary file and the move on a local disk, without the headers of R2', function (): void {
    Config::set('twa.tts.audio_disk', 'public');
    r2Synthesizer();
    $puts = [];
    r2SpyPut('public', $puts);

    app(WordAudioService::class)->generate(r2Word('Resilient'));

    expect($puts)->toHaveCount(1)
        ->and($puts[0]['path'])->toStartWith('audio/.tmp/')
        ->and($puts[0]['options'])->toBe([])
        ->and(Storage::disk('public')->get(r2PathOf('resilient')))->toBe('mp3-bytes');
});

// ------------------------------------------------------------------ the address and the way of a learner

it('gives the address of a word on the public domain of the bucket, without asking the bucket', function (string $publicUrl): void {
    // The real S3 disk (not the fake): url() only glues the public URL and the path, no request leaves.
    Storage::forgetDisk('audio');
    Config::set('filesystems.disks.audio', [...config('filesystems.disks.audio'), 'bucket' => 'lexiflow-audio', 'key' => 'k', 'secret' => 's', 'endpoint' => 'https://acc.r2.cloudflarestorage.com', 'url' => $publicUrl]);
    $word = r2Word('Resilient', ['audio_path' => r2PathOf('resilient')]);

    expect(app(WordAudioService::class)->urlFor($word))->toBe('https://audio.example.uz/'.r2PathOf('resilient'));
})->with(['without a slash' => 'https://audio.example.uz', 'with a slash' => 'https://audio.example.uz/']);

it('puts the R2 address on the training card and never looks at the bucket for it', function (): void {
    r2UrlOnlyDisk();
    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $learner = Learner::factory()->memberOf($group)->create();
    $lesson = Lesson::factory()->for(Stage::factory()->create())->create();
    Word::factory()->for($lesson)->create(['word' => 'resilient', 'audio_path' => r2PathOf('resilient')]);
    $session = TrainingSession::factory()->create([
        'telegram_group_id' => $group->id,
        'lesson_id' => $lesson->id,
        'started_by_user_id' => User::factory()->create()->id,
        'status' => 'open',
    ]);

    $this->withHeaders(r2Headers($learner))
        ->postJson("/api/twa/training/sessions/{$session->id}/start")
        ->assertOk()
        ->assertJsonPath('card.audio_url', 'https://audio.example.uz/'.r2PathOf('resilient'));
});

it('takes the words of a dictation by their path when they are on R2: no look at the bucket per candidate', function (): void {
    CarbonImmutable::setTestNow('2026-10-30 10:00:00');
    PracticeKit::openAccess();
    Storage::fake('practice_audio');
    [$learner, $course, $lesson] = DictationKit::world();
    // The paths are right, the files are nowhere this test could see: the bucket is not asked.
    foreach (['one', 'two', 'three', 'four', 'five'] as $name) {
        DictationKit::word($lesson, $learner, 'word'.$name, file: false);
    }
    r2UrlOnlyDisk();

    $elements = app(DictationMode::class)->build(new MaterialScope($course, $lesson, null), $learner, LocaleSet::of('ru'), str_repeat('a', 32), 5, false);

    expect($elements)->toHaveCount(5)
        ->and(array_values(array_unique(array_map(static fn (SnapshotElement $e): mixed => $e->get('audio_disk'), $elements))))->toBe(['audio']);

    CarbonImmutable::setTestNow();
});

it('still looks for the file of a word on the local disk, where a look costs nothing', function (): void {
    Config::set('twa.tts.audio_disk', 'public');
    $audio = app(PracticeAudioService::class);
    Storage::disk('public')->put('audio/here.mp3', 'mp3');

    expect($audio->isPlayable('public', 'audio/here.mp3'))->toBeTrue()
        ->and($audio->isPlayable('public', 'audio/gone.mp3'))->toBeFalse()
        ->and($audio->isPlayable('public', ''))->toBeFalse();
});

it('gives the public address of a word on R2 to be played by the client, and streams the rest (docs/26 §9.9)', function (): void {
    $audio = app(PracticeAudioService::class);
    Config::set('filesystems.disks.audio.url', 'https://audio.example.uz');
    r2UrlOnlyDisk();

    expect($audio->publicUrl(new AudioClip('audio', 'audio/x.mp3')))->toBe('https://audio.example.uz/audio/x.mp3')
        ->and($audio->publicUrl(new AudioClip('practice_audio', 'x.mp3')))->toBeNull();

    // TTS_AUDIO_DISK=public: the words are on this server and streamed with the token, as before.
    Config::set('twa.tts.audio_disk', 'public');
    expect($audio->publicUrl(new AudioClip('public', 'audio/x.mp3')))->toBeNull();
});

it('streams a word on R2 when the bucket has no public address: the address of its API is neither public nor in the CSP', function (?string $publicUrl): void {
    Config::set('filesystems.disks.audio.url', $publicUrl);
    Storage::disk('audio')->put('audio/x.mp3', 'mp3');

    expect(app(PracticeAudioService::class)->publicUrl(new AudioClip('audio', 'audio/x.mp3')))->toBeNull();
})->with(['unset' => null, 'empty' => '']);

it('never hands out an address that is not http(s)', function (string $built): void {
    Config::set('filesystems.disks.audio.url', 'https://audio.example.uz');
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('url')->andReturn($built);
    Storage::set('audio', $disk);

    expect(app(PracticeAudioService::class)->publicUrl(new AudioClip('audio', 'audio/x.mp3')))->toBeNull();
})->with(['javascript' => 'javascript:alert(1)//x.mp3', 'relative' => '/storage/audio/x.mp3', 'protocol-relative' => '//audio.example.uz/audio/x.mp3', 'no host' => 'https:///audio/x.mp3', 'blank' => '']);

it('answers the public address of a word of a dictation session in JSON, private and no-store, and streams its phrases', function (): void {
    CarbonImmutable::setTestNow('2026-10-30 10:00:00');
    PracticeKit::openAccess();
    Storage::fake('practice_audio');
    Config::set('filesystems.disks.audio.url', 'https://audio.example.uz');
    [$learner, $course, $lesson] = DictationKit::world();
    DictationKit::stock($lesson, $learner, 5, 0);
    foreach (['one', 'two', 'three'] as $name) {
        DictationKit::word($lesson, $learner, 'word'.$name, file: false);
    }
    r2UrlOnlyDisk();

    $session = $this->withHeaders(PracticeKit::headers($learner))
        ->postJson('/api/twa/practice/sessions', ['mode' => 'dictation', 'course' => $course->slug, 'lesson_id' => $lesson->id])
        ->assertCreated()->json();
    $elements = PracticeSession::query()->findOrFail($session['id'])->snapshot['elements'];
    $sources = array_column($elements, 'audio_disk');
    expect($sources)->toContain('audio')->toContain('practice_audio');

    foreach ($elements as $element) {
        // What the client sends: the token and the Accept of a clip (requestBlob in resources/twa/src/api/client.ts).
        $response = $this->withHeaders([...PracticeKit::headers($learner), 'Accept' => 'audio/mpeg, application/json;q=0.5'])
            ->get("/api/twa/practice/sessions/{$session['id']}/audio/{$element['id']}");
        if ($element['audio_disk'] === 'audio') {
            $response->assertOk()->assertExactJson(['url' => 'https://audio.example.uz/'.$element['audio_path']]);
            expect($response->headers->get('Content-Type'))->toContain('application/json')
                ->and($response->headers->get('Location'))->toBeNull()
                ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
        } else {
            $response->assertOk();
            expect($response->headers->get('Content-Type'))->toBe('audio/mpeg')
                ->and($response->streamedContent())->toBe('mp3:'.$element['display']);
        }
    }

    CarbonImmutable::setTestNow();
});

// ------------------------------------------------------------------ audio:coverage --verify

it('checks the files on R2 with audio:coverage --verify and names the disk', function (): void {
    $stage = Stage::factory()->create();
    $course = $stage->course;
    Storage::disk('audio')->put(r2PathOf('alpha'), 'mp3');
    Storage::disk('audio')->put(r2PathOf('beta'), '');
    r2Word('alpha', ['audio_path' => r2PathOf('alpha')], $stage);
    r2Word('beta', ['audio_path' => r2PathOf('beta')], $stage);
    r2Word('gamma', ['audio_path' => r2PathOf('gamma')], $stage);
    r2Word('delta', [], $stage);

    $this->artisan('audio:coverage', ['course' => $course->slug, '--verify' => true])
        ->expectsOutputToContain('1 of 4 active words voiced (25.0%), files checked on the disk "audio"')
        ->assertSuccessful();
});

it('fails audio:coverage --verify loudly when the bucket does not answer, instead of reporting a guess', function (): void {
    $stage = Stage::factory()->create();
    r2Word('alpha', ['audio_path' => r2PathOf('alpha')], $stage);
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('fileExists')->andThrow(UnableToCheckFileExistence::forLocation(r2PathOf('alpha')));
    Storage::set('audio', $disk);

    $this->artisan('audio:coverage', ['course' => $stage->course->slug, '--verify' => true])
        ->expectsOutputToContain('The audio disk "audio" cannot be read')
        ->assertFailed();
});

// ------------------------------------------------------------------ audio:migrate-disk

it('copies the files of the words from public to R2 with the headers of the jobs, once', function (): void {
    Storage::disk('public')->put(r2PathOf('alpha'), 'mp3-alpha');
    Storage::disk('public')->put(r2PathOf('beta'), 'mp3-beta');
    r2Word('alpha', ['audio_path' => r2PathOf('alpha')]);
    r2Word('Alpha', ['audio_path' => r2PathOf('alpha')]);                         // the same file: copied once
    r2Word('beta', ['audio_path' => r2PathOf('beta'), 'is_active' => false]);     // a switched-off word keeps its file
    r2Word('gamma', ['audio_path' => r2PathOf('gamma')]);                         // its file is gone
    r2Word('delta');                                                              // no audio
    $puts = [];
    r2SpyPut('audio', $puts);

    $this->artisan('audio:migrate-disk', ['--to' => 'audio'])
        ->expectsOutputToContain('public -> audio: 3 files; copied 2, already there 0, missing on public 1, failed 0.')
        ->assertSuccessful();

    expect(array_column($puts, 'path'))->toBe(collect([r2PathOf('alpha'), r2PathOf('beta')])->sort()->values()->all())
        ->and(array_values(array_unique(array_column($puts, 'options'), SORT_REGULAR)))->toBe([WordAudioService::PUT_OPTIONS])
        ->and(Storage::disk('audio')->get(r2PathOf('alpha')))->toBe('mp3-alpha')
        ->and(Storage::disk('audio')->get(r2PathOf('beta')))->toBe('mp3-beta')
        // nothing in the database changes, the source stays
        ->and(Word::query()->where('word', 'alpha')->value('audio_path'))->toBe(r2PathOf('alpha'))
        ->and(Storage::disk('public')->exists(r2PathOf('alpha')))->toBeTrue();

    // A repeat copies nothing.
    $puts = [];
    $this->artisan('audio:migrate-disk', ['--to' => 'audio'])
        ->expectsOutputToContain('copied 0, already there 2, missing on public 1, failed 0.')
        ->assertSuccessful();
    expect($puts)->toBe([]);
});

it('takes the target from TTS_AUDIO_DISK and only counts with --dry-run', function (): void {
    Storage::disk('public')->put(r2PathOf('alpha'), 'mp3-alpha');
    r2Word('alpha', ['audio_path' => r2PathOf('alpha')]);

    $this->artisan('audio:migrate-disk', ['--dry-run' => true])
        ->expectsOutputToContain('[dry run] public -> audio: 1 files; would copy 1, already there 0, missing on public 0, failed 0.')
        ->assertSuccessful();

    expect(Storage::disk('audio')->allFiles())->toBe([]);
});

it('does not overwrite an empty leftover on the source over a good target, and re-copies over an empty target', function (): void {
    Storage::disk('public')->put(r2PathOf('alpha'), 'mp3-alpha');
    Storage::disk('audio')->put(r2PathOf('alpha'), '');
    Storage::disk('public')->put(r2PathOf('beta'), '');
    r2Word('alpha', ['audio_path' => r2PathOf('alpha')]);
    r2Word('beta', ['audio_path' => r2PathOf('beta')]);

    $this->artisan('audio:migrate-disk', ['--to' => 'audio'])
        ->expectsOutputToContain('2 files; copied 1, already there 0, missing on public 1, failed 0.')
        ->assertSuccessful();

    expect(Storage::disk('audio')->get(r2PathOf('alpha')))->toBe('mp3-alpha')
        ->and(Storage::disk('audio')->exists(r2PathOf('beta')))->toBeFalse();
});

it('lists the files R2 refused and exits non-zero, so the command is run again', function (): void {
    Storage::disk('public')->put(r2PathOf('alpha'), 'mp3-alpha');
    r2Word('alpha', ['audio_path' => r2PathOf('alpha')]);
    $puts = [];
    r2SpyPut('audio', $puts, result: false);

    $this->artisan('audio:migrate-disk', ['--to' => 'audio'])
        ->expectsOutputToContain('copied 0, already there 0, missing on public 0, failed 1.')
        ->expectsOutputToContain('failed: '.r2PathOf('alpha'))
        ->assertFailed();
});

it('refuses an unknown disk and the same disk twice', function (array $options, string $message): void {
    $this->artisan('audio:migrate-disk', $options)
        ->expectsOutputToContain($message)
        ->assertExitCode(2);
})->with([
    'unknown target' => [['--to' => 'nowhere'], 'Unknown disk: nowhere'],
    'unknown source' => [['--from' => 'nowhere', '--to' => 'audio'], 'Unknown disk: nowhere'],
    'the same disk' => [['--from' => 'audio', '--to' => 'audio'], 'the same disk'],
]);
