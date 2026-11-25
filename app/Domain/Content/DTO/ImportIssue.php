<?php

declare(strict_types=1);

namespace App\Domain\Content\DTO;

/**
 * One reason a lesson import was refused (VocabularyImporter). `message` is English, for logs and the audit record
 * (it is what {@see ImportReport::$errors} lists); `code` is the stable machine-readable code and `context` the values
 * the message was built from, so that the admin panel can write the same error in Russian (AdminErrors).
 *
 *   file_too_large              the file is over VocabularyImporter::MAX_BYTES
 *   invalid_json                not parseable JSON (`cause`: syntax | encoding | depth | other)
 *   root_not_object             the JSON root is not an object
 *   validation                  the file does not match the schema (`field`, the validator's own `message`)
 *   duplicate_word              the same word twice in one file (`word`, `position` in the list, from 1)
 *   course_not_found            `course` names no existing course (`slug`)
 *   not_allowed                 the importing user may not import into this course
 *   unsupported_locale          a translation in a language the platform does not support
 *   translation_conflict        `translation` and `translations.ru` differ
 *   ru_required                 a word without its ru translation
 *   example_translation_orphan  an example translation in a language the word has no translation in
 *   invalid_phrase              the block `phrase` of a phrase card is wrong (docs/35 §3.4; `word`, `position`, `cause` - one of the
 *                               CAUSE_PHRASE_* causes below; `field`, `gap` when they apply)
 */
final readonly class ImportIssue
{
    public const FILE_TOO_LARGE = 'file_too_large';

    public const INVALID_JSON = 'invalid_json';

    public const ROOT_NOT_OBJECT = 'root_not_object';

    public const VALIDATION = 'validation';

    public const DUPLICATE_WORD = 'duplicate_word';

    public const COURSE_NOT_FOUND = 'course_not_found';

    public const NOT_ALLOWED = 'not_allowed';

    public const UNSUPPORTED_LOCALE = 'unsupported_locale';

    public const TRANSLATION_CONFLICT = 'translation_conflict';

    public const RU_REQUIRED = 'ru_required';

    public const EXAMPLE_TRANSLATION_ORPHAN = 'example_translation_orphan';

    public const INVALID_PHRASE = 'invalid_phrase';

    /** `invalid_phrase`: `part_of_speech` is `phrase` and the entry has no block `phrase`. */
    public const CAUSE_PHRASE_MISSING = 'missing';

    /** `invalid_phrase`: a block `phrase` on an entry whose `part_of_speech` is not `phrase`. */
    public const CAUSE_PHRASE_NOT_PHRASE = 'not_phrase';

    /** `invalid_phrase`: a phrase in a course whose `exam_tag` is not `phrases`. */
    public const CAUSE_PHRASE_NOT_PHRASE_COURSE = 'not_phrase_course';

    /** `invalid_phrase`: a word that is not a phrase in a course of phrases. */
    public const CAUSE_PHRASE_COURSE_WORD = 'phrase_course_word';

    /** `invalid_phrase`: a key of the block the format does not know (`field`). */
    public const CAUSE_PHRASE_UNKNOWN_FIELD = 'unknown_field';

    /** `invalid_phrase`: a field has a value outside its list or range (`field`). */
    public const CAUSE_PHRASE_FIELD = 'field';

    /** `invalid_phrase`: `gap` is absent or blank. */
    public const CAUSE_PHRASE_GAP_EMPTY = 'gap_empty';

    /** `invalid_phrase`: `gap` is not one word of Latin letters. */
    public const CAUSE_PHRASE_GAP_NOT_WORD = 'gap_not_word';

    /** `invalid_phrase`: `gap` does not stand in the phrase as a whole word. */
    public const CAUSE_PHRASE_GAP_MISSING = 'gap_missing';

    /** `invalid_phrase`: `gap` stands in the phrase more than once. */
    public const CAUSE_PHRASE_GAP_REPEATED = 'gap_repeated';

    /** `invalid_phrase`: `gap` is the whole phrase. */
    public const CAUSE_PHRASE_GAP_WHOLE = 'gap_whole';

    /** `invalid_json`: a comma, quote or bracket is wrong. */
    public const CAUSE_SYNTAX = 'syntax';

    /** `invalid_json`: the file is not UTF-8. */
    public const CAUSE_ENCODING = 'encoding';

    /** `invalid_json`: nested deeper than the importer reads. */
    public const CAUSE_DEPTH = 'depth';

    public const CAUSE_OTHER = 'other';

    /** @param  array<string, int|float|string|list<string>>  $context */
    public function __construct(
        public string $code,
        public string $message,
        public array $context = [],
    ) {}
}
