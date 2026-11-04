<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Services\CheckoutService;
use App\Domain\Billing\Services\PaymentApplier;
use App\Domain\Billing\Services\SubscriptionService;
use App\Domain\Engagement\Services\LeagueService;
use App\Domain\Learners\Services\LearnerPurgeService;
use App\Domain\Learners\Services\LearnerRegistrar;
use App\Domain\Operations\Support\PureCache;
use App\Domain\Organizations\Services\OrgOverviewService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Models\Achievement;
use App\Models\AuditLog;
use App\Models\Challenge;
use App\Models\ExamAnswer;
use App\Models\ExamResult;
use App\Models\Friendship;
use App\Models\ClassCaravan;
use App\Models\ClassCaravanMember;
use App\Models\GameBoosterUse;
use App\Models\GameCityProgress;
use App\Models\GameCoinLedgerEntry;
use App\Models\GameInventoryItem;
use App\Models\GameItem;
use App\Models\GameProfile;
use App\Models\GameRun;
use App\Models\GameRunAttempt;
use App\Models\GameStopProgress;
use App\Models\GameTicketLedgerEntry;
use App\Models\GameWallet;
use App\Models\GameWordMiss;
use App\Models\GroupMember;
use App\Models\LeagueMember;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\NotificationLog;
use App\Models\Offer;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Payment;
use App\Models\PaymentOrder;
use App\Models\PracticeSession;
use App\Models\ProductEvent;
use App\Models\PromoRedemption;
use App\Models\Referral;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\TrainingReview;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FakeBillingProvider;
use Tests\Support\FakeTelegramClient;

/*
 * Final deletion of soft-deleted learners (docs/03 §8 rule 2, docs/07 §10): after `learners.purge.after_days` the
 * learners row and, by FK cascade, every personal and learning record go; group exam answers / results and product
 * events are anonymised; payment records stay whole and still name the payer through the snapshot. Before the day
 * comes nothing is deleted and the learner's own /start brings the account back. Repeated runs change nothing.
 */

uses(RefreshDatabase::class);

const PURGE_NOW = '2026-11-03 03:30:00';

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse(PURGE_NOW, 'UTC'));
    config()->set('learners.purge.after_days', 30);
    config()->set('learners.purge.batch', 2);
    config()->set('learners.purge.max_per_run', 500);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/** Soft-deletes the learner `$daysAgo` days before PURGE_NOW. */
function purgeSoftDelete(Learner $learner, int $daysAgo): void
{
    $learner->delete();
    Learner::withTrashed()->whereKey($learner->id)->update(['deleted_at' => CarbonImmutable::now()->subDays($daysAgo)]);
}

/**
 * The game "Caravan" of the learner (docs/18 §12.2): a run with its attempt and a booster use, the wallet and its ledger, the profile
 * and the ticket ledger (bound to the run), the progress of a stop and of a city, a missed word, the bag, a member row of a class caravan
 * and a run of that caravan.
 */
function purgeGameRows(Learner $learner, TelegramGroup $group): void
{
    $run = GameRun::factory()->finished()->create(['learner_id' => $learner->id]);
    GameRunAttempt::factory()->create(['run_id' => $run->id, 'learner_id' => $learner->id]);
    $booster = GameItem::factory()->booster()->create();
    GameBoosterUse::factory()->create(['run_id' => $run->id, 'learner_id' => $learner->id, 'item_id' => $booster->id]);
    GameInventoryItem::factory()->create(['learner_id' => $learner->id, 'item_id' => $booster->id, 'qty' => 2]);
    GameWallet::factory()->withBalance(40)->create(['learner_id' => $learner->id]);
    GameCoinLedgerEntry::factory()->create(['learner_id' => $learner->id, 'amount' => 40, 'balance_after' => 40]);
    GameProfile::factory()->withTickets(2)->create(['learner_id' => $learner->id]);
    GameTicketLedgerEntry::factory()->create(['learner_id' => $learner->id, 'run_id' => $run->id]);
    GameStopProgress::factory()->cleared(3)->create(['learner_id' => $learner->id]);
    GameCityProgress::factory()->create(['learner_id' => $learner->id]);
    GameWordMiss::factory()->create(['learner_id' => $learner->id, 'misses' => 3]);

    $caravan = ClassCaravan::factory()->create(['telegram_group_id' => $group->id]);
    ClassCaravanMember::factory()->create(['class_caravan_id' => $caravan->id, 'learner_id' => $learner->id, 'runs' => 2, 'stars' => 5]);
    GameRun::factory()->finished()->create(['learner_id' => $learner->id, 'class_caravan_id' => $caravan->id]);
}

