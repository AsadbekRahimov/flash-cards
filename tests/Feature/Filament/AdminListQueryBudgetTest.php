<?php

declare(strict_types=1);

use App\Domain\Administration\Services\OrganizationAdminService;
use App\Filament\Resources\CourseResource\Pages\EditCourse;
use App\Filament\Resources\CourseResource\Pages\ListCourses;
use App\Filament\Resources\CourseResource\RelationManagers\StagesRelationManager;
use App\Filament\Resources\ExamSessionResource\Pages\ListExamSessions;
use App\Filament\Resources\ExamSessionResource\Pages\ViewExamSession;
use App\Filament\Resources\ExamSessionResource\RelationManagers\AnswersRelationManager;
use App\Filament\Resources\ExamSessionResource\RelationManagers\ResultsRelationManager;
use App\Filament\Resources\LearnerResource\Pages\ListLearners;
use App\Filament\Resources\LearnerResource\Pages\ViewLearner;
use App\Filament\Resources\LearnerResource\RelationManagers\DailyStatsRelationManager;
use App\Filament\Resources\LearnerResource\RelationManagers\OffersRelationManager;
use App\Filament\Resources\LearnerResource\RelationManagers\ProductEventsRelationManager;
use App\Filament\Resources\LearnerResource\RelationManagers\ReferralsRelationManager;
use App\Filament\Resources\LearnerResource\RelationManagers\SubscriptionsRelationManager;
use App\Filament\Resources\LessonResource\Pages\EditLesson;
use App\Filament\Resources\LessonResource\Pages\ListLessons;
use App\Filament\Resources\LessonResource\RelationManagers\WordsRelationManager;
use App\Filament\Resources\OrganizationResource\Pages\EditOrganization;
use App\Filament\Resources\OrganizationResource\Pages\ListOrganizations;
use App\Filament\Resources\OrganizationResource\RelationManagers\GroupsRelationManager;
use App\Filament\Resources\OrganizationResource\RelationManagers\InvitesRelationManager;
use App\Filament\Resources\OrganizationResource\RelationManagers\MembersRelationManager;
use App\Filament\Resources\PaymentOrderResource\Pages\ListPaymentOrders;
use App\Filament\Resources\PaymentResource\Pages\ListPayments;
use App\Filament\Resources\PracticeItemResource\Pages\ListPracticeItems;
use App\Filament\Resources\PromoCodeResource\Pages\ListPromoCodes;
use App\Filament\Resources\ProviderTransactionResource\Pages\ListProviderTransactions;
use App\Filament\Resources\StageResource\Pages\EditStage;
use App\Filament\Resources\StageResource\Pages\ListStages;
use App\Filament\Resources\StageResource\RelationManagers\LessonsRelationManager;
use App\Filament\Resources\SubscriptionResource\Pages\ListSubscriptions;
use App\Filament\Resources\TelegramGroupResource\Pages\ListTelegramGroups;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Filament\Resources\WordResource\Pages\EditWord;
use App\Filament\Resources\WordResource\Pages\ListWords;
use App\Filament\Resources\WordResource\RelationManagers\WordTranslationsRelationManager;
use App\Models\Course;
use App\Models\ExamAnswer;
use App\Models\ExamResult;
use App\Models\ExamSession;
use App\Models\GroupMember;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\Lesson;
use App\Models\Offer;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\OrganizationMember;
use App\Models\Payment;
use App\Models\PaymentOrder;
use App\Models\PracticeItem;
use App\Models\ProductEvent;
use App\Models\PromoCode;
use App\Models\ProviderTransaction;
use App\Models\Referral;
use App\Models\Stage;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\User;
use App\Models\Word;
use App\Models\WordTranslation;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Support\FilamentQueryBudget;
use Tests\Support\QueryLog;

/*
 * Query budget of EVERY list page of /admin and of the tables of its relation managers (docs/20 §1.13, task F4): the number of
 * statements of the first render does not grow with the number of rows (10 -> 50) and stays within a ceiling a few above
 * what the page runs today. Each filler brings the table to the asked number of rows WITH the related rows the columns
 * read (a group, a subscriber, a version...): a budget over bare rows proves nothing. The learners list of an admin and the
 * members of a center (/org) are in ListQueryBudgetTest.
 *
 * Every render is measured twice by the helper (the mount and the change of the page size), so the ceilings below are about
 * double the statements of one render.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
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

/**
 * A relation manager (or any table) whose rows cannot reach 10 and 50: the same check with the sizes it can reach.
 *
 * @param  class-string  $page
 * @param  array<string, mixed>  $parameters
 * @param  array{0: int, 1: int}  $sizes
 */
