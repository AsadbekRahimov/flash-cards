<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\Plan;
use App\Domain\Site\Services\LandingPricing;
use Illuminate\Support\Arr;

/*
 * Marketing and invoice copy may only promise what Pro really gates: config/billing.php (`plans.*.features`,
 * new-word limit), config/decks.php (deck limits), config/learning.php (mock exams). "Detailed statistics"
 * used to be on the list while the code has no such feature.
 */

/** @return list<string> every string of lang/{locale}/landing.php, flattened */
function proCopyLandingStrings(string $locale): array
{
    return array_values(array_map('strval', Arr::dot(require lang_path("{$locale}/landing.php"))));
}

it('gates every Pro feature the copy names behind the plan config', function (): void {
    $pro = Plan::Pro->features();
    $free = Plan::Free->features();

    foreach (['exercise.spelling', 'exercise.gap_fill', 'streak.freeze', 'exam.simulator'] as $feature) {
        expect($pro)->toContain($feature)
            ->and($free)->not->toContain($feature);
    }

    expect(Plan::Pro->newWordsPerDay())->toBeNull()
        ->and(Plan::Free->newWordsPerDay())->not->toBeNull()
        ->and(config('learning.mock_exam.exam_tags'))->toBe(['ielts', 'sat'])
        ->and(config('decks.limits.pro.decks'))->toBeGreaterThan(config('decks.limits.free.decks'))
        ->and(config('decks.limits.pro.deck_words'))->toBeGreaterThan(config('decks.limits.free.deck_words'));
});

it('lists the five real Pro features on the landing', function (string $locale): void {
    // The ticks as the landing shows them: the limits in them are filled in from config (LandingPricing::limits()).
    $features = (new LandingPricing)->limits($locale)['proFeatures'];

    expect($features)->toHaveCount(5)
        ->and(array_unique($features))->toHaveCount(5);

    expect(implode("\n", $features))
        ->toContain('IELTS')
        ->toContain('SAT')
        ->toContain((string) config('decks.limits.pro.decks'))
        ->toContain((string) config('decks.limits.pro.deck_words'));
})->with(['ru', 'uz']);

it('does not promise detailed statistics anywhere on the landing or in the invoice', function (string $locale): void {
    $strings = [
        ...proCopyLandingStrings($locale),
        __('billing.stars.invoice.description_month', [], $locale),
        __('billing.stars.invoice.description_year', [], $locale),
    ];

    foreach ($strings as $string) {
        expect(mb_strtolower($string))->not->toContain('статистик')->not->toContain('statistik');
    }
})->with(['ru', 'uz']);

it('keeps the Telegram Stars invoice description within 255 characters and names the real features', function (string $locale, string $key): void {
    $description = __("billing.stars.invoice.{$key}", [], $locale);

    // Telegram: description 1-255 characters (TelegramStarsProvider truncates silently above that).
    expect(mb_strlen($description))->toBeGreaterThan(0)->toBeLessThanOrEqual(255)
        ->and($description)->toContain('IELTS')->toContain('SAT')
        ->and($description)->toContain((string) config('decks.limits.pro.decks'))
        ->and($description)->toContain((string) config('decks.limits.pro.deck_words'));
})->with(['ru', 'uz'])->with(['description_month', 'description_year']);
