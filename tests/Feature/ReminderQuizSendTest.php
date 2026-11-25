<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Support\ExperimentBucket;
use App\Domain\Learners\Enums\ReminderOutcome;
use App\Domain\Learners\Services\ReminderQuizStore;
use App\Domain\Learners\Services\ReminderSender;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Models\Course;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\NotificationLog;
use App\Models\ProductEvent;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Tests\Support\FakeTelegramClient;
use Tests\Support\QueryLog;
use Tests\Support\ReminderQuizFixtures as Fx;

uses(RefreshDatabase::class);

/*
 * The reminder that is a question (docs/12 §7.5): what goes to Telegram, what is stored before it, and every way back to
 * the plain text. The tap is covered in ReminderQuizCallbackTest.
 */

beforeEach(function (): void {
    config(['twa.base_url' => 'https://twa.test']);
    // 2026-10-18 14:05 UTC = 19:05 in Tashkent, Sunday.
    $this->travelTo(CarbonImmutable::parse('2026-10-18 14:05:00', 'UTC'));

    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
});

/** @param array<string, mixed> $props jsonb does not keep the order of the keys */
function reminderQuizSorted(array $props): array
{
    ksort($props);

    return $props;
}

function reminderQuizSend(Learner $learner): ReminderOutcome
{
    return app(ReminderSender::class)->send($learner->id, CarbonImmutable::parse('2026-10-18 14:00:00', 'UTC'));
}

/** A learner with `$due` due words of a six-word lesson. @param array<string, mixed> $attributes */
function reminderQuizDueLearner(int $due = 3, array $attributes = []): Learner
{
    $learner = Fx::learner($attributes);
    foreach (array_slice(Fx::lesson(6), 0, $due) as $word) {
        Fx::due($learner, $word);
    }

    return $learner;
}

it('sends the reminder as a question with four answer buttons and the app button', function (): void {
    $learner = reminderQuizDueLearner(3);

    expect(reminderQuizSend($learner))->toBe(ReminderOutcome::Sent)
        ->and($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['chat_id'])->toBe(777001)
        ->and($this->tg->sent[0]['button'])->toBeNull();

    $state = app(ReminderQuizStore::class)->get($learner->id);
    $text = $this->tg->sent[0]['text'];
    $rows = $this->tg->sent[0]['markup']['inline_keyboard'];

    expect($text)->toStartWith("Пора повторить 3 слова. Начнём с одного прямо здесь.\n\nЧто значит «quizword")
        ->and($text)->toEndWith("\n\nИзменить время или отключить: /settings")
        ->and($rows)->toHaveCount(5)
        ->and($state)->not->toBeNull();

    foreach (array_slice($rows, 0, 4) as $i => $row) {
        $data = $row[0]['callback_data'];
        expect($data)->toBe("rq1:{$state['token']}:{$i}")
            ->and($data)->toMatch('~^rq1:[A-Za-z0-9]{10}:[0-3]$~')
            ->and(strlen($data))->toBeLessThanOrEqual(64)
            ->and($row[0]['text'])->toBe($state['options'][$i]);
    }

    // Nothing in a button gives the right option away: the data only differs by the index.
    expect(array_map(static fn (array $row): string => substr($row[0]['callback_data'], 0, -1), array_slice($rows, 0, 4)))->each->toBe("rq1:{$state['token']}:")
        ->and($rows[4])->toBe([['text' => 'Открыть Muhrla', 'web_app' => ['url' => 'https://twa.test/twa/?from=reminder']]]);
});

it('words the question in the learner language (uz)', function (): void {
    $learner = reminderQuizDueLearner(2, ['interface_locale' => 'uz']);

    reminderQuizSend($learner);

    $sent = $this->tg->sent[0];
    expect($sent['text'])->toStartWith("Muhrla'da 2 ta so'zni takrorlash vaqti keldi. Birinchisidan shu yerning o'zida boshlaymiz.\n\n«quizword")
        ->and($sent['text'])->toContain(" nima degani?\n\nEslatma vaqtini o'zgartirish yoki eslatmalarni o'chirish uchun /settings buyrug'ini yuboring.")
        ->and($sent['markup']['inline_keyboard'][0][0]['text'])->toStartWith('tarjimaquizword')
        ->and($sent['markup']['inline_keyboard'][4][0]['text'])->toBe("Muhrla'ni ochish");
});

