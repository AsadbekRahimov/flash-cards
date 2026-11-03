<?php

declare(strict_types=1);

use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Domain\Twa\Services\JwtService;
use App\Models\AuditLog;
use App\Models\Learner;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * POST /api/twa/me/delete (docs/04 A.1a): the confirmation is mandatory, the token stops working at once
 * (401 learner_not_found), the learner's own /start within the delay brings the account back.
 */

uses(RefreshDatabase::class);

function deleteHeaders(Learner $learner): array
{
    return ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
}

it('deletes the account on confirm and answers with the date until which the learner can return', function (): void {
    config()->set('learners.purge.after_days', 30);
    $learner = Learner::factory()->create();

    $response = $this->withHeaders(deleteHeaders($learner))->postJson('/api/twa/me/delete', ['confirm' => true]);

    $response->assertOk()
        ->assertJsonPath('deleted', true)
        ->assertJsonPath('restorable_until', Learner::withTrashed()->find($learner->id)->deleted_at->addDays(30)->toIso8601String());
    expect(Learner::query()->find($learner->id))->toBeNull()
        ->and(AuditLog::query()->where('action', 'learner.deleted')->where('entity_id', $learner->id)->whereNull('user_id')->count())->toBe(1);
});

it('refuses without a confirmation and deletes nothing', function (array $body): void {
    $learner = Learner::factory()->create();

    $this->withHeaders(deleteHeaders($learner))->postJson('/api/twa/me/delete', $body)
        ->assertStatus(422)
        ->assertJsonValidationErrors('confirm');

    expect(Learner::query()->find($learner->id))->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'learner.deleted')->count())->toBe(0);
})->with([
    'no body' => [[]],
    'false' => [['confirm' => false]],
    'zero' => [['confirm' => 0]],
    'text' => [['confirm' => 'no']],
]);

it('requires a token', function (): void {
    $this->postJson('/api/twa/me/delete', ['confirm' => true])->assertUnauthorized();
});

it('stops the token at once: 401 learner_not_found, also on a repeated delete', function (): void {
    $learner = Learner::factory()->create();
    $headers = deleteHeaders($learner);

    $this->withHeaders($headers)->postJson('/api/twa/me/delete', ['confirm' => true])->assertOk();

    $this->withHeaders($headers)->getJson('/api/twa/me')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'learner_not_found');
    $this->withHeaders($headers)->postJson('/api/twa/me/delete', ['confirm' => true])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'learner_not_found');
    expect(AuditLog::query()->where('action', 'learner.deleted')->count())->toBe(1);
});

it('brings the account back with /start within the delay', function (): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 880_100]);
    $this->withHeaders(deleteHeaders($learner))->postJson('/api/twa/me/delete', ['confirm' => true])->assertOk();

    app(TelegramDispatcher::class)->dispatch(['message' => [
        'chat' => ['id' => 880_100, 'type' => 'private'],
        'from' => ['id' => 880_100, 'first_name' => 'Ali', 'language_code' => 'ru'],
        'text' => '/start',
    ]]);

    expect(Learner::query()->find($learner->id))->not->toBeNull();
    $this->withHeaders(deleteHeaders($learner))->getJson('/api/twa/me')->assertOk();
});
