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
use App\Domain\Practice\Enums\Verdict;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\Grading\ErrorHuntGrader;
use App\Domain\Practice\Services\PracticeMaterialService;
use App\Domain\Practice\Support\ErrorHuntText;
use App\Domain\Practice\Support\OpaqueId;
use App\Domain\Practice\Support\SeededShuffle;
use App\Models\Learner;
use App\Models\PracticeItemVersion;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * "Find the mistake" (docs/16 §5.7): a sentence with exactly one wrong word. TWO answers per element: the step `locate` (`{token_id}`:
 * which word is wrong), then the step `fix` (`{option_id}`: which of three corrections is right). The corrections reach the client only
 * AFTER the step `locate` is accepted - they name the wrong word. The step `fix` comes after a wrong `locate` too: the mistake is shown and
 * the learner still picks the correction. `{skip: true}` at either step ends the element. Solo only, no clock, no audio.
 *
 * Material and availability are those of the collocation (CollocationMode): published tasks of the type `error_hunt` whose target words
 * are all introduced to the learner, read through PracticeMaterialService (no query of its own).
 *
 * The snapshot element: id, kind, item_id, version_id, tokens [{id, text}] in the order of the sentence (not shuffled: only the
 * randomness of the ids is secret), error_id, options [{id, text}] (SeededShuffle, a label per version), correct_ids, wrong_text (the
 * sentence as the learner sees it), full_text (the corrected one: the core of the wrong word replaced, its punctuation kept), translation /
 * explanation per locale.
 *
 * The step lives in the block of the mode, `state.pairs[element]` (ElementState::$extra): `{located, located_ok}` after `locate`, then
 * `{…, fixed, fixed_ok}`. The element is answered (and `correct_first` may grow) only at the final step: `GradeResult::$final` is false
 * after `locate`. The verdict of the element is `correct` only when both steps were right.
 */
final class ErrorHuntMode implements PracticeModeHandler
{
    public function __construct(
        private readonly PracticeMaterialService $material,
        private readonly ErrorHuntGrader $grader,
        private readonly LocaleResolver $locales,
    ) {}

    public function mode(): PracticeMode
    {
        return PracticeMode::ErrorHunt;
    }

    public function availability(MaterialScope $scope, ?Learner $learner, LocaleSet $locales): ModeAvailability
    {
        $available = $this->playableCount($scope, $learner);
        $required = $learner === null ? max(1, (int) config('practice.battle.rounds')) : $this->material->minItems($scope);

        return $available >= $required
            ? ModeAvailability::enough($available, $required)
            : ModeAvailability::notEnough($available, $required, $this->material->shortageReason($scope, PracticeItemType::ErrorHunt, $learner, $available, $required));
    }

    public function build(MaterialScope $scope, ?Learner $learner, LocaleSet $locales, string $seed, int $size, bool $strictSize): array
    {
        $size = max(1, $size);
        $playable = $this->playable($scope, $learner);
        $required = $strictSize ? $size : $this->material->minItems($scope);

        if (count($playable) < $required) {
            throw PracticeException::notEnoughMaterial(
                PracticeMode::ErrorHunt,
                ModeAvailability::notEnough(count($playable), $required, $this->material->shortageReason($scope, PracticeItemType::ErrorHunt, $learner, count($playable), $required)),
                $scope->course->slug,
                $scope->lesson?->id,
            );
        }

        $chosen = array_slice(SeededShuffle::apply($playable, $seed, 'error_hunt:items'), 0, $size);

        $taken = [];
        $elements = [];
        foreach ($chosen as $version) {
            $elements[] = $this->element($version, $locales, $seed, $taken);
        }

        return $elements;
    }

