<?php

declare(strict_types=1);

/*
 * The tables of the word points (docs/32 §2.2–§2.6, S2): the models and factories write and read them, the keys hold (one lexeme -
 * one credit a day, one period row per learner, period and first day; the violating insert runs in a savepoint, as PostgreSQL
 * aborts the transaction otherwise), a composite-key model updates only its own row, the final
 * purge of a learner takes every row with it, and the new columns of the league tables default to the v1 meaning.
 */

use App\Domain\Engagement\Enums\ScoreCategory;
use App\Domain\Engagement\Enums\ScoreKind;
use App\Domain\Engagement\Enums\ScorePeriod;
use App\Domain\Engagement\Enums\ScoreSource;
use App\Models\LeagueGroup;
use App\Models\LeagueMember;
use App\Models\LeagueMonth;
use App\Models\LeagueMonthScore;
use App\Models\LeagueWeek;
use App\Models\Learner;
use App\Models\LearnerLexeme;
use App\Models\LearnerScoreDay;
use App\Models\LearnerScorePeriod;
use App\Models\LearnerScoreTotal;
use App\Models\Lexeme;
use App\Models\ScoreCredit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates every table of the word points with its columns', function (): void {
    expect(Schema::hasColumns('lexemes', ['id', 'language', 'key', 'created_at']))->toBeTrue()
        ->and(Schema::hasColumn('words', 'lexeme_id'))->toBeTrue()
        ->and(Schema::hasColumns('learner_lexemes', ['learner_id', 'lexeme_id', 'confirmed_on', 'confirmed_source', 'last_attempt_on', 'last_credit_due_at', 'first_long_on', 'long_free_on', 'long_pro_on']))->toBeTrue()
        ->and(Schema::hasColumns('learner_score_periods', ['learner_id', 'period', 'starts_on', 'category', 'rules_version', 'fixed_at']))->toBeTrue()
        ->and(Schema::hasColumns('learner_score_days', ['rating_day', 'week_starts_on', 'week_category', 'week_version', 'month_starts_on', 'month_category', 'month_version', 'raw_points', 'new_count', 'review_count', 'long_count', 'week_points', 'week_new', 'week_review', 'week_long', 'week_capped_at', 'month_points', 'month_new', 'month_review', 'month_long', 'month_capped_at', 'revision']))->toBeTrue()
        ->and(Schema::hasColumns('score_credits', ['learner_id', 'rating_day', 'lexeme_id', 'word_id', 'source', 'source_id', 'kind', 'week_points', 'month_points', 'voided_at', 'voided_by_user_id', 'void_reason', 'created_at']))->toBeTrue()
        ->and(Schema::hasColumns('learner_score_totals', ['learner_id', 'category', 'points', 'long_words', 'active_days', 'first_day', 'last_day', 'participant', 'updated_at']))->toBeTrue()
        ->and(Schema::hasTable('learner_score_legacy'))->toBeFalse();
});

it('reads and writes the rows through the models and factories', function (): void {
    $learner = Learner::factory()->create();
    $lexeme = Lexeme::factory()->create(['key' => 'apple']);

    LearnerLexeme::factory()->confirmedOn('2027-02-10')->create(['learner_id' => $learner->id, 'lexeme_id' => $lexeme->id]);
    LearnerScorePeriod::factory()->pro()->create(['learner_id' => $learner->id, 'starts_on' => '2027-02-08']);
    LearnerScoreDay::factory()->on('2027-02-10')->create(['learner_id' => $learner->id, 'week_points' => 12, 'month_category' => ScoreCategory::Pro]);
    ScoreCredit::factory()->create(['learner_id' => $learner->id, 'lexeme_id' => $lexeme->id, 'rating_day' => '2027-02-10', 'kind' => ScoreKind::Long, 'week_points' => 5]);
    LearnerScoreTotal::factory()->create(['learner_id' => $learner->id, 'points' => 800]);

    $state = LearnerLexeme::query()->sole();
    $period = LearnerScorePeriod::query()->sole();
    $day = LearnerScoreDay::query()->sole();
    $credit = ScoreCredit::query()->sole();

    expect($state->confirmed_on?->toDateString())->toBe('2027-02-10')
        ->and($state->confirmed_source)->toBe('training')
        ->and($period->period)->toBe(ScorePeriod::Week)
        ->and($period->category)->toBe(ScoreCategory::Pro)
        ->and($day->week_starts_on->toDateString())->toBe('2027-02-08')
        ->and($day->month_starts_on->toDateString())->toBe('2027-02-01')
        ->and($day->month_category)->toBe(ScoreCategory::Pro)
        ->and($day->week_points)->toBe(12)
        ->and($day->raw_points)->toBe(0)
        ->and($day->revision)->toBe(0)
        ->and($credit->kind)->toBe(ScoreKind::Long)
        ->and($credit->source)->toBe(ScoreSource::Training)
        ->and($credit->lexeme->key)->toBe('apple')
        ->and(LearnerScoreTotal::query()->sole()->participant)->toBeTrue();
});

