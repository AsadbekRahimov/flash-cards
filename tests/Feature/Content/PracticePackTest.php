<?php

declare(strict_types=1);

use App\Domain\Content\Services\CoursePackImporter;
use App\Domain\Content\Services\CoursePublisher;
use App\Domain\Practice\Enums\AudioStatus;
use App\Domain\Practice\Enums\PracticeItemStatus;
use App\Domain\Practice\Enums\PracticeItemType;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Enums\Verdict;
use App\Domain\Practice\Services\Grading\DictationGrader;
use App\Domain\Practice\Services\Grading\SentenceGrader;
use App\Domain\Practice\Services\PracticeAudioService;
use App\Domain\Practice\Services\PracticeImporter;
use App\Domain\Practice\Services\PracticeSessionService;
use App\Domain\Practice\Support\EnglishLexicon;
use App\Domain\Practice\Support\PracticeContentValidator;
use App\Domain\Practice\Support\PracticeTextNormalizer;
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
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Practice\PracticeKit;
use Tests\Support\Practice\PracticePackRules;

uses(RefreshDatabase::class);

/*
 * The practice packs of the courses of ppk_courses() (a1-a2: database/data/practice/english-a1-a2*.json, docs/CONTENT_A1_A2.md,
 * 936 tasks; b1 and b2: english-b1-p*.json and english-b2-p*.json, 900 tasks each, exactly three tasks of each type in a lesson), each in files the importer takes whole (300 at most each). The files must keep: unique keys, at least three tasks of each
 * authored type in every one of the 100 lessons, target words of the task's own lesson only, the level of the module (1-10 A1,
 * 11-20 A2), uz in Latin script on «Siz» and ru on «вы», the writings of a dictation by the one rule of docs/16 §5.2, every
 * accepted writing and order graded `correct`, every word known to the lexicon of the dictation. Imported after the course: no
 * refusal, a second import changes nothing, `practice:import --publish` passes, and a lesson of every module opens a solo session
 * of each authored mode in ru and in uz.
 */

const PPK_TYPES = ['collocation', 'sentence', 'dictation'];

/**
 * The courses with practice packs: add a course by adding a row here. Per course: the folder of its pack, the glob of its practice
 * files, the number of tasks, the level of a task by the number of its module, and the lesson of a module the session test opens.
 *
 * @return array<string, array{dir: string, glob: string, tasks: int, level: Closure(int): string, lesson: Closure(int): int}>
 */
function ppk_courses(): array
{
    return [
        'english-a1-a2' => [
            'dir' => 'database/data/courses/english-a1-a2',
            'glob' => 'database/data/practice/english-a1-a2*.json',
            'tasks' => 936,
            'level' => static fn (int $module): string => $module <= 10 ? 'A1' : 'A2',
            // Modules 2, 4 and 7 show the lessons the tasks of the old pack left: down to three tasks of a type there.
            'lesson' => static fn (int $module): int => in_array($module, [2, 4, 7], true) ? 1 : ($module - 1) % 5 + 1,
        ],
        'english-b1' => [
            'dir' => 'database/data/courses/english-b1',
            'glob' => 'database/data/practice/english-b1-p*.json',
            'tasks' => 900,
            'level' => static fn (int $module): string => 'B1',
            'lesson' => static fn (int $module): int => ($module - 1) % 5 + 1,
        ],
        'english-b2' => [
            'dir' => 'database/data/courses/english-b2',
            'glob' => 'database/data/practice/english-b2-p*.json',
            'tasks' => 900,
            'level' => static fn (int $module): string => 'B2',
            'lesson' => static fn (int $module): int => ($module - 1) % 5 + 1,
        ],
    ];
}

dataset('ppk_courses', function (): array {
    $rows = [];
    foreach (array_keys(ppk_courses()) as $slug) {
        $rows[$slug] = [$slug];
    }

    return $rows;
});

/** @return list<string> the pack files */
function ppk_files(string $slug): array
{
    $files = glob(base_path(ppk_courses()[$slug]['glob'])) ?: [];
    natsort($files);

    return array_values($files);
}

