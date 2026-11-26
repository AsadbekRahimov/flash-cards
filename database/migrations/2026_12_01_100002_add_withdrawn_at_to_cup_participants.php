<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `cup_participants.withdrawn_at` (owner's decision of 27.11.2026, docs/33 §11.12): the participant left the cup HIMSELF (the button
 * «Выйти из кубка»). The status is `left`, as for one who left the chat - his points stay in the table with the mark - but unlike him
 * no "member" of Telegram brings him back (`CupMembershipGate`, the join), and he no longer sees the cup.
 *
 * A nullable column without a default: on PostgreSQL a change of the catalog only (no rewrite, a moment of ACCESS EXCLUSIVE). The CHECK
 * `withdrawn_at IS NULL OR status = 'left'` goes in `NOT VALID` with the column and is validated in a transaction of its own (`SHARE
 * UPDATE EXCLUSIVE`: the writes of the participants go on) - hence `$withinTransaction = false`. Restartable: `IF NOT EXISTS` /
 * `IF EXISTS`, `VALIDATE` repeats harmlessly. `down()` drops the CHECK and the column (a withdrawn participant stays `left`).
 */
return new class extends Migration
{
    /** @var bool */
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            if (! Schema::hasColumn('cup_participants', 'withdrawn_at')) {
                Schema::table('cup_participants', function (Blueprint $table): void {
                    $table->timestamp('withdrawn_at')->nullable();
                });
            }

            return;
        }

        DB::transaction(function (): void {
            DB::statement('ALTER TABLE cup_participants ADD COLUMN IF NOT EXISTS withdrawn_at timestamp(0) without time zone NULL');
            DB::statement('ALTER TABLE cup_participants DROP CONSTRAINT IF EXISTS cup_participants_withdrawn_check');
            DB::statement("ALTER TABLE cup_participants ADD CONSTRAINT cup_participants_withdrawn_check CHECK (withdrawn_at IS NULL OR status = 'left') NOT VALID");
        });
        DB::statement('ALTER TABLE cup_participants VALIDATE CONSTRAINT cup_participants_withdrawn_check');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE cup_participants DROP CONSTRAINT IF EXISTS cup_participants_withdrawn_check');
            DB::statement('ALTER TABLE cup_participants DROP COLUMN IF EXISTS withdrawn_at');

            return;
        }
        if (Schema::hasColumn('cup_participants', 'withdrawn_at')) {
            Schema::table('cup_participants', function (Blueprint $table): void {
                $table->dropColumn('withdrawn_at');
            });
        }
    }
};