function alb_budget(string $page, Closure $fill, int $ceiling, array $parameters = [], array $sizes = [FilamentQueryBudget::SMALL, FilamentQueryBudget::LARGE]): void
{
    $queries = FilamentQueryBudget::measure($page, $fill, $parameters, $sizes);
    $small = $queries[$sizes[0]];
    $large = $queries[$sizes[1]];

    expect(count($large))->toBe(
        count($small),
        sprintf("%s runs %d statements for %d rows and %d for %d. Repeated:\n%s", class_basename($page), count($small), $sizes[0], count($large), $sizes[1], implode("\n", FilamentQueryBudget::grown($small, $large))),
    )->and(count($large))->toBeLessThanOrEqual(
        $ceiling,
        sprintf("%s runs %d statements against a budget of %d:\n%s", class_basename($page), count($large), $ceiling, implode("\n", $large)),
    );
}

/** @return list<TelegramGroup> */
function alb_groups(int $count): array
{
    return TelegramGroup::factory()->count($count)->create()->all();
}

// --- the lists of /admin -----------------------------------------------------------------------

it('lists courses with the stages counted by the page query', function (): void {
    FilamentQueryBudget::assert(ListCourses::class, function (int $rows): void {
        $courses = Course::factory()->count($rows - Course::query()->count())->create();
        foreach ($courses as $course) {
            Stage::factory()->create(['course_id' => $course->id]);
        }
    }, ceiling: 6);
});

it('lists stages with the course and the lessons counted by the page query', function (): void {
    FilamentQueryBudget::assert(ListStages::class, function (int $rows): void {
        Stage::factory()->count($rows - Stage::query()->count())->create();
    }, ceiling: 8);
});

it('lists lessons with the stage and the words counted by the page query', function (): void {
    FilamentQueryBudget::assert(ListLessons::class, function (int $rows): void {
        Lesson::factory()->count($rows - Lesson::query()->count())->create();
    }, ceiling: 10);
});

it('lists words with the course path and the translations counted by the page query', function (): void {
    $lesson = Lesson::factory()->create();

    FilamentQueryBudget::assert(ListWords::class, function (int $rows) use ($lesson): void {
        for ($i = Word::query()->count(); $i < $rows; $i++) {
            $word = Word::factory()->create(['lesson_id' => $lesson->id, 'word' => "budget-word-{$i}"]);
            WordTranslation::factory()->create(['word_id' => $word->id]);
        }
    }, ceiling: 10);
});

it('lists exam sessions with the group, the lesson and the counts of results and answers', function (): void {
    $groups = alb_groups(4);
    $lesson = Lesson::factory()->create();
    $starter = User::factory()->create();

    FilamentQueryBudget::assert(ListExamSessions::class, function (int $rows) use ($groups, $lesson, $starter): void {
        for ($i = ExamSession::query()->count(); $i < $rows; $i++) {
            // One open exam per group is allowed: the others are closed.
            $session = ExamSession::factory()->create([
                'telegram_group_id' => $groups[$i % 4]->id,
                'lesson_id' => $lesson->id,
                'started_by_user_id' => $starter->id,
                'status' => 'closed',
                'closed_at' => now(),
            ]);
            ExamResult::factory()->create(['exam_session_id' => $session->id]);
        }
    }, ceiling: 12);
});

it('lists payments with the subscriber of either kind loaded in two statements', function (): void {
    $organization = Organization::factory()->create();

    FilamentQueryBudget::assert(ListPayments::class, function (int $rows) use ($organization): void {
        // Learners and a center: both kinds of the morph relation are read.
        for ($i = Payment::query()->count(); $i < $rows; $i++) {
            Payment::factory()->create($i % 5 === 0
                ? ['subscriber_type' => 'organization', 'subscriber_id' => $organization->id]
                : []);
        }
    }, ceiling: 8);
});

