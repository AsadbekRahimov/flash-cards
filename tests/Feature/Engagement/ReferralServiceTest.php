<?php

declare(strict_types=1);

use App\Domain\Engagement\Events\ReferralMilestoneReached;
use App\Domain\Engagement\Services\ReferralCodeGenerator;
use App\Domain\Engagement\Services\ReferralService;
use App\Domain\Learners\Services\DailyStatsService;
use App\Domain\Learners\Services\LearnerRegistrar;
use App\Jobs\GrantReferralMilestoneJob;
use App\Jobs\RewardReferralJob;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\ProductEvent;
use App\Models\Referral;
use App\Models\ReferralMilestone;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/*
 * ReferralService (docs/13 §1.5, §2.4, §7.1 "2C.9"): codes, linking, activation ("the friend started": the onboarding
 * and one day of 10 cards), the counting of a friend for the inviter, the milestones (3 and 6 friends → 7 days of Pro
 * each, exactly once whatever the retries), the burst flag, the admin actions, the recovery sweep. The invited friend
 * gets nothing by the referral (the welcome trial is every new learner's — ProTrialServiceTest).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'telegram.bot_username' => 'LexiFlowBot',
        'referrals.milestones' => [3, 6],
        'referrals.milestone_days' => 7,
        'referrals.activation' => ['days' => 1, 'reviews_per_day' => 10, 'window_days' => 14],
        'referrals.flag_links_per_day' => 20,
        'billing.trial.days' => 7,
    ]);
});

function refService(): ReferralService
{
    return app(ReferralService::class);
}

function refNow(string $utc = '2026-10-22 10:00:00'): CarbonImmutable
{
    return CarbonImmutable::parse($utc, 'UTC');
}

/** A learner created "by this /start" — the registrar's fresh row has `wasRecentlyCreated` (and its welcome trial). */
function refNewLearner(int $tgId, string $tz = 'Asia/Tashkent', string $onboarding = 'done'): Learner
{
    $learner = app(LearnerRegistrar::class)->fromTelegramUser(['id' => $tgId, 'first_name' => 'New'.$tgId]);
    $learner->forceFill(['timezone' => $tz, 'onboarding_state' => $onboarding])->save();

    return $learner;
}

function refInviter(string $code = 'INVITE2'): Learner
{
    return Learner::factory()->create(['referral_code' => $code, 'timezone' => 'Asia/Tashkent']);
}

/** A pending referral of a fresh learner, linked at `$linkedAt` (UTC). */
function refPending(Learner $inviter, int $tgId, string $linkedAt = '2026-10-22 10:00:00'): Referral
{
    $referee = refNewLearner($tgId);
    $referral = refService()->link($referee, (string) $inviter->referral_code, refNow($linkedAt));

    /** @var Referral $referral */
    return $referral;
}

/** An activated referral of the inviter, the way `evaluate` leaves it, ready for the job. */
function refActivated(Learner $inviter, int $tgId): Referral
{
    $referral = refPending($inviter, $tgId);
    $referral->forceFill(['status' => 'activated', 'activated_at' => refNow()])->save();

    return $referral;
}

function refDay(Learner $learner, string $date, int $reviews): void
{
    LearnerDailyStats::factory()->create(['learner_id' => $learner->id, 'date' => $date, 'reviews_count' => $reviews]);
}

/** The inviter's reward subscriptions (`provider = reward`): the milestone grants. */
function refRewards(Learner $inviter): Collection
{
    return Subscription::query()->where('subscriber_id', $inviter->id)->where('provider', 'reward')->orderBy('id')->get();
}

/** @return list<array{milestone: int, granted: bool, subscription_id: int|null, skipped: string|null}> */
function refMilestones(Learner $inviter): array
{
    return ReferralMilestone::query()->where('referrer_learner_id', $inviter->id)->orderBy('milestone')->get()
        ->map(fn (ReferralMilestone $m): array => ['milestone' => $m->milestone, 'granted' => $m->isSettled(), 'subscription_id' => $m->subscription_id, 'skipped' => $m->skipped])
        ->all();
}

// --- codeFor -----------------------------------------------------------------

it('generates a code lazily from the safe alphabet, once, and keeps it', function (): void {
    $learner = Learner::factory()->create();
    expect($learner->referral_code)->toBeNull();

    $code = refService()->codeFor($learner);

    expect($code)->toMatch('/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{8}$/')
        ->and($learner->referral_code)->toBe($code)
        ->and($learner->isDirty())->toBeFalse()
        ->and($learner->fresh()?->referral_code)->toBe($code)
        ->and(refService()->codeFor($learner))->toBe($code)
        ->and(refService()->codeFor($learner->fresh() ?? $learner))->toBe($code);
});

it('does not overwrite a code written by a parallel request', function (): void {
    $learner = Learner::factory()->create();
    Learner::query()->whereKey($learner->id)->update(['referral_code' => 'PARALLEL']);

    expect(refService()->codeFor($learner))->toBe('PARALLEL')
        ->and($learner->referral_code)->toBe('PARALLEL');
});

