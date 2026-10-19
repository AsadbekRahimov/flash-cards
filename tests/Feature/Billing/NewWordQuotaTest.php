<?php

declare(strict_types=1);

/*
 * Free-plan quota of new words (task 1.4b, docs/12 §5.3): NewWordQuota bound as the NewCardBudgetResolver,
 * driven through the real HTTP training flow. The JSON shape is pinned in TwaContractTest; the two-tab
 * race on PostgreSQL is in BillingConcurrencyTest.
 */

use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Billing\Services\NewWordQuota;
use App\Domain\Learning\Contracts\NewCardBudgetResolver;
use App\Domain\Twa\Services\JwtService;
use App\Models\Course;
use App\Models\GroupMember;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Organization;
use App\Models\Stage;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\TrainingSession;
use App\Models\Word;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

afterEach(fn () => CarbonImmutable::setTestNow());

/** @return array<string, string> */
function nwqHeaders(Learner $learner): array
{
    return ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
}

/** One published course with a single lesson of `$words` words and a learner who studies it. */
function nwqLearner(int $words, array $attributes = []): Learner
{
    $course = Course::factory()->published()->create();
    $stage = Stage::factory()->create(['course_id' => $course->id, 'number' => 1]);
    $lesson = Lesson::factory()->create(['stage_id' => $stage->id, 'number' => 1]);
    Word::factory()->count($words)->for($lesson)->create();

    return Learner::factory()->onboarded()->create(['active_course_id' => $course->id, ...$attributes]);
}

function nwqOpen(object $test, Learner $learner): TestResponse
{
    return $test->withHeaders(nwqHeaders($learner))->postJson('/api/twa/training/sessions', ['mode' => 'daily_review']);
}

function nwqNext(object $test, Learner $learner, int $sessionId): TestResponse
{
    return $test->withHeaders(nwqHeaders($learner))->getJson("/api/twa/training/sessions/{$sessionId}/next");
}

/** Grades the card in `$step` (Easy) and returns the answer, which carries the next step. */
function nwqGrade(object $test, Learner $learner, int $sessionId, TestResponse $step): TestResponse
{
    return $test->withHeaders(nwqHeaders($learner))
        ->postJson("/api/twa/training/sessions/{$sessionId}/review", [
            'word_id' => $step->json('card.word_id'),
            'quality' => 5,
            'time_spent_ms' => 300,
        ])->assertOk();
}

/**
 * Opens a daily review and walks it, grading every card, until the server has no card.
 *
 * @return array{session: int, served: int, last: TestResponse}
 */
function nwqWalk(object $test, Learner $learner): array
{
    $open = nwqOpen($test, $learner)->assertOk();
    $sessionId = (int) $open->json('session_id');
    $served = 0;
    $step = $open;

    while ($step->json('card') !== null) {
        $served++;
        $step = nwqGrade($test, $learner, $sessionId, $step);
    }

    return ['session' => $sessionId, 'served' => $served, 'last' => $step];
}

it('is the bound new-card budget resolver', function (): void {
    expect(app(NewCardBudgetResolver::class))->toBeInstanceOf(NewWordQuota::class);
});

it('serves 20 new cards to a Free learner and not the 21st: done_reason new_quota_exhausted', function (): void {
    $learner = nwqLearner(25);

    $walk = nwqWalk($this, $learner);

    expect($walk['served'])->toBe(20)
        ->and($walk['last']->json('card'))->toBeNull()
        ->and($walk['last']->json('done_reason'))->toBe('new_quota_exhausted')
        ->and($walk['last']->json('quota'))->toBe(['new_words_remaining' => 0])
        ->and(WordRepetition::query()->where('learner_id', $learner->id)->count())->toBe(20);

    // Asking again changes nothing: still exhausted, no 21st row.
    nwqNext($this, $learner, $walk['session'])->assertOk()
        ->assertJsonPath('card', null)
        ->assertJsonPath('done_reason', 'new_quota_exhausted')
        ->assertJsonPath('quota.new_words_remaining', 0);
    expect(WordRepetition::query()->where('learner_id', $learner->id)->count())->toBe(20);
});

it('counts the quota down in every answer, counting the card on screen as spent', function (): void {
    config()->set('billing.plans.free.limits.new_words_per_day', 3);
    $learner = nwqLearner(5);

    $first = nwqOpen($this, $learner)->assertOk();
    expect($first->json('quota.new_words_remaining'))->toBe(2);

    $second = nwqGrade($this, $learner, (int) $first->json('session_id'), $first);
    expect($second->json('quota.new_words_remaining'))->toBe(1)
        ->and(nwqNext($this, $learner, (int) $first->json('session_id'))->json('quota.new_words_remaining'))->toBe(1);
});

