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
use App\Domain\Practice\Services\Grading\WordFormGrader;
use App\Domain\Practice\Services\PracticeMaterialService;
use App\Domain\Practice\Support\OpaqueId;
use App\Domain\Practice\Support\SeededShuffle;
use App\Models\Learner;
use App\Models\PracticeItemVersion;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * "Word form" (docs/16 §5.6): a sentence with one blank (`We need to make a ___ by Friday.`) and a base word (`decide`); the learner
 * types the form the sentence needs (`decision`). Solo only (`PracticeMode::battleable()`), no clock, no audio. The tasks are authored
 * (`practice_items` of the type `word_form`); the mode picks, projects and grades them ({@see WordFormGrader}: no typo).
 *
 * Material and availability are those of the collocation (CollocationMode): the published tasks of the scope whose target words are
 * ALL introduced to the learner, read through PracticeMaterialService - the scope remembers the rows, so the hub and the opening add no
 * query. A task whose content cannot be played is left out of the count and of the build alike, and logged.
 *
 * The snapshot element: id, kind, item_id, version_id, text, base, accepted (as the author wrote them, the canonical first), full_text
 * (the text with the canonical form in the blank), translation / explanation per locale of the LocaleSet. `project()` shows `id`, `kind`,
 * `text` and `base` - never a right form.
 */
final class WordFormMode implements PracticeModeHandler
{
    /** The blank of the sentence: a task has exactly one. */
    public const BLANK = '___';

    public function __construct(
        private readonly PracticeMaterialService $material,
        private readonly WordFormGrader $grader,
        private readonly LocaleResolver $locales,
    ) {}

    public function mode(): PracticeMode
    {
        return PracticeMode::WordForm;
    }

    public function availability(MaterialScope $scope, ?Learner $learner, LocaleSet $locales): ModeAvailability
    {
        $available = $this->playableCount($scope, $learner);
        $required = $learner === null ? max(1, (int) config('practice.battle.rounds')) : $this->material->minItems($scope);

        return $available >= $required
            ? ModeAvailability::enough($available, $required)
            : ModeAvailability::notEnough($available, $required, $this->material->shortageReason($scope, PracticeItemType::WordForm, $learner, $available, $required));
    }

    public function build(MaterialScope $scope, ?Learner $learner, LocaleSet $locales, string $seed, int $size, bool $strictSize): array
    {
        $size = max(1, $size);
        $playable = $this->playable($scope, $learner);
        $required = $strictSize ? $size : $this->material->minItems($scope);

        if (count($playable) < $required) {
            throw PracticeException::notEnoughMaterial(
                PracticeMode::WordForm,
                ModeAvailability::notEnough(count($playable), $required, $this->material->shortageReason($scope, PracticeItemType::WordForm, $learner, count($playable), $required)),
                $scope->course->slug,
                $scope->lesson?->id,
            );
        }

        $chosen = array_slice(SeededShuffle::apply($playable, $seed, 'word_form:items'), 0, $size);

        $taken = [];
        $elements = [];
        foreach ($chosen as $version) {
            $elements[] = $this->element($version, $locales, $taken);
        }

        return $elements;
    }

    public function project(SnapshotElement $element, string $locale, ElementState $state): array
    {
        return [
            'id' => $element->id,
            'kind' => $element->kind,
            'text' => self::stringOf($element->get('text')),
            'base' => self::stringOf($element->get('base')),
        ];
    }

    public function grade(SnapshotElement $element, array $answer, ElementState $state): GradeResult
    {
        $graded = $this->grader->grade($this->accepted($element), self::stringOf($element->get('base')), $answer);
        $review = $this->review($element, null);

        return new GradeResult(
            verdict: $graded->verdict,
            reveal: [
                'expected' => $review['expected'],
                'alternatives' => $review['alternatives'],
                'your_text' => $graded->reveal['your_text'] ?? null,
                'same_as_base' => (bool) ($graded->reveal['same_as_base'] ?? false),
                'full_text' => $review['full_text'],
                'translation' => $review['translation'],
                'explanation' => $review['explanation'],
            ],
            normalizedAnswer: $graded->normalizedAnswer,
            points: $graded->points,
        );
    }

    /**
     * The review for a viewer of `$locale`, independent of any answer: the canonical form, the other right ones, the whole sentence, the
     * translation and the explanation in that language.
     *
     * @return array{expected: string, alternatives: list<string>, full_text: string, translation: string|null, explanation: string|null}
     */
    public function reveal(SnapshotElement $element, string $locale, ElementState $state): array
    {
        return $this->review($element, $locale);
    }

