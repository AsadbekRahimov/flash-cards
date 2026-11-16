<?php

declare(strict_types=1);

/*
 * The administrator's review of a month of the rating (docs/13 §7.6): confirmation with the places, disqualification
 * (the next candidate moves up) and its reversal, "prize delivered", the prizes of a month — every decision under the
 * state it needs, with an audit row and an event; the policy that guards the page.
 */

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Engagement\DTO\MonthCandidateReview;
use App\Domain\Engagement\Events\LeagueMonthConfirmed;
use App\Domain\Engagement\Exceptions\LeagueMonthException;
use App\Domain\Engagement\Services\MonthlyRatingReviewService;
use App\Domain\Engagement\Services\MonthlyRatingService;
use App\Models\AuditLog;
use App\Models\LeagueMonth;
use App\Models\LeagueMonthCandidate;
use App\Models\Learner;
use App\Models\ProductEvent;
use App\Models\User;
use App\Policies\LeagueMonthPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function mrrService(): MonthlyRatingReviewService
{
    return app(MonthlyRatingReviewService::class);
}

function mrrAdmin(): User
{
    Role::findOrCreate('admin', 'web');
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('admin');

    return $user;
}

/**
 * A pending October with five candidates ranked 1..5 (points 500, 400, …).
 *
 * @return array{month: LeagueMonth, candidates: list<LeagueMonthCandidate>}
 */
function mrrPendingMonth(int $count = 5): array
{
    $month = LeagueMonth::factory()->startingOn('2026-10-01')->pendingReview()->create();
    $candidates = [];
    foreach (range(1, $count) as $rank) {
        $candidates[] = LeagueMonthCandidate::factory()->create([
            'league_month_id' => $month->id,
            'learner_id' => Learner::factory()->create(['first_name' => "L{$rank}"])->id,
            'rank' => $rank,
            'points' => 600 - $rank * 100,
        ]);
    }

    return ['month' => $month, 'candidates' => $candidates];
}

/** @return list<string> `rank:status:place` of the review, by rank */
function mrrPlaces(LeagueMonth $month): array
{
    return array_map(
        fn (MonthCandidateReview $c): string => "{$c->candidate->rank}:{$c->candidate->status}:".($c->provisionalPlace ?? '-'),
        mrrService()->review($month)->candidates,
    );
}