/** @return array<string, mixed> */
function ppk_read(string $file): array
{
    return json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
}

/** @return list<array<string, mixed>> every task of every pack (the files are read once per process) */
function ppk_tasks(string $slug): array
{
    static $tasks = [];
    if (! isset($tasks[$slug])) {
        $tasks[$slug] = [];
        foreach (ppk_files($slug) as $file) {
            foreach (ppk_read($file)['items'] as $task) {
                $tasks[$slug][] = $task;
            }
        }
    }

    return $tasks[$slug];
}

/** @return array<string, string> every word of the course => "module.lesson" */
function ppk_course_words(string $slug): array
{
    static $words = [];
    if (! isset($words[$slug])) {
        $words[$slug] = [];
        foreach (glob(base_path(ppk_courses()[$slug]['dir']).'/m*-l*.json') ?: [] as $file) {
            $data = ppk_read($file);
            foreach ($data['words'] as $word) {
                $words[$slug][$word['word']] = $data['stage']['number'].'.'.$data['lesson']['number'];
            }
        }
    }

    return $words[$slug];
}

/** @param array<string, mixed> $task */
function ppk_lesson(array $task): string
{
    return $task['lesson']['stage'].'.'.$task['lesson']['number'];
}

/** @return list<array{0: string, 1: string, 2: string}> every text of the tasks in a language: [where, locale, text] */
function ppk_texts(string $slug): array
{
    $texts = [];
    foreach (ppk_tasks($slug) as $task) {
        foreach (['prompt', 'translation', 'explanation'] as $field) {
            foreach ((array) ($task[$field] ?? []) as $locale => $text) {
                $texts[] = ["{$task['key']} {$field}.{$locale}", (string) $locale, (string) $text];
            }
        }
    }

    return $texts;
}

/** The course imported from its pack, as a draft. */
function ppk_import_course(string $slug): Course
{
    $report = app(CoursePackImporter::class)->import(base_path(ppk_courses()[$slug]['dir']), create: true);
    expect($report->errors)->toBe([]);

    return Course::query()->where('slug', $slug)->firstOrFail();
}

// ---------------------------------------------------------------- the files

it('keeps the tasks of the course in files the importer takes whole: one course, one format, at most 300 tasks a file, every key once', function (string $slug): void {
    $limit = min(300, (int) config('practice.import.max_items'));
    $keys = [];
    $total = 0;
    foreach (ppk_files($slug) as $file) {
        $data = ppk_read($file);
        expect(array_keys($data))->toBe(['format', 'course', 'items'])
            ->and($data['format'])->toBe(PracticeImporter::FORMAT)
            ->and($data['course'])->toBe($slug)
            ->and(count($data['items']))->toBeLessThanOrEqual($limit, basename($file));
        foreach ($data['items'] as $task) {
            expect($keys)->not->toHaveKey($task['key'], "{$task['key']} is in ".basename($file).' and in '.($keys[$task['key']] ?? ''));
            $keys[$task['key']] = basename($file);
        }
        $total += count($data['items']);
    }

    expect($total)->toBe(ppk_courses()[$slug]['tasks']);
})->with('ppk_courses');

it('gives each of the 100 lessons of the course at least three tasks of each authored type', function (string $slug): void {
    $count = [];
    foreach (array_unique(ppk_course_words($slug)) as $lesson) {
        foreach (PPK_TYPES as $type) {
            $count["{$lesson} {$type}"] = 0;
        }
    }
    foreach (ppk_tasks($slug) as $task) {
        $slot = ppk_lesson($task).' '.$task['type'];
        $count[$slot] = ($count[$slot] ?? 0) + 1;   // a lesson the course does not have adds a slot
    }

    expect($count)->toHaveCount(300)
        ->and(array_keys(array_filter($count, static fn (int $n): bool => $n < 3)))->toBe([]);
})->with('ppk_courses');