it('still serves due cards after the quota is exhausted', function (): void {
    config()->set('billing.plans.free.limits.new_words_per_day', 2);
    $learner = nwqLearner(5);

    $walk = nwqWalk($this, $learner);
    expect($walk['served'])->toBe(2)
        ->and($walk['last']->json('done_reason'))->toBe('new_quota_exhausted');

    WordRepetition::query()->where('learner_id', $learner->id)->orderBy('id')->limit(1)->update(['next_review_at' => now()->subMinute()]);

    $next = nwqNext($this, $learner, $walk['session'])->assertOk();

    expect($next->json('card.card_kind'))->toBe('due')
        ->and($next->json('done_reason'))->toBeNull()
        ->and($next->json('quota.new_words_remaining'))->toBe(0);
});

it('ends with nothing_due, not with the quota, when the course has fewer new words than the limit', function (): void {
    config()->set('billing.plans.free.limits.new_words_per_day', 10);
    $learner = nwqLearner(3);

    $walk = nwqWalk($this, $learner);

    // Three words exist: the walk ends because the course is exhausted, not because of the quota.
    expect($walk['served'])->toBe(3)
        ->and($walk['last']->json('done_reason'))->toBe('nothing_due')
        ->and($walk['last']->json('quota.new_words_remaining'))->toBe(7);
});

it('applies the same quota to a lesson session', function (): void {
    config()->set('billing.plans.free.limits.new_words_per_day', 2);
    $learner = nwqLearner(5);
    $lessonId = Lesson::query()->firstOrFail()->id;

    $open = $this->withHeaders(nwqHeaders($learner))
        ->postJson('/api/twa/training/sessions', ['mode' => 'lesson', 'lesson_id' => $lessonId])
        ->assertOk();
    $sessionId = (int) $open->json('session_id');
    $step = nwqGrade($this, $learner, $sessionId, $open);
    $step = nwqGrade($this, $learner, $sessionId, $step);

    expect($step->json('card'))->toBeNull()
        ->and($step->json('done_reason'))->toBe('new_quota_exhausted');
});

it('applies the quota to group lesson sessions too: the limit belongs to the learner, not to the session', function (): void {
    config()->set('billing.plans.free.limits.new_words_per_day', 2);
    $learner = nwqLearner(5);
    $lesson = Lesson::query()->firstOrFail();
    $group = TelegramGroup::factory()->create(['status' => 'active']);
    GroupMember::factory()->create(['telegram_group_id' => $group->id, 'learner_id' => $learner->id]);
    $session = TrainingSession::factory()->create([
        'telegram_group_id' => $group->id,
        'lesson_id' => $lesson->id,
        'mode' => 'group_lesson',
        'status' => 'open',
    ]);

    $headers = nwqHeaders($learner);
    $start = $this->withHeaders($headers)->postJson("/api/twa/training/sessions/{$session->id}/start")->assertOk();
    $step = nwqGrade($this, $learner, $session->id, $start);
    $step = nwqGrade($this, $learner, $session->id, $step);

    expect($step->json('card'))->toBeNull()
        ->and($step->json('done_reason'))->toBe('new_quota_exhausted')
        ->and($step->json('quota.new_words_remaining'))->toBe(0);
});

it('counts new cards of every session mode in one daily budget', function (): void {
    config()->set('billing.plans.free.limits.new_words_per_day', 3);
    $learner = nwqLearner(6);
    $lessonId = Lesson::query()->firstOrFail()->id;

    $lessonOpen = $this->withHeaders(nwqHeaders($learner))
        ->postJson('/api/twa/training/sessions', ['mode' => 'lesson', 'lesson_id' => $lessonId])->assertOk();
    nwqGrade($this, $learner, (int) $lessonOpen->json('session_id'), $lessonOpen);

    $daily = nwqWalk($this, $learner);

    expect($daily['served'])->toBe(2)
        ->and($daily['last']->json('done_reason'))->toBe('new_quota_exhausted');
});

it('does not limit a Pro learner, personal or sponsored by a center', function (): void {
    config()->set('billing.plans.free.limits.new_words_per_day', 2);

    $personal = nwqLearner(5);
    Subscription::factory()->create(['subscriber_type' => 'learner', 'subscriber_id' => $personal->id]);

    $sponsored = nwqLearner(5);
    $organization = Organization::factory()->create();
    $group = TelegramGroup::factory()->create(['status' => 'active', 'organization_id' => $organization->id]);
    GroupMember::factory()->create(['telegram_group_id' => $group->id, 'learner_id' => $sponsored->id]);
    Subscription::factory()->forOrganization($organization)->create();

    foreach ([$personal, $sponsored] as $learner) {
        $open = nwqOpen($this, $learner)->assertOk();
        expect($open->json('quota.new_words_remaining'))->toBeNull();

        $walk = nwqWalk($this, $learner);

        expect($walk['served'])->toBe(5)
            ->and($walk['last']->json('done_reason'))->toBe('nothing_due')
            ->and($walk['last']->json('quota.new_words_remaining'))->toBeNull();
    }
});

