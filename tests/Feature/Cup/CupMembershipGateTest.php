<?php

declare(strict_types=1);

use App\Domain\Cup\Exceptions\CupException;
use App\Domain\Cup\Services\CupMembershipGate;
use App\Domain\Cup\Services\CupParticipationService;
use App\Domain\Cup\Services\CupRoundService;
use App\Domain\Cup\Services\CupScoreBook;
use App\Domain\Operations\Support\PureCache;
use App\Domain\Telegram\DTO\ChatMemberInfo;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramRateLimitedException;
use App\Models\Cup;
use App\Models\CupParticipant;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\Support\Cup\CupKit;

/*
 * docs/33 §4.1-§4.5 (K4): who may see, join and play a cup - a current member of the chat by a fresh getChatMember, for every
 * action of §4.2 and every answer of Telegram; the throttle of 10 s, the negative answer of 30 s, the 24 h rule when Telegram is down.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CupKit::open();
    CupKit::course();
});

afterEach(fn () => CarbonImmutable::setTestNow());

function gate(): CupMembershipGate
{
    return app(CupMembershipGate::class);
}

/** @return array<string, array{0: ChatMemberInfo|Throwable, 1: string|null, 2: int|null}> answer => [what Telegram says, refusal code (null = allowed), status] */
function telegramAnswers(): array
{
    return [
        'member' => [new ChatMemberInfo('member', true), null, null],
        'creator' => [new ChatMemberInfo('creator', true), null, null],
        'administrator' => [new ChatMemberInfo('administrator', true), null, null],
        'restricted, still in the chat' => [new ChatMemberInfo('restricted', true), null, null],
        'restricted, left the chat' => [new ChatMemberInfo('restricted', false), 'cup_not_member', 403],
        'left' => [new ChatMemberInfo('left', false), 'cup_not_member', 403],
        'kicked' => [new ChatMemberInfo('kicked', false), 'cup_not_member', 403],
        'user not found' => [TelegramApiException::apiError('Bad Request: user not found', 400), 'cup_not_member', 403],
        'bot removed (403)' => [TelegramApiException::apiError('Forbidden: bot is not a member of the supergroup chat', 403), 'cup_paused', 409],
        'network' => [new TelegramApiException('cURL error 28: Operation timed out'), 'cup_unverifiable', 503],
        'flood wait (429)' => [new TelegramRateLimitedException(5, 'getChatMember'), 'cup_unverifiable', 503],
    ];
}

it('decides joining by what Telegram says right now', function (string $case): void {
    [$answer, $code, $status] = telegramAnswers()[$case];
    $cup = CupKit::scheduled(CupKit::group(), CarbonImmutable::now());
    $learner = Learner::factory()->create();
    CupKit::telegram()->chatMembersByUser[(int) $learner->telegram_user_id] = $answer;

    if ($code === null) {
        expect(app(CupParticipationService::class)->join($cup, $learner)->created)->toBeTrue();
    } else {
        expect(fn () => app(CupParticipationService::class)->join($cup, $learner))->toThrow(fn (CupException $e) => expect([$e->errorCode, $e->httpStatus()])->toBe([$code, $status]));
        expect(CupParticipant::query()->count())->toBe(0);
    }
})->with(array_keys(telegramAnswers()));

it('decides the start of a round by what Telegram says right now, and makes a non-member left', function (string $case): void {
    [$answer, $code, $status] = telegramAnswers()[$case];
    ['cup' => $cup, 'learners' => [$a], 'start' => $now] = CupKit::running(2);
    Cache::flush();
    // the last successful check is older than 24 h: Telegram down does not let him in
    DB::table('cup_participants')->update(['membership_checked_at' => $now->subDays(2)]);
    CupKit::telegram()->chatMembersByUser[(int) $a->telegram_user_id] = $answer;

    if ($code === null) {
        expect(app(CupRoundService::class)->startToday($a, $cup, $now)->created)->toBeTrue();
        expect(CupParticipant::query()->where('learner_id', $a->id)->value('membership_checked_at'))->not->toBeNull();
    } else {
        expect(fn () => app(CupRoundService::class)->startToday($a, $cup, $now))->toThrow(fn (CupException $e) => expect([$e->errorCode, $e->httpStatus()])->toBe([$code, $status]));
        $left = $code === 'cup_not_member';
        expect(DB::table('cup_participants')->where('learner_id', $a->id)->value('status'))->toBe($left ? 'left' : 'active');
    }
})->with(array_keys(telegramAnswers()));

it('lets an active participant play when Telegram is down and his last check is within 24 hours', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $now] = CupKit::running(2);
    Cache::flush();
    DB::table('cup_participants')->update(['membership_checked_at' => $now->subHours(23)]);
    CupKit::memberFails($a, new TelegramApiException('cURL error 7: connection refused'));

    expect(app(CupRoundService::class)->startToday($a, $cup, $now)->created)->toBeTrue();
});

