<?php

declare(strict_types=1);

use App\Domain\Learning\DTO\SchedulerMetrics;
use App\Domain\Learning\Enums\SchedulerKind;
use App\Filament\Resources\ExamSessionResource\Pages\ViewExamSession;
use App\Filament\Resources\ExamSessionResource\RelationManagers\ResultsRelationManager;
use App\Filament\Resources\LearnerResource\Pages\ViewLearner;
use App\Filament\Resources\LearnerResource\RelationManagers\DailyStatsRelationManager;
use App\Filament\Resources\Support\AdminNumbers;
use App\Filament\Widgets\SchedulerComparisonWidget;
use App\Models\ExamAnswer;
use App\Models\ExamResult;
use App\Models\ExamSession;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

/*
 * A number in a Russian text of the admin panel has a decimal comma — «12,34 с», «92,5%» — not PHP's dot. The same
 * writing AdminErrors uses in its sentences (AdminNumbers::decimal).
 */

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    app()->setLocale('ru');

    $admin = User::factory()->create(['is_active' => true, 'two_factor_secret' => 'stub', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now()]);
    $admin->assignRole('admin');
    $this->actingAs($admin);
});

it('formats a number with a decimal comma and a non-breaking space between the thousands', function (): void {
    expect(AdminNumbers::decimal(12.34, 2))->toBe('12,34')
        ->and(AdminNumbers::decimal(12.3, 2))->toBe('12,30')
        ->and(AdminNumbers::decimal(1.5))->toBe('1,5')
        ->and(AdminNumbers::decimal(1234.5, 2))->toBe("1\u{00A0}234,50")
        ->and(AdminNumbers::decimal(7, 0))->toBe('7')
        ->and(AdminNumbers::decimal(100, 0))->toBe('100');
});

it('drops the zeros at the end of the fraction for a number in a sentence, and keeps an integer whole', function (): void {
    expect(AdminNumbers::decimal(95.0, trimZeros: true))->toBe('95')
        ->and(AdminNumbers::decimal(79.5, trimZeros: true))->toBe('79,5')
        ->and(AdminNumbers::decimal(100.0, trimZeros: true))->toBe('100')
        ->and(AdminNumbers::decimal(10.0, trimZeros: true))->toBe('10')
        ->and(AdminNumbers::decimal(100, 0, trimZeros: true))->toBe('100');
});

it('writes the time of an exam result in seconds with a decimal comma', function (): void {
    $exam = ExamSession::factory()->create();
    $quick = Learner::factory()->create();
    $slow = Learner::factory()->create();
    $quickResult = ExamResult::factory()->create(['exam_session_id' => $exam->id, 'learner_id' => $quick->id, 'rank' => 1]);
    $slowResult = ExamResult::factory()->create(['exam_session_id' => $exam->id, 'learner_id' => $slow->id, 'rank' => 2]);

    foreach ([[$quick, 5_000], [$quick, 7_340], [$slow, 1_234_500]] as [$learner, $ms]) {
        ExamAnswer::factory()->create(['exam_session_id' => $exam->id, 'learner_id' => $learner->id, 'time_spent_ms' => $ms]);
    }

    livewire(ResultsRelationManager::class, ['ownerRecord' => $exam, 'pageClass' => ViewExamSession::class])
        ->assertTableColumnFormattedStateSet('time_spent_ms', '12,34 с', $quickResult)
        ->assertTableColumnFormattedStateSet('time_spent_ms', "1\u{00A0}234,50 с", $slowResult);
});

it('writes the minutes of a learner day with a decimal comma', function (): void {
    $learner = Learner::factory()->create();
    $day = LearnerDailyStats::factory()->create(['learner_id' => $learner->id, 'date' => now()->toDateString(), 'time_spent_ms' => 90_000]);

    livewire(DailyStatsRelationManager::class, ['ownerRecord' => $learner, 'pageClass' => ViewLearner::class])
        ->assertTableColumnStateSet('time_spent_ms', '1,5', $day);
});

it('writes the percents and ratios of the SM-2 / FSRS comparison with a decimal comma', function (): void {
    Cache::put(SchedulerComparisonWidget::CACHE_KEY, [
        SchedulerKind::Sm2->value => new SchedulerMetrics(SchedulerKind::Sm2, 50, 40, 37, 16, 7, 4),
        SchedulerKind::Fsrs->value => new SchedulerMetrics(SchedulerKind::Fsrs, 0, 0, 0, 0, 0, 0),
    ], 60);

    livewire(SchedulerComparisonWidget::class)
        ->assertSee('SM-2 92,5% · FSRS —')
        ->assertSee('SM-2 3,13 · FSRS —')
        ->assertSee('SM-2 57,1% · FSRS —')
        ->assertDontSee('92.5%')
        ->assertDontSee('3.13');
});
