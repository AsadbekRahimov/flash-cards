<?php

declare(strict_types=1);

use App\Domain\Twa\Services\JwtService;
use App\Models\Challenge;
use App\Models\Friendship;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\Word;
use App\Models\WordRepetition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
 * GET /api/twa/friends, POST accept / decline / remove, GET/POST /api/twa/challenges (docs/04 A.13): the learner's
 * own pairs only — somebody else's id is a 404 that looks like a missing one — and no ids of other learners.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    config(['telegram.bot_username' => 'LexiFlowBot']);
});

function tfHeaders(Learner $learner): array
{
    return ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
}

it('lists friends with streak and words, the incoming and outgoing requests, and the invite link', function (): void {
    $me = Learner::factory()->create(['referral_code' => 'MYCODE23']);
    $friend = Learner::factory()->create(['first_name' => 'Dilnoza', 'username' => 'dil_secret', 'streak_current' => 4, 'streak_last_date' => now()->toDateString()]);
    $asker = Learner::factory()->create(['first_name' => 'Bek']);
    $asked = Learner::factory()->create(['first_name' => 'Sam']);
    $friendship = Friendship::factory()->between($me, $friend)->create();
    $incoming = Friendship::factory()->pending()->between($asker, $me, $asker)->create();
    Friendship::factory()->pending()->between($me, $asked, $me)->create();
    Friendship::factory()->create(); // a stranger's pair

    $response = $this->withHeaders(tfHeaders($me))->getJson('/api/twa/friends')->assertOk();

    expect($response->json('invite_link'))->toBe('https://t.me/LexiFlowBot?start=f_MYCODE23')
        ->and($response->json('limit'))->toBe(100)
        ->and($response->json('friends'))->toHaveCount(1)
        ->and($response->json('friends.0.id'))->toBe($friendship->id)
        ->and($response->json('friends.0.first_name'))->toBe('Dilnoza')
        ->and($response->json('friends.0.streak'))->toBe(4)
        ->and($response->json('friends.0.words_learned'))->toBe(0)
        ->and(array_keys($response->json('friends.0')))->toBe(['id', 'first_name', 'streak', 'words_learned', 'since'])
        ->and($response->json('incoming'))->toHaveCount(1)
        ->and($response->json('incoming.0.id'))->toBe($incoming->id)
        ->and($response->json('incoming.0.first_name'))->toBe('Bek')
        ->and($response->json('outgoing.0.first_name'))->toBe('Sam')
        ->and($response->getContent())->not->toContain('dil_secret');
});

it('lists any number of friends in the same number of queries, and reads no learned words while they are cached', function (): void {
    $list = function (int $friends): array {
        $me = Learner::factory()->create(['referral_code' => 'MYCODE'.$friends]); // else the first GET writes the code (one more query)
        $words = Word::factory()->count(3)->create();
        foreach (range(1, $friends) as $i) {
            $friend = Learner::factory()->create(['first_name' => "Friend {$i}"]);
            Friendship::factory()->between($me, $friend)->create();
            foreach ($words->take(1 + $i % 3) as $word) { // Friend N has learned 1 + N % 3 words
                WordRepetition::factory()->create(['learner_id' => $friend->id, 'word_id' => $word->id, 'repetitions' => 1]);
            }
        }
        $get = function () use ($me): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $response = $this->withHeaders(tfHeaders($me))->getJson('/api/twa/friends')->assertOk();
            $queries = array_column(DB::getQueryLog(), 'query');
            DB::disableQueryLog();

            return [
                count($queries),
                count(array_filter($queries, static fn (string $q): bool => str_contains($q, 'word_repetitions'))),
                array_column($response->json('friends'), 'words_learned', 'first_name'),
            ];
        };

        return [$get(), $get()];
    };

    [[$coldOne, $coldOneReads, $coldOneWords], [$warmOne, $warmOneReads, $warmOneWords]] = $list(1);
    [[$coldMany, $coldManyReads, $coldManyWords], [$warmMany, $warmManyReads, $warmManyWords]] = $list(20);

    expect($coldMany)->toBe($coldOne)
        ->and($warmMany)->toBe($warmOne)
        ->and($warmOne)->toBe($coldOne - 1)
        ->and([$coldOneReads, $coldManyReads])->toBe([1, 1])  // ONE count for all the friends that missed
        ->and([$warmOneReads, $warmManyReads])->toBe([0, 0])  // none once they are cached
        ->and($coldOneWords)->toBe(['Friend 1' => 2])
        ->and($warmOneWords)->toBe($coldOneWords)
        ->and($coldManyWords)->toEqual(array_combine(array_map(static fn (int $i): string => "Friend {$i}", range(1, 20)), array_map(static fn (int $i): int => 1 + $i % 3, range(1, 20))))
        ->and($warmManyWords)->toBe($coldManyWords);
});

