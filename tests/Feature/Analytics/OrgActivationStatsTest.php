<?php

declare(strict_types=1);

use App\Domain\Administration\Services\OrganizationGroupLinker;
use App\Domain\Administration\Services\WidgetCache;
use App\Domain\Operations\Support\PureCache;
use App\Domain\Organizations\Services\OrgActivationStats;
use App\Filament\Widgets\OrgActivationTableWidget;
use App\Models\Assignment;
use App\Models\AssignmentResult;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\TelegramGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\QueryLog;

/*
 * Audit 2026-11-18 §4: the time from the creation of a learning center to its first group link, first assignment and first
 * assignment completed by a learner, the medians over the centers, the waiting centers first, and the admin widget over it
 * (one statement, cached, no query on a warm open). The same file runs on sqlite and on PostgreSQL.
 */

uses(RefreshDatabase::class);

const OAS_NOW = '2026-11-18 10:00:00';

beforeEach(function (): void {
    app()->setLocale('ru');
    $this->travelTo(CarbonImmutable::parse(OAS_NOW, 'UTC'));
});

function oas_center(string $createdUtc, string $name = 'Center'): Organization
{
    return Organization::factory()->create(['name' => $name, 'created_at' => $createdUtc, 'updated_at' => $createdUtc]);
}

function oas_link(Organization $center, string $atUtc, string $action = OrganizationGroupLinker::ACTION_LINKED): void
{
    AuditLog::query()->create([
        'action' => $action,
        'entity_type' => Organization::class,
        'entity_id' => $center->id,
        'meta' => [],
        'created_at' => $atUtc,
    ]);
}

function oas_assignment(Organization $center, string $createdUtc): Assignment
{
    return Assignment::factory()->create(['organization_id' => $center->id, 'created_at' => $createdUtc, 'updated_at' => $createdUtc]);
}

function oas_result(Assignment $assignment, ?string $completedUtc): void
{
    AssignmentResult::factory()->create([
        'assignment_id' => $assignment->id,
        'progress_pct' => $completedUtc === null ? 40 : 100,
        'completed_at' => $completedUtc,
    ]);
}

function oas_compute(): array
{
    return app(OrgActivationStats::class)->compute(CarbonImmutable::parse(OAS_NOW, 'UTC'));
}

it('measures each center from its creation to the first link, the first assignment and the first completed one', function (): void {
    $center = oas_center('2026-11-01 08:00:00', 'Fast');
    oas_link($center, '2026-11-01 10:00:00');                         // 2 h
    oas_link($center, '2026-11-03 10:00:00');                         // a later link changes nothing
    oas_link($center, '2026-10-31 08:00:00', 'organization.unlinked_group'); // not a link
    $first = oas_assignment($center, '2026-11-02 08:00:00');          // 1 day
    oas_assignment($center, '2026-11-05 08:00:00');
    oas_result($first, null);                                         // in progress: not completed
    oas_result($first, '2026-11-04 08:30:00');                        // 3 days 30 min
    oas_result(oas_assignment(oas_center('2026-11-01 08:00:00', 'Other'), '2026-11-01 09:00:00'), '2026-11-01 09:30:00'); // another center's result

    $row = collect(oas_compute()['rows'])->firstWhere('name', 'Fast');

    expect($row)->toMatchArray([
        'id' => $center->id,
        'created_at' => '2026-11-01 08:00:00',
        'age' => 17 * 86400 + 2 * 3600,
        'link' => 2 * 3600,
        'assignment' => 86400,
        'completed' => 3 * 86400 + 1800,
    ]);
});

it('lists the centers without a completed assignment first, the oldest on top, then the others slowest first, with medians', function (): void {
    $young = oas_center('2026-11-15 08:00:00', 'Young waiting');
    $old = oas_center('2026-10-01 08:00:00', 'Old waiting');
    oas_assignment($old, '2026-10-02 08:00:00');                       // an assignment nobody finished yet
    $quick = oas_center('2026-11-01 08:00:00', 'Quick');
    oas_result(oas_assignment($quick, '2026-11-01 09:00:00'), '2026-11-01 10:00:00');   // 2 h
    $slow = oas_center('2026-11-01 08:00:00', 'Slow');
    oas_result(oas_assignment($slow, '2026-11-02 08:00:00'), '2026-11-05 08:00:00');    // 4 days
    oas_link($slow, '2026-11-01 08:30:00');

    $figures = oas_compute();

    expect(array_column($figures['rows'], 'name'))->toBe(['Old waiting', 'Young waiting', 'Slow', 'Quick'])
        ->and($figures['centers'])->toBe(4)
        ->and($figures['waiting'])->toBe(2)
        ->and($figures['steps']['completed'])->toBe(['reached' => 2, 'median' => (2 * 3600 + 4 * 86400) / 2])
        // First assignments: old 1 day, quick 1 h, slow 1 day -> median 1 day.
        ->and($figures['steps']['assignment'])->toBe(['reached' => 3, 'median' => 86400])
        ->and($figures['steps']['link'])->toBe(['reached' => 1, 'median' => 1800])
        ->and(collect($figures['rows'])->firstWhere('name', 'Young waiting'))->toMatchArray(['link' => null, 'assignment' => null, 'completed' => null]);

    expect($young->id)->toBeInt();
});

