<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Learners\Services\AccountRestoreNotice;
use App\Domain\Twa\Services\MePayloadBuilder;
use App\Http\Resources\Twa\MeResource;
use App\Models\Learner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class MeController
{
    public function __construct(private readonly MePayloadBuilder $payload, private readonly AccountRestoreNotice $restoreNotice) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        return (new MeResource($this->payload->build($learner)))->response();
    }

    /** POST /api/twa/me/account-restored/seen - the Mini App showed "account restored"; idempotent, always 204. */
    public function restoredSeen(Request $request): Response
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');
        $this->restoreNotice->consume($learner);

        return response()->noContent();
    }
}
