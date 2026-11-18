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

    public const MARKUP_NOT_ALLOWED = 'markup_not_allowed';

    public const LOCALE_TEXT_REQUIRED = 'locale_text_required';

    public const FRAGMENTS_COUNT = 'fragments_count';

    public const FRAGMENT_INVALID = 'fragment_invalid';

    public const ACCEPTED_REQUIRED = 'accepted_required';

    public const ACCEPTED_TOO_MANY = 'accepted_too_many';

    public const ACCEPTED_NOT_PERMUTATION = 'accepted_not_permutation';

    public const ACCEPTED_DUPLICATE = 'accepted_duplicate';

    public const ACCEPTED_FIRST_MISMATCH = 'accepted_first_mismatch';

    public const DISPLAY_MISMATCH = 'display_mismatch';

    public const PLACEHOLDER_INVALID = 'placeholder_invalid';

    public const OPTIONS_COUNT = 'options_count';

    public const OPTIONS_DUPLICATE = 'options_duplicate';

    public const CORRECT_INVALID = 'correct_invalid';

    public const ALL_OPTIONS_CORRECT = 'all_options_correct';

    public const DICTATION_ACCEPTED_INVALID = 'dictation_accepted_invalid';

    /** "Word form" (docs/16 §5.6): the base word is not one or two words of letters. */
    public const BASE_INVALID = 'base_invalid';

    /** "Word form": a right answer is empty, too long, of too many words or not letters (`cause`: empty | length | tokens | chars). */
    public const ANSWER_INVALID = 'answer_invalid';

    /** "Word form": a right answer reads like the base word. */
    public const ANSWER_EQUALS_BASE = 'answer_equals_base';

    /** "Find the mistake" (docs/16 §5.7): not exactly one whole word marked `*…*`. */
    public const ERROR_MARK_INVALID = 'error_mark_invalid';

    /** "Find the mistake": too few or too many words in the sentence. */
    public const TOKENS_COUNT = 'tokens_count';

    /** "Find the mistake": a word is only punctuation or too long. */
    public const TOKEN_INVALID = 'token_invalid';

    /** "Find the mistake": a correction is the wrong word itself. */
    public const OPTION_IS_ERROR = 'option_is_error';

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
