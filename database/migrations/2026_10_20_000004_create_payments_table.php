<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `payments` (docs/13 §1.2): the ledger. One row per provider event (charge / renewal /
 * refund); `(provider, provider_event_id)` unique is the idempotency key of every webhook.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_order_id')->nullable()->constrained('payment_orders')->nullOnDelete();
            $table->foreignId('provider_transaction_id')->nullable()->constrained('provider_transactions')->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->string('subscriber_type', 16);
            $table->unsignedBigInteger('subscriber_id');
            $table->string('provider', 16);
            $table->string('provider_event_id', 191);
            $table->string('provider_charge_id', 191)->nullable();
            $table->string('kind', 12);
            $table->string('status', 12);
            $table->unsignedBigInteger('amount');
            $table->string('currency', 8);
            $table->foreignId('refunded_payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->timestamp('period_starts_at')->nullable();
            $table->timestamp('period_ends_at')->nullable();
            $table->timestamp('occurred_at');
            $table->jsonb('raw')->default('{}');
            $table->timestamps();

            $table->unique(['provider', 'provider_event_id']);
            $table->index(['subscriber_type', 'subscriber_id', 'occurred_at']);
            $table->index(['status', 'occurred_at']);
            $table->index('subscription_id');
            $table->index('payment_order_id');
            $table->index('provider_charge_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
