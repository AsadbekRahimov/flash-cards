<?php

declare(strict_types=1);

use App\Domain\Practice\Enums\AudioStatus;
use App\Domain\Practice\Enums\PracticeItemStatus;
use App\Domain\Practice\Enums\PracticeItemType;
use App\Domain\Practice\Enums\VersionState;
use App\Domain\Practice\Support\PracticeTextNormalizer;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\PracticeItem;
use App\Models\PracticeItemVersion;
use App\Models\PracticeItemWord;
use App\Models\Stage;
use App\Models\Word;
use Database\Factories\PracticeItemVersionFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * The factories the tests of the modes (18.2-18.5) use INSTEAD of PracticeItemService (docs/16 §15, "Уточнения"): a task with a
 * VALID version of its type, the pointers of the task set the way the service sets them, the target words.
 */

uses(RefreshDatabase::class);

function factoryVersionOf(PracticeItem $item, ?int $id = null): PracticeItemVersion
{
    return PracticeItemVersion::query()->findOrFail($id ?? $item->current_version_id);
}

it('creates a draft collocation with one valid version by default', function (): void {
    $item = PracticeItem::factory()->create();
    $version = factoryVersionOf($item);

    expect($item->type)->toBe(PracticeItemType::Collocation)
        ->and($item->status)->toBe(PracticeItemStatus::Draft)
        ->and($item->published_version_id)->toBeNull()
        ->and($item->archived_at)->toBeNull()
        ->and($item->level)->toBe('A1')
        ->and($version->item_id)->toBe($item->id)
        ->and($version->number)->toBe(1)
        ->and($version->state)->toBe(VersionState::Draft)
        ->and($version->audio_status)->toBe(AudioStatus::None)
        ->and($version->audio_key)->toBeNull()
        ->and($item->course?->status)->toBe('published')
        ->and(PracticeItemVersion::query()->count())->toBe(1);
});

it('points the task at its published version the way PracticeItemService does', function (): void {
    $item = PracticeItem::factory()->sentence()->published()->create();
    $version = factoryVersionOf($item);

    expect($item->status)->toBe(PracticeItemStatus::Published)
        ->and($item->current_version_id)->toBe($item->published_version_id)
        ->and($item->archived_at)->toBeNull()
        ->and($version->state)->toBe(VersionState::Published)
        ->and($version->published_at)->not->toBeNull()
        ->and($version->content)->toHaveKeys(['prompt', 'fragments', 'accepted', 'display', 'explanation']);

    $reviewed = PracticeItem::factory()->reviewed()->create();
    expect($reviewed->status)->toBe(PracticeItemStatus::Reviewed)
        ->and($reviewed->published_version_id)->toBeNull()
        ->and(factoryVersionOf($reviewed)->state)->toBe(VersionState::Reviewed)
        ->and(factoryVersionOf($reviewed)->reviewed_at)->not->toBeNull();

    // An archived task keeps its published version, but is not handed out.
    $archived = PracticeItem::factory()->archived()->create();
    expect($archived->status)->toBe(PracticeItemStatus::Archived)
        ->and($archived->archived_at)->not->toBeNull()
        ->and($archived->published_version_id)->toBe($archived->current_version_id);
});

it('gives each type the content of its type, and a dictation a file name and an audio status', function (): void {
    $collocation = PracticeItem::factory()->collocation()->published()->create();
    $sentence = PracticeItem::factory()->sentence()->published()->create();
    $dictation = PracticeItem::factory()->dictation()->published()->create();

    expect(factoryVersionOf($collocation)->content)->toHaveKeys(['text', 'options', 'correct', 'translation', 'explanation'])
        ->and(factoryVersionOf($sentence)->content)->toHaveKeys(['prompt', 'fragments', 'accepted'])
        ->and(factoryVersionOf($dictation)->content)->toHaveKeys(['text', 'accepted', 'translation'])
        ->and(factoryVersionOf($dictation)->audio_status)->toBe(AudioStatus::Pending)
        ->and(factoryVersionOf($dictation)->audio_key)->toMatch('/\A[0-9a-f]{40}\z/')
        ->and(factoryVersionOf($collocation)->audio_key)->toBeNull();

    $ready = PracticeItem::factory()->dictation()->published()->audioReady()->create();
    $failed = PracticeItem::factory()->dictation()->published()->audioFailed()->create();
    expect(factoryVersionOf($ready)->audio_status)->toBe(AudioStatus::Ready)
        ->and(factoryVersionOf($ready)->audio_generated_at)->not->toBeNull()
        ->and(factoryVersionOf($failed)->audio_status)->toBe(AudioStatus::Failed)
        ->and(factoryVersionOf($failed)->audio_error)->not->toBeNull();
});

