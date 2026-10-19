<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `provider_transactions` (docs/13 §1.2): the provider-side state of one transaction
 * (Payme `params.id`, Click `click_trans_id`); one row per provider transaction id.
 * Not used by Telegram Stars. For Click the row id doubles as `merchant_prepare_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_transactions', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 16);
            $table->string('provider_transaction_id', 64);
            $table->foreignId('payment_order_id')->constrained('payment_orders')->restrictOnDelete();
            // Payme: 1 created, 2 performed, -1 canceled before perform, -2 canceled after; Click: 1 prepared, 2 completed, -1 canceled.
            $table->smallInteger('state');
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('provider_time')->nullable();
            $table->timestamp('created_at_provider')->nullable();
            $table->timestamp('performed_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->smallInteger('cancel_reason')->nullable();
            $table->jsonb('raw')->default('{}');
            $table->jsonb('history')->default('[]');
            $table->timestamps();

            $table->unique(['provider', 'provider_transaction_id']);
            $table->index('payment_order_id');
            $table->index(['provider', 'state', 'created_at_provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_transactions');
    }
};
