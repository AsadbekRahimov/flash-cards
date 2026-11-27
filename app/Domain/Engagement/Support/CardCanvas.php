<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Support;

use App\Domain\Engagement\Services\AchievementCardRenderer;
use App\Domain\Engagement\Services\StoryCardRenderer;
use GdImage;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * What every card renderer does the same way (docs/13 §7.4, docs/36 §2б, docs/40 §2.4): the GD primitives - text with Noto Sans,
 * measuring, greedy wrapping, cutting to N lines with "…", rounded rectangles, a PNG for the card - the JPEG encoding, and the
 * file on the cards disk: written to a temporary name and renamed (a reader never sees a half-written file), "used" once a day when
 * served again (`achievements:prune-cards` keeps what is needed), drawn anew when the prune deleted it under the touch.
 *
 * Shared by the 1:1 card ({@see AchievementCardRenderer}) and the 9:16 story card
 * ({@see StoryCardRenderer}); the layouts stay in their classes. Centring takes the width of the
 * image in hand, so no constant of a canvas lives here.
 */
trait CardCanvas
{
    /**
     * The regular and the bold font (`achievements.fonts`), after the checks of GD and FreeType.
     *
     * @return array{string, string}
     *
     * @throws RuntimeException the fonts or GD are not available
     */
    private function fonts(): array
    {
        $regular = (string) config('achievements.fonts.regular');
        $bold = (string) config('achievements.fonts.bold');
        if (! is_file($regular) || ! is_file($bold)) {
            throw new RuntimeException('The achievement card fonts are missing (resources/fonts).');
        }
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagettftext')) {
            throw new RuntimeException('PHP GD with FreeType is required for achievement cards.');
        }

