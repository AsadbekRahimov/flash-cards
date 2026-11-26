<?php

declare(strict_types=1);

/*
 * The product name lives in one config key per meaning (docs/36 §5б): `app.brand`, `app.brand_pro`, `app.brand_logo`.
 * The technical APP_NAME stays as it is (it derives REDIS_PREFIX, the backup folder): the brand is a key of its own.
 */

/**
 * config/app.php as it reads with the given brand variables unset, whatever the local `.env` says.
 *
 * @return array<string, mixed>
 */
function appConfigWithoutBrandEnv(): array
{
    $keys = ['APP_BRAND', 'APP_BRAND_PRO', 'APP_BRAND_LOGO'];
    $saved = [];

    foreach ($keys as $key) {
        $saved[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }

    try {
        /** @var array<string, mixed> */
        return require config_path('app.php');
    } finally {
        foreach ($saved as $key => [$put, $env, $server]) {
            if ($put !== false) {
                putenv("{$key}={$put}");
            }
            if ($env !== null) {
                $_ENV[$key] = $env;
            }
            if ($server !== null) {
                $_SERVER[$key] = $server;
            }
        }
    }
}

it('names the product Muhrla, the paid plan Muhrla Pro and has no logo by default', function (): void {
    $config = appConfigWithoutBrandEnv();

    expect($config['brand'])->toBe('Muhrla')
        ->and($config['brand_pro'])->toBe('Muhrla Pro')
        ->and($config['brand_logo'])->toBeNull();
});

it('keeps the brand apart from the technical APP_NAME', function (): void {
    $source = (string) file_get_contents(config_path('app.php'));

    expect($source)->toContain("'name' => env('APP_NAME', 'Laravel')")
        ->and($source)->toContain("'brand' => env('APP_BRAND', 'Muhrla')");
});

it('lists the brand variables in .env.example, with APP_NAME left as it was', function (): void {
    $example = (string) file_get_contents(base_path('.env.example'));

    expect($example)->toMatch('/^APP_BRAND=Muhrla$/m')
        ->and($example)->toMatch('/^APP_BRAND_PRO="Muhrla Pro"$/m')
        ->and($example)->toMatch('/^APP_BRAND_LOGO=$/m')
        ->and($example)->toMatch('/^VITE_BRAND=/m')
        ->and($example)->toMatch('/^APP_NAME=LexiFlow$/m');
});
