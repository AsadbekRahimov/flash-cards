<?php

declare(strict_types=1);

use App\Domain\Practice\DTO\MaterialScope;
use App\Domain\Practice\Enums\AudioStatus;
use App\Domain\Practice\Enums\PracticeItemStatus;
use App\Domain\Practice\Enums\PracticeItemType;
use App\Domain\Practice\Services\PracticeAccess;
use App\Domain\Practice\Services\PracticeMaterialService;
use App\Domain\Practice\Support\PracticeTextNormalizer;
use App\Models\Course;
use App\Models\Learner;
use App\Models\PracticeItem;
use App\Models\PracticeItemVersion;
use App\Models\PracticeItemWord;
use App\Models\WordRepetition;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoContentSeeder;
use Database\Seeders\PracticeDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
 * PracticeDemoSeeder (docs/16 §16 item 1): the pilot package, published, in the demo course; the demo learners in the pilot and
 * introduced to its words; the audio of the phrases made by the seeder itself with ffmpeg + flite - it queues no voicing job and
 * calls no TTS service, whatever a developer's .env names (the queue of a test is the sync one, so a job would run in the middle
 * of the seeding: TestCase forbids stray HTTP requests, which fails any test of this file that lets one through).
 * ffmpeg itself is replaced by a stand-in that writes a file, so the test runs the same everywhere.
 */

beforeEach(function (): void {
    Storage::fake('practice_audio');
    // A developer's .env names a TTS service (the .env.example does): the seeder must not need it.
    config(['twa.tts.service_url' => 'http://tts:8000', 'queue.default' => 'sync']);
    $this->commands = [];
    // The stand-in of ffmpeg: it "makes" the mp3 (the last argument is the file to write) and remembers how it was asked to.
    Process::fake(function (PendingProcess $process): mixed {
        $command = is_array($process->command) ? $process->command : [(string) $process->command];
        $this->commands[] = $command;
        file_put_contents((string) end($command), 'ID3 a stand-in of an mp3');

        return Process::result();
    });
});

/** The whole demo, as `db:seed` does it. */
function pds_seed_all(): void
{
    test()->seed(DatabaseSeeder::class);
}

it('imports the pilot package published into the demo course', function (): void {
    pds_seed_all();

    expect(PracticeItem::query()->count())->toBe(90)
        ->and(PracticeItem::query()->where('status', PracticeItemStatus::Published->value)->count())->toBe(90)
        ->and(PracticeItem::query()->whereNull('published_version_id')->count())->toBe(0)
        ->and(Course::query()->where('slug', 'default')->sole()->id)->toBe(PracticeItem::query()->distinct()->value('course_id'));
});

it('puts the demo learners into the pilot and introduces them to the target words, the way the lessons of the group would have', function (): void {
    pds_seed_all();

    $words = PracticeItemWord::query()->distinct()->pluck('word_id')->all();
    $learners = Learner::query()->orderBy('id')->get();

    expect($learners)->toHaveCount(5)
        ->and(Learner::query()->whereNotNull('practice_pilot_at')->count())->toBe(5);

    foreach ($learners as $learner) {
        $rows = WordRepetition::query()->where('learner_id', $learner->id)->whereIn('word_id', $words)->get();

        expect($rows)->toHaveCount(count($words))
            ->and($rows->every(fn (WordRepetition $row): bool => $row->introduced_mode === 'group_lesson' && $row->created_at?->lt(now()->subDays(8)) === true))->toBeTrue();
    }
});

it('leaves the cards of the group, and what is due today, as they were', function (): void {
    pds_seed_all();

    // A row that DemoRepetitionsSeeder made is not overwritten (the pair is unique): its history is the group's own.
    expect(WordRepetition::query()->where('introduced_mode', '!=', 'group_lesson')->count())->toBe(0)
        ->and(WordRepetition::query()->where('created_at', '>', now()->subDays(8))->count())->toBe(0)
        ->and(WordRepetition::query()->where('created_at', '<=', now()->subDays(30)->subMinute())->count())->toBe(0);
});