it('retries on a unique collision with a fresh code', function (): void {
    Learner::factory()->create(['referral_code' => 'TAKEN234']);
    $learner = Learner::factory()->create();

    $this->mock(ReferralCodeGenerator::class, function ($mock): void {
        $mock->shouldReceive('generate')->times(2)->andReturn('TAKEN234', 'FRESH234');
    });

    expect(refService()->codeFor($learner))->toBe('FRESH234')
        ->and($learner->fresh()?->referral_code)->toBe('FRESH234');
});

it('gives up after repeated collisions instead of looping forever', function (): void {
    Learner::factory()->create(['referral_code' => 'TAKEN234']);
    $learner = Learner::factory()->create();

    $this->mock(ReferralCodeGenerator::class, function ($mock): void {
        $mock->shouldReceive('generate')->andReturn('TAKEN234');
    });

    expect(fn () => refService()->codeFor($learner))->toThrow(RuntimeException::class);
});

it('builds the invite link from the bot username, or none while it is not configured', function (): void {
    expect(refService()->inviteLink('ABC234'))->toBe('https://t.me/LexiFlowBot?start=ref_ABC234');

    config(['telegram.bot_username' => '@LexiFlowBot ']);
    expect(refService()->inviteLink('ABC234'))->toBe('https://t.me/LexiFlowBot?start=ref_ABC234');

    config(['telegram.bot_username' => '']);
    expect(refService()->inviteLink('ABC234'))->toBeNull();
});

// --- link ----------------------------------------------------------------------

it('links a learner created by this /start as pending and records referral.linked', function (): void {
    $inviter = refInviter();
    $referee = refNewLearner(9001);

    $referral = refService()->link($referee, 'INVITE2', refNow());

    expect($referral)->not->toBeNull()
        ->and($referral?->status)->toBe('pending')
        ->and($referral?->referrer_learner_id)->toBe($inviter->id)
        ->and($referral?->referee_learner_id)->toBe($referee->id)
        ->and($referral?->linked_at?->toIso8601String())->toBe(refNow()->toIso8601String())
        ->and($referral?->flagged_at)->toBeNull();

    $event = ProductEvent::query()->where('name', 'referral.linked')->sole();
    expect($event->learner_id)->toBe($referee->id)
        ->and(ksorted($event->props))->toBe(ksorted(['referral_id' => $referral?->id, 'referrer_learner_id' => $inviter->id, 'flagged' => false]));
});

it('rejects a self-referral and an existing learner, each with its reason', function (): void {
    $inviter = refInviter();

    $self = refService()->link($inviter, 'INVITE2', refNow());
    expect($self?->status)->toBe('rejected')
        ->and($self?->reject_reason)->toBe('self');

    // Re-read: a factory model is itself "recently created"; a learner from the database is not.
    /** @var Learner $existing */
    $existing = Learner::factory()->create()->fresh();
    $old = refService()->link($existing, 'INVITE2', refNow());
    expect($old?->status)->toBe('rejected')
        ->and($old?->reject_reason)->toBe('existing_learner');

    expect(ProductEvent::query()->where('name', 'referral.rejected')->pluck('props')->map(fn (array $p): string => $p['reason'])->sort()->values()->all())
        ->toBe(['existing_learner', 'self']);
});

it('writes nothing for an unknown or blocked inviter code but records the rejection', function (): void {
    Learner::factory()->create(['referral_code' => 'BLOCKED2', 'blocked_at' => now()]);
    $referee = refNewLearner(9002);

    expect(refService()->link($referee, 'NOSUCH2', refNow()))->toBeNull()
        ->and(refService()->link($referee, 'BLOCKED2', refNow()))->toBeNull()
        ->and(Referral::query()->count())->toBe(0)
        ->and(ProductEvent::query()->where('name', 'referral.rejected')->where('props->reason', 'unknown_code')->count())->toBe(2);
});

it('is safe to repeat: one row per invited learner, the first link wins', function (): void {
    $inviter = refInviter();
    $other = refInviter('OTHER23');
    $referee = refNewLearner(9003);

    $first = refService()->link($referee, 'INVITE2', refNow());
    $again = refService()->link($referee, 'INVITE2', refNow()->addMinute());
    $elsewhere = refService()->link($referee, 'OTHER23', refNow()->addMinutes(2));

    expect(Referral::query()->count())->toBe(1)
        ->and($again?->id)->toBe($first?->id)
        ->and($elsewhere?->id)->toBe($first?->id)
        ->and($elsewhere?->referrer_learner_id)->toBe($inviter->id)
        ->and(Referral::query()->where('referrer_learner_id', $other->id)->count())->toBe(0)
        ->and(ProductEvent::query()->where('name', 'referral.linked')->count())->toBe(1);
});

