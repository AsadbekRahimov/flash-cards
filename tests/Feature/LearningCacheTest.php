<?php

declare(strict_types=1);

use App\Domain\Learning\Services\LearningCache;
use App\Models\ExamResult;
use App\Models\ExamSession;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\TelegramGroup;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('cache.default', 'array');
    Cache::flush();
});

it('caches lesson word counts until the lesson cache is cleared', function (): void {
    $lesson = Lesson::factory()->for(Stage::factory())->create();
    Word::factory()->count(2)->create(['lesson_id' => $lesson->id]);

    $cache = app(LearningCache::class);

    expect($cache->lessonWordCount((int) $lesson->id))->toBe(2);

    Word::factory()->create(['lesson_id' => $lesson->id]);

    expect($cache->lessonWordCount((int) $lesson->id))->toBe(2);

    $cache->forgetLesson($lesson);

    expect($cache->lessonWordCount((int) $lesson->id))->toBe(3);
});

it('caches exam leaderboards until the exam cache is cleared', function (): void {
    $group = TelegramGroup::factory()->create();
    $session = ExamSession::factory()->create([
        'telegram_group_id' => $group->id,
        'status' => 'closed',
        'closed_at' => now(),
    ]);
    $alice = Learner::factory()->memberOf($group)->create(['first_name' => 'Alice']);
    $bob = Learner::factory()->memberOf($group)->create(['first_name' => 'Bob']);

    ExamResult::factory()->create([
        'exam_session_id' => $session->id,
        'learner_id' => $alice->id,
        'total_score' => 30,
        'rank' => 1,
    ]);

    $cache = app(LearningCache::class);
    $first = $cache->examLeaderboard($session);

    expect($first['total_participants'])->toBe(1)
        ->and($first['rows'][0]['name'])->toBe('Alice');

    ExamResult::factory()->create([
        'exam_session_id' => $session->id,
        'learner_id' => $bob->id,
        'total_score' => 20,
        'rank' => 2,
    ]);

    expect($cache->examLeaderboard($session)['total_participants'])->toBe(1);

    $cache->forgetExam($session);

    expect($cache->examLeaderboard($session)['total_participants'])->toBe(2);
});

it('caches words learned per learner and hands only the learners without an entry to the loader', function (): void {
    $calls = [];
    $load = function (array $ids) use (&$calls): array {
        $calls[] = $ids;

        // Learner 3 has no learned word: the loader leaves them out, they count as 0.
        return array_intersect_key([1 => 10, 2 => 20, 4 => 40], array_flip($ids));
    };
    $cache = app(LearningCache::class);

    expect($cache->wordsLearned([1, 2, 3], $load))->toBe([1 => 10, 2 => 20, 3 => 0])
        ->and($cache->wordsLearned([3, 4, 1, 3], $load))->toBe([3 => 0, 4 => 40, 1 => 10]) // the order of the request, no duplicates
        ->and($cache->wordsLearned([1, 2, 3, 4], $load))->toBe([1 => 10, 2 => 20, 3 => 0, 4 => 40])
        ->and($cache->wordsLearned([], $load))->toBe([])
        ->and($calls)->toBe([[1, 2, 3], [4]]); // a 0 is an entry too: learner 3 is not asked again
});

it('keeps a words learned entry under stats:words_learned:{id} for ten minutes', function (): void {
    app(LearningCache::class)->wordsLearned([7], fn (array $ids): array => [7 => 12]);

    expect((int) Cache::get('stats:words_learned:7'))->toBe(12);

    $this->travel(599)->seconds();
    expect(Cache::has('stats:words_learned:7'))->toBeTrue();

    $this->travel(2)->seconds();
    expect(Cache::has('stats:words_learned:7'))->toBeFalse();

    $reloaded = [];
    app(LearningCache::class)->wordsLearned([7], function (array $ids) use (&$reloaded): array {
        $reloaded = $ids;

        return [7 => 15];
    });
    expect($reloaded)->toBe([7])->and((int) Cache::get('stats:words_learned:7'))->toBe(15);
});
