<?php

declare(strict_types=1);

use App\Domain\Engagement\Enums\ScoreCategory;
use App\Domain\Engagement\Events\LeagueMonthConfirmed;
use App\Domain\Engagement\Services\AchievementCardRenderer;
use App\Domain\Engagement\Services\MonthWinnerCardBuilder;
use App\Domain\Engagement\Services\MonthWinnerSender;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\DTO\PhotoSource;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Exceptions\TelegramOutboundBusyException;
use App\Domain\Telegram\Exceptions\TelegramRateLimitedException;
use App\Domain\Telegram\Services\TelegramMessageRateLimiter;
use App\Jobs\Middleware\ThrottleTelegramOutbound;
use App\Listeners\Engagement\SendMonthWinnerMessages;
use App\Models\LeagueMonth;
use App\Models\Learner;
use App\Models\NotificationLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeTelegramClient;

/*
 * The DM to the winners of a confirmed month (docs/13 §7.6, docs/36 §2б A5): a photo - the picture "N place in the rating for
 * <month>" without the name - with the congratulation as its caption ("you took place N of the rating of the month - P points. Your
 * prize: X. We will contact you"), in the winner's language with the prize of their place (of their table in a v2 month); without the
 * prize sentences when the month has none; the plain text when there is no picture; once per (winner, month) however often the event
 * is raised; nothing for a blocked learner or a closed DM.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
    config(['telegram.bot_username' => 'MuhrlaBot']);
    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
    config(['leagues.monthly.prizes' => [
        1 => ['ru' => 'Telegram Premium на 3 месяца', 'uz' => 'Telegram Premium 3 oyga'],
        2 => ['ru' => 'Telegram Premium на 1 месяц', 'uz' => 'Telegram Premium 1 oyga'],
    ]]);
});

function mwMonth(array $attributes = []): LeagueMonth
{
    return LeagueMonth::factory()->startingOn('2026-10-01')->confirmed()->create($attributes);
}

function mwWinner(array $attributes = []): Learner
{
    return Learner::factory()->create(['interface_locale' => 'ru', ...$attributes]);
}

/** @param list<array{learner_id: int, place: int, points: int, category?: string}> $winners */
function mwRaise(LeagueMonth $month, array $winners): void
{
    event(new LeagueMonthConfirmed($month->id, $month->starts_on->toDateString(), $winners));
}

it('sends every winner a photo of their place with the congratulation and the prize as its caption', function (): void {
    $month = mwMonth();
    $first = mwWinner();
    $second = mwWinner();

    mwRaise($month, [
        ['learner_id' => $first->id, 'place' => 1, 'points' => 1204],
        ['learner_id' => $second->id, 'place' => 2, 'points' => 1],
    ]);

    $photos = $this->tg->photoMessages;
    expect($this->tg->sent)->toBe([])
        ->and($photos)->toHaveCount(2)
        ->and($photos[0]['chat_id'])->toBe($first->telegram_user_id)
        // Uploaded as a file, not by its URL: no public https address needed.
        ->and($photos[0]['kind'])->toBe(PhotoSource::KIND_FILE)
        ->and($photos[0]['photo'])->toEndWith('.jpg')
        ->and($photos[0]['caption'])->toBe('🏆 Поздравляем! Вы заняли 1-е место в рейтинге за октябрь 2026 — 1204 балла. Ваш приз: Telegram Premium на 3 месяца. Мы свяжемся с вами в Telegram, чтобы вручить его.')
        ->and($photos[1]['caption'])->toContain('2-е место')->toContain('1 балл.')->toContain('Telegram Premium на 1 месяц')
        ->and($photos[0]['photo'])->not->toBe($photos[1]['photo'])
        ->and(NotificationLog::query()->where('type', 'league_month_won')->count())->toBe(2)
        ->and(NotificationLog::query()->where('learner_id', $first->id)->value('payload'))->toMatchArray(['key' => 'month:'.$month->id, 'place' => 1, 'photo' => true]);

    // The file is a JPEG of the cards directory, 1080 × 1080.
    $size = getimagesize($photos[0]['photo']);
    expect($size)->not->toBeFalse()
        ->and($size[0])->toBe(AchievementCardRenderer::SIZE)
        ->and($size['mime'])->toBe('image/jpeg')
        ->and(dirname($photos[0]['photo']))->toBe(rtrim(Storage::disk('public')->path('share-cards'), '/'));
});

