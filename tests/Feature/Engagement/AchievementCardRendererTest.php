<?php

declare(strict_types=1);

/*
 * AchievementCardRenderer (docs/13 §7.4): a real 1080×1080 PNG per key and language (Russian and Uzbek),
 * cached by (key, locale, texts), written atomically, with the bundled Noto Sans (OFL).
 */

use App\Domain\Engagement\Enums\AchievementKey;
use App\Domain\Engagement\Services\AchievementCardRenderer;
use App\Models\Achievement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
});

function cardFor(AchievementKey $key, string $locale = 'ru'): string
{
    $achievement = Achievement::factory()->key($key)->create();

    return app(AchievementCardRenderer::class)->render($achievement, $locale);
}

it('bundles the Noto Sans fonts with their licence', function (): void {
    foreach (['NotoSans-Regular.ttf', 'NotoSans-Bold.ttf', 'OFL.txt'] as $file) {
        expect(is_file(resource_path("fonts/{$file}")))->toBeTrue();
    }
    expect(file_get_contents(resource_path('fonts/OFL.txt')))->toContain('SIL OPEN FONT LICENSE Version 1.1')
        ->and((int) filesize(resource_path('fonts/NotoSans-Regular.ttf')) + (int) filesize(resource_path('fonts/NotoSans-Bold.ttf')))->toBeLessThan(1_048_576);
});

it('renders a 1080x1080 PNG of a sensible size for every key in both languages', function (): void {
    foreach (AchievementKey::cases() as $key) {
        foreach (['ru', 'uz'] as $locale) {
            $path = cardFor($key, $locale);

            expect(is_file($path))->toBeTrue();
            expect(file_get_contents($path, false, null, 0, 8))->toBe("\x89PNG\r\n\x1a\n");
            $info = getimagesize($path);
            expect($info)->not->toBeFalse()
                ->and($info[0])->toBe(1080)
                ->and($info[1])->toBe(1080)
                ->and($info[2])->toBe(IMAGETYPE_PNG)
                ->and(filesize($path))->toBeGreaterThan(8_000)->toBeLessThan(1_500_000); // telegram: photo <= 10 MB
        }
    }
});

it('renders the Russian and the Uzbek card differently, and an unknown locale as Russian', function (): void {
    $ru = cardFor(AchievementKey::Words100, 'ru');
    $uz = cardFor(AchievementKey::Words100, 'uz');
    $other = cardFor(AchievementKey::Words100, 'en');

    expect($uz)->not->toBe($ru)
        ->and(md5_file($uz))->not->toBe(md5_file($ru))
        ->and(md5_file($other))->toBe(md5_file($ru))
        ->and(basename($other))->toStartWith('words_100-ru-');
});

it('draws the text: the card is not a flat gradient (the glyphs of the badge and the title are present)', function (): void {
    $image = imagecreatefrompng(cardFor(AchievementKey::Streak7, 'uz'));
    expect($image)->not->toBeFalse();

    // The medal is a white disc in the middle; its centre must be mostly white with ink pixels of the badge.
    $white = 0;
    $dark = 0;
    for ($x = 440; $x < 640; $x += 4) {
        for ($y = 300; $y < 500; $y += 4) {
            $rgb = imagecolorat($image, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            $white += $r > 240 && $g > 240 && $b > 240 ? 1 : 0;
            $dark += $r < 150 && $g < 100 ? 1 : 0;
        }
    }
    expect($white)->toBeGreaterThan(500)->and($dark)->toBeGreaterThan(20);
});

it('caches the card: the same key, locale and texts give the same file, untouched', function (): void {
    $first = cardFor(AchievementKey::Streak30);
    touch($first, 1_700_000_000);
    clearstatcache();
    $second = cardFor(AchievementKey::Streak30);
    clearstatcache();

    expect($second)->toBe($first)
        ->and(filemtime($second))->toBe(1_700_000_000)
        ->and(Storage::disk('public')->allFiles('achievements'))->toHaveCount(1); // no temporary leftovers
});

it('renders a new file when a text changes', function (): void {
    $before = cardFor(AchievementKey::FirstFriend);
    app('translator')->addLines(['achievements.items.first_friend.title' => 'Друг найден'], 'ru');
    $after = cardFor(AchievementKey::FirstFriend);

    expect($after)->not->toBe($before)->and(is_file($before))->toBeTrue()->and(is_file($after))->toBeTrue();
});

it('refuses an unknown key and reports missing fonts', function (): void {
    $unknown = Achievement::factory()->create(['key' => 'course_completed:x']);
    expect(fn () => app(AchievementCardRenderer::class)->render($unknown, 'ru'))->toThrow(InvalidArgumentException::class);

    config(['achievements.fonts.bold' => resource_path('fonts/missing.ttf')]);
    expect(fn () => cardFor(AchievementKey::Streak7))->toThrow(RuntimeException::class, 'fonts are missing');
});
