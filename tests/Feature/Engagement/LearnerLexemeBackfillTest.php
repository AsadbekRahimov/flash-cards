<?php

declare(strict_types=1);

/*
 * `learner_lexemes:backfill` (docs/32 §12.3, task S7): the lexemes a learner has studied (a card out of `new`) become confirmed as
 * `legacy` on the rating day of the run - one row for two copies of a lexeme, a confirmation of the core left as it is, a row of the
 * core without one confirmed, the cards without a lexeme skipped and counted; idempotent, batches of any size give the same rows.
 */

use App\Domain\Learning\Enums\RepetitionState;
use App\Models\Learner;
use App\Models\LearnerLexeme;
use App\Models\Lexeme;
use App\Models\Word;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // 2026-11-25 23:30 UTC = 2026-11-26 04:30 in Tashkent: the rating day is the league zone's.
    $this->travelTo(CarbonImmutable::parse('2026-11-25 23:30:00', 'UTC'));
    config(['leagues.timezone' => 'Asia/Tashkent']);
});

function llbCard(Learner $learner, ?int $lexemeId, RepetitionState $state): Word
{
    $word = Word::factory()->create(['lexeme_id' => $lexemeId]);
    WordRepetition::factory()->create(['learner_id' => $learner->id, 'word_id' => $word->id, 'state' => $state->value]);

    return $word;
}

/** @return list<array<string, mixed>> */
function llbRows(): array
{
    return DB::table('learner_lexemes')->orderBy('learner_id')->orderBy('lexeme_id')->get()
        ->map(fn (object $r): array => [
            'learner_id' => (int) $r->learner_id, 'lexeme_id' => (int) $r->lexeme_id,
            'confirmed_on' => $r->confirmed_on === null ? null : substr((string) $r->confirmed_on, 0, 10),
            'confirmed_source' => $r->confirmed_source,
            'last_attempt_on' => $r->last_attempt_on === null ? null : substr((string) $r->last_attempt_on, 0, 10),
        ])->all();
}

/** @return array{ann: Learner, bob: Learner, lexemes: array<string, int>} */
function llbWorld(): array
{
    $lexemes = [];
    foreach (['apple', 'book', 'cat', 'dog'] as $key) {
        $lexemes[$key] = (int) Lexeme::factory()->create()->id;
    }

    $ann = Learner::factory()->create();
    llbCard($ann, $lexemes['apple'], RepetitionState::Review);     // two copies of one lexeme (two courses)…
    llbCard($ann, $lexemes['apple'], RepetitionState::Learning);   // …one row
    llbCard($ann, $lexemes['book'], RepetitionState::New);         // never studied: not confirmed
    llbCard($ann, $lexemes['cat'], RepetitionState::Relearning);
    llbCard($ann, null, RepetitionState::Review);                  // a deck word outside the catalog: skipped and counted

    $bob = Learner::factory()->create();
    llbCard($bob, $lexemes['apple'], RepetitionState::Review);
    llbCard($bob, $lexemes['dog'], RepetitionState::Review);
    // The core met both before the backfill: a real confirmation (kept as it is) and an attempt closed by a mistake (gets confirmed).
    LearnerLexeme::factory()->create(['learner_id' => $bob->id, 'lexeme_id' => $lexemes['apple'], 'confirmed_on' => '2026-11-20', 'confirmed_source' => 'game', 'last_attempt_on' => '2026-11-20']);
    LearnerLexeme::factory()->create(['learner_id' => $bob->id, 'lexeme_id' => $lexemes['dog'], 'confirmed_on' => null, 'confirmed_source' => null, 'last_attempt_on' => '2026-11-24']);

    return compact('ann', 'bob', 'lexemes');
}

/**
 * The rows after a backfill on 2026-11-26 (Tashkent): ann's two studied lexemes (apple once for its two copies), bob's real
 * confirmation as it was, bob's closed attempt confirmed.
 *
 * @param  array{ann: Learner, bob: Learner, lexemes: array<string, int>}  $world
 * @return list<array<string, mixed>>
 */
function llbExpected(array $world): array
{
    ['ann' => $ann, 'bob' => $bob, 'lexemes' => $l] = $world;

    return [
        ['learner_id' => $ann->id, 'lexeme_id' => $l['apple'], 'confirmed_on' => '2026-11-26', 'confirmed_source' => 'legacy', 'last_attempt_on' => null],
        ['learner_id' => $ann->id, 'lexeme_id' => $l['cat'], 'confirmed_on' => '2026-11-26', 'confirmed_source' => 'legacy', 'last_attempt_on' => null],
        ['learner_id' => $bob->id, 'lexeme_id' => $l['apple'], 'confirmed_on' => '2026-11-20', 'confirmed_source' => 'game', 'last_attempt_on' => '2026-11-20'],
        ['learner_id' => $bob->id, 'lexeme_id' => $l['dog'], 'confirmed_on' => '2026-11-26', 'confirmed_source' => 'legacy', 'last_attempt_on' => '2026-11-24'],
    ];
}

it('confirms the studied lexemes once, keeps the confirmations of the core and skips the words without a lexeme', function (): void {
    $world = llbWorld();

    $this->artisan('learner_lexemes:backfill')
        ->expectsOutputToContain('lexemes confirmed: 3; studied cards without a lexeme (skipped): 1')
        ->assertSuccessful();

    expect(llbRows())->toBe(llbExpected($world));
});

it('is idempotent: a second run, even days later, changes nothing and keeps the earliest date', function (): void {
    llbWorld();
    $this->artisan('learner_lexemes:backfill')->assertSuccessful();
    $first = llbRows();

    $this->travel(3)->days();
    $this->artisan('learner_lexemes:backfill')
        ->expectsOutputToContain('lexemes confirmed: 0;')
        ->assertSuccessful();

    expect(llbRows())->toBe($first);
});

it('gives the same rows whatever the batch, and reports every batch', function (): void {
    $world = llbWorld();
    Learner::factory()->count(3)->create(); // learners without cards: walked, nothing written

    $this->artisan('learner_lexemes:backfill', ['--batch' => 2])
        ->expectsOutputToContain('learners: 2 (up to id')
        ->expectsOutputToContain('Learners: 5 in 3 batches; lexemes confirmed: 3;')
        ->assertSuccessful();
    expect(llbRows())->toBe(llbExpected($world));
});

it('walks only the learner given with --learner, soft-deleted ones included', function (): void {
    ['ann' => $ann, 'bob' => $bob] = llbWorld();
    $ann->delete();

    $this->artisan('learner_lexemes:backfill', ['--learner' => (string) $ann->id])
        ->expectsOutputToContain('Learners: 1 in 1 batches; lexemes confirmed: 2;')
        ->assertSuccessful();

    expect(DB::table('learner_lexemes')->where('learner_id', $bob->id)->whereNull('confirmed_on')->count())->toBe(1)
        ->and(DB::table('learner_lexemes')->where('learner_id', $ann->id)->where('confirmed_source', 'legacy')->count())->toBe(2);
});

it('rejects a bad batch or learner option', function (array $options): void {
    $this->artisan('learner_lexemes:backfill', $options)->assertExitCode(2);
})->with([
    'batch 0' => [['--batch' => 0]],
    'learner text' => [['--learner' => 'abc']],
    'learner 0' => [['--learner' => '0']],
]);
