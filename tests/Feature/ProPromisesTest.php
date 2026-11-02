<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\ProPracticePromise;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Services\CheckoutService;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Billing\Support\ProPromises;
use App\Domain\Practice\Services\PracticeAccess;
use App\Domain\Site\Services\LandingPricing;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\ProScreen;
use App\Models\Learner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeTelegramClient;
use Tests\Support\Practice\PracticeKit;
use Tests\Support\QueryLog;

/*
 * "Practice (and battles) without a daily limit" (docs/18 G1) is among the things Pro gives, and the copy may say so only while it
 * is true for the reader. ONE rule (`ProPromises`) decides it for three readers: the landing and the Stars invoice (the public copy:
 * practice is open there only at `PRACTICE_ACCESS=all`), and the Mini App, which is sent `practice.pro_promise` by `/me` (practice
 * is open to a learner when the entries are shown to them: everybody at `all`, the pilot's learners at `pilot`, a Free learner at
 * `PRACTICE_PLAN=pro`). Given that practice is open: at `plan = pro` the line is there (Free has no practice at all, Pro opens it),
 * with battles in it only while they are on; at `plan = all` it is there while Free has a daily limit to lift - battles in it while
 * they are on and limited. The bot's /pro screen lists no Pro features at all, which the last test pins.
 */

uses(RefreshDatabase::class);

// One fake client for the whole test: the provider registry is built on its first use and keeps the client it was given.
beforeEach(function (): void {
    $this->telegram = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->telegram);
});

/** The words that name practice and battles in each locale. */
const PRO_PRACTICE_WORDS = ['ru' => ['практик', 'баттл'], 'uz' => ['amaliyot', 'bellashuv']];

/**
 * The rule for a reader to whom practice IS open, written out as a table: "<plan>:<battles>:<Free sessions>:<Free battles>". A Free
 * limit is `limited` for a number (0 included: only Pro creates) and `free` for an empty value.
 */
const PRO_PROMISE_RULE = [
    // plan = all: Pro lifts a Free limit; battles are named while they are on and Free is limited in them
    'all:on:limited:limited' => ProPracticePromise::Both,
    'all:on:limited:free' => ProPracticePromise::Solo,
    'all:on:free:limited' => ProPracticePromise::Both,
    'all:on:free:free' => ProPracticePromise::None,
    'all:off:limited:limited' => ProPracticePromise::Solo,
    'all:off:limited:free' => ProPracticePromise::Solo,
    'all:off:free:limited' => ProPracticePromise::None,
    'all:off:free:free' => ProPracticePromise::None,
    // plan = pro: Free has no practice at all, so there is no limit to lift - practice itself is what Pro opens, battles in it while they are on
    'pro:on:limited:limited' => ProPracticePromise::Both,
    'pro:on:limited:free' => ProPracticePromise::Both,
    'pro:on:free:limited' => ProPracticePromise::Both,
    'pro:on:free:free' => ProPracticePromise::Both,
    'pro:off:limited:limited' => ProPracticePromise::Solo,
    'pro:off:limited:free' => ProPracticePromise::Solo,
    'pro:off:free:limited' => ProPracticePromise::Solo,
    'pro:off:free:free' => ProPracticePromise::Solo,
];

/** The values of one Free limit by their kind: a number is a limit (0 included), an empty value is none. */
const PRO_LIMIT_VALUES = ['limited' => [0, 2], 'free' => [null, '']];

/** @return array{0: bool, 1: bool} whether the text names practice, whether it names battles */
function proNames(string $text, string $locale): array
{
    $text = mb_strtolower($text);

    return [str_contains($text, PRO_PRACTICE_WORDS[$locale][0]), str_contains($text, PRO_PRACTICE_WORDS[$locale][1])];
}

/** The configuration of one test: the access switch, the Free limits of both kinds, the battle switch, the plan. */
function proPracticeConfig(mixed $access, mixed $sessions = 2, mixed $battles = 1, bool $battleOn = true, mixed $plan = 'all'): void
{
    config([
        'practice.access' => $access,
        'practice.plan' => $plan,
        'practice.free_limits.sessions_per_day' => $sessions,
        'practice.free_limits.battles_per_day' => $battles,
        'practice.battle.enabled' => $battleOn,
    ]);
}

