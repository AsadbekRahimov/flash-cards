<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Game\Enums\TicketSource;
use App\Models\GameTicketLedgerEntry;
use App\Models\Learner;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<GameTicketLedgerEntry> */
class GameTicketLedgerEntryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'learner_id' => Learner::factory(),
            'delta' => 1,
            'source' => TicketSource::Streak,
            'idempotency_key' => 'test:'.Str::uuid(),
            'run_id' => null,
        ];
    }
}
