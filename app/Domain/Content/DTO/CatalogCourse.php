<?php

declare(strict_types=1);

namespace App\Domain\Content\DTO;

/**
 * A course as the catalog shows it, localized for one interface locale
 * (no per-learner data, so it is safe to cache). `title` / `description`
 * already went through the locale → ru fallback.
 *
 * @phpstan-type CatalogCourseArray array{id:int, slug:string, title:string, description:string|null, level:string|null, exam_tag:string|null, words_count:int, is_featured:bool}
 */
final readonly class CatalogCourse
{
    public function __construct(
        public int $id,
        public string $slug,
        public string $title,
        public ?string $description,
        public ?string $level,
        public ?string $examTag,
        public int $wordsCount,
        public bool $isFeatured,
    ) {}

    /** @return CatalogCourseArray */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'description' => $this->description,
            'level' => $this->level,
            'exam_tag' => $this->examTag,
            'words_count' => $this->wordsCount,
            'is_featured' => $this->isFeatured,
        ];
    }

    /** @param  CatalogCourseArray  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['slug'],
            $data['title'],
            $data['description'],
            $data['level'],
            $data['exam_tag'],
            $data['words_count'],
            $data['is_featured'],
        );
    }
}
