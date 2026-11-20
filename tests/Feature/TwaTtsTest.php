<?php

declare(strict_types=1);

use App\Domain\Twa\Services\JwtService;
use App\Models\Learner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

/*
 * docs/26 §9.9: `GET /api/twa/tts` is gone. It sat behind TwaAuth (the header `Authorization` only) and the Mini App called it
 * through `new Audio(url)`, which cannot send a header - it answered 401 to every real call. A word plays its `audio_url` (the
 * pre-generated file) or the phone voice; nothing is synthesized, and nothing is looked up by a text, on behalf of a learner.
 */

uses(RefreshDatabase::class);

it('has no TTS endpoint any more: neither the route nor the controller', function (): void {
    expect(Route::has('twa.tts'))->toBeFalse()
        ->and(file_exists(app_path('Http/Controllers/Api/Twa/TtsController.php')))->toBeFalse()
        ->and(collect(Route::getRoutes()->getRoutes())->map(fn ($route): string => $route->uri())->all())->not->toContain('api/twa/tts');
});

it('answers 404 to an authenticated learner and calls nothing', function (): void {
    Http::preventStrayRequests();
    Http::fake();
    $token = app(JwtService::class)->issue(Learner::factory()->create()->id)['token'];

    $this->withHeaders(['Authorization' => 'Bearer '.$token])->getJson('/api/twa/tts?word=resilient')->assertNotFound();
    Http::assertNothingSent();
});

it('drops the live synthesis settings', function (): void {
    expect(config('twa.tts'))->not->toHaveKeys(['live_timeout', 'live_connect_timeout', 'live_concurrency'])
        ->and(file_exists(app_path('Domain/Twa/Services/TtsFallbackService.php')))->toBeFalse()
        ->and((string) file_get_contents(base_path('.env.example')))->not->toContain('TTS_LIVE_');
});
