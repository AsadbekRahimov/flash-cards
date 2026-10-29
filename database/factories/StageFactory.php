<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Content\Services\CourseCatalogService;
use App\Models\Course;
use App\Models\Stage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Stage> */
class StageFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        static $counter = 0;
        $counter++;

        return [
            'course_id' => Course::factory(),
            'number' => $counter,
            'title' => "Module {$counter}",
            'description' => fake()->sentence(),
        ];
    }

    /** A module without a name of its own (`title` NULL): readers are shown "Модуль N" / "N-modul". */
    public function untitled(): static
    {
        return $this->state(fn (): array => ['title' => null]);
    }

    /** The stage lives in the platform `default` course (where teacher commands look for it when the group has no course). */
    public function inDefaultCourse(): static
    {
        return $this->state(fn (): array => [
            'course_id' => app(CourseCatalogService::class)->defaultCourse()->id,
        ]);
    }
}
