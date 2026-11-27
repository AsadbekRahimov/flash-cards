<?php

declare(strict_types=1);

/*
 * The texts of the share cards (docs/36 §2б, A2/A3): every achievement key, every city of the "Caravan" (docs/18 §12.3.4) and the
 * card of the brand and the picture of a winner of the month (A5) fit their lines in Russian and Uzbek; the fonts have a glyph for every letter of those texts; the colours of
 * the card are the tokens of the Mini App.
 */

use App\Domain\Engagement\DTO\CardSpec;
use App\Domain\Engagement\Enums\AchievementKey;
use App\Domain\Engagement\Enums\ScoreCategory;
use App\Domain\Engagement\Services\AchievementCardRenderer;
use App\Domain\Engagement\Services\InviteCardBuilder;
use App\Domain\Engagement\Services\MonthWinnerCardBuilder;
use App\Domain\Engagement\Services\StoryCardRenderer;
use App\Domain\Engagement\Services\StoryShareService;
use App\Domain\Engagement\Support\CardPalette;
use App\Domain\Game\Support\CityNames;
use App\Models\LeagueMonth;

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

it('fits the picture of a winner of the month: every month, places 1-3, v1 and both tables of v2', function (string $locale): void {
    $builder = app(MonthWinnerCardBuilder::class);
    $bold = (string) config('achievements.fonts.bold');

    for ($number = 1; $number <= 12; $number++) {
        $month = new LeagueMonth(['starts_on' => sprintf('2026-%02d-01', $number)]);
        foreach ([1, 2, 3] as $place) {
            foreach ([null, ScoreCategory::Free, ScoreCategory::Pro] as $category) {
                $spec = $builder->spec($month, $place, 123456, $category, $locale);
                $where = "month {$number}, place {$place}, ".($category->value ?? 'v1')." ({$locale})";
                assertCardFits($spec, $where);

                // The label is one spaced line of capitals (28 pt, 5 px between letters): it must not run off the panel either.
                $label = mb_strtoupper((string) $spec->label);
                $box = imagettfbbox(28, 0, $bold, $label);
                expect($box)->not->toBeFalse()
                    ->and(abs($box[2] - $box[0]) + 5 * mb_strlen($label))->toBeLessThanOrEqual(AchievementCardRenderer::TEXT_WIDTH, "{$where}: the label «{$label}» is wider than the card");
            }
        }
    }
})->with(['ru', 'uz']);

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
    for ($number = 1; $number <= 12; $number++) {
        foreach ([null, ScoreCategory::Free, ScoreCategory::Pro] as $category) {
            $spec = app(MonthWinnerCardBuilder::class)->spec(new LeagueMonth(['starts_on' => sprintf('2026-%02d-01', $number)]), 3, 1234567890, $category, $locale);
            $texts[] = $spec->title.' '.$spec->description.' '.$spec->label;
        }
    }
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

it('copies the colours of the story card from the kit: the ink and the paper of the light theme, the muted and the muhr of the dark pair', function (): void {
    $css = (string) file_get_contents(base_path('resources/twa/src/style.css'));
    $dark = strpos($css, ":root[data-theme='dark'] {");
    expect($dark)->not->toBeFalse();

    foreach (CardPalette::STORY_TOKENS as ['token' => $token, 'theme' => $theme, 'hex' => $hex]) {
        $haystack = $theme === 'dark' ? substr($css, (int) $dark) : $css;
        expect(preg_match('/'.preg_quote($token, '/').':\s*(#[0-9a-fA-F]{6})\s*;/', $haystack, $m))->toBe(1, "style.css ({$theme}) has no {$token}")
            ->and(strtolower($m[1]))->toBe($hex, "{$token} of style.css ({$theme}) is {$m[1]}, CardPalette has {$hex}");
    }
});

// --- The story card (docs/40 §2.4) ----------------------------------------------------------------------------------------------