it('resets the quota at the learner\'s local midnight (Asia/Tashkent), not at UTC midnight', function (): void {
    config()->set('billing.plans.free.limits.new_words_per_day', 2);
    $learner = nwqLearner(6, ['timezone' => 'Asia/Tashkent']);

    CarbonImmutable::setTestNow('2026-10-19 18:50:00'); // 23:50 on the 19th in Tashkent
    $walk = nwqWalk($this, $learner);
    expect($walk['served'])->toBe(2)
        ->and($walk['last']->json('done_reason'))->toBe('new_quota_exhausted');

    CarbonImmutable::setTestNow('2026-10-19 18:59:00'); // still the 19th locally
    expect(nwqNext($this, $learner, $walk['session'])->json('done_reason'))->toBe('new_quota_exhausted');

    CarbonImmutable::setTestNow('2026-10-19 19:01:00'); // 00:01 on the 20th locally, still the 19th in UTC
    $quota = app(NewWordQuota::class);
    expect($quota->remaining($learner))->toBe(2);

    $again = nwqWalk($this, $learner);
    expect($again['served'])->toBe(2)
        ->and($again['last']->json('done_reason'))->toBe('new_quota_exhausted')
        ->and(WordRepetition::query()->where('learner_id', $learner->id)->count())->toBe(4);
});

it('counts the same instant in the learner\'s own zone: UTC midnight does not reset a Tashkent learner', function (): void {
    config()->set('billing.plans.free.limits.new_words_per_day', 2);
    $learner = nwqLearner(6, ['timezone' => 'Asia/Tashkent']);

    CarbonImmutable::setTestNow('2026-10-19 23:30:00'); // 04:30 on the 20th in Tashkent
    $walk = nwqWalk($this, $learner);
    expect($walk['served'])->toBe(2);

    CarbonImmutable::setTestNow('2026-10-20 00:30:00'); // after UTC midnight, 05:30 locally on the same day
    expect(app(NewWordQuota::class)->remaining($learner))->toBe(0);
});

it('keeps the count when the timezone changes within the day as long as the cards fall into the new local day', function (): void {
    config()->set('billing.plans.free.limits.new_words_per_day', 3);
    $learner = nwqLearner(6, ['timezone' => 'Asia/Tashkent']);

    CarbonImmutable::setTestNow('2026-10-19 08:00:00'); // 13:00 on the 19th in Tashkent
    nwqWalk($this, $learner);
    expect(app(NewWordQuota::class)->remaining($learner))->toBe(0);

    // Dubai (UTC+4) and Almaty (UTC+5/+6) put 08:00 UTC on the same calendar day: the cards are still counted.
    foreach (['Asia/Dubai', 'Asia/Almaty', 'Europe/Moscow'] as $zone) {
        $learner->update(['timezone' => $zone]);
        expect(app(NewWordQuota::class)->remaining($learner->fresh()))->toBe(0, $zone);
    }
});

it('bounds the effect of a timezone change: cards outside the new local-day window are not counted (docs/12 §5.3)', function (): void {
    config()->set('billing.plans.free.limits.new_words_per_day', 3);
    $learner = nwqLearner(10, ['timezone' => 'Asia/Tashkent']);

    CarbonImmutable::setTestNow('2026-10-19 08:00:00');
    nwqWalk($this, $learner);

    // At 08:00 UTC it is already the 19th in Tashkent, but still the 18th evening in Honolulu (UTC-10):
    // that local day is [18th 10:00 UTC, 19th 10:00 UTC) and contains the cards, so the quota stays spent...
    $learner->update(['timezone' => 'Pacific/Honolulu']);
    expect(app(NewWordQuota::class)->remaining($learner->fresh()))->toBe(0);

    // ...whereas at 11:00 UTC Honolulu's day is the next window and the cards are outside it.
    // Never more than the limit per local-day window: the accepted anomaly (same as for the streak).
    CarbonImmutable::setTestNow('2026-10-19 11:00:00');
    expect(app(NewWordQuota::class)->remaining($learner->fresh()))->toBe(3);
});

it('shows new_words_remaining in /today: full quota, spent quota, unlimited for Pro', function (): void {
    config()->set('billing.plans.free.limits.new_words_per_day', 4);
    $learner = nwqLearner(6);
    $today = fn (Learner $l) => $this->withHeaders(nwqHeaders($l))->getJson('/api/twa/today')->assertOk();

    expect($today($learner)->json('new_words_remaining'))->toBe(4);

    $open = nwqOpen($this, $learner)->assertOk();
    nwqGrade($this, $learner, (int) $open->json('session_id'), $open);
    expect($today($learner)->json('new_words_remaining'))->toBe(2);

    nwqWalk($this, $learner);
    expect($today($learner)->json('new_words_remaining'))->toBe(0);

    Subscription::factory()->create(['subscriber_type' => 'learner', 'subscriber_id' => $learner->id]);
    app(EntitlementResolver::class)->forget($learner);
    expect($today($learner)->json('new_words_remaining'))->toBeNull();
});

it('takes no lock of its own: the budget closure is a single plain count query', function (): void {
    $learner = Learner::factory()->create();

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    app(NewWordQuota::class)->budgetFor($learner, CarbonImmutable::now())->remaining($learner->id, CarbonImmutable::now());

    $count = array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'word_repetitions')));
    expect($count)->toHaveCount(1)
        ->and(strtolower($count[0]))->toStartWith('select count(*)')
        ->and(strtolower($count[0]))->not->toContain('for update');
});
