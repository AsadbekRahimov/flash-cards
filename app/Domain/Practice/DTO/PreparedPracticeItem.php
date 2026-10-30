<?php

declare(strict_types=1);

namespace App\Domain\Practice\DTO;

use App\Domain\Practice\Enums\PracticeItemType;

/**
 * A task that passed the validation of the text and of its place (docs/16 §4.5): everything PracticeItemService needs to
 * write it, cleaned and in the form it is stored in. The importer prepares all the tasks of a file first and writes them
 * only when none of them was refused.
 */
final readonly class PreparedPracticeItem
{
    /**
     * @param  list<int>  $wordIds  target words (active words of the course), without repeats, in ascending order
     * @param  array<string, mixed>  $content  `practice_item_versions.content` of the type (docs/16 §2.3)
     */
    public function __construct(
        public PracticeItemType $type,
        public string $key,
        public ?int $lessonId,
        public ?string $level,
        public array $wordIds,
        public array $content,
    ) {}
}
