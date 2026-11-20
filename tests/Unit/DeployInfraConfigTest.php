<?php

declare(strict_types=1);

use App\Http\Middleware\RandomizeResponseLength;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

/*
 * The deploy / infrastructure files of the audit 2026-11-10 package (docs/22 §8, docs/24 §10): Coolify networks, the Telegram step of the
 * deploy, pinned CI actions, the nginx limits. Docker is not needed - the files are read, parsed and, for the shell ones, run.
 */

/** @return string the file under the project root */
function infraFile(string $path): string
{
    $contents = file_get_contents(base_path($path));
    assert(is_string($contents));

    return $contents;
}

/** @return array<string, mixed> */
function coolifyCompose(): array
{
    /** @var array<string, mixed> $compose */
    $compose = Yaml::parse(infraFile('docker-compose.coolify.yml'));

    return $compose;
}

/** @return list<string> network keys of a service */
function serviceNetworks(array $service): array
{
    $networks = $service['networks'] ?? [];

    return array_map('strval', array_is_list($networks) ? $networks : array_keys($networks));
}

it('puts only lf-nginx on the proxy network and keeps every service on the private bridge (Coolify, M3)', function (): void {
    $compose = coolifyCompose();
    $services = $compose['services'];

    foreach ($services as $name => $service) {
        $networks = serviceNetworks($service);

        expect([$name => $networks])->toHaveKey($name)
            ->and($networks)->toContain('lf-internal')
            ->and(array_key_exists('ports', $service))->toBeFalse();

        if ($name === 'lf-nginx') {
            expect($networks)->toContain('proxy')->and($networks)->not->toContain('infra');
        } else {
            expect($networks)->toContain('infra')->and($networks)->not->toContain('proxy');
        }
    }

    // a plain bridge: `internal: true` would cut the outbound route (Telegram API, payments, TTS, Sentry)
    $private = $compose['networks']['lf-internal'];
    expect($private['driver'])->toBe('bridge')
        ->and($private['internal'] ?? false)->toBeFalse()
        ->and($private['ipam']['config'][0]['subnet'])->toContain('LF_INTERNAL_SUBNET')
        ->and($compose['networks']['proxy']['external'])->toBeTrue()
        ->and($compose['networks']['infra']['external'])->toBeTrue();
});

it('trusts the private subnet only in lf-api and reaches php-fpm and Octane by private aliases', function (): void {
    $compose = coolifyCompose();
    $api = $compose['services']['lf-api'];

    expect($api['environment']['TRUSTED_PROXIES'])->toContain('LF_INTERNAL_SUBNET')
        ->and($api['environment']['TRUSTED_PROXIES'])->not->toContain('10.0.0.0/8')
        ->and($api['environment']['TRUSTED_PROXIES'])->not->toContain('172.16.0.0/12')
        ->and($api['environment']['TRUSTED_PROXIES'])->not->toContain('192.168.0.0/16')
        ->and($compose['services']['lf-app']['environment']['TRUSTED_PROXIES'])->toBe('')
        ->and($api['networks']['lf-internal']['aliases'])->toBe(['lf-api-private'])
        ->and($compose['services']['lf-app']['networks']['lf-internal']['aliases'])->toBe(['lf-app-private']);

    $nginx = infraFile('docker/nginx/coolify.conf');
    expect($nginx)->toContain('server lf-app-private:9000 resolve;')
        ->and($nginx)->toContain('server lf-api-private:8000 resolve;')
        ->and($nginx)->not->toMatch('/server lf-(app|api):/');
});

it('takes the proxies of nginx from the generated snippet, not from hard-coded private ranges', function (): void {
    $nginx = infraFile('docker/nginx/coolify.conf');

    expect($nginx)->toContain('include /etc/nginx/snippets-generated/realip-proxy.conf;')
        ->and($nginx)->not->toContain('set_real_ip_from 10.0.0.0/8;')
        ->and(coolifyCompose()['services']['lf-nginx']['environment'])->toHaveKey('TRUSTED_PROXY_CIDRS');
});

