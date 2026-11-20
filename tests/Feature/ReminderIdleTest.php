<?php

declare(strict_types=1);

use App\Domain\Learners\Enums\ReminderKind;
use App\Domain\Learners\Enums\ReminderOutcome;
use App\Domain\Learners\Services\ReminderContentResolver;
use App\Domain\Learners\Services\ReminderSender;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Models\Course;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\NotificationLog;
use App\Models\Word;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeTelegramClient;

/*
 * Reminders for a break in learning (owner decision 2026-11-16, docs/12 §7.5, docs/06 §6):
 * `idle` = local days without reviews; silence after 60, sparse after 7, a "N days" text from 3,
 * "new words" / "start" / "choose a course" when there is nothing to repeat.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['twa.base_url' => 'https://twa.test']);
    // 2026-10-18 14:05 UTC = 19:05 in Tashkent (the factory default zone).
    $this->travelTo(CarbonImmutable::parse('2026-10-18 14:05:00', 'UTC'));

    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
});

/**
 * A learner whose last reviews were `$idle` local days ago (`$studied`), or who registered `$idle` local days
 * ago and never studied. Local today is 2026-10-18.
 *
 * @param  array<string, mixed>  $attributes
 */
function idLearner(int $idle, bool $studied = true, bool $course = true, array $attributes = []): Learner
{
    $day = CarbonImmutable::parse('2026-10-18')->subDays($idle);

    $learner = Learner::factory()->create([
        'telegram_user_id' => random_int(1_000_000, 9_999_999),
        'interface_locale' => 'ru',
        'reminder_time' => '19:00',
        'active_course_id' => $course ? Course::factory()->create()->id : null,
        'created_at' => $studied ? $day->subDays(30) : $day->setTime(10, 0),
        ...$attributes,
    ]);

    if ($studied) {
        LearnerDailyStats::factory()->create(['learner_id' => $learner->id, 'date' => $day->toDateString(), 'reviews_count' => 4]);
    }

    return $learner;
}

function idKind(Learner $learner): ?ReminderKind
{
    return app(ReminderContentResolver::class)->resolve($learner)?->kind;
}

function idDue(Learner $learner, int $count): void
{
    foreach (Word::factory()->count($count)->create() as $word) {
        WordRepetition::factory()->due()->create(['learner_id' => $learner->id, 'word_id' => $word->id]);
    }
}

// --- rule 1: silence for those who left ---------------------------------------------------

it('stays silent after stop_after_days: 60 is the last reminder, 61 is silence', function (): void {
    expect(idKind(idLearner(60)))->toBe(ReminderKind::Inactive)
        ->and(idKind(idLearner(61)))->toBeNull()
        ->and(idKind(idLearner(200)))->toBeNull()
        ->and(idKind(idLearner(61, studied: false)))->toBeNull();
});

it('is silent for the left learner even with due words', function (): void {
    $learner = idLearner(90);
    idDue($learner, 5);

    expect(idKind($learner))->toBeNull();
});

// --- rule 2: sparse mode ------------------------------------------------------------------

it('reminds daily up to daily_until_days and then only on sparse_days', function (): void {
    expect(idKind(idLearner(7)))->toBe(ReminderKind::Inactive)
        ->and(idKind(idLearner(8)))->toBeNull()
        ->and(idKind(idLearner(9)))->toBeNull()
        ->and(idKind(idLearner(10)))->toBe(ReminderKind::Inactive)
        ->and(idKind(idLearner(11)))->toBeNull()
        ->and(idKind(idLearner(14)))->toBe(ReminderKind::Inactive)
        ->and(idKind(idLearner(15)))->toBeNull()
        ->and(idKind(idLearner(45)))->toBe(ReminderKind::Inactive)
        ->and(idKind(idLearner(46)))->toBeNull();
});

it('is silent in sparse mode even when words are due', function (): void {
    $learner = idLearner(9);
    idDue($learner, 3);

    expect(idKind($learner))->toBeNull();
});

it('reads the thresholds from config, with garbage guarded', function (): void {
    config([
        'learning.reminders.winback_from_days' => 0,
        'learning.reminders.daily_until_days' => 2,
        'learning.reminders.sparse_days' => [5, '6', 0, -3, 'x'],
        'learning.reminders.stop_after_days' => 6,
    ]);
    $resolver = app(ReminderContentResolver::class);

    expect($resolver->winbackFromDays())->toBe(1)
        ->and($resolver->sparseDays())->toBe([5, 6])
        ->and(idKind(idLearner(2)))->toBe(ReminderKind::Inactive)
        ->and(idKind(idLearner(4)))->toBeNull()
        ->and(idKind(idLearner(5)))->toBe(ReminderKind::Inactive)
        ->and(idKind(idLearner(7)))->toBeNull();
});

it('keeps the sparse_days default in config/learning.php', function (): void {
    expect(config('learning.reminders.winback_from_days'))->toBe(3)
        ->and(config('learning.reminders.daily_until_days'))->toBe(7)
        ->and(config('learning.reminders.sparse_days'))->toBe([10, 14, 21, 30, 45, 60])
        ->and(config('learning.reminders.stop_after_days'))->toBe(60);
});

