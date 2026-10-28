<?php

declare(strict_types=1);

/*
 * Course catalog of the TWA (task 1.2b): GET /courses, GET /courses/{slug}, POST /me/course,
 * progress over active words, visibility rules and the catalog cache.
 */

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Content\Services\CoursePublisher;
use App\Domain\Content\Services\LessonContentRefresher;
use App\Domain\Twa\Services\JwtService;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\ProductEvent;
use App\Models\Stage;
use App\Models\TelegramGroup;
use App\Models\User;
use App\Models\Word;
use App\Models\WordRepetition;
use App\Models\WordTranslation;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** @return array<string, string> */
function courseHeaders(Learner $learner): array
{
    return ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
}

/**
 * A published course with one stage per entry of $lessons (stage number => words per lesson list).
 *
 * @param  array<string, mixed>  $attributes
 * @param  list<int>  $lessonWords  words in each lesson, all in stage 1
 * @return array{course: Course, lessons: list<Lesson>}
 */
function catalogCourse(array $attributes = [], array $lessonWords = [3, 2]): array
{
    $course = Course::factory()->published()->create($attributes);
    $stage = Stage::factory()->create(['course_id' => $course->id]);

    $lessons = [];
    foreach ($lessonWords as $count) {
        $lesson = Lesson::factory()->for($stage)->create();
        Word::factory()->count($count)->for($lesson)->create(['is_active' => true]);
        $lessons[] = $lesson;
    }

    // The catalog reads the course counter (as after any import / deck change); a test that sets it itself keeps its value.
    if (! array_key_exists('words_count', $attributes)) {
        app(CoursePublisher::class)->recount($course);
    }

    return ['course' => $course, 'lessons' => $lessons];
}

function learnWord(Learner $learner, Word $word, int $repetitions = 2): void
{
    WordRepetition::factory()->create([
        'learner_id' => $learner->id,
        'word_id' => $word->id,
        'repetitions' => $repetitions,
    ]);
}

// --- GET /courses ----------------------------------------------------------

it('lists published public courses in catalog order and hides everything else', function (): void {
    $learner = Learner::factory()->create();
    $b = catalogCourse(['slug' => 'b-course', 'position' => 2])['course'];
    $a = catalogCourse(['slug' => 'a-course', 'position' => 1])['course'];
    Course::factory()->create(['slug' => 'draft-course', 'status' => 'draft']);
    Course::factory()->create(['slug' => 'review-course', 'status' => 'review']);
    Course::factory()->published()->create(['slug' => 'archived-course', 'status' => 'archived']);
    Course::factory()->published()->create(['slug' => 'private-course', 'visibility' => 'private']);
    Course::factory()->published()->create(['slug' => 'unlisted-course', 'visibility' => 'unlisted']);

    $response = $this->withHeaders(courseHeaders($learner))->getJson('/api/twa/courses')->assertOk();

    expect(array_column($response->json('courses'), 'slug'))->toBe([$a->slug, $b->slug]);
});

it('returns the title and description in the learner locale with a ru fallback', function (): void {
    catalogCourse(['slug' => 'both', 'position' => 1, 'title' => ['ru' => 'Курс', 'uz' => 'Kurs'], 'description' => ['ru' => 'Описание', 'uz' => 'Tavsif']]);
    catalogCourse(['slug' => 'ru-only', 'position' => 2, 'title' => ['ru' => 'Только рус', 'uz' => ''], 'description' => ['ru' => 'Рус описание']]);
    catalogCourse(['slug' => 'no-description', 'position' => 3, 'title' => ['ru' => 'Без описания', 'uz' => 'Tavsifsiz'], 'description' => []]);

    $uz = Learner::factory()->create(['interface_locale' => 'uz']);
    $ru = Learner::factory()->create(['interface_locale' => 'ru']);

    $uzCourses = collect($this->withHeaders(courseHeaders($uz))->getJson('/api/twa/courses')->json('courses'))->keyBy('slug');
    $ruCourses = collect($this->withHeaders(courseHeaders($ru))->getJson('/api/twa/courses')->json('courses'))->keyBy('slug');

    expect($uzCourses['both']['title'])->toBe('Kurs')
        ->and($uzCourses['both']['description'])->toBe('Tavsif')
        ->and($uzCourses['ru-only']['title'])->toBe('Только рус')
        ->and($uzCourses['ru-only']['description'])->toBe('Рус описание')
        ->and($uzCourses['no-description']['description'])->toBeNull()
        ->and($ruCourses['both']['title'])->toBe('Курс');
});

