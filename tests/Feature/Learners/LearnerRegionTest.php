<?php

declare(strict_types=1);

/*
 * The region of a learner (docs/35 §2.2–2.3, R1, R2, R4): the list of regions, LearnerRegionService (the first choice, 30 days between
 * choices, the reset that does not get around them, the undo of 10 minutes, the card of the Rating tab) and the Mini App API
 * (`PATCH /me/settings` `region_code`, `POST /me/settings/region/revert`, `POST /me/region-prompt/dismiss`, the `settings` block of
 * `GET /me`). The bot is in OnboardingRegionBotTest.
 */

use App\Domain\Learners\Exceptions\InvalidSettingException;
use App\Domain\Learners\Exceptions\RegionChangeTooSoonException;
use App\Domain\Learners\Exceptions\RegionRevertUnavailableException;
use App\Domain\Learners\Services\LearnerRegionService;
use App\Domain\Learners\Support\Regions;
use App\Domain\Twa\Services\JwtService;
use App\Models\Learner;
use App\Models\ProductEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2026-11-25 10:00:00'));

afterEach(fn () => CarbonImmutable::setTestNow());

function rgnLearner(array $attributes = []): Learner
{
    return Learner::factory()->onboarded()->create(['timezone' => 'Asia/Tashkent', ...$attributes]);
}

function rgnService(): LearnerRegionService
{
    return app(LearnerRegionService::class);
}

/** @param  array<string, mixed>  $body */
function rgnPatch(object $test, Learner $learner, array $body): TestResponse
{
    return $test->withHeaders(['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']])
        ->patchJson('/api/twa/me/settings', $body);
}

function rgnPost(object $test, Learner $learner, string $path): TestResponse
{
    return $test->withHeaders(['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']])->postJson($path);
}

/** @return list<array<string, mixed>> the props of every `region.set` */
function rgnEvents(): array
{
    return ProductEvent::query()->where('name', 'region.set')->orderBy('id')->pluck('props')->map(fn ($p): array => (array) $p)->all();
}

// --- R1: the list ---------------------------------------------------------------------------------------------

it('knows 15 regions: the 14 units of Uzbekistan by ISO 3166-2:UZ and other, each with a Russian and an Uzbek name', function (): void {
    expect(Regions::codes())->toHaveCount(15)
        ->and(Regions::uzbekistan())->toHaveCount(14)
        ->and(Regions::codes())->toContain('UZ-TK', 'UZ-TO', 'UZ-QR', 'UZ-XO', 'other')
        ->and(Regions::uzbekistan())->not->toContain('other');

    foreach (Regions::codes() as $code) {
        expect(strlen($code))->toBeLessThanOrEqual(Regions::MAX_LENGTH);
        foreach (['ru', 'uz'] as $locale) {
            $name = Regions::name($code, $locale);
            expect($name)->not->toBe($code)->not->toBe('regions.'.$code)->not->toBe('');
        }
        expect(Regions::name($code, 'ru'))->not->toBe(Regions::name($code, 'uz'))
            ->and(Regions::englishName($code))->not->toBe($code);
    }

    expect(Regions::name('UZ-TK', 'ru'))->toBe('город Ташкент')
        ->and(Regions::name('UZ-TK', 'uz'))->toBe('Toshkent shahri')
        ->and(Regions::name('other', 'uz'))->toBe('Boshqa davlat')
        ->and(Regions::isValid('UZ-XX'))->toBeFalse()
        ->and(Regions::isValid('uz-tk'))->toBeFalse()
        ->and(Regions::isValid(null))->toBeFalse()
        ->and(Regions::name('UZ-XX', 'ru'))->toBe('UZ-XX');
});

it('orders the 14 regions per language with Tashkent City first and every region once', function (string $locale, string $second): void {
    $ordered = Regions::ordered($locale);

    expect($ordered)->toHaveCount(14)
        ->and($ordered[0])->toBe('UZ-TK')
        ->and($ordered[1])->toBe($second)
        ->and(array_unique($ordered))->toHaveCount(14);

    // Alphabetical after the city: the names in the order of the list are sorted (Uzbek apostrophes aside).
    $names = array_map(fn (string $code): string => Regions::name($code, $locale), array_slice($ordered, 1));
    $sorted = $names;
    usort($sorted, fn (string $a, string $b): int => strcmp(str_replace("'", '', mb_strtolower($a)), str_replace("'", '', mb_strtolower($b))));
    expect($names)->toBe($sorted);
})->with([['ru', 'UZ-AN'], ['uz', 'UZ-AN']]);

