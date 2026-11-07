<?php

declare(strict_types=1);

/*
 * TelegramClient is warmed by the Octane worker only when a bot token is configured: with an empty token the SDK throws
 * while the worker boots and the whole API is down (docs/21 №5, config/octane.php).
 */

use App\Domain\Billing\Services\BillingProviderRegistry;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\IrazasyedTelegramClient;
use App\Domain\Twa\Services\JwtService;
use App\Providers\AppServiceProvider;
use Illuminate\Contracts\Auth\Access\Gate;
use Tests\Support\OctaneHarness;

/**
 * The `warm` list of config/octane.php evaluated under a controlled TELEGRAM_BOT_TOKEN ($_ENV, $_SERVER and putenv,
 * where Illuminate\Support\Env reads), the environment restored afterwards.
 *
 * @return list<string>
 */
function warmListFor(?string $token): array
{
    $saved = [$_ENV['TELEGRAM_BOT_TOKEN'] ?? null, $_SERVER['TELEGRAM_BOT_TOKEN'] ?? null, getenv('TELEGRAM_BOT_TOKEN')];

    try {
        if ($token === null) {
            unset($_ENV['TELEGRAM_BOT_TOKEN'], $_SERVER['TELEGRAM_BOT_TOKEN']);
            putenv('TELEGRAM_BOT_TOKEN');
        } else {
            $_ENV['TELEGRAM_BOT_TOKEN'] = $_SERVER['TELEGRAM_BOT_TOKEN'] = $token;
            putenv('TELEGRAM_BOT_TOKEN='.$token);
        }

        /** @var array{warm: list<string>} $config */
        $config = require config_path('octane.php');

        return $config['warm'];
    } finally {
        foreach (['_ENV' => $saved[0], '_SERVER' => $saved[1]] as $name => $value) {
            if ($value === null) {
                unset($GLOBALS[$name]['TELEGRAM_BOT_TOKEN']);
            } else {
                $GLOBALS[$name]['TELEGRAM_BOT_TOKEN'] = $value;
            }
        }
        $saved[2] === false ? putenv('TELEGRAM_BOT_TOKEN') : putenv('TELEGRAM_BOT_TOKEN='.$saved[2]);
    }
}

it('warms the TelegramClient (and the billing registry that needs it) only when a bot token is set', function (?string $token, bool $warmed): void {
    $warm = warmListFor($token);

    // The registry builds the Stars provider, which takes the client: it follows the client.
    expect(in_array(TelegramClient::class, $warm, true))->toBe($warmed)
        ->and(in_array(BillingProviderRegistry::class, $warm, true))->toBe($warmed);
})->with([
    'token' => ['123456:ABC', true],
    'empty' => ['', false],
    'whitespace' => ['   ', false],
    'unset' => [null, false],
]);

it('keeps the other services warm without a bot token', function (): void {
    expect(warmListFor(''))->toContain(Gate::class, JwtService::class);
});

it('boots the worker and serves requests with an empty bot token', function (): void {
    // The TestCase replaces the client with a fake; a production worker has the real one (AppServiceProvider), whose Api throws on an empty token.
    (new AppServiceProvider($this->app))->register();
    $this->app->forgetInstance(IrazasyedTelegramClient::class);
    $this->app->forgetInstance(BillingProviderRegistry::class);
    config(['telegram.bot_token' => '', 'octane.warm' => warmListFor('')]);

    $octane = OctaneHarness::boot($this->app);

    expect($octane->handle(OctaneHarness::request('GET', '/up'))->getStatusCode())->toBe(200)
        ->and($octane->handle(OctaneHarness::request('GET', '/api/twa/me'))->getStatusCode())->toBe(401);
});
