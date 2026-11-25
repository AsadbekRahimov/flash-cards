<?php

declare(strict_types=1);

/*
 * docs/35 O3: the broadcast of a launched poll (`PollDeliverySender`, `SendPollBatchJob`, `polls:sweep`) and the test send. Every row
 * is sent at most once: the lease keeps two workers apart, the conditional step to `sending` decides the send, a crash after it
 * leaves `sending` and the sweep makes it `unknown` (no second send). 403 → `forbidden` and the DM flag; 429 → back to `pending`,
 * the rest released, a successor after `retry_after`; another definite refusal → `failed` (reported); a timeout → `unknown`, the job
 * retries; the stop in the middle of a batch; `sending → sent`; the photo by `file_id`; the statements of a batch. The share of the
 * bot's budget (`reserveGlobalBelow`) is at the end. Races on PostgreSQL: PollConcurrencyTest.
 */

use App\Domain\Polls\DTO\PollBatchOutcome;
use App\Domain\Polls\Enums\PollDeliveryStatus;
use App\Domain\Polls\Enums\PollStatus;
use App\Domain\Polls\Exceptions\PollLaunchException;
use App\Domain\Polls\Services\PollDeliverySender;
use App\Domain\Polls\Services\PollLauncher;
use App\Domain\Polls\Services\PollSweeper;
use App\Domain\Polls\Services\PollTestSender;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\DTO\PhotoSource;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Services\TelegramMessageRateLimiter;
use App\Jobs\SendPollBatchJob;
use App\Models\Learner;
use App\Models\Poll;
use App\Models\PollDelivery;
use App\Models\PollOption;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeTelegramClient;
use Tests\Support\Polls\PollFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['polls.send_per_second' => 0, 'polls.batch_size' => 100, 'polls.workers' => 2, 'telegram.outbound.sync_wait_ms' => 0]);
    $this->tg = new FakeTelegramClient;
    app()->instance(TelegramClient::class, $this->tg);
});

/** @return array<string, PollDeliveryStatus> chat => status of the learner rows */
function pollStatuses(Poll $poll): array
{
    return PollDelivery::query()->where('poll_id', $poll->id)->whereNotNull('learner_id')->orderBy('id')->get()
        ->mapWithKeys(fn (PollDelivery $d): array => [(string) $d->telegram_user_id => $d->status])->all();
}

/** @return list<int|string> */
function pollChats(FakeTelegramClient $tg): array
{
    return [...array_column($tg->textMessages, 'chat_id'), ...array_column($tg->photoMessages, 'chat_id')];
}

function pollBatch(Poll $poll, ?CarbonImmutable $now = null): PollBatchOutcome
{
    return app(PollDeliverySender::class)->sendBatch($poll->id, 60, $now);
}

it('sends every recipient one text message with its buttons and finishes the poll through the job chain', function (): void {
    $learners = PollFixtures::learners(5);
    $learners[0]->update(['interface_locale' => 'uz']);
    $poll = PollFixtures::poll(['question' => ['ru' => 'Как дела?', 'uz' => 'Qalaysiz?']]);
    PollFixtures::tested($poll);
    config(['polls.batch_size' => 2]);

    app(PollLauncher::class)->launch($poll, PollFixtures::admin(null));

    expect($poll->fresh()->status)->toBe(PollStatus::Sent)
        ->and($this->tg->textMessages)->toHaveCount(5)
        ->and(collect(pollChats($this->tg))->sort()->values()->all())->toBe(collect($learners)->pluck('telegram_user_id')->sort()->values()->all())
        ->and(array_values(array_unique(array_map(fn (PollDeliveryStatus $s): string => $s->value, pollStatuses($poll)))))->toBe(['sent']);

    $uz = PollDelivery::query()->where('learner_id', $learners[0]->id)->firstOrFail();
    $message = collect($this->tg->textMessages)->firstWhere('chat_id', $learners[0]->telegram_user_id);
    expect($message['text'])->toBe('Qalaysiz?')
        ->and($message['markup']['inline_keyboard'])->toHaveCount(3)
        ->and($message['markup']['inline_keyboard'][0][0])->toBe(['text' => 'Variant 1', 'callback_data' => 'pl1:'.$uz->token.':0'])
        ->and($message['markup']['inline_keyboard'][2][0]['callback_data'])->toBe('pl1:'.$uz->token.':2')
        ->and($uz->message_id)->toBe($message['message_id'])
        ->and($uz->sent_at)->not->toBeNull();
});