/** Fails with the card and the text when a story card would cut its title or its description. */
function assertStoryFits(CardSpec $spec, string $where): void
{
    $lines = app(StoryCardRenderer::class)->lines($spec);

    expect(count($lines['title']))->toBeLessThanOrEqual(StoryCardRenderer::TITLE_LINES, "{$where}: the title «{$spec->title}» takes ".count($lines['title']).' lines')
        ->and(count($lines['description']))->toBeLessThanOrEqual(StoryCardRenderer::DESCRIPTION_LINES, "{$where}: the description «{$spec->description}» takes ".count($lines['description']).' lines');

    foreach ([[$lines['title'], 'bold', StoryCardRenderer::TITLE_SIZE], [$lines['description'], 'regular', StoryCardRenderer::DESCRIPTION_SIZE]] as [$rows, $font, $size]) {
        foreach ($rows as $row) {
            $box = imagettfbbox($size, 0, (string) config("achievements.fonts.{$font}"), $row);
            expect($box)->not->toBeFalse()
                ->and(abs($box[2] - $box[0]))->toBeLessThanOrEqual(StoryCardRenderer::TEXT_WIDTH, "{$where}: «{$row}» is wider than the story");
        }
    }
}

/** The story of a series of `$days` with the goal `$goal`, as StoryShareService builds it. */
function streakStorySpec(int $days, int $goal, string $locale): CardSpec
{
    $daysText = trans_choice('share.story.days', $days, ['count' => $days], $locale);

    return new CardSpec(
        'streak_'.$days,
        'streak',
        (string) $days,
        __('share.story.streak.title', ['days' => $daysText], $locale),
        __('share.story.streak.description', ['cards' => trans_choice('share.story.cards', $goal, ['count' => $goal], $locale)], $locale),
        '',
    );
}

it('fits the story of every series length 1…365 with every goal of the day, and of every achievement', function (string $locale): void {
    foreach ([10, 20, 40] as $goal) {
        foreach ([1, 2, 3, 4, 5, 7, 11, 21, 22, 25, 30, 99, 100, 101, 111, 121, 200, 365] as $days) {
            assertStoryFits(streakStorySpec($days, $goal, $locale), "streak {$days}, goal {$goal} ({$locale})");
        }
    }
    foreach (AchievementKey::cases() as $key) {
        assertStoryFits(new CardSpec($key->value, $key->family(), $key->badge(), $key->title($locale), $key->description($locale), ''), "story {$key->value} ({$locale})");
    }
})->with(['ru', 'uz']);

it('keeps the caption of a story within 200 characters for every series length and achievement, with the longest link', function (string $locale): void {
    $link = 'https://t.me/'.str_repeat('b', 32).'?start=ref_'.str_repeat('X', 16);
    $limit = StoryShareService::TEXT_LIMIT - mb_strlen(' · '.$link);

    for ($days = 1; $days <= 365; $days++) {
        $text = __('share.story.streak.text', ['days' => trans_choice('share.story.days', $days, ['count' => $days], $locale)], $locale);
        expect(mb_strlen($text))->toBeLessThanOrEqual($limit, "streak {$days} ({$locale})");
    }
    foreach (AchievementKey::cases() as $key) {
        $text = __('share.story.achievement.text', ['title' => $key->title($locale)], $locale);
        expect(mb_strlen($text))->toBeLessThanOrEqual($limit, "{$key->value} ({$locale})");
    }
})->with(['ru', 'uz']);

it('has a glyph for every character of the story texts in both fonts', function (string $locale): void {
    $texts = [(string) config('app.brand'), '@abcdefghijklmnopqrstuvwxyz_0123456789 · …'];
    foreach ([1, 2, 5, 21, 365] as $days) {
        $spec = streakStorySpec($days, 20, $locale);
        $texts[] = $spec->title.' '.$spec->description.' '.__('share.story.streak.text', ['days' => trans_choice('share.story.days', $days, ['count' => $days], $locale)], $locale);
    }
    foreach ([1, 2, 5] as $goal) {
        $texts[] = trans_choice('share.story.cards', $goal, ['count' => $goal], $locale);
    }
    $texts[] = __('share.story.achievement.text', ['title' => 'T'], $locale);
    $characters = array_unique(mb_str_split(preg_replace('/\s+/u', '', implode(' ', $texts)) ?? ''));

    foreach (['regular', 'bold'] as $kind) {
        $font = (string) config("achievements.fonts.{$kind}");
        $notdef = glyphPixels($font, "\u{E000}");
        $missing = array_values(array_filter($characters, fn (string $char): bool => glyphPixels($font, $char) === $notdef));

        expect($missing)->toBe([], "{$kind} has no glyph for: ".implode(' ', $missing));
    }
})->with(['ru', 'uz']);
