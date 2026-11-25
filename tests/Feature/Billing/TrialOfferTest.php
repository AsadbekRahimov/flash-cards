<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Billing\Services\TrialOfferSender;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramRateLimitedException;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Jobs\SendTrialOfferJob;
use App\Models\Learner;
use App\Models\NotificationLog;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\ProductEvent;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeTelegramClient;
use Tests\Support\QueryLog;

/*
 * The offers after the welcome trial (docs/34 §2д, P5): steps 0 / 3 / 10 after its end `T`, between 11:00 and 19:00 of the learner's
 * clock, each once, at most one message about Pro a local day (with the renewal reminders), stopped by Pro / a closed DM / an opt-out;
 * prices from the catalog; the marker under docs/34 §4в п. 8. Runs on sqlite + the array cache and, in CI, on PostgreSQL + Redis.
 */

uses(RefreshDatabase::class);

/** The end of the trial: 12:00 in Tashkent. */
const TO_END = '2026-11-20 07:00:00';

beforeEach(function (): void {
    config([
        'twa.base_url' => 'https://twa.test',
        'billing.trial_offers.after_days' => [0, 3, 10],
        'billing.paywall.mode' => 'purchase',
        'billing.providers.telegram_stars.enabled' => true,
        'billing.providers.payme.enabled' => true,
        'referrals.milestones' => [3, 6],
        // Practice and the game closed: the four lines of "what Pro gives" are the ones every learner gets.
        'practice.access' => 'off',
        'game.access' => 'off',
        'billing.plans.free.limits.new_words_per_day' => 10,
        'billing.plans.free.limits.daily_goal_max' => 10,
    ]);

    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
});

/** @param array<string, mixed> $learner */
function toTrial(array $learner = [], ?string $endsAt = null): Subscription
{
    $end = CarbonImmutable::parse($endsAt ?? TO_END, 'UTC');
    $owner = Learner::factory()->create(['timezone' => 'Asia/Tashkent', 'interface_locale' => 'ru', ...$learner]);

    return Subscription::factory()->create([
        'subscriber_id' => $owner->id,
        'provider' => 'trial',
        'status' => 'expired',
        'starts_at' => $end->subDays(14),
        'current_period_ends_at' => $end,
        'ended_at' => $end,
    ])->refresh();
}

function toLearner(Subscription $trial): Learner
{
    return Learner::query()->findOrFail($trial->subscriber_id);
}

function toSend(Subscription $trial, int $step, ?CarbonImmutable $now = null): bool
{
    return app(TrialOfferSender::class)->send($trial->id, $step, CarbonImmutable::instance($trial->current_period_ends_at), $now);
}

/** @return list<array<string, mixed>> */
function toMarkers(Learner $learner): array
{
    return NotificationLog::query()->where('learner_id', $learner->id)->where('type', 'trial_offer')->get()->map(fn (NotificationLog $l): array => $l->payload)->all();
}

/** @return list<string> every button text of a markup, row by row */
function toButtons(?array $markup): array
{
    $texts = [];
    foreach ((array) ($markup['inline_keyboard'] ?? []) as $row) {
        foreach ($row as $button) {
            $texts[] = $button['text'];
        }
    }

    return $texts;
}

// --- the schedule and the selection -------------------------------------------------------------------------------

