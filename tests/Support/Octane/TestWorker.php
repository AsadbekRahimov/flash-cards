<?php

declare(strict_types=1);

namespace Tests\Support\Octane;

use Laravel\Octane\Contracts\Client;
use Laravel\Octane\Testing\Fakes\FakeWorker;

/**
 * Octane's FakeWorker booted without the WorkerStarting event: its only listeners (EnsureUploadedFilesAreValid,
 * EnsureUploadedFilesCanBeMoved) replace Symfony's is_uploaded_file() / move_uploaded_file() for the whole PHP process -
 * right for a worker, wrong for a test process that goes on running other tests. Requests are served by the real
 * `Worker::handle()`.
 */
final class TestWorker extends FakeWorker
{
    public function boot(array $initialInstances = []): void
    {
        $this->app = $this->appFactory->createApplication($initialInstances + [Client::class => $this->client]);
    }
}