it('requires a learner token', function (): void {
    $this->getJson('/api/twa/friends')->assertUnauthorized();
    $this->postJson('/api/twa/friends/1/accept')->assertUnauthorized();
    $this->getJson('/api/twa/challenges')->assertUnauthorized();
    $this->postJson('/api/twa/challenges', [])->assertUnauthorized();
});

it('accepts, declines and removes among the learner\'s own pairs and answers with the refreshed list', function (): void {
    $me = Learner::factory()->create();
    $a = Learner::factory()->create();
    $b = Learner::factory()->create();
    $c = Learner::factory()->create();
    $toAccept = Friendship::factory()->pending()->between($a, $me, $a)->create();
    $toDecline = Friendship::factory()->pending()->between($b, $me, $b)->create();
    $toRemove = Friendship::factory()->between($me, $c)->create();

    $this->withHeaders(tfHeaders($me))->postJson("/api/twa/friends/{$toAccept->id}/accept")
        ->assertOk()->assertJsonCount(2, 'friends')->assertJsonCount(1, 'incoming');
    $this->withHeaders(tfHeaders($me))->postJson("/api/twa/friends/{$toDecline->id}/decline")
        ->assertOk()->assertJsonCount(0, 'incoming');
    $this->withHeaders(tfHeaders($me))->postJson("/api/twa/friends/{$toRemove->id}/remove")
        ->assertOk()->assertJsonCount(1, 'friends');

    expect($toAccept->fresh()?->status)->toBe('accepted')
        ->and(Friendship::query()->whereKey([$toDecline->id, $toRemove->id])->count())->toBe(0);
});

it('answers 404 for somebody else\'s pair, a missing id and a repeat, 403 for the asker answering themselves', function (): void {
    $me = Learner::factory()->create();
    $other = Friendship::factory()->pending()->create();
    $mine = Friendship::factory()->pending()->between($me, Learner::factory()->create(), $me)->create();

    foreach (['accept', 'decline', 'remove'] as $action) {
        $this->withHeaders(tfHeaders($me))->postJson("/api/twa/friends/{$other->id}/{$action}")
            ->assertNotFound()->assertJsonPath('error.code', 'friendship_not_found');
        $this->withHeaders(tfHeaders($me))->postJson('/api/twa/friends/999999/'.$action)->assertNotFound();
    }
    expect($other->fresh()?->status)->toBe('pending');

    $this->withHeaders(tfHeaders($me))->postJson("/api/twa/friends/{$mine->id}/accept")
        ->assertForbidden()->assertJsonPath('error.code', 'not_recipient');
    $this->withHeaders(tfHeaders($me))->postJson("/api/twa/friends/{$mine->id}/decline")->assertForbidden();

    $this->withHeaders(tfHeaders($me))->postJson('/api/twa/friends/abc/accept')->assertStatus(422);
});

it('answers 409 when the friend limit is reached on accept', function (): void {
    config(['friends.max' => 1]);
    $me = Learner::factory()->create();
    Friendship::factory()->between($me, Learner::factory()->create())->create();
    $row = Friendship::factory()->pending()->between($asker = Learner::factory()->create(), $me, $asker)->create();

    $this->withHeaders(tfHeaders($me))->postJson("/api/twa/friends/{$row->id}/accept")
        ->assertStatus(409)->assertJsonPath('error.code', 'friend_limit');
});

