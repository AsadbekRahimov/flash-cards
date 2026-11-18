<?php

declare(strict_types=1);

use App\Domain\Content\Services\CoursePackImporter;
use App\Domain\Content\Services\CoursePublisher;
use App\Domain\Practice\Enums\PracticeItemStatus;
use App\Domain\Practice\Enums\PracticeItemType;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Exceptions\PracticeContentException;
use App\Domain\Practice\Services\PracticeImporter;
use App\Domain\Practice\Services\PracticeSessionService;
use App\Domain\Practice\Support\PracticeContentValidator;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\PracticeItem;
use App\Models\PracticeItemVersion;
use App\Models\PracticeSession;
use App\Models\Word;
use App\Models\WordRepetition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Practice\PracticeKit;
use Tests\Support\Practice\PracticePackRules;

uses(RefreshDatabase::class);

/*
 * The packs of the two newer types, «Word form» and «Find the mistake» (docs/16 §5.6, §5.7, §15.1): the files
 * database/data/practice/<course>-x01.json … -x10.json. File xNN holds the modules 2·NN-1 and 2·NN whole: 3 tasks of each type in every
 * one of their ten lessons (60 tasks); the course is complete with its ten files (600). The checks run over every x-file there is - a
 * course without one is skipped - and over the pilot sample of the tests (tests/Fixtures/Practice: two lessons of english-b1), so the
 * rules are proven before the first real file comes. The rules of the tasks themselves are PracticePackRules', shared with
 * docs/content-authoring/check_practice.php; the p-files (PracticePackTest) are never matched here.
 */

const PXK_TYPES = ['word_form', 'error_hunt'];

const PXK_DIR = 'database/data/practice';

const PXK_FIXTURE = 'tests/Fixtures/Practice';

/** The lessons the pilot sample covers, completely. */
const PXK_FIXTURE_LESSONS = ['1.1', '1.2'];

/**
 * Every course that may have x-files. Per course: the folder of its cards, the prefix of the keys, the tasks of each type a lesson,
 * the level by the module, the lesson of a module the session test opens, the lower-case names its sentences may use (not in the
 * lexicon) and `unwritten` - task key => why its target word is not written in its sentence.
 *
 * @return array<string, array{dir: string, prefix: string, per_type: int, level: Closure(int): string, lesson: Closure(int): int, names: list<string>, unwritten: array<string, string>}>
 */
function pxk_courses(): array
{
    $row = static fn (string $slug, string $prefix, Closure $level): array => [
        'dir' => "database/data/courses/{$slug}",
        'prefix' => $prefix,
        'per_type' => 3,
        'level' => $level,
        'lesson' => static fn (int $module): int => ($module - 1) % 5 + 1,
        'names' => [],
        'unwritten' => [],
    ];

    return [
        'english-a1-a2' => $row('english-a1-a2', 'a1a2', static fn (int $module): string => $module <= 10 ? 'A1' : 'A2'),
        'english-b1' => $row('english-b1', 'b1', static fn (int $module): string => 'B1'),
        'english-b2' => $row('english-b2', 'b2', static fn (int $module): string => 'B2'),
        'english-c1' => $row('english-c1', 'c1', static fn (int $module): string => 'C1'),
        'english-c2' => $row('english-c2', 'c2', static fn (int $module): string => 'C2'),
    ];
}

dataset('pxk_courses', function (): array {
    $rows = [];
    foreach (array_keys(pxk_courses()) as $slug) {
        $rows[$slug] = [$slug];
    }

    return $rows;
});

/** @return list<string> the x-files of the course in the folder (absolute paths, in order) */
function pxk_files(string $slug, string $dir = PXK_DIR): array
{
    $files = array_filter(glob(base_path("{$dir}/{$slug}-x*.json")) ?: [], static fn (string $file): bool => preg_match(PracticePackRules::EXTRA_FILE, $file) === 1);
    natsort($files);

    return array_values($files);
}

/** The x-files of the course, or the test is skipped: a course gets its checks with its first file. @return list<string> */
function pxk_files_or_skip(string $slug): array
{
    $files = pxk_files($slug);
    if ($files === []) {
        test()->markTestSkipped("No x-file of {$slug} yet.");
    }

    return $files;
}

/** @return array<string, mixed> */
function pxk_read(string $file): array
{
    return json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
}

