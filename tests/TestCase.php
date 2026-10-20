<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\IrazasyedTelegramClient;
use App\Domain\Telegram\Services\TelegramKeyboardFactory;
use App\Domain\Telegram\Services\TelegramMessageRateLimiter;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeTelegramClient;
use Tests\Support\OfflineTelegramApi;

abstract class TestCase extends BaseTestCase
{
    /**
     * RefreshDatabase rolls back the DB but not the cache. With the array store
     * the state dies with the app instance; with a persistent store (CI runs
     * CACHE_STORE=redis) rate-limiter hits, cached entitlements/lookups and
     * locks would leak from one test into the next, so start each test clean.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        Http::preventStrayRequests();

        // No test talks to the real Bot API (CI has internet, a fictitious token answers 404): the contract is
        // a recording fake, the concrete adapter an SDK that throws. A test that checks sending rebinds its own.
        $this->app->instance(TelegramClient::class, new FakeTelegramClient);
        $this->app->singleton(IrazasyedTelegramClient::class, fn (): IrazasyedTelegramClient => new IrazasyedTelegramClient(
            new OfflineTelegramApi,
            $this->app->make(TelegramKeyboardFactory::class),
            $this->app->make(TelegramMessageRateLimiter::class),
        ));
    }
}