it('asks reverse in the English words when the learner switched meaning off', function (): void {
    $learner = reminderQuizDueLearner(1, ['meta' => ['exercises' => ['meaning' => false]]]);

    reminderQuizSend($learner);

    expect($this->tg->sent[0]['text'])->toContain('Как по-английски «перевод')
        ->and($this->tg->sent[0]['markup']['inline_keyboard'][0][0]['text'])->toStartWith('quizword');
});

it('records the question, the log row and the two events of a sent question', function (): void {
    $learner = reminderQuizDueLearner(3);

    reminderQuizSend($learner);
    $state = app(ReminderQuizStore::class)->get($learner->id);

    $log = NotificationLog::query()->where('learner_id', $learner->id)->sole();
    expect($log->type)->toBe('review_reminder')
        ->and(reminderQuizSorted($log->payload['quiz']))->toBe(reminderQuizSorted(['word_id' => $state['word_id'], 'exercise' => 'meaning']));

    $sent = ProductEvent::query()->where('name', ProductEventName::ReminderSent->value)->sole();
    $quiz = ProductEvent::query()->where('name', ProductEventName::ReminderQuizSent->value)->sole();
    expect(reminderQuizSorted($sent->props))->toBe(reminderQuizSorted(['kind' => 'review', 'due_count' => 3, 'quiz' => true]))
        ->and(reminderQuizSorted($quiz->props))->toBe(reminderQuizSorted(['kind' => 'review', 'word_id' => $state['word_id'], 'exercise' => 'meaning', 'due_count' => 3]))
        ->and($quiz->learner_id)->toBe($learner->id);
});

it('stores the question BEFORE the Telegram call, so no tap can meet a missing state', function (): void {
    $learner = reminderQuizDueLearner(3);

    $seen = null;
    $telegram = Mockery::mock(TelegramClient::class);
    $telegram->shouldReceive('sendMessage')->once()->andReturnUsing(function () use ($learner, &$seen): void {
        $seen = ['state' => Cache::has(ReminderQuizStore::key($learner->id)), 'log' => NotificationLog::query()->where('learner_id', $learner->id)->count()];
    });
    $this->app->instance(TelegramClient::class, $telegram);

    expect(reminderQuizSend($learner))->toBe(ReminderOutcome::Sent)
        ->and($seen)->toBe(['state' => true, 'log' => 1]);
});

it('removes both the log row and the stored question on a 403', function (): void {
    $learner = reminderQuizDueLearner(3);

    $telegram = Mockery::mock(TelegramClient::class);
    $telegram->shouldReceive('sendMessage')->once()->andThrow(new TelegramForbiddenException(TelegramForbiddenException::REASON_BOT_BLOCKED));
    $this->app->instance(TelegramClient::class, $telegram);

    expect(reminderQuizSend($learner))->toBe(ReminderOutcome::Forbidden)
        ->and(NotificationLog::count())->toBe(0)
        ->and(Cache::has(ReminderQuizStore::key($learner->id)))->toBeFalse()
        ->and($learner->fresh()?->dm_blocked_at)->not->toBeNull()
        ->and(ProductEvent::query()->where('name', ProductEventName::ReminderQuizSent->value)->count())->toBe(0)
        ->and(ProductEvent::query()->where('name', ProductEventName::ReminderFailed->value)->count())->toBe(1);
});

it('keeps the question and the log row on an ambiguous failure (the message may have been delivered)', function (): void {
    $learner = reminderQuizDueLearner(3);
    $this->tg->failSends = 1;

    expect(fn () => reminderQuizSend($learner))->toThrow(TelegramApiException::class)
        ->and(Cache::has(ReminderQuizStore::key($learner->id)))->toBeTrue()
        ->and(NotificationLog::count())->toBe(1);
});

it('sends the old text and button, byte for byte, when the flag is off', function (): void {
    config(['learning.reminders.quiz.enabled' => false]);
    $learner = reminderQuizDueLearner(5);

    expect(reminderQuizSend($learner))->toBe(ReminderOutcome::Sent)
        ->and($this->tg->sent[0]['text'])->toBe("Пора повторить 5 слов в Muhrla.\n\nИзменить время или отключить: /settings")
        ->and($this->tg->sent[0]['button'])->toBe('Открыть Muhrla')
        ->and($this->tg->sent[0]['url'])->toBe('https://twa.test/twa/?from=reminder')
        ->and($this->tg->sent[0]['markup'])->toBeNull()
        ->and(Cache::has(ReminderQuizStore::key($learner->id)))->toBeFalse()
        ->and(ProductEvent::query()->where('name', ProductEventName::ReminderQuizSent->value)->count())->toBe(0)
        ->and(ProductEvent::query()->where('name', ProductEventName::ReminderSent->value)->sole()->props)->toEqual(['kind' => 'review', 'due_count' => 5]);
});