it('adds the region columns and the index to learners and takes them away again', function (): void {
    expect(Schema::hasColumns('learners', ['region_code', 'region_changed_at', 'pro_emoji']))->toBeTrue()
        ->and(collect(Schema::getIndexes('learners'))->pluck('name'))->toContain('learners_region_code_idx');

    $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_11_25_200002_add_pro_emoji_to_learners_table.php'])->assertSuccessful();
    $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_11_25_200001_add_region_to_learners_table.php'])->assertSuccessful();
    expect(Schema::hasColumn('learners', 'region_code'))->toBeFalse()
        ->and(Schema::hasColumn('learners', 'region_changed_at'))->toBeFalse()
        ->and(Schema::hasColumn('learners', 'pro_emoji'))->toBeFalse();

    $this->artisan('migrate', ['--path' => 'database/migrations/2026_11_25_200001_add_region_to_learners_table.php'])->assertSuccessful();
    $this->artisan('migrate', ['--path' => 'database/migrations/2026_11_25_200002_add_pro_emoji_to_learners_table.php'])->assertSuccessful();
    expect(Schema::hasColumns('learners', ['region_code', 'region_changed_at', 'pro_emoji']))->toBeTrue();
});

// --- R2: the rules --------------------------------------------------------------------------------------------

it('accepts the first choice at once and records region.set', function (): void {
    $learner = rgnLearner();

    rgnService()->set($learner, 'UZ-SA', LearnerRegionService::VIA_SETTINGS);

    expect($learner->region_code)->toBe('UZ-SA')
        ->and($learner->region_changed_at?->toIso8601String())->toBe('2026-11-25T10:00:00+00:00')
        ->and(rgnEvents())->toEqual([['from' => null, 'to' => 'UZ-SA', 'via' => 'settings']]);
});

it('refuses a change after 29 days and accepts it after 30', function (): void {
    $learner = rgnLearner();
    rgnService()->set($learner, 'UZ-SA', LearnerRegionService::VIA_SETTINGS);

    CarbonImmutable::setTestNow('2026-12-24 10:00:00'); // 29 days
    try {
        rgnService()->set($learner, 'UZ-TK', LearnerRegionService::VIA_SETTINGS);
        $this->fail('The change was accepted.');
    } catch (RegionChangeTooSoonException $e) {
        expect($e->errorCode)->toBe('region_change_too_soon')
            ->and($e->details)->toBe(['available_at' => '2026-12-25T10:00:00+00:00']);
    }
    expect($learner->fresh()->region_code)->toBe('UZ-SA');

    CarbonImmutable::setTestNow('2026-12-25 10:00:00'); // 30 days
    rgnService()->set($learner, 'UZ-TK', LearnerRegionService::VIA_SETTINGS);

    expect($learner->fresh()->region_code)->toBe('UZ-TK')
        ->and(rgnEvents())->toHaveCount(2);
});

it('allows the reset at any time but does not let it get around the 30 days', function (): void {
    $learner = rgnLearner();
    rgnService()->set($learner, 'UZ-SA', LearnerRegionService::VIA_SETTINGS);

    CarbonImmutable::setTestNow('2026-11-26 10:00:00');
    rgnService()->set($learner, null, LearnerRegionService::VIA_SETTINGS);
    expect($learner->fresh()->region_code)->toBeNull()
        // The reset does not move the moment of the last choice.
        ->and($learner->fresh()->region_changed_at?->toIso8601String())->toBe('2026-11-25T10:00:00+00:00');

    // Past the undo window: the next choice after the reset waits for the 30 days from the last choice.
    CarbonImmutable::setTestNow('2026-12-01 10:00:00');
    expect(fn () => rgnService()->set($learner, 'UZ-TK', LearnerRegionService::VIA_SETTINGS))
        ->toThrow(RegionChangeTooSoonException::class);
    expect($learner->fresh()->region_code)->toBeNull();

    CarbonImmutable::setTestNow('2026-12-25 10:00:00');
    rgnService()->set($learner, 'UZ-TK', LearnerRegionService::VIA_SETTINGS);
    expect($learner->fresh()->region_code)->toBe('UZ-TK');
});

