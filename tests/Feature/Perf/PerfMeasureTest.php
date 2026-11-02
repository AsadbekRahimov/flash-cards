<?php

declare(strict_types=1);

use App\Domain\Operations\Services\PerfSeeder;
use App\Domain\Twa\Services\JwtService;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\User;
use Composer\InstalledVersions;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Support\PerfProbe;

/*
 * The baseline ("до") of docs/20 §4 and docs/19 §8: the list pages of every resource of /admin and /org, both dashboards
 * (page + widgets) and the hot TWA endpoints, with the number of SQL statements, their time, the render time (response
 * minus SQL) and the size of the first render (HTML / JSON) as a proxy for the payload.
 *
 * It runs on the data of `perf:seed`, so it never wipes the database (no RefreshDatabase) and is skipped in a normal run:
 *
 *     php artisan migrate:fresh && php artisan perf:seed --learners=50000      # PostgreSQL, APP_ENV=local|testing
 *     PERF_MEASURE=1 ./vendor/bin/pest --group=perf
 *
 * The result is a markdown file, storage/logs/perf-<run>.md (PERF_RUN names the run; the date by default).
 */

/** The file of this run; the header is written with the first section. */
function perfReport(string $section): void
{
    static $run = null;
    $run ??= (string) (getenv('PERF_RUN') ?: date('Ymd-His'));
    $path = storage_path("logs/perf-{$run}.md");

    if (! is_file($path)) {
        $tables = [];
        foreach (['learners', 'words', 'lessons', 'word_repetitions', 'training_reviews', 'payments', 'exam_answers', 'organization_members', 'product_events'] as $table) {
            $tables[] = $table.' '.number_format(DB::table($table)->count());
        }

        file_put_contents($path, sprintf(
            "# Baseline %s\n\n- PHP %s (CLI, OPcache for CLI %s), Laravel %s, Filament %s, Livewire %s\n- %s\n- Data: %s\n- Each page: 1 warm-up request, then the measured ones; times in ms on a shared machine; the SQL time is the sum reported by the query log; render = response - SQL.\n\n",
            date('Y-m-d H:i:s').' UTC',
            PHP_VERSION,
            filter_var(ini_get('opcache.enable_cli'), FILTER_VALIDATE_BOOLEAN) ? 'on' : 'off',
            app()->version(),
            InstalledVersions::getPrettyVersion('filament/filament'),
            InstalledVersions::getPrettyVersion('livewire/livewire'),
            (string) DB::selectOne('select version() as v')->v,
            implode(', ', $tables),
        ));
    }

    file_put_contents($path, $section."\n", FILE_APPEND);
}

function perfGuard(): void
{
    expect(DB::getDriverName())->toBe('pgsql', 'measure on PostgreSQL (DB_CONNECTION=pgsql)');
    expect(DB::table('courses')->where('slug', PerfSeeder::COURSE_SLUG)->exists())->toBeTrue('the database holds no perf:seed data set: php artisan perf:seed first');
}

/**
 * GET $url $repeat times after one warm-up request; one report row.
 *
 * @return list<string|int|float|null>
 */
function perfPageRow($test, string $label, string $url, ?int $rows, int $repeat = 4): array
{
    $test->get($url);

    $runs = [];
    $bytes = 0;
    $status = 0;
    for ($i = 0; $i < $repeat; $i++) {
        $run = PerfProbe::measure(fn () => $test->get($url));
        $runs[] = $run;
        $bytes = strlen((string) $run['result']->getContent());
        $status = $run['result']->getStatusCode();
    }

    $summary = PerfProbe::summarize($runs);

    return [$label.($status === 200 ? '' : " ({$status})"), $rows === null ? '' : number_format($rows), $summary['queries'], round($summary['sql_ms']), round($summary['render_ms']), round($summary['p95']), round($bytes / 1024, 1), $summary['repeat'] ?? ''];
}

/**
 * Mounts a widget $repeat times after one warm-up; one report row.
 *
 * @return list<string|int|float|null>
 */
function perfWidgetRow(string $widget, int $repeat = 3): array
{
    Livewire::test($widget);

    $runs = [];
    $bytes = 0;
    for ($i = 0; $i < $repeat; $i++) {
        $run = PerfProbe::measure(fn () => Livewire::test($widget));
        $runs[] = $run;
        $bytes = strlen($run['result']->html());
    }

    $summary = PerfProbe::summarize($runs);

    return [class_basename($widget), '', $summary['queries'], round($summary['sql_ms']), round($summary['render_ms']), round($summary['p95']), round($bytes / 1024, 1), $summary['repeat'] ?? ''];
}

const PERF_PAGE_HEADER = ['Page', 'Rows', 'Queries', 'SQL, ms', 'Render, ms', 'p95 total, ms', 'HTML, KB', 'Most repeated statement'];

