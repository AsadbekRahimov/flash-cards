<?php

declare(strict_types=1);

use App\Support\TrustedHosts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

/*
 * Host pinning (bootstrap/app.php `trustHosts`): in production a request whose Host is not the host of APP_URL (or one
 * of APP_TRUSTED_HOSTS, or the loopback of the container healthcheck) is a 400, so a forged Host cannot poison the
 * links built by url() - the /org password reset mail. The framework does not run it in local and testing.
 */

afterEach(function (): void {
    Request::setTrustedHosts([]);
});

it('collects the host of APP_URL, the extra hosts and the loopback names', function (): void {
    Config::set('app.url', 'https://App.Example.test:8443/base');
    Config::set('app.trusted_hosts', ['admin.example.test']);

    expect(TrustedHosts::list())->toBe(['^localhost$', '^127\.0\.0\.1$', '^app\.example\.test$', '^admin\.example\.test$']);
});

it('parses APP_TRUSTED_HOSTS, dropping blanks', function (): void {
    expect(TrustedHosts::fromEnv(' a.test, ,b.test,'))->toBe(['a.test', 'b.test'])
        ->and(TrustedHosts::fromEnv(null))->toBe([])
        ->and(TrustedHosts::fromEnv(''))->toBe([]);
});

it('refuses a foreign Host in production and serves the APP_URL host, the loopback and the extra hosts', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    Config::set('app.url', 'https://app.example.test');
    Config::set('app.trusted_hosts', ['alias.example.test']);

    $this->get('http://evil.test/up')->assertStatus(400);
    $this->get('http://evil.test/org/password-reset/request')->assertStatus(400);
    $this->get('http://app.example.test/up')->assertOk();
    $this->get('http://alias.example.test/up')->assertOk();
    $this->get('http://127.0.0.1:8000/up')->assertOk();
    $this->get('http://sub.app.example.test/up')->assertStatus(400);
});

it('does not pin the Host in testing', function (): void {
    Config::set('app.url', 'https://app.example.test');

    $this->get('http://evil.test/up')->assertOk();
});
