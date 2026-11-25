<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Learners\DTO\ReminderContent;
use App\Domain\Learners\DTO\ReminderQuiz;
use App\Domain\Learners\Enums\ReminderKind;
use App\Domain\Learners\Enums\ReminderOutcome;
use App\Domain\Learners\Services\LessonNudgeSender;
use App\Domain\Learners\Services\ReminderDispatcher;
use App\Domain\Learners\Services\ReminderSender;
use App\Domain\Learners\Services\ReviewScoreHint;
use App\Domain\Learning\Enums\ExerciseType;
use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\ReminderQuizMessenger;
use App\Jobs\SendLearnerReminderJob;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\NotificationLog;
use App\Models\ProductEvent;
use App\Models\Word;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeTelegramClient;

/*
 * The reminder of the day after a lesson (docs/31 §5б, deferred part of G): "the words of yesterday's lesson came up for review - a
 * review in time earns points". Not a second message: the wording of the personal reminder of that day, only while the word points
 * count (`live`, the day on v2), only when the words of yesterday's lesson are due and the learner has not studied today. The
 * dispatcher finds those learners with ONE statement per chunk.
 */

uses(RefreshDatabase::class);

const SH_TEXT_RU = "Слова вчерашнего урока пришли на повторение. Повторите их сегодня: за повторение в срок начисляются баллы рейтинга.\n\nИзменить время или отключить: /settings";

beforeEach(function (): void {
    config([
        'twa.base_url' => 'https://twa.test',
        'learning.reminders.quiz.enabled' => false,
        // The word points count: `live`, v2 from Monday 2026-09-28.
        'leagues.scoring.mode' => 'live',
        'leagues.scoring.versions.2.starts_on' => '2026-09-28',
    ]);
    // 2026-10-18 14:05 UTC = 19:05 in Tashkent, Sunday; the local yesterday is 2026-10-16 19:00 - 2026-10-17 19:00 UTC.
    $this->travelTo(CarbonImmutable::parse('2026-10-18 14:05:00', 'UTC'));

    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
});

/** @param array<string, mixed> $attributes */
function shLearner(array $attributes = []): Learner
{
    return Learner::factory()->create([
        'interface_locale' => 'ru',
        'timezone' => 'Asia/Tashkent',
        'reminder_time' => '19:00',
        'next_reminder_at' => CarbonImmutable::parse('2026-10-18 14:00:00', 'UTC'),
        ...$attributes,
    ]);
}

/**
 * A word first met (and answered) at `$metAt` in a session of `$mode`, due at `$dueAt`; the day it was met counts as studied.
 */
function shWord(Learner $learner, string $metAt = '2026-10-17 10:00:00', string $dueAt = '2026-10-18 10:00:00', string $mode = 'lesson'): void
{
    WordRepetition::factory()->create([
        'learner_id' => $learner->id,
        'word_id' => Word::factory()->create()->id,
        'state' => RepetitionState::Review->value,
        'repetitions' => 1,
        'interval_days' => 1,
        'last_quality' => 4,
        'last_reviewed_at' => CarbonImmutable::parse($metAt, 'UTC'),
        'next_review_at' => CarbonImmutable::parse($dueAt, 'UTC'),
        'introduced_mode' => $mode,
        'created_at' => CarbonImmutable::parse($metAt, 'UTC'),
    ]);
    LearnerDailyStats::query()->firstOrCreate(
        ['learner_id' => $learner->id, 'date' => CarbonImmutable::parse($metAt, 'UTC')->setTimezone('Asia/Tashkent')->toDateString()],
        ['reviews_count' => 5],
    );
}

/** @return array<int, bool> learner id => the `scoreHint` of their queued job */
function shDispatch(): array
{
    Bus::fake();
    app(ReminderDispatcher::class)->dispatchDue(CarbonImmutable::parse('2026-10-18 14:05:00', 'UTC'));

    $hints = [];
    Bus::assertDispatched(SendLearnerReminderJob::class, function (SendLearnerReminderJob $job) use (&$hints): bool {
        $hints[$job->learnerId] = $job->scoreHint;

        return true;
    });

    return $hints;
}

