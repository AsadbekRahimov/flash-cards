<?php

declare(strict_types=1);

namespace App\Domain\Practice\Services\Modes;

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
use App\Domain\Practice\Services\Grading\SentenceGrader;
use App\Domain\Practice\Services\PracticeMaterialService;
use App\Domain\Practice\Support\OpaqueId;
use App\Domain\Practice\Support\PracticeTextNormalizer;
use App\Domain\Practice\Support\SeededShuffle;
use App\Models\Learner;
use App\Models\PracticeItemVersion;

/**
 * "Build the sentence" (docs/15 §3.2, docs/16 §5.3): the learner sees the meaning of a sentence in their language and the English
 * fragments in a shuffled order, and puts them in a line. The tasks are authored (`PracticeItemType::Sentence`): fragments, the
 * allowed orders as permutations of them, an optional `display`, the prompt and the explanation in ru and uz.
 *
 * - Material: the published tasks whose target words the learner has been introduced to (a battle: all the active ones),
 *   read through {@see PracticeMaterialService}, so the hub costs no query more than the tasks themselves. A version whose
 *   `content` is not what the authoring writes (a broken fragment list, no valid order, no prompt) is not material: it is left out
 *   of the count and of the choice alike - the hub never promises what `build()` cannot deliver.
 * - Snapshot element (docs/16 §2.8): `item_id`, `version_id`, the `prompt` and the `explanation` for each locale of the LocaleSet,
 *   the `fragments` as `{id, text}` in the order they are SHOWN, the normalized `accepted` texts and their `accepted_display` forms.
 *   The shown order is a keyed shuffle of the seed, redrawn (up to {@see self::SHUFFLE_ATTEMPTS} times) while it happens to read as
 *   an allowed sentence - an exercise must not hand out its answer.
 * - The client sees the prompt in its language and the fragments; never `accepted`, `accepted_display`, `item_id`, `version_id`.
 *   After the answer: the right sentence, the other allowed ones, what was put together, the explanation (docs/16 §5.3).
 *
 * A battle round is worth 100 points for a correct final answer and 0 for anything else (docs/15 §3.5).
 *
 * @phpstan-type SentenceTask array{item_id: int, version_id: int, fragments: list<string>, sequences: list<list<int>>, prompt: array<string, string>, explanation: array<string, string>, display: string|null}
 */
final class SentenceMode implements PracticeModeHandler
{
    /** How often the shown order is redrawn while it spells an allowed sentence (a task of two words may allow both orders). */
    private const SHUFFLE_ATTEMPTS = 16;

    public function __construct(
        private readonly PracticeMaterialService $material,
        private readonly SentenceGrader $grader,
    ) {}

    public function mode(): PracticeMode
    {
        return PracticeMode::Sentence;
    }

    public function availability(MaterialScope $scope, ?Learner $learner, LocaleSet $locales): ModeAvailability
    {
        $required = $this->required($scope, $learner);
        $available = count($this->tasks($scope, $learner));

        return $available >= $required
            ? ModeAvailability::enough($available, $required)
            : ModeAvailability::notEnough($available, $required, ModeAvailability::REASON_NO_ITEMS);
    }

    public function build(MaterialScope $scope, ?Learner $learner, LocaleSet $locales, string $seed, int $size, bool $strictSize): array
    {
        $tasks = $this->tasks($scope, $learner);
        $count = $strictSize ? $size : min($size, count($tasks));
        $required = $strictSize ? $size : min($size, $this->required($scope, $learner));

        if (count($tasks) < $required || $count < 1) {
            throw PracticeException::notEnoughMaterial(
                PracticeMode::Sentence,
                ModeAvailability::notEnough(count($tasks), max(1, $required), ModeAvailability::REASON_NO_ITEMS),
                $scope->course->slug,
                $scope->lesson?->id,
            );
        }

        $chosen = array_slice(SeededShuffle::apply($tasks, $seed, 'sentence:pick'), 0, $count);

        $taken = [];
        $elements = [];
        foreach ($chosen as $position => $task) {
            $id = OpaqueId::make('e', $taken);
            $taken[] = $id;

            $forms = $this->grader->forms($task['fragments'], $task['sequences'], $task['display']);
            $explanation = $this->forLocales($task['explanation'], $locales);

            $elements[] = new SnapshotElement($id, PracticeMode::Sentence->elementKind(), [
                'item_id' => $task['item_id'],
                'version_id' => $task['version_id'],
                'prompt' => $this->forLocales($task['prompt'], $locales),
                'fragments' => $this->shown($task['fragments'], $forms['accepted'], $seed, $position, $taken),
                'accepted' => $forms['accepted'],
                'accepted_display' => $forms['accepted_display'],
                'explanation' => $explanation === [] ? null : $explanation,
            ]);
        }

        return $elements;
    }