it('writes the proxy list of nginx from TRUSTED_PROXY_CIDRS and refuses a catch-all', function (): void {
    $dir = sys_get_temp_dir().'/lf-nginx-ep-'.bin2hex(random_bytes(4));
    mkdir($dir);

    $run = fn (string $cidrs) => Process::env([
        'NGINX_ENTRYPOINT_GENERATE_ONLY' => '1',
        'TRUSTED_PROXY_CIDRS' => $cidrs,
        'PROXY_REAL_IP_SNIPPET' => "{$dir}/proxy.conf",
        'CF_REAL_IP_CONF' => "{$dir}/cf.conf",
        'CF_ONLY_SNIPPET' => "{$dir}/only.conf",
    ])->run(['sh', base_path('docker/nginx-entrypoint.sh')]);

    try {
        $ok = $run('10.1.2.0/24, 172.16.0.5');
        expect($ok->successful())->toBeTrue()
            ->and((string) file_get_contents("{$dir}/proxy.conf"))->toContain("set_real_ip_from 10.1.2.0/24;\n", "set_real_ip_from 172.16.0.5;\n");

        foreach (['0.0.0.0/0', '10.0.0.0/7', '::/0', 'not-an-address'] as $bad) {
            $result = $run($bad);
            expect($result->failed())->toBeTrue()
                ->and($result->errorOutput())->toContain('TRUSTED_PROXY_CIDRS');
        }
    } finally {
        array_map('unlink', glob("{$dir}/*") ?: []);
        rmdir($dir);
    }
});

it('writes the origin of AUDIO_PUBLIC_URL for the CSP of the Mini App, empty when unset, and refuses anything but https://host', function (): void {
    $dir = sys_get_temp_dir().'/lf-nginx-audio-'.bin2hex(random_bytes(4));
    mkdir($dir);

    $run = fn (?string $url) => Process::env([
        'NGINX_ENTRYPOINT_GENERATE_ONLY' => '1',
        'PROXY_REAL_IP_SNIPPET' => "{$dir}/proxy.conf",
        'CF_REAL_IP_CONF' => "{$dir}/cf.conf",
        'CF_ONLY_SNIPPET' => "{$dir}/only.conf",
        'AUDIO_ORIGIN_CONF' => "{$dir}/audio.conf",
        ...($url === null ? [] : ['AUDIO_PUBLIC_URL' => $url]),
    ])->run(['sh', base_path('docker/nginx-entrypoint.sh')]);
    $origin = function () use ($dir): ?string {
        preg_match('/^map \$host \$lexiflow_audio_origin \{\n    default "([^"]*)";\n\}$/m', (string) @file_get_contents("{$dir}/audio.conf"), $m);

        return $m[1] ?? null;
    };

    try {
        foreach ([
            'https://audio.example.uz' => 'https://audio.example.uz',
            'https://audio.example.uz/' => 'https://audio.example.uz',
            'https://cdn.example.uz:8443/words/v1' => 'https://cdn.example.uz:8443',
            '' => '',
        ] as $url => $expected) {
            expect($run((string) $url)->successful())->toBeTrue()
                ->and($origin())->toBe($expected);
        }
        expect($run(null)->successful())->toBeTrue()->and($origin())->toBe('');

        foreach (['http://audio.example.uz', 'audio.example.uz', 'https://a.uz";add_header X 1', 'https://a.uz x', "https://a.uz\nhttps://b.uz", 'https://-a.uz'] as $bad) {
            @unlink("{$dir}/audio.conf");
            $result = $run($bad);
            expect($result->failed())->toBeTrue()
                ->and($result->errorOutput())->toContain('AUDIO_PUBLIC_URL')
                ->and(file_exists("{$dir}/audio.conf"))->toBeFalse();
        }
    } finally {
        array_map('unlink', glob("{$dir}/*") ?: []);
        rmdir($dir);
    }
});

it('lets the audio domain into media-src and connect-src of every CSP of the Mini App in the image configs, and passes it to nginx', function (): void {
    foreach (['docker/nginx/prod.conf', 'docker/nginx/coolify.conf'] as $file) {
        preg_match_all('/add_header Content-Security-Policy "([^"]*)"/', infraFile($file), $m);

        expect($m[1])->toHaveCount(3);
        foreach ($m[1] as $csp) {
            expect($csp)->toContain("media-src 'self' blob: data: \$lexiflow_audio_origin;")
                ->and($csp)->toContain("connect-src 'self' \$lexiflow_audio_origin;");
        }
    }

    // The development stack mounts default.conf as it is (no entrypoint): its audio is on the same origin.
    expect(infraFile('docker/nginx/default.conf'))->not->toContain('lexiflow_audio_origin');

    /** @var array{services: array<string, array{environment?: array<string, mixed>}>} $prod */
    $prod = Yaml::parse(infraFile('docker-compose.prod.yml'));
    expect($prod['services']['nginx']['environment']['AUDIO_PUBLIC_URL'])->toBe('${AUDIO_PUBLIC_URL:-}')
        ->and(coolifyCompose()['services']['lf-nginx']['environment']['AUDIO_PUBLIC_URL'])->toBe('${AUDIO_PUBLIC_URL:-}');
});

