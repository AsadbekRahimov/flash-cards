<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\ProPracticePromise;
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
 * "Practice (and battles) without a daily limit" (docs/18 G1) is among the things Pro gives, and the public copy may say so only
 * while it is true for the reader: practice open to everybody (`PRACTICE_ACCESS=all`) with a daily limit of Free to lift - and
 * battles only while they are switched on (`PRACTICE_BATTLE`) and limited for Free, the rule of `/me` that the Mini App follows
 * (`benefits.practice` / `benefits.practice_solo`). At `pilot` practice is open to a few and at `off` (the production default) to
 * nobody. The landing and the Stars invoice are checked here; the bot's /pro screen lists no Pro features at all, which the last
 * test pins.
 */

uses(RefreshDatabase::class);

/** The words that name practice and battles in each locale. */
const PRO_PRACTICE_WORDS = ['ru' => ['практик', 'баттл'], 'uz' => ['amaliyot', 'bellashuv']];

/** @return array{0: bool, 1: bool} whether the text names practice, whether it names battles */
function proNames(string $text, string $locale): array
{
    $text = mb_strtolower($text);

    return [str_contains($text, PRO_PRACTICE_WORDS[$locale][0]), str_contains($text, PRO_PRACTICE_WORDS[$locale][1])];
}

/** The configuration of one test: the access switch, the Free limits of both kinds, the battle switch. */
function proPracticeConfig(mixed $access, mixed $sessions = 2, mixed $battles = 1, bool $battleOn = true): void
{
    config([
        'practice.access' => $access,
        'practice.free_limits.sessions_per_day' => $sessions,
        'practice.free_limits.battles_per_day' => $battles,
        'practice.battle.enabled' => $battleOn,
    ]);
}

it('promises practice without a daily limit by the two flags: practice open and limited, battles on and limited', function (mixed $access, mixed $sessions, mixed $battles, bool $battleOn, ProPracticePromise $expected): void {
    proPracticeConfig($access, $sessions, $battles, $battleOn);

    expect(ProPromises::practice())->toBe($expected)
        ->and(ProPromises::practiceUnlimited())->toBe($expected !== ProPracticePromise::None);
})->with([
    'all, the defaults of Free, battles on' => ['all', 2, 1, true, ProPracticePromise::Both],
    'all, written loosely' => [' ALL ', 2, 1, true, ProPracticePromise::Both],
    'all, battles switched off' => ['all', 2, 1, false, ProPracticePromise::Solo],
    'all, battles on but unlimited for Free: nothing to lift there' => ['all', 2, null, true, ProPracticePromise::Solo],
    'all, only battles limited, battles on: the line of both, like the Mini App' => ['all', null, 1, true, ProPracticePromise::Both],
    'all, only battles limited, battles OFF: nothing to lift' => ['all', null, 1, false, ProPracticePromise::None],
    'all, only battles limited, battles OFF, empty strings' => ['all', '', 1, false, ProPracticePromise::None],
    'all, zero sessions is a limit: only Pro creates' => ['all', 0, null, false, ProPracticePromise::Solo],
    'all, nothing limited: nothing to lift' => ['all', null, null, true, ProPracticePromise::None],
    'all, empty values' => ['all', '', '', true, ProPracticePromise::None],
    'pilot' => ['pilot', 2, 1, true, ProPracticePromise::None],
    'off' => ['off', 2, 1, true, ProPracticePromise::None],
    'not set' => [null, 2, 1, true, ProPracticePromise::None],
    'anything else is off' => ['yes', 2, 1, true, ProPracticePromise::None],
]);

it('picks the text by the promise: the keys of the landing line and the suffix of the invoice', function (): void {
    expect(ProPracticePromise::None->landingKey())->toBeNull()
        ->and(ProPracticePromise::Solo->landingKey())->toBe('landing.limits.pro_practice_solo')
        ->and(ProPracticePromise::Both->landingKey())->toBe('landing.limits.pro_practice')
        ->and(ProPracticePromise::None->invoiceSuffix())->toBe('')
        ->and(ProPracticePromise::Solo->invoiceSuffix())->toBe('_practice_solo')
        ->and(ProPracticePromise::Both->invoiceSuffix())->toBe('_practice');
});

it('writes the line of the Pro card of the landing by the promise, in both languages, as the Mini App does', function (string $access, bool $battleOn, ProPracticePromise $expected): void {
    proPracticeConfig($access, battleOn: $battleOn);

    foreach (['ru', 'uz'] as $locale) {
        $features = (new LandingPricing)->limits($locale)['proFeatures'];
        $line = $features[1] ?? '';
        [$practice, $battles] = proNames(implode("\n", $features), $locale);

        expect($features)->toHaveCount($expected === ProPracticePromise::None ? 5 : 6)
            ->and(array_unique($features))->toHaveCount(count($features));

        match ($expected) {
            ProPracticePromise::None => expect([$practice, $battles])->toBe([false, false], "{$locale} at {$access}"),
            ProPracticePromise::Solo => expect($line)->toBe(__('landing.limits.pro_practice_solo', [], $locale))
                ->and(proNames($line, $locale))->toBe([true, false], "{$locale}: practice without battles"),
            ProPracticePromise::Both => expect($line)->toBe(__('landing.limits.pro_practice', [], $locale))
                ->and(proNames($line, $locale))->toBe([true, true], "{$locale}: practice and battles"),
        };
    }
})->with([
    'public release, battles on' => ['all', true, ProPracticePromise::Both],
    'public release, battles off' => ['all', false, ProPracticePromise::Solo],
    'pilot' => ['pilot', true, ProPracticePromise::None],
    'off' => ['off', false, ProPracticePromise::None],
]);