it('updates only its own row of a composite key', function (): void {
    $learner = Learner::factory()->create();
    [$a, $b] = Lexeme::factory()->count(2)->create();
    LearnerLexeme::factory()->create(['learner_id' => $learner->id, 'lexeme_id' => $a->id]);
    LearnerLexeme::factory()->create(['learner_id' => $learner->id, 'lexeme_id' => $b->id]);

    $row = LearnerLexeme::query()->where('lexeme_id', $b->id)->sole();
    $row->last_attempt_on = now()->startOfDay();
    $row->save();

    expect(LearnerLexeme::query()->whereNotNull('last_attempt_on')->pluck('lexeme_id')->all())->toBe([$b->id])
        ->and($row->fresh()?->lexeme_id)->toBe($b->id);

    LearnerScoreTotal::factory()->create(['learner_id' => $learner->id, 'category' => ScoreCategory::Free]);
    LearnerScoreTotal::factory()->create(['learner_id' => $learner->id, 'category' => ScoreCategory::Pro]);
    $pro = LearnerScoreTotal::query()->where('category', 'pro')->sole();
    $pro->update(['points' => 950]);

    expect(LearnerScoreTotal::query()->orderBy('category')->pluck('points')->all())->toBe([0, 950]);
});

it('allows one credit per lexeme and rating day', function (): void {
    $credit = ScoreCredit::factory()->create(['rating_day' => '2027-02-10']);

    expect(fn () => DB::transaction(fn () => ScoreCredit::factory()->create(['learner_id' => $credit->learner_id, 'lexeme_id' => $credit->lexeme_id, 'rating_day' => '2027-02-10'])))
        ->toThrow(UniqueConstraintViolationException::class);

    ScoreCredit::factory()->create(['learner_id' => $credit->learner_id, 'lexeme_id' => $credit->lexeme_id, 'rating_day' => '2027-02-11']);
    expect(ScoreCredit::query()->count())->toBe(2);
});

it('allows one day row and one period row per learner', function (): void {
    $day = LearnerScoreDay::factory()->on('2027-02-10')->create();
    expect(fn () => DB::transaction(fn () => LearnerScoreDay::factory()->on('2027-02-10')->create(['learner_id' => $day->learner_id])))
        ->toThrow(UniqueConstraintViolationException::class);

    $period = LearnerScorePeriod::factory()->create(['starts_on' => '2027-02-08']);
    expect(fn () => DB::transaction(fn () => LearnerScorePeriod::factory()->create(['learner_id' => $period->learner_id, 'starts_on' => '2027-02-08'])))
        ->toThrow(UniqueConstraintViolationException::class);
    LearnerScorePeriod::factory()->month('2027-02-01')->create(['learner_id' => $period->learner_id]);
    expect(LearnerScorePeriod::query()->count())->toBe(2);
});