it('numbers long options in the text, ticks boxes and adds the submit button for several answers', function (): void {
    $poll = PollFixtures::poll(['allows_multiple' => true, 'max_choices' => 2], 2);
    PollOption::query()->where('poll_id', $poll->id)->where('position', 1)
        ->update(['label' => json_encode(['ru' => 'Очень длинный вариант ответа на вопрос', 'uz' => 'Juda uzun javob varianti savolga'])]);
    PollFixtures::tested($poll);
    PollFixtures::learners(1);

    app(PollLauncher::class)->launch($poll, PollFixtures::admin(null));

    $message = $this->tg->textMessages[0];
    $token = PollDelivery::query()->where('poll_id', $poll->id)->whereNotNull('learner_id')->value('token');
    expect($message['text'])->toBe("Какой формат занятий вам удобнее?\n\n1. Вариант 1\n2. Очень длинный вариант ответа на вопрос")
        ->and(array_map(fn (array $row): array => $row[0], $message['markup']['inline_keyboard']))->toBe([
            ['text' => '☐ 1', 'callback_data' => "pl1:{$token}:0"],
            ['text' => '☐ 2', 'callback_data' => "pl1:{$token}:1"],
            ['text' => '✅ Отправить', 'callback_data' => "pl1:{$token}:s"],
        ]);
});

it('uploads the picture once on the test send and then sends every learner the photo by its file_id', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('polls/pic.png', 'png-bytes');
    $poll = PollFixtures::poll(['image_path' => 'polls/pic.png']);
    PollFixtures::learners(3);

    $test = app(PollTestSender::class)->send($poll, PollFixtures::admin(777000111), 'uz');

    expect($test->status)->toBe(PollDeliveryStatus::Sent)
        ->and($test->is_test)->toBeTrue()
        ->and($test->learner_id)->toBeNull()
        ->and($test->locale)->toBe('uz')
        ->and($this->tg->photoMessages[0]['kind'])->toBe(PhotoSource::KIND_FILE)
        ->and($this->tg->photoMessages[0]['chat_id'])->toBe(777000111)
        ->and($poll->fresh()->image_file_id)->toBe($this->tg->photoMessages[0]['file_id']);

    app(PollLauncher::class)->launch($poll->fresh(), PollFixtures::admin(null));

    $broadcast = array_slice($this->tg->photoMessages, 1);
    expect($broadcast)->toHaveCount(3)
        ->and(array_unique(array_column($broadcast, 'kind')))->toBe([PhotoSource::KIND_FILE_ID])
        ->and(array_unique(array_column($broadcast, 'photo')))->toBe([$poll->fresh()->image_file_id])
        ->and($broadcast[0]['caption'])->toBe('Какой формат занятий вам удобнее?');
});

it('uploads again once when Telegram refuses a stored file_id', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('polls/pic.png', 'png-bytes');
    $poll = PollFixtures::launched(2, ['image_path' => 'polls/pic.png', 'image_file_id' => 'stale-file-id']);
    $this->tg->failSends = 1;

    pollBatch($poll);

    expect(array_column($this->tg->photoMessages, 'kind'))->toBe([PhotoSource::KIND_FILE, PhotoSource::KIND_FILE_ID])
        ->and($poll->fresh()->image_file_id)->toBe($this->tg->photoMessages[0]['file_id'])
        ->and(array_values(array_map(fn ($s) => $s->value, pollStatuses($poll))))->toBe(['sent', 'sent']);
});

it('refuses a broadcast whose picture is neither on the disk nor known to Telegram, touching no row', function (): void {
    Storage::fake('local');
    $poll = PollFixtures::launched(2, ['image_path' => 'polls/missing.png']);

    expect(fn () => pollBatch($poll))->toThrow(RuntimeException::class);
    expect(array_values(array_map(fn ($s) => $s->value, pollStatuses($poll))))->toBe(['pending', 'pending'])
        ->and(PollDelivery::query()->whereNotNull('claimed_at')->whereNotNull('learner_id')->count())->toBe(0);
});

it('marks a 403 forbidden, closes the learner DM and goes on', function (): void {
    $poll = PollFixtures::launched(3);
    $first = PollDelivery::query()->where('poll_id', $poll->id)->whereNotNull('learner_id')->orderBy('id')->firstOrFail();
    $this->tg->failSends = 1;
    $this->tg->failSendsForbidden = 'bot_blocked';

    $outcome = pollBatch($poll);

    expect($outcome->forbidden)->toBe(1)->and($outcome->sent)->toBe(2)
        ->and($first->fresh()->status)->toBe(PollDeliveryStatus::Forbidden)
        ->and($first->fresh()->error_code)->toBe('bot_blocked')
        ->and(Learner::query()->find($first->learner_id)?->dm_blocked_at)->not->toBeNull()
        ->and(Learner::query()->find($first->learner_id)?->dm_blocked_reason)->toBe('bot_blocked');
});