it('exposes level, goal, words_count and the active flag', function (): void {
    $course = catalogCourse(['slug' => 'ielts', 'level' => 'B2', 'exam_tag' => 'ielts', 'words_count' => 5, 'is_featured' => true])['course'];
    catalogCourse(['slug' => 'other', 'position' => 5]);
    $learner = Learner::factory()->create(['active_course_id' => $course->id]);

    $rows = collect($this->withHeaders(courseHeaders($learner))->getJson('/api/twa/courses')->json('courses'))->keyBy('slug');

    expect($rows['ielts']['level'])->toBe('B2')
        ->and($rows['ielts']['exam_tag'])->toBe('ielts')
        ->and($rows['ielts']['words_count'])->toBe(5)
        ->and($rows['ielts']['is_featured'])->toBeTrue()
        ->and($rows['ielts']['is_active'])->toBeTrue()
        ->and($rows['other']['is_active'])->toBeFalse();
});

it('keeps an unlisted course in the list when it is the learner\'s active one', function (): void {
    $unlisted = catalogCourse(['slug' => 'by-link', 'visibility' => 'unlisted'])['course'];
    $learner = Learner::factory()->create(['active_course_id' => $unlisted->id]);
    $other = Learner::factory()->create();

    $mine = $this->withHeaders(courseHeaders($learner))->getJson('/api/twa/courses')->json('courses');
    $theirs = $this->withHeaders(courseHeaders($other))->getJson('/api/twa/courses')->json('courses');

    expect(array_column($mine, 'slug'))->toBe(['by-link'])
        ->and($mine[0]['is_active'])->toBeTrue()
        ->and($theirs)->toBe([]);
});

it('does not list an active course that has been archived or made private since', function (): void {
    $course = catalogCourse(['slug' => 'gone', 'visibility' => 'unlisted'])['course'];
    $learner = Learner::factory()->create(['active_course_id' => $course->id]);
    $course->update(['status' => 'archived']);

    $this->withHeaders(courseHeaders($learner))->getJson('/api/twa/courses')
        ->assertOk()
        ->assertExactJson(['courses' => []]);
});

it('returns an empty list when there are no courses', function (): void {
    $this->withHeaders(courseHeaders(Learner::factory()->create()))
        ->getJson('/api/twa/courses')
        ->assertOk()
        ->assertExactJson(['courses' => []]);
});

it('requires authentication', function (): void {
    $this->getJson('/api/twa/courses')->assertUnauthorized();
    $this->getJson('/api/twa/courses/x')->assertUnauthorized();
    $this->postJson('/api/twa/me/course', ['slug' => 'x'])->assertUnauthorized();
});

// --- progress --------------------------------------------------------------

it('counts learned active words per course and ignores inactive words', function (): void {
    ['course' => $course, 'lessons' => $lessons] = catalogCourse(['slug' => 'prog'], [3, 2]);
    $learner = Learner::factory()->create();
    $words = Word::query()->where('lesson_id', $lessons[0]->id)->orderBy('id')->get();

    learnWord($learner, $words[0]);
    learnWord($learner, $words[1]);
    // A word that was learned and then deactivated no longer counts, neither as total nor as done
    // (switched off the way the admin panel does it: the course counter follows).
    $words[1]->update(['is_active' => false]);
    app(LessonContentRefresher::class)->wordChanged($words[1]);
    // Seen but not learned yet (repetitions = 0): not "done".
    learnWord($learner, $words[2], repetitions: 0);
    // Another learner's progress is not ours.
    learnWord(Learner::factory()->create(), $words[2]);

    $row = collect($this->withHeaders(courseHeaders($learner))->getJson('/api/twa/courses')->json('courses'))
        ->firstWhere('slug', $course->slug);

    expect($row['progress'])->toBe(['done' => 1, 'total' => 4]);
});

it('reports zero progress for a course without words', function (): void {
    Course::factory()->published()->create(['slug' => 'empty']);

    $rows = $this->withHeaders(courseHeaders(Learner::factory()->create()))->getJson('/api/twa/courses')->json('courses');

    expect($rows[0]['progress'])->toBe(['done' => 0, 'total' => 0]);
});