it('flags the link above the daily burst threshold; the ones below stay clean', function (): void {
    config(['referrals.flag_links_per_day' => 3]);
    $inviter = refInviter();

    $rows = [];
    foreach ([1, 2, 3, 4] as $i) {
        $rows[] = refService()->link(refNewLearner(9100 + $i), 'INVITE2', refNow()->addMinutes($i));
    }

    expect($rows[0]?->flagged_at)->toBeNull()
        ->and($rows[1]?->flagged_at)->toBeNull()
        ->and($rows[2]?->flagged_at)->toBeNull()
        ->and($rows[3]?->flagged_at)->not->toBeNull()
        ->and($rows[3]?->status)->toBe('pending')
        ->and(ProductEvent::query()->where('name', 'referral.linked')->where('props->flagged', true)->count())->toBe(1);

    // Links older than 24 h do not count: a day later the next one is clean again.
    $late = refService()->link(refNewLearner(9105), 'INVITE2', refNow()->addDays(2));
    expect($late?->flagged_at)->toBeNull();
});

// --- evaluate ------------------------------------------------------------------

it('activates after one local day with 10 graded cards inside the window, and not before', function (): void {
    Queue::fake();
    $inviter = refInviter();
    // Linked at 2026-10-22 20:30 UTC = 2026-10-23 01:30 in Tashkent: the window is Oct 23 .. Nov 5 (14 local days).
    $referral = refPending($inviter, 9201, '2026-10-22 20:30:00');
    /** @var Learner $referee */
    $referee = $referral->referee;

    // Nine cards: not yet. A day before the window (UTC Oct 22 is before the local link day) does not count either.
    refDay($referee, '2026-10-23', 9);
    refDay($referee, '2026-10-22', 50);
    refService()->evaluate($referee, refNow('2026-10-23 12:00:00'));
    expect($referral->fresh()?->status)->toBe('pending');
    Queue::assertNothingPushed();

    // A day after the window (Nov 6) does not count.
    refDay($referee, '2026-11-06', 50);
    refService()->evaluate($referee, refNow('2026-11-06 12:00:00'));
    expect($referral->fresh()?->status)->toBe('pending');

    // The last day of the window does.
    refDay($referee, '2026-11-05', 10);
    refService()->evaluate($referee, refNow('2026-11-06 12:00:00'));

    $fresh = $referral->fresh();
    expect($fresh?->status)->toBe('activated')
        ->and($fresh?->activated_at?->toIso8601String())->toBe(refNow('2026-11-06 12:00:00')->toIso8601String())
        ->and($fresh?->meta['active_days'])->toBe(['2026-11-05']);
    Queue::assertPushed(RewardReferralJob::class, fn (RewardReferralJob $job): bool => $job->referralId === $referral->id);
    expect(ksorted(ProductEvent::query()->where('name', 'referral.activated')->sole()->props))
        ->toBe(ksorted(['referral_id' => $referral->id, 'referrer_learner_id' => $inviter->id, 'active_days' => 1]));
});

it('needs the onboarding done: cards graded before it count only once it is finished', function (): void {
    Queue::fake();
    $inviter = refInviter();
    $referral = refPending($inviter, 9204);
    /** @var Learner $referee */
    $referee = $referral->referee;
    $referee->forceFill(['onboarding_state' => 'goal'])->save();
    refDay($referee, '2026-10-22', 10);

    refService()->evaluate($referee, refNow('2026-10-22 12:00:00'));
    expect($referral->fresh()?->status)->toBe('pending');
    Queue::assertNothingPushed();

    $referee->forceFill(['onboarding_state' => 'done'])->save();
    refService()->evaluate($referee, refNow('2026-10-22 13:00:00'));
    expect($referral->fresh()?->status)->toBe('activated');
    Queue::assertPushed(RewardReferralJob::class, 1);
});

it('does nothing for a learner without a pending referral and activates only once', function (): void {
    Queue::fake();
    $inviter = refInviter();
    $referral = refPending($inviter, 9202);
    /** @var Learner $referee */
    $referee = $referral->referee;
    refDay($referee, '2026-10-22', 10);
    refDay($referee, '2026-10-23', 10);

    refService()->evaluate(Learner::factory()->create(), refNow());
    refService()->evaluate($referee, refNow('2026-10-22 12:00:00'));
    refService()->evaluate($referee, refNow('2026-10-23 12:00:00'));

    expect(Referral::query()->where('status', 'activated')->count())->toBe(1);
    Queue::assertPushed(RewardReferralJob::class, 1);
});