it('keeps the text for a learner who never started, one without a course, and a rare idle day', function (): void {
    // never started: a course, nothing studied
    $course = Course::factory()->published()->create();
    $never = Fx::learner(['telegram_user_id' => 777011, 'active_course_id' => $course->id]);
    // choose course: no course
    $chooser = Fx::learner(['telegram_user_id' => 777012, 'active_course_id' => null]);
    // idle 10 days (a sparse day) with a due word: the text, not the question
    $idle = reminderQuizDueLearner(2, ['telegram_user_id' => 777013]);
    LearnerDailyStats::factory()->create(['learner_id' => $idle->id, 'date' => '2026-10-08', 'reviews_count' => 5]);

    foreach ([$never, $chooser, $idle] as $learner) {
        expect(reminderQuizSend($learner))->toBe(ReminderOutcome::Sent);
    }

    expect($this->tg->sent)->toHaveCount(3)
        ->and(array_column($this->tg->sent, 'button'))->each->toBe('Открыть Muhrla')
        ->and($this->tg->sent[0]['text'])->toStartWith('Вы ещё не начали')
        ->and($this->tg->sent[1]['text'])->toStartWith('Выберите курс')
        ->and($this->tg->sent[2]['text'])->toStartWith('Вы 10 дней не занимались')
        ->and(Cache::has(ReminderQuizStore::key($never->id)) || Cache::has(ReminderQuizStore::key($chooser->id)) || Cache::has(ReminderQuizStore::key($idle->id)))->toBeFalse();
});

it('asks on the first days of a break and says "N days without lessons" in the lead', function (): void {
    $learner = reminderQuizDueLearner(2);
    LearnerDailyStats::factory()->create(['learner_id' => $learner->id, 'date' => '2026-10-15', 'reviews_count' => 5]); // 3 days ago

    reminderQuizSend($learner);

    expect($this->tg->sent[0]['text'])->toStartWith("Вы 3 дня не занимались. Проверим одно слово:\n\nЧто значит «")
        ->and($this->tg->sent[0]['markup']['inline_keyboard'])->toHaveCount(5);
});

it('falls back to the old text and reports when the choice of the question fails', function (): void {
    Exceptions::fake();
    $learner = reminderQuizDueLearner(3);
    Event::listen('eloquent.retrieved: '.WordRepetition::class, static function (): void {
        throw new RuntimeException('no question today');
    });

    expect(reminderQuizSend($learner))->toBe(ReminderOutcome::Sent)
        ->and($this->tg->sent[0]['button'])->toBe('Открыть Muhrla')
        ->and($this->tg->sent[0]['text'])->toStartWith('Пора повторить 3 слова в Muhrla.');

    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'no question today');
});

it('falls back to the old text, with no buttons that could only say "outdated", when Redis refuses the state', function (): void {
    Exceptions::fake();
    $learner = reminderQuizDueLearner(3);
    // A default store that works for everything but the question's key (the send lock must still be taken).
    Cache::extend('reminder-quiz-broken', fn () => Cache::repository(new class extends ArrayStore
    {
        public function put($key, $value, $seconds): bool
        {
            if (str_starts_with((string) $key, 'reminders:quiz:')) {
                throw new RuntimeException('redis is down');
            }

            return parent::put($key, $value, $seconds);
        }
    }));
    config(['cache.stores.reminder-quiz-broken' => ['driver' => 'reminder-quiz-broken'], 'cache.default' => 'reminder-quiz-broken']);
    Cache::forgetDriver();

    expect(reminderQuizSend($learner))->toBe(ReminderOutcome::Sent)
        ->and($this->tg->sent[0]['button'])->toBe('Открыть Muhrla')
        ->and($this->tg->sent[0]['markup'])->toBeNull()
        ->and(NotificationLog::query()->where('learner_id', $learner->id)->sole()->payload['quiz'])->toBeNull();

    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'redis is down');
});

it('is silent for a learner whose reminders are off, before any question is built', function (): void {
    $learner = reminderQuizDueLearner(3, ['reminder_time' => null]);

    expect(reminderQuizSend($learner))->toBe(ReminderOutcome::SkippedUnreachable)
        ->and($this->tg->sent)->toBe([])
        ->and(Cache::has(ReminderQuizStore::key($learner->id)))->toBeFalse();
});

