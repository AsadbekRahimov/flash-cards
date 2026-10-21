<?php

declare(strict_types=1);

/*
 * AchievementService (docs/13 §1.5, §7.4): every key is unlocked exactly once, the counting keys follow the
 * committed facts, the single-event keys are hooked after commit, and a card DM is queued once per key.
 */

use App\Domain\Engagement\Enums\AchievementKey;
use App\Domain\Engagement\Services\AchievementService;
use App\Domain\Engagement\Services\FriendshipService;
use App\Domain\Engagement\Services\LeagueService;
use App\Domain\Learners\Services\DailyStatsService;
use App\Jobs\SendAchievementJob;
use App\Models\Achievement;
use App\Models\Friendship;
use App\Models\LeagueGroup;
use App\Models\LeagueMember;
use App\Models\LeagueWeek;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\ProductEvent;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['achievements.enabled' => true, 'leagues.timezone' => 'Asia/Tashkent', 'telegram.bot_username' => 'LexiFlowBot']);
    $this->travelTo(CarbonImmutable::parse('2026-10-21 05:00:00', 'UTC'));
    Queue::fake();
});

function achSvc(): AchievementService
{
    return app(AchievementService::class);
}

/** @return list<string> */
function achKeys(Learner $learner): array
{
    return Achievement::query()->where('learner_id', $learner->id)->orderBy('key')->pluck('key')->all();
}

function achEvents(string $name): int
{
    return ProductEvent::query()->where('name', $name)->count();
}

it('unlocks a streak key when the best streak reaches its threshold, and not before', function (): void {
    $learner = Learner::factory()->create(['streak_current' => 6, 'streak_best' => 6]);
    expect(achSvc()->evaluate($learner, now()->toImmutable()))->toBe([]);

    $learner->forceFill(['streak_current' => 7, 'streak_best' => 7])->save();
    $unlocked = achSvc()->evaluate($learner, now()->toImmutable());

    expect($unlocked)->toHaveCount(1)
        ->and($unlocked[0]->key)->toBe('streak_7')
        ->and($unlocked[0]->meta)->toBe(['value' => 7])
        ->and(achKeys($learner))->toBe(['streak_7'])
        ->and(achEvents('achievement.unlocked'))->toBe(1);
    Queue::assertPushed(SendAchievementJob::class, 1);
});

it('keeps a streak achievement after the streak broke (it is about the best one)', function (): void {
    $learner = Learner::factory()->create(['streak_current' => 0, 'streak_best' => 31]);

    achSvc()->evaluate($learner, now()->toImmutable());

    expect(achKeys($learner))->toBe(['streak_30', 'streak_7']);
});

it('announces only the highest key of a family when several are reached at once, but stores all', function (): void {
    $learner = Learner::factory()->create(['streak_current' => 120, 'streak_best' => 120]);

    achSvc()->evaluate($learner, now()->toImmutable());

    expect(achKeys($learner))->toBe(['streak_100', 'streak_30', 'streak_7']);
    Queue::assertPushed(SendAchievementJob::class, 1);
    $top = Achievement::query()->where('learner_id', $learner->id)->where('key', 'streak_100')->firstOrFail();
    Queue::assertPushed(SendAchievementJob::class, fn (SendAchievementJob $job): bool => $job->achievementId === $top->id);
});

it('unlocks the words keys from the learned cards (repetitions >= 1)', function (): void {
    $learner = Learner::factory()->create();
    WordRepetition::factory()->count(100)->create(['learner_id' => $learner->id, 'repetitions' => 1]);
    WordRepetition::factory()->count(5)->create(['learner_id' => $learner->id, 'repetitions' => 0]);

    $unlocked = achSvc()->evaluate($learner, now()->toImmutable());

    expect(array_map(fn (Achievement $a): string => $a->key, $unlocked))->toBe(['words_100'])
        ->and($unlocked[0]->meta)->toBe(['value' => 100]);
});

