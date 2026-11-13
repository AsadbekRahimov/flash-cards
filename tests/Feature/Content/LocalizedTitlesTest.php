<?php

declare(strict_types=1);

/*
 * R3c: the names of modules and lessons in the learner's language (docs/03 §2.5-§2.6). `stages.title_uz` /
 * `lessons.title_uz` beside the Russian `title`; a reader of uz is given the uz name, else the ru one, else nothing
 * (the client names the module by its number). The cached tree of a course is per locale and the names are picked
 * before they are cached, so a ru entry never serves a uz reader.
 */

use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Content\Services\CoursePublisher;
use App\Domain\Content\Services\DeckTitles;
use App\Domain\Content\Services\VocabularyImporter;
use App\Domain\Operations\Support\PureCache;
use App\Domain\Practice\DTO\Snapshot;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Twa\Services\JwtService;
use App\Filament\Resources\LessonResource\Pages\EditLesson;
use App\Filament\Resources\StageResource\Pages\CreateStage;
use App\Filament\Resources\StageResource\Pages\EditStage;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\User;
use App\Models\Word;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use function Pest\Livewire\livewire;

use Tests\Support\Practice\BattleKit;
use Tests\Support\Practice\PracticeKit;

uses(RefreshDatabase::class);

/** @return array<string, string> */
function ltHeaders(Learner $learner): array
{
    return ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
}

/**
 * A published course with three modules: one named in both languages, one named in ru only, one without a name;
 * the first module has lessons of the same three kinds.
 *
 * @return array{course: Course, both: Stage, ru: Stage, none: Stage, lessons: array{both: Lesson, ru: Lesson, none: Lesson}}
 */
function ltCourse(string $slug = 'titles'): array
{
    $course = Course::factory()->published()->create(['slug' => $slug, 'title' => ['ru' => 'Курс', 'uz' => 'Kurs']]);
    $both = Stage::factory()->create(['course_id' => $course->id, 'number' => 1, 'title' => 'Знакомство', 'title_uz' => 'Tanishuv']);
    $ru = Stage::factory()->create(['course_id' => $course->id, 'number' => 2, 'title' => 'Семья', 'title_uz' => null]);
    $none = Stage::factory()->untitled()->create(['course_id' => $course->id, 'number' => 3, 'title_uz' => null]);

    $lessons = [
        'both' => Lesson::factory()->for($both)->create(['number' => 1, 'title' => 'Приветствия', 'title_uz' => 'Salomlashish']),
        'ru' => Lesson::factory()->for($both)->create(['number' => 2, 'title' => 'Местоимения', 'title_uz' => null]),
        'none' => Lesson::factory()->for($both)->create(['number' => 3, 'title' => null, 'title_uz' => null]),
    ];
    Lesson::factory()->for($ru)->create(['number' => 1, 'title' => 'Дом', 'title_uz' => '  ']);
    Lesson::factory()->for($none)->create(['number' => 1, 'title' => '', 'title_uz' => 'Ovqat']);
    foreach (Lesson::query()->whereIn('stage_id', [$both->id, $ru->id, $none->id])->get() as $lesson) {
        Word::factory()->for($lesson)->create(['is_active' => true]);
    }
    app(CoursePublisher::class)->recount($course);

    return ['course' => $course->refresh(), 'both' => $both, 'ru' => $ru, 'none' => $none, 'lessons' => $lessons];
}

/** @return list<array{string|null, list<string|null>}> [module title, [lesson titles]] of GET /courses/{slug} for the learner */
function ltPage(object $test, Learner $learner, string $slug): array
{
    $stages = $test->withHeaders(ltHeaders($learner))->getJson("/api/twa/courses/{$slug}")->assertOk()->json('stages');

    return array_map(fn (array $stage): array => [$stage['title'], array_column($stage['lessons'], 'title')], $stages);
}

// --- the schema ----------------------------------------------------------------------------------------------------

