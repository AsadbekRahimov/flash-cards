<?php

declare(strict_types=1);

use App\Domain\Administration\Services\BotBlockedCounter;
use App\Domain\Operations\Support\PureCache;
use App\Filament\Resources\LearnerResource\Pages\ListLearners;
use App\Filament\Widgets\TotalLearnersWidget;
use App\Models\Learner;
use App\Models\TelegramGroup;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\QueryLog;

/*
 * docs/34 §4в п. 4 (B5): who cannot be reached by the bot, in `/admin`. The dashboard says «Заблокировали бота: N (за 7 дней: M)»
 * from a 60-second cache (an active admin only), the learners' list has the filter «Бот недоступен» and the column with the reason.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

function bbUser(string $role): User
{
    $user = User::factory()->create([
        'is_active' => true,
        'two_factor_secret' => 'stub',
        'two_factor_recovery_codes' => [],
        'two_factor_confirmed_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

it('counts the learners who closed the DM and those of the last 7 days, deleted ones aside', function (): void {
    Learner::factory()->create(['dm_blocked_at' => now()->subDays(2), 'dm_blocked_reason' => 'bot_blocked']);
    Learner::factory()->create(['dm_blocked_at' => now()->subDays(30), 'dm_blocked_reason' => 'user_deactivated']);
    Learner::factory()->create(['dm_blocked_at' => now()->subDay()])->delete();
    Learner::factory()->create();
    $this->actingAs(bbUser('admin'));

    Livewire::test(TotalLearnersWidget::class)
        ->assertSee('Заблокировали бота')
        ->assertSee('за 7 дней: 1');

    expect(BotBlockedCounter::counts())->toBe(['total' => 2, 'week' => 1]);
});

it('serves the counter from the cache, a string from Redis included, and recomputes after the TTL', function (): void {
    Learner::factory()->create(['dm_blocked_at' => now()->subDay(), 'dm_blocked_reason' => 'bot_blocked']);
    expect(BotBlockedCounter::counts())->toBe(['total' => 1, 'week' => 1]);

    Learner::factory()->create(['dm_blocked_at' => now()->subDay(), 'dm_blocked_reason' => 'bot_blocked']);
    expect(QueryLog::during(fn () => BotBlockedCounter::counts()))->toBe([])
        ->and(BotBlockedCounter::counts())->toBe(['total' => 1, 'week' => 1]);

    // Redis may hand the numbers back as strings: the reader still gets integers.
    PureCache::store()->put(BotBlockedCounter::KEY, ['total' => '4', 'week' => '2'], 60);
    expect(BotBlockedCounter::counts())->toBe(['total' => 4, 'week' => 2]);

    // A value of another shape (an old format) is recomputed, not trusted.
    PureCache::store()->put(BotBlockedCounter::KEY, '3', 60);
    expect(BotBlockedCounter::counts())->toBe(['total' => 2, 'week' => 2]);

    $this->travel(61)->seconds();
    PureCache::store()->forget(BotBlockedCounter::KEY); // the array store of the tests does not expire on the travelled clock
    expect(BotBlockedCounter::counts())->toBe(['total' => 2, 'week' => 2]);
});

it('does not show the platform-wide counter to a teacher', function (): void {
    Learner::factory()->create(['dm_blocked_at' => now()->subDay(), 'dm_blocked_reason' => 'bot_blocked']);
    $this->actingAs(bbUser('teacher'));

    Livewire::test(TotalLearnersWidget::class)
        ->assertOk()
        ->assertDontSee('Заблокировали бота');
});

it('filters the learners the bot cannot reach and shows the reason with the date', function (): void {
    $this->actingAs(bbUser('admin'));
    $blocked = Learner::factory()->create(['dm_blocked_at' => '2026-11-20 10:00:00', 'dm_blocked_reason' => 'bot_blocked']);
    $dead = Learner::factory()->create(['dm_blocked_at' => '2026-11-21 10:00:00', 'dm_blocked_reason' => 'user_deactivated']);
    $legacy = Learner::factory()->create(['dm_blocked_at' => '2026-11-22 10:00:00', 'dm_blocked_reason' => null]);
    $open = Learner::factory()->create();

    Livewire::test(ListLearners::class)
        ->filterTable('dm_blocked', true)
        ->assertCanSeeTableRecords([$blocked, $dead, $legacy])
        ->assertCanNotSeeTableRecords([$open])
        ->assertSee('Заблокировал бота')
        ->assertSee('Аккаунт удалён')
        ->assertSee('Причина не записана')
        ->assertSee('с 20.11.2026');

    Livewire::test(ListLearners::class)
        ->filterTable('dm_blocked', false)
        ->assertCanSeeTableRecords([$open])
        ->assertCanNotSeeTableRecords([$blocked, $dead, $legacy]);
});

it('gives a teacher neither the filter nor the column', function (): void {
    $teacher = bbUser('teacher');
    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $teacher->telegramGroups()->attach($group->id);
    Learner::factory()->memberOf($group)->create(['dm_blocked_at' => now(), 'dm_blocked_reason' => 'bot_blocked']);
    $this->actingAs($teacher);

    Livewire::test(ListLearners::class)
        ->assertTableFilterHidden('dm_blocked')
        ->assertTableColumnHidden('dm_blocked_reason');
});