    /**
     * Before `locate`: the words, `located` and `options` null. After it: the step `fix` - the word the learner took, whether it was the
     * wrong one, which one was, and the three corrections. The keys are always there (a simpler type for the client).
     */
    public function project(SnapshotElement $element, string $locale, ElementState $state): array
    {
        $tokens = [];
        foreach ($this->tokens($element) as $token) {
            $tokens[] = ['id' => $token['id'], 'text' => $token['text']];
        }

        $located = self::located($state);
        if ($located === null) {
            return [
                'id' => $element->id,
                'kind' => $element->kind,
                'step' => PracticeException::STEP_LOCATE,
                'tokens' => $tokens,
                'located' => null,
                'options' => null,
            ];
        }

        $options = [];
        foreach ($this->options($element) as $option) {
            $options[] = ['id' => $option['id'], 'text' => $option['text']];
        }

        return [
            'id' => $element->id,
            'kind' => $element->kind,
            'step' => PracticeException::STEP_FIX,
            'tokens' => $tokens,
            'located' => ['token_id' => $located, 'ok' => ($state->extra['located_ok'] ?? false) === true, 'error_token_id' => self::stringOf($element->get('error_id'))],
            'options' => $options,
        ];
    }

    public function grade(SnapshotElement $element, array $answer, ElementState $state): GradeResult
    {
        $located = self::located($state);
        $locatedOk = $located !== null && ($state->extra['located_ok'] ?? false) === true;
        $errorId = self::stringOf($element->get('error_id'));

        // "I don't know", at either step: the element ends, the steps not taken count as not done.
        if (($answer['skip'] ?? null) === true) {
            $extra = ['located' => $located, 'located_ok' => $locatedOk, 'fixed' => null, 'fixed_ok' => false];

            return new GradeResult(
                verdict: Verdict::Skipped,
                reveal: $this->finalReview($element, $extra),
                normalizedAnswer: ['skip' => true],
                extra: $extra,
            );
        }

        if ($located === null) {
            $ok = $this->grader->locate(array_column($this->tokens($element), 'id'), $errorId, $answer);
            $tokenId = (string) $answer['token_id'];

            return new GradeResult(
                verdict: $ok ? Verdict::Correct : Verdict::Wrong,
                reveal: ['step' => PracticeException::STEP_LOCATE, 'located_ok' => $ok, 'your_token_id' => $tokenId, 'error_token_id' => $errorId],
                normalizedAnswer: ['token_id' => $tokenId],
                final: false,
                firstAttempt: true,
                extra: ['located' => $tokenId, 'located_ok' => $ok],
            );
        }

        $fixed = $this->grader->fix(array_column($this->options($element), 'id'), $this->correctIds($element), $answer);
        $optionId = (string) $answer['option_id'];
        $extra = ['located' => $located, 'located_ok' => $locatedOk, 'fixed' => $optionId, 'fixed_ok' => $fixed];
        $correct = $locatedOk && $fixed;

        return new GradeResult(
            verdict: $correct ? Verdict::Correct : Verdict::Wrong,
            reveal: $this->finalReview($element, $extra),
            normalizedAnswer: ['option_id' => $optionId],
            points: $correct ? max(0, (int) config('practice.battle.points.round', 100)) : 0,
            extra: $extra,
        );
    }

    /**
     * The review for a viewer of `$locale`, independent of any answer: the wrong word, the right correction, both sentences, the
     * translation and the explanation in that language.
     *
     * @return array{error_token_id: string, correct_option_ids: list<string>, wrong_text: string, full_text: string, translation: string|null, explanation: string|null}
     */
    public function reveal(SnapshotElement $element, string $locale, ElementState $state): array
    {
        return $this->review($element, $locale);
    }

    public function mistakeLabel(SnapshotElement $element, ElementState $state, string $locale): array
    {
        $label = self::stringOf($element->get('full_text'));
        if ($state->verdict === Verdict::Skipped || ($state->answer['skip'] ?? null) === true) {
            return [['label' => $label, 'your_answer' => null]];
        }

        // The mistake not found: it stayed in the sentence. Found, but corrected wrong: the sentence with the correction picked.
        if (($state->extra['located_ok'] ?? false) !== true) {
            return [['label' => $label, 'your_answer' => self::stringOf($element->get('wrong_text'))]];
        }

        $picked = $state->extra['fixed'] ?? null;
        $yourAnswer = null;
        foreach ($this->options($element) as $option) {
            if ($option['id'] === $picked) {
                $yourAnswer = ErrorHuntText::replace(array_column($this->tokens($element), 'text'), $this->errorIndex($element), $option['text']);
            }
        }

        return [['label' => $label, 'your_answer' => $yourAnswer]];
    }