it('lists payment orders with the subscriber of either kind and the flags of the meta', function (): void {
    $organization = Organization::factory()->create();

    FilamentQueryBudget::assert(ListPaymentOrders::class, function (int $rows) use ($organization): void {
        for ($i = PaymentOrder::query()->count(); $i < $rows; $i++) {
            PaymentOrder::factory()->create($i % 5 === 0
                ? ['subscriber_type' => 'organization', 'subscriber_id' => $organization->id, 'meta' => ['tier_mismatch' => true, 'tier_mismatch_live_tier' => 'tutor']]
                : ['meta' => ['paid_after_cancel' => true, 'canceled_reason' => 'offer_redeemed']]);
        }
    }, ceiling: 8);
});

it('lists provider transactions with their order', function (): void {
    FilamentQueryBudget::assert(ListProviderTransactions::class, function (int $rows): void {
        ProviderTransaction::factory()->count($rows - ProviderTransaction::query()->count())->create();
    }, ceiling: 6);
});

it('lists subscriptions with the subscriber of either kind and the admin who granted them', function (): void {
    $organization = Organization::factory()->create();

    FilamentQueryBudget::assert(ListSubscriptions::class, function (int $rows) use ($organization): void {
        for ($i = Subscription::query()->count(); $i < $rows; $i++) {
            // A subscriber has one live subscription at most: the center's others are over.
            Subscription::factory()->create($i % 5 === 0
                ? ['subscriber_type' => 'organization', 'subscriber_id' => $organization->id, 'granted_by_user_id' => $this->admin->id, 'status' => $i === 0 ? 'active' : 'expired']
                : ['granted_by_user_id' => $this->admin->id]);
        }
    }, ceiling: 10);
});

it('lists promo codes with the admin who created them', function (): void {
    FilamentQueryBudget::assert(ListPromoCodes::class, function (int $rows): void {
        PromoCode::factory()->count($rows - PromoCode::query()->count())->create(['created_by_user_id' => $this->admin->id]);
    }, ceiling: 8);
});

it('lists organizations without asking the database about the delete button of every row', function (): void {
    // Every kind of row the policy tells apart: a live subscription (blocked), groups only (blocked), nothing (deletable).
    FilamentQueryBudget::assert(ListOrganizations::class, function (int $rows): void {
        for ($i = Organization::query()->count(); $i < $rows; $i++) {
            $organization = Organization::factory()->create();

            match ($i % 3) {
                0 => Subscription::factory()->forOrganization($organization)->create(),
                1 => TelegramGroup::factory()->create(['organization_id' => $organization->id]),
                default => Subscription::factory()->forOrganization($organization)->expired()->create(),
            };
        }
    }, ceiling: 6);
});

it('answers whether an organization may be deleted from the facts its list row carries, and asks the database without them', function (): void {
    $withSubscription = Organization::factory()->create();
    Subscription::factory()->forOrganization($withSubscription)->create();
    $withGroup = Organization::factory()->create();
    TelegramGroup::factory()->create(['organization_id' => $withGroup->id]);
    $free = Organization::factory()->create();
    Subscription::factory()->forOrganization($free)->expired()->create();

    $rows = app(OrganizationAdminService::class)->withDeletionFacts(Organization::query())->get()->keyBy('id');
    // The roles of the viewer are read once per request, not per row.
    Gate::forUser($this->admin)->allows('viewAny', Organization::class);

    $verdicts = [];
    $statements = QueryLog::during(function () use ($rows, &$verdicts): void {
        foreach ($rows as $id => $row) {
            $verdicts[$id] = Gate::forUser($this->admin)->inspect('delete', $row);
        }
    });

    expect($statements)->toBe([])
        ->and($verdicts[$withSubscription->id]->message())->toContain('live subscription')
        ->and($verdicts[$withGroup->id]->message())->toContain('linked groups')
        ->and($verdicts[$free->id]->allowed())->toBeTrue();

    // The edit page loads the organization without the facts: the policy reads the database and says the same.
    expect(Gate::forUser($this->admin)->inspect('delete', Organization::query()->findOrFail($withSubscription->id))->message())->toContain('live subscription')
        ->and(Gate::forUser($this->admin)->inspect('delete', Organization::query()->findOrFail($withGroup->id))->message())->toContain('linked groups')
        ->and(Gate::forUser($this->admin)->inspect('delete', Organization::query()->findOrFail($free->id))->allowed())->toBeTrue();
});

it('lists telegram groups with the active members counted by the page query', function (): void {
    FilamentQueryBudget::assert(ListTelegramGroups::class, function (int $rows): void {
        $groups = TelegramGroup::factory()->count($rows - TelegramGroup::query()->count())->create();
        foreach ($groups as $group) {
            GroupMember::factory()->create(['telegram_group_id' => $group->id, 'learner_id' => Learner::factory()]);
        }
    }, ceiling: 6);
});