beforeEach(function (): void {
    config([
        'leagues.timezone' => 'Asia/Tashkent',
        'leagues.monthly.places' => 3,
        'leagues.monthly.prizes' => [
            1 => ['ru' => 'Telegram Premium на 3 месяца', 'uz' => 'Telegram Premium 3 oyga'],
            2 => ['ru' => 'Telegram Premium на 1 месяц', 'uz' => 'Telegram Premium 1 oyga'],
            3 => ['ru' => 'Telegram Premium на 1 месяц', 'uz' => 'Telegram Premium 1 oyga'],
        ],
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-11-01 05:00:00', 'UTC'));
});

it('confirms a pending month: the places go to the candidates not disqualified by rank, once, with an audit row and the events', function (): void {
    Event::fake([LeagueMonthConfirmed::class]);
    ['month' => $month, 'candidates' => $c] = mrrPendingMonth();
    $admin = mrrAdmin();
    mrrService()->disqualify($month, $c[1]->id, 'script: 2 000 cards in an hour', $admin, '10.0.0.1');

    expect(mrrPlaces($month))->toBe(['1:candidate:1', '2:disqualified:-', '3:candidate:2', '4:candidate:3', '5:candidate:-']);

    $confirmed = mrrService()->confirm($month, $admin, '10.0.0.1');

    expect($confirmed->status)->toBe('confirmed')
        ->and($confirmed->confirmed_by_user_id)->toBe($admin->id)
        ->and($confirmed->confirmed_at)->not->toBeNull()
        ->and(mrrPlaces($month))->toBe(['1:winner:1', '2:disqualified:-', '3:winner:2', '4:winner:3', '5:candidate:-'])
        ->and(AuditLog::query()->where('action', MonthlyRatingReviewService::ACTION_CONFIRMED)->where('entity_id', $month->id)->where('user_id', $admin->id)->where('ip', '10.0.0.1')->count())->toBe(1)
        ->and(ProductEvent::query()->where('name', ProductEventName::LeagueMonthConfirmed->value)->where('user_id', $admin->id)->count())->toBe(1);

    Event::assertDispatchedTimes(LeagueMonthConfirmed::class, 1);
    Event::assertDispatched(LeagueMonthConfirmed::class, fn (LeagueMonthConfirmed $e): bool => $e->monthId === $month->id
        && $e->startsOn === '2026-10-01'
        && $e->winners === [
            ['learner_id' => $c[0]->learner_id, 'place' => 1, 'points' => 500],
            ['learner_id' => $c[2]->learner_id, 'place' => 2, 'points' => 300],
            ['learner_id' => $c[3]->learner_id, 'place' => 3, 'points' => 200],
        ]);

    // Final forever: no second confirmation, no disqualification, no reinstatement, no change of prizes.
    expect(fn () => mrrService()->confirm($month, $admin))->toThrow(LeagueMonthException::class, 'not waiting for a review')
        ->and(fn () => mrrService()->disqualify($month, $c[0]->id, 'late doubt', $admin))->toThrow(LeagueMonthException::class)
        ->and(fn () => mrrService()->reinstate($month, $c[1]->id, $admin))->toThrow(LeagueMonthException::class)
        ->and(fn () => mrrService()->setPrizes($month, [], $admin))->toThrow(LeagueMonthException::class, 'cannot be changed');
    try {
        mrrService()->setPrizes($month, [], $admin);
    } catch (LeagueMonthException $e) {
        expect($e->errorCode)->toBe(LeagueMonthException::PRIZES_LOCKED);
    }
    Event::assertDispatchedTimes(LeagueMonthConfirmed::class, 1);
});

it('confirms a month without candidates (nobody was eligible) and a month with fewer candidates than places', function (): void {
    Event::fake([LeagueMonthConfirmed::class]);
    $admin = mrrAdmin();
    $empty = LeagueMonth::factory()->startingOn('2026-09-01')->pendingReview()->create();
    expect(mrrService()->confirm($empty, $admin)->status)->toBe('confirmed');
    Event::assertDispatched(LeagueMonthConfirmed::class, fn (LeagueMonthConfirmed $e): bool => $e->monthId === $empty->id && $e->winners === []);

    ['month' => $month] = mrrPendingMonth(2);
    mrrService()->confirm($month, $admin);
    expect(mrrPlaces($month))->toBe(['1:winner:1', '2:winner:2']);
});

it('refuses to confirm, disqualify or reinstate while the month is open or closing', function (): void {
    $admin = mrrAdmin();
    foreach (['open' => '2026-10-01', 'closing' => '2026-09-01'] as $status => $firstDay) {
        $month = LeagueMonth::factory()->startingOn($firstDay)->create(['status' => $status]);
        $candidate = LeagueMonthCandidate::factory()->create(['league_month_id' => $month->id]);
        foreach ([
            fn () => mrrService()->confirm($month, $admin),
            fn () => mrrService()->disqualify($month, $candidate->id, 'x', $admin),
            fn () => mrrService()->reinstate($month, $candidate->id, $admin),
            fn () => mrrService()->markPrizeDelivered($month, $candidate->id, $admin),
        ] as $action) {
            try {
                $action();
                $this->fail("Allowed in {$status}");
            } catch (LeagueMonthException $e) {
                expect($e->errorCode)->toBeIn([LeagueMonthException::MONTH_NOT_PENDING, LeagueMonthException::MONTH_NOT_CONFIRMED]);
            }
        }
        expect($month->fresh()?->status)->toBe($status)->and(AuditLog::query()->count())->toBe(0);
    }
});

it('disqualifies with a reason and takes it back: the provisional places shift, both are audited with the reason', function (): void {
    ['month' => $month, 'candidates' => $c] = mrrPendingMonth(4);
    $admin = mrrAdmin();

    $disqualified = mrrService()->disqualify($month, $c[0]->id, '  shared account  ', $admin, '10.0.0.2');
    expect($disqualified->status)->toBe('disqualified')
        ->and($disqualified->disqualify_reason)->toBe('shared account')
        ->and($disqualified->disqualified_by_user_id)->toBe($admin->id)
        ->and($disqualified->disqualified_at)->not->toBeNull()
        ->and(mrrPlaces($month))->toBe(['1:disqualified:-', '2:candidate:1', '3:candidate:2', '4:candidate:3'])
        ->and(AuditLog::query()->where('action', MonthlyRatingReviewService::ACTION_DISQUALIFIED)->sole()->meta)->toMatchArray(['candidate_id' => $c[0]->id, 'learner_id' => $c[0]->learner_id, 'rank' => 1, 'points' => 500, 'reason' => 'shared account', 'starts_on' => '2026-10-01'])
        ->and(ProductEvent::query()->where('name', ProductEventName::LeagueMonthCandidateDisqualified->value)->where('learner_id', $c[0]->learner_id)->count())->toBe(1);

    // Twice: refused; no reason: refused; a candidate of another month: not found.
    expect(fn () => mrrService()->disqualify($month, $c[0]->id, 'again', $admin))->toThrow(LeagueMonthException::class, 'already disqualified')
        ->and(fn () => mrrService()->disqualify($month, $c[1]->id, '   ', $admin))->toThrow(LeagueMonthException::class, 'needs a reason')
        ->and(fn () => mrrService()->disqualify($month, $c[1]->id, str_repeat('x', 501), $admin))->toThrow(LeagueMonthException::class, 'needs a reason');
    $other = LeagueMonth::factory()->startingOn('2026-09-01')->pendingReview()->create();
    expect(fn () => mrrService()->disqualify($other, $c[1]->id, 'wrong month', $admin))->toThrow(LeagueMonthException::class, 'No such candidate')
        ->and(fn () => mrrService()->reinstate($month, $c[1]->id, $admin))->toThrow(LeagueMonthException::class, 'not disqualified')
        ->and(mrrPlaces($month))->toBe(['1:disqualified:-', '2:candidate:1', '3:candidate:2', '4:candidate:3']);

    $back = mrrService()->reinstate($month, $c[0]->id, $admin, '10.0.0.2');
    expect($back->status)->toBe('candidate')
        ->and($back->disqualify_reason)->toBeNull()
        ->and($back->disqualified_at)->toBeNull()
        ->and(mrrPlaces($month))->toBe(['1:candidate:1', '2:candidate:2', '3:candidate:3', '4:candidate:-'])
        ->and(AuditLog::query()->where('action', MonthlyRatingReviewService::ACTION_REINSTATED)->sole()->meta)->toMatchArray(['candidate_id' => $c[0]->id, 'previous_reason' => 'shared account'])
        ->and(ProductEvent::query()->where('name', ProductEventName::LeagueMonthCandidateReinstated->value)->count())->toBe(1);
});

it('marks the prize of a confirmed winner as delivered once — never of a non-winner or before the confirmation', function (): void {
    ['month' => $month, 'candidates' => $c] = mrrPendingMonth(4);
    $admin = mrrAdmin();

    expect(fn () => mrrService()->markPrizeDelivered($month, $c[0]->id, $admin))->toThrow(LeagueMonthException::class, 'not confirmed');

    mrrService()->confirm($month, $admin);
    $delivered = mrrService()->markPrizeDelivered($month, $c[0]->id, $admin, '10.0.0.3');
    expect($delivered->prize_delivered_at)->not->toBeNull()
        ->and($delivered->prize_delivered_by_user_id)->toBe($admin->id)
        ->and(AuditLog::query()->where('action', MonthlyRatingReviewService::ACTION_PRIZE_DELIVERED)->sole()->meta)->toMatchArray(['candidate_id' => $c[0]->id, 'place' => 1])
        ->and(ProductEvent::query()->where('name', ProductEventName::LeagueMonthPrizeDelivered->value)->where('learner_id', $c[0]->learner_id)->count())->toBe(1);

    expect(fn () => mrrService()->markPrizeDelivered($month, $c[0]->id, $admin))->toThrow(LeagueMonthException::class, 'already marked')
        ->and(fn () => mrrService()->markPrizeDelivered($month, $c[3]->id, $admin))->toThrow(LeagueMonthException::class, 'Only a confirmed winner')
        ->and(AuditLog::query()->where('action', MonthlyRatingReviewService::ACTION_PRIZE_DELIVERED)->count())->toBe(1);
});

it('edits the prizes of a month that is not confirmed: validated, normalised, audited; null returns to the defaults', function (): void {
    $month = LeagueMonth::factory()->startingOn('2026-11-01')->create();
    $admin = mrrAdmin();

    $saved = mrrService()->setPrizes($month, [
        ['place' => '3', 'ru' => ' Книга ', 'uz' => 'Kitob'],
        ['place' => 1, 'ru' => 'Premium 3 мес.', 'uz' => ''],
        ['place' => 2, 'ru' => '', 'uz' => ''], // dropped: no title
    ], $admin, '10.0.0.4');

    expect($saved->prizes)->toBe([['place' => 1, 'ru' => 'Premium 3 мес.', 'uz' => ''], ['place' => 3, 'ru' => 'Книга', 'uz' => 'Kitob']])
        ->and(array_map(fn ($p) => [$p->place, $p->title('ru'), $p->title('uz')], app(MonthlyRatingService::class)->prizes($month)))
        ->toBe([[1, 'Premium 3 мес.', 'Premium 3 мес.'], [3, 'Книга', 'Kitob']])
        ->and(AuditLog::query()->where('action', MonthlyRatingReviewService::ACTION_PRIZES_CHANGED)->sole()->meta)->toMatchArray(['before' => null]);

    foreach ([
        [['place' => 4, 'ru' => 'x', 'uz' => 'x']],
        [['place' => 0, 'ru' => 'x', 'uz' => 'x']],
        [['place' => 'first', 'ru' => 'x', 'uz' => 'x']],
        [['place' => 1, 'ru' => 'a', 'uz' => 'a'], ['place' => 1, 'ru' => 'b', 'uz' => 'b']],
        [['place' => 1, 'ru' => str_repeat('x', 201), 'uz' => '']],
        ['not a prize'],
    ] as $invalid) {
        try {
            mrrService()->setPrizes($month, $invalid, $admin);
            $this->fail('Accepted: '.json_encode($invalid));
        } catch (LeagueMonthException $e) {
            expect($e->errorCode)->toBe(LeagueMonthException::PRIZES_INVALID);
        }
    }
    expect($month->fresh()?->prizes)->toHaveCount(2);

    expect(mrrService()->setPrizes($month, [], $admin)->prizes)->toBe([])
        ->and(mrrService()->setPrizes($month, null, $admin)->prizes)->toBeNull()
        ->and(AuditLog::query()->where('action', MonthlyRatingReviewService::ACTION_PRIZES_CHANGED)->count())->toBe(3);

    // A pending month may still fix a typo; a confirmed one may not.
    $month->forceFill(['status' => 'pending_review'])->save();
    expect(mrrService()->setPrizes($month, [['place' => 1, 'ru' => 'x', 'uz' => 'y']], $admin)->prizes)->toBe([['place' => 1, 'ru' => 'x', 'uz' => 'y']]);
});

it('lists the months newest first with their candidate counts, and the review carries the learners', function (): void {
    ['month' => $october, 'candidates' => $c] = mrrPendingMonth(2);
    $november = LeagueMonth::factory()->startingOn('2026-11-01')->create();

    $months = mrrService()->months();
    expect($months->pluck('id')->all())->toBe([$november->id, $october->id])
        ->and($months->firstWhere('id', $october->id)?->getAttribute('candidates_count'))->toBe(2);

    $review = mrrService()->review($october);
    expect($review->month->id)->toBe($october->id)
        ->and($review->prizes)->toHaveCount(3)
        ->and($review->candidates[0]->learner?->id)->toBe($c[0]->learner_id)
        ->and($review->candidates[0]->learner?->first_name)->toBe('L1');
});

it('lets an active admin review and decide, nobody else', function (): void {
    $month = LeagueMonth::factory()->create();
    $policy = new LeagueMonthPolicy;
    $admin = mrrAdmin();
    Role::findOrCreate('teacher', 'web');
    $teacher = User::factory()->create(['is_active' => true]);
    $teacher->assignRole('teacher');
    $inactive = User::factory()->create(['is_active' => false]);
    $inactive->assignRole('admin');

    expect($policy->viewAny($admin))->toBeTrue()
        ->and($policy->view($admin, $month))->toBeTrue()
        ->and($policy->update($admin, $month))->toBeTrue()
        ->and($policy->create($admin))->toBeFalse()
        ->and($policy->delete($admin, $month))->toBeFalse()
        ->and($policy->viewAny($teacher))->toBeFalse()
        ->and($policy->update($teacher, $month))->toBeFalse()
        ->and($policy->viewAny($inactive))->toBeFalse()
        ->and($policy->update($inactive, $month))->toBeFalse();
});