it('runs billing:trial-offers hourly without overlapping', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($e): bool => str_contains((string) $e->command, 'billing:trial-offers'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('sends every step exactly once, however often the command runs', function (): void {
    $trial = toTrial();
    $learner = toLearner($trial);

    // Before the hour after T: nothing yet.
    $this->travelTo(CarbonImmutable::parse('2026-11-20 07:30:00', 'UTC'));
    $this->artisan('billing:trial-offers')->expectsOutputToContain('Trial offers queued: 0');

    foreach (['2026-11-20 09:00:00' => 0, '2026-11-23 09:00:00' => 3, '2026-11-30 09:00:00' => 10] as $at => $step) {
        $this->travelTo(CarbonImmutable::parse($at, 'UTC'));
        $this->artisan('billing:trial-offers')->assertSuccessful();
        $this->artisan('billing:trial-offers')->assertSuccessful();
        // A repeated job (the queue retried it) is silent as well.
        SendTrialOfferJob::dispatchSync($trial->id, $step, CarbonImmutable::instance($trial->current_period_ends_at)->toIso8601String());
    }

    // Days between the steps: nothing.
    $this->travelTo(CarbonImmutable::parse('2026-11-25 09:00:00', 'UTC'));
    $this->artisan('billing:trial-offers')->expectsOutputToContain('Trial offers queued: 0');

    expect($this->tg->sent)->toHaveCount(3)
        ->and(array_column(toMarkers($learner), 'step'))->toEqual([0, 3, 10])
        ->and(ProductEvent::query()->where('name', ProductEventName::TrialOfferSent->value)->count())->toBe(3);
});

it('queues only inside 11:00-19:00 of the learner clock', function (string $zone, string $at, int $queued): void {
    Queue::fake();
    toTrial(['timezone' => $zone], '2026-11-20 03:00:00');

    $this->travelTo(CarbonImmutable::parse($at, 'UTC'));
    $this->artisan('billing:trial-offers')->assertSuccessful();

    Queue::assertPushed(SendTrialOfferJob::class, $queued);
})->with([
    'Tashkent 09:00' => ['Asia/Tashkent', '2026-11-20 04:00:00', 0],
    'Tashkent 11:00' => ['Asia/Tashkent', '2026-11-20 06:00:00', 1],
    'Tashkent 18:59' => ['Asia/Tashkent', '2026-11-20 13:59:00', 1],
    'Tashkent 19:00' => ['Asia/Tashkent', '2026-11-20 14:00:00', 0],
    'New York 04:00' => ['America/New_York', '2026-11-20 09:00:00', 0],
    'New York 12:00' => ['America/New_York', '2026-11-20 17:00:00', 1],
]);

it('does not send a step older than a day, neither from the command nor from a late job', function (): void {
    Queue::fake();
    $trial = toTrial();

    // T + 1 day + 1 hour, 13:00 in Tashkent: step 0 is stale, step 3 not due yet.
    $this->travelTo(CarbonImmutable::parse('2026-11-21 08:00:00', 'UTC'));
    $this->artisan('billing:trial-offers')->expectsOutputToContain('Trial offers queued: 0');

    expect(toSend($trial, 0))->toBeFalse()
        ->and($this->tg->sent)->toBe([]);
});

it('does not send from a job that runs outside the window: no marker, and the window of the next day sends it', function (): void {
    $trial = toTrial();
    $learner = toLearner($trial);

    // Queued at 14:00 in Tashkent, held back by the queue (or a 429) until 21:00: silent, nothing written.
    $this->travelTo(CarbonImmutable::parse('2026-11-20 16:00:00', 'UTC'));
    expect(toSend($trial, 0))->toBeFalse()
        ->and(toMarkers($learner))->toBe([])
        ->and($this->tg->sent)->toBe([]);

    // 10:30 the next morning: still before the window.
    $this->travelTo(CarbonImmutable::parse('2026-11-21 05:30:00', 'UTC'));
    expect(toSend($trial, 0))->toBeFalse()->and(toMarkers($learner))->toBe([]);

    // 11:30, T + 23.5 h: the step is still fresh and goes.
    $this->travelTo(CarbonImmutable::parse('2026-11-21 06:30:00', 'UTC'));
    $this->artisan('billing:trial-offers')->expectsOutputToContain('Trial offers queued: 1');
    expect($this->tg->sent)->toHaveCount(1)
        ->and(array_column(toMarkers($learner), 'step'))->toBe([0]);
});

it('does not queue a step already sent again, and the statements of the run do not grow with the trials already handled', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00:00', 'UTC'));
    $trials = [toTrial(), toTrial(), toTrial()];

    Queue::fake();
    expect(app(TrialOfferSender::class)->queueDue())->toBe(3);
    Queue::assertPushed(SendTrialOfferJob::class, 3);

    // Step 0 of the first trial goes out (the job ran); the next hourly run in the same window queues only the other two.
    expect(toSend($trials[0], 0))->toBeTrue();
    Queue::fake();
    expect(app(TrialOfferSender::class)->queueDue())->toBe(2);
    Queue::assertPushed(SendTrialOfferJob::class, 2);
    Queue::assertNotPushed(SendTrialOfferJob::class, fn (SendTrialOfferJob $job): bool => $job->subscriptionId === $trials[0]->id);

    // A marker of another step, or of the same step before this end (an older trial), does not hide the step.
    $other = [
        NotificationLog::query()->create(['learner_id' => $trials[1]->subscriber_id, 'type' => 'trial_offer', 'payload' => ['key' => 'x', 'step' => 3], 'sent_at' => now()]),
        NotificationLog::query()->create(['learner_id' => $trials[2]->subscriber_id, 'type' => 'trial_offer', 'payload' => ['key' => 'y', 'step' => 0], 'sent_at' => CarbonImmutable::parse(TO_END)->subDay()]),
    ];
    expect(app(TrialOfferSender::class)->queueDue())->toBe(2);
    foreach ($other as $row) {
        $row->delete();
    }

    // Every step sent: nothing is queued, and the run costs the same statements as with one trial handled.
    expect(toSend($trials[1], 0))->toBeTrue()
        ->and(toSend($trials[2], 0))->toBeTrue();
    $few = QueryLog::during(fn () => app(TrialOfferSender::class)->queueDue());
    foreach (range(1, 5) as $i) {
        $more = toTrial();
        NotificationLog::query()->create(['learner_id' => $more->subscriber_id, 'type' => 'trial_offer', 'payload' => ['key' => "k{$i}", 'step' => 0], 'sent_at' => now()]);
    }
    $many = QueryLog::during(fn () => app(TrialOfferSender::class)->queueDue());

    // One statement a step of the chain, whatever was handled before: the sent ones are not read back.
    expect(app(TrialOfferSender::class)->queueDue())->toBe(0)
        ->and(count($few))->toBe(count(TrialOfferSender::steps()))
        ->and(count($many))->toBe(count($few));
});

it('leaves out of the selection a blocked learner, a closed DM, a deleted learner and one who opted out', function (): void {
    Queue::fake();
    toTrial(['blocked_at' => now()]);
    toTrial(['dm_blocked_at' => now()]);
    toTrial(['meta' => [TrialOfferSender::OPT_OUT_META => true]]);
    toLearner(toTrial())->delete();
    $kept = toTrial(['meta' => [TrialOfferSender::OPT_OUT_META => false]]);

    $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00:00', 'UTC'));
    $this->artisan('billing:trial-offers')->expectsOutputToContain('Trial offers queued: 1');

    Queue::assertPushed(SendTrialOfferJob::class, fn (SendTrialOfferJob $job): bool => $job->subscriptionId === $kept->id && $job->step === 0);
});

// --- the texts ----------------------------------------------------------------------------------------------------

it('says on the day of the end what Free is now, what Pro gives, the prices of the catalog, with the buy buttons, friends and the opt-out', function (): void {
    config(['practice.access' => 'all', 'practice.plan' => 'all', 'practice.battle.enabled' => false, 'practice.free_limits.sessions_per_day' => 3]);
    $trial = toTrial();
    $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00:00', 'UTC'));

    expect(toSend($trial, 0))->toBeTrue();

    $message = $this->tg->sent[0];
    expect($message['chat_id'])->toBe(toLearner($trial)->telegram_user_id)
        ->and($message['text'])->toBe("Пробный Pro закончился. Теперь у вас Free — в день: 10 новых слов, цель до 10 карточек, 3 игры со словами.\n\n"
            ."Что даёт Pro:\n"
            ."• Новые слова без дневного лимита\n"
            ."• Практика без дневного лимита\n"
            ."• Свои колоды слов\n"
            ."• Заморозка серии: пропуск дня не обрывает серию\n\n"
            ."Muhrla Pro — от 150 ⭐ / 29 000 сум в месяц. Выгоднее на срок: 3 месяца −14 %, 1 год −28 %.\n"
            .'Выберите срок и способ оплаты:')
        ->and(toButtons($message['markup']))->toBe([
            '⭐ Stars · 1 месяц · 150 ⭐',
            'Payme · 1 месяц · 29 000 сум',
            '⭐ Stars · 3 месяца · 390 ⭐',
            'Payme · 3 месяца · 75 000 сум',
            '⭐ Stars · 1 год · 1 300 ⭐',
            'Payme · 1 год · 249 000 сум',
            '👥 Пригласить друзей',
            '🔕 Не присылать предложения',
        ])
        ->and($this->tg->lastCallbackData())->toContain('pro1:buy:month:telegram_stars', 'bill1:offers_off');

    $rows = $message['markup']['inline_keyboard'];
    expect($rows[count($rows) - 2][0]['web_app']['url'])->toBe('https://twa.test/twa/referrals?from=trial_offer');
});

it('sums up the trial on step 3: the new words of its local days and the best streak', function (): void {
    $trial = toTrial(['streak_best' => 6]);
    $learner = toLearner($trial);
    // Inside [start, T] of Tashkent: counted; the day after T: not.
    foreach (['2026-11-06' => 5, '2026-11-15' => 30, '2026-11-20' => 7, '2026-11-21' => 100] as $date => $words) {
        DB::table('learner_daily_stats')->insert(['learner_id' => $learner->id, 'date' => $date, 'new_words_count' => $words, 'reviews_count' => 0]);
    }
    $this->travelTo(CarbonImmutable::parse('2026-11-23 09:00:00', 'UTC'));

    expect(toSend($trial, 3))->toBeTrue()
        ->and($this->tg->sent[0]['text'])->toStartWith("Пробный Pro закончился 20.11.2026.\n"
            ."За пробный период вы выучили 42 новых слова.\n"
            ."Лучшая серия — 6 дней.\n"
            ."Верните Pro — и учитесь дальше без дневных лимитов.\n\n"
            ."Что даёт Pro:\n");
});

it('calls step 10 the last reminder, without a discount', function (): void {
    $trial = toTrial();
    $this->travelTo(CarbonImmutable::parse('2026-11-30 09:00:00', 'UTC'));

    expect(toSend($trial, 10))->toBeTrue()
        ->and($this->tg->sent[0]['text'])->toStartWith('Последнее напоминание о Pro: пробный период закончился 20.11.2026. Больше писать об этом не будем — Pro можно вернуть в любой момент в /pro.')
        ->and($this->tg->sent[0]['text'])->not->toContain('скидк')
        ->and($this->tg->sent[0]['text'])->toContain('Muhrla Pro — от 150 ⭐ / 29 000 сум в месяц.');
});

it('writes in Uzbek to an Uzbek learner', function (): void {
    $trial = toTrial(['interface_locale' => 'uz']);
    $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00:00', 'UTC'));

    toSend($trial, 0);

    expect($this->tg->sent[0]['text'])->toStartWith("Pro sinov muddati tugadi. Endi sizda Free — kuniga: 10 ta yangi so'z, maqsad 10 tagacha kartochka.\n\nPro nima beradi:\n")
        ->and($this->tg->sent[0]['text'])->toContain("Muhrla Pro narxi — oyiga 150 ⭐ / 29 000 so'm.")
        ->and(toButtons($this->tg->sent[0]['markup']))->toContain("👥 Do'stlarni taklif qilish", '🔕 Takliflar kerak emas');
});

it('sends step 0 without prices when nothing is for sale, and steps 3 and 10 not at all', function (): void {
    config(['billing.paywall.mode' => 'coming_soon']);
    $trial = toTrial();
    $learner = toLearner($trial);

    $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00:00', 'UTC'));
    expect(toSend($trial, 0))->toBeTrue()
        ->and($this->tg->sent[0]['text'])->toEndWith("Muhrla Pro скоро откроется. Есть промокод? Отправьте /promo КОД.\nПригласите друзей — получите Pro бесплатно: /invite")
        ->and(toButtons($this->tg->sent[0]['markup']))->toBe(['👥 Пригласить друзей', '🔕 Не присылать предложения'])
        ->and(ProductEvent::query()->where('name', 'trial_offer.sent')->first()?->props)->toEqual(['step' => 0, 'subscription_id' => $trial->id, 'priced' => false]);

    $this->travelTo(CarbonImmutable::parse('2026-11-23 09:00:00', 'UTC'));
    expect(toSend($trial, 3))->toBeFalse();
    $this->travelTo(CarbonImmutable::parse('2026-11-30 09:00:00', 'UTC'));
    expect(toSend($trial, 10))->toBeFalse()
        ->and($this->tg->sent)->toHaveCount(1)
        ->and(toMarkers($learner))->toHaveCount(1);
});

// --- stops --------------------------------------------------------------------------------------------------------

it('stops when the learner has Pro again, read past the cache', function (string $how): void {
    $trial = toTrial();
    $learner = toLearner($trial);
    $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00:00', 'UTC'));
    // The snapshot says Free and stays there: the Pro below is written without a forget().
    app(EntitlementResolver::class)->for($learner);

    match ($how) {
        'purchase' => Subscription::factory()->create(['subscriber_id' => $learner->id, 'provider' => 'payme']),
        'promo' => Subscription::factory()->create(['subscriber_id' => $learner->id, 'provider' => 'promo']),
        'friends' => Subscription::factory()->create(['subscriber_id' => $learner->id, 'provider' => 'reward']),
        'center' => (function () use ($learner): void {
            $organization = Organization::factory()->create();
            Subscription::factory()->forOrganization($organization)->create();
            OrganizationMember::factory()->create(['organization_id' => $organization->id, 'learner_id' => $learner->id, 'seat_state' => 'seated', 'seated_at' => now()]);
        })(),
    };

    expect(toSend($trial, 0))->toBeFalse()
        ->and($this->tg->sent)->toBe([])
        ->and(toMarkers($learner))->toBe([]);
})->with(['purchase', 'promo', 'friends', 'center']);

it('stops when the trial row became something else (bought during the trial, extended)', function (): void {
    $trial = toTrial();
    $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00:00', 'UTC'));
    $endsAt = CarbonImmutable::instance($trial->current_period_ends_at);

    $trial->update(['provider' => 'telegram_stars', 'status' => 'active', 'current_period_ends_at' => $endsAt->addDays(30)]);

    expect(app(TrialOfferSender::class)->send($trial->id, 0, $endsAt))->toBeFalse()
        ->and($this->tg->sent)->toBe([]);
});

it('stops for a blocked learner, a closed DM, a deleted learner and one who opted out', function (string $state): void {
    $trial = toTrial();
    $learner = toLearner($trial);
    match ($state) {
        'blocked' => $learner->update(['blocked_at' => now()]),
        'dm closed' => $learner->update(['dm_blocked_at' => now()]),
        'deleted' => $learner->delete(),
        'opted out' => $learner->update(['meta' => [TrialOfferSender::OPT_OUT_META => true]]),
    };
    $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00:00', 'UTC'));

    expect(toSend($trial, 0))->toBeFalse()
        ->and($this->tg->sent)->toBe([]);
})->with(['blocked', 'dm closed', 'deleted', 'opted out']);

it('sends no second message about Pro in the same local day, a renewal reminder included', function (string $type): void {
    $trial = toTrial();
    $learner = toLearner($trial);
    // 00:30 in Tashkent of the same local day (19:30 UTC of the day before).
    NotificationLog::query()->create(['learner_id' => $learner->id, 'type' => $type, 'payload' => ['key' => 'other'], 'sent_at' => CarbonImmutable::parse('2026-11-19 19:30:00', 'UTC')]);
    $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00:00', 'UTC'));

    expect(toSend($trial, 0))->toBeFalse()
        ->and(toMarkers($learner))->toHaveCount($type === 'trial_offer' ? 1 : 0);

    // The next run of the next local day inside the window (11:00 in Tashkent; step 0 still fresh: T + 23 h) sends it.
    $this->travelTo(CarbonImmutable::parse('2026-11-21 06:00:00', 'UTC'));
    expect(toSend($trial, 0))->toBeTrue();
})->with(['renewal_reminder', 'trial_offer']);

it('does not count a learning reminder as a message about Pro', function (): void {
    $trial = toTrial();
    NotificationLog::query()->create(['learner_id' => $trial->subscriber_id, 'type' => 'review_reminder', 'payload' => [], 'sent_at' => CarbonImmutable::parse('2026-11-20 08:00:00', 'UTC')]);
    $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00:00', 'UTC'));

    expect(toSend($trial, 0))->toBeTrue();
});

// --- errors (docs/34 §4в п. 8) ------------------------------------------------------------------------------------

it('marks the DM unreachable on a 403 and keeps no marker', function (): void {
    $trial = toTrial();
    $learner = toLearner($trial);
    $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00:00', 'UTC'));
    $this->tg->failSends = 1;
    $this->tg->failSendsForbidden = 'bot_blocked';

    expect(toSend($trial, 0))->toBeFalse()
        ->and(toMarkers($learner))->toBe([])
        ->and($learner->fresh()?->dm_blocked_at)->not->toBeNull()
        ->and($learner->fresh()?->dm_blocked_reason)->toBe('bot_blocked');
});

it('rethrows a 429 without a marker, and the retry delivers exactly one', function (): void {
    $trial = toTrial();
    $learner = toLearner($trial);
    $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00:00', 'UTC'));
    $this->tg->failSends = 1;
    $this->tg->failSendsRetryAfter = 7;

    expect(fn () => toSend($trial, 0))->toThrow(TelegramRateLimitedException::class)
        ->and(toMarkers($learner))->toBe([]);

    expect(toSend($trial, 0))->toBeTrue()
        ->and(toSend($trial, 0))->toBeFalse()
        ->and($this->tg->sent)->toHaveCount(1)
        ->and(toMarkers($learner))->toHaveCount(1);
});

it('keeps the marker on a timeout: the retry stays silent', function (): void {
    $trial = toTrial();
    $learner = toLearner($trial);
    $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00:00', 'UTC'));
    $this->tg->failSends = 1;
    $this->tg->failSendsAsTransport = true;

    expect(fn () => toSend($trial, 0))->toThrow(TelegramApiException::class);

    expect(toSend($trial, 0))->toBeFalse()
        ->and($this->tg->sent)->toBe([])
        ->and(toMarkers($learner))->toEqual([['key' => $trial->id.':0@2026-11-20T07:00:00+00:00', 'subscription_id' => $trial->id, 'step' => 0]]);
});

// --- the opt-out --------------------------------------------------------------------------------------------------

it('opts out on bill1:offers_off once: one event, the same answer on a second tap, and the chain stops', function (): void {
    $trial = toTrial();
    $learner = toLearner($trial);
    $tap = fn (string $id) => app(TelegramDispatcher::class)->dispatch(['callback_query' => [
        'id' => $id,
        'from' => ['id' => $learner->telegram_user_id, 'first_name' => 'Ali', 'language_code' => 'ru'],
        'message' => ['message_id' => 5, 'chat' => ['id' => $learner->telegram_user_id, 'type' => 'private']],
        'data' => 'bill1:offers_off',
    ]]);

    $tap('cb-1');
    $tap('cb-2');

    $answer = ['text' => 'Хорошо, предложений Pro больше не будет. Сообщения об оплате и напоминания о занятиях останутся.', 'show_alert' => true];
    expect($learner->fresh()?->meta[TrialOfferSender::OPT_OUT_META] ?? null)->toBeTrue()
        ->and(ProductEvent::query()->where('name', ProductEventName::TrialOfferOptedOut->value)->where('learner_id', $learner->id)->count())->toBe(1)
        ->and($this->tg->answeredTexts['cb-1'])->toBe($answer)
        ->and($this->tg->answeredTexts['cb-2'])->toBe($answer);

    $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00:00', 'UTC'));
    expect(toSend($trial, 0))->toBeFalse();
});

it('ignores bill1:offers_off from a chat that is not the tapper own DM, and from an unknown account', function (): void {
    $learner = Learner::factory()->create();

    app(TelegramDispatcher::class)->dispatch(['callback_query' => [
        'id' => 'cb-g',
        'from' => ['id' => $learner->telegram_user_id, 'first_name' => 'Ali'],
        'message' => ['message_id' => 5, 'chat' => ['id' => -100500, 'type' => 'supergroup']],
        'data' => 'bill1:offers_off',
    ]]);
    app(TelegramDispatcher::class)->dispatch(['callback_query' => [
        'id' => 'cb-u',
        'from' => ['id' => 777_000_123, 'first_name' => 'Nobody'],
        'message' => ['message_id' => 5, 'chat' => ['id' => 777_000_123, 'type' => 'private']],
        'data' => 'bill1:offers_off',
    ]]);

    expect($learner->fresh()?->meta[TrialOfferSender::OPT_OUT_META] ?? null)->toBeNull()
        ->and(Learner::query()->where('telegram_user_id', 777_000_123)->exists())->toBeFalse()
        ->and(ProductEvent::query()->where('name', ProductEventName::TrialOfferOptedOut->value)->count())->toBe(0);
});