/**
 * @param  list<string>  $files
 * @return list<array<string, mixed>>
 */
function pxk_tasks(array $files): array
{
    $tasks = [];
    foreach ($files as $file) {
        foreach (pxk_read($file)['items'] as $task) {
            $tasks[] = $task;
        }
    }

    return $tasks;
}

/** @return array<string, string> every word of the course => "module.lesson" */
function pxk_course_words(string $slug): array
{
    static $words = [];
    if (! isset($words[$slug])) {
        $words[$slug] = [];
        foreach (glob(base_path(pxk_courses()[$slug]['dir']).'/m*-l*.json') ?: [] as $file) {
            $data = pxk_read($file);
            foreach ($data['words'] as $word) {
                $words[$slug][$word['word']] = $data['stage']['number'].'.'.$data['lesson']['number'];
            }
        }
    }

    return $words[$slug];
}

/** @param array<string, mixed> $task */
function pxk_lesson(array $task): string
{
    return $task['lesson']['stage'].'.'.$task['lesson']['number'];
}

/** The keys of the p-files of a course: an x-key must not be one of them. @return array<string, true> */
function pxk_p_keys(string $slug): array
{
    $keys = [];
    foreach (glob(base_path(PXK_DIR."/{$slug}*.json")) ?: [] as $file) {
        if (preg_match(PracticePackRules::EXTRA_FILE, $file) === 1) {
            continue;
        }
        foreach (pxk_read($file)['items'] ?? [] as $task) {
            $keys[(string) ($task['key'] ?? '')] = true;
        }
    }

    return $keys;
}

/**
 * The files themselves: the format, the course, at most 300 tasks, keys by the pattern and unique in the course (with the keys of the
 * p-files), the lessons of the file - those of its two modules (or the `$lessons` given) - with exactly `per_type` tasks of each type,
 * the digit of a key once per lesson and type.
 *
 * @param  list<string>  $files
 * @param  list<string>|null  $lessons  the lessons the files must hold completely; null: the two modules of each file by its number
 * @return list<string>
 */
function pxk_file_problems(string $slug, array $files, ?array $lessons = null): array
{
    $row = pxk_courses()[$slug];
    $limit = min(300, (int) config('practice.import.max_items'));
    $pKeys = pxk_p_keys($slug);
    $problems = [];
    $keys = [];
    $slots = [];
    $expected = [];

    foreach ($files as $file) {
        $name = basename($file);
        $data = pxk_read($file);
        if (array_keys($data) !== ['format', 'course', 'items'] || $data['format'] !== PracticeImporter::FORMAT || $data['course'] !== $slug) {
            $problems[] = "{$name}: not a pack of {$slug} in the format ".PracticeImporter::FORMAT;

            continue;
        }
        if (count($data['items']) > $limit) {
            $problems[] = "{$name}: more than {$limit} tasks";
        }
        if ($lessons === null) {
            $number = (int) substr($name, -7, 2);
            foreach ([2 * $number - 1, 2 * $number] as $module) {
                foreach (range(1, 5) as $lesson) {
                    $expected["{$module}.{$lesson}"] = $name;
                }
            }
        }

        foreach ($data['items'] as $task) {
            $key = (string) ($task['key'] ?? '');
            if (! in_array($task['type'] ?? null, PXK_TYPES, true)) {
                $problems[] = "{$name} {$key}: the type «".($task['type'] ?? '').'» is not one of an x-file';

                continue;
            }
            if (preg_match(PracticePackRules::EXTRA_KEY, $key) !== 1
                || $key !== sprintf('%s-m%02d-l%d-%s%s', $row['prefix'], $task['lesson']['stage'], $task['lesson']['number'], $task['type'] === 'word_form' ? 'w' : 'e', substr($key, -1))) {
                $problems[] = "{$name} {$key}: the key does not match its course, lesson and type";
            }
            if (isset($keys[$key]) || isset($pKeys[$key])) {
                $problems[] = "{$name} {$key}: the key is used twice";
            }
            $keys[$key] = true;
            $slots[pxk_lesson($task)][$task['type']][] = substr($key, -1);
            if ($lessons === null && ($expected[pxk_lesson($task)] ?? null) !== $name) {
                $problems[] = "{$name} {$key}: lesson ".pxk_lesson($task).' is not one of the modules of this file';
            }
        }
    }

    foreach ($lessons ?? array_keys($expected) as $lesson) {
        foreach (PXK_TYPES as $type) {
            $digits = $slots[$lesson][$type] ?? [];
            if (count($digits) !== $row['per_type'] || count(array_unique($digits)) !== count($digits)) {
                $problems[] = "lesson {$lesson}: ".count($digits)." {$type} tasks (keys ".implode(',', $digits)."), {$row['per_type']} needed";
            }
        }
    }
    if ($lessons !== null && array_diff(array_keys($slots), $lessons) !== []) {
        $problems[] = 'tasks outside the lessons '.implode(', ', $lessons);
    }

    return $problems;
}

