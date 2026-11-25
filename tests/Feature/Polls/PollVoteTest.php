<?php

declare(strict_types=1);

/*
 * docs/35 O4: the core of a tap on a poll button (`PollVoteService::tap`). One answer, the same option again (`repeated`, nothing
 * written), a change with and without `allow_change`, several answers within `max_choices`, "Отправить" empty and not, "Изменить
 * ответ", the poll closed / stopped / past `closes_at` before any command ran, a foreign `from.id`, an unknown or malformed token,
 * a message that never went out, a test delivery of a draft - and the statements each path costs (the budget of the fast path).
 * The race of two taps on two PostgreSQL connections is in PollConcurrencyTest.
 */

use App\Domain\Polls\Enums\PollDeliveryStatus;
use App\Domain\Polls\Enums\PollStatus;
use App\Domain\Polls\Enums\PollTapStatus;
use App\Domain\Polls\Services\PollVoteService;
use App\Models\PollDelivery;
use App\Models\PollOption;
use App\Models\PollVote;
use App\Models\ProductEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Polls\PollFixtures;

uses(RefreshDatabase::class);

function pollTap(PollDelivery $delivery, string $action, ?int $fromId = null, ?CarbonImmutable $now = null): \App\Domain\Polls\DTO\PollTapResult
{
    return app(PollVoteService::class)->tap($delivery->token, $fromId ?? $delivery->telegram_user_id, $action, $now ?? CarbonImmutable::now());
}

/** @return list<int> */
function pollVotesOf(PollDelivery $delivery): array
{
    return PollVote::query()->where('poll_delivery_id', $delivery->id)->orderBy('poll_option_id')->pluck('poll_option_id')->map(fn ($id): int => (int) $id)->all();
}

function pollOptionId(PollDelivery $delivery, int $position): int
{
    return (int) PollOption::query()->where('poll_id', $delivery->poll_id)->where('position', $position)->value('id');
}

/** Statements run by `$fn` (BEGIN/COMMIT are not statements). */
function pollStatements(callable $fn): int
{
    $n = 0;
    DB::listen(function () use (&$n): void {
        $n++;
    });
    $fn();

    return $n;
}

it('records one answer, then answers the same option as repeated without writing anything', function (): void {
    $delivery = PollFixtures::delivered(['allow_change' => false]);
    $now = CarbonImmutable::parse('2026-11-28 10:00:00');

    $first = pollTap($delivery, '1', now: $now);
    expect($first->status)->toBe(PollTapStatus::Voted)
        ->and($first->changed)->toBeFalse()
        ->and($first->selectedOptionIds)->toBe([pollOptionId($delivery, 1)])
        ->and($first->pollId)->toBe($delivery->poll_id)
        ->and($first->deliveryId)->toBe($delivery->id)
        ->and($first->locale)->toBe('ru')
        ->and($first->allowChange)->toBeFalse();

    $fresh = $delivery->fresh();
    expect($fresh->answered_at?->toDateTimeString())->toBe('2026-11-28 10:00:00')
        ->and($fresh->changed_count)->toBe(0)
        ->and(pollVotesOf($delivery))->toBe([pollOptionId($delivery, 1)])
        ->and((int) PollVote::query()->value('poll_id'))->toBe($delivery->poll_id);

    $statements = pollStatements(function () use ($delivery, &$again): void {
        $again = pollTap($delivery, '1', now: CarbonImmutable::parse('2026-11-28 11:00:00'));
    });

    expect($again->status)->toBe(PollTapStatus::Repeated)
        ->and($again->selectedOptionIds)->toBe([pollOptionId($delivery, 1)])
        ->and($statements)->toBe(2)
        ->and($delivery->fresh()->answered_at?->toDateTimeString())->toBe('2026-11-28 10:00:00');
});

