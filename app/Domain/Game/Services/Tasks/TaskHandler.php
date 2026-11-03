<?php

declare(strict_types=1);

namespace App\Domain\Game\Services\Tasks;

use App\Domain\Game\DTO\RunState;
use App\Domain\Game\DTO\TaskGrade;
use App\Domain\Game\Enums\TaskKind;
use App\Domain\Game\Exceptions\GameException;
use App\Domain\Practice\DTO\SnapshotElement;

/**
 * One kind (or several) of task of a run (docs/18 §12.1, §12.3.2): how an element is shown, graded, reviewed, hinted. Stateless and
 * PURE as far as the snapshot goes: everything is read from the element and the state, never from the database while a run is on.
 * `WordTaskHandler` plays the five kinds made of words; `PracticeTaskAdapter` plays the four kinds of the practice through its handlers.
 */
interface TaskHandler
{
    /** @return list<TaskKind> */
    public function kinds(): array;

    /**
     * What the client sees of the element (docs/18 §12.3.2): no `correct_option_id`, `expected`, `pairs`, `correct_ids`, `accepted`,
     * `audio_path`, `word_id`, `version_id`, `item_id`, `target_word_ids` - the contract test fails on them. The hint already given on
     * the element (`$state`) is part of the projection, so a reload shows it again.
     *
     * @return array<string, mixed>
     */
    public function project(SnapshotElement $element, string $locale, RunState $state): array;

    /**
     * Validates the SHAPE of the answer and grades it.
     *
     * @param  array<string, mixed>  $answer
     *
     * @throws GameException invalid_answer_shape | unknown_option_id | pair_already_matched
     */
    public function grade(SnapshotElement $element, array $answer, RunState $state): TaskGrade;

    /**
     * The review of the element for the viewer's locale, independent of the answer: the right answer in the form of the kind.
     *
     * @return array<string, mixed>
     */
    public function reveal(SnapshotElement $element, string $locale, RunState $state): array;

    /**
     * The rows of the list of mistakes of the summary for an element the learner got wrong: `{label, your_answer}`, one per
     * mistake (a round of pairs: one per word of `wrong_left`).
     *
     * @return list<array{label: string, your_answer: string|null}>
     */
    public function mistakeLabel(SnapshotElement $element, RunState $state, string $locale): array;

    /**
     * The hint of the element (docs/18 §12.3.2): `{options_removed: [id, id]}` for a kind with options, `{prefix: "ap"}` for a text
     * answer; null when the kind has no hint (409 `hint_not_available`). Deterministic: the same element gives the same hint.
     *
     * @return array<string, mixed>|null
     */
    public function hint(SnapshotElement $element, RunState $state): ?array;

    /**
     * The words the element is about (the mistakes list, the SRS rule of docs/18 §12.4).
     *
     * @return list<int>
     */
    public function wordIds(SnapshotElement $element): array;
}