it('adds title_uz beside title on stages and lessons, nullable, and drops it again on rollback without touching the names', function (): void {
    $migration = require database_path('migrations/2026_11_03_100001_add_title_uz_to_stages_and_lessons_tables.php');
    $column = fn (string $table): ?array => collect(Schema::getColumns($table))->firstWhere('name', 'title_uz');

    $stage = Stage::factory()->create(['title' => 'Семья', 'title_uz' => 'Oila']);
    $lesson = Lesson::factory()->for($stage)->create(['title' => 'Дом', 'title_uz' => 'Uy']);

    expect($column('stages'))->not->toBeNull()
        ->and($column('stages')['nullable'])->toBeTrue()
        ->and($column('lessons'))->not->toBeNull()
        ->and($column('lessons')['nullable'])->toBeTrue();

    $migration->down();
    expect($column('stages'))->toBeNull()
        ->and($column('lessons'))->toBeNull()
        ->and(Schema::hasColumns('stages', ['id', 'course_id', 'number', 'title', 'description']))->toBeTrue()
        ->and(DB::table('stages')->where('id', $stage->id)->value('title'))->toBe('Семья')
        ->and(DB::table('lessons')->where('id', $lesson->id)->value('title'))->toBe('Дом');

    // The unique keys and the foreign keys of both tables are untouched by the rollback.
    $unique = fn (string $table, array $columns): bool => collect(Schema::getIndexes($table))->contains(fn (array $index): bool => $index['unique'] && $index['columns'] === $columns);
    expect($unique('stages', ['course_id', 'number']))->toBeTrue()
        ->and($unique('lessons', ['stage_id', 'number']))->toBeTrue()
        ->and(collect(Schema::getForeignKeys('lessons'))->contains(fn (array $fk): bool => $fk['columns'] === ['stage_id']))->toBeTrue();

    $migration->up();
    expect($column('stages')['nullable'])->toBeTrue()
        ->and($column('lessons')['nullable'])->toBeTrue()
        ->and(DB::table('stages')->where('id', $stage->id)->value('title_uz'))->toBeNull('a fresh column is empty');

    Stage::query()->whereKey($stage->id)->update(['title_uz' => 'Oila']);
    expect(Stage::query()->findOrFail($stage->id)->title_uz)->toBe('Oila');
});

// --- the pick by locale (DeckTitles) --------------------------------------------------------------------------------

it('gives a reader of uz the uz name, else the ru one, and nothing when there is none; a reader of ru always the ru one', function (): void {
    $course = Course::factory()->published()->create();
    $titles = app(DeckTitles::class);

    expect($titles->stageIn($course, 'uz', 'Семья', 'Oila'))->toBe('Oila')
        ->and($titles->stageIn($course, 'uz', 'Семья', null))->toBe('Семья')
        ->and($titles->stageIn($course, 'uz', 'Семья', '  '))->toBe('Семья', 'a blank uz name is no name')
        ->and($titles->stageIn($course, 'uz', null, null))->toBeNull()
        ->and($titles->stageIn($course, 'uz', '', null))->toBeNull('a blank ru name is no name')
        ->and($titles->stageIn($course, 'uz', null, 'Oila'))->toBe('Oila', 'a uz name without a ru one is still a name for uz')
        ->and($titles->stageIn($course, 'ru', 'Семья', 'Oila'))->toBe('Семья')
        ->and($titles->stageIn($course, 'ru', null, 'Oila'))->toBeNull('ru does not fall back to uz')
        ->and($titles->lessonIn($course, 'uz', 1, 'Дом', 'Uy'))->toBe('Uy')
        ->and($titles->lessonIn($course, 'uz', 1, 'Дом', null))->toBe('Дом')
        ->and($titles->lessonIn($course, 'ru', 1, 'Дом', 'Uy'))->toBe('Дом')
        ->and($titles->lessonIn($course, 'ru', 1, null, null))->toBeNull();

    $stage = Stage::factory()->make(['title' => 'Семья', 'title_uz' => 'Oila']);
    $lesson = Lesson::factory()->make(['number' => 2, 'title' => 'Дом', 'title_uz' => 'Uy']);
    $uz = Learner::factory()->make(['interface_locale' => 'uz']);
    $ru = Learner::factory()->make(['interface_locale' => 'ru']);
    expect($titles->stage($course, $uz, $stage))->toBe('Oila')
        ->and($titles->stage($course, $ru, $stage))->toBe('Семья')
        ->and($titles->lesson($course, $uz, $lesson))->toBe('Uy')
        ->and($titles->lesson($course, $ru, $lesson))->toBe('Дом')
        ->and($titles->lessonTitles($course, $lesson, ['ru', 'uz']))->toBe(['ru' => 'Дом', 'uz' => 'Uy'])
        ->and($titles->lessonTitles($course, Lesson::factory()->make(['number' => 3, 'title' => 'Дом', 'title_uz' => null]), ['ru', 'uz']))->toBe(['ru' => 'Дом', 'uz' => 'Дом'])
        ->and($titles->lessonTitles($course, Lesson::factory()->make(['number' => 4, 'title' => null, 'title_uz' => null]), ['ru', 'uz']))->toBe([], 'a lesson without a name has no entry');
});

