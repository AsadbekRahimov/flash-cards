<?php

declare(strict_types=1);

/*
 * AchievementCardRenderer (docs/13 §7.4, docs/36 §2б): a real 1080×1080 JPEG per key and language (Russian and Uzbek) in the
 * colours of the Mini App, cached by what it shows under an unguessable name in `share-cards/`, written atomically, with the
 * bundled Noto Sans (OFL); the medal while there is no illustration, the designer's file once it is in the directory; the public
 * address Telegram downloads it from.
 */

use App\Domain\Engagement\DTO\CardSpec;
use App\Domain\Engagement\Enums\AchievementKey;
use App\Domain\Engagement\Services\AchievementCardRenderer;
use App\Domain\Engagement\Support\CardPalette;
use App\Models\Achievement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public', ['url' => 'https://muhrla.test/storage']);
    config([
        'app.brand' => 'Muhrla',
        'app.brand_logo' => null,
        'telegram.bot_username' => 'muhrla_bot',
        // No illustrations unless a test puts them there: the medal.
        'achievements.illustrations' => sys_get_temp_dir().'/lf-no-card-art',
    ]);
});

function cardFor(AchievementKey $key, string $locale = 'ru'): string
{
    $achievement = Achievement::factory()->key($key)->create();

    return app(AchievementCardRenderer::class)->render($achievement, $locale);
}

/** @return array{int, int, int} */
function cardPixel(GdImage $image, int $x, int $y): array
{
    $rgb = imagecolorat($image, $x, $y);

    return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
}

/** The colour at a point is `$hex` within the error of the JPEG (flat areas are near-exact at quality 90). */
function cardPixelIs(GdImage $image, int $x, int $y, string $hex, int $tolerance = 6): bool
{
    $expected = CardPalette::rgb($hex);
    foreach (cardPixel($image, $x, $y) as $i => $channel) {
        if (abs($channel - $expected[$i]) > $tolerance) {
            return false;
        }
    }

    return true;
}

function cardImage(string $path): GdImage
{
    $image = imagecreatefromjpeg($path);
    expect($image)->not->toBeFalse();

    return $image;
}

/** Pixels of a box that are far from both the panel white and the medal fill: the strokes of what is drawn there. */
function cardInk(GdImage $image, int $x1, int $y1, int $x2, int $y2): int
{
    $ink = 0;
    for ($x = $x1; $x < $x2; $x += 3) {
        for ($y = $y1; $y < $y2; $y += 3) {
            [$r, $g, $b] = cardPixel($image, $x, $y);
            // Any channel well below the paper: the ink, the muted grey and the reddish accent of the kit (the bot's address) alike.
            $ink += min($r, $g, $b) < 150 ? 1 : 0;
        }
    }

    return $ink;
}

function cardRed(GdImage $image, int $x1, int $y1, int $x2, int $y2): int
{
    $red = 0;
    for ($x = $x1; $x < $x2; $x += 2) {
        for ($y = $y1; $y < $y2; $y += 2) {
            [$r, $g, $b] = cardPixel($image, $x, $y);
            // The red of the fixture (200, 30, 30), not the muhr (228, 87, 46) or the accent (181, 58, 24) the card itself is drawn in.
            $red += $r > 170 && $g < 45 && $b < 60 ? 1 : 0;
        }
    }

    return $red;
}

it('bundles the Noto Sans fonts with their licence', function (): void {
    foreach (['NotoSans-Regular.ttf', 'NotoSans-Bold.ttf', 'OFL.txt'] as $file) {
        expect(is_file(resource_path("fonts/{$file}")))->toBeTrue();
    }
    expect(file_get_contents(resource_path('fonts/OFL.txt')))->toContain('SIL OPEN FONT LICENSE Version 1.1')
        ->and((int) filesize(resource_path('fonts/NotoSans-Regular.ttf')) + (int) filesize(resource_path('fonts/NotoSans-Bold.ttf')))->toBeLessThan(1_048_576);
});

