<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services;

use App\Domain\Engagement\DTO\CardSpec;
use App\Domain\Engagement\Support\CardPalette;
use App\Models\Achievement;
use GdImage;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The share card (docs/13 §7.4, docs/36 §2б): a 1080×1080 JPEG drawn with GD and Noto Sans (SIL OFL, `resources/fonts/`,
 * Latin with the Uzbek letters + Cyrillic) in the colours of the Mini App ({@see CardPalette}). A white rounded panel on the
 * soft blue field: the brand (`config('app.brand')`, with the logo of `app.brand_logo` when there is one) on top, the
 * illustration of the family - or the medal with the badge while the designer's file is not there - then the label, the title
 * (≤ 2 lines), the description (≤ 3 lines) and the footer: the tagline and `t.me/<bot>` (a picture forwarded without the button
 * still leads to the bot).
 *
 * The picture carries no personal data - no learner's name, only the texts of the card in the chosen language - so it is cached
 * per what it shows: the file name holds a keyed hash of the template version, the texts, the brand, the bot and the files of the
 * logo and the illustration. Rendering the same card again returns the existing file; a changed text, brand or illustration
 * renders a new one. The hash is keyed by the application key, so the address of a card cannot be guessed from its texts.
 * Written to a temporary name and renamed, so a reader (and Telegram downloading the photo) never sees a half-written file.
 */
final class AchievementCardRenderer
{
    public const SIZE = 1080;

    /** Bump to invalidate every cached card after a change of the drawing code. */
    public const TEMPLATE_VERSION = '2';

    /** Lines the title and the description may take; longer texts are cut with "…" (a test keeps every key within them). */
    public const TITLE_LINES = 2;

    public const DESCRIPTION_LINES = 3;

    /** Width the texts are wrapped to (the panel is 960 wide, with 60 px of air on each side). */
    public const TEXT_WIDTH = 840;

    /** Sizes in pt of the title and the description. */
    public const TITLE_SIZE = 68;

    public const DESCRIPTION_SIZE = 38;

    /** `<key>-<locale>-<hash>.jpg`: what nginx lets through `/storage/share-cards/` (docker/nginx/*.conf) is exactly this. */
    public const FILE_PATTERN = '/^[a-z0-9_]{1,40}-(?:ru|uz)-[0-9a-f]{20}\.jpg$/';

    private const LOCALES = ['ru', 'uz'];

    private const MARGIN = 60;

    private const RADIUS = 48;

    private const BORDER = 3;

    private const TITLE_LINE_HEIGHT = 82;

    private const DESCRIPTION_LINE_HEIGHT = 50;

    /** Baseline of the brand in the header and of the footer. */
    private const HEADER_BASELINE = 150;

    private const FOOTER_BASELINE = 970;

    /** The lowest baseline of the description: the footer has its own band under it. */
    private const TEXT_BOTTOM = 880;

    /** The illustration box (docs/36 §2б: 480×420), and the top of the zone it is centred in. */
    private const ART_WIDTH = 480;

    private const ART_HEIGHT = 420;

    private const ART_TOP = 185;

    private const MEDAL_DIAMETER = 320;

    /**
     * The card of an achievement: its texts in the language, drawn by {@see renderSpec()}.
     *
     * @return string absolute path of the JPEG on the cards disk
     *
     * @throws InvalidArgumentException unknown key
     * @throws RuntimeException GD / the font is not available
     */
    public function render(Achievement $achievement, string $locale): string
    {
        $key = $achievement->keyEnum() ?? throw new InvalidArgumentException("Unknown achievement key '{$achievement->key}'.");
        $locale = $this->locale($locale);

        return $this->renderSpec(new CardSpec($key->value, $key->family(), $key->badge(), $key->title($locale), $key->description($locale)), $locale);
    }

    /**
     * Any card (docs/13 §7.4, docs/18 §12.3.4, docs/36 §2б): the achievements', the game's and the brand's. Cached by what the
     * card shows (see the class).
     *
     * @return string absolute path of the JPEG on the cards disk
     *
     * @throws InvalidArgumentException a key outside `[a-z0-9_]{1,40}`
     * @throws RuntimeException GD / the font is not available
     */
    public function renderSpec(CardSpec $spec, string $locale): string
    {
        if (preg_match('/^[a-z0-9_]{1,40}$/', $spec->key) !== 1) {
            throw new InvalidArgumentException("A card key must be [a-z0-9_]{1,40}, got '{$spec->key}'.");
        }

        $locale = $this->locale($locale);
        $content = $this->content($spec, $locale);

        $relative = $this->directory().'/'.$this->fileName($spec->key, $locale, $content);

        $disk = $this->disk();
        if ($disk->exists($relative)) {
            $this->markUsed($disk->path($relative));
        } else {
            $this->store($disk, $relative, $this->draw($content));
        }

        return $disk->path($relative);
    }

