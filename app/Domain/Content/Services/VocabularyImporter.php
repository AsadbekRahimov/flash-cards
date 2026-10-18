<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Content\DTO\ImportReport;
use App\Domain\Learning\Services\LearningCache;
use App\Domain\Localization\Services\LocaleResolver;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\Word;
use App\Models\User;
use App\Models\WordTranslation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Throwable;

final class VocabularyImporter
{
    public const MAX_BYTES = 2 * 1024 * 1024; // 2 MB

    public const ALLOWED_POS = [
        'noun', 'verb', 'adjective', 'adverb',
        'pronoun', 'preposition', 'conjunction', 'interjection',
    ];

    public function __construct(
        private readonly LearningCache $cache,
        private readonly CourseCatalogService $catalog,
        private readonly CoursePublisher $publisher,
        private readonly LocaleResolver $locales,
    ) {}

    /**
     * Imports one lesson. Two file schemas are accepted (docs/12 §3.2):
     *
     *   v1: {"stage": N | {number,...}, "lesson": N | {number,...}, "words": [ {word, translation, ...} ]}
     *       (the PRD form with `vocabulary` instead of `words` works too)
     *   v2: v1 plus
     *       - top-level `course`: slug of an EXISTING course (absent → the `default` course);
     *       - per word `translations`: {"ru": "...", "uz": "..."} and optional `example_translations`.
     *         `translation` stays the ru translation; `translations.ru` is its alias (both → must be equal).
     *
     * The ru text is always written to `words.translation` AND to `word_translations`. Re-importing a
     * file is idempotent: unchanged rows are skipped; a changed translation is overwritten and loses its
     * `reviewed_at` mark. Everything is written in ONE transaction or nothing at all.
     *
     * Who may import (docs/12 §8: in Phase 1 content is platform-owned): `$by` must pass the `update`
     * ability of the target course (CoursePolicy → an active admin); a refusal is an `aborted` report
     * and nothing is written. `$by = null` is the system path (seeders, artisan, queue) and is allowed
     * ONLY when the app runs in the console — an HTTP request can never import without a user.
     *
     * @param  array{ip?:string|null}  $auditContext
     */
    public function import(string $rawJson, ?User $by = null, array $auditContext = []): ImportReport
    {
        $report = new ImportReport;

        if ($by === null ? ! app()->runningInConsole() : Gate::forUser($by)->denies('viewAny', Course::class)) {
            return $this->refuse($report);
        }

        if (strlen($rawJson) > self::MAX_BYTES) {
            $report->aborted = true;
            $report->errors[] = sprintf('File exceeds %d bytes limit.', self::MAX_BYTES);

            return $report;
        }

        try {
            $data = json_decode($rawJson, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $report->aborted = true;
            $report->errors[] = 'Invalid JSON: '.$e->getMessage();

            return $report;
        }

        if (! is_array($data)) {
            $report->aborted = true;
            $report->errors[] = 'Root must be an object.';

            return $report;
        }

        $data = $this->normalizeShape($data);

        $validator = Validator::make($data, $this->rules());

        if ($validator->fails()) {
            $report->aborted = true;
            foreach ($validator->errors()->all() as $msg) {
                $report->errors[] = $msg;
            }

            return $report;
        }

        /** @var array{course?:string|null,stage:array{number:int,title?:string,description?:string},lesson:array{number:int,title?:string},words:list<array<string,mixed>>} $data */
        $this->assertUniqueWords($data['words'], $report);
        $words = $this->normalizeWords($data['words'], $report);
        $course = $this->resolveCourse($data['course'] ?? null, $report);

        if ($report->aborted || $course === null) {
            $report->aborted = true;

            return $report;
        }

        if ($by !== null && Gate::forUser($by)->denies('update', $course)) {
            return $this->refuse($report);
        }

        $lesson = DB::transaction(function () use ($data, $words, $course, $report, $by, $auditContext): Lesson {
            $stage = Stage::updateOrCreate(
                ['course_id' => $course->id, 'number' => $data['stage']['number']],
                array_filter([
                    'title' => $data['stage']['title'] ?? "Stage {$data['stage']['number']}",
                    'description' => $data['stage']['description'] ?? null,
                ], fn ($v): bool => $v !== null),
            );

            $lesson = Lesson::updateOrCreate(
                ['stage_id' => $stage->id, 'number' => $data['lesson']['number']],
                ['title' => $data['lesson']['title'] ?? null],
            );

            /** @var Collection<string, Word> $existingWords */
            $existingWords = Word::query()->where('lesson_id', $lesson->id)->get()->keyBy('word');
            /** @var Collection<int, Collection<string, WordTranslation>> $existingTranslations */
            $existingTranslations = WordTranslation::query()
                ->whereIn('word_id', $existingWords->pluck('id')->all())
                ->get()
                ->groupBy('word_id')
                ->map(fn (Collection $rows): Collection => $rows->keyBy('locale'));

            foreach ($words as $w) {
                $existing = $existingWords->get($w['word']);

                $payload = [
                    'translation' => $w['translations']['ru'],
                    'example' => $w['example'],
                    'part_of_speech' => $w['part_of_speech'],
                    'transcription' => $w['transcription'],
                ];

                if ($existing === null) {
                    $word = Word::create(['lesson_id' => $lesson->id, 'word' => $w['word']] + $payload);
                    $this->upsertTranslations($word, $w, new Collection, $report);
                    $report->added++;

                    continue;
                }

                $existing->fill($payload);
                $changed = $existing->isDirty();
                if ($changed) {
                    $existing->save();
                }

                $translationsChanged = $this->upsertTranslations(
                    $existing,
                    $w,
                    $existingTranslations->get((int) $existing->id, new Collection),
                    $report,
                );

                if ($changed || $translationsChanged) {
                    $report->updated++;
                } else {
                    $report->skipped++;
                }
            }

            $this->publisher->recount($course);

            AuditLog::create([
                'user_id' => $by?->id,
                'action' => 'import.uploaded',
                'entity_type' => Lesson::class,
                'entity_id' => $lesson->id,
                'ip' => $auditContext['ip'] ?? null,
                'meta' => [
                    'course_id' => $course->id,
                    'course_slug' => $course->slug,
                    'stage_id' => $stage->id,
                    'stage_number' => $stage->number,
                    'lesson_number' => $lesson->number,
                    'report' => $report->toArray(),
                ],
            ]);

            return $lesson;
        });

        // After the commit: a rolled-back import must not drop (or re-warm from stale rows) the cache.
        $this->cache->forgetLesson($lesson);

        return $report;
    }

    private function refuse(ImportReport $report): ImportReport
    {
        $report->aborted = true;
        $report->errors[] = 'Import is not allowed: only a platform admin can import vocabulary.';

        return $report;
    }

    /**
     * `course` is the slug of an existing course; no `course` → the platform default course.
     */
    private function resolveCourse(mixed $slug, ImportReport $report): ?Course
    {
        if ($slug === null) {
            return $this->catalog->defaultCourse();
        }

        $course = is_string($slug) ? $this->catalog->findBySlug($slug) : null;
        if ($course === null) {
            $report->aborted = true;
            $report->errors[] = 'Course '.(is_string($slug) ? "'{$slug}'" : '(invalid)').' not found: create it in the admin panel first.';
        }

        return $course;
    }

    /**
     * Flattens both file schemas into one shape and checks the translation rules the
     * validator cannot express: ru is required (it is `words.translation`), `translation`
     * and `translations.ru` must agree, only supported locales are allowed.
     *
     * @param  list<array<string, mixed>>  $words
     * @return list<array{word:string, example:?string, part_of_speech:?string, transcription:?string, translations:array<string,string>, example_translations:array<string,string>}>
     */
    private function normalizeWords(array $words, ImportReport $report): array
    {
        $supported = $this->locales->supported();
        $out = [];

        foreach ($words as $i => $w) {
            $label = "Word '{$w['word']}' (index {$i})";
            $translations = $this->stringMap($w['translations'] ?? null);
            $exampleTranslations = $this->stringMap($w['example_translations'] ?? null);

            // Locale keys are checked on the raw maps: an empty value must not hide an unsupported locale.
            foreach (['translations' => $w['translations'] ?? null, 'example_translations' => $w['example_translations'] ?? null] as $field => $raw) {
                foreach (is_array($raw) ? array_keys($raw) : [] as $locale) {
                    if (! in_array((string) $locale, $supported, true)) {
                        $report->aborted = true;
                        $report->errors[] = "{$label}: unsupported locale '{$locale}' in {$field} (supported: ".implode(', ', $supported).').';
                    }
                }
            }

            $legacy = isset($w['translation']) && is_string($w['translation']) ? trim($w['translation']) : '';
            $ru = $translations['ru'] ?? '';

            if ($legacy !== '' && $ru !== '' && $legacy !== $ru) {
                $report->aborted = true;
                $report->errors[] = "{$label}: 'translation' and 'translations.ru' differ.";
            }

            if ($legacy === '' && $ru === '') {
                $report->aborted = true;
                $report->errors[] = "{$label}: ru translation is required ('translation' or 'translations.ru').";
            }

            $translations['ru'] = $legacy !== '' ? $legacy : $ru;

            // An example translation needs its word translation; silently dropping it would hide a typo.
            foreach (array_keys($exampleTranslations) as $locale) {
                if (in_array($locale, $supported, true) && ($translations[$locale] ?? '') === '') {
                    $report->aborted = true;
                    $report->errors[] = "{$label}: example_translations '{$locale}' has no matching translations.{$locale}.";
                }
            }

            $out[] = [
                'word' => (string) $w['word'],
                'example' => isset($w['example']) ? (string) $w['example'] : null,
                'part_of_speech' => isset($w['part_of_speech']) ? (string) $w['part_of_speech'] : null,
                'transcription' => isset($w['transcription']) ? (string) $w['transcription'] : null,
                'translations' => $translations,
                'example_translations' => $exampleTranslations,
            ];
        }

        return $out;
    }

    /**
     * Non-empty, trimmed string values of a `locale => text` object.
     *
     * @return array<string, string>
     */
    private function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $key => $text) {
            if (is_string($text) && trim($text) !== '') {
                $out[(string) $key] = trim($text);
            }
        }

