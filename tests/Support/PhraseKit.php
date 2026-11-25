<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Content\Services\CoursePackImporter;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Word;
use RuntimeException;

/**
 * The phrase pack of the tests (`tests/fixtures/phrases/english-phrases-test`, 12 phrases of one lesson, docs/35 §3.4) - NOT the content
 * of the release: invented texts in the format of the real packs.
 */
final class PhraseKit
{
    public const DIR = 'tests/fixtures/phrases/english-phrases-test';

    public const SLUG = 'english-phrases-test';

    /** @return array<string, mixed> the lesson file of the pack, decoded */
    public static function lessonFile(): array
    {
        return json_decode((string) file_get_contents(base_path(self::DIR.'/m01-l1.json')), true, 32, JSON_THROW_ON_ERROR);
    }

    /**
     * Imports the pack as the artisan command does and publishes the course.
     *
     * @return array{0: Course, 1: Lesson, 2: list<Word>}
     */
    public static function import(): array
    {
        $report = app(CoursePackImporter::class)->import(base_path(self::DIR), create: true);
        if ($report->errors !== [] || ! $report->ok()) {
            throw new RuntimeException('The phrase pack did not import: '.json_encode([$report->errors, array_map(fn ($r) => $r->errors, $report->files)]));
        }

        $course = Course::query()->where('slug', self::SLUG)->firstOrFail();
        $course->update(['status' => 'published', 'published_at' => now()]);
        $lesson = Lesson::query()->whereHas('stage', fn ($q) => $q->where('course_id', $course->id))->firstOrFail();

        return [$course->refresh(), $lesson, Word::query()->where('lesson_id', $lesson->id)->orderBy('id')->get()->all()];
    }
}
