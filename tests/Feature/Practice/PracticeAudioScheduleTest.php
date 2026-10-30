<?php

declare(strict_types=1);

use App\Domain\Practice\Enums\AudioStatus;
use App\Domain\Practice\Services\PracticeAudioService;
use App\Jobs\GeneratePracticeAudioJob;
use App\Models\PracticeItemVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

/*
 * PracticeAudioService::schedule (docs/16 §10.1): the one entry the authoring (18.7a) uses to get a dictation version voiced.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
});

it('queues the voicing of a failed dictation version after the commit and marks it pending', function (): void {
    $version = PracticeItemVersion::factory()->dictation()->audioFailed()->create();

    app(PracticeAudioService::class)->schedule($version);

    expect($version->fresh()?->audio_status)->toBe(AudioStatus::Pending)
        ->and($version->fresh()?->audio_error)->toBeNull();
    // The fake queue does not wait for commits (RefreshDatabase wraps the test in a transaction): the flag is what proves it.
    Queue::assertPushed(GeneratePracticeAudioJob::class, fn (GeneratePracticeAudioJob $job): bool => $job->versionId === $version->id
        && ! $job->force
        && $job->afterCommit === true);
});

it('leaves a ready version alone unless forced', function (): void {
    $version = PracticeItemVersion::factory()->dictation()->audioReady()->create();

    app(PracticeAudioService::class)->schedule($version);
    Queue::assertNothingPushed();

    app(PracticeAudioService::class)->schedule($version, force: true);
    Queue::assertPushed(GeneratePracticeAudioJob::class, fn (GeneratePracticeAudioJob $job): bool => $job->force);
    expect($version->fresh()?->audio_status)->toBe(AudioStatus::Pending);
});

it('does nothing for a version that is not a dictation', function (): void {
    $version = PracticeItemVersion::factory()->collocation()->create();

    app(PracticeAudioService::class)->schedule($version);

    Queue::assertNothingPushed();
    expect($version->fresh()?->audio_status)->toBe(AudioStatus::None);
});