it('refuses with bot_not_admin when Telegram refuses and the bot is no administrator', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $now] = CupKit::running(2);
    Cache::flush();
    PureCache::store()->flush(); // the cached status of the bot
    CupKit::telegram()->chatMemberStatus = 'member'; // the bot was demoted
    CupKit::memberFails($a, TelegramApiException::apiError('Bad Request: member list is inaccessible', 400));

    expect(fn () => app(CupRoundService::class)->startToday($a, $cup, $now))
        ->toThrow(fn (CupException $e) => expect([$e->errorCode, $e->details['reason'], $e->httpStatus()])->toBe(['cup_unverifiable', 'bot_not_admin', 409]));
});

it('does not ask Telegram at all in a group whose bot was removed: the cup is paused', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $now] = CupKit::running(2);
    $cup->telegramGroup->forceFill(['bot_status' => 'kicked'])->save();
    CupKit::telegram()->chatMemberLookups = [];

    expect(fn () => app(CupRoundService::class)->startToday($a, $cup->fresh(['telegramGroup']), $now))
        ->toThrow(fn (CupException $e) => expect([$e->errorCode, $e->details['reason']])->toBe(['cup_paused', 'bot_removed']));
    expect(CupKit::telegram()->chatMemberLookups)->toBe([]);
});

it('lets a started attempt play on after the participant left the chat (no check on an answer)', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $now] = CupKit::running(2);
    $view = app(CupRoundService::class)->startToday($a, $cup, $now)->view;
    app(CupParticipationService::class)->onLeftChat($a, $cup->telegramGroup, $now);
    CupKit::member($a, 'left', false);

    $dayId = (int) DB::table('cup_round_attempts')->where('id', $view->attemptId)->value('cup_day_id');
    $outcome = app(CupRoundService::class)->answer($a, $view->attemptId, (string) Str::uuid(), (string) $view->current['id'], CupKit::rightAnswer($dayId, (string) $view->current['id']), $now->addSeconds(5));
    expect($outcome->body['points'])->toBe(2);
});

it('closes new rounds to a participant who left and gives him his old score back when he returns', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $now] = CupKit::running(2);
    $participant = CupParticipant::query()->where('learner_id', $a->id)->firstOrFail();
    app(CupScoreBook::class)->addRoundPoints($cup->id, $participant->id, 1, 14, 5, $now);

    expect(app(CupParticipationService::class)->onLeftChat($a, $cup->telegramGroup, $now))->toBe(1);
    expect($participant->fresh()?->status->value)->toBe('left');

    CupKit::member($a, 'left', false);
    $tomorrow = $now->addDay();
    expect(fn () => app(CupRoundService::class)->startToday($a, $cup, $tomorrow))->toThrow(fn (CupException $e) => expect($e->errorCode)->toBe('cup_not_member'));

    // back in the chat: the next fresh check makes him active, with the same row and score
    CupKit::member($a);
    Cache::flush();
    $start = app(CupRoundService::class)->startToday($a, $cup, $tomorrow->addMinute());
    $participant->refresh();
    expect($start->created)->toBeTrue()
        ->and($participant->status->value)->toBe('active')
        ->and($participant->left_at)->toBeNull()
        ->and($participant->best_total)->toBe(14)
        ->and(CupParticipant::query()->where('learner_id', $a->id)->count())->toBe(1);
});

it('gives nothing to a forwarded invitation: a stranger cannot see, join or play', function (): void {
    ['cup' => $cup, 'start' => $now] = CupKit::running(2);
    $stranger = Learner::factory()->create();
    CupKit::member($stranger, 'left', false);

    expect(fn () => gate()->assertCanView($cup, $stranger, null, false))->toThrow(fn (CupException $e) => expect([$e->errorCode, $e->httpStatus()])->toBe(['cup_not_member', 403]));
    expect(fn () => app(CupParticipationService::class)->join($cup, $stranger, 'Spy', $now))->toThrow(fn (CupException $e) => expect($e->errorCode)->toBe('cup_not_member'));
    expect(fn () => app(CupRoundService::class)->startToday($stranger, $cup, $now))->toThrow(fn (CupException $e) => expect($e->errorCode)->toBe('cup_not_member'));
    expect(CupParticipant::query()->where('learner_id', $stranger->id)->exists())->toBeFalse();
});

it('shows the table to a participant without asking Telegram, to a member after asking', function (): void {
    ['cup' => $cup, 'learners' => [$a]] = CupKit::running(2);
    $participant = CupParticipant::query()->where('learner_id', $a->id)->firstOrFail();
    $member = Learner::factory()->create();
    CupKit::member($member);
    Cache::flush();
    CupKit::telegram()->chatMemberLookups = [];

    gate()->assertCanView($cup, $a, $participant, true);
    expect(CupKit::telegram()->chatMemberLookups)->toBe([]);

    gate()->assertCanView($cup, $member, null, true);
    expect(CupKit::telegram()->chatMemberLookups)->toHaveCount(1);
});