it('renders a 1080x1080 JPEG of a sensible size for every key in both languages, in share-cards/ under an unguessable name', function (): void {
    foreach (AchievementKey::cases() as $key) {
        foreach (['ru', 'uz'] as $locale) {
            $path = cardFor($key, $locale);

            expect(is_file($path))->toBeTrue()
                ->and(dirname($path))->toBe(Storage::disk('public')->path('share-cards'))
                ->and(basename($path))->toMatch(AchievementCardRenderer::FILE_PATTERN)
                ->and(basename($path))->toStartWith("{$key->value}-{$locale}-");
            expect(file_get_contents($path, false, null, 0, 3))->toBe("\xFF\xD8\xFF");
            $info = getimagesize($path);
            expect($info)->not->toBeFalse()
                ->and($info[0])->toBe(1080)
                ->and($info[1])->toBe(1080)
                ->and($info[2])->toBe(IMAGETYPE_JPEG)
                // InlineQueryResultPhoto: a JPEG up to 5 MB.
                ->and(filesize($path))->toBeGreaterThan(30_000)->toBeLessThan(1_000_000);
        }
    }
});

it('renders the Russian and the Uzbek card differently, and an unknown locale as Russian', function (): void {
    $ru = cardFor(AchievementKey::Words100, 'ru');
    $uz = cardFor(AchievementKey::Words100, 'uz');
    $other = cardFor(AchievementKey::Words100, 'en');

    expect($uz)->not->toBe($ru)
        ->and(md5_file($uz))->not->toBe(md5_file($ru))
        ->and($other)->toBe($ru);
});

it('draws the layout in the colours of the Mini App: the field, the framed white panel, the medal with its badge', function (): void {
    $image = cardImage(cardFor(AchievementKey::Streak7, 'uz'));

    // The soft blue field around the panel, the white panel, its frame.
    expect(cardPixelIs($image, 10, 10, CardPalette::BACKGROUND))->toBeTrue()
        ->and(cardPixelIs($image, 1070, 1070, CardPalette::BACKGROUND))->toBeTrue()
        ->and(cardPixelIs($image, 540, 70, CardPalette::PANEL))->toBeTrue()
        ->and(cardPixelIs($image, 61, 540, CardPalette::LINE, 12))->toBeTrue();

    // The medal: a disc of the soft colour in the middle of the art zone, the badge "7" in the accent colour inside it.
    $fill = 0;
    $badge = 0;
    for ($x = 400; $x < 680; $x += 4) {
        for ($y = 260; $y < 540; $y += 4) {
            $fill += cardPixelIs($image, $x, $y, CardPalette::BACKGROUND) ? 1 : 0;
            $badge += cardPixelIs($image, $x, $y, CardPalette::ACCENT, 24) ? 1 : 0;
        }
    }
    expect($fill)->toBeGreaterThan(1_500)->and($badge)->toBeGreaterThan(150);

    // The title is ink on white under the medal; the footer has the tagline and the bot's address.
    expect(cardInk($image, 120, 700, 960, 880))->toBeGreaterThan(300)
        ->and(cardInk($image, 100, 940, 540, 980))->toBeGreaterThan(20);
});

it('draws the illustration of the family instead of the medal once its file is in the directory', function (): void {
    $medal = cardImage($medalPath = cardFor(AchievementKey::Streak7));

    // tests/fixtures/cards/streak.png: a transparent frame with a red square in the middle - fitted into the 480×420 box.
    config(['achievements.illustrations' => base_path('tests/fixtures/cards')]);
    $illustratedPath = cardFor(AchievementKey::Streak7);
    $illustrated = cardImage($illustratedPath);

    $red = fn (GdImage $image): bool => cardRed($image, 530, 410, 550, 430) > 0;

    expect($illustratedPath)->not->toBe($medalPath) // a new file: the illustration is part of the name
        ->and($red($illustrated))->toBeTrue()
        ->and($red($medal))->toBeFalse()
        // The transparent part of the illustration shows the white panel, not black.
        ->and(cardPixelIs($illustrated, 380, 300, CardPalette::PANEL))->toBeTrue();

    // Another family has no file: still the medal.
    $words = cardImage(cardFor(AchievementKey::Words100));
    expect($red($words))->toBeFalse()->and(cardPixelIs($words, 470, 330, CardPalette::BACKGROUND))->toBeTrue();
});

it('keeps the medal when an illustration file is not a readable PNG', function (): void {
    $dir = sys_get_temp_dir().'/lf-card-art-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents("{$dir}/streak.png", 'not a png');
    config(['achievements.illustrations' => $dir]);

    try {
        $image = cardImage(cardFor(AchievementKey::Streak7));
        expect(cardPixelIs($image, 470, 330, CardPalette::BACKGROUND))->toBeTrue();
    } finally {
        @unlink("{$dir}/streak.png");
        @rmdir($dir);
    }
});