function shSend(Learner $learner, bool $hint = true): ReminderOutcome
{
    return app(ReminderSender::class)->send($learner->id, CarbonImmutable::parse('2026-10-18 14:00:00', 'UTC'), scoreHint: $hint);
}

// --- the dispatcher: who gets the wording ------------------------------------------------------------------------------------

it('marks the job of a learner whose words of yesterday\'s lesson are due', function (): void {
    $hinted = shLearner();
    shWord($hinted);
    $plain = shLearner();

    expect(shDispatch())->toBe([$hinted->id => true, $plain->id => false]);
});

it('does not mark it when the words are not yesterday\'s, not due yet, not from a lesson or never answered', function (): void {
    $older = shLearner();
    shWord($older, metAt: '2026-10-16 10:00:00');            // the day before yesterday
    $today = shLearner();
    shWord($today, metAt: '2026-10-18 10:00:00');            // today (local 15:00)
    $notDue = shLearner();
    shWord($notDue, dueAt: '2026-10-18 18:00:00');           // due tonight, not yet
    $daily = shLearner();
    shWord($daily, mode: 'daily_review');                    // met in the daily review, not in a lesson
    $unanswered = shLearner();
    shWord($unanswered);
    WordRepetition::query()->where('learner_id', $unanswered->id)->update(['last_reviewed_at' => null]);

    expect(array_filter(shDispatch()))->toBe([]);
});

it('does not mark a learner opted out of the leagues: no rating points for them, and no statement more', function (): void {
    $hinted = shLearner();
    shWord($hinted);
    $optedOut = shLearner(['meta' => ['leagues_opt_out' => true]]);
    shWord($optedOut);
    $optedBack = shLearner(['meta' => ['leagues_opt_out' => false]]);
    shWord($optedBack);

    expect(shDispatch())->toBe([$hinted->id => true, $optedOut->id => false, $optedBack->id => true]);
});

it('does not even look for the words of a banned learner (no rating points)', function (): void {
    $banned = shLearner(['blocked_at' => now()]);
    shWord($banned);

    expect(app(ReviewScoreHint::class)->learnerIds([$banned->fresh()], CarbonImmutable::parse('2026-10-18 14:05:00', 'UTC')))->toBe([]);
});

it('takes the local yesterday of each learner: one statement for learners of different time zones', function (): void {
    // New York (UTC-4 on 2026-10-18): the local yesterday is 2026-10-17 04:00 - 2026-10-18 04:00 UTC.
    $newYork = shLearner(['timezone' => 'America/New_York', 'next_reminder_at' => CarbonImmutable::parse('2026-10-18 14:00:00', 'UTC')]);
    shWord($newYork, metAt: '2026-10-18 02:00:00');           // 22:00 of 2026-10-17 in New York, 07:00 of 2026-10-18 in Tashkent
    $tashkent = shLearner();
    shWord($tashkent, metAt: '2026-10-18 02:00:00');          // today in Tashkent: not yesterday's
    $both = shLearner();
    shWord($both);

    DB::enableQueryLog();
    $hints = shDispatch();
    $statements = collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], 'word_repetitions'))->count();

    expect($hints)->toBe([$newYork->id => true, $tashkent->id => false, $both->id => true])
        ->and($statements)->toBe(1);
});

it('marks nobody and reads nothing while the word points do not count', function (string $mode, ?string $startsOn): void {
    config(['leagues.scoring.mode' => $mode, 'leagues.scoring.versions.2.starts_on' => $startsOn]);
    $learner = shLearner();
    shWord($learner);

    DB::enableQueryLog();
    $hints = shDispatch();

    expect($hints)->toBe([$learner->id => false])
        ->and(collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], 'word_repetitions'))->count())->toBe(0);
})->with([
    'off' => ['off', '2026-09-28'],
    'shadow' => ['shadow', '2026-09-28'],
    'live before v2' => ['live', '2026-10-19'],
    'live without a start date' => ['live', null],
]);