it('is driven by the grading flow: the 10th grade of the first day activates after commit and counts on the sync queue', function (): void {
    $inviter = refInviter();
    $referral = refPending($inviter, 9203, '2026-10-22 06:00:00');
    /** @var Learner $referee */
    $referee = $referral->referee;
    $stats = app(DailyStatsService::class);

    for ($i = 1; $i <= 10; $i++) {
        DB::transaction(fn () => $stats->recordReview($referee, 4, 1000, false, refNow('2026-10-22 08:00:00')));
        if ($i < 10) {
            expect($referral->fresh()?->status)->toBe('pending', "grade #{$i}");
        }
    }

    $fresh = $referral->fresh();
    expect($fresh?->status)->toBe('rewarded')
        ->and($fresh?->meta['counted'])->toBe(1)
        // The first friend: counted, no milestone yet, no Pro for anybody by the referral (the friend's trial is the trial's).
        ->and(ReferralMilestone::query()->count())->toBe(0)
        ->and(refRewards($inviter))->toHaveCount(0)
        ->and(Subscription::query()->where('subscriber_id', $referee->id)->where('provider', 'reward')->count())->toBe(0);
});

// --- reward: counting and milestones ----------------------------------------------

it('counts friends one by one and rewards the inviter at 3 and at 6, never the friend, nothing after the last milestone', function (): void {
    Event::fake([ReferralMilestoneReached::class]);
    $inviter = refInviter();
    $now = refNow('2026-10-25 09:00:00');

    foreach (range(1, 8) as $n) {
        $referral = refActivated($inviter, 9300 + $n);
        refService()->reward($referral, $now->addMinutes($n));

        $fresh = $referral->fresh();
        expect($fresh?->status)->toBe('rewarded', "friend {$n}")
            ->and($fresh?->rewarded_at?->toIso8601String())->toBe($now->addMinutes($n)->toIso8601String())
            ->and($fresh?->meta['counted'])->toBe($n)
            ->and(Subscription::query()->where('subscriber_id', $fresh?->referee_learner_id)->where('provider', 'reward')->count())->toBe(0)
            ->and(refRewards($inviter))->toHaveCount($n < 3 ? 0 : 1)
            ->and(ReferralMilestone::query()->where('referrer_learner_id', $inviter->id)->count())->toBe(match (true) {
                $n < 3 => 0, $n < 6 => 1, default => 2
            });
    }

    $reward = refRewards($inviter)->sole();
    expect($reward->provider_ref)->toBe("referral_milestone:{$inviter->id}:3")
        // 7 days at the 3rd friend (minute 3), 7 more at the 6th: one row, extended from its end.
        ->and($reward->current_period_ends_at?->toIso8601String())->toBe($now->addMinutes(3)->addDays(14)->toIso8601String())
        ->and($reward->meta['history'][0]['source'])->toBe("referral_milestone:{$inviter->id}:6")
        ->and(refMilestones($inviter))->toBe([
            ['milestone' => 3, 'granted' => true, 'subscription_id' => $reward->id, 'skipped' => null],
            ['milestone' => 6, 'granted' => true, 'subscription_id' => $reward->id, 'skipped' => null],
        ]);

    $three = ReferralMilestone::query()->where('milestone', 3)->sole();
    expect($three->referral_id)->toBe(Referral::query()->where('meta->counted', 3)->sole()->id)
        ->and($three->days)->toBe(7)
        ->and($three->reached_at?->toIso8601String())->toBe($now->addMinutes(3)->toIso8601String())
        ->and($three->granted_at?->toIso8601String())->toBe($now->addMinutes(3)->toIso8601String());

    // Events: one `referral.counted` per friend, one `referral.milestone_reached` per milestone (actor: the inviter).
    expect(ProductEvent::query()->where('name', 'referral.counted')->count())->toBe(8)
        ->and(ProductEvent::query()->where('name', 'referral.counted')->where('props->counted', 6)->sole()->learner_id)->toBe(Referral::query()->where('meta->counted', 6)->sole()->referee_learner_id)
        ->and(ProductEvent::query()->where('name', 'referral.milestone_reached')->where('learner_id', $inviter->id)->orderBy('id')->pluck('props')->map(fn (array $p): array => ksorted($p))->all())
        ->toBe([
            ksorted(['milestone' => 3, 'days' => 7, 'referral_id' => $three->referral_id, 'subscription_id' => $reward->id, 'skipped' => null]),
            ksorted(['milestone' => 6, 'days' => 7, 'referral_id' => ReferralMilestone::query()->where('milestone', 6)->sole()->referral_id, 'subscription_id' => $reward->id, 'skipped' => null]),
        ])
        ->and(ProductEvent::query()->where('name', 'subscription.granted')->where('props->provider', 'reward')->count())->toBe(2);
    Event::assertDispatchedTimes(ReferralMilestoneReached::class, 2);
    Event::assertDispatched(ReferralMilestoneReached::class, fn (ReferralMilestoneReached $e): bool => $e->learnerId === $inviter->id && $e->milestone === 3 && $e->days === 7 && $e->subscriptionId === $reward->id && $e->skipped === null);
    Event::assertDispatched(ReferralMilestoneReached::class, fn (ReferralMilestoneReached $e): bool => $e->milestone === 6);
});

