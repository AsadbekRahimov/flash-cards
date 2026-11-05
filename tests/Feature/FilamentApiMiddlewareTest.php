<?php

declare(strict_types=1);

use App\Http\Middleware\PublishNotificationsOutsideApi;
use Filament\Notifications\Http\Middleware\PublishNotifications;
use Filament\Notifications\Notification;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Route;

// Filament prepends PublishNotifications to the global middleware; the API and the webhooks do not need it (and its mailbox).
// AppServiceProvider swaps it for a variant that steps aside there. The panels must keep the original behaviour.

function sendTestNotification(): void
{
    Notification::make()->title('hello')->success()->send();
}

it('replaces the global PublishNotifications with the variant that skips the API', function (): void {
    $global = app(Kernel::class)->getGlobalMiddleware();

    expect($global)->toContain(PublishNotificationsOutsideApi::class)
        ->and($global)->not->toContain(PublishNotifications::class)
        ->and(array_search(PublishNotificationsOutsideApi::class, $global, true))->toBe(0); // Filament put it first
});

it('does not build the notification mailbox for an API request', function (): void {
    $this->getJson('/api/twa/me')->assertUnauthorized();

    expect(app()->resolved(NotificationMailbox::class))->toBeFalse();
});

it('does not publish for API paths and webhooks, but publishes for a web request', function (string $uri, bool $published): void {
    Route::middleware('web')->get($uri, function () {
        sendTestNotification();

        return response('ok');
    });

    $this->get($uri)->assertOk();

    // PublishNotifications flushes what the request left pending after the response is built.
    expect(app(NotificationMailbox::class)->hasPendingNotifications())->toBe(! $published);
})->with([
    'api' => ['/api/_probe', false],
    'telegram webhook' => ['/telegram/webhook/_probe', false],
    'billing webhook' => ['/billing/webhook/_probe', false],
    'panel-like web page' => ['/_probe', true],
    'admin path' => ['/admin/_probe', true],
]);
