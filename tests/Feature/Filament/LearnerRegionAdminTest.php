<?php

declare(strict_types=1);

/*
 * The region in /admin (docs/35 §2.4, R5): the column and the filter of the learners list (with "not given") within the query budget
 * of docs/20 §1, and the dashboard widget «Ученики по регионам» - one grouped statement through the widget cache, the onboarded
 * learners only, without the deleted ones.
 */

use App\Domain\Analytics\Services\RegionDistributionStats;
use App\Filament\Resources\LearnerResource\Pages\ListLearners;
use App\Filament\Widgets\RegionDistributionWidget;
use App\Models\Learner;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\FilamentQueryBudget;
use Tests\Support\QueryLog;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->setLocale('ru');
    $this->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create([
        'is_active' => true,
        'two_factor_secret' => 'stub',
        'two_factor_recovery_codes' => [],
        'two_factor_confirmed_at' => now(),
    ]);
    $admin->assignRole('admin');
    $this->admin = $admin;
    $this->actingAs($admin);
});

it('lists learners with their region within the query budget: the name comes from the config, no query per row', function (): void {
    $codes = ['UZ-TK', 'UZ-SA', 'other', null];
    $fill = function (int $rows) use ($codes): void {
        $existing = Learner::query()->count();
        for ($i = $existing; $i < $rows; $i++) {
            Learner::factory()->onboarded()->create(['region_code' => $codes[$i % 4]]);
        }
    };

    $queries = FilamentQueryBudget::assert(ListLearners::class, $fill, ceiling: 8);

    expect(count($queries[FilamentQueryBudget::SMALL]))->toBe(count($queries[FilamentQueryBudget::LARGE]));
});

it('shows the region column and filters by a region and by "not given"', function (): void {
    $tashkent = Learner::factory()->onboarded()->create(['region_code' => 'UZ-TK', 'first_name' => 'Tashkentlik']);
    $abroad = Learner::factory()->onboarded()->create(['region_code' => 'other', 'first_name' => 'Abroad']);
    $none = Learner::factory()->onboarded()->create(['region_code' => null, 'first_name' => 'Nobody']);

    Livewire::test(ListLearners::class)
        ->assertOk()
        ->assertSee('город Ташкент')
        ->assertSee('Другая страна')
        ->filterTable('region', 'UZ-TK')
        ->assertCanSeeTableRecords([$tashkent])
        ->assertCanNotSeeTableRecords([$abroad, $none])
        ->filterTable('region', 'none')
        ->assertCanSeeTableRecords([$none])
        ->assertCanNotSeeTableRecords([$tashkent, $abroad])
        ->filterTable('region', 'other')
        ->assertCanSeeTableRecords([$abroad])
        ->assertCanNotSeeTableRecords([$tashkent, $none]);
});

it('counts the onboarded learners per region in one statement, without the deleted and the unfinished ones', function (): void {
    Learner::factory()->onboarded()->count(3)->create(['region_code' => 'UZ-SA']);
    Learner::factory()->onboarded()->create(['region_code' => 'UZ-TK']);
    Learner::factory()->onboarded()->count(2)->create(['region_code' => null]);
    Learner::factory()->onboarded()->create(['region_code' => 'other']);
    Learner::factory()->create(['region_code' => 'UZ-TK', 'onboarding_state' => 'level']);
    Learner::factory()->onboarded()->create(['region_code' => 'UZ-TK'])->delete();

    $queries = QueryLog::during(fn () => app(RegionDistributionStats::class)->compute());
    $result = app(RegionDistributionStats::class)->compute();

    expect($queries)->toHaveCount(1)
        ->and($result['total'])->toBe(7)
        ->and($result['rows'])->toEqual([
            ['id' => 'UZ-TK', 'region' => 'UZ-TK', 'learners' => 1, 'pct' => 14.3],
            ['id' => 'UZ-SA', 'region' => 'UZ-SA', 'learners' => 3, 'pct' => 42.9],
            ['id' => 'other', 'region' => 'other', 'learners' => 1, 'pct' => 14.3],
            ['id' => 'none', 'region' => 'none', 'learners' => 2, 'pct' => 28.6],
        ]);
});

it('shows the widget to an active admin only and opens it a second time without a query', function (): void {
    Learner::factory()->onboarded()->count(2)->create(['region_code' => 'UZ-BU']);
    Learner::factory()->onboarded()->create(['region_code' => null]);
    $teacher = User::factory()->create(['is_active' => true]);
    $teacher->assignRole('teacher');

    $this->actingAs($teacher);
    expect(RegionDistributionWidget::canView())->toBeFalse();

    $this->actingAs($this->admin);
    expect(RegionDistributionWidget::canView())->toBeTrue();

    Livewire::test(RegionDistributionWidget::class)
        ->assertOk()
        ->assertSee('Ученики по областям')
        ->assertSee('Бухарская область')
        ->assertSee('Не указана')
        ->assertSee('66,7%');

    expect(QueryLog::during(fn () => Livewire::test(RegionDistributionWidget::class)->assertOk()))
        ->each->not->toContain('"learners"');
});
