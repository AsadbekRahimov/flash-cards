<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

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
    }
}
