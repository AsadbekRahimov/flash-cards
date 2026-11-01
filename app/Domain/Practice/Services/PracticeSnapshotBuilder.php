<?php

declare(strict_types=1);

namespace App\Domain\Practice\Services;

use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Content\Services\DeckTitles;
use App\Domain\Practice\DTO\LocaleSet;
use App\Domain\Practice\DTO\MaterialScope;
use App\Domain\Practice\DTO\Snapshot;
use App\Domain\Practice\DTO\SnapshotElement;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\Modes\ModeRegistry;
use App\Domain\Practice\Services\Modes\ReplaceableElements;
use App\Domain\Practice\Support\SeededShuffle;
use App\Models\Learner;
use LogicException;

/**
 * Makes the snapshot of a session / a battle through the handler of the mode (docs/16 §2.8, §4.6): the elements, the spares
 * of the pool (a mode with {@see ReplaceableElements}) and what the screens show about the scope without a query - the
 * slug and the titles of the course, the stage / number / title of the lesson.
 *
 * The seed is `random_bytes(32)`, used here and NOT stored: the layout is in the snapshot. A test passes its own
 * (`seed: str_repeat('a', 32)`) and gets the same layout every time.
 */
final class PracticeSnapshotBuilder
{
    public function __construct(
        private readonly ModeRegistry $modes,
        private readonly CourseCatalogService $catalog,
        private readonly DeckTitles $deckTitles,
    ) {}

    /**
     * @param  int  $size  the target number of elements (solo: from the config - pairs count rounds; a battle: the rounds)
     * @param  bool  $strictSize  solo `false`, battle `true`
     *
     * @throws PracticeException practice_not_enough_material (the handler reports it)
     * @throws LogicException a handler returned a malformed list, or the snapshot is bigger than {@see Snapshot::MAX_BYTES}
     */
    public function build(
        PracticeMode $mode,
        MaterialScope $scope,
        ?Learner $learner,
        LocaleSet $locales,
        int $size,
        bool $strictSize,
        ?string $seed = null,
    ): Snapshot {
        $handler = $this->modes->for($mode);
        $seed ??= SeededShuffle::newSeed();

        $elements = $handler->build($scope, $learner, $locales, $seed, $size, $strictSize);
        $this->assertWellFormed($mode, $elements);

        $pool = $handler instanceof ReplaceableElements
            ? $handler->spares($scope, $learner, $locales, $seed, $elements)
            : [];

        $course = $scope->course;
        $lesson = $scope->lesson;

        $titles = [];
        foreach ($locales->locales as $locale) {
            $titles[$locale] = $this->catalog->title($course, $locale);
        }

        $snapshot = new Snapshot(
            mode: $mode,
            locales: $locales->locales,
            elements: $elements,
            pool: $pool,
            courseSlug: $course->slug,
            courseTitle: $titles,
            lessonStage: $lesson?->stage?->number,
            lessonNumber: $lesson?->number,
            lessonTitle: $lesson === null ? null : ($learner === null ? $lesson->title : $this->deckTitles->lesson($course, $learner, $lesson->number, $lesson->title)),
        );
        $snapshot->assertFits();

        return $snapshot;
    }

    /**
     * @param  list<SnapshotElement>  $elements
     */
    private function assertWellFormed(PracticeMode $mode, array $elements): void
    {
        if ($elements === []) {
            throw new LogicException("The handler of {$mode->value} built a snapshot without elements.");
        }

        $ids = [];
        foreach ($elements as $element) {
            if ($element->id === '' || isset($ids[$element->id])) {
                throw new LogicException("The handler of {$mode->value} built an element without a unique id.");
            }
            if ($element->kind !== $mode->elementKind()) {
                throw new LogicException("The handler of {$mode->value} built an element of the kind {$element->kind}.");
            }
            $ids[$element->id] = true;
        }
    }
}
