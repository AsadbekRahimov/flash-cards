<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Student;
use App\Models\TelegramGroup;
use App\Models\Word;
use App\Models\WordRepetition;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoRepetitionsSeeder extends Seeder
{
    public function run(): void
    {
        $group = TelegramGroup::where('chat_id', -1001234567890)->firstOrFail();
        $students = Student::where('telegram_group_id', $group->id)->get();
        $words = Word::query()->limit(40)->get();

        if ($students->isEmpty() || $words->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($students, $words): void {
            foreach ($students as $i => $student) {
                foreach ($words as $j => $word) {
                    $quality = ($i + $j) % 6;
                    $reps = $quality >= 3 ? min(5, ($i + $j) % 5 + 1) : 0;
                    $isHard = $quality <= 2;
                    $dueOffset = ($j % 3 === 0) ? -random_int(1, 24) : random_int(1, 14) * 24;

                    WordRepetition::updateOrCreate(
                        ['student_id' => $student->id, 'word_id' => $word->id],
                        [
                            'repetitions' => $reps,
                            'interval_days' => $reps === 0 ? 0 : min(30, $reps * 3),
                            'easiness_factor' => round(2.5 + ($quality - 3) * 0.1, 2),
                            'last_quality' => $quality,
                            'last_reviewed_at' => now()->subDays(random_int(0, 7)),
                            'next_review_at' => now()->addHours($dueOffset),
                            'is_hard' => $isHard,
                            'meta' => [],
                        ],
                    );
                }
            }
        });
    }
}