it('voices every phrase of the dictations: the file of the key of docs/16 §10.1, the version ready', function (): void {
    pds_seed_all();

    $versions = PracticeItemVersion::query()
        ->whereIn('id', PracticeItem::query()->where('type', PracticeItemType::Dictation->value)->select('published_version_id'))
        ->get();

    expect($versions)->toHaveCount(30);
    foreach ($versions as $version) {
        $key = sha1(implode('|', [$version->id, PracticeTextNormalizer::normalize($version->content['text']), config('twa.tts.voice'), config('twa.tts.audio_version')]));

        expect($version->audio_status)->toBe(AudioStatus::Ready)
            ->and($version->audio_key)->toBe($key)
            ->and($version->audio_error)->toBeNull()
            ->and($version->audio_generated_at)->not->toBeNull()
            ->and(Storage::disk('practice_audio')->exists("{$key}.mp3"))->toBeTrue();
    }

    // The text of a phrase never goes into the command line: it goes through a file (quotes and colons need no escaping there).
    $first = $this->commands[0];
    expect($this->commands)->toHaveCount(30)
        ->and($first[0])->toBe('ffmpeg')
        ->and(implode(' ', $first))->toContain('flite=textfile=')->toContain('libmp3lame')->not->toContain('tomorrow')->not->toContain('Good morning');
});

it('makes the demo practice work: a demo learner of the pilot gets the tasks of a pilot lesson and the audio is ready', function (): void {
    config(['practice.access' => 'pilot', 'practice.plan' => 'all']);
    pds_seed_all();

    $course = Course::query()->where('slug', 'default')->sole();
    $learner = Learner::query()->orderBy('id')->firstOrFail();
    $outsider = Learner::factory()->create();
    $material = app(PracticeMaterialService::class);

    expect(app(PracticeAccess::class)->state($learner)->isAvailable())->toBeTrue()
        ->and(app(PracticeAccess::class)->state($outsider)->isAvailable())->toBeFalse();

    // Module 1, lesson 3 and module 2, lesson 1 are not covered by DemoRepetitionsSeeder: the seeder introduced the words itself.
    foreach ([[1, 2], [1, 3], [2, 1]] as [$stage, $number]) {
        $lesson = $course->stages()->where('number', $stage)->firstOrFail()->lessons()->where('number', $number)->firstOrFail();
        $scope = new MaterialScope($course, $lesson);

        foreach (PracticeItemType::cases() as $type) {
            expect($material->eligibleItems($scope, $type, $learner))->toHaveCount(10, "{$type->value} of module {$stage} lesson {$number}");
        }
    }
});

it('does nothing the second time: no new task, no new version, no second voicing, the pilot date stays', function (): void {
    pds_seed_all();
    $pilotAt = Learner::query()->orderBy('id')->firstOrFail()->practice_pilot_at;
    $versions = PracticeItemVersion::query()->count();
    $rows = WordRepetition::query()->count();
    $ran = count($this->commands);

    $this->seed(PracticeDemoSeeder::class);

    expect(PracticeItem::query()->count())->toBe(90)
        ->and(PracticeItemVersion::query()->count())->toBe($versions)
        ->and(WordRepetition::query()->count())->toBe($rows)
        ->and(count($this->commands))->toBe($ran)
        ->and(Learner::query()->orderBy('id')->firstOrFail()->practice_pilot_at?->equalTo($pilotAt))->toBeTrue();
});

it('voices again a phrase whose file has gone', function (): void {
    pds_seed_all();
    $version = PracticeItemVersion::query()->where('audio_status', AudioStatus::Ready->value)->orderBy('id')->firstOrFail();
    Storage::disk('practice_audio')->delete("{$version->audio_key}.mp3");
    $ran = count($this->commands);

    $this->seed(PracticeDemoSeeder::class);

    expect(count($this->commands))->toBe($ran + 1)
        ->and(Storage::disk('practice_audio')->exists("{$version->audio_key}.mp3"))->toBeTrue();
});

it('leaves the phrases pending and does not break when ffmpeg or its flite filter is missing, with no TTS service either', function (string $url): void {
    config(['twa.tts.service_url' => $url]);
    Process::fake(fn () => Process::result(errorOutput: 'No such filter: flite', exitCode: 1));
    $this->seed(DemoContentSeeder::class);

    // The sync queue, as in a test or a local run: nothing is queued, so nothing can fail "for want of a TTS service" or reach one.
    $this->seed(PracticeDemoSeeder::class);

    expect(PracticeItem::query()->count())->toBe(90)
        ->and(PracticeItemVersion::query()->where('audio_status', AudioStatus::Pending->value)->count())->toBe(30)
        ->and(PracticeItemVersion::query()->where('audio_status', AudioStatus::Failed->value)->count())->toBe(0)
        ->and(Storage::disk('practice_audio')->allFiles())->toBe([]);
    Http::assertNothingSent();
    // It asked once and gave up: thirty failures in a row would only be thirty waits.
    Process::assertRanTimes(fn () => true, 1);
})->with(['no TTS service' => [''], 'a TTS service' => ['http://tts:8000']]);

