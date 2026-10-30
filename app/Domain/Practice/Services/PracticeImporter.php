<?php

declare(strict_types=1);

namespace App\Domain\Practice\Services;

use App\Domain\Content\DTO\ImportIssue;
use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Content\Services\CoursePublisher;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Practice\DTO\PracticeImportReport;
use App\Domain\Practice\DTO\PreparedPracticeItem;
use App\Domain\Practice\DTO\SavedPracticeItem;
use App\Domain\Practice\Enums\PracticeItemStatus;
use App\Domain\Practice\Enums\PracticeItemType;
use App\Domain\Practice\Exceptions\PracticeContentException;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\PracticeItem;
use App\Models\User;
use App\Models\Word;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * The JSON import of the tasks of the practice (docs/16 §15.1): one service for the admin page and `practice:import`.
 *
 *   {"format": "lexiflow-practice/1", "course": "default", "items": [ {key, type, lesson: {stage, number}, level,
 *     target_words: [..], ...the fields of the type...}, ... ]}
 *
 * The `key` of a task is its identity: the file can be imported again. A task with a known key and the same text and target
 * words is `skipped`; a changed one gets a NEW version (`updated`); a new key is `added`; the key of an archived task is a
 * refusal (`item_archived`: restore it in the admin panel first). By default the versions are drafts; with `$publish` ("publish
 * at once", the option of the person who runs the import - never a field of the file) they are written as published.
 *
 * All or nothing, as the vocabulary import: the whole file is checked first (every task, every fault is reported with the
 * `position` of the task in the file, from 1, and its `key`), and written in ONE transaction only if nothing was refused. The
 * lock of the course comes first (docs/16 §9 rule 12), so the checks read what the writes then find. The voicing of every new
 * dictation version is queued after the commit (PracticeItemService -> PracticeAudioService::schedule).
 *
 * Who may import: a person passed to {@see import()} must pass `PracticeItemPolicy::create` (an active admin); trusted code
 * without a user (artisan, seeders) calls {@see importAsSystem()}, which skips the policy and marks the audit row
 * `meta.actor = system`. The environment never decides who is allowed.
 */
final class PracticeImporter
{
    public const MAX_BYTES = 2 * 1024 * 1024; // 2 MB

    public const FORMAT = 'lexiflow-practice/1';

    /** The issues of the import itself; the ones of the text of a task are the codes of PracticeContentException (§4.5). */
    public const FORMAT_UNSUPPORTED = 'format_unsupported';

    public const ITEMS_REQUIRED = 'items_required';

    public const ITEMS_TOO_MANY = 'items_too_many';

    public const ITEM_INVALID = 'item_invalid';

    public const FIELD_UNKNOWN = 'field_unknown';

    public const FIELD_INVALID = 'field_invalid';

    public const KEY_DUPLICATE_IN_FILE = 'key_duplicate_in_file';

    public const KEY_TYPE_MISMATCH = 'key_type_mismatch';

    public const ITEM_ARCHIVED = 'item_archived';

    public const ACTION_IMPORTED = 'practice.imported';

    /** `cause` of `field_unknown` for a key of the file itself rather than of a task. */
    public const CAUSE_FILE = 'file';

    private const ROOT_FIELDS = ['format', 'course', 'items'];

    private const COMMON_FIELDS = ['key', 'type', 'lesson', 'level', 'target_words'];

    /** @var array<string, list<string>> */
    private const TYPE_FIELDS = [
        'sentence' => ['prompt', 'fragments', 'accepted', 'display', 'explanation'],
        'collocation' => ['text', 'options', 'correct', 'translation', 'explanation'],
        'dictation' => ['text', 'accepted', 'translation', 'explanation'],
    ];

    /** Fields that are `{locale: text}` objects. */
    private const LOCALIZED_FIELDS = ['prompt', 'translation', 'explanation'];

    /** Fields that are lists. */
    private const LIST_FIELDS = ['target_words', 'fragments', 'accepted', 'options', 'correct'];

    /** Fields that are one text. */
    private const TEXT_FIELDS = ['text', 'display'];

    public function __construct(
        private readonly CourseCatalogService $catalog,
        private readonly CoursePublisher $publisher,
        private readonly PracticeItemService $items,
        private readonly LocaleResolver $locales,
    ) {}

    /**
     * Imports a file on behalf of a person.
     *
     * @param  array{ip?:string|null}  $auditContext
     */
    public function import(string $rawJson, User $by, bool $publish, array $auditContext = []): PracticeImportReport
    {
        return $this->run($rawJson, $by, $publish, $auditContext);
    }

    /**
     * Import by trusted system code (artisan, seeders): no policy check, audit `user_id` null and `meta.actor` `system`. Never
     * call it with a file from a request.
     *
     * @param  array{ip?:string|null}  $auditContext
     */
    public function importAsSystem(string $rawJson, bool $publish, array $auditContext = []): PracticeImportReport
    {
        return $this->run($rawJson, null, $publish, $auditContext);
    }

    /** @param  array{ip?:string|null}  $auditContext */
    private function run(string $rawJson, ?User $by, bool $publish, array $auditContext): PracticeImportReport
    {
        $report = new PracticeImportReport;

        if ($by !== null && Gate::forUser($by)->denies('create', PracticeItem::class)) {
            $report->abort(ImportIssue::NOT_ALLOWED, 'Import is not allowed: only a platform admin can import practice tasks.');

            return $report;
        }

        $maxBytes = (int) config('practice.import.max_bytes', self::MAX_BYTES);
        if (strlen($rawJson) > $maxBytes) {
            $report->abort(ImportIssue::FILE_TOO_LARGE, sprintf('File exceeds %d bytes limit.', $maxBytes), ['megabytes' => $maxBytes / 1024 / 1024]);

            return $report;
        }

        // A byte order mark (some editors write one) is not part of the JSON.
        $rawJson = str_starts_with($rawJson, "\xEF\xBB\xBF") ? substr($rawJson, 3) : $rawJson;

        try {
            $data = json_decode($rawJson, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $report->abort(ImportIssue::INVALID_JSON, 'Invalid JSON: '.$e->getMessage(), ['cause' => $this->jsonCause($e)]);

            return $report;
        }

        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            $report->abort(ImportIssue::ROOT_NOT_OBJECT, 'Root must be an object.');

            return $report;
        }

        $course = $this->checkRoot($data, $report);
        if ($course === null) {
            return $report;
        }

        /** @var list<mixed> $items */
        $items = $data['items'];

        DB::transaction(function () use ($items, $course, $by, $publish, $auditContext, $report): void {
            // The first lock of the transaction: the writers of one course take turns (CoursePublisher::lockForWriting).
            if ($this->publisher->lockForWriting($course) === null) {
                $report->abort(ImportIssue::COURSE_NOT_FOUND, 'The course does not exist.', ['slug' => $course->slug]);

                return;
            }

            $plans = $this->plan($items, $course, $publish, $report);
            if ($report->aborted) {
                return;
            }

            foreach ($plans as $plan) {
                $saved = $this->items->write($course, $plan['existing'], $plan['prepared'], $by, $publish);

                if ($saved->outcome() === SavedPracticeItem::ADDED) {
                    $report->added++;
                } elseif ($saved->outcome() === SavedPracticeItem::UPDATED) {
                    $report->updated++;
                } else {
                    $report->skipped++;
                }

                if ($saved->published) {
                    $report->published++;
                }
            }

            AuditLog::query()->create([
                'user_id' => $by?->id,
                'action' => self::ACTION_IMPORTED,
                'entity_type' => Course::class,
                'entity_id' => $course->id,
                'ip' => $auditContext['ip'] ?? null,
                'meta' => [
                    'actor' => $by === null ? 'system' : 'user',
                    'course_id' => $course->id,
                    'course_slug' => $course->slug,
                    'publish' => $publish,
                    'report' => $report->toArray(),
                ],
            ]);
        });

        return $report;
    }

    /**
     * The format, the course and the list of the tasks. Null (the report says why) when the file cannot be read as a practice
     * import at all.
     *
     * @param  array<array-key, mixed>  $data
     */
    private function checkRoot(array $data, PracticeImportReport $report): ?Course
    {
        // A key that is no field of the format is reported, not dropped: a typo, or `"publish": true` that the file cannot do.
        foreach (array_keys($data) as $field) {
            if (! in_array($field, self::ROOT_FIELDS, true)) {
                $report->abort(
                    self::FIELD_UNKNOWN,
                    "Unknown field '{$field}' in the file.",
                    ['field' => (string) $field, 'cause' => self::CAUSE_FILE],
                );
            }
        }

        $format = $data['format'] ?? null;
        if ($format !== self::FORMAT) {
            $report->abort(
                self::FORMAT_UNSUPPORTED,
                'Unsupported format: expected '.self::FORMAT.'.',
                ['format' => is_string($format) ? $format : '(none)', 'expected' => self::FORMAT],
            );
        }

        $slug = $data['course'] ?? null;
        $course = is_string($slug) && $slug !== '' ? $this->catalog->findBySlug($slug) : null;
        if ($course === null) {
            $report->abort(
                ImportIssue::COURSE_NOT_FOUND,
                'Course '.(is_string($slug) && $slug !== '' ? "'{$slug}'" : '(invalid)').' not found: create it in the admin panel first.',
                ['slug' => is_string($slug) && $slug !== '' ? $slug : '(invalid)'],
            );
        } elseif ($course->owner_type === 'learner') {
            $report->abort(PracticeContentException::COURSE_INVALID, "Course '{$course->slug}' is a deck of a learner: it has no practice tasks.", ['slug' => $course->slug]);
        }

        $items = $data['items'] ?? null;
        $max = (int) config('practice.import.max_items');
        if (! is_array($items) || ! array_is_list($items) || $items === []) {
            $report->abort(self::ITEMS_REQUIRED, "The file needs a list 'items' of 1..{$max} tasks.", ['max' => $max]);
        } elseif (count($items) > $max) {
            $report->abort(self::ITEMS_TOO_MANY, 'The file has '.count($items)." tasks, the limit is {$max}.", ['count' => count($items), 'max' => $max]);
        }

        return $report->aborted ? null : $course;
    }

    /**
     * Checks every task of the file under the lock of the course and prepares it for writing. Issues go to the report; with
     * any, the caller writes nothing.
     *
     * @param  list<mixed>  $items
     * @return list<array{existing: ?PracticeItem, prepared: PreparedPracticeItem}>
     */
    private function plan(array $items, Course $course, bool $publish, PracticeImportReport $report): array
    {
        $existing = $this->existingItems($items, $course);
        $lessons = $this->lessonIds($course);
        $words = $this->wordsByText($course);

        $plans = [];
        $seen = [];
        foreach ($items as $index => $raw) {
            $position = $index + 1;
            $key = is_array($raw) && is_string($raw['key'] ?? null) ? $raw['key'] : null;

            /** Reports one fault of this task: its position and key go into the context of every issue. */
            $issue = function (string $code, string $message, array $extra = []) use ($report, $position, $key): void {
                $report->abort(
                    $code,
                    "Task {$position} (key '".($key ?? '—')."'): {$message}",
                    ['position' => $position, 'key' => $key ?? '—', ...$extra],
                );
            };
            $before = count($report->issues);

            if (! is_array($raw) || ($raw !== [] && array_is_list($raw))) {
                $issue(self::ITEM_INVALID, 'a task must be an object.');

                continue;
            }

            if ($key !== null && isset($seen[$key])) {
                $issue(self::KEY_DUPLICATE_IN_FILE, "the key '{$key}' is used twice in the file (first at task {$seen[$key]}).", ['first_position' => $seen[$key]]);

                continue;
            }
            if ($key !== null) {
                $seen[$key] = $position;
            }

            $type = is_string($raw['type'] ?? null) ? PracticeItemType::tryFrom($raw['type']) : null;
            if ($type === null) {
                $issue(PracticeContentException::TYPE_INVALID, 'the type is collocation, sentence or dictation.');

                continue;
            }

            $this->checkFields($raw, $type, $issue);

            $found = $key === null ? null : $existing->get($key);
            if ($found !== null && $found->type !== $type) {
                $issue(self::KEY_TYPE_MISMATCH, "the key belongs to a task of the type {$found->type->value}, a type does not change.", ['existing_type' => $found->type->value]);
            } elseif ($found !== null && $found->status === PracticeItemStatus::Archived) {
                $issue(self::ITEM_ARCHIVED, 'the task is archived: restore it in the admin panel first.');
            }

            $lessonId = $this->resolveLesson($raw['lesson'] ?? null, $lessons, $issue);
            $wordIds = $this->resolveWords($raw['target_words'] ?? null, $lessonId, $words, $issue);

            // Fields of the wrong shape were reported above; reading them as text would only add noise.
            if (count($report->issues) > $before) {
                continue;
            }

            try {
                $prepared = $this->items->prepare($course, [
                    'type' => $type->value,
                    'key' => $key,
                    'lesson_id' => $lessonId,
                    'level' => $raw['level'] ?? null,
                    'word_ids' => $wordIds,
                    'content' => array_intersect_key($raw, array_flip(self::TYPE_FIELDS[$type->value])),
                ], $found);

                if ($publish) {
                    $this->items->assertPublishable($prepared);
                }
            } catch (PracticeContentException $e) {
                $issue($e->errorCode, $e->getMessage(), ['field' => $e->field, ...$e->context]);

                continue;
            }

            $plans[] = ['existing' => $found, 'prepared' => $prepared];
        }

        return $plans;
    }

    /**
     * The unknown fields of a task, and the fields of a shape the format does not allow (a list where a text should be).
     *
     * @param  array<array-key, mixed>  $raw
     * @param  callable(string, string, array<string, int|float|string|list<string>>): mixed  $issue
     */
    private function checkFields(array $raw, PracticeItemType $type, callable $issue): void
    {
        $allowed = [...self::COMMON_FIELDS, ...self::TYPE_FIELDS[$type->value]];

        foreach ($raw as $field => $value) {
            $field = (string) $field;

            if (! in_array($field, $allowed, true)) {
                $issue(self::FIELD_UNKNOWN, "unknown field '{$field}'.", ['field' => $field]);

                continue;
            }

            $valid = match (true) {
                $value === null => true,
                in_array($field, self::LIST_FIELDS, true) => is_array($value) && array_is_list($value),
                in_array($field, self::TEXT_FIELDS, true), $field === 'level', $field === 'key', $field === 'type' => is_string($value),
                in_array($field, self::LOCALIZED_FIELDS, true) => is_array($value) && ! array_is_list($value) || $value === [],
                default => true,
            };

            if (! $valid) {
                $issue(self::FIELD_INVALID, "the field '{$field}' has the wrong shape.", ['field' => $field]);

                continue;
            }

            if (in_array($field, self::LOCALIZED_FIELDS, true) && is_array($value)) {
                foreach ($value as $locale => $text) {
                    if (! in_array((string) $locale, $this->locales->supported(), true)) {
                        $issue(self::FIELD_UNKNOWN, "unknown language '{$locale}' in '{$field}'.", ['field' => "{$field}.{$locale}"]);
                    } elseif ($text !== null && ! is_string($text)) {
                        $issue(self::FIELD_INVALID, "the text '{$field}.{$locale}' must be a string.", ['field' => "{$field}.{$locale}"]);
                    }
                }
            }
        }
    }

    /**
     * `{"stage": 1, "number": 3}` -> the id of that lesson of the course. Absent / null: a task of the course.
     *
     * @param  array<string, int>  $lessons  "stage:number" => lesson id
     * @param  callable(string, string, array<string, int|float|string|list<string>>): mixed  $issue
     */
    private function resolveLesson(mixed $lesson, array $lessons, callable $issue): ?int
    {
        if ($lesson === null) {
            return null;
        }

        $stage = is_array($lesson) ? ($lesson['stage'] ?? null) : null;
        $number = is_array($lesson) ? ($lesson['number'] ?? null) : null;
        if (! is_int($stage) || ! is_int($number)) {
            $issue(self::FIELD_INVALID, "the field 'lesson' must be {\"stage\": N, \"number\": N}.", ['field' => 'lesson']);

            return null;
        }

        $id = $lessons["{$stage}:{$number}"] ?? null;
        if ($id === null) {
            $issue(PracticeContentException::LESSON_NOT_IN_COURSE, "the course has no lesson {$number} in module {$stage}.", ['field' => 'lesson', 'stage' => $stage, 'number' => $number]);
        }

        return $id;
    }

    /**
     * The target words by their text, without regard to case, among the ACTIVE words of the course. When a word is in several
     * lessons the one in the lesson of the task is taken, else the earliest in the study order.
     *
     * @param  array<string, list<array{id: int, lesson: int}>>  $words  lower case text => candidates in the study order
     * @param  callable(string, string, array<string, int|float|string|list<string>>): mixed  $issue
     * @return list<int>
     */
    private function resolveWords(mixed $targets, ?int $lessonId, array $words, callable $issue): array
    {
        if ($targets === null) {
            return [];
        }

        $ids = [];
        foreach (is_array($targets) ? $targets : [] as $target) {
            if (! is_string($target)) {
                $issue(self::FIELD_INVALID, "the field 'target_words' must be a list of texts.", ['field' => 'target_words']);

                return [];
            }

            $candidates = $words[mb_strtolower(trim($target))] ?? [];
            if ($candidates === []) {
                $issue(PracticeContentException::TARGET_WORD_UNKNOWN, "the target word '{$target}' is no active word of the course.", ['field' => 'target_words', 'word' => $target]);

                continue;
            }

            $chosen = $candidates[0]['id'];
            foreach ($candidates as $candidate) {
                if ($lessonId !== null && $candidate['lesson'] === $lessonId) {
                    $chosen = $candidate['id'];
                    break;
                }
            }
            $ids[] = $chosen;
        }

        return $ids;
    }

    /**
     * @param  list<mixed>  $items
     * @return Collection<string, PracticeItem> by key
     */
    private function existingItems(array $items, Course $course): Collection
    {
        $keys = [];
        foreach ($items as $item) {
            if (is_array($item) && is_string($item['key'] ?? null)) {
                $keys[] = $item['key'];
            }
        }

        return PracticeItem::query()
            ->where('course_id', $course->id)
            ->whereIn('key', array_values(array_unique($keys)))
            ->get()
            ->keyBy('key');
    }

    /** @return array<string, int> "stage:number" => lesson id */
    private function lessonIds(Course $course): array
    {
        $lessons = [];
        foreach (Lesson::query()
            ->join('stages', 'stages.id', '=', 'lessons.stage_id')
            ->where('stages.course_id', $course->id)
            ->get(['lessons.id', 'lessons.number', 'stages.number as stage_number']) as $lesson) {
            $lessons[$lesson->getAttribute('stage_number').':'.$lesson->number] = $lesson->id;
        }

        return $lessons;
    }

    /** @return array<string, list<array{id: int, lesson: int}>> lower case text => candidates in the study order */
    private function wordsByText(Course $course): array
    {
        $words = [];
        foreach (Word::query()
            ->join('lessons', 'lessons.id', '=', 'words.lesson_id')
            ->join('stages', 'stages.id', '=', 'lessons.stage_id')
            ->where('stages.course_id', $course->id)
            ->where('words.is_active', true)
            ->orderBy('stages.number')
            ->orderBy('lessons.number')
            ->orderBy('words.id')
            ->get(['words.id', 'words.word', 'words.lesson_id']) as $word) {
            $words[mb_strtolower(trim($word->word))][] = ['id' => $word->id, 'lesson' => $word->lesson_id];
        }

        return $words;
    }

    /** What is wrong with a file `json_decode` refused: the few mistakes a person can fix; anything else is `other`. */
    private function jsonCause(Throwable $e): string
    {
        return match ($e->getCode()) {
            JSON_ERROR_SYNTAX => ImportIssue::CAUSE_SYNTAX,
            JSON_ERROR_UTF8, JSON_ERROR_UTF16 => ImportIssue::CAUSE_ENCODING,
            JSON_ERROR_DEPTH => ImportIssue::CAUSE_DEPTH,
            default => ImportIssue::CAUSE_OTHER,
        };
    }
}
