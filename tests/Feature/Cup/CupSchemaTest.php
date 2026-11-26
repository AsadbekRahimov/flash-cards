<?php

declare(strict_types=1);

use App\Models\Course;
use App\Models\Cup;
use App\Models\CupDay;
use App\Models\CupParticipant;
use App\Models\TelegramGroup;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * docs/33 §2 (K4, K5): the tables of the cup roll back and forth on both drivers; on PostgreSQL the CHECKs hold the shapes and the
 * partial indexes of the scheduler exist.
 */

uses(RefreshDatabase::class);

const CUP_MIGRATIONS = [
    'database/migrations/2026_11_30_100001_add_battle_voided_to_cup_duel_end_reasons.php',
    'database/migrations/2026_11_29_100001_create_cup_duels_tables.php',
    'database/migrations/2026_11_28_100002_create_cup_rounds_tables.php',
    'database/migrations/2026_11_28_100001_create_cups_tables.php',
];

const CUP_TABLES = ['cups', 'cup_participants', 'cup_days', 'cup_day_scores', 'cup_messages', 'cup_round_attempts', 'cup_round_answers', 'cup_duels', 'cup_duel_slots'];

it('rolls the tables of the cup back and forth', function (): void {
    foreach (CUP_TABLES as $table) {
        expect(Schema::hasTable($table))->toBeTrue("{$table} missing");
    }

    foreach (CUP_MIGRATIONS as $path) {
        $this->artisan('migrate:rollback', ['--path' => $path])->assertSuccessful();
    }
    foreach (CUP_TABLES as $table) {
        expect(Schema::hasTable($table))->toBeFalse("{$table} survived the rollback");
    }

    $this->artisan('migrate')->assertSuccessful();
    foreach (CUP_TABLES as $table) {
        expect(Schema::hasTable($table))->toBeTrue("{$table} missing after the re-run");
    }
    expect(Schema::hasIndex('cups', 'cups_scheduled_start_idx'))->toBeTrue()
        ->and(Schema::hasIndex('cup_round_attempts', 'cup_round_attempts_active_expiry_idx'))->toBeTrue()
        ->and(Schema::hasIndex('battles', 'battles_cup_duel_id_unique'))->toBeTrue()
        ->and(Schema::hasColumns('battles', ['cup_duel_id', 'limit_exempt']))->toBeTrue();
    if (DB::getDriverName() === 'pgsql') {
        // The foreign key of `battles` went in NOT VALID and was validated; the CHECK of the reasons knows `battle_voided`.
        expect(DB::selectOne("SELECT convalidated FROM pg_constraint WHERE conname = 'battles_cup_duel_id_foreign'")?->convalidated)->toBeTrue()
            ->and((string) DB::selectOne("SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'cup_duels_end_reason_check'")?->def)->toContain('battle_voided');
    }
});

it('runs the migration of the duels again from any state a broken run leaves, both ways', function (): void {
    $voided = require base_path(CUP_MIGRATIONS[0]);
    $duels = require base_path(CUP_MIGRATIONS[1]);
    $voided->down();

    // Everything is there: up() again changes nothing and does not fail.
    $duels->up();
    // The tables are made, the link of `battles` is not (a run that broke off after the first step).
    DB::statement('DROP INDEX IF EXISTS battles_cup_duel_id_unique');
    Schema::table('battles', fn (Blueprint $table) => $table->dropForeign(['cup_duel_id']));
    Schema::table('battles', fn (Blueprint $table) => $table->dropColumn(['cup_duel_id', 'limit_exempt']));
    $duels->up();
    expect(Schema::hasColumns('battles', ['cup_duel_id', 'limit_exempt']))->toBeTrue()
        ->and(Schema::hasIndex('battles', 'battles_cup_duel_id_unique'))->toBeTrue();

    // On PostgreSQL: the column is there, its key is not.
    if (DB::getDriverName() === 'pgsql') {
        DB::statement('ALTER TABLE battles DROP CONSTRAINT battles_cup_duel_id_foreign');
        $duels->up();
        expect(DB::selectOne("SELECT convalidated FROM pg_constraint WHERE conname = 'battles_cup_duel_id_foreign'")?->convalidated)->toBeTrue();
    }

    // down() twice: the second meets nothing to drop.
    $duels->down();
    $duels->down();
    expect(Schema::hasTable('cup_duels'))->toBeFalse()
        ->and(Schema::hasColumn('battles', 'cup_duel_id'))->toBeFalse();

    $duels->up();
    $voided->up();
    expect(Schema::hasTable('cup_duels'))->toBeTrue()
        ->and(Schema::hasTable('cup_duel_slots'))->toBeTrue()
        ->and(Schema::hasColumns('battles', ['cup_duel_id', 'limit_exempt']))->toBeTrue()
        ->and(Schema::hasIndex('battles', 'battles_cup_duel_id_unique'))->toBeTrue();
});

