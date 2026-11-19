<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Analytics\Services\ClientTimingRecorder;
use App\Http\Requests\Twa\ClientTimingRequest;
use App\Models\Learner;
use Illuminate\Http\Response;

/**
 * POST /api/twa/client-timing (throttle `twa-client-timing`): the timing marks of one sampled open of the Mini App (docs/04 A.21,
 * docs/12 §7.7). Always 204: an open outside the sample, a repeat and the collection switched off are not errors to the client, which
 * never waits for this call. The learner is the one of the token: there is no field to name another.
 */
final class ClientTimingController
{
    public function __construct(private readonly ClientTimingRecorder $recorder) {}

    public function __invoke(ClientTimingRequest $request): Response
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');
        $this->recorder->record($learner, $request->openId(), $request->platform(), $request->cold(), $request->entry(), $request->marks());

        return response()->noContent();
    }
}
