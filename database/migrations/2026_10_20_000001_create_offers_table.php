<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Personal discounts (docs/13 §1.2 `offers`): B2B -> B2C bridge (`post_org_discount`),
 * later win-back / campaign offers. Created before `payment_orders` because the order
 * references the applied offer; the back-reference `offers.payment_order_id` gets its
 * FK in the payment_orders migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('learner_id')->constrained('learners')->cascadeOnDelete();
            $table->string('kind', 24);
            $table->string('plan', 16)->nullable();
            // NULL = any period.
            $table->string('period', 8)->nullable();
            $table->unsignedSmallInteger('discount_pct');
            $table->timestamp('expires_at');
            $table->timestamp('redeemed_at')->nullable();
            // FK -> payment_orders added by 2026_10_20_000002 (the table does not exist yet; PG only, see there).
            $table->unsignedBigInteger('payment_order_id')->nullable();
            $table->foreignId('source_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->jsonb('meta')->default('{}');
            $table->timestamps();

            $table->index(['learner_id', 'kind', 'expires_at']);
            $table->index('payment_order_id');
        });

        // One open offer per (learner, kind). On other drivers OfferService checks under the learner lock.
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement(
                'CREATE UNIQUE INDEX offers_one_open_per_learner_kind
                 ON offers (learner_id, kind)
                 WHERE redeemed_at IS NULL',
            );
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS offers_one_open_per_learner_kind');
        }

        Schema::dropIfExists('offers');
    }
};
