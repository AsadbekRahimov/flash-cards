<?php

declare(strict_types=1);

use App\Http\Middleware\RandomizeResponseLength;
use App\Http\Middleware\SecurityHeaders;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;
use Livewire\Features\SupportDisablingBackButtonCache\DisableBackButtonCacheMiddleware;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/*
 * BREACH noise on the compressed panel responses (App\Http\Middleware\RandomizeResponseLength): a random-length random
 * HTML comment / JSON key on /admin, /org, /2fa and /livewire-*, nothing anywhere else. The probe routes below live
 * under those prefixes but outside Filament's and Livewire's own routes.
 */

const NOISE = '[A-Za-z0-9+\/]{32,160}';

const PROBE_HTML = '<!DOCTYPE html><html><head><title>p</title></head><body><p>hello</p></body></html>';

function padTestRoutes(): void
{
    Route::get('/admin/_probe', fn () => response(PROBE_HTML, 200, ['Content-Type' => 'text/html; charset=UTF-8']));
    Route::get('/2fa/_probe/length', fn () => response(PROBE_HTML, 200, [
        'Content-Type' => 'text/html; charset=UTF-8',
        'Content-Length' => (string) strlen(PROBE_HTML),
    ]));
    Route::get('/2fa/_probe/empty', fn () => response('', 200, ['Content-Type' => 'text/html; charset=UTF-8']));
    Route::get('/2fa/_probe/no-content', fn () => response()->noContent());
    Route::get('/2fa/_probe/redirect', fn () => redirect('/2fa/_probe/empty'));
    Route::get('/2fa/_probe/text', fn () => response("plain\n", 200, ['Content-Type' => 'text/plain']));
    Route::get('/2fa/_probe/encoded', fn () => response(PROBE_HTML, 200, ['Content-Type' => 'text/html', 'Content-Encoding' => 'identity']));
    Route::get('/livewire-probe/update', fn () => response()->json([
        'components' => [['snapshot' => '{"a":{"b":{}}}', 'effects' => ['html' => '<div>{</div>', 'ratio' => 1.0]]],
        'assets' => [],
        'empty' => (object) [],
    ]));
    Route::get('/livewire-probe/empty-object', fn () => response('{}', 200, ['Content-Type' => 'application/json']));
    Route::get('/livewire-probe/array', fn () => response()->json([1, 2, 3]));
    Route::get('/livewire-probe/file', fn () => response()->file(base_path('public/robots.txt'), ['Content-Type' => 'text/html']));
    Route::get('/livewire-probe/stream', fn () => response()->stream(function (): void {
        echo PROBE_HTML;
    }, 200, ['Content-Type' => 'text/html']));
    Route::get('/api/_probe', fn () => response()->json(['components' => []]));
    Route::get('/administration/_probe', fn () => response(PROBE_HTML, 200, ['Content-Type' => 'text/html']));
    // The same handler with and without a session, outside every panel path.
    Route::middleware('web')->get('/_probe/session', fn () => response(PROBE_HTML, 200, ['Content-Type' => 'text/html']));
    Route::get('/_probe/stateless', fn () => response(PROBE_HTML, 200, ['Content-Type' => 'text/html']));
}

function twoFactorAdminForPadding(): User
{
    test()->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create([
        'is_active' => true,
        'two_factor_secret' => app(Google2FA::class)->generateSecretKey(),
        'two_factor_recovery_codes' => ['r1'],
        'two_factor_confirmed_at' => now(),
    ]);
    $admin->assignRole('admin');

    return $admin;
}

uses(RefreshDatabase::class);

beforeEach(function (): void {
    padTestRoutes();
});

it('is the innermost global middleware, after the security headers', function (): void {
    /** @var list<string> $global */
    $global = app(Kernel::class)->getGlobalMiddleware();

    $index = array_search(RandomizeResponseLength::class, $global, true);

    // Whatever runs inside it (and so sees the body before it) must only set headers: Livewire pushes its back-button
    // cache headers at boot; a body-changing middleware here would be padded wrongly or not at all.
    expect($index)->toBeGreaterThan(array_search(SecurityHeaders::class, $global, true))
        ->and(array_slice($global, (int) $index + 1))->toBe([DisableBackButtonCacheMiddleware::class]);
});

