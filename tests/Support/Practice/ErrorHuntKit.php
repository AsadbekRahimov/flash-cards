<?php

declare(strict_types=1);

namespace Tests\Support\Practice;

use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\PracticeItem;
use App\Models\PracticeSession;
use App\Models\Word;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;

/**
 * What the tests of "find the mistake" (docs/16 §5.7) share: published tasks whose texts name their number (`Task n: we wrongN it here.`
 * with `wrongN` the wrong word and `rightN` its right correction), a lesson with a learner who has met their words, and the HTTP steps.
 */
final class ErrorHuntKit
{
    /**
     * The stored content of the task number `$n`: five words, the third one wrong.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function content(int $n, array $overrides = []): array
    {
        return [
            'text' => "Task {$n}: we wrong{$n} it here.",
            'error_index' => 3,
            'error' => "wrong{$n}",
            'options' => ["right{$n}", "badA{$n}", "badB{$n}"],
            'correct' => [0],
            'translation' => ['ru' => "Перевод {$n}", 'uz' => "Tarjima {$n}"],
            'explanation' => ['ru' => "Пояснение {$n}", 'uz' => "Izoh {$n}"],
            ...$overrides,
        ];
    }

    /** @param  array<string, mixed>  $overrides */
    public static function task(Lesson $lesson, Word $target, int $n, array $overrides = []): PracticeItem
    {
        return PracticeItem::factory()->errorHunt()->published()->forLesson($lesson)->targeting($target)->withContent(self::content($n, $overrides))->create();
    }

    /** @return array{0: Learner, 1: Course, 2: Lesson, 3: list<PracticeItem>} */
    public static function world(int $tasks = 5, string $locale = 'ru', string $slug = 'english-a1'): array
    {
        [$course, $lesson] = PracticeKit::course($slug);
        $learner = Learner::factory()->create(['interface_locale' => $locale]);

        $items = [];
        foreach (PracticeKit::words($lesson, $tasks, $learner) as $i => $word) {
            $items[] = self::task($lesson, $word, $i + 1);
        }

        return [$learner, $course, $lesson, $items];
    }

    /** The number n of a task, out of its text `Task n: …`. */
    public static function number(string $text): int
    {
        preg_match('/^Task (\d+):/', $text, $m);

        return (int) ($m[1] ?? 0);
    }

    public static function open(Learner $learner, Course $course, ?Lesson $lesson = null): TestResponse
    {
        return test()->withHeaders(PracticeKit::headers($learner))->postJson('/api/twa/practice/sessions', array_filter([
            'mode' => 'error_hunt', 'course' => $course->slug, 'lesson_id' => $lesson?->id,
        ]));
    }

    /** @param  array<string, mixed>  $answer */
    public static function answer(Learner $learner, int $sessionId, string $elementId, array $answer, ?string $requestId = null): TestResponse
    {
        return test()->withHeaders(PracticeKit::headers($learner))->postJson("/api/twa/practice/sessions/{$sessionId}/answer", [
            'request_id' => $requestId ?? (string) Str::uuid(), 'element_id' => $elementId, 'answer' => $answer,
        ]);
    }

    public static function get(Learner $learner, int $sessionId): TestResponse
    {
        return test()->withHeaders(PracticeKit::headers($learner))->getJson("/api/twa/practice/sessions/{$sessionId}");
    }

    /**
     * The element of the snapshot of the session as the SERVER keeps it.
     *
     * @return array<string, mixed>
     */
    public static function stored(int $sessionId, string $elementId): array
    {
        foreach (PracticeSession::query()->findOrFail($sessionId)->snapshot['elements'] as $element) {
            if ($element['id'] === $elementId) {
                return $element;
            }
        }

        throw new RuntimeException("No element {$elementId} in the session {$sessionId}.");
    }

    /**
     * A word of the stored element: the wrong one, or one that is not.
     *
     * @param  array<string, mixed>  $stored
     */
    public static function token(array $stored, bool $wrong = true): string
    {
        foreach ($stored['tokens'] as $token) {
            if (($token['id'] === $stored['error_id']) === $wrong) {
                return $token['id'];
            }
        }

        throw new RuntimeException('No such word.');
    }

    /**
     * A correction of the stored element: the right one, or a wrong one.
     *
     * @param  array<string, mixed>  $stored
     */
    public static function option(array $stored, bool $right = true): string
    {
        foreach ($stored['options'] as $option) {
            if (in_array($option['id'], $stored['correct_ids'], true) === $right) {
                return $option['id'];
            }
        }

        throw new RuntimeException('No such option.');
    }
}
