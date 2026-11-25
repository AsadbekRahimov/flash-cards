<?php

declare(strict_types=1);

/*
 * docs/35 O3: the audience of a poll (`PollAudienceQuery` - one definition for the preview count and the insert of the launch) and the
 * transitions of `PollLauncher`: launch (preconditions, snapshot, the insert by ranges, idempotent), stop and close (the rows not
 * sent yet skipped, the totals at closing), the resume of an unfinished insert. The parallel launches on PostgreSQL are in
 * PollConcurrencyTest.
 */

use App\Domain\Polls\Enums\PollDeliveryStatus;
use App\Domain\Polls\Enums\PollStatus;
use App\Domain\Polls\Exceptions\PollLaunchException;
use App\Domain\Polls\Services\PollAudienceQuery;
use App\Domain\Polls\Services\PollLauncher;
use App\Domain\Polls\Services\PollSweeper;
use App\Jobs\SendPollBatchJob;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\PollDelivery;
use App\Models\PollOption;
use App\Models\PollVote;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Polls\PollFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['polls.send_per_second' => 0, 'polls.insert_chunk' => 100]);
});

/** @return list<int> */
function pollLearnerIds(int $pollId): array
{
    return PollDelivery::query()->where('poll_id', $pollId)->whereNotNull('learner_id')->orderBy('learner_id')->pluck('learner_id')
        ->map(fn ($id): int => (int) $id)->all();
}

it('counts and launches the same audience: reachable, onboarded, by plan, language, course and region', function (): void {
    $courseA = Course::factory()->create();
    $courseB = Course::factory()->create();
    $org = Organization::factory()->create();
    Subscription::factory()->forOrganization($org)->create();

    $free = Learner::factory()->onboarded()->create(['interface_locale' => 'uz', 'active_course_id' => $courseA->id, 'region_code' => 'UZ-TK']);
    $pro = Learner::factory()->onboarded()->create(['interface_locale' => 'ru', 'active_course_id' => $courseA->id, 'region_code' => 'UZ-SA']);
    Subscription::factory()->create(['subscriber_id' => $pro->id]);
    $trial = Learner::factory()->onboarded()->create(['interface_locale' => 'ru', 'active_course_id' => $courseB->id]);
    Subscription::factory()->create(['subscriber_id' => $trial->id, 'status' => 'trialing']);
    $seated = Learner::factory()->onboarded()->seatedIn($org)->create(['interface_locale' => 'uz', 'active_course_id' => $courseB->id]);
    $lapsed = Learner::factory()->onboarded()->create();
    Subscription::factory()->expired()->create(['subscriber_id' => $lapsed->id]);

    // Never in any audience.
    Learner::factory()->onboarded()->create(['blocked_at' => now()]);
    Learner::factory()->onboarded()->create(['dm_blocked_at' => now(), 'dm_blocked_reason' => 'bot_blocked']);
    Learner::factory()->onboarded()->create(['dm_blocked_reason' => 'kicked']);
    Learner::factory()->onboarded()->create()->delete();
    Learner::factory()->onboarded()->create(['telegram_user_id' => -5]);
    $fresh = Learner::factory()->create(['onboarding_state' => 'new']);

    $query = app(PollAudienceQuery::class);
    $cases = [
        [['plan' => 'all'], [$free, $pro, $trial, $seated, $lapsed]],
        [['plan' => 'pro'], [$pro, $trial, $seated]],
        [['plan' => 'free'], [$free, $lapsed]],
        [['plan' => 'all', 'locales' => ['uz']], [$free, $seated]],
        [['plan' => 'pro', 'locales' => ['uz']], [$seated]],
        [['plan' => 'all', 'course_ids' => [$courseB->id]], [$trial, $seated]],
        [['plan' => 'all', 'region_codes' => ['UZ-TK', 'UZ-SA']], [$free, $pro]],
        [['plan' => 'all', 'onboarded_only' => false], [$free, $pro, $trial, $seated, $lapsed, $fresh]],
        [['plan' => 'nonsense', 'locales' => [5, 'ru'], 'course_ids' => ['x']], [$pro, $trial, $lapsed]],
    ];

    foreach ($cases as $i => [$audience, $expected]) {
        $ids = array_map(fn (Learner $l): int => $l->id, $expected);
        sort($ids);
        $statements = 0;
        DB::listen(function () use (&$statements): void {
            $statements++;
        });
        $count = $query->count($audience);
        DB::flushQueryLog();

        expect($count)->toBe(count($ids), "count #{$i}");

        $poll = PollFixtures::poll(['audience' => $audience]);
        PollFixtures::tested($poll);
        Queue::fake();
        expect(app(PollLauncher::class)->launch($poll, PollFixtures::admin(null)))->toBeTrue();

        expect(pollLearnerIds($poll->id))->toBe($ids, "launch #{$i}")
            ->and($poll->fresh()->recipient_count)->toBe(count($ids));
    }
});

