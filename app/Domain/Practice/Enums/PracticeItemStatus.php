<?php

declare(strict_types=1);

namespace App\Domain\Practice\Enums;

/**
 * The status of a task (`practice_items.status`, docs/16 §2.2) - a projection of its versions, written only by
 * PracticeItemService: `archived` when `archived_at` is set; otherwise `published` when there is a
 * `published_version_id`; otherwise the state of the current version (`draft` / `reviewed`).
 */
enum PracticeItemStatus: string
{
    case Draft = 'draft';
    case Reviewed = 'reviewed';
    case Published = 'published';
    case Archived = 'archived';
}