it('limits the public paths in both nginx configs and raises worker_connections in the image', function (): void {
    foreach (['docker/nginx/prod.conf', 'docker/nginx/coolify.conf'] as $file) {
        $conf = infraFile($file);

        expect($conf)->toContain('limit_req_zone $lexiflow_limit_key zone=lexiflow_public:10m rate=30r/s;')
            ->and($conf)->toContain('limit_req zone=lexiflow_public burst=60 nodelay;')
            ->and($conf)->toContain('limit_req_status 429;')
            // not counted: the probes, ACME, Telegram's own addresses
            ->and($conf)->toContain('up|health|\.well-known')
            ->and($conf)->toContain('149.154.160.0/20');
    }

    expect(infraFile('Dockerfile'))->toContain('worker_connections[[:space:]]+4096;');
});

// --- Compression of the panels and BREACH (16.11.2026): nginx compresses /admin, /org, /2fa, /livewire-*; the noise
// --- against BREACH comes from App\Http\Middleware\RandomizeResponseLength, whose path list must equal the nginx location.

const NGINX_CONFIGS = ['docker/nginx/prod.conf', 'docker/nginx/coolify.conf', 'docker/nginx/default.conf'];

/** The direct-fastcgi `location ~ …` block of the panels: the regex and the body, or null when the file has none. */
function panelLocation(string $conf): ?array
{
    if (preg_match('/^    location ~ \^\/\(\?:(?<alternatives>[^\n]*?)\) \{\n(?<body>.*?)^    \}/ms', $conf, $m) !== 1) {
        return null;
    }

    return ['alternatives' => $m['alternatives'], 'body' => $m['body']];
}

it('hands the padded paths of RandomizeResponseLength, the webhook and the payment return to PHP-FPM directly, compressed', function (): void {
    // The two non-panel paths share the block for the access-log map; they are not padded (no secret in the body).
    $expected = implode('|', [...RandomizeResponseLength::nginxLocationAlternatives(), 'telegram/webhook/', 'billing/return/?$']);

    foreach (NGINX_CONFIGS as $file) {
        $location = panelLocation(infraFile($file));

        expect($location)->not->toBeNull("{$file}: no direct-fastcgi location of the panels")
            ->and($location['alternatives'])->toBe($expected)
            ->and($location['body'])->not->toContain('gzip off')
            ->and($location['body'])->toContain('fastcgi_param SCRIPT_FILENAME $document_root/index.php;')
            ->and($location['body'])->toContain('fastcgi_param SCRIPT_NAME /index.php;')
            ->and($location['body'])->toContain('fastcgi_param DOCUMENT_URI /index.php;');
    }
});

it('keeps gzip on for the panel location, text/html included, and off for the mp3 audio only', function (): void {
    foreach (NGINX_CONFIGS as $file) {
        $conf = infraFile($file);

        expect($conf)->toContain("\n    gzip on;\n")
            ->and($conf)->toContain('application/json')
            ->and($conf)->toContain('application/javascript')
            ->and(substr_count($conf, 'gzip off;'))->toBe(1)
            ->and($conf)->toMatch('/location \^~ \/storage\/audio\/ \{\n(?:.*\n)*?\s+gzip off;/');
    }
});

it('caches the versioned Filament static files for a year, served by nginx, with the server-level headers repeated', function (): void {
    foreach (NGINX_CONFIGS as $file) {
        $conf = infraFile($file);

        expect(preg_match('/^    location ~ \^\/\(\?:css\|js\|fonts\)\/filament\/ \{\n(?<body>.*?)^    \}/ms', $conf, $m))->toBe(1, "{$file}: no Filament static location");

        expect($m['body'])->toContain('try_files $uri =404;')
            ->and($m['body'])->toContain('add_header Cache-Control "public, max-age=31536000, immutable";')
            ->and($m['body'])->not->toContain('fastcgi_pass');

        if ($file !== 'docker/nginx/default.conf') {
            expect($m['body'])->toContain('add_header Strict-Transport-Security "max-age=15552000; includeSubDomains" always;')
                ->and($m['body'])->toContain('add_header X-Content-Type-Options "nosniff" always;');
        }

        // Filament assets are padded by nobody and must not be in the padded paths.
        foreach (['css/filament/filament/app.css', 'js/filament/filament/app.js', 'fonts/filament/filament/inter.woff2'] as $asset) {
            expect(RandomizeResponseLength::appliesTo($asset))->toBeFalse();
        }
    }
});

