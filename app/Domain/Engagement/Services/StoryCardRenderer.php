<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services;

use App\Domain\Engagement\DTO\CardSpec;
use App\Domain\Engagement\Support\CardCanvas;
use App\Domain\Engagement\Support\CardPalette;
use GdImage;
use InvalidArgumentException;
use RuntimeException;

/**
 * The story card (docs/40 §2.4, F3 of the kit): a 1080×1920 JPEG for `WebApp.shareToStory` - the ink of the kit edge to edge, the
 * stamp of the kit (a muhr ring around a paper disc with a dotted inner ring) holding the illustration of the family - or the
 * badge while the designer's file is not there - then the title (≤ 2 lines, bold), the description (≤ 3 lines) and the line
 * «Muhrla · @<bot>»: a story carries a link only for Telegram Premium, so the address of the bot is drawn on the picture.
 *
 * Same fonts, disk, directory, caching and public address as the 1:1 card ({@see AchievementCardRenderer}, {@see CardCanvas}):
 * the file is `story_<key>-<locale>-<hash20>.jpg` - the prefix keeps it apart from the 1:1 card of the same key, and the name
 * still matches {@see AchievementCardRenderer::FILE_PATTERN} and the rule of nginx, so `publicUrl()` serves it and
 * `achievements:prune-cards` clears it. No personal data: the number of days of a series, never a name. Everything is drawn
 * inside the middle of the canvas: the UI of Telegram covers the top and the bottom of a story.
 */
final class StoryCardRenderer
{
    use CardCanvas;

    public const WIDTH = 1080;

    public const HEIGHT = 1920;

    /** Bump to invalidate every cached story card after a change of the drawing code. */
    public const TEMPLATE_VERSION = '1';

    /** `story_<key>`: the first part of the file name. */
    public const KEY_PREFIX = 'story_';

    public const TITLE_LINES = 2;

    public const DESCRIPTION_LINES = 3;

    /** Width the texts are wrapped to (90 px of air on each side). */
    public const TEXT_WIDTH = 900;

    public const TITLE_SIZE = 84;

    public const DESCRIPTION_SIZE = 44;

    private const TITLE_LINE_HEIGHT = 104;

    private const DESCRIPTION_LINE_HEIGHT = 62;

    /** The first baseline of the title; the description and the footer follow the text. */
    private const TITLE_BASELINE = 1130;

    /** The stamp: its centre, the outer diameter of the muhr ring, the band and the dotted ring inside the paper disc. */
    private const STAMP_CENTER_Y = 700;

    private const STAMP_DIAMETER = 600;

    private const STAMP_RING = 18;

    private const STAMP_DOTS = 44;

    private const STAMP_DOT = 9;

    /** The box the illustration is fitted into, inside the disc. */
    private const ART_SIZE = 370;

    private const FOOTER_SIZE = 40;

    public function __construct(private readonly AchievementCardRenderer $cards) {}

