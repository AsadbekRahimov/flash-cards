<?php

declare(strict_types=1);

namespace App\Domain\Cup\Services;

use App\Domain\Cup\Support\CupSeeds;
use App\Domain\Game\DTO\RunState;
use App\Domain\Game\Services\Tasks\TaskRegistry;
use App\Domain\Practice\DTO\SnapshotElement;
use App\Domain\Practice\Support\SeededShuffle;

/**
 * What the client sees of a task of the cup (docs/33 §5.2, §7.2): the game's own projection ({@see TaskRegistry} - no
 * `correct_option_id`, `expected`, `word_id`, `audio_path`, `lexeme_id`, …) with the OPTIONS in the participant's own order: a
 * deterministic shuffle by `HMAC(day seed, participant, element)`, so a reload shows the same order and two participants of the same
 * day see the same options in different places. The ids of the options are the snapshot's, so grading does not change.
 */
final class CupProjection
{
    public function __construct(private readonly TaskRegistry $tasks) {}

    /** @return array<string, mixed> */
    public function project(SnapshotElement $element, string $locale, RunState $state, string $daySeed, int $participantId): array
    {
        $projection = $this->tasks->forElement($element)->project($element, $locale, $state);
        $options = $projection['options'] ?? null;
        if (is_array($options) && array_is_list($options) && count($options) > 1) {
            $projection['options'] = SeededShuffle::apply($options, CupSeeds::options($daySeed, $participantId, $element->id), 'cup-options');
        }

        return $projection;
    }

    /** @return array<string, mixed> */
    public function reveal(SnapshotElement $element, string $locale, RunState $state): array
    {
        return $this->tasks->forElement($element)->reveal($element, $locale, $state);
    }
}
