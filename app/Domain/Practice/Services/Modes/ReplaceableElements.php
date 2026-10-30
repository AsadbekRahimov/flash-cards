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
     * Which candidates of `$pool` can really be played now - for a dictation, whose clip is on the disk and not empty. The answer
     * is a list of KEYS the mode makes of its candidates and understands again in {@see replacement()}. It reads the disk, so the
     * service asks it BEFORE it takes the lock of the session (docs/16 §9 rule 10), never inside the transaction.
     *
     * @param  list<array<string, mixed>>  $pool
     * @return list<string>
     */
    public function playableSpares(array $pool): array;

    /**
     * The element that takes the place of `$replaced`, made of a candidate of `$pool` (the same source first: a phrase for a
     * phrase, a word for a word), and the pool WITHOUT the candidate used (and without the ones found unusable on the way).
     * Null: nothing in the pool fits - the service then removes the element and the session gets shorter.
     *
     * `$playable`: the keys {@see playableSpares()} found; the choice is then made among them and the disk is not touched. Without
     * it (null) the mode looks at the disk itself.
     *
     * @param  list<array<string, mixed>>  $pool
     * @param  list<string>|null  $playable
     * @return array{0: SnapshotElement, 1: list<array<string, mixed>>}|null
     */
    public function replacement(SnapshotElement $replaced, array $pool, LocaleSet $locales, ?array $playable = null): ?array;
}
