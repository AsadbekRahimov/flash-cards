<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Content\DTO\CoursePackReport;
use App\Domain\Localization\Services\LocaleResolver;
use App\Models\Course;
use Throwable;

/**
 * Imports a course pack: a directory with `course.json` (the manifest: slug, titles and descriptions per language, level,
 * `exam_tag`, ...) and one lesson file per lesson (`mNN-lN.json`, the v2 schema of {@see VocabularyImporter}).
 *
 * `$create` makes the course as a DRAFT when it does not exist yet (never published here: that is the admin's decision after
 * the check); an existing course is left as it is. Every file is imported by {@see VocabularyImporter::importAsSystem()} in a
 * transaction of its own, so a broken file does not take the others with it, and a second run over the same pack is
 * idempotent (all words `skipped`). Trusted callers only (artisan): no policy is checked.
 */
final class CoursePackImporter
{
    public const MANIFEST = 'course.json';

    public const LESSON_GLOB = 'm*-l*.json';

    public function __construct(
        private readonly VocabularyImporter $importer,
        private readonly CourseCatalogService $catalog,
        private readonly LocaleResolver $locales,
    ) {}

    public function import(string $dir, bool $create): CoursePackReport
    {
        $report = new CoursePackReport;
        $dir = rtrim($dir, '/');

        $manifest = $this->readManifest($dir, $report);
        if ($manifest === null) {
            return $report;
        }

        $slug = $manifest['slug'];

        if ($this->catalog->findBySlug($slug) === null) {
            if (! $create) {
                $report->errors[] = "Course '{$slug}' does not exist: run with --create to create it as a draft.";

                return $report;
            }

            $this->createCourse($manifest);
            $report->courseCreated = true;
        }

        $files = glob($dir.'/'.self::LESSON_GLOB) ?: [];
        natsort($files);
        if ($files === []) {
            $report->errors[] = 'No lesson files ('.self::LESSON_GLOB.") in {$dir}.";

            return $report;
        }

        foreach ($files as $file) {
            $name = basename($file);
            $raw = file_get_contents($file);
            if ($raw === false) {
                $report->errors[] = "{$name}: file is not readable.";

                continue;
            }

            // A lesson file must name this very course: a stray file of another pack must not land in it.
            $decoded = json_decode($raw, true);
            if (! is_array($decoded) || ($decoded['course'] ?? null) !== $slug) {
                $report->errors[] = "{$name}: 'course' must be '{$slug}'.";

                continue;
            }

            $report->files[$name] = $this->importer->importAsSystem($raw);
        }

        return $report;
    }

    /** @return array{slug:string, title:array<string,string>, description:array<string,string>, level:?string, exam_tag:?string, visibility:string, position:int, is_featured:bool}|null */
    private function readManifest(string $dir, CoursePackReport $report): ?array
    {
        $path = $dir.'/'.self::MANIFEST;
        if (! is_file($path)) {
            $report->errors[] = 'Manifest '.self::MANIFEST." not found in {$dir}.";

            return null;
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $report->errors[] = self::MANIFEST.': invalid JSON: '.$e->getMessage();

            return null;
        }

        $data = is_array($data) ? $data : [];
        $slug = $data['slug'] ?? null;
        if (! is_string($slug) || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1 || strlen($slug) > 64) {
            $report->errors[] = self::MANIFEST.": 'slug' must be a lowercase slug of up to 64 characters.";

            return null;
        }

        $title = $this->texts($data['title'] ?? null);
        foreach ($this->locales->supported() as $locale) {
            if (! isset($title[$locale])) {
                $report->errors[] = self::MANIFEST.": 'title.{$locale}' is required.";
            }
        }
        if ($report->errors !== []) {
            return null;
        }

        return [
            'slug' => $slug,
            'title' => $title,
            'description' => $this->texts($data['description'] ?? null),
            'level' => is_string($data['level'] ?? null) ? $data['level'] : null,
            'exam_tag' => is_string($data['exam_tag'] ?? null) ? $data['exam_tag'] : null,
            'visibility' => is_string($data['visibility'] ?? null) ? $data['visibility'] : 'public',
            'position' => is_int($data['position'] ?? null) ? $data['position'] : 0,
            'is_featured' => ($data['is_featured'] ?? false) === true,
        ];
    }

    /** @return array<string, string> non-empty texts by locale */
    private function texts(mixed $value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $locale => $text) {
            if (is_string($text) && trim($text) !== '') {
                $out[(string) $locale] = trim($text);
            }
        }

        return $out;
    }

    /** @param  array{slug:string, title:array<string,string>, description:array<string,string>, level:?string, exam_tag:?string, visibility:string, position:int, is_featured:bool}  $manifest */
    private function createCourse(array $manifest): Course
    {
        return Course::create([
            'slug' => $manifest['slug'],
            'owner_type' => 'platform',
            'source' => 'platform',
            'title' => $manifest['title'],
            'description' => $manifest['description'],
            'target_language' => 'en',
            'level' => $manifest['level'],
            'exam_tag' => $manifest['exam_tag'],
            'visibility' => $manifest['visibility'],
            'status' => CoursePublisher::DRAFT,
            'position' => $manifest['position'],
            'is_featured' => $manifest['is_featured'],
        ]);
    }
}
