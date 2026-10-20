<?php

declare(strict_types=1);

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Filament\Org\Pages\Reports;
use App\Models\Assignment;
use App\Models\AssignmentResult;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\Lesson;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;

/*
 * The /org Reports screen (docs/13 §6.4, DoD 2B.5): the owner sees the whole center, a teacher only
 * their groups, another organization is a 404; the CSV download is UTF-8 with BOM and carries the
 * numbers of the table.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->a = Organization::factory()->create(['name' => 'Center A', 'slug' => 'center-a', 'timezone' => 'UTC', 'locale' => 'ru']);
    $this->b = Organization::factory()->create(['name' => 'Center B', 'slug' => 'center-b', 'timezone' => 'UTC']);
    Subscription::factory()->forOrganization($this->a)->create(['current_period_ends_at' => null, 'tier' => 'tutor', 'seats_limit' => 30]);

    $this->ownerA = rpUser($this->a, OrganizationRole::Owner);
    $this->ownerB = rpUser($this->b, OrganizationRole::Owner);
    $this->teacherA = rpUser($this->a, OrganizationRole::Teacher, twoFactor: false);

    $this->g1 = TelegramGroup::factory()->create(['organization_id' => $this->a->id, 'title' => 'Mine', 'status' => 'active']);
    $this->g2 = TelegramGroup::factory()->create(['organization_id' => $this->a->id, 'title' => 'Not mine', 'status' => 'active']);
    $this->gB = TelegramGroup::factory()->create(['organization_id' => $this->b->id, 'title' => 'Other center', 'status' => 'active']);
    $this->teacherA->telegramGroups()->attach($this->g1->id, ['is_primary' => true]);

    $this->mine = Learner::factory()->memberOf($this->g1)->seatedIn($this->a)->create(['first_name' => 'MineLearner', 'last_name' => null, 'username' => null]);
    $this->notMine = Learner::factory()->memberOf($this->g2)->seatedIn($this->a)->create(['first_name' => 'NotMineLearner', 'last_name' => null, 'username' => null]);
    $this->foreign = Learner::factory()->memberOf($this->gB)->seatedIn($this->b)->create(['first_name' => 'ForeignLearner', 'last_name' => null, 'username' => null]);

    $today = now('UTC')->toDateString();
    LearnerDailyStats::factory()->create(['learner_id' => $this->mine->id, 'date' => $today, 'reviews_count' => 11, 'new_words_count' => 2]);
    LearnerDailyStats::factory()->create(['learner_id' => $this->notMine->id, 'date' => $today, 'reviews_count' => 22, 'new_words_count' => 3]);
    LearnerDailyStats::factory()->create(['learner_id' => $this->foreign->id, 'date' => $today, 'reviews_count' => 33, 'new_words_count' => 4]);
    // Outside the default 30 days, but inside a custom period.
    LearnerDailyStats::factory()->create(['learner_id' => $this->mine->id, 'date' => now('UTC')->subDays(60)->toDateString(), 'reviews_count' => 5, 'new_words_count' => 0]);

    $lesson = Lesson::factory()->create();
    $as = Assignment::factory()->create(['organization_id' => $this->a->id, 'telegram_group_id' => $this->g1->id, 'lesson_id' => $lesson->id, 'due_at' => now()->subDay()]);
    AssignmentResult::factory()->completed()->create(['assignment_id' => $as->id, 'learner_id' => $this->mine->id]);
});

function rpUser(Organization $organization, OrganizationRole $role, bool $twoFactor = true): User
{
    $user = User::factory()->create([
        'is_active' => true,
        'two_factor_secret' => $twoFactor ? 'stub' : null,
        'two_factor_recovery_codes' => $twoFactor ? [] : null,
        'two_factor_confirmed_at' => $twoFactor ? now() : null,
    ]);
    OrganizationUser::factory()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role->value]);

    return $user;
}

function rpEnter(User $user, Organization $tenant): void
{
    Filament::setCurrentPanel(Filament::getPanel('org'));
    Filament::bootCurrentPanel();
    test()->actingAs($user, 'org');
    Session::put('2fa.passed_at', now()->timestamp);
    Session::put('2fa.user_id', $user->id);
    Filament::setTenant($tenant, isQuiet: true);
    app()->setLocale($tenant->locale);
}

/** Decoded body of the file the component sent to download. */
function rpDownload(mixed $component): string
{
    $download = $component->effects['download'] ?? null;
    expect($download)->not->toBeNull();

    return (string) base64_decode((string) $download['content'], true);
}

it('shows the owner every group and learner of the center and nothing of another center', function (): void {
    rpEnter($this->ownerA, $this->a);

    Livewire::test(Reports::class)
        ->assertSuccessful()
        ->assertSee('MineLearner')
        ->assertSee('NotMineLearner')
        ->assertSee('Mine')
        ->assertSee('Not mine')
        ->assertDontSee('ForeignLearner')
        ->assertDontSee('Other center');
});

