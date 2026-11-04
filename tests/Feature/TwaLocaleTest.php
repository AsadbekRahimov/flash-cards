<?php

declare(strict_types=1);

use App\Domain\Twa\Services\JwtService;
use App\Models\Learner;
use Illuminate\Foundation\Events\LocaleUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/** PATCH /me/settings with a non-strict integer: 422 "daily_goal must be an integer". */
function patchInvalidGoal(Learner $learner): TestResponse
{
    $token = app(JwtService::class)->issue($learner->id)['token'];

    return test()->withToken($token)->patchJson('/api/twa/me/settings', ['daily_goal' => '20'])->assertStatus(422);
}

it('answers with the validation texts of the learner\'s interface language', function (string $locale, string $expected): void {
    $learner = Learner::factory()->create(['interface_locale' => $locale]);

    expect(patchInvalidGoal($learner)->json('errors.daily_goal.0'))->toBe($expected);
})->with([
    'ru' => ['ru', 'Поле дневная цель должно быть целым числом.'],
    'uz' => ['uz', 'kunlik maqsad maydoni butun son bo\'lishi kerak.'],
]);

it('does not depend on APP_LOCALE or on the Accept-Language of an authenticated request', function (): void {
    app()->setLocale('ru');
    $learner = Learner::factory()->create(['interface_locale' => 'uz']);
    $token = app(JwtService::class)->issue($learner->id)['token'];

    $this->withToken($token)->withHeader('Accept-Language', 'ru')
        ->patchJson('/api/twa/me/settings', ['daily_goal' => '20'])
        ->assertStatus(422)
        ->assertJsonPath('errors.daily_goal.0', 'kunlik maqsad maydoni butun son bo\'lishi kerak.');
});

it('falls back to the language of the Telegram profile, then to the default, for an unknown interface locale', function (string $language, string $expected): void {
    $learner = Learner::factory()->create(['interface_locale' => 'xx', 'language_code' => $language]);

    expect(patchInvalidGoal($learner)->json('errors.daily_goal.0'))->toBe($expected);
})->with([
    'telegram uz' => ['uz-UZ', 'kunlik maqsad maydoni butun son bo\'lishi kerak.'],
    'telegram en -> default ru' => ['en', 'Поле дневная цель должно быть целым числом.'],
]);

it('negotiates the language of POST /auth validation errors from Accept-Language', function (string $header, string $expected): void {
    app()->setLocale('ru');

    $this->postJson('/api/twa/auth', [], ['Accept-Language' => $header])
        ->assertStatus(422)
        ->assertJsonPath('errors.init_data.0', $expected);
})->with([
    'uz' => ['uz', 'initData maydoni to\'ldirilishi shart.'],
    'uz with region and weights' => ['de;q=0.5, uz-UZ;q=0.9', 'initData maydoni to\'ldirilishi shart.'],
    'ru' => ['ru', 'Поле initData обязательно для заполнения.'],
    'unknown -> default ru' => ['fr-FR,en;q=0.8', 'Поле initData обязательно для заполнения.'],
]);

it('uses the default locale for /auth without Accept-Language', function (): void {
    app()->setLocale('uz');

    $this->postJson('/api/twa/auth', [])
        ->assertStatus(422)
        ->assertJsonPath('errors.init_data.0', 'Поле initData обязательно для заполнения.');
});

it('does not re-dispatch LocaleUpdated when the locale is already current, and switches ru -> uz -> ru in one process', function (): void {
    $dispatched = 0;
    Event::listen(LocaleUpdated::class, function () use (&$dispatched): void {
        $dispatched++;
    });
    app()->setLocale('ru');
    $dispatched = 0;

    $ru = Learner::factory()->create(['interface_locale' => 'ru']);
    $uz = Learner::factory()->create(['interface_locale' => 'uz']);

    expect(patchInvalidGoal($ru)->json('errors.daily_goal.0'))->toBe('Поле дневная цель должно быть целым числом.')
        ->and($dispatched)->toBe(0);

    expect(patchInvalidGoal($uz)->json('errors.daily_goal.0'))->toBe('kunlik maqsad maydoni butun son bo\'lishi kerak.')
        ->and($dispatched)->toBe(1)
        ->and(Carbon::now()->locale)->toBe('uz');

    expect(patchInvalidGoal($ru)->json('errors.daily_goal.0'))->toBe('Поле дневная цель должно быть целым числом.')
        ->and($dispatched)->toBe(2)
        ->and(Carbon::now()->locale)->toBe('ru');
});