it('puts a 429 back to pending with the rest of the lease and comes back after retry_after, then sends each once', function (): void {
    $poll = PollFixtures::launched(3);
    $this->tg->failSends = 1;
    $this->tg->failSendsRetryAfter = 7;

    $outcome = pollBatch($poll);

    expect($outcome->sent)->toBe(0)
        ->and($outcome->released)->toBe(3)
        ->and($outcome->more)->toBeTrue()
        ->and($outcome->delaySeconds)->toBeGreaterThanOrEqual(7)
        ->and(array_values(array_map(fn ($s) => $s->value, pollStatuses($poll))))->toBe(['pending', 'pending', 'pending'])
        ->and(PollDelivery::query()->whereNotNull('learner_id')->whereNotNull('claimed_at')->count())->toBe(0);

    pollBatch($poll);
    pollBatch($poll);

    expect($this->tg->textMessages)->toHaveCount(3)
        ->and(count(array_unique(pollChats($this->tg))))->toBe(3)
        ->and($poll->fresh()->status)->toBe(PollStatus::Sent);
});

it('marks another definite refusal failed and reports it, without stopping the batch', function (): void {
    Exceptions::fake();
    $poll = PollFixtures::launched(2);
    $this->tg->failSends = 1;

    $outcome = pollBatch($poll);

    expect($outcome->failed)->toBe(1)->and($outcome->sent)->toBe(1)
        ->and(PollDelivery::query()->where('status', 'failed')->value('error_code'))->toBe('http_403');
    Exceptions::assertReported(TelegramApiException::class);
});

it('marks a timeout unknown, releases the rest and throws; the retry never sends that row again', function (): void {
    $poll = PollFixtures::launched(3);
    $this->tg->failSends = 1;
    $this->tg->failSendsAsTransport = true;

    expect(fn () => pollBatch($poll))->toThrow(TelegramApiException::class);

    $statuses = array_values(array_map(fn ($s) => $s->value, pollStatuses($poll)));
    expect($statuses)->toBe(['unknown', 'pending', 'pending'])
        ->and(PollDelivery::query()->whereNotNull('learner_id')->where('status', 'pending')->whereNotNull('claimed_at')->count())->toBe(0);

    pollBatch($poll);
    pollBatch($poll);

    expect($this->tg->textMessages)->toHaveCount(2)
        ->and($poll->fresh()->status)->toBe(PollStatus::Sent);
});

it('stops in the middle of a batch: the message on its way goes out, nothing after it', function (): void {
    $poll = PollFixtures::launched(5);
    $sends = 0;
    $this->tg->beforeSend = function () use (&$sends, $poll): void {
        if (++$sends === 2) {
            app(PollLauncher::class)->cancel(Poll::query()->findOrFail($poll->id), PollFixtures::admin(null));
        }
    };

    $outcome = pollBatch($poll);

    expect($outcome->sent)->toBe(2)
        ->and($outcome->more)->toBeFalse()
        ->and($this->tg->textMessages)->toHaveCount(2)
        ->and(array_values(array_map(fn ($s) => $s->value, pollStatuses($poll))))->toBe(['sent', 'sent', 'skipped', 'skipped', 'skipped'])
        ->and($poll->fresh()->status)->toBe(PollStatus::Cancelled);

    expect(pollBatch($poll)->sent)->toBe(0)->and($this->tg->textMessages)->toHaveCount(2);
});

it('skips a learner who became unreachable after the launch, without a send', function (): void {
    $poll = PollFixtures::launched(4);
    $rows = PollDelivery::query()->where('poll_id', $poll->id)->whereNotNull('learner_id')->orderBy('id')->get();
    Learner::query()->whereKey($rows[0]->learner_id)->update(['dm_blocked_at' => now(), 'dm_blocked_reason' => 'bot_blocked']);
    Learner::query()->whereKey($rows[1]->learner_id)->update(['blocked_at' => now()]);
    Learner::query()->findOrFail($rows[2]->learner_id)->delete();

    $outcome = pollBatch($poll);

    expect($outcome->skipped)->toBe(3)->and($outcome->sent)->toBe(1)
        ->and($this->tg->textMessages)->toHaveCount(1)
        ->and($rows[0]->fresh()->error_code)->toBe('unreachable');
});

