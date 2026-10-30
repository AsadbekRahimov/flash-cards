<?php

declare(strict_types=1);

namespace Tests\Support\Practice;

use App\Domain\Practice\DTO\ElementState;
use App\Domain\Practice\DTO\GradeResult;
use App\Domain\Practice\DTO\LocaleSet;
use App\Domain\Practice\DTO\MaterialScope;
use App\Domain\Practice\DTO\ModeAvailability;
use App\Domain\Practice\DTO\SnapshotElement;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Enums\Verdict;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\Modes\PracticeModeHandler;
use App\Domain\Practice\Services\Modes\ReplaceableElements;
use App\Domain\Practice\Services\PracticeMaterialService;
use App\Domain\Practice\Support\OpaqueId;
use App\Domain\Practice\Support\PracticeTextNormalizer;
use App\Domain\Practice\Support\SeededShuffle;
use App\Models\Learner;

/**
 * The test handler of the generic life cycle of a session (task 18.1): put it in place of a skeleton with
 * `app()->instance(CollocationMode::class, new FakeEchoMode(PracticeMode::Collocation))`. It knows no word and no task: it
 * has `$candidates` imaginary items numbered 1..N (the element keeps the number as `item_id`, so a retry can narrow them
 * through `RetryMaterial`), builds elements from them and grades by a trivial rule.
 *
 * Non-pairs modes. Element: `prompt` "Question k", expected answer "answer k". Answer `{text}` (correct if it equals the
 * expected text after normalization; the text "almost" is a TYPO; anything else is wrong) or `{skip: true}`; a missing `text`
 * is 422 `invalid_answer_shape`. A dictation-like fake (`$audio`) gives its elements an audio file on the disk `practice_audio`.
 *
 * Pairs mode (`PracticeMode::Pairs`): a round of 3 pairs with the rules of docs/16 §5.5, to exercise `final = false`.
 *
 * The secrets of every element are in the snapshot under the keys that must NEVER be shown: `expected`, `accepted`,
 * `accepted_display`, `correct_ids`, `pairs`, `word_id`, `version_id`, `item_id`, `audio_path`, `audio_disk`, `full_text`.
 */
final class FakeEchoMode implements PracticeModeHandler, ReplaceableElements
{
    /** Where the audio of an element lives when the fake plays a dictation. */
    public const AUDIO_DISK = 'practice_audio';

    /** @var list<int> the ids of the items the last `build()` used (for the tests to look at) */
    public array $built = [];

    /** The projection also shows the secrets of the element (a buggy handler): the resource must not let them out. */
    public bool $leak = false;

    /** `availability()` reads the words / the tasks of the scope through PracticeMaterialService, the way a real handler does. */
    public bool $readsMaterial = false;

    /** Bytes of dead weight in every element of the snapshot (the size limit of docs/16 §13). */
    public int $padding = 0;

    public function __construct(
        private readonly PracticeMode $mode = PracticeMode::Collocation,
        public int $candidates = 8,
        public int $spares = 0,
        public bool $audio = false,
    ) {}

    public function mode(): PracticeMode
    {
        return $this->mode;
    }

    public function availability(MaterialScope $scope, ?Learner $learner, LocaleSet $locales): ModeAvailability
    {
        if ($this->readsMaterial) {
            $this->readMaterial($scope, $learner, $locales);
        }

        $required = $this->mode === PracticeMode::Pairs
            ? max(1, (int) config('practice.solo.pairs.min_per_round'))
            : app(PracticeMaterialService::class)->minItems($scope);
        $available = count($this->items($scope));

        return $available >= $required
            ? ModeAvailability::enough($available, $required)
            : ModeAvailability::notEnough($available, $required, ModeAvailability::REASON_NO_ITEMS);
    }

    public function build(MaterialScope $scope, ?Learner $learner, LocaleSet $locales, string $seed, int $size, bool $strictSize): array
    {
        $items = SeededShuffle::apply($this->items($scope), $seed, 'fake');

        if ($this->mode === PracticeMode::Pairs) {
            return $this->rounds($items, $size);
        }

        $chosen = array_slice($items, 0, $size);
        $this->built = $chosen;
        $taken = [];
        $elements = [];
        foreach ($chosen as $k) {
            $elements[] = $this->element($k, $taken);
        }

        return $elements;
    }

    public function project(SnapshotElement $element, string $locale, ElementState $state): array
    {
        $projection = $this->projection($element, $state);

        return $this->leak
            ? [...$projection, 'expected' => $element->get('expected'), 'word_id' => $element->get('word_id'), 'pairs' => $element->get('pairs'), 'nested' => ['audio_path' => $element->get('audio_path'), 'item_id' => $element->get('item_id')]]
            : $projection;
    }