/**
 * The tasks: the text passes the validator (as the import and the publication check it), the target words are words of THIS lesson,
 * written in the sentence by PracticePackRules (word form: the filled sentence or the base word; find the mistake: the corrected
 * sentence), the level of the module, uz in Latin with «Siz», ru with «вы», and every rule of PracticePackRules for the type.
 *
 * @param  list<array<string, mixed>>  $tasks
 * @return list<string>
 */
function pxk_task_problems(string $slug, array $tasks): array
{
    $row = pxk_courses()[$slug];
    $validator = app(PracticeContentValidator::class);
    $words = pxk_course_words($slug);
    $problems = [];
    $used = [];

    foreach ($tasks as $task) {
        $key = (string) $task['key'];
        $type = PracticeItemType::from($task['type']);
        try {
            $content = $validator->canonical($type, $task);
            $validator->assertPublishable($type, $content);
        } catch (PracticeContentException $e) {
            $problems[] = "{$key}: {$e->errorCode} ({$e->field}) - {$e->getMessage()}";

            continue;
        }

        if (($task['level'] ?? null) !== $row['level']($task['lesson']['stage'])) {
            $problems[] = "{$key}: level ".($task['level'] ?? 'none').' in module '.$task['lesson']['stage'];
        }
        if ($task['target_words'] === []) {
            $problems[] = "{$key}: no target word";
        }
        foreach ($task['target_words'] as $target) {
            $at = $words[mb_strtolower(trim($target))] ?? 'no lesson of the course';
            if ($at !== pxk_lesson($task)) {
                $problems[] = "{$key} (lesson ".pxk_lesson($task)."): «{$target}» is in {$at}";
            }
        }

        $rules = $type === PracticeItemType::WordForm
            ? PracticePackRules::wordFormProblems($content, $task['target_words'])
            : PracticePackRules::errorHuntProblems($content, $task['target_words'], $row['names']);
        foreach ($rules as $problem) {
            if (str_starts_with($problem, 'target word') && isset($row['unwritten'][$key])) {
                $used[$key] = true;

                continue;
            }
            $problems[] = "{$key}: {$problem}";
        }

        foreach (['translation', 'explanation'] as $field) {
            foreach ((array) $task[$field] as $locale => $text) {
                $where = "{$key} {$field}.{$locale}";
                if ($locale === 'uz') {
                    if (preg_match('/[’ʻʼ‘`]|\p{Cyrillic}/u', (string) $text) === 1) {
                        $problems[] = "{$where}: Cyrillic or a typographic apostrophe";
                    }
                    foreach (PracticePackRules::uzInformal((string) $text) as $word) {
                        $problems[] = "{$where}: «{$word}»";
                    }
                } else {
                    foreach (PracticePackRules::ruInformal((string) $text) as $word) {
                        $problems[] = "{$where}: «{$word}»";
                    }
                }
            }
        }
    }

    // An exception stays only while the task needs it.
    foreach (array_diff(array_keys($row['unwritten']), array_keys($used)) as $key) {
        $problems[] = "{$key}: listed in `unwritten` but its target word is written";
    }

    return $problems;
}

/** The course imported from its pack, as a draft. */
function pxk_import_course(string $slug): Course
{
    $report = app(CoursePackImporter::class)->import(base_path(pxk_courses()[$slug]['dir']), create: true);
    expect($report->errors)->toBe([]);

    return Course::query()->where('slug', $slug)->firstOrFail();
}

/**
 * Imports the course and the files: added, then skipped, then published by `practice:import --publish`; then a lesson session of both
 * modes opens in every lesson of `$lessons`, in ru and in uz by turns, with exactly the tasks of that lesson in the learner's language.
 *
 * @param  list<string>  $files
 * @param  list<string>  $lessons  "module.lesson"
 */