it('draws the place and the month on the picture, never the name of the winner', function (string $locale, string $title, string $points): void {
    $month = mwMonth();
    $spec = app(MonthWinnerCardBuilder::class)->spec($month, 1, 900, null, $locale);

    expect($spec->title)->toBe($title)
        ->and($spec->description)->toBe($points)
        ->and($spec->palette)->toBe('month')
        ->and($spec->badge)->toBe('1')
        ->and($spec->key)->toBe('month_place_1');
})->with([
    'ru' => ['ru', '1 место', 'в рейтинге за октябрь 2026 — 900 баллов'],
    'uz' => ['uz', "1-o'rin", '2026-yil oktyabr reytingida — 900 ball'],
]);

it('speaks Uzbek to an uz winner: caption and picture', function (): void {
    $month = mwMonth();
    $ru = mwWinner(['first_name' => 'Анвар']);
    $uz = mwWinner(['interface_locale' => 'uz', 'first_name' => 'Anvar']);

    mwRaise($month, [['learner_id' => $uz->id, 'place' => 1, 'points' => 900], ['learner_id' => $ru->id, 'place' => 1, 'points' => 900]]);

    expect($this->tg->photoMessages[0]['caption'])->toBe("🏆 Tabriklaymiz! Siz 2026-yil oktyabr reytingida 1-o'rinni egalladingiz — 900 ball. Sovg'angiz: Telegram Premium 3 oyga. Uni topshirish uchun Telegram orqali siz bilan bog'lanamiz.")
        // One picture per language: the same place, month and points in Russian is another file.
        ->and($this->tg->photoMessages[0]['photo'])->not->toBe($this->tg->photoMessages[1]['photo'])
        ->and($this->tg->photoMessages[0]['photo'])->toContain('-uz-')
        ->and($this->tg->photoMessages[1]['photo'])->toContain('-ru-');
});

it('gives every winner who shares a prize place their own message with the same prize and the same picture', function (): void {
    $month = mwMonth();
    $a = mwWinner();
    $b = mwWinner();

    mwRaise($month, [['learner_id' => $a->id, 'place' => 1, 'points' => 700], ['learner_id' => $b->id, 'place' => 1, 'points' => 700]]);

    $photos = $this->tg->photoMessages;
    expect($photos)->toHaveCount(2)
        ->and(array_column($photos, 'chat_id'))->toBe([$a->telegram_user_id, $b->telegram_user_id])
        ->and($photos[0]['caption'])->toBe($photos[1]['caption'])->toContain('1-е место')->toContain('Telegram Premium на 3 месяца')
        // No personal data on it: the tie shares the file.
        ->and($photos[0]['photo'])->toBe($photos[1]['photo'])
        ->and(NotificationLog::query()->where('type', 'league_month_won')->count())->toBe(2);
});

it('takes the prize of the table and names the table on the picture in a v2 month', function (): void {
    $month = mwMonth(['rules_version' => 2, 'prizes' => [
        'free' => [['place' => 1, 'ru' => 'Футболка', 'uz' => 'Futbolka']],
        'pro' => [['place' => 1, 'ru' => 'Наушники', 'uz' => 'Quloqchin']],
    ]]);
    $free = mwWinner();
    $pro = mwWinner(['interface_locale' => 'uz']);

    mwRaise($month, [
        ['learner_id' => $free->id, 'place' => 1, 'points' => 300, 'category' => 'free'],
        ['learner_id' => $pro->id, 'place' => 1, 'points' => 300, 'category' => 'pro'],
    ]);

    $photos = $this->tg->photoMessages;
    expect($photos[0]['caption'])->toContain('Ваш приз: Футболка')
        ->and($photos[1]['caption'])->toContain("Sovg'angiz: Quloqchin")
        ->and($photos[0]['photo'])->toContain('month_place_1_free-ru-')
        ->and($photos[1]['photo'])->toContain('month_place_1_pro-uz-');

    $builder = app(MonthWinnerCardBuilder::class);
    expect($builder->spec($month, 1, 300, ScoreCategory::Pro, 'ru')->label)->toBe('Рейтинг месяца · Pro')
        ->and($builder->spec($month, 2, 300, ScoreCategory::Free, 'uz')->label)->toBe('Oy reytingi · Free')
        ->and($builder->spec($month, 2, 300, null, 'ru')->label)->toBe('Рейтинг месяца');
});

