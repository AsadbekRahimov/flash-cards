<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Analytics\Services\ProductEventBuffer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Product analytics events of an API request are gathered and written with one INSERT after the response, instead of one
 * INSERT (and, inside a transaction, a savepoint) per event in the learner's way. Only the `api` group has it: webhooks,
 * panels, the console and the queue keep writing at once. See ProductEventBuffer for the rules and the cost.
 *
 * The buffer is taken from the current container on each call (not through the constructor): see ProductEventBuffer.
 */
class DeferProductEvents
{
    public function handle(Request $request, Closure $next): Response
    {
        app(ProductEventBuffer::class)->defer();

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        // After this, an event recorded by a later terminating callback is written at once, not lost.
        app(ProductEventBuffer::class)->finish();
    }
}