it('takes every target word of a task from the lesson of the task', function (string $slug): void {
    $words = ppk_course_words($slug);
    $offences = [];
    foreach (ppk_tasks($slug) as $task) {
        if ($task['target_words'] === []) {
            $offences[] = "{$task['key']}: no target word";
        }
        foreach ($task['target_words'] as $target) {
            $at = $words[mb_strtolower(trim($target))] ?? 'no lesson of the course';
            if ($at !== ppk_lesson($task)) {
                $offences[] = "{$task['key']} (lesson ".ppk_lesson($task)."): «{$target}» is in {$at}";
            }
        }
    }

    expect($offences)->toBe([]);
})->with('ppk_courses');

it('sets the level of a task by its module (a1-a2: A1 in modules 1-10, A2 in modules 11-20)', function (string $slug): void {
    $offences = [];
    foreach (ppk_tasks($slug) as $task) {
        $level = ppk_courses()[$slug]['level']($task['lesson']['stage']);
        if (($task['level'] ?? null) !== $level) {
            $offences[] = "{$task['key']} (module {$task['lesson']['stage']}): ".($task['level'] ?? 'none');
        }
    }

    expect($offences)->toBe([]);
})->with('ppk_courses');

it('writes uz in Latin script with the ASCII apostrophe and addresses the learner as «Siz» in uz and «вы» in ru', function (string $slug): void {
    $offences = [];
    foreach (ppk_texts($slug) as [$where, $locale, $text]) {
        if ($locale === 'uz') {
            if (preg_match('/[’ʻʼ‘`]|\p{Cyrillic}/u', $text) === 1) {
                $offences[] = "{$where}: Cyrillic or a typographic apostrophe";
            }
            foreach (PracticePackRules::uzInformal($text) as $word) {
                $offences[] = "{$where}: «{$word}»";
            }
        } elseif ($locale === 'ru') {
            foreach (PracticePackRules::ruInformal($text) as $word) {
                $offences[] = "{$where}: «{$word}»";
            }
        } else {
            $offences[] = "{$where}: a language the course does not have";
        }
    }

    expect($offences)->toBe([]);
})->with('ppk_courses');

it('tells the informal address by its forms: «sen» in uz, «ты» in ru', function (): void {
    expect(PracticePackRules::uzInformal("Senga keyinroq qo'ng'iroq qilaman"))->toBe(['Senga'])
        ->and(PracticePackRules::uzInformal('Kechirasan, Xatimni oldingmi? Menga xat yubora olasanmi? Nima qilyapsan?'))->toBe(['Kechirasan', 'oldingmi', 'olasanmi', 'qilyapsan'])
        ->and(PracticePackRules::uzInformal("Kechirasiz, xatimni oldingizmi? Menga xat yubora olasizmi? Siz ko'rgansiz."))->toBe([])
        ->and(PracticePackRules::uzInformal('reading, a wedding, the building'))->toBe([])
        ->and(PracticePackRules::ruInformal('Ты получил моё письмо? Можешь прислать? Давай встретимся. Лишь бы успеть.'))->toBe(['ты', 'можешь', 'давай'])
        ->and(PracticePackRules::ruInformal('Вы получили моё письмо? Можете прислать? Давайте встретимся.'))->toBe([]);
});

it('keeps every practice file of a course in the glob of that course and of no other', function (): void {
    $courses = ppk_courses();
    $seen = 0;
    foreach (glob(base_path('database/data/practice/*.json')) ?: [] as $file) {
        $course = ppk_read($file)['course'] ?? null;
        if (! isset($courses[$course]) || (ppk_read($file)['format'] ?? null) !== PracticeImporter::FORMAT) {
            continue;   // another course (`pilot-a1-a2.json` is of `default`), not a pack of this list
        }
        $seen++;
        foreach ($courses as $slug => $row) {
            expect(in_array($file, ppk_files($slug), true))->toBe($slug === $course, basename($file)." is of {$course}, glob of {$slug}");
        }
    }

    expect($seen)->toBeGreaterThan(0);
});

// ---------------------------------------------------------------- the grading