// --- rule 3: the break message -----------------------------------------------------------

it('says "N days without lessons" from the 3rd idle day, with the number of days', function (): void {
    $two = idLearner(2);
    $three = idLearner(3);

    expect(idKind($two))->toBe(ReminderKind::NewWords)
        ->and(app(ReminderContentResolver::class)->resolve($three)?->kind)->toBe(ReminderKind::Inactive)
        ->and(app(ReminderContentResolver::class)->resolve($three)?->idleDays)->toBe(3);
});

it('adds the due count to the break message when words are due', function (): void {
    $learner = idLearner(3);
    idDue($learner, 4);

    $content = app(ReminderContentResolver::class)->resolve($learner);
    expect($content?->kind)->toBe(ReminderKind::Inactive)
        ->and($content?->dueCount)->toBe(4);

    expect(app(ReminderSender::class)->send($learner->id, CarbonImmutable::now()->subMinutes(5)))->toBe(ReminderOutcome::Sent)
        ->and($this->tg->sent[0]['text'])->toBe("Вы 3 дня не занимались. Слов к повторению: 4. Хватит 5 минут, чтобы вернуться.\n\nИзменить время или отключить: /settings")
        ->and(NotificationLog::query()->where('learner_id', $learner->id)->value('type'))->toBe('inactive_reminder');
});

it('writes the break message without the due sentence when nothing is due, in both languages', function (): void {
    $ru = idLearner(5);
    $uz = idLearner(21, attributes: ['interface_locale' => 'uz']);

    app(ReminderSender::class)->send($ru->id, CarbonImmutable::now()->subMinutes(5));
    app(ReminderSender::class)->send($uz->id, CarbonImmutable::now()->subMinutes(5));

    expect($this->tg->sent[0]['text'])->toBe("Вы 5 дней не занимались. Слова забываются без повторения — хватит 5 минут, чтобы вернуться.\n\nИзменить время или отключить: /settings")
        ->and($this->tg->sent[1]['text'])->toBe("LexiFlow: 21 kundan beri shug'ullanmadingiz. Bugun 5 daqiqa ajratib, avvalgi so'zlarni yana bir bor mashq qiling.\n\nEslatma vaqtini o'zgartirish yoki eslatmalarni o'chirish uchun /settings buyrug'ini yuboring.")
        ->and(NotificationLog::query()->where('learner_id', $ru->id)->first()?->payload)->toMatchArray(['kind' => 'inactive', 'idle_days' => 5]);
});

it('picks the Russian plural form by the number of days', function (): void {
    $texts = [];
    foreach ([3 => 'дня', 21 => 'день'] as $idle => $word) {
        // 21 is a sparse day, 3 is a daily one: both are sent
        $learner = idLearner($idle);
        app(ReminderSender::class)->send($learner->id, CarbonImmutable::now()->subMinutes(5));
        $texts[$idle] = $this->tg->sent[array_key_last($this->tg->sent)]['text'];
        expect($texts[$idle])->toStartWith("Вы {$idle} {$word} не занимались");
    }
});

// --- rule 3 / 6: never studied -----------------------------------------------------------

it('tells a newcomer who never studied to start: days 1 and 3 yes, 9 silent, 10 yes', function (): void {
    expect(idKind(idLearner(0, studied: false)))->toBe(ReminderKind::NeverStarted)
        ->and(idKind(idLearner(1, studied: false)))->toBe(ReminderKind::NeverStarted)
        ->and(idKind(idLearner(2, studied: false)))->toBe(ReminderKind::NeverStarted)
        ->and(idKind(idLearner(3, studied: false)))->toBe(ReminderKind::NeverStarted)
        ->and(idKind(idLearner(7, studied: false)))->toBe(ReminderKind::NeverStarted)
        ->and(idKind(idLearner(8, studied: false)))->toBeNull()
        ->and(idKind(idLearner(9, studied: false)))->toBeNull()
        ->and(idKind(idLearner(10, studied: false)))->toBe(ReminderKind::NeverStarted)
        ->and(idKind(idLearner(61, studied: false)))->toBeNull();
});

it('sends the "not started yet" text', function (): void {
    $learner = idLearner(1, studied: false);

    expect(app(ReminderSender::class)->send($learner->id, CarbonImmutable::now()->subMinutes(5)))->toBe(ReminderOutcome::Sent)
        ->and($this->tg->sent[0]['text'])->toBe("Вы ещё не начали заниматься в LexiFlow. Первое занятие займёт всего 5 минут.\n\nИзменить время или отключить: /settings")
        ->and(NotificationLog::query()->where('learner_id', $learner->id)->value('type'))->toBe('never_started_reminder');
});

it('counts a newcomer\'s idle days from the local day of registration', function (): void {
    // Registered 2026-10-17 21:00 UTC = 02:00 on the 18th in Tashkent: today is day 0, not day 1.
    $learner = idLearner(0, studied: false, attributes: ['created_at' => CarbonImmutable::parse('2026-10-17 21:00:00', 'UTC')]);

    expect(app(ReminderContentResolver::class)->resolve($learner)?->idleDays)->toBe(0);
});