it('keeps the captions of a learner\'s own deck in the reader\'s language whatever the columns hold', function (): void {
    $owner = Learner::factory()->create(['interface_locale' => 'uz']);
    $deck = Course::factory()->published()->create(['owner_type' => 'learner', 'owner_id' => $owner->id, 'visibility' => 'private']);
    $titles = app(DeckTitles::class);

    expect($titles->stageIn($deck, 'uz', 'Моя колода', 'что-то'))->toBe(__('decks.stage', [], 'uz'))
        ->and($titles->lessonIn($deck, 'ru', 2, 'Часть 2', 'x'))->toBe(__('decks.part', ['n' => 2], 'ru'));
});

// --- the page of a course and its cache -----------------------------------------------------------------------------

it('shows a uz learner the uz names, the ru ones where there is no uz name, and null where there is none - and a ru learner the ru ones', function (): void {
    $fx = ltCourse();
    $uz = Learner::factory()->create(['interface_locale' => 'uz']);
    $ru = Learner::factory()->create(['interface_locale' => 'ru']);

    expect(ltPage($this, $uz, 'titles'))->toBe([
        ['Tanishuv', ['Salomlashish', 'Местоимения', null]],
        ['Семья', ['Дом']],
        [null, ['Ovqat']],
    ])->and(ltPage($this, $ru, 'titles'))->toBe([
        ['Знакомство', ['Приветствия', 'Местоимения', null]],
        ['Семья', ['Дом']],
        [null, [null]],
    ]);
});

it('never hands a uz learner the names cached for a ru learner, nor the other way round', function (): void {
    $fx = ltCourse('first');
    $uz = Learner::factory()->create(['interface_locale' => 'uz']);
    $ru = Learner::factory()->create(['interface_locale' => 'ru']);

    // ru first, uz second: the tree is in the cache after the first read.
    expect(ltPage($this, $ru, 'first')[0])->toBe(['Знакомство', ['Приветствия', 'Местоимения', null]]);
    expect(PureCache::store()->has(CourseCatalogService::courseTreeKey($fx['course']->id, 'ru')))->toBeTrue();
    expect(ltPage($this, $uz, 'first')[0])->toBe(['Tanishuv', ['Salomlashish', 'Местоимения', null]]);

    // uz first, ru second on another course.
    $second = ltCourse('second');
    expect(ltPage($this, $uz, 'second')[0])->toBe(['Tanishuv', ['Salomlashish', 'Местоимения', null]]);
    expect(PureCache::store()->has(CourseCatalogService::courseTreeKey($second['course']->id, 'uz')))->toBeTrue();
    expect(ltPage($this, $ru, 'second')[0])->toBe(['Знакомство', ['Приветствия', 'Местоимения', null]]);

    // The cached entries themselves: the uz one holds no ru name that has a uz twin, and the other way round.
    /** @var list<array{stage_title: string|null, lesson_title: string|null}> $uzTree */
    $uzTree = PureCache::store()->get(CourseCatalogService::courseTreeKey($second['course']->id, 'uz'));
    /** @var list<array{stage_title: string|null, lesson_title: string|null}> $ruTree */
    $ruTree = PureCache::store()->get(CourseCatalogService::courseTreeKey($second['course']->id, 'ru'));
    expect(array_column($uzTree, 'stage_title'))->not->toContain('Знакомство')
        ->and(array_column($uzTree, 'lesson_title'))->not->toContain('Приветствия')
        ->and(array_column($ruTree, 'stage_title'))->not->toContain('Tanishuv')
        ->and(array_column($ruTree, 'lesson_title'))->not->toContain('Salomlashish', 'Ovqat');

    // A learner who switches language reads the other names at once (another key, no stale entry).
    $ru->update(['interface_locale' => 'uz']);
    expect(ltPage($this, $ru->refresh(), 'first')[0][0])->toBe('Tanishuv');
});

