<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Content\DTO\DeckParseResult;
use App\Domain\Content\DTO\DeckRow;
use InvalidArgumentException;
use Normalizer;

/**
 * Turns the text a learner typed or pasted into deck rows (docs/04 A.10).
 *
 *   list: one word per line — `word — перевод`, `word - перевод`, `word;перевод`, `word<TAB>перевод`,
 *         `word = перевод`; split at the FIRST separator, so a translation may contain `;`.
 *   csv:  `word,translation_ru,translation_uz,example`; the header row is optional; the delimiter
 *         (`,` `;` or TAB) is taken from the first line; RFC 4180 quoting.
 *
 * Every field is cleaned (tags stripped, NFC, control characters dropped, whitespace collapsed,
 * typographic apostrophes folded to `'`). Lines are thrown away — and reported with a reason —
 * instead of failing the whole text: no translation, junk (a "word" without letters or with symbols
 * outside the word alphabet, links), too long (word > 100, translation > 500, example > 300 characters),
 * a repeat of an earlier line (case-insensitive) and everything past the line limit. The user text
 * is only ever stored, never executed, rendered as HTML or interpreted.
 *
 * Stateless: the same input always gives the same rows.
 */
final class DeckImportParser
{
    public const FORMAT_LIST = 'list';

    public const FORMAT_CSV = 'csv';

    public const MAX_WORD = 100;

    public const MAX_TRANSLATION = 500;

    public const MAX_EXAMPLE = 300;

    /** Separators of a list line; the leftmost one wins. A bare hyphen only counts with spaces around it ("well-known"). */
    private const LIST_SEPARATOR = '/\s*(?:\t|;|\s[-\x{2013}\x{2014}\x{2212}]\s|[\x{2013}\x{2014}]|\s=\s|\s->\s|\s\x{2192}\s)\s*/u';

    private const WORD_PATTERN = '/^[\p{L}\p{N}][\p{L}\p{N}\p{M} \'\-.,\/()?!&]*$/u';

    private const LINK_PATTERN = '/https?:|www\.|:\/\/|\b[\w.-]+@[\w-]+\.\w/iu';

    /**
     * @param  string  $locale  interface locale of the learner: a list line carries ONE translation, written
     *                          in this language (`uz` also fills the uz translation)
     *
     * @throws InvalidArgumentException for an unknown format or a text over the size cap (the request layer checks it first)
     */
    public function parse(string $text, string $format, string $locale = 'ru'): DeckParseResult
    {
        if (! in_array($format, [self::FORMAT_LIST, self::FORMAT_CSV], true)) {
            throw new InvalidArgumentException("Unknown deck import format '{$format}'.");
        }

        if (strlen($text) > $this->maxBytes()) {
            throw new InvalidArgumentException('Deck import text is too large.');
        }

        $text = mb_scrub($text);
        $text = preg_replace('/^\x{FEFF}/u', '', $text) ?? $text;

        $records = $format === self::FORMAT_CSV ? $this->csvRecords($text) : $this->listRecords($text, $locale);

        $maxLines = max(1, (int) config('decks.import.max_lines', 500));
        $rows = [];
        $rejected = [];
        $seen = [];
        $count = 0;

        foreach ($records as $line => $record) {
            if (++$count > $maxLines) {
                $rejected[] = ['line' => $line, 'reason' => DeckParseResult::REASON_OVER_LIMIT];

                continue;
            }

            $row = $this->accept($record, $rejectedReason);
            if ($row === null) {
                $rejected[] = ['line' => $line, 'reason' => (string) $rejectedReason];

                continue;
            }

            if (isset($seen[$row->key()])) {
                $rejected[] = ['line' => $line, 'reason' => DeckParseResult::REASON_DUPLICATE];

                continue;
            }

            $seen[$row->key()] = true;
            $rows[] = $row;
        }

        return new DeckParseResult($rows, $rejected);
    }