    public function mistakeLabel(SnapshotElement $element, ElementState $state, string $locale): array
    {
        // The sentence as it should be, and the sentence with what the learner typed in the blank (nothing when skipped).
        $typed = $state->answer['text'] ?? null;
        $yourAnswer = is_string($typed) && trim($typed) !== ''
            ? str_replace(self::BLANK, trim($typed), self::stringOf($element->get('text')))
            : null;

        return [['label' => self::stringOf($element->get('full_text')), 'your_answer' => $yourAnswer]];
    }

    /**
     * @return array{expected: string, alternatives: list<string>, full_text: string, translation: string|null, explanation: string|null}
     */
    private function review(SnapshotElement $element, ?string $locale): array
    {
        $accepted = $this->accepted($element);

        return [
            'expected' => $accepted[0] ?? '',
            'alternatives' => array_slice($accepted, 1),
            'full_text' => self::stringOf($element->get('full_text')),
            'translation' => $this->textIn($element->get('translation'), $locale),
            'explanation' => $this->textIn($element->get('explanation'), $locale),
        ];
    }

    /** How many tasks can be played, for the availability: the rows of the versions, nothing hydrated; the test of {@see parse()}. */
    private function playableCount(MaterialScope $scope, ?Learner $learner): int
    {
        return $scope->remember('word_form:count:'.($learner->id ?? 0), function () use ($scope, $learner): int {
            $count = 0;
            foreach ($this->material->eligibleRows($scope, PracticeItemType::WordForm, $learner) as $row) {
                if (self::parse($row['content']) !== null) {
                    $count++;

                    continue;
                }
                Log::warning('practice.word_form_content_unplayable', ['item_id' => $row['item_id'], 'version_id' => $row['id']]);
            }

            return $count;
        });
    }

    /**
     * The versions that can be played in the scope, remembered by it: the same list serves the check of the opening and the build.
     *
     * @return list<PracticeItemVersion>
     */
    private function playable(MaterialScope $scope, ?Learner $learner): array
    {
        return $scope->remember('word_form:playable:'.($learner->id ?? 0), function () use ($scope, $learner): array {
            $playable = [];
            foreach ($this->material->eligibleItems($scope, PracticeItemType::WordForm, $learner) as $version) {
                if (self::parse($version->content) !== null) {
                    $playable[] = $version;

                    continue;
                }
                Log::warning('practice.word_form_content_unplayable', ['item_id' => $version->item_id, 'version_id' => $version->id]);
            }

            return $playable;
        });
    }

    /**
     * The task of a version as the mode plays it, or null when it cannot be played: not exactly one blank, no base word, no right form
     * (each a text that is not empty). The rules of publication are PracticeContentValidator's; these are the ones without which nothing
     * can be graded.
     *
     * @param  array<string, mixed>  $content
     * @return array{text: string, base: string, accepted: list<string>}|null
     */
    private static function parse(array $content): ?array
    {
        $text = $content['text'] ?? null;
        $base = $content['base'] ?? null;
        if (! is_string($text) || substr_count($text, self::BLANK) !== 1 || ! is_string($base) || trim($base) === '') {
            return null;
        }

        $accepted = [];
        foreach (is_array($content['accepted'] ?? null) ? $content['accepted'] : [] as $form) {
            if (! is_string($form) || trim($form) === '') {
                return null;
            }
            $accepted[] = $form;
        }

        return $accepted === [] ? null : ['text' => $text, 'base' => $base, 'accepted' => $accepted];
    }

    /**
     * @param  list<string>  $taken  the ids already used in the snapshot (grows)
     */
    private function element(PracticeItemVersion $version, LocaleSet $locales, array &$taken): SnapshotElement
    {
        $task = self::parse($version->content) ?? throw new LogicException("The version {$version->id} is not a playable word form.");

        $id = OpaqueId::make('e', $taken);
        $taken[] = $id;

        return new SnapshotElement($id, PracticeMode::WordForm->elementKind(), [
            'item_id' => $version->item_id,
            'version_id' => $version->id,
            'text' => $task['text'],
            'base' => $task['base'],
            'accepted' => $task['accepted'],
            'full_text' => str_replace(self::BLANK, $task['accepted'][0], $task['text']),
            'translation' => $this->textsFor($version->content['translation'] ?? null, $locales),
            'explanation' => $this->textsFor($version->content['explanation'] ?? null, $locales),
        ]);
    }

    /** @return list<string> */
    private function accepted(SnapshotElement $element): array
    {
        $accepted = [];
        foreach (is_array($element->get('accepted')) ? $element->get('accepted') : [] as $form) {
            if (is_string($form)) {
                $accepted[] = $form;
            }
        }

        return $accepted;
    }

    /**
     * One text per locale of the set: the locale's own, else the fallback one; a locale with nothing in either has no entry.
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

    private static function stringOf(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