    /**
     * The review of the final step of a solo answer (the learner's own language: the first text of the element).
     *
     * @param  array{located: string|null, located_ok: bool, fixed: string|null, fixed_ok: bool}  $extra
     * @return array<string, mixed>
     */
    private function finalReview(SnapshotElement $element, array $extra): array
    {
        $review = $this->review($element, null);

        return [
            'step' => PracticeException::STEP_FIX,
            'located_ok' => $extra['located_ok'],
            'fixed_ok' => $extra['fixed_ok'],
            'your_token_id' => $extra['located'],
            'error_token_id' => $review['error_token_id'],
            'your_option_id' => $extra['fixed'],
            'correct_option_ids' => $review['correct_option_ids'],
            'wrong_text' => $review['wrong_text'],
            'full_text' => $review['full_text'],
            'translation' => $review['translation'],
            'explanation' => $review['explanation'],
        ];
    }

    /**
     * @return array{error_token_id: string, correct_option_ids: list<string>, wrong_text: string, full_text: string, translation: string|null, explanation: string|null}
     */
    private function review(SnapshotElement $element, ?string $locale): array
    {
        return [
            'error_token_id' => self::stringOf($element->get('error_id')),
            'correct_option_ids' => $this->correctIds($element),
            'wrong_text' => self::stringOf($element->get('wrong_text')),
            'full_text' => self::stringOf($element->get('full_text')),
            'translation' => $this->textIn($element->get('translation'), $locale),
            'explanation' => $this->textIn($element->get('explanation'), $locale),
        ];
    }

    /** The word the learner took at the step `locate`, null before it. */
    private static function located(ElementState $state): ?string
    {
        $located = $state->extra['located'] ?? null;

        return is_string($located) && $located !== '' ? $located : null;
    }

    /** How many tasks can be played, for the availability: the rows of the versions, nothing hydrated; the test of {@see parse()}. */
    private function playableCount(MaterialScope $scope, ?Learner $learner): int
    {
        return $scope->remember('error_hunt:count:'.($learner->id ?? 0), function () use ($scope, $learner): int {
            $count = 0;
            foreach ($this->material->eligibleRows($scope, PracticeItemType::ErrorHunt, $learner) as $row) {
                if (self::parse($row['content']) !== null) {
                    $count++;

                    continue;
                }
                Log::warning('practice.error_hunt_content_unplayable', ['item_id' => $row['item_id'], 'version_id' => $row['id']]);
            }

            return $count;
        });
    }

    /**
     * The versions that can be played in the scope, remembered by it.
     *
     * @return list<PracticeItemVersion>
     */
    private function playable(MaterialScope $scope, ?Learner $learner): array
    {
        return $scope->remember('error_hunt:playable:'.($learner->id ?? 0), function () use ($scope, $learner): array {
            $playable = [];
            foreach ($this->material->eligibleItems($scope, PracticeItemType::ErrorHunt, $learner) as $version) {
                if (self::parse($version->content) !== null) {
                    $playable[] = $version;

                    continue;
                }
                Log::warning('practice.error_hunt_content_unplayable', ['item_id' => $version->item_id, 'version_id' => $version->id]);
            }

            return $playable;
        });
    }