it('covers both Filament panels, the Livewire update endpoint and the 2FA pages, and nothing else', function (): void {
    foreach (Filament::getPanels() as $panel) {
        expect(RandomizeResponseLength::appliesTo($panel->getPath()))->toBeTrue()
            ->and(RandomizeResponseLength::appliesTo($panel->getPath().'/login'))->toBeTrue();
    }

    expect(RandomizeResponseLength::appliesTo(ltrim(app(HandleRequests::class)->getUpdateUri(), '/')))->toBeTrue()
        ->and(RandomizeResponseLength::appliesTo(ltrim(parse_url(route('2fa.challenge'), PHP_URL_PATH) ?: '', '/')))->toBeTrue()
        ->and(RandomizeResponseLength::appliesTo('livewire-555f5e94/livewire.min.js'))->toBeTrue();

    foreach (['/', '', 'ru', 'offer', 'api/twa/auth', 'api/admin', 'administration', 'organization/x', '2fa-x', 'telegram/webhook/x', 'billing/return', 'up', 'storage/audio/a.mp3', 'css/filament/filament/app.css'] as $path) {
        expect(RandomizeResponseLength::appliesTo($path))->toBeFalse("'{$path}' must not be padded");
    }
});

it('draws the noise from the CSPRNG alphabet within the declared length range', function (): void {
    $lengths = [];
    foreach (range(1, 300) as $i) {
        $noise = RandomizeResponseLength::noise();
        expect($noise)->toMatch('/^'.NOISE.'$/');
        $lengths[strlen($noise)] = true;
    }

    expect(min(array_keys($lengths)))->toBeGreaterThanOrEqual(RandomizeResponseLength::MIN_LENGTH)
        ->and(max(array_keys($lengths)))->toBeLessThanOrEqual(RandomizeResponseLength::MAX_LENGTH)
        ->and(count($lengths))->toBeGreaterThan(40);
});

it('appends a random comment after the HTML document and leaves the document itself untouched', function (): void {
    $body = $this->get('/admin/_probe')->assertOk()->getContent();

    expect($body)->toMatch('/^'.preg_quote(PROBE_HTML, '/').'<!--'.NOISE.'-->$/');

    libxml_use_internal_errors(true);
    $document = new DOMDocument;
    expect($document->loadHTML($body))->toBeTrue()
        ->and($document->getElementsByTagName('p')->item(0)?->textContent)->toBe('hello')
        ->and($document->documentElement?->textContent)->toBe('phello');
    libxml_clear_errors();
});

it('gives two identical requests bodies of different length', function (): void {
    $lengths = [];
    foreach (range(1, 8) as $i) {
        $lengths[] = strlen((string) $this->get('/admin/_probe')->getContent());
    }

    // 8 draws from 129 equally likely lengths: all equal with probability (1/129)^7.
    expect(count(array_unique($lengths)))->toBeGreaterThan(1);
});

it('pads the HTML of a real panel page', function (): void {
    expect((string) $this->get('/admin/login')->assertOk()->getContent())->toMatch('/<\/html>\s*<!--'.NOISE.'-->$/');
    expect((string) $this->get('/org/login')->assertOk()->getContent())->toMatch('/<\/html>\s*<!--'.NOISE.'-->$/');
});

it('adds one top-level key to a JSON object and keeps the original text byte for byte', function (): void {
    $response = $this->get('/livewire-probe/update')->assertOk();
    $body = (string) $response->getContent();
    $original = (string) response()->json([
        'components' => [['snapshot' => '{"a":{"b":{}}}', 'effects' => ['html' => '<div>{</div>', 'ratio' => 1.0]]],
        'assets' => [],
        'empty' => (object) [],
    ])->getContent();

    expect($body)->toMatch('/^'.preg_quote(substr($original, 0, -1), '/').',"_pad":"'.NOISE.'"}$/');

    $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    expect($decoded)->toHaveKeys(['components', 'assets', 'empty', '_pad'])
        ->and($decoded['components'][0]['snapshot'])->toBe('{"a":{"b":{}}}')
        ->and($decoded['components'][0]['effects']['html'])->toBe('<div>{</div>')
        ->and($decoded['empty'])->toBe([])
        ->and(json_decode($body)->empty)->toBeInstanceOf(stdClass::class);
});

it('pads an empty JSON object without a leading comma', function (): void {
    $body = (string) $this->get('/livewire-probe/empty-object')->assertOk()->getContent();

    expect($body)->toMatch('/^\{"_pad":"'.NOISE.'"\}$/')
        ->and(json_decode($body, true, 512, JSON_THROW_ON_ERROR))->toHaveKey('_pad');
});