it('drops both trees when a uz name is edited in the panel, so the uz learner reads the new name and the ru one is untouched', function (): void {
    $fx = ltCourse();
    $uz = Learner::factory()->create(['interface_locale' => 'uz']);
    $ru = Learner::factory()->create(['interface_locale' => 'ru']);
    expect(ltPage($this, $uz, 'titles')[1][0])->toBe('Семья')
        ->and(ltPage($this, $ru, 'titles')[1][0])->toBe('Семья');

    $fx['ru']->update(['title_uz' => 'Oila']);
    $fx['lessons']['ru']->update(['title_uz' => 'Olmoshlar']);

    expect(ltPage($this, $uz, 'titles'))->toBe([
        ['Tanishuv', ['Salomlashish', 'Olmoshlar', null]],
        ['Oila', ['Дом']],
        [null, ['Ovqat']],
    ])->and(ltPage($this, $ru, 'titles')[1][0])->toBe('Семья')
        ->and(ltPage($this, $ru, 'titles')[0][1][1])->toBe('Местоимения');
});

it('names the next lesson of Today and a lesson read with its context in the learner\'s language', function (): void {
    $fx = ltCourse();
    $uz = Learner::factory()->onboarded()->create(['interface_locale' => 'uz', 'active_course_id' => $fx['course']->id]);
    $ru = Learner::factory()->onboarded()->create(['interface_locale' => 'ru', 'active_course_id' => $fx['course']->id]);

    $this->withHeaders(ltHeaders($uz))->getJson('/api/twa/today')->assertOk()
        ->assertJsonPath('active_course.next_lesson.title', 'Salomlashish');
    $this->withHeaders(ltHeaders($ru))->getJson('/api/twa/today')->assertOk()
        ->assertJsonPath('active_course.next_lesson.title', 'Приветствия');

    // The training start reads the lesson with its module in one joined row: the module's uz name travels with it.
    $catalog = app(CourseCatalogService::class);
    $lesson = $catalog->lessonWithContext($fx['lessons']['both']->id);
    expect($lesson)->not->toBeNull()
        ->and($lesson?->stage?->title_uz)->toBe('Tanishuv')
        ->and(app(DeckTitles::class)->forLesson($lesson, $uz))->toBe('Salomlashish')
        ->and(app(DeckTitles::class)->forLesson($lesson, $ru))->toBe('Приветствия')
        ->and(app(DeckTitles::class)->stage($fx['course'], $uz, $lesson->stage))->toBe('Tanishuv');
});

// --- the import -----------------------------------------------------------------------------------------------------

/** @param  array<string, mixed>  $stage  @param  array<string, mixed>  $lesson */
function ltImport(Course $course, array $stage, array $lesson, string $word = 'hello'): void
{
    $json = json_encode([
        'course' => $course->slug,
        'stage' => ['number' => 1, ...$stage],
        'lesson' => ['number' => 1, ...$lesson],
        'words' => [['word' => $word, 'translation' => 'привет', 'translations' => ['ru' => 'привет', 'uz' => 'salom']]],
    ], JSON_THROW_ON_ERROR);

    $report = app(VocabularyImporter::class)->importAsSystem($json);
    expect($report->aborted)->toBeFalse($report->issues === [] ? '' : json_encode($report->issues, JSON_UNESCAPED_UNICODE));
}

