<?php

declare(strict_types=1);

use App\Domain\Engagement\Exceptions\ChallengeException;
use App\Domain\Engagement\Services\ChallengeService;
use App\Domain\Engagement\Services\FriendshipService;
use App\Jobs\SendFriendDmJob;
use App\Models\Challenge;
use App\Models\Friendship;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\ProductEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
 * ChallengeService (docs/13 §1.5, §2.4, §7.5): propose only to a friend, one open challenge per pair, the
 * period fixed at acceptance, the result from learner_daily_stats (period borders, a draw), idempotent closing.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    config(['friends.challenges' => ['days' => 7, 'pending_ttl_hours' => 72]]);
});

function chSvc(): ChallengeService
{
    return app(ChallengeService::class);
}

function chNow(string $utc = '2026-10-22 10:00:00'): CarbonImmutable
{
    return CarbonImmutable::parse($utc, 'UTC');
}

/** @return array{0: Learner, 1: Learner} two friends (Tashkent unless a zone is given) */
function chFriends(string $tzA = 'Asia/Tashkent', string $tzB = 'Asia/Tashkent'): array
{
    $a = Learner::factory()->create(['timezone' => $tzA]);
    $b = Learner::factory()->create(['timezone' => $tzB]);
    Friendship::factory()->between($a, $b)->create();

    return [$a, $b];
}

function chStats(Learner $learner, string $date, int $reviews = 0, int $newWords = 0): void
{
    LearnerDailyStats::factory()->create(['learner_id' => $learner->id, 'date' => $date, 'reviews_count' => $reviews, 'new_words_count' => $newWords]);
}

function chEvents(string $name): int
{
    return ProductEvent::query()->where('name', $name)->count();
}

it('proposes only to an accepted friend, with a known metric and length', function (): void {
    [$a, $b] = chFriends();
    $stranger = Learner::factory()->create();
    Friendship::factory()->pending()->between($a, $stranger)->create();

    $c = chSvc()->propose($a, $b, 'reviews', now: chNow());

    expect($c->status)->toBe('pending')
        ->and($c->metric)->toBe('reviews')
        ->and($c->days)->toBe(7)
        ->and($c->starts_on)->toBeNull()
        ->and(chEvents('challenge.proposed'))->toBe(1);
    Queue::assertPushed(SendFriendDmJob::class, fn (SendFriendDmJob $j): bool => $j->kind === 'challenge_proposed' && $j->id === $c->id);

    $codes = [];
    foreach ([
        fn () => chSvc()->propose($a, $stranger, 'reviews'),          // pending friendship is not a friend
        fn () => chSvc()->propose($a, Learner::factory()->create(), 'reviews'),
        fn () => chSvc()->propose($a, $a, 'reviews'),
        fn () => chSvc()->propose($b, $a, 'bogus'),
        fn () => chSvc()->propose($b, $a, 'new_words', 0),
        fn () => chSvc()->propose($b, $a, 'new_words', 31),
    ] as $attempt) {
        try {
            $attempt();
        } catch (ChallengeException $e) {
            $codes[] = $e->errorCode;
        }
    }
    expect($codes)->toBe(['not_friends', 'not_friends', 'not_friends', 'challenge_invalid', 'challenge_invalid', 'challenge_invalid']);
});

it('allows ONE open challenge per pair, in either direction, until it is answered', function (): void {
    [$a, $b] = chFriends();
    $first = chSvc()->propose($a, $b, 'reviews', now: chNow());

    foreach ([[$a, $b], [$b, $a]] as [$from, $to]) {
        try {
            chSvc()->propose($from, $to, 'new_words', now: chNow());
            $this->fail('expected challenge_exists');
        } catch (ChallengeException $e) {
            expect($e->errorCode)->toBe('challenge_exists')->and($e->httpStatus())->toBe(409);
        }
    }

    chSvc()->decline($b, $first->id);
    $second = chSvc()->propose($b, $a, 'new_words', now: chNow());

    expect($second->id)->not->toBe($first->id)
        ->and(Challenge::query()->count())->toBe(2);
});

