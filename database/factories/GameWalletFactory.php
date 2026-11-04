<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GameWallet;
use App\Models\Learner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A wallet as a row. It carries a balance but no ledger entries: a test of the invariant `SUM(ledger) = balance` makes its coins
 * through WalletService (G2.5a).
 *
 * @extends Factory<GameWallet>
 */
class GameWalletFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'learner_id' => Learner::factory(),
            'balance' => 0,
            'earned_total' => 0,
            'spent_total' => 0,
        ];
    }

    public function withBalance(int $balance): static
    {
        return $this->state(fn (): array => ['balance' => $balance, 'earned_total' => $balance]);
    }
}
