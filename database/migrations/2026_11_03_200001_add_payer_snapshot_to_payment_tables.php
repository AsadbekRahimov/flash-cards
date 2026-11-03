<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The payer snapshot of `payment_orders` and `payments` (docs/03 §2.27, §2.29; migration 58): who paid, as the
 * learner (or organization) was at the time of the order / payment. `subscriber_id` alone stops saying anything
 * once the learner is deleted for good (docs/03 §8 rule 2) — the payment rows stay, the `learners` row does not.
 *
 * Backfill in the same file: from the learners (soft-deleted ones included — their payments are exactly the case)
 * and from the organizations, with correlated sub-selects that both drivers run.
 */
return new class extends Migration
{
    private const TABLES = ['payment_orders', 'payments'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->bigInteger('payer_telegram_user_id')->nullable();
                $blueprint->string('payer_name', 201)->nullable();
                $blueprint->string('payer_username', 100)->nullable();

                $blueprint->index('payer_telegram_user_id', "{$table}_payer_telegram_user_id_index");
            });

            // Constant SQL, no values from outside: the table name comes from the list above.
            DB::statement(
                "UPDATE {$table} SET
                    payer_telegram_user_id = (SELECT l.telegram_user_id FROM learners l WHERE l.id = {$table}.subscriber_id),
                    payer_name = (SELECT NULLIF(TRIM(COALESCE(l.first_name, '') || ' ' || COALESCE(l.last_name, '')), '') FROM learners l WHERE l.id = {$table}.subscriber_id),
                    payer_username = (SELECT l.username FROM learners l WHERE l.id = {$table}.subscriber_id)
                 WHERE subscriber_type = 'learner' AND payer_telegram_user_id IS NULL",
            );

            DB::statement(
                "UPDATE {$table} SET
                    payer_name = (SELECT o.name FROM organizations o WHERE o.id = {$table}.subscriber_id)
                 WHERE subscriber_type = 'organization' AND payer_name IS NULL",
            );
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->dropIndex("{$table}_payer_telegram_user_id_index");
                $blueprint->dropColumn(['payer_telegram_user_id', 'payer_name', 'payer_username']);
            });
        }
    }
};
