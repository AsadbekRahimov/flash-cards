<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Game\Enums\LedgerKind;
use App\Models\GameCoinLedgerEntry;
use App\Models\Learner;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<GameCoinLedgerEntry> */
class GameCoinLedgerEntryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'learner_id' => Learner::factory(),
            'amount' => 10,
            'kind' => LedgerKind::RunReward,
            'idempotency_key' => 'test:'.Str::uuid(),
            'ref_type' => null,
            'ref_id' => null,
            'balance_after' => 10,
            'meta' => [],
        ];
    }
}