it('is idempotent: a repeated reward, a repeated job and a recovery grant nothing more', function (): void {
    $inviter = refInviter();
    $now = refNow('2026-10-25 09:00:00');
    Referral::factory()->count(2)->rewarded($now->subDay())->create(['referrer_learner_id' => $inviter->id]);
    $third = refActivated($inviter, 9311);

    refService()->reward($third, $now);
    refService()->reward($third, $now->addMinute());
    (new RewardReferralJob($third->id))->handle(refService());
    (new GrantReferralMilestoneJob(ReferralMilestone::query()->sole()->id))->handle(refService());
    refService()->grantMilestone(ReferralMilestone::query()->sole()->id, $now->addHour());
    expect(refService()->recoverStuck($now->addHour()))->toBe(0);

    $reward = refRewards($inviter)->sole();
    expect($reward->current_period_ends_at?->toIso8601String())->toBe($now->addDays(7)->toIso8601String())
        ->and(refMilestones($inviter))->toBe([['milestone' => 3, 'granted' => true, 'subscription_id' => $reward->id, 'skipped' => null]])
        ->and(ProductEvent::query()->where('name', 'referral.counted')->count())->toBe(1)
        ->and(ProductEvent::query()->where('name', 'referral.milestone_reached')->count())->toBe(1)
        ->and(ProductEvent::query()->where('name', 'subscription.granted')->where('props->provider', 'reward')->count())->toBe(1);
});

it('settles a milestone owed after a failure between the claim and the grant on the next run, exactly once', function (): void {
    $inviter = refInviter();
    $now = refNow('2026-10-25 09:00:00');
    Referral::factory()->count(2)->rewarded($now->subDay())->create(['referrer_learner_id' => $inviter->id]);
    $third = refActivated($inviter, 9312);

    // The grant transaction dies inside the subscription write; the claim (the count and the milestone row) is committed.
    $poison = true;
    Subscription::creating(function (Subscription $s) use (&$poison, $inviter): void {
        if ($poison && $s->subscriber_id === $inviter->id) {
            throw new RuntimeException('simulated outage');
        }
    });

    expect(fn () => refService()->reward($third, $now))->toThrow(RuntimeException::class);

    expect($third->fresh()?->status)->toBe('rewarded')
        ->and(refMilestones($inviter))->toBe([['milestone' => 3, 'granted' => false, 'subscription_id' => null, 'skipped' => null]])
        ->and(refRewards($inviter))->toHaveCount(0)
        ->and(ProductEvent::query()->where('name', 'referral.milestone_reached')->count())->toBe(0);

    // The job retry: the claim is "already", the owed milestone is granted. A second retry: nothing more.
    $poison = false;
    refService()->reward($third, $now->addMinute());
    (new RewardReferralJob($third->id))->handle(refService());

    $reward = refRewards($inviter)->sole();
    expect(refMilestones($inviter))->toBe([['milestone' => 3, 'granted' => true, 'subscription_id' => $reward->id, 'skipped' => null]])
        ->and($reward->current_period_ends_at?->toIso8601String())->toBe($now->addMinute()->addDays(7)->toIso8601String())
        ->and(ReferralMilestone::query()->sole()->granted_at?->toIso8601String())->toBe($now->addMinute()->toIso8601String())
        ->and(ProductEvent::query()->where('name', 'referral.milestone_reached')->count())->toBe(1);
});

it('counts the friends of before the milestone scheme too: the rows already rewarded make the 3rd new one the milestone', function (): void {
    $inviter = refInviter();
    $now = refNow('2026-10-25 09:00:00');
    // Two friends rewarded under the old per-friend scheme (both sides had their days): no milestone row exists for them.
    Referral::factory()->count(2)->rewarded($now->subDays(20))->create(['referrer_learner_id' => $inviter->id, 'meta' => ['rewards' => ['referee' => ['subscription_id' => 1, 'skipped' => null], 'referrer' => ['subscription_id' => 2, 'skipped' => null]]]]);

    refService()->reward(refActivated($inviter, 9313), $now);

    expect(refMilestones($inviter))->toHaveCount(1)
        ->and(refRewards($inviter))->toHaveCount(1);
});