it('changes nothing for the same value and refuses an unknown code', function (): void {
    $learner = rgnLearner(['region_code' => 'UZ-TK', 'region_changed_at' => '2026-11-20 10:00:00']);

    rgnService()->set($learner, 'UZ-TK', LearnerRegionService::VIA_SETTINGS);
    expect(rgnEvents())->toBe([]);

    foreach (['UZ-XX', 'uz-tk', 'Tashkent', ''] as $code) {
        expect(fn () => rgnService()->set($learner, $code, LearnerRegionService::VIA_SETTINGS))->toThrow(InvalidSettingException::class);
    }
    expect($learner->fresh()->region_code)->toBe('UZ-TK');
});

it('takes a change back within 10 minutes without a 30-day penalty, once', function (): void {
    $learner = rgnLearner();
    rgnService()->set($learner, 'UZ-SA', LearnerRegionService::VIA_SETTINGS);

    CarbonImmutable::setTestNow('2026-12-30 10:00:00');
    rgnService()->set($learner, 'UZ-AN', LearnerRegionService::VIA_SETTINGS);
    expect(rgnService()->undoAvailableUntil($learner->fresh())?->toIso8601String())->toBe('2026-12-30T10:10:00+00:00');

    CarbonImmutable::setTestNow('2026-12-30 10:09:00');
    rgnService()->revert($learner);

    $learner->refresh();
    expect($learner->region_code)->toBe('UZ-SA')
        ->and($learner->region_changed_at?->toIso8601String())->toBe('2026-11-25T10:00:00+00:00')
        // No penalty: another region can be chosen right away (30 days from the first choice have passed).
        ->and(rgnService()->changeAvailableAt($learner))->toBeNull()
        ->and(rgnService()->undoAvailableUntil($learner))->toBeNull()
        ->and(rgnEvents()[2])->toEqual(['from' => 'UZ-AN', 'to' => 'UZ-SA', 'via' => 'undo']);

    expect(fn () => rgnService()->revert($learner))->toThrow(RegionRevertUnavailableException::class);
});

it('refuses the undo after 10 minutes and without a change', function (): void {
    $learner = rgnLearner();
    expect(fn () => rgnService()->revert($learner))->toThrow(RegionRevertUnavailableException::class);

    rgnService()->set($learner, 'UZ-SA', LearnerRegionService::VIA_SETTINGS);
    CarbonImmutable::setTestNow('2026-11-25 10:10:00');
    expect(fn () => rgnService()->revert($learner))->toThrow(RegionRevertUnavailableException::class);
    expect($learner->fresh()->region_code)->toBe('UZ-SA');
});

it('takes back a reset by mistake too', function (): void {
    $learner = rgnLearner(['region_code' => 'UZ-TK', 'region_changed_at' => '2026-11-20 10:00:00']);

    rgnService()->set($learner, null, LearnerRegionService::VIA_SETTINGS);
    rgnService()->revert($learner);

    expect($learner->fresh()->region_code)->toBe('UZ-TK')
        ->and($learner->fresh()->region_changed_at?->toIso8601String())->toBe('2026-11-20T10:00:00+00:00');
});

it('shows the card of the Rating tab without a region and hides it for 30 days after "Not now"', function (): void {
    $learner = rgnLearner();
    expect(rgnService()->promptVisible($learner))->toBeTrue();

    rgnService()->dismissPrompt($learner);
    expect(rgnService()->promptVisible($learner->fresh()))->toBeFalse();

    CarbonImmutable::setTestNow('2026-12-25 09:59:00');
    expect(rgnService()->promptVisible($learner->fresh()))->toBeFalse();
    CarbonImmutable::setTestNow('2026-12-25 10:00:00');
    expect(rgnService()->promptVisible($learner->fresh()))->toBeTrue();

    rgnService()->set($learner, 'other', LearnerRegionService::VIA_PROMPT);
    expect(rgnService()->promptVisible($learner->fresh()))->toBeFalse();
});

// --- R4: the API of the Mini App ------------------------------------------------------------------------------

