<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Filament\Notifications\Http\Middleware\PublishNotifications;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Filament prepends `PublishNotifications` to the GLOBAL middleware, so it wraps every request, and on the first one of a
 * process it builds the notification mailbox (~0.5-0.8 ms). A JSON API call or a payment / Telegram webhook never carries a
 * Filament notification: those requests skip it. Everything else (panels, Livewire) goes through the original unchanged.
 *
 * It takes the place of the original in the global middleware list, see AppServiceProvider::swapNotificationsMiddleware().
 */
class PublishNotificationsOutsideApi extends PublishNotifications
{
    public const SKIPPED = ['api/*', 'telegram/webhook/*', 'billing/webhook/*'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is(self::SKIPPED)) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
