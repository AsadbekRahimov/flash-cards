<?php

declare(strict_types=1);

use App\Domain\Engagement\Services\AchievementCardRenderer;
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

it('lets the audio domain into media-src - and only there, not connect-src (docs/26 §9.9) - of every CSP of the Mini App in the image configs, and passes it to nginx', function (): void {
    foreach (['docker/nginx/prod.conf', 'docker/nginx/coolify.conf'] as $file) {
        // The CSPs of the Mini App (/twa/); the maintenance page has its own, which loads nothing.
        preg_match_all('/add_header Content-Security-Policy "([^"]*https:\/\/telegram\.org[^"]*)"/', infraFile($file), $m);

        expect($m[1])->toHaveCount(3);
        foreach ($m[1] as $csp) {
            // The Mini App plays the words of R2 in an <audio> and never fetches them: no connect-src, no CORS on the bucket.
            expect($csp)->toContain("media-src 'self' blob: data: \$lexiflow_audio_origin;")
                ->and($csp)->toContain("connect-src 'self';")
                ->and(substr_count($csp, 'lexiflow_audio_origin'))->toBe(1);
        }
    }

    // The development stack mounts default.conf as it is (no entrypoint): its audio is on the same origin.
    expect(infraFile('docker/nginx/default.conf'))->not->toContain('lexiflow_audio_origin');

    /** @var array{services: array<string, array{environment?: array<string, mixed>}>} $prod */
    $prod = Yaml::parse(infraFile('docker-compose.prod.yml'));
    expect($prod['services']['nginx']['environment']['AUDIO_PUBLIC_URL'])->toBe('${AUDIO_PUBLIC_URL:-}')
        ->and(coolifyCompose()['services']['lf-nginx']['environment']['AUDIO_PUBLIC_URL'])->toBe('${AUDIO_PUBLIC_URL:-}');
});

it('mounts the key file of Google TTS read-only at one path, optionally, only where a synthesis or the key check runs', function (): void {
    $mount = '${GOOGLE_TTS_KEY_FILE:-/dev/null}:/run/secrets/google-tts.json:ro';

    /** @var array{services: array<string, array{volumes?: list<string>}>} $prod */
    $prod = Yaml::parse(infraFile('docker-compose.prod.yml'));
    $coolify = coolifyCompose();
    /** @var array<string, array{volumes?: list<string>}> $coolifyServices */
    $coolifyServices = $coolify['services'];

    foreach ([[$prod['services'], ['app', 'queue-low', 'scheduler'], ['app-api', 'queue-high', 'queue-default', 'nginx']],
        [$coolifyServices, ['lf-app', 'lf-queue-low', 'lf-scheduler'], ['lf-api', 'lf-queue-high', 'lf-queue-default', 'lf-migrate', 'lf-nginx']]] as [$services, $with, $without]) {
        foreach ($with as $name) {
            // The volumes of the application stay next to the key (a `volumes:` of a Coolify service replaces the merged one).
            expect($services[$name]['volumes'] ?? [])->toContain($mount)
                ->toContain('app_storage:/var/www/html/storage')
                ->toContain('app_audio:/var/www/html/storage/app/public/audio');
        }
        foreach ($without as $name) {
            expect($services[$name]['volumes'] ?? [])->not->toContain($mount);
        }
    }

    // .env.example points the driver at that path in Docker and names the file of the host.
    expect(infraFile('.env.example'))->toContain('/run/secrets/google-tts.json')->toContain("GOOGLE_TTS_KEY_FILE=\n");
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
            ->and(substr_count($conf, 'gzip off;'))->toBe(2)
            ->and($conf)->toMatch('/location \^~ \/storage\/audio\/ \{\n(?:.*\n)*?\s+gzip off;/')
            ->and($conf)->toMatch('/location \^~ \/audio\/ \{\n(?:.*\n)*?\s+gzip off;/');
    }
});

// --- The word audio on the main domain (docs/31 §5д): `/audio/<hash>.mp3` next to the old `/storage/audio/`.

