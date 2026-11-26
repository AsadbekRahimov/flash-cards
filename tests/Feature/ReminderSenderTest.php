<?php

declare(strict_types=1);

use App\Domain\Learners\Enums\ReminderOutcome;
use App\Domain\Learners\Services\ReminderSender;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Models\Course;
use App\Models\Learner;
use App\Models\NotificationLog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeTelegramClient;
use Tests\Support\ReminderQuizFixtures as Fx;

/*
 * The one button of a plain reminder says what to do (docs/36 §3а, N5): "▶️ Start a lesson" for the kinds that call to learn,
 * "▶️ First lesson" for a learner who never started, "📚 Choose a course" (to the catalogue) for one without a course. The question
 * of a reminder is not this test's (ReminderQuizSendTest).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['twa.base_url' => 'https://twa.test', 'learning.reminders.quiz.enabled' => false]);
    $this->travelTo(CarbonImmutable::parse('2026-10-18 14:05:00', 'UTC'));

    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
});

function rsSend(Learner $learner): ReminderOutcome
{
    return app(ReminderSender::class)->send($learner->id, CarbonImmutable::parse('2026-10-18 14:00:00', 'UTC'));
}

it('gives a reminder to review "Start a lesson" into Today', function (): void {
    $learner = Fx::learner();
    foreach (array_slice(Fx::lesson(6), 0, 3) as $word) {
        Fx::due($learner, $word);
    }

    expect(rsSend($learner))->toBe(ReminderOutcome::Sent)
        ->and($this->tg->sent[0]['text'])->toStartWith('Пора повторить 3 слова')
        ->and($this->tg->sent[0]['button'])->toBe('▶️ Начать занятие')
        ->and($this->tg->sent[0]['url'])->toBe('https://twa.test/twa/?from=reminder');
});

it('gives a learner who never started "First lesson" into Today', function (): void {
    $learner = Fx::learner(['active_course_id' => Course::factory()->published()->create()->id]);

    expect(rsSend($learner))->toBe(ReminderOutcome::Sent)
        ->and($this->tg->sent[0]['text'])->toStartWith('Вы ещё не начали')
        ->and($this->tg->sent[0]['button'])->toBe('▶️ Первое занятие')
        ->and($this->tg->sent[0]['url'])->toBe('https://twa.test/twa/?from=reminder');
});

it('gives a learner without a course "Choose a course" into the catalogue, in their language', function (): void {
    $learner = Fx::learner(['active_course_id' => null, 'interface_locale' => 'uz']);

    expect(rsSend($learner))->toBe(ReminderOutcome::Sent)
        ->and($this->tg->sent[0]['button'])->toBe('📚 Kursni tanlash')
        ->and($this->tg->sent[0]['url'])->toBe('https://twa.test/twa/courses?from=reminder');
});

it('still drops the marker and closes the DM on a 403 with the new button', function (): void {
    $learner = Fx::learner(['active_course_id' => null]);
    $this->tg->failSends = 1;
    $this->tg->failSendsForbidden = TelegramForbiddenException::REASON_BOT_BLOCKED;

    expect(rsSend($learner))->toBe(ReminderOutcome::Forbidden)
        ->and(NotificationLog::query()->where('learner_id', $learner->id)->count())->toBe(0)
        ->and($learner->fresh()?->dm_blocked_at)->not->toBeNull();
});
