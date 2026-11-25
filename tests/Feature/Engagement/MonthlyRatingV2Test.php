<?php

declare(strict_types=1);

/*
 * The monthly rating on the word points v2 (docs/32 §5.2, §5.3, §7; task S9; owner's decisions docs/31 §5 В5): the version of the
 * month written at its creation, the source by the version (rating days, the distinct long words of the journal, the signals of the
 * local days), the two tables Free / Pro, the competition rank, the top / a page / the neighbours / the gap, the close (RANK per
 * category, the candidates with a shared boundary place, idempotent), the confirmation of 3 + 3 prizes with the reserve for a tie.
 * The v1 month is MonthlyRatingServiceTest (unchanged); the statements - LeagueV2BudgetTest.
 */

use App\Domain\Engagement\DTO\BoardCursor;
use App\Domain\Engagement\DTO\BoardRow;
use App\Domain\Engagement\DTO\MonthlyMyLine;
use App\Domain\Engagement\Enums\ScoreCategory;
use App\Domain\Engagement\Enums\ScoreKind;
use App\Domain\Engagement\Exceptions\LeagueMonthException;
use App\Domain\Engagement\Services\LeagueService;
use App\Domain\Engagement\Services\MonthlyRatingReviewService;
use App\Domain\Engagement\Services\MonthlyRatingService;
use App\Domain\Operations\Support\PureCache;
use App\Models\LeagueMonth;
use App\Models\LeagueMonthCandidate;
use App\Models\LeagueMonthScore;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\LearnerScoreDay;
use App\Models\Lexeme;
use App\Models\ScoreCredit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('leagues.timezone', 'Asia/Tashkent');
    config()->set('leagues.scoring.mode', 'live');
    config()->set('leagues.scoring.versions.2.starts_on', '2027-02-01');
    config()->set('leagues.monthly.min_active_days', 2);
    config()->set('leagues.monthly.min_account_days', 0);
    config()->set('leagues.monthly.candidates', 3);
    config()->set('leagues.monthly.categories', ['free' => ['places' => 3, 'tie_reserve' => 1], 'pro' => ['places' => 3, 'tie_reserve' => 1]]);
});

function mv2Now(string $local = '2027-02-20 12:00'): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Asia/Tashkent')->utc();
}

function mv2(): MonthlyRatingService
{
    return app(MonthlyRatingService::class);
}

function mv2Month(): LeagueMonth
{
    return mv2()->currentMonth(mv2Now());
}

/** Rating days of February from the 2nd: month points per day (all in one category). @param list<int> $points */
function mv2Days(Learner $learner, array $points, ScoreCategory $category = ScoreCategory::Free, bool $capped = false): void
{
    foreach ($points as $i => $p) {
        $day = CarbonImmutable::parse('2027-02-02')->addDays($i);
        LearnerScoreDay::factory()->create([
            'learner_id' => $learner->id, 'rating_day' => $day->toDateString(),
            'week_starts_on' => $day->startOfWeek()->toDateString(), 'month_starts_on' => '2027-02-01',
            'week_category' => $category, 'month_category' => $category,
            'month_points' => $p, 'week_points' => $p, 'raw_points' => $p + 5,
            'month_capped_at' => $capped && $i === 0 ? mv2Now() : null,
        ]);
    }
}

function mv2Admin(): User
{
    Role::findOrCreate('admin', 'web');
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('admin');

    return $user;
}

/** A closed (pending) v2 month: the learners with their days, already the full recount, the ranks and the candidates. */
function mv2Close(): LeagueMonth
{
    $month = mv2Month();
    expect(mv2()->closeMonth($month, mv2Now('2027-03-01 04:00')))->toBeTrue();

    return $month->fresh() ?? $month;
}

it('writes the rules version into the month at its creation; January (before the start) stays v1', function (): void {
    expect(mv2Month()->fresh()?->rules_version)->toBe(2)
        ->and(mv2()->currentMonth(mv2Now('2027-01-20 12:00'))->fresh()?->rules_version)->toBe(1)
        ->and(MonthlyRatingService::topKey(9))->toBe('league:month:top:v1:9')
        ->and(MonthlyRatingService::topKey(9, ScoreCategory::Pro))->toBe('league:month:top:v2:9:pro');
});