it('lists staff users with their roles loaded in one statement', function (): void {
    FilamentQueryBudget::assert(ListUsers::class, function (int $rows): void {
        $users = User::factory()->count($rows - User::query()->count())->create();
        foreach ($users as $user) {
            $user->assignRole('teacher');
        }
    }, ceiling: 10);
});

it('lists practice items with the course, the lesson and both versions loaded by the page query', function (): void {
    $course = Course::factory()->published()->create();

    FilamentQueryBudget::assert(ListPracticeItems::class, function (int $rows) use ($course): void {
        for ($i = PracticeItem::query()->count(); $i < $rows; $i++) {
            // A dictation with ready audio: the audio status column reads both versions.
            PracticeItem::factory()->dictation()->published()->audioReady()->forCourse($course)->create();
        }
    }, ceiling: 14);
});

// --- the lists a teacher opens ----------------------------------------------------------------------------

/**
 * A teacher of $group, signed in.
 */
function alb_teacher(TelegramGroup $group): User
{
    $teacher = User::factory()->create(['is_active' => true]);
    $teacher->assignRole('teacher');
    $teacher->telegramGroups()->attach($group->id, ['is_primary' => true]);
    test()->actingAs($teacher);

    return $teacher;
}

it('lists the learners of a teacher, whose row policy still asks the database about every row', function (): void {
    $group = TelegramGroup::factory()->create();
    alb_teacher($group);

    // KNOWN N+1 (docs/20 A11): LearnerPolicy::view re-asks "is the viewer a teacher of an active group of this learner" for the
    // link, the view button and the record action of every row - 6 statements per row, though the list is already limited to
    // exactly these learners (PanelDataScope). Fixing it touches the access rules: it waits for the decision recorded in
    // docs/20 §4, and this test pins today's cost so it cannot get worse.
    FilamentQueryBudget::assert(ListLearners::class, function (int $rows) use ($group): void {
        Learner::factory()->count($rows - Learner::query()->count())->memberOf($group)->create();
    }, ceiling: 308, knownPerRow: 6);
});

it('lists the exams of a teacher, whose row policy still asks the database about every row', function (): void {
    $group = TelegramGroup::factory()->create();
    alb_teacher($group);
    $lesson = Lesson::factory()->create();

    // KNOWN N+1 (docs/20 A11), the same as above: ExamSessionPolicy::view asks whether the viewer teaches the group of the
    // exam, 6 statements per row.
    FilamentQueryBudget::assert(ListExamSessions::class, function (int $rows) use ($group, $lesson): void {
        for ($i = ExamSession::query()->count(); $i < $rows; $i++) {
            ExamSession::factory()->create(['telegram_group_id' => $group->id, 'lesson_id' => $lesson->id, 'status' => 'closed', 'closed_at' => now()]);
        }
    }, ceiling: 312, knownPerRow: 6);
});

it('lists the groups of a teacher with the members counted by the page query', function (): void {
    $teacher = alb_teacher(TelegramGroup::factory()->create());

    FilamentQueryBudget::assert(ListTelegramGroups::class, function (int $rows) use ($teacher): void {
        for ($i = TelegramGroup::query()->count(); $i < $rows; $i++) {
            $teacher->telegramGroups()->attach(TelegramGroup::factory()->create()->id, ['is_primary' => false]);
        }
    }, ceiling: 6);
});

it('lists the words of a teacher in a number of statements that does not depend on the rows', function (): void {
    alb_teacher(TelegramGroup::factory()->create());
    $lesson = Lesson::factory()->create();

    FilamentQueryBudget::assert(ListWords::class, function (int $rows) use ($lesson): void {
        for ($i = Word::query()->count(); $i < $rows; $i++) {
            Word::factory()->create(['lesson_id' => $lesson->id, 'word' => "teacher-word-{$i}"]);
        }
    }, ceiling: 10);
});

it('lists the lessons of a teacher in a number of statements that does not depend on the rows', function (): void {
    alb_teacher(TelegramGroup::factory()->create());

    FilamentQueryBudget::assert(ListLessons::class, function (int $rows): void {
        Lesson::factory()->count($rows - Lesson::query()->count())->create();
    }, ceiling: 10);
});

