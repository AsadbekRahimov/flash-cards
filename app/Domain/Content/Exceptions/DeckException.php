<?php

declare(strict_types=1);

namespace App\Domain\Content\Exceptions;

use RuntimeException;

/**
 * A business error of learner decks. `errorCode` is the stable machine-readable code of the TWA API;
 * `httpStatus()` its status. `deck_limit_reached` / `deck_words_limit_reached` are paywall reasons:
 * the client answers them with the paywall sheet.
 */
final class DeckException extends RuntimeException
{
    public const NOT_FOUND = 'deck_not_found';

    public const WORD_NOT_FOUND = 'deck_word_not_found';

    public const LIMIT_REACHED = 'deck_limit_reached';

    public const WORDS_LIMIT_REACHED = 'deck_words_limit_reached';

    public const IMPORT_EMPTY = 'deck_import_empty';

    public const TITLE_INVALID = 'deck_title_invalid';

    private function __construct(public readonly string $errorCode, string $message, private readonly int $status)
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return $this->status;
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND, 'Deck not found.', 404);
    }

    public static function wordNotFound(): self
    {
        return new self(self::WORD_NOT_FOUND, 'Deck word not found.', 404);
    }

    public static function decksLimit(int $limit): self
    {
        return new self(self::LIMIT_REACHED, "Deck limit of the plan is reached ({$limit}).", 422);
    }

    public static function wordsLimit(int $limit, int $free): self
    {
        return new self(self::WORDS_LIMIT_REACHED, "Deck word limit of the plan is reached ({$limit}); {$free} more fit.", 422);
    }

    public static function importEmpty(): self
    {
        return new self(self::IMPORT_EMPTY, 'No valid lines found in the text.', 422);
    }

    public static function titleInvalid(): self
    {
        return new self(self::TITLE_INVALID, 'Deck title is empty after cleaning.', 422);
    }
}
