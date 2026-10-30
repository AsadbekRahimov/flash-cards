<?php

declare(strict_types=1);

namespace App\Domain\Practice\Services\Modes;

use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Practice\DTO\ElementState;
use App\Domain\Practice\DTO\GradeResult;
use App\Domain\Practice\DTO\LocaleSet;
use App\Domain\Practice\DTO\MaterialScope;
use App\Domain\Practice\DTO\ModeAvailability;
use App\Domain\Practice\DTO\SnapshotElement;
use App\Domain\Practice\Enums\PracticeItemType;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\Grading\CollocationGrader;
use App\Domain\Practice\Services\PracticeMaterialService;
use App\Domain\Practice\Support\OpaqueId;
use App\Domain\Practice\Support\SeededShuffle;
use App\Models\Learner;
use App\Models\PracticeItemVersion;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * "Pick the collocation" (docs/16 §1.2, §4.2, §5.4; docs/15 §3.3): a short phrase with a blank (`Let's ___ a meeting.`) and
 * three options, one or two of which are right. The tasks are authored (`practice_items` of the type `collocation`); the
 * mode only picks, lays out, projects and grades them.
 *
 * Material: the published tasks of the scope whose target words are ALL introduced to the learner (a battle: all active),
 * through PracticeMaterialService - the scope remembers what was read, so the hub and the opening of a session add no query.
 * A solo session plays `min(size, available)` tasks and needs `practice.solo.items.min` of them (one for the retry of
 * mistakes); a battle needs exactly its rounds. A task whose content cannot be played (not one blank, fewer than two options,
 * no right option or nothing wrong) is left out of the count AND of the build alike, and logged: the publication check
 * (PracticeItemService) is the guard, this is the seat belt.
 *
 * The snapshot element keeps everything the game needs, so a later edit of the task changes nothing in a game that is on:
 *   id, kind, item_id, version_id (retry, analytics), text, options [{id, text}] in the shown order, correct_ids,
 *   full_text (the text with the blank filled by the FIRST right option of the author), translation / explanation per locale
 *   of the LocaleSet (a battle keeps those of both players). The options are shuffled by `SeededShuffle` with a label of
 *   their own for every task, and wear opaque ids - nothing in the order or in the ids says which one is right.
 *
 * `project()` shows `id`, `kind`, `text` and the options (id, text) - nothing else. The review (`reveal()`) comes from the
 * SAME snapshot element as the grading, so the explanation can never contradict the verdict.
 */
final class CollocationMode implements PracticeModeHandler
{
    /** The blank of the phrase: a task has exactly one. */
    public const BLANK = '___';

    public function __construct(
        private readonly PracticeMaterialService $material,
        private readonly CollocationGrader $grader,
        private readonly LocaleResolver $locales,
    ) {}

    public function mode(): PracticeMode
    {
        return PracticeMode::Collocation;
    }

    public function availability(MaterialScope $scope, ?Learner $learner, LocaleSet $locales): ModeAvailability
    {
        $available = count($this->playable($scope, $learner));
        $required = $learner === null ? max(1, (int) config('practice.battle.rounds')) : $this->material->minItems($scope);

        return $available >= $required
            ? ModeAvailability::enough($available, $required)
            : ModeAvailability::notEnough($available, $required, ModeAvailability::REASON_NO_ITEMS);
    }

    public function build(MaterialScope $scope, ?Learner $learner, LocaleSet $locales, string $seed, int $size, bool $strictSize): array
    {
        $size = max(1, $size);
        $playable = $this->playable($scope, $learner);
        $required = $strictSize ? $size : $this->material->minItems($scope);

        if (count($playable) < $required) {
            throw PracticeException::notEnoughMaterial(
                PracticeMode::Collocation,
                ModeAvailability::notEnough(count($playable), $required, ModeAvailability::REASON_NO_ITEMS),
                $scope->course->slug,
                $scope->lesson?->id,
            );
        }

        $chosen = array_slice(SeededShuffle::apply($playable, $seed, 'collocation:items'), 0, $size);

        $taken = [];
        $elements = [];
        foreach ($chosen as $version) {
            $elements[] = $this->element($version, $locales, $seed, $taken);
        }

        return $elements;
    }