    /** One-line text for titles and fields: no tags, NFC, no control characters, single spaces, trimmed. */
    public function sanitize(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $value = strip_tags(mb_scrub($value));
        if (class_exists(Normalizer::class)) {
            $normalized = Normalizer::normalize($value, Normalizer::FORM_C);
            if ($normalized !== false) {
                $value = $normalized;
            }
        }

        $value = preg_replace('/[\x{2018}\x{2019}\x{02BC}\x{0060}\x{00B4}]/u', "'", $value) ?? $value;
        $value = preg_replace('/[\p{Cc}\p{Cf}\p{Z}]+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * @param  array{word:?string, translation:?string, uz:?string, example:?string}  $record
     *
     * @param-out string|null $reason
     */
    private function accept(array $record, ?string &$reason): ?DeckRow
    {
        $reason = null;

        $word = $this->sanitize($record['word']);
        $translation = $this->sanitize($record['translation']);
        $uz = $this->sanitize($record['uz']);
        $example = $this->sanitize($record['example']);

        // A CSV row with only the uz column still has a translation: it doubles as the default one.
        if ($translation === '' && $uz !== '') {
            $translation = $uz;
        }

        if (mb_strlen($word) > self::MAX_WORD
            || mb_strlen($translation) > self::MAX_TRANSLATION
            || mb_strlen($uz) > self::MAX_TRANSLATION
            || mb_strlen($example) > self::MAX_EXAMPLE) {
            $reason = DeckParseResult::REASON_TOO_LONG;

            return null;
        }

        if ($word === '' || preg_match(self::WORD_PATTERN, $word) !== 1 || preg_match('/\p{L}/u', $word) !== 1) {
            $reason = DeckParseResult::REASON_JUNK;

            return null;
        }

        if ($translation === '') {
            $reason = DeckParseResult::REASON_EMPTY_TRANSLATION;

            return null;
        }

        foreach ([$word, $translation, $uz, $example] as $field) {
            if ($field !== '' && preg_match(self::LINK_PATTERN, $field) === 1) {
                $reason = DeckParseResult::REASON_JUNK;

                return null;
            }
        }

        if (preg_match('/\p{L}/u', $translation) !== 1 || ($uz !== '' && preg_match('/\p{L}/u', $uz) !== 1)) {
            $reason = DeckParseResult::REASON_JUNK;

            return null;
        }

        return new DeckRow($word, $translation, $uz === '' ? null : $uz, $example === '' ? null : $example);
    }

    /**
     * @return iterable<int, array{word:?string, translation:?string, uz:?string, example:?string}> by 1-based line number
     */
    private function listRecords(string $text, string $locale): iterable
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];

        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }

            $parts = preg_split(self::LIST_SEPARATOR, $line, 2) ?: [$line];
            $translation = $parts[1] ?? null;

            yield $index + 1 => [
                'word' => $parts[0],
                'translation' => $translation,
                'uz' => $locale === 'uz' ? $translation : null,
                'example' => null,
            ];
        }
    }

    /**
     * @return iterable<int, array{word:?string, translation:?string, uz:?string, example:?string}> by 1-based record number
     */
    private function csvRecords(string $text): iterable
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return;
        }

        try {
            fwrite($handle, $text);
            rewind($handle);

            $delimiter = $this->csvDelimiter($text);
            $columns = ['word' => 0, 'translation' => 1, 'uz' => 2, 'example' => 3];
            $line = 0;
            $first = true;

            while (($cells = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
                $line++;

                if ($cells === [null]) {
                    continue;
                }

                if ($first) {
                    $first = false;
                    $header = $this->csvHeader($cells);
                    if ($header !== null) {
                        $columns = $header;

                        continue;
                    }
                }

                $cell = static fn (?int $i): ?string => $i === null ? null : ($cells[$i] ?? null);

                yield $line => [
                    'word' => $cell($columns['word']),
                    'translation' => $cell($columns['translation']),
                    'uz' => $cell($columns['uz']),
                    'example' => $cell($columns['example']),
                ];
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Column positions of a header row, or null when the first row is data.
     * A header needs `word` and at least one translation column.
     *
     * @param  list<string|null>  $cells
     * @return array{word:int, translation:int|null, uz:int|null, example:int|null}|null
     */
    private function csvHeader(array $cells): ?array
    {
        $names = array_map(fn (?string $c): string => strtolower($this->sanitize($c)), $cells);

        $find = static function (array $candidates) use ($names): ?int {
            foreach ($names as $i => $name) {
                if (in_array($name, $candidates, true)) {
                    return $i;
                }
            }

            return null;
        };

        $word = $find(['word']);
        $translation = $find(['translation_ru', 'translation', 'ru']);
        $uz = $find(['translation_uz', 'uz']);

        if ($word === null || ($translation === null && $uz === null)) {
            return null;
        }

        return ['word' => $word, 'translation' => $translation, 'uz' => $uz, 'example' => $find(['example'])];
    }

    /** `,` `;` or TAB — whichever the first non-empty line has most of; `,` when none. */
    private function csvDelimiter(string $text): string
    {
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            $best = ',';
            $bestCount = 0;
            foreach ([',', ';', "\t"] as $candidate) {
                $count = substr_count($line, $candidate);
                if ($count > $bestCount) {
                    $best = $candidate;
                    $bestCount = $count;
                }
            }

            return $best;
        }

        return ',';
    }

    private function maxBytes(): int
    {
        return max(1, (int) config('decks.import.max_kb', 256)) * 1024;
    }
}