it('keeps one progress per learner no matter how many groups the learner studies in', function (): void {
    ['course' => $course, 'lessons' => $lessons] = catalogCourse(['slug' => 'groups'], [2]);
    $groupA = TelegramGroup::factory()->create(['status' => 'active', 'course_id' => $course->id]);
    $groupB = TelegramGroup::factory()->create(['status' => 'active', 'course_id' => $course->id]);
    $learner = Learner::factory()->memberOf($groupA)->memberOf($groupB)->create();
    $word = Word::query()->where('lesson_id', $lessons[0]->id)->first();

    learnWord($learner, $word);

    $headers = courseHeaders($learner);
    $list = collect($this->withHeaders($headers)->getJson('/api/twa/courses')->json('courses'))->firstWhere('slug', 'groups');
    $detail = $this->withHeaders($headers)->getJson('/api/twa/courses/groups')->json();

    expect($list['progress'])->toBe(['done' => 1, 'total' => 2])
        ->and($detail['progress'])->toBe(['done' => 1, 'total' => 2])
        ->and($detail['stages'][0]['lessons'][0]['progress'])->toBe(['done' => 1, 'total' => 2]);
});

it('computes the catalog progress in a constant number of queries', function (): void {
    foreach (range(1, 6) as $i) {
        ['lessons' => $lessons] = catalogCourse(['position' => $i], [2, 2]);
    }
    $learner = Learner::factory()->create();
    $headers = courseHeaders($learner);
    $this->withHeaders($headers)->getJson('/api/twa/courses')->assertOk(); // warm up the catalog cache

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->withHeaders($headers)->getJson('/api/twa/courses')->assertOk();
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    // One statement from the learner's own rows; the words of the catalog are not scanned (the total is the
    // course counter) — see CatalogProgressTest for the full query budget.
    expect($queries->filter(fn (string $sql): bool => str_contains($sql, 'word_repetitions')))->toHaveCount(1)
        ->and($queries->filter(fn (string $sql): bool => str_contains($sql, 'from "words"') || str_contains($sql, 'from `words`')))->toHaveCount(0);
});

// --- GET /courses/{slug} ---------------------------------------------------

it('returns stages with lessons in study order and per-lesson progress', function (): void {
    $course = Course::factory()->published()->create(['slug' => 'detail', 'title' => ['ru' => 'Детали', 'uz' => 'Tafsilot']]);
    $second = Stage::factory()->create(['course_id' => $course->id, 'number' => 2, 'title' => 'Second']);
    $first = Stage::factory()->create(['course_id' => $course->id, 'number' => 1, 'title' => 'First']);
    $l21 = Lesson::factory()->for($second)->create(['number' => 1, 'title' => null]);
    $l12 = Lesson::factory()->for($first)->create(['number' => 2, 'title' => 'Two']);
    $l11 = Lesson::factory()->for($first)->create(['number' => 1, 'title' => 'One']);
    $w11 = Word::factory()->count(3)->for($l11)->create();
    Word::factory()->count(2)->for($l12)->create();
    Word::factory()->for($l12)->create(['is_active' => false]);
    // l21 has no words at all.

    $learner = Learner::factory()->create(['interface_locale' => 'uz', 'active_course_id' => $course->id]);
    learnWord($learner, $w11[0]);
    learnWord($learner, $w11[1]);

    $json = $this->withHeaders(courseHeaders($learner))->getJson('/api/twa/courses/detail')->assertOk()->json();

    expect($json['title'])->toBe('Tafsilot')
        ->and($json['is_active'])->toBeTrue()
        ->and($json['progress'])->toBe(['done' => 2, 'total' => 5])
        ->and(array_column($json['stages'], 'number'))->toBe([1, 2])
        ->and($json['stages'][0]['title'])->toBe('First')
        ->and($json['stages'][0]['progress'])->toBe(['done' => 2, 'total' => 5])
        ->and(array_column($json['stages'][0]['lessons'], 'number'))->toBe([1, 2])
        ->and($json['stages'][0]['lessons'][0])->toBe(['id' => $l11->id, 'number' => 1, 'title' => 'One', 'progress' => ['done' => 2, 'total' => 3]])
        ->and($json['stages'][0]['lessons'][1]['progress'])->toBe(['done' => 0, 'total' => 2])
        ->and($json['stages'][1]['lessons'][0])->toBe(['id' => $l21->id, 'number' => 1, 'title' => null, 'progress' => ['done' => 0, 'total' => 0]]);
});

