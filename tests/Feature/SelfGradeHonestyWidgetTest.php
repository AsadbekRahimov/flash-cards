<?php

declare(strict_types=1);

use App\Domain\Administration\Services\WidgetCache;
use App\Domain\Learning\Services\SelfGradeHonestyService;
use App\Filament\Widgets\SchedulerComparisonWidget;
use App\Models\ProductEvent;
use App\Models\TrainingReview;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function honestyReview(string $exercise, int $quality, DateTimeInterface $at): void
{
    TrainingReview::factory()->create(['exercise' => $exercise, 'quality' => $quality, 'created_at' => $at]);
}

function honestyEvent(DateTimeInterface $at, string $name = 'review.self_grade_mismatch'): void
{
    ProductEvent::query()->create(['name' => $name, 'props' => [], 'occurred_at' => $at]);
}

it('counts the mismatch events against the wrong objective answers of the window', function (): void {
    $now = CarbonImmutable::parse('2026-11-10 12:00:00');
    $in = $now->subDays(3);
    $old = $now->subDays(40);

    // Wrong objective answers: reverse + spelling in the window count; flash, correct and old ones do not.
    honestyReview('reverse', 2, $in);
    honestyReview('spelling', 0, $in);
    honestyReview('reverse', 4, $in);
    honestyReview('flash', 1, $in);
    honestyReview('reverse', 2, $old);

    honestyEvent($in);
    honestyEvent($old);
    honestyEvent($in, 'review.other');

    expect(app(SelfGradeHonestyService::class)->window($now))->toBe(['mismatches' => 1, 'wrong_objective' => 2]);
});

it('is zero on an empty window', function (): void {
    expect(app(SelfGradeHonestyService::class)->window())->toBe(['mismatches' => 0, 'wrong_objective' => 0]);
});

it('shows the share on the comparison widget and caches it for ten minutes', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');
    $this->actingAs($admin);

    honestyReview('reverse', 2, now()->subDay());
    honestyReview('audio', 2, now()->subDay());
    honestyReview('reverse', 2, now()->subDay());
    honestyReview('reverse', 2, now()->subDay());
    honestyEvent(now()->subDay());

    Livewire::test(SchedulerComparisonWidget::class)
        ->assertSee('Честность самооценки')
        ->assertSee('25,0%')
        ->assertSee('1 из 4');

    // A second event does not show until the entry expires: the figure is served from the cache.
    honestyEvent(now()->subHours(2));
    Livewire::test(SchedulerComparisonWidget::class)->assertSee('1 из 4');

    app(WidgetCache::class)->forget(SchedulerComparisonWidget::HONESTY_WIDGET, 'all');
    Livewire::test(SchedulerComparisonWidget::class)->assertSee('2 из 4')->assertSee('50,0%');
});

it('shows a dash when there are no wrong objective answers', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');
    $this->actingAs($admin);

    Livewire::test(SchedulerComparisonWidget::class)->assertSee('Честность самооценки')->assertSee('0 из 0');
});