/**
 * Every combination of the plan, the battle switch and the two Free limits (64), with what the table says for a reader to whom
 * practice is open.
 *
 * @return list<array{plan: string, battleOn: bool, sessions: mixed, battles: mixed, expected: ProPracticePromise}>
 */
function proPromiseGrid(): array
{
    $grid = [];

    foreach (['all', 'pro'] as $plan) {
        foreach ([true, false] as $battleOn) {
            foreach (PRO_LIMIT_VALUES as $sessionsKind => $sessionsValues) {
                foreach (PRO_LIMIT_VALUES as $battlesKind => $battlesValues) {
                    foreach ($sessionsValues as $sessions) {
                        foreach ($battlesValues as $battles) {
                            $grid[] = [
                                'plan' => $plan,
                                'battleOn' => $battleOn,
                                'sessions' => $sessions,
                                'battles' => $battles,
                                'expected' => PRO_PROMISE_RULE["{$plan}:".($battleOn ? 'on' : 'off').":{$sessionsKind}:{$battlesKind}"],
                            ];
                        }
                    }
                }
            }
        }
    }

    return $grid;
}

/** The description of the Stars invoice an order of the period gets, as Telegram is told (the newest invoice of the test). */
function proStarsDescription(string $locale, string $period): string
{
    config(['billing.providers.telegram_stars.enabled' => true, 'billing.paywall.mode' => 'purchase']);
    $learner = Learner::factory()->create(['interface_locale' => $locale]);

    app(CheckoutService::class)->create($learner, Plan::Pro, $period, SubscriptionProvider::TelegramStars, null, 'twa');

    $invoices = test()->telegram->invoiceLinks;

    return end($invoices)['description'];
}

// --- the rule ---------------------------------------------------------------------------------------------------------

it('promises the public practice without a daily limit by what is open and what Pro lifts', function (mixed $access, mixed $sessions, mixed $battles, bool $battleOn, mixed $plan, ProPracticePromise $expected): void {
    proPracticeConfig($access, $sessions, $battles, $battleOn, $plan);

    expect(ProPromises::publicPractice())->toBe($expected);
})->with([
    'all, the defaults of Free, battles on' => ['all', 2, 1, true, 'all', ProPracticePromise::Both],
    'all, written loosely' => [' ALL ', 2, 1, true, 'all', ProPracticePromise::Both],
    'all, battles switched off' => ['all', 2, 1, false, 'all', ProPracticePromise::Solo],
    'all, battles on but unlimited for Free: nothing to lift there' => ['all', 2, null, true, 'all', ProPracticePromise::Solo],
    'all, only battles limited, battles on: the line of both, like the Mini App' => ['all', null, 1, true, 'all', ProPracticePromise::Both],
    'all, only battles limited, battles OFF: nothing to lift' => ['all', null, 1, false, 'all', ProPracticePromise::None],
    'all, only battles limited, battles OFF, empty strings' => ['all', '', 1, false, 'all', ProPracticePromise::None],
    'all, zero sessions is a limit: only Pro creates' => ['all', 0, null, false, 'all', ProPracticePromise::Solo],
    'all, nothing limited: nothing to lift' => ['all', null, null, true, 'all', ProPracticePromise::None],
    'all, empty values' => ['all', '', '', true, 'all', ProPracticePromise::None],
    'all, an unknown plan is `all` (as PracticeAccess reads it)' => ['all', null, null, true, 'premium', ProPracticePromise::None],
    // PRACTICE_PLAN=pro: Free has no practice at all, so there is no limit to lift - and the line is still true, it is what Pro opens
    'all, practice behind Pro, no limits, battles on' => ['all', null, null, true, 'pro', ProPracticePromise::Both],
    'all, practice behind Pro, no limits, battles off' => ['all', null, null, false, 'pro', ProPracticePromise::Solo],
    'all, practice behind Pro, written loosely' => ['all', null, null, true, ' Pro ', ProPracticePromise::Both],
    'all, practice behind Pro: the limits of Free change nothing' => ['all', 2, 1, true, 'pro', ProPracticePromise::Both],
    'all, practice behind Pro, battles off, limits set' => ['all', 2, 1, false, 'pro', ProPracticePromise::Solo],
    'pilot' => ['pilot', 2, 1, true, 'all', ProPracticePromise::None],
    'pilot, practice behind Pro' => ['pilot', null, null, true, 'pro', ProPracticePromise::None],
    'off' => ['off', 2, 1, true, 'all', ProPracticePromise::None],
    'off, practice behind Pro' => ['off', null, null, true, 'pro', ProPracticePromise::None],
    'not set' => [null, 2, 1, true, 'all', ProPracticePromise::None],
    'anything else is off' => ['yes', 2, 1, true, 'all', ProPracticePromise::None],
]);