    /**
     * The HTTPS address of a rendered card (`/storage/share-cards/<file>.jpg`) for Telegram to download (the photo of a shared
     * message, docs/36 §1в). Null when there is none to give: the cards are not on the `public` disk, APP_URL is not https, or
     * `$path` is not a card of the cards directory.
     */
    public function publicUrl(string $path): ?string
    {
        if ((string) config('achievements.disk', 'public') !== 'public') {
            return null;
        }

        $disk = $this->disk();
        $prefix = rtrim($disk->path($this->directory()), '/').'/';
        if (! str_starts_with($path, $prefix)) {
            return null;
        }

        $file = substr($path, strlen($prefix));
        if (preg_match(self::FILE_PATTERN, $file) !== 1) {
            return null;
        }

        $url = $disk->url($this->directory().'/'.$file);

        return str_starts_with($url, 'https://') ? $url : null;
    }

    /**
     * The lines the title and the description of a card take, before any cut (the test of every key; docs/36 A3).
     *
     * @return array{title: list<string>, description: list<string>}
     *
     * @throws RuntimeException the font is not available
     */
    public function lines(CardSpec $spec): array
    {
        [$regular, $bold] = $this->fonts();

        return [
            'title' => $this->wrap($spec->title, $bold, self::TITLE_SIZE, self::TEXT_WIDTH),
            'description' => $this->wrap($spec->description, $regular, self::DESCRIPTION_SIZE, self::TEXT_WIDTH),
        ];
    }

    /**
     * The path of the illustration of a family when the file is there (a PNG GD can read later), else null - the medal.
     */
    public function illustration(string $family): ?string
    {
        if (preg_match('/^[a-z0-9_]{1,40}$/', $family) !== 1) {
            return null;
        }

        $path = rtrim((string) config('achievements.illustrations'), '/')."/{$family}.png";

        return is_file($path) && is_readable($path) ? $path : null;
    }

    /** The directory of the cards on the disk (`share-cards`). */
    public function directory(): string
    {
        return trim((string) config('achievements.directory', 'share-cards'), '/');
    }

    public function disk(): FilesystemAdapter
    {
        return Storage::disk((string) config('achievements.disk', 'public'));
    }

    private function locale(string $locale): string
    {
        return in_array($locale, self::LOCALES, true) ? $locale : 'ru';
    }

    /**
     * Everything the picture shows, resolved: the input of both the drawing and the name of the file.
     *
     * @return array{key: string, locale: string, badge: string, label: string, title: string, description: string, brand: string, logo: string|null, tagline: string, bot: string, art: string|null}
     */
    private function content(CardSpec $spec, string $locale): array
    {
        $bot = ltrim(trim((string) config('telegram.bot_username')), '@');
        $logo = config('app.brand_logo');
        $logo = is_string($logo) && $logo !== '' && is_file($logo) && is_readable($logo) ? $logo : null;

        return [
            'key' => $spec->key,
            'locale' => $locale,
            'badge' => $spec->badge,
            'label' => $spec->label ?? __('achievements.card.label', [], $locale),
            'title' => $spec->title,
            'description' => $spec->description,
            'brand' => trim((string) config('app.brand')),
            'logo' => $logo,
            'tagline' => __('achievements.card.tagline', [], $locale),
            'bot' => $bot === '' ? '' : 't.me/'.$bot,
            'art' => $this->illustration($spec->palette),
        ];
    }

