<?php

declare(strict_types=1);

namespace App\Domain\Game\Services\Tasks;

use App\Domain\Game\DTO\RunPoolWord;
use App\Domain\Game\DTO\RunState;
use App\Domain\Game\DTO\TaskGrade;
use App\Domain\Game\Enums\RunEvent;
use App\Domain\Game\Enums\TaskKind;
use App\Domain\Game\Exceptions\GameException;
use App\Domain\Learning\Services\GapFillBuilder;
use App\Domain\Learning\Services\SpellingGrader;
use App\Domain\Practice\DTO\LocaleSet;
use App\Domain\Practice\DTO\SnapshotElement;
use App\Domain\Practice\Enums\Verdict;
use App\Domain\Practice\Support\OpaqueId;
use App\Domain\Practice\Support\SeededShuffle;
use InvalidArgumentException;

/**
 * The five tasks made of the words alone (docs/18 §12.3.1, §12.3.2): no author needed, enough for the whole map.
 *
 *   translate_fwd   the word -> 4 translations          {word_id, prompt: "mother", audio, options: [{id, text: {ru, uz}}], correct_option_id, translation: {ru, uz}}
 *   translate_back  the translation -> 4 words          {word_id, prompt: {ru, uz}, options: [{id, text: "bread"}], correct_option_id, word, translation}
 *   listen          the recording -> 4 words            {word_id, audio_disk, audio_path, options: [{id, text}], correct_option_id, word, translation[, event]}
 *   spell           the translation -> type the word    {word_id, prompt: {ru, uz}, expected: "apple", answer_length, word}
 *   gap             the example with a blank -> type    {word_id, masked: "I ____ tea.", expected: [forms], correct_text, full_text, translation, word}
 *
 * The elements are BUILT here by pure functions of the pool and the seed (`RunComposer` calls them), and graded here: an option by
 * its opaque id; `spell` by `SpellingGrader` (5 = correct, 4 = typo, else wrong); `gap` by the normalized text against the forms of
 * the word (`GapFillBuilder::forms`), a typo when it is within the tolerance of some form. `{skip: true}` is `skipped`. The hint
 * removes two wrong options, or opens the first two letters of the expected text.
 */
final class WordTaskHandler implements TaskHandler
{
    public const DISTRACTORS = 3;

    public const MAX_TEXT = 100;

    public const PREFIX_LETTERS = 2;

    public function __construct(private readonly SpellingGrader $spelling) {}

    /** @return list<TaskKind> */
    public function kinds(): array
    {
        return [TaskKind::TranslateFwd, TaskKind::TranslateBack, TaskKind::Listen, TaskKind::Spell, TaskKind::Gap];
    }

    // ------------------------------------------------------------------ building (pure)