/** The body of a top-level `location ^~ {prefix} {` block of an nginx config, or null when the file has none. */
function prefixLocation(string $conf, string $prefix): ?string
{
    $quoted = preg_quote($prefix, '/');

    return preg_match('/^    location \^~ '.$quoted.' \{\n(?<body>.*?)^    \}/ms', $conf, $m) === 1 ? $m['body'] : null;
}

it('serves the word audio at /audio/ from the folder of /storage/audio/, GET/HEAD and plain mp3 names only, with the same headers', function (): void {
    foreach (NGINX_CONFIGS as $file) {
        $conf = infraFile($file);
        $audio = prefixLocation($conf, '/audio/');
        $old = prefixLocation($conf, '/storage/audio/');

        expect($audio)->not->toBeNull("{$file}: no /audio/ location")
            // The old addresses stay: the open sessions of the Mini App hold them.
            ->and($old)->not->toBeNull("{$file}: /storage/audio/ must stay for the old addresses");

        expect($audio)->toContain('alias /var/www/html/storage/app/public/audio/;')
            ->and($audio)->toContain("limit_except GET {\n            deny all;\n        }")
            ->and($audio)->toContain('if ($uri !~ "^/audio/[A-Za-z0-9_-]+\.mp3$") {'."\n            return 404;\n        }")
            ->and($audio)->toContain('gzip off;')
            ->and($audio)->toContain('add_header Cache-Control "public, max-age=2592000, immutable";')
            ->and($audio)->toContain('add_header X-Content-Type-Options "nosniff" always;')
            // The rest of the old location's headers (HSTS in the images) is repeated: add_header drops the server-level ones.
            ->and(substr_count((string) $old, 'add_header'))->toBe(substr_count((string) $audio, 'add_header') - ($file === 'docker/nginx/default.conf' ? 1 : 0));

        if ($file !== 'docker/nginx/default.conf') {
            expect($audio)->toContain('add_header Strict-Transport-Security "max-age=15552000; includeSubDomains" always;');
        }

        // The same folder as the old address, which the volume `app_audio` mounts in the images.
        expect($old)->toContain('alias /var/www/html/storage/app/public/audio/;');
    }
});