it('lets a challenge nobody answered expire and stop blocking the pair', function (): void {
    [$a, $b] = chFriends();
    $old = chSvc()->propose($a, $b, 'reviews', now: chNow('2026-10-10 10:00:00'));
    $old->forceFill(['created_at' => chNow('2026-10-10 10:00:00')])->save();

    $new = chSvc()->propose($a, $b, 'reviews', now: chNow('2026-10-22 10:00:00'));

    expect($old->fresh()?->status)->toBe('expired')
        ->and($new->status)->toBe('pending');

    // Accepting a stale one expires it, too.
    $new->forceFill(['created_at' => chNow('2026-10-18 09:00:00')])->save();
    try {
        chSvc()->accept($b, $new->id, chNow('2026-10-22 10:00:00'));
        $this->fail('expected challenge_closed');
    } catch (ChallengeException $e) {
        expect($e->errorCode)->toBe('challenge_closed');
    }
    expect($new->fresh()?->status)->toBe('expired');
});

it('fixes the period at acceptance: from the challenger\'s next local day, ending when it is over in both zones', function (): void {
    [$a, $b] = chFriends('Asia/Tashkent', 'America/New_York');
    $c = chSvc()->propose($a, $b, 'reviews', now: chNow());

    // The challenger cannot accept their own challenge; a stranger sees nothing.
    expect(fn () => chSvc()->accept($a, $c->id, chNow()))->toThrow(ChallengeException::class);
    expect(fn () => chSvc()->accept(Learner::factory()->create(), $c->id, chNow()))->toThrow(ChallengeException::class);

    $active = chSvc()->accept($b, $c->id, chNow('2026-10-22 10:00:00'));

    // 10:00 UTC = 15:00 on 22 Oct in Tashkent -> starts 23 Oct, 7 dates: 23..29.
    expect($active->status)->toBe('active')
        ->and($active->starts_on?->toDateString())->toBe('2026-10-23')
        ->and($active->ends_on?->toDateString())->toBe('2026-10-29')
        ->and($active->starts_at?->toIso8601String())->toBe('2026-10-22T19:00:00+00:00')
        // 29 Oct is over in New York (UTC-4) at 04:00 UTC on 30 Oct — later than in Tashkent (19:00 UTC on 29 Oct).
        ->and($active->ends_at?->toIso8601String())->toBe('2026-10-30T04:00:00+00:00')
        ->and(chEvents('challenge.accepted'))->toBe(1);

    // Accepting again changes nothing and raises nothing.
    expect(chSvc()->accept($b, $c->id, chNow('2026-10-22 12:00:00'))->starts_on?->toDateString())->toBe('2026-10-23');
    expect(chEvents('challenge.accepted'))->toBe(1);
    Queue::assertPushed(SendFriendDmJob::class, fn (SendFriendDmJob $j): bool => $j->kind === 'challenge_accepted');
});

it('declines (once) and then the challenge is closed for answers', function (): void {
    [$a, $b] = chFriends();
    $c = chSvc()->propose($a, $b, 'reviews', now: chNow());

    expect(fn () => chSvc()->decline($a, $c->id))->toThrow(ChallengeException::class);
    expect(chSvc()->decline($b, $c->id)->status)->toBe('declined')
        ->and(chSvc()->decline($b, $c->id)->status)->toBe('declined');
    expect(fn () => chSvc()->accept($b, $c->id, chNow()))->toThrow(ChallengeException::class);
});

it('closes by learner_daily_stats: the period borders count, nothing outside does', function (): void {
    [$a, $b] = chFriends();
    $c = chSvc()->propose($a, $b, 'reviews', now: chNow());
    chSvc()->accept($b, $c->id, chNow()); // 23..29 Oct, ends 29 Oct 19:00 UTC

    // Challenger: 10 (first day) + 20 (last day) = 30; the day before and the day after do not count.
    chStats($a, '2026-10-22', reviews: 100);
    chStats($a, '2026-10-23', reviews: 10);
    chStats($a, '2026-10-29', reviews: 20);
    chStats($a, '2026-10-30', reviews: 100);
    // Opponent: 12 + 15 = 27 (new words do not count for the "reviews" metric).
    chStats($b, '2026-10-24', reviews: 12, newWords: 50);
    chStats($b, '2026-10-28', reviews: 15);

    // Not due yet: unchanged.
    expect(chSvc()->close($c->fresh() ?? $c, chNow('2026-10-29 18:59:59'))->status)->toBe('active');

    $closed = chSvc()->close($c->fresh() ?? $c, chNow('2026-10-29 19:00:00'));

    expect($closed->status)->toBe('finished')
        ->and(ksorted((array) $closed->scores))->toBe(['challenger' => 30, 'opponent' => 27])
        ->and($closed->winner_learner_id)->toBe($a->id)
        ->and($closed->open_pair)->toBeNull()
        ->and(chEvents('challenge.finished'))->toBe(1);
});

