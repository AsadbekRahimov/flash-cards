<?php

declare(strict_types=1);

namespace App\Domain\Telegram\DTO;

use App\Domain\Telegram\Contracts\TelegramClient;
use InvalidArgumentException;

/**
 * Where the photo of {@see TelegramClient::sendPhotoMessage()} comes from (docs/35 §1.6, O1;
 * Bot API "Sending files"):
 *  - {@see file()} - a local file, uploaded as multipart/form-data (Telegram's limit for a photo is 10 MB);
 *  - {@see fileId()} - a `file_id` from an earlier send of this bot: nothing is uploaded (the poll picture after the first send);
 *  - {@see url()} - an http(s) address Telegram downloads itself (at most 5 MB, the right MIME type).
 */
final readonly class PhotoSource
{
    public const KIND_FILE = 'file';

    public const KIND_FILE_ID = 'file_id';

    public const KIND_URL = 'url';

    /** @param self::KIND_* $kind */
    private function __construct(
        public string $kind,
        public string $value,
    ) {}

    public static function file(string $path): self
    {
        if ($path === '') {
            throw new InvalidArgumentException('A photo file path must not be empty.');
        }

        return new self(self::KIND_FILE, $path);
    }

    public static function fileId(string $fileId): self
    {
        $fileId = trim($fileId);
        if ($fileId === '') {
            throw new InvalidArgumentException('A photo file_id must not be empty.');
        }

        return new self(self::KIND_FILE_ID, $fileId);
    }

    public static function url(string $url): self
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('A photo URL must be an http(s) address.');
        }

        return new self(self::KIND_URL, $url);
    }

    public function isUpload(): bool
    {
        return $this->kind === self::KIND_FILE;
    }
}
