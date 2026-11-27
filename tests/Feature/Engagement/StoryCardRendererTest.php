<?php

declare(strict_types=1);

/*
 * StoryCardRenderer (docs/40 §2.4, F3): a real 1080×1920 JPEG on the ink of the kit - the stamp (muhr ring, paper disc), the
 * title, the description and «Muhrla · @bot» - under `story_<key>-<locale>-<hash20>.jpg` in `share-cards/` (the name nginx lets
 * through and the pruner clears), cached by what it shows, with the illustration of the family once its file is there. Not a
 * byte-for-byte reference picture (GD / FreeType / libjpeg differ a little between machines): the format, the flat colours at the
 * fixed points of the layout and non-empty text zones.
 */

use App\Domain\Engagement\DTO\CardSpec;
use App\Domain\Engagement\Services\AchievementCardRenderer;
use App\Domain\Engagement\Services\ShareCardPruner;
use App\Domain\Engagement\Services\StoryCardRenderer;
use App\Domain\Engagement\Support\CardPalette;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('public', ['url' => 'https://muhrla.test/storage']);
    config([
        'app.brand' => 'Muhrla',
        'app.brand_logo' => null,
        'telegram.bot_username' => 'muhrla_bot',
        'achievements.illustrations' => sys_get_temp_dir().'/lf-no-card-art',
    ]);
});

function storySpec(string $key = 'streak_7', string $title = '7 дней подряд', string $description = 'Учу английский в Muhrla — каждый день по 20 карточек'): CardSpec
{
    return new CardSpec($key, 'streak', '7', $title, $description, '');
}

function storyRender(CardSpec $spec, string $locale = 'ru'): string
{
    return app(StoryCardRenderer::class)->renderSpec($spec, $locale);
}

/** @return array{int, int, int} */
function storyPixel(GdImage $image, int $x, int $y): array
{
    $rgb = imagecolorat($image, $x, $y);

    return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
}

function storyPixelIs(GdImage $image, int $x, int $y, string $hex, int $tolerance = 6): bool
{
    $expected = CardPalette::rgb($hex);
    foreach (storyPixel($image, $x, $y) as $i => $channel) {
        if (abs($channel - $expected[$i]) > $tolerance) {
            return false;
        }
    }

    return true;
}

/** Light pixels (the paper of the texts, the muhr) on the ink in a box. */
function storyLight(GdImage $image, int $x1, int $y1, int $x2, int $y2): int
{
    $light = 0;
    for ($x = $x1; $x < $x2; $x += 3) {
        for ($y = $y1; $y < $y2; $y += 3) {
            $light += max(storyPixel($image, $x, $y)) > 120 ? 1 : 0;
        }
    }

    return $light;
}

function storyImage(string $path): GdImage
{
    $image = imagecreatefromjpeg($path);
    expect($image)->not->toBeFalse();

    return $image;
}

it('renders a 1080x1920 JPEG in share-cards/ under story_<key>-<locale>-<hash>.jpg, in both languages', function (string $locale): void {
    $path = storyRender(storySpec(), $locale);

    expect(is_file($path))->toBeTrue()
        ->and(dirname($path))->toBe(Storage::disk('public')->path('share-cards'))
        ->and(basename($path))->toStartWith("story_streak_7-{$locale}-")
        // The 1:1 card's pattern, and the rule of nginx (docker/nginx/*.conf) are the same: the story is served and pruned like a card.
        ->and(basename($path))->toMatch(AchievementCardRenderer::FILE_PATTERN)
        ->and(preg_match('#^[a-z0-9_]{1,40}-(ru|uz)-[0-9a-f]{20}\.jpg$#', basename($path)))->toBe(1)
        ->and(file_get_contents($path, false, null, 0, 3))->toBe("\xFF\xD8\xFF");

    $info = getimagesize($path);
    expect($info)->not->toBeFalse()
        ->and([$info[0], $info[1], $info[2]])->toBe([1080, 1920, IMAGETYPE_JPEG])
        ->and(filesize($path))->toBeGreaterThan(20_000)->toBeLessThan(1_000_000);
})->with(['ru', 'uz']);

it('draws the ink edge to edge, the stamp with its paper disc and the badge, and the texts under it', function (): void {
    $image = storyImage(storyRender(storySpec()));

    // The ink in the corners and along the edges (the UI of Telegram covers the top and the bottom - nothing is drawn there).
    foreach ([[8, 8], [1071, 8], [8, 1911], [1071, 1911], [540, 100], [540, 1800], [30, 960], [1050, 960]] as [$x, $y]) {
        expect(storyPixelIs($image, $x, $y, CardPalette::STORY_BACKGROUND))->toBeTrue("ink at {$x},{$y}");
    }

    // The muhr ring (outer diameter 600 around 540,700), the paper disc inside, the ink just outside.
    expect(storyPixelIs($image, 540, 700 - 300 + 8, CardPalette::STORY_MUHR, 10))->toBeTrue('ring top')
        ->and(storyPixelIs($image, 540 - 300 + 8, 700, CardPalette::STORY_MUHR, 10))->toBeTrue('ring left')
        ->and(storyPixelIs($image, 540, 700 - 300 - 8, CardPalette::STORY_BACKGROUND))->toBeTrue('ink above the ring')
        ->and(storyPixelIs($image, 540 - 150, 700 - 150, CardPalette::STORY_TEXT))->toBeTrue('paper inside the disc');

    // The badge "7" in the accent colour in the middle of the disc.
    $badge = 0;
    for ($x = 440; $x < 640; $x += 3) {
        for ($y = 600; $y < 800; $y += 3) {
            $badge += storyPixelIs($image, $x, $y, CardPalette::ACCENT, 28) ? 1 : 0;
        }
    }
    expect($badge)->toBeGreaterThan(200);

    // The title (paper on ink), the description (muted) and «Muhrla · @muhrla_bot»: the text zones are not empty; the air between is.
    expect(storyLight($image, 120, 1050, 960, 1150))->toBeGreaterThan(300)
        ->and(storyLight($image, 120, 1180, 960, 1320))->toBeGreaterThan(200)
        ->and(storyLight($image, 200, 1300, 880, 1460))->toBeGreaterThan(60)
        ->and(storyLight($image, 0, 1600, 1080, 1900))->toBe(0);
});