it('lets only a plain <name>.mp3 through the /audio/ pattern: no dotfile, no subdirectory, no other type', function (): void {
    preg_match('/if \(\$uri !~ "(?<pattern>[^"]+)"\)/', (string) prefixLocation(infraFile('docker/nginx/prod.conf'), '/audio/'), $m);
    $pattern = '#'.$m['pattern'].'#';

    foreach (['/audio/0a1b2c3d4e5f60718293a4b5c6d7e8f901234567.mp3', '/audio/abc-def_1.mp3'] as $ok) {
        expect(preg_match($pattern, $ok))->toBe(1, $ok);
    }
    foreach (['/audio/.tmp/x.mp3', '/audio/.x.mp3', '/audio/sub/x.mp3', '/audio/x.txt', '/audio/x.mp3.php', '/audio/', '/audio/x.mp3/', '/audio/../x.mp3', '/audio/x.MP3x'] as $bad) {
        expect(preg_match($pattern, $bad))->toBe(0, $bad);
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

// --- The share cards (docs/36 §2б): `/storage/share-cards/<key>-<ru|uz>-<hash>.jpg` for Telegram to download, nothing else of storage.

it('serves the share cards at /storage/share-cards/: GET/HEAD and the names of the renderer only, cached for a year', function (): void {
    foreach (NGINX_CONFIGS as $file) {
        $conf = infraFile($file);
        $cards = prefixLocation($conf, '/storage/share-cards/');

        expect($cards)->not->toBeNull("{$file}: no /storage/share-cards/ location");
        expect($cards)->toContain('alias /var/www/html/storage/app/public/share-cards/;')
            ->and($cards)->toContain("limit_except GET {\n            deny all;\n        }")
            ->and($cards)->toContain('if ($uri !~ "^/storage/share-cards/[a-z0-9_]{1,40}-(ru|uz)-[0-9a-f]{20}\.jpg$") {'."\n            return 404;\n        }")
            ->and($cards)->toContain('add_header Cache-Control "public, max-age=31536000, immutable";')
            ->and($cards)->toContain('add_header X-Content-Type-Options "nosniff" always;')
            ->and($cards)->not->toContain('fastcgi_pass');

        if ($file !== 'docker/nginx/default.conf') {
            expect($cards)->toContain('add_header Strict-Transport-Security "max-age=15552000; includeSubDomains" always;');
        }

        // Nothing else of the public disk: the old PNG cards (/storage/achievements/) and the rest of /storage/ fall to `location /`
        // (no `storage:link` in the images) - 404.
        expect(prefixLocation($conf, '/storage/achievements/'))->toBeNull()
            ->and(prefixLocation($conf, '/storage/'))->toBeNull()
            ->and($conf)->not->toMatch('/location [^{]*\/storage\/(?!audio\/|share-cards\/)/');
    }
});

it('lets through the /storage/share-cards/ pattern exactly the names the renderer writes', function (): void {
    preg_match('/if \(\$uri !~ "(?<pattern>[^"]+)"\)/', (string) prefixLocation(infraFile('docker/nginx/prod.conf'), '/storage/share-cards/'), $m);
    $pattern = '#'.$m['pattern'].'#';

    foreach (['streak_7-ru-0123456789abcdef0123.jpg', 'boss_24-uz-ffffffffffffffffffff.jpg', 'invite-ru-0a1b2c3d4e5f60718293.jpg'] as $name) {
        expect(preg_match($pattern, "/storage/share-cards/{$name}"))->toBe(1, $name)
            ->and(preg_match(AchievementCardRenderer::FILE_PATTERN, $name))->toBe(1, $name);
    }
    foreach ([
        '/storage/share-cards/streak_7-ru-0123456789abcdef0123.jpg.a1b2c3.tmp', // a render in progress
        '/storage/share-cards/.streak_7-ru-0123456789abcdef0123.jpg',
        '/storage/share-cards/sub/streak_7-ru-0123456789abcdef0123.jpg',
        '/storage/share-cards/streak_7-en-0123456789abcdef0123.jpg',
        '/storage/share-cards/streak_7-ru-0123456789abcdef012.jpg',
        '/storage/share-cards/streak_7-ru-0123456789ABCDEF0123.jpg',
        '/storage/share-cards/streak_7-ru-0123456789abcdef0123.png',
        '/storage/share-cards/streak_7-ru-0123456789abcdef0123.jpg.php',
        '/storage/share-cards/../share-cards/streak_7-ru-0123456789abcdef0123.jpg',
        '/storage/share-cards/',
    ] as $bad) {
        expect(preg_match($pattern, $bad))->toBe(0, $bad);
    }
});

it('gives the share cards their own volume, shared read-only with nginx, wherever the word audio is mounted', function (): void {
    /** @var array{services: array<string, array{volumes?: list<string>}>, volumes: array<string, mixed>} $prod */
    $prod = Yaml::parse(infraFile('docker-compose.prod.yml'));
    $coolify = coolifyCompose();
    /** @var array<string, array{volumes?: list<string>}> $coolifyServices */
    $coolifyServices = $coolify['services'];

    foreach ([[$prod['services'], $prod['volumes'], 'nginx'], [$coolifyServices, (array) $coolify['volumes'], 'lf-nginx']] as [$services, $volumes, $nginx]) {
        expect($volumes)->toHaveKey('app_share_cards');
        foreach ($services as $name => $service) {
            $mounts = $service['volumes'] ?? [];
            if ($name === $nginx) {
                expect($mounts)->toContain('app_share_cards:/var/www/html/storage/app/public/share-cards:ro')
                    ->not->toContain('app_storage:/var/www/html/storage');
            } elseif (in_array('app_audio:/var/www/html/storage/app/public/audio', $mounts, true)) {
                expect(in_array('app_share_cards:/var/www/html/storage/app/public/share-cards', $mounts, true))->toBeTrue("{$name}: no share cards volume");
            }
        }
    }

    // The image owns the mount point, so the fresh named volume is writable by the application user.
    expect(infraFile('Dockerfile'))->toContain("        storage/app/public/share-cards \\\n");
});

it('builds GD with JPEG and FreeType in every image: the share cards are JPEG', function (): void {
    foreach (['Dockerfile', 'Dockerfile.dev'] as $file) {
        $docker = infraFile($file);

        expect(substr_count($docker, 'docker-php-ext-configure gd'))->toBe(substr_count($docker, 'docker-php-ext-configure gd --with-freetype --with-jpeg'), $file)
            ->and(substr_count($docker, 'libjpeg-turbo-dev'))->toBe(substr_count($docker, 'docker-php-ext-configure gd'), $file);
    }
    expect(substr_count(infraFile('Dockerfile'), 'gd_info()["JPEG Support"]'))->toBe(2);
});

// --- PHP unreachable (28.11.2026): nginx stays up without its upstreams and answers itself - JSON for the API, the plain status
// --- for the webhook, the payments and the probes, the branded page for the rest; the application's own 5xx pass unchanged.

/** The body of a top-level `location {spec} {` block (a named or an exact location), or null when the file has none. */
function exactLocation(string $conf, string $spec): ?string
{
    return preg_match('/^    location '.preg_quote($spec, '/').' \{\n(?<body>.*?)^    \}/ms', $conf, $m) === 1 ? $m['body'] : null;
}

it('starts lf-nginx without waiting for the application, with upstreams it re-resolves at run time', function (): void {
    $nginx = coolifyCompose()['services']['lf-nginx'];

    expect($nginx)->not->toHaveKey('depends_on');

    foreach (['docker/nginx/prod.conf', 'docker/nginx/coolify.conf'] as $file) {
        $conf = infraFile($file);
        preg_match_all('/^\s*server \S+.*;$/m', $conf, $servers);

        // Every upstream server is resolved by nginx at run time: an absent name does not stop nginx from starting.
        expect($conf)->toContain('resolver 127.0.0.11 valid=5s ipv6=off;')
            ->and($servers[0])->toHaveCount(3);
        foreach ($servers[0] as $line) {
            expect($line)->toMatch('/^\s*server [a-z-]+:\d+ resolve;$/');
        }
    }
});

it('answers nginx\'s own 502 and 504 itself and never intercepts a response of the application', function (): void {
    foreach (['docker/nginx/prod.conf', 'docker/nginx/coolify.conf'] as $file) {
        $conf = infraFile($file);

        expect($conf)->toContain("    error_page 502 = \$lexiflow_down_502;\n    error_page 504 = \$lexiflow_down_504;\n    recursive_error_pages on;\n")
            // The application's 503 (maintenance mode, the envelope of the API) and a failing /health/ready pass as they are.
            ->and($conf)->not->toContain('intercept_errors on')
            ->and(preg_match_all('/^\s*error_page /m', $conf))->toBe(4, $file);

        $api = prefixLocation($conf, '/api/');
        expect($api)->toContain('error_page 502 504 = @lexiflow_down_api;');

        // The plain status with a body: a response of its own, so recursive_error_pages cannot loop on it.
        expect(exactLocation($conf, '@lexiflow_down_502'))->toContain('return 502 "Bad Gateway\n";')
            ->and(exactLocation($conf, '@lexiflow_down_504'))->toContain('return 504 "Gateway Timeout\n";');

        $page = (string) exactLocation($conf, '@lexiflow_down_page');
        expect($page)->toContain('error_page 503 /maintenance.html;')
            ->and($page)->toContain('return 503;');

        $file404 = (string) exactLocation($conf, '= /maintenance.html');
        expect($file404)->toContain('internal;')
            ->and($file404)->toContain('add_header Retry-After 30 always;')
            ->and($file404)->toContain('add_header Cache-Control "no-store" always;')
            ->and($file404)->toContain("add_header Content-Security-Policy \"default-src 'none'; style-src 'unsafe-inline';")
            ->and($file404)->toContain('add_header Strict-Transport-Security "max-age=15552000; includeSubDomains" always;')
            ->and($file404)->toContain('add_header X-Content-Type-Options "nosniff" always;');
    }

    // The development config talks to live containers only: nothing of this there.
    expect(infraFile('docker/nginx/default.conf'))->not->toContain('lexiflow_down');
});

it('answers /api/* with the error envelope of the API, 503 and Retry-After, when app-api is unreachable', function (): void {
    foreach (['docker/nginx/prod.conf', 'docker/nginx/coolify.conf'] as $file) {
        $api = (string) exactLocation(infraFile($file), '@lexiflow_down_api');

        expect($api)->toContain('default_type application/json;')
            ->and($api)->toContain('add_header Retry-After 30 always;')
            ->and($api)->toContain('add_header Cache-Control "no-store" always;')
            ->and(preg_match("/return 503 '(?<body>[^']+)';/", $api, $m))->toBe(1);

        // The shape of ApiErrorResource (docs/04 A.6): the Mini App reads error.code of a 503 (ApiClientError kind `server`).
        expect(json_decode($m['body'], true, 512, JSON_THROW_ON_ERROR))->toBe(['error' => [
            'code' => 'maintenance',
            'message' => 'The service is being updated. Try again in a minute.',
        ]]);
    }
});

it('keeps the plain status for the webhook, the payments and the probes, and gives every other page the branded one', function (): void {
    foreach (['docker/nginx/prod.conf', 'docker/nginx/coolify.conf'] as $file) {
        $conf = infraFile($file);

        expect(preg_match('/^map \$request_uri \$lexiflow_upstream_plain \{\n    default 0;\n    ~(?<pattern>\S+) 1;\n\}$/m', $conf, $m))->toBe(1, $file);
        foreach (['502', '504'] as $code) {
            expect($conf)->toContain("map \$lexiflow_upstream_plain \$lexiflow_down_{$code} {\n    1 @lexiflow_down_{$code};\n    default @lexiflow_down_page;\n}");
        }
        $pattern = '#'.$m['pattern'].'#';

        foreach (['/telegram/webhook/abc', '/billing/webhook/payme', '/billing/webhook/click/prepare', '/billing/return', '/billing/return?x=1', '/up', '/up?probe=1', '/health/ready', '/health'] as $plain) {
            expect(preg_match($pattern, $plain))->toBe(1, $plain);
        }
        foreach (['/', '/?utm=1', '/admin', '/admin/login', '/org', '/2fa/challenge', '/livewire-abc/update', '/privacy', '/upgrade', '/healthy', '/api', '/telegram'] as $page) {
            expect(preg_match($pattern, $page))->toBe(0, $page);
        }
    }
});

it('ships a self-contained branded maintenance page: inline CSS, no script, no request to anywhere, three languages', function (): void {
    $html = infraFile('public/maintenance.html');

    expect($html)->toContain('<meta charset="utf-8">')
        ->and($html)->toContain('<meta http-equiv="refresh" content="20">')
        ->and($html)->toContain('Muhrla')
        ->and($html)->toContain('lang="uz"')->and($html)->toContain('lang="ru"')->and($html)->toContain('lang="en"')
        ->and($html)->toContain('#F6F2EA')->and($html)->toContain('#121117')->and($html)->toContain('#E4572E')
        ->and($html)->toContain('prefers-color-scheme: dark')
        // The CSP of its location is `default-src 'none'; style-src 'unsafe-inline'`: nothing that would load anything.
        ->and($html)->not->toMatch('/<script|<link|<img|<iframe|@import|url\(|\bsrc=|\bstyle="/i')
        ->and($html)->not->toMatch('#https?://#i');
});