/**
 * A learner with a row in every table that refers to learners, a paid Pro (Stars through the fake provider) and an
 * organization seat. Returns the learner and the people around them.
 *
 * @return array{learner: Learner, friend: Learner, inviter: Learner, invitee: Learner, organization: Organization, payment: Payment, league_group_id: int}
 */
function purgeFixture(): array
{
    $stars = FakeBillingProvider::register(SubscriptionProvider::TelegramStars);
    $group = TelegramGroup::factory()->create();
    $organization = Organization::factory()->create();
    $learner = Learner::factory()->memberOf($group)->seatedIn($organization)->create([
        'telegram_user_id' => 777_001,
        'first_name' => 'Zoya',
        'last_name' => 'Karimova',
        'username' => 'zoya_k',
    ]);

    $friend = Learner::factory()->create();
    Friendship::factory()->between($learner, $friend)->create();
    Challenge::factory()->create(['challenger_id' => $learner->id, 'opponent_id' => $friend->id]);

    $inviter = Learner::factory()->create();
    Referral::factory()->create(['referrer_learner_id' => $inviter->id, 'referee_learner_id' => $learner->id]);
    $invitee = Learner::factory()->create(['referrer_learner_id' => $learner->id]);
    Referral::factory()->create(['referrer_learner_id' => $learner->id, 'referee_learner_id' => $invitee->id]);

    WordRepetition::factory()->create(['learner_id' => $learner->id]);
    TrainingReview::factory()->create(['learner_id' => $learner->id]);
    LearnerDailyStats::factory()->create(['learner_id' => $learner->id]);
    Achievement::factory()->create(['learner_id' => $learner->id]);
    $leagueMember = LeagueMember::factory()->create(['learner_id' => $learner->id]);
    PracticeSession::factory()->create(['learner_id' => $learner->id]);
    Offer::factory()->create(['learner_id' => $learner->id]);
    PromoRedemption::factory()->create(['learner_id' => $learner->id]);
    NotificationLog::factory()->create(['learner_id' => $learner->id]);
    ExamAnswer::factory()->create(['learner_id' => $learner->id]);
    ExamResult::factory()->create(['learner_id' => $learner->id]);
    app(ProductEventRecorder::class)->record(ProductEventName::OnboardingStarted, [], $learner);
    purgeGameRows($learner, $group);

    $order = app(CheckoutService::class)->create($learner, Plan::Pro, 'month', SubscriptionProvider::TelegramStars, null, 'twa');
    $payment = app(PaymentApplier::class)->applyCharge($stars->emitCharge($order));

    return [
        'learner' => $learner,
        'friend' => $friend,
        'inviter' => $inviter,
        'invitee' => $invitee,
        'organization' => $organization,
        'payment' => $payment,
        'league_group_id' => $leagueMember->league_group_id,
    ];
}

