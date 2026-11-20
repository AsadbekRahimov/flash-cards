<?php

declare(strict_types=1);

use App\Domain\Content\Contracts\SpeechSynthesizer;
use App\Domain\Content\Exceptions\SpeechSynthesisException;
use App\Domain\Content\Services\Tts\NullSpeechSynthesizer;
use App\Domain\Content\Services\TtsSampleRecorder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 * `audio:sample` (docs/26 §5.3, §7 step 3): the control set synthesized right now with the current driver, the files with clear names
 * in a local folder, a table of text / file / size; an error of the driver is printed as its code and nothing secret.
 */

beforeEach(function (): void {
    config(['twa.tts.voice' => 'en-US-Neural2-F']);
    $this->dir = sys_get_temp_dir().'/audio-sample-test-'.Str::random(8);
});

afterEach(function (): void {
    File::deleteDirectory($this->dir);
});

/**
 * A synthesizer that records what it got; `$fail` maps a sent text to the error it throws.
 *
 * @param  array<string, SpeechSynthesisException|RuntimeException>  $fail
 */
function sampleSynth(array $fail = []): object
{
    $synth = new class($fail) implements SpeechSynthesizer
    {
        /** @var list<array{string, string}> */
        public array $calls = [];

        /** @param  array<string, Throwable>  $fail */
        public function __construct(private readonly array $fail) {}

        public function synthesize(string $text, string $voice): array
        {
            $this->calls[] = [$text, $voice];
            if (isset($this->fail[$text])) {
                throw $this->fail[$text];
            }

            return ['body' => 'mp3:'.$text, 'content_type' => 'audio/mpeg'];
        }
    };
    app()->instance(SpeechSynthesizer::class, $synth);

    return $synth;
}

it('has about thirty texts: short words, homographs, abbreviations, numbers, dates, phrases and two long dictations', function (): void {
    $samples = app(TtsSampleRecorder::class)->samples();
    $names = array_column($samples, 'name');

    expect(count($samples))->toBeGreaterThanOrEqual(28)->toBeLessThanOrEqual(32)
        ->and(array_unique($names))->toHaveCount(count($names))
        ->and(array_column($samples, 'text'))->toContain('a', 'I', 'go', 'read', 'live', 'lead', 'TV', 'USA', 'Mr.')
        ->and($names)->toContain('01-short-a.mp3', '02-short-i.mp3')
        ->and(array_filter($names, fn (string $name): bool => str_contains($name, '-dictation-')))->toHaveCount(2)
        ->and(array_filter($names, fn (string $name): bool => str_contains($name, '-phrase-')))->toHaveCount(6);

    foreach ($samples as $sample) {
        expect($sample['name'])->toMatch('/\A\d{2}-[a-z]+-[a-z0-9-]+\.mp3\z/');
    }
});

it('voices the set synchronously into the folder of the voice and prints the table', function (): void {
    Queue::fake();
    $synth = sampleSynth();
    $total = count(app(TtsSampleRecorder::class)->samples());

    $this->artisan('audio:sample', ['--dir' => $this->dir])
        ->expectsOutputToContain('Voice en-US-Neural2-F')
        ->expectsTable(['Text', 'File', 'Size'], array_map(fn (array $s): array => [
            $s['text'] === $s['sent'] ? $s['text'] : "{$s['text']} → {$s['sent']}",
            $s['name'],
            number_format(strlen('mp3:'.$s['sent']) / 1024, 1).' KB',
        ], app(TtsSampleRecorder::class)->samples()))
        ->expectsOutput("Voiced {$total} of {$total}.")
        ->assertSuccessful();

    Queue::assertNothingPushed();
    expect($synth->calls)->toHaveCount($total)
        ->and($synth->calls[1])->toBe(['i', 'en-US-Neural2-F'])                       // a word is sent the way a word job sends it
        ->and(collect($synth->calls)->pluck(0))->toContain('Can you turn on the TV, please?') // a phrase as it is
        ->and(File::get($this->dir.'/01-short-a.mp3'))->toBe('mp3:a')
        ->and(File::files($this->dir))->toHaveCount($total);
});

it('puts the files into storage/app/audio-samples/{voice} by default and takes --voice', function (): void {
    $synth = sampleSynth();
    $dir = storage_path('app/audio-samples/en-US-Chirp3-HD-Charon');
    File::deleteDirectory($dir);

    try {
        $this->artisan('audio:sample', ['--voice' => 'en-US-Chirp3-HD-Charon'])->assertSuccessful();

        expect(File::exists($dir.'/01-short-a.mp3'))->toBeTrue()
            ->and($synth->calls[0][1])->toBe('en-US-Chirp3-HD-Charon');
    } finally {
        File::deleteDirectory($dir);
    }
});

it('refuses a voice that is not a voice name (it is a folder name too)', function (string $voice): void {
    $synth = sampleSynth();

    $this->artisan('audio:sample', ['--voice' => $voice, '--dir' => $this->dir])->assertExitCode(2);

    expect($synth->calls)->toBe([]);
})->with(['../etc', 'en US', 'en/US']);

it('prints a failed text as its code, keeps going after a retryable one and fails the run', function (): void {
    sampleSynth(['go' => SpeechSynthesisException::rateLimited()]);
    $total = count(app(TtsSampleRecorder::class)->samples());

    $this->artisan('audio:sample', ['--dir' => $this->dir])
        ->expectsOutputToContain('tts_rate_limited')
        ->expectsOutput('Voiced '.($total - 1)." of {$total}, 1 failed.")
        ->assertFailed();

    expect(File::exists($this->dir.'/03-short-go.mp3'))->toBeFalse()
        ->and(File::files($this->dir))->toHaveCount($total - 1);
});

it('stops at a final error of the driver (the sign-in to Google) and prints its code only', function (): void {
    $synth = sampleSynth(['a' => SpeechSynthesisException::authFailed()]);

    $this->artisan('audio:sample', ['--dir' => $this->dir])
        ->expectsOutputToContain('Stopped after a final error: tts_auth_failed. Voiced 0 of')
        ->assertFailed();

    expect($synth->calls)->toHaveCount(1);
});

it('says the driver is not configured with TTS_DRIVER=null', function (): void {
    app()->instance(SpeechSynthesizer::class, new NullSpeechSynthesizer);

    $this->artisan('audio:sample', ['--dir' => $this->dir])
        ->expectsOutputToContain(SpeechSynthesisException::NOT_CONFIGURED)
        ->assertFailed();
});

it('never prints the message of an unexpected error, only its class', function (): void {
    sampleSynth(['a' => new RuntimeException('secret-token-abc')]);

    $this->artisan('audio:sample', ['--dir' => $this->dir])
        ->expectsOutputToContain('tts_unexpected_error (RuntimeException)')
        ->doesntExpectOutputToContain('secret-token-abc')
        ->assertFailed();
});

it('refuses an answer that is not audio', function (): void {
    app()->instance(SpeechSynthesizer::class, new class implements SpeechSynthesizer
    {
        public function synthesize(string $text, string $voice): array
        {
            return ['body' => '<html>', 'content_type' => 'text/html'];
        }
    });

    $this->artisan('audio:sample', ['--dir' => $this->dir])->expectsOutputToContain('tts_service_bad_audio')->assertFailed();

    expect(File::files($this->dir))->toBe([]);
});
