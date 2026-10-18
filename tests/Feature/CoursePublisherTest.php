<?php

declare(strict_types=1);

use App\Domain\Content\Exceptions\CoursePublishException;
use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Content\Services\CoursePublisher;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\User;
use App\Models\Word;
use App\Models\WordTranslation;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

function publisherAdmin(): User
{
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');

    return $admin;
}

/**
 * A course with one lesson of $words words; the first $fullyTranslated words have ru + uz rows,
 * the next $ruOnly words have a ru row only.
 */
function courseWithWords(int $words, int $fullyTranslated, int $ruOnly = 0, string $status = 'review'): Course
{
    $course = Course::factory()->create(['status' => $status]);
    $lesson = Lesson::factory()->for(Stage::factory()->create(['course_id' => $course->id]))->create();

    foreach (Word::factory()->count($words)->for($lesson)->create() as $i => $word) {
        if ($i < $fullyTranslated + $ruOnly) {
            WordTranslation::factory()->create(['word_id' => $word->id, 'locale' => 'ru']);
        }
        if ($i < $fullyTranslated) {
            WordTranslation::factory()->uz()->create(['word_id' => $word->id]);
        }
    }

    return $course;
}

it('walks draft → review → published → archived and writes an audit row per move', function (): void {
    $admin = publisherAdmin();
    $course = courseWithWords(4, 4, status: 'draft');
    $publisher = app(CoursePublisher::class);

    $publisher->transition($course, 'review', $admin, '10.0.0.1');
    expect($course->status)->toBe('review');

    $publisher->transition($course, 'published', $admin);
    expect($course->status)->toBe('published')->and($course->published_at)->not->toBeNull();

    $publisher->transition($course, 'archived', $admin);
    expect($course->fresh()->status)->toBe('archived');

    $logs = AuditLog::query()->where('action', 'course.status_changed')->orderBy('id')->get();
    expect($logs)->toHaveCount(3)
        ->and($logs[0]->user_id)->toBe($admin->id)
        ->and($logs[0]->ip)->toBe('10.0.0.1')
        ->and($logs[0]->entity_id)->toBe($course->id)
        ->and($logs[0]->meta['from'])->toBe('draft')->and($logs[0]->meta['to'])->toBe('review')
        ->and($logs[1]->meta['to'])->toBe('published')
        ->and($logs[1]->meta['translations']['covered'])->toBe(4)
        ->and($logs[2]->meta['to'])->toBe('archived');
});

it('allows sending a course back to draft from review and from archive', function (): void {
    $admin = publisherAdmin();
    $publisher = app(CoursePublisher::class);

    $review = courseWithWords(1, 1, status: 'review');
    $archived = courseWithWords(1, 1, status: 'archived');

    expect($publisher->transition($review, 'draft', $admin)->status)->toBe('draft')
        ->and($publisher->transition($archived, 'draft', $admin)->status)->toBe('draft');
});

it('rejects moves outside the lifecycle', function (string $from, string $to): void {
    $course = courseWithWords(2, 2, status: $from);

    expect(fn () => app(CoursePublisher::class)->transition($course, $to, publisherAdmin()))
        ->toThrow(CoursePublishException::class, "from '{$from}' to '{$to}'");

    expect($course->fresh()->status)->toBe($from)
        ->and(AuditLog::query()->where('action', 'course.status_changed')->count())->toBe(0);
})->with([
    'draft → published (skips review)' => ['draft', 'published'],
    'draft → archived' => ['draft', 'archived'],
    'published → draft' => ['published', 'draft'],
    'published → review' => ['published', 'review'],
    'review → archived' => ['review', 'archived'],
    'archived → published' => ['archived', 'published'],
    'same status' => ['review', 'review'],
]);

it('rejects an unknown target status', function (): void {
    $course = courseWithWords(1, 1, status: 'draft');

    expect(fn () => app(CoursePublisher::class)->transition($course, 'deleted', publisherAdmin()))
        ->toThrow(CoursePublishException::class, "Unknown course status 'deleted'");
});

