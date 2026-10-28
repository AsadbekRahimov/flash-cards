<?php

declare(strict_types=1);

use App\Domain\Site\Services\LandingPricing;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;

/*
 * The limits the landing quotes (Free's new words per day, the decks and the words per deck of both plans) are the ones
 * the app enforces: `billing.plans.free.limits.new_words_per_day` (null = no daily limit) and `decks.limits.{free,pro}`.
 * LandingPricing::limits() reads them, lang/{ru,uz}/landing.php types none of them.
 */

beforeEach(function (): void {
    Config::set('telegram.bot_username', 'lexiflow_test_bot');
    Config::set('billing.paywall.contact', '');
    Config::set('billing.paywall.mode', 'coming_soon');
});

/** The limits of the shipped config (config/billing.php, config/decks.php), whatever the .env of the host says. */
function landingShippedLimits(): void
{
    Config::set('billing.plans.free.limits.new_words_per_day', 20);
    Config::set('decks.limits', ['free' => ['decks' => 1, 'deck_words' => 50], 'pro' => ['decks' => 20, 'deck_words' => 2000]]);
}

/** Different from the shipped ones in every number, so nothing can match by accident. */
function landingCustomLimits(): void
{
    Config::set('billing.plans.free.limits.new_words_per_day', 15);
    Config::set('decks.limits', ['free' => ['decks' => 1, 'deck_words' => 70], 'pro' => ['decks' => 5, 'deck_words' => 300]]);
}

/** @return string the text of `<meta name="description">`, entities decoded */
function landingMetaDescription(string $html): string
{
    preg_match('/<meta name="description" content="([^"]*)">/', $html, $m);

    return html_entity_decode($m[1] ?? '', ENT_QUOTES);
}

/** @return list<list<string>> the ticks of every `<ul class="ticks">` (Free, Pro, centers), entities decoded */
function landingTicks(string $html): array
{
    preg_match_all('#<ul class="ticks">(.*?)</ul>#s', $html, $lists);

    return array_map(static function (string $list): array {
        preg_match_all('#<li>(.*?)</li>#s', $list, $items);

        return array_map(static fn (string $item): string => html_entity_decode(trim($item), ENT_QUOTES), $items[1]);
    }, $lists[1]);
}

// --- LandingPricing::limits() ------------------------------------------------------

it('words the shipped limits as the landing always did, and adds the own deck to Free', function (): void {
    landingShippedLimits();

    expect((new LandingPricing)->limits('ru'))->toBe([
        'free' => '20 новых слов в день',
        'freeFeatures' => ['Публичные курсы', '20 новых слов в день', 'Повторения без ограничений', 'Своя колода до 50 слов', 'Бот и мини-приложение'],
        'proFeatures' => [
            'Без дневного лимита новых слов',
            'Упражнения «Написание слова» и «Пропуск в примере»',
            'Свои колоды: до 20 колод по 2000 слов',
            'Заморозки серии: пропущенный день не обрывает серию',
            'Пробные тесты IELTS и SAT',
        ],
    ])->and((new LandingPricing)->limits('uz'))->toBe([
        'free' => "kuniga 20 ta yangi so'z",
        'freeFeatures' => ['Ommaviy kurslar', "Kuniga 20 ta yangi so'z", 'Cheklovsiz takrorlash', "Shaxsiy to'plam: 50 tagacha so'z", 'Bot va mini-ilova'],
        'proFeatures' => [
            "Yangi so'zlar uchun kunlik limitsiz",
            "Mashqlar: «So'zni yozish» va «Misoldagi bo'sh joy»",
            "Shaxsiy to'plamlar: 2000 tadan so'zli 20 tagacha to'plam",
            "Seriyani muzlatish: o'tkazib yuborilgan kun seriyani uzmaydi",
            'IELTS va SAT sinov testlari',
        ],
    ]);
});

it('inflects "новое слово" by the daily limit in ru, and not at all in uz', function (int $limit, string $expected): void {
    Config::set('billing.plans.free.limits.new_words_per_day', $limit);

    expect((new LandingPricing)->limits('ru')['free'])->toBe($expected)
        ->and((new LandingPricing)->limits('ru')['freeFeatures'][1])->toBe($expected)
        ->and((new LandingPricing)->limits('uz')['free'])->toBe("kuniga {$limit} ta yangi so'z");
})->with([
    '0' => [0, '0 новых слов в день'],
    '1' => [1, '1 новое слово в день'],
    '2' => [2, '2 новых слова в день'],
    '4' => [4, '4 новых слова в день'],
    '5' => [5, '5 новых слов в день'],
    '11' => [11, '11 новых слов в день'],
    '12' => [12, '12 новых слов в день'],
    '15' => [15, '15 новых слов в день'],
    '21' => [21, '21 новое слово в день'],
    '22' => [22, '22 новых слова в день'],
    '25' => [25, '25 новых слов в день'],
    '101' => [101, '101 новое слово в день'],
]);

