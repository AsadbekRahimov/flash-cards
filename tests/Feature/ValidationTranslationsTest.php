<?php

declare(strict_types=1);

use App\Domain\Twa\Services\JwtService;
use App\Models\Learner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;

/*
 * Laravel ships validation messages only in English and the project has no lang/en: without
 * lang/{ru,uz}/validation.php a 422 carries the raw key "validation.integer".
 */

uses(RefreshDatabase::class);

/** @return list<string> */
function validationPlaceholders(string $text): array
{
    preg_match_all('/:[a-z_]+/', $text, $m);
    $unique = array_values(array_unique($m[0]));
    sort($unique);

    return $unique;
}

it('translates the PATCH /me/settings 422 in the app locale instead of showing the raw key', function (string $locale, string $expected): void {
    app()->setLocale($locale);
    $learner = Learner::factory()->create();
    $token = app(JwtService::class)->issue($learner->id)['token'];

    $response = $this->withToken($token)->patchJson('/api/twa/me/settings', ['daily_goal' => '20'])
        ->assertStatus(422);

    $message = $response->json('errors.daily_goal.0');
    expect($message)->toBe($expected)
        ->and($message)->not->toContain('validation.');
})->with([
    'ru' => ['ru', 'Поле дневная цель должно быть целым числом.'],
    'uz' => ['uz', 'kunlik maqsad maydoni butun son bo\'lishi kerak.'],
]);

it('has every key of the framework\'s English validation file, with the same placeholders', function (string $locale): void {
    $english = Arr::dot(require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php'));
    $translated = Arr::dot(require lang_path("{$locale}/validation.php"));

    // `attributes` is project-specific (field names of our API), `custom` is a template.
    $keys = array_filter(array_keys($english), fn (string $key): bool => ! str_starts_with($key, 'custom.') && $key !== 'attributes');

    expect(array_values(array_diff($keys, array_keys($translated))))->toBe([]);

    foreach ($keys as $key) {
        expect(validationPlaceholders((string) $translated[$key]))
            ->toBe(validationPlaceholders((string) $english[$key]), "{$locale} {$key}");
    }
})->with(['ru', 'uz']);
