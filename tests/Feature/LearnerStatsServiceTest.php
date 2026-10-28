<?php

declare(strict_types=1);

use App\Domain\Engagement\Services\AchievementService;
use App\Domain\Learning\Services\LearnerStatsService;
use App\Models\Learner;
use App\Models\WordRepetition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
 * LearnerStatsService: `wordsLearnedFor()` is the EXACT count (the learner's own numbers, achievements);
 * `cachedWordsLearnedFor()` is its friends-screen variant — the same numbers, up to ten minutes old, counted in
 * the database only for the learners that missed the cache.
 */

uses(RefreshDatabase::class);

function lsLearned(Learner $learner, int $count, int $repetitions = 1): void
{
    WordRepetition::factory()->count($count)->create(['learner_id' => $learner->id, 'repetitions' => $repetitions]);
}

/** @return list<array{query: string, bindings: list<mixed>}> the queries `$fn` ran against word_repetitions */
function lsRepetitionReads(Closure $fn): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $fn();
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    return array_values(array_filter($log, static fn (array $q): bool => str_contains($q['query'], 'word_repetitions')));
}

it('gives the cached variant the same numbers as the exact one, and 0 for a learner without a learned word', function (): void {
    $stats = app(LearnerStatsService::class);
    [$a, $b, $none] = [Learner::factory()->create(), Learner::factory()->create(), Learner::factory()->create()];
    lsLearned($a, 3);
    lsLearned($b, 1, repetitions: 5);
    lsLearned($b, 2, repetitions: 0); // seen, not learned
    $ids = [$a->id, $b->id, $none->id];

    expect($stats->cachedWordsLearnedFor($ids))->toBe([$a->id => 3, $b->id => 1, $none->id => 0])
        ->and($stats->cachedWordsLearnedFor($ids))->toBe($stats->wordsLearnedFor($ids))
        ->and($stats->cachedWordsLearnedFor([]))->toBe([]);
});

it('counts in the database only the learners that missed the cache, in one query, and nobody while all are cached', function (): void {
    $stats = app(LearnerStatsService::class);
    [$a, $b, $c] = [Learner::factory()->create(), Learner::factory()->create(), Learner::factory()->create()];
    lsLearned($a, 2);
    lsLearned($b, 4);
    lsLearned($c, 1);

    $first = lsRepetitionReads(fn () => $stats->cachedWordsLearnedFor([$a->id, $b->id]));
    $again = lsRepetitionReads(fn () => $stats->cachedWordsLearnedFor([$a->id, $b->id]));
    $third = lsRepetitionReads(fn () => $stats->cachedWordsLearnedFor([$c->id, $a->id, $b->id]));

    expect($first)->toHaveCount(1)
        ->and($first[0]['bindings'])->toBe([$a->id, $b->id, 1])
        ->and($again)->toBe([])
        ->and($third)->toHaveCount(1)
        ->and($third[0]['bindings'])->toBe([$c->id, 1]);
});

it('lags up to ten minutes behind, while the exact count never does', function (): void {
    $stats = app(LearnerStatsService::class);
    $friend = Learner::factory()->create();
    lsLearned($friend, 2);

    expect($stats->cachedWordsLearnedFor([$friend->id]))->toBe([$friend->id => 2]);
    lsLearned($friend, 3);

    expect($stats->wordsLearnedFor([$friend->id]))->toBe([$friend->id => 5])
        ->and($stats->forLearner($friend)->wordsLearned)->toBe(5)
        ->and($stats->cachedWordsLearnedFor([$friend->id]))->toBe([$friend->id => 2]);

    $this->travel(9)->minutes();
    expect($stats->cachedWordsLearnedFor([$friend->id]))->toBe([$friend->id => 2]);

    $this->travel(2)->minutes();
    expect($stats->cachedWordsLearnedFor([$friend->id]))->toBe([$friend->id => 5]);
});

it('does not let the friends\' cache delay the learner\'s own achievements', function (): void {
    Queue::fake();
    config(['achievements.enabled' => true]);
    $stats = app(LearnerStatsService::class);
    $learner = Learner::factory()->create();
    lsLearned($learner, 99);

    // A friend opened the friends screen while the learner had 99 words: 99 is now cached under their id.
    expect($stats->cachedWordsLearnedFor([$learner->id]))->toBe([$learner->id => 99]);
    lsLearned($learner, 1);

    $unlocked = app(AchievementService::class)->evaluate($learner, now()->toImmutable());

    expect(array_map(static fn ($a): string => $a->key, $unlocked))->toBe(['words_100']);
});
