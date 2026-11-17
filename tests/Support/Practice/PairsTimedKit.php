<?php

declare(strict_types=1);

namespace Tests\Support\Practice;

use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Word;

/** What the tests of "Pairs against the clock" share: a learner who has met a number of words with a translation of their own. */
final class PairsTimedKit
{
    /**
     * @return array{0: Learner, 1: Course, 2: Lesson, 3: list<Word>}
     */
    public static function world(int $count = 8): array
    {
        [$course, $lesson] = PracticeKit::course();
        $learner = Learner::factory()->create();
        $words = PracticeKit::words($lesson, $count, $learner, state: 'review');
        foreach ($words as $i => $word) {
            $word->forceFill(['translation' => "перевод{$i}x{$word->id}"])->save();
        }

        return [$learner, $course, $lesson, $words];
    }
}
