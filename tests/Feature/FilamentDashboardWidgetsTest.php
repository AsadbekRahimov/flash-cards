<?php

declare(strict_types=1);

use App\Filament\Widgets\ActivityChartWidget;
use App\Filament\Widgets\ExamsLast30DaysWidget;
use App\Filament\Widgets\HardestWordsTableWidget;
use App\Filament\Widgets\TopStudentsTableWidget;
use App\Filament\Widgets\TotalStudentsWidget;
use App\Models\Student;
use App\Models\TrainingReview;
use App\Models\User;
use App\Models\Word;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(RolePermissionSeeder::class)->run();

    $admin = User::factory()->create([
        'is_active' => true,
        'two_factor_secret' => 'stub',
        'two_factor_recovery_codes' => [],
        'two_factor_confirmed_at' => now(),
    ]);
    $admin->assignRole('admin');

    Auth::login($admin);
    Session::put('2fa.passed_at', now()->timestamp);
});

it('renders all dashboard widgets without livewire errors', function (): void {
    $student = Student::factory()->create();
    $word = Word::factory()->create(['word' => 'difficult']);

    TrainingReview::factory()
        ->count(5)
        ->create([
            'student_id' => $student->id,
            'word_id' => $word->id,
            'quality' => 2,
            'created_at' => now(),
        ]);

    Livewire::test(TotalStudentsWidget::class)->assertOk();
    Livewire::test(ExamsLast30DaysWidget::class)->assertOk();
    Livewire::test(ActivityChartWidget::class)->assertOk();
    Livewire::test(TopStudentsTableWidget::class)->assertCanSeeTableRecords([$student]);
    Livewire::test(HardestWordsTableWidget::class)->assertCanSeeTableRecords([$word]);
});