it('scores the "new_words" metric from new_words_count', function (): void {
    [$a, $b] = chFriends();
    $c = chSvc()->propose($a, $b, 'new_words', 3, chNow());
    chSvc()->accept($b, $c->id, chNow()); // 23..25 Oct
    chStats($a, '2026-10-23', reviews: 90, newWords: 4);
    chStats($b, '2026-10-24', reviews: 1, newWords: 9);

    $closed = chSvc()->close($c->fresh() ?? $c, chNow('2026-10-26 00:00:00'));

    expect(ksorted((array) $closed->scores))->toBe(['challenger' => 4, 'opponent' => 9])
        ->and($closed->winner_learner_id)->toBe($b->id);
});

it('has no winner on a draw (including 0:0)', function (): void {
    [$a, $b] = chFriends();
    $c = chSvc()->propose($a, $b, 'reviews', now: chNow());
    chSvc()->accept($b, $c->id, chNow());
    chStats($a, '2026-10-25', reviews: 8);
    chStats($b, '2026-10-26', reviews: 8);

    $closed = chSvc()->close($c->fresh() ?? $c, chNow('2026-11-01 00:00:00'));
    expect($closed->status)->toBe('finished')
        ->and($closed->winner_learner_id)->toBeNull()
        ->and(ksorted((array) $closed->scores))->toBe(['challenger' => 8, 'opponent' => 8]);

    [$x, $y] = chFriends();
    $z = chSvc()->propose($x, $y, 'reviews', now: chNow());
    chSvc()->accept($y, $z->id, chNow());
    expect(chSvc()->close($z->fresh() ?? $z, chNow('2026-11-01 00:00:00'))->winner_learner_id)->toBeNull();
});

it('closes twice with ONE result, ONE event and ONE DM per side', function (): void {
    [$a, $b] = chFriends();
    $c = chSvc()->propose($a, $b, 'reviews', now: chNow());
    chSvc()->accept($b, $c->id, chNow());
    chStats($a, '2026-10-24', reviews: 5);

    $stale = Challenge::query()->findOrFail($c->id); // a second worker holding the same active row
    chSvc()->close($c->fresh() ?? $c, chNow('2026-11-01 00:00:00'));
    chSvc()->close($stale, chNow('2026-11-02 00:00:00'));
    chSvc()->close(Challenge::query()->findOrFail($c->id), chNow('2026-11-03 00:00:00'));

    $row = Challenge::query()->findOrFail($c->id);
    expect($row->status)->toBe('finished')
        ->and($row->finished_at?->toDateTimeString())->toBe('2026-11-01 00:00:00')
        ->and(ksorted((array) $row->scores))->toBe(['challenger' => 5, 'opponent' => 0])
        ->and(chEvents('challenge.finished'))->toBe(1);
    Queue::assertPushed(SendFriendDmJob::class, fn (SendFriendDmJob $j): bool => $j->kind === 'challenge_finished', 2);
});

it('lets the scheduler command close due challenges and expire stale pending ones, idempotently', function (): void {
    [$a, $b] = chFriends();
    $due = chSvc()->propose($a, $b, 'reviews', now: chNow('2026-10-01 10:00:00'));
    chSvc()->accept($b, $due->id, chNow('2026-10-01 10:00:00'));
    [$x, $y] = chFriends();
    $notYet = chSvc()->propose($x, $y, 'reviews', now: chNow());
    chSvc()->accept($y, $notYet->id, chNow());
    [$p, $q] = chFriends();
    $stale = Challenge::factory()->create(['challenger_id' => $p->id, 'opponent_id' => $q->id, 'open_pair' => 'z', 'created_at' => now()->subDays(10)]);

    $this->travelTo(CarbonImmutable::parse('2026-10-22 12:00:00', 'UTC'));
    $this->artisan('challenges:close-due')->assertSuccessful();
    $this->artisan('challenges:close-due')->assertSuccessful();

    expect($due->fresh()?->status)->toBe('finished')
        ->and($notYet->fresh()?->status)->toBe('active')
        ->and($stale->fresh()?->status)->toBe('expired')
        ->and($stale->fresh()?->open_pair)->toBeNull()
        ->and(chEvents('challenge.finished'))->toBe(1);
});