it('leaves a JSON array alone', function (): void {
    expect($this->get('/livewire-probe/array')->assertOk()->getContent())->toBe('[1,2,3]');
});

it('pads the JSON of a real Livewire update and the client still finds its keys', function (): void {
    $admin = twoFactorAdminForPadding();

    $html = (string) $this->actingAs($admin)
        ->withSession(['2fa.web.passed_at' => time(), '2fa.web.user_id' => $admin->id])
        ->get('/admin/users')
        ->assertOk()
        ->getContent();

    expect($html)->toMatch('/<!--'.NOISE.'-->$/');

    preg_match('/wire:snapshot="([^"]+)"/', $html, $m);
    expect($m)->toHaveKey(1);

    $response = $this->postJson(route('default-livewire.update'), [
        '_token' => csrf_token(),
        'components' => [[
            'snapshot' => html_entity_decode($m[1]),
            'updates' => [],
            'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
        ]],
    ], ['X-Livewire' => 'true'])->assertOk();

    $body = (string) $response->getContent();
    $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

    expect($body)->toMatch('/,"_pad":"'.NOISE.'"\}$/')
        ->and($json)->toHaveKeys(['components', 'assets', '_pad'])
        ->and($json['components'][0])->toHaveKeys(['snapshot', 'effects'])
        ->and(json_decode($json['components'][0]['snapshot'], true, 512, JSON_THROW_ON_ERROR))->toHaveKey('memo');
});

// --- The decision must not depend on how the attacker spelled the URL (review finding, 16.11.2026) -----------------

it('normalises the path the way the router matches it', function (): void {
    expect(RandomizeResponseLength::normalizePath('/%61dmin/courses'))->toBe('admin/courses')
        ->and(RandomizeResponseLength::normalizePath('/admin%2Fcourses'))->toBe('admin/courses')
        ->and(RandomizeResponseLength::normalizePath('/2f%61/challenge'))->toBe('2fa/challenge')
        ->and(RandomizeResponseLength::normalizePath('//admin//courses/'))->toBe('admin/courses')
        ->and(RandomizeResponseLength::normalizePath('/livewire-5%35/update'))->toBe('livewire-55/update')
        ->and(RandomizeResponseLength::normalizePath('/'))->toBe('')
        ->and(RandomizeResponseLength::normalizePath('/%61dministration'))->toBe('administration');

    foreach (['/%61dmin/courses', '/admin%2Fcourses', '/2f%61/challenge', '//admin//courses', '/%6Civewire-55/update'] as $raw) {
        expect(RandomizeResponseLength::appliesTo(RandomizeResponseLength::normalizePath($raw)))->toBeTrue($raw);
    }
    // Neither the router nor this check resolves `..` or case: such URLs reach no session route (404) and carry no secret.
    foreach (['/%61dministration', '/ADMIN/x', '/x/../admin/x'] as $raw) {
        expect(RandomizeResponseLength::appliesTo(RandomizeResponseLength::normalizePath($raw)))->toBeFalse($raw);
    }
});

it('pads a panel path however it is percent-encoded', function (string $url): void {
    $response = $this->get($url)->assertOk();

    expect((string) $response->getContent())->toMatch('/^'.preg_quote(PROBE_HTML, '/').'<!--'.NOISE.'-->$/');
})->with(['/%61dmin/_probe', '/admin%2F_probe', '/%32fa/_probe/length', '/2f%61/_probe/length', '/2fa%2F_probe%2Flength']);

it('pads the encoded JSON path too', function (): void {
    expect((string) $this->get('/%6Civewire-probe/empty-object')->assertOk()->getContent())->toMatch('/^\{"_pad":"'.NOISE.'"\}$/');
});

it('pads the real 2FA challenge reached through an encoded path, and keeps the view in `original`', function (): void {
    $admin = twoFactorAdminForPadding();

    foreach (['/2fa/challenge?guard=web', '/2f%61/challenge?guard=web', '/%32fa/challenge?guard=web'] as $url) {
        $response = $this->actingAs($admin)->get($url)->assertOk();

        expect((string) $response->getContent())->toMatch('/<\/html>\s*<!--'.NOISE.'-->$/')
            ->and((string) $response->getContent())->toContain('_token');

        $response->assertViewIs('2fa.challenge')->assertViewHas('guard', 'web');
        expect($response->baseResponse->original)->toBeInstanceOf(View::class);
    }
});