it('proposes a challenge to a friend by friendship id, and lists, accepts and declines it', function (): void {
    $me = Learner::factory()->create(['timezone' => 'Asia/Tashkent']);
    $friend = Learner::factory()->create(['first_name' => 'Bek', 'timezone' => 'Asia/Tashkent']);
    $row = Friendship::factory()->between($me, $friend)->create();

    $created = $this->withHeaders(tfHeaders($me))->postJson('/api/twa/challenges', ['friendship_id' => $row->id, 'metric' => 'reviews'])
        ->assertCreated()
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('role', 'challenger')
        ->assertJsonPath('friend_name', 'Bek')
        ->assertJsonPath('friendship_id', $row->id)
        ->assertJsonPath('days', 7);
    $id = $created->json('id');

    // Only one open challenge per pair.
    $this->withHeaders(tfHeaders($me))->postJson('/api/twa/challenges', ['friendship_id' => $row->id, 'metric' => 'new_words'])
        ->assertStatus(409)->assertJsonPath('error.code', 'challenge_exists');

    // The challenger cannot answer; the friend accepts.
    $this->withHeaders(tfHeaders($me))->postJson("/api/twa/challenges/{$id}/accept")->assertForbidden()->assertJsonPath('error.code', 'not_opponent');
    $this->withHeaders(tfHeaders($friend))->postJson("/api/twa/challenges/{$id}/accept")
        ->assertOk()->assertJsonPath('status', 'active')->assertJsonPath('role', 'opponent')->assertJsonPath('friend_name', $me->first_name);

    LearnerDailyStats::factory()->create(['learner_id' => $me->id, 'date' => now('Asia/Tashkent')->addDay()->toDateString(), 'reviews_count' => 11]);
    $list = $this->withHeaders(tfHeaders($me))->getJson('/api/twa/challenges')->assertOk();
    expect($list->json('days'))->toBe(7)
        ->and($list->json('open'))->toHaveCount(1)
        ->and($list->json('open.0.my_score'))->toBe(11)
        ->and($list->json('open.0.their_score'))->toBe(0)
        ->and($list->json('finished'))->toBe([]);

    // Decline on a fresh pair.
    $other = Learner::factory()->create();
    $row2 = Friendship::factory()->between($me, $other)->create();
    $second = $this->withHeaders(tfHeaders($other))->postJson('/api/twa/challenges', ['friendship_id' => $row2->id, 'metric' => 'new_words'])->assertCreated()->json('id');
    $this->withHeaders(tfHeaders($me))->postJson("/api/twa/challenges/{$second}/decline")->assertOk()->assertJsonPath('status', 'declined');
});

