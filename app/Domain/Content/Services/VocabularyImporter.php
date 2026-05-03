<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Content\DTO\ImportReport;
use App\Models\AuditLog;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\Word;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

final class VocabularyImporter
{
    public const MAX_BYTES = 2 * 1024 * 1024; // 2 MB

    public const ALLOWED_POS = [
        'noun', 'verb', 'adjective', 'adverb',
        'pronoun', 'preposition', 'conjunction', 'interjection',
    ];

    /**
     * @param  array{user_id?:int|null, ip?:string|null}  $auditContext
     */
    public function import(string $rawJson, array $auditContext = []): ImportReport
    {
        $report = new ImportReport;

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

        /** @var array{stage:array{number:int,title?:string,description?:string},lesson:array{number:int,title?:string},words:list<array<string,string>>} $data */
        $this->assertUniqueWords($data['words'], $report);

        if ($report->aborted) {
            return $report;
        }

        DB::transaction(function () use ($data, $report, $auditContext): void {
            $stage = Stage::updateOrCreate(
                ['number' => $data['stage']['number']],
                array_filter([
                    'title' => $data['stage']['title'] ?? "Stage {$data['stage']['number']}",
                    'description' => $data['stage']['description'] ?? null,
                ], fn ($v): bool => $v !== null),
            );

            $lesson = Lesson::updateOrCreate(
                ['stage_id' => $stage->id, 'number' => $data['lesson']['number']],
                ['title' => $data['lesson']['title'] ?? null],
            );

            foreach ($data['words'] as $w) {
                $existing = Word::where('lesson_id', $lesson->id)
                    ->where('word', $w['word'])
                    ->first();

                $payload = [
                    'translation' => $w['translation'],
                    'example' => $w['example'] ?? null,
                    'part_of_speech' => $w['part_of_speech'] ?? null,
                    'transcription' => $w['transcription'] ?? null,
                ];

                if ($existing === null) {
                    Word::create(['lesson_id' => $lesson->id, 'word' => $w['word']] + $payload);
                    $report->added++;
                } else {
                    $existing->fill($payload);
                    if ($existing->isDirty()) {
                        $existing->save();
                        $report->updated++;
                    } else {
                        $report->skipped++;
                    }
                }
            }

            AuditLog::create([
                'user_id' => $auditContext['user_id'] ?? null,
                'action' => 'import.uploaded',
                'entity_type' => Lesson::class,
                'entity_id' => $lesson->id,
                'ip' => $auditContext['ip'] ?? null,
                'meta' => [
                    'stage_id' => $stage->id,
                    'stage_number' => $stage->number,
                    'lesson_number' => $lesson->number,
                    'report' => $report->toArray(),
                ],
            ]);
        });

        return $report;
    }

    /**
     * Support both the original PRD schema:
     *   { "stage": 4, "lesson": 8, "vocabulary": [...] }
     * and the richer admin schema:
     *   { "stage": {"number": 4}, "lesson": {"number": 8}, "words": [...] }.
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
            'words.*.translation' => ['required', 'string', 'max:500'],
            'words.*.example' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'words.*.part_of_speech' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', self::ALLOWED_POS)],
            'words.*.transcription' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    /** @param  list<array<string,string>>  $words */
    private function assertUniqueWords(array $words, ImportReport $report): void
    {
        $seen = [];
        foreach ($words as $i => $w) {
            $key = mb_strtolower(trim($w['word']));
            if (isset($seen[$key])) {
                $report->aborted = true;
                $report->errors[] = "Duplicate word '{$w['word']}' at index {$i}.";

                return;
            }
            $seen[$key] = true;
        }
    }
}