it('does not count a pending, a rejected or a flagged referral, and a flagged one counts once an admin clears the flag', function (): void {
    $inviter = refInviter();
    $now = refNow('2026-10-25 09:00:00');
    Referral::factory()->count(2)->rewarded($now->subDay())->create(['referrer_learner_id' => $inviter->id]);

    $pending = refPending($inviter, 9321);
    refService()->reward($pending, $now);
    expect($pending->fresh()?->status)->toBe('pending');

    $rejected = refActivated($inviter, 9322);
    $rejected->forceFill(['status' => 'rejected', 'reject_reason' => 'flagged'])->save();
    refService()->reward($rejected, $now);
    expect($rejected->fresh()?->status)->toBe('rejected');

    $flagged = refActivated($inviter, 9323);
    $flagged->forceFill(['flagged_at' => $now])->save();
    refService()->reward($flagged, $now);
    expect($flagged->fresh()?->status)->toBe('activated')
        ->and(ReferralMilestone::query()->count())->toBe(0)
        ->and(refRewards($inviter))->toHaveCount(0)
        ->and(refService()->overview($inviter)->counted)->toBe(2);

    $admin = User::factory()->create();
    expect(refService()->unflag($flagged, $admin, '10.0.0.1'))->toBeTrue()
        ->and($flagged->flagged_at)->toBeNull();

    // The sync queue ran the job at once: the 3rd friend, the first milestone.
    expect($flagged->fresh()?->status)->toBe('rewarded')
        ->and(refMilestones($inviter))->toHaveCount(1)
        ->and(refRewards($inviter))->toHaveCount(1);

    $audit = AuditLog::query()->where('action', 'referral.unflagged')->sole();
    expect($audit->user_id)->toBe($admin->id)
        ->and($audit->entity_type)->toBe(Referral::class)
        ->and($audit->entity_id)->toBe($flagged->id)
        ->and($audit->ip)->toBe('10.0.0.1')
        ->and($audit->meta['referrer_learner_id'])->toBe($inviter->id);

    // Not flagged any more: nothing to clear.
    expect(refService()->unflag($flagged, $admin))->toBeFalse()
        ->and(AuditLog::query()->count())->toBe(1);
});

it('lets an admin refuse a flagged referral for good', function (): void {
    $inviter = refInviter();
    $referral = refPending($inviter, 9310);
    $referral->forceFill(['flagged_at' => refNow()])->save();
    $admin = User::factory()->create();

    expect(refService()->rejectFlagged($referral, $admin))->toBeTrue()
        ->and($referral->status)->toBe('rejected')
        ->and($referral->reject_reason)->toBe('flagged')
        ->and(AuditLog::query()->where('action', 'referral.rejected')->count())->toBe(1)
        ->and(ProductEvent::query()->where('name', 'referral.rejected')->where('props->reason', 'flagged')->count())->toBe(1)
        // Rejected: neither action applies any more.
        ->and(refService()->rejectFlagged($referral, $admin))->toBeFalse()
        ->and(refService()->unflag($referral, $admin))->toBeTrue()
        ->and($referral->fresh()?->status)->toBe('rejected')
        ->and(Subscription::query()->where('provider', 'reward')->count())->toBe(0);
});

it('settles a milestone without Pro for an inviter with perpetual Pro, deleted, or blocked, and records why', function (): void {
    Event::fake([ReferralMilestoneReached::class]);
    $now = refNow('2026-10-25 09:00:00');

    $cases = [
        'perpetual' => function (Learner $inviter): void {
            Subscription::factory()->create(['subscriber_id' => $inviter->id, 'current_period_ends_at' => null]);
        },
        'learner_deleted' => fn (Learner $inviter) => $inviter->delete(),
        'learner_blocked' => fn (Learner $inviter) => $inviter->forceFill(['blocked_at' => $now])->save(),
    ];

    $tg = 9400;
    foreach ($cases as $skipped => $prepare) {
        $inviter = refInviter('INV'.strtoupper(substr(md5($skipped), 0, 5)));
        Referral::factory()->count(2)->rewarded($now->subDay())->create(['referrer_learner_id' => $inviter->id]);
        $third = refActivated($inviter, ++$tg);
        $prepare($inviter);

        refService()->reward($third, $now);

        expect($third->fresh()?->status)->toBe('rewarded', $skipped)
            ->and(refMilestones($inviter))->toBe([['milestone' => 3, 'granted' => true, 'subscription_id' => null, 'skipped' => $skipped]], $skipped)
            ->and(refRewards($inviter))->toHaveCount(0, $skipped)
            ->and(ProductEvent::query()->where('name', 'referral.milestone_reached')->where('props->skipped', $skipped)->count())->toBe(1, $skipped);
        Event::assertDispatched(ReferralMilestoneReached::class, fn (ReferralMilestoneReached $e): bool => $e->learnerId === $inviter->id && $e->skipped === $skipped && $e->subscriptionId === null);

        // Settled for good: a retry does not come back to it, also once the block is lifted.
        $inviter->forceFill(['blocked_at' => null])->save();
        (new RewardReferralJob($third->id))->handle(refService());
        expect(refRewards($inviter))->toHaveCount(0, $skipped);
    }

    // A deleted inviter's event names no learner (nothing of a deleted profile is attributed).
    expect(ProductEvent::query()->where('name', 'referral.milestone_reached')->where('props->skipped', 'learner_deleted')->sole()->learner_id)->toBeNull();
});

