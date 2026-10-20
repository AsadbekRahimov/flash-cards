<?php

declare(strict_types=1);

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Services\OrgOverviewService;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * OrgOverviewService (docs/13 §6.3 dashboard): numbers come from learner_daily_stats of the learners
 * the user may see — the whole center for an owner, the teacher's groups for a teacher — and the
 * subscription is the owner's business only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->now = CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC');
    $this->org = Organization::factory()->create(['timezone' => 'Asia/Tashkent']);
    Subscription::factory()->forOrganization($this->org)->create(['current_period_ends_at' => $this->now->addDays(10), 'tier' => 'center_s', 'seats_limit' => 100]);

    $this->owner = User::factory()->create();
    OrganizationUser::factory()->create(['organization_id' => $this->org->id, 'user_id' => $this->owner->id]);
    $this->teacher = User::factory()->create();
    OrganizationUser::factory()->teacher()->create(['organization_id' => $this->org->id, 'user_id' => $this->teacher->id]);

    $this->g1 = TelegramGroup::factory()->create(['organization_id' => $this->org->id]);
    $this->g2 = TelegramGroup::factory()->create(['organization_id' => $this->org->id]);
    $this->teacher->telegramGroups()->attach($this->g1->id, ['is_primary' => true]);

    $this->l1 = Learner::factory()->memberOf($this->g1)->seatedIn($this->org)->create();
    $this->l2 = Learner::factory()->memberOf($this->g2)->seatedIn($this->org)->create();
    $this->l3 = Learner::factory()->seatedIn($this->org, 'waitlisted')->create();
    $stranger = Learner::factory()->create();

    $today = $this->now->setTimezone('Asia/Tashkent')->toDateString();
    LearnerDailyStats::factory()->create(['learner_id' => $this->l1->id, 'date' => $today, 'reviews_count' => 10, 'new_words_count' => 3]);
    LearnerDailyStats::factory()->create(['learner_id' => $this->l2->id, 'date' => $this->now->subDays(6)->toDateString(), 'reviews_count' => 4, 'new_words_count' => 0]);
    LearnerDailyStats::factory()->create(['learner_id' => $this->l2->id, 'date' => $this->now->subDays(7)->toDateString(), 'reviews_count' => 99, 'new_words_count' => 9]);
    LearnerDailyStats::factory()->create(['learner_id' => $this->l3->id, 'date' => $today, 'reviews_count' => 0, 'new_words_count' => 0]);
    LearnerDailyStats::factory()->create(['learner_id' => $stranger->id, 'date' => $today, 'reviews_count' => 50, 'new_words_count' => 5]);
});

it('sums the last 7 days of the center for the owner, with seats and subscription', function (): void {
    $overview = app(OrgOverviewService::class)->summary($this->owner, $this->org, $this->now);

    expect($overview->seats->seated)->toBe(2)
        ->and($overview->seats->waitlisted)->toBe(1)
        ->and($overview->seats->limit)->toBe(100)
        ->and($overview->groups)->toBe(2)
        ->and($overview->activeMembers)->toBe(3)
        ->and($overview->activeLearners7d)->toBe(2)
        ->and($overview->reviews7d)->toBe(14)
        ->and($overview->newWords7d)->toBe(3)
        ->and($overview->subscriptionTier)->toBe('center_s')
        ->and($overview->subscriptionStatus)->toBe('active')
        ->and($overview->subscriptionEndsAt?->toIso8601String())->toBe($this->now->addDays(10)->toIso8601String());
});

it('narrows a teacher to their groups and hides the subscription', function (): void {
    $overview = app(OrgOverviewService::class)->summary($this->teacher, $this->org, $this->now);

    expect($overview->groups)->toBe(1)
        ->and($overview->activeMembers)->toBe(1)
        ->and($overview->activeLearners7d)->toBe(1)
        ->and($overview->reviews7d)->toBe(10)
        ->and($overview->newWords7d)->toBe(3)
        ->and($overview->subscriptionTier)->toBeNull()
        ->and($overview->subscriptionStatus)->toBeNull()
        ->and($overview->seats->seated)->toBe(2);
});

it('reports no subscription and zero activity for a user who is not staff', function (): void {
    $overview = app(OrgOverviewService::class)->summary(User::factory()->create(), $this->org, $this->now);

    expect($overview->groups)->toBe(0)
        ->and($overview->activeMembers)->toBe(0)
        ->and($overview->activeLearners7d)->toBe(0)
        ->and($overview->subscriptionTier)->toBeNull();
});

it('treats an expired organization subscription as none', function (): void {
    Subscription::query()->update(['status' => 'expired']);

    $overview = app(OrgOverviewService::class)->summary($this->owner, $this->org, $this->now);

    expect($overview->subscriptionStatus)->toBeNull()
        ->and($overview->seats->sponsoring)->toBeFalse();
    expect(OrganizationRole::Owner->value)->toBe('owner');
});
