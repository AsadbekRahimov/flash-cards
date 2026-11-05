<?php

declare(strict_types=1);

use App\Support\TrustedProxies;
use Illuminate\Http\Request;

it('parses TRUSTED_PROXIES as a comma-separated list, trimmed, without empty entries', function (mixed $value, array $expected): void {
    expect(TrustedProxies::fromEnv($value))->toBe($expected);
})->with([
    'unset' => [null, []],
    'empty' => ['', []],
    'blanks only' => [' , ,', []],
    'one subnet' => ['172.28.0.0/16', ['172.28.0.0/16']],
    'several, with blanks' => [' 172.28.0.0/16 , 10.0.0.1,, fd00::/8 ', ['172.28.0.0/16', '10.0.0.1', 'fd00::/8']],
]);

it('refuses a catch-all: every caller would become a trusted proxy', function (string $value): void {
    expect(fn () => TrustedProxies::fromEnv($value))->toThrow(InvalidArgumentException::class, 'every caller');
})->with([
    'star' => '*',
    'double star' => '**',
    'star among proxies' => '172.28.0.0/16,*',
    'REMOTE_ADDR keyword' => 'REMOTE_ADDR',
    'remote_addr keyword, lower case' => 'remote_addr',
    'IPv4 catch-all network' => '0.0.0.0/0',
    'IPv6 catch-all network' => '::/0',
]);

it('refuses a value that is not a string (env() turns "true"/"false" into booleans)', function (): void {
    expect(fn () => TrustedProxies::fromEnv(true))->toThrow(InvalidArgumentException::class);
});

it('is what config/trustedproxy.php exposes, and trusts the four X-Forwarded-* headers nginx rewrites - not the prefix', function (): void {
    expect(config('trustedproxy.proxies'))->toBe([])
        ->and(TrustedProxies::HEADERS & Request::HEADER_X_FORWARDED_FOR)->not->toBe(0)
        ->and(TrustedProxies::HEADERS & Request::HEADER_X_FORWARDED_HOST)->not->toBe(0)
        ->and(TrustedProxies::HEADERS & Request::HEADER_X_FORWARDED_PORT)->not->toBe(0)
        ->and(TrustedProxies::HEADERS & Request::HEADER_X_FORWARDED_PROTO)->not->toBe(0)
        ->and(TrustedProxies::HEADERS & Request::HEADER_X_FORWARDED_PREFIX)->toBe(0)
        ->and(TrustedProxies::HEADERS & Request::HEADER_FORWARDED)->toBe(0);
});