it('grades every accepted writing of a dictation and every accepted order of a sentence as correct', function (string $slug): void {
    $validator = app(PracticeContentValidator::class);
    $dictation = app(DictationGrader::class);
    $sentence = app(SentenceGrader::class);
    $offences = [];
    $graded = 0;
    foreach (ppk_tasks($slug) as $task) {
        $type = PracticeItemType::from($task['type']);
        if ($type === PracticeItemType::Collocation) {
            continue;
        }
        // What a version stores, as the import makes it.
        $content = $validator->canonical($type, $task);

        if ($type === PracticeItemType::Dictation) {
            foreach ([$task['text'], ...$task['accepted']] as $writing) {
                $verdict = $dictation->grade($content['accepted'], $writing)->verdict;
                $graded++;
                if ($verdict !== Verdict::Correct) {
                    $offences[] = "{$task['key']}: «{$writing}» is {$verdict->value}";
                }
            }

            continue;
        }

        $fragments = [];
        foreach ($content['fragments'] as $index => $text) {
            $fragments["f{$index}"] = $text;
        }
        $accepted = $sentence->forms($content['fragments'], $content['accepted'], $content['display'] ?? null)['accepted'];
        foreach ($content['accepted'] as $sequence) {
            $order = array_map(static fn (int $index): string => "f{$index}", $sequence);
            $verdict = $sentence->grade($fragments, $accepted, ['order' => $order])->verdict;
            $graded++;
            if ($verdict !== Verdict::Correct) {
                $offences[] = "{$task['key']}: «".$sentence->join($fragments, $order)."» is {$verdict->value}";
            }
        }
    }

    expect($offences)->toBe([])
        ->and($graded)->toBeGreaterThan(ppk_courses()[$slug]['tasks'] * 2 / 3);
})->with('ppk_courses');

it('lists the writings of a dictation phrase by the one rule of docs/16 §5.2', function (string $slug): void {
    $offences = [];
    foreach (ppk_tasks($slug) as $task) {
        if ($task['type'] !== 'dictation') {
            continue;
        }
        $accepted = array_map(PracticeTextNormalizer::normalize(...), [$task['text'], ...$task['accepted']]);
        $expected = array_map(PracticeTextNormalizer::normalize(...), PracticePackRules::expectedWritings($task['text']));

        // Both ways for a function word with a verb and for a negative, words and digits for a number.
        foreach (array_diff($expected, $accepted) as $writing) {
            $offences[] = "{$task['key']}: «{$writing}» is missing";
        }

        // A noun with `is` is contracted only where the text is: no other writing may do it.
        foreach ($task['accepted'] as $writing) {
            if (in_array(PracticeTextNormalizer::normalize($writing), $expected, true)) {
                continue;
            }
            preg_match_all("/\\b([A-Za-z]+)'s\\b/", $writing, $matches);
            foreach ($matches[1] as $word) {
                if (preg_match('/\b'.$word.' is\b/i', $task['text']) === 1 && preg_match("/\\b{$word}'s\\b/i", $task['text']) !== 1) {
                    $offences[] = "{$task['key']}: «{$writing}» contracts «{$word} is», the text does not";
                }
            }
        }
    }

    expect($offences)->toBe([]);
})->with('ppk_courses');

it('expands a phrase by the rule: contractions both ways, numbers in words and digits, a noun with is left alone', function (): void {
    expect(PracticePackRules::expectedWritings("It's seven o'clock"))->toEqualCanonicalizing(["It's seven o'clock", "It's 7 o'clock", "It is seven o'clock", "It is 7 o'clock"])
        ->and(PracticePackRules::expectedWritings('He is not at home'))->toEqualCanonicalizing(['He is not at home', "He isn't at home", "He's not at home"])
        ->and(PracticePackRules::expectedWritings('Two thousand people live here'))->toEqualCanonicalizing(['Two thousand people live here', '2000 people live here', '2,000 people live here'])
        ->and(PracticePackRules::expectedWritings("Let's go"))->toEqualCanonicalizing(["Let's go", 'Let us go'])
        ->and(PracticePackRules::expectedWritings('Where is my scarf?'))->toEqualCanonicalizing(['Where is my scarf?', "Where's my scarf?"])
        ->and(PracticePackRules::expectedWritings('The train is late'))->toBe(['The train is late'])
        ->and(PracticePackRules::expectedWritings('The service here is very good'))->toBe(['The service here is very good'])
        ->and(PracticePackRules::expectedWritings('What time is it?'))->toBe(['What time is it?']);
});