it('unlocks a key exactly once: a repeat or a parallel claim changes nothing and announces nothing', function (): void {
    $learner = Learner::factory()->create(['streak_current' => 7, 'streak_best' => 7]);

    $first = achSvc()->unlock($learner, AchievementKey::Streak7, now()->toImmutable());
    // The loser of a race hits the unique (learner_id, key): the conditional insert affects no row.
    $second = achSvc()->unlock($learner, AchievementKey::Streak7, now()->toImmutable());
    $third = achSvc()->evaluate($learner, now()->toImmutable());

    expect($first)->toBeInstanceOf(Achievement::class)
        ->and($second)->toBeNull()
        ->and($third)->toBe([])
        ->and(Achievement::query()->count())->toBe(1)
        ->and(achEvents('achievement.unlocked'))->toBe(1);
    Queue::assertPushed(SendAchievementJob::class, 1);
});

it('does nothing when the feature is off or the learner is blocked', function (): void {
    $learner = Learner::factory()->create(['streak_current' => 7, 'streak_best' => 7]);
    $blocked = Learner::factory()->create(['streak_current' => 7, 'streak_best' => 7, 'blocked_at' => now()]);

    achSvc()->evaluate($blocked, now()->toImmutable());
    config(['achievements.enabled' => false]);
    achSvc()->evaluate($learner, now()->toImmutable());
    achSvc()->onFirstReview($learner, now()->toImmutable());

    expect(Achievement::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('unlocks first_session on the first new card of a graded review, after commit', function (): void {
    $learner = Learner::factory()->create();

    app(DailyStatsService::class)->recordReview($learner, 4, 1200, true, now()->toImmutable());

    expect(achKeys($learner))->toBe(['first_session']);

    // A second graded card changes nothing.
    app(DailyStatsService::class)->recordReview($learner, 4, 1200, true, now()->toImmutable());
    expect(Achievement::query()->count())->toBe(1);
});

it('looks at the streak and the words once the daily goal is met', function (): void {
    $learner = Learner::factory()->create(['daily_goal' => 1, 'streak_current' => 6, 'streak_best' => 6]);
    WordRepetition::factory()->count(100)->create(['learner_id' => $learner->id, 'repetitions' => 1]);

    // Meeting the goal registers the achievements' look after the streak's own hook (both after commit).
    app(DailyStatsService::class)->recordReview($learner, 4, 1200, false, now()->toImmutable());

    expect(achKeys($learner))->toBe(['first_session', 'words_100']);
});

it('unlocks streak_7 when the goal met today extends a six-day streak to seven', function (): void {
    $learner = Learner::factory()->create(['daily_goal' => 1, 'streak_current' => 6, 'streak_best' => 6, 'streak_last_date' => '2026-10-20']);

    app(DailyStatsService::class)->recordReview($learner, 4, 1200, false, now()->toImmutable());

    expect($learner->fresh()?->streak_current)->toBe(7)
        ->and(achKeys($learner))->toContain('streak_7');
});

it('does not run inside the review transaction: a rolled back grade unlocks nothing', function (): void {
    $learner = Learner::factory()->create();

    try {
        DB::transaction(function () use ($learner): void {
            app(DailyStatsService::class)->recordReview($learner, 4, 1200, true, now()->toImmutable());
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect(Achievement::query()->count())->toBe(0);
});

it('gives first_friend to both sides of an accepted friendship, once', function (): void {
    [$a, $b] = [Learner::factory()->create(), Learner::factory()->create()];
    $request = app(FriendshipService::class)->request($a, $b);

    app(FriendshipService::class)->accept($b, $request->id);
    app(FriendshipService::class)->accept($b, $request->id); // the repeat is a no-op

    expect(achKeys($a))->toBe(['first_friend'])
        ->and(achKeys($b))->toBe(['first_friend'])
        ->and(achEvents('achievement.unlocked'))->toBe(2);
});

it('gives league_top1 to the winners of the groups when a week is closed', function (): void {
    config(['leagues.promote' => 2, 'leagues.demote' => 2]);
    $week = LeagueWeek::factory()->startingOn('2026-10-12')->create();
    $group = LeagueGroup::factory()->create(['league_week_id' => $week->id, 'tier' => 0]);
    $idle = LeagueGroup::factory()->create(['league_week_id' => $week->id, 'tier' => 1]);
    $winner = Learner::factory()->create();
    $second = Learner::factory()->create();
    $idleOne = Learner::factory()->create();
    foreach ([[$winner, 60, $group], [$second, 30, $group], [$idleOne, 0, $idle]] as [$learner, $reviews, $g]) {
        LeagueMember::factory()->create(['league_group_id' => $g->id, 'learner_id' => $learner->id]);
        if ($reviews > 0) {
            LearnerDailyStats::factory()->create(['learner_id' => $learner->id, 'date' => '2026-10-14', 'reviews_count' => $reviews, 'correct_count' => 0, 'new_words_count' => 0, 'goal_met_at' => null]);
        }
    }

    expect(app(LeagueService::class)->closeWeek($week, CarbonImmutable::parse('2026-10-18 19:05:00', 'UTC')))->toBeTrue();

    expect(achKeys($winner))->toBe(['league_top1'])
        ->and(achKeys($second))->toBe([])
        ->and(achKeys($idleOne))->toBe([]) // first place with zero points is not a win
        ->and(Achievement::query()->where('key', 'league_top1')->firstOrFail()->meta)->toBe(['league_week_id' => $week->id]);
});

it('marks an achievement as shared once, with one event, and refuses a key that is not unlocked', function (): void {
    $learner = Learner::factory()->create();
    achSvc()->unlock($learner, AchievementKey::Streak7, now()->toImmutable());

    expect(achSvc()->markShared($learner, AchievementKey::Streak7, now()->toImmutable()))->toBeTrue()
        ->and(achSvc()->markShared($learner, AchievementKey::Streak7, now()->addHour()->toImmutable()))->toBeTrue()
        ->and(achSvc()->markShared($learner, AchievementKey::Words100, now()->toImmutable()))->toBeFalse()
        ->and(achEvents('achievement.shared'))->toBe(1)
        ->and(Achievement::query()->firstOrFail()->shared_at?->toDateTimeString())->toBe('2026-10-21 05:00:00');
});

it('builds the overview: the whole catalog, progress capped at the target, the invite link', function (): void {
    $learner = Learner::factory()->create(['streak_current' => 12, 'streak_best' => 40]);
    WordRepetition::factory()->count(3)->create(['learner_id' => $learner->id, 'repetitions' => 2]);
    achSvc()->unlock($learner, AchievementKey::Streak7, now()->toImmutable());

    $overview = achSvc()->overview($learner);
    $byKey = [];
    foreach ($overview->entries as $entry) {
        $byKey[$entry->key->value] = $entry;
    }

    expect($overview->entries)->toHaveCount(count(AchievementKey::cases()))
        ->and($byKey['streak_7']->unlocked())->toBeTrue()
        ->and($byKey['streak_7']->progressCurrent)->toBe(7)
        ->and($byKey['streak_30']->unlocked())->toBeFalse()
        ->and($byKey['streak_30']->progressCurrent)->toBe(30)
        ->and($byKey['words_100']->progressCurrent)->toBe(3)
        ->and($byKey['league_top1']->progressTarget)->toBeNull()
        ->and($overview->shareLink)->toStartWith('https://t.me/LexiFlowBot?start=ref_');
});

it('does not touch the friendship when the achievement hook fails', function (): void {
    [$a, $b] = [Learner::factory()->create(), Learner::factory()->create()];
    $request = app(FriendshipService::class)->request($a, $b);
    $this->mock(AchievementService::class)->shouldReceive('onFriendshipAccepted')->andThrow(new RuntimeException('boom'));

    app(FriendshipService::class)->accept($b, $request->id);

    expect(Friendship::query()->findOrFail($request->id)->status)->toBe('accepted');
});