it('deletes a learner due for purge with all personal and learning data and keeps the payment records whole', function (): void {
    ['learner' => $learner, 'friend' => $friend, 'inviter' => $inviter, 'invitee' => $invitee, 'organization' => $organization, 'payment' => $payment, 'league_group_id' => $groupId] = purgeFixture();
    $id = $learner->id;
    expect(app(SubscriptionService::class)->liveFor($learner))->not->toBeNull();

    // Caches that must not outlive the learner (docs/19 §3 rule 5).
    PureCache::store()->put(LeagueService::CACHE_PREFIX.$groupId, ['stale'], 600);
    Cache::put(OrgOverviewService::versionKey($organization->id), 3);

    purgeSoftDelete($learner, 31);

    $report = app(LearnerPurgeService::class)->purgeDue();

    expect($report->purged)->toBe(1)
        ->and($report->skipped)->toBe(0)
        ->and($report->capped)->toBeFalse()
        ->and(Learner::withTrashed()->find($id))->toBeNull();

    // Personal and learning data: gone by cascade.
    foreach ([GroupMember::class, OrganizationMember::class, WordRepetition::class, TrainingReview::class, LearnerDailyStats::class, Achievement::class, LeagueMember::class, PracticeSession::class, Offer::class, PromoRedemption::class, NotificationLog::class] as $model) {
        expect($model::query()->where('learner_id', $id)->count())->toBe(0, $model);
    }
    // The game: every row of the learner goes; the caravan of the class and the items of the shop stay.
    foreach ([GameRun::class, GameRunAttempt::class, GameBoosterUse::class, GameInventoryItem::class, GameWallet::class, GameCoinLedgerEntry::class, GameProfile::class, GameTicketLedgerEntry::class, GameStopProgress::class, GameCityProgress::class, GameWordMiss::class, ClassCaravanMember::class] as $model) {
        expect($model::query()->where('learner_id', $id)->count())->toBe(0, $model);
    }
    expect(ClassCaravan::query()->count())->toBe(1)
        ->and(GameItem::query()->count())->toBe(1)
        ->and(DB::table('game_runs')->count())->toBe(0);
    expect(Friendship::query()->where('learner_id', $id)->orWhere('friend_id', $id)->count())->toBe(0)
        ->and(Challenge::query()->where('challenger_id', $id)->orWhere('opponent_id', $id)->count())->toBe(0)
        ->and(Referral::query()->where('referrer_learner_id', $id)->orWhere('referee_learner_id', $id)->count())->toBe(0);

    // The people around them stay, anonymised where they pointed at the learner.
    expect($friend->fresh())->not->toBeNull()
        ->and($inviter->fresh())->not->toBeNull()
        ->and($invitee->fresh()?->referrer_learner_id)->toBeNull();

    // Group statistics stay without the person.
    expect(ExamAnswer::query()->count())->toBe(1)
        ->and(ExamAnswer::query()->whereNull('learner_id')->count())->toBe(1)
        ->and(ExamResult::query()->whereNull('learner_id')->count())->toBe(1)
        ->and(ProductEvent::query()->where('learner_id', $id)->count())->toBe(0)
        ->and(ProductEvent::query()->where('name', ProductEventName::OnboardingStarted->value)->whereNull('learner_id')->count())->toBe(1);

    // Payment records: untouched, and they still say who paid and when.
    $kept = Payment::query()->findOrFail($payment->id);
    $order = PaymentOrder::query()->findOrFail($payment->payment_order_id);
    expect($kept->subscriber_id)->toBe($id)
        ->and($kept->status)->toBe(Payment::STATUS_SUCCEEDED)
        ->and($kept->occurred_at->toIso8601String())->toBe($payment->occurred_at->toIso8601String())
        ->and($kept->payer_telegram_user_id)->toBe(777_001)
        ->and($kept->payer_name)->toBe('Zoya Karimova')
        ->and($kept->payer_username)->toBe('zoya_k')
        ->and($order->payer_telegram_user_id)->toBe(777_001)
        ->and($order->payer_name)->toBe('Zoya Karimova')
        ->and($order->status)->toBe(PaymentOrder::STATUS_PAID);

    // The subscription row stays too, ended now with the reason in its history.
    $subscription = Subscription::query()->where('subscriber_type', 'learner')->where('subscriber_id', $id)->firstOrFail();
    expect($subscription->status)->toBe('expired')
        ->and($subscription->ended_at?->toIso8601String())->toBe(CarbonImmutable::now()->toIso8601String())
        ->and(collect($subscription->meta['history'])->last()['reason'] ?? null)->toBe(LearnerPurgeService::REASON_PURGED);

    // The trace for the support: ids and counts, no personal data.
    $audit = AuditLog::query()->where('action', LearnerPurgeService::ACTION_PURGED)->firstOrFail();
    expect($audit->user_id)->toBeNull()
        ->and($audit->entity_type)->toBe(Learner::class)
        ->and($audit->entity_id)->toBe($id)
        ->and($audit->meta['subscriptions_ended'])->toBe(1)
        ->and($audit->meta['payments_kept'])->toBe(1)
        ->and(json_encode($audit->meta))->not->toContain('Zoya')->not->toContain('777001');

    // Caches: the league standings of the group and the seat summary of the organization are dropped.
    expect(PureCache::store()->get(LeagueService::CACHE_PREFIX.$groupId))->toBeNull()
        ->and((int) Cache::get(OrgOverviewService::versionKey($organization->id)))->toBe(4);
});

it('has a row of the learner in every game table that refers to learners, so the purge of them is really tested', function (): void {
    ['learner' => $learner] = purgeFixture();

    $missing = [];
    foreach (Schema::getTableListing(schemaQualified: false) as $table) {
        if (! str_starts_with($table, 'game_') && ! str_starts_with($table, 'class_caravan')) {
            continue;
        }
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if ($foreignKey['foreign_table'] === 'learners' && DB::table($table)->where($foreignKey['columns'][0], $learner->id)->doesntExist()) {
                $missing[] = "{$table}.{$foreignKey['columns'][0]}";
            }
        }
    }

    expect($missing)->toBe([]);
});

