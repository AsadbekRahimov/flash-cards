<?php

declare(strict_types=1);

/*
 * Real races on PostgreSQL around the onboarding of the Mini App (docs/40 §2.1, §5 п. 1): two devices saving the same step, parallel
 * presses of "Start the first lesson", the Mini App finishing while the bot's last button is pressed, and two runs of the job that
 * offers the pending invitation. Each contender is a forked
 * process with its own connection; committed rows: DatabaseTruncation. Skipped on sqlite.
 */

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Learners\Exceptions\OnboardingException;
use App\Domain\Learners\Services\OnboardingService;
use App\Domain\Learners\Services\TwaOnboardingService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\FriendsMessenger;
use App\Domain\Telegram\Services\OrganizationMessenger;
use App\Jobs\OfferPendingInvitesJob;
use App\Models\Learner;
use App\Models\ProductEvent;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeTelegramClient;
use Tests\Support\ForkSafe;
use Tests\Support\HotPathFixtures as Fx;

$onPostgres = (getenv('DB_CONNECTION') ?: 'sqlite') === 'pgsql';

if (! $onPostgres) {
    it('races the onboarding of the Mini App on PostgreSQL', function (): void {})
        ->skip('Requires PostgreSQL (DB_CONNECTION=pgsql): real parallel transactions need committed rows and row locks.');

    return;
}

uses(DatabaseTruncation::class);

beforeEach(function (): void {
    config(['learners.goals' => ['general'], 'learners.daily_goal_options' => [10, 20, 40]]);
    Fx::course([1 => [1 => 3]], ['slug' => 'english-b1', 'exam_tag' => 'general', 'level' => 'B1']);
});

afterEach(function (): void {
    $this->truncateTablesForAllConnections();
});

/**
 * @param  list<callable(): mixed>  $tasks
 * @return list<array{ok: bool, result?: mixed, error?: string, message?: string}>
 */
function onbRaceInParallel(array $tasks): array
{
    ForkSafe::releaseConnections();

    $dir = sys_get_temp_dir().'/lexiflow-onb-race-'.getmypid().'-'.bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    $go = "{$dir}/go";
    $pids = [];

    foreach ($tasks as $i => $task) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('pcntl_fork failed');
        }

        if ($pid === 0) {
            $outcome = [];
            try {
                ForkSafe::releaseConnections();
                // A deadlock must surface as an error, not as a hang of the suite.
                DB::statement("SET lock_timeout = '10s'");

                $deadline = microtime(true) + 10;
                while (! file_exists($go) && microtime(true) < $deadline) {
                    usleep(500);
                }

                $outcome = ['ok' => true, 'result' => $task()];
            } catch (Throwable $e) {
                $outcome = ['ok' => false, 'error' => $e::class, 'message' => $e->getMessage()];
            } finally {
                file_put_contents("{$dir}/{$i}.json", json_encode($outcome, JSON_THROW_ON_ERROR));
                DB::disconnect();
                posix_kill(posix_getpid(), SIGKILL);
            }
        }

        $pids[] = $pid;
    }

    touch($go);

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $results = [];
    foreach (array_keys($tasks) as $i) {
        /** @var array{ok: bool, result?: mixed, error?: string, message?: string} $decoded */
        $decoded = json_decode((string) file_get_contents("{$dir}/{$i}.json"), true, 512, JSON_THROW_ON_ERROR);
        $results[] = $decoded;
        unlink("{$dir}/{$i}.json");
    }
    unlink($go);
    rmdir($dir);

    return $results;
}

function onbRaceEvents(int $learnerId, ProductEventName $name): int
{
    return ProductEvent::query()->where('learner_id', $learnerId)->where('name', $name->value)->count();
}

