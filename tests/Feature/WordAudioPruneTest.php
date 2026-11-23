<?php

declare(strict_types=1);

use App\Models\Lesson;
use App\Models\Stage;
use App\Models\Word;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

/*
 * `audio:prune` (docs/26 §5.8, stage 8): the old files of `audio/` no word points at (neither by `audio_path` nor by the path
 * of its current text) are listed, and deleted only with `--delete`; temporary leftovers older than a day go too. Other
 * directories and the disk of the dictations are never touched; an empty database next to a full directory is refused.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('twa.tts.voice', 'en-US-AriaNeural');
    Config::set('twa.tts.audio_version', 1);
    Config::set('twa.tts.audio_disk', 'public');
    Storage::fake('public');
});

function prunePathOf(string $text): string
{
    return 'audio/'.sha1(mb_strtolower($text).'|en-US-AriaNeural|1').'.mp3';
}

function pruneWord(string $text, array $attrs = []): Word
{
    $lesson = Lesson::factory()->for(Stage::factory()->create())->create();

    return Word::factory()->for($lesson)->create(['word' => $text] + $attrs);
}

/** Puts a file on the disk with the given age in days. */
function prunePut(string $path, int $daysOld, string $disk = 'public'): void
{
    Storage::disk($disk)->put($path, 'mp3');
    touch(Storage::disk($disk)->path($path), time() - $daysOld * 86400);
}

/**
 * Linked by audio_path (also a switched-off word), the current path of a word whose row has not caught up, an old orphan,
 * a fresh orphan, an old and a fresh temporary leftover, a file of another directory and a non-mp3 file next to them.
 */
function pruneScene(): void
{
    pruneWord('alpha', ['audio_path' => prunePathOf('alpha')]);
    pruneWord('beta', ['audio_path' => prunePathOf('beta'), 'is_active' => false]);
    pruneWord('gamma');                                     // the job wrote the file, the row is not updated yet

    prunePut(prunePathOf('alpha'), 90);
    prunePut(prunePathOf('beta'), 90);
    prunePut(prunePathOf('gamma'), 90);
    prunePut(prunePathOf('old orphan'), 31);
    prunePut(prunePathOf('fresh orphan'), 5);
    prunePut('audio/.tmp/x.mp3.abcdefabcdef', 2);
    prunePut('audio/.tmp/y.mp3.abcdefabcdef', 0);
    prunePut('avatars/old.mp3', 90);
    prunePut('audio/sub/old.mp3', 90);
    prunePut('audio/readme.txt', 90);
}

it('only lists the old orphans by default and deletes nothing', function (): void {
    pruneScene();
    $before = Storage::disk('public')->allFiles();

    $this->artisan('audio:prune')
        ->expectsOutputToContain('would delete: '.prunePathOf('old orphan'))
        ->expectsOutputToContain('would delete: audio/.tmp/x.mp3.abcdefabcdef')
        ->expectsOutputToContain('[dry run] public: 7 files; in use 3, younger than 30 days 2, would delete 2, failed 0.')
        ->expectsOutputToContain('run with --delete')
        ->assertSuccessful();

    expect(Storage::disk('public')->allFiles())->toBe($before);
});

it('deletes only the old orphans with --delete', function (): void {
    pruneScene();

    $this->artisan('audio:prune', ['--delete' => true])
        ->expectsOutputToContain('deleted: '.prunePathOf('old orphan'))
        ->expectsOutputToContain('public: 7 files; in use 3, younger than 30 days 2, deleted 2, failed 0.')
        ->assertSuccessful();

    $disk = Storage::disk('public');
    expect($disk->exists(prunePathOf('old orphan')))->toBeFalse()
        ->and($disk->exists('audio/.tmp/x.mp3.abcdefabcdef'))->toBeFalse()
        // linked by audio_path (also switched off), by the current text, fresh
        ->and($disk->exists(prunePathOf('alpha')))->toBeTrue()
        ->and($disk->exists(prunePathOf('beta')))->toBeTrue()
        ->and($disk->exists(prunePathOf('gamma')))->toBeTrue()
        ->and($disk->exists(prunePathOf('fresh orphan')))->toBeTrue()
        ->and($disk->exists('audio/.tmp/y.mp3.abcdefabcdef'))->toBeTrue()
        // other directories and other files are not touched
        ->and($disk->exists('avatars/old.mp3'))->toBeTrue()
        ->and($disk->exists('audio/sub/old.mp3'))->toBeTrue()
        ->and($disk->exists('audio/readme.txt'))->toBeTrue();
});