    /**
     * A task with options: the word with three distractors (docs/18 §12.3.1), the options in the order of the seed. `listen` needs the
     * word's recording; a sandstorm (`$event`) is a `listen` built on a word that was to be translated.
     *
     * @param  list<RunPoolWord>  $distractors  exactly {@see DISTRACTORS}, none clashing with the word in any locale
     * @param  list<string>  $taken
     */
    public static function buildChoice(TaskKind $kind, RunPoolWord $word, array $distractors, LocaleSet $locales, string $seed, array &$taken, ?RunEvent $event = null): SnapshotElement
    {
        if (count($distractors) !== self::DISTRACTORS) {
            throw new InvalidArgumentException('A task with options needs exactly '.self::DISTRACTORS.' distractors.');
        }
        if ($kind !== TaskKind::TranslateFwd && $kind !== TaskKind::TranslateBack && $kind !== TaskKind::Listen) {
            throw new InvalidArgumentException("The kind {$kind->value} has no options.");
        }
        if ($kind === TaskKind::Listen && ! $word->hasAudio()) {
            throw new InvalidArgumentException('A listen task needs the recording of the word.');
        }

        $id = OpaqueId::make('e', $taken);
        $taken[] = $id;

        $candidates = [$word, ...$distractors];
        $ids = [];
        foreach ($candidates as $index => $candidate) {
            $ids[$index] = OpaqueId::make('o', $taken);
            $taken[] = $ids[$index];
        }

        $options = [];
        foreach (SeededShuffle::order($seed, "options:{$kind->value}:{$word->id()}", count($candidates)) as $index) {
            $candidate = $candidates[$index];
            $options[] = [
                'id' => $ids[$index],
                'text' => $kind === TaskKind::TranslateFwd ? self::translations($candidate, $locales) : self::text($candidate),
            ];
        }

        $data = [
            'word_id' => $word->id(),
            'word' => self::text($word),
            'translation' => self::translations($word, $locales),
            'options' => $options,
            'correct_option_id' => $ids[0],
        ];
        if ($kind === TaskKind::TranslateFwd) {
            $data = ['prompt' => self::text($word), 'audio' => $word->hasAudio(), ...$data];
        } elseif ($kind === TaskKind::TranslateBack) {
            $data = ['prompt' => self::translations($word, $locales), ...$data];
        } else {
            $data = ['audio_disk' => (string) config('twa.tts.audio_disk', 'public'), 'audio_path' => (string) $word->audioPath, ...$data];
        }
        if ($event !== null) {
            $data['event'] = $event->value;
        }

        return new SnapshotElement($id, $kind->value, $data);
    }

    /** @param list<string> $taken */
    public static function buildSpell(RunPoolWord $word, LocaleSet $locales, array &$taken): SnapshotElement
    {
        $id = OpaqueId::make('e', $taken);
        $taken[] = $id;
        $expected = self::text($word);

        return new SnapshotElement($id, TaskKind::Spell->value, [
            'word_id' => $word->id(),
            'word' => $expected,
            'prompt' => self::translations($word, $locales),
            'expected' => $expected,
            'answer_length' => mb_strlen($expected),
        ]);
    }

    /** @param list<string> $taken */
    public static function buildGap(RunPoolWord $word, LocaleSet $locales, array &$taken): SnapshotElement
    {
        $gap = $word->gap ?? throw new InvalidArgumentException('A gap task needs the example of the word with the word in it.');
        $id = OpaqueId::make('e', $taken);
        $taken[] = $id;

        $forms = [$gap->expected];
        foreach ((new GapFillBuilder)->forms(self::text($word)) as $form) {
            if (! in_array($form, $forms, true)) {
                $forms[] = $form;
            }
        }

        return new SnapshotElement($id, TaskKind::Gap->value, [
            'word_id' => $word->id(),
            'word' => self::text($word),
            'masked' => $gap->masked,
            'expected' => $forms,
            'correct_text' => $gap->expected,
            'full_text' => str_replace(GapFillBuilder::MASK, $gap->expected, $gap->masked),
            'translation' => self::translations($word, $locales),
        ]);
    }

    // ------------------------------------------------------------------ playing

    public function project(SnapshotElement $element, string $locale, RunState $state): array
    {
        $kind = $this->kindOf($element);
        $projection = ['id' => $element->id, 'kind' => $element->kind];

        switch ($kind) {
            case TaskKind::TranslateFwd:
                $projection['prompt'] = (string) $element->get('prompt');
                $projection['audio'] = (bool) $element->get('audio', false);
                $projection['options'] = $this->shownOptions($element, $locale);
                break;
            case TaskKind::TranslateBack:
                $projection['prompt'] = $this->localized($element->get('prompt'), $locale);
                $projection['options'] = $this->shownOptions($element, $locale);
                break;
            case TaskKind::Listen:
                $projection['audio'] = true;
                $projection['options'] = $this->shownOptions($element, $locale);
                break;
            case TaskKind::Spell:
                $projection['prompt'] = $this->localized($element->get('prompt'), $locale);
                $projection['answer_length'] = (int) $element->get('answer_length', 0);
                break;
            default:
                $projection['masked'] = (string) $element->get('masked');
                $projection['translation'] = $this->localized($element->get('translation'), $locale);
        }

        $hint = $state->hintOf($element->id);
        if ($hint !== null) {
            $projection['hint'] = $hint;
        }

        return $projection;
    }

