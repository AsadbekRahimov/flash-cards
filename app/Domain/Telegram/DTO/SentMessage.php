<?php

declare(strict_types=1);

namespace App\Domain\Telegram\DTO;

use App\Domain\Telegram\Exceptions\TelegramApiException;

/**
 * A message the bot has just sent (docs/35 §1.6, O1): its `message_id` - a later edit of the caption or the text needs it - and,
 * for a photo, the `file_id` of its LARGEST size, by which the same picture goes out again without a new upload
 * (`polls.image_file_id`). A `file_id` belongs to this bot only and may differ between two sends of one picture; any valid one works.
 */
final readonly class SentMessage
{
    public function __construct(
        public int $messageId,
        public ?string $photoFileId = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $message  a `Message` object of the Bot API (the `result` of a send)
     *
     * @throws TelegramApiException when Telegram said ok but the answer carried no `message_id` (as a transport failure: the message
     *                              may well be in the chat, so it is not a refusal and must not be sent again)
     */
    public static function fromMessage(array $message, string $method): self
    {
        $messageId = $message['message_id'] ?? null;

        if (! is_int($messageId) || $messageId <= 0) {
            throw new TelegramApiException(sprintf('Telegram API request failed (%s): the answer carried no message_id', $method));
        }

        return new self($messageId, self::largestPhotoFileId($message['photo'] ?? null));
    }

    /**
     * `Message.photo` is an array of `PhotoSize` (one per size Telegram made). The largest by area is taken, the bigger file on a tie;
     * the order of the array is not relied on.
     */
    private static function largestPhotoFileId(mixed $sizes): ?string
    {
        if (! is_array($sizes)) {
            return null;
        }

        $best = null;
        $bestRank = null;
        foreach ($sizes as $size) {
            if (! is_array($size) || ! is_string($size['file_id'] ?? null) || $size['file_id'] === '') {
                continue;
            }

            $width = is_int($size['width'] ?? null) ? $size['width'] : 0;
            $height = is_int($size['height'] ?? null) ? $size['height'] : 0;
            $bytes = is_int($size['file_size'] ?? null) ? $size['file_size'] : 0;
            $rank = [$width * $height, $bytes];

            if ($bestRank === null || $rank > $bestRank) {
                $best = $size['file_id'];
                $bestRank = $rank;
            }
        }

        return $best;
    }
}