it('lists the modules of a teacher in a number of statements that does not depend on the rows', function (): void {
    alb_teacher(TelegramGroup::factory()->create());

    FilamentQueryBudget::assert(ListStages::class, function (int $rows): void {
        Stage::factory()->count($rows - Stage::query()->count())->create();
    }, ceiling: 8);
});

// --- the tables of the relation managers -------------------------------------------------------------

it('lists the stages of a course', function (): void {
    $course = Course::factory()->create();

    FilamentQueryBudget::assert(StagesRelationManager::class, function (int $rows) use ($course): void {
        Stage::factory()->count($rows - Stage::query()->where('course_id', $course->id)->count())->create(['course_id' => $course->id]);
    }, ceiling: 7, parameters: ['ownerRecord' => $course, 'pageClass' => EditCourse::class]);
});

it('lists the lessons of a stage', function (): void {
    $stage = Stage::factory()->create();

    FilamentQueryBudget::assert(LessonsRelationManager::class, function (int $rows) use ($stage): void {
        Lesson::factory()->count($rows - Lesson::query()->where('stage_id', $stage->id)->count())->create(['stage_id' => $stage->id]);
    }, ceiling: 7, parameters: ['ownerRecord' => $stage, 'pageClass' => EditStage::class]);
});

it('lists the words of a lesson', function (): void {
    $lesson = Lesson::factory()->create();

    FilamentQueryBudget::assert(WordsRelationManager::class, function (int $rows) use ($lesson): void {
        for ($i = Word::query()->where('lesson_id', $lesson->id)->count(); $i < $rows; $i++) {
            Word::factory()->create(['lesson_id' => $lesson->id, 'word' => "lesson-word-{$i}"]);
        }
    }, ceiling: 7, parameters: ['ownerRecord' => $lesson, 'pageClass' => EditLesson::class]);
});

it('lists the translations of a word', function (): void {
    $word = Word::factory()->create();

    // A word has one translation per language, and the panel knows two: the table cannot reach 10 rows.
    alb_budget(WordTranslationsRelationManager::class, function (int $rows) use ($word): void {
        foreach (array_slice(['ru', 'uz'], 0, $rows) as $locale) {
            if (! WordTranslation::query()->where('word_id', $word->id)->where('locale', $locale)->exists()) {
                WordTranslation::factory()->create(['word_id' => $word->id, 'locale' => $locale]);
            }
        }
    }, ceiling: 7, parameters: ['ownerRecord' => $word, 'pageClass' => EditWord::class], sizes: [1, 2]);
});

it('lists the answers of an exam with the learner and the word loaded in one statement each', function (): void {
    $exam = ExamSession::factory()->withQuestions(4)->create();
    $word = Word::factory()->create();

    FilamentQueryBudget::assert(AnswersRelationManager::class, function (int $rows) use ($exam, $word): void {
        ExamAnswer::factory()->count($rows - ExamAnswer::query()->where('exam_session_id', $exam->id)->count())->create(['exam_session_id' => $exam->id, 'word_id' => $word->id]);
    }, ceiling: 11, parameters: ['ownerRecord' => $exam, 'pageClass' => ViewExamSession::class]);
});

it('lists the results of an exam with the learner and the time spent', function (): void {
    $exam = ExamSession::factory()->create();

    FilamentQueryBudget::assert(ResultsRelationManager::class, function (int $rows) use ($exam): void {
        for ($i = ExamResult::query()->where('exam_session_id', $exam->id)->count(); $i < $rows; $i++) {
            ExamResult::factory()->create(['exam_session_id' => $exam->id, 'rank' => $i + 1]);
        }
    }, ceiling: 9, parameters: ['ownerRecord' => $exam, 'pageClass' => ViewExamSession::class]);
});

it('lists the subscriptions of a learner', function (): void {
    $learner = Learner::factory()->create();

    FilamentQueryBudget::assert(SubscriptionsRelationManager::class, function (int $rows) use ($learner): void {
        // A learner has one live subscription at most: the rest is history.
        Subscription::factory()->expired()->count($rows - Subscription::query()->where('subscriber_id', $learner->id)->count())->create(['subscriber_id' => $learner->id]);
    }, ceiling: 5, parameters: ['ownerRecord' => $learner, 'pageClass' => ViewLearner::class]);
});