it('lists the moves available from each status', function (): void {
    $publisher = app(CoursePublisher::class);

    expect($publisher->allowedTransitions(Course::factory()->make(['status' => 'draft'])))->toBe(['review'])
        ->and($publisher->allowedTransitions(Course::factory()->make(['status' => 'review'])))->toBe(['draft', 'published'])
        ->and($publisher->allowedTransitions(Course::factory()->make(['status' => 'published'])))->toBe(['archived'])
        ->and($publisher->allowedTransitions(Course::factory()->make(['status' => 'archived'])))->toBe(['draft']);
});

it('only lets an active admin change the status', function (): void {
    $course = courseWithWords(2, 2, status: 'draft');
    $teacher = User::factory()->create(['is_active' => true]);
    $teacher->assignRole('teacher');
    $inactiveAdmin = User::factory()->create(['is_active' => false]);
    $inactiveAdmin->assignRole('admin');

    foreach ([$teacher, $inactiveAdmin] as $user) {
        expect(fn () => app(CoursePublisher::class)->transition($course, 'review', $user))
            ->toThrow(CoursePublishException::class, 'Only an active admin');
    }

    expect($course->fresh()->status)->toBe('draft');
});

it('publishes when translations are complete for exactly the threshold share of words', function (): void {
    // 19 of 20 words have ru + uz = 95%.
    $course = courseWithWords(20, 19, ruOnly: 1);

    $published = app(CoursePublisher::class)->transition($course, 'published', publisherAdmin());

    expect($published->status)->toBe('published');
});

it('refuses to publish when too few words have uz + ru', function (): void {
    // 18 of 20 = 90% < 95%.
    $course = courseWithWords(20, 18, ruOnly: 2);

    try {
        app(CoursePublisher::class)->transition($course, 'published', publisherAdmin());
        $this->fail('Expected CoursePublishException');
    } catch (CoursePublishException $e) {
        expect($e->reason)->toBe(CoursePublishException::REASON_TRANSLATIONS_BELOW_THRESHOLD)
            ->and($e->getMessage())->toContain('18 of 20')->toContain('95.0%');
    }

    expect($course->fresh()->status)->toBe('review')
        ->and(AuditLog::query()->where('action', 'course.status_changed')->count())->toBe(0);
});

it('takes the threshold from config', function (): void {
    config(['content.publish_translation_threshold' => 0.5]);
    $course = courseWithWords(4, 2, ruOnly: 2);

    expect(app(CoursePublisher::class)->transition($course, 'published', publisherAdmin())->status)->toBe('published');

    config(['content.publish_translation_threshold' => 0.75]);
    $another = courseWithWords(4, 2, ruOnly: 2);

    expect(fn () => app(CoursePublisher::class)->transition($another, 'published', publisherAdmin()))
        ->toThrow(CoursePublishException::class);
});

it('counts only active words and ignores empty translation rows', function (): void {
    $course = courseWithWords(4, 4);
    // An inactive, untranslated word does not count against the course...
    Word::factory()->create(['lesson_id' => $course->stages()->first()->lessons()->first()->id, 'is_active' => false]);
    // ...but an empty uz text is no translation.
    $word = Word::query()->where('is_active', true)->first();
    WordTranslation::query()->where('word_id', $word->id)->where('locale', 'uz')->update(['translation' => '']);

    $coverage = app(CoursePublisher::class)->translationCoverage($course);

    expect($coverage['total'])->toBe(4)->and($coverage['covered'])->toBe(3);
});