    public function grade(SnapshotElement $element, array $answer, RunState $state): TaskGrade
    {
        $kind = $this->kindOf($element);
        $wordId = (int) $element->get('word_id');
        $reveal = $this->reveal($element, $this->primaryLocale($element), $state);

        if (($answer['skip'] ?? false) === true) {
            return new TaskGrade(Verdict::Skipped, $reveal, ['skip' => true], srsErrorWordIds: [$wordId]);
        }

        if ($kind === TaskKind::Spell || $kind === TaskKind::Gap) {
            $text = $answer['text'] ?? null;
            if (! is_string($text) || mb_strlen($text) > self::MAX_TEXT) {
                throw GameException::invalidAnswerShape();
            }
            if (trim($text) === '') {
                return new TaskGrade(Verdict::Skipped, $reveal, ['skip' => true], srsErrorWordIds: [$wordId]);
            }
            $verdict = $kind === TaskKind::Spell ? $this->gradeSpelling((string) $element->get('expected'), $text) : $this->gradeGap($element, $text);

            return new TaskGrade($verdict, $reveal, ['text' => $text], srsErrorWordIds: $verdict === Verdict::Wrong ? [$wordId] : []);
        }

        $optionId = $answer['option_id'] ?? null;
        if (! is_string($optionId) || $optionId === '') {
            throw GameException::invalidAnswerShape();
        }
        if (! in_array($optionId, array_column($this->options($element), 'id'), true)) {
            throw GameException::unknownOptionId();
        }
        $verdict = $optionId === (string) $element->get('correct_option_id') ? Verdict::Correct : Verdict::Wrong;

        return new TaskGrade($verdict, $reveal, ['option_id' => $optionId], srsErrorWordIds: $verdict === Verdict::Wrong ? [$wordId] : []);
    }

    public function reveal(SnapshotElement $element, string $locale, RunState $state): array
    {
        $kind = $this->kindOf($element);
        $translation = $this->localized($element->get('translation'), $locale);

        return match ($kind) {
            TaskKind::TranslateFwd, TaskKind::TranslateBack, TaskKind::Listen => [
                'correct_id' => (string) $element->get('correct_option_id'),
                'correct_text' => $kind === TaskKind::TranslateFwd ? $translation : (string) $element->get('word'),
                'word' => (string) $element->get('word'),
                'translation' => $translation,
            ],
            TaskKind::Spell => ['correct_text' => (string) $element->get('expected'), 'translation' => $this->localized($element->get('prompt'), $locale)],
            default => ['correct_text' => (string) $element->get('correct_text'), 'full_text' => (string) $element->get('full_text'), 'translation' => $translation],
        };
    }

    public function mistakeLabel(SnapshotElement $element, RunState $state, string $locale): array
    {
        $kind = $this->kindOf($element);
        $translation = $this->localized($kind === TaskKind::Spell ? $element->get('prompt') : $element->get('translation'), $locale);
        // Not `trim($text, ' —')`: the mask is bytes, and the bytes of the dash are the tails of Cyrillic letters ("мир" ends in D1 80, which would be cut
        // to a lone D1 and the JSON of the run would fail with malformed UTF-8).
        $label = preg_replace('/^[\s—]+|[\s—]+$/u', '', (string) $element->get('word').' — '.$translation) ?? '';

        $entry = $state->entry($element->id);
        $answer = is_array($entry['answer'] ?? null) ? $entry['answer'] : [];
        $yours = null;
        if (is_string($answer['text'] ?? null)) {
            $yours = $answer['text'];
        } elseif (is_string($answer['option_id'] ?? null)) {
            foreach ($this->shownOptions($element, $locale) as $option) {
                if ($option['id'] === $answer['option_id']) {
                    $yours = $option['text'];
                }
            }
        }

        return [['label' => $label, 'your_answer' => $yours]];
    }