it('replaces yesterday\'s question with today\'s under the same key', function (): void {
    $learner = reminderQuizDueLearner(3);
    reminderQuizSend($learner);
    $first = app(ReminderQuizStore::class)->get($learner->id)['token'] ?? '';

    $this->travelTo(CarbonImmutable::parse('2026-10-19 14:05:00', 'UTC'));
    app(ReminderSender::class)->send($learner->id, CarbonImmutable::parse('2026-10-19 14:00:00', 'UTC'));
    $second = app(ReminderQuizStore::class)->get($learner->id)['token'] ?? '';

    expect($this->tg->sent)->toHaveCount(2)
        ->and($first)->not->toBe('')
        ->and($second)->not->toBe('')
        ->and($second)->not->toBe($first);
});

it('costs a few queries more than the plain reminder on a warm cache', function (): void {
    $words = Fx::lesson(6);
    $make = function (int $telegramId) use ($words): Learner {
        $learner = Fx::learner(['telegram_user_id' => $telegramId]);
        Fx::due($learner, $words[0]);

        return $learner;
    };
    $warm = $make(777021);
    $quiz = $make(777022);
    $plain = $make(777023);

    reminderQuizSend($warm); // fills the translation and the distractor caches of the lesson

    // Measured: 12 vs 9 (candidates, their words, the extra event insert); the translations and the distractors come from the cache.
    $quizQueries = QueryLog::during(fn () => reminderQuizSend($quiz));
    config(['learning.reminders.quiz.enabled' => false]);
    $plainQueries = QueryLog::during(fn () => reminderQuizSend($plain));

    expect($this->tg->sent)->toHaveCount(3)
        ->and($this->tg->sent[1]['markup'])->not->toBeNull()
        ->and($this->tg->sent[2]['markup'])->toBeNull()
        ->and(count($quizQueries))->toBeLessThanOrEqual(count($plainQueries) + 3)
        ->and(count($quizQueries))->toBeLessThanOrEqual(12);
});

// --- texts ----------------------------------------------------------------------------------------

it('has every quiz text in ru and uz, and the three plural forms in ru', function (): void {
    $ru = require lang_path('ru/reminders.php');
    $uz = require lang_path('uz/reminders.php');

    $flat = static function (array $lines, string $prefix = '') use (&$flat): array {
        $out = [];
        foreach ($lines as $key => $value) {
            is_array($value) ? $out += $flat($value, "{$prefix}{$key}.") : $out["{$prefix}{$key}"] = $value;
        }

        return $out;
    };
    $ruQuiz = $flat($ru['quiz']);
    $uzQuiz = $flat($uz['quiz']);

    expect(array_keys($uzQuiz))->toEqualCanonicalizing(array_keys($ruQuiz))
        ->and(array_keys($ruQuiz))->toEqualCanonicalizing([
            'lead.review', 'lead.streak_at_risk', 'lead.inactive', 'lead.new_words', 'question.meaning', 'question.reverse',
            'footer', 'correct', 'wrong', 'after_due', 'after_none', 'open_more', 'expired',
        ]);

    foreach (['lead.review', 'lead.streak_at_risk', 'lead.inactive', 'after_due'] as $key) {
        expect(explode('|', $ruQuiz[$key]))->toHaveCount(3);
    }
    foreach ($uzQuiz as $key => $text) {
        expect($text)->not->toContain('|')->and(trim($text))->not->toBe('');
    }
    expect(mb_strlen($ruQuiz['expired']))->toBeLessThanOrEqual(200)->and(mb_strlen($uzQuiz['expired']))->toBeLessThanOrEqual(200);

    // The same placeholders in both languages.
    foreach ($ruQuiz as $key => $text) {
        preg_match_all('/:[a-z]+/', $text, $a);
        preg_match_all('/:[a-z]+/', $uzQuiz[$key], $b);
        expect(array_unique($b[0]))->toEqualCanonicalizing(array_unique($a[0]), $key);
    }
});

// --- the question vs the plain text experiment (audit 2026-11-18) -----------------------------------

/** @param array<string, mixed> $overrides */
function reminderExperimentOn(array $overrides = []): void
{
    config(['learning.reminders.quiz.experiment' => [
        'enabled' => true,
        'share' => 50,
        'salt' => 'reminder-quiz-test',
        'starts_at' => null,
        ...$overrides,
    ]]);
}