/** A panel's dashboard: the page and each widget on its own (widgets load lazily in a browser), and their sum. */
function perfDashboard($test, string $title, string $url, array $widgets): string
{
    $rows = [perfPageRow($test, 'Dashboard page '.$url, $url, null)];
    $sum = $rows[0];

    foreach ($widgets as $widget) {
        if (! $widget::canView()) {
            continue;
        }

        $row = perfWidgetRow($widget);
        $rows[] = $row;
        foreach ([2, 3, 4, 5, 6] as $i) {
            $sum[$i] += $row[$i];
        }
    }

    $sum[0] = "**{$title}: page + widgets (sum)**";
    $sum[1] = '';
    $sum[7] = '';

    return PerfProbe::markdown(PERF_PAGE_HEADER, [...$rows, $sum]);
}

it('measures the list pages and the dashboard of /admin', function (): void {
    perfGuard();

    $admin = User::query()->where('email', PerfSeeder::ADMIN_EMAIL)->firstOrFail();
    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    $this->actingAs($admin)->withSession(['2fa.web.passed_at' => now()->timestamp, '2fa.web.user_id' => $admin->id]);

    $rows = [];
    foreach ($panel->getResources() as $resource) {
        if (! array_key_exists('index', $resource::getPages())) {
            continue;
        }

        $count = null;
        try {
            $count = $resource::getEloquentQuery()->toBase()->count();
        } catch (Throwable) {
        }

        $rows[] = perfPageRow($this, str_replace('Resource', '', class_basename($resource)).' '.$resource::getUrl('index', isAbsolute: false), $resource::getUrl('index', isAbsolute: false), $count);
    }

    perfReport("## /admin: list pages\n\n".PerfProbe::markdown(PERF_PAGE_HEADER, $rows));
    perfReport("## /admin: dashboard\n\n".perfDashboard($this, '/admin', '/admin', $panel->getWidgets()));

    expect($rows)->not->toBeEmpty();
})->group('perf')->skip(fn (): bool => getenv('PERF_MEASURE') !== '1', 'PERF_MEASURE=1 and a database seeded by perf:seed');

it('measures the list pages and the dashboard of /org', function (): void {
    perfGuard();

    $owner = User::query()->where('email', sprintf(PerfSeeder::OWNER_EMAIL, 1))->firstOrFail();
    $organization = Organization::query()->where('slug', sprintf(PerfSeeder::ORGANIZATION_SLUG, 1))->firstOrFail();
    $panel = Filament::getPanel('org');
    Filament::setCurrentPanel($panel);
    Filament::bootCurrentPanel();
    $this->actingAs($owner, 'org')->withSession(['2fa.org.passed_at' => now()->timestamp, '2fa.org.user_id' => $owner->id]);
    Filament::setTenant($organization, isQuiet: true);

    $rows = [];
    foreach ($panel->getResources() as $resource) {
        if (! array_key_exists('index', $resource::getPages())) {
            continue;
        }

        $count = null;
        try {
            $count = $resource::getEloquentQuery()->toBase()->count();
        } catch (Throwable) {
        }

        $url = $resource::getUrl('index', isAbsolute: false);
        $rows[] = perfPageRow($this, str_replace('Resource', '', class_basename($resource)).' '.$url, $url, $count);
    }

    perfReport("## /org (center 1, owner): list pages\n\n".PerfProbe::markdown(PERF_PAGE_HEADER, $rows));
    perfReport("## /org: dashboard\n\n".perfDashboard($this, '/org', '/org/'.$organization->slug, $panel->getWidgets()));

    expect($rows)->not->toBeEmpty();
})->group('perf')->skip(fn (): bool => getenv('PERF_MEASURE') !== '1', 'PERF_MEASURE=1 and a database seeded by perf:seed');

/**
 * One request per learner of $pool (the limiter and the per-learner caches see a different learner each time), after a
 * warm-up with another learner: the figures are the spread over learners, not one user hammered.
 *
 * @param  list<int>  $pool  learner ids
 * @param  Closure(Learner, array<string, string>): TestResponse  $call
 * @return array{queries: int, queries_max: int, sql_ms: float, render_ms: float, p50: float, p95: float, repeat: ?string, kb: float, status: int}
 */
function perfTwa(array $pool, int $warmUp, Closure $call): array
{
    $headers = fn (int $learnerId): array => ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learnerId)['token']];

    $call(Learner::query()->findOrFail($warmUp), $headers($warmUp));

    $runs = [];
    $bytes = 0;
    $status = 0;
    foreach ($pool as $learnerId) {
        $learner = Learner::query()->findOrFail($learnerId);
        $run = PerfProbe::measure(fn () => $call($learner, $headers($learnerId)));
        $runs[] = $run;
        $bytes = max($bytes, strlen((string) $run['result']->getContent()));
        $status = max($status, $run['result']->getStatusCode());
    }

    return [...PerfProbe::summarize($runs), 'kb' => round($bytes / 1024, 1), 'status' => $status];
}

