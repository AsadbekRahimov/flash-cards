<?php

declare(strict_types=1);

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Handlers\OrgCommandHandler;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeTelegramClient;

/*
 * Bot `/org` (docs/13 §6.3): a staff user whose Telegram is linked gets a block per center with the
 * cabinet URL; anyone else a hint. DM only. (Registration in TelegramDispatcher is left to the
 * coordinator — the dispatcher is being changed by 2A.3.)
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
    $this->handler = app(OrgCommandHandler::class);
});

/** @return array<string, mixed> */
function orgCmd(int $fromId, string $text = '/org', string $chatType = 'private'): array
{
    return ['message' => [
        'message_id' => 1,
        'chat' => ['id' => $fromId, 'type' => $chatType],
        'from' => ['id' => $fromId, 'language_code' => 'ru'],
        'text' => $text,
    ]];
}

it('matches /org only in a private chat', function (): void {
    expect($this->handler->matches(orgCmd(1)))->toBeTrue()
        ->and($this->handler->matches(orgCmd(1, '/org@LexiBot')))->toBeTrue()
        ->and($this->handler->matches(orgCmd(1, '/organize')))->toBeFalse()
        ->and($this->handler->matches(orgCmd(1, '/org', 'supergroup')))->toBeFalse()
        ->and($this->handler->requiresActiveGroup())->toBeFalse();
});

it('tells a non-staff Telegram user what /org is for', function (): void {
    Learner::factory()->create(['telegram_user_id' => 777]);

    $this->handler->handle(orgCmd(777));

    expect($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['text'])->toBe(__('org.bot.not_staff', [], 'ru'));
});

it('lists the centers of a linked staff user with seats for the owner and activity for both roles', function (): void {
    $user = User::factory()->create(['telegram_user_id' => 4242, 'is_active' => true]);
    $owned = Organization::factory()->create(['name' => 'Alpha', 'slug' => 'alpha']);
    $taught = Organization::factory()->create(['name' => 'Beta', 'slug' => 'beta']);
    Subscription::factory()->forOrganization($owned)->create(['current_period_ends_at' => null, 'tier' => 'tutor', 'seats_limit' => 30]);
    OrganizationUser::factory()->create(['organization_id' => $owned->id, 'user_id' => $user->id, 'role' => OrganizationRole::Owner->value]);
    OrganizationUser::factory()->teacher()->create(['organization_id' => $taught->id, 'user_id' => $user->id]);

    $learner = Learner::factory()->seatedIn($owned)->create();
    LearnerDailyStats::factory()->create(['learner_id' => $learner->id, 'date' => now()->toDateString(), 'reviews_count' => 5]);

    $this->handler->handle(orgCmd(4242));

    expect($this->tg->sent)->toHaveCount(1);
    $text = $this->tg->sent[0]['text'];
    expect($text)->toContain('Alpha')->toContain('1 / 30')->toContain(url('/org/alpha'))
        ->toContain('Beta')->toContain(url('/org/beta'))
        ->and(substr_count($text, '/org/'))->toBe(2);
});

it('ignores an inactive account', function (): void {
    $user = User::factory()->create(['telegram_user_id' => 4343, 'is_active' => false]);
    OrganizationUser::factory()->create(['user_id' => $user->id]);

    $this->handler->handle(orgCmd(4343));

    expect($this->tg->sent[0]['text'])->toBe(__('org.bot.not_staff', [], 'ru'));
});