it('does not take an empty file for audio', function (): void {
    Process::fake(fn () => Process::result());
    $this->seed(DemoContentSeeder::class);

    $this->seed(PracticeDemoSeeder::class);

    // The stand-in "succeeded" but wrote nothing: that is no mp3.
    expect(PracticeItemVersion::query()->where('audio_status', AudioStatus::Pending->value)->count())->toBe(30);
});

it('voices the phrases itself whatever TTS service is configured: no job is queued and no request is sent', function (string $url): void {
    config(['twa.tts.service_url' => $url]);
    Queue::fake();
    $this->seed(DemoContentSeeder::class);

    $this->seed(PracticeDemoSeeder::class);

    Queue::assertNothingPushed();
    Http::assertNothingSent();
    expect($this->commands)->toHaveCount(30)
        ->and(PracticeItemVersion::query()->where('audio_status', AudioStatus::Ready->value)->count())->toBe(30)
        ->and(PracticeItemVersion::query()->where('audio_status', AudioStatus::Pending->value)->count())->toBe(0);
})->with(['no TTS service' => [''], 'a TTS service' => ['http://tts:8000']]);

it('seeds on the sync queue with a TTS service that nobody can reach: db:seed does not call it', function (): void {
    // With the .env of a developer (a TTS service) and the sync queue the import used to run the voicing job in the middle of the
    // seeding: a request to the service, which a test forbids and a machine without the service fails.
    pds_seed_all();

    Http::assertNothingSent();
    expect(PracticeItem::query()->count())->toBe(90)
        ->and(PracticeItemVersion::query()->where('audio_status', AudioStatus::Ready->value)->count())->toBe(30);
});

it('leaves the phrases pending when the disk of the practice audio is not configured', function (): void {
    // `Storage::fake` put a disk in place of the missing one; take it away again, so the disk is what the config says: nothing.
    config(['filesystems.disks.practice_audio' => null]);
    Storage::forgetDisk('practice_audio');
    $this->seed(DemoContentSeeder::class);

    $this->seed(PracticeDemoSeeder::class);

    expect($this->commands)->toBe([])
        ->and(PracticeItem::query()->count())->toBe(90)
        ->and(PracticeItemVersion::query()->where('audio_status', AudioStatus::Pending->value)->count())->toBe(30);
});

it('takes the course from the package, not from the default course of the installation', function (): void {
    $this->seed(DatabaseSeeder::class);
    $learner = Learner::query()->orderBy('id')->firstOrFail();
    WordRepetition::query()->where('learner_id', $learner->id)->whereIn('word_id', PracticeItemWord::query()->select('word_id'))->delete();
    Learner::query()->update(['practice_pilot_at' => null]);

    // Another default course (`CONTENT_DEFAULT_COURSE_SLUG`): it is not the course of the package, which names `default`.
    config(['content.default_course_slug' => 'elsewhere']);
    $this->seed(PracticeDemoSeeder::class);

    expect(Course::query()->where('slug', 'elsewhere')->exists())->toBeFalse()
        ->and(Learner::query()->whereNotNull('practice_pilot_at')->count())->toBe(5)
        ->and(WordRepetition::query()->where('learner_id', $learner->id)->whereIn('word_id', PracticeItemWord::query()->select('word_id'))->count())
        ->toBe(PracticeItemWord::query()->distinct()->count('word_id'));
});

it('imports the package without a demo group, and puts nobody into the pilot', function (): void {
    $this->seed(DemoContentSeeder::class);

    $this->seed(PracticeDemoSeeder::class);

    expect(PracticeItem::query()->count())->toBe(90)
        ->and(Learner::query()->whereNotNull('practice_pilot_at')->count())->toBe(0);
});

it('does not run outside local and testing, like the other demo seeders', function (): void {
    $this->seed(DemoContentSeeder::class);
    app()->detectEnvironment(fn (): string => 'production');
    config(['app.demo_seed' => false]);

    // Run directly: `db:seed` itself asks a person to confirm in production.
    app(PracticeDemoSeeder::class)->run();

    expect(PracticeItem::query()->count())->toBe(0)
        ->and($this->commands)->toBe([]);

    config(['app.demo_seed' => true]);
    app(PracticeDemoSeeder::class)->run();

    expect(PracticeItem::query()->count())->toBe(90);
});

it('stops the seeding with the reason when the package is refused', function (): void {
    // No demo content: the course has none of the lessons and words of the package.
    expect(fn () => $this->seed(PracticeDemoSeeder::class))->toThrow(RuntimeException::class, 'The pilot package of the practice was refused');
    expect(PracticeItem::query()->count())->toBe(0);
});
