<?php

declare(strict_types=1);

/*
 * `php artisan courses:recount-words` (docs/03 §6): the repair of `courses.words_count` — the `total` of GET /courses —
 * for every course, the decks of learners and archived ones included, and of the cached word counts of the lessons.
 * It corrects what drifted, says how many courses it changed, and a second run changes (and writes) nothing.
 * `deploy.sh` runs it after the migrations.
 */

use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Content\Services\CoursePublisher;
use App\Domain\Content\Services\LessonContentRefresher;
use App\Domain\Learning\Services\LearningCache;
use App\Models\Course;
use App\Models\Learner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\HotPathFixtures as Fx;

uses(RefreshDatabase::class);

/**
 * Five courses whose figures are wrong in different ways (and one that is right), the way a restored dump or a path
 * that forgot to recount would leave them.
 *
 * @return array{ok: Course, platform: Course, deck: Course, archived: Course, empty: Course}
 */
function recountDriftedCourses(): array
{
    ['course' => $ok] = Fx::course([1 => [1 => 4]]);
    ['course' => $platform] = Fx::course([1 => [1 => [3, 1], 2 => 2]]); // 5 active words, 1 withdrawn
    $owner = Learner::factory()->create();
    ['course' => $deck] = Fx::course([1 => [1 => 2]], ['owner_type' => 'learner', 'owner_id' => $owner->id, 'source' => 'learner', 'visibility' => 'private']);
    ['course' => $archived] = Fx::course([1 => [1 => [0, 2]]], ['status' => 'archived']); // only withdrawn words
    $empty = Course::factory()->create(); // no stages at all

    DB::table('courses')->where('id', $platform->id)->update(['words_count' => 99]);
    DB::table('courses')->where('id', $deck->id)->update(['words_count' => 0]);
    DB::table('courses')->where('id', $archived->id)->update(['words_count' => 7]);
    DB::table('courses')->where('id', $empty->id)->update(['words_count' => 5]);

    return compact('ok', 'platform', 'deck', 'archived', 'empty');
}

/** @return array<int, int> course id => words_count */
function recountedCounts(): array
{
    return Course::query()->orderBy('id')->pluck('words_count', 'id')->map(fn ($n): int => (int) $n)->all();
}

it('corrects the words_count of every course - decks and archived ones too - and says how many it changed', function (): void {
    ['ok' => $ok, 'platform' => $platform, 'deck' => $deck, 'archived' => $archived, 'empty' => $empty] = recountDriftedCourses();

    $this->artisan('courses:recount-words')
        ->expectsOutputToContain("course #{$platform->id} ({$platform->slug}): 99 -> 5")
        ->expectsOutputToContain("course #{$deck->id} ({$deck->slug}): 0 -> 2")
        ->expectsOutputToContain("course #{$archived->id} ({$archived->slug}): 7 -> 0")
        ->expectsOutputToContain("course #{$empty->id} ({$empty->slug}): 5 -> 0")
        ->expectsOutputToContain('Courses checked: 5, changed: 4; lesson caches dropped: 5')
        ->assertSuccessful();

    expect(recountedCounts())->toBe([$ok->id => 4, $platform->id => 5, $deck->id => 2, $archived->id => 0, $empty->id => 0]);
});

it('is idempotent: the second run reports 0 changed and writes nothing, not even updated_at', function (): void {
    recountDriftedCourses();
    $this->travelTo(now()->addHour());
    $this->artisan('courses:recount-words')->expectsOutputToContain('changed: 4')->assertSuccessful();

    $before = Course::query()->orderBy('id')->get(['id', 'words_count', 'updated_at'])->map(fn (Course $c): string => "{$c->id}:{$c->words_count}:{$c->updated_at}")->all();
    $this->travelTo(now()->addHour());

    $this->artisan('courses:recount-words')
        ->expectsOutputToContain('Courses checked: 5, changed: 0')
        ->doesntExpectOutputToContain(' -> ')
        ->assertSuccessful();

    $after = Course::query()->orderBy('id')->get(['id', 'words_count', 'updated_at'])->map(fn (Course $c): string => "{$c->id}:{$c->words_count}:{$c->updated_at}")->all();
    expect($after)->toBe($before);
});

it('does nothing on a catalog without courses', function (): void {
    $this->artisan('courses:recount-words')
        ->expectsOutputToContain('Courses checked: 0, changed: 0; lesson caches dropped: 0')
        ->assertSuccessful();
});

it('drops the cached word count of every lesson, and the catalog cache shows the corrected total', function (): void {
    ['platform' => $platform] = recountDriftedCourses();
    $lessonIds = DB::table('lessons')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    foreach ($lessonIds as $id) {
        Cache::put("lesson:{$id}:word_count", 999, 3600);
    }
    $catalog = app(CourseCatalogService::class);
    $listed = fn (): ?int => collect($catalog->published('ru'))->firstWhere('id', $platform->id)?->wordsCount;
    expect($listed())->toBe(99); // cached with the wrong figure

    $this->artisan('courses:recount-words')->assertSuccessful();

    foreach ($lessonIds as $id) {
        expect(Cache::has("lesson:{$id}:word_count"))->toBeFalse();
    }
    $lesson = DB::table('lessons')->orderBy('id')->first();
    expect(app(LearningCache::class)->lessonWordCount((int) $lesson?->id))->toBe((int) DB::table('words')->where('lesson_id', $lesson?->id)->where('is_active', true)->count())
        ->and($listed())->toBe(5);
});

it('is run by deploy.sh right after the migrations, and never on a rollback', function (): void {
    $script = (string) file_get_contents(base_path('deploy.sh'));
    $migrate = strpos($script, 'php artisan migrate --force');
    $recount = strpos($script, 'php artisan courses:recount-words');

    expect($migrate)->not->toBeFalse()
        ->and($recount)->not->toBeFalse()
        ->and($recount)->toBeGreaterThan($migrate)
        // before the containers are switched: the new code is not serving yet
        ->and($recount)->toBeLessThan(strpos($script, 'up -d --remove-orphans'))
        // the older code of a rollback has no such command, and `set -e` would stop the rollback on it
        ->and($script)->toMatch('/if \[ "\$ROLLBACK" != "1" \]; then\s+echo "==> Recounting[^\n]*\n\s+\$COMPOSE run --rm app php artisan courses:recount-words/')
        // a failed repair only warns
        ->and($script)->toContain('|| echo "WARNING: courses:recount-words failed');
});

it('leaves a right figure alone and corrects it by the same rule as the paths that change words', function (): void {
    ['platform' => $platform] = recountDriftedCourses();

    $report = app(LessonContentRefresher::class)->refreshAll();

    expect($report->courses)->toBe(5)
        ->and($report->changed())->toBe(4)
        ->and($report->lessons)->toBe(5)
        ->and(collect($report->changes)->pluck('to', 'id')->all()[$platform->id])->toBe(5);

    // The figure CoursePublisher::recount() writes is the one the repair wrote.
    DB::table('courses')->where('id', $platform->id)->update(['words_count' => 1]);
    app(CoursePublisher::class)->recount($platform->fresh() ?? $platform);
    expect(recountedCounts()[$platform->id])->toBe(5);
});
