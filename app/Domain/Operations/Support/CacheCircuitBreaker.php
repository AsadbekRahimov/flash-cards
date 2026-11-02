<?php

declare(strict_types=1);

namespace App\Domain\Operations\Support;

use Carbon\CarbonImmutable;

/**
 * A circuit breaker in front of a member of a failover cache chain (docs/19 §4.1), shared by every process that shares the
 * marker directory: the php-fpm workers of the app container and, through the `app_storage` volume, the queue workers and
 * the scheduler. A hung Redis instance - one that accepts the connection and never answers - costs a timeout per call
 * without it; with it, the first failure opens the breaker and every other call of every process skips the member at once.
 *
 * State is ONE FILE per guarded store (`<directory>/<store>`): present = open, its mtime = the moment of the last failure,
 * and the breaker is open while `mtime + open seconds` lies in the future. A file rather than Redis or APCu because the
 * state must outlive a php-fpm request (statics do not), must cost nothing while the cache is healthy (one stat() of a path
 * that does not exist: a microsecond, no network round trip on every request as a key in the other Redis would be), and the
 * image has no APCu.
 *
 * Protocol:
 *   - closed (no file): every call goes to the member;
 *   - a failure creates the file - `fopen` with `x`, so exactly ONE process creates it and reports the opening;
 *   - open (file younger than the window): the member is skipped, not even a connection is attempted;
 *   - half-open (file older than the window): ONE process at a time - the one holding a flock() on the file - tries the
 *     member (the probe) while the others keep skipping. A probe that succeeds deletes the file (closed again); one that
 *     fails touches it (open for another window) and reports again.
 *
 * Per process the answer is remembered: nothing is read while the breaker is known to be open, and a member that answered
 * is trusted until it fails - a php-fpm request costs one stat, a long-lived worker reads the file again only after its own
 * failure or when its window ends. The clock is CarbonImmutable::now() (the tests travel in time); the file carries whole
 * seconds.
 */
final class CacheCircuitBreaker
{
    /** @var array<string, int|true> store => until when (unix seconds) it is known open, or true: trusted until it fails */
    private array $memo = [];

    /** @var array<string, resource> store => the lock handle of the probe this process is running right now */
    private array $probes = [];

    public function __construct(private readonly string $directory, private readonly int $openSeconds) {}

    public function openSeconds(): int
    {
        return $this->openSeconds;
    }

    public function markerPath(string $store): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.$store;
    }

    /**
     * Whether `$store` may be tried now: the breaker is closed, or it is half-open and this process has just become its
     * prober. After a `true` the caller reports the outcome with {@see succeeded()} or {@see failed()}.
     */
    public function allows(string $store): bool
    {
        $memo = $this->memo[$store] ?? null;
        if ($memo === true) {
            return true;
        }

        $now = $this->now();
        if ($memo !== null && $now < $memo) {
            return false;
        }

        $path = $this->markerPath($store);
        $mtime = $this->mtime($path);

        if ($mtime === null) {
            $this->memo[$store] = true;

            return true;
        }

        if ($now < $mtime + $this->openSeconds) {
            $this->memo[$store] = $mtime + $this->openSeconds;

            return false;
        }

        // Half-open: the window has passed. One prober at a time - whoever holds the lock on the marker.
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            // Deleted meanwhile by a probe that succeeded.
            $this->memo[$store] = true;

            return true;
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            // Another process is probing right now: keep skipping, look again next second.
            $this->memo[$store] = $now + 1;

            return false;
        }

        // Under the lock the previous prober may have renewed the marker (its probe failed) or deleted it (it succeeded).
        $mtime = $this->mtime($path);
        if ($mtime !== null && $now < $mtime + $this->openSeconds) {
            flock($handle, LOCK_UN);
            fclose($handle);
            $this->memo[$store] = $mtime + $this->openSeconds;

            return false;
        }

        if ($mtime === null) {
            flock($handle, LOCK_UN);
            fclose($handle);
            $this->memo[$store] = true;

            return true;
        }

        $this->probes[$store] = $handle;
        $this->memo[$store] = true;

        return true;
    }

    /**
     * The member answered. Returns true when this was the probe that closed the breaker - the recovery to report.
     */
    public function succeeded(string $store): bool
    {
        $this->memo[$store] = true;

        $probe = $this->probes[$store] ?? null;
        if ($probe === null) {
            return false;
        }

        unset($this->probes[$store]);
        @unlink($this->markerPath($store));
        flock($probe, LOCK_UN);
        fclose($probe);

        return true;
    }

    /**
     * The member failed. Opens the breaker for a window; returns true when THIS call opened (or re-opened) it - the one
     * process that reports it. A fresh marker left by another process means it has already been reported.
     */
    public function failed(string $store): bool
    {
        $now = $this->now();
        $path = $this->markerPath($store);
        $this->memo[$store] = $now + $this->openSeconds;

        $probe = $this->probes[$store] ?? null;
        if ($probe !== null) {
            // The probe failed: another window, under the lock - nobody else is re-opening it at the same time.
            unset($this->probes[$store]);
            @touch($path, $now);
            flock($probe, LOCK_UN);
            fclose($probe);

            return true;
        }

        if (! is_dir($this->directory)) {
            @mkdir($this->directory, 0775, true);
        }

        $handle = @fopen($path, 'x');
        if ($handle !== false) {
            fclose($handle);
            @touch($path, $now);

            return true;
        }

        $mtime = $this->mtime($path);
        if ($mtime === null) {
            // Neither created nor present: the directory cannot be written. No shared state, then - this process keeps
            // its own window (the memo above) and reports, so the operator sees both the outage and the odd marker.
            return true;
        }

        if ($now < $mtime + $this->openSeconds) {
            return false;
        }

        // A stale marker nobody renewed (a prober that died mid-probe): a new window from this failure.
        @touch($path, $now);

        return true;
    }

    /** Forgets everything about the store: the marker, the memory, a probe in flight (tests, operations). */
    public function reset(string $store): void
    {
        $probe = $this->probes[$store] ?? null;
        if ($probe !== null) {
            unset($this->probes[$store]);
            flock($probe, LOCK_UN);
            fclose($probe);
        }

        unset($this->memo[$store]);
        @unlink($this->markerPath($store));
    }

    private function mtime(string $path): ?int
    {
        clearstatcache(true, $path);
        $mtime = @filemtime($path);

        return $mtime === false ? null : $mtime;
    }

    private function now(): int
    {
        return CarbonImmutable::now()->getTimestamp();
    }
}
