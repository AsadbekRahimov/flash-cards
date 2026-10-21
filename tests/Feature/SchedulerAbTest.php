<?php

declare(strict_types=1);

use App\Domain\Learners\Services\LearnerRegistrar;
use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Enums\SchedulerKind;
use App\Domain\Learning\Services\ReviewSubmissionService;
use App\Domain\Learning\Services\SchedulerMetricsService;
use App\Domain\Learning\Services\SchedulerSwitchService;
use App\Domain\Learning\Support\FsrsParameters;
use App\Filament\Resources\LearnerResource\Pages\ListLearners;
use App\Filament\Widgets\SchedulerComparisonWidget;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\TelegramGroup;
use App\Models\TrainingReview;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\Word;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

/*
 * FSRS as an A/B (docs/13 §8.3, docs/06 §9): the bucket is fixed at creation, the review path goes
 * through SchedulerResolver and records the arm, an admin can switch one learner (audited, seeded),
 * and the dashboard compares the arms by `training_reviews.scheduler`.
 */

function abRollout(int $percent): void
{
    config(['learning.fsrs.rollout_percent' => $percent]);
    app()->forgetInstance(FsrsParameters::class);
}

function abAdmin(): User
{
    $user = User::factory()->create([
        'is_active' => true,
        'two_factor_secret' => 'stub',
        'two_factor_recovery_codes' => [],
        'two_factor_confirmed_at' => now(),
    ]);
    $user->assignRole('admin');

    return $user;
}

/**
 * A learner with a due review card (SM-2 state: interval 10, EF 2.5, last answered 10 days ago) in a personal lesson session.
 *
 * @return array{learner: Learner, session: TrainingSession, word: Word, rep: WordRepetition}
 */
function abDueCard(?SchedulerKind $bucket, CarbonImmutable $now): array
{
    $learner = Learner::factory()->create(['scheduler' => $bucket]);
    $course = Course::factory()->create(['status' => 'published', 'visibility' => 'public']);
    $stage = Stage::factory()->create(['course_id' => $course->id]);
    $lesson = Lesson::factory()->for($stage)->create();
    $word = Word::factory()->for($lesson)->create();
    $rep = WordRepetition::factory()->create([
        'learner_id' => $learner->id,
        'word_id' => $word->id,
        'state' => RepetitionState::Review->value,
        'repetitions' => 3,
        'interval_days' => 10,
        'easiness_factor' => 2.5,
        'last_reviewed_at' => $now->subDays(10),
        'next_review_at' => $now->subHour(),
        'introduced_mode' => 'lesson',
        'stability' => null,
        'difficulty' => null,
    ]);
    $session = TrainingSession::factory()->personal($learner, 'lesson')->create(['lesson_id' => $lesson->id]);

    return compact('learner', 'session', 'word', 'rep');
}

// --- bucket at creation ------------------------------------------------------------

it('fixes the bucket when the learner is created: 0% → sm2, 100% → fsrs, and never moves it later', function (): void {
    abRollout(0);
    $registrar = app(LearnerRegistrar::class);

    $a = $registrar->fromTelegramUser(['id' => 1001, 'first_name' => 'A']);
    expect($a->scheduler)->toBe(SchedulerKind::Sm2)
        ->and($a->fresh()?->scheduler)->toBe(SchedulerKind::Sm2);

    abRollout(100);
    $registrar = app(LearnerRegistrar::class);
    $b = $registrar->fromTelegramUser(['id' => 1002, 'first_name' => 'B']);
    expect($b->scheduler)->toBe(SchedulerKind::Fsrs);

    // A later contact of an existing learner, at any percentage, keeps the bucket.
    $aAgain = $registrar->fromTelegramUser(['id' => 1001, 'first_name' => 'A']);
    expect($aAgain->id)->toBe($a->id)
        ->and($aAgain->scheduler)->toBe(SchedulerKind::Sm2);

    abRollout(0);
    $bAgain = app(LearnerRegistrar::class)->observeTelegramUser(['id' => 1002, 'first_name' => 'B']);
    expect($bAgain?->scheduler)->toBe(SchedulerKind::Fsrs);
});

it('gives the same Telegram id the same bucket on every creation at a partial rollout', function (): void {
    abRollout(50);

    $ids = [1, 7, 42, 123456789, 987654321012];
    foreach ($ids as $id) {
        $expected = crc32((string) $id) % 100 < 50 ? SchedulerKind::Fsrs : SchedulerKind::Sm2;
        $learner = app(LearnerRegistrar::class)->fromTelegramUser(['id' => $id]);
        expect($learner->scheduler)->toBe($expected);

        $learner->forceDelete();
        $recreated = app(LearnerRegistrar::class)->fromTelegramUser(['id' => $id]);
        expect($recreated->scheduler)->toBe($expected);
    }
});

