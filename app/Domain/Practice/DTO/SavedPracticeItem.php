<?php

declare(strict_types=1);

namespace App\Domain\Practice\DTO;

use App\Models\PracticeItem;
use App\Models\PracticeItemVersion;

/**
 * What a save did to a task (PracticeItemService::save): the task and the version it now stands on (`version` is the
 * current one - a new row when `versionCreated`, otherwise the one the text already was in).
 */
final readonly class SavedPracticeItem
{
    public const ADDED = 'added';

    public const UPDATED = 'updated';

    public const SKIPPED = 'skipped';

    public function __construct(
        public PracticeItem $item,
        public PracticeItemVersion $version,
        public bool $itemCreated,
        public bool $versionCreated,
        public bool $attributesChanged,
        public bool $published,
    ) {}

    /** `added` (a new task), `updated` (a new version, or a changed lesson / level) or `skipped` (nothing changed). */
    public function outcome(): string
    {
        return match (true) {
            $this->itemCreated => self::ADDED,
            $this->versionCreated, $this->attributesChanged => self::UPDATED,
            default => self::SKIPPED,
        };
    }
}
