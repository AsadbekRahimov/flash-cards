<?php

declare(strict_types=1);

namespace App\Filament\Resources\PracticeItemResource\Support;

use App\Domain\Practice\Enums\PracticeItemType;
use App\Domain\Practice\Support\PracticeContentValidator;
use App\Domain\Practice\Support\PracticeTextNormalizer;
use App\Models\PracticeItem;
use App\Models\PracticeItemWord;

/**
 * Between the form of the task in the admin panel and the data PracticeItemService takes (the form of the import file: texts,
 * never indices).
 *
 * The form keeps the fields of each type under its own name (`sentence.*`, `collocation.*`, `dictation.*`) so that the three
 * sections never clash, and writes lists the way a person types them:
 *   - the fragments of a sentence and the other accepted writings of a dictation: one per line;
 *   - the OTHER accepted orders of a sentence: one order per line, its fragments told apart by " | ";
 *   - the options of a collocation: three rows, each with its text and a switch "correct".
 * The order of the fragments is always the first accepted order of a sentence (PracticeContentValidator wants it so, and the
 * review of an answer shows the first as the right one): the field lists only the orders besides it, and left empty it means "no
 * other order" - the common case needs no second typing of the sentence.
 */
final class PracticeItemFormMapper
{
    /**
     * The state of the form for a task: the place, the target words and the text of the CURRENT version - what the admin edits and
     * what a save is compared to - not the lesson, the level and the words the task shows now (those are the published version's).
     *
     * @return array<string, mixed>
     */
    public static function fill(PracticeItem $item): array
    {
        $version = $item->currentVersion;
        $content = $version->content ?? [];
        $author = app(PracticeContentValidator::class)->authorForm($item->type, $content);

        $state = [
            'course_id' => $item->course_id,
            'type' => $item->type->value,
            'key' => $item->key,
            'lesson_id' => $version !== null ? $version->lesson_id : $item->lesson_id,
            'level' => $version !== null ? $version->level : $item->level,
            'word_ids' => $version !== null
                ? array_values(array_map(static fn (mixed $id): int => (int) $id, $version->target_word_ids))
                : PracticeItemWord::query()->where('item_id', $item->id)->orderBy('word_id')->pluck('word_id')->map(static fn (mixed $id): int => (int) $id)->all(),
        ];

        $state[$item->type->value] = match ($item->type) {
            PracticeItemType::Sentence => [
                'prompt' => self::texts($author['prompt'] ?? null),
                'fragments' => implode("\n", self::strings($author['fragments'] ?? [])),
                'accepted' => implode("\n", array_map(
                    static fn (array $order): string => implode(' | ', $order),
                    self::otherOrders($author),
                )),
                'display' => $author['display'] ?? null,
                'explanation' => self::texts($author['explanation'] ?? null),
            ],
            PracticeItemType::Collocation => [
                'text' => $author['text'] ?? null,
                'options' => self::optionRows(self::strings($author['options'] ?? []), self::strings($author['correct'] ?? [])),
                'translation' => self::texts($author['translation'] ?? null),
                'explanation' => self::texts($author['explanation'] ?? null),
            ],
            PracticeItemType::Dictation => [
                'text' => $author['text'] ?? null,
                // The first accepted writing is the text itself: the field lists the others.
                'accepted' => implode("\n", array_slice(self::strings($author['accepted'] ?? []), 1)),
                'translation' => self::texts($author['translation'] ?? null),
                'explanation' => self::texts($author['explanation'] ?? null),
            ],
        };

        return $state;
    }