it('ends two devices saving the same step at once in one state, without an error and with one start', function (): void {
    $learner = Learner::factory()->create();

    $results = onbRaceInParallel([
        fn (): ?string => app(TwaOnboardingService::class)->saveStep(Learner::query()->findOrFail($learner->id), 'goals', ['goals' => ['career']])->step,
        fn (): ?string => app(TwaOnboardingService::class)->saveStep(Learner::query()->findOrFail($learner->id), 'goals', ['goals' => ['career', 'media']])->step,
        fn (): ?string => app(TwaOnboardingService::class)->saveStep(Learner::query()->findOrFail($learner->id), 'goals', ['goals' => ['career']])->step,
    ]);

    foreach ($results as $result) {
        expect($result['ok'])->toBeTrue(($result['error'] ?? '').' '.($result['message'] ?? ''))
            ->and($result['result'])->toBe('level');
    }
    $fresh = $learner->fresh();
    expect($fresh?->goals)->toBeIn([['career'], ['career', 'media']])
        ->and($fresh?->meta['twa_onboarding']['step'] ?? null)->toBe('level')
        ->and($fresh?->meta['twa_onboarding']['answered'] ?? null)->toBe(['goals'])
        ->and(onbRaceEvents($learner->id, ProductEventName::OnboardingStarted))->toBe(1)
        // The first save, then one per real change: two or three, never more than the requests.
        ->and(onbRaceEvents($learner->id, ProductEventName::OnboardingStepCompleted))->toBeGreaterThanOrEqual(2)->toBeLessThanOrEqual(3);
});

it('completes once when "Start the first lesson" is pressed three times at once', function (): void {
    $learner = Learner::factory()->create(['level' => 'B1']);

    $results = onbRaceInParallel(array_fill(0, 3, fn (): ?string => app(TwaOnboardingService::class)->complete(Learner::query()->findOrFail($learner->id))->course['slug'] ?? null));

    foreach ($results as $result) {
        expect($result['ok'])->toBeTrue(($result['error'] ?? '').' '.($result['message'] ?? ''))
            ->and($result['result'])->toBe('english-b1');
    }
    expect($learner->fresh()?->onboarding_state)->toBe('done')
        ->and(onbRaceEvents($learner->id, ProductEventName::OnboardingCompleted))->toBe(1)
        ->and(onbRaceEvents($learner->id, ProductEventName::CourseSelected))->toBe(1);
});

it('lets exactly one of the bot and the Mini App finish the onboarding when both do at once', function (int $round): void {
    $learner = Learner::factory()->create(['onboarding_state' => 'reminder', 'level' => 'B1']);

    $results = onbRaceInParallel([
        function () use ($learner): string {
            try {
                return app(OnboardingService::class)->apply(Learner::query()->findOrFail($learner->id), 'reminder', '2100')->state;
            } catch (OnboardingException $e) {
                return $e->errorCode; // the Mini App was first: the bot's button is stale
            }
        },
        fn (): ?string => app(TwaOnboardingService::class)->complete(Learner::query()->findOrFail($learner->id))->course['slug'] ?? null,
    ]);

    foreach ($results as $result) {
        expect($result['ok'])->toBeTrue(($result['error'] ?? '').' '.($result['message'] ?? ''));
    }
    expect($results[0]['result'])->toBeIn(['done', OnboardingException::STALE_STEP])
        ->and($results[1]['result'])->toBe('english-b1')
        ->and($learner->fresh()?->onboarding_state)->toBe('done')
        ->and(onbRaceEvents($learner->id, ProductEventName::OnboardingCompleted))->toBe(1);
})->with([1, 2, 3]);

it('offers a pending invitation once when two runs of the job (a retry, a duplicate) send at the same instant', function (): void {
    Learner::factory()->create(['telegram_user_id' => 9001, 'first_name' => 'Dilnoza', 'referral_code' => 'NEWFRND2', 'onboarding_state' => 'done']);
    $learner = Learner::factory()->create(['telegram_user_id' => 9312, 'onboarding_state' => 'done', 'meta' => ['pending_friend_invite' => 'NEWFRND2']]);

    $offer = function () use ($learner): int {
        $tg = new FakeTelegramClient;
        app()->instance(TelegramClient::class, $tg);
        (new OfferPendingInvitesJob($learner->id, OfferPendingInvitesJob::FRIEND))->handle(app(OrganizationMessenger::class), app(FriendsMessenger::class));

        return count(array_filter($tg->sent, fn (array $m): bool => ($m['markup']['inline_keyboard'][0][0]['callback_data'] ?? null) === 'fr1:snd:NEWFRND2'));
    };

    $results = onbRaceInParallel([$offer, $offer, $offer]);

    foreach ($results as $result) {
        expect($result['ok'])->toBeTrue(($result['error'] ?? '').' '.($result['message'] ?? ''));
    }
    expect(array_sum(array_column($results, 'result')))->toBe(1)
        ->and($learner->fresh()?->meta)->not->toHaveKey('pending_friend_invite');
});
