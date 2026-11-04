<?php

declare(strict_types=1);

use App\Filament\Resources\OrganizationResource\Pages\EditOrganization;
use App\Filament\Resources\OrganizationResource\RelationManagers\GroupsRelationManager;
use App\Filament\Resources\OrganizationResource\RelationManagers\MembersRelationManager;
use App\Filament\Resources\PracticeItemResource\Pages\CreatePracticeItem;
use App\Filament\Resources\StageResource;
use App\Filament\Resources\SubscriptionResource\Pages\ListSubscriptions;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Stage;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\User;
use App\Models\Word;
use Database\Seeders\RolePermissionSeeder;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\QueryLog;

/*
 * The own LIKE searches of /admin (docs/20 §1.7) - the ones written by hand, not Filament's own column search: the picker of a
 * learner of the manual Pro grant and of the members of a center, the member column of a center, the free groups to link, the
 * modules of the lesson form and the target words of a practice task. All of them go through AdminTable::whereContains: `_`, `%`
 * and `!` of the typed text are letters (never wildcards or the escape character), the case of the typed text does not matter,
 * and a search is ONE statement. The names are ASCII: SQLite folds the case of ASCII only (the PostgreSQL run folds the rest).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole('admin');
    $this->actingAs($this->admin);
});

/**
 * What the search of a select field of the mounted action (or of the form of the page) offers for $term, and how many statements
 * it took. The field answers the way the browser asks it: `getSearchResults()`.
 *
 * @return array{0: array<int|string, string>, 1: int}
 */
function als_offered(Testable $component, string $field, string $term): array
{
    $offered = [];
    $statements = 0;

    $component->assertFormFieldExists($field, function (Select $select) use ($term, &$offered, &$statements): bool {
        $statements = count(QueryLog::during(function () use ($select, $term, &$offered): void {
            $offered = $select->getSearchResults($term);
        }));

        return true;
    });

    return [$offered, $statements];
}

function als_center(): Organization
{
    $org = Organization::factory()->create(['name' => 'Search Center']);
    Subscription::factory()->forOrganization($org)->create(['current_period_ends_at' => null, 'tier' => 'tutor', 'seats_limit' => 30]);

    return $org;
}

function als_members(Organization $org): Testable
{
    return Livewire::test(MembersRelationManager::class, ['ownerRecord' => $org, 'pageClass' => EditOrganization::class]);
}

// --- the learners -------------------------------------------------------------------------------------------------

/**
 * The learners every search of a learner is run against: a name with `_`, its lookalike, a name with `%`, a name with `!` and
 * its lookalike, and one learner with a surname and a username in a mixed case. Nobody else has anything in common with them.
 *
 * @return array<string, Learner>
 */
function als_learners(): array
{
    $make = fn (string $first, ?string $last, ?string $username, int $telegram): Learner => Learner::factory()->create([
        'first_name' => $first,
        'last_name' => $last,
        'username' => $username,
        'telegram_user_id' => $telegram,
    ]);

    return [
        'underscore' => $make('Ab_cd', null, null, 880_000_001),
        'lookalike' => $make('Abxcd', null, null, 880_000_002),
        'percent' => $make('Half%', null, null, 880_000_003),
        'bang' => $make('Hey!', null, null, 880_000_004),
        'plain' => $make('Hey', null, null, 880_000_005),
        'mixed' => $make('Olim', 'KARIMOV', 'Nick_Name', 880_000_006),
        'nick lookalike' => $make('Other', null, 'nickxname', 880_000_007),
    ];
}

/**
 * The checks every picker of learners passes: the same terms, the same learners.
 *
 * @param  Closure(string): array{0: array<int|string, string>, 1: int}  $search  the offer for a typed text and its statements
 * @param  array<string, Learner>  $learners
 */
function als_expect_learner_search(Closure $search, array $learners): void
{
    $ids = function (string $term) use ($search): array {
        [$offered, $statements] = $search($term);
        expect($statements)->toBe(1);

        return array_map('intval', array_keys($offered));
    };

    // `_`, `%` and `!` are letters: "ab_cd" is not "Abxcd", "%" finds the percent sign only, "hey!" is not "Hey".
    expect($ids('ab_cd'))->toBe([$learners['underscore']->id])
        ->and($ids('%'))->toBe([$learners['percent']->id])
        ->and($ids('hey!'))->toBe([$learners['bang']->id])
        // The case of the typed text does not matter - in the first name, the surname and the username.
        ->and($ids('ABXCD'))->toBe([$learners['lookalike']->id])
        ->and($ids('olim'))->toBe([$learners['mixed']->id])
        ->and($ids('karimov'))->toBe([$learners['mixed']->id])
        ->and($ids('nick_name'))->toBe([$learners['mixed']->id])
        // "@name" finds the username, `_` in it is a letter too ("nickxname" is somebody else).
        ->and($ids('@NICK_NAME'))->toBe([$learners['mixed']->id])
        ->and($ids('@nickxname'))->toBe([$learners['nick lookalike']->id])
        // Nothing is found by a text nobody has - and a bare wildcard is not "everybody".
        ->and($ids('nobody_here'))->toBe([])
        ->and($ids('%%'))->toBe([]);
}

it('finds the learner to grant Pro to by name and username, whatever the case, with _ % ! as letters', function (): void {
    $learners = als_learners();
    $grant = Livewire::test(ListSubscriptions::class)->mountAction('grantPro');

    als_expect_learner_search(fn (string $term): array => als_offered($grant, 'learner_id', $term), $learners);

    // The Telegram id is still found by the whole number, and by nothing else.
    [$byId] = als_offered($grant, 'learner_id', '880000006');
    [$byPart] = als_offered($grant, 'learner_id', '88000000');
    expect(array_keys($byId))->toBe([$learners['mixed']->id])
        ->and($byPart)->toBe([]);
});