it('refuses another option when the poll does not allow a change, and changes it when it does', function (): void {
    $locked = PollFixtures::delivered(['allow_change' => false]);
    pollTap($locked, '0');
    $verdict = pollTap($locked, '2');
    expect($verdict->status)->toBe(PollTapStatus::Locked)
        ->and($verdict->selectedOptionIds)->toBe([pollOptionId($locked, 0)])
        ->and(pollVotesOf($locked))->toBe([pollOptionId($locked, 0)])
        ->and($locked->fresh()->changed_count)->toBe(0);
    expect(pollTap($locked, 'c')->status)->toBe(PollTapStatus::Locked);

    $open = PollFixtures::delivered(['allow_change' => true]);
    pollTap($open, '0', now: CarbonImmutable::parse('2026-11-28 10:00:00'));
    $changed = pollTap($open, '2', now: CarbonImmutable::parse('2026-11-28 10:05:00'));
    expect($changed->status)->toBe(PollTapStatus::Voted)
        ->and($changed->changed)->toBeTrue()
        ->and(pollVotesOf($open))->toBe([pollOptionId($open, 2)])
        ->and($open->fresh()->changed_count)->toBe(1)
        ->and($open->fresh()->answered_at?->toDateTimeString())->toBe('2026-11-28 10:00:00')
        ->and((string) PollVote::query()->where('poll_delivery_id', $open->id)->toBase()->value('created_at'))->toStartWith('2026-11-28 10:05:00');

    expect(pollTap($open, 'c')->status)->toBe(PollTapStatus::Changing)
        ->and(pollTap($open, 'c')->selectedOptionIds)->toBe([pollOptionId($open, 2)]);
    expect(pollTap($open, '0')->changed)->toBeTrue()
        ->and($open->fresh()->changed_count)->toBe(2);
});

it('toggles several answers in the draft within max_choices and records them on submit', function (): void {
    $delivery = PollFixtures::delivered(['allows_multiple' => true, 'max_choices' => 2, 'allow_change' => true], options: 4);
    [$a, $b, $c] = [pollOptionId($delivery, 0), pollOptionId($delivery, 1), pollOptionId($delivery, 2)];

    $empty = pollTap($delivery, 's');
    expect($empty->status)->toBe(PollTapStatus::EmptySubmit)->and(PollVote::query()->count())->toBe(0);

    expect(pollTap($delivery, '0')->status)->toBe(PollTapStatus::Toggled);
    $two = pollTap($delivery, '2');
    expect($two->status)->toBe(PollTapStatus::Toggled)->and($two->selectedOptionIds)->toBe([$a, $c]);

    $limit = pollTap($delivery, '1');
    expect($limit->status)->toBe(PollTapStatus::LimitReached)->and($limit->selectedOptionIds)->toBe([$a, $c])
        ->and($delivery->fresh()->draft)->toBe([$a, $c]);

    $off = pollTap($delivery, '0');
    expect($off->selectedOptionIds)->toBe([$c]);
    expect(pollTap($delivery, '1')->selectedOptionIds)->toBe([$b, $c])
        ->and(PollVote::query()->count())->toBe(0)
        ->and($delivery->fresh()->answered_at)->toBeNull();

    $voted = pollTap($delivery, 's');
    expect($voted->status)->toBe(PollTapStatus::Voted)->and($voted->changed)->toBeFalse()
        ->and($voted->selectedOptionIds)->toBe([$b, $c])
        ->and(pollVotesOf($delivery))->toBe([$b, $c])
        ->and($delivery->fresh()->draft)->toBeNull();

    // The same answer again: repeated, nothing written.
    expect(pollTap($delivery, 's')->status)->toBe(PollTapStatus::Repeated);

    // "Изменить ответ": the recorded answer becomes the draft; a change of it is recorded with changed_count + 1.
    $changing = pollTap($delivery, 'c');
    expect($changing->status)->toBe(PollTapStatus::Changing)->and($changing->selectedOptionIds)->toBe([$b, $c])
        ->and($delivery->fresh()->draft)->toBe([$b, $c]);
    pollTap($delivery, '1');
    expect(pollTap($delivery, '0')->selectedOptionIds)->toBe([$a, $c]);
    $again = pollTap($delivery, 's');
    expect($again->status)->toBe(PollTapStatus::Voted)->and($again->changed)->toBeTrue()
        ->and(pollVotesOf($delivery))->toBe([$a, $c])
        ->and($delivery->fresh()->changed_count)->toBe(1);

    // A draft equal to the answer: repeated, the draft cleared.
    pollTap($delivery, 'c');
    expect(pollTap($delivery, 's')->status)->toBe(PollTapStatus::Repeated)
        ->and($delivery->fresh()->draft)->toBeNull()
        ->and($delivery->fresh()->changed_count)->toBe(1);
});