it('imports stage.title_uz and lesson.title_uz, idempotently, and treats a blank one as no name', function (): void {
    $course = Course::factory()->create(['slug' => 'imp']);

    ltImport($course, ['title' => 'Знакомство', 'title_uz' => 'Tanishuv'], ['title' => 'Приветствия', 'title_uz' => 'Salomlashish']);
    $stage = Stage::query()->where('course_id', $course->id)->where('number', 1)->sole();
    $lesson = Lesson::query()->where('stage_id', $stage->id)->where('number', 1)->sole();
    expect([$stage->title, $stage->title_uz])->toBe(['Знакомство', 'Tanishuv'])
        ->and([$lesson->title, $lesson->title_uz])->toBe(['Приветствия', 'Salomlashish']);

    // The same file again: nothing changes (no new module or lesson, same names).
    $updated = [$stage->updated_at?->toDateTimeString(), $lesson->updated_at?->toDateTimeString()];
    $this->travel(1)->minutes();
    ltImport($course, ['title' => 'Знакомство', 'title_uz' => 'Tanishuv'], ['title' => 'Приветствия', 'title_uz' => 'Salomlashish']);
    expect(Stage::query()->where('course_id', $course->id)->count())->toBe(1)
        ->and(Lesson::query()->where('stage_id', $stage->id)->count())->toBe(1)
        ->and([$stage->refresh()->title_uz, $lesson->refresh()->title_uz])->toBe(['Tanishuv', 'Salomlashish'])
        ->and([$stage->updated_at?->toDateTimeString(), $lesson->updated_at?->toDateTimeString()])->toBe($updated);

    // A file without the uz name of the module leaves the one it has alone (as with `title`); a blank one is no name.
    ltImport($course, ['title' => 'Знакомство'], ['title' => 'Приветствия', 'title_uz' => '   '], 'bye');
    expect($stage->refresh()->title_uz)->toBe('Tanishuv')
        ->and($lesson->refresh()->title_uz)->toBeNull();

    // A new uz name of the module and of the lesson in a later file wins.
    ltImport($course, ['title' => 'Знакомство', 'title_uz' => 'Tanishuv va odamlar'], ['title' => 'Приветствия', 'title_uz' => 'Salom'], 'hi');
    expect($stage->refresh()->title_uz)->toBe('Tanishuv va odamlar')
        ->and($lesson->refresh()->title_uz)->toBe('Salom');

    // A file without the `title_uz` key leaves the lesson's name alone; with the key blank - NULL.
    ltImport($course, ['title' => 'Знакомство'], ['title' => 'Приветствия'], 'again');
    expect($lesson->refresh()->title_uz)->toBe('Salom');
    ltImport($course, ['title' => 'Знакомство'], ['title' => 'Приветствия', 'title_uz' => null], 'blank');
    expect($lesson->refresh()->title_uz)->toBeNull();
});

it('rejects a title_uz that is not a string or is too long', function (): void {
    $course = Course::factory()->create(['slug' => 'imp-bad']);
    $import = fn (array $stage, array $lesson): bool => app(VocabularyImporter::class)->importAsSystem((string) json_encode([
        'course' => $course->slug,
        'stage' => ['number' => 1, ...$stage],
        'lesson' => ['number' => 1, ...$lesson],
        'words' => [['word' => 'hello', 'translation' => 'привет']],
    ]))->aborted;

    expect($import(['title_uz' => ['x']], []))->toBeTrue()
        ->and($import([], ['title_uz' => str_repeat('a', 256)]))->toBeTrue()
        ->and($import(['title_uz' => null], ['title_uz' => null]))->toBeFalse('null is allowed: no name');
});

it('imports the uz names of the modules and lessons of the A1-A2 pack files', function (): void {
    $file = base_path('database/data/courses/english-a1-a2/m01-l1.json');
    $data = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
    $course = Course::factory()->create(['slug' => 'english-a1-a2']);

    $report = app(VocabularyImporter::class)->importAsSystem((string) file_get_contents($file));
    expect($report->aborted)->toBeFalse();

    $stage = Stage::query()->where('course_id', $course->id)->where('number', 1)->sole();
    expect($stage->title_uz)->toBe($data['stage']['title_uz'])
        ->and($stage->lessons()->where('number', 1)->sole()->title_uz)->toBe($data['lesson']['title_uz']);
});

// --- practice: a snapshot read by two learners ---------------------------------------------------------------------

it('keeps the lesson name per locale in a battle snapshot, so each participant reads it in their language', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $lesson->update(['title_uz' => 'Ovqat']);

    $battle = BattleKit::fresh(BattleKit::invite($me, $friendship, PracticeMode::Sentence, $course, $lesson));
    expect($battle->snapshot['lesson_titles'])->toBe(['ru' => 'Еда', 'uz' => 'Ovqat'])
        ->and($battle->snapshot['lesson_title'])->toBe('Еда', 'the string of the pre-v2 code: the first locale')
        ->and($battle->snapshot['v'])->toBe(Snapshot::VERSION);

    $mine = $this->withHeaders(PracticeKit::headers($me))->getJson("/api/twa/practice/battles/{$battle->id}")->assertOk();
    $theirs = $this->withHeaders(PracticeKit::headers($friend))->getJson("/api/twa/practice/battles/{$battle->id}")->assertOk();
    expect($mine->json('lesson.title'))->toBe('Еда')
        ->and($theirs->json('lesson.title'))->toBe('Ovqat');

    // A snapshot written before (v1): one string, read as the name in every locale; no entry at all - no name.
    $v1 = array_diff_key($battle->snapshot, ['lesson_titles' => 1]);
    $legacy = Snapshot::fromArray([...$v1, 'lesson_title' => 'Еда', 'v' => 1]);
    expect($legacy->lessonTitle)->toBe(['ru' => 'Еда', 'uz' => 'Еда'])
        ->and($legacy->lessonTitleFor('uz'))->toBe('Еда')
        ->and(Snapshot::fromArray([...$v1, 'lesson_title' => null])->lessonTitleFor('uz'))->toBeNull()
        ->and(Snapshot::fromArray([...$battle->snapshot, 'lesson_title' => 'Еда', 'lesson_titles' => ['ru' => 'Еда']])->lessonTitleFor('uz'))->toBeNull('no name of its own: no other locale\'s');
});

