<?php

declare(strict_types=1);

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Services\OrgHardWordsReport;
use App\Filament\Org\Pages\HardWords;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\User;
use App\Models\Word;
use App\Models\WordRepetition;
use App\Models\WordTranslation;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\Support\QueryLog;

/*
 * The "hard words" report of /org (docs/06 §12.6): how many learners of the group have failed a word at least
 * `learning.leech.threshold` times, scoped like the activity report: the owner sees the center, the teacher their groups,
 * another center nothing. One grouped statement, whatever the number of words.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['learning.leech.threshold' => 4]);

    $this->a = Organization::factory()->create(['slug' => 'hw-a', 'timezone' => 'UTC', 'locale' => 'ru']);
    $this->b = Organization::factory()->create(['slug' => 'hw-b', 'timezone' => 'UTC', 'locale' => 'ru']);
    Subscription::factory()->forOrganization($this->a)->create(['current_period_ends_at' => null, 'tier' => 'tutor', 'seats_limit' => 30]);

    $this->ownerA = hwUser($this->a, OrganizationRole::Owner);
    $this->teacherA = hwUser($this->a, OrganizationRole::Teacher, twoFactor: false);

    $this->g1 = TelegramGroup::factory()->create(['organization_id' => $this->a->id, 'title' => 'Mine', 'status' => 'active']);
    $this->g2 = TelegramGroup::factory()->create(['organization_id' => $this->a->id, 'title' => 'Not mine', 'status' => 'active']);
    $this->gB = TelegramGroup::factory()->create(['organization_id' => $this->b->id, 'title' => 'Other center', 'status' => 'active']);
    $this->teacherA->telegramGroups()->attach($this->g1->id, ['is_primary' => true]);

    $this->l1 = Learner::factory()->memberOf($this->g1)->seatedIn($this->a)->create();
    $this->l2 = Learner::factory()->memberOf($this->g1)->seatedIn($this->a)->create();
    $this->l3 = Learner::factory()->memberOf($this->g1)->seatedIn($this->a)->create();
    $this->other = Learner::factory()->memberOf($this->g2)->seatedIn($this->a)->create();
    $this->foreign = Learner::factory()->memberOf($this->gB)->seatedIn($this->b)->create();

    $this->mother = Word::factory()->create(['word' => 'mother', 'translation' => 'мать', 'meta' => ['confusable_with' => ['Father', 'mother']]]);
    $this->bread = Word::factory()->create(['word' => 'bread', 'translation' => 'хлеб']);
    $this->rare = Word::factory()->create(['word' => 'rare', 'translation' => 'редкий']);
    WordTranslation::factory()->create(['word_id' => $this->mother->id, 'locale' => 'uz', 'translation' => 'ona']);

    // mother: hard for l1, l2 (+ the learner of the other group and of the other center); bread: l1 only; rare: l3 below the threshold.
    hwLapses($this->l1, $this->mother, 5);
    hwLapses($this->l2, $this->mother, 4);
    hwLapses($this->l3, $this->mother, 3);
    hwLapses($this->l1, $this->bread, 4);
    hwLapses($this->l3, $this->rare, 1);
    hwLapses($this->other, $this->mother, 9);
    hwLapses($this->other, $this->bread, 9);
    hwLapses($this->foreign, $this->rare, 9);
});

function hwUser(Organization $organization, OrganizationRole $role, bool $twoFactor = true): User
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

function hwLapses(Learner $learner, Word $word, int $lapses): void
{
    WordRepetition::factory()->create(['learner_id' => $learner->id, 'word_id' => $word->id, 'lapses' => $lapses]);
}

function hwEnter(User $user, Organization $tenant): void
{
    Filament::setCurrentPanel(Filament::getPanel('org'));
    Filament::bootCurrentPanel();
    test()->actingAs($user, 'org');
    Session::put('2fa.org.passed_at', now()->timestamp);
    Session::put('2fa.org.user_id', $user->id);
    Filament::setTenant($tenant, isQuiet: true);
    app()->setLocale($tenant->locale);
}

it('counts the learners of a group who fail a word at least threshold times', function (): void {
    $report = app(OrgHardWordsReport::class)->forGroup($this->ownerA, $this->a, $this->g1);

    expect($report['class_size'])->toBe(3)
        ->and($report['rows'])->toBe([
            ['word_id' => $this->mother->id, 'word' => 'mother', 'translation' => 'мать', 'learners' => 2, 'confused_with' => 'Father'],
            ['word_id' => $this->bread->id, 'word' => 'bread', 'translation' => 'хлеб', 'learners' => 1, 'confused_with' => null],
        ]);
});

it('counts every group of the center for the owner, a learner once', function (): void {
    $report = app(OrgHardWordsReport::class)->forGroup($this->ownerA, $this->a, null);

    expect($report['class_size'])->toBe(4)
        ->and(array_column($report['rows'], 'learners', 'word'))->toBe(['mother' => 3, 'bread' => 2]);
});

it('narrows a teacher to their own groups, also when another one is asked for', function (): void {
    $report = app(OrgHardWordsReport::class);

    expect(array_column($report->forGroup($this->teacherA, $this->a, null)['rows'], 'learners', 'word'))->toBe(['mother' => 2, 'bread' => 1]);

    $foreignGroup = $report->forGroup($this->teacherA, $this->a, $this->g2);
    expect($foreignGroup)->toBe(['rows' => [], 'class_size' => 0]);
});

it('does not see a group of another center', function (): void {
    $report = app(OrgHardWordsReport::class)->forGroup($this->ownerA, $this->a, $this->gB);

    expect($report)->toBe(['rows' => [], 'class_size' => 0]);
});

it('ignores a learner who left the group and a word below the threshold', function (): void {
    $this->l2->memberships()->update(['status' => 'left', 'left_at' => now()]);

    $report = app(OrgHardWordsReport::class)->forGroup($this->ownerA, $this->a, $this->g1);

    expect($report['class_size'])->toBe(2)
        ->and(array_column($report['rows'], 'learners', 'word'))->toBe(['mother' => 1, 'bread' => 1]);
});

it('narrows the report to the active words of one lesson, keeping the size of the class', function (): void {
    $lesson = Lesson::factory()->create();
    $inLesson = Word::factory()->for($lesson)->create(['word' => 'apple']);
    $inactive = Word::factory()->for($lesson)->create(['word' => 'pear', 'is_active' => false]);
    hwLapses($this->l1, $inLesson, 4);
    hwLapses($this->l1, $inactive, 4);
    hwLapses($this->l2, $inactive, 4);

    $report = app(OrgHardWordsReport::class)->forGroup($this->ownerA, $this->a, $this->g1, $lesson->id);

    expect($report['class_size'])->toBe(3)
        ->and(array_column($report['rows'], 'learners', 'word'))->toBe(['apple' => 1])
        // Without the lesson: every word, the lesson's too.
        ->and(array_column(app(OrgHardWordsReport::class)->forGroup($this->ownerA, $this->a, $this->g1)['rows'], 'learners', 'word'))
        ->toBe(['mother' => 2, 'pear' => 2, 'bread' => 1, 'apple' => 1]);
});

it('gives the material to repeat of a lesson: shared by at least two learners, at most the limit, in one statement', function (): void {
    $lesson = Lesson::factory()->create();
    $words = collect(['one', 'two', 'three', 'four', 'solo'])->map(fn (string $w): Word => Word::factory()->for($lesson)->create(['word' => $w]));
    foreach ([[$this->l1, $this->l2, $this->l3], [$this->l1, $this->l2], [$this->l2, $this->l3], [$this->l1, $this->l3], [$this->l1]] as $i => $learners) {
        foreach ($learners as $learner) {
            hwLapses($learner, $words[$i], 4 + $i);
        }
    }
    $report = app(OrgHardWordsReport::class);
    $report->toRepeat($this->teacherA, $this->a, $this->g1, $lesson->id); // warms the memo of the teacher's groups

    $statements = QueryLog::during(function () use ($report, $lesson, &$rows): void {
        $rows = $report->toRepeat($this->teacherA, $this->a, $this->g1, $lesson->id);
    });

    expect($statements)->toHaveCount(1)
        ->and(array_column($rows, 'learners', 'word'))->toBe(['one' => 3, 'two' => 2, 'three' => 2])
        ->and(array_column($report->toRepeat($this->ownerA, $this->a, $this->g1, $lesson->id, minLearners: 3), 'word'))->toBe(['one'])
        ->and($report->toRepeat($this->ownerA, $this->a, $this->g1, $this->mother->lesson_id))->toHaveCount(1);
});

it('gives no material to repeat outside the scope or with leeches off', function (): void {
    $lessonId = $this->mother->lesson_id;
    $report = app(OrgHardWordsReport::class);

    // mother: 2 learners of g1 -> material of g1; the teacher does not run g2, center B is not center A.
    expect(array_column($report->toRepeat($this->teacherA, $this->a, $this->g1, $lessonId), 'word'))->toBe(['mother'])
        ->and($report->toRepeat($this->teacherA, $this->a, $this->g2, $lessonId))->toBe([])
        ->and($report->toRepeat($this->ownerA, $this->b, $this->gB, $lessonId))->toBe([]);

    config(['learning.leech.threshold' => 0]);
    expect(app(OrgHardWordsReport::class)->toRepeat($this->ownerA, $this->a, $this->g1, $lessonId))->toBe([]);
});

it('is empty when leeches are off', function (): void {
    config(['learning.leech.threshold' => 0]);

    expect(app(OrgHardWordsReport::class)->forGroup($this->ownerA, $this->a, $this->g1))->toBe(['rows' => [], 'class_size' => 0]);
});

it('shows the translation in the language of the center', function (): void {
    $this->a->update(['locale' => 'uz']);

    $rows = app(OrgHardWordsReport::class)->forGroup($this->ownerA, $this->a->refresh(), $this->g1)['rows'];

    expect(array_column($rows, 'translation', 'word'))->toBe(['mother' => 'ona', 'bread' => 'хлеб']);
});

it('takes a fixed number of statements whatever the number of words', function (): void {
    $measure = fn (): int => count(QueryLog::during(fn () => app(OrgHardWordsReport::class)->forGroup($this->teacherA, $this->a, $this->g1)));
    $measure(); // warms the memo of the teacher's groups
    $few = $measure();

    foreach (range(1, 40) as $i) {
        $word = Word::factory()->create();
        hwLapses($this->l1, $word, 5);
        hwLapses($this->l2, $word, 5);
    }

    expect($measure())->toBe($few)->and($few)->toBeLessThanOrEqual(3);
});

it('renders the page for the owner and the teacher with the numbers', function (): void {
    hwEnter($this->ownerA, $this->a);
    Livewire::test(HardWords::class)
        ->assertSee('Трудные слова')
        ->assertSee('mother')
        ->assertSee('3 из 4')
        ->assertSee('Father')
        ->set('data.group', $this->g2->id)
        ->assertSee('1 из 1');

    hwEnter($this->teacherA, $this->a);
    Livewire::test(HardWords::class)
        ->assertSee('mother')
        ->assertSee('2 из 3')->assertDontSee('1 из 1')
        // A group of the center the teacher is not in: an empty report, not "all groups".
        ->set('data.group', $this->g2->id)
        ->assertSee('Трудных слов пока нет.')
        ->assertDontSee('mother');
});
