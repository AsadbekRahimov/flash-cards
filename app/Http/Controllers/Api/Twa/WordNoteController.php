<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Learning\Services\WordNoteService;
use App\Http\Requests\Twa\WordNoteRequest;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\Twa\WordNoteResource;
use App\Models\Learner;
use Illuminate\Http\JsonResponse;

final class WordNoteController
{
    public function __construct(private readonly WordNoteService $notes) {}

    /**
     * PUT /api/twa/words/{word}/note — saves (or clears, with `null`) the learner's own memory hook for a word they
     * have been served (docs/06 §12.3). 404 `card_not_started` for a word the learner has never seen: the note lives on
     * the (learner, word) row, and a learner cannot annotate words they do not study. Learner identity is the JWT (TwaAuth).
     */
    public function update(WordNoteRequest $request, int $word): JsonResponse
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        $saved = $this->notes->save($learner, $word, $request->note());
        if ($saved === null) {
            return ApiErrorResource::respond(404, 'card_not_started', 'This word has not been served to you yet.');
        }

        return (new WordNoteResource($saved))->response();
    }
}
