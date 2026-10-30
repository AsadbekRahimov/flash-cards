<?php

declare(strict_types=1);

namespace App\Domain\Practice\Exceptions;

use RuntimeException;

/**
 * Why the text of a task (a version) is refused when it is saved or published (docs/16 §4.5): 422 in the API of the editor,
 * the same code strings as the `ImportIssue` codes of the import. `details` = `{field, code}`.
 *
 * The codes are listed here so the editor and the importer (18.7a) share them; the rules that raise them live in
 * PracticeContentValidator (the text) and PracticeItemService (the course, the lesson, the words, the key).
 *
 * `context` carries the values the English message was built from (`word`, `index`, `locale`...), so that the admin panel
 * can word the same error in Russian (AdminErrors) without parsing the message.
 */
final class PracticeContentException extends RuntimeException
{
    public const KEY_INVALID = 'key_invalid';

    public const KEY_DUPLICATE = 'key_duplicate';

    public const TYPE_INVALID = 'type_invalid';

    public const COURSE_INVALID = 'course_invalid';

    public const LESSON_NOT_IN_COURSE = 'lesson_not_in_course';

    public const LEVEL_INVALID = 'level_invalid';

    public const TARGET_WORDS_REQUIRED = 'target_words_required';

    public const TARGET_WORD_UNKNOWN = 'target_word_unknown';

    public const TEXT_REQUIRED = 'text_required';

    public const TEXT_TOO_LONG = 'text_too_long';

    public const LOCALE_TEXT_REQUIRED = 'locale_text_required';

    public const FRAGMENTS_COUNT = 'fragments_count';

    public const FRAGMENT_INVALID = 'fragment_invalid';

    public const ACCEPTED_REQUIRED = 'accepted_required';

    public const ACCEPTED_TOO_MANY = 'accepted_too_many';

    public const ACCEPTED_NOT_PERMUTATION = 'accepted_not_permutation';

    public const ACCEPTED_DUPLICATE = 'accepted_duplicate';

    public const DISPLAY_MISMATCH = 'display_mismatch';

    public const PLACEHOLDER_INVALID = 'placeholder_invalid';

    public const OPTIONS_COUNT = 'options_count';

    public const OPTIONS_DUPLICATE = 'options_duplicate';

    public const CORRECT_INVALID = 'correct_invalid';

    public const ALL_OPTIONS_CORRECT = 'all_options_correct';

    public const DICTATION_ACCEPTED_INVALID = 'dictation_accepted_invalid';

    /** @var array{field: string, code: string} */
    public readonly array $details;

    /** @param array<string, int|string> $context */
    public function __construct(
        public readonly string $errorCode,
        public readonly string $field,
        string $message,
        public readonly array $context = [],
    ) {
        parent::__construct($message);

        $this->details = ['field' => $field, 'code' => $errorCode];
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