it('caches the card: the same texts give the same file, untouched, with no temporary leftovers', function (): void {
    $first = cardFor(AchievementKey::Streak30);
    touch($first, now()->subHours(2)->getTimestamp());
    clearstatcache();
    $before = filemtime($first);
    $second = cardFor(AchievementKey::Streak30);
    clearstatcache();

    expect($second)->toBe($first)
        ->and(filemtime($second))->toBe($before)
        ->and(Storage::disk('public')->allFiles('share-cards'))->toHaveCount(1);
});

it('moves the time of a cached card served again after a day: prune keeps the cards still in use', function (): void {
    $path = cardFor(AchievementKey::Streak30);
    touch($path, now()->subDays(40)->getTimestamp());
    clearstatcache();

    expect(cardFor(AchievementKey::Streak30))->toBe($path);
    clearstatcache();
    expect(filemtime($path))->toBeGreaterThan(now()->subMinute()->getTimestamp());
});

it('draws an old card again when prune deleted it under the touch: no empty file is served', function (): void {
    $path = cardFor(AchievementKey::Streak30);
    $jpeg = (string) file_get_contents($path);
    // What `touch` leaves when achievements:prune-cards deleted the old card between the check and the touch: an empty file.
    file_put_contents($path, '');
    touch($path, now()->subDays(40)->getTimestamp());
    clearstatcache();

    expect(cardFor(AchievementKey::Streak30))->toBe($path);
    clearstatcache();
    expect(filesize($path))->toBe(strlen($jpeg))
        ->and(getimagesize($path)[2] ?? null)->toBe(IMAGETYPE_JPEG)
        ->and(Storage::disk('public')->allFiles('share-cards'))->toHaveCount(1);
});

it('refuses a public address and a card key that end with a newline', function (): void {
    $renderer = app(AchievementCardRenderer::class);
    $path = cardFor(AchievementKey::Streak7);

    expect(preg_match(AchievementCardRenderer::FILE_PATTERN, basename($path)."\n"))->toBe(0)
        ->and($renderer->publicUrl($path."\n"))->toBeNull()
        ->and(fn () => $renderer->renderSpec(new CardSpec("streak_7\n", 'streak', '7', 'T', 'D'), 'ru'))->toThrow(InvalidArgumentException::class);
});

it('renders a new file when a text, the brand or the bot changes', function (): void {
    $before = cardFor(AchievementKey::FirstFriend);

    app('translator')->addLines(['achievements.items.first_friend.title' => 'Друг найден'], 'ru');
    $text = cardFor(AchievementKey::FirstFriend);

    config(['app.brand' => 'Brandy']);
    $brand = cardFor(AchievementKey::FirstFriend);

    config(['telegram.bot_username' => 'other_bot']);
    $bot = cardFor(AchievementKey::FirstFriend);

    expect(array_unique([$before, $text, $brand, $bot]))->toHaveCount(4)
        ->and(is_file($before))->toBeTrue();
});

it('draws the logo of the brand when one is configured, and skips a missing logo file', function (): void {
    $plain = cardFor(AchievementKey::Streak7);

    config(['app.brand_logo' => '/nonexistent/logo.png']);
    expect(cardFor(AchievementKey::Streak7))->toBe($plain);

    config(['app.brand_logo' => base_path('tests/fixtures/cards/streak.png')]);
    $logo = cardFor(AchievementKey::Streak7);
    expect($logo)->not->toBe($plain)
        // The red square of the fixture shrinks into the header, left of the brand name.
        ->and(cardRed(cardImage($logo), 0, 90, 1080, 170))->toBeGreaterThan(20)
        ->and(cardRed(cardImage($plain), 0, 90, 1080, 170))->toBe(0);
});

it('draws a card without the bot address when the bot has no username', function (): void {
    config(['telegram.bot_username' => '']);
    $image = cardImage(cardFor(AchievementKey::Streak7));

    // The right half of the footer stays white.
    expect(cardInk($image, 700, 940, 980, 980))->toBe(0);
});

