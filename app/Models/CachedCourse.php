<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Content\Services\CourseCatalogService;
use LogicException;

/**
 * A course as the pure cache returned it ({@see CourseCatalogService::cachedCourse()}): the row of `courses`,
 * possibly minutes behind the database, for reads only. Saving or deleting it would write that old row back over a newer one,
 * so it refuses; whoever changes a course reads it with `Course::query()`.
 */
final class CachedCourse extends Course
{
    protected $table = 'courses';

    /** @param  array<string, mixed>  $options */
    public function save(array $options = []): never
    {
        throw new LogicException('A course from the cache is read-only: read it from the database to change it.');
    }

    public function delete(): never
    {
        throw new LogicException('A course from the cache is read-only: read it from the database to delete it.');
    }
}
