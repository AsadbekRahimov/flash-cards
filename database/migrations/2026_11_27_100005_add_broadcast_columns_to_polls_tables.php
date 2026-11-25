<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/35 O3, the broadcast (docs/03 §2.72, §2.74):
 *
 * `poll_deliveries.claimed_at` - the moment a worker last took the row:
 *  - `pending` + `claimed_at` = leased: a worker took the row into its batch (`FOR UPDATE SKIP LOCKED`), the message is NOT on its
 *    way yet. Other workers skip it until the lease runs out (`polls.lease_seconds`); a crashed worker's rows come back by
 *    themselves after that - never sent twice, never lost;
 *  - `sending` + `claimed_at` = the step `pending → sending` right before the Telegram call: `polls:sweep` turns such a row older
 *    than `polls.stuck_minutes` into `unknown` (the message may have gone out: no second send).
 * No index: every read of it goes through `poll_deliveries_unsent_idx` and filters there. It is in no index nor index predicate,
 * so the lease write is a HOT update.
 *
 * `polls.recipient_count` - the recipients inserted at launch, written once when the insert has finished: NULL on a `sending` poll
 * means the insert did not finish (the launcher died) and `polls:sweep` resumes it. Also the "получателей" of `/admin` without a count.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('poll_deliveries', function (Blueprint $table): void {
            $table->timestamp('claimed_at')->nullable();
        });

        Schema::table('polls', function (Blueprint $table): void {
            $table->integer('recipient_count')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('poll_deliveries', function (Blueprint $table): void {
            $table->dropColumn('claimed_at');
        });

        Schema::table('polls', function (Blueprint $table): void {
            $table->dropColumn('recipient_count');
        });
    }
};
