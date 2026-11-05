<?php

declare(strict_types=1);

/*
 * Isolation between the requests of one Octane worker (docs/21 §3.7): the requests go through a real Octane
 * `Worker::handle()` over the application of the test (Tests\Support\OctaneHarness), i.e. through the sandbox clone,
 * the listeners of config/octane.php and the two guards of app/Octane/Listeners. Runs on sqlite and on pgsql alike.
 */

use App\Domain\Administration\Services\PanelRequestMemo;
use App\Domain\Organizations\Services\OrgRequestMemo;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\Subscription;
use App\Octane\LeakedTransactionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Tests\Support\Octane\MemoProbeEvent;
use Tests\Support\Octane\MemoProbeListener;
use Tests\Support\OctaneHarness;

uses(RefreshDatabase::class);

it('serves each learner in the locale of that learner and restores the default locale after the request', function (): void {
    $octane = OctaneHarness::boot($this->app);
    $uz = Learner::factory()->create(['interface_locale' => 'uz']);
    $ru = Learner::factory()->create(['interface_locale' => 'ru']);
    $default = (string) config('app.locale');
    Route::get('/__octane/locale', fn (): string => app('translator')->getLocale());

    $messages = [
        'uz' => "kunlik maqsad maydoni butun son bo'lishi kerak.",
        'ru' => 'Поле дневная цель должно быть целым числом.',
    ];

    foreach ([$uz, $ru, $uz] as $learner) {
        $response = $octane->handle(OctaneHarness::request('PATCH', '/api/twa/me/settings', $learner, ['daily_goal' => '20']));
        $me = $octane->handle(OctaneHarness::request('GET', '/api/twa/me', $learner));

        expect($response->getStatusCode())->toBe(422)
            ->and(OctaneHarness::json($response)['errors']['daily_goal'][0])->toBe($messages[$learner->interface_locale])
            ->and(OctaneHarness::json($me)['learner']['locale'])->toBe($learner->interface_locale)
            // The sandbox's configuration died with the request: the base application is back at the default.
            ->and(app()->getLocale())->toBe($default)
            ->and(config('app.locale'))->toBe($default);

        // The translator (a shared service) is reset by FlushLocaleState when the next request begins: a request that sets
        // no locale sees the default, not the language of the previous learner.
        expect($octane->handle(Request::create('/__octane/locale'))->getContent())->toBe($default);
    }
});

it('keeps the plan and the center of a learner to that learner across the requests of one worker', function (): void {
    $octane = OctaneHarness::boot($this->app);
    $org = Organization::factory()->create();
    Subscription::factory()->forOrganization($org)->create();
    $pro = Learner::factory()->onboarded()->seatedIn($org)->create();
    $free = Learner::factory()->onboarded()->create();

    foreach ([$pro, $free, $pro, $free] as $learner) {
        $me = OctaneHarness::json($octane->handle(OctaneHarness::request('GET', '/api/twa/me', $learner)));
        $today = OctaneHarness::json($octane->handle(OctaneHarness::request('GET', '/api/twa/today', $learner)));

        $plan = $learner->is($pro) ? 'pro' : 'free';
        $source = $learner->is($pro) ? "sponsor:organization:{$org->id}" : 'free';

        expect($me['learner']['id'])->toBe($learner->id)
            ->and($me['entitlement']['plan'])->toBe($plan)
            ->and($me['entitlement']['source'])->toBe($source)
            ->and($today['entitlement']['plan'])->toBe($plan)
            ->and($today['entitlement']['source'])->toBe($source);
    }
});