it('puts a task into a course or a lesson, and links its target words (models, ids, lists)', function (): void {
    $course = Course::factory()->published()->create();
    $lesson = Lesson::factory()->for(Stage::factory()->create(['course_id' => $course->id]))->create();
    [$one, $two, $three] = [Word::factory()->create(), Word::factory()->create(), Word::factory()->create()];

    $inLesson = PracticeItem::factory()->forLesson($lesson)->targeting($one)->targeting([$two->id, $three])->create();
    $inCourse = PracticeItem::factory()->forCourse($course)->targeting($one->id)->create();

    expect($inLesson->course_id)->toBe($course->id)
        ->and($inLesson->lesson_id)->toBe($lesson->id)
        ->and($inLesson->words()->pluck('words.id')->sort()->values()->all())->toBe([$one->id, $two->id, $three->id])
        ->and($inCourse->course_id)->toBe($course->id)
        ->and($inCourse->lesson_id)->toBeNull()
        ->and($inCourse->words()->pluck('words.id')->all())->toBe([$one->id])
        ->and(PracticeItemWord::query()->count())->toBe(4);
});

it('takes the keys of withContent over the content of the type', function (): void {
    $item = PracticeItem::factory()->collocation()->withContent(['text' => 'We ___ English.', 'options' => ['speak', 'say', 'tell'], 'correct' => [0]])->create();
    $content = factoryVersionOf($item)->content;

    expect($content['text'])->toBe('We ___ English.')
        ->and($content['options'])->toBe(['speak', 'say', 'tell'])
        ->and($content)->toHaveKeys(['translation', 'explanation']);
});

it('writes only the row for bare(), and nothing at all for make()', function (): void {
    $bare = PracticeItem::factory()->bare()->create();
    expect($bare->current_version_id)->toBeNull()
        ->and(PracticeItemVersion::query()->count())->toBe(0);

    $made = PracticeItem::factory()->published()->make();
    expect($made->exists)->toBeFalse()
        ->and(PracticeItem::query()->count())->toBe(1);
});

it('makes a version on its own with the content of the type, in a task of that type', function (): void {
    $version = PracticeItemVersion::factory()->dictation()->audioReady()->create();

    expect($version->item?->type)->toBe(PracticeItemType::Dictation)
        ->and($version->item?->current_version_id)->toBeNull()
        ->and($version->audio_status)->toBe(AudioStatus::Ready)
        ->and($version->content)->toHaveKey('text')
        ->and(PracticeItemVersion::factory()->sentence()->published()->create()->state)->toBe(VersionState::Published)
        ->and(PracticeItemVersion::factory()->superseded()->create()->superseded_at)->not->toBeNull();
});

it('makes many tasks, each with its own key and version', function (): void {
    $course = Course::factory()->published()->create();
    $items = PracticeItem::factory()->count(4)->sentence()->published()->forCourse($course)->create();

    expect($items->pluck('key')->unique())->toHaveCount(4)
        ->and($items->pluck('published_version_id')->unique())->toHaveCount(4)
        ->and(PracticeItemVersion::query()->count())->toBe(4);
});

it('writes contents that pass the rules of docs/16 §4.5', function (PracticeItemType $type, int $sample): void {
    $content = PracticeItemVersionFactory::contentFor($type, $sample);
    $normalize = PracticeTextNormalizer::normalize(...);

    if ($type === PracticeItemType::Sentence) {
        $fragments = $content['fragments'];
        expect(count($fragments))->toBeBetween((int) config('practice.content.sentence_fragments.min'), (int) config('practice.content.sentence_fragments.max'))
            ->and(count($content['accepted']))->toBeBetween(1, (int) config('practice.content.sentence_accepted_max'));
        foreach ($content['accepted'] as $sequence) {
            $sorted = $sequence;
            sort($sorted);
            expect($sorted)->toBe(range(0, count($fragments) - 1));   // a permutation of ALL the fragments
        }
        $first = implode(' ', array_map(static fn (int $i): string => $fragments[$i], $content['accepted'][0]));
        expect($normalize($content['display']))->toBe($normalize($first))
            ->and($content['prompt'])->toHaveKeys(['ru', 'uz'])
            ->and($content['explanation'])->toHaveKeys(['ru', 'uz']);
    }

    if ($type === PracticeItemType::Collocation) {
        $options = array_map($normalize, $content['options']);
        expect(substr_count($content['text'], '___'))->toBe(1)
            ->and($content['options'])->toHaveCount((int) config('practice.content.collocation_options'))
            ->and(array_unique($options))->toHaveCount(3)
            ->and($content['correct'])->not->toBeEmpty()->and(count($content['correct']))->toBeLessThan(3)
            ->and(array_diff($content['correct'], [0, 1, 2]))->toBe([])
            ->and($content['translation'])->toHaveKeys(['ru', 'uz'])
            ->and($content['explanation'])->toHaveKeys(['ru', 'uz']);
    }

    if ($type === PracticeItemType::Dictation) {
        expect($content['accepted'][0])->toBe($content['text'])
            ->and(mb_strlen($content['text']))->toBeLessThanOrEqual((int) config('practice.content.dictation_text_max'))
            ->and($content['translation'])->toHaveKeys(['ru', 'uz']);
    }
})->with(function (): Generator {
    foreach (PracticeItemType::cases() as $type) {
        foreach (range(0, 5) as $sample) {
            yield "{$type->value} #{$sample}" => [$type, $sample];
        }
    }
});