    public function project(SnapshotElement $element, string $locale, ElementState $state): array
    {
        $fragments = [];
        foreach ($this->fragmentList($element) as $fragment) {
            $fragments[] = ['id' => $fragment['id'], 'text' => $fragment['text']];
        }

        return [
            'id' => $element->id,
            'kind' => $element->kind,
            'prompt' => $this->localized($element->get('prompt'), $locale) ?? '',
            'fragments' => $fragments,
        ];
    }

    public function grade(SnapshotElement $element, array $answer, ElementState $state): GradeResult
    {
        $fragments = $this->fragmentTexts($element);
        $graded = $this->grader->grade($fragments, $this->strings($element->get('accepted')), $answer);

        $shown = $this->strings($element->get('accepted_display'));
        $verdict = $graded->verdict;

        return new GradeResult(
            verdict: $verdict,
            reveal: [
                'correct_text' => $shown[0] ?? '',
                'alternatives' => array_slice($shown, 1),
                'your_text' => $graded->text === null ? null : $this->grader->capitalized($graded->text),
                // One language per answer: a solo snapshot is built for the locale of the learner, so its only entry is theirs.
                'explanation' => $this->localized($element->get('explanation'), null),
            ],
            normalizedAnswer: $verdict === Verdict::Skipped ? ['skip' => true] : ['order' => $graded->order],
            final: true,
            points: $verdict === Verdict::Correct ? $this->points() : 0,
            firstAttempt: true,
        );
    }

    public function mistakeLabel(SnapshotElement $element, ElementState $state, string $locale): array
    {
        $shown = $this->strings($element->get('accepted_display'));

        $yourAnswer = null;
        $order = $state->answer['order'] ?? null;
        if (is_array($order)) {
            $ids = array_values(array_filter($order, 'is_string'));
            $text = $this->grader->join($this->fragmentTexts($element), $ids);
            $yourAnswer = $text === '' ? null : $this->grader->capitalized($text);
        }

        return [['label' => $shown[0] ?? '', 'your_answer' => $yourAnswer]];
    }

    /** How many tasks make a session: the minimum of a solo session (`practice.solo.items.min`), the rounds of a battle. */
    private function required(MaterialScope $scope, ?Learner $learner): int
    {
        return $learner === null
            ? max(1, (int) config('practice.battle.rounds'))
            : $this->material->minItems($scope);
    }

    private function points(): int
    {
        return max(0, min(100, (int) config('practice.battle.points.round')));
    }

    /**
     * The tasks the scope offers that can be played, read once per scope (the hub asks twice: the card and the opening).
     *
     * @return list<SentenceTask>
     */
    private function tasks(MaterialScope $scope, ?Learner $learner): array
    {
        return $scope->remember('sentence:tasks:'.($learner->id ?? 0), function () use ($scope, $learner) {
            $tasks = [];
            foreach ($this->material->eligibleItems($scope, PracticeItemType::Sentence, $learner) as $version) {
                $task = $this->task($version);
                if ($task !== null) {
                    $tasks[] = $task;
                }
            }

            return $tasks;
        });
    }

    /**
     * The `content` of a version as a task; null when it cannot be played (the validation of docs/16 §4.5 keeps this from
     * happening - this only keeps a stray row from breaking a session). Fragments are all or nothing: the allowed orders point
     * into the list by index, so a dropped fragment would move the rest.
     *
     * @return SentenceTask|null
     */
    private function task(PracticeItemVersion $version): ?array
    {
        $content = $version->content;

        $fragments = $this->strings($content['fragments'] ?? null);
        if ($fragments === [] || count($fragments) !== count((array) ($content['fragments'] ?? []))) {
            return null;
        }

        $sequences = $this->sequences($content['accepted'] ?? null, count($fragments));
        $prompt = $this->texts($content['prompt'] ?? null);
        if ($sequences === [] || $prompt === []) {
            return null;
        }

        $display = $content['display'] ?? null;

        return [
            'item_id' => $version->item_id,
            'version_id' => $version->id,
            'fragments' => $fragments,
            'sequences' => $sequences,
            'prompt' => $prompt,
            'explanation' => $this->texts($content['explanation'] ?? null),
            'display' => is_string($display) && trim($display) !== '' ? trim($display) : null,
        ];
    }

