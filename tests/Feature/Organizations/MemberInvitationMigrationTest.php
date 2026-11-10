<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The migration that moves staff invitations out of `organization_members` (review finding B1): `invited` / `declined` rows
 * become `organization_member_invitations` rows with the learner's handle and leave the memberships; `down()` brings the open ones
 * that name a learner back as `invited` rows.
 */

uses(RefreshDatabase::class);

function mimMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require base_path('database/migrations/2026_11_10_130000_create_organization_member_invitations_table.php');

    return $migration;
}

/** @return array<string, mixed> */
function mimMember(int $organizationId, int $learnerId, string $status, string $at, ?string $leftAt = null): array
{
    return [
        'organization_id' => $organizationId,
        'learner_id' => $learnerId,
        'status' => $status,
        'seat_state' => 'none',
        'joined_via' => 'admin',
        'invite_id' => null,
        'seated_at' => null,
        'waitlisted_at' => null,
        'joined_at' => $at,
        'left_at' => $leftAt,
        'invited_at' => $at,
        'invited_by_user_id' => null,
        'meta' => '{}',
        'created_at' => $at,
        'updated_at' => $at,
    ];
}

it('moves invited and declined member rows into invitations on up(), and open ones back on down()', function (): void {
    $migration = mimMigration();
    $migration->down();
    expect(Schema::hasTable('organization_member_invitations'))->toBeFalse()
        ->and(Schema::hasColumn('organization_members', 'invited_at'))->toBeTrue();

    $organizationId = (int) DB::table('organizations')->insertGetId(['name' => 'C', 'slug' => 'c', 'status' => 'active', 'timezone' => 'UTC', 'locale' => 'ru', 'created_at' => now(), 'updated_at' => now()]);
    $named = (int) DB::table('learners')->insertGetId(['telegram_user_id' => 7001, 'username' => 'Named_One', 'first_name' => 'N', 'created_at' => now(), 'updated_at' => now()]);
    $unnamed = (int) DB::table('learners')->insertGetId(['telegram_user_id' => 7002, 'username' => null, 'first_name' => 'U', 'created_at' => now(), 'updated_at' => now()]);
    $active = (int) DB::table('learners')->insertGetId(['telegram_user_id' => 7003, 'username' => 'Active_One', 'first_name' => 'A', 'created_at' => now(), 'updated_at' => now()]);

    $recent = now()->subDay()->format('Y-m-d H:i:s');
    $old = now()->subDays(30)->format('Y-m-d H:i:s');
    DB::table('organization_members')->insert([
        mimMember($organizationId, $named, 'invited', $recent),
        mimMember($organizationId, $unnamed, 'declined', $recent, $recent),
        mimMember($organizationId, $active, 'active', $old),
        [...mimMember($organizationId, (int) DB::table('learners')->insertGetId(['telegram_user_id' => 7004, 'first_name' => 'E', 'created_at' => now(), 'updated_at' => now()]), 'invited', $old)],
    ]);

    $migration->up();

    expect(Schema::hasColumn('organization_members', 'invited_at'))->toBeFalse()
        ->and(DB::table('organization_members')->pluck('status')->all())->toBe(['active']);

    $rows = DB::table('organization_member_invitations')->orderBy('id')->get();
    expect($rows)->toHaveCount(3)
        ->and($rows[0]->invited_handle)->toBe('@named_one')
        ->and((int) $rows[0]->learner_id)->toBe($named)
        ->and($rows[0]->declined_at)->toBeNull()
        ->and($rows[0]->dm_sent_at)->toBe($recent)
        ->and($rows[1]->invited_handle)->toBe('7002')
        ->and($rows[1]->declined_at)->toBe($recent)
        ->and($rows[2]->invited_handle)->toBe('7004')
        ->and(strtotime((string) $rows[2]->expires_at))->toBeLessThan(time())
        ->and(strtotime((string) $rows[0]->expires_at))->toBeGreaterThan(time());

    // Back: the two open (unexpired) invitations become member rows again, the expired one does not; the active member is untouched.
    $migration->down();

    expect(Schema::hasTable('organization_member_invitations'))->toBeFalse()
        ->and(DB::table('organization_members')->orderBy('learner_id')->pluck('status', 'learner_id')->all())
        ->toBe([$named => 'invited', $unnamed => 'declined', $active => 'active']);

    $migration->up();
    expect(DB::table('organization_member_invitations')->count())->toBe(2);
});
