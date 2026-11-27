<?php

declare(strict_types=1);

/*
 * The statement budget of "My vocabulary" (docs/40 §2.3, docs/04 A.25), 120 graded words:
 *
 *   first page (no `after`)   the learner (auth), the page, the counts of the chips, the translations of the page (one batch, only
 *                             for the words the cache of the resolver has not seen - 0 when warm);
 *   with a search / a filter  the same: the search and the filter narrow the statement of the page, no statement of their own;
 *   next page (`after`)       the learner, the page, the translations (cold) - no counts.
 *
 * Every statement of the list starts from the learner's rows (`word_repetitions where learner_id = ?`); `words` is joined per row.
 */

use App\Domain\Twa\Services\JwtService;
use App\Models\Learner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\HotPathFixtures as Fx;
use Tests\Support\QueryLog;

uses(RefreshDatabase::class);

/** @return array{learner: Learner, headers: array<string, string>} a learner with 120 graded words */
function vocBudgetLearner(): array
{
    ['words' => $words] = Fx::course([1 => [1 => 60, 2 => 60]]);
    $learner = Learner::factory()->create();
    $rows = [];
    foreach ([...$words['1.1']['active'], ...$words['1.2']['active']] as $i => $wordId) {
        $rows[] = [
            'learner_id' => $learner->id, 'word_id' => $wordId, 'state' => $i % 3 === 0 ? 'learning' : 'review', 'repetitions' => 1,
            'interval_days' => $i % 3 === 0 ? 0 : $i % 30, 'last_quality' => 4, 'last_reviewed_at' => now()->subDay(),
            'next_review_at' => now()->addDays(2), 'created_at' => now(), 'updated_at' => now(),
        ];
    }
    DB::table('word_repetitions')->insert($rows);

    return ['learner' => $learner, 'headers' => ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']]];
}

/**
 * @param  array<string, int|string>  $query
 * @return list<string>
 */
function vocBudget(object $test, array $headers, array $query = []): array
{
    return QueryLog::during(fn () => $test->withHeaders($headers)->getJson('/api/twa/vocabulary'.($query === [] ? '' : '?'.http_build_query($query)))->assertOk());
}

/** @param  list<string>  $queries */
function vocBudgetFromRows(array $queries): int
{
    // The learner's rows first, `words` per row: on PostgreSQL a LATERAL subquery with OFFSET 0 (the fence of docs/40 "Замеры"), on sqlite
    // a plain join (its planner always loops over the rows of the WHERE).
    $words = (getenv('DB_CONNECTION') ?: 'sqlite') === 'pgsql'
        ? 'inner join lateral \(select ["`]id["`], ["`]word["`], .* from ["`]words["`] where ["`]words["`]\.["`]id["`] = ["`]wr["`]\.["`]word_id["`] and ["`]words["`]\.["`]is_active["`] = \? offset 0\) as ["`]w["`] on true'
        : 'inner join ["`]words["`] as ["`]w["`] on ["`]w["`]\.["`]id["`] = ["`]wr["`]\.["`]word_id["`] and ["`]w["`]\.["`]is_active["`] = \?';

    return count(QueryLog::matching($queries, '/ from ["`]word_repetitions["`] as ["`]wr["`] '.$words.'.* where ["`]wr["`]\.["`]learner_id["`] = \? and ["`]wr["`]\.["`]last_reviewed_at["`] is not null/s'));
}

it('reads the first page in 4 statements cold and 3 warm, and the next page in 3 cold and 2 warm', function (): void {
    ['headers' => $h] = vocBudgetLearner();

    $cold = vocBudget($this, $h);
    expect($cold)->toHaveCount(4)
        ->and(QueryLog::matching($cold, '/from ["`]learners["`]/'))->toHaveCount(1)
        ->and(vocBudgetFromRows($cold))->toBe(1) // the page
        // The counts: the learner's rows with the anti-join of inactive words, no probe of `words` per row.
        ->and(QueryLog::matching($cold, '/^select count\(\*\) as all_count, .* from ["`]word_repetitions["`] as ["`]wr["`] where ["`]wr["`]\.["`]learner_id["`] = \? and ["`]wr["`]\.["`]last_reviewed_at["`] is not null and not exists \(select 1 from ["`]words["`] as ["`]w["`] where ["`]w["`]\.["`]id["`] = ["`]wr["`]\.["`]word_id["`] and ["`]w["`]\.["`]is_active["`] = \?\)/'))->toHaveCount(1)
        ->and(QueryLog::matching($cold, '/from ["`]word_translations["`]/'))->toHaveCount(1)
        ->and(QueryLog::matching($cold, '/^(insert|update|delete)/i'))->toBe([]);

    $warm = vocBudget($this, $h);
    expect($warm)->toHaveCount(3)
        ->and(QueryLog::matching($warm, '/from ["`]word_translations["`]/'))->toBe([]);

    $after = $this->withHeaders($h)->getJson('/api/twa/vocabulary')->json('next_cursor');
    $nextCold = vocBudget($this, $h, ['after' => $after]);
    expect($nextCold)->toHaveCount(3)
        ->and(vocBudgetFromRows($nextCold))->toBe(1)
        ->and(QueryLog::matching($nextCold, '/count\(\*\) as all_count/'))->toBe([]);

    expect(vocBudget($this, $h, ['after' => $after]))->toHaveCount(2);
});

it('pays nothing extra for a search or a filter: they narrow the statement of the page', function (array $query, int $cold): void {
    ['headers' => $h] = vocBudgetLearner();

    $queries = vocBudget($this, $h, $query);
    expect($queries)->toHaveCount($cold)
        ->and(vocBudgetFromRows($queries))->toBe(1)
        ->and(QueryLog::matching($queries, '/count\(\*\) as all_count/'))->toHaveCount(1);
})->with([
    // A search matching nothing has no translations to read: 3; one matching words reads them once.
    'search, no match' => [['q' => 'zzz'], 3],
    'search by the word' => [['q' => 'w'], 4],
    'weak' => [['filter' => 'weak'], 4],
    'strong with a search' => [['filter' => 'strong', 'q' => 'т'], 4],
]);

it('reads a 422 cursor with the learner alone', function (): void {
    ['headers' => $h] = vocBudgetLearner();

    $queries = QueryLog::during(fn () => $this->withHeaders($h)->getJson('/api/twa/vocabulary?after=***')->assertStatus(422));
    expect($queries)->toHaveCount(1);
});
