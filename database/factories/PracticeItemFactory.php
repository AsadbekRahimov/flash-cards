<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Practice\Enums\AudioStatus;
use App\Domain\Practice\Enums\PracticeItemStatus;
use App\Domain\Practice\Enums\PracticeItemType;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\PracticeItem;
use App\Models\PracticeItemVersion;
use App\Models\PracticeItemWord;
use App\Models\Stage;
use App\Models\Word;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * An authored task with a VALID version (docs/16 §2.2, §2.3, §4.5): the tests of the modes (18.2-18.5) take their
 * tasks from here instead of PracticeItemService.
 *
 *   PracticeItem::factory()->sentence()->published()->forLesson($lesson)->targeting($words)->create();
 *   PracticeItem::factory()->dictation()->published()->audioReady()->forCourse($course)->targeting($word)->create();
 *
 * `create()` writes the row, then ONE version (number 1) of the chosen stage with the content of the type, points
 * `current_version_id` / `published_version_id` / `status` at it the way PracticeItemService does, and fills
 * `practice_item_words`. The defaults: a collocation, a draft, no target words, in a new published course.
 * {@see bare()} writes only the row. `make()` writes nothing but the model.
 *
 * @extends Factory<PracticeItem>
 */
class PracticeItemFactory extends Factory
{
    private ?PracticeItemBuild $build = null;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        static $counter = 0;
        $counter++;

        return [
            'course_id' => Course::factory()->published(),
            'lesson_id' => null,
            'type' => PracticeItemType::Collocation,
            'key' => "item-{$counter}",
            'level' => 'A1',
            'status' => PracticeItemStatus::Draft,
            'current_version_id' => null,
            'published_version_id' => null,
            'created_by_user_id' => null,
            'archived_at' => null,
        ];
    }

    public function collocation(): static
    {
        return $this->state(['type' => PracticeItemType::Collocation]);
    }

    public function sentence(): static
    {
        return $this->state(['type' => PracticeItemType::Sentence]);
    }

    public function dictation(): static
    {
        return $this->state(['type' => PracticeItemType::Dictation]);
    }

    /** The task of a course (a model or an id). */
    public function forCourse(Course|int $course): static
    {
        return $this->state(['course_id' => $course instanceof Course ? $course->id : $course]);
    }

    /** The task of a lesson (and of the course of that lesson). */
    public function forLesson(Lesson $lesson): static
    {
        return $this->state(fn (): array => [
            'course_id' => (int) Stage::query()->whereKey($lesson->stage_id)->value('course_id'),
            'lesson_id' => $lesson->id,
        ]);
    }

    /** The version is a draft (the default). */
    public function draft(): static
    {
        return $this->building($this->currentBuild()->atStage(PracticeItemStatus::Draft));
    }

    /** The version is marked as reviewed ("Проверено"). */
    public function reviewed(): static
    {
        return $this->building($this->currentBuild()->atStage(PracticeItemStatus::Reviewed));
    }

    /** The version is published and handed to the learners (`published_version_id`). */
    public function published(): static
    {
        return $this->building($this->currentBuild()->atStage(PracticeItemStatus::Published));
    }

    /** Was published, then archived: `published_version_id` stays, but nothing is handed out. */
    public function archived(): static
    {
        return $this->building($this->currentBuild()->atStage(PracticeItemStatus::Archived));
    }

    /** Only the row: no version (the version factory uses it for the task it belongs to). */
    public function bare(): static
    {
        return $this->building($this->currentBuild()->asBare());
    }

    /**
     * Takes the keys of `$content` over the default content of the type (see PracticeItemVersionFactory::withContent).
     *
     * @param  array<string, mixed>  $content
     */
    public function withContent(array $content): static
    {
        return $this->building($this->currentBuild()->withContent($content));
    }

    /** A dictation whose audio is ready (the file is expected on the audio disk). */
    public function audioReady(): static
    {
        return $this->building($this->currentBuild()->withAudio(AudioStatus::Ready));
    }

    public function audioPending(): static
    {
        return $this->building($this->currentBuild()->withAudio(AudioStatus::Pending));
    }

    public function audioFailed(): static
    {
        return $this->building($this->currentBuild()->withAudio(AudioStatus::Failed));
    }

    /**
     * The target words of the task (docs/16 §2.4). Add up over calls.
     *
     * @param  Word|int|iterable<Word|int>  $words
     */
    public function targeting(Word|int|iterable $words): static
    {
        $list = $words instanceof Word || is_int($words) ? [$words] : $words;
        $ids = [];
        foreach ($list as $word) {
            $ids[] = $word instanceof Word ? $word->id : $word;
        }

        return $this->building($this->currentBuild()->withWords($ids));
    }

    /** @param array<string, mixed> $arguments */
    protected function newInstance(array $arguments = []): static
    {
        $new = parent::newInstance($arguments);
        $new->build = $this->build;

        return $new;
    }

    /** @param Collection<int, Model> $instances */
    protected function callAfterCreating(Collection $instances, ?Model $parent = null): void
    {
        foreach ($instances as $item) {
            if ($item instanceof PracticeItem) {
                $this->buildAround($item);
            }
        }

        parent::callAfterCreating($instances, $parent);
    }

    private function buildAround(PracticeItem $item): void
    {
        $build = $this->currentBuild();

        if ($build->words !== []) {
            PracticeItemWord::query()->insert(array_map(
                static fn (int $wordId): array => ['item_id' => $item->id, 'word_id' => $wordId],
                array_values(array_unique($build->words)),
            ));
        }

        if ($build->bare) {
            return;
        }

        $version = $this->versionFactory($item->type, $build)->createOne(['item_id' => $item->id]);

        $pointers = ['current_version_id' => $version->id, 'status' => $build->stage];
        if ($build->stage === PracticeItemStatus::Published || $build->stage === PracticeItemStatus::Archived) {
            $pointers['published_version_id'] = $version->id;
        }
        if ($build->stage === PracticeItemStatus::Archived) {
            $pointers['archived_at'] = now();
        }

        $item->forceFill($pointers)->save();
    }

    private function versionFactory(PracticeItemType $type, PracticeItemBuild $build): PracticeItemVersionFactory
    {
        $factory = match ($type) {
            PracticeItemType::Collocation => PracticeItemVersion::factory()->collocation(),
            PracticeItemType::Sentence => PracticeItemVersion::factory()->sentence(),
            PracticeItemType::Dictation => PracticeItemVersion::factory()->dictation(),
        };

        $factory = match ($build->stage) {
            PracticeItemStatus::Draft => $factory,
            PracticeItemStatus::Reviewed => $factory->reviewed(),
            PracticeItemStatus::Published, PracticeItemStatus::Archived => $factory->published(),
        };

        if ($build->content !== []) {
            $factory = $factory->withContent($build->content);
        }

        if ($type === PracticeItemType::Dictation && $build->audio !== null) {
            $factory = match ($build->audio) {
                AudioStatus::Ready => $factory->audioReady(),
                AudioStatus::Failed => $factory->audioFailed(),
                default => $factory->audioPending(),
            };
        }

        return $factory;
    }

    private function currentBuild(): PracticeItemBuild
    {
        return $this->build ?? new PracticeItemBuild;
    }

    private function building(PracticeItemBuild $build): static
    {
        $new = $this->newInstance();
        $new->build = $build;

        return $new;
    }
}