it('keeps the statements of the mass sending flat: one more per chunk, whatever the number of learners', function (): void {
    $count = function (int $learners, string $mode): int {
        DB::table('word_repetitions')->delete();
        DB::table('learner_daily_stats')->delete();
        DB::table('learners')->delete();
        config(['leagues.scoring.mode' => $mode]);
        for ($i = 0; $i < $learners; $i++) {
            shWord(shLearner());
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        Bus::fake();
        app(ReminderDispatcher::class)->dispatchDue(CarbonImmutable::parse('2026-10-18 14:05:00', 'UTC'));
        $all = DB::getQueryLog();
        DB::disableQueryLog();

        return count($all);
    };

    $offFew = $count(3, 'off');
    $liveFew = $count(3, 'live');
    $offMany = $count(30, 'off');
    $liveMany = $count(30, 'live');

    // Before: the chunk read + one claim per learner (+ the backfill read and the end of the chunks); after: + 1 per chunk.
    expect($liveFew - $offFew)->toBe(1)
        ->and($liveMany - $offMany)->toBe(1);
});

// --- the sender: the wording replaces the text, never adds a message ----------------------------------------------------------

it('words the reminder as "yesterday\'s words earn points" with the review button when every condition holds', function (): void {
    $learner = shLearner();
    shWord($learner);

    expect(shSend($learner))->toBe(ReminderOutcome::Sent)
        ->and($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['text'])->toBe(SH_TEXT_RU)
        ->and($this->tg->sent[0]['button'])->toBe('🔁 Повторить слова')
        ->and($this->tg->sent[0]['url'])->toBe('https://twa.test/twa/?from=reminder');

    $log = NotificationLog::query()->where('learner_id', $learner->id)->sole();
    expect($log->type)->toBe('review_reminder')
        ->and($log->payload['kind'])->toBe('review')
        ->and($log->payload['score_hint'])->toBeTrue();

    $event = ProductEvent::query()->where('name', ProductEventName::ReminderSent->value)->sole();
    expect(ksorted($event->props))->toBe(ksorted(['kind' => 'review', 'due_count' => 1, 'score_hint' => true]));
});

it('speaks Uzbek to an uz learner', function (): void {
    $learner = shLearner(['interface_locale' => 'uz']);
    shWord($learner);

    shSend($learner);

    expect($this->tg->sent[0]['text'])->toBe("Kechagi dars so'zlarini takrorlash vaqti keldi. Ularni bugun takrorlang: o'z vaqtida takrorlash uchun reyting ballari beriladi.\n\nEslatma vaqtini o'zgartirish yoki eslatmalarni o'chirish uchun /settings buyrug'ini yuboring.")
        ->and($this->tg->sent[0]['button'])->toBe("🔁 So'zlarni takrorlash");
});

it('sends the usual reminder when the word points stopped counting since the dispatch', function (): void {
    config(['leagues.scoring.mode' => 'off']);
    $learner = shLearner();
    shWord($learner);

    shSend($learner);

    expect($this->tg->sent[0]['text'])->toBe("Пора повторить 1 слово в Muhrla.\n\nИзменить время или отключить: /settings")
        ->and($this->tg->sent[0]['button'])->toBe('▶️ Начать занятие')
        ->and(NotificationLog::query()->sole()->payload)->not->toHaveKey('score_hint');
});

it('sends the usual reminder without the mark of the dispatcher', function (): void {
    $learner = shLearner();
    shWord($learner);

    shSend($learner, hint: false);

    expect($this->tg->sent[0]['text'])->toBe("Пора повторить 1 слово в Muhrla.\n\nИзменить время или отключить: /settings");
});

it('sends the usual reminder to a learner who already studied today', function (): void {
    $learner = shLearner();
    shWord($learner);
    LearnerDailyStats::factory()->create(['learner_id' => $learner->id, 'date' => '2026-10-18', 'reviews_count' => 3]);

    shSend($learner);

    expect($this->tg->sent[0]['text'])->toBe("Пора повторить 1 слово в Muhrla.\n\nИзменить время или отключить: /settings");
});

it('sends nothing at all when a message about studies already went out today: no second message', function (): void {
    $learner = shLearner();
    shWord($learner);
    NotificationLog::query()->create(['learner_id' => $learner->id, 'type' => LessonNudgeSender::NOTIFICATION_TYPE, 'payload' => [], 'sent_at' => now()->subHours(3)]);

    expect(shSend($learner))->toBe(ReminderOutcome::SkippedRecent)
        ->and($this->tg->sent)->toBe([]);
});

it('runs the whole way from the dispatcher to the message', function (): void {
    $learner = shLearner();
    shWord($learner);

    Bus::fake();
    app(ReminderDispatcher::class)->dispatchDue(CarbonImmutable::parse('2026-10-18 14:05:00', 'UTC'));
    $job = null;
    Bus::assertDispatched(SendLearnerReminderJob::class, function (SendLearnerReminderJob $queued) use (&$job): bool {
        $job = $queued;

        return true;
    });
    // Through the queue's serialization, as a worker gets it.
    $job = unserialize(serialize($job));
    $job->handle(app(ReminderSender::class));

    expect($this->tg->sent[0]['text'])->toBe(SH_TEXT_RU);
});

it('reads a job queued before the mark existed as one without it', function (): void {
    // unserialize() builds the object without the constructor, as here: the default of the property is what an old payload gets.
    $old = (new ReflectionClass(SendLearnerReminderJob::class))->newInstanceWithoutConstructor();

    expect($old->scoreHint)->toBeFalse();
});

it('puts the same wording into the lead of a reminder question', function (): void {
    $quiz = new ReminderQuiz(56, 'tree', ExerciseType::Meaning, ['дом', 'кот', 'лес', 'мир'], 1, 'дерево', 'NewToken12');
    $messenger = app(ReminderQuizMessenger::class);

    expect($messenger->question((new ReminderContent(ReminderKind::Review, 2, 0, 1))->withScoreHint(), $quiz, 'ru')['text'])
        ->toStartWith('Слова вчерашнего урока пришли на повторение — за повторение в срок начисляются баллы. Начнём с одного прямо здесь.')
        ->and($messenger->question(new ReminderContent(ReminderKind::Review, 2, 0, 1), $quiz, 'ru')['text'])
        ->toStartWith('Пора повторить 2 слова.')
        ->and($messenger->question((new ReminderContent(ReminderKind::Review, 2, 0, 1))->withScoreHint(), $quiz, 'uz')['text'])
        ->toStartWith("Kechagi dars so'zlarini takrorlash vaqti keldi");
});

it('applies only to a reminder about due words to a learner who has not studied today', function (): void {
    $hint = app(ReviewScoreHint::class);
    $now = CarbonImmutable::parse('2026-10-18 14:05:00', 'UTC');

    expect($hint->applies(true, new ReminderContent(ReminderKind::Review, 3, 0, 1), $now))->toBeTrue()
        ->and($hint->applies(false, new ReminderContent(ReminderKind::Review, 3, 0, 1), $now))->toBeFalse()
        ->and($hint->applies(true, new ReminderContent(ReminderKind::Review, 3, 0, 0), $now))->toBeFalse()
        ->and($hint->applies(true, new ReminderContent(ReminderKind::StreakAtRisk, 0, 4, 1), $now))->toBeFalse()
        ->and($hint->applies(true, new ReminderContent(ReminderKind::NewWords, 0, 0, 1), $now))->toBeFalse();
});