// --- review path -------------------------------------------------------------------

it('schedules an SM-2 learner exactly as before and records sm2 on the grade', function (): void {
    $now = CarbonImmutable::parse('2026-10-10 12:00:00');
    ['learner' => $learner, 'session' => $session, 'word' => $word] = abDueCard(SchedulerKind::Sm2, $now);

    $rep = app(ReviewSubmissionService::class)->submit($session, $learner, $word->id, 4, 1500, null, $now);

    // Classic SM-2: repetitions 3 → interval round(10 · 2.5) = 25, EF unchanged on Good.
    expect($rep->interval_days)->toBe(25)
        ->and($rep->repetitions)->toBe(4)
        ->and((float) $rep->easiness_factor)->toBe(2.5)
        ->and($rep->stability)->toBeNull()
        ->and($rep->difficulty)->toBeNull()
        ->and($rep->scheduler)->toBe(SchedulerKind::Sm2)
        ->and($rep->next_review_at->equalTo($now->addDays(25)))->toBeTrue();

    $review = TrainingReview::query()->sole();
    expect($review->scheduler)->toBe('sm2')
        ->and($review->interval_before_days)->toBe(10);
});

it('schedules an FSRS learner with FSRS, seeds the memory from SM-2 and records fsrs on the grade', function (): void {
    $now = CarbonImmutable::parse('2026-10-10 12:00:00');
    ['learner' => $learner, 'session' => $session, 'word' => $word] = abDueCard(SchedulerKind::Fsrs, $now);

    $rep = app(ReviewSubmissionService::class)->submit($session, $learner, $word->id, 4, 1500, null, $now);

    // Reference B of FsrsSchedulerTest: S = 10, D = 5, R = 0.9 → S' = 32.954264, I = 33; EF untouched.
    expect($rep->interval_days)->toBe(33)
        ->and($rep->repetitions)->toBe(4)
        ->and($rep->stability)->toEqualWithDelta(32.954264, 1e-4)
        ->and($rep->difficulty)->toEqualWithDelta(4.991833, 1e-4)
        ->and((float) $rep->easiness_factor)->toBe(2.5)
        ->and($rep->scheduler)->toBe(SchedulerKind::Fsrs)
        ->and($rep->next_review_at->equalTo($now->addDays(33)))->toBeTrue();

    expect(TrainingReview::query()->sole()->scheduler)->toBe('fsrs');
});

it('uses the config default for a learner without a bucket', function (): void {
    $now = CarbonImmutable::parse('2026-10-10 12:00:00');
    ['learner' => $learner, 'session' => $session, 'word' => $word] = abDueCard(null, $now);

    config(['learning.algorithm' => 'fsrs']);
    $rep = app(ReviewSubmissionService::class)->submit($session, $learner, $word->id, 4, 1500, null, $now);

    expect($rep->scheduler)->toBe(SchedulerKind::Fsrs)
        ->and($rep->interval_days)->toBe(33)
        ->and(TrainingReview::query()->sole()->scheduler)->toBe('fsrs');
});

// --- admin switch ------------------------------------------------------------------

