<?php

declare(strict_types=1);

use App\Domain\Practice\Enums\BattleStatus;
use App\Models\Battle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `battles.open_pair` (docs/16 §2.7, §8.2; 18.6-fix): "<lower learner id>:<higher learner id>" while the battle is not over, NULL
 * after any end - UNIQUE, so "one battle that is not over per pair of players" is a fact of the database, the way `active_learner_id`
 * holds "one active battle per learner" and `challenges.open_pair` holds one open challenge per pair. Two invitations that cross in
 * the same instant (both friends press "Rematch") can no longer both go through: the second INSERT fails on the index and answers
 * 409 `battle_busy` with the id of the first, whatever the gap between them.
 *
 * On PostgreSQL a CHECK keeps a battle that is over from holding the pair. The rows that exist are back-filled: the earliest
 * battle of a pair that is not over takes the key; a later one of the same pair (a crossing that slipped through) is left NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('battles', function (Blueprint $table): void {
            $table->string('open_pair', 64)->nullable()->unique()->after('friendship_id');
        });

        $taken = [];
        Battle::query()
            ->whereNotIn('status', [BattleStatus::Finished->value, BattleStatus::Closed->value])
            ->orderBy('id')
            ->lazyById(200, 'id')
            ->each(function (Battle $battle) use (&$taken): void {
                $key = Battle::pairKey($battle->challenger_id, $battle->opponent_id);
                if (isset($taken[$key])) {
                    return;
                }
                $taken[$key] = true;
                Battle::query()->whereKey($battle->id)->update(['open_pair' => $key]);
            });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE battles ADD CONSTRAINT battles_open_pair_shape_check CHECK (open_pair IS NULL OR status NOT IN ('finished','closed'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE battles DROP CONSTRAINT IF EXISTS battles_open_pair_shape_check');
        }

        Schema::table('battles', function (Blueprint $table): void {
            $table->dropUnique(['open_pair']);
        });
        Schema::table('battles', function (Blueprint $table): void {
            $table->dropColumn('open_pair');
        });
    }
};