it('sends the region, the dates, the card flag and the ordered list in the settings of GET /me', function (string $locale, string $name): void {
    $learner = rgnLearner(['interface_locale' => $locale]);

    $settings = $this->withHeaders(['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']])
        ->getJson('/api/twa/me')->assertOk()->json('settings');

    expect($settings['region_code'])->toBeNull()
        ->and($settings['region_change_available_at'])->toBeNull()
        ->and($settings['region_undo_available_until'])->toBeNull()
        ->and($settings['region_prompt'])->toBeTrue()
        ->and($settings['options']['regions'])->toHaveCount(15)
        ->and($settings['options']['regions'][0])->toBe(['code' => 'UZ-TK', 'name' => $name])
        ->and(end($settings['options']['regions'])['code'])->toBe('other');
})->with([['ru', 'город Ташкент'], ['uz', 'Toshkent shahri']]);

it('saves the region through PATCH /me/settings with the via of the screen', function (): void {
    $learner = rgnLearner();

    $settings = rgnPatch($this, $learner, ['region_code' => 'UZ-BU', 'region_via' => 'prompt'])->assertOk()->json('settings');

    expect($settings['region_code'])->toBe('UZ-BU')
        ->and($settings['region_prompt'])->toBeFalse()
        ->and($settings['region_change_available_at'])->toBe('2026-12-25T10:00:00+00:00')
        ->and($settings['region_undo_available_until'])->toBe('2026-11-25T10:10:00+00:00')
        ->and(rgnEvents())->toEqual([['from' => null, 'to' => 'UZ-BU', 'via' => 'prompt']])
        ->and(ProductEvent::query()->where('name', 'settings.changed')->sole()->props)->toBe(['fields' => 'region_code']);
});

it('answers 422 region_change_too_soon with the date and applies nothing of the request', function (): void {
    $learner = rgnLearner(['region_code' => 'UZ-TK', 'region_changed_at' => '2026-11-20 10:00:00', 'reminder_time' => '19:00']);

    rgnPatch($this, $learner, ['region_code' => 'UZ-SA', 'reminder_time' => '08:00'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'region_change_too_soon')
        ->assertJsonPath('error.details.available_at', '2026-12-20T10:00:00+00:00');

    expect($learner->fresh()->region_code)->toBe('UZ-TK')
        ->and(substr((string) $learner->fresh()->reminder_time, 0, 5))->toBe('19:00')
        ->and(rgnEvents())->toBe([]);
});

it('refuses an unknown region and an unknown via with 422 validation', function (array $body): void {
    $learner = rgnLearner();

    rgnPatch($this, $learner, $body)->assertStatus(422);

    expect($learner->fresh()->region_code)->toBeNull();
})->with([
    'unknown code' => [['region_code' => 'UZ-XX']],
    'lower case' => [['region_code' => 'uz-tk']],
    'a number' => [['region_code' => 17]],
    'unknown via' => [['region_code' => 'UZ-TK', 'region_via' => 'bot']],
]);

it('resets the region with null', function (): void {
    $learner = rgnLearner(['region_code' => 'UZ-TK', 'region_changed_at' => '2026-11-20 10:00:00']);

    rgnPatch($this, $learner, ['region_code' => null])->assertOk()->assertJsonPath('settings.region_code', null);

    expect(rgnEvents())->toEqual([['from' => 'UZ-TK', 'to' => null, 'via' => 'settings']]);
});

it('takes the change back through POST /me/settings/region/revert, then answers 422', function (): void {
    $learner = rgnLearner();
    rgnPatch($this, $learner, ['region_code' => 'UZ-BU'])->assertOk();

    rgnPost($this, $learner, '/api/twa/me/settings/region/revert')
        ->assertOk()
        ->assertJsonPath('settings.region_code', null)
        ->assertJsonPath('settings.region_undo_available_until', null);

    rgnPost($this, $learner, '/api/twa/me/settings/region/revert')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'region_revert_unavailable');
});

it('hides the card for 30 days through POST /me/region-prompt/dismiss, idempotently', function (): void {
    $learner = rgnLearner();

    rgnPost($this, $learner, '/api/twa/me/region-prompt/dismiss')->assertNoContent();
    rgnPost($this, $learner, '/api/twa/me/region-prompt/dismiss')->assertNoContent();

    expect(rgnService()->promptVisible($learner->fresh()))->toBeFalse()
        ->and($learner->fresh()->meta)->toHaveKey(LearnerRegionService::PROMPT_DISMISSED_META);
});

it('refuses the region endpoints without a token', function (): void {
    $this->postJson('/api/twa/me/settings/region/revert')->assertUnauthorized();
    $this->postJson('/api/twa/me/region-prompt/dismiss')->assertUnauthorized();
});
