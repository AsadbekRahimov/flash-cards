<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Content\Exceptions\DeckException;
use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Content\Services\LearnerDeckService;
use App\Http\Requests\Twa\DeckTitleRequest;
use App\Http\Requests\Twa\ImportDeckWordsRequest;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\Twa\DeckDetailResource;
use App\Http\Resources\Twa\DeckImportResource;
use App\Http\Resources\Twa\DeckListResource;
use App\Http\Resources\Twa\DeckResource;
use App\Models\Course;
use App\Models\Learner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Learner decks (docs/04 A.10). Only the learner's OWN decks are reachable; any other slug is a 404. */
final class DeckController
{
    public function __construct(
        private readonly LearnerDeckService $decks,
        private readonly CourseCatalogService $catalog,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $learner = $this->learner($request);

        return (new DeckListResource([
            'decks' => $this->catalog->listings($this->decks->decks($learner), $learner),
            'limits' => $this->decks->limits($learner),
        ]))->response();
    }

    public function store(DeckTitleRequest $request): JsonResponse
    {
        $learner = $this->learner($request);

        try {
            $deck = $this->decks->create($learner, $request->string('title')->toString());
        } catch (DeckException $e) {
            return $this->error($e);
        }

        return (new DeckResource($this->catalog->listing($deck, $learner)))->response()->setStatusCode(201);
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $learner = $this->learner($request);
        $deck = $this->decks->find($learner, $slug);
        if ($deck === null) {
            return $this->error(DeckException::notFound());
        }

        return (new DeckDetailResource([
            'deck' => $this->catalog->listing($deck, $learner),
            'words' => $this->decks->words($deck),
        ]))->response();
    }

    public function update(DeckTitleRequest $request, string $slug): JsonResponse
    {
        $learner = $this->learner($request);

        try {
            $deck = $this->decks->rename($learner, $this->owned($learner, $slug), $request->string('title')->toString());
        } catch (DeckException $e) {
            return $this->error($e);
        }

        return (new DeckResource($this->catalog->listing($deck, $learner)))->response();
    }

    public function destroy(Request $request, string $slug): Response|JsonResponse
    {
        $learner = $this->learner($request);

        try {
            $this->decks->archive($learner, $this->owned($learner, $slug));
        } catch (DeckException $e) {
            return $this->error($e);
        }

        return response()->noContent();
    }

    public function addWords(ImportDeckWordsRequest $request, string $slug): JsonResponse
    {
        $learner = $this->learner($request);

        try {
            $deck = $this->owned($learner, $slug);
            $report = $this->decks->importText(
                $learner,
                $deck,
                $request->string('text')->toString(),
                $request->string('format')->toString(),
            );
        } catch (DeckException $e) {
            return $this->error($e);
        }

        return (new DeckImportResource([
            'report' => $report,
            'deck' => $this->catalog->listing($deck->refresh(), $learner),
        ]))->response();
    }

    public function removeWord(Request $request, string $slug, int $word): Response|JsonResponse
    {
        $learner = $this->learner($request);

        try {
            $this->decks->removeWord($learner, $this->owned($learner, $slug), $word);
        } catch (DeckException $e) {
            return $this->error($e);
        }

        return response()->noContent();
    }

    private function learner(Request $request): Learner
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        return $learner;
    }

    /** @throws DeckException deck_not_found */
    private function owned(Learner $learner, string $slug): Course
    {
        return $this->decks->find($learner, $slug) ?? throw DeckException::notFound();
    }

    private function error(DeckException $e): JsonResponse
    {
        return ApiErrorResource::respond($e->httpStatus(), $e->errorCode, $e->getMessage());
    }
}