function pxk_import_and_play(string $slug, array $files, array $lessons): void
{
    Queue::fake();
    $course = pxk_import_course($slug);
    $importer = app(PracticeImporter::class);
    $tasks = pxk_tasks($files);

    foreach ($files as $file) {
        $count = count(pxk_read($file)['items']);
        expect($importer->importAsSystem((string) file_get_contents($file), false)->toArray())
            ->toBe(['added' => $count, 'updated' => 0, 'skipped' => 0, 'published' => 0, 'errors' => [], 'aborted' => false], basename($file));
    }
    $versions = PracticeItemVersion::query()->count();
    foreach ($files as $file) {
        $count = count(pxk_read($file)['items']);
        expect($importer->importAsSystem((string) file_get_contents($file), false)->toArray())
            ->toBe(['added' => 0, 'updated' => 0, 'skipped' => $count, 'published' => 0, 'errors' => [], 'aborted' => false], basename($file));
        expect(Artisan::call('practice:import', ['file' => $file, '--publish' => true]))->toBe(0, basename($file))
            ->and(Artisan::output())->toContain('Practice import finished.');
    }
    expect(PracticeItem::query()->where('course_id', $course->id)->where('status', PracticeItemStatus::Published)->count())->toBe(count($tasks))
        ->and(PracticeItemVersion::query()->count())->toBe($versions);

    PracticeKit::openAccess();
    config(['practice.modes.word_form' => true, 'practice.modes.error_hunt' => true]);
    $course->forceFill(['status' => CoursePublisher::PUBLISHED, 'published_at' => now()])->save();

    $byPlace = [];
    foreach (Lesson::query()->with('stage')->whereHas('stage', fn ($query) => $query->where('course_id', $course->id))->get() as $lesson) {
        $byPlace[$lesson->stage->number.'.'.$lesson->number] = $lesson->id;
    }
    $chosen = array_map(static fn (string $place): int => $byPlace[$place], $lessons);

    $learners = ['ru' => Learner::factory()->create(['interface_locale' => 'ru']), 'uz' => Learner::factory()->create(['interface_locale' => 'uz'])];
    $rows = [];
    foreach ($learners as $learner) {
        foreach (Word::query()->whereIn('lesson_id', $chosen)->pluck('id') as $word) {
            $rows[] = ['learner_id' => $learner->id, 'word_id' => $word, 'state' => 'new', 'next_review_at' => now(), 'created_at' => now(), 'updated_at' => now()];
        }
    }
    WordRepetition::query()->insert($rows);

    $byKey = [];
    foreach ($tasks as $task) {
        $byKey[$task['key']] = $task;
    }
    $keys = PracticeItem::query()->where('course_id', $course->id)->pluck('key', 'id');
    $sessions = app(PracticeSessionService::class);
    $expected = min((int) config('practice.solo.items.size'), pxk_courses()[$slug]['per_type']);

    foreach ($lessons as $turn => $place) {
        $locale = $turn % 2 === 0 ? 'ru' : 'uz';
        foreach ([PracticeMode::WordForm, PracticeMode::ErrorHunt] as $mode) {
            $where = "{$place} {$mode->value} {$locale}";
            $session = $sessions->open($learners[$locale], $mode, $slug, $byPlace[$place], PracticeSession::SOURCE_HUB, true, null);

            expect($session->total)->toBe($expected, $where)
                ->and($session->locale)->toBe($locale);
            foreach ($session->snapshot['elements'] as $element) {
                $key = $keys[$element['item_id']] ?? null;
                expect($key !== null && pxk_lesson($byKey[$key]) === $place)->toBeTrue("{$where}: an element of another lesson")
                    ->and($element['translation'])->toBe([$locale => $byKey[$key]['translation'][$locale]], "{$where}: {$key}");
            }
        }
    }
}

// ---------------------------------------------------------------- the files of the courses

