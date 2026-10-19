<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Content\DTO\CourseListing;
use App\Domain\Content\DTO\DeckImportReport;
use Illuminate\Http\Request;

/**
 * POST /api/twa/decks/{slug}/words: what was added and what was thrown away, plus the refreshed deck.
 *
 * @property array{report: DeckImportReport, deck: CourseListing} $resource
 */
final class DeckImportResource extends TwaResource
{
    /**
     * @return array{
     *   added: int,
     *   reactivated: int,
     *   duplicates: int,
     *   rejected: list<array{line: int, reason: string}>,
     *   deck: array<string, mixed>
     * }
     */
    public function toArray(Request $request): array
    {
        $report = $this->resource['report'];

        return [
            'added' => $report->added,
            'reactivated' => $report->reactivated,
            'duplicates' => $report->duplicates,
            'rejected' => $report->rejected,
            'deck' => (new DeckResource($this->resource['deck']))->toArray($request),
        ];
    }
}
