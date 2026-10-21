<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services;

use App\Domain\Engagement\Enums\AchievementKey;
use App\Models\Achievement;
use GdImage;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The share card of an achievement (docs/13 §7.4): a 1080×1080 PNG drawn with GD and Noto Sans
 * (SIL OFL, `resources/fonts/`, Latin with the Uzbek letters + Cyrillic).
 *
 * The picture carries no personal data — only the achievement's texts in the chosen language — so it is
 * cached per (key, locale, badge, texts): the file name holds a hash of exactly those, rendering the same
 * card again returns the existing file, and a changed text or template renders a new one. Written to a
 * temporary name and renamed, so a reader never sees a half-written PNG.
 */
final class AchievementCardRenderer
{
    public const SIZE = 1080;

    /** Bump to invalidate every cached card after a change of the drawing code. */
    public const TEMPLATE_VERSION = '1';

    private const LOCALES = ['ru', 'uz'];

    /** @var array<string, array{0: array{int, int, int}, 1: array{int, int, int}}> gradient top / bottom per family */
    private const PALETTES = [
        'streak' => [[234, 88, 12], [159, 18, 57]],
        'words' => [[37, 99, 235], [76, 29, 149]],
        'league' => [[202, 138, 4], [154, 52, 18]],
        'friend' => [[13, 148, 136], [30, 64, 175]],
        'session' => [[22, 163, 74], [21, 94, 117]],
    ];

    /**
     * @return string absolute path of the PNG on the achievements disk
     *
     * @throws InvalidArgumentException unknown key
     * @throws RuntimeException GD / the font is not available
     */
    public function render(Achievement $achievement, string $locale): string
    {
        $key = $achievement->keyEnum() ?? throw new InvalidArgumentException("Unknown achievement key '{$achievement->key}'.");
        $locale = in_array($locale, self::LOCALES, true) ? $locale : 'ru';

        $title = $key->title($locale);
        $description = $key->description($locale);
        $brand = __('achievements.card.brand', [], $locale);
        $tagline = __('achievements.card.tagline', [], $locale);

        $hash = substr(sha1(implode('|', [self::TEMPLATE_VERSION, $key->value, $locale, $key->badge(), $title, $description, $brand, $tagline])), 0, 12);
        $relative = trim((string) config('achievements.directory', 'achievements'), '/')."/{$key->value}-{$locale}-{$hash}.png";

        $disk = $this->disk();
        if (! $disk->exists($relative)) {
            $this->store($disk, $relative, $this->draw($key, $title, $description, $brand, $tagline));
        }

        return $disk->path($relative);
    }

    private function disk(): FilesystemAdapter
    {
        $disk = Storage::disk((string) config('achievements.disk', 'public'));
        if (! $disk instanceof FilesystemAdapter) {
            throw new RuntimeException('The achievements disk must be a filesystem adapter.');
        }

        return $disk;
    }

    private function store(FilesystemAdapter $disk, string $relative, string $png): void
    {
        $temporary = $relative.'.'.bin2hex(random_bytes(6)).'.tmp';
        if (! $disk->put($temporary, $png)) {
            throw new RuntimeException('Could not write the achievement card.');
        }

        try {
            $disk->move($temporary, $relative);
        } catch (Throwable $e) {
            $disk->delete($temporary);
            // A parallel renderer produced the very same card first: fine.
            if (! $disk->exists($relative)) {
                throw new RuntimeException('Could not store the achievement card.', 0, $e);
            }
        }
    }

    private function draw(AchievementKey $key, string $title, string $description, string $brand, string $tagline): string
    {
        $regular = (string) config('achievements.fonts.regular');
        $bold = (string) config('achievements.fonts.bold');
        if (! is_file($regular) || ! is_file($bold)) {
            throw new RuntimeException('The achievement card fonts are missing (resources/fonts).');
        }
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagettftext')) {
            throw new RuntimeException('PHP GD with FreeType is required for achievement cards.');
        }

        $size = self::SIZE;
        $image = imagecreatetruecolor($size, $size);
        if ($image === false) {
            throw new RuntimeException('Could not create the card image.');
        }

        [$top, $bottom] = self::PALETTES[$key->family()] ?? self::PALETTES['streak'];
        for ($y = 0; $y < $size; $y++) {
            $t = $y / ($size - 1);
            $line = $this->color($image, (int) round($top[0] + ($bottom[0] - $top[0]) * $t), (int) round($top[1] + ($bottom[1] - $top[1]) * $t), (int) round($top[2] + ($bottom[2] - $top[2]) * $t));
            imageline($image, 0, $y, $size - 1, $y, $line);
        }

        $white = $this->color($image, 255, 255, 255);
        $soft = $this->color($image, 255, 255, 255, 96);
        $ink = $this->color($image, (int) round($bottom[0] * 0.55), (int) round($bottom[1] * 0.55), (int) round($bottom[2] * 0.55));

        // The medal: a translucent halo, a white disc and the badge in the colour of the card.
        imagealphablending($image, true);
        imagefilledellipse($image, 540, 400, 500, 500, $soft);
        imagefilledellipse($image, 540, 400, 420, 420, $white);

        $badge = $key->badge();
        $badgeSize = match (true) {
            mb_strlen($badge) <= 1 => 220,
            mb_strlen($badge) === 2 => 190,
            mb_strlen($badge) === 3 => 150,
            default => 118,
        };
        $this->centered($image, $badge, $bold, $badgeSize, 400 + (int) round($badgeSize * 0.36), $ink);

        $y = 745;
        foreach ($this->wrap($title, $bold, 66, 900) as $line) {
            $this->centered($image, $line, $bold, 66, $y, $white);
            $y += 84;
        }
        $y += 6;
        foreach ($this->wrap($description, $regular, 40, 880) as $line) {
            $this->centered($image, $line, $regular, 40, $y, $white);
            $y += 56;
        }

        $this->centered($image, $brand, $bold, 40, 985, $white);
        $this->centered($image, $tagline, $regular, 28, 1030, $white);

        ob_start();
        $written = imagepng($image, null, 6);
        $png = ob_get_clean();
        if (! $written || ! is_string($png) || $png === '') {
            throw new RuntimeException('Could not encode the card as PNG.');
        }

        return $png;
    }

    /** @param  int<0, 127>  $alpha  GD alpha: 0 opaque … 127 transparent */
    private function color(GdImage $image, int $r, int $g, int $b, int $alpha = 0): int
    {
        $color = imagecolorallocatealpha($image, max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)), $alpha);
        if ($color === false) {
            throw new RuntimeException('Could not allocate a colour.');
        }

        return $color;
    }

    /** Draws `$text` horizontally centred, its baseline at `$baseline`. */
    private function centered(GdImage $image, string $text, string $font, int $size, int $baseline, int $color): void
    {
        $x = (int) round((self::SIZE - $this->width($text, $font, $size)) / 2);
        if (imagettftext($image, $size, 0, $x, $baseline, $color, $font, $text) === false) {
            throw new RuntimeException('Could not draw text on the card.');
        }
    }

    private function width(string $text, string $font, int $size): int
    {
        $box = imagettfbbox($size, 0, $font, $text);
        if ($box === false) {
            throw new RuntimeException('Could not measure text.');
        }

        return abs($box[2] - $box[0]);
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
}
