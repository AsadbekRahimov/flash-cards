<?php

declare(strict_types=1);

/*
 * The card of the brand (docs/36 §2б, A4) and the fixed points of the layout. Not a byte-for-byte reference picture: GD / FreeType /
 * libjpeg of the container and of CI anti-alias and compress a little differently, so the test checks what does not depend on them -
 * the format and size, the flat colours at the fixed points of the layout (the field, the panel, its frame, the medal) and that the
 * text zones are not empty.
 */

use App\Domain\Engagement\Services\AchievementCardRenderer;
use App\Domain\Engagement\Services\InviteCardBuilder;
use App\Domain\Engagement\Support\CardPalette;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('public', ['url' => 'https://muhrla.test/storage']);
    config([
        'app.brand' => 'Muhrla',
        'app.brand_logo' => null,
        'telegram.bot_username' => 'muhrla_bot',
        'billing.trial.days' => 14,
        'achievements.illustrations' => sys_get_temp_dir().'/lf-no-card-art',
    ]);
});

/** @return array{int, int, int} */
function snapPixel(GdImage $image, int $x, int $y): array
{
    $rgb = imagecolorat($image, $x, $y);

    return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
}

function snapIs(GdImage $image, int $x, int $y, string $hex, int $tolerance = 6): bool
{
    $expected = CardPalette::rgb($hex);
    foreach (snapPixel($image, $x, $y) as $i => $channel) {
        if (abs($channel - $expected[$i]) > $tolerance) {
            return false;
        }
    }

    return true;
}

/** Dark pixels (text) in a box. */
function snapInk(GdImage $image, int $x1, int $y1, int $x2, int $y2): int
{
    $ink = 0;
    for ($x = $x1; $x < $x2; $x += 3) {
        for ($y = $y1; $y < $y2; $y += 3) {
            [$r, $g] = snapPixel($image, $x, $y);
            $ink += $r < 150 && $g < 150 ? 1 : 0;
        }
    }

    return $ink;
}

it('draws the card of the brand for the invitation in both languages: a 1080x1080 JPEG with the fixed points of the layout', function (string $locale): void {
    $path = app(InviteCardBuilder::class)->render($locale);

    expect(basename($path))->toMatch(AchievementCardRenderer::FILE_PATTERN)
        ->and(basename($path))->toStartWith("invite-{$locale}-");
    $info = getimagesize($path);
    expect($info)->not->toBeFalse()
        ->and([$info[0], $info[1], $info[2]])->toBe([1080, 1080, IMAGETYPE_JPEG])
        ->and(filesize($path))->toBeGreaterThan(30_000)->toBeLessThan(1_000_000);

    $image = imagecreatefromjpeg($path);
    expect($image)->not->toBeFalse();

    // The field in the four corners, the white panel inside its frame (left, right, top, bottom edges).
    foreach ([[8, 8], [1071, 8], [8, 1071], [1071, 1071], [30, 540], [540, 30]] as [$x, $y]) {
        expect(snapIs($image, $x, $y, CardPalette::BACKGROUND))->toBeTrue("field at {$x},{$y}");
    }
    foreach ([[540, 70], [100, 540], [980, 540], [540, 1005]] as [$x, $y]) {
        expect(snapIs($image, $x, $y, CardPalette::PANEL))->toBeTrue("panel at {$x},{$y}");
    }
    foreach ([[61, 540], [1018, 540], [540, 61], [540, 1018]] as [$x, $y]) {
        expect(snapIs($image, $x, $y, CardPalette::LINE, 14))->toBeTrue("frame at {$x},{$y}");
    }

    // No illustration `invite` yet: the medal - its soft fill left of the letter, the first letter of the brand in the accent colour.
    expect(snapIs($image, 420, 380, CardPalette::BACKGROUND))->toBeTrue();
    $letter = 0;
    for ($x = 450; $x < 630; $x += 3) {
        for ($y = 300; $y < 460; $y += 3) {
            $letter += snapIs($image, $x, $y, CardPalette::ACCENT, 24) ? 1 : 0;
        }
    }
    expect($letter)->toBeGreaterThan(300);

    // The brand in the header, the label, the title and the description, the bot in the footer: the text zones are not empty.
    expect(snapInk($image, 380, 100, 700, 160))->toBeGreaterThan(40)
        ->and(snapInk($image, 120, 600, 960, 900))->toBeGreaterThan(400)
        ->and(snapInk($image, 640, 940, 980, 985))->toBeGreaterThan(20);
})->with(['ru', 'uz']);

it('says the length of the welcome trial on the card of the brand, and something else without a trial', function (): void {
    $builder = app(InviteCardBuilder::class);

    expect($builder->spec('ru')->description)->toBe('Первые 14 дней Pro бесплатно')
        ->and($builder->spec('uz')->description)->toBe('Birinchi 14 kun Pro bepul')
        ->and($builder->spec('ru')->title)->toBe('Учим английский в Telegram')
        ->and($builder->spec('ru')->label)->toBe('Приглашение')
        ->and($builder->spec('ru')->badge)->toBe('M')
        ->and($builder->spec('ru')->palette)->toBe('invite');

    $with = $builder->render('ru');
    config(['billing.trial.days' => 0]);

    expect($builder->spec('ru')->description)->toBe(__('achievements.card.invite.description_plain', [], 'ru'))
        ->and($builder->render('ru'))->not->toBe($with);
});

it('gives the card of the brand a new file when the brand changes, and one file per language otherwise', function (): void {
    $builder = app(InviteCardBuilder::class);
    $ru = $builder->render('ru');

    expect($builder->render('ru'))->toBe($ru)
        ->and($builder->render('uz'))->not->toBe($ru);

    config(['app.brand' => 'Brandy']);
    expect($builder->render('ru'))->not->toBe($ru)
        ->and($builder->spec('ru')->badge)->toBe('B');

    expect(Storage::disk('public')->allFiles('share-cards'))->toHaveCount(3);
});