it('keeps every x-file in the folder of the practice a pack of exactly one course, named after it', function (): void {
    foreach (glob(base_path(PXK_DIR.'/*.json')) ?: [] as $file) {
        if (preg_match(PracticePackRules::EXTRA_FILE, $file) !== 1) {
            continue;
        }
        $owners = array_keys(array_filter(pxk_courses(), static fn (array $row, string $slug): bool => in_array($file, pxk_files($slug), true), ARRAY_FILTER_USE_BOTH));
        expect($owners)->toHaveCount(1, basename($file))
            ->and(pxk_read($file)['course'] ?? null)->toBe($owners[0] ?? null, basename($file));
    }

    // The course of a name never takes the files of a longer one: english-b1 is no prefix of an x-file of english-b2.
    expect(fnmatch('english-b1-x*.json', 'english-b2-x01.json'))->toBeFalse();
});

it('keeps the x-files of the course whole: the format, the keys, two modules a file with 3 tasks of each type in every lesson', function (string $slug): void {
    $files = pxk_files_or_skip($slug);

    expect(pxk_file_problems($slug, $files))->toBe([])
        ->and(count(pxk_tasks($files)))->toBe(60 * count($files));
})->with('pxk_courses');

it('keeps the tasks of the x-files right: the validator, the target words of the lesson, the level, the languages, the grading', function (string $slug): void {
    expect(pxk_task_problems($slug, pxk_tasks(pxk_files_or_skip($slug))))->toBe([]);
})->with('pxk_courses');

it('imports the x-files after the course without a refusal, again as a no-op, publishes them, and opens both modes in a lesson of every module', function (string $slug): void {
    $files = pxk_files_or_skip($slug);
    $modules = [];
    foreach (pxk_tasks($files) as $task) {
        $modules[$task['lesson']['stage']] = true;
    }
    $lessons = array_map(static fn (int $module): string => $module.'.'.pxk_courses()[$slug]['lesson']($module), array_keys($modules));

    pxk_import_and_play($slug, $files, $lessons);
})->with('pxk_courses');

// ---------------------------------------------------------------- the pilot sample: the rules proven before the first real file

it('passes every check on the pilot sample of the tests', function (): void {
    $files = pxk_files('english-b1', PXK_FIXTURE);

    expect($files)->toHaveCount(1)
        ->and(pxk_file_problems('english-b1', $files, PXK_FIXTURE_LESSONS))->toBe([])
        ->and(pxk_task_problems('english-b1', pxk_tasks($files)))->toBe([]);
});

it('imports the pilot sample and plays both modes in both of its lessons, in ru and in uz', function (): void {
    pxk_import_and_play('english-b1', pxk_files('english-b1', PXK_FIXTURE), PXK_FIXTURE_LESSONS);
});

