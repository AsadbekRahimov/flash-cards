<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Content\DTO\CourseListing;
use Illuminate\Http\Request;

/**
 * One learner deck (docs/04 A.10): POST /decks, PATCH /decks/{slug} and an element of GET /decks.
 *
 * @property CourseListing $resource
 */
final class DeckResource extends TwaResource
{
    /**
     * @return array{
     *   slug: string,
     *   title: string,
     *   words_count: int,
     *   is_active: bool,
     *   progress: array{done: int, total: int}
     * }
     */
    public function toArray(Request $request): array
    {
        $listing = $this->resource;

        return [
            'slug' => $listing->course->slug,
            'title' => $listing->course->title,
            'words_count' => $listing->course->wordsCount,
            'is_active' => $listing->isActive,
            'progress' => [
                'done' => $listing->progress->done,
                'total' => $listing->progress->total,
            ],
        ];
    }
}