it('gives the policies (sandbox) and the observers (base application) one per-request memory and keeps none of it after the request', function (): void {
    $octane = OctaneHarness::boot($this->app);
    Event::listen(MemoProbeEvent::class, MemoProbeListener::class);

    $seen = null;
    Route::get('/__octane/memo', function () use (&$seen): array {
        // What a policy does: resolve the memo through app(), i.e. from the sandbox, and remember a value.
        $seen = app(OrgRequestMemo::class);
        $hadProbeOnEntry = $seen->has('probe');
        $seen->remember('probe', fn (): string => 'value');

        // What an observer does: get the memo from the event dispatcher's container (the base application).
        $probe = new MemoProbeEvent;
        event($probe);

        return [
            'had_probe_on_entry' => $hadProbeOnEntry,
            'sandbox_org' => spl_object_id($seen),
            'sandbox_panel' => spl_object_id(app(PanelRequestMemo::class)),
            'listener_org' => $probe->orgMemoId,
            'listener_panel' => $probe->panelMemoId,
            'listener_sees_probe' => $probe->orgMemoHasProbe,
        ];
    });

    $first = OctaneHarness::json($octane->handle(Request::create('/__octane/memo')));

    expect($first['had_probe_on_entry'])->toBeFalse()
        ->and($first['listener_org'])->toBe($first['sandbox_org'])
        ->and($first['listener_panel'])->toBe($first['sandbox_panel'])
        ->and($first['listener_sees_probe'])->toBeTrue();

    // After the request: the base application has forgotten the scoped instance (FlushTemporaryContainerInstances) - a
    // fresh, empty memo is built on the next resolution - and the sandbox is gone. `$seen` keeps the request's object
    // alive, so the ids compared here belong to two live objects.
    expect(app())->toBe($this->app)
        ->and(spl_object_id($this->app->make(OrgRequestMemo::class)))->not->toBe($first['sandbox_org'])
        ->and($this->app->make(OrgRequestMemo::class)->has('probe'))->toBeFalse()
        ->and($this->app->make(PanelRequestMemo::class)->has('probe'))->toBeFalse();

    // The next request starts with an empty memory of its own, shared the same way.
    $second = OctaneHarness::json($octane->handle(Request::create('/__octane/memo')));

    expect($second['had_probe_on_entry'])->toBeFalse()
        ->and($second['listener_org'])->toBe($second['sandbox_org'])
        ->and($second['listener_sees_probe'])->toBeTrue();
});

it('rolls back a transaction a request left open, reports it, and the next request writes normally', function (): void {
    $octane = OctaneHarness::boot($this->app);
    Exceptions::fake();

    // RefreshDatabase wraps the test in a transaction: the level before the request is what the worker must come back to.
    $baseline = DB::transactionLevel();

    Route::get('/__octane/leak-open', function (): string {
        DB::beginTransaction();

        return 'ok';
    });
    Route::get('/__octane/leak-throw', function (): never {
        DB::beginTransaction();

        throw new RuntimeException('boom');
    });
    Route::post('/__octane/write', fn (): int => Learner::factory()->create()->id);

    $open = $octane->handle(Request::create('/__octane/leak-open'));
    expect($open->getStatusCode())->toBe(200)
        ->and(DB::transactionLevel())->toBe($baseline);
    Exceptions::assertReported(fn (LeakedTransactionException $e): bool => str_contains($e->getMessage(), 'GET /__octane/leak-open')
        && str_contains($e->getMessage(), '"'.DB::connection()->getName().'"'));

    $thrown = $octane->handle(OctaneHarness::request('GET', '/__octane/leak-throw'));
    expect($thrown->getStatusCode())->toBe(500)
        ->and(DB::transactionLevel())->toBe($baseline);
    Exceptions::assertReported(fn (LeakedTransactionException $e): bool => str_contains($e->getMessage(), 'GET /__octane/leak-throw'));

    $written = $octane->handle(OctaneHarness::request('POST', '/__octane/write'));
    $id = (int) $written->getContent();

    expect($written->getStatusCode())->toBe(200)
        ->and(Learner::query()->whereKey($id)->exists())->toBeTrue()
        ->and(DB::transactionLevel())->toBe($baseline);

    // A request without a transaction of its own reports nothing.
    $octane->handle(OctaneHarness::request('POST', '/__octane/write'));
    Exceptions::assertReportedCount(3); // the two leaks + the RuntimeException of /leak-throw
});

it('believes X-Forwarded-* only from the proxies of TRUSTED_PROXIES, and never X-Forwarded-Prefix', function (): void {
    $octane = OctaneHarness::boot($this->app);
    Route::get('/__octane/ip', fn (Request $request): array => [
        'ip' => $request->ip(),
        'secure' => $request->isSecure(),
        'base_url' => $request->getBaseUrl(),
    ]);

    $forwarded = [
        'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_PREFIX' => '/evil',
    ];
    $probe = fn (string $remoteAddr): array => OctaneHarness::json(
        $octane->handle(Request::create('/__octane/ip', 'GET', [], [], [], ['REMOTE_ADDR' => $remoteAddr] + $forwarded)),
    );

    // The compose subnet is the proxy; the config is read on every request (TrustProxies::setTrustedProxyIpAddresses).
    config()->set('trustedproxy.proxies', ['172.28.0.0/16']);

    expect($probe('203.0.113.9'))->toBe(['ip' => '203.0.113.9', 'secure' => false, 'base_url' => ''])
        ->and($probe('172.28.0.3'))->toBe(['ip' => '1.2.3.4', 'secure' => true, 'base_url' => '']);

    // Empty (php-fpm, the default of .env.example): nobody, not even the compose subnet.
    config()->set('trustedproxy.proxies', []);

    expect($probe('172.28.0.3'))->toBe(['ip' => '172.28.0.3', 'secure' => false, 'base_url' => '']);
});