it('renders the Russian and the Uzbek card differently, an unknown locale as Russian, and keeps the 1:1 card apart', function (): void {
    $ru = storyRender(storySpec());
    $uz = storyRender(storySpec(title: '7 kun ketma-ket', description: "Muhrla'da ingliz tilini o'rganyapman — har kuni 20 ta kartochka"), 'uz');
    $other = storyRender(storySpec(), 'en');

    expect($uz)->not->toBe($ru)
        ->and(basename($uz))->toStartWith('story_streak_7-uz-')
        ->and($other)->toBe($ru);

    $card = app(AchievementCardRenderer::class)->renderSpec(storySpec(), 'ru');
    expect(basename($card))->toStartWith('streak_7-ru-')->and($card)->not->toBe($ru);
});

it('caches the card: the same texts give the same file, a changed text, brand or bot a new one', function (): void {
    $first = storyRender(storySpec());
    touch($first, now()->subHours(2)->getTimestamp());
    clearstatcache();
    $before = filemtime($first);

    expect(storyRender(storySpec()))->toBe($first);
    clearstatcache();
    expect(filemtime($first))->toBe($before);

    $text = storyRender(storySpec(title: '8 дней подряд'));
    config(['app.brand' => 'Brandy']);
    $brand = storyRender(storySpec());
    config(['telegram.bot_username' => 'other_bot']);
    $bot = storyRender(storySpec());

    expect(array_unique([$first, $text, $brand, $bot]))->toHaveCount(4)
        ->and(Storage::disk('public')->allFiles('share-cards'))->toHaveCount(4);
});

it('draws the illustration of the family inside the disc once its file is in the directory', function (): void {
    $plain = storyRender(storySpec());

    // tests/fixtures/cards/streak.png: a transparent frame with a red square in the middle - fitted into the disc.
    config(['achievements.illustrations' => base_path('tests/fixtures/cards')]);
    $illustratedPath = storyRender(storySpec());
    $illustrated = storyImage($illustratedPath);

    $red = 0;
    for ($x = 520; $x < 560; $x += 2) {
        for ($y = 680; $y < 720; $y += 2) {
            [$r, $g, $b] = storyPixel($illustrated, $x, $y);
            $red += $r > 170 && $g < 45 && $b < 60 ? 1 : 0;
        }
    }

    expect($illustratedPath)->not->toBe($plain)
        ->and($red)->toBeGreaterThan(50)
        // The disc around the illustration (and the transparent margin of the fixture, 360,540) is still the paper, not black.
        ->and(storyPixelIs($illustrated, 300, 700, CardPalette::STORY_TEXT))->toBeTrue()
        ->and(storyPixelIs($illustrated, 360, 540, CardPalette::STORY_TEXT))->toBeTrue();
});

it('cuts a title over 2 lines and a description over 3 with an ellipsis and still keeps the footer inside the safe zone', function (): void {
    $renderer = app(StoryCardRenderer::class);
    $spec = new CardSpec('long', 'streak', '1', str_repeat('Очень длинный заголовок ', 4), str_repeat('Описание из многих слов подряд ', 8), '');

    $lines = $renderer->lines($spec);
    expect(count($lines['title']))->toBeGreaterThan(StoryCardRenderer::TITLE_LINES)
        ->and(count($lines['description']))->toBeGreaterThan(StoryCardRenderer::DESCRIPTION_LINES);

    $image = storyImage($renderer->renderSpec($spec, 'ru'));
    expect(storyLight($image, 0, 1640, 1080, 1920))->toBe(0)
        ->and(storyLight($image, 120, 1050, 960, 1600))->toBeGreaterThan(500);
});

it('refuses a key that cannot be a file name, and a key too long with the story prefix', function (): void {
    $renderer = app(StoryCardRenderer::class);

    expect(fn () => $renderer->renderSpec(new CardSpec('../x', 'streak', '1', 'T', 'D'), 'ru'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $renderer->renderSpec(new CardSpec(str_repeat('a', 35), 'streak', '1', 'T', 'D'), 'ru'))->toThrow(InvalidArgumentException::class)
        ->and(basename($renderer->renderSpec(new CardSpec(str_repeat('a', 34), 'streak', '1', 'T', 'D'), 'ru')))->toMatch(AchievementCardRenderer::FILE_PATTERN);
});

it('gives the story the public address of the cards, and the pruner clears an old story like a card', function (): void {
    $path = storyRender(storySpec());
    $url = app(AchievementCardRenderer::class)->publicUrl($path);

    expect($url)->toBe('https://muhrla.test/storage/share-cards/'.basename($path));

    touch($path, now()->subDays(31)->subMinute()->getTimestamp());
    clearstatcache();
    $report = app(ShareCardPruner::class)->prune(30);

    expect($report['deleted'])->toBe(1)->and(is_file($path))->toBeFalse();
});
