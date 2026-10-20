<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `payment_orders` (docs/13 §1.2): the intent to buy and the only source of the amount.
 * The provider only ever sees `token` (invoice_payload / account.order_id / merchant_trans_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('token', 32)->unique();
            $table->string('subscriber_type', 16);
            // Polymorphic subscriber (learners.id / organizations.id): no FK, like `subscriptions`.
            $table->unsignedBigInteger('subscriber_id');
            $table->string('plan', 16);
            $table->string('tier', 16)->nullable();
            $table->string('period', 8);
            $table->string('provider', 16);
            // Minor units (tiyin for UZS, whole Stars for XTR), after the discount.
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('list_amount');
            $table->string('currency', 8);
            $table->foreignId('offer_id')->nullable()->constrained('offers')->nullOnDelete();
            $table->string('status', 12)->default('pending');
            // The checkout URL / invoice link (not a transaction id); a Payme link carries base64 params and is ~250 chars (2A.4).
            $table->string('provider_ref', 2048)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->string('created_via', 16);
            $table->jsonb('meta')->default('{}');
            $table->timestamps();

            $table->index(['subscriber_type', 'subscriber_id', 'status']);
            $table->index(['status', 'expires_at']);
            $table->index('subscription_id');
            $table->index('offer_id');
        });

        // The back-reference FK. Not on sqlite: altering a table there rebuilds it and the rebuild
        // re-creates `offers_one_open_per_learner_kind` WITHOUT its partial predicate (sqlite is the
        // test driver only; PaymentApplier never writes a dangling id).
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('offers', function (Blueprint $table): void {
                $table->foreign('payment_order_id')->references('id')->on('payment_orders')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('offers', function (Blueprint $table): void {
                $table->dropForeign(['payment_order_id']);
            });
        }

        Schema::dropIfExists('payment_orders');
    }
};