it('extends the live subscription of the inviter instead of opening a second one, and keeps a canceled one canceled', function (): void {
    $inviter = refInviter();
    $now = refNow('2026-10-25 09:00:00');
    $live = Subscription::factory()->create(['subscriber_id' => $inviter->id, 'provider' => 'payme', 'current_period_ends_at' => $now->addDays(10)]);
    Referral::factory()->count(2)->rewarded($now->subDay())->create(['referrer_learner_id' => $inviter->id]);

    refService()->reward(refActivated($inviter, 9330), $now);

    expect(Subscription::query()->where('subscriber_id', $inviter->id)->count())->toBe(1)
        ->and($live->fresh()?->current_period_ends_at?->toIso8601String())->toBe($now->addDays(17)->toIso8601String())
        ->and($live->fresh()?->provider)->toBe('payme')
        ->and(refMilestones($inviter))->toBe([['milestone' => 3, 'granted' => true, 'subscription_id' => $live->id, 'skipped' => null]]);
});

it('follows the configured milestones: a single one, and none at all', function (): void {
    config(['referrals.milestones' => [2], 'referrals.milestone_days' => 3]);
    $inviter = refInviter();
    $now = refNow('2026-10-25 09:00:00');

    refService()->reward(refActivated($inviter, 9340), $now);
    refService()->reward(refActivated($inviter, 9341), $now);
    refService()->reward(refActivated($inviter, 9342), $now);

    expect(refMilestones($inviter))->toBe([['milestone' => 2, 'granted' => true, 'subscription_id' => refRewards($inviter)->sole()->id, 'skipped' => null]])
        ->and(refRewards($inviter)->sole()->current_period_ends_at?->toIso8601String())->toBe($now->addDays(3)->toIso8601String());

    config(['referrals.milestones' => []]);
    $nobody = refInviter('NOBODY2');
    refService()->reward(refActivated($nobody, 9343), $now);
    expect($nobody->referrals()->where('status', 'rewarded')->count())->toBe(1)
        ->and(ReferralMilestone::query()->where('referrer_learner_id', $nobody->id)->count())->toBe(0)
        ->and(refService()->overview($nobody)->milestones)->toBe([])
        ->and(refService()->overview($nobody)->next)->toBeNull();
});

// --- overview --------------------------------------------------------------------

it('builds the inviter overview: code, link, the rule, the counts, the milestones, the next one and the invited names only', function (): void {
    $inviter = Learner::factory()->create();
    $a = refNewLearner(9401);
    $b = refNewLearner(9402);
    $c = refNewLearner(9403);
    $code = refService()->codeFor($inviter);
    refService()->link($a, $code, refNow());
    $rb = refService()->link($b, $code, refNow()->addMinute());
    $rc = refService()->link($c, $code, refNow()->addMinutes(2));
    $rb?->forceFill(['status' => 'activated', 'activated_at' => refNow()])->save();
    $rc?->forceFill(['status' => 'rewarded', 'activated_at' => refNow(), 'rewarded_at' => refNow()])->save();
    /** @var Learner $existing */
    $existing = Learner::factory()->create()->fresh();
    refService()->link($existing, $code, refNow()); // rejected (existing learner): not listed

    $overview = refService()->overview($inviter);

    expect($overview->code)->toBe($code)
        ->and($overview->link)->toBe("https://t.me/LexiFlowBot?start=ref_{$code}")
        ->and($overview->activationDays)->toBe(1)
        ->and($overview->activationReviewsPerDay)->toBe(10)
        ->and($overview->activationWindowDays)->toBe(14)
        ->and($overview->milestoneDays)->toBe(7)
        ->and($overview->invited)->toBe(3)
        ->and($overview->activated)->toBe(2)
        ->and($overview->counted)->toBe(1)
        ->and($overview->milestones)->toBe([
            ['friends' => 3, 'days' => 7, 'reached_at' => null, 'rewarded' => false],
            ['friends' => 6, 'days' => 7, 'reached_at' => null, 'rewarded' => false],
        ])
        ->and($overview->next)->toBe(['friends' => 3, 'remaining' => 2])
        ->and($overview->referrals->pluck('referee_learner_id')->all())->toBe([$c->id, $b->id, $a->id]);
});

it('shows a reached milestone with its date, rewarded once settled, and the next one with what is left', function (): void {
    $inviter = Learner::factory()->create();
    $now = refNow('2026-10-25 09:00:00');
    Referral::factory()->count(4)->rewarded($now)->create(['referrer_learner_id' => $inviter->id]);
    $owed = ReferralMilestone::factory()->create(['referrer_learner_id' => $inviter->id, 'milestone' => 3, 'reached_at' => $now]);

    $overview = refService()->overview($inviter);
    expect($overview->counted)->toBe(4)
        ->and($overview->milestones[0]['reached_at']?->toIso8601String())->toBe($now->toIso8601String())
        ->and($overview->milestones[0])->toMatchArray(['friends' => 3, 'days' => 7, 'rewarded' => false])
        ->and($overview->next)->toBe(['friends' => 6, 'remaining' => 2]);

    refService()->grantMilestone($owed->id, $now);
    ReferralMilestone::factory()->granted('perpetual')->create(['referrer_learner_id' => $inviter->id, 'milestone' => 6, 'days' => 5]);
    $overview = refService()->overview($inviter);
    expect($overview->milestones[0]['rewarded'])->toBeTrue()
        // A settled milestone shows the days it was reached with, a skipped one counts as rewarded (there was nothing to add).
        ->and($overview->milestones[1])->toMatchArray(['friends' => 6, 'days' => 5, 'rewarded' => true])
        ->and($overview->next)->toBeNull();
});

