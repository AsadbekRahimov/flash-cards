<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Learning\Exceptions\InvalidVocabularyCursor;
use App\Domain\Learning\Services\VocabularyService;
use App\Http\Requests\Twa\VocabularyRequest;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\Twa\VocabularyResource;
use App\Models\Learner;
use Illuminate\Http\JsonResponse;

final class VocabularyController
{
    public function __construct(private readonly VocabularyService $vocabulary) {}

    /**
     * GET /api/twa/vocabulary?q=&filter=&after=&limit= — "My vocabulary" (docs/04 A.25, docs/40 §2.3): the learner's graded words with
     * the memory strength, alphabetically, a page at a time; the counts of the chips on the first page only. Only the learner's own
     * rows (the JWT, TwaAuth). 422: validation, `invalid_cursor`.
     */
    public function __invoke(VocabularyRequest $request): JsonResponse
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        try {
            $page = $this->vocabulary->page($learner, $request->filter(), $request->search(), $request->cursor(), $request->limit());
        } catch (InvalidVocabularyCursor $e) {
            return ApiErrorResource::respond(422, InvalidVocabularyCursor::ERROR_CODE, $e->getMessage());
        }

        return (new VocabularyResource($page))->response();
    }
}