it('follows the table for every access, plan, battle switch and pair of Free limits: only `all` opens practice to the public', function (): void {
    $wrong = [];

    foreach (['off', 'pilot', 'all'] as $access) {
        foreach (proPromiseGrid() as $case) {
            proPracticeConfig($access, $case['sessions'], $case['battles'], $case['battleOn'], $case['plan']);
            $expected = $access === 'all' ? $case['expected'] : ProPracticePromise::None;
            $got = ProPromises::publicPractice();

            if ($got !== $expected) {
                $wrong[] = sprintf(
                    'access %s, plan %s, battles %s, Free sessions %s, Free battles %s: %s instead of %s',
                    $access, $case['plan'], $case['battleOn'] ? 'on' : 'off', var_export($case['sessions'], true), var_export($case['battles'], true), $got->value, $expected->value,
                );
            }
        }
    }

    expect($wrong)->toBe([]);
});

it('answers for a reader by `open` alone: closed is nothing whatever the config says, open is the table whatever the access is', function (): void {
    $wrong = [];

    foreach (['off', 'pilot', 'all'] as $access) {
        foreach (proPromiseGrid() as $case) {
            proPracticeConfig($access, $case['sessions'], $case['battles'], $case['battleOn'], $case['plan']);

            if (ProPromises::practice(open: false) !== ProPracticePromise::None) {
                $wrong[] = "closed at {$access}/{$case['plan']}";
            }
            if (ProPromises::practice(open: true) !== $case['expected']) {
                $wrong[] = sprintf('open at %s/%s, Free %s/%s, battles %s', $access, $case['plan'], var_export($case['sessions'], true), var_export($case['battles'], true), $case['battleOn'] ? 'on' : 'off');
            }
        }
    }

    expect($wrong)->toBe([]);
});

it('picks the text by the promise: the keys of the landing line and the suffix of the invoice', function (): void {
    expect(ProPracticePromise::None->landingKey())->toBeNull()
        ->and(ProPracticePromise::Solo->landingKey())->toBe('landing.limits.pro_practice_solo')
        ->and(ProPracticePromise::Both->landingKey())->toBe('landing.limits.pro_practice')
        ->and(ProPracticePromise::None->invoiceSuffix())->toBe('')
        ->and(ProPracticePromise::Solo->invoiceSuffix())->toBe('_practice_solo')
        ->and(ProPracticePromise::Both->invoiceSuffix())->toBe('_practice');
});

// --- the Mini App: practice.pro_promise of /me ------------------------------------------------------------------------