it('refuses an unknown key or a key that cannot be a file name, and reports missing fonts', function (): void {
    $unknown = Achievement::factory()->create(['key' => 'course_completed:x']);
    expect(fn () => app(AchievementCardRenderer::class)->render($unknown, 'ru'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(AchievementCardRenderer::class)->renderSpec(new CardSpec('../x', 'game', 'B', 'T', 'D'), 'ru'))->toThrow(InvalidArgumentException::class);

    config(['achievements.fonts.bold' => resource_path('fonts/missing.ttf')]);
    expect(fn () => cardFor(AchievementKey::Streak7))->toThrow(RuntimeException::class, 'fonts are missing');
});

it('cuts a title over 2 lines and a description over 3 with an ellipsis', function (): void {
    $renderer = app(AchievementCardRenderer::class);
    $spec = new CardSpec('long', 'game', 'BOSS', str_repeat('Очень длинный заголовок ', 4), str_repeat('Описание из многих слов подряд ', 8));

    $lines = $renderer->lines($spec);
    expect(count($lines['title']))->toBeGreaterThan(AchievementCardRenderer::TITLE_LINES)
        ->and(count($lines['description']))->toBeGreaterThan(AchievementCardRenderer::DESCRIPTION_LINES);

    // It still renders: the text block keeps to its place and the medal shrinks into what is left.
    $image = cardImage($renderer->renderSpec($spec, 'ru'));
    expect(cardPixelIs($image, 540, 1050, CardPalette::BACKGROUND))->toBeTrue()
        ->and(cardPixelIs($image, 540, 70, CardPalette::PANEL))->toBeTrue();
});

it('draws any card from a CardSpec (the game cards), and the achievement card is the same spec drawn', function (): void {
    $renderer = app(AchievementCardRenderer::class);
    $spec = new CardSpec('boss_3', 'game', 'BOSS', 'Я дошёл до Самарканда', 'Победил(а) хранителя ворот в игре «Караван»');

    $path = $renderer->renderSpec($spec, 'ru');
    $info = getimagesize($path);

    expect(basename($path))->toStartWith('boss_3-ru-')
        ->and($info[0] ?? null)->toBe(1080)->and($info[2] ?? null)->toBe(IMAGETYPE_JPEG)
        ->and($renderer->renderSpec($spec, 'ru'))->toBe($path)
        ->and($renderer->renderSpec(new CardSpec('boss_3', 'game', 'BOSS', 'Я дошёл до Бухары', $spec->description), 'ru'))->not->toBe($path)
        ->and(basename($renderer->renderSpec($spec, 'uz')))->toStartWith('boss_3-uz-')
        // A label of its own (or none) is part of what the card shows.
        ->and($renderer->renderSpec(new CardSpec('boss_3', 'game', 'BOSS', $spec->title, $spec->description, ''), 'ru'))->not->toBe($path);

    // render(Achievement) is renderSpec of the texts of its key: the same file
    $achievement = Achievement::factory()->key(AchievementKey::Streak7)->create();
    $key = AchievementKey::Streak7;
    $same = $renderer->renderSpec(new CardSpec($key->value, $key->family(), $key->badge(), $key->title('ru'), $key->description('ru')), 'ru');
    expect($renderer->render($achievement, 'ru'))->toBe($same);
});

// --- publicUrl -------------------------------------------------------------------------------------------------------------

it('gives the https address of a card on the public disk under /storage/share-cards/', function (): void {
    $path = cardFor(AchievementKey::Streak7);
    $url = app(AchievementCardRenderer::class)->publicUrl($path);

    expect($url)->toBe('https://muhrla.test/storage/share-cards/'.basename($path))
        // The address nginx lets through (docker/nginx/*.conf) and no learner in it.
        ->and(preg_match('#^/storage/share-cards/[a-z0-9_]{1,40}-(ru|uz)-[0-9a-f]{20}\.jpg$#', (string) parse_url((string) $url, PHP_URL_PATH)))->toBe(1);
});

it('gives no address when APP_URL is not https, the disk is not public, or the path is not a card', function (): void {
    $renderer = app(AchievementCardRenderer::class);
    $path = cardFor(AchievementKey::Streak7);

    expect($renderer->publicUrl(Storage::disk('public')->path('share-cards/../secret.jpg')))->toBeNull()
        ->and($renderer->publicUrl(Storage::disk('public')->path('audio/x.mp3')))->toBeNull()
        ->and($renderer->publicUrl($path.'.tmp'))->toBeNull()
        ->and($renderer->publicUrl('/etc/passwd'))->toBeNull();

    Storage::fake('public', ['url' => 'http://muhrla.test/storage']);
    expect($renderer->publicUrl(Storage::disk('public')->path('share-cards/'.basename($path))))->toBeNull();

    Storage::fake('cards', ['url' => 'https://muhrla.test/cards']);
    config(['achievements.disk' => 'cards']);
    $other = cardFor(AchievementKey::Streak7);
    expect($renderer->publicUrl($other))->toBeNull();
});
