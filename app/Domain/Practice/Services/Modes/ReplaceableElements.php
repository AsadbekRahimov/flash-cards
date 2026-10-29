<?php

declare(strict_types=1);

namespace App\Domain\Practice\Services\Modes;

use App\Domain\Practice\DTO\LocaleSet;
use App\Domain\Practice\DTO\MaterialScope;
use App\Domain\Practice\DTO\SnapshotElement;
use App\Models\Learner;

/**
 * An optional capability of a mode handler: its elements can be swapped for a spare without a penalty (a dictation whose
 * audio does not play, docs/16 §10.4). The snapshot keeps the spares in `pool`; the swap itself (the limit, the state, the
 * counters, the attempt, the event) is the same for every mode that has the capability and lives in `PracticeSessionService::replace`.
 *
 * A mode that does not implement it answers every swap with 409 `replace_not_allowed` (`not_dictation`).
 */
interface ReplaceableElements
{
    /**
     * The spare candidates for `Snapshot::$pool`: what was NOT taken into `$chosen`, in seeded order. A candidate is a small
     * array the mode understands (docs/16 §2.8: `{version_id}` for a phrase, `{word_id}` for a word).
     *
     * @param  list<SnapshotElement>  $chosen
     * @return list<array<string, mixed>>
     */
    public function spares(MaterialScope $scope, ?Learner $learner, LocaleSet $locales, string $seed, array $chosen): array;

    /**
     * The element that takes the place of `$replaced`, made of a candidate of `$pool` (the same source first: a phrase for a
     * phrase, a word for a word), and the pool WITHOUT the candidate used (and without the ones found unusable on the way).
     * Null: nothing in the pool fits - the service then removes the element and the session gets shorter.
     *
     * @param  list<array<string, mixed>>  $pool
     * @return array{0: SnapshotElement, 1: list<array<string, mixed>>}|null
     */
    public function replacement(SnapshotElement $replaced, array $pool, LocaleSet $locales): ?array;
}