it('answers every refusal of a lookup by id with the same 403, so an id cannot be probed', function (string $case): void {
    [$answer, $code] = telegramAnswers()[$case];
    if ($code === null) {
        expect(true)->toBeTrue();

        return;
    }
    ['cup' => $cup] = CupKit::running(2);
    Cache::flush();
    $stranger = Learner::factory()->create();
    CupKit::telegram()->chatMembersByUser[(int) $stranger->telegram_user_id] = $answer;

    expect(fn () => gate()->assertCanView($cup, $stranger, null, true))->toThrow(fn (CupException $e) => expect([$e->errorCode, $e->httpStatus(), $e->details])->toBe(['cup_not_member', 403, []]));
})->with(array_keys(telegramAnswers()));

it('remembers a "no" to a view for 30 seconds and asks Telegram again after it', function (): void {
    ['cup' => $cup] = CupKit::running(2);
    $stranger = Learner::factory()->create();
    CupKit::member($stranger, 'left', false);
    CupKit::telegram()->chatMemberLookups = [];

    foreach (range(1, 5) as $ignored) {
        expect(fn () => gate()->assertCanView($cup, $stranger, null, true))->toThrow(CupException::class);
    }
    expect(CupKit::telegram()->chatMemberLookups)->toHaveCount(1);

    Cache::forget(CupMembershipGate::negativeKey($cup->id, $stranger->id));
    Cache::forget(CupMembershipGate::key($cup->id, $stranger->id));
    CupKit::member($stranger);
    gate()->assertCanView($cup, $stranger, null, true);
    expect(CupKit::telegram()->chatMemberLookups)->toHaveCount(2);
});

it('asks Telegram at most once per 10 seconds for the same learner and cup, and never keeps an outage', function (): void {
    ['cup' => $cup, 'learners' => [$a]] = CupKit::running(2);
    Cache::flush();
    CupKit::telegram()->chatMemberLookups = [];

    gate()->check($cup, $a);
    gate()->check($cup, $a);
    expect(CupKit::telegram()->chatMemberLookups)->toHaveCount(1);
    $ttl = config('cache.default') === 'redis' ? Redis::connection('cache')->ttl(config('cache.prefix').CupMembershipGate::key($cup->id, $a->id)) : 10;
    expect($ttl)->toBeGreaterThan(0)->toBeLessThanOrEqual(10);

    Cache::forget(CupMembershipGate::key($cup->id, $a->id)); // the 10 s are over
    gate()->check($cup, $a);
    expect(CupKit::telegram()->chatMemberLookups)->toHaveCount(2);

    Cache::flush();
    CupKit::memberFails($a, new TelegramApiException('cURL error 28'));
    gate()->check($cup, $a);
    gate()->check($cup, $a);
    expect(CupKit::telegram()->chatMemberLookups)->toHaveCount(4);
});

it('reads a stored answer of the throttle written as a string by Redis', function (): void {
    ['cup' => $cup, 'learners' => [$a]] = CupKit::running(2);
    Cache::put(CupMembershipGate::key($cup->id, $a->id), 'not_member', 10);
    CupKit::telegram()->chatMemberLookups = [];

    expect(gate()->check($cup, $a)->value)->toBe('not_member')->and(CupKit::telegram()->chatMemberLookups)->toBe([]);
});

it('drops the reused answer when Telegram reports the learner left, so the next check really asks', function (): void {
    ['cup' => $cup, 'learners' => [$a]] = CupKit::running(2);
    gate()->check($cup, $a);
    expect(Cache::get(CupMembershipGate::key($cup->id, $a->id)))->toBe('member');

    app(CupParticipationService::class)->onLeftChat($a, $cup->telegramGroup);

    expect(Cache::get(CupMembershipGate::key($cup->id, $a->id)))->toBeNull();
});

it('refuses the join while another check of the same learner holds the lock too long', function (): void {
    ['cup' => $cup] = CupKit::running(2);
    config(['cup.membership.wait_seconds' => 1]);
    CarbonImmutable::setTestNow(); // the wait of a lock is measured by the clock: a frozen one never ends it
    $learner = Learner::factory()->create();
    $lock = Cache::lock("cup:member-check:{$cup->id}:{$learner->id}", 10);
    $lock->get();

    try {
        expect(fn () => gate()->check($cup, $learner))->toThrow(fn (CupException $e) => expect([$e->errorCode, $e->httpStatus()])->toBe(['cup_check_busy', 429]));
    } finally {
        $lock->release();
    }
});

it('accepts a view from a participant whatever his status, and only of his own cup', function (): void {
    ['cup' => $cup, 'learners' => [$a]] = CupKit::running(2);
    $other = CupKit::scheduled(CupKit::group(), CarbonImmutable::now());
    $participant = CupParticipant::query()->where('learner_id', $a->id)->firstOrFail();
    $participant->forceFill(['status' => 'left'])->save();
    Cache::flush();
    CupKit::member($a, 'left', false);

    gate()->assertCanView($cup, $a, $participant, true);
    expect(fn () => gate()->assertCanView(Cup::query()->with('telegramGroup')->findOrFail($other->id), $a, $participant, true))->toThrow(CupException::class);
});