it('lists the open and the finished challenges with live scores in a constant number of queries', function (): void {
    $list = function (int $challenges): array {
        $me = Learner::factory()->create(['timezone' => 'Asia/Tashkent']);
        foreach (range(1, $challenges) as $i) {
            $friend = Learner::factory()->create(['first_name' => "Friend {$i}"]);
            Friendship::factory()->between($me, $friend)->create();
            // An earlier, finished challenge of the same pair: the second list reads the same names and friendships.
            Challenge::factory()->create(['challenger_id' => $friend->id, 'opponent_id' => $me->id, 'status' => 'finished', 'scores' => ['challenger' => 1, 'opponent' => 2], 'winner_learner_id' => $me->id]);
            $metric = $i % 2 === 0 ? 'new_words' : 'reviews';
            Challenge::factory()->active('2026-10-23')->create([
                'challenger_id' => $me->id, 'opponent_id' => $friend->id, 'metric' => $metric, 'open_pair' => "{$me->id}:{$friend->id}",
            ]);
            LearnerDailyStats::factory()->create(['learner_id' => $friend->id, 'date' => '2026-10-24', 'reviews_count' => 10 + $i, 'new_words_count' => 100 + $i]);
        }
        LearnerDailyStats::factory()->create(['learner_id' => $me->id, 'date' => '2026-10-23', 'reviews_count' => 3, 'new_words_count' => 4]);
        LearnerDailyStats::factory()->create(['learner_id' => $me->id, 'date' => '2026-10-29', 'reviews_count' => 5, 'new_words_count' => 6]); // the last day
        LearnerDailyStats::factory()->create(['learner_id' => $me->id, 'date' => '2026-10-30', 'reviews_count' => 99, 'new_words_count' => 99]); // after the period (23..29)
        LearnerDailyStats::factory()->create(['learner_id' => $me->id, 'date' => '2026-10-22', 'reviews_count' => 99, 'new_words_count' => 99]); // before it

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->withHeaders(tfHeaders($me))->getJson('/api/twa/challenges')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$count, $response->json('open'), $response->json('finished')];
    };

    [$one, $oneOpen, $oneFinished] = $list(1);
    [$many, $manyOpen, $manyFinished] = $list(20);

    // The learner, the open and the finished challenges, then ONE read of the names, of the friendships and of the stats for both lists.
    expect($many)->toBe($one)
        ->and($one)->toBe(6)
        ->and($oneFinished)->toHaveCount(1)
        ->and($manyFinished)->toHaveCount(20)
        ->and($oneOpen)->toHaveCount(1)
        ->and($oneOpen[0]['metric'])->toBe('reviews')
        ->and($oneOpen[0]['my_score'])->toBe(8)      // 3 + 5 on the two days of the period
        ->and($oneOpen[0]['their_score'])->toBe(11)
        ->and($manyOpen)->toHaveCount(20);
    // Newest first: the 20th friend is index 0 ("new_words": my 4 + 6, theirs 100 + 20).
    expect($manyOpen[0]['friend_name'])->toBe('Friend 20')
        ->and($manyOpen[0]['metric'])->toBe('new_words')
        ->and($manyOpen[0]['my_score'])->toBe(10)
        ->and($manyOpen[0]['their_score'])->toBe(120)
        ->and($manyOpen[19]['my_score'])->toBe(8)
        ->and($manyOpen[19]['their_score'])->toBe(11);
});

/**
 * A viewer with open (pending and active) and finished challenges against five people; the friend Ann and the friend Cal
 * are in both lists.
 *
 * @return array{0: Learner, 1: array<string, int>, 2: array<string, int>} the viewer, the friendship ids and the challenge ids by name
 */