it('shows a teacher only their own groups and learners', function (): void {
    rpEnter($this->teacherA, $this->a);

    Livewire::test(Reports::class)
        ->assertSee('MineLearner')
        ->assertDontSee('NotMineLearner')
        ->assertDontSee('Not mine')
        ->assertDontSee('ForeignLearner');
});

it('offers a teacher only their own groups in the filter, and an injected foreign group yields an empty report', function (): void {
    rpEnter($this->teacherA, $this->a);

    $component = Livewire::test(Reports::class);
    $method = new ReflectionMethod(Reports::class, 'groupOptions');
    expect($method->invoke($component->instance()))->toBe([$this->g1->id => 'Mine']);

    $component->set('data.group', $this->g2->id)
        ->assertDontSee('NotMineLearner')
        ->assertDontSee('MineLearner');
    expect($component->instance()->learnerRows())->toBe([])
        ->and($component->instance()->groupRows())->toBe([]);
});

it('filters by group and by period', function (): void {
    rpEnter($this->ownerA, $this->a);

    $component = Livewire::test(Reports::class)->set('data.group', $this->g2->id);
    expect(array_map(fn ($r) => $r->name, $component->instance()->learnerRows()))->toBe(['NotMineLearner']);

    $component->set('data.group', null);
    $reviews = fn () => collect($component->instance()->learnerRows())->firstWhere('name', 'MineLearner')->reviews;
    expect($reviews())->toBe(11);

    $component->set('data.period', 'custom')
        ->set('data.from', now('UTC')->subDays(61)->toDateString())
        ->set('data.to', now('UTC')->toDateString());
    expect($reviews())->toBe(16);

    $component->set('data.period', '7');
    expect($reviews())->toBe(11);
});

it('rejects an inverted or longer-than-366-days custom period and downloads nothing', function (): void {
    rpEnter($this->ownerA, $this->a);

    $component = Livewire::test(Reports::class)
        ->set('data.period', 'custom')
        ->set('data.from', '2026-10-10')
        ->set('data.to', '2026-10-01')
        ->assertSee(__('org.reports.errors.invalid_range'))
        ->assertDontSee('MineLearner');
    expect($component->instance()->range())->toBeNull();

    $component->set('data.from', '2024-01-01')->set('data.to', '2026-10-01')
        ->assertSee(__('org.reports.errors.range_too_long', ['days' => 366]));

    $component->callAction('csvLearners');
    expect($component->effects['download'] ?? null)->toBeNull();
});

it('downloads the learners CSV as UTF-8 with BOM, localized headers and the table numbers', function (): void {
    rpEnter($this->ownerA, $this->a);

    $component = Livewire::test(Reports::class)->callAction('csvLearners');
    $csv = rpDownload($component);

    expect(str_starts_with($csv, "\xEF\xBB\xBF"))->toBeTrue()
        ->and($component->effects['download']['name'])->toStartWith('activity-center-a-')->toEndWith('.csv');

    $lines = explode("\r\n", rtrim(substr($csv, 3), "\r\n"));
    expect(str_getcsv($lines[0], ';', '"', '')[0])->toBe('Ученик')
        ->and($lines)->toHaveCount(3)  // header + the two learners of the center
        ->and($csv)->toContain('NotMineLearner;;22;3;1;0;0;0;0;')
        ->and($csv)->toContain('MineLearner;;11;2;1;0;0;1;1;')
        ->and($csv)->not->toContain('Foreign');
});

it('limits the CSV of a teacher to their own groups and exports the group summary', function (): void {
    rpEnter($this->teacherA, $this->a);

    $learners = rpDownload(Livewire::test(Reports::class)->callAction('csvLearners'));
    expect($learners)->toContain('MineLearner')->not->toContain('NotMineLearner');

    $groups = rpDownload(Livewire::test(Reports::class)->callAction('csvGroups'));
    expect(str_starts_with($groups, "\xEF\xBB\xBF"))->toBeTrue()
        ->and($groups)->toContain("Mine;1;1;11;2;0;1;1\r\n")
        ->and($groups)->not->toContain('Not mine');
});

it('speaks the center language in the CSV headers (uz)', function (): void {
    $this->a->update(['locale' => 'uz']);
    rpEnter($this->ownerA->refresh(), $this->a->refresh());

    $csv = rpDownload(Livewire::test(Reports::class)->callAction('csvLearners'));

    expect(str_getcsv(explode("\r\n", substr($csv, 3))[0], ';', '"', '')[0])->toBe("O'quvchi");
});

it('answers 404 for the reports of another organization and 200 for both roles in their own', function (): void {
    $this->actingAs($this->ownerA, 'org')->withSession(['2fa.passed_at' => now()->timestamp, '2fa.user_id' => $this->ownerA->id]);
    $this->get('/org/center-a/reports')->assertOk();
    $this->get('/org/center-b/reports')->assertNotFound();

    $this->actingAs($this->teacherA, 'org')->withSession(['2fa.passed_at' => now()->timestamp, '2fa.user_id' => $this->teacherA->id]);
    $this->get('/org/center-a/reports')->assertOk();
    $this->get('/org/center-b/reports')->assertNotFound();
});
