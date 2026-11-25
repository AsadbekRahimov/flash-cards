<?php

declare(strict_types=1);

/*
 * The texts of the share cards (docs/36 §2б, A2/A3): every achievement key, every city of the "Caravan" (docs/18 §12.3.4) and the
 * card of the brand fit their lines in Russian and Uzbek; the fonts have a glyph for every letter of those texts; the colours of
 * the card are the tokens of the Mini App.
 */

use App\Domain\Engagement\DTO\CardSpec;
use App\Domain\Engagement\Enums\AchievementKey;
use App\Domain\Engagement\Services\AchievementCardRenderer;
use App\Domain\Engagement\Services\InviteCardBuilder;
use App\Domain\Engagement\Support\CardPalette;
use App\Domain\Game\Support\CityNames;

/** Fails with the card and the text when a card would cut its title or its description. */
function assertCardFits(CardSpec $spec, string $where): void
{
    $lines = app(AchievementCardRenderer::class)->lines($spec);

    expect(count($lines['title']))->toBeLessThanOrEqual(AchievementCardRenderer::TITLE_LINES, "{$where}: the title «{$spec->title}» takes ".count($lines['title']).' lines')
        ->and(count($lines['description']))->toBeLessThanOrEqual(AchievementCardRenderer::DESCRIPTION_LINES, "{$where}: the description «{$spec->description}» takes ".count($lines['description']).' lines');

    // No single word wider than the line either (the wrap cannot break it).
    foreach ([[$lines['title'], 'bold', AchievementCardRenderer::TITLE_SIZE], [$lines['description'], 'regular', AchievementCardRenderer::DESCRIPTION_SIZE]] as [$rows, $font, $size]) {
        foreach ($rows as $row) {
            $box = imagettfbbox($size, 0, (string) config("achievements.fonts.{$font}"), $row);
            expect($box)->not->toBeFalse()
                ->and(abs($box[2] - $box[0]))->toBeLessThanOrEqual(AchievementCardRenderer::TEXT_WIDTH, "{$where}: «{$row}» is wider than the card");
        }
    }
}

it('fits every achievement key: the title in 2 lines, the description in 3', function (string $locale): void {
    foreach (AchievementKey::cases() as $key) {
        assertCardFits(new CardSpec($key->value, $key->family(), $key->badge(), $key->title($locale), $key->description($locale)), "{$key->value} ({$locale})");
    }
})->with(['ru', 'uz']);

it('fits the card of every city of the Caravan', function (string $locale): void {
    for ($number = 1; $number <= CityNames::COUNT; $number++) {
        $title = __('game.card.title', ['city' => CityNames::toward($number, $locale)], $locale);
        assertCardFits(new CardSpec('boss_'.$number, 'game', 'BOSS', $title, __('game.card.description', [], $locale)), "boss_{$number} ({$locale})");
    }
})->with(['ru', 'uz']);

it('fits the card of the brand with and without a welcome trial', function (string $locale, int $trial): void {
    config(['billing.trial.days' => $trial]);

    assertCardFits(app(InviteCardBuilder::class)->spec($locale), "invite ({$locale}, trial {$trial})");
})->with(['ru', 'uz'])->with([14, 0, 30]);

/** The letters a card can show: titles, descriptions, labels, the tagline, the card of the brand and the cities. */
function cardCharacters(string $locale): string
{
    $texts = [__('achievements.card.label', [], $locale), __('achievements.card.tagline', [], $locale), (string) config('app.brand')];
    foreach (AchievementKey::cases() as $key) {
        $texts[] = $key->title($locale).' '.$key->description($locale).' '.$key->badge();
    }
    foreach (['label', 'title', 'description', 'description_plain'] as $line) {
        $texts[] = __("achievements.card.invite.{$line}", ['trial' => '0123456789'], $locale);
    }
    $texts[] = trans_choice('referrals.days', 14, ['count' => 14], $locale).' '.trans_choice('referrals.days', 21, ['count' => 21], $locale).' '.trans_choice('referrals.days', 2, ['count' => 2], $locale);
    $texts[] = __('game.card.description', [], $locale);
    for ($number = 1; $number <= CityNames::COUNT; $number++) {
        $texts[] = __('game.card.title', ['city' => CityNames::toward($number, $locale)], $locale);
    }
    $texts[] = 't.me/abcdefghijklmnopqrstuvwxyz_0123456789…';

    $all = implode(' ', $texts);

    return $all.' '.mb_strtoupper($all);
}

/** The pixels of one character drawn alone: a missing glyph draws the `.notdef` box. */
function glyphPixels(string $font, string $char): string
{
    $image = imagecreatetruecolor(120, 120);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
    imagettftext($image, 60, 0, 20, 90, (int) imagecolorallocate($image, 0, 0, 0), $font, $char);
    ob_start();
    imagepng($image);

    return md5((string) ob_get_clean());
}

it('has a glyph for every character of the card texts in both fonts', function (string $locale): void {
    $characters = array_unique(mb_str_split(preg_replace('/\s+/u', '', cardCharacters($locale)) ?? ''));

    foreach (['regular', 'bold'] as $kind) {
        $font = (string) config("achievements.fonts.{$kind}");
        // U+E000 is a private-use code point no font of the card has: what its `.notdef` looks like.
        $notdef = glyphPixels($font, "\u{E000}");
        $missing = array_values(array_filter($characters, fn (string $char): bool => glyphPixels($font, $char) === $notdef));

        expect($missing)->toBe([], "{$kind} has no glyph for: ".implode(' ', $missing));
    }
})->with(['ru', 'uz']);

it('notices a missing glyph (the check itself works)', function (): void {
    $font = (string) config('achievements.fonts.regular');

    expect(glyphPixels($font, "\u{E000}"))->toBe(glyphPixels($font, "\u{E001}"))
        ->and(glyphPixels($font, 'Ж'))->not->toBe(glyphPixels($font, "\u{E000}"));
});

it('copies the light-theme colours of the Mini App (style.css)', function (): void {
    $css = (string) file_get_contents(base_path('resources/twa/src/style.css'));

    foreach (CardPalette::TOKENS as $token => $hex) {
        // The first declaration is the light theme (`:root`); the dark one comes later under its media query / class.
        expect(preg_match('/'.preg_quote($token, '/').':\s*(#[0-9a-fA-F]{6})\s*;/', $css, $m))->toBe(1, "style.css has no {$token}")
            ->and(strtolower($m[1]))->toBe($hex, "{$token} of style.css is {$m[1]}, CardPalette has {$hex}");
    }
});