it('keeps two workers apart by the lease: a second batch during the first takes other rows, each learner gets one message', function (): void {
    config(['polls.batch_size' => 3]);
    $poll = PollFixtures::launched(6);
    $inner = null;
    $this->tg->beforeSend = function () use (&$inner, $poll): void {
        if ($inner === null) {
            $inner = false;
            $inner = pollBatch($poll);
        }
    };

    $outer = pollBatch($poll);

    expect($outer->sent)->toBe(3)
        ->and($inner->sent)->toBe(3)
        ->and($this->tg->textMessages)->toHaveCount(6)
        ->and(count(array_unique(pollChats($this->tg))))->toBe(6);
});

it('takes back the rows of a lease that ran out (a crashed worker) and leaves a live lease alone', function (): void {
    config(['polls.lease_seconds' => 300]);
    $poll = PollFixtures::launched(2);
    $now = CarbonImmutable::now()->utc()->startOfSecond();
    $rows = PollDelivery::query()->where('poll_id', $poll->id)->whereNotNull('learner_id')->orderBy('id')->get();
    $rows[0]->update(['claimed_at' => $now->subSeconds(301)]);
    $rows[1]->update(['claimed_at' => $now->subSeconds(10)]);

    $outcome = pollBatch($poll, $now);

    expect($outcome->sent)->toBe(1)
        ->and($rows[0]->fresh()->status)->toBe(PollDeliveryStatus::Sent)
        ->and($rows[1]->fresh()->status)->toBe(PollDeliveryStatus::Pending);
});

it('leaves a row whose worker died after the step to sending to the sweep: unknown, never sent again, the poll finished', function (): void {
    $poll = PollFixtures::launched(2);
    $this->tg->beforeSend = function (): void {
        throw new RuntimeException('worker killed');
    };

    expect(fn () => pollBatch($poll))->toThrow(RuntimeException::class, 'worker killed');
    $this->tg->beforeSend = null;
    $stuck = PollDelivery::query()->where('poll_id', $poll->id)->where('status', 'sending')->firstOrFail();

    // The rest is released by the lease running out; meanwhile the other row is sent by another batch.
    pollBatch($poll, CarbonImmutable::now()->addMinutes(6));
    expect($this->tg->textMessages)->toHaveCount(1)
        ->and($poll->fresh()->status)->toBe(PollStatus::Sending);

    expect(app(PollSweeper::class)->sweep(CarbonImmutable::now()->addMinutes(10))['unknown'])->toBe(0);
    $out = app(PollSweeper::class)->sweep(CarbonImmutable::now()->addMinutes(16));

    expect($out['unknown'])->toBe(1)
        ->and($out['finished'])->toBe(1)
        ->and($stuck->fresh()->status)->toBe(PollDeliveryStatus::Unknown)
        ->and($stuck->fresh()->error_code)->toBe('stuck')
        ->and($poll->fresh()->status)->toBe(PollStatus::Sent);

    pollBatch($poll);
    expect($this->tg->textMessages)->toHaveCount(1);
});

it('restarts the chains of a stalled broadcast and leaves a live one alone', function (): void {
    $poll = PollFixtures::launched(2, ['launched_at' => now()]);
    Queue::fake();

    expect(app(PollSweeper::class)->sweep()['restarted'])->toBe(1);
    Queue::assertPushed(SendPollBatchJob::class, 2);

    PollDelivery::query()->where('poll_id', $poll->id)->whereNotNull('learner_id')->limit(1)->update(['claimed_at' => now()]);
    Queue::fake();
    expect(app(PollSweeper::class)->sweep()['restarted'])->toBe(0);
    Queue::assertNothingPushed();
});

it('ends the broadcast at its deadline: nothing sent after it, the rest skipped, the poll sent', function (): void {
    $poll = PollFixtures::launched(2);
    $later = CarbonImmutable::now()->addHours(25);

    expect(pollBatch($poll, $later)->sent)->toBe(0);
    $out = app(PollSweeper::class)->sweep($later);

    expect($out['expired'])->toBe(2)
        ->and(array_values(array_map(fn ($s) => $s->value, pollStatuses($poll))))->toBe(['skipped', 'skipped'])
        ->and($poll->fresh()->status)->toBe(PollStatus::Sent)
        ->and($this->tg->textMessages)->toHaveCount(0);
});