it('writes the snapshot so that the pre-v2 code reads it after a rollback', function (): void {
    $snapshot = new Snapshot(PracticeMode::Sentence, ['uz', 'ru'], [], lessonTitle: ['ru' => 'Еда', 'uz' => 'Ovqat']);
    $data = $snapshot->toArray();

    expect($data['lesson_title'])->toBe('Ovqat', 'a string - the first locale of the snapshot, as the old code wrote it')
        ->and($data['lesson_titles'])->toBe(['ru' => 'Еда', 'uz' => 'Ovqat'])
        ->and(Snapshot::fromArray($data)->lessonTitle)->toBe(['ru' => 'Еда', 'uz' => 'Ovqat'])
        // only the v1 string: the name in every locale
        ->and(Snapshot::fromArray(['mode' => 'sentence', 'locales' => ['ru', 'uz'], 'lesson_title' => 'Еда', 'v' => 1])->lessonTitle)->toBe(['ru' => 'Еда', 'uz' => 'Еда'])
        // both keys: the map wins over the string
        ->and(Snapshot::fromArray([...$data, 'lesson_title' => 'other'])->lessonTitle)->toBe(['ru' => 'Еда', 'uz' => 'Ovqat'])
        ->and((new Snapshot(PracticeMode::Sentence, ['ru'], []))->toArray()['lesson_title'])->toBeNull();
});

it('names the lesson in the options of a battle by the viewer locale', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $lesson->update(['title_uz' => 'Ovqat']);
    $url = "/api/twa/practice/battles/options?friendship_id={$friendship->id}&course={$course->slug}&lesson_id={$lesson->id}";

    $ru = $this->withHeaders(PracticeKit::headers($me))->getJson($url)->assertOk();
    $me->update(['interface_locale' => 'uz']);
    $uz = $this->withHeaders(PracticeKit::headers($me->refresh()))->getJson($url)->assertOk();

    expect($ru->json('lesson.title'))->toBe('Еда')
        ->and($uz->json('lesson.title'))->toBe('Ovqat');
});

// --- the admin panel -------------------------------------------------------------------------------------------------

it('saves the uz name of a module and of a lesson from the panel, an empty field as NULL', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');
    $this->actingAs($admin);
    $course = Course::factory()->create();

    livewire(CreateStage::class)
        ->fillForm(['course_id' => $course->id, 'number' => 1, 'title' => 'Семья', 'title_uz' => 'Oila'])
        ->call('create')
        ->assertHasNoFormErrors();
    $stage = Stage::query()->where('course_id', $course->id)->where('number', 1)->sole();
    expect([$stage->title, $stage->title_uz])->toBe(['Семья', 'Oila']);

    livewire(EditStage::class, ['record' => $stage->getKey()])
        ->assertSet('data.title_uz', 'Oila')
        ->fillForm(['title_uz' => '   '])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($stage->refresh()->title_uz)->toBeNull();

    $lesson = Lesson::factory()->for($stage)->create(['number' => 1, 'title' => 'Дом', 'title_uz' => null]);
    livewire(EditLesson::class, ['record' => $lesson->getKey()])
        ->fillForm(['title_uz' => 'Uy'])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($lesson->refresh()->title_uz)->toBe('Uy');

    livewire(EditLesson::class, ['record' => $lesson->getKey()])
        ->fillForm(['title_uz' => ''])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($lesson->refresh()->title_uz)->toBeNull();

    // The version of the tree moved on with every save (CourseTreeObserver): a learner reads the new name at once.
    expect((int) Cache::get(CourseCatalogService::courseTreeVersionKey($course->id)))->toBeGreaterThanOrEqual(4);
});