it('lists the offers of a learner with the center that made them', function (): void {
    $learner = Learner::factory()->create();
    $center = Organization::factory()->create();

    // One offer per kind and learner, and there are three kinds.
    alb_budget(OffersRelationManager::class, function (int $rows) use ($learner, $center): void {
        $kinds = [Offer::KIND_POST_ORG_DISCOUNT, Offer::KIND_WINBACK, Offer::KIND_CAMPAIGN];
        for ($i = Offer::query()->where('learner_id', $learner->id)->count(); $i < $rows; $i++) {
            Offer::factory()->expired()->create(['learner_id' => $learner->id, 'kind' => $kinds[$i], 'source_organization_id' => $center->id]);
        }
    }, ceiling: 7, parameters: ['ownerRecord' => $learner, 'pageClass' => ViewLearner::class], sizes: [1, 3]);
});

it('lists the referrals of a learner with the invited learner loaded in one statement', function (): void {
    $learner = Learner::factory()->create();

    FilamentQueryBudget::assert(ReferralsRelationManager::class, function (int $rows) use ($learner): void {
        Referral::factory()->count($rows - Referral::query()->where('referrer_learner_id', $learner->id)->count())->create(['referrer_learner_id' => $learner->id]);
    }, ceiling: 9, parameters: ['ownerRecord' => $learner, 'pageClass' => ViewLearner::class]);
});

it('lists the last product events of a learner', function (): void {
    $learner = Learner::factory()->create();

    FilamentQueryBudget::assert(ProductEventsRelationManager::class, function (int $rows) use ($learner): void {
        for ($i = ProductEvent::query()->where('learner_id', $learner->id)->count(); $i < $rows; $i++) {
            ProductEvent::query()->create(['name' => 'course.selected', 'learner_id' => $learner->id, 'props' => ['position' => $i], 'occurred_at' => now()->subMinutes($i)]);
        }
    }, ceiling: 5, parameters: ['ownerRecord' => $learner, 'pageClass' => ViewLearner::class]);
});

it('lists the daily statistics of a learner for the last 14 days', function (): void {
    $learner = Learner::factory()->create();

    // The table shows a window of 14 days: it cannot reach 50 rows.
    alb_budget(DailyStatsRelationManager::class, function (int $rows) use ($learner): void {
        for ($i = LearnerDailyStats::query()->where('learner_id', $learner->id)->count(); $i < $rows; $i++) {
            LearnerDailyStats::factory()->create(['learner_id' => $learner->id, 'date' => now()->subDays($i)->toDateString(), 'reviews_count' => 5]);
        }
    }, ceiling: 5, parameters: ['ownerRecord' => $learner, 'pageClass' => ViewLearner::class], sizes: [4, 14]);
});

it('lists the groups of an organization with the active members counted by the page query', function (): void {
    $organization = Organization::factory()->create();

    FilamentQueryBudget::assert(GroupsRelationManager::class, function (int $rows) use ($organization): void {
        $groups = TelegramGroup::factory()->count($rows - TelegramGroup::query()->where('organization_id', $organization->id)->count())->create(['organization_id' => $organization->id]);
        foreach ($groups as $group) {
            GroupMember::factory()->create(['telegram_group_id' => $group->id, 'learner_id' => Learner::factory()]);
        }
    }, ceiling: 7, parameters: ['ownerRecord' => $organization, 'pageClass' => EditOrganization::class]);
});

it('lists the invite links of an organization with the group they lead to', function (): void {
    $organization = Organization::factory()->create();
    $group = TelegramGroup::factory()->create(['organization_id' => $organization->id]);

    FilamentQueryBudget::assert(InvitesRelationManager::class, function (int $rows) use ($organization, $group): void {
        OrganizationInvite::factory()->count($rows - OrganizationInvite::query()->where('organization_id', $organization->id)->count())->create(['organization_id' => $organization->id, 'telegram_group_id' => $group->id]);
    }, ceiling: 9, parameters: ['ownerRecord' => $organization, 'pageClass' => EditOrganization::class]);
});

it('lists the members of an organization with the learner loaded in one statement', function (): void {
    $organization = Organization::factory()->create();

    FilamentQueryBudget::assert(MembersRelationManager::class, function (int $rows) use ($organization): void {
        OrganizationMember::factory()->count($rows - OrganizationMember::query()->where('organization_id', $organization->id)->count())->create(['organization_id' => $organization->id]);
    }, ceiling: 9, parameters: ['ownerRecord' => $organization, 'pageClass' => EditOrganization::class]);
});
