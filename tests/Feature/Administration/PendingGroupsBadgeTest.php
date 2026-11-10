<?php

declare(strict_types=1);

use App\Domain\Administration\Services\PendingGroupsBadge;
use App\Filament\Resources\TelegramGroupResource;
use App\Models\TelegramGroup;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The «Ждут активации» badge of the `/admin` menu: groups with status = pending, a 60-second cache of one integer, dropped
 * by TelegramGroupObserver when a group is created, changes status or is deleted.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole('admin');
    $this->teacher = User::factory()->create(['is_active' => true]);
    $this->teacher->assignRole('teacher');
    PendingGroupsBadge::forget();
});

it('shows the number of pending groups to an admin, nothing when there are none', function (): void {
    $this->actingAs($this->admin);

    expect(TelegramGroupResource::getNavigationBadge())->toBeNull();

    TelegramGroup::factory()->count(2)->create(['status' => 'pending']);
    TelegramGroup::factory()->create(['status' => 'active']);
    TelegramGroup::factory()->create(['status' => 'disabled']);

    expect(TelegramGroupResource::getNavigationBadge())->toBe('2')
        ->and(TelegramGroupResource::getNavigationBadgeTooltip())->toBe('Ждут активации');
});

it('shows nothing to a teacher', function (): void {
    TelegramGroup::factory()->create(['status' => 'pending']);
    $this->actingAs($this->teacher);

    expect(TelegramGroupResource::getNavigationBadge())->toBeNull();
});

it('counts once per minute: a second read runs no query', function (): void {
    $this->actingAs($this->admin);
    TelegramGroup::factory()->create(['status' => 'pending']);
    PendingGroupsBadge::count();

    DB::enableQueryLog();
    PendingGroupsBadge::count();
    PendingGroupsBadge::count();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toBe([]);
});

it('drops the cached number when a group is created, activated or deleted (written, then the old value is not served)', function (): void {
    expect(PendingGroupsBadge::count())->toBe(0);

    $group = TelegramGroup::factory()->create(['status' => 'pending']);
    expect(PendingGroupsBadge::count())->toBe(1);

    $other = TelegramGroup::factory()->create(['status' => 'pending']);
    expect(PendingGroupsBadge::count())->toBe(2);

    $group->update(['status' => 'active']);
    expect(PendingGroupsBadge::count())->toBe(1);

    $other->delete();
    expect(PendingGroupsBadge::count())->toBe(0);
});

it('leaves the cached number alone when something else of the group changes', function (): void {
    $group = TelegramGroup::factory()->create(['status' => 'pending']);
    expect(PendingGroupsBadge::count())->toBe(1);

    // Out of band (a query-builder write has no observer): only a status change through Eloquent drops the entry.
    DB::table('telegram_groups')->where('id', $group->id)->update(['status' => 'active']);
    $group->refresh()->update(['title' => 'Renamed']);

    expect(PendingGroupsBadge::count())->toBe(1);
});

it('has an index on the status column the badge counts by', function (): void {
    expect(collect(Schema::getIndexes('telegram_groups'))->contains(fn (array $index): bool => $index['columns'] === ['status']))->toBeTrue();
});