it('sends a v1 month as before, only now with the picture', function (): void {
    $month = mwMonth(['prizes' => []]);
    $winner = mwWinner();
    mwRaise($month, [['learner_id' => $winner->id, 'place' => 1, 'points' => 500]]);

    expect($this->tg->photoMessages[0]['caption'])->toBe('🏆 Поздравляем! Вы заняли 1-е место в рейтинге за октябрь 2026 — 500 баллов.')
        ->and($this->tg->photoMessages[0]['photo'])->toContain('month_place_1-ru-');

    $other = mwMonth(['starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'prizes' => null]);
    $third = mwWinner();
    mwRaise($other, [['learner_id' => $third->id, 'place' => 3, 'points' => 20]]);

    expect($this->tg->photoMessages[1]['caption'])->not->toContain('приз')->not->toContain('свяжемся');
});

it('falls back to the text of the congratulation when the picture cannot be drawn, and reports it', function (): void {
    Exceptions::fake();
    config(['achievements.fonts.regular' => '/nonexistent/font.ttf', 'achievements.fonts.bold' => '/nonexistent/font.ttf']);
    $month = mwMonth();
    $winner = mwWinner();

    mwRaise($month, [['learner_id' => $winner->id, 'place' => 1, 'points' => 1204]]);

    expect($this->tg->photoMessages)->toBe([])
        ->and($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['text'])->toBe('🏆 Поздравляем! Вы заняли 1-е место в рейтинге за октябрь 2026 — 1204 балла. Ваш приз: Telegram Premium на 3 месяца. Мы свяжемся с вами в Telegram, чтобы вручить его.')
        ->and($this->tg->sent[0]['markup']['inline_keyboard'][0][0]['text'])->toBe('🏆 Рейтинг месяца')
        ->and(NotificationLog::query()->where('learner_id', $winner->id)->value('payload'))->toMatchArray(['photo' => false]);
    Exceptions::assertReported(RuntimeException::class);
});

it('sends one message per (winner, month) however often the event is raised', function (): void {
    $month = mwMonth();
    $winner = mwWinner();
    $winners = [['learner_id' => $winner->id, 'place' => 1, 'points' => 500]];

    mwRaise($month, $winners);
    mwRaise($month, $winners); // a retried job

    expect($this->tg->photoMessages)->toHaveCount(1)
        ->and($this->tg->sent)->toBe([])
        ->and(NotificationLog::query()->where('learner_id', $winner->id)->count())->toBe(1);
});

it('sends nothing to a blocked, deleted or DM-closed winner but still reaches the others', function (): void {
    $month = mwMonth();
    $blocked = mwWinner(['blocked_at' => now()]);
    $closed = mwWinner(['dm_blocked_at' => now()]);
    $deleted = mwWinner();
    $deleted->delete();
    $fine = mwWinner();

    mwRaise($month, [
        ['learner_id' => $blocked->id, 'place' => 1, 'points' => 4],
        ['learner_id' => $closed->id, 'place' => 2, 'points' => 3],
        ['learner_id' => $deleted->id, 'place' => 3, 'points' => 2],
        ['learner_id' => $fine->id, 'place' => 4, 'points' => 1],
    ]);

    expect($this->tg->photoMessages)->toHaveCount(1)
        ->and($this->tg->photoMessages[0]['chat_id'])->toBe($fine->telegram_user_id);
});

it('closes the DM and keeps no marker when Telegram answers 403 to the photo', function (): void {
    $month = mwMonth();
    $winner = mwWinner();
    $telegram = Mockery::mock(TelegramClient::class);
    $telegram->shouldReceive('sendPhotoMessage')->once()->andThrow(new TelegramForbiddenException(TelegramForbiddenException::REASON_BOT_BLOCKED));
    $telegram->shouldNotReceive('sendMessage');
    $this->app->instance(TelegramClient::class, $telegram);

    mwRaise($month, [['learner_id' => $winner->id, 'place' => 1, 'points' => 4]]);

    expect($winner->fresh()?->dm_blocked_at)->not->toBeNull()
        ->and(NotificationLog::query()->count())->toBe(0);
});

it('stops at the first 429 without a marker: the other winners are not tried, and the retry reaches each exactly once', function (): void {
    $month = mwMonth();
    $first = mwWinner();
    $second = mwWinner();
    $winners = [
        ['learner_id' => $first->id, 'place' => 1, 'points' => 10],
        ['learner_id' => $second->id, 'place' => 2, 'points' => 5],
    ];
    $this->tg->failSends = 1;
    $this->tg->failSendsRetryAfter = 8;

    expect(fn () => app(MonthWinnerSender::class)->sendAll($month->id, $winners))->toThrow(TelegramRateLimitedException::class);
    expect($this->tg->failSends)->toBe(0)
        ->and($this->tg->photoMessages)->toBe([])
        ->and(NotificationLog::query()->count())->toBe(0);

    expect(app(MonthWinnerSender::class)->sendAll($month->id, $winners))->toBe(['sent' => 2, 'skipped' => 0, 'forbidden' => 0])
        ->and(app(MonthWinnerSender::class)->sendAll($month->id, $winners))->toBe(['sent' => 0, 'skipped' => 2, 'forbidden' => 0])
        ->and($this->tg->photoMessages)->toHaveCount(2);
});

it('deletes the marker on a definite refusal and keeps it after a timeout, goes on with the others and rethrows at the end', function (): void {
    $month = mwMonth();
    $first = mwWinner();
    $second = mwWinner();
    $winners = [
        ['learner_id' => $first->id, 'place' => 1, 'points' => 10],
        ['learner_id' => $second->id, 'place' => 2, 'points' => 5],
    ];

    // A refusal (400): certainly not delivered - no marker, the retry delivers.
    $this->tg->failSends = 1;
    expect(fn () => app(MonthWinnerSender::class)->sendAll($month->id, $winners))->toThrow(TelegramApiException::class);
    expect($this->tg->photoMessages)->toHaveCount(1)
        ->and(NotificationLog::query()->where('learner_id', $first->id)->exists())->toBeFalse()
        ->and(app(MonthWinnerSender::class)->sendAll($month->id, $winners))->toBe(['sent' => 1, 'skipped' => 1, 'forbidden' => 0]);

    // A timeout: may have been delivered - the marker stays, the retry skips.
    $other = mwMonth(['starts_on' => '2026-09-01', 'ends_on' => '2026-09-30']);
    $this->tg->failSends = 1;
    $this->tg->failSendsAsTransport = true;
    expect(fn () => app(MonthWinnerSender::class)->sendAll($other->id, $winners))->toThrow(TelegramApiException::class);
    expect(NotificationLog::query()->where('payload->key', 'month:'.$other->id)->count())->toBe(2)
        ->and(app(MonthWinnerSender::class)->sendAll($other->id, $winners))->toBe(['sent' => 0, 'skipped' => 2, 'forbidden' => 0]);
});

it('is picked up by the event system without a manual registration', function (): void {
    Event::fake();
    Event::assertListening(LeagueMonthConfirmed::class, SendMonthWinnerMessages::class);
});

it('puts one button under the photo: the rating of the month (docs/36 §3а, N4)', function (): void {
    config(['twa.base_url' => 'https://twa.test']);
    $month = mwMonth();
    $ru = mwWinner();
    $uz = mwWinner(['interface_locale' => 'uz']);

    mwRaise($month, [['learner_id' => $ru->id, 'place' => 1, 'points' => 10], ['learner_id' => $uz->id, 'place' => 2, 'points' => 5]]);

    expect($this->tg->photoMessages[0]['markup'])->toBe(['inline_keyboard' => [[
        ['text' => '🏆 Рейтинг месяца', 'web_app' => ['url' => 'https://twa.test/twa/league/month?from=month_won']],
    ]]])
        ->and($this->tg->photoMessages[1]['markup']['inline_keyboard'][0][0]['text'])->toBe('🏆 Oy reytingi');
});

it('sends the same text with the same button once as a message when Telegram refuses the photo, under the same marker', function (): void {
    Exceptions::fake();
    $month = mwMonth();
    $winner = mwWinner();
    $this->tg->failSends = 1;
    $this->tg->failSendsBadRequest = 'IMAGE_PROCESS_FAILED';

    expect(app(MonthWinnerSender::class)->sendAll($month->id, [['learner_id' => $winner->id, 'place' => 1, 'points' => 10]]))
        ->toBe(['sent' => 1, 'skipped' => 0, 'forbidden' => 0]);

    expect($this->tg->photoMessages)->toBe([])
        ->and($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['chat_id'])->toBe($winner->telegram_user_id)
        ->and($this->tg->sent[0]['text'])->toContain('1-е место')
        ->and($this->tg->sent[0]['markup']['inline_keyboard'][0][0]['text'])->toBe('🏆 Рейтинг месяца')
        ->and(NotificationLog::query()->where('learner_id', $winner->id)->sole()->payload)->toMatchArray(['key' => 'month:'.$month->id, 'photo' => false]);
    Exceptions::assertReported(fn (TelegramApiException $e): bool => str_contains($e->getMessage(), 'IMAGE_PROCESS_FAILED'));

    // Once: a repeated event sends nothing more.
    expect(app(MonthWinnerSender::class)->sendAll($month->id, [['learner_id' => $winner->id, 'place' => 1, 'points' => 10]]))
        ->toBe(['sent' => 0, 'skipped' => 1, 'forbidden' => 0]);
});

it('deletes the marker when the text after a refused photo is refused too, so the retry delivers', function (): void {
    Exceptions::fake();
    $month = mwMonth();
    $winner = mwWinner();
    $this->tg->failSends = 2;
    $this->tg->failSendsBadRequest = 'IMAGE_PROCESS_FAILED';

    expect(fn () => app(MonthWinnerSender::class)->sendAll($month->id, [['learner_id' => $winner->id, 'place' => 1, 'points' => 10]]))
        ->toThrow(TelegramApiException::class);
    expect(NotificationLog::query()->where('learner_id', $winner->id)->exists())->toBeFalse()
        ->and($this->tg->sent)->toBe([]);
});

it('takes a slot of the bot-wide budget for every message after the first; none in time puts the listener back, nothing lost', function (): void {
    config(['telegram.outbound.sync_wait_ms' => 0]);
    $limiter = new TelegramMessageRateLimiter(20, 60, 25);
    $this->app->instance(TelegramMessageRateLimiter::class, $limiter);
    $month = mwMonth();
    [$first, $second, $third] = [mwWinner(), mwWinner(), mwWinner()];
    $winners = [
        ['learner_id' => $first->id, 'place' => 1, 'points' => 10],
        ['learner_id' => $second->id, 'place' => 2, 'points' => 5],
        ['learner_id' => $third->id, 'place' => 3, 'points' => 4],
    ];
    // The first message uses the slot of the listener's middleware; the budget is gone before the second.
    $this->tg->beforeSend = function (): void {
        Cache::put(TelegramMessageRateLimiter::PAUSE_KEY, now()->getTimestamp() + 30, 30);
    };

    expect(fn () => app(MonthWinnerSender::class)->sendAll($month->id, $winners))->toThrow(TelegramOutboundBusyException::class);
    expect($this->tg->photoMessages)->toHaveCount(1)
        ->and(NotificationLog::query()->pluck('learner_id')->all())->toBe([$first->id]);

    // The retry after the pause: the first is skipped, the others take a slot each.
    $this->tg->beforeSend = null;
    Cache::forget(TelegramMessageRateLimiter::PAUSE_KEY);
    expect(app(MonthWinnerSender::class)->sendAll($month->id, $winners))->toBe(['sent' => 2, 'skipped' => 1, 'forbidden' => 0])
        ->and($this->tg->photoMessages)->toHaveCount(3)
        ->and(array_column($this->tg->photoMessages, 'chat_id'))->toBe([$first->telegram_user_id, $second->telegram_user_id, $third->telegram_user_id]);
});

it('puts the listener back without an exception when the sender finds no slot (ThrottleTelegramOutbound)', function (): void {
    $job = new class
    {
        public object $job;

        public ?int $releasedFor = null;

        public function __construct()
        {
            $this->job = new stdClass;
        }

        public function release(int $delay): void
        {
            $this->releasedFor = $delay;
        }
    };
    $limiter = new TelegramMessageRateLimiter(20, 60, 0);

    $out = (new ThrottleTelegramOutbound($limiter))->handle($job, fn () => throw new TelegramOutboundBusyException(4));

    expect($out)->toBeNull()->and($job->releasedFor)->toBe(4);
});