it('takes any number of answers without max_choices and locks several answers without allow_change', function (): void {
    $any = PollFixtures::delivered(['allows_multiple' => true, 'max_choices' => null, 'allow_change' => false], options: 3);
    foreach (['0', '1', '2'] as $n) {
        expect(pollTap($any, $n)->status)->toBe(PollTapStatus::Toggled);
    }
    expect(pollTap($any, 's')->status)->toBe(PollTapStatus::Voted)
        ->and(count(pollVotesOf($any)))->toBe(3);

    expect(pollTap($any, '0')->status)->toBe(PollTapStatus::Locked)
        ->and(pollTap($any, 'c')->status)->toBe(PollTapStatus::Locked)
        ->and(pollTap($any, 's')->status)->toBe(PollTapStatus::Repeated)
        ->and(count(pollVotesOf($any)))->toBe(3);
});

it('answers closed for a closed or stopped poll and at closes_at, before any closing command ran', function (string $case): void {
    $now = CarbonImmutable::parse('2026-11-28 12:00:00');
    $delivery = match ($case) {
        'closed' => PollFixtures::delivered(['status' => PollStatus::Closed, 'closed_at' => $now->subMinute()]),
        'cancelled' => PollFixtures::delivered(['status' => PollStatus::Cancelled]),
        'closes_at' => PollFixtures::delivered(['closes_at' => $now]),
        'draft' => PollFixtures::delivered(['status' => PollStatus::Draft]),
    };

    $verdict = pollTap($delivery, '0', now: $now);

    expect($verdict->status)->toBe(PollTapStatus::Closed)
        ->and(PollVote::query()->count())->toBe(0)
        ->and($delivery->fresh()->answered_at)->toBeNull();
})->with(['closed', 'cancelled', 'closes_at', 'draft']);

it('takes the tap one second before closes_at', function (): void {
    $now = CarbonImmutable::parse('2026-11-28 12:00:00');
    $delivery = PollFixtures::delivered(['closes_at' => $now->addSecond()]);

    expect(pollTap($delivery, '0', now: $now)->status)->toBe(PollTapStatus::Voted);
});

it('lets the administrator vote on a test delivery of a draft, which never counts as a learner', function (): void {
    $poll = PollFixtures::poll(['status' => PollStatus::Draft]);
    $test = PollDelivery::factory()->test(555000111)->sent()->create(['poll_id' => $poll->id]);

    $verdict = pollTap($test, '1');

    expect($verdict->status)->toBe(PollTapStatus::Voted)->and($verdict->isTest)->toBeTrue()
        ->and(pollVotesOf($test))->toHaveCount(1);
});

