<?php

declare(strict_types=1);

namespace App\Domain\Game\Services\Tasks;

use App\Domain\Game\DTO\AuthoredCandidate;
use App\Domain\Game\DTO\RunState;
use App\Domain\Game\DTO\TaskGrade;
use App\Domain\Game\Enums\TaskKind;
use App\Domain\Game\Exceptions\GameException;
use App\Domain\Practice\DTO\LocaleSet;
use App\Domain\Practice\DTO\MaterialScope;
use App\Domain\Practice\DTO\RetryMaterial;
use App\Domain\Practice\DTO\SnapshotElement;
use App\Domain\Practice\Enums\PracticeItemType;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\Modes\ModeRegistry;
use App\Domain\Practice\Services\Modes\PracticeModeHandler;
use App\Models\Course;
use App\Models\Learner;
use App\Models\PracticeItemVersion;
use InvalidArgumentException;

/**
 * The four kinds of the practice in a run (docs/18 §12.1, §12.3.2): `pairs_round`, `collocation`, `sentence`, `dictation` are played
 * through the handlers of the practice AS THEY ARE (`ModeRegistry`): the element of the snapshot has the same shape, the projection,
 * the grading, the review and the labels are theirs. What is the game's: the state of the element as a run keeps it
 * (`RunState::elementState()` reads it as the practice would), the mapping of a refusal to the codes of the game, and the words a
 * mistake is about (a round of pairs: the left word of a wrong match; an authored task: every target word of its version).
 *
 * Building: the authored tasks are built BEFORE the transaction by the handler's own `build()` on a scope narrowed to the chosen
 * tasks (`RetryMaterial`: the way "repeat the mistakes" narrows it), so the handler reads, voices and lays out exactly those; a
 * dictation whose clip is not on the disk is dropped there (the handler refuses it, as it refuses a retry without a clip).
 */
final class PracticeTaskAdapter implements TaskHandler
{
    public function __construct(private readonly ModeRegistry $modes) {}

    /** @return list<TaskKind> */
    public function kinds(): array
    {
        return [TaskKind::PairsRound, TaskKind::Collocation, TaskKind::Sentence, TaskKind::Dictation];
    }

    /**
     * The elements of the chosen authored tasks, one per version, with their target words (`target_word_ids` in the data: the SRS
     * rule). A type whose handler refuses (a dictation without its clip) gives no candidates; the others are kept.
     *
     * @param  array<string, list<PracticeItemVersion>>  $byType  type => published versions eligible for the learner (`PracticeMaterialService::eligibleItems`)
     * @return list<AuthoredCandidate>
     */
    public function buildAuthored(Course $course, Learner $learner, LocaleSet $locales, string $seed, array $byType): array
    {
        $candidates = [];
        foreach ($byType as $typeValue => $chosen) {
            if ($chosen === []) {
                continue;
            }
            $type = PracticeItemType::from($typeValue);
            $scope = new MaterialScope($course, null, new RetryMaterial([], array_map(static fn (PracticeItemVersion $v): int => $v->item_id, $chosen)));
            try {
                $elements = $this->modes->for(self::modeOf($type))->build($scope, $learner, $locales, $seed, count($chosen), false);
            } catch (PracticeException) {
                continue; // nothing of this type can be played now (a clip is missing): the run goes on without it
            }

            $versionsById = [];
            foreach ($chosen as $version) {
                $versionsById[$version->id] = $version;
            }
            foreach ($elements as $element) {
                $version = $versionsById[(int) $element->get('version_id')] ?? null;
                if ($version === null) {
                    continue;
                }
                $targets = array_map('intval', $version->target_word_ids);
                $candidates[] = new AuthoredCandidate(
                    new SnapshotElement($element->id, $element->kind, [...$element->data, 'target_word_ids' => $targets]),
                    $type,
                    $version->item_id,
                    $targets,
                );
            }
        }

        return $candidates;
    }

    public function project(SnapshotElement $element, string $locale, RunState $state): array
    {
        $projection = $this->handler($element)->project($element, $locale, $state->elementState($element->id));
        $hint = $state->hintOf($element->id);
        if ($hint !== null) {
            $projection['hint'] = $hint;
        }

        return $projection;
    }

