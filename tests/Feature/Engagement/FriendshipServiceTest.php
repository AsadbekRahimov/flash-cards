<?php

declare(strict_types=1);

use App\Domain\Engagement\Exceptions\FriendshipException;
use App\Domain\Engagement\Services\FriendshipService;
use App\Jobs\SendFriendDmJob;
use App\Models\Challenge;
use App\Models\Friendship;
use App\Models\Learner;
use App\Models\ProductEvent;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

/*
 * FriendshipService (docs/13 §1.5, §2.4, §7.5): the normalised pair, idempotent requests, the friend limit,
 * accept / decline / remove / block, the friends overview and what a friend may see.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    config(['friends.max' => 100, 'telegram.bot_username' => 'LexiFlowBot']);
});

function friendsSvc(): FriendshipService
{
    return app(FriendshipService::class);
}

function friendEvents(string $name): int
{
    return ProductEvent::query()->where('name', $name)->count();
}

it('keeps ONE row per pair: A asks B and B asks A are the same normalised row', function (): void {
    [$a, $b] = [Learner::factory()->create(), Learner::factory()->create()];

    $first = friendsSvc()->request($b, $a);

    expect($first->learner_id)->toBe(min($a->id, $b->id))
        ->and($first->friend_id)->toBe(max($a->id, $b->id))
        ->and($first->requested_by_learner_id)->toBe($b->id)
        ->and($first->status)->toBe('pending');

    // The repeat is the same row (idempotent), no second event.
    $again = friendsSvc()->request($b, $a);
    expect($again->id)->toBe($first->id)
        ->and(Friendship::query()->count())->toBe(1)
        ->and(friendEvents('friend.requested'))->toBe(1);
});

it('accepts when the two requests meet (A asks B, then B asks A)', function (): void {
    [$a, $b] = [Learner::factory()->create(), Learner::factory()->create()];

    friendsSvc()->request($a, $b);
    $met = friendsSvc()->request($b, $a);

    expect($met->status)->toBe('accepted')
        ->and($met->accepted_at)->not->toBeNull()
        ->and(Friendship::query()->count())->toBe(1);
    Queue::assertPushed(SendFriendDmJob::class, 1);
});

it('refuses to befriend yourself and a blocked learner', function (): void {
    $a = Learner::factory()->create();
    $blocked = Learner::factory()->create(['blocked_at' => now()]);

    expect(fn () => friendsSvc()->request($a, $a))->toThrow(FriendshipException::class)
        ->and(fn () => friendsSvc()->request($a, $blocked))->toThrow(FriendshipException::class);

    try {
        friendsSvc()->request($a, $a);
    } catch (FriendshipException $e) {
        expect($e->errorCode)->toBe('friendship_self')->and($e->httpStatus())->toBe(422);
    }
    expect(Friendship::query()->count())->toBe(0);
});

it('lets only the asked side accept, once, and raises one event and one DM', function (): void {
    [$a, $b] = [Learner::factory()->create(), Learner::factory()->create()];
    $row = friendsSvc()->request($a, $b);

    // The asker cannot answer their own request; a stranger sees nothing.
    try {
        friendsSvc()->accept($a, $row->id);
        $this->fail('expected not_recipient');
    } catch (FriendshipException $e) {
        expect($e->errorCode)->toBe('not_recipient')->and($e->httpStatus())->toBe(403);
    }
    $stranger = Learner::factory()->create();
    try {
        friendsSvc()->accept($stranger, $row->id);
        $this->fail('expected not found');
    } catch (FriendshipException $e) {
        expect($e->errorCode)->toBe('friendship_not_found')->and($e->httpStatus())->toBe(404);
    }

    $accepted = friendsSvc()->accept($b, $row->id);
    $repeat = friendsSvc()->accept($b, $row->id);

    expect($accepted->status)->toBe('accepted')
        ->and($repeat->status)->toBe('accepted')
        ->and(friendEvents('friend.accepted'))->toBe(1)
        ->and(friendsSvc()->areFriends($a, $b))->toBeTrue();
    Queue::assertPushed(SendFriendDmJob::class, 1);
});

it('deletes a declined request, so the pair may ask again', function (): void {
    [$a, $b] = [Learner::factory()->create(), Learner::factory()->create()];
    $row = friendsSvc()->request($a, $b);

    friendsSvc()->decline($b, $row->id);

    expect(Friendship::query()->count())->toBe(0)
        ->and(friendEvents('friend.removed'))->toBe(1);
    expect(fn () => friendsSvc()->decline($b, $row->id))->toThrow(FriendshipException::class);
    expect(friendsSvc()->request($a, $b)->status)->toBe('pending');
});

it('stops at the friend limit for the asker, and for either side at accept', function (): void {
    config(['friends.max' => 2]);
    $a = Learner::factory()->create();
    Friendship::factory()->between($a, Learner::factory()->create())->create();
    Friendship::factory()->between($a, Learner::factory()->create())->create();

    try {
        friendsSvc()->request($a, Learner::factory()->create());
        $this->fail('expected the limit');
    } catch (FriendshipException $e) {
        expect($e->errorCode)->toBe('friend_limit')->and($e->httpStatus())->toBe(409);
    }

    // The limit counts the pending requests one SENT, too.
    $b = Learner::factory()->create();
    $c = Learner::factory()->create();
    friendsSvc()->request($b, Learner::factory()->create());
    friendsSvc()->request($b, $c);
    expect(fn () => friendsSvc()->request($b, Learner::factory()->create()))->toThrow(FriendshipException::class);

    // A request to somebody who is full is accepted-or-refused at accept time.
    $asker = Learner::factory()->create();
    $row = friendsSvc()->request($asker, $a);
    try {
        friendsSvc()->accept($a, $row->id);
        $this->fail('expected the limit');
    } catch (FriendshipException $e) {
        expect($e->errorCode)->toBe('friend_limit');
    }

    $full = Learner::factory()->create();
    Friendship::factory()->between($full, Learner::factory()->create())->create();
    Friendship::factory()->between($full, Learner::factory()->create())->create();
    $fresh = Learner::factory()->create();
    $pending = Friendship::factory()->pending()->between($full, $fresh, $full)->create();
    try {
        friendsSvc()->accept($fresh, $pending->id);
        $this->fail('expected the other side limit');
    } catch (FriendshipException $e) {
        expect($e->errorCode)->toBe('friend_limit_other');
    }
});

it('removes a friendship from either side and cancels the open challenges of the pair', function (): void {
    [$a, $b] = [Learner::factory()->create(), Learner::factory()->create()];
    $row = Friendship::factory()->between($a, $b)->create();
    $open = Challenge::factory()->active()->create(['challenger_id' => $a->id, 'opponent_id' => $b->id, 'open_pair' => min($a->id, $b->id).':'.max($a->id, $b->id)]);
    $done = Challenge::factory()->create(['challenger_id' => $a->id, 'opponent_id' => $b->id, 'status' => 'finished']);
    $elsewhere = Challenge::factory()->create(['challenger_id' => $a->id, 'opponent_id' => Learner::factory()->create()->id, 'open_pair' => 'x:y']);

    friendsSvc()->remove($b, $row->id);

    expect(Friendship::query()->count())->toBe(0)
        ->and($open->fresh()?->status)->toBe('canceled')
        ->and($open->fresh()?->open_pair)->toBeNull()
        ->and($done->fresh()?->status)->toBe('finished')
        ->and($elsewhere->fresh()?->status)->toBe('pending')
        ->and(friendEvents('friend.removed'))->toBe(1);

    // A repeat finds nothing: not found, no second event.
    expect(fn () => friendsSvc()->remove($b, $row->id))->toThrow(FriendshipException::class);
    expect(friendEvents('friend.removed'))->toBe(1);
});

it('lets the asker withdraw a request, but not the asked side "remove" it', function (): void {
    [$a, $b] = [Learner::factory()->create(), Learner::factory()->create()];
    $row = friendsSvc()->request($a, $b);

    expect(fn () => friendsSvc()->remove($b, $row->id))->toThrow(FriendshipException::class);
    expect(Friendship::query()->count())->toBe(1);

    friendsSvc()->remove($a, $row->id);
    expect(Friendship::query()->count())->toBe(0);
});

it('blocks: the blocker keeps the row, the blocked side cannot ask again and is not told', function (): void {
    [$a, $b] = [Learner::factory()->create(), Learner::factory()->create()];
    $row = Friendship::factory()->between($a, $b)->create();
    $challenge = Challenge::factory()->create(['challenger_id' => $a->id, 'opponent_id' => $b->id, 'open_pair' => 'k']);

    $blocked = friendsSvc()->block($a, $b);

    expect($blocked->id)->toBe($row->id)
        ->and($blocked->status)->toBe('blocked')
        ->and($blocked->requested_by_learner_id)->toBe($a->id)
        ->and($challenge->fresh()?->status)->toBe('canceled');

    // Idempotent; the blocked side gets the row back without an error and without a change.
    expect(friendsSvc()->block($a, $b)->id)->toBe($row->id);
    expect(friendsSvc()->request($b, $a)->status)->toBe('blocked');
    expect(Friendship::query()->count())->toBe(1);
    // The blocker asking is told to unblock first.
    expect(fn () => friendsSvc()->request($a, $b))->toThrow(FriendshipException::class);
    // The blocked side cannot see it, remove it or accept it.
    expect(fn () => friendsSvc()->remove($b, $row->id))->toThrow(FriendshipException::class);
    expect(friendsSvc()->areFriends($a, $b))->toBeFalse();

    // Unblock = remove by the blocker.
    friendsSvc()->remove($a, $row->id);
    expect(Friendship::query()->count())->toBe(0);
});

it('lists friends with the visible streak and learned words, and the requests in both directions', function (): void {
    $me = Learner::factory()->create();
    $now = CarbonImmutable::parse('2026-10-22 10:00:00', 'UTC');

    $live = Learner::factory()->create(['first_name' => 'Live', 'streak_current' => 5, 'streak_last_date' => '2026-10-22']);
    $yesterday = Learner::factory()->create(['first_name' => 'Yday', 'streak_current' => 3, 'streak_last_date' => '2026-10-21']);
    $lapsed = Learner::factory()->create(['first_name' => 'Lapsed', 'streak_current' => 9, 'streak_last_date' => '2026-10-19']);
    foreach ([$live, $yesterday, $lapsed] as $friend) {
        Friendship::factory()->between($me, $friend)->create();
    }
    WordRepetition::factory()->count(2)->create(['learner_id' => $live->id, 'repetitions' => 2]);
    WordRepetition::factory()->create(['learner_id' => $live->id, 'repetitions' => 0]);

    $in = Learner::factory()->create(['first_name' => 'Incoming']);
    $out = Learner::factory()->create(['first_name' => 'Outgoing']);
    friendsSvc()->request($in, $me);
    friendsSvc()->request($me, $out);
    Friendship::factory()->between($me, Learner::factory()->create())->blocked()->create();
    Friendship::factory()->create(); // somebody else's pair

    $overview = friendsSvc()->overview($me, $now);

    $byName = [];
    foreach ($overview->friends as $entry) {
        $byName[(string) $entry->firstName] = $entry;
    }
    expect($overview->friends)->toHaveCount(3)
        ->and($byName['Live']->streak)->toBe(5)
        ->and($byName['Live']->wordsLearned)->toBe(2)
        ->and($byName['Yday']->streak)->toBe(3)
        ->and($byName['Yday']->wordsLearned)->toBe(0)
        ->and($byName['Lapsed']->streak)->toBe(0)
        ->and($overview->incoming)->toHaveCount(1)
        ->and($overview->incoming[0]->firstName)->toBe('Incoming')
        ->and($overview->outgoing)->toHaveCount(1)
        ->and($overview->outgoing[0]->firstName)->toBe('Outgoing')
        ->and($overview->limit)->toBe(100);
});

it('builds the invite link from the learner code, null without the bot username', function (): void {
    $me = Learner::factory()->create(['referral_code' => 'ABCDEFGH']);

    expect(friendsSvc()->inviteLink($me))->toBe('https://t.me/LexiFlowBot?start=f_ABCDEFGH');

    config(['telegram.bot_username' => '']);
    expect(friendsSvc()->inviteLink($me))->toBeNull();
});