it('counts the preview in one statement', function (): void {
    PollFixtures::learners(3);
    $statements = 0;
    DB::listen(function () use (&$statements): void {
        $statements++;
    });

    app(PollAudienceQuery::class)->count(['plan' => 'pro', 'locales' => ['ru'], 'course_ids' => [1], 'region_codes' => ['UZ-TK']]);

    expect($statements)->toBe(1);
});

it('launches once: a draft with a sent test goes sending, the recipients inserted, the chains started, the audience frozen', function (): void {
    Queue::fake();
    config(['polls.workers' => 2]);
    $learners = PollFixtures::learners(5, ['interface_locale' => 'uz']);
    $en = Learner::factory()->onboarded()->create(['interface_locale' => 'en']);
    $poll = PollFixtures::poll(['audience' => ['plan' => 'all', 'locales' => [], 'course_ids' => [], 'region_codes' => [], 'onboarded_only' => true, 'junk' => 1]]);
    PollFixtures::tested($poll);
    $admin = PollFixtures::admin(null);
    $at = CarbonImmutable::parse('2026-11-28 09:00:00');

    expect(app(PollLauncher::class)->launch($poll, $admin, $at))->toBeTrue();

    $poll->refresh();
    expect($poll->status)->toBe(PollStatus::Sending)
        ->and($poll->launched_at?->toDateTimeString())->toBe('2026-11-28 09:00:00')
        ->and($poll->launched_by_user_id)->toBe($admin->id)
        ->and($poll->audience)->toBe(['plan' => 'all', 'locales' => [], 'course_ids' => [], 'region_codes' => [], 'onboarded_only' => true])
        ->and($poll->recipient_count)->toBe(6);

    $rows = PollDelivery::query()->where('poll_id', $poll->id)->where('is_test', false)->get();
    expect($rows)->toHaveCount(6)
        ->and($rows->pluck('status')->unique()->all())->toBe([PollDeliveryStatus::Pending])
        ->and($rows->firstWhere('learner_id', $en->id)?->locale)->toBe('ru')
        ->and($rows->firstWhere('learner_id', $learners[0]->id)?->locale)->toBe('uz')
        ->and($rows->firstWhere('learner_id', $learners[0]->id)?->telegram_user_id)->toBe($learners[0]->telegram_user_id)
        ->and($rows->pluck('token')->unique())->toHaveCount(6);
    foreach ($rows as $row) {
        expect($row->token)->toMatch('~^[A-Za-z0-9]{12}$~D');
    }

    Queue::assertPushed(SendPollBatchJob::class, 2);
    Queue::assertPushed(SendPollBatchJob::class, fn (SendPollBatchJob $job): bool => $job->pollId === $poll->id
        && $job->notAfter === $at->addHours(24)->getTimestamp() && $job->queue === 'low');
    expect(AuditLog::query()->where('action', PollLauncher::AUDIT_LAUNCHED)->where('entity_id', $poll->id)->count())->toBe(1);

    // A second click: nothing.
    expect(app(PollLauncher::class)->launch($poll, $admin))->toBeFalse()
        ->and(PollDelivery::query()->where('poll_id', $poll->id)->count())->toBe(7);
    Queue::assertPushed(SendPollBatchJob::class, 2);
});