    public function project(SnapshotElement $element, string $locale, ElementState $state): array
    {
        $options = [];
        foreach ($this->options($element) as $option) {
            $options[] = ['id' => $option['id'], 'text' => $option['text']];
        }

        return [
            'id' => $element->id,
            'kind' => $element->kind,
            'text' => self::stringOf($element->get('text')),
            'options' => $options,
        ];
    }

    public function grade(SnapshotElement $element, array $answer, ElementState $state): GradeResult
    {
        $graded = $this->grader->grade(array_column($this->options($element), 'id'), $this->correctIds($element), $answer);

        return new GradeResult(
            verdict: $graded->verdict,
            reveal: $this->reveal($element),
            normalizedAnswer: $graded->normalizedAnswer,
            final: $graded->final,
            points: $graded->points,
            firstAttempt: $graded->firstAttempt,
            extra: $graded->extra,
        );
    }

    public function mistakeLabel(SnapshotElement $element, ElementState $state, string $locale): array
    {
        // The phrase as it should be, and the phrase the learner made of it: the two lines read side by side.
        $picked = $state->answer['option_id'] ?? null;
        $yourAnswer = null;
        foreach ($this->options($element) as $option) {
            if ($option['id'] === $picked) {
                $yourAnswer = str_replace(self::BLANK, $option['text'], self::stringOf($element->get('text')));
            }
        }

        return [['label' => self::stringOf($element->get('full_text')), 'your_answer' => $yourAnswer]];
    }

    /**
     * What the learner is shown after the answer, in his language: the right options, the whole phrase, the translation and
     * the short explanation. Read from the element of the snapshot - the very one the answer was graded on. `$locale`: the
     * viewer's (a battle keeps the texts of both players; two of them can look at the same element); without it the first
     * text the element has - a solo session has just one.
     *
     * @return array{correct_option_ids: list<string>, full_text: string, translation: string|null, explanation: string|null}
     */
    public function reveal(SnapshotElement $element, ?string $locale = null): array
    {
        return [
            'correct_option_ids' => $this->correctIds($element),
            'full_text' => self::stringOf($element->get('full_text')),
            'translation' => $this->textIn($element->get('translation'), $locale),
            'explanation' => $this->textIn($element->get('explanation'), $locale),
        ];
    }

    /**
     * The versions that can be played in the scope, remembered by it: the same list serves the hub, the check of the opening and the build.
     *
     * @return list<PracticeItemVersion>
     */
    private function playable(MaterialScope $scope, ?Learner $learner): array
    {
        return $scope->remember('collocation:playable:'.($learner->id ?? 0), function () use ($scope, $learner): array {
            $playable = [];
            foreach ($this->material->eligibleItems($scope, PracticeItemType::Collocation, $learner) as $version) {
                if (self::parse($version->content) !== null) {
                    $playable[] = $version;

                    continue;
                }
                Log::warning('practice.collocation_content_unplayable', ['item_id' => $version->item_id, 'version_id' => $version->id]);
            }

            return $playable;
        });
    }

    /**
     * The task of a version as the mode plays it, or null when its content cannot be played: the phrase has not exactly one
     * blank, there are fewer than two options (each a text), `correct` names no option, an option that does not exist, or
     * all of them. (The rules of publication are PracticeItemService's; these are the ones without which nothing can be graded.)
     *
     * @param  array<string, mixed>  $content
     * @return array{text: string, options: list<string>, correct: list<int>}|null `correct`: the indices of the right options, the author's first first
     */
    private static function parse(array $content): ?array
    {
        $text = $content['text'] ?? null;
        if (! is_string($text) || substr_count($text, self::BLANK) !== 1) {
            return null;
        }

        $options = [];
        foreach (is_array($content['options'] ?? null) ? $content['options'] : [] as $option) {
            if (! is_string($option) || trim($option) === '') {
                return null;
            }
            $options[] = $option;
        }

        $correct = [];
        foreach (is_array($content['correct'] ?? null) ? $content['correct'] : [] as $index) {
            if (! is_int($index) || ! isset($options[$index])) {
                return null;
            }
            $correct[$index] = $index;
        }
        $correct = array_values($correct);

        if (count($options) < 2 || $correct === [] || count($correct) >= count($options)) {
            return null;
        }

        return ['text' => $text, 'options' => $options, 'correct' => $correct];
    }

