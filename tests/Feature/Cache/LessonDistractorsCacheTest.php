<?php

declare(strict_types=1);

use App\Domain\Content\DTO\ResolvedTranslation;
use App\Domain\Content\Services\LessonContentRefresher;
use App\Domain\Learning\Enums\ExerciseType;
use App\Domain\Learning\Services\ExercisePromptBuilder;
use App\Domain\Learning\Services\LearningCache;
use App\Domain\Operations\Support\PureCache;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\QueryLog;

/*
 * The pool of distractors of a lesson (`lesson:{id}:distractors:v1`): plain rows of the first 41 active words, so a reverse /
 * audio card costs no query for its first layer, and the pool is dropped with the other keys of the lesson.
 */

uses(RefreshDatabase::class);

/** @return array{lesson: Lesson, words: list<Word>} */
function ldcLesson(int $words): array
{
    $lesson = Lesson::factory()->for(Stage::factory()->create())->create();
    /** @var list<Word> $list */
    $list = [];
    for ($i = 0; $i < $words; $i++) {
        $list[] = Word::factory()->for($lesson)->create(['word' => sprintf('w%03d', $i), 'translation' => sprintf('t%03d', $i)]);
    }

    return ['lesson' => $lesson, 'words' => $list];
}

/** @return list<string> */
function ldcOptions(Word $word, ExerciseType $type = ExerciseType::Reverse): array
{
    $prompt = app(ExercisePromptBuilder::class)->build($type, $word, new ResolvedTranslation('x', 'ru', false), 'ru', null, 11);

    return $prompt->options ?? [];
}

it('picks the same distractors from a warm pool as from an empty one, only from the first 40 other words by id', function (): void {
    $world = ldcLesson(50);
    $card = $world['words'][3];

    $cold = ldcOptions($card);
    $warm = ldcOptions($card);

    $allowed = [];
    foreach (array_slice($world['words'], 0, 41) as $w) {
        if ($w->id !== $card->id) {
            $allowed[] = $w->word;
        }
    }
    $foreign = array_values(array_diff($cold, [trim($card->word)]));

    expect($cold)->toHaveCount(4)
        ->and($warm)->toBe($cold)
        ->and($foreign)->toHaveCount(3)
        ->and(array_diff($foreign, array_slice($allowed, 0, 40)))->toBe([]);

    PureCache::store()->flush();
    expect(ldcOptions($card))->toBe($cold);
    expect(ldcOptions($world['words'][3], ExerciseType::Audio))->toHaveCount(4);
});

it('does not query for the lesson layer on the second card, and stores arrays only', function (): void {
    $world = ldcLesson(8);
    ldcOptions($world['words'][0]);

    $queries = QueryLog::during(fn () => ldcOptions($world['words'][1]));

    expect(QueryLog::matching($queries, '/["`]?words["`]?/'))->toBe([], 'no read of words')
        ->and(PureCache::store()->get("lesson:{$world['lesson']->id}:distractors:v1"))->toBeArray()->each->toBeArray();
});

it('drops the pool with the lesson, so an edit of a word shows', function (): void {
    $world = ldcLesson(6);
    $cache = app(LearningCache::class);
    $cache->lessonDistractorPool($world['lesson']->id);

    $world['words'][5]->update(['word' => 'edited']);
    expect(collect($cache->lessonDistractorPool($world['lesson']->id))->pluck('word')->all())->not->toContain('edited');

    app(LessonContentRefresher::class)->wordChanged($world['words'][5]);

    expect(collect($cache->lessonDistractorPool($world['lesson']->id))->pluck('word')->all())->toContain('edited');
});