it('refuses a launch without a sent test, with too few options or a text over the limit, and writes nothing', function (): void {
    Queue::fake();
    PollFixtures::learners(2);
    $admin = PollFixtures::admin(null);

    $untested = PollFixtures::poll();
    PollDelivery::factory()->test()->create(['poll_id' => $untested->id, 'status' => PollDeliveryStatus::Failed]);
    $oneOption = PollFixtures::poll([], 1);
    PollFixtures::tested($oneOption);
    $long = PollFixtures::poll(['question' => ['ru' => str_repeat('я', 1030), 'uz' => 'ok'], 'image_path' => 'polls/x.png']);
    PollFixtures::tested($long);
    $longText = PollFixtures::poll(['question' => ['ru' => 'ok', 'uz' => str_repeat('a', 4097)]]);
    PollFixtures::tested($longText);

    foreach ([[$untested, PollLaunchException::UNTESTED], [$oneOption, PollLaunchException::OPTIONS], [$long, PollLaunchException::TOO_LONG], [$longText, PollLaunchException::TOO_LONG]] as [$poll, $reason]) {
        try {
            app(PollLauncher::class)->launch($poll, $admin);
            $this->fail('no exception for '.$reason);
        } catch (PollLaunchException $e) {
            expect($e->reason)->toBe($reason);
        }

        expect($poll->fresh()->status)->toBe(PollStatus::Draft)
            ->and(PollDelivery::query()->where('poll_id', $poll->id)->whereNotNull('learner_id')->count())->toBe(0);
    }

    // 1 030 characters of caption with a picture are too many; without a picture the same text is fine.
    $long->update(['image_path' => null]);
    expect(app(PollLauncher::class)->launch($long, $admin))->toBeTrue();
    Queue::assertPushed(SendPollBatchJob::class);
});

it('leaves out learners created after the launch, also when the insert is resumed', function (): void {
    Queue::fake();
    $before = PollFixtures::learners(3, ['created_at' => now()->subHour()]);
    $poll = PollFixtures::poll();
    PollFixtures::tested($poll);
    app(PollLauncher::class)->launch($poll, PollFixtures::admin(null), CarbonImmutable::now()->subMinutes(30));

    // The launcher died mid-insert: some rows missing, no recipient_count, no chains.
    PollDelivery::query()->where('poll_id', $poll->id)->where('learner_id', $before[1]->id)->delete();
    $poll->forceFill(['recipient_count' => null])->save();
    $late = Learner::factory()->onboarded()->create();
    Queue::fake();

    $out = app(PollSweeper::class)->sweep();

    expect($out['resumed'])->toBe(1)
        ->and(pollLearnerIds($poll->id))->toBe(array_map(fn (Learner $l): int => $l->id, $before))
        ->and($poll->fresh()->recipient_count)->toBe(3)
        ->and(pollLearnerIds($poll->id))->not->toContain($late->id);
    Queue::assertPushed(SendPollBatchJob::class);

    // Within the grace a running launcher is not raced.
    $poll2 = PollFixtures::poll();
    PollFixtures::tested($poll2);
    app(PollLauncher::class)->launch($poll2, PollFixtures::admin(null));
    $poll2->forceFill(['recipient_count' => null])->save();
    expect(app(PollSweeper::class)->sweep()['resumed'])->toBe(0);
});

