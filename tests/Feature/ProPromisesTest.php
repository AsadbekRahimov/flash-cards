<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Services\CheckoutService;
use App\Domain\Billing\Support\ProPromises;
use App\Domain\Site\Services\LandingPricing;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\ProScreen;
use App\Models\Learner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeTelegramClient;

/*
 * "Practice and battles without a daily limit" (docs/18 G1) is among the things Pro gives, and the public copy may say so only
 * while it is true for the reader: practice open to everybody (`PRACTICE_ACCESS=all`) with a daily limit of Free to lift. At
 * `pilot` it is open to a few and at `off` (the production default) to nobody. The landing and the Stars invoice are checked
 * here; the bot's /pro screen lists no Pro features at all, which the last test pins.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['cache.pure_store' => 'array']);
});

/** The words that name the practice line in each locale. */
const PRO_PRACTICE_WORDS = ['ru' => ['практик', 'баттл'], 'uz' => ['amaliyot', 'bellashuv']];

function proLandingHasPracticeTick(string $locale): bool
{
    $text = mb_strtolower(implode("\n", (new LandingPricing)->limits($locale)['proFeatures']));

    return str_contains($text, PRO_PRACTICE_WORDS[$locale][0]) && str_contains($text, PRO_PRACTICE_WORDS[$locale][1]);
}

it('promises practice without a daily limit only when it is open to everybody and Free has a limit', function (mixed $access, mixed $sessions, mixed $battles, bool $promised): void {
    config(['practice.access' => $access, 'practice.free_limits.sessions_per_day' => $sessions, 'practice.free_limits.battles_per_day' => $battles]);

    expect(ProPromises::practiceUnlimited())->toBe($promised);
})->with([
    'all, the defaults of Free' => ['all', 2, 1, true],
    'all, written loosely' => [' ALL ', 2, 1, true],
    'all, only sessions are limited' => ['all', 2, null, true],
    'all, only battles are limited' => ['all', '', 1, true],
    'all, zero is a limit: only Pro creates' => ['all', 0, null, true],
    'all, nothing is limited: nothing to lift' => ['all', null, null, false],
    'all, empty values' => ['all', '', '', false],
    'pilot' => ['pilot', 2, 1, false],
    'off' => ['off', 2, 1, false],
    'not set' => [null, 2, 1, false],
    'anything else is off' => ['yes', 2, 1, false],
]);

it('names practice and battles in the Pro card of the landing at all, and in no other mode', function (string $access, bool $named): void {
    config(['practice.access' => $access]);

    foreach (['ru', 'uz'] as $locale) {
        $features = (new LandingPricing)->limits($locale)['proFeatures'];

        expect(proLandingHasPracticeTick($locale))->toBe($named, "{$locale} at {$access}")
            ->and($features)->toHaveCount($named ? 6 : 5);

        if ($named) {
            // The second tick, after the new words, as in the Mini App.
            expect($features[1])->toBe(__('landing.limits.pro_practice', [], $locale))
                ->and(array_unique($features))->toHaveCount(6);
        }
    }
})->with([
    'public release' => ['all', true],
    'pilot' => ['pilot', false],
    'off' => ['off', false],
]);

it('drops the practice tick when Free has no daily limit of it, like the tick of the new words', function (): void {
    config(['practice.access' => 'all', 'practice.free_limits.sessions_per_day' => null, 'practice.free_limits.battles_per_day' => null]);

    expect(proLandingHasPracticeTick('ru'))->toBeFalse()
        ->and(proLandingHasPracticeTick('uz'))->toBeFalse();
});

it('shows the practice line on the landing page only at all, and follows a change of the switch at once', function (): void {
    config(['practice.access' => 'off']);
    foreach (['ru', 'uz'] as $locale) {
        $this->get("/{$locale}")->assertOk()->assertDontSee(__('landing.limits.pro_practice', [], $locale));
    }

    config(['practice.access' => 'all']);
    foreach (['ru', 'uz'] as $locale) {
        $this->get("/{$locale}")->assertOk()->assertSee(__('landing.limits.pro_practice', [], $locale));
    }

    config(['practice.access' => 'pilot']);
    foreach (['ru', 'uz'] as $locale) {
        $this->get("/{$locale}")->assertOk()->assertDontSee(__('landing.limits.pro_practice', [], $locale));
    }
});

it('puts the practice variant of the description into the Stars invoice only at all', function (string $access, bool $withPractice, string $locale, string $period): void {
    $telegram = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $telegram);
    config(['billing.providers.telegram_stars.enabled' => true, 'billing.paywall.mode' => 'purchase', 'practice.access' => $access]);
    $learner = Learner::factory()->create(['interface_locale' => $locale]);

    app(CheckoutService::class)->create($learner, Plan::Pro, $period, SubscriptionProvider::TelegramStars, null, 'twa');

    $key = $period === 'month' ? 'description_month' : 'description_year';
    $description = $telegram->invoiceLinks[0]['description'];

    expect($description)->toBe(__("billing.stars.invoice.{$key}".($withPractice ? '_practice' : ''), [], $locale))
        ->and(mb_strlen($description))->toBeLessThanOrEqual(255);

    $named = str_contains(mb_strtolower($description), PRO_PRACTICE_WORDS[$locale][0]);
    expect($named)->toBe($withPractice);
})->with([
    'public release' => ['all', true],
    'pilot' => ['pilot', false],
    'off' => ['off', false],
])->with(['ru', 'uz'])->with(['month', 'year']);

it('keeps all eight invoice descriptions within the 255 characters of Telegram, and the practice ones name practice and battles', function (string $locale, string $key): void {
    foreach (['', '_practice'] as $variant) {
        $description = __("billing.stars.invoice.{$key}{$variant}", [], $locale);

        expect(mb_strlen($description))->toBeGreaterThan(0)->toBeLessThanOrEqual(255, "{$locale} {$key}{$variant}")
            ->and($description)->toContain('IELTS')->toContain('SAT')
            ->and($description)->toContain((string) config('decks.limits.pro.decks'))->toContain((string) config('decks.limits.pro.deck_words'));

        $text = mb_strtolower($description);
        foreach (PRO_PRACTICE_WORDS[$locale] as $word) {
            expect(str_contains($text, $word))->toBe($variant === '_practice', "{$locale} {$key}{$variant} / {$word}");
        }
    }
})->with(['ru', 'uz'])->with(['description_month', 'description_year']);

it('says nothing of Pro features on the /pro screen at any access: it lists none, so there is no line to gate', function (string $access): void {
    config(['practice.access' => $access, 'billing.paywall.mode' => 'coming_soon']);

    foreach (['ru', 'uz'] as $locale) {
        $learner = Learner::factory()->create(['interface_locale' => $locale]);
        $text = mb_strtolower(app(ProScreen::class)->build($learner)['text']);

        foreach (PRO_PRACTICE_WORDS[$locale] as $word) {
            expect(str_contains($text, $word))->toBeFalse("{$locale} /pro at {$access} names {$word}");
        }
    }
})->with(['all', 'pilot', 'off']);