        return $out;
    }

    /**
     * Writes the `word_translations` rows of one word; returns true when anything changed.
     * A changed text is overwritten as an `import` row and loses `reviewed_at` (needs a new check).
     *
     * @param  array{translations:array<string,string>, example_translations:array<string,string>}  $w
     * @param  Collection<string, WordTranslation>  $existing  rows of this word by locale
     */
    private function upsertTranslations(Word $word, array $w, Collection $existing, ImportReport $report): bool
    {
        $changed = false;

        foreach ($w['translations'] as $locale => $text) {
            $example = $w['example_translations'][$locale] ?? null;
            $row = $existing->get($locale);

            if ($row === null) {
                WordTranslation::create([
                    'word_id' => $word->id,
                    'locale' => $locale,
                    'translation' => $text,
                    'example_translation' => $example,
                    'source' => 'import',
                ]);
                $report->translationsAdded++;
                $changed = true;

                continue;
            }

            if ($row->translation === $text && ($example === null || $row->example_translation === $example)) {
                continue;
            }

            $row->fill([
                'translation' => $text,
                'example_translation' => $example ?? $row->example_translation,
                'source' => 'import',
                'reviewed_at' => null,
            ])->save();
            $report->translationsUpdated++;
            $changed = true;
        }

        return $changed;
    }

    /**
     * Support both the original PRD schema:
     *   { "stage": 4, "lesson": 8, "vocabulary": [...] }
     * and the richer admin schema:
     *   { "stage": {"number": 4}, "lesson": {"number": 8}, "words": [...] }.
     * `course` (v2) is passed through in both.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeShape(array $data): array
    {
        if (
            array_key_exists('vocabulary', $data)
            && ! array_key_exists('words', $data)
            && isset($data['stage'], $data['lesson'])
            && ! is_array($data['stage'])
            && ! is_array($data['lesson'])
        ) {
            return [
                'course' => $data['course'] ?? null,
                'stage' => ['number' => $data['stage']],
                'lesson' => ['number' => $data['lesson']],
                'words' => $data['vocabulary'],
            ];
        }

        return $data;
    }

    /** @return array<string, array<int, string>|string> */
    private function rules(): array
    {
        return [
            'course' => ['sometimes', 'nullable', 'string', 'max:64'],

            'stage' => ['required', 'array'],
            'stage.number' => ['required', 'integer', 'min:1'],
            'stage.title' => ['sometimes', 'string', 'max:255'],
            'stage.description' => ['sometimes', 'nullable', 'string'],

            'lesson' => ['required', 'array'],
            'lesson.number' => ['required', 'integer', 'min:1'],
            'lesson.title' => ['sometimes', 'nullable', 'string', 'max:255'],

            'words' => ['required', 'array', 'min:1', 'max:500'],
            'words.*' => ['required', 'array'],
            'words.*.word' => ['required', 'string', 'max:100'],
            // ru text: `translation` and/or `translations.ru` — presence is checked in normalizeWords().
            'words.*.translation' => ['sometimes', 'nullable', 'string', 'max:500'],
            'words.*.translations' => ['sometimes', 'nullable', 'array'],
            'words.*.translations.*' => ['nullable', 'string', 'max:500'],
            'words.*.example_translations' => ['sometimes', 'nullable', 'array'],
            'words.*.example_translations.*' => ['nullable', 'string', 'max:2000'],
            'words.*.example' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'words.*.part_of_speech' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', self::ALLOWED_POS)],
            'words.*.transcription' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    /** @param  list<array<string,mixed>>  $words */
    private function assertUniqueWords(array $words, ImportReport $report): void
    {
        $seen = [];
        foreach ($words as $i => $w) {
            $key = mb_strtolower(trim((string) $w['word']));
            if (isset($seen[$key])) {
                $report->aborted = true;
                $report->errors[] = "Duplicate word '{$w['word']}' at index {$i}.";

                return;
            }
            $seen[$key] = true;
        }
    }
}
