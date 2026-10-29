<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Practice\Services\PracticeAudioService;
use App\Models\PracticeItemVersion;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Voices one dictation version of a practice task (docs/16 §10.1): {@see PracticeAudioService::generate()}. One version
 * per job, like {@see PregenerateWordAudioJob}: a failing TTS call retries with backoff and never takes others down.
 * Queued by {@see PracticeAudioService::schedule()} after the commit of the version.
 */
final class GeneratePracticeAudioJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly int $versionId,
        public readonly bool $force = false,
    ) {
        $this->onQueue('low');
    }

    /** @return list<int> seconds between attempts */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(PracticeAudioService $audio): void
    {
        /** @var PracticeItemVersion|null $version */
        $version = PracticeItemVersion::query()->find($this->versionId);

        // Deleted since it was queued: nothing to voice.
        if ($version === null) {
            return;
        }

        $audio->generate($version, $this->force);
    }

    /**
     * The last attempt has failed (docs/16 §10.1): the version stops waiting and becomes `failed` with the reason, so that the
     * admin sees it and can generate it again. Between the attempts it stays `pending`.
     */
    public function failed(Throwable $e): void
    {
        Log::warning('practice.audio_failed', [
            'version_id' => $this->versionId,
            'reason' => $e->getMessage(),
        ]);

        app(PracticeAudioService::class)->markFailed($this->versionId, $e);
    }
}
