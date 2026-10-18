<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->string('subscriber_type', 16);
            // Polymorphic subscriber (learners.id / organizations.id): no FK by design.
            $table->unsignedBigInteger('subscriber_id');
            $table->string('plan', 16);
            $table->string('status', 12);
            $table->string('provider', 16);
            $table->string('provider_ref', 191)->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('current_period_ends_at')->nullable();
            $table->timestamp('grace_until')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->integer('seats_limit')->nullable();
            $table->foreignId('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->jsonb('meta')->default('{}');
            $table->timestamps();

            $table->index(['subscriber_type', 'subscriber_id', 'status']);
            $table->index(['status', 'current_period_ends_at']);
            $table->index(['status', 'grace_until']);
            $table->index('granted_by_user_id');
        });

        // At most one "live" subscription per subscriber. On other drivers the
        // invariant is enforced by SubscriptionService under lockForUpdate.
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement(
                "CREATE UNIQUE INDEX subscriptions_one_live_per_subscriber
                 ON subscriptions (subscriber_type, subscriber_id)
                 WHERE status IN ('trialing', 'active', 'past_due')",
            );
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS subscriptions_one_live_per_subscriber');
        }

        Schema::dropIfExists('subscriptions');
    }
};