    /**
     * @return string absolute path of the JPEG on the cards disk
     *
     * @throws InvalidArgumentException a key outside `[a-z0-9_]` or too long for the file name
     * @throws RuntimeException GD / the font is not available
     */
    public function renderSpec(CardSpec $spec, string $locale): string
    {
        $key = self::KEY_PREFIX.$spec->key;
        if (preg_match(AchievementCardRenderer::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException("A story card key must be [a-z0-9_]{1,34}, got '{$spec->key}'.");
        }

        $locale = $this->cards->locale($locale);
        $content = $this->content($spec, $locale);

        return $this->cards->renderCached($key, $locale, $content, self::TEMPLATE_VERSION, fn (): string => $this->draw($content));
    }

    /**
     * The lines the title and the description take, before any cut (the test of every text, ru and uz).
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
     * Everything the picture shows, resolved: the input of both the drawing and the name of the file (`logo` is not drawn here but
     * named for the shared file name).
     *
     * @return array{key: string, locale: string, badge: string, title: string, description: string, brand: string, bot: string, art: string|null, logo: null}
     */
    private function content(CardSpec $spec, string $locale): array
    {
        $bot = $this->cards->botAddress();

        return [
            'key' => self::KEY_PREFIX.$spec->key,
            'locale' => $locale,
            'badge' => $spec->badge,
            'title' => $spec->title,
            'description' => $spec->description,
            'brand' => trim((string) config('app.brand')),
            'bot' => $bot === '' ? '' : '@'.substr($bot, strlen('t.me/')),
            'art' => $this->cards->illustration($spec->palette),
            'logo' => null,
        ];
    }

    /** @param array{key: string, locale: string, badge: string, title: string, description: string, brand: string, bot: string, art: string|null, logo: null} $content */
    private function draw(array $content): string
    {
        [$regular, $bold] = $this->fonts();
        $image = $this->canvas(self::WIDTH, self::HEIGHT);

        $ink = $this->color($image, CardPalette::STORY_BACKGROUND);
        $paper = $this->color($image, CardPalette::STORY_TEXT);
        $muted = $this->color($image, CardPalette::STORY_MUTED);
        $muhr = $this->color($image, CardPalette::STORY_MUHR);
        $dots = $this->color($image, CardPalette::MUHR);
        $accent = $this->color($image, CardPalette::ACCENT);

        imagefilledrectangle($image, 0, 0, self::WIDTH - 1, self::HEIGHT - 1, $ink);

        $this->stamp($image, $content['art'], $content['badge'], $bold, $muhr, $paper, $dots, $accent);

        $title = $this->fit($this->wrap($content['title'], $bold, self::TITLE_SIZE, self::TEXT_WIDTH), self::TITLE_LINES, $bold, self::TITLE_SIZE, self::TEXT_WIDTH);
        $description = $this->fit($this->wrap($content['description'], $regular, self::DESCRIPTION_SIZE, self::TEXT_WIDTH), self::DESCRIPTION_LINES, $regular, self::DESCRIPTION_SIZE, self::TEXT_WIDTH);

        $baseline = self::TITLE_BASELINE;
        foreach ($title as $i => $row) {
            $this->centered($image, $row, $bold, self::TITLE_SIZE, $baseline + $i * self::TITLE_LINE_HEIGHT, $paper);
        }
        $baseline += max(0, count($title) - 1) * self::TITLE_LINE_HEIGHT + 96;

        foreach ($description as $i => $row) {
            $this->centered($image, $row, $regular, self::DESCRIPTION_SIZE, $baseline + $i * self::DESCRIPTION_LINE_HEIGHT, $muted);
        }
        $baseline += max(0, count($description) - 1) * self::DESCRIPTION_LINE_HEIGHT + ($description === [] ? 60 : 112);

        $this->footer($image, $content['brand'], $content['bot'], $bold, $regular, $baseline, $muhr, $paper);

        return $this->encodeJpeg($image);
    }

    /**
     * The stamp of the kit: the muhr ring, the paper disc, a dotted ring just inside its edge, and in the middle the illustration of
     * the family fitted into its box - or the badge in the accent colour.
     */
    private function stamp(GdImage $image, ?string $artPath, string $badge, string $font, int $ring, int $paper, int $dots, int $accent): void
    {
        $cx = (int) round(self::WIDTH / 2);
        $cy = self::STAMP_CENTER_Y;
        $inner = self::STAMP_DIAMETER - 2 * self::STAMP_RING;

        imagefilledellipse($image, $cx, $cy, self::STAMP_DIAMETER, self::STAMP_DIAMETER, $ring);
        imagefilledellipse($image, $cx, $cy, $inner, $inner, $paper);

        $radius = $inner / 2 - 26;
        for ($i = 0; $i < self::STAMP_DOTS; $i++) {
            $angle = 2 * M_PI * $i / self::STAMP_DOTS;
            imagefilledellipse($image, (int) round($cx + $radius * cos($angle)), (int) round($cy + $radius * sin($angle)), self::STAMP_DOT, self::STAMP_DOT, $dots);
        }

        $art = $artPath === null ? null : $this->loadPng($artPath);
        if ($art !== null) {
            $scale = min(self::ART_SIZE / max(1, imagesx($art)), self::ART_SIZE / max(1, imagesy($art)));
            $width = (int) round(imagesx($art) * $scale);
            $height = (int) round(imagesy($art) * $scale);
            imagecopyresampled($image, $art, $cx - intdiv($width, 2), $cy - intdiv($height, 2), 0, 0, $width, $height, imagesx($art), imagesy($art));

            return;
        }

        $badge = trim($badge);
        if ($badge === '') {
            return;
        }

        // The largest size whose glyphs stay inside the dotted ring with air around them.
        $size = (int) round($inner * 0.4);
        while ($size > 24 && $this->width($badge, $font, $size) > $inner * 0.56) {
            $size -= 4;
        }
        $box = $this->box($badge, $font, $size);
        $height = $box[1] - $box[7];
        $this->centered($image, $badge, $font, $size, $cy + (int) round($height / 2) - $box[1], $accent);
    }

    /** «Muhrla · @bot»: the brand in muhr (bold), the separator and the address in paper, centred as one line. */
    private function footer(GdImage $image, string $brand, string $bot, string $bold, string $regular, int $baseline, int $muhr, int $paper): void
    {
        $separator = $brand !== '' && $bot !== '' ? '· ' : '';
        $gap = $separator === '' ? 0 : 18;
        $brandWidth = $brand === '' ? 0 : $this->width($brand, $bold, self::FOOTER_SIZE);
        $restWidth = $separator.$bot === '' ? 0 : $this->width($separator.$bot, $regular, self::FOOTER_SIZE);

        $x = (int) round((self::WIDTH - $brandWidth - $gap - $restWidth) / 2);
        if ($brand !== '') {
            $this->text($image, $brand, $bold, self::FOOTER_SIZE, $x, $baseline, $muhr);
        }
        if ($separator.$bot !== '') {
            $this->text($image, $separator.$bot, $regular, self::FOOTER_SIZE, $x + $brandWidth + $gap, $baseline, $paper);
        }
    }
}
