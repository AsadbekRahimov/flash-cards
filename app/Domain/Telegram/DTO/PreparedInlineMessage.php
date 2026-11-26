<?php

declare(strict_types=1);

namespace App\Domain\Telegram\DTO;

use App\Domain\Telegram\Exceptions\TelegramApiException;

/**
 * A message prepared for `WebApp.shareMessage` (`savePreparedInlineMessage`, Bot API 8.0; docs/36 §1в): the `id` the Mini App
 * passes to the client and the moment it stops working (`expiration_date`, Unix time). The lifetime is Telegram's - a cache of
 * prepared messages takes it from here, never from a constant (docs/36 §1е).
 */
final readonly class PreparedInlineMessage
{
    public function __construct(
        public string $id,
        public int $expirationDate,
    ) {}

    /**
     * @param  array<string, mixed>  $message  a `PreparedInlineMessage` object of the Bot API
     *
     * @throws TelegramApiException when Telegram said ok but the answer has no id or no expiration date (as a transport failure: it is
     *                              not a refusal of the request)
     */
    public static function fromArray(array $message): self
    {
        $id = $message['id'] ?? null;
        $expirationDate = $message['expiration_date'] ?? null;

        if (! is_string($id) || $id === '' || ! is_int($expirationDate) || $expirationDate <= 0) {
            throw new TelegramApiException('Telegram API request failed (savePreparedInlineMessage): the answer carried no id or expiration_date');
        }

        return new self($id, $expirationDate);
    }
}