/** How many times the question builder read the learner's cards (the plain arm must never get there). */
function reminderQuizBuilderReads(): Closure
{
    $reads = 0;
    Event::listen('eloquent.retrieved: '.WordRepetition::class, static function () use (&$reads): void {
        $reads++;
    });

    return static function () use (&$reads): int {
        return $reads;
    };
}

it('splits quiz-eligible learners by the sticky bucket of their id', function (): void {
    reminderExperimentOn();

    $learners = [];
    foreach (range(1, 12) as $i) {
        $learners[] = reminderQuizDueLearner(3, ['telegram_user_id' => 778000 + $i]);
    }
    foreach ($learners as $learner) {
        expect(reminderQuizSend($learner))->toBe(ReminderOutcome::Sent);
    }

    $arms = [];
    foreach ($learners as $learner) {
        $expected = ExperimentBucket::in('reminder-quiz-test', $learner->id, 50) ? 'quiz' : 'plain';
        $sent = ProductEvent::query()->where('name', ProductEventName::ReminderSent->value)->where('learner_id', $learner->id)->sole();
        $log = NotificationLog::query()->where('learner_id', $learner->id)->sole();

        expect($sent->props['variant'])->toBe($expected)
            ->and($sent->props['experiment'])->toBe('reminder-quiz-test')
            ->and($log->payload['variant'])->toBe($expected)
            ->and($log->payload['experiment'])->toBe('reminder-quiz-test')
            ->and($log->payload['quiz'] !== null)->toBe($expected === 'quiz');
        $arms[$expected] = ($arms[$expected] ?? 0) + 1;
    }

    // Twelve ids are enough for both arms under this salt (a fixed, deterministic split).
    expect($arms['quiz'] ?? 0)->toBeGreaterThan(0)->and($arms['plain'] ?? 0)->toBeGreaterThan(0)
        ->and(ProductEvent::query()->where('name', ProductEventName::ReminderQuizSent->value)->count())->toBe($arms['quiz']);
});

it('never builds a question in the plain arm and sends the old text', function (): void {
    reminderExperimentOn(['share' => 0]);
    $learner = reminderQuizDueLearner(5);
    $reads = reminderQuizBuilderReads();

    expect(reminderQuizSend($learner))->toBe(ReminderOutcome::Sent)
        ->and($reads())->toBe(0)
        ->and($this->tg->sent[0]['text'])->toBe("Пора повторить 5 слов в Muhrla.\n\nИзменить время или отключить: /settings")
        ->and($this->tg->sent[0]['button'])->toBe('Открыть Muhrla')
        ->and(Cache::has(ReminderQuizStore::key($learner->id)))->toBeFalse()
        ->and(ProductEvent::query()->where('name', ProductEventName::ReminderQuizSent->value)->count())->toBe(0)
        ->and(reminderQuizSorted(ProductEvent::query()->where('name', ProductEventName::ReminderSent->value)->sole()->props))
        ->toBe(reminderQuizSorted(['kind' => 'review', 'due_count' => 5, 'variant' => 'plain', 'experiment' => 'reminder-quiz-test']))
        ->and(NotificationLog::query()->sole()->payload)->toMatchArray(['variant' => 'plain', 'experiment' => 'reminder-quiz-test']);
});

it('marks a built question of the quiz arm', function (): void {
    reminderExperimentOn(['share' => 100]);
    $learner = reminderQuizDueLearner(3);

    reminderQuizSend($learner);

    expect($this->tg->sent[0]['markup'])->not->toBeNull()
        ->and(reminderQuizSorted(ProductEvent::query()->where('name', ProductEventName::ReminderSent->value)->sole()->props))
        ->toBe(reminderQuizSorted(['kind' => 'review', 'due_count' => 3, 'quiz' => true, 'variant' => 'quiz', 'experiment' => 'reminder-quiz-test', 'quiz_built' => true]))
        ->and(NotificationLog::query()->sole()->payload)->toMatchArray(['variant' => 'quiz', 'experiment' => 'reminder-quiz-test']);
});