        return [$regular, $bold];
    }

    /**
     * A blank true-colour canvas with alpha blending on.
     *
     * @param  positive-int  $width
     * @param  positive-int  $height
     *
     * @throws RuntimeException GD has no JPEG support, or the image could not be created
     */
    private function canvas(int $width, int $height): GdImage
    {
        if (! function_exists('imagejpeg') || ! (bool) (gd_info()['JPEG Support'] ?? false)) {
            throw new RuntimeException('PHP GD with JPEG support is required for achievement cards.');
        }

        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            throw new RuntimeException('Could not create the card image.');
        }
        imagealphablending($image, true);

        return $image;
    }

    /** The JPEG bytes of the canvas at `achievements.jpeg_quality`. */
    private function encodeJpeg(GdImage $image): string
    {
        ob_start();
        $written = imagejpeg($image, null, max(1, min(100, (int) config('achievements.jpeg_quality', 90))));
        $jpeg = ob_get_clean();
        if (! $written || ! is_string($jpeg) || $jpeg === '') {
            throw new RuntimeException('Could not encode the card as JPEG.');
        }

        return $jpeg;
    }

    /**
     * A cached card served again is "used": its time moves on (at most once a day), so `achievements:prune-cards` removes only
     * the cards nobody needed for a month. Best effort - a read-only file is still a good card.
     *
     * False when the card is not there to serve after all: `achievements:prune-cards` may delete an old card between the check of
     * the caller and the `touch` here, and `touch` then CREATES an empty file under the name. The file is looked at again after
     * the touch (past the stat cache): missing or empty - the caller draws the card anew.
     */
    private function markUsed(string $path): bool
    {
        $modified = @filemtime($path);
        if ($modified !== false && $modified < now()->subDay()->getTimestamp()) {
            @touch($path);
        }

        clearstatcache(true, $path);
        $size = @filesize($path);

        return $size !== false && $size > 0;
    }

    /** Writes the JPEG under a temporary name and renames it: a reader (Telegram downloading it) never sees a half-written file. */
    private function store(FilesystemAdapter $disk, string $relative, string $jpeg): void
    {
        $temporary = $relative.'.'.bin2hex(random_bytes(6)).'.tmp';
        if (! $disk->put($temporary, $jpeg)) {
            throw new RuntimeException('Could not write the share card.');
        }

        try {
            $disk->move($temporary, $relative);
        } catch (Throwable $e) {
            $disk->delete($temporary);
            // A parallel renderer produced the very same card first: fine.
            if (! $disk->exists($relative)) {
                throw new RuntimeException('Could not store the share card.', 0, $e);
            }
        }
    }

    /** A PNG for the card (the logo, an illustration); an unreadable one is skipped with a warning - the card still goes out. */
    private function loadPng(string $path): ?GdImage
    {
        $image = @imagecreatefrompng($path);
        if ($image === false) {
            Log::warning('achievements.card_image_unreadable', ['file' => basename($path)]);

            return null;
        }
        imagealphablending($image, true);
        imagesavealpha($image, true);

        return $image;
    }

    private function roundedRectangle(GdImage $image, int $x1, int $y1, int $x2, int $y2, int $radius, int $color): void
    {
        imagefilledrectangle($image, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
        imagefilledrectangle($image, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);
        foreach ([[$x1 + $radius, $y1 + $radius], [$x2 - $radius, $y1 + $radius], [$x1 + $radius, $y2 - $radius], [$x2 - $radius, $y2 - $radius]] as [$cx, $cy]) {
            imagefilledellipse($image, $cx, $cy, 2 * $radius, 2 * $radius, $color);
        }
    }

    private function color(GdImage $image, string $hex): int
    {
        [$r, $g, $b] = CardPalette::rgb($hex);
        $color = imagecolorallocate($image, $r, $g, $b);
        if ($color === false) {
            throw new RuntimeException('Could not allocate a colour.');
        }

        return $color;
    }

    /** Draws `$text` horizontally centred on the canvas, its baseline at `$baseline`. */
    private function centered(GdImage $image, string $text, string $font, int $size, int $baseline, int $color): void
    {
        $this->text($image, $text, $font, $size, (int) round((imagesx($image) - $this->width($text, $font, $size)) / 2), $baseline, $color);
    }

    /** Small capitals with tracking, centred (the label): letter by letter, `$tracking` px apart. */
    private function spaced(GdImage $image, string $text, string $font, int $size, int $baseline, int $color, int $tracking): void
    {
        $letters = mb_str_split($text);
        $widths = array_map(fn (string $letter): int => $letter === ' ' ? (int) round($size * 0.4) : $this->width($letter, $font, $size), $letters);
        $x = (int) round((imagesx($image) - array_sum($widths) - $tracking * max(0, count($letters) - 1)) / 2);

        foreach ($letters as $i => $letter) {
            if ($letter !== ' ') {
                $this->text($image, $letter, $font, $size, $x, $baseline, $color);
            }
            $x += $widths[$i] + $tracking;
        }
    }

    private function text(GdImage $image, string $text, string $font, int $size, int $x, int $baseline, int $color): void
    {
        if ($text !== '' && imagettftext($image, $size, 0, $x, $baseline, $color, $font, $text) === false) {
            throw new RuntimeException('Could not draw text on the card.');
        }
    }

    private function width(string $text, string $font, int $size): int
    {
        $box = $this->box($text, $font, $size);

        return abs($box[2] - $box[0]);
    }

    /** @return array<int, int> */
    private function box(string $text, string $font, int $size): array
    {
        $box = imagettfbbox($size, 0, $font, $text);
        if ($box === false) {
            throw new RuntimeException('Could not measure text.');
        }

        return $box;
    }

    /**
     * Greedy word wrap to `$maxWidth` pixels (a single word wider than that stays on its own line).
     *
     * @return list<string>
     */
    private function wrap(string $text, string $font, int $size, int $maxWidth): array
    {
        $lines = [];
        $current = '';
        foreach (preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;
            if ($current !== '' && $this->width($candidate, $font, $size) > $maxWidth) {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    /**
     * At most `$max` lines: the last kept one ends with "…" (shortened until it fits) when anything was cut.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function fit(array $lines, int $max, string $font, int $size, int $maxWidth): array
    {
        if (count($lines) <= $max && ($lines === [] || $this->width($lines[count($lines) - 1], $font, $size) <= $maxWidth)) {
            return $lines;
        }

        $kept = array_slice($lines, 0, $max);
        $last = (string) array_pop($kept);
        // Whole words first; letters only when a single word is wider than the line.
        while (str_contains($last, ' ') && $this->width($last.'…', $font, $size) > $maxWidth) {
            $last = mb_substr($last, 0, (int) mb_strrpos($last, ' '));
        }
        while ($last !== '' && $this->width($last.'…', $font, $size) > $maxWidth) {
            $last = mb_substr($last, 0, -1);
        }
        $kept[] = rtrim($last, ' ,.;:—-').'…';

        return $kept;
    }
}
