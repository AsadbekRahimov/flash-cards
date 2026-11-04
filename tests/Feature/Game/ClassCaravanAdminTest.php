<?php

declare(strict_types=1);

use App\Domain\Game\Enums\ClassCaravanStatus;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Filament\Resources\ClassCaravanResource;
use App\Filament\Resources\ClassCaravanResource\Pages\ListClassCaravans;
use App\Filament\Resources\ClassCaravanResource\Pages\ViewClassCaravan;
use App\Models\ClassCaravan;
use App\Models\ClassCaravanMember;
use App\Models\Learner;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Livewire\livewire;

use Tests\Support\FakeTelegramClient;
use Tests\Support\FilamentQueryBudget;
use Tests\Support\Game\ClassKit;
use Tests\Support\Game\GameKit;

uses(RefreshDatabase::class);

/*
 * docs/18 §12.10 (G2.11 part 2): the class caravans in /admin - read-only, admin only; the table with its filters, the page with the
 * contributions, "Отменить" (the reason becomes `admin`, the chat of the group is told), the budget of the list.
 */

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-05 10:00:00');
    GameKit::openAccess();
    $this->seed(RolePermissionSeeder::class);
    app()->setLocale('ru');
    $this->admin = cca_user('admin');
    $this->actingAs($this->admin);
    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function cca_user(string $role, bool $active = true): User
{
    $user = User::factory()->create([
        'is_active' => $active,
        'two_factor_secret' => 'stub',
        'two_factor_recovery_codes' => [],
        'two_factor_confirmed_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

/** @param array<string, mixed> $attributes */
function cca_caravan(array $world, array $attributes = []): ClassCaravan
{
    return ClassCaravan::factory()->create([
        'telegram_group_id' => $world['group']->id,
        'organization_id' => $world['organization']->id,
        'course_id' => $world['course']->id,
        'target_stars' => 18,
        ...$attributes,
    ]);
}

it('lets only an active admin open the pages', function (): void {
    $caravan = cca_caravan(ClassKit::world(1));
    $urls = ['/admin/class-caravans', "/admin/class-caravans/{$caravan->id}"];

    foreach ($urls as $url) {
        $this->actingAs($this->admin)->withSession(['2fa.web.passed_at' => now()->timestamp, '2fa.web.user_id' => $this->admin->id])->get($url)->assertOk();
    }
    foreach ([cca_user('teacher'), cca_user('admin', active: false)] as $user) {
        foreach ($urls as $url) {
            $this->actingAs($user)->withSession(['2fa.web.passed_at' => now()->timestamp, '2fa.web.user_id' => $user->id])->get($url)->assertForbidden();
        }
    }
});

it('is read-only: no create, edit or delete, and the cancel is for an active caravan only', function (): void {
    $active = cca_caravan(ClassKit::world(1));
    $over = cca_caravan(ClassKit::world(1, chatId: -2002, teacherTelegramId: null), ['status' => ClassCaravanStatus::Finished, 'active_group_id' => null]);

    expect($this->admin->can('create', ClassCaravan::class))->toBeFalse()
        ->and($this->admin->can('update', $active))->toBeFalse()
        ->and($this->admin->can('delete', $active))->toBeFalse()
        ->and($this->admin->can('cancel', $active))->toBeTrue()
        ->and($this->admin->can('cancel', $over))->toBeFalse()
        ->and(ClassCaravanResource::canCreate())->toBeFalse()
        ->and(array_keys(ClassCaravanResource::getPages()))->toBe(['index', 'view']);
});

it('lists the caravans of every center with the group, center, progress, window, boss and the marker of the summary', function (): void {
    $w = ClassKit::world(2);
    $c = cca_caravan($w, ['stars_total' => 7, 'boss_unlocked_at' => now(), 'summary_posted_at' => '2026-11-05 09:00:00']);
    $other = cca_caravan(ClassKit::world(1, chatId: -2002, teacherTelegramId: null), ['boss_unlocked_at' => now(), 'boss_defeated_at' => now()]);

    livewire(ListClassCaravans::class)
        ->assertCanSeeTableRecords([$c, $other])
        ->assertTableColumnStateSet('group.title', 'Группа A1', $c)
        ->assertTableColumnStateSet('organization.name', $w['organization']->name, $c)
        ->assertTableColumnStateSet('stars_total', '7 / 18', $c)
        ->assertTableColumnStateSet('status', 'active', $c)
        ->assertTableColumnStateSet('starts_at', '05.11.2026 10:00 — 12.11.2026 10:00', $c)
        ->assertTableColumnStateSet('boss', 'ready', $c)
        ->assertTableColumnStateSet('boss', 'defeated', $other)
        ->assertTableColumnFormattedStateSet('status', 'В пути', $c);
});

it('filters the list by status and by center', function (): void {
    $a = ClassKit::world(1);
    $b = ClassKit::world(1, chatId: -2002, teacherTelegramId: null);
    $ca = cca_caravan($a);
    $cb = cca_caravan($b, ['status' => ClassCaravanStatus::Canceled, 'active_group_id' => null]);

    livewire(ListClassCaravans::class)
        ->filterTable('status', 'canceled')->assertCanSeeTableRecords([$cb])->assertCanNotSeeTableRecords([$ca])
        ->removeTableFilters()
        ->filterTable('organization_id', $a['organization']->id)->assertCanSeeTableRecords([$ca])->assertCanNotSeeTableRecords([$cb]);
});

it('shows the contributions of the members on the page of a caravan', function (): void {
    $w = ClassKit::world(1);
    $c = cca_caravan($w, ['summary_posted_at' => null]);
    ClassCaravanMember::factory()->create(['class_caravan_id' => $c->id, 'learner_id' => Learner::factory()->create(['first_name' => 'Anna']), 'stars' => 5, 'runs' => 2, 'best_score' => 80]);

    livewire(ViewClassCaravan::class, ['record' => $c->id])->assertSee('Anna')->assertSee('80')->assertSee('Не отправлены');
});

it('cancels a caravan from the list: the reason is admin, the chat of the group is told, the caravan leaves the "active" slot', function (): void {
    $w = ClassKit::world(1);
    $c = cca_caravan($w);

    livewire(ListClassCaravans::class)->callTableAction('cancel', $c)->assertNotified(__('admin.game.caravans.notifications.canceled'));

    $fresh = $c->fresh();
    expect($fresh->status)->toBe(ClassCaravanStatus::Canceled)
        ->and($fresh->active_group_id)->toBeNull()
        ->and($fresh->meta['canceled_reason'])->toBe('admin')
        ->and($fresh->meta['canceled_by_user_id'])->toBe($this->admin->id)
        ->and($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['chat_id'])->toBe($w['group']->chat_id)
        ->and($this->tg->sent[0]['text'])->toContain('Караван класса отменён');
});

it('cancels from the page of a caravan, and hides the cancel of one that is over', function (): void {
    $w = ClassKit::world(1);
    $c = cca_caravan($w);
    $over = cca_caravan(ClassKit::world(1, chatId: -2002, teacherTelegramId: null), ['status' => ClassCaravanStatus::Finished, 'active_group_id' => null]);

    livewire(ViewClassCaravan::class, ['record' => $c->id])->callAction('cancel');
    expect($c->fresh()->status)->toBe(ClassCaravanStatus::Canceled);

    livewire(ListClassCaravans::class)->assertTableActionHidden('cancel', $over);
    expect($this->tg->sent)->toHaveCount(1);
});

it('keeps the cancellation when the chat does not take the message "canceled"', function (): void {
    $c = cca_caravan(ClassKit::world(1));
    $this->tg->failSends = 1;

    livewire(ListClassCaravans::class)->callTableAction('cancel', $c)->assertNotified(__('admin.game.caravans.notifications.canceled'));

    expect($c->fresh()->status)->toBe(ClassCaravanStatus::Canceled)->and($this->tg->sent)->toBe([]);
});

it('says nothing to the chat when the sweep has closed the caravan under the page, or another cancel came first', function (): void {
    $c = cca_caravan(ClassKit::world(1));
    $stale = $c->fresh();
    ClassCaravan::query()->whereKey($c->id)->update(['status' => 'finished', 'active_group_id' => null, 'finished_at' => now()]);

    ClassCaravanResource::cancel($stale);
    expect($c->fresh()->status)->toBe(ClassCaravanStatus::Finished)->and($this->tg->sent)->toBe([]);

    $second = cca_caravan(ClassKit::world(1, chatId: -2002, teacherTelegramId: null));
    ClassCaravanResource::cancel($second);
    ClassCaravanResource::cancel($second);

    expect($this->tg->sent)->toHaveCount(1);
});

it('records "teacher" when the admin who cancels teaches the group', function (): void {
    $w = ClassKit::world(1);
    $c = cca_caravan($w);
    DB::table('teacher_groups')->insert(['user_id' => $this->admin->id, 'telegram_group_id' => $w['group']->id, 'is_primary' => false, 'created_at' => now(), 'updated_at' => now()]);

    livewire(ListClassCaravans::class)->callTableAction('cancel', $c);

    expect($c->fresh()->meta['canceled_reason'])->toBe('teacher');
});

it('lists the caravans within a budget that does not grow with the number of rows', function (): void {
    $worlds = [ClassKit::world(1), ClassKit::world(1, chatId: -2002, teacherTelegramId: null)];

    FilamentQueryBudget::assert(ListClassCaravans::class, function (int $rows) use ($worlds): void {
        for ($i = ClassCaravan::query()->count(); $i < $rows; $i++) {
            $w = $worlds[$i % 2];
            $caravan = ClassCaravan::factory()->finished()->create([
                'telegram_group_id' => $w['group']->id,
                'organization_id' => $w['organization']->id,
                'course_id' => $w['course']->id,
                'boss_unlocked_at' => $i % 2 === 0 ? now() : null,
            ]);
            ClassCaravanMember::factory()->create(['class_caravan_id' => $caravan->id]);
        }
    }, ceiling: 10); // 10 measured: the TwaAuth-less admin page twice (mount, page size) - the rows with their group, center and course, the options of the center filter
});
