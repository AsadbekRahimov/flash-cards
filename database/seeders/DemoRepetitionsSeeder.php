<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Learning\Enums\RepetitionState;
use App\Models\Learner;
use App\Models\TelegramGroup;
use App\Models\TrainingSession;
use App\Models\Word;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Database\Seeders\Concerns\GuardsDemoSeeding;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoRepetitionsSeeder extends Seeder
{
    use GuardsDemoSeeding;

    public function run(): void
    {
        if (! $this->demoSeedingAllowed()) {
            return;
        }

        $group = TelegramGroup::where('chat_id', -1001234567890)->firstOrFail();
        $learners = Learner::query()
            ->whereHas('memberships', fn ($q) => $q->where('telegram_group_id', $group->id)->where('status', 'active'))
            ->orderBy('id')
            ->get();
        $words = Word::query()->limit(40)->get();

        if ($learners->isEmpty() || $words->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($learners, $words): void {
            foreach ($learners as $i => $learner) {
                foreach ($words as $j => $word) {
                    $quality = ($i + $j) % 6;
                    $reps = $quality >= 3 ? min(5, ($i + $j) % 5 + 1) : 0;
                    $isHard = $quality <= 2;
                    $dueOffset = ($j % 3 === 0) ? -random_int(1, 24) : random_int(1, 14) * 24;

                    $repetition = WordRepetition::firstOrNew(['learner_id' => $learner->id, 'word_id' => $word->id]);
                    $repetition->fill([
                        'repetitions' => $reps,
                        'interval_days' => $reps === 0 ? 0 : min(30, $reps * 3),
                        'easiness_factor' => round(2.5 + ($quality - 3) * 0.1, 2),
                        'last_quality' => $quality,
                        'last_reviewed_at' => now()->subDays(random_int(0, 7)),
                        'next_review_at' => now()->addHours($dueOffset),
                        'is_hard' => $isHard,
                        // Failed-but-never-graduated rows are relearning (minute steps on next visit).
                        'state' => $reps >= 1 ? RepetitionState::Review : RepetitionState::Relearning,
                        'learning_step' => 0,
                        'lapses' => $reps >= 1 ? 0 : 1,
                        // History: the group's lessons introduced these words 8..30 days ago. Neither
                        // today's Free new-word quota (counted by created_at, outside group lessons)
                        // nor the demo learner's "Today" screen should be eaten by seed data.
                        'introduced_mode' => TrainingSession::MODE_GROUP_LESSON,
                        'meta' => [],
                    ]);
                    $repetition->created_at = CarbonImmutable::now()->subDays(8 + ($i * 7 + $j) % 23);
                    $repetition->save();
                }
            }
        });
    }
}