it('finds the learner to add to a center by name and username, whatever the case, with _ % ! as letters', function (): void {
    $learners = als_learners();
    $add = als_members(als_center())->mountTableAction('addMember');

    als_expect_learner_search(fn (string $term): array => als_offered($add, 'learner_id', $term), $learners);

    [$byId] = als_offered($add, 'learner_id', '880000006');
    expect(array_keys($byId))->toBe([$learners['mixed']->id]);
});

it('searches the members of a center by name and username, whatever the case, with _ % ! as letters', function (): void {
    $org = als_center();
    $members = [];
    foreach (als_learners() as $key => $learner) {
        $members[$key] = OrganizationMember::factory()->create(['organization_id' => $org->id, 'learner_id' => $learner->id]);
    }

    // The keys of the members the table shows for the typed text.
    $found = function (string $term) use ($org, $members): array {
        $shown = als_members($org)->searchTable($term)->instance()->getTableRecords()->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return array_keys(array_filter($members, fn (OrganizationMember $member): bool => in_array($member->id, $shown, true)));
    };

    expect($found('ab_cd'))->toBe(['underscore'])
        ->and($found('%'))->toBe(['percent'])
        ->and($found('hey!'))->toBe(['bang'])
        ->and($found('ABXCD'))->toBe(['lookalike'])
        ->and($found('olim'))->toBe(['mixed'])
        ->and($found('@NICK_NAME'))->toBe(['mixed'])
        ->and($found('@nickxname'))->toBe(['nick lookalike'])
        ->and($found('%%'))->toBe([]);
});

// --- the groups to link, the modules, the words -------------------------------------------------------------------

it('finds the free groups to link to a center by a part of the title, whatever the case, with _ % ! as letters', function (): void {
    $make = fn (string $title, ?int $organization = null): int => TelegramGroup::factory()->create(['title' => $title, 'organization_id' => $organization])->id;
    $underscore = $make('Ab_cd club');
    $lookalike = $make('Abxcd club');
    $percent = $make('Half% club');
    $bang = $make('Hey! club');
    // A group of another center is not on offer, whatever is typed.
    $make('Ab_cd taken', Organization::factory()->create()->id);

    $link = Livewire::test(GroupsRelationManager::class, ['ownerRecord' => als_center(), 'pageClass' => EditOrganization::class])->mountTableAction('linkGroup');
    $ids = function (string $term) use ($link): array {
        [$offered, $statements] = als_offered($link, 'telegram_group_id', $term);
        expect($statements)->toBe(1);

        return array_map('intval', array_keys($offered));
    };

    expect($ids('ab_cd'))->toBe([$underscore])
        ->and($ids('ABXCD'))->toBe([$lookalike])
        ->and($ids('%'))->toBe([$percent])
        ->and($ids('hey!'))->toBe([$bang])
        ->and($ids('club'))->toHaveCount(4)
        ->and($ids('%%'))->toBe([]);
});

it('finds the modules of the lesson form by a part of the title, whatever the case, with _ % ! as letters', function (): void {
    $course = Course::factory()->create();
    $make = fn (string $title): int => Stage::factory()->create(['course_id' => $course->id, 'title' => $title])->id;
    $underscore = $make('Ab_cd module');
    $lookalike = $make('Abxcd module');
    $percent = $make('Half% module');
    $bang = $make('Hey! module');

    $ids = function (string $term): array {
        $statements = QueryLog::during(function () use ($term, &$offered): void {
            $offered = StageResource::searchOptions($term);
        });
        expect($statements)->toHaveCount(1);

        return array_keys($offered);
    };

    expect($ids('ab_cd'))->toBe([$underscore])
        ->and($ids('ABXCD'))->toBe([$lookalike])
        ->and($ids('%'))->toBe([$percent])
        ->and($ids('hey!'))->toBe([$bang])
        ->and($ids('module'))->toHaveCount(4)
        ->and($ids('%%'))->toBe([]);
});

it('finds the target words of a task by the word or the translation, whatever the case, with _ % ! as letters', function (): void {
    $course = Course::factory()->create();
    $lesson = Lesson::factory()->for(Stage::factory()->create(['course_id' => $course->id]))->create();
    $make = fn (string $word, string $translation): int => Word::factory()->for($lesson)->create(['word' => $word, 'translation' => $translation])->id;
    $underscore = $make('ab_cd', 'one');
    $lookalike = $make('abxcd', 'two');
    $percent = $make('half', '50% off');
    $bang = $make('Hey!', 'three');
    $upper = $make('HELLO', 'GREETING');

    $form = Livewire::test(CreatePracticeItem::class)->fillForm(['course_id' => $course->id]);
    $ids = function (string $term) use ($form): array {
        [$offered, $statements] = als_offered($form, 'word_ids', $term);
        expect($statements)->toBe(1);

        return array_map('intval', array_keys($offered));
    };

    expect($ids('ab_cd'))->toBe([$underscore])
        ->and($ids('ABXCD'))->toBe([$lookalike])
        ->and($ids('%'))->toBe([$percent])
        ->and($ids('hey!'))->toBe([$bang])
        // The word and its translation, in either case.
        ->and($ids('hello'))->toBe([$upper])
        ->and($ids('greeting'))->toBe([$upper])
        ->and($ids('%%'))->toBe([]);
});