it('keeps a learner whose question could not be built in the quiz arm (intent to treat)', function (): void {
    Exceptions::fake();
    reminderExperimentOn(['share' => 100]);
    $learner = reminderQuizDueLearner(3);
    Event::listen('eloquent.retrieved: '.WordRepetition::class, static function (): void {
        throw new RuntimeException('no question today');
    });

    expect(reminderQuizSend($learner))->toBe(ReminderOutcome::Sent)
        ->and($this->tg->sent[0]['button'])->toBe('Открыть Muhrla')
        ->and(reminderQuizSorted(ProductEvent::query()->where('name', ProductEventName::ReminderSent->value)->sole()->props))
        ->toBe(reminderQuizSorted(['kind' => 'review', 'due_count' => 3, 'variant' => 'quiz', 'experiment' => 'reminder-quiz-test', 'quiz_built' => false]))
        ->and(NotificationLog::query()->sole()->payload)->toMatchArray(['quiz' => null, 'variant' => 'quiz', 'experiment' => 'reminder-quiz-test']);
});

it('keeps the arm of a learner from one send to the next', function (): void {
    reminderExperimentOn();
    $learner = reminderQuizDueLearner(3);

    reminderQuizSend($learner);
    $this->travelTo(CarbonImmutable::parse('2026-10-19 14:05:00', 'UTC'));
    app(ReminderSender::class)->send($learner->id, CarbonImmutable::parse('2026-10-19 14:00:00', 'UTC'));

    $variants = ProductEvent::query()->where('name', ProductEventName::ReminderSent->value)->orderBy('id')->get()->map(fn (ProductEvent $e) => $e->props['variant']);
    expect($variants)->toHaveCount(2)
        ->and($variants[0])->toBe($variants[1])
        ->and($variants[0])->toBe(ExperimentBucket::in('reminder-quiz-test', $learner->id, 50) ? 'quiz' : 'plain');
});

it('changes nothing while the experiment is off, not started, or the reminder cannot be a question', function (): void {
    // off (the default)
    $off = reminderQuizDueLearner(3);
    reminderQuizSend($off);
    // starts tomorrow
    reminderExperimentOn(['share' => 0, 'starts_at' => '2026-10-19']);
    $early = reminderQuizDueLearner(3, ['telegram_user_id' => 777031]);
    reminderQuizSend($early);
    // running, but a learner who never started gets the text whatever the arm
    reminderExperimentOn(['share' => 0]);
    $never = Fx::learner(['telegram_user_id' => 777032, 'active_course_id' => Course::factory()->published()->create()->id]);
    reminderQuizSend($never);

    $props = ProductEvent::query()->where('name', ProductEventName::ReminderSent->value)->orderBy('id')->pluck('props')->all();
    expect(config('learning.reminders.quiz.experiment.enabled'))->toBeTrue()
        ->and(reminderQuizSorted($props[0]))->toBe(reminderQuizSorted(['kind' => 'review', 'due_count' => 3, 'quiz' => true]))
        ->and(reminderQuizSorted($props[1]))->toBe(reminderQuizSorted(['kind' => 'review', 'due_count' => 3, 'quiz' => true]))
        ->and(array_keys($props[2]))->not->toContain('variant')
        ->and(array_keys($props[2]))->not->toContain('experiment')
        ->and(NotificationLog::query()->get()->filter(fn (NotificationLog $log): bool => array_key_exists('variant', $log->payload) || array_key_exists('experiment', $log->payload)))->toHaveCount(0)
        ->and($this->tg->sent[0]['markup'])->not->toBeNull()
        ->and($this->tg->sent[1]['markup'])->not->toBeNull();
});

it('reads an unreadable starts_at as "not started"', function (): void {
    reminderExperimentOn(['share' => 0, 'starts_at' => 'next tuesday-ish']);
    $learner = reminderQuizDueLearner(3);

    reminderQuizSend($learner);

    expect(ProductEvent::query()->where('name', ProductEventName::ReminderSent->value)->sole()->props)->not->toHaveKey('variant')
        ->and($this->tg->sent[0]['markup'])->not->toBeNull();
});

it('puts the arm on the failure of a blocked send', function (): void {
    reminderExperimentOn(['share' => 0]);
    $learner = reminderQuizDueLearner(3);
    $telegram = Mockery::mock(TelegramClient::class);
    $telegram->shouldReceive('sendWebAppButton')->once()->andThrow(new TelegramForbiddenException(TelegramForbiddenException::REASON_BOT_BLOCKED));
    $this->app->instance(TelegramClient::class, $telegram);

    expect(reminderQuizSend($learner))->toBe(ReminderOutcome::Forbidden)
        ->and(ProductEvent::query()->where('name', ProductEventName::ReminderFailed->value)->sole()->props)->toMatchArray(['variant' => 'plain', 'experiment' => 'reminder-quiz-test'])
        ->and(ProductEvent::query()->where('name', ProductEventName::ReminderSent->value)->count())->toBe(0);
});