it('does not count a day with zero reviews as studying', function (): void {
    $learner = idLearner(0, studied: false);
    LearnerDailyStats::factory()->create(['learner_id' => $learner->id, 'date' => '2026-10-17', 'reviews_count' => 0]);

    expect(idKind($learner))->toBe(ReminderKind::NeverStarted);
});

// --- rules 4, 5: as before ----------------------------------------------------------------

it('still prefers review, then the streak warning, below the break threshold', function (): void {
    $due = idLearner(1);
    idDue($due, 2);
    $risk = idLearner(1, attributes: ['streak_current' => 3, 'streak_best' => 3, 'streak_last_date' => '2026-10-17']);

    expect(idKind($due))->toBe(ReminderKind::Review)
        ->and(idKind($risk))->toBe(ReminderKind::StreakAtRisk);
});

// --- rule 6: nothing to repeat, not studied today -----------------------------------------

it('suggests new words to a learner who studied yesterday and has nothing to repeat', function (): void {
    $learner = idLearner(1);

    expect(app(ReminderSender::class)->send($learner->id, CarbonImmutable::now()->subMinutes(5)))->toBe(ReminderOutcome::Sent)
        ->and($this->tg->sent[0]['text'])->toBe("Сегодня вас ждут новые слова в LexiFlow. Хватит 5 минут.\n\nИзменить время или отключить: /settings")
        ->and(NotificationLog::query()->where('learner_id', $learner->id)->value('type'))->toBe('new_words_reminder');
});

it('asks a learner without an active course to choose one', function (): void {
    $newbie = idLearner(1, studied: false, course: false);
    $quitter = idLearner(1, course: false);
    $gone = idLearner(5, studied: false, course: false);

    expect(idKind($newbie))->toBe(ReminderKind::ChooseCourse)
        ->and(idKind($quitter))->toBe(ReminderKind::ChooseCourse)
        ->and(idKind($gone))->toBe(ReminderKind::ChooseCourse);

    app(ReminderSender::class)->send($newbie->id, CarbonImmutable::now()->subMinutes(5));
    expect($this->tg->sent[0]['text'])->toBe("Выберите курс в LexiFlow, чтобы начать заниматься.\n\nИзменить время или отключить: /settings");
});

it('keeps the break message for a former learner without a course', function (): void {
    expect(idKind(idLearner(4, course: false)))->toBe(ReminderKind::Inactive);
});

// --- rule 7: studied today -----------------------------------------------------------------

it('stays silent when the learner studied today and nothing is due', function (): void {
    expect(idKind(idLearner(0)))->toBeNull()
        ->and(idKind(idLearner(0, course: false)))->toBeNull();
});

it('still reminds about due words after studying today', function (): void {
    $learner = idLearner(0);
    idDue($learner, 2);

    expect(idKind($learner))->toBe(ReminderKind::Review);
});

// --- local days --------------------------------------------------------------------------

it('counts idle days in the learner\'s local calendar: 23:30 UTC on the 17th is already the 18th in Tashkent', function (): void {
    // Reviews on the local 15th. At 2026-10-17 23:30 UTC it is 04:30 on the 18th in Tashkent -> idle 3, not 2.
    $learner = idLearner(3);
    $this->travelTo(CarbonImmutable::parse('2026-10-17 23:30:00', 'UTC'));

    $content = app(ReminderContentResolver::class)->resolve($learner);
    expect($content?->kind)->toBe(ReminderKind::Inactive)
        ->and($content?->idleDays)->toBe(3);

    // A learner in UTC at the same instant is still on the 17th: idle 2 (the reviews were on the 15th).
    $utc = idLearner(3, attributes: ['timezone' => 'UTC']);
    expect(app(ReminderContentResolver::class)->resolve($utc)?->idleDays)->toBe(2)
        ->and(idKind($utc))->toBe(ReminderKind::NewWords);
});

it('treats reviews made late in the evening UTC as the next local day in Tashkent', function (): void {
    // Local 2026-10-18 02:00 (Tashkent) = 21:00 UTC on the 17th; the stats row is keyed by the local date.
    $learner = idLearner(0);
    $this->travelTo(CarbonImmutable::parse('2026-10-17 21:00:00', 'UTC'));

    expect(idKind($learner))->toBeNull();
});

// --- cost ---------------------------------------------------------------------------------

it('adds one query for a learner who did not study today and none for one who did', function (): void {
    $resolver = app(ReminderContentResolver::class);
    $away = idLearner(2);
    $today = idLearner(0);

    $count = function (Learner $learner) use ($resolver): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $resolver->resolve($learner);
        $queries = array_filter(DB::getQueryLog(), static fn (array $q): bool => str_contains((string) $q['query'], 'max("date")') || str_contains((string) $q['query'], 'max(`date`)'));
        DB::disableQueryLog();

        return count($queries);
    };

    expect($count($away))->toBe(1)
        ->and($count($today))->toBe(0);
});