it('pads every response of a request with a session, whatever the path, and not the same handler without one', function (): void {
    expect((string) $this->get('/_probe/session')->assertOk()->getContent())->toMatch('/^'.preg_quote(PROBE_HTML, '/').'<!--'.NOISE.'-->$/');
    expect($this->get('/_probe/stateless')->assertOk()->getContent())->toBe(PROBE_HTML);
});

it('leaves the stateless public responses alone: the payment return page, the Telegram webhook, the readiness probe', function (): void {
    $return = $this->get('/billing/return?order=nope&lang=ru')->assertOk();
    expect((string) $return->headers->get('Content-Type'))->toContain('text/html')
        ->and((string) $return->getContent())->not->toMatch('/<!--'.NOISE.'-->\s*$/')
        ->and($return->headers->has('Set-Cookie'))->toBeFalse();

    $webhook = $this->postJson('/telegram/webhook/not-the-secret-0123456789', ['update_id' => 1]);
    expect($webhook->getStatusCode())->toBeIn([401, 403, 404])
        ->and((string) $webhook->getContent())->not->toMatch('/<!--'.NOISE.'-->\s*$/')
        ->and((string) $webhook->getContent())->not->toContain('"_pad"');

    $ready = $this->get('/health/ready');
    expect((string) $ready->getContent())->not->toContain('"_pad"')
        ->and((string) $ready->getContent())->not->toMatch('/<!--'.NOISE.'-->\s*$/');
});

it('keeps `original` of a JSON response', function (): void {
    $response = $this->get('/livewire-probe/empty-object')->assertOk();
    expect($response->baseResponse->original)->toBe('{}');

    $json = $this->get('/livewire-probe/array')->assertOk();
    expect($json->baseResponse->original)->toBe([1, 2, 3]);

    $padded = $this->get('/livewire-probe/update')->assertOk();
    expect($padded->baseResponse->original)->toBeArray()->toHaveKeys(['components', 'assets', 'empty'])->not->toHaveKey('_pad');
});

it('corrects a Content-Length a response had set', function (): void {
    $response = $this->get('/2fa/_probe/length')->assertOk();

    expect((int) $response->headers->get('Content-Length'))->toBe(strlen((string) $response->getContent()))
        ->and(strlen((string) $response->getContent()))->toBeGreaterThan(strlen(PROBE_HTML));
});

it('does not touch an empty body, 204, a redirect, plain text, an encoded body or HEAD', function (): void {
    expect($this->get('/2fa/_probe/empty')->assertOk()->getContent())->toBe('');
    expect($this->get('/2fa/_probe/no-content')->assertNoContent()->getContent())->toBe('');
    expect((string) $this->get('/2fa/_probe/redirect')->assertRedirect('/2fa/_probe/empty')->getContent())->not->toMatch('/<!--'.NOISE.'-->/');
    expect($this->get('/2fa/_probe/text')->assertOk()->getContent())->toBe("plain\n");
    expect($this->get('/2fa/_probe/encoded')->assertOk()->getContent())->toBe(PROBE_HTML);
    expect((string) $this->head('/admin/_probe')->assertOk()->getContent())->toBe('');
});

it('does not touch a file or a streamed response', function (): void {
    $file = $this->get('/livewire-probe/file')->assertOk();
    expect($file->baseResponse)->toBeInstanceOf(BinaryFileResponse::class)
        ->and($file->baseResponse->getContent())->toBeFalse()
        ->and((int) $file->headers->get('Content-Length'))->toBe(filesize(base_path('public/robots.txt')));

    expect($this->get('/livewire-probe/stream')->assertOk()->streamedContent())->toBe(PROBE_HTML);
});

it('does not touch the API, the public site or paths that merely start with a panel name', function (): void {
    expect($this->get('/api/_probe')->assertOk()->getContent())->toBe('{"components":[]}');
    expect($this->get('/administration/_probe')->assertOk()->getContent())->toBe(PROBE_HTML);

    $public = $this->get('/ru')->assertOk();
    expect((string) $public->headers->get('Cache-Control'))->toContain('public')
        ->and((string) $public->getContent())->not->toMatch('/<!--'.NOISE.'-->\s*$/');
});