    /**
     * The element of the snapshot for a version: the options shuffled (a label of their own per version: two tasks do not
     * share a layout), the ids opaque, the texts of the locales of the set.
     *
     * @param  list<string>  $taken  the ids already used in the snapshot (grows)
     */
    private function element(PracticeItemVersion $version, LocaleSet $locales, string $seed, array &$taken): SnapshotElement
    {
        $task = self::parse($version->content) ?? throw new LogicException("The version {$version->id} is not a playable collocation.");

        $id = OpaqueId::make('e', $taken);
        $taken[] = $id;

        /** @var array<int, string> $idByIndex authored index => opaque id */
        $idByIndex = [];
        foreach (array_keys($task['options']) as $index) {
            $idByIndex[$index] = OpaqueId::make('o', $taken);
            $taken[] = $idByIndex[$index];
        }

        $options = [];
        foreach (SeededShuffle::order($seed, "collocation:options:{$version->id}", count($task['options'])) as $index) {
            $options[] = ['id' => $idByIndex[$index], 'text' => $task['options'][$index]];
        }

        return new SnapshotElement($id, PracticeMode::Collocation->elementKind(), [
            'item_id' => $version->item_id,
            'version_id' => $version->id,
            'text' => $task['text'],
            'options' => $options,
            'correct_ids' => array_map(static fn (int $index): string => $idByIndex[$index], $task['correct']),
            'full_text' => str_replace(self::BLANK, $task['options'][$task['correct'][0]], $task['text']),
            'translation' => $this->textsFor($version->content['translation'] ?? null, $locales),
            'explanation' => $this->textsFor($version->content['explanation'] ?? null, $locales),
        ]);
    }

    /**
     * One text per locale of the set: the locale's own, else the fallback one (the way the names of courses and the
     * translations of words are shown); a locale with nothing in either has no entry.
     *
     * @return array<string, string>
     */
    private function textsFor(mixed $texts, LocaleSet $locales): array
    {
        if (! is_array($texts)) {
            return [];
        }

        $byLocale = [];
        foreach ($locales->locales as $locale) {
            foreach ([$locale, $this->locales->fallback()] as $key) {
                $text = $texts[$key] ?? null;
                if (is_string($text) && trim($text) !== '') {
                    $byLocale[$locale] = $text;

                    break;
                }
            }
        }

        return $byLocale;
    }

    /** The text of the locale from the map of the snapshot; without a locale (or one the snapshot lacks) the first one. */
    private function textIn(mixed $texts, ?string $locale): ?string
    {
        if (! is_array($texts)) {
            return null;
        }
        if ($locale !== null && is_string($texts[$locale] ?? null)) {
            return $texts[$locale];
        }

        foreach ($texts as $text) {
            if (is_string($text) && $text !== '') {
                return $text;
            }
        }

        return null;
    }

    /**
     * The options of the element as the snapshot keeps them, in the shown order.
     *
     * @return list<array{id: string, text: string}>
     */
    private function options(SnapshotElement $element): array
    {
        $options = [];
        foreach (is_array($element->get('options')) ? $element->get('options') : [] as $option) {
            if (is_array($option) && is_string($option['id'] ?? null) && is_string($option['text'] ?? null)) {
                $options[] = ['id' => $option['id'], 'text' => $option['text']];
            }
        }

        return $options;
    }

    /** @return list<string> */
    private function correctIds(SnapshotElement $element): array
    {
        $ids = [];
        foreach (is_array($element->get('correct_ids')) ? $element->get('correct_ids') : [] as $id) {
            if (is_string($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private static function stringOf(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
