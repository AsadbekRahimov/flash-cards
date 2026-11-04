<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The economy of the game (docs/18 §12.2.5, §12.2.6): the wallet of coins and its append-only ledger, the catalogue of items,
 * the inventory, the profile (tickets, equipped items), the ledger of tickets and the uses of boosters.
 *
 * `SUM(game_coin_ledger.amount) = game_wallets.balance` is an invariant kept by WalletService; the CHECKs
 * (PostgreSQL only) are the last line: no negative balance, no negative tickets, no zero entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_wallets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('learner_id')->unique()->constrained('learners')->cascadeOnDelete();
            $table->integer('balance')->default(0);
            $table->bigInteger('earned_total')->default(0);
            $table->bigInteger('spent_total')->default(0);
            $table->timestamps();
        });

        Schema::create('game_coin_ledger', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('learner_id')->constrained('learners')->cascadeOnDelete();
            $table->integer('amount'); // > 0 credit, < 0 debit
            $table->string('kind', 24);
            $table->string('idempotency_key', 96)->unique();
            $table->string('ref_type', 24)->nullable();
            $table->unsignedBigInteger('ref_id')->nullable();
            $table->integer('balance_after');
            $table->jsonb('meta')->default('{}');
            $table->timestamp('created_at')->useCurrent(); // append-only: no updated_at

            $table->index(['learner_id', 'created_at']);
            $table->index(['kind', 'created_at']);
        });

        Schema::create('game_items', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 48)->unique();
            $table->string('kind', 10); // cosmetic | booster
            $table->string('slot', 10)->nullable(); // camel | carpet | tent | frame: cosmetics only
            $table->string('effect', 16)->nullable(); // hint | extra_flask | second_chance: boosters only
            $table->integer('price_coins')->default(0); // 0: not for sale
            $table->string('availability', 12); // base | achievement | seasonal | exclusive
            $table->string('achievement_key', 32)->nullable();
            $table->unsignedBigInteger('season_id')->nullable(); // G4: no foreign key until `seasons` exists
            $table->string('status', 10)->default('draft'); // draft | published | archived
            $table->smallInteger('sort')->default(0);
            $table->jsonb('title'); // {ru, uz}
            $table->jsonb('description')->nullable(); // {ru, uz}
            $table->string('asset', 64)->nullable(); // a name from the whitelist of the Mini App assets, not a URL
            $table->timestamps();

            $table->index(['kind', 'status', 'sort']);
        });

        Schema::create('game_inventory', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('learner_id')->constrained('learners')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('game_items')->cascadeOnDelete();
            $table->integer('qty')->default(1); // boosters are used up, a cosmetic is always 1
            $table->timestamp('acquired_at');
            $table->string('source', 12); // purchase | achievement | admin | default
            $table->timestamps();

            $table->unique(['learner_id', 'item_id']);
        });

        Schema::create('game_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('learner_id')->unique()->constrained('learners')->cascadeOnDelete();
            $table->jsonb('equipped')->default('{}'); // {slot: item_id}
            $table->smallInteger('tickets')->default(0); // changed only under the lock of the learner's row
            $table->integer('tickets_earned_total')->default(0);
            $table->smallInteger('stops_cleared')->default(0);
            $table->smallInteger('cities_cleared')->default(0);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });

        Schema::create('game_ticket_ledger', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('learner_id')->constrained('learners')->cascadeOnDelete();
            $table->smallInteger('delta');
            $table->string('source', 12); // streak | referral | admin | spend
            $table->string('idempotency_key', 96)->unique();
            $table->foreignId('run_id')->nullable()->constrained('game_runs')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent(); // append-only: no updated_at

            $table->index(['learner_id', 'created_at']);
        });

        Schema::create('game_booster_uses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('learner_id')->constrained('learners')->cascadeOnDelete();
            $table->foreignId('run_id')->constrained('game_runs')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('game_items');
            $table->string('element_id', 16)->nullable(); // NULL: a booster of the whole run
            $table->timestamp('used_at');
            $table->date('week_start'); // the Monday of the learner's local week
            $table->timestamps();

            $table->index(['learner_id', 'week_start']);
            $table->unique(['run_id', 'item_id', 'element_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE game_wallets ADD CONSTRAINT game_wallets_balance_check CHECK (balance >= 0)');
            DB::statement('ALTER TABLE game_coin_ledger ADD CONSTRAINT game_coin_ledger_amount_check CHECK (amount <> 0)');
            DB::statement(
                "ALTER TABLE game_coin_ledger ADD CONSTRAINT game_coin_ledger_kind_check
                 CHECK (kind IN ('run_reward','boss_reward','daily_reward','class_reward','bazaar_hint','item_purchase','admin_grant','admin_revoke','refund'))",
            );
            DB::statement("ALTER TABLE game_items ADD CONSTRAINT game_items_kind_check CHECK (kind IN ('cosmetic','booster'))");
            DB::statement("ALTER TABLE game_items ADD CONSTRAINT game_items_availability_check CHECK (availability IN ('base','achievement','seasonal','exclusive'))");
            DB::statement("ALTER TABLE game_items ADD CONSTRAINT game_items_status_check CHECK (status IN ('draft','published','archived'))");
            DB::statement(
                "ALTER TABLE game_items ADD CONSTRAINT game_items_kind_shape_check
                 CHECK (((kind = 'cosmetic') = (slot IS NOT NULL)) AND ((kind = 'booster') = (effect IS NOT NULL)))",
            );
            DB::statement('ALTER TABLE game_inventory ADD CONSTRAINT game_inventory_qty_check CHECK (qty >= 0)');
            DB::statement('ALTER TABLE game_profiles ADD CONSTRAINT game_profiles_tickets_check CHECK (tickets >= 0)');
            DB::statement("ALTER TABLE game_ticket_ledger ADD CONSTRAINT game_ticket_ledger_source_check CHECK (source IN ('streak','referral','admin','spend'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('game_booster_uses');
        Schema::dropIfExists('game_ticket_ledger');
        Schema::dropIfExists('game_profiles');
        Schema::dropIfExists('game_inventory');
        Schema::dropIfExists('game_items');
        Schema::dropIfExists('game_coin_ledger');
        Schema::dropIfExists('game_wallets');
    }
};