it('switches a learner to FSRS, seeds graduated cards without memory and writes one audit record', function (): void {
    $actor = User::factory()->create();
    $learner = Learner::factory()->create(['scheduler' => SchedulerKind::Sm2]);
    $lesson = Lesson::factory()->for(Stage::factory()->create())->create();

    $mk = fn (array $attrs): WordRepetition => WordRepetition::factory()->create(array_merge([
        'learner_id' => $learner->id,
        'word_id' => Word::factory()->for($lesson)->create()->id,
        'stability' => null,
        'difficulty' => null,
    ], $attrs));

    $review = $mk(['state' => RepetitionState::Review->value, 'repetitions' => 3, 'interval_days' => 12, 'easiness_factor' => 2.0]);
    $fresh = $mk(['state' => RepetitionState::Review->value, 'repetitions' => 1, 'interval_days' => 0, 'easiness_factor' => 1.3]);
    $learning = $mk(['state' => RepetitionState::Learning->value, 'repetitions' => 0, 'interval_days' => 0]);
    $relearning = $mk(['state' => RepetitionState::Relearning->value, 'repetitions' => 0, 'interval_days' => 0]);
    $seeded = $mk(['state' => RepetitionState::Review->value, 'repetitions' => 2, 'interval_days' => 7, 'stability' => 42.5, 'difficulty' => 3.25]);
    $foreign = WordRepetition::factory()->create(['state' => RepetitionState::Review->value, 'repetitions' => 2, 'interval_days' => 9, 'stability' => null, 'difficulty' => null]);

    $changed = app(SchedulerSwitchService::class)->switchTo($learner, SchedulerKind::Fsrs, $actor, '203.0.113.7');

    expect($changed)->toBeTrue()
        ->and($learner->scheduler)->toBe(SchedulerKind::Fsrs)
        ->and($learner->fresh()?->scheduler)->toBe(SchedulerKind::Fsrs)
        // max(1, 12) = 12; 5 + (2.5 − 2.0) · 4/1.2 = 6.6667
        ->and($review->fresh()?->stability)->toEqualWithDelta(12.0, 1e-4)
        ->and($review->fresh()?->difficulty)->toEqualWithDelta(6.6667, 1e-4)
        // max(1, 0) = 1; EF 1.3 → 9
        ->and($fresh->fresh()?->stability)->toEqualWithDelta(1.0, 1e-4)
        ->and($fresh->fresh()?->difficulty)->toEqualWithDelta(9.0, 1e-4)
        // minute-step cards get their memory when they graduate
        ->and($learning->fresh()?->stability)->toBeNull()
        ->and($relearning->fresh()?->stability)->toBeNull()
        // an earlier FSRS memory is kept
        ->and($seeded->fresh()?->stability)->toEqualWithDelta(42.5, 1e-4)
        ->and($seeded->fresh()?->difficulty)->toEqualWithDelta(3.25, 1e-4)
        // other learners untouched
        ->and($foreign->fresh()?->stability)->toBeNull();

    $audit = AuditLog::query()->where('action', SchedulerSwitchService::ACTION_CHANGED)->sole();
    expect($audit->user_id)->toBe($actor->id)
        ->and($audit->entity_type)->toBe(Learner::class)
        ->and($audit->entity_id)->toBe($learner->id)
        ->and($audit->ip)->toBe('203.0.113.7')
        ->and($audit->meta)->toEqual(['from' => 'sm2', 'to' => 'fsrs', 'initialized_cards' => 2]);
});

it('is idempotent and switching back to SM-2 touches no card', function (): void {
    $actor = User::factory()->create();
    $learner = Learner::factory()->create(['scheduler' => null]);
    config(['learning.algorithm' => 'sm2']);

    $service = app(SchedulerSwitchService::class);

    // Already on the default arm: nothing to do, no audit.
    expect($service->switchTo($learner, SchedulerKind::Sm2, $actor))->toBeFalse()
        ->and(AuditLog::query()->count())->toBe(0)
        ->and($learner->fresh()?->scheduler)->toBeNull();

    expect($service->switchTo($learner, SchedulerKind::Fsrs, $actor))->toBeTrue()
        ->and($service->switchTo($learner, SchedulerKind::Fsrs, $actor))->toBeFalse();

    $card = WordRepetition::factory()->create([
        'learner_id' => $learner->id,
        'state' => RepetitionState::Review->value,
        'repetitions' => 2,
        'interval_days' => 7,
        'easiness_factor' => 2.36,
        'stability' => 9.5,
        'difficulty' => 4.0,
        'scheduler' => SchedulerKind::Fsrs->value,
    ]);

    expect($service->switchTo($learner, SchedulerKind::Sm2, $actor))->toBeTrue()
        ->and($learner->fresh()?->scheduler)->toBe(SchedulerKind::Sm2)
        ->and($card->fresh()?->stability)->toEqualWithDelta(9.5, 1e-4)
        ->and($card->fresh()?->interval_days)->toBe(7)
        ->and((float) $card->fresh()?->easiness_factor)->toBe(2.36)
        ->and(AuditLog::query()->where('action', SchedulerSwitchService::ACTION_CHANGED)->count())->toBe(2);

    $back = AuditLog::query()->where('action', SchedulerSwitchService::ACTION_CHANGED)->orderByDesc('id')->first();
    expect($back?->meta)->toEqual(['from' => 'fsrs', 'to' => 'sm2', 'initialized_cards' => 0]);
});

// --- Filament -----------------------------------------------------------------------

