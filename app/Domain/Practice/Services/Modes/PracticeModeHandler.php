<?php

declare(strict_types=1);

namespace App\Domain\Practice\Services\Modes;

use App\Domain\Practice\DTO\ElementState;
use App\Domain\Practice\DTO\GradeResult;
use App\Domain\Practice\DTO\LocaleSet;
use App\Domain\Practice\DTO\MaterialScope;
use App\Domain\Practice\DTO\ModeAvailability;
use App\Domain\Practice\DTO\SnapshotElement;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Models\Learner;

/**
 * One mode of the practice (docs/16 §1.2). The common conveyor of every mode: material -> snapshot -> projection for the
 * client -> grading -> the review. A battle runs the same four steps with other sizes and the locales of both players.
 *
 * A handler is stateless and PURE as far as the snapshot goes: `grade()` and `project()` read only what they are given -
 * never `practice_items` / `words` while a game is on (docs/16 §0). The material comes through `PracticeMaterialService`
 * (the scope remembers what it read, so the four modes of a hub cost the queries of one).
 *
 * The id of an element (`e…`) and of everything in it that a client may name (fragments, options, sides of pairs) are
 * opaque (`OpaqueId`); `PracticeSnapshotBuilder` checks that the elements of one snapshot have distinct ids.
 */
interface PracticeModeHandler
{
    public function mode(): PracticeMode;

    /**
     * How much suitable material there is (does not build a snapshot). `$learner = null`: a battle - no learner's
     * progress is taken into account. The hub and the opening of a session call it first, so a mode that cannot be
     * played answers 422 `practice_not_enough_material` and `build()` is reached only for a mode that can.
     */
    public function availability(MaterialScope $scope, ?Learner $learner, LocaleSet $locales): ModeAvailability;

    /**
     * Builds the elements of the snapshot, deterministically from `$seed` (`SeededShuffle`). `$size`: the target number of
     * elements (solo: from the config - pairs count rounds; a battle: exactly the rounds). `$strictSize`: solo `false`
     * (`min <= n <= size` is fine; the minimum is `PracticeMaterialService::minItems`), battle `true` (exactly `$size`).
     *
     * Every element has `kind = PracticeMode::elementKind()`. A task-based element keeps `item_id` and `version_id`, a
     * word-based one `word_id` (the retry of the session reads them from the mistakes, docs/16 §6.1); a pairs round keeps
     * `word_id` in the entries of `left`. An element with audio keeps `audio_disk` and `audio_path` (the audio endpoint
     * streams them); the phrase / word `source` tag is read by the swap of a dictation.
     *
     * @return list<SnapshotElement>
     *
     * @throws PracticeException practice_not_enough_material (with the numbers of ModeAvailability)
     */
    public function build(MaterialScope $scope, ?Learner $learner, LocaleSet $locales, string $seed, int $size, bool $strictSize): array;

    /**
     * What the client sees of the element, for the viewer's `$locale` (in a battle the players have different ones). No
     * expected answer, no `pairs`, `correct_ids`, `accepted`, `expected`, `audio_path`, `word_id`, `version_id`
     * (docs/16 §2.8): the contract test fails on them. The array has `id` and `kind`. `$state->audioUrl` and
     * `$state->replacementsLeft` are what the service knows and the handler does not: put them into the projection if the
     * mode shows them (dictation: `audio_url`, `replacements_left`).
     *
     * @return array<string, mixed>
     */
    public function project(SnapshotElement $element, string $locale, ElementState $state): array;

    /**
     * Validates the SHAPE of the answer (422 `invalid_answer_shape` / `unknown_element_id`, 409 `pair_already_matched`:
     * throw {@see PracticeException}) and grades it. A pure function of the element and the answer.
     *
     * @param  array<string, mixed>  $answer
     *
     * @throws PracticeException
     */
    public function grade(SnapshotElement $element, array $answer, ElementState $state): GradeResult;

    /**
     * The rows of the list of mistakes of the summary for an element the learner got wrong (docs/16 §6.4): zero or
     * more `{label, your_answer}` - one for an ordinary element, one per word of `wrong_left` for a round of pairs.
     *
     * @return list<array{label: string, your_answer: string|null}>
     */
    public function mistakeLabel(SnapshotElement $element, ElementState $state, string $locale): array;
}
