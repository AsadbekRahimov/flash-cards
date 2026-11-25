<?php

declare(strict_types=1);

/*
 * Real races on PostgreSQL of the word-points foundation (docs/32 S1, S3):
 *   - the fixing of a period's category (§2.3, §4 "Фиксация категории двумя запросами"): several requests credit the first answer of
 *     a week at the same instant, with different plans in hand (an upgrade landing between them) - exactly one row per period, every
 *     request reads the same category, exactly one of them reports that it fixed it;
 *   - the catalog (§2.1): two long transactions (imports of two courses) create overlapping lexemes in opposite orders - no deadlock,
 *     one row per key, both read the same ids.
 * Each contender is a forked process with its own connection, inside its own transaction; committed rows: DatabaseTruncation.
 * Skipped on sqlite.
 */

use App\Domain\Content\Services\LexemeCatalog;
use App\Domain\Engagement\Enums\ScoreCategory;
use App\Domain\Engagement\Enums\ScorePeriod;
use App\Domain\Engagement\Services\Scoring\ScorePeriodService;
use App\Models\Learner;
use App\Models\LearnerScorePeriod;
use App\Models\Lexeme;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\ForkSafe;

$onPostgres = (getenv('DB_CONNECTION') ?: 'sqlite') === 'pgsql';

if (! $onPostgres) {
    it('races the word-points foundation on PostgreSQL', function (): void {})
        ->skip('Requires PostgreSQL (DB_CONNECTION=pgsql): real parallel transactions need committed rows and row locks.');

    return;
}

uses(DatabaseTruncation::class);

beforeEach(function (): void {
    config()->set('leagues.timezone', 'Asia/Tashkent');
    config()->set('leagues.scoring.mode', 'live');
    config()->set('leagues.scoring.versions.2.starts_on', '2027-02-01');
});

afterEach(function (): void {
    $this->truncateTablesForAllConnections();
});

/**
 * @param  list<callable(): mixed>  $tasks
 * @return list<array{ok: bool, result?: mixed, error?: string, message?: string}>
 */
function scorePeriodRace(array $tasks): array
{
    ForkSafe::releaseConnections();

    $dir = sys_get_temp_dir().'/lexiflow-score-period-race-'.getmypid().'-'.bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    $go = "{$dir}/go";
    $pids = [];

    foreach ($tasks as $i => $task) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('pcntl_fork failed');
        }

        if ($pid === 0) {
            $outcome = [];
            try {
                ForkSafe::releaseConnections();
                // A deadlock must surface as an error, not as a hang of the suite.
                DB::statement("SET lock_timeout = '10s'");

                $deadline = microtime(true) + 10;
                while (! file_exists($go) && microtime(true) < $deadline) {
                    usleep(500);
                }

                $outcome = ['ok' => true, 'result' => $task()];
            } catch (Throwable $e) {
                $outcome = ['ok' => false, 'error' => $e::class, 'message' => $e->getMessage()];
            } finally {
                file_put_contents("{$dir}/{$i}.json", json_encode($outcome, JSON_THROW_ON_ERROR));
                DB::disconnect();
                posix_kill(posix_getpid(), SIGKILL);
            }
        }

        $pids[] = $pid;
    }

    touch($go);

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $results = [];
    foreach (array_keys($tasks) as $i) {
        /** @var array{ok: bool, result?: mixed, error?: string, message?: string} $decoded */
        $decoded = json_decode((string) file_get_contents("{$dir}/{$i}.json"), true, 512, JSON_THROW_ON_ERROR);
        $results[] = $decoded;
        unlink("{$dir}/{$i}.json");
    }
    unlink($go);
    rmdir($dir);

    return $results;
}

it('fixes the category of a week and a month once when four requests credit their first answer at the same instant', function (): void {
    $learner = Learner::factory()->create();
    $now = CarbonImmutable::parse('2027-02-10 12:00', 'Asia/Tashkent')->utc();

    $categories = [ScoreCategory::Free, ScoreCategory::Pro, ScoreCategory::Free, ScoreCategory::Pro];
    $results = scorePeriodRace(array_map(fn (ScoreCategory $category): Closure => function () use ($learner, $now, $category): array {
        return DB::transaction(function () use ($learner, $now, $category): array {
            $fixed = app(ScorePeriodService::class)->fixDay($learner->id, $now, $category, $now);
            // Hold the transaction a moment, as the answer that follows the fixing would.
            usleep(50_000);

            return [
                'week' => $fixed['week']->category->value,
                'month' => $fixed['month']->category->value,
                'week_created' => $fixed['week']->created,
                'month_created' => $fixed['month']->created,
            ];
        });
    }, $categories));

    foreach ($results as $result) {
        expect($result['ok'])->toBeTrue(($result['error'] ?? '').' '.($result['message'] ?? ''));
    }

    $rows = LearnerScorePeriod::query()->where('learner_id', $learner->id)->get();
    $weekCategory = $rows->firstWhere('period', ScorePeriod::Week)?->category->value;
    $monthCategory = $rows->firstWhere('period', ScorePeriod::Month)?->category->value;

    expect($rows)->toHaveCount(2)
        ->and(collect($results)->pluck('result.week')->unique()->values()->all())->toBe([$weekCategory])
        ->and(collect($results)->pluck('result.month')->unique()->values()->all())->toBe([$monthCategory])
        ->and(collect($results)->where('result.week_created', true)->count())->toBe(1)
        ->and(collect($results)->where('result.month_created', true)->count())->toBe(1);
});

it('fixes one period once when it is fixed alone by parallel requests', function (): void {
    $learner = Learner::factory()->create();
    $now = CarbonImmutable::parse('2027-02-15 00:00:05', 'Asia/Tashkent')->utc();

    $results = scorePeriodRace(array_fill(0, 3, fn (): array => DB::transaction(function () use ($learner, $now): array {
        $fixed = app(ScorePeriodService::class)->fix($learner->id, ScorePeriod::Week, $now, ScoreCategory::Pro, $now);

        return ['category' => $fixed->category->value, 'created' => $fixed->created, 'version' => $fixed->rulesVersion];
    })));

    foreach ($results as $result) {
        expect($result['ok'])->toBeTrue(($result['error'] ?? '').' '.($result['message'] ?? ''));
    }

    expect(collect($results)->where('result.created', true)->count())->toBe(1)
        ->and(collect($results)->pluck('result.version')->unique()->all())->toBe([2])
        ->and(LearnerScorePeriod::query()->where('learner_id', $learner->id)->count())->toBe(1);
});

it('creates overlapping lexemes from two long transactions in opposite orders without a deadlock', function (): void {
    $keys = array_map(fn (int $i): string => "word{$i}", range(1, 60));

    $results = scorePeriodRace([
        fn (): array => DB::transaction(function () use ($keys): array {
            $ids = app(LexemeCatalog::class)->ensureKeys($keys);
            usleep(200_000); // the rest of the import

            return $ids;
        }),
        fn (): array => DB::transaction(function () use ($keys): array {
            $ids = app(LexemeCatalog::class)->ensureKeys(array_reverse($keys));
            usleep(200_000);

            return $ids;
        }),
    ]);

    foreach ($results as $result) {
        expect($result['ok'])->toBeTrue(($result['error'] ?? '').' '.($result['message'] ?? ''));
    }

    $stored = Lexeme::query()->pluck('id', 'key')->all();
    ksort($stored);
    $first = $results[0]['result'];
    $second = $results[1]['result'];
    ksort($first);
    ksort($second);

    expect($stored)->toHaveCount(60)
        ->and($first)->toBe($stored)
        ->and($second)->toBe($stored);
});