it('takes every row of a learner with the final purge', function (): void {
    $learner = Learner::factory()->create();
    $lexeme = Lexeme::factory()->create();
    LearnerLexeme::factory()->create(['learner_id' => $learner->id, 'lexeme_id' => $lexeme->id]);
    LearnerScorePeriod::factory()->create(['learner_id' => $learner->id]);
    LearnerScoreDay::factory()->create(['learner_id' => $learner->id]);
    ScoreCredit::factory()->create(['learner_id' => $learner->id, 'lexeme_id' => $lexeme->id]);
    LearnerScoreTotal::factory()->create(['learner_id' => $learner->id]);

    $learner->forceDelete();

    expect(LearnerLexeme::query()->count() + LearnerScorePeriod::query()->count() + LearnerScoreDay::query()->count()
        + ScoreCredit::query()->count() + LearnerScoreTotal::query()->count())->toBe(0)
        ->and(Lexeme::query()->count())->toBe(1);
});

it('gives the league rows written by the v1 code the v1 meaning of the new columns', function (): void {
    $week = LeagueWeek::factory()->create();
    $group = LeagueGroup::factory()->create(['league_week_id' => $week->id]);
    $member = LeagueMember::factory()->create(['league_group_id' => $group->id, 'league_week_id' => $week->id]);
    $month = LeagueMonth::factory()->create();
    $score = LeagueMonthScore::factory()->create(['league_month_id' => $month->id]);

    expect($week->fresh()?->rules_version)->toBe(1)
        ->and($month->fresh()?->rules_version)->toBe(1)
        ->and($group->fresh()?->category)->toBe('free')
        ->and($member->fresh()?->category)->toBeNull()
        ->and($member->fresh()?->final_long_words)->toBeNull()
        ->and($score->fresh()?->category)->toBeNull()
        ->and($score->fresh()?->long_words)->toBe(0);
});

it('rolls the catalog and the score tables back and forth, keeping the indexes of words and of the league tables', function (): void {
    $names = fn (string $table): array => array_column(Schema::getIndexes($table), 'name');
    $files = [
        '2026_11_25_300001_create_lexemes_table',
        '2026_11_25_300002_add_lexeme_id_to_words_table',
        '2026_11_25_300003_add_words_lexeme_id_index',
        '2026_11_25_300004_create_word_score_tables',
        '2026_11_25_300005_add_scoring_columns_to_league_tables',
        '2026_11_25_300006_add_category_standings_index_to_league_month_scores',
    ];
    $migrations = array_map(fn (string $file): object => require database_path("migrations/{$file}.php"), $files);

    foreach (array_reverse($migrations) as $migration) {
        $migration->down();
    }

    expect(Schema::hasTable('lexemes'))->toBeFalse()
        ->and(Schema::hasTable('learner_score_days'))->toBeFalse()
        ->and(Schema::hasColumn('words', 'lexeme_id'))->toBeFalse()
        ->and(Schema::hasColumn('league_groups', 'category'))->toBeFalse()
        ->and(Schema::hasColumn('league_month_scores', 'long_words'))->toBeFalse()
        ->and(Schema::hasColumn('league_weeks', 'rules_version'))->toBeFalse()
        ->and($names('words'))->toContain('words_inactive_idx', 'words_lesson_id_word_unique')
        ->and($names('league_groups'))->toContain('league_groups_league_week_id_tier_members_count_index')
        ->and($names('league_groups'))->not->toContain('league_groups_league_week_id_category_tier_members_count_index')
        ->and($names('league_month_scores'))->toContain('league_month_scores_standings_idx')
        ->and($names('league_month_scores'))->not->toContain('league_month_scores_category_standings_idx');

    foreach ($migrations as $migration) {
        $migration->up();
    }

    expect(Schema::hasColumn('words', 'lexeme_id'))->toBeTrue()
        ->and($names('words'))->toContain('words_lexeme_id_idx', 'words_inactive_idx')
        ->and($names('league_groups'))->toContain('league_groups_league_week_id_category_tier_members_count_index', 'league_groups_league_week_id_tier_members_count_index')
        ->and($names('learner_lexemes'))->toContain('learner_lexemes_first_long_idx')
        ->and($names('learner_score_totals'))->toContain('learner_score_totals_standings_idx');
});