it('finds the faults an author makes in a pack of the newer types', function (): void {
    $tasks = pxk_tasks(pxk_files('english-b1', PXK_FIXTURE));
    $byKey = array_column($tasks, null, 'key');
    $broken = [
        // a typo in a right form: a learner who writes the right word gets a «mistake»
        [...$byKey['b1-m01-l1-w1'], 'accepted' => ['signatrue']],
        // the right form in the sentence already
        [...$byKey['b1-m01-l1-w2'], 'text' => 'Personal data is ___ information.'],
        // a wrong target word: of another lesson, and not written
        [...$byKey['b1-m01-l1-e1'], 'target_words' => ['childhood']],
        // «ты» and «sen»
        [...$byKey['b1-m01-l2-e1'], 'explanation' => ['ru' => 'Ты должен запомнить went.', 'uz' => 'Sen went ni eslab qol.']],
        // the level of another course
        [...$byKey['b1-m01-l2-w3'], 'level' => 'B2'],
        // two words marked
        [...$byKey['b1-m01-l2-e2'], 'text' => 'People of *my generation* use paper maps.'],
        // the right correction alone in capitals
        [...$byKey['b1-m01-l2-e3'], 'options' => ['An', 'some', 'many'], 'correct' => ['An']],
    ];

    $problems = implode("\n", pxk_task_problems('english-b1', $broken));

    expect($problems)->toContain('b1-m01-l1-w1: «signatrue» of the right form «signatrue» is not in the lexicon')
        ->toContain('b1-m01-l1-w2: the right form «personal» is in the sentence already')
        ->toContain('b1-m01-l1-e1 (lesson 1.1): «childhood» is in 1.2')
        ->toContain('b1-m01-l1-e1: target word «childhood» is not in the corrected sentence')
        ->toContain('b1-m01-l2-e1 explanation.ru: «ты»')
        ->toContain('b1-m01-l2-e1 explanation.uz: «Sen»')
        ->toContain('b1-m01-l2-w3: level B2 in module 1')
        ->toContain('b1-m01-l2-e2: error_mark_invalid')
        ->toContain('b1-m01-l2-e3: options_case (options)');

    // The rules of the packs say the same of stored content that never met the validator: the case of the corrections gives the
    // answer away - one capital inside the sentence, a small letter where the mistake is the first word.
    $stored = app(PracticeContentValidator::class)->canonical(PracticeItemType::ErrorHunt, $byKey['b1-m01-l2-e3']);
    $inside = PracticePackRules::errorHuntProblems([...$stored, 'options' => ['an', 'Some', 'many']], $byKey['b1-m01-l2-e3']['target_words']);
    $first = PracticePackRules::errorHuntProblems(
        [...$stored, 'text' => 'A elderly man crossed the road.', 'error_index' => 0, 'error' => 'A', 'options' => ['An', 'some', 'Many']],
        [],
    );
    expect($inside)->toContain('the corrections start with letters of different case: the odd one gives the answer away')
        ->and(implode("\n", $first))->toContain('the mistake is the first word, but a correction starts with a small letter')
        ->and(PracticePackRules::errorHuntProblems([...$stored, 'text' => 'A elderly man crossed the road.', 'error_index' => 0, 'error' => 'A', 'options' => ['An', 'Some', 'Many']], []))
        ->toBe([]);

    // Read as a real file x01, the sample lacks the other eight lessons of its modules 1 and 2 - and nothing else.
    $whole = pxk_file_problems('english-b1', pxk_files('english-b1', PXK_FIXTURE));
    expect($whole)->toHaveCount(16)
        ->toContain('lesson 1.3: 0 word_form tasks (keys ), 3 needed')
        ->toContain('lesson 2.5: 0 error_hunt tasks (keys ), 3 needed');

    // A lesson short of a task, and a key of the wrong type.
    $file = sys_get_temp_dir().'/pxk-'.bin2hex(random_bytes(4)).'/english-b1-x01.json';
    mkdir(dirname($file));
    $data = pxk_read(pxk_files('english-b1', PXK_FIXTURE)[0]);
    array_pop($data['items']);
    $data['items'][0]['key'] = 'b1-m01-l1-e9';
    file_put_contents($file, json_encode($data, JSON_THROW_ON_ERROR));

    expect(pxk_file_problems('english-b1', [$file], PXK_FIXTURE_LESSONS))->toBe([
        'english-b1-x01.json b1-m01-l1-e9: the key does not match its course, lesson and type',
        'lesson 1.2: 2 error_hunt tasks (keys 1,2), 3 needed',
    ]);
    unlink($file);
    rmdir(dirname($file));
});

it('passes the checker of the authors on the pilot sample, and the checker reports the faults of a broken copy', function (): void {
    $sample = base_path(PXK_FIXTURE.'/english-b1-x01.json');
    $ok = Process::path(base_path())->timeout(120)->run(['php', 'docs/content-authoring/check_practice.php', $sample]);

    expect($ok->exitCode())->toBe(0, $ok->output().$ok->errorOutput())
        ->and($ok->output())->toContain('12 tasks checked, 0 errors');

    $dir = sys_get_temp_dir().'/pxk-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $data = pxk_read($sample);
    $data['items'][0]['accepted'] = ['signatrue'];
    $data['items'][3]['options'] = ['is', 'are', 'am'];
    $data['items'][4]['key'] = 'b1-m01-l1-w9';
    file_put_contents("{$dir}/english-b1-x01.json", json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
    $bad = Process::path(base_path())->timeout(120)->run(['php', 'docs/content-authoring/check_practice.php', "{$dir}/english-b1-x01.json"]);
    array_map('unlink', glob("{$dir}/*") ?: []);
    rmdir($dir);

    expect($bad->exitCode())->toBe(1)
        ->and($bad->output())->toContain('ERROR english-b1-x01.json b1-m01-l1-w1: «signatrue» of the right form «signatrue» is not in the lexicon')
        ->toContain('ERROR english-b1-x01.json b1-m01-l1-e1: validator: An option is the wrong word itself.')
        ->toContain('ERROR english-b1-x01.json b1-m01-l1-w9: key must be b1-m01-l1-e1..3 for this lesson and type')
        ->toContain('not in the canonical layout');
});