    /**
     * The task of a version as the mode plays it, or null when it cannot be played: fewer than two words, a number of the wrong word that
     * is not one of them, fewer than two corrections (each a text), no right correction, one that does not exist, or all of them right.
     *
     * @param  array<string, mixed>  $content
     * @return array{tokens: list<string>, error_index: int, options: list<string>, correct: list<int>}|null
     */
    private static function parse(array $content): ?array
    {
        $text = $content['text'] ?? null;
        $index = $content['error_index'] ?? null;
        $tokens = is_string($text) ? ErrorHuntText::tokens($text) : [];
        if (count($tokens) < 2 || ! is_int($index) || ! isset($tokens[$index]) || ErrorHuntText::core($tokens[$index]) === '') {
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
        foreach (is_array($content['correct'] ?? null) ? $content['correct'] : [] as $i) {
            if (! is_int($i) || ! isset($options[$i])) {
                return null;
            }
            $correct[$i] = $i;
        }
        $correct = array_values($correct);

        if (count($options) < 2 || $correct === [] || count($correct) >= count($options)) {
            return null;
        }

        return ['tokens' => $tokens, 'error_index' => $index, 'options' => $options, 'correct' => $correct];
    }

    /**
     * @param  list<string>  $taken  the ids already used in the snapshot (grows)
     */
    private function element(PracticeItemVersion $version, LocaleSet $locales, string $seed, array &$taken): SnapshotElement
    {
        $task = self::parse($version->content) ?? throw new LogicException("The version {$version->id} is not a playable find-the-mistake.");

        $id = OpaqueId::make('e', $taken);
        $taken[] = $id;

        $tokens = [];
        foreach ($task['tokens'] as $text) {
            $tokenId = OpaqueId::make('t', $taken);
            $taken[] = $tokenId;
            $tokens[] = ['id' => $tokenId, 'text' => $text];
        }

        /** @var array<int, string> $idByIndex authored index => opaque id */
        $idByIndex = [];
        foreach (array_keys($task['options']) as $index) {
            $idByIndex[$index] = OpaqueId::make('o', $taken);
            $taken[] = $idByIndex[$index];
        }

        $options = [];
        foreach (SeededShuffle::order($seed, "error_hunt:options:{$version->id}", count($task['options'])) as $index) {
            $options[] = ['id' => $idByIndex[$index], 'text' => $task['options'][$index]];
        }

        return new SnapshotElement($id, PracticeMode::ErrorHunt->elementKind(), [
            'item_id' => $version->item_id,
            'version_id' => $version->id,
            'tokens' => $tokens,
            'error_id' => $tokens[$task['error_index']]['id'],
            'options' => $options,
            'correct_ids' => array_map(static fn (int $index): string => $idByIndex[$index], $task['correct']),
            'wrong_text' => implode(' ', $task['tokens']),
            'full_text' => ErrorHuntText::replace($task['tokens'], $task['error_index'], $task['options'][$task['correct'][0]]),
            'translation' => $this->textsFor($version->content['translation'] ?? null, $locales),
            'explanation' => $this->textsFor($version->content['explanation'] ?? null, $locales),
        ]);
    }

    /**
     * The words of the element as the snapshot keeps them, in the order of the sentence.
     *
     * @return list<array{id: string, text: string}>
     */
    private function tokens(SnapshotElement $element): array
    {
        return self::pairsOf($element->get('tokens'));
    }

    /**
     * The corrections of the element as the snapshot keeps them, in the shown order.
     *
     * @return list<array{id: string, text: string}>
     */
    private function options(SnapshotElement $element): array
    {
        return self::pairsOf($element->get('options'));
    }

    /** @return list<array{id: string, text: string}> */
    private static function pairsOf(mixed $list): array
    {
        $pairs = [];
        foreach (is_array($list) ? $list : [] as $entry) {
            if (is_array($entry) && is_string($entry['id'] ?? null) && is_string($entry['text'] ?? null)) {
                $pairs[] = ['id' => $entry['id'], 'text' => $entry['text']];
            }
        }

        return $pairs;
    }

    /** The number of the wrong word in the sentence. */
    private function errorIndex(SnapshotElement $element): int
    {
        $index = array_search($element->get('error_id'), array_column($this->tokens($element), 'id'), true);

        return is_int($index) ? $index : -1;
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