it('honours --days', function (): void {
    pruneScene();

    $this->artisan('audio:prune', ['--delete' => true, '--days' => 3])
        ->expectsOutputToContain('younger than 3 days 1, deleted 3, failed 0.')
        ->assertSuccessful();

    expect(Storage::disk('public')->exists(prunePathOf('fresh orphan')))->toBeFalse();
});

it('refuses an empty database next to a full directory unless forced', function (): void {
    pruneWord('alpha');                                     // a word, but no audio_path
    prunePut(prunePathOf('old orphan'), 90);

    $this->artisan('audio:prune', ['--delete' => true])
        ->expectsOutputToContain('No word has audio_path')
        ->assertFailed();
    expect(Storage::disk('public')->exists(prunePathOf('old orphan')))->toBeTrue();

    $this->artisan('audio:prune', ['--delete' => true, '--force' => true])
        ->expectsOutputToContain('deleted 1, failed 0.')
        ->assertSuccessful();
    expect(Storage::disk('public')->exists(prunePathOf('old orphan')))->toBeFalse();
});

it('prunes the disk given by --disk and leaves the default one alone', function (): void {
    Storage::fake('audio');
    pruneWord('alpha', ['audio_path' => prunePathOf('alpha')]);
    prunePut(prunePathOf('old orphan'), 90, 'audio');
    prunePut(prunePathOf('old orphan'), 90, 'public');

    $this->artisan('audio:prune', ['--delete' => true, '--disk' => 'audio'])
        ->expectsOutputToContain('audio: 1 files; in use 0, younger than 30 days 0, deleted 1, failed 0.')
        ->assertSuccessful();

    expect(Storage::disk('audio')->exists(prunePathOf('old orphan')))->toBeFalse()
        ->and(Storage::disk('public')->exists(prunePathOf('old orphan')))->toBeTrue();
});

it('refuses the disk of the dictations, an unknown disk and a bad --days', function (array $options, string $message): void {
    Storage::fake('practice_audio');
    prunePut('audio/old.mp3', 90, 'practice_audio');

    $this->artisan('audio:prune', $options + ['--delete' => true])
        ->expectsOutputToContain($message)
        ->assertExitCode(2);

    expect(Storage::disk('practice_audio')->exists('audio/old.mp3'))->toBeTrue();
})->with([
    'dictations' => [['--disk' => 'practice_audio'], 'has no word audio'],
    'unknown' => [['--disk' => 'nowhere'], 'Unknown disk: nowhere'],
    'zero days' => [['--days' => '0'], 'at least 1'],
    'not a number' => [['--days' => 'many'], 'whole number'],
]);

it('refuses the disk practice.audio.disk names, whatever its name', function (): void {
    config(['practice.audio.disk' => 'local']);
    Storage::fake('local');
    prunePut('audio/old.mp3', 90, 'local');

    $this->artisan('audio:prune', ['--disk' => 'local', '--delete' => true])
        ->expectsOutputToContain('The disk local has no word audio.')
        ->assertExitCode(2);

    expect(Storage::disk('local')->exists('audio/old.mp3'))->toBeTrue();
});

it('lists the files that could not be deleted and exits non-zero', function (): void {
    pruneWord('alpha', ['audio_path' => prunePathOf('alpha')]);
    $old = time() - 90 * 86400;
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('files')->with('audio')->andReturn([prunePathOf('alpha'), prunePathOf('old orphan')]);
    $disk->shouldReceive('files')->with('audio/.tmp')->andReturn([]);
    $disk->shouldReceive('lastModified')->with(prunePathOf('old orphan'))->andReturn($old);
    $disk->shouldReceive('delete')->with(prunePathOf('old orphan'))->once()->andReturn(false);
    $disk->shouldNotReceive('lastModified')->with(prunePathOf('alpha'));
    Storage::set('public', $disk);

    $this->artisan('audio:prune', ['--delete' => true])
        ->expectsOutputToContain('2 files; in use 1, younger than 30 days 0, deleted 0, failed 1.')
        ->expectsOutputToContain('failed: '.prunePathOf('old orphan').': not deleted')
        ->assertFailed();
});