it('deletes nothing before the retention period and the learner can still come back', function (): void {
    ['learner' => $learner, 'payment' => $payment] = purgeFixture();
    purgeSoftDelete($learner, 29);

    $report = app(LearnerPurgeService::class)->purgeDue();

    expect($report->purged)->toBe(0)
        ->and(Learner::withTrashed()->find($learner->id)?->trashed())->toBeTrue()
        ->and(WordRepetition::query()->where('learner_id', $learner->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', LearnerPurgeService::ACTION_PURGED)->count())->toBe(0)
        ->and(Subscription::query()->where('subscriber_id', $learner->id)->value('status'))->toBe('active');

    $revived = app(LearnerRegistrar::class)->fromTelegramUser(['id' => 777_001, 'first_name' => 'Zoya']);

    expect($revived->id)->toBe($learner->id)
        ->and($revived->trashed())->toBeFalse()
        ->and(Learner::query()->find($learner->id)?->trashed())->toBeFalse()
        ->and(app(SubscriptionService::class)->liveFor($revived)?->id)->toBe(Subscription::query()->where('subscriber_id', $learner->id)->value('id'))
        ->and(Payment::query()->find($payment->id)?->subscriber_id)->toBe($learner->id);
});

it('treats the retention period as inclusive: exactly after_days days is due, a second less is not', function (): void {
    $due = Learner::factory()->create();
    $notYet = Learner::factory()->create();
    purgeSoftDelete($due, 30);
    $notYet->delete();
    Learner::withTrashed()->whereKey($notYet->id)->update(['deleted_at' => CarbonImmutable::now()->subDays(30)->addSecond()]);

    $report = app(LearnerPurgeService::class)->purgeDue();

    expect($report->purged)->toBe(1)
        ->and(Learner::withTrashed()->find($due->id))->toBeNull()
        ->and(Learner::withTrashed()->find($notYet->id))->not->toBeNull();
});

it('changes nothing on a second run', function (): void {
    ['learner' => $learner] = purgeFixture();
    purgeSoftDelete($learner, 45);
    $service = app(LearnerPurgeService::class);

    $first = $service->purgeDue();
    $paymentsBefore = Payment::query()->count();
    $auditBefore = AuditLog::query()->count();
    $second = $service->purgeDue();

    expect($first->purged)->toBe(1)
        ->and($second->purged)->toBe(0)
        ->and($second->skipped)->toBe(0)
        ->and(Payment::query()->count())->toBe($paymentsBefore)
        ->and(AuditLog::query()->count())->toBe($auditBefore)
        ->and($service->purge($learner->id))->toBeFalse();
});

it('lets the same Telegram user start afresh after the purge: a new learner that inherits nothing', function (): void {
    ['learner' => $learner, 'friend' => $friend, 'payment' => $payment] = purgeFixture();
    $oldId = $learner->id;
    purgeSoftDelete($learner, 31);
    app(LearnerPurgeService::class)->purgeDue();

    $fresh = app(LearnerRegistrar::class)->fromTelegramUser(['id' => 777_001, 'first_name' => 'Zoya', 'username' => 'zoya_k'])->refresh();

    expect($fresh->id)->not->toBe($oldId)
        ->and($fresh->trashed())->toBeFalse()
        ->and($fresh->onboarding_state)->toBe('new')
        ->and($fresh->streak_current)->toBe(0)
        ->and(Learner::withTrashed()->where('telegram_user_id', 777_001)->count())->toBe(1)
        ->and(app(SubscriptionService::class)->liveFor($fresh))->toBeNull()
        ->and(WordRepetition::query()->where('learner_id', $fresh->id)->count())->toBe(0)
        ->and(Friendship::query()->where('learner_id', min($fresh->id, $friend->id))->where('friend_id', max($fresh->id, $friend->id))->count())->toBe(0)
        ->and(OrganizationMember::query()->where('learner_id', $fresh->id)->count())->toBe(0)
        ->and(Referral::query()->where('referee_learner_id', $fresh->id)->count())->toBe(0);

    // The old payments stay on the old id and are still found by the Telegram id of their payer.
    $kept = Payment::query()->findOrFail($payment->id);
    expect($kept->subscriber_id)->toBe($oldId)
        ->and(Payment::query()->where('payer_telegram_user_id', 777_001)->count())->toBe(1)
        ->and(Payment::query()->where('subscriber_id', $fresh->id)->count())->toBe(0);
});

it('asks Telegram to stop a running Stars subscription before the row goes, and purges even when Telegram refuses', function (): void {
    /** @var FakeTelegramClient $telegram */
    $telegram = app(TelegramClient::class);
    config()->set('billing.providers.telegram_stars.enabled', true);

    $stopped = Learner::factory()->create(['telegram_user_id' => 777_010]);
    Subscription::factory()->create(['subscriber_id' => $stopped->id, 'provider' => 'telegram_stars', 'provider_ref' => 'ch_ok', 'meta' => ['stars' => ['charge_id' => 'ch_ok', 'recurring' => true]]]);
    $refused = Learner::factory()->create(['telegram_user_id' => 777_011]);
    Subscription::factory()->create(['subscriber_id' => $refused->id, 'provider' => 'telegram_stars', 'provider_ref' => 'ch_bad', 'meta' => ['stars' => ['charge_id' => 'ch_bad', 'recurring' => true]]]);
    $oneOff = Learner::factory()->create(['telegram_user_id' => 777_012]);
    Subscription::factory()->create(['subscriber_id' => $oneOff->id, 'provider' => 'telegram_stars', 'provider_ref' => 'ch_year', 'meta' => ['stars' => ['charge_id' => 'ch_year', 'recurring' => false]]]);
    foreach ([$stopped, $refused, $oneOff] as $learner) {
        purgeSoftDelete($learner, 40);
    }
    $telegram->failStarsOn = 'ch_bad';

    $report = app(LearnerPurgeService::class)->purgeDue();

    // The fake records a call only when it succeeds: the refused one (`ch_bad`) was attempted and failed, the one-off (`ch_year`) was never asked.
    expect($report->purged)->toBe(3)
        ->and($telegram->starSubscriptionEdits)->toBe([
            ['user_id' => 777_010, 'charge_id' => 'ch_ok', 'is_canceled' => true],
        ])
        ->and(Learner::withTrashed()->whereIn('id', [$stopped->id, $refused->id, $oneOff->id])->count())->toBe(0)
        ->and(Subscription::query()->whereIn('subscriber_id', [$stopped->id, $refused->id, $oneOff->id])->pluck('status')->unique()->all())->toBe(['expired']);
});

it('purges oldest first, in batches, up to the per-run limit, and reports what is left', function (): void {
    $learners = [];
    foreach ([50, 40, 31] as $daysAgo) {
        $learner = Learner::factory()->create();
        purgeSoftDelete($learner, $daysAgo);
        $learners[$daysAgo] = $learner;
    }

    $report = app(LearnerPurgeService::class)->purgeDue(limit: 2);

    expect($report->purged)->toBe(2)
        ->and($report->capped)->toBeTrue()
        ->and(Learner::withTrashed()->find($learners[50]->id))->toBeNull()
        ->and(Learner::withTrashed()->find($learners[40]->id))->toBeNull()
        ->and(Learner::withTrashed()->find($learners[31]->id))->not->toBeNull();

    $rest = app(LearnerPurgeService::class)->purgeDue();

    expect($rest->purged)->toBe(1)
        ->and($rest->capped)->toBeFalse()
        ->and(Learner::onlyTrashed()->count())->toBe(0);
});

it('does nothing while another run holds the purge lock', function (): void {
    $learner = Learner::factory()->create();
    purgeSoftDelete($learner, 31);
    $lock = Cache::lock(LearnerPurgeService::LOCK_KEY, 60);
    expect($lock->get())->toBeTrue();

    try {
        $report = app(LearnerPurgeService::class)->purgeDue();
    } finally {
        $lock->release();
    }

    expect($report->locked)->toBeTrue()
        ->and($report->purged)->toBe(0)
        ->and(Learner::withTrashed()->find($learner->id))->not->toBeNull();
});

it('never purges a live learner or one restored meanwhile', function (): void {
    $live = Learner::factory()->create();
    $restored = Learner::factory()->create();
    purgeSoftDelete($restored, 31);
    $restored->restore();
    $service = app(LearnerPurgeService::class);

    expect($service->purge($live->id))->toBeFalse()
        ->and($service->purge($restored->id))->toBeFalse()
        ->and($service->purgeDue()->purged)->toBe(0)
        ->and(Learner::query()->count())->toBe(2);
});

it('runs from the console command with the retention and limit from the config and the option', function (): void {
    foreach ([31, 32, 33] as $daysAgo) {
        purgeSoftDelete(Learner::factory()->create(), $daysAgo);
    }
    purgeSoftDelete(Learner::factory()->create(), 10);

    $this->artisan('learners:purge-deleted', ['--limit' => 2])
        ->expectsOutputToContain('Learners purged: 2')
        ->expectsOutputToContain('per-run limit')
        ->assertSuccessful();

    $this->artisan('learners:purge-deleted')
        ->expectsOutputToContain('Learners purged: 1')
        ->assertSuccessful();

    expect(Learner::onlyTrashed()->count())->toBe(1);
});
