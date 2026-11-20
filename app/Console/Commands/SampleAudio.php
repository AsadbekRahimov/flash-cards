<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Content\Services\TtsSampleRecorder;
use Illuminate\Console\Command;

/**
 * `audio:sample` (docs/26 §5.3, §7 step 3): voices the control set ({@see TtsSampleRecorder}) with the current driver right now
 * (no queue) into `storage/app/audio-samples/{voice}/` and prints what was sent, the file and its size - to listen to before the
 * voice is approved, and the first real call of Google (an error is printed as its code). The characters count against the
 * monthly limit like any synthesis. The exit code is 1 when any text failed.
 */
final class SampleAudio extends Command
{
    /** @var string */
    protected $signature = 'audio:sample
        {--voice= : The voice (default TTS_VOICE)}
        {--dir= : Where to put the files (default storage/app/audio-samples/{voice})}';

    /** @var string */
    protected $description = 'Synthesize the control set of words and phrases with the current TTS driver, to listen to before a voice is approved.';

    public function handle(TtsSampleRecorder $recorder): int
    {
        $voice = $this->option('voice');
        $voice = is_string($voice) && $voice !== '' ? $voice : (string) config('twa.tts.voice');
        // The voice is a folder name too: letters, digits and dashes, like every voice name of Google and edge-tts.
        if (preg_match('/\A[A-Za-z0-9-]+\z/', $voice) !== 1) {
            $this->error('--voice must be a voice name: letters, digits and dashes (e.g. en-US-Neural2-F).');

            return self::INVALID;
        }

        $dir = $this->option('dir');
        $dir = is_string($dir) && $dir !== '' ? $dir : storage_path('app/audio-samples/'.$voice);

        $this->info("Voice {$voice}, driver ".(string) (config('twa.tts.driver') ?: 'auto').", into {$dir}");

        $rows = $recorder->record($voice, $dir, function (array $row): void {
            $this->output->write($row['error'] === null ? '.' : 'E');
        });
        $this->newLine();

        $this->table(
            ['Text', 'File', 'Size'],
            array_map(static fn (array $row): array => [
                $row['text'] === $row['sent'] ? $row['text'] : "{$row['text']} → {$row['sent']}",
                $row['error'] ?? basename((string) $row['file']),
                $row['bytes'] === null ? '-' : number_format($row['bytes'] / 1024, 1).' KB',
            ], $rows),
        );

        $total = count($recorder->samples());
        $failed = count(array_filter($rows, static fn (array $row): bool => $row['error'] !== null));
        $done = count($rows) - $failed;

        $last = $rows === [] ? null : $rows[array_key_last($rows)];
        if ($last !== null && count($rows) < $total) {
            $this->error('Stopped after a final error: '.(string) $last['error'].'. Voiced '.$done.' of '.$total.'.');

            return self::FAILURE;
        }

        if ($failed > 0) {
            $this->error("Voiced {$done} of {$total}, {$failed} failed.");

            return self::FAILURE;
        }

        $this->info("Voiced {$done} of {$total}.");

        return self::SUCCESS;
    }
}