    public function hint(SnapshotElement $element, RunState $state): array
    {
        $kind = $this->kindOf($element);

        if ($kind === TaskKind::Spell || $kind === TaskKind::Gap) {
            $expected = $kind === TaskKind::Spell ? (string) $element->get('expected') : (string) $element->get('correct_text');

            return ['prefix' => mb_substr($expected, 0, self::PREFIX_LETTERS)];
        }

        $correct = (string) $element->get('correct_option_id');
        $removed = [];
        foreach ($this->options($element) as $option) {
            if ($option['id'] !== $correct && count($removed) < 2) {
                $removed[] = $option['id'];
            }
        }

        return ['options_removed' => $removed];
    }

    public function wordIds(SnapshotElement $element): array
    {
        $wordId = $element->get('word_id');

        return is_int($wordId) || is_numeric($wordId) ? [(int) $wordId] : [];
    }

    // ------------------------------------------------------------------ helpers

    private function gradeSpelling(string $expected, string $text): Verdict
    {
        $quality = $this->spelling->grade($expected, $text)->quality;

        return match (true) {
            $quality >= SpellingGrader::QUALITY_EXACT => Verdict::Correct,
            $quality === SpellingGrader::QUALITY_CLOSE => Verdict::Typo,
            default => Verdict::Wrong,
        };
    }

    private function gradeGap(SnapshotElement $element, string $text): Verdict
    {
        $forms = [];
        foreach (is_array($element->get('expected')) ? $element->get('expected') : [] as $form) {
            if (is_string($form)) {
                $forms[] = $form;
            }
        }

        $normalized = $this->spelling->normalize($text);
        $close = false;
        foreach ($forms as $form) {
            $verdict = $this->spelling->grade($form, $text);
            if ($verdict->normalizedExpected === $normalized) {
                return Verdict::Correct;
            }
            $close = $close || $verdict->quality === SpellingGrader::QUALITY_CLOSE;
        }

        return $close ? Verdict::Typo : Verdict::Wrong;
    }

    private function kindOf(SnapshotElement $element): TaskKind
    {
        $kind = TaskKind::tryFrom($element->kind);
        if ($kind === null || ! $kind->isWordTask()) {
            throw new InvalidArgumentException("Not a task made of a word: {$element->kind}.");
        }

        return $kind;
    }

    /** @return list<array{id: string, text: mixed}> */
    private function options(SnapshotElement $element): array
    {
        $options = [];
        foreach (is_array($element->get('options')) ? $element->get('options') : [] as $option) {
            if (is_array($option) && is_string($option['id'] ?? null)) {
                $options[] = ['id' => $option['id'], 'text' => $option['text'] ?? ''];
            }
        }

        return $options;
    }

    /** @return list<array{id: string, text: string}> */
    private function shownOptions(SnapshotElement $element, string $locale): array
    {
        $shown = [];
        foreach ($this->options($element) as $option) {
            $shown[] = ['id' => $option['id'], 'text' => $this->localized($option['text'], $locale)];
        }

        return $shown;
    }

    /** A text per locale (`{ru, uz}`) in the locale, else the first one; a plain string as it is. */
    private function localized(mixed $texts, string $locale): string
    {
        if (is_string($texts)) {
            return $texts;
        }
        if (! is_array($texts)) {
            return '';
        }
        $text = $texts[$locale] ?? (array_values($texts)[0] ?? '');

        return is_string($text) ? $text : '';
    }

    private function primaryLocale(SnapshotElement $element): string
    {
        $translation = $element->get('translation');

        return is_array($translation) && $translation !== [] ? (string) array_key_first($translation) : 'ru';
    }

    private static function text(RunPoolWord $word): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $word->word->word));
    }

    /** @return array<string, string> */
    private static function translations(RunPoolWord $word, LocaleSet $locales): array
    {
        $texts = [];
        foreach ($locales->locales as $locale) {
            $texts[$locale] = trim($word->word->translationFor($locale));
        }

        return $texts;
    }
}