it('opens an unlisted published course by its slug', function (): void {
    catalogCourse(['slug' => 'by-link', 'visibility' => 'unlisted']);

    $this->withHeaders(courseHeaders(Learner::factory()->create()))
        ->getJson('/api/twa/courses/by-link')
        ->assertOk()
        ->assertJsonPath('slug', 'by-link')
        ->assertJsonPath('is_active', false);
});

it('answers 404 course_not_found for draft, review, archived, private and unknown courses', function (string $slug, array $attributes): void {
    Course::factory()->create(['slug' => $slug, ...$attributes]);
    $headers = courseHeaders(Learner::factory()->create());

    $this->withHeaders($headers)->getJson("/api/twa/courses/{$slug}")
        ->assertNotFound()
        ->assertExactJson(['error' => ['code' => 'course_not_found', 'message' => 'Course not found.']]);
})->with([
    'draft' => ['draft-c', ['status' => 'draft']],
    'review' => ['review-c', ['status' => 'review']],
    'archived' => ['archived-c', ['status' => 'archived']],
    'private' => ['private-c', ['status' => 'published', 'visibility' => 'private']],
]);

it('answers 404 for a slug that does not exist, identical to an unavailable one', function (): void {
    $this->withHeaders(courseHeaders(Learner::factory()->create()))
        ->getJson('/api/twa/courses/nope')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'course_not_found');
});

// --- POST /me/course -------------------------------------------------------

it('sets the active course and answers with the course row', function (): void {
    ['course' => $course, 'lessons' => $lessons] = catalogCourse(['slug' => 'pick']);
    $learner = Learner::factory()->create();
    learnWord($learner, Word::query()->where('lesson_id', $lessons[0]->id)->first());

    $this->withHeaders(courseHeaders($learner))->postJson('/api/twa/me/course', ['slug' => 'pick'])
        ->assertOk()
        ->assertJsonPath('slug', 'pick')
        ->assertJsonPath('is_active', true)
        ->assertJsonPath('progress', ['done' => 1, 'total' => 5]);

    expect($learner->fresh()->active_course_id)->toBe($course->id);
});

it('switches the active course and is idempotent', function (): void {
    $first = catalogCourse(['slug' => 'first'])['course'];
    $second = catalogCourse(['slug' => 'second'])['course'];
    $learner = Learner::factory()->create(['active_course_id' => $first->id]);
    $headers = courseHeaders($learner);

    $this->withHeaders($headers)->postJson('/api/twa/me/course', ['slug' => 'second'])->assertOk();
    $this->withHeaders($headers)->postJson('/api/twa/me/course', ['slug' => 'second'])->assertOk();

    expect($learner->fresh()->active_course_id)->toBe($second->id);
});

it('records course.selected only when the active course really changes', function (): void {
    $first = catalogCourse(['slug' => 'first'])['course'];
    $second = catalogCourse(['slug' => 'second'])['course'];
    $learner = Learner::factory()->create(['active_course_id' => $first->id]);
    $headers = courseHeaders($learner);

    $this->withHeaders($headers)->postJson('/api/twa/me/course', ['slug' => 'first'])->assertOk();
    expect(ProductEvent::query()->count())->toBe(0);

    $this->withHeaders($headers)->postJson('/api/twa/me/course', ['slug' => 'second'])->assertOk();
    $this->withHeaders($headers)->postJson('/api/twa/me/course', ['slug' => 'second'])->assertOk();
    $this->withHeaders($headers)->postJson('/api/twa/me/course', ['slug' => 'missing'])->assertNotFound();

    $events = ProductEvent::query()->where('name', ProductEventName::CourseSelected->value)->get();
    expect($events)->toHaveCount(1)
        ->and($events[0]->learner_id)->toBe($learner->id)
        ->and(ksorted($events[0]->props))->toBe(['course_id' => $second->id, 'via' => 'twa']);
});

it('can choose an unlisted published course', function (): void {
    $course = catalogCourse(['slug' => 'by-link', 'visibility' => 'unlisted'])['course'];
    $learner = Learner::factory()->create();

    $this->withHeaders(courseHeaders($learner))->postJson('/api/twa/me/course', ['slug' => 'by-link'])->assertOk();

    expect($learner->fresh()->active_course_id)->toBe($course->id);
});

