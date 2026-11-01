<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Practice\Services\PracticeAudioService;
use App\Domain\Twa\Services\TtsFallbackService;
use App\Models\PracticeItemVersion;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Voices one dictation version of a practice task (docs/16 §10.1): {@see PracticeAudioService::generate()}. One version
 * per job, like {@see PregenerateWordAudioJob}: a failing TTS call retries with backoff and never takes others down.
 * Queued by {@see PracticeAudioService::schedule()} after the commit of the version.
 *
 * A TTS service that is NOT CONFIGURED is not a failure to retry but a state of the installation: the job fails at once
 * ({@see handle()}), the version becomes `failed` with the code, and nothing is thrown out of the job.
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

        try {
            $audio->generate($version, $this->force);
        } catch (RuntimeException $e) {
            // A TTS service that is not configured stays so: five more attempts over the next hour would end the same way, and
            // a sync queue (a local environment without the service) rethrows whatever a job throws out of dispatch() - which
            // took `db:seed` down. Failing the job turns the version to `failed` with the code (failed() below), once, no retry.
            if ($e->getMessage() !== TtsFallbackService::NOT_CONFIGURED) {
                throw $e;
            }

            $this->fail($e);
        }
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