    /** @return array<string, mixed> */
    private function projection(SnapshotElement $element, ElementState $state): array
    {
        if ($element->isPairsRound()) {
            return [
                'id' => $element->id,
                'kind' => $element->kind,
                'left' => array_map(static fn (array $l): array => ['id' => $l['id'], 'text' => $l['text']], (array) $element->get('left')),
                'right' => array_map(static fn (array $r): array => ['id' => $r['id'], 'text' => $r['text']], (array) $element->get('right')),
                'matched' => array_map(null, array_keys((array) ($state->extra['matched'] ?? [])), array_values((array) ($state->extra['matched'] ?? []))),
                'mismatches' => (int) ($state->extra['mismatches'] ?? 0),
            ];
        }

        // An element with audio is a dictation (docs/16 §7.2): no text, only where to listen and how many words to write.
        if ($element->get('audio_path') !== null) {
            return [
                'id' => $element->id,
                'kind' => $element->kind,
                'source' => $element->get('source'),
                'audio_url' => $state->audioUrl,
                'words' => str_word_count((string) $element->get('expected')),
                'replacements_left' => $state->replacementsLeft,
            ];
        }

        return ['id' => $element->id, 'kind' => $element->kind, 'prompt' => $element->get('prompt')];
    }

    public function grade(SnapshotElement $element, array $answer, ElementState $state): GradeResult
    {
        if ($element->isPairsRound()) {
            return $this->gradePair($element, $answer, $state);
        }

        if (($answer['skip'] ?? false) === true) {
            return new GradeResult(Verdict::Skipped, $this->review($element, null), ['skip' => true]);
        }
        if (! isset($answer['text']) || ! is_string($answer['text'])) {
            throw PracticeException::invalidAnswerShape(PracticeException::EXPECTED_TEXT_OR_SKIP);
        }

        $text = $answer['text'];
        $accepted = array_map(PracticeTextNormalizer::normalize(...), (array) $element->get('accepted'));
        $verdict = match (true) {
            in_array(PracticeTextNormalizer::normalize($text), $accepted, true) => Verdict::Correct,
            PracticeTextNormalizer::normalize($text) === 'almost' => Verdict::Typo,
            default => Verdict::Wrong,
        };

        return new GradeResult($verdict, $this->review($element, $text), ['text' => $text]);
    }

    /** The review of a battle round: the secret as the right answer (pairs: the table), a fixed translation, no explanation. */
    public function reveal(SnapshotElement $element, string $locale, ElementState $state): array
    {
        if ($element->isPairsRound()) {
            return ['pairs' => (array) $element->get('pairs'), 'translation' => null, 'explanation' => null];
        }

        return ['expected' => (string) $element->get('expected'), 'translation' => 'tr', 'explanation' => null];
    }

    public function mistakeLabel(SnapshotElement $element, ElementState $state, string $locale): array
    {
        if ($element->isPairsRound()) {
            $texts = [];
            foreach ((array) $element->get('left') as $left) {
                $texts[$left['id']] = $left['text'].' — '.$this->rightTextOf($element, (string) $left['id']);
            }

            return array_map(
                static fn (string $leftId): array => ['label' => $texts[$leftId], 'your_answer' => null],
                array_values(array_filter((array) ($state->extra['wrong_left'] ?? []), static fn (mixed $id): bool => isset($texts[$id]))),
            );
        }

        $text = $state->answer['text'] ?? null;

        return [['label' => (string) $element->get('prompt'), 'your_answer' => is_string($text) ? $text : null]];
    }

    /** The spares are the imaginary items that were not built: `{item_id, source: phrase}`. */
    public function spares(MaterialScope $scope, ?Learner $learner, LocaleSet $locales, string $seed, array $chosen): array
    {
        $used = array_map(static fn (SnapshotElement $e): int => (int) $e->get('item_id'), $chosen);
        $rest = array_values(array_filter(
            SeededShuffle::apply($this->items($scope), $seed, 'fake'),
            static fn (int $k): bool => ! in_array($k, $used, true),
        ));

        return array_map(static fn (int $k): array => ['item_id' => $k, 'source' => 'phrase'], array_slice($rest, 0, $this->spares));
    }

    public function replacement(SnapshotElement $replaced, array $pool, LocaleSet $locales): ?array
    {
        if ($pool === []) {
            return null;
        }

        $candidate = array_shift($pool);
        $taken = [];

        return [$this->element((int) $candidate['item_id'], $taken), array_values($pool)];
    }

    /** What a real handler asks the material service for: the words (pairs, dictation) and the tasks of its type. */
    private function readMaterial(MaterialScope $scope, ?Learner $learner, LocaleSet $locales): void
    {
        $material = app(PracticeMaterialService::class);

        if ($learner !== null && ($this->mode === PracticeMode::Pairs || $this->mode === PracticeMode::Dictation)) {
            $material->introducedWords($scope, $learner, $locales);
        }
        if ($this->mode->itemType() !== null) {
            $material->eligibleItems($scope, $this->mode->itemType(), $learner);
        }
    }