it('shows live scores for an active challenge and the result for a finished one', function (): void {
    [$a, $b] = chFriends();
    $c = chSvc()->propose($a, $b, 'reviews', now: chNow());
    chSvc()->accept($b, $c->id, chNow());
    chStats($a, '2026-10-24', reviews: 7);
    chStats($b, '2026-10-24', reviews: 3);

    $mine = chSvc()->overview($a)->open[0];
    $theirs = chSvc()->overview($b)->open[0];
    expect($mine->myScore)->toBe(7)->and($mine->theirScore)->toBe(3)->and($mine->role)->toBe('challenger')
        ->and($theirs->myScore)->toBe(3)->and($theirs->theirScore)->toBe(7)->and($theirs->role)->toBe('opponent')
        ->and($mine->friendshipId)->not->toBeNull()
        ->and($mine->result)->toBeNull();

    chSvc()->close($c->fresh() ?? $c, chNow('2026-11-05 00:00:00'));
    $done = chSvc()->overview($b);
    expect($done->open)->toBe([])
        ->and($done->finished)->toHaveCount(1)
        ->and($done->finished[0]->result)->toBe('lost')
        ->and(chSvc()->overview($a)->finished[0]->result)->toBe('won');
});

it('marks the result DMs as dispatched on close, and recovery leaves such a challenge alone', function (): void {
    [$a, $b] = chFriends();
    $c = chSvc()->propose($a, $b, 'reviews', now: chNow());
    chSvc()->accept($b, $c->id, chNow());
    chSvc()->close($c->fresh() ?? $c, chNow('2026-11-01 00:00:00'));

    expect(Challenge::query()->findOrFail($c->id)->dms_dispatched_at)->not->toBeNull()
        ->and(chSvc()->recoverUnnotified(chNow('2026-11-02 00:00:00')))->toBe(0);
    Queue::assertPushed(SendFriendDmJob::class, fn (SendFriendDmJob $j): bool => $j->kind === 'challenge_finished', 2);
});

it('re-queues the result DMs lost between the finished claim and the queue, once', function (): void {
    [$a, $b] = chFriends();
    $c = chSvc()->propose($a, $b, 'reviews', now: chNow());
    chSvc()->accept($b, $c->id, chNow());
    chSvc()->close($c->fresh() ?? $c, chNow('2026-11-01 00:00:00'));

    // The process died after the claim: finished, but nothing queued and no mark.
    Challenge::query()->whereKey($c->id)->update(['dms_dispatched_at' => null]);
    Queue::fake();

    // Too fresh to judge: the original run may still be dispatching.
    expect(chSvc()->recoverUnnotified(chNow('2026-11-01 00:05:00')))->toBe(0);
    Queue::assertNothingPushed();

    expect(chSvc()->recoverUnnotified(chNow('2026-11-01 00:20:00')))->toBe(1);
    Queue::assertPushed(SendFriendDmJob::class, fn (SendFriendDmJob $j): bool => $j->kind === 'challenge_finished' && $j->id === $c->id, 2);

    expect(chSvc()->recoverUnnotified(chNow('2026-11-01 01:00:00')))->toBe(0);
    Queue::assertPushed(SendFriendDmJob::class, 2);
});

it('does not recover expired or declined challenges', function (): void {
    [$a, $b] = chFriends();
    $c = chSvc()->propose($a, $b, 'reviews', now: chNow());
    Challenge::query()->whereKey($c->id)->update(['status' => 'expired', 'open_pair' => null, 'finished_at' => chNow()]);

    expect(chSvc()->recoverUnnotified(chNow('2026-11-05 00:00:00')))->toBe(0);
});