// --- recovery sweep ------------------------------------------------------------

it('re-queues the jobs of referrals left activated and of milestones left owed, and nothing else', function (): void {
    Queue::fake();
    $now = refNow();
    $old = $now->subMinutes(30);

    $activated = Referral::factory()->create(['status' => 'activated', 'activated_at' => $old]);
    $owed = ReferralMilestone::factory()->create(['reached_at' => $old]);
    // Not to be touched: fresh (in flight), flagged, counted, pending, rejected, a settled milestone, a fresh milestone.
    Referral::factory()->create(['status' => 'activated', 'activated_at' => $now->subMinute()]);
    Referral::factory()->flagged()->create(['status' => 'activated', 'activated_at' => $old]);
    Referral::factory()->rewarded($old)->create();
    Referral::factory()->create(['status' => 'pending']);
    Referral::factory()->create(['status' => 'rejected', 'reject_reason' => 'self']);
    ReferralMilestone::factory()->granted()->create(['reached_at' => $old]);
    ReferralMilestone::factory()->create(['reached_at' => $now->subMinute()]);

    expect(refService()->recoverStuck($now))->toBe(2);

    expect(Queue::pushed(RewardReferralJob::class)->map(fn (RewardReferralJob $j): int => $j->referralId)->all())->toBe([$activated->id])
        ->and(Queue::pushed(GrantReferralMilestoneJob::class)->map(fn (GrantReferralMilestoneJob $j): int => $j->milestoneId)->all())->toBe([$owed->id]);
});

it('recovers only rows within the recovery window: a stuck row older than it is left alone', function (): void {
    Queue::fake();
    config(['referrals.recover_window_days' => 14]);
    $now = refNow();

    $fresh = Referral::factory()->create(['status' => 'activated', 'activated_at' => $now->subDays(13)]);
    Referral::factory()->create(['status' => 'activated', 'activated_at' => $now->subDays(15)]);
    $owed = ReferralMilestone::factory()->create(['reached_at' => $now->subDays(13)]);
    ReferralMilestone::factory()->create(['reached_at' => $now->subDays(15)]);

    expect(refService()->recoverStuck($now))->toBe(2);
    expect(Queue::pushed(RewardReferralJob::class)->map(fn (RewardReferralJob $j): int => $j->referralId)->all())->toBe([$fresh->id])
        ->and(Queue::pushed(GrantReferralMilestoneJob::class)->map(fn (GrantReferralMilestoneJob $j): int => $j->milestoneId)->all())->toBe([$owed->id]);
});

it('recovers a lost job end to end from the scheduler command, and a second run finds nothing', function (): void {
    $inviter = refInviter();
    Referral::factory()->count(2)->rewarded(now()->subDays(2))->create(['referrer_learner_id' => $inviter->id]);
    $referral = refPending($inviter, 9703);
    $referral->forceFill(['status' => 'activated', 'activated_at' => now()->subHour()])->save();
    $owed = ReferralMilestone::factory()->create(['referrer_learner_id' => refInviter('OWED234')->id, 'reached_at' => now()->subHour()]);

    $this->artisan('referrals:recover')->expectsOutput('Referral reward jobs queued: 2')->assertSuccessful();

    // The queue is sync in tests: the friend was counted (the 3rd: a milestone), the owed milestone was granted.
    expect($referral->fresh()?->status)->toBe('rewarded')
        ->and(refMilestones($inviter))->toBe([['milestone' => 3, 'granted' => true, 'subscription_id' => refRewards($inviter)->sole()->id, 'skipped' => null]])
        ->and($owed->fresh()?->isSettled())->toBeTrue()
        ->and($owed->fresh()?->subscription_id)->not->toBeNull();
    $this->artisan('referrals:recover')->expectsOutput('Referral reward jobs queued: 0')->assertSuccessful();
});

// --- the after-commit hook -----------------------------------------------------

it('does not fail the grade when the referral evaluation throws after commit', function (): void {
    config(['referrals.activation.reviews_per_day' => 1]);
    // No `referrals` table: the evaluation query fails after the commit (the milestone book goes first: it references it).
    Schema::drop('referral_milestones');
    Schema::drop('referrals');
    Exceptions::fake();
    $learner = Learner::factory()->create(['timezone' => 'Asia/Tashkent']);

    $row = DB::transaction(fn () => app(DailyStatsService::class)->recordReview($learner, 4, 500, false, refNow()));

    // The grade itself is committed and returned; the error is only reported. (No further queries here:
    // on PostgreSQL the failed statement aborted the test's outer transaction.)
    expect($row->reviews_count)->toBe(1);
    Exceptions::assertReported(QueryException::class);
});