it('has no medians and no rows when there is no center', function (): void {
    $figures = oas_compute();

    expect($figures['centers'])->toBe(0)
        ->and($figures['waiting'])->toBe(0)
        ->and($figures['rows'])->toBe([])
        ->and($figures['steps']['completed'])->toBe(['reached' => 0, 'median' => null]);
});

it('takes the median of an odd and an even number of values', function (): void {
    expect(OrgActivationStats::median([]))->toBeNull()
        ->and(OrgActivationStats::median([5]))->toBe(5)
        ->and(OrgActivationStats::median([9, 1, 5]))->toBe(5)
        ->and(OrgActivationStats::median([4, 1, 2, 9]))->toBe(3);
});

it('runs one statement for 3 centers and for 30', function (): void {
    $fill = function (int $count): void {
        foreach (range(1, $count) as $i) {
            $center = oas_center('2026-10-'.str_pad((string) (1 + $i % 28), 2, '0', STR_PAD_LEFT).' 08:00:00');
            oas_link($center, '2026-11-01 08:00:00');
            if ($i % 2 === 0) {
                oas_result(oas_assignment($center, '2026-11-02 08:00:00'), '2026-11-03 08:00:00');
            }
        }
    };

    $fill(3);
    $few = QueryLog::during(fn () => oas_compute());
    $fill(27);
    $many = QueryLog::during(fn () => oas_compute());

    expect($few)->toHaveCount(1)->and($many)->toHaveCount(1);
});

it('serves the figures from the cache with numbers cast back from strings', function (): void {
    oas_result(oas_assignment(oas_center('2026-11-01 08:00:00', 'A'), '2026-11-01 09:00:00'), '2026-11-01 10:00:00');
    $stats = app(OrgActivationStats::class);

    $first = $stats->figures();
    expect(QueryLog::during(fn () => $stats->figures()))->toBe([]);

    $key = WidgetCache::key(OrgActivationStats::WIDGET, 'all');
    expect($key)->toBe('admin:widget:org_activation:v1:all');
    $stored = PureCache::store()->get($key);
    $stored['centers'] = '1';
    $stored['steps']['completed']['median'] = '7200';
    $stored['rows'][0]['completed'] = '7200';
    PureCache::store()->put($key, $stored, 600);

    $read = $stats->figures();
    expect($read['centers'])->toBe(1)
        ->and($read['steps']['completed']['median'])->toBe(7200)
        ->and($read['rows'][0]['completed'])->toBe(7200)
        ->and($read)->toBe($first);
});

it('shows the widget to an active admin only, the waiting centers first, and opens it a second time without a query', function (): void {
    app(RolePermissionSeeder::class)->run();
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');
    $teacher = User::factory()->create(['is_active' => true]);
    $teacher->assignRole('teacher');

    $waiting = oas_center('2026-10-01 08:00:00', 'Waiting center');
    TelegramGroup::factory()->create(['organization_id' => $waiting->id]);
    $done = oas_center('2026-11-01 08:00:00', 'Done center');
    oas_link($done, '2026-11-01 10:00:00');
    oas_result(oas_assignment($done, '2026-11-02 08:00:00'), '2026-11-04 09:00:00');

    $this->actingAs($teacher);
    expect(OrgActivationTableWidget::canView())->toBeFalse();

    $this->actingAs($admin);
    expect(OrgActivationTableWidget::canView())->toBeTrue();

    Livewire::test(OrgActivationTableWidget::class)
        ->assertOk()
        ->assertSeeInOrder(['Waiting center', 'Done center'])
        ->assertSee('ещё нет')
        ->assertSee('3 дн 1 ч')              // 01.11 08:00 -> 04.11 09:00
        ->assertSee('2 ч 0 мин')
        ->assertSee('Без выполненного учеником задания: 1');

    expect(QueryLog::during(fn () => Livewire::test(OrgActivationTableWidget::class)->assertOk()))
        ->each->not->toContain('organizations');
});

it('formats durations in minutes, hours and days', function (?int $seconds, string $text): void {
    expect(OrgActivationTableWidget::duration($seconds))->toBe($text);
})->with([
    'none' => [null, '—'],
    'minutes' => [45 * 60 + 30, '45 мин'],
    'hours' => [5 * 3600 + 20 * 60, '5 ч 20 мин'],
    'days' => [3 * 86400 + 4 * 3600 + 59, '3 дн 4 ч'],
]);