it('says "no daily limit" instead of a number when Free has none, and then Pro has no such advantage', function (): void {
    landingShippedLimits();
    Config::set('billing.plans.free.limits.new_words_per_day', null);

    $ru = (new LandingPricing)->limits('ru');
    $uz = (new LandingPricing)->limits('uz');

    expect($ru['free'])->toBe('новые слова без дневного лимита')
        ->and($ru['freeFeatures'])->toBe(['Публичные курсы', 'Новые слова без дневного лимита', 'Повторения без ограничений', 'Своя колода до 50 слов', 'Бот и мини-приложение'])
        ->and($ru['proFeatures'])->toHaveCount(4)->not->toContain('Без дневного лимита новых слов')
        ->and($uz['free'])->toBe("yangi so'zlar uchun kunlik limitsiz")
        ->and($uz['freeFeatures'][1])->toBe("Yangi so'zlar uchun kunlik limitsiz")
        ->and($uz['proFeatures'])->toHaveCount(4)->not->toContain("Yangi so'zlar uchun kunlik limitsiz");

    foreach ([...$ru['freeFeatures'], ...$ru['proFeatures'], ...$uz['freeFeatures'], ...$uz['proFeatures']] as $tick) {
        expect($tick)->not->toBe('')->not->toStartWith(':');
    }
});

it('lists several decks of Free in the plural form, and the single one of Pro in the singular', function (): void {
    landingShippedLimits();
    Config::set('decks.limits', ['free' => ['decks' => 3, 'deck_words' => 70], 'pro' => ['decks' => 1, 'deck_words' => 400]]);

    expect((new LandingPricing)->limits('ru')['freeFeatures'])->toContain('Свои колоды: до 3 колод по 70 слов')
        ->and((new LandingPricing)->limits('ru')['proFeatures'])->toContain('Своя колода до 400 слов')
        ->and((new LandingPricing)->limits('uz')['freeFeatures'])->toContain("Shaxsiy to'plamlar: 70 tadan so'zli 3 tagacha to'plam")
        ->and((new LandingPricing)->limits('uz')['proFeatures'])->toContain("Shaxsiy to'plam: 400 tagacha so'z");
});

it('counts exactly one deck as the singular and any other number as many, whatever the ru plural rule says', function (): void {
    landingShippedLimits();
    Config::set('decks.limits.free.decks', 21);

    $ticks = (new LandingPricing)->limits('ru')['freeFeatures'];

    expect($ticks)->toContain('Свои колоды: до 21 колод по 50 слов')
        ->not->toContain('Своя колода до 50 слов');
});

it('leaves out the deck tick of a plan that cannot make decks', function (array $free): void {
    landingShippedLimits();
    Config::set('decks.limits.free', $free);

    foreach (['ru', 'uz'] as $locale) {
        $limits = (new LandingPricing)->limits($locale);

        expect($limits['freeFeatures'])->toHaveCount(4)
            ->and($limits['proFeatures'])->toHaveCount(5);
    }
    expect((new LandingPricing)->limits('ru')['freeFeatures'])->toBe(['Публичные курсы', '20 новых слов в день', 'Повторения без ограничений', 'Бот и мини-приложение']);
})->with([
    'no decks' => [['decks' => 0, 'deck_words' => 50]],
    'no words in a deck' => [['decks' => 1, 'deck_words' => 0]],
    'a negative limit counts as none' => [['decks' => -3, 'deck_words' => 50]],
    'not configured' => [[]],
]);

// --- the pages ----------------------------------------------------------------------

it('quotes the configured limits on the landing in ru and in uz, and not the old numbers', function (): void {
    landingCustomLimits();

    $this->get('/ru')->assertOk()
        ->assertSee('Бесплатно: 15 новых слов в день, повторения — без ограничений.')
        ->assertSee('Своя колода до 70 слов')
        ->assertSee('Свои колоды: до 5 колод по 300 слов')
        ->assertDontSee('20 новых слов')
        ->assertDontSee('до 20 колод')
        ->assertDontSee('2000')
        ->assertDontSee('до 50 слов');

    $this->get('/uz')->assertOk()
        ->assertSee("Bepul: kuniga 15 ta yangi so'z, takrorlash esa cheklovsiz.")
        ->assertSee("Shaxsiy to'plam: 70 tagacha so'z")
        ->assertSee("Shaxsiy to'plamlar: 300 tadan so'zli 5 tagacha to'plam")
        ->assertDontSee('kuniga 20 ta')
        ->assertDontSee('2000')
        ->assertDontSee('50 tagacha');
});