    /** @return list<int> */
    private function items(MaterialScope $scope): array
    {
        $all = $this->candidates < 1 ? [] : range(1, $this->candidates);
        if ($scope->retry === null) {
            return $all;
        }

        // The retry names words for pairs (their `word_id` is 3000 + the number) and tasks for the other modes.
        return $this->mode === PracticeMode::Pairs
            ? array_values(array_filter($all, static fn (int $k): bool => in_array(3000 + $k, $scope->retry->wordIds ?? [], true)))
            : array_values(array_intersect($all, $scope->retry->itemIds));
    }

    /**
     * @param  list<string>  $taken  the ids already used in the snapshot
     */
    private function element(int $k, array &$taken): SnapshotElement
    {
        $audio = $this->audio || $this->mode === PracticeMode::Dictation;
        $id = OpaqueId::make('e', $taken);
        $taken[] = $id;

        return new SnapshotElement($id, $this->mode->elementKind(), array_filter([
            'item_id' => $k,
            'version_id' => 1000 + $k,
            'word_id' => 2000 + $k,
            'prompt' => "Question {$k}",
            'expected' => "answer {$k}",
            'accepted' => ["answer {$k}"],
            'accepted_display' => ["Answer {$k}."],
            'correct_ids' => ['oSECRET'.$k],
            'full_text' => "Full text {$k}",
            'source' => 'phrase',
            'audio_disk' => $audio ? self::AUDIO_DISK : null,
            'audio_path' => $audio ? "clip-{$k}.mp3" : null,
            'padding' => $this->padding > 0 ? str_repeat('x', $this->padding) : null,
        ], static fn (mixed $v): bool => $v !== null));
    }

    /**
     * Rounds of three pairs: the words are numbered from the items, three to a round.
     *
     * @param  list<int>  $items
     * @return list<SnapshotElement>
     */
    private function rounds(array $items, int $size): array
    {
        $rounds = [];
        $taken = [];
        foreach (array_slice(array_chunk($items, 3), 0, $size) as $chunk) {
            if (count($chunk) < 3) {
                break;
            }
            $left = [];
            $right = [];
            $pairs = [];
            foreach ($chunk as $k) {
                $l = OpaqueId::make('l', $taken);
                $r = OpaqueId::make('r', $taken);
                $taken[] = $l;
                $taken[] = $r;
                $left[] = ['id' => $l, 'word_id' => 3000 + $k, 'text' => "word {$k}"];
                $right[] = ['id' => $r, 'text' => ['ru' => "слово {$k}"]];
                $pairs[$l] = $r;
            }
            $this->built = [...$this->built, ...$chunk];
            $rounds[] = new SnapshotElement(OpaqueId::make('e', $taken), 'pairs_round', ['left' => $left, 'right' => $right, 'pairs' => $pairs]);
        }

        return $rounds;
    }

    private function gradePair(SnapshotElement $element, array $answer, ElementState $state): GradeResult
    {
        $leftId = $answer['left_id'] ?? null;
        $rightId = $answer['right_id'] ?? null;
        if (! is_string($leftId) || ! is_string($rightId)) {
            throw PracticeException::invalidAnswerShape(PracticeException::EXPECTED_LEFT_RIGHT);
        }

        $lefts = array_column((array) $element->get('left'), 'id');
        $rights = array_column((array) $element->get('right'), 'id');
        if (! in_array($leftId, $lefts, true) || ! in_array($rightId, $rights, true)) {
            throw PracticeException::unknownElementId();
        }

        $matched = (array) ($state->extra['matched'] ?? []);
        $wrongLeft = (array) ($state->extra['wrong_left'] ?? []);
        $mismatches = (int) ($state->extra['mismatches'] ?? 0);
        if (isset($matched[$leftId])) {
            throw PracticeException::pairAlreadyMatched($leftId);
        }

        $first = ! in_array($leftId, $wrongLeft, true);
        $correct = ((array) $element->get('pairs'))[$leftId] === $rightId;
        if ($correct) {
            $matched[$leftId] = $rightId;
        } else {
            $mismatches++;
            if ($first) {
                $wrongLeft[] = $leftId;
            }
        }

        return new GradeResult(
            verdict: $correct ? Verdict::Correct : Verdict::Wrong,
            reveal: null,
            normalizedAnswer: ['left_id' => $leftId, 'right_id' => $rightId],
            final: count($matched) === count($lefts),
            firstAttempt: $first,
            extra: ['matched' => $matched, 'mismatches' => $mismatches, 'wrong_left' => $wrongLeft],
        );
    }

    /** @return array<string, mixed> */
    private function review(SnapshotElement $element, ?string $yourText): array
    {
        return ['expected' => (string) $element->get('expected'), 'your_text' => $yourText, 'translation' => 'tr', 'explanation' => null];
    }

    private function rightTextOf(SnapshotElement $element, string $leftId): string
    {
        $rightId = ((array) $element->get('pairs'))[$leftId] ?? null;
        foreach ((array) $element->get('right') as $right) {
            if ($right['id'] === $rightId) {
                return (string) ($right['text']['ru'] ?? '');
            }
        }

        return '';
    }
}