it('sums a v2 month from the rating days: points, days, capped days, distinct long words of the journal, the category, the signals', function (): void {
    $learner = Learner::factory()->create(['onboarding_state' => 'new']); // no onboarding in the bot: still in the table (docs/34 §3)
    mv2Days($learner, [100, 0, 40], ScoreCategory::Pro, capped: true);
    [$x, $y, $z] = Lexeme::factory()->count(3)->create()->modelKeys();
    $credit = fn (string $day, int $lexeme, int $month, ?CarbonImmutable $voided = null, ScoreKind $kind = ScoreKind::Long) => ScoreCredit::factory()->create([
        'learner_id' => $learner->id, 'rating_day' => $day, 'lexeme_id' => $lexeme, 'kind' => $kind, 'week_points' => $month, 'month_points' => $month, 'voided_at' => $voided,
    ]);
    $credit('2027-02-02', $x, 5);
    $credit('2027-02-04', $x, 5);           // the same lexeme a second time this month: one long word
    $credit('2027-02-04', $y, 0);           // cut by the cap: not a long word of the month
    $credit('2027-02-03', $z, 5, mv2Now()); // voided
    $credit('2027-02-03', $y, 3, null, ScoreKind::Review);
    LearnerDailyStats::factory()->create(['learner_id' => $learner->id, 'date' => '2027-02-02', 'reviews_count' => 30, 'correct_count' => 25, 'new_words_count' => 4, 'time_spent_ms' => 60000]);

    mv2()->refresh(mv2Month(), mv2Now(), full: true);

    $row = LeagueMonthScore::query()->where('learner_id', $learner->id)->sole();
    expect([$row->category, $row->points, $row->active_days, $row->capped_days, $row->long_words, $row->raw_points, $row->max_day_points, $row->participant])
        ->toBe(['pro', 140, 2, 1, 1, 155, 105, true])
        ->and([$row->reviews, $row->correct, $row->new_words, $row->time_spent_ms])->toBe([30, 25, 4, 60000]);

    // A blocked learner stays in the table as a record, out of the places, with the reason.
    $learner->forceFill(['blocked_at' => mv2Now()])->save();
    $line = mv2()->myLine(mv2Month(), $learner->fresh() ?? $learner);
    expect($line?->participant)->toBeTrue(); // not recounted yet
    mv2()->refreshLearner(mv2Month(), $learner, mv2Now());
    $line = mv2()->myLine(mv2Month(), $learner->fresh() ?? $learner);
    expect($line?->participant)->toBeFalse()
        ->and($line?->rank)->toBeNull()
        ->and($line?->notCountedReason)->toBe(MonthlyMyLine::REASON_BLOCKED)
        ->and($line?->category)->toBe(ScoreCategory::Pro)
        ->and($line?->longWords)->toBe(1);
});

it('ranks each table on its own with the competition rank and serves the top of the category from its own key', function (): void {
    $free = Learner::factory()->count(4)->create()->all();
    foreach ([[50, 50], [60, 20], [60, 20], [10]] as $i => $days) {
        mv2Days($free[$i], $days);
    }
    $pro = Learner::factory()->create();
    mv2Days($pro, [5], ScoreCategory::Pro);
    $month = mv2Month();
    mv2()->refresh($month, mv2Now(), full: true);

    $top = mv2()->top($month, ScoreCategory::Free);
    expect(array_map(fn ($s): array => [$s->learnerId, $s->rank, $s->points], $top))->toBe([
        [$free[0]->id, 1, 100],                     // 100 on 2 days
        [$free[1]->id, 2, 80], [$free[2]->id, 2, 80], // equal tuples: one place
        [$free[3]->id, 4, 10],
    ])
        ->and(array_map(fn ($s): int => $s->learnerId, mv2()->top($month, ScoreCategory::Pro)))->toBe([$pro->id])
        ->and(PureCache::store()->get(MonthlyRatingService::topKey($month->id, ScoreCategory::Free)))->toBeArray()
        ->and(mv2()->myLine($month, $free[2])?->rank)->toBe(2)
        ->and(mv2()->myLine($month, $pro)?->rank)->toBe(1)
        ->and(mv2()->participantsCount($month, ScoreCategory::Free))->toBe(4);
});

