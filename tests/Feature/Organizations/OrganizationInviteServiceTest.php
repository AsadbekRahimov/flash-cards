<?php

declare(strict_types=1);

use App\Domain\Organizations\Services\OrganizationInviteService;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\TelegramGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates an active invite with an unambiguous 10-character code and the given attributes', function (): void {
    $org = Organization::factory()->create();
    $by = User::factory()->create();
    $group = TelegramGroup::factory()->create(['organization_id' => $org->id]);
    $expires = CarbonImmutable::parse('2026-12-01 10:00:00');

    $invite = app(OrganizationInviteService::class)->create($org, $by, [
        'label' => '  группа 7Б  ',
        'telegram_group_id' => $group->id,
        'max_uses' => 0, // clamped to 1
        'expires_at' => $expires,
    ]);

    expect($invite->code)->toMatch('/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{10}$/')
        ->and($invite->organization_id)->toBe($org->id)
        ->and($invite->label)->toBe('группа 7Б')
        ->and($invite->telegram_group_id)->toBe($group->id)
        ->and($invite->max_uses)->toBe(1)
        ->and($invite->uses_count)->toBe(0)
        ->and($invite->expires_at?->toIso8601String())->toBe($expires->toIso8601String())
        ->and($invite->is_active)->toBeTrue()
        ->and($invite->created_by_user_id)->toBe($by->id);

    $bare = app(OrganizationInviteService::class)->create($org, null);
    expect($bare->label)->toBeNull()
        ->and($bare->max_uses)->toBeNull()
        ->and($bare->expires_at)->toBeNull()
        ->and($bare->created_by_user_id)->toBeNull()
        ->and($bare->code)->not->toBe($invite->code);
});

it('never uses 0, O, 1 or I in a code and honours the configured length', function (): void {
    config()->set('organizations.invite_code_length', 12);

    foreach (range(1, 50) as $_) {
        $code = OrganizationInviteService::generateCode();
        expect($code)->toHaveLength(12)->and($code)->not->toMatch('/[0O1I]/');
    }

    config()->set('organizations.invite_code_length', 99); // capped by the column (varchar(16))
    expect(OrganizationInviteService::generateCode())->toHaveLength(16);
});

it('builds the deep link from the bot username and normalizes typed codes', function (): void {
    config()->set('telegram.bot_username', '@LexiFlowBot');
    $invite = OrganizationInvite::factory()->create(['code' => 'ABCDEFGHJK']);

    expect(app(OrganizationInviteService::class)->link($invite))->toBe('https://t.me/LexiFlowBot?start=org_ABCDEFGHJK')
        ->and(OrganizationInviteService::startPayload('ABCDEFGHJK'))->toBe('org_ABCDEFGHJK')
        ->and(OrganizationInviteService::normalizeCode("  abcdefghjk\n"))->toBe('ABCDEFGHJK');
});

it('deactivates an invite once and leaves the counters alone', function (): void {
    $invite = OrganizationInvite::factory()->create(['uses_count' => 3]);

    $same = app(OrganizationInviteService::class)->deactivate($invite, User::factory()->create());
    app(OrganizationInviteService::class)->deactivate($invite);

    expect($same->is_active)->toBeFalse()
        ->and($invite->fresh()?->is_active)->toBeFalse()
        ->and($invite->fresh()?->uses_count)->toBe(3);
});

it('tells expired and exhausted invites apart on the model', function (): void {
    $now = CarbonImmutable::parse('2026-10-19 12:00:00');
    $open = OrganizationInvite::factory()->create(['expires_at' => $now->addMinute(), 'max_uses' => 2, 'uses_count' => 1]);
    $expired = OrganizationInvite::factory()->create(['expires_at' => $now]);
    $exhausted = OrganizationInvite::factory()->create(['max_uses' => 2, 'uses_count' => 2]);

    expect($open->isExpiredAt($now))->toBeFalse()->and($open->isExhausted())->toBeFalse()
        ->and($expired->isExpiredAt($now))->toBeTrue()
        ->and($exhausted->isExhausted())->toBeTrue();
});
