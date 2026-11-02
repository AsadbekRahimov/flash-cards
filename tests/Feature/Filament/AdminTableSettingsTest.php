<?php

declare(strict_types=1);

use App\Domain\Administration\Services\PanelDataScope;
use App\Domain\Learning\Enums\SchedulerKind;
use App\Filament\Resources\ExamSessionResource\Pages\ListExamSessions;
use App\Filament\Resources\ExamSessionResource\Pages\ViewExamSession;
use App\Filament\Resources\ExamSessionResource\RelationManagers\AnswersRelationManager;
use App\Filament\Resources\LearnerResource;
use App\Filament\Resources\LearnerResource\Pages\ListLearners;
use App\Filament\Resources\LessonResource\Pages\ListLessons;
use App\Filament\Resources\PaymentOrderResource\Pages\ListPaymentOrders;
use App\Filament\Resources\PaymentResource\Pages\ListPayments;
use App\Filament\Resources\ProviderTransactionResource\Pages\ListProviderTransactions;
use App\Filament\Resources\StageResource;
use App\Filament\Resources\SubscriptionResource\Pages\ListSubscriptions;
use App\Filament\Resources\Support\AdminTable;
use App\Filament\Resources\Support\GroupFilter;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Filament\Resources\WordResource\Pages\ListWords;
use App\Models\Course;
use App\Models\ExamAnswer;
use App\Models\ExamSession;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\PaymentOrder;
use App\Models\ProviderTransaction;
use App\Models\Stage;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\User;
use App\Models\Word;
use Database\Seeders\RolePermissionSeeder;
use Filament\Tables\Enums\PaginationMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\Paginator;
use Livewire\Livewire;
use Tests\Support\QueryLog;