it('pages a category by a keyset with stable shared places (7, 7, 9) and finds the neighbours and the gap', function (): void {
    // Ten Free learners: 100, 90, …, 40, then 30, 30, 10 - places 1..7, 8, 8, 10.
    $points = [100, 90, 80, 70, 60, 50, 40, 30, 30, 10];
    $learners = [];
    foreach ($points as $p) {
        $learner = Learner::factory()->create();
        mv2Days($learner, [$p]);
        $learners[] = $learner;
    }
    $month = mv2Month();
    mv2()->refresh($month, mv2Now(), full: true);

    $first = mv2()->board($month, ScoreCategory::Free, null, 4);
    $second = mv2()->board($month, ScoreCategory::Free, $first['next'], 4);
    $third = mv2()->board($month, ScoreCategory::Free, BoardCursor::decode($second['next']?->encode()), 4);
    $ranks = fn (array $page): array => array_map(fn (BoardRow $r): int => $r->rank, $page['rows']);

    expect($ranks($first))->toBe([1, 2, 3, 4])
        ->and($ranks($second))->toBe([5, 6, 7, 8])
        ->and($ranks($third))->toBe([8, 10])
        ->and($third['next'])->toBeNull()
        ->and(array_map(fn (BoardRow $r): int => $r->learnerId, [...$first['rows'], ...$second['rows'], ...$third['rows']]))->toBe(array_map(fn (Learner $l): int => $l->id, $learners));

    $score = mv2()->scoreOf($month, $learners[8]->id);
    $around = mv2()->neighbours($month, $score);
    expect($around['rank'])->toBe(8)
        ->and(array_map(fn (BoardRow $r): array => [$r->learnerId, $r->rank], $around['above']))->toBe([[$learners[6]->id, 7], [$learners[7]->id, 8]])
        ->and(array_map(fn (BoardRow $r): array => [$r->learnerId, $r->rank], $around['below']))->toBe([[$learners[9]->id, 10]])
        ->and($around['next_gap'])->toBe(11)                 // 40 − 30 + 1: overtakes place 7 for sure
        ->and(mv2()->neighbours($month, mv2()->scoreOf($month, $learners[0]->id))['next_gap'])->toBeNull();

    // An untrusted cursor is refused, never queried.
    expect(BoardCursor::decode('not-a-cursor'))->toBeNull()
        ->and(BoardCursor::decode(rtrim(strtr(base64_encode('{"p":1,"l":-1,"d":0,"id":1}'), '+/', '-_'), '=')))->toBeNull()
        ->and(BoardCursor::decode(rtrim(strtr(base64_encode('{"p":"1 or 1=1","l":0,"d":0,"id":1}'), '+/', '-_'), '=')))->toBeNull();
});

it('closes a v2 month once: RANK per category, the candidates with everybody sharing the last place, a repeat changes nothing', function (): void {
    // Free: 100 | 80 | 60 60 (candidates 3: place 3 is shared - four candidates) | 50; Pro: 70 | 20 (one day: not eligible).
    $free = Learner::factory()->count(5)->create()->all();
    foreach ([[50, 50], [40, 40], [30, 30], [30, 30], [25, 25]] as $i => $days) {
        mv2Days($free[$i], $days);
    }
    $pro = Learner::factory()->count(2)->create()->all();
    mv2Days($pro[0], [35, 35], ScoreCategory::Pro);
    mv2Days($pro[1], [20], ScoreCategory::Pro);

    $month = mv2Close();
    $ranks = LeagueMonthScore::query()->where('league_month_id', $month->id)->get()->mapWithKeys(fn ($s): array => [$s->learner_id => $s->final_rank])->all();
    expect($ranks)->toBe([$free[0]->id => 1, $free[1]->id => 2, $free[2]->id => 3, $free[3]->id => 3, $free[4]->id => 5, $pro[0]->id => 1, $pro[1]->id => 2]);

    $candidates = fn (): array => LeagueMonthCandidate::query()->where('league_month_id', $month->id)->orderBy('category')->orderBy('rank')->orderBy('learner_id')
        ->get()->map(fn ($c): string => "{$c->category}:{$c->rank}:{$c->learner_id}")->all();
    $frozen = $candidates();
    expect($frozen)->toBe([
        "free:1:{$free[0]->id}", "free:2:{$free[1]->id}", "free:3:{$free[2]->id}", "free:3:{$free[3]->id}",
        "pro:1:{$pro[0]->id}",
    ]);

    expect(mv2()->closeMonth($month, mv2Now('2027-03-01 05:00')))->toBeFalse()
        ->and($candidates())->toBe($frozen);
});