it('lets an admin switch the scheduler from the learners table, with an audit record', function (): void {
    $learner = Learner::factory()->create(['scheduler' => SchedulerKind::Sm2]);
    WordRepetition::factory()->create(['learner_id' => $learner->id, 'state' => RepetitionState::Review->value, 'repetitions' => 2, 'interval_days' => 4, 'stability' => null, 'difficulty' => null]);

    $this->actingAs(abAdmin());
    Livewire::test(ListLearners::class)
        ->callTableAction('switchScheduler', $learner, data: ['scheduler' => 'fsrs'])
        ->assertHasNoTableActionErrors();

    expect($learner->fresh()?->scheduler)->toBe(SchedulerKind::Fsrs)
        ->and(WordRepetition::query()->where('learner_id', $learner->id)->whereNotNull('stability')->count())->toBe(1);

    $audit = AuditLog::query()->where('action', SchedulerSwitchService::ACTION_CHANGED)->sole();
    expect($audit->entity_id)->toBe($learner->id)
        ->and($audit->meta['to'] ?? null)->toBe('fsrs');

    // An unknown value is refused by the select; nothing changes.
    Livewire::test(ListLearners::class)
        ->callTableAction('switchScheduler', $learner->fresh(), data: ['scheduler' => 'bogus'])
        ->assertHasTableActionErrors(['scheduler']);
    expect(AuditLog::query()->count())->toBe(1);
});