/** An active challenge `$challenger` -> `$opponent` over the local dates `$from`..`$to`, ends_at long after (nothing is due yet). */
function chActive(Learner $challenger, Learner $opponent, string $metric, string $from, string $to): Challenge
{
    [$low, $high] = FriendshipService::pair($challenger->id, $opponent->id);

    return Challenge::factory()->active($from)->create([
        'challenger_id' => $challenger->id,
        'opponent_id' => $opponent->id,
        'metric' => $metric,
        'days' => (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1,
        'ends_on' => $to,
        'ends_at' => '2026-12-31 00:00:00',
        'open_pair' => "{$low}:{$high}",
    ]);
}

it('sums every active challenge over its own period and metric, and gets the same numbers as the closing sums', function (): void {
    $me = Learner::factory()->create(['timezone' => 'Asia/Tashkent']);
    [$a, $b, $c, $d] = [Learner::factory()->create(), Learner::factory()->create(), Learner::factory()->create(), Learner::factory()->create()];
    $nobody = Learner::factory()->create(); // a side without a single stats row
    foreach ([$a, $b, $c, $d, $nobody] as $friend) {
        Friendship::factory()->between($me, $friend)->create();
    }

    // Every learner has a row for 18..31 Oct with numbers that differ per learner, day and column.
    /** @var array<int, array<string, array{reviews_count: int, new_words_count: int}>> $stats */
    $stats = [];
    foreach ([$me, $a, $b, $c, $d] as $i => $learner) {
        foreach (range(18, 31) as $day) {
            $date = sprintf('2026-10-%02d', $day);
            $stats[$learner->id][$date] = ['reviews_count' => ($day * 3 + $i * 5) % 17 + 1, 'new_words_count' => ($day + $i * 2) % 5 + 1];
            LearnerDailyStats::factory()->create(['learner_id' => $learner->id, 'date' => $date] + $stats[$learner->id][$date]);
        }
    }
    $expected = fn (Learner $l, string $from, string $to, string $column): int => array_sum(array_map(
        static fn (array $day): int => $day[$column],
        array_filter($stats[$l->id] ?? [], static fn (string $date): bool => $date >= $from && $date <= $to, ARRAY_FILTER_USE_KEY),
    ));

    // Different periods (3, 7 and 1 days; the viewer on either side), both metrics, borders inside the stats rows.
    $c1 = chActive($me, $a, 'reviews', '2026-10-23', '2026-10-25');
    $c2 = chActive($me, $b, 'new_words', '2026-10-24', '2026-10-30');
    $c3 = chActive($c, $me, 'reviews', '2026-10-20', '2026-10-26');
    $c4 = chActive($me, $nobody, 'new_words', '2026-10-27', '2026-10-27');
    $c5 = chActive($d, $me, 'new_words', '2026-10-18', '2026-10-31');
    // Not scored from the stats: a pending one (no period yet) and a finished one (its stored result).
    $pending = Challenge::factory()->create(['challenger_id' => $me->id, 'opponent_id' => Learner::factory()->create()->id, 'open_pair' => 'p:p']);
    $finished = Challenge::factory()->create([
        'challenger_id' => $me->id, 'opponent_id' => $a->id, 'status' => 'finished', 'scores' => ['challenger' => 7, 'opponent' => 9],
        'winner_learner_id' => $a->id, 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-07',
    ]);

    $overview = chSvc()->overview($me);

    $byId = [];
    foreach ($overview->open as $entry) {
        $byId[$entry->id] = $entry;
    }
    expect($overview->open)->toHaveCount(6);
    foreach ([[$c1, 'reviews_count'], [$c2, 'new_words_count'], [$c3, 'reviews_count'], [$c4, 'new_words_count'], [$c5, 'new_words_count']] as [$challenge, $column]) {
        $from = $challenge->starts_on?->toDateString() ?? '';
        $to = $challenge->ends_on?->toDateString() ?? '';
        $challengerScore = $expected($challenge->challenger()->firstOrFail(), $from, $to, $column);
        $opponentScore = $expected($challenge->opponent()->firstOrFail(), $from, $to, $column);
        $iAmChallenger = $challenge->challenger_id === $me->id;

        expect($byId[$challenge->id]->myScore)->toBe($iAmChallenger ? $challengerScore : $opponentScore)
            ->and($byId[$challenge->id]->theirScore)->toBe($iAmChallenger ? $opponentScore : $challengerScore);
    }
    // Spot values worked out by hand, so the oracle above is not the only witness: me 23..25 Oct reviews.
    expect($byId[$c1->id]->myScore)->toBe($expected($me, '2026-10-23', '2026-10-25', 'reviews_count'))
        ->and($byId[$c4->id]->theirScore)->toBe(0)
        ->and($byId[$c4->id]->myScore)->toBe($stats[$me->id]['2026-10-27']['new_words_count'])
        ->and($byId[$pending->id]->myScore)->toBeNull()
        ->and($byId[$pending->id]->theirScore)->toBeNull();
    $done = $overview->finished[0];
    expect($done->id)->toBe($finished->id)->and($done->myScore)->toBe(7)->and($done->theirScore)->toBe(9)->and($done->result)->toBe('lost');

    // Closing sums every challenge on its own (ChallengeService::score), so after the periods end the final scores must
    // be the numbers the screen showed while they ran.
    foreach ([$c1, $c2, $c3, $c4, $c5] as $challenge) {
        $closed = chSvc()->close($challenge->fresh() ?? $challenge, CarbonImmutable::parse('2027-01-02 00:00:00', 'UTC'));
        $entry = $byId[$challenge->id];
        $iAmChallenger = $challenge->challenger_id === $me->id;

        expect($closed->status)->toBe('finished')
            ->and(ksorted((array) $closed->scores))->toBe(['challenger' => $iAmChallenger ? $entry->myScore : $entry->theirScore, 'opponent' => $iAmChallenger ? $entry->theirScore : $entry->myScore]);
    }
});

it('reads the stats, the names and the friendships once for the open AND the finished lists: the query count does not grow with them', function (): void {
    $count = function (int $challenges): array {
        $me = Learner::factory()->create(['timezone' => 'Asia/Tashkent']);
        foreach (range(1, $challenges) as $i) {
            $friend = Learner::factory()->create();
            Friendship::factory()->between($me, $friend)->create();
            chActive($i % 2 === 0 ? $friend : $me, $i % 2 === 0 ? $me : $friend, $i % 3 === 0 ? 'new_words' : 'reviews', '2026-10-2'.($i % 5), '2026-10-29');
            chStats($friend, '2026-10-26', reviews: $i, newWords: 1);
            // The same friend in the second list (the finished ones are scored from their stored result).
            Challenge::factory()->create(['challenger_id' => $me->id, 'opponent_id' => $friend->id, 'status' => 'finished', 'scores' => ['challenger' => 3, 'opponent' => 1], 'winner_learner_id' => $me->id]);
        }
        chStats($me, '2026-10-26', reviews: 5, newWords: 2);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $overview = chSvc()->overview($me);
        $log = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        expect($overview->open)->toHaveCount($challenges)
            ->and($overview->finished)->toHaveCount($challenges);

        $reads = static fn (string $table): int => count(array_filter($log, static fn (string $q): bool => preg_match('/\bfrom\s+[`"]?'.$table.'[`"]?(\s|$)/i', $q) === 1));

        return [count($log), $reads('learner_daily_stats'), $reads('learners'), $reads('friendships')];
    };

    [$one, $oneStats, $oneNames, $oneFriendships] = $count(1);
    [$many, $manyStats, $manyNames, $manyFriendships] = $count(20);

    // Two selects of the lists (open, finished), then ONE read of the names, of the friendships and of the stats for both.
    expect($one)->toBe(5)
        ->and($many)->toBe(5)
        ->and([$oneStats, $oneNames, $oneFriendships])->toBe([1, 1, 1])
        ->and([$manyStats, $manyNames, $manyFriendships])->toBe([1, 1, 1]);
});

it('does not read learner_daily_stats at all when no challenge is active', function (): void {
    [$a, $b] = chFriends();
    $c = chSvc()->propose($a, $b, 'reviews', now: chNow()); // pending
    chSvc()->decline($b, $c->id);
    chSvc()->propose($b, $a, 'reviews', now: chNow()); // pending again

    DB::flushQueryLog();
    DB::enableQueryLog();
    $overview = chSvc()->overview($a);
    $log = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    expect($overview->open)->toHaveCount(1)
        ->and($overview->open[0]->myScore)->toBeNull()
        ->and(array_filter($log, static fn (string $q): bool => str_contains($q, 'learner_daily_stats')))->toBe([]);
});
