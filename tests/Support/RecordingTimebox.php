<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Timebox;

/**
 * A {@see Timebox} that runs the callback like the real one but records every requested duration
 * and never sleeps, so a test can assert the budget a page asks for without waiting for it.
 */
final class RecordingTimebox extends Timebox
{
    /** @var list<int> microseconds passed to each `call()` */
    public array $durations = [];

    public function call(callable $callback, int $microseconds): mixed
    {
        $this->durations[] = $microseconds;

        return parent::call($callback, $microseconds);
    }

    protected function usleep(int $microseconds): void {}
}
