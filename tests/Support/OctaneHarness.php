<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Twa\Services\JwtService;
use App\Models\Learner;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\Contracts\Client;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Listeners\FlushAuthenticationState;
use Laravel\Octane\Octane;
use Laravel\Octane\Testing\Fakes\FakeClient;
use Mockery;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Octane\TestWorker;

/**
 * An Octane worker over the application of the test (docs/21 §3.7): the same harness Octane tests itself with
 * (tests/TestCase.php::createOctaneContext) - a FakeWorker whose ApplicationFactory hands back the booted test
 * application, so every request goes through the real `Worker::handle()`: the clone of the application, the listeners
 * of config/octane.php, the kernel of the sandbox, OperationTerminated. The database and the cache are those of the test
 * (RefreshDatabase's transaction is the baseline the transaction guard respects). The worker is booted without
 * WorkerStarting (see TestWorker).
 *
 * After a request Octane's listeners have pointed the long-lived managers (db, cache, router, log, ...) at the sandbox of
 * that request, which is flushed when it ends - in production the next request re-points them before anything runs. A
 * test, however, touches the database and the container BETWEEN requests (assertions, factories, RefreshDatabase's
 * rollback in tearDown), so {@see handle()} re-points them at the base application the way the next request would.
 */
final class OctaneHarness
{
    private function __construct(
        private readonly Application $app,
        private readonly TestWorker $worker,
        private readonly FakeClient $client,
    ) {}

    /** Boots a worker on the application of the test and warms the services of `octane.warm`, as a real worker does. */
    public static function boot(Application $app): self
    {
        $factory = Mockery::mock(ApplicationFactory::class);
        $factory->shouldReceive('createApplication')->andReturn($app);

        $client = new FakeClient([]);
        $app->instance(Client::class, $client);

        $worker = new TestWorker($factory, $client);
        $worker->boot();

        (new ApplicationFactory($app->basePath()))->warm($app);

        return new self($app, $worker, $client);
    }

    /** Serves one request through the worker and returns the response the client received. */
    public function handle(Request $request): Response
    {
        $this->client->requests = [$request];
        $this->client->responses = [];
        $this->client->errors = [];

        try {
            $this->worker->run();
        } finally {
            $this->restoreBaseApplication();
        }

        if ($this->client->responses === []) {
            throw new RuntimeException('The Octane worker sent no response: '.implode("\n", $this->client->errors));
        }

        return $this->client->responses[0];
    }

    /**
     * The decoded JSON body of a response.
     *
     * @return array<string, mixed>
     */
    public static function json(Response $response): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * A JSON request of the Mini App, with the learner's bearer token when a learner is given.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $server  extra server variables (REMOTE_ADDR, HTTP_X_FORWARDED_FOR, ...)
     */
    public static function request(string $method, string $uri, ?Learner $learner = null, array $payload = [], array $server = []): Request
    {
        $server = array_merge(['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], $server);
        if ($learner !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.app(JwtService::class)->issue($learner->id)['token'];
        }

        return Request::create($uri, $method, [], [], [], $server, $payload === [] ? null : json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * What the next request's RequestReceived would do for its sandbox, done for the base application: the
     * GiveNewApplicationInstanceTo* listeners and the authentication reset, nothing that flushes state.
     */
    private function restoreBaseApplication(): void
    {
        $event = new RequestReceived($this->app, $this->app, Request::create('/'));

        foreach (Octane::prepareApplicationForNextOperation() as $listener) {
            if (str_starts_with(class_basename($listener), 'GiveNewApplicationInstanceTo')) {
                $this->app->make($listener)->handle($event);
            }
        }

        $this->app->make(FlushAuthenticationState::class)->handle($event);
    }
}