/*
 * The settings of the big tables of /admin (docs/20 §1.4, §1.6, §1.7, §1.9; tasks F3 and F6): the page sizes, the pages without
 * a count of the rows, the search of the identifiers, the columns the lists select, the group filter that does not put every
 * group into the page, the chunks of a bulk action. The query budgets of the lists are in AdminListQueryBudgetTest.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->admin = User::factory()->create([
        'is_active' => true,
        'two_factor_secret' => 'stub',
        'two_factor_recovery_codes' => [],
        'two_factor_confirmed_at' => now(),
    ]);
    $this->admin->assignRole('admin');
    $this->actingAs($this->admin);

    // A list that reads an attribute its query did not select fails here instead of showing an empty cell.
    Model::preventAccessingMissingAttributes();
});

afterEach(function (): void {
    Model::preventAccessingMissingAttributes(false);
});

dataset('big lists', [
    'learners' => [ListLearners::class],
    'words' => [ListWords::class],
    'exam sessions' => [ListExamSessions::class],
    'payments' => [ListPayments::class],
    'payment orders' => [ListPaymentOrders::class],
    'provider transactions' => [ListProviderTransactions::class],
    'subscriptions' => [ListSubscriptions::class],
]);

// --- pages --------------------------------------------------------------------------------------

it('pages a big list without counting its rows, with sizes from 10 to 100 and never "all"', function (string $page): void {
    $table = Livewire::test($page)->instance()->getTable();

    expect($table->getPaginationMode())->toBe(PaginationMode::Simple)
        ->and($table->getPaginationPageOptions())->toBe(AdminTable::PAGE_SIZES)
        ->and($table->getPaginationPageOptions())->not->toContain('all')
        ->and(max($table->getPaginationPageOptions()))->toBeLessThanOrEqual(100)
        ->and($table->enforcesPaginationPageOptions())->toBeTrue()
        ->and($table->getDefaultPaginationPageOption())->toBe(10);
})->with('big lists');

it('does not run a COUNT of the rows when it renders a big list', function (string $page): void {
    Livewire::test($page);

    $statements = QueryLog::during(fn () => Livewire::test($page));

    // The page query of the learners has COUNT subqueries of its own; the count of the paginator is a statement on its own.
    expect(QueryLog::matching($statements, '/^select count\(\*\) as ["`]aggregate["`]/'))->toBe([]);
})->with('big lists');

it('answers a forged page size ("all", a million rows) with the default one', function (string $page): void {
    $component = Livewire::test($page);

    foreach (['all', 1_000_000, 'DROP', 7] as $forged) {
        $component->set('tableRecordsPerPage', $forged);

        expect($component->instance()->getTableRecordsPerPage())->toBe(10);
    }

    $component->set('tableRecordsPerPage', 100);
    expect($component->instance()->getTableRecordsPerPage())->toBe(100);
})->with('big lists');

it('does not let a request ask for every user at once either', function (): void {
    User::factory()->count(12)->create();

    $component = Livewire::test(ListUsers::class)->set('tableRecordsPerPage', 'all');

    expect($component->instance()->getTableRecordsPerPage())->toBe(10)
        ->and($component->instance()->getTableRecords())->toHaveCount(10);
});

it('hands a paginator that knows only whether a next page exists', function (): void {
    Payment::factory()->count(12)->create();

    $records = Livewire::test(ListPayments::class)->instance()->getTableRecords();

    expect($records)->toBeInstanceOf(Paginator::class)
        ->and($records->count())->toBe(10)
        ->and($records->hasMorePages())->toBeTrue();
});

it('opens the learners list with the newest first and sorts it by the first name from the header', function (): void {
    $zoya = Learner::factory()->create(['first_name' => 'Zoya']);
    $adam = Learner::factory()->create(['first_name' => 'Adam']);
    $mira = Learner::factory()->create(['first_name' => 'Mira']);

    // By `id`: the page of a search for a frequent name walks the primary key, not the name index (docs/20 §4).
    Livewire::test(ListLearners::class)
        ->assertCanSeeTableRecords([$mira, $adam, $zoya], inOrder: true)
        ->sortTable('first_name')
        ->assertCanSeeTableRecords([$adam, $mira, $zoya], inOrder: true)
        ->sortTable('first_name', 'desc')
        ->assertCanSeeTableRecords([$zoya, $mira, $adam], inOrder: true);
});

it('does not sort the learners by the count of their referrals, an aggregate over the whole table', function (): void {
    $table = Livewire::test(ListLearners::class)->instance()->getTable();

    // The aggregates of the exams (10 000 rows) stay sortable; the one of the learners (200 000) would sort the table by a subquery.
    expect($table->getColumn('referrals_count')?->isSortable())->toBeFalse()
        ->and($table->getColumn('first_name')?->isSortable())->toBeTrue()
        ->and(Livewire::test(ListExamSessions::class)->instance()->getTable()->getColumn('results_count')?->isSortable())->toBeTrue();
});

// --- search of the identifiers --------------------------------------------------------------------

it('finds a payment by the whole charge id or event id of the provider, never by a part of it', function (): void {
    $exact = Payment::factory()->create(['provider_charge_id' => 'chg_ABC123', 'provider_event_id' => 'evt_X1']);
    $longer = Payment::factory()->create(['provider_charge_id' => 'chg_ABC1234', 'provider_event_id' => 'evt_X12']);

    Livewire::test(ListPayments::class)
        ->searchTable('chg_ABC123')
        ->assertCanSeeTableRecords([$exact])
        ->assertCanNotSeeTableRecords([$longer])
        ->searchTable('evt_X1')
        ->assertCanSeeTableRecords([$exact])
        ->assertCanNotSeeTableRecords([$longer])
        ->searchTable('ABC123')
        ->assertCanNotSeeTableRecords([$exact, $longer])
        ->searchTable('chg_ABC123 chg_ABC1234')
        ->assertCanNotSeeTableRecords([$exact, $longer])
        ->searchTable('')
        ->assertCanSeeTableRecords([$exact, $longer]);
});

it('finds a provider transaction by the whole id of the provider', function (): void {
    $exact = ProviderTransaction::factory()->create(['provider_transaction_id' => 'abcdef012345']);
    $longer = ProviderTransaction::factory()->create(['provider_transaction_id' => 'abcdef0123456789']);

    Livewire::test(ListProviderTransactions::class)
        ->searchTable('abcdef012345')
        ->assertCanSeeTableRecords([$exact])
        ->assertCanNotSeeTableRecords([$longer])
        ->searchTable('abcdef')
        ->assertCanNotSeeTableRecords([$exact, $longer]);
});

it('searches the word in the search box of the table and the translation in its own box', function (): void {
    $lesson = Lesson::factory()->create();
    $apple = Word::factory()->create(['lesson_id' => $lesson->id, 'word' => 'apple', 'translation' => 'яблоко']);
    $pear = Word::factory()->create(['lesson_id' => $lesson->id, 'word' => 'pear', 'translation' => 'груша']);

    Livewire::test(ListWords::class)
        ->searchTable('app')
        ->assertCanSeeTableRecords([$apple])
        ->assertCanNotSeeTableRecords([$pear])
        // The translation is not part of the table's search ...
        ->searchTable('груша')
        ->assertCanNotSeeTableRecords([$apple, $pear])
        // ... it has a box of its own.
        ->searchTable('')
        ->searchTableColumns(['translation' => 'груша'])
        ->assertCanSeeTableRecords([$pear])
        ->assertCanNotSeeTableRecords([$apple]);
});

it('keeps finding a learner by a full name, a username and the whole Telegram id', function (): void {
    $aziz = Learner::factory()->create(['first_name' => 'Aziz', 'last_name' => 'Karimov', 'username' => 'azizk', 'telegram_user_id' => 700_000_001]);
    $other = Learner::factory()->create(['first_name' => 'Aziza', 'last_name' => 'Nazarova', 'username' => 'nazarova', 'telegram_user_id' => 7_000_000_012]);

    Livewire::test(ListLearners::class)
        ->searchTable('Aziz Karimov')
        ->assertCanSeeTableRecords([$aziz])
        ->assertCanNotSeeTableRecords([$other])
        ->searchTable('@azizk')
        ->assertCanSeeTableRecords([$aziz])
        ->assertCanNotSeeTableRecords([$other])
        ->searchTable('700000001')
        ->assertCanSeeTableRecords([$aziz])
        ->assertCanNotSeeTableRecords([$other]);
});

// --- the columns a list selects ---------------------------------------------------------------------

it('selects the columns of the learners list and none of the three counts of the learner page', function (): void {
    $group = TelegramGroup::factory()->create();
    Learner::factory()->memberOf($group)->create();

    $row = Livewire::test(ListLearners::class)->toggleAllTableColumns()->instance()->getTableRecords()->first();

    expect($row->getAttributes())
        ->toHaveKeys(['id', 'first_name', 'telegram_user_id', 'blocked_at', 'practice_pilot_at', 'created_at', 'personal_pro_id', 'sponsor_pro_id', 'referrals_count'])
        ->not->toHaveKeys(['meta', 'repetitions_count', 'training_reviews_count', 'exam_answers_count'])
        ->and($row->groups)->toHaveCount(1);
});

it('keeps the whole row and the counts for the learner page', function (): void {
    $learner = Learner::factory()->create();

    $row = LearnerResource::getEloquentQuery()->whereKey($learner->id)->sole();

    expect($row->getAttributes())->toHaveKeys(['meta', 'repetitions_count', 'training_reviews_count', 'exam_answers_count']);
});

it('does not select the answer key of an exam, the payload of a payment or the meta of a subscription in their lists', function (): void {
    ExamSession::factory()->create(['status' => 'closed']);
    Payment::factory()->create(['raw' => ['secret' => 'x']]);
    PaymentOrder::factory()->create(['meta' => ['paid_after_cancel' => true]]);
    ProviderTransaction::factory()->create(['raw' => ['a' => 1], 'history' => [['b' => 2]]]);
    Subscription::factory()->create(['meta' => ['history' => [['at' => 'now']]]]);
    Word::factory()->create();

    $first = fn (string $page) => Livewire::test($page)->toggleAllTableColumns()->instance()->getTableRecords()->first()->getAttributes();

    expect($first(ListExamSessions::class))->not->toHaveKeys(['config', 'closed_at', 'mode'])->toHaveKeys(['status', 'started_at', 'results_count', 'answers_count'])
        ->and($first(ListPayments::class))->not->toHaveKeys(['raw', 'period_ends_at'])->toHaveKeys(['provider_charge_id', 'provider_event_id', 'amount'])
        // The flags of the order are in its meta: the list reads them.
        ->and($first(ListPaymentOrders::class))->not->toHaveKeys(['token', 'provider_ref'])->toHaveKeys(['meta', 'status'])
        ->and($first(ListProviderTransactions::class))->not->toHaveKeys(['raw', 'history'])->toHaveKeys(['state', 'amount'])
        ->and($first(ListSubscriptions::class))->not->toHaveKeys(['meta', 'provider_ref'])->toHaveKeys(['status', 'current_period_ends_at', 'note'])
        ->and($first(ListWords::class))->not->toHaveKeys(['example', 'meta', 'audio_path'])->toHaveKeys(['word', 'translation', 'is_active', 'translations_count']);
});

it('reads a learner or a center for a name only when a list shows the subscriber', function (): void {
    $learner = Learner::factory()->create(['first_name' => 'Sherzod', 'last_name' => 'Aliev', 'username' => 'sher']);
    Payment::factory()->create(['subscriber_id' => $learner->id]);

    $subscriber = Livewire::test(ListPayments::class)->instance()->getTableRecords()->first()->subscriber;

    expect($subscriber)->toBeInstanceOf(Learner::class)
        ->and($subscriber->getAttributes())->toHaveKeys(['id', 'first_name', 'last_name', 'username'])->not->toHaveKeys(['meta', 'telegram_user_id']);

    Livewire::test(ListPayments::class)->assertSee('Sherzod Aliev @sher #'.$learner->id);
});

it('opens the scheduler switch of a learner on the scheduler the learner has: a row action works on the whole row', function (): void {
    config(['learning.algorithm' => 'sm2']);
    $learner = Learner::factory()->create(['scheduler' => SchedulerKind::Fsrs, 'meta' => ['kept' => true]]);

    $component = Livewire::test(ListLearners::class)->mountTableAction('switchScheduler', $learner);

    // The default of the field is read from the record of the row: the narrow row of the list has no `scheduler` and would offer the
    // default arm of the platform instead of the learner's own.
    $component->assertTableActionDataSet(['scheduler' => SchedulerKind::Fsrs->value]);
    expect($component->instance()->getMountedAction()?->getRecord()?->getAttributes())->toHaveKeys(['scheduler', 'meta']);
});

it('lets the refund of a payment work on the whole row, not on the columns of the list', function (): void {
    $payment = Payment::factory()->create(['raw' => ['note' => 'kept']]);

    $component = Livewire::test(ListPayments::class)->mountTableAction('refund', $payment);

    expect($component->instance()->getMountedAction()?->getRecord()?->getAttributes())->toHaveKey('raw');
});

// --- the group filter ---------------------------------------------------------------------------------

it('keeps the page of a list with a group filter the size it was however many groups there are', function (string $url): void {
    $this->withSession(['2fa.web.passed_at' => now()->timestamp, '2fa.web.user_id' => $this->admin->id]);

    TelegramGroup::factory()->count(5)->create();
    $few = strlen((string) $this->get($url)->assertOk()->getContent());

    TelegramGroup::factory()->count(395)->create();
    $many = strlen((string) $this->get($url)->assertOk()->getContent());

    // 390 more groups used to add about 136 KB (a native <option> each, 240 bytes of markup); the select now holds 50.
    expect($many - $few)->toBeLessThan(15_000);
})->with(['learners' => '/admin/learners', 'exam sessions' => '/admin/exam-sessions']);

it('offers the first groups by title and finds the others by what is typed', function (): void {
    foreach (range(1, 80) as $number) {
        TelegramGroup::factory()->create(['title' => sprintf('Group %03d', $number)]);
    }
    TelegramGroup::factory()->create(['title' => 'Zebra Club']);

    $offered = GroupFilter::search('');

    expect($offered)->toHaveCount(GroupFilter::LIMIT)
        ->and($offered)->not->toContain('Zebra Club')
        ->and(array_values(GroupFilter::search('zebra')))->toBe(['Zebra Club'])
        ->and(GroupFilter::search('no such group'))->toBe([]);
});

it('filters the learners and the exams by a group found by typing and says which one in the indicator', function (): void {
    $far = TelegramGroup::factory()->create(['title' => 'Zebra Club']);
    foreach (range(1, 60) as $number) {
        TelegramGroup::factory()->create(['title' => sprintf('Group %03d', $number)]);
    }
    $member = Learner::factory()->memberOf($far)->create();
    $stranger = Learner::factory()->memberOf(TelegramGroup::query()->where('title', 'Group 001')->sole())->create();
    $exam = ExamSession::factory()->create(['telegram_group_id' => $far->id, 'status' => 'closed']);
    $other = ExamSession::factory()->create(['telegram_group_id' => TelegramGroup::query()->where('title', 'Group 002')->value('id'), 'status' => 'closed']);

    $learners = Livewire::test(ListLearners::class)->filterTable('telegram_group_id', $far->id);
    $learners->assertCanSeeTableRecords([$member])->assertCanNotSeeTableRecords([$stranger]);

    $exams = Livewire::test(ListExamSessions::class)->filterTable('telegram_group_id', $far->id);
    $exams->assertCanSeeTableRecords([$exam])->assertCanNotSeeTableRecords([$other]);

    // Neither of the groups is among the 50 the select offers, and both name the group in the indicator.
    expect(collect($learners->instance()->getTable()->getFilterIndicators())->map(fn ($indicator) => (string) $indicator->getLabel())->all())
        ->toContain('Группа: Zebra Club');
    expect(collect($exams->instance()->getTable()->getFilterIndicators())->map(fn ($indicator) => (string) $indicator->getLabel())->all())
        ->toContain('Группа: Zebra Club');
});

it('offers a teacher the groups of theirs only, also by search and by a forged id', function (): void {
    $own = TelegramGroup::factory()->create(['title' => 'Own Club']);
    $foreign = TelegramGroup::factory()->create(['title' => 'Foreign Club']);
    $teacher = User::factory()->create(['is_active' => true]);
    $teacher->assignRole('teacher');
    $teacher->telegramGroups()->attach($own->id, ['is_primary' => true]);

    $this->actingAs($teacher);

    expect(GroupFilter::search(''))->toBe([$own->id => 'Own Club'])
        ->and(GroupFilter::search('club'))->toBe([$own->id => 'Own Club'])
        ->and(GroupFilter::title($own->id))->toBe('Own Club')
        ->and(GroupFilter::title($foreign->id))->toBeNull()
        ->and(GroupFilter::title('not-an-id'))->toBeNull()
        ->and(GroupFilter::title(null))->toBeNull();
});

it('filters the lessons by a module found by typing and names it in the indicator', function (): void {
    $course = Course::factory()->create();
    foreach (range(1, 60) as $number) {
        Stage::factory()->untitled()->create(['course_id' => $course->id, 'number' => $number]);
    }
    $far = Stage::factory()->create(['course_id' => $course->id, 'number' => 99, 'title' => 'Far Module']);
    $inFar = Lesson::factory()->create(['stage_id' => $far->id]);
    $elsewhere = Lesson::factory()->create(['stage_id' => Stage::query()->where('number', 1)->value('id')]);

    $component = Livewire::test(ListLessons::class)->filterTable('stage_id', $far->id);

    $component->assertCanSeeTableRecords([$inFar])->assertCanNotSeeTableRecords([$elsewhere]);
    expect(collect($component->instance()->getTable()->getFilterIndicators())->map(fn ($indicator) => (string) $indicator->getLabel())->all())
        ->toContain('Модуль: Far Module');

    $offered = StageResource::searchOptions(null, app(PanelDataScope::class)->stages());
    expect($offered)->toHaveCount(50)
        ->and($offered)->not->toHaveKey($far->id)
        ->and(array_values(StageResource::searchOptions('far', app(PanelDataScope::class)->stages())))->toBe(['Far Module']);
});

it('offers a teacher the modules of the content they may read only', function (): void {
    $platform = Stage::factory()->create(['title' => 'Platform Module']);
    $deck = Stage::factory()->create(['title' => 'Private Module', 'course_id' => Course::factory()->deckOf(Learner::factory()->create()->id)]);
    $teacher = User::factory()->create(['is_active' => true]);
    $teacher->assignRole('teacher');
    $this->actingAs($teacher);

    $offered = StageResource::searchOptions(null, app(PanelDataScope::class)->stages());

    expect($offered)->toHaveKey($platform->id)->not->toHaveKey($deck->id);

    $this->actingAs($this->admin);
    expect(StageResource::searchOptions(null, app(PanelDataScope::class)->stages()))->toHaveKeys([$platform->id, $deck->id]);
});

// --- bulk action and the answers --------------------------------------------------------------------------

it('reads the selected users in chunks and asks the policy for each of them', function (): void {
    $bulk = Livewire::test(ListUsers::class)->instance()->getTable()->getFlatBulkActions()['delete'];

    expect($bulk->getSelectedRecordsChunkSize())->toBe(250)
        ->and($bulk->shouldAuthorizeIndividualRecords())->toBeTrue()
        // UserPolicy::deleteAny is false: the action stays closed to everybody, the chunks and the per-user check wait for the day it opens.
        ->and($bulk->isAuthorized())->toBeFalse();
});

it('shows the correct translation of an answer from the key of the exam, once read per request', function (): void {
    $exam = ExamSession::factory()->withQuestions(3)->create();
    $questions = $exam->config['questions'];
    $answer = ExamAnswer::factory()->create(['exam_session_id' => $exam->id, 'word_id' => $questions[1]['word_id']]);
    $unknown = ExamAnswer::factory()->create(['exam_session_id' => $exam->id, 'word_id' => Word::factory()->create()->id]);

    Livewire::test(AnswersRelationManager::class, ['ownerRecord' => $exam, 'pageClass' => ViewExamSession::class])
        ->assertTableColumnStateSet('correct_translation', $questions[1]['correct_translation'], $answer)
        ->assertTableColumnStateSet('correct_translation', '—', $unknown);
});
