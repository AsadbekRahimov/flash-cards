<?php

declare(strict_types=1);

namespace Tests\Support\Game;

use App\Domain\Game\Exceptions\GameException;
use App\Models\Learner;
use App\Models\ProductEvent;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\ForkSafe;
use Throwable;

/**
 * The race harness of the game (docs/18 §12.5.4): every closure runs in a forked child with a fresh PostgreSQL connection and a lock
 * timeout, all released at the same instant. A refusal of the game is a result, not a crash (`code`, `details`); anything else that
 * is thrown is a crash (`error`). A deadlock (40P01) or a wait that never ends (55P03) is a crash, never a hang - and so is a worker
 * that never returns for any other reason: the parent polls the children (`WNOHANG`) until a deadline, then kills the ones still alive
 * and records `worker timed out`, so a stuck race fails fast with a clear message instead of holding the whole suite (the CI run that
 * sat for 45 minutes). Each child also arms `pcntl_alarm()` a little past the deadline as a backstop.
 */
final class GameRace
{
    /** Seconds after the common start instant a worker may take before it is killed; `lock_timeout` (8 s) is well inside it. */
    public const DEFAULT_TIMEOUT_SECONDS = 60;

    /**
     * @param  list<Closure(): mixed>  $tasks
     * @param  int  $timeoutSeconds  the deadline of every worker, counted from the instant they are released
     * @return list<array{ok: bool, value?: mixed, code?: string, details?: array<string, mixed>, error?: string}>
     */
    public static function run(array $tasks, int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): array
    {
        ForkSafe::releaseConnections();

        $dir = sys_get_temp_dir().'/lexiflow-game-race-'.bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        $startAt = microtime(true) + 0.4;
        $deadline = $startAt + $timeoutSeconds;
        $pids = [];

        foreach ($tasks as $i => $task) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('pcntl_fork failed');
            }

            if ($pid === 0) {
                // The backstop: SIGALRM's default action ends the child a few seconds after the parent would have killed it anyway.
                pcntl_alarm($timeoutSeconds + 5);
                $out = ['ok' => false, 'error' => 'no result'];
                try {
                    ForkSafe::releaseConnections();
                    DB::reconnect();
                    DB::statement("SET lock_timeout = '8000'");
                    while (microtime(true) < $startAt) {
                        usleep(500);
                    }
                    $out = ['ok' => true, 'value' => $task()];
                } catch (GameException $e) {
                    $out = ['ok' => false, 'code' => $e->errorCode, 'details' => $e->details];
                } catch (Throwable $e) {
                    $out = ['ok' => false, 'error' => $e::class.': '.$e->getMessage()];
                } finally {
                    file_put_contents("{$dir}/{$i}.json", json_encode($out));
                    DB::disconnect();
                    posix_kill(posix_getpid(), SIGKILL);
                }
            }

            $pids[$i] = $pid;
        }

        $timedOut = self::reap($pids, $deadline);

        $results = [];
        foreach ($pids as $i => $pid) {
            $raw = @file_get_contents("{$dir}/{$i}.json");
            $results[$i] = match (true) {
                isset($timedOut[$i]) => ['ok' => false, 'error' => "worker timed out after {$timeoutSeconds} s"],
                $raw === false => ['ok' => false, 'error' => 'worker died without a result'],
                default => json_decode($raw, true, 512, JSON_THROW_ON_ERROR),
            };
            @unlink("{$dir}/{$i}.json");
        }
        @rmdir($dir);

        DB::reconnect();

        return $results;
    }

    /**
     * Waits for the children without blocking (`WNOHANG`, polled) until all have ended or the deadline has passed; the ones still alive
     * then are killed (SIGKILL) and reaped. Returns the indexes of the killed workers.
     *
     * @param  array<int, int>  $pids
     * @return array<int, true>
     */
    private static function reap(array $pids, float $deadline): array
    {
        $alive = $pids;
        while ($alive !== [] && microtime(true) < $deadline) {
            foreach ($alive as $i => $pid) {
                if (pcntl_waitpid($pid, $status, WNOHANG) !== 0) {
                    unset($alive[$i]);
                }
            }
            if ($alive !== []) {
                usleep(5_000);
            }
        }

        $timedOut = [];
        foreach ($alive as $i => $pid) {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
            $timedOut[$i] = true;
        }

        return $timedOut;
    }

    /**
     * The workers that crashed (not the ones the game refused).
     *
     * @param  list<array<string, mixed>>  $results
     * @return list<string>
     */
    public static function crashes(array $results): array
    {
        return array_values(array_map('strval', array_column(array_filter($results, static fn (array $r): bool => isset($r['error'])), 'error')));
    }

    /**
     * `ok` for a worker that succeeded, else its code, sorted.
     *
     * @param  list<array<string, mixed>>  $results
     * @return list<string>
     */
    public static function outcomes(array $results): array
    {
        $outcomes = array_map(static fn (array $r): string => $r['ok'] ? 'ok' : (string) ($r['code'] ?? 'crash'), $results);
        sort($outcomes);

        return $outcomes;
    }

    /** The learner read afresh in a worker (the parent's model must not cross the fork). */
    public static function learner(int $id): Learner
    {
        return Learner::query()->findOrFail($id);
    }

    /** @return list<array<string, mixed>> the props of every event of the name, in order */
    public static function events(string $name): array
    {
        return ProductEvent::query()->where('name', $name)->orderBy('id')->get()->map(fn (ProductEvent $e): array => (array) $e->props)->all();
    }
}
