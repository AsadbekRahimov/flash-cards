<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Twa\Services\JwtService;
use App\Models\Learner;
use App\Models\ProductEvent;
use App\Models\TrainingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/*
 * The button left on a reminder question after the answer opens the app with `?from=reminder_quiz`; the app opens the
 * daily review with that source, which is how "opened after answering" is told apart from "opened without answering"
 * (docs/12 §7.5, `training.session_opened {source}`).
 */

/** @param array<string, mixed> $body */
function reminderQuizOpenSession(Learner $learner, array $body): TestResponse
{
    return test()->withHeaders(['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']])
        ->postJson('/api/twa/training/sessions', $body);
}

it('accepts reminder_quiz as the source of a session and records it on training.session_opened', function (): void {
    $learner = Learner::factory()->create();

    reminderQuizOpenSession($learner, ['mode' => 'daily_review', 'source' => 'reminder_quiz'])->assertOk();

    $event = ProductEvent::query()->where('name', ProductEventName::TrainingSessionOpened->value)->sole();
    expect($event->props)->toMatchArray(['mode' => 'daily_review', 'source' => 'reminder_quiz'])
        ->and(TrainingSession::query()->sole()->source)->toBe('reminder_quiz');
});

it('still refuses a source that is not on the list', function (): void {
    $learner = Learner::factory()->create();

    reminderQuizOpenSession($learner, ['mode' => 'daily_review', 'source' => 'reminder_quiz2'])->assertStatus(422);
    reminderQuizOpenSession($learner, ['mode' => 'daily_review', 'source' => 'reminder'])->assertOk();
});
