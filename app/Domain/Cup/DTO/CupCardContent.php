<?php

declare(strict_types=1);

namespace App\Domain\Cup\DTO;

use App\Domain\Cup\Services\CupCardRenderer;

/**
 * One message of a cup in its chat as {@see CupCardRenderer} made it: the plain text (a caption under the picture, or the text of a
 * message when there is no picture), the `reply_markup` (null - no buttons) and, for the card and the final, the picture to send it
 * with. `hash()` is what `cups.card_text_hash` keeps: an edit is made only when it changed.
 */
final readonly class CupCardContent
{
    /** Telegram's limit of a caption under a photo. */
    public const CAPTION_LIMIT = 1024;

    /** Telegram's limit of the text of a message. */
    public const TEXT_LIMIT = 4096;

    /**
     * @param  array{inline_keyboard: list<list<array<string, mixed>>>}|null  $keyboard
     */
    public function __construct(
        public string $text,
        public ?array $keyboard,
    ) {}

    /** The text cut to what Telegram takes under a photo (`$photo`) or in a message. */
    public function textFor(bool $photo): string
    {
        $limit = $photo ? self::CAPTION_LIMIT : self::TEXT_LIMIT;

        return mb_strlen($this->text) <= $limit ? $this->text : rtrim(mb_substr($this->text, 0, $limit - 1)).'…';
    }

    /** sha1 of the text and the buttons: 40 characters, `cups.card_text_hash`. */
    public function hash(): string
    {
        return sha1($this->text."\n".json_encode($this->keyboard, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