it('counts the legacy words.translation as the ru text when the ru row is missing or blank', function (): void {
    $course = Course::factory()->create(['status' => 'review']);
    $lesson = Lesson::factory()->for(Stage::factory()->create(['course_id' => $course->id]))->create();
    $make = function (string $legacy, ?string $ruRow, ?string $uzRow) use ($lesson): void {
        $word = Word::factory()->for($lesson)->create(['translation' => $legacy]);
        if ($ruRow !== null) {
            WordTranslation::factory()->create(['word_id' => $word->id, 'locale' => 'ru', 'translation' => $ruRow]);
        }
        if ($uzRow !== null) {
            WordTranslation::factory()->uz()->create(['word_id' => $word->id, 'translation' => $uzRow]);
        }
    };

    $make('привет', null, 'salom');       // no ru row, legacy text -> covered
    $make('мир', '   ', 'dunyo');         // blank ru row, legacy text -> covered
    $make('   ', "\t\n", 'tea');          // ru blank everywhere -> not covered
    $make('чай', 'чай', "  \t");          // uz blank after trim -> not covered
    $make('кот', 'кот', null);            // no uz at all -> not covered

    $coverage = app(CoursePublisher::class)->translationCoverage($course);

    expect($coverage['total'])->toBe(5)->and($coverage['covered'])->toBe(2);
});

it('publishes a course whose ru texts live only in words.translation', function (): void {
    $course = courseWithWords(4, 4);
    WordTranslation::query()->where('locale', 'ru')->delete();

    expect(app(CoursePublisher::class)->transition($course, 'published', publisherAdmin())->status)->toBe('published');
});

it('refuses to publish a course without lessons', function (): void {
    $course = Course::factory()->create(['status' => 'review']);
    Stage::factory()->create(['course_id' => $course->id]);

    expect(fn () => app(CoursePublisher::class)->transition($course, 'published', publisherAdmin()))
        ->toThrow(CoursePublishException::class, 'no lessons');
});

it('refuses to publish a course without words', function (): void {
    $course = Course::factory()->create(['status' => 'review']);
    Lesson::factory()->for(Stage::factory()->create(['course_id' => $course->id]))->create();

    expect(fn () => app(CoursePublisher::class)->transition($course, 'published', publisherAdmin()))
        ->toThrow(CoursePublishException::class, 'no active words');
});

it('refuses to publish a course whose title misses a language', function (): void {
    $course = courseWithWords(2, 2);
    $course->update(['title' => ['ru' => 'Курс', 'uz' => ' ']]);

    try {
        app(CoursePublisher::class)->transition($course, 'published', publisherAdmin());
        $this->fail('Expected CoursePublishException');
    } catch (CoursePublishException $e) {
        expect($e->reason)->toBe(CoursePublishException::REASON_TITLE_INCOMPLETE)->and($e->getMessage())->toContain('uz');
    }
});

it('does not re-check a course that is already published', function (): void {
    // Old content without uz stays published and is shown with the ru fallback; only NEW publications are gated.
    $course = courseWithWords(10, 0, ruOnly: 10, status: 'published');

    expect(app(CoursePublisher::class)->transition($course, 'archived', publisherAdmin())->status)->toBe('archived');
});

it('recounts the active words of a course', function (): void {
    $course = courseWithWords(5, 0);
    Word::query()->first()->update(['is_active' => false]);
    $other = courseWithWords(3, 0);

    app(CoursePublisher::class)->recount($course);

    expect($course->words_count)->toBe(4)
        ->and($course->fresh()->words_count)->toBe(4)
        ->and($other->fresh()->words_count)->toBe(0);
});

it('never archives the default course: it serves every group without a course', function (): void {
    $default = app(CourseCatalogService::class)->defaultCourse();
    $publisher = app(CoursePublisher::class);

    expect($default->status)->toBe('published')
        ->and($publisher->allowedTransitions($default))->not->toContain('archived');

    try {
        $publisher->transition($default, 'archived', publisherAdmin());
        $this->fail('Expected CoursePublishException');
    } catch (CoursePublishException $e) {
        expect($e->reason)->toBe(CoursePublishException::REASON_DEFAULT_COURSE_PROTECTED);
    }

    expect($default->fresh()->status)->toBe('published')
        ->and(AuditLog::query()->where('action', 'course.status_changed')->count())->toBe(0);
});

it('archives any other published course', function (): void {
    $course = Course::factory()->published()->create(['slug' => 'ielts']);

    expect(app(CoursePublisher::class)->allowedTransitions($course))->toContain('archived')
        ->and(app(CoursePublisher::class)->transition($course, 'archived', publisherAdmin())->status)->toBe('archived');
});