it('skips rows of a stopped poll that an insert range committed after the stop', function (): void {
    $poll = PollFixtures::launched(2);
    app(PollLauncher::class)->cancel($poll, PollFixtures::admin(null));
    PollDelivery::query()->where('poll_id', $poll->id)->whereNotNull('learner_id')->update(['status' => 'pending', 'error_code' => null]);

    expect(app(PollSweeper::class)->sweep()['skipped'])->toBe(2);
});

it('does not finish a poll while a row is still sending or its insert is unfinished', function (): void {
    $poll = PollFixtures::launched(1);
    $row = PollDelivery::query()->where('poll_id', $poll->id)->whereNotNull('learner_id')->firstOrFail();
    $row->update(['status' => PollDeliveryStatus::Sending, 'claimed_at' => now()]);
    $sender = app(PollDeliverySender::class);

    expect($sender->finishIfDone($poll->id))->toBeFalse();
    $row->update(['status' => PollDeliveryStatus::Sent]);
    $poll->forceFill(['recipient_count' => null])->save();
    expect($sender->finishIfDone($poll->id))->toBeFalse();
    $poll->forceFill(['recipient_count' => 1])->save();
    // A test row stuck in sending does not hold the broadcast.
    PollDelivery::factory()->test()->create(['poll_id' => $poll->id, 'status' => PollDeliveryStatus::Sending]);
    expect($sender->finishIfDone($poll->id))->toBeTrue()
        ->and($poll->fresh()->status)->toBe(PollStatus::Sent);
});

it('keeps a batch within 2 statements per message plus a few per batch', function (): void {
    $poll = PollFixtures::launched(10);
    $statements = 0;
    DB::listen(function () use (&$statements): void {
        $statements++;
    });

    pollBatch($poll);
    $first = $statements;
    $statements = 0;
    pollBatch($poll);

    // poll, options, lease + (step to sending, result) × 10
    expect($first)->toBe(3 + 2 * 10)
        // poll, options, empty lease, finish
        ->and($statements)->toBe(4)
        ->and($poll->fresh()->status)->toBe(PollStatus::Sent);
});

it('queues the successor of a batch while there may be work left, and none at the end', function (): void {
    config(['polls.batch_size' => 2]);
    $poll = PollFixtures::launched(3);
    Queue::fake();

    (new SendPollBatchJob($poll->id, now()->addDay()->getTimestamp()))->handle(app(PollDeliverySender::class));
    Queue::assertPushed(SendPollBatchJob::class, 1);

    Queue::fake();
    (new SendPollBatchJob($poll->id, now()->addDay()->getTimestamp()))->handle(app(PollDeliverySender::class));
    (new SendPollBatchJob($poll->id, now()->addDay()->getTimestamp()))->handle(app(PollDeliverySender::class));
    Queue::assertPushed(SendPollBatchJob::class, 1);
    expect($poll->fresh()->status)->toBe(PollStatus::Sent);
});

it('stops a batch without a slot of the bulk budget and hands the lease back', function (): void {
    $poll = PollFixtures::launched(2);
    Cache::put(TelegramMessageRateLimiter::CONTENDED_KEY, 1, 2);

    $outcome = pollBatch($poll);

    expect($outcome->sent)->toBe(0)->and($outcome->released)->toBe(2)->and($outcome->more)->toBeTrue()
        ->and($outcome->delaySeconds)->toBeGreaterThanOrEqual(1)
        ->and(PollDelivery::query()->whereNotNull('learner_id')->whereNotNull('claimed_at')->count())->toBe(0);
});

it('refuses a test send without a linked Telegram or for a finished poll, and records a refusal on the row', function (): void {
    $poll = PollFixtures::poll();
    $sender = app(PollTestSender::class);

    expect(fn () => $sender->send($poll, PollFixtures::admin(null)))->toThrow(PollLaunchException::class);
    $closed = PollFixtures::poll(['status' => PollStatus::Closed]);
    try {
        $sender->send($closed, PollFixtures::admin(1234));
    } catch (PollLaunchException $e) {
        expect($e->reason)->toBe(PollLaunchException::FINISHED);
    }

    $this->tg->failSends = 1;
    $this->tg->failSendsForbidden = 'not_started';
    $refused = $sender->send($poll, PollFixtures::admin(4321));
    expect($refused->status)->toBe(PollDeliveryStatus::Forbidden)->and($refused->error_code)->toBe('not_started');

    $this->tg->failSends = 1;
    $this->tg->failSendsForbidden = null;
    $this->tg->failSendsAsTransport = true;
    expect($sender->send($poll, PollFixtures::admin(4322))->status)->toBe(PollDeliveryStatus::Unknown);

    // None of these lets the poll launch.
    expect(fn () => app(PollLauncher::class)->launch($poll, PollFixtures::admin(null)))->toThrow(PollLaunchException::class);
});

