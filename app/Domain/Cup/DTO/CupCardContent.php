<?php

declare(strict_types=1);

namespace App\Domain\Cup\DTO;

use App\Domain\Cup\Services\CupCardRenderer;
use App\Domain\Telegram\Support\TelegramText;

/**
 * One message of a cup in its chat as {@see CupCardRenderer} made it: the plain text (the text of a message when there is no
 * picture), the caption under the picture when the full text does not fit there (the table shortened by whole rows, made by the
 * renderer), the `reply_markup` (null - no buttons). `hash()` is what `cups.card_text_hash` keeps: an edit is made only when it changed.
 *
 * The limits are Telegram's, in UTF-16 code units ({@see TelegramText}): an emoji in a name counts 2, a family emoji ~11.
 */
final readonly class CupCardContent
{
    /** Telegram's limit of a caption under a photo. */
    public const CAPTION_LIMIT = TelegramText::CAPTION_LIMIT;

    /** Telegram's limit of the text of a message. */
    public const TEXT_LIMIT = TelegramText::MESSAGE_LIMIT;

    /**
     * @param  array{inline_keyboard: list<list<array<string, mixed>>>}|null  $keyboard
     */
    public function __construct(
        public string $text,
        public ?array $keyboard,
        public ?string $caption = null,
    ) {}

    /** The text under a photo (`$photo`) or of a message, within what Telegram takes (cut between graphemes as the last resort). */
    public function textFor(bool $photo): string
    {
        return $photo
            ? TelegramText::cut($this->caption ?? $this->text, self::CAPTION_LIMIT)
            : TelegramText::cut($this->text, self::TEXT_LIMIT);
    }

    /** sha1 of the text and the buttons: 40 characters, `cups.card_text_hash`. */
    public function hash(): string
    {
        return sha1($this->text."\n".json_encode($this->keyboard, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