    /**
     * The allowed orders that really are orders: lists with every index `0..$n-1` exactly once.
     *
     * @return list<list<int>>
     */
    private function sequences(mixed $value, int $n): array
    {
        $sequences = [];
        $candidates = is_array($value) ? $value : [];
        foreach ($candidates as $sequence) {
            if (! is_array($sequence) || ! array_is_list($sequence) || count($sequence) !== $n) {
                continue;
            }

            $indices = array_values(array_filter($sequence, 'is_int'));
            $sorted = $indices;
            sort($sorted);
            if (count($indices) === $n && $sorted === range(0, $n - 1)) {
                $sequences[] = $indices;
            }
        }

        return $sequences;
    }

    /**
     * The fragments in the order they are shown: the keyed shuffle of the seed for the position of the task. Redrawn while the
     * result reads as one of the allowed sentences (then the answer would be on the screen). Ids are drawn after the order is
     * settled; `$taken` is every id used in the snapshot so far (no two share one).
     *
     * @param  list<string>  $texts  the fragments, in the author's order
     * @param  list<string>  $accepted  the normalized texts of the allowed orders
     * @param  list<string>  $taken
     * @return list<array{id: string, text: string}>
     */
    private function shown(array $texts, array $accepted, string $seed, int $position, array &$taken): array
    {
        $order = [];
        for ($attempt = 0; $attempt < self::SHUFFLE_ATTEMPTS; $attempt++) {
            $order = SeededShuffle::order($seed, "sentence:{$position}:fragments:{$attempt}", count($texts));
            $text = PracticeTextNormalizer::normalize(implode(' ', array_map(static fn (int $i): string => $texts[$i], $order)));
            if (! in_array($text, $accepted, true)) {
                break;
            }
        }

        $fragments = [];
        foreach ($order as $index) {
            $id = OpaqueId::make('f', $taken);
            $taken[] = $id;
            $fragments[] = ['id' => $id, 'text' => $texts[$index]];
        }

        return $fragments;
    }

    /**
     * `{locale: text}` for the locales of the set: the text in the locale, else the Russian one, else any (the languages of
     * a published task are all there; the fallback is for a task that lacks one).
     *
     * @param  array<string, string>  $texts
     * @return array<string, string>
     */
    private function forLocales(array $texts, LocaleSet $locales): array
    {
        $byLocale = [];
        foreach ($locales->locales as $locale) {
            $text = $texts[$locale] ?? $texts['ru'] ?? (array_values($texts)[0] ?? null);
            if ($text !== null) {
                $byLocale[$locale] = $text;
            }
        }

        return $byLocale;
    }

    /**
     * A text out of a `{locale: text}` snapshot value. For a locale: its text, else the Russian one, else any. Without a locale:
     * the first entry - in a solo snapshot the only one, the language of the learner.
     */
    private function localized(mixed $texts, ?string $locale): ?string
    {
        $map = $this->texts($texts);
        if ($map === []) {
            return null;
        }

        return $locale === null ? array_values($map)[0] : ($map[$locale] ?? $map['ru'] ?? array_values($map)[0]);
    }

    /**
     * The non-empty string values of a `{locale: text}` object.
     *
     * @return array<string, string>
     */
    private function texts(mixed $value): array
    {
        $texts = [];
        $entries = is_array($value) ? $value : [];
        foreach ($entries as $locale => $text) {
            if (is_string($locale) && is_string($text) && trim($text) !== '') {
                $texts[$locale] = trim($text);
            }
        }

        return $texts;
    }

    /**
     * The non-empty strings of a list (anything else in it is skipped).
     *
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        $strings = [];
        $items = is_array($value) ? $value : [];
        foreach ($items as $item) {
            if (is_string($item) && trim($item) !== '') {
                $strings[] = trim($item);
            }
        }

        return $strings;
    }

    /** @return list<array{id: string, text: string}> the fragments of the element as the snapshot keeps them */
    private function fragmentList(SnapshotElement $element): array
    {
        $fragments = [];
        foreach ((array) $element->get('fragments', []) as $fragment) {
            if (is_array($fragment) && is_string($fragment['id'] ?? null) && is_string($fragment['text'] ?? null)) {
                $fragments[] = ['id' => $fragment['id'], 'text' => $fragment['text']];
            }
        }

        return $fragments;
    }

    /** @return array<string, string> fragment id => text */
    private function fragmentTexts(SnapshotElement $element): array
    {
        $texts = [];
        foreach ($this->fragmentList($element) as $fragment) {
            $texts[$fragment['id']] = $fragment['text'];
        }

        return $texts;
    }
}