it('runs the Telegram setup after the migrations, before the stack is switched, never on a rollback, never fatally', function (): void {
    $script = infraFile('deploy.sh');
    $migrate = strpos($script, 'php artisan migrate --force');
    $telegram = strpos($script, 'sh docker/telegram-setup.sh');

    expect($migrate)->not->toBeFalse()
        ->and($telegram)->toBeGreaterThan($migrate)
        ->and($telegram)->toBeLessThan(strpos($script, 'up -d --remove-orphans'))
        ->and($script)->toMatch('/if \[ "\$ROLLBACK" != "1" \]; then\s+echo "==> Setting up the Telegram bot[^\n]*\n\s+\$COMPOSE run --rm app sh docker\/telegram-setup\.sh/')
        ->and($script)->toContain('|| echo "WARNING: docker/telegram-setup.sh failed');

    $command = implode(' ', coolifyCompose()['services']['lf-migrate']['command']);
    expect(strpos($command, 'sh docker/telegram-setup.sh'))->toBeGreaterThan(strpos($command, 'courses:recount-words'))
        ->and($command)->toContain("|| echo 'WARNING telegram setup failed'");
});

it('warns before the build about production settings left at their development defaults', function (): void {
    $script = infraFile('deploy.sh');
    $warn = strpos($script, 'Checking the production settings');

    expect($warn)->not->toBeFalse()
        ->and($warn)->toBeLessThan(strpos($script, 'Building immutable application'))
        ->and($script)->toContain('TELEGRAM_IP_ALLOWLIST_ENABLED', 'PAYME_ALLOW_TEST_KEY', 'APP_DEBUG', 'BACKUP_ARCHIVE_PASSWORD', 'BACKUP_DISKS', 'SENTRY_TRACES_SAMPLE_RATE');

    $syntax = Process::run(['bash', '-n', base_path('deploy.sh')]);
    expect($syntax->successful())->toBeTrue();
});

it('skips the Telegram setup with a warning when the bot token is not set, and exits 0', function (): void {
    $result = Process::env(['TELEGRAM_BOT_TOKEN' => ''])->run(['sh', base_path('docker/telegram-setup.sh')]);

    expect($result->successful())->toBeTrue()
        ->and($result->errorOutput())->toContain('WARNING: TELEGRAM_BOT_TOKEN is not set');
});

it('names the missing webhook variables and still exits 0 when the Telegram API commands fail', function (): void {
    $bin = sys_get_temp_dir().'/lf-fake-php-'.bin2hex(random_bytes(4));
    mkdir($bin);
    file_put_contents("{$bin}/php", "#!/bin/sh\necho \"php \$*\"\nexit 1\n");
    chmod("{$bin}/php", 0o755);

    try {
        $result = Process::env([
            'PATH' => $bin.':'.getenv('PATH'),
            'TELEGRAM_BOT_TOKEN' => 'token',
            'TELEGRAM_WEBHOOK_URL' => 'https://example.test',
            'TELEGRAM_URL_SECRET' => 'secret',
            'TELEGRAM_HEADER_SECRET' => '',
        ])->run(['sh', base_path('docker/telegram-setup.sh')]);

        expect($result->successful())->toBeTrue()
            ->and($result->output())->toContain('php artisan telegram:setup-bot')
            ->and($result->errorOutput())->toContain('WARNING: telegram:setup-bot failed')
            ->and($result->errorOutput())->toContain('not set: TELEGRAM_HEADER_SECRET')
            ->and($result->output())->not->toContain('telegram:set-webhook');
    } finally {
        unlink("{$bin}/php");
        rmdir($bin);
    }
});

it('pins every third-party action of the workflows to a full commit SHA with the version in a comment', function (): void {
    $files = glob(base_path('.github/workflows/*.yml')) ?: [];
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        preg_match_all('/^\s*(?:-\s+)?uses:\s*(\S+)(.*)$/m', (string) file_get_contents($file), $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            if (str_starts_with($match[1], './')) {
                continue;
            }
            expect($match[1])->toMatch('/^[\w.-]+\/[\w.-]+@[0-9a-f]{40}$/')
                ->and(trim($match[2]))->toMatch('/^# v\d/');
        }
    }
});

it('gives the app-api pool of docker-compose.prod.yml a static pm and the pool file its pm from the environment', function (): void {
    expect(infraFile('docker/php/zz-lexiflow-pool.conf'))->toContain('pm = ${PHP_FPM_PM}')
        ->and(infraFile('Dockerfile'))->toContain('ENV PHP_FPM_PM=dynamic');

    /** @var array{services: array<string, array{environment?: array<string, mixed>}>} $prod */
    $prod = Yaml::parse(infraFile('docker-compose.prod.yml'));

    expect($prod['services']['app-api']['environment']['PHP_FPM_PM'])->toBe('${PHP_FPM_API_PM:-static}')
        ->and($prod['services']['app']['environment'] ?? [])->not->toHaveKey('PHP_FPM_PM');
});
