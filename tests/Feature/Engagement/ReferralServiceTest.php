<?php

declare(strict_types=1);

use App\Domain\Engagement\Services\ReferralCodeGenerator;
use App\Domain\Engagement\Services\ReferralService;
use App\Domain\Learners\Services\DailyStatsService;
use App\Domain\Learners\Services\LearnerRegistrar;
use App\Jobs\RewardReferralJob;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\ProductEvent;
use App\Models\Referral;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/*
 * ReferralService (docs/13 §1.5, §2.4, §7.1): codes, linking, activation, the reward and its
 * idempotency, the inviter's caps, the burst flag, the admin actions.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'telegram.bot_username' => 'LexiFlowBot',
        'referrals.reward_days' => 7,
        'referrals.activation' => ['days' => 3, 'reviews_per_day' => 10, 'window_days' => 14],
        'referrals.max_rewards_per_30d' => 10,
        'referrals.max_rewards_lifetime' => 50,
        'referrals.flag_links_per_day' => 20,
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

/** A learner created "by this /start" — the registrar's fresh row has `wasRecentlyCreated`. */
function refNewLearner(int $tgId, string $tz = 'Asia/Tashkent'): Learner
{
    $learner = app(LearnerRegistrar::class)->fromTelegramUser(['id' => $tgId, 'first_name' => 'New'.$tgId]);
    $learner->forceFill(['timezone' => $tz])->save();

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

function refDay(Learner $learner, string $date, int $reviews): void
{
    LearnerDailyStats::factory()->create(['learner_id' => $learner->id, 'date' => $date, 'reviews_count' => $reviews]);
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

it('activates only with enough active days inside the window, counted in the learner local days', function (): void {
    Queue::fake();
    $inviter = refInviter();
    // Linked at 2026-10-22 20:30 UTC = 2026-10-23 01:30 in Tashkent: the window is Oct 23 .. Nov 5 (14 local days).
    $referral = refPending($inviter, 9201, '2026-10-22 20:30:00');
    $referee = $referral->referee;
    expect($referee)->not->toBeNull();
    /** @var Learner $referee */

    // Two qualifying days + one day with 9 reviews: not yet.
    refDay($referee, '2026-10-23', 10);
    refDay($referee, '2026-10-24', 9);
    refDay($referee, '2026-10-25', 25);
    refService()->evaluate($referee, refNow('2026-10-25 12:00:00'));
    expect($referral->fresh()?->status)->toBe('pending');
    Queue::assertNothingPushed();

    // A day before the window (UTC Oct 22 is before the local link day) does not count either.
    refDay($referee, '2026-10-22', 50);
    refService()->evaluate($referee, refNow('2026-10-25 12:00:00'));
    expect($referral->fresh()?->status)->toBe('pending');

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
        ->and($fresh?->meta['active_days'])->toBe(['2026-10-23', '2026-10-25', '2026-11-05']);
    Queue::assertPushed(RewardReferralJob::class, fn (RewardReferralJob $job): bool => $job->referralId === $referral->id);
    expect(ksorted(ProductEvent::query()->where('name', 'referral.activated')->sole()->props))
        ->toBe(ksorted(['referral_id' => $referral->id, 'referrer_learner_id' => $inviter->id, 'active_days' => 3]));
});

it('does nothing for a learner without a pending referral and activates only once', function (): void {
    Queue::fake();
    $inviter = refInviter();
    $referral = refPending($inviter, 9202);
    /** @var Learner $referee */
    $referee = $referral->referee;
    foreach (['2026-10-22', '2026-10-23', '2026-10-24'] as $d) {
        refDay($referee, $d, 10);
    }

    refService()->evaluate(Learner::factory()->create(), refNow());
    refService()->evaluate($referee, refNow('2026-10-24 12:00:00'));
    refService()->evaluate($referee, refNow('2026-10-25 12:00:00'));

    expect(Referral::query()->where('status', 'activated')->count())->toBe(1);
    Queue::assertPushed(RewardReferralJob::class, 1);
});

it('is driven by the grading flow: the 10th grade of the 3rd day activates after commit and rewards on the sync queue', function (): void {
    $inviter = refInviter();
    $referral = refPending($inviter, 9203, '2026-10-22 06:00:00');
    /** @var Learner $referee */
    $referee = $referral->referee;
    $stats = app(DailyStatsService::class);

    foreach (['2026-10-22', '2026-10-23', '2026-10-24'] as $day) {
        for ($i = 1; $i <= 10; $i++) {
            DB::transaction(fn () => $stats->recordReview($referee, 4, 1000, false, refNow("{$day} 08:00:00")));
            if ($day !== '2026-10-24' || $i < 10) {
                expect($referral->fresh()?->status)->toBe('pending', "{$day} #{$i}");
            }
        }
    }

    $fresh = $referral->fresh();
    expect($fresh?->status)->toBe('rewarded')
        ->and(Subscription::query()->where('subscriber_id', $referee->id)->where('provider', 'reward')->count())->toBe(1)
        ->and(Subscription::query()->where('subscriber_id', $inviter->id)->where('provider', 'reward')->count())->toBe(1);
});

// --- reward --------------------------------------------------------------------

it('rewards both sides exactly once: 7 days of Pro each, meta.rewards, one referral.rewarded event; a repeat is a no-op', function (): void {
    $inviter = refInviter();
    $referral = refPending($inviter, 9301);
    $referral->forceFill(['status' => 'activated', 'activated_at' => refNow()])->save();
    $now = refNow('2026-10-25 09:00:00');

    refService()->reward($referral, $now);
    refService()->reward($referral, $now->addMinute());
    (new RewardReferralJob($referral->id))->handle(refService());

    $fresh = $referral->fresh();
    $refereeSub = Subscription::query()->where('subscriber_id', $referral->referee_learner_id)->sole();
    $inviterSub = Subscription::query()->where('subscriber_id', $inviter->id)->sole();

    expect($fresh?->status)->toBe('rewarded')
        ->and($fresh?->rewarded_at?->toIso8601String())->toBe($now->toIso8601String())
        ->and($refereeSub->provider)->toBe('reward')
        ->and($refereeSub->provider_ref)->toBe('referral:'.$referral->id)
        ->and($refereeSub->current_period_ends_at?->toIso8601String())->toBe($now->addDays(7)->toIso8601String())
        ->and($inviterSub->current_period_ends_at?->toIso8601String())->toBe($now->addDays(7)->toIso8601String())
        ->and(ksorted($fresh?->meta ?? []))->toBe(ksorted([
            'rewards' => [
                'referee' => ['subscription_id' => $refereeSub->id, 'skipped' => null],
                'referrer' => ['subscription_id' => $inviterSub->id, 'skipped' => null],
            ],
            'reward_subscription_ids' => [$refereeSub->id, $inviterSub->id],
        ]))
        ->and(ProductEvent::query()->where('name', 'referral.rewarded')->count())->toBe(1)
        ->and(ProductEvent::query()->where('name', 'subscription.granted')->count())->toBe(2);
});

it('retries a partial failure: when the inviter grant dies, the repeat grants only the inviter', function (): void {
    $inviter = refInviter();
    $referral = refPending($inviter, 9302);
    $referral->forceFill(['status' => 'activated', 'activated_at' => refNow()])->save();

    // The second transaction (the inviter's) fails inside the subscription write.
    $poison = true;
    Subscription::creating(function (Subscription $s) use (&$poison, $inviter): void {
        if ($poison && $s->subscriber_id === $inviter->id) {
            throw new RuntimeException('simulated outage');
        }
    });

    expect(fn () => refService()->reward($referral, refNow()))->toThrow(RuntimeException::class);

    $fresh = $referral->fresh();
    expect($fresh?->status)->toBe('rewarded')
        ->and($fresh?->rewardOf('referee'))->not->toBeNull()
        ->and($fresh?->rewardOf('referrer'))->toBeNull()
        ->and(Subscription::query()->where('subscriber_id', $inviter->id)->count())->toBe(0)
        ->and(ProductEvent::query()->where('name', 'referral.rewarded')->count())->toBe(0);

    $poison = false;
    (new RewardReferralJob($referral->id))->handle(refService());

    $fresh = $referral->fresh();
    expect($fresh?->fullyRewarded())->toBeTrue()
        ->and(Subscription::query()->where('subscriber_id', $referral->referee_learner_id)->count())->toBe(1)
        ->and(Subscription::query()->where('subscriber_id', $inviter->id)->count())->toBe(1)
        ->and(ProductEvent::query()->where('name', 'referral.rewarded')->count())->toBe(1);
});

it('skips the reward of a side with perpetual Pro and records why', function (): void {
    $inviter = refInviter();
    Subscription::factory()->create(['subscriber_id' => $inviter->id, 'current_period_ends_at' => null]);
    $referral = refPending($inviter, 9303);
    $referral->forceFill(['status' => 'activated', 'activated_at' => refNow()])->save();

    refService()->reward($referral, refNow());

    $fresh = $referral->fresh();
    expect($fresh?->rewardOf('referrer'))->toBe(['subscription_id' => null, 'skipped' => 'perpetual'])
        ->and($fresh?->rewardOf('referee')['subscription_id'] ?? null)->not->toBeNull()
        ->and(Subscription::query()->where('subscriber_id', $inviter->id)->count())->toBe(1)
        ->and($fresh?->meta['reward_subscription_ids'])->toHaveCount(1)
        ->and(ProductEvent::query()->where('name', 'referral.rewarded')->sole()->props['referrer_skipped'])->toBe('perpetual');
});

it('extends a live subscription of a side instead of opening a second one', function (): void {
    $inviter = refInviter();
    $live = Subscription::factory()->create(['subscriber_id' => $inviter->id, 'provider' => 'payme', 'current_period_ends_at' => refNow()->addDays(10)]);
    $referral = refPending($inviter, 9304);
    $referral->forceFill(['status' => 'activated', 'activated_at' => refNow()])->save();

    refService()->reward($referral, refNow());

    expect(Subscription::query()->where('subscriber_id', $inviter->id)->count())->toBe(1)
        ->and($live->fresh()?->current_period_ends_at?->toIso8601String())->toBe(refNow()->addDays(17)->toIso8601String())
        ->and($referral->fresh()?->rewardOf('referrer'))->toBe(['subscription_id' => $live->id, 'skipped' => null]);
});

it('does not reward a pending or rejected referral', function (): void {
    $inviter = refInviter();
    $pending = refPending($inviter, 9305);

    refService()->reward($pending, refNow());
    expect($pending->fresh()?->status)->toBe('pending')
        ->and(Subscription::query()->count())->toBe(0);
});

it('rejects the reward of a capped inviter: 30-day cap', function (): void {
    config(['referrals.max_rewards_per_30d' => 2, 'referrals.max_rewards_lifetime' => 50]);
    $inviter = refInviter();
    $now = refNow('2026-10-25 09:00:00');
    Referral::factory()->rewarded($now->subDays(29))->create(['referrer_learner_id' => $inviter->id]);
    Referral::factory()->rewarded($now->subDays(5))->create(['referrer_learner_id' => $inviter->id]);
    // One more than 30 days old does not count towards the rolling window.
    Referral::factory()->rewarded($now->subDays(31))->create(['referrer_learner_id' => $inviter->id]);

    $referral = refPending($inviter, 9306);
    $referral->forceFill(['status' => 'activated', 'activated_at' => $now])->save();

    refService()->reward($referral, $now);

    $fresh = $referral->fresh();
    expect($fresh?->status)->toBe('rejected')
        ->and($fresh?->reject_reason)->toBe('referrer_capped')
        ->and(Subscription::query()->count())->toBe(0)
        ->and(ProductEvent::query()->where('name', 'referral.rejected')->where('props->reason', 'referrer_capped')->count())->toBe(1);
});

it('allows the reward right below the 30-day cap and refuses at the lifetime cap', function (): void {
    config(['referrals.max_rewards_per_30d' => 2, 'referrals.max_rewards_lifetime' => 3]);
    $inviter = refInviter();
    $now = refNow('2026-10-25 09:00:00');
    Referral::factory()->rewarded($now->subDays(5))->create(['referrer_learner_id' => $inviter->id]);
    Referral::factory()->rewarded($now->subDays(60))->create(['referrer_learner_id' => $inviter->id]);

    // 1 in the window (< 2), 2 lifetime (< 3): rewarded.
    $ok = refPending($inviter, 9307);
    $ok->forceFill(['status' => 'activated', 'activated_at' => $now])->save();
    refService()->reward($ok, $now);
    expect($ok->fresh()?->status)->toBe('rewarded');

    // Now 3 lifetime: the next one is refused even though the 30-day window would still allow it after 30 days.
    $capped = refPending($inviter, 9308);
    $capped->forceFill(['status' => 'activated', 'activated_at' => $now])->save();
    refService()->reward($capped, $now->addDays(40));
    expect($capped->fresh()?->status)->toBe('rejected')
        ->and($capped->fresh()?->reject_reason)->toBe('referrer_capped');
});

it('parks the reward of a flagged referral until an admin clears the flag, which is audited', function (): void {
    $inviter = refInviter();
    $referral = refPending($inviter, 9309);
    $referral->forceFill(['status' => 'activated', 'activated_at' => refNow(), 'flagged_at' => refNow()])->save();

    refService()->reward($referral, refNow());
    expect($referral->fresh()?->status)->toBe('activated')
        ->and(Subscription::query()->count())->toBe(0);

    $admin = User::factory()->create();
    expect(refService()->unflag($referral, $admin, '10.0.0.1'))->toBeTrue()
        ->and($referral->flagged_at)->toBeNull();

    // The sync queue ran the reward job at once.
    expect($referral->fresh()?->status)->toBe('rewarded')
        ->and(Subscription::query()->count())->toBe(2);

    $audit = AuditLog::query()->where('action', 'referral.unflagged')->sole();
    expect($audit->user_id)->toBe($admin->id)
        ->and($audit->entity_type)->toBe(Referral::class)
        ->and($audit->entity_id)->toBe($referral->id)
        ->and($audit->ip)->toBe('10.0.0.1')
        ->and($audit->meta['referrer_learner_id'])->toBe($inviter->id);

    // Not flagged any more: nothing to clear.
    expect(refService()->unflag($referral, $admin))->toBeFalse()
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
        ->and(Subscription::query()->count())->toBe(0);
});

// --- overview --------------------------------------------------------------------

it('builds the inviter overview: code, link, counts and the invited names only', function (): void {
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
        ->and($overview->rewardDays)->toBe(7)
        ->and($overview->invited)->toBe(3)
        ->and($overview->activated)->toBe(2)
        ->and($overview->rewarded)->toBe(1)
        ->and($overview->referrals->pluck('referee_learner_id')->all())->toBe([$c->id, $b->id, $a->id]);
});

// --- deleted / blocked sides ---------------------------------------------------

it('grants no Pro to a deleted or an administrator-blocked side and records why; the other side is rewarded', function (): void {
    $inviter = refInviter();
    $referral = refPending($inviter, 9701);
    $referral->forceFill(['status' => 'activated', 'activated_at' => refNow()])->save();
    $referral->referee?->delete(); // soft delete
    $inviter->forceFill(['blocked_at' => refNow()])->save();

    refService()->reward($referral, refNow());

    $fresh = $referral->fresh();
    expect($fresh?->status)->toBe('rewarded')
        ->and($fresh?->rewardOf('referee'))->toBe(['subscription_id' => null, 'skipped' => 'learner_deleted'])
        ->and($fresh?->rewardOf('referrer'))->toBe(['subscription_id' => null, 'skipped' => 'learner_blocked'])
        ->and($fresh?->fullyRewarded())->toBeTrue()
        ->and(Subscription::query()->where('provider', 'reward')->count())->toBe(0);

    // A normal pair next to it still gets Pro.
    $other = refPending(refInviter('INVITE3'), 9702);
    $other->forceFill(['status' => 'activated', 'activated_at' => refNow()])->save();
    refService()->reward($other, refNow());
    expect(Subscription::query()->where('provider', 'reward')->count())->toBe(2);
});

// --- recovery sweep ------------------------------------------------------------

it('re-queues the reward job of referrals left activated or half-rewarded, and nothing else', function (): void {
    Queue::fake();
    $now = refNow();
    $old = $now->subMinutes(30);

    $activated = Referral::factory()->create(['status' => 'activated', 'activated_at' => $old]);
    $half = Referral::factory()->rewarded($old)->create(['meta' => ['rewards' => [Referral::SIDE_REFEREE => ['subscription_id' => null, 'skipped' => 'perpetual']]]]);
    $noRewards = Referral::factory()->rewarded($old)->create(['meta' => []]);
    // Not to be touched: fresh (in flight), flagged, fully rewarded, pending, rejected.
    Referral::factory()->create(['status' => 'activated', 'activated_at' => $now->subMinute()]);
    Referral::factory()->flagged()->create(['status' => 'activated', 'activated_at' => $old]);
    Referral::factory()->rewarded($old)->create();
    Referral::factory()->create(['status' => 'pending']);
    Referral::factory()->create(['status' => 'rejected', 'reject_reason' => 'self']);

    expect(refService()->recoverStuck($now))->toBe(3);

    $queued = Queue::pushed(RewardReferralJob::class)->map(fn (RewardReferralJob $j): int => $j->referralId)->sort()->values()->all();
    expect($queued)->toBe(collect([$activated->id, $half->id, $noRewards->id])->sort()->values()->all());
});

it('recovers a lost job end to end from the scheduler command, and a second run finds nothing', function (): void {
    $inviter = refInviter();
    $referral = refPending($inviter, 9703);
    $referral->forceFill(['status' => 'activated', 'activated_at' => now()->subHour()])->save();

    $this->artisan('referrals:recover')->expectsOutput('Referral reward jobs queued: 1')->assertSuccessful();

    // The queue is sync in tests: the reward has been granted by the job.
    expect($referral->fresh()?->status)->toBe('rewarded')
        ->and($referral->fresh()?->fullyRewarded())->toBeTrue();
    $this->artisan('referrals:recover')->expectsOutput('Referral reward jobs queued: 0')->assertSuccessful();
});

// --- the after-commit hook -----------------------------------------------------

it('does not fail the grade when the referral evaluation throws after commit', function (): void {
    config(['referrals.activation.reviews_per_day' => 1]);
    // No `referrals` table: the evaluation query fails after the commit.
    Schema::drop('referrals');
    Exceptions::fake();
    $learner = Learner::factory()->create(['timezone' => 'Asia/Tashkent']);

    $row = DB::transaction(fn () => app(DailyStatsService::class)->recordReview($learner, 4, 500, false, refNow()));

    // The grade itself is committed and returned; the error is only reported. (No further queries here:
    // on PostgreSQL the failed statement aborted the test's outer transaction.)
    expect($row->reviews_count)->toBe(1);
    Exceptions::assertReported(QueryException::class);
});