it('puts the plan limits into the ticks of the plan cards', function (): void {
    landingCustomLimits();

    [$free, $pro] = landingTicks($this->get('/ru')->getContent());
    expect($free)->toBe(['Публичные курсы', '15 новых слов в день', 'Повторения без ограничений', 'Своя колода до 70 слов', 'Бот и мини-приложение'])
        ->and($pro)->toBe([
            'Без дневного лимита новых слов',
            'Упражнения «Написание слова» и «Пропуск в примере»',
            'Свои колоды: до 5 колод по 300 слов',
            'Заморозки серии: пропущенный день не обрывает серию',
            'Пробные тесты IELTS и SAT',
        ]);

    [$free, $pro] = landingTicks($this->get('/uz')->getContent());
    expect($free)->toBe(['Ommaviy kurslar', "Kuniga 15 ta yangi so'z", 'Cheklovsiz takrorlash', "Shaxsiy to'plam: 70 tagacha so'z", 'Bot va mini-ilova'])
        ->and($pro[2])->toBe("Shaxsiy to'plamlar: 300 tadan so'zli 5 tagacha to'plam");
});

it('puts the daily limit into the meta description and the og:description of every public page', function (string $url, string $needle): void {
    landingCustomLimits();

    $html = $this->get($url)->assertOk()->getContent();

    expect(landingMetaDescription($html))->toContain($needle)
        ->and(html_entity_decode($html, ENT_QUOTES))->toContain('<meta property="og:description" content="'.landingMetaDescription($html).'">');
})->with([
    'landing ru' => ['/ru', 'Бесплатно: 15 новых слов в день.'],
    'landing uz' => ['/uz', "Bepul: kuniga 15 ta yangi so'z."],
    'default language' => ['/', 'Бесплатно: 15 новых слов в день.'],
    'offer' => ['/ru/offer', 'Бесплатно: 15 новых слов в день.'],
    'privacy' => ['/uz/privacy', "Bepul: kuniga 15 ta yangi so'z."],
]);

it('shows no number and no hole on the pages when Free has no daily limit', function (): void {
    landingShippedLimits();
    Config::set('billing.plans.free.limits.new_words_per_day', null);

    $ru = $this->get('/ru')->assertOk()
        ->assertSee('Бесплатно: новые слова без дневного лимита, повторения — без ограничений.')
        ->assertDontSee('новых слов в день');
    $uz = $this->get('/uz')->assertOk()
        ->assertSee("Bepul: yangi so'zlar uchun kunlik limitsiz, takrorlash esa cheklovsiz.");

    expect(landingMetaDescription($ru->getContent()))->toEndWith('Бесплатно: новые слова без дневного лимита.')
        ->and(landingMetaDescription($uz->getContent()))->toEndWith("Bepul: yangi so'zlar uchun kunlik limitsiz.");

    foreach ([$ru, $uz] as $response) {
        [$free, $pro] = landingTicks($response->getContent());

        expect($free)->toHaveCount(5)->and($pro)->toHaveCount(4)
            ->and($response->getContent())->not->toContain('<li></li>')->not->toContain(':new_words')->not->toContain(':decks');
    }
});

it('shows the limits of the shipped config as before', function (): void {
    landingShippedLimits();

    $this->get('/ru')
        ->assertSee('Бесплатно: 20 новых слов в день, повторения — без ограничений.')
        ->assertSee('Свои колоды: до 20 колод по 2000 слов');
    $this->get('/uz')
        ->assertSee("Bepul: kuniga 20 ta yangi so'z, takrorlash esa cheklovsiz.")
        ->assertSee("Shaxsiy to'plamlar: 2000 tadan so'zli 20 tagacha to'plam");
});

// --- nothing typed in lang ----------------------------------------------------------

it('types no number in lang/{ru,uz}/landing.php, apart from the counter of the app picture', function (string $locale): void {
    $strings = Arr::dot(require lang_path("{$locale}/landing.php"));
    // `hero.mockup.progress` ("7 / 20") is a drawing of the Mini App, not a limit of a plan.
    unset($strings['hero.mockup.progress']);

    // The plural selectors `{1}` and `[2,*]` of trans_choice are syntax, not numbers of the copy.
    $withDigits = array_keys(array_filter($strings, static fn (mixed $text): bool => preg_match('/\d/', (string) preg_replace('/[\{\[][\d,*]+[\}\]]/', '', (string) $text)) === 1));

    expect($strings)->not->toBeEmpty()
        ->and($withDigits)->toBe([]);
})->with(['ru', 'uz']);
