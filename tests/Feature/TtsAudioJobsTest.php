<?php

declare(strict_types=1);

use App\Domain\Content\Contracts\SpeechSynthesizer;
use App\Domain\Content\Exceptions\SpeechSynthesisException;
use App\Domain\Content\Services\WordAudioService;
use App\Domain\Practice\Enums\AudioStatus;
use App\Jobs\GeneratePracticeAudioJob;
use App\Jobs\PregenerateWordAudioJob;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\PracticeItemVersion;
use App\Models\Stage;
use App\Models\Word;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/*
 * docs/26 §9.2, §9.4: what the audio jobs do with the errors of the synthesizer, and the words of a learner's own deck.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['twa.tts.voice' => 'en-US-Chirp3-HD-Charon', 'twa.tts.audio_version' => 1]);
    Storage::fake('public');
    Storage::fake('practice_audio');
});

/** A synthesizer that throws the given error, or answers with an mp3 and counts the calls. */
function ttsJobsSynth(?SpeechSynthesisException $error = null): SpeechSynthesizer
{
    $synth = new class($error) implements SpeechSynthesizer
    {
        public int $calls = 0;

        public function __construct(private readonly ?SpeechSynthesisException $error) {}

        public function synthesize(string $text, string $voice): array
        {
            $this->calls++;
            if ($this->error !== null) {
                throw $this->error;
            }

            return ['body' => 'mp3-bytes', 'content_type' => 'audio/mpeg'];
        }
    };
    app()->instance(SpeechSynthesizer::class, $synth);

    return $synth;
}

function ttsJobsWord(?Course $course = null): Word
{
    $stage = Stage::factory()->create(['course_id' => ($course ?? Course::factory()->create())->id]);

    return Word::factory()->for(Lesson::factory()->for($stage))->create(['word' => 'resilient']);
}

/** Runs the word job with a mocked queue job, so a `fail()` is seen. */
function ttsJobsRunWord(Word $word, Closure $expect): void
{
    $queueJob = Mockery::mock(QueueJob::class);
    $expect($queueJob);
    $job = new PregenerateWordAudioJob($word->id);
    $job->setJob($queueJob);

    $job->handle(app(WordAudioService::class));
}

it('the word job ends quietly at the monthly limit: no exception, no fail, no warning', function (): void {
    Log::spy();
    ttsJobsSynth(SpeechSynthesisException::monthlyLimitReached());
    $word = ttsJobsWord();

    ttsJobsRunWord($word, function ($queueJob): void {
        $queueJob->shouldNotReceive('fail');
        $queueJob->shouldNotReceive('release');
    });

    expect($word->fresh()?->audio_path)->toBeNull();
    Log::shouldNotHaveReceived('warning');
});

it('the word job leaves no failed job at the monthly limit on a real queue run', function (): void {
    config(['queue.default' => 'sync']);
    Log::spy();
    ttsJobsSynth(SpeechSynthesisException::monthlyLimitReached());
    $word = ttsJobsWord();

    PregenerateWordAudioJob::dispatch($word->id);

    expect($word->fresh()?->audio_path)->toBeNull();
    Log::shouldNotHaveReceived('warning');
});

it('the word job fails at once on a final error, without a retry', function (SpeechSynthesisException $error): void {
    ttsJobsSynth($error);
    $word = ttsJobsWord();

    ttsJobsRunWord($word, function ($queueJob) use ($error): void {
        $queueJob->shouldReceive('fail')->once()->with($error);
        $queueJob->shouldNotReceive('release');
    });
})->with([
    'forbidden' => fn () => SpeechSynthesisException::forbidden(),
    'auth failed' => fn () => SpeechSynthesisException::authFailed(),
    'credentials invalid' => fn () => SpeechSynthesisException::credentialsInvalid(),
    'rejected' => fn () => SpeechSynthesisException::rejected(),
    'not configured' => fn () => SpeechSynthesisException::notConfigured(),
]);

it('the word job throws a retryable error for the retry of the queue', function (SpeechSynthesisException $error): void {
    ttsJobsSynth($error);
    $word = ttsJobsWord();

    expect(fn () => ttsJobsRunWord($word, fn ($queueJob) => $queueJob->shouldNotReceive('fail')))
        ->toThrow(SpeechSynthesisException::class, $error->getMessage());
})->with([
    'failed' => fn () => SpeechSynthesisException::failed(),
    'rate limited' => fn () => SpeechSynthesisException::rateLimited(),
    'bad audio' => fn () => SpeechSynthesisException::badAudio(),
]);

it('the dictation job turns the version to failed with tts_monthly_limit_reached', function (): void {
    config(['queue.default' => 'sync']);
    ttsJobsSynth(SpeechSynthesisException::monthlyLimitReached());
    $version = PracticeItemVersion::factory()->dictation()->audioPending()->create();

    GeneratePracticeAudioJob::dispatch($version->id);

    $failed = $version->fresh();
    expect($failed?->audio_status)->toBe(AudioStatus::Failed)
        ->and($failed?->audio_error)->toBe('tts_monthly_limit_reached');
});

it('the word job never voices a word of a learner\'s own deck (docs/26 §9.4)', function (): void {
    $synth = ttsJobsSynth();
    $deck = Course::factory()->deckOf(Learner::factory()->create()->id)->create();
    $word = ttsJobsWord($deck);

    (new PregenerateWordAudioJob($word->id))->handle(app(WordAudioService::class));
    (new PregenerateWordAudioJob($word->id, force: true))->handle(app(WordAudioService::class));

    expect($synth->calls)->toBe(0)
        ->and($word->fresh()?->audio_path)->toBeNull()
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('the word job voices a word of a platform or organization course', function (string $ownerType): void {
    $synth = ttsJobsSynth();
    $word = ttsJobsWord(Course::factory()->create(['owner_type' => $ownerType]));

    (new PregenerateWordAudioJob($word->id))->handle(app(WordAudioService::class));

    expect($synth->calls)->toBe(1)
        ->and($word->fresh()?->audio_path)->not->toBeNull();
})->with(['platform', 'organization']);