it('knows every word of the course and every word of the dictation phrases, so that writing one of them for another is a mistake', function (string $slug): void {
    $unknown = [];
    foreach (array_keys(ppk_course_words($slug)) as $word) {
        foreach (PracticeTextNormalizer::tokens($word) as $token) {
            if (! EnglishLexicon::isWord($token)) {
                $unknown[] = "the course: {$token}";
            }
        }
    }
    foreach (ppk_tasks($slug) as $task) {
        if ($task['type'] !== 'dictation') {
            continue;
        }
        foreach ([$task['text'], ...$task['accepted']] as $writing) {
            // A number in digits is no word; a hyphenated word (`e-mail`) is known as one (`email`) or by its parts.
            preg_match_all("/[a-z][a-z'-]*/i", $writing, $matches);
            foreach ($matches[0] as $word) {
                $parts = explode('-', $word);
                if (! EnglishLexicon::isWord(str_replace('-', '', $word)) && array_filter($parts, static fn (string $part): bool => ! EnglishLexicon::isWord($part)) !== []) {
                    $unknown[] = "{$task['key']}: {$word}";
                }
            }
        }
    }

    expect(array_values(array_unique($unknown)))->toBe([]);
})->with('ppk_courses');

// ---------------------------------------------------------------- the import and the sessions

