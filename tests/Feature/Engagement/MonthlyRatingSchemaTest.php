<?php

declare(strict_types=1);

/*
 * The tables of the monthly rating (docs/03 §2.62–2.64): uniqueness per (month, learner), the cascades, the index of
 * the standings and of the day rows' date, and the rollback / re-run of the two migrations.
 */

use App\Models\LeagueMonth;
use App\Models\LeagueMonthCandidate;
use App\Models\LeagueMonthScore;
use App\Models\Learner;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/** The `--step` that reaches back to (and includes) the migration file `$firstFile`, whatever files other tasks add later. */
function monthMigrationStepsBackTo(string $firstFile): int
{
    $names = array_map(fn (string $f): string => basename($f, '.php'), glob(database_path('migrations/*.php')) ?: []);
    sort($names);

    return count(array_filter($names, fn (string $n): bool => $n >= $firstFile));
}

it('keeps one month per first day and one score / one candidate per learner and month', function (): void {
    $month = LeagueMonth::factory()->startingOn('2026-11-01')->create();
    $learner = Learner::factory()->create();

    expect(fn () => DB::transaction(fn () => LeagueMonth::factory()->startingOn('2026-11-01')->create()))->toThrow(QueryException::class);

    LeagueMonthScore::factory()->create(['league_month_id' => $month->id, 'learner_id' => $learner->id]);
    expect(fn () => DB::transaction(fn () => LeagueMonthScore::factory()->create(['league_month_id' => $month->id, 'learner_id' => $learner->id])))->toThrow(QueryException::class);

    LeagueMonthCandidate::factory()->create(['league_month_id' => $month->id, 'learner_id' => $learner->id]);
    expect(fn () => DB::transaction(fn () => LeagueMonthCandidate::factory()->create(['league_month_id' => $month->id, 'learner_id' => $learner->id])))->toThrow(QueryException::class);

    expect(array_column(Schema::getIndexes('league_month_scores'), 'name'))->toContain('league_month_scores_standings_idx')
        ->and(array_column(Schema::getIndexes('league_month_candidates'), 'columns'))->toContain(['league_month_id', 'rank'])
        ->and(array_column(Schema::getIndexes('learner_daily_stats'), 'name'))->toContain('learner_daily_stats_date_idx');
});

it('cascades scores and candidates with the month and with the learner, and keeps the month when its reviewer is deleted', function (): void {
    $month = LeagueMonth::factory()->startingOn('2026-11-01')->create();
    $gone = Learner::factory()->create();
    $kept = Learner::factory()->create();
    LeagueMonthScore::factory()->create(['league_month_id' => $month->id, 'learner_id' => $gone->id]);
    LeagueMonthScore::factory()->create(['league_month_id' => $month->id, 'learner_id' => $kept->id]);
    LeagueMonthCandidate::factory()->create(['league_month_id' => $month->id, 'learner_id' => $gone->id]);
    LeagueMonthCandidate::factory()->create(['league_month_id' => $month->id, 'learner_id' => $kept->id]);

    $gone->forceDelete();
    expect(LeagueMonthScore::query()->count())->toBe(1)->and(LeagueMonthCandidate::query()->count())->toBe(1);

    $other = LeagueMonth::factory()->startingOn('2026-10-01')->create();
    LeagueMonthScore::factory()->create(['league_month_id' => $other->id, 'learner_id' => $kept->id]);
    $month->delete();
    expect(LeagueMonthScore::query()->count())->toBe(1)->and(LeagueMonthCandidate::query()->count())->toBe(0);
});

it('rolls back and re-applies the migrations of the monthly rating', function (): void {
    $this->artisan('migrate:rollback', ['--step' => monthMigrationStepsBackTo('2026_11_16_200001_create_league_month_tables')])->assertSuccessful();
    expect(Schema::hasTable('league_months'))->toBeFalse()
        ->and(Schema::hasTable('league_month_scores'))->toBeFalse()
        ->and(Schema::hasTable('league_month_candidates'))->toBeFalse()
        ->and(array_column(Schema::getIndexes('learner_daily_stats'), 'name'))->not->toContain('learner_daily_stats_date_idx')
        ->and(Schema::hasTable('learner_daily_stats'))->toBeTrue();

    $this->artisan('migrate')->assertSuccessful();
    expect(Schema::hasTable('league_months'))->toBeTrue()
        ->and(Schema::hasTable('league_month_candidates'))->toBeTrue()
        ->and(array_column(Schema::getIndexes('learner_daily_stats'), 'name'))->toContain('learner_daily_stats_date_idx');
});