    /** @param array{key: string, locale: string, badge: string, label: string, title: string, description: string, brand: string, logo: string|null, tagline: string, bot: string, art: string|null} $content */
    private function fileName(string $key, string $locale, array $content): string
    {
        $inputs = $content;
        // The files themselves, not their paths: a new logo or a new illustration under the same name is a new card.
        $inputs['logo'] = $content['logo'] === null ? '' : (string) sha1_file($content['logo']);
        $inputs['art'] = $content['art'] === null ? '' : (string) sha1_file($content['art']);
        $inputs['version'] = self::TEMPLATE_VERSION;

        $hash = hash_hmac('sha256', json_encode($inputs, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'share-card|'.(string) config('app.key'));

        return "{$key}-{$locale}-".substr($hash, 0, 20).'.jpg';
    }

    /**
     * A cached card served again is "used": its time moves on (at most once a day), so `achievements:prune-cards` removes only
     * the cards nobody needed for a month. Best effort - a read-only file is still a good card.
     */
    private function markUsed(string $path): void
    {
        $modified = @filemtime($path);
        if ($modified !== false && $modified < now()->subDay()->getTimestamp()) {
            @touch($path);
        }
    }

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

    /** @return array{string, string} the regular and the bold font */
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

    /** @param array{key: string, locale: string, badge: string, label: string, title: string, description: string, brand: string, logo: string|null, tagline: string, bot: string, art: string|null} $content */
    private function draw(array $content): string
    {
        [$regular, $bold] = $this->fonts();
        if (! function_exists('imagejpeg') || ! (bool) (gd_info()['JPEG Support'] ?? false)) {
            throw new RuntimeException('PHP GD with JPEG support is required for achievement cards.');
        }

        $size = self::SIZE;
        $image = imagecreatetruecolor($size, $size);
        if ($image === false) {
            throw new RuntimeException('Could not create the card image.');
        }
        imagealphablending($image, true);

        $background = $this->color($image, CardPalette::BACKGROUND);
        $panel = $this->color($image, CardPalette::PANEL);
        $line = $this->color($image, CardPalette::LINE);
        $primary = $this->color($image, CardPalette::PRIMARY);
        $accent = $this->color($image, CardPalette::ACCENT);
        $text = $this->color($image, CardPalette::TEXT);
        $muted = $this->color($image, CardPalette::MUTED);

        // The field and the panel with its frame: the frame is the outer rounded rectangle, the panel the inner one.
        imagefilledrectangle($image, 0, 0, $size - 1, $size - 1, $background);
        $this->roundedRectangle($image, self::MARGIN, self::MARGIN, $size - 1 - self::MARGIN, $size - 1 - self::MARGIN, self::RADIUS, $line);
        $this->roundedRectangle($image, self::MARGIN + self::BORDER, self::MARGIN + self::BORDER, $size - 1 - self::MARGIN - self::BORDER, $size - 1 - self::MARGIN - self::BORDER, self::RADIUS - self::BORDER, $panel);

        $this->header($image, $content['brand'], $content['logo'], $bold, $primary);

        // The text block sits on the bottom of its zone, so the illustration gets whatever the texts leave.
        $title = $this->fit($this->wrap($content['title'], $bold, self::TITLE_SIZE, self::TEXT_WIDTH), self::TITLE_LINES, $bold, self::TITLE_SIZE);
        $description = $this->fit($this->wrap($content['description'], $regular, self::DESCRIPTION_SIZE, self::TEXT_WIDTH), self::DESCRIPTION_LINES, $regular, self::DESCRIPTION_SIZE);
        $label = mb_strtoupper(trim($content['label']));

        // Baselines from the bottom up: the last line of the description, the title above it, the label above the title.
        $descriptionFirst = self::TEXT_BOTTOM - max(0, count($description) - 1) * self::DESCRIPTION_LINE_HEIGHT;
        $titleLast = $description === [] ? self::TEXT_BOTTOM : $descriptionFirst - 72;
        $titleFirst = $titleLast - max(0, count($title) - 1) * self::TITLE_LINE_HEIGHT;
        $labelBaseline = $titleFirst - 92;
        $artBottom = ($label === '' ? $titleFirst - 50 : $labelBaseline - 26) - 30;

        $this->art($image, $content['art'], $content['badge'], $artBottom, $bold, $line, $background, $accent);

        if ($label !== '') {
            $this->spaced($image, $label, $bold, 28, $labelBaseline, $muted, 5);
        }
        foreach ($title as $i => $row) {
            $this->centered($image, $row, $bold, self::TITLE_SIZE, $titleFirst + $i * self::TITLE_LINE_HEIGHT, $text);
        }
        foreach ($description as $i => $row) {
            $this->centered($image, $row, $regular, self::DESCRIPTION_SIZE, $descriptionFirst + $i * self::DESCRIPTION_LINE_HEIGHT, $muted);
        }

        // The card of the brand says the tagline in its title already: not twice.
        $this->footer($image, $content['tagline'] === $content['title'] ? '' : $content['tagline'], $content['bot'], $regular, $muted, $accent);

        ob_start();
        $written = imagejpeg($image, null, max(1, min(100, (int) config('achievements.jpeg_quality', 90))));
        $jpeg = ob_get_clean();
        if (! $written || ! is_string($jpeg) || $jpeg === '') {
            throw new RuntimeException('Could not encode the card as JPEG.');
        }

        return $jpeg;
    }

    private function header(GdImage $image, string $brand, ?string $logoPath, string $font, int $color): void
    {
        $logo = $logoPath === null ? null : $this->loadPng($logoPath);
        $logoHeight = 56;
        $logoWidth = $logo === null ? 0 : (int) round(imagesx($logo) * $logoHeight / max(1, imagesy($logo)));
        $gap = $logo !== null && $brand !== '' ? 16 : 0;
        $textWidth = $brand === '' ? 0 : $this->width($brand, $font, 44);

        $x = (int) round((self::SIZE - $logoWidth - $gap - $textWidth) / 2);
        if ($logo !== null) {
            imagecopyresampled($image, $logo, $x, self::HEADER_BASELINE - 46, 0, 0, $logoWidth, $logoHeight, imagesx($logo), imagesy($logo));
        }
        if ($brand !== '') {
            $this->text($image, $brand, $font, 44, $x + $logoWidth + $gap, self::HEADER_BASELINE, $color);
        }
    }

    /**
     * The illustration of the family fitted into its box, centred in the zone between the header and `$bottom`; without one the
     * medal: a soft disc in the frame colour with the badge.
     */
    private function art(GdImage $image, ?string $artPath, string $badge, int $bottom, string $font, int $line, int $soft, int $ink): void
    {
        $zone = max(120, $bottom - self::ART_TOP);
        $art = $artPath === null ? null : $this->loadPng($artPath);

        if ($art !== null) {
            $scale = min(self::ART_WIDTH / max(1, imagesx($art)), min(self::ART_HEIGHT, $zone) / max(1, imagesy($art)));
            $width = (int) round(imagesx($art) * $scale);
            $height = (int) round(imagesy($art) * $scale);
            $x = (int) round((self::SIZE - $width) / 2);
            $y = self::ART_TOP + (int) round(($zone - $height) / 2);
            imagecopyresampled($image, $art, $x, $y, 0, 0, $width, $height, imagesx($art), imagesy($art));

            return;
        }

        $diameter = min(self::MEDAL_DIAMETER, $zone - 20);
        $cx = (int) round(self::SIZE / 2);
        $cy = self::ART_TOP + (int) round($zone / 2);
        imagefilledellipse($image, $cx, $cy, $diameter + 2 * self::BORDER, $diameter + 2 * self::BORDER, $line);
        imagefilledellipse($image, $cx, $cy, $diameter, $diameter, $soft);

        $badge = trim($badge);
        if ($badge === '') {
            return;
        }

        // The largest size whose glyphs stay inside two thirds of the disc.
        $size = (int) round($diameter * 0.5);
        while ($size > 24 && $this->width($badge, $font, $size) > $diameter * 0.66) {
            $size -= 4;
        }
        $box = $this->box($badge, $font, $size);
        $height = $box[1] - $box[7];
        $baseline = $cy + (int) round($height / 2) - $box[1];
        $this->centered($image, $badge, $font, $size, $baseline, $ink);
    }

    /**
     * The tagline on the left, the bot's address on the right. Both shrink together (26 → 22 pt) to share the line; past that the
     * tagline is cut, never drawn over the address.
     */
    private function footer(GdImage $image, string $tagline, string $bot, string $font, int $muted, int $accent): void
    {
        $left = self::MARGIN + 44;
        $right = self::SIZE - self::MARGIN - 44;
        $gap = 28;

        $size = 26;
        while ($size > 22 && $tagline !== '' && $bot !== '' && $this->width($tagline, $font, $size) + $gap + $this->width($bot, $font, $size) > $right - $left) {
            $size--;
        }
        $botWidth = $bot === '' ? 0 : $this->width($bot, $font, $size);

        if ($tagline !== '') {
            $fitted = $this->fit([$tagline], 1, $font, $size, $right - $left - ($botWidth === 0 ? 0 : $botWidth + $gap));
            $this->text($image, $fitted[0] ?? '', $font, $size, $left, self::FOOTER_BASELINE, $muted);
        }
        if ($bot !== '') {
            $this->text($image, $bot, $font, $size, $right - $botWidth, self::FOOTER_BASELINE, $accent);
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

    /** Draws `$text` horizontally centred, its baseline at `$baseline`. */
    private function centered(GdImage $image, string $text, string $font, int $size, int $baseline, int $color): void
    {
        $this->text($image, $text, $font, $size, (int) round((self::SIZE - $this->width($text, $font, $size)) / 2), $baseline, $color);
    }

    /** Small capitals with tracking, centred (the label): letter by letter, `$tracking` px apart. */
    private function spaced(GdImage $image, string $text, string $font, int $size, int $baseline, int $color, int $tracking): void
    {
        $letters = mb_str_split($text);
        $widths = array_map(fn (string $letter): int => $letter === ' ' ? (int) round($size * 0.4) : $this->width($letter, $font, $size), $letters);
        $x = (int) round((self::SIZE - array_sum($widths) - $tracking * max(0, count($letters) - 1)) / 2);

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
    private function fit(array $lines, int $max, string $font, int $size, int $maxWidth = self::TEXT_WIDTH): array
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