    public function grade(SnapshotElement $element, array $answer, RunState $state): TaskGrade
    {
        try {
            $result = $this->handler($element)->grade($element, $answer, $state->elementState($element->id));
        } catch (PracticeException $e) {
            throw match ($e->errorCode) {
                PracticeException::PAIR_ALREADY_MATCHED => GameException::pairAlreadyMatched(),
                PracticeException::UNKNOWN_ELEMENT_ID => GameException::unknownElementId(),
                default => GameException::invalidAnswerShape(),
            };
        }

        $errors = [];
        if ($result->verdict->isMistake()) {
            $errors = $element->isPairsRound() ? $this->leftWordIds($element, (string) ($result->normalizedAnswer['left_id'] ?? '')) : $this->wordIds($element);
        }

        return new TaskGrade($result->verdict, $result->reveal, $result->normalizedAnswer, $result->final, $result->firstAttempt, $result->extra, $errors);
    }

    public function reveal(SnapshotElement $element, string $locale, RunState $state): array
    {
        return $this->handler($element)->reveal($element, $locale, $state->elementState($element->id));
    }

    public function mistakeLabel(SnapshotElement $element, RunState $state, string $locale): array
    {
        return $this->handler($element)->mistakeLabel($element, $state->elementState($element->id), $locale);
    }

    /** Only a collocation has options to remove; the other kinds of the practice have no hint (docs/18 §12.3.2). */
    public function hint(SnapshotElement $element, RunState $state): ?array
    {
        if ($element->kind !== TaskKind::Collocation->value) {
            return null;
        }

        $correct = [];
        foreach (is_array($element->get('correct_ids')) ? $element->get('correct_ids') : [] as $id) {
            if (is_string($id)) {
                $correct[] = $id;
            }
        }
        $removed = [];
        foreach (is_array($element->get('options')) ? $element->get('options') : [] as $option) {
            $id = is_array($option) ? ($option['id'] ?? null) : null;
            if (is_string($id) && ! in_array($id, $correct, true) && count($removed) < 2) {
                $removed[] = $id;
            }
        }

        return ['options_removed' => $removed];
    }

    /** A round of pairs: the words of its left column; an authored task: its target words. */
    public function wordIds(SnapshotElement $element): array
    {
        if ($element->isPairsRound()) {
            $ids = [];
            foreach (is_array($element->get('left')) ? $element->get('left') : [] as $card) {
                if (is_array($card) && isset($card['word_id'])) {
                    $ids[] = (int) $card['word_id'];
                }
            }

            return $ids;
        }

        $ids = [];
        foreach (is_array($element->get('target_word_ids')) ? $element->get('target_word_ids') : [] as $id) {
            $ids[] = (int) $id;
        }

        return $ids;
    }

    /** The round of pairs with a `wrong_left` that is not empty is a mistake even when its last match was right. */
    public function isPairsMistake(SnapshotElement $element, RunState $state): bool
    {
        return $element->isPairsRound() && ($state->pairs[$element->id]['wrong_left'] ?? []) !== [];
    }

    private function handler(SnapshotElement $element): PracticeModeHandler
    {
        $kind = TaskKind::tryFrom($element->kind);
        if ($kind === null || $kind->isWordTask()) {
            throw new InvalidArgumentException("Not a task of the practice: {$element->kind}.");
        }

        return $this->modes->for(match ($kind) {
            TaskKind::PairsRound => PracticeMode::Pairs,
            TaskKind::Collocation => PracticeMode::Collocation,
            TaskKind::Sentence => PracticeMode::Sentence,
            default => PracticeMode::Dictation,
        });
    }

    /** @return list<int> */
    private function leftWordIds(SnapshotElement $element, string $leftId): array
    {
        foreach (is_array($element->get('left')) ? $element->get('left') : [] as $card) {
            if (is_array($card) && ($card['id'] ?? null) === $leftId && isset($card['word_id'])) {
                return [(int) $card['word_id']];
            }
        }

        return [];
    }

    private static function modeOf(PracticeItemType $type): PracticeMode
    {
        return match ($type) {
            PracticeItemType::Collocation => PracticeMode::Collocation,
            PracticeItemType::Sentence => PracticeMode::Sentence,
            PracticeItemType::Dictation => PracticeMode::Dictation,
        };
    }
}