it('gives the same unavailable verdict to a foreign tap, an unknown or malformed token, a missing option and an unsent message', function (): void {
    $delivery = PollFixtures::delivered([], [], 3);
    $service = app(PollVoteService::class);
    $now = CarbonImmutable::now();

    $cases = [
        'foreign from.id' => fn () => $service->tap($delivery->token, $delivery->telegram_user_id + 1, '0', $now),
        'unknown token' => fn () => $service->tap('ZZZZZZZZZZZZ', $delivery->telegram_user_id, '0', $now),
        'short token' => fn () => $service->tap(substr($delivery->token, 0, 11), $delivery->telegram_user_id, '0', $now),
        'sql in token' => fn () => $service->tap("abc' OR '1'='1", $delivery->telegram_user_id, '0', $now),
        'unicode token' => fn () => $service->tap('ааааааааааaa', $delivery->telegram_user_id, '0', $now),
        'zero from.id' => fn () => $service->tap($delivery->token, 0, '0', $now),
        'option out of range' => fn () => $service->tap($delivery->token, $delivery->telegram_user_id, '7', $now),
        'two digits' => fn () => $service->tap($delivery->token, $delivery->telegram_user_id, '10', $now),
        'unknown action' => fn () => $service->tap($delivery->token, $delivery->telegram_user_id, 'x', $now),
        'submit of one answer' => fn () => $service->tap($delivery->token, $delivery->telegram_user_id, 's', $now),
    ];

    foreach ($cases as $name => $tap) {
        $verdict = $tap();
        expect($verdict->status)->toBe(PollTapStatus::Unavailable, $name)
            ->and($verdict->pollId)->toBeNull($name)
            ->and($verdict->selectedOptionIds)->toBe([], $name);
    }

    expect(PollVote::query()->count())->toBe(0);

    foreach ([PollDeliveryStatus::Pending, PollDeliveryStatus::Skipped, PollDeliveryStatus::Forbidden, PollDeliveryStatus::Failed] as $status) {
        $delivery->update(['status' => $status]);
        expect(pollTap($delivery, '0')->status)->toBe(PollTapStatus::Unavailable, $status->value);
    }

    // A message that may have gone out (a crash, a timeout) can be answered.
    foreach ([PollDeliveryStatus::Unknown, PollDeliveryStatus::Sending] as $status) {
        $delivery->update(['status' => $status]);
        expect(pollTap($delivery, '0')->status)->not->toBe(PollTapStatus::Unavailable, $status->value);
    }
});

it('runs a malformed tap without a single statement', function (): void {
    $delivery = PollFixtures::delivered();

    expect(pollStatements(fn () => app(PollVoteService::class)->tap('short', $delivery->telegram_user_id, '0', CarbonImmutable::now())))->toBe(0)
        ->and(pollStatements(fn () => app(PollVoteService::class)->tap($delivery->token, $delivery->telegram_user_id, 'zz', CarbonImmutable::now())))->toBe(0);
});

it('keeps the fast path within its statement budget', function (): void {
    $single = PollFixtures::delivered(['allow_change' => true]);
    $multi = PollFixtures::delivered(['allows_multiple' => true, 'allow_change' => true], options: 4);
    $closed = PollFixtures::delivered(['status' => PollStatus::Closed]);

    expect(pollStatements(fn () => pollTap($single, '0')))->toBe(3)       // SELECT … FOR UPDATE, INSERT vote, UPDATE delivery
        ->and(pollStatements(fn () => pollTap($single, '0')))->toBe(2)    // repeated: + the votes, read after the lock
        ->and(pollStatements(fn () => pollTap($single, '1')))->toBe(5)    // change: SELECT, votes, DELETE, INSERT, UPDATE
        ->and(pollStatements(fn () => pollTap($multi, '0')))->toBe(2)     // toggle: SELECT, UPDATE draft
        ->and(pollStatements(fn () => pollTap($multi, '1')))->toBe(2)
        ->and(pollStatements(fn () => pollTap($multi, 's')))->toBe(3)     // first submit: SELECT, INSERT (both), UPDATE
        ->and(pollStatements(fn () => pollTap($closed, '0')))->toBe(1)
        ->and(pollStatements(fn () => pollTap($single, 'c')))->toBe(2)
        ->and(pollStatements(fn () => app(PollVoteService::class)->tap($single->token, 1, '0', CarbonImmutable::now())))->toBe(1);
});

it('writes nothing to the learning statistics, the points or the events', function (): void {
    $delivery = PollFixtures::delivered();
    $learner = $delivery->learner;
    $before = $learner?->only(['streak_current', 'streak_last_date', 'last_seen_at']);

    pollTap($delivery, '0');

    expect(DB::table('learner_daily_stats')->count())->toBe(0)
        ->and(ProductEvent::query()->count())->toBe(0)
        ->and($learner?->fresh()?->only(['streak_current', 'streak_last_date', 'last_seen_at']))->toBe($before);
});

it('keeps changed_count within smallint', function (): void {
    $delivery = PollFixtures::delivered(['allow_change' => true]);
    pollTap($delivery, '0');
    DB::table('poll_deliveries')->where('id', $delivery->id)->update(['changed_count' => 32767]);

    expect(pollTap($delivery, '1')->status)->toBe(PollTapStatus::Voted)
        ->and($delivery->fresh()->changed_count)->toBe(32767);
});