it('confirms 3 prizes in Free and 3 in Pro: a shared prize place within the reserve wins together, beyond it the admin decides', function (): void {
    config()->set('leagues.monthly.candidates', 6);
    // Free: 100 | 80 | 60 60 → places 1, 2, 3, 3 (four winners: places 3 + reserve 1). Pro: 90 | 70 | 50 | 40 → three winners.
    $free = Learner::factory()->count(4)->create()->all();
    foreach ([[50, 50], [40, 40], [30, 30], [30, 30]] as $i => $days) {
        mv2Days($free[$i], $days);
    }
    $pro = Learner::factory()->count(4)->create()->all();
    foreach ([[45, 45], [35, 35], [25, 25], [20, 20]] as $i => $days) {
        mv2Days($pro[$i], $days, ScoreCategory::Pro);
    }
    $month = mv2Close();
    $admin = mv2Admin();

    app(MonthlyRatingReviewService::class)->confirm($month, $admin, now: mv2Now('2027-03-02 10:00'));

    $winners = LeagueMonthCandidate::query()->where('league_month_id', $month->id)->where('status', 'winner')->orderBy('category')->orderBy('place')->orderBy('learner_id')
        ->get()->map(fn ($c): string => "{$c->category}:{$c->place}:{$c->learner_id}")->all();
    expect($winners)->toBe([
        "free:1:{$free[0]->id}", "free:2:{$free[1]->id}", "free:3:{$free[2]->id}", "free:3:{$free[3]->id}",
        "pro:1:{$pro[0]->id}", "pro:2:{$pro[1]->id}", "pro:3:{$pro[2]->id}",
    ]);
});

it('refuses a confirmation whose shared prize place goes beyond the reserve unless the admin accepts it; a disqualification moves the places', function (): void {
    config()->set('leagues.monthly.candidates', 6);
    config()->set('leagues.monthly.categories.free.tie_reserve', 0);
    $free = Learner::factory()->count(4)->create()->all();
    foreach ([[50, 50], [40, 40], [30, 30], [30, 30]] as $i => $days) {
        mv2Days($free[$i], $days);
    }
    $month = mv2Close();
    $admin = mv2Admin();
    $review = app(MonthlyRatingReviewService::class);

    expect(fn () => $review->confirm($month, $admin))->toThrow(LeagueMonthException::class)
        ->and(($month->fresh() ?? $month)->status)->toBe('pending_review');

    // The provisional places of the page: 1, 2, 3, 3.
    $places = collect($review->review($month)->candidates)->map(fn ($c): ?int => $c->provisionalPlace)->all();
    expect($places)->toBe([1, 2, 3, 3]);

    // Disqualifying the second: 1, -, 2, 2 - four... three winners within 3 places.
    $second = LeagueMonthCandidate::query()->where('league_month_id', $month->id)->where('learner_id', $free[1]->id)->sole();
    $review->disqualify($month, $second->id, 'shared device', $admin);
    $review->confirm($month, $admin);
    expect(LeagueMonthCandidate::query()->where('league_month_id', $month->id)->where('status', 'winner')->orderBy('learner_id')->pluck('place', 'learner_id')->all())
        ->toBe([$free[0]->id => 1, $free[2]->id => 2, $free[3]->id => 2]);
});

it('keeps the prizes of each table: an object by category, a plain list for both, the places up to the category\'s', function (): void {
    $month = mv2Month();
    $admin = mv2Admin();
    $review = app(MonthlyRatingReviewService::class);

    $review->setPrizes($month, ['free' => [['place' => 1, 'ru' => 'Книга', 'uz' => 'Kitob']], 'pro' => [['place' => 3, 'ru' => 'Курс', 'uz' => 'Kurs']]], $admin);
    expect(array_map(fn ($p): string => $p->place.':'.$p->ru, mv2()->prizes($month->fresh() ?? $month, ScoreCategory::Free)))->toBe(['1:Книга'])
        ->and(array_map(fn ($p): string => $p->place.':'.$p->ru, mv2()->prizes($month->fresh() ?? $month, ScoreCategory::Pro)))->toBe(['3:Курс']);

    expect(fn () => $review->setPrizes($month, ['vip' => []], $admin))->toThrow(LeagueMonthException::class)
        ->and(fn () => $review->setPrizes($month, ['free' => [['place' => 4, 'ru' => 'x', 'uz' => 'x']]], $admin))->toThrow(LeagueMonthException::class);

    $review->setPrizes($month, [['place' => 2, 'ru' => 'Обоим', 'uz' => 'Ikkalasiga']], $admin);
    expect(count(mv2()->prizes($month->fresh() ?? $month, ScoreCategory::Pro)))->toBe(1)
        ->and(count(mv2()->prizes($month->fresh() ?? $month, ScoreCategory::Free)))->toBe(1);
});

it('leaves the weekly cache key of a v1 week and the v1 month top key as they were', function (): void {
    expect(LeagueService::CACHE_PREFIX)->toBe('league:standings:v2:')
        ->and(MonthlyRatingService::TOP_PREFIX)->toBe('league:month:top:v1:');
});