it('sends the Mini App the promise in /me, for the learner practice is open to: a pilot learner, a Free learner behind Pro, and nothing at off', function (string $access, string $plan, string $who, bool $battleOn, mixed $sessions, mixed $battles, bool $available, ProPracticePromise $expected): void {
    proPracticeConfig($access, $sessions, $battles, $battleOn, $plan);
    $learner = match ($who) {
        'free' => Learner::factory()->create(),
        'pro' => PracticeKit::pro(Learner::factory()->create()),
        'pilot' => Learner::factory()->create(['practice_pilot_at' => now()]),
    };

    $practice = $this->withHeaders(PracticeKit::headers($learner))->getJson('/api/twa/me')->assertOk()->json('practice');

    expect($practice['available'])->toBe($available)
        ->and($practice['pro_promise'])->toBe($expected->value);

    // At `all` practice is open to everybody: what the landing says is what every learner is sent.
    if ($access === 'all') {
        expect($practice['pro_promise'])->toBe(ProPromises::publicPractice()->value);
    }
})->with([
    // access = pilot: open to the learners of the pilot only
    'pilot, a learner of the pilot, the defaults of Free' => ['pilot', 'all', 'pilot', true, 2, 1, true, ProPracticePromise::Both],
    'pilot, a learner of the pilot, battles off' => ['pilot', 'all', 'pilot', false, 2, 1, true, ProPracticePromise::Solo],
    'pilot, a learner of the pilot, nothing limited' => ['pilot', 'all', 'pilot', true, null, null, true, ProPracticePromise::None],
    'pilot, a learner outside it' => ['pilot', 'all', 'free', true, 2, 1, false, ProPracticePromise::None],
    'pilot, practice behind Pro, a Free learner of the pilot (Pro chip)' => ['pilot', 'pro', 'pilot', true, null, null, true, ProPracticePromise::Both],
    'pilot, practice behind Pro, a learner outside it' => ['pilot', 'pro', 'free', true, null, null, false, ProPracticePromise::None],
    // access = all: the same word for everybody
    'all, a Free learner, the defaults of Free' => ['all', 'all', 'free', true, 2, 1, true, ProPracticePromise::Both],
    'all, a Free learner, only sessions limited' => ['all', 'all', 'free', true, 2, null, true, ProPracticePromise::Solo],
    'all, a Free learner, only battles limited' => ['all', 'all', 'free', true, null, 1, true, ProPracticePromise::Both],
    'all, a Free learner, only battles limited and off' => ['all', 'all', 'free', false, null, 1, true, ProPracticePromise::None],
    'all, a Free learner, nothing limited: no line, as the landing' => ['all', 'all', 'free', true, null, null, true, ProPracticePromise::None],
    'all, a Pro learner, the defaults of Free' => ['all', 'all', 'pro', true, 2, 1, true, ProPracticePromise::Both],
    'all, practice behind Pro, a Free learner (Pro chip), no limits' => ['all', 'pro', 'free', true, null, null, true, ProPracticePromise::Both],
    'all, practice behind Pro, a Free learner, battles off' => ['all', 'pro', 'free', false, null, null, true, ProPracticePromise::Solo],
    'all, practice behind Pro, a Pro learner' => ['all', 'pro', 'pro', true, null, null, true, ProPracticePromise::Both],
    // access = off: nobody has practice, so nothing is sold
    'off, a Free learner' => ['off', 'all', 'free', true, 2, 1, false, ProPracticePromise::None],
    'off, a learner of the pilot, practice behind Pro' => ['off', 'pro', 'pilot', true, null, null, false, ProPracticePromise::None],
]);

it('says the promise in the block of describe() without a query: it is a reading of the config', function (): void {
    proPracticeConfig('all');
    $learner = Learner::factory()->create();
    $entitlement = app(EntitlementResolver::class)->for($learner);
    $access = app(PracticeAccess::class);

    expect(QueryLog::during(fn () => $access->describe($learner, $entitlement)))->toBe([])
        ->and($access->describe($learner, $entitlement)['pro_promise'])->toBe('both');
});

// --- the landing and the Stars invoice: the public copy ---------------------------------------------------------------

it('writes the line of the Pro card of the landing by the promise, in both languages, as the Mini App does', function (string $access, bool $battleOn, ProPracticePromise $expected, string $plan = 'all', bool $limited = true): void {
    proPracticeConfig($access, $limited ? 2 : null, $limited ? 1 : null, $battleOn, $plan);

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
    'public release, practice behind Pro, no limits, battles on' => ['all', true, ProPracticePromise::Both, 'pro', false],
    'public release, practice behind Pro, no limits, battles off' => ['all', false, ProPracticePromise::Solo, 'pro', false],
    'pilot' => ['pilot', true, ProPracticePromise::None],
    'pilot, practice behind Pro' => ['pilot', true, ProPracticePromise::None, 'pro', false],
    'off' => ['off', false, ProPracticePromise::None],
    'off, practice behind Pro' => ['off', true, ProPracticePromise::None, 'pro', false],
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

        proPracticeConfig('all', null, null, plan: 'pro'); // practice behind Pro: no Free limit, and the line is what Pro opens
        $this->get("/{$locale}")->assertOk()->assertSee($both);

        proPracticeConfig('all', null, null, battleOn: false, plan: 'pro');
        $this->get("/{$locale}")->assertOk()->assertSee($solo)->assertDontSee($both);

        proPracticeConfig('pilot', null, null, plan: 'pro');
        $this->get("/{$locale}")->assertOk()->assertDontSee($both)->assertDontSee($solo);
    }
});

