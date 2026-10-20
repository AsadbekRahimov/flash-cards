<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Course> */
class CourseFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        static $counter = 0;
        $counter++;

        return [
            'slug' => "course-{$counter}",
            'owner_type' => 'platform',
            'owner_id' => null,
            'source' => 'platform',
            'title' => ['ru' => "Курс {$counter}", 'uz' => "Kurs {$counter}"],
            'description' => [],
            'target_language' => 'en',
            'level' => null,
            'exam_tag' => null,
            'visibility' => 'public',
            'status' => 'draft',
            'published_at' => null,
            'position' => 0,
            'is_featured' => false,
            'words_count' => 0,
            'meta' => [],
        ];
    }

    /** A learner's own deck (docs/13 §8.1): private, published at once. */
    public function deckOf(int $learnerId): static
    {
        return $this->state(fn (): array => [
            'owner_type' => 'learner',
            'owner_id' => $learnerId,
            'source' => 'learner',
            'visibility' => 'private',
            'status' => 'published',
            'published_at' => now(),
        ]);
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => 'published',
            'published_at' => now(),
        ]);
    }
}