    /**
     * The data for PracticeItemService from the state of the form.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function toData(array $state, ?PracticeItemType $type = null): array
    {
        $type ??= PracticeItemType::tryFrom(is_string($state['type'] ?? null) ? $state['type'] : '');
        $section = $type->value ?? '';
        $part = is_array($state[$section] ?? null) ? $state[$section] : [];

        return [
            'type' => $type?->value,
            'key' => $state['key'] ?? null,
            'lesson_id' => filled($state['lesson_id'] ?? null) ? $state['lesson_id'] : null,
            'level' => filled($state['level'] ?? null) ? $state['level'] : null,
            'word_ids' => array_values(is_array($state['word_ids'] ?? null) ? $state['word_ids'] : []),
            'content' => match ($type) {
                PracticeItemType::Sentence => self::sentence($part),
                PracticeItemType::Collocation => self::collocation($part),
                PracticeItemType::Dictation => self::dictation($part),
                null => [],
            },
        ];
    }

    /**
     * @param  array<array-key, mixed>  $part
     * @return array<string, mixed>
     */
    private static function sentence(array $part): array
    {
        $fragments = self::lines($part['fragments'] ?? null);

        // The order of the fragments comes first, whatever the field says; the lines of the field follow. A line that reads like an
        // order already there (the first, or an earlier line) adds nothing and is left out: a list that is one order written twice
        // would be refused.
        $accepted = [$fragments];
        $seen = [self::reading($fragments) => true];
        foreach (self::lines($part['accepted'] ?? null) as $line) {
            $order = array_values(array_filter(array_map('trim', explode('|', $line)), static fn (string $piece): bool => $piece !== ''));

            if (! isset($seen[self::reading($order)])) {
                $seen[self::reading($order)] = true;
                $accepted[] = $order;
            }
        }

        return [
            'prompt' => $part['prompt'] ?? [],
            'fragments' => $fragments,
            'accepted' => $accepted,
            'display' => $part['display'] ?? null,
            'explanation' => $part['explanation'] ?? [],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $part
     * @return array<string, mixed>
     */
    private static function collocation(array $part): array
    {
        $options = [];
        $correct = [];
        foreach (is_array($part['options'] ?? null) ? $part['options'] : [] as $row) {
            $text = is_array($row) && is_string($row['text'] ?? null) ? trim($row['text']) : '';
            if ($text === '') {
                continue;
            }

            $options[] = $text;
            if (is_array($row) && ($row['correct'] ?? false)) {
                $correct[] = $text;
            }
        }

        return [
            'text' => $part['text'] ?? null,
            'options' => $options,
            'correct' => $correct,
            'translation' => $part['translation'] ?? [],
            'explanation' => $part['explanation'] ?? [],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $part
     * @return array<string, mixed>
     */
    private static function dictation(array $part): array
    {
        return [
            'text' => $part['text'] ?? null,
            'accepted' => self::lines($part['accepted'] ?? null),
            'translation' => $part['translation'] ?? [],
            'explanation' => $part['explanation'] ?? [],
        ];
    }

    /**
     * The accepted orders of a sentence that its field lists: all but the first, which is the order of the fragments - accepted
     * always, so nobody types it. A first order that reads otherwise (a task saved before that rule) is not hidden: every order
     * is shown, so that saving the task again loses none of them.
     *
     * @param  array<string, mixed>  $author  the content in the form of the author (texts, not indices)
     * @return list<list<string>>
     */
    private static function otherOrders(array $author): array
    {
        $orders = [];
        foreach (is_array($author['accepted'] ?? null) ? array_values($author['accepted']) : [] as $sequence) {
            $orders[] = self::strings($sequence);
        }

        if ($orders !== [] && self::reading($orders[0]) === self::reading(self::strings($author['fragments'] ?? []))) {
            array_shift($orders);
        }

        return $orders;
    }

    /**
     * How the grader reads a list of fragments: the normalized text of them put in a line.
     *
     * @param  list<string>  $pieces
     */
    private static function reading(array $pieces): string
    {
        return PracticeTextNormalizer::normalize(implode(' ', $pieces));
    }

    /**
     * The lines of a text area that are not empty, trimmed.
     *
     * @return list<string>
     */
    private static function lines(mixed $text): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', is_string($text) ? $text : '') ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * Three rows for the three options of a collocation (the form always shows three, filled or not).
     *
     * @param  list<string>  $options
     * @param  list<string>  $correct
     * @return list<array{text: string, correct: bool}>
     */
    private static function optionRows(array $options, array $correct): array
    {
        $rows = array_map(static fn (string $text): array => ['text' => $text, 'correct' => in_array($text, $correct, true)], $options);
        while (count($rows) < (int) config('practice.content.collocation_options')) {
            $rows[] = ['text' => '', 'correct' => false];
        }

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    private static function texts(mixed $value): array
    {
        $texts = [];
        foreach (is_array($value) ? $value : [] as $locale => $text) {
            if (is_string($text)) {
                $texts[(string) $locale] = $text;
            }
        }

        return $texts;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        $strings = [];
        foreach (is_array($value) ? array_values($value) : [] as $item) {
            $strings[] = is_string($item) ? $item : '';
        }

        return $strings;
    }
}