it('keeps the two sentences of the landing word for word those of the Mini App', function (): void {
    $twaRu = (string) file_get_contents(base_path('resources/twa/src/i18n/ru.ts'));
    $twaUz = (string) file_get_contents(base_path('resources/twa/src/i18n/uz.ts'));

    expect($twaRu)->toContain("'benefits.practice': '".__('landing.limits.pro_practice', [], 'ru')."'")
        ->toContain("'benefits.practice_solo': '".__('landing.limits.pro_practice_solo', [], 'ru')."'")
        ->and($twaUz)->toContain("'benefits.practice': '".__('landing.limits.pro_practice', [], 'uz')."'")
        ->toContain("'benefits.practice_solo': '".__('landing.limits.pro_practice_solo', [], 'uz')."'");
});

it('drops the practice tick when Free has no daily limit of it, like the tick of the new words', function (): void {
    proPracticeConfig('all', null, null);

    foreach (['ru', 'uz'] as $locale) {
        expect(proNames(implode("\n", (new LandingPricing)->limits($locale)['proFeatures']), $locale))->toBe([false, false]);
    }
});

it('shows the practice line on the landing page by the promise, and follows a change of the switches at once', function (): void {
    $lines = fn (string $locale): array => [__('landing.limits.pro_practice', [], $locale), __('landing.limits.pro_practice_solo', [], $locale)];

    foreach (['ru', 'uz'] as $locale) {
        [$both, $solo] = $lines($locale);

        proPracticeConfig('off');
        $this->get("/{$locale}")->assertOk()->assertDontSee($both)->assertDontSee($solo);

        proPracticeConfig('all');
        $this->get("/{$locale}")->assertOk()->assertSee($both);

        proPracticeConfig('all', battleOn: false);
        $this->get("/{$locale}")->assertOk()->assertSee($solo)->assertDontSee($both);

        proPracticeConfig('all', null, 1, battleOn: false); // a limit only on battles that are off
        $this->get("/{$locale}")->assertOk()->assertDontSee($solo)->assertDontSee($both);

        proPracticeConfig('pilot');
        $this->get("/{$locale}")->assertOk()->assertDontSee($both)->assertDontSee($solo);
    }
});

it('puts the variant of the description into the Stars invoice by the promise', function (string $access, bool $battleOn, string $suffix, string $locale, string $period): void {
    $telegram = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $telegram);
    config(['billing.providers.telegram_stars.enabled' => true, 'billing.paywall.mode' => 'purchase']);
    proPracticeConfig($access, battleOn: $battleOn);
    $learner = Learner::factory()->create(['interface_locale' => $locale]);

    app(CheckoutService::class)->create($learner, Plan::Pro, $period, SubscriptionProvider::TelegramStars, null, 'twa');

    $key = $period === 'month' ? 'description_month' : 'description_year';
    $description = $telegram->invoiceLinks[0]['description'];

    expect($description)->toBe(__("billing.stars.invoice.{$key}{$suffix}", [], $locale))
        ->and(mb_strlen($description))->toBeLessThanOrEqual(255);

    expect(proNames($description, $locale))->toBe(match ($suffix) {
        '' => [false, false],
        '_practice_solo' => [true, false],
        '_practice' => [true, true],
    });
})->with([
    'public release, battles on' => ['all', true, '_practice'],
    'public release, battles off' => ['all', false, '_practice_solo'],
    'pilot' => ['pilot', true, ''],
    'off' => ['off', false, ''],
])->with(['ru', 'uz'])->with(['month', 'year']);

it('keeps all twelve invoice descriptions within the 255 characters of Telegram, each naming what it should', function (string $locale, string $key): void {
    $named = ['' => [false, false], '_practice' => [true, true], '_practice_solo' => [true, false]];

    foreach ($named as $variant => $expected) {
        $description = __("billing.stars.invoice.{$key}{$variant}", [], $locale);

        expect(mb_strlen($description))->toBeGreaterThan(0)->toBeLessThanOrEqual(255, "{$locale} {$key}{$variant}")
            ->and($description)->toContain('IELTS')->toContain('SAT')
            ->and($description)->toContain((string) config('decks.limits.pro.decks'))->toContain((string) config('decks.limits.pro.deck_words'))
            ->and(proNames($description, $locale))->toBe($expected, "{$locale} {$key}{$variant}");
    }
})->with(['ru', 'uz'])->with(['description_month', 'description_year']);

it('says nothing of Pro features on the /pro screen at any access: it lists none, so there is no line to gate', function (string $access): void {
    proPracticeConfig($access);
    config(['billing.paywall.mode' => 'coming_soon']);

    foreach (['ru', 'uz'] as $locale) {
        $learner = Learner::factory()->create(['interface_locale' => $locale]);

        expect(proNames(app(ProScreen::class)->build($learner)['text'], $locale))->toBe([false, false], "{$locale} /pro at {$access}");
    }
})->with(['all', 'pilot', 'off']);