it('measures the hot TWA endpoints', function (): void {
    perfGuard();
    config(['practice.access' => 'all']);

    $base = (int) DB::table('learners')->where('telegram_user_id', PerfSeeder::TELEGRAM_USER_BASE + 1)->value('id');
    $learners = (int) DB::table('learners')->where('telegram_user_id', '>', PerfSeeder::TELEGRAM_USER_BASE)->count();
    // Power users: the first learners (300 repetitions, a friend, a league seat, a center, Pro); onboarded ones only
    // (every tenth learner, idx 9, 19, ..., is not onboarded).
    $heavy = collect(range(0, 19))->reject(fn (int $i): bool => $i % 10 === 9)->map(fn (int $i): int => $base + $i)->values()->all();
    // Typical learners: 24 repetitions, spread over the whole population.
    $typical = collect(range(0, 19))->map(fn (int $i): int => 1000 + $i * 997)->filter(fn (int $i): bool => $i % 10 !== 9 && $i < $learners)->map(fn (int $i): int => $base + $i)->values()->all();
    $warm = $base + min(5000, $learners - 1);

    $slug = (string) DB::table('courses')->where('slug', PerfSeeder::COURSE_SLUG)->value('slug');

    $endpoints = [
        '/me' => fn ($learner, $h) => $this->withHeaders($h)->getJson('/api/twa/me'),
        '/today' => fn ($learner, $h) => $this->withHeaders($h)->getJson('/api/twa/today'),
        '/courses' => fn ($learner, $h) => $this->withHeaders($h)->getJson('/api/twa/courses'),
        '/courses/{slug}' => fn ($learner, $h) => $this->withHeaders($h)->getJson("/api/twa/courses/{$slug}"),
        '/league' => fn ($learner, $h) => $this->withHeaders($h)->getJson('/api/twa/league'),
        '/friends' => fn ($learner, $h) => $this->withHeaders($h)->getJson('/api/twa/friends'),
        '/practice/hub' => fn ($learner, $h) => $this->withHeaders($h)->getJson("/api/twa/practice/hub?course={$slug}"),
    ];

    $sessions = [];
    $training = [
        'POST /training/sessions (daily review, first card)' => function ($learner, $h) use (&$sessions) {
            $response = $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'daily_review']);
            $sessions[$learner->id] = [(int) $response->json('session_id'), (int) $response->json('card.word_id'), $response->json('card.serve_token'), $response->json('card.prompt.type')];

            return $response;
        },
        'GET /training/sessions/{id}/next' => function ($learner, $h) use (&$sessions) {
            $response = $this->withHeaders($h)->getJson('/api/twa/training/sessions/'.($sessions[$learner->id][0] ?? 0).'/next');
            // The card to answer is the one served last.
            $sessions[$learner->id] = [$sessions[$learner->id][0] ?? 0, (int) $response->json('card.word_id'), $response->json('card.serve_token'), $response->json('card.prompt.type')];

            return $response;
        },
        'POST /training/sessions/{id}/review' => function ($learner, $h) use (&$sessions) {
            [$sessionId, $wordId, $serveToken, $type] = $sessions[$learner->id] ?? [0, 0, null, null];
            // Spelling and gap-fill cards are graded by the server (`answer`), the others by the client (`quality`).
            $graded = in_array($type, ['spelling', 'gap_fill'], true) ? ['answer' => 'perf'] : ['quality' => 4];

            return $this->withHeaders($h)->postJson("/api/twa/training/sessions/{$sessionId}/review", array_filter(
                ['word_id' => $wordId, ...$graded, 'time_spent_ms' => 1500, 'serve_token' => $serveToken],
                fn ($value): bool => $value !== null,
            ));
        },
    ];

    $report = [];
    foreach ([['power users', $heavy], ['typical learners', $typical]] as [$who, $pool]) {
        $rows = [];
        foreach ([...$endpoints, ...$training] as $label => $call) {
            // The training steps run in order for the same learner: open, next, review.
            Cache::flush();
            $m = perfTwa($pool, $warm, $call);
            $rows[] = [$label.($m['status'] === 200 ? '' : " ({$m['status']})"), $m['queries'], $m['queries_max'], round($m['sql_ms'], 1), round($m['render_ms'], 1), round($m['p50'], 1), round($m['p95'], 1), $m['kb'], $m['repeat'] ?? ''];
        }
        $report[] = "### {$who} (".count($pool)." learners, one request each)\n\n".PerfProbe::markdown(['Endpoint', 'Queries p50', 'Queries max', 'SQL, ms', 'Render, ms', 'p50 total, ms', 'p95 total, ms', 'JSON, KB', 'Most repeated statement'], $rows);
    }

    perfReport("## TWA: hot endpoints (warm: each endpoint after one request of another learner; shared caches start empty)\n\n".implode("\n", $report));

    expect($report)->not->toBeEmpty();
})->group('perf')->skip(fn (): bool => getenv('PERF_MEASURE') !== '1', 'PERF_MEASURE=1 and a database seeded by perf:seed');
