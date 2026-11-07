<?php

declare(strict_types=1);

use App\Domain\Billing\Services\BillingProviderRegistry;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Twa\Services\InitDataValidator;
use App\Domain\Twa\Services\JwtService;
use App\Octane\Listeners\RememberTransactionLevels;
use App\Octane\Listeners\RollBackLeakedTransactions;
use App\Octane\Listeners\ShareRequestMemosWithSandbox;
use Illuminate\Contracts\Auth\Access\Gate;
use Laravel\Octane\Contracts\OperationTerminated;
use Laravel\Octane\Events\RequestHandled;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\TaskReceived;
use Laravel\Octane\Events\TaskTerminated;
use Laravel\Octane\Events\TickReceived;
use Laravel\Octane\Events\TickTerminated;
use Laravel\Octane\Events\WorkerErrorOccurred;
use Laravel\Octane\Events\WorkerStarting;
use Laravel\Octane\Events\WorkerStopping;
use Laravel\Octane\Listeners\CloseMonologHandlers;
use Laravel\Octane\Listeners\CollectGarbage;
use Laravel\Octane\Listeners\EnsureUploadedFilesAreValid;
use Laravel\Octane\Listeners\EnsureUploadedFilesCanBeMoved;
use Laravel\Octane\Listeners\FlushOnce;
use Laravel\Octane\Listeners\FlushTemporaryContainerInstances;
use Laravel\Octane\Listeners\ReportException;
use Laravel\Octane\Listeners\StopWorkerIfNecessary;
use Laravel\Octane\Octane;

/*
 * Laravel Octane: the worker mode of the `app-api` container (APP_SERVER=octane, docs/21_OCTANE_READINESS.md §3.5,
 * docs/DEVOPS_GUIDE.md §0 «Режим контейнера app-api»). The application is booted once per worker and every request is
 * served from a clone ("sandbox") of it; the listeners below reset per-request state between requests. php-fpm (`app`,
 * the queue workers, the scheduler) never reads this file.
 */
return [

    /*
    | FrankenPHP: the image stage app-octane (docker/octane-start.sh). RoadRunner is the fallback without code changes
    | (docs/21 §3.1); Swoole is not built into the image.
    */
    'server' => env('OCTANE_SERVER', 'frankenphp'),

    /*
    | true: every absolute URL is https and the request is marked secure (EnforceRequestScheme) - the production
    | container behind nginx with TLS (compose sets it). false for a local `octane:start` without TLS.
    */
    'https' => env('OCTANE_HTTPS', false),

    /*
    | Octane's defaults plus the two guards of this application (app/Octane/Listeners):
    |  - ShareRequestMemosWithSandbox: one OrgRequestMemo / PanelRequestMemo for the sandbox and the base application,
    |    so the observers (resolved by the event dispatcher from the base application) flush the memory the policies
    |    read (docs/21 §2.2 №12). After Octane's own listeners: it needs the sandbox request state they prepare.
    |  - RememberTransactionLevels + RollBackLeakedTransactions: a transaction the request left open is rolled back and
    |    reported, instead of continuing in the next request of the same worker (docs/21 §2.2 №32).
    | CollectGarbage is on (threshold `garbage` below). DisconnectFromDatabases stays off on purpose: a worker keeps its
    | PDO handle, reconnecting through pgbouncer costs ~20 ms per request (R12d).
    */
    'listeners' => [
        WorkerStarting::class => [
            EnsureUploadedFilesAreValid::class,
            EnsureUploadedFilesCanBeMoved::class,
        ],

        RequestReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
            ...Octane::prepareApplicationForNextRequest(),
            ShareRequestMemosWithSandbox::class,
            RememberTransactionLevels::class,
        ],

        RequestHandled::class => [
            //
        ],

        RequestTerminated::class => [
            // FlushUploadedFiles::class,
        ],

        TaskReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
            //
        ],

        TaskTerminated::class => [
            //
        ],

        TickReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
            //
        ],

        TickTerminated::class => [
            //
        ],

        OperationTerminated::class => [
            FlushOnce::class,
            FlushTemporaryContainerInstances::class,
            RollBackLeakedTransactions::class,
            CollectGarbage::class,
        ],

        WorkerErrorOccurred::class => [
            ReportException::class,
            StopWorkerIfNecessary::class,
        ],

        WorkerStopping::class => [
            CloseMonologHandlers::class,
        ],
    ],

    /*
    | Resolved once when the worker boots, then shared by every request of that worker. A singleton that is first
    | resolved DURING a request lands in the sandbox and dies with it, i.e. it is rebuilt on every request - hence the
    | application's own stateless singletons are listed here (docs/21 §2.2 №5, №17): all of them are built from the
    | configuration alone and hold nothing of a request. The Gate gets the sandbox as its container on every request
    | (GiveNewApplicationInstanceToAuthorizationGate). LearningPolicy and FsrsParameters are resolved in
    | AppServiceProvider::boot already.
    |
    | TelegramClient - and BillingProviderRegistry, whose Telegram Stars provider takes the client in its constructor - are
    | warmed only when TELEGRAM_BOT_TOKEN is set: with an empty token the SDK throws ("Required token not supplied")
    | while the worker boots, the worker never reaches its request loop and the whole API is down because of one
    | optional integration. With a token they stay warmed - the Guzzle client of the Bot API is built once per worker
    | and reuses connections (docs/21 №5); without it they are resolved lazily, as before.
    */
    'warm' => [
        ...Octane::defaultServicesToWarm(),
        Gate::class,
        JwtService::class,
        InitDataValidator::class,
        ...(is_string(env('TELEGRAM_BOT_TOKEN')) && trim((string) env('TELEGRAM_BOT_TOKEN')) !== ''
            ? [TelegramClient::class, BillingProviderRegistry::class]
            : []),
    ],

    /*
    | Nothing: the per-request bindings are `scoped` and Octane forgets them itself (FlushTemporaryContainerInstances);
    | there is no singleton with request state to drop.
    */
    'flush' => [
        //
    ],

    /*
    | Swoole only - not used (Redis is the one cache, docs/19 §3; a per-process table is invisible to php-fpm). Kept as
    | in Octane's stub so the `octane` store of config/cache.php stays resolvable.
    */
    'tables' => [
        'example:1000' => [
            'name' => 'string:1000',
            'votes' => 'int',
        ],
    ],

    'cache' => [
        'rows' => 1000,
        'bytes' => 10000,
    ],

    /*
    | `octane:start --watch` in development only; the production image never watches.
    */
    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        '.env',
    ],

    /*
    | gc_collect_cycles() after a request once the worker uses more than this many megabytes (docs/21 §3.4): the api
    | worker without Filament sits at ~50-90 MB; OCTANE_MAX_REQUESTS restarts it anyway.
    */
    'garbage' => 128,

    /*
    | Seconds per request (FrankenPHP: REQUEST_MAX_EXECUTION_TIME) - PHP's own default; nginx's proxy_read_timeout (60 s)
    | is above it. The TTS fallback is 8 s inside this.
    */
    'max_execution_time' => 30,

    /*
    | Master process id and admin endpoint of the running server, read by octane:status / reload / stop.
    */
    'state_file' => storage_path('logs/octane-server-state.json'),

    /*
    | RoadRunner (the fallback server, OCTANE_SERVER=roadrunner): its worker binary and HTTP middleware; unused with FrankenPHP.
    */
    'roadrunner' => [
        'command' => 'vendor/bin/roadrunner-worker',
        'http_middleware' => 'static',
    ],

];
