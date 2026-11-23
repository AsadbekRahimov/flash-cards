<?php

declare(strict_types=1);

use App\Jobs\GeneratePracticeAudioJob;
use App\Jobs\PregenerateWordAudioJob;

/*
 * docs/26 §9.3: the audio jobs have the queue `tts`, served by the `low` worker AFTER `low` (strict priority), so a mass run
 * of syntheses never stands in front of the `low` heartbeat; and `tts` is checked by neither readiness list.
 */

it('puts both audio jobs on the tts queue', function (): void {
    expect((new PregenerateWordAudioJob(1))->queue)->toBe('tts')
        ->and((new GeneratePracticeAudioJob(1))->queue)->toBe('tts');
});

it('lets the low worker of every compose file take tts after low', function (string $file, string $service): void {
    $compose = (string) file_get_contents(base_path($file));

    // The command line of the service: from its name to the next `command:`.
    expect(preg_match('/^\s*'.preg_quote($service, '/').':\n(?:(?!^\s*command:).*\n)*?^\s*command: (.+)$/m', $compose, $m))->toBe(1)
        ->and($m[1])->toContain('"--queue=low,tts"');

    // No worker of the file serves tts before low, or serves tts elsewhere.
    preg_match_all('/"--queue=([a-z,]+)"/', $compose, $queues);
    expect(array_values(array_filter($queues[1], fn (string $list): bool => str_contains($list, 'tts'))))->toBe(['low,tts']);
})->with([
    'prod' => ['docker-compose.prod.yml', 'queue-low'],
    'coolify' => ['docker-compose.coolify.yml', 'lf-queue-low'],
]);

it('keeps tts out of the readiness backlog and heartbeat lists', function (): void {
    expect(config('operations.readiness.queues'))->not->toContain('tts')
        ->and(config('operations.readiness.heartbeat_queues'))->not->toContain('tts')
        ->and(config('operations.readiness.heartbeat_queues'))->toContain('low');
});
