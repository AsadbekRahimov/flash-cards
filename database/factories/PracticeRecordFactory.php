<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Practice\Enums\PracticeMode;
use App\Models\Learner;
use App\Models\PracticeRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The personal best of a learner in a mode as a row (docs/16 §2.10); no session by default.
 *
 * @extends Factory<PracticeRecord>
 */
class PracticeRecordFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'learner_id' => Learner::factory(),
            'mode' => PracticeMode::PairsTimed,
            'best_score' => 20,
            'best_session_id' => null,
            'achieved_at' => now(),
        ];
    }
}