it('imports the course and every pack without a refusal, again as a no-op, publishes them with practice:import --publish, and opens a lesson session of each authored mode', function (string $slug): void {
    Queue::fake();
    Storage::fake('practice_audio');
    $course = ppk_import_course($slug);
    $importer = app(PracticeImporter::class);

    foreach (ppk_files($slug) as $file) {
        $count = count(ppk_read($file)['items']);
        expect($importer->importAsSystem((string) file_get_contents($file), false)->toArray())
            ->toBe(['added' => $count, 'updated' => 0, 'skipped' => 0, 'published' => 0, 'errors' => [], 'aborted' => false], basename($file));
    }

    // Each task sits in the lesson of its file, at the level of the file, and every target word is a word of that lesson.
    $lessons = [];
    foreach (Lesson::query()->with('stage')->whereHas('stage', fn ($query) => $query->where('course_id', $course->id))->get() as $lesson) {
        $lessons[$lesson->id] = $lesson->stage->number.'.'.$lesson->number;
    }
    $items = PracticeItem::query()->where('course_id', $course->id)->with('words')->get()->keyBy('key');
    expect($items)->toHaveCount(ppk_courses()[$slug]['tasks']);
    foreach (ppk_tasks($slug) as $task) {
        $item = $items->get($task['key']);
        expect($item)->not->toBeNull($task['key']);
        expect($lessons[(int) $item->lesson_id] ?? null)->toBe(ppk_lesson($task), $task['key'])
            ->and($item->level)->toBe($task['level'], $task['key'])
            ->and($item->words->map(fn (Word $word): ?string => $lessons[$word->lesson_id] ?? null)->unique()->values()->all())
            ->toBe([ppk_lesson($task)], $task['key']);
    }

    // The same files again: every task skipped, no version written.
    $versions = PracticeItemVersion::query()->count();
    foreach (ppk_files($slug) as $file) {
        $count = count(ppk_read($file)['items']);
        expect($importer->importAsSystem((string) file_get_contents($file), false)->toArray())
            ->toBe(['added' => 0, 'updated' => 0, 'skipped' => $count, 'published' => 0, 'errors' => [], 'aborted' => false], basename($file));
    }
    expect(PracticeItemVersion::query()->count())->toBe($versions);

    // The step of docs/CONTENT_A1_A2.md after the owner's review: the command publishes the very versions that were imported.
    foreach (ppk_files($slug) as $file) {
        expect(Artisan::call('practice:import', ['file' => 'database/data/practice/'.basename($file), '--publish' => true]))->toBe(0, basename($file))
            ->and(Artisan::output())->toContain('Practice import finished.');
    }
    expect(PracticeItem::query()->where('status', PracticeItemStatus::Published)->count())->toBe(ppk_courses()[$slug]['tasks'])
        ->and(PracticeItemVersion::query()->count())->toBe($versions);

    // The sessions, once the course is published: a lesson of every module, each in every authored mode, in ru and in uz by turns.
    // The lesson of a module is the one of the course row (a1-a2: modules 2, 4 and 7 show the lessons with down to three tasks of
    // a type, so a single locked task would close the mode).
    PracticeKit::openAccess();
    $course->forceFill(['status' => CoursePublisher::PUBLISHED, 'published_at' => now()])->save();
    $wanted = [];
    foreach (range(1, 20) as $module) {
        $wanted[] = $module.'.'.ppk_courses()[$slug]['lesson']($module);
    }
    $chosen = array_keys(array_intersect($lessons, $wanted));
    expect($chosen)->toHaveCount(20);

    // Their phrases are voiced (no job runs here): ready, with a file on the disk of the practice.
    $audio = app(PracticeAudioService::class);
    $phrases = PracticeItem::query()->whereIn('lesson_id', $chosen)->where('type', PracticeItemType::Dictation)->pluck('published_version_id');
    foreach (PracticeItemVersion::query()->whereIn('id', $phrases)->get() as $version) {
        $version->forceFill(['audio_status' => AudioStatus::Ready, 'audio_key' => sha1('ppk-'.$version->id)])->save();
        Storage::disk($audio->diskName())->put((string) $audio->pathFor($version), 'mp3');
    }

    // A ru and an uz learner, introduced to every word of those lessons.
    $learners = ['ru' => Learner::factory()->create(['interface_locale' => 'ru']), 'uz' => Learner::factory()->create(['interface_locale' => 'uz'])];
    $rows = [];
    foreach ($learners as $learner) {
        foreach (Word::query()->whereIn('lesson_id', $chosen)->pluck('id') as $word) {
            $rows[] = ['learner_id' => $learner->id, 'word_id' => $word, 'state' => 'new', 'next_review_at' => now(), 'created_at' => now(), 'updated_at' => now()];
        }
    }
    WordRepetition::query()->insert($rows);

    $tasks = [];
    $stock = [];
    foreach (ppk_tasks($slug) as $task) {
        $tasks[$task['key']] = $task;
        $slot = ppk_lesson($task).' '.$task['type'];
        $stock[$slot] = ($stock[$slot] ?? 0) + 1;
    }
    $keys = $items->pluck('key', 'id');
    $size = (int) config('practice.solo.items.size');

    $sessions = app(PracticeSessionService::class);
    foreach ($chosen as $turn => $lessonId) {
        $locale = $turn % 2 === 0 ? 'ru' : 'uz';
        foreach ([PracticeMode::Collocation, PracticeMode::Sentence, PracticeMode::Dictation] as $mode) {
            $where = "{$lessons[$lessonId]} {$mode->value} {$locale}";
            $session = $sessions->open($learners[$locale], $mode, $slug, $lessonId, PracticeSession::SOURCE_HUB, true, null);
            $elements = $session->snapshot['elements'];

            // Every task of the type is open to a learner who knows the words of the lesson (no target word of another lesson
            // locks one): a full session, or all the tasks of a lesson that has fewer. No word is voiced: the dictation is phrases.
            expect($session->lesson_id)->toBe($lessonId)
                ->and($session->locale)->toBe($locale)
                ->and($session->total)->toBe(min($size, $stock[$lessons[$lessonId].' '.$mode->value]), $where)
                ->and($elements)->toHaveCount($session->total);

            // Every element is a task of THIS lesson, in the learner's language.
            foreach ($elements as $element) {
                $key = $keys[$element['item_id'] ?? 0] ?? null;
                expect($key !== null && ppk_lesson($tasks[$key]) === $lessons[$lessonId])->toBeTrue("{$where}: an element of another lesson");
                $field = $mode === PracticeMode::Sentence ? 'prompt' : 'translation';
                expect($element[$field])->toBe([$locale => $tasks[$key][$field][$locale]], "{$where}: {$key}");
            }
        }
    }
})->with('ppk_courses');
