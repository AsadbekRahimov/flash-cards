<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Content\DTO\ImportIssue;
use App\Domain\Content\DTO\ImportReport;
use App\Domain\Learning\Services\LearningCache;
use App\Domain\Localization\Services\LocaleResolver;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\User;
use App\Models\Word;
use App\Models\WordExample;
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

    public const MAX_EXAMPLES = 10;

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
     *   The method (docs/06 §12, all optional, every key absent = unchanged):
     *       - `hooks`: {"ru": "...", "uz": "..."} — the authored memory hook per locale (`word_translations.hook`);
     *       - `examples`: [{"text": "...", "translations": {"ru": "...", "uz": "..."}}, ...] — ADDITIONAL examples
     *         (`word_examples`, positions 1..; `example` stays the anchor). The list replaces the word's rows;
     *       - `confusable_with`: ["father", ...] — words of the same course this one is mixed up with (`words.meta`).
     *
     * The ru text is always written to `words.translation` AND to `word_translations`. Re-importing a
     * file is idempotent: unchanged rows are skipped; a changed translation is overwritten and loses its
     * `reviewed_at` mark. Everything is written in ONE transaction or nothing at all.
     *
     * Who may import (docs/12 §8: in Phase 1 content is platform-owned): `$by` must pass the `update`
     * ability of the target course (CoursePolicy → an active admin); a refusal is an `aborted` report
     * and nothing is written. A person is always passed explicitly to {@see import()}; trusted code
     * without a user (seeders, artisan) calls {@see importAsSystem()} — an explicit entry point that
     * skips the policy and marks the audit row `meta.actor = system`. The environment (console, queue
     * worker, Octane) never decides who is allowed to import.
     *
     * @param  array{ip?:string|null}  $auditContext
     */
    public function import(string $rawJson, User $by, array $auditContext = []): ImportReport
    {
        return $this->run($rawJson, $by, $auditContext);
    }

    /**
     * Import by trusted system code (seeders, artisan commands): no policy check, audit `user_id` is
     * null and `meta.actor` is `system`. Never call it with a user-supplied file from a request.
     *
     * @param  array{ip?:string|null}  $auditContext
     */
    public function importAsSystem(string $rawJson, array $auditContext = []): ImportReport
    {
        return $this->run($rawJson, null, $auditContext);
    }

    /** @param  array{ip?:string|null}  $auditContext */
    private function run(string $rawJson, ?User $by, array $auditContext): ImportReport
    {
        $report = new ImportReport;

        if ($by !== null && Gate::forUser($by)->denies('viewAny', Course::class)) {
            return $this->refuse($report);
        }

        if (strlen($rawJson) > self::MAX_BYTES) {
            $report->abort(ImportIssue::FILE_TOO_LARGE, sprintf('File exceeds %d bytes limit.', self::MAX_BYTES), ['megabytes' => self::MAX_BYTES / 1024 / 1024]);

            return $report;
        }

        try {
            $data = json_decode($rawJson, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $report->abort(ImportIssue::INVALID_JSON, 'Invalid JSON: '.$e->getMessage(), ['cause' => $this->jsonCause($e)]);

            return $report;
        }

        if (! is_array($data)) {
            $report->abort(ImportIssue::ROOT_NOT_OBJECT, 'Root must be an object.');

            return $report;
        }

        $data = $this->normalizeShape($data);

        $validator = Validator::make($data, $this->rules());

        if ($validator->fails()) {
            foreach ($validator->errors()->messages() as $field => $messages) {
                foreach ($messages as $msg) {
                    $report->abort(ImportIssue::VALIDATION, $msg, ['field' => (string) $field, 'message' => $msg]);
                }
            }

            return $report;
        }

        /** @var array{course?:string|null,stage:array{number:int,title?:string,title_uz?:string|null,description?:string},lesson:array{number:int,title?:string|null,title_uz?:string|null},words:list<array<string,mixed>>} $data */
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
            // The first lock of the transaction: the writers of one course take turns (CoursePublisher::lockForWriting).
            $this->publisher->lockForWriting($course);

            // A file without a stage title (or with a blank one) gives a new module NO name (NULL: readers are shown
            // "Модуль N" / "N-modul" in their language, docs/14 §8) and leaves the name of an existing module alone. The
            // Uzbek name (`title_uz`, docs/03 §2.5) follows the same rule; a lesson's `title_uz` is the file's when the key is present.
            $stage = Stage::updateOrCreate(
                ['course_id' => $course->id, 'number' => $data['stage']['number']],
                array_filter([
                    'title' => self::name($data['stage']['title'] ?? null),
                    'title_uz' => self::name($data['stage']['title_uz'] ?? null),
                    'description' => $data['stage']['description'] ?? null,
                ], fn ($v): bool => $v !== null),
            );

            // `title_uz`: no key in the file - the name typed in the panel stays; the key present and blank - NULL.
            $lessonValues = ['title' => $data['lesson']['title'] ?? null];
            if (array_key_exists('title_uz', $data['lesson'])) {
                $lessonValues['title_uz'] = self::name($data['lesson']['title_uz']);
            }
            $lesson = Lesson::updateOrCreate(
                ['stage_id' => $stage->id, 'number' => $data['lesson']['number']],
                $lessonValues,
            );

            /** @var Collection<string, Word> $existingWords */
            $existingWords = Word::query()->where('lesson_id', $lesson->id)->get()->keyBy('word');
            /** @var Collection<int, Collection<string, WordTranslation>> $existingTranslations */
            $existingTranslations = WordTranslation::query()
                ->whereIn('word_id', $existingWords->pluck('id')->all())
                ->get()
                ->groupBy('word_id')
                ->map(fn (Collection $rows): Collection => $rows->keyBy('locale'));

            $examplesChanged = [];

            foreach ($words as $w) {
                $existing = $existingWords->get($w['word']);

                $payload = [
                    'translation' => $w['translations']['ru'],
                    'example' => $w['example'],
                    'part_of_speech' => $w['part_of_speech'],
                    'transcription' => $w['transcription'],
                ];

                if ($existing === null) {
                    $word = Word::create(['lesson_id' => $lesson->id, 'word' => $w['word']] + $payload + ['meta' => $this->metaWith([], $w)]);
                    $this->upsertTranslations($word, $w, new Collection, $report);
                    if ($this->syncExamples($word, $w)) {
                        $examplesChanged[] = (int) $word->id;
                    }
                    $report->added++;

                    continue;
                }

                $existing->fill($payload);
                $currentMeta = $existing->getAttribute('meta');
                $existing->setAttribute('meta', $this->metaWith(is_array($currentMeta) ? $currentMeta : [], $w));
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

                $rowsChanged = $this->syncExamples($existing, $w);
                if ($rowsChanged) {
                    $examplesChanged[] = (int) $existing->id;
                }

                if ($changed || $translationsChanged || $rowsChanged) {
                    $report->updated++;
                } else {
                    $report->skipped++;
                }
            }

            if ($examplesChanged !== []) {
                // The cached examples of the words whose rows changed (docs/06 §12.4) — after the commit, like the lesson's keys.
                DB::afterCommit(fn () => $this->cache->forgetWordExamples($examplesChanged));
            }

            $this->publisher->recount($course);

            AuditLog::create([
                'user_id' => $by?->id,
                'action' => 'import.uploaded',
                'entity_type' => Lesson::class,
                'entity_id' => $lesson->id,
                'ip' => $auditContext['ip'] ?? null,
                'meta' => [
                    'actor' => $by === null ? 'system' : 'user',
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

        // After the commit (also the OUTER one, when the caller wraps us in a transaction): a rolled-back
        // import must not drop (or re-warm from stale rows) the cache.
        DB::afterCommit(fn () => $this->cache->forgetLesson($lesson));

        return $report;
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

    private function refuse(ImportReport $report): ImportReport
    {
        $report->abort(ImportIssue::NOT_ALLOWED, 'Import is not allowed: only a platform admin can import vocabulary.');

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
            $report->abort(
                ImportIssue::COURSE_NOT_FOUND,
                'Course '.(is_string($slug) ? "'{$slug}'" : '(invalid)').' not found: create it in the admin panel first.',
                ['slug' => is_string($slug) ? $slug : '(invalid)'],
            );
        }

        return $course;
    }

    /**
     * Flattens both file schemas into one shape and checks the translation rules the
     * validator cannot express: ru is required (it is `words.translation`), `translation`
     * and `translations.ru` must agree, only supported locales are allowed.
     *
     * @param  list<array<string, mixed>>  $words
     * @return list<array{word:string, example:?string, part_of_speech:?string, transcription:?string, translations:array<string,string>, example_translations:array<string,string>, hooks:array<string,string>, has_hooks:bool, examples:list<array{text:string, translations:array<string,string>}>, has_examples:bool, confusable_with:list<string>, has_confusable_with:bool}>
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
                        $report->abort(
                            ImportIssue::UNSUPPORTED_LOCALE,
                            "{$label}: unsupported locale '{$locale}' in {$field} (supported: ".implode(', ', $supported).').',
                            ['word' => (string) $w['word'], 'position' => $i + 1, 'locale' => (string) $locale, 'field' => $field, 'supported' => $supported],
                        );
                    }
                }
            }

            $legacy = isset($w['translation']) && is_string($w['translation']) ? trim($w['translation']) : '';
            $ru = $translations['ru'] ?? '';

            if ($legacy !== '' && $ru !== '' && $legacy !== $ru) {
                $report->abort(ImportIssue::TRANSLATION_CONFLICT, "{$label}: 'translation' and 'translations.ru' differ.", ['word' => (string) $w['word'], 'position' => $i + 1]);
            }

            if ($legacy === '' && $ru === '') {
                $report->abort(ImportIssue::RU_REQUIRED, "{$label}: ru translation is required ('translation' or 'translations.ru').", ['word' => (string) $w['word'], 'position' => $i + 1]);
            }

            $translations['ru'] = $legacy !== '' ? $legacy : $ru;

            // An example translation needs its word translation; silently dropping it would hide a typo.
            foreach (array_keys($exampleTranslations) as $locale) {
                if (in_array($locale, $supported, true) && ($translations[$locale] ?? '') === '') {
                    $report->abort(
                        ImportIssue::EXAMPLE_TRANSLATION_ORPHAN,
                        "{$label}: example_translations '{$locale}' has no matching translations.{$locale}.",
                        ['word' => (string) $w['word'], 'position' => $i + 1, 'locale' => $locale],
                    );
                }
            }

            $hooks = $this->stringMap($w['hooks'] ?? null);
            foreach (is_array($w['hooks'] ?? null) ? array_keys($w['hooks']) : [] as $locale) {
                if (! in_array((string) $locale, $supported, true)) {
                    $report->abort(
                        ImportIssue::UNSUPPORTED_LOCALE,
                        "{$label}: unsupported locale '{$locale}' in hooks (supported: ".implode(', ', $supported).').',
                        ['word' => (string) $w['word'], 'position' => $i + 1, 'locale' => (string) $locale, 'field' => 'hooks', 'supported' => $supported],
                    );
                }
            }
            // A hook rides with the translation row of its locale (docs/06 §12.3): there must be one.
            foreach (array_keys($hooks) as $locale) {
                if (in_array($locale, $supported, true) && ($translations[$locale] ?? '') === '') {
                    $report->abort(
                        ImportIssue::EXAMPLE_TRANSLATION_ORPHAN,
                        "{$label}: hooks '{$locale}' has no matching translations.{$locale}.",
                        ['word' => (string) $w['word'], 'position' => $i + 1, 'locale' => $locale],
                    );
                }
            }

            $examples = [];
            foreach (is_array($w['examples'] ?? null) ? $w['examples'] : [] as $example) {
                if (! is_array($example)) {
                    continue;
                }
                $text = isset($example['text']) && is_string($example['text']) ? trim($example['text']) : '';
                if ($text === '') {
                    continue;
                }
                foreach (is_array($example['translations'] ?? null) ? array_keys($example['translations']) : [] as $locale) {
                    if (! in_array((string) $locale, $supported, true)) {
                        $report->abort(
                            ImportIssue::UNSUPPORTED_LOCALE,
                            "{$label}: unsupported locale '{$locale}' in examples (supported: ".implode(', ', $supported).').',
                            ['word' => (string) $w['word'], 'position' => $i + 1, 'locale' => (string) $locale, 'field' => 'examples', 'supported' => $supported],
                        );
                    }
                }
                $examples[] = ['text' => $text, 'translations' => $this->stringMap($example['translations'] ?? null)];
            }

            $confusable = [];
            foreach (is_array($w['confusable_with'] ?? null) ? $w['confusable_with'] : [] as $other) {
                if (is_string($other) && trim($other) !== '' && mb_strtolower(trim($other)) !== mb_strtolower(trim((string) $w['word']))) {
                    $confusable[mb_strtolower(trim($other))] = true;
                }
            }

            $out[] = [
                'word' => (string) $w['word'],
                'example' => isset($w['example']) ? (string) $w['example'] : null,
                'part_of_speech' => isset($w['part_of_speech']) ? (string) $w['part_of_speech'] : null,
                'transcription' => isset($w['transcription']) ? (string) $w['transcription'] : null,
                'translations' => $translations,
                'example_translations' => $exampleTranslations,
                'hooks' => $hooks,
                'has_hooks' => array_key_exists('hooks', $w),
                'examples' => $examples,
                'has_examples' => array_key_exists('examples', $w),
                'confusable_with' => array_keys($confusable),
                'has_confusable_with' => array_key_exists('confusable_with', $w),
            ];
        }

        return $out;
    }

    /**
     * `words.meta` after this file: `confusable_with` replaced when the key is present (an empty list removes the mark),
     * every other key of the meta left alone.
     *
     * @param  array<mixed>  $meta
     * @param  array{confusable_with: list<string>, has_confusable_with: bool}  $w
     * @return array<mixed>
     */
    private function metaWith(array $meta, array $w): array
    {
        if (! $w['has_confusable_with']) {
            return $meta;
        }

        if ($w['confusable_with'] === []) {
            unset($meta['confusable_with']);

            return $meta;
        }

        $meta['confusable_with'] = $w['confusable_with'];

        return $meta;
    }

    /**
     * The additional examples of the word (`word_examples`, docs/06 §12.4): the file's list replaces the rows when the key
     * is present (an empty list removes them); a file without the key leaves them alone. Returns true when anything changed.
     *
     * @param  array{examples: list<array{text: string, translations: array<string, string>}>, has_examples: bool}  $w
     */
    private function syncExamples(Word $word, array $w): bool
    {
        if (! $w['has_examples']) {
            return false;
        }

        $current = WordExample::query()
            ->where('word_id', $word->id)
            ->orderBy('position')
            ->get()
            ->map(static fn (WordExample $row): array => ['text' => $row->text, 'translations' => $row->translations ?? []])
            ->values()
            ->all();

        $wanted = array_map(static fn (array $e): array => ['text' => $e['text'], 'translations' => $e['translations']], $w['examples']);
        if ($current === $wanted) {
            return false;
        }

        WordExample::query()->where('word_id', $word->id)->delete();
        foreach ($wanted as $i => $example) {
            WordExample::query()->create([
                'word_id' => $word->id,
                'position' => $i + 1,
                'text' => $example['text'],
                'translations' => $example['translations'] === [] ? null : $example['translations'],
            ]);
        }

        return true;
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
     * The hook (docs/06 §12.3): written when the file has the `hooks` key — the locale's text, or NULL when the key is there
     * and the locale is not (the mark is taken back); a file without `hooks` leaves it alone. A changed hook alone does not
     * reset `reviewed_at`: it is not the translation.
     *
     * @param  array{translations:array<string,string>, example_translations:array<string,string>, hooks:array<string,string>, has_hooks:bool}  $w
     * @param  Collection<string, WordTranslation>  $existing  rows of this word by locale
     */
    private function upsertTranslations(Word $word, array $w, Collection $existing, ImportReport $report): bool
    {
        $changed = false;

        foreach ($w['translations'] as $locale => $text) {
            $example = $w['example_translations'][$locale] ?? null;
            $hook = $w['hooks'][$locale] ?? null;
            $row = $existing->get($locale);

            if ($row === null) {
                WordTranslation::create([
                    'word_id' => $word->id,
                    'locale' => $locale,
                    'translation' => $text,
                    'example_translation' => $example,
                    'hook' => $hook,
                    'source' => 'import',
                ]);
                $report->translationsAdded++;
                $changed = true;

                continue;
            }

            $hookChanged = $w['has_hooks'] && $row->hook !== $hook;
            if ($row->translation === $text && ($example === null || $row->example_translation === $example)) {
                if ($hookChanged) {
                    $row->fill(['hook' => $hook])->save();
                    $report->translationsUpdated++;
                    $changed = true;
                }

                continue;
            }

            $row->fill([
                'translation' => $text,
                'example_translation' => $example ?? $row->example_translation,
                'hook' => $w['has_hooks'] ? $hook : $row->hook,
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
            'stage.title_uz' => ['sometimes', 'nullable', 'string', 'max:255'],
            'stage.description' => ['sometimes', 'nullable', 'string'],

            'lesson' => ['required', 'array'],
            'lesson.number' => ['required', 'integer', 'min:1'],
            'lesson.title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'lesson.title_uz' => ['sometimes', 'nullable', 'string', 'max:255'],

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
            // The method (docs/06 §12): hooks per locale, additional examples, confusable words.
            'words.*.hooks' => ['sometimes', 'nullable', 'array'],
            'words.*.hooks.*' => ['nullable', 'string', 'max:500'],
            'words.*.examples' => ['sometimes', 'nullable', 'array', 'max:'.self::MAX_EXAMPLES],
            'words.*.examples.*' => ['required', 'array'],
            'words.*.examples.*.text' => ['required', 'string', 'max:2000'],
            'words.*.examples.*.translations' => ['sometimes', 'nullable', 'array'],
            'words.*.examples.*.translations.*' => ['nullable', 'string', 'max:2000'],
            'words.*.confusable_with' => ['sometimes', 'nullable', 'array', 'max:10'],
            'words.*.confusable_with.*' => ['required', 'string', 'max:100'],
            'words.*.part_of_speech' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', self::ALLOWED_POS)],
            'words.*.transcription' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    /** A name from the file: a string that is not blank; null for anything else (no name). */
    private static function name(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /** @param  list<array<string,mixed>>  $words */
    private function assertUniqueWords(array $words, ImportReport $report): void
    {
        $seen = [];
        foreach ($words as $i => $w) {
            $key = mb_strtolower(trim((string) $w['word']));
            if (isset($seen[$key])) {
                $report->abort(ImportIssue::DUPLICATE_WORD, "Duplicate word '{$w['word']}' at index {$i}.", ['word' => (string) $w['word'], 'position' => $i + 1]);

                return;
            }
            $seen[$key] = true;
        }
    }
}