it('refuses to choose an unavailable course with 404 and leaves the active course alone', function (string $status, string $visibility): void {
    $current = catalogCourse(['slug' => 'current'])['course'];
    Course::factory()->create(['slug' => 'target', 'status' => $status, 'visibility' => $visibility]);
    $learner = Learner::factory()->create(['active_course_id' => $current->id]);

    $this->withHeaders(courseHeaders($learner))->postJson('/api/twa/me/course', ['slug' => 'target'])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'course_not_found');

    expect($learner->fresh()->active_course_id)->toBe($current->id);
})->with([
    'draft' => ['draft', 'public'],
    'review' => ['review', 'public'],
    'archived' => ['archived', 'public'],
    'private' => ['published', 'private'],
]);

it('validates the request body', function (): void {
    $headers = courseHeaders(Learner::factory()->create());

    $this->withHeaders($headers)->postJson('/api/twa/me/course', [])->assertUnprocessable()->assertJsonValidationErrors('slug');
    $this->withHeaders($headers)->postJson('/api/twa/me/course', ['slug' => ['x']])->assertUnprocessable();
    $this->withHeaders($headers)->postJson('/api/twa/me/course', ['slug' => str_repeat('a', 65)])->assertUnprocessable();
});

// --- catalog cache ---------------------------------------------------------

it('caches the public list and serves new data after publication', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');

    $course = Course::factory()->create([
        'slug' => 'soon',
        'status' => 'review',
        'title' => ['ru' => 'Скоро', 'uz' => 'Tez orada'],
    ]);
    $lesson = Lesson::factory()->for(Stage::factory()->create(['course_id' => $course->id]))->create();
    foreach (Word::factory()->count(2)->for($lesson)->create() as $word) {
        WordTranslation::factory()->create(['word_id' => $word->id, 'locale' => 'ru']);
        WordTranslation::factory()->uz()->create(['word_id' => $word->id]);
    }

    $learner = Learner::factory()->create();
    $headers = courseHeaders($learner);

    expect($this->withHeaders($headers)->getJson('/api/twa/courses')->json('courses'))->toBe([]);

    app(CoursePublisher::class)->transition($course, 'published', $admin);
    expect(array_column($this->withHeaders($headers)->getJson('/api/twa/courses')->json('courses'), 'slug'))->toBe(['soon']);

    app(CoursePublisher::class)->transition($course, 'archived', $admin);
    expect($this->withHeaders($headers)->getJson('/api/twa/courses')->json('courses'))->toBe([]);
});

it('serves the list from the cache between changes', function (): void {
    $course = catalogCourse(['slug' => 'cached'])['course'];
    $catalog = app(CourseCatalogService::class);

    expect($catalog->published('ru'))->toHaveCount(1);

    // A direct write bypasses the invalidation hooks: the cached list still has the old title.
    DB::table('courses')->where('id', $course->id)->update(['title' => json_encode(['ru' => 'Новое', 'uz' => 'Yangi'])]);
    expect($catalog->published('ru')[0]->title)->not->toBe('Новое');

    $catalog->forgetPublished();
    expect($catalog->published('ru')[0]->title)->toBe('Новое')
        ->and($catalog->published('uz')[0]->title)->toBe('Yangi');
});

it('drops the cached list when words are recounted', function (): void {
    ['course' => $course] = catalogCourse(['slug' => 'counted', 'words_count' => 0], [3]);
    $catalog = app(CourseCatalogService::class);

    expect($catalog->published('ru')[0]->wordsCount)->toBe(0);

    app(CoursePublisher::class)->recount($course);

    expect($catalog->published('ru')[0]->wordsCount)->toBe(3);
});

it('does not cache learner progress', function (): void {
    ['lessons' => $lessons] = catalogCourse(['slug' => 'live'], [2]);
    $learner = Learner::factory()->create();
    $headers = courseHeaders($learner);

    $before = $this->withHeaders($headers)->getJson('/api/twa/courses')->json('courses.0.progress');
    learnWord($learner, Word::query()->where('lesson_id', $lessons[0]->id)->first());
    $after = $this->withHeaders($headers)->getJson('/api/twa/courses')->json('courses.0.progress');

    expect($before)->toBe(['done' => 0, 'total' => 2])
        ->and($after)->toBe(['done' => 1, 'total' => 2]);
});