it('stops a broadcast: draft or sending to cancelled, the rows not sent yet skipped, a second stop does nothing', function (): void {
    $poll = PollFixtures::launched(4);
    $ids = PollDelivery::query()->where('poll_id', $poll->id)->whereNotNull('learner_id')->orderBy('id')->pluck('id');
    PollDelivery::query()->whereKey($ids[0])->update(['status' => PollDeliveryStatus::Sent, 'sent_at' => now()]);
    PollDelivery::query()->whereKey($ids[1])->update(['status' => PollDeliveryStatus::Sending, 'claimed_at' => now()]);
    $admin = PollFixtures::admin(null);

    expect(app(PollLauncher::class)->cancel($poll, $admin))->toBeTrue()
        ->and($poll->status)->toBe(PollStatus::Cancelled)
        ->and(PollDelivery::query()->find($ids[0])?->status)->toBe(PollDeliveryStatus::Sent)
        ->and(PollDelivery::query()->find($ids[1])?->status)->toBe(PollDeliveryStatus::Sending)
        ->and(PollDelivery::query()->whereIn('id', [$ids[2], $ids[3]])->pluck('status')->unique()->all())->toBe([PollDeliveryStatus::Skipped])
        ->and(PollDelivery::query()->find($ids[2])?->error_code)->toBe('cancelled')
        ->and(app(PollLauncher::class)->cancel($poll, $admin))->toBeFalse()
        ->and(app(PollLauncher::class)->close($poll, $admin))->toBeFalse()
        ->and(AuditLog::query()->where('action', PollLauncher::AUDIT_CANCELLED)->count())->toBe(1);

    $draft = PollFixtures::poll();
    expect(app(PollLauncher::class)->cancel($draft, $admin))->toBeTrue()
        ->and(app(PollLauncher::class)->launch($draft, $admin))->toBeFalse();
});

it('closes once with the totals of the learners only, and does not close a draft', function (): void {
    $poll = PollFixtures::launched(3);
    $rows = PollDelivery::query()->where('poll_id', $poll->id)->whereNotNull('learner_id')->orderBy('id')->get();
    $options = PollOption::query()->where('poll_id', $poll->id)->orderBy('position')->pluck('id');
    $rows[0]->update(['status' => PollDeliveryStatus::Sent, 'answered_at' => now()]);
    $rows[1]->update(['status' => PollDeliveryStatus::Forbidden]);
    PollVote::query()->create(['poll_id' => $poll->id, 'poll_delivery_id' => $rows[0]->id, 'poll_option_id' => $options[1]]);
    $test = PollDelivery::query()->where('poll_id', $poll->id)->where('is_test', true)->firstOrFail();
    PollVote::query()->create(['poll_id' => $poll->id, 'poll_delivery_id' => $test->id, 'poll_option_id' => $options[1]]);
    $now = CarbonImmutable::parse('2026-11-30 12:00:00');

    expect(app(PollLauncher::class)->close($poll, null, $now))->toBeTrue();

    $poll->refresh();
    expect($poll->status)->toBe(PollStatus::Closed)
        ->and($poll->closed_at?->toDateTimeString())->toBe('2026-11-30 12:00:00')
        ->and($rows[2]->fresh()->status)->toBe(PollDeliveryStatus::Skipped)
        ->and($rows[2]->fresh()->error_code)->toBe('closed')
        ->and($poll->results_snapshot['recipients'])->toBe(3)
        ->and($poll->results_snapshot['answered'])->toBe(1)
        ->and($poll->results_snapshot['statuses']['sent'])->toBe(1)
        ->and($poll->results_snapshot['statuses']['forbidden'])->toBe(1)
        ->and($poll->results_snapshot['statuses']['skipped'])->toBe(1)
        ->and($poll->results_snapshot['options'])->toBe([$options[0] => 0, $options[1] => 1, $options[2] => 0])
        ->and(AuditLog::query()->where('action', PollLauncher::AUDIT_CLOSED)->whereNull('user_id')->count())->toBe(1)
        ->and(app(PollLauncher::class)->close($poll, null, $now))->toBeFalse();

    expect(app(PollLauncher::class)->close(PollFixtures::poll(), null))->toBeFalse();
});

it('rolls the broadcast columns back and forth', function (): void {
    $migration = require database_path('migrations/2026_11_27_100005_add_broadcast_columns_to_polls_tables.php');

    $migration->down();
    expect(Schema::hasColumn('poll_deliveries', 'claimed_at'))->toBeFalse()
        ->and(Schema::hasColumn('polls', 'recipient_count'))->toBeFalse();

    $migration->up();
    expect(Schema::hasColumn('poll_deliveries', 'claimed_at'))->toBeTrue()
        ->and(Schema::hasColumn('polls', 'recipient_count'))->toBeTrue();
});