it('keeps one open cup per chat as a fact of the database', function (): void {
    $cup = Cup::factory()->create();

    expect(fn () => Cup::factory()->create(['telegram_group_id' => $cup->telegram_group_id]))->toThrow(QueryException::class);
});

it('keeps one attempt per participant and day, one answer per task, one row per learner and cup', function (): void {
    $participant = CupParticipant::factory()->create();
    expect(fn () => DB::transaction(fn () => CupParticipant::factory()->create(['cup_id' => $participant->cup_id, 'learner_id' => $participant->learner_id])))->toThrow(QueryException::class);

    $day = CupDay::factory()->create(['cup_id' => $participant->cup_id]);
    $row = [
        'cup_id' => $participant->cup_id, 'cup_day_id' => $day->id, 'participant_id' => $participant->id, 'learner_id' => $participant->learner_id,
        'order' => '[]', 'state' => '{}', 'status' => 'active', 'locale' => 'ru', 'started_at' => now(), 'expires_at' => now()->addMinutes(10),
    ];
    DB::table('cup_round_attempts')->insert($row);
    expect(fn () => DB::transaction(fn () => DB::table('cup_round_attempts')->insert($row)))->toThrow(QueryException::class);
});

it('refuses rows of a wrong shape on PostgreSQL', function (string $table, Closure $row): void {
    expect(fn () => DB::table($table)->insert($row()))->toThrow(QueryException::class);
})->with([
    'a status out of the list' => ['cups', fn () => [...cupRow(), 'status' => 'paused']],
    'an open cup without active_group_id' => ['cups', fn () => [...cupRow(), 'active_group_id' => null]],
    'a finished cup holding the chat' => ['cups', fn () => [...cupRow(), 'status' => 'finished', 'starts_at' => now(), 'ends_at' => now()->addDay()]],
    'a canceled cup without a reason' => ['cups', fn () => [...cupRow(), 'status' => 'canceled', 'active_group_id' => null]],
    'counted days above the days' => ['cups', fn () => [...cupRow(), 'counted_days' => 8]],
    'a scheduled cup without its dates' => ['cups', fn () => [...cupRow(), 'status' => 'scheduled']],
    'a day of 21 round points' => ['cup_day_scores', fn () => dayScoreRow(['round_points' => 21])],
    'a day of 6 duel points' => ['cup_day_scores', fn () => dayScoreRow(['duel_points' => 6])],
])->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'The CHECK constraints are PostgreSQL only.');

it('sets the fillfactor of the tables updated by every answer on PostgreSQL', function (): void {
    $options = DB::table('pg_class')->whereIn('relname', ['cup_participants', 'cup_day_scores', 'cup_round_attempts'])->pluck('reloptions', 'relname')->all();

    expect($options['cup_participants'])->toContain('fillfactor=90')
        ->and($options['cup_day_scores'])->toContain('fillfactor=80')
        ->and($options['cup_round_attempts'])->toContain('fillfactor=80');
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'PostgreSQL only.');

/** @return array<string, mixed> */
function cupRow(): array
{
    $template = Cup::factory()->make();
    $group = TelegramGroup::factory()->create();

    return [
        ...collect($template->getAttributes())->except(['telegram_group_id', 'active_group_id', 'course_id'])->all(),
        'telegram_group_id' => $group->id,
        'active_group_id' => $group->id,
        'course_id' => Course::factory()->create()->id,
        'status' => 'draft',
        'meta' => '{}',
    ];
}

/** @param array<string, int> $points @return array<string, mixed> */
function dayScoreRow(array $points): array
{
    $participant = CupParticipant::factory()->create();

    return ['cup_id' => $participant->cup_id, 'participant_id' => $participant->id, 'day' => 1, ...$points];
}