it('puts the variant of the description into the Stars invoice by the promise', function (string $access, bool $battleOn, string $suffix, string $plan, bool $limited, string $locale, string $period): void {
    proPracticeConfig($access, $limited ? 2 : null, $limited ? 1 : null, $battleOn, $plan);

    $key = $period === 'month' ? 'description_month' : 'description_year';
    $description = proStarsDescription($locale, $period);

    expect($description)->toBe(__("billing.stars.invoice.{$key}{$suffix}", [], $locale))
        ->and(mb_strlen($description))->toBeLessThanOrEqual(255);

    expect(proNames($description, $locale))->toBe(match ($suffix) {
        '' => [false, false],
        '_practice_solo' => [true, false],
        '_practice' => [true, true],
    });
})->with([
    'public release, battles on' => ['all', true, '_practice', 'all', true],
    'public release, battles off' => ['all', false, '_practice_solo', 'all', true],
    'public release, practice behind Pro, no limits' => ['all', true, '_practice', 'pro', false],
    'public release, practice behind Pro, no limits, battles off' => ['all', false, '_practice_solo', 'pro', false],
    'pilot' => ['pilot', true, '', 'all', true],
    'pilot, practice behind Pro' => ['pilot', true, '', 'pro', false],
    'off' => ['off', false, '', 'all', true],
    'off, practice behind Pro' => ['off', true, '', 'pro', false],
])->with(['ru', 'uz'])->with(['month', 'year']);

it('changes nothing for the settings the app ships with: no practice on the landing or in the invoice while it is off, and both words at the public release', function (): void {
    // config/practice.php as it ships: PRACTICE_ACCESS=off, PRACTICE_PLAN=all, 2 sessions and 1 battle a day for Free, battles on.
    expect(config('practice.access'))->toBe('off')
        ->and(config('practice.plan'))->toBe('all')
        ->and(config('practice.free_limits'))->toBe(['sessions_per_day' => 2, 'battles_per_day' => 1])
        ->and(config('practice.battle.enabled'))->toBeTrue()
        ->and(ProPromises::publicPractice())->toBe(ProPracticePromise::None);

    foreach (['ru', 'uz'] as $locale) {
        $features = (new LandingPricing)->limits($locale)['proFeatures'];

        expect($features)->toHaveCount(5)
            ->and(proNames(implode("\n", $features), $locale))->toBe([false, false])
            ->and(proStarsDescription($locale, 'month'))->toBe(__('billing.stars.invoice.description_month', [], $locale))
            ->and(proStarsDescription($locale, 'year'))->toBe(__('billing.stars.invoice.description_year', [], $locale));
    }

    // The public release with nothing else changed: the line of both, the invoice "with practice and battles" - what they were.
    config(['practice.access' => 'all']);

    foreach (['ru', 'uz'] as $locale) {
        $features = (new LandingPricing)->limits($locale)['proFeatures'];

        expect(ProPromises::publicPractice())->toBe(ProPracticePromise::Both)
            ->and($features)->toHaveCount(6)
            ->and($features[1])->toBe(__('landing.limits.pro_practice', [], $locale))
            ->and(proStarsDescription($locale, 'month'))->toBe(__('billing.stars.invoice.description_month_practice', [], $locale))
            ->and(proStarsDescription($locale, 'year'))->toBe(__('billing.stars.invoice.description_year_practice', [], $locale));
    }
});

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