function tfMixedChallenges(): array
{
    $me = Learner::factory()->create(['timezone' => 'Asia/Tashkent']);
    $people = [];
    foreach (['Ann', 'Bek', 'Cal', 'Dan', 'Eve'] as $name) {
        $people[$name] = Learner::factory()->create(['first_name' => $name]);
    }
    // Dan is not a friend any more: his finished challenge stays, without a handle for "challenge again".
    $friendships = [];
    foreach (['Ann', 'Bek', 'Cal'] as $name) {
        $friendships[$name] = Friendship::factory()->between($me, $people[$name])->create()->id;
    }
    $pair = static fn (Learner $a, Learner $b): string => min($a->id, $b->id).':'.max($a->id, $b->id);

    // Finished: won against Cal, lost to Dan, a draw with Ann. The same friends are also in the open list below.
    $ids = [];
    $ids['cal_won'] = Challenge::factory()->create(['challenger_id' => $me->id, 'opponent_id' => $people['Cal']->id, 'status' => 'finished', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-07', 'scores' => ['challenger' => 12, 'opponent' => 9], 'winner_learner_id' => $me->id, 'created_at' => '2026-10-01 08:00:00'])->id;
    $ids['dan_lost'] = Challenge::factory()->create(['challenger_id' => $people['Dan']->id, 'opponent_id' => $me->id, 'metric' => 'new_words', 'status' => 'finished', 'starts_on' => '2026-10-08', 'ends_on' => '2026-10-14', 'scores' => ['challenger' => 20, 'opponent' => 18], 'winner_learner_id' => $people['Dan']->id, 'created_at' => '2026-10-08 08:00:00'])->id;
    $ids['ann_draw'] = Challenge::factory()->create(['challenger_id' => $me->id, 'opponent_id' => $people['Ann']->id, 'status' => 'finished', 'starts_on' => '2026-10-15', 'ends_on' => '2026-10-21', 'scores' => ['challenger' => 5, 'opponent' => 5], 'winner_learner_id' => null, 'created_at' => '2026-10-15 08:00:00'])->id;
    // Open: Bek's new-words race is running, mine with Ann waits for her, mine with Cal runs 25..27 Oct.
    $ids['bek_active'] = Challenge::factory()->active('2026-10-23')->create(['challenger_id' => $people['Bek']->id, 'opponent_id' => $me->id, 'metric' => 'new_words', 'open_pair' => $pair($me, $people['Bek']), 'created_at' => '2026-10-22 08:00:00'])->id;
    $ids['ann_pending'] = Challenge::factory()->create(['challenger_id' => $me->id, 'opponent_id' => $people['Ann']->id, 'open_pair' => $pair($me, $people['Ann']), 'created_at' => '2026-10-24 09:00:00'])->id;
    $ids['cal_active'] = Challenge::factory()->active('2026-10-25')->create(['challenger_id' => $me->id, 'opponent_id' => $people['Cal']->id, 'days' => 3, 'ends_on' => '2026-10-27', 'open_pair' => $pair($me, $people['Cal']), 'created_at' => '2026-10-24 10:00:00'])->id;
    // Neither list: a closed one of mine and a stranger's.
    Challenge::factory()->create(['challenger_id' => $me->id, 'opponent_id' => $people['Eve']->id, 'status' => 'expired']);
    Challenge::factory()->create(['open_pair' => 'x:y']);

    // Live scores. Bek's race (new_words, 23..29 Oct): Bek 6 (the 22nd is before it), me 4 (the 30th is after it).
    // Cal's (reviews, 25..27 Oct): me 7 + 8, Cal 5.
    $stats = [
        [$people['Bek'], '2026-10-23', 0, 6], [$people['Bek'], '2026-10-22', 0, 50], [$me, '2026-10-24', 0, 4], [$me, '2026-10-30', 0, 99],
        [$me, '2026-10-25', 7, 0], [$me, '2026-10-26', 8, 0], [$people['Cal'], '2026-10-27', 5, 0],
    ];
    foreach ($stats as [$learner, $date, $reviews, $newWords]) {
        LearnerDailyStats::factory()->create(['learner_id' => $learner->id, 'date' => $date, 'reviews_count' => $reviews, 'new_words_count' => $newWords]);
    }

    return [$me, $friendships, $ids];
}

it('lists the open and the finished challenges with names, friendship ids and scores, newest first', function (): void {
    [$me, $friend, $id] = tfMixedChallenges();

    $this->withHeaders(tfHeaders($me))->getJson('/api/twa/challenges')->assertOk()->assertExactJson([
        'days' => 7,
        'open' => [
            ['id' => $id['cal_active'], 'status' => 'active', 'metric' => 'reviews', 'days' => 3, 'role' => 'challenger', 'friend_name' => 'Cal', 'friendship_id' => $friend['Cal'], 'starts_on' => '2026-10-25', 'ends_on' => '2026-10-27', 'created_at' => '2026-10-24T10:00:00+00:00', 'my_score' => 15, 'their_score' => 5, 'result' => null],
            ['id' => $id['ann_pending'], 'status' => 'pending', 'metric' => 'reviews', 'days' => 7, 'role' => 'challenger', 'friend_name' => 'Ann', 'friendship_id' => $friend['Ann'], 'starts_on' => null, 'ends_on' => null, 'created_at' => '2026-10-24T09:00:00+00:00', 'my_score' => null, 'their_score' => null, 'result' => null],
            ['id' => $id['bek_active'], 'status' => 'active', 'metric' => 'new_words', 'days' => 7, 'role' => 'opponent', 'friend_name' => 'Bek', 'friendship_id' => $friend['Bek'], 'starts_on' => '2026-10-23', 'ends_on' => '2026-10-29', 'created_at' => '2026-10-22T08:00:00+00:00', 'my_score' => 4, 'their_score' => 6, 'result' => null],
        ],
        'finished' => [
            ['id' => $id['ann_draw'], 'status' => 'finished', 'metric' => 'reviews', 'days' => 7, 'role' => 'challenger', 'friend_name' => 'Ann', 'friendship_id' => $friend['Ann'], 'starts_on' => '2026-10-15', 'ends_on' => '2026-10-21', 'created_at' => '2026-10-15T08:00:00+00:00', 'my_score' => 5, 'their_score' => 5, 'result' => 'draw'],
            ['id' => $id['dan_lost'], 'status' => 'finished', 'metric' => 'new_words', 'days' => 7, 'role' => 'opponent', 'friend_name' => 'Dan', 'friendship_id' => null, 'starts_on' => '2026-10-08', 'ends_on' => '2026-10-14', 'created_at' => '2026-10-08T08:00:00+00:00', 'my_score' => 18, 'their_score' => 20, 'result' => 'lost'],
            ['id' => $id['cal_won'], 'status' => 'finished', 'metric' => 'reviews', 'days' => 7, 'role' => 'challenger', 'friend_name' => 'Cal', 'friendship_id' => $friend['Cal'], 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-07', 'created_at' => '2026-10-01T08:00:00+00:00', 'my_score' => 12, 'their_score' => 9, 'result' => 'won'],
        ],
    ]);
});

it('refuses a challenge for somebody else\'s friendship, a pending friendship and bad input', function (): void {
    $me = Learner::factory()->create();
    $foreign = Friendship::factory()->create();
    $pending = Friendship::factory()->pending()->between($me, Learner::factory()->create(), $me)->create();

    $this->withHeaders(tfHeaders($me))->postJson('/api/twa/challenges', ['friendship_id' => $foreign->id, 'metric' => 'reviews'])
        ->assertNotFound()->assertJsonPath('error.code', 'friendship_not_found');
    $this->withHeaders(tfHeaders($me))->postJson('/api/twa/challenges', ['friendship_id' => $pending->id, 'metric' => 'reviews'])
        ->assertForbidden()->assertJsonPath('error.code', 'not_friends');
    $this->withHeaders(tfHeaders($me))->postJson('/api/twa/challenges', ['friendship_id' => $pending->id, 'metric' => 'bogus'])->assertStatus(422);
    $this->withHeaders(tfHeaders($me))->postJson('/api/twa/challenges', ['metric' => 'reviews'])->assertStatus(422);
    expect(Challenge::query()->count())->toBe(0);
});

it('hides somebody else\'s challenge: 404 on accept and decline', function (): void {
    $me = Learner::factory()->create();
    $foreign = Challenge::factory()->create(['open_pair' => 'a:b']);

    $this->withHeaders(tfHeaders($me))->postJson("/api/twa/challenges/{$foreign->id}/accept")->assertNotFound()->assertJsonPath('error.code', 'challenge_not_found');
    $this->withHeaders(tfHeaders($me))->postJson("/api/twa/challenges/{$foreign->id}/decline")->assertNotFound();
    $this->withHeaders(tfHeaders($me))->getJson('/api/twa/challenges')->assertOk()->assertJsonCount(0, 'open');
});

it('cancels the open challenge when the friendship is removed through the API', function (): void {
    $me = Learner::factory()->create();
    $friend = Learner::factory()->create();
    $row = Friendship::factory()->between($me, $friend)->create();
    $id = $this->withHeaders(tfHeaders($me))->postJson('/api/twa/challenges', ['friendship_id' => $row->id, 'metric' => 'reviews'])->json('id');

    $this->withHeaders(tfHeaders($friend))->postJson("/api/twa/friends/{$row->id}/remove")->assertOk();

    expect(Challenge::query()->findOrFail($id)->status)->toBe('canceled');
    $this->withHeaders(tfHeaders($friend))->postJson("/api/twa/challenges/{$id}/accept")->assertStatus(409)->assertJsonPath('error.code', 'challenge_closed');
});

it('throttles the write endpoints per learner', function (): void {
    $me = Learner::factory()->create();

    for ($i = 0; $i < 30; $i++) {
        $this->withHeaders(tfHeaders($me))->postJson('/api/twa/friends/1/accept')->assertNotFound();
    }
    $this->withHeaders(tfHeaders($me))->postJson('/api/twa/friends/1/accept')->assertStatus(429);
});