it('hides the scheduler switch from teachers and refuses it when called directly', function (): void {
    $group = TelegramGroup::factory()->create();
    $teacher = User::factory()->create(['is_active' => true]);
    $teacher->assignRole('teacher');
    $teacher->telegramGroups()->attach($group->id, ['is_primary' => true]);
    $learner = Learner::factory()->memberOf($group)->create(['scheduler' => SchedulerKind::Sm2]);

    expect($teacher->can('update', $learner))->toBeFalse();

    $this->actingAs($teacher);
    Livewire::test(ListLearners::class)
        ->assertTableActionHidden('switchScheduler', $learner);

    // A forged Livewire call that bypasses the hidden button.
    Livewire::test(ListLearners::class)
        ->call('mountTableAction', 'switchScheduler', $learner->getKey())
        ->set('mountedActions.0.data.scheduler', 'fsrs')
        ->call('callMountedTableAction');

    expect($learner->fresh()?->scheduler)->toBe(SchedulerKind::Sm2)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('shows the comparison widget to an active admin only', function (): void {
    $this->actingAs(abAdmin());
    expect(SchedulerComparisonWidget::canView())->toBeTrue();
    Livewire::test(SchedulerComparisonWidget::class)->assertOk()->assertSee('SM-2 vs FSRS');

    $teacher = User::factory()->create(['is_active' => true]);
    $teacher->assignRole('teacher');
    $this->actingAs($teacher);
    expect(SchedulerComparisonWidget::canView())->toBeFalse();
    Livewire::test(SchedulerComparisonWidget::class)->assertForbidden();
});

// --- metrics -------------------------------------------------------------------------

it('compares the arms by training_reviews.scheduler over 28 days', function (): void {
    Cache::flush();
    $now = CarbonImmutable::parse('2026-10-20 12:00:00');
    config(['learning.algorithm' => 'sm2']);

    $lesson = Lesson::factory()->for(Stage::factory()->create())->create();
    $session = TrainingSession::factory()->create();

    $sm2Old = Learner::factory()->create(['scheduler' => SchedulerKind::Sm2, 'created_at' => $now->subDays(20)]);
    $sm2Default = Learner::factory()->create(['scheduler' => null, 'created_at' => $now->subDays(15)]);
    $fsrsRetained = Learner::factory()->create(['scheduler' => SchedulerKind::Fsrs, 'created_at' => $now->subDays(10)]);
    $fsrsLost = Learner::factory()->create(['scheduler' => SchedulerKind::Fsrs, 'created_at' => $now->subDays(9)]);
    $fsrsTooYoung = Learner::factory()->create(['scheduler' => SchedulerKind::Fsrs, 'created_at' => $now->subDays(3)]);
    $fsrsTooOld = Learner::factory()->create(['scheduler' => SchedulerKind::Fsrs, 'created_at' => $now->subDays(40)]);

    $review = function (Learner $learner, string $scheduler, int $quality, ?int $before, CarbonImmutable $at) use ($lesson, $session): void {
        TrainingReview::factory()->create([
            'training_session_id' => $session->id,
            'learner_id' => $learner->id,
            'word_id' => Word::factory()->for($lesson)->create()->id,
            'quality' => $quality,
            'scheduler' => $scheduler,
            'interval_before_days' => $before,
            'created_at' => $at,
        ]);
    };

    // sm2: 4 grades in the window (3 of due review cards: 2 passes, 1 fail; 1 learning card), 1 outside the window.
    $review($sm2Old, 'sm2', 4, 6, $now->subDays(1));
    $review($sm2Old, 'sm2', 5, 12, $now->subDays(2));
    $review($sm2Old, 'sm2', 2, 3, $now->subDays(3));
    $review($sm2Default, 'sm2', 4, 0, $now->subDays(4));
    $review($sm2Old, 'sm2', 4, 6, $now->subDays(29));
    // fsrs: 2 grades of due cards, both passes; one of them 8 days after the learner signed up (retained).
    $review($fsrsRetained, 'fsrs', 4, 5, $now->subDays(2));
    $review($fsrsLost, 'fsrs', 3, 5, $now->subDays(8));
    $review($fsrsTooOld, 'fsrs', 4, 5, $now->subDays(1));

    // Graduated words answered by each arm within the window.
    $word = fn (): int => Word::factory()->for($lesson)->create()->id;
    WordRepetition::factory()->create(['learner_id' => $sm2Old->id, 'word_id' => $word(), 'repetitions' => 2, 'scheduler' => 'sm2', 'last_reviewed_at' => $now->subDays(1)]);
    WordRepetition::factory()->create(['learner_id' => $sm2Old->id, 'word_id' => $word(), 'repetitions' => 1, 'scheduler' => 'sm2', 'last_reviewed_at' => $now->subDays(3)]);
    WordRepetition::factory()->create(['learner_id' => $sm2Old->id, 'word_id' => $word(), 'repetitions' => 0, 'state' => 'learning', 'scheduler' => 'sm2', 'last_reviewed_at' => $now->subDays(1)]);
    WordRepetition::factory()->create(['learner_id' => $sm2Old->id, 'word_id' => $word(), 'repetitions' => 3, 'scheduler' => 'sm2', 'last_reviewed_at' => $now->subDays(40)]);
    WordRepetition::factory()->create(['learner_id' => $fsrsRetained->id, 'word_id' => $word(), 'repetitions' => 4, 'scheduler' => 'fsrs', 'last_reviewed_at' => $now->subDays(2)]);

    $metrics = app(SchedulerMetricsService::class)->compare($now);
    $sm2 = $metrics['sm2'];
    $fsrs = $metrics['fsrs'];

    expect($sm2->reviews)->toBe(4)
        ->and($sm2->dueReviews)->toBe(3)
        ->and($sm2->duePasses)->toBe(2)
        ->and($sm2->passRate())->toEqualWithDelta(2 / 3, 1e-9)
        ->and($sm2->graduatedWords)->toBe(2)
        ->and($sm2->reviewsPerGraduatedWord())->toEqualWithDelta(2.0, 1e-9)
        // cohort 28..7 days: sm2Old, sm2Default (NULL → default sm2); sm2Old graded 19 days after sign-up, sm2Default 11 days after.
        ->and($sm2->cohortLearners)->toBe(2)
        ->and($sm2->retainedD7)->toBe(2)
        ->and($sm2->retentionD7())->toEqualWithDelta(1.0, 1e-9);

    expect($fsrs->reviews)->toBe(3)
        ->and($fsrs->dueReviews)->toBe(3)
        ->and($fsrs->duePasses)->toBe(3)
        ->and($fsrs->passRate())->toEqualWithDelta(1.0, 1e-9)
        ->and($fsrs->graduatedWords)->toBe(1)
        ->and($fsrs->reviewsPerGraduatedWord())->toEqualWithDelta(3.0, 1e-9)
        // cohort: fsrsRetained (graded on day 8 → retained), fsrsLost (graded on day 1 → not); too young / too old excluded.
        ->and($fsrs->cohortLearners)->toBe(2)
        ->and($fsrs->retainedD7)->toBe(1)
        ->and($fsrs->retentionD7())->toEqualWithDelta(0.5, 1e-9);

    // No data at all → null rates, not division by zero.
    $empty = app(SchedulerMetricsService::class)->compare($now->addYears(5));
    expect($empty['sm2']->passRate())->toBeNull()
        ->and($empty['fsrs']->reviewsPerGraduatedWord())->toBeNull()
        ->and($empty['fsrs']->retentionD7())->toBeNull();
});