describe('the share of the bot budget for bulk sends', function (): void {
    it('gives bulk slots only below the ceiling of the whole second', function (): void {
        $limiter = new TelegramMessageRateLimiter(20, 60, 25);
        $this->travelTo(CarbonImmutable::parse('2026-11-28 10:00:00.100'));

        $bulk = 0;
        while ($limiter->reserveGlobalBelow(15)) {
            $bulk++;
            if ($bulk > 30) {
                break;
            }
        }
        expect($bulk)->toBe(15);

        // The others still have the rest of the second.
        $others = 0;
        while ($limiter->reserveGlobal()) {
            $others++;
        }
        expect($others)->toBe(10);
    });

    it('lets replies and normal sends of the second shrink the bulk share', function (): void {
        $limiter = new TelegramMessageRateLimiter(20, 60, 25);
        $this->travelTo(CarbonImmutable::parse('2026-11-28 10:00:01.100'));
        for ($i = 0; $i < 12; $i++) {
            $limiter->consumeGlobal();
        }

        $bulk = 0;
        while ($limiter->reserveGlobalBelow(15) && $bulk < 30) {
            $bulk++;
        }
        expect($bulk)->toBe(3);
    });

    it('yields entirely while a normal sender was refused, and during a pause after a 429', function (): void {
        $limiter = new TelegramMessageRateLimiter(20, 60, 2);
        $this->travelTo(CarbonImmutable::parse('2026-11-28 10:00:02.100'));
        expect($limiter->reserveGlobal())->toBeTrue()
            ->and($limiter->reserveGlobal())->toBeTrue()
            ->and($limiter->reserveGlobal())->toBeFalse()
            ->and(Cache::has(TelegramMessageRateLimiter::CONTENDED_KEY))->toBeTrue();

        $this->travelTo(CarbonImmutable::parse('2026-11-28 10:00:03.100'));
        expect($limiter->reserveGlobalBelow(15))->toBeFalse();
        $this->travelTo(CarbonImmutable::parse('2026-11-28 10:00:05.100'));
        Cache::forget(TelegramMessageRateLimiter::CONTENDED_KEY);
        expect($limiter->reserveGlobalBelow(15))->toBeTrue();

        $limiter->pause(5, 12345);
        expect($limiter->reserveGlobalBelow(15))->toBeFalse()
            ->and($limiter->reserveGlobalBelow(0))->toBeFalse();
    });

    it('caps the bulk ceiling by the bot-wide limit and treats 0 as no ceiling of its own', function (): void {
        $limiter = new TelegramMessageRateLimiter(20, 60, 3);
        $this->travelTo(CarbonImmutable::parse('2026-11-28 10:00:06.100'));
        $n = 0;
        while ($limiter->reserveGlobalBelow(15) && $n < 10) {
            $n++;
        }
        expect($n)->toBe(3);

        $off = new TelegramMessageRateLimiter(20, 60, 0);
        $this->travelTo(CarbonImmutable::parse('2026-11-28 10:00:07.100'));
        $m = 0;
        while ($off->reserveGlobalBelow(0) && $m < 50) {
            $m++;
        }
        expect($m)->toBe(50);
    });
});

it('goes on past a row purged with its learner in the middle of a batch', function (): void {
    $poll = PollFixtures::launched(3);
    $rows = PollDelivery::query()->where('poll_id', $poll->id)->whereNotNull('learner_id')->orderBy('id')->get();
    $this->tg->beforeSend = function () use ($rows): void {
        if (Learner::withTrashed()->whereKey($rows[1]->learner_id)->exists()) {
            Learner::withTrashed()->findOrFail($rows[1]->learner_id)->forceDelete();
        }
    };

    $outcome = pollBatch($poll);

    expect($outcome->sent)->toBe(2)
        ->and($rows[2]->fresh()->status)->toBe(PollDeliveryStatus::Sent)
        ->and(PollDelivery::query()->find($rows[1]->id))->toBeNull();
});

it('runs polls:sweep from the console', function (): void {
    $this->artisan('polls:sweep')->assertSuccessful();
});
